<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';
require_once __DIR__ . '/../app/helpers/loyalty_helper.php';
require_once __DIR__ . '/../app/helpers/delivery_message_helper.php';
require_once __DIR__ . '/../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../app/helpers/admin_badge_helper.php';
// Reused by approve_payment for stock handling – same helpers the admin
// order_details.php page uses (get_order_items, deduct_product_stock).
require_once __DIR__ . '/../customer/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$allowed_statuses = [
    'pending', 'confirmed', 'processing', 'ready_for_pickup', 'to_ship',
    'to_receive', 'reviews', 'out_for_delivery', 'completed', 'cancelled'
];

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

function request_body(): array
{
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : $_POST;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['HTTP_X_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

function require_staff(): array
{
    $payload = verifyJWT(bearer_token());
    if (!is_array($payload)) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if (!in_array($role, ['admin', 'staff'], true)) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    return $payload;
}

/**
 * Best-effort audit trail for actions taken from the staff app. Writes to the
 * existing `activity_logs` table using whatever columns it actually has, so it
 * never breaks a request if the schema differs. Silent on any failure.
 */
function app_audit(mysqli $conn, int $actor_id, string $action, string $details): void
{
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        $res = @$conn->query("SHOW COLUMNS FROM activity_logs");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $cols[strtolower($r['Field'])] = true;
            }
        }
    }
    if (!$cols) {
        return;
    }

    $row = [];
    if (isset($cols['user_id']))     $row['user_id'] = $actor_id;
    elseif (isset($cols['admin_id'])) $row['admin_id'] = $actor_id;

    if (isset($cols['action']))      $row['action'] = $action;
    elseif (isset($cols['activity'])) $row['activity'] = $action;
    elseif (isset($cols['event']))    $row['event'] = $action;

    $detailCol = null;
    foreach (['details', 'description', 'message', 'note', 'notes'] as $c) {
        if (isset($cols[$c])) { $detailCol = $c; break; }
    }
    if ($detailCol) {
        $row[$detailCol] = '[staff app] ' . $details;
    }
    if (isset($cols['ip_address'])) {
        $row['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    if (isset($cols['created_at']) && !isset($cols['created_at_default'])) {
        // leave created_at to its DEFAULT if present; only set when NOT nullable-with-default is unknown
    }

    if (!$row) {
        return;
    }
    $fields = array_keys($row);
    $place  = implode(',', array_fill(0, count($fields), '?'));
    $types  = '';
    $vals   = [];
    foreach ($row as $v) {
        $types .= is_int($v) ? 'i' : 's';
        $vals[] = $v;
    }
    $sql = 'INSERT INTO activity_logs (' . implode(',', $fields) . ') VALUES (' . $place . ')';
    $stmt = @$conn->prepare($sql);
    if ($stmt) {
        @$stmt->bind_param($types, ...$vals);
        @$stmt->execute();
        $stmt->close();
    }
}

/** Human labels for every order_status value (single place). */
function status_label(string $value): string
{
    static $labels = [
        'pending'          => 'Pending',
        'confirmed'        => 'Confirmed',
        'processing'       => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'to_ship'          => 'To Ship',
        'to_receive'       => 'To Receive',
        'reviews'          => 'Reviews',
        'out_for_delivery' => 'Out for Delivery',
        'completed'        => 'Completed',
        'cancelled'        => 'Cancelled',
    ];
    return $labels[$value] ?? ucwords(str_replace('_', ' ', $value));
}

/**
 * Full, read-only order detail used by lookup_order and get_order.
 * Reuses existing columns only – no schema changes.
 */
function fetch_order_detail(mysqli $conn, int $order_id): ?array
{
    $stmt = $conn->prepare(
        'SELECT o.id, o.order_number, o.order_status, o.fulfillment_type,
                o.subtotal, o.vat_amount, o.delivery_fee, o.discount_amount, o.total,
                o.payment_method, o.payment_reference, o.payment_settled,
                o.payment_proof_path, o.bank_name, o.bank_account_name,
                o.delivery_address, o.delivery_phone, o.pickup_date, o.pickup_time,
                o.created_at, o.updated_at,
                c.id AS customer_id, c.name AS customer_name, c.email AS customer_email,
                d.id AS delivery_id, d.status AS delivery_status
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN tbl_delivery d ON d.order_id = o.id
         WHERE o.id = ?
         ORDER BY d.id DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        return null;
    }

    $items = [];
    $item_stmt = $conn->prepare(
        'SELECT product_name, variant_size, quantity, unit_price, total_price
         FROM tbl_order_items WHERE order_id = ? ORDER BY id ASC'
    );
    $item_stmt->bind_param('i', $order_id);
    $item_stmt->execute();
    $item_res = $item_stmt->get_result();
    while ($row = $item_res->fetch_assoc()) {
        $items[] = [
            'product_name'  => (string) $row['product_name'],
            'variant_size'  => $row['variant_size'] !== null ? (string) $row['variant_size'] : null,
            'quantity'      => (int) $row['quantity'],
            'unit_price'    => (float) $row['unit_price'],
            'total_price'   => (float) $row['total_price'],
        ];
    }
    $item_stmt->close();

    return [
        'id'               => (int) $order['id'],
        'order_number'     => (string) $order['order_number'],
        'order_status'     => (string) $order['order_status'],
        'status_label'     => status_label((string) $order['order_status']),
        'fulfillment_type' => (string) ($order['fulfillment_type'] ?? ''),
        'subtotal'         => (float) $order['subtotal'],
        'vat_amount'       => (float) $order['vat_amount'],
        'delivery_fee'     => (float) $order['delivery_fee'],
        'discount_amount'  => (float) $order['discount_amount'],
        'total'            => (float) $order['total'],
        'payment_method'   => (string) ($order['payment_method'] ?? ''),
        'payment_reference'=> $order['payment_reference'] !== null ? (string) $order['payment_reference'] : null,
        'payment_settled'  => (int) ($order['payment_settled'] ?? 0) === 1,
        'bank_name'        => $order['bank_name'] !== null ? (string) $order['bank_name'] : null,
        'bank_account_name'=> $order['bank_account_name'] !== null ? (string) $order['bank_account_name'] : null,
        'payment_proof_url'=> !empty($order['payment_proof_path'])
            ? rtrim(BASE_URL, '/') . '/' . ltrim((string) $order['payment_proof_path'], '/')
            : null,
        // True when a prepaid (GCash / bank) order is still waiting for a
        // staff decision – mirrors modules/admin/order_details.php.
        'payment_approval_pending' => (string) $order['order_status'] === 'pending'
            && in_array((string) ($order['payment_method'] ?? ''), ['gcash', 'bank'], true),
        'delivery_address' => $order['delivery_address'] !== null ? (string) $order['delivery_address'] : null,
        'delivery_phone'   => $order['delivery_phone'] !== null ? (string) $order['delivery_phone'] : null,
        'pickup_date'      => $order['pickup_date'] !== null ? (string) $order['pickup_date'] : null,
        'pickup_time'      => $order['pickup_time'] !== null ? (string) $order['pickup_time'] : null,
        'created_at'       => (string) $order['created_at'],
        'updated_at'       => $order['updated_at'] !== null ? (string) $order['updated_at'] : null,
        'customer_id'      => (int) ($order['customer_id'] ?? 0),
        'customer_name'    => $order['customer_name'] !== null ? (string) $order['customer_name'] : null,
        'customer_email'   => $order['customer_email'] !== null ? (string) $order['customer_email'] : null,
        'delivery_id'      => $order['delivery_id'] !== null ? (int) $order['delivery_id'] : null,
        'delivery_status'  => $order['delivery_status'] !== null ? (string) $order['delivery_status'] : null,
        'items'            => $items,
    ];
}

$body = request_body();
$action = (string)($body['action'] ?? $_GET['action'] ?? '');

