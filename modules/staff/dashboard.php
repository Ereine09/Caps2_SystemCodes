<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_role = strtolower(trim($payload['role'] ?? 'staff'));
$username = $payload['username'];

// Fix: Updated table name and added Total Loyalty Points calculation to match Admin logic
$total_customers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"))['total'];
$total_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_orders"))['total'];
$total_loyalty_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(loyalty_points), 0) AS total FROM customers"))['total'] ?? 0);

// Fetch pending orders count for the sidebar badge
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css"> 
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/settings.php" <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'class="active"' : ''; ?>><i class="fas fa-cogs"></i> System Settings</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars($username); ?>! 👋</h1>
            <p>Role: <span style="color: #4a3e94; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($user_role); ?></span></p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-top: 30px;">
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-user-friends" style="font-size: 2.5rem; color: #4a3e94; margin-bottom: 15px;"></i>
                <h3>Total Customers</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo $total_customers; ?></p>
                <a href="../customers/customers.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">Manage Customers →</a>
            </div>
            
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-shopping-cart" style="font-size: 2.5rem; color: #27ae60; margin-bottom: 15px;"></i>
                <h3>Total Orders</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo $total_orders; ?></p>
                <a href="orders.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">View Orders →</a>
            </div>
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-star" style="font-size: 2.5rem; color: #f1c40f; margin-bottom: 15px;"></i>
                <h3>Total Loyalty Points</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo number_format($total_loyalty_points, 2); ?></p>
                <a href="../customers/loyalty_points.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">Quick Actions →</a>
            </div>
        </div>
    </div>
</body>
</html>