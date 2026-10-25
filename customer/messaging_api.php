<?php
/**
 * Customer Messaging API Endpoint
 * Handles message operations for customers
 */

header('Content-Type: application/json');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/messaging_helper.php';

$customer = current_customer();
$customer_id = $customer ? (int) $customer['id'] : 0;
if ($customer_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if (in_array($action, ['send_message', 'connect_staff', 'mark_all_as_read', 'mark_as_read', 'delete_message'], true)
        && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('This action requires POST.');
    }

    switch ($action) {
        case 'send_message':
            // Send message
            $message = trim($_POST['message'] ?? '');
            
            if (empty($message)) {
                throw new Exception('Message cannot be empty');
            }
            
            $user_id = null; // Will be assigned by admin/staff
            if (send_message($customer_id, $user_id, 'customer', $message, (int) ($_POST['reply_to_id'] ?? 0))) {
                $response['success'] = true;
                $response['message'] = 'Message sent successfully';
            } else {
                throw new Exception('Failed to send message');
            }
            break;

        case 'connect_staff':
            // AI -> Human Handoff: silence the AI Auto-Reply and post one
            // system message into the customer's existing conversation.
            if ($customer_id > 0 && request_staff_handoff($conn, $customer_id)) {
                $response['success'] = true;
                $response['message'] = 'Staff has been notified';
            } else {
                throw new Exception('Failed to notify staff');
            }
            break;

        case 'get_messages':
            // Get all messages for customer
            $messages = get_customer_messages($customer_id);

            // Mirrors modules/admin/messaging_api.php's own get_messages case
            // ("Automatically mark customer messages as read when fetched by
            // staff/admin") - the customer side never had the equivalent,
            // which mattered once messages.php stopped doing a full page
            // reload (a reload's initial PHP render was the only place this
            // used to happen; polling/WS-triggered fetches here never did).
            if ($messages) {
                foreach ($messages as $msg) {
                    if ((int) $msg['is_read'] === 0 && $msg['sender_type'] !== 'customer') {
                        mark_as_read($msg['id']);
                    }
                }
                $messages = get_customer_messages($customer_id); // refresh is_read for the response
            }

            $response['success'] = true;
            $response['data'] = messaging_attach_extras($conn, $messages ?: [], 'customer', $customer_id);
            break;

        case 'react':
            // One emoji per person per message; the same emoji again removes it.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                throw new Exception('This action requires POST.');
            }
            csrf_require();
            if (!messaging_toggle_reaction($conn, (int) ($_POST['message_id'] ?? 0), $customer_id, 'customer', $customer_id, (string) ($_POST['emoji'] ?? ''))) {
                throw new Exception('That reaction could not be saved.');
            }
            $response['success'] = true;
            break;

        case 'unsend':
            // The customer takes back their OWN message, for everyone.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                throw new Exception('This action requires POST.');
            }
            csrf_require();
            if (!messaging_unsend($conn, (int) ($_POST['message_id'] ?? 0), $customer_id, 'customer', $customer_id)) {
                throw new Exception('You can only delete your own messages for everyone.');
            }
            $response['success'] = true;
            break;

        case 'delete_for_me':
            // Hides a message from this customer's own view only.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                throw new Exception('This action requires POST.');
            }
            csrf_require();
            if (!messaging_hide_for_me($conn, (int) ($_POST['message_id'] ?? 0), $customer_id, 'customer', $customer_id)) {
                throw new Exception('That message could not be deleted.');
            }
            $response['success'] = true;
            break;

        case 'get_unread_count':
            // Get unread message count
            $count = get_unread_count_customer($customer_id);
            $response['success'] = true;
            $response['data'] = ['unread_count' => $count];
            break;

        case 'mark_all_as_read':
            // Mark all messages as read
            if (mark_all_as_read($customer_id)) {
                $response['success'] = true;
                $response['message'] = 'All messages marked as read';
            } else {
                throw new Exception('Failed to mark messages as read');
            }
            break;

        case 'mark_as_read':
            // Mark specific message as read
            $message_id = intval($_POST['message_id'] ?? 0);
            
            if ($message_id <= 0) {
                throw new Exception('Invalid message ID');
            }
            
            if (mark_as_read($message_id)) {
                $response['success'] = true;
                $response['message'] = 'Message marked as read';
            } else {
                throw new Exception('Failed to mark message as read');
            }
            break;

        case 'delete_message':
            // Delete message
            $message_id = intval($_POST['message_id'] ?? 0);
            
            if ($message_id <= 0) {
                throw new Exception('Invalid message ID');
            }
            
            if (delete_message($message_id, null, $customer_id)) {
                $response['success'] = true;
                $response['message'] = 'Message deleted successfully';
            } else {
                throw new Exception('Failed to delete message');
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>
