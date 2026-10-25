<?php
/**
 * Customer Messaging API Endpoint
 * Handles message operations for customers
 */

header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../app/helpers/jwt_helper.php';

/*
 * The customer portal is entirely JWT-based (customer_login() never sets
 * $_SESSION['customer_id'] - see customer/includes/auth.php) so the old
 * "if (!isset($_SESSION['customer_id']))" check below was never true for a
 * real customer, making this whole endpoint unusable. Same class of bug
 * already fixed on modules/admin/messaging_api.php: hydrate from the
 * verified JWT cookie first, exactly like current_customer() does.
 */
if (empty($_SESSION['customer_id'])) {
    $__jwt = getJWTFromCookie();
    $__payload = $__jwt ? verifyJWT($__jwt) : null;
    if (is_array($__payload) && strtolower(trim($__payload['role'] ?? '')) === 'customer' && !empty($__payload['user_id'])) {
        $_SESSION['customer_id'] = (int) $__payload['user_id'];
    }
}

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Check if customer is logged in
    if (!isset($_SESSION['customer_id'])) {
        throw new Exception('Not authenticated');
    }

    $customer_id = $_SESSION['customer_id'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'send_message':
            // Send message
            $message = trim($_POST['message'] ?? '');
            
            if (empty($message)) {
                throw new Exception('Message cannot be empty');
            }
            
            $user_id = null; // Will be assigned by admin/staff
            if (send_message($customer_id, $user_id, 'customer', $message)) {
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
            $response['data'] = $messages;
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
