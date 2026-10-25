<?php
/**
 * Success view for modules/admin/create_customer_order.php — included only
 * from that file (relies on $success_order, $customer, $customer_id,
 * $pending_orders_count, $unread_count, $current_page, $is_staff,
 * $portal_base, $order_details_base already being set — see that file).
 * Not directly web-reachable in a meaningful way on its own (it echoes
 * undefined-variable notices without them), which is fine since it's never
 * linked to directly.
 */
$payment_labels = [
    'cod' => 'Cash on Delivery',
    'pay_at_shop' => 'Cash (Pay at Shop)',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer',
];
$payment_label = $payment_labels[$success_order['payment_method']] ?? ucfirst($success_order['payment_method']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Created - DPS <?php echo $is_staff ? 'Staff' : 'Admin'; ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .aco-success-box { max-width: 480px; margin: 40px auto; background: var(--surface); border-radius: 16px; box-shadow: 0 10px 20px rgba(0,0,0,0.06); padding: 36px 30px; text-align: center; }
        .aco-success-box .icon { width: 64px; height: 64px; border-radius: 50%; background: var(--success-soft); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 16px; }
        .aco-success-box h2 { margin: 0 0 4px; }
        .aco-success-box .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 22px; }
        .aco-success-details { text-align: left; background: var(--surface-2); border-radius: 10px; padding: 16px 18px; margin-bottom: 22px; }
        .aco-success-details dt { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); margin-top: 12px; }
        .aco-success-details dt:first-child { margin-top: 0; }
        .aco-success-details dd { margin: 3px 0 0; font-size: 0.95rem; color: var(--text); font-weight: 600; }
        .aco-success-actions { display: flex; flex-direction: column; gap: 10px; }
        .aco-success-actions a { padding: 12px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.9rem; }
        .aco-btn-primary { background: var(--dk-primary, #4a3e94); color: var(--dk-on-primary, #fff); }
        .aco-btn-secondary { background: var(--surface-2); color: var(--text-2); }
        .aco-btn-tertiary { background: none; color: var(--muted); }
    </style>
</head>
<body>
    <?php include $is_staff ? __DIR__ . '/../staff/sidebar.php' : __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="aco-success-box">
            <div class="icon"><i class="fas fa-check"></i></div>
            <h2>Order Created Successfully</h2>
            <p class="sub">This order is now saved under the customer's account, just like a normal order.</p>

            <dl class="aco-success-details">
                <dt>Order</dt>
                <dd>#<?php echo htmlspecialchars($success_order['order_number']); ?></dd>
                <dt>Customer</dt>
                <dd><?php echo htmlspecialchars($customer['name']); ?></dd>
                <dt>Total</dt>
                <dd>₱<?php echo number_format((float) $success_order['total'], 2); ?></dd>
                <dt>Payment</dt>
                <dd><?php echo htmlspecialchars($payment_label); ?></dd>
                <dt>Fulfillment</dt>
                <dd><?php echo ucfirst($success_order['fulfillment_type']); ?></dd>
            </dl>

            <div class="aco-success-actions">
                <a href="<?php echo $order_details_base; ?>/order_details.php?id=<?php echo (int) $success_order['id']; ?>" class="aco-btn-primary"><i class="fas fa-eye"></i> View Order</a>
                <a href="create_customer_order.php?customer_id=<?php echo $customer_id; ?>" class="aco-btn-secondary"><i class="fas fa-plus"></i> Create Another Order</a>
                <a href="<?php echo $portal_base; ?>/customers.php" class="aco-btn-tertiary">Back to Customers</a>
            </div>
        </div>
    </div>
</body>
</html>
