<?php
/**
 * Inventory batches: every unit of sellable stock belongs to a batch (one supplier delivery, with
 * its own purchase cost, lot number and expiration date).
 *
 * The stock column (tbl_product_inventory.stock / tbl_product_variants.stock) stays the sellable
 * count that checkout, returns and the Products page already use. Batches MIRROR it:
 *
 *     stock = SUM(qty_remaining) of the item's batches with status 'available'
 *
 * inv_batch_sync() restores that rule after any stock change made elsewhere:
 *   - stock went down (a sale, a removal)  -> units are taken from batches FEFO: earliest
 *     expiration first, batches without an expiration date last, then FIFO (oldest received first);
 *   - stock went back up by a cancel/return -> units go back to the batch they were last taken from;
 *   - any other increase (Products > Add stock, a direct stock edit) -> an "adjustment" batch with
 *     no recorded cost. Stock that existed before batch tracking becomes one "Opening stock" batch.
 * Receiving a supplier delivery (purchasing_helper.php) creates a real batch and raises stock in
 * the same transaction, so the rule holds without a sync.
 *
 * Quarantined, expired, damaged, recalled and reversed batches are NOT available: their units were
 * taken out of the stock column when the batch got that status, so customers can never buy them.
 *
 * Sync never runs DDL and never throws (except a deadlock inside the caller's own transaction,
 * which the caller must see), so it is safe inside checkout. The schema is created by
 * inv_batches_ensure_schema(), called from the Suppliers & Deliveries and Products pages.
 */

if (!defined('INV_NEAR_EXPIRY_DAYS')) {
    define('INV_NEAR_EXPIRY_DAYS', 30); // "near expiry" alert window
}

