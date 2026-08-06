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
                throw new Exception("$customer_name does not have enough points (Need: $points_required, Has: $current_points).");
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
    <title>Reward Redemption - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        @media print {
            /* Hide non-report UI elements */
            .sidebar, .logout-link, button, .nav-links, .reward-claiming-guides, 
            #notification-panel, .welcome-header div:last-child, #redemption-form-box {
                display: none !important;
            }
            /* Expand content to full page width */
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .table-box {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            /* Show the professional header only when printing */
            .print-report-header {
                display: block !important;
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #4a3e94;
                padding-bottom: 15px;
            }
            .print-report-header h2 { margin: 0; color: #4a3e94; font-size: 24px; }
            .print-report-header p { margin: 5px 0 0; color: #666; font-size: 14px; }
        }
        .print-report-header { display: none; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
        <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
        <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
    </div>
    <ul class="nav-links" style="list-style: none; padding: 0;">
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
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>        <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
        <?php if ($role === 'admin'): ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
        <?php endif; ?>
    </ul>
    <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

    <div class="main-content">
        <div class="print-report-header">
            <h2>DPS Loyalty Management System</h2>
            <h3>Customer Reward Redemption Report</h3>
            <p>Generated by: <?php echo htmlspecialchars($username); ?> | Date: <?php echo date('F j, Y, g:i a'); ?></p>
        </div>

            <div class="welcome-header" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <h1>Reward Redemption 🎁</h1>
                    <p>Process customer rewards and track point deductions securely. Usable points expire after <?php echo notifications_expiry_months(); ?> months.</p>
                </div>
                <div style="display: flex; gap: 12px; align-items: center; position: relative;">
                    <button type="button" onclick="window.print()" style="background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button type="button" onclick="toggleNotifications()" style="background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; color: #4a3e94;">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if ($notification_count > 0): ?>
                            <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; margin-left: 8px; background: #e74c3c; color: #fff; border-radius: 999px; font-size: 0.75rem; padding: 0 6px;"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notification-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 10px); width: 360px; max-width: 90vw; background: #fff; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.12); border: 1px solid #eee; z-index: 1000;">
                        <div style="padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                            <strong style="color: #4a3e94;">Recent Notifications</strong>
                            <form method="POST" style="margin: 0;">
                                <button type="submit" name="mark_notifications_read" style="background: none; border: none; color: #4a3e94; font-weight: 600; cursor: pointer;">Mark all as read</button>
                            </form>
                        </div>
                        <div style="max-height: 320px; overflow-y: auto;">
                            <?php if (!empty($recent_notifications)): ?>
                                <?php foreach ($recent_notifications as $notification): ?>
                                    <div style="padding: 14px 15px; border-bottom: 1px solid #f3f3f3; background: <?php echo (int) $notification['is_read'] === 1 ? '#fff' : '#f7f4ff'; ?>;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; gap: 10px;">
                                            <strong style="color: #2c3e50;"><?php echo htmlspecialchars($notification['title']); ?></strong>
                                            <?php if ((int) $notification['is_read'] === 0): ?>
                                                <span style="width: 10px; height: 10px; background: #4a3e94; border-radius: 999px; margin-top: 5px;"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="margin-top: 6px; color: #555; line-height: 1.4; font-size: 0.92rem;"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div style="margin-top: 8px; color: #888; font-size: 0.8rem;"><?php echo htmlspecialchars($notification['created_at']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding: 18px 15px; color: #777;">No notifications yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-gift" style="font-size: 2rem; color: #db1c1c;"></i>
                <h3>Total Redemptions</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_redemptions; ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-star" style="font-size: 2rem; color: #f1c40f;"></i>
                <h3>Total Points Redeemed</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_points_redeemed, 2); ?></p>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px;">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div id="redemption-form-box" class="table-box" style="margin-bottom: 25px; border-left: 5px solid #f1c40f; background: white; padding: 25px; border-radius: 12px;">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-hand-holding-heart"></i> Process Redemption</h2>
            
            <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; align-items: start;">
                
                <div style="grid-column: span 2; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <select id="customer_id" name="customer_id" required style="padding: 12px; border-radius: 8px; border: 1px solid #ddd; width: 100%;">
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" data-points="<?php echo $customer['loyalty_points']; ?>" <?php echo $selected_customer == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?> (<?php echo htmlspecialchars(number_format($customer['loyalty_points'], 2)); ?> pts)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="reward_code" name="reward_code" required style="padding: 12px; border-radius: 8px; border: 1px solid #ddd; width: 100%;">
                        <option value="">Select Reward</option>
                        <?php foreach ($reward_catalog as $code => $reward): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" 
                                    data-required="<?php echo htmlspecialchars($reward['points']); ?>" 
                                    data-stock="<?php echo $reward['stock']; ?>"
                                    <?php echo ($selected_reward_code === $code) ? 'selected' : ''; ?>
                                    <?php echo ($reward['stock'] <= 0) ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($reward['name']); ?> 
                                (<?php echo htmlspecialchars(number_format($reward['points'], 2)); ?> pts)
                                — <?php echo ($reward['stock'] > 0) ? $reward['stock'] . ' left' : 'OUT OF STOCK'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div style="grid-column: span 2; margin-top: 10px;">
                        <button type="submit" name="redeem_reward" id="redeem-button" style="background: #0082c3; color: white; border: none; padding: 12px 30px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 1rem;">
                            Redeem Now
                        </button>
                        <span id="redemption-preview" style="margin-left: 15px; font-weight: 600; color: #666;"></span>
                    </div>
                </div>

            </form>
        </div>

        <div class="table-box" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <h2 style="margin-bottom: 20px;">Recent Redemptions</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #eee;">
                        <th style="padding: 12px;">Customer</th>
                        <th style="padding: 12px;">Reward</th>
                        <th style="padding: 12px;">Points</th>
                        <th style="padding: 12px;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redemption_history as $entry): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;"><?php echo htmlspecialchars($entry['customer_name']); ?></td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($entry['reward_name']); ?></td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars(number_format($entry['points_used'], 2)); ?></td>
                            <td style="padding: 12px; color: #666; font-size: 0.9rem;"><?php echo $entry['redeemed_at']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const customerSelect = document.getElementById('customer_id');
        const rewardSelect = document.getElementById('reward_code');
        const preview = document.getElementById('redemption-preview');
        const redeemButton = document.getElementById('redeem-button');

        function updatePreview() {
            const customerOpt = customerSelect.options[customerSelect.selectedIndex];
            const rewardOpt = rewardSelect.options[rewardSelect.selectedIndex];
            
            if (customerOpt.value && rewardOpt.value) {
                const has = parseFloat(customerOpt.dataset.points);
                const needs = parseFloat(rewardOpt.dataset.required);
                const stock = parseInt(rewardOpt.dataset.stock);
                const diff = has - needs;
                
                if (stock <= 0) {
                    preview.style.color = "#e74c3c";
                    preview.textContent = "Cannot redeem: Item is out of stock!"; //
                    redeemButton.disabled = true;
                } else if (diff < 0) {
                    preview.style.color = "#e74c3c";
                    preview.textContent = `Short of ${Math.abs(diff).toFixed(2)} points!`; //
                    redeemButton.disabled = true;
                } else {
                    preview.style.color = "#27ae60";
                    preview.textContent = `Remaining after: ${diff.toFixed(2)} points`; //
                    redeemButton.disabled = false;
                }
            } else {
                preview.textContent = "";
            }
        }

        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        }

        document.addEventListener('click', function (event) {
            const panel = document.getElementById('notification-panel');
            if (!panel) {
                return;
            }

            if (!panel.contains(event.target) && !event.target.closest('button[onclick="toggleNotifications()"]')) {
                panel.style.display = 'none';
            }
        });

        customerSelect.addEventListener('change', updatePreview);
        rewardSelect.addEventListener('change', updatePreview);
        
        // Add confirmation dialog
        redeemButton.addEventListener('click', function(event) {
            const customerName = customerSelect.options[customerSelect.selectedIndex].text.split('(')[0].trim();
            const rewardName = rewardSelect.options[rewardSelect.selectedIndex].text.split('(')[0].trim();
            
            if (customerSelect.value === "" || rewardSelect.value === "") {
                alert("Please select both a customer and a reward.");
                event.preventDefault();
                return;
            }
            if (!confirm(`Are you sure you want to redeem "${rewardName}" for "${customerName}"? This action cannot be undone.`)) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>
