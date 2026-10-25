<?php
/**
 * Number badges on the admin and staff sidebar links for sections that had none (Reviews, E-Bike).
 * Orders, Remittance and Messages keep their own badges (pending orders not yet opened, pending
 * remittances, unread messages), which api/notifications_poll.php also refreshes live.
 *
 * A notification (notifications table) belongs to a section by what it points at (reference_table).
 * The count = that admin/staff member's unread notifications for the section; it stays until they
 * open the section's page, which marks those notifications read for them only (an Order Details
 * page clears just that order's). Live: api/notifications_poll.php -> assets/js/toast.js.
 */

/** Sections whose sidebar link already has its own number badge. */
const NAV_NOTIF_OWN_BADGE = ['orders', 'remittance', 'messages'];
require_once __DIR__ . '/notification_helper.php';

const NAV_NOTIF_SECTIONS = [
    // section => [reference tables, pages that count as "viewed" (first one = the sidebar link)]
    'orders'     => [['tbl_orders'], ['orders.php', 'order_details.php']],
    'messages'   => [['tbl_messages'], ['messages.php']],
    'remittance' => [['tbl_rider_remittances'], ['remittance_management.php']],
    'reviews'    => [['tbl_product_reviews'], ['reviews.php']],
    'ebike'      => [['riders'], ['ebike_maintenance.php']],
];

/** The section a sidebar link (or the current page) belongs to, or null. */
function nav_notif_section_for(string $file): ?string
{
    $file = basename(strtok($file, '?#'));
    foreach (NAV_NOTIF_SECTIONS as $section => [, $pages]) {
        if (in_array($file, $pages, true)) {
            return $section;
        }
    }
    return null;
}

/** Unread notification counts per section for one admin/staff account. */
function nav_notif_counts(mysqli $conn, int $user_id): array
{
    $counts = array_fill_keys(array_keys(NAV_NOTIF_SECTIONS), 0);
    if ($user_id <= 0) {
        return $counts;
    }
    notifications_ensure_schema($conn);
    [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
    $stmt = $conn->prepare("SELECT reference_table, COUNT(*) AS n FROM notifications WHERE $scope AND is_read = 0 GROUP BY reference_table");
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        foreach (NAV_NOTIF_SECTIONS as $section => [$tables]) {
            if (in_array((string) $row['reference_table'], $tables, true)) {
                $counts[$section] += (int) $row['n'];
            }
        }
    }
    $stmt->close();
    return $counts;
}

/** Marks this account's notifications for a section as read (all of them, or one record's). */
function nav_notif_mark_seen(mysqli $conn, int $user_id, string $section, int $reference_id = 0): void
{
    if ($user_id <= 0 || !isset(NAV_NOTIF_SECTIONS[$section])) {
        return;
    }
    notifications_ensure_schema($conn);
    [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
    $tables = NAV_NOTIF_SECTIONS[$section][0];
    $in = implode(',', array_fill(0, count($tables), '?'));
    $sql = "UPDATE notifications SET is_read = 1 WHERE $scope AND is_read = 0 AND reference_table IN ($in)";
    $types .= str_repeat('s', count($tables));
    $args = array_merge($args, $tables);
    if ($reference_id > 0) {
        $sql .= ' AND reference_id = ?';
        $types .= 'i';
        $args[] = $reference_id;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $stmt->close();
}

/**
 * Called by the admin and staff sidebars: clears the dot of the page being viewed, then returns the
 * counts for the rest. Order Details clears only that order's notifications.
 */
function nav_notif_for_sidebar(mysqli $conn): array
{
    $payload = function_exists('verifyJWT') ? verifyJWT((string) getJWTFromCookie()) : null;
    $user_id = is_array($payload) ? (int) ($payload['user_id'] ?? 0) : 0;
    $page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $section = nav_notif_section_for($page);
    if ($section !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        nav_notif_mark_seen($conn, $user_id, $section, $page === 'order_details.php' ? (int) ($_GET['id'] ?? 0) : 0);
    }
    return nav_notif_counts($conn, $user_id);
}

/** The number badge for a sidebar link without its own (always printed, hidden at 0). */
function nav_notif_badge(string $href, array $counts): string
{
    $section = nav_notif_section_for($href);
    if ($section === null || in_array($section, NAV_NOTIF_OWN_BADGE, true)) {
        return '';
    }
    $n = (int) ($counts[$section] ?? 0);
    return '<span class="app-nav-badge" data-nav-count="' . $section . '"' . ($n > 0 ? '' : ' style="display: none;"') . '>' . ($n > 99 ? '99+' : $n) . '</span>';
}
