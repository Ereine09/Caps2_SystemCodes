<?php
/**
 * "Sales" = money the shop has actually received. One definition used by the admin/staff
 * dashboards, Analytics and the admin app, so every screen shows the same number.
 *
 *   COD          -> counted when staff accept the rider's remittance (payment_settled = 1)
 *   GCash / Bank -> counted when PayMongo verifies it, or staff verify a manual transfer (payment_settled = 1)
 *   Pay at shop  -> counted when staff confirm the cash at the counter (or the pickup is completed)
 *   Cancelled and refunded orders never count.
 *
 * Each collected order is dated by `tbl_orders.paid_at` (the day the money came in), not by
 * the day it was ordered: a COD order placed Monday and remitted Wednesday is Wednesday's sale.
 */

/** SQL condition: the order's money has been received. $a = table alias ('' for none). */
function sales_collected_sql(string $a = 'o'): string
{
    $p = $a !== '' ? $a . '.' : '';
    return "({$p}order_status NOT IN ('cancelled','refunded') AND ("
        . "({$p}payment_method IN ('cod','gcash','bank') AND {$p}payment_settled = 1)"
        . " OR ({$p}payment_method NOT IN ('cod','gcash','bank') AND ({$p}payment_settled = 1 OR {$p}order_status = 'completed'))))";
}

/** SQL condition: a live order whose money has NOT been received yet. */
function sales_uncollected_sql(string $a = 'o'): string
{
    $p = $a !== '' ? $a . '.' : '';
    return "({$p}order_status NOT IN ('cancelled','refunded') AND NOT " . sales_collected_sql($a) . ')';
}

/**
 * Adds tbl_orders.paid_at (once, with a backfill) and stamps any collected order that has no
 * paid_at yet. The remittance and payment-approval flows set paid_at themselves; this catches
 * Pay-at-shop orders, which can be completed from several screens.
 */
function sales_ensure_paid_at(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $col = $conn->query("SHOW COLUMNS FROM `tbl_orders` LIKE 'paid_at'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_orders` ADD COLUMN `paid_at` DATETIME NULL DEFAULT NULL AFTER `payment_settled`");
        // COD: the day the shop received the remittance.
        $conn->query(
            "UPDATE tbl_orders o
             JOIN tbl_rider_remittance_items i ON i.order_id = o.id
             JOIN tbl_rider_remittances r ON r.id = i.remittance_id AND r.status = 'approved'
             SET o.paid_at = COALESCE(r.received_at, r.processed_at, r.requested_at)
             WHERE o.payment_method = 'cod' AND o.payment_settled = 1 AND o.paid_at IS NULL"
        );
    }

    $conn->query(
        "UPDATE tbl_orders o SET o.paid_at = COALESCE(o.updated_at, o.created_at)
         WHERE o.paid_at IS NULL AND " . sales_collected_sql('o')
    );
}

/**
 * Sales figures for dashboards: total collected, collected today, and money still to be collected
 * (e.g. COD cash with riders, GCash payments not yet approved).
 */
function sales_summary(mysqli $conn): array
{
    sales_ensure_paid_at($conn);
    $row = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN " . sales_collected_sql('o') . " THEN o.total END), 0) AS total_sales,
            COALESCE(SUM(CASE WHEN " . sales_collected_sql('o') . " AND DATE(o.paid_at) = CURDATE() THEN o.total END), 0) AS sales_today,
            COALESCE(SUM(CASE WHEN " . sales_uncollected_sql('o') . " THEN o.total END), 0) AS uncollected
         FROM tbl_orders o"
    )->fetch_assoc() ?: [];
    return [
        'total_sales' => (float) ($row['total_sales'] ?? 0),
        'sales_today' => (float) ($row['sales_today'] ?? 0),
        'uncollected' => (float) ($row['uncollected'] ?? 0),
    ];
}

/** Collected sales between two dates (inclusive, Y-m-d), by the day the money came in. */
function sales_between(mysqli $conn, string $from, string $to): float
{
    sales_ensure_paid_at($conn);
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(o.total), 0) AS s FROM tbl_orders o
         WHERE " . sales_collected_sql('o') . " AND DATE(o.paid_at) BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $s = (float) ($stmt->get_result()->fetch_assoc()['s'] ?? 0);
    $stmt->close();
    return $s;
}

/** Collected sales per day (Y-m-d => amount) between two dates, for trend charts. */
function sales_per_day(mysqli $conn, string $from, string $to): array
{
    sales_ensure_paid_at($conn);
    $stmt = $conn->prepare(
        "SELECT DATE(o.paid_at) AS d, COALESCE(SUM(o.total), 0) AS s FROM tbl_orders o
         WHERE " . sales_collected_sql('o') . " AND DATE(o.paid_at) BETWEEN ? AND ?
         GROUP BY d"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[$r['d']] = (float) $r['s'];
    }
    $stmt->close();
    return $out;
}
