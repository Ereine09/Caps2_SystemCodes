<?php
/**
 * Return / Refund requests - one request per returned order item (partial quantities allowed).
 *
 *   Customer (customer/orders.php) picks an item of a received order (within RR_WINDOW_DAYS), the
 *   quantity, a reason, a description, the resolution they prefer and a photo -> 'pending'.
 *   Admin/staff (modules/shared/order_return.php) then move it through a fixed workflow:
 *
 *     pending  -> approved  -> received -> inspected -> refunded | replaced
 *     pending | approved -> rejected
 *
 * The ORDER itself is never touched (a completed order stays completed); the request carries its
 * own status. Inventory is only changed at inspection, from the condition staff record:
 *
 *   resellable                         -> stock + quantity   (movement RETURN-IN)
 *   damaged / expired / non_resellable -> stock + 0          (RETURN-DAMAGED / -EXPIRED / -NONSELLABLE)
 *
 * and a replacement deducts the same product/variant (REPLACEMENT-OUT). tbl_return_stock_movements
 * has UNIQUE (return_request_id, movement_type) and every step is a conditional status update inside
 * a transaction with the request row locked, so a refresh, double click or repeated request can't
 * apply stock, money or points twice.
 *
 * Refund amount is always computed here from the stored order item, never taken from the browser:
 *   (unit_price x qty) / items subtotal x (subtotal + VAT - discount), capped at what is still
 *   refundable on the order. Delivery fee and rider tip are not refunded.
 * Loyalty points (earned as subtotal / 100, see loyalty_helper.php) are reversed for the same share.
 *
 * Stock changes go through restore_product_stock() / deduct_product_stock() from
 * customer/includes/functions.php, which every caller that inspects or replaces loads.
 *
 * Return pickup (free): approving a request schedules a rider pickup for the next business day
 * (rr_pickup_date()). Any rider can claim it in the rider portal (modules/rider/rider_returns_api.php),
 * marks it picked up at the customer, then dropped off at the shop - which is the 'receive' step.
 * Staff can still mark an item received themselves (e.g. the customer brought it in).
 * Refunds are released within RR_REFUND_MIN_DAYS - RR_REFUND_MAX_DAYS business days of the item
 * reaching the shop (rr_refund_window()). Business days = every day the shop is open (closed Saturday).
 */
require_once __DIR__ . '/upload_helper.php';
require_once __DIR__ . '/notification_helper.php';

const RR_WINDOW_DAYS = 7;
const RR_REFUND_MIN_DAYS = 5;
const RR_REFUND_MAX_DAYS = 7;
const RR_CLOSED_WEEKDAY = 6; // date('w'): the shop is closed on Saturday

