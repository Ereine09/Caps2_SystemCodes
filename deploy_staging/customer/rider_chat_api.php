<?php
/**
 * Customer → Rider Chat API (JSON)
 *
 * Lets a customer chat with the rider assigned to their delivery order(s).
 * Reuses the shared `tbl_messages` table so the rider's Flutter app chat
 * (rider_messaging_api.php) sees the same conversation.
 *
 * Customer sends with sender_type='customer' (matching the existing message
 * scheme). Rider replies appear as sender_type='rider'.
 *
 * GET  ?action=get_my_rider            - the rider assigned to the customer's active orders
 * GET  ?action=get_conversation        - messages with that rider (customer_id scope)
 * GET  ?action=get_unread_count        - unread rider messages for this customer
 * POST action=send_message {message}   - send a message to the rider
 *
 * Authenticates the customer via JWT cookie/header.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => 'An error occurred.',
    'data' => null,
];

try {
    $customer = current_customer();
    $customer_id = $customer ? (int)$customer['id'] : 0;
    if ($customer_id <= 0) {
        throw new Exception('Not authenticated.');
    }

    messaging_ensure_schema($conn);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $action = $body['action'] ?? '';
    }

    switch ($action) {
        // The rider assigned to this customer's non-completed delivery orders.
        case 'get_my_rider': {
            $stmt = $conn->prepare(
                "SELECT o.rider_id, o.id AS order_id, o.order_number, o.order_status,
                        u.id AS rider_user_id,
                        CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS rider_name,
                        r.vehicle_type, r.plate_number
                 FROM tbl_orders o
                 JOIN riders r ON r.id = o.rider_id
                 JOIN users u ON u.id = r.user_id
                 WHERE o.customer_id = ?
                   AND o.rider_id IS NOT NULL
                   AND o.order_status NOT IN ('completed', 'cancelled')
                 ORDER BY o.created_at DESC
                 LIMIT 1"
            );
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $rider = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$rider) {
                $response['success'] = true;
                $response['message'] = 'No rider assigned yet.';
                $response['data'] = ['rider' => null];
                break;
            }

            $rider['rider_id'] = (int)$rider['rider_id'];
            $rider['order_id'] = (int)$rider['order_id'];
            $rider['rider_name'] = trim($rider['rider_name'] ?? '') ?: 'Your Rider';

            $response['success'] = true;
            $response['message'] = 'Rider found.';
            $response['data'] = ['rider' => $rider];
            break;
        }

        case 'get_conversation': {
            $rider_thread = messaging_rider_thread_filter('m');
            $stmt = $conn->prepare(
                "SELECT m.id, m.sender_type, m.message, m.is_read, m.created_at,
                        CASE WHEN m.sender_type = 'rider' THEN
                            (SELECT CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))
                             FROM users u WHERE u.id = m.user_id)
                        ELSE ? END AS sender_name
                 FROM tbl_messages m
                 WHERE m.customer_id = ?
                   AND $rider_thread
                 ORDER BY m.created_at ASC"
            );
            $customer_name = $customer['name'] ?? 'You';
            $stmt->bind_param('si', $customer_name, $customer_id);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Mark rider messages as read when the customer opens the chat.
            $mark = $conn->prepare(
                "UPDATE tbl_messages SET is_read = 1, read_at = NOW()
                 WHERE customer_id = ?
                   AND sender_type = 'rider' AND user_id IS NOT NULL AND is_read = 0"
            );
            $mark->bind_param('i', $customer_id);
            $mark->execute();
            $mark->close();

            $response['success'] = true;
            $response['message'] = 'Conversation fetched.';
            $response['data'] = ['messages' => $messages, 'customer_id' => $customer_id];
            break;
        }

        case 'get_unread_count': {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM tbl_messages
                 WHERE customer_id = ?
                   AND sender_type = 'rider' AND user_id IS NOT NULL AND is_read = 0"
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

        case 'send_message': {
            $message = trim((string)($_POST['message'] ?? ($body['message'] ?? '')));

            if ($message === '') {
                throw new Exception('Message cannot be empty.');
            }

            // Only allow sending if the customer actually has an assigned rider.
            $chk = $conn->prepare(
                "SELECT o.rider_id, r.user_id
                 FROM tbl_orders o
                 JOIN riders r ON r.id = o.rider_id
                 WHERE o.customer_id = ? AND o.rider_id IS NOT NULL
                 ORDER BY o.created_at DESC LIMIT 1"
            );
            $chk->bind_param('i', $customer_id);
            $chk->execute();
            $assigned = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$assigned) {
                throw new Exception('No rider assigned to your delivery yet.');
            }

            $rider_user_id = (int)$assigned['user_id'];

// Insert as sender_type='customer' (shared table with rider app).
            $stmt = $conn->prepare(
                "INSERT INTO tbl_messages (customer_id, user_id, sender_type, message)
                 VALUES (?, ?, 'customer', ?)"
            );
            $escaped_message = htmlspecialchars($message);
            $stmt->bind_param('iis', $customer_id, $rider_user_id, $escaped_message);
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();

            // Best-effort real-time push to the rider via WS (keyed by rider
            // user id). This must NEVER block message delivery — if the WS
            // server is offline or the notification helper throws, the message
            // was already saved and we still report success.
            try {
                notifications_create($conn, [
                    'user_id' => $rider_user_id,
                    'customer_id' => $customer_id,
                    'type' => 'customer_message',
                    'channel' => 'in_app',
                    'title' => 'New message from customer',
                    'message' => $customer['name'] . ' sent you a message.',
                    'reference_table' => 'tbl_messages',
                    'reference_id' => $message_id,
                    'email_to' => null,
                ]);
            } catch (\Throwable $notificationError) {
                // Notification/WS failure must not fail the message send.
                // Log it for debugging but keep the response successful.
                error_log('rider_chat notifications_create failed: ' . $notificationError->getMessage());
            }

            $response['success'] = true;
            $response['message'] = 'Message sent.';
            $response['data'] = ['message_id' => $message_id];
            break;
        }

        default:
            throw new Exception('Invalid action specified.');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
