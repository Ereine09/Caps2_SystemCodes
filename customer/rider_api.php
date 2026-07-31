<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php'; // New helper for schema management


$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// try {
//     // Ensure database schema is ready for rider features
//     ensureRiderSchema($conn);

    // --- Rider Authentication (Future Enhancement) ---
    // For now, we'll proceed without strict JWT checks for this new module.
    // In a real scenario, you'd verify a rider-specific JWT.
    // $token = getJWTFromCookie();
    // $payload = verifyJWT($token);
    // if (!$payload || $payload['role'] !== 'rider') {
    //     throw new Exception('Unauthorized Rider');
    // }
//     $rider_user_id = (int)$payload['user_id'];

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'get_deliveries':
            // This would fetch deliveries assigned to a specific rider
            // For now, let's fetch all deliverable orders as a placeholder
            $rider_id = (int)($_GET['rider_id'] ?? 0); // This would come from JWT

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
                -- AND o.rider_id = ? -- Uncomment when rider assignment is implemented
                GROUP BY o.id, o.order_number, o.order_status, o.fulfillment_type, o.total, o.delivery_address, o.delivery_phone, o.payment_method, c.name, c.phone
                ORDER BY o.created_at DESC
            ";
            
            $stmt = $conn->prepare($query);
            // $stmt->bind_param('i', $rider_id); // Uncomment for specific rider
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

            // Fetch order details
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

            // Fetch order items
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
            $rider_id = (int)($_POST['rider_id'] ?? 0); // This would come from JWT
            $new_status = trim($_POST['status'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $proof_image_url = null;
            
            $allowed_statuses = ['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed_delivery'];
            if ($order_id <= 0 || $rider_id <= 0 || !in_array($new_status, $allowed_statuses)) {
                throw new Exception('Invalid data for status update.');
            }

            $conn->begin_transaction();
            try {
                // 1. Update the main order status in tbl_orders
                $order_status_map = [
                    'accepted' => 'confirmed',
                    'picked_up' => 'processing',
                    'out_for_delivery' => 'out_for_delivery',
                    'delivered' => 'completed',
                    'failed_delivery' => 'cancelled' // Or a new 'failed' status
                ];
                $mapped_status = $order_status_map[$new_status];

                $update_order_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = ? WHERE id = ?");
                $update_order_stmt->bind_param('si', $mapped_status, $order_id);
                $update_order_stmt->execute();
                $update_order_stmt->close();

                // Handle proof of delivery upload if status is 'delivered'
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
                            $proof_image_url = '/uploads/pod/' . $filename; // Relative URL to store in DB
                        } else {
                            throw new Exception('Failed to save proof of delivery image.');
                        }
                    }
                }

                // 2. Log this event in the delivery_tracking table
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

        case 'toggle_duty_status':
            $rider_id = (int)($_POST['rider_id'] ?? 0); // This would come from JWT
            $is_on_duty = (int)($_POST['is_on_duty'] ?? 0);

            if ($rider_id <= 0) throw new Exception('Invalid Rider ID.');

            $stmt = $conn->prepare("UPDATE riders SET is_on_duty = ? WHERE id = ?");
            $stmt->bind_param('ii', $is_on_duty, $rider_id);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Duty status updated.';
            break;

        case 'verify_delivery_qr':
            $rider_id = (int)($_POST['rider_id'] ?? 0); // This would come from JWT
            $qr_token = trim($_POST['qr_token'] ?? '');

            if ($rider_id <= 0 || empty($qr_token)) {
                throw new Exception('Invalid rider or QR token.');
            }

            // The QR data is expected to be a JSON string: {"delivery_id": X, "token": "..."}
            $qr_data = json_decode($qr_token, true);
            if (!$qr_data || !isset($qr_data['delivery_id']) || !isset($qr_data['token'])) {
                throw new Exception('Invalid QR code format.');
            }

            $delivery_details_id = (int)$qr_data['delivery_id'];
            $token_from_qr = $qr_data['token'];

            // Fetch the delivery details from the database to verify the token
            // CORRECTED: Use tbl_delivery, not delivery_details
            $stmt = $conn->prepare(
                "SELECT dd.order_id, dd.qr_confirmation_token, o.order_status 
                 FROM tbl_delivery dd
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

            // Securely compare tokens
            if (!hash_equals((string)$delivery_info['qr_confirmation_token'], $token_from_qr)) {
                throw new Exception('QR Code is invalid or does not match the order.');
            }

            // --- Backend-Managed Status Update ---
            $conn->begin_transaction();
            try {
                // Update order status to completed
                $update_order_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed' WHERE id = ?");
                $update_order_stmt->bind_param('i', $delivery_info['order_id']);
                $update_order_stmt->execute();
                $update_order_stmt->close();

                // Update delivery status to delivered
                $update_delivery_stmt = $conn->prepare("UPDATE tbl_delivery SET status = 'delivered', delivered_at = NOW() WHERE id = ?");
                $update_delivery_stmt->bind_param('i', $delivery_details_id);
                $update_delivery_stmt->execute();
                $update_delivery_stmt->close();

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
//
// } catch (Exception $e) {
//     $response['success'] = false;
//     $response['message'] = $e->getMessage();
//     http_response_code(400);
// }

echo json_encode($response);
?>