<?php
/**
 * Modal contents of the Suppliers & Deliveries page (delivery.php?fragment=...), rendered on the
 * server and shown in its one modal. Forms here post back to delivery.php (JSON), like every other
 * form on that page. The caller has already checked the login; admin-only buttons are hidden here
 * and the actions themselves check the role again (purch_can()).
 *
 *   po_form[&id]   new purchase order / edit a draft        receive&id   Receive Delivery form
 *   po&id          purchase order details                   receipt&id   one stock receipt
 *   po_receipts&id receiving history of one PO              batch&id     one inventory batch
 *   po_batches&id  batches created from one PO              supplier&id  supplier profile
 *   status_form&op=cancel|close_short&id                    link&id      link an old record to products
 */

function delivery_render_fragment(mysqli $conn, array $actor, string $which): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $id = (int) ($_GET['id'] ?? 0);
    $is_admin = $actor['role'] === 'admin';
    switch ($which) {
        case 'po_form':     frag_po_form($conn, $id); break;
        case 'po':          frag_po($conn, $id, $is_admin); break;
        case 'po_receipts': frag_po_receipts($conn, $id); break;
        case 'po_batches':  frag_po_batches($conn, $id); break;
        case 'receive':     frag_receive($conn, $id); break;
        case 'receipt':     frag_receipt($conn, $id, $is_admin); break;
        case 'status_form': frag_status_form($conn, $id, (string) ($_GET['op'] ?? ''), $is_admin); break;
        case 'batch':       frag_batch($conn, $id, $is_admin); break;
        case 'supplier':    frag_supplier($conn, $id); break;
        case 'link':        frag_link($conn, $id, $is_admin); break;
        default:            echo '<p class="frag-error" data-frag-title="Not found">Unknown view.</p>';
    }
}

