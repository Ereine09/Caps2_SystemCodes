<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';
require_once __DIR__ . '/../app/helpers/loyalty_helper.php';

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
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['HTTP_X_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
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

if ($action === 'validate_session') {
    respond(['success' => true, 'data' => ['authenticated' => true]]);
}

if ($action === 'lookup_order') {
    $qr = json_decode((string)($body['qr_data'] ?? ''), true);
    if (!is_array($qr) || empty($qr['delivery_id']) || empty($qr['order_id']) || empty($qr['token'])) {
        respond(['success' => false, 'message' => 'Invalid delivery QR code.'], 422);
    }

    $stmt = $conn->prepare(
        'SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.total, o.created_at,
                  c.name AS customer_name, c.email AS customer_email,
                  d.id AS delivery_id
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
              INNER JOIN tbl_delivery d ON d.order_id = o.id
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

    $current_stmt = $conn->prepare(
        'SELECT o.order_status, o.order_number, o.customer_id, o.created_at,
                o.fulfillment_type, o.pickup_date, o.pickup_time,
                o.delivery_address, c.name AS customer_name, c.email AS customer_email
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ? LIMIT 1'
    );
    $current_stmt->bind_param('i', $order_id);
    $current_stmt->execute();
    $current = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    if (!$current) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if (in_array($current['order_status'], ['completed', 'cancelled'], true)) {
        if ($current['order_status'] === 'cancelled' && $status === 'completed') {
            respond(['success' => false, 'message' => 'Cancelled orders cannot be completed.'], 409);
        }
        respond(['success' => false, 'message' => 'Completed or cancelled orders cannot be updated.'], 409);
    }
    if ($current['order_status'] === $status) {
        respond(['success' => false, 'message' => 'Order is already ' . str_replace('_', ' ', $status) . '.'], 409);
    }

    $conn->begin_transaction();
    $award_result = [];
    try {
        $stmt = $conn->prepare('UPDATE tbl_orders SET order_status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $status, $order_id);
        $stmt->execute();
        $stmt->close();

        if (in_array($status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed'], true)) {
            $award_result = loyalty_award_completed_order($conn, $order_id);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Unable to update order status.'], 500);
    }

    notifications_ensure_schema($conn);
    $status_labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'to_ship' => 'To Ship',
        'to_receive' => 'To Receive',
        'out_for_delivery' => 'Out for Delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    $order_number = (string) ($current['order_number'] ?? ('DPS-' . $order_id));
    $customer_name = trim((string) ($current['customer_name'] ?? 'Customer')) ?: 'Customer';
    $status_label = $status_labels[$status] ?? $status;
    $message = "Hello {$customer_name},\n\n"
        . "Your order #{$order_number} has been updated.\n\n"
        . "Current Order Status:\n{$status_label}\n\n"
        . "Order Date: " . date('F j, Y', strtotime((string) $current['created_at'])) . "\n"
        . "Fulfillment: " . ucfirst((string) ($current['fulfillment_type'] ?? ''));
    if (!empty($current['pickup_date'])) {
        $message .= "\nPickup Date: {$current['pickup_date']}";
    }
    if (!empty($current['pickup_time'])) {
        $message .= "\nPickup Time: {$current['pickup_time']}";
    }
    if ($status === 'completed' && (float) ($award_result['points'] ?? 0) > 0) {
        $message .= "\n\nLoyalty Points Earned: " . notifications_format_points((float) $award_result['points'])
            . "\nCurrent Loyalty Points Balance: " . notifications_format_points((float) ($award_result['balance'] ?? 0));
    }
    $message .= "\n\nYou can log in to your Darius Poultry Supplies customer portal to view the latest details of your order.\n\nThank you for shopping with Darius Poultry Supplies.";

    $notification_result = [];
    notifications_create($conn, [
        'customer_id' => (int) ($current['customer_id'] ?? 0),
        'type' => 'order_status_update',
        'channel' => 'both',
        'title' => "Order #{$order_number} Status Updated",
        'message' => $message,
        'reference_table' => 'tbl_orders',
        'reference_id' => $order_id,
        'email_to' => (string) ($current['customer_email'] ?? ''),
    ], $notification_result);

    $email_sent = (bool) ($notification_result['email_sent'] ?? false);
    respond(['success' => true, 'message' => $email_sent
        ? 'Order status updated successfully.'
        : 'Order status updated, but email notification could not be sent.',
        'order_status' => $status,
        'email_sent' => $email_sent,
        'data' => [
        'order_id' => $order_id,
        'order_status' => $status,
    ]]);
}

respond(['success' => false, 'message' => 'Unsupported action.'], 400);