<?php
/**
 * Staff — Messaging API endpoint (JSON).
 *
 * Self-contained: it does NOT depend on modules/admin/messaging_api.php.
 * Every action requires a valid STAFF JWT. The acting user id and sender type
 * are taken from the verified token, never from the request body.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload   = require_staff_api();            // 401 JSON if not a valid staff token
$actor_id  = (int) $payload['user_id'];

// State-changing calls arrive as POST and must carry the CSRF token
// (sent by the page as the `csrf_token` field or the X-CSRF-Token header).
csrf_require();

$response = ['success' => false, 'message' => '', 'data' => null];

// Ensure typing-status columns exist (same schema the admin messaging UI expects).
$check_cols = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'admin_typing_at'");
if ($check_cols && mysqli_num_rows($check_cols) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN admin_typing_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN last_typing_at TIMESTAMP NULL DEFAULT NULL");
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'send_message':
            $customer_id = (int) ($_POST['customer_id'] ?? 0);
            $message     = trim($_POST['message'] ?? '');
            if ($customer_id <= 0 || $message === '') {
                throw new Exception('Invalid customer ID or empty message.');
            }
            if (send_message($customer_id, $actor_id, 'staff', $message)) {
                $response['success'] = true;
                $response['message'] = 'Message sent successfully.';
            } else {
                throw new Exception('Failed to send message.');
            }
            break;

        case 'get_messages':
            $customer_id = (int) ($_GET['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                throw new Exception('Invalid customer ID.');
            }
            $messages = get_conversation($customer_id, $actor_id);
            if ($messages) {
                foreach ($messages as $msg) {
                    if ((int) $msg['is_read'] === 0 && $msg['sender_type'] === 'customer') {
                        mark_as_read($msg['id']);
                    }
                }
            }
            $response['success'] = true;
            $response['data']    = $messages;
            break;

        case 'get_conversations':
            $response['success'] = true;
            $response['data']    = get_customers_with_messages();
            break;

        case 'mark_as_read':
            $message_id = (int) ($_POST['message_id'] ?? 0);
            if ($message_id <= 0) {
                throw new Exception('Invalid message ID.');
            }
            $response['success'] = (bool) mark_as_read($message_id);
            $response['message'] = $response['success'] ? 'Message marked as read.' : 'Failed to mark as read.';
            break;

        case 'get_unread_count':
            $response['success'] = true;
            $response['data']    = ['unread_count' => get_unread_count_staff($actor_id)];
            break;

        case 'delete_message':
            $message_id = (int) ($_POST['message_id'] ?? 0);
            if ($message_id <= 0) {
                throw new Exception('Invalid message ID.');
            }
            delete_message($message_id, $actor_id);
            $response['success'] = true;
            $response['message'] = 'Message deleted.';
            break;

        case 'get_typing_status':
            $customer_id = (int) ($_GET['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                throw new Exception('Invalid ID.');
            }
            $stmt = $conn->prepare("SELECT last_typing_at FROM customers WHERE id = ?");
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $is_typing = $res && $res['last_typing_at'] && (time() - strtotime($res['last_typing_at'])) < 6;
            $response['success'] = true;
            $response['data']    = ['is_typing' => (bool) $is_typing];
            break;

        case 'set_typing_status':
            $customer_id = (int) ($_POST['customer_id'] ?? 0);
            if ($customer_id > 0) {
                $stmt = $conn->prepare("UPDATE customers SET admin_typing_at = NOW() WHERE id = ?");
                $stmt->bind_param('i', $customer_id);
                $stmt->execute();
                $stmt->close();
                $response['success'] = true;
            }
            break;

        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
