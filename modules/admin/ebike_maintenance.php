<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/ebike_helper.php';
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../shared/rider_board.php'; // Riders: Online / On Break / Offline

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}
$username = htmlspecialchars($payload['username']);
$user_role = htmlspecialchars($payload['role']);
$user_id = (int)$payload['user_id'];

// Sidebar badge counts
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$pending_remittances_count = get_pending_remittance_count($conn);
$unread_count = get_unread_count_staff($user_id);

/* ---------------------------------------------------------------- schema */
ebike_ensure_schema($conn); // tbl_ebikes (+ per-bike On/Off column), shared with customer checkout
$conn->query("CREATE TABLE IF NOT EXISTS tbl_ebike_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ebike_id INT NOT NULL,
    maintenance_type VARCHAR(100) NOT NULL,
    description TEXT NULL,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    serviced_on DATE NOT NULL,
    next_due_date DATE NULL,
    performed_by VARCHAR(100) NULL,
    status ENUM('in_progress','completed') NOT NULL DEFAULT 'completed',
    created_by_user_id INT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ebike (ebike_id),
    FOREIGN KEY (ebike_id) REFERENCES tbl_ebikes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$STATUS_LABELS = ['available' => 'Available', 'in_use' => 'In Use', 'maintenance' => 'Under Maintenance', 'retired' => 'Retired'];

function ebike_audit(mysqli $conn, int $user_id, string $action, string $details): void
{
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $user_id, $action, $details);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('E-bike audit failed: ' . $e->getMessage());
    }
}

function ebike_flash(string $type, string $message): void
{
    $_SESSION['ebike_flash'] = ['type' => $type, 'message' => $message];
}

