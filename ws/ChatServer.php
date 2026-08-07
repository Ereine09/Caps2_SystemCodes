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
                // Relay a notification to a specific user (e.g. a rider).
                // The payload is sent by the PHP backend via a one-shot WS client.
                $targetUserId = (int)($data['user_id'] ?? 0);
                if ($targetUserId > 0 && isset($this->connections[$targetUserId])) {
                    $this->connections[$targetUserId]->send(json_encode([
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

