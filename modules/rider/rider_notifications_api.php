<?php
/*
 * Rider Notifications API (JSON)
 *
 * GET  - Fetch unread count or list of notifications for the rider
 *        ?action=get_unread_count
 *        ?action=get_list&limit=20
 * POST - Mark all notifications as read
 *        body: { "action": "mark_all_read" }
 *
 * Requires rider JWT authentication.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
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

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    notifications_ensure_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'get_unread_count') {
            $unread = notifications_get_unread_count($conn, $rider_user_id);
            $response['success'] = true;
            $response['message'] = 'Unread count fetched successfully.';
            $response['data'] = ['unread_count' => $unread];
        } elseif ($action === 'get_list') {
            $limit = (int)($_GET['limit'] ?? 20);
            $notifications = notifications_get_recent($conn, $rider_user_id, $limit);
            $response['success'] = true;
            $response['message'] = 'Notifications fetched successfully.';
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
            $updated = notifications_mark_all_read($conn, $rider_user_id);
            $response['success'] = $updated;
            $response['message'] = $updated
                ? 'All notifications marked as read.'
                : 'No notifications to mark or failed to update.';
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
