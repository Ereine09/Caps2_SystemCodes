<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
require_once __DIR__ . '/../app/helpers/notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'get_deliveries':
            $rider_id = (int)($_GET['rider_id'] ?? 0);

            $query = "
                SELECT 
                    o.id, o.order_number, o.order_status, o.fulfillment_type, o.total,
                    o.delivery_address, o.delivery_phone, o.payment_method,
                    c.name as customer_name, c.phone as customer_phone,
                    GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') AS items_summary
                FROM tbl_orders o
                JOIN customers c ON o.customer_id = c.id
                LEFT JOIN tbl_order_items oi ON o.id = oi.order_id
                WHERE o.fulfillment_type = 'delivery' 
                AND o.order_status NOT IN ('completed', 'cancelled', 'pending')
                GROUP BY o.id, o.order_number, o.order_status, o.fulfillment_type, o.total, o.delivery_address, o.delivery_phone, o.payment_method, c.name, c.phone
                ORDER BY o.created_at DESC
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Query prepare failed: ' . $conn->error);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $deliveries = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $response['success'] = true;
            $response['data'] = $deliveries;
            break;

        case 'get_delivery_details':
            $order_id = (int)($_GET['order_id'] ?? 0);
            if ($order_id <= 0) throw new Exception('Invalid Order ID');

            $order_query = "
                SELECT 
                    o.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
                FROM tbl_orders o
                JOIN customers c ON o.customer_id = c.id
                WHERE o.id = ?
            ";
            $stmt = $conn->prepare($order_query);
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $order_details = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order_details) throw new Exception('Order not found.');

            $items_stmt = $conn->prepare("SELECT * FROM tbl_order_items WHERE order_id = ?");
            $items_stmt->bind_param('i', $order_id);
            $items_stmt->execute();
            $order_items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $items_stmt->close();

            $order_details['items'] = $order_items;

            $response['success'] = true;
            $response['data'] = $order_details;
            break;

        case 'update_delivery_status':
            $order_id = (int)($_POST['order_id'] ?? 0);
            $rider_id = (int)($_POST['rider_id'] ?? 0);
            $new_status = trim($_POST['status'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $proof_image_url = null;
            
            $allowed_statuses = ['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed_delivery'];
            if ($order_id <= 0 || $rider_id <= 0 || !in_array($new_status, $allowed_statuses)) {
                throw new Exception('Invalid data for status update.');
            }

            $conn->begin_transaction();
            try {
                $order_status_map = [
                    'accepted' => 'confirmed',
                    'picked_up' => 'processing',
                    'out_for_delivery' => 'out_for_delivery',
                    'delivered' => 'completed',
                    'failed_delivery' => 'cancelled'
                ];
                $mapped_status = $order_status_map[$new_status];

                $update_order_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = ? WHERE id = ?");
                $update_order_stmt->bind_param('si', $mapped_status, $order_id);
                $update_order_stmt->execute();
                $update_order_stmt->close();

                if ($new_status === 'delivered' && isset($_FILES['proof_image'])) {
                    $file = $_FILES['proof_image'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = __DIR__ . '/../../uploads/pod/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                        if (!in_array($file['type'], $allowed_types)) {
                            throw new Exception('Invalid file type for proof. Please upload a JPG, PNG, or GIF.');
                        }

                        $filename = 'pod_' . $order_id . '_' . uniqid() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
                        $destination = $upload_dir . $filename;

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            $proof_image_url = '/uploads/pod/' . $filename;
                        } else {
                            throw new Exception('Failed to save proof of delivery image.');
                        }
                    }
                }

                $log_stmt = $conn->prepare(
                    "INSERT INTO delivery_tracking (order_id, rider_id, status, notes, proof_image_url) VALUES (?, ?, ?, ?, ?)"
                );
                $log_stmt->bind_param('iisss', $order_id, $rider_id, $new_status, $notes, $proof_image_url);
                $log_stmt->execute();
                $log_stmt->close();

                $conn->commit();
                $response['success'] = true;
                $response['message'] = 'Delivery status updated to ' . $new_status;

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'verify_delivery_qr':
            $rider_id = (int)($_POST['rider_id'] ?? 0);
            $qr_token = trim($_POST['qr_token'] ?? '');

            if ($rider_id <= 0 || empty($qr_token)) {
                throw new Exception('Invalid rider or QR token.');
            }

            $qr_data = json_decode($qr_token, true);
            if (!$qr_data || !isset($qr_data['delivery_id']) || !isset($qr_data['token'])) {
                throw new Exception('Invalid QR code format.');
            }

            $delivery_details_id = (int)$qr_data['delivery_id'];
            $token_from_qr = $qr_data['token'];

            $stmt = $conn->prepare(
                "SELECT dd.order_id, dd.qr_confirmation_token, o.order_status, o.customer_id, o.subtotal, o.vat_amount
                 FROM tbl_deliveries dd
                 JOIN tbl_orders o ON dd.order_id = o.id
                 WHERE dd.id = ?"
            );
            $stmt->bind_param('i', $delivery_details_id);
            $stmt->execute();
            $delivery_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$delivery_info) {
                throw new Exception('Delivery record not found for this QR code.');
            }

            if ($delivery_info['order_status'] === 'completed') {
                throw new Exception('This order has already been completed.');
            }

            if ($delivery_info['order_status'] !== 'out_for_delivery') {
                throw new Exception('Order is not yet out for delivery.');
            }

            if (!hash_equals((string)$delivery_info['qr_confirmation_token'], $token_from_qr)) {
                throw new Exception('QR Code is invalid or does not match the order.');
            }

            $conn->begin_transaction();
            try {
                $update_order_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed' WHERE id = ?");
                $update_order_stmt->bind_param('i', $delivery_info['order_id']);
                $update_order_stmt->execute();
                $update_order_stmt->close();

                $update_delivery_stmt = $conn->prepare("UPDATE tbl_deliveries SET delivery_status = 'delivered', delivered_at = NOW() WHERE delivery_id = ?");
                $update_delivery_stmt->bind_param('i', $delivery_details_id);
                $update_delivery_stmt->execute();
                $update_delivery_stmt->close();

                // --- Loyalty Points Logic on Order Completion ---
                $customer_id = (int)$delivery_info['customer_id'];
                $order_subtotal_gross = (float)$delivery_info['subtotal'];
                $order_vat = (float)$delivery_info['vat_amount'];
                $order_subtotal_net = $order_subtotal_gross - $order_vat;

                $points_earned = round($order_subtotal_net / 100, 2);

                if ($points_earned > 0) {
                    $previous_points_query = mysqli_query($conn, "SELECT loyalty_points, name, email FROM customers WHERE id = " . $customer_id);
                    $customer_data = mysqli_fetch_assoc($previous_points_query);
                    $previous_points = (float)($customer_data['loyalty_points'] ?? 0.00);
                    $client_name = $customer_data['name'] ?? 'Customer';
                    $client_email = $customer_data['email'] ?? '';

                    $transaction_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned, order_id) VALUES (?, NULL, ?, 0.00, ?, ?)");
                    $product_name_for_transaction = 'Online Purchase (Order #' . $delivery_info['order_id'] . ')';
                    if ($transaction_stmt) {
                        $transaction_stmt->bind_param("isdi", $customer_id, $product_name_for_transaction, $points_earned, $delivery_info['order_id']);
                        $transaction_stmt->execute();
                        $transaction_id = (int) $conn->insert_id;
                        $transaction_stmt->close();
                    } else {
                        $transaction_id = 0;
                    }

                    $new_total_points = notifications_sync_customer_loyalty_points($conn, $customer_id);

                    $update_points_stmt = $conn->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
                    if ($update_points_stmt) {
                        $update_points_stmt->bind_param("di", $new_total_points, $customer_id);
                        $update_points_stmt->execute();
                        $update_points_stmt->close();
                    }

                    notifications_create($conn, [
                        'customer_id' => $customer_id,
                        'type' => 'points_earned',
                        'channel' => 'both',
                        'title' => 'You earned ' . notifications_format_points($points_earned) . ' points!',
                        'message' => $client_name . ' earned ' . notifications_format_points($points_earned) . ' from your purchase. New usable balance: ' . notifications_format_points($new_total_points) . ' points.',
                        'reference_table' => 'loyalty_transactions',
                        'reference_id' => $transaction_id,
                        'email_to' => $client_email
                    ]);

                    foreach (notifications_crossed_thresholds($previous_points, $new_total_points) as $threshold) {
                        notifications_create($conn, [
                            'customer_id' => $customer_id,
                            'type' => 'reward_redeemable',
                            'channel' => 'in_app',
                            'title' => 'You can now redeem a reward',
                            'message' => $client_name . ' now has ' . notifications_format_points($new_total_points) . ' usable points and unlocked the ' . notifications_format_points($threshold) . '-point reward tier.'
                        ]);
                    }
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception('Failed to update order status: ' . $e->getMessage());
            }

            $response['success'] = true;
            $response['message'] = 'Delivery Confirmed! Order status has been updated to Completed.';
            $response['data'] = ['order_id' => $delivery_info['order_id']];
            break;

        default:
            throw new Exception('Invalid API action');
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>