<?php
/**
 * Rider self-assignment: POST { "order_id": 123 } -> the logged-in rider accepts an unassigned
 * To Ship delivery order. The rider id always comes from the verified login, never from the browser.
 * All checks + the atomic assignment + the admin notification: rider_accept_order()
 * in app/helpers/rider_dispatch_helper.php.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/rider_dispatch_helper.php';

function accept_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    accept_respond(['success' => false, 'message' => 'POST method required.'], 405);
}
$payload = verifyJWT((string) getJWTFromCookie());
if (!$payload || strtolower(trim((string) ($payload['role'] ?? ''))) !== 'rider' || (int) ($payload['user_id'] ?? 0) <= 0) {
    accept_respond(['success' => false, 'message' => 'Unauthorized access token'], 401);
}
if (!csrf_check()) {
    accept_respond(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
}
$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int) ($input['order_id'] ?? 0);
if ($order_id <= 0) {
    accept_respond(['success' => false, 'message' => 'Invalid order.'], 422);
}

$result = rider_accept_order($conn, (int) $payload['user_id'], $order_id);
$status = $result['ok'] ? 200 : (['taken' => 409, 'status' => 409, 'unavailable' => 409, 'missing' => 404, 'pickup' => 422][$result['code']] ?? 400);
accept_respond([
    'success' => $result['ok'],
    'message' => $result['message'],
    'data'    => ['order_id' => $order_id, 'code' => $result['code'], 'order_number' => $result['order_number'] ?? null],
], $status);
