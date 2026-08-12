<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

if (isset($_POST['mark_notifications_read'])) {
    notifications_mark_all_read($conn, $user_id);
    header("Location: " . BASE_URL . "/modules/customers/reward_redemption.php");
    exit();
}
$notification_count = notifications_get_unread_count($conn, $user_id);
$recent_notifications = notifications_get_recent($conn, $user_id, 6);

// --- Database Schema Checks ---
$loyaltyColumn = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
if ($loyaltyColumn && mysqli_num_rows($loyaltyColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN loyalty_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER address");
}

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS reward_redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        user_id INT NOT NULL,
        reward_code VARCHAR(100) NOT NULL,
        reward_name VARCHAR(255) NOT NULL,
        points_used DECIMAL(10,2) NOT NULL,
        redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_id (customer_id),
        INDEX idx_user_id (user_id)
    )"
);

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reward_code VARCHAR(100) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        points DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        description TEXT
    )"
);

// Seed initial rewards if table is empty
$check_rewards = mysqli_query($conn, "SELECT COUNT(*) as total FROM rewards");
$reward_count = mysqli_fetch_assoc($check_rewards)['total'];
if ($reward_count == 0) {
    mysqli_query($conn, "INSERT INTO rewards (reward_code, name, points, stock, description) VALUES 
        ('free_item_1000', 'Free Item', 1000.00, 5, 'Example reward: redeem 1000 points for one free item.'),
        ('free_catfood_500', 'Free 0.5kg Cat Food', 500.00, 10, 'Half-kilo cat food reward for repeat buyers.'),
        ('free_treat_pack_250', 'Free Treat Pack', 250.00, 20, 'Small thank-you reward for loyal clients.')");
}

// Fetch rewards from database
$reward_catalog = [];
$res_rewards = mysqli_query($conn, "SELECT * FROM rewards ORDER BY points DESC");
while ($r = mysqli_fetch_assoc($res_rewards)) {
    $reward_catalog[$r['reward_code']] = $r;
}

$success_message = '';
$error_message = '';
$selected_customer = (int) ($_POST['customer_id'] ?? 0);
$selected_reward_code = trim($_POST['reward_code'] ?? '');

// --- MAIN LOGIC: REDEEM REWARD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_reward'])) {
    if ($selected_customer <= 0) {
        $error_message = 'Please select a customer first.';
    } elseif ($selected_reward_code === '' || !isset($reward_catalog[$selected_reward_code])) {
        $error_message = 'Please choose a valid reward from the catalog.';
    } else {
        $synced_points = notifications_sync_customer_loyalty_points($conn, $selected_customer);

        // Start Database Transaction para safe ang points
        $conn->begin_transaction();

        try {
            // 1. Lock reward for update to check stock safely
            $reward_stmt = $conn->prepare("SELECT id, name, points, stock FROM rewards WHERE reward_code = ? FOR UPDATE");
            $reward_stmt->bind_param("s", $selected_reward_code);
            $reward_stmt->execute();
            $reward_data = $reward_stmt->get_result()->fetch_assoc();

            if (!$reward_data) {
                throw new Exception("Reward not found.");
            }

            if ($reward_data['stock'] <= 0) {
                throw new Exception("Sorry, " . $reward_data['name'] . " is out of stock.");
            }

            $points_required = (float) $reward_data['points'];
            $reward_name = $reward_data['name'];

            // 1. Kunin ang current points at pangalan ng customer
            $stmt = $conn->prepare("SELECT name, email, COALESCE(loyalty_points, 0) AS loyalty_points FROM customers WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $selected_customer);
            $stmt->execute();
            $customer_data = $stmt->get_result()->fetch_assoc();

            if (!$customer_data) {
                throw new Exception("Customer not found.");
            }

            $current_points = (float) $synced_points;
            $customer_name = $customer_data['name'];
            $customer_email = $customer_data['email'] ?? '';

            if ($current_points < $points_required) {
                throw new Exception("$customer_name does not have enough points (Need: " . number_format($points_required, 2) . ", Has: " . number_format($current_points, 2) . ").");
            }

            // 2. Decrement Stock
            $update_stock = $conn->prepare("UPDATE rewards SET stock = stock - 1 WHERE id = ?");
            $update_stock->bind_param("i", $reward_data['id']);
            $update_stock->execute();

            // 3. I-record sa reward_redemptions table
            $redeem_stmt = $conn->prepare(
                "INSERT INTO reward_redemptions (customer_id, user_id, reward_code, reward_name, points_used) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            $redeem_stmt->bind_param("iissd", $selected_customer, $user_id, $selected_reward_code, $reward_name, $points_required);
            $redeem_stmt->execute();
            $redemption_id = (int) $conn->insert_id;
            $remaining_points = notifications_sync_customer_loyalty_points($conn, $selected_customer);

            notifications_create($conn, [
                'user_id' => $user_id,
                'customer_id' => $selected_customer,
                'type' => 'reward_redeemed',
                'channel' => 'both',
                'title' => 'Reward redeemed successfully',
                'message' => $customer_name . ' redeemed ' . $reward_name . ' using ' . notifications_format_points($points_required) . ' points. Remaining usable balance: ' . notifications_format_points($remaining_points) . ' points. Points expire after ' . notifications_expiry_months() . ' months.',
                'reference_table' => 'reward_redemptions',
                'reference_id' => $redemption_id,
                'points_value' => $points_required,
                'email_to' => $customer_email
            ]);

            // 4. I-log sa activity_logs (Audit Trail)
            $log_details = "$customer_name redeemed $points_required points for $reward_name";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'REWARD_REDEMPTION', ?)");
            $log_stmt->bind_param("is", $user_id, $log_details);
            $log_stmt->execute();

            // Lahat success! I-save na sa database.
            $conn->commit();
            
            $success_message = "$customer_name successfully redeemed $reward_name.";
            $selected_customer = 0;
            $selected_reward_code = '';

        } catch (Exception $e) {
            // May error? I-undo lahat ng pagbabago (Rollback)
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// --- Fetch data for display ---
$customers = [];
$customers_result = mysqli_query($conn, "
    SELECT 
        id, 
        name, 
        email, 
        ((SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = customers.id) - 
         (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = customers.id)) AS loyalty_points 
    FROM customers 
    ORDER BY name ASC");
if ($customers_result) {
    while ($row = mysqli_fetch_assoc($customers_result)) {
        $customers[] = $row;
    }
}

$total_redemptions = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reward_redemptions"))['total'] ?? 0);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

$total_points_redeemed = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(points_used), 0) AS total FROM reward_redemptions"))['total'] ?? 0);

$redemption_history = [];
$history_result = mysqli_query(
    $conn,
    "SELECT rr.reward_name, rr.points_used, rr.redeemed_at, c.name AS customer_name
     FROM reward_redemptions rr
     LEFT JOIN customers c ON c.id = rr.customer_id
     ORDER BY rr.redeemed_at DESC
     LIMIT 15"
);
if ($history_result) {
    while ($row = mysqli_fetch_assoc($history_result)) { $redemption_history[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reward Redemption - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        :root {
            --primary-purple: #4a3e94;
            --border-color: #e2e8f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        body {
            background-color: #f8fafc;
            margin: 0;
            font-family: inherit;
        }

        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 260px !important;
            height: 100vh !important;
            display: flex !important;
            flex-direction: column !important;
            background-color: #1e293b !important;
            z-index: 1000 !important;
            box-sizing: border-box !important;
        }

        .sidebar-brand {
            flex-shrink: 0 !important;
            padding: 20px 15px !important;
            text-align: center !important;
        }

        /* Ginagawang scrollable ang mismong links lang */
        .sidebar .nav-links {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            list-style: none !important;
            padding: 0 10px 20px 10px !important;
            margin: 0 !important;
            max-height: calc(100vh - 180px) !important; /* Piliting mag-scroll bago tumapat sa logout */
        }

        /* Custom Scrollbar */
        .sidebar .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Naka-pin sa pinakababa ang Logout */
        .sidebar-footer {
            flex-shrink: 0 !important;
            padding: 16px 20px !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
            background-color: #1e293b !important;
            margin-top: auto !important;
        }

        .sidebar-footer .logout-link {
            position: static !important; /* Tinatanggal ang absolute positioning */
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            color: #ef4444 !important;
            font-weight: 600 !important;
            text-decoration: none !important;
        }

        /* Main Content Layout */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .welcome-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .welcome-header h1 {
            margin: 0;
            font-size: 1.6rem;
            color: var(--text-dark);
        }

        /* Notification Panel */
        .notification-wrapper {
            position: relative;
        }

        .notification-bell {
            background: #ffffff;
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            position: relative;
            font-size: 1.1rem;
            color: var(--text-dark);
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 999px;
        }

        .notification-dropdown {
            position: absolute;
            right: 0;
            top: 45px;
            width: 320px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            z-index: 1050;
            display: none;
            overflow: hidden;
        }

        .notification-dropdown.active {
            display: block;
        }

        .notif-header {
            padding: 12px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 2px;
        }

        .notif-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Stats & Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 8px;
        }

        .stat-card h3 {
            margin: 0;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        /* Form Box & Table Box */
        .content-box {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }

        .content-box h2 {
            margin-top: 0;
            font-size: 1.15rem;
            color: var(--text-dark);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group select, .form-group input {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            outline: none;
        }

        .btn-primary {
            background: var(--primary-purple);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-print {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Table */
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .styled-table th, .styled-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .styled-table th {
            background: #f8fafc;
            color: var(--text-dark);
            font-weight: 700;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Print Media Styles */
        @media print {
            .sidebar, button, .notification-wrapper, .reward-claiming-guides,
            #redemption-form-box, .welcome-header div:last-child {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .content-box {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            .print-report-header {
                display: block !important;
                text-align: center;
                margin-bottom: 25px;
                border-bottom: 2px solid #4a3e94;
                padding-bottom: 12px;
            }
            .print-report-header h2 { margin: 0; color: #4a3e94; font-size: 22px; }
            .print-report-header p { margin: 4px 0 0; color: #666; font-size: 13px; }
        }
        .print-report-header { display: none; }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>            
            <?php if ($role === 'admin'): ?>
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">

        <!-- Printable Header -->
        <div class="print-report-header">
            <h2><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
            <p>Reward Redemption Audit & History Report | Generated: <?php echo date('F d, Y h:i A'); ?></p>
        </div>

        <!-- Header Bar -->
        <div class="welcome-header">
            <div>
                <h1><i class="fas fa-gift" style="color: var(--primary-purple);"></i> Reward Redemption</h1>
                <p style="margin: 4px 0 0 0; color: var(--text-muted); font-size: 0.9rem;">Process customer reward redemptions using their accumulated loyalty points.</p>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="button" onclick="window.print()" class="btn-print">
                    <i class="fas fa-print"></i> Print Report
                </button>

                <!-- Notifications Dropdown -->
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span>Notifications</span>
                            <?php if ($notification_count > 0): ?>
                                <form method="POST" style="margin: 0;">
                                    <button type="submit" name="mark_notifications_read" style="background: none; border: none; color: var(--primary-purple); font-size: 0.8rem; cursor: pointer; font-weight: 600;">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($recent_notifications)): ?>
                            <div class="notif-item" style="text-align: center; color: var(--text-muted);">No notifications</div>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $notif): ?>
                                <div class="notif-item">
                                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div style="color: #475569;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notif-time"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Alerts -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-gift" style="color: #8b5cf6;"></i>
                <h3>Total Redemptions</h3>
                <p><?php echo number_format($total_redemptions); ?></p>
            </div>
            <div class="stat-card" style="border-bottom: 4px solid #f59e0b;">
                <i class="fas fa-star" style="color: #f59e0b;"></i>
                <h3>Total Points Redeemed</h3>
                <p><?php echo number_format($total_points_redeemed, 2); ?></p>
            </div>
        </div>

        <!-- Process Redemption Form -->
        <div class="content-box" id="redemption-form-box">
            <h2><i class="fas fa-hand-holding-heart" style="color: #10b981;"></i> Process Reward Claim</h2>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="customer_id">Select Customer</label>
                        <select name="customer_id" id="customer_id" required onchange="updateCustomerPointsDisplay()">
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>" 
                                        data-points="<?php echo (float)$cust['loyalty_points']; ?>"
                                        <?php echo ($selected_customer === (int)$cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['name']); ?> (Bal: <?php echo number_format($cust['loyalty_points'], 2); ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reward_code">Select Reward Item</label>
                        <select name="reward_code" id="reward_code" required>
                            <option value="">-- Choose Reward Item --</option>
                            <?php foreach ($reward_catalog as $code => $item): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"
                                        <?php echo ($selected_reward_code === $code) ? 'selected' : ''; ?>
                                        <?php echo ($item['stock'] <= 0) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($item['name']); ?> 
                                    (Cost: <?php echo number_format($item['points'], 2); ?> pts | Stock: <?php echo $item['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 10px;">
                    <div id="selected-points-info" style="font-size: 0.9rem; font-weight: 600; color: var(--primary-purple);">
                        Available Customer Balance: <span id="display-points">0.00</span> points
                    </div>
                    <button type="submit" name="redeem_reward" class="btn-primary">
                        <i class="fas fa-check-circle"></i> Confirm Redemption
                    </button>
                </div>
            </form>
        </div>

        <!-- Redemption History Table -->
        <div class="content-box">
            <h2><i class="fas fa-history" style="color: var(--primary-purple);"></i> Recent Redemption Transactions</h2>
            
            <?php if (empty($redemption_history)): ?>
                <p style="text-align: center; color: var(--text-muted); margin: 30px 0;">No redemption history found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Reward Claimed</th>
                                <th>Points Used</th>
                                <th>Redeemed Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($redemption_history as $history): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($history['customer_name'] ?? 'Unknown'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($history['reward_name']); ?></td>
                                    <td><span style="color: #e11d48; font-weight: 700;">-<?php echo number_format($history['points_used'], 2); ?> pts</span></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($history['redeemed_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown) {
                dropdown.classList.toggle('active');
            }
        }

        function updateCustomerPointsDisplay() {
            const select = document.getElementById('customer_id');
            const display = document.getElementById('display-points');
            if (!select || !display) return;

            const selectedOption = select.options[select.selectedIndex];
            const points = selectedOption ? selectedOption.getAttribute('data-points') : 0;
            display.textContent = points ? parseFloat(points).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
        }

        // Run on page load if a customer was pre-selected
        document.addEventListener('DOMContentLoaded', () => {
            updateCustomerPointsDisplay();

            // Close notification dropdown when clicking outside
            document.addEventListener('click', (e) => {
                const wrapper = document.querySelector('.notification-wrapper');
                const dropdown = document.getElementById('notifDropdown');
                if (wrapper && !wrapper.contains(e.target) && dropdown) {
                    dropdown.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>