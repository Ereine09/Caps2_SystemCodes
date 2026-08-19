<?php
// The autoloader must be included before any `use` statements for vendor libraries.
// auth.php includes functions.php, which in turn includes the Composer autoloader.
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    require_customer_login();
    $customer = current_customer();
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);
    $cancellation_reason = trim($_POST['cancellation_reason'] ?? 'No reason provided.');

    if ($order_id_to_cancel > 0) {
        $order = get_order_by_id($order_id_to_cancel, (int)$customer['id']);

        if ($order && in_array($order['order_status'], ['pending', 'confirmed'])) {
            $conn->begin_transaction();
            try {
                // 1. Restore stock ONLY if the order was already confirmed (as stock is now deducted on confirmation)
                if ($order['order_status'] === 'confirmed') {
                    $order_items_to_restore = get_order_items($order_id_to_cancel);
                    foreach ($order_items_to_restore as $item) {
                        $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                        restore_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity']);
                    }
                }

                // 2. Update the order status to 'cancelled'
                $update_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled', cancellation_reason = ? WHERE id = ?");
                $update_stmt->bind_param('si', $cancellation_reason, $order_id_to_cancel);
                $update_stmt->execute();
                $update_stmt->close();

                $conn->commit();
                $_SESSION['message'] = 'Your order has been successfully cancelled.';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['message'] = 'There was an error cancelling your order. Please contact support.';
                $_SESSION['message_type'] = 'error';
            }
        }
    }
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id_to_cancel);
    exit();
}

require_customer_login();

$customer = current_customer();

// Ensure tbl_deliveries table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    delivery_status ENUM('pending', 'accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed_delivery', 'cancelled') NOT NULL DEFAULT 'pending',
    qr_confirmation_token VARCHAR(255) NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$order = null;
