<?php
/**
 * Supplier purchasing and stock receiving (modules/admin/delivery.php).
 *
 * A purchase order (PO) is a row of tbl_supplier_deliveries (the table the old "Record Delivery"
 * used, so its history is kept) with one tbl_purchase_order_items row per product / size ordered.
 *
 *   Draft -> Ordered (status 'pending') -> In Transit -> Partially Received -> Received ('delivered')
 *   Draft / Ordered / In Transit with nothing received -> Cancelled
 *   Partially Received -> Closed with Shortage (the rest is not coming)
 *
 * Stock changes ONLY when a delivery is received (purch_receive): one transaction creates the
 * receipt (tbl_stock_receipts + items), one inventory batch per accepted line (its own cost, lot,
 * expiry), raises the stock column by the accepted quantity, logs tbl_stock_movements and the
 * batch movement, and updates the PO's received quantities and status. Rejected units are recorded
 * on the receipt (for supplier discrepancies) but never reach stock. A request token (UNIQUE) and
 * the locked PO row make a repeated submit a no-op.
 *
 * Old deliveries (before purchase orders) are "legacy": free text only, never touched by stock.
 * purch_link_legacy() lets the admin link them to real products; a delivered one is recorded as
 * history only (its stock was entered by hand in Products, so it is not added again).
 */

require_once __DIR__ . '/inventory_batch_helper.php';

const PURCH_STATUS_LABELS = [
    'draft'              => 'Draft',
    'pending'            => 'Ordered',
    'in_transit'         => 'In Transit',
    'partially_received' => 'Partially Received',
    'delivered'          => 'Received',
    'closed_short'       => 'Closed with Shortage',
    'cancelled'          => 'Cancelled',
];
const PURCH_REJECT_REASONS = [
    'damaged'     => 'Damaged',
    'expired'     => 'Expired / short-dated',
    'wrong_item'  => 'Wrong item',
    'quality'     => 'Failed quality check',
    'other'       => 'Other',
];
const PURCH_RECEIVABLE = ['pending', 'in_transit', 'partially_received'];

/** Who may do what. Staff may order, track and receive; corrections stay with the admin. */
function purch_can(string $role, string $action): bool
{
    if ($role === 'admin') {
        return true;
    }
    return $role === 'staff' && in_array($action, ['view', 'create_po', 'submit', 'mark_in_transit', 'receive'], true);
}

function purch_column_exists(mysqli $conn, string $table, string $column): bool
{
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    return $r && $r->num_rows > 0;
}

