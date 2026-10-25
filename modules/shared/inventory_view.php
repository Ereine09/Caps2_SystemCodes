<?php
/**
 * Inventory view of the Products page (products.php?view=inventory):
 * one table row per product (or per variant, for products with sizes/flavors) with
 * + Add stock, Adjust (remove or set the exact count) and History.
 *
 * Every change goes through inventory_move(): the row is locked, the new count can never go below
 * zero, the stock alert level is recomputed (low / critical / out), and the change is written to
 * tbl_stock_movements (who, when, before -> after, reason, note) and the Activity Logs.
 */
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/inventory_batch_helper.php';

function inventory_reasons(): array
{
    return [
        'add' => [
            'supplier_delivery' => 'Supplier delivery received',
            'customer_return'   => 'Customer return (resellable)',
            'count_correction'  => 'Stock count correction',
            'other'             => 'Other',
        ],
        'remove' => [
            'damaged'          => 'Damaged',
            'expired'          => 'Expired',
            'lost'             => 'Lost / missing',
            'store_use'        => 'Used in store / giveaway',
            'count_correction' => 'Stock count correction',
            'other'            => 'Other',
        ],
        'set' => [
            'count_correction' => 'Physical stock count',
            'other'            => 'Other',
        ],
    ];
}

function inventory_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        variant_id INT NULL,
        change_qty INT NOT NULL,
        stock_before INT NOT NULL,
        stock_after INT NOT NULL,
        reason VARCHAR(40) NOT NULL,
        note VARCHAR(255) NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_move_item (product_id, variant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Changes one item's stock. $mode: add | remove | set. Returns ['ok', 'message'].
 * $variant_id = 0 for the product's own stock.
 */
function inventory_move(mysqli $conn, int $user_id, int $product_id, int $variant_id, string $mode, int $qty, string $reason, string $note): array
{
    inventory_ensure_schema($conn);
    $reasons = inventory_reasons();
    if (!isset($reasons[$mode])) {
        return ['ok' => false, 'message' => 'Choose Add, Remove or Set.'];
    }
    if (!isset($reasons[$mode][$reason])) {
        return ['ok' => false, 'message' => 'Please choose a reason.'];
    }
    $note = mb_substr(trim($note), 0, 255);
    if ($reason === 'other' && $note === '') {
        return ['ok' => false, 'message' => 'Please write a note for "Other".'];
    }
    if ($mode === 'set' ? $qty < 0 : $qty <= 0) {
        return ['ok' => false, 'message' => $mode === 'set' ? 'The count cannot be negative.' : 'Enter a quantity of at least 1.'];
    }
    if ($qty > 100000) {
        return ['ok' => false, 'message' => 'That quantity is too large.'];
    }

    $table = $variant_id > 0 ? 'tbl_product_variants' : 'tbl_product_inventory';
    $conn->begin_transaction();
    try {
        if ($variant_id > 0) {
            $s = $conn->prepare("SELECT v.stock, v.size, p.name FROM tbl_product_variants v JOIN tbl_product_inventory p ON p.id = v.product_id
                                 WHERE v.id = ? AND v.product_id = ? FOR UPDATE");
            $s->bind_param('ii', $variant_id, $product_id);
        } else {
            $s = $conn->prepare("SELECT stock, NULL AS size, name FROM tbl_product_inventory WHERE id = ? FOR UPDATE");
            $s->bind_param('i', $product_id);
        }
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        $before = (int) $row['stock'];
        $after = $mode === 'add' ? $before + $qty : ($mode === 'remove' ? $before - $qty : $qty);
        $label = $row['name'] . ($row['size'] ? ' (' . $row['size'] . ')' : '');
        if ($after < 0) {
            $conn->rollback();
            return ['ok' => false, 'message' => "Only $before in stock for $label - you cannot remove $qty."];
        }
        if ($after === $before) {
            $conn->rollback();
            return ['ok' => true, 'message' => "Stock for $label is already $after; nothing changed."];
        }

        if ($variant_id > 0) {
            $u = $conn->prepare("UPDATE tbl_product_variants SET stock = ? WHERE id = ?");
            $u->bind_param('ii', $after, $variant_id);
            $u->execute();
            $u->close();
            $t = $conn->prepare("UPDATE tbl_product_inventory SET updated_at = NOW() WHERE id = ?");
            $t->bind_param('i', $product_id);
            $t->execute();
            $t->close();
        } else {
            $u = $conn->prepare("UPDATE tbl_product_inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
            $u->bind_param('ii', $after, $product_id);
            $u->execute();
            $u->close();
        }
        $change = $after - $before;
        $vid = $variant_id > 0 ? $variant_id : null;
        $note_db = $note !== '' ? $note : null;
        $m = $conn->prepare("INSERT INTO tbl_stock_movements (product_id, variant_id, change_qty, stock_before, stock_after, reason, note, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $m->bind_param('iiiiissi', $product_id, $vid, $change, $before, $after, $reason, $note_db, $user_id);
        $m->execute();
        $m->close();
        // Removed units come out of the item's batches FEFO; added units go to an adjustment batch.
        inv_batch_sync($conn, $product_id, $variant_id, 'adjust', $reasons[$mode][$reason] . ($note !== '' ? ' - ' . $note : ''), $user_id, 'stock_move', null);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('inventory_move failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the stock. Please try again.'];
    }

    evaluate_stock_alert($conn, $table, $variant_id > 0 ? $variant_id : $product_id);
    $reason_label = $reasons[$mode][$reason];
    $details = "Stock of $label: $before -> $after (" . ($change > 0 ? '+' : '') . "$change). Reason: $reason_label" . ($note !== '' ? " - $note" : '') . '.';
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Updated Stock', ?)");
    $log->bind_param('is', $user_id, $details);
    $log->execute();
    $log->close();

    return ['ok' => true, 'message' => "$label: stock $before → $after (" . ($change > 0 ? '+' : '') . "$change)."];
}

/** Records a stock change made elsewhere on the page (e.g. the quick +/- box on a product card). */
function inventory_log(mysqli $conn, int $user_id, int $product_id, int $variant_id, int $before, int $after, string $reason, string $note = ''): void
{
    inventory_ensure_schema($conn);
    $change = $after - $before;
    $vid = $variant_id > 0 ? $variant_id : null;
    $note_db = $note !== '' ? $note : null;
    $m = $conn->prepare("INSERT INTO tbl_stock_movements (product_id, variant_id, change_qty, stock_before, stock_after, reason, note, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $m->bind_param('iiiiissi', $product_id, $vid, $change, $before, $after, $reason, $note_db, $user_id);
    $m->execute();
    $m->close();
}

/** POST action=stock_move from the Inventory view. Redirects back. Returns false when it is not ours. */
function inventory_handle_post(mysqli $conn, int $user_id): bool
{
    if (($_POST['action'] ?? '') !== 'stock_move') {
        return false;
    }
    $back = 'products.php?' . preg_replace('/[^A-Za-z0-9_\-\.%&=\[\]+]/', '', (string) ($_POST['return_qs'] ?? 'view=inventory'));
    if (!csrf_check()) {
        $_SESSION['message'] = 'Your session expired. Please try again.';
        $_SESSION['message_type'] = 'error';
    } else {
        $r = inventory_move($conn, $user_id, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['variant_id'] ?? 0),
            (string) ($_POST['mode'] ?? ''), (int) ($_POST['qty'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['note'] ?? ''));
        $_SESSION['message'] = $r['message'];
        $_SESSION['message_type'] = $r['ok'] ? 'success' : 'error';
    }
    header('Location: ' . $back);
    exit();
}

/** GET ?stock_history=<product id>&variant=<id>: the item's last 50 movements as JSON. */
function inventory_handle_history(mysqli $conn): void
{
    if (!isset($_GET['stock_history'])) {
        return;
    }
    inventory_ensure_schema($conn);
    $pid = (int) $_GET['stock_history'];
    $vid = (int) ($_GET['variant'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT m.change_qty, m.stock_before, m.stock_after, m.reason, m.note, m.created_at,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'System') AS who
         FROM tbl_stock_movements m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.product_id = ? AND COALESCE(m.variant_id, 0) = ?
         ORDER BY m.created_at DESC, m.id DESC LIMIT 50"
    );
    $stmt->bind_param('ii', $pid, $vid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $labels = [];
    foreach (inventory_reasons() as $set) {
        $labels += $set;
    }
    $labels['card_edit'] = 'Quick edit on product card';
    $labels += [
        'receipt_reversal' => 'Supplier receipt reversed',
        'batch_quarantine' => 'Batch put on hold (quarantine)',
        'batch_release'    => 'Batch released for sale',
        'batch_expired'    => 'Batch expired',
        'batch_damaged'    => 'Batch written off: damaged',
        'batch_recalled'   => 'Batch recalled',
    ];
    foreach ($rows as &$r) {
        $r['reason_label'] = $labels[$r['reason']] ?? $r['reason'];
        $r['when'] = date('M j, Y g:i A', strtotime($r['created_at']));
    }
    unset($r);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $rows]);
    exit();
}

/** One row per product without variants, one per variant otherwise. */
function inventory_rows(mysqli $conn, array $products): array
{
    inventory_ensure_schema($conn);
    $last = [];
    $res = $conn->query("SELECT product_id, COALESCE(variant_id, 0) AS vid, MAX(created_at) AS at FROM tbl_stock_movements GROUP BY product_id, vid");
    while ($res && ($r = $res->fetch_assoc())) {
        $last[$r['product_id'] . ':' . $r['vid']] = $r['at'];
    }
    $rows = [];
    foreach ($products as $p) {
        $variants = get_product_variants((int) $p['id']);
        $cat = trim(explode(',', (string) ($p['category'] ?? 'General'))[0]);
        if (!$variants) {
            $variants = [null];
        }
        foreach ($variants as $v) {
            $vid = $v ? (int) $v['id'] : 0;
            $stock = (int) ($v ? $v['stock'] : $p['stock']);
            $low = (int) ($v ? ($v['low_stock_threshold'] ?? 10) : ($p['low_stock_threshold'] ?? 10));
            $level = (string) ($v ? ($v['stock_alert_level'] ?? 'normal') : ($p['stock_alert_level'] ?? 'normal'));
            $status = $stock <= 0 ? 'out' : (in_array($level, ['low', 'critical'], true) ? $level : ($stock <= $low ? 'low' : 'normal'));
            $rows[] = [
                'product_id' => (int) $p['id'],
                'variant_id' => $vid,
                'name'       => (string) $p['name'],
                'variant'    => $v ? (string) $v['size'] : '',
                'image'      => (string) (($v['image_url'] ?? '') ?: ($p['image_url'] ?? '')),
                'sku'        => (string) ($p['sku'] ?? ''),
                'category'   => $cat,
                'price'      => (float) ($v ? $v['price'] : $p['price']),
                'stock'      => $stock,
                'low'        => $low,
                'status'     => $status,
                'updated'    => $last[$p['id'] . ':' . $vid] ?? ($p['updated_at'] ?? $p['created_at'] ?? null),
            ];
        }
    }
    return $rows;
}

function inventory_html(mysqli $conn, array $products, string $return_qs): string
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rows = inventory_rows($conn, $products);

    // status filter (?istatus=) on top of the page's own search / category / stock filters
    $istatus = (string) ($_GET['istatus'] ?? '');
    if (in_array($istatus, ['normal', 'low', 'critical', 'out'], true)) {
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $istatus || ($istatus === 'low' && $r['status'] === 'critical')));
    }
    $total_units = array_sum(array_column($rows, 'stock'));
    $badge = ['normal' => ['success', 'In Stock'], 'low' => ['warning', 'Low Stock'], 'critical' => ['danger', 'Critical'], 'out' => ['danger', 'Out of Stock']];

    $out = '<section class="inv" id="inventory">';
    $out .= '<form method="GET" class="inv-toolbar">'
        . '<input type="hidden" name="view" value="inventory">'
        . '<label class="inv-search"><i class="fas fa-search"></i><input type="search" name="search" value="' . $h($_GET['search'] ?? '') . '" placeholder="Search product or SKU..."></label>'
        . '<select name="istatus" onchange="this.form.submit()" aria-label="Stock status">'
        . '<option value="">All stock statuses</option>';
    foreach (['normal' => 'In Stock', 'low' => 'Low / Critical', 'critical' => 'Critical only', 'out' => 'Out of Stock'] as $k => $l) {
        $out .= '<option value="' . $k . '"' . ($istatus === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    $out .= '</select><span class="inv-count">' . count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' · ' . number_format($total_units) . ' units in stock</span></form>';

    if (!$rows) {
        return $out . '<div class="inv-empty"><i class="fas fa-box-open"></i><p>No products match these filters.</p></div></section>';
    }

    // Most urgent first: Out of Stock / Critical, then Low Stock, then In Stock. A product ranks by
    // its worst variant so its rows stay together; ties keep the existing (name) order.
    $rank = ['out' => 0, 'critical' => 0, 'low' => 1, 'normal' => 2];
    $product_rank = [];
    foreach ($rows as $r) {
        $product_rank[$r['product_id']] = min($product_rank[$r['product_id']] ?? 2, $rank[$r['status']]);
    }
    $order = array_flip(array_values(array_unique(array_column($rows, 'product_id'))));
    usort($rows, fn($a, $b) => [$product_rank[$a['product_id']], $order[$a['product_id']]] <=> [$product_rank[$b['product_id']], $order[$b['product_id']]]);

    // 10 products per page (?page=); a product's variant rows stay together on one page.
    $product_ids = array_values(array_unique(array_column($rows, 'product_id')));
    $pg = ui_paginate(count($product_ids), (int) ($_GET['page'] ?? 1), 10);
    $page_ids = array_flip(array_slice($product_ids, $pg['offset'], $pg['per_page']));
    $rows = array_values(array_filter($rows,fn($r) => isset($page_ids[$r['product_id']])));

    $out .= '<div class="inv-table-wrap"><table class="inv-table"><thead><tr>'
        . '<th>Product</th><th>SKU</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th class="num" title="Low stock alert level">Alert at</th><th>Status</th><th>Updated</th><th class="inv-actions-h">Actions</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        [$tone, $label] = $badge[$r['status']];
        $img = $r['image'] !== '' ? (preg_match('#^https?://#', $r['image']) ? $r['image'] : BASE_URL . '/' . ltrim($r['image'], '/')) : '';
        $name = $r['name'] . ($r['variant'] !== '' ? ' — ' . $r['variant'] : '');
        $data = ' data-pid="' . $r['product_id'] . '" data-vid="' . $r['variant_id'] . '" data-name="' . $h($name) . '" data-stock="' . $r['stock'] . '"';
        $out .= '<tr' . $data . '>'
            . '<td><div class="inv-prod">' . ($img ? '<img src="' . $h($img) . '" alt="" loading="lazy">' : '<span class="inv-noimg"><i class="fas fa-box"></i></span>')
                . '<span><strong>' . $h($r['name']) . '</strong>' . ($r['variant'] !== '' ? '<small>' . $h($r['variant']) . '</small>' : '') . '</span></div></td>'
            . '<td><code class="inv-sku">' . $h($r['sku'] ?: '—') . '</code></td>'
            . '<td>' . $h($r['category']) . '</td>'
            . '<td class="num">₱' . number_format($r['price'], 2) . '</td>'
            . '<td class="num"><strong class="inv-stock">' . number_format($r['stock']) . '</strong></td>'
            . '<td class="num">' . (int) $r['low'] . '</td>'
            . '<td><span class="ui-badge ui-badge-' . $tone . '">' . $label . '</span></td>'
            . '<td class="inv-when">' . ($r['updated'] ? $h(date('M j, g:i A', strtotime((string) $r['updated']))) : '—') . '</td>'
            . '<td class="inv-actions">'
                . '<button type="button" class="inv-btn inv-add" data-inv="add" title="Add stock"><i class="fas fa-plus"></i></button>'
                . '<button type="button" class="inv-btn" data-inv="remove" title="Adjust stock (remove or set the count)"><i class="fas fa-sliders-h"></i></button>'
                . '<button type="button" class="inv-btn inv-ghost" data-inv="history" title="Stock history"><i class="fas fa-history"></i></button>'
            . '</td></tr>';
    }
    $out .= '</tbody></table></div>';
    $out .= '<div class="ui-pager-bar"><span class="ui-pager-count">Showing ' . $pg['from'] . '&ndash;' . $pg['to'] . ' of ' . $pg['total'] . ' product' . ($pg['total'] === 1 ? '' : 's') . '</span>'
        . ui_pagination($pg['page'], $pg['pages'], static fn(int $p): string => ui_page_url(['page' => $p], 'inventory'), 'Inventory pages') . '</div>';

    // Add / Adjust dialog + History dialog
    $reasons = inventory_reasons();
    $out .= '<dialog class="inv-dialog" id="inv-move">'
        . '<form method="POST" id="inv-move-form">' . csrf_field()
        . '<input type="hidden" name="action" value="stock_move"><input type="hidden" name="return_qs" value="' . $h($return_qs) . '">'
        . '<input type="hidden" name="product_id"><input type="hidden" name="variant_id">'
        . '<h3 id="inv-move-title">Adjust stock</h3><p class="inv-item" id="inv-move-item"></p>'
        . '<div class="inv-modes" role="radiogroup">'
            . '<label><input type="radio" name="mode" value="add"> <i class="fas fa-plus"></i> Add</label>'
            . '<label><input type="radio" name="mode" value="remove"> <i class="fas fa-minus"></i> Remove</label>'
            . '<label><input type="radio" name="mode" value="set"> <i class="fas fa-equals"></i> Set count</label></div>'
        . '<div class="inv-qty"><button type="button" data-step="-1" aria-label="Less">−</button><input type="number" name="qty" min="0" value="1" required><button type="button" data-step="1" aria-label="More">+</button></div>'
        . '<p class="inv-preview" id="inv-preview"></p>'
        . '<label class="inv-field">Reason <select name="reason" required></select></label>'
        . '<label class="inv-field">Note <span id="inv-note-req" hidden>(required for Other)</span><input type="text" name="note" maxlength="255" placeholder="e.g. Delivery receipt #, damaged in storage"></label>'
        . '<div class="inv-dialog-actions"><button type="button" class="ui-btn" data-close>Cancel</button><button type="submit" class="ui-btn ui-btn-primary" id="inv-save"><i class="fas fa-check"></i> Save</button></div>'
        . '</form></dialog>'
        . '<dialog class="inv-dialog inv-history" id="inv-history"><h3><i class="fas fa-history"></i> Stock history</h3><p class="inv-item" id="inv-history-item"></p>'
        . '<div id="inv-history-body">Loading…</div><div class="inv-dialog-actions"><button type="button" class="ui-btn" data-close>Close</button></div></dialog>'
        . '<script>window.INV_REASONS = ' . json_encode($reasons) . ';</script>';

    return $out . '</section>';
}

function inventory_assets(): string
{
    return <<<'HTML'
<style>
    .inv-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; }
    .inv-search { position: relative; flex: 1 1 280px; }
    .inv-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .inv-search input { width: 100%; box-sizing: border-box; padding: 10px 12px 10px 36px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); }
    .inv-toolbar select { padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); }
    .inv-count { color: var(--muted); font-size: .85rem; margin-left: auto; }
    .inv-table-wrap { overflow-x: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; }
    .inv-table { width: 100%; border-collapse: collapse; border-spacing: 0; font-size: .86rem; }
    .inv-table th { text-align: left; padding: 10px 8px; font-size: .7rem; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); background: var(--surface-2); white-space: nowrap; }
    .inv-table th:first-child, .inv-table td:first-child { padding-left: 14px; }
    .inv-table th:last-child, .inv-table td:last-child { padding-right: 14px; }
    .inv-table td { padding: 9px 8px; border-top: 1px solid var(--border); vertical-align: middle; }
    .inv-table .num { text-align: right; font-variant-numeric: tabular-nums; }
    .inv-prod { display: flex; align-items: center; gap: 10px; min-width: 180px; }
    .inv-prod strong { font-size: .86rem; line-height: 1.3; }
    .inv-prod img, .inv-noimg { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); flex-shrink: 0; background: var(--surface-2); display: inline-flex; align-items: center; justify-content: center; color: var(--muted); }
    .inv-prod small { display: block; color: var(--muted); font-size: .78rem; }
    .inv-sku { font-size: .78rem; padding: 2px 6px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-2); white-space: nowrap; }
    .inv-stock { font-size: 1rem; }
    .inv-when { color: var(--muted); font-size: .8rem; }
    .inv-actions { white-space: nowrap; }
    .inv-btn { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; margin-left: 3px; font-size: .8rem; }
    .inv-btn:first-child { margin-left: 0; }
    .inv-btn:hover { border-color: var(--primary, #4f46e5); color: var(--primary, #4f46e5); }
    .inv-btn.inv-add { background: #15803d; border-color: #15803d; color: #fff; }
    .inv-btn.inv-ghost { border-color: transparent; background: transparent; }
    .inv-empty { text-align: center; padding: 40px; color: var(--muted); background: var(--surface); border: 1px solid var(--border); border-radius: 12px; }
    .inv-dialog { border: 1px solid var(--border); border-radius: 14px; padding: 22px; width: min(440px, calc(100vw - 32px)); background: var(--surface); color: var(--text); box-shadow: 0 20px 50px rgba(0,0,0,.25); }
    /* Centered on screen (the admin stylesheet's margin reset would otherwise pin it to the top-left) */
    .inv-dialog[open] { position: fixed; inset: 0; margin: auto !important; height: fit-content; max-height: calc(100vh - 32px); overflow: auto; box-sizing: border-box; }
    .inv-dialog::backdrop { background: rgba(15,23,42,.45); }
    .inv-qty input::-webkit-outer-spin-button, .inv-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .inv-qty input { -moz-appearance: textfield; appearance: textfield; padding: 8px 0; }
    .inv-dialog h3 { margin: 0 0 4px; }
    .inv-item { margin: 0 0 14px; color: var(--muted); font-size: .9rem; }
    .inv-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 12px; }
    .inv-modes label { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; font-weight: 600; font-size: .88rem; }
    .inv-modes input { position: absolute; opacity: 0; }
    .inv-modes label:has(input:checked) { border-color: var(--primary, #4f46e5); background: var(--primary-soft, #eef2ff); color: var(--primary, #4f46e5); }
    .inv-qty { display: flex; align-items: stretch; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .inv-qty button { width: 46px; border: 0; background: var(--surface-2); color: var(--text); font-size: 1.2rem; cursor: pointer; }
    .inv-qty input { flex: 1; min-width: 0; border: 0; text-align: center; font-size: 1.15rem; font-weight: 700; background: var(--surface); color: var(--text); }
    .inv-preview { margin: 8px 0 12px; font-size: .88rem; color: var(--muted); }
    .inv-preview.is-bad { color: var(--danger); font-weight: 600; }
    .inv-field { display: grid; gap: 4px; margin-bottom: 10px; font-size: .85rem; font-weight: 600; }
    .inv-field select, .inv-field input { padding: 9px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-weight: 400; }
    .inv-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
    .inv-history { width: min(620px, calc(100vw - 32px)); }
    .inv-hist-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .inv-hist-table th, .inv-hist-table td { padding: 8px; border-top: 1px solid var(--border); text-align: left; vertical-align: top; }
    .inv-hist-table small { display: block; color: var(--muted); }
    .inv-up { color: #15803d; font-weight: 700; } .inv-down { color: var(--danger); font-weight: 700; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var moveDlg = document.getElementById('inv-move');
    if (!moveDlg) return;
    var form = document.getElementById('inv-move-form'), histDlg = document.getElementById('inv-history');
    var qty = form.qty, reasonSel = form.reason, preview = document.getElementById('inv-preview'), save = document.getElementById('inv-save');
    var current = null;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function mode() { var m = form.querySelector('input[name="mode"]:checked'); return m ? m.value : 'add'; }
    function fillReasons() {
        var r = (window.INV_REASONS || {})[mode()] || {};
        reasonSel.innerHTML = '<option value="">Choose a reason…</option>' + Object.keys(r).map(function (k) { return '<option value="' + k + '">' + esc(r[k]) + '</option>'; }).join('');
    }
    function update() {
        var m = mode(), q = parseInt(qty.value, 10) || 0, s = current.stock;
        var after = m === 'add' ? s + q : (m === 'remove' ? s - q : q);
        var bad = after < 0 || (m !== 'set' && q <= 0) || q < 0;
        preview.textContent = bad ? (after < 0 ? 'Only ' + s + ' in stock - you cannot remove ' + q + '.' : 'Enter a quantity.') : 'Stock: ' + s + ' → ' + after + (after !== s ? ' (' + (after > s ? '+' : '') + (after - s) + ')' : ' (no change)');
        preview.classList.toggle('is-bad', bad);
        save.disabled = bad || after === s;
        document.getElementById('inv-note-req').hidden = reasonSel.value !== 'other';
        form.note.required = reasonSel.value === 'other';
    }
    function openMove(tr, m) {
        current = { pid: tr.dataset.pid, vid: tr.dataset.vid, name: tr.dataset.name, stock: parseInt(tr.dataset.stock, 10) || 0 };
        form.product_id.value = current.pid; form.variant_id.value = current.vid;
        document.getElementById('inv-move-title').textContent = m === 'add' ? 'Add stock' : 'Adjust stock';
        document.getElementById('inv-move-item').textContent = current.name + ' · ' + current.stock + ' in stock now';
        form.querySelector('input[name="mode"][value="' + m + '"]').checked = true;
        qty.value = m === 'set' ? current.stock : 1;
        form.note.value = '';
        fillReasons(); update();
        moveDlg.showModal();
        qty.focus(); qty.select();
    }
    function openHistory(tr) {
        document.getElementById('inv-history-item').textContent = tr.dataset.name + ' · ' + tr.dataset.stock + ' in stock now';
        var body = document.getElementById('inv-history-body');
        body.textContent = 'Loading…';
        histDlg.showModal();
        fetch('products.php?stock_history=' + tr.dataset.pid + '&variant=' + tr.dataset.vid, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var rows = (d && d.data) || [];
                body.innerHTML = rows.length ? '<table class="inv-hist-table"><thead><tr><th>When</th><th>Change</th><th>Stock</th><th>Reason</th><th>By</th></tr></thead><tbody>' +
                    rows.map(function (r) {
                        var c = parseInt(r.change_qty, 10);
                        return '<tr><td>' + esc(r.when) + '</td><td class="' + (c > 0 ? 'inv-up' : 'inv-down') + '">' + (c > 0 ? '+' : '') + c + '</td><td>' + esc(r.stock_before) + ' → ' + esc(r.stock_after) + '</td>' +
                            '<td>' + esc(r.reason_label) + (r.note ? '<small>' + esc(r.note) + '</small>' : '') + '</td><td>' + esc(r.who) + '</td></tr>';
                    }).join('') + '</tbody></table>'
                    : '<p class="inv-item">No stock changes recorded yet. From now on, every Add / Adjust here and every quick stock edit on a product card is listed.</p>';
            }, function () { body.textContent = 'Could not load the history. Please try again.'; });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-inv]');
        if (b) {
            var tr = b.closest('tr');
            if (b.dataset.inv === 'history') openHistory(tr); else openMove(tr, b.dataset.inv);
            return;
        }
        var c = e.target.closest('[data-close]');
        if (c) c.closest('dialog').close();
        var st = e.target.closest('.inv-qty [data-step]');
        if (st) { qty.value = Math.max(0, (parseInt(qty.value, 10) || 0) + parseInt(st.dataset.step, 10)); update(); }
    });
    form.addEventListener('change', function (e) { if (e.target.name === 'mode') { fillReasons(); if (mode() === 'set') qty.value = current.stock; } update(); });
    qty.addEventListener('input', update);
    form.addEventListener('submit', function () { save.disabled = true; save.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; });
});
</script>
HTML;
}
