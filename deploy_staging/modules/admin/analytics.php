<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/validation_helper.php';

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
$date_from = clean_date($_GET['date_from'] ?? '', date('Y-m-d', strtotime('-30 days')));
$date_to = clean_date($_GET['date_to'] ?? '', date('Y-m-d'));

// Fetch Analytics Stats
$total_earned = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
$total_redeemed = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);

// Active customers specifically in this filtered period
$active_customers_period = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) as total FROM (SELECT customer_id FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' UNION SELECT customer_id FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to') as active_pool"))['total'] ?? 0);

$net_points_circulation = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(loyalty_points) as total FROM customers"))['total'] ?? 0);

// Fetch Total Sales and Order Count from Orders
$total_sales = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total_sales FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total_sales'] ?? 0);
$unremitted_cod = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' AND rider_id IS NOT NULL AND order_status = 'completed' AND payment_method = 'cod' AND payment_settled = 0"))['total'] ?? 0);
$total_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
$date_to_inclusive = date('Y-m-d', strtotime($date_to . ' +1 day')); // For queries that need to include the end date

// Prepare data for ML and AI
$all_points = [];
$pts_res = mysqli_query($conn, "SELECT loyalty_points FROM customers");
while ($p_row = mysqli_fetch_assoc($pts_res)) { $all_points[] = (float)$p_row['loyalty_points']; }

// AI Business Insight
$stats_summary = [
    'total_earned' => $total_earned,
    'total_redeemed' => $total_redeemed,
    'active_customers' => $active_customers_period,
    'net_points_in_circulation' => $net_points_circulation,
    'total_sales' => $total_sales
];
$ai_insight = getGeminiBusinessInsight($stats_summary);

// Fetch Top Customers with ML Segmentation
$top_customers = [];
$customers_query = "
    SELECT c.id, c.name, c.email, c.loyalty_points, 
           MAX(lt.created_at) as last_activity,
           COALESCE(purchase_summary.purchased_count, 0) as purchased_count
    FROM customers c
    LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
    LEFT JOIN (
        SELECT o.customer_id, SUM(oi.quantity) as purchased_count
        FROM tbl_orders o
        INNER JOIN tbl_order_items oi ON oi.order_id = o.id
        WHERE o.order_status <> 'cancelled'
        GROUP BY o.customer_id
    ) purchase_summary ON purchase_summary.customer_id = c.id
    GROUP BY c.id
    ORDER BY c.loyalty_points DESC
    LIMIT 10";
