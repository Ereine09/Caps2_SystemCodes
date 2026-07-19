<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$order = null;
$order_items = [];
$orders = [];

if (isset($_GET['id'])) {
    $order_id = (int) $_GET['id'];
    $order = get_order_by_id($order_id, (int) $customer['id']);
    if ($order) {
        $order_items = get_order_items($order['id']);
    }
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
            elseif (in_array($status, ['ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive'])) $current_pos = 3;
            elseif ($status === 'completed') $current_pos = 4;
        ?>

        <div class="order-details">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="margin: 0;">Order #<?php echo htmlspecialchars($order['order_number']); ?></h3>
                <span class="status-pill status-<?php echo $status; ?>" style="padding: 6px 15px; font-size: 0.9rem;">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_status']))); ?>
                </span>
            </div>

            <?php if ($status === 'cancelled'): ?>
                <div class="alert-message alert-error" style="margin-bottom: 25px;">This order has been cancelled.</div>
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
                                        <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br><small style="color: #64748b;">PHP <?php echo number_format($item['unit_price'], 2); ?></small></td>
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
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-credit-card"></i> Payment</h4>
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo strtoupper($order['payment_method']); ?></span></div>
                        <?php if ($order['payment_method'] === 'bank'): ?>
                            <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                            <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name']); ?></span>
                            <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                            <?php if ($order['payment_proof_path']): ?>
                                <div class="info-item"><span class="info-label">Proof</span><span class="info-value"><a href="<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>" target="_blank" class="button-link">View Proof</a></span></div>
                            <?php endif; ?>
                        <?php elseif ($order['payment_reference']): ?><div class="info-item"><span class="info-label">Reference</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div><?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                        <div class="order-summary-box">
                            <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
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
            <div style="margin-top: 30px;"><a class="button" href="<?php echo BASE_URL; ?>/customer/orders.php">Back to Orders</a></div>
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
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
