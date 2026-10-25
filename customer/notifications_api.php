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
 * POST {"action":"mark_read","notification_id":123} - mark one own notification as read
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
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
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
    $body = [];
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
    if ($method === 'GET' && !in_array($action, ['get_unread_count', 'get_list'], true)) {
        http_response_code(405);
        throw new Exception('This action requires POST.');
    }

    // Only rows addressed to this customer (see notifications_recipient_sql()).
    [$n_scope, $n_types, $n_args] = notifications_recipient_sql('customer', $customer_id);

    switch ($action) {
        case 'mark_read': {
            $notification_id = (int)($body['notification_id'] ?? $_POST['notification_id'] ?? 0);
            if ($notification_id <= 0) {
                throw new Exception('Invalid notification ID.');
            }

            $stmt = $conn->prepare(
                "UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE id = ? AND $n_scope"
            );
            $stmt->bind_param('i' . $n_types, $notification_id, ...$n_args);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                throw new Exception('Notification not found.');
            }
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Notification marked as read.';
            $response['data'] = ['notification_id' => $notification_id];
            break;
        }

        case 'get_unread_count': {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM notifications
                 WHERE $n_scope AND is_read = 0"
            );
            $stmt->bind_param($n_types, ...$n_args);
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
                 WHERE $n_scope
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->bind_param($n_types . 'i', ...array_merge($n_args, [$limit]));
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
                 WHERE $n_scope AND is_read = 0"
            );
            $stmt->bind_param($n_types, ...$n_args);
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

