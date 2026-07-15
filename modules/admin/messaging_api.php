<?php
/**
 * Messaging API Endpoint
 * Handles message operations via POST/GET requests
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Ensure tracking columns exist in customers table for typing status and AI logic
$check_cols = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'admin_typing_at'");
if ($check_cols && mysqli_num_rows($check_cols) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN admin_typing_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN last_typing_at TIMESTAMP NULL DEFAULT NULL");
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Helper for JWT verification in API
    $verifyAuth = function() {
        $token = getJWTFromCookie();
        if (!$token || !verifyJWT($token)) throw new Exception('Unauthorized');
    };

    switch ($action) {
            case 'send_message':
            // Handle sending message (REST persistence)
            $customer_id = intval($_POST['customer_id'] ?? 0);
            $user_id = intval($_POST['user_id'] ?? 0);
            $sender_type = $_POST['sender_type'] ?? 'customer';
            $message = trim($_POST['message'] ?? '');
            
            if ($customer_id <= 0 || empty($message)) {
                throw new Exception('Invalid customer ID or message');
            }
            
            if (send_message($customer_id, $user_id > 0 ? $user_id : null, $sender_type, $message)) {
                // NOTE: WebSocket broadcasting is handled by ws/ChatServer.php in this project only when clients send WS events.
                // Admin/customer UIs still persist through REST, so the UI will refresh on polling unless we wire WS send from the client.
                $response['success'] = true;
                $response['message'] = 'Message sent successfully';
            } else {
                throw new Exception('Failed to send message');
            }
            break;


        case 'get_messages':
            // Get messages for a customer
            $verifyAuth();
            
            if ($_SESSION['user_role'] !== 'customer' && !in_array($_SESSION['user_role'], ['admin', 'staff'])) {
                throw new Exception('Unauthorized');
            }
            
            if ($_SESSION['user_role'] === 'customer') {
                $customer_id = $_SESSION['customer_id'];
                $messages = get_customer_messages($customer_id);
            } else {
                $customer_id = intval($_GET['customer_id'] ?? 0);
                if ($customer_id <= 0) {
                    throw new Exception('Invalid customer ID');
                }
                $messages = get_conversation($customer_id, $_SESSION['user_id']);

                // Automatically mark customer messages as read when fetched by staff/admin
                if ($messages) {
                    foreach ($messages as $msg) {
                        if ($msg['is_read'] == 0 && $msg['sender_type'] == 'customer') {
                            mark_as_read($msg['id']);
                        }
                    }
                }
            }
            
            $response['success'] = true;
            $response['data'] = $messages;
            break;

        case 'get_conversations':
            // Get all conversations (for admin/staff)
            $verifyAuth();
            
            if (!in_array($_SESSION['user_role'], ['admin', 'staff'])) {
                throw new Exception('Unauthorized');
            }
            
            $customers = get_customers_with_messages();
            $response['success'] = true;
            $response['data'] = $customers;
            break;

        case 'mark_as_read':
            // Mark message as read
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

        case 'mark_all_as_read':
            // Mark all messages as read for a customer
            $verifyAuth();
            
            if ($_SESSION['user_role'] !== 'customer') {
                throw new Exception('Unauthorized');
            }
            
            $customer_id = $_SESSION['customer_id'];
            
            if (mark_all_as_read($customer_id)) {
                $response['success'] = true;
                $response['message'] = 'All messages marked as read';
            } else {
                throw new Exception('Failed to mark all messages as read');
            }
            break;

        case 'get_unread_count':
            // Get unread message count
            $verifyAuth();
            
            if ($_SESSION['user_role'] === 'customer') {
                $count = get_unread_count_customer($_SESSION['customer_id']);
            } elseif (in_array($_SESSION['user_role'], ['admin', 'staff'])) {
                $count = get_unread_count_staff($_SESSION['user_id']);
            } else {
                throw new Exception('Invalid user role');
            }
            
            $response['success'] = true;
            $response['data'] = ['unread_count' => $count];
            break;

        case 'delete_message':
            // Delete a message
            $verifyAuth();
            
            $message_id = intval($_POST['message_id'] ?? 0);
            
            if ($message_id <= 0) {
                throw new Exception('Invalid message ID');
            }
            
            if ($_SESSION['user_role'] === 'customer') {
                delete_message($message_id, null, $_SESSION['customer_id']);
            } else {
                delete_message($message_id, $_SESSION['user_id']);
            }
            
            $response['success'] = true;
            $response['message'] = 'Message deleted successfully';
            break;

        case 'get_typing_status':
            $verifyAuth();
            $role = $_SESSION['user_role'];
            
            if ($role === 'customer') {
                // Customer checks if Admin is typing
                $customer_id = $_SESSION['customer_id'];
                $column = 'admin_typing_at';
            } else {
                // Admin/Staff checks if Customer is typing
                $customer_id = intval($_GET['customer_id'] ?? 0);
                $column = 'last_typing_at';
            }

            if ($customer_id <= 0) throw new Exception('Invalid ID');

            $stmt = $conn->prepare("SELECT $column FROM customers WHERE id = ?");
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            
            $is_typing = false;
            if ($res && $res[$column]) {
                $is_typing = (time() - strtotime($res[$column])) < 6;
            }
            
            $response['success'] = true;
            $response['data'] = ['is_typing' => $is_typing];
            break;

        case 'set_typing_status':
            $verifyAuth();
            $role = $_SESSION['user_role'];
            
            if ($role === 'customer') {
                // Customer says "I am typing"
                $customer_id = $_SESSION['customer_id'];
                $stmt = $conn->prepare("UPDATE customers SET last_typing_at = NOW() WHERE id = ?");
            } else {
                // Admin/Staff says "I am typing"
                $customer_id = intval($_POST['customer_id'] ?? 0);
                $stmt = $conn->prepare("UPDATE customers SET admin_typing_at = NOW() WHERE id = ?");
            }

            if ($customer_id > 0) {
                $stmt->bind_param("i", $customer_id);
                $stmt->execute();
                $response['success'] = true;
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
