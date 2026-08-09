<?php
/**
 * Update Rider Order API
 *
 * POST JSON: { "order_id": 123, "action": "accept" | "reject" }
 * Requires rider JWT authentication.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    ensureRiderSchema($conn);

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
        throw new Exception('Unauthorized token');
    }

    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if ($role !== 'rider') {
        throw new Exception('Unauthorized role');
    }

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider user ID in token');
    }

    // --- Look up or auto-create the `id` from the `riders` table ---
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    if ($rider_stmt) {
        $rider_stmt->bind_param('i', $rider_user_id);
        $rider_stmt->execute();
        $rider_res = $rider_stmt->get_result()->fetch_assoc();
        $rider_stmt->close();

        if ($rider_res) {
            $rider_id = (int)$rider_res['id'];
        }
    }

    // Auto-create rider profile if user exists in users table but missing in riders table
    if ($rider_id <= 0) {
        $insert_rider_stmt = $conn->prepare("INSERT INTO riders (user_id) VALUES (?)");
        if ($insert_rider_stmt) {
            $insert_rider_stmt->bind_param('i', $rider_user_id);
            if ($insert_rider_stmt->execute()) {
                $rider_id = $conn->insert_id;
            }
            $insert_rider_stmt->close();
        }
    }

    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found. Please re-login or contact support.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON payload');
    }

    $order_id = (int)($input['order_id'] ?? 0);
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if ($order_id <= 0 || !in_array($action, ['accept', 'reject'], true)) {
        throw new Exception('Missing or invalid order_id / action');
    }

    $status_map = [
        'accept' => 'out_for_delivery',
        'reject' => 'pending',
    ];

    $new_status = $status_map[$action];

// Check if order exists
    $stmt = $conn->prepare('SELECT id, order_status, rider_id, fulfillment_type FROM tbl_orders WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception('Order not found');
    }

    // Pickup orders are fulfilled by the customer at the shop — never assigned to a rider.
    if (($order['fulfillment_type'] ?? '') === 'pickup') {
        throw new Exception('Pickup orders are not assigned to riders.');
    }

    // Execute update according to action
    if ($action === 'accept') {
        $update_sql = 'UPDATE tbl_orders SET order_status = ?, rider_id = ? WHERE id = ?';
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param('sii', $new_status, $rider_id, $order_id);
        $new_rider_id = $rider_id;
    } else {
        $update_sql = 'UPDATE tbl_orders SET order_status = ?, rider_id = NULL WHERE id = ?';
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param('si', $new_status, $order_id);
        $new_rider_id = null;
    }

if (!$stmt->execute()) {
        throw new Exception('Failed to update order');
    }
    $stmt->close();

    // --- Notify the customer when the rider ACCEPTS their order ---
    // The customer should know their order is now being delivered.
    if ($action === 'accept') {
        // Look up the customer who placed this order.
        $cust_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
        $cust_stmt->bind_param('i', $order_id);
        $cust_stmt->execute();
        $cust_res = $cust_stmt->get_result()->fetch_assoc();
        $cust_stmt->close();

        $customer_id = $cust_res ? (int)$cust_res['customer_id'] : 0;

        if ($customer_id > 0) {
            // Fetch the rider's name for the notification message.
            $rider_name = 'Your rider';
            $rn_stmt = $conn->prepare(
                "SELECT u.first_name, u.last_name FROM users u JOIN riders r ON r.user_id = u.id WHERE r.id = ? LIMIT 1"
            );
            if ($rn_stmt) {
                $rn_stmt->bind_param('i', $rider_id);
                $rn_stmt->execute();
                $rn_res = $rn_stmt->get_result()->fetch_assoc();
                $rn_stmt->close();
                if ($rn_res) {
                    $rider_name = trim(($rn_res['first_name'] ?? '') . ' ' . ($rn_res['last_name'] ?? ''));
                    if ($rider_name === '') {
                        $rider_name = 'Your rider';
                    }
                }
            }

// Create an in-app notification for the customer.
            // Note: `user_id` is set to the customer's id because the customer's
            // JWT uses the customer id as its user_id, which is what the WS server
            // keys connections by. This routes the real-time push to the customer.
            notifications_create($conn, [
                'user_id' => $customer_id,
                'customer_id' => $customer_id,
                'type' => 'order_accepted',
                'channel' => 'in_app',
                'title' => 'Order Accepted',
                'message' => $rider_name . ' has accepted your order and is now on the way to deliver it.',
                'reference_table' => 'tbl_orders',
                'reference_id' => $order_id,
                'email_to' => null,
            ]);

            // --- Remind the rider to prepare the exact COD amount ---
            // Push an in-app notification to the rider (keyed by their user id)
            // so they are reminded to prepare the exact cash amount to collect.
            if ($customer_id > 0) {
                // Fetch the order total for the reminder message.
                $amt_stmt = $conn->prepare("SELECT total, payment_method FROM tbl_orders WHERE id = ? LIMIT 1");
                $amt_stmt->bind_param('i', $order_id);
                $amt_stmt->execute();
                $amt_res = $amt_stmt->get_result()->fetch_assoc();
                $amt_stmt->close();

                $order_total = (float)($amt_res['total'] ?? 0.00);
                $pay_method = strtolower((string)($amt_res['payment_method'] ?? 'cod'));

                $reminder_message = 'Reminder: Please prepare the exact amount of PHP ' . number_format($order_total, 2) . ' to collect for this order.';
                if ($pay_method !== 'cod') {
                    $reminder_message = 'Order accepted. Payment method: ' . strtoupper($pay_method) . '.';
                }

                notifications_create($conn, [
                    'user_id' => $rider_user_id,
                    'customer_id' => $customer_id,
                    'type' => 'rider_reminder',
                    'channel' => 'in_app',
                    'title' => 'Prepare Exact Amount',
                    'message' => $reminder_message,
                    'reference_table' => 'tbl_orders',
                    'reference_id' => $order_id,
                    'email_to' => null,
                ]);
            }
        }
    }

    $response['success'] = true;
    $response['message'] = $action === 'accept' ? 'Order accepted successfully.' : 'Order rejected successfully.';
    $response['data'] = [
        'order_id' => $order_id,
        'status' => $new_status,
        'rider_id' => $new_rider_id,
    ];
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>