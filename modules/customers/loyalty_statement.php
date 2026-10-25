<?php
/**
 * Printable loyalty points statement for ONE customer, opened from the
 * "Statement" link in the Customer Loyalty Summary on loyalty_points.php.
 *
 * One row per product: every item of every online order that earned points
 * (1 point per PHP 100, the same rule as app/helpers/loyalty_helper.php),
 * plus manual adjustments and reward redemptions, so the points column adds
 * up to the customer's balance. Read-only: it only SELECTs and renders.
 *
 * ?ids=3,5,9 (the "Print selected" button) prints the same statement for
 * several customers, one per printed page; &autoprint=1 opens the print dialog.
 */
require_once __DIR__ . '/../../app/helpers/session_helper.php';
app_session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$token = getJWTFromCookie();
$payload = $token ? verifyJWT($token) : null;
if (!$payload || !in_array(strtolower((string) ($payload['role'] ?? '')), ['admin', 'staff'], true)) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}
$username = (string) ($payload['username'] ?? '');

$customer_id = (int) ($_GET['id'] ?? 0);
$customer_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))));
if (!$customer_ids && $customer_id > 0) {
    $customer_ids = [$customer_id];
}
$customers = [];
if ($customer_ids) {
    $cust_stmt = $conn->prepare('SELECT id, name, email, loyalty_points FROM customers WHERE id IN (' . implode(',', array_fill(0, count($customer_ids), '?')) . ') ORDER BY name ASC');
    $cust_stmt->bind_param(str_repeat('i', count($customer_ids)), ...$customer_ids);
    $cust_stmt->execute();
    $customers = $cust_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cust_stmt->close();
}
if (!$customers) {
    http_response_code(404);
    exit('Customer not found.');
}

// Optional period (?from=YYYY-MM-DD&to=YYYY-MM-DD); empty = all time.
$valid_date = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$from = $valid_date($from) ? $from : '';
$to = $valid_date($to) ? $to : '';
$from_dt = $from !== '' ? $from . ' 00:00:00' : '1000-01-01 00:00:00';
$to_dt = $to !== '' ? $to . ' 23:59:59' : '9999-12-31 23:59:59';

