<?php
/**
 * Retired legacy endpoint. Order completion now requires authenticated Rider QR confirmation.
 */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}
header('Content-Type: application/json');
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'message' => 'This legacy endpoint is retired. Complete deliveries through authenticated Rider QR confirmation.',
]);