/** Every table/column this module needs. DDL: call on page load, outside any transaction. */
function purch_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // The original tables (kept exactly as delivery.php created them).
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_supplier_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_email VARCHAR(255) DEFAULT NULL,
        supplier_name VARCHAR(255) NOT NULL,
        items_summary TEXT NOT NULL,
        tracking_number VARCHAR(100) DEFAULT NULL,
        status ENUM('pending', 'in_transit', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
        expected_date DATE DEFAULT NULL,
        received_date DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!purch_column_exists($conn, 'tbl_supplier_deliveries', 'supplier_email')) {
        $conn->query("ALTER TABLE `tbl_supplier_deliveries` ADD COLUMN `supplier_email` VARCHAR(255) DEFAULT NULL AFTER `id`");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_name VARCHAR(255) NOT NULL,
        brand_name VARCHAR(255) DEFAULT NULL,
        contact_person VARCHAR(255) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!purch_column_exists($conn, 'tbl_supplier_deliveries', 'supplier_id')) {
        $conn->query("ALTER TABLE `tbl_supplier_deliveries` ADD COLUMN `supplier_id` INT DEFAULT NULL AFTER `id`");
    }
    // One-time back-fill: seed the directory from suppliers already used in past deliveries.
    $directory_empty = (int) ($conn->query("SELECT COUNT(*) AS c FROM tbl_suppliers")->fetch_assoc()['c'] ?? 0) === 0;
    if ($directory_empty) {
        $conn->query("INSERT INTO tbl_suppliers (supplier_name, email)
                      SELECT TRIM(supplier_name), MAX(NULLIF(TRIM(supplier_email), ''))
                      FROM tbl_supplier_deliveries
                      WHERE TRIM(supplier_name) <> ''
                      GROUP BY TRIM(supplier_name)");
        $conn->query("UPDATE tbl_supplier_deliveries d
                      JOIN tbl_suppliers s ON s.supplier_name = TRIM(d.supplier_name)
                      SET d.supplier_id = s.id
                      WHERE d.supplier_id IS NULL");
    }

    // --- Purchase order columns on the deliveries table ---
    if (!purch_column_exists($conn, 'tbl_supplier_deliveries', 'is_legacy')) {
        $conn->query("ALTER TABLE tbl_supplier_deliveries ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0");
        // Everything recorded before purchase orders existed is free text: legacy.
        $conn->query("UPDATE tbl_supplier_deliveries SET is_legacy = 1");
    }
    $cols = [
        'po_number'      => "VARCHAR(30) NULL DEFAULT NULL AFTER id",
        'total_amount'   => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
        'created_by'     => "INT NULL DEFAULT NULL",
        'submitted_at'   => "DATETIME NULL DEFAULT NULL",
        'in_transit_at'  => "DATETIME NULL DEFAULT NULL",
        'closed_reason'  => "VARCHAR(255) NULL DEFAULT NULL",
        'closed_by'      => "INT NULL DEFAULT NULL",
        'closed_at'      => "DATETIME NULL DEFAULT NULL",
    ];
    foreach ($cols as $c => $def) {
        if (!purch_column_exists($conn, 'tbl_supplier_deliveries', $c)) {
            $conn->query("ALTER TABLE tbl_supplier_deliveries ADD COLUMN $c $def");
        }
    }
    $st = $conn->query("SHOW COLUMNS FROM tbl_supplier_deliveries LIKE 'status'")->fetch_assoc();
    if ($st && strpos((string) $st['Type'], 'partially_received') === false) {
        $conn->query("ALTER TABLE tbl_supplier_deliveries MODIFY status
                      ENUM('draft','pending','in_transit','partially_received','delivered','closed_short','cancelled') NOT NULL DEFAULT 'pending'");
    }
    $conn->query("UPDATE tbl_supplier_deliveries SET po_number = CONCAT('PO-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 4, '0')) WHERE po_number IS NULL");
    $idx = $conn->query("SHOW INDEX FROM tbl_supplier_deliveries WHERE Key_name = 'uniq_po_number'");
    if ($idx && $idx->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_supplier_deliveries ADD UNIQUE KEY uniq_po_number (po_number), ADD KEY idx_po_supplier (supplier_id, status)");
    }

    inv_batches_ensure_schema($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS tbl_purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_id INT NOT NULL DEFAULT 0,
        item_label VARCHAR(255) NOT NULL,
        variant_label VARCHAR(100) NULL,
        qty_ordered INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        qty_received INT NOT NULL DEFAULT 0,
        qty_rejected INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_po_item (po_id, product_id, variant_id),
        KEY idx_poi_item (product_id, variant_id),
        CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES tbl_supplier_deliveries (id) ON DELETE RESTRICT,
        CONSTRAINT chk_poi_qty CHECK (qty_ordered > 0 AND qty_received >= 0 AND qty_received <= qty_ordered AND qty_rejected >= 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS tbl_stock_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_number VARCHAR(30) NULL,
        po_id INT NOT NULL,
        supplier_id INT NULL,
        request_token CHAR(32) NOT NULL,
        received_at DATETIME NOT NULL,
        received_by INT NULL,
        physically_verified TINYINT(1) NOT NULL DEFAULT 0,
        stock_applied TINYINT(1) NOT NULL DEFAULT 1,
        remarks TEXT NULL,
        total_qty INT NOT NULL DEFAULT 0,
        total_rejected INT NOT NULL DEFAULT 0,
        total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        status ENUM('confirmed','reversed') NOT NULL DEFAULT 'confirmed',
        reversed_at DATETIME NULL,
        reversed_by INT NULL,
        reversal_reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_receipt_number (receipt_number),
        UNIQUE KEY uniq_receipt_token (request_token),
        KEY idx_receipt_po (po_id),
        KEY idx_receipt_supplier (supplier_id, received_at),
        CONSTRAINT fk_receipt_po FOREIGN KEY (po_id) REFERENCES tbl_supplier_deliveries (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS tbl_stock_receipt_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL,
        po_item_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_id INT NOT NULL DEFAULT 0,
        item_label VARCHAR(255) NOT NULL,
        variant_label VARCHAR(100) NULL,
        qty_accepted INT NOT NULL DEFAULT 0,
        qty_rejected INT NOT NULL DEFAULT 0,
        reject_reason VARCHAR(30) NULL,
        item_condition VARCHAR(20) NOT NULL DEFAULT 'good',
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        expiry_date DATE NULL,
        lot_number VARCHAR(100) NULL,
        held TINYINT(1) NOT NULL DEFAULT 0,
        batch_id INT NULL,
        UNIQUE KEY uniq_receipt_line (receipt_id, po_item_id),
        KEY idx_ri_item (product_id, variant_id),
        CONSTRAINT fk_ri_receipt FOREIGN KEY (receipt_id) REFERENCES tbl_stock_receipts (id) ON DELETE RESTRICT,
        CONSTRAINT fk_ri_po_item FOREIGN KEY (po_item_id) REFERENCES tbl_purchase_order_items (id) ON DELETE RESTRICT,
        CONSTRAINT fk_ri_batch FOREIGN KEY (batch_id) REFERENCES tbl_inventory_batches (id) ON DELETE RESTRICT,
        CONSTRAINT chk_ri_qty CHECK (qty_accepted >= 0 AND qty_rejected >= 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/* ------------------------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------------------------- */

function purch_money(float $v): string
{
    return '₱' . number_format($v, 2);
}

function purch_item_label(string $name, ?string $variant): string
{
    return $name . ($variant !== null && $variant !== '' ? ' (' . $variant . ')' : '');
}

function purch_activity(mysqli $conn, int $user_id, string $action, string $details): void
{
    try {
        $s = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $s->bind_param('iss', $user_id, $action, $details);
        $s->execute();
        $s->close();
    } catch (Throwable $e) {
        error_log('purch_activity: ' . $e->getMessage());
    }
}

function purch_new_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Every orderable item: one entry per product without sizes, one per size otherwise.
 * key "pid:vid" => [product_id, variant_id, name, variant, label, stock, last_cost, last_supplier]
 */
function purch_catalog(mysqli $conn): array
{
    $last = purch_last_costs($conn);
    $items = [];
    $res = $conn->query("SELECT p.id, p.name, p.sku, p.stock, v.id AS vid, v.size, v.stock AS vstock
                         FROM tbl_product_inventory p
                         LEFT JOIN tbl_product_variants v ON v.product_id = p.id
                         ORDER BY p.name, v.size");
    while ($res && ($r = $res->fetch_assoc())) {
        $pid = (int) $r['id'];
        $vid = (int) ($r['vid'] ?? 0);
        $key = $pid . ':' . $vid;
        $items[$key] = [
            'product_id' => $pid,
            'variant_id' => $vid,
            'name'       => (string) $r['name'],
            'variant'    => $vid ? (string) $r['size'] : '',
            'label'      => purch_item_label((string) $r['name'], $vid ? (string) $r['size'] : null),
            'sku'        => (string) $r['sku'],
            'stock'      => (int) ($vid ? $r['vstock'] : $r['stock']),
            'last_cost'  => $last[$key]['unit_cost'] ?? null,
            'last_date'  => $last[$key]['received_at'] ?? null,
        ];
    }
    return $items;
}

/** Latest confirmed purchase cost per item ("pid:vid"). */
function purch_last_costs(mysqli $conn): array
{
    $out = [];
    $res = $conn->query("SELECT ri.product_id, ri.variant_id, ri.unit_cost, r.received_at
                         FROM tbl_stock_receipt_items ri JOIN tbl_stock_receipts r ON r.id = ri.receipt_id
                         WHERE r.status = 'confirmed' AND ri.qty_accepted > 0
                         ORDER BY r.received_at ASC, ri.id ASC");
    while ($res && ($r = $res->fetch_assoc())) {
        $out[$r['product_id'] . ':' . $r['variant_id']] = ['unit_cost' => (float) $r['unit_cost'], 'received_at' => $r['received_at']];
    }
    return $out;
}

/** Parses + validates PO lines from the form. Returns [lines, errors]. */
function purch_parse_lines(mysqli $conn, $raw): array
{
    $catalog = purch_catalog($conn);
    $has_variants = [];
    foreach ($catalog as $c) {
        if ($c['variant_id'] > 0) {
            $has_variants[$c['product_id']] = true;
        }
    }
    $lines = [];
    $errors = [];
    if (!is_array($raw)) {
        $raw = [];
    }
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row['item'] ?? ''));
        $qty_raw = trim((string) ($row['qty'] ?? ''));
        $cost_raw = trim((string) ($row['cost'] ?? ''));
        if ($key === '' && $qty_raw === '' && $cost_raw === '') {
            continue; // empty row
        }
        if ($key === '' || !isset($catalog[$key])) {
            $errors[] = 'Choose a product for every line.';
            continue;
        }
        $c = $catalog[$key];
        if ($c['variant_id'] === 0 && !empty($has_variants[$c['product_id']])) {
            $errors[] = "{$c['name']} has sizes - choose a size.";
            continue;
        }
        if (!preg_match('/^\d+$/', $qty_raw) || (int) $qty_raw < 1 || (int) $qty_raw > 100000) {
            $errors[] = "{$c['label']}: enter a whole quantity from 1 to 100,000.";
            continue;
        }
        if ($cost_raw === '' || !is_numeric($cost_raw) || (float) $cost_raw < 0 || (float) $cost_raw > 9999999) {
            $errors[] = "{$c['label']}: enter the agreed unit cost (0 or more).";
            continue;
        }
        if (isset($lines[$key])) {
            $errors[] = "{$c['label']} is listed twice - put the full quantity on one line.";
            continue;
        }
        $lines[$key] = $c + ['qty' => (int) $qty_raw, 'cost' => round((float) $cost_raw, 2)];
    }
    if (!$lines && !$errors) {
        $errors[] = 'Add at least one product to the purchase order.';
    }
    return [array_values($lines), array_values(array_unique($errors))];
}

function purch_lines_summary(array $lines): string
{
    $out = [];
    foreach ($lines as $l) {
        $out[] = $l['qty'] . ' x ' . $l['label'] . ' @ ' . purch_money($l['cost']) . ' = ' . purch_money($l['qty'] * $l['cost']);
    }
    return implode("\n", $out);
}

function purch_fetch_po(mysqli $conn, int $po_id, bool $lock = false): ?array
{
    $s = $conn->prepare("SELECT d.*, s.brand_name AS supplier_brand, s.phone AS supplier_phone, s.is_active AS supplier_active,
                                s.contact_person AS supplier_contact, s.address AS supplier_address
                         FROM tbl_supplier_deliveries d LEFT JOIN tbl_suppliers s ON s.id = d.supplier_id
                         WHERE d.id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $s->bind_param('i', $po_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function purch_po_items(mysqli $conn, int $po_id, bool $lock = false): array
{
    $s = $conn->prepare("SELECT * FROM tbl_purchase_order_items WHERE po_id = ? ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $s->bind_param('i', $po_id);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

/* ------------------------------------------------------------------------------------------
 * Purchase orders: create / edit draft / status changes
 * ---------------------------------------------------------------------------------------- */

/**
 * Creates a PO, or replaces a draft's contents ($po_id > 0). $submit = place the order now
 * (status Ordered) instead of keeping a draft. Returns ['ok','message','po_id','warning'].
 */
/** Main categories offered by the New Purchase Order "+ New product" form (same list as Products). */
const PURCH_QUICK_CATEGORIES = ['Dog Food', 'Cat Food', 'Chicken', 'Chicken Feed', 'Dog Essentials', 'Cat Essentials', 'Dog & Cat Medicine', 'Skin Care & Treatment'];

/**
 * "+ New product" in a purchase order: creates a catalog product that is not in the system yet, with
 * 0 stock (stock only comes in through Receive Delivery). Gets an ABC-001 SKU and a template
 * description; picture, sizes and the rest can be added later on the Products page.
 * Returns ['ok', 'message', 'product' => ['key' => 'pid:0', 'label', 'sku']].
 */
function purch_quick_product(mysqli $conn, array $actor, array $in): array
{
    require_once __DIR__ . '/sku_helper.php';
    require_once __DIR__ . '/product_description_helper.php';
    $clean = static fn($v) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
    $name = mb_substr($clean($in['qp_name'] ?? ''), 0, 255);
    $price = round((float) ($in['qp_price'] ?? 0), 2);
    $weight = max(0.0, round((float) ($in['qp_weight'] ?? 0), 3));
    $cat = $clean($in['qp_category'] ?? '');
    $brand = mb_substr($clean($in['qp_brand'] ?? ''), 0, 80);
    if ($name === '') {
        return ['ok' => false, 'message' => 'Type the product name.'];
    }
    if ($price <= 0) {
        return ['ok' => false, 'message' => 'Type the selling price (more than 0).'];
    }
    if ($cat !== '' && !in_array($cat, PURCH_QUICK_CATEGORIES, true)) {
        $cat = '';
    }
    $st = $conn->prepare("SELECT p.id, p.sku, p.name, (SELECT COUNT(*) FROM tbl_product_variants v WHERE v.product_id = p.id) AS sizes
                          FROM tbl_product_inventory p WHERE LOWER(p.name) = LOWER(?) LIMIT 1");
    $st->bind_param('s', $name);
    $st->execute();
    $dup = $st->get_result()->fetch_assoc();
    $st->close();
    if ($dup && (int) $dup['sizes'] > 0) {
        return ['ok' => false, 'message' => '"' . $dup['name'] . '" is already a product with sizes (' . $dup['sku'] . '). Type its name in the search box and pick the size.'];
    }
    if ($dup) {
        // Already in the catalog (maybe just added from this form): pick it instead of making a duplicate.
        return ['ok' => true, 'message' => '"' . $dup['name'] . '" is already a product (' . $dup['sku'] . '), so it was picked.',
            'product' => ['key' => (int) $dup['id'] . ':0', 'label' => (string) $dup['name'], 'sku' => (string) $dup['sku']]];
    }
    try {
        $sku = sku_generate($conn, $name);
    } catch (SkuLimitException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $category = implode(', ', array_filter([$cat, $brand], 'strlen')) ?: 'General';
    $desc = product_description_template(product_description_input([
        'name' => $name, 'product_category_input' => $cat, 'brand_category' => $brand, 'price' => $price, 'weight_kg' => $weight,
    ]));
    $stock = 0;
    $low = 10;
    $img = '';
    $st = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, weight_kg, stock, category, image_url, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param('sssddissi', $sku, $name, $desc, $price, $weight, $stock, $category, $img, $low);
    $st->execute();
    $pid = (int) $conn->insert_id;
    $st->close();
    if ($brand !== '') {
        $st = $conn->prepare("INSERT IGNORE INTO tbl_custom_categories (group_name, category_value) VALUES ('Brand', ?)");
        if ($st) {
            $st->bind_param('s', $brand);
            $st->execute();
            $st->close();
        }
    }
    $uid = (int) ($actor['user_id'] ?? 0);
    $action = 'Added New Product';
    $details = "User added product: $name (SKU: $sku) from a purchase order, with 0 stock.";
    $st = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
    if ($st) {
        $st->bind_param('iss', $uid, $action, $details);
        $st->execute();
        $st->close();
    }
    return ['ok' => true, 'message' => "New product added: $name ($sku). It has 0 stock until you receive it.",
        'product' => ['key' => $pid . ':0', 'label' => $name, 'sku' => $sku]];
}

function purch_save_po(mysqli $conn, array $actor, array $in, bool $submit, int $po_id = 0): array
{
    if (!purch_can($actor['role'], 'create_po')) {
        return ['ok' => false, 'message' => 'You do not have permission to create purchase orders.'];
    }
    $supplier_id = (int) ($in['supplier_id'] ?? 0);
    $tracking = mb_substr(trim((string) ($in['tracking_number'] ?? '')), 0, 100);
    $expected = trim((string) ($in['expected_date'] ?? ''));
    $remarks = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000);
    $errors = [];

    $sup = null;
    if ($supplier_id > 0) {
        $s = $conn->prepare("SELECT id, supplier_name, email, is_active FROM tbl_suppliers WHERE id = ?");
        $s->bind_param('i', $supplier_id);
        $s->execute();
        $sup = $s->get_result()->fetch_assoc();
        $s->close();
    }
    if (!$sup) {
        $errors[] = 'Choose a supplier from your directory.';
    } elseif ((int) $sup['is_active'] !== 1) {
        $errors[] = "{$sup['supplier_name']} is inactive. Reactivate the supplier first or choose another one.";
    }
    if ($expected !== '' && !inv_valid_date($expected)) {
        $errors[] = 'Enter a valid expected delivery date.';
    }
    [$lines, $line_errors] = purch_parse_lines($conn, $in['items'] ?? []);
    $errors = array_merge($errors, $line_errors);
    if ($errors) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $total = 0.0;
    foreach ($lines as $l) {
        $total += $l['qty'] * $l['cost'];
    }
    $total = round($total, 2);
    $summary = purch_lines_summary($lines);
    $status = $submit ? 'pending' : 'draft';
    $expected_db = $expected !== '' ? $expected : null;
    $tracking_db = $tracking !== '' ? $tracking : null;
    $name = (string) $sup['supplier_name'];
    $email = (string) ($sup['email'] ?? '');
    $uid = (int) $actor['user_id'];

    $conn->begin_transaction();
    try {
        if ($po_id > 0) {
            $po = purch_fetch_po($conn, $po_id, true);
            if (!$po) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Purchase order not found.'];
            }
            if ($po['status'] !== 'draft') {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Only a draft can be edited. This purchase order is already ' . strtolower(PURCH_STATUS_LABELS[$po['status']] ?? $po['status']) . '.'];
            }
            $u = $conn->prepare("UPDATE tbl_supplier_deliveries SET supplier_id = ?, supplier_name = ?, supplier_email = ?, items_summary = ?, tracking_number = ?,
                                 expected_date = ?, notes = ?, total_amount = ?, status = ?, submitted_at = IF(? = 'pending', NOW(), NULL) WHERE id = ?");
            $u->bind_param('issssssdssi', $supplier_id, $name, $email, $summary, $tracking_db, $expected_db, $remarks, $total, $status, $status, $po_id);
            $u->execute();
            $u->close();
            $d = $conn->prepare("DELETE FROM tbl_purchase_order_items WHERE po_id = ?");
            $d->bind_param('i', $po_id);
            $d->execute();
            $d->close();
        } else {
            $i = $conn->prepare("INSERT INTO tbl_supplier_deliveries (supplier_id, supplier_name, supplier_email, items_summary, tracking_number, expected_date, notes,
                                 status, total_amount, created_by, submitted_at, is_legacy)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'pending', NOW(), NULL), 0)");
            $i->bind_param('isssssssdis', $supplier_id, $name, $email, $summary, $tracking_db, $expected_db, $remarks, $status, $total, $uid, $status);
            $i->execute();
            $po_id = (int) $conn->insert_id;
            $i->close();
            $u = $conn->prepare("UPDATE tbl_supplier_deliveries SET po_number = CONCAT('PO-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 4, '0')) WHERE id = ?");
            $u->bind_param('i', $po_id);
            $u->execute();
            $u->close();
        }
        $li = $conn->prepare("INSERT INTO tbl_purchase_order_items (po_id, product_id, variant_id, item_label, variant_label, qty_ordered, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($lines as $l) {
            $vlabel = $l['variant'] !== '' ? $l['variant'] : null;
            $li->bind_param('iiissid', $po_id, $l['product_id'], $l['variant_id'], $l['name'], $vlabel, $l['qty'], $l['cost']);
            $li->execute();
        }
        $li->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('purch_save_po failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not save the purchase order. Please try again.'];
    }

    $po = purch_fetch_po($conn, $po_id);
    $number = (string) $po['po_number'];
    purch_activity($conn, $uid, $submit ? 'Placed Purchase Order' : 'Saved Purchase Order Draft',
        "$number for $name: " . count($lines) . ' item(s), ' . purch_money($total) . ($submit ? '' : ' (draft)'));
    $warning = null;
    $message = $submit ? "Purchase order $number placed with $name." : "Draft $number saved. Nothing is ordered until you submit it.";
    if ($submit) {
        $sent = purch_email_supplier($po, $lines);
        if ($sent === true) {
            $message .= ' The purchase order was emailed to the supplier.';
        } elseif ($sent === false) {
            $warning = "$number was saved, but the email to the supplier could not be sent. Contact them directly.";
        }
    }
    return ['ok' => true, 'message' => $message, 'po_id' => $po_id, 'warning' => $warning];
}

/** Emails the PO to the supplier. true = sent, false = failed, null = no email on file. Never throws. */
function purch_email_supplier(array $po, array $lines): ?bool
{
    $to = trim((string) ($po['supplier_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('notifications_mailer')) {
        return null;
    }
    try {
        $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        $text = '';
        $total = 0.0;
        foreach ($lines as $l) {
            $line = $l['qty'] * $l['cost'];
            $total += $line;
            $rows .= '<tr><td style="padding:6px 10px;border:1px solid #ddd">' . $h($l['label']) . '</td><td style="padding:6px 10px;border:1px solid #ddd;text-align:right">' . (int) $l['qty']
                . '</td><td style="padding:6px 10px;border:1px solid #ddd;text-align:right">' . $h(purch_money($l['cost'])) . '</td><td style="padding:6px 10px;border:1px solid #ddd;text-align:right">' . $h(purch_money($line)) . '</td></tr>';
            $text .= '- ' . $l['qty'] . ' x ' . $l['label'] . ' @ ' . purch_money($l['cost']) . ' = ' . purch_money($line) . "\n";
        }
        $mail = notifications_mailer();
        $mail->addAddress($to, (string) $po['supplier_name']);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SYSTEM_NAME);
        $mail->Subject = 'Purchase Order ' . $po['po_number'] . ' from ' . SYSTEM_NAME;
        $expected = !empty($po['expected_date']) ? date('F j, Y', strtotime($po['expected_date'])) : 'as soon as possible';
        $mail->Body = '<p>Hello ' . $h($po['supplier_name']) . ',</p>'
            . '<p>We would like to place purchase order <strong>' . $h($po['po_number']) . '</strong>:</p>'
            . '<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px"><thead><tr>'
            . '<th style="padding:6px 10px;border:1px solid #ddd;text-align:left">Item</th><th style="padding:6px 10px;border:1px solid #ddd">Qty</th>'
            . '<th style="padding:6px 10px;border:1px solid #ddd">Unit cost</th><th style="padding:6px 10px;border:1px solid #ddd">Amount</th></tr></thead><tbody>' . $rows
            . '</tbody><tfoot><tr><td colspan="3" style="padding:6px 10px;border:1px solid #ddd;text-align:right"><strong>Total</strong></td><td style="padding:6px 10px;border:1px solid #ddd;text-align:right"><strong>'
            . $h(purch_money($total)) . '</strong></td></tr></tfoot></table>'
            . '<p>Requested delivery: ' . $h($expected) . '</p>'
            . (!empty($po['notes']) ? '<p><strong>Notes:</strong> ' . nl2br($h($po['notes'])) . '</p>' : '')
            . '<p>Please confirm this order and reply with your delivery schedule. Kindly quote ' . $h($po['po_number']) . ' on your delivery receipt.</p>'
            . '<p>Thank you,<br>' . $h(SYSTEM_NAME) . '</p>';
        $mail->AltBody = "Hello {$po['supplier_name']},\n\nWe would like to place purchase order {$po['po_number']}:\n\n$text\nTotal: " . purch_money($total)
            . "\nRequested delivery: $expected\n" . (!empty($po['notes']) ? "Notes: {$po['notes']}\n" : '')
            . "\nPlease confirm this order and quote {$po['po_number']} on your delivery receipt.\n\nThank you,\n" . SYSTEM_NAME;
        return (bool) $mail->send();
    } catch (Throwable $e) {
        error_log('Supplier purchase order email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Status changes that are not receiving. $action: submit | mark_in_transit | cancel | close_short.
 * Returns ['ok','message','warning'].
 */
function purch_change_status(mysqli $conn, array $actor, int $po_id, string $action, string $reason = ''): array
{
    if (!purch_can($actor['role'], $action)) {
        return ['ok' => false, 'message' => 'You do not have permission to do that.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (in_array($action, ['cancel', 'close_short'], true) && mb_strlen($reason) < 3) {
        return ['ok' => false, 'message' => 'Please give a reason.'];
    }
    $conn->begin_transaction();
    try {
        $po = purch_fetch_po($conn, $po_id, true);
        if (!$po) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        $status = (string) $po['status'];
        $items = purch_po_items($conn, $po_id, true);
        $received = (int) array_sum(array_column($items, 'qty_received'));
        $from = PURCH_STATUS_LABELS[$status] ?? $status;
        $bad = static fn(string $why) => ['ok' => false, 'message' => $why];
        $uid = (int) $actor['user_id'];

        switch ($action) {
            case 'submit':
                if ($status !== 'draft') {
                    $conn->rollback();
                    return $bad("Only a draft can be submitted. {$po['po_number']} is $from.");
                }
                if (!$items) {
                    $conn->rollback();
                    return $bad('Add at least one product before submitting.');
                }
                if ((int) ($po['supplier_active'] ?? 0) !== 1) {
                    $conn->rollback();
                    return $bad('This supplier is inactive. Reactivate them or edit the draft to choose another supplier.');
                }
                $sql = "UPDATE tbl_supplier_deliveries SET status = 'pending', submitted_at = NOW() WHERE id = ?";
                $to = 'pending';
                break;
            case 'mark_in_transit':
                if ($status !== 'pending') {
                    $conn->rollback();
                    return $bad("Only an ordered purchase order can be marked In Transit. {$po['po_number']} is $from.");
                }
                $sql = "UPDATE tbl_supplier_deliveries SET status = 'in_transit', in_transit_at = NOW() WHERE id = ?";
                $to = 'in_transit';
                break;
            case 'cancel':
                if (!in_array($status, ['draft', 'pending', 'in_transit'], true)) {
                    $conn->rollback();
                    return $bad("{$po['po_number']} is $from and cannot be cancelled." . ($status === 'partially_received' ? ' Part of it was received - use Close with Shortage instead.' : ''));
                }
                if ($received > 0) {
                    $conn->rollback();
                    return $bad('Part of this order was already received. Use Close with Shortage instead.');
                }
                $sql = "UPDATE tbl_supplier_deliveries SET status = 'cancelled', closed_reason = ?, closed_by = ?, closed_at = NOW() WHERE id = ?";
                $to = 'cancelled';
                break;
            case 'close_short':
                if ($status !== 'partially_received') {
                    $conn->rollback();
                    return $bad("Only a partially received purchase order can be closed with a shortage. {$po['po_number']} is $from.");
                }
                $sql = "UPDATE tbl_supplier_deliveries SET status = 'closed_short', closed_reason = ?, closed_by = ?, closed_at = NOW() WHERE id = ?";
                $to = 'closed_short';
                break;
            default:
                $conn->rollback();
                return $bad('Unknown action.');
        }
        $u = $conn->prepare($sql);
        if (in_array($action, ['cancel', 'close_short'], true)) {
            $u->bind_param('sii', $reason, $uid, $po_id);
        } else {
            $u->bind_param('i', $po_id);
        }
        $u->execute();
        $u->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('purch_change_status failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the purchase order. Please try again.'];
    }

    $to_label = PURCH_STATUS_LABELS[$to];
    $details = "{$po['po_number']} ({$po['supplier_name']}): $from -> $to_label" . ($reason !== '' ? ". Reason: $reason" : '');
    if ($action === 'close_short') {
        $short = 0;
        foreach ($items as $it) {
            $short += max(0, (int) $it['qty_ordered'] - (int) $it['qty_received']);
        }
        $details .= ". $short unit(s) not delivered.";
    }
    purch_activity($conn, (int) $actor['user_id'], 'Purchase Order Status', $details);
    $warning = null;
    $message = "{$po['po_number']} is now $to_label.";
    if ($action === 'submit') {
        $lines = [];
        foreach ($items as $it) {
            $lines[] = ['qty' => (int) $it['qty_ordered'], 'cost' => (float) $it['unit_cost'], 'label' => purch_item_label($it['item_label'], $it['variant_label'])];
        }
        $sent = purch_email_supplier(purch_fetch_po($conn, $po_id), $lines);
        if ($sent === true) {
            $message .= ' The purchase order was emailed to the supplier.';
        } elseif ($sent === false) {
            $warning = 'The order was placed, but the email to the supplier could not be sent. Contact them directly.';
        }
    }
    return ['ok' => true, 'message' => $message, 'warning' => $warning];
}

/** Status after receiving / reversing, from the line quantities. */
function purch_status_from_items(array $items, string $current, ?string $in_transit_at): string
{
    $all = true;
    $any = false;
    foreach ($items as $it) {
        if ((int) $it['qty_received'] < (int) $it['qty_ordered']) {
            $all = false;
        }
        if ((int) $it['qty_received'] > 0 || (int) $it['qty_rejected'] > 0) {
            $any = true;
        }
    }
    if ($items && $all) {
        return 'delivered';
    }
    if ($any) {
        return $current === 'closed_short' ? 'closed_short' : 'partially_received';
    }
    return $in_transit_at ? 'in_transit' : 'pending';
}

/* ------------------------------------------------------------------------------------------
 * Receiving
 * ---------------------------------------------------------------------------------------- */

/**
 * Receives a delivery against a PO. $in: token, received_at, remarks, verified, lines[po_item_id] =>
 * [accepted, rejected, reject_reason, unit_cost, expiry, lot, hold]. Returns
 * ['ok', 'message', 'receipt_id', 'duplicate' => bool].
 */
function purch_receive(mysqli $conn, array $actor, int $po_id, array $in): array
{
    if (!purch_can($actor['role'], 'receive')) {
        return ['ok' => false, 'message' => 'You do not have permission to receive deliveries.'];
    }
    $uid = (int) $actor['user_id'];
    $token = strtolower(trim((string) ($in['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return ['ok' => false, 'message' => 'This form has expired. Close it and open Receive Delivery again.'];
    }
    $dup = purch_receipt_by_token($conn, $token, false);
    if ($dup) {
        return ['ok' => true, 'duplicate' => true, 'receipt_id' => (int) $dup['id'],
                'message' => "This delivery was already recorded as {$dup['receipt_number']}. Nothing was added again."];
    }
    if (empty($in['verified'])) {
        return ['ok' => false, 'message' => 'Confirm that you physically counted and checked the items before receiving them.'];
    }
    $when_raw = trim((string) ($in['received_at'] ?? ''));
    $when_ts = $when_raw !== '' ? strtotime($when_raw) : time();
    if ($when_ts === false) {
        return ['ok' => false, 'message' => 'Enter a valid receiving date and time.'];
    }
    if ($when_ts > time() + 300) {
        return ['ok' => false, 'message' => 'The receiving date cannot be in the future.'];
    }
    $received_at = date('Y-m-d H:i:s', $when_ts);
    $received_day = date('Y-m-d', $when_ts);
    $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 2000);
    $raw_lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];

    inv_remap_variant_ids($conn);
    $touched = [];
    $conn->begin_transaction();
    try {
        $po = purch_fetch_po($conn, $po_id, true);
        if (!$po) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        // A second submit that waited for the lock above sees the first one's receipt here.
        $dup = purch_receipt_by_token($conn, $token, true);
        if ($dup) {
            $conn->rollback();
            return ['ok' => true, 'duplicate' => true, 'receipt_id' => (int) $dup['id'],
                    'message' => "This delivery was already recorded as {$dup['receipt_number']}. Nothing was added again."];
        }
        if ((int) $po['is_legacy'] === 1) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This is an old delivery record without linked products. Link its items first.'];
        }
        if (!in_array($po['status'], PURCH_RECEIVABLE, true)) {
            $conn->rollback();
            return ['ok' => false, 'message' => "{$po['po_number']} is " . strtolower(PURCH_STATUS_LABELS[$po['status']] ?? $po['status']) . ' and cannot receive stock.'];
        }
        if (strtotime($received_day) < strtotime(date('Y-m-d', strtotime($po['created_at'])))) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'The receiving date cannot be before the purchase order date.'];
        }

        $items = purch_po_items($conn, $po_id, true);
        $by_id = [];
        foreach ($items as $it) {
            $by_id[(int) $it['id']] = $it;
        }
        $errors = [];
        $plan = [];
        foreach ($raw_lines as $item_id => $l) {
            $item_id = (int) $item_id;
            if (!isset($by_id[$item_id]) || !is_array($l)) {
                continue;
            }
            $it = $by_id[$item_id];
            $label = purch_item_label($it['item_label'], $it['variant_label']);
            $acc_raw = trim((string) ($l['accepted'] ?? ''));
            $rej_raw = trim((string) ($l['rejected'] ?? ''));
            $acc = $acc_raw === '' ? 0 : $acc_raw;
            $rej = $rej_raw === '' ? 0 : $rej_raw;
            if (!preg_match('/^\d+$/', (string) $acc) || !preg_match('/^\d+$/', (string) $rej)) {
                $errors[] = "$label: quantities must be whole numbers of 0 or more.";
                continue;
            }
            $acc = (int) $acc;
            $rej = (int) $rej;
            if ($acc === 0 && $rej === 0) {
                continue;
            }
            $remaining = (int) $it['qty_ordered'] - (int) $it['qty_received'];
            if ($remaining <= 0) {
                $errors[] = "$label is already fully received.";
                continue;
            }
            if ($acc + $rej > $remaining) {
                $errors[] = "$label: only $remaining still expected - accepted ($acc) plus rejected ($rej) cannot be more.";
                continue;
            }
            $reason = (string) ($l['reject_reason'] ?? '');
            if ($rej > 0 && !isset(PURCH_REJECT_REASONS[$reason])) {
                $errors[] = "$label: choose why $rej unit(s) were rejected.";
                continue;
            }
            $cost_raw = trim((string) ($l['unit_cost'] ?? ''));
            if ($cost_raw === '' || !is_numeric($cost_raw) || (float) $cost_raw < 0 || (float) $cost_raw > 9999999) {
                $errors[] = "$label: enter the actual unit cost (0 or more).";
                continue;
            }
            $expiry = trim((string) ($l['expiry'] ?? ''));
            if ($expiry !== '' && !inv_valid_date($expiry)) {
                $errors[] = "$label: enter a valid expiration date.";
                continue;
            }
            if ($expiry !== '' && $acc > 0 && $expiry < $received_day) {
                $errors[] = "$label: expired on " . date('M j, Y', strtotime($expiry)) . ' - reject these units instead of accepting them.';
                continue;
            }
            // The product / size must still exist.
            $pid = (int) $it['product_id'];
            $vid = (int) $it['variant_id'];
            if ($vid > 0) {
                $chk = $conn->prepare("SELECT 1 FROM tbl_product_variants WHERE id = ? AND product_id = ?");
                $chk->bind_param('ii', $vid, $pid);
            } else {
                $chk = $conn->prepare("SELECT 1 FROM tbl_product_inventory p WHERE p.id = ? AND NOT EXISTS (SELECT 1 FROM tbl_product_variants v WHERE v.product_id = p.id)");
                $chk->bind_param('i', $pid);
            }
            $chk->execute();
            $exists = (bool) $chk->get_result()->fetch_row();
            $chk->close();
            if (!$exists) {
                $errors[] = "$label no longer exists in Products (deleted or its sizes changed). Fix the product first.";
                continue;
            }
            $plan[] = [
                'item' => $it, 'label' => $label, 'accepted' => $acc, 'rejected' => $rej,
                'reason' => $rej > 0 ? $reason : null, 'cost' => round((float) $cost_raw, 2),
                'expiry' => $expiry !== '' ? $expiry : null, 'lot' => mb_substr(trim((string) ($l['lot'] ?? '')), 0, 100),
                'hold' => !empty($l['hold']) && $acc > 0,
            ];
        }
        if ($errors) {
            $conn->rollback();
            return ['ok' => false, 'message' => implode(' ', array_unique($errors))];
        }
        if (!$plan) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Enter the quantity received (or rejected) for at least one item.'];
        }

        // Receipt header.
        $sid = $po['supplier_id'] !== null ? (int) $po['supplier_id'] : null;
        $r = $conn->prepare("INSERT INTO tbl_stock_receipts (po_id, supplier_id, request_token, received_at, received_by, physically_verified, stock_applied, remarks)
                             VALUES (?, ?, ?, ?, ?, 1, 1, ?)");
        $remarks_db = $remarks !== '' ? $remarks : null;
        $r->bind_param('iissis', $po_id, $sid, $token, $received_at, $uid, $remarks_db);
        $r->execute();
        $receipt_id = (int) $conn->insert_id;
        $r->close();
        $receipt_number = 'RCV-' . date('Ymd', $when_ts) . '-' . str_pad((string) $receipt_id, 4, '0', STR_PAD_LEFT);
        $u = $conn->prepare("UPDATE tbl_stock_receipts SET receipt_number = ? WHERE id = ?");
        $u->bind_param('si', $receipt_number, $receipt_id);
        $u->execute();
        $u->close();

        $tot_qty = 0;
        $tot_rej = 0;
        $tot_cost = 0.0;
        $ins_item = $conn->prepare("INSERT INTO tbl_stock_receipt_items (receipt_id, po_item_id, product_id, variant_id, item_label, variant_label, qty_accepted, qty_rejected,
                                    reject_reason, item_condition, unit_cost, line_total, expiry_date, lot_number, held)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd_po_item = $conn->prepare("UPDATE tbl_purchase_order_items SET qty_received = qty_received + ?, qty_rejected = qty_rejected + ? WHERE id = ?");
        foreach ($plan as $p) {
            $it = $p['item'];
            $pid = (int) $it['product_id'];
            $vid = (int) $it['variant_id'];
            $item_row = inv_lock_item($conn, $pid, $vid);
            // Settle any stock change made outside receiving, so this batch starts from a true count.
            inv_batch_sync_locked($conn, $pid, $vid, $item_row, 'adjust', 'Reconciled before receiving', $uid, 'receipt', $receipt_id);

            $line_total = round($p['accepted'] * $p['cost'], 2);
            $condition = $p['rejected'] > 0 ? (string) $p['reason'] : 'good';
            $po_item_id = (int) $it['id'];
            $vlabel = $it['variant_label'];
            $lot_db = $p['lot'] !== '' ? $p['lot'] : null;
            $held = $p['hold'] ? 1 : 0;
            $ins_item->bind_param('iiiissiissddssi', $receipt_id, $po_item_id, $pid, $vid, $it['item_label'], $vlabel, $p['accepted'], $p['rejected'],
                $p['reason'], $condition, $p['cost'], $line_total, $p['expiry'], $lot_db, $held);
            $ins_item->execute();
            $receipt_item_id = (int) $conn->insert_id;

            if ($p['accepted'] > 0) {
                $batch_id = inv_create_batch($conn, [
                    'product_id' => $pid, 'variant_id' => $vid, 'item_label' => $it['item_label'], 'variant_label' => $vlabel,
                    'source' => 'receipt', 'supplier_id' => $sid, 'receipt_id' => $receipt_id, 'receipt_item_id' => $receipt_item_id,
                    'lot_number' => $lot_db, 'unit_cost' => number_format($p['cost'], 2, '.', ''), 'qty_received' => $p['accepted'], 'qty_remaining' => $p['accepted'],
                    'expiry_date' => $p['expiry'], 'received_at' => $received_at, 'status' => $p['hold'] ? 'quarantined' : 'available',
                    'status_reason' => $p['hold'] ? 'Held for inspection at receiving' : null, 'created_by' => $uid,
                ]);
                $ub = $conn->prepare("UPDATE tbl_stock_receipt_items SET batch_id = ? WHERE id = ?");
                $ub->bind_param('ii', $batch_id, $receipt_item_id);
                $ub->execute();
                $ub->close();
                $code = 'BAT-' . str_pad((string) $batch_id, 6, '0', STR_PAD_LEFT);
                inv_log_batch_movement($conn, $batch_id, $pid, $vid, $p['accepted'], $p['accepted'], $p['hold'] ? 'receive_hold' : 'receive', 'receipt', $receipt_id,
                    "$receipt_number / {$po['po_number']}", $uid);
                if (!$p['hold']) {
                    $before = (int) $item_row['stock'];
                    $after = $before + $p['accepted'];
                    inv_set_item_stock($conn, $pid, $vid, $after);
                    inv_log_stock_movement($conn, $pid, $vid, $before, $after, 'supplier_delivery', "$receipt_number ({$po['po_number']}), batch $code", $uid);
                    $touched[] = [$pid, $vid];
                }
            }
            $upd_po_item->bind_param('iii', $p['accepted'], $p['rejected'], $po_item_id);
            $upd_po_item->execute();
            $tot_qty += $p['accepted'];
            $tot_rej += $p['rejected'];
            $tot_cost += $line_total;
        }
        $ins_item->close();
        $upd_po_item->close();

        $u = $conn->prepare("UPDATE tbl_stock_receipts SET total_qty = ?, total_rejected = ?, total_cost = ? WHERE id = ?");
        $u->bind_param('iidi', $tot_qty, $tot_rej, $tot_cost, $receipt_id);
        $u->execute();
        $u->close();

        $items = purch_po_items($conn, $po_id);
        $new_status = purch_status_from_items($items, (string) $po['status'], $po['in_transit_at']);
        $u = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = ?, received_date = IF(? = 'delivered', ?, received_date) WHERE id = ?");
        $u->bind_param('sssi', $new_status, $new_status, $received_at, $po_id);
        $u->execute();
        $u->close();
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        if ((int) $e->getCode() === 1062) {
            $dup = purch_receipt_by_token($conn, $token, false);
            if ($dup) {
                return ['ok' => true, 'duplicate' => true, 'receipt_id' => (int) $dup['id'],
                        'message' => "This delivery was already recorded as {$dup['receipt_number']}. Nothing was added again."];
            }
        }
        error_log('purch_receive failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record the delivery. Nothing was changed - please try again.'];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('purch_receive failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record the delivery. Nothing was changed - please try again.'];
    }

    foreach ($touched as [$pid, $vid]) {
        if (function_exists('evaluate_stock_alert')) {
            evaluate_stock_alert($conn, $vid > 0 ? 'tbl_product_variants' : 'tbl_product_inventory', $vid > 0 ? $vid : $pid);
        }
    }
    $label = PURCH_STATUS_LABELS[$new_status];
    purch_activity($conn, $uid, 'Received Stock', "$receipt_number for {$po['po_number']} ({$po['supplier_name']}): $tot_qty accepted, $tot_rej rejected, "
        . purch_money($tot_cost) . ". Purchase order is now $label.");
    $msg = "$receipt_number recorded: $tot_qty unit(s) added to stock" . ($tot_rej > 0 ? ", $tot_rej rejected" : '') . ". {$po['po_number']} is now $label.";
    $held = array_sum(array_map(static fn($p) => $p['hold'] ? $p['accepted'] : 0, $plan));
    if ($held > 0) {
        $msg = "$receipt_number recorded: " . ($tot_qty - $held) . " unit(s) added to stock, $held on hold for inspection" . ($tot_rej > 0 ? ", $tot_rej rejected" : '') . ". {$po['po_number']} is now $label.";
    }
    return ['ok' => true, 'duplicate' => false, 'receipt_id' => $receipt_id, 'message' => $msg];
}

function purch_receipt_by_token(mysqli $conn, string $token, bool $locking): ?array
{
    $s = $conn->prepare("SELECT id, receipt_number FROM tbl_stock_receipts WHERE request_token = ?" . ($locking ? ' LOCK IN SHARE MODE' : ''));
    $s->bind_param('s', $token);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/**
 * Admin correction: reverses a confirmed receipt. Only while none of its batches' units were sold
 * or adjusted (otherwise correct the stock with an inventory adjustment instead).
 */
function purch_reverse_receipt(mysqli $conn, array $actor, int $receipt_id, string $reason): array
{
    if (!purch_can($actor['role'], 'reverse')) {
        return ['ok' => false, 'message' => 'Only an admin can reverse a receipt.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Please explain why this receipt is being reversed (at least 5 characters).'];
    }
    $uid = (int) $actor['user_id'];
    $touched = [];
    $conn->begin_transaction();
    try {
        $s = $conn->prepare("SELECT * FROM tbl_stock_receipts WHERE id = ? FOR UPDATE");
        $s->bind_param('i', $receipt_id);
        $s->execute();
        $rc = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$rc) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Receipt not found.'];
        }
        if ($rc['status'] !== 'confirmed') {
            $conn->rollback();
            return ['ok' => false, 'message' => "{$rc['receipt_number']} was already reversed."];
        }
        $po = purch_fetch_po($conn, (int) $rc['po_id'], true);
        purch_po_items($conn, (int) $rc['po_id'], true);
        $s = $conn->prepare("SELECT * FROM tbl_stock_receipt_items WHERE receipt_id = ? ORDER BY id");
        $s->bind_param('i', $receipt_id);
        $s->execute();
        $lines = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        $upd_po_item = $conn->prepare("UPDATE tbl_purchase_order_items SET qty_received = qty_received - ?, qty_rejected = qty_rejected - ? WHERE id = ?");
        foreach ($lines as $l) {
            $pid = (int) $l['product_id'];
            $vid = (int) $l['variant_id'];
            $label = purch_item_label($l['item_label'], $l['variant_label']);
            if ((int) $rc['stock_applied'] === 1 && !empty($l['batch_id'])) {
                $item_row = inv_lock_item($conn, $pid, $vid);
                $b = inv_batch_fetch($conn, (int) $l['batch_id'], true);
                if (!$b || !$item_row) {
                    $conn->rollback();
                    return ['ok' => false, 'message' => "$label no longer exists in Products, so this receipt cannot be reversed automatically."];
                }
                inv_batch_sync_locked($conn, $pid, $vid, $item_row, 'adjust', 'Reconciled before reversing a receipt', $uid, 'receipt', $receipt_id);
                $b = inv_batch_fetch($conn, (int) $l['batch_id'], true);
                $item_row = inv_lock_item($conn, $pid, $vid);
                if (!in_array($b['status'], ['available', 'quarantined'], true) || (int) $b['qty_remaining'] !== (int) $b['qty_received']) {
                    $conn->rollback();
                    return ['ok' => false, 'message' => "Some units of $label from batch {$b['batch_code']} were already sold, adjusted or written off, so this receipt cannot be reversed. Correct the stock with an adjustment in Products > Inventory instead."];
                }
                $qty = (int) $b['qty_remaining'];
                if ($b['status'] === 'available') {
                    $before = (int) $item_row['stock'];
                    $after = $before - $qty;
                    if ($after < 0) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => "Only $before of $label are in stock, so the $qty from this receipt cannot be taken back."];
                    }
                    inv_set_item_stock($conn, $pid, $vid, $after);
                    inv_log_stock_movement($conn, $pid, $vid, $before, $after, 'receipt_reversal', "{$rc['receipt_number']} reversed: $reason", $uid);
                    $touched[] = [$pid, $vid];
                }
                $u = $conn->prepare("UPDATE tbl_inventory_batches SET status = 'reversed', qty_remaining = 0, status_reason = ? WHERE id = ?");
                $why = "Receipt {$rc['receipt_number']} reversed: $reason";
                $bid = (int) $b['id'];
                $u->bind_param('si', $why, $bid);
                $u->execute();
                $u->close();
                inv_log_batch_movement($conn, $bid, $pid, $vid, -$qty, 0, 'reversal', 'receipt', $receipt_id, $reason, $uid);
            }
            $acc = (int) $l['qty_accepted'];
            $rej = (int) $l['qty_rejected'];
            $poi = (int) $l['po_item_id'];
            $upd_po_item->bind_param('iii', $acc, $rej, $poi);
            $upd_po_item->execute();
        }
        $upd_po_item->close();

        $u = $conn->prepare("UPDATE tbl_stock_receipts SET status = 'reversed', reversed_at = NOW(), reversed_by = ?, reversal_reason = ? WHERE id = ?");
        $u->bind_param('isi', $uid, $reason, $receipt_id);
        $u->execute();
        $u->close();

        $items = purch_po_items($conn, (int) $rc['po_id']);
        $cur = (string) $po['status'] === 'closed_short' ? 'partially_received' : (string) $po['status'];
        $new_status = purch_status_from_items($items, $cur, $po['in_transit_at']);
        $u = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = ?, received_date = IF(? = 'delivered', received_date, NULL),
                             closed_reason = IF(status = 'closed_short', NULL, closed_reason), closed_by = IF(status = 'closed_short', NULL, closed_by),
                             closed_at = IF(status = 'closed_short', NULL, closed_at) WHERE id = ?");
        $poid = (int) $rc['po_id'];
        $u->bind_param('ssi', $new_status, $new_status, $poid);
        $u->execute();
        $u->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('purch_reverse_receipt failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not reverse the receipt. Nothing was changed - please try again.'];
    }
    foreach ($touched as [$pid, $vid]) {
        if (function_exists('evaluate_stock_alert')) {
            evaluate_stock_alert($conn, $vid > 0 ? 'tbl_product_variants' : 'tbl_product_inventory', $vid > 0 ? $vid : $pid);
        }
    }
    $label = PURCH_STATUS_LABELS[$new_status];
    purch_activity($conn, $uid, 'Reversed Stock Receipt', "{$rc['receipt_number']} for {$po['po_number']} reversed ({$rc['total_qty']} unit(s) taken back out of stock). Reason: $reason. Purchase order is now $label.");
    return ['ok' => true, 'message' => "{$rc['receipt_number']} reversed. {$po['po_number']} is now $label."];
}

/* ------------------------------------------------------------------------------------------
 * Legacy deliveries (free text, recorded before purchase orders)
 * ---------------------------------------------------------------------------------------- */

/**
 * Links a legacy delivery's free text to real products. Ordered / in-transit ones become normal
 * purchase orders (receive them as usual). A delivered one is recorded as history only: its stock
 * was entered by hand in Products, so nothing is added again. Admin only.
 */
function purch_link_legacy(mysqli $conn, array $actor, int $po_id, array $in): array
{
    if (!purch_can($actor['role'], 'link_legacy')) {
        return ['ok' => false, 'message' => 'Only an admin can link old delivery records.'];
    }
    [$lines, $errors] = purch_parse_lines($conn, $in['items'] ?? []);
    if ($errors) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }
    $uid = (int) $actor['user_id'];
    $conn->begin_transaction();
    try {
        $po = purch_fetch_po($conn, $po_id, true);
        if (!$po) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Delivery record not found.'];
        }
        if ((int) $po['is_legacy'] !== 1) {
            $conn->rollback();
            return ['ok' => true, 'message' => "{$po['po_number']} is already linked to products."];
        }
        if ($po['status'] === 'cancelled') {
            $conn->rollback();
            return ['ok' => false, 'message' => 'A cancelled delivery has nothing to link.'];
        }
        $delivered = $po['status'] === 'delivered';
        if ($delivered && empty($in['confirm_history'])) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Confirm that this stock was already entered in Products. It is recorded as history only, so stock is not added twice.'];
        }
        $total = 0.0;
        $li = $conn->prepare("INSERT INTO tbl_purchase_order_items (po_id, product_id, variant_id, item_label, variant_label, qty_ordered, unit_cost, qty_received) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ids = [];
        foreach ($lines as $l) {
            $vlabel = $l['variant'] !== '' ? $l['variant'] : null;
            $recv = $delivered ? $l['qty'] : 0;
            $li->bind_param('iiissidi', $po_id, $l['product_id'], $l['variant_id'], $l['name'], $vlabel, $l['qty'], $l['cost'], $recv);
            $li->execute();
            $ids[] = (int) $conn->insert_id;
            $total += $l['qty'] * $l['cost'];
        }
        $li->close();
        $u = $conn->prepare("UPDATE tbl_supplier_deliveries SET is_legacy = 0, total_amount = ? WHERE id = ?");
        $u->bind_param('di', $total, $po_id);
        $u->execute();
        $u->close();

        $receipt_number = null;
        if ($delivered) {
            $when = $po['received_date'] ?: ($po['updated_at'] ?: $po['created_at']);
            $token = purch_new_token();
            $sid = $po['supplier_id'] !== null ? (int) $po['supplier_id'] : null;
            $remarks = 'Old delivery record linked to products on ' . date('M j, Y') . '. Its stock was already entered by hand in Products, so it was not added again (history only).';
            $tq = (int) array_sum(array_column($lines, 'qty'));
            $r = $conn->prepare("INSERT INTO tbl_stock_receipts (po_id, supplier_id, request_token, received_at, received_by, physically_verified, stock_applied, remarks, total_qty, total_cost)
                                 VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?)");
            $r->bind_param('iissisid', $po_id, $sid, $token, $when, $uid, $remarks, $tq, $total);
            $r->execute();
            $rid = (int) $conn->insert_id;
            $r->close();
            $receipt_number = 'RCV-' . date('Ymd', strtotime($when)) . '-' . str_pad((string) $rid, 4, '0', STR_PAD_LEFT);
            $u = $conn->prepare("UPDATE tbl_stock_receipts SET receipt_number = ? WHERE id = ?");
            $u->bind_param('si', $receipt_number, $rid);
            $u->execute();
            $u->close();
            $ri = $conn->prepare("INSERT INTO tbl_stock_receipt_items (receipt_id, po_item_id, product_id, variant_id, item_label, variant_label, qty_accepted, unit_cost, line_total)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $i => $l) {
                $vlabel = $l['variant'] !== '' ? $l['variant'] : null;
                $lt = round($l['qty'] * $l['cost'], 2);
                $ri->bind_param('iiiissidd', $rid, $ids[$i], $l['product_id'], $l['variant_id'], $l['name'], $vlabel, $l['qty'], $l['cost'], $lt);
                $ri->execute();
            }
            $ri->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('purch_link_legacy failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not link the items. Nothing was changed - please try again.'];
    }
    purch_activity($conn, $uid, 'Linked Old Delivery', "{$po['po_number']} ({$po['supplier_name']}) linked to " . count($lines) . ' product line(s)'
        . ($receipt_number ? "; history-only receipt $receipt_number (stock not changed)." : '; it can now be received.'));
    return ['ok' => true, 'message' => $receipt_number
        ? "{$po['po_number']} linked. Recorded as history only ($receipt_number) - stock was not changed."
        : "{$po['po_number']} linked to products. Use Receive Delivery when the stock arrives."];
}

/* ------------------------------------------------------------------------------------------
 * Read models for the page
 * ---------------------------------------------------------------------------------------- */

function purch_list_pos(mysqli $conn): array
{
    $res = $conn->query("SELECT d.*, s.brand_name AS supplier_brand, s.phone AS supplier_phone,
                                COALESCE(i.n_lines, 0) AS line_count, COALESCE(i.qty_ordered, 0) AS qty_ordered,
                                COALESCE(i.qty_received, 0) AS qty_received, COALESCE(i.qty_rejected, 0) AS qty_rejected,
                                COALESCE(r.receipts, 0) AS receipt_count
                         FROM tbl_supplier_deliveries d
                         LEFT JOIN tbl_suppliers s ON s.id = d.supplier_id
                         LEFT JOIN (SELECT po_id, COUNT(*) n_lines, SUM(qty_ordered) qty_ordered, SUM(qty_received) qty_received, SUM(qty_rejected) qty_rejected
                                    FROM tbl_purchase_order_items GROUP BY po_id) i ON i.po_id = d.id
                         LEFT JOIN (SELECT po_id, COUNT(*) receipts FROM tbl_stock_receipts WHERE status = 'confirmed' GROUP BY po_id) r ON r.po_id = d.id
                         ORDER BY d.created_at DESC, d.id DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function purch_list_receipts(mysqli $conn, ?int $po_id = null, ?int $supplier_id = null, int $limit = 300): array
{
    $where = [];
    if ($po_id) {
        $where[] = 'r.po_id = ' . (int) $po_id;
    }
    if ($supplier_id) {
        $where[] = 'r.supplier_id = ' . (int) $supplier_id;
    }
    $res = $conn->query("SELECT r.*, d.po_number, d.supplier_name,
                                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'System') AS received_by_name,
                                GROUP_CONCAT(CONCAT(ri.qty_accepted, ' x ', ri.item_label, IF(ri.variant_label IS NULL, '', CONCAT(' (', ri.variant_label, ')'))) ORDER BY ri.id SEPARATOR '\n') AS items_text,
                                GROUP_CONCAT(b.batch_code ORDER BY ri.id SEPARATOR ', ') AS batch_codes
                         FROM tbl_stock_receipts r
                         JOIN tbl_supplier_deliveries d ON d.id = r.po_id
                         LEFT JOIN users u ON u.id = r.received_by
                         LEFT JOIN tbl_stock_receipt_items ri ON ri.receipt_id = r.id
                         LEFT JOIN tbl_inventory_batches b ON b.id = ri.batch_id
                         " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
                         GROUP BY r.id
                         ORDER BY r.received_at DESC, r.id DESC
                         LIMIT " . max(1, $limit));
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function purch_get_receipt(mysqli $conn, int $receipt_id): ?array
{
    $s = $conn->prepare("SELECT r.*, d.po_number, d.supplier_name, d.status AS po_status,
                                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'System') AS received_by_name,
                                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', x.first_name, x.last_name)), ''), x.username) AS reversed_by_name
                         FROM tbl_stock_receipts r JOIN tbl_supplier_deliveries d ON d.id = r.po_id
                         LEFT JOIN users u ON u.id = r.received_by LEFT JOIN users x ON x.id = r.reversed_by
                         WHERE r.id = ?");
    $s->bind_param('i', $receipt_id);
    $s->execute();
    $rc = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$rc) {
        return null;
    }
    $s = $conn->prepare("SELECT ri.*, b.batch_code, b.status AS batch_status, b.qty_remaining AS batch_remaining, poi.qty_ordered
                         FROM tbl_stock_receipt_items ri
                         LEFT JOIN tbl_inventory_batches b ON b.id = ri.batch_id
                         LEFT JOIN tbl_purchase_order_items poi ON poi.id = ri.po_item_id
                         WHERE ri.receipt_id = ? ORDER BY ri.id");
    $s->bind_param('i', $receipt_id);
    $s->execute();
    $rc['items'] = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rc;
}

/** Batches, newest first, with filters: status (or 'near' / 'sellable'), q (item search), supplier. */
function purch_list_batches(mysqli $conn, array $f = [], int $limit = 400): array
{
    $where = ['1=1'];
    $types = '';
    $params = [];
    $status = (string) ($f['status'] ?? '');
    if ($status === 'near') {
        $where[] = "b.status = 'available' AND b.qty_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) INV_NEAR_EXPIRY_DAYS . " DAY)";
    } elseif ($status === 'sellable') {
        $where[] = "b.status = 'available' AND b.qty_remaining > 0";
    } elseif (isset(inv_batch_statuses()[$status])) {
        $where[] = 'b.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(b.item_label LIKE ? OR b.variant_label LIKE ? OR b.batch_code LIKE ? OR b.lot_number LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($f['supplier_id'])) {
        $where[] = 'b.supplier_id = ?';
        $types .= 'i';
        $params[] = (int) $f['supplier_id'];
    }
    if (!empty($f['product'])) {
        [$pid, $vid] = array_map('intval', explode(':', (string) $f['product']) + [0, 0]);
        $where[] = 'b.product_id = ? AND b.variant_id = ?';
        $types .= 'ii';
        array_push($params, $pid, $vid);
    }
    $sql = "SELECT b.*, s.supplier_name, r.receipt_number, r.po_id, d.po_number
            FROM tbl_inventory_batches b
            LEFT JOIN tbl_suppliers s ON s.id = b.supplier_id
            LEFT JOIN tbl_stock_receipts r ON r.id = b.receipt_id
            LEFT JOIN tbl_supplier_deliveries d ON d.id = r.po_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY b.product_id, b.variant_id, " . inv_fefo_order_sql('b') . "
            LIMIT " . max(1, $limit);
    $s = $conn->prepare($sql);
    if ($params) {
        $s->bind_param($types, ...$params);
    }
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    // FEFO: the first available batch of each item is the one sold next.
    $seen = [];
    foreach ($rows as &$r) {
        $k = $r['product_id'] . ':' . $r['variant_id'];
        $r['sells_next'] = false;
        if ($r['status'] === 'available' && (int) $r['qty_remaining'] > 0 && !isset($seen[$k])) {
            $seen[$k] = true;
            $r['sells_next'] = true;
        }
    }
    unset($r);
    return $rows;
}

/** Headline numbers for the batches tab. */
function purch_batch_summary(mysqli $conn): array
{
    $near = (int) INV_NEAR_EXPIRY_DAYS;
    $row = $conn->query("SELECT
            COALESCE(SUM(CASE WHEN status = 'available' THEN qty_remaining END), 0) AS sellable_units,
            COALESCE(SUM(CASE WHEN status = 'available' AND unit_cost IS NOT NULL THEN qty_remaining * unit_cost END), 0) AS value,
            COALESCE(SUM(CASE WHEN status = 'available' AND unit_cost IS NULL THEN qty_remaining END), 0) AS uncosted_units,
            COALESCE(SUM(status = 'available' AND qty_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL $near DAY)), 0) AS near_batches,
            COALESCE(SUM(CASE WHEN status = 'available' AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL $near DAY) THEN qty_remaining END), 0) AS near_units,
            COALESCE(SUM(CASE WHEN status = 'quarantined' THEN qty_remaining END), 0) AS held_units,
            COALESCE(SUM(CASE WHEN status IN ('expired','damaged','recalled') THEN qty_remaining END), 0) AS written_off_units
        FROM tbl_inventory_batches")->fetch_assoc();
    return array_map(static fn($v) => $v + 0, $row ?: []);
}

/**
 * Purchase cost history: every confirmed receipt line with the item's previous cost.
 * Newest first. ['item', 'supplier', 'received_at', 'cost', 'prev_cost', 'diff', 'pct', 'receipt_number', 'po_number', 'qty']
 */
function purch_cost_history(mysqli $conn, ?int $supplier_id = null): array
{
    $res = $conn->query("SELECT ri.product_id, ri.variant_id, ri.item_label, ri.variant_label, ri.unit_cost, ri.qty_accepted,
                                r.received_at, r.receipt_number, r.supplier_id, d.po_number, d.supplier_name
                         FROM tbl_stock_receipt_items ri
                         JOIN tbl_stock_receipts r ON r.id = ri.receipt_id
                         JOIN tbl_supplier_deliveries d ON d.id = r.po_id
                         WHERE r.status = 'confirmed' AND ri.qty_accepted > 0
                         ORDER BY ri.product_id, ri.variant_id, r.received_at, ri.id");
    $out = [];
    $prev = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $k = $r['product_id'] . ':' . $r['variant_id'];
        $cost = (float) $r['unit_cost'];
        $p = $prev[$k] ?? null;
        $prev[$k] = $cost;
        if ($supplier_id && (int) $r['supplier_id'] !== $supplier_id) {
            continue;
        }
        $out[] = [
            'key'            => $k,
            'item'           => purch_item_label($r['item_label'], $r['variant_label']),
            'supplier'       => (string) $r['supplier_name'],
            'received_at'    => $r['received_at'],
            'cost'           => $cost,
            'prev_cost'      => $p,
            'diff'           => $p !== null ? round($cost - $p, 2) : null,
            'pct'            => $p !== null && $p > 0 ? round(($cost - $p) / $p * 100, 1) : null,
            'receipt_number' => (string) $r['receipt_number'],
            'po_number'      => (string) $r['po_number'],
            'qty'            => (int) $r['qty_accepted'],
        ];
    }
    usort($out, static fn($a, $b) => strcmp((string) $b['received_at'], (string) $a['received_at']));
    return $out;
}

/** Supplier profile: header numbers + their POs and receipts. */
function purch_supplier_profile(mysqli $conn, int $supplier_id): ?array
{
    $s = $conn->prepare("SELECT * FROM tbl_suppliers WHERE id = ?");
    $s->bind_param('i', $supplier_id);
    $s->execute();
    $sup = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$sup) {
        return null;
    }
    $s = $conn->prepare("SELECT d.*, COALESCE(i.qty_ordered, 0) qty_ordered, COALESCE(i.qty_received, 0) qty_received
                         FROM tbl_supplier_deliveries d
                         LEFT JOIN (SELECT po_id, SUM(qty_ordered) qty_ordered, SUM(qty_received) qty_received FROM tbl_purchase_order_items GROUP BY po_id) i ON i.po_id = d.id
                         WHERE d.supplier_id = ? ORDER BY d.created_at DESC, d.id DESC");
    $s->bind_param('i', $supplier_id);
    $s->execute();
    $sup['pos'] = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    $sup['receipts'] = purch_list_receipts($conn, null, $supplier_id, 100);
    $confirmed = array_filter($sup['receipts'], static fn($r) => $r['status'] === 'confirmed');
    $sup['total_pos'] = count(array_filter($sup['pos'], static fn($p) => $p['status'] !== 'draft'));
    $sup['received_deliveries'] = count($confirmed);
    $sup['total_purchase_cost'] = array_sum(array_map(static fn($r) => (float) $r['total_cost'], $confirmed));
    $sup['units_received'] = array_sum(array_map(static fn($r) => (int) $r['total_qty'], $confirmed));
    $sup['units_rejected'] = array_sum(array_map(static fn($r) => (int) $r['total_rejected'], $confirmed));
    $sup['ordered_value'] = array_sum(array_map(static fn($p) => in_array($p['status'], ['draft', 'cancelled'], true) ? 0 : (float) $p['total_amount'], $sup['pos']));
    $sup['costs'] = purch_cost_history($conn, $supplier_id);
    return $sup;
}