// One customer's statement rows (date order) plus the period's earned / redeemed totals.
function loyalty_statement_rows(mysqli $conn, int $customer_id, string $from_dt, string $to_dt): array
{
    $rows = [];

    // 1) Points earned: online orders broken down per item, and manual adjustments.
    $tx_stmt = $conn->prepare(
        "SELECT lt.id, lt.order_id, lt.product_name, lt.points_earned, lt.created_at, o.order_number,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_name
         FROM loyalty_transactions lt
         LEFT JOIN tbl_orders o ON o.id = lt.order_id
         LEFT JOIN users u ON u.id = lt.user_id
         WHERE lt.customer_id = ? AND lt.created_at BETWEEN ? AND ?
         ORDER BY lt.created_at, lt.id"
    );
    $tx_stmt->bind_param('iss', $customer_id, $from_dt, $to_dt);
    $tx_stmt->execute();
    $transactions = $tx_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $tx_stmt->close();

    $items_by_order = [];
    $order_ids = array_values(array_unique(array_filter(array_map(static fn($t) => (int) $t['order_id'], $transactions))));
    if (!empty($order_ids)) {
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $items_stmt = $conn->prepare(
            "SELECT order_id, product_name, variant_size, quantity, total_price
             FROM tbl_order_items WHERE order_id IN ($placeholders) ORDER BY order_id, id"
        );
        $items_stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
        $items_stmt->execute();
        foreach ($items_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
            $items_by_order[(int) $item['order_id']][] = $item;
        }
        $items_stmt->close();
    }

    $total_earned = 0.0;
    foreach ($transactions as $t) {
        $total_earned += (float) $t['points_earned'];
        $items = $items_by_order[(int) $t['order_id']] ?? [];
        if (!empty($t['order_id']) && !empty($items)) {
            $source = 'Online Purchase - Order #' . ($t['order_number'] ?: $t['order_id']);
            foreach ($items as $item) {
                $rows[] = [
                    'date' => $t['created_at'],
                    'source' => $source,
                    'product' => $item['product_name'] . (!empty($item['variant_size']) && !str_contains($item['product_name'], $item['variant_size']) ? ' (' . $item['variant_size'] . ')' : ''),
                    'qty' => (int) $item['quantity'],
                    'amount' => (float) $item['total_price'],
                    'points' => round((float) $item['total_price'] / 100, 2),
                ];
            }
            continue;
        }
        $by = trim((string) $t['staff_name']);
        $rows[] = [
            'date' => $t['created_at'],
            'source' => !empty($t['order_id'])
                ? 'Online Purchase - Order #' . ($t['order_number'] ?: $t['order_id'])
                : 'Manual Adjustment' . ($by !== '' ? ' by ' . $by : ''),
            'product' => (string) $t['product_name'],
            'qty' => null,
            'amount' => null,
            'points' => (float) $t['points_earned'],
        ];
    }

    // 2) Points used: reward redemptions (shown as negative points).
    $rd_stmt = $conn->prepare(
        "SELECT reward_name, points_used, redeemed_at, channel
         FROM reward_redemptions
         WHERE customer_id = ? AND redeemed_at BETWEEN ? AND ?
         ORDER BY redeemed_at, id"
    );
    $rd_stmt->bind_param('iss', $customer_id, $from_dt, $to_dt);
    $rd_stmt->execute();
    $total_redeemed = 0.0;
    foreach ($rd_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $total_redeemed += (float) $r['points_used'];
        $rows[] = [
            'date' => $r['redeemed_at'],
            'source' => 'Reward Redemption' . (!empty($r['channel']) ? ' (' . ucwords(strtolower(str_replace('_', '-', $r['channel']))) . ')' : ''),
            'product' => (string) $r['reward_name'],
            'qty' => 1,
            'amount' => null,
            'points' => -(float) $r['points_used'],
        ];
    }
    $rd_stmt->close();

    usort($rows, static fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

    return [$rows, $total_earned, $total_redeemed];
}

$period_label = ($from === '' && $to === '')
    ? 'All transactions'
    : ($from !== '' ? date('M j, Y', strtotime($from)) : 'Start') . ' - ' . ($to !== '' ? date('M j, Y', strtotime($to)) : 'Today');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Statement - <?php echo htmlspecialchars(count($customers) === 1 ? $customers[0]['name'] : count($customers) . ' customers'); ?></title>
    <style>
        body { margin: 0; background: #e2e8f0; font-family: Arial, Helvetica, sans-serif; color: #111827; }
        .toolbar {
            position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; justify-content: space-between;
            align-items: center; gap: 10px; padding: 14px; background: #1f2937; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .toolbar form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; color: #e5e7eb; font-size: 0.85rem; }
        .toolbar input[type="date"] { padding: 8px; border-radius: 6px; border: 1px solid #4b5563; background: #111827; color: #e5e7eb; }
        .toolbar a, .toolbar button {
            display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 8px;
            padding: 10px 18px; font-weight: 700; font-size: 0.9rem; cursor: pointer; text-decoration: none; font-family: inherit;
        }
        .btn-back { background: #374151; color: #e5e7eb; }
        .btn-back:hover { background: #4b5563; }
        .btn-filter { background: #4b5563; color: #fff; }
        .btn-print { background: #0f766e; color: #fff; }
        .btn-print:hover { background: #0e6377; }
        .sheet { background: #fff; max-width: 900px; margin: 24px auto; padding: 32px; box-sizing: border-box; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { font-size: 18px; margin: 0 0 2px; text-align: center; letter-spacing: .5px; }
        h2 { font-size: 14px; margin: 0 0 10px; text-align: center; letter-spacing: .5px; }
        .meta { text-align: center; font-size: 12px; margin: 0 0 14px; line-height: 1.5; }
        hr { border: none; border-top: 1px solid #000; margin: 14px 0; }
        .section-title { font-weight: 700; font-size: 13px; letter-spacing: .5px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .info td { padding: 3px 0; }
        .info td:first-child { width: 40%; }
        .info td:last-child { text-align: right; font-weight: 600; }
        .details th, .details td { border-bottom: 1px solid #999; padding: 5px 6px; text-align: left; vertical-align: top; }
        .details th.num, .details td.num { text-align: right; white-space: nowrap; }
        .details tfoot td { font-weight: 700; border-bottom: 1px solid #000; }
        .neg { color: #b91c1c; }
        .signature { margin-top: 50px; font-size: 12px; }
        @media print {
            .toolbar { display: none !important; }
            body { background: #fff; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
            .sheet + .sheet { break-before: page; } /* one customer per printed page */
            .neg { color: #000; }
            @page { margin: 1.5cm; }
        }
        @media (max-width: 640px) { .sheet { margin: 0; padding: 16px; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="loyalty_points.php?tab=summary#customer-summary-section" class="btn-back">&larr; Back</a>
        <form method="GET">
            <input type="hidden" name="<?php echo count($customer_ids) > 1 ? 'ids' : 'id'; ?>" value="<?php echo htmlspecialchars(implode(',', $customer_ids)); ?>">
            <label>From <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" max="<?php echo date('Y-m-d'); ?>"></label>
            <label>To <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" max="<?php echo date('Y-m-d'); ?>"></label>
            <button type="submit" class="btn-filter">Apply</button>
        </form>
        <button type="button" class="btn-print" onclick="window.print()">&#128424; Print <?php echo count($customers) > 1 ? count($customers) . ' Statements' : 'Statement'; ?></button>
    </div>

    <?php foreach ($customers as $customer):
        [$rows, $total_earned, $total_redeemed] = loyalty_statement_rows($conn, (int) $customer['id'], $from_dt, $to_dt); ?>
    <div class="sheet">
        <h1><?php echo htmlspecialchars(SYSTEM_NAME); ?></h1>
        <h2>CUSTOMER LOYALTY POINTS STATEMENT</h2>
        <p class="meta">Generated: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($username); ?></p>
        <hr>
        <div class="section-title">CUSTOMER</div>
        <table class="info">
            <tr><td>Name</td><td><?php echo htmlspecialchars($customer['name']); ?></td></tr>
            <tr><td>Email</td><td><?php echo htmlspecialchars($customer['email']); ?></td></tr>
            <tr><td>Period</td><td><?php echo htmlspecialchars($period_label); ?></td></tr>
            <tr><td>Points Earned (period)</td><td><?php echo number_format($total_earned, 2); ?> pts</td></tr>
            <tr><td>Points Redeemed (period)</td><td><?php echo number_format($total_redeemed, 2); ?> pts</td></tr>
            <tr><td>Current Usable Balance</td><td><?php echo number_format((float) $customer['loyalty_points'], 2); ?> pts</td></tr>
        </table>
        <hr>
        <div class="section-title">DETAILS &mdash; Item-by-Item Points Breakdown</div>
        <table class="details">
            <thead>
                <tr><th>Date</th><th>Source</th><th>Product / Reward</th><th class="num">Qty</th><th class="num">Amount</th><th class="num">Points</th></tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">No loyalty activity for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($row['date']))); ?></td>
                            <td><?php echo htmlspecialchars($row['source']); ?></td>
                            <td><?php echo htmlspecialchars($row['product']); ?></td>
                            <td class="num"><?php echo $row['qty'] === null ? '&mdash;' : (int) $row['qty']; ?></td>
                            <td class="num"><?php echo $row['amount'] === null ? '&mdash;' : '&#8369;' . number_format($row['amount'], 2); ?></td>
                            <td class="num<?php echo $row['points'] < 0 ? ' neg' : ''; ?>"><?php echo ($row['points'] > 0 ? '+' : '') . number_format($row['points'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="5">Net points for period (earned &minus; redeemed)</td><td class="num"><?php echo number_format($total_earned - $total_redeemed, 2); ?></td></tr>
            </tfoot>
        </table>
        <p class="meta" style="text-align:left; margin-top:10px;">Points rule: 1 point for every &#8369;100 spent on a completed order.</p>
        <p class="signature">Prepared by: ____________________________</p>
    </div>
    <?php endforeach; ?>
    <?php if (!empty($_GET['autoprint'])): ?>
        <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