if (!function_exists('rr_ensure_schema')) {
    function rr_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_return_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            customer_id INT NOT NULL,
            reason VARCHAR(50) NOT NULL,
            details TEXT NULL,
            photo_path VARCHAR(255) NULL,
            status ENUM('pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending',
            staff_note TEXT NULL,
            refund_amount DECIMAL(10,2) NULL,
            restocked TINYINT(1) NOT NULL DEFAULT 0,
            handled_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY idx_return_order (order_id),
            KEY idx_return_customer (customer_id),
            KEY idx_return_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Item-level returns (added later; older rows are whole-order requests with order_item_id NULL).
        $cols = [];
        $res = $conn->query("SHOW COLUMNS FROM tbl_return_requests");
        while ($res && ($c = $res->fetch_assoc())) {
            $cols[$c['Field']] = $c['Type'];
        }
        $add = [
            'order_item_id'      => 'INT NULL AFTER customer_id',
            'quantity'           => 'INT NULL AFTER order_item_id',
            'unit_price'         => 'DECIMAL(10,2) NULL AFTER quantity',
            'requested_amount'   => 'DECIMAL(10,2) NULL AFTER unit_price',
            'resolution'         => "ENUM('refund','replacement') NOT NULL DEFAULT 'refund' AFTER photo_path",
            'item_condition'     => "ENUM('resellable','damaged','expired','non_resellable') NULL AFTER resolution",
            'inspection_note'    => 'TEXT NULL AFTER item_condition',
            'stock_processed'    => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER restocked',
            'replacement_issued' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_processed',
            'points_reversed'    => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER replacement_issued',
            'approved_at'        => 'DATETIME NULL',
            'pickup_date'        => 'DATE NULL',
            'pickup_rider_id'    => 'INT NULL',
            'pickup_claimed_at'  => 'DATETIME NULL',
            'picked_up_at'       => 'DATETIME NULL',
            'received_at'        => 'DATETIME NULL',
            'received_by'        => 'INT NULL',
            'inspected_at'       => 'DATETIME NULL',
            'inspected_by'       => 'INT NULL',
            'completed_at'       => 'DATETIME NULL',
        ];
        foreach ($add as $col => $def) {
            if (!isset($cols[$col])) {
                $conn->query("ALTER TABLE tbl_return_requests ADD COLUMN `$col` $def");
            }
        }
        if (isset($cols['status']) && strpos($cols['status'], "'inspected'") === false) {
            $conn->query("ALTER TABLE tbl_return_requests MODIFY status ENUM('pending','approved','received','inspected','refunded','replaced','rejected') NOT NULL DEFAULT 'pending'");
        }
        // One order can now have several requests (one per item).
        $idx = $conn->query("SHOW INDEX FROM tbl_return_requests WHERE Key_name = 'uq_return_order'");
        if ($idx && $idx->num_rows > 0) {
            $conn->query("ALTER TABLE tbl_return_requests DROP INDEX uq_return_order, ADD KEY idx_return_order (order_id)");
        }
        $idx = $conn->query("SHOW INDEX FROM tbl_return_requests WHERE Key_name = 'idx_return_item'");
        if ($idx && $idx->num_rows === 0) {
            $conn->query("ALTER TABLE tbl_return_requests ADD KEY idx_return_item (order_item_id)");
        }

        // Inventory audit for returns. The unique key is the last line of defence against a
        // second stock change for the same request.
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_return_stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_request_id INT NOT NULL,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            variant_id INT NULL,
            product_name VARCHAR(255) NOT NULL,
            movement_type ENUM('RETURN-IN','RETURN-DAMAGED','RETURN-EXPIRED','RETURN-NONSELLABLE','REPLACEMENT-OUT') NOT NULL,
            quantity INT NOT NULL,
            stock_change INT NOT NULL,
            note VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_return_movement (return_request_id, movement_type),
            KEY idx_movement_product (product_id, variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('rr_add_business_days')) {
    /** $days business days after $ymd (Y-m-d), skipping the day the shop is closed. */
    function rr_add_business_days(string $ymd, int $days): string
    {
        $t = strtotime($ymd . ' 12:00:00');
        while ($days > 0) {
            $t = strtotime('+1 day', $t);
            if ((int) date('w', $t) !== RR_CLOSED_WEEKDAY) {
                $days--;
            }
        }
        return date('Y-m-d', $t);
    }

    /** Pickup date for a return approved at $approved (timestamp): the next business day. */
    function rr_pickup_date(int $approved): string
    {
        return rr_add_business_days(date('Y-m-d', $approved), 1);
    }

    /** ['from' => Y-m-d, 'to' => Y-m-d]: when a refund is released, counted from the item reaching the shop. */
    function rr_refund_window(string $received_at): array
    {
        $day = date('Y-m-d', strtotime($received_at));
        return ['from' => rr_add_business_days($day, RR_REFUND_MIN_DAYS), 'to' => rr_add_business_days($day, RR_REFUND_MAX_DAYS)];
    }

    /** True when a refund request is past its latest release date and still not refunded. */
    function rr_refund_overdue(array $r): bool
    {
        return in_array($r['status'] ?? '', ['received', 'inspected'], true) && ($r['resolution'] ?? '') === 'refund'
            && !empty($r['received_at']) && date('Y-m-d') > rr_refund_window((string) $r['received_at'])['to'];
    }

    /** "Mon, Oct 12" style date. */
    function rr_day(string $ymd): string
    {
        return date('D, M j', strtotime($ymd));
    }
}

if (!function_exists('rr_reasons')) {
    /** Reasons a customer can pick today. */
    function rr_reasons(): array
    {
        return [
            'damaged_delivery' => 'Damaged during delivery',
            'expired'          => 'Expired product',
            'wrong_item'       => 'Wrong item received',
            'changed_mind'     => 'Ordered the wrong item / changed mind',
            'other'            => 'Other',
        ];
    }
}

if (!function_exists('rr_reason_label')) {
    /** Label for any stored reason, including the ones older requests used. */
    function rr_reason_label(string $reason): string
    {
        return (rr_reasons() + [
            'damaged'          => 'Item arrived damaged',
            'missing_item'     => 'Missing item / incomplete order',
            'not_as_described' => 'Not as described',
        ])[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
    }
}

if (!function_exists('rr_reason_needs_photo')) {
    /** Claims about the item itself need a photo as evidence. */
    function rr_reason_needs_photo(string $reason): bool
    {
        return in_array($reason, ['damaged_delivery', 'expired', 'wrong_item'], true);
    }
}

if (!function_exists('rr_resolutions')) {
    function rr_resolutions(): array
    {
        return ['refund' => 'Refund', 'replacement' => 'Replacement'];
    }
}

if (!function_exists('rr_conditions')) {
    /** Inspection results: [label, adds back to sellable stock?, movement type]. */
    function rr_conditions(): array
    {
        return [
            'resellable'     => ['Good / resellable', true, 'RETURN-IN'],
            'damaged'        => ['Damaged', false, 'RETURN-DAMAGED'],
            'expired'        => ['Expired', false, 'RETURN-EXPIRED'],
            'non_resellable' => ['Opened / non-resellable', false, 'RETURN-NONSELLABLE'],
        ];
    }
}

if (!function_exists('rr_status_label')) {
    /** [label, tone] for a request status. Tone: warning | info | danger | success. */
    function rr_status_label(string $status): array
    {
        return [
            'pending'   => ['Pending review', 'warning'],
            'approved'  => ['Approved for return', 'info'],
            'received'  => ['Item received', 'info'],
            'inspected' => ['Inspected', 'info'],
            'refunded'  => ['Refunded', 'success'],
            'replaced'  => ['Replaced', 'success'],
            'rejected'  => ['Rejected', 'danger'],
        ][$status] ?? ['Return ' . $status, 'info'];
    }
}

if (!function_exists('rr_open_statuses')) {
    /** Statuses still waiting for staff. */
    function rr_open_statuses(): array
    {
        return ['pending', 'approved', 'received', 'inspected'];
    }
}

if (!function_exists('rr_get_for_order')) {
    /** Every request on an order, oldest first, with the item's name. */
    function rr_get_for_order(mysqli $conn, int $order_id): array
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT r.*, oi.product_name, oi.variant_size, oi.product_id, oi.variant_id, oi.quantity AS purchased_qty,
                NULLIF(TRIM(CONCAT(COALESCE(pu.first_name, ''), ' ', COALESCE(pu.last_name, ''))), '') AS pickup_rider_name
            FROM tbl_return_requests r LEFT JOIN tbl_order_items oi ON oi.id = r.order_item_id
            LEFT JOIN users pu ON pu.id = r.pickup_rider_id
            WHERE r.order_id = ? ORDER BY r.id ASC");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('rr_get_for_customer')) {
    /** A customer's most recent request per order, keyed by order_id. */
    function rr_get_for_customer(mysqli $conn, int $customer_id): array
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT * FROM tbl_return_requests WHERE customer_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $map = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $map[(int) $row['order_id']] = $row;
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('rr_order_received_at')) {
    /** When the customer got the order: the delivery time if recorded, else the order's last update. */
    function rr_order_received_at(mysqli $conn, array $order): int
    {
        $stmt = $conn->prepare("SELECT delivered_at FROM tbl_delivery WHERE order_id = ? AND delivered_at IS NOT NULL ORDER BY delivered_at DESC LIMIT 1");
        $order_id = (int) $order['id'];
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $delivered = $stmt->get_result()->fetch_assoc()['delivered_at'] ?? null;
        $stmt->close();
        $when = $delivered ?: (($order['updated_at'] ?? '') ?: $order['created_at']);
        return (int) strtotime((string) $when);
    }
}

if (!function_exists('rr_refund_base')) {
    /** What the customer paid for the goods: subtotal + VAT - discount (no delivery fee, no tip). */
    function rr_refund_base(array $order): float
    {
        return max(0.0, round((float) $order['subtotal'] + (float) $order['vat_amount'] - (float) $order['discount_amount'], 2));
    }
}

if (!function_exists('rr_returnable_items')) {
    /**
     * The order's items with how many units can still be returned and the refund per unit.
     * Units already in a request that wasn't rejected are not returnable again.
     * Returns [order_item_id => item row + returnable_qty, refund_per_unit].
     */
    function rr_returnable_items(mysqli $conn, array $order): array
    {
        rr_ensure_schema($conn);
        $order_id = (int) $order['id'];
        $stmt = $conn->prepare("SELECT oi.*, COALESCE((SELECT SUM(r.quantity) FROM tbl_return_requests r
                    WHERE r.order_item_id = oi.id AND r.status <> 'rejected'), 0) AS requested_qty
                FROM tbl_order_items oi WHERE oi.order_id = ? ORDER BY oi.id ASC");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $items_total = 0.0;
        foreach ($rows as $r) {
            $items_total += (float) $r['total_price'];
        }
        $base = rr_refund_base($order);
        $items = [];
        foreach ($rows as $r) {
            $r['returnable_qty'] = max(0, (int) $r['quantity'] - (int) $r['requested_qty']);
            $r['refund_per_unit'] = $items_total > 0 ? (float) $r['unit_price'] / $items_total * $base : 0.0;
            $items[(int) $r['id']] = $r;
        }
        return $items;
    }
}

if (!function_exists('rr_compute_refund')) {
    /**
     * Refund for $qty units of an order item, capped at what is still refundable on the order
     * (excluding $exclude_request_id from the "already refunded" sum).
     */
    function rr_compute_refund(mysqli $conn, array $order, int $order_item_id, int $qty, int $exclude_request_id = 0): float
    {
        $items = rr_returnable_items($conn, $order);
        if (!isset($items[$order_item_id]) || $qty <= 0) {
            return 0.0;
        }
        $amount = round($items[$order_item_id]['refund_per_unit'] * $qty, 2);

        $order_id = (int) $order['id'];
        $stmt = $conn->prepare("SELECT COALESCE(SUM(refund_amount), 0) AS done FROM tbl_return_requests WHERE order_id = ? AND status = 'refunded' AND id <> ?");
        $stmt->bind_param('ii', $order_id, $exclude_request_id);
        $stmt->execute();
        $already = (float) ($stmt->get_result()->fetch_assoc()['done'] ?? 0);
        $stmt->close();
        return max(0.0, min($amount, round(rr_refund_base($order) - $already, 2)));
    }
}

if (!function_exists('rr_eligibility')) {
    /**
     * Can the customer ask for a return on this order right now (on at least one item)?
     * $requests is unused (kept for older callers); requests are read from the database.
     * Returns ['ok' => bool, 'message' => string, 'deadline' => int|null].
     */
    function rr_eligibility(mysqli $conn, array $order, $requests = null): array
    {
        if (!in_array($order['order_status'], ['completed', 'reviews'], true)) {
            return ['ok' => false, 'message' => 'Only orders you have received can be returned.', 'deadline' => null];
        }
        $deadline = rr_order_received_at($conn, $order) + RR_WINDOW_DAYS * 86400;
        if (time() > $deadline) {
            return ['ok' => false, 'message' => 'The ' . RR_WINDOW_DAYS . '-day return window for this order has ended.', 'deadline' => $deadline];
        }
        foreach (rr_get_for_order($conn, (int) $order['id']) as $r) {
            if ($r['order_item_id'] === null && $r['status'] !== 'rejected') {
                return ['ok' => false, 'message' => 'A return request already covers this whole order.', 'deadline' => $deadline];
            }
        }
        foreach (rr_returnable_items($conn, $order) as $item) {
            if ($item['returnable_qty'] > 0) {
                return ['ok' => true, 'message' => '', 'deadline' => $deadline];
            }
        }
        return ['ok' => false, 'message' => 'Every item in this order already has a return request.', 'deadline' => $deadline];
    }
}

if (!function_exists('rr_submit')) {
    /**
     * Saves a customer's request for one order item. $order must already be loaded for this
     * customer (ownership is checked again here). Returns ['ok' => bool, 'message' => string].
     */
    function rr_submit(mysqli $conn, array $order, int $customer_id, array $input, ?array $photo, bool $notify = true): array
    {
        rr_ensure_schema($conn);
        if ((int) $order['customer_id'] !== $customer_id) {
            return ['ok' => false, 'message' => 'That order could not be found.'];
        }
        $eligibility = rr_eligibility($conn, $order);
        if (!$eligibility['ok']) {
            return ['ok' => false, 'message' => $eligibility['message']];
        }
        $reason = (string) ($input['reason'] ?? '');
        if (!isset(rr_reasons()[$reason])) {
            return ['ok' => false, 'message' => 'Please choose a reason for the return.'];
        }
        $resolution = (string) ($input['resolution'] ?? '');
        if (!isset(rr_resolutions()[$resolution])) {
            return ['ok' => false, 'message' => 'Please choose a refund or a replacement.'];
        }
        $details = mb_substr(trim((string) ($input['details'] ?? '')), 0, 1000);
        if ($details === '') {
            return ['ok' => false, 'message' => 'Please describe the problem with the item.'];
        }
        $item_id = (int) ($input['order_item_id'] ?? 0);
        $qty = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
        $has_photo = $photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$has_photo && rr_reason_needs_photo($reason)) {
            return ['ok' => false, 'message' => 'Please attach a photo of the item for this reason.'];
        }

        $order_id = (int) $order['id'];
        $conn->begin_transaction();
        try {
            // Lock the order so two submits can't both claim the last returnable units.
            $lock = $conn->prepare("SELECT id FROM tbl_orders WHERE id = ? AND customer_id = ? FOR UPDATE");
            $lock->bind_param('ii', $order_id, $customer_id);
            $lock->execute();
            $owned = (bool) $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$owned) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'That order could not be found.'];
            }
            $items = rr_returnable_items($conn, $order);
            if (!isset($items[$item_id])) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Please choose an item from this order.'];
            }
            $item = $items[$item_id];
            if ($qty === false || $qty < 1 || $qty > $item['returnable_qty']) {
                $conn->rollback();
                return ['ok' => false, 'message' => $item['returnable_qty'] > 0
                    ? 'You can return between 1 and ' . $item['returnable_qty'] . ' of this item.'
                    : 'This item already has a return request for all its units.'];
            }
            $amount = rr_compute_refund($conn, $order, $item_id, $qty);
            $unit_price = (float) $item['unit_price'];

            $photo_path = null;
            if ($has_photo) {
                $upload = save_uploaded_image($photo, __DIR__ . '/../../uploads/returns/', '/uploads/returns/', 'return_', 5 * 1024 * 1024);
                if (!$upload['ok']) {
                    $conn->rollback();
                    return ['ok' => false, 'message' => $upload['error']];
                }
                $photo_path = $upload['url'];
            }

            $stmt = $conn->prepare("INSERT INTO tbl_return_requests
                (order_id, customer_id, order_item_id, quantity, unit_price, requested_amount, reason, details, photo_path, resolution)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iiiiddssss', $order_id, $customer_id, $item_id, $qty, $unit_price, $amount, $reason, $details, $photo_path, $resolution);
            $stmt->execute();
            $request_id = (int) $conn->insert_id;
            $stmt->close();

            $log = $conn->prepare("INSERT INTO activity_logs (customer_id, action, details) VALUES (?, 'Return Requested', ?)");
            $log_details = "Customer submitted return request #$request_id for order #{$order['order_number']}: {$item['product_name']} x$qty ("
                . rr_reason_label($reason) . ', prefers ' . strtolower(rr_resolutions()[$resolution]) . ').';
            $log->bind_param('is', $customer_id, $log_details);
            $log->execute();
            $log->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Your request could not be saved. Please try again.'];
        }

        if ($notify) {
            $cust = $conn->prepare("SELECT name, email FROM customers WHERE id = ?");
            $cust->bind_param('i', $customer_id);
            $cust->execute();
            $c = $cust->get_result()->fetch_assoc() ?: [];
            $cust->close();
            $customer_name = trim((string) ($c['name'] ?? '')) ?: 'A customer';
            $staff_res = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'staff')");
            while ($staff_res && ($staff = $staff_res->fetch_assoc())) {
                notifications_create($conn, [
                    'user_id'         => (int) $staff['id'],
                    'type'            => 'return_requested',
                    'channel'         => 'in_app',
                    'title'           => 'Return / refund request',
                    'message'         => "$customer_name asked to return {$item['product_name']} x$qty from order #{$order['order_number']}: " . rr_reason_label($reason) . '.',
                    'reference_table' => 'tbl_orders',
                    'reference_id'    => $order_id,
                ]);
            }
            notifications_create($conn, [
                'customer_id'     => $customer_id,
                'type'            => 'order_return_update',
                'channel'         => 'in_app',
                'title'           => 'Return request sent',
                'message'         => "We received your return request for {$item['product_name']} x$qty (order #{$order['order_number']}). Our staff will review it.",
                'reference_table' => 'tbl_orders',
                'reference_id'    => $order_id,
            ]);
        }

        return ['ok' => true, 'message' => 'Your return request was sent. We will review it and update you here.', 'request_id' => $request_id];
    }
}

