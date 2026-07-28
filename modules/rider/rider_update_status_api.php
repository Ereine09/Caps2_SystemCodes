<?php
/**
 * Rider Update Status API
 *
 * Expected actions:
 * - picked_up
 * - out_for_delivery
 * - delivered
 * - cancelled
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

    $rider_id = (int)($payload['user_id'] ?? 0);
    if ($rider_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $delivery_id = (int)($input['delivery_id'] ?? 0);
    $order_id = (int)($input['order_id'] ?? 0);
    $status = strtolower(trim((string)($input['status'] ?? '')));

    if ($delivery_id <= 0 && $order_id <= 0) {
        throw new Exception('delivery_id or order_id is required');
    }
    if ($status === '') {
        throw new Exception('status is required');
    }

    $allowed = ['picked_up', 'out_for_delivery', 'delivered', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new Exception('Invalid status');
    }

    // Map rider status -> backend enum values
    $order_status_map = [
        'picked_up' => 'ready_for_pickup',
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'completed',
        'cancelled' => 'cancelled'
    ];

    $delivery_status_map = [
        'picked_up' => 'in_transit',
        'out_for_delivery' => 'in_transit',
        'delivered' => 'delivered',
        'cancelled' => 'failed'
    ];

    $order_status = $order_status_map[$status];
    $delivery_status = $delivery_status_map[$status];

    // Load order id if only delivery_id is provided
    if ($order_id <= 0) {
        $stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $delivery_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) throw new Exception('Delivery not found');
        $order_id = (int)$res['order_id'];
    }

    // Update tbl_orders
    $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
    $stmt1->bind_param('si', $order_status, $order_id);
    $stmt1->execute();
    $stmt1->close();

    // Update tbl_delivery if delivery_id exists
    if ($delivery_id > 0) {
        $stmt2 = $conn->prepare(
            "UPDATE tbl_delivery
             SET status = ?, delivered_at = CASE WHEN ? = 'delivered' THEN NOW() ELSE delivered_at END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $delStatus = $delivery_status;
        $deliveredFlag = $status === 'delivered' ? 'delivered' : 'not_delivered';
        $stmt2->bind_param('ssi', $delStatus, $deliveredFlag, $delivery_id);
        $stmt2->execute();
        $stmt2->close();
    }

    // Notify customer
    $customer_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
    $customer_stmt->bind_param('i', $order_id);
    $customer_stmt->execute();
    $customer_res = $customer_stmt->get_result()->fetch_assoc();
    $customer_stmt->close();

    $customer_id = $customer_res ? (int)$customer_res['customer_id'] : 0;

    if ($customer_id > 0) {
        notifications_create($conn, [
            'user_id' => $rider_id,
            'customer_id' => $customer_id,
            'type' => 'rider_status_update',
            'channel' => 'in_app',
            'title' => 'Delivery update',
            'message' => 'Your order has been updated by your rider.',
            'reference_table' => 'tbl_orders',
            'reference_id' => $order_id,
            'email_to' => null,
        ]);
    }

    $response['success'] = true;
    $response['data'] = [
        'order_id' => $order_id,
        'delivery_id' => $delivery_id,
        'order_status' => $order_status,
        'delivery_status' => $delivery_status,
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>