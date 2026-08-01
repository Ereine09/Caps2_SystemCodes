<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    $payload = verifyJWT($token);
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }
    $rider_id = (int)$payload['user_id'];

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_my_balance':
            // Query for the total unremitted balance
            $balance_query = "
                SELECT COALESCE(SUM(o.total), 0) AS total_unremitted_cod
                FROM tbl_orders o
                JOIN tbl_delivery d ON o.id = d.order_id
                WHERE d.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0;
            ";
            $stmt_balance = $conn->prepare($balance_query);
            $stmt_balance->bind_param('i', $rider_id);
            $stmt_balance->execute();
            $balance_result = $stmt_balance->get_result()->fetch_assoc();
            $stmt_balance->close();

            // Query for the individual orders making up the balance
            $orders_query = "
                SELECT o.id AS order_id, o.order_number, o.total, o.created_at
                FROM tbl_orders o
                JOIN tbl_delivery d ON o.id = d.order_id
                WHERE d.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                ORDER BY o.created_at ASC;
            ";
            $stmt_orders = $conn->prepare($orders_query);
            $stmt_orders->bind_param('i', $rider_id);
            $stmt_orders->execute();
            $orders = $stmt_orders->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_orders->close();

            $response['success'] = true;
            $response['message'] = 'Balance fetched successfully.';
            $response['data'] = array_merge($balance_result, ['orders' => $orders]);
            break;

        default:
            throw new Exception('Invalid action specified.');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);