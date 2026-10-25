<?php
/**
 * Toast notification poll — GET, JSON. Reads the EXISTING `notifications`
 * table (every event already goes through notifications_create()), so any
 * notification inserted anywhere shows up here with no per-event code.
 *
 *   GET ?portal=admin|staff|rider|customer       -> { ok, unread, latest_id, new: [] }
 *   GET ?portal=admin|staff|rider|customer&after=<id>  -> same, plus up to 5 rows with id > after
 *
 * `portal` says WHICH login cookie to read and WHICH role must be in it (admin/staff use
 * jwt_token_staff, customers use jwt_token_customer); 'admin' additionally
 * honours Settings > Security "Admin login OFF" (dev only). The account id always
 * comes from the verified token, never from the request, and every query is
 * scoped to that account — a changed ?after can only ever return the
 * caller's own rows. Not logged in -> 401.
 */
// Shared by every area: if anything starts a session, it must be the caller's own area's
// session (LM_CUSTOMER / LM_RIDER / LM_ADMIN), never another area's (app/helpers/session_helper.php).
define('APP_SESSION_AREA', [
    'customer' => 'customer', 'rider' => 'rider',
][(string) ($_GET['portal'] ?? '')] ?? 'admin');
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';

// Never hold the PHP session lock while polling (this endpoint doesn't write to it).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function poll_respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    poll_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// ---- Who is asking (from the existing JWT login cookies) --------------------
$portal_param = (string) ($_GET['portal'] ?? '');
$portal = $portal_param === 'customer' ? 'customer' : 'staff';
$role = '';
$account_id = 0;

if ($portal === 'customer') {
    require_once __DIR__ . '/../customer/includes/auth.php'; // customer_token_is_stale()
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $payload = verifyJWT($_COOKIE['jwt_token_customer'] ?? '');
    if (is_array($payload) && strtolower(trim((string) ($payload['role'] ?? ''))) === 'customer') {
        $cid = (int) ($payload['user_id'] ?? 0);
        // Same rule as customer_is_logged_in(): tokens issued before a password reset are revoked.
        if ($cid > 0 && !customer_token_is_stale($cid, (int) ($payload['iat'] ?? 0))) {
            $role = 'customer';
            $account_id = $cid;
        }
    }
} else {
    // Each portal reads ONLY its own login cookie and accepts ONLY its own role, so a rider
    // signed in on the same browser can never show up in the admin/staff bell (or vice versa).
    $want = in_array($portal_param, ['admin', 'staff', 'rider'], true) ? $portal_param : 'staff';
    $payload = verifyJWT($_COOKIE[$want === 'rider' ? JWT_PORTAL_COOKIES['rider'] : JWT_PORTAL_COOKIES['staff']] ?? '');
    $r = is_array($payload) ? strtolower(trim((string) ($payload['role'] ?? ''))) : '';
    if ($r === $want && (int) ($payload['user_id'] ?? 0) > 0) {
        $role = $r;
        $account_id = (int) $payload['user_id'];
    } elseif ($want === 'admin' && defined('ADMIN_AUTH_ENABLED') && !ADMIN_AUTH_ENABLED && !active_session_device_active('staff')) {
        // Settings > Security "Admin login OFF" (local/dev only — config.php forces login ON
        // in production). Admin pages already run as the bypass admin account in that mode
        // (see admin_auth_disabled()), so their poller uses the same account. Staff and
        // customer polling never take this path.
        $bypass = admin_auth_bypass_payload();
        $role = 'admin';
        $account_id = (int) ($bypass['user_id'] ?? 0);
    }
}

if ($role === '' || $account_id <= 0) {
    poll_respond(401, ['ok' => false, 'error' => 'Not logged in']);
}

notifications_ensure_schema($conn);

// ---- Recipient scope (see notifications_recipient_sql() for the rule) --------
[$scope_sql, $scope_types, $scope_args] = notifications_recipient_sql($role === 'customer' ? 'customer' : 'user', $account_id);

$stmt = $conn->prepare("SELECT COALESCE(SUM(is_read = 0), 0) AS unread, COALESCE(MAX(id), 0) AS latest_id FROM notifications WHERE $scope_sql");
$stmt->bind_param($scope_types, ...$scope_args);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$unread = (int) ($summary['unread'] ?? 0);
$latest_id = (int) ($summary['latest_id'] ?? 0);
$new = [];

// Only when the client already knows its starting point: old rows are never re-toasted on page load.
if (isset($_GET['after']) && ctype_digit((string) $_GET['after'])) {
    $after = (int) $_GET['after'];
    $stmt = $conn->prepare(
        "SELECT id, type, title, message, reference_table, reference_id, customer_id
         FROM notifications
         WHERE $scope_sql AND id > ?
         ORDER BY id ASC
         LIMIT 5"
    );
    $types = $scope_types . 'i';
    $args = array_merge($scope_args, [$after]);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $new[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'type' => notifications_toast_tone((string) $row['type'], (string) $row['title']),
            'link' => notifications_link_for($role, (string) $row['type'], (string) ($row['reference_table'] ?? ''), (int) ($row['reference_id'] ?? 0), (int) ($row['customer_id'] ?? 0)),
        ];
    }
    $stmt->close();
}

// Sidebar badges for admin/staff, so Orders and Remittance update on every page without a reload
// (same counts as modules/customers/sidebar.php and modules/staff/sidebar.php).
$badges = null;
$nav_dots = null;
if ($role === 'admin' || $role === 'staff') {
    require_once __DIR__ . '/../app/helpers/admin_badge_helper.php';
    $orders_sql = $role === 'admin'
        ? "SELECT COUNT(*) AS c FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"
        : "SELECT COUNT(*) AS c FROM tbl_orders WHERE order_status = 'pending'";
    $badges = [
        'orders' => (int) ($conn->query($orders_sql)->fetch_assoc()['c'] ?? 0),
        'remittance' => get_pending_remittance_count($conn),
    ];
    require_once __DIR__ . '/../app/helpers/messaging_helper.php';
    if (function_exists('get_unread_count_staff')) {
        $badges['messages'] = (int) get_unread_count_staff($account_id);
    }
    require_once __DIR__ . '/../app/helpers/nav_notif_helper.php';
    $nav_dots = nav_notif_counts($conn, $account_id); // Reviews / E-Bike number badges
}

poll_respond(200, ['ok' => true, 'unread' => $unread, 'latest_id' => $latest_id, 'new' => $new, 'badges' => $badges, 'nav' => $nav_dots]);
