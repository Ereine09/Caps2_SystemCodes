<?php
require_once __DIR__ . '/bootstrap.php'; // Centralized autoloader
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/qr_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload || !in_array($payload['role'], ['admin', 'staff'])) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
$order_id = (int)($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header('Location: orders.php');
    exit();
}

$order = get_order_by_id($order_id);
if (!$order) {
    die("Order not found.");
}
$customer = get_customer_by_id($order['customer_id']);

// Mark this order as viewed for the sidebar's "Orders" notification badge —
// a separate concept from order_status (never touched here). A shared
// column: whichever admin/staff opens it first clears the badge for
// everyone, matching how the Messages badge's shared inbox already works.
// Guarded to only fire once (no-op write on every repeat view).
if ($order['admin_viewed_at'] === null) {
    $view_stmt = $conn->prepare("UPDATE tbl_orders SET admin_viewed_at = NOW() WHERE id = ? AND admin_viewed_at IS NULL");
    $view_stmt->bind_param('i', $order_id);
    $view_stmt->execute();
    $view_stmt->close();
    $order['admin_viewed_at'] = date('Y-m-d H:i:s'); // keep the in-memory copy consistent for the rest of this request
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'approve_payment') {
        $conn->begin_transaction();
        try {
            // 1. Deduct stock for each item in the order (no-op if some other
            // status change already deducted it for this order).
            $stock_result = deduct_order_stock_once($conn, $order_id);
            if (!$stock_result['ok']) {
                throw new Exception(htmlspecialchars($stock_result['error'] ?? 'Insufficient stock.'));
            }

            // 2. Update order status to 'confirmed'
            $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'confirmed' WHERE id = ?");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            notifications_ensure_schema($conn);
            notifications_create($conn, [
                'customer_id' => (int) $order['customer_id'],
                'type' => 'order_confirmed',
                'channel' => 'both',
                'title' => 'Order Confirmed',
                'message' => 'Your order #' . $order['order_number'] . ' has been confirmed.',
                'reference_table' => 'tbl_orders',
                'reference_id' => $order_id,
                'email_to' => (string) ($customer['email'] ?? ''),
            ]);
            header("Location: order_details.php?id=$order_id&status_updated=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error approving order: " . $e->getMessage();
            header("Location: order_details.php?id=$order_id");
            exit();
        }
    } elseif ($action === 'reject_payment') {
        // Stock is not deducted for pending orders, so just cancel the order.
        $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $order_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: order_details.php?id=$order_id&status_updated=1");
            exit();
        }
        $stmt->close();
    }
}

$order_items = get_order_items($order_id);
$delivery_details = get_delivery_details_by_order_id($order_id);
// Proof of Delivery (rider's photo) - the exact same query/table customer/orders.php
// already uses, just surfaced here for admin too. Not a new proof system.
$delivery_proof = get_order_delivery_proof($order_id);

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$user_id = (int)$payload['user_id'];
$unread_count = get_unread_count_staff($user_id);

// ---- Display-only helpers for the header + status timeline (no queries, no status logic) ----
$od_status = (string) $order['order_status'];
$od_is_pickup = ($order['fulfillment_type'] ?? '') === 'pickup';
$od_pay_names = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
$od_pay_label = $od_pay_names[strtolower((string) $order['payment_method'])] ?? strtoupper((string) $order['payment_method']);

// The applicable stages of the existing workflow for this order type (same stages the customer sees).
$od_flow = $od_is_pickup
    ? ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'ready_for_pickup' => 'Ready for Pickup', 'completed' => 'Picked Up']
    : ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'to_ship' => 'To Ship', 'to_receive' => 'To Receive', 'out_for_delivery' => 'Out for Delivery', 'completed' => 'Delivered'];
$od_keys = array_keys($od_flow);
$od_cur = $od_status === 'reviews' ? 'completed' : $od_status;
if (!isset($od_flow[$od_cur])) {
    // a status that isn't a stage of this order type (e.g. "ready for pickup" on a delivery order): closest stage
    $od_cur = $od_is_pickup ? 'ready_for_pickup' : ($od_cur === 'ready_for_pickup' ? 'to_ship' : 'pending');
}
$od_idx = (int) array_search($od_cur, $od_keys, true);
$od_is_final = $od_cur === 'completed';
$od_stage_note = [
    'pending' => in_array($order['payment_method'], ['gcash', 'bank'], true)
        ? 'Waiting for you to verify the payment - use Approve or Reject in the Payment card.'
        : 'New order waiting to be confirmed.',
    'confirmed' => 'Order confirmed. Move it to Processing once the shop starts preparing it.',
    'processing' => 'The shop is preparing this order.',
    'ready_for_pickup' => 'Ready - waiting for the customer to pick it up at the scheduled date and time.',
    'to_ship' => 'Packed and waiting for a rider to pick it up.',
    'to_receive' => 'The rider has the parcel and is about to deliver it.',
    'out_for_delivery' => 'The parcel is on its way to the customer.',
    'completed' => $od_is_pickup ? 'The customer picked up this order.' : 'This order was delivered to the customer.',
][$od_cur] ?? '';

// ---- Extra read-only details for the cards below (prepared statements, nothing is written) ----
// Customer history: how many orders / how much they've spent with the shop.
$od_cust = ['orders' => 0, 'done' => 0, 'spent' => 0.0];
$od_stmt = $conn->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(order_status IN ('completed','reviews')), 0) AS done,
            COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS spent
     FROM tbl_orders WHERE customer_id = ?"
);
$od_cust_id = (int) $order['customer_id'];
$od_stmt->bind_param('i', $od_cust_id);
$od_stmt->execute();
$od_row = $od_stmt->get_result()->fetch_assoc();
$od_stmt->close();
if ($od_row) {
    $od_cust = ['orders' => (int) $od_row['n'], 'done' => (int) $od_row['done'], 'spent' => (float) $od_row['spent']];
}

