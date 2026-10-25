<?php
/**
 * Purchase order / stock receiving / inventory batch tests (CLI).
 *
 * Runs against a COPY of the database - never the real one:
 *     mysql -u root -e "CREATE DATABASE capstone_test" && mysqldump capstone_db | mysql capstone_test
 *     DB_NAME=capstone_test php tests/purchasing_receiving_test.php
 *
 * It creates its own supplier and products (names start with "ZZ TEST"), blanks the admin emails of
 * the test copy (so stock alerts send nothing) and checks after every step that each item's stock
 * equals the sum of its available batches.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
if (getenv('DB_NAME') === false || getenv('DB_NAME') === 'capstone_db') {
    exit("Refusing to run: set DB_NAME to a test copy (e.g. DB_NAME=capstone_test).\n");
}
$_SERVER['SCRIPT_NAME'] = '/loyalty_managements/tests/purchasing_receiving_test.php';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../customer/includes/functions.php';
require_once __DIR__ . '/../app/helpers/purchasing_helper.php';
require_once __DIR__ . '/../modules/shared/inventory_view.php';

// ---- worker mode: one receive call (used to fire two submits at the same time) ----
if (($argv[1] ?? '') === '--receive') {
    [, , $po, $item, $qty, $token] = $argv;
    $r = purch_receive($conn, ['role' => 'staff', 'user_id' => 0], (int) $po, [
        'token' => $token, 'verified' => 1, 'received_at' => date('Y-m-d H:i'),
        'lines' => [(int) $item => ['accepted' => (int) $qty, 'rejected' => 0, 'unit_cost' => '180.00']],
    ]);
    echo json_encode($r), "\n";
    exit;
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}
function q(string $sql)
{
    global $conn;
    $r = $conn->query($sql);
    return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : $r;
}
function one(string $sql)
{
    $rows = q($sql);
    return $rows ? reset($rows[0]) : null;
}
function stock(int $pid, int $vid = 0): int
{
    return (int) ($vid ? one("SELECT stock FROM tbl_product_variants WHERE id = $vid") : one("SELECT stock FROM tbl_product_inventory WHERE id = $pid"));
}
function batch_sum(int $pid, int $vid = 0): int
{
    return (int) one("SELECT COALESCE(SUM(qty_remaining),0) FROM tbl_inventory_batches WHERE product_id = $pid AND variant_id = $vid AND status = 'available'");
}
/** The core invariant, over EVERY item in the database. */
function invariant(string $when): void
{
    $bad = q("SELECT p.id, 0 vid, p.stock, COALESCE((SELECT SUM(qty_remaining) FROM tbl_inventory_batches b WHERE b.product_id = p.id AND b.variant_id = 0 AND b.status = 'available'), 0) s
              FROM tbl_product_inventory p WHERE NOT EXISTS (SELECT 1 FROM tbl_product_variants v WHERE v.product_id = p.id)
              HAVING p.stock <> s
              UNION ALL
              SELECT v.product_id, v.id, v.stock, COALESCE((SELECT SUM(qty_remaining) FROM tbl_inventory_batches b WHERE b.variant_id = v.id AND b.status = 'available'), 0) s
              FROM tbl_product_variants v HAVING v.stock <> s");
    check("invariant stock == available batches ($when)", !$bad, json_encode($bad));
}
function po_status(int $po): string
{
    return (string) one("SELECT status FROM tbl_supplier_deliveries WHERE id = $po");
}
function po_item(int $po, int $pid, int $vid = 0): array
{
    return q("SELECT * FROM tbl_purchase_order_items WHERE po_id = $po AND product_id = $pid AND variant_id = $vid")[0];
}

$admin = ['role' => 'admin', 'user_id' => (int) one("SELECT id FROM users WHERE role = 'admin' LIMIT 1")];
$staff = ['role' => 'staff', 'user_id' => (int) one("SELECT id FROM users WHERE role = 'staff' LIMIT 1")];

echo "== Setup (" . getenv('DB_NAME') . ")\n";
// Test copy only: admin emails without an @, so stock alerts cannot reach a real inbox.
q("UPDATE users SET email = CONCAT('no-email-', id) WHERE role = 'admin'");
purch_ensure_schema($conn);
$legacy_before = q("SELECT id, status, is_legacy, items_summary, po_number FROM tbl_supplier_deliveries ORDER BY id");
check('existing deliveries preserved and marked legacy', count($legacy_before) >= 1 && array_sum(array_column($legacy_before, 'is_legacy')) === count(array_filter($legacy_before, fn($r) => (int) $r['is_legacy'] === 1)) && $legacy_before[0]['po_number'] !== null);
inv_batches_reconcile_all($conn);
invariant('after first reconcile (opening batches created)');
$opening = (int) one("SELECT COUNT(*) FROM tbl_inventory_batches WHERE source = 'opening'");
check('opening batches created for stock on hand', $opening > 0, "opening=$opening");

q("INSERT INTO tbl_suppliers (supplier_name, brand_name, is_active) VALUES ('ZZ TEST Supplier', 'TestBrand', 1)");
$sid = (int) $conn->insert_id;
q("INSERT INTO tbl_product_inventory (sku, name, price, stock, low_stock_threshold) VALUES ('ZZT-" . time() . "', 'ZZ TEST Cat Litter 7kg', 250.00, 0, 0)");
$pid = (int) $conn->insert_id;
q("INSERT INTO tbl_product_inventory (sku, name, price, stock, low_stock_threshold) VALUES ('ZZV-" . time() . "', 'ZZ TEST Dog Food', 100.00, 0, 0)");
$vpid = (int) $conn->insert_id;
q("INSERT INTO tbl_product_variants (product_id, size, price, stock, low_stock_threshold) VALUES ($vpid, '1kg', 100, 0, 0), ($vpid, '5kg', 450, 0, 0)");
$v1 = (int) one("SELECT id FROM tbl_product_variants WHERE product_id = $vpid AND size = '1kg'");
$price_before = (string) one("SELECT price FROM tbl_product_inventory WHERE id = $pid");

// Existing batch: 20 pcs at 150
$r = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '20', 'cost' => '150']]], true);
$po0 = (int) $r['po_id'];
$i0 = (int) po_item($po0, $pid)['id'];
$r = purch_receive($conn, $staff, $po0, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [$i0 => ['accepted' => 20, 'unit_cost' => '150', 'expiry' => date('Y-m-d', strtotime('+200 days'))]]]);
check('setup: first batch of 20 @ 150 received', $r['ok'] && stock($pid) === 20, $r['message']);

