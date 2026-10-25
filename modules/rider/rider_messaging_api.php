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
 * POST body: { "action": "send_message", "customer_id": 5, "message": "...", "reply_to_id": 12 }
 * POST action=react, customer_id, message_id, emoji   - add / change / remove a reaction (form post, CSRF)
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
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

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
            $input = $_POST; // form posts (reactions from assets/js/chat-extras.js)
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
                 LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
                 JOIN customers c ON o.customer_id = c.id
                 -- Assigned at pickup (tbl_orders.rider_id = riders.id) or by the admin
                 -- (tbl_delivery.rider_id = users.id): chat opens as soon as the delivery is assigned.
                 WHERE (o.rider_id = ? OR d.rider_id = ?)
                 GROUP BY c.id, c.name, c.phone
                 ORDER BY last_message_time DESC"
            );
$conv_stmt->bind_param('iiii', $rider_user_id, $rider_user_id, $rider_id, $rider_user_id);
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
            // Same assignment rule as get_conversations (pickup or admin assignment).
            $verify = $conn->prepare(
                "SELECT o.id FROM tbl_orders o
                 LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
                 WHERE o.customer_id = ? AND (o.rider_id = ? OR d.rider_id = ?) LIMIT 1"
            );
            $verify->bind_param('iii', $customer_id, $rider_id, $rider_user_id);
            $verify->execute();
            $vres = $verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$vres) {
                throw new Exception('You do not have a delivery for this customer.');
            }

$msg_stmt = $conn->prepare(
                "SELECT m.id, m.customer_id, m.user_id, m.sender_type, m.message, m.is_read, m.read_at, m.reply_to_id, m.unsent_at, m.created_at,
                        CASE WHEN m.sender_type = 'customer' THEN c.name
                             ELSE COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), u.username) END AS sender_name
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
            // Customer text is stored HTML-escaped: send plain text (the page escapes it once).
            foreach ($messages as &$m) {
                if ($m['sender_type'] === 'customer') $m['message'] = html_entity_decode((string) $m['message'], ENT_QUOTES, 'UTF-8');
            }
            unset($m);
            // Replies + reactions (the rider reacts with their users.id).
            $messages = messaging_attach_extras($conn, $messages, 'staff', $rider_user_id);

            // Mark this customer's messages TO THIS RIDER as read (the customer then sees "Seen").
            // Scoped to the rider's thread: the customer's support chat with the shop is not touched.
            $mark = $conn->prepare(
                "UPDATE tbl_messages SET is_read = 1, read_at = NOW()
                 WHERE customer_id = ? AND sender_type = 'customer' AND user_id = ? AND is_read = 0"
            );
            $mark->bind_param('ii', $customer_id, $rider_user_id);
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

            // Same assignment rule as get_conversations (pickup or admin assignment).
            $verify = $conn->prepare(
                "SELECT o.id FROM tbl_orders o
                 LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
                 WHERE o.customer_id = ? AND (o.rider_id = ? OR d.rider_id = ?) LIMIT 1"
            );
            $verify->bind_param('iii', $customer_id, $rider_id, $rider_user_id);
            $verify->execute();
            $vres = $verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$vres) {
                throw new Exception('You do not have a delivery for this customer.');
            }

            $reply_to_id = (int)($input['reply_to_id'] ?? 0);
            if ($reply_to_id > 0 && !messaging_rider_thread_message($conn, $reply_to_id, $customer_id, $rider_user_id)) {
                $reply_to_id = 0; // only a message from this same chat can be quoted
            }
            // Insert as sender_type='rider' so the customer UI shows it as a rider reply.
            $stmt = $conn->prepare(
                "INSERT INTO tbl_messages (customer_id, user_id, sender_type, message, reply_to_id)
                 VALUES (?, ?, 'rider', ?, NULLIF(?, 0))"
            );
            $stmt->bind_param('iisi', $customer_id, $rider_user_id, $message, $reply_to_id);
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();

            // Best-effort real-time in-app notification to the customer (their
            // WS is keyed by customer_id). Never block the message send if the
            // WS is offline or the notification helper throws.
            try {
                // Who sent it and what it says, so the customer's bell/pop-up is useful on its own.
                $name_stmt = $conn->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username FROM users WHERE id = ?");
                $name_stmt->bind_param('i', $rider_user_id);
                $name_stmt->execute();
                $who = $name_stmt->get_result()->fetch_assoc() ?: [];
                $name_stmt->close();
                $rider_name = trim((string) ($who['full_name'] ?? '')) ?: (string) ($who['username'] ?? 'Your rider');
                $preview = mb_strlen($message) > 120 ? mb_substr($message, 0, 117) . '...' : $message;
                // Only `customer_id`: `user_id` is a foreign key to `users`, not customers.
                notifications_create($conn, [
                    'customer_id' => $customer_id,
                    'type' => 'rider_message',
                    'channel' => 'in_app',
                    'title' => 'New message from your rider, ' . $rider_name,
                    'message' => $preview,
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

        case 'react': {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $message_id = (int)($input['message_id'] ?? 0);
            $emoji = (string)($input['emoji'] ?? '');
            if (!isset($headers['Authorization']) && !csrf_check()) {
                throw new Exception('Your session expired. Please reload the page.');
            }
            if (!messaging_rider_toggle_reaction($conn, $message_id, $customer_id, $rider_user_id, 'staff', $rider_user_id, $emoji)) {
                throw new Exception('Could not save the reaction.');
            }
            $response['success'] = true;
            $response['message'] = 'Reaction saved.';
            break;
        }

        case 'get_unread_count': {
$stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM tbl_messages m
                 WHERE m.sender_type = 'customer' AND m.user_id = ? AND m.is_read = 0
                   AND EXISTS (
                       SELECT 1 FROM tbl_orders o
                       LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
                       WHERE o.customer_id = m.customer_id AND (o.rider_id = ? OR d.rider_id = ?)
                   )"
            );
            // EXISTS (not a JOIN) so a customer with several orders isn't counted once per order.
            $stmt->bind_param('iii', $rider_user_id, $rider_id, $rider_user_id);
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