$order_items = [];
$delivery_details = null;
$delivery_proof = null;
$orders = [];
if (isset($_GET['id'])) {
    $order_id = (int) $_GET['id'];
    $order = get_order_by_id($order_id, (int) $customer['id']);
    $delivery_details = $order ? get_delivery_details_by_order_id($order['id']) : null;
    $delivery_proof = $order ? get_order_delivery_proof($order['id']) : null;


    // --- DYNAMICALLY ENSURE DELIVERY RECORD & QR TOKEN FOR ANY ORDER ID ---
    if ($order && $order['order_status'] !== 'cancelled') {
        if (!$delivery_details) {
            // Auto-create a delivery record if it doesn't exist yet in tbl_delivery
            $token = bin2hex(random_bytes(16));
            $insert_stmt = $conn->prepare("INSERT INTO tbl_deliveries (order_id, delivery_status, qr_confirmation_token) VALUES (?, 'pending', ?)");
            if ($insert_stmt) {
                $insert_stmt->bind_param("is", $order['id'], $token);
                $insert_stmt->execute();
                $insert_stmt->close();
                // Refresh details
                $delivery_details = get_delivery_details_by_order_id($order['id']);
            }
        } elseif (empty($delivery_details['qr_confirmation_token'])) {
            // Auto-generate token if record exists but token is missing
            $token = bin2hex(random_bytes(16));
            $update_stmt = $conn->prepare("UPDATE tbl_deliveries SET qr_confirmation_token = ? WHERE id = ?");
            if ($update_stmt) {
                $update_stmt->bind_param("si", $token, $delivery_details['id']);
                $update_stmt->execute();
                $update_stmt->close();
                $delivery_details['qr_confirmation_token'] = $token;
            }
        }
    }

    if ($order) {
        $order_items = get_order_items($order['id']);
    }

    // Include the new QR helper
    require_once __DIR__ . '/../modules/admin/qr_helper.php';
} else {
    $orders = get_customer_orders((int) $customer['id']);
}
?>
<?php $page_title = 'Your Orders'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .status-tracker {
        display: flex;
        justify-content: space-between;
        margin-bottom: 40px;
        position: relative;
        padding: 20px 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .status-tracker::before {
        content: '';
        position: absolute;
        top: 42px;
        left: 10%;
        right: 10%;
        height: 3px;
        background: #e2e8f0;
        z-index: 1;
    }
    .status-step {
        position: relative;
        z-index: 2;
        text-align: center;
        flex: 1;
    }
    .step-icon {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #fff;
        border: 4px solid #e2e8f0;
        margin: 0 auto 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: #94a3b8;
        transition: all 0.3s ease;
    }
    .status-step.completed .step-icon { border-color: #10b981; background: #10b981; color: #fff; }
    .status-step.active .step-icon { border-color: #312e81; background: #312e81; color: #fff; box-shadow: 0 0 0 4px rgba(49, 46, 129, 0.1); }
    .step-label { font-size: 0.8rem; font-weight: 700; color: #64748b; }
    .status-step.active .step-label { color: #312e81; }
    .status-step.completed .step-label { color: #10b981; }

    .order-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 20px; }
    .info-card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; margin-bottom: 25px; }
    .info-card h4 { margin-top: 0; margin-bottom: 20px; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
    .info-item { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; }
    .info-label { color: #64748b; font-weight: 500; }
    .info-value { color: #1e293b; font-weight: 700; }
    
    .item-img { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: #f8fafc; border: 1px solid #eee; }
    .order-summary-box { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px dashed #cbd5e1; }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: #475569; }
    .summary-total { margin-top: 15px; padding-top: 15px; border-top: 2px solid #e2e8f0; font-size: 1.2rem; color: #1e293b; font-weight: 800; }

    /* Modal Header/Footer Styling */
    .modal-header {
        padding: 15px 25px;
        border-bottom: 1px solid #e2e8f0;
    }
    .modal-footer {
        padding: 15px 25px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
    }
    /* Status Pill Colors */
    .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; color: white; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #f39c12; }
    .status-confirmed, .status-processing, .status-ready_for_pickup { background: #3498db; }
    .status-to_ship, .status-to_receive { background: #16a085; }
    .status-reviews { background: #f1c40f; color: #333; }
    .status-out_for_delivery { background: #8e44ad; }
    .status-completed { background: #27ae60; }
    .status-cancelled { background: #c0392b; }

    @media (max-width: 900px) { .order-grid { grid-template-columns: 1fr; } }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Your Orders</h2>
        <p>Track fulfillment status, delivery details, and order history.</p>
    </div>
    <?php if ($order): ?>
        <?php 
            $status = $order['order_status'];
            $steps = [
                'pending' => ['label' => 'Placed', 'icon' => 'fa-clipboard-list', 'pos' => 0],
                'confirmed' => ['label' => 'Confirmed', 'icon' => 'fa-check-circle', 'pos' => 1],
                'processing' => ['label' => 'Processing', 'icon' => 'fa-cog', 'pos' => 2],
                'shipping' => ['label' => 'Shipping', 'icon' => 'fa-truck', 'pos' => 3],
                'completed' => ['label' => 'Delivered', 'icon' => 'fa-box-open', 'pos' => 4]
            ];

            $current_pos = 0;
            if ($status === 'confirmed') $current_pos = 1;
            elseif ($status === 'processing') $current_pos = 2;
            elseif (in_array($status, ['ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive', 'shipping'])) $current_pos = 3;
            elseif ($status === 'completed') $current_pos = 4;
        ?>

        <div class="order-details">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0;">Order #<?php echo htmlspecialchars($order['order_number']); ?></h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="status-pill status-<?php echo $status; ?>" style="padding: 6px 15px; font-size: 0.9rem;">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_status']))); ?>
                    </span>
                    <?php if (in_array($order['order_status'], ['pending', 'confirmed'])): ?>
                        <button type="button" class="button" onclick="openCancelModal()" style="background: #ef4444; color: white; padding: 8px 14px; font-size: 0.85rem;">
                            <i class="fas fa-times-circle"></i> Cancel Order
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status === 'cancelled'): ?>
                <div class="alert-message alert-error" style="margin-bottom: 25px;">This order has been cancelled. Reason: <?php echo htmlspecialchars($order['cancellation_reason'] ?: 'Not provided'); ?></div>
            <?php else: ?>
                <div class="status-tracker">
                    <?php foreach ($steps as $key => $s): ?>
                        <?php 
                            $class = '';
                            if ($current_pos > $s['pos']) $class = 'completed';
                            elseif ($current_pos == $s['pos']) $class = 'active';
                        ?>
                        <div class="status-step <?php echo $class; ?>">
                            <div class="step-icon"><i class="fas <?php echo $s['icon']; ?>"></i></div>
                            <div class="step-label"><?php echo $s['label']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
<?php if ($delivery_proof): ?>
                <div class="info-card" style="margin-top: 25px; border-left: 5px solid #10b981;">
                    <h4><i class="fas fa-camera"></i> Proof of Delivery</h4>
                    <div style="display: flex; gap: 25px; align-items: flex-start; flex-wrap: wrap;">
                        <div style="max-width: 240px;">
                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" title="View full proof image">
                                <img src="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" alt="Proof of Delivery" style="width: 100%; max-width: 240px; border-radius: 10px; border: 4px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.12); cursor: zoom-in;">
                            </a>
                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" class="button" style="display: block; text-align: center; margin-top: 12px; font-size: 0.85rem;">
                                <i class="fas fa-expand"></i> View Proof of Delivery
                            </a>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <?php if (!empty($delivery_proof['rider_name']) && trim($delivery_proof['rider_name']) !== ''): ?>
                                <div class="info-item"><span class="info-label">Delivered By</span><span class="info-value"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($delivery_proof['rider_name']); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($delivery_proof['plate_number'])): ?>
                                <div class="info-item"><span class="info-label">Vehicle</span><span class="info-value"><?php echo htmlspecialchars($delivery_proof['vehicle_type'] . ' - ' . $delivery_proof['plate_number']); ?></span></div>
                            <?php endif; ?>
                            <div class="info-item"><span class="info-label">Delivered At</span><span class="info-value"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($delivery_proof['created_at']))); ?></span></div>
                            <?php if (!empty($delivery_proof['notes'])): ?>
                                <div class="info-item" style="flex-direction: column; gap: 5px;">
                                    <span class="info-label">Delivery Notes</span>
                                    <span class="info-value" style="font-weight: 500; font-size: 0.9rem; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #eef2f7;"><?php echo nl2br(htmlspecialchars($delivery_proof['notes'])); ?></span>
                                </div>
                            <?php endif; ?>
                            <p style="font-size: 0.8rem; color: #94a3b8; margin: 8px 0 0;">This photo confirms your order was delivered. If you were not home, please check the notes for where it was left.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($delivery_details && $order['order_status'] !== 'cancelled'): ?>

                <div class="info-card" style="margin-top: 25px; text-align: center; border-left: 5px solid #10b981;">
                    <h4 style="justify-content: center;"><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                    <p style="font-size: 0.9rem; color: #475569; max-width: 450px; margin: 0 auto 20px;">
                        Please present this QR code to the rider upon delivery to confirm you have received your order.
                    </p>
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
                    <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" style="width: 220px; height: 220px; border: 5px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 8px;">
                    <p style="margin-top: 10px; font-size: 0.75rem; color: #94a3b8;">Delivery Ref ID: #<?php echo (int)$delivery_details['id']; ?></p>
                </div>
            <?php endif; ?>

            <div class="order-grid">
                <div class="order-main">
                    <div class="info-card">
                        <h4><i class="fas fa-shopping-basket"></i> Order Items</h4>
                        <table class="customer-table">
                            <thead><tr><th></th><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($order_items as $item): ?>
                                    <tr>
                                        <td style="width: 60px;"><img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img" alt=""></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
                                            <small style="color: #64748b;">PHP <?php echo number_format($item['unit_price'], 2); ?></small>
                                            <?php if ($order['order_status'] === 'completed'): ?>
                                                <?php if (has_customer_reviewed_product((int)$customer['id'], (int)$item['product_id'], (int)$order['id'])): ?>
                                                    <div style="margin-top: 8px; font-size: 0.9rem; color: #10b981; font-weight: 600;"><i class="fas fa-check-circle"></i> Thank you for your review!</div>
                                                <?php else: ?>
                                                    <a href="submit_review.php?order_id=<?php echo $order['id']; ?>&product_id=<?php echo $item['product_id']; ?>" 
                                                       class="button" 
                                                       style="font-size: 0.85rem; padding: 8px 12px; margin-top: 8px; background: #f59e0b; display: inline-block;">
                                                       <i class="fas fa-star"></i> Write a Review
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
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
                        <h4><i class="fas fa-info-circle"></i> Fulfillment Info</h4>
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo ucfirst($order['fulfillment_type']); ?></span></div>
                        <?php if ($order['fulfillment_type'] === 'pickup'): ?>
                            <div class="info-item"><span class="info-label">Date</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_date']); ?></span></div>
                            <div class="info-item"><span class="info-label">Time</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_time']); ?></span></div>
                        <?php else: ?>
                            <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Address</span><span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($order['delivery_address']); ?></span></div>
                            <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($order['delivery_phone']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($order['order_notes'])): ?>
                            <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Order Notes</span><span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($order['order_notes'])); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-credit-card"></i> Payment</h4>
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo strtoupper($order['payment_method']); ?></span></div>
                        <?php if ($order['payment_method'] === 'bank'): ?>
                            <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                            <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name']); ?></span></div>
                            <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                            <?php if ($order['payment_proof_path']): ?>
                                <div class="info-item">
                                    <span class="info-label">Proof</span>
                                    <span class="info-value">
                                        <button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>')">View Proof</button>
                                    </span>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($order['payment_reference']): ?>
                            <div class="info-item"><span class="info-label">Reference</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                        <div class="order-summary-box">
                            <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                            <div class="summary-row"><span>VAT (12%)</span><span>PHP <?php echo number_format($order['vat_amount'], 2); ?></span></div>
                            <div class="summary-row"><span>Delivery Fee</span><span>PHP <?php echo number_format($order['delivery_fee'], 2); ?></span></div>
                            <?php if ((float)$order['discount_amount'] > 0): ?>
                                <div class="summary-row" style="color: #10b981; font-weight: 600;"><span>Discount</span><span>-PHP <?php echo number_format($order['discount_amount'], 2); ?></span></div>
                            <?php endif; ?>
                            <div class="summary-row" style="color: #27ae60;"><span>Loyalty Earned</span><span>+<?php echo number_format($order['loyalty_points_earned'], 2); ?> pts</span></div>
                            <div class="summary-row summary-total"><span>Total Paid</span><span>PHP <?php echo number_format($order['total'], 2); ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="margin-top: 30px;">
                <a class="button" href="<?php echo BASE_URL; ?>/customer/orders.php"><i class="fas fa-arrow-left"></i> Back to All Orders</a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($orders): ?>
            <table class="customer-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Order</th>
                        <th>Items Purchased</th>
                        <th>Status</th>
                        <th>Fulfillment</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($orders as $order_item): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($order_item['order_number']); ?></td>
                            <td style="max-width: 200px; font-size: 0.85rem; color: #64748b; line-height: 1.4;">
                                <?php echo htmlspecialchars($order_item['items_summary'] ?? 'N/A'); ?>
                            </td>
                            <td>
                                <span class="status-pill status-<?php echo $order_item['order_status']; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order_item['order_status']))); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($order_item['fulfillment_type'])); ?></td>
                            <td>PHP <?php echo number_format($order_item['total'], 2); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($order_item['created_at']))); ?></td>
                            <td><a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo $order_item['id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty-state">You have no orders yet. Start shopping to place your first order.</p>
            <a class="button" href="<?php echo BASE_URL; ?>/customer/products.php">Browse Products</a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Proof of Payment Modal -->
    <div id="proofModal" class="modal">
        <div class="modal-content" style="max-width: 600px; padding: 0;">
            <span class="close-modal" onclick="closeProofModal()" style="top: 15px; right: 25px; color: #fff; font-size: 30px;">&times;</span>
            <img id="proofModalImage" style="width: 100%; border-radius: 12px;">
        </div>
    </div>

    <!-- Cancellation Modal -->
    <div id="cancellationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Order</h3>
                <span class="close-modal" onclick="closeCancelModal()">&times;</span>
            </div>
            <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this order? This action cannot be undone.');" id="cancel-order-form">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="form-group" style="padding: 25px;">
                    <label>Order to Cancel:</label>
                    <p style="font-weight: bold; margin-top: 5px; margin-bottom: 15px;">#<?php echo htmlspecialchars($order['order_number']); ?></p>

                    <label for="cancellation_reason">Please provide a reason for cancellation:</label>
                    <textarea name="cancellation_reason" id="cancellation_reason" required style="min-height: 80px;"></textarea>
                    <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                        Please note: Cancelling this order is final and cannot be undone.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeCancelModal()">Keep Order</button>
                    <button type="submit" class="button" style="background: #ef4444; color: white;">Yes, Cancel This Order</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleNewAddressForm() {
        const selectedOption = document.querySelector('input[name="address_option"]:checked');
        const isNew = selectedOption && selectedOption.value === 'new';
        const newAddressFields = document.getElementById('new-address-fields');
        const newAddressInput = document.getElementById('delivery_address');
        const savedAddressText = document.getElementById('saved_address_text');
        const savedAddressPhone = document.getElementById('saved_address_phone');
        if (isNew) {
            newAddressFields.style.display = 'block';
            savedAddressText.value = ''; 
            savedAddressPhone.value = '';
        } else {
            newAddressFields.style.display = 'none';
            newAddressInput.value = ''; 
            if (selectedOption) {
                savedAddressText.value = selectedOption.dataset.address;
                savedAddressPhone.value = selectedOption.dataset.phone;
            }
        } 
        updateDeliveryFeeDisplay(); 
    }

        const proofModal = document.getElementById('proofModal');
        const modalImg = document.getElementById('proofModalImage');
        const cancelModal = document.getElementById('cancellationModal');

        function openProofModal(src) {
            proofModal.style.display = "flex";
            modalImg.src = src;
        }
        function closeProofModal() {
            proofModal.style.display = "none";
        }
        function openCancelModal() { cancelModal.style.display = "flex"; }
        function closeCancelModal() { cancelModal.style.display = "none"; }

        window.onclick = (event) => {
            if (event.target == proofModal) closeProofModal();
            if (event.target == cancelModal) closeCancelModal();
        };
    </script>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>