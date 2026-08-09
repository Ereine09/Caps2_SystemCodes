<?php
/**
 * Rider Messaging API (JSON)
 *
 * Lets a rider chat with customers they have (or had) a delivery for.
 * Reuses the existing `tbl_messages` table so the customer's existing
 * messaging UI will automatically see rider replies.
 *
 * GET  ?action=get_conversations                        - list customers w/ deliveries
 * GET  ?action=get_conversation&customer_id=5          - fetch conversation + mark read
 * GET  ?action=get_unread_count                        - unread customer messages
 * POST body: { "action": "send_message", "customer_id": 5, "message": "..." }
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
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = [
    'success' => false,
    'message' => 'An error occurred.',
    'data' => null,
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
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }

    $rider_user_id = (int)$payload['user_id'];
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account.');
    }

    ensureRiderSchema($conn);
    messaging_ensure_schema($conn);

    // Resolve riders.id (used by tbl_orders.rider_id) from users.id.
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();
    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    }
    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found. Please re-login or contact support.');
    }

    $action = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'get_conversations': {
            // Customers the rider has been assigned to deliver to.
            $conv_stmt = $conn->prepare(
                "SELECT c.id AS customer_id,
                        c.name AS customer_name,
                        c.phone AS customer_phone,
COUNT(DISTINCT o.id) AS order_count,
                        (SELECT COUNT(*) FROM tbl_messages m2
                          WHERE m2.customer_id = c.id AND m2.sender_type = 'customer'
                            AND m2.user_id = ? AND m2.is_read = 0) AS unread_count,
                        (SELECT MAX(m3.created_at) FROM tbl_messages m3
                          WHERE m3.customer_id = c.id AND m3.sender_type IN ('customer', 'rider')
                            AND m3.user_id = ?) AS last_message_time
                 FROM tbl_orders o
                 JOIN customers c ON o.customer_id = c.id
                 WHERE o.rider_id = ?
                 GROUP BY c.id, c.name, c.phone
                 ORDER BY last_message_time DESC"
            );
$conv_stmt->bind_param('iii', $rider_user_id, $rider_user_id, $rider_id);
            $conv_stmt->execute();
            $conversations = $conv_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $conv_stmt->close();

            foreach ($conversations as &$conv) {
                $conv['customer_id'] = (int)$conv['customer_id'];
                $conv['order_count'] = (int)$conv['order_count'];
                $conv['unread_count'] = (int)$conv['unread_count'];

                $lm_stmt = $conn->prepare(
                    "SELECT message, sender_type, created_at
                     FROM tbl_messages
                     WHERE customer_id = ?
                       AND sender_type IN ('customer', 'rider')
                       AND user_id = ?
                     ORDER BY created_at DESC
                     LIMIT 1"
                );
                $lm_stmt->bind_param('ii', $conv['customer_id'], $rider_user_id);
                $lm_stmt->execute();
                $lrow = $lm_stmt->get_result()->fetch_assoc();
                $lm_stmt->close();

                $conv['last_message'] = $lrow['message'] ?? '';
                $conv['last_sender'] = $lrow['sender_type'] ?? '';
                $conv['last_message_time'] = $conv['last_message_time'] ?? '';
            }
            unset($conv);

            $response['success'] = true;
            $response['message'] = 'Conversations fetched successfully.';
            $response['data'] = ['conversations' => $conversations];
            break;
        }

        case 'get_conversation': {
            $customer_id = (int)($_GET['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                throw new Exception('Invalid customer ID.');
            }

            // Only allow customers this rider actually delivered to.
            $verify = $conn->prepare("SELECT id FROM tbl_orders WHERE customer_id = ? AND rider_id = ? LIMIT 1");
            $verify->bind_param('ii', $customer_id, $rider_id);
            $verify->execute();
            $vres = $verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$vres) {
                throw new Exception('You do not have a delivery for this customer.');
            }

$msg_stmt = $conn->prepare(
                "SELECT m.id, m.customer_id, m.user_id, m.sender_type, m.message, m.is_read, m.created_at,
                        CASE WHEN m.sender_type = 'customer' THEN c.name ELSE u.username END AS sender_name
                 FROM tbl_messages m
                 LEFT JOIN customers c ON m.customer_id = c.id
                 LEFT JOIN users u ON m.user_id = u.id
                 WHERE m.customer_id = ?
                   AND m.sender_type IN ('customer', 'rider')
                   AND m.user_id = ?
                 ORDER BY m.created_at ASC"
            );
            $msg_stmt->bind_param('ii', $customer_id, $rider_user_id);
            $msg_stmt->execute();
            $messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $msg_stmt->close();

            // Mark customer messages as read when the rider opens the chat.
            $mark = $conn->prepare(
                "UPDATE tbl_messages SET is_read = 1, read_at = NOW()
                 WHERE customer_id = ? AND sender_type = 'customer' AND is_read = 0"
            );
            $mark->bind_param('i', $customer_id);
            $mark->execute();
            $mark->close();

            $response['success'] = true;
            $response['message'] = 'Conversation fetched successfully.';
            $response['data'] = [
                'customer_id' => $customer_id,
                'messages' => $messages,
            ];
            break;
        }

        case 'send_message': {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $message = trim((string)($input['message'] ?? ''));

            if ($customer_id <= 0) {
                throw new Exception('Invalid customer ID.');
            }
            if ($message === '') {
                throw new Exception('Message cannot be empty.');
            }

            $verify = $conn->prepare("SELECT id FROM tbl_orders WHERE customer_id = ? AND rider_id = ? LIMIT 1");
            $verify->bind_param('ii', $customer_id, $rider_id);
            $verify->execute();
            $vres = $verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$vres) {
                throw new Exception('You do not have a delivery for this customer.');
            }

// Insert as sender_type='rider' so the customer UI shows it as a rider reply.
            $stmt = $conn->prepare(
                "INSERT INTO tbl_messages (customer_id, user_id, sender_type, message)
                 VALUES (?, ?, 'rider', ?)"
            );
            $stmt->bind_param('iis', $customer_id, $rider_user_id, $message);
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();

            // Best-effort real-time in-app notification to the customer (their
            // WS is keyed by customer_id). Never block the message send if the
            // WS is offline or the notification helper throws.
            try {
                notifications_create($conn, [
                    'user_id' => $customer_id,
                    'customer_id' => $customer_id,
                    'type' => 'rider_message',
                    'channel' => 'in_app',
                    'title' => 'Message from your rider',
                    'message' => 'Your rider sent you a message.',
                    'reference_table' => 'tbl_messages',
                    'reference_id' => $message_id,
                    'email_to' => null,
                ]);
            } catch (\Throwable $notificationError) {
                error_log('rider_messaging notifications_create failed: ' . $notificationError->getMessage());
            }

            $response['success'] = true;
            $response['message'] = 'Message sent successfully.';
            $response['data'] = ['message_id' => $message_id];
            break;
        }

        case 'get_unread_count': {
$stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM tbl_messages m
                 JOIN tbl_orders o ON o.customer_id = m.customer_id AND o.rider_id = ?
                 WHERE m.sender_type = 'customer' AND m.user_id = ? AND m.is_read = 0"
            );
            $stmt->bind_param('ii', $rider_id, $rider_user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Unread count fetched successfully.';
            $response['data'] = ['unread_count' => (int)($row['total'] ?? 0)];
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

