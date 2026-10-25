<?php
/**
 * WebSocket Push Helper
 *
 * A minimal WebSocket client used to push one-shot events to the Ratchet
 * WebSocket server (ws/run_ws_server.php). This lets the PHP backend deliver
 * real-time notifications to online riders without requiring a long-lived
 * connection from the request thread.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/config.php';
 *   require_once __DIR__ . '/ws_push_helper.php';
 *   ws_push_notification($conn, $user_id, $notification_id, $title, $message, $reference_table, $reference_id, $created_at);
 *
 * If the WS server is offline, this helper silently fails (non-blocking for
 * the HTTP request) so the notification still persists in the database.
 */

/**
 * Returns a 4-byte masked WebSocket frame for a text payload.
 */
function ws_build_masked_frame(string $payload): string
{
    $length = strlen($payload);
    $maskKey = random_bytes(4);

    if ($length <= 125) {
        $header = chr(0x81) . chr(0x80 | $length);
    } elseif ($length <= 65535) {
        $header = chr(0x81) . chr(0x80 | 126) . pack('n', $length);
    } else {
        $header = chr(0x81) . chr(0x80 | 127) . pack('J', $length);
    }

    $maskedPayload = '';
    for ($i = 0; $i < $length; $i++) {
        $maskedPayload .= $payload[$i] ^ $maskKey[$i % 4];
    }

    return $header . $maskKey . $maskedPayload;
}

/**
 * Opens a WebSocket connection to the WS server and sends a single JSON message.
 *
 * ws/ChatServer.php::onOpen() requires every connection to present a valid
 * JWT as a `?token=` query param (closing the socket immediately otherwise) -
 * this one-shot server-to-server push connection is no exception, so it
 * authenticates as a reserved, non-user "system" identity using the SAME
 * generateJWT()/JWT secret every real login already uses. (Bug fixed here:
 * this previously connected with no token at all, so ChatServer::onOpen()
 * always closed it before the push frame could ever be relayed - meaning no
 * push notification, for any feature using this helper, actually reached a
 * connected client until this fix.)
 *
 * @return bool true on success, false on any failure (connection, handshake, send).
 */
function ws_send_raw(array $message): bool
{
    if (!function_exists('generateJWT')) {
        require_once __DIR__ . '/jwt_helper.php';
    }

    $host = defined('WS_HOST') ? WS_HOST : '127.0.0.1';
    $port = defined('WS_PORT') ? (int)WS_PORT : 8080;

    // Short timeout so we don't hang the HTTP request if the WS server is down.
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        1.0
    );

    if (!$socket) {
        return false;
    }

    // Set a short read timeout for the handshake response.
    stream_set_timeout($socket, 1);

    // Reserved system pseudo-user (id -1, never a real account - real user
    // ids start at 1) - only used so this internal connection passes the
    // SAME auth check every real browser connection already goes through.
    // Deliberately non-zero: ChatServer.php's onOpen()/onMessage() both use
    // empty()/truthy checks on user_id, which would reject 0 the same way
    // they were rejecting the old no-token connection.
    $push_token = generateJWT(-1, 'system', 'system');

    $key = base64_encode(random_bytes(16));
    $handshake =
        "GET /?token=" . urlencode($push_token) . " HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Key: {$key}\r\n" .
        "Sec-WebSocket-Version: 13\r\n" .
        "\r\n";

    fwrite($socket, $handshake);

    // Read until the end of the HTTP response headers (\r\n\r\n). A single
    // fread() can return a partial response, which made valid handshakes look
    // like failures under load.
    $response = '';
    $deadline = microtime(true) + 1.0;
    while (strpos($response, "\r\n\r\n") === false && microtime(true) < $deadline) {
        $chunk = fread($socket, 2048);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
                break;
            }
            continue;
        }
        $response .= $chunk;
    }

    if (stripos($response, ' 101 ') === false && strpos($response, "HTTP/1.1 101") === false) {
        fclose($socket);
        return false;
    }

    $frame = ws_build_masked_frame(json_encode($message));
    $written = fwrite($socket, $frame);
    fflush($socket);
    fclose($socket);

    return $written !== false && $written > 0;
}

/**
 * Pushes a notification event to a specific recipient (rider, admin, staff,
 * or customer) via the WS server. The WS server relays it only if that
 * exact role+id has an active connection (ChatServer.php's
 * connectionsByRoleId - role is required so a customer id and a users.id
 * that happen to share the same number can never be confused).
 */
function ws_push_notification(
    mysqli $conn,
    int    $user_id,
    int    $notification_id,
    string $title,
    string $message,
    string $reference_table = '',
    int    $reference_id = 0,
    string $created_at = '',
    string $role = ''
): bool {
    if ($user_id <= 0 || $role === '') {
        return false;
    }

    if ($created_at === '') {
        $created_at = date('Y-m-d H:i:s');
    }

    return ws_send_raw([
        'type' => 'push_notification',
        'user_id' => $user_id,
        'role' => $role,
        'notification_id' => $notification_id,
        'title' => $title,
        'message' => $message,
        'reference_table' => $reference_table,
        'reference_id' => $reference_id,
        'created_at' => $created_at,
    ]);
}