if ($action === 'login') {
    $identity = trim((string)($body['username'] ?? $body['username_email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($identity === '' || $password === '') {
        respond(['success' => false, 'message' => 'Username and password are required.'], 422);
    }

    $stmt = $conn->prepare('SELECT id, username, email, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Same lockout policy as the web login (modules/auth/login.php):
    // 3 failed attempts -> 5 minute lock. Success resets the counter.
    $MAX_ATTEMPTS = 3;
    $LOCK_SECONDS = 300;

    if ($user) {
        $now = time();
        $lock_ts = !empty($user['lock_until']) ? strtotime((string)$user['lock_until']) : 0;

        if ($lock_ts && $lock_ts > $now) {
            respond([
                'success' => false,
                'message' => 'Account temporarily locked after too many failed attempts. Try again in '
                    . ceil(($lock_ts - $now) / 60) . ' minute(s).',
                'locked_seconds' => $lock_ts - $now,
            ], 429);
        }
        // Expired lock -> start fresh.
        $attempts = ($lock_ts && $lock_ts <= $now) ? 0 : (int)($user['login_attempts'] ?? 0);

        if (!password_verify($password, (string)$user['password'])) {
            $attempts++;
            if ($attempts >= $MAX_ATTEMPTS) {
                $lock_until = date('Y-m-d H:i:s', $now + $LOCK_SECONDS);
                $u = $conn->prepare('UPDATE users SET login_attempts = ?, lock_until = ? WHERE id = ?');
                $u->bind_param('isi', $attempts, $lock_until, $user['id']);
                $u->execute();
                $u->close();
                respond([
                    'success' => false,
                    'message' => 'Too many failed attempts. Account locked for 5 minutes.',
                    'locked_seconds' => $LOCK_SECONDS,
                ], 429);
            }
            $u = $conn->prepare('UPDATE users SET login_attempts = ?, lock_until = NULL WHERE id = ?');
            $u->bind_param('ii', $attempts, $user['id']);
            $u->execute();
            $u->close();
            respond([
                'success' => false,
                'message' => 'Invalid username or password. ' . ($MAX_ATTEMPTS - $attempts) . ' attempt(s) left.',
            ], 401);
        }
    }

    if (!$user || !password_verify($password, (string)$user['password'])) {
        respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
    }

    $role = strtolower(trim((string)$user['role']));
    if (!in_array($role, ['admin', 'staff'], true)) {
        respond(['success' => false, 'message' => 'This account cannot access the staff app.'], 403);
    }

    // Successful login: clear the failed-attempt counter.
    $reset = $conn->prepare('UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?');
    $reset->bind_param('i', $user['id']);
    $reset->execute();
    $reset->close();

    $token = generateJWT((int)$user['id'], (string)$user['username'], $role);
    respond(['success' => true, 'data' => [
        'token' => $token,
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $role,
    ]]);
}

if ($action === 'app_health') {
    // Callable without a token so the app can show an "update required" notice.
    respond(['success' => true, 'data' => [
        'api_version'     => '2',
        'min_app_build'   => 1,
        'server_time'     => date('c'),
        'statuses'        => $allowed_statuses,
    ]]);
}

$actor = require_staff();
$actor_id = (int) ($actor['user_id'] ?? 0);

if ($action === 'validate_session') {
    respond(['success' => true, 'data' => ['authenticated' => true]]);
}

if ($action === 'attention_counts') {
    $pending = (int) ($conn->query(
        "SELECT COUNT(*) c FROM tbl_orders WHERE order_status = 'pending'"
    )->fetch_assoc()['c'] ?? 0);
    $awaiting = (int) ($conn->query(
        "SELECT COUNT(*) c FROM tbl_orders
         WHERE order_status = 'pending' AND payment_method IN ('gcash','bank')"
    )->fetch_assoc()['c'] ?? 0);
    // Same source the web admin sidebar badge uses (admin_badge_helper.php).
    $pending_remittances = function_exists('get_pending_remittance_count')
        ? (int) get_pending_remittance_count($conn)
        : (int) ($conn->query(
            "SELECT COUNT(*) c FROM tbl_rider_remittances WHERE status = 'pending'"
          )->fetch_assoc()['c'] ?? 0);
    $unread_messages = function_exists('get_unread_count_staff')
        ? (int) get_unread_count_staff($actor_id) : 0;
    $pending_reviews = (int) ($conn->query(
        "SELECT COUNT(*) c FROM tbl_product_reviews WHERE is_approved = 0"
    )->fetch_assoc()['c'] ?? 0);
    $unread_notifications = function_exists('notifications_get_unread_count')
        ? (int) notifications_get_unread_count($conn, $actor_id) : 0;
    respond(['success' => true, 'data' => [
        'pending_orders'       => $pending,
        'awaiting_payment'     => $awaiting,
        'pending_remittances'  => $pending_remittances,
        'unread_messages'      => $unread_messages,
        'pending_reviews'      => $pending_reviews,
        'unread_notifications' => $unread_notifications,
    ]]);
}

if ($action === 'list_riders') {
    $res = $conn->query(
        "SELECT u.id, u.username,
                (SELECT COUNT(*) FROM tbl_delivery d
                 JOIN tbl_orders o ON o.id = d.order_id
                 WHERE d.rider_id = u.id
                   AND o.order_status NOT IN ('completed','cancelled')) AS active_deliveries
         FROM users u
         WHERE LOWER(TRIM(u.role)) = 'rider'
         ORDER BY u.username ASC"
    );
    $riders = [];
    while ($res && $row = $res->fetch_assoc()) {
        $riders[] = [
            'id'                => (int) $row['id'],
            'username'          => (string) $row['username'],
            'active_deliveries' => (int) $row['active_deliveries'],
        ];
    }
    respond(['success' => true, 'data' => ['riders' => $riders]]);
}

if ($action === 'assign_rider') {
    $order_id  = (int) ($body['order_id'] ?? 0);
    $rider_id  = (int) ($body['rider_id'] ?? 0);
    if ($order_id <= 0 || $rider_id <= 0) {
        respond(['success' => false, 'message' => 'order_id and rider_id are required.'], 422);
    }

    $ord = $conn->prepare(
        "SELECT o.order_status, o.order_number FROM tbl_orders o WHERE o.id = ? LIMIT 1"
    );
    $ord->bind_param('i', $order_id);
    $ord->execute();
    $ord_row = $ord->get_result()->fetch_assoc();
    $ord->close();
    if (!$ord_row) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if (in_array($ord_row['order_status'], ['cancelled'], true)) {
        respond(['success' => false, 'message' => 'Cannot assign a rider to a cancelled order.'], 409);
    }

    $rc = $conn->prepare("SELECT username FROM users WHERE id = ? AND LOWER(TRIM(role)) = 'rider' LIMIT 1");
    $rc->bind_param('i', $rider_id);
    $rc->execute();
    $rider = $rc->get_result()->fetch_assoc();
    $rc->close();
    if (!$rider) {
        respond(['success' => false, 'message' => 'That rider account was not found.'], 404);
    }

    // Attach to the latest delivery row for this order; create one if missing.
    $d = $conn->prepare("SELECT id FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $d->bind_param('i', $order_id);
    $d->execute();
    $d_row = $d->get_result()->fetch_assoc();
    $d->close();

    if ($d_row) {
        $u = $conn->prepare("UPDATE tbl_delivery SET rider_id = ?, updated_at = NOW() WHERE id = ?");
        $u->bind_param('ii', $rider_id, $d_row['id']);
        $u->execute();
        $u->close();
    } else {
        $u = $conn->prepare(
            "INSERT INTO tbl_delivery (order_id, rider_id, delivery_type, status, qr_confirmation_token)
             VALUES (?, ?, 'delivery', 'pending', ?)"
        );
        $tok = bin2hex(random_bytes(16));
        $u->bind_param('iis', $order_id, $rider_id, $tok);
        $u->execute();
        $u->close();
    }

    // Let the rider know (they have the rider app; ws_push fires if online).
    notifications_ensure_schema($conn);
    notifications_create($conn, [
        'user_id'         => $rider_id,
        'type'            => 'delivery_assigned',
        'channel'         => 'in_app',
        'title'           => 'New delivery assigned',
        'message'         => 'You have been assigned to deliver order #' . $ord_row['order_number'] . '.',
        'reference_table' => 'tbl_orders',
        'reference_id'    => $order_id,
    ]);

    app_audit($conn, $actor_id, 'rider_assigned',
        "Order #{$ord_row['order_number']} ({$order_id}) assigned to rider {$rider['username']} ({$rider_id}).");

    respond(['success' => true, 'message' => 'Rider assigned.', 'data' => [
        'order_id'  => $order_id,
        'rider_id'  => $rider_id,
        'rider_name' => (string) $rider['username'],
    ]]);
}

if ($action === 'lookup_order') {
    $qr = json_decode((string)($body['qr_data'] ?? ''), true);
    if (!is_array($qr) || empty($qr['delivery_id']) || empty($qr['order_id']) || empty($qr['token'])) {
        respond(['success' => false, 'message' => 'Invalid delivery QR code.'], 422);
    }

    // Validate the QR against the shared tbl_delivery token (same contract the
    // customer portal and rider app use).
    $stmt = $conn->prepare(
        'SELECT d.order_id
         FROM tbl_delivery d
         WHERE d.id = ? AND d.order_id = ? AND d.qr_confirmation_token = ? LIMIT 1'
    );
    $delivery_id = (int)$qr['delivery_id'];
    $order_id = (int)$qr['order_id'];
    $token = (string)$qr['token'];
    $stmt->bind_param('iis', $delivery_id, $order_id, $token);
    $stmt->execute();
    $match = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$match) {
        respond(['success' => false, 'message' => 'No matching order was found for this QR code.'], 404);
    }

    $detail = fetch_order_detail($conn, (int) $match['order_id']);
    if (!$detail) {
        respond(['success' => false, 'message' => 'No matching order was found for this QR code.'], 404);
    }
    respond(['success' => true, 'data' => $detail]);
}

if ($action === 'get_order') {
    $order_id = (int) ($body['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        respond(['success' => false, 'message' => 'A valid order id is required.'], 422);
    }
    $detail = fetch_order_detail($conn, $order_id);
    if (!$detail) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    respond(['success' => true, 'data' => $detail]);
}

/**
 * Everything the Admin App's "Print E-Receipt" screen needs, in ONE call —
 * same architecture as the PHP Admin Portal's print_receipt.php: reuse
 * fetch_order_detail() for the base fields (identical to get_order, so
 * nothing here recalculates a total/VAT/discount the backend already
 * computed), then layer on the receipt-only extras (QR image, "Prepared
 * by", the real loyalty ledger, per-item weight/"Unit") that get_order
 * doesn't need. A dedicated action rather than widening get_order's shape,
 * so every other screen already depending on get_order's exact response is
 * untouched.
 *
 * QR: the SAME {delivery_id, order_id, token} payload and
 * ensure_order_delivery_token()/generate_qr_code_uri() used by
 * customer/orders.php, modules/admin/order_details.php and
 * modules/admin/print_receipt.php — no second QR system. Returned as a
 * base64 PNG so the app can render it without a second network round trip.
 */
if ($action === 'get_receipt') {
    $order_id = (int) ($body['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        respond(['success' => false, 'message' => 'A valid order id is required.'], 422);
    }

    $detail = fetch_order_detail($conn, $order_id);
    if (!$detail) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }

    $order_row    = get_order_by_id($order_id); // customer/includes/functions.php
    $customer_row = $detail['customer_id'] > 0 ? get_customer_by_id((int) $detail['customer_id']) : null;
    if (!$order_row) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }

    // Items enriched with weight_kg (the same real field the "Unit" column
    // on the Gmail/PHP-print receipts already uses) — get_order_items()
    // instead of fetch_order_detail()'s own lighter item query.
    $items_out = [];
    foreach (get_order_items($order_id) as $it) {
        $items_out[] = [
            'product_name' => (string) $it['product_name'],
            'variant_size' => $it['variant_size'] !== null ? (string) $it['variant_size'] : null,
            'quantity'     => (int) $it['quantity'],
            'unit_price'   => (float) $it['unit_price'],
            'total_price'  => (float) $it['total_price'],
            'weight_kg'    => (float) ($it['weight_kg'] ?? 0),
        ];
    }

    // QR — lazily creates the tbl_delivery/token row if this order has never
    // had one yet (same as print_receipt.php; otherwise most real orders,
    // which a customer hasn't opened themselves, would have no QR to show).
    $qr_image_base64 = null;
    $delivery_ref_id = null;
    if ((string) $order_row['order_status'] !== 'cancelled') {
        require_once __DIR__ . '/../modules/admin/qr_helper.php';
        $delivery = ensure_order_delivery_token($order_row);
        if ($delivery && !empty($delivery['qr_confirmation_token'])) {
            $delivery_ref_id = (int) $delivery['id'];
            $qr_payload = json_encode([
                'delivery_id' => (int) $delivery['id'],
                'order_id'    => (int) $order_id,
                'token'       => $delivery['qr_confirmation_token'],
            ]);
            $qr_uri = generate_qr_code_uri($qr_payload);
            if ($qr_uri !== '' && preg_match('/^data:image\/png;base64,(.+)$/', $qr_uri, $m)) {
                $qr_image_base64 = $m[1];
            }
            if ($qr_image_base64 === null) {
                // Same QuickChart fallback used everywhere else in this codebase.
                $ctx = stream_context_create(['http' => ['timeout' => 6]]);
                $bytes = @file_get_contents('https://quickchart.io/qr?size=400&text=' . urlencode($qr_payload), false, $ctx);
                if ($bytes) {
                    $qr_image_base64 = base64_encode($bytes);
                }
            }
        }
    }

    // "Prepared by" — ONLY real data (Admin-Assisted Ordering sets
    // created_by_admin_id to the real staff/admin who built the order). No
    // "Checked by / Received by / Approved by" — the system has no such data
    // for any order; never invented.
    $prepared_by = null;
    if (!empty($order_row['created_by_admin_id'])) {
        $prep_id = (int) $order_row['created_by_admin_id'];
        $prep_stmt = $conn->prepare('SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1');
        $prep_stmt->bind_param('i', $prep_id);
        $prep_stmt->execute();
        $prep = $prep_stmt->get_result()->fetch_assoc();
        $prep_stmt->close();
        if ($prep) {
            $prepared_by = trim($prep['first_name'] . ' ' . $prep['last_name']) . ' (' . ucfirst((string) $prep['role']) . ')';
        }
    }

    // Loyalty — the real loyalty_transactions ledger, never the
    // tbl_orders.loyalty_points_earned snapshot (which is written at
    // order-creation time and can be non-zero on a still-Pending order —
    // same fix already applied to the Gmail/PHP-print receipts).
    $lt_stmt = $conn->prepare('SELECT COALESCE(SUM(points_earned), 0) AS pts FROM loyalty_transactions WHERE order_id = ?');
    $lt_stmt->bind_param('i', $order_id);
    $lt_stmt->execute();
    $points_earned = (float) ($lt_stmt->get_result()->fetch_assoc()['pts'] ?? 0);
    $lt_stmt->close();

    $logo_url = SYSTEM_LOGO_URL;
    if (!preg_match('#^https?://#i', $logo_url)) {
        $logo_url = rtrim(BASE_URL, '/') . '/' . ltrim($logo_url, '/');
    }

    respond(['success' => true, 'data' => [
        'id'                       => (int) $order_id,
        'order_number'             => $detail['order_number'],
        'order_status'             => $detail['order_status'],
        'status_label'             => $detail['status_label'],
        'fulfillment_type'         => $detail['fulfillment_type'],
        'created_at'               => $detail['created_at'],
        'customer_name'            => $detail['customer_name'],
        'customer_phone'           => $customer_row['phone'] !== null ? (string) $customer_row['phone'] : null,
        'delivery_address'         => $detail['delivery_address'],
        'pickup_date'              => $detail['pickup_date'],
        'pickup_time'              => $detail['pickup_time'],
        'order_notes'              => $order_row['order_notes'] !== null ? (string) $order_row['order_notes'] : null,
        'payment_method'           => $detail['payment_method'],
        'payment_reference'        => $detail['payment_reference'],
        'bank_name'                => $detail['bank_name'],
        'bank_account_name'        => $detail['bank_account_name'],
        'items'                    => $items_out,
        'subtotal'                 => $detail['subtotal'],
        'vat_amount'               => $detail['vat_amount'],
        'delivery_fee'             => $detail['delivery_fee'],
        'discount_amount'          => $detail['discount_amount'],
        'total'                    => $detail['total'],
        'prepared_by'              => $prepared_by,
        'loyalty_points_earned'    => $points_earned,
        'customer_loyalty_balance' => (float) ($customer_row['loyalty_points'] ?? 0),
        'qr_image_base64'          => $qr_image_base64,
        'delivery_ref_id'          => $delivery_ref_id,
        'logo_url'                 => $logo_url,
        'system_name'              => SYSTEM_NAME,
        'shop_address'             => SHOP_ADDRESS,
    ]]);
}

/**
 * "📧 Send E-Receipt" — calls the EXISTING Gmail/PHPMailer e-receipt
 * function (notifications_send_order_receipt(), app/helpers/notification_helper.php)
 * that customer/orders.php's own "Email My E-Receipt" button already uses.
 * No SMTP credentials ever reach the app, no second email system: this
 * action only gathers the same real order/items/delivery/customer rows and
 * hands them to that one existing function.
 */
if ($action === 'send_receipt_email') {
    $order_id = (int) ($body['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        respond(['success' => false, 'message' => 'A valid order id is required.'], 422);
    }

    $order_row = get_order_by_id($order_id);
    if (!$order_row) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    $customer_row = get_customer_by_id((int) $order_row['customer_id']);
    if (!$customer_row) {
        respond(['success' => false, 'message' => 'Customer not found for this order.'], 404);
    }
    if ($order_row['order_status'] === 'cancelled') {
        respond(['success' => false, 'message' => 'A cancelled order has no delivery QR to email.'], 422);
    }

    $items_full = get_order_items($order_id);
    $delivery   = ensure_order_delivery_token($order_row);
    if (!$delivery || empty($delivery['qr_confirmation_token'])) {
        respond(['success' => false, 'message' => 'This order does not have an active delivery QR yet.'], 422);
    }

    $result = notifications_send_order_receipt($order_row, $items_full, $delivery, $customer_row, $conn);
    if ($result['success']) {
        respond(['success' => true, 'message' => 'E-receipt emailed to ' . $customer_row['email'] . '.']);
    }
    respond(['success' => false, 'message' => $result['error'] ?? 'Failed to send the e-receipt. Please try again.'], 502);
}

if ($action === 'dashboard_stats') {
    $counts = array_fill_keys($allowed_statuses, 0);
    $res = $conn->query('SELECT order_status, COUNT(*) AS c FROM tbl_orders GROUP BY order_status');
    $total = 0;
    while ($res && $row = $res->fetch_assoc()) {
        $counts[(string) $row['order_status']] = (int) $row['c'];
        $total += (int) $row['c'];
    }
    $today = (int) ($conn->query(
        "SELECT COUNT(*) AS c FROM tbl_orders WHERE DATE(created_at) = CURDATE()"
    )->fetch_assoc()['c'] ?? 0);
    $active = $total - ($counts['completed'] ?? 0) - ($counts['cancelled'] ?? 0);

    $by_status = [];
    foreach ($counts as $value => $count) {
        $by_status[] = ['status' => $value, 'label' => status_label($value), 'count' => $count];
    }

    // --- Money / people totals: identical queries to modules/admin/dashboard.php ---
    $total_sales = (float) ($conn->query(
        "SELECT COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS s FROM tbl_orders"
    )->fetch_assoc()['s'] ?? 0);
    $total_customers = (int) ($conn->query("SELECT COUNT(*) AS c FROM customers")->fetch_assoc()['c'] ?? 0);
    $total_staff = (int) ($conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'] ?? 0);
    $unremitted_cod = (float) ($conn->query(
        "SELECT COALESCE(SUM(total), 0) AS t FROM tbl_orders
         WHERE rider_id IS NOT NULL AND order_status = 'completed'
           AND payment_method = 'cod' AND payment_settled = 0"
    )->fetch_assoc()['t'] ?? 0);
    $usable_points = (float) ($conn->query(
        "SELECT COALESCE(SUM(loyalty_points), 0) AS t FROM customers"
    )->fetch_assoc()['t'] ?? 0);
    $gross_points = (float) ($conn->query(
        "SELECT COALESCE(SUM(points_earned), 0) AS t FROM loyalty_transactions"
    )->fetch_assoc()['t'] ?? 0);
    $awaiting_payment = (int) ($conn->query(
        "SELECT COUNT(*) AS c FROM tbl_orders
         WHERE order_status = 'pending' AND payment_method IN ('gcash','bank')"
    )->fetch_assoc()['c'] ?? 0);

    $unread_messages = 0;
    if (function_exists('get_unread_count_staff')) {
        $unread_messages = (int) get_unread_count_staff($actor_id);
    }

    // --- Recent orders (5) ---
    $recent_orders = [];
    $ro = $conn->query(
        "SELECT o.id, o.order_number, o.order_status, o.total, o.created_at, o.fulfillment_type,
                c.name AS customer_name
         FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id
         ORDER BY o.created_at DESC LIMIT 5"
    );
    while ($ro && $r = $ro->fetch_assoc()) {
        $recent_orders[] = [
            'id'            => (int) $r['id'],
            'order_number'  => (string) $r['order_number'],
            'order_status'  => (string) $r['order_status'],
            'status_label'  => status_label((string) $r['order_status']),
            'total'         => (float) $r['total'],
            'created_at'    => (string) $r['created_at'],
            'fulfillment_type' => (string) ($r['fulfillment_type'] ?? ''),
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : null,
        ];
    }

    // --- Recent rider remittances (5) ---
    $recent_remittances = [];
    $rr = $conn->query(
        "SELECT r.id, r.reference_number, r.amount, r.status, r.requested_at,
                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_name,
                u.username AS rider_username,
                (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = r.id) AS order_count
         FROM tbl_rider_remittances r
         LEFT JOIN users u ON u.id = r.rider_id
         ORDER BY r.id DESC LIMIT 5"
    );
    while ($rr && $r = $rr->fetch_assoc()) {
        $recent_remittances[] = [
            'id'               => (int) $r['id'],
            'reference_number' => (string) ($r['reference_number'] ?? ''),
            'amount'           => (float) $r['amount'],
            'status'           => (string) $r['status'],
            'order_count'      => (int) $r['order_count'],
            'requested_at'     => (string) ($r['requested_at'] ?? ''),
            'rider_name'       => trim((string) $r['rider_name']) !== ''
                ? trim((string) $r['rider_name']) : (string) ($r['rider_username'] ?? 'Rider'),
        ];
    }

    respond(['success' => true, 'data' => [
        'total_orders'         => $total,
        'active_orders'        => max(0, $active),
        'today_orders'         => $today,
        'total_sales'          => $total_sales,
        'total_customers'      => $total_customers,
        'total_staff'          => $total_staff,
        'unremitted_cod'       => $unremitted_cod,
        'usable_loyalty_points'=> $usable_points,
        'gross_points_issued'  => $gross_points,
        'awaiting_payment'     => $awaiting_payment,
        'unread_messages'      => $unread_messages,
        'by_status'            => $by_status,
        'recent_orders'        => $recent_orders,
        'recent_remittances'   => $recent_remittances,
    ]]);
}

if ($action === 'list_orders') {
    $status = trim((string) ($body['status'] ?? $_GET['status'] ?? ''));
    $search = trim((string) ($body['search'] ?? $_GET['search'] ?? ''));
    $limit  = (int) ($body['limit'] ?? $_GET['limit'] ?? 30);
    $offset = (int) ($body['offset'] ?? $_GET['offset'] ?? 0);
    $limit  = max(1, min($limit, 100));
    $offset = max(0, $offset);

    $where = [];
    $types = '';
    $params = [];
    if ($status !== '' && in_array($status, $allowed_statuses, true)) {
        $where[] = 'o.order_status = ?';
        $types  .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.email LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $count_sql = "SELECT COUNT(*) AS c FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    if ($types !== '') {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int) ($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $count_stmt->close();

    $list_sql = "SELECT o.id, o.order_number, o.order_status, o.fulfillment_type,
                        o.total, o.created_at, o.payment_method,
                        c.name AS customer_name, c.email AS customer_email
                 FROM tbl_orders o
                 LEFT JOIN customers c ON c.id = o.customer_id
                 $where_sql
                 ORDER BY o.created_at DESC
                 LIMIT ? OFFSET ?";
    $list_types = $types . 'ii';
    $list_params = array_merge($params, [$limit, $offset]);
    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param($list_types, ...$list_params);
    $list_stmt->execute();
    $list_res = $list_stmt->get_result();
    $orders = [];
    while ($row = $list_res->fetch_assoc()) {
        $orders[] = [
            'id'            => (int) $row['id'],
            'order_number'  => (string) $row['order_number'],
            'order_status'  => (string) $row['order_status'],
            'status_label'  => status_label((string) $row['order_status']),
            'fulfillment_type' => (string) ($row['fulfillment_type'] ?? ''),
            'total'         => (float) $row['total'],
            'created_at'    => (string) $row['created_at'],
            'payment_method'=> (string) ($row['payment_method'] ?? ''),
            'customer_name' => $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            'customer_email'=> $row['customer_email'] !== null ? (string) $row['customer_email'] : null,
        ];
    }
    $list_stmt->close();

    respond(['success' => true, 'data' => [
        'orders' => $orders,
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]]);
}

if ($action === 'update_status') {
    $order_id = (int)($body['order_id'] ?? 0);
    $status = (string)($body['status'] ?? '');
    if ($order_id <= 0 || !in_array($status, $allowed_statuses, true)) {
        respond(['success' => false, 'message' => 'Invalid order or status.'], 422);
    }

    $current_stmt = $conn->prepare(
        'SELECT o.order_status, o.order_number, o.customer_id, o.created_at,
                o.fulfillment_type, o.pickup_date, o.pickup_time,
                o.delivery_address, c.name AS customer_name, c.email AS customer_email
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ? LIMIT 1'
    );
    $current_stmt->bind_param('i', $order_id);
    $current_stmt->execute();
    $current = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    if (!$current) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if (in_array($current['order_status'], ['completed', 'cancelled'], true)) {
        if ($current['order_status'] === 'cancelled' && $status === 'completed') {
            respond(['success' => false, 'message' => 'Cancelled orders cannot be completed.'], 409);
        }
        respond(['success' => false, 'message' => 'Completed or cancelled orders cannot be updated.'], 409);
    }
    if ($current['order_status'] === $status) {
        respond(['success' => false, 'message' => 'Order is already ' . str_replace('_', ' ', $status) . '.'], 409);
    }

    $conn->begin_transaction();
    $award_result = [];
    try {
        $stmt = $conn->prepare('UPDATE tbl_orders SET order_status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $status, $order_id);
        $stmt->execute();
        $stmt->close();

        if (in_array($status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed'], true)) {
            // Deduct product stock the moment the order moves into fulfillment
            // (normally "Processing"). Safe no-op if a GCash/bank "Approve
            // Payment" action (or the COD auto-confirm job) already deducted it.
            $stock_result = deduct_order_stock_once($conn, $order_id);
            if (!$stock_result['ok']) {
                throw new RuntimeException($stock_result['error'] ?? 'Insufficient stock.');
            }
            $award_result = loyalty_award_completed_order($conn, $order_id);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update order status.';
        respond(['success' => false, 'message' => $msg], $e instanceof RuntimeException ? 409 : 500);
    }

    // Automatic "DPS DELIVERY!" message when a delivery order actually moves into To Receive
    // (this path already rejected same-status updates above, so this is a real transition).
    if ($status === 'to_receive') {
        delivery_message_send_on_to_receive($conn, $order_id);
    }

    notifications_ensure_schema($conn);
    $status_labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'to_ship' => 'To Ship',
        'to_receive' => 'To Receive',
        'out_for_delivery' => 'Out for Delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    $order_number = (string) ($current['order_number'] ?? ('DPS-' . $order_id));
    $customer_name = trim((string) ($current['customer_name'] ?? 'Customer')) ?: 'Customer';
    $status_label = $status_labels[$status] ?? $status;
    $message = "Hello {$customer_name},\n\n"
        . "Your order #{$order_number} has been updated.\n\n"
        . "Current Order Status:\n{$status_label}\n\n"
        . "Order Date: " . date('F j, Y', strtotime((string) $current['created_at'])) . "\n"
        . "Fulfillment: " . ucfirst((string) ($current['fulfillment_type'] ?? ''));
    if (!empty($current['pickup_date'])) {
        $message .= "\nPickup Date: {$current['pickup_date']}";
    }
    if (!empty($current['pickup_time'])) {
        $message .= "\nPickup Time: {$current['pickup_time']}";
    }
    if ($status === 'completed' && (float) ($award_result['points'] ?? 0) > 0) {
        $message .= "\n\nLoyalty Points Earned: " . notifications_format_points((float) $award_result['points'])
            . "\nCurrent Loyalty Points Balance: " . notifications_format_points((float) ($award_result['balance'] ?? 0));
    }
    $message .= "\n\nYou can log in to your Darius Poultry Supplies customer portal to view the latest details of your order.\n\nThank you for shopping with Darius Poultry Supplies.";

    $notification_result = [];
    notifications_create($conn, [
        'customer_id' => (int) ($current['customer_id'] ?? 0),
        'type' => 'order_status_update',
        'channel' => 'both',
        'title' => "Order #{$order_number} Status Updated",
        'message' => $message,
        'reference_table' => 'tbl_orders',
        'reference_id' => $order_id,
        'email_to' => (string) ($current['customer_email'] ?? ''),
    ], $notification_result);

    app_audit($conn, $actor_id, 'order_status_update',
        "Order #{$order_number} ({$order_id}): {$current['order_status']} -> {$status}");

    $email_sent = (bool) ($notification_result['email_sent'] ?? false);
    respond(['success' => true, 'message' => $email_sent
        ? 'Order status updated successfully.'
        : 'Order status updated, but email notification could not be sent.',
        'order_status' => $status,
        'email_sent' => $email_sent,
        'data' => [
        'order_id' => $order_id,
        'order_status' => $status,
    ]]);
}

/**
 * Approve / reject a prepaid (GCash or bank transfer) payment that is waiting
 * for review. Same rules and side effects as modules/admin/order_details.php:
 *   approve -> deduct stock, set order to 'confirmed', notify the customer
 *   reject  -> set order to 'cancelled' (stock was never deducted while pending)
 */
if ($action === 'approve_payment' || $action === 'reject_payment') {
    $order_id = (int) ($body['order_id'] ?? 0);
    if ($order_id <= 0) {
        respond(['success' => false, 'message' => 'A valid order id is required.'], 422);
    }

    $stmt = $conn->prepare(
        'SELECT o.order_status, o.order_number, o.payment_method, o.customer_id,
                c.name AS customer_name, c.email AS customer_email
         FROM tbl_orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        respond(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if ($order['order_status'] !== 'pending'
        || !in_array((string) $order['payment_method'], ['gcash', 'bank'], true)) {
        respond([
            'success' => false,
            'message' => 'This order is not awaiting payment verification.',
        ], 409);
    }

    $order_number  = (string) ($order['order_number'] ?? ('DPS-' . $order_id));
    $customer_id   = (int) ($order['customer_id'] ?? 0);
    $customer_name = trim((string) ($order['customer_name'] ?? 'Customer')) ?: 'Customer';
    $customer_email = (string) ($order['customer_email'] ?? '');

    if ($action === 'reject_payment') {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Payment could not be verified.';
        }
        $upd = $conn->prepare(
            'UPDATE tbl_orders SET order_status = "cancelled", cancellation_reason = ?, updated_at = NOW() WHERE id = ?'
        );
        $upd->bind_param('si', $reason, $order_id);
        if (!$upd->execute()) {
            $upd->close();
            respond(['success' => false, 'message' => 'Unable to reject the payment.'], 500);
        }
        $upd->close();

        $notification_result = [];
        notifications_ensure_schema($conn);
        notifications_create($conn, [
            'customer_id'     => $customer_id,
            'type'            => 'order_status_update',
            'channel'         => 'both',
            'title'           => "Order #{$order_number} Cancelled",
            'message'         => "Hello {$customer_name},\n\nYour order #{$order_number} has been cancelled during payment verification.\n\nReason: {$reason}\n\nIf you believe this is a mistake, please contact Darius Poultry Supplies.",
            'reference_table' => 'tbl_orders',
            'reference_id'    => $order_id,
            'email_to'        => $customer_email,
        ], $notification_result);

        app_audit($conn, $actor_id, 'payment_rejected',
            "Order #{$order_number} ({$order_id}) payment rejected; order cancelled. Reason: {$reason}");

        respond([
            'success' => true,
            'message' => 'Payment rejected. The order has been cancelled.',
            'order_status' => 'cancelled',
            'email_sent' => (bool) ($notification_result['email_sent'] ?? false),
            'data' => ['order_id' => $order_id, 'order_status' => 'cancelled'],
        ]);
    }

    // approve_payment
    $conn->begin_transaction();
    try {
        // No-op if some other status change already deducted stock for this order.
        $stock_result = deduct_order_stock_once($conn, $order_id);
        if (!$stock_result['ok']) {
            throw new RuntimeException($stock_result['error'] ?? 'Not enough stock.');
        }
        $upd = $conn->prepare(
            'UPDATE tbl_orders SET order_status = "confirmed", updated_at = NOW() WHERE id = ?'
        );
        $upd->bind_param('i', $order_id);
        $upd->execute();
        $upd->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to approve the payment.';
        respond(['success' => false, 'message' => $msg], 409);
    }

    $notification_result = [];
    notifications_ensure_schema($conn);
    notifications_create($conn, [
        'customer_id'     => $customer_id,
        'type'            => 'order_confirmed',
        'channel'         => 'both',
        'title'           => "Order #{$order_number} Confirmed",
        'message'         => "Hello {$customer_name},\n\nYour payment for order #{$order_number} has been verified and the order is now confirmed.\n\nThank you for shopping with Darius Poultry Supplies.",
        'reference_table' => 'tbl_orders',
        'reference_id'    => $order_id,
        'email_to'        => $customer_email,
    ], $notification_result);

    app_audit($conn, $actor_id, 'payment_approved',
        "Order #{$order_number} ({$order_id}) payment approved; stock deducted; order confirmed.");

    respond([
        'success' => true,
        'message' => 'Payment approved. The order is now confirmed.',
        'order_status' => 'confirmed',
        'email_sent' => (bool) ($notification_result['email_sent'] ?? false),
        'data' => ['order_id' => $order_id, 'order_status' => 'confirmed'],
    ]);
}

/* ==========================================================================
 * REMITTANCE MONITORING  (mobile mirror of modules/admin/remittance_management.php)
 * All reads/writes hit the SAME tbl_rider_remittances / tbl_rider_remittance_items
 * the web module uses. verify/reject reproduce the web module's side effects for
 * a rider-submitted (pending) remittance the admin is looking at.
 * ======================================================================== */
if ($action === 'remittance_list') {
    ensure_remittance_schema($conn);
    $status = strtolower(trim((string) ($body['status'] ?? '')));
    $valid_status = in_array($status, ['pending', 'approved', 'rejected', 'discrepancy'], true);

    $where = $valid_status ? 'WHERE rr.status = ?' : '';
    $sql = "SELECT rr.id AS remittance_id, rr.amount, rr.reference_number, rr.proof_image_path,
                   rr.status, rr.notes, rr.expected_amount, rr.difference_amount,
                   COALESCE(rr.requested_at, rr.remitted_at) AS submitted_at,
                   COALESCE(rr.processed_at, rr.remitted_at) AS processed_at,
                   (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = rr.id) AS order_count,
                   (SELECT COALESCE(SUM(o.total),0) FROM tbl_rider_remittance_items i2
                      JOIN tbl_orders o ON o.id = i2.order_id WHERE i2.remittance_id = rr.id) AS cod_total,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_name,
                   u.username AS rider_username,
                   rr.received_at,
                   TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS received_by_name,
                   rb.username AS received_by_username
            FROM tbl_rider_remittances rr
            JOIN users u ON rr.rider_id = u.id
            LEFT JOIN users rb ON rb.id = COALESCE(rr.received_by_user_id, rr.processed_by_user_id)
            $where
            ORDER BY rr.id DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($valid_status) {
        $stmt->bind_param('s', $status);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'discrepancy' => 0];
    while ($r = $res->fetch_assoc()) {
        $rider_name = trim((string) $r['rider_name']) !== ''
            ? trim((string) $r['rider_name']) : (string) $r['rider_username'];
        $rows[] = [
            'id'               => (int) $r['remittance_id'],
            'remittance_number' => remittance_number((int) $r['remittance_id']),
            'reference_number' => (string) ($r['reference_number'] ?? ''),
            'amount'           => (float) $r['amount'],
            'cod_total'        => (float) $r['cod_total'],
            'expected_amount'  => $r['expected_amount'] !== null ? (float) $r['expected_amount'] : (float) $r['cod_total'],
            'difference'       => round((float) $r['amount'] - ($r['expected_amount'] !== null ? (float) $r['expected_amount'] : (float) $r['cod_total']), 2),
            'order_count'      => (int) $r['order_count'],
            'status'           => (string) $r['status'],
            'status_label'     => remittance_status_label($r['status']),
            'notes'            => $r['notes'] !== null ? (string) $r['notes'] : null,
            'rider_name'       => $rider_name,
            'rider_username'   => (string) $r['rider_username'],
            'submitted_at'     => (string) ($r['submitted_at'] ?? ''),
            'processed_at'     => $r['processed_at'] !== null ? (string) $r['processed_at'] : null,
            // Who at the shop received the rider's handover (not shown while it is still only submitted).
            'received_by'      => in_array($r['status'], ['pending', 'rejected'], true) ? null : (trim((string) $r['received_by_name']) !== '' ? trim((string) $r['received_by_name']) : ($r['received_by_username'] ?: null)),
            'received_at'      => in_array($r['status'], ['pending', 'rejected'], true) ? null : ($r['received_at'] !== null ? (string) $r['received_at'] : ($r['processed_at'] !== null ? (string) $r['processed_at'] : null)),
            'proof_image_url'  => !empty($r['proof_image_path'])
                ? rtrim(BASE_URL, '/') . '/' . ltrim((string) $r['proof_image_path'], '/') : null,
        ];
    }
    $stmt->close();
    foreach (['pending', 'approved', 'rejected', 'discrepancy'] as $s) {
        $counts[$s] = (int) ($conn->query(
            "SELECT COUNT(*) c FROM tbl_rider_remittances WHERE status = '" . $s . "'"
        )->fetch_assoc()['c'] ?? 0);
    }
    respond(['success' => true, 'data' => ['remittances' => $rows, 'counts' => $counts]]);
}

if ($action === 'remittance_detail') {
    ensure_remittance_schema($conn);
    $id = (int) ($body['remittance_id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'A valid remittance id is required.'], 422);
    }
    $stmt = $conn->prepare(
        "SELECT rr.id, rr.rider_id AS rider_user_id, rr.amount, rr.reference_number, rr.proof_image_path,
                rr.status, rr.notes,
                COALESCE(rr.requested_at, rr.remitted_at) AS submitted_at,
                COALESCE(rr.processed_at, rr.remitted_at) AS processed_at,
                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_name,
                u.username AS rider_username, u.email AS rider_email,
                TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS processed_by,
                rr.received_at,
                TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS received_by_name,
                rb.username AS received_by_username
         FROM tbl_rider_remittances rr
         JOIN users u ON rr.rider_id = u.id
         LEFT JOIN users p ON rr.processed_by_user_id = p.id
         LEFT JOIN users rb ON rb.id = COALESCE(rr.received_by_user_id, rr.processed_by_user_id)
         WHERE rr.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $h = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$h) {
        respond(['success' => false, 'message' => 'Remittance not found.'], 404);
    }

    $items = [];
    $it = $conn->prepare(
        "SELECT o.id, o.order_number, o.total, o.created_at, o.order_status, o.payment_settled,
                c.name AS customer_name
         FROM tbl_rider_remittance_items i
         JOIN tbl_orders o ON o.id = i.order_id
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE i.remittance_id = ?
         ORDER BY o.created_at ASC"
    );
    $it->bind_param('i', $id);
    $it->execute();
    $itr = $it->get_result();
    $cod_total = 0.0;
    while ($r = $itr->fetch_assoc()) {
        $cod_total += (float) $r['total'];
        $items[] = [
            'order_id'       => (int) $r['id'],
            'order_number'   => (string) $r['order_number'],
            'total'          => (float) $r['total'],
            'created_at'     => (string) $r['created_at'],
            'order_status'   => (string) $r['order_status'],
            'payment_settled'=> (int) $r['payment_settled'] === 1,
            'customer_name'  => $r['customer_name'] !== null ? (string) $r['customer_name'] : null,
        ];
    }
    $it->close();

    $rider_name = trim((string) $h['rider_name']) !== ''
        ? trim((string) $h['rider_name']) : (string) $h['rider_username'];
    respond(['success' => true, 'data' => [
        'id'               => (int) $h['id'],
        'reference_number' => (string) ($h['reference_number'] ?? ''),
        'amount'           => (float) $h['amount'],
        'cod_total'        => $cod_total,
        'status'           => (string) $h['status'],
        'status_label'     => remittance_status_label($h['status']),
        'notes'            => $h['notes'] !== null ? (string) $h['notes'] : null,
        'rider_name'       => $rider_name,
        'rider_username'   => (string) $h['rider_username'],
        'rider_email'      => (string) ($h['rider_email'] ?? ''),
        'submitted_at'     => (string) ($h['submitted_at'] ?? ''),
        'processed_at'     => $h['processed_at'] !== null ? (string) $h['processed_at'] : null,
        'processed_by'     => trim((string) ($h['processed_by'] ?? '')) ?: null,
        'received_by'      => in_array($h['status'], ['pending', 'rejected'], true) ? null : (trim((string) $h['received_by_name']) !== '' ? trim((string) $h['received_by_name']) : ($h['received_by_username'] ?: null)),
        'received_at'      => in_array($h['status'], ['pending', 'rejected'], true) ? null : ($h['received_at'] !== null ? (string) $h['received_at'] : ($h['processed_at'] !== null ? (string) $h['processed_at'] : null)),
        'proof_image_url'  => !empty($h['proof_image_path'])
            ? rtrim(BASE_URL, '/') . '/' . ltrim((string) $h['proof_image_path'], '/') : null,
        'items'            => $items,
    ]]);
}

if ($action === 'remittance_verify' || $action === 'remittance_reject') {
    ensure_remittance_schema($conn);
    $id = (int) ($body['remittance_id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'A valid remittance id is required.'], 422);
    }

    // Same rules as the web module: the expected amount comes from the orders, the
    // rider's declared handover is what gets received, and only a matching amount
    // is verified - anything else becomes a discrepancy and needs a reason.
    try {
        $conn->begin_transaction();
        if ($action === 'remittance_reject') {
            $result = remittance_apply_rejection($conn, $id, $actor_id, (string) ($body['reason'] ?? $body['notes'] ?? ''));
        } else {
            $result = remittance_apply_receipt($conn, $id, $actor_id, [
                'notes'              => (string) ($body['notes'] ?? ''),
                'discrepancy_reason' => (string) ($body['reason'] ?? $body['discrepancy_reason'] ?? ''),
            ]);
        }
        $conn->commit();
    } catch (RemittanceException $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => $e->getMessage()], $e->getMessage() === 'Remittance not found.' ? 404 : 409);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'message' => 'Unable to process this remittance.'], 409);
    }

    remittance_notify_rider($conn, $result);
    if ($action === 'remittance_reject') {
        remittance_audit($conn, $actor_id, 'Remittance Rejected',
            "Rejected {$result['number']} from {$result['rider_name']}. Reason: {$result['reason']}", '[staff app] ');
        respond(['success' => true, 'message' => 'Remittance rejected. The rider has been notified.', 'data' => ['id' => $id, 'status' => 'rejected']]);
    }
    remittance_audit_receipt($conn, $actor_id, $result, '[staff app] ');
    $verified = $result['status'] === 'approved';
    respond([
        'success' => true,
        'message' => $verified
            ? 'Remittance verified. Orders marked settled and the rider was notified.'
            : 'Remittance received with a discrepancy of ' . remittance_peso($result['difference']) . '. It needs to be resolved on the web Remittance page.',
        'data'    => ['id' => $id, 'status' => $result['status']],
    ]);
}

/* ==========================================================================
 * CUSTOMERS  (mirror of modules/customers/customers.php – read only)
 * ======================================================================== */
if ($action === 'list_customers') {
    $search = trim((string) ($body['search'] ?? ''));
    $limit  = max(1, min((int) ($body['limit'] ?? 30), 100));
    $offset = max(0, (int) ($body['offset'] ?? 0));

    $where = '';
    $types = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?';
        $like = '%' . $search . '%';
        $types = 'sss';
        $params = [$like, $like, $like];
    }
    $total = 0;
    $cs = $conn->prepare("SELECT COUNT(*) c FROM customers c $where");
    if ($types !== '') { $cs->bind_param($types, ...$params); }
    $cs->execute();
    $total = (int) ($cs->get_result()->fetch_assoc()['c'] ?? 0);
    $cs->close();

    $sql = "SELECT c.id, c.name, c.email, c.phone, c.loyalty_points, c.created_at,
                   (SELECT COUNT(*) FROM tbl_orders o WHERE o.customer_id = c.id) AS order_count,
                   (SELECT COALESCE(SUM(o2.total),0) FROM tbl_orders o2
                      WHERE o2.customer_id = c.id AND o2.order_status <> 'cancelled') AS total_spent
            FROM customers c
            $where
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id'             => (int) $r['id'],
            'name'           => (string) $r['name'],
            'email'          => (string) $r['email'],
            'phone'          => $r['phone'] !== null ? (string) $r['phone'] : null,
            'loyalty_points' => (float) $r['loyalty_points'],
            'order_count'    => (int) $r['order_count'],
            'total_spent'    => (float) $r['total_spent'],
            'created_at'     => (string) $r['created_at'],
        ];
    }
    $stmt->close();
    respond(['success' => true, 'data' => ['customers' => $rows, 'total' => $total]]);
}

if ($action === 'customer_detail') {
    $id = (int) ($body['customer_id'] ?? $body['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'A valid customer id is required.'], 422);
    }
    $stmt = $conn->prepare(
        "SELECT c.id, c.name, c.email, c.phone, c.gender, c.age, c.address,
                c.loyalty_points, c.created_at,
                (SELECT COUNT(*) FROM tbl_orders o WHERE o.customer_id = c.id) AS order_count,
                (SELECT COALESCE(SUM(o2.total),0) FROM tbl_orders o2
                   WHERE o2.customer_id = c.id AND o2.order_status <> 'cancelled') AS total_spent
         FROM customers c WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$c) {
        respond(['success' => false, 'message' => 'Customer not found.'], 404);
    }

    $orders = [];
    $o = $conn->prepare(
        "SELECT id, order_number, order_status, total, created_at, fulfillment_type
         FROM tbl_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $o->bind_param('i', $id);
    $o->execute();
    $ores = $o->get_result();
    while ($r = $ores->fetch_assoc()) {
        $orders[] = [
            'id'            => (int) $r['id'],
            'order_number'  => (string) $r['order_number'],
            'order_status'  => (string) $r['order_status'],
            'status_label'  => status_label((string) $r['order_status']),
            'total'         => (float) $r['total'],
            'created_at'    => (string) $r['created_at'],
            'fulfillment_type' => (string) ($r['fulfillment_type'] ?? ''),
        ];
    }
    $o->close();

    $loyalty = [];
    $l = $conn->prepare(
        "SELECT product_name, quantity_kg, points_earned, created_at
         FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $l->bind_param('i', $id);
    $l->execute();
    $lres = $l->get_result();
    while ($r = $lres->fetch_assoc()) {
        $loyalty[] = [
            'product_name' => (string) $r['product_name'],
            'quantity_kg'  => (float) $r['quantity_kg'],
            'points_earned'=> (float) $r['points_earned'],
            'created_at'   => (string) $r['created_at'],
        ];
    }
    $l->close();

    $redemptions = [];
    $rd = $conn->prepare(
        "SELECT reward_name, points_used, redeemed_at, status, expiry_date
         FROM reward_redemptions WHERE customer_id = ? ORDER BY redeemed_at DESC LIMIT 10"
    );
    $rd->bind_param('i', $id);
    $rd->execute();
    $rdres = $rd->get_result();
    while ($r = $rdres->fetch_assoc()) {
        $redemptions[] = [
            'reward_name' => (string) $r['reward_name'],
            'points_used' => (float) $r['points_used'],
            'redeemed_at' => (string) $r['redeemed_at'],
            'status'      => (string) ($r['status'] ?? ''),
            'expiry_date' => $r['expiry_date'] !== null ? (string) $r['expiry_date'] : null,
        ];
    }
    $rd->close();

    respond(['success' => true, 'data' => [
        'id'             => (int) $c['id'],
        'name'           => (string) $c['name'],
        'email'          => (string) $c['email'],
        'phone'          => $c['phone'] !== null ? (string) $c['phone'] : null,
        'gender'         => $c['gender'] !== null ? (string) $c['gender'] : null,
        'age'            => $c['age'] !== null ? (int) $c['age'] : null,
        'address'        => $c['address'] !== null ? (string) $c['address'] : null,
        'loyalty_points' => (float) $c['loyalty_points'],
        'order_count'    => (int) $c['order_count'],
        'total_spent'    => (float) $c['total_spent'],
        'created_at'     => (string) $c['created_at'],
        'recent_orders'  => $orders,
        'loyalty_transactions' => $loyalty,
        'redemptions'    => $redemptions,
    ]]);
}

/* ==========================================================================
 * LOYALTY POINTS  (mirror of modules/customers/loyalty_points.php – read only)
 * ======================================================================== */
if ($action === 'loyalty_overview') {
    $usable = (float) ($conn->query("SELECT COALESCE(SUM(loyalty_points),0) t FROM customers")->fetch_assoc()['t'] ?? 0);
    $gross  = (float) ($conn->query("SELECT COALESCE(SUM(points_earned),0) t FROM loyalty_transactions")->fetch_assoc()['t'] ?? 0);
    $redeemed = (float) ($conn->query("SELECT COALESCE(SUM(points_used),0) t FROM reward_redemptions")->fetch_assoc()['t'] ?? 0);
    $redemption_count = (int) ($conn->query("SELECT COUNT(*) c FROM reward_redemptions")->fetch_assoc()['c'] ?? 0);

    $top = [];
    $t = $conn->query(
        "SELECT id, name, loyalty_points FROM customers
         WHERE loyalty_points > 0 ORDER BY loyalty_points DESC LIMIT 10"
    );
    while ($t && $r = $t->fetch_assoc()) {
        $top[] = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'loyalty_points' => (float) $r['loyalty_points'],
        ];
    }

    $recent = [];
    $rc = $conn->query(
        "SELECT lt.product_name, lt.quantity_kg, lt.points_earned, lt.created_at,
                c.name AS customer_name
         FROM loyalty_transactions lt
         LEFT JOIN customers c ON c.id = lt.customer_id
         ORDER BY lt.created_at DESC LIMIT 20"
    );
    while ($rc && $r = $rc->fetch_assoc()) {
        $recent[] = [
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : 'Customer',
            'product_name'  => (string) $r['product_name'],
            'quantity_kg'   => (float) $r['quantity_kg'],
            'points_earned' => (float) $r['points_earned'],
            'created_at'    => (string) $r['created_at'],
        ];
    }

    respond(['success' => true, 'data' => [
        'usable_points'    => $usable,
        'gross_points'     => $gross,
        'redeemed_points'  => $redeemed,
        'redemption_count' => $redemption_count,
        'top_customers'    => $top,
        'recent_transactions' => $recent,
    ]]);
}

/* ==========================================================================
 * REWARDS + REDEMPTIONS  (mirror of manage_rewards.php / reward_redemption.php)
 * ======================================================================== */
if ($action === 'list_rewards') {
    $res = $conn->query(
        "SELECT id, reward_code, name, points, stock, validity_days, expiry_date, description
         FROM rewards ORDER BY points ASC"
    );
    $rows = [];
    while ($res && $r = $res->fetch_assoc()) {
        $rows[] = [
            'id'            => (int) $r['id'],
            'reward_code'   => (string) $r['reward_code'],
            'name'          => (string) $r['name'],
            'points'        => (float) $r['points'],
            'stock'         => (int) $r['stock'],
            'validity_days' => $r['validity_days'] !== null ? (int) $r['validity_days'] : null,
            'expiry_date'   => $r['expiry_date'] !== null ? (string) $r['expiry_date'] : null,
            'description'   => $r['description'] !== null ? (string) $r['description'] : null,
        ];
    }
    respond(['success' => true, 'data' => ['rewards' => $rows]]);
}

if ($action === 'list_redemptions') {
    $limit = max(1, min((int) ($body['limit'] ?? 50), 100));
    $res = $conn->query(
        "SELECT rr.id, rr.reward_code, rr.reward_name, rr.points_used, rr.redeemed_at,
                rr.card_number, rr.expiry_date, rr.status,
                c.name AS customer_name
         FROM reward_redemptions rr
         LEFT JOIN customers c ON c.id = rr.customer_id
         ORDER BY rr.redeemed_at DESC LIMIT " . $limit
    );
    $rows = [];
    while ($res && $r = $res->fetch_assoc()) {
        $rows[] = [
            'id'            => (int) $r['id'],
            'reward_code'   => (string) $r['reward_code'],
            'reward_name'   => (string) $r['reward_name'],
            'points_used'   => (float) $r['points_used'],
            'redeemed_at'   => (string) $r['redeemed_at'],
            'card_number'   => $r['card_number'] !== null ? (string) $r['card_number'] : null,
            'expiry_date'   => $r['expiry_date'] !== null ? (string) $r['expiry_date'] : null,
            'status'        => (string) ($r['status'] ?? ''),
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : 'Customer',
        ];
    }
    respond(['success' => true, 'data' => ['redemptions' => $rows]]);
}

/* ==========================================================================
 * PRODUCTS  (mirror of modules/admin/products.php – read only)
 * Catalog lives in tbl_product_inventory (+ tbl_product_variants).
 * ======================================================================== */
if ($action === 'list_products') {
    $search = trim((string) ($body['search'] ?? ''));
    $where = '';
    $types = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ?';
        $like = '%' . $search . '%';
        $types = 'sss';
        $params = [$like, $like, $like];
    }
    $sql = "SELECT p.id, p.sku, p.name, p.price, p.stock, p.category, p.brand, p.image_url,
                   (SELECT COUNT(*) FROM tbl_product_variants v WHERE v.product_id = p.id) AS variant_count,
                   (SELECT COALESCE(SUM(v2.stock),0) FROM tbl_product_variants v2 WHERE v2.product_id = p.id) AS variant_stock
            FROM tbl_product_inventory p
            $where
            ORDER BY p.name ASC";
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $variant_count = (int) $r['variant_count'];
        $rows[] = [
            'id'            => (int) $r['id'],
            'sku'           => (string) $r['sku'],
            'name'          => (string) $r['name'],
            'price'         => (float) $r['price'],
            'stock'         => $variant_count > 0 ? (int) $r['variant_stock'] : (int) $r['stock'],
            'base_stock'    => (int) $r['stock'],
            'category'      => $r['category'] !== null ? (string) $r['category'] : null,
            'brand'         => $r['brand'] !== null ? (string) $r['brand'] : null,
            'variant_count' => $variant_count,
            'image_url'     => !empty($r['image_url'])
                ? (preg_match('#^https?://#', (string) $r['image_url'])
                    ? (string) $r['image_url']
                    : rtrim(BASE_URL, '/') . '/' . ltrim((string) $r['image_url'], '/'))
                : null,
        ];
    }
    $stmt->close();
    respond(['success' => true, 'data' => ['products' => $rows]]);
}

/* ==========================================================================
 * REVIEWS  (mirror of modules/admin/reviews.php)
 * ======================================================================== */
if ($action === 'list_reviews') {
    $filter = strtolower(trim((string) ($body['filter'] ?? 'all')));
    $where = '';
    if ($filter === 'pending') { $where = 'WHERE r.is_approved = 0'; }
    elseif ($filter === 'approved') { $where = 'WHERE r.is_approved = 1'; }

    $res = $conn->query(
        "SELECT r.id, r.rating, r.review_text, r.is_approved, r.created_at,
                r.order_id, r.product_id,
                p.name AS product_name, c.name AS customer_name
         FROM tbl_product_reviews r
         LEFT JOIN tbl_product_inventory p ON p.id = r.product_id
         LEFT JOIN customers c ON c.id = r.customer_id
         $where
         ORDER BY r.created_at DESC LIMIT 100"
    );
    $rows = [];
    while ($res && $r = $res->fetch_assoc()) {
        $rows[] = [
            'id'            => (int) $r['id'],
            'rating'        => (int) $r['rating'],
            'review_text'   => $r['review_text'] !== null ? (string) $r['review_text'] : '',
            'is_approved'   => (int) $r['is_approved'] === 1,
            'created_at'    => (string) $r['created_at'],
            'order_id'      => (int) $r['order_id'],
            'product_name'  => $r['product_name'] !== null ? (string) $r['product_name'] : 'Product',
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : 'Customer',
        ];
    }
    $pending = (int) ($conn->query("SELECT COUNT(*) c FROM tbl_product_reviews WHERE is_approved = 0")->fetch_assoc()['c'] ?? 0);
    respond(['success' => true, 'data' => ['reviews' => $rows, 'pending_count' => $pending]]);
}

if ($action === 'review_set_approval') {
    $rid = (int) ($body['review_id'] ?? 0);
    $approve = (bool) ($body['approve'] ?? false);
    if ($rid <= 0) {
        respond(['success' => false, 'message' => 'A valid review id is required.'], 422);
    }
    $val = $approve ? 1 : 0;
    $stmt = $conn->prepare("UPDATE tbl_product_reviews SET is_approved = ? WHERE id = ?");
    $stmt->bind_param('ii', $val, $rid);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();
    app_audit($conn, $actor_id, 'review_moderated', "Review #{$rid} " . ($approve ? 'approved' : 'unapproved') . '.');
    respond(['success' => $ok, 'message' => $approve ? 'Review approved.' : 'Review hidden.', 'data' => ['id' => $rid, 'is_approved' => $approve]]);
}

/* ==========================================================================
 * NOTIFICATIONS  (staff/admin in-app feed – notifications table)
 * ======================================================================== */
if ($action === 'notifications_list') {
    notifications_ensure_schema($conn);
    $limit = max(1, min((int) ($body['limit'] ?? 40), 100));
    $stmt = $conn->prepare(
        "SELECT id, title, message, type, is_read, created_at, reference_table, reference_id
         FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT " . $limit
    );
    $stmt->bind_param('i', $actor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id'              => (int) $r['id'],
            'title'           => (string) $r['title'],
            'message'         => (string) $r['message'],
            'type'            => (string) $r['type'],
            'is_read'         => (int) $r['is_read'] === 1,
            'created_at'      => (string) $r['created_at'],
            'reference_table' => $r['reference_table'] !== null ? (string) $r['reference_table'] : null,
            'reference_id'    => $r['reference_id'] !== null ? (int) $r['reference_id'] : null,
        ];
    }
    $stmt->close();
    $unread = (int) notifications_get_unread_count($conn, $actor_id);
    respond(['success' => true, 'data' => ['notifications' => $rows, 'unread' => $unread]]);
}

if ($action === 'notifications_mark_read') {
    notifications_ensure_schema($conn);
    $one = (int) ($body['notification_id'] ?? 0);
    if ($one > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $one, $actor_id);
        $stmt->execute();
        $stmt->close();
    } else {
        notifications_mark_all_read($conn, $actor_id);
    }
    respond(['success' => true, 'data' => ['unread' => (int) notifications_get_unread_count($conn, $actor_id)]]);
}

/* ==========================================================================
 * MESSAGING  (customer <-> admin/staff support thread – messaging_helper.php)
 * Same shared thread the web admin/staff messaging pages use.
 * ======================================================================== */
if ($action === 'messaging_conversations') {
    messaging_ensure_schema($conn);
    $list = get_customers_with_messages();
    $rows = [];
    foreach ($list as $r) {
        $rows[] = [
            'customer_id'       => (int) $r['id'],
            'name'              => (string) $r['name'],
            'email'             => (string) ($r['email'] ?? ''),
            'unread_count'      => (int) ($r['unread_count'] ?? 0),
            'last_message_time' => (string) ($r['last_message_time'] ?? ''),
        ];
    }
    respond(['success' => true, 'data' => ['conversations' => $rows]]);
}

if ($action === 'messaging_thread') {
    messaging_ensure_schema($conn);
    $customer_id = (int) ($body['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        respond(['success' => false, 'message' => 'A valid customer id is required.'], 422);
    }
    $msgs = get_conversation($customer_id);
    if (!empty($body['mark_read'])) {
        // clear unread customer messages in this support thread
        $mk = $conn->prepare("UPDATE tbl_messages SET is_read = 1, read_at = NOW() WHERE customer_id = ? AND is_read = 0 AND sender_type = 'customer' AND user_id IS NULL");
        $mk->bind_param('i', $customer_id);
        $mk->execute();
        $mk->close();
    }
    $rows = [];
    foreach ($msgs as $m) {
        $rows[] = [
            'id'          => (int) $m['id'],
            'sender_type' => (string) $m['sender_type'],
            'sender_name' => (string) ($m['sender_name'] ?? ''),
            'message'     => (string) $m['message'],
            'is_read'     => (int) ($m['is_read'] ?? 0) === 1,
            'created_at'  => (string) $m['created_at'],
        ];
    }
    $cust = $conn->prepare("SELECT name, email FROM customers WHERE id = ? LIMIT 1");
    $cust->bind_param('i', $customer_id);
    $cust->execute();
    $c = $cust->get_result()->fetch_assoc();
    $cust->close();
    respond(['success' => true, 'data' => [
        'customer_id'   => $customer_id,
        'customer_name' => (string) ($c['name'] ?? 'Customer'),
        'customer_email'=> (string) ($c['email'] ?? ''),
        'messages'      => $rows,
    ]]);
}

if ($action === 'messaging_send') {
    messaging_ensure_schema($conn);
    $customer_id = (int) ($body['customer_id'] ?? 0);
    $text = trim((string) ($body['message'] ?? ''));
    if ($customer_id <= 0 || $text === '') {
        respond(['success' => false, 'message' => 'A customer id and a non-empty message are required.'], 422);
    }
    $role = strtolower(trim((string) ($actor['role'] ?? 'staff')));
    $sender_type = in_array($role, ['admin', 'staff'], true) ? $role : 'staff';
    $ok = send_message($customer_id, $actor_id, $sender_type, $text);
    if (!$ok) {
        respond(['success' => false, 'message' => 'The message could not be sent.'], 500);
    }
    app_audit($conn, $actor_id, 'message_sent', "Replied to customer #{$customer_id} support thread.");
    respond(['success' => true, 'message' => 'Message sent.', 'data' => ['customer_id' => $customer_id]]);
}

respond(['success' => false, 'message' => 'Unsupported action.'], 400);