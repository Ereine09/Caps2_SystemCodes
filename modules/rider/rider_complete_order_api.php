<?php
/**
 * Rider Complete Order API (JSON)
 * POST JSON: { "order_number": "..." }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
// Isama rito ang iyong jwt_helper kung protektado ng login ang app mo
require_once __DIR__ . '/../../app/helpers/jwt_helper.php'; 
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php'; // New helper for schema management

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    // Ensure rider schema is up-to-date
    ensureRiderSchema($conn);

    // // Optional: I-verify ang JWT Token kung gusto mong protektado ang endpoint
    // $headers = getallheaders();
    // $authHeader = $headers['Authorization'] ?? '';
    // if (!$authHeader) throw new Exception('Unauthorized access');

    $input = json_decode(file_get_contents('php://input'), true);
    $order_number = trim((string)($input['order_number'] ?? ''));

    if ($order_number === '') {
        throw new Exception('Invalid receipt data or empty order number.');
    }

// I-check muna kung umiiral ang order na ito
    // Paki-adjust ang table name (`orders` / `tbl_orders`) at column names base sa database mo
    $stmt = $conn->prepare("SELECT id, order_status FROM tbl_orders WHERE order_number = ? LIMIT 1");
    $stmt->bind_param('s', $order_number);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception('Order not found in the system.');
    }

    if ($order['order_status'] === 'completed') {
        throw new Exception('This order is already marked as completed.');
    }

    // I-update ang status ng order tungo sa 'completed'
    $update_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed' WHERE order_number = ?");
    $update_stmt->bind_param('s', $order_number);

    if ($update_stmt->execute()) {
        $response['success'] = true;
        $response['message'] = "Order successfully marked as Completed!";
    } else {
        throw new Exception('Failed to update the database status.');
    }
    $update_stmt->close();

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>