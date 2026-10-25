<?php
/**
 * Rider's answer to an order an admin assigned to them:
 * POST { "order_id": 123, "decision": "accept" | "decline", "reason": "optional, for decline" }
 * The rider id always comes from the verified login. Logic: rider_respond_assignment()
 * in app/helpers/rider_dispatch_helper.php.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/rider_dispatch_helper.php';

function assignment_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assignment_respond(['success' => false, 'message' => 'POST method required.'], 405);
}
$payload = verifyJWT((string) getJWTFromCookie());
if (!$payload || strtolower(trim((string) ($payload['role'] ?? ''))) !== 'rider' || (int) ($payload['user_id'] ?? 0) <= 0) {
    assignment_respond(['success' => false, 'message' => 'Unauthorized access token'], 401);
}
if (!csrf_check()) {
    assignment_respond(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
}
$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int) ($input['order_id'] ?? 0);
if ($order_id <= 0) {
    assignment_respond(['success' => false, 'message' => 'Invalid order.'], 422);
}

$result = rider_respond_assignment($conn, (int) $payload['user_id'], $order_id, (string) ($input['decision'] ?? ''), (string) ($input['reason'] ?? ''));
$status = $result['ok'] ? 200 : (['not_mine' => 409, 'closed' => 409, 'picked_up' => 409, 'invalid' => 422][$result['code']] ?? 400);
assignment_respond([
    'success' => $result['ok'],
    'message' => $result['message'],
    'data'    => ['order_id' => $order_id, 'code' => $result['code']],
], $status);
