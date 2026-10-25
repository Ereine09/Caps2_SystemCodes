<?php

function ensure_remittance_schema(mysqli $conn): void
{
    // 1. Table to store remittance requests from riders
    $conn->query("
        CREATE TABLE IF NOT EXISTS `tbl_rider_remittances` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `rider_id` INT NOT NULL,
          `reference_number` VARCHAR(100) NULL,
          `amount` DECIMAL(10, 2) NOT NULL,
          `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
          `notes` TEXT NULL COMMENT 'Reason for rejection, etc.',
          `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `processed_by_user_id` INT NULL,
          `processed_at` DATETIME NULL,
          FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`processed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Junction table to link which orders are included in a remittance
    $conn->query("
        CREATE TABLE IF NOT EXISTS `tbl_rider_remittance_items` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `remittance_id` INT NOT NULL,
          `order_id` INT NOT NULL,
          FOREIGN KEY (`remittance_id`) REFERENCES `tbl_rider_remittances`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`order_id`) REFERENCES `tbl_orders`(`id`) ON DELETE CASCADE,
          UNIQUE KEY `uniq_remittance_order` (`remittance_id`, `order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Repair older installations that already have the table without newer columns.
    $columns = [
        'reference_number' => "ALTER TABLE `tbl_rider_remittances` ADD COLUMN `reference_number` VARCHAR(100) NULL AFTER `rider_id`",
        'remitted_at' => "ALTER TABLE `tbl_rider_remittances` ADD COLUMN `remitted_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_at`",
    ];
    foreach ($columns as $column => $alter_sql) {
        $column_check = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances` LIKE '$column'");
        if ($column_check && $column_check->num_rows === 0) {
            $conn->query($alter_sql);
        }
    }

    // Cash-handover workflow columns (additive; existing rows keep working).
    // `amount` stays the total actually handed over. Expected/difference are
    // snapshots taken by the server from the real orders - never from the rider.
    $handover_columns = [
        'proof_image_path'    => "VARCHAR(255) DEFAULT NULL AFTER `reference_number`",
        'expected_amount'     => "DECIMAL(10,2) NULL AFTER `amount`",
        'declared_amount'     => "DECIMAL(10,2) NULL AFTER `expected_amount`",
        'cash_amount'         => "DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `declared_amount`",
        'gcash_amount'        => "DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `cash_amount`",
        'bank_amount'         => "DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `gcash_amount`",
        'difference_amount'   => "DECIMAL(10,2) NULL AFTER `bank_amount`",
        'rider_notes'         => "TEXT NULL AFTER `notes`",
        'discrepancy_reason'  => "TEXT NULL AFTER `rider_notes`",
        'received_by_user_id' => "INT NULL AFTER `processed_by_user_id`",
        'received_at'         => "DATETIME NULL AFTER `received_by_user_id`",
    ];
    $existing_cols = [];
    $cols_res = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances`");
    while ($cols_res && ($col_row = $cols_res->fetch_assoc())) {
        $existing_cols[$col_row['Field']] = $col_row['Type'];
    }
    foreach ($handover_columns as $column => $definition) {
        if (!isset($existing_cols[$column])) {
            $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `$column` $definition");
        }
    }
    // 'pending' = submitted by the rider, waiting for staff; 'approved' = verified.
    if (isset($existing_cols['status']) && stripos($existing_cols['status'], 'discrepancy') === false) {
        $conn->query(
            "ALTER TABLE `tbl_rider_remittances`
             MODIFY `status` ENUM('pending','approved','rejected','discrepancy') NOT NULL DEFAULT 'pending'"
        );
    }

    // Reference numbers are receipt identifiers and must be unique globally.
    // Keep NULL values allowed for legacy/admin-created records without a reference.
    $reference_index = $conn->query("SHOW INDEX FROM `tbl_rider_remittances` WHERE Key_name = 'uniq_remittance_reference'");
    if ($reference_index && $reference_index->num_rows === 0) {
        $duplicate_refs = $conn->query(
            "SELECT reference_number FROM tbl_rider_remittances
             WHERE reference_number IS NOT NULL AND reference_number <> ''
             GROUP BY reference_number HAVING COUNT(*) > 1 LIMIT 1"
        );
        if ($duplicate_refs && $duplicate_refs->num_rows > 0) {
            throw new RuntimeException('Duplicate remittance reference numbers already exist. Resolve the existing duplicates before enabling global reference validation.');
        }
        $conn->query("ALTER TABLE `tbl_rider_remittances` ADD UNIQUE KEY `uniq_remittance_reference` (`reference_number`)");
    }

    // ---- Remittance TYPE (how the rider is remitting to the shop) + a system-generated Transaction ID ----
    // Two DIFFERENT things, both new:
    //   remittance_type : 'store' (rider physically hands the money to the shop) or 'gcash' (rider sends it
    //                      electronically). Independent of the cash/gcash/bank PAYMENT BREAKDOWN above, which
    //                      keeps its existing meaning (how the money was actually collected/handed over).
    //   transaction_id  : the DPS system's own id (STR-YYYYMMDD-00001 / GCS-YYYYMMDD-00001), never the rider's
    //                      real GCash reference - that is still `reference_number`, untouched, reused as-is.
    if (!isset($existing_cols['remittance_type'])) {
        $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `remittance_type` ENUM('store','gcash') NOT NULL DEFAULT 'store' AFTER `rider_id`");
    }
    if (!isset($existing_cols['transaction_id'])) {
        $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `transaction_id` VARCHAR(24) NULL AFTER `remittance_type`");
    }
    // Table that backs remittance_generate_transaction_id(): one row per (type, day), advanced atomically.
    $conn->query("
        CREATE TABLE IF NOT EXISTS `tbl_remittance_sequences` (
          `seq_type` VARCHAR(10) NOT NULL,
          `seq_day` CHAR(8) NOT NULL,
          `seq_value` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`seq_type`, `seq_day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Backfill + unique constraint, once. Existing rows never had a rider-facing "type" concept before this
    // feature - they were always the shop physically receiving the rider's handover (the documented flow at
    // the top of this file), so they backfill as 'store' (the column DEFAULT already did that on the ALTER
    // above). Each legacy row is assigned its own transaction_id through the SAME generator new rows use
    // (no separate numbering logic), keyed to its own requested_at date and in id order, so numbers still
    // read as a believable day-by-day sequence.
    $txn_index = $conn->query("SHOW INDEX FROM `tbl_rider_remittances` WHERE Key_name = 'uniq_remittance_transaction_id'");
    if ($txn_index && $txn_index->num_rows === 0) {
        $legacy = $conn->query("SELECT id, remittance_type, requested_at FROM tbl_rider_remittances WHERE transaction_id IS NULL OR transaction_id = '' ORDER BY id ASC");
        if ($legacy) {
            while ($row = $legacy->fetch_assoc()) {
                $txn = remittance_generate_transaction_id($conn, (string) ($row['remittance_type'] ?: 'store'), $row['requested_at']);
                $up = $conn->prepare("UPDATE tbl_rider_remittances SET transaction_id = ? WHERE id = ?");
                $rid = (int) $row['id'];
                $up->bind_param('si', $txn, $rid);
                $up->execute();
                $up->close();
            }
        }
        $conn->query("ALTER TABLE `tbl_rider_remittances` ADD UNIQUE KEY `uniq_remittance_transaction_id` (`transaction_id`)");
    }

    // 3. Add a column to tbl_orders to track if the COD payment has been settled by the rider
    $check_col_sql = "SHOW COLUMNS FROM `tbl_orders` LIKE 'payment_settled'";
    $res = $conn->query($check_col_sql);
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_orders` ADD COLUMN `payment_settled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_method`;");
    }

    // 4. Add a rider_id to the delivery table to know who handled the delivery
    $check_rider_col_sql = "SHOW COLUMNS FROM `tbl_delivery` LIKE 'rider_id'";
    $res_rider = $conn->query($check_rider_col_sql);
    if ($res_rider && $res_rider->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_delivery` ADD COLUMN `rider_id` INT NULL AFTER `order_id`;");
        $conn->query("ALTER TABLE `tbl_delivery` ADD FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;");
    }

    // For demonstration, let's ensure the user who confirms the QR code is assigned as the rider.
    // This should be integrated into your rider app's logic.
    // We will modify the QR confirm API to set this.
}

/* ==========================================================================
 * Cash-handover remittance workflow
 *
 * Shared by modules/admin/remittance_api.php (web), modules/rider/
 * rider_remittance_api.php (rider app) and api/admin_app_api.php (staff app)
 * so every entry point applies the SAME rules:
 *   - the expected amount always comes from the real COD orders, never input;
 *   - a remittance is only "Verified" when handed-over == expected;
 *   - any other amount becomes a "Discrepancy" that needs a written reason.
 *
 * Stored status values (kept for backward compatibility):
 *   pending     -> shown as "Submitted"  (rider submitted / staff is receiving)
 *   approved    -> shown as "Verified"
 *   discrepancy -> shown as "Discrepancy"
 *   rejected    -> shown as "Rejected"   (orders are released for re-submission)
 * ======================================================================== */

/** Business-rule error that is safe to show to the user as-is. */
class RemittanceException extends RuntimeException
{
}

function remittance_number(int $id): string
{
    return 'RM-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

/** "Store Remittance" (physical handover) or "GCash Remittance" (electronic). Unknown/legacy -> Store. */
function remittance_type_label(?string $type): string
{
    return strtolower(trim((string) $type)) === 'gcash' ? 'GCash Remittance' : 'Store Remittance';
}

/**
 * The DPS SYSTEM transaction id for a remittance - STR-YYYYMMDD-00001 (store) or GCS-YYYYMMDD-00001
 * (gcash). This is NEVER the rider's real GCash/bank reference (that stays in `reference_number`,
 * typed by the rider/staff and validated in remittance_resolve_reference() - untouched).
 *
 * Server-generated, safe under concurrent submissions from different riders: the per-type-per-day
 * counter in `tbl_remittance_sequences` is advanced with a single atomic statement
 * (INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID()), the standard MySQL/MariaDB-documented
 * race-free counter pattern - the UPDATE itself takes the row lock, so two simultaneous callers can
 * never read-then-write the same value (unlike a bare "SELECT MAX()+1"). The value is never taken
 * from JavaScript, Math.random(), a bare timestamp, or rider_id/order_id. The UNIQUE KEY added on
 * tbl_rider_remittances.transaction_id in ensure_remittance_schema() is the final safety net.
 */
function remittance_generate_transaction_id(mysqli $conn, string $type, ?string $on_date = null): string
{
    $type = $type === 'gcash' ? 'gcash' : 'store';
    $prefix = $type === 'gcash' ? 'GCS' : 'STR';
    $timestamp = ($on_date !== null && $on_date !== '') ? strtotime($on_date) : false;
    $day = $timestamp !== false ? date('Ymd', $timestamp) : date('Ymd');

    // LAST_INSERT_ID(1) on the INSERT side matters: without it, a brand-new (type, day) row takes the plain
    // INSERT branch (no auto_increment column here to set it implicitly), so LAST_INSERT_ID() would read back
    // as 0 on a day's very first call and the first id of the day would wrongly come out as "00000".
    $stmt = $conn->prepare(
        "INSERT INTO tbl_remittance_sequences (seq_type, seq_day, seq_value) VALUES (?, ?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)"
    );
    $stmt->bind_param('ss', $type, $day);
    $stmt->execute();
    $seq = (int) $conn->insert_id; // LAST_INSERT_ID(expr) above makes insert_id return the new seq_value
    $stmt->close();

    return sprintf('%s-%s-%05d', $prefix, $day, $seq);
}

function remittance_status_label(?string $status): string
{
    switch (strtolower(trim((string) $status))) {
        case 'pending':     return 'Submitted';
        case 'approved':    return 'Verified';
        case 'discrepancy': return 'Discrepancy';
        case 'rejected':    return 'Rejected';
        default:            return ucfirst((string) $status);
    }
}

function remittance_peso(float $amount): string
{
    return 'PHP ' . ($amount < 0 ? '-' : '') . number_format(abs($amount), 2);
}

/** "Cash", "GCash", "Bank Transfer" or "Mixed (Cash + GCash)". */
function remittance_payment_method_label(float $cash, float $gcash, float $bank, ?string $reference = null): string
{
    if ($cash <= 0 && $gcash <= 0 && $bank <= 0) {
        // Legacy rows were GCash-only when a reference was recorded.
        return trim((string) $reference) !== '' ? 'GCash' : 'Cash';
    }
    $parts = [];
    if ($cash > 0)  $parts[] = 'Cash';
    if ($gcash > 0) $parts[] = 'GCash';
    if ($bank > 0)  $parts[] = 'Bank Transfer';
    return count($parts) === 1 ? $parts[0] : 'Mixed (' . implode(' + ', $parts) . ')';
}

/**
 * COD orders a rider can remit right now: completed, unsettled, and not already
 * attached to a remittance that is still open (submitted / discrepancy).
 * $riders_id is riders.id (what tbl_orders.rider_id stores).
 */
function remittance_eligible_orders(mysqli $conn, int $riders_id, ?array $order_ids = null, bool $lock = false): array
{
    $sql = "SELECT o.id AS order_id, o.order_number, o.total, o.created_at, c.name AS customer_name
            FROM tbl_orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.rider_id = ? AND o.payment_method = 'cod'
              AND o.order_status = 'completed' AND o.payment_settled = 0
              AND NOT EXISTS (
                  SELECT 1 FROM tbl_rider_remittance_items i
                  JOIN tbl_rider_remittances r ON r.id = i.remittance_id
                  WHERE i.order_id = o.id AND r.status IN ('pending','discrepancy')
              )";
    $types = 'i';
    $params = [$riders_id];
    if ($order_ids !== null) {
        if (!$order_ids) {
            return [];
        }
        $sql .= ' AND o.id IN (' . implode(',', array_fill(0, count($order_ids), '?')) . ')';
        $types .= str_repeat('i', count($order_ids));
        $params = array_merge($params, $order_ids);
    }
    $sql .= ' ORDER BY o.created_at ASC' . ($lock ? ' FOR UPDATE' : '');

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function remittance_orders_total(array $orders): float
{
    $total = 0.0;
    foreach ($orders as $o) {
        $total += (float) $o['total'];
    }
    return round($total, 2);
}

/** Parses/validates a cash/gcash/bank breakdown from request input. */
function remittance_parse_amounts(array $in): array
{
    $out = [];
    foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'bank' => 'Bank Transfer'] as $key => $label) {
        $raw = $in[$key . '_amount'] ?? 0;
        if ($raw === '' || $raw === null) {
            $raw = 0;
        }
        if (!is_numeric($raw)) {
            throw new RemittanceException("The {$label} amount is not a valid number.");
        }
        $value = round((float) $raw, 2);
        if ($value < 0) {
            throw new RemittanceException("The {$label} amount cannot be negative.");
        }
        $out[$key] = $value;
    }
    $out['total'] = round($out['cash'] + $out['gcash'] + $out['bank'], 2);
    return $out;
}

/**
 * Validates the optional GCash/bank reference against the amounts entered.
 * Returns the value to store: the real reference typed by the rider/staff, or
 * NULL for a cash-only handover. A reference is only REQUIRED when GCash was
 * actually used, and is never generated by the system.
 */
function remittance_resolve_reference(mysqli $conn, array $amounts, string $reference, int $exclude_id = 0): ?string
{
    $reference = trim($reference);
    if ($amounts['gcash'] > 0 && $reference === '') {
        throw new RemittanceException('Enter the GCash reference number for the GCash amount.');
    }
    if ($reference === '' || ($amounts['gcash'] <= 0 && $amounts['bank'] <= 0)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id FROM tbl_rider_remittances WHERE reference_number = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $reference, $exclude_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($taken) {
        throw new RemittanceException('This reference number has already been used for another remittance.');
    }
    return $reference;
}

/**
 * Inserts a "Submitted" (pending) remittance plus its order links.
 * Call inside a transaction. $orders must come from remittance_eligible_orders().
 * $staff_created = staff are recording it for a rider who did not submit in the
 * app; the amounts are then a placeholder that remittance_apply_receipt() replaces.
 */
function remittance_insert_pending(
    mysqli $conn, int $rider_user_id, array $orders, array $amounts, ?string $reference,
    ?string $proof_path, ?string $rider_notes, bool $staff_created = false, string $remittance_type = 'store'
): int {
    $remittance_type = $remittance_type === 'gcash' ? 'gcash' : 'store';
    $expected = remittance_orders_total($orders);
    if ($staff_created) {
        $amounts = ['cash' => $expected, 'gcash' => 0.0, 'bank' => 0.0, 'total' => $expected];
    }
    $declared = $amounts['total'];
    $difference = round($declared - $expected, 2);
    $cash = $amounts['cash'];
    $gcash = $amounts['gcash'];
    $bank = $amounts['bank'];
    $rider_notes = ($rider_notes !== null && trim($rider_notes) !== '') ? trim($rider_notes) : null;
    $transaction_id = remittance_generate_transaction_id($conn, $remittance_type);

    $stmt = $conn->prepare(
        "INSERT INTO tbl_rider_remittances
            (rider_id, remittance_type, transaction_id, amount, expected_amount, declared_amount, cash_amount, gcash_amount, bank_amount,
             difference_amount, reference_number, proof_image_path, rider_notes, status, remitted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->bind_param(
        'issdddddddsss',
        $rider_user_id, $remittance_type, $transaction_id, $declared, $expected, $declared,
        $cash, $gcash, $bank, $difference,
        $reference, $proof_path, $rider_notes
    );
    $stmt->execute();
    $remittance_id = (int) $conn->insert_id;
    $stmt->close();

    $item = $conn->prepare("INSERT INTO tbl_rider_remittance_items (remittance_id, order_id) VALUES (?, ?)");
    foreach ($orders as $o) {
        $order_id = (int) $o['order_id'];
        $item->bind_param('ii', $remittance_id, $order_id);
        $item->execute();
    }
    $item->close();
    return $remittance_id;
}

/**
 * Staff/admin receives the physical handover for a Submitted remittance.
 * Call inside a transaction. $in: cash_amount, gcash_amount, bank_amount,
 * reference_number, notes, discrepancy_reason, mark_discrepancy. When no amounts
 * are supplied the rider's own declared breakdown is used.
 *
 * Verified only when handed-over == expected (from the real orders). Otherwise
 * the record becomes a Discrepancy and a reason is mandatory.
 */
function remittance_apply_receipt(mysqli $conn, int $remittance_id, int $staff_user_id, array $in): array
{
    $rem = remittance_lock_row($conn, $remittance_id);
    if ($rem['status'] !== 'pending') {
        throw new RemittanceException('This remittance was already processed (status: ' . remittance_status_label($rem['status']) . ').');
    }

    // Expected amount = real orders linked to this remittance.
    $stmt = $conn->prepare(
        "SELECT o.id, o.total, o.payment_settled, o.payment_method, o.order_status
         FROM tbl_rider_remittance_items i
         JOIN tbl_orders o ON o.id = i.order_id
         WHERE i.remittance_id = ? FOR UPDATE"
    );
    $stmt->bind_param('i', $remittance_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$orders) {
        throw new RemittanceException('This remittance has no orders attached.');
    }
    $expected = 0.0;
    foreach ($orders as $o) {
        if ((int) $o['payment_settled'] === 1 || $o['payment_method'] !== 'cod' || $o['order_status'] !== 'completed') {
            throw new RemittanceException('One or more orders in this remittance are no longer eligible.');
        }
        $expected += (float) $o['total'];
    }
    $expected = round($expected, 2);

    $has_amounts = isset($in['cash_amount']) || isset($in['gcash_amount']) || isset($in['bank_amount']);
    if ($has_amounts) {
        $amounts = remittance_parse_amounts($in);
    } else {
        $amounts = remittance_row_breakdown($rem);
    }

    $reference_in = array_key_exists('reference_number', $in) ? (string) $in['reference_number'] : (string) $rem['reference_number'];
    $reference = remittance_resolve_reference($conn, $amounts, $reference_in, $remittance_id);

    $total = $amounts['total'];
    $difference = round($total - $expected, 2);
    $force = !empty($in['mark_discrepancy']);
    $status = (abs($difference) < 0.005 && !$force) ? 'approved' : 'discrepancy';

    $notes = trim((string) ($in['notes'] ?? ''));
    $reason = trim((string) ($in['discrepancy_reason'] ?? ''));
    if ($status === 'discrepancy') {
        $reason = $reason !== '' ? $reason : $notes;
        if ($reason === '') {
            throw new RemittanceException('The amount does not match the expected amount. Enter the reason for the discrepancy.');
        }
    } else {
        $reason = '';
    }
    $notes_db = $notes !== '' ? $notes : null;
    $reason_db = $reason !== '' ? $reason : null;
    $cash = $amounts['cash'];
    $gcash = $amounts['gcash'];
    $bank = $amounts['bank'];

    // Who at the shop physically received the money. Defaults to the person recording it; a different
    // admin/staff member can be picked (the recording user is still kept in processed_by_user_id).
    $received_by = $staff_user_id;
    $picked = (int) ($in['received_by_user_id'] ?? 0);
    if ($picked > 0 && $picked !== $staff_user_id) {
        $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('admin','staff') LIMIT 1");
        $chk->bind_param('i', $picked);
        $chk->execute();
        $found = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$found) {
            throw new RemittanceException('Choose a valid admin or staff member as the person who received the remittance.');
        }
        $received_by = $picked;
    }

    $upd = $conn->prepare(
        "UPDATE tbl_rider_remittances
         SET amount = ?, expected_amount = ?, cash_amount = ?, gcash_amount = ?, bank_amount = ?,
             difference_amount = ?, reference_number = ?, status = ?, notes = ?, discrepancy_reason = ?,
             received_by_user_id = ?, received_at = NOW(), processed_by_user_id = ?, processed_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $upd->bind_param(
        'ddddddssssiii',
        $total, $expected, $cash, $gcash, $bank, $difference,
        $reference, $status, $notes_db, $reason_db, $received_by, $staff_user_id, $remittance_id
    );
    $upd->execute();
    $changed = $upd->affected_rows > 0;
    $upd->close();
    if (!$changed) {
        throw new RemittanceException('Remittance was already processed.');
    }

    // Orders are only settled once the amount is verified; a discrepancy keeps
    // them attached to this remittance until staff resolve or reject it.
    if ($status === 'approved') {
        remittance_settle_orders($conn, $remittance_id);
    }

    $result = remittance_result($conn, $rem, $status, $expected, $total, $difference, $amounts, count($orders), $reason);
    $result['received_by_user_id'] = $received_by;
    $result['received_by'] = remittance_user_name($conn, $received_by);
    $result['recorded_by_user_id'] = $staff_user_id;
    return $result;
}

/** Display name of a user (full name, else username). */
function remittance_user_name(mysqli $conn, int $user_id): string
{
    $stmt = $conn->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS n, username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return trim((string) ($u['n'] ?? '')) !== '' ? trim($u['n']) : (string) ($u['username'] ?? '');
}

/**
 * cash/gcash/bank/total for a stored remittance row. Legacy rows (created before
 * the breakdown columns existed) carry only `amount`: GCash when a reference was
 * recorded, otherwise cash.
 */
function remittance_row_breakdown(array $row): array
{
    $b = [
        'cash'  => round((float) ($row['cash_amount'] ?? 0), 2),
        'gcash' => round((float) ($row['gcash_amount'] ?? 0), 2),
        'bank'  => round((float) ($row['bank_amount'] ?? 0), 2),
    ];
    if ($b['cash'] + $b['gcash'] + $b['bank'] <= 0 && (float) ($row['amount'] ?? 0) > 0) {
        $legacy_gcash = trim((string) ($row['reference_number'] ?? '')) !== '';
        $b[$legacy_gcash ? 'gcash' : 'cash'] = round((float) $row['amount'], 2);
    }
    $b['total'] = round($b['cash'] + $b['gcash'] + $b['bank'], 2);
    return $b;
}

/** Staff resolves a Discrepancy: explicit action with a mandatory written reason. */
function remittance_apply_resolution(mysqli $conn, int $remittance_id, int $staff_user_id, string $resolution): array
{
    $resolution = trim($resolution);
    if ($resolution === '') {
        throw new RemittanceException('Enter a resolution note explaining how this discrepancy was settled.');
    }
    $rem = remittance_lock_row($conn, $remittance_id);
    if ($rem['status'] !== 'discrepancy') {
        throw new RemittanceException('Only a remittance with a discrepancy can be resolved.');
    }

    $stmt = $conn->prepare(
        "UPDATE tbl_rider_remittances
         SET status = 'approved', discrepancy_reason = CONCAT(COALESCE(discrepancy_reason, ''), ?),
             processed_by_user_id = ?, processed_at = NOW()
         WHERE id = ? AND status = 'discrepancy'"
    );
    $suffix = "\nResolution: " . $resolution;
    $stmt->bind_param('sii', $suffix, $staff_user_id, $remittance_id);
    $stmt->execute();
    $stmt->close();
    remittance_settle_orders($conn, $remittance_id);

    $count = $conn->query("SELECT COUNT(*) c FROM tbl_rider_remittance_items WHERE remittance_id = " . (int) $remittance_id)->fetch_assoc();
    $amounts = ['cash' => (float) $rem['cash_amount'], 'gcash' => (float) $rem['gcash_amount'], 'bank' => (float) $rem['bank_amount']];
    return remittance_result(
        $conn, $rem, 'approved', (float) $rem['expected_amount'], (float) $rem['amount'],
        (float) $rem['difference_amount'], $amounts, (int) $count['c'], $resolution
    );
}

/** Rejects a Submitted or Discrepancy remittance; its orders become eligible again. */
function remittance_apply_rejection(mysqli $conn, int $remittance_id, int $staff_user_id, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RemittanceException('A rejection reason is required.');
    }
    $rem = remittance_lock_row($conn, $remittance_id);
    if (!in_array($rem['status'], ['pending', 'discrepancy'], true)) {
        throw new RemittanceException('This remittance was already processed (status: ' . remittance_status_label($rem['status']) . ').');
    }
    $stmt = $conn->prepare(
        "UPDATE tbl_rider_remittances
         SET status = 'rejected', notes = ?, processed_by_user_id = ?, processed_at = NOW()
         WHERE id = ? AND status IN ('pending','discrepancy')"
    );
    $stmt->bind_param('sii', $reason, $staff_user_id, $remittance_id);
    $stmt->execute();
    $stmt->close();
    $amounts = ['cash' => 0.0, 'gcash' => 0.0, 'bank' => 0.0];
    return remittance_result($conn, $rem, 'rejected', (float) $rem['expected_amount'], (float) $rem['amount'], (float) $rem['difference_amount'], $amounts, 0, $reason);
}

function remittance_lock_row(mysqli $conn, int $remittance_id): array
{
    $stmt = $conn->prepare("SELECT * FROM tbl_rider_remittances WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $remittance_id);
    $stmt->execute();
    $rem = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rem) {
        throw new RemittanceException('Remittance not found.');
    }
    return $rem;
}

function remittance_settle_orders(mysqli $conn, int $remittance_id): void
{
    $stmt = $conn->prepare(
        "UPDATE tbl_orders o
         JOIN tbl_rider_remittance_items i ON i.order_id = o.id
         SET o.payment_settled = 1
         WHERE i.remittance_id = ? AND o.payment_method = 'cod'
           AND o.order_status = 'completed' AND o.payment_settled = 0"
    );
    $stmt->bind_param('i', $remittance_id);
    $stmt->execute();
    $stmt->close();
}

function remittance_result(mysqli $conn, array $rem, string $status, float $expected, float $total, float $difference, array $amounts, int $order_count, string $reason): array
{
    $stmt = $conn->prepare(
        "SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username
         FROM users WHERE id = ? LIMIT 1"
    );
    $rider_uid = (int) $rem['rider_id'];
    $stmt->bind_param('i', $rider_uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $declared = $rem['declared_amount'] !== null ? (float) $rem['declared_amount'] : null;

    return [
        'id'             => (int) $rem['id'],
        'number'         => remittance_number((int) $rem['id']),
        'remittance_type' => (string) ($rem['remittance_type'] ?? 'store'),
        'type_label'     => remittance_type_label($rem['remittance_type'] ?? 'store'),
        'transaction_id' => (string) ($rem['transaction_id'] ?? ''),
        'rider_user_id'  => $rider_uid,
        'rider_name'     => trim((string) ($u['full_name'] ?? '')) !== '' ? trim($u['full_name']) : (string) ($u['username'] ?? 'Rider'),
        'status'         => $status,
        'status_label'   => remittance_status_label($status),
        'expected'       => $expected,
        'total'          => $total,
        'difference'     => $difference,
        'amounts'        => $amounts,
        'order_count'    => $order_count,
        'reason'         => $reason,
        'declared'       => $declared,
        'amount_changed' => $declared !== null && abs($declared - $total) >= 0.005,
    ];
}

/**
 * Best-effort activity log entry (user_id, action, details). Descriptions use
 * the visible remittance number and rider name, never raw database ids.
 */
function remittance_audit(mysqli $conn, int $user_id, string $action, string $details, string $prefix = ''): void
{
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $details = $prefix . $details;
        $stmt->bind_param('iss', $user_id, $action, $details);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Remittance audit failed: ' . $e->getMessage());
    }
}

/** Writes the activity-log trail for a receive/verify/discrepancy result. */
function remittance_audit_receipt(mysqli $conn, int $staff_user_id, array $r, string $prefix = ''): void
{
    $who = "{$r['number']} from {$r['rider_name']}";
    remittance_audit($conn, $staff_user_id, 'Remittance Received',
        "Received {$who}: " . remittance_peso($r['total']) . ' handed over against ' . remittance_peso($r['expected']) . ' expected.'
        . (!empty($r['received_by']) ? " Received at the shop by {$r['received_by']}." : ''), $prefix);
    if ($r['amount_changed']) {
        remittance_audit($conn, $staff_user_id, 'Remittance Amount Adjusted',
            "Amount for {$who} changed from " . remittance_peso((float) $r['declared']) . ' (rider) to ' . remittance_peso($r['total']) . ' (staff).', $prefix);
    }
    if ($r['status'] === 'approved') {
        remittance_audit($conn, $staff_user_id, 'Remittance Verified', "Verified {$who}: amount matched the expected collection.", $prefix);
    } else {
        remittance_audit($conn, $staff_user_id, 'Remittance Discrepancy',
            "Marked discrepancy on {$who}: difference " . remittance_peso($r['difference']) . '. Reason: ' . $r['reason'], $prefix);
    }
}

/** In-app notification to the rider after staff act on their remittance. */
function remittance_notify_rider(mysqli $conn, array $r): void
{
    if (!function_exists('notifications_create') || $r['rider_user_id'] <= 0) {
        return;
    }
    $orders = $r['order_count'] . ' ' . ($r['order_count'] === 1 ? 'order' : 'orders');
    switch ($r['status']) {
        case 'approved':
            $type = 'remittance_verified';
            $title = 'Remittance verified';
            $message = "Your remittance {$r['number']} of " . remittance_peso($r['total']) . " ({$orders}) was received" . (!empty($r['received_by']) ? " by {$r['received_by']} at the shop" : '') . " and verified. Your cash balance is settled.";
            break;
        case 'discrepancy':
            $type = 'remittance_discrepancy';
            $title = 'Remittance has a discrepancy';
            $message = "Your remittance {$r['number']} was received" . (!empty($r['received_by']) ? " by {$r['received_by']} at the shop" : '') . " but differs from the expected amount by "
                . remittance_peso($r['difference']) . ".\n\nNote: {$r['reason']}";
            break;
        default:
            $type = 'remittance_rejected';
            $title = 'Remittance needs correction';
            $message = "Your remittance {$r['number']} was not accepted.\n\nReason: {$r['reason']}\n\nPlease review the details and submit a corrected remittance.";
    }
    @notifications_create($conn, [
        'user_id'         => $r['rider_user_id'],
        'type'            => $type,
        'channel'         => 'in_app',
        'title'           => $title,
        'message'         => $message,
        'reference_table' => 'tbl_rider_remittances',
        'reference_id'    => $r['id'],
    ]);
}
