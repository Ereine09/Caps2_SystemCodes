<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/jwt_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$allowed_statuses = [
    'pending', 'confirmed', 'processing', 'ready_for_pickup', 'to_ship',
    'to_receive', 'reviews', 'out_for_delivery', 'completed', 'cancelled'
];

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

function request_body(): array
{
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : $_POST;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

function require_staff(): array
{
    $payload = verifyJWT(bearer_token());
    if (!is_array($payload)) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if (!in_array($role, ['admin', 'staff'], true)) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    return $payload;
}

$body = request_body();
$action = (string)($body['action'] ?? $_GET['action'] ?? '');

if ($action === 'login') {
    $identity = trim((string)($body['username'] ?? $body['username_email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($identity === '' || $password === '') {
        respond(['success' => false, 'message' => 'Username and password are required.'], 422);
    }

    $stmt = $conn->prepare('SELECT id, username, email, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
    }

    $role = strtolower(trim((string)$user['role']));
    if (!in_array($role, ['admin', 'staff'], true)) {
        respond(['success' => false, 'message' => 'This account cannot access the staff app.'], 403);
    }

    $token = generateJWT((int)$user['id'], (string)$user['username'], $role);
    respond(['success' => true, 'data' => [
        'token' => $token,
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $role,
    ]]);
}

require_staff();

if ($action === 'lookup_order') {
    $qr = json_decode((string)($body['qr_data'] ?? ''), true);
    if (!is_array($qr) || empty($qr['delivery_id']) || empty($qr['order_id']) || empty($qr['token'])) {
        respond(['success' => false, 'message' => 'Invalid delivery QR code.'], 422);
    }

    $stmt = $conn->prepare(
        'SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.total, o.created_at,
                c.name AS customer_name, c.email AS customer_email
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         INNER JOIN tbl_deliveries d ON d.order_id = o.id
         WHERE d.id = ? AND d.order_id = ? AND d.qr_confirmation_token = ? LIMIT 1'
    );
    $delivery_id = (int)$qr['delivery_id'];
    $order_id = (int)$qr['order_id'];
    $token = (string)$qr['token'];
    $stmt->bind_param('iis', $delivery_id, $order_id, $token);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        respond(['success' => false, 'message' => 'No matching order was found for this QR code.'], 404);
    }
    respond(['success' => true, 'data' => $order]);
}

if ($action === 'update_status') {
    $order_id = (int)($body['order_id'] ?? 0);
    $status = (string)($body['status'] ?? '');
    if ($order_id <= 0 || !in_array($status, $allowed_statuses, true)) {
        respond(['success' => false, 'message' => 'Invalid order or status.'], 422);
    }

    $current_stmt = $conn->prepare('SELECT order_status FROM tbl_orders WHERE id = ? LIMIT 1');
    $current_stmt->bind_param('i', $order_id);
    $current_stmt->execute();
    $current = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    if (!$current) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if (in_array($current['order_status'], ['completed', 'cancelled'], true)) {
        respond(['success' => false, 'message' => 'Completed or cancelled orders cannot be updated.'], 409);
    }

    $stmt = $conn->prepare('UPDATE tbl_orders SET order_status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $order_id);
    $stmt->execute();
    $stmt->close();
    respond(['success' => true, 'message' => 'Order status updated.', 'data' => [
        'order_id' => $order_id,
        'order_status' => $status,
    ]]);
}

respond(['success' => false, 'message' => 'Unsupported action.'], 400);