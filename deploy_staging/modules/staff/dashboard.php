<?php
require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// 1. Load config  2. Load JWT helper  3. Verify JWT  4. Require role === 'staff'
$payload  = require_staff();
$username = $payload['username'] ?? 'Staff';
$user_id  = (int) $payload['user_id'];
$user_role = 'staff';

$total_customers      = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM customers"))['total'] ?? 0);
$total_loyalty_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(loyalty_points), 0) AS total FROM customers"))['total'] ?? 0);

$order_metrics = $conn->query(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS total_sales,
            COALESCE(SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders
     FROM tbl_orders"
)->fetch_assoc() ?: [];
$total_orders = (int) ($order_metrics['total_orders'] ?? 0);
$total_sales = (float) ($order_metrics['total_sales'] ?? 0);
$pending_orders_count = (int) ($order_metrics['pending_orders'] ?? 0);
$unread_messages_count = get_unread_count_staff($user_id);

// --- Product Insights (same read-only view as the Admin dashboard) ---
// Real order data for the last N days (7 / 30 / 90, default 30). Cancelled orders are
// excluded everywhere so this reflects actual completed / in-progress revenue.
$insight_days = (int) ($_GET['insights'] ?? 30);
if (!in_array($insight_days, [7, 30, 90], true)) {
    $insight_days = 30;
}
$insight_from = date('Y-m-d', strtotime('-' . ($insight_days - 1) . ' days'));
$insight_to = date('Y-m-d');
$insight_where = "DATE(o.created_at) BETWEEN '$insight_from' AND '$insight_to' AND o.order_status != 'cancelled'";

$top_products = [];
$res = $conn->query("
    SELECT oi.product_id, oi.product_name, oi.variant_size,
           SUM(oi.quantity) AS total_qty, SUM(oi.total_price) AS total_revenue
    FROM tbl_order_items oi
    JOIN tbl_orders o ON o.id = oi.order_id
    WHERE $insight_where
    GROUP BY oi.product_id, oi.variant_size
    ORDER BY total_qty DESC
    LIMIT 5
");
if ($res) {
    while ($row = $res->fetch_assoc()) { $top_products[] = $row; }
}

$low_products = [];
$res = $conn->query("
    SELECT p.id, p.name, COALESCE(SUM(oi.quantity), 0) AS total_qty
    FROM tbl_product_inventory p
    LEFT JOIN tbl_order_items oi ON oi.product_id = p.id
        AND oi.order_id IN (SELECT id FROM tbl_orders o WHERE $insight_where)
    GROUP BY p.id
    ORDER BY total_qty ASC, p.name ASC
    LIMIT 5
");
if ($res) {
    while ($row = $res->fetch_assoc()) { $low_products[] = $row; }
}

$trend = [];
for ($i = $insight_days - 1; $i >= 0; $i--) {
    $trend[date('Y-m-d', strtotime("-$i days"))] = 0.0;
}
$res = $conn->query("
    SELECT DATE(o.created_at) AS d, COALESCE(SUM(o.total), 0) AS sales
    FROM tbl_orders o
    WHERE $insight_where
    GROUP BY d
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (isset($trend[$row['d']])) { $trend[$row['d']] = (float) $row['sales']; }
    }
}
$insight_trend = [
    'labels' => array_map(fn($d) => date('M j', strtotime($d)), array_keys($trend)),
    'values' => array_values($trend),
];
$peak_hour_row = ($res = $conn->query("SELECT HOUR(o.created_at) AS hr, COUNT(*) AS c FROM tbl_orders o WHERE $insight_where GROUP BY hr ORDER BY c DESC LIMIT 1")) ? $res->fetch_assoc() : null;
$peak_day_row = ($res = $conn->query("SELECT DAYNAME(o.created_at) AS dn, COUNT(*) AS c FROM tbl_orders o WHERE $insight_where GROUP BY dn ORDER BY c DESC LIMIT 1")) ? $res->fetch_assoc() : null;

// ---------------------------------------------------------------------------
// Dashboard display data (read-only).
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';

$today_metrics = $conn->query(
    "SELECT COUNT(*) AS orders_today,
            COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS sales_today
     FROM tbl_orders WHERE DATE(created_at) = CURDATE()"
)->fetch_assoc() ?: [];
$orders_today = (int) ($today_metrics['orders_today'] ?? 0);
$sales_today = (float) ($today_metrics['sales_today'] ?? 0);

$recent_orders = [];
if ($res = $conn->query(
    "SELECT o.id, o.order_number, o.order_status, o.total, o.fulfillment_type, o.created_at, c.name AS customer_name
     FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY o.created_at DESC, o.id DESC LIMIT 6"
)) {
    while ($row = $res->fetch_assoc()) { $recent_orders[] = $row; }
}

$stock_alerts = [];
if ($res = $conn->query(
    "SELECT p.id, p.name,
            MAX(CASE WHEN p.stock_alert_level = 'out' OR v.stock_alert_level = 'out' THEN 3
                     WHEN p.stock_alert_level = 'critical' OR v.stock_alert_level = 'critical' THEN 2
                     WHEN p.stock_alert_level = 'low' OR v.stock_alert_level = 'low' THEN 1 ELSE 0 END) AS severity
     FROM tbl_product_inventory p LEFT JOIN tbl_product_variants v ON v.product_id = p.id
     GROUP BY p.id, p.name HAVING severity > 0
     ORDER BY severity DESC, p.name ASC LIMIT 5"
)) {
    while ($row = $res->fetch_assoc()) { $stock_alerts[] = $row; }
}
$severity_meta = [3 => ['danger', 'Out of stock'], 2 => ['danger', 'Critical stock'], 1 => ['warning', 'Low stock']];
$pending_remittances = get_pending_remittance_count($conn);

$attention = [];
if ($pending_orders_count > 0) {
    $attention[] = ['warning', 'fa-clock', $pending_orders_count . ' pending ' . ($pending_orders_count === 1 ? 'order' : 'orders'), 'Waiting for confirmation', 'orders.php?status=pending'];
}
if ((int) $unread_messages_count > 0) {
    $attention[] = ['primary', 'fa-comment-dots', (int) $unread_messages_count . ' unread ' . ((int) $unread_messages_count === 1 ? 'message' : 'messages'), 'From customers', 'messages.php'];
}
if ($pending_remittances > 0) {
    $attention[] = ['info', 'fa-money-bill-wave', $pending_remittances . ' remittance' . ($pending_remittances === 1 ? '' : 's') . ' to review', 'Submitted by riders', 'remittance_management.php'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - DPS Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        .dash-chart { position: relative; height: 280px; }
        .dash-peaks { display: flex; gap: 10px 28px; flex-wrap: wrap; margin-bottom: 16px; }
        .dash-peak { display: flex; align-items: center; gap: 10px; }
        .dash-peak strong { display: block; font-weight: 600; color: var(--text); }
        .dash-ok { display: flex; align-items: center; gap: 10px; margin: 0; color: var(--success); font-weight: 500; }
        @media (max-width: 480px) { .dash-chart { height: 220px; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="ui-page-head">
            <div>
                <h1 class="ui-page-title">Dashboard</h1>
                <p class="ui-page-sub">Welcome back, <?php echo ui_e($username); ?> · <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="ui-page-actions">
                <a href="messages.php" class="ui-btn"><i class="fas fa-comment-dots"></i> Messages</a>
                <a href="orders.php" class="ui-btn ui-btn-primary"><i class="fas fa-shopping-cart"></i> Process Orders</a>
            </div>
        </div>

        <div class="ui-kpis">
            <div class="ui-kpi tone-success">
                <span class="ui-kpi-icon"><i class="fas fa-sack-dollar"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Today's Sales</span>
                    <span class="ui-kpi-value"><?php echo ui_peso($sales_today); ?></span>
                    <span class="ui-kpi-meta">All-time <?php echo ui_peso($total_sales); ?></span>
                </div>
            </div>
            <a href="orders.php" class="ui-kpi">
                <span class="ui-kpi-icon"><i class="fas fa-receipt"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Orders</span>
                    <span class="ui-kpi-value"><?php echo number_format($total_orders); ?></span>
                    <span class="ui-kpi-meta"><?php echo number_format($orders_today); ?> placed today</span>
                </div>
            </a>
            <a href="orders.php?status=pending" class="ui-kpi tone-warning">
                <span class="ui-kpi-icon"><i class="fas fa-clock"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Pending Orders</span>
                    <span class="ui-kpi-value"><?php echo number_format($pending_orders_count); ?></span>
                    <span class="ui-kpi-meta">Awaiting confirmation</span>
                </div>
            </a>
            <a href="customers.php" class="ui-kpi tone-info">
                <span class="ui-kpi-icon"><i class="fas fa-user-friends"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Customers</span>
                    <span class="ui-kpi-value"><?php echo number_format($total_customers); ?></span>
                    <span class="ui-kpi-meta">Registered accounts</span>
                </div>
            </a>
            <a href="loyalty_points.php" class="ui-kpi tone-gold">
                <span class="ui-kpi-icon"><i class="fas fa-star"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Usable Loyalty Points</span>
                    <span class="ui-kpi-value"><?php echo number_format($total_loyalty_points, 2); ?></span>
                    <span class="ui-kpi-meta">Across all customers</span>
                </div>
            </a>
        </div>

        <div class="ui-grid-main" style="margin-bottom: 18px;">
            <section class="ui-card" id="product-insights">
                <div class="ui-card-head">
                    <div>
                        <h2 class="ui-card-title">Sales Overview</h2>
                        <p class="ui-card-sub"><?php echo ui_e(date('M j', strtotime($insight_from))); ?> – <?php echo ui_e(date('M j, Y', strtotime($insight_to))); ?> · excludes cancelled orders</p>
                    </div>
                    <div class="ui-segmented" role="group" aria-label="Sales period">
                        <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $label): ?>
                            <a href="?insights=<?php echo $d; ?>#product-insights" class="<?php echo $insight_days === $d ? 'active' : ''; ?>" <?php echo $insight_days === $d ? 'aria-current="true"' : ''; ?>><?php echo $label; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ui-card-body">
                    <div class="dash-peaks">
                        <div class="dash-peak">
                            <span class="ui-icon-tile primary"><i class="fas fa-clock"></i></span>
                            <div><span class="ui-xs ui-muted">Peak order hour</span><strong><?php echo $peak_hour_row ? date('g A', mktime((int) $peak_hour_row['hr'], 0)) . ' – ' . date('g A', mktime(((int) $peak_hour_row['hr'] + 1) % 24, 0)) : 'No orders in this period'; ?></strong></div>
                        </div>
                        <div class="dash-peak">
                            <span class="ui-icon-tile primary"><i class="fas fa-calendar-day"></i></span>
                            <div><span class="ui-xs ui-muted">Peak order day</span><strong><?php echo $peak_day_row ? ui_e($peak_day_row['dn']) : 'No orders in this period'; ?></strong></div>
                        </div>
                    </div>
                    <div class="dash-chart"><canvas id="salesTrendChart" role="img" aria-label="Bar chart of daily sales"></canvas></div>
                </div>
            </section>

            <div class="ui-stack">
                <section class="ui-card">
                    <div class="ui-card-head"><h2 class="ui-card-title"><i class="fas fa-bell"></i> Needs Attention</h2></div>
                    <?php if (empty($attention)): ?>
                        <div class="ui-card-body"><p class="dash-ok"><i class="fas fa-circle-check"></i> You're all caught up.</p></div>
                    <?php else: ?>
                        <ul class="ui-list">
                            <?php foreach ($attention as [$tone, $icon, $title, $sub, $href]): ?>
                                <li><a class="ui-list-row" href="<?php echo ui_e($href); ?>">
                                    <span class="ui-icon-tile <?php echo $tone; ?>"><i class="fas <?php echo $icon; ?>"></i></span>
                                    <span class="ui-list-main"><span class="ui-list-title"><?php echo ui_e($title); ?></span><span class="ui-list-sub"><?php echo ui_e($sub); ?></span></span>
                                    <i class="fas fa-chevron-right ui-muted ui-xs"></i>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="ui-card">
                    <div class="ui-card-head">
                        <h2 class="ui-card-title"><i class="fas fa-boxes-stacked"></i> Stock Alerts</h2>
                        <a class="ui-card-link" href="products.php">Products</a>
                    </div>
                    <?php if (empty($stock_alerts)): ?>
                        <div class="ui-card-body"><p class="dash-ok"><i class="fas fa-circle-check"></i> All products are above their low-stock level.</p></div>
                    <?php else: ?>
                        <ul class="ui-list">
                            <?php foreach ($stock_alerts as $sa): [$tone, $lbl] = $severity_meta[(int) $sa['severity']]; ?>
                                <li>
                                    <span class="ui-list-main"><span class="ui-list-title"><?php echo ui_e($sa['name']); ?></span></span>
                                    <span class="ui-badge ui-badge-<?php echo $tone; ?>"><?php echo $lbl; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <section class="ui-card" style="margin-bottom: 18px;">
            <div class="ui-card-head">
                <h2 class="ui-card-title">Recent Orders</h2>
                <a class="ui-card-link" href="orders.php">View all orders</a>
            </div>
            <?php if (empty($recent_orders)): ?>
                <div class="ui-empty"><i class="fas fa-receipt"></i><strong>No orders yet</strong><p>New orders will appear here.</p></div>
            <?php else: ?>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-stack">
                        <thead><tr><th>Order</th><th>Customer</th><th>Fulfillment</th><th>Status</th><th class="num">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_orders as $ro): ?>
                                <tr data-href="order_details.php?id=<?php echo (int) $ro['id']; ?>">
                                    <td data-label="Order">
                                        <span>
                                            <a class="ui-cell-main" href="order_details.php?id=<?php echo (int) $ro['id']; ?>" style="text-decoration: none;"><?php echo ui_e($ro['order_number']); ?></a>
                                            <span class="ui-cell-sub"><?php echo ui_e(date('M j, g:i A', strtotime($ro['created_at']))); ?></span>
                                        </span>
                                    </td>
                                    <td data-label="Customer"><?php echo ui_e($ro['customer_name'] ?: 'Walk-in'); ?></td>
                                    <td data-label="Fulfillment"><?php echo $ro['fulfillment_type'] === 'delivery' ? 'Delivery' : 'Pickup'; ?></td>
                                    <td data-label="Status"><?php echo ui_order_status_badge($ro['order_status']); ?></td>
                                    <td data-label="Total" class="num ui-strong"><?php echo ui_peso($ro['total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <div class="ui-grid-2">
            <section class="ui-card">
                <div class="ui-card-head"><h2 class="ui-card-title"><i class="fas fa-arrow-trend-up"></i> Top Selling</h2><span class="ui-xs ui-muted">Last <?php echo $insight_days; ?> days</span></div>
                <?php if (empty($top_products)): ?>
                    <div class="ui-empty"><p>No sales in this period yet.</p></div>
                <?php else: ?>
                    <ul class="ui-list">
                        <?php foreach ($top_products as $tp): ?>
                            <li>
                                <span class="ui-list-main"><span class="ui-list-title" title="<?php echo ui_e($tp['product_name']); ?>"><?php echo ui_e($tp['product_name']); ?></span><span class="ui-list-sub"><?php echo ui_peso($tp['total_revenue']); ?></span></span>
                                <span class="ui-list-end"><strong class="ui-num"><?php echo (int) $tp['total_qty']; ?></strong> <span class="ui-xs ui-muted">sold</span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <section class="ui-card">
                <div class="ui-card-head"><h2 class="ui-card-title"><i class="fas fa-arrow-trend-down"></i> Low Performing</h2><span class="ui-xs ui-muted">Last <?php echo $insight_days; ?> days</span></div>
                <?php if (empty($low_products)): ?>
                    <div class="ui-empty"><p>No products yet.</p></div>
                <?php else: ?>
                    <ul class="ui-list">
                        <?php foreach ($low_products as $lp): ?>
                            <li>
                                <span class="ui-list-main"><span class="ui-list-title" title="<?php echo ui_e($lp['name']); ?>"><?php echo ui_e($lp['name']); ?></span></span>
                                <span class="ui-list-end"><strong class="ui-num"<?php echo (int) $lp['total_qty'] === 0 ? ' style="color: var(--warning);"' : ''; ?>><?php echo (int) $lp['total_qty']; ?></strong> <span class="ui-xs ui-muted">sold</span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
    (function () {
        document.querySelectorAll('tr[data-href]').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button')) return;
                window.location = tr.getAttribute('data-href');
            });
        });

        var TREND = <?php echo json_encode($insight_trend); ?>;
        var canvas = document.getElementById('salesTrendChart');
        if (!canvas || typeof Chart === 'undefined') return;

        function peso(n) { return 'PHP ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function short(n) { return n >= 1e6 ? (n / 1e6) + 'M' : n >= 1e3 ? (n / 1e3) + 'k' : n; }
        function token(name) { return getComputedStyle(document.body).getPropertyValue(name).trim(); }

        Chart.defaults.font.family = token('--font') || 'Poppins, sans-serif';
        var chart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels: TREND.labels, datasets: [{ label: 'Sales (PHP)', data: TREND.values, borderRadius: 4, maxBarThickness: 28 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return peso(c.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                    y: { beginAtZero: true, border: { display: false }, ticks: { callback: short, maxTicksLimit: 6 } }
                }
            }
        });

        function render() {
            chart.data.datasets[0].backgroundColor = token('--primary');
            chart.data.datasets[0].hoverBackgroundColor = token('--primary-hover');
            chart.options.scales.x.ticks.color = token('--muted');
            chart.options.scales.y.ticks.color = token('--muted');
            chart.options.scales.y.grid.color = token('--border');
            chart.update();
        }
        document.addEventListener('dps:theme-change', render);
        render();
    })();
    </script>
</body>
</html>
