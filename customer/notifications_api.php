<?php
/**
 * Customer Notifications API (JSON)
 *
 * Lets the customer web portal read their in-app notifications from the shared
 * `notifications` table. Authenticates the customer via JWT cookie/header.
 *
 * GET  ?action=get_unread_count         - unread notification count
 * GET  ?action=get_list&limit=50        - recent notifications (newest first)
 * GET  ?action=mark_all_read            - mark all unread as read
 *
 * Customer JWT has role='customer' and user_id = customer id.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => 'An error occurred.',
    'data' => null,
];

try {
    $customer_id = 0;
    if (customer_is_logged_in()) {
        $customer = current_customer();
        $customer_id = $customer ? (int)$customer['id'] : 0;
    }
    if ($customer_id <= 0) {
        // Fall back to Authorization header (web app often uses cookie).
        throw new Exception('Not authenticated.');
    }

    notifications_ensure_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }
        $action = $body['action'] ?? ($_POST['action'] ?? '');
    }

    switch ($action) {
        case 'get_unread_count': {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM notifications
                 WHERE customer_id = ? AND is_read = 0"
            );
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Unread count fetched.';
            $response['data'] = ['unread_count' => (int)($row['total'] ?? 0)];
            break;
        }

        case 'get_list': {
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

            $stmt = $conn->prepare(
                "SELECT id, type, title, message, reference_table, reference_id, is_read, created_at
                 FROM notifications
                 WHERE customer_id = ?
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->bind_param('ii', $customer_id, $limit);
            $stmt->execute();
            $rows = $stmt->get_result();
            $notifications = [];
            while ($row = $rows->fetch_assoc()) {
                $notifications[] = $row;
            }
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Notifications fetched.';
            $response['data'] = ['notifications' => $notifications];
            break;
        }

        case 'mark_all_read': {
            $stmt = $conn->prepare(
                "UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE customer_id = ? AND is_read = 0"
            );
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'All notifications marked as read.';
            $response['data'] = ['customer_id' => $customer_id];
            break;
        }

        default:
            throw new Exception('Invalid action specified.');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(401);
}

echo json_encode($response);

