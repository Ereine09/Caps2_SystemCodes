<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

if ($role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

// Handle Date Range Filter
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch Analytics Stats
$total_earned = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
$total_redeemed = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);

// Active customers specifically in this filtered period
$active_customers_period = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) as total FROM (SELECT customer_id FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' UNION SELECT customer_id FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to') as active_pool"))['total'] ?? 0);

$net_points_circulation = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(loyalty_points) as total FROM customers"))['total'] ?? 0);
$total_sales_from_loyalty_transactions = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(points_earned * 100), 0) AS total_sales_from_loyalty FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total_sales_from_loyalty'] ?? 0);

// Fetch Total Sales and Order Count from Orders
$total_sales = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total_sales FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total_sales'] ?? 0);
$total_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);

// Prepare data for ML and AI
$all_points = [];
$pts_res = mysqli_query($conn, "SELECT loyalty_points FROM customers");
while ($p_row = mysqli_fetch_assoc($pts_res)) { $all_points[] = (float)$p_row['loyalty_points']; }

// AI Business Insight
$stats_summary = [
    'total_earned' => $total_earned,
    'total_redeemed' => $total_redeemed,
    'active_customers' => $active_customers_period,
    'net_points_in_circulation' => $net_points_circulation
];
$ai_insight = getGeminiBusinessInsight($stats_summary);

// Fetch Top Customers with ML Segmentation
$top_customers = [];
$customers_query = "
    SELECT c.id, c.name, c.email, c.loyalty_points, 
           COUNT(lt.id) as trans_count, 
           MAX(lt.created_at) as last_activity
    FROM customers c
    LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
    GROUP BY c.id
    ORDER BY c.loyalty_points DESC
    LIMIT 10";
$res_top = mysqli_query($conn, $customers_query);
while ($row = mysqli_fetch_assoc($res_top)) {
    $ml = getMLCustomerClassification($row['loyalty_points'], $row['trans_count'], $row['last_activity'], $all_points);
    $row['ml_label'] = $ml['label'];
    $row['ml_class'] = $ml['class'];
    $top_customers[] = $row;
}

// Fetch Top Performing Staff Ranking
$top_staff = [];
$staff_query = "
    SELECT u.username, u.first_name, u.last_name, 
           COUNT(DISTINCT lt.id) as transactions_count, 
           SUM(lt.points_earned) as total_points_awarded,
           COUNT(DISTINCT rr.id) as redemptions_processed
    FROM users u
    LEFT JOIN loyalty_transactions lt ON u.id = lt.user_id 
        AND DATE(lt.created_at) BETWEEN '$date_from' AND '$date_to'
    LEFT JOIN reward_redemptions rr ON u.id = rr.user_id 
        AND DATE(rr.redeemed_at) BETWEEN '$date_from' AND '$date_to'
    WHERE u.role = 'staff'
    GROUP BY u.id
    ORDER BY total_points_awarded DESC LIMIT 5";
$res_staff = mysqli_query($conn, $staff_query);
while ($s_row = mysqli_fetch_assoc($res_staff)) { $top_staff[] = $s_row; }

// Fetch Daily Activity for Trend Chart (Fixed 14-day lookback for visual context)
$daily_labels = [];
$daily_earned = [];
$daily_redeemed = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $daily_labels[] = date('M d', strtotime($date));
    $e = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE DATE(created_at) = '$date'"))['total'] ?? 0);
    $r = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE DATE(redeemed_at) = '$date'"))['total'] ?? 0);
    $daily_earned[] = $e;
    $daily_redeemed[] = $r;
}

// Fetch Daily Summary Table (Respects the User's Date Filter)
$daily_summary_data = [];
$summary_query = "
    SELECT activity_date, SUM(earned) as earned, SUM(redeemed) as redeemed 
    FROM (
        SELECT DATE(created_at) as activity_date, points_earned as earned, 0 as redeemed FROM loyalty_transactions
        UNION ALL
        SELECT DATE(redeemed_at) as activity_date, 0 as earned, points_used as redeemed FROM reward_redemptions
    ) as combined WHERE activity_date BETWEEN '$date_from' AND '$date_to'
    GROUP BY activity_date ORDER BY activity_date DESC";
