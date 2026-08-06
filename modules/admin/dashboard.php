<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
// Force logout if they are trying to use a customer session in the staff area
enforcePortalGuard('staff');

$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_role = strtolower(trim($payload['role'] ?? 'staff'));

// --- SECURITY GATE: ADMIN ONLY ---
if ($user_role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

$total_staff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users"))['total'];
$total_customers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"))['total'];
$total_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_orders"))['total'];
$total_sales = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total_sales FROM tbl_orders"))['total_sales'] ?? 0);
$unremitted_cod = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total FROM tbl_orders WHERE payment_method = 'cod' AND payment_settled = 0"))['total'] ?? 0);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$total_loyalty_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(loyalty_points) AS total FROM customers"))['total'] ?? 0);
$total_gross_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) AS total FROM loyalty_transactions"))['total'] ?? 0);
$username = $payload['username'];
$user_id = $payload['user_id'];
$unread_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'class="active"' : ''; ?>><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content" style="margin-left: 260px;">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Welcome, <?php echo htmlspecialchars($username); ?>! 👋</h1>
            <p>Role: <span style="color: #4a3e94; font-weight: bold; text-transform: uppercase;">
                <?php echo $user_role; ?>
            </span></p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-users" style="font-size: 2rem; color: #4a3e94;"></i>
                <h3>Total Staff</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_staff; ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-user-tag" style="font-size: 2rem; color: #4a3e94;"></i>
                <h3>Total Customers</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_customers; ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-shopping-cart" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Total Orders</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_orders; ?></p>
            </div>
<div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #27ae60;">
                <i class="fas fa-dollar-sign" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Total Sales</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($total_sales, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #e74c3c;">
                <i class="fas fa-hand-holding-usd" style="font-size: 2rem; color: #e74c3c;"></i>
                <h3>Unremitted COD</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($unremitted_cod, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #f1c40f;">
                <i class="fas fa-star" style="font-size: 2rem; color: #f1c40f;"></i>
                <h3>Usable Points</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_loyalty_points, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #2ecc71;">
                <i class="fas fa-coins" style="font-size: 2rem; color: #2ecc71;"></i>
                <h3>Gross Points Issued</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_gross_points, 2); ?></p>
            </div>
        </div>

        <div class="welcome-header">
            <h2 style="color: #4a3e94; margin-bottom: 20px;">Quick Shortcuts 🚀</h2>
        </div>

        <div class="shortcuts-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px;">
            
            <a href="staff_management.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-users-cog" style="font-size: 2.5rem; color: #3498db; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Manage Staff</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Register or update employees.</p>
                </div>
            </a>

            <a href="activity_logs.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-history" style="font-size: 2.5rem; color: #f39c12; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Audit Logs</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Track all system changes.</p>
                </div>
            </a>
            
            <a href="manage_rewards.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-boxes" style="font-size: 2.5rem; color: #e74c3c; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Reward Inventory</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Manage stock and point costs.</p>
                </div>
            </a>

            <a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-user-friends" style="font-size: 2.5rem; color: #2ecc71; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Customer Database</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Full list and profiles of customers.</p>
                </div>
            </a>

            <a href="messages.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-comments" style="font-size: 2.5rem; color: #667eea; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Messages</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Chat with customers.</p>
                </div>
            </a>

            <a href="orders.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-shopping-cart" style="font-size: 2.5rem; color: #27ae60; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Orders</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Review customer order history.</p>
                </div>
            </a>

            <a href="test_smtp.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-envelope-circle-check" style="font-size: 2.5rem; color: #27ae60; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">SMTP Test</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Verify email configuration.</p>
                </div>
            </a>

            <a href="settings.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-sliders" style="font-size: 2.5rem; color: #4a3e94; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Global Settings</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Update API keys and SMTP.</p>
                </div>
            </a>

            <a href="security_alert_sim.php" style="text-decoration: none; color: inherit;">
                <div class="card" style="background: white; padding: 25px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;">
                    <i class="fas fa-mask" style="font-size: 2.5rem; color: #e74c3c; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0;">Security Sim</h4>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Test user security awareness.</p>
                </div>
            </a>
        </div>

        <!-- AI Engine Logic Card -->
        <div class="card" style="background: linear-gradient(135deg, #4a3e94, #6c5ce7); color: white; padding: 30px; border-radius: 15px; margin-bottom: 40px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div style="flex: 1; min-width: 250px;">
                    <h2 style="margin: 0;"><i class="fas fa-brain color: #2200ffff;"></i> AI Engine Processing</h2>
                    <p style="margin-top: 10px; opacity: 0.9;">The system automatically performs <strong>Heuristic Clustering</strong> and <strong>Predictive Classification</strong> using local datasets, then synthesizes business strategies via <strong>Google Gemini AI</strong>.</p>
                </div>
                <div style="text-align: right;">
                    <a href="analytics.php" style="background: white; color: #4a3e94; padding: 12px 25px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; white-space: nowrap;">
                        View AI Analytics
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>