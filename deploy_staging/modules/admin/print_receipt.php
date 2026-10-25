<?php
/**
 * Admin/Staff "Print E-Receipt" view — a dedicated, print-friendly page for
 * one order, opened from the 🧾 Print E-Receipt button on order_details.php
 * via normal same-tab navigation (no target="_blank", no window.open()).
 * Its own toolbar links back to order_details.php?id=<the same order>.
 *
 * This is a READ-ONLY page: it only SELECTs data and renders it. It never
 * changes order_status, never (re-)awards loyalty points, never touches
 * payment/delivery/customer records, and never generates a new QR — see
 * notifications_render_order_receipt_html() in app/helpers/notification_helper.php,
 * which builds the actual receipt markup and is shared with the Gmail
 * e-receipt (notifications_send_order_receipt()) so both look identical and
 * neither duplicates the other's totals/loyalty/QR logic.
 *
 * The QR itself reuses the exact same {delivery_id, order_id, token} payload
 * (from tbl_delivery.qr_confirmation_token) that customer/orders.php,
 * modules/admin/order_details.php's own QR panel, and the rider scanner all
 * already use — generated here via the existing modules/admin/qr_helper.php
 * (generate_qr_code_uri()), with the same QuickChart fallback used
 * everywhere else in this codebase. No second QR system.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/qr_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload || !in_array($payload['role'], ['admin', 'staff'], true)) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$order_id = (int) ($_GET['id'] ?? 0);
if ($order_id <= 0) {
    die('No order specified.');
}

$order = get_order_by_id($order_id);
if (!$order) {
    die('Order not found.');
}
$customer = get_customer_by_id((int) $order['customer_id']);
$items    = get_order_items($order_id);
// ensure_order_delivery_token() (customer/includes/functions.php) is the
// SAME single-source-of-truth function customer/orders.php and the Gmail
// e-receipt already use — it returns the existing tbl_delivery row/token if
// one exists, or lazily creates it if this order has never had one yet
// (common for orders the customer hasn't opened themselves). Using the
// passive get_delivery_details_by_order_id() here instead would leave the
// QR section missing on a large share of real orders. No second QR system;
// same token, same payload shape as every other QR in this app.
$delivery = ensure_order_delivery_token($order);

// --- Logo: plain <img src>, no PHPMailer/cid needed for a normal browser page. ---
$logo_src = SYSTEM_LOGO_URL;

// --- QR block: same payload/token as everywhere else in the system; only
// built when this order actually has a live delivery QR and isn't cancelled
// (matches order_details.php's own existing QR panel condition). ---
$qr_html = '';
if ($delivery && !empty($delivery['qr_confirmation_token']) && $order['order_status'] !== 'cancelled') {
    $qr_payload = json_encode([
        'delivery_id' => (int) $delivery['id'],
        'order_id'    => (int) $order['id'],
        'token'       => $delivery['qr_confirmation_token'],
    ]);

    $qr_code_uri = generate_qr_code_uri($qr_payload); // modules/admin/qr_helper.php — the existing helper
    if ($qr_code_uri === '') {
        $qr_code_uri = 'https://quickchart.io/qr?size=400&text=' . urlencode($qr_payload); // same fallback used elsewhere
    }

    $qr_html = "
        <div style='text-align:center;margin:0 0 24px;padding:20px 16px;background:#f9fafb;border-radius:10px;border:1px dashed #cbd5e1;'>
            <img src='" . htmlspecialchars($qr_code_uri, ENT_QUOTES, 'UTF-8') . "' alt='Delivery Confirmation QR' width='200' height='200' style='width:200px;height:auto;border:5px solid #ffffff;box-shadow:0 2px 8px rgba(0,0,0,0.12);border-radius:8px;'>
            <p style='margin:12px 0 0;font-weight:700;color:#111827;font-size:14px;'>Delivery Confirmation QR</p>
            <p style='margin:4px 0 0;font-size:12.5px;color:#64748b;'>Please show this QR code to the rider for delivery confirmation.</p>
            <p style='margin:8px 0 0;font-size:11px;color:#94a3b8;'>Delivery Ref ID: #" . (int) $delivery['id'] . "</p>
        </div>";
}

$receipt_html = notifications_render_order_receipt_html($order, $items, $customer, $conn, $logo_src, $qr_html);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Receipt - Order #<?php echo htmlspecialchars($order['order_number']); ?></title>
<style>
    /* Deliberately NOT loading admin_style.css / the admin sidebar here —
       this page has no admin chrome to hide in the first place. Only its
       own small on-screen toolbar (Print/Close) needs hiding when printed. */
    body { margin: 0; background: #e2e8f0; font-family: Arial, Helvetica, sans-serif; }
    .toolbar {
        position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; justify-content: space-between;
        align-items: center; gap: 10px; padding: 14px; background: #1f2937; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .toolbar a, .toolbar button {
        display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 8px;
        padding: 10px 18px; font-weight: 700; font-size: 0.9rem; cursor: pointer; text-decoration: none;
        font-family: inherit;
    }
    .btn-back { background: #374151; color: #e5e7eb; }
    .btn-back:hover { background: #4b5563; }
    .btn-print { background: #0f766e; color: #ffffff; }
    .btn-print:hover { background: #0e6377; }
    @media print {
        .toolbar { display: none !important; }
        body { background: #ffffff; }
    }
</style>
</head>
<body>
    <div class="toolbar no-print">
        <a class="btn-back" href="<?php echo BASE_URL; ?>/modules/admin/order_details.php?id=<?php echo $order_id; ?>">← Back to Order Details</a>
        <button type="button" class="btn-print" onclick="window.print()">🖨 Print Receipt</button>
    </div>

    <?php echo $receipt_html; ?>

</body>
</html>
