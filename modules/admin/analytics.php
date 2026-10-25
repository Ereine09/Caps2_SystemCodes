<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/validation_helper.php';
require_once __DIR__ . '/../../app/helpers/analytics_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php'; // the Gemini buying-behavior button posts with a CSRF token

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
// Future dates can't be picked (the inputs have max = today); a typed/URL one is treated as today.
$date_from = min($date_from, date('Y-m-d'));
$date_to = min($date_to, date('Y-m-d'));

// Fetch Analytics Stats
['earned' => $total_earned, 'redeemed' => $total_redeemed] = analytics_points_totals($conn, $date_from, $date_to);

// Active customers specifically in this filtered period
$active_customers_period = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) as total FROM (SELECT customer_id FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' UNION SELECT customer_id FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to') as active_pool"))['total'] ?? 0);

$net_points_circulation = analytics_points_outstanding($conn);

// Fetch Total Sales and Order Count from Orders
// Sales = money actually received in the period (COD once its remittance is accepted, GCash/Bank
// once approved, Pay-at-shop once completed), dated by the day it came in. See sales_helper.php.
require_once __DIR__ . '/../../app/helpers/sales_helper.php';
$total_sales = sales_between($conn, $date_from, $date_to);
$unremitted_cod = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' AND rider_id IS NOT NULL AND order_status = 'completed' AND payment_method = 'cod' AND payment_settled = 0"))['total'] ?? 0);
$total_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM tbl_orders WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
$date_to_inclusive = date('Y-m-d', strtotime($date_to . ' +1 day')); // For queries that need to include the end date

// AI Business Insight
$stats_summary = [
    'total_earned' => $total_earned,
    'total_redeemed' => $total_redeemed,
    'active_customers' => $active_customers_period,
    'net_points_in_circulation' => $net_points_circulation,
    'total_sales' => $total_sales
];
$ai_insight = getGeminiBusinessInsight($stats_summary);

// Customer groups (K-Means): top few of each group, plus every group's size.
$per_group_limit = 5;
// Every customer once (same K-Means classification as before); the table keeps the top few of each
// group, and the "Analyze buying behavior" picker lists them all.
['totals' => $group_totals, 'top' => $all_group_customers] = analytics_customer_groups($conn, 0);
$top_customers = [];
$shown_per_group = [];
foreach ($all_group_customers as $gc) {
    $shown_per_group[$gc['ml_label']] = ($shown_per_group[$gc['ml_label']] ?? 0) + 1;
    if ($shown_per_group[$gc['ml_label']] <= $per_group_limit) $top_customers[] = $gc;
}
$customer_group_priority = analytics_group_priority();

// Top staff by points given, and points by day for the trend chart (fixed 14-day lookback).
$top_staff = analytics_top_staff($conn, $date_from, $date_to);
['labels' => $daily_labels, 'earned' => $daily_earned, 'redeemed' => $daily_redeemed] = analytics_last_14_days($conn);