if (!function_exists('inv_batch_statuses')) {
    function inv_batch_statuses(): array
    {
        return [
            'available'   => 'Available',
            'quarantined' => 'On hold (quarantine)',
            'expired'     => 'Expired',
            'damaged'     => 'Damaged',
            'recalled'    => 'Recalled',
            'reversed'    => 'Reversed',
        ];
    }

    /** True once the batch tables exist. Cached; $set lets the schema creator flip it. */
    function inv_batches_ready(mysqli $conn, ?bool $set = null): bool
    {
        static $ready = null;
        if ($set !== null) {
            $ready = $set;
        }
        if ($ready === null) {
            try {
                $r = $conn->query("SHOW TABLES LIKE 'tbl_inventory_batch_movements'");
                $ready = $r && $r->num_rows > 0;
            } catch (Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** DDL - call only outside a transaction (page load), never from checkout. */
    function inv_batches_ensure_schema(mysqli $conn): void
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
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_inventory_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_code VARCHAR(30) NULL,
            product_id INT NOT NULL,
            variant_id INT NOT NULL DEFAULT 0,
            item_label VARCHAR(255) NOT NULL,
            variant_label VARCHAR(100) NULL,
            source ENUM('receipt','opening','adjustment') NOT NULL DEFAULT 'receipt',
            supplier_id INT NULL,
            receipt_id INT NULL,
            receipt_item_id INT NULL,
            lot_number VARCHAR(100) NULL,
            unit_cost DECIMAL(12,2) NULL,
            qty_received INT NOT NULL DEFAULT 0,
            qty_remaining INT NOT NULL DEFAULT 0,
            expiry_date DATE NULL,
            received_at DATETIME NOT NULL,
            status ENUM('available','quarantined','expired','damaged','recalled','reversed') NOT NULL DEFAULT 'available',
            status_reason VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_batch_code (batch_code),
            UNIQUE KEY uniq_batch_receipt_item (receipt_item_id),
            KEY idx_batch_item (product_id, variant_id, status, expiry_date),
            KEY idx_batch_expiry (status, expiry_date),
            KEY idx_batch_supplier (supplier_id),
            CONSTRAINT chk_batch_qty CHECK (qty_remaining >= 0 AND qty_remaining <= qty_received)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_inventory_batch_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            product_id INT NOT NULL,
            variant_id INT NOT NULL DEFAULT 0,
            change_qty INT NOT NULL,
            qty_after INT NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            ref_type VARCHAR(30) NULL,
            ref_id INT NULL,
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bm_batch (batch_id, id),
            KEY idx_bm_item (product_id, variant_id, created_at),
            CONSTRAINT fk_bm_batch FOREIGN KEY (batch_id) REFERENCES tbl_inventory_batches (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        inv_batches_ready($conn, true);
    }

    /** True when the connection is inside an open transaction (MariaDB). Unknown -> true (never nest). */
    function inv_in_transaction(mysqli $conn): bool
    {
        try {
            $r = $conn->query("SELECT @@in_transaction AS t");
            $row = $r ? $r->fetch_assoc() : null;
            return $row === null || (int) $row['t'] === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** FEFO, then FIFO: earliest expiry first, no-expiry batches last, oldest received first. */
    function inv_fefo_order_sql(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return "({$a}expiry_date IS NULL), {$a}expiry_date ASC, {$a}received_at ASC, {$a}id ASC";
    }

    /** Locks and returns the item's stock row: ['stock', 'name', 'size', 'created_at'] or null. */
    function inv_lock_item(mysqli $conn, int $product_id, int $variant_id): ?array
    {
        if ($variant_id > 0) {
            $s = $conn->prepare("SELECT v.stock, v.size, p.name, p.created_at FROM tbl_product_variants v
                                 JOIN tbl_product_inventory p ON p.id = v.product_id
                                 WHERE v.id = ? AND v.product_id = ? FOR UPDATE");
            $s->bind_param('ii', $variant_id, $product_id);
        } else {
            $s = $conn->prepare("SELECT stock, NULL AS size, name, created_at FROM tbl_product_inventory WHERE id = ? FOR UPDATE");
            $s->bind_param('i', $product_id);
        }
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        return $row ?: null;
    }

    /** Sets the item's stock column (row must be locked by the caller). */
    function inv_set_item_stock(mysqli $conn, int $product_id, int $variant_id, int $stock): void
    {
        if ($variant_id > 0) {
            $u = $conn->prepare("UPDATE tbl_product_variants SET stock = ? WHERE id = ?");
            $u->bind_param('ii', $stock, $variant_id);
            $u->execute();
            $u->close();
            $t = $conn->prepare("UPDATE tbl_product_inventory SET updated_at = NOW() WHERE id = ?");
            $t->bind_param('i', $product_id);
            $t->execute();
            $t->close();
        } else {
            $u = $conn->prepare("UPDATE tbl_product_inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
            $u->bind_param('ii', $stock, $product_id);
            $u->execute();
            $u->close();
        }
    }

    /** One row in tbl_stock_movements (the Products > Inventory > History list). */
    function inv_log_stock_movement(mysqli $conn, int $product_id, int $variant_id, int $before, int $after, string $reason, string $note, ?int $user_id): void
    {
        $change = $after - $before;
        $vid = $variant_id > 0 ? $variant_id : null;
        $note = mb_substr($note, 0, 255);
        $m = $conn->prepare("INSERT INTO tbl_stock_movements (product_id, variant_id, change_qty, stock_before, stock_after, reason, note, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $m->bind_param('iiiiissi', $product_id, $vid, $change, $before, $after, $reason, $note, $user_id);
        $m->execute();
        $m->close();
    }

    function inv_log_batch_movement(mysqli $conn, int $batch_id, int $product_id, int $variant_id, int $change, int $qty_after, string $type, ?string $ref_type, ?int $ref_id, string $note, ?int $user_id): void
    {
        $note = $note !== '' ? mb_substr($note, 0, 255) : null;
        $m = $conn->prepare("INSERT INTO tbl_inventory_batch_movements (batch_id, product_id, variant_id, change_qty, qty_after, movement_type, ref_type, ref_id, note, user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $m->bind_param('iiiiissisi', $batch_id, $product_id, $variant_id, $change, $qty_after, $type, $ref_type, $ref_id, $note, $user_id);
        $m->execute();
        $m->close();
    }

    /** Inserts a batch and gives it its code (BAT-000123). Returns the new id. */
    function inv_create_batch(mysqli $conn, array $b): int
    {
        $s = $conn->prepare("INSERT INTO tbl_inventory_batches
            (product_id, variant_id, item_label, variant_label, source, supplier_id, receipt_id, receipt_item_id, lot_number, unit_cost,
             qty_received, qty_remaining, expiry_date, received_at, status, status_reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $product_id = (int) $b['product_id'];
        $variant_id = (int) ($b['variant_id'] ?? 0);
        $label = mb_substr((string) $b['item_label'], 0, 255);
        $vlabel = isset($b['variant_label']) && $b['variant_label'] !== '' ? mb_substr((string) $b['variant_label'], 0, 100) : null;
        $source = (string) ($b['source'] ?? 'receipt');
        $supplier_id = isset($b['supplier_id']) ? (int) $b['supplier_id'] : null;
        $receipt_id = isset($b['receipt_id']) ? (int) $b['receipt_id'] : null;
        $receipt_item_id = isset($b['receipt_item_id']) ? (int) $b['receipt_item_id'] : null;
        $lot = isset($b['lot_number']) && $b['lot_number'] !== '' ? mb_substr((string) $b['lot_number'], 0, 100) : null;
        $cost = isset($b['unit_cost']) && $b['unit_cost'] !== null ? (string) $b['unit_cost'] : null;
        $qty_received = (int) $b['qty_received'];
        $qty_remaining = (int) ($b['qty_remaining'] ?? $qty_received);
        $expiry = !empty($b['expiry_date']) ? (string) $b['expiry_date'] : null;
        $received_at = (string) ($b['received_at'] ?? date('Y-m-d H:i:s'));
        $status = (string) ($b['status'] ?? 'available');
        $reason = isset($b['status_reason']) && $b['status_reason'] !== '' ? mb_substr((string) $b['status_reason'], 0, 255) : null;
        $created_by = isset($b['created_by']) ? (int) $b['created_by'] : null;
        $s->bind_param('iisssiiissiissssi', $product_id, $variant_id, $label, $vlabel, $source, $supplier_id, $receipt_id, $receipt_item_id,
            $lot, $cost, $qty_received, $qty_remaining, $expiry, $received_at, $status, $reason, $created_by);
        $s->execute();
        $id = (int) $conn->insert_id;
        $s->close();
        $code = 'BAT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $u = $conn->prepare("UPDATE tbl_inventory_batches SET batch_code = ? WHERE id = ?");
        $u->bind_param('si', $code, $id);
        $u->execute();
        $u->close();
        return $id;
    }

    /**
     * Product edits re-create variants (new ids). Points batches / PO lines / receipt lines whose
     * variant no longer exists at the variant with the same size label, so history follows the item.
     */
    function inv_remap_variant_ids(mysqli $conn, ?int $product_id = null): void
    {
        if (!inv_batches_ready($conn)) {
            return;
        }
        $tables = ['tbl_inventory_batches'];
        foreach (['tbl_purchase_order_items', 'tbl_stock_receipt_items'] as $t) {
            try {
                $r = $conn->query("SHOW TABLES LIKE '$t'");
                if ($r && $r->num_rows > 0) {
                    $tables[] = $t;
                }
            } catch (Throwable $e) {
            }
        }
        $where = $product_id ? ' AND x.product_id = ' . (int) $product_id : '';
        foreach ($tables as $t) {
            try {
                $conn->query("UPDATE `$t` x
                              JOIN tbl_product_variants v ON v.product_id = x.product_id AND v.size = x.variant_label
                              SET x.variant_id = v.id
                              WHERE x.variant_id > 0 AND x.variant_label IS NOT NULL$where
                                AND NOT EXISTS (SELECT 1 FROM tbl_product_variants v2 WHERE v2.id = x.variant_id AND v2.product_id = x.product_id)");
                if ($t === 'tbl_inventory_batches') {
                    $conn->query("UPDATE tbl_inventory_batch_movements m JOIN tbl_inventory_batches b ON b.id = m.batch_id
                                  SET m.variant_id = b.variant_id WHERE m.variant_id <> b.variant_id" . ($product_id ? ' AND b.product_id = ' . (int) $product_id : ''));
                }
            } catch (Throwable $e) {
                error_log("inv_remap_variant_ids($t): " . $e->getMessage());
            }
        }
    }

    /**
     * Makes the item's available batches add up to its stock column again (see the file comment).
     * $mode: 'sale' | 'restore' | 'adjust'. Never throws, except a deadlock inside the caller's
     * transaction (InnoDB has already rolled that transaction back, so the caller must know).
     */
    function inv_batch_sync(mysqli $conn, int $product_id, int $variant_id = 0, string $mode = 'adjust', string $note = '', ?int $user_id = null, ?string $ref_type = null, ?int $ref_id = null): void
    {
        if ($product_id <= 0 || !inv_batches_ready($conn)) {
            return;
        }
        $variant_id = max(0, $variant_id);
        $lock_name = 'inv_batch_' . $product_id . '_' . $variant_id;
        $got = 0;
        $own_tx = false;
        try {
            $l = $conn->prepare("SELECT GET_LOCK(?, 5)");
            $l->bind_param('s', $lock_name);
            $l->execute();
            $got = (int) ($l->get_result()->fetch_row()[0] ?? 0);
            $l->close();
            if ($got !== 1) {
                error_log("inv_batch_sync: lock busy for $lock_name - batches will be reconciled on the next sync.");
                return;
            }
            $own_tx = !inv_in_transaction($conn);
            if ($own_tx) {
                $conn->begin_transaction();
            }

            $item = inv_lock_item($conn, $product_id, $variant_id);
            if ($item) {
                inv_batch_sync_locked($conn, $product_id, $variant_id, $item, $mode, $note, $user_id, $ref_type, $ref_id);
            }
            if ($own_tx) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($own_tx) {
                try { $conn->rollback(); } catch (Throwable $ignore) {}
            }
            error_log('inv_batch_sync failed: ' . $e->getMessage());
            if (!$own_tx && $e instanceof mysqli_sql_exception && (int) $e->getCode() === 1213) {
                throw $e;
            }
        } finally {
            if ($got === 1) {
                try {
                    $r = $conn->prepare("SELECT RELEASE_LOCK(?)");
                    $r->bind_param('s', $lock_name);
                    $r->execute();
                    $r->close();
                } catch (Throwable $ignore) {
                }
            }
        }
    }

    /** The sync itself; the item row is already locked and a transaction is open. */
    function inv_batch_sync_locked(mysqli $conn, int $product_id, int $variant_id, array $item, string $mode, string $note, ?int $user_id, ?string $ref_type, ?int $ref_id): void
    {
        if ($variant_id === 0) {
            // A product with sizes sells only its sizes; its own stock column is not batched.
            $v = $conn->prepare("SELECT 1 FROM tbl_product_variants WHERE product_id = ? LIMIT 1");
            $v->bind_param('i', $product_id);
            $v->execute();
            $has_sizes = (bool) $v->get_result()->fetch_row();
            $v->close();
            if ($has_sizes) {
                return;
            }
        }
        $stock = max(0, (int) $item['stock']);
        $s = $conn->prepare("SELECT id, qty_received, qty_remaining FROM tbl_inventory_batches
                             WHERE product_id = ? AND variant_id = ? AND status = 'available'
                             ORDER BY " . inv_fefo_order_sql() . " FOR UPDATE");
        $s->bind_param('ii', $product_id, $variant_id);
        $s->execute();
        $batches = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        $diff = $stock - (int) array_sum(array_column($batches, 'qty_remaining'));
        if ($diff === 0) {
            return;
        }

        $upd = $conn->prepare("UPDATE tbl_inventory_batches SET qty_remaining = ? WHERE id = ?");
        if ($diff < 0) {
            // Stock went down: take the units FEFO.
            $need = -$diff;
            $type = $mode === 'sale' ? 'sale' : 'adjust_out';
            foreach ($batches as $b) {
                if ($need <= 0) {
                    break;
                }
                $take = min($need, (int) $b['qty_remaining']);
                if ($take <= 0) {
                    continue;
                }
                $left = (int) $b['qty_remaining'] - $take;
                $id = (int) $b['id'];
                $upd->bind_param('ii', $left, $id);
                $upd->execute();
                inv_log_batch_movement($conn, $id, $product_id, $variant_id, -$take, $left, $type, $ref_type, $ref_id, $note, $user_id);
                $need -= $take;
            }
            $upd->close();
            return;
        }

        // Stock went up.
        $add = $diff;
        if ($mode === 'restore') {
            // Back into the batch the units were last taken from.
            $r = $conn->prepare("SELECT b.id, b.qty_received, b.qty_remaining FROM tbl_inventory_batches b
                                 WHERE b.product_id = ? AND b.variant_id = ? AND b.status = 'available' AND b.qty_remaining < b.qty_received
                                 ORDER BY (SELECT MAX(m.id) FROM tbl_inventory_batch_movements m WHERE m.batch_id = b.id AND m.change_qty < 0) DESC, b.id DESC
                                 FOR UPDATE");
            $r->bind_param('ii', $product_id, $variant_id);
            $r->execute();
            $room = $r->get_result()->fetch_all(MYSQLI_ASSOC);
            $r->close();
            foreach ($room as $b) {
                if ($add <= 0) {
                    break;
                }
                $put = min($add, (int) $b['qty_received'] - (int) $b['qty_remaining']);
                if ($put <= 0) {
                    continue;
                }
                $left = (int) $b['qty_remaining'] + $put;
                $id = (int) $b['id'];
                $upd->bind_param('ii', $left, $id);
                $upd->execute();
                inv_log_batch_movement($conn, $id, $product_id, $variant_id, $put, $left, 'restore', $ref_type, $ref_id, $note, $user_id);
                $add -= $put;
            }
        }
        $upd->close();
        if ($add <= 0) {
            return;
        }

        // Anything else: the item's open opening/adjustment batch (no recorded cost).
        $o = $conn->prepare("SELECT id, qty_received, qty_remaining FROM tbl_inventory_batches
                             WHERE product_id = ? AND variant_id = ? AND status = 'available' AND source IN ('opening','adjustment')
                             ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $o->bind_param('ii', $product_id, $variant_id);
        $o->execute();
        $open = $o->get_result()->fetch_assoc();
        $o->close();
        if ($open) {
            $id = (int) $open['id'];
            $u = $conn->prepare("UPDATE tbl_inventory_batches SET qty_received = qty_received + ?, qty_remaining = qty_remaining + ? WHERE id = ?");
            $u->bind_param('iii', $add, $add, $id);
            $u->execute();
            $u->close();
            inv_log_batch_movement($conn, $id, $product_id, $variant_id, $add, (int) $open['qty_remaining'] + $add, 'adjust_in', $ref_type, $ref_id, $note, $user_id);
            return;
        }
        $c = $conn->prepare("SELECT COUNT(*) FROM tbl_inventory_batches WHERE product_id = ? AND variant_id = ?");
        $c->bind_param('ii', $product_id, $variant_id);
        $c->execute();
        $first = (int) ($c->get_result()->fetch_row()[0] ?? 0) === 0;
        $c->close();
        $id = inv_create_batch($conn, [
            'product_id'    => $product_id,
            'variant_id'    => $variant_id,
            'item_label'    => (string) $item['name'],
            'variant_label' => (string) ($item['size'] ?? ''),
            'source'        => $first ? 'opening' : 'adjustment',
            'qty_received'  => $add,
            'qty_remaining' => $add,
            // Opening stock is the oldest stock there is, so FIFO sells it first.
            'received_at'   => $first && !empty($item['created_at']) ? (string) $item['created_at'] : date('Y-m-d H:i:s'),
            'status_reason' => $first ? 'Stock on hand when batch tracking started' : 'Stock added outside supplier receiving',
            'created_by'    => $user_id,
        ]);
        inv_log_batch_movement($conn, $id, $product_id, $variant_id, $add, $add, $first ? 'opening' : 'adjust_in', $ref_type, $ref_id, $note, $user_id);
    }

    /**
     * Brings every item whose stock and available batches disagree back in line (and creates the
     * opening batches the first time). Call outside a transaction. Returns how many items changed.
     */
    function inv_batches_reconcile_all(mysqli $conn): int
    {
        if (!inv_batches_ready($conn)) {
            return 0;
        }
        inv_remap_variant_ids($conn);
        $sql = "SELECT p.id AS pid, 0 AS vid FROM tbl_product_inventory p
                LEFT JOIN (SELECT product_id, SUM(qty_remaining) s FROM tbl_inventory_batches WHERE status = 'available' AND variant_id = 0 GROUP BY product_id) b
                       ON b.product_id = p.id
                WHERE NOT EXISTS (SELECT 1 FROM tbl_product_variants v WHERE v.product_id = p.id)
                  AND GREATEST(p.stock, 0) <> COALESCE(b.s, 0)
                UNION ALL
                SELECT v.product_id, v.id FROM tbl_product_variants v
                LEFT JOIN (SELECT variant_id, SUM(qty_remaining) s FROM tbl_inventory_batches WHERE status = 'available' AND variant_id > 0 GROUP BY variant_id) b
                       ON b.variant_id = v.id
                WHERE GREATEST(v.stock, 0) <> COALESCE(b.s, 0)";
        $n = 0;
        try {
            $res = $conn->query($sql);
            while ($res && ($r = $res->fetch_assoc())) {
                inv_batch_sync($conn, (int) $r['pid'], (int) $r['vid'], 'adjust', 'Reconciled with the stock count');
                $n++;
            }
        } catch (Throwable $e) {
            error_log('inv_batches_reconcile_all: ' . $e->getMessage());
        }
        return $n;
    }

    /**
     * Changes a batch's status and moves its units in or out of sellable stock:
     *   available   -> quarantined | expired | damaged | recalled : units leave the stock column
     *   quarantined -> available (release; not past its expiry)  : units return to the stock column
     *   quarantined -> expired | damaged | recalled               : no stock change (already out)
     * Opens its own transaction - call outside one. Returns ['ok', 'message'].
     */
    function inv_batch_set_status(mysqli $conn, int $batch_id, string $new_status, string $reason, ?int $user_id, bool $system = false): array
    {
        $labels = inv_batch_statuses();
        if (!isset($labels[$new_status]) || $new_status === 'reversed') {
            return ['ok' => false, 'message' => 'Choose a valid batch status.'];
        }
        $reason = trim($reason);
        if ($reason === '' && !$system) {
            return ['ok' => false, 'message' => 'Please give a reason for this change.'];
        }
        $conn->begin_transaction();
        try {
            $b = inv_batch_fetch($conn, $batch_id, true);
            if (!$b) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Batch not found.'];
            }
            $pid = (int) $b['product_id'];
            $vid = (int) $b['variant_id'];
            $item = inv_lock_item($conn, $pid, $vid);
            if (!$item) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This product or size no longer exists.'];
            }
            // Settle any outside stock change first so the batch's remaining count is current.
            inv_batch_sync_locked($conn, $pid, $vid, $item, 'adjust', 'Reconciled before a batch status change', $user_id, 'batch', $batch_id);
            $b = inv_batch_fetch($conn, $batch_id, true);
            $old = (string) $b['status'];
            $qty = (int) $b['qty_remaining'];
            if ($old === $new_status) {
                $conn->rollback();
                return ['ok' => true, 'message' => "{$b['batch_code']} is already " . strtolower($labels[$new_status]) . '.'];
            }
            $allowed = [
                'available'   => ['quarantined', 'expired', 'damaged', 'recalled'],
                'quarantined' => ['available', 'expired', 'damaged', 'recalled'],
            ];
            if (!in_array($new_status, $allowed[$old] ?? [], true)) {
                $conn->rollback();
                return ['ok' => false, 'message' => "A batch that is " . strtolower($labels[$old] ?? $old) . " cannot be changed to " . strtolower($labels[$new_status]) . '.'];
            }
            if ($new_status === 'available' && !empty($b['expiry_date']) && $b['expiry_date'] < date('Y-m-d')) {
                $conn->rollback();
                return ['ok' => false, 'message' => "{$b['batch_code']} expired on " . date('M j, Y', strtotime($b['expiry_date'])) . ' and cannot be released for sale.'];
            }

            $before = (int) $item['stock'];
            $after = $before;
            if ($old === 'available') {
                $after = max(0, $before - $qty);           // units leave sellable stock
            } elseif ($new_status === 'available') {
                $after = $before + $qty;                   // released back to sellable stock
            }
            $u = $conn->prepare("UPDATE tbl_inventory_batches SET status = ?, status_reason = ? WHERE id = ?");
            $why = mb_substr($reason, 0, 255);
            $u->bind_param('ssi', $new_status, $why, $batch_id);
            $u->execute();
            $u->close();
            $type = $new_status === 'available' ? 'release' : $new_status;
            inv_log_batch_movement($conn, $batch_id, $pid, $vid, $after - $before, $qty, $type, 'batch', $batch_id, $reason, $user_id);
            if ($after !== $before) {
                inv_set_item_stock($conn, $pid, $vid, $after);
                $stock_reason = ['quarantined' => 'batch_quarantine', 'available' => 'batch_release', 'expired' => 'batch_expired', 'damaged' => 'batch_damaged', 'recalled' => 'batch_recalled'][$new_status];
                inv_log_stock_movement($conn, $pid, $vid, $before, $after, $stock_reason, $b['batch_code'] . ': ' . $reason, $user_id);
            }
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignore) {}
            error_log('inv_batch_set_status failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the batch. Please try again.'];
        }
        if (function_exists('evaluate_stock_alert')) {
            evaluate_stock_alert($conn, $vid > 0 ? 'tbl_product_variants' : 'tbl_product_inventory', $vid > 0 ? $vid : $pid);
        }
        $label = $b['item_label'] . ($b['variant_label'] ? ' (' . $b['variant_label'] . ')' : '');
        $details = "Batch {$b['batch_code']} of $label: " . strtolower($labels[$old]) . ' -> ' . strtolower($labels[$new_status]) . " ($qty units). Stock $before -> $after. Reason: $reason";
        try {
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Inventory Batch', ?)");
            $log->bind_param('is', $user_id, $details);
            $log->execute();
            $log->close();
        } catch (Throwable $e) {
        }
        $msg = "{$b['batch_code']} is now " . strtolower($labels[$new_status]) . '.';
        if ($after !== $before) {
            $msg .= " Sellable stock of $label: $before → $after.";
        }
        return ['ok' => true, 'message' => $msg];
    }

    function inv_batch_fetch(mysqli $conn, int $batch_id, bool $lock = false): ?array
    {
        $s = $conn->prepare("SELECT * FROM tbl_inventory_batches WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $s->bind_param('i', $batch_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        return $row ?: null;
    }

    /** Admin correction of a batch's expiration date / lot number. Past expiry -> expires now. */
    function inv_batch_update_details(mysqli $conn, int $batch_id, ?string $expiry, string $lot, string $reason, ?int $user_id): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Please give a reason for the correction.'];
        }
        if ($expiry !== null && $expiry !== '' && !inv_valid_date($expiry)) {
            return ['ok' => false, 'message' => 'Enter a valid expiration date.'];
        }
        $expiry = $expiry !== null && $expiry !== '' ? $expiry : null;
        $lot = mb_substr(trim($lot), 0, 100);
        $b = inv_batch_fetch($conn, $batch_id);
        if (!$b) {
            return ['ok' => false, 'message' => 'Batch not found.'];
        }
        if (in_array($b['status'], ['reversed'], true)) {
            return ['ok' => false, 'message' => 'A reversed batch cannot be edited.'];
        }
        $lot_db = $lot !== '' ? $lot : null;
        $u = $conn->prepare("UPDATE tbl_inventory_batches SET expiry_date = ?, lot_number = ? WHERE id = ?");
        $u->bind_param('ssi', $expiry, $lot_db, $batch_id);
        $u->execute();
        $u->close();
        $note = 'Details corrected: expiry ' . ($b['expiry_date'] ?: 'none') . ' -> ' . ($expiry ?: 'none') . ', lot ' . ($b['lot_number'] ?: 'none') . ' -> ' . ($lot_db ?: 'none') . ". Reason: $reason";
        inv_log_batch_movement($conn, $batch_id, (int) $b['product_id'], (int) $b['variant_id'], 0, (int) $b['qty_remaining'], 'edit', 'batch', $batch_id, $note, $user_id);
        $msg = "{$b['batch_code']} updated.";
        if ($expiry !== null && $expiry < date('Y-m-d') && $b['status'] === 'available') {
            $r = inv_batch_set_status($conn, $batch_id, 'expired', 'Expiration date ' . date('M j, Y', strtotime($expiry)) . ' has passed', $user_id, true);
            $msg .= ' ' . $r['message'];
        }
        return ['ok' => true, 'message' => $msg];
    }

    /**
     * Takes every available batch past its expiration date out of sellable stock (status expired).
     * Call outside a transaction. Returns how many batches expired.
     */
    function inv_expire_due_batches(mysqli $conn): int
    {
        if (!inv_batches_ready($conn)) {
            return 0;
        }
        $n = 0;
        try {
            $res = $conn->query("SELECT id, expiry_date FROM tbl_inventory_batches
                                 WHERE status = 'available' AND expiry_date IS NOT NULL AND expiry_date < CURDATE() AND qty_remaining > 0
                                 ORDER BY expiry_date LIMIT 200");
            $due = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            foreach ($due as $d) {
                $r = inv_batch_set_status($conn, (int) $d['id'], 'expired', 'Expired on ' . date('M j, Y', strtotime($d['expiry_date'])) . ' - removed from sale automatically', null, true);
                if ($r['ok']) {
                    $n++;
                }
            }
        } catch (Throwable $e) {
            error_log('inv_expire_due_batches: ' . $e->getMessage());
        }
        return $n;
    }

    function inv_valid_date(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