$summary_res = mysqli_query($conn, $summary_query);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

while ($s_row = mysqli_fetch_assoc($summary_res)) { $daily_summary_data[] = $s_row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Analytics & Reports - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @media print {
            .sidebar, .logout-link, button, .nav-links, .ai-insight-box, .welcome-header div:last-child, .filter-box {
                display: none !important;
            }
            .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
            .table-box { box-shadow: none !important; border: 1px solid #ddd !important; page-break-inside: avoid; }
            .print-report-header { display: block !important; text-align: center; margin-bottom: 30px; border-bottom: 2px solid #4a3e94; padding-bottom: 15px; }
            .print-report-header h2 { margin: 0; color: #4a3e94; font-size: 24px; }
            .print-report-header p { margin: 5px 0 0; color: #666; font-size: 14px; }
            .stats-container { gap: 10px !important; }
            .card { border: 1px solid #eee !important; }
        }
        .print-report-header { display: none; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; color: white; }
        .badge-success { background: #27ae60; }
        .badge-primary { background: #3498db; }
        .badge-danger { background: #e74c3c; }
        .badge-secondary { background: #95a5a6; }
        .ai-insight-box { background: #f0f7ff; border-left: 5px solid #3498db; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="print-report-header">
            <h2>DPS Loyalty Management System</h2>
            <h3>Executive Business Analytics Report</h3>
            <p>Generated by: <?php echo htmlspecialchars($username); ?> | Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
        </div>

        <div class="welcome-header" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Analytics & Reports 📊</h1>
                    <p>Business intelligence and customer behavior insights.</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="window.print()" style="background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </div>

        <div class="filter-box" style="background: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #555;">From Date:</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #555;">To Date:</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <button type="submit" style="background: #4a3e94; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
            </form>
        </div>

        <div class="ai-insight-box">
            <h3 style="color: #2980b9; margin-top: 0;"><i class="fas fa-robot"></i> AI Business Strategy (Gemini)</h3>
            <p style="font-style: italic; line-height: 1.6;"><?php echo htmlspecialchars($ai_insight); ?></p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-coins" style="font-size: 2rem; color: #f59e0b;"></i>
                <h3>Pts Issued (Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_earned, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-hand-holding-heart" style="font-size: 2rem; color: #ef4444;"></i>
                <h3>Pts Redeemed (Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_redeemed, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-wallet" style="font-size: 2rem; color: #3498db;"></i>
                <h3>Total Circulation</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($net_points_circulation, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-hand-holding-usd" style="font-size: 2rem; color: #16a085;"></i>
                <h3>Sales from Loyalty (Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($total_sales_from_loyalty_transactions, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-users" style="font-size: 2rem; color: #8e44ad;"></i>
                <h3>Active (Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $active_customers_period; ?></p>
            </div>
        </div>

        <div class="table-box" style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 30px;">
            <h3>Daily Points Trend (Last 14 Days)</h3>
            <canvas id="trendChart" height="80"></canvas>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px;" id="charts-section">
            <div class="table-box" style="background: white; padding: 20px; border-radius: 12px;">
                <h3>Loyalty Flow (Issued vs Redeemed)</h3>
                <canvas id="loyaltyChart" height="200"></canvas>
            </div>
            <div class="table-box" style="background: white; padding: 20px; border-radius: 12px;">
                <h3>Points Utilization Rate</h3>
                <canvas id="utilizationChart" height="200"></canvas>
            </div>
        </div>

        <div class="table-box" id="loyal-customers-section" style="background: white; padding: 25px; border-radius: 12px;">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-crown"></i> Customer Segmentation (ML Classification)</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 12px; text-align: left;">Customer</th>
                        <th style="padding: 12px; text-align: left;">Usable Points</th>
                        <th style="padding: 12px; text-align: left;">Engagement</th>
                        <th style="padding: 12px; text-align: left;">Segment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_customers as $c): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($c['name']); ?></strong><br>
                                <small style="color: #777;"><?php echo htmlspecialchars($c['email']); ?></small>
                            </td>
                            <td style="padding: 12px; font-weight: bold; color: #4a3e94;"><?php echo number_format($c['loyalty_points'], 2); ?></td>
                            <td style="padding: 12px;"><?php echo $c['trans_count']; ?> transactions</td>
                            <td style="padding: 12px;">
                                <span class="badge <?php echo $c['ml_class']; ?>">
                                    <?php echo $c['ml_label']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-box" style="background: white; padding: 25px; border-radius: 12px; margin-top: 30px;">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-medal"></i> Staff Performance Ranking</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 12px; text-align: left;">Staff Member</th>
                        <th style="padding: 12px; text-align: center;">Transactions</th>
                        <th style="padding: 12px; text-align: center;">Redemptions</th>
                        <th style="padding: 12px; text-align: right;">Total Points Issued</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($top_staff)): ?>
                        <?php foreach ($top_staff as $staff): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">
                                    <strong><?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></strong><br>
                                    <small style="color: #777;">@<?php echo htmlspecialchars($staff['username']); ?></small>
                                </td>
                                <td style="padding: 12px; text-align: center;"><?php echo $staff['transactions_count']; ?></td>
                                <td style="padding: 12px; text-align: center;"><?php echo $staff['redemptions_processed']; ?></td>
                                <td style="padding: 12px; text-align: right; font-weight: bold; color: #27ae60;">+<?php echo number_format($staff['total_points_awarded'] ?? 0, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="padding: 20px; text-align: center; color: #999;">No staff activity recorded for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-box" style="background: white; padding: 25px; border-radius: 12px; margin-top: 30px; page-break-before: auto;">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-calendar-day"></i> Filtered Daily Activity Summary</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 12px; text-align: left;">Date</th>
                        <th style="padding: 12px; text-align: left;">Points Issued</th>
                        <th style="padding: 12px; text-align: left;">Points Redeemed</th>
                        <th style="padding: 12px; text-align: left;">Net Change</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_summary_data as $day): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;"><?php echo date('F j, Y', strtotime($day['activity_date'])); ?></td>
                            <td style="padding: 12px; color: #27ae60;">+<?php echo number_format($day['earned'], 2); ?></td>
                            <td style="padding: 12px; color: #e67e22;">-<?php echo number_format($day['redeemed'], 2); ?></td>
                            <td style="padding: 12px; font-weight: bold;"><?php echo number_format($day['earned'] - $day['redeemed'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Global Chart Defaults
        const ctxLoyalty = document.getElementById('loyaltyChart').getContext('2d');
        new Chart(ctxLoyalty, {
            type: 'bar',
            data: {
                labels: ['Total Points'],
                datasets: [
                    {
                        label: 'Issued',
                        data: [<?php echo $total_earned; ?>],
                        backgroundColor: '#2ecc71'
                    },
                    {
                        label: 'Redeemed',
                        data: [<?php echo $total_redeemed; ?>],
                        backgroundColor: '#e67e22'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Utilization Pie Chart
        const ctxUtil = document.getElementById('utilizationChart').getContext('2d');
        new Chart(ctxUtil, {
            type: 'doughnut',
            data: {
                labels: ['Redeemed', 'Remaining'],
                datasets: [{
                    data: [<?php echo $total_redeemed; ?>, <?php echo $net_points_circulation; ?>],
                    backgroundColor: ['#e67e22', '#3498db']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Daily Trend Line Chart
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [
                    {
                        label: 'Issued',
                        data: <?php echo json_encode($daily_earned); ?>,
                        borderColor: '#2ecc71',
                        tension: 0.3,
                        fill: false
                    },
                    {
                        label: 'Redeemed',
                        data: <?php echo json_encode($daily_redeemed); ?>,
                        borderColor: '#e67e22',
                        tension: 0.3,
                        fill: false
                    }
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    </script>
</body>
</html>