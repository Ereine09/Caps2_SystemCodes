<?php
/**
 * Admin — Sales Breakdown.
 *
 * Drill-down behind every "money collected" figure (dashboard Today's Sales, Analytics Total Sales):
 * shows where the collected money came from — payment method, online vs. staff-created orders,
 * what the money paid for (products, VAT, delivery fees, tips, minus discounts), top products,
 * the rider remittances that brought in COD cash, and the individual orders.
 *
 * Uses the same "collected" definition and paid_at dating as sales_helper.php, so the total here
 * always matches the figure that linked to it.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/validation_helper.php';
require_once __DIR__ . '/../../app/helpers/sales_helper.php';
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}
if (strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header('Location: ' . BASE_URL . '/modules/staff/dashboard.php');
    exit();
}
$user_id = (int) ($payload['user_id'] ?? 0);
// ?print=1: the printable report for the same period - every section, every row, then the print dialog.
$print_mode = isset($_GET['print']);

// ---- Period: ?range=today|7|30|all, or a custom ?from=&to= (Y-m-d) -------------------------
$ranges = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All time'];
$range = (string) ($_GET['range'] ?? '');
$from = $to = null;
if (!empty($_GET['from']) || !empty($_GET['to'])) {
    $range = 'custom';
    $from = clean_date($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
    $to = clean_date($_GET['to'] ?? '', date('Y-m-d'));
    // Future dates can't be picked (the inputs have max = today); a typed/URL one is treated as today.
    $from = min($from, date('Y-m-d'));
    $to = min($to, date('Y-m-d'));
    if ($from > $to) { [$from, $to] = [$to, $from]; }
} else {
    if (!isset($ranges[$range])) { $range = 'today'; }
    if ($range === 'today') { $from = $to = date('Y-m-d'); }
    elseif ($range !== 'all') { $from = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days')); $to = date('Y-m-d'); }
}
$period_label = $range === 'all' ? 'All time'
    : ($from === $to ? date('M j, Y', strtotime($from)) : date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)));

sales_ensure_paid_at($conn);
$where = sales_collected_sql('o');
if ($from !== null) {
    $where .= " AND DATE(o.paid_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
}
$rows = static fn(string $sql): array => ($r = $conn->query($sql)) ? $r->fetch_all(MYSQLI_ASSOC) : [];

// ---- Totals and what the money paid for ----------------------------------------------------
$t = $rows("SELECT COUNT(*) AS orders, COALESCE(SUM(o.total),0) AS total, COALESCE(SUM(o.subtotal),0) AS products,
                   COALESCE(SUM(o.vat_amount),0) AS vat, COALESCE(SUM(o.delivery_fee),0) AS delivery,
                   COALESCE(SUM(o.tip),0) AS tip, COALESCE(SUM(o.discount_amount),0) AS discount
            FROM tbl_orders o WHERE $where")[0];
$total = (float) $t['total'];
$order_count = (int) $t['orders'];
$share = static fn(float $amount): string => $total > 0 ? number_format($amount / $total * 100, 1) . '%' : '—';

$components = [
    ['Products sold', 'fa-box', (float) $t['products'], 'Item prices before VAT'],
    ['VAT (12%)', 'fa-file-invoice', (float) $t['vat'], 'Tax collected on the products'],
    ['Delivery fees', 'fa-truck', (float) $t['delivery'], 'Charged on delivery orders'],
    ['Tips', 'fa-hand-holding-heart', (float) $t['tip'], 'Added by customers for riders'],
];
$components[] = ['Vouchers & discounts', 'fa-ticket', -(float) $t['discount'], 'Taken off the order totals'];
$components = array_filter($components, fn($c) => abs($c[2]) >= 0.005); // hide parts with nothing in this period

// ---- By payment method ---------------------------------------------------------------------
$method_notes = [
    'cod' => 'Cash collected by riders, counted once the shop accepts their remittance',
    'gcash' => 'Counted once the GCash payment is verified',
    'bank' => 'Counted once the bank transfer is verified',
    'pay_at_shop' => 'Paid at the counter on pickup',
];
$by_method = $rows("SELECT o.payment_method AS k, COUNT(*) AS n, SUM(o.total) AS s FROM tbl_orders o WHERE $where GROUP BY k ORDER BY s DESC");

// ---- By channel (who placed it, how it was fulfilled) --------------------------------------
require_once __DIR__ . '/../../app/helpers/order_channel_helper.php';
order_channel_ensure_schema($conn);
$by_channel = $rows("SELECT o.order_source AS src, o.order_channel AS ch, o.fulfillment_type AS ful, COUNT(*) AS n, SUM(o.total) AS s
                     FROM tbl_orders o WHERE $where GROUP BY src, ch, ful ORDER BY s DESC");
$channel_label = static fn(array $r): string =>
    ($r['src'] === 'admin' || !empty($r['ch']) ? order_channel_label($r['ch'] ?? null, $r['src']) : 'Online (customer)') . ' · ' . ($r['ful'] === 'delivery' ? 'Delivery' : 'Pickup');

// ---- Top products (item revenue, before VAT), ranked, 10 per page (?ppage=) ---------------
$top_products_all = $rows("SELECT oi.product_name AS name, SUM(oi.quantity) AS qty, SUM(oi.total_price) AS s
                       FROM tbl_order_items oi JOIN tbl_orders o ON o.id = oi.order_id
                       WHERE $where GROUP BY oi.product_name ORDER BY s DESC, name ASC");
$prod_pg = ui_paginate(count($top_products_all), $print_mode ? 1 : (int) ($_GET['ppage'] ?? 1), $print_mode ? max(1, count($top_products_all)) : 10);
$top_products = array_slice($top_products_all, $prod_pg['offset'], $prod_pg['per_page']);

// ---- COD cash: which rider remittances brought it in ---------------------------------------
$remittances = $rows("SELECT rr.id, rr.transaction_id, rr.remittance_type, COALESCE(rr.received_at, rr.processed_at, rr.requested_at) AS at,
                             TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_full, u.username AS rider_user,
                             COUNT(*) AS n, SUM(o.total) AS s
                      FROM tbl_orders o
                      JOIN tbl_rider_remittance_items i ON i.order_id = o.id
                      JOIN tbl_rider_remittances rr ON rr.id = i.remittance_id AND rr.status = 'approved'
                      JOIN users u ON u.id = rr.rider_id
                      WHERE $where AND o.payment_method = 'cod'
                      GROUP BY rr.id ORDER BY at DESC");
$cod_pg = ui_paginate(count($remittances), $print_mode ? 1 : (int) ($_GET['cpage'] ?? 1), $print_mode ? max(1, count($remittances)) : 10); // 10 per page (?cpage=)
$remittances_page = array_slice($remittances, $cod_pg['offset'], $cod_pg['per_page']);

// ---- The orders themselves (paginated) -----------------------------------------------------
$pg = ui_paginate($order_count, $print_mode ? 1 : (int) ($_GET['page'] ?? 1), $print_mode ? max(1, $order_count) : 15);
$orders = $rows("SELECT o.id, o.order_number, o.paid_at, o.payment_method, o.order_source, o.order_channel, o.fulfillment_type, o.total,
                        c.name AS customer
                 FROM tbl_orders o LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE $where ORDER BY o.paid_at DESC, o.id DESC
                 LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");

// Not-yet-collected money for the same period (by order date), so the gap is visible too.
$uncollected_where = sales_uncollected_sql('o') . ($from !== null
    ? " AND DATE(o.created_at) BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'" : '');
$uncollected = $rows("SELECT COUNT(*) AS n, COALESCE(SUM(o.total),0) AS s FROM tbl_orders o WHERE $uncollected_where")[0];

// Tab shown before the script runs (the script then follows the URL #hash): paging means Paid Orders.
$initial_tab = isset($_GET['page']) ? 'orders' : (isset($_GET['ppage']) ? 'products' : (isset($_GET['cpage']) && $remittances ? 'cod' : 'method'));
$panel_hidden = static fn(string $tab): string => ($print_mode || $tab === $initial_tab) ? '' : ' hidden';
// Same period, as the printable report (range / from / to only).
$print_url = 'sales_breakdown.php?' . http_build_query(array_filter([
    'range' => $range !== 'custom' ? $range : null, 'from' => $range === 'custom' ? $from : null, 'to' => $range === 'custom' ? $to : null, 'print' => 1,
]));

// "Showing X-Y of N" + page links for a tab's own ?Xpage= (links end in that tab's #hash).
$sb_pager = static function (array $pg, string $param, string $tab, string $noun): string {
    return '<div class="ui-card-foot ui-pager-bar"><span class="ui-pager-count">Showing ' . $pg['from'] . '–' . $pg['to'] . ' of ' . number_format($pg['total']) . ' ' . $noun . ($pg['total'] === 1 ? '' : 's') . '</span>'
        . ($pg['pages'] > 1 ? ui_pagination($pg['page'], $pg['pages'], fn($n) => ui_page_url([$param => $n], $tab)) : '') . '</div>';
};

require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
$unread_count = get_unread_count_staff($user_id); // sidebar Messages badge
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Breakdown - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .sb-filter { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: flex-end; justify-content: space-between; margin-bottom: 18px; }
        .sb-custom { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
        .sb-custom label { display: grid; gap: 4px; font-size: var(--fs-xs); color: var(--muted); }
        .sb-custom input { padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); }
        .sb-bar { height: 6px; border-radius: 999px; background: var(--surface-3); overflow: hidden; margin-top: 6px; min-width: 80px; }
        .sb-bar > span { display: block; height: 100%; background: var(--primary); border-radius: inherit; }
        .sb-note { display: block; font-size: var(--fs-xs); color: var(--muted); margin-top: 2px; }
        .sb-total td { font-weight: 700; border-top: 2px solid var(--border-strong); }
        .sb-neg { color: var(--danger); }
        .sb-tabs { margin-top: 18px; }
        .sb-tabs button { white-space: nowrap; }
        .sb-panel[hidden] { display: none !important; }
        .sb-rank { color: var(--muted); font-weight: 700; }
        .sb-print-head { display: none; }
        .sb-print-menu { position: relative; }
        .sb-print-menu > summary { list-style: none; cursor: pointer; }
        .sb-print-menu > summary::-webkit-details-marker { display: none; }
        .sb-print-pop { position: absolute; right: 0; top: calc(100% + 6px); z-index: 50; width: 270px; padding: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-lg, 0 12px 30px rgba(0,0,0,.15)); }
        .sb-print-pop > a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; color: var(--text); text-decoration: none; font-size: var(--fs-sm); }
        .sb-print-pop > a:hover { background: var(--surface-2); }
        .sb-print-pop > a i { width: 16px; color: var(--muted); }
        .sb-print-pop small { display: block; color: var(--muted); font-size: var(--fs-xs); }
        .sb-print-current { border-bottom: 1px solid var(--border); border-radius: 8px 8px 0 0 !important; margin-bottom: 4px; }
        .sb-print-custom { display: grid; gap: 6px; padding: 10px; margin-top: 4px; border-top: 1px solid var(--border); }
        .sb-print-label { font-size: var(--fs-xs); font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .sb-print-custom label { display: grid; gap: 3px; font-size: var(--fs-xs); color: var(--muted); }
        .sb-print-custom input { padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); }

        /* Printable report (?print=1): no sidebar, filters, tabs or page links; every section stacked */
        body.sb-print .app-sidebar, body.sb-print .customer-sidebar, body.sb-print .mobile-topbar, body.sb-print .admin-sidebar,
        body.sb-print .sb-filter, body.sb-print .sb-tabs, body.sb-print .ui-page-head, body.sb-print .ui-pager-bar,
        body.sb-print .ui-card-head .ui-btn { display: none !important; }
        body.sb-print { display: block !important; background: var(--bg, #fff); }
        body.sb-print .main-content { display: block !important; margin: 0 auto !important; padding: 24px !important; width: 100% !important; max-width: 1000px !important; box-sizing: border-box; min-height: 0 !important; }
        body.sb-print .ui-kpis { display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)) !important; gap: 12px; }
        body.sb-print .sb-print-head { display: block; text-align: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #222; }
        body.sb-print .sb-print-head h1 { margin: 0; font-size: 1.4rem; }
        body.sb-print .sb-print-head h2 { margin: 4px 0; font-size: 1.05rem; font-weight: 600; }
        body.sb-print .sb-print-head p { margin: 2px 0; font-size: .85rem; color: #444; }
        body.sb-print .sb-panel { margin-top: 16px; break-inside: avoid-page; }
        body.sb-print .sb-print-bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 8px; justify-content: flex-end; padding: 10px 0; background: var(--bg, #fff); }
        body.sb-print .sb-sign { margin-top: 48px; display: flex; justify-content: space-between; gap: 60px; font-size: .85rem; break-inside: avoid; }
        body.sb-print .sb-sign span { flex: 1; border-top: 1px solid #222; padding-top: 6px; text-align: center; }
        @media print {
            @page { size: A4; margin: 0; } /* no page margin = the browser prints no URL / date / page-number header and footer */
            html, body { background: #fff !important; color: #000 !important; }
            body.sb-print .sb-print-bar { display: none !important; }
            body.sb-print .main-content { padding: 12mm !important; max-width: none !important; }
            body.sb-print .ui-card, body.sb-print .ui-kpi { box-shadow: none !important; border: 1px solid #bbb !important; background: #fff !important; }
            body.sb-print a { color: #000 !important; text-decoration: none !important; }
            body.sb-print .sb-bar > span { background: #555 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body<?php echo $print_mode ? ' class="sb-print"' : ''; ?>>
    <?php if (!$print_mode) include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <?php if ($print_mode): ?>
            <div class="sb-print-bar">
                <a href="<?php echo ui_e(str_replace(['&print=1', '?print=1&', '?print=1'], ['', '?', ''], $print_url)); ?>" class="ui-btn ui-btn-sm"><i class="fas fa-arrow-left"></i> Back to Sales Breakdown</a>
                <button type="button" class="ui-btn ui-btn-sm ui-btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
            <div class="sb-print-head">
                <h1><?php echo ui_e(SYSTEM_NAME); ?></h1>
                <h2>Sales Report · <?php echo ui_e($period_label); ?></h2>
                <p>Money collected in this period, dated by the day the payment came in.</p>
                <p>Generated <?php echo date('F j, Y g:i A'); ?> by <?php echo ui_e($payload['username'] ?? 'Admin'); ?></p>
            </div>
        <?php endif; ?>
        <div class="ui-page-head">
            <div>
                <a href="javascript:history.back()" class="ui-back"><i class="fas fa-arrow-left"></i> Back</a>
                <h1 class="ui-page-title">Sales Breakdown</h1>
                <p class="ui-page-sub">Where the collected money came from · <?php echo ui_e($period_label); ?></p>
            </div>
            <!-- Print Report: the current selection, a preset period, or any From/To dates -->
            <details class="sb-print-menu">
                <summary class="ui-btn ui-btn-primary"><i class="fas fa-print"></i> Print Report <i class="fas fa-chevron-down" style="font-size: .75em;"></i></summary>
                <div class="sb-print-pop" role="menu">
                    <a role="menuitem" href="<?php echo ui_e($print_url); ?>" target="_blank" rel="noopener" class="sb-print-current">
                        <i class="fas fa-filter"></i><span>Current selection<small><?php echo ui_e($period_label); ?></small></span>
                    </a>
                    <?php foreach (['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All time'] as $pk => $pl): ?>
                        <a role="menuitem" href="sales_breakdown.php?range=<?php echo $pk; ?>&amp;print=1" target="_blank" rel="noopener"><i class="fas fa-calendar-day"></i><span><?php echo $pl; ?></span></a>
                    <?php endforeach; ?>
                    <form method="GET" action="sales_breakdown.php" target="_blank" class="sb-print-custom">
                        <input type="hidden" name="print" value="1">
                        <span class="sb-print-label">Specific dates</span>
                        <label>From <input type="date" name="from" value="<?php echo ui_e($from ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" required></label>
                        <label>To <input type="date" name="to" value="<?php echo ui_e($to ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" required></label>
                        <button type="submit" class="ui-btn ui-btn-sm ui-btn-primary"><i class="fas fa-print"></i> Print these dates</button>
                    </form>
                </div>
            </details>
        </div>

        <div class="sb-filter">
            <div class="ui-segmented" role="group" aria-label="Period">
                <?php foreach ($ranges as $key => $label): ?>
                    <a href="?range=<?php echo $key; ?>" class="<?php echo $range === (string) $key ? 'active' : ''; ?>" <?php echo $range === (string) $key ? 'aria-current="true"' : ''; ?>><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="sb-custom">
                <label>From <input type="date" name="from" value="<?php echo ui_e($from ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required></label>
                <label>To <input type="date" name="to" value="<?php echo ui_e($to ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required></label>
                <button type="submit" class="ui-btn ui-btn-sm<?php echo $range === 'custom' ? ' ui-btn-primary' : ''; ?>"><i class="fas fa-filter"></i> Apply</button>
            </form>
        </div>

        <div class="ui-kpis">
            <div class="ui-kpi tone-success">
                <span class="ui-kpi-icon"><i class="fas fa-peso-sign"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Money Collected</span>
                    <span class="ui-kpi-value"><?php echo ui_peso($total); ?></span>
                    <span class="ui-kpi-meta">Dated by the day the money came in</span>
                </div>
            </div>
            <div class="ui-kpi">
                <span class="ui-kpi-icon"><i class="fas fa-receipt"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Paid Orders</span>
                    <span class="ui-kpi-value"><?php echo number_format($order_count); ?></span>
                    <span class="ui-kpi-meta">Average <?php echo ui_peso($order_count ? $total / $order_count : 0); ?> per order</span>
                </div>
            </div>
            <a href="orders.php" class="ui-kpi tone-warning">
                <span class="ui-kpi-icon"><i class="fas fa-hourglass-half"></i></span>
                <div class="ui-kpi-body">
                    <span class="ui-kpi-label">Not Yet Collected</span>
                    <span class="ui-kpi-value"><?php echo ui_peso($uncollected['s']); ?></span>
                    <span class="ui-kpi-meta"><?php echo number_format((int) $uncollected['n']); ?> open orders placed in this period</span>
                </div>
            </a>
        </div>

        <?php if ($order_count === 0): ?>
            <section class="ui-card"><div class="ui-card-body"><div class="ui-empty"><p>No money was collected in this period.<?php echo $print_mode ? '' : ' Try a wider range.'; ?></p></div></div></section>
        <?php else: ?>

        <div class="ui-tabs sb-tabs" role="tablist" aria-label="Breakdown">
            <button type="button" role="tab" data-tab="method"><i class="fas fa-wallet"></i> Payment Method</button>
            <button type="button" role="tab" data-tab="parts"><i class="fas fa-layer-group"></i> What It Paid For</button>
            <button type="button" role="tab" data-tab="channel"><i class="fas fa-store"></i> Order Channel</button>
            <button type="button" role="tab" data-tab="products"><i class="fas fa-ranking-star"></i> Top Products</button>
            <?php if ($remittances): ?>
                <button type="button" role="tab" data-tab="cod"><i class="fas fa-motorcycle"></i> COD from Riders <span class="ui-tab-count"><?php echo count($remittances); ?></span></button>
            <?php endif; ?>
            <button type="button" role="tab" data-tab="orders"><i class="fas fa-list"></i> Paid Orders <span class="ui-tab-count"><?php echo number_format($order_count); ?></span></button>
        </div>

            <!-- By payment method -->
            <section class="ui-card sb-panel" id="sb-tab-method" role="tabpanel"<?php echo $panel_hidden('method'); ?>>
                <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-wallet"></i> By Payment Method</h2><p class="ui-card-sub">How customers paid</p></div></div>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-compact">
                        <thead><tr><th>Method</th><th class="num">Orders</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
                        <tbody>
                            <?php foreach ($by_method as $m): ?>
                                <tr>
                                    <td><strong><?php echo ui_e(ui_payment_method_label($m['k'])); ?></strong>
                                        <span class="sb-note"><?php echo ui_e($method_notes[$m['k']] ?? ''); ?></span>
                                        <div class="sb-bar"><span style="width: <?php echo $total > 0 ? round($m['s'] / $total * 100, 1) : 0; ?>%"></span></div></td>
                                    <td class="num"><?php echo number_format((int) $m['n']); ?></td>
                                    <td class="num"><?php echo ui_peso($m['s']); ?></td>
                                    <td class="num"><?php echo $share((float) $m['s']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- What the money paid for -->
            <section class="ui-card sb-panel" id="sb-tab-parts" role="tabpanel"<?php echo $panel_hidden('parts'); ?>>
                <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-layer-group"></i> What the Money Paid For</h2><p class="ui-card-sub">Parts of the order totals; they add up to the amount collected</p></div></div>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-compact">
                        <thead><tr><th>Part</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
                        <tbody>
                            <?php foreach ($components as [$label, $icon, $amount, $note]): ?>
                                <tr>
                                    <td><i class="fas <?php echo $icon; ?> ui-muted"></i> <strong><?php echo ui_e($label); ?></strong><span class="sb-note"><?php echo ui_e($note); ?></span></td>
                                    <td class="num<?php echo $amount < 0 ? ' sb-neg' : ''; ?>"><?php echo $amount < 0 ? '− ' . ui_peso(-$amount) : ui_peso($amount); ?></td>
                                    <td class="num"><?php echo $amount < 0 ? '' : $share($amount); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="sb-total"><td>Total collected</td><td class="num"><?php echo ui_peso($total); ?></td><td class="num">100%</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- By channel -->
            <section class="ui-card sb-panel" id="sb-tab-channel" role="tabpanel"<?php echo $panel_hidden('channel'); ?>>
                <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-store"></i> By Order Channel</h2><p class="ui-card-sub">Who placed the order and how it was handed over</p></div></div>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-compact">
                        <thead><tr><th>Channel</th><th class="num">Orders</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
                        <tbody>
                            <?php foreach ($by_channel as $c): ?>
                                <tr>
                                    <td><strong><?php echo ui_e($channel_label($c)); ?></strong>
                                        <div class="sb-bar"><span style="width: <?php echo $total > 0 ? round($c['s'] / $total * 100, 1) : 0; ?>%"></span></div></td>
                                    <td class="num"><?php echo number_format((int) $c['n']); ?></td>
                                    <td class="num"><?php echo ui_peso($c['s']); ?></td>
                                    <td class="num"><?php echo $share((float) $c['s']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Top products -->
            <section class="ui-card sb-panel" id="sb-tab-products" role="tabpanel"<?php echo $panel_hidden('products'); ?>>
                <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-ranking-star"></i> Top Products</h2><p class="ui-card-sub">Item sales before VAT and delivery fees</p></div></div>
                <div class="ui-table-wrap">
                    <table class="ui-table ui-table-compact">
                        <thead><tr><th class="num" style="width: 48px;">#</th><th>Product</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_products as $i => $p): ?>
                                <tr>
                                    <td class="num sb-rank"><?php echo $prod_pg['offset'] + $i + 1; ?></td>
                                    <td><span class="ui-truncate" style="display:block; max-width: 340px;" title="<?php echo ui_e($p['name']); ?>"><?php echo ui_e($p['name']); ?></span></td>
                                    <td class="num"><?php echo number_format((int) $p['qty']); ?></td>
                                    <td class="num"><?php echo ui_peso($p['s']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo $sb_pager($prod_pg, 'ppage', 'products', 'product'); ?>
            </section>

        <?php if ($remittances): ?>
        <!-- COD remittances -->
        <section class="ui-card sb-panel" id="sb-tab-cod" role="tabpanel"<?php echo $panel_hidden('cod'); ?>>
            <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-motorcycle"></i> COD Cash from Riders</h2><p class="ui-card-sub">Rider remittances the shop accepted, which turned their cash-on-delivery orders into collected money</p></div>
                <a href="remittance_management.php" class="ui-btn ui-btn-sm">Remittances <i class="fas fa-arrow-right"></i></a></div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table-compact">
                    <thead><tr><th class="num" style="width: 48px;">#</th><th>Remittance</th><th>Rider</th><th>Paid in as</th><th>Received</th><th class="num">Orders</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($remittances_page as $i => $r): ?>
                            <tr>
                                <td class="num sb-rank"><?php echo $cod_pg['offset'] + $i + 1; ?></td>
                                <td class="ui-mono"><?php echo ui_e($r['transaction_id'] ?: remittance_number((int) $r['id'])); ?></td>
                                <td><?php echo ui_e(trim($r['rider_full']) !== '' ? $r['rider_full'] : $r['rider_user']); ?></td>
                                <td><?php echo ui_e(remittance_type_label($r['remittance_type'])); ?></td>
                                <td class="ui-nowrap"><?php echo ui_e(date('M j, Y g:i A', strtotime($r['at']))); ?></td>
                                <td class="num"><?php echo number_format((int) $r['n']); ?></td>
                                <td class="num"><?php echo ui_peso($r['s']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo $sb_pager($cod_pg, 'cpage', 'cod', 'remittance'); ?>
        </section>
        <?php endif; ?>

        <!-- Orders -->
        <section class="ui-card sb-panel" id="sb-tab-orders" role="tabpanel"<?php echo $panel_hidden('orders'); ?>>
            <div class="ui-card-head"><div><h2 class="ui-card-title"><i class="fas fa-list"></i> Paid Orders</h2><p class="ui-card-sub">Every order counted in the total above, newest payment first. Click an order to open it.</p></div></div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table-compact">
                    <thead><tr><th class="num" style="width: 48px;">#</th><th>Paid</th><th>Order</th><th>Customer</th><th>Method</th><th>Channel</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($orders as $i => $o): ?>
                            <tr>
                                <td class="num sb-rank"><?php echo $pg['offset'] + $i + 1; ?></td>
                                <td class="ui-nowrap"><?php echo ui_e(date('M j, Y g:i A', strtotime($o['paid_at']))); ?></td>
                                <td><a href="order_details.php?id=<?php echo (int) $o['id']; ?>" class="ui-mono"><?php echo ui_e($o['order_number']); ?></a></td>
                                <td><?php echo ui_e($o['customer'] ?? '—'); ?></td>
                                <td><?php echo ui_e(ui_payment_method_label($o['payment_method'])); ?></td>
                                <td><?php echo ui_e($channel_label(['src' => $o['order_source'], 'ch' => $o['order_channel'], 'ful' => $o['fulfillment_type']])); ?></td>
                                <td class="num"><?php echo ui_peso($o['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pg['pages'] > 1): ?>
                <div class="ui-card-foot ui-pager-bar">
                    <span class="ui-pager-count">Showing <?php echo $pg['from']; ?>–<?php echo $pg['to']; ?> of <?php echo number_format($pg['total']); ?></span>
                    <?php echo ui_pagination($pg['page'], $pg['pages'], fn($n) => ui_page_url(['page' => $n], 'orders')); ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($print_mode): ?>
            <div class="sb-sign"><span>Prepared by</span><span>Checked by</span></div>
        <?php endif; ?>
    </div>

    <?php if ($print_mode): ?>
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    <?php endif; ?>

    <script>
    document.addEventListener('click', function (e) {
        var menu = document.querySelector('.sb-print-menu');
        if (menu && menu.open && !menu.contains(e.target)) menu.open = false;
    });
    </script>
    <script>
    (function () {
        // One breakdown visible at a time. The open tab lives in the URL hash (#orders, #cod, ...),
        // so paging links (which end in #orders), the period buttons and Back all keep it.
        var buttons = document.querySelectorAll('.sb-tabs [data-tab]');
        if (!buttons.length || document.body.classList.contains('sb-print')) return; // the printed report shows every section
        var valid = Array.prototype.map.call(buttons, function (b) { return b.dataset.tab; });
        var periodLinks = document.querySelectorAll('.sb-filter .ui-segmented a');
        var periodForm = document.querySelector('.sb-filter form');

        function activate(tab, updateHash) {
            if (valid.indexOf(tab) === -1) tab = 'method';
            buttons.forEach(function (b) {
                var on = b.dataset.tab === tab;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            document.querySelectorAll('.sb-panel').forEach(function (p) { p.hidden = p.id !== 'sb-tab-' + tab; });
            periodLinks.forEach(function (a) { a.hash = tab; });
            if (periodForm) periodForm.action = '#' + tab;
            if (updateHash) history.replaceState(null, '', '#' + tab);
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () { activate(b.dataset.tab, true); });
        });
        var start = location.hash.replace('#', '');
        // A ?page= link without a hash still belongs to the Paid Orders list.
        if (!start && /[?&]page=/.test(location.search)) start = 'orders';
        if (!start && /[?&]ppage=/.test(location.search)) start = 'products';
        if (!start && /[?&]cpage=/.test(location.search)) start = 'cod';
        activate(start, false);
    })();
    </script>
</body>
</html>
