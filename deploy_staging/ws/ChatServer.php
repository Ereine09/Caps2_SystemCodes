<?php
namespace Ws;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * Minimal Ratchet WebSocket server.
 * - Presence events (online/offline)
 * - Typing events
 * - Message/Seen events (actual persistence is handled by your existing messaging_api.php REST endpoints)
 */
class ChatServer implements MessageComponentInterface
{
    /** @var array<int, ConnectionInterface> */
    private array $connections = [];

    /**
     * Same connections, but namespaced by role too: connectionsByRoleId[role][id].
     * Needed because $connections above is keyed by a bare numeric id, and
     * customers.id / users.id (admin, staff, rider all share the users
     * table) are separate auto-increment sequences that can and do collide
     * (e.g. a real customer #1 and a real admin #1 both exist) - a plain
     * $connections[$id] lookup for a TARGETED push (see 'push_notification'
     * below) could silently deliver to the wrong person. Only used for that
     * targeted lookup; every broadcast-based flow (typing/new_message/seen/
     * presence) still uses $connections exactly as before, untouched.
     * @var array<string, array<int, ConnectionInterface>>
     */
    private array $connectionsByRoleId = [];

    public function onOpen(ConnectionInterface $conn): void
    {
        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $params);

        $token = $params['token'] ?? '';
        if (!$token) {
            $conn->close();
            return;
        }

        $payload = $this->decodeJwtPayload($token);
        if (!$payload || empty($payload['user_id']) || empty($payload['role'])) {
            $conn->close();
            return;
        }

        $userId = (int)$payload['user_id'];
        $role = strtolower(trim((string)$payload['role']));

        $this->connections[$userId] = $conn;
        $this->connectionsByRoleId[$role][$userId] = $conn;

        // Broadcast presence (simple broadcast)
        $this->broadcast([
            'type' => 'presence',
            'user_id' => $userId,
            'online' => true,
        ], $userId);

        $conn->send(json_encode([
            'type' => 'ws_ready',
            'success' => true,
            'user_id' => $userId,
            'role' => $role,
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string)$msg, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        $senderId = $this->findUserIdByConn($from);
        if (!$senderId) {
            return;
        }

        $type = (string)$data['type'];

        switch ($type) {
            case 'typing':
                $customerId = (int)($data['customer_id'] ?? 0);
                $this->broadcast([
                    'type' => 'typing',
                    'customer_id' => $customerId,
                    'user_id' => $senderId,
                    'is_typing' => true,
                ], $senderId);
                break;

            case 'stop_typing':
                $customerId = (int)($data['customer_id'] ?? 0);
                $this->broadcast([
                    'type' => 'typing',
                    'customer_id' => $customerId,
                    'user_id' => $senderId,
                    'is_typing' => false,
                ], $senderId);
                break;

            case 'new_message':
            case 'send_message':
                // Persistence is handled by your REST endpoint already.
                $customerId = (int)($data['customer_id'] ?? 0);
                $this->broadcast([
                    'type' => 'new_message',
                    'customer_id' => $customerId,
                    'sender_id' => $senderId,
                    'message' => (string)($data['message'] ?? ''),
                ], $senderId);
                break;

case 'seen':
                $customerId = (int)($data['customer_id'] ?? 0);
                $messageId = (int)($data['message_id'] ?? 0);
                $this->broadcast([
                    'type' => 'seen',
                    'customer_id' => $customerId,
                    'message_id' => $messageId,
                    'seen_by' => $senderId,
                ], $senderId);
                break;

            case 'push_notification':
                // Relay a notification to a specific user (e.g. a rider,
                // an admin/staff, or a customer). The payload is sent by the
                // PHP backend via a one-shot WS client (ws_push_helper.php).
                // Looked up by role+id (connectionsByRoleId), NOT the bare
                // $connections[$id] map - see that property's docblock for
                // why (id collisions across customers/users).
                $targetRole = strtolower(trim((string)($data['role'] ?? '')));
                $targetUserId = (int)($data['user_id'] ?? 0);
                $targetConn = ($targetRole !== '' && $targetUserId > 0)
                    ? ($this->connectionsByRoleId[$targetRole][$targetUserId] ?? null)
                    : null;
                if ($targetConn) {
                    $targetConn->send(json_encode([
                        'type' => 'notification',
                        'notification_id' => (int)($data['notification_id'] ?? 0),
                        'title' => (string)($data['title'] ?? 'Notification'),
                        'message' => (string)($data['message'] ?? ''),
                        'reference_table' => (string)($data['reference_table'] ?? ''),
                        'reference_id' => (int)($data['reference_id'] ?? 0),
                        'created_at' => (string)($data['created_at'] ?? ''),
                    ]));
                }
                break;

            default:
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $userId = $this->findUserIdByConn($conn);
        if ($userId) {
            unset($this->connections[$userId]);
            // Also remove from the role-scoped map - linear scan (mirrors
            // findUserIdByConn's own style) since we only have the bare id,
            // not which role bucket it was filed under.
            foreach ($this->connectionsByRoleId as $role => $byId) {
                foreach ($byId as $id => $c) {
                    if ($c === $conn) {
                        unset($this->connectionsByRoleId[$role][$id]);
                    }
                }
            }
            $this->broadcast([
                'type' => 'presence',
                'user_id' => $userId,
                'online' => false,
            ], $userId);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    private function findUserIdByConn(ConnectionInterface $conn): int
    {
        foreach ($this->connections as $userId => $c) {
            if ($c === $conn) {
                return (int)$userId;
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $payload */
    private function broadcast(array $payload, int $excludeUserId = 0): void
    {
        foreach ($this->connections as $uid => $c) {
            if ($excludeUserId && (int)$uid === (int)$excludeUserId) {
                continue;
            }
            $c->send(json_encode($payload));
        }
    }

    private function decodeJwtPayload(string $jwt): ?array
    {
        // Minimal decode without signature verification.
        // Replace with verified decoding for production.
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payloadB64 = $parts[1];
        $payloadB64 .= str_repeat('=', 4 - (strlen($payloadB64) % 4));
        $json = base64_decode(strtr($payloadB64, '-_', '+/'));
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}

