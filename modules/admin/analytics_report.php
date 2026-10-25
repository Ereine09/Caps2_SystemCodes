<?php
/**
 * Printable soft copy of one Analytics & Reports tab, opened from that tab's "Print"
 * button on analytics.php. A plain report document (not the dashboard UI):
 *
 *   ?report=daily    Points Earned & Used - Last 14 Days
 *   ?report=flow     Points Given vs. Points Used (selected dates)
 *   ?report=groups   Customer Groups (Loyal, Frequent, Regular, At Risk) - every customer
 *   ?report=staff    Top Staff by Points Given (selected dates)
 *   &date_from=YYYY-MM-DD&date_to=YYYY-MM-DD   same dates as the analytics filter
 *
 * Figures come from app/helpers/analytics_helper.php, the same as the on-screen tabs.
 * Read-only: it only SELECTs and renders.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/validation_helper.php';
require_once __DIR__ . '/../../app/helpers/analytics_helper.php';

$token = getJWTFromCookie();
$payload = $token ? verifyJWT($token) : null;
if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}
if (strtolower(trim((string) ($payload['role'] ?? ''))) !== 'admin') {
    header('Location: ' . BASE_URL . '/modules/staff/dashboard.php');
    exit();
}
$username = (string) ($payload['username'] ?? '');

// Same date rules as analytics.php.
$date_from = min(clean_date($_GET['date_from'] ?? '', date('Y-m-d', strtotime('-30 days'))), date('Y-m-d'));
$date_to = min(clean_date($_GET['date_to'] ?? '', date('Y-m-d')), date('Y-m-d'));
$period_label = date('F j, Y', strtotime($date_from)) . ' - ' . date('F j, Y', strtotime($date_to));

$REPORTS = [
    'daily' => 'Points Earned & Used - Last 14 Days',
    'flow' => 'Points Given vs. Points Used',
    'groups' => 'Customer Groups (Loyal, Frequent, Regular, At Risk)',
    'staff' => 'Top Staff by Points Given',
];
$report = (string) ($_GET['report'] ?? '');
if (!isset($REPORTS[$report])) {
    $report = 'daily';
}
$title = $REPORTS[$report];

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pts = static fn($n) => number_format((float) $n, 2);

// ---- Data for the chosen report -------------------------------------------------------------
if ($report === 'daily') {
    $days = analytics_last_14_days($conn);
    $covers = 'Last 14 days (' . date('F j, Y', strtotime('-13 days')) . ' - ' . date('F j, Y') . '). Does not follow the date filter.';
    $sum_earned = array_sum($days['earned']);
    $sum_used = array_sum($days['redeemed']);
    $busiest = $days['earned'] ? array_keys($days['earned'], max($days['earned']))[0] : null;
    $active_days = count(array_filter(array_map(null, $days['earned'], $days['redeemed']), static fn($d) => $d[0] > 0 || $d[1] > 0));
} elseif ($report === 'flow') {
    ['earned' => $sum_earned, 'redeemed' => $sum_used] = analytics_points_totals($conn, $date_from, $date_to);
    $outstanding = analytics_points_outstanding($conn);
    $by_day = analytics_daily_in_period($conn, $date_from, $date_to);
    $covers = $period_label . ' (selected dates)';
} elseif ($report === 'groups') {
    ['totals' => $group_totals, 'top' => $members] = analytics_customer_groups($conn, 0); // every customer
    $priority = analytics_group_priority();
    uksort($group_totals, static fn($a, $b) => ($priority[$a] ?? 99) <=> ($priority[$b] ?? 99));
    $customer_total = array_sum($group_totals);
    $covers = 'All ' . number_format($customer_total) . ' customers, all-time activity. Does not follow the date filter.';
    $by_group = [];
    foreach ($members as $m) {
        $by_group[$m['ml_label']][] = $m;
    }
} else {
    $staff = analytics_top_staff($conn, $date_from, $date_to);
    $covers = $period_label . ' (selected dates)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($title); ?> - <?php echo $e(SYSTEM_NAME); ?></title>
    <style>
        body { margin: 0; background: #e2e8f0; font-family: Arial, Helvetica, sans-serif; color: #111827; }
        .toolbar {
            position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; justify-content: space-between;
            align-items: center; gap: 10px; padding: 14px; background: #1f2937; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .toolbar span { color: #e5e7eb; font-size: 0.9rem; font-weight: 600; }
        .toolbar a, .toolbar button {
            display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 8px;
            padding: 10px 18px; font-weight: 700; font-size: 0.9rem; cursor: pointer; text-decoration: none; font-family: inherit;
        }
        .btn-back { background: #374151; color: #e5e7eb; }
        .btn-back:hover { background: #4b5563; }
        .btn-print { background: #0f766e; color: #fff; }
        .btn-print:hover { background: #0e6377; }
        .sheet { background: #fff; max-width: 900px; margin: 24px auto; padding: 32px; box-sizing: border-box; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { font-size: 18px; margin: 0 0 2px; text-align: center; letter-spacing: .5px; }
        h2 { font-size: 14px; margin: 0 0 10px; text-align: center; letter-spacing: .5px; text-transform: uppercase; }
        .meta { text-align: center; font-size: 12px; margin: 0 0 14px; line-height: 1.5; }
        hr { border: none; border-top: 1px solid #000; margin: 14px 0; }
        .section-title { font-weight: 700; font-size: 13px; letter-spacing: .5px; margin: 0 0 8px; }
        .group-title { font-weight: 700; font-size: 12.5px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .info td { padding: 3px 0; }
        .info td:first-child { width: 55%; }
        .info td:last-child { text-align: right; font-weight: 600; }
        .details th, .details td { border-bottom: 1px solid #999; padding: 5px 6px; text-align: left; vertical-align: top; }
        .details th { background: #f3f4f6; }
        .details th.num, .details td.num { text-align: right; white-space: nowrap; }
        .details tfoot td { font-weight: 700; border-bottom: 1px solid #000; border-top: 2px solid #000; }
        .details small { display: block; color: #4b5563; }
        .neg { color: #b91c1c; }
        .note { font-size: 11.5px; color: #374151; line-height: 1.5; margin: 10px 0 0; }
        .signatures { display: flex; gap: 40px; margin-top: 50px; font-size: 12px; }
        .signatures div { flex: 1; border-top: 1px solid #000; padding-top: 4px; text-align: center; }
        @media print {
            .toolbar { display: none !important; }
            body { background: #fff; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
            .details th { background: none; }
            .neg { color: #000; }
            tr, .signatures { break-inside: avoid; }
            thead { display: table-header-group; } /* repeat column headings on every page */
            @page { margin: 1.5cm; }
        }
        @media (max-width: 640px) { .sheet { margin: 0; padding: 16px; } .details { font-size: 11px; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="analytics.php?date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>" class="btn-back">&larr; Back to Analytics</a>
        <span><?php echo $e($title); ?></span>
        <button type="button" class="btn-print" onclick="window.print()">&#128424; Print / Save as PDF</button>
    </div>

    <div class="sheet">
        <h1><?php echo $e(SYSTEM_NAME); ?></h1>
        <h2><?php echo $e($title); ?></h2>
        <p class="meta">Covers: <?php echo $e($covers); ?><br>Generated: <?php echo date('F j, Y g:i A'); ?> by <?php echo $e($username); ?></p>
        <hr>

        <?php if ($report === 'daily'): ?>
            <div class="section-title">SUMMARY</div>
            <table class="info">
                <tr><td>Points Earned (14 days)</td><td><?php echo $pts($sum_earned); ?> pts</td></tr>
                <tr><td>Points Used (14 days)</td><td><?php echo $pts($sum_used); ?> pts</td></tr>
                <tr><td>Difference (Earned &minus; Used)</td><td><?php echo $pts($sum_earned - $sum_used); ?> pts</td></tr>
                <tr><td>Days with Points Activity</td><td><?php echo (int) $active_days; ?> of 14</td></tr>
                <tr><td>Most Points Earned in a Day</td><td><?php echo $sum_earned > 0 ? $e($days['labels'][$busiest]) . ' (' . $pts($days['earned'][$busiest]) . ' pts)' : '&mdash;'; ?></td></tr>
            </table>
            <hr>
            <div class="section-title">DETAILS &mdash; Points by Day</div>
            <table class="details">
                <thead><tr><th>Date</th><th class="num">Points Earned</th><th class="num">Points Used</th><th class="num">Difference</th></tr></thead>
                <tbody>
                    <?php foreach ($days['labels'] as $i => $label): $diff = $days['earned'][$i] - $days['redeemed'][$i]; ?>
                        <tr>
                            <td><?php echo $e($label); ?></td>
                            <td class="num"><?php echo $pts($days['earned'][$i]); ?></td>
                            <td class="num"><?php echo $pts($days['redeemed'][$i]); ?></td>
                            <td class="num<?php echo $diff < 0 ? ' neg' : ''; ?>"><?php echo $pts($diff); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total</td><td class="num"><?php echo $pts($sum_earned); ?></td><td class="num"><?php echo $pts($sum_used); ?></td><td class="num"><?php echo $pts($sum_earned - $sum_used); ?></td></tr></tfoot>
            </table>
            <p class="note"><strong>Difference</strong> = points customers earned minus points they spent on rewards that day.</p>

        <?php elseif ($report === 'flow'): ?>
            <div class="section-title">SUMMARY</div>
            <table class="info">
                <tr><td>Points Given (Earned by Customers)</td><td><?php echo $pts($sum_earned); ?> pts</td></tr>
                <tr><td>Points Used on Rewards</td><td><?php echo $pts($sum_used); ?> pts</td></tr>
                <tr><td>Difference (Earned &minus; Used)</td><td><?php echo $pts($sum_earned - $sum_used); ?> pts</td></tr>
                <tr><td>Share of Given Points Used</td><td><?php echo $sum_earned > 0 ? number_format($sum_used / $sum_earned * 100, 1) . '%' : '&mdash;'; ?></td></tr>
                <tr><td>Points Customers Still Have (as of today)</td><td><?php echo $pts($outstanding); ?> pts</td></tr>
            </table>
            <hr>
            <div class="section-title">DETAILS &mdash; Points by Day (days with activity)</div>
            <table class="details">
                <thead><tr><th>Date</th><th class="num">Points Earned</th><th class="num">Points Used</th><th class="num">Difference</th></tr></thead>
                <tbody>
                    <?php if (!$by_day): ?>
                        <tr><td colspan="4">No points activity in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($by_day as $d): $diff = (float) $d['earned'] - (float) $d['redeemed']; ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($d['activity_date'])); ?></td>
                            <td class="num"><?php echo $pts($d['earned']); ?></td>
                            <td class="num"><?php echo $pts($d['redeemed']); ?></td>
                            <td class="num<?php echo $diff < 0 ? ' neg' : ''; ?>"><?php echo $pts($diff); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($by_day): ?><tfoot><tr><td>Total</td><td class="num"><?php echo $pts($sum_earned); ?></td><td class="num"><?php echo $pts($sum_used); ?></td><td class="num"><?php echo $pts($sum_earned - $sum_used); ?></td></tr></tfoot><?php endif; ?>
            </table>
            <p class="note">A large positive difference means points are piling up unused, which may be a sign to promote the rewards.
                <strong>Points Customers Still Have</strong> is every customer's usable balance today and does not follow the dates.</p>

        <?php elseif ($report === 'groups'): ?>
            <div class="section-title">SUMMARY</div>
            <table class="info">
                <?php foreach ($group_totals as $label => $n): ?>
                    <tr><td><?php echo $e($label); ?></td><td><?php echo number_format($n); ?> customer<?php echo $n === 1 ? '' : 's'; ?> (<?php echo $customer_total ? number_format($n / $customer_total * 100, 0) : 0; ?>%)</td></tr>
                <?php endforeach; ?>
                <tr><td><strong>Total Customers</strong></td><td><?php echo number_format($customer_total); ?></td></tr>
            </table>
            <hr>
            <div class="section-title">DETAILS &mdash; Customers by Group (highest usable points first)</div>
            <?php foreach ($group_totals as $label => $n): ?>
                <div class="group-title"><?php echo $e($label); ?> (<?php echo number_format($n); ?>)</div>
                <table class="details">
                    <thead><tr><th class="num" style="width:36px;">#</th><th>Customer</th><th class="num">Usable Points</th><th class="num">Items Purchased</th></tr></thead>
                    <tbody>
                        <?php foreach ($by_group[$label] ?? [] as $i => $c): ?>
                            <tr>
                                <td class="num"><?php echo $i + 1; ?></td>
                                <td><?php echo $e($c['name']); ?><small><?php echo $e($c['email']); ?></small></td>
                                <td class="num"><?php echo $pts($c['loyalty_points']); ?></td>
                                <td class="num"><?php echo (int) $c['purchased_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
            <p class="note">Groups are decided by K-Means clustering of each customer's purchase recency, frequency and points.
                <strong>Loyal</strong>: buys most often and holds the most points. <strong>Frequent</strong>: buys regularly and recently.
                <strong>Regular</strong>: buys occasionally. <strong>At Risk</strong>: has gone the longest without buying.
                <strong>New</strong>: has an account but no purchases yet.</p>

        <?php else: ?>
            <div class="section-title">SUMMARY</div>
            <table class="info">
                <tr><td>Staff Listed</td><td><?php echo count($staff); ?> (top 5 by points given)</td></tr>
                <tr><td>Total Points Given by Listed Staff</td><td><?php echo $pts(array_sum(array_map(static fn($s) => (float) ($s['total_points_awarded'] ?? 0), $staff))); ?> pts</td></tr>
                <tr><td>Total Redemptions Handled</td><td><?php echo number_format(array_sum(array_column($staff, 'redemptions_processed'))); ?></td></tr>
            </table>
            <hr>
            <div class="section-title">DETAILS &mdash; Staff Ranking</div>
            <table class="details">
                <thead><tr><th class="num" style="width:36px;">Rank</th><th>Staff Member</th><th class="num">Times Gave Points</th><th class="num">Redemptions Handled</th><th class="num">Total Points Given</th></tr></thead>
                <tbody>
                    <?php if (!$staff): ?>
                        <tr><td colspan="5">No staff activity in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($staff as $i => $s): ?>
                        <tr>
                            <td class="num"><?php echo $i + 1; ?></td>
                            <td><?php echo $e(trim($s['first_name'] . ' ' . $s['last_name'])); ?><small>@<?php echo $e($s['username']); ?></small></td>
                            <td class="num"><?php echo (int) $s['transactions_count']; ?></td>
                            <td class="num"><?php echo (int) $s['redemptions_processed']; ?></td>
                            <td class="num"><?php echo $pts($s['total_points_awarded'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="note">Ranked by the loyalty points each staff member gave to customers during the selected dates.</p>
        <?php endif; ?>

        <div class="signatures">
            <div>Prepared by</div>
            <div>Checked by</div>
        </div>
    </div>
</body>
</html>
