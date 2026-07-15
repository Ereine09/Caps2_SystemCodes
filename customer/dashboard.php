<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$orders = get_customer_orders((int)$customer['id']);

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
<?php $page_title = 'DPS Customer Dashboard'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    .stat-card {
        background: white;
        padding: 18px;
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .icon-points { background: #fff7ed; color: #f59e0b; }
    .icon-orders { background: #eef2ff; color: #6366f1; }
    .icon-last { background: #f0fdf4; color: #10b981; }
    
    .stat-info h3 { margin: 0; font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-info .stat-value { margin: 4px 0 0; font-size: 1.3rem; font-weight: 800; color: #1e293b; }

    .status-summary-box {
        background: white;
        padding: 18px;
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        margin-bottom: 20px;
    }
    .status-summary-box h2 { font-size: 1rem; margin-top: 0; margin-bottom: 15px; color: #1e293b; }
    .status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 10px;
    }
    .status-pill-card {
        padding: 12px;
        border-radius: 8px;
        text-align: center;
        border: 1px solid #f1f5f9;
        transition: 0.3s;
    }
    .status-pill-card:hover { border-color: #6366f1; transform: translateY(-2px); }
    
    /* Status Pill Colors */
    .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 3px 10px; border-radius: 999px; font-size: 0.65rem; color: white; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #f39c12; }
    .status-confirmed, .status-processing, .status-ready_for_pickup { background: #3498db; }
    .status-to_ship, .status-to_receive { background: #16a085; }
    .status-reviews { background: #f1c40f; color: #333; }
    .status-out_for_delivery { background: #8e44ad; }
    .status-completed { background: #27ae60; }
    .status-cancelled { background: #c0392b; }

    .status-border-pending { border-left: 4px solid #f39c12; }
    .status-border-confirmed, .status-border-processing, .status-border-ready_for_pickup { border-left: 4px solid #3498db; }
    .status-border-to_ship, .status-border-to_receive { border-left: 4px solid #16a085; }
    .status-border-out_for_delivery { border-left: 4px solid #8e44ad; }
    .status-border-completed { border-left: 4px solid #27ae60; }
    .status-border-cancelled { border-left: 4px solid #c0392b; }

    .status-pill-card span { display: block; font-size: 0.7rem; color: #64748b; font-weight: 600; margin-bottom: 4px; }
    .status-pill-card strong { font-size: 1rem; color: #1e293b; }

    .table-container {
        background: white;
        padding: 18px;
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .table-container h2 { font-size: 1rem; margin-top: 0; margin-bottom: 15px; color: #1e293b; }

    /* Decathlon Inspired Reward Slider */
    .reward-slider-container { margin-bottom: 30px; }
    .reward-slider-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    .reward-slider-header h2 { font-size: 1.1rem; margin: 0; color: #1e293b; }
    .reward-slider-header a { font-size: 0.85rem; color: #6366f1; text-decoration: none; font-weight: 600; }
    
    .reward-slider {
        display: flex;
        overflow-x: auto;
        gap: 15px;
        padding: 5px 5px 15px 5px;
        scroll-snap-type: x mandatory;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .reward-slider::-webkit-scrollbar { height: 6px; }
    .reward-slider::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .reward-voucher-card {
        flex: 0 0 240px;
        background: white;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        padding: 20px;
        scroll-snap-align: start;
        cursor: pointer;
        transition: 0.3s;
        position: relative;
        overflow: hidden;
    }
    .reward-voucher-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px; background: #0082c3; }
    .reward-voucher-card:hover { transform: translateY(-5px); box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.15); }
    .voucher-value { font-size: 1.2rem; font-weight: 900; color: #0082c3; margin-bottom: 5px; }
    .voucher-name { font-size: 0.9rem; color: #64748b; margin-bottom: 15px; height: 40px; overflow: hidden; }
    .voucher-points { 
        background: #eef2ff; 
        color: #4338ca; 
        padding: 6px 12px; 
        border-radius: 20px; 
        font-size: 0.8rem; 
        font-weight: 700; 
        display: inline-block; 
    }
</style>

<section class="customer-panel">
    <div class="welcome-header" style="margin-bottom: 30px;">
        <h1>Hello, <?php echo htmlspecialchars($customer['name']); ?>! 👋</h1>
        <p>Welcome to your <strong>Darius Poultry Supply</strong> account portal.</p>
    </div>
    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-icon icon-points"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <h3>Available Points</h3>
                <p class="stat-value"><?php echo number_format($customer['loyalty_points'] ?? 0, 2); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-orders"><i class="fas fa-shopping-bag"></i></div>
            <div class="stat-info">
                <h3>Total Orders</h3>
                <p class="stat-value"><?php echo count($orders); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-last"><i class="fas fa-receipt"></i></div>
            <div class="stat-info">
                <h3>Latest Order</h3>
                <p class="stat-value" style="font-size: 1rem;"><?php echo $orders ? htmlspecialchars($orders[0]['order_number']) : 'None'; ?></p>
            </div>
        </div>
    </div>

    <div class="customer-actions" style="margin-bottom: 30px; display: flex; gap: 15px;">
        <a class="button" href="<?php echo BASE_URL; ?>/customer/products.php">Start Shopping</a>
        <a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/orders.php">Purchase History</a>
        <a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/profile.php">My Account</a>
        <a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/addresses.php">My Address</a>
    </div>

    <!-- Decathlon Inspired Reward Slider -->
    <div class="reward-slider-container">
        <div class="reward-slider-header">
            <h2>Reward Catalog Highlights</h2>
            <a href="reward_catalog.php">See All Rewards <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="reward-slider">
            <?php while($reward = mysqli_fetch_assoc($rewards_res)): ?>
                <?php 
                    $price_display = 'Voucher';
                    if (preg_match('/[₱P](\d+)/', $reward['name'], $matches)) {
                        $price_display = '₱' . $matches[1];
                    }
                ?>
                <div class="reward-voucher-card" onclick="location.href='reward_catalog.php?id=<?php echo $reward['id']; ?>'">
                    <div class="voucher-value">Gift Card of <?php echo $price_display; ?></div>
                    <div class="voucher-name"><?php echo htmlspecialchars($reward['name']); ?></div>
                    <div class="voucher-points"><?php echo number_format($reward['points']); ?> Points</div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <div class="status-summary-box">
        <h2>Order Status Summary</h2>
        <div class="status-grid">
            <?php foreach ($status_counts as $status => $count): ?>
                <div class="status-pill-card status-border-<?php echo $status; ?>">
                    <span><?php echo ucwords(str_replace('_', ' ', $status)); ?></span>
                    <strong><?php echo $count; ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