if (!function_exists('rr_transitions')) {
    /** action => [allowed current statuses, new status]. */
    function rr_transitions(): array
    {
        return [
            'approve' => [['pending'], 'approved'],
            'reject'  => [['pending', 'approved'], 'rejected'],
            'receive' => [['approved'], 'received'],
            'inspect' => [['received'], 'inspected'],
            'refund'  => [['inspected'], 'refunded'],
            'replace' => [['inspected'], 'replaced'],
        ];
    }
}

if (!function_exists('rr_process')) {
    /**
     * Moves request $request_id (which must belong to $order) one step. All money, stock and points
     * effects happen in one transaction with the request row locked; the status update is
     * conditional on the expected current status, so a repeat does nothing.
     *
     * $input: note (staff note to the customer), condition (inspect).
     * Returns ['ok' => bool, 'message' => string]. Customer notification is sent after commit.
     */
    function rr_process(mysqli $conn, array $order, int $request_id, string $action, int $actor_id, array $input = [], bool $notify = true): array
    {
        rr_ensure_schema($conn); // DDL first - it would end an open transaction
        $transitions = rr_transitions();
        if (!isset($transitions[$action])) {
            return ['ok' => false, 'message' => 'Unknown return action.'];
        }
        if (in_array($action, ['inspect', 'replace'], true) && (!function_exists('restore_product_stock') || !function_exists('deduct_product_stock'))) {
            return ['ok' => false, 'message' => 'Inventory functions are not loaded.'];
        }
        [$from, $to] = $transitions[$action];
        $order_id = (int) $order['id'];
        $no = (string) $order['order_number'];
        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 1000);

        $role_stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $role_stmt->bind_param('i', $actor_id);
        $role_stmt->execute();
        $actor_role = ucfirst((string) ($role_stmt->get_result()->fetch_assoc()['role'] ?? 'staff'));
        $role_stmt->close();

        $log = static function (string $what, string $details) use ($conn, $actor_id, $actor_role, $order) {
            $details = "[$actor_role] $details";
            $customer_id = (int) $order['customer_id'];
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, customer_id, action, details) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiss', $actor_id, $customer_id, $what, $details);
            $stmt->execute();
            $stmt->close();
        };
        $movement = static function (array $req, string $type, int $qty, int $change, string $why) use ($conn, $actor_id, $order_id) {
            $variant_id = $req['variant_id'] !== null ? (int) $req['variant_id'] : null;
            $product_id = (int) $req['product_id'];
            $name = (string) $req['product_name'];
            $rid = (int) $req['id'];
            $stmt = $conn->prepare("INSERT INTO tbl_return_stock_movements
                (return_request_id, order_id, product_id, variant_id, product_name, movement_type, quantity, stock_change, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iiiissiisi', $rid, $order_id, $product_id, $variant_id, $name, $type, $qty, $change, $why, $actor_id);
            $stmt->execute(); // duplicate key throws -> whole step rolls back
            $stmt->close();
        };

        $customer_msg = null;
        $notify_riders = '';
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT r.*, oi.product_id, oi.variant_id, oi.product_name
                FROM tbl_return_requests r LEFT JOIN tbl_order_items oi ON oi.id = r.order_item_id
                WHERE r.id = ? AND r.order_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $request_id, $order_id);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$req) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'That return request was not found on this order.'];
            }
            if ($req['order_item_id'] === null) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This is an older whole-order request and can no longer be changed here.'];
            }
            if (!in_array($req['status'], $from, true)) {
                $conn->rollback();
                [$cur] = rr_status_label((string) $req['status']);
                return ['ok' => false, 'message' => "This request is \"$cur\" - that step was already done or isn't allowed yet."];
            }
            $qty = (int) $req['quantity'];
            $item_text = "{$req['product_name']} x$qty";
            $ref = "return #$request_id (order #$no, $item_text)";

            $set = "status = ?, handled_by = ?, updated_at = NOW()";
            $types = 'si';
            $params = [$to, $actor_id];
            if ($note !== '') {
                $set .= ", staff_note = ?";
                $types .= 's';
                $params[] = $note;
            }

            switch ($action) {
                case 'approve':
                    // Free rider pickup on the next business day.
                    $pickup = rr_pickup_date(time());
                    $set .= ", approved_at = NOW(), pickup_date = ?";
                    $types .= 's';
                    $params[] = $pickup;
                    $log('Return Approved', "Approved $ref. Rider pickup scheduled for " . rr_day($pickup) . '.');
                    $customer_msg = ['Return approved', "Your return request for $item_text (order #$no) was approved. A rider will pick up the item on " . rr_day($pickup)
                        . ' at no cost to you. Please have it packed and ready. We will process your ' . ($req['resolution'] === 'replacement' ? 'replacement' : 'refund') . ' after it reaches the shop and is checked.'];
                    $notify_riders = "Return pickup for order #$no ($item_text) on " . rr_day($pickup) . '. Open Return pickups to claim it.';
                    break;

                case 'reject':
                    if ($note === '') {
                        $conn->rollback();
                        return ['ok' => false, 'message' => 'Please give the customer a reason for rejecting the return.'];
                    }
                    $set .= ", completed_at = NOW()";
                    $log('Return Rejected', "Rejected $ref. Reason: $note");
                    $customer_msg = ['Return request declined', "Your return request for $item_text (order #$no) was declined.\n\nReason: $note"];
                    break;

                case 'receive':
                    // Physical receipt only - stock is NOT changed until the item is inspected.
                    $set .= ", received_at = NOW(), received_by = ?";
                    $types .= 'i';
                    $params[] = $actor_id;
                    $log('Return Item Received', "Received the returned item for $ref" . (!empty($req['picked_up_at']) ? ' (dropped off by the pickup rider)' : '') . '. Awaiting inspection; stock unchanged.');
                    $window = rr_refund_window(date('Y-m-d H:i:s'));
                    $customer_msg = ['Returned item received', "Your returned $item_text (order #$no) has reached the shop. Our staff will check it next."
                        . ($req['resolution'] === 'refund' ? ' Your refund will be released within ' . RR_REFUND_MIN_DAYS . '-' . RR_REFUND_MAX_DAYS . ' business days (between ' . rr_day($window['from']) . ' and ' . rr_day($window['to']) . ').' : '')];
                    break;

                case 'inspect':
                    $condition = (string) ($input['condition'] ?? '');
                    $conditions = rr_conditions();
                    if (!isset($conditions[$condition])) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => 'Please choose the condition of the returned item.'];
                    }
                    if ((int) $req['stock_processed'] === 1) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => 'Stock for this return was already handled.'];
                    }
                    [$cond_label, $sellable, $type] = $conditions[$condition];
                    $variant_id = $req['variant_id'] !== null ? (int) $req['variant_id'] : null;
                    if ($sellable) {
                        if (!restore_product_stock((int) $req['product_id'], $variant_id, $qty)) {
                            throw new RuntimeException('The product for this item no longer exists, so it could not be put back in stock.');
                        }
                        $movement($req, $type, $qty, $qty, 'Resellable customer return');
                    } else {
                        $movement($req, $type, $qty, 0, $cond_label . ' customer return - non-sellable');
                    }
                    $inspection_note = mb_substr(trim((string) ($input['inspection_note'] ?? '')), 0, 1000);
                    $set .= ", item_condition = ?, inspection_note = ?, stock_processed = 1, restocked = ?, inspected_at = NOW(), inspected_by = ?";
                    $types .= 'ssii';
                    array_push($params, $condition, $inspection_note, $sellable ? 1 : 0, $actor_id);
                    $log('Return Inspected', "Inspected $ref as $cond_label.");
                    $log('Inventory ' . $type, "$type order #$no, {$req['product_name']}, quantity $qty, available stock "
                        . ($sellable ? "+$qty (resellable customer return)." : '+0 (non-sellable).'));
                    break;

                case 'refund':
                    $amount = rr_compute_refund($conn, $order, (int) $req['order_item_id'], $qty, $request_id);
                    if ($amount <= 0) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => 'Nothing is left to refund on this order.'];
                    }
                    // Points for the same share of the goods, never more than the order still has.
                    $pts = $conn->prepare("SELECT COALESCE(SUM(points_earned), 0) AS p FROM loyalty_transactions WHERE order_id = ?");
                    $pts->bind_param('i', $order_id);
                    $pts->execute();
                    $earned = (float) ($pts->get_result()->fetch_assoc()['p'] ?? 0);
                    $pts->close();
                    $rev = $conn->prepare("SELECT COALESCE(-SUM(points_earned), 0) AS p FROM loyalty_transactions WHERE order_id IS NULL AND product_name = ?");
                    $rev_label = 'Refund reversal (Order #' . $order_id . ')';
                    $rev->bind_param('s', $rev_label);
                    $rev->execute();
                    $reversed_so_far = (float) ($rev->get_result()->fetch_assoc()['p'] ?? 0);
                    $rev->close();
                    $base = rr_refund_base($order);
                    $points = $base > 0 ? round($earned * $amount / $base, 2) : 0.0;
                    $points = max(0.0, min($points, round($earned - $reversed_so_far, 2)));
                    if ($points > 0) {
                        $customer_id = (int) $order['customer_id'];
                        $neg = -$points;
                        $ins = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned, order_id) VALUES (?, ?, ?, 0, ?, NULL)");
                        $ins->bind_param('iisd', $customer_id, $actor_id, $rev_label, $neg);
                        $ins->execute();
                        $ins->close();
                        notifications_sync_customer_loyalty_points($conn, $customer_id);
                    }
                    $set .= ", refund_amount = ?, points_reversed = ?, completed_at = NOW()";
                    $types .= 'dd';
                    array_push($params, $amount, $points);
                    $log('Return Refunded', "Refunded PHP " . number_format($amount, 2) . " for $ref."
                        . ($points > 0 ? ' Reversed ' . number_format($points, 2) . ' loyalty points.' : ''));
                    $customer_msg = ['Refund processed', "Your refund of PHP " . number_format($amount, 2) . " for $item_text (order #$no) has been processed."
                        . ($points > 0 ? ' ' . number_format($points, 2) . ' loyalty points earned on this item were removed.' : '')
                        . ($note !== '' ? "\n\nNote from the shop: $note" : '')];
                    break;

                case 'replace':
                    if ((int) $req['replacement_issued'] === 1) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => 'A replacement was already issued for this request.'];
                    }
                    $variant_id = $req['variant_id'] !== null ? (int) $req['variant_id'] : null;
                    if (!deduct_product_stock((int) $req['product_id'], $variant_id, $qty)) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => "Not enough stock to send a replacement of $qty. Restock the product or process a refund instead."];
                    }
                    $movement($req, 'REPLACEMENT-OUT', $qty, -$qty, 'Replacement for customer return');
                    $set .= ", replacement_issued = 1, completed_at = NOW()";
                    $log('Return Replaced', "Issued a replacement for $ref.");
                    $log('Inventory REPLACEMENT-OUT', "REPLACEMENT-OUT order #$no, {$req['product_name']}, quantity $qty, available stock -$qty.");
                    $customer_msg = ['Replacement processed', "A replacement for $item_text (order #$no) has been prepared for you."
                        . ($note !== '' ? "\n\nNote from the shop: $note" : '')];
                    break;
            }

            $placeholders = implode(',', array_fill(0, count($from), '?'));
            $sql = "UPDATE tbl_return_requests SET $set WHERE id = ? AND status IN ($placeholders)";
            $types .= 'i' . str_repeat('s', count($from));
            array_push($params, $request_id, ...$from);
            $upd = $conn->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();
            $changed = $upd->affected_rows;
            $upd->close();
            if ($changed !== 1) {
                throw new RuntimeException('This request was changed by someone else. Please reload.');
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'The return could not be updated. Nothing was changed; please try again.';
            if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
                $msg = 'Stock for this return was already handled.';
            }
            return ['ok' => false, 'message' => $msg];
        }

        if ($notify && $customer_msg) {
            $email = '';
            $c = $conn->prepare("SELECT email FROM customers WHERE id = ?");
            $cid = (int) $order['customer_id'];
            $c->bind_param('i', $cid);
            $c->execute();
            $email = (string) ($c->get_result()->fetch_assoc()['email'] ?? '');
            $c->close();
            notifications_create($conn, [
                'customer_id' => $cid, 'type' => 'order_return_update', 'channel' => 'both',
                'title' => $customer_msg[0], 'message' => $customer_msg[1],
                'reference_table' => 'tbl_orders', 'reference_id' => $order_id, 'email_to' => $email,
            ]);
        }
        if ($notify && $notify_riders !== '') {
            rr_notify_riders($conn, $notify_riders, $order_id);
        }
        return ['ok' => true, 'message' => 'Return #' . $request_id . ': ' . rr_status_label($to)[0] . '.'];
    }
}

