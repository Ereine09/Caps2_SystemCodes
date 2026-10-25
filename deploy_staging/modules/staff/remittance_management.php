<?php
/**
 * Staff — Rider Remittance Management.
 * Same page as modules/admin/remittance_management.php (Awaiting Handover, receive /
 * resolve / reject, filters, history) — the shared modules/admin/remittance_api.php
 * already accepts both admin and staff, so only the auth gate, the API's JS base URL,
 * and the sidebar/link paths differ here.
 */
require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload = require_staff();
$username = htmlspecialchars($payload['username']);
$user_role = 'staff';
$user_id = (int) $payload['user_id'];

// Fetch counts for sidebar badges (staff has no admin_viewed_at "opened" tracking).
$pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$pending_remittances_count = get_pending_remittance_count($conn);
$unread_messages_count = get_unread_count_staff($user_id);

// Ensure DB schema is ready
ensure_remittance_schema($conn);

// Every remittance record, newest first. Expected/difference come from the real
// orders (stored snapshot, or the live order total for older records).
$remittances = [];
$awaiting = [];
$remit_counts = ['all' => 0, 'awaiting' => 0, 'pending' => 0, 'approved' => 0, 'discrepancy' => 0, 'rejected' => 0];
try {
    $query = "
        SELECT
            rr.id AS remittance_id,
            rr.amount,
            rr.expected_amount,
            rr.cash_amount, rr.gcash_amount, rr.bank_amount,
            rr.remittance_type,
            rr.transaction_id,
            rr.reference_number,
            rr.status,
            rr.requested_at AS submitted_at,
            rr.received_at,
            (SELECT COUNT(*) FROM tbl_rider_remittance_items i WHERE i.remittance_id = rr.id) AS order_count,
            (SELECT COALESCE(SUM(o.total),0) FROM tbl_rider_remittance_items i2
               JOIN tbl_orders o ON o.id = i2.order_id WHERE i2.remittance_id = rr.id) AS cod_total,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS rider_name,
            u.username AS rider_username,
            TRIM(CONCAT(COALESCE(rb.first_name, ''), ' ', COALESCE(rb.last_name, ''))) AS received_by_name,
            rb.username AS received_by_username
        FROM tbl_rider_remittances rr
        JOIN users u ON rr.rider_id = u.id
        LEFT JOIN users rb ON rb.id = COALESCE(rr.received_by_user_id, rr.processed_by_user_id)
        ORDER BY rr.id DESC;
    ";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $b = remittance_row_breakdown($row);
            $row['expected'] = $row['expected_amount'] !== null ? (float)$row['expected_amount'] : (float)$row['cod_total'];
            $row['handed_over'] = (float)$row['amount'];
            $row['difference'] = round($row['handed_over'] - $row['expected'], 2);
            $row['method'] = remittance_payment_method_label($b['cash'], $b['gcash'], $b['bank'], $row['reference_number']);
            $row['received_by'] = in_array($row['status'], ['pending', 'rejected'], true) ? '' : (trim($row['received_by_name']) !== '' ? trim($row['received_by_name']) : (string)$row['received_by_username']);
            $row['type_label'] = remittance_type_label($row['remittance_type'] ?? 'store');
            $remittances[] = $row;
            $remit_counts['all']++;
            if (isset($remit_counts[$row['status']])) {
                $remit_counts[$row['status']]++;
            }
        }
    }
    // Riders holding completed COD orders that are not in any remittance yet
    $rider_res = $conn->query("
        SELECT r.id AS rider_id, u.username,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS rider_name
        FROM riders r JOIN users u ON r.user_id = u.id
        WHERE EXISTS (SELECT 1 FROM tbl_orders o WHERE o.rider_id = r.id AND o.payment_method = 'cod'
                      AND o.order_status = 'completed' AND o.payment_settled = 0)
        ORDER BY rider_name
    ");
    while ($rider_res && ($rider = $rider_res->fetch_assoc())) {
        $orders = remittance_eligible_orders($conn, (int)$rider['rider_id']);
        $total = remittance_orders_total($orders);
        if ($total <= 0) {
            continue;
        }
        $rider['order_count'] = count($orders);
        $rider['expected'] = $total;
        $rider['latest'] = max(array_column($orders, 'created_at'));
        $awaiting[] = $rider;
        $remit_counts['all']++;
        $remit_counts['awaiting']++;
    }
} catch (Exception $e) {
    error_log("Error fetching all remittances: " . $e->getMessage());
}
$me_row = $conn->query("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS n FROM users WHERE id = " . (int)$user_id)->fetch_assoc();
$current_user_name = trim((string)($me_row['n'] ?? '')) !== '' ? trim($me_row['n']) : (string)($payload['username'] ?? '');

// Admin/staff who can physically receive a rider's remittance at the shop ("Received By" picker).
$receivers = [];
$rcv_res = $conn->query("SELECT id, role, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username FROM users WHERE role IN ('admin','staff') ORDER BY role = 'admin' DESC, first_name, username");
while ($rcv_res && ($ru = $rcv_res->fetch_assoc())) {
    $receivers[] = ['id' => (int)$ru['id'], 'role' => $ru['role'], 'name' => trim($ru['full_name']) !== '' ? trim($ru['full_name']) : $ru['username']];
}
// Names that actually appear in the table (for the "Received by" filter).
$receiver_filter = array_values(array_unique(array_filter(array_column($remittances, 'received_by'), static fn($n) => $n !== '')));
sort($receiver_filter, SORT_NATURAL | SORT_FLAG_CASE);

