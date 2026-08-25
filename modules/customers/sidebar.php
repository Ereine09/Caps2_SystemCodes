<?php
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
if (!isset($pending_remittances_count)) {
    $pending_remittances_count = get_pending_remittance_count($conn);
}
// This file is included in various admin pages.
// Ensure necessary variables are defined in the parent script before including this file.
// Expected variables: $user_role, $pending_orders_count, $unread_count, $current_page (optional, for active state)
// Constants: SYSTEM_LOGO_URL, SYSTEM_NAME, BASE_URL

// Determine current page for active state if not already set

$user_role = strtolower(trim($payload['role'] ?? 'staff'));

// --- SECURITY GATE: ADMIN ONLY ---
if ($user_role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');
?>
<div class="sidebar">
    <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
        <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
        <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
    </div>
    <ul class="nav-links">
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>            
        <?php if ($user_role === 'admin'): ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" class="<?php echo $current_page === 'staff_management' ? 'active' : ''; ?>"><i class="fas fa-users-cog"></i> Staff Management</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" class="<?php echo $current_page === 'activity_logs' ? 'active' : ''; ?>"><i class="fas fa-history"></i> Activity Logs</a></li>
        <?php endif; ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" class="<?php echo $current_page === 'manage_rewards' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> Manage Rewards</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" class="<?php echo $current_page === 'products' ? 'active' : ''; ?>"><i class="fas fa-store"></i> Products</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" class="<?php echo $current_page === 'orders' ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i> Orders
            <?php if (isset($pending_orders_count) && $pending_orders_count > 0): ?>
                <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php" class="<?php echo $current_page === 'delivery' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" class="<?php echo $current_page === 'about' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About Us</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" class="<?php echo $current_page === 'messages' ? 'active' : ''; ?>">
            <i class="fas fa-comment-dots"></i> Messages
            <?php if (isset($unread_count) && $unread_count > 0): ?>
                <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
            <?php endif; ?>
        </a></li>            
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php" class="<?php echo $current_page === 'reviews' ? 'active' : ''; ?>"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" class="<?php echo $current_page === 'remittance_management' ? 'active' : ''; ?>">
            <i class="fas fa-money-bill-wave"></i> Remittance
            <?php if ($pending_remittances_count > 0): ?>
                <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?php echo $pending_remittances_count; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo ($current_page == 'customers' || $current_page == 'customer_details') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo $current_page === 'loyalty_points' ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo $current_page === 'reward_redemption' ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
        <?php if ($user_role === 'admin'): ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" class="<?php echo $current_page === 'analytics' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
        <?php endif; ?>
    </ul>
    <div class="sidebar-footer">
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>