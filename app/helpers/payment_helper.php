<?php
/**
 * Payments: one place that decides when money counts as received.
 *
 * ORDER STATUS and PAYMENT STATUS are separate. tbl_orders.order_status is the fulfilment
 * (pending, confirmed, ..., completed); tbl_orders.payment_status is the money:
 *
 *   unpaid               nothing paid yet (COD / Pay at shop until collected; online before paying)
 *   pending              online payment started at the provider, waiting for its result
 *   pending_verification manual GCash/bank transfer: customer sent a reference, staff must check
 *   paid                 verified (provider webhook/inquiry, or staff for offline methods)
 *   failed / expired     online attempt did not complete; the customer may try again
 *   cancelled            payment abandoned / order cancelled before paying
 *   review               provider says paid, but the amount/currency does not match: held for staff
 *   refunded             money returned
 *
 * Who may set PAID:
 *   - Online (PayMongo: GCash, online banking, QR Ph): ONLY payment_apply_session(), fed by the
 *     signed webhook (api/paymongo_webhook.php) or a server-side inquiry to PayMongo's API.
 *     Nothing sent by the browser (return URL parameters, JS) is ever trusted.
 *   - Manual GCash/bank transfer, Pay at shop: an authenticated admin/staff member
 *     (payment_confirm_by_staff()), recorded as verified by staff.
 *   - COD: the rider remittance flow (remittance_settle_orders()).
 *
 * Provider: PayMongo Hosted Checkout (a licensed PH payment gateway that offers GCash). GCash has
 * no public merchant API of its own. Keys live only on the server (app/config/config.local.php or
 * environment variables) and are never sent to the browser. No customer credentials (passwords,
 * MPIN, OTP, PINs) are ever asked for or stored: the customer authenticates on PayMongo/GCash/bank
 * pages.
 */
require_once __DIR__ . '/../config/config.php';

const PAYMONGO_API_BASE = 'https://api.paymongo.com';
const PAYMENT_WEBHOOK_TOLERANCE = 600; // seconds a webhook timestamp may differ from now (replay guard)

/** Server-side payment configuration. Secrets never leave this file's callers. */
function payment_config(): array
{
    global $LOCAL_SECRETS;
    $get = static fn(string $k) => (string) ($LOCAL_SECRETS[$k] ?? getenv($k) ?: '');
    $env = strtolower($get('PAYMENT_ENVIRONMENT')) === 'production' ? 'production' : 'sandbox';
    $secret = trim($get('PAYMONGO_SECRET_KEY'));
    $webhook = trim($get('PAYMONGO_WEBHOOK_SECRET'));
    // A key for the wrong environment is treated as not configured (never charge live money from sandbox).
    $prefix = $env === 'production' ? 'sk_live_' : 'sk_test_';
    $key_ok = $secret !== '' && strpos($secret, $prefix) === 0;
    return [
        'environment'     => $env,
        'livemode'        => $env === 'production',
        'secret_key'      => $key_ok ? $secret : '',
        'webhook_secret'  => $webhook,
        // Checkout works with the secret key: results are confirmed by the signed webhook and/or a
        // server-side status check (both come from PayMongo). The webhook is required for live mode
        // (it is what confirms payments when nobody returns to the site); in sandbox the status
        // check alone is enough, because PayMongo cannot deliver webhooks to http://localhost.
        'online_enabled'  => $key_ok && ($env === 'sandbox' || $webhook !== ''),
        'webhook_enabled' => $key_ok && $webhook !== '',
        'key_problem'     => $secret === '' ? 'PAYMONGO_SECRET_KEY is not set.'
            : (!$key_ok ? "PAYMONGO_SECRET_KEY must start with {$prefix} when PAYMENT_ENVIRONMENT={$env}."
            : ($webhook === '' ? 'PAYMONGO_WEBHOOK_SECRET is not set (' . ($env === 'production' ? 'required for live mode' : 'webhooks disabled; payments are confirmed by status checks') . ').' : '')),
        'allow_manual'    => strtolower($get('PAYMENT_ALLOW_MANUAL_TRANSFER') ?: 'true') !== 'false',
        // PayMongo method types the checkout page offers, e.g. PAYMONGO_METHODS=card,gcash,paymaya,dob
        // (each must be activated on the PayMongo account for live mode). Default: GCash + online banking.
        'methods'         => payment_parse_methods($get('PAYMONGO_METHODS')),
    ];
}

/** PayMongo method types we know how to label; anything else in PAYMONGO_METHODS is ignored. */
const PAYMONGO_METHOD_LABELS = [
    'gcash'    => 'GCash',
    'paymaya'  => 'Maya',
    'card'     => 'Credit/Debit Card',
    'grab_pay' => 'GrabPay',
    'qrph'     => 'QR Ph',
    'dob'      => 'Online Banking',
    'brankas'  => 'Online Banking',
    'billease' => 'BillEase',
];
const PAYMONGO_BANK_TYPES = ['dob', 'brankas'];

function payment_parse_methods(string $raw): array
{
    $list = array_values(array_unique(array_filter(array_map(static fn($m) => strtolower(trim($m)), explode(',', $raw)),
        static fn($m) => isset(PAYMONGO_METHOD_LABELS[$m]))));
    return $list ?: ['gcash', 'dob', 'brankas'];
}

function payment_online_available(): bool
{
    return payment_config()['online_enabled'];
}

/**
 * PayMongo method types for a payment method of ours (from PAYMONGO_METHODS):
 *   bank  -> the online-banking types (dob / brankas)
 *   gcash -> every other type: GCash, Maya, card, ... (the customer picks one on PayMongo's page)
 */
function payment_provider_methods(string $method): array
{
    $all = payment_config()['methods'];
    $bank = array_values(array_intersect($all, PAYMONGO_BANK_TYPES));
    // GCash first, then Maya, Card, ... (PAYMONGO_METHOD_LABELS order), whatever order the setting lists them in.
    $other = array_values(array_intersect(array_keys(PAYMONGO_METHOD_LABELS), array_diff($all, PAYMONGO_BANK_TYPES)));
    if ($method === 'bank') {
        return $bank ?: ['dob'];
    }
    return $other ?: ['gcash'];
}