// ---- Remittance Log: a timeline built from the remittance records themselves (so older records are included) ----
// Events: submitted by the rider, received at the shop (by whom / recorded by whom), discrepancy, resolved, rejected.
$remit_log = [];
try {
    $log_res = $conn->query("
        SELECT rr.id, rr.status, rr.amount, rr.expected_amount, rr.declared_amount, rr.notes, rr.discrepancy_reason,
               rr.requested_at, rr.received_at, rr.processed_at,
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
    $pick = static fn($full, $user) => trim((string)$full) !== '' ? trim((string)$full) : (string)$user;
    while ($log_res && ($lr = $log_res->fetch_assoc())) {
        $num = remittance_number((int)$lr['id']);
        $rider = $pick($lr['rider_full'], $lr['rider_user']);
        $proc = $pick($lr['proc_full'], $lr['proc_user']);
        $recv = $pick($lr['recv_full'], $lr['recv_user']);
        if ($recv === '') { $recv = $proc; }                       // older records only stored who processed it
        $expected = $lr['expected_amount'] !== null ? (float)$lr['expected_amount'] : (float)$lr['cod_total'];
        $base = ['id' => (int)$lr['id'], 'number' => $num, 'rider' => $rider];

        if (!empty($lr['requested_at'])) {
            $declared = $lr['declared_amount'] !== null ? (float)$lr['declared_amount'] : (float)$lr['amount'];
            $remit_log[] = $base + ['time' => $lr['requested_at'], 'type' => 'submitted', 'by' => $rider, 'recorded_by' => '',
                'amount' => $declared, 'detail' => (int)$lr['order_count'] . ' order' . ((int)$lr['order_count'] === 1 ? '' : 's') . ' - rider submitted the handover'];
        }
        if (in_array($lr['status'], ['approved', 'discrepancy'], true)) {
            $t = $lr['received_at'] ?: $lr['processed_at'];
            $resolved = $lr['status'] === 'approved' && strpos((string)$lr['discrepancy_reason'], 'Resolution:') !== false;
            $diff = round((float)$lr['amount'] - $expected, 2);
            $detail = 'Handed over PHP ' . number_format((float)$lr['amount'], 2) . ' against PHP ' . number_format($expected, 2) . ' expected';
            if ($lr['status'] === 'discrepancy' || $resolved) {
                $reason = trim((string)strtok((string)$lr['discrepancy_reason'], "\n"));
                $detail .= $reason !== '' ? '. Discrepancy: ' . $reason : '';
            }
            $remit_log[] = $base + ['time' => $t, 'type' => ($lr['status'] === 'discrepancy' || $resolved) ? 'received_discrepancy' : 'received_verified',
                'by' => $recv, 'recorded_by' => ($proc !== '' && $proc !== $recv) ? $proc : '', 'amount' => (float)$lr['amount'], 'detail' => $detail];
            if ($resolved && !empty($lr['processed_at']) && $lr['processed_at'] !== $t) {
                $res_note = trim((string)substr((string)$lr['discrepancy_reason'], strrpos((string)$lr['discrepancy_reason'], 'Resolution:') + 11));
                $remit_log[] = $base + ['time' => $lr['processed_at'], 'type' => 'resolved', 'by' => $proc, 'recorded_by' => '',
                    'amount' => (float)$lr['amount'], 'detail' => $res_note !== '' ? $res_note : 'Discrepancy resolved and verified'];
            }
        } elseif ($lr['status'] === 'rejected') {
            $remit_log[] = $base + ['time' => $lr['processed_at'] ?: $lr['requested_at'], 'type' => 'rejected', 'by' => $proc, 'recorded_by' => '',
                'amount' => (float)$lr['amount'], 'detail' => trim((string)$lr['notes']) !== '' ? trim((string)$lr['notes']) : 'Rejected'];
        }
    }
    usort($remit_log, static fn($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);
    $remit_log = array_slice($remit_log, 0, 120);
} catch (Throwable $e) {
    error_log('Remittance log failed: ' . $e->getMessage());
}
$remit_log_types = [
    'submitted'            => ['Submitted by rider',     'badge-pending',     'fa-paper-plane'],
    'received_verified'    => ['Received & verified',    'badge-approved',    'fa-check-circle'],
    'received_discrepancy' => ['Received - discrepancy', 'badge-discrepancy', 'fa-exclamation-triangle'],
    'resolved'             => ['Discrepancy resolved',   'badge-approved',    'fa-check-double'],
    'rejected'             => ['Rejected',               'badge-rejected',    'fa-times-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Remittance Management - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --success-color: #16a34a;
            --danger-color: #dc2626;
            --bg-light: #f8fafc;
            --border-color: var(--border);
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        body { 
            overflow-x: hidden; 
            background-color: var(--surface-2);
        }
        /* Main Dashboard Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: var(--surface);
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .page-header h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Section Container Box */
        .content-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        .content-card h3 {
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 1.15rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Pending Action Cards View */
        .pending-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
        }
        .rider-remit-card {
            background: var(--surface);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .rider-remit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.05);
            border-color: var(--border);
        }
        .rider-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .rider-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--info-soft);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .rider-details h4 {
            margin: 0;
            font-size: 1rem;
            color: var(--text-dark);
        }
        .rider-details p {
            margin: 2px 0 0 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .remit-amount-box {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
            text-align: center;
        }
        .remit-amount-box span {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .remit-amount-box h2 {
            margin: 4px 0 0 0;
            font-size: 1.5rem;
            color: var(--text-dark);
        }

        /* Buttons */
        .btn-action {
            width: 100%;
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            background: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-action:hover {
            background: var(--primary-hover);
        }

        /* Table Styling */
        .custom-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .custom-table th {
            background: var(--bg-light);
            padding: 12px 16px;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }
        .custom-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-dark);
            vertical-align: middle;
        }
        .custom-table tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-approved { background: var(--success-soft); color: var(--success); }
        .badge-rejected { background: var(--danger-soft); color: var(--danger); }
        .badge-pending  { background: var(--warning-soft); color: var(--warning); }
        .badge-discrepancy { background: var(--warning-soft); color: var(--warning); }
        .badge-awaiting { background: var(--info-soft); color: var(--info); }
        #all-remittances-table th, #all-remittances-table td { padding-left: 9px; padding-right: 9px; }

        /* Handover table + filters */
        .page-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color, #e2e8f0); overflow-x: auto; }
        .page-tab {
            background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px;
            padding: 12px 20px; font: inherit; font-size: 0.95rem; font-weight: 600; color: var(--text-muted, #64748b);
            cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;
        }
        .page-tab:hover { color: var(--primary-color); }
        .page-tab.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab-panel[hidden] { display: none !important; }
        body.dark-mode .page-tabs { border-bottom-color: var(--dm-border, #2f3b52); }
        body.dark-mode .page-tab { color: var(--dm-text-muted, #94a3b8); }
        body.dark-mode .page-tab:hover, body.dark-mode .page-tab.active { color: #93c5fd; }
        body.dark-mode .page-tab.active { border-bottom-color: #93c5fd; }
        .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .filter-chip {
            border: 1px solid var(--border-color); background: var(--surface); color: var(--text-muted);
            padding: 6px 14px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; cursor: pointer;
        }
        .filter-chip.active { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
        #method-select { margin-top: 4px; }
        #method-select .method-chip.active { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
        body.dark-mode #method-select .method-chip:not(.active) { background: var(--dm-surface-2, #1b2436); color: var(--dm-text-muted, #94a3b8); border-color: var(--dm-border, #2f3b52); }
        /* Dark mode: these were hard-coded light (unreadable text on the badges, chips and small buttons) */
        body.dark-mode .content-card .badge-status.badge-approved { background: #12301f; color: #86efac; }
        body.dark-mode .content-card .badge-status.badge-rejected { background: #3a1414; color: #fca5a5; }
        body.dark-mode .content-card .badge-status.badge-pending { background: #3a2c10; color: #fcd34d; }
        body.dark-mode .content-card .badge-status.badge-discrepancy { background: #3a230f; color: #fdba74; }
        body.dark-mode .content-card .badge-status.badge-awaiting { background: #14274a; color: #93c5fd; }
        body.dark-mode .content-card .filter-chip:not(.active) { background: var(--dm-surface-2, #1b2436); color: var(--dm-text-muted, #94a3b8); border-color: var(--dm-border, #2f3b52); }
        body.dark-mode .content-card .btn-sm.warn { background: #3a230f; color: #fdba74; border-color: #5c3a16; }
        body.dark-mode .content-card .btn-sm.danger { background: #3a1414; color: #fca5a5; border-color: #5c1f1f; }
        body.dark-mode .content-card .btn-sm:not(.primary):not(.danger):not(.warn) { background: var(--dm-surface-2, #1b2436); color: var(--dm-text, #e5e7eb); border-color: var(--dm-border, #2f3b52); }

        /* Remittance Log: scrolls inside its own box so the page stays short */
        .log-scroll { max-height: 440px; overflow: auto; border: 1px solid var(--border-color); border-radius: 10px; }
        .log-scroll .custom-table { margin: 0; }
        .log-scroll thead th { position: sticky; top: 0; z-index: 1; background: var(--bg-light, #f8fafc); }
        .log-time { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .log-time small, .log-by small { display: block; color: var(--text-muted); font-size: 0.75rem; }
        .log-detail { max-width: 380px; color: var(--text-muted); font-size: 0.85rem; overflow-wrap: anywhere; }
        .log-empty { text-align: center; padding: 26px; color: var(--text-muted); }
        .rcv-filter { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; color: var(--text-muted); }
        .rcv-filter select, .field select { padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--surface); color: var(--text-dark); font: inherit; font-size: 0.85rem; }
        .field select { width: 100%; padding: 9px 10px; box-sizing: border-box; }
        .field small.hint { display: block; margin-top: 4px; color: var(--text-muted); font-size: 0.78rem; }
        body.dark-mode .rcv-filter select, body.dark-mode .field select { background: var(--dm-surface-2, #1b2436); color: var(--dm-text, #e5e7eb); border-color: var(--dm-border, #2f3b52); }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .diff-zero { color: var(--success-color); font-weight: 600; }
        .diff-neg, .diff-pos { color: var(--danger-color); font-weight: 700; }
        .row-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .btn-sm {
            padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--surface);
            color: var(--text-dark); font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap;
        }
        .btn-sm.primary { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
        .btn-sm.warn { color: var(--warning); border-color: #fdba74; }
        .btn-sm.danger { color: var(--danger-color); border-color: #fca5a5; }

        /* Receive modal */
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin: 14px 0; }
        .summary-grid dt { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; }
        .summary-grid dd { margin: 2px 0 0; font-weight: 600; color: var(--text-dark); }
        .amount-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 10px 0; }
        .field label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }
        .field input, .field textarea {
            width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid var(--border-color);
            border-radius: 8px; font-size: 0.95rem; font-family: inherit;
        }
        .field input[readonly] { background: var(--bg-light); }
        .diff-box { display: flex; justify-content: space-between; align-items: center; background: var(--bg-light);
            border-radius: 8px; padding: 12px 14px; margin: 12px 0; font-weight: 600; }
        .diff-box.bad { background: var(--warning-soft); }
        .callout { border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; margin: 10px 0; }
        .callout.warn { background: var(--warning-soft); color: var(--warning); border: 1px solid #fed7aa; }
        .callout.info { background: var(--info-soft); color: var(--info); border: 1px solid #bfdbfe; }
        .order-mini { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 6px; }
        .order-mini th, .order-mini td { padding: 6px 8px; border-bottom: 1px solid var(--border-color); text-align: left; }
        .order-mini td:last-child, .order-mini th:last-child { text-align: right; }
        @media (max-width: 600px) { .amount-grid, .summary-grid { grid-template-columns: 1fr; } }

        /* Modal Redesign */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: var(--surface);
            margin: 6% auto;
            padding: 30px;
            border: none;
            width: 90%;
            max-width: 650px;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close-button {
            color: var(--text-muted);
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close-button:hover { color: var(--text-dark); }

        .modal-footer {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-reject {
            background: var(--surface-2);
            color: var(--danger-color);
            border: 1px solid var(--border);
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-modal-reject:hover {
            background: var(--danger-soft);
            border-color: #fca5a5;
        }
        .btn-modal-approve {
            background: var(--success-color);
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-modal-approve:hover {
            background: #15803d;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-hand-holding-usd" style="color: var(--primary-color);"></i> Rider Remittance Management</h1>
        </div>

        <div class="page-tabs" role="tablist">
            <button type="button" class="page-tab active" role="tab" id="tab-remittances" data-tab="remittances" aria-selected="true" aria-controls="panel-remittances"><i class="fas fa-history"></i> Remittances</button>
            <button type="button" class="page-tab" role="tab" id="tab-log" data-tab="log" aria-selected="false" aria-controls="panel-log"><i class="fas fa-clipboard-list"></i> Remittance Log</button>
        </div>

        <main>
            <!-- All remittances -->
            <div class="content-card tab-panel" id="panel-remittances" role="tabpanel" aria-labelledby="tab-remittances">
                <h3><i class="fas fa-history" style="color: var(--primary-color);"></i> Remittances</h3>
                <?php if ($awaiting): ?>
                    <p style="margin: -8px 0 16px; color: var(--text-muted); font-size: 0.88rem;">
                        <strong>Awaiting Handover</strong> rows are riders holding completed COD orders that have not been remitted yet. When a rider returns to the shop and hands over the money, click <strong>Receive Remittance</strong>.
                    </p>
                <?php endif; ?>
                <div id="remit-wrap">
                <?php if (empty($remittances) && empty($awaiting)): ?>
                    <p style="text-align: center; padding: 30px; color: var(--text-muted);">No remittances recorded yet.</p>
                <?php else: ?>
                    <div class="filter-bar">
                        <?php
                        $filters = ['all' => 'All', 'awaiting' => 'Awaiting Handover', 'pending' => 'Submitted', 'discrepancy' => 'Discrepancy', 'approved' => 'Verified', 'rejected' => 'Rejected'];
                        foreach ($filters as $key => $label): ?>
                            <button type="button" class="filter-chip<?php echo $key === 'all' ? ' active' : ''; ?>" data-filter="<?php echo $key; ?>">
                                <?php echo $label; ?> (<span data-count="<?php echo $key; ?>"><?php echo (int)$remit_counts[$key]; ?></span>)
                            </button>
                        <?php endforeach; ?>
                        <?php if ($receiver_filter): ?>
                            <label class="rcv-filter"><i class="fas fa-user-check"></i> Received by
                                <select id="filter-receiver" aria-label="Filter by who received the remittance">
                                    <option value="">Anyone</option>
                                    <?php foreach ($receiver_filter as $rn): ?><option value="<?php echo htmlspecialchars($rn); ?>"><?php echo htmlspecialchars($rn); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="custom-table" id="all-remittances-table">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Rider</th>
                                    <th>Date</th>
                                    <th>Orders</th>
                                    <th class="num">Expected</th>
                                    <th class="num">Handed Over</th>
                                    <th class="num">Difference</th>
                                    <th>Payment Method</th>
                                    <th>Received By</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($awaiting as $aw): ?>
                                    <tr data-status="awaiting" data-receiver="" data-key="awaiting-<?php echo (int)$aw['rider_id']; ?>">
                                        <td><span style="color: var(--text-muted);">Not yet recorded</span></td><!-- no transaction id until the rider/staff actually records a remittance -->
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(trim($aw['rider_name']) ?: $aw['username']); ?></div>
                                            <small style="color: var(--text-muted);">@<?php echo htmlspecialchars($aw['username']); ?></small>
                                        </td>
                                        <td style="white-space: nowrap;"><?php echo htmlspecialchars(date('M j, Y', strtotime($aw['latest']))); ?></td>
                                        <td><?php $oc = (int)$aw['order_count']; echo $oc . ' ' . ($oc === 1 ? 'order' : 'orders'); ?></td>
                                        <td class="num">&#8369;<?php echo number_format($aw['expected'], 2); ?></td>
                                        <td class="num"><span style="color: var(--text-muted);">&mdash;</span></td>
                                        <td class="num"><span style="color: var(--text-muted);">&mdash;</span></td>
                                        <td><span style="color: var(--text-muted);">&mdash;</span></td>
                                        <td><span style="color: var(--text-muted);">&mdash;</span></td>
                                        <td><span class="badge-status badge-awaiting"><i class="fas fa-clock"></i> Awaiting Handover</span></td>
                                        <td>
                                            <div class="row-actions">
                                                <button class="btn-sm primary" onclick="openRemittance(null, null, <?php echo (int)$aw['rider_id']; ?>)">Receive Remittance</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($remittances as $remittance):
                                    $st = strtolower($remittance['status'] ?? 'pending');
                                    $icon = ['approved' => 'fa-check-circle', 'rejected' => 'fa-times-circle', 'discrepancy' => 'fa-exclamation-triangle'][$st] ?? 'fa-hourglass-half';
                                    $diff = (float)$remittance['difference'];
                                    $diff_class = abs($diff) < 0.005 ? 'diff-zero' : ($diff < 0 ? 'diff-neg' : 'diff-pos');
                                    $rid = (int)$remittance['remittance_id'];
                                    ?>
                                    <tr data-status="<?php echo htmlspecialchars($st); ?>" data-receiver="<?php echo htmlspecialchars((string)$remittance['received_by']); ?>" data-key="r-<?php echo $rid; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($remittance['transaction_id'] ?: remittance_number($rid)); ?></strong>
                                            <br><small style="color: var(--text-muted);"><?php echo htmlspecialchars($remittance['type_label']); ?> &middot; <?php echo htmlspecialchars(remittance_number($rid)); ?></small>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(trim($remittance['rider_name']) ?: $remittance['rider_username']); ?></div>
                                            <small style="color: var(--text-muted);">@<?php echo htmlspecialchars($remittance['rider_username']); ?></small>
                                        </td>
                                        <td style="white-space: nowrap;"><?php echo htmlspecialchars(date('M j, Y', strtotime($remittance['submitted_at']))); ?></td>
                                        <td><?php $oc = (int)$remittance['order_count']; echo $oc . ' ' . ($oc === 1 ? 'order' : 'orders'); ?></td>
                                        <td class="num">&#8369;<?php echo number_format($remittance['expected'], 2); ?></td>
                                        <td class="num" style="font-weight: 700;">&#8369;<?php echo number_format($remittance['handed_over'], 2); ?></td>
                                        <td class="num <?php echo $diff_class; ?>"><?php echo $diff < 0 ? '-' : ($diff > 0 ? '+' : ''); ?>&#8369;<?php echo number_format(abs($diff), 2); ?></td>
                                        <td><?php echo htmlspecialchars($remittance['method']); ?></td>
                                        <td><?php echo $remittance['received_by'] !== '' ? htmlspecialchars($remittance['received_by']) : '<span style="color: var(--text-muted);">&mdash;</span>'; ?></td>
                                        <td>
                                            <span class="badge-status badge-<?php echo htmlspecialchars($st); ?>">
                                                <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars(remittance_status_label($st)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <?php if ($st === 'pending'): ?>
                                                    <button class="btn-sm primary" onclick="openRemittance(<?php echo $rid; ?>, null)">Receive Remittance</button>
                                                    <button class="btn-sm danger" onclick="openRemittance(<?php echo $rid; ?>, 'reject')">Reject</button>
                                                <?php elseif ($st === 'discrepancy'): ?>
                                                    <button class="btn-sm warn" onclick="openRemittance(<?php echo $rid; ?>, 'resolve')">Resolve</button>
                                                    <button class="btn-sm danger" onclick="openRemittance(<?php echo $rid; ?>, 'reject')">Reject</button>
                                                <?php endif; ?>
                                                <button class="btn-sm" onclick="openRemittance(<?php echo $rid; ?>, 'view')">Details</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </div><!-- /#remit-wrap -->
            </div>

            <!-- Remittance Log (newest first) -->
            <div class="content-card tab-panel" id="panel-log" role="tabpanel" aria-labelledby="tab-log" hidden>
                <h3><i class="fas fa-clipboard-list" style="color: var(--primary-color);"></i> Remittance Log</h3>
                <p style="margin: -8px 0 14px; color: var(--text-muted); font-size: 0.88rem;">
                    Every step of each rider remittance, newest first: when it was submitted, who received it at the shop, and any discrepancy, resolution or rejection.
                </p>
                <div id="remit-log-wrap">
                <?php if (empty($remit_log)): ?>
                    <p class="log-empty">No remittance activity yet.</p>
                <?php else: ?>
                    <div class="log-scroll">
                        <table class="custom-table" id="remittance-log-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Remittance</th>
                                    <th>Rider</th>
                                    <th>Event</th>
                                    <th>By</th>
                                    <th class="num">Amount</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($remit_log as $ev):
                                    [$ev_label, $ev_badge, $ev_icon] = $remit_log_types[$ev['type']];
                                    $ev_ts = $ev['time'] ? strtotime($ev['time']) : 0; ?>
                                    <tr>
                                        <td class="log-time"><?php echo $ev_ts ? htmlspecialchars(date('M j, Y', $ev_ts)) . '<small>' . htmlspecialchars(date('g:i A', $ev_ts)) . '</small>' : '&mdash;'; ?></td>
                                        <td><strong><?php echo htmlspecialchars($ev['number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($ev['rider']); ?></td>
                                        <td><span class="badge-status <?php echo $ev_badge; ?>"><i class="fas <?php echo $ev_icon; ?>"></i> <?php echo htmlspecialchars($ev_label); ?></span></td>
                                        <td class="log-by"><?php echo $ev['by'] !== '' ? htmlspecialchars($ev['by']) : '<span style="color: var(--text-muted);">&mdash;</span>'; ?>
                                            <?php if ($ev['type'] === 'received_verified' || $ev['type'] === 'received_discrepancy'): ?><small>received at the shop<?php echo $ev['recorded_by'] !== '' ? ' &middot; recorded by ' . htmlspecialchars($ev['recorded_by']) : ''; ?></small><?php endif; ?>
                                        </td>
                                        <td class="num">&#8369;<?php echo number_format($ev['amount'], 2); ?></td>
                                        <td class="log-detail"><?php echo htmlspecialchars($ev['detail']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </div><!-- /#remit-log-wrap -->
            </div>
        </main>
    </div>

    <!-- Receive / Details Modal -->
    <div id="remittance-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal()">&times;</span>
            <h2 id="modal-title" style="margin-top: 0; color: var(--text-dark);">Receive Rider Remittance</h2>

            <dl class="summary-grid">
                <div><dt>Rider</dt><dd id="m-rider">&mdash;</dd></div>
                <div><dt>Remittance Type</dt><dd id="m-type">&mdash;</dd></div>
                <div><dt>Transaction ID</dt><dd id="m-txn">&mdash;</dd></div>
                <div><dt>Remittance</dt><dd id="m-number">&mdash;</dd></div>
                <div><dt>Date</dt><dd id="m-date">&mdash;</dd></div>
                <div><dt>Status</dt><dd id="m-status">&mdash;</dd></div>
                <div><dt>Orders Covered</dt><dd id="m-orders-count">&mdash;</dd></div>
                <div><dt>Expected Amount</dt><dd id="m-expected" style="font-size: 1.15rem;">&mdash;</dd></div>
            </dl>

            <details>
                <summary style="cursor: pointer; font-size: 0.85rem; color: var(--primary-color); font-weight: 600;">Show orders</summary>
                <table class="order-mini">
                    <thead><tr><th>Order</th><th>Customer</th><th>Total</th></tr></thead>
                    <tbody id="m-orders"></tbody>
                </table>
            </details>

            <div id="m-rider-note" class="callout info" style="display: none;"></div>

            <div id="m-amount-section">
                <div class="field" style="margin-top: 14px;">
                    <label>Remittance Method</label>
                    <div class="filter-bar" id="method-select" role="group" aria-label="Remittance method">
                        <button type="button" class="filter-chip method-chip" data-method="cash" onclick="selectMethod('cash')">Cash</button>
                        <button type="button" class="filter-chip method-chip" data-method="gcash" onclick="selectMethod('gcash')">GCash</button>
                        <button type="button" class="filter-chip method-chip" data-method="bank" onclick="selectMethod('bank')">Bank Transfer</button>
                    </div>
                    <small class="hint">How the rider is remitting this money to the shop.</small>
                </div>
                <div class="field" id="cash-field">
                    <label for="f-cash">Cash Amount Handed Over</label>
                    <input type="number" id="f-cash" min="0" step="0.01" value="0" oninput="recalc()">
                </div>
                <div class="field" id="gcash-field" style="display: none;">
                    <label for="f-gcash">GCash Amount</label>
                    <input type="number" id="f-gcash" min="0" step="0.01" value="0" oninput="recalc()">
                </div>
                <div class="field" id="bank-field" style="display: none;">
                    <label for="f-bank">Bank Transfer Amount</label>
                    <input type="number" id="f-bank" min="0" step="0.01" value="0" oninput="recalc()">
                </div>
                <div class="field" id="ref-field" style="display: none;">
                    <label for="f-ref" id="ref-label">Reference Number <span id="ref-hint" style="font-weight: 400;"></span></label>
                    <input type="text" id="f-ref" maxlength="100" placeholder="GCash / bank reference">
                </div>
                <div id="m-proof" style="display: none; margin: 8px 0;"></div>

                <div class="diff-box" id="diff-box">
                    <span>Difference</span><span id="diff-value">&#8369;0.00</span>
                </div>

                <div class="field" id="reason-field" style="display: none;">
                    <label for="f-reason">Discrepancy Reason <span style="color: var(--danger-color);">*</span></label>
                    <textarea id="f-reason" rows="2" placeholder="e.g. &#8369;100 shortage reported by rider."></textarea>
                </div>
                <div class="field" style="margin-top: 10px;">
                    <label for="f-notes">Notes</label>
                    <textarea id="f-notes" rows="2" placeholder="Optional"></textarea>
                </div>
                <div class="field" style="margin-top: 10px;">
                    <label for="f-received-by">Received By (at the shop) <span style="color: var(--danger-color);">*</span></label>
                    <select id="f-received-by">
                        <?php foreach ($receivers as $rc): ?>
                            <option value="<?php echo (int)$rc['id']; ?>"<?php echo $rc['id'] === $user_id ? ' selected' : ''; ?>><?php echo htmlspecialchars($rc['name']) . ' (' . ucfirst($rc['role']) . ')' . ($rc['id'] === $user_id ? ' - you' : ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="hint">The person at the shop who physically received the cash. Defaults to you.</small>
                </div>
                <dl class="summary-grid">
                    <div><dt>Recorded By</dt><dd><?php echo htmlspecialchars($current_user_name); ?></dd></div>
                    <div><dt>Received At</dt><dd id="m-received-at">Recorded automatically when confirmed</dd></div>
                </dl>
            </div>

            <div id="m-readonly" style="display: none;"></div>

            <div id="m-action-note" class="field" style="display: none; margin-top: 10px;">
                <label id="m-action-label" for="f-action-note"></label>
                <textarea id="f-action-note" rows="3"></textarea>
            </div>

            <div class="modal-footer" id="m-footer"></div>
        </div>
    </div>

    <!-- Receipt Viewer Modal -->
    <div id="receipt-modal" class="modal">
        <div class="modal-content" style="max-width: 420px; text-align: center;">
            <span class="close-button" onclick="closeReceiptModal()">&times;</span>
            <h2 style="margin-top: 0; color: var(--text-dark); font-size: 1.1rem; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-receipt" style="color: var(--primary-color);"></i> Payment Receipt
            </h2>
            <img id="receipt-modal-img" src="" alt="Payment receipt"
                 style="width: 100%; max-height: 60vh; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-color); margin-top: 10px;">
            <div style="margin-top: 14px;">
                <a id="receipt-modal-open-tab" href="#" target="_blank" rel="noopener"
                   style="font-size: 0.85rem; color: var(--text-muted);">
                    <i class="fas fa-up-right-from-square"></i> Open full size in a new tab
                </a>
            </div>
        </div>
    </div>

    <script>
    // Modal state. `current` is the last payload from remittance_api.php; `mode` is
    // receive | resolve | reject | view. The expected amount is display-only: the
    // server always recomputes it from the real orders.
    let current = null;
    let mode = 'receive';

    const peso = n => (n < 0 ? '-' : '') + '₱' + Math.abs(Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const el = id => document.getElementById(id);

    // Always resolves to {success, message, ...}. Network problems, timeouts, an unreadable reply or an
    // expired session become {success:false, message} instead of throwing, so callers stay simple.
    const REMIT_API = <?php echo json_encode(BASE_URL . '/modules/admin/remittance_api.php'); ?>;
    async function api(action, params = {}, body = null) {
        const qs = new URLSearchParams({action, ...params}).toString();
        try {
            return await LiveUI.request(REMIT_API + '?' + qs, body ? {method: 'POST', body} : {method: 'GET'});
        } catch (e) {
            return e.data && typeof e.data === 'object' ? e.data : {success: false, message: e.message};
        }
    }

    // remittanceId: an existing (submitted) record; riderId: record a handover for a
    // rider who has not submitted in the app; action: view | resolve | reject | null.
    async function openRemittance(remittanceId, action, riderId = null) {
        const result = remittanceId
            ? await api('get_remittance_details', {remittance_id: remittanceId})
            : await api('get_remittance_details', {rider_id: riderId});
        if (!result.success) { LiveUI.toast(result.message, 'error'); return; }
        current = result.data;
        mode = action || 'receive';
        renderModal();
        el('remittance-modal').style.display = 'block';
    }

    function renderModal() {
        const d = current;
        const receiving = mode === 'receive';
        const titles = {receive: 'Receive Rider Remittance', resolve: 'Resolve Discrepancy', reject: 'Reject Remittance', view: 'Remittance Details'};
        el('modal-title').textContent = titles[mode];
        el('m-rider').textContent = d.rider_name;
        el('m-type').textContent = d.type_label || 'Not chosen yet';
        el('m-txn').textContent = d.transaction_id || (d.remittance_id ? 'Not yet assigned' : 'Assigned on submission');
        el('m-number').textContent = d.number || 'New (not yet submitted by rider)';
        el('m-date').textContent = d.submitted_at ? new Date(d.submitted_at.replace(' ', 'T')).toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric'}) : new Date().toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric'});
        el('m-status').textContent = d.status_label;
        el('m-orders-count').textContent = d.orders.length + (d.orders.length === 1 ? ' order' : ' orders');
        el('m-expected').textContent = peso(d.expected);
        el('m-orders').innerHTML = d.orders.map(o => `<tr><td>#${esc(o.order_number)}</td><td>${esc(o.customer_name || '')}</td><td>${peso(o.total)}</td></tr>`).join('');

        const note = el('m-rider-note');
        if (d.rider_notes) { note.style.display = 'block'; note.innerHTML = '<strong>Rider note:</strong> ' + esc(d.rider_notes); }
        else { note.style.display = 'none'; }

        // Amount inputs only when actually receiving; other modes show what was recorded.
        el('m-amount-section').style.display = receiving ? 'block' : 'none';
        const readonly = el('m-readonly');
        readonly.style.display = receiving ? 'none' : 'block';
        if (receiving) {
            el('f-cash').value = d.breakdown.cash;
            el('f-gcash').value = d.breakdown.gcash;
            el('f-bank').value = d.breakdown.bank;
            el('f-ref').value = d.reference_number || '';
            el('f-reason').value = '';
            el('f-notes').value = '';
            el('f-received-by').value = String(CURRENT_USER_ID);
            // Which method the amount already belongs to (e.g. what the rider submitted in the app).
            // Defaults to Cash when nothing has been recorded yet (a staff-initiated handover).
            applyMethodVisibility(d.breakdown.gcash > 0 ? 'gcash' : (d.breakdown.bank > 0 ? 'bank' : 'cash'));
            const proof = el('m-proof');
            if (d.proof_url) {
                proof.style.display = 'block';
                proof.innerHTML = `<button class="btn-sm" onclick="openReceiptModal('${esc(d.proof_url)}')"><i class="fas fa-receipt"></i> View payment receipt</button>`;
            } else { proof.style.display = 'none'; }
            recalc();
        } else {
            let html = `<dl class="summary-grid">
                <div><dt>Amount Handed Over</dt><dd>${peso(d.breakdown.total)}</dd></div>
                <div><dt>Difference</dt><dd class="${Math.abs(d.difference) < 0.005 ? 'diff-zero' : 'diff-neg'}">${peso(d.difference)}</dd></div>
                <div><dt>Cash</dt><dd>${peso(d.breakdown.cash)}</dd></div>
                <div><dt>GCash</dt><dd>${peso(d.breakdown.gcash)}</dd></div>
                <div><dt>Bank Transfer</dt><dd>${peso(d.breakdown.bank)}</dd></div>
                <div><dt>${d.remittance_type === 'gcash' ? 'Actual GCash Reference' : 'Reference'}</dt><dd>${esc(d.reference_number) || '&mdash;'}</dd></div>
                <div><dt>Received By</dt><dd>${esc(d.received_by) || '&mdash;'}</dd></div>
                <div><dt>Received At</dt><dd>${esc(d.received_at) || '&mdash;'}</dd></div>
                ${d.recorded_by ? `<div><dt>Recorded By</dt><dd>${esc(d.recorded_by)}</dd></div>` : ''}
            </dl>`;
            if (d.discrepancy_reason) html += `<div class="callout warn"><strong>Discrepancy:</strong> ${esc(d.discrepancy_reason).replace(/\n/g, '<br>')}</div>`;
            if (d.staff_notes) html += `<div class="callout info"><strong>Notes:</strong> ${esc(d.staff_notes)}</div>`;
            if (d.proof_url) html += `<button class="btn-sm" onclick="openReceiptModal('${esc(d.proof_url)}')"><i class="fas fa-receipt"></i> View payment receipt</button> `;
            if (d.status === 'approved' || d.status === 'discrepancy') html += `<button class="btn-sm" onclick="printRemittance()"><i class="fas fa-print"></i> Print receipt</button>`;
            readonly.innerHTML = html;
        }

        // Resolve / reject need a written reason.
        const needsNote = mode === 'resolve' || mode === 'reject';
        el('m-action-note').style.display = needsNote ? 'block' : 'none';
        el('f-action-note').value = '';
        el('m-action-label').textContent = mode === 'resolve'
            ? 'Resolution note (required) - how was this discrepancy settled?'
            : 'Reason for rejecting (required) - the rider will see this';

        const footer = el('m-footer');
        const cancel = '<button class="btn-modal-reject" style="color: var(--text-dark);" onclick="closeModal()">Close</button>';
        if (receiving) {
            footer.innerHTML = cancel +
                '<button class="btn-modal-reject" onclick="submitReceive(true)"><i class="fas fa-exclamation-triangle"></i> Mark Discrepancy</button>' +
                '<button class="btn-modal-approve" id="btn-confirm" onclick="submitReceive(false)"><i class="fas fa-check"></i> Confirm Received</button>';
        } else if (mode === 'resolve') {
            footer.innerHTML = cancel + '<button class="btn-modal-approve" onclick="submitResolve()"><i class="fas fa-check"></i> Resolve &amp; Verify</button>';
        } else if (mode === 'reject') {
            footer.innerHTML = cancel + '<button class="btn-modal-reject" onclick="submitReject()"><i class="fas fa-times"></i> Reject Remittance</button>';
        } else {
            footer.innerHTML = cancel;
        }
    }

    const num = id => Math.max(0, parseFloat(el(id).value) || 0);
    const CURRENT_USER_ID = <?php echo (int)$user_id; ?>;
    const SHOP_NAME = <?php echo json_encode(defined('SYSTEM_NAME') ? SYSTEM_NAME : 'Shop'); ?>;
    // Name of the person picked as the receiver ("you" when it is the logged-in user).
    function receiverLabel() {
        const sel = el('f-received-by');
        if (!sel || parseInt(sel.value, 10) === CURRENT_USER_ID) return 'you';
        return sel.options[sel.selectedIndex].text.replace(/ \((Admin|Staff)\)$/, '');
    }

    // Shows/hides the amount + reference fields for the chosen remittance method, without
    // touching their values (used both when the admin clicks a method and when the modal
    // first opens with a method already implied by what was recorded).
    function applyMethodVisibility(method) {
        document.querySelectorAll('#method-select .method-chip').forEach(b => b.classList.toggle('active', b.dataset.method === method));
        el('cash-field').style.display = method === 'cash' ? 'block' : 'none';
        el('gcash-field').style.display = method === 'gcash' ? 'block' : 'none';
        el('bank-field').style.display = method === 'bank' ? 'block' : 'none';
        el('ref-label').firstChild.textContent = method === 'bank' ? 'Bank Transfer Reference Number ' : 'GCash Reference Number ';
        recalc();
    }

    // The admin picking a method: only one method's amount is ever submitted, so the other
    // two are cleared, and the newly active field is pre-filled with the expected amount
    // (still editable) to match how the rider app behaves.
    function selectMethod(method) {
        if (method !== 'cash') el('f-cash').value = 0;
        if (method !== 'gcash') el('f-gcash').value = 0;
        if (method !== 'bank') el('f-bank').value = 0;
        applyMethodVisibility(method);
        const activeId = method === 'cash' ? 'f-cash' : (method === 'gcash' ? 'f-gcash' : 'f-bank');
        if (num(activeId) === 0 && current) el(activeId).value = current.expected;
        recalc();
    }

    // Live view of what the server will decide: difference = handed over - expected.
    function recalc() {
        const cash = num('f-cash'), gcash = num('f-gcash'), bank = num('f-bank');
        const total = Math.round((cash + gcash + bank) * 100) / 100;
        const diff = Math.round((total - current.expected) * 100) / 100;
        const off = Math.abs(diff) >= 0.005;
        el('diff-value').textContent = (diff > 0 ? '+' : '') + peso(diff);
        el('diff-value').className = off ? 'diff-neg' : 'diff-zero';
        el('diff-box').className = 'diff-box' + (off ? ' bad' : '');
        // A reference is only needed when GCash or Bank Transfer was actually used.
        el('ref-field').style.display = (gcash > 0 || bank > 0) ? 'block' : 'none';
        el('ref-hint').textContent = gcash > 0 ? '(required for GCash)' : '(optional)';
        el('reason-field').style.display = off ? 'block' : 'none';
        const btn = el('btn-confirm');
        if (btn) btn.innerHTML = off ? '<i class="fas fa-exclamation-triangle"></i> Record as Discrepancy' : '<i class="fas fa-check"></i> Confirm Received';
    }

    // Printable acknowledgement of a received remittance: who handed it over, who received it at the shop,
    // the amounts and signature lines. Built from the record already loaded in the modal.
    function printRemittance() {
        const d = current;
        if (!d) return;
        const rows = d.orders.map(o => `<tr><td>#${esc(o.order_number)}</td><td>${esc(o.customer_name || '')}</td><td class="r">${peso(o.total)}</td></tr>`).join('');
        const when = d.received_at ? esc(d.received_at) : '&mdash;';
        const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Remittance ${esc(d.number)}</title><style>
            body{font-family:Arial,Helvetica,sans-serif;color:var(--text);margin:32px;font-size:13px}
            h1{margin:0;font-size:20px} h2{margin:2px 0 18px;font-size:14px;font-weight:600;color:#555}
            table{width:100%;border-collapse:collapse;margin:10px 0}
            th,td{border:1px solid #bbb;padding:6px 8px;text-align:left} th{background:#f1f1f1} .r{text-align:right}
            .grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:14px} .grid div{padding:4px 0;border-bottom:1px dashed #ccc}
            .grid b{display:inline-block;min-width:130px;color:var(--text-2);font-weight:600}
            .sig{display:flex;gap:40px;margin-top:56px} .sig div{flex:1;text-align:center;border-top:1.5px solid #111;padding-top:6px}
            .note{margin-top:12px;padding:8px 10px;border:1px solid #f0b37e;background:#fff7ed}
            @media print{body{margin:14mm}}
        </style></head><body>
            <h1>${esc(SHOP_NAME)}</h1><h2>Rider Remittance Receipt</h2>
            <div class="grid">
                <div><b>Remittance #</b>${esc(d.number)}</div><div><b>Status</b>${esc(d.status_label)}</div>
                <div><b>Rider</b>${esc(d.rider_name)}</div><div><b>Remittance Type</b>${esc(d.type_label) || '&mdash;'}</div>
                <div><b>Transaction ID</b>${esc(d.transaction_id) || '&mdash;'}</div><div><b>Received at the shop by</b>${esc(d.received_by) || '&mdash;'}</div>
                <div><b>Date received</b>${when}</div><div><b>Recorded by</b>${esc(d.recorded_by || d.received_by) || '&mdash;'}</div>
                <div><b>${d.remittance_type === 'gcash' ? 'Actual GCash Reference' : 'Reference'}</b>${esc(d.reference_number) || '&mdash;'}</div><div><b>Orders covered</b>${d.orders.length}</div>
            </div>
            <table><thead><tr><th>Order</th><th>Customer</th><th class="r">Total</th></tr></thead><tbody>${rows}</tbody></table>
            <div class="grid">
                <div><b>Expected amount</b>${peso(d.expected)}</div><div><b>Amount handed over</b>${peso(d.breakdown.total)}</div>
                <div><b>Cash</b>${peso(d.breakdown.cash)}</div><div><b>GCash</b>${peso(d.breakdown.gcash)}</div>
                <div><b>Bank transfer</b>${peso(d.breakdown.bank)}</div><div><b>Difference</b>${peso(d.difference)}</div>
            </div>
            ${d.discrepancy_reason ? `<div class="note"><b>Discrepancy:</b> ${esc(d.discrepancy_reason).replace(/\n/g, '<br>')}</div>` : ''}
            <div class="sig"><div>${esc(d.rider_name)}<br><small>Rider</small></div><div>${esc(d.received_by) || ''}<br><small>Received by (shop)</small></div></div>
            <script>window.onload = function(){ window.print(); };<\/script>
        <\/body></html>`;
        const w = window.open('', '_blank');
        if (!w) { LiveUI.toast('Allow pop-ups to print the receipt.', 'warn'); return; }
        w.document.open(); w.document.write(html); w.document.close();
    }

    function closeModal() {
        el('remittance-modal').style.display = 'none';
        current = null;
    }

    function openReceiptModal(src) {
        el('receipt-modal-img').src = src;
        el('receipt-modal-open-tab').href = src;
        el('receipt-modal').style.display = 'block';
    }

    function closeReceiptModal() {
        el('receipt-modal').style.display = 'none';
        el('receipt-modal-img').src = '';
    }

    // Runs one modal action: disables just the modal's buttons (no double submit), then updates the table.
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
        await finish(result);
    }

    async function finish(result) {
        if (!result.success) { LiveUI.toast(result.message, 'error'); return; }   // modal stays open
        LiveUI.toast(result.message, 'success');
        closeModal();
        await refreshRemittances();      // update only the affected row(s), counts and sidebar badge
    }

    async function submitReceive(markDiscrepancy) {
        const cash = num('f-cash'), gcash = num('f-gcash'), bank = num('f-bank');
        const total = cash + gcash + bank;
        const off = Math.abs(total - current.expected) >= 0.005;
        const isDiscrepancy = off || markDiscrepancy;
        if (isDiscrepancy && !el('f-reason').value.trim() && !el('f-notes').value.trim()) {
            el('reason-field').style.display = 'block';
            LiveUI.toast('Enter the reason for the discrepancy first.', 'warn');
            el('f-reason').focus();
            return;
        }
        const question = isDiscrepancy
            ? `Record a discrepancy of ${peso(total - current.expected)}? The remittance will NOT be verified until it is resolved.`
            : `Confirm ${receiverLabel()} received ${peso(total)} in hand from ${current.rider_name}?`;
        if (!confirm(question)) return;

        run(() => api('receive_remittance', {}, {
            remittance_id: current.remittance_id,
            rider_id: current.rider_id,
            cash_amount: cash, gcash_amount: gcash, bank_amount: bank,
            reference_number: el('f-ref').value.trim(),
            notes: el('f-notes').value.trim(),
            discrepancy_reason: el('f-reason').value.trim(),
            mark_discrepancy: !!markDiscrepancy,
            received_by_user_id: parseInt(el('f-received-by').value, 10) || 0
        }));
    }

    async function submitResolve() {
        const note = el('f-action-note').value.trim();
        if (!note) { LiveUI.toast('Enter a resolution note.', 'warn'); return; }
        if (!confirm('Mark this discrepancy as resolved and verify the remittance? The orders will be settled.')) return;
        run(() => api('resolve_discrepancy', {}, {remittance_id: current.remittance_id, resolution: note}));
    }

    async function submitReject() {
        const note = el('f-action-note').value.trim();
        if (!note) { LiveUI.toast('Enter the reason for rejecting this remittance.', 'warn'); return; }
        run(() => api('reject_remittance', {}, {remittance_id: current.remittance_id, notes: note}));
    }

    // Status filter chips for the remittances table (delegated: they survive a live table swap).
    function applyFilter() {
        const active = document.querySelector('.filter-chip.active');
        const f = active ? active.dataset.filter : 'all';
        const who = el('filter-receiver') ? el('filter-receiver').value : '';
        document.querySelectorAll('#all-remittances-table tbody tr').forEach(tr => {
            const okStatus = f === 'all' || tr.dataset.status === f;
            const okWho = who === '' || tr.dataset.receiver === who;
            tr.style.display = (okStatus && okWho) ? '' : 'none';
        });
    }
    document.addEventListener('change', e => { if (e.target && e.target.id === 'filter-receiver') applyFilter(); });
    document.addEventListener('click', e => {
        const chip = e.target.closest ? e.target.closest('.filter-chip') : null;
        if (!chip) return;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.toggle('active', c === chip));
        applyFilter();
    });

    // Live refresh: fetch the page, then change only what differs - rows are keyed (data-key), so a
    // remittance can never appear twice; the selected filter chip is kept.
    let refreshing = null;
    function refreshRemittances() {
        if (refreshing) return refreshing;
        refreshing = LiveUI.fetchDoc(location.pathname + location.search).then(doc => {
            doc.querySelectorAll('[data-count]').forEach(n => {
                LiveUI.setText(document.querySelector('[data-count="' + n.dataset.count + '"]'), n.textContent);
            });
            const cur = el('remit-wrap'), fr = doc.getElementById('remit-wrap');
            if (cur && fr) {
                const curBody = cur.querySelector('#all-remittances-table tbody');
                const frBody = fr.querySelector('#all-remittances-table tbody');
                if (curBody && frBody) LiveUI.syncKeyed(curBody, frBody, 'data-key');
                else LiveUI.setHTML(cur, fr);        // "No remittances yet" <-> table
                applyFilter();
            }
            // Remittance Log (below the table) follows the same live refresh.
            const curLog = el('remit-log-wrap'), frLog = doc.getElementById('remit-log-wrap');
            if (curLog && frLog) LiveUI.setHTML(curLog, frLog);
            LiveUI.syncSidebar(doc);
        }).catch(err => { if (err && err.code === 'auth') return; }).then(() => { refreshing = null; });
        return refreshing;
    }

    // Pick up remittances submitted by riders: a tiny probe every ~5s; the list is re-read only when the
    // remittance fingerprint changed, and never while a dialog is open or a request is running.
    LiveUI.watch('remittance', () => {
        if (submitting || el('remittance-modal').style.display === 'block' || el('receipt-modal').style.display === 'block') return;
        return refreshRemittances();
    }, { fallbackMs: 20000 });

    window.onclick = function(event) {
        if (event.target === el('remittance-modal')) closeModal();
        if (event.target === el('receipt-modal')) closeReceiptModal();
    };
    </script>
    <script>
        // Tabs: Remittances | Remittance Log (remembers the last one; #log / #remittances also work).
        // Both panels stay in the page, so the live refresh keeps updating the hidden one too.
        (function () {
            var tabs = document.querySelectorAll('.page-tab');
            function show(name, save) {
                if (!document.getElementById('panel-' + name)) name = 'remittances';
                tabs.forEach(function (t) {
                    var on = t.dataset.tab === name;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    document.getElementById('panel-' + t.dataset.tab).hidden = !on;
                });
                if (save) { try { localStorage.setItem('remittanceTab', name); } catch (e) {} }
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab, true); }); });
            var start = location.hash.replace('#', '');
            if (start !== 'log' && start !== 'remittances') { try { start = localStorage.getItem('remittanceTab') || ''; } catch (e) { start = ''; } }
            show(start, false);
        })();
    </script>
</body>
</html>
