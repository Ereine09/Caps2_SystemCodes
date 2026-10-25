<?php
/**
 * Suppliers & Deliveries: supplier directory, purchase orders, stock receiving, receiving history,
 * inventory batches (FEFO) and supplier purchase cost history.
 *
 * Ordering never changes stock. Stock goes up only when a delivery is RECEIVED here (Receive
 * Delivery): the accepted units become a new inventory batch with its own cost / lot / expiry, in
 * one transaction (app/helpers/purchasing_helper.php, app/helpers/inventory_batch_helper.php).
 *
 * Admin: everything. Staff (modules/staff/delivery.php, this page in staff mode): view, create and
 * submit purchase orders, mark In Transit and receive deliveries. Cancelling, closing with a
 * shortage, reversing receipts, batch write-offs and the supplier directory stay with the admin.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/purchasing_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    ajax_auth_required(); // AJAX callers get a JSON 401 instead of the login page
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));
$staff_mode = function_exists('staff_portal_mode') && staff_portal_mode();

// Admin, or staff through their own portal page (modules/staff/delivery.php).
if ($role !== 'admin' && !($role === 'staff' && $staff_mode)) {
    if (is_ajax_request()) {
        ajax_fail('You do not have permission to do that.', 403);
    }
    header("Location: " . BASE_URL . ($role === 'staff' ? '/modules/staff/delivery.php' : '/modules/staff/dashboard.php'));
    exit();
}
$is_admin = $role === 'admin';
$actor = ['role' => $role, 'user_id' => $user_id];

purch_ensure_schema($conn);

// Modal contents (purchase order, receive form, receipt, batch, supplier profile ...): HTML, then exit.
if (isset($_GET['fragment'])) {
    require __DIR__ . '/delivery_fragments.php';
    delivery_render_fragment($conn, $actor, (string) $_GET['fragment']);
    exit();
}

// Handle POST actions
$message = '';
$error = '';
$warning = null;
$saved = false;      // the action changed something, even if a follow-up (email) failed
$next = null;        // fragment the page opens after success (e.g. the new receipt)
$new_product = null; // "+ New product" in a purchase order: the product it created

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $admin_only = ['save_supplier', 'toggle_supplier', 'reverse_receipt', 'batch_status', 'batch_edit', 'link_legacy'];
    if (in_array($action, $admin_only, true) && !$is_admin) {
        $error = 'Only an admin can do that.';
        $action = '';
    }

    if ($action === 'save_supplier') {
        $sid       = (int) ($_POST['supplier_id'] ?? 0);
        $s_name    = trim($_POST['supplier_name'] ?? '');
        $s_brand   = trim($_POST['brand_name'] ?? '');
        $s_person  = trim($_POST['contact_person'] ?? '');
        $s_email   = trim($_POST['email'] ?? '');
        $s_phone   = trim($_POST['phone'] ?? '');
        $s_address = trim($_POST['address'] ?? '');
        $s_notes   = trim($_POST['supplier_notes'] ?? '');

        if ($s_name === '') {
            $error = 'Supplier Name is required.';
        } elseif ($s_email !== '' && !filter_var($s_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid supplier email address.';
        } else {
            // Store blanks as NULL so the table stays clean.
            $b = $s_brand   !== '' ? $s_brand   : null;
            $p = $s_person  !== '' ? $s_person  : null;
            $e = $s_email   !== '' ? $s_email   : null;
            $ph = $s_phone  !== '' ? $s_phone   : null;
            $a = $s_address !== '' ? $s_address : null;
            $n = $s_notes   !== '' ? $s_notes   : null;

            if ($sid > 0) {
                $stmt = $conn->prepare("UPDATE tbl_suppliers SET supplier_name=?, brand_name=?, contact_person=?, email=?, phone=?, address=?, notes=? WHERE id=?");
                $stmt->bind_param('sssssssi', $s_name, $b, $p, $e, $ph, $a, $n, $sid);
                if ($stmt->execute()) {
                    $message = "Supplier \"" . $s_name . "\" updated.";
                    $saved = true;
                } else {
                    $error = 'Could not update the supplier.';
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO tbl_suppliers (supplier_name, brand_name, contact_person, email, phone, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssss', $s_name, $b, $p, $e, $ph, $a, $n);
                if ($stmt->execute()) {
                    $message = "Supplier \"" . $s_name . "\" added to the directory.";
                    $saved = true;
                } else {
                    $error = 'Could not add the supplier.';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'toggle_supplier') {
        // Deactivating only hides the supplier from new purchase orders; their history stays.
        $sid = (int) ($_POST['supplier_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $conn->prepare("UPDATE tbl_suppliers SET is_active = 1 - is_active WHERE id = ?");
            $stmt->bind_param('i', $sid);
            $stmt->execute();
            $stmt->close();
            $message = 'Supplier status updated.';
            $saved = true;
        }
    } else {
        $r = null;
        switch ($action) {
            case 'save_po':
                $r = purch_save_po($conn, $actor, $_POST, ($_POST['submit_mode'] ?? '') === 'submit', (int) ($_POST['po_id'] ?? 0));
                if ($r['ok']) {
                    $next = 'po&id=' . (int) $r['po_id'];
                }
                break;
            case 'po_status':
                $r = purch_change_status($conn, $actor, (int) ($_POST['po_id'] ?? 0), (string) ($_POST['op'] ?? ''), (string) ($_POST['reason'] ?? ''));
                break;
            case 'receive':
                $r = purch_receive($conn, $actor, (int) ($_POST['po_id'] ?? 0), [
                    'token'       => $_POST['token'] ?? '',
                    'received_at' => $_POST['received_at'] ?? '',
                    'remarks'     => $_POST['remarks'] ?? '',
                    'verified'    => !empty($_POST['verified']),
                    'lines'       => $_POST['lines'] ?? [],
                ]);
                if ($r['ok'] && !empty($r['receipt_id'])) {
                    $next = 'receipt&id=' . (int) $r['receipt_id'];
                }
                break;
            case 'reverse_receipt':
                $r = purch_reverse_receipt($conn, $actor, (int) ($_POST['receipt_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
                break;
            case 'batch_status':
                $r = inv_batch_set_status($conn, (int) ($_POST['batch_id'] ?? 0), (string) ($_POST['new_status'] ?? ''), (string) ($_POST['reason'] ?? ''), $user_id);
                break;
            case 'batch_edit':
                $r = inv_batch_update_details($conn, (int) ($_POST['batch_id'] ?? 0), (string) ($_POST['expiry_date'] ?? ''), (string) ($_POST['lot_number'] ?? ''), (string) ($_POST['reason'] ?? ''), $user_id);
                break;
            case 'link_legacy':
                $r = purch_link_legacy($conn, $actor, (int) ($_POST['po_id'] ?? 0), $_POST);
                break;
            case 'quick_product':
                $r = purch_quick_product($conn, $actor, $_POST);
                $new_product = $r['product'] ?? null;
                break;
            default:
                if ($error === '') {
                    $error = 'Unknown action.';
                }
        }
        if ($r !== null) {
            if ($r['ok']) {
                $message = $r['message'];
                $saved = empty($r['duplicate']);
                $warning = $r['warning'] ?? null;
            } else {
                $error = $r['message'];
            }
        }
    }

    // AJAX: answer with JSON so the page patches only what changed instead of reloading.
    if (is_ajax_request()) {
        if ($error !== '') {
            ajax_fail($error, 422);
        }
        ajax_json([
            'success' => true,
            'message' => $message !== '' ? $message : 'Saved.',
            'warning' => $warning,   // e.g. the order was placed but the supplier email could not be sent
            'next'    => $next,
            'product' => $new_product,
        ]);
    }
}

// Batches past their expiry leave sellable stock; stock edited elsewhere is mirrored into batches.
inv_batches_reconcile_all($conn);
inv_expire_due_batches($conn);

// Fetch order metrics for sidebar badge & headers
$order_summary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_orders, SUM(total) AS total_sales, SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders FROM tbl_orders"));
if (!$order_summary) {
    $order_summary = ['total_orders' => 0, 'total_sales' => 0, 'pending_orders' => 0, 'completed_orders' => 0];
}
$order_summary = array_map(function ($value) {
    return $value === null ? 0 : $value;
}, $order_summary);

$unread_count = get_unread_count_staff($user_id);

// Supplier Directory, with purchase order / receipt counts per supplier
$suppliers = [];
$suppliers_query = mysqli_query($conn, "
    SELECT s.*,
        (SELECT COUNT(*) FROM tbl_supplier_deliveries d WHERE d.supplier_id = s.id) AS delivery_count,
        (SELECT COUNT(*) FROM tbl_supplier_deliveries d WHERE d.supplier_id = s.id AND d.status <> 'draft') AS po_count,
        (SELECT COUNT(*) FROM tbl_stock_receipts r WHERE r.supplier_id = s.id AND r.status = 'confirmed') AS receipt_count,
        (SELECT COALESCE(SUM(r.total_cost), 0) FROM tbl_stock_receipts r WHERE r.supplier_id = s.id AND r.status = 'confirmed') AS purchase_total
    FROM tbl_suppliers s
    ORDER BY s.is_active DESC, s.supplier_name ASC");
if ($suppliers_query) {
    while ($row = mysqli_fetch_assoc($suppliers_query)) {
        $suppliers[] = $row;
    }
}
$active_suppliers = array_values(array_filter($suppliers, static fn ($s) => (int) $s['is_active'] === 1));

$deliveries = purch_list_pos($conn);
$receipts = purch_list_receipts($conn);
$batches = purch_list_batches($conn);
$batch_summary = purch_batch_summary($conn);
$cost_history = purch_cost_history($conn);

// Deliveries status breakdown (the original six cards, plus Partially Received)
$delivery_counts = ['total' => count($deliveries), 'pending' => 0, 'in_transit' => 0, 'partially_received' => 0, 'delivered' => 0];
foreach ($deliveries as $d) {
    $st = $d['status'];
    if ($st === 'draft') {
        $st = 'pending';
    } elseif ($st === 'closed_short') {
        $st = 'delivered';
    }
    if (isset($delivery_counts[$st])) {
        $delivery_counts[$st]++;
    }
}
$supplier_counts = [
    'total'  => count($suppliers),
    'active' => count($active_suppliers),
];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$today = date('Y-m-d');
$near_limit = date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers &amp; Deliveries - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        body { overflow-x: hidden; margin: 0; background-color: var(--surface-2); }

        .delivery-container {
            background: var(--surface); padding: 25px; border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 30px;
        }
        .header-action { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .header-action h2 { margin: 0; color: var(--text); }
        .header-action > div { flex: 1 1 320px; min-width: 0; }
        .header-action > .btn-add { flex: 0 0 auto; align-self: flex-start; }
        .header-action .sub { margin: 4px 0 0; color: var(--muted); font-size: 0.88rem; line-height: 1.5; }

        .btn-add {
            background: var(--dk-primary, #2563eb); color: var(--dk-on-primary, white); padding: 10px 18px; border-radius: 8px;
            text-decoration: none; font-weight: 600; display: inline-flex; align-items: center;
            gap: 8px; border: none; cursor: pointer; transition: background 0.2s;
        }
        .btn-add:hover { background: var(--dk-primary-hover, #1d4ed8); }
        .btn-add:disabled { opacity: .6; cursor: not-allowed; }
        .btn-plain { padding: 9px 16px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; font-weight: 600; }

        .tab-panel[hidden] { display: none; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--surface-2); border: 1px solid var(--border); padding: 15px 20px; border-radius: 10px; display: flex; align-items: center; gap: 15px; }
        .stat-card i { font-size: 1.7rem; color: var(--info); }
        .stat-info h4 { margin: 0; font-size: 0.82rem; color: var(--muted); }
        .stat-info p { margin: 5px 0 0 0; font-size: 1.3rem; font-weight: bold; color: var(--text); }

        .delivery-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .delivery-table th, .delivery-table td {
            padding: 12px 13px; text-align: left; border-bottom: 1px solid var(--border);
            font-size: 0.9rem; vertical-align: top;
        }
        .delivery-table th { background-color: var(--surface-2); color: var(--text-2); font-weight: 600; white-space: nowrap; }
        .delivery-table td.num, .delivery-table th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .delivery-table tr:hover td { background: #fcfdff; }
        .cell-strong { font-weight: 700; color: var(--text); }
        .cell-muted { color: var(--muted); font-size: 0.83rem; }
        .contact-lines { line-height: 1.55; }
        .contact-lines a { color: var(--info); text-decoration: none; }
        .contact-lines a:hover { text-decoration: underline; }
        .items-lines { line-height: 1.5; white-space: pre-line; font-size: .86rem; }
        .progress { height: 6px; border-radius: 99px; background: var(--surface-2); border: 1px solid var(--border); overflow: hidden; margin-top: 6px; min-width: 90px; }
        .progress > span { display: block; height: 100%; background: #16a34a; }

        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; display: inline-block; white-space: nowrap; }
        .badge-draft { background: var(--surface-2); color: var(--text-2); border: 1px dashed var(--border); }
        .badge-pending { background: var(--warning-soft); color: var(--warning); }
        .badge-in_transit { background: var(--info-soft); color: var(--info); }
        .badge-partially_received { background: #fef3c7; color: #92400e; }
        .badge-delivered { background: var(--success-soft); color: var(--success); }
        .badge-closed_short { background: #ffedd5; color: #9a3412; }
        .badge-cancelled { background: var(--danger-soft); color: var(--danger); }
        .badge-active, .badge-available, .badge-confirmed { background: var(--success-soft); color: var(--success); }
        .badge-inactive, .badge-reversed { background: var(--surface-2); color: var(--muted); }
        .badge-quarantined { background: #fef3c7; color: #92400e; }
        .badge-expired, .badge-damaged, .badge-recalled { background: var(--danger-soft); color: var(--danger); }
        .badge-legacy { background: #ede9fe; color: #5b21b6; }
        .badge-history { background: #e0f2fe; color: #075985; }
        .badge-near { background: #fef3c7; color: #92400e; }
        .badge-next { background: #dcfce7; color: #166534; }
        .brand-chip { background: var(--primary-soft); color: var(--link); padding: 2px 8px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; display: inline-block; }

        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
        .alert-error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }
        .alert-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
        .alert-warn button { margin-left: auto; }

        .action-buttons-group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .btn-action {
            padding: 6px 11px; border-radius: 7px; font-size: 0.78rem; font-weight: 600;
            border: 1px solid transparent; cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); outline: none; white-space: nowrap;
        }
        .btn-approve { background-color: var(--success-soft); color: var(--success); border-color: #a7f3d0; }
        .btn-approve:hover { background-color: #059669; color: #fff; border-color: #059669; transform: translateY(-1px); }
        .btn-cancel { background-color: var(--danger-soft); color: var(--danger); border-color: #fecaca; }
        .btn-cancel:hover { background-color: #dc2626; color: #fff; border-color: #dc2626; transform: translateY(-1px); }
        .btn-edit { background-color: var(--info-soft); color: var(--info); border-color: #bfdbfe; }
        .btn-edit:hover { background-color: var(--dk-primary, #2563eb); color: var(--dk-on-primary, #fff); border-color: var(--dk-primary, #2563eb); transform: translateY(-1px); }
        .btn-muted { background-color: var(--surface-2); color: var(--text-2); border-color: var(--border); }
        .btn-muted:hover { background-color: #e2e8f0; }
        .btn-warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .btn-warn:hover { background: #d97706; color: #fff; border-color: #d97706; }
        .text-action-disabled { color: var(--muted); font-size: 0.83rem; display: inline-flex; align-items: center; gap: 5px; font-style: italic; }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000;
            padding: 20px; box-sizing: border-box; overflow-y: auto;
        }
        .modal-content {
            background: var(--surface); padding: 25px; border-radius: 12px; width: 100%;
            max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); margin: auto; box-sizing: border-box;
        }
        .modal-content.wide { max-width: 640px; }
        .modal-content.xwide { max-width: 1000px; }
        .modal-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 10px; }
        .modal-title h3 { margin: 0; }
        .modal-close { cursor: pointer; font-weight: bold; font-size: 1.3rem; color: var(--muted); line-height: 1; background: none; border: 0; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-2); font-size: 0.88rem; }
        .form-group .hint { font-weight: 400; color: var(--muted); font-size: 0.8rem; }
        .form-group input, .form-group textarea, .form-group select, .mini-field input, .mini-field select, .mini-field textarea {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;
            box-sizing: border-box; font-family: inherit; font-size: 0.92rem; background: var(--surface); color: var(--text);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        @media (max-width: 700px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
        .empty-state .empty-help { max-width: 460px; margin: 6px auto 0; font-size: 0.85rem; line-height: 1.5; }

        /* Help / description text */
        .stat-info small { display: block; margin-top: 3px; font-size: 0.72rem; line-height: 1.3; color: var(--muted); }
        .info-note {
            display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; padding: 12px 14px;
            background: var(--info-soft); border: 1px solid #bfdbfe; color: var(--info); border-radius: 10px;
            font-size: 0.85rem; line-height: 1.5;
        }
        .info-note i { margin-top: 3px; }
        .info-note p { margin: 0; }
        .status-guide { margin-bottom: 16px; }
        .status-guide summary { cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--info); width: fit-content; }
        .status-guide-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 10px; }
        .status-guide-grid > div { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; background: var(--surface-2); font-size: 0.82rem; line-height: 1.45; color: var(--text-2); }
        .status-guide-grid .badge { margin-bottom: 6px; }
        th[title] { cursor: help; }
        .field-help { display: block; margin-top: 5px; font-size: 0.78rem; line-height: 1.4; font-weight: 400; color: var(--muted); }
        .modal-intro { margin: -8px 0 18px; color: var(--muted); font-size: 0.88rem; line-height: 1.5; }

        /* Filters above a table */
        /* Paging under each table (10 rows a page; works with the search box and status filter) */
        .dl-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
        .dl-pager[hidden] { display: none; }
        .dl-pager-info { font-size: .84rem; color: var(--muted); }
        .dl-pager-nav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .dl-pager-nav button { min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .dl-pager-nav button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .dl-pager-nav button.is-active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .dl-pager-nav button:disabled { opacity: .4; cursor: not-allowed; }
        .dl-pager-nav .dl-gap { color: var(--muted); padding: 0 4px; }
        @media (max-width: 640px) { .dl-pager { justify-content: center; } .dl-pager-label { display: none; } }
        .table-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; }
        .table-tools input, .table-tools select { padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: .88rem; }
        .table-tools input[type="search"] { flex: 1 1 240px; }
        .table-tools .count { color: var(--muted); font-size: .84rem; margin-left: auto; }

        /* Modal contents (delivery_fragments.php) */
        .frag-error { color: var(--danger); }
        .form-errors { display: none; background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: .88rem; line-height: 1.5; }
        .form-errors.show { display: block; }
        .kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px 18px; margin-bottom: 16px; }
        .kv div { font-size: .88rem; }
        .kv span { display: block; font-size: .74rem; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 2px; }
        .frag-section { margin: 18px 0 8px; font-size: 1rem; }
        .frag-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 16px; }
        .frag-box { border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-top: 14px; background: var(--surface-2); }
        .frag-box h4 { margin: 0 0 10px; font-size: .92rem; }
        .mini-field { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; color: var(--text-2); }
        .mini-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .mini-stats div { background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
        .mini-stats span { display: block; font-size: .74rem; color: var(--muted); }
        .mini-stats strong { font-size: 1.1rem; }

        /* Purchase order lines */
        .po-lines { display: grid; gap: 8px; margin-bottom: 8px; }
        .po-line { display: grid; grid-template-columns: minmax(0, 3fr) 90px 120px 120px 36px; gap: 8px; align-items: end; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
        .po-line .item-search { margin-bottom: 4px; padding-left: 32px !important; background: var(--surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512' width='14' height='14'%3E%3Cpath fill='%2394a3b8' d='M505 442.7 405.3 343c-4.5-4.5-10.6-7-17-7H372c27.6-35.3 44-79.7 44-128C416 93.1 322.9 0 208 0S0 93.1 0 208s93.1 208 208 208c48.3 0 92.7-16.4 128-44v16.3c0 6.4 2.5 12.5 7 17l99.7 99.7c9.4 9.4 24.6 9.4 33.9 0l28.3-28.3c9.4-9.4 9.4-24.6.1-34zM208 336c-70.7 0-128-57.2-128-128 0-70.7 57.2-128 128-128 70.7 0 128 57.2 128 128 0 70.7-57.2 128-128 128z'/%3E%3C/svg%3E") no-repeat 11px center; }
        .po-line .item-search:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); outline: none; }
        .po-line .item-search-none { font-size: .76rem; color: var(--danger); }
        .po-line .link-new-product { justify-self: start; margin-top: 2px; padding: 2px 0; border: 0; background: none; color: var(--primary); font: inherit; font-size: .76rem; font-weight: 700; cursor: pointer; }
        .po-line .link-new-product:hover { text-decoration: underline; }
        .qp-panel { padding: 12px; border: 2px solid var(--primary); border-radius: 12px; background: var(--surface); box-shadow: 0 6px 18px rgba(79, 70, 229, .12); }
        .qp-head { display: flex; flex-direction: column; gap: 2px; margin-bottom: 10px; }
        .qp-head strong { color: var(--primary); }
        .qp-head span { font-size: .78rem; color: var(--muted); }
        .qp-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .qp-grid .qp-wide { grid-column: 1 / -1; }
        .qp-error { color: var(--danger); font-size: .82rem; font-weight: 600; margin-top: 6px; }
        .qp-error:empty { display: none; }
        .qp-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        @media (max-width: 700px) { .qp-grid { grid-template-columns: 1fr 1fr; } }
        .po-line .line-total { font-weight: 700; padding: 9px 0; text-align: right; font-variant-numeric: tabular-nums; }
        .po-line .line-hint { grid-column: 1 / -1; font-size: .76rem; color: var(--muted); margin-top: -2px; }
        .po-line .line-remove { height: 38px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--danger); cursor: pointer; }
        .po-total { display: flex; justify-content: flex-end; gap: 22px; font-size: .95rem; margin: 6px 0 14px; flex-wrap: wrap; }
        .po-total strong { font-size: 1.1rem; }
        @media (max-width: 700px) {
            .po-line { grid-template-columns: 1fr 1fr; }
            .po-line .mini-field:first-child { grid-column: 1 / -1; }
            .po-line .line-total { text-align: left; }
        }

        /* Receive form */
        .rcv-line { border: 1px solid var(--border); border-radius: 10px; padding: 12px; margin-bottom: 10px; background: var(--surface-2); }
        .rcv-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .rcv-head strong { font-size: .95rem; }
        .rcv-counts { display: flex; gap: 12px; flex-wrap: wrap; font-size: .8rem; color: var(--muted); }
        .rcv-counts b { color: var(--text); }
        .rcv-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .rcv-hint { margin-top: 8px; font-size: .8rem; color: var(--muted); }
        .rcv-hint.is-bad { color: var(--danger); font-weight: 600; }
        .rcv-check { display: flex; gap: 8px; align-items: flex-start; font-size: .82rem; font-weight: 600; margin-top: 2px; }
        .rcv-check input { width: auto; margin-top: 2px; }
        .verify-box { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 2px solid #fde68a; background: #fffbeb; color: #92400e; border-radius: 10px; font-size: .88rem; font-weight: 600; }
        .verify-box input { width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0; }
        @media (max-width: 800px) { .rcv-grid { grid-template-columns: 1fr 1fr; } }
        .up { color: var(--danger); font-weight: 700; }   /* cost went up */
        .down { color: var(--success); font-weight: 700; } /* cost went down */

        body.dark-mode .info-note { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.35); }
        body.dark-mode .delivery-container .info-note { color: var(--info); }
        body.dark-mode .delivery-container .status-guide summary { color: var(--dm-link, var(--primary)); }
        body.dark-mode .delivery-container .status-guide-grid > div { background: var(--dm-surface-2); border-color: var(--dm-border); color: var(--dm-text-muted); }
        body.dark-mode .stat-card .stat-info small,
        body.dark-mode .delivery-container .empty-help { color: var(--dm-text-muted); }
        body.dark-mode .field-help, body.dark-mode .modal-intro { color: var(--dm-text-muted); }
        body.dark-mode .delivery-table tr:hover td { background: transparent; }
        /* The dark theme forces inherited text color inside .delivery-container; keep these pale chips readable. */
        body.dark-mode .delivery-container .badge-active, body.dark-mode .delivery-container .badge-available,
        body.dark-mode .delivery-container .badge-confirmed, body.dark-mode .delivery-container .badge-next { color: #15803d; }
        body.dark-mode .delivery-container .badge-inactive, body.dark-mode .delivery-container .badge-reversed { color: #64748b; }
        body.dark-mode .delivery-container .badge-quarantined, body.dark-mode .delivery-container .badge-near,
        body.dark-mode .delivery-container .badge-partially_received { color: #92400e; }
        body.dark-mode .delivery-container .badge-closed_short { color: #9a3412; }
        body.dark-mode .delivery-container .badge-legacy { color: #5b21b6; }
        body.dark-mode .delivery-container .badge-history { color: #075985; }
        body.dark-mode .delivery-container .btn-approve { color: #047857; }
        body.dark-mode .delivery-container .btn-approve:hover { color: #fff; }
        body.dark-mode .verify-box, body.dark-mode .alert-warn { background: rgba(217,119,6,.12); border-color: rgba(217,119,6,.45); color: #fbbf24; }
    </style>
</head>
<body>
    <?php
    // Sidebar badge only — deliberately a SEPARATE query from $order_summary
    // above (which still reports the true pending-order business metric on
    // this page, untouched). This one only counts pending orders an admin
    // hasn't opened yet, so it clears on view independently of order status.
    $pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"
    ))['count'] ?? 0);
    include __DIR__ . '/../customers/sidebar.php';
    ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Suppliers &amp; Deliveries 🚚</h1>
            <p>Order stock from suppliers, receive deliveries into inventory, and track every batch, its cost and its expiration date.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($warning)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($warning); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $h($error); ?></div>
        <?php endif; ?>

        <div id="expiry-alert">
        <?php if ((int) $batch_summary['near_batches'] > 0 || (int) $batch_summary['held_units'] > 0): ?>
            <div class="alert alert-warn">
                <i class="fas fa-hourglass-half" style="margin-top:3px;"></i>
                <div>
                    <?php if ((int) $batch_summary['near_batches'] > 0): ?>
                        <strong><?php echo (int) $batch_summary['near_batches']; ?> batch<?php echo (int) $batch_summary['near_batches'] === 1 ? '' : 'es'; ?> (<?php echo number_format((int) $batch_summary['near_units']); ?> units)</strong> expire within <?php echo INV_NEAR_EXPIRY_DAYS; ?> days &mdash; they are sold first (FEFO). Expired batches are taken off sale automatically.
                    <?php endif; ?>
                    <?php if ((int) $batch_summary['held_units'] > 0): ?>
                        <div><strong><?php echo number_format((int) $batch_summary['held_units']); ?> unit(s)</strong> are on hold for inspection and cannot be sold until an admin releases them.</div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-action btn-warn" data-goto-tab="batches" data-batch-filter="<?php echo (int) $batch_summary['near_batches'] > 0 ? 'near' : 'quarantined'; ?>"><i class="fas fa-layer-group"></i> View batches</button>
            </div>
        <?php endif; ?>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-truck-field"></i>
                <div class="stat-info"><h4>Suppliers</h4><p data-stat="suppliers_total"><?php echo $supplier_counts['total']; ?></p><small>Saved in your directory</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-circle-check" style="color: #16a34a;"></i>
                <div class="stat-info"><h4>Active Suppliers</h4><p data-stat="suppliers_active"><?php echo $supplier_counts['active']; ?></p><small>Can be picked for new orders</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-boxes-packing"></i>
                <div class="stat-info"><h4>Total Deliveries</h4><p data-stat="deliveries_total"><?php echo $delivery_counts['total']; ?></p><small>All purchase orders, incl. cancelled</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock" style="color: #d97706;"></i>
                <div class="stat-info"><h4>Pending</h4><p data-stat="deliveries_pending"><?php echo $delivery_counts['pending']; ?></p><small>Drafts and orders not yet shipped</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-truck-fast" style="color: #2563eb;"></i>
                <div class="stat-info"><h4>In Transit</h4><p data-stat="deliveries_in_transit"><?php echo $delivery_counts['in_transit']; ?></p><small>On the way from the supplier</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-box-open" style="color: #b45309;"></i>
                <div class="stat-info"><h4>Partially Received</h4><p data-stat="deliveries_partial"><?php echo $delivery_counts['partially_received']; ?></p><small>Some items still outstanding</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-circle-check" style="color: #16a34a;"></i>
                <div class="stat-info"><h4>Delivered</h4><p data-stat="deliveries_delivered"><?php echo $delivery_counts['delivered']; ?></p><small>Fully received or closed</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-coins" style="color: #7c3aed;"></i>
                <div class="stat-info"><h4>Stock Value</h4><p data-stat="stock_value"><?php echo $h(purch_money((float) $batch_summary['value'])); ?></p><small>Sellable batches at purchase cost</small></div>
            </div>
        </div>

        <div class="page-tabs ui-tabs ui-tabs-fill" role="tablist">
            <button type="button" class="page-tab active" role="tab" id="tab-deliveries" data-tab="deliveries" aria-selected="true" aria-controls="panel-deliveries"><i class="fas fa-truck-fast"></i> Incoming Deliveries</button>
            <button type="button" class="page-tab" role="tab" id="tab-receiving" data-tab="receiving" aria-selected="false" aria-controls="panel-receiving"><i class="fas fa-clipboard-check"></i> Receiving History</button>
            <button type="button" class="page-tab" role="tab" id="tab-batches" data-tab="batches" aria-selected="false" aria-controls="panel-batches"><i class="fas fa-layer-group"></i> Inventory Batches</button>
            <button type="button" class="page-tab" role="tab" id="tab-costs" data-tab="costs" aria-selected="false" aria-controls="panel-costs"><i class="fas fa-chart-line"></i> Cost History</button>
            <button type="button" class="page-tab" role="tab" id="tab-suppliers" data-tab="suppliers" aria-selected="false" aria-controls="panel-suppliers"><i class="fas fa-address-book"></i> Supplier Directory</button>
        </div>

        <!-- ===================== INCOMING DELIVERIES (PURCHASE ORDERS) ===================== -->
        <div class="delivery-container tab-panel" id="panel-deliveries" role="tabpanel" aria-labelledby="tab-deliveries">
            <div class="header-action">
                <div>
                    <h2>Incoming Deliveries</h2>
                    <p class="sub">Every stock order is a <strong>purchase order</strong> listing real products and sizes, quantities and agreed costs. Placing an order or marking it In Transit does <strong>not</strong> change stock. When the shipment arrives, click <strong>Receive</strong>, count the items and confirm: only the accepted quantity is added to stock, as a new inventory batch with its own cost and expiration date. Partial deliveries are fine &mdash; the rest stays outstanding.</p>
                </div>
                <button class="btn-add" type="button" data-frag="po_form" data-wide="x"><i class="fas fa-plus"></i> New Purchase Order</button>
            </div>

            <details class="status-guide">
                <summary>What do the purchase order statuses mean?</summary>
                <div class="status-guide-grid">
                    <div><span class="badge badge-draft">Draft</span><br>Saved but not ordered yet. You can still edit it. Submitting it emails the supplier (if they have an email).</div>
                    <div><span class="badge badge-pending">Ordered</span><br>Placed with the supplier, not shipped yet. Stock is unchanged.</div>
                    <div><span class="badge badge-in_transit">In Transit</span><br>The supplier shipped it. Stock is still unchanged until you receive it.</div>
                    <div><span class="badge badge-partially_received">Partially Received</span><br>Some items arrived and were added to stock. Receive the rest when it comes, or close it with a shortage.</div>
                    <div><span class="badge badge-delivered">Received</span><br>Every ordered unit was received and is in stock. Final.</div>
                    <div><span class="badge badge-closed_short">Closed with Shortage</span><br>Part was received; the rest will not come. Final &mdash; the shortage stays on record.</div>
                    <div><span class="badge badge-cancelled">Cancelled</span><br>Called off before anything was received. Final.</div>
                    <div><span class="badge badge-legacy">Old record</span><br>Recorded before purchase orders, as free text. An admin can link it to products; it never changes stock by itself.</div>
                </div>
            </details>

            <div id="deliveries-wrap">
            <?php if (empty($deliveries)): ?>
                <div class="empty-state">
                    <i class="fas fa-truck-ramp-box" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No purchase orders yet. Click "New Purchase Order" to order stock from a supplier.</p>
                    <p class="empty-help">Pick an active supplier, add the products and sizes you need with quantities and unit costs, and the totals are worked out for you.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="delivery-table" id="deliveries-table">
                        <thead>
                            <tr>
                                <th title="Purchase order number and the date it was created">PO No.</th>
                                <th title="Who the stock was ordered from">Supplier</th>
                                <th title="The products ordered, and how much has been received so far">Items</th>
                                <th class="num" title="Total of the agreed unit costs">Amount</th>
                                <th title="Supplier tracking code or invoice number">Tracking No.</th>
                                <th title="The date you expect the shipment to arrive">Expected</th>
                                <th title="Current stage of the purchase order">Status</th>
                                <th title="What you can do next with this purchase order">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $item):
                                $pid = (int) $item['id'];
                                $st = (string) $item['status'];
                                $legacy = (int) $item['is_legacy'] === 1;
                                $ordered = (int) $item['qty_ordered'];
                                $got = (int) $item['qty_received'];
                                $pct = $ordered > 0 ? min(100, round($got / $ordered * 100)) : 0;
                                $overdue = !empty($item['expected_date']) && $item['expected_date'] < $today && in_array($st, ['pending', 'in_transit', 'partially_received'], true);
                            ?>
                                <tr data-delivery-id="<?php echo $pid; ?>">
                                    <td>
                                        <span class="cell-strong"><?php echo $h($item['po_number']); ?></span>
                                        <div class="cell-muted"><?php echo date('M d, Y', strtotime($item['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <span class="cell-strong"><?php echo $h($item['supplier_name']); ?></span>
                                        <?php if (!empty($item['supplier_brand'])): ?>
                                            <div><span class="brand-chip"><?php echo $h($item['supplier_brand']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['supplier_phone'])): ?>
                                            <div class="cell-muted"><i class="fas fa-phone" style="width:14px;"></i> <?php echo $h($item['supplier_phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($legacy): ?>
                                            <span class="badge badge-legacy">Old record &middot; not linked</span>
                                            <div class="items-lines"><?php echo $h($item['items_summary']); ?></div>
                                        <?php else: ?>
                                            <div class="cell-strong"><?php echo (int) $item['line_count']; ?> item<?php echo (int) $item['line_count'] === 1 ? '' : 's'; ?> &middot; <?php echo number_format($got); ?> / <?php echo number_format($ordered); ?> received</div>
                                            <?php if ((int) $item['qty_rejected'] > 0): ?><div class="cell-muted"><?php echo (int) $item['qty_rejected']; ?> rejected</div><?php endif; ?>
                                            <div class="progress" title="<?php echo $pct; ?>% received"><span style="width: <?php echo $pct; ?>%"></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?php echo $legacy ? '<span class="cell-muted">&mdash;</span>' : $h(purch_money((float) $item['total_amount'])); ?></td>
                                    <td><code><?php echo $h($item['tracking_number'] ?: 'N/A'); ?></code></td>
                                    <td>
                                        <?php echo $item['expected_date'] ? date('M d, Y', strtotime($item['expected_date'])) : '—'; ?>
                                        <?php if ($overdue): ?><div class="cell-muted" style="color:var(--danger)">Overdue</div><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $h($st); ?>"><?php echo $h(PURCH_STATUS_LABELS[$st] ?? $st); ?></span>
                                        <?php if ($st === 'delivered' && $item['received_date']): ?><div class="cell-muted"><?php echo date('M d, Y', strtotime($item['received_date'])); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-group">
                                            <button type="button" class="btn-action btn-muted" data-frag="po&id=<?php echo $pid; ?>" data-wide="x" title="Purchase order details, lines and receipts"><i class="fas fa-eye"></i> View</button>
                                            <?php if ($legacy): ?>
                                                <?php if ($is_admin && $st !== 'cancelled'): ?>
                                                    <button type="button" class="btn-action btn-edit" data-frag="link&id=<?php echo $pid; ?>" data-wide="x" title="Link this old free-text record to real products"><i class="fas fa-link"></i> Link Items</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($st === 'draft'): ?>
                                                    <button type="button" class="btn-action btn-edit" data-frag="po_form&id=<?php echo $pid; ?>" data-wide="x"><i class="fas fa-pen"></i> Edit</button>
                                                    <form method="POST" style="margin:0" data-confirm="Submit this purchase order to the supplier? It can no longer be edited after this.">
                                                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="po_status"><input type="hidden" name="op" value="submit"><input type="hidden" name="po_id" value="<?php echo $pid; ?>">
                                                        <button type="submit" class="btn-action btn-approve"><i class="fas fa-paper-plane"></i> Submit</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($st === 'pending'): ?>
                                                    <form method="POST" style="margin:0" data-confirm="Mark this order as shipped by the supplier? Stock does not change until you receive it.">
                                                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="po_status"><input type="hidden" name="op" value="mark_in_transit"><input type="hidden" name="po_id" value="<?php echo $pid; ?>">
                                                        <button type="submit" class="btn-action btn-edit" title="Update shipment status: the supplier has shipped it"><i class="fas fa-truck-fast"></i> In Transit</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (in_array($st, PURCH_RECEIVABLE, true)): ?>
                                                    <button type="button" class="btn-action btn-approve" data-frag="receive&id=<?php echo $pid; ?>" data-wide="x" title="Count what arrived and add it to stock"><i class="fas fa-dolly"></i> Receive</button>
                                                <?php endif; ?>
                                                <?php if ((int) $item['receipt_count'] > 0): ?>
                                                    <button type="button" class="btn-action btn-muted" data-frag="po_receipts&id=<?php echo $pid; ?>" data-wide="x" title="Receiving history of this purchase order"><i class="fas fa-clipboard-list"></i> Receipts</button>
                                                    <button type="button" class="btn-action btn-muted" data-frag="po_batches&id=<?php echo $pid; ?>" data-wide="x" title="Inventory batches created from this purchase order"><i class="fas fa-layer-group"></i> Batches</button>
                                                <?php endif; ?>
                                                <?php if ($is_admin && in_array($st, ['draft', 'pending', 'in_transit'], true)): ?>
                                                    <button type="button" class="btn-action btn-cancel" data-frag="status_form&op=cancel&id=<?php echo $pid; ?>"><i class="fas fa-times"></i> Cancel</button>
                                                <?php endif; ?>
                                                <?php if ($is_admin && $st === 'partially_received'): ?>
                                                    <button type="button" class="btn-action btn-warn" data-frag="status_form&op=close_short&id=<?php echo $pid; ?>" title="The rest of this order will not arrive"><i class="fas fa-flag-checkered"></i> Close Short</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div><!-- /#deliveries-wrap -->
        </div>

        <!-- ===================== RECEIVING HISTORY ===================== -->
        <div class="delivery-container tab-panel" id="panel-receiving" role="tabpanel" aria-labelledby="tab-receiving" hidden>
            <div class="header-action">
                <div>
                    <h2>Receiving History</h2>
                    <p class="sub">Every confirmed stock receipt, newest first. Receipts are permanent: a mistake is corrected by an admin <strong>reversal</strong> (with a reason), which takes the units back out of stock and keeps both records.</p>
                </div>
            </div>
            <div id="receipts-wrap">
            <?php if (empty($receipts)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-check" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No deliveries received yet.</p>
                    <p class="empty-help">When a shipment arrives, open its purchase order in Incoming Deliveries and click Receive.</p>
                </div>
            <?php else: ?>
                <div class="table-tools"><input type="search" placeholder="Search receipt, PO, supplier or product..." data-filter-table="receipts-table"></div>
                <div style="overflow-x: auto;">
                    <table class="delivery-table" id="receipts-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th><th>PO No.</th><th>Supplier</th><th>Products Received</th>
                                <th class="num">Qty</th><th class="num">Total Cost</th><th>Date Received</th><th>Received By</th><th>Batch</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receipts as $rc): $rid = (int) $rc['id']; ?>
                                <tr data-receipt-id="<?php echo $rid; ?>">
                                    <td><span class="cell-strong"><?php echo $h($rc['receipt_number']); ?></span></td>
                                    <td><?php echo $h($rc['po_number']); ?></td>
                                    <td><?php echo $h($rc['supplier_name']); ?></td>
                                    <td class="items-lines"><?php echo $h($rc['items_text']); ?></td>
                                    <td class="num"><?php echo number_format((int) $rc['total_qty']); ?><?php if ((int) $rc['total_rejected'] > 0): ?><div class="cell-muted"><?php echo (int) $rc['total_rejected']; ?> rejected</div><?php endif; ?></td>
                                    <td class="num"><?php echo $h(purch_money((float) $rc['total_cost'])); ?></td>
                                    <td><?php echo date('M d, Y g:i A', strtotime($rc['received_at'])); ?></td>
                                    <td><?php echo $h($rc['received_by_name']); ?></td>
                                    <td class="cell-muted"><?php echo $h($rc['batch_codes'] ?: '—'); ?></td>
                                    <td>
                                        <?php if ($rc['status'] === 'reversed'): ?><span class="badge badge-reversed">Reversed</span>
                                        <?php elseif ((int) $rc['stock_applied'] === 0): ?><span class="badge badge-history" title="Old delivery linked to products; stock was entered by hand earlier">History only</span>
                                        <?php else: ?><span class="badge badge-confirmed">Confirmed</span><?php endif; ?>
                                    </td>
                                    <td><button type="button" class="btn-action btn-muted" data-frag="receipt&id=<?php echo $rid; ?>" data-wide="x"><i class="fas fa-receipt"></i> View</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- ===================== INVENTORY BATCHES ===================== -->
        <div class="delivery-container tab-panel" id="panel-batches" role="tabpanel" aria-labelledby="tab-batches" hidden>
            <div class="header-action">
                <div>
                    <h2>Inventory Batches</h2>
                    <p class="sub">Each receipt line becomes its own batch, so the same product can hold stock bought at different costs and expiring on different dates. Sales take stock <strong>FEFO</strong> &mdash; the earliest expiration date first (products without expiry: oldest first). Stock on hand before batch tracking is one <em>Opening stock</em> batch with no recorded cost. On-hold, expired, damaged and recalled batches are never sold.</p>
                </div>
            </div>
            <div id="batches-wrap">
                <div class="mini-stats">
                    <div><span>Sellable units</span><strong><?php echo number_format((int) $batch_summary['sellable_units']); ?></strong></div>
                    <div><span>Stock value (at cost)</span><strong><?php echo $h(purch_money((float) $batch_summary['value'])); ?></strong><?php if ((int) $batch_summary['uncosted_units'] > 0): ?><span><?php echo number_format((int) $batch_summary['uncosted_units']); ?> units have no recorded cost</span><?php endif; ?></div>
                    <div><span>Expiring within <?php echo INV_NEAR_EXPIRY_DAYS; ?> days</span><strong><?php echo number_format((int) $batch_summary['near_units']); ?></strong></div>
                    <div><span>On hold (quarantine)</span><strong><?php echo number_format((int) $batch_summary['held_units']); ?></strong></div>
                    <div><span>Expired / damaged / recalled</span><strong><?php echo number_format((int) $batch_summary['written_off_units']); ?></strong></div>
                </div>
                <?php if (empty($batches)): ?>
                    <div class="empty-state"><i class="fas fa-layer-group" style="font-size: 2.5rem; color: #cbd5e1;"></i><p>No batches yet.</p></div>
                <?php else: ?>
                    <div class="table-tools">
                        <input type="search" placeholder="Search product, batch or lot number..." data-filter-table="batches-table">
                        <select data-filter-status="batches-table" aria-label="Batch status">
                            <option value="">All batches</option>
                            <option value="sellable">Sellable now</option>
                            <option value="near">Expiring within <?php echo INV_NEAR_EXPIRY_DAYS; ?> days</option>
                            <?php foreach (inv_batch_statuses() as $k => $l): ?><option value="<?php echo $h($k); ?>"><?php echo $h($l); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="delivery-table" id="batches-table">
                            <thead>
                                <tr>
                                    <th>Batch</th><th>Product</th><th>Supplier</th><th>Lot No.</th><th>Received</th><th>Expires</th>
                                    <th class="num">Unit Cost</th><th class="num">Received</th><th class="num">Remaining</th><th class="num">Value</th><th>Status</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $b):
                                    $bid = (int) $b['id'];
                                    $exp = $b['expiry_date'];
                                    $is_near = $b['status'] === 'available' && (int) $b['qty_remaining'] > 0 && $exp && $exp <= $near_limit;
                                    $sellable = $b['status'] === 'available' && (int) $b['qty_remaining'] > 0;
                                ?>
                                    <tr data-batch-id="<?php echo $bid; ?>" data-status="<?php echo $h($b['status']); ?>" data-near="<?php echo $is_near ? 1 : 0; ?>" data-sellable="<?php echo $sellable ? 1 : 0; ?>">
                                        <td><span class="cell-strong"><?php echo $h($b['batch_code']); ?></span>
                                            <div class="cell-muted"><?php echo $b['source'] === 'receipt' ? $h($b['receipt_number'] ?: '') : ($b['source'] === 'opening' ? 'Opening stock' : 'Stock adjustment'); ?></div></td>
                                        <td><?php echo $h(purch_item_label($b['item_label'], $b['variant_label'])); ?></td>
                                        <td><?php echo $h($b['supplier_name'] ?: '—'); ?></td>
                                        <td><?php echo $h($b['lot_number'] ?: '—'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($b['received_at'])); ?></td>
                                        <td><?php if ($exp): ?><?php echo date('M d, Y', strtotime($exp)); ?>
                                                <?php if ($is_near): ?><div><span class="badge badge-near"><?php echo $exp < $today ? 'Expired' : ((int) round((strtotime($exp) - strtotime($today)) / 86400) . ' days left'); ?></span></div><?php endif; ?>
                                            <?php else: ?><span class="cell-muted">No expiry</span><?php endif; ?></td>
                                        <td class="num"><?php echo $b['unit_cost'] !== null ? $h(purch_money((float) $b['unit_cost'])) : '<span class="cell-muted">not recorded</span>'; ?></td>
                                        <td class="num"><?php echo number_format((int) $b['qty_received']); ?></td>
                                        <td class="num"><strong><?php echo number_format((int) $b['qty_remaining']); ?></strong></td>
                                        <td class="num"><?php echo $b['unit_cost'] !== null && $b['status'] === 'available' ? $h(purch_money((int) $b['qty_remaining'] * (float) $b['unit_cost'])) : '—'; ?></td>
                                        <td><span class="badge badge-<?php echo $h($b['status']); ?>"><?php echo $h(inv_batch_statuses()[$b['status']] ?? $b['status']); ?></span>
                                            <?php if ($b['sells_next']): ?><div><span class="badge badge-next" title="FEFO: the next sale of this product takes from this batch">Sells next</span></div><?php endif; ?></td>
                                        <td><button type="button" class="btn-action btn-muted" data-frag="batch&id=<?php echo $bid; ?>" data-wide="w"><i class="fas fa-eye"></i> View</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===================== COST HISTORY ===================== -->
        <div class="delivery-container tab-panel" id="panel-costs" role="tabpanel" aria-labelledby="tab-costs" hidden>
            <div class="header-action">
                <div>
                    <h2>Supplier Purchase Cost History</h2>
                    <p class="sub">The actual unit cost of every received delivery, compared with the previous delivery of the same product. Selling prices are <strong>never</strong> changed automatically &mdash; if a cost increase means a new selling price, an admin updates it in Products.</p>
                </div>
            </div>
            <div id="costs-wrap">
            <?php if (empty($cost_history)): ?>
                <div class="empty-state"><i class="fas fa-chart-line" style="font-size: 2.5rem; color: #cbd5e1;"></i><p>No purchase costs recorded yet. They appear here as deliveries are received.</p></div>
            <?php else: ?>
                <div class="table-tools"><input type="search" placeholder="Search product or supplier..." data-filter-table="costs-table"></div>
                <div style="overflow-x: auto;">
                    <table class="delivery-table" id="costs-table">
                        <thead><tr><th>Date Received</th><th>Product</th><th>Supplier</th><th class="num">Qty</th><th class="num">Previous Cost</th><th class="num">New Cost</th><th class="num">Difference</th><th class="num">Change</th><th>Receipt</th></tr></thead>
                        <tbody>
                            <?php foreach ($cost_history as $i => $c): ?>
                                <tr data-cost-row="<?php echo $h($c['receipt_number'] . '|' . $c['key']); ?>">
                                    <td><?php echo date('M d, Y', strtotime($c['received_at'])); ?></td>
                                    <td class="cell-strong"><?php echo $h($c['item']); ?></td>
                                    <td><?php echo $h($c['supplier']); ?></td>
                                    <td class="num"><?php echo number_format($c['qty']); ?></td>
                                    <td class="num"><?php echo $c['prev_cost'] !== null ? $h(purch_money($c['prev_cost'])) : '<span class="cell-muted">first delivery</span>'; ?></td>
                                    <td class="num"><strong><?php echo $h(purch_money($c['cost'])); ?></strong></td>
                                    <td class="num"><?php if ($c['diff'] !== null): ?><span class="<?php echo $c['diff'] > 0 ? 'up' : ($c['diff'] < 0 ? 'down' : ''); ?>"><?php echo ($c['diff'] > 0 ? '+' : ($c['diff'] < 0 ? '−' : '')) . $h(purch_money(abs($c['diff']))); ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td class="num"><?php if ($c['pct'] !== null): ?><span class="<?php echo $c['pct'] > 0 ? 'up' : ($c['pct'] < 0 ? 'down' : ''); ?>"><?php echo ($c['pct'] > 0 ? '+' : '') . $h(number_format($c['pct'], 1)); ?>%</span><?php else: ?>—<?php endif; ?></td>
                                    <td class="cell-muted"><?php echo $h($c['receipt_number']); ?><br><?php echo $h($c['po_number']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- ===================== SUPPLIER DIRECTORY ===================== -->
        <div class="delivery-container tab-panel" id="panel-suppliers" role="tabpanel" aria-labelledby="tab-suppliers" hidden>
            <div class="header-action">
                <div>
                    <h2>Supplier Directory</h2>
                    <p class="sub">Your list of suppliers &mdash; name, brand, contact details and address. Only <strong>active</strong> suppliers can be picked for a new purchase order. <strong>Deactivate</strong> a supplier you no longer order from: their purchase orders, receipts and costs are kept, and you can <strong>Reactivate</strong> them any time. Click <strong>Profile</strong> for a supplier's purchase history.</p>
                </div>
                <?php if ($is_admin): ?><button class="btn-add" onclick="openSupplierModal()"><i class="fas fa-user-plus"></i> Add Supplier</button><?php endif; ?>
            </div>

            <div id="suppliers-wrap">
            <?php if (empty($suppliers)): ?>
                <div class="empty-state">
                    <i class="fas fa-address-book" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No suppliers yet.<?php echo $is_admin ? ' Click "Add Supplier" to build your directory.' : ' Ask an admin to add one.'; ?></p>
                    <p class="empty-help">Only the supplier name is required. Add the brand, contact person, phone, email and address so everything is in one place.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="delivery-table" id="suppliers-table">
                        <thead>
                            <tr>
                                <th title="Supplier name. Any notes you saved (payment terms, lead time) appear underneath">Supplier</th>
                                <th title="The product brand this supplier provides">Brand</th>
                                <th title="Contact person, phone and email. Phone and email are clickable">Contact Info</th>
                                <th title="Where the supplier is located">Address</th>
                                <th class="num" title="Purchase orders placed (drafts not counted)">Purchase Orders</th>
                                <th class="num" title="Deliveries received into stock">Received</th>
                                <th class="num" title="Total actual cost of everything received from this supplier">Purchases</th>
                                <th title="Active suppliers can be picked for new purchase orders. Inactive ones are hidden from that list">Status</th>
                                <th title="Purchase history, edit, deactivate / reactivate">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $s): ?>
                                <tr data-supplier-id="<?php echo (int) $s['id']; ?>">
                                    <td>
                                        <span class="cell-strong"><?php echo $h($s['supplier_name']); ?></span>
                                        <?php if (!empty($s['notes'])): ?>
                                            <div class="cell-muted"><?php echo nl2br($h($s['notes'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['brand_name'])): ?>
                                            <span class="brand-chip"><?php echo $h($s['brand_name']); ?></span>
                                        <?php else: ?>
                                            <span class="cell-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="contact-lines">
                                        <?php if (!empty($s['contact_person'])): ?>
                                            <div><i class="fas fa-user" style="color:#94a3b8;width:16px;"></i> <?php echo $h($s['contact_person']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($s['phone'])): ?>
                                            <div><i class="fas fa-phone" style="color:#94a3b8;width:16px;"></i> <a href="tel:<?php echo $h($s['phone']); ?>"><?php echo $h($s['phone']); ?></a></div>
                                        <?php endif; ?>
                                        <?php if (!empty($s['email'])): ?>
                                            <div><i class="fas fa-envelope" style="color:#94a3b8;width:16px;"></i> <a href="mailto:<?php echo $h($s['email']); ?>"><?php echo $h($s['email']); ?></a></div>
                                        <?php endif; ?>
                                        <?php if (empty($s['contact_person']) && empty($s['phone']) && empty($s['email'])): ?>
                                            <span class="cell-muted">No contact info</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($s['address']) ? nl2br($h($s['address'])) : '<span class="cell-muted">&mdash;</span>'; ?></td>
                                    <td class="num"><?php echo (int) $s['po_count']; ?></td>
                                    <td class="num"><?php echo (int) $s['receipt_count']; ?></td>
                                    <td class="num"><?php echo $h(purch_money((float) $s['purchase_total'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo ((int) $s['is_active'] === 1) ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo ((int) $s['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons-group">
                                            <button type="button" class="btn-action btn-muted" data-frag="supplier&id=<?php echo (int) $s['id']; ?>" data-wide="x" title="Purchase orders, deliveries and costs of this supplier"><i class="fas fa-id-card"></i> Profile</button>
                                            <?php if ($is_admin): ?>
                                            <button type="button" class="btn-action btn-edit" title="Change this supplier's details"
                                                onclick='openSupplierModal(<?php echo json_encode([
                                                    'id' => (int) $s['id'],
                                                    'supplier_name' => $s['supplier_name'],
                                                    'brand_name' => $s['brand_name'],
                                                    'contact_person' => $s['contact_person'],
                                                    'email' => $s['email'],
                                                    'phone' => $s['phone'],
                                                    'address' => $s['address'],
                                                    'notes' => $s['notes'],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>
                                                <i class="fas fa-pen"></i> Edit
                                            </button>
                                            <form method="POST" style="margin:0;" data-confirm="<?php echo ((int) $s['is_active'] === 1) ? 'Deactivate this supplier? Their history is kept.' : 'Reactivate this supplier?'; ?>">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int) $s['id']; ?>">
                                                <button type="submit" class="btn-action btn-muted" title="<?php echo ((int) $s['is_active'] === 1) ? 'Hide this supplier from new purchase orders (their history is kept)' : 'Show this supplier for new purchase orders again'; ?>">
                                                    <i class="fas fa-<?php echo ((int) $s['is_active'] === 1) ? 'ban' : 'rotate-left'; ?>"></i>
                                                    <?php echo ((int) $s['is_active'] === 1) ? 'Deactivate' : 'Reactivate'; ?>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div><!-- /#suppliers-wrap -->
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- Add / Edit Supplier Modal -->
    <div id="supplierModal" class="modal">
        <div class="modal-content wide">
            <div class="modal-title">
                <h3 id="supplierModalTitle">Add Supplier</h3>
                <span class="modal-close" onclick="closeSupplierModal()">&times;</span>
            </div>
            <p class="modal-intro">Active suppliers saved here can be picked for purchase orders. Only the supplier name is required.</p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="supplier_id" id="supplier_id" value="">
                <div class="form-errors" role="alert"></div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Supplier Name <span class="hint">*required</span></label>
                        <input type="text" name="supplier_name" id="s_supplier_name" required placeholder="e.g. San Miguel Foods Inc.">
                        <span class="field-help">The company or person you buy stock from.</span>
                    </div>
                    <div class="form-group">
                        <label>Brand / Company</label>
                        <input type="text" name="brand_name" id="s_brand_name" placeholder="e.g. B-MEG, Pigrolac">
                        <span class="field-help">The product brand(s) they supply. Shown as a tag in the tables.</span>
                    </div>
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="s_contact_person" placeholder="e.g. Juan Dela Cruz">
                        <span class="field-help">Who to talk to when placing or following up an order.</span>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="s_phone" placeholder="e.g. +63 917 000 0000">
                        <span class="field-help">Becomes a tap-to-call link in the directory.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Email</label>
                        <input type="email" name="email" id="s_email" placeholder="orders@supplier.com">
                        <span class="field-help">Submitted purchase orders are emailed to this address. Leave blank if you don't want emails sent.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Business Address</label>
                        <textarea name="address" id="s_address" rows="2" placeholder="Street, Barangay, City, Province"></textarea>
                        <span class="field-help">Warehouse or office address, useful for pick-ups and returns.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes</label>
                        <textarea name="supplier_notes" id="s_notes" rows="2" placeholder="Payment terms, lead time, minimum order, etc."></textarea>
                        <span class="field-help">For your own reference. Shown under the supplier's name in the directory and never sent to the supplier.</span>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeSupplierModal()" class="btn-plain">Cancel</button>
                    <button type="submit" class="btn-add" id="supplierSubmitBtn">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- One modal for every server-rendered view (delivery_fragments.php) -->
    <div id="fragModal" class="modal" aria-hidden="true">
        <div class="modal-content" id="fragBox" role="dialog" aria-modal="true">
            <div class="modal-title">
                <h3 id="fragTitle">Loading…</h3>
                <button type="button" class="modal-close" data-frag-close aria-label="Close">&times;</button>
            </div>
            <div id="fragBody"></div>
        </div>
    </div>

    <script>
        function openSupplierModal(data) {
            const isEdit = data && typeof data === 'object';
            document.getElementById('supplierModalTitle').textContent = isEdit ? 'Edit Supplier' : 'Add Supplier';
            document.getElementById('supplierSubmitBtn').textContent = isEdit ? 'Update Supplier' : 'Save Supplier';
            document.getElementById('supplier_id').value      = isEdit ? data.id : '';
            document.getElementById('s_supplier_name').value   = isEdit ? (data.supplier_name || '') : '';
            document.getElementById('s_brand_name').value      = isEdit ? (data.brand_name || '') : '';
            document.getElementById('s_contact_person').value  = isEdit ? (data.contact_person || '') : '';
            document.getElementById('s_phone').value           = isEdit ? (data.phone || '') : '';
            document.getElementById('s_email').value           = isEdit ? (data.email || '') : '';
            document.getElementById('s_address').value         = isEdit ? (data.address || '') : '';
            document.getElementById('s_notes').value           = isEdit ? (data.notes || '') : '';
            document.getElementById('supplierModal').style.display = 'flex';
        }
        function closeSupplierModal() { var m = document.getElementById('supplierModal'); if (m) m.style.display = 'none'; }

        window.onclick = function (event) {
            ['supplierModal', 'fragModal'].forEach(function (id) {
                const modal = document.getElementById(id);
                if (modal && event.target === modal) modal.style.display = 'none';
            });
        };
    </script>
    <script>
        // Tabs (remembers the last one; #deliveries, #receiving, #batches, #costs, #suppliers also work)
        var showTab = (function () {
            var tabs = document.querySelectorAll('.page-tab');
            function show(name, save) {
                if (!document.getElementById('panel-' + name)) name = 'deliveries';
                tabs.forEach(function (t) {
                    var on = t.dataset.tab === name;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    document.getElementById('panel-' + t.dataset.tab).hidden = !on;
                });
                if (save) { try { localStorage.setItem('deliveryTab', name); } catch (e) {} }
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab, true); }); });
            var start = location.hash.replace('#', '');
            if (!document.getElementById('panel-' + start)) { try { start = localStorage.getItem('deliveryTab') || ''; } catch (e) { start = ''; } }
            show(start, false);
            return show;
        })();
    </script>
    <script>
        // Modals, purchase order lines, receive form and table filters.
        (function () {
            var modal = document.getElementById('fragModal'), box = document.getElementById('fragBox'),
                body = document.getElementById('fragBody'), title = document.getElementById('fragTitle');
            var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

            window.openFrag = function (frag, wide) {
                box.className = 'modal-content' + (wide === 'x' ? ' xwide' : (wide === 'w' ? ' wide' : ''));
                title.textContent = 'Loading…';
                body.innerHTML = '<p class="cell-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                var url = location.pathname + '?fragment=' + frag;
                return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest-doc', 'Accept': 'text/html' } })
                    .then(function (res) {
                        if (res.redirected && /login/i.test(res.url)) throw new Error('Your session has expired. Please log in again.');
                        if (!res.ok) throw new Error('Could not open this. Please try again.');
                        return res.text();
                    })
                    .then(function (html) {
                        body.innerHTML = html;
                        var t = body.querySelector('[data-frag-title]');
                        title.textContent = t ? t.getAttribute('data-frag-title') : '';
                        body.querySelectorAll('[data-po-form]').forEach(function (f) {
                            f.setAttribute('data-next', f.querySelectorAll('.po-line').length); // index for "Add another product"
                            poRecalc(f);
                        });
                        body.querySelectorAll('[data-receive-form] .rcv-line').forEach(rcvLine);
                        var f = body.querySelector('input:not([type=hidden]), select, textarea');
                        if (f && f.type !== 'checkbox') try { f.focus({ preventScroll: true }); } catch (e) {}
                    })
                    .catch(function (err) {
                        body.innerHTML = '<p class="frag-error">' + (err.message || 'Could not open this.') + '</p>';
                        title.textContent = 'Something went wrong';
                    });
            };
            window.closeFrag = function () { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); body.innerHTML = ''; };

            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-frag]');
                if (b) { e.preventDefault(); openFrag(b.getAttribute('data-frag'), b.getAttribute('data-wide')); return; }
                if (e.target.closest('[data-frag-close]')) { closeFrag(); return; }
                var g = e.target.closest('[data-goto-tab]');
                if (g) {
                    showTab(g.getAttribute('data-goto-tab'), true);
                    var sel = document.querySelector('[data-filter-status="batches-table"]');
                    if (sel && g.getAttribute('data-batch-filter')) { sel.value = g.getAttribute('data-batch-filter'); filterTable.typed = true; filterTable('batches-table'); filterTable.typed = false; }
                    document.getElementById('panel-' + g.getAttribute('data-goto-tab')).scrollIntoView({ behavior: 'smooth', block: 'start' });
                    return;
                }
                // Purchase order form: add / remove a line
                var add = e.target.closest('[data-po-add]');
                if (add) {
                    var form = add.closest('[data-po-form]'), tpl = form.querySelector('template[data-po-line]');
                    var idx = parseInt(form.getAttribute('data-next') || '0', 10);
                    form.setAttribute('data-next', idx + 1);
                    var wrap = document.createElement('div');
                    wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, idx);
                    form.querySelector('.po-lines').appendChild(wrap.firstElementChild);
                    poRecalc(form);
                    return;
                }
                var rm = e.target.closest('[data-po-remove]');
                if (rm) {
                    var f2 = rm.closest('[data-po-form]');
                    if (f2.querySelectorAll('.po-line').length > 1) rm.closest('.po-line').remove();
                    else {
                        rm.closest('.po-line').querySelectorAll('input, select').forEach(function (i) { i.value = ''; });
                        rm.closest('.po-line').querySelectorAll('[data-item-search]').forEach(itemSearch); // show every product again
                    }
                    poRecalc(f2);
                    return;
                }
                // Receive form: "everything arrived as ordered"
                var all = e.target.closest('[data-rcv-all]');
                if (all) {
                    all.closest('form').querySelectorAll('.rcv-line').forEach(function (l) {
                        l.querySelector('[data-acc]').value = l.getAttribute('data-remaining');
                        l.querySelector('[data-rej]').value = 0;
                        rcvLine(l);
                    });
                }
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display === 'flex') closeFrag(); });

            // ---- Purchase order totals ----
            function poRecalc(form) {
                var qty = 0, total = 0;
                form.querySelectorAll('.po-line').forEach(function (line) {
                    var sel = line.querySelector('select'), q = parseInt(line.querySelector('[data-qty]').value, 10) || 0,
                        c = parseFloat(line.querySelector('[data-cost]').value) || 0, opt = sel.options[sel.selectedIndex];
                    line.querySelector('.line-total').textContent = peso(q * c);
                    var hint = line.querySelector('.line-hint');
                    if (opt && opt.value) {
                        var last = opt.getAttribute('data-last');
                        hint.textContent = 'In stock: ' + opt.getAttribute('data-stock') + (last ? ' · Last paid ' + peso(parseFloat(last)) + ' per unit' : ' · No purchase cost recorded yet');
                    } else hint.textContent = '';
                    qty += q; total += q * c;
                });
                form.querySelector('[data-po-qty]').textContent = qty.toLocaleString();
                form.querySelector('[data-po-total]').textContent = peso(total);
            }
            // ---- Product search: typing narrows the "Product / size" list; one match is picked automatically ----
            function itemSearch(box) {
                var sel = box.closest('.mini-field').querySelector('select[data-item]');
                if (!sel) return;
                var words = box.value.toLowerCase().trim().split(/\s+/).filter(Boolean), shown = [], prev = sel.value;
                Array.prototype.forEach.call(sel.options, function (o) {
                    if (!o.value) { o.hidden = false; return; } // "Choose a product..."
                    var group = o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label + ' ' : '';
                    var text = (group + o.textContent).toLowerCase();
                    var ok = words.every(function (w) { return text.indexOf(w) !== -1; });
                    o.hidden = !ok;
                    if (ok) shown.push(o);
                });
                Array.prototype.forEach.call(sel.querySelectorAll('optgroup'), function (g) {
                    g.hidden = !Array.prototype.some.call(g.children, function (o) { return !o.hidden; });
                });
                if (shown.length === 1 && words.length) {
                    sel.value = shown[0].value;
                } else if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) {
                    sel.value = ''; // the chosen product no longer matches what was typed
                }
                if (sel.value !== prev) sel.dispatchEvent(new Event('change', { bubbles: true }));
                var none = box.parentNode.querySelector('.item-search-none');
                if (!shown.length && words.length) {
                    if (!none) { none = document.createElement('span'); none.className = 'item-search-none'; box.insertAdjacentElement('afterend', none); }
                    none.textContent = 'No product matches "' + box.value.trim() + '".';
                } else if (none) {
                    none.remove();
                }
            }
            // ---- "+ New product": create a product that is not in the list yet (0 stock) and pick it in that row ----
            var qpRow = null;
            function qpOpen(btn) {
                var form = btn.closest('[data-po-form]'), panel = form && form.querySelector('[data-qp]');
                if (!panel) return;
                qpRow = btn.closest('.po-line');
                qpRow.insertAdjacentElement('afterend', panel);
                var typed = qpRow.querySelector('[data-item-search]');
                panel.querySelectorAll('input, select').forEach(function (i) { i.value = ''; });
                panel.querySelector('[data-qp-name]').value = typed ? typed.value.trim() : '';
                panel.querySelector('.qp-error').textContent = '';
                panel.hidden = false;
                panel.querySelector(panel.querySelector('[data-qp-name]').value ? '[data-qp-price]' : '[data-qp-name]').focus();
            }
            function qpSave(panel) {
                var form = panel.closest('[data-po-form]'), btn = panel.querySelector('[data-qp-save]'), err = panel.querySelector('.qp-error');
                var fd = new FormData();
                fd.append('action', 'quick_product');
                fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
                fd.append('qp_name', panel.querySelector('[data-qp-name]').value);
                fd.append('qp_price', panel.querySelector('[data-qp-price]').value);
                fd.append('qp_category', panel.querySelector('[data-qp-category]').value);
                fd.append('qp_brand', panel.querySelector('[data-qp-brand]').value);
                fd.append('qp_weight', panel.querySelector('[data-qp-weight]').value);
                if (!panel.querySelector('[data-qp-name]').value.trim()) { err.textContent = 'Type the product name.'; return; }
                if (!(parseFloat(panel.querySelector('[data-qp-price]').value) > 0)) { err.textContent = 'Type the selling price.'; return; }
                btn.disabled = true;
                err.textContent = '';
                fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.success || !d.product) throw new Error((d && d.message) || 'Could not add the product.');
                        var p = d.product;
                        // Add it to every product list in this form (and the template for new rows).
                        var lists = Array.prototype.slice.call(form.querySelectorAll('select[data-item]'));
                        var tpl = form.querySelector('template[data-po-line]');
                        if (tpl) lists = lists.concat(Array.prototype.slice.call(tpl.content.querySelectorAll('select[data-item]')));
                        lists.forEach(function (sel) {
                            if (sel.querySelector('option[value="' + p.key + '"]')) return;
                            var o = document.createElement('option');
                            o.value = p.key; o.textContent = p.label; o.setAttribute('data-stock', '0'); o.setAttribute('data-last', '');
                            var first = sel.querySelector(':scope > option');
                            if (first) first.insertAdjacentElement('afterend', o); else sel.appendChild(o);
                        });
                        if (qpRow) {
                            var search = qpRow.querySelector('[data-item-search]');
                            if (search) { search.value = ''; itemSearch(search); }
                            var sel = qpRow.querySelector('select[data-item]');
                            sel.value = p.key;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                            var qty = qpRow.querySelector('[data-qty]');
                            if (qty && !qty.value) qty.focus();
                        }
                        panel.hidden = true;
                        (window.toastOr || alert)(d.message, 'success');
                    })
                    .catch(function (e) { err.textContent = e.message || 'Could not add the product.'; })
                    .then(function () { btn.disabled = false; });
            }
            document.addEventListener('click', function (e) {
                var nb = e.target.closest('[data-new-product]');
                if (nb) { e.preventDefault(); qpOpen(nb); return; }
                var sv = e.target.closest('[data-qp-save]');
                if (sv) { e.preventDefault(); qpSave(sv.closest('[data-qp]')); return; }
                var cc = e.target.closest('[data-qp-cancel]');
                if (cc) { e.preventDefault(); cc.closest('[data-qp]').hidden = true; }
            });
            document.addEventListener('keydown', function (e) {
                // Enter in the small form saves the product instead of submitting the whole purchase order.
                var panel = e.target.closest && e.target.closest('[data-qp]');
                if (panel && e.key === 'Enter') { e.preventDefault(); qpSave(panel); }
            });
            document.addEventListener('input', function (e) {
                if (e.target.matches('[data-item-search]')) itemSearch(e.target);
                var f = e.target.closest('[data-po-form]');
                if (f) poRecalc(f);
                var l = e.target.closest('.rcv-line');
                if (l) rcvLine(l);
            });
            document.addEventListener('change', function (e) {
                var f = e.target.closest('[data-po-form]');
                if (f && e.target.matches('select[data-item]')) {
                    // Pre-fill the cost with the last price paid for this item.
                    var opt = e.target.options[e.target.selectedIndex], cost = e.target.closest('.po-line').querySelector('[data-cost]');
                    if (opt && opt.getAttribute('data-last') && !cost.value) cost.value = parseFloat(opt.getAttribute('data-last')).toFixed(2);
                }
                if (f) poRecalc(f);
                var l = e.target.closest('.rcv-line');
                if (l) rcvLine(l);
                if (e.target.matches('[data-filter-status]')) { filterTable.typed = true; filterTable(e.target.getAttribute('data-filter-status')); filterTable.typed = false; }
            });

            // ---- Receive form: live check of one line ----
            function rcvLine(l) {
                var rem = parseInt(l.getAttribute('data-remaining'), 10) || 0,
                    acc = parseInt(l.querySelector('[data-acc]').value, 10) || 0,
                    rej = parseInt(l.querySelector('[data-rej]').value, 10) || 0,
                    hold = l.querySelector('[data-hold]'), reason = l.querySelector('[data-reason]'),
                    cost = parseFloat(l.querySelector('[data-rcost]').value), last = parseFloat(l.getAttribute('data-last')),
                    hint = l.querySelector('.rcv-hint');
                reason.required = rej > 0;
                reason.closest('.mini-field').style.opacity = rej > 0 ? '1' : '.55';
                var msg, bad = false;
                if (acc < 0 || rej < 0) { msg = 'Quantities cannot be negative.'; bad = true; }
                else if (acc + rej > rem) { msg = 'Only ' + rem + ' still expected — accepted + rejected is ' + (acc + rej) + '.'; bad = true; }
                else if (acc + rej === 0) { msg = 'Nothing entered for this item — it stays outstanding (' + rem + ').'; }
                else {
                    msg = (hold && hold.checked ? acc + ' go on hold (not sellable yet)' : acc + ' will be added to stock') + (rej ? ', ' + rej + ' rejected' : '') +
                        '. Still outstanding after this: ' + (rem - acc) + '.';
                    if (acc > 0 && !isNaN(cost) && !isNaN(last) && last > 0 && Math.abs(cost - last) >= 0.005) {
                        var pct = (cost - last) / last * 100;
                        msg += ' Cost ' + (cost > last ? 'up' : 'down') + ' ' + Math.abs(pct).toFixed(1) + '% from the last ' + peso(last) + '.';
                    }
                }
                hint.textContent = msg;
                hint.classList.toggle('is-bad', bad);
            }

            // ---- Table search / status filters ----
            function filterTable(id) {
                var table = document.getElementById(id);
                if (!table) return;
                var q = ((document.querySelector('[data-filter-table="' + id + '"]') || {}).value || '').toLowerCase().trim(),
                    st = (document.querySelector('[data-filter-status="' + id + '"]') || {}).value || '';
                table.querySelectorAll('tbody tr').forEach(function (tr) {
                    var ok = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
                    if (ok && st) ok = st === 'near' ? tr.dataset.near === '1' : (st === 'sellable' ? tr.dataset.sellable === '1' : tr.dataset.status === st);
                    tr.toggleAttribute('data-out', !ok);
                });
                if (filterTable.typed) pageState[id] = 1; // a new search starts on page 1
                pageTable(id);
            }

            // ---- Paging: 10 rows a page under every table; the search / filter above works on all pages ----
            var PAGE_SIZE = 10, pageState = {};
            function pageTable(id) {
                var table = document.getElementById(id);
                if (!table) return;
                var rows = Array.prototype.filter.call(table.querySelectorAll('tbody tr'), function (tr) { return !tr.hasAttribute('data-out'); });
                var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
                var page = Math.min(Math.max(1, pageState[id] || 1), pages);
                pageState[id] = page;
                table.querySelectorAll('tbody tr').forEach(function (tr) { tr.hidden = true; });
                rows.forEach(function (tr, i) { tr.hidden = i < (page - 1) * PAGE_SIZE || i >= page * PAGE_SIZE; });

                var holder = table.closest('div[style*="overflow"]') || table, pager = holder.nextElementSibling;
                if (!pager || !pager.classList.contains('dl-pager')) {
                    pager = document.createElement('div');
                    pager.className = 'dl-pager';
                    pager.setAttribute('data-pager-for', id);
                    holder.insertAdjacentElement('afterend', pager);
                }
                if (rows.length <= PAGE_SIZE) { pager.hidden = true; return; }
                pager.hidden = false;
                var from = (page - 1) * PAGE_SIZE + 1, to = Math.min(page * PAGE_SIZE, rows.length);
                var nums = [], html = '';
                for (var n = 1; n <= pages; n++) {
                    if (n === 1 || n === pages || Math.abs(n - page) <= 2) nums.push(n);
                    else if (nums[nums.length - 1] !== '…') nums.push('…');
                }
                html += '<button type="button" data-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="Previous page"><i class="fas fa-chevron-left"></i> <span class="dl-pager-label">Previous</span></button>';
                nums.forEach(function (n) {
                    html += n === '…' ? '<span class="dl-gap">…</span>'
                        : '<button type="button" data-page="' + n + '"' + (n === page ? ' class="is-active" aria-current="page"' : '') + '>' + n + '</button>';
                });
                html += '<button type="button" data-page="' + (page + 1) + '"' + (page === pages ? ' disabled' : '') + ' aria-label="Next page"><span class="dl-pager-label">Next</span> <i class="fas fa-chevron-right"></i></button>';
                pager.innerHTML = '<span class="dl-pager-info">Showing ' + from + '&ndash;' + to + ' of ' + rows.length + '</span><div class="dl-pager-nav">' + html + '</div>';
            }
            document.addEventListener('click', function (e) {
                var b = e.target.closest('.dl-pager [data-page]');
                if (!b || b.disabled) return;
                var id = b.closest('.dl-pager').getAttribute('data-pager-for');
                pageState[id] = parseInt(b.getAttribute('data-page'), 10) || 1;
                pageTable(id);
                var t = document.getElementById(id);
                if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            var DL_TABLES = ['deliveries-table', 'receipts-table', 'batches-table', 'costs-table', 'suppliers-table'];
            window.filterTable = filterTable;
            window.dlTables = DL_TABLES;
            DL_TABLES.forEach(filterTable);
            document.addEventListener('input', function (e) {
                if (!e.target.matches('[data-filter-table]')) return;
                filterTable.typed = true;
                filterTable(e.target.getAttribute('data-filter-table'));
                filterTable.typed = false;
            });
        })();
    </script>
    <script>
        // No-refresh actions: every POST form on this page (suppliers, purchase orders, status changes,
        // receiving, reversals, batch actions) is sent with fetch(); the JSON answer then updates only the
        // affected rows and counters. Rows are keyed by id, so nothing can appear twice.
        (function () {
            if (!window.LiveUI) return;

            var busyRow = function (tr) { return tr.classList.contains('liveui-row-busy'); };

            function syncTable(wrapId, keyAttr, freshDoc) {
                var cur = document.getElementById(wrapId), fr = freshDoc.getElementById(wrapId);
                if (!cur || !fr) return;
                var curBody = cur.querySelector('tbody'), frBody = fr.querySelector('tbody');
                if (curBody && frBody && keyAttr) LiveUI.syncKeyed(curBody, frBody, keyAttr, { skip: busyRow });
                else LiveUI.setHTML(cur, fr);            // empty state <-> table, or summary blocks
            }
            function syncPart(id, freshDoc) {
                var cur = document.getElementById(id), fr = freshDoc.getElementById(id);
                if (cur && fr && cur.innerHTML !== fr.innerHTML) cur.innerHTML = fr.innerHTML;
            }

            function refresh() {
                return LiveUI.fetchDoc(location.pathname + location.search).then(function (doc) {
                    doc.querySelectorAll('[data-stat]').forEach(function (el) {
                        LiveUI.setText(document.querySelector('[data-stat="' + el.getAttribute('data-stat') + '"]'), el.textContent);
                    });
                    syncTable('deliveries-wrap', 'data-delivery-id', doc);
                    syncTable('receipts-wrap', 'data-receipt-id', doc);
                    syncTable('costs-wrap', 'data-cost-row', doc);
                    syncTable('suppliers-wrap', 'data-supplier-id', doc);
                    syncPart('expiry-alert', doc);
                    // Batches: summary + table; keep the current search / status filter.
                    var bw = document.getElementById('batches-wrap'), fbw = doc.getElementById('batches-wrap');
                    if (bw && fbw) {
                        var sumCur = bw.querySelector('.mini-stats'), sumNew = fbw.querySelector('.mini-stats');
                        if (sumCur && sumNew) sumCur.innerHTML = sumNew.innerHTML;
                        var tb = bw.querySelector('tbody'), ftb = fbw.querySelector('tbody');
                        if (tb && ftb) LiveUI.syncKeyed(tb, ftb, 'data-batch-id', { skip: busyRow });
                        else LiveUI.setHTML(bw, fbw);
                    }
                    (window.dlTables || ['receipts-table', 'batches-table', 'costs-table']).forEach(window.filterTable);
                });
            }

            var LABELS = { save_supplier: 'Saving…', toggle_supplier: 'Updating…', save_po: 'Saving…', po_status: 'Updating…',
                           receive: 'Recording…', reverse_receipt: 'Reversing…', batch_status: 'Updating…', batch_edit: 'Saving…', link_legacy: 'Linking…' };

            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form.matches || !form.matches('form[method="POST"], form[method="post"]')) return;
                if (e.defaultPrevented) return;
                var ask = form.getAttribute('data-confirm');
                if (ask && !confirm(ask)) { e.preventDefault(); return; }
                e.preventDefault();

                var action = (form.querySelector('[name="action"]') || {}).value || '';
                var target = (form.querySelector('[name="po_id"], [name="receipt_id"], [name="batch_id"], [name="supplier_id"]') || {}).value || '';
                var release = LiveUI.lock('delivery-' + action + '-' + target);
                if (!release) return;                    // already being processed: ignore the double click

                var btn = e.submitter || form.querySelector('button[type="submit"]');
                var row = form.closest('tr');
                var errs = form.querySelector('.form-errors');
                if (errs) { errs.classList.remove('show'); errs.textContent = ''; }
                var done = LiveUI.busy(btn, LABELS[action] || 'Working…');
                if (row) row.classList.add('liveui-row-busy');
                // While a receipt is being recorded, every submit button of that form stays disabled.
                var others = Array.prototype.filter.call(form.querySelectorAll('button[type="submit"]'), function (b) { return b !== btn && !b.disabled; });
                others.forEach(function (b) { b.disabled = true; });

                LiveUI.post(location.pathname + location.search, LiveUI.formData(form, btn)).then(function (res) {
                    LiveUI.toast(res.message, 'success');
                    if (res.warning) LiveUI.toast(res.warning, 'warn');
                    if (form.closest('#supplierModal')) closeSupplierModal();
                    if (form.closest('#fragModal')) {
                        if (res.next) openFrag(res.next, 'x'); else closeFrag();
                    }
                    return true;
                }).catch(function (err) {
                    // The modal stays open so nothing typed is lost; the reason is shown in the form too.
                    LiveUI.toast(err.message, 'error');
                    if (errs) { errs.textContent = err.message; errs.classList.add('show'); errs.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                    return false;
                }).then(function (ok) {
                    done();
                    others.forEach(function (b) { b.disabled = false; });
                    if (row) row.classList.remove('liveui-row-busy');
                    release();
                    if (ok) return refresh().catch(function (err) { LiveUI.toast(err.message, 'error'); });
                });
            });

            // Changes by another admin or staff member: a tiny probe every ~5s; the tables are re-read
            // only when the fingerprint changed (rows are keyed, so nothing is duplicated).
            LiveUI.watch('delivery', function () { return refresh(); }, { fallbackMs: 30000 });
        })();
    </script>
</body>
</html>