$res_top = mysqli_query($conn, $customers_query);
while ($row = mysqli_fetch_assoc($res_top)) {
    $ml = getMLCustomerClassification($row['loyalty_points'], $row['purchased_count'], $row['last_activity'], $all_points);
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
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

while ($s_row = mysqli_fetch_assoc($summary_res)) { $daily_summary_data[] = $s_row; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Reports - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            overflow-x: hidden;
            margin: 0;
            background-color: var(--surface-2);
        }

        /* Simple, print-only business report. Everything else on the page
           (sidebar, filters, charts, cards, AI box, tables) is hidden via
           `body * { visibility: hidden }` and only .print-report-only is
           made visible again — more robust than hiding each screen element
           one by one, and guarantees nothing but the report ever prints. */
        @media print {
            body * { visibility: hidden; }
            .print-report-only, .print-report-only * { visibility: visible; }
            .print-report-only {
                display: block !important;
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                margin: 0;
                padding: 0;
            }
            @page { margin: 1.5cm; }
        }

        .print-report-only { display: none; }
        .print-report-only h1 { font-size: 18px; margin: 0 0 2px; text-align: center; letter-spacing: .5px; }
        .print-report-only h2 { font-size: 14px; margin: 0 0 10px; text-align: center; font-weight: 700; letter-spacing: .5px; }
        .print-report-only .print-meta { text-align: center; font-size: 12px; color: var(--text); margin: 0 0 14px; line-height: 1.5; }
        .print-report-only hr { border: none; border-top: 1px solid #000; margin: 14px 0; }
        .print-report-only .print-section-title { font-weight: 700; font-size: 13px; letter-spacing: .5px; margin-bottom: 8px; }
        .print-report-only table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 6px; }
        .print-report-only .print-summary-table td { padding: 3px 0; }
        .print-report-only .print-summary-table td:first-child { width: 60%; }
        .print-report-only .print-summary-table td:last-child { text-align: right; font-weight: 600; }
        .print-report-only .print-details-table th,
        .print-report-only .print-details-table td { border-bottom: 1px solid #999; padding: 5px 6px; text-align: left; }
        .print-report-only .print-signature { margin-top: 50px; font-size: 12px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; color: white; }
        .badge-success { background: #27ae60; }
        .badge-primary { background: #3498db; }
        .badge-danger { background: #e74c3c; }
        .badge-secondary { background: #95a5a6; }
        .ai-insight-box { background: var(--info-soft); border-left: 4px solid var(--info); padding: 18px 20px; border-radius: var(--radius); margin-bottom: 24px; color: var(--text); }

        /* ---- Compact analytics grid (layout only; theme via --dm-* vars from admin_style.css) ---- */
        .an-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .an-tab { appearance: none; cursor: pointer; font: inherit; font-size: 0.88rem; font-weight: 600; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--dm-border); background: var(--dm-surface-2); color: var(--dm-text-muted); transition: background .15s, color .15s, border-color .15s; }
        .an-tab:hover { color: var(--dm-text); border-color: var(--dm-link); }
        .an-tab:focus-visible { outline: 2px solid var(--dm-link); outline-offset: 2px; }
        .an-tab[aria-selected="true"] { background: #4a3e94; border-color: #4a3e94; color: #fff; box-shadow: 0 2px 6px rgba(74, 62, 148, 0.35); }
        .an-panels { margin-bottom: 30px; }
        .an-card.an-panel { display: none; }
        .an-card.an-panel.active { display: flex; }
        .an-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; align-items: start; }
        .an-cols .an-chart { height: 290px; }
        .an-cols .an-scroll-sm { max-height: 300px; }
        .an-seg { display: flex; flex-wrap: wrap; gap: 8px; }
        .an-seg-item { background: var(--dm-surface-2); border: 1px solid var(--dm-border); border-radius: 8px; padding: 6px 12px; font-size: 0.82rem; color: var(--dm-text); display: flex; align-items: center; gap: 8px; }
        .an-card { min-width: 0; background: var(--dm-surface); color: var(--dm-text); border: 1px solid var(--dm-border); border-radius: 12px; box-shadow: var(--dm-shadow); padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
        .an-head h3 { margin: 0; font-size: 1rem; color: var(--dm-text); }
        .an-head h3 i { color: var(--dm-link); margin-right: 4px; }
        .an-note { margin: 4px 0 0; font-size: 0.78rem; color: var(--dm-text-muted); }
        .an-sub { font-size: 0.82rem; font-weight: 700; color: var(--dm-text-muted); margin-bottom: 6px; }
        .an-chart { position: relative; height: 230px; width: 100%; }
        .an-chart-sm { height: 210px; }
        .an-mini-charts, .an-split { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .an-split > div, .an-mini-charts > div { min-width: 0; }
        .an-scroll { overflow: auto; max-width: 100%; border: 1px solid var(--dm-border); border-radius: 8px; }
        .an-scroll-sm { max-height: 240px; }
        .an-scroll-lg { max-height: 340px; }
        .an-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; font-size: 0.85rem; color: var(--dm-text); }
        .an-table th { position: sticky; top: 0; z-index: 1; background: var(--dm-surface-2); color: var(--dm-text-muted); padding: 8px 10px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: .3px; border-bottom: 1px solid var(--dm-border); white-space: nowrap; text-align: left; }
        .an-table td { padding: 7px 10px; border-bottom: 1px solid var(--dm-border); vertical-align: middle; color: var(--dm-text); }
        .an-table tbody tr:last-child td { border-bottom: none; }
        .an-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .an-table small.an-muted { display: block; color: var(--dm-text-muted); font-size: 0.75rem; overflow-wrap: anywhere; }
        .an-pos { color: var(--success) !important; }
        .an-neg { color: #e67e22 !important; }
        .an-strong { font-weight: 700; }
        .an-link { color: var(--dm-link) !important; font-weight: 700; }
        .an-empty { color: var(--dm-text-muted); font-size: 0.85rem; text-align: center; padding: 14px 10px !important; margin: 0; }

        /* Descriptive help text */
        .stat-help { display: block; margin-top: 6px; font-size: 0.75rem; line-height: 1.4; font-weight: 400; color: var(--muted); }
        .an-legend { border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-surface-2); padding: 8px 12px; font-size: 0.82rem; color: var(--dm-text-muted); }
        .an-legend summary { cursor: pointer; font-weight: 700; color: var(--dm-link); width: fit-content; }
        .an-legend dl { margin: 10px 0 2px; display: grid; grid-template-columns: max-content 1fr; gap: 8px 14px; align-items: baseline; }
        .an-legend dt { margin: 0; }
        .an-legend dd { margin: 0; line-height: 1.45; color: var(--dm-text-muted); }
        .an-legend p { margin: 8px 0 0; line-height: 1.45; }
        th[title] { cursor: help; }
        @media (max-width: 600px) { .an-legend dl { grid-template-columns: 1fr; gap: 4px; } .an-legend dd { margin-bottom: 8px; } }
        body.dark-mode .card .stat-help { color: var(--dm-text-muted); }
        body.dark-mode .ai-insight-box .an-note { color: var(--dm-text-muted); }
        @media (max-width: 600px) { .an-tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; } .an-tab { flex: 0 0 auto; white-space: nowrap; } }
        @media (max-width: 480px) { .an-card { padding: 12px; } .an-table { font-size: 0.8rem; } }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="print-report-only">
            <h1><?php echo htmlspecialchars(SYSTEM_NAME); ?></h1>
            <h2>ANALYTICS &amp; REPORTS</h2>
            <p class="print-meta">
                Report Period: <?php echo date('F j, Y', strtotime($date_from)); ?> &ndash; <?php echo date('F j, Y', strtotime($date_to)); ?><br>
                Generated: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($username); ?>
            </p>
            <hr>
            <div class="print-section-title">SUMMARY</div>
            <table class="print-summary-table">
                <tr><td>Total Orders</td><td><?php echo (int) $total_orders_count; ?></td></tr>
                <tr><td>Total Sales</td><td>PHP <?php echo number_format($total_sales, 2); ?></td></tr>
                <tr><td>Points Issued</td><td><?php echo number_format($total_earned, 2); ?></td></tr>
                <tr><td>Points Redeemed</td><td><?php echo number_format($total_redeemed, 2); ?></td></tr>
                <tr><td>Total Points Circulation</td><td><?php echo number_format($net_points_circulation, 2); ?></td></tr>
                <tr><td>Active Customers (Period)</td><td><?php echo (int) $active_customers_period; ?></td></tr>
                <tr><td>Unremitted COD</td><td>PHP <?php echo number_format($unremitted_cod, 2); ?></td></tr>
            </table>
            <hr>
            <div class="print-section-title">DETAILS &mdash; Daily Activity (within period)</div>
            <table class="print-details-table">
                <thead>
                    <tr><th>Date</th><th>Points Issued</th><th>Points Redeemed</th><th>Net Change</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($daily_summary_data)): ?>
                        <?php foreach ($daily_summary_data as $day): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($day['activity_date'])); ?></td>
                                <td><?php echo number_format($day['earned'], 2); ?></td>
                                <td><?php echo number_format($day['redeemed'], 2); ?></td>
                                <td><?php echo number_format($day['earned'] - $day['redeemed'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No activity recorded for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="print-signature">Prepared by: ____________________________</p>
        </div>

        <div class="welcome-header" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Analytics & Reports 📊</h1>
                    <p>Track loyalty points, sales, customer segments and staff activity for the date range you choose.</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="window.print()" title="Print a one-page summary report for the selected dates" style="background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </div>

        <div class="ai-insight-box">
            <h3 style="color: var(--info); margin-top: 0;"><i class="fas fa-robot"></i> AI Business Strategy (Gemini)</h3>
            <p style="font-style: italic; line-height: 1.6;"><?php echo htmlspecialchars($ai_insight); ?></p>
            <p class="an-note">A short retention suggestion written by Google Gemini each time this page loads. It is based on the selected period's points issued and redeemed, active customers, points in circulation and total sales.</p>
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
            <p style="margin: 12px 0 0; font-size: 0.8rem; line-height: 1.5; color: #64748b;">
                Choose a start and end date, then click <strong>Apply Filter</strong>. Figures marked <em>Selected Period</em> follow this range
                (default: the last 30 days). <strong>Points In Circulation</strong>, <strong>Customer Segmentation</strong> and the
                <strong>Daily Points Trend</strong> do not change with the dates &mdash; each one says what it covers.
            </p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-coins" style="font-size: 2rem; color: #f59e0b;"></i>
                <h3>Points Issued (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_earned, 2); ?></p>
                <small class="stat-help">Loyalty points customers earned within the dates above.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-hand-holding-heart" style="font-size: 2rem; color: #ef4444;"></i>
                <h3>Points Redeemed (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_redeemed, 2); ?></p>
                <small class="stat-help">Points customers spent on rewards within the dates above.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-wallet" style="font-size: 2rem; color: #3498db;"></i>
                <h3>Points In Circulation</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($net_points_circulation, 2); ?></p>
                <small class="stat-help">Usable points all customers hold right now. Not affected by the dates.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-users" style="font-size: 2rem; color: #8e44ad;"></i>
                <h3>Active Customers (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $active_customers_period; ?></p>
                <small class="stat-help">Customers who earned or redeemed points within the dates above.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-dollar-sign" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Total Sales (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($total_sales, 2); ?></p>
                <small class="stat-help">Combined total of every order placed within the dates above, whatever its status.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-hand-holding-usd" style="font-size: 2rem; color: #e74c3c;"></i>
                <h3>Unremitted COD (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($unremitted_cod, 2); ?></p>
                <small class="stat-help">Cash collected by riders on completed cash-on-delivery orders that they have not yet handed over.</small>
            </div>
        </div>

        <div class="an-tabs" role="tablist" aria-label="Analytics sections">
            <button type="button" class="an-tab" role="tab" id="an-tab-daily" data-panel="an-daily-trend" aria-controls="an-daily-trend" aria-selected="true" title="Points issued and redeemed each day for the last 14 days, regardless of the selected dates">Daily Points Trend (Last 14 Days)</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-flow" data-panel="charts-section" aria-controls="charts-section" aria-selected="false" tabindex="-1" title="Total points issued compared with points redeemed in the selected period">Loyalty Flow (Issued vs Redeemed)</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-customers" data-panel="loyal-customers-section" aria-controls="loyal-customers-section" aria-selected="false" tabindex="-1" title="Top customers grouped into Loyal, Frequent, Regular and At Risk">Customer Segmentation (ML Classification)</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-staff" data-panel="an-staff" aria-controls="an-staff" aria-selected="false" tabindex="-1" title="Staff ranked by loyalty points awarded in the selected period">Staff Performance Ranking (Selected Period)</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-activity" data-panel="an-daily-activity" aria-controls="an-daily-activity" aria-selected="false" tabindex="-1" title="Day-by-day points issued and redeemed across the selected dates">Daily Activity (Selected Dates)</button>
        </div>

        <div class="an-panels">

            <!-- Daily Points Trend (Last 14 Days) -->
            <section class="an-card an-panel active" role="tabpanel" id="an-daily-trend" aria-labelledby="an-tab-daily">
                <div class="an-head">
                    <h3><i class="fas fa-chart-column"></i> Daily Points Trend (Last 14 Days)</h3>
                    <p class="an-note">How many loyalty points were issued and redeemed each day over the last 14 days. This view always covers the most recent 14 days and does not follow the date filter. <strong>Net Points</strong> = points issued minus points redeemed that day.</p>
                </div>
                <div class="an-cols">
                <div class="an-chart"><canvas id="trendChart"></canvas></div>
                <div class="an-scroll an-scroll-sm">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="num" title="Points customers earned on this day">Points Issued</th>
                                <th class="num" title="Points customers spent on rewards on this day">Points Redeemed</th>
                                <th class="num" title="Points issued minus points redeemed">Net Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = count($daily_labels) - 1; $i >= 0; $i--): $net_day = $daily_earned[$i] - $daily_redeemed[$i]; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($daily_labels[$i]); ?></td>
                                    <td class="num an-pos">+<?php echo number_format($daily_earned[$i], 2); ?></td>
                                    <td class="num an-neg">-<?php echo number_format($daily_redeemed[$i], 2); ?></td>
                                    <td class="num an-strong"><?php echo number_format($net_day, 2); ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </section>

            <!-- Loyalty Flow (Issued vs Redeemed) -->
            <section class="an-card an-panel" role="tabpanel" id="charts-section" aria-labelledby="an-tab-flow">
                <div class="an-head">
                    <h3><i class="fas fa-right-left"></i> Loyalty Flow (Issued vs Redeemed)</h3>
                    <p class="an-note">Compares the points given out with the points customers have spent on rewards during the selected dates. A large positive difference means points are piling up unused, which may be a sign to promote your rewards.</p>
                </div>
                <div class="an-scroll">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th title="The date range chosen in the filter above">Selected Period</th>
                                <th class="num" title="Points customers earned in this period">Points Issued</th>
                                <th class="num" title="Points customers spent on rewards in this period">Points Redeemed</th>
                                <th class="num" title="Points issued minus points redeemed">Difference (Issued &minus; Redeemed)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($date_from)); ?> &ndash; <?php echo date('M j, Y', strtotime($date_to)); ?></td>
                                <td class="num an-pos">+<?php echo number_format($total_earned, 2); ?></td>
                                <td class="num an-neg">-<?php echo number_format($total_redeemed, 2); ?></td>
                                <td class="num an-strong"><?php echo number_format($total_earned - $total_redeemed, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="an-mini-charts">
                    <div>
                        <div class="an-sub">Points Issued vs Points Redeemed (Selected Period)</div>
                        <div class="an-chart an-chart-sm"><canvas id="loyaltyChart"></canvas></div>
                    </div>
                    <div>
                        <div class="an-sub">Points Utilization Rate &mdash; Redeemed vs Points Still In Circulation</div>
                        <div class="an-chart an-chart-sm"><canvas id="utilizationChart"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- Customer Segmentation (ML Classification) -->
            <section class="an-card an-panel" role="tabpanel" id="loyal-customers-section" aria-labelledby="an-tab-customers">
                <div class="an-head">
                    <h3><i class="fas fa-crown"></i> Customer Segmentation (ML Classification)</h3>
                    <p class="an-note">The top <?php echo count($top_customers); ?> customers by usable points, each placed in a segment by the system's scoring model. This list covers all-time activity and does not follow the date filter.</p>
                </div>
                <details class="an-legend">
                    <summary>How are customers segmented?</summary>
                    <dl>
                        <dt><span class="badge badge-danger">At Risk</span></dt>
                        <dd>Holds more points than the average customer but has not earned any points for over 30 days. Checked first &mdash; consider reaching out with a reminder or offer.</dd>
                        <dt><span class="badge badge-success">Loyal</span></dt>
                        <dd>Holds well above the average number of points, or has a very high combined score from points and purchases.</dd>
                        <dt><span class="badge badge-primary">Frequent</span></dt>
                        <dd>Holds at least the average number of points, or has bought 5 or more items.</dd>
                        <dt><span class="badge badge-secondary">Regular</span></dt>
                        <dd>Everyone else &mdash; customers who buy occasionally and hold fewer points.</dd>
                    </dl>
                    <p>Points are compared with the average across all customers. <strong>Items Purchased</strong> counts the items in each customer's non-cancelled orders.</p>
                </details>
                <?php
                    $seg_counts = [];
                    foreach ($top_customers as $c) { $seg_counts[$c['ml_label']] = ($seg_counts[$c['ml_label']] ?? 0) + 1; }
                    arsort($seg_counts);
                    $seg_total = count($top_customers);
                ?>
                <?php if ($seg_total > 0): ?>
                    <div class="an-seg">
                        <?php foreach ($seg_counts as $seg_label => $seg_n): ?>
                            <span class="an-seg-item"><strong><?php echo htmlspecialchars($seg_label); ?></strong> <?php echo (int)$seg_n; ?> &middot; <?php echo number_format($seg_n / $seg_total * 100, 0); ?>%</span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="an-scroll an-scroll-lg">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th title="Customer name and email">Customer</th>
                                <th class="num" title="Points the customer can currently spend on rewards">Usable Points</th>
                                <th title="Total items bought across all non-cancelled orders">Items Purchased</th>
                                <th title="Segment assigned by the scoring model. See &quot;How are customers segmented?&quot; above">Segment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_customers as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                                        <small class="an-muted"><?php echo htmlspecialchars($c['email']); ?></small>
                                    </td>
                                    <td class="num an-link"><?php echo number_format($c['loyalty_points'], 2); ?></td>
                                    <td><?php echo (int)$c['purchased_count']; ?> items</td>
                                    <td><span class="badge <?php echo $c['ml_class']; ?>"><?php echo $c['ml_label']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Staff Performance Ranking -->
            <section class="an-card an-panel" role="tabpanel" id="an-staff" aria-labelledby="an-tab-staff">
                <div class="an-head">
                    <h3><i class="fas fa-medal"></i> Staff Performance Ranking (Selected Period)</h3>
                    <p class="an-note">The top 5 staff members ranked by the loyalty points they awarded to customers during the selected dates.</p>
                </div>
                <div class="an-scroll an-scroll-lg">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th class="num" title="Rank by total points issued">#</th>
                                <th title="Staff member's name and username">Staff Member</th>
                                <th class="num" title="Number of point-awarding transactions this person handled">Points Transactions</th>
                                <th class="num" title="Number of reward redemptions this person processed">Rewards Redeemed</th>
                                <th class="num" title="Total loyalty points this person awarded to customers">Total Points Issued</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_staff)): ?>
                                <?php $staff_rank = 0; foreach ($top_staff as $staff): $staff_rank++; ?>
                                    <tr>
                                        <td class="num an-strong"><?php echo $staff_rank; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></strong>
                                            <small class="an-muted">@<?php echo htmlspecialchars($staff['username']); ?></small>
                                        </td>
                                        <td class="num"><?php echo $staff['transactions_count']; ?></td>
                                        <td class="num"><?php echo $staff['redemptions_processed']; ?></td>
                                        <td class="num an-pos an-strong">+<?php echo number_format($staff['total_points_awarded'] ?? 0, 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="an-empty">No staff activity recorded for this period. Try a wider date range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Filtered Daily Activity Summary -->
            <section class="an-card an-panel" role="tabpanel" id="an-daily-activity" aria-labelledby="an-tab-activity">
                <div class="an-head">
                    <h3><i class="fas fa-calendar-day"></i> Daily Activity (Selected Dates)</h3>
                    <p class="an-note">A day-by-day list of points issued and redeemed for every day in the selected date range that had activity, newest first. Days with no activity are left out.</p>
                </div>
                <div class="an-scroll an-scroll-lg">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="num" title="Points customers earned on this day">Points Issued</th>
                                <th class="num" title="Points customers spent on rewards on this day">Points Redeemed</th>
                                <th class="num" title="Points issued minus points redeemed">Net Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($daily_summary_data)): ?>
                                <?php foreach ($daily_summary_data as $day): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($day['activity_date'])); ?></td>
                                        <td class="num an-pos">+<?php echo number_format($day['earned'], 2); ?></td>
                                        <td class="num an-neg">-<?php echo number_format($day['redeemed'], 2); ?></td>
                                        <td class="num an-strong"><?php echo number_format($day['earned'] - $day['redeemed'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="an-empty">No points were issued or redeemed in the selected date range. Try a wider range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </div>

    <script>
        // Chart.js theme awareness — Dark Mode (assets/js/nav-toggle.js) only
        // toggles a body class + CSS variables, which Chart.js's canvas-drawn
        // text/gridlines can't see on their own. Apply readable colors for the
        // current theme up front, and again live if the toggle fires while
        // this page is open (see the 'dps:theme-change' event dispatched from
        // nav-toggle.js) — no chart data/logic touched either way.
        function applyChartTheme() {
            var dark = document.body.classList.contains('dark-mode');
            Chart.defaults.color = dark ? '#9aa5b1' : '#666';
            Chart.defaults.borderColor = dark ? '#333c48' : '#e5e5e5';
        }
        applyChartTheme();

        // Global Chart Defaults
        const ctxLoyalty = document.getElementById('loyaltyChart').getContext('2d');
        const loyaltyChart = new Chart(ctxLoyalty, {
            type: 'bar',
            data: {
                labels: ['Selected Period'],
                datasets: [
                    {
                        label: 'Points Issued',
                        data: [<?php echo $total_earned; ?>],
                        backgroundColor: '#2ecc71'
                    },
                    {
                        label: 'Points Redeemed',
                        data: [<?php echo $total_redeemed; ?>],
                        backgroundColor: '#e67e22'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, scales: { y: { beginAtZero: true } }
            }
        });

        // Utilization Pie Chart
        const ctxUtil = document.getElementById('utilizationChart').getContext('2d');
        const utilChart = new Chart(ctxUtil, {
            type: 'doughnut',
            data: {
                labels: ['Points Redeemed (Selected Period)', 'Points Still In Circulation'],
                datasets: [{
                    data: [<?php echo $total_redeemed; ?>, <?php echo $net_points_circulation; ?>],
                    backgroundColor: ['#e67e22', '#3498db']
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Daily Points Trend - grouped bar chart (issued vs redeemed, one pair of bars per day)
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [
                    {
                        label: 'Points Issued',
                        data: <?php echo json_encode($daily_earned); ?>,
                        backgroundColor: '#2ecc71',
                        borderRadius: 4,
                        maxBarThickness: 26
                    },
                    {
                        label: 'Points Redeemed',
                        data: <?php echo json_encode($daily_redeemed); ?>,
                        backgroundColor: '#e67e22',
                        borderRadius: 4,
                        maxBarThickness: 26
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true } }
            }
        });

        // Analytics tabs - pure show/hide, no reload. Last tab is remembered under
        // its own key (does not touch the theme/sidebar keys).
        (function () {
            var KEY = 'dps_analytics_active_tab';
            var tabs = Array.prototype.slice.call(document.querySelectorAll('.an-tab'));
            var panels = Array.prototype.slice.call(document.querySelectorAll('.an-panel'));
            var charts = [trendChart, loyaltyChart, utilChart];
            function exists(id) { return tabs.some(function (t) { return t.dataset.panel === id; }); }
            function activate(id, focusTab) {
                tabs.forEach(function (t) {
                    var on = t.dataset.panel === id;
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    t.tabIndex = on ? 0 : -1;
                    if (on && focusTab) t.focus();
                });
                panels.forEach(function (p) { p.classList.toggle('active', p.id === id); });
                // Charts drawn while hidden have no size; re-measure now that the panel is visible.
                requestAnimationFrame(function () { charts.forEach(function (c) { c.resize(); }); });
                try { localStorage.setItem(KEY, id); } catch (e) {}
            }
            tabs.forEach(function (t, i) {
                t.addEventListener('click', function () { activate(t.dataset.panel); });
                t.addEventListener('keydown', function (e) {
                    var n = null;
                    if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
                    else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
                    else if (e.key === 'Home') n = 0;
                    else if (e.key === 'End') n = tabs.length - 1;
                    if (n !== null) { e.preventDefault(); activate(tabs[n].dataset.panel, true); }
                });
            });
            // Deep links from other pages (#charts-section, #loyal-customers-section, ...) win over the remembered tab.
            var start = tabs[0].dataset.panel;
            var hash = location.hash.replace('#', '');
            if (hash && exists(hash)) start = hash;
            else { try { var saved = localStorage.getItem(KEY); if (saved && exists(saved)) start = saved; } catch (e) {} }
            if (start !== tabs[0].dataset.panel) activate(start);
            window.addEventListener('hashchange', function () {
                var h = location.hash.replace('#', '');
                if (h && exists(h)) activate(h);
            });
        })();

        document.addEventListener('dps:theme-change', function () {
            applyChartTheme();
            [loyaltyChart, utilChart, trendChart].forEach(function (c) {
                c.options.scales && Object.keys(c.options.scales).forEach(function (axis) {
                    c.options.scales[axis].ticks = c.options.scales[axis].ticks || {};
                    c.options.scales[axis].ticks.color = Chart.defaults.color;
                    c.options.scales[axis].grid = c.options.scales[axis].grid || {};
                    c.options.scales[axis].grid.color = Chart.defaults.borderColor;
                });
                c.update();
            });
        });
    </script>
</body>
</html>