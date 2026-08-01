<?php
/**
 * Rider QR Confirm API
 *
 * Expected payload (JSON):
 * {
 *   "delivery_id": 123,
 *   "qr_code": "{\"delivery_id\":123,\"token\":\"...\"}"
 * }
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
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

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
    $qr_code_content = trim((string)($input['qr_code'] ?? ''));

    if ($delivery_id <= 0) throw new Exception('delivery_id is required');
    if ($qr_code_content === '') throw new Exception('qr_code is required');

    // --- Validate QR Code Token ---
    $qr_data = json_decode($qr_code_content, true);
    if (!is_array($qr_data) || !isset($qr_data['delivery_id']) || !isset($qr_data['token'])) {
        throw new Exception('Invalid QR code format');
    }

    $qr_delivery_id = (int)$qr_data['delivery_id'];
    $qr_token = (string)$qr_data['token'];

    if ($delivery_id !== $qr_delivery_id) {
        throw new Exception('QR code does not match this delivery');
    }

    $stmt = $conn->prepare("SELECT order_id, qr_confirmation_token FROM tbl_delivery WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $delivery_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) throw new Exception('Delivery not found');

    if (!$res['qr_confirmation_token'] || $res['qr_confirmation_token'] !== $qr_token) {
        throw new Exception('Invalid confirmation token.');
    }

    $order_id = (int)$res['order_id'];

    // Update order status to completed
    $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed', updated_at = NOW() WHERE id = ?");
    $stmt1->bind_param('i', $order_id);
    $stmt1->execute();
    $stmt1->close();

    // Update delivery status to delivered
    $stmt2 = $conn->prepare(
        "UPDATE tbl_delivery 
         SET status = 'delivered', delivered_at = NOW(), updated_at = NOW(), rider_id = ?
         WHERE id = ?"
    );
    $stmt2->bind_param('ii', $rider_id, $delivery_id);
    $stmt2->execute();
    $stmt2->close();

    // Nullify the token after use to prevent re-scanning
    $clear_stmt = $conn->prepare("UPDATE tbl_delivery SET qr_confirmation_token = NULL WHERE id = ?");
    $clear_stmt->bind_param('i', $delivery_id);
    $clear_stmt->execute();
    $clear_stmt->close();

    // Notify Customer
    $cust_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
    $cust_stmt->bind_param('i', $order_id);
    $cust_stmt->execute();
    $cust_res = $cust_stmt->get_result()->fetch_assoc();
    $cust_stmt->close();

    $customer_id = $cust_res ? (int)$cust_res['customer_id'] : 0;

    notifications_create($conn, [
        'user_id' => $rider_id,
        'customer_id' => $customer_id,
        'type' => 'rider_qr_confirm',
        'channel' => 'in_app',
        'title' => 'Delivery confirmed',
        'message' => 'Your delivery has been confirmed by the rider.',
        'reference_table' => 'tbl_orders',
        'reference_id' => $order_id,
        'email_to' => null,
    ]);

    $response['success'] = true;
    $response['data'] = [
        'delivery_id' => $delivery_id,
        'order_id' => $order_id,
        'confirmed' => true,
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>