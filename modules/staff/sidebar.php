<?php
// Prevent redundant DB queries if already calculated in parent file
if (!isset($pending_orders_count)) {
    $pending_count_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_orders WHERE order_status = 'pending'");
    $pending_orders_count = $pending_count_res ? mysqli_fetch_assoc($pending_count_res)['total'] : 0;
}
if (!isset($unread_messages_count)) {
    $unread_msg_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_messages WHERE is_read = 0 AND sender_type = 'customer'");
    $unread_messages_count = $unread_msg_res ? mysqli_fetch_assoc($unread_msg_res)['total'] : 0;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
        <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
        <h2 style="color: white; font-size: 1.1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        <p style="font-size: 0.8rem; color: rgba(255,255,255,0.7); margin-top: 5px;">Staff Portal</p>
    </div>
    <ul class="nav-links">
        <li><a href="<?php echo BASE_URL; ?>/modules/staff/dashboard.php" <?php echo $current_page === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/staff/orders.php" <?php echo $current_page === 'orders.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-shopping-cart"></i> Orders
            <?php if ($pending_orders_count > 0): ?>
                <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo $current_page === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo $current_page === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo $current_page === 'messages.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-comment-dots"></i> Messages
            <?php if ($unread_messages_count > 0): ?>
                <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?php echo $unread_messages_count; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" <?php echo in_array($current_page, ['customers.php', 'customer_details.php']) ? 'class="active"' : ''; ?>><i class="fas fa-user-friends"></i> Customers</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" <?php echo $current_page === 'loyalty_points.php' ? 'class="active"' : ''; ?>><i class="fas fa-star"></i> Loyalty Points</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" <?php echo $current_page === 'reward_redemption.php' ? 'class="active"' : ''; ?>><i class="fas fa-gift"></i> Reward Redemption</a></li>
        <?php if ($user_role === 'admin'): ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo $current_page === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo $current_page === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo $current_page === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/settings.php" <?php echo $current_page === 'settings.php' ? 'class="active"' : ''; ?>><i class="fas fa-cogs"></i> System Settings</a></li>
        <?php endif; ?>
    </ul>
    <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>