// Delivery record + assigned rider (delivery orders only). The rider is stored two ways in this system:
// tbl_orders.rider_id = riders.id, or tbl_delivery.rider_id = users.id (set by the admin "assign rider").
$od_delivery = null;
$od_rider = null;
if (!$od_is_pickup) {
    $od_stmt = $conn->prepare("SELECT status, scheduled_at, delivered_at, instructions, rider_id FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $od_stmt->bind_param('i', $order_id);
    $od_stmt->execute();
    $od_delivery = $od_stmt->get_result()->fetch_assoc() ?: null;
    $od_stmt->close();

    $od_order_rider = (int) ($order['rider_id'] ?? 0);
    $od_delivery_rider = (int) ($od_delivery['rider_id'] ?? 0);
    $od_rider_sql = null;
    $od_rider_key = 0;
    if ($od_order_rider > 0) {
        $od_rider_sql = 'r.id = ?';
        $od_rider_key = $od_order_rider;
    } elseif ($od_delivery_rider > 0) {
        $od_rider_sql = 'r.user_id = ?';
        $od_rider_key = $od_delivery_rider;
    }
    if ($od_rider_sql !== null) {
        $od_stmt = $conn->prepare(
            "SELECT r.vehicle_type, r.plate_number, u.first_name, u.last_name, u.username
             FROM riders r LEFT JOIN users u ON u.id = r.user_id
             WHERE $od_rider_sql LIMIT 1"
        );
        $od_stmt->bind_param('i', $od_rider_key);
        $od_stmt->execute();
        $od_rider = $od_stmt->get_result()->fetch_assoc() ?: null;
        $od_stmt->close();
    }
}
$od_delivery_labels = ['pending' => 'Pending', 'in_transit' => 'In transit', 'delivered' => 'Delivered', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

// Payment state, worded for the admin (display only - the real flag is tbl_orders.payment_settled).
$od_pm = strtolower((string) $order['payment_method']);
if ($od_status === 'cancelled') {
    $od_pay_state = ['Cancelled', 'muted'];
} elseif (in_array($od_pm, ['gcash', 'bank'], true)) {
    // Same rule the customer's order page uses: online payments count as verified once staff moves the order past "pending".
    $od_pay_state = $od_status === 'pending' ? ['Awaiting verification', 'warn'] : ['Paid', 'ok'];
} elseif ((int) ($order['payment_settled'] ?? 0) === 1 || in_array($od_status, ['completed', 'reviews'], true)) {
    $od_pay_state = ['Paid', 'ok'];
} elseif ($od_pm === 'cod') {
    $od_pay_state = ['Collect on delivery', 'warn'];
} else {
    $od_pay_state = ['Pay at shop', 'warn'];
}
$od_money = static fn ($v): string => 'PHP ' . number_format((float) $v, 2);
$od_initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $i = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '');
    return mb_strtoupper($i !== '' ? $i : '?');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .order-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 20px; }
        .info-card { background: var(--surface); padding: 25px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 25px; }
        .info-card h4 { margin-top: 0; margin-bottom: 20px; color: var(--text); border-bottom: 1px solid var(--border); padding-bottom: 12px; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .info-item { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; }
        .info-label { color: var(--muted); font-weight: 500; }
        .info-value { color: var(--text); font-weight: 700; }
        .item-img { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: var(--surface-2); border: 1px solid var(--border); }
        .order-summary-box { background: var(--surface-2); padding: 20px; border-radius: 12px; border: 1px dashed var(--border); }
        
        .button-link {
            background: var(--primary-soft); color: var(--link); padding: 6px 12px; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.85rem; border: 1px solid #c7d2fe;
            cursor: pointer; transition: all 0.2s ease;
        }
        .button-link:hover { background: #c7d2fe; }

        /* Alert Banners */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fca5a5; }
        .alert-success { background: var(--success-soft); color: var(--success); border: 1px solid #6ee7b7; }

        /* Proof Modal Styles — a lightbox over the page (overlay is
           intentionally always dark regardless of Admin theme, so there is
           nothing here that needs a separate Dark Mode variant; only the
           receipt thumbnail below sits on a themed card and picks up the
           existing --dm-* variables/dark-mode CSS in admin_style.css). */
        .modal {
            display: none; position: fixed; z-index: 1001; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8);
            align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;
        }
        .modal-content {
            margin: auto; display: block;
            max-width: min(90vw, 700px); max-height: 80vh;
            width: auto; height: auto;
            object-fit: contain; border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            animation: zoom 0.2s ease;
        }
        @keyframes zoom { from {transform:scale(0.92); opacity:0.5} to {transform:scale(1); opacity:1} }
        .modal-caption {
            position: absolute; top: 18px; left: 50%; transform: translateX(-50%);
            color: #f8fafc; font-weight: 700; font-size: 1rem;
            text-align: center; max-width: 80%; text-shadow: 0 1px 3px rgba(0,0,0,0.6);
        }
        .modal-error {
            display: none; color: #fca5a5; font-weight: 600; font-size: 0.95rem;
            background: rgba(0,0,0,0.4); padding: 14px 20px; border-radius: 8px;
        }
        .close-modal {
            position: absolute; top: 18px; right: 24px; color: #f1f1f1;
            font-size: 34px; font-weight: bold; cursor: pointer; line-height: 1;
            z-index: 1; padding: 4px 10px; border-radius: 6px;
            transition: background 0.15s ease;
        }
        .close-modal:hover { background: rgba(255,255,255,0.15); }
        .receipt-thumb {
            width: 42px; height: 42px; object-fit: cover; border-radius: 6px;
            border: 1px solid var(--border); cursor: pointer; flex-shrink: 0;
        }
        @media (max-width: 480px) {
            .modal-caption { font-size: 0.88rem; top: 12px; }
            .close-modal { top: 8px; right: 12px; font-size: 30px; }
            .modal-content { max-height: 72vh; }
        }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: var(--text-2); }
        .summary-total { margin-top: 15px; padding-top: 15px; border-top: 2px solid var(--border); font-size: 1.2rem; color: var(--text); font-weight: 800; }
        @media (max-width: 900px) { .order-grid { grid-template-columns: 1fr; } }

        /* =====================================================================
           Look-and-feel layer (display only). Cards get a clear outline, the
           header shows status/method/date at a glance and a timeline shows the
           order's progress. Tokens: --dm-* (redefined by body.dark-mode).
           ===================================================================== */
        .main-content { --od-edge: #cfd8e3; --od-soft: #f8fafc; --od-text: #1e293b; --od-muted: #64748b; --od-surface: #fff; --od-brand: #4a3e94; }
        body.dark-mode .main-content { --od-edge: var(--dm-border, #2f3b52); --od-soft: var(--dm-surface-2, #1b2436); --od-text: var(--dm-text, #e5e7eb); --od-muted: var(--dm-text-muted, #94a3b8); --od-surface: var(--dm-surface, #131a29); }

        .od-back { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; padding: 8px 14px; border-radius: 9px; font-size: 0.85rem; font-weight: 600; text-decoration: none;
            background: var(--od-surface); border: 1.5px solid var(--od-edge); color: var(--od-muted); }
        .od-back:hover { color: var(--od-brand); border-color: var(--od-brand); }
        body.dark-mode .od-back:hover { color: #c7d2fe; border-color: #a5b4fc; }

        .od-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px 24px; margin-bottom: 18px; padding: 20px 24px; border-radius: 16px;
            background: var(--od-surface); border: 2px solid var(--od-edge); box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08); }
        .od-head-main { flex: 1 1 420px; min-width: 0; }
        .od-head-actions { margin-left: auto; }
        .od-header h1 { margin: 0; font-size: 1.5rem; color: var(--od-text); }
        .od-number { margin: 4px 0 12px; font-size: 1.05rem; font-weight: 700; color: var(--od-brand); overflow-wrap: anywhere; }
        body.dark-mode .od-number { color: #c7d2fe; }
        .od-chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .od-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; background: var(--od-soft); border: 1px solid var(--od-edge); color: var(--od-text); }
        .od-chip i { color: var(--od-muted); }
        .od-pill { display: inline-flex; align-items: center; padding: 5px 14px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #fff; background: #64748b; }
        .od-pill-pending { background: #f39c12; }
        .od-pill-confirmed, .od-pill-processing, .od-pill-ready_for_pickup { background: #3498db; }
        .od-pill-to_ship, .od-pill-to_receive { background: #16a085; }
        .od-pill-out_for_delivery { background: #8e44ad; }
        .od-pill-reviews { background: #f1c40f; color: var(--text); }
        .od-pill-completed { background: #27ae60; }
        .od-pill-cancelled { background: #c0392b; }
        .od-head-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .od-total-box { display: flex; flex-direction: column; align-items: flex-end; padding: 8px 16px; border-radius: 12px; background: var(--od-soft); border: 1.5px dashed var(--od-edge); }
        .od-total-box span { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--od-muted); }
        .od-total-box strong { font-size: 1.25rem; color: var(--od-text); }
        .od-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 10px; font-size: 0.9rem; font-weight: 700; text-decoration: none; }
        .od-btn-teal { background: #0f766e; color: #fff; box-shadow: 0 4px 12px rgba(15, 118, 110, 0.3); }
        .od-btn-teal:hover { background: #115e59; }

        /* Timeline */
        .od-timeline { margin-bottom: 4px; padding: 20px 24px 16px; border-radius: 16px; background: var(--od-surface); border: 2px solid var(--od-edge); box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08); }
        .od-steps { list-style: none; margin: 0; padding: 0; display: flex; }
        .od-step { position: relative; flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 6px; }
        .od-step::before { content: ''; position: absolute; top: 13px; right: 50%; width: 100%; height: 3px; background: var(--od-edge); z-index: 0; }
        .od-step:first-child::before { display: none; }
        .od-step.is-done::before, .od-step.is-current::before { background: #10b981; }
        .od-dot { position: relative; z-index: 1; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff;
            background: var(--od-surface); border: 3px solid var(--od-edge); }
        .od-step.is-done .od-dot { background: #10b981; border-color: #10b981; }
        .od-step.is-current .od-dot { background: #6366f1; border-color: #6366f1; box-shadow: 0 0 0 5px rgba(99, 102, 241, 0.22); }
        .od-step.is-current .od-dot i { font-size: 7px; }
        .od-step-label { font-size: 0.82rem; font-weight: 700; color: var(--od-muted); line-height: 1.25; padding: 0 4px; }
        .od-step.is-done .od-step-label { color: var(--od-text); }
        .od-step.is-current .od-step-label { color: var(--link); font-weight: 800; }
        body.dark-mode .od-step.is-current .od-step-label { color: #a5b4fc; }
        .od-step-time { min-height: 1.1em; font-size: 0.72rem; color: var(--od-muted); }
        .od-stage-note { margin: 16px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 0.86rem; line-height: 1.4; color: var(--od-text); background: var(--od-soft); border: 1px solid var(--od-edge); border-left: 4px solid #6366f1; }
        .od-stage-note i { color: var(--link); margin-right: 4px; }
        .od-cancelled { display: flex; align-items: center; gap: 14px; margin-bottom: 4px; padding: 16px 20px; border-radius: 16px; background: var(--danger-soft); border: 2px solid #fca5a5; }
        .od-cancelled > i { font-size: 1.6rem; color: var(--danger); }
        .od-cancelled strong { display: block; color: var(--danger); font-size: 1rem; }
        .od-cancelled span { color: var(--danger); font-size: 0.9rem; }
        body.dark-mode .od-cancelled { background: #3a1414; border-color: #7f1d1d; }
        body.dark-mode .od-cancelled strong, body.dark-mode .od-cancelled span { color: #fca5a5; }

        /* Cards: clear outline + tinted icon chip in each title */
        .info-card { border: 2px solid var(--od-edge); box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06); padding: 20px 22px; margin-bottom: 20px; }
        .info-card h4 { padding-bottom: 12px; margin-bottom: 16px; border-bottom: 1.5px solid var(--od-edge); }
        .info-card h4 i { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 9px; font-size: 0.85rem; background: var(--primary-soft); color: var(--link); }
        body.dark-mode .info-card h4 i { background: #23264d; color: #a5b4fc; }
        body.dark-mode .info-card .info-label { color: var(--od-muted); }
        .info-item { padding: 7px 0; margin-bottom: 0; border-bottom: 1px dashed var(--od-edge); }
        .info-item:last-child { border-bottom: 0; }
        .info-value { text-align: right; overflow-wrap: anywhere; }
        .info-item[style*="flex-direction: column"] .info-value { text-align: left; }

        /* Items table */
        .od-items { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .od-items thead th { padding: 6px 12px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: .04em; text-align: left; background: transparent; border: 0; color: var(--od-muted); }
        .od-items tbody td { padding: 12px; background: var(--od-surface); border-top: 1.5px solid var(--od-edge); border-bottom: 1.5px solid var(--od-edge); vertical-align: middle; }
        .od-items tbody td:first-child { border-left: 1.5px solid var(--od-edge); border-radius: 12px 0 0 12px; }
        .od-items tbody td:last-child { border-right: 1.5px solid var(--od-edge); border-radius: 0 12px 12px 0; text-align: right; white-space: nowrap; }
        .od-items thead th:last-child { text-align: right; }
        .od-items .od-qty { display: inline-block; min-width: 34px; padding: 2px 10px; border-radius: 999px; text-align: center; font-weight: 700; font-size: 0.82rem; background: var(--od-soft); border: 1px solid var(--od-edge); }

        /* Compact spacing so the fuller page still scrolls as little as possible */
        .order-grid { gap: 20px; margin-top: 16px; }
        .info-card { padding: 16px 20px; margin-bottom: 16px; }
        .info-card h4 { margin-bottom: 10px; padding-bottom: 10px; font-size: 1.02rem; }
        .info-item { padding: 5px 0; font-size: 0.9rem; }
        .order-summary-box { padding: 12px 16px; }
        .summary-row { margin-bottom: 5px; font-size: 0.9rem; }
        .summary-total { margin-top: 8px; padding-top: 10px; font-size: 1.1rem; }
        .od-items { border-spacing: 0 6px; }
        .od-items tbody td { padding: 9px 12px; }
        .od-header { margin-bottom: 14px; padding: 16px 22px; }
        .od-timeline { padding: 16px 22px 14px; margin-bottom: 0; }
        .od-stage-note { margin-top: 12px; }
        .od-stats { margin-bottom: 8px; }
        .od-linkbtn { margin-top: 10px; }

        /* ---- extra detail cards ---- */
        .od-muted { color: var(--od-muted); font-weight: 500; }
        body.dark-mode .info-card .od-muted { color: var(--od-muted); }
        .od-tag { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; }
        .od-tag-ok { background: var(--success-soft); color: var(--success); }
        .od-tag-warn { background: var(--warning-soft); color: var(--warning); }
        .od-tag-info { background: var(--info-soft); color: var(--info); }
        .od-tag-muted { background: var(--od-soft); color: var(--od-muted); border: 1px solid var(--od-edge); }
        body.dark-mode .info-card .od-tag-ok { background: #12301f; color: #86efac; }
        body.dark-mode .info-card .od-tag-warn { background: #3a2c10; color: #fcd34d; }
        body.dark-mode .info-card .od-tag-info { background: #14274a; color: #93c5fd; }
        body.dark-mode .info-card .od-tag-muted { color: var(--od-muted); }

        .od-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 32px; }
        .od-grid2 .od-span2 { grid-column: 1 / -1; flex-direction: column; gap: 4px; }
        .info-value.od-left, .od-grid2 .od-span2 .info-value { text-align: left; font-weight: 600; }
        .od-maplink { display: inline-flex; align-items: center; gap: 6px; margin-left: 10px; font-size: 0.8rem; font-weight: 700; color: var(--link); text-decoration: none; }
        .od-maplink:hover { text-decoration: underline; }
        body.dark-mode .info-card .od-maplink { color: #a5b4fc; }

        .od-notes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .od-note-box { padding: 12px 14px; border-radius: 12px; background: var(--od-soft); border: 1.5px solid var(--od-edge); }
        .od-note-title { display: block; margin-bottom: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--od-muted); }
        body.dark-mode .info-card .od-note-title { color: var(--od-muted); }
        .od-note-box p { margin: 0; font-size: 0.9rem; line-height: 1.5; overflow-wrap: anywhere; }

        .od-cust-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .od-cust-head strong { display: block; font-size: 1.05rem; }
        .od-cust-head .od-muted { font-size: 0.8rem; }
        .od-avatar { flex: 0 0 auto; width: 46px; height: 46px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem; color: #fff;
            background: linear-gradient(135deg, #6d5bd0, #4a3e94); box-shadow: 0 3px 8px rgba(74, 62, 148, 0.3); }
        body.dark-mode .info-card .od-avatar { color: #fff; }
        .od-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }
        .od-stats > div { padding: 10px 6px; text-align: center; border-radius: 12px; background: var(--od-soft); border: 1.5px solid var(--od-edge); }
        .od-stats strong { display: block; font-size: 1.05rem; }
        .od-stats span { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--od-muted); }
        body.dark-mode .info-card .od-stats span { color: var(--od-muted); }
        .od-linkbtn { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; font-weight: 700; text-decoration: none;
            background: var(--primary-soft); color: var(--link); border: 1px solid #c7d2fe; }
        .od-linkbtn:hover { background: #c7d2fe; }
        body.dark-mode .info-card .od-linkbtn { background: #23264d; color: #c7d2fe; border-color: #3b3f78; }
        @media (max-width: 600px) {
            .od-grid2, .od-notes { grid-template-columns: 1fr; }
            /* items: each product becomes a stacked card so names get the full width */
            .od-items, .od-items tbody { display: block; }
            .od-items thead { display: none; }
            .od-items tbody tr { display: grid; grid-template-columns: 56px minmax(0, 1fr) auto; grid-template-areas: "img name name" "img qty total"; gap: 4px 12px; align-items: center;
                margin-bottom: 8px; padding: 10px; border: 1.5px solid var(--od-edge); border-radius: 12px; background: var(--od-surface); }
            .od-items tbody td { padding: 0 !important; border: 0 !important; border-radius: 0 !important; background: transparent !important; width: auto !important; text-align: left !important; }
            .od-items tbody td:nth-child(1) { grid-area: img; align-self: start; }
            .od-items tbody td:nth-child(2) { grid-area: name; }
            .od-items tbody td:nth-child(3) { grid-area: qty; }
            .od-items tbody td:nth-child(4) { grid-area: total; text-align: right !important; }
        }

        /* Approve / Reject payment buttons (existing forms, just given proper size) */
        .info-card form .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 10px 14px; border: 0; border-radius: 10px; font-size: 0.9rem; font-weight: 700; color: #fff; cursor: pointer; }

        /* Summary + QR */
        .order-summary-box { border: 1.5px dashed var(--od-edge); }
        .summary-total { color: var(--od-text); }
        .od-qr-body { display: flex; align-items: center; gap: 14px; }
        .od-qr-img { width: 130px; height: 130px; flex: 0 0 130px; border: 4px solid #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18); background: var(--surface); }
        .od-qr-body p { margin: 0; font-size: 0.85rem; line-height: 1.45; color: var(--od-muted); }
        .od-qr-body small { color: var(--od-muted); }

        @media (max-width: 760px) {
            .od-header { padding: 16px; }
            .od-head-actions { width: 100%; justify-content: space-between; }
            /* timeline becomes a vertical list */
            .od-steps { flex-direction: column; gap: 0; }
            .od-step { flex-direction: row; align-items: center; text-align: left; gap: 12px; padding: 0 0 14px; }
            .od-step:last-child { padding-bottom: 0; }
            .od-step::before { top: 22px; bottom: -6px; right: auto; left: 13px; width: 3px; height: auto; }
            .od-step:first-child::before { display: none; }
            .od-step:not(:first-child)::before { top: -14px; bottom: auto; height: 22px; }
            .od-step-label { flex: 1; padding: 0; font-size: 0.9rem; }
            .od-step-time { margin-left: auto; white-space: nowrap; }
            .od-timeline { padding: 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>
    <div class="main-content">
        <a class="od-back" href="orders.php"><i class="fas fa-arrow-left"></i> Back to All Orders</a>

        <!-- Order header: who/what/when at a glance -->
        <div class="od-header">
            <div class="od-head-main">
                <h1>Order Details</h1>
                <p class="od-number">#<?php echo htmlspecialchars($order['order_number']); ?></p>
                <div class="od-chips">
                    <span class="od-pill od-pill-<?php echo htmlspecialchars($od_status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $od_status))); ?></span>
                    <span class="od-chip"><i class="fas <?php echo $od_is_pickup ? 'fa-store' : 'fa-truck'; ?>"></i> <?php echo $od_is_pickup ? 'Pickup' : 'Delivery'; ?></span>
                    <span class="od-chip"><i class="fas fa-wallet"></i> <?php echo htmlspecialchars($od_pay_label); ?></span>
                    <span class="od-chip"><i class="far fa-calendar-alt"></i> Placed <?php echo htmlspecialchars(date('M j, Y \a\t g:i A', strtotime((string) $order['created_at']))); ?></span>
                </div>
            </div>
            <div class="od-head-actions">
                <div class="od-total-box"><span>Order Total</span><strong>PHP <?php echo number_format((float) $order['total'], 2); ?></strong></div>
                <a class="od-btn od-btn-teal" href="<?php echo BASE_URL; ?>/modules/admin/print_receipt.php?id=<?php echo $order_id; ?>"><i class="fas fa-receipt"></i> Print E-Receipt</a>
            </div>
        </div>

        <!-- Notification Alerts -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['status_updated']) && $_GET['status_updated'] == 1): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>Order status has been updated successfully.</span>
            </div>
        <?php endif; ?>

        <!-- Status timeline: where this order is in its (pickup / delivery) workflow. Display only. -->
        <?php if ($od_status === 'cancelled'): ?>
            <div class="od-cancelled">
                <i class="fas fa-ban"></i>
                <div>
                    <strong>This order was cancelled</strong>
                    <span>Reason: <?php echo htmlspecialchars($order['cancellation_reason'] ?: 'Not provided'); ?></span>
                </div>
            </div>
        <?php else: ?>
            <section class="od-timeline" aria-label="Order progress">
                <ol class="od-steps">
                    <?php foreach ($od_flow as $key => $label): ?>
                        <?php
                            $i = (int) array_search($key, $od_keys, true);
                            $state = $i < $od_idx || ($od_is_final && $i === $od_idx) ? 'done' : ($i === $od_idx ? 'current' : 'todo');
                        ?>
                        <li class="od-step is-<?php echo $state; ?>"<?php echo $state === 'current' ? ' aria-current="step"' : ''; ?>>
                            <span class="od-dot"><?php if ($state === 'done'): ?><i class="fas fa-check"></i><?php elseif ($state === 'current'): ?><i class="fas fa-circle"></i><?php endif; ?></span>
                            <span class="od-step-label"><?php echo htmlspecialchars($label); ?></span>
                            <span class="od-step-time">
                                <?php if ($key === 'pending' && !empty($order['created_at'])): ?>
                                    <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $order['created_at']))); ?>
                                <?php elseif ($key === 'completed' && $state === 'done' && !empty($order['updated_at'])): ?>
                                    <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $order['updated_at']))); ?>
                                <?php elseif ($state === 'current' && !$od_is_final): ?>
                                    Current stage
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($od_stage_note !== ''): ?>
                    <p class="od-stage-note"><i class="fas fa-info-circle"></i> <strong><?php echo htmlspecialchars($od_flow[$od_cur]); ?>:</strong> <?php echo htmlspecialchars($od_stage_note); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="order-grid">
            <div class="order-main">
                <div class="info-card">
                    <h4><i class="fas fa-shopping-basket"></i> Order Items</h4>
                    <table class="od-items">
                        <thead><tr><th></th><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td style="width: 60px;"><img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img" alt=""></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
                                        <small class="od-muted"><?php echo !empty($item['variant_size']) ? htmlspecialchars($item['variant_size']) . ' &bull; ' : ''; ?>PHP <?php echo number_format($item['unit_price'], 2); ?> each</small>
                                    </td>
                                    <td><span class="od-qty">x<?php echo htmlspecialchars($item['quantity']); ?></span></td>
                                    <td><strong>PHP <?php echo number_format($item['total_price'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="info-card">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                    <div class="order-summary-box">
                        <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                        <div class="summary-row"><span>VAT (12%)</span><span>PHP <?php echo number_format($order['vat_amount'], 2); ?></span></div>
                        <div class="summary-row"><span>Delivery Fee<?php echo !empty($order['free_delivery']) ? ' (free delivery)' : ''; ?></span><span><?php echo (float) $order['delivery_fee'] > 0 ? 'PHP ' . number_format($order['delivery_fee'], 2) : 'PHP 0.00'; ?></span></div>
                        <?php if ((float) ($order['tip'] ?? 0) > 0): ?>
                            <div class="summary-row"><span>Tip</span><span>PHP <?php echo number_format($order['tip'], 2); ?></span></div>
                        <?php endif; ?>
                        <?php if ((float)$order['discount_amount'] > 0): ?>
                            <div class="summary-row" style="color: #10b981; font-weight: 600;"><span>Discount<?php echo !empty($order['voucher_code']) ? ' (' . htmlspecialchars($order['voucher_code']) . ')' : ''; ?></span><span>-PHP <?php echo number_format($order['discount_amount'], 2); ?></span></div>
                        <?php endif; ?>
                        <div class="summary-row" style="color: #27ae60;"><span>Loyalty Earned</span><span>+<?php echo number_format($order['loyalty_points_earned'], 2); ?> pts</span></div>
                        <div class="summary-row summary-total"><span>Total Amount</span><span>PHP <?php echo number_format($order['total'], 2); ?></span></div>
                    </div>
                </div>
                <div class="info-card">
                    <h4><i class="fas <?php echo $od_is_pickup ? 'fa-store' : 'fa-truck'; ?>"></i> <?php echo $od_is_pickup ? 'Pickup Details' : 'Delivery & Rider'; ?></h4>
                    <div class="od-grid2">
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo $od_is_pickup ? 'Store pickup' : 'Delivery'; ?></span></div>
                        <?php if ($od_is_pickup): ?>
                            <div class="info-item"><span class="info-label">Pickup date</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_date'] ?: '-'); ?></span></div>
                            <div class="info-item"><span class="info-label">Pickup time</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_time'] ?: '-'); ?></span></div>
                            <div class="info-item"><span class="info-label">Contact</span><span class="info-value"><?php echo htmlspecialchars($customer['phone'] ?: '-'); ?></span></div>
                            <?php if (defined('SHOP_ADDRESS') && SHOP_ADDRESS !== ''): ?>
                                <div class="info-item od-span2"><span class="info-label">Pickup location</span><span class="info-value od-left"><?php echo htmlspecialchars(SHOP_ADDRESS); ?></span></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="info-item"><span class="info-label">Delivery status</span><span class="info-value"><span class="od-tag od-tag-<?php echo ($od_delivery['status'] ?? '') === 'delivered' ? 'ok' : (($od_delivery['status'] ?? '') === 'in_transit' ? 'info' : 'warn'); ?>"><?php echo htmlspecialchars($od_delivery ? ($od_delivery_labels[$od_delivery['status']] ?? ucwords(str_replace('_', ' ', (string) $od_delivery['status']))) : 'No delivery record yet'); ?></span></span></div>
                            <div class="info-item"><span class="info-label">Contact</span><span class="info-value"><?php echo htmlspecialchars($order['delivery_phone'] ?: ($customer['phone'] ?: '-')); ?></span></div>
                            <div class="info-item"><span class="info-label">Assigned rider</span><span class="info-value"><?php
                                $od_rider_name = $od_rider ? trim(($od_rider['first_name'] ?? '') . ' ' . ($od_rider['last_name'] ?? '')) : '';
                                if ($od_rider && $od_rider_name === '') { $od_rider_name = (string) ($od_rider['username'] ?? ''); }
                                echo $od_rider_name !== '' ? htmlspecialchars($od_rider_name) : '<span class="od-muted">Not assigned yet</span>';
                            ?></span></div>
                            <div class="info-item"><span class="info-label">Vehicle</span><span class="info-value"><?php
                                $od_vehicle = $od_rider ? trim(($od_rider['vehicle_type'] ?? '') . (!empty($od_rider['plate_number']) ? ' - ' . $od_rider['plate_number'] : '')) : '';
                                echo $od_vehicle !== '' ? htmlspecialchars($od_vehicle) : '<span class="od-muted">-</span>';
                            ?></span></div>
                            <?php if (!empty($order['delivery_distance_km'])): ?>
                                <div class="info-item"><span class="info-label">Distance</span><span class="info-value"><?php echo number_format((float) $order['delivery_distance_km'], 1); ?> km</span></div>
                            <?php endif; ?>
                            <div class="info-item"><span class="info-label">Delivery fee</span><span class="info-value"><?php echo (float) $order['delivery_fee'] > 0 ? htmlspecialchars($od_money($order['delivery_fee'])) : 'Free'; ?></span></div>
                            <?php if (!empty($od_delivery['scheduled_at'])): ?>
                                <div class="info-item"><span class="info-label">Scheduled</span><span class="info-value"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($od_delivery['scheduled_at']))); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($od_delivery['delivered_at'])): ?>
                                <div class="info-item"><span class="info-label">Delivered at</span><span class="info-value"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($od_delivery['delivered_at']))); ?></span></div>
                            <?php endif; ?>
                            <div class="info-item od-span2"><span class="info-label">Delivery address</span><span class="info-value od-left"><?php echo htmlspecialchars($order['delivery_address'] ?: '-'); ?>
                                <?php if (is_numeric($order['delivery_latitude'] ?? null) && is_numeric($order['delivery_longitude'] ?? null)): ?>
                                    <a class="od-maplink" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?php echo urlencode($order['delivery_latitude'] . ',' . $order['delivery_longitude']); ?>"><i class="fas fa-map-marker-alt"></i> Open in Maps</a>
                                <?php endif; ?>
                            </span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-card">
                    <h4><i class="fas fa-sticky-note"></i> Notes & Instructions</h4>
                    <?php
                    $od_note = trim((string) ($order['order_notes'] ?? ''));
                    $od_instr = trim((string) ($order['delivery_instructions'] ?? ''));
                    if ($od_instr === '') { $od_instr = trim((string) ($od_delivery['instructions'] ?? '')); }
                    ?>
                    <div class="od-notes">
                        <div class="od-note-box">
                            <span class="od-note-title"><i class="fas fa-comment-dots"></i> Customer order notes</span>
                            <p><?php echo $od_note !== '' ? nl2br(htmlspecialchars($od_note)) : '<span class="od-muted">No notes from the customer.</span>'; ?></p>
                        </div>
                        <?php if (!$od_is_pickup): ?>
                        <div class="od-note-box">
                            <span class="od-note-title"><i class="fas fa-route"></i> Delivery instructions</span>
                            <p><?php echo $od_instr !== '' ? nl2br(htmlspecialchars($od_instr)) : '<span class="od-muted">No special delivery instructions.</span>'; ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($delivery_proof): ?>
                <div class="info-card" style="border-left: 5px solid #10b981;">
                    <h4><i class="fas fa-camera"></i> Proof of Delivery</h4>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px;">
                        <img src="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>"
                             alt="Proof of delivery thumbnail"
                             class="receipt-thumb"
                             onclick="openProofModal('<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>', 'Proof of Delivery')"
                             onerror="this.replaceWith(Object.assign(document.createElement('span'), {textContent: 'Proof photo unavailable', style: 'color:#b91c1c; font-size:0.8rem; font-weight:600;'}))">
                        <button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>', 'Proof of Delivery')">
                            <i class="fas fa-expand"></i> View Full Photo
                        </button>
                    </div>
                    <?php if (!empty($delivery_proof['rider_name']) && trim($delivery_proof['rider_name']) !== ''): ?>
                        <div class="info-item"><span class="info-label">Rider</span><span class="info-value"><?php echo htmlspecialchars($delivery_proof['rider_name']); ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($delivery_proof['plate_number'])): ?>
                        <div class="info-item"><span class="info-label">Vehicle</span><span class="info-value"><?php echo htmlspecialchars($delivery_proof['vehicle_type'] . ' - ' . $delivery_proof['plate_number']); ?></span></div>
                    <?php endif; ?>
                    <div class="info-item"><span class="info-label">Delivered At</span><span class="info-value"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($delivery_proof['created_at']))); ?></span></div>
                    <?php if (!empty($delivery_proof['notes'])): ?>
                        <div class="info-item" style="flex-direction: column; gap: 5px;">
                            <span class="info-label">Delivery Notes</span>
                            <span class="info-value" style="font-weight: 500; font-size: 0.85rem; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #eef2f7; width: 100%; box-sizing: border-box;"><?php echo nl2br(htmlspecialchars($delivery_proof['notes'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="order-sidebar">
                <div class="info-card">
                    <h4><i class="fas fa-user"></i> Customer Info</h4>
                    <div class="od-cust-head">
                        <span class="od-avatar"><?php echo htmlspecialchars($od_initials((string) $customer['name'])); ?></span>
                        <div>
                            <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                            <span class="od-muted"><?php echo !empty($customer['created_at']) ? 'Customer since ' . htmlspecialchars(date('M Y', strtotime($customer['created_at']))) : 'Customer'; ?></span>
                        </div>
                    </div>
                    <div class="od-stats">
                        <div><strong><?php echo number_format((float) ($customer['loyalty_points'] ?? 0), 2); ?></strong><span>Loyalty pts</span></div>
                        <div><strong><?php echo $od_cust['orders']; ?></strong><span>Orders</span></div>
                        <div><strong><?php echo $od_cust['done']; ?></strong><span>Completed</span></div>
                    </div>
                    <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($customer['email']); ?></span></div>
                    <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($customer['phone']); ?></span></div>
                    <?php if (!empty($customer['address'])): ?>
                        <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Address on file</span><span class="info-value od-left" style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($customer['address']); ?></span></div>
                    <?php endif; ?>
                    <div class="info-item"><span class="info-label">Total spent (all orders)</span><span class="info-value"><?php echo htmlspecialchars($od_money($od_cust['spent'])); ?></span></div>
                    <a class="od-linkbtn" href="<?php echo BASE_URL; ?>/modules/customers/customer_details.php?id=<?php echo (int) $order['customer_id']; ?>"><i class="fas fa-user-circle"></i> View customer profile</a>
                </div>
                <div class="info-card">
                    <h4><i class="fas fa-credit-card"></i> Payment</h4>
                    <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo htmlspecialchars($od_pay_label); ?></span></div>
                    <div class="info-item"><span class="info-label">Payment status</span><span class="info-value"><span class="od-tag od-tag-<?php echo $od_pay_state[1]; ?>"><?php echo htmlspecialchars($od_pay_state[0]); ?></span></span></div>
                    <?php if ($order['payment_method'] === 'bank'): ?>
                        <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php elseif ($order['payment_reference']): ?>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php endif; ?>

                    <?php if (in_array($order['payment_method'], ['gcash', 'bank'], true)): ?>
                        <?php
                        // Receipt/proof-of-payment viewer — applies to both
                        // GCash (the receipt screenshot uploaded at checkout)
                        // and Bank Transfer (unchanged from before), since
                        // both now share the same tbl_orders.payment_proof_path
                        // column. Only shown for these two methods — a Cash/
                        // Pay-at-Shop order never has a receipt to begin with.
                        // Uploading a receipt only means "proof submitted" —
                        // it does NOT change order_status or payment_settled;
                        // the Approve/Reject buttons below are still the only
                        // thing that does.
                        $receipt_label = $order['payment_method'] === 'gcash' ? 'GCash Receipt' : 'Payment Proof';
                        ?>
                        <div class="info-item" style="align-items: center;">
                            <span class="info-label"><?php echo htmlspecialchars($receipt_label); ?></span>
                            <span class="info-value">
                                <?php if ($order['payment_proof_path']): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 8px;">
                                        <img src="<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>"
                                             alt="Receipt thumbnail"
                                             class="receipt-thumb"
                                             onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>', '<?php echo htmlspecialchars(addslashes($receipt_label)); ?>')"
                                             onerror="this.replaceWith(Object.assign(document.createElement('span'), {textContent: 'Receipt file unavailable', style: 'color:#b91c1c; font-size:0.8rem; font-weight:600;'}))">
                                        <button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>', '<?php echo htmlspecialchars(addslashes($receipt_label)); ?>')">
                                            <i class="fas fa-receipt"></i> View Receipt
                                        </button>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-weight: 600; font-size: 0.85rem;">No receipt uploaded</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($order['order_status'] === 'pending' && ($order['payment_method'] === 'gcash' || $order['payment_method'] === 'bank')): ?>
                        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; gap: 10px;">
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="approve_payment">
                                <button type="submit" class="button" style="width: 100%; background: #10b981;"><i class="fas fa-check-circle"></i> Approve</button>
                            </form>
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to reject this payment and cancel the order?');">
                                <input type="hidden" name="action" value="reject_payment">
                                <button type="submit" class="button button-secondary" style="width: 100%; background: #ef4444; color: white;"><i class="fas fa-times-circle"></i> Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
                <?php if ($delivery_details && $order['order_status'] !== 'cancelled'): ?>
                <div class="info-card od-qr-card">
                    <h4><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                    <?php
                    // Dynamic JSON payload per order
                    $qr_data = json_encode([
                        'delivery_id' => (int)$delivery_details['id'],
                        'order_id'    => (int)$order['id'],
                        'token'       => $delivery_details['qr_confirmation_token']
                    ]);

                    $qr_code_uri = null;

                    // Option 1: Try Endroid PHP Library
                    if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
                        try {
                            $qrCode = \Endroid\QrCode\QrCode::create($qr_data);
                            $writer = new \Endroid\QrCode\Writer\PngWriter();
                            $qr_code_uri = $writer->write($qrCode)->getDataUri();
                        } catch (Throwable $t) {
                            $qr_code_uri = null;
                        }
                    }

                    // Option 2: Fallback to QuickChart API (Works reliably for any order)
                    if (!$qr_code_uri) {
                        $qr_code_uri = "https://quickchart.io/qr?size=250&text=" . urlencode($qr_data);
                    }
                    ?>
                    <div class="od-qr-body">
                        <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" class="od-qr-img">
                        <p><?php echo $od_is_pickup ? 'Confirmation QR for this pickup order.' : 'The rider scans this QR at hand-over to confirm delivery.'; ?><br><small>Delivery Ref ID: #<?php echo (int)$delivery_details['id']; ?></small></p>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-card">
                    <h4><i class="fas fa-clipboard-list"></i> Order Record</h4>
                    <div class="info-item"><span class="info-label">Order ID</span><span class="info-value">#<?php echo (int) $order['id']; ?></span></div>
                    <div class="info-item"><span class="info-label">Order source</span><span class="info-value"><?php echo ($order['order_source'] ?? 'customer') === 'admin' ? 'Created by admin' : 'Customer (online)'; ?></span></div>
                    <div class="info-item"><span class="info-label">Placed</span><span class="info-value"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $order['created_at']))); ?></span></div>
                    <?php if (!empty($order['updated_at'])): ?>
                        <div class="info-item"><span class="info-label">Last updated</span><span class="info-value"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($order['updated_at']))); ?></span></div>
                    <?php endif; ?>
                    <div class="info-item"><span class="info-label">Items</span><span class="info-value"><?php echo count($order_items); ?> product<?php echo count($order_items) === 1 ? '' : 's'; ?> &middot; <?php echo array_sum(array_map(static fn ($i) => (int) $i['quantity'], $order_items)); ?> pcs</span></div>
                    <div class="info-item"><span class="info-label">Stock deducted</span><span class="info-value"><?php echo !empty($order['stock_deducted']) ? 'Yes' : 'Not yet'; ?></span></div>
                    <div class="info-item"><span class="info-label">Loyalty points</span><span class="info-value"><?php echo number_format((float) $order['loyalty_points_earned'], 2); ?> pts <?php echo in_array($od_status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed', 'reviews'], true) ? '(credited)' : ($od_status === 'cancelled' ? '(not credited)' : '(pending)'); ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Proof of Payment Modal (GCash receipt / Bank transfer proof) — a
         lightbox over this same page, never a new tab/window/redirect. -->
    <div id="proofModal" class="modal" role="dialog" aria-modal="true" aria-label="Payment receipt viewer">
        <span class="close-modal" onclick="closeProofModal()" title="Close" aria-label="Close">&times;</span>
        <div id="proofModalCaption" class="modal-caption"></div>
        <img class="modal-content" id="proofModalImage" alt="Payment receipt"
             onerror="this.style.display='none'; document.getElementById('proofModalError').style.display='block';">
        <div id="proofModalError" class="modal-error">
            <i class="fas fa-triangle-exclamation"></i> Receipt file unavailable
        </div>
    </div>

    <script>
        const modal = document.getElementById('proofModal');
        const modalImg = document.getElementById('proofModalImage');
        const modalCaption = document.getElementById('proofModalCaption');
        const modalError = document.getElementById('proofModalError');

        function openProofModal(src, label) {
            modalError.style.display = 'none';
            modalImg.style.display = 'block';
            modalImg.src = src;
            modalCaption.textContent = label || 'Payment Receipt';
            modal.classList.add('active');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden'; // lock background scroll while open
        }

        function closeProofModal() {
            modal.classList.remove('active');
            modal.style.display = 'none';
            modalImg.src = '';
            document.body.style.overflow = '';
        }

        // Click the dark overlay (not the image/caption/close button) to close.
        window.addEventListener('click', (event) => {
            if (event.target === modal) closeProofModal();
        });

        // ESC closes the modal, same as the X button.
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('active')) closeProofModal();
        });
    </script>
</body>
</html>