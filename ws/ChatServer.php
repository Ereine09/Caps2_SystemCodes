<?php
namespace Ws;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * DPS realtime chat server.
 *
 * Persistence remains in messaging_api.php / messaging_helper.php.
 * This server only relays presence, typing, message, seen, and notification events.
 *
 * IMPORTANT FIX:
 * Connections are keyed by "role:userId", not by bare numeric user id.
 * customers.id and users.id are separate sequences and can collide.
 */
class ChatServer implements MessageComponentInterface
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    /** @var array<int, string> resourceId => role:userId */
    private array $identityByResource = [];

    /** @var array<string, array<int, ConnectionInterface>> */
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

        $userId = (int) $payload['user_id'];
        $role = strtolower(trim((string) $payload['role']));
        $key = $this->identityKey($role, $userId);

        // One active WS connection per role+id in this server instance.
        // Close an older socket for the exact same identity, but never a different role
        // that happens to have the same numeric id.
        if (isset($this->connections[$key]) && $this->connections[$key] !== $conn) {
            try { $this->connections[$key]->close(); } catch (\Throwable $e) {}
        }

        $this->connections[$key] = $conn;
        $this->identityByResource[(int) $conn->resourceId] = $key;
        $this->connectionsByRoleId[$role][$userId] = $conn;

        $this->broadcast([
            'type' => 'presence',
            'user_id' => $userId,
            'role' => $role,
            'online' => true,
        ], $key);

        $conn->send(json_encode([
            'type' => 'ws_ready',
            'success' => true,
            'user_id' => $userId,
            'role' => $role,
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string) $msg, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        $identity = $this->findIdentityByConn($from);
        if (!$identity) {
            return;
        }

        $senderId = (int) $identity['user_id'];
        $senderRole = (string) $identity['role'];
        $senderKey = (string) $identity['key'];
        $type = (string) $data['type'];

        switch ($type) {
            case 'typing':
            case 'stop_typing':
                $customerId = (int) ($data['customer_id'] ?? 0);
                $this->broadcast([
                    'type' => 'typing',
                    'customer_id' => $customerId,
                    'user_id' => $senderId,
                    'sender_role' => $senderRole,
                    'is_typing' => $type === 'typing',
                ], $senderKey);
                break;

            case 'new_message':
            case 'send_message':
                $customerId = (int) ($data['customer_id'] ?? 0);
                $this->broadcast([
                    'type' => 'new_message',
                    'customer_id' => $customerId,
                    'sender_id' => $senderId,
                    'sender_role' => $senderRole,
                    'message' => (string) ($data['message'] ?? ''),
                ], $senderKey);
                break;

            case 'seen':
                $customerId = (int) ($data['customer_id'] ?? 0);
                $messageId = (int) ($data['message_id'] ?? 0);
                $this->broadcast([
                    'type' => 'seen',
                    'customer_id' => $customerId,
                    'message_id' => $messageId,
                    'seen_by' => $senderId,
                    'seen_by_role' => $senderRole,
                ], $senderKey);
                break;

            case 'push_notification':
                $targetRole = strtolower(trim((string) ($data['role'] ?? '')));
                $targetUserId = (int) ($data['user_id'] ?? 0);
                $targetConn = ($targetRole !== '' && $targetUserId > 0)
                    ? ($this->connectionsByRoleId[$targetRole][$targetUserId] ?? null)
                    : null;

                if ($targetConn) {
                    $targetConn->send(json_encode([
                        'type' => 'notification',
                        'notification_id' => (int) ($data['notification_id'] ?? 0),
                        'title' => (string) ($data['title'] ?? 'Notification'),
                        'message' => (string) ($data['message'] ?? ''),
                        'reference_table' => (string) ($data['reference_table'] ?? ''),
                        'reference_id' => (int) ($data['reference_id'] ?? 0),
                        'created_at' => (string) ($data['created_at'] ?? ''),
                    ]));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $identity = $this->findIdentityByConn($conn);
        if (!$identity) {
            return;
        }

        $key = (string) $identity['key'];
        $role = (string) $identity['role'];
        $userId = (int) $identity['user_id'];

        if (($this->connections[$key] ?? null) === $conn) {
            unset($this->connections[$key]);
        }
        unset($this->identityByResource[(int) $conn->resourceId]);

        if (($this->connectionsByRoleId[$role][$userId] ?? null) === $conn) {
            unset($this->connectionsByRoleId[$role][$userId]);
            if (empty($this->connectionsByRoleId[$role])) {
                unset($this->connectionsByRoleId[$role]);
            }
        }

        $this->broadcast([
            'type' => 'presence',
            'user_id' => $userId,
            'role' => $role,
            'online' => false,
        ], $key);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    /** @return array{key:string,role:string,user_id:int}|null */
    private function findIdentityByConn(ConnectionInterface $conn): ?array
    {
        $key = $this->identityByResource[(int) $conn->resourceId] ?? null;

        if ($key === null || ($this->connections[$key] ?? null) !== $conn) {
            foreach ($this->connections as $candidateKey => $candidateConn) {
                if ($candidateConn === $conn) {
                    $key = $candidateKey;
                    break;
                }
            }
        }

        if (!$key || !str_contains($key, ':')) {
            return null;
        }

        [$role, $id] = explode(':', $key, 2);
        return [
            'key' => $key,
            'role' => $role,
            'user_id' => (int) $id,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function broadcast(array $payload, string $excludeKey = ''): void
    {
        foreach ($this->connections as $key => $conn) {
            if ($excludeKey !== '' && $key === $excludeKey) {
                continue;
            }
            try {
                $conn->send(json_encode($payload));
            } catch (\Throwable $e) {
                // A stale socket will be cleaned up by Ratchet's close/error flow.
            }
        }
    }

    private function identityKey(string $role, int $userId): string
    {
        return strtolower(trim($role)) . ':' . $userId;
    }

    private function decodeJwtPayload(string $jwt): ?array
    {
        /*
         * Kept protocol-compatible with the supplied server.
         * SECURITY NOTE: this only decodes the JWT payload and does NOT verify
         * its signature. For production, wire this server to the same verified
         * JWT helper/secret used by the PHP application before exposing the WS
         * endpoint publicly.
         */
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payloadB64 = $parts[1];
        $padding = strlen($payloadB64) % 4;
        if ($padding !== 0) {
            $payloadB64 .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
