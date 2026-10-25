<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/payment_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

/** Rider-facing label for the stored status enum. Does not change the DB value. */
function remit_status_label(?string $status): string
{
    return remittance_status_label($status);
}

/**
 * Validates a base64 image (data-URI or raw), confirms it is really an image,
 * saves it to uploads/proofs/ and returns the project-relative path.
 * Same approach as modules/rider/rider_proof_api.php.
 */
function remit_save_proof_image(string $image_data): string
{
    $image_data = trim($image_data);
    if (preg_match('#^data:image/\w+;base64,(.+)$#s', $image_data, $m)) {
        $image_data = $m[1];
    }
    $bytes = base64_decode($image_data, true);
    if ($bytes === false || $bytes === '') {
        throw new Exception('Invalid receipt image data.');
    }
    if (strlen($bytes) > 8 * 1024 * 1024) {
        throw new Exception('Receipt image is too large (max 8MB).');
    }
    $probe = @getimagesizefromstring($bytes);
    $ext_by_type = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if ($probe === false || !isset($ext_by_type[$probe[2]])) {
        throw new Exception('Unsupported or invalid receipt image.');
    }
    $ext = $ext_by_type[$probe[2]];

    $dir = __DIR__ . '/../../uploads/proofs/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = 'remit_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . $filename, $bytes) === false) {
        throw new Exception('Failed to save the receipt image.');
    }
    return 'uploads/proofs/' . $filename;
}

