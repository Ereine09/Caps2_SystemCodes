<?php
/**
 * Data behind the four Analytics & Reports tabs. Shared by the on-screen tabs
 * (modules/admin/analytics.php) and their printable soft copies
 * (modules/admin/analytics_report.php), so both always show the same figures.
 *
 * $date_from / $date_to must already be clean Y-m-d strings (clean_date()).
 */
require_once __DIR__ . '/ml_helper.php';

/** Points earned and used in the selected period. */
function analytics_points_totals(mysqli $conn, string $date_from, string $date_to): array
{
    $earned = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
    $redeemed = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE DATE(redeemed_at) BETWEEN '$date_from' AND '$date_to'"))['total'] ?? 0);
    return ['earned' => $earned, 'redeemed' => $redeemed];
}

/** Points earned and used on each day of the selected period that had activity, newest first. */
function analytics_daily_in_period(mysqli $conn, string $date_from, string $date_to): array
{
    $summary_query = "
        SELECT activity_date, SUM(earned) as earned, SUM(redeemed) as redeemed
        FROM (
            SELECT DATE(created_at) as activity_date, points_earned as earned, 0 as redeemed FROM loyalty_transactions
            UNION ALL
            SELECT DATE(redeemed_at) as activity_date, 0 as earned, points_used as redeemed FROM reward_redemptions
        ) as combined WHERE activity_date BETWEEN '$date_from' AND '$date_to'
        GROUP BY activity_date ORDER BY activity_date DESC";
    return mysqli_query($conn, $summary_query)->fetch_all(MYSQLI_ASSOC);
}

/** Usable points every customer holds right now (not date-filtered). */
function analytics_points_outstanding(mysqli $conn): float
{
    return (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(loyalty_points) as total FROM customers"))['total'] ?? 0);
}

/**
 * Points earned and used per day for the last 14 days (fixed lookback, ignores the filter).
 * Returns ['labels' => ['Sep 25', ...], 'earned' => [...], 'redeemed' => [...]], oldest first.
 */
function analytics_last_14_days(mysqli $conn): array
{
    $labels = $earned = $redeemed = [];
    for ($i = 13; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('M d', strtotime($date));
        $earned[] = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE DATE(created_at) = '$date'"))['total'] ?? 0);
        $redeemed[] = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE DATE(redeemed_at) = '$date'"))['total'] ?? 0);
    }
    return ['labels' => $labels, 'earned' => $earned, 'redeemed' => $redeemed];
}

/**
 * Every customer classified by K-Means (getMLCustomerClassification()). Returns
 * ['totals' => [group => count], 'top' => the top $per_group_limit of each group by usable
 * points (all of them when $per_group_limit is 0), sorted Loyal, Frequent, Regular, At Risk, New].
 */
function analytics_customer_groups(mysqli $conn, int $per_group_limit = 5): array
{
    $all_points = [];
    $pts_res = mysqli_query($conn, "SELECT loyalty_points FROM customers");
    while ($p_row = mysqli_fetch_assoc($pts_res)) { $all_points[] = (float)$p_row['loyalty_points']; }

    $customers_query = "
        SELECT c.id, c.name, c.email, c.loyalty_points,
               MAX(lt.created_at) as last_activity,
               COALESCE(purchase_summary.purchased_count, 0) as purchased_count
        FROM customers c
        LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
        LEFT JOIN (
            SELECT o.customer_id, SUM(oi.quantity) as purchased_count
            FROM tbl_orders o
            INNER JOIN tbl_order_items oi ON oi.order_id = o.id
            WHERE o.order_status <> 'cancelled'
            GROUP BY o.customer_id
        ) purchase_summary ON purchase_summary.customer_id = c.id
        GROUP BY c.id
        ORDER BY c.loyalty_points DESC";
    // Classify every customer, then keep the top few of each group so Frequent and
    // Regular customers show up too (a plain top-10 by points is all Loyal / At Risk).
    $totals = [];
    $top = [];
    $res_top = mysqli_query($conn, $customers_query);
    while ($row = mysqli_fetch_assoc($res_top)) {
        $ml = getMLCustomerClassification($row['loyalty_points'], $row['purchased_count'], $row['last_activity'], $all_points);
        $row['ml_label'] = $ml['label'];
        $row['ml_class'] = $ml['class'];
        $totals[$row['ml_label']] = ($totals[$row['ml_label']] ?? 0) + 1;
        if ($per_group_limit === 0 || $totals[$row['ml_label']] <= $per_group_limit) {
            $top[] = $row;
        }
    }

    $priority = analytics_group_priority();
    usort($top, function ($a, $b) use ($priority) {
        $aPriority = $priority[$a['ml_label']] ?? 99;
        $bPriority = $priority[$b['ml_label']] ?? 99;
        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }
        return ((float) ($b['loyalty_points'] ?? 0)) <=> ((float) ($a['loyalty_points'] ?? 0));
    });
    return ['totals' => $totals, 'top' => $top];
}

/** Display order of the customer groups. */
function analytics_group_priority(): array
{
    return ['Loyal' => 1, 'Frequent' => 2, 'Regular' => 3, 'At Risk' => 4, 'New' => 5];
}

/** Top 5 staff by loyalty points awarded in the selected period. */
function analytics_top_staff(mysqli $conn, string $date_from, string $date_to): array
{
    $staff_query = "
        SELECT u.username, u.first_name, u.last_name,
               COUNT(DISTINCT lt.id) as transactions_count,
               SUM(lt.points_earned) as total_points_awarded,
               COUNT(DISTINCT rr.id) as redemptions_processed
        FROM users u
        LEFT JOIN loyalty_transactions lt ON u.id = lt.user_id
            AND DATE(lt.created_at) BETWEEN '$date_from' AND '$date_to'
        LEFT JOIN reward_redemptions rr ON u.id = rr.user_id
            AND DATE(rr.redeemed_at) BETWEEN '$date_from' AND '$date_to'
        WHERE u.role = 'staff'
        GROUP BY u.id
        ORDER BY total_points_awarded DESC LIMIT 5";
    $rows = [];
    $res_staff = mysqli_query($conn, $staff_query);
    while ($s_row = mysqli_fetch_assoc($res_staff)) { $rows[] = $s_row; }
    return $rows;
}
