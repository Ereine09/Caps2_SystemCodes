<?php
/**
 * Rider Remittances page, shared by modules/admin/remittance_management.php and
 * modules/staff/remittance_management.php (the wrappers do the auth + sidebar badge counts).
 *
 * The page is organised by what the shop has to DO, one flow per section:
 *   1. Cash at Store       - the rider brings the cash to the physical store; staff count it and
 *                            click "Receive Cash" (a Store remittance is created right then).
 *   2. GCash / Bank Transfer - the rider sends the money and submits the reference no. in the rider
 *                            portal/app; staff check the shop's GCash/bank and click "Confirm".
 *   3. Discrepancies       - received, but the amount did not match; resolve or reject.
 * Once a remittance is verified its orders are settled and count in Total Sales (sales_helper.php).
 *
 * Expects from the wrapper: $conn, $payload, $user_id, $user_role, $remit_sidebar (file to include),
 * $remit_api (URL of modules/admin/remittance_api.php), $remit_title, plus whatever the sidebar needs.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

// This partial has no auth of its own by design - it must only be reached through one of the
// two wrappers above, which already verified the session and set every variable it expects.
// Requesting this file's path directly (bypassing the wrapper) used to render the page with
// undefined $remit_sidebar/$payload/etc.; send it to the right place instead.
if (!isset($conn, $payload, $user_id, $user_role, $remit_sidebar, $remit_api)) {
    $token = getJWTFromCookie();
    $direct_payload = $token ? verifyJWT($token) : null;
    $direct_role = strtolower(trim((string) ($direct_payload['role'] ?? '')));
    if ($direct_role === 'admin') {
        header('Location: ' . BASE_URL . '/modules/admin/remittance_management.php');
    } elseif ($direct_role === 'staff') {
        header('Location: ' . BASE_URL . '/modules/staff/remittance_management.php');
    } else {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
    }
    exit();
}

require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
ensure_remittance_schema($conn);

$pick_name = static fn($full, $user) => trim((string) $full) !== '' ? trim((string) $full) : (string) $user;

// ---- Every remittance record, newest first -------------------------------------------------
$records = [];
$res = $conn->query("
    SELECT rr.id, rr.remittance_type, rr.transaction_id, rr.reference_number, rr.status,
           rr.amount, rr.expected_amount, rr.cash_amount, rr.gcash_amount, rr.bank_amount,
           rr.proof_image_path, rr.rider_notes, rr.discrepancy_reason,
           rr.requested_at, rr.received_at, rr.processed_at,
           (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = rr.id) AS order_count,
           (SELECT COALESCE(SUM(o.total),0) FROM tbl_rider_remittance_items i2
              JOIN tbl_orders o ON o.id = i2.order_id WHERE i2.remittance_id = rr.id) AS cod_total,
           TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_full, u.username AS rider_user,
           TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS recv_full, rb.username AS recv_user,
           (SELECT p.status FROM tbl_payments p WHERE p.purpose = 'remittance' AND p.remittance_id = rr.id ORDER BY p.id DESC LIMIT 1) AS online_status
    FROM tbl_rider_remittances rr
    JOIN users u ON u.id = rr.rider_id
    LEFT JOIN users rb ON rb.id = COALESCE(rr.received_by_user_id, rr.processed_by_user_id)
    ORDER BY rr.id DESC
");
while ($res && ($row = $res->fetch_assoc())) {
    $type = remittance_normalize_type($row['remittance_type']);
    $expected = $row['expected_amount'] !== null ? (float) $row['expected_amount'] : (float) $row['cod_total'];
    $records[] = [
        'id'          => (int) $row['id'],
        'txn'         => (string) ($row['transaction_id'] ?: remittance_number((int) $row['id'])),
        'type'        => $type,
        'via'         => remittance_type_label($type),
        'status'      => (string) $row['status'],
        'rider'       => $pick_name($row['rider_full'], $row['rider_user']),
        'rider_user'  => (string) $row['rider_user'],
        'orders'      => (int) $row['order_count'],
        'expected'    => $expected,
        'amount'      => (float) $row['amount'],
        'difference'  => round((float) $row['amount'] - $expected, 2),
        'reference'   => (string) ($row['reference_number'] ?? ''),
        'has_proof'   => !empty($row['proof_image_path']),
        'rider_notes' => (string) ($row['rider_notes'] ?? ''),
        'reason'      => trim((string) strtok((string) ($row['discrepancy_reason'] ?? ''), "\n")),
        'submitted'   => (string) $row['requested_at'],
        'done_at'     => (string) ($row['received_at'] ?: $row['processed_at'] ?: $row['requested_at']),
        'received_by' => in_array($row['status'], ['pending', 'rejected'], true) ? ''
            : ($row['online_status'] !== null && $row['recv_user'] === null ? 'PayMongo' : $pick_name($row['recv_full'], $row['recv_user'])),
        'online'      => $row['online_status'] !== null, // paid through PayMongo: verified by the provider, not by hand
    ];
}

// ---- Riders holding completed COD cash that is not in any remittance yet ----------------------
$cash_riders = [];
$rider_res = $conn->query("
    SELECT r.id AS rider_id, u.username,
           TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_full
    FROM riders r JOIN users u ON r.user_id = u.id
    WHERE EXISTS (SELECT 1 FROM tbl_orders o WHERE o.rider_id = r.id AND o.payment_method = 'cod'
                  AND o.order_status = 'completed' AND o.payment_settled = 0)
    ORDER BY rider_full
");
while ($rider_res && ($rider = $rider_res->fetch_assoc())) {
    $orders = remittance_eligible_orders($conn, (int) $rider['rider_id']);
    $total = remittance_orders_total($orders);
    if ($total <= 0) {
        continue;
    }
    $cash_riders[] = [
        'rider_id' => (int) $rider['rider_id'],
        'rider'    => $pick_name($rider['rider_full'], $rider['username']),
        'username' => (string) $rider['username'],
        'orders'   => count($orders),
        'expected' => $total,
        'oldest'   => min(array_column($orders, 'created_at')),
    ];
}

// ---- Buckets ----------------------------------------------------------------------------------
$cash_pending = [];   // older Store remittances a rider announced in the app (before cash-only-at-store)
$online_pending = []; // GCash / Bank transfers waiting to be checked
$discrepancies = [];
$history = [];        // verified + rejected
foreach ($records as $r) {
    if ($r['status'] === 'pending') {
        if ($r['type'] === 'store') { $cash_pending[] = $r; } else { $online_pending[] = $r; }
    } elseif ($r['status'] === 'discrepancy') {
        $discrepancies[] = $r;
    } else {
        $history[] = $r;
    }
}

$kpi_cash = array_sum(array_column($cash_riders, 'expected')) + array_sum(array_column($cash_pending, 'expected'));
$kpi_online = array_sum(array_column($online_pending, 'amount'));
$kpi_today = 0.0;
$today = date('Y-m-d');
foreach ($history as $r) {
    if ($r['status'] === 'approved' && substr($r['done_at'], 0, 10) === $today) {
        $kpi_today += $r['amount'];
    }
}
$todo_count = count($cash_riders) + count($cash_pending) + count($online_pending) + count($discrepancies);

// Who can receive / check a remittance ("Received by" picker) and the names used in the History filter.
$receivers = [];
$rcv_res = $conn->query("SELECT id, role, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username FROM users WHERE role IN ('admin','staff') ORDER BY role = 'admin' DESC, first_name, username");
while ($rcv_res && ($ru = $rcv_res->fetch_assoc())) {
    $receivers[] = ['id' => (int) $ru['id'], 'role' => $ru['role'], 'name' => $pick_name($ru['full_name'], $ru['username'])];
}
$history_receivers = array_values(array_unique(array_filter(array_column($history, 'received_by'), static fn($n) => $n !== '')));
sort($history_receivers, SORT_NATURAL | SORT_FLAG_CASE);

$history_per_page = 10;
$history_page = max(1, (int) ($_GET['history_page'] ?? 1));
$history_total_pages = max(1, (int) ceil(count($history) / $history_per_page));
$history_page = min($history_page, $history_total_pages);
$history_page_rows = array_slice($history, ($history_page - 1) * $history_per_page, $history_per_page);

// ---- Activity log: every step of each remittance, built from the records themselves -------------
$remit_log = [];
try {
    $log_res = $conn->query("
        SELECT rr.id, rr.remittance_type, rr.transaction_id, rr.status, rr.amount, rr.expected_amount, rr.declared_amount,
               rr.notes, rr.discrepancy_reason, rr.requested_at, rr.received_at, rr.processed_at,
               (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = rr.id) AS order_count,
               (SELECT COALESCE(SUM(o.total),0) FROM tbl_rider_remittance_items i2 JOIN tbl_orders o ON o.id = i2.order_id WHERE i2.remittance_id = rr.id) AS cod_total,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_full, u.username AS rider_user,
               TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS recv_full, rb.username AS recv_user,
               TRIM(CONCAT(COALESCE(pb.first_name,''),' ',COALESCE(pb.last_name,''))) AS proc_full, pb.username AS proc_user
        FROM tbl_rider_remittances rr
        JOIN users u ON u.id = rr.rider_id
        LEFT JOIN users rb ON rb.id = rr.received_by_user_id
        LEFT JOIN users pb ON pb.id = rr.processed_by_user_id
        ORDER BY rr.id DESC
        LIMIT 200
    ");
    while ($log_res && ($lr = $log_res->fetch_assoc())) {
        $type = remittance_normalize_type($lr['remittance_type']);
        $online = $type !== 'store';
        $rider = $pick_name($lr['rider_full'], $lr['rider_user']);
        $proc = $pick_name($lr['proc_full'], $lr['proc_user']);
        $recv = $pick_name($lr['recv_full'], $lr['recv_user']);
        if ($recv === '') { $recv = $proc; }                       // older records only stored who processed it
        $expected = $lr['expected_amount'] !== null ? (float) $lr['expected_amount'] : (float) $lr['cod_total'];
        $base = ['id' => (int) $lr['id'], 'txn' => (string) ($lr['transaction_id'] ?: remittance_number((int) $lr['id'])), 'rider' => $rider, 'via' => remittance_type_label($type)];
        $n = (int) $lr['order_count'] . ' order' . ((int) $lr['order_count'] === 1 ? '' : 's');

        // A cash remittance recorded by staff at the counter is created and received in the same moment:
        // there is no separate "rider submitted" step to show for it.
        $recorded_at_counter = !$online && !empty($lr['received_at'])
            && abs(strtotime($lr['received_at']) - strtotime($lr['requested_at'])) <= 5;
        if (!empty($lr['requested_at']) && !$recorded_at_counter) {
            $declared = $lr['declared_amount'] !== null ? (float) $lr['declared_amount'] : (float) $lr['amount'];
            $remit_log[] = $base + ['time' => $lr['requested_at'], 'type' => 'submitted', 'by' => $rider, 'recorded_by' => '',
                'amount' => $declared, 'detail' => $n . ($online ? ' - rider sent it by ' . remittance_type_label($type) : ' - rider said they would bring the cash')];
        }
        if (in_array($lr['status'], ['approved', 'discrepancy'], true)) {
            $t = $lr['received_at'] ?: $lr['processed_at'];
            $resolved = $lr['status'] === 'approved' && strpos((string) $lr['discrepancy_reason'], 'Resolution:') !== false;
            $detail = ($online ? 'PHP ' . number_format((float) $lr['amount'], 2) . ' arrived' : 'PHP ' . number_format((float) $lr['amount'], 2) . ' cash received')
                . ' against PHP ' . number_format($expected, 2) . ' due (' . $n . ')';
            if ($lr['status'] === 'discrepancy' || $resolved) {
                $reason = trim((string) strtok((string) $lr['discrepancy_reason'], "\n"));
                $detail .= $reason !== '' ? '. Discrepancy: ' . $reason : '';
            }
            $remit_log[] = $base + ['time' => $t, 'type' => ($lr['status'] === 'discrepancy' || $resolved) ? 'received_discrepancy' : 'received_verified',
                'by' => $recv, 'recorded_by' => ($proc !== '' && $proc !== $recv) ? $proc : '', 'amount' => (float) $lr['amount'], 'detail' => $detail];
            if ($resolved && !empty($lr['processed_at']) && $lr['processed_at'] !== $t) {
                $res_note = trim((string) substr((string) $lr['discrepancy_reason'], strrpos((string) $lr['discrepancy_reason'], 'Resolution:') + 11));
                $remit_log[] = $base + ['time' => $lr['processed_at'], 'type' => 'resolved', 'by' => $proc, 'recorded_by' => '',
                    'amount' => (float) $lr['amount'], 'detail' => $res_note !== '' ? $res_note : 'Discrepancy resolved and verified'];
            }
        } elseif ($lr['status'] === 'rejected') {
            $remit_log[] = $base + ['time' => $lr['processed_at'] ?: $lr['requested_at'], 'type' => 'rejected', 'by' => $proc, 'recorded_by' => '',
                'amount' => (float) $lr['amount'], 'detail' => trim((string) $lr['notes']) !== '' ? trim((string) $lr['notes']) : 'Rejected'];
        }
    }
    usort($remit_log, static fn($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);
    $remit_log = array_slice($remit_log, 0, 120);
} catch (Throwable $e) {
    error_log('Remittance log failed: ' . $e->getMessage());
}

$log_per_page = 15;
$log_page = max(1, (int) ($_GET['log_page'] ?? 1));
$log_total_pages = max(1, (int) ceil(count($remit_log) / $log_per_page));
$log_page = min($log_page, $log_total_pages);
$log_page_rows = array_slice($remit_log, ($log_page - 1) * $log_per_page, $log_per_page);

$remit_log_types = [
    'submitted'            => ['Submitted by rider',     'badge-pending',     'fa-paper-plane'],
    'received_verified'    => ['Received & verified',    'badge-approved',    'fa-check-circle'],
    'received_discrepancy' => ['Received - discrepancy', 'badge-discrepancy', 'fa-exclamation-triangle'],
    'resolved'             => ['Discrepancy resolved',   'badge-approved',    'fa-check-double'],
    'rejected'             => ['Rejected',               'badge-rejected',    'fa-times-circle'],
];

$me_row = $conn->query("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS n, username FROM users WHERE id = " . (int) $user_id)->fetch_assoc() ?: [];
$current_user_name = $pick_name($me_row['n'] ?? '', $me_row['username'] ?? ($payload['username'] ?? ''));

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$peso = static fn($n) => '&#8369;' . number_format((float) $n, 2);
$short_date = static fn($d) => $d ? date('M j, Y', strtotime($d)) : '';
$via_icon = ['store' => 'fa-store', 'gcash' => 'fa-mobile-screen', 'bank' => 'fa-building-columns'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($remit_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        body { overflow-x: hidden; background-color: var(--surface-2); }
        .rm-flow { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
        .rm-flow > div { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); font-size: var(--fs-sm); color: var(--muted); }
        .rm-flow i { font-size: 1.1rem; color: var(--primary); margin-top: 2px; width: 20px; text-align: center; }
        .rm-flow strong { display: block; color: var(--text); font-size: var(--fs-base); margin-bottom: 2px; }
        .rm-section { margin-bottom: 18px; }
        .rm-section .ui-card-sub { max-width: 70ch; }
        .rm-via { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .rm-via i { color: var(--muted); }
        .rm-ref { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9em; }
        .rm-off { color: var(--danger); font-weight: 600; }
        .rm-ok { color: var(--success); font-weight: 600; }
        .rm-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 12px 20px; border-bottom: 1px solid var(--border); }
        .rm-filters select { padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; font-size: var(--fs-sm); }
        .rm-filters label { display: inline-flex; align-items: center; gap: 6px; font-size: var(--fs-sm); color: var(--muted); }
        .rm-chip { padding: 6px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--muted); font: inherit; font-size: var(--fs-sm); font-weight: 600; cursor: pointer; }
.rm-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .rm-tab-count { margin-left: 6px; }
        .ui-tabs {
            display: flex;
            width: 100%;
            padding: 0 10px;
            margin: 0 0 0;
            border: 1px solid rgba(99, 102, 241, 0.16);
            border-bottom: 0;
            border-radius: 10px 10px 0 0;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(224, 231, 255, 0.55));
            gap: 0;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }
        .ui-tabs button {
            flex: 1 1 0;
            justify-content: center;
            padding: 12px 14px 11px;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            color: #475569;
            font-weight: 600;
        }
        .ui-tabs button:hover {
            color: #1e293b;
            background: rgba(148, 163, 184, 0.08);
        }
        .ui-tabs .active {
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(237,242,255,0.9));
            color: #312e81;
            border-bottom-color: #4f46e5;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
        }
        .ui-tabs .active i {
            color: #4f46e5;
        }
        .tab-panel {
            border: 1px solid rgba(99, 102, 241, 0.12);
            border-top: 0;
            border-radius: 0 0 10px 10px;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.9));
            padding: 0;
        }
        .remit-pagination-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
            padding: 6px 0 2px;
            background: transparent;
        }
        .remit-pagination-label {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.2;
            color: #64748b;
            font-weight: 500;
        }
        .remit-pagination-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .remit-pagination-nav a,
        .remit-pagination-nav span {
            min-width: 36px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #ffffff;
            color: #475569;
            font-size: 0.96rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.02);
        }
        .remit-pagination-nav a:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
        }
        .remit-pagination-nav .current,
        .remit-pagination-nav .active {
            background: linear-gradient(180deg, #5b6ef5 0%, #4f46e5 100%);
            border-color: #4f46e5;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.18);
        }
        .remit-pagination-nav .muted,
        .remit-pagination-nav .disabled {
            opacity: 0.55;
            cursor: default;
            pointer-events: none;
        }
        .remit-pagination-nav .dots {
            border: none;
            background: transparent;
            box-shadow: none;
            min-width: 16px;
            padding: 0;
            color: #94a3b8;
        }
        @media (max-width: 900px) { .rm-flow { grid-template-columns: 1fr; } }

        /* Activity log */
        .badge-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
        .badge-approved { background: var(--success-soft); color: var(--success); }
        .badge-rejected { background: var(--danger-soft); color: var(--danger); }
        .badge-pending { background: var(--info-soft); color: var(--info); }
        .badge-discrepancy { background: var(--warning-soft); color: var(--warning); }
        .log-scroll { max-height: 640px; overflow: auto; }
        .log-scroll thead th { position: sticky; top: 0; background: var(--surface); z-index: 1; }
        .log-time small, .log-by small { display: block; color: var(--muted); font-size: 0.78rem; }
        .log-detail { color: var(--muted); font-size: 0.85rem; max-width: 360px; }

        /* Modal */
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin: 14px 0; }
        .summary-grid dt { font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
        .summary-grid dd { margin: 2px 0 0; font-weight: 600; color: var(--text); overflow-wrap: anywhere; }
        .field { margin-top: 12px; }
        .field label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
        .field input, .field textarea, .field select {
            width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 0.95rem; font-family: inherit; background: var(--surface); color: var(--text);
        }
        .field .hint { display: block; margin-top: 4px; color: var(--muted); font-size: 0.78rem; }
        .diff-box { display: flex; justify-content: space-between; align-items: center; background: var(--surface-2);
            border-radius: 8px; padding: 12px 14px; margin: 12px 0; font-weight: 600; }
        .diff-box.bad { background: var(--warning-soft); }
        .diff-zero { color: var(--success); }
        .diff-neg { color: var(--danger); }
        .callout { border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; margin: 10px 0; }
        .callout.warn { background: var(--warning-soft); color: var(--text); }
        .callout.info { background: var(--info-soft); color: var(--text); }
        .order-mini { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 6px; }
        .order-mini th, .order-mini td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; }
        .order-mini td:last-child, .order-mini th:last-child { text-align: right; }
        .modal { display: none; position: fixed; z-index: 1001; inset: 0; overflow: auto; background-color: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); }
        .modal-content { background-color: var(--surface); color: var(--text); margin: 5% auto; padding: 26px; width: 92%; max-width: 620px; border-radius: 16px; box-shadow: var(--shadow-lg, 0 20px 25px -5px rgba(0,0,0,.15)); }
        .close-button { color: var(--muted); float: right; font-size: 24px; font-weight: bold; cursor: pointer; background: none; border: 0; line-height: 1; }
        .close-button:hover { color: var(--text); }
        .modal-footer { margin-top: 22px; display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
        @media (max-width: 600px) { .summary-grid { grid-template-columns: 1fr; } .modal-content { padding: 20px; margin: 16px auto; } }
    </style>
</head>
<body>
    <?php include $remit_sidebar; ?>

    <div class="main-content">
        <div class="ui-page-head">
            <div>
                <h1 class="ui-page-title"><i class="fas fa-hand-holding-usd"></i> Rider Remittances</h1>
                <p class="ui-page-sub">Collect the COD money riders owe the shop. Verified remittances are added to Total Sales.</p>
            </div>
        </div>

        <!-- How it works: the three ways money comes in -->
        <div class="rm-flow">
            <div><i class="fas fa-store"></i><span><strong>1. Cash at Store</strong>The rider brings the cash to the store. Count it, then click <em>Receive Cash</em>.</span></div>
            <div><i class="fas fa-mobile-screen"></i><span><strong>2. GCash / Bank</strong>Paid through PayMongo: verified automatically. Sent manually: the rider gives the real reference no.; find it in the shop's account, then click <em>Confirm</em>.</span></div>
            <div><i class="fas fa-chart-line"></i><span><strong>3. Added to sales</strong>Once confirmed, the rider's orders are settled and counted in Total Sales for that day.</span></div>
        </div>

        <div class="ui-kpis" id="remit-kpis" style="margin-bottom: 18px;">
            <div class="ui-kpi tone-warning">
                <span class="ui-kpi-icon"><i class="fas fa-store"></i></span>
                <div class="ui-kpi-body"><span class="ui-kpi-label">Cash still with riders</span><span class="ui-kpi-value"><?php echo $peso($kpi_cash); ?></span>
                    <span class="ui-kpi-meta"><?php echo count($cash_riders) + count($cash_pending); ?> to receive at the store</span></div>
            </div>
            <div class="ui-kpi tone-info">
                <span class="ui-kpi-icon"><i class="fas fa-mobile-screen"></i></span>
                <div class="ui-kpi-body"><span class="ui-kpi-label">Transfers to check</span><span class="ui-kpi-value"><?php echo $peso($kpi_online); ?></span>
                    <span class="ui-kpi-meta"><?php echo count($online_pending); ?> GCash / bank</span></div>
            </div>
            <div class="ui-kpi tone-danger">
                <span class="ui-kpi-icon"><i class="fas fa-exclamation-triangle"></i></span>
                <div class="ui-kpi-body"><span class="ui-kpi-label">Discrepancies</span><span class="ui-kpi-value"><?php echo count($discrepancies); ?></span>
                    <span class="ui-kpi-meta">amount did not match</span></div>
            </div>
            <div class="ui-kpi tone-success">
                <span class="ui-kpi-icon"><i class="fas fa-circle-check"></i></span>
                <div class="ui-kpi-body"><span class="ui-kpi-label">Collected today</span><span class="ui-kpi-value"><?php echo $peso($kpi_today); ?></span>
                    <span class="ui-kpi-meta">added to today's sales</span></div>
            </div>
        </div>

        <div class="ui-tabs" role="tablist">
            <button type="button" role="tab" data-tab="todo" aria-controls="panel-todo"><i class="fas fa-list-check"></i> To Do<span class="ui-count rm-tab-count" data-count="todo"<?php echo $todo_count ? '' : ' hidden'; ?>><?php echo $todo_count; ?></span></button>
            <button type="button" role="tab" data-tab="history" aria-controls="panel-history"><i class="fas fa-clock-rotate-left"></i> History</button>
            <button type="button" role="tab" data-tab="log" aria-controls="panel-log"><i class="fas fa-clipboard-list"></i> Activity Log</button>
        </div>

        <main>
            <!-- ================= TO DO ================= -->
            <div class="tab-panel" id="panel-todo" role="tabpanel">
                <div id="remit-todo">
                <?php if ($todo_count === 0): ?>
                    <div class="ui-card"><div class="ui-empty"><i class="fas fa-circle-check"></i><strong>All caught up</strong><p>No rider owes the shop money right now.</p></div></div>
                <?php endif; ?>

                <?php if ($cash_riders || $cash_pending): ?>
                <section class="ui-card rm-section">
                    <div class="ui-card-head"><div>
                        <h3 class="ui-card-title"><i class="fas fa-store"></i> Cash to receive at the store</h3>
                        <p class="ui-card-sub">Riders holding COD cash. When the rider is at the counter, count the money and click <strong>Receive Cash</strong>.</p>
                    </div></div>
                    <div class="ui-table-wrap"><table class="ui-table ui-table-stack">
                        <thead><tr><th>Rider</th><th>Orders</th><th>Oldest order</th><th class="num">Amount due</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($cash_riders as $c): ?>
                            <tr>
                                <td data-label="Rider"><span><span class="ui-cell-main"><?php echo $h($c['rider']); ?></span><span class="ui-cell-sub">@<?php echo $h($c['username']); ?></span></span></td>
                                <td data-label="Orders"><?php echo $c['orders'] . ' ' . ($c['orders'] === 1 ? 'order' : 'orders'); ?></td>
                                <td data-label="Oldest order"><?php echo $h($short_date($c['oldest'])); ?></td>
                                <td data-label="Amount due" class="num ui-strong"><?php echo $peso($c['expected']); ?></td>
                                <td class="actions"><button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="openRemittance(null, null, <?php echo $c['rider_id']; ?>)"><i class="fas fa-hand-holding-dollar"></i> Receive Cash</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($cash_pending as $r): ?>
                            <tr>
                                <td data-label="Rider"><span><span class="ui-cell-main"><?php echo $h($r['rider']); ?></span><span class="ui-cell-sub"><?php echo $h($r['txn']); ?> &middot; announced <?php echo $h($short_date($r['submitted'])); ?></span></span></td>
                                <td data-label="Orders"><?php echo $r['orders'] . ' ' . ($r['orders'] === 1 ? 'order' : 'orders'); ?></td>
                                <td data-label="Oldest order">&mdash;</td>
                                <td data-label="Amount due" class="num ui-strong"><?php echo $peso($r['expected']); ?></td>
                                <td class="actions">
                                    <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, null)"><i class="fas fa-hand-holding-dollar"></i> Receive Cash</button>
                                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, 'reject')">Reject</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </section>
                <?php endif; ?>

                <?php if ($online_pending): ?>
                <section class="ui-card rm-section">
                    <div class="ui-card-head"><div>
                        <h3 class="ui-card-title"><i class="fas fa-mobile-screen"></i> GCash / Bank transfers to check</h3>
                        <p class="ui-card-sub"><strong>PayMongo</strong> payments are verified automatically (you can ask PayMongo for the status). <strong>Manual</strong> ones: find the exact reference no. and amount in the shop's GCash or bank account, then click <strong>Confirm</strong>.</p>
                    </div></div>
                    <div class="ui-table-wrap"><table class="ui-table ui-table-stack">
                        <thead><tr><th>Sent</th><th>Rider</th><th>Via</th><th>Reference no.</th><th class="num">Amount sent</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($online_pending as $r): ?>
                            <tr>
                                <td data-label="Sent"><span><span class="ui-cell-main"><?php echo $h($short_date($r['submitted'])); ?></span><span class="ui-cell-sub"><?php echo $h(date('g:i A', strtotime($r['submitted']))); ?></span></span></td>
                                <td data-label="Rider"><span><span class="ui-cell-main"><?php echo $h($r['rider']); ?></span><span class="ui-cell-sub"><?php echo $r['orders'] . ' ' . ($r['orders'] === 1 ? 'order' : 'orders'); ?></span></span></td>
                                <td data-label="Via"><span class="rm-via"><i class="fas <?php echo $via_icon[$r['type']]; ?>"></i><?php echo $h($r['via']); ?></span>
                                    <span class="ui-cell-sub"><?php echo $r['online'] ? 'PayMongo · auto-verified' : 'Manual · check your account'; ?></span></td>
                                <td data-label="Reference no."><?php if ($r['online']): ?><span class="ui-cell-sub">Waiting for PayMongo</span><?php else: ?><span class="rm-ref"><?php echo $r['reference'] !== '' ? $h($r['reference']) : '&mdash;'; ?></span><?php echo $r['has_proof'] ? ' <i class="fas fa-receipt" title="Receipt photo attached" style="color: var(--muted);"></i>' : ''; ?><?php endif; ?></td>
                                <td data-label="Amount sent" class="num"><span class="ui-strong"><?php echo $peso($r['amount']); ?></span>
                                    <?php if (abs($r['difference']) >= 0.005): ?><span class="ui-cell-sub rm-off"><?php echo ($r['difference'] < 0 ? 'Short ' : 'Over ') . $peso(abs($r['difference'])); ?></span><?php endif; ?></td>
                                <td class="actions">
                                    <?php if ($r['online']): ?>
                                    <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="checkProvider(<?php echo $r['id']; ?>, this)"><i class="fas fa-rotate"></i> Check with PayMongo</button>
                                    <?php else: ?>
                                    <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, null)"><i class="fas fa-check"></i> Confirm</button>
                                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, 'reject')">Reject</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </section>
                <?php endif; ?>

                <?php if ($discrepancies): ?>
                <section class="ui-card rm-section">
                    <div class="ui-card-head"><div>
                        <h3 class="ui-card-title"><i class="fas fa-exclamation-triangle"></i> Discrepancies to resolve</h3>
                        <p class="ui-card-sub">Received, but the amount did not match what was due. The orders are not counted in sales until you resolve it.</p>
                    </div></div>
                    <div class="ui-table-wrap"><table class="ui-table ui-table-stack">
                        <thead><tr><th>Transaction</th><th>Rider</th><th class="num">Due</th><th class="num">Received</th><th>Reason</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($discrepancies as $r): ?>
                            <tr>
                                <td data-label="Transaction"><span><span class="ui-cell-main"><?php echo $h($r['txn']); ?></span><span class="ui-cell-sub"><?php echo $h($r['via']); ?> &middot; <?php echo $h($short_date($r['done_at'])); ?></span></span></td>
                                <td data-label="Rider"><?php echo $h($r['rider']); ?></td>
                                <td data-label="Due" class="num"><?php echo $peso($r['expected']); ?></td>
                                <td data-label="Received" class="num"><span class="ui-strong"><?php echo $peso($r['amount']); ?></span><span class="ui-cell-sub rm-off"><?php echo ($r['difference'] < 0 ? 'Short ' : 'Over ') . $peso(abs($r['difference'])); ?></span></td>
                                <td data-label="Reason"><?php echo $r['reason'] !== '' ? $h($r['reason']) : '&mdash;'; ?></td>
                                <td class="actions">
                                    <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, 'resolve')">Resolve</button>
                                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, 'reject')">Reject</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </section>
                <?php endif; ?>
                </div><!-- /#remit-todo -->
            </div>

            <!-- ================= HISTORY ================= -->
            <div class="tab-panel" id="panel-history" role="tabpanel" hidden>
                <section class="ui-card">
                    <div class="ui-card-head"><div>
                        <h3 class="ui-card-title"><i class="fas fa-clock-rotate-left"></i> Remittance history</h3>
                        <p class="ui-card-sub">Every verified or rejected remittance. Verified ones are already counted in Total Sales.</p>
                    </div>
                    <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" onclick="printRemittanceReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button></div>
                    <div class="rm-filters">
                        <button type="button" class="rm-chip active" data-filter="all">All</button>
                        <button type="button" class="rm-chip" data-filter="approved">Verified</button>
                        <button type="button" class="rm-chip" data-filter="rejected">Rejected</button>
                        <label>Paid via
                            <select id="filter-via"><option value="">Any</option><option value="store">Cash at Store</option><option value="gcash">GCash</option><option value="bank">Bank Transfer</option></select>
                        </label>
                        <label>Received by
                            <select id="filter-receiver"><option value="">Anyone</option>
                                <?php foreach ($history_receivers as $rn): ?><option value="<?php echo $h($rn); ?>"><?php echo $h($rn); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div id="remit-history">
                    <?php if (!$history): ?>
                        <div class="ui-empty"><i class="fas fa-receipt"></i><strong>No remittances yet</strong><p>Verified and rejected remittances will appear here.</p></div>
                    <?php else: ?>
                        <div class="ui-table-wrap"><table class="ui-table ui-table-stack" id="history-table">
                            <thead><tr><th>Transaction ID</th><th>Date</th><th>Rider</th><th>Paid via</th><th class="num">Amount</th><th>Received by</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($history_page_rows as $r):
                                $ok = $r['status'] === 'approved'; ?>
                                <tr data-status="<?php echo $h($r['status']); ?>" data-via="<?php echo $h($r['type']); ?>" data-receiver="<?php echo $h($r['received_by']); ?>">
                                    <td data-label="Transaction ID"><span><span class="ui-cell-main rm-ref"><?php echo $h($r['txn']); ?></span><?php if ($r['reference'] !== ''): ?><span class="ui-cell-sub">Ref. <?php echo $h($r['reference']); ?></span><?php endif; ?></span></td>
                                    <td data-label="Date" class="ui-nowrap"><?php echo $h($short_date($r['done_at'])); ?></td>
                                    <td data-label="Rider"><span><span class="ui-cell-main"><?php echo $h($r['rider']); ?></span><span class="ui-cell-sub"><?php echo $r['orders'] . ' ' . ($r['orders'] === 1 ? 'order' : 'orders'); ?></span></span></td>
                                    <td data-label="Paid via"><span class="rm-via"><i class="fas <?php echo $via_icon[$r['type']]; ?>"></i><?php echo $h($r['via']); ?></span></td>
                                    <td data-label="Amount" class="num"><span class="ui-strong"><?php echo $peso($r['amount']); ?></span>
                                        <?php if ($ok && abs($r['difference']) >= 0.005): ?><span class="ui-cell-sub rm-off"><?php echo ($r['difference'] < 0 ? 'Short ' : 'Over ') . $peso(abs($r['difference'])); ?></span><?php endif; ?></td>
                                    <td data-label="Received by"><?php echo $r['received_by'] !== '' ? $h($r['received_by']) : '&mdash;'; ?></td>
                                    <td data-label="Status"><span class="ui-badge ui-badge-<?php echo $ok ? 'success' : 'danger'; ?>"><?php echo $ok ? 'Verified' : 'Rejected'; ?></span></td>
                                    <td class="actions"><button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="openRemittance(<?php echo $r['id']; ?>, 'view')">Details</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <?php if ($history_total_pages > 1): ?>
                            <div class="remit-pagination-box">
                                <p class="remit-pagination-label">Page <?php echo $history_page; ?> of <?php echo $history_total_pages; ?></p>
                                <div class="remit-pagination-nav">
                                    <?php if ($history_page > 1): ?>
                                        <a href="<?php echo $h($_SERVER['PHP_SELF'] . '?history_page=' . ($history_page - 1) . '#panel-history'); ?>">&lt; Previous</a>
                                    <?php else: ?>
                                        <span class="disabled">&lt; Previous</span>
                                    <?php endif; ?>

                                    <?php
                                    $history_window = [];
                                    for ($hp = 1; $hp <= $history_total_pages; $hp++) {
                                        if ($hp === 1 || $hp === $history_total_pages || abs($hp - $history_page) <= 1) {
                                            $history_window[] = $hp;
                                        } elseif ($history_window[count($history_window) - 1] !== '...') {
                                            $history_window[] = '...';
                                        }
                                    }
                                    foreach ($history_window as $hp):
                                        if ($hp === '...') {
                                            echo '<span class="dots">...</span>';
                                        } elseif ($hp === $history_page) {
                                            echo '<span class="active">' . $hp . '</span>';
                                        } else {
                                            echo '<a href="' . $h($_SERVER['PHP_SELF'] . '?history_page=' . $hp . '#panel-history') . '">' . $hp . '</a>';
                                        }
                                    endforeach;
                                    ?>

                                    <?php if ($history_page < $history_total_pages): ?>
                                        <a href="<?php echo $h($_SERVER['PHP_SELF'] . '?history_page=' . ($history_page + 1) . '#panel-history'); ?>">Next &gt;</a>
                                    <?php else: ?>
                                        <span class="disabled">Next &gt;</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div><!-- /#remit-history -->
                </section>
            </div>

            <!-- ================= ACTIVITY LOG ================= -->
            <div class="tab-panel" id="panel-log" role="tabpanel" hidden>
                <section class="ui-card">
                    <div class="ui-card-head"><div>
                        <h3 class="ui-card-title"><i class="fas fa-clipboard-list"></i> Activity log</h3>
                        <p class="ui-card-sub">Every step of each remittance, newest first: submitted, received or confirmed (and by whom), discrepancies, resolutions and rejections.</p>
                    </div></div>
                    <div id="remit-log-wrap">
                    <?php if (!$remit_log): ?>
                        <div class="ui-empty"><i class="fas fa-clipboard-list"></i><strong>No activity yet</strong></div>
                    <?php else: ?>
                        <div class="ui-table-wrap log-scroll"><table class="ui-table ui-table-compact">
                            <thead><tr><th>Date &amp; time</th><th>Transaction</th><th>Rider</th><th>Event</th><th>By</th><th class="num">Amount</th><th>Details</th></tr></thead>
                            <tbody>
                            <?php foreach ($log_page_rows as $ev):
                                [$ev_label, $ev_badge, $ev_icon] = $remit_log_types[$ev['type']];
                                $ev_ts = $ev['time'] ? strtotime($ev['time']) : 0; ?>
                                <tr>
                                    <td class="log-time ui-nowrap"><?php echo $ev_ts ? $h(date('M j, Y', $ev_ts)) . '<small>' . $h(date('g:i A', $ev_ts)) . '</small>' : '&mdash;'; ?></td>
                                    <td><span class="rm-ref"><?php echo $h($ev['txn']); ?></span><small style="display: block; color: var(--muted);"><?php echo $h($ev['via']); ?></small></td>
                                    <td><?php echo $h($ev['rider']); ?></td>
                                    <td><span class="badge-status <?php echo $ev_badge; ?>"><i class="fas <?php echo $ev_icon; ?>"></i> <?php echo $h($ev_label); ?></span></td>
                                    <td class="log-by"><?php echo $ev['by'] !== '' ? $h($ev['by']) : '&mdash;'; ?>
                                        <?php if ($ev['recorded_by'] !== ''): ?><small>recorded by <?php echo $h($ev['recorded_by']); ?></small><?php endif; ?></td>
                                    <td class="num"><?php echo $peso($ev['amount']); ?></td>
                                    <td class="log-detail"><?php echo $h($ev['detail']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <?php if ($log_total_pages > 1): ?>
                            <div class="remit-pagination-box">
                                <p class="remit-pagination-label">Page <?php echo $log_page; ?> of <?php echo $log_total_pages; ?></p>
                                <div class="remit-pagination-nav">
                                    <?php if ($log_page > 1): ?>
                                        <a href="<?php echo $h($_SERVER['PHP_SELF'] . '?log_page=' . ($log_page - 1) . '#panel-log'); ?>">&lt; Previous</a>
                                    <?php else: ?>
                                        <span class="disabled">&lt; Previous</span>
                                    <?php endif; ?>

                                    <?php
                                    $log_window = [];
                                    for ($lp = 1; $lp <= $log_total_pages; $lp++) {
                                        if ($lp === 1 || $lp === $log_total_pages || abs($lp - $log_page) <= 1) {
                                            $log_window[] = $lp;
                                        } elseif ($log_window[count($log_window) - 1] !== '...') {
                                            $log_window[] = '...';
                                        }
                                    }
                                    foreach ($log_window as $lp):
                                        if ($lp === '...') {
                                            echo '<span class="dots">...</span>';
                                        } elseif ($lp === $log_page) {
                                            echo '<span class="active">' . $lp . '</span>';
                                        } else {
                                            echo '<a href="' . $h($_SERVER['PHP_SELF'] . '?log_page=' . $lp . '#panel-log') . '">' . $lp . '</a>';
                                        }
                                    endforeach;
                                    ?>

                                    <?php if ($log_page < $log_total_pages): ?>
                                        <a href="<?php echo $h($_SERVER['PHP_SELF'] . '?log_page=' . ($log_page + 1) . '#panel-log'); ?>">Next &gt;</a>
                                    <?php else: ?>
                                        <span class="disabled">Next &gt;</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div><!-- /#remit-log-wrap -->
                </section>
            </div>
        </main>
    </div>

    <!-- Receive / Confirm / Details modal -->
    <div id="remittance-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-content">
            <button type="button" class="close-button" onclick="closeModal()" aria-label="Close">&times;</button>
            <h2 id="modal-title" style="margin-top: 0;">Receive Cash at the Store</h2>

            <dl class="summary-grid">
                <div><dt>Rider</dt><dd id="m-rider">&mdash;</dd></div>
                <div><dt>Paid via</dt><dd id="m-via">&mdash;</dd></div>
                <div><dt>Transaction ID</dt><dd id="m-txn">&mdash;</dd></div>
                <div><dt>Status</dt><dd id="m-status">&mdash;</dd></div>
                <div><dt>Orders</dt><dd id="m-orders-count">&mdash;</dd></div>
                <div><dt>Amount due</dt><dd id="m-expected" style="font-size: 1.15rem;">&mdash;</dd></div>
            </dl>

            <details>
                <summary style="cursor: pointer; font-size: 0.85rem; color: var(--link); font-weight: 600;">Show orders</summary>
                <table class="order-mini">
                    <thead><tr><th>Order</th><th>Customer</th><th>Total</th></tr></thead>
                    <tbody id="m-orders"></tbody>
                </table>
            </details>

            <div id="m-rider-note" class="callout info" style="display: none;"></div>

            <div id="m-receive" style="display: none;">
                <div id="m-instructions" class="callout info"></div>
                <div class="field">
                    <label for="f-amount" id="f-amount-label">Cash received</label>
                    <input type="number" id="f-amount" min="0" step="0.01" oninput="recalc()">
                </div>
                <div class="field" id="ref-field">
                    <label for="f-ref" id="f-ref-label">Reference no.</label>
                    <input type="text" id="f-ref" maxlength="100">
                    <span class="hint" id="m-proof"></span>
                </div>
                <div class="diff-box" id="diff-box"><span>Difference</span><span id="diff-value">&#8369;0.00</span></div>
                <div class="field" id="reason-field" style="display: none;">
                    <label for="f-reason">Why is the amount different? <span style="color: var(--danger);">*</span></label>
                    <textarea id="f-reason" rows="2" placeholder="e.g. &#8369;100 short, rider will pay tomorrow."></textarea>
                    <span class="hint">It will be saved as a discrepancy and not counted in sales until resolved.</span>
                </div>
                <div class="field">
                    <label for="f-received-by" id="f-received-by-label">Received by</label>
                    <select id="f-received-by">
                        <?php foreach ($receivers as $rc): ?>
                            <option value="<?php echo $rc['id']; ?>"<?php echo $rc['id'] === (int) $user_id ? ' selected' : ''; ?>><?php echo $h($rc['name']) . ' (' . ucfirst($rc['role']) . ')' . ($rc['id'] === (int) $user_id ? ' - you' : ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-notes">Notes (optional)</label>
                    <textarea id="f-notes" rows="2"></textarea>
                </div>
            </div>

            <div id="m-readonly" style="display: none;"></div>

            <div id="m-action-note" class="field" style="display: none;">
                <label id="m-action-label" for="f-action-note"></label>
                <textarea id="f-action-note" rows="3"></textarea>
            </div>

            <div class="modal-footer" id="m-footer"></div>
        </div>
    </div>

    <!-- Receipt viewer -->
    <div id="receipt-modal" class="modal" role="dialog" aria-modal="true">
        <div class="modal-content" style="max-width: 420px; text-align: center;">
            <button type="button" class="close-button" onclick="closeReceiptModal()" aria-label="Close">&times;</button>
            <h2 style="margin-top: 0; font-size: 1.1rem;"><i class="fas fa-receipt"></i> Payment receipt</h2>
            <img id="receipt-modal-img" src="" alt="Payment receipt" style="width: 100%; max-height: 60vh; object-fit: contain; border-radius: 8px; border: 1px solid var(--border); margin-top: 10px;">
            <p style="margin: 14px 0 0;"><a id="receipt-modal-open-tab" href="#" target="_blank" rel="noopener" style="font-size: 0.85rem; color: var(--muted);"><i class="fas fa-up-right-from-square"></i> Open full size</a></p>
        </div>
    </div>

    <script>
    // `current` is the last payload from remittance_api.php; `mode` is receive | resolve | reject | view.
    // The amount due is display-only: the server always recomputes it from the real orders.
    const REMIT_API = <?php echo json_encode($remit_api); ?>;
    const CURRENT_USER_ID = <?php echo (int) $user_id; ?>;
    const SHOP_NAME = <?php echo json_encode(defined('SYSTEM_NAME') ? SYSTEM_NAME : 'Shop'); ?>;
    const VIA = {
        store: {label: 'Cash at Store', icon: 'fa-store', channel: 'cash'},
        gcash: {label: 'GCash', icon: 'fa-mobile-screen', channel: 'gcash'},
        bank:  {label: 'Bank Transfer', icon: 'fa-building-columns', channel: 'bank'}
    };
    let current = null, mode = 'receive';

    const peso = n => (n < 0 ? '-' : '') + '₱' + Math.abs(Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const el = id => document.getElementById(id);
    const viaOf = d => VIA[d.remittance_type] || VIA.store;
    const fmtDate = s => s ? new Date(String(s).replace(' ', 'T')).toLocaleString('en-US', {year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}) : '—';

    // Always resolves to {success, message, ...}; network errors become {success:false, message}.
    async function api(action, params = {}, body = null) {
        const qs = new URLSearchParams({action, ...params}).toString();
        try {
            return await LiveUI.request(REMIT_API + '?' + qs, body ? {method: 'POST', body} : {method: 'GET'});
        } catch (e) {
            return e.data && typeof e.data === 'object' ? e.data : {success: false, message: e.message};
        }
    }

    // remittanceId: an existing record; riderId: receive cash from a rider (no record yet).
    async function openRemittance(remittanceId, action, riderId = null) {
        const result = remittanceId
            ? await api('get_remittance_details', {remittance_id: remittanceId})
            : await api('get_remittance_details', {rider_id: riderId});
        if (!result.success) { LiveUI.toast(result.message, 'error'); return; }
        current = result.data;
        if (!current.remittance_id) current.remittance_type = 'store';   // cash handed over at the counter
        mode = action || 'receive';
        renderModal();
        el('remittance-modal').style.display = 'block';
    }

    function proofButton(url) {
        return `<button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="openReceiptModal('${esc(url)}')"><i class="fas fa-receipt"></i> View receipt photo</button>`;
    }

    function renderModal() {
        const d = current, via = viaOf(d), online = via.channel !== 'cash';
        const receiving = mode === 'receive';
        el('modal-title').textContent = {
            receive: online ? `Confirm ${via.label} Payment` : 'Receive Cash at the Store',
            resolve: 'Resolve Discrepancy', reject: 'Reject Remittance', view: 'Remittance Details'
        }[mode];
        el('m-rider').textContent = d.rider_name;
        el('m-via').innerHTML = `<i class="fas ${via.icon}" style="color: var(--muted);"></i> ${esc(via.label)}`;
        el('m-txn').textContent = d.transaction_id || 'Assigned when you confirm';
        el('m-status').textContent = d.remittance_id ? (d.status === 'pending' ? (online ? 'Waiting for you to check' : 'Waiting for the cash') : d.status_label) : 'Rider has the cash';
        el('m-orders-count').textContent = d.orders.length + (d.orders.length === 1 ? ' order' : ' orders');
        el('m-expected').textContent = peso(d.expected);
        el('m-orders').innerHTML = d.orders.map(o => `<tr><td>#${esc(o.order_number)}</td><td>${esc(o.customer_name || '')}</td><td>${peso(o.total)}</td></tr>`).join('');

        const note = el('m-rider-note');
        note.style.display = d.rider_notes ? 'block' : 'none';
        note.innerHTML = d.rider_notes ? '<strong>Rider note:</strong> ' + esc(d.rider_notes) : '';

        el('m-receive').style.display = receiving ? 'block' : 'none';
        el('m-readonly').style.display = receiving ? 'none' : 'block';
        if (receiving) {
            const sent = d.remittance_id ? d.breakdown[via.channel] || d.breakdown.total : d.expected;
            el('f-amount').value = (Math.round(sent * 100) / 100).toFixed(2);
            el('f-amount-label').textContent = online ? `Amount that arrived in the shop's ${via.label}` : 'Cash received (count it)';
            el('m-instructions').innerHTML = online
                ? `Open the shop's <strong>${esc(via.label)}</strong> and look for reference no. <strong>${esc(d.reference_number || '—')}</strong>. The rider says they sent <strong>${peso(d.breakdown.total)}</strong>. Enter the amount that actually arrived.`
                : `Count the cash the rider hands you. <strong>${peso(d.expected)}</strong> is due for these orders.`;
            el('ref-field').style.display = online ? 'block' : 'none';
            el('f-ref-label').textContent = via.label + ' reference no.';
            el('f-ref').value = d.reference_number || '';
            el('m-proof').innerHTML = d.proof_url ? proofButton(d.proof_url) : 'No receipt photo attached.';
            el('f-received-by-label').textContent = online ? 'Checked by' : 'Received by (at the counter)';
            el('f-received-by').value = String(CURRENT_USER_ID);
            el('f-reason').value = '';
            el('f-notes').value = '';
            recalc();
        } else {
            let html = `<dl class="summary-grid">
                <div><dt>${online ? 'Amount that arrived' : 'Cash received'}</dt><dd>${peso(d.breakdown.total)}</dd></div>
                <div><dt>Difference</dt><dd class="${Math.abs(d.difference) < 0.005 ? 'diff-zero' : 'diff-neg'}">${Math.abs(d.difference) < 0.005 ? 'Exact amount' : peso(d.difference)}</dd></div>
                ${online ? `<div><dt>Reference no.</dt><dd class="rm-ref">${esc(d.reference_number) || '&mdash;'}</dd></div>` : ''}
                <div><dt>Sent / submitted</dt><dd>${esc(fmtDate(d.submitted_at))}</dd></div>
                <div><dt>${online ? 'Checked by' : 'Received by'}</dt><dd>${esc(d.received_by) || '&mdash;'}</dd></div>
                <div><dt>${online ? 'Confirmed at' : 'Received at'}</dt><dd>${esc(fmtDate(d.received_at))}</dd></div>
                ${d.recorded_by ? `<div><dt>Recorded by</dt><dd>${esc(d.recorded_by)}</dd></div>` : ''}
                <div><dt>Record no.</dt><dd>${esc(d.number || '—')}</dd></div>
            </dl>`;
            if (d.discrepancy_reason) html += `<div class="callout warn"><strong>Discrepancy:</strong> ${esc(d.discrepancy_reason).replace(/\n/g, '<br>')}</div>`;
            if (d.staff_notes) html += `<div class="callout info"><strong>${d.status === 'rejected' ? 'Reason' : 'Notes'}:</strong> ${esc(d.staff_notes)}</div>`;
            if (d.proof_url) html += proofButton(d.proof_url) + ' ';
            if (d.status === 'approved' || d.status === 'discrepancy') html += `<button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" onclick="printRemittance()"><i class="fas fa-print"></i> Print receipt</button>`;
            el('m-readonly').innerHTML = html;
        }

        const needsNote = mode === 'resolve' || mode === 'reject';
        el('m-action-note').style.display = needsNote ? 'block' : 'none';
        el('f-action-note').value = '';
        el('m-action-label').textContent = mode === 'resolve'
            ? 'How was it settled? (required) - e.g. rider paid the ₱100 balance'
            : 'Why are you rejecting it? (required) - the rider will see this';

        const close = '<button type="button" class="ui-btn ui-btn-ghost" onclick="closeModal()">Close</button>';
        el('m-footer').innerHTML = close + (
            receiving ? `<button type="button" class="ui-btn ui-btn-success" id="btn-confirm" onclick="submitReceive()"></button>`
            : mode === 'resolve' ? '<button type="button" class="ui-btn ui-btn-success" onclick="submitResolve()"><i class="fas fa-check"></i> Resolve &amp; Verify</button>'
            : mode === 'reject' ? '<button type="button" class="ui-btn ui-btn-danger" onclick="submitReject()"><i class="fas fa-times"></i> Reject</button>'
            : '');
        if (receiving) recalc();
    }

    const amountIn = () => Math.max(0, Math.round((parseFloat(el('f-amount').value) || 0) * 100) / 100);
    function receiverLabel() {
        const sel = el('f-received-by');
        if (!sel || parseInt(sel.value, 10) === CURRENT_USER_ID) return 'you';
        return sel.options[sel.selectedIndex].text.replace(/ \((Admin|Staff)\)$/, '');
    }

    // Live preview of what the server decides: verified only when the amount matches what is due.
    function recalc() {
        if (!current || mode !== 'receive') return;
        const diff = Math.round((amountIn() - current.expected) * 100) / 100;
        const off = Math.abs(diff) >= 0.005;
        el('diff-value').textContent = off ? (diff < 0 ? 'Short ' : 'Over ') + peso(Math.abs(diff)) : 'Exact amount';
        el('diff-value').className = off ? 'diff-neg' : 'diff-zero';
        el('diff-box').className = 'diff-box' + (off ? ' bad' : '');
        el('reason-field').style.display = off ? 'block' : 'none';
        const btn = el('btn-confirm');
        if (btn) btn.innerHTML = off ? '<i class="fas fa-exclamation-triangle"></i> Save as Discrepancy' : '<i class="fas fa-check"></i> ' + (viaOf(current).channel === 'cash' ? 'Confirm Cash Received' : 'Confirm Payment Arrived');
    }

    // Printable overall remittance history report.
    // Uses the rows currently visible in History, so the selected History filters are respected.
    function printRemittanceReport() {
        const table = document.getElementById('history-table');
        if (!table) {
            if (window.LiveUI && typeof LiveUI.toast === 'function') {
                LiveUI.toast('There are no remittance records to print.', 'info');
            } else {
                alert('There are no remittance records to print.');
            }
            return;
        }

        const visibleRows = Array.from(table.querySelectorAll('tbody tr')).filter(row => {
            return row.style.display !== 'none' && !row.hidden;
        });

        if (!visibleRows.length) {
            if (window.LiveUI && typeof LiveUI.toast === 'function') {
                LiveUI.toast('No remittances match the current filters.', 'info');
            } else {
                alert('No remittances match the current filters.');
            }
            return;
        }

        const escReport = (value) => String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        let total = 0;
        let verified = 0;
        let rejected = 0;

        const rows = visibleRows.map(row => {
            const cells = row.querySelectorAll('td');
            const txn = cells[0]?.querySelector('.rm-ref')?.textContent.trim() || cells[0]?.textContent.trim() || '';
            const reference = cells[0]?.querySelector('.ui-cell-sub')?.textContent.trim() || '';
            const date = cells[1]?.textContent.trim() || '';
            const rider = cells[2]?.querySelector('.ui-cell-main')?.textContent.trim() || cells[2]?.textContent.trim() || '';
            const orders = cells[2]?.querySelector('.ui-cell-sub')?.textContent.trim() || '';
            const via = cells[3]?.textContent.trim() || '';
            const amountText = cells[4]?.querySelector('.ui-strong')?.textContent.trim() || cells[4]?.textContent.trim() || '';
            const receivedBy = cells[5]?.textContent.trim() || '—';
            const status = cells[6]?.textContent.trim() || '';

            const numericAmount = parseFloat(amountText.replace(/[^0-9.-]/g, '')) || 0;
            total += numericAmount;
            if (/verified/i.test(status)) verified++;
            if (/rejected/i.test(status)) rejected++;

            return `<tr>
                <td><strong>${escReport(txn)}</strong>${reference ? `<small>${escReport(reference)}</small>` : ''}</td>
                <td>${escReport(date)}</td>
                <td>${escReport(rider)}${orders ? `<small>${escReport(orders)}</small>` : ''}</td>
                <td>${escReport(via)}</td>
                <td class="money">${escReport(amountText)}</td>
                <td>${escReport(receivedBy)}</td>
                <td>${escReport(status)}</td>
            </tr>`;
        }).join('');

        const generated = new Date().toLocaleString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: 'numeric', minute: '2-digit'
        });

        const preparedBy = <?php echo json_encode((string)($username ?? ($payload['username'] ?? 'Admin'))); ?>;
        const shopName = (typeof SHOP_NAME !== 'undefined' && SHOP_NAME) ? SHOP_NAME : 'Darius Poultry Supplies';

        const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Rider Remittance Report</title>
<style>
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:28px;font-size:12px}
    .head{text-align:center;margin-bottom:20px}
    .head h1{font-size:21px;margin:0 0 4px}
    .head h2{font-size:15px;margin:0;font-weight:600;color:#444}
    .meta{display:flex;justify-content:space-between;gap:20px;margin:14px 0;color:#444}
    .summary{display:flex;gap:12px;margin:14px 0 18px}
    .summary div{flex:1;border:1px solid #ccc;padding:9px 10px;border-radius:5px}
    .summary b{display:block;font-size:14px;margin-top:3px;color:#111}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #bbb;padding:7px 6px;text-align:left;vertical-align:top}
    th{background:#f1f1f1;font-size:11px;text-transform:uppercase}
    td small{display:block;color:#666;margin-top:2px}
    .money{text-align:right;white-space:nowrap}
    .footer{margin-top:34px;display:flex;justify-content:flex-end}
    .signature{width:260px;text-align:center;border-top:1px solid #111;padding-top:6px}
    @page{size:A4 landscape;margin:12mm}
    @media print{body{margin:0}}
</style>
</head>
<body>
    <div class="head">
        <h1>${escReport(shopName)}</h1>
        <h2>Rider Remittance Report</h2>
    </div>
    <div class="meta">
        <span><strong>Generated:</strong> ${escReport(generated)}</span>
        <span><strong>Prepared by:</strong> ${escReport(preparedBy)}</span>
    </div>
    <div class="summary">
        <div>Records printed<b>${visibleRows.length}</b></div>
        <div>Verified<b>${verified}</b></div>
        <div>Rejected<b>${rejected}</b></div>
        <div>Total amount<b>₱${total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</b></div>
    </div>
    <table>
        <thead><tr>
            <th>Transaction ID</th><th>Date</th><th>Rider</th><th>Paid via</th>
            <th class="money">Amount</th><th>Received by</th><th>Status</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>
    <div class="footer"><div class="signature">${escReport(preparedBy)}<br><small>Prepared by</small></div></div>
    <script>window.onload=function(){window.print();};<\/script>
</body>
</html>`;

        const reportWindow = window.open('', '_blank');
        if (!reportWindow) {
            if (window.LiveUI && typeof LiveUI.toast === 'function') {
                LiveUI.toast('Allow pop-ups to print the report.', 'warn');
            } else {
                alert('Allow pop-ups to print the report.');
            }
            return;
        }
        reportWindow.document.open();
        reportWindow.document.write(html);
        reportWindow.document.close();
    }

    // Printable acknowledgement of a received remittance, with signature lines.
    function printRemittance() {
        const d = current;
        if (!d) return;
        const via = viaOf(d), online = via.channel !== 'cash';
        const rows = d.orders.map(o => `<tr><td>#${esc(o.order_number)}</td><td>${esc(o.customer_name || '')}</td><td class="r">${peso(o.total)}</td></tr>`).join('');
        const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Remittance ${esc(d.transaction_id || d.number)}</title><style>
            body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:32px;font-size:13px}
            h1{margin:0;font-size:20px} h2{margin:2px 0 18px;font-size:14px;font-weight:600;color:#555}
            table{width:100%;border-collapse:collapse;margin:10px 0}
            th,td{border:1px solid #bbb;padding:6px 8px;text-align:left} th{background:#f1f1f1} .r{text-align:right}
            .grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:14px} .grid div{padding:4px 0;border-bottom:1px dashed #ccc}
            .grid b{display:inline-block;min-width:130px;color:#444;font-weight:600}
            .sig{display:flex;gap:40px;margin-top:56px} .sig div{flex:1;text-align:center;border-top:1.5px solid #111;padding-top:6px}
            .note{margin-top:12px;padding:8px 10px;border:1px solid #f0b37e;background:#fff7ed}
            @media print{body{margin:14mm}}
        </style></head><body>
            <h1>${esc(SHOP_NAME)}</h1><h2>Rider Remittance Receipt</h2>
            <div class="grid">
                <div><b>Transaction ID</b>${esc(d.transaction_id) || '&mdash;'}</div><div><b>Status</b>${esc(d.status_label)}</div>
                <div><b>Rider</b>${esc(d.rider_name)}</div><div><b>Paid via</b>${esc(via.label)}</div>
                <div><b>${online ? 'Checked by' : 'Received by'}</b>${esc(d.received_by) || '&mdash;'}</div><div><b>Date</b>${esc(fmtDate(d.received_at))}</div>
                ${online ? `<div><b>Reference no.</b>${esc(d.reference_number) || '&mdash;'}</div>` : ''}<div><b>Orders</b>${d.orders.length}</div>
            </div>
            <table><thead><tr><th>Order</th><th>Customer</th><th class="r">Total</th></tr></thead><tbody>${rows}</tbody></table>
            <div class="grid">
                <div><b>Amount due</b>${peso(d.expected)}</div><div><b>${online ? 'Amount that arrived' : 'Cash received'}</b>${peso(d.breakdown.total)}</div>
                <div><b>Difference</b>${peso(d.difference)}</div>
            </div>
            ${d.discrepancy_reason ? `<div class="note"><b>Discrepancy:</b> ${esc(d.discrepancy_reason).replace(/\n/g, '<br>')}</div>` : ''}
            <div class="sig"><div>${esc(d.rider_name)}<br><small>Rider</small></div><div>${esc(d.received_by) || ''}<br><small>${online ? 'Checked by (shop)' : 'Received by (shop)'}</small></div></div>
            <script>window.onload = function(){ window.print(); };<\/script>
        <\/body></html>`;
        const w = window.open('', '_blank');
        if (!w) { LiveUI.toast('Allow pop-ups to print the receipt.', 'warn'); return; }
        w.document.open(); w.document.write(html); w.document.close();
    }

    function closeModal() { el('remittance-modal').style.display = 'none'; current = null; }
    function openReceiptModal(src) { el('receipt-modal-img').src = src; el('receipt-modal-open-tab').href = src; el('receipt-modal').style.display = 'block'; }
    function closeReceiptModal() { el('receipt-modal').style.display = 'none'; el('receipt-modal-img').src = ''; }

    // Runs one modal action with the footer buttons locked (no double submit), then refreshes the page data.
    let submitting = false;
    async function run(fn) {
        if (submitting) return;
        submitting = true;
        const btns = Array.from(el('m-footer').querySelectorAll('button'));
        btns.forEach(b => { b.disabled = true; b.classList.add('liveui-busy'); });
        let result;
        try { result = await fn(); } catch (e) { result = {success: false, message: e.message || 'Request failed.'}; }
        submitting = false;
        btns.forEach(b => { b.disabled = false; b.classList.remove('liveui-busy'); });
        if (!result.success) { LiveUI.toast(result.message, 'error'); return; }   // modal stays open
        LiveUI.toast(result.message, 'success');
        closeModal();
        await refreshRemittances();
    }

    function submitReceive() {
        const via = viaOf(current), online = via.channel !== 'cash';
        const amount = amountIn();
        const diff = Math.round((amount - current.expected) * 100) / 100;
        const off = Math.abs(diff) >= 0.005;
        const reason = el('f-reason').value.trim();
        if (amount <= 0) { LiveUI.toast('Enter the amount received.', 'warn'); el('f-amount').focus(); return; }
        if (online && !el('f-ref').value.trim()) { LiveUI.toast('Enter the reference no.', 'warn'); el('f-ref').focus(); return; }
        if (off && !reason) { LiveUI.toast('Say why the amount is different.', 'warn'); el('f-reason').focus(); return; }
        const n = current.orders.length + (current.orders.length === 1 ? ' order' : ' orders');
        const question = off
            ? `Save as a discrepancy (${diff < 0 ? 'short' : 'over'} ${peso(Math.abs(diff))})? The orders will NOT be counted in sales until it is resolved.`
            : online
                ? `Confirm ${peso(amount)} arrived in the shop's ${via.label} (ref. ${el('f-ref').value.trim()})? The ${n} will be counted in today's sales.`
                : `Confirm ${receiverLabel()} received ${peso(amount)} cash from ${current.rider_name}? The ${n} will be counted in today's sales.`;
        if (!confirm(question)) return;
        const body = {
            remittance_id: current.remittance_id, rider_id: current.rider_id,
            cash_amount: 0, gcash_amount: 0, bank_amount: 0,
            notes: el('f-notes').value.trim(), discrepancy_reason: reason,
            received_by_user_id: parseInt(el('f-received-by').value, 10) || 0
        };
        body[via.channel + '_amount'] = amount;
        if (online) body.reference_number = el('f-ref').value.trim();
        run(() => api('receive_remittance', {}, body));
    }

    // Server-side status check with PayMongo (the browser never decides the result).
    async function checkProvider(id, btn) {
        if (btn) { btn.disabled = true; btn.classList.add('liveui-busy'); }
        const r = await api('check_provider', {}, {remittance_id: id});
        if (btn) { btn.disabled = false; btn.classList.remove('liveui-busy'); }
        LiveUI.toast(r.message, r.success ? (r.data && r.data.status === 'approved' ? 'success' : 'info') : 'error');
        if (r.success) await refreshRemittances();
    }

    function submitResolve() {
        const note = el('f-action-note').value.trim();
        if (!note) { LiveUI.toast('Say how the discrepancy was settled.', 'warn'); return; }
        if (!confirm('Resolve and verify this remittance? The orders will be counted in sales.')) return;
        run(() => api('resolve_discrepancy', {}, {remittance_id: current.remittance_id, resolution: note}));
    }

    function submitReject() {
        const note = el('f-action-note').value.trim();
        if (!note) { LiveUI.toast('Enter the reason for rejecting.', 'warn'); return; }
        run(() => api('reject_remittance', {}, {remittance_id: current.remittance_id, notes: note}));
    }

    // History filters (status chip + paid via + received by).
    function applyFilter() {
        const chip = document.querySelector('.rm-chip.active');
        const f = chip ? chip.dataset.filter : 'all';
        const via = el('filter-via').value, who = el('filter-receiver').value;
        document.querySelectorAll('#history-table tbody tr').forEach(tr => {
            const show = (f === 'all' || tr.dataset.status === f) && (!via || tr.dataset.via === via) && (!who || tr.dataset.receiver === who);
            tr.style.display = show ? '' : 'none';
        });
    }
    document.addEventListener('change', e => { if (e.target.id === 'filter-via' || e.target.id === 'filter-receiver') applyFilter(); });
    document.addEventListener('click', e => {
        const chip = e.target.closest ? e.target.closest('.rm-chip') : null;
        if (!chip) return;
        document.querySelectorAll('.rm-chip').forEach(c => c.classList.toggle('active', c === chip));
        applyFilter();
    });

    // Live refresh: re-read the page and swap only the parts that changed.
    let refreshing = null;
    function refreshRemittances() {
        if (refreshing) return refreshing;
        refreshing = LiveUI.fetchDoc(location.pathname + location.search).then(doc => {
            ['remit-kpis', 'remit-todo', 'remit-history', 'remit-log-wrap'].forEach(id => LiveUI.setHTML(el(id), doc.getElementById(id)));
            doc.querySelectorAll('[data-count]').forEach(n => {
                const cur = document.querySelector('[data-count="' + n.dataset.count + '"]');
                if (cur) { LiveUI.setText(cur, n.textContent); cur.hidden = n.hidden; }
            });
            applyFilter();
            LiveUI.syncSidebar(doc);
        }).catch(() => {}).then(() => { refreshing = null; });
        return refreshing;
    }
    // Picks up remittances sent by riders; never while a dialog is open or a request is running.
    LiveUI.watch('remittance', () => {
        if (submitting || el('remittance-modal').style.display === 'block' || el('receipt-modal').style.display === 'block') return;
        return refreshRemittances();
    }, { fallbackMs: 20000 });

    window.addEventListener('click', e => {
        if (e.target === el('remittance-modal')) closeModal();
        if (e.target === el('receipt-modal')) closeReceiptModal();
    });

    // Tabs: To Do | History | Activity Log (remembers the last one; #todo / #history / #log also work).
    (function () {
        const tabs = document.querySelectorAll('.ui-tabs [data-tab]');
        function show(name, save) {
            if (!el('panel-' + name)) name = 'todo';
            tabs.forEach(t => {
                const on = t.dataset.tab === name;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                el('panel-' + t.dataset.tab).hidden = !on;
            });
            if (save) { try { localStorage.setItem('remittanceTab', name); } catch (e) {} }
        }
        tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab, true)));
        let start = location.hash.replace('#', '');
        if (!el('panel-' + start)) { try { start = localStorage.getItem('remittanceTab') || ''; } catch (e) { start = ''; } }
        show(start, false);
    })();
    </script>
</body>
</html>
