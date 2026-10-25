<?php
/**
 * PayMongo webhook endpoint (register this URL in the PayMongo dashboard -> Developers -> Webhooks,
 * event: checkout_session.payment.paid). Public URL, so every request is authenticated by its
 * Paymongo-Signature (HMAC-SHA256 with PAYMONGO_WEBHOOK_SECRET) before anything is read.
 * The raw body is verified exactly as received. Responds 2xx quickly so PayMongo does not retry
 * a delivery we already handled; handling is idempotent anyway.
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only.']);
    exit;
}

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/payment_helper.php';
require_once __DIR__ . '/../app/helpers/remittance_schema_helper.php';

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');

try {
    payment_ensure_schema($conn);
    [$code, $message] = payment_handle_webhook($conn, $raw, $sig);
} catch (Throwable $e) {
    error_log('paymongo_webhook: ' . $e->getMessage());
    [$code, $message] = [500, 'Could not process the event.']; // PayMongo will retry
}
http_response_code($code);
echo json_encode(['ok' => $code < 300, 'message' => $message]);
