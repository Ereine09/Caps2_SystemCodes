<?php
require_once __DIR__ . '/includes/auth.php';
if (!customer_is_logged_in()) {
    // Guests (old bookmarks, the installed DPS Shop app's first start page) see the shop, not a login wall.
    header('Location: ' . BASE_URL . '/customer/products.php');
    exit();
}
require_customer_login(); // Ensure customer is logged in (and still exists)

$customer = current_customer();
$orders = get_customer_orders((int)($customer['id'] ?? 0));

// Fetch available rewards for the slider
$rewards_res = mysqli_query($conn, "SELECT * FROM rewards WHERE stock > 0 ORDER BY points ASC LIMIT 10");

$status_counts = [
    'pending' => 0,
    'confirmed' => 0,
    'processing' => 0,
    'ready_for_pickup' => 0,
    'out_for_delivery' => 0,
    'to_ship' => 0,
    'to_receive' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
foreach ($orders as $order) {
    if (isset($status_counts[$order['order_status']])) {
        $status_counts[$order['order_status']]++;
    }
}
?>
<?php
// ---- Display-only values derived from the data loaded above ----
require_once __DIR__ . '/../app/helpers/ui_helper.php';
$rewards = [];
if ($rewards_res) {
    while ($reward = mysqli_fetch_assoc($rewards_res)) { $rewards[] = $reward; }
}
$points_balance = (float) ($customer['loyalty_points'] ?? 0);
$next_reward = null;
foreach ($rewards as $r) {
    if ((float) $r['points'] > $points_balance) { $next_reward = $r; break; }
}
$active_orders = $status_counts['pending'] + $status_counts['confirmed'] + $status_counts['processing']
    + $status_counts['ready_for_pickup'] + $status_counts['out_for_delivery'] + $status_counts['to_ship'] + $status_counts['to_receive'];
$recent_orders = array_slice($orders, 0, 4);
$first_name = explode(' ', trim((string) $customer['name']))[0] ?: $customer['name'];
?>
<?php $page_title = 'DPS Customer Dashboard'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .cd-hero {
        display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(260px, 1fr); gap: 24px; align-items: center;
        padding: 24px 26px; margin-bottom: 18px; border-radius: var(--radius-lg);
        background: linear-gradient(120deg, var(--primary-soft), var(--surface) 70%);
        border: 1px solid var(--border);
    }
    .cd-hero h1 { margin: 0 0 4px; font-size: var(--fs-2xl); font-weight: 600; color: var(--text); }
    .cd-hero p { margin: 0 0 16px; color: var(--text-2); }
    .cd-points { padding: 18px 20px; border-radius: var(--radius); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); }
    .cd-points-label { display: flex; align-items: center; gap: 8px; font-size: var(--fs-sm); color: var(--muted); }
    .cd-points-label i, .cd-points-label svg { color: var(--gold); }
    .cd-points-value { margin: 4px 0 10px; font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: var(--text); font-variant-numeric: tabular-nums; }
    .cd-points-value small { font-size: var(--fs-md); font-weight: 500; color: var(--muted); }
    .cd-progress { height: 8px; border-radius: 999px; background: var(--surface-3); overflow: hidden; }
    .cd-progress span { display: block; height: 100%; border-radius: inherit; background: var(--gold); }
    .cd-progress-note { margin: 8px 0 0; font-size: var(--fs-xs); color: var(--muted); }
    .cd-status-list { list-style: none; margin: 0; padding: 0; }
    .cd-status-list li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 20px; border-bottom: 1px solid var(--border); }
    .cd-status-list li:last-child { border-bottom: 0; }
    .cd-status-list li.is-zero { color: var(--faint); }
    .cd-status-list li.is-zero .ui-badge { opacity: .55; }
    .cd-status-list strong { font-variant-numeric: tabular-nums; }
    .cd-rewards { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(220px, 1fr); gap: 14px; overflow-x: auto; padding-bottom: 6px; scroll-snap-type: x mandatory; }
    .cd-reward {
        scroll-snap-align: start; display: flex; flex-direction: column; gap: 6px; min-width: 0; padding: 16px 18px;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm);
        color: var(--text); text-decoration: none; transition: border-color .15s, transform .15s, box-shadow .15s;
    }
    .cd-reward:hover { border-color: var(--border-strong); transform: translateY(-2px); box-shadow: var(--shadow); color: var(--text); }
    .cd-reward-icon { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: var(--gold-soft); color: var(--gold); margin-bottom: 4px; }
    .cd-reward-name { font-weight: 600; line-height: 1.35; }
    .cd-reward-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding-top: 6px; }
    .cd-quick { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
    .cd-quick a {
        display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: var(--radius);
        background: var(--surface); border: 1px solid var(--border); color: var(--text); font-weight: 500; text-decoration: none;
    }
    .cd-quick a:hover { border-color: var(--border-strong); background: var(--surface-2); color: var(--text); }
    @media (max-width: 860px) { .cd-hero { grid-template-columns: minmax(0, 1fr); padding: 20px; } }
</style>

<section class="customer-panel">
    <!-- Hero: greeting, main actions, loyalty balance -->
    <div class="cd-hero">
        <div>
            <h1>Hello, <?php echo ui_e($first_name); ?>!</h1>
            <p>Welcome to your <strong>Darius Poultry Supply</strong> account. Shop, track your orders, and turn your points into rewards.</p>
            <div class="ui-row">
                <a class="ui-btn ui-btn-primary ui-btn-lg" href="<?php echo BASE_URL; ?>/customer/products.php"><i class="fas fa-store"></i> Start Shopping</a>
                <a class="ui-btn ui-btn-lg" href="<?php echo BASE_URL; ?>/customer/orders.php"><i class="fas fa-history"></i> Purchase History</a>
            </div>
        </div>
        <div class="cd-points">
            <div class="cd-points-label"><i class="fas fa-star"></i> Available loyalty points</div>
            <div class="cd-points-value"><?php echo number_format($points_balance, 2); ?> <small>pts</small></div>
            <?php if ($next_reward): ?>
                <?php $pct = max(2, min(100, $points_balance / max(1, (float) $next_reward['points']) * 100)); ?>
                <div class="cd-progress" role="progressbar" aria-valuenow="<?php echo (int) $pct; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width: <?php echo round($pct, 1); ?>%;"></span></div>
                <p class="cd-progress-note"><?php echo number_format((float) $next_reward['points'] - $points_balance, 2); ?> pts to go for <strong><?php echo ui_e($next_reward['name']); ?></strong></p>
            <?php elseif ($rewards): ?>
                <p class="cd-progress-note">You have enough points to redeem a reward. <a href="reward_catalog.php">Browse rewards</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary -->
    <div class="ui-kpis">
        <a class="ui-kpi" href="<?php echo BASE_URL; ?>/customer/orders.php">
            <span class="ui-kpi-icon"><i class="fas fa-shopping-bag"></i></span>
            <span class="ui-kpi-body"><span class="ui-kpi-label">Total Orders</span><span class="ui-kpi-value"><?php echo count($orders); ?></span></span>
        </a>
        <a class="ui-kpi tone-info" href="<?php echo BASE_URL; ?>/customer/orders.php?tab=to_ship">
            <span class="ui-kpi-icon"><i class="fas fa-truck"></i></span>
            <span class="ui-kpi-body"><span class="ui-kpi-label">In Progress</span><span class="ui-kpi-value"><?php echo $active_orders; ?></span></span>
        </a>
        <a class="ui-kpi tone-success" href="<?php echo BASE_URL; ?>/customer/orders.php?tab=completed">
            <span class="ui-kpi-icon"><i class="fas fa-circle-check"></i></span>
            <span class="ui-kpi-body"><span class="ui-kpi-label">Completed</span><span class="ui-kpi-value"><?php echo $status_counts['completed']; ?></span></span>
        </a>
        <div class="ui-kpi tone-accent">
            <span class="ui-kpi-icon"><i class="fas fa-receipt"></i></span>
            <span class="ui-kpi-body"><span class="ui-kpi-label">Latest Order</span><span class="ui-kpi-value" style="font-size: var(--fs-md);"><?php echo $orders ? ui_e($orders[0]['order_number']) : 'None yet'; ?></span></span>
        </div>
    </div>

    <div class="ui-grid-main" style="margin-bottom: 18px;">
        <!-- Recent orders -->
        <section class="ui-card">
            <div class="ui-card-head">
                <h2 class="ui-card-title">Recent Orders</h2>
                <a class="ui-card-link" href="<?php echo BASE_URL; ?>/customer/orders.php">View all</a>
            </div>
            <?php if (empty($recent_orders)): ?>
                <div class="ui-empty">
                    <i class="fas fa-shopping-bag"></i>
                    <strong>No orders yet</strong>
                    <p>Your orders will show up here once you check out.</p>
                </div>
            <?php else: ?>
                <ul class="ui-list">
                    <?php foreach ($recent_orders as $ro): ?>
                        <li><a class="ui-list-row" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo (int) $ro['id']; ?>">
                            <span class="ui-icon-tile"><i class="fas <?php echo $ro['fulfillment_type'] === 'pickup' ? 'fa-store' : 'fa-truck'; ?>"></i></span>
                            <span class="ui-list-main">
                                <span class="ui-list-title">#<?php echo ui_e($ro['order_number']); ?></span>
                                <span class="ui-list-sub"><?php echo ui_e(date('M j, Y', strtotime($ro['created_at']))); ?> · <?php echo ui_e($ro['items_summary'] ?? ''); ?></span>
                            </span>
                            <span class="ui-list-end ui-stack-sm" style="justify-items: end; gap: 4px;">
                                <strong class="ui-num"><?php echo ui_peso($ro['total']); ?></strong>
                                <?php echo ui_order_status_badge($ro['order_status']); ?>
                            </span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- Order status summary -->
        <section class="ui-card">
            <div class="ui-card-head">
                <h2 class="ui-card-title">Order Status Summary</h2>
            </div>
            <ul class="cd-status-list">
                <?php foreach ($status_counts as $status => $count): ?>
                    <li class="<?php echo $count === 0 ? 'is-zero' : ''; ?>">
                        <?php echo ui_order_status_badge($status); ?>
                        <strong><?php echo $count; ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="ui-card-foot" style="justify-content: stretch;">
                <a class="ui-btn ui-btn-soft ui-btn-block" href="<?php echo BASE_URL; ?>/customer/messages.php"><i class="fas fa-headset"></i> Contact Staff</a>
            </div>
        </section>
    </div>

    <!-- Rewards -->
    <?php if ($rewards): ?>
        <section style="margin-bottom: 18px;">
            <div class="ui-row-between" style="margin-bottom: 12px;">
                <h2 class="ui-section-title" style="margin: 0;">Reward Catalog Highlights</h2>
                <a class="ui-card-link" href="reward_catalog.php">See all rewards <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="cd-rewards">
                <?php foreach ($rewards as $reward): $can_redeem = $points_balance >= (float) $reward['points']; ?>
                    <a class="cd-reward" href="reward_catalog.php?id=<?php echo (int) $reward['id']; ?>">
                        <span class="cd-reward-icon"><i class="fas fa-gift"></i></span>
                        <span class="cd-reward-name"><?php echo ui_e($reward['name']); ?></span>
                        <span class="cd-reward-foot">
                            <span class="ui-badge ui-badge-gold"><i class="fas fa-star"></i> <?php echo number_format($reward['points']); ?> pts</span>
                            <?php if ($can_redeem): ?><span class="ui-badge ui-badge-success">Redeemable</span><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Quick links -->
    <div class="cd-quick">
        <a href="<?php echo BASE_URL; ?>/customer/my_rewards.php"><span class="ui-icon-tile gold"><i class="fas fa-ticket"></i></span> My Vouchers</a>
        <a href="<?php echo BASE_URL; ?>/customer/profile.php"><span class="ui-icon-tile primary"><i class="fas fa-user-circle"></i></span> My Account</a>
        <a href="<?php echo BASE_URL; ?>/customer/addresses.php"><span class="ui-icon-tile info"><i class="fas fa-location-dot"></i></span> My Address</a>
        <a href="<?php echo BASE_URL; ?>/customer/notifications.php"><span class="ui-icon-tile warning"><i class="fas fa-bell"></i></span> Notifications</a>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
