<?php
/**
 * Customer Messaging API Endpoint
 * Handles message operations for customers
 */

header('Content-Type: application/json');

session_start();
require_once '../includes/db.php';
require_once '../app/helpers/messaging_helper.php';

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

        case 'get_messages':
            // Get all messages for customer
            $messages = get_customer_messages($customer_id);
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
