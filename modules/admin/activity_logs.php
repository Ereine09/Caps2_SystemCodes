<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// Check if logged in via JWT
$token = getJWTFromCookie();
if (!$token) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = $payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'admin'));
// Activity log viewer must use the JWT user_id for filtering.
// Do NOT rely on any other IDs.
$user_id = (int)($payload['user_id'] ?? 0);

// --- Schema Update: Add customer_id for customer-side logging ---
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM activity_logs LIKE 'customer_id'");
if ($check_col && mysqli_num_rows($check_col) === 0) {
    mysqli_query($conn, "ALTER TABLE activity_logs ADD COLUMN customer_id INT NULL DEFAULT NULL AFTER user_id");
    mysqli_query($conn, "ALTER TABLE activity_logs MODIFY COLUMN user_id INT NULL DEFAULT NULL");
}

// Check if Admin - redirect if not admin
if (strtolower(trim($role)) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php?error=access_denied");
    exit();
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

/* --------------------------------------------------------------------
   Read-only search / filter / pagination inputs. Nothing here writes to
   activity_logs — this page only ever SELECTs from it.
   -------------------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user_filter'] ?? '');   // "u_<id>" (users.id) or "c_<id>" (customers.id)
$action_filter = trim($_GET['action_filter'] ?? ''); // exact existing al.action value
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
// Future dates can't be picked (the inputs have max = today); a typed/URL one is treated as today.
if ($date_from > date('Y-m-d')) { $date_from = date('Y-m-d'); }
if ($date_to > date('Y-m-d')) { $date_to = date('Y-m-d'); }
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

/* Build the WHERE clause shared by both the COUNT query and the page query. */
$where = [];
$params = [];
$types = '';

// Defensive: mirrors the original logic in case a non-admin ever reaches
// this file directly — admins (the only role allowed past the check above)
// see everything, everyone else would only see their own entries.
if ($role !== 'admin') {
    $where[] = "al.user_id = ?";
    $params[] = $user_id;
    $types .= 'i';
}

if ($user_filter !== '') {
    if (preg_match('/^u_(\d+)$/', $user_filter, $m)) {
        $where[] = "al.user_id = ?";
        $params[] = (int)$m[1];
        $types .= 'i';
    } elseif (preg_match('/^c_(\d+)$/', $user_filter, $m)) {
        $where[] = "al.customer_id = ?";
        $params[] = (int)$m[1];
        $types .= 'i';
    }
}

if ($action_filter !== '') {
    $where[] = "al.action = ?";
    $params[] = $action_filter;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(u.username LIKE ? OR c.name LIKE ? OR al.action LIKE ? OR al.details LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($date_from !== '') {
    $where[] = "al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}
if ($date_to !== '') {
    $where[] = "al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from_sql = "FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             LEFT JOIN customers c ON al.customer_id = c.id";

// --- Total matching this search/filter (drives pagination + result count) ---
$count_sql = "SELECT COUNT(*) AS total $from_sql $where_sql";
if ($params) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
} else {
    $total = (int) (mysqli_fetch_assoc(mysqli_query($conn, $count_sql))['total'] ?? 0);
}

$total_pages = max(1, (int) ceil($total / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// --- The actual page of results ---
$list_sql = "SELECT al.*, u.username, u.role AS user_role, c.name AS customer_name
             $from_sql
             $where_sql
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?";
$list_params = $params;
$list_types = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = $offset;

$list_stmt = $conn->prepare($list_sql);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$log_res = $list_stmt->get_result();

/* --------------------------------------------------------------------
   Summary cards — real, unfiltered counts straight from activity_logs
   (always reflect the whole table, not the current search/filter), so
   they read as a stable "at a glance" summary. Nothing hard-coded.
   -------------------------------------------------------------------- */
$total_activities = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM activity_logs"))['c'] ?? 0);
$admin_actions = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM activity_logs al JOIN users u ON al.user_id = u.id WHERE u.role = 'admin'"))['c'] ?? 0);
$staff_actions = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM activity_logs al JOIN users u ON al.user_id = u.id WHERE u.role = 'staff'"))['c'] ?? 0);
$customer_activities = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM activity_logs WHERE customer_id IS NOT NULL"))['c'] ?? 0);

/* --------------------------------------------------------------------
   Filter dropdown source data — built ONLY from actors/actions that
   actually appear in activity_logs, so nothing in the dropdowns is
   invented or unused.
   -------------------------------------------------------------------- */
$actions_list = [];
$actions_res = mysqli_query($conn, "SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC");
if ($actions_res) {
    while ($a = mysqli_fetch_assoc($actions_res)) {
        $actions_list[] = $a['action'];
    }
}

$actors_list = [];
$actors_res = mysqli_query($conn, "
    SELECT DISTINCT
        CASE WHEN al.user_id IS NOT NULL THEN CONCAT('u_', al.user_id) ELSE CONCAT('c_', al.customer_id) END AS filter_key,
        CASE WHEN al.user_id IS NOT NULL THEN u.username ELSE c.name END AS display_name,
        CASE WHEN al.user_id IS NOT NULL THEN u.role ELSE 'customer' END AS display_role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    LEFT JOIN customers c ON al.customer_id = c.id
    WHERE al.user_id IS NOT NULL OR al.customer_id IS NOT NULL
    ORDER BY display_name ASC
");
if ($actors_res) {
    while ($row = mysqli_fetch_assoc($actors_res)) {
        if (!empty($row['display_name'])) {
            $actors_list[] = $row;
        }
    }
}

/** Builds a link to this page preserving the current filters, with the
 *  given params overridden (or removed, when the override value is null). */
require_once __DIR__ . '/../../app/helpers/ui_helper.php';

function activity_logs_url(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return 'activity_logs.php' . ($qs !== '' ? ('?' . $qs) : '');
}

/** Maps an actual, existing activity_logs.action value to a badge style +
 *  icon by meaning (create/update/delete/login/etc). This never changes or
 *  invents the stored action text — it only decides how to color the label
 *  that's already there, the same way status/badge colors are decided
 *  everywhere else in the Admin UI. */
function classify_log_action(string $action): array {
    $a = strtolower($action);
    if (str_contains($a, 'delete') || str_contains($a, 'remove') || str_contains($a, 'reject') || str_contains($a, 'cancel')) {
        return ['log-danger', 'fa-trash-can'];
    }
    if (str_contains($a, 'logout')) {
        return ['log-neutral', 'fa-right-from-bracket'];
    }
    if (str_contains($a, 'login')) {
        return ['log-positive', 'fa-right-to-bracket'];
    }
    if (str_contains($a, 'approve')) {
        return ['log-positive', 'fa-circle-check'];
    }
    if (str_contains($a, 'add') || str_contains($a, 'create') || str_contains($a, 'new')) {
        return ['log-positive', 'fa-circle-plus'];
    }
    if (str_contains($a, 'update') || str_contains($a, 'edit') || str_contains($a, 'manage')) {
        return ['log-info', 'fa-pen-to-square'];
    }
    if (str_contains($a, 'point') || str_contains($a, 'reward') || str_contains($a, 'redemption')) {
        return ['log-warning', 'fa-star'];
    }
    return ['log-neutral', 'fa-circle-info'];
}

/** Role badge for the User column — only ever reflects a role that's
 *  actually on the record (users.role, or "Customer" derived from a real
 *  customer_id join). Returns '' when there's nothing real to show. */
function activity_role_badge(?string $userRole, bool $isCustomer): string {
    if ($isCustomer) {
        return '<span class="role-badge role-customer">Customer</span>';
    }
    $r = strtolower(trim($userRole ?? ''));
    if ($r === 'admin') return '<span class="role-badge role-admin">Admin</span>';
    if ($r === 'staff') return '<span class="role-badge role-staff">Staff</span>';
    if ($r === 'rider') return '<span class="role-badge role-rider">Rider</span>';
    return '';
}

/**
 * Presentation-only formatter for activity_logs.details.
 *
 * This NEVER touches the database — $log['details'] stays exactly as
 * stored, is still what the search box matches against (al.details LIKE ?
 * above), and is still what any future "raw record" / troubleshooting view
 * would read. This function only decides what gets echoed onto the page:
 * known, structured log formats (order creation, login/logout, status
 * changes, rider assignment, payment/remittance/review actions — collected
 * by grepping every actual `INSERT INTO activity_logs` / app_audit() call
 * site in the codebase, not guessed) get rewritten into a plain sentence
 * with technical IDs and the internal DPS-xxxx-xxxxxxxxxx order-number
 * token removed. Anything that doesn't match a known shape — including any
 * action type added later — still goes through a generic cleanup pass that
 * strips the same handful of ID-revealing patterns, so a new log source
 * can't leak a raw numeric ID by default just because nobody wrote a
 * specific rule for it yet.
 */
function sanitize_activity_details(string $details): string {
    static $status_labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'out_for_delivery' => 'Out for Delivery',
        'to_ship' => 'To Ship',
        'to_receive' => 'To Receive',
        'reviews' => 'Reviews',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $text = trim($details);
    if ($text === '') {
        return $text;
    }

    // Every log written via the staff-app audit helper (app_audit() in
    // api/admin_app_api.php) is prefixed "[staff app] " — an internal
    // source tag, not something an admin needs to see.
    $text = preg_replace('/^\[[^\]]*\]\s*/', '', $text);

    // "Created order DPS-XXXX-XXXXXXXXXX for Jane Doe (Customer ID: 5)"
    if (preg_match('/^Created order\s+\S+\s+for\s+(.+?)\s*\(Customer ID:\s*\d+\)\.?$/i', $text, $m)) {
        return "Created a customer order for {$m[1]}.";
    }
    // "Customer 'Jane Doe' (ID: 5) logged in./out."
    if (preg_match('/^Customer\s+[\'"](.+?)[\'"]\s*\(ID:\s*\d+\)\s*logged (in|out)\.?$/i', $text, $m)) {
        return "{$m[1]} logged {$m[2]}.";
    }
    // "Order #DPS-XXXX-XXXXXXXXXX (106): processing -> out_for_delivery"
    if (preg_match('/^Order\s*#?\S+\s*\(\d+\):\s*([a-z_]+)\s*->\s*([a-z_]+)\.?$/i', $text, $m)) {
        $from = $status_labels[strtolower($m[1])] ?? ucwords(str_replace('_', ' ', $m[1]));
        $to = $status_labels[strtolower($m[2])] ?? ucwords(str_replace('_', ' ', $m[2]));
        return "Order status updated from {$from} to {$to}.";
    }
    // "Order #DPS-XXXX-XXXXXXXXXX (106) assigned to rider Rider1 (8)."
    if (preg_match('/^Order\s*#?\S+\s*\(\d+\)\s*assigned to rider\s+(.+?)\s*\(\d+\)\.?$/i', $text, $m)) {
        return "Order assigned to rider {$m[1]}.";
    }
    // "Order #DPS-XXXX-XXXXXXXXXX (106) payment approved; stock deducted; order confirmed."
    if (preg_match('/^Order\s*#?\S+\s*\(\d+\)\s*payment approved\b/i', $text)) {
        return "Payment approved; order confirmed.";
    }
    // "Order #DPS-XXXX-XXXXXXXXXX (106) payment rejected; order cancelled. Reason: X"
    if (preg_match('/^Order\s*#?\S+\s*\(\d+\)\s*payment rejected;\s*order cancelled\.\s*Reason:\s*(.+)$/i', $text, $m)) {
        return "Payment rejected; order cancelled. Reason: {$m[1]}";
    }
    // "Remittance #45 verified; 3 order(s) settled; amount PHP 1,234.56."
    if (preg_match('/^Remittance\s*#?\d+\s*verified;\s*(.+)$/i', $text, $m)) {
        return "Remittance verified; {$m[1]}";
    }
    // "Remittance #45 rejected. Reason: X"
    if (preg_match('/^Remittance\s*#?\d+\s*rejected\.\s*Reason:\s*(.+)$/i', $text, $m)) {
        return "Remittance rejected. Reason: {$m[1]}";
    }
    // "Review #12 approved./unapproved."
    if (preg_match('/^Review\s*#?\d+\s*(approved|unapproved)\.?$/i', $text, $m)) {
        return 'Review ' . strtolower($m[1]) . '.';
    }
    // "Replied to customer #7 support thread."
    if (preg_match('/^Replied to customer\s*#?\d+\s*support thread\.?$/i', $text)) {
        return 'Replied to a customer support thread.';
    }

    // Generic fallback — strips the same class of ID-revealing fragments
    // from anything the specific rules above didn't recognize, so a future
    // action type is never displayed with a raw ID by default.
    $clean = $text;
    $clean = preg_replace('/\((?:Customer|User|Order)\s*ID:\s*\d+\)/i', '', $clean);
    $clean = preg_replace('/\(ID:\s*\d+\)/i', '', $clean);
    $clean = preg_replace('/\bID:\s*\d+\b/i', '', $clean);           // bare "ID: 47" (no parens)
    $clean = preg_replace('/\bDPS-[A-Za-z0-9]+-\d+\b/', '', $clean); // raw internal order-number token
    $clean = preg_replace('/#\s*\(/', '(', $clean);                  // leftover "# (" -> "("
    $clean = preg_replace('/\(\d+\)/', '', $clean);                  // any other bare "(106)"-style numeric ref
    $clean = preg_replace('/\s{2,}/', ' ', $clean);                  // collapse double spaces left behind
    $clean = preg_replace('/\s+([.,:;])/', '$1', $clean);            // "word ." -> "word."
    $clean = preg_replace('/^[\s.,:;]+/', '', $clean);               // stray leading punctuation
    $clean = preg_replace('/:\s*$/', '', $clean);                    // trailing "...: " with nothing after it
    $clean = trim($clean);

    return $clean !== '' ? $clean : $text; // never show a blank row — fall back to the original text as a last resort
}

$has_active_filters = ($search !== '' || $user_filter !== '' || $action_filter !== '' || $date_from !== '' || $date_to !== '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin: 22px 0 26px; }
        .stat-card { background: var(--surface); padding: 18px 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .icon-blue { background: var(--info-soft); color: var(--info); }
        .icon-red { background: var(--danger-soft); color: var(--danger); }
        .icon-green { background: var(--success-soft); color: var(--success); }
        .icon-purple { background: var(--primary-soft); color: var(--accent); }
        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 800; color: var(--text); line-height: 1.1; }
        .stat-info p { margin: 2px 0 0; font-size: 0.8rem; color: var(--muted); font-weight: 600; }

        /* Toolbar: search + filters + refresh */
        .log-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; margin-bottom: 18px; }
        .log-toolbar .search-box { position: relative; flex: 1 1 220px; min-width: 200px; }
        .log-toolbar .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 0.85rem; }
        .log-toolbar .search-box input { width: 100%; padding: 10px 12px 10px 34px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; box-sizing: border-box; }
        .log-toolbar select, .log-toolbar input[type="date"] { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; min-width: 140px; }
        .log-toolbar .field-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); margin-bottom: 5px; }
        .log-toolbar .date-range { display: flex; align-items: center; gap: 6px; }
        .log-toolbar .date-range span { color: var(--muted); font-size: 0.8rem; }
        .log-toolbar .btn-apply { padding: 10px 18px; border-radius: 8px; border: 1px solid var(--dk-primary, #3498db); background: var(--dk-primary, #3498db); color: var(--dk-on-primary, #fff); font-weight: 600; cursor: pointer; font-size: 0.9rem; }
        .log-toolbar .btn-clear-filter { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); text-decoration: none; font-size: 0.85rem; font-weight: 600; white-space: nowrap; display: inline-flex; align-items: center; }
        .btn-refresh { padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text-2); font-weight: 600; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
        .btn-refresh:hover { background: var(--surface-2); }

        .result-count { color: var(--muted); font-size: 0.85rem; margin-bottom: 12px; }

        /* Role badges (shared concept/colors with Staff Management's role
           tags — admin=red, staff=green, rider=violet already wired into
           Dark Mode centrally; role-customer below is the one new tint). */
        .role-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; text-transform: capitalize; margin-top: 4px; }
        .role-admin { background: var(--danger-soft); color: var(--danger); }
        .role-staff { background: var(--success-soft); color: var(--success); }
        .role-rider { background: var(--primary-soft); color: var(--accent); }
        .role-customer { background: var(--info-soft); color: var(--info); }

        /* Action badges — colored by real meaning (see classify_log_action). */
        .log-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; white-space: nowrap; }
        .log-positive { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
        .log-neutral { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
        .log-info { background: var(--info-soft); color: var(--info); border: 1px solid #bfdbfe; }
        .log-warning { background: var(--warning-soft); color: var(--warning); border: 1px solid #fde68a; }
        .log-danger { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }

        .log-user-cell strong { display: block; }
        .log-details-cell { max-width: 420px; }
        .log-details-summary {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            color: var(--text-2);
            font-size: 0.88rem;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        .btn-view-details { background: none; border: none; color: #3498db; font-size: 0.8rem; font-weight: 600; cursor: pointer; padding: 0; }
        .btn-view-details:hover { text-decoration: underline; }
        .log-row { cursor: pointer; }
        .log-row:hover { background: var(--surface-2); }

        .empty-state-box { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state-box i { font-size: 2.4rem; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state-box p.main { color: var(--muted); font-weight: 600; margin: 0 0 4px; }
        .empty-state-box p.sub { margin: 0; font-size: 0.85rem; }

        /* Pagination */
        .log-pagination { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
        .log-pagination a, .log-pagination span.page-info {
            padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: var(--text-2); font-size: 0.85rem; font-weight: 600;
        }
        .log-pagination a:hover { background: var(--surface-2); }
        .log-pagination a.disabled { pointer-events: none; opacity: 0.4; }
        .log-pagination span.page-info { border: none; color: var(--muted); font-weight: 500; }

        /* Activity details modal */
        .activity-modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .activity-modal.active { display: flex; }
        .activity-modal .modal-content { background: var(--surface); border-radius: 14px; max-width: 480px; width: 100%; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .activity-modal .modal-title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .activity-modal .modal-title-row h2 { margin: 0; font-size: 1.15rem; }
        .activity-modal .modal-close-btn { background: none; border: none; font-size: 1.3rem; color: var(--muted); cursor: pointer; line-height: 1; padding: 4px; }
        .activity-modal dl { margin: 0; }
        .activity-modal dt { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); margin-top: 14px; }
        .activity-modal dt:first-child { margin-top: 0; }
        .activity-modal dd { margin: 4px 0 0; color: var(--text); font-size: 0.95rem; word-break: break-word; white-space: pre-wrap; }

        @media (max-width: 640px) {
            .log-toolbar { flex-direction: column; align-items: stretch; }
            .log-toolbar .date-range { flex-wrap: wrap; }
            .log-details-cell { max-width: none; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>
    <div class="main-content">
        <div class="welcome-header">
            <h1>Activity Logs 📋</h1>
            <p>Monitor system changes and user actions.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fas fa-list-check"></i></div>
                <div class="stat-info"><h3><?php echo $total_activities; ?></h3><p>Total Activities</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info"><h3><?php echo $admin_actions; ?></h3><p>Admin Actions</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info"><h3><?php echo $staff_actions; ?></h3><p>Staff Actions</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="fas fa-user"></i></div>
                <div class="stat-info"><h3><?php echo $customer_activities; ?></h3><p>Customer Activities</p></div>
            </div>
        </div>

        <div class="table-box">
            <form method="GET" action="activity_logs.php" class="log-toolbar">
                <div class="search-box">
                    <span class="field-label">Search</span>
                    <div style="position: relative;">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search activity...">
                    </div>
                </div>
                <div>
                    <span class="field-label">User</span>
                    <select name="user_filter">
                        <option value="">All Users</option>
                        <?php foreach ($actors_list as $actor): ?>
                            <option value="<?php echo htmlspecialchars($actor['filter_key']); ?>" <?php echo ($user_filter === $actor['filter_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($actor['display_name']); ?> (<?php echo htmlspecialchars(ucfirst($actor['display_role'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <span class="field-label">Action</span>
                    <select name="action_filter">
                        <option value="">All Actions</option>
                        <?php foreach ($actions_list as $act): ?>
                            <option value="<?php echo htmlspecialchars($act); ?>" <?php echo ($action_filter === $act) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($act); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <span class="field-label">Date Range</span>
                    <div class="date-range">
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" max="<?php echo date('Y-m-d'); ?>">
                        <span>&ndash;</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($has_active_filters): ?>
                    <a href="activity_logs.php" class="btn-clear-filter"><i class="fas fa-xmark"></i> Clear</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(activity_logs_url()); ?>" class="btn-refresh"><i class="fas fa-rotate"></i> Refresh</a>
            </form>

            <p class="result-count">
                <?php if ($total > 0): ?>
                    Showing <?php echo ($offset + 1); ?>&ndash;<?php echo min($offset + $per_page, $total); ?> of <?php echo $total; ?> activities
                <?php else: ?>
                    Showing 0 of 0 activities
                <?php endif; ?>
            </p>

            <?php if ($total > 0): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                            <th style="padding: 15px; text-align: left;">Date & Time</th>
                            <th style="padding: 15px; text-align: left;">User</th>
                            <th style="padding: 15px; text-align: left;">Action</th>
                            <th style="padding: 15px; text-align: left;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($log = mysqli_fetch_assoc($log_res)):
                            $is_customer = !empty($log['customer_id']);
                            if (!empty($log['username'])) {
                                $u_display = $log['username'];
                            } elseif (!empty($log['customer_name'])) {
                                $u_display = $log['customer_name'];
                            } else {
                                $u_display = "System/Deleted User";
                            }
                            $role_badge_html = activity_role_badge($log['user_role'] ?? null, $is_customer);

                            [$badge_class, $badge_icon] = classify_log_action($log['action'] ?? '');
                            $display_action = ucwords(strtolower(str_replace('_', ' ', $log['action'] ?? '')));

                            $datetime_full = date("F j, Y — g:i A", strtotime($log['created_at']));
                            $datetime_short = date("M d, Y — h:i A", strtotime($log['created_at']));
                            $role_label = $is_customer ? 'Customer' : ucfirst($log['user_role'] ?? '');
                            // $details_text is the RAW, unmodified DB value — still what
                            // the search box matches against and what stays in the record.
                            // $details_display is the sanitized text actually shown/echoed
                            // to the browser (table row + View Details modal).
                            $details_text = (string) ($log['details'] ?? '');
                            $details_display = sanitize_activity_details($details_text);
                        ?>
                            <tr class="log-row"
                                onclick="openActivityModal(this)"
                                data-datetime="<?php echo htmlspecialchars($datetime_full); ?>"
                                data-user="<?php echo htmlspecialchars($u_display); ?>"
                                data-role="<?php echo htmlspecialchars($role_label); ?>"
                                data-action="<?php echo htmlspecialchars($display_action); ?>"
                                data-details="<?php echo htmlspecialchars($details_display); ?>">
                                <td style="padding:15px; white-space: nowrap;"><?php echo $datetime_short; ?></td>
                                <td style="padding:15px;" class="log-user-cell">
                                    <strong><?php echo htmlspecialchars($u_display); ?></strong>
                                    <?php echo $role_badge_html; ?>
                                </td>
                                <td style="padding:15px;">
                                    <span class="log-badge <?php echo $badge_class; ?>">
                                        <i class="fas <?php echo $badge_icon; ?>"></i> <?php echo htmlspecialchars($display_action); ?>
                                    </span>
                                </td>
                                <td style="padding:15px;" class="log-details-cell">
                                    <div class="log-details-summary"><?php echo htmlspecialchars($details_display); ?></div>
                                    <button type="button" class="btn-view-details" onclick="event.stopPropagation(); openActivityModal(this.closest('tr'));">View Details</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="ui-pager-bar">
                <span class="ui-pager-count">Showing <?php echo ($offset + 1); ?>&ndash;<?php echo min($offset + $per_page, $total); ?> of <?php echo $total; ?> activities</span>
                <?php echo ui_pagination($page, $total_pages, static fn(int $p): string => activity_logs_url(['page' => $p]), 'Activity log pages'); ?>
            </div>
            <?php else: ?>
                <div class="empty-state-box">
                    <i class="fas fa-inbox"></i>
                    <p class="main">No activity found.</p>
                    <p class="sub">Try adjusting your search or filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Activity Details modal — read-only, shows only fields that already
         exist on the activity_logs record (no IP/device/browser/session,
         none of that is stored). -->
    <div class="activity-modal" id="activityModal">
        <div class="modal-content">
            <div class="modal-title-row">
                <h2><i class="fas fa-circle-info"></i> Activity Details</h2>
                <button type="button" class="modal-close-btn" onclick="closeActivityModal()" aria-label="Close">&times;</button>
            </div>
            <dl>
                <dt>Date &amp; Time</dt>
                <dd id="am-datetime"></dd>
                <dt>User</dt>
                <dd id="am-user"></dd>
                <dt>Role</dt>
                <dd id="am-role"></dd>
                <dt>Action</dt>
                <dd id="am-action"></dd>
                <dt>Details</dt>
                <dd id="am-details"></dd>
            </dl>
        </div>
    </div>

    <script>
        function openActivityModal(rowEl) {
            if (!rowEl) return;
            document.getElementById('am-datetime').textContent = rowEl.dataset.datetime || '';
            document.getElementById('am-user').textContent = rowEl.dataset.user || '';
            document.getElementById('am-role').textContent = rowEl.dataset.role || '—';
            document.getElementById('am-action').textContent = rowEl.dataset.action || '';
            document.getElementById('am-details').textContent = rowEl.dataset.details || '';
            document.getElementById('activityModal').classList.add('active');
        }
        function closeActivityModal() {
            document.getElementById('activityModal').classList.remove('active');
        }
        window.addEventListener('click', function (e) {
            if (e.target.id === 'activityModal') closeActivityModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeActivityModal();
        });
    </script>
</body>
</html>