/** Customer-facing names of what the "GCash" option offers online, e.g. "GCash, Maya, Credit/Debit Card". */
function payment_online_wallet_labels(): array
{
    return array_values(array_unique(array_map(static fn($t) => PAYMONGO_METHOD_LABELS[$t], payment_provider_methods('gcash'))));
}

const PAYMENT_STATUS_LABELS = [
    'unpaid'               => 'Unpaid',
    'pending'              => 'Payment pending',
    'pending_verification' => 'Pending verification',
    'paid'                 => 'Paid',
    'failed'               => 'Payment failed',
    'expired'              => 'Payment expired',
    'cancelled'            => 'Cancelled',
    'review'               => 'Needs review',
    'refunded'             => 'Refunded',
];

function payment_status_label(?string $status): string
{
    return PAYMENT_STATUS_LABELS[(string) $status] ?? ucfirst((string) $status);
}

/**
 * Schema (additive). DDL: call it OUTSIDE any transaction (MySQL commits implicitly on DDL).
 */
function payment_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM tbl_orders");
    while ($res && ($c = $res->fetch_assoc())) {
        $cols[$c['Field']] = true;
    }
    $new_status_col = !isset($cols['payment_status']);
    if ($new_status_col) {
        $conn->query("ALTER TABLE tbl_orders ADD COLUMN payment_status ENUM('unpaid','pending','pending_verification','paid','failed','expired','cancelled','review','refunded') NOT NULL DEFAULT 'unpaid' AFTER payment_method");
    }
    if (!isset($cols['payment_provider'])) {
        // 'paymongo' (verified by the provider) or 'manual' (reference + receipt, verified by staff). NULL = offline method.
        $conn->query("ALTER TABLE tbl_orders ADD COLUMN payment_provider VARCHAR(20) NULL DEFAULT NULL AFTER payment_status");
    }
    if (!isset($cols['payment_verified_via'])) {
        // provider_webhook | provider_inquiry | staff | remittance
        $conn->query("ALTER TABLE tbl_orders ADD COLUMN payment_verified_via VARCHAR(20) NULL DEFAULT NULL AFTER payment_provider");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tbl_payments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          purpose ENUM('order','remittance') NOT NULL,
          order_id INT NULL,
          remittance_id INT NULL,
          provider VARCHAR(20) NOT NULL DEFAULT 'paymongo',
          environment ENUM('sandbox','production') NOT NULL,
          method VARCHAR(20) NOT NULL,
          reference VARCHAR(40) NULL COMMENT 'our reference_number sent to the provider',
          provider_session_id VARCHAR(64) NULL,
          provider_payment_id VARCHAR(64) NULL,
          provider_reference VARCHAR(100) NULL COMMENT 'external reference from GCash / the bank, when given',
          amount_expected DECIMAL(10,2) NOT NULL,
          amount_paid DECIMAL(10,2) NULL,
          currency CHAR(3) NOT NULL DEFAULT 'PHP',
          status ENUM('created','pending','paid','failed','expired','cancelled','review') NOT NULL DEFAULT 'created',
          checkout_url VARCHAR(500) NULL,
          verified_via VARCHAR(20) NULL,
          failure_reason VARCHAR(255) NULL,
          paid_at DATETIME NULL,
          last_checked_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_provider_session (provider, provider_session_id),
          UNIQUE KEY uniq_provider_payment (provider, provider_payment_id),
          UNIQUE KEY uniq_reference (reference),
          KEY idx_order (order_id),
          KEY idx_remittance (remittance_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS tbl_payment_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          payment_id INT NULL,
          order_id INT NULL,
          remittance_id INT NULL,
          event VARCHAR(40) NOT NULL,
          status VARCHAR(30) NULL,
          amount DECIMAL(10,2) NULL,
          reference VARCHAR(100) NULL,
          source VARCHAR(30) NOT NULL COMMENT 'provider_webhook | provider_inquiry | staff | system | customer',
          actor_user_id INT NULL,
          details VARCHAR(500) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_payment (payment_id),
          KEY idx_order (order_id),
          KEY idx_remittance (remittance_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if ($new_status_col) {
        // Backfill from what the system already knew. Money already received stays received.
        $conn->query("UPDATE tbl_orders SET payment_status = 'refunded' WHERE order_status = 'refunded' OR refunded_at IS NOT NULL");
        $conn->query("UPDATE tbl_orders SET payment_status = 'paid',
                          payment_verified_via = IF(payment_method = 'cod', 'remittance', 'staff')
                      WHERE payment_status = 'unpaid' AND payment_settled = 1");
        // Pay-at-shop orders that were completed were paid at pickup (sales already counted them).
        $conn->query("UPDATE tbl_orders SET payment_status = 'paid', payment_settled = 1, payment_verified_via = 'staff',
                          paid_at = COALESCE(paid_at, updated_at, created_at)
                      WHERE payment_status = 'unpaid' AND payment_method = 'pay_at_shop' AND order_status = 'completed'");
        // GCash/bank orders past "pending" were approved by staff (the staff page and admin app approved
        // without setting payment_settled); the customer was already shown them as paid.
        $conn->query("UPDATE tbl_orders SET payment_status = 'paid', payment_settled = 1, payment_verified_via = 'staff', payment_provider = 'manual',
                          paid_at = COALESCE(paid_at, updated_at, created_at)
                      WHERE payment_status = 'unpaid' AND payment_method IN ('gcash','bank')
                        AND order_status NOT IN ('pending','cancelled','refunded')");
        $conn->query("UPDATE tbl_orders SET payment_status = 'cancelled'
                      WHERE payment_status = 'unpaid' AND payment_method IN ('gcash','bank') AND order_status = 'cancelled'");
        // GCash/bank orders still waiting for staff to check the customer's reference + receipt.
        $conn->query("UPDATE tbl_orders SET payment_status = 'pending_verification', payment_provider = 'manual'
                      WHERE payment_status = 'unpaid' AND payment_method IN ('gcash','bank') AND order_status = 'pending'");
        $conn->query("UPDATE tbl_orders SET payment_provider = 'manual'
                      WHERE payment_provider IS NULL AND payment_method IN ('gcash','bank')");
    }
}

/** Audit trail entry. Never pass credentials here (none are ever collected). */
function payment_log_event(mysqli $conn, array $e): void
{
    try {
        $stmt = $conn->prepare(
            "INSERT INTO tbl_payment_events (payment_id, order_id, remittance_id, event, status, amount, reference, source, actor_user_id, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $pid = isset($e['payment_id']) ? (int) $e['payment_id'] : null;
        $oid = isset($e['order_id']) ? (int) $e['order_id'] : null;
        $rid = isset($e['remittance_id']) ? (int) $e['remittance_id'] : null;
        $event = (string) $e['event'];
        $status = isset($e['status']) ? (string) $e['status'] : null;
        $amount = isset($e['amount']) ? (float) $e['amount'] : null;
        $ref = isset($e['reference']) ? substr((string) $e['reference'], 0, 100) : null;
        $source = (string) ($e['source'] ?? 'system');
        $actor = isset($e['actor_user_id']) && (int) $e['actor_user_id'] > 0 ? (int) $e['actor_user_id'] : null;
        $details = isset($e['details']) ? substr((string) $e['details'], 0, 500) : null;
        $stmt->bind_param('iiissdssis', $pid, $oid, $rid, $event, $status, $amount, $ref, $source, $actor, $details);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $t) {
        error_log('payment_log_event failed: ' . $t->getMessage());
    }
}

/**
 * Calls the PayMongo API with the server-side secret key.
 * Returns ['ok' => bool, 'http' => int, 'json' => ?array, 'error' => string].
 * Network errors / 5xx come back as ok=false, never as a payment result.
 */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    $cfg = payment_config();
    if ($cfg['secret_key'] === '') {
        return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Online payment is not configured.'];
    }
    // Test seam for the automated tests only (CLI). A web request can never reach it.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['PAYMENT_TEST_TRANSPORT']) && is_callable($GLOBALS['PAYMENT_TEST_TRANSPORT'])) {
        return ($GLOBALS['PAYMENT_TEST_TRANSPORT'])($method, $path, $body);
    }
    $ch = curl_init(PAYMONGO_API_BASE . $path);
    $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($cfg['secret_key'] . ':')];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($raw === false) {
        return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Could not reach PayMongo: ' . $err];
    }
    $json = json_decode((string) $raw, true);
    $ok = $http >= 200 && $http < 300 && is_array($json);
    $msg = '';
    if (!$ok) {
        $msg = is_array($json) && !empty($json['errors'][0]['detail']) ? (string) $json['errors'][0]['detail'] : "PayMongo returned HTTP {$http}.";
    }
    return ['ok' => $ok, 'http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $msg];
}

function payment_get(mysqli $conn, int $payment_id, bool $lock = false): ?array
{
    $stmt = $conn->prepare("SELECT * FROM tbl_payments WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** Latest provider payment attempt for an order / remittance. */
function payment_latest(mysqli $conn, string $purpose, int $id): ?array
{
    $col = $purpose === 'remittance' ? 'remittance_id' : 'order_id';
    $stmt = $conn->prepare("SELECT * FROM tbl_payments WHERE purpose = ? AND $col = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('si', $purpose, $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * The order's products (+ VAT, delivery fee, tip) as PayMongo line items, so the checkout page
 * lists what the customer is paying for. Returns null - and the caller shows one "Order #..." line -
 * when the lines don't add up to exactly $total_centavos (e.g. a voucher discount: PayMongo has no
 * negative lines). The amount charged is always the order total either way.
 */
function payment_order_line_items(mysqli $conn, int $order_id, int $total_centavos): ?array
{
    if ($order_id <= 0) {
        return null;
    }
    $o = $conn->query("SELECT vat_amount, delivery_fee, tip, discount_amount FROM tbl_orders WHERE id = $order_id")->fetch_assoc();
    if (!$o || (float) $o['discount_amount'] > 0) {
        return null;
    }
    $lines = [];
    $res = $conn->query("SELECT product_name, quantity, unit_price FROM tbl_order_items WHERE order_id = $order_id ORDER BY id");
    while ($res && ($it = $res->fetch_assoc())) {
        $qty = (int) $it['quantity'];
        $unit = (int) round((float) $it['unit_price'] * 100);
        if ($qty <= 0 || $unit <= 0) {
            return null;
        }
        $name = trim((string) $it['product_name']);
        $lines[] = ['name' => mb_strlen($name) > 120 ? mb_substr($name, 0, 117) . '...' : $name, 'amount' => $unit, 'currency' => 'PHP', 'quantity' => $qty];
    }
    if (!$lines) {
        return null;
    }
    foreach (['vat_amount' => 'VAT (12%)', 'delivery_fee' => 'Delivery fee', 'tip' => 'Rider tip'] as $col => $label) {
        $cents = (int) round((float) $o[$col] * 100);
        if ($cents > 0) {
            $lines[] = ['name' => $label, 'amount' => $cents, 'currency' => 'PHP', 'quantity' => 1];
        }
    }
    $sum = 0;
    foreach ($lines as $l) {
        $sum += $l['amount'] * $l['quantity'];
    }
    return $sum === $total_centavos ? $lines : null;
}

/**
 * Starts an online payment: records the attempt, creates a PayMongo Checkout Session and returns
 * its hosted URL. The order/remittance is NOT marked paid here.
 * $o: purpose, order_id|remittance_id, amount, method (gcash|bank), description, success_url,
 *     cancel_url, name, email (optional).
 * Returns ['ok' => bool, 'payment_id' => int, 'checkout_url' => ?string, 'message' => string].
 */
function payment_start_checkout(mysqli $conn, array $o): array
{
    payment_ensure_schema($conn);
    $cfg = payment_config();
    if (!$cfg['online_enabled']) {
        return ['ok' => false, 'payment_id' => 0, 'checkout_url' => null, 'message' => 'Online payment is not available right now. Please try again later.'];
    }
    $purpose = $o['purpose'] === 'remittance' ? 'remittance' : 'order';
    $order_id = $purpose === 'order' ? (int) $o['order_id'] : null;
    $remittance_id = $purpose === 'remittance' ? (int) $o['remittance_id'] : null;
    $method = $o['method'] === 'bank' ? 'bank' : 'gcash';
    $amount = round((float) $o['amount'], 2);
    if ($amount <= 0) {
        return ['ok' => false, 'payment_id' => 0, 'checkout_url' => null, 'message' => 'Nothing to pay.'];
    }

    $stmt = $conn->prepare("INSERT INTO tbl_payments (purpose, order_id, remittance_id, environment, method, amount_expected, status) VALUES (?, ?, ?, ?, ?, ?, 'created')");
    $stmt->bind_param('siissd', $purpose, $order_id, $remittance_id, $cfg['environment'], $method, $amount);
    $stmt->execute();
    $payment_id = (int) $conn->insert_id;
    $stmt->close();
    $reference = sprintf('DPS-PAY-%06d', $payment_id);
    $conn->query("UPDATE tbl_payments SET reference = '" . $conn->real_escape_string($reference) . "' WHERE id = $payment_id");

    $line_items = $purpose === 'order' ? payment_order_line_items($conn, (int) $order_id, (int) round($amount * 100)) : null;
    $attrs = [
        'line_items' => $line_items ?: [[
            'name'     => (string) $o['description'],
            'amount'   => (int) round($amount * 100), // centavos
            'currency' => 'PHP',
            'quantity' => 1,
        ]],
        'payment_method_types' => payment_provider_methods($method),
        'success_url'      => (string) $o['success_url'],
        'cancel_url'       => (string) $o['cancel_url'],
        'reference_number' => $reference,
        'description'      => (string) $o['description'],
        'send_email_receipt' => !empty($o['email']),
        'metadata' => ['payment_id' => (string) $payment_id, 'purpose' => $purpose],
    ];
    if (!empty($o['email']) || !empty($o['name'])) {
        $attrs['billing'] = array_filter(['name' => (string) ($o['name'] ?? ''), 'email' => (string) ($o['email'] ?? '')]);
    }
    $res = paymongo_request('POST', '/v2/checkout_sessions', ['data' => ['attributes' => $attrs]]);
    $session_id = (string) ($res['json']['data']['id'] ?? '');
    $url = (string) ($res['json']['data']['attributes']['checkout_url'] ?? '');

    $log = ['payment_id' => $payment_id, 'order_id' => $order_id, 'remittance_id' => $remittance_id, 'amount' => $amount, 'reference' => $reference, 'source' => 'system'];
    if (!$res['ok'] || $session_id === '' || $url === '') {
        $reason = $res['error'] ?: 'No checkout URL returned.';
        $stmt = $conn->prepare("UPDATE tbl_payments SET status = 'cancelled', failure_reason = ? WHERE id = ?");
        $stmt->bind_param('si', $reason, $payment_id);
        $stmt->execute();
        $stmt->close();
        payment_log_event($conn, $log + ['event' => 'provider_unavailable', 'status' => 'cancelled', 'details' => $reason]);
        error_log("PayMongo checkout failed for payment {$payment_id} ({$purpose}): HTTP {$res['http']} {$reason}"); // technical detail stays server-side
        return ['ok' => false, 'payment_id' => $payment_id, 'checkout_url' => null, 'message' => 'Online payment is temporarily unavailable. Please try again later or use the manual verification option.'];
    }

    if (strpos($url, 'https://') !== 0) { // never send anyone to a non-HTTPS "checkout"
        $reason = 'Provider returned an invalid checkout URL.';
        $conn->query("UPDATE tbl_payments SET status = 'cancelled', failure_reason = '" . $conn->real_escape_string($reason) . "' WHERE id = $payment_id");
        payment_log_event($conn, $log + ['event' => 'provider_unavailable', 'status' => 'cancelled', 'details' => $reason]);
        error_log("PayMongo checkout for payment {$payment_id} returned a non-HTTPS URL.");
        return ['ok' => false, 'payment_id' => $payment_id, 'checkout_url' => null, 'message' => 'Unable to create the secure payment session.'];
    }
    $stmt = $conn->prepare("UPDATE tbl_payments SET provider_session_id = ?, checkout_url = ?, status = 'pending' WHERE id = ?");
    $stmt->bind_param('ssi', $session_id, $url, $payment_id);
    $stmt->execute();
    $stmt->close();
    if ($order_id) {
        $conn->query("UPDATE tbl_orders SET payment_status = 'pending', payment_provider = 'paymongo' WHERE id = $order_id AND payment_status <> 'paid'");
    }
    payment_log_event($conn, $log + ['event' => 'payment_created', 'status' => 'pending', 'details' => "PayMongo checkout session {$session_id} ({$method}, {$cfg['environment']})"]);
    return ['ok' => true, 'payment_id' => $payment_id, 'checkout_url' => $url, 'message' => ''];
}

/**
 * Applies a PayMongo Checkout Session (from a verified webhook or a server-side inquiry) to our
 * payment record. This is the ONLY code path that can mark an online payment as paid.
 * Checks: the session belongs to this payment (id + our reference), environment (livemode),
 * currency, the provider's own payment status, the amount, and that the provider payment was not
 * already used by another record. Idempotent: repeating a delivery changes nothing.
 * Returns ['status' => payment status after applying, 'changed' => bool, 'message' => string].
 */
function payment_apply_session(mysqli $conn, int $payment_id, array $session, string $source): array
{
    $cfg = payment_config();
    $conn->begin_transaction();
    try {
        $p = payment_get($conn, $payment_id, true);
        if (!$p) {
            throw new RuntimeException('Unknown payment.');
        }
        $attrs = $session['attributes'] ?? [];
        $log = ['payment_id' => $payment_id, 'order_id' => $p['order_id'], 'remittance_id' => $p['remittance_id'], 'source' => $source];

        if ((string) ($session['id'] ?? '') !== (string) $p['provider_session_id']
            || (string) ($attrs['reference_number'] ?? '') !== (string) $p['reference']) {
            payment_log_event($conn, $log + ['event' => 'verification_rejected', 'details' => 'Session id / reference does not match this payment.']);
            $conn->commit();
            return ['status' => $p['status'], 'changed' => false, 'message' => 'Session does not match this payment.'];
        }
        if (isset($attrs['livemode']) && (bool) $attrs['livemode'] !== $cfg['livemode']) {
            payment_log_event($conn, $log + ['event' => 'verification_rejected', 'details' => 'Live/test mode does not match PAYMENT_ENVIRONMENT.']);
            $conn->commit();
            return ['status' => $p['status'], 'changed' => false, 'message' => 'Environment mismatch.'];
        }
        if (in_array($p['status'], ['paid', 'review'], true)) {
            $conn->commit();
            return ['status' => $p['status'], 'changed' => false, 'message' => 'Already recorded.'];
        }

        $paid = null;
        $failed = false;
        foreach ((array) ($attrs['payments'] ?? []) as $pay) {
            $st = strtolower((string) ($pay['attributes']['status'] ?? ''));
            if ($st === 'paid') { $paid = $pay; break; }
            if ($st === 'failed') { $failed = true; }
        }
        $now_str = date('Y-m-d H:i:s');

        if ($paid) {
            $pa = $paid['attributes'] ?? [];
            $pay_id = (string) ($paid['id'] ?? '');
            $centavos = (int) ($pa['amount'] ?? -1);
            $currency = strtoupper((string) ($pa['currency'] ?? ''));
            $amount_paid = round($centavos / 100, 2);
            $ext_ref = (string) ($pa['external_reference_number'] ?? '');
            $expected_centavos = (int) round((float) $p['amount_expected'] * 100);

            // The same provider payment can never settle two records.
            $dup = $conn->prepare("SELECT id FROM tbl_payments WHERE provider = 'paymongo' AND provider_payment_id = ? AND id <> ? LIMIT 1");
            $dup->bind_param('si', $pay_id, $payment_id);
            $dup->execute();
            $is_dup = $pay_id === '' || $dup->get_result()->num_rows > 0;
            $dup->close();
            if ($is_dup) {
                payment_log_event($conn, $log + ['event' => 'duplicate_rejected', 'reference' => $pay_id, 'amount' => $amount_paid, 'details' => 'Provider payment already recorded on another payment.']);
                $conn->commit();
                return ['status' => $p['status'], 'changed' => false, 'message' => 'Duplicate provider payment.'];
            }

            $match = $currency === 'PHP' && $centavos === $expected_centavos;
            $new = $match ? 'paid' : 'review';
            $reason = $match ? null : "Provider reported {$currency} " . number_format($amount_paid, 2) . ' but ' . number_format((float) $p['amount_expected'], 2) . ' PHP was expected.';
            $paid_at = !empty($pa['paid_at']) ? date('Y-m-d H:i:s', (int) $pa['paid_at']) : $now_str;
            $stmt = $conn->prepare("UPDATE tbl_payments SET status = ?, provider_payment_id = ?, provider_reference = ?, amount_paid = ?, currency = ?, verified_via = ?, paid_at = ?, failure_reason = ?, last_checked_at = NOW() WHERE id = ?");
            $cur = $currency !== '' ? substr($currency, 0, 3) : 'PHP';
            $ext = $ext_ref !== '' ? $ext_ref : null;
            $stmt->bind_param('sssdssssi', $new, $pay_id, $ext, $amount_paid, $cur, $source, $paid_at, $reason, $payment_id);
            $stmt->execute();
            $stmt->close();
            payment_log_event($conn, $log + ['event' => $match ? 'payment_successful' : 'amount_mismatch', 'status' => $new, 'amount' => $amount_paid,
                'reference' => $ext_ref !== '' ? $ext_ref : $pay_id, 'details' => $reason ?? ("Verified by PayMongo ({$pay_id})."
                    . (($used = PAYMONGO_METHOD_LABELS[strtolower((string) ($pa['source']['type'] ?? $pa['payment_method_type'] ?? ''))] ?? '') !== '' ? " Paid with {$used}." : ''))]);

            $after = null;
            if ($p['purpose'] === 'order' && $p['order_id']) {
                if ($match) {
                    payment_settle_order($conn, (int) $p['order_id'], $source, $paid_at);
                } else {
                    $conn->query("UPDATE tbl_orders SET payment_status = 'review' WHERE id = " . (int) $p['order_id'] . " AND payment_status <> 'paid'");
                }
            } elseif ($p['purpose'] === 'remittance' && $p['remittance_id']) {
                require_once __DIR__ . '/remittance_schema_helper.php';
                $after = remittance_apply_provider_payment($conn, (int) $p['remittance_id'], $amount_paid, $ext_ref !== '' ? $ext_ref : $pay_id, $match);
                if ($after === null && $new === 'paid') {
                    // Money arrived but the remittance had already been closed (e.g. cancelled/rejected):
                    // never silently lose it - hold for staff to settle.
                    $new = 'review';
                    $why = 'PayMongo confirmed the payment after the remittance was closed. Staff must settle it.';
                    $conn->query("UPDATE tbl_payments SET status = 'review', failure_reason = '" . $conn->real_escape_string($why) . "' WHERE id = $payment_id");
                    payment_log_event($conn, $log + ['event' => 'paid_after_close', 'status' => 'review', 'amount' => $amount_paid, 'reference' => $pay_id, 'details' => $why]);
                }
            }
            $conn->commit();
            payment_after_commit($conn, $p, $new, $after);
            return ['status' => $new, 'changed' => true, 'message' => $match ? 'Payment verified.' : 'Amount does not match. Held for review.'];
        }

        $session_status = strtolower((string) ($attrs['status'] ?? ''));
        $new = null;
        if ($session_status === 'expired') {
            $new = 'expired';
        } elseif ($failed) {
            $new = 'failed';
        }
        $conn->query("UPDATE tbl_payments SET last_checked_at = NOW() WHERE id = $payment_id");
        if ($new !== null && $new !== $p['status']) {
            $stmt = $conn->prepare("UPDATE tbl_payments SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $new, $payment_id);
            $stmt->execute();
            $stmt->close();
            payment_log_event($conn, $log + ['event' => 'payment_' . $new, 'status' => $new, 'amount' => (float) $p['amount_expected']]);
            if ($p['purpose'] === 'order' && $p['order_id']) {
                $conn->query("UPDATE tbl_orders SET payment_status = '$new' WHERE id = " . (int) $p['order_id'] . " AND payment_status IN ('pending','unpaid')");
            } elseif ($p['purpose'] === 'remittance' && $p['remittance_id']) {
                require_once __DIR__ . '/remittance_schema_helper.php';
                remittance_cancel_unpaid_online($conn, (int) $p['remittance_id'], $new === 'expired' ? 'The online payment expired before it was completed.' : 'The online payment failed.');
            }
            $conn->commit();
            if ($p['purpose'] === 'remittance' && $p['remittance_id'] && in_array($new, ['failed', 'expired'], true)) {
                if (!function_exists('notifications_create')) {
                    require_once __DIR__ . '/notification_helper.php';
                }
                remittance_notify_online_unpaid($conn, (int) $p['remittance_id'], $new); // the rider can pay again
            }
            return ['status' => $new, 'changed' => true, 'message' => ''];
        }
        $conn->commit();
        return ['status' => $p['status'], 'changed' => false, 'message' => 'Still waiting for the payment result.'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Marks an order's money as received (inside the caller's transaction): deducts stock once,
 * sets payment_status = paid and settles it for Sales. A 'pending' order (waiting on payment)
 * moves to 'confirmed'; any other fulfilment status is left alone.
 */
function payment_settle_order(mysqli $conn, int $order_id, string $via, ?string $paid_at = null): void
{
    if (!function_exists('deduct_order_stock_once')) {
        require_once __DIR__ . '/../../customer/includes/functions.php';
    }
    $stock = deduct_order_stock_once($conn, $order_id);
    if (!$stock['ok']) {
        throw new RuntimeException($stock['error'] ?? 'Not enough stock.');
    }
    $paid_at = $paid_at ?: date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "UPDATE tbl_orders SET payment_status = 'paid', payment_settled = 1, paid_at = COALESCE(paid_at, ?), payment_verified_via = ?,
                order_status = IF(order_status = 'pending', 'confirmed', order_status), updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('ssi', $paid_at, $via, $order_id);
    $stmt->execute();
    $stmt->close();
}

/** Notifications once a provider result is committed (never inside the transaction). */
function payment_after_commit(mysqli $conn, array $p, string $status, ?array $remittance_result): void
{
    if (!function_exists('notifications_create')) {
        require_once __DIR__ . '/notification_helper.php';
    }
    if ($p['purpose'] === 'order' && $p['order_id']) {
        $o = $conn->query("SELECT o.id, o.order_number, o.customer_id, c.email FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = " . (int) $p['order_id'])->fetch_assoc();
        if (!$o) {
            return;
        }
        if ($status === 'paid') {
            @notifications_create($conn, [
                'customer_id' => (int) $o['customer_id'], 'type' => 'order_confirmed', 'channel' => 'both',
                'title' => 'Payment received', 'message' => "We received your payment for order #{$o['order_number']}. Your order is confirmed.",
                'reference_table' => 'tbl_orders', 'reference_id' => (int) $o['id'], 'email_to' => (string) ($o['email'] ?? ''),
            ]);
        } elseif ($status === 'review') {
            @notifications_create($conn, [
                'customer_id' => (int) $o['customer_id'], 'type' => 'order_status_update', 'channel' => 'in_app',
                'title' => 'Payment being reviewed', 'message' => "Your payment for order #{$o['order_number']} did not match the order total. Our staff will review it and contact you.",
                'reference_table' => 'tbl_orders', 'reference_id' => (int) $o['id'],
            ]);
        }
    } elseif ($p['purpose'] === 'remittance' && $p['remittance_id']) {
        require_once __DIR__ . '/remittance_schema_helper.php';
        if ($remittance_result) {
            remittance_notify_rider($conn, $remittance_result);
            remittance_notify_staff($conn, $remittance_result); // the shop learns PayMongo settled it
        } elseif ($status === 'review') {
            // Paid after the remittance was already closed: the money is held for staff to settle.
            $rem = $conn->query("SELECT r.id, r.rider_id, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS full_name, u.username
                                 FROM tbl_rider_remittances r LEFT JOIN users u ON u.id = r.rider_id WHERE r.id = " . (int) $p['remittance_id'])->fetch_assoc();
            $paid = $conn->query("SELECT amount_paid FROM tbl_payments WHERE id = " . (int) $p['id'])->fetch_assoc();
            if ($rem) {
                remittance_notify_staff($conn, [
                    'id' => (int) $rem['id'], 'number' => remittance_number((int) $rem['id']),
                    'rider_name' => trim((string) $rem['full_name']) !== '' ? trim($rem['full_name']) : (string) ($rem['username'] ?? 'Rider'),
                    'total' => (float) ($paid['amount_paid'] ?? 0), 'status' => 'review',
                ], true);
            }
        }
    }
}

/**
 * Server-side inquiry: asks PayMongo for the session's current state and applies it.
 * If PayMongo cannot be reached, nothing changes (never marked paid or failed on an outage).
 */
function payment_inquire(mysqli $conn, int $payment_id): array
{
    $p = payment_get($conn, $payment_id);
    if (!$p || empty($p['provider_session_id'])) {
        return ['ok' => false, 'status' => $p['status'] ?? null, 'message' => 'No online payment to check.'];
    }
    if (in_array($p['status'], ['paid', 'review', 'expired', 'cancelled'], true)) {
        return ['ok' => true, 'status' => $p['status'], 'message' => ''];
    }
    $res = paymongo_request('GET', '/v1/checkout_sessions/' . rawurlencode($p['provider_session_id']));
    if (!$res['ok'] || empty($res['json']['data'])) {
        payment_log_event($conn, ['payment_id' => $payment_id, 'order_id' => $p['order_id'], 'remittance_id' => $p['remittance_id'],
            'event' => 'inquiry_failed', 'source' => 'provider_inquiry', 'details' => $res['error']]);
        return ['ok' => false, 'status' => $p['status'], 'message' => 'Payment verification is currently unavailable. Please try again later.'];
    }
    $r = payment_apply_session($conn, $payment_id, $res['json']['data'], 'provider_inquiry');
    return ['ok' => true, 'status' => $r['status'], 'message' => $r['message']];
}

/**
 * The payer came back through the cancel link. First ask PayMongo (a payment may have gone
 * through anyway); if it is still open, expire the session AT PAYMONGO so it can no longer be
 * paid, and only then close our record. If PayMongo cannot be reached, nothing is closed.
 */
function payment_cancel(mysqli $conn, int $payment_id): array
{
    $chk = payment_inquire($conn, $payment_id);
    if (!$chk['ok'] || $chk['status'] !== 'pending') {
        return $chk;
    }
    $p = payment_get($conn, $payment_id);
    $res = paymongo_request('POST', '/v1/checkout_sessions/' . rawurlencode($p['provider_session_id']) . '/expire');
    if (!$res['ok']) {
        // It may have been paid or expired in the meantime: let the provider's state decide.
        $again = payment_inquire($conn, $payment_id);
        if (!$again['ok'] || $again['status'] === 'pending') {
            error_log("PayMongo expire failed for payment {$payment_id}: {$res['error']}");
            return ['ok' => false, 'status' => 'pending', 'message' => 'Could not cancel the payment at PayMongo right now. It stays pending; please check again later.'];
        }
        return $again;
    }
    $conn->begin_transaction();
    try {
        $row = payment_get($conn, $payment_id, true);
        if ($row['status'] !== 'pending') {
            $conn->commit();
            return ['ok' => true, 'status' => $row['status'], 'message' => ''];
        }
        $conn->query("UPDATE tbl_payments SET status = 'cancelled', failure_reason = 'Cancelled by the payer.', last_checked_at = NOW() WHERE id = $payment_id");
        payment_log_event($conn, ['payment_id' => $payment_id, 'order_id' => $row['order_id'], 'remittance_id' => $row['remittance_id'],
            'event' => 'payment_cancelled', 'status' => 'cancelled', 'amount' => (float) $row['amount_expected'], 'source' => 'provider_inquiry',
            'details' => 'Payer cancelled; session expired at PayMongo.']);
        if ($row['purpose'] === 'order' && $row['order_id']) {
            $conn->query("UPDATE tbl_orders SET payment_status = 'cancelled' WHERE id = " . (int) $row['order_id'] . " AND payment_status IN ('pending','unpaid')");
        } elseif ($row['purpose'] === 'remittance' && $row['remittance_id']) {
            require_once __DIR__ . '/remittance_schema_helper.php';
            remittance_cancel_unpaid_online($conn, (int) $row['remittance_id'], 'The online payment was cancelled.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return ['ok' => true, 'status' => 'cancelled', 'message' => ''];
}

/**
 * Verifies a PayMongo webhook (Paymongo-Signature: t=..,te=..,li=..; HMAC-SHA256 of "t.rawBody"
 * with the webhook secret; te = test mode, li = live mode) and applies it.
 * Returns [http_code, message].
 */
function payment_handle_webhook(mysqli $conn, string $raw, string $signature_header): array
{
    $cfg = payment_config();
    if ($cfg['webhook_secret'] === '') {
        return [503, 'Webhook not configured.'];
    }
    $parts = [];
    foreach (explode(',', $signature_header) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k] = $v;
    }
    $t = $parts['t'] ?? '';
    $given = $cfg['livemode'] ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
    if ($t === '' || !ctype_digit($t) || $given === '') {
        return [400, 'Missing signature.'];
    }
    $expected = hash_hmac('sha256', $t . '.' . $raw, $cfg['webhook_secret']);
    if (!hash_equals($expected, $given)) {
        return [401, 'Invalid signature.'];
    }
    if (abs(time() - (int) $t) > PAYMENT_WEBHOOK_TOLERANCE) {
        return [400, 'Stale webhook.'];
    }

    $event = json_decode($raw, true);
    $data = $event['data'] ?? null;
    // Classic envelope: data.type = "event", the real type/resource in data.attributes.{type,data}.
    // Newer envelope: data.{type,data}. Prefer attributes when present.
    $type = (string) ($data['attributes']['type'] ?? $data['type'] ?? '');
    $resource = $data['attributes']['data'] ?? $data['data'] ?? null;
    if (!is_array($resource)) {
        return [200, 'Ignored (no resource).'];
    }
    // Both envelope shapes PayMongo documents: data.{type,livemode,data} and data.attributes.{type,livemode,data}.
    $live = $data['livemode'] ?? $data['attributes']['livemode'] ?? null;
    if ($live !== null && (bool) $live !== $cfg['livemode']) {
        return [200, 'Ignored (other environment).'];
    }
    if ($type !== 'checkout_session.payment.paid') {
        return [200, 'Ignored event ' . $type . '.'];
    }

    payment_ensure_schema($conn);
    $session_id = (string) ($resource['id'] ?? '');
    $stmt = $conn->prepare("SELECT id FROM tbl_payments WHERE provider = 'paymongo' AND provider_session_id = ? LIMIT 1");
    $stmt->bind_param('s', $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        payment_log_event($conn, ['event' => 'webhook_unknown_session', 'source' => 'provider_webhook', 'reference' => $session_id]);
        return [200, 'Unknown session.'];
    }
    $r = payment_apply_session($conn, (int) $row['id'], $resource, 'provider_webhook');
    return [200, $r['message']];
}

/**
 * Staff confirmation for OFFLINE-verified payments only: a manual GCash/bank transfer the staff
 * checked in the shop's own account, or cash paid at the shop. Provider (PayMongo) payments can
 * not be confirmed by hand - they are verified by the provider.
 */
function payment_confirm_by_staff(mysqli $conn, int $order_id, int $staff_user_id): array
{
    payment_ensure_schema($conn);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id, order_number, payment_method, payment_status, payment_provider, order_status, total FROM tbl_orders WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $o = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$o) {
            throw new RuntimeException('Order not found.');
        }
        if ($o['payment_status'] === 'paid') {
            throw new RuntimeException('This order is already paid.');
        }
        if ($o['payment_provider'] === 'paymongo') {
            throw new RuntimeException('This order was paid online through PayMongo. Its payment is verified by the provider, not by hand. Use "Check with PayMongo".');
        }
        $manual_transfer = in_array($o['payment_method'], ['gcash', 'bank'], true);
        if (!$manual_transfer && $o['payment_method'] !== 'pay_at_shop') {
            throw new RuntimeException('COD payments are confirmed through the rider remittance.');
        }
        if (in_array($o['order_status'], ['cancelled', 'refunded'], true)) {
            throw new RuntimeException('This order is cancelled.');
        }
        payment_settle_order($conn, $order_id, 'staff');
        payment_log_event($conn, ['order_id' => $order_id, 'event' => 'payment_verified', 'status' => 'paid', 'amount' => (float) $o['total'],
            'source' => 'staff', 'actor_user_id' => $staff_user_id,
            'details' => $manual_transfer ? 'Manual transfer checked by staff in the shop account.' : 'Cash received at the shop.']);
        $conn->commit();
        return ['ok' => true, 'order' => $o];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to confirm the payment.'];
    }
}

/** Staff rejects a manual transfer they could not find: the order is cancelled (existing behaviour). */
function payment_reject_by_staff(mysqli $conn, int $order_id, int $staff_user_id, string $reason): array
{
    payment_ensure_schema($conn);
    $o = $conn->query("SELECT id, payment_provider, payment_status, total, order_status FROM tbl_orders WHERE id = " . (int) $order_id)->fetch_assoc();
    if (!$o) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }
    if ($o['payment_provider'] === 'paymongo' || $o['payment_status'] === 'paid') {
        return ['ok' => false, 'message' => 'Only an unverified manual transfer can be rejected.'];
    }
    $reason = trim($reason) !== '' ? trim($reason) : 'Payment could not be verified.';
    $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled', payment_status = 'cancelled', cancellation_reason = ?, updated_at = NOW() WHERE id = ? AND order_status = 'pending'");
    $stmt->bind_param('si', $reason, $order_id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if ($ok) {
        payment_log_event($conn, ['order_id' => $order_id, 'event' => 'payment_rejected', 'status' => 'cancelled', 'amount' => (float) $o['total'],
            'source' => 'staff', 'actor_user_id' => $staff_user_id, 'details' => $reason]);
    }
    return ['ok' => $ok, 'message' => $ok ? '' : 'This order is not awaiting payment verification.'];
}

/** Audit trail rows for an order (newest first). */
function payment_events_for_order(mysqli $conn, int $order_id): array
{
    $stmt = $conn->prepare("SELECT e.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS actor_name
                            FROM tbl_payment_events e LEFT JOIN users u ON u.id = e.actor_user_id
                            WHERE e.order_id = ? ORDER BY e.id DESC LIMIT 50");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** How a payment was verified, in words for the admin screens. */
function payment_verified_via_label(?string $via): string
{
    switch ((string) $via) {
        case 'provider_webhook': return 'Verified by PayMongo (webhook)';
        case 'provider_inquiry': return 'Verified by PayMongo (status check)';
        case 'staff':            return 'Confirmed by staff';
        case 'remittance':       return 'Collected by rider, remittance verified';
        default:                 return '—';
    }
}

/** Setting keys for the shop's own receiving accounts (edited in Admin > Settings > Payment Accounts). */
/** What checkout showed before Payment Accounts existed; used until the admin saves real details. */
const SHOP_PAYMENT_DEFAULTS = [
    'PAY_GCASH_QR'            => 'customer/assets/images/sampleqr.jpg',
    'PAY_BANK_NAME'           => 'BDO Unibank',
    'PAY_BANK_ACCOUNT_NAME'   => 'Darius Poultry Supply',
    'PAY_BANK_ACCOUNT_NUMBER' => '001234567890',
];

const SHOP_PAYMENT_ACCOUNT_KEYS = [
    'PAY_GCASH_NAME', 'PAY_GCASH_NUMBER', 'PAY_GCASH_QR',
    'PAY_BANK_NAME', 'PAY_BANK_ACCOUNT_NAME', 'PAY_BANK_ACCOUNT_NUMBER', 'PAY_BANK_QR',
];

/**
 * Where customers and riders send MANUAL transfers: the shop's own GCash and bank account,
 * exactly as the admin entered them. Nothing is invented: a channel with missing details comes
 * back with ready = false so the page can say so instead of showing a placeholder.
 */
function shop_payment_accounts(mysqli $conn, bool $with_defaults = true): array
{
    $v = array_fill_keys(SHOP_PAYMENT_ACCOUNT_KEYS, '');
    $in = "'" . implode("','", SHOP_PAYMENT_ACCOUNT_KEYS) . "'";
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
    while ($res && ($row = $res->fetch_assoc())) {
        $v[$row['setting_key']] = trim((string) $row['setting_value']);
    }
    // Until the admin saves real details, fall back to the details checkout has always shown
    // (the GCash QR image and BDO account below). Transfers are still verified by staff.
    $gcash_default = $with_defaults && $v['PAY_GCASH_NAME'] === '' && $v['PAY_GCASH_NUMBER'] === '' && $v['PAY_GCASH_QR'] === '';
    if ($gcash_default) {
        $v['PAY_GCASH_QR'] = SHOP_PAYMENT_DEFAULTS['PAY_GCASH_QR'];
    }
    $bank_default = $with_defaults && $v['PAY_BANK_NAME'] === '' && $v['PAY_BANK_ACCOUNT_NAME'] === '' && $v['PAY_BANK_ACCOUNT_NUMBER'] === '';
    if ($bank_default) {
        foreach (['PAY_BANK_NAME', 'PAY_BANK_ACCOUNT_NAME', 'PAY_BANK_ACCOUNT_NUMBER'] as $k) {
            $v[$k] = SHOP_PAYMENT_DEFAULTS[$k];
        }
    }
    $url = static fn(string $path) => $path === '' ? null : (preg_match('#^https?://#', $path) ? $path : rtrim(BASE_URL, '/') . '/' . ltrim($path, '/'));
    return [
        'gcash' => [
            'label'          => 'GCash',
            'account_name'   => $v['PAY_GCASH_NAME'],
            'account_number' => $v['PAY_GCASH_NUMBER'],
            'bank_name'      => 'GCash',
            'qr_url'         => $url($v['PAY_GCASH_QR']),
            // A QR alone is enough to pay (scan it); otherwise name + number are needed.
            'ready'          => $v['PAY_GCASH_QR'] !== '' || ($v['PAY_GCASH_NAME'] !== '' && $v['PAY_GCASH_NUMBER'] !== ''),
            'is_default'     => $gcash_default,
        ],
        'bank' => [
            'label'          => 'Bank Transfer',
            'account_name'   => $v['PAY_BANK_ACCOUNT_NAME'],
            'account_number' => $v['PAY_BANK_ACCOUNT_NUMBER'],
            'bank_name'      => $v['PAY_BANK_NAME'],
            'qr_url'         => $url($v['PAY_BANK_QR']),
            'ready'          => $v['PAY_BANK_NAME'] !== '' && $v['PAY_BANK_ACCOUNT_NAME'] !== '' && $v['PAY_BANK_ACCOUNT_NUMBER'] !== '',
            'is_default'     => $bank_default,
        ],
    ];
}