if (!function_exists('rr_movements')) {
    /** Stock movements recorded for one request. */
    function rr_movements(mysqli $conn, int $request_id): array
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT * FROM tbl_return_stock_movements WHERE return_request_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('rr_pending_count')) {
    /** Requests still waiting for staff - the "Return / Refund" tab badge. */
    function rr_pending_count(mysqli $conn): int
    {
        rr_ensure_schema($conn);
        $res = $conn->query("SELECT COUNT(*) AS n FROM tbl_return_requests WHERE status IN ('pending', 'approved', 'received', 'inspected')");
        return $res ? (int) ($res->fetch_assoc()['n'] ?? 0) : 0;
    }
}

if (!function_exists('rr_notify_riders')) {
    /** In-app note to every rider account that a return pickup is waiting to be claimed. */
    function rr_notify_riders(mysqli $conn, string $message, int $order_id): void
    {
        try {
            $res = $conn->query("SELECT id FROM users WHERE role = 'rider'");
            while ($res && ($u = $res->fetch_assoc())) {
                notifications_create($conn, [
                    'user_id' => (int) $u['id'], 'type' => 'return_pickup', 'channel' => 'in_app',
                    'title' => 'New return pickup', 'message' => $message,
                    'reference_table' => 'tbl_orders', 'reference_id' => $order_id,
                ]);
            }
        } catch (Throwable $e) {
            error_log('Return pickup rider notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rr_rider_pickups')) {
    /**
     * Return pickups a rider can see: approved requests nobody has claimed yet, plus the ones this
     * rider claimed and hasn't dropped off. Pickup address: the order's delivery address, else the
     * customer's default saved address, else their profile address.
     */
    function rr_rider_pickups(mysqli $conn, int $rider_user_id): array
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT r.id, r.order_id, r.quantity, r.resolution, r.pickup_date, r.pickup_rider_id, r.picked_up_at,
                oi.product_name, oi.variant_size, o.order_number,
                c.name AS customer_name,
                COALESCE(NULLIF(o.delivery_address, ''), (SELECT a.full_address FROM customer_addresses a WHERE a.customer_id = c.id ORDER BY a.is_default DESC, a.id DESC LIMIT 1), c.address) AS address,
                COALESCE(NULLIF(o.delivery_phone, ''), NULLIF(c.phone, '')) AS phone,
                IF(NULLIF(o.delivery_address, '') IS NULL, NULL, o.delivery_latitude) AS latitude,
                IF(NULLIF(o.delivery_address, '') IS NULL, NULL, o.delivery_longitude) AS longitude
            FROM tbl_return_requests r
            JOIN tbl_orders o ON o.id = r.order_id
            JOIN customers c ON c.id = r.customer_id
            LEFT JOIN tbl_order_items oi ON oi.id = r.order_item_id
            WHERE r.status = 'approved' AND r.order_item_id IS NOT NULL AND r.pickup_date IS NOT NULL
              AND (r.pickup_rider_id IS NULL OR r.pickup_rider_id = ?)
            ORDER BY (r.pickup_rider_id = ?) DESC, r.pickup_date ASC, r.id ASC");
        $stmt->bind_param('ii', $rider_user_id, $rider_user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['mine'] = (int) $r['pickup_rider_id'] === $rider_user_id;
            $r['picked_up'] = !empty($r['picked_up_at']);
            $r['pickup_label'] = $r['pickup_date'] ? rr_day((string) $r['pickup_date']) : '';
            $r['due'] = $r['pickup_date'] <= date('Y-m-d');
            unset($r['pickup_rider_id']);
        }
        return $rows;
    }
}

if (!function_exists('rr_rider_action')) {
    /**
     * A rider's step on a return pickup: claim | release | picked_up | dropped_off.
     * Each is a conditional update (only the claiming rider, only from the right step), so two
     * riders can't claim the same pickup and a double tap does nothing. 'dropped_off' is the
     * request's 'receive' step (rr_process), which tells the customer when their refund comes.
     * Returns ['ok' => bool, 'message' => string].
     */
    function rr_rider_action(mysqli $conn, int $rider_user_id, int $request_id, string $action): array
    {
        rr_ensure_schema($conn);
        $sql = [
            'claim'     => "UPDATE tbl_return_requests SET pickup_rider_id = ?, pickup_claimed_at = NOW(), updated_at = NOW()
                            WHERE id = ? AND status = 'approved' AND pickup_date IS NOT NULL AND pickup_rider_id IS NULL",
            'release'   => "UPDATE tbl_return_requests SET pickup_rider_id = NULL, pickup_claimed_at = NULL, updated_at = NOW()
                            WHERE pickup_rider_id = ? AND id = ? AND status = 'approved' AND picked_up_at IS NULL",
            'picked_up' => "UPDATE tbl_return_requests SET picked_up_at = NOW(), updated_at = NOW()
                            WHERE pickup_rider_id = ? AND id = ? AND status = 'approved' AND picked_up_at IS NULL",
        ];
        $done = [
            'claim'     => ['Pickup claimed. Collect the item on the pickup date.', 'Another rider already claimed this pickup, or it is no longer open.'],
            'release'   => ['Pickup released. Another rider can take it now.', 'You can only release a pickup you claimed and have not collected yet.'],
            'picked_up' => ['Marked as picked up. Bring the item to the shop.', 'This pickup is not yours, or it was already picked up.'],
        ];
        if (isset($sql[$action])) {
            $stmt = $conn->prepare($sql[$action]);
            $stmt->bind_param('ii', $rider_user_id, $request_id);
            $stmt->execute();
            $ok = $stmt->affected_rows === 1;
            $stmt->close();
            return ['ok' => $ok, 'message' => $done[$action][$ok ? 0 : 1]];
        }
        if ($action !== 'dropped_off') {
            return ['ok' => false, 'message' => 'Unknown pickup action.'];
        }

        $stmt = $conn->prepare("SELECT r.status, r.pickup_rider_id, r.picked_up_at, o.* FROM tbl_return_requests r JOIN tbl_orders o ON o.id = r.order_id WHERE r.id = ?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int) $row['pickup_rider_id'] !== $rider_user_id) {
            return ['ok' => false, 'message' => 'This pickup is not assigned to you.'];
        }
        if (empty($row['picked_up_at'])) {
            return ['ok' => false, 'message' => 'Mark the item as picked up from the customer first.'];
        }
        $result = rr_process($conn, $row, $request_id, 'receive', $rider_user_id);
        return $result['ok'] ? ['ok' => true, 'message' => 'Dropped off at the shop. Thank you!'] : $result;
    }
}