echo "== Test 1: create a purchase order for 50 (no stock change)\n";
$r = purch_save_po($conn, $staff, ['supplier_id' => $sid, 'expected_date' => date('Y-m-d', strtotime('+3 days')), 'tracking_number' => 'TRK-1', 'notes' => 'test',
    'items' => [['item' => "$pid:0", 'qty' => '50', 'cost' => '180'], ['item' => "$vpid:$v1", 'qty' => '10', 'cost' => '60.50']]], true);
$po = (int) ($r['po_id'] ?? 0);
check('PO created (staff, submitted -> Ordered)', $r['ok'] && po_status($po) === 'pending', $r['message']);
check('inventory unchanged after PO', stock($pid) === 20 && stock($vpid, $v1) === 0);
check('total amount computed (50*180 + 10*60.50 = 9605.00)', (float) one("SELECT total_amount FROM tbl_supplier_deliveries WHERE id = $po") === 9605.0);
check('PO lines reference real product + variant', (int) one("SELECT COUNT(*) FROM tbl_purchase_order_items WHERE po_id = $po") === 2 && (int) po_item($po, $vpid, $v1)['variant_id'] === $v1);
$bad = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$vpid:0", 'qty' => '5', 'cost' => '1']]], false);
check('PO rejects a product with sizes but no size chosen', !$bad['ok']);
$bad = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '0', 'cost' => '1']]], false);
check('PO rejects quantity 0', !$bad['ok']);
q("UPDATE tbl_suppliers SET is_active = 0 WHERE id = $sid");
$bad = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '1', 'cost' => '1']]], false);
check('PO rejects an inactive supplier', !$bad['ok']);
q("UPDATE tbl_suppliers SET is_active = 1 WHERE id = $sid");

