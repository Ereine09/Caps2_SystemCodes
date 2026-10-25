<?php
/**
 * Customer Loyalty Statements — printable, ONE CUSTOMER PER PAGE.
 *
 * Opened from Loyalty Points (modules/customers/loyalty_points.php):
 *   ?id=5           one customer
 *   ?ids=3,5,9      the customers ticked in the summary table
 *   ?search=ana     every customer whose name/email matches (same filter as the summary table)
 *   (nothing)       every customer
 *   &autoprint=1    opens the browser's print dialog straight away
 *
 * Each statement carries the customer's details, points summary (tier + customer group),
 * order totals, every loyalty transaction and every reward redemption. Voucher PINs are
 * never printed.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}
$username = (string) ($payload['username'] ?? '');

// ---- Which customers ------------------------------------------------------------------------
$only_id = (int) ($_GET['id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$picked_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))))); // ticked rows
if ($picked_ids) {
    $stmt = $conn->prepare('SELECT * FROM customers WHERE id IN (' . implode(',', array_fill(0, count($picked_ids), '?')) . ') ORDER BY id ASC');
    $stmt->bind_param(str_repeat('i', count($picked_ids)), ...$picked_ids);
} elseif ($only_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->bind_param('i', $only_id);
} elseif ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare('SELECT * FROM customers WHERE name LIKE ? OR email LIKE ? ORDER BY id ASC');
    $stmt->bind_param('ss', $like, $like);
} else {
    $stmt = $conn->prepare('SELECT * FROM customers ORDER BY id ASC');
}
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$scope_label = $picked_ids ? 'Selected customers'
    : ($only_id > 0 ? 'Single customer'
    : ($search !== '' ? 'Customers matching “' . $search . '”' : 'All customers'));

// Every customer's balance: the customer-group model compares against all customers.
$all_points = array_map('floatval', array_column(
    $conn->query('SELECT loyalty_points FROM customers')->fetch_all(MYSQLI_ASSOC), 'loyalty_points'));

// Same tiers as the customer profile: from lifetime points earned (app/helpers/loyalty_tier_helper.php).
require_once __DIR__ . '/../../app/helpers/loyalty_tier_helper.php';
$tier_of = static fn(float $lifetime_points): string => loyalty_tier_for($lifetime_points)['label'];

// Per-customer queries (prepared once, run for each statement).
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php'; // Order Amount column
$q_tx = $conn->prepare('SELECT lt.product_name, lt.points_earned, lt.order_id, lt.created_at, lt_o.subtotal AS order_amount FROM loyalty_transactions lt' . loyalty_tx_order_join() . 'WHERE lt.customer_id = ? ORDER BY lt.created_at DESC, lt.id DESC');
// Items of every order this customer earned points on, so each purchase lists its products.
$q_items = $conn->prepare('SELECT order_id, product_name, variant_size, quantity FROM tbl_order_items
    WHERE order_id IN (SELECT order_id FROM loyalty_transactions WHERE customer_id = ? AND order_id IS NOT NULL) ORDER BY order_id, id');
$q_rd = $conn->prepare('SELECT reward_name, reward_code, points_used, status, channel, redeemed_at FROM reward_redemptions WHERE customer_id = ? ORDER BY redeemed_at DESC, id DESC');
$q_or = $conn->prepare("SELECT COUNT(*) AS orders,
                               COALESCE(SUM(order_status IN ('completed','reviews')), 0) AS completed,
                               COALESCE(SUM(order_status = 'cancelled'), 0) AS cancelled,
                               COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS spent,
                               MAX(created_at) AS last_order
                        FROM tbl_orders WHERE customer_id = ?");
$q_login = $conn->prepare('SELECT last_login FROM customer_login_credentials WHERE customer_id = ? LIMIT 1');

$fetch = static function (mysqli_stmt $s, int $id, bool $all = true) {
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    return $all ? $r->fetch_all(MYSQLI_ASSOC) : ($r->fetch_assoc() ?: []);
};
$fmt_date = static fn(?string $d, string $f = 'M j, Y') => $d ? date($f, strtotime($d)) : '—';
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$total = count($customers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Loyalty Statements - <?php echo $e(SYSTEM_NAME); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* A paper document: always light, on screen and on paper. */
        :root { --ink: #111827; --muted: #4b5563; --line: #d1d5db; --soft: #f3f4f6; --accent: #4a3e94; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: var(--ink); font: 13px/1.45 "Segoe UI", Arial, sans-serif; }

        .st-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;
                      padding: 12px 16px; background: #1f2937; color: #fff; }
        .st-toolbar strong { font-size: 15px; }
        .st-toolbar span { color: #d1d5db; margin-left: 8px; }
        .st-toolbar .st-actions { display: flex; gap: 8px; }
        .st-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 8px; border: 0; cursor: pointer;
                  font: 600 13px "Segoe UI", Arial, sans-serif; text-decoration: none; background: #374151; color: #fff; }
        .st-btn.primary { background: var(--accent); }

        .sheet { width: 210mm; min-height: 297mm; margin: 18px auto; padding: 16mm 15mm; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.15); }
        .sheet-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 2px solid var(--ink); padding-bottom: 10px; margin-bottom: 14px; }
        .sheet-head h1 { margin: 0; font-size: 18px; letter-spacing: .3px; }
        .sheet-head h2 { margin: 2px 0 0; font-size: 13px; font-weight: 700; letter-spacing: 1px; color: var(--accent); }
        .sheet-head .meta { text-align: right; font-size: 11px; color: var(--muted); }
        .section-title { margin: 16px 0 6px; font-size: 12px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--accent); }

        .kv { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 24px; }
        .kv div { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px dotted var(--line); }
        .kv dt { width: 110px; flex-shrink: 0; color: var(--muted); }
        .kv dd { margin: 0; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
        .kv .wide { grid-column: 1 / -1; }

        .tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
        .tile { border: 1px solid var(--line); border-radius: 6px; padding: 8px 10px; }
        .tile span { display: block; font-size: 10.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
        .tile strong { font-size: 15px; }

        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th, td { padding: 5px 6px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { background: var(--soft); font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; color: var(--muted); }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .empty { color: var(--muted); font-style: italic; }
        .items { margin: 2px 0 0; padding-left: 14px; color: var(--muted); font-size: 10.5px; }
        .pos { color: #047857; } .neg { color: #b91c1c; }
        tfoot td { font-weight: 700; border-top: 2px solid var(--ink); border-bottom: 0; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 36px; font-size: 11.5px; }
        .signatures div { border-top: 1px solid var(--ink); padding-top: 4px; text-align: center; color: var(--muted); }
        .sheet-foot { margin-top: 18px; font-size: 10.5px; color: var(--muted); text-align: center; }
        .none { max-width: 520px; margin: 60px auto; padding: 30px; background: #fff; border-radius: 10px; text-align: center; }

        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; }
            .st-toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; break-after: page; page-break-after: always; }
            .sheet:last-of-type { break-after: auto; page-break-after: auto; }
            tr, .tile, .signatures { break-inside: avoid; page-break-inside: avoid; }
            thead { display: table-header-group; } /* column headings repeat when a table runs onto the next page */
            tfoot { display: table-row-group; }    /* ...but the total row prints once, at the end */
        }
        @media screen and (max-width: 860px) {
            .sheet { width: auto; min-height: 0; margin: 12px; padding: 18px 16px; }
            .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .kv { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body>
    <div class="st-toolbar">
        <div><strong><i class="fas fa-file-invoice"></i> Customer Loyalty Statements</strong><span><?php echo $e($scope_label); ?> · <?php echo number_format($total); ?> customer<?php echo $total === 1 ? '' : 's'; ?> · one per page</span></div>
        <div class="st-actions">
            <a href="loyalty_points.php" class="st-btn"><i class="fas fa-arrow-left"></i> Back</a>
            <?php if ($total > 0): ?><button type="button" class="st-btn primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button><?php endif; ?>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <div class="none">No customers to print<?php echo $search !== '' ? ' for “' . $e($search) . '”' : ''; ?>.</div>
    <?php endif; ?>

    <?php foreach ($customers as $n => $c):
        $cid = (int) $c['id'];
        $points = (float) $c['loyalty_points'];
        $tx = $fetch($q_tx, $cid);
        $rd = $fetch($q_rd, $cid);
        $items_by_order = [];
        foreach ($fetch($q_items, $cid) as $item) {
            $items_by_order[(int) $item['order_id']][] = $item;
        }
        $or = $fetch($q_or, $cid, false);
        $login = $fetch($q_login, $cid, false)['last_login'] ?? null;
        $earned = array_sum(array_map('floatval', array_column($tx, 'points_earned')));
        $redeemed = array_sum(array_map('floatval', array_column($rd, 'points_used')));
        // The K-Means model's Frequency feature is the number of loyalty transactions (purchases).
        $group = getMLCustomerClassification($points, count($tx), $tx[0]['created_at'] ?? null, $all_points)['label'];
        $profile = trim(implode(', ', array_filter([$c['gender'] ?? '', !empty($c['age']) ? $c['age'] . ' yrs' : ''])));
    ?>
    <section class="sheet">
        <div class="sheet-head">
            <div>
                <h1><?php echo $e(SYSTEM_NAME); ?></h1>
                <h2>CUSTOMER LOYALTY STATEMENT</h2>
            </div>
            <div class="meta">
                Customer <?php echo $n + 1; ?> of <?php echo $total; ?><br>
                Generated <?php echo date('M j, Y g:i A'); ?><br>
                by <?php echo $e($username); ?>
            </div>
        </div>

        <div class="section-title">Customer details</div>
        <dl class="kv">
            <div><dt>Customer ID</dt><dd>#<?php echo $cid; ?></dd></div>
            <div><dt>Name</dt><dd><?php echo $e($c['name']); ?></dd></div>
            <div><dt>Email</dt><dd><?php echo $e($c['email']); ?></dd></div>
            <div><dt>Phone</dt><dd><?php echo $e($c['phone'] ?: '—'); ?></dd></div>
            <div><dt>Gender / Age</dt><dd><?php echo $e($profile !== '' ? $profile : '—'); ?></dd></div>
            <div><dt>Member since</dt><dd><?php echo $fmt_date($c['created_at'] ?? null); ?></dd></div>
            <div><dt>Last login</dt><dd><?php echo $fmt_date($login, 'M j, Y g:i A'); ?></dd></div>
            <div><dt>Customer group</dt><dd><?php echo $e($group); ?></dd></div>
            <div class="wide"><dt>Address</dt><dd><?php echo $e($c['address'] ?: '—'); ?></dd></div>
        </dl>

        <div class="section-title">Points summary</div>
        <div class="tiles">
            <div class="tile"><span>Usable balance</span><strong><?php echo number_format($points, 2); ?></strong></div>
            <div class="tile"><span>Total earned</span><strong class="pos">+<?php echo number_format($earned, 2); ?></strong></div>
            <div class="tile"><span>Total redeemed</span><strong<?php echo $redeemed > 0 ? ' class="neg">−' : '>'; ?><?php echo number_format($redeemed, 2); ?></strong></div>
            <div class="tile"><span>Tier</span><strong><?php echo $tier_of($earned); ?></strong></div>
        </div>

        <div class="section-title">Orders</div>
        <div class="tiles">
            <div class="tile"><span>Orders placed</span><strong><?php echo (int) ($or['orders'] ?? 0); ?></strong></div>
            <div class="tile"><span>Completed / cancelled</span><strong><?php echo (int) ($or['completed'] ?? 0); ?> / <?php echo (int) ($or['cancelled'] ?? 0); ?></strong></div>
            <div class="tile"><span>Total spent</span><strong>PHP <?php echo number_format((float) ($or['spent'] ?? 0), 2); ?></strong></div>
            <div class="tile"><span>Last order</span><strong><?php echo $fmt_date($or['last_order'] ?? null); ?></strong></div>
        </div>

        <div class="section-title">Loyalty transactions (<?php echo count($tx); ?>)</div>
        <table>
            <thead><tr><th>Date</th><th>Description</th><th class="num">Order Amount</th><th class="num">Points</th></tr></thead>
            <tbody>
                <?php if (!$tx): ?>
                    <tr><td colspan="4" class="empty">No loyalty transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($tx as $t): ?>
                    <tr>
                        <td><?php echo $fmt_date($t['created_at'], 'M j, Y g:i A'); ?></td>
                        <td>
                            <?php echo $e($t['product_name']); ?>
                            <?php if (!empty($items_by_order[(int) $t['order_id']])): ?>
                                <ul class="items">
                                    <?php foreach ($items_by_order[(int) $t['order_id']] as $item): ?>
                                        <li><?php echo $e($item['product_name'] . (!empty($item['variant_size']) && !str_contains($item['product_name'], $item['variant_size']) ? ' (' . $item['variant_size'] . ')' : '')); ?> &times; <?php echo (int) $item['quantity']; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?php echo loyalty_tx_amount($t); ?></td>
                        <td class="num <?php echo (float) $t['points_earned'] < 0 ? 'neg' : 'pos'; ?>"><?php echo ((float) $t['points_earned'] < 0 ? '' : '+') . number_format((float) $t['points_earned'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($tx): ?><tfoot><tr><td colspan="3">Total earned</td><td class="num">+<?php echo number_format($earned, 2); ?></td></tr></tfoot><?php endif; ?>
        </table>

        <div class="section-title">Reward redemptions (<?php echo count($rd); ?>)</div>
        <table>
            <thead><tr><th>Date</th><th>Reward</th><th>Code</th><th>Status</th><th class="num">Points used</th></tr></thead>
            <tbody>
                <?php if (!$rd): ?>
                    <tr><td colspan="5" class="empty">No rewards redeemed yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rd as $r): ?>
                    <tr>
                        <td><?php echo $fmt_date($r['redeemed_at'], 'M j, Y g:i A'); ?></td>
                        <td><?php echo $e($r['reward_name']); ?><?php echo !empty($r['channel']) ? ' <span class="empty">(' . $e($r['channel']) . ')</span>' : ''; ?></td>
                        <td><?php echo $e($r['reward_code'] ?: '—'); ?></td>
                        <td><?php echo $e(ucfirst((string) ($r['status'] ?: '—'))); ?></td>
                        <td class="num neg">−<?php echo number_format((float) $r['points_used'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rd): ?><tfoot><tr><td colspan="4">Total redeemed</td><td class="num">−<?php echo number_format($redeemed, 2); ?></td></tr></tfoot><?php endif; ?>
        </table>

        <div class="signatures">
            <div>Prepared by</div>
            <div>Customer acknowledgement</div>
        </div>
        <div class="sheet-foot"><?php echo $e(SYSTEM_NAME); ?> · Customer #<?php echo $cid; ?> · <?php echo $e($c['name']); ?></div>
    </section>
    <?php endforeach; ?>

    <?php if ($total > 0 && !empty($_GET['autoprint'])): ?>
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    <?php endif; ?>
</body>
</html>
