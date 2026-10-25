<?php
/**
 * Rider Update Status API
 *
 * Rider flow (two QR scans):
 *   1. At the store, scan the parcel QR  -> To Ship becomes Out for Delivery ("pickup").
 *   2. At the customer, scan their QR    -> Out for Delivery becomes Completed.
 * Pickup = the order leaving To Ship: that is when the e-bike is recorded and the customer
 * gets the "on the way" message + email.
 * Also accepted, for older clients / orders staff moved by hand:
 *   To Ship -> To Receive (older Flutter app) and To Receive -> Out for Delivery.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';
require_once __DIR__ . '/../../app/helpers/delivery_message_helper.php';
require_once __DIR__ . '/../../app/helpers/ebike_helper.php';
require_once __DIR__ . '/../../app/helpers/delivery_proof_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Ensure rider schema is up-to-date
ensureRiderSchema($conn);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    // --- JWT Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    if (empty($token)) {
        $token = getJWTFromCookie();
    }

    $payload = verifyJWT($token);
    if (!$payload) {
        throw new Exception('Unauthorized access token');
    }

    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if ($role !== 'rider') {
        throw new Exception('Unauthorized role');
    }
    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_row = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();
    $rider_id = (int)($rider_row['id'] ?? 0);
    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $delivery_id = (int)($input['delivery_id'] ?? 0);
    $order_id = (int)($input['order_id'] ?? 0);
    $status = strtolower(trim((string)($input['status'] ?? '')));
    $qr_code = trim((string)($input['qr_code'] ?? ''));
    // E-bike used for this delivery: the one the rider picked in Rider Portal > Profile, unless
    // the request names one (0 = not using a shop e-bike). Only recorded at pickup.
    $ebike_id = array_key_exists('ebike_id', $input) ? (int)$input['ebike_id'] : -1;

    if ($delivery_id <= 0 && $order_id <= 0) {
        throw new Exception('delivery_id or order_id is required');
    }
    if ($status === '') {
        throw new Exception('status is required');
    }

    $allowed = ['to_receive', 'out_for_delivery', 'completed'];
    if (!in_array($status, $allowed, true)) {
        throw new Exception('Invalid status');
    }

    if ($qr_code === '') {
        throw new Exception('Scan the parcel QR code before updating the delivery status.');
    }
    $qr_data = json_decode($qr_code, true);
    if (!is_array($qr_data) || !isset($qr_data['delivery_id'], $qr_data['token'])) {
        throw new Exception('Invalid parcel QR code.');
    }
    $qr_delivery_id = (int)$qr_data['delivery_id'];
    $qr_token = (string)$qr_data['token'];
    if ($delivery_id <= 0) {
        $delivery_id = $qr_delivery_id;
    }
    if ($delivery_id !== $qr_delivery_id || $delivery_id <= 0) {
        throw new Exception('QR code does not match this delivery.');
    }

    $order_status = $status;
    $delivery_status = $status === 'completed' ? 'delivered' : 'in_transit';

    // Load order id if only delivery_id is provided
    if ($order_id <= 0) {
        $stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? AND qr_confirmation_token = ? LIMIT 1");
        $stmt->bind_param('is', $delivery_id, $qr_token);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) throw new Exception('Invalid or already used parcel QR code.');
        $order_id = (int)$res['order_id'];
    } else {
        $qr_stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? AND qr_confirmation_token = ? LIMIT 1");
        $qr_stmt->bind_param('is', $delivery_id, $qr_token);
        $qr_stmt->execute();
        $qr_res = $qr_stmt->get_result()->fetch_assoc();
        $qr_stmt->close();
        if (!$qr_res || ($order_id > 0 && (int)$qr_res['order_id'] !== $order_id)) {
            throw new Exception('QR code does not match this order.');
        }
        $order_id = (int)$qr_res['order_id'];
    }

    $current_order_stmt = $conn->prepare('SELECT order_status, bulk_order FROM tbl_orders WHERE id = ? LIMIT 1');
    $current_order_stmt->bind_param('i', $order_id);
    $current_order_stmt->execute();
    $current_order = $current_order_stmt->get_result()->fetch_assoc();
    $current_order_stmt->close();
    if (!$current_order) {
        throw new Exception('Order not found');
    }
    $transition_map = [
        'to_ship' => ['out_for_delivery', 'to_receive'],
        'to_receive' => ['out_for_delivery'],
        'out_for_delivery' => ['completed'],
    ];
    if (!in_array($order_status, $transition_map[$current_order['order_status']] ?? [], true)) {
        throw new Exception('This scan does not fit the order right now. Scan the parcel QR at the store to start the delivery, then scan the customer\'s QR to complete it.');
    }
    $is_pickup = $current_order['order_status'] === 'to_ship';

    $auth_stmt = $conn->prepare(
        "SELECT o.rider_id, d.rider_id AS delivery_rider_id
         FROM tbl_orders o JOIN tbl_delivery d ON d.id = ?
         WHERE o.id = ? LIMIT 1"
    );
    $auth_stmt->bind_param('ii', $delivery_id, $order_id);
    $auth_stmt->execute();
    $auth_row = $auth_stmt->get_result()->fetch_assoc();
    $auth_stmt->close();
    $previous_status = (string)$current_order['order_status'];
    $order_rider_id = (int)($auth_row['rider_id'] ?? 0);
    $delivery_rider_id = (int)($auth_row['delivery_rider_id'] ?? 0);
    $is_assigned_rider = $order_rider_id === $rider_id
        || $order_rider_id === $rider_user_id
        || $delivery_rider_id === $rider_user_id;
    // Taking over an order nobody has (scan without Accept): no rider in EITHER column - an
    // admin-assigned rider (tbl_delivery.rider_id) keeps the order - and only while Online,
    // the same rule as the Accept button (rider_dispatch_helper.php).
    $is_unassigned_pickup = $previous_status === 'to_ship'
        && ($order_rider_id === 0 || $order_rider_id === $rider_id)
        && ($delivery_rider_id === 0 || $delivery_rider_id === $rider_user_id);

    if (!$auth_row || (!$is_assigned_rider && !$is_unassigned_pickup)) {
        throw new Exception('You are not authorized to update this delivery.');
    }
    if (!$is_assigned_rider) {
        require_once __DIR__ . '/../../app/helpers/rider_dispatch_helper.php';
        $takeover_status = rider_availability_from_row(rider_profile_row($conn, $rider_user_id))['status'];
        if ($takeover_status !== 'online') {
            throw new Exception('You are ' . rider_status_label($takeover_status) . '. Go Online and accept the order to pick it up.');
        }
    }
    $status_changed = $current_order['order_status'] !== $order_status;

    // Schema DDL must run before the transaction (it would commit it), and a bad e-bike
    // choice must fail before anything is written.
    ebike_usage_ensure_schema($conn);
    if ($is_pickup && $ebike_id === -1) {
        $profile_bike = ebike_rider_profile_bike($conn, $rider_user_id);
        // A profile bike the shop has since switched off is simply not recorded (never blocks the pickup).
        $ebike_id = ($profile_bike && $profile_bike['usable']) ? $profile_bike['id'] : 0;
    }
    if ($is_pickup && $ebike_id > 0
        && !in_array($ebike_id, array_column(ebike_rider_choices($conn, $rider_user_id), 'id'), true)) {
        throw new Exception('That e-bike is not available. Pick another one.');
    }
    if ($is_pickup && $ebike_id > 0 && (int)($current_order['bulk_order'] ?? 0) === 1
        && ($bulk_error = ebike_bulk_check($conn, $ebike_id)) !== '') {
        throw new Exception($bulk_error);
    }

    $conn->begin_transaction();

    // Update tbl_orders
    $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = ?, rider_id = ?, updated_at = NOW() WHERE id = ? AND order_status = ?");
    $stmt1->bind_param('siis', $order_status, $rider_id, $order_id, $previous_status);
    $stmt1->execute();
    if ($stmt1->affected_rows !== 1) {
        $stmt1->close();
        throw new Exception('The order status changed before confirmation. Please scan again.');
    }
    $stmt1->close();

    // Update tbl_delivery if delivery_id exists
    if ($delivery_id > 0) {
        $stmt2 = $conn->prepare(
            "UPDATE tbl_delivery
             SET status = ?, delivered_at = CASE WHEN ? = 'delivered' THEN NOW() ELSE delivered_at END,
                 updated_at = NOW()
               WHERE id = ? AND qr_confirmation_token = ?"
        );
        $delStatus = $delivery_status;
        $deliveredFlag = $status === 'completed' ? 'delivered' : 'not_delivered';
           $stmt2->bind_param('ssis', $delStatus, $deliveredFlag, $delivery_id, $qr_token);
        $stmt2->execute();
        $stmt2->close();
    }

    $ebike_used = '';
    if ($is_pickup && $ebike_id > 0) {
        $ebike_used = ebike_record_pickup($conn, $ebike_id, $rider_user_id, $order_id, $delivery_id);
    }

    if ($order_status === 'completed') {
        ebike_record_return($conn, $order_id);
        loyalty_award_completed_order($conn, $order_id);
        $clear_qr_stmt = $conn->prepare("UPDATE tbl_delivery SET qr_confirmation_token = NULL WHERE id = ? AND qr_confirmation_token = ?");
        $clear_qr_stmt->bind_param('is', $delivery_id, $qr_token);
        $clear_qr_stmt->execute();
        $clear_qr_stmt->close();
    }
    $conn->commit();

    // Photos are asked for, never required: if the rider went on without this step's photo, record
    // it so admin/staff see a "No pickup photo" / "No delivery photo" flag (delivery_proof_helper.php).
    if ($status_changed) {
        try {
            if ($is_pickup || $order_status === 'to_receive') {
                delivery_photo_record_skip($conn, $order_id, $rider_id, 'pickup');
            } elseif ($order_status === 'completed') {
                delivery_photo_record_skip($conn, $order_id, $rider_id, 'delivery');
            }
        } catch (Throwable $photo_ex) {
            error_log("[rider status] photo flag error for order {$order_id}: " . $photo_ex->getMessage());
        }
    }

    // Notify customer
    $customer_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
    $customer_stmt->bind_param('i', $order_id);
    $customer_stmt->execute();
    $customer_res = $customer_stmt->get_result()->fetch_assoc();
    $customer_stmt->close();

    $customer_id = $customer_res ? (int)$customer_res['customer_id'] : 0;

    // "On the way" = the pickup (or an old-style move into To Receive). It gets the richer
    // bubble + email below; every other rider status change gets this generic bubble.
    $on_the_way = $status_changed && ($is_pickup || $order_status === 'to_receive');
    $on_the_way_label = $order_status === 'to_receive' ? 'To Receive' : 'Out for Delivery';
    // Generic in-app bubble for every rider status change EXCEPT the "on the way" one,
    // which gets its own richer bubble + customer email below.
    // NOTE: only `customer_id` is set. `notifications.user_id` is a foreign key to `users`
    // (admin/staff/rider accounts), so a customer id must never go there - it violated the
    // constraint for any customer without a matching users row. The customer's real-time push
    // is routed by customer_id inside notifications_create().
    // The status change is already committed, so a notification problem must never turn this
    // request into an error for the rider.
    if ($customer_id > 0 && $status_changed && !$on_the_way && $order_status !== 'completed') {
        try {
            notifications_create($conn, [
                'customer_id' => $customer_id,
                'type' => 'rider_status_update',
                'channel' => 'in_app',
                'title' => 'Delivery update',
                'message' => 'Your order has been updated by your rider.',
                'reference_table' => 'tbl_orders',
                'reference_id' => $order_id,
                'email_to' => null,
            ]);
        } catch (Throwable $notify_ex) {
            error_log("[rider status] notification error for order {$order_id}: " . $notify_ex->getMessage());
        }
    }

    $response['success'] = true;
    $response['data'] = [
        'order_id' => $order_id,
        'delivery_id' => $delivery_id,
        'order_status' => $order_status,
        'delivery_status' => $delivery_status,
        'ebike' => $ebike_used,
    ];

    /* -----------------------------------------------------------------
     * "To Receive" customer notification (in-app bubble + Gmail email).
     *
     * Fires ONLY when the DB transition to 'to_receive' actually happened
     * (status_changed + the transaction above committed). All data is read
     * live from this order. A row in `notifications` acts as the once-only
     * guard, so a page refresh / re-open / unrelated edit never re-sends.
     * A mail failure NEVER rolls back the (already committed) status.
     * --------------------------------------------------------------- */
    if ($on_the_way && $customer_id > 0) {
        // Automatic "DPS DELIVERY!" message in the customer's Messages (system-generated, sent once).
        $response['data']['to_receive_message'] = delivery_message_send_on_to_receive($conn, $order_id);
    }

    if ($on_the_way && $customer_id > 0) {
        try {
            // Once-only guard: has a To Receive notification already been logged
            // for this order? (belt-and-suspenders on top of the transition map).
            $dup_stmt = $conn->prepare(
                "SELECT id FROM notifications
                 WHERE reference_table = 'tbl_orders' AND reference_id = ?
                   AND type = 'rider_to_receive' LIMIT 1"
            );
            $dup_stmt->bind_param('i', $order_id);
            $dup_stmt->execute();
            $already_sent = $dup_stmt->get_result()->fetch_assoc();
            $dup_stmt->close();

            if ($already_sent) {
                $response['data']['to_receive_email'] = 'skipped_duplicate';
            } else {
                // --- Pull the REAL data for this order ---
                $od_stmt = $conn->prepare(
                    "SELECT o.order_number, o.total, o.payment_method,
                            c.name AS customer_name, c.email AS customer_email
                     FROM tbl_orders o
                     LEFT JOIN customers c ON c.id = o.customer_id
                     WHERE o.id = ? LIMIT 1"
                );
                $od_stmt->bind_param('i', $order_id);
                $od_stmt->execute();
                $od = $od_stmt->get_result()->fetch_assoc();
                $od_stmt->close();

                // Rider name = the authenticated rider performing this update.
                $rn_stmt = $conn->prepare(
                    "SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name,
                            username
                     FROM users WHERE id = ? LIMIT 1"
                );
                $rn_stmt->bind_param('i', $rider_user_id);
                $rn_stmt->execute();
                $rn = $rn_stmt->get_result()->fetch_assoc();
                $rn_stmt->close();
                $rider_name = trim((string)($rn['full_name'] ?? '')) !== ''
                    ? trim((string)$rn['full_name'])
                    : (string)($rn['username'] ?? 'Your rider');

                $order_number   = (string)($od['order_number'] ?? ('DPS-' . $order_id));
                $customer_name  = trim((string)($od['customer_name'] ?? '')) ?: 'Customer';
                $customer_email = trim((string)($od['customer_email'] ?? ''));
                $payment_method = strtolower(trim((string)($od['payment_method'] ?? '')));
                $amount_php     = 'PHP ' . number_format((float)($od['total'] ?? 0), 2);

                // Correct wording per payment method – never mislabel non-COD as COD.
                if ($payment_method === 'cod') {
                    $amount_line_email = "COD Amount: {$amount_php}\n\n"
                        . "You can pay by cash or conveniently online when the rider arrives.";
                    $amount_line_short = "COD Amount: {$amount_php}";
                } else {
                    $method_label = [
                        'gcash' => 'GCash',
                        'bank' => 'bank transfer',
                        'pay_at_shop' => 'payment at the shop',
                    ][$payment_method] ?? 'online payment';
                    $amount_line_email = "Amount: {$amount_php}\n\n"
                        . "This order was paid via {$method_label}. No cash is required on delivery.";
                    $amount_line_short = "Amount: {$amount_php} (paid via {$method_label})";
                }

                // 1) In-app bubble (short) – always logged; also the once-only guard row.
                $short_msg = "Your order #{$order_number} is now {$on_the_way_label}. "
                    . "Rider: {$rider_name}. {$amount_line_short}.";
                notifications_create($conn, [
                    'customer_id'     => $customer_id,
                    'type'            => 'rider_to_receive',
                    'channel'         => 'in_app',
                    'title'           => "Order #{$order_number} - {$on_the_way_label}",
                    'message'         => $short_msg,
                    'reference_table' => 'tbl_orders',
                    'reference_id'    => $order_id,
                ]);

                // 2) Customer email (full "DPS DELIVERY!" format) – only with a valid address.
                if ($customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
                    $email_body =
                        "DPS DELIVERY!\n\n"
                        . ($order_status === 'to_receive'
                            ? "Your order #{$order_number} is now ready to be received.\n\n"
                            : "Your rider picked up your order #{$order_number} and it is on its way to you.\n\n")
                        . "Rider: {$rider_name}\n"
                        . "Customer: {$customer_name}\n\n"
                        . "{$amount_line_email}\n\n"
                        . "Please note that a photo proof of delivery is required.\n\n"
                        . "Thank you for choosing DPS Express Philippines!\n"
                        . "Stay safe and have a great day ahead.";

                    $email_result = null;
                    notifications_create($conn, [
                        'customer_id'     => $customer_id,
                        'type'            => 'rider_to_receive_email',
                        'channel'         => 'email',
                        'title'           => "Delivery Update - Order #{$order_number}",
                        'message'         => $email_body,
                        'email_to'        => $customer_email,
                        'reference_table' => 'tbl_orders',
                        'reference_id'    => $order_id,
                    ], $email_result);

                    if (!empty($email_result['email_sent'])) {
                        $response['data']['to_receive_email'] = 'sent';
                    } else {
                        $response['data']['to_receive_email'] = 'failed';
                        $response['message'] = 'Order status updated. The customer email notification could not be sent.';
                        error_log("[rider to_receive] email FAILED for order {$order_id} ({$order_number}) -> {$customer_email}");
                    }
                } else {
                    $response['data']['to_receive_email'] = 'skipped_no_email';
                    $response['message'] = 'Order status updated. Customer has no valid email on file for a notification.';
                    error_log("[rider to_receive] no valid customer email for order {$order_id} ({$order_number})");
                }
            }
        } catch (Throwable $notify_ex) {
            // Status is already committed – never fail the rider request over a notification.
            $response['data']['to_receive_email'] = 'error';
            error_log("[rider to_receive] notification error for order {$order_id}: " . $notify_ex->getMessage());
        }
    }

    if ($status_changed && $order_status === 'completed' && $customer_id > 0) {
        try {
            $completed_order_stmt = $conn->prepare(
                'SELECT o.order_number, o.fulfillment_type, c.name AS customer_name, c.email AS customer_email
                 FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE o.id = ? LIMIT 1'
            );
            $completed_order_stmt->bind_param('i', $order_id);
            $completed_order_stmt->execute();
            $completed_order = $completed_order_stmt->get_result()->fetch_assoc();
            $completed_order_stmt->close();

            if ($completed_order) {
                $order_number = (string) $completed_order['order_number'];
                $picked_up = ($completed_order['fulfillment_type'] ?? '') === 'pickup';
                $verb = $picked_up ? 'picked up' : 'delivered';
                $title = $picked_up ? 'Order picked up' : 'Order delivered';
                $message = 'Your order #' . $order_number . ' has been ' . $verb . ' by the rider. Thank you for choosing ' . SYSTEM_NAME . '.';
                notifications_create($conn, [
                    'customer_id' => $customer_id,
                    'type' => 'rider_order_completed',
                    'channel' => 'both',
                    'title' => $title . ' - Order #' . $order_number,
                    'message' => $message,
                    'email_to' => (string) ($completed_order['customer_email'] ?? ''),
                    'reference_table' => 'tbl_orders',
                    'reference_id' => $order_id,
                ]);
            }
        } catch (Throwable $notification_error) {
            error_log("Rider completion notification failed for order {$order_id}.");
        }
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>