echo "== Test 2: mark In Transit (no stock change)\n";
$r = purch_change_status($conn, $staff, $po, 'mark_in_transit');
check('marked In Transit', $r['ok'] && po_status($po) === 'in_transit', $r['message']);
check('inventory unchanged in transit', stock($pid) === 20);
$r = purch_change_status($conn, $staff, $po, 'mark_in_transit');
check('invalid transition refused (in_transit -> in_transit)', !$r['ok']);
$r = purch_change_status($conn, $staff, $po, 'cancel', 'nope');
check('staff cannot cancel', !$r['ok']);

echo "== Test 3: receive 35 of 50\n";
$item = (int) po_item($po, $pid)['id'];
$t3 = purch_new_token();
$r = purch_receive($conn, $staff, $po, ['token' => $t3, 'verified' => 1, 'received_at' => date('Y-m-d H:i'), 'lines' => [$item => ['accepted' => 35, 'rejected' => 0, 'unit_cost' => '180', 'lot' => 'LOT-A']]]);
check('receipt recorded', $r['ok'] && empty($r['duplicate']), $r['message']);
check('inventory +35 only (20 -> 55)', stock($pid) === 55, 'stock=' . stock($pid));
check('PO line received = 35, outstanding 15', (int) po_item($po, $pid)['qty_received'] === 35);
check('status Partially Received', po_status($po) === 'partially_received');
invariant('after receiving 35');
$r = purch_receive($conn, $staff, $po, ['token' => purch_new_token(), 'lines' => [$item => ['accepted' => 1, 'unit_cost' => '180']]]);
check('receiving without physical verification refused', !$r['ok']);
$r = purch_receive($conn, $staff, $po, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [$item => ['accepted' => 16, 'unit_cost' => '180']]]);
check('over-receiving refused (16 > 15 outstanding)', !$r['ok'] && stock($pid) === 55, $r['message']);
$r = purch_receive($conn, $staff, $po, ['token' => purch_new_token(), 'verified' => 1, 'received_at' => date('Y-m-d H:i', strtotime('+2 days')), 'lines' => [$item => ['accepted' => 1, 'unit_cost' => '180']]]);
check('future receiving date refused', !$r['ok']);

echo "== Test 6: the same receipt submitted twice\n";
$r = purch_receive($conn, $staff, $po, ['token' => $t3, 'verified' => 1, 'lines' => [$item => ['accepted' => 15, 'unit_cost' => '180']]]);
check('repeat of the same token is a no-op', $r['ok'] && !empty($r['duplicate']) && stock($pid) === 55, $r['message']);
// Two simultaneous submits of one form (same token) from two processes.
$tok = purch_new_token();
$php = PHP_BINARY;
$env = array_merge(getenv(), ['DB_NAME' => getenv('DB_NAME')]);
$procs = [];
foreach ([1, 2] as $n) {
    $procs[$n] = proc_open([$php, __FILE__, '--receive', (string) $po, (string) $item, '5', $tok], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n], null, $env);
}
$outs = [];
foreach ([1, 2] as $n) {
    $outs[$n] = trim(stream_get_contents($pipes[$n][1]));
    fclose($pipes[$n][1]);
    fclose($pipes[$n][2]);
    proc_close($procs[$n]);
}
$dups = array_sum(array_map(fn($o) => !empty(json_decode(substr($o, strrpos($o, '{')), true)['duplicate']) ? 1 : 0, $outs));
check('two simultaneous submits add stock once (55 -> 60)', stock($pid) === 60 && $dups === 1, 'stock=' . stock($pid) . ' outs=' . implode(' | ', $outs));
check('only one receipt exists for that token', (int) one("SELECT COUNT(*) FROM tbl_stock_receipts WHERE request_token = '$tok'") === 1);
invariant('after concurrent submits');

