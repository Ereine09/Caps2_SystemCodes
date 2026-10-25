<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
// Force logout if they are trying to use a customer session in the staff area
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_role = strtolower(trim($payload['role'] ?? 'staff'));

// --- SECURITY GATE: ADMIN ONLY ---
if ($user_role !== 'admin' && !defined('STAFF_PORTAL_MODE')) { // modules/staff/dashboard.php shows this page in staff mode
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

$is_staff_view = defined('STAFF_PORTAL_MODE'); // staff: no Analytics / Sales Breakdown links

$total_staff = (int)($conn->query("SELECT COUNT(*) AS total FROM users")->fetch_assoc()['total'] ?? 0);
$total_customers = (int)($conn->query("SELECT COUNT(*) AS total FROM customers")->fetch_assoc()['total'] ?? 0);

$order_metrics = $conn->query(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders
     FROM tbl_orders"
)->fetch_assoc() ?: [];
$total_orders = (int)($order_metrics['total_orders'] ?? 0);
// Sales = money the shop has actually received (COD after its remittance is accepted,
// GCash/Bank after the payment is approved, Pay-at-shop when completed). See sales_helper.php.
require_once __DIR__ . '/../../app/helpers/sales_helper.php';
$sales = sales_summary($conn);
$total_sales = $sales['total_sales'];
// Sidebar badge only — deliberately a SEPARATE query from $order_metrics
// above (which still reports the true pending-order business metric,
// untouched). This one only counts pending orders an admin hasn't opened
// yet, so it clears on view independently of order status.
$pending_orders_count = (int)($conn->query(
    "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"
)->fetch_assoc()['count'] ?? 0);

$unremitted_cod = (float)($conn->query(
    "SELECT COALESCE(SUM(total), 0) AS total
     FROM tbl_orders
     WHERE rider_id IS NOT NULL
       AND order_status = 'completed'
       AND payment_method = 'cod'
       AND payment_settled = 0"
)->fetch_assoc()['total'] ?? 0);
$total_loyalty_points = (float)($conn->query(
    "SELECT COALESCE(SUM(loyalty_points), 0) AS total FROM customers"
)->fetch_assoc()['total'] ?? 0);
$total_gross_points = (float)($conn->query(
    "SELECT COALESCE(SUM(points_earned), 0) AS total FROM loyalty_transactions"
)->fetch_assoc()['total'] ?? 0);

// --- Product Insights (moved here from Analytics) ---
// Real order data for the last N days (7 / 30 / 90, default 30). Cancelled orders are
// excluded everywhere so this reflects actual completed / in-progress revenue.
$insight_days = (int) ($_GET['insights'] ?? 30);
if (!in_array($insight_days, [7, 30, 90], true)) {
    $insight_days = 30;
}
$insight_from = date('Y-m-d', strtotime('-' . ($insight_days - 1) . ' days'));
$insight_to = date('Y-m-d');
$insight_where = "DATE(o.created_at) BETWEEN '$insight_from' AND '$insight_to' AND o.order_status != 'cancelled'";

// Top Selling Products: grouped by product_id + the item's own variant_size text (not
// variant_id), because some historical order_items rows never had variant_id populated.
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

// Low Performing Products: every catalog product ranked by units sold ASCENDING (LEFT JOIN,
// so a product with zero sales in the window still shows up instead of being invisible).
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

// Sales trend: real order totals per day over the same window (zero-filled).
$trend = [];
for ($i = $insight_days - 1; $i >= 0; $i--) {
    $trend[date('Y-m-d', strtotime("-$i days"))] = 0.0;
}
// Dated by the day the money came in (same definition as Total Sales).
foreach (sales_per_day($conn, $insight_from, $insight_to) as $d => $amount) {
    if (isset($trend[$d])) { $trend[$d] = $amount; }
}
$insight_trend = [
    'labels' => array_map(fn($d) => date('M j', strtotime($d)), array_keys($trend)),
    'values' => array_values($trend),
];

// Peak ordering periods: hour-of-day and day-of-week with the most orders in the window.
$peak_hour_row = ($res = $conn->query("SELECT HOUR(o.created_at) AS hr, COUNT(*) AS c FROM tbl_orders o WHERE $insight_where GROUP BY hr ORDER BY c DESC LIMIT 1")) ? $res->fetch_assoc() : null;
$peak_day_row = ($res = $conn->query("SELECT DAYNAME(o.created_at) AS dn, COUNT(*) AS c FROM tbl_orders o WHERE $insight_where GROUP BY dn ORDER BY c DESC LIMIT 1")) ? $res->fetch_assoc() : null;

$username = $payload['username'];
$user_id = $payload['user_id'];
$unread_count = get_unread_count_staff($user_id);

// ---------------------------------------------------------------------------
// Dashboard display data (read-only). Same cancelled-order exclusion as the
// sales metrics above.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';

$today_metrics = $conn->query(
    "SELECT COUNT(*) AS orders_today FROM tbl_orders WHERE DATE(created_at) = CURDATE()"
)->fetch_assoc() ?: [];
$orders_today = (int) ($today_metrics['orders_today'] ?? 0);
$sales_today = $sales['sales_today'];
$pending_orders_total = (int) ($order_metrics['pending_orders'] ?? 0);

$new_customers_month = (int) ($conn->query(
    "SELECT COUNT(*) AS c FROM customers WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetch_assoc()['c'] ?? 0);
$active_customers_30d = (int) ($conn->query(
    "SELECT COUNT(DISTINCT customer_id) AS c FROM tbl_orders
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND order_status <> 'cancelled'"
)->fetch_assoc()['c'] ?? 0);

$recent_orders = [];
if ($res = $conn->query(
    "SELECT o.id, o.order_number, o.order_status, o.total, o.fulfillment_type, o.payment_method, o.created_at, c.name AS customer_name
     FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY o.created_at DESC, o.id DESC LIMIT 6"
)) {
    while ($row = $res->fetch_assoc()) { $recent_orders[] = $row; }
}

$top_members = [];
if ($res = $conn->query("SELECT id, name, loyalty_points FROM customers WHERE loyalty_points > 0 ORDER BY loyalty_points DESC LIMIT 4")) {
    while ($row = $res->fetch_assoc()) { $top_members[] = $row; }
}

// Stock bands from the same event-based stock_alert_level column Products uses.
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
$pending_remittances = get_pending_remittance_count($conn);

// "Needs attention" items — only shown when there is something to act on.
$attention = [];
if ($pending_orders_total > 0) {
    $attention[] = ['warning', 'fa-clock', $pending_orders_total . ' pending ' . ($pending_orders_total === 1 ? 'order' : 'orders'), 'Waiting for confirmation', 'orders.php?status=pending'];
}
if ($pending_remittances > 0) {
    $attention[] = ['info', 'fa-money-bill-wave', $pending_remittances . ' remittance' . ($pending_remittances === 1 ? '' : 's') . ' to review', 'Submitted by riders', 'remittance_management.php'];
}
if ($unremitted_cod > 0) {
    $attention[] = ['danger', 'fa-file-invoice-dollar', ui_peso($unremitted_cod) . ' unremitted COD', 'Collected by riders, not yet settled', 'remittance_management.php'];
}
if ((int) $unread_count > 0) {
    $attention[] = ['primary', 'fa-comment-dots', (int) $unread_count . ' unread ' . ((int) $unread_count === 1 ? 'message' : 'messages'), 'From customers', 'messages.php'];
}
$severity_meta = [3 => ['danger', 'Out of stock'], 2 => ['danger', 'Critical stock'], 1 => ['warning', 'Low stock']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
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
        .dash-ai { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .dash-ai p { margin: 2px 0 0; color: var(--muted); font-size: var(--fs-sm); max-width: 760px; }
        .dash-issued { font-size: var(--fs-base); color: var(--text-2); }
        .dash-issued strong { font-size: var(--fs-lg); font-weight: 600; color: var(--text); font-variant-numeric: tabular-nums; }
        .dash-member-pts { color: var(--gold); font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .dash-cust-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--border); border-bottom: 1px solid var(--border); }
        .dash-cust-stats > div { background: var(--surface); padding: 14px 18px; min-width: 0; }
        .dash-cust-stats span { display: block; font-size: var(--fs-xs); color: var(--muted); }
        .dash-cust-stats strong { font-size: var(--fs-lg); font-weight: 600; font-variant-numeric: tabular-nums; }
        @media (max-width: 480px) { .dash-cust-stats > div { padding: 12px 10px; } .dash-chart { height: 220px; } }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="ui-page-head">
            <div>
                <h1 class="ui-page-title">Dashboard</h1>
                <p class="ui-page-sub">Welcome back, <?php echo ui_e($username); ?> · <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="ui-page-actions">
                <a href="scan.php" class="ui-btn"><i class="fas fa-qrcode"></i> Scan QR</a>
                <a href="orders.php" class="ui-btn"><i class="fas fa-shopping-cart"></i> Orders</a>
                <?php if (!$is_staff_view): ?>
                <a href="analytics.php" class="ui-btn ui-btn-primary"><i class="fas fa-chart-line"></i> Analytics &amp; Reports</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="ui-kpis">
            <<?php echo $is_staff_view ? 'div' : 'a href="sales_breakdown.php?range=today"'; ?> class="ui-kpi tone-success" title="See where today's money came from">
                <span class="ui-kpi-icon"><i class="fas fa-peso-sign"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Today's Sales</span>
                    <span class="ui-kpi-value"><?php echo ui_peso($sales_today); ?></span>
                    <span class="ui-kpi-meta">All-time <?php echo ui_peso($total_sales); ?><?php if ($sales['uncollected'] > 0): ?> &middot; <?php echo ui_peso($sales['uncollected']); ?> not yet collected<?php endif; ?><?php if (!$is_staff_view): ?> &middot; View breakdown<?php endif; ?></span>
                </div>
            </<?php echo $is_staff_view ? 'div' : 'a'; ?>>
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
                    <span class="ui-kpi-value"><?php echo number_format($pending_orders_total); ?></span>
                    <span class="ui-kpi-meta">Awaiting confirmation</span>
                </div>
            </a>
            <a href="../customers/customers.php" class="ui-kpi tone-info">
                <span class="ui-kpi-icon"><i class="fas fa-user-friends"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Customers</span>
                    <span class="ui-kpi-value"><?php echo number_format($total_customers); ?></span>
                    <span class="ui-kpi-meta">+<?php echo number_format($new_customers_month); ?> this month</span>
                </div>
            </a>
            <a href="../customers/loyalty_points.php" class="ui-kpi tone-gold">
                <span class="ui-kpi-icon"><i class="fas fa-star"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Usable Loyalty Points</span>
                    <span class="ui-kpi-value"><?php echo number_format($total_loyalty_points, 2); ?></span>
                    <span class="ui-kpi-meta dash-issued"><strong><?php echo number_format($total_gross_points, 2); ?></strong> issued in total</span>
                </div>
            </a>
        </div>

        <div class="ui-tabs ui-tabs-fill" role="tablist">
            <button type="button" data-tab="overview">Overview</button>
            <button type="button" data-tab="orders">Orders &amp; Customers</button>
            <button type="button" data-tab="insights">Product Insights</button>
        </div>

        <div class="dash-tab-panel" id="dash-tab-overview">
        <div class="ui-grid-main" style="margin-bottom: 18px;">
            <!-- Sales overview -->
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
                <!-- Needs attention -->
                <section class="ui-card">
                    <div class="ui-card-head">
                        <h2 class="ui-card-title"><i class="fas fa-bell"></i> Needs Attention</h2>
                    </div>
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

                <!-- Stock alerts -->
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

        <?php if (!$is_staff_view): // Analytics is admin-only ?>
        <!-- AI engine pointer -->
        <section class="ui-card">
            <div class="ui-card-body dash-ai">
                <div class="ui-row" style="flex-wrap: nowrap; align-items: flex-start;">
                    <span class="ui-icon-tile primary"><i class="fas fa-wand-magic-sparkles"></i></span>
                    <div>
                        <h2 class="ui-card-title">AI Engine Processing</h2>
                        <p>The system automatically performs <strong>Heuristic Clustering</strong> and <strong>Predictive Classification</strong> using local datasets, then synthesizes business strategies via <strong>Google Gemini AI</strong>.</p>
                    </div>
                </div>
                <a href="analytics.php" class="ui-btn ui-btn-soft">View AI Analytics <i class="fas fa-arrow-right"></i></a>
            </div>
        </section>
        <?php endif; ?>
        </div><!-- /dash-tab-overview -->

        <div class="dash-tab-panel" id="dash-tab-orders" hidden>
        <div class="ui-grid-main" style="margin-bottom: 18px;">
            <!-- Recent orders -->
            <section class="ui-card">
                <div class="ui-card-head">
                    <h2 class="ui-card-title">Recent Orders</h2>
                    <a class="ui-card-link" href="orders.php">View all orders</a>
                </div>
                <?php if (empty($recent_orders)): ?>
                    <div class="ui-empty"><i class="fas fa-receipt"></i><strong>No orders yet</strong><p>New orders will appear here.</p></div>
                <?php else: ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table-stack">
                            <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($recent_orders as $ro): ?>
                                    <tr data-href="order_details.php?id=<?php echo (int) $ro['id']; ?>">
                                        <td data-label="Order">
                                            <span>
                                                <a class="ui-cell-main" href="order_details.php?id=<?php echo (int) $ro['id']; ?>" style="text-decoration: none;"><?php echo ui_e($ro['order_number']); ?></a>
                                                <span class="ui-cell-sub"><?php echo ui_e(date('M j, g:i A', strtotime($ro['created_at']))); ?> · <?php echo $ro['fulfillment_type'] === 'delivery' ? 'Delivery' : 'Pickup'; ?></span>
                                            </span>
                                        </td>
                                        <td data-label="Customer"><?php echo ui_e($ro['customer_name'] ?: 'Walk-in'); ?></td>
                                        <td data-label="Status"><?php echo ui_order_status_badge($ro['order_status']); ?></td>
                                        <td data-label="Total" class="num ui-strong"><?php echo ui_peso($ro['total']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Customer overview -->
            <section class="ui-card">
                <div class="ui-card-head">
                    <h2 class="ui-card-title">Customer Overview</h2>
                    <a class="ui-card-link" href="../customers/customers.php">Customers</a>
                </div>
                <div class="dash-cust-stats">
                    <div><span>Total</span><strong><?php echo number_format($total_customers); ?></strong></div>
                    <div><span>New this month</span><strong><?php echo number_format($new_customers_month); ?></strong></div>
                    <div><span>Active (30 days)</span><strong><?php echo number_format($active_customers_30d); ?></strong></div>
                </div>
                <div class="ui-card-body" style="padding-bottom: 0;">
                    <p class="ui-section-label" style="margin: 0;">Top loyalty members</p>
                </div>
                <?php if (empty($top_members)): ?>
                    <div class="ui-empty" style="padding-top: 12px;"><p>No points earned yet.</p></div>
                <?php else: ?>
                    <ul class="ui-list">
                        <?php foreach ($top_members as $m): ?>
                            <li><a class="ui-list-row" href="../customers/customer_details.php?id=<?php echo (int) $m['id']; ?>">
                                <span class="ui-avatar"><?php echo ui_e(ui_initials($m['name'])); ?></span>
                                <span class="ui-list-main"><span class="ui-list-title"><?php echo ui_e($m['name']); ?></span></span>
                                <span class="dash-member-pts"><?php echo number_format((float) $m['loyalty_points'], 2); ?> pts</span>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        </div><!-- /dash-tab-orders -->

        <div class="dash-tab-panel" id="dash-tab-insights" hidden>
        <div class="ui-grid-2" style="margin-bottom: 18px;">
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
        </div><!-- /dash-tab-insights -->
    </div>

    <script>
    (function () {
        // Tabs: the dashboard used to show every section stacked at once - now only one
        // group is visible at a time. The KPI row above stays visible in every tab.
        var tabButtons = document.querySelectorAll('.ui-tabs [data-tab]');
        var tabPanels = document.querySelectorAll('.dash-tab-panel');
        var validTabs = ['overview', 'orders', 'insights'];
        var STORAGE_KEY = 'adminDashboardActiveTab';
        var salesChart = null; // assigned once Chart.js below creates it

        function activateTab(tab, updateHistory) {
            if (validTabs.indexOf(tab) === -1) tab = 'overview';
            tabButtons.forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.tab === tab);
            });
            tabPanels.forEach(function (panel) {
                panel.hidden = panel.id !== 'dash-tab-' + tab;
            });
            try { localStorage.setItem(STORAGE_KEY, tab); } catch (e) {}
            if (updateHistory) history.replaceState(null, '', '#' + tab);
            // The sales chart can't size itself while its tab panel is hidden (display:none),
            // so re-measure it every time Overview becomes visible again.
            if (tab === 'overview' && salesChart) salesChart.resize();
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () { activateTab(btn.dataset.tab, true); });
        });

        var initialTab = (window.location.hash || '').replace('#', '');
        if (!initialTab) {
            try { initialTab = localStorage.getItem(STORAGE_KEY) || ''; } catch (e) { initialTab = ''; }
        }
        activateTab(initialTab || 'overview', false);

        // Whole-row click on Recent Orders (the order number stays a real link).
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
        // Chart colors come from the same CSS tokens as the rest of the UI.
        function token(name) { return getComputedStyle(document.body).getPropertyValue(name).trim(); }

        Chart.defaults.font.family = token('--font') || 'Poppins, sans-serif';
        var chart = salesChart = new Chart(canvas.getContext('2d'), {
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
<script src="<?php echo BASE_URL; ?>/assets/js/customer-popup.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/customer-popup.js') ?: time(); ?>"></script>
</body>
</html>