function ebike_label(mysqli $conn, int $id): string
{
    $stmt = $conn->prepare("SELECT bike_code FROM tbl_ebikes WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['bike_code'] ?? '';
}

function ebike_valid_date(?string $d): ?string
{
    $d = trim((string)$d);
    if ($d === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/* ------------------------------------------------------------- handlers */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A raw csrf_require() dead-ends on a blank "Invalid or missing security token" page -
    // the admin has to manually go back and reload. Most of the time this just means the
    // PHP session token expired (page left open a while) or the tab was reloaded from
    // cache, not a real attack, so redirect back with a flash message instead, matching
    // how every other error on this page is already handled below.
    if (!csrf_check()) {
        ebike_flash('error', 'Your session took a while and the security token expired. Please try that action again.');
        header('Location: ebike_maintenance.php');
        exit();
    }
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'toggle_bike':
                // Switch ONE e-bike on/off without touching the others. Delivery stays available while any e-bike is available.
                $id = (int)($_POST['ebike_id'] ?? 0);
                $enable = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;
                $stmt = $conn->prepare("SELECT bike_code, status FROM tbl_ebikes WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $bike = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$bike) throw new RuntimeException('E-bike not found.');
                if ($bike['status'] === 'retired') throw new RuntimeException("{$bike['bike_code']} is retired. Change its status first to switch it on or off.");
                $stmt = $conn->prepare("UPDATE tbl_ebikes SET is_enabled = ? WHERE id = ?");
                $stmt->bind_param('ii', $enable, $id);
                $stmt->execute();
                $stmt->close();
                ebike_audit($conn, $user_id, 'E-Bike Switch', "E-bike {$bike['bike_code']} was switched " . ($enable ? 'on' : 'off') . '.');
                $msg = "{$bike['bike_code']} is now switched " . ($enable ? 'on' : 'off') . '. ' . ebike_fleet_message(ebike_fleet_summary($conn));
                ebike_flash('success', $msg);
                break;

            case 'toggle_bulk':
                // Choose which e-bikes may carry bulk orders. Riders can only pick up a bulk order on one
                // of these (ebike_bulk_check()); with none chosen, any e-bike may carry bulk orders.
                $id = (int)($_POST['ebike_id'] ?? 0);
                $bulk = ($_POST['bulk_ok'] ?? '') === '1' ? 1 : 0;
                $stmt = $conn->prepare("SELECT bike_code, status FROM tbl_ebikes WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $bike = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$bike) throw new RuntimeException('E-bike not found.');
                if ($bike['status'] === 'retired' && $bulk) throw new RuntimeException("{$bike['bike_code']} is retired. Change its status first to use it for bulk orders.");
                $stmt = $conn->prepare("UPDATE tbl_ebikes SET bulk_ok = ? WHERE id = ?");
                $stmt->bind_param('ii', $bulk, $id);
                $stmt->execute();
                $stmt->close();
                ebike_audit($conn, $user_id, 'E-Bike Bulk Orders', "E-bike {$bike['bike_code']} was " . ($bulk ? 'allowed' : 'no longer allowed') . ' to deliver bulk orders.');
                $left = (int)$conn->query("SELECT COUNT(*) FROM tbl_ebikes WHERE bulk_ok = 1 AND status <> 'retired'")->fetch_row()[0];
                ebike_flash('success', $bulk
                    ? "{$bike['bike_code']} can now deliver bulk orders. Riders must use one of the selected e-bikes for bulk orders."
                    : "{$bike['bike_code']} no longer delivers bulk orders." . ($left === 0 ? ' No e-bike is selected now, so any e-bike can carry bulk orders.' : ''));
                break;

            case 'add_bike':
                $code = trim((string)($_POST['bike_code'] ?? ''));
                $model = trim((string)($_POST['model'] ?? ''));
                $plate = trim((string)($_POST['plate_number'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($code === '') throw new RuntimeException('Enter a bike name or code (e.g. EB-01).');
                $model = $model !== '' ? $model : null;
                $plate = $plate !== '' ? $plate : null;
                $notes = $notes !== '' ? $notes : null;
                $stmt = $conn->prepare("INSERT INTO tbl_ebikes (bike_code, model, plate_number, notes) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $code, $model, $plate, $notes);
                try {
                    $stmt->execute();
                } catch (mysqli_sql_exception $e) {
                    if ((int)$e->getCode() === 1062) throw new RuntimeException('An e-bike with that name/code already exists.');
                    throw $e;
                }
                $stmt->close();
                ebike_audit($conn, $user_id, 'E-Bike Added', "Added e-bike {$code}" . ($model ? " ({$model})" : '') . '.');
                ebike_flash('success', "E-bike {$code} added.");
                break;

            case 'update_status':
                $id = (int)($_POST['ebike_id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if (!isset($STATUS_LABELS[$status])) throw new RuntimeException('Invalid status.');
                $label = ebike_label($conn, $id);
                if ($label === '') throw new RuntimeException('E-bike not found.');
                if ($status !== 'maintenance') {
                    $open = $conn->query("SELECT COUNT(*) c FROM tbl_ebike_maintenance WHERE ebike_id = $id AND status = 'in_progress'")->fetch_assoc();
                    if ((int)$open['c'] > 0) throw new RuntimeException("{$label} has maintenance in progress. Mark it completed first.");
                }
                $stmt = $conn->prepare("UPDATE tbl_ebikes SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $status, $id);
                $stmt->execute();
                $stmt->close();
                ebike_audit($conn, $user_id, 'E-Bike Status', "E-bike {$label} marked as {$STATUS_LABELS[$status]}.");
                ebike_flash('success', "{$label} is now {$STATUS_LABELS[$status]}.");
                break;

            case 'log_maintenance':
                $id = (int)($_POST['ebike_id'] ?? 0);
                $label = ebike_label($conn, $id);
                if ($label === '') throw new RuntimeException('Select an e-bike.');
                $type = trim((string)($_POST['maintenance_type'] ?? ''));
                if ($type === '') throw new RuntimeException('Enter the type of maintenance.');
                $desc = trim((string)($_POST['description'] ?? ''));
                $desc = $desc !== '' ? $desc : null;
                $cost = round((float)($_POST['cost'] ?? 0), 2);
                if ($cost < 0) throw new RuntimeException('Cost cannot be negative.');
                $serviced = ebike_valid_date($_POST['serviced_on'] ?? '') ?? date('Y-m-d');
                $next = ebike_valid_date($_POST['next_due_date'] ?? '');
                if ($next !== null && $next < $serviced) throw new RuntimeException('The next service date cannot be before the service date.');
                $by = trim((string)($_POST['performed_by'] ?? ''));
                $by = $by !== '' ? $by : null;
                $mstatus = ($_POST['status'] ?? '') === 'in_progress' ? 'in_progress' : 'completed';
                $completed_at = $mstatus === 'completed' ? date('Y-m-d H:i:s') : null;

                $conn->begin_transaction();
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_ebike_maintenance
                        (ebike_id, maintenance_type, description, cost, serviced_on, next_due_date, performed_by, status, created_by_user_id, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issdssssis', $id, $type, $desc, $cost, $serviced, $next, $by, $mstatus, $user_id, $completed_at);
                $stmt->execute();
                $stmt->close();
                if ($mstatus === 'in_progress') {
                    $conn->query("UPDATE tbl_ebikes SET status = 'maintenance' WHERE id = $id AND status <> 'retired'");
                }
                $conn->commit();
                ebike_audit($conn, $user_id, 'E-Bike Maintenance', "Logged {$type} for e-bike {$label}" . ($mstatus === 'in_progress' ? ' (in progress; bike set to under maintenance).' : ' (completed).'));
                ebike_flash('success', "Maintenance logged for {$label}.");
                break;

            case 'complete_maintenance':
                $mid = (int)($_POST['maintenance_id'] ?? 0);
                $conn->begin_transaction();
                $stmt = $conn->prepare("SELECT ebike_id, maintenance_type FROM tbl_ebike_maintenance WHERE id = ? AND status = 'in_progress' FOR UPDATE");
                $stmt->bind_param('i', $mid);
                $stmt->execute();
                $m = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$m) { $conn->rollback(); throw new RuntimeException('That maintenance record is not in progress.'); }
                $bike = (int)$m['ebike_id'];
                $stmt = $conn->prepare("UPDATE tbl_ebike_maintenance SET status = 'completed', completed_at = NOW() WHERE id = ?");
                $stmt->bind_param('i', $mid);
                $stmt->execute();
                $stmt->close();
                $open = $conn->query("SELECT COUNT(*) c FROM tbl_ebike_maintenance WHERE ebike_id = $bike AND status = 'in_progress'")->fetch_assoc();
                if ((int)$open['c'] === 0) {
                    $conn->query("UPDATE tbl_ebikes SET status = 'available' WHERE id = $bike AND status = 'maintenance'");
                }
                $conn->commit();
                $label = ebike_label($conn, $bike);
                ebike_audit($conn, $user_id, 'E-Bike Maintenance', "Completed {$m['maintenance_type']} for e-bike {$label}.");
                ebike_flash('success', "Maintenance completed for {$label}.");
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        ebike_flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('ebike_maintenance error: ' . $e->getMessage());
        ebike_flash('error', 'Something went wrong. Please try again.');
    }
    header('Location: ebike_maintenance.php');
    exit();
}

$flash = $_SESSION['ebike_flash'] ?? null;
unset($_SESSION['ebike_flash']);

/* ----------------------------------------------------------------- data */
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+7 days'));
$bikes = $conn->query(
    "SELECT b.*,
            (SELECT MAX(m.serviced_on) FROM tbl_ebike_maintenance m WHERE m.ebike_id = b.id AND m.status = 'completed') AS last_service,
            (SELECT m2.next_due_date FROM tbl_ebike_maintenance m2 WHERE m2.ebike_id = b.id AND m2.status = 'completed'
                ORDER BY m2.serviced_on DESC, m2.id DESC LIMIT 1) AS next_due
     FROM tbl_ebikes b ORDER BY b.bike_code"
)->fetch_all(MYSQLI_ASSOC);

$fleet = ebike_fleet_summary($conn);
$counts = [
    'total' => $fleet['total'], 'available' => $fleet['available'], 'in_use' => $fleet['in_use'],
    'maintenance' => $fleet['maintenance'], 'off' => $fleet['off'], 'due' => 0,
];
foreach ($bikes as &$b) {
    $b['is_on'] = (int)($b['is_enabled'] ?? 1) === 1;
    $b['due_state'] = '';
    if ($b['status'] !== 'retired' && $b['next_due']) {
        if ($b['next_due'] < $today) { $b['due_state'] = 'overdue'; $counts['due']++; }
        elseif ($b['next_due'] <= $soon) { $b['due_state'] = 'soon'; $counts['due']++; }
    }
}
unset($b);
$bulk_bikes = array_values(array_filter($bikes, static fn($b) => (int)($b['bulk_ok'] ?? 0) === 1 && $b['status'] !== 'retired'));
$fleet_message = ebike_fleet_message($fleet);
$delivery_ok = $fleet['available'] > 0;

// Who has each e-bike right now (recorded by the rider at pickup - see ebike_record_pickup()).
ebike_usage_ensure_schema($conn);
$with_rider = []; // ebike_id => list of open trips (rider_name, order_id, order_number, picked_up_at)
$res = $conn->query(
    "SELECT u.ebike_id, u.rider_name, u.order_id, u.picked_up_at, o.order_number
     FROM tbl_ebike_usage u JOIN tbl_orders o ON o.id = u.order_id
     WHERE u.returned_at IS NULL AND o.order_status IN (" . EBIKE_ON_ROAD_STATUSES . ")
     ORDER BY u.picked_up_at"
);
while ($res && ($r = $res->fetch_assoc())) {
    $with_rider[(int)$r['ebike_id']][] = $r;
}
// Riders who picked a bike in Rider Portal > Profile > My E-Bike (their e-bike even between trips).
$picked_by = []; // ebike_id => rider name
$res = $conn->query(
    "SELECT r.ebike_id, u.username, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS full_name
     FROM riders r JOIN users u ON u.id = r.user_id WHERE r.ebike_id IS NOT NULL"
);
while ($res && ($r = $res->fetch_assoc())) {
    $picked_by[(int)$r['ebike_id']] = $r['full_name'] !== '' ? $r['full_name'] : $r['username'];
}

// Maintenance log: paginated, optionally for one e-bike (?log_bike=id).
$log_bike = (int)($_GET['log_bike'] ?? 0);
$log_where = $log_bike > 0 ? "WHERE m.ebike_id = $log_bike" : '';
$log_total = (int)($conn->query("SELECT COUNT(*) c FROM tbl_ebike_maintenance m $log_where")->fetch_assoc()['c'] ?? 0);
$log_pg = ui_paginate($log_total, (int)($_GET['lpage'] ?? 1), 5); // 5 logs per page
$log = $conn->query(
    "SELECT m.*, b.bike_code FROM tbl_ebike_maintenance m
     JOIN tbl_ebikes b ON b.id = m.ebike_id
     $log_where
     ORDER BY (m.status = 'in_progress') DESC, m.serviced_on DESC, m.id DESC
     LIMIT {$log_pg['per_page']} OFFSET {$log_pg['offset']}"
)->fetch_all(MYSQLI_ASSOC);

// Usage history: who used which e-bike for which delivery, newest first.
$use_total = (int)($conn->query("SELECT COUNT(*) c FROM tbl_ebike_usage")->fetch_assoc()['c'] ?? 0);
$use_pg = ui_paginate($use_total, (int)($_GET['upage'] ?? 1), 10);
$usage = $conn->query(
    "SELECT u.*, b.bike_code, o.order_number, o.order_status
     FROM tbl_ebike_usage u
     JOIN tbl_ebikes b ON b.id = u.ebike_id
     LEFT JOIN tbl_orders o ON o.id = u.order_id
     ORDER BY u.picked_up_at DESC, u.id DESC
     LIMIT {$use_pg['per_page']} OFFSET {$use_pg['offset']}"
)->fetch_all(MYSQLI_ASSOC);

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_date = static fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
$fmt_time = static fn($d) => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Bike Maintenance</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        :root { --primary-color:#2563eb; --success-color:#16a34a; --danger-color:#dc2626; --bg-light:#f8fafc; --border-color:var(--border); --text-dark:#1e293b; --text-muted:#64748b; }
        body { overflow-x: hidden; background-color: var(--surface-2); }
        .page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:25px; background:var(--surface); padding:20px 25px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
        .page-header h1 { margin:0; font-size:1.5rem; color:var(--text-dark); display:flex; align-items:center; gap:12px; }
        .fleet-banner { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; margin-bottom:18px; font-weight:600; }
        .fleet-banner.ok { background:var(--success-soft); color:var(--success); } .fleet-banner.none { background:var(--warning-soft); color:var(--warning); }
        .content-card { background:var(--surface); border-radius:12px; padding:24px; margin-bottom:30px; box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid var(--border-color); }
        .content-card h3 { margin:0 0 18px; font-size:1.15rem; color:var(--text-dark); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .content-card h3 .spacer { flex:1; }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:18px; }
        /* Compact: label left, number right, one line */
        .stat { background:var(--surface); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:nowrap; min-height:44px; }
        .stat > span:first-child { line-height:1.25; }
        .stat span { font-size:.72rem; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted); font-weight:600; }
        .stat strong { display:block; font-size:1.3rem; margin:0; color:var(--text-dark); line-height:1.1; }
        .stat span + strong + span { display:none; } /* "N out with riders now" - same number as In use */
        /* One section at a time: Fleet & Maintenance | Riders | Usage */
        .eb-tab[hidden] { display:none !important; }
        .custom-table { width:100%; border-collapse:separate; border-spacing:0; }
        .custom-table th { background:var(--bg-light); padding:12px 14px; text-align:left; font-size:.85rem; font-weight:600; color:var(--text-muted); border-bottom:1px solid var(--border-color); white-space:nowrap; }
        .custom-table td { padding:12px 14px; border-bottom:1px solid var(--border-color); font-size:.9rem; color:var(--text-dark); vertical-align:middle; }
        .custom-table tr:last-child td { border-bottom:none; }
        .num { text-align:right; white-space:nowrap; }
        .pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-size:.78rem; font-weight:600; }
        .pill.available { background:var(--success-soft); color:var(--success); } .pill.in_use { background:var(--info-soft); color:var(--info); }
        .pill.maintenance, .pill.in_progress { background:var(--warning-soft); color:var(--warning); } .pill.retired { background:#e2e8f0; color:var(--text-2); }
        .pill.completed { background:var(--success-soft); color:var(--success); } .pill.overdue { background:var(--danger-soft); color:var(--danger); } .pill.soon { background:var(--warning-soft); color:var(--warning); }
        .btn { padding:8px 14px; border-radius:8px; border:1px solid var(--border-color); background:var(--surface); color:var(--text-dark); font-weight:600; font-size:.85rem; cursor:pointer; }
        .btn.primary { background:var(--primary-color); border-color:var(--primary-color); color:#fff; }
        .btn.sm { padding:5px 10px; font-size:.8rem; }
        .row-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        select.inline { padding:5px 8px; border:1px solid var(--border-color); border-radius:6px; font-size:.82rem; background:var(--surface); }
        .flash { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-weight:600; }
        .flash.success { background:var(--success-soft); color:var(--success); } .flash.error { background:var(--danger-soft); color:var(--danger); }
        .empty { text-align:center; padding:30px; color:var(--text-muted); }
        .modal { display:none; position:fixed; z-index:1001; inset:0; overflow:auto; background:rgba(15,23,42,.65); backdrop-filter:blur(4px); }
        .modal-content { background:var(--surface); margin:5% auto; padding:28px; width:90%; max-width:520px; border-radius:16px; box-shadow:0 20px 25px -5px rgba(0,0,0,.1); }
        .modal-content h2 { margin:0 0 16px; color:var(--text-dark); }
        .close-button { float:right; font-size:24px; font-weight:bold; cursor:pointer; color:var(--text-muted); }
        .field { margin-bottom:12px; } .field label { display:block; font-size:.8rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; }
        .field input, .field select, .field textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid var(--border-color); border-radius:8px; font-size:.95rem; font-family:inherit; }
        .two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
        .table-scroll { overflow-x:auto; }
        .switch { position:relative; display:inline-block; width:50px; height:26px; }
        .switch input { opacity:0; width:0; height:0; }
        .slider { position:absolute; cursor:pointer; inset:0; background-color:#cbd5e1; transition:.3s; border-radius:34px; }
        .slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background:var(--surface); transition:.3s; border-radius:50%; box-shadow:0 2px 4px rgba(0,0,0,.2); }
        input:checked + .slider { background-color:#10b981; }
        input:checked + .slider:before { transform:translateX(24px); }
        .bike-switch { display:flex; align-items:center; gap:10px; }
        .switch-label { font-size:.82rem; font-weight:700; min-width:24px; }
        .switch-label.on { color:var(--success); } .switch-label.off { color:var(--muted); }
        tr.bike-off td { background:var(--surface-2); }
        tr.bike-off td:not(:last-child):not(:nth-last-child(2)) { opacity:.7; }
        .cell-muted { color:var(--text-muted); }
        /* Fleet (left) + Maintenance Log (right); stacks on narrower screens. */
        .eb-layout { display:grid; grid-template-columns:minmax(0,1.75fr) minmax(0,1fr); gap:20px; align-items:stretch; margin-bottom:30px; }
        /* Both cards share the row's height (no empty gap under the shorter one); the log's pager sits at the bottom. */
        .eb-layout > .content-card { display:flex; flex-direction:column; }
        .eb-layout #maintenance-log .eb-pager { margin-top:auto; }
        .eb-layout > .content-card { margin-bottom:0; min-width:0; }
        .eb-fleet .custom-table th, .eb-fleet .custom-table td { padding:10px 10px; }
        .eb-status-form { margin:6px 0 0; }
        .eb-fleet select.inline { max-width:170px; }
        .eb-bulk-note { margin:0 0 12px; padding:10px 12px; border-radius:8px; background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-muted); font-size:.85rem; }
        .eb-bulk-note i { color:var(--primary-color); margin-right:4px; }
        .eb-bulk-note strong { color:var(--text, inherit); }
        .eb-actions { display:flex; flex-direction:column; align-items:flex-start; gap:8px; }
        .with-rider a { overflow-wrap:anywhere; font-size:.8rem; }
        .bike-meta { display:block; font-size:.78rem; color:var(--text-muted); margin-top:2px; font-weight:500; }
        .with-rider { display:flex; flex-direction:column; gap:3px; font-size:.84rem; }
        .with-rider a { color:var(--link); font-weight:600; text-decoration:none; }
        .with-rider small { color:var(--text-muted); }
        .svc-line { display:block; font-size:.84rem; white-space:nowrap; }
        .svc-line span { color:var(--text-muted); }
        .eb-log-list { list-style:none; margin:0; padding:0; }
        .eb-log-item { padding:12px 0; border-bottom:1px solid var(--border-color); display:grid; gap:4px; }
        .eb-log-item:first-child { padding-top:0; }
        .eb-log-item:last-child { border-bottom:0; }
        .eb-log-top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.8rem; color:var(--text-muted); }
        .eb-log-top strong { color:var(--text-dark); }
        .eb-log-top .pill { margin-left:auto; }
        .eb-log-type { font-weight:600; color:var(--text-dark); }
        .eb-log-desc { font-size:.85rem; color:var(--text-2); overflow-wrap:anywhere; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .eb-log-meta { display:flex; flex-wrap:wrap; align-items:center; gap:4px 14px; font-size:.8rem; color:var(--text-muted); }
        .eb-log-meta form { margin:0 0 0 auto; }
        .eb-pager { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:14px; font-size:.82rem; color:var(--text-muted); }
        .content-card h3 form { margin:0; }
        @media (max-width:1180px) { .eb-layout { grid-template-columns:minmax(0,1fr); align-items:start; } }
        @media (max-width:600px) { .two { grid-template-columns:1fr; } .content-card { padding:18px 16px; } }
    </style>
    <?php echo rider_board_styles(); ?>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-bicycle" style="color: var(--primary-color);"></i> E-Bike Maintenance</h1>

        </div>

        <main>
            <?php if ($flash): ?>
                <div class="flash <?php echo $h($flash['type']); ?>"><?php echo $h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="fleet-banner <?php echo $delivery_ok ? 'ok' : 'none'; ?>">
                <i class="fas <?php echo $delivery_ok ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <span><?php echo $h($fleet_message); ?></span>
            </div>

            <div class="stat-grid">
                <div class="stat"><span>Total e-bikes</span><strong><?php echo $counts['total']; ?></strong></div>
                <div class="stat"><span>Available</span><strong style="color:#15803d;"><?php echo $counts['available']; ?></strong></div>
                <div class="stat"><span>In use</span><strong style="color:#1d4ed8;"><?php echo $counts['in_use']; ?></strong>
                    <?php if ($with_rider): ?><span style="text-transform:none;letter-spacing:0;font-weight:500;"><?php echo count($with_rider); ?> out with riders now</span><?php endif; ?></div>
                <div class="stat"><span>Under maintenance</span><strong style="color:#b45309;"><?php echo $counts['maintenance']; ?></strong></div>
                <div class="stat"><span>Switched off</span><strong style="color:#64748b;"><?php echo $counts['off']; ?></strong></div>
                <div class="stat"><span>Service due / overdue</span><strong style="color:#b91c1c;"><?php echo $counts['due']; ?></strong></div>
            </div>

            <!-- Same tab style as the Dashboard (ui-kit .ui-tabs .ui-tabs-fill) -->
            <nav class="ui-tabs ui-tabs-fill eb-tabs" role="tablist" aria-label="E-bike sections">
                <button type="button" role="tab" data-tab="fleet">Fleet &amp; Maintenance</button>
                <button type="button" role="tab" data-tab="riders">Riders right now</button>
                <button type="button" role="tab" data-tab="usage">E-Bike Usage</button>
            </nav>

            <section class="eb-tab" id="tab-riders" hidden>
            <?php echo rider_board_html($conn); ?>
            </section>

            <section class="eb-tab" id="tab-fleet">
            <div class="eb-layout">
            <!-- Fleet -->
            <div class="content-card eb-fleet">
                <h3><i class="fas fa-motorcycle" style="color: var(--primary-color);"></i> Fleet &amp; Availability
                    <span class="spacer"></span>
                    <button class="btn primary" onclick="openModal('bike-modal')"><i class="fas fa-plus"></i> Add E-Bike</button>
                </h3>
                <?php if ($bikes): ?>
                    <p class="eb-bulk-note"><i class="fas fa-boxes-stacked"></i>
                        <?php if ($bulk_bikes): ?>
                            Bulk orders can only be picked up on: <strong><?php echo $h(implode(', ', array_column($bulk_bikes, 'bike_code'))); ?></strong>.
                        <?php else: ?>
                            No e-bike is selected for bulk orders, so riders can use any e-bike. Switch on <strong>Bulk Orders</strong> to choose which ones may carry them.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (!$bikes): ?>
                    <p class="empty">No e-bikes added yet. Click <strong>Add E-Bike</strong> to register your first one.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="custom-table">
                            <thead><tr>
                                <th>E-Bike &amp; Status</th>
                                <th title="Recorded when the rider confirms pickup of an order">With Rider</th>
                                <th title="Riders can only pick up a bulk order on an e-bike with this switched on. If none is switched on, any e-bike may carry bulk orders.">Bulk Orders</th>
                                <th>Service</th>
                                <th title="Log maintenance, or switch a single e-bike off (for example when its battery is low) without affecting the other e-bikes">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($bikes as $b): $holders = $with_rider[(int)$b['id']] ?? []; ?>
                                <tr class="<?php echo $b['is_on'] ? '' : 'bike-off'; ?>">
                                    <td>
                                        <strong><?php echo $h($b['bike_code']); ?></strong>
                                        <?php $meta = array_filter([$b['model'], $b['plate_number']]); if ($meta): ?><span class="bike-meta"><?php echo $h(implode(' · ', $meta)); ?></span><?php endif; ?>
                                        <?php if (!$b['is_on'] && $b['status'] !== 'retired'): ?><div><span class="pill retired" style="margin-top:4px;"><i class="fas fa-power-off"></i> Switched off</span></div><?php endif; ?>
                                        <form method="POST" class="eb-status-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="ebike_id" value="<?php echo (int)$b['id']; ?>">
                                            <select name="status" class="inline" onchange="this.form.submit()" aria-label="Status of <?php echo $h($b['bike_code']); ?>">
                                                <?php foreach ($STATUS_LABELS as $val => $lbl): ?>
                                                    <option value="<?php echo $val; ?>" <?php echo $b['status'] === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($holders): ?>
                                            <div class="with-rider">
                                                <strong><i class="fas fa-user"></i> <?php echo $h($holders[0]['rider_name']); ?></strong>
                                                <?php foreach ($holders as $hd): ?>
                                                    <a href="order_details.php?id=<?php echo (int)$hd['order_id']; ?>">#<?php echo $h($hd['order_number'] ?: $hd['order_id']); ?></a>
                                                <?php endforeach; ?>
                                                <small>since <?php echo $h(date('g:i A', strtotime($holders[0]['picked_up_at']))); ?></small>
                                            </div>
                                        <?php elseif (isset($picked_by[(int)$b['id']])): ?>
                                            <div class="with-rider">
                                                <span><i class="fas fa-user"></i> <?php echo $h($picked_by[(int)$b['id']]); ?></span>
                                                <small>picked in their profile · not on a delivery</small>
                                            </div>
                                        <?php else: ?>
                                            <span class="cell-muted">At the shop</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $is_bulk = (int)($b['bulk_ok'] ?? 0) === 1; ?>
                                        <?php if ($b['status'] === 'retired'): ?>
                                            <span class="cell-muted" title="Retired e-bikes can't deliver bulk orders">&mdash;</span>
                                        <?php else: ?>
                                            <form method="POST" class="bike-switch" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_bulk">
                                                <input type="hidden" name="ebike_id" value="<?php echo (int)$b['id']; ?>">
                                                <input type="hidden" name="bulk_ok" value="<?php echo $is_bulk ? '0' : '1'; ?>">
                                                <label class="switch" title="<?php echo $is_bulk ? 'Delivers bulk orders - click to stop' : 'Click to let this e-bike deliver bulk orders'; ?>">
                                                    <input type="checkbox" <?php echo $is_bulk ? 'checked' : ''; ?> onchange="this.form.submit();" aria-label="Let <?php echo $h($b['bike_code']); ?> deliver bulk orders">
                                                    <span class="slider"></span>
                                                </label>
                                                <span class="switch-label <?php echo $is_bulk ? 'on' : 'off'; ?>"><?php echo $is_bulk ? 'Yes' : 'No'; ?></span>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="svc-line"><span>Last:</span> <?php echo $fmt_date($b['last_service']); ?></span>
                                        <span class="svc-line"><span>Next:</span> <?php echo $fmt_date($b['next_due']); ?></span>
                                        <?php if ($b['due_state'] === 'overdue'): ?><span class="pill overdue">Overdue</span>
                                        <?php elseif ($b['due_state'] === 'soon'): ?><span class="pill soon">Due soon</span><?php endif; ?>
                                    </td>
                                    <td><div class="eb-actions">
                                        <button class="btn sm" onclick="openMaintenance(<?php echo (int)$b['id']; ?>)" title="Log maintenance for <?php echo $h($b['bike_code']); ?>"><i class="fas fa-wrench"></i> Log</button>
                                        <?php if ($b['status'] === 'retired'): ?>
                                            <span class="cell-muted" title="Retired e-bikes can't be switched on or off">&mdash;</span>
                                        <?php else: ?>
                                            <form method="POST" class="bike-switch" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_bike">
                                                <input type="hidden" name="ebike_id" value="<?php echo (int)$b['id']; ?>">
                                                <input type="hidden" name="enabled" value="<?php echo $b['is_on'] ? '0' : '1'; ?>">
                                                <label class="switch" title="<?php echo $b['is_on'] ? 'On - click to switch this e-bike off' : 'Off - click to switch this e-bike on'; ?>">
                                                    <input type="checkbox" <?php echo $b['is_on'] ? 'checked' : ''; ?> onchange="this.form.submit();" aria-label="Switch <?php echo $h($b['bike_code']); ?> on or off">
                                                    <span class="slider"></span>
                                                </label>
                                                <span class="switch-label <?php echo $b['is_on'] ? 'on' : 'off'; ?>"><?php echo $b['is_on'] ? 'On' : 'Off'; ?></span>
                                            </form>
                                        <?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Maintenance log (right of the fleet; paginated) -->
            <div class="content-card" id="maintenance-log">
                <h3><i class="fas fa-tools" style="color: #f59e0b;"></i> Maintenance Log
                    <span class="spacer"></span>
                    <?php if ($bikes): ?>
                    <form method="GET" action="ebike_maintenance.php#maintenance-log">
                        <select name="log_bike" class="inline" onchange="this.form.submit()" aria-label="Show maintenance for">
                            <option value="0">All e-bikes</option>
                            <?php foreach ($bikes as $b): ?><option value="<?php echo (int)$b['id']; ?>" <?php echo $log_bike === (int)$b['id'] ? 'selected' : ''; ?>><?php echo $h($b['bike_code']); ?></option><?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                </h3>
                <?php if (!$log): ?>
                    <p class="empty"><?php echo $log_bike > 0 ? 'No maintenance recorded for this e-bike yet.' : 'No maintenance recorded yet.'; ?></p>
                <?php else: ?>
                    <ul class="eb-log-list">
                    <?php foreach ($log as $m): ?>
                        <li class="eb-log-item">
                            <div class="eb-log-top">
                                <strong><?php echo $h($m['bike_code']); ?></strong>
                                <span><?php echo $fmt_date($m['serviced_on']); ?></span>
                                <span class="pill <?php echo $h($m['status']); ?>"><?php echo $m['status'] === 'in_progress' ? 'In Progress' : 'Completed'; ?></span>
                            </div>
                            <div class="eb-log-type"><?php echo $h($m['maintenance_type']); ?></div>
                            <?php if ($m['description']): ?><div class="eb-log-desc" title="<?php echo $h($m['description']); ?>"><?php echo $h($m['description']); ?></div><?php endif; ?>
                            <div class="eb-log-meta">
                                <span>&#8369;<?php echo number_format((float)$m['cost'], 2); ?></span>
                                <?php if ($m['performed_by']): ?><span><i class="fas fa-user-gear"></i> <?php echo $h($m['performed_by']); ?></span><?php endif; ?>
                                <?php if ($m['next_due_date']): ?><span><i class="fas fa-calendar"></i> Next <?php echo $fmt_date($m['next_due_date']); ?></span><?php endif; ?>
                                <?php if ($m['status'] === 'in_progress'): ?>
                                    <form method="POST" onsubmit="return confirm('Mark this maintenance as completed? The e-bike becomes available again.');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="complete_maintenance">
                                        <input type="hidden" name="maintenance_id" value="<?php echo (int)$m['id']; ?>">
                                        <button class="btn sm primary">Mark Completed</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($log_total > 0): ?>
                <div class="eb-pager">
                    <span><?php echo number_format($log_pg['from']) . '&ndash;' . number_format($log_pg['to']) . ' of ' . number_format($log_total); ?></span>
                    <?php echo ui_pagination($log_pg['page'], $log_pg['pages'], static fn(int $p): string => ui_page_url(['lpage' => $p], 'maintenance-log'), 'Maintenance log pages'); ?>
                </div>
                <?php endif; ?>
            </div>
            </div>
            </section>

            <!-- Usage history: who used which e-bike, recorded at order pickup -->
            <section class="eb-tab" id="tab-usage" hidden>
            <div class="content-card" id="usage-log">
                <h3><i class="fas fa-route" style="color: var(--primary-color);"></i> E-Bike Usage
                    <span class="bike-meta" style="margin:0;">Recorded when a rider confirms pickup of an order</span>
                </h3>
                <?php if (!$usage): ?>
                    <p class="empty">No e-bike trips recorded yet. When a rider confirms an order pickup in the Rider Portal, they choose the e-bike they take and it shows up here.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="custom-table">
                            <thead><tr><th>E-Bike</th><th>Rider</th><th>Order</th><th>Picked Up</th><th>Returned / Delivered</th></tr></thead>
                            <tbody>
                            <?php foreach ($usage as $u):
                                $on_road = !$u['returned_at'] && in_array($u['order_status'], ['to_receive', 'out_for_delivery'], true);
                            ?>
                                <tr>
                                    <td><strong><?php echo $h($u['bike_code']); ?></strong></td>
                                    <td><?php echo $h($u['rider_name'] ?: 'Rider'); ?></td>
                                    <td><a href="order_details.php?id=<?php echo (int)$u['order_id']; ?>" style="color:var(--link);font-weight:600;text-decoration:none;">#<?php echo $h($u['order_number'] ?: $u['order_id']); ?></a></td>
                                    <td style="white-space:nowrap;"><?php echo $fmt_time($u['picked_up_at']); ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php if ($on_road): ?><span class="pill in_use"><i class="fas fa-truck-fast"></i> On the road</span>
                                        <?php elseif ($u['returned_at']): ?><?php echo $fmt_time($u['returned_at']); ?>
                                        <?php else: ?><span class="cell-muted">Trip ended (order <?php echo $h(str_replace('_', ' ', (string)$u['order_status'])); ?>)</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="eb-pager">
                        <span><?php echo number_format($use_pg['from']) . '&ndash;' . number_format($use_pg['to']) . ' of ' . number_format($use_total); ?> trips</span>
                        <?php echo ui_pagination($use_pg['page'], $use_pg['pages'], static fn(int $p): string => ui_page_url(['upage' => $p], 'usage-log'), 'E-bike usage pages'); ?>
                    </div>
                <?php endif; ?>
            </div>
            </section>
        </main>
    </div>

    <!-- Add e-bike -->
    <div id="bike-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('bike-modal')">&times;</span>
            <h2>Add E-Bike</h2>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_bike">
                <div class="field"><label>Name / Code *</label><input type="text" name="bike_code" maxlength="50" placeholder="e.g. EB-01" required></div>
                <div class="two">
                    <div class="field"><label>Model</label><input type="text" name="model" maxlength="100"></div>
                    <div class="field"><label>Plate / Serial No.</label><input type="text" name="plate_number" maxlength="20"></div>
                </div>
                <div class="field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('bike-modal')">Cancel</button>
                    <button class="btn primary">Add E-Bike</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Log maintenance -->
    <div id="maint-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal('maint-modal')">&times;</span>
            <h2>Log Maintenance</h2>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="log_maintenance">
                <div class="field"><label>E-Bike *</label>
                    <select name="ebike_id" id="m-bike" required>
                        <?php foreach ($bikes as $b): ?><option value="<?php echo (int)$b['id']; ?>"><?php echo $h($b['bike_code']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Maintenance Type *</label>
                    <input type="text" name="maintenance_type" list="types" maxlength="100" required placeholder="e.g. Battery check">
                    <datalist id="types">
                        <option value="General inspection"><option value="Battery check / replacement"><option value="Tire repair / replacement">
                        <option value="Brake adjustment"><option value="Chain lubrication"><option value="Motor / controller repair"><option value="Lights &amp; electrical">
                    </datalist>
                </div>
                <div class="field"><label>Details</label><textarea name="description" rows="2"></textarea></div>
                <div class="two">
                    <div class="field"><label>Service Date</label><input type="date" name="serviced_on" value="<?php echo $today; ?>"></div>
                    <div class="field"><label>Next Service Due</label><input type="date" name="next_due_date"></div>
                </div>
                <div class="two">
                    <div class="field"><label>Cost (&#8369;)</label><input type="number" name="cost" min="0" step="0.01" value="0"></div>
                    <div class="field"><label>Done By / Shop</label><input type="text" name="performed_by" maxlength="100"></div>
                </div>
                <div class="field"><label>Status</label>
                    <select name="status">
                        <option value="completed">Completed</option>
                        <option value="in_progress">In progress (e-bike becomes Under Maintenance)</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('maint-modal')">Cancel</button>
                    <button class="btn primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openModal(id) { document.getElementById(id).style.display = 'block'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function openMaintenance(bikeId) {
        document.getElementById('m-bike').value = bikeId;
        openModal('maint-modal');
    }
    window.addEventListener('click', e => { if (e.target.classList && e.target.classList.contains('modal')) e.target.style.display = 'none'; });

    // Tabs: the URL hash picks one (#riders, #usage-log / page links, #maintenance-log), else the last one used.
    (function () {
        var btns = document.querySelectorAll('.eb-tabs [data-tab]');
        function show(tab, remember) {
            btns.forEach(function (b) { var on = b.dataset.tab === tab; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
            document.querySelectorAll('.eb-tab').forEach(function (s) { s.hidden = s.id !== 'tab-' + tab; });
            if (remember) { try { localStorage.setItem('ebikeTab', tab); } catch (e) {} }
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab, true); history.replaceState(null, '', location.pathname + location.search); }); });
        var h = location.hash.replace('#', ''), map = { riders: 'riders', 'usage-log': 'usage', 'maintenance-log': 'fleet', fleet: 'fleet', usage: 'usage' };
        var start = map[h];
        if (!start && /[?&]upage=/.test(location.search)) start = 'usage';
        if (!start && /[?&]lpage=/.test(location.search)) start = 'fleet';
        if (!start) { try { start = localStorage.getItem('ebikeTab'); } catch (e) {} }
        show(['fleet', 'riders', 'usage'].indexOf(start) !== -1 ? start : 'fleet', false);
    })();

    // Riders panel: refresh every 20 s while the tab is open (a rider going on break shows up by itself).
    (function () {
        var box = document.getElementById('riders');
        if (!box || !window.fetch) return;
        setInterval(function () {
            if (document.hidden) return;
            fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (html) {
                    var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('riders');
                    if (fresh && fresh.innerHTML !== box.innerHTML) box.innerHTML = fresh.innerHTML;
                }).catch(function () {});
        }, 20000);
    })();
    </script>
</body>
</html>