echo "== Test 4: receive the remaining 10 (35 + 5 + 10 = 50)\n";
$exp = date('Y-m-d', strtotime('+20 days'));
$r = purch_receive($conn, $staff, $po, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [
    $item => ['accepted' => 10, 'unit_cost' => '180', 'expiry' => $exp, 'lot' => 'LOT-B'],
    (int) po_item($po, $vpid, $v1)['id'] => ['accepted' => 10, 'unit_cost' => '60.50'],
]]);
check('second receipt recorded', $r['ok'], $r['message']);
check('total increase from this PO is exactly 50 (20 -> 70)', stock($pid) === 70, 'stock=' . stock($pid));
check('variant stock received (0 -> 10)', stock($vpid, $v1) === 10);
check('status Received', po_status($po) === 'delivered');
$r = purch_receive($conn, $staff, $po, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [$item => ['accepted' => 1, 'unit_cost' => '1']]]);
check('a fully received PO cannot receive more', !$r['ok']);
invariant('after full receipt');

echo "== Test 5: different purchase cost -> separate batch\n";
$costs = array_map('floatval', array_column(q("SELECT unit_cost FROM tbl_inventory_batches WHERE product_id = $pid AND status = 'available' ORDER BY id"), 'unit_cost'));
check('old batch keeps 150, new batches 180', $costs[0] === 150.0 && count(array_filter($costs, fn($c) => $c === 180.0)) === 3, json_encode($costs));
check('no duplicate product created', (int) one("SELECT COUNT(*) FROM tbl_product_inventory WHERE name = 'ZZ TEST Cat Litter 7kg'") === 1);
check('each batch linked to supplier + receipt line', (int) one("SELECT COUNT(*) FROM tbl_inventory_batches WHERE product_id = $pid AND (supplier_id <> $sid OR receipt_item_id IS NULL)") === 0);
check('selling price unchanged by cost increase', (string) one("SELECT price FROM tbl_product_inventory WHERE id = $pid") === $price_before);

echo "== Test 7: expiration date stored on the batch\n";
check('batch LOT-B has expiry ' . $exp, (string) one("SELECT expiry_date FROM tbl_inventory_batches WHERE product_id = $pid AND lot_number = 'LOT-B'") === $exp);
$r = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '5', 'cost' => '180']]], true);
$poX = (int) $r['po_id'];
$r = purch_receive($conn, $staff, $poX, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poX, $pid)['id'] => ['accepted' => 5, 'unit_cost' => '180', 'expiry' => date('Y-m-d', strtotime('-1 day'))]]]);
check('accepting already-expired units is refused', !$r['ok'], $r['message']);

echo "== Test 8: reject damaged items\n";
$r = purch_receive($conn, $staff, $poX, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poX, $pid)['id'] => ['accepted' => 3, 'rejected' => 2, 'unit_cost' => '180']]]);
check('rejected units need a reason', !$r['ok']);
$before = stock($pid);
$r = purch_receive($conn, $staff, $poX, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poX, $pid)['id'] => ['accepted' => 3, 'rejected' => 2, 'reject_reason' => 'damaged', 'unit_cost' => '180']]]);
check('only accepted units added (+3, not +5)', $r['ok'] && stock($pid) === $before + 3, $r['message']);
$ri = q("SELECT qty_accepted, qty_rejected, reject_reason FROM tbl_stock_receipt_items WHERE receipt_id = " . (int) $r['receipt_id'])[0];
check('accepted and rejected both preserved on the receipt', (int) $ri['qty_accepted'] === 3 && (int) $ri['qty_rejected'] === 2 && $ri['reject_reason'] === 'damaged');
check('rejected units stay outstanding (2) -> Partially Received', po_status($poX) === 'partially_received' && (int) po_item($poX, $pid)['qty_received'] === 3);
$r = purch_change_status($conn, $admin, $poX, 'close_short', 'Supplier will not resend');
check('admin closes with shortage', $r['ok'] && po_status($poX) === 'closed_short');
invariant('after rejecting');

echo "== Test 9: receiving history + cost history\n";
$hist = purch_list_receipts($conn, $po);
check('receiving history lists the PO receipts', count($hist) === 3 && $hist[0]['receipt_number'] !== null && $hist[0]['received_by_name'] !== '');
$ch = array_values(array_filter(purch_cost_history($conn), fn($c) => $c['key'] === "$pid:0"));
$first180 = array_values(array_filter($ch, fn($c) => $c['prev_cost'] === 150.0));
check('cost history: 150 -> 180 = +30.00 / +20%', $first180 && $first180[0]['diff'] === 30.0 && $first180[0]['pct'] === 20.0, json_encode($first180[0] ?? null));
$prof = purch_supplier_profile($conn, $sid);
check('supplier profile totals', $prof['received_deliveries'] >= 4 && $prof['units_rejected'] === 2 && $prof['total_purchase_cost'] > 0, json_encode(['r' => $prof['received_deliveries'], 'rej' => $prof['units_rejected']]));

echo "== Test 10: customer ordering / stock deduction still works (FEFO)\n";
$s0 = stock($pid);
$ok = deduct_product_stock($pid, null, 4);
check('deduct_product_stock still deducts', $ok && stock($pid) === $s0 - 4);
$lotB = q("SELECT qty_remaining FROM tbl_inventory_batches WHERE product_id = $pid AND lot_number = 'LOT-B'")[0]['qty_remaining'];
check('FEFO: units taken from the batch expiring first (LOT-B, 10 -> 6)', (int) $lotB === 6, "LOT-B=$lotB");
check('deduct refuses more than stock', !deduct_product_stock($pid, null, 100000));
restore_product_stock($pid, null, 4);
check('cancel/restore puts the units back into the same batch', stock($pid) === $s0 && (int) one("SELECT qty_remaining FROM tbl_inventory_batches WHERE product_id = $pid AND lot_number = 'LOT-B'") === 10);
check('variant deduction', deduct_product_stock($vpid, $v1, 2) && stock($vpid, $v1) === 8);
invariant('after sales and restores');
// deduct_order_stock_once on a real order of the test copy still works and stays once-only
$ord = q("SELECT o.id FROM tbl_orders o WHERE o.stock_deducted = 0 AND EXISTS (SELECT 1 FROM tbl_order_items i WHERE i.order_id = o.id) LIMIT 1");
if ($ord) {
    $oid = (int) $ord[0]['id'];
    $conn->begin_transaction();
    $res = deduct_order_stock_once($conn, $oid);
    $conn->commit();
    $again = deduct_order_stock_once($conn, $oid);
    check('deduct_order_stock_once works and is once-only', $res['ok'] === (bool) $res['ok'] && $again['ok'], json_encode($res));
    invariant('after deduct_order_stock_once');
}

echo "== Extra: quarantine, release, expiry job, reversal, legacy, inventory_move, variant re-create\n";
$r = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '6', 'cost' => '175']]], true);
$poH = (int) $r['po_id'];
$s0 = stock($pid);
$r = purch_receive($conn, $staff, $poH, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poH, $pid)['id'] => ['accepted' => 6, 'unit_cost' => '175', 'hold' => 1]]]);
check('held (quarantined) units do not become sellable', $r['ok'] && stock($pid) === $s0, $r['message']);
$hb = (int) one("SELECT id FROM tbl_inventory_batches WHERE receipt_id = " . (int) $r['receipt_id']);
$rel = inv_batch_set_status($conn, $hb, 'available', 'Inspected OK', $admin['user_id']);
check('releasing adds them to stock', $rel['ok'] && stock($pid) === $s0 + 6, $rel['message']);
$dmg = inv_batch_set_status($conn, $hb, 'damaged', 'Water damage', $admin['user_id']);
check('writing off a batch removes it from stock', $dmg['ok'] && stock($pid) === $s0);
invariant('after quarantine / release / write-off');
// Expiry job: push LOT-B's expiry into the past
q("UPDATE tbl_inventory_batches SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE product_id = $pid AND lot_number = 'LOT-B'");
$s0 = stock($pid);
$n = inv_expire_due_batches($conn);
check('expired batch taken off sale automatically (-10)', $n >= 1 && stock($pid) === $s0 - 10 && one("SELECT status FROM tbl_inventory_batches WHERE lot_number = 'LOT-B' AND product_id = $pid") === 'expired', "n=$n stock=" . stock($pid));
$eb = (int) one("SELECT id FROM tbl_inventory_batches WHERE lot_number = 'LOT-B' AND product_id = $pid");
check('expired batch cannot be released', !inv_batch_set_status($conn, $eb, 'available', 'x', $admin['user_id'])['ok']);
invariant('after expiry job');
// Reversal
$r = purch_save_po($conn, $admin, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '4', 'cost' => '170']]], true);
$poR = (int) $r['po_id'];
$s0 = stock($pid);
$rr = purch_receive($conn, $staff, $poR, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poR, $pid)['id'] => ['accepted' => 4, 'unit_cost' => '170', 'expiry' => date('Y-m-d', strtotime('+2 days'))]]]);
check('staff cannot reverse', !purch_reverse_receipt($conn, $staff, (int) $rr['receipt_id'], 'wrong PO')['ok']);
check('reversal needs a reason', !purch_reverse_receipt($conn, $admin, (int) $rr['receipt_id'], '')['ok']);
$rv = purch_reverse_receipt($conn, $admin, (int) $rr['receipt_id'], 'Recorded on the wrong PO');
check('admin reversal takes the units back out and reopens the PO', $rv['ok'] && stock($pid) === $s0 && po_status($poR) === 'pending' && (int) po_item($poR, $pid)['qty_received'] === 0, $rv['message']);
check('reversed receipt is kept (not deleted)', one("SELECT status FROM tbl_stock_receipts WHERE id = " . (int) $rr['receipt_id']) === 'reversed');
check('reversing twice refused', !purch_reverse_receipt($conn, $admin, (int) $rr['receipt_id'], 'again again')['ok']);
$rr2 = purch_receive($conn, $staff, $poR, ['token' => purch_new_token(), 'verified' => 1, 'lines' => [(int) po_item($poR, $pid)['id'] => ['accepted' => 4, 'unit_cost' => '170', 'expiry' => date('Y-m-d', strtotime('+1 day'))]]]);
deduct_product_stock($pid, null, 1); // FEFO sells from the batch expiring tomorrow
check('cannot reverse once units were sold', !purch_reverse_receipt($conn, $admin, (int) $rr2['receipt_id'], 'too late now')['ok']);
invariant('after reversal tests');
// inventory_move (Products > Inventory) stays in step
$s0 = stock($pid);
$m = inventory_move($conn, $admin['user_id'], $pid, 0, 'add', 7, 'count_correction', '');
check('Products add-stock still works and creates an adjustment batch', $m['ok'] && stock($pid) === $s0 + 7 && (int) one("SELECT COUNT(*) FROM tbl_inventory_batches WHERE product_id = $pid AND source = 'adjustment'") >= 1, $m['message']);
$m = inventory_move($conn, $admin['user_id'], $pid, 0, 'remove', 3, 'damaged', '');
check('Products remove-stock still works', $m['ok'] && stock($pid) === $s0 + 4);
invariant('after inventory_move');
// direct stock edit (product card) is reconciled by the maintenance sync
q("UPDATE tbl_product_inventory SET stock = stock + 2 WHERE id = $pid");
inv_batches_reconcile_all($conn);
invariant('after a direct stock edit + reconcile');
// product edit re-creates variants with new ids: history follows the size label
$oldv = $v1;
q("DELETE FROM tbl_product_variants WHERE product_id = $vpid");
q("INSERT INTO tbl_product_variants (product_id, size, price, stock, low_stock_threshold) VALUES ($vpid, '1kg', 100, 8, 0), ($vpid, '5kg', 450, 0, 0)");
$v1 = (int) one("SELECT id FROM tbl_product_variants WHERE product_id = $vpid AND size = '1kg'");
inv_batches_reconcile_all($conn);
check('variant re-created: batches remapped to the new id, no extra batch', (int) one("SELECT COUNT(*) FROM tbl_inventory_batches WHERE variant_id = $oldv") === 0
    && batch_sum($vpid, $v1) === 8 && (int) one("SELECT COUNT(*) FROM tbl_inventory_batches WHERE product_id = $vpid AND source <> 'receipt'") === 0);
invariant('after variant re-create');
// Legacy records
$leg = q("SELECT id, status FROM tbl_supplier_deliveries WHERE is_legacy = 1 AND status IN ('pending','in_transit') LIMIT 1");
if ($leg) {
    $lid = (int) $leg[0]['id'];
    $r = purch_receive($conn, $staff, $lid, ['token' => purch_new_token(), 'verified' => 1, 'lines' => []]);
    check('legacy record cannot be received before linking', !$r['ok']);
    $s0 = stock($pid);
    $r = purch_link_legacy($conn, $admin, $lid, ['items' => [['item' => "$pid:0", 'qty' => '50', 'cost' => '160']]]);
    check('legacy pending record linked -> normal PO, no stock added', $r['ok'] && stock($pid) === $s0 && (int) one("SELECT is_legacy FROM tbl_supplier_deliveries WHERE id = $lid") === 0, $r['message']);
    $orig = array_values(array_filter($legacy_before, fn($x) => (int) $x['id'] === $lid))[0]['items_summary'];
    check('legacy free text preserved', (string) one("SELECT items_summary FROM tbl_supplier_deliveries WHERE id = $lid") === (string) $orig);
}
q("INSERT INTO tbl_supplier_deliveries (supplier_id, supplier_name, items_summary, status, received_date, is_legacy) VALUES ($sid, 'ZZ TEST Supplier', '30x Cat Litter 7kg', 'delivered', NOW(), 1)");
$ld = (int) $conn->insert_id;
q("UPDATE tbl_supplier_deliveries SET po_number = CONCAT('PO-LEG-', id) WHERE id = $ld");
$s0 = stock($pid);
check('delivered legacy link requires history confirmation', !purch_link_legacy($conn, $admin, $ld, ['items' => [['item' => "$pid:0", 'qty' => '30', 'cost' => '150']]])['ok']);
$r = purch_link_legacy($conn, $admin, $ld, ['items' => [['item' => "$pid:0", 'qty' => '30', 'cost' => '150']], 'confirm_history' => 1]);
check('delivered legacy -> history-only receipt, stock NOT added again', $r['ok'] && stock($pid) === $s0
    && (int) one("SELECT stock_applied FROM tbl_stock_receipts WHERE po_id = $ld") === 0, $r['message']);
$r = purch_link_legacy($conn, $admin, $ld, ['items' => [['item' => "$pid:0", 'qty' => '30', 'cost' => '150']], 'confirm_history' => 1]);
check('linking twice does nothing', $r['ok'] && (int) one("SELECT COUNT(*) FROM tbl_stock_receipts WHERE po_id = $ld") === 1);
// drafts + cancel
$r = purch_save_po($conn, $staff, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '2', 'cost' => '1']]], false);
$pd = (int) $r['po_id'];
check('draft saved', $r['ok'] && po_status($pd) === 'draft');
$r = purch_save_po($conn, $staff, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '3', 'cost' => '2']]], false, $pd);
check('draft edited (lines replaced)', $r['ok'] && (int) po_item($pd, $pid)['qty_ordered'] === 3);
check('draft cannot be received', !purch_receive($conn, $staff, $pd, ['token' => purch_new_token(), 'verified' => 1, 'lines' => []])['ok']);
$r = purch_change_status($conn, $admin, $pd, 'cancel', 'Not needed');
check('admin cancels a draft', $r['ok'] && po_status($pd) === 'cancelled');
check('cancelled PO cannot be edited', !purch_save_po($conn, $staff, ['supplier_id' => $sid, 'items' => [['item' => "$pid:0", 'qty' => '3', 'cost' => '2']]], false, $pd)['ok']);
check('partially received PO cannot be cancelled', !purch_change_status($conn, $admin, $poX, 'cancel', 'xx')['ok']);
invariant('final');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
