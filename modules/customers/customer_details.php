<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = (int)$payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'staff'));
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0) {
    die("Invalid Customer ID.");
}

// Sync points and fetch customer basic info
$current_points = notifications_sync_customer_loyalty_points($conn, $customer_id);
$customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$customer_query->bind_param("i", $customer_id);
$customer_query->execute();
$customer = $customer_query->get_result()->fetch_assoc();

if (!$customer) {
    die("Customer not found.");
}

// Fetch Gross Points (All-Time Earned) and Total Redeemed
$gross_res = mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE customer_id = $customer_id");
$gross_points = (float)(mysqli_fetch_assoc($gross_res)['total'] ?? 0);
$redeemed_res = mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE customer_id = $customer_id");
$total_redeemed_pts = (float)(mysqli_fetch_assoc($redeemed_res)['total'] ?? 0);

// Tier Logic based on notification_helper thresholds
function getTierLabel($points) {
    if ($points >= 1000) return ['label' => 'Platinum', 'color' => '#55efc4', 'icon' => 'fa-crown'];
    if ($points >= 500) return ['label' => 'Gold', 'color' => '#ffeaa7', 'icon' => 'fa-medal'];
    if ($points >= 250) return ['label' => 'Silver', 'color' => '#dfe6e9', 'icon' => 'fa-award'];
    return ['label' => 'Bronze', 'color' => '#fab1a0', 'icon' => 'fa-star'];
}
$tier = getTierLabel($current_points);

// Fetch Transaction History
$trans_stmt = $conn->prepare("SELECT * FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC");
$trans_stmt->bind_param("i", $customer_id);
$trans_stmt->execute();
$transactions = $trans_stmt->get_result();

// Fetch Redemption History
$redeem_stmt = $conn->prepare("SELECT * FROM reward_redemptions WHERE customer_id = ? ORDER BY redeemed_at DESC");
$redeem_stmt->bind_param("i", $customer_id);
$redeem_stmt->execute();
$redemptions = $redeem_stmt->get_result();

// Fetch Activity Timeline (Union of earned and redeemed)
$timeline_query = "
    (SELECT 'earned' as type, product_name as detail, points_earned as val, created_at as ts FROM loyalty_transactions WHERE customer_id = ?)
    UNION ALL
    (SELECT 'redeemed' as type, reward_name as detail, points_used as val, redeemed_at as ts FROM reward_redemptions WHERE customer_id = ?)
    ORDER BY ts DESC LIMIT 15";
$timeline_stmt = $conn->prepare($timeline_query);
$timeline_stmt->bind_param("ii", $customer_id, $customer_id);
$timeline_stmt->execute();
$timeline = $timeline_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Profile - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .profile-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .timeline-item { padding: 10px; border-left: 3px solid #4a3e94; margin-bottom: 10px; background: #f9f9ff; margin-left: 10px; }
        .tier-badge { padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; display: inline-block; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li>
                <a href="<?php echo BASE_URL; ?>/modules/<?php echo ($role === 'admin' ? 'admin' : 'staff'); ?>/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>>
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>            
            
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'manage_rewards.php') ? 'active' : ''; ?>">
                    <i class="fas fa-boxes"></i> Manage Rewards
                </a>
            </li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
<li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li>
                <a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" 
                   class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>">
                    <i class="fas fa-user-friends"></i> Customers
                </a>
            </li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="main-content">
        <div class="welcome-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>Customer Profile: <?php echo htmlspecialchars($customer['name']); ?></h1>
                <p>Email: <?php echo htmlspecialchars($customer['email']); ?> | Joined: <?php echo date('M d, Y', strtotime($customer['created_at'] ?? 'now')); ?></p>
            </div>
            <a href="customers.php" class="btn-add" style="background: #bdc3c7; color: #2c3e50;"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>

        <div class="profile-grid">
            <!-- Left Column: Stats & Timeline -->
            <div class="left-col">
                <div class="stat-card" style="text-align: center;">
                    <i class="fas <?php echo $tier['icon']; ?>" style="font-size: 3rem; color: #f1c40f; margin-bottom: 10px;"></i>
                    <h3>Current Status</h3>
                    <div class="tier-badge" style="background: <?php echo $tier['color']; ?>; color: #2c3e50;">
                        <?php echo $tier['label']; ?> Tier
                    </div>
                    <h1 style="font-size: 2.5rem; margin-top: 15px;"><?php echo number_format($current_points, 2); ?></h1>
                    <p style="color: #7f8c8d;">Total Usable Points</p>
                </div>

                <div class="stat-card" style="display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Gross Earned:</span>
                        <span style="color: #27ae60; font-weight: bold;">+<?php echo number_format($gross_points, 2); ?> pts</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Total Redeemed:</span>
                        <span style="color: #e74c3c; font-weight: bold;">-<?php echo number_format($total_redeemed_pts, 2); ?> pts</span>
                    </div>
                    <div style="font-size: 0.75rem; color: #999; margin-top: 5px; font-style: italic;">
                        * Usable balance accounts for redeemed and expired points.
                    </div>
                </div>

                <div class="stat-card">
                    <h3><i class="fas fa-stream"></i> Activity Timeline</h3>
                    <div style="margin-top: 15px;">
                        <?php while($item = $timeline->fetch_assoc()): ?>
                            <div class="timeline-item">
                                <small style="color: #95a5a6;"><?php echo date('M d, H:i', strtotime($item['ts'])); ?></small><br>
                                <strong><?php echo $item['type'] === 'earned' ? '<span style="color:#27ae60;">+ Earned</span>' : '<span style="color:#e74c3c;">- Redeemed</span>'; ?></strong>
                                <span style="font-size: 0.9rem;"><?php echo htmlspecialchars($item['detail']); ?></span>
                                <div style="float: right; font-weight: bold;"><?php echo number_format($item['val'], 2); ?></div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Tables -->
            <div class="right-col">
                <!-- Transaction History -->
                <div class="table-box" style="margin-bottom: 20px;">
                    <h2><i class="fas fa-receipt"></i> Transaction History</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 10px;">Product</th>
                                <th style="padding: 10px;">Weight (kg)</th>
                                <th style="padding: 10px;">Points</th>
                                <th style="padding: 10px;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while($row = $transactions->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td style="padding: 10px;"><?php echo number_format($row['quantity_kg'], 2); ?></td>
                                        <td style="padding: 10px; font-weight: bold; color: #27ae60;">+<?php echo number_format($row['points_earned'], 2); ?></td>
                                        <td style="padding: 10px; font-size: 0.85rem;"><?php echo $row['created_at']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="padding: 20px; text-align: center;">No transactions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Redemption History -->
                <div class="table-box">
                    <h2><i class="fas fa-gift"></i> Redemption History</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 10px;">Reward</th>
                                <th style="padding: 10px;">Points Used</th>
                                <th style="padding: 10px;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($redemptions->num_rows > 0): ?>
                                <?php while($row = $redemptions->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($row['reward_name']); ?></td>
                                        <td style="padding: 10px; font-weight: bold; color: #e74c3c;">-<?php echo number_format($row['points_used'], 2); ?></td>
                                        <td style="padding: 10px; font-size: 0.85rem;"><?php echo $row['redeemed_at']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="padding: 20px; text-align: center;">No redemptions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