try {
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    // Web Rider Portal (/rider/) sends the rider's login cookie instead of a Bearer header,
    // same fallback every other rider API already has.
    if (empty($token)) {
        $token = getJWTFromCookie();
    }

    $payload = verifyJWT($token);
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }
    $rider_user_id = (int)$payload['user_id'];
    ensureRiderSchema($conn);

    // Get specific riders.id from users.id
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();

    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    }

    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    ensure_remittance_schema($conn);

    // --- Rider identity (for the history header) ---
    $name_stmt = $conn->prepare(
        "SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username, email
         FROM users WHERE id = ? LIMIT 1"
    );
    $name_stmt->bind_param('i', $rider_user_id);
    $name_stmt->execute();
    $name_row = $name_stmt->get_result()->fetch_assoc();
    $name_stmt->close();
    $rider_name = trim((string) ($name_row['full_name'] ?? '')) !== ''
        ? trim((string) $name_row['full_name'])
        : (string) ($name_row['username'] ?? 'Rider');
    $rider_email = (string) ($name_row['email'] ?? '');

    $input = [];
    $action = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'check_reference':
            $reference_number = trim((string)($input['reference_number'] ?? $_GET['reference_number'] ?? ''));
            if ($reference_number === '') {
                throw new Exception('Reference number is required.');
            }

            $reference_check = $conn->prepare(
                "SELECT id FROM tbl_rider_remittances WHERE reference_number = ? LIMIT 1"
            );
            $reference_check->bind_param('s', $reference_number);
            $reference_check->execute();
            $exists = $reference_check->get_result()->num_rows > 0;
            $reference_check->close();

            $response['success'] = true;
            $response['data'] = ['available' => !$exists];
            $response['message'] = $exists
                ? 'Reference number already exists. Please enter a different reference number.'
                : 'Reference number is available.';
            break;

        case 'get_my_balance':
            // Unremitted COD orders (excludes orders already submitted and awaiting staff)
            $orders = remittance_eligible_orders($conn, $rider_id);

            $response['success'] = true;
            $response['message'] = 'Balance fetched successfully.';
            $response['data'] = [
                'total_unremitted_cod' => remittance_orders_total($orders),
                'orders' => array_map(static fn($o) => [
                    'order_id' => $o['order_id'], 'order_number' => $o['order_number'],
                    'total' => $o['total'], 'created_at' => $o['created_at'],
                ], $orders),
            ];
            break;

        case 'get_overview':
            // Open online remittances: ask PayMongo for their result (server to server) so the
            // history is right even when no webhook can reach this server (e.g. localhost).
            if (payment_online_available()) {
                $open_pay = $conn->prepare(
                    "SELECT p.id FROM tbl_payments p JOIN tbl_rider_remittances r ON r.id = p.remittance_id
                     WHERE p.purpose = 'remittance' AND p.status = 'pending' AND r.rider_id = ?
                       AND (p.last_checked_at IS NULL OR p.last_checked_at < NOW() - INTERVAL 30 SECOND)
                     ORDER BY p.id DESC LIMIT 3"
                );
                $open_pay->bind_param('i', $rider_user_id);
                $open_pay->execute();
                foreach ($open_pay->get_result()->fetch_all(MYSQLI_ASSOC) as $op) {
                    try { payment_inquire($conn, (int) $op['id']); } catch (Throwable $e) { error_log('remittance inquiry: ' . $e->getMessage()); }
                }
                $open_pay->close();
            }

            // --- Summary: every completed COD order handled by this rider ---
            $sum_stmt = $conn->prepare(
                "SELECT
                    COALESCE(SUM(o.total), 0) AS total_cod_collected,
                    COUNT(*) AS orders_total,
                    COALESCE(SUM(CASE WHEN o.payment_settled = 1 THEN 1 ELSE 0 END), 0) AS orders_remitted
                 FROM tbl_orders o
                 WHERE o.rider_id = ? AND o.payment_method = 'cod' AND o.order_status = 'completed'"
            );
            $sum_stmt->bind_param('i', $rider_id);
            $sum_stmt->execute();
            $sum = $sum_stmt->get_result()->fetch_assoc() ?: [];
            $sum_stmt->close();

            // total_remitted = amount staff have actually verified
            $remitted_stmt = $conn->prepare(
                "SELECT COALESCE(SUM(amount), 0) AS total_remitted, COUNT(*) AS verified_count
                 FROM tbl_rider_remittances WHERE rider_id = ? AND status = 'approved'"
            );
            $remitted_stmt->bind_param('i', $rider_user_id);
            $remitted_stmt->execute();
            $remitted = $remitted_stmt->get_result()->fetch_assoc() ?: [];
            $remitted_stmt->close();

            // --- COD orders that still have to be remitted (not yet submitted). The
            // expected amount is always calculated here from the real orders. ---
            $pending_orders = remittance_eligible_orders($conn, $rider_id);
            $pending_total = remittance_orders_total($pending_orders);

            // --- Submitted / discrepancy remittances still waiting on staff ---
            $open_stmt = $conn->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(expected_amount, amount)), 0) AS total
                 FROM tbl_rider_remittances WHERE rider_id = ? AND status IN ('pending','discrepancy')"
            );
            $open_stmt->bind_param('i', $rider_user_id);
            $open_stmt->execute();
            $open = $open_stmt->get_result()->fetch_assoc() ?: [];
            $open_stmt->close();

            // --- Remittance history (only this rider's own records) ---
            $hist_stmt = $conn->prepare(
                "SELECT r.*,
                        (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = r.id) AS order_count,
                        (SELECT COALESCE(SUM(o2.total),0)
                           FROM tbl_rider_remittance_items i2
                           JOIN tbl_orders o2 ON o2.id = i2.order_id
                          WHERE i2.remittance_id = r.id) AS cod_total,
                        TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS received_by_name,
                        rb.username AS received_by_username
                 FROM tbl_rider_remittances r
                 LEFT JOIN users rb ON rb.id = COALESCE(r.received_by_user_id, r.processed_by_user_id)
                 WHERE r.rider_id = ?
                 ORDER BY r.requested_at DESC, r.id DESC
                 LIMIT 50"
            );
            $hist_stmt->bind_param('i', $rider_user_id);
            $hist_stmt->execute();
            $hist_rows = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $hist_stmt->close();

            $items_stmt = $conn->prepare(
                "SELECT i.order_id, o.order_number, o.total, o.created_at, o.order_status,
                        c.name AS customer_name
                 FROM tbl_rider_remittance_items i
                 JOIN tbl_orders o ON o.id = i.order_id
                 LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE i.remittance_id = ?
                 ORDER BY o.created_at ASC"
            );

            $history = [];
            foreach ($hist_rows as $r) {
                $rid = (int) $r['id'];
                $items_stmt->bind_param('i', $rid);
                $items_stmt->execute();
                $order_rows = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $expected = $r['expected_amount'] !== null ? (float) $r['expected_amount'] : (float) $r['cod_total'];
                $breakdown = remittance_row_breakdown($r);
                $received_by = trim((string) $r['received_by_name']) !== ''
                    ? trim((string) $r['received_by_name'])
                    : ($r['received_by_username'] ?? null);

                $online = payment_latest($conn, 'remittance', $rid);
                $history[] = [
                    'online'           => $online ? ['status' => $online['status'], 'checkout_url' => $online['status'] === 'pending' ? $online['checkout_url'] : null] : null,
                    'id'               => $rid,
                    'remittance_number' => remittance_number($rid),
                    'remittance_type'  => (string) ($r['remittance_type'] ?? 'store'),
                    'type_label'       => remittance_type_label($r['remittance_type'] ?? 'store'),
                    'transaction_id'   => (string) ($r['transaction_id'] ?? ''),
                    'reference_number' => (string) ($r['reference_number'] ?? ''),
                    'rider_name'       => $rider_name,
                    'has_proof'        => !empty($r['proof_image_path']),
                    'proof_image_url'  => !empty($r['proof_image_path'])
                        ? rtrim(BASE_URL, '/') . '/' . ltrim((string) $r['proof_image_path'], '/')
                        : null,
                    'amount'           => (float) $r['amount'],
                    'expected_amount'  => $expected,
                    'difference'       => round((float) $r['amount'] - $expected, 2),
                    'cash_amount'      => $breakdown['cash'],
                    'gcash_amount'     => $breakdown['gcash'],
                    'bank_amount'      => $breakdown['bank'],
                    'payment_method'   => remittance_payment_method_label($breakdown['cash'], $breakdown['gcash'], $breakdown['bank'], $r['reference_number']),
                    'cod_total'        => (float) $r['cod_total'],
                    'order_count'      => (int) $r['order_count'],
                    'status'           => (string) $r['status'],
                    'status_label'     => remit_status_label($r['status']),
                    'notes'            => $r['status'] === 'rejected' && $r['notes'] !== null ? (string) $r['notes'] : null,
                    'rider_notes'      => $r['rider_notes'] !== null ? (string) $r['rider_notes'] : null,
                    'discrepancy_reason' => $r['discrepancy_reason'] !== null ? (string) $r['discrepancy_reason'] : null,
                    'received_by'      => $received_by,
                    'received_at'      => $r['received_at'] !== null ? (string) $r['received_at'] : null,
                    'requested_at'     => (string) $r['requested_at'],
                    'remitted_at'      => $r['remitted_at'] !== null ? (string) $r['remitted_at'] : null,
                    'processed_at'     => $r['processed_at'] !== null ? (string) $r['processed_at'] : null,
                    'orders'           => array_map(static function ($o) {
                        return [
                            'order_id'      => (int) $o['order_id'],
                            'order_number'  => (string) $o['order_number'],
                            'customer_name' => $o['customer_name'] !== null ? (string) $o['customer_name'] : null,
                            'total'         => (float) $o['total'],
                            'created_at'    => (string) $o['created_at'],
                            'order_status'  => (string) $o['order_status'],
                        ];
                    }, $order_rows),
                ];
            }
            $items_stmt->close();

            $response['success'] = true;
            $response['message'] = 'Remittance overview fetched.';
            $response['data'] = [
                'rider_name' => $rider_name,
                'online_payment_available' => payment_online_available(),
                // The shop's own GCash / bank details (Admin > Settings > Payment Accounts) for "Already sent".
                'shop_accounts' => shop_payment_accounts($conn),
                'summary' => [
                    'total_cod_collected'  => (float) ($sum['total_cod_collected'] ?? 0),
                    'total_remitted'       => (float) ($remitted['total_remitted'] ?? 0),
                    'total_pending_amount' => $pending_total,
                    'awaiting_review_amount' => (float) ($open['total'] ?? 0),
                    'awaiting_review_count'  => (int) ($open['n'] ?? 0),
                    'orders_total'         => (int) ($sum['orders_total'] ?? 0),
                    'orders_remitted'      => (int) ($sum['orders_remitted'] ?? 0),
                    'orders_pending'       => count($pending_orders),
                    'verified_count'       => (int) ($remitted['verified_count'] ?? 0),
                ],
                'pending_orders' => array_map(static function ($o) {
                    return [
                        'order_id'      => (int) $o['order_id'],
                        'order_number'  => (string) $o['order_number'],
                        'customer_name' => $o['customer_name'] !== null ? (string) $o['customer_name'] : null,
                        'total'         => (float) $o['total'],
                        'created_at'    => (string) $o['created_at'],
                        'order_status'  => 'completed',
                    ];
                }, $pending_orders),
                'history' => $history,
            ];
            break;

        case 'submit_remittance':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('This action requires a POST request.');
            }

            // The rider only reports what they are handing over. Expected amount, status,
            // verification and order totals are never taken from the request.
            $order_ids = $input['order_ids'] ?? [];
            if (empty($order_ids) || !is_array($order_ids)) {
                throw new RemittanceException('No orders specified for remittance.');
            }
            $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids), static fn($id) => $id > 0)));
            if (empty($order_ids)) {
                throw new RemittanceException('No valid orders specified for remittance.');
            }

            // Riders only send ONLINE remittances here: GCash or Bank Transfer. Cash is handed over in
            // person at the physical store, where the staff record it (Remittance page -> Receive Cash).
            $remittance_type = strtolower(trim((string) ($input['remittance_type'] ?? '')));
            if ($remittance_type === '' && trim((string) ($input['reference_number'] ?? '')) !== '') {
                $remittance_type = 'gcash'; // older app builds: a reference number meant GCash
            }
            if (!in_array($remittance_type, ['gcash', 'bank'], true)) {
                throw new RemittanceException('Cash is paid in person at the store - bring it to the shop and the staff will record it. Only GCash and Bank Transfer can be sent here.');
            }
            $channel = remittance_type_channel($remittance_type);
            $sent = $input[$channel . '_amount'] ?? $input['amount'] ?? 0;
            $amounts = remittance_parse_amounts([$channel . '_amount' => $sent]);
            if ($amounts['total'] <= 0) {
                throw new RemittanceException('Enter the amount you sent by ' . remittance_type_label($remittance_type) . '.');
            }
            $rider_notes = trim((string) ($input['rider_notes'] ?? $input['notes'] ?? ''));
            $proof_image_data = trim((string) ($input['proof_image'] ?? ''));

            // The rider's ACTUAL GCash / bank reference number (required for both) - never the system
            // transaction id generated below, and never invented here.
            if (trim((string) ($input['reference_number'] ?? '')) === '') {
                throw new RemittanceException('Enter the reference number from your ' . remittance_type_label($remittance_type) . ' receipt.');
            }
            $reference_number = remittance_resolve_reference($conn, $amounts, (string) $input['reference_number']);

            // Optional receipt screenshot for an electronic payment. Saved BEFORE the
            // transaction so a bad image never leaves a half-written remittance.
            $proof_image_path = null;
            if ($proof_image_data !== '' && ($amounts['gcash'] > 0 || $amounts['bank'] > 0)) {
                $proof_image_path = remit_save_proof_image($proof_image_data);
            }

            $conn->begin_transaction();
            try {
                $eligible_rows = remittance_eligible_orders($conn, $rider_id, $order_ids, true);
                if (count($eligible_rows) !== count($order_ids)) {
                    throw new RemittanceException('One or more orders are not eligible for remittance (already submitted or settled).');
                }
                $expected = remittance_orders_total($eligible_rows);
                $difference = round($amounts['total'] - $expected, 2);
                if (abs($difference) >= 0.005 && $rider_notes === '') {
                    throw new RemittanceException('The amount you are handing over differs from the expected amount. Add a note explaining the difference.');
                }

                $remittance_id = remittance_insert_pending(
                    $conn, $rider_user_id, $eligible_rows, $amounts, $reference_number, $proof_image_path, $rider_notes,
                    false, $remittance_type
                );
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
                    throw new RemittanceException('This reference number has already been used for another remittance.');
                }
                throw $e;
            }

            $number = remittance_number($remittance_id);
            $txn_row = $conn->query("SELECT transaction_id FROM tbl_rider_remittances WHERE id = $remittance_id")->fetch_assoc();
            $transaction_id = (string) ($txn_row['transaction_id'] ?? '');
            $n_orders = count($order_ids);
            remittance_audit(
                $conn, $rider_user_id, 'Remittance Submitted',
                "{$rider_name} submitted {$number}: " . remittance_peso($amounts['total']) . ' handed over against '
                . remittance_peso($expected) . " expected for {$n_orders} " . ($n_orders === 1 ? 'order' : 'orders') . '.'
            );

            if (function_exists('notifications_create')) {
                try {
                    notifications_create($conn, [
                        'user_id'         => $rider_user_id,
                        'type'            => 'remittance_submitted',
                        'channel'         => 'both',
                        'title'           => 'Remittance submitted',
                        'message'         => "Your " . remittance_type_label($remittance_type) . " remittance {$number} for " . remittance_peso($amounts['total'])
                            . " ({$n_orders} " . ($n_orders === 1 ? 'order' : 'orders') . ') was submitted. The shop will check that the transfer arrived.',
                        'email_to'        => $rider_email,
                        'reference_table' => 'tbl_rider_remittances',
                        'reference_id'    => $remittance_id,
                    ]);
                } catch (Throwable $notification_error) {
                    error_log("Rider remittance submission notification failed for remittance {$remittance_id}.");
                }
            }

            $response['success'] = true;
            $response['message'] = "Remittance submitted. Transaction ID: {$transaction_id}. The shop will confirm your " . remittance_type_label($remittance_type) . ' transfer.';
            $response['data'] = [
                'remittance_id'     => $remittance_id,
                'remittance_number' => $number,
                'remittance_type'   => $remittance_type,
                'type_label'        => remittance_type_label($remittance_type),
                'transaction_id'    => $transaction_id,
                'reference_number'  => (string) $reference_number,
                'proof_image_url'   => $proof_image_path ? rtrim(BASE_URL, '/') . '/' . ltrim($proof_image_path, '/') : null,
                'order_count'       => $n_orders,
                'amount'            => $amounts['total'],
                'expected_amount'   => $expected,
                'difference'        => $difference,
            ];
            break;

        case 'start_online_remittance':
            // Pay the shop through PayMongo (GCash / online banking). The remittance stays
            // "Pending verification" and is verified ONLY when PayMongo confirms the payment
            // (webhook or server-side status check) - never by this request.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('This action requires a POST request.');
            }
            if (!payment_online_available()) {
                throw new RemittanceException('Online payment is not available right now. Send it by GCash/bank and enter the reference no., or pay cash at the store.');
            }
            $remittance_type = ($input['remittance_type'] ?? '') === 'bank' ? 'bank' : 'gcash';
            $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['order_ids'] ?? [])), static fn($id) => $id > 0)));
            if (!$order_ids) {
                throw new RemittanceException('Pick the orders you are paying for.');
            }
            $conn->begin_transaction();
            try {
                $eligible_rows = remittance_eligible_orders($conn, $rider_id, $order_ids, true);
                if (count($eligible_rows) !== count($order_ids)) {
                    throw new RemittanceException('One or more orders are not eligible for remittance (already submitted or settled).');
                }
                $expected = remittance_orders_total($eligible_rows);
                $channel = remittance_type_channel($remittance_type);
                $amounts = remittance_parse_amounts([$channel . '_amount' => $expected]);
                $remittance_id = remittance_insert_pending($conn, $rider_user_id, $eligible_rows, $amounts, null, null,
                    'Paying online through PayMongo', false, $remittance_type);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            $number = remittance_number($remittance_id);
            // Both links only bring the rider back; neither marks anything paid. The result is always
            // asked from PayMongo by the server (check_online_remittance) or received by the webhook.
            $return = SITE_URL . '/rider/remittance.php?check=' . $remittance_id;
            $start = payment_start_checkout($conn, [
                'purpose' => 'remittance', 'remittance_id' => $remittance_id, 'amount' => $expected, 'method' => $remittance_type,
                'description' => "Rider remittance {$number} ({$rider_name})", 'success_url' => $return, 'cancel_url' => $return . '&cancelled=1',
            ]);
            if (!$start['ok']) {
                remittance_cancel_unpaid_online($conn, $remittance_id, 'The online payment could not be started.');
                throw new RemittanceException($start['message']);
            }
            remittance_audit($conn, $rider_user_id, 'Remittance Submitted',
                "{$rider_name} started {$number}: " . remittance_peso($expected) . ' by ' . remittance_type_label($remittance_type) . ' through PayMongo.');
            $response['success'] = true;
            $response['message'] = 'Continue to PayMongo to pay.';
            $response['data'] = ['remittance_id' => $remittance_id, 'checkout_url' => $start['checkout_url'],
                'transaction_id' => (string) $conn->query("SELECT transaction_id FROM tbl_rider_remittances WHERE id = $remittance_id")->fetch_row()[0]];
            break;

        case 'check_online_remittance':
            // Asks PayMongo (server to server) for the result - nothing the browser sends can mark it
            // paid. cancelled=1 (the rider came back through PayMongo's cancel link): if PayMongo still
            // has it open, the session is expired AT PAYMONGO first, then the remittance is closed.
            $remittance_id = (int) ($input['remittance_id'] ?? $_GET['remittance_id'] ?? 0);
            $own = $conn->prepare("SELECT id FROM tbl_rider_remittances WHERE id = ? AND rider_id = ? LIMIT 1");
            $own->bind_param('ii', $remittance_id, $rider_user_id);
            $own->execute();
            $mine = $own->get_result()->fetch_assoc();
            $own->close();
            if (!$mine) {
                throw new RemittanceException('Remittance not found.');
            }
            $pay = payment_latest($conn, 'remittance', $remittance_id);
            $check = ['ok' => true, 'message' => ''];
            if ($pay) {
                $check = !empty($input['cancelled']) ? payment_cancel($conn, (int) $pay['id']) : payment_inquire($conn, (int) $pay['id']);
                $pay = payment_latest($conn, 'remittance', $remittance_id);
            }
            $r = $conn->query("SELECT * FROM tbl_rider_remittances WHERE id = $remittance_id")->fetch_assoc();
            $orders = $conn->query("SELECT o.order_number, o.total FROM tbl_rider_remittance_items i JOIN tbl_orders o ON o.id = i.order_id WHERE i.remittance_id = $remittance_id ORDER BY o.id")->fetch_all(MYSQLI_ASSOC);
            $status = (string) $r['status'];
            $response['success'] = true;
            $response['message'] = !$check['ok'] ? $check['message']
                : ($status === 'approved' ? 'Payment successful.'
                : ($status === 'discrepancy' ? 'PayMongo reported a different amount. The shop will review it.'
                : ($status === 'rejected' ? 'Payment failed or was cancelled.'
                : 'Payment is being verified.')));
            $response['data'] = [
                'status'                 => $status,
                'status_label'           => remittance_status_label($status),
                'verification_available' => (bool) $check['ok'],
                'remittance_id'          => $remittance_id,
                'transaction_id'         => (string) $r['transaction_id'],
                'amount_due'             => (float) $r['expected_amount'],
                'amount_paid'            => $pay && $pay['amount_paid'] !== null ? (float) $pay['amount_paid'] : null,
                'method'                 => remittance_type_label($r['remittance_type']),
                'provider_payment_id'    => $pay['provider_payment_id'] ?? null,
                'provider_reference'     => (string) ($r['reference_number'] ?? ''),
                'date'                   => (string) ($r['processed_at'] ?: $r['requested_at']),
                'note'                   => $status === 'rejected' || $status === 'discrepancy' ? (string) ($r['discrepancy_reason'] ?: $r['notes']) : '',
                'orders'                 => array_map(static fn($o) => ['order_number' => $o['order_number'], 'total' => (float) $o['total']], $orders),
            ];
            break;

        default:
            throw new Exception('Invalid action specified.');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);