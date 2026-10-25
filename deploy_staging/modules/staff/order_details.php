<?php
/**
 * Staff — Order details.
 * Auth + authorization run before any POST/DB action.
 */

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$payload = require_staff();
$user_id = (int) $payload['user_id'];

$order_id = (int) ($_GET['id'] ?? 0);
if ($order_id <= 0) {
    header('Location: orders.php');
    exit();
}

$order = get_order_by_id($order_id);
if (!$order) {
    http_response_code(404);
    die('Order not found.');
}
$customer = get_customer_by_id((int) $order['customer_id']);

// --- Payment approve / reject (authenticated staff only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();
    $action = $_POST['action'];

    if ($action === 'approve_payment') {
        $conn->begin_transaction();
        try {
            // No-op if some other status change already deducted stock for this order.
            $stock_result = deduct_order_stock_once($conn, $order_id);
            if (!$stock_result['ok']) {
                throw new Exception($stock_result['error'] ?? 'Insufficient stock.');
            }

            $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'confirmed' WHERE id = ?");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            notifications_ensure_schema($conn);
            notifications_create($conn, [
                'customer_id'     => (int) $order['customer_id'],
                'type'            => 'order_confirmed',
                'channel'         => 'both',
                'title'           => 'Order Confirmed',
                'message'         => 'Your order #' . $order['order_number'] . ' has been confirmed.',
                'reference_table' => 'tbl_orders',
                'reference_id'    => $order_id,
                'email_to'        => (string) ($customer['email'] ?? ''),
            ]);

            header("Location: order_details.php?id=$order_id&status_updated=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Error approving order: ' . $e->getMessage();
            header("Location: order_details.php?id=$order_id");
            exit();
        }
    } elseif ($action === 'reject_payment') {
        $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $stmt->close();
        header("Location: order_details.php?id=$order_id&status_updated=1");
        exit();
    }
}

$order_items      = get_order_items($order_id);
$delivery_details = get_delivery_details_by_order_id($order_id);
// Proof of Delivery (rider's photo) - same query customer/orders.php and
// modules/admin/order_details.php already use. Not a new proof system.
$delivery_proof   = get_order_delivery_proof($order_id);

$pending_orders_count  = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$unread_messages_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - Staff</title>
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
        .button-link { background: var(--primary-soft); color: var(--link); padding: 6px 12px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.85rem; border: 1px solid #c7d2fe; cursor: pointer; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95rem; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fca5a5; }
        .alert-success { background: var(--success-soft); color: var(--success); border: 1px solid #6ee7b7; }
        .modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background: rgba(0,0,0,0.8); align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
        .modal-content { margin: auto; display: block; max-width: min(90vw, 700px); max-height: 80vh; width: auto; height: auto; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .modal-caption { position: absolute; top: 18px; left: 50%; transform: translateX(-50%); color: #f8fafc; font-weight: 700; font-size: 1rem; text-align: center; max-width: 80%; text-shadow: 0 1px 3px rgba(0,0,0,0.6); }
        .modal-error { display: none; color: #fca5a5; font-weight: 600; font-size: 0.95rem; background: rgba(0,0,0,0.4); padding: 14px 20px; border-radius: 8px; }
        .close-modal { position: absolute; top: 18px; right: 24px; color: #f1f1f1; font-size: 34px; font-weight: bold; cursor: pointer; line-height: 1; padding: 4px 10px; border-radius: 6px; }
        .close-modal:hover { background: rgba(255,255,255,0.15); }
        .receipt-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); cursor: pointer; flex-shrink: 0; }
        @media (max-width: 480px) { .modal-caption { font-size: 0.88rem; top: 12px; } .close-modal { top: 8px; right: 12px; font-size: 30px; } .modal-content { max-height: 72vh; } }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: var(--text-2); }
        .summary-total { margin-top: 15px; padding-top: 15px; border-top: 2px solid var(--border); font-size: 1.2rem; color: var(--text); font-weight: 800; }
        @media (max-width: 900px) { .order-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content" style="margin-left: 260px; padding: 30px;">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Order Details</h1>
            <p>Viewing order #<?php echo htmlspecialchars($order['order_number']); ?></p>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['status_updated']) && $_GET['status_updated'] == 1): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><span>Order status has been updated successfully.</span></div>
        <?php endif; ?>

        <?php if ($delivery_details && $order['order_status'] !== 'cancelled'): ?>
            <div class="info-card" style="margin-top: 25px; text-align: center; border-left: 5px solid #10b981;">
                <h4 style="justify-content: center;"><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                <p style="font-size: 0.9rem; color: #475569; max-width: 450px; margin: 0 auto 20px;">
                    Present this QR code to the rider upon delivery to confirm the order was received.
                </p>
                <?php
                $qr_data = json_encode([
                    'delivery_id' => (int) $delivery_details['id'],
                    'order_id'    => (int) $order['id'],
                    'token'       => $delivery_details['qr_confirmation_token'],
                ]);
                $qr_code_uri = null;
                if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
                    try {
                        $qrCode = \Endroid\QrCode\QrCode::create($qr_data);
                        $writer = new \Endroid\QrCode\Writer\PngWriter();
                        $qr_code_uri = $writer->write($qrCode)->getDataUri();
                    } catch (Throwable $t) {
                        $qr_code_uri = null;
                    }
                }
                if (!$qr_code_uri) {
                    $qr_code_uri = "https://quickchart.io/qr?size=250&text=" . urlencode($qr_data);
                }
                ?>
                <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" style="width: 220px; height: 220px; border: 5px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 8px;">
                <p style="margin-top: 10px; font-size: 0.75rem; color: #94a3b8;">Delivery Ref ID: #<?php echo (int) $delivery_details['id']; ?></p>
            </div>
        <?php endif; ?>

        <div class="order-grid">
            <div class="order-main">
                <div style="margin-top: 10px;">
                    <a href="orders.php" class="button button-secondary"><i class="fas fa-arrow-left"></i> Back to All Orders</a>
                </div>

                <?php if ($order['order_status'] === 'cancelled'): ?>
                    <div class="info-card">
                        <h4 style="color: #dc2626;"><i class="fas fa-ban"></i> Order Cancellation</h4>
                        <div class="info-item"><span class="info-label">Cancellation Reason</span><span class="info-value"><?php echo htmlspecialchars($order['cancellation_reason'] ?: 'Not provided'); ?></span></div>
                    </div>
                <?php endif; ?>

                <div class="info-card">
                    <h4><i class="fas fa-shopping-basket"></i> Order Items</h4>
                    <table style="width:100%;">
                        <thead><tr><th></th><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td style="width: 60px;"><img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img" alt=""></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                        <?php if (!empty($item['variant_size'])): ?><br><small style="color:#64748b;">Variant: <?php echo htmlspecialchars($item['variant_size']); ?></small><?php endif; ?>
                                        <br><small style="color: #64748b;">PHP <?php echo number_format($item['unit_price'], 2); ?></small>
                                    </td>
                                    <td>x<?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td><strong>PHP <?php echo number_format($item['total_price'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="order-sidebar">
                <div class="info-card">
                    <h4><i class="fas fa-user"></i> Customer Info</h4>
                    <div class="info-item"><span class="info-label">Name</span><span class="info-value"><?php echo htmlspecialchars($customer['name'] ?? 'N/A'); ?></span></div>
                    <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></span></div>
                    <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></span></div>
                </div>

                <div class="info-card">
                    <h4><i class="fas fa-info-circle"></i> Fulfillment Info</h4>
                    <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo ucfirst($order['fulfillment_type']); ?></span></div>
                    <?php if ($order['fulfillment_type'] === 'pickup'): ?>
                        <div class="info-item"><span class="info-label">Date</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_date'] ?? ''); ?></span></div>
                        <div class="info-item"><span class="info-label">Time</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_time'] ?? ''); ?></span></div>
                    <?php else: ?>
                        <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Address</span><span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($order['delivery_address'] ?? ''); ?></span></div>
                        <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($order['delivery_phone'] ?? ''); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="info-card">
                    <h4><i class="fas fa-credit-card"></i> Payment</h4>
                    <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo strtoupper($order['payment_method']); ?></span></div>
                    <?php if ($order['payment_method'] === 'bank'): ?>
                        <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name'] ?? ''); ?></span></div>
                        <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name'] ?? ''); ?></span></div>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference'] ?? ''); ?></span></div>
                    <?php elseif (!empty($order['payment_reference'])): ?>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php endif; ?>

                    <?php if (in_array($order['payment_method'], ['gcash', 'bank'], true)): ?>
                        <?php
                        // Receipt/proof-of-payment viewer — GCash (receipt
                        // uploaded at checkout) and Bank Transfer both share
                        // tbl_orders.payment_proof_path. Only shown for these
                        // two methods — Cash/Pay-at-Shop never has a receipt.
                        // Uploading a receipt only means "proof submitted",
                        // never auto-verified — the Approve/Reject buttons
                        // below are still the only thing that changes
                        // payment status.
                        $receipt_label = $order['payment_method'] === 'gcash' ? 'GCash Receipt' : 'Payment Proof';
                        ?>
                        <div class="info-item" style="align-items: center;">
                            <span class="info-label"><?php echo htmlspecialchars($receipt_label); ?></span>
                            <span class="info-value">
                                <?php if (!empty($order['payment_proof_path'])): ?>
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

                    <?php if ($order['order_status'] === 'pending' && in_array($order['payment_method'], ['gcash', 'bank'], true)): ?>
                        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; gap: 10px;">
                            <form method="POST" style="flex: 1;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="approve_payment">
                                <button type="submit" class="button" style="width: 100%; background: #10b981;"><i class="fas fa-check-circle"></i> Approve</button>
                            </form>
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Reject this payment and cancel the order?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="reject_payment">
                                <button type="submit" class="button button-secondary" style="width: 100%; background: #ef4444; color: white;"><i class="fas fa-times-circle"></i> Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>
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

                <div class="info-card">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                    <div class="order-summary-box">
                        <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                        <div class="summary-row"><span>VAT (12%)</span><span>PHP <?php echo number_format($order['vat_amount'] ?? 0, 2); ?></span></div>
                        <div class="summary-row"><span>Delivery Fee</span><span>PHP <?php echo number_format($order['delivery_fee'], 2); ?></span></div>
                        <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
                            <div class="summary-row" style="color: #10b981; font-weight: 600;"><span>Discount</span><span>-PHP <?php echo number_format($order['discount_amount'], 2); ?></span></div>
                        <?php endif; ?>
                        <div class="summary-row" style="color: #27ae60;"><span>Loyalty Earned</span><span>+<?php echo number_format($order['loyalty_points_earned'], 2); ?> pts</span></div>
                        <div class="summary-row summary-total"><span>Total Paid</span><span>PHP <?php echo number_format($order['total'], 2); ?></span></div>
                    </div>
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
            document.body.style.overflow = 'hidden';
        }
        function closeProofModal() {
            modal.classList.remove('active');
            modal.style.display = 'none';
            modalImg.src = '';
            document.body.style.overflow = '';
        }
        window.addEventListener('click', (e) => { if (e.target === modal) closeProofModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('active')) closeProofModal(); });
    </script>
</body>
</html>
