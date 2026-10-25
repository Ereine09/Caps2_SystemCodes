<?php
/*
 * Admin/Staff Notifications API (JSON)
 *
 * Mirrors modules/rider/rider_notifications_api.php exactly - same thin
 * wrapper pattern around the EXISTING notification_helper.php functions
 * (notifications_get_unread_count / notifications_get_recent /
 * notifications_mark_all_read), just scoped to role admin/staff instead of
 * rider. No second notification system - this is the same one, reused.
 *
 * GET  - ?action=get_unread_count
 *        ?action=get_list&limit=20
 * POST - body: { "action": "mark_all_read" }
 *
 * Requires an admin/staff JWT (cookie jwt_token_staff, matching every other
 * admin/staff endpoint in this project).
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    $token = getJWTFromCookie();
    $payload = $token ? verifyJWT($token) : null;
    if (!$payload) {
        throw new Exception('Unauthorized');
    }

    $role = strtolower(trim((string) ($payload['role'] ?? '')));
    if (!in_array($role, ['admin', 'staff'], true)) {
        throw new Exception('Unauthorized role');
    }

    $user_id = (int) ($payload['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception('Invalid account session');
    }

    notifications_ensure_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'get_unread_count') {
            $unread = notifications_get_unread_count($conn, $user_id);
            $response['success'] = true;
            $response['data'] = ['unread_count' => $unread];
        } elseif ($action === 'get_list') {
            $limit = (int) ($_GET['limit'] ?? 20);
            $notifications = notifications_get_recent($conn, $user_id, $limit);
            $response['success'] = true;
            $response['data'] = ['notifications' => $notifications];
        } else {
            throw new Exception('Invalid action specified.');
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $action = $input['action'] ?? '';

        if ($action === 'mark_all_read') {
            $updated = notifications_mark_all_read($conn, $user_id);
            $response['success'] = true; // "nothing to mark" is not a failure
            $response['data'] = ['updated' => $updated];
        } else {
            throw new Exception('Invalid action specified.');
        }
    } else {
        throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
