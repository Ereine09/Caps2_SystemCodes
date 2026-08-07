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
 * @return bool true on success, false on any failure (connection, handshake, send).
 */
function ws_send_raw(array $message): bool
{
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

    $key = base64_encode(random_bytes(16));
    $handshake =
        "GET / HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Key: {$key}\r\n" .
        "Sec-WebSocket-Version: 13\r\n" .
        "\r\n";

    fwrite($socket, $handshake);

    $response = fread($socket, 4096);
    if ($response === false || strpos($response, "101") === false) {
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
 * Pushes a notification event to a specific user (rider) via the WS server.
 * The WS server relays it only if that user has an active connection.
 */
function ws_push_notification(
    mysqli $conn,
    int    $user_id,
    int    $notification_id,
    string $title,
    string $message,
    string $reference_table = '',
    int    $reference_id = 0,
    string $created_at = ''
): bool {
    if ($user_id <= 0) {
        return false;
    }

    if ($created_at === '') {
        $created_at = date('Y-m-d H:i:s');
    }

    return ws_send_raw([
        'type' => 'push_notification',
        'user_id' => $user_id,
        'notification_id' => $notification_id,
        'title' => $title,
        'message' => $message,
        'reference_table' => $reference_table,
        'reference_id' => $reference_id,
        'created_at' => $created_at,
    ]);
}
