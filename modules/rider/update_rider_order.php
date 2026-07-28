<?php
/**
 * Update Rider Order API
 *
 * POST JSON: { "order_id": 123, "action": "accept" | "reject" }
 * Requires rider JWT authentication.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

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
    $stmt = $conn->prepare('SELECT id, order_status, rider_id FROM tbl_orders WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception('Order not found');
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