function fh($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function frag_missing(string $what): void
{
    echo '<p class="frag-error" data-frag-title="Not found">' . fh($what) . ' was not found. It may have been removed - refresh the page.</p>';
}

function frag_status_badge(string $status): string
{
    return '<span class="badge badge-' . fh($status) . '">' . fh(PURCH_STATUS_LABELS[$status] ?? $status) . '</span>';
}

function frag_date(?string $d, string $fmt = 'M d, Y'): string
{
    return $d ? fh(date($fmt, strtotime($d))) : '—';
}

/** <option>s of every orderable product / size, grouped by product. */
function frag_item_options(array $catalog, string $selected = ''): string
{
    $groups = [];
    foreach ($catalog as $key => $c) {
        $groups[$c['product_id']][$key] = $c;
    }
    $out = '<option value="">Choose a product…</option>';
    foreach ($groups as $items) {
        $first = reset($items);
        $opt = static function (string $key, array $c, string $text) use ($selected): string {
            return '<option value="' . fh($key) . '" data-stock="' . (int) $c['stock'] . '" data-last="' . ($c['last_cost'] !== null ? fh(number_format($c['last_cost'], 2, '.', '')) : '') . '"'
                . ($key === $selected ? ' selected' : '') . '>' . fh($text) . '</option>';
        };
        if ($first['variant_id'] > 0) {
            $out .= '<optgroup label="' . fh($first['name']) . '">';
            foreach ($items as $key => $c) {
                $out .= $opt($key, $c, $c['name'] . ' — ' . $c['variant']);
            }
            $out .= '</optgroup>';
        } else {
            $out .= $opt(key($items), $first, $first['name']);
        }
    }
    return $out;
}

function frag_po_line(string $index, string $options, string $qty = '', string $cost = ''): string
{
    return '<div class="po-line">'
        . '<label class="mini-field">Product / size'
        . '<input type="search" class="item-search" data-item-search placeholder="Type a product name to search..." autocomplete="off" aria-label="Search products">'
        . '<select name="items[' . $index . '][item]" data-item>' . $options . '</select>'
        . '<button type="button" class="link-new-product" data-new-product><i class="fas fa-plus"></i> Not in the list? Add a new product</button></label>'
        . '<label class="mini-field">Quantity<input type="number" name="items[' . $index . '][qty]" min="1" max="100000" step="1" value="' . fh($qty) . '" data-qty placeholder="0"></label>'
        . '<label class="mini-field">Unit cost (₱)<input type="number" name="items[' . $index . '][cost]" min="0" step="0.01" value="' . fh($cost) . '" data-cost placeholder="0.00"></label>'
        . '<div class="mini-field">Line total<span class="line-total">₱0.00</span></div>'
        . '<button type="button" class="line-remove" data-po-remove title="Remove this line" aria-label="Remove line"><i class="fas fa-trash"></i></button>'
        . '<div class="line-hint"></div></div>';
}

/** Product lines editor shared by the PO form and the legacy link form. */
function frag_lines_editor(mysqli $conn, array $existing = []): string
{
    $catalog = purch_catalog($conn);
    $out = '<div class="po-lines">';
    $i = 0;
    foreach ($existing as $e) {
        $out .= frag_po_line((string) $i++, frag_item_options($catalog, $e['key']), (string) $e['qty'], (string) $e['cost']);
    }
    if (!$existing) {
        $out .= frag_po_line((string) $i++, frag_item_options($catalog));
    }
    $out .= '</div>';
    // "+ New product": one small form per editor, moved under the row that opened it (fields have no
    // name, so they are never sent with the purchase order itself).
    $cats = '';
    foreach (PURCH_QUICK_CATEGORIES as $c) {
        $cats .= '<option value="' . fh($c) . '">' . fh($c) . '</option>';
    }
    $out .= '<div class="qp-panel" data-qp hidden>'
        . '<div class="qp-head"><strong><i class="fas fa-box-open"></i> New product</strong>'
        . '<span>Saved with <b>0 stock</b>. Stock comes in when you receive the delivery. Add the picture and sizes later in Products.</span></div>'
        . '<div class="qp-grid">'
        . '<label class="mini-field qp-wide">Product name *<input type="text" data-qp-name maxlength="255" placeholder="e.g. Pedigree Adult Beef 3kg"></label>'
        . '<label class="mini-field">Selling price (₱) *<input type="number" data-qp-price min="0.01" step="0.01" placeholder="0.00"></label>'
        . '<label class="mini-field">Category<select data-qp-category><option value="">General</option>' . $cats . '</select></label>'
        . '<label class="mini-field">Brand<input type="text" data-qp-brand maxlength="80" placeholder="e.g. Pedigree"></label>'
        . '<label class="mini-field">Weight (kg)<input type="number" data-qp-weight min="0" step="0.001" placeholder="0.000"></label>'
        . '</div><div class="qp-error" role="alert"></div>'
        . '<div class="qp-actions"><button type="button" class="btn-plain" data-qp-cancel>Cancel</button>'
        . '<button type="button" class="btn-add" data-qp-save><i class="fas fa-plus"></i> Save product</button></div></div>';
    $out .= '<template data-po-line>' . frag_po_line('__i__', frag_item_options($catalog)) . '</template>';
    $out .= '<button type="button" class="btn-action btn-edit" data-po-add><i class="fas fa-plus"></i> Add another product</button>';
    $out .= '<div class="po-total"><span>Total quantity: <strong data-po-qty>0</strong></span><span>Total amount: <strong data-po-total>₱0.00</strong></span></div>';
    return $out;
}

function frag_po_form(mysqli $conn, int $po_id): void
{
    $po = null;
    $existing = [];
    if ($po_id > 0) {
        $po = purch_fetch_po($conn, $po_id);
        if (!$po) {
            frag_missing('This purchase order');
            return;
        }
        if ($po['status'] !== 'draft') {
            echo '<p class="frag-error" data-frag-title="Cannot edit">' . fh($po['po_number']) . ' is already ' . fh(strtolower(PURCH_STATUS_LABELS[$po['status']] ?? '')) . '. Only drafts can be edited.</p>';
            return;
        }
        foreach (purch_po_items($conn, $po_id) as $it) {
            $existing[] = ['key' => $it['product_id'] . ':' . $it['variant_id'], 'qty' => (int) $it['qty_ordered'], 'cost' => number_format((float) $it['unit_cost'], 2, '.', '')];
        }
    }
    $sups = $conn->query("SELECT id, supplier_name, brand_name, email, is_active FROM tbl_suppliers ORDER BY supplier_name")->fetch_all(MYSQLI_ASSOC);
    $active = array_filter($sups, static fn($s) => (int) $s['is_active'] === 1);
    $title = $po ? 'Edit Draft ' . $po['po_number'] : 'New Purchase Order';
    echo '<div data-frag-title="' . fh($title) . '">';
    if (!$active) {
        echo '<div class="info-note"><i class="fas fa-info-circle"></i><p>There are no active suppliers. Add or reactivate a supplier in the Supplier Directory first.</p></div></div>';
        return;
    }
    echo '<p class="modal-intro">Choose the supplier and the exact products / sizes you are ordering. Placing the order does <strong>not</strong> add stock &mdash; stock goes up only when you receive the delivery.</p>';
    echo '<form method="POST" data-po-form>' . csrf_field()
        . '<input type="hidden" name="action" value="save_po"><input type="hidden" name="po_id" value="' . (int) $po_id . '">'
        . '<div class="form-errors" role="alert"></div>'
        . '<div class="grid-2"><div class="form-group"><label>Supplier <span class="hint">*required &middot; active suppliers only</span></label><select name="supplier_id" required><option value="">Choose a supplier…</option>';
    foreach ($active as $s) {
        $sel = $po && (int) $po['supplier_id'] === (int) $s['id'] ? ' selected' : '';
        echo '<option value="' . (int) $s['id'] . '"' . $sel . '>' . fh($s['supplier_name'] . (!empty($s['brand_name']) ? ' — ' . $s['brand_name'] : '') . (empty($s['email']) ? ' (no email)' : '')) . '</option>';
    }
    echo '</select><span class="field-help">Submitting the order emails it to the supplier if they have an email address.</span></div>'
        . '<div class="form-group"><label>Expected Delivery Date</label><input type="date" name="expected_date" value="' . fh($po['expected_date'] ?? '') . '"></div></div>';
    echo '<h4 class="frag-section">Products to order</h4>' . frag_lines_editor($conn, $existing);
    echo '<div class="grid-2"><div class="form-group"><label>Tracking / Reference No.</label><input type="text" name="tracking_number" maxlength="100" value="' . fh($po['tracking_number'] ?? '') . '" placeholder="Supplier quotation, invoice or tracking no."></div>'
        . '<div class="form-group"><label>Remarks</label><textarea name="notes" rows="2" maxlength="2000" placeholder="Delivery time, packaging, payment terms…">' . fh($po['notes'] ?? '') . '</textarea><span class="field-help">Included in the email to the supplier.</span></div></div>'
        . '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Cancel</button>'
        . '<button type="submit" name="submit_mode" value="draft" class="btn-plain"><i class="fas fa-floppy-disk"></i> Save as Draft</button>'
        . '<button type="submit" name="submit_mode" value="submit" class="btn-add"><i class="fas fa-paper-plane"></i> Place Order</button></div>'
        . '</form></div>';
}

function frag_po(mysqli $conn, int $po_id, bool $is_admin): void
{
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This purchase order');
        return;
    }
    $items = purch_po_items($conn, $po_id);
    $st = (string) $po['status'];
    echo '<div data-frag-title="Purchase Order ' . fh($po['po_number']) . '">';
    echo '<div class="kv">'
        . '<div><span>Status</span>' . frag_status_badge($st) . ((int) $po['is_legacy'] === 1 ? ' <span class="badge badge-legacy">Old record</span>' : '') . '</div>'
        . '<div><span>Supplier</span><strong>' . fh($po['supplier_name']) . '</strong>' . (!empty($po['supplier_email']) ? '<br><small>' . fh($po['supplier_email']) . '</small>' : '') . '</div>'
        . '<div><span>Created</span>' . frag_date($po['created_at'], 'M d, Y g:i A') . '</div>'
        . '<div><span>Expected</span>' . frag_date($po['expected_date']) . '</div>'
        . '<div><span>Tracking / Ref.</span>' . fh($po['tracking_number'] ?: '—') . '</div>'
        . '<div><span>Total amount</span><strong>' . fh(purch_money((float) $po['total_amount'])) . '</strong></div>';
    if (!empty($po['received_date'])) {
        echo '<div><span>Fully received</span>' . frag_date($po['received_date'], 'M d, Y g:i A') . '</div>';
    }
    if (!empty($po['closed_reason'])) {
        echo '<div style="grid-column:1/-1"><span>' . ($st === 'cancelled' ? 'Cancellation reason' : 'Closing reason') . '</span>' . fh($po['closed_reason']) . '</div>';
    }
    if (!empty($po['notes'])) {
        echo '<div style="grid-column:1/-1"><span>Remarks</span>' . nl2br(fh($po['notes'])) . '</div>';
    }
    echo '</div>';

    if ((int) $po['is_legacy'] === 1) {
        echo '<div class="info-note"><i class="fas fa-info-circle"></i><p>This delivery was recorded before purchase orders existed, as free text only. It never changed stock.'
            . ($is_admin && $st !== 'cancelled' ? ' Use <strong>Link Items</strong> to connect it to real products.' : '') . '</p></div>'
            . '<div class="frag-box"><h4>Items (as typed)</h4><div class="items-lines">' . fh($po['items_summary']) . '</div></div>';
    } else {
        echo '<div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>Product</th><th class="num">Ordered</th><th class="num">Unit cost</th><th class="num">Line total</th><th class="num">Received</th><th class="num">Rejected</th><th class="num">Outstanding</th></tr></thead><tbody>';
        $tq = $tr = $tj = 0;
        foreach ($items as $it) {
            $out = max(0, (int) $it['qty_ordered'] - (int) $it['qty_received']);
            $tq += (int) $it['qty_ordered'];
            $tr += (int) $it['qty_received'];
            $tj += (int) $it['qty_rejected'];
            echo '<tr><td class="cell-strong">' . fh(purch_item_label($it['item_label'], $it['variant_label'])) . '</td>'
                . '<td class="num">' . number_format((int) $it['qty_ordered']) . '</td><td class="num">' . fh(purch_money((float) $it['unit_cost'])) . '</td>'
                . '<td class="num">' . fh(purch_money((int) $it['qty_ordered'] * (float) $it['unit_cost'])) . '</td>'
                . '<td class="num">' . number_format((int) $it['qty_received']) . '</td><td class="num">' . number_format((int) $it['qty_rejected']) . '</td>'
                . '<td class="num"><strong>' . number_format($out) . '</strong>' . ($out > 0 && $st === 'closed_short' ? '<br><small class="cell-muted">short</small>' : '') . '</td></tr>';
        }
        echo '</tbody><tfoot><tr><th>Total</th><th class="num">' . number_format($tq) . '</th><th></th><th class="num">' . fh(purch_money((float) $po['total_amount'])) . '</th><th class="num">' . number_format($tr) . '</th><th class="num">' . number_format($tj) . '</th><th class="num">' . number_format(max(0, $tq - $tr)) . '</th></tr></tfoot></table></div>';
    }

    $receipts = purch_list_receipts($conn, $po_id);
    if ($receipts) {
        echo '<h4 class="frag-section">Receipts</h4>' . frag_receipts_table($receipts);
    }

    // What can be done next
    $btns = '';
    $form = static function (string $op, string $label, string $cls, string $icon, string $confirm) use ($po_id): string {
        return '<form method="POST" style="margin:0" data-confirm="' . fh($confirm) . '">' . csrf_field()
            . '<input type="hidden" name="action" value="po_status"><input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="po_id" value="' . $po_id . '">'
            . '<button type="submit" class="btn-action ' . $cls . '"><i class="fas ' . $icon . '"></i> ' . $label . '</button></form>';
    };
    if ((int) $po['is_legacy'] === 1) {
        if ($is_admin && $st !== 'cancelled') {
            $btns .= '<button type="button" class="btn-action btn-edit" data-frag="link&id=' . $po_id . '" data-wide="x"><i class="fas fa-link"></i> Link Items</button>';
        }
    } else {
        if ($st === 'draft') {
            $btns .= '<button type="button" class="btn-action btn-edit" data-frag="po_form&id=' . $po_id . '" data-wide="x"><i class="fas fa-pen"></i> Edit Draft</button>';
            $btns .= $form('submit', 'Submit Order', 'btn-approve', 'fa-paper-plane', 'Submit this purchase order to the supplier? It can no longer be edited after this.');
        }
        if ($st === 'pending') {
            $btns .= $form('mark_in_transit', 'Mark In Transit', 'btn-edit', 'fa-truck-fast', 'Mark this order as shipped by the supplier? Stock does not change until you receive it.');
        }
        if (in_array($st, PURCH_RECEIVABLE, true)) {
            $btns .= '<button type="button" class="btn-action btn-approve" data-frag="receive&id=' . $po_id . '" data-wide="x"><i class="fas fa-dolly"></i> Receive Delivery</button>';
        }
        if ($receipts) {
            $btns .= '<button type="button" class="btn-action btn-muted" data-frag="po_batches&id=' . $po_id . '" data-wide="x"><i class="fas fa-layer-group"></i> Inventory Batches</button>';
        }
        if ($is_admin && in_array($st, ['draft', 'pending', 'in_transit'], true)) {
            $btns .= '<button type="button" class="btn-action btn-cancel" data-frag="status_form&op=cancel&id=' . $po_id . '"><i class="fas fa-times"></i> Cancel Order</button>';
        }
        if ($is_admin && $st === 'partially_received') {
            $btns .= '<button type="button" class="btn-action btn-warn" data-frag="status_form&op=close_short&id=' . $po_id . '"><i class="fas fa-flag-checkered"></i> Close with Shortage</button>';
        }
    }
    echo '<div class="frag-actions">' . $btns . '<button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_receipts_table(array $receipts): string
{
    $out = '<div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>Receipt</th><th>Date</th><th>Items</th><th class="num">Qty</th><th class="num">Cost</th><th>By</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($receipts as $rc) {
        $status = $rc['status'] === 'reversed' ? '<span class="badge badge-reversed">Reversed</span>'
            : ((int) $rc['stock_applied'] === 0 ? '<span class="badge badge-history">History only</span>' : '<span class="badge badge-confirmed">Confirmed</span>');
        $out .= '<tr><td class="cell-strong">' . fh($rc['receipt_number']) . '</td><td>' . frag_date($rc['received_at'], 'M d, Y g:i A') . '</td>'
            . '<td class="items-lines">' . fh($rc['items_text']) . '</td><td class="num">' . number_format((int) $rc['total_qty'])
            . ((int) $rc['total_rejected'] > 0 ? '<br><small class="cell-muted">' . (int) $rc['total_rejected'] . ' rejected</small>' : '') . '</td>'
            . '<td class="num">' . fh(purch_money((float) $rc['total_cost'])) . '</td><td>' . fh($rc['received_by_name']) . '</td><td>' . $status . '</td>'
            . '<td><button type="button" class="btn-action btn-muted" data-frag="receipt&id=' . (int) $rc['id'] . '" data-wide="x"><i class="fas fa-receipt"></i> View</button></td></tr>';
    }
    return $out . '</tbody></table></div>';
}

function frag_po_receipts(mysqli $conn, int $po_id): void
{
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This purchase order');
        return;
    }
    $receipts = purch_list_receipts($conn, $po_id);
    echo '<div data-frag-title="Receiving History · ' . fh($po['po_number']) . '">';
    echo $receipts ? frag_receipts_table($receipts) : '<p class="cell-muted">Nothing has been received for this purchase order yet.</p>';
    echo '<div class="frag-actions"><button type="button" class="btn-action btn-muted" data-frag="po&id=' . $po_id . '" data-wide="x"><i class="fas fa-eye"></i> Purchase Order</button><button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_batches_table(array $batches): string
{
    $out = '<div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>Batch</th><th>Product</th><th>Lot</th><th>Expires</th><th class="num">Cost</th><th class="num">Received</th><th class="num">Remaining</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($batches as $b) {
        $out .= '<tr><td class="cell-strong">' . fh($b['batch_code']) . '</td><td>' . fh(purch_item_label($b['item_label'], $b['variant_label'])) . '</td>'
            . '<td>' . fh($b['lot_number'] ?: '—') . '</td><td>' . ($b['expiry_date'] ? frag_date($b['expiry_date']) : '<span class="cell-muted">No expiry</span>') . '</td>'
            . '<td class="num">' . ($b['unit_cost'] !== null ? fh(purch_money((float) $b['unit_cost'])) : '—') . '</td>'
            . '<td class="num">' . number_format((int) $b['qty_received']) . '</td><td class="num"><strong>' . number_format((int) $b['qty_remaining']) . '</strong></td>'
            . '<td><span class="badge badge-' . fh($b['status']) . '">' . fh(inv_batch_statuses()[$b['status']] ?? $b['status']) . '</span>' . (!empty($b['sells_next']) ? ' <span class="badge badge-next">Sells next</span>' : '') . '</td>'
            . '<td><button type="button" class="btn-action btn-muted" data-frag="batch&id=' . (int) $b['id'] . '" data-wide="w"><i class="fas fa-eye"></i> View</button></td></tr>';
    }
    return $out . '</tbody></table></div>';
}

function frag_po_batches(mysqli $conn, int $po_id): void
{
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This purchase order');
        return;
    }
    $s = $conn->prepare("SELECT b.* FROM tbl_inventory_batches b JOIN tbl_stock_receipts r ON r.id = b.receipt_id WHERE r.po_id = ? ORDER BY b.id");
    $s->bind_param('i', $po_id);
    $s->execute();
    $batches = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    echo '<div data-frag-title="Inventory Batches · ' . fh($po['po_number']) . '">';
    echo $batches ? frag_batches_table($batches) : '<p class="cell-muted">No inventory batches were created from this purchase order yet.</p>';
    echo '<div class="frag-actions"><button type="button" class="btn-action btn-muted" data-frag="po&id=' . $po_id . '" data-wide="x"><i class="fas fa-eye"></i> Purchase Order</button><button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_receive(mysqli $conn, int $po_id): void
{
    inv_remap_variant_ids($conn);
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This purchase order');
        return;
    }
    if ((int) $po['is_legacy'] === 1) {
        echo '<p class="frag-error" data-frag-title="Link items first">This is an old delivery record without linked products. An admin must link its items before it can be received.</p>';
        return;
    }
    if (!in_array($po['status'], PURCH_RECEIVABLE, true)) {
        echo '<p class="frag-error" data-frag-title="Cannot receive">' . fh($po['po_number']) . ' is ' . fh(strtolower(PURCH_STATUS_LABELS[$po['status']] ?? $po['status'])) . ' and cannot receive stock.</p>';
        return;
    }
    $items = purch_po_items($conn, $po_id);
    $last = purch_last_costs($conn);
    $open = array_filter($items, static fn($it) => (int) $it['qty_ordered'] > (int) $it['qty_received']);
    echo '<div data-frag-title="Receive Delivery · ' . fh($po['po_number']) . '">';
    echo '<div class="kv"><div><span>Purchase order</span><strong>' . fh($po['po_number']) . '</strong></div><div><span>Supplier</span><strong>' . fh($po['supplier_name']) . '</strong></div>'
        . '<div><span>Status</span>' . frag_status_badge((string) $po['status']) . '</div><div><span>Expected</span>' . frag_date($po['expected_date']) . '</div>'
        . '<div><span>Tracking / Ref.</span>' . fh($po['tracking_number'] ?: '—') . '</div></div>';
    echo '<p class="modal-intro">Count what actually arrived. <strong>Accepted</strong> units are added to stock as a new batch; <strong>rejected</strong> units (damaged, expired, wrong item) are recorded for the supplier but never added. Leave an item at 0 if it did not come &mdash; it stays outstanding for a later delivery.</p>';
    echo '<form method="POST" data-receive-form>' . csrf_field()
        . '<input type="hidden" name="action" value="receive"><input type="hidden" name="po_id" value="' . $po_id . '">'
        . '<input type="hidden" name="token" value="' . fh(purch_new_token()) . '">'
        . '<div class="form-errors" role="alert"></div>'
        . '<div style="display:flex;justify-content:flex-end;margin-bottom:8px"><button type="button" class="btn-action btn-muted" data-rcv-all title="Fill every line with its outstanding quantity, then correct what differs"><i class="fas fa-check-double"></i> Everything arrived as ordered</button></div>';
    foreach ($open as $it) {
        $iid = (int) $it['id'];
        $rem = (int) $it['qty_ordered'] - (int) $it['qty_received'];
        $key = $it['product_id'] . ':' . $it['variant_id'];
        $lc = $last[$key]['unit_cost'] ?? null;
        $n = 'lines[' . $iid . ']';
        echo '<div class="rcv-line" data-remaining="' . $rem . '" data-last="' . ($lc !== null ? fh($lc) : '') . '">'
            . '<div class="rcv-head"><strong>' . fh(purch_item_label($it['item_label'], $it['variant_label'])) . '</strong>'
            . '<div class="rcv-counts"><span>Ordered <b>' . number_format((int) $it['qty_ordered']) . '</b></span><span>Received so far <b>' . number_format((int) $it['qty_received']) . '</b></span>'
            . ((int) $it['qty_rejected'] > 0 ? '<span>Rejected so far <b>' . number_format((int) $it['qty_rejected']) . '</b></span>' : '')
            . '<span>Remaining <b>' . number_format($rem) . '</b></span><span>PO cost <b>' . fh(purch_money((float) $it['unit_cost'])) . '</b></span>'
            . ($lc !== null ? '<span>Last paid <b>' . fh(purch_money($lc)) . '</b></span>' : '') . '</div></div>'
            . '<div class="rcv-grid">'
            . '<label class="mini-field">Accepted (good) qty<input type="number" name="' . $n . '[accepted]" min="0" max="' . $rem . '" step="1" placeholder="0" data-acc></label>'
            . '<label class="mini-field">Rejected qty<input type="number" name="' . $n . '[rejected]" min="0" max="' . $rem . '" step="1" placeholder="0" data-rej></label>'
            . '<label class="mini-field">Reason for rejecting<select name="' . $n . '[reject_reason]" data-reason><option value="">—</option>';
        foreach (PURCH_REJECT_REASONS as $k => $l) {
            echo '<option value="' . fh($k) . '">' . fh($l) . '</option>';
        }
        echo '</select></label>'
            . '<label class="mini-field">Actual unit cost (₱)<input type="number" name="' . $n . '[unit_cost]" min="0" step="0.01" value="' . fh(number_format((float) $it['unit_cost'], 2, '.', '')) . '" required data-rcost></label>'
            . '<label class="mini-field">Expiration date<input type="date" name="' . $n . '[expiry]"></label>'
            . '<label class="mini-field">Supplier batch / lot no.<input type="text" name="' . $n . '[lot]" maxlength="100" placeholder="If printed on the box"></label>'
            . '<label class="rcv-check" style="grid-column: span 2"><input type="checkbox" name="' . $n . '[hold]" value="1" data-hold> Hold accepted units for inspection (quarantine) &mdash; not sellable until an admin releases them</label>'
            . '</div><div class="rcv-hint"></div></div>';
    }
    $done = array_filter($items, static fn($it) => (int) $it['qty_ordered'] <= (int) $it['qty_received']);
    if ($done) {
        echo '<p class="cell-muted">Already fully received: ' . fh(implode(', ', array_map(static fn($it) => purch_item_label($it['item_label'], $it['variant_label']), $done))) . '</p>';
    }
    echo '<div class="grid-2" style="margin-top:12px"><div class="form-group"><label>Date &amp; time received <span class="hint">*required</span></label>'
        . '<input type="datetime-local" name="received_at" required value="' . date('Y-m-d\TH:i') . '" max="' . date('Y-m-d\TH:i', time() + 300) . '" min="' . date('Y-m-d\T00:00', strtotime($po['created_at'])) . '"></div>'
        . '<div class="form-group"><label>Receiving remarks</label><textarea name="remarks" rows="2" maxlength="2000" placeholder="Condition of the boxes, delivery receipt no., who delivered…"></textarea></div></div>'
        . '<label class="verify-box"><input type="checkbox" name="verified" value="1" required> I physically counted and inspected these items, and the quantities above match what actually arrived.</label>'
        . '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Cancel</button><button type="submit" class="btn-add"><i class="fas fa-dolly"></i> Confirm Receipt</button></div>'
        . '</form></div>';
}

function frag_receipt(mysqli $conn, int $receipt_id, bool $is_admin): void
{
    $rc = purch_get_receipt($conn, $receipt_id);
    if (!$rc) {
        frag_missing('This receipt');
        return;
    }
    $status = $rc['status'] === 'reversed' ? '<span class="badge badge-reversed">Reversed</span>'
        : ((int) $rc['stock_applied'] === 0 ? '<span class="badge badge-history">History only &middot; stock not changed</span>' : '<span class="badge badge-confirmed">Confirmed</span>');
    echo '<div data-frag-title="Receipt ' . fh($rc['receipt_number']) . '">';
    echo '<div class="kv"><div><span>Receipt</span><strong>' . fh($rc['receipt_number']) . '</strong></div><div><span>Status</span>' . $status . '</div>'
        . '<div><span>Purchase order</span><a href="#" data-frag="po&id=' . (int) $rc['po_id'] . '" data-wide="x">' . fh($rc['po_number']) . '</a></div>'
        . '<div><span>Supplier</span>' . fh($rc['supplier_name']) . '</div><div><span>Received</span>' . frag_date($rc['received_at'], 'M d, Y g:i A') . '</div>'
        . '<div><span>Received by</span>' . fh($rc['received_by_name']) . '</div>'
        . '<div><span>Physically verified</span>' . ((int) $rc['physically_verified'] === 1 ? 'Yes' : 'No (old record)') . '</div>'
        . '<div><span>Recorded</span>' . frag_date($rc['created_at'], 'M d, Y g:i A') . '</div>';
    if (!empty($rc['remarks'])) {
        echo '<div style="grid-column:1/-1"><span>Remarks</span>' . nl2br(fh($rc['remarks'])) . '</div>';
    }
    if ($rc['status'] === 'reversed') {
        echo '<div style="grid-column:1/-1"><span>Reversed</span>' . frag_date($rc['reversed_at'], 'M d, Y g:i A') . ' by ' . fh($rc['reversed_by_name'] ?: '—') . ': ' . fh($rc['reversal_reason']) . '</div>';
    }
    echo '</div><div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>Product</th><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Unit cost</th><th class="num">Line total</th><th>Expires</th><th>Lot</th><th>Batch</th></tr></thead><tbody>';
    foreach ($rc['items'] as $it) {
        $rej = (int) $it['qty_rejected'] > 0 ? number_format((int) $it['qty_rejected']) . '<br><small class="cell-muted">' . fh(PURCH_REJECT_REASONS[$it['reject_reason']] ?? $it['reject_reason']) . '</small>' : '0';
        $batch = $it['batch_code'] ? '<a href="#" data-frag="batch&id=' . (int) $it['batch_id'] . '" data-wide="w">' . fh($it['batch_code']) . '</a><br><span class="badge badge-' . fh($it['batch_status']) . '">' . fh(inv_batch_statuses()[$it['batch_status']] ?? $it['batch_status']) . '</span>' : '—';
        echo '<tr><td class="cell-strong">' . fh(purch_item_label($it['item_label'], $it['variant_label'])) . ((int) $it['held'] === 1 ? '<br><small class="cell-muted">held for inspection</small>' : '') . '</td>'
            . '<td class="num">' . number_format((int) $it['qty_accepted']) . '</td><td class="num">' . $rej . '</td>'
            . '<td class="num">' . fh(purch_money((float) $it['unit_cost'])) . '</td><td class="num">' . fh(purch_money((float) $it['line_total'])) . '</td>'
            . '<td>' . ($it['expiry_date'] ? frag_date($it['expiry_date']) : '—') . '</td><td>' . fh($it['lot_number'] ?: '—') . '</td><td>' . $batch . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th>Total</th><th class="num">' . number_format((int) $rc['total_qty']) . '</th><th class="num">' . number_format((int) $rc['total_rejected']) . '</th><th></th><th class="num">' . fh(purch_money((float) $rc['total_cost'])) . '</th><th colspan="3"></th></tr></tfoot></table></div>';

    if ($is_admin && $rc['status'] === 'confirmed') {
        echo '<details class="frag-box"><summary style="cursor:pointer;font-weight:600"><i class="fas fa-rotate-left"></i> Reverse this receipt (correct a mistake)</summary>'
            . '<p class="field-help">Takes the accepted units back out of stock and reopens the purchase order quantities. Only possible while none of the units have been sold or adjusted. The receipt stays on record, marked Reversed.</p>'
            . '<form method="POST" data-confirm="Reverse ' . fh($rc['receipt_number']) . '? The units come back out of stock.">' . csrf_field()
            . '<input type="hidden" name="action" value="reverse_receipt"><input type="hidden" name="receipt_id" value="' . $receipt_id . '"><div class="form-errors" role="alert"></div>'
            . '<label class="mini-field">Reason <span class="hint">*required</span><textarea name="reason" rows="2" required minlength="5" maxlength="255" placeholder="e.g. Recorded against the wrong purchase order"></textarea></label>'
            . '<div class="frag-actions"><button type="submit" class="btn-action btn-cancel"><i class="fas fa-rotate-left"></i> Reverse Receipt</button></div></form></details>';
    }
    echo '<div class="frag-actions"><button type="button" class="btn-plain" onclick="window.print()"><i class="fas fa-print"></i> Print</button><button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_status_form(mysqli $conn, int $po_id, string $op, bool $is_admin): void
{
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This purchase order');
        return;
    }
    if (!$is_admin || !in_array($op, ['cancel', 'close_short'], true)) {
        echo '<p class="frag-error" data-frag-title="Not allowed">Only an admin can do that.</p>';
        return;
    }
    $cancel = $op === 'cancel';
    $short = 0;
    foreach (purch_po_items($conn, $po_id) as $it) {
        $short += max(0, (int) $it['qty_ordered'] - (int) $it['qty_received']);
    }
    echo '<div data-frag-title="' . ($cancel ? 'Cancel ' : 'Close with Shortage · ') . fh($po['po_number']) . '">'
        . '<p class="modal-intro">' . ($cancel
            ? 'The order is called off. Nothing was received, so stock is not affected. This cannot be undone.'
            : 'The remaining <strong>' . number_format($short) . ' unit(s)</strong> will not be delivered. What was received stays in stock; the shortage stays on record against ' . fh($po['supplier_name']) . '.') . '</p>'
        . '<form method="POST">' . csrf_field() . '<input type="hidden" name="action" value="po_status"><input type="hidden" name="op" value="' . fh($op) . '"><input type="hidden" name="po_id" value="' . $po_id . '">'
        . '<div class="form-errors" role="alert"></div>'
        . '<div class="form-group"><label>Reason <span class="hint">*required</span></label><textarea name="reason" rows="3" required minlength="3" maxlength="255" placeholder="' . ($cancel ? 'e.g. Supplier is out of stock' : 'e.g. Supplier discontinued the item') . '"></textarea></div>'
        . '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Back</button><button type="submit" class="btn-action ' . ($cancel ? 'btn-cancel' : 'btn-warn') . '">' . ($cancel ? 'Cancel Order' : 'Close with Shortage') . '</button></div></form></div>';
}

function frag_batch(mysqli $conn, int $batch_id, bool $is_admin): void
{
    $b = inv_batch_fetch($conn, $batch_id);
    if (!$b) {
        frag_missing('This batch');
        return;
    }
    $sup = null;
    if ($b['supplier_id']) {
        $sup = $conn->query("SELECT supplier_name FROM tbl_suppliers WHERE id = " . (int) $b['supplier_id'])->fetch_assoc();
    }
    $rc = $b['receipt_id'] ? $conn->query("SELECT r.receipt_number, d.po_number, d.id AS po_id FROM tbl_stock_receipts r JOIN tbl_supplier_deliveries d ON d.id = r.po_id WHERE r.id = " . (int) $b['receipt_id'])->fetch_assoc() : null;
    $labels = inv_batch_statuses();
    $source = ['receipt' => 'Supplier delivery', 'opening' => 'Opening stock (before batch tracking)', 'adjustment' => 'Stock added outside receiving'][$b['source']] ?? $b['source'];
    echo '<div data-frag-title="Batch ' . fh($b['batch_code']) . '">';
    echo '<div class="kv"><div><span>Product</span><strong>' . fh(purch_item_label($b['item_label'], $b['variant_label'])) . '</strong></div>'
        . '<div><span>Status</span><span class="badge badge-' . fh($b['status']) . '">' . fh($labels[$b['status']] ?? $b['status']) . '</span></div>'
        . '<div><span>Source</span>' . fh($source) . '</div><div><span>Supplier</span>' . fh($sup['supplier_name'] ?? '—') . '</div>'
        . '<div><span>Receipt / PO</span>' . ($rc ? '<a href="#" data-frag="receipt&id=' . (int) $b['receipt_id'] . '" data-wide="x">' . fh($rc['receipt_number']) . '</a> &middot; ' . fh($rc['po_number']) : '—') . '</div>'
        . '<div><span>Received</span>' . frag_date($b['received_at'], 'M d, Y g:i A') . '</div>'
        . '<div><span>Expires</span>' . ($b['expiry_date'] ? frag_date($b['expiry_date']) : 'No expiry') . '</div><div><span>Lot no.</span>' . fh($b['lot_number'] ?: '—') . '</div>'
        . '<div><span>Unit cost</span>' . ($b['unit_cost'] !== null ? fh(purch_money((float) $b['unit_cost'])) : 'Not recorded') . '</div>'
        . '<div><span>Received / remaining</span>' . number_format((int) $b['qty_received']) . ' / <strong>' . number_format((int) $b['qty_remaining']) . '</strong></div>';
    if (!empty($b['status_reason'])) {
        echo '<div style="grid-column:1/-1"><span>Note</span>' . fh($b['status_reason']) . '</div>';
    }
    echo '</div>';

    $s = $conn->prepare("SELECT m.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'System') AS who
                         FROM tbl_inventory_batch_movements m LEFT JOIN users u ON u.id = m.user_id
                         WHERE m.batch_id = ? ORDER BY m.id DESC LIMIT 100");
    $s->bind_param('i', $batch_id);
    $s->execute();
    $moves = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    $types = ['receive' => 'Received', 'receive_hold' => 'Received (on hold)', 'sale' => 'Sold', 'restore' => 'Returned / cancelled order', 'adjust_out' => 'Removed (adjustment)',
              'adjust_in' => 'Added (adjustment)', 'opening' => 'Opening stock', 'quarantined' => 'Put on hold', 'release' => 'Released for sale', 'expired' => 'Expired',
              'damaged' => 'Written off: damaged', 'recalled' => 'Recalled', 'reversal' => 'Receipt reversed', 'edit' => 'Details corrected'];
    echo '<h4 class="frag-section">Movements</h4>';
    if ($moves) {
        echo '<div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>When</th><th>What</th><th class="num">Change</th><th class="num">Left</th><th>By</th></tr></thead><tbody>';
        foreach ($moves as $m) {
            $c = (int) $m['change_qty'];
            echo '<tr><td>' . frag_date($m['created_at'], 'M d, Y g:i A') . '</td><td>' . fh($types[$m['movement_type']] ?? $m['movement_type']) . ($m['note'] ? '<br><small class="cell-muted">' . fh($m['note']) . '</small>' : '') . '</td>'
                . '<td class="num"><span class="' . ($c > 0 ? 'down' : ($c < 0 ? 'up' : '')) . '">' . ($c > 0 ? '+' : '') . $c . '</span></td><td class="num">' . (int) $m['qty_after'] . '</td><td>' . fh($m['who']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="cell-muted">No movements yet.</p>';
    }

    if ($is_admin && in_array($b['status'], ['available', 'quarantined'], true)) {
        $opts = $b['status'] === 'available'
            ? ['quarantined' => 'Put on hold (quarantine) - remove from sale', 'damaged' => 'Write off as damaged', 'expired' => 'Write off as expired', 'recalled' => 'Recalled by supplier / manufacturer']
            : ['available' => 'Release for sale', 'damaged' => 'Write off as damaged', 'expired' => 'Write off as expired', 'recalled' => 'Recalled by supplier / manufacturer'];
        echo '<div class="frag-box"><h4>Change status</h4><p class="field-help">' . ($b['status'] === 'available'
                ? 'The ' . number_format((int) $b['qty_remaining']) . ' remaining unit(s) leave sellable stock immediately.'
                : 'Releasing adds the ' . number_format((int) $b['qty_remaining']) . ' unit(s) back to sellable stock.') . '</p>'
            . '<form method="POST">' . csrf_field() . '<input type="hidden" name="action" value="batch_status"><input type="hidden" name="batch_id" value="' . $batch_id . '"><div class="form-errors" role="alert"></div>'
            . '<div class="grid-2"><label class="mini-field">New status<select name="new_status" required><option value="">Choose…</option>';
        foreach ($opts as $k => $l) {
            echo '<option value="' . fh($k) . '">' . fh($l) . '</option>';
        }
        echo '</select></label><label class="mini-field">Reason <span class="hint">*required</span><input type="text" name="reason" required maxlength="255" placeholder="e.g. Torn packaging found during stock check"></label></div>'
            . '<div class="frag-actions"><button type="submit" class="btn-action btn-warn"><i class="fas fa-check"></i> Update Batch</button></div></form>'
            . '<h4 style="margin-top:14px">Correct expiration date / lot no.</h4>'
            . '<form method="POST">' . csrf_field() . '<input type="hidden" name="action" value="batch_edit"><input type="hidden" name="batch_id" value="' . $batch_id . '"><div class="form-errors" role="alert"></div>'
            . '<div class="grid-3"><label class="mini-field">Expiration date<input type="date" name="expiry_date" value="' . fh($b['expiry_date'] ?? '') . '"></label>'
            . '<label class="mini-field">Lot no.<input type="text" name="lot_number" maxlength="100" value="' . fh($b['lot_number'] ?? '') . '"></label>'
            . '<label class="mini-field">Reason <span class="hint">*required</span><input type="text" name="reason" required maxlength="255" placeholder="e.g. Typo at receiving"></label></div>'
            . '<div class="frag-actions"><button type="submit" class="btn-action btn-edit"><i class="fas fa-floppy-disk"></i> Save Correction</button></div></form></div>';
    }
    echo '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_supplier(mysqli $conn, int $supplier_id): void
{
    $s = purch_supplier_profile($conn, $supplier_id);
    if (!$s) {
        frag_missing('This supplier');
        return;
    }
    echo '<div data-frag-title="' . fh($s['supplier_name']) . '">';
    echo '<div class="kv"><div><span>Status</span><span class="badge ' . ((int) $s['is_active'] === 1 ? 'badge-active">Active' : 'badge-inactive">Inactive') . '</span></div>'
        . '<div><span>Brand / company</span>' . fh($s['brand_name'] ?: '—') . '</div><div><span>Contact person</span>' . fh($s['contact_person'] ?: '—') . '</div>'
        . '<div><span>Phone</span>' . fh($s['phone'] ?: '—') . '</div><div><span>Email</span>' . fh($s['email'] ?: '—') . '</div>'
        . '<div style="grid-column:1/-1"><span>Business address</span>' . nl2br(fh($s['address'] ?: '—')) . '</div></div>';
    echo '<div class="mini-stats"><div><span>Purchase orders</span><strong>' . number_format($s['total_pos']) . '</strong></div>'
        . '<div><span>Received deliveries</span><strong>' . number_format($s['received_deliveries']) . '</strong></div>'
        . '<div><span>Total purchase cost</span><strong>' . fh(purch_money($s['total_purchase_cost'])) . '</strong></div>'
        . '<div><span>Units received</span><strong>' . number_format($s['units_received']) . '</strong></div>'
        . '<div><span>Units rejected</span><strong>' . number_format($s['units_rejected']) . '</strong></div></div>';
    echo '<h4 class="frag-section">Purchase orders</h4>';
    if ($s['pos']) {
        echo '<div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>PO No.</th><th>Created</th><th>Status</th><th class="num">Amount</th><th class="num">Received / Ordered</th><th></th></tr></thead><tbody>';
        foreach ($s['pos'] as $p) {
            echo '<tr><td class="cell-strong">' . fh($p['po_number']) . '</td><td>' . frag_date($p['created_at']) . '</td><td>' . frag_status_badge((string) $p['status'])
                . ((int) $p['is_legacy'] === 1 ? ' <span class="badge badge-legacy">Old record</span>' : '') . '</td>'
                . '<td class="num">' . ((int) $p['is_legacy'] === 1 ? '—' : fh(purch_money((float) $p['total_amount']))) . '</td>'
                . '<td class="num">' . ((int) $p['is_legacy'] === 1 ? '—' : number_format((int) $p['qty_received']) . ' / ' . number_format((int) $p['qty_ordered'])) . '</td>'
                . '<td><button type="button" class="btn-action btn-muted" data-frag="po&id=' . (int) $p['id'] . '" data-wide="x"><i class="fas fa-eye"></i> View</button></td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="cell-muted">No purchase orders yet.</p>';
    }
    echo '<h4 class="frag-section">Received deliveries</h4>' . ($s['receipts'] ? frag_receipts_table($s['receipts']) : '<p class="cell-muted">Nothing received yet.</p>');
    if ($s['costs']) {
        echo '<h4 class="frag-section">Purchase costs</h4><div style="overflow-x:auto"><table class="delivery-table"><thead><tr><th>Date</th><th>Product</th><th class="num">Qty</th><th class="num">Previous</th><th class="num">Cost</th><th class="num">Change</th></tr></thead><tbody>';
        foreach ($s['costs'] as $c) {
            echo '<tr><td>' . frag_date($c['received_at']) . '</td><td>' . fh($c['item']) . '</td><td class="num">' . number_format($c['qty']) . '</td>'
                . '<td class="num">' . ($c['prev_cost'] !== null ? fh(purch_money($c['prev_cost'])) : '—') . '</td><td class="num"><strong>' . fh(purch_money($c['cost'])) . '</strong></td>'
                . '<td class="num">' . ($c['pct'] !== null ? '<span class="' . ($c['pct'] > 0 ? 'up' : ($c['pct'] < 0 ? 'down' : '')) . '">' . ($c['pct'] > 0 ? '+' : '') . fh(number_format($c['pct'], 1)) . '%</span>' : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Close</button></div></div>';
}

function frag_link(mysqli $conn, int $po_id, bool $is_admin): void
{
    $po = purch_fetch_po($conn, $po_id);
    if (!$po) {
        frag_missing('This delivery record');
        return;
    }
    if (!$is_admin) {
        echo '<p class="frag-error" data-frag-title="Not allowed">Only an admin can link old delivery records.</p>';
        return;
    }
    if ((int) $po['is_legacy'] !== 1) {
        echo '<p class="cell-muted" data-frag-title="Already linked">' . fh($po['po_number']) . ' is already linked to products.</p>';
        return;
    }
    $delivered = $po['status'] === 'delivered';
    echo '<div data-frag-title="Link Items · ' . fh($po['po_number']) . '">';
    echo '<div class="frag-box" style="margin-top:0"><h4>What was typed</h4><div class="items-lines">' . fh($po['items_summary']) . '</div>'
        . '<p class="field-help">Supplier: ' . fh($po['supplier_name']) . ' &middot; recorded ' . frag_date($po['created_at']) . ' &middot; ' . frag_status_badge((string) $po['status']) . '</p></div>';
    echo '<p class="modal-intro" style="margin-top:12px">Pick the real product and size for each item above, with the quantity and unit cost. The text is <strong>not</strong> matched automatically. '
        . ($delivered
            ? 'This delivery was already marked delivered, so its stock was entered by hand in Products back then. It is recorded as <strong>history only</strong> &mdash; stock is <strong>not</strong> added again.'
            : 'After linking, it becomes a normal purchase order: use <strong>Receive Delivery</strong> when the stock actually arrives.') . '</p>';
    echo '<form method="POST" data-po-form>' . csrf_field() . '<input type="hidden" name="action" value="link_legacy"><input type="hidden" name="po_id" value="' . $po_id . '">'
        . '<div class="form-errors" role="alert"></div>' . frag_lines_editor($conn);
    if ($delivered) {
        echo '<label class="verify-box"><input type="checkbox" name="confirm_history" value="1" required> I confirm this stock is already counted in Products. Record it as history only and do not add it again.</label>';
    }
    echo '<div class="frag-actions"><button type="button" class="btn-plain" data-frag-close>Cancel</button><button type="submit" class="btn-add"><i class="fas fa-link"></i> Link Items</button></div></form></div>';
}
