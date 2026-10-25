<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/ebike_helper.php';

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
$fleet_message = ebike_fleet_message($fleet);
$delivery_ok = $fleet['available'] > 0;

$log = $conn->query(
    "SELECT m.*, b.bike_code FROM tbl_ebike_maintenance m
     JOIN tbl_ebikes b ON b.id = m.ebike_id
     ORDER BY (m.status = 'in_progress') DESC, m.serviced_on DESC, m.id DESC LIMIT 100"
)->fetch_all(MYSQLI_ASSOC);

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fmt_date = static fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Bike Maintenance</title>
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
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:24px; }
        .stat { background:var(--surface); border:1px solid var(--border-color); border-radius:12px; padding:14px 16px; }
        .stat span { font-size:.78rem; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted); font-weight:600; }
        .stat strong { display:block; font-size:1.6rem; margin-top:4px; color:var(--text-dark); }
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
        @media (max-width:600px) { .two { grid-template-columns:1fr; } }
    </style>
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
                <div class="stat"><span>In use</span><strong style="color:#1d4ed8;"><?php echo $counts['in_use']; ?></strong></div>
                <div class="stat"><span>Under maintenance</span><strong style="color:#b45309;"><?php echo $counts['maintenance']; ?></strong></div>
                <div class="stat"><span>Switched off</span><strong style="color:#64748b;"><?php echo $counts['off']; ?></strong></div>
                <div class="stat"><span>Service due / overdue</span><strong style="color:#b91c1c;"><?php echo $counts['due']; ?></strong></div>
            </div>

            <!-- Fleet -->
            <div class="content-card">
                <h3><i class="fas fa-motorcycle" style="color: var(--primary-color);"></i> Fleet &amp; Availability
                    <span class="spacer"></span>
                    <button class="btn primary" onclick="openModal('bike-modal')"><i class="fas fa-plus"></i> Add E-Bike</button>
                </h3>
                <?php if (!$bikes): ?>
                    <p class="empty">No e-bikes added yet. Click <strong>Add E-Bike</strong> to register your first one.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="custom-table">
                            <thead><tr>
                                <th>E-Bike</th><th>Model</th><th>Plate</th><th>Status</th><th>Last Service</th><th>Next Service Due</th><th>Actions</th>
                                <th title="Switch a single e-bike off (for example when its battery is low) without affecting the other e-bikes">On / Off</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($bikes as $b): ?>
                                <tr class="<?php echo $b['is_on'] ? '' : 'bike-off'; ?>">
                                    <td>
                                        <strong><?php echo $h($b['bike_code']); ?></strong>
                                        <?php if (!$b['is_on'] && $b['status'] !== 'retired'): ?><div><span class="pill retired" style="margin-top:4px;"><i class="fas fa-power-off"></i> Switched off</span></div><?php endif; ?>
                                    </td>
                                    <td><?php echo $b['model'] ? $h($b['model']) : '—'; ?></td>
                                    <td><?php echo $b['plate_number'] ? $h($b['plate_number']) : '—'; ?></td>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="ebike_id" value="<?php echo (int)$b['id']; ?>">
                                            <select name="status" class="inline" onchange="this.form.submit()">
                                                <?php foreach ($STATUS_LABELS as $val => $lbl): ?>
                                                    <option value="<?php echo $val; ?>" <?php echo $b['status'] === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?php echo $fmt_date($b['last_service']); ?></td>
                                    <td>
                                        <?php echo $fmt_date($b['next_due']); ?>
                                        <?php if ($b['due_state'] === 'overdue'): ?><span class="pill overdue">Overdue</span>
                                        <?php elseif ($b['due_state'] === 'soon'): ?><span class="pill soon">Due soon</span><?php endif; ?>
                                    </td>
                                    <td><button class="btn sm" onclick="openMaintenance(<?php echo (int)$b['id']; ?>)"><i class="fas fa-wrench"></i> Log Maintenance</button></td>
                                    <td>
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
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Maintenance log -->
            <div class="content-card">
                <h3><i class="fas fa-tools" style="color: #f59e0b;"></i> Maintenance Log
                    <span class="spacer"></span>
                    <?php if ($bikes): ?>
                    <select id="log-filter" class="inline" onchange="filterLog()">
                        <option value="">All e-bikes</option>
                        <?php foreach ($bikes as $b): ?><option value="<?php echo $h($b['bike_code']); ?>"><?php echo $h($b['bike_code']); ?></option><?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </h3>
                <?php if (!$log): ?>
                    <p class="empty">No maintenance recorded yet.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="custom-table" id="log-table">
                            <thead><tr>
                                <th>Date</th><th>E-Bike</th><th>Type</th><th>Details</th><th>Done By</th><th class="num">Cost</th><th>Next Due</th><th>Status</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($log as $m): ?>
                                <tr data-bike="<?php echo $h($m['bike_code']); ?>">
                                    <td style="white-space:nowrap;"><?php echo $fmt_date($m['serviced_on']); ?></td>
                                    <td><strong><?php echo $h($m['bike_code']); ?></strong></td>
                                    <td><?php echo $h($m['maintenance_type']); ?></td>
                                    <td><?php echo $m['description'] ? $h($m['description']) : '—'; ?></td>
                                    <td><?php echo $m['performed_by'] ? $h($m['performed_by']) : '—'; ?></td>
                                    <td class="num">&#8369;<?php echo number_format((float)$m['cost'], 2); ?></td>
                                    <td><?php echo $fmt_date($m['next_due_date']); ?></td>
                                    <td><span class="pill <?php echo $h($m['status']); ?>"><?php echo $m['status'] === 'in_progress' ? 'In Progress' : 'Completed'; ?></span></td>
                                    <td>
                                        <?php if ($m['status'] === 'in_progress'): ?>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Mark this maintenance as completed? The e-bike becomes available again.');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="complete_maintenance">
                                                <input type="hidden" name="maintenance_id" value="<?php echo (int)$m['id']; ?>">
                                                <button class="btn sm primary">Mark Completed</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
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
    function filterLog() {
        const v = document.getElementById('log-filter').value;
        document.querySelectorAll('#log-table tbody tr').forEach(tr => {
            tr.style.display = (!v || tr.dataset.bike === v) ? '' : 'none';
        });
    }
    window.addEventListener('click', e => { if (e.target.classList && e.target.classList.contains('modal')) e.target.style.display = 'none'; });
    </script>
</body>
</html>