// Points by day within the selected dates (the one-page Print Summary).
$daily_summary_data = analytics_daily_in_period($conn, $date_from, $date_to);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Reports - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
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
        .an-print-btn { float: right; margin: 0 0 6px 12px; text-decoration: none; }


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
        .badge-info { background: #0ea5e9; }
        .ai-insight-box { background: var(--info-soft); border-left: 4px solid var(--info); padding: 18px 20px; border-radius: var(--radius); margin-bottom: 24px; color: var(--text); }

        /* ---- Compact analytics grid (layout only; theme via --dm-* vars from admin_style.css) ---- */
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
        .an-stat-link { display: block; color: inherit; text-decoration: none; transition: box-shadow .15s, transform .15s; }
        .an-stat-link:hover { box-shadow: var(--shadow) !important; transform: translateY(-1px); }
        .an-stat-more { display: inline-block; margin-top: 8px; font-size: 0.8rem; font-weight: 600; color: var(--primary); }
        @media (max-width: 600px) { .an-legend dl { grid-template-columns: 1fr; gap: 4px; } .an-legend dd { margin-bottom: 8px; } }
        body.dark-mode .card .stat-help { color: var(--dm-text-muted); }
        body.dark-mode .ai-insight-box .an-note { color: var(--dm-text-muted); }
        @media (max-width: 480px) { .an-card { padding: 12px; } .an-table { font-size: 0.8rem; } }

        /* Buying behavior: K-Means (classification) + Gemini AI (explanation) */
        .cba-row-btn { border: 1px solid var(--dm-border); background: var(--dm-surface-2); color: var(--dm-link); border-radius: 999px; padding: 4px 10px; font-size: 0.75rem; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .cba-row-btn:hover:not(:disabled) { border-color: var(--dm-link); }
        .cba-row-btn:disabled { opacity: .45; cursor: not-allowed; }
        .cba { border: 1px solid var(--dm-border); border-radius: 12px; padding: 14px; background: var(--dm-surface-2); display: flex; flex-direction: column; gap: 12px; }
        .cba-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
        .cba-head h4 { margin: 0; font-size: 0.95rem; color: var(--dm-text); }
        .cba-head h4 i { color: var(--dm-link); margin-right: 4px; }
        .cba-head select { min-width: 240px; max-width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--dm-border); background: var(--dm-surface); color: var(--dm-text); font-size: 0.85rem; }
        #cba-body { display: flex; flex-direction: column; gap: 12px; }
        .cba-card { background: var(--dm-surface); border: 1px solid var(--dm-border); border-radius: 12px; padding: 14px; }
        .cba-kicker { display: flex; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: var(--dm-text-muted); }
        .cba-kicker i { color: var(--dm-link); }
        .cba-kicker .cba-tag { margin-left: auto; text-transform: none; letter-spacing: 0; font-weight: 600; }
        /* K-Means: who + group | RFM meters | purchase summary */
        .cba-ml { display: grid; grid-template-columns: minmax(180px, 220px) 1fr 1fr; gap: 16px; align-items: start; }
        .cba-who { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
        .cba-avatar { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; color: #fff; background: var(--primary); }
        .cba-who strong { font-size: 1rem; color: var(--dm-text); line-height: 1.25; }
        .cba-who .badge { font-size: 0.8rem; padding: 4px 12px; }
        .cba-who small { font-size: 0.75rem; color: var(--dm-text-muted); line-height: 1.4; }
        .cba-meters { display: flex; flex-direction: column; gap: 10px; }
        .cba-meter-top { display: flex; justify-content: space-between; gap: 8px; font-size: 0.8rem; color: var(--dm-text-muted); }
        .cba-meter-top b { color: var(--dm-text); font-variant-numeric: tabular-nums; }
        .cba-meter-top span i { width: 14px; color: var(--dm-link); }
        .cba-bar { height: 7px; border-radius: 999px; background: var(--dm-surface-2); border: 1px solid var(--dm-border); overflow: hidden; margin-top: 4px; }
        .cba-bar span { display: block; height: 100%; border-radius: inherit; background: var(--dm-link); }
        .cba-meter small { display: block; margin-top: 2px; font-size: 0.7rem; color: var(--dm-text-muted); }
        .cba-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; margin: 0; }
        .cba-facts div { min-width: 0; }
        .cba-facts dt { font-size: 0.7rem; color: var(--dm-text-muted); text-transform: uppercase; letter-spacing: .3px; }
        .cba-facts dd { margin: 0; font-size: 0.92rem; font-weight: 700; color: var(--dm-text); font-variant-numeric: tabular-nums; }
        .cba-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
        .cba-chips span { padding: 3px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; background: var(--dm-surface-2); border: 1px solid var(--dm-border); color: var(--dm-text-muted); }
        /* Gemini AI: 2 x 2 cards */
        .cba-ai { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .cba-ai section { border: 1px solid var(--dm-border); border-left: 4px solid var(--cba-accent, var(--dm-link)); border-radius: 10px; padding: 12px 14px; background: var(--dm-surface); }
        .cba-ai h5 { display: flex; align-items: center; gap: 8px; margin: 0 0 6px; font-size: 0.85rem; color: var(--dm-text); }
        .cba-ai h5 i { width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; font-size: 0.8rem; color: var(--cba-accent, var(--dm-link)); background: var(--dm-surface-2); }
        .cba-ai p { margin: 0; font-size: 0.86rem; line-height: 1.55; color: var(--dm-text); }
        .cba-ai .k-behavior { --cba-accent: #6366f1; }
        .cba-ai .k-engage { --cba-accent: #0ea5e9; }
        .cba-ai .k-strategy { --cba-accent: #16a34a; }
        .cba-ai .k-promo { --cba-accent: #ea580c; }
        .cba-eng { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: var(--dm-surface-2); color: var(--dm-text-muted); }
        .cba-eng.active { background: rgba(22,163,74,.14); color: #16a34a; }
        .cba-eng.occasional { background: rgba(37,99,235,.12); color: #2563eb; }
        .cba-eng.becoming_inactive { background: rgba(234,88,12,.14); color: #ea580c; }
        .cba-eng.inactive { background: rgba(220,38,38,.12); color: #dc2626; }
        .cba-empty-ai { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
        .cba-empty-ai p { margin: 0; font-size: 0.85rem; color: var(--dm-text-muted); max-width: 560px; line-height: 1.5; }
        .cba-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-top: 10px; font-size: 0.75rem; color: var(--dm-text-muted); }
        .cba-btn { border: 0; border-radius: 8px; padding: 9px 16px; background: var(--primary); color: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .cba-btn.ghost { background: transparent; color: var(--dm-link); border: 1px solid var(--dm-border); padding: 6px 12px; font-size: 0.8rem; }
        .cba-btn:disabled { opacity: .6; cursor: wait; }
        .cba-stale { color: #ea580c; }
        .cba-err { color: #dc2626; font-size: 0.85rem; margin: 0; }
        .cba-disclaimer { margin: 0; font-size: 0.72rem; color: var(--dm-text-muted); }
        @media (max-width: 1100px) { .cba-ml { grid-template-columns: 1fr 1fr; } .cba-who { grid-column: 1 / -1; flex-direction: row; flex-wrap: wrap; align-items: center; } }
        @media (max-width: 700px) { .cba-ml, .cba-ai { grid-template-columns: 1fr; } .cba-head select { min-width: 0; width: 100%; } }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <?php
        // Each tab's "Print" opens its soft copy: a printable report document (analytics_report.php).
        $an_print_btn = static fn(string $report, string $what): string => '<a class="ui-btn ui-btn-soft ui-btn-sm an-print-btn" target="_blank" rel="noopener" href="analytics_report.php?report=' . $report
            . '&amp;date_from=' . urlencode($date_from) . '&amp;date_to=' . urlencode($date_to) . '" title="Open a printable copy of the ' . htmlspecialchars($what) . ' report"><i class="fas fa-print"></i> Print</a>';
        ?>
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
                <tr><td>Points Earned</td><td><?php echo number_format($total_earned, 2); ?></td></tr>
                <tr><td>Points Used</td><td><?php echo number_format($total_redeemed, 2); ?></td></tr>
                <tr><td>Points Customers Still Have</td><td><?php echo number_format($net_points_circulation, 2); ?></td></tr>
                <tr><td>Active Customers (Period)</td><td><?php echo (int) $active_customers_period; ?></td></tr>
                <tr><td>COD Not Yet Turned In</td><td>PHP <?php echo number_format($unremitted_cod, 2); ?></td></tr>
            </table>
            <hr>
            <div class="print-section-title">DETAILS &mdash; Points by Day (within period)</div>
            <table class="print-details-table">
                <thead>
                    <tr><th>Date</th><th>Points Earned</th><th>Points Used</th><th>Difference</th></tr>
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
                    <p>Track loyalty points, sales, customer groups and staff activity for the date range you choose.</p>
                </div>
            </div>
        </div>

        <div class="ai-insight-box">
            <h3 style="color: var(--info); margin-top: 0;"><i class="fas fa-robot"></i> AI Tip (Google Gemini)</h3>
            <p style="font-style: italic; line-height: 1.6;"><?php echo htmlspecialchars($ai_insight); ?></p>
            <p class="an-note">A short suggestion written by Google Gemini each time this page loads, based on the selected period's points earned and used, active customers, points customers still have, and total sales.</p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-coins" style="font-size: 2rem; color: #f59e0b;"></i>
                <h3>Points Earned (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_earned, 2); ?></p>
                <small class="stat-help">Loyalty points customers earned within the dates above.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-hand-holding-heart" style="font-size: 2rem; color: #ef4444;"></i>
                <h3>Points Used (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_redeemed, 2); ?></p>
                <small class="stat-help">Points customers spent on rewards within the dates above.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-wallet" style="font-size: 2rem; color: #3498db;"></i>
                <h3>Points Customers Still Have</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($net_points_circulation, 2); ?></p>
                <small class="stat-help">Usable points all customers hold right now. Not affected by the dates.</small>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-users" style="font-size: 2rem; color: #8e44ad;"></i>
                <h3>Active Customers (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $active_customers_period; ?></p>
                <small class="stat-help">Customers who earned or redeemed points within the dates above.</small>
            </div>
            <a href="sales_breakdown.php?from=<?php echo urlencode($date_from); ?>&amp;to=<?php echo urlencode($date_to); ?>" class="card an-stat-link" title="See where this money came from" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-peso-sign" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Total Sales (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($total_sales, 2); ?></p>
                <small class="stat-help">Money actually received within the dates above (COD once the rider's cash is turned in, GCash/Bank once verified, Pay at Shop once paid).</small>
                <span class="an-stat-more">View breakdown <i class="fas fa-arrow-right"></i></span>
            </a>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 200px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-peso-sign" style="font-size: 2rem; color: #e74c3c;"></i>
                <h3>COD Not Yet Turned In (Selected Period)</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format($unremitted_cod, 2); ?></p>
                <small class="stat-help">Cash collected by riders on completed cash-on-delivery orders that they have not yet handed over.</small>
            </div>
        </div>

        <div class="filter-box" style="background: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #555;">From Date:</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #555;">To Date:</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <button type="submit" style="background: #4a3e94; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                <button type="button" onclick="window.print()" title="Print a one-page summary report for the selected dates" style="margin-left: auto; background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-print"></i> Print Summary
                </button>
            </form>
            <p style="margin: 12px 0 0; font-size: 0.8rem; line-height: 1.5; color: #64748b;">
                Choose a start and end date, then click <strong>Apply Filter</strong>. Figures marked <em>Selected Period</em> follow this range
                (default: the last 30 days). <strong>Points Customers Still Have</strong>, <strong>Customer Groups</strong> and
                <strong>Points Earned &amp; Used - Last 14 Days</strong> do not change with the dates &mdash; each one says what it covers.
            </p>
        </div>

        <div class="an-tabs ui-tabs ui-tabs-fill" role="tablist" aria-label="Analytics sections">
            <button type="button" class="an-tab" role="tab" id="an-tab-daily" data-panel="an-daily-trend" aria-controls="an-daily-trend" aria-selected="true" title="Points earned and used each day for the last 14 days, regardless of the selected dates">Points Earned &amp; Used</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-flow" data-panel="charts-section" aria-controls="charts-section" aria-selected="false" tabindex="-1" title="Total points earned compared with points used in the selected period">Points Given vs. Points Used</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-customers" data-panel="loyal-customers-section" aria-controls="loyal-customers-section" aria-selected="false" tabindex="-1" title="Top customers grouped into Loyal, Frequent, Regular and At Risk">Customer Groups (Loyal, Frequent, Regular, At Risk)</button>
            <button type="button" class="an-tab" role="tab" id="an-tab-staff" data-panel="an-staff" aria-controls="an-staff" aria-selected="false" tabindex="-1" title="Staff ranked by loyalty points awarded in the selected period">Top Staff by Points Given</button>
        </div>

        <div class="an-panels">

            <!-- Points Earned & Used - Last 14 Days -->
            <section class="an-card an-panel active" role="tabpanel" id="an-daily-trend" aria-labelledby="an-tab-daily">
                <div class="an-head">
                    <?php echo $an_print_btn('daily', 'Points Earned & Used'); ?>
                    <h3><i class="fas fa-chart-column"></i> Points Earned &amp; Used - Last 14 Days</h3>
                    <p class="an-note">How many loyalty points customers earned and used each day over the last 14 days. This view always covers the most recent 14 days and does not follow the date filter. <strong>Difference</strong> = points earned minus points used that day.</p>
                </div>
                <div class="an-cols">
                <div class="an-chart"><canvas id="trendChart"></canvas></div>
                <div class="an-scroll an-scroll-sm">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="num" title="Points customers earned on this day">Points Earned</th>
                                <th class="num" title="Points customers spent on rewards on this day">Points Used</th>
                                <th class="num" title="Points earned minus points used">Difference</th>
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

            <!-- Points Given vs. Points Used -->
            <section class="an-card an-panel" role="tabpanel" id="charts-section" aria-labelledby="an-tab-flow">
                <div class="an-head">
                    <?php echo $an_print_btn('flow', 'Points Given vs. Points Used'); ?>
                    <h3><i class="fas fa-right-left"></i> Points Given vs. Points Used</h3>
                    <p class="an-note">Compares the points given out with the points customers have spent on rewards during the selected dates. A large positive difference means points are piling up unused, which may be a sign to promote your rewards.</p>
                </div>
                <div class="an-scroll">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th title="The date range chosen in the filter above">Selected Period</th>
                                <th class="num" title="Points customers earned in this period">Points Earned</th>
                                <th class="num" title="Points customers spent on rewards in this period">Points Used</th>
                                <th class="num" title="Points earned minus points used">Difference (Earned &minus; Used)</th>
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
                        <div class="an-sub">Points Earned vs Points Used (Selected Period)</div>
                        <div class="an-chart an-chart-sm"><canvas id="loyaltyChart"></canvas></div>
                    </div>
                    <div>
                        <div class="an-sub">Points Used vs Points Customers Still Have</div>
                        <div class="an-chart an-chart-sm"><canvas id="utilizationChart"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- Customer Groups (Loyal, Frequent, Regular, At Risk) -->
            <section class="an-card an-panel" role="tabpanel" id="loyal-customers-section" aria-labelledby="an-tab-customers">
                <div class="an-head">
                    <?php echo $an_print_btn('groups', 'Customer Groups'); ?>
                    <h3><i class="fas fa-crown"></i> Customer Groups (Loyal, Frequent, Regular, At Risk)</h3>
                    <p class="an-note">Every customer is placed in a group by K-Means clustering of their purchase recency, frequency and points. The counts below cover all <?php echo (int) array_sum($group_totals); ?> customers; the table lists the top <?php echo (int) $per_group_limit; ?> of each group by usable points. This covers all-time activity and does not follow the date filter.</p>
                </div>
                <details class="an-legend">
                    <summary>How are these groups decided?</summary>
                    <dl>
                        <dt>How it works</dt>
                        <dd>The system uses <strong>K-Means clustering</strong> (machine learning). It measures every customer who has bought at least once on three things &mdash; <strong>Recency</strong> (days since last purchase), <strong>Frequency</strong> (number of purchases) and <strong>Monetary</strong> (loyalty points earned from spending) &mdash; and groups customers who behave alike into 4 groups. Each group is named from its average customer, and every customer joins the group they are closest to. The groups are learned from the data, not set by fixed rules, so they adjust as customers buy.</dd>
                        <dt><span class="badge badge-success">Loyal</span></dt>
                        <dd>The group that buys most often and holds the most points, and bought recently.</dd>
                        <dt><span class="badge badge-primary">Frequent</span></dt>
                        <dd>Buys regularly and recently, with a moderate number of points.</dd>
                        <dt><span class="badge badge-secondary">Regular</span></dt>
                        <dd>Buys occasionally and holds fewer points.</dd>
                        <dt><span class="badge badge-danger">At Risk</span></dt>
                        <dd>Has bought before but has gone the longest without buying &mdash; at risk of not coming back. Consider reaching out with a reminder or offer.</dd>
                        <dt><span class="badge badge-info">New</span></dt>
                        <dd>Has an account but no purchases yet, so there is no buying behavior to group. Not part of the clustering.</dd>
                        <dt><i class="fas fa-wand-magic-sparkles"></i> Gemini AI</dt>
                        <dd><strong>K-Means decides the group; Gemini AI only explains it.</strong> When you press <em>Analyze buying behavior</em>, Google Gemini reads that customer's purchase numbers (no name, email, phone or address) and writes why they fit their group, how engaged they are, a retention strategy and a promotion that uses only rewards the store really offers. Gemini never changes the group, points, vouchers or orders.</dd>
                    </dl>
                    <p>Points are compared with the average across all customers. <strong>Items Purchased</strong> counts the items in each customer's non-cancelled orders.</p>
                </details>
                <?php
                    $seg_counts = $group_totals;
                    uksort($seg_counts, function ($a, $b) use ($customer_group_priority) {
                        return ($customer_group_priority[$a] ?? 99) <=> ($customer_group_priority[$b] ?? 99);
                    });
                    $seg_total = array_sum($group_totals);
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
                                <th title="Group assigned based on points and activity. See &quot;How are these groups decided?&quot; above">Group</th>
                                <th title="Gemini AI explains this customer's buying behavior and suggests a retention strategy">AI</th>
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
                                    <td><button type="button" class="cba-row-btn" data-cba="<?php echo (int) $c['id']; ?>" title="Analyze buying behavior"<?php echo $c['ml_label'] === 'New' ? ' disabled' : ''; ?>><i class="fas fa-wand-magic-sparkles"></i> Analyze</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Buying behavior: K-Means group + Gemini AI explanation, on demand -->
                <div class="cba" id="cba">
                    <div class="cba-head">
                        <h4><i class="fas fa-wand-magic-sparkles"></i> Analyze buying behavior</h4>
                        <select id="cba-pick" aria-label="Choose a customer to analyze">
                            <option value="">Choose a customer&hellip;</option>
                            <?php
                                $cba_groups = [];
                                foreach ($all_group_customers as $gc) $cba_groups[$gc['ml_label']][] = $gc;
                                foreach ($cba_groups as $glabel => $gcs):
                                    if ($glabel === 'New') continue; // no purchases = nothing to analyze
                            ?>
                                <optgroup label="<?php echo htmlspecialchars($glabel); ?> (<?php echo count($gcs); ?>)">
                                    <?php foreach ($gcs as $gc): ?>
                                        <option value="<?php echo (int) $gc['id']; ?>"><?php echo htmlspecialchars($gc['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cba-body"><p class="an-empty">Pick a customer (or press <strong>Analyze</strong> in the table) to see their K-Means group, their numbers and a Gemini AI explanation.</p></div>
                </div>
            </section>

            <!-- Top Staff by Points Given -->
            <section class="an-card an-panel" role="tabpanel" id="an-staff" aria-labelledby="an-tab-staff">
                <div class="an-head">
                    <?php echo $an_print_btn('staff', 'Top Staff'); ?>
                    <h3><i class="fas fa-medal"></i> Top Staff by Points Given</h3>
                    <p class="an-note">The top 5 staff members ranked by the loyalty points they awarded to customers during the selected dates.</p>
                </div>
                <div class="an-scroll an-scroll-lg">
                    <table class="an-table">
                        <thead>
                            <tr>
                                <th class="num" title="Rank by total points given">#</th>
                                <th title="Staff member's name and username">Staff Member</th>
                                <th class="num" title="Number of times this person gave points to a customer">Times Gave Points</th>
                                <th class="num" title="Number of reward redemptions this person processed">Redemptions Handled</th>
                                <th class="num" title="Total loyalty points this person gave to customers">Total Points Given</th>
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
            Chart.defaults.color = dark ? '#968c80' : '#666';
            Chart.defaults.borderColor = dark ? '#332e28' : '#e5e5e5';
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
                        label: 'Points Earned',
                        data: [<?php echo $total_earned; ?>],
                        backgroundColor: '#2ecc71'
                    },
                    {
                        label: 'Points Used',
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
                labels: ['Points Used (Selected Period)', 'Points Customers Still Have'],
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

        // Points Earned & Used - grouped bar chart (earned vs used, one pair of bars per day)
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [
                    {
                        label: 'Points Earned',
                        data: <?php echo json_encode($daily_earned); ?>,
                        backgroundColor: '#2ecc71',
                        borderRadius: 4,
                        maxBarThickness: 26
                    },
                    {
                        label: 'Points Used',
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
                scales: {
                    y: { beginAtZero: true, grace: '12%', title: { display: true, text: 'Number of points', font: { weight: '600' } } },
                    x: { title: { display: true, text: 'Date', font: { weight: '600' } } }
                }
            },
            plugins: [{
                // Write each bar's value just above it (zero days are skipped to keep it readable).
                id: 'barValues',
                afterDatasetsDraw(chart) {
                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.font = '600 11px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    chart.data.datasets.forEach((ds, i) => {
                        const meta = chart.getDatasetMeta(i);
                        if (meta.hidden) return;
                        ctx.fillStyle = ds.backgroundColor === '#e67e22' ? '#c2410c' : '#15803d';
                        meta.data.forEach((bar, j) => {
                            const v = Number(ds.data[j]) || 0;
                            if (v === 0) return;
                            ctx.fillText(v % 1 === 0 ? v.toLocaleString() : v.toLocaleString(undefined, { maximumFractionDigits: 2 }), bar.x, bar.y - 3);
                        });
                    });
                    ctx.restore();
                }
            }]
        });

        // Buying behavior: the group comes from K-Means; Gemini only explains it (on demand, cached server-side).
        (function () {
            var API = 'customer_behavior_ai_api.php';
            var CSRF = <?php echo json_encode(csrf_token()); ?>;
            var pick = document.getElementById('cba-pick'), body = document.getElementById('cba-body'), box = document.getElementById('cba');
            if (!pick || !body) return;
            var current = 0, busy = false;
            function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
            function peso(n) { return n == null ? '—' : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function num(n, d) { return n == null ? '—' : Number(n).toLocaleString('en-PH', { maximumFractionDigits: d || 0 }); }
            function days(n, ago) {
                if (n == null) return '—';
                n = Math.round(Number(n));
                if (ago && n === 0) return 'Today';
                return n + (n === 1 ? ' day' : ' days') + (ago ? ' ago' : '');
            }
            // One K-Means feature: its value and where it sits among all customers (the model's 0..1 scale).
            function meter(icon, label, value, scale, note) {
                var pct = scale == null ? null : Math.round(Math.max(0, Math.min(1, scale)) * 100);
                return '<div class="cba-meter"><div class="cba-meter-top"><span><i class="fas ' + icon + '"></i> ' + esc(label) + '</span><b>' + value + '</b></div>' +
                    (pct == null ? '' : '<div class="cba-bar" role="img" aria-label="' + esc(label) + ' ' + pct + '%"><span style="width:' + Math.max(pct, 3) + '%"></span></div>') +
                    '<small>' + esc(note) + '</small></div>';
            }
            function fact(label, value) { return '<div><dt>' + esc(label) + '</dt><dd>' + value + '</dd></div>'; }

            function render(d, error) {
                var s = d.stats || {}, r = d.rfm || {}, k = d.rfm_scale || {}, a = d.analysis, labels = d.engagement_labels || {};
                var initials = String(d.name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');

                // 1) K-Means: the decision
                var html = '<div class="cba-card">' +
                    '<p class="cba-kicker"><i class="fas fa-diagram-project"></i> Customer group &middot; decided by K-Means (machine learning)</p>' +
                    '<div class="cba-ml">' +
                        '<div class="cba-who"><div class="cba-avatar" aria-hidden="true">' + esc(initials) + '</div>' +
                            '<div><strong>' + esc(d.name) + '</strong></div>' +
                            '<span class="badge ' + esc(d.classification_class) + '">' + esc(d.classification) + '</span>' +
                            '<small>Grouped by the three purchase features on the right.</small></div>' +
                        '<div class="cba-meters">' +
                            // Recency: fewer days is better, so the bar shows how RECENT (full = bought most recently).
                            meter('fa-clock', 'Recency', days(r.recency_days, true), k.recency == null ? null : 1 - k.recency, 'Fuller bar = bought more recently') +
                            meter('fa-cart-shopping', 'Frequency', Number(r.frequency || 0).toLocaleString() + ' items', k.frequency, 'Items purchased, compared with all customers') +
                            meter('fa-coins', 'Monetary', Number(r.monetary_points || 0).toLocaleString('en-PH', { maximumFractionDigits: 2 }) + ' pts', k.monetary, 'Loyalty points (1 pt per ₱100 spent)') +
                        '</div>' +
                        '<dl class="cba-facts">' +
                            fact('Orders', num(s.orders) + ' <small style="font-weight:600;color:var(--dm-text-muted)">(' + num(s.orders_90d) + ' in 90 days)</small>') +
                            fact('Last order', days(s.days_since_last_order, true)) +
                            fact('Total spent', peso(s.spent)) +
                            fact('Avg. order', peso(s.aov)) +
                            fact('Points earned', num(s.points_earned, 2)) +
                            fact('Points used', num(s.points_redeemed, 2)) +
                        '</dl>' +
                    '</div>' +
                    (s.top_categories && s.top_categories.length ? '<div class="cba-chips" title="What this customer buys most">' +
                        s.top_categories.map(function (c) { return '<span><i class="fas fa-tag"></i> ' + esc(c) + '</span>'; }).join('') + '</div>' : '') +
                    '</div>';

                // 2) Gemini AI: the explanation
                html += '<div class="cba-card">';
                if (a) {
                    html += '<p class="cba-kicker"><i class="fas fa-wand-magic-sparkles"></i> Explanation &amp; recommendations &middot; by Gemini AI' +
                        '<span class="cba-tag cba-eng ' + esc(a.engagement_level) + '">' + esc(labels[a.engagement_level] || a.engagement_level) + '</span></p>' +
                        '<div class="cba-ai">' +
                        '<section class="k-behavior"><h5><i class="fas fa-magnifying-glass-chart"></i> Why this group</h5><p>' + esc(a.behavior_explanation) + '</p></section>' +
                        '<section class="k-engage"><h5><i class="fas fa-heart-pulse"></i> Engagement</h5><p>' + esc(a.engagement_analysis) + '</p></section>' +
                        '<section class="k-strategy"><h5><i class="fas fa-chess-knight"></i> Recommended strategy</h5><p>' + esc(a.recommended_strategy) + '</p></section>' +
                        '<section class="k-promo"><h5><i class="fas fa-gift"></i> Suggested promotion</h5><p>' + esc(a.suggested_promotion) + '</p></section>' +
                        '</div>' +
                        '<div class="cba-actions"><button type="button" class="cba-btn ghost" data-cba-run="1"><i class="fas fa-rotate"></i> Analyze again</button>' +
                        (d.generated_at ? '<span>Analyzed ' + esc(d.generated_at) + '</span>' : '') +
                        (d.stale ? '<span class="cba-stale"><i class="fas fa-circle-info"></i> This customer has bought since - analyze again for an update.</span>' : '') +
                        '</div>';
                } else {
                    html += '<p class="cba-kicker"><i class="fas fa-wand-magic-sparkles"></i> Explanation &amp; recommendations &middot; by Gemini AI</p>' +
                        '<div class="cba-empty-ai"><p>Ask Gemini AI to explain why this customer is <strong>' + esc(d.classification) + '</strong>, how engaged they are, and what the store can do to keep them.</p>' +
                        '<button type="button" class="cba-btn" data-cba-run="0"><i class="fas fa-wand-magic-sparkles"></i> Analyze buying behavior</button></div>';
                }
                if (error) html += '<p class="cba-err"><i class="fas fa-circle-exclamation"></i> ' + esc(error) + '</p>';
                html += '</div>';
                html += '<p class="cba-disclaimer"><i class="fas fa-shield-halved"></i> K-Means decides the group. Gemini AI only explains it and suggests ideas - it cannot change the group, points, vouchers or orders, and it receives purchase totals only (no name, email, phone or address).</p>';
                body.innerHTML = html;
            }

            function load(id) {
                current = id;
                body.innerHTML = '<p class="an-empty"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</p>';
                fetch(API + '?action=profile&customer_id=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (id !== current) return;
                        if (!j.success) { body.innerHTML = '<p class="cba-err">' + esc(j.message || 'Could not load this customer.') + '</p>'; return; }
                        render(j.data);
                    })
                    .catch(function () { if (id === current) body.innerHTML = '<p class="cba-err">Could not reach the server.</p>'; });
            }

            // Keep what is on screen (the K-Means group and any earlier analysis); just show why it failed.
            function failed(btn, message) {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Try again'; }
                var old = body.querySelector('.cba-err');
                if (old) old.remove();
                var host = body.querySelectorAll('.cba-card')[1] || body;
                host.insertAdjacentHTML('beforeend', '<p class="cba-err" style="margin-top:8px"><i class="fas fa-circle-exclamation"></i> ' + esc(message) + '</p>');
            }

            function analyze(force) {
                if (busy || !current) return; // one request at a time (no double clicks)
                busy = true;
                var id = current, btn = body.querySelector('[data-cba-run]');
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Asking Gemini AI&hellip;'; }
                var fd = new FormData();
                fd.append('action', 'analyze'); fd.append('customer_id', id); fd.append('force', force ? '1' : '0'); fd.append('csrf_token', CSRF);
                fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (id !== current) return;
                        if (j.success) render(j.data);
                        else failed(btn, j.message || 'Could not analyze this customer.');
                    })
                    .catch(function () { failed(btn, 'Could not reach the server. The customer groups are not affected.'); })
                    .then(function () { busy = false; });
            }

            pick.addEventListener('change', function () { if (pick.value) load(+pick.value); });
            body.addEventListener('click', function (e) {
                var b = e.target.closest('[data-cba-run]');
                if (b) analyze(b.getAttribute('data-cba-run') === '1');
            });
            document.addEventListener('click', function (e) {
                var b = e.target.closest('.cba-row-btn');
                if (!b || b.disabled) return;
                var id = +b.getAttribute('data-cba');
                pick.value = String(id);
                load(id);
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        })();

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