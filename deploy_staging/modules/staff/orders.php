<?php
/**
 * Staff — Order management.
 * Same page as modules/admin/orders.php (search/filters, status tabs, Items Purchased
 * collapse, live no-reload updates, Abandoned Carts tab) — only the auth gate and the
 * sidebar/link paths differ, since staff has its own portal and no admin_viewed_at tracking.
 */
require_once __DIR__ . '/../shared/staff_auth.php';                    // config + JWT helper + verify + role === 'staff'
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';
require_once __DIR__ . '/../../app/helpers/delivery_message_helper.php';
require_once __DIR__ . '/../admin/qr_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

$payload = require_staff();

/** Headline order numbers shown in the four summary cards (also returned to AJAX callers). */
function orders_fetch_summary(mysqli $conn): array
{
    $row = $conn->query(
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
                COALESCE(SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders
         FROM tbl_orders"
    )->fetch_assoc();
    return $row ?: ['total_orders' => 0, 'total_sales' => 0, 'pending_orders' => 0, 'completed_orders' => 0];
}

$statuses = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'ready_for_pickup' => 'Ready for Pickup',
    'out_for_delivery' => 'Out for Delivery',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'reviews' => 'Reviews',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

// Abandoned-cart reminder (moved here from the Customers page). Reuses notifications_create() (in-app + email);
// the cooldown is read from the existing `notifications` table so nobody gets the same reminder twice in 48 hours.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_cart_reminder'])) {
    csrf_require();
    $reminder_customer_id = (int) $_POST['send_cart_reminder'];
    $cart_msg = ''; $cart_ok = false;

    $cooldown_check = $conn->prepare(
        "SELECT id FROM notifications
         WHERE type = 'cart_abandoned_reminder' AND customer_id = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
         LIMIT 1"
    );
    $cooldown_check->bind_param('i', $reminder_customer_id);
    $cooldown_check->execute();
    $already_reminded = $cooldown_check->get_result()->fetch_assoc();
    $cooldown_check->close();

    if ($already_reminded) {
        $cart_msg = 'This customer was already sent a cart reminder recently.';
    } else {
        $cart_stmt = $conn->prepare(
            "SELECT cu.name, cu.email, GROUP_CONCAT(p.name SEPARATOR ', ') AS items_summary
             FROM tbl_cart c
             JOIN customers cu ON cu.id = c.customer_id
             JOIN tbl_product_inventory p ON p.id = c.product_id
             WHERE c.customer_id = ?
             GROUP BY c.customer_id"
        );
        $cart_stmt->bind_param('i', $reminder_customer_id);
        $cart_stmt->execute();
        $cart_row = $cart_stmt->get_result()->fetch_assoc();
        $cart_stmt->close();

        if (!$cart_row) {
            $cart_msg = 'This customer no longer has items in their cart.';
        } else {
            $cart_ok = (bool) notifications_create($conn, [
                'customer_id'     => $reminder_customer_id,
                'type'            => 'cart_abandoned_reminder',
                'channel'         => 'both',
                'title'           => 'You left something in your cart!',
                'message'         => "Hi " . $cart_row['name'] . "! You still have items waiting in your cart (" . $cart_row['items_summary'] . "). Complete your order before they sell out!",
                'reference_table' => 'tbl_cart',
                'reference_id'    => $reminder_customer_id,
                'email_to'        => $cart_row['email'],
            ]);
            $cart_msg = $cart_ok ? 'Cart reminder sent to ' . $cart_row['name'] . '.' : 'Failed to send cart reminder.';
        }
    }

    if (is_ajax_request()) {
        if ($cart_ok) { ajax_json(['success' => true, 'message' => $cart_msg, 'customer_id' => $reminder_customer_id]); }
        ajax_fail($cart_msg, 422);
    }
    $_SESSION['cart_msg'] = $cart_msg;
    $_SESSION['cart_msg_type'] = $cart_ok ? 'success' : 'error';
    header('Location: orders.php#abandoned-carts');
    exit();
}

// Handle Order Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['order_status'])) {
    $order_id = (int) $_POST['order_id'];
    $new_order_status = $_POST['order_status'];
    $allowed_statuses = array_keys($statuses);

    if (in_array($new_order_status, $allowed_statuses, true) && $order_id > 0) {
        // Load previous order data
        $prev_stmt = $conn->prepare(
            "SELECT o.order_status, o.order_number, o.customer_id, c.email, c.name
             FROM tbl_orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.id = ?"
        );
        $prev_stmt->bind_param('i', $order_id);
        $prev_stmt->execute();
        $prev = $prev_stmt->get_result()->fetch_assoc();
        $prev_stmt->close();

        if ($prev) {
            $old_order_status = (string) ($prev['order_status'] ?? '');
            if ($old_order_status === 'cancelled' && $new_order_status === 'completed') {
                if (is_ajax_request()) {
                    ajax_fail('A cancelled order cannot be marked as completed.', 422);
                }
                header('Location: orders.php?error=cancelled_order');
                exit();
            }

            $conn->begin_transaction();
            $award_result = [];
            try {
                $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = ? WHERE id = ?");
                $stmt->bind_param('si', $new_order_status, $order_id);
                $stmt->execute();
                $stmt->close();
                if (in_array($new_order_status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed'], true)) {
                    // Deduct product stock the moment the order moves into fulfillment
                    // (normally "Processing"). Safe no-op if a GCash/bank "Approve
                    // Payment" action (or the COD auto-confirm job) already deducted it.
                    $stock_result = deduct_order_stock_once($conn, $order_id);
                    if (!$stock_result['ok']) {
                        throw new Exception($stock_result['error'] ?? 'Insufficient stock.');
                    }
                    $award_result = loyalty_award_completed_order($conn, $order_id);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log("Error completing order $order_id: " . $e->getMessage());
                if (is_ajax_request()) {
                    // Business messages (e.g. "Insufficient stock") are thrown as plain Exceptions and are
                    // safe to show; database/engine errors are replaced by a generic message.
                    $safe = (get_class($e) === 'Exception') ? $e->getMessage() : 'The order could not be updated. Please try again.';
                    ajax_fail($safe, 422);
                }
                header('Location: orders.php?error=status_update&detail=' . urlencode($e->getMessage()));
                exit();
            }

            // Automatic "DPS DELIVERY!" message when a delivery order actually moves into To Receive.
            if ($old_order_status !== $new_order_status && $new_order_status === 'to_receive') {
                delivery_message_send_on_to_receive($conn, $order_id);
            }

            // Notify the customer once when the order status changes.
            try {
                if ($old_order_status !== $new_order_status && (int) ($prev['customer_id'] ?? 0) > 0) {
                    $order_number = $prev['order_number'] ?? ('#' . $order_id);
                    $customer_name = $prev['name'] ?? 'Customer';
                    $customer_email = $prev['email'];
                    $new_status_label = $statuses[$new_order_status] ?? $new_order_status;

                    $title = "Order {$order_number} status update";
                    $message = "Hi {$customer_name},\n\nYour order {$order_number} has been updated to: {$new_status_label}.\n\nIf you have any questions, please contact us.\n";
                    if ($new_order_status === 'completed' && (float) ($award_result['points'] ?? 0) > 0) {
                        $message .= "\nLoyalty Points Earned: " . notifications_format_points((float) $award_result['points'])
                            . "\nCurrent Loyalty Points Balance: " . notifications_format_points((float) ($award_result['balance'] ?? 0));
                    }

                    notifications_create($conn, [
                        'user_id' => NULL,
                        'customer_id' => (int) ($prev['customer_id'] ?? 0),
                        'type' => 'order_status_update',
                        'channel' => 'both',
                        'title' => $title,
                        'message' => $message,
                        'reference_table' => 'tbl_orders',
                        'reference_id' => $order_id,
                        'email_to' => $customer_email,
                    ]);
                }

                // If confirmed, send payment confirmation email
                if ($new_order_status === 'confirmed' && $old_order_status !== 'confirmed') {
                    send_payment_confirmation_email($order_id, $prev['email'], $prev['name']);
                }
            } catch (Exception $e) {
                error_log("Error sending order status email for order $order_id: " . $e->getMessage());
            }
        } elseif (is_ajax_request()) {
            ajax_fail('That order could not be found.', 404);
        }

        // AJAX: answer with JSON and let the page patch just this row + the counters (no reload).
        if (is_ajax_request()) {
            $label = $statuses[$new_order_status] ?? $new_order_status;
            $number = (string) ($prev['order_number'] ?? ('#' . $order_id));
            ajax_json([
                'success'    => true,
                'message'    => $old_order_status === $new_order_status
                    ? "Order {$number} is already {$label}."
                    : "Order {$number} updated to {$label}.",
                'order_id'   => $order_id,
                'old_status' => $old_order_status,
                'new_status' => $new_order_status,
                'summary'    => orders_fetch_summary($conn),
            ]);
        }

        // Return to the same search/filter/page staff was on.
        header('Location: ' . orders_page_url(max(1, (int) ($_GET['page'] ?? 1))));
        exit();
    } elseif (is_ajax_request()) {
        ajax_fail('Invalid order or status.', 422);
    }
}

// Order Metrics
$order_summary = orders_fetch_summary($conn);

// Abandoned carts: real tbl_cart rows whose last activity (updated_at, else created_at) is 24+ hours old and that were
// never checked out. Read-only - nothing here places an order or changes a cart.
$abandoned_carts = [];
$carts_res = $conn->query("
    SELECT c.customer_id, cu.name, cu.email,
           GROUP_CONCAT(CONCAT(p.name, ' x', c.quantity) SEPARATOR ', ') AS items_summary,
           SUM(c.quantity * COALESCE(v.price, p.price)) AS cart_total,
           MAX(COALESCE(c.updated_at, c.created_at)) AS last_activity,
           TIMESTAMPDIFF(MINUTE, MAX(COALESCE(c.updated_at, c.created_at)), NOW()) AS idle_minutes
    FROM tbl_cart c
    JOIN customers cu ON cu.id = c.customer_id
    JOIN tbl_product_inventory p ON p.id = c.product_id
    LEFT JOIN tbl_product_variants v ON v.id = c.variant_id
    GROUP BY c.customer_id
    HAVING idle_minutes >= 1440
    ORDER BY last_activity ASC
");
if ($carts_res) { $abandoned_carts = $carts_res->fetch_all(MYSQLI_ASSOC); }

/** "5 min ago" / "3 hr ago" / "2 days ago" from a whole number of idle minutes. */
function cart_idle_label(int $minutes): string
{
    if ($minutes < 1) { return 'Just now'; }
    if ($minutes < 60) { return $minutes . ' min ago'; }
    if ($minutes < 24 * 60) { return intdiv($minutes, 60) . ' hr ago'; }
    $days = intdiv($minutes, 24 * 60);
    return $days . ($days === 1 ? ' day ago' : ' days ago');
}
$cart_flash = (string) ($_SESSION['cart_msg'] ?? '');
$cart_flash_type = (string) ($_SESSION['cart_msg_type'] ?? 'success');
unset($_SESSION['cart_msg'], $_SESSION['cart_msg_type']);

// --- Search + filters (server-side, prepared statements) ---
// q       : order ID / order number / payment ref / customer name, email, phone
// status  : one of $statuses, or 'in_progress' (the merged Ready for Pickup /
//           Out for Delivery / To Ship / To Receive option shown in the table)
// payment : payment_method enum value
$search_q = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    $search_q = mb_substr($search_q, 0, 100);
}
$filter_status = (string) ($_GET['status'] ?? '');
$in_progress_group = ['ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive'];
if ($filter_status !== 'in_progress' && !isset($statuses[$filter_status])) {
    $filter_status = '';
}
$payment_methods = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
$filter_payment = (string) ($_GET['payment'] ?? '');
if (!isset($payment_methods[$filter_payment])) {
    $filter_payment = '';
}

$where_sql = [];
$where_types = '';
$where_params = [];

if ($search_q !== '') {
    $like = '%' . addcslashes($search_q, '\\%_') . '%';
    $clauses = [
        'o.order_number LIKE ?',
        'o.payment_reference LIKE ?',
        'c.name LIKE ?',
        'c.email LIKE ?',
        'c.phone LIKE ?',
        'o.delivery_phone LIKE ?',
    ];
    $where_types .= 'ssssss';
    array_push($where_params, $like, $like, $like, $like, $like, $like);

    if (ctype_digit($search_q)) {
        $clauses[] = 'o.id = ?';
        $where_types .= 'i';
        $where_params[] = (int) $search_q;
    }

    // Phone numbers are stored in mixed formats (0919..., +63919..., with
    // spaces/dashes), so also compare digits-only, ignoring a leading 0 or
    // the +63 country code (0919... and +63919... are the same number).
    $digits = preg_replace('/\D+/', '', $search_q);
    if (strpos($digits, '63') === 0 && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }
    $digits = ltrim($digits, '0');
    if (strlen($digits) >= 4) {
        $digits_like = '%' . $digits . '%';
        $strip = fn(string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
        $clauses[] = $strip('c.phone') . ' LIKE ?';
        $clauses[] = $strip('o.delivery_phone') . ' LIKE ?';
        $where_types .= 'ss';
        array_push($where_params, $digits_like, $digits_like);
    }

    $where_sql[] = '(' . implode(' OR ', $clauses) . ')';
}

if ($filter_payment !== '') {
    $where_sql[] = 'o.payment_method = ?';
    $where_types .= 's';
    $where_params[] = $filter_payment;
}

// Status tab counts: same search + payment filters, but NOT the selected status, so every
// tab shows how many orders it would list right now.
$tab_count_stmt = $conn->prepare(
    "SELECT o.order_status AS s, COUNT(*) AS n
     FROM tbl_orders o
     LEFT JOIN customers c ON o.customer_id = c.id
     " . ($where_sql ? 'WHERE ' . implode(' AND ', $where_sql) : '') . "
     GROUP BY o.order_status"
);
if ($where_params) {
    $tab_count_stmt->bind_param($where_types, ...$where_params);
}
$tab_count_stmt->execute();
$status_counts = [];
foreach ($tab_count_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $status_counts[(string) $row['s']] = (int) $row['n'];
}
$tab_count_stmt->close();

// Status tabs. Each tab uses the same `status` values as before; "in_progress" is the merged
// Ready for Pickup / To Ship / To Receive / Out for Delivery option the table's status menu shows.
$status_tabs = [
    ''            => ['label' => 'All',                       'count' => array_sum($status_counts)],
    'pending'     => ['label' => 'Pending',                   'count' => $status_counts['pending'] ?? 0],
    'confirmed'   => ['label' => 'Confirmed',                 'count' => $status_counts['confirmed'] ?? 0],
    'processing'  => ['label' => 'Processing',                'count' => $status_counts['processing'] ?? 0],
    'in_progress' => ['label' => 'Ready / Out for Delivery',  'count' => array_sum(array_map(static fn ($s) => $status_counts[$s] ?? 0, $in_progress_group))],
    'reviews'     => ['label' => 'Reviews',                   'count' => $status_counts['reviews'] ?? 0],
    'completed'   => ['label' => 'Delivered / Picked Up',     'count' => $status_counts['completed'] ?? 0],
    'cancelled'   => ['label' => 'Cancelled',                 'count' => $status_counts['cancelled'] ?? 0],
];
// "Reviews" is a rare hand-off state: only show its tab while it has orders (or is selected).
if ($status_tabs['reviews']['count'] === 0 && $filter_status !== 'reviews') {
    unset($status_tabs['reviews']);
}

if ($filter_status === 'in_progress') {
    $where_sql[] = "o.order_status IN ('ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive')";
} elseif ($filter_status !== '') {
    $where_sql[] = 'o.order_status = ?';
    $where_types .= 's';
    $where_params[] = $filter_status;
}

$where_clause = $where_sql ? 'WHERE ' . implode(' AND ', $where_sql) : '';
$is_filtered = $search_q !== '' || $filter_status !== '' || $filter_payment !== '';

// --- Pagination (server-side) ---
// The count uses the same WHERE clause as the list query so page counts
// always match the filtered result set.
$per_page = 10;
$count_stmt = $conn->prepare(
    "SELECT COUNT(*) AS n
     FROM tbl_orders o
     LEFT JOIN customers c ON o.customer_id = c.id
     $where_clause"
);
if ($where_params) {
    $count_stmt->bind_param($where_types, ...$where_params);
}
$count_stmt->execute();
$total_orders_count = (int) ($count_stmt->get_result()->fetch_assoc()['n'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_orders_count / $per_page));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

/**
 * Builds an orders.php URL for a given page number, preserving any other
 * query-string params (e.g. a future search/filter) so pagination never
 * drops them, while stripping the one-shot flash flags so clicking a page
 * number doesn't re-show a stale success/error banner.
 */
function orders_page_url(int $target_page): string
{
    $params = $_GET;
    unset($params['error'], $params['detail'], $params['rider_updated']);
    $params['page'] = $target_page;
    $qs = http_build_query($params);
    return 'orders.php' . ($qs !== '' ? '?' . $qs : '');
}

/** URL for a status tab: keeps the search and payment filter, resets to page 1. '' = All. */
function orders_status_url(string $status): string
{
    $params = $_GET;
    unset($params['error'], $params['detail'], $params['rider_updated'], $params['page'], $params['status']);
    if ($status !== '') {
        $params['status'] = $status;
    }
    $qs = http_build_query($params);
    return 'orders.php' . ($qs !== '' ? '?' . $qs : '');
}

/** URL for the same list with the given query params dropped (page resets to 1). */
function orders_url_without(array $drop): string
{
    $params = $_GET;
    unset($params['error'], $params['detail'], $params['rider_updated'], $params['page']);
    foreach ($drop as $key) {
        unset($params[$key]);
    }
    $qs = http_build_query($params);
    return 'orders.php' . ($qs !== '' ? '?' . $qs : '');
}

// Fetch Recent Orders (current page only)
$order_stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.subtotal, o.delivery_fee, o.loyalty_points_earned, o.total, o.created_at, o.payment_method, o.payment_reference, c.name AS customer_name, c.email AS customer_email,
    GROUP_CONCAT(CONCAT(oi.product_name, ' (x', oi.quantity, ')') SEPARATOR '<br>') as items_summary
    FROM tbl_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN tbl_order_items oi ON o.id = oi.order_id
    $where_clause
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?"
);
$list_types = $where_types . 'ii';
$list_params = array_merge($where_params, [$per_page, $offset]);
$order_stmt->bind_param($list_types, ...$list_params);
$order_stmt->execute();
$order_result = $order_stmt->get_result();
$order_stmt->close();

$orders = [];
if ($order_result) {
    while ($row = mysqli_fetch_assoc($order_result)) {
        $orders[] = $row;
    }
}

$showing_from = $total_orders_count > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_orders_count);

// Windowed page-number list with ellipsis (first, last, and a few around
// the current page) so the nav stays a fixed, mobile-friendly width no
// matter how many total pages there are.
$page_window = 2;
$page_numbers = [];
for ($i = 1; $i <= $total_pages; $i++) {
    if ($i === 1 || $i === $total_pages || ($i >= $page - $page_window && $i <= $page + $page_window)) {
        $page_numbers[] = $i;
    } elseif (end($page_numbers) !== '...') {
        $page_numbers[] = '...';
    }
}

$username = htmlspecialchars($payload['username']);
$user_id = (int)$payload['user_id'];
$unread_messages_count = get_unread_count_staff($user_id);

// Sidebar "Orders" badge: same pending-order count as the summary cards above (staff has no
// admin_viewed_at "opened" tracking - that's admin/order_details.php only).
$pending_orders_count = (int) $order_summary['pending_orders'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Orders - DPS Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        body {
            overflow-x: hidden;
            margin: 0;
        }

        /* Page-level layout. Everything below uses the --dm-* variables from
           admin_style.css (defined on :root, redefined on body.dark-mode), so
           light/dark switching needs no separate block. min-width:0 lets the
           flex child (.main-content, next to the sidebar) shrink so a wide
           table scrolls inside its own wrapper instead of stretching the page. */
        .main-content { min-width: 0; box-sizing: border-box; }
        .orders-page { max-width: 1600px; margin: 0 auto; }

        .orders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .orders-header h1 { margin: 0 0 4px; }
        .orders-header p { margin: 0; color: var(--dm-text-muted, #64748b); }

        /* Summary cards: icon beside the figure instead of stacked, so they
           stay short and fill the row evenly at any width. */
        .orders-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .orders-stats .card.orders-stat {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            text-align: left;
            min-width: 0;
        }
        .orders-stats .card.orders-stat > i { font-size: 1.7rem; margin: 0; flex-shrink: 0; width: 36px; text-align: center; }
        .orders-stat h3 { margin: 0 0 2px; font-size: 0.85rem; font-weight: 600; color: var(--dm-text-muted, #64748b); }
        .orders-stat p { margin: 0; font-size: 1.35rem; font-weight: 700; overflow-wrap: anywhere; }

        /* Page tabs: Orders | Abandoned Carts */
        .page-tabs { display: flex; gap: 28px; margin: 0 0 18px; border-bottom: 2px solid var(--dm-border, #e2e8f0); overflow-x: auto; }
        .page-tab {
            background: none; border: none; border-bottom: 4px solid transparent; margin-bottom: -2px;
            padding: 10px 2px 14px; font: inherit; font-size: 1.3rem; font-weight: 800; color: var(--dm-text-muted, #64748b);
            cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 10px;
        }
        .page-tab i { font-size: 1.1rem; }
        .page-tab:hover { color: var(--link); }
        .page-tab.active { color: var(--link); border-bottom-color: #4a3e94; }
        body.dark-mode .page-tab:hover, body.dark-mode .page-tab.active { color: #a5b4fc; }
        body.dark-mode .page-tab.active { border-bottom-color: #a5b4fc; }
        .page-tab-count { min-width: 26px; padding: 2px 9px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; background: var(--dm-surface-2, #f1f5f9); color: var(--dm-text-muted, #64748b); }
        .page-tab-count.warn { background: var(--warning-soft); color: var(--warning); }
        body.dark-mode .page-tab-count.warn { background: #4a3410; color: #fcd34d; }
        @media (max-width: 560px) { .page-tabs { gap: 18px; } .page-tab { font-size: 1.05rem; } }
        .carts-panel[hidden], #orders-live[hidden] { display: none; }
        /* Attention: an amber, pulsing tab whenever there are abandoned carts */
        .page-tab.has-alert { color: var(--warning); }
        .page-tab.has-alert .page-tab-count { background: #f59e0b; color: #fff; animation: cartPulse 1.8s ease-in-out infinite; }
        .page-tab.has-alert.active { color: var(--warning); border-bottom-color: #f59e0b; }
        body.dark-mode .page-tab.has-alert, body.dark-mode .page-tab.has-alert.active { color: #fcd34d; }
        @keyframes cartPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.55); } 50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); } }
        @media (prefers-reduced-motion: reduce) { .page-tab.has-alert .page-tab-count { animation: none; } }
        .carts-box { padding: 20px; border-radius: 14px; background: var(--dm-surface, #fff); border: 1px solid var(--dm-border, #e5e7eb); border-left: 5px solid #f59e0b; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06); margin-bottom: 22px; }
        .carts-box h3 { margin: 0 0 4px; font-size: 1.05rem; }
        .carts-box .carts-note { margin: 0 0 14px; font-size: 0.85rem; color: var(--dm-text-muted, #64748b); }
        .carts-empty { margin: 4px 0 0; padding: 14px; text-align: center; border: 1px dashed var(--dm-border, #d1d5db); border-radius: 10px; color: var(--dm-text-muted, #64748b); }
        .carts-wrap { overflow-x: auto; }
        table.carts-table { width: 100%; border-collapse: collapse; }
        .carts-table th { padding: 10px; text-align: left; font-size: 0.8rem; background: var(--dm-surface-2, #f8f9fa); border-bottom: 2px solid var(--dm-border, #eee); }
        .carts-table td { padding: 10px; border-bottom: 1px solid var(--dm-border, #f1f5f9); vertical-align: middle; }
        .carts-table tr:last-child td { border-bottom: 0; }
        .carts-table .num { text-align: right; white-space: nowrap; font-weight: 700; }
        .carts-table small { display: block; margin-top: 2px; font-size: 0.75rem; color: var(--dm-text-muted, #94a3b8); }
        .carts-items { font-size: 0.85rem; color: var(--dm-text-muted, #64748b); max-width: 340px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
        .carts-remind { padding: 6px 12px; font-size: 0.8rem; background: #f59e0b; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .carts-remind:hover { background: #d97706; }

        /* Status tabs: one strip above the search bar; scrolls sideways when the row is too narrow */
        .orders-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 14px;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid var(--dm-border, #e5e7eb);
            background: var(--dm-surface, #fff);
            overflow-x: auto;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }
        .orders-tab {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 11px;
            border-radius: 9px;
            font-size: 0.84rem;
            font-weight: 600;
            white-space: nowrap;
            text-decoration: none;
            color: var(--dm-text-muted, #64748b);
            border: 1px solid transparent;
            transition: background .15s ease, color .15s ease;
        }
        /* While a new list is being fetched the old one stays put, only dimmed (no size change = no shifting) */
        #orders-live { transition: opacity .15s ease; }
        #orders-live.is-loading { opacity: 0.55; pointer-events: none; }

        /* Always ONE line (never wraps, so the page height can't change between tabs). Tabs grow to
           share the width on desktop; on narrow screens the strip scrolls sideways instead. */
        .orders-tabs { flex-wrap: nowrap; }
        .orders-tab { flex: 1 0 auto; justify-content: center; }
        .orders-tab:hover { background: var(--dm-surface-2, #f1f5f9); color: var(--dm-text, #1e293b); }
        .orders-tab.is-active {
            background: var(--chat-primary, #4a3e94);
            color: #fff;
            box-shadow: 0 3px 10px rgba(74, 62, 148, 0.30);
        }
        .orders-tab-count {
            min-width: 22px;
            padding: 1px 7px;
            border-radius: 999px;
            text-align: center;
            font-size: 0.72rem;
            font-weight: 700;
            background: var(--dm-surface-2, #f1f5f9);
            color: var(--dm-text-muted, #64748b);
        }
        .orders-tab.is-active .orders-tab-count { background: rgba(255, 255, 255, 0.22); color: #fff; }

        /* Search + filter toolbar */
        .orders-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 20px;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--dm-border, #e5e7eb);
            background: var(--dm-surface, #fff);
            box-shadow: var(--dm-shadow, 0 4px 15px rgba(0,0,0,0.05));
        }
        .orders-search { position: relative; flex: 1 1 320px; min-width: 0; }
        .orders-search > i.fa-search {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--dm-text-muted, #94a3b8); pointer-events: none;
        }
        .orders-toolbar input[type="search"],
        .orders-toolbar select {
            box-sizing: border-box;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--dm-border, #cbd5e1);
            border-radius: 8px;
            background: var(--dm-surface-2, #f8fafc);
            color: var(--dm-text, #1e293b);
            font: inherit;
            font-size: 0.92rem;
            max-width: 100%;
        }
        .orders-toolbar input[type="search"] { width: 100%; padding-left: 40px; padding-right: 38px; -webkit-appearance: none; appearance: none; }
        .orders-toolbar input[type="search"]::-webkit-search-cancel-button { display: none; }
        .orders-toolbar input::placeholder { color: var(--dm-text-muted, #94a3b8); opacity: 1; }
        .orders-toolbar input:focus, .orders-toolbar select:focus {
            outline: 2px solid rgba(37, 99, 235, .35);
            outline-offset: 0;
            border-color: #2563eb;
        }
        .orders-search-clear {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; color: var(--dm-text-muted, #64748b); text-decoration: none;
        }
        .orders-search-clear:hover { background: var(--dm-border, #e2e8f0); }
        .orders-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .orders-field label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--dm-text-muted, #64748b); }
        .orders-field select { min-width: 160px; }
        .orders-toolbar-actions { display: flex; align-items: center; gap: 10px; }
        .orders-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 42px; padding: 0 20px; border-radius: 8px; border: 1px solid #2563eb;
            background: #2563eb; color: #fff; font: inherit; font-size: 0.92rem; font-weight: 600;
            cursor: pointer; text-decoration: none;
        }
        .orders-btn:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .orders-reset-link { font-size: 0.85rem; font-weight: 600; text-decoration: none; white-space: nowrap; color: var(--dm-link, #4f46e5); }
        .orders-reset-link:hover { text-decoration: underline; }

        /* Table card. The wrapper is the only thing that scrolls sideways. */
        .table-box.orders-table-box { padding: 20px; box-sizing: border-box; min-width: 0; }
        .orders-table-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 14px; }
        .orders-table-head h3 { margin: 0; font-size: 1.1rem; }
        body .table-box .orders-result-note { font-size: 0.85rem; color: var(--dm-text-muted, #64748b); }
        .orders-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid var(--dm-border, #eee); border-radius: 10px; }

        /* Fixed layout with percentage columns (see git history for why auto
           layout let the Status <select> pin the table at a fixed pixel
           width). The min-width keeps 9 columns readable; below it the
           wrapper above scrolls instead of the page. */
        .table-box table.orders-table {
            width: 100%;
            min-width: 940px;
            margin: 0;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .orders-table th, .orders-table td {
            padding: 12px 10px;
            text-align: left;
            vertical-align: middle;
            border: 0;
            border-bottom: 1px solid var(--dm-border, #eee);
            overflow-wrap: anywhere;
        }
        .orders-table th {
            background: var(--dm-surface-2, #f8f9fa);
            color: var(--dm-text-muted, #64748b);
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .orders-table tbody tr:last-child td { border-bottom: 0; }
        .orders-table tbody tr:hover td { background: var(--dm-surface-2, #f8fafc); }
        .orders-table td { font-size: 0.88rem; }
        .orders-table .col-num { text-align: right; white-space: nowrap; }

        /* Column widths (order matches <thead>): No, Order Number, Items
           Purchased, Customer, Fulfillment, Status, Payment, Total, Placed At. */
        .orders-table th:nth-child(1), .orders-table td:nth-child(1) { width: 4%; }
        .orders-table th:nth-child(2), .orders-table td:nth-child(2) { width: 12%; }
        .orders-table th:nth-child(3), .orders-table td:nth-child(3) { width: 16%; }
        .orders-table th:nth-child(4), .orders-table td:nth-child(4) { width: 14%; }
        .orders-table th:nth-child(5), .orders-table td:nth-child(5) { width: 9%; }
        .orders-table th:nth-child(6), .orders-table td:nth-child(6) { width: 18%; }
        .orders-table th:nth-child(7), .orders-table td:nth-child(7) { width: 8%; }
        .orders-table th:nth-child(8), .orders-table td:nth-child(8) { width: 9%; }
        .orders-table th:nth-child(9), .orders-table td:nth-child(9) { width: 10%; }

        body .table-box .orders-table a.order-link { font-weight: 700; text-decoration: none; color: var(--info); }
        body.dark-mode .table-box .orders-table a.order-link { color: var(--dm-link, #a5b4fc); }
        .orders-table a.order-link:hover { text-decoration: underline; }
        body .table-box .orders-table .cell-sub { display: block; margin-top: 2px; font-size: 0.78rem; color: var(--dm-text-muted, #64748b); }
        body .table-box .orders-table .cell-items { font-size: 0.82rem; line-height: 1.45; min-width: 220px; max-width: 320px; }
        /* Many items: show the first two, fold the rest behind a "+N more" toggle so one big order doesn't make a giant row */
        .cell-items .oi { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 3px; }
        .cell-items .oi-more[hidden] { display: none; }
        .cell-items .oi-toggle { background: none; border: 0; padding: 2px 0 0; font: inherit; font-size: 0.78rem; font-weight: 700; color: var(--info); cursor: pointer; }
        .cell-items .oi-toggle:hover { text-decoration: underline; }
        body.dark-mode .cell-items .oi-toggle { color: #93c5fd; }
        body .table-box .orders-table .pay-gcash { font-weight: 700; color: #2980b9; }
        body .table-box .orders-table .pay-other { font-weight: 600; color: var(--dm-text-muted, #7f8c8d); }
        .orders-table form { margin: 0; }
        .orders-table select.status-select {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            height: 36px;
            padding: 0 8px;
            border-radius: 8px;
            border: 1px solid var(--dm-border, #cbd5e1);
            background: var(--dm-surface-2, #fff);
            color: var(--dm-text, #1e293b);
            font: inherit;
            font-size: 0.82rem;
            text-overflow: ellipsis;
        }
        .orders-empty { text-align: center; padding: 36px 16px !important; color: var(--dm-text-muted, #64748b); }
        .orders-empty i { display: block; font-size: 1.8rem; margin-bottom: 8px; opacity: .6; }

        .orders-alert {
            background: var(--danger-soft); color: var(--danger); padding: 12px 18px; border-radius: 8px;
            margin-bottom: 20px; border: 1px solid #fecaca;
        }
        body.dark-mode .orders-alert { background: #3a1414; color: #fca5a5; border-color: #5c1f1f; }

        /* Keep pagination legible where the blanket dark-mode
           ".table-box * { color: inherit }" would otherwise flatten it. */
        body .table-box .page-btn, body .table-box .page-num { color: var(--dm-text, #1e293b); }
        body .table-box .page-num.active { color: #fff; }
        body .table-box .page-ellipsis, body .table-box .orders-pagination-info { color: var(--dm-text-muted, #64748b); }

        @media (max-width: 900px) {
            .orders-search { flex-basis: 100%; }
            .orders-field { flex: 1 1 160px; }
            .orders-field select { width: 100%; min-width: 0; }
            .orders-toolbar-actions { flex: 1 1 100%; }
            .orders-toolbar-actions .orders-btn { flex: 1; }
        }
        @media (max-width: 480px) {
            .orders-header h1 { font-size: 1.5rem; }
            .orders-toolbar { padding: 12px; }
            .orders-field { flex-basis: 100%; }
            .table-box.orders-table-box { padding: 12px; }
        }

        .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 999px; font-size: 0.8rem; color: white; font-weight: 700; }
        .status-pending { background: #f39c12; }
        .status-confirmed, .status-processing, .status-ready_for_pickup { background: #3498db; }
        .status-to_ship, .status-to_receive { background: #16a085; }
        .status-reviews { background: #f1c40f; color: var(--text); }
        .status-out_for_delivery { background: #8e44ad; }
        .status-completed { background: #27ae60; }
        .status-cancelled { background: #c0392b; }

        /* Status Badge Styling */
        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .badge-delivered { background: var(--success-soft); color: var(--success); }
        .badge-cancelled { background: var(--danger-soft); color: var(--danger); }

        /* iOS-Style Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: var(--surface);
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        input:checked + .slider {
            background-color: #10b981;
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* Orders pagination — uses the same --dm-* variables admin_style.css
           already defines on :root/body.dark-mode, so it tracks dark/light
           mode automatically with no separate override block needed. */
        .orders-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--dm-border, #eee);
        }
        .orders-pagination-info {
            font-size: 0.85rem;
            color: var(--dm-text-muted, #64748b);
            white-space: nowrap;
        }
        .orders-pagination-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            max-width: 100%;
        }
        .page-numbers {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: nowrap;
            overflow-x: auto;
            max-width: 100%;
            padding-bottom: 2px;
        }
        .page-btn, .page-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid var(--dm-border, #e2e8f0);
            background: var(--dm-surface, #fff);
            color: var(--dm-text, #1e293b);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            flex-shrink: 0;
            transition: background .15s, color .15s, border-color .15s;
        }
        a.page-btn:hover, a.page-num:hover {
            background: var(--dm-surface-2, #f1f5f9);
            border-color: #2563eb;
        }
        .page-num.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        .page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .page-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            color: var(--dm-text-muted, #94a3b8);
            font-size: 0.82rem;
            flex-shrink: 0;
        }

        @media (max-width: 640px) {
            .orders-pagination { justify-content: center; text-align: center; }
            .orders-pagination-nav { width: 100%; justify-content: center; }
            .page-btn-label { display: none; }
        }

    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
      <div class="orders-page">
        <?php if (isset($_GET['error'])): ?>
            <div class="orders-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php if ($_GET['error'] === 'cancelled_order'): ?>
                    A cancelled order cannot be marked as completed.
                <?php elseif (!empty($_GET['detail'])): ?>
                    Status not updated: <?php echo htmlspecialchars($_GET['detail']); ?>
                <?php else: ?>
                    Something went wrong updating that order's status.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <!-- Header -->
        <div class="welcome-header orders-header">
            <div>
                <h1>Orders 🛒</h1>
                <p>Order records shared across customer, staff, and admin modules.</p>
            </div>

        </div>

        <div class="orders-stats">
            <div class="card orders-stat">
                <i class="fas fa-shopping-cart" style="color: #27ae60;"></i>
                <div>
                    <h3>Total Orders</h3>
                    <p data-stat="total_orders"><?php echo number_format((int)$order_summary['total_orders']); ?></p>
                </div>
            </div>
            <div class="card orders-stat">
                <i class="fas fa-dollar-sign" style="color: #3498db;"></i>
                <div>
                    <h3>Total Sales</h3>
                    <p data-stat="total_sales">PHP <?php echo number_format((float)$order_summary['total_sales'], 2); ?></p>
                </div>
            </div>
            <div class="card orders-stat">
                <i class="fas fa-clock" style="color: #f39c12;"></i>
                <div>
                    <h3>Pending Orders</h3>
                    <p data-stat="pending_orders"><?php echo number_format((int)$order_summary['pending_orders']); ?></p>
                </div>
            </div>
            <div class="card orders-stat">
                <i class="fas fa-check-circle" style="color: #27ae60;"></i>
                <div>
                    <h3>Completed Orders</h3>
                    <p data-stat="completed_orders"><?php echo number_format((int)$order_summary['completed_orders']); ?></p>
                </div>
            </div>
        </div>

        <div class="page-tabs" id="page-tabs" role="tablist">
            <button type="button" class="page-tab active" role="tab" data-tab="orders" aria-selected="true">Orders</button>
            <button type="button" class="page-tab<?php echo $abandoned_carts ? ' has-alert' : ''; ?>" role="tab" data-tab="carts" aria-selected="false">
                <?php echo $abandoned_carts ? '<i class="fas fa-cart-arrow-down"></i>' : ''; ?>Abandoned Carts
                <span class="page-tab-count<?php echo $abandoned_carts ? ' warn' : ''; ?>"><?php echo count($abandoned_carts); ?></span>
            </button>
        </div>

        <div class="carts-panel" id="abandoned-carts" hidden>
            <?php if ($cart_flash !== ''): ?>
                <div style="margin-bottom: 16px; padding: 12px 15px; border-radius: 8px; font-weight: 600; background: <?php echo $cart_flash_type === 'success' ? '#eafaf1' : '#fff5f5'; ?>; color: <?php echo $cart_flash_type === 'success' ? '#27ae60' : '#e74c3c'; ?>;">
                    <?php echo htmlspecialchars($cart_flash); ?>
                </div>
            <?php endif; ?>
            <div class="carts-box">
                <h3>Abandoned Carts</h3>
                <p class="carts-note">Customers who added items to their cart but did not check out for 24+ hours. Nothing here places an order or changes a cart &mdash; <strong>Remind</strong> only sends the customer a notification and email.</p>
                <?php if (empty($abandoned_carts)): ?>
                    <p class="carts-empty">No abandoned carts.</p>
                <?php else: ?>
                    <div class="carts-wrap">
                        <table class="carts-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th class="num">Cart Total</th>
                                    <th>Last Activity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($abandoned_carts as $ac): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($ac['name']); ?></strong><small><?php echo htmlspecialchars($ac['email']); ?></small></td>
                                        <td><div class="carts-items" title="<?php echo htmlspecialchars($ac['items_summary']); ?>"><?php echo htmlspecialchars($ac['items_summary']); ?></div></td>
                                        <td class="num">PHP <?php echo number_format((float) $ac['cart_total'], 2); ?></td>
                                        <td style="white-space: nowrap;"><?php echo htmlspecialchars(cart_idle_label((int) $ac['idle_minutes'])); ?>
                                            <small><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ac['last_activity']))); ?></small></td>
                                        <td>
                                            <form method="POST" action="orders.php" class="cart-remind-form" onsubmit="return confirm('Send a cart reminder email to this customer?');" style="margin: 0;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="send_cart_reminder" value="<?php echo (int) $ac['customer_id']; ?>">
                                                <button type="submit" class="carts-remind">Remind</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Everything from the tabs down to the pagination is swapped in place (see the script at the
             bottom) so choosing a tab / page / search never reloads the summary cards above it. -->
        <div id="orders-live">
        <!-- Status tabs: each one is a plain link (server-side filter), so it works with the
             search box and payment filter below and resets to page 1. -->
        <nav class="orders-tabs" aria-label="Order status">
            <?php foreach ($status_tabs as $tab_key => $tab): ?>
                <a class="orders-tab<?php echo (string) $tab_key === $filter_status ? ' is-active' : ''; ?>"
                   href="<?php echo htmlspecialchars(orders_status_url((string) $tab_key)); ?>"
                   <?php echo (string) $tab_key === $filter_status ? 'aria-current="page"' : ''; ?>>
                    <?php echo htmlspecialchars($tab['label']); ?>
                    <span class="orders-tab-count"><?php echo number_format((int) $tab['count']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Search + filters: plain GET form. It never sends `page`, so any new
             search/filter starts again from page 1. The selected status tab travels as a hidden field. -->
        <form class="orders-toolbar" method="get" action="orders.php" role="search">
            <?php if ($filter_status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>"><?php endif; ?>
            <div class="orders-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?php echo htmlspecialchars($search_q); ?>" maxlength="100"
                       placeholder="Search by order ID, customer name, email, or phone..."
                       aria-label="Search orders" autocomplete="off">
                <?php if ($search_q !== ''): ?>
                    <a class="orders-search-clear" href="<?php echo htmlspecialchars(orders_url_without(['q'])); ?>" title="Clear search" aria-label="Clear search">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="orders-field">
                <label for="filter-payment">Payment</label>
                <select name="payment" id="filter-payment">
                    <option value="">All payments</option>
                    <?php foreach ($payment_methods as $pm_value => $pm_label): ?>
                        <option value="<?php echo htmlspecialchars($pm_value); ?>" <?php echo $filter_payment === $pm_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($pm_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="orders-toolbar-actions">
                <button type="submit" class="orders-btn"><i class="fas fa-search"></i> Search</button>
                <?php if ($filter_payment !== ''): ?>
                    <a class="orders-reset-link" href="<?php echo htmlspecialchars(orders_url_without(['payment'])); ?>">Reset filters</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-box orders-table-box">
            <div class="orders-table-head">
                <h3><?php echo $is_filtered ? 'Matching Orders' : 'Recent Orders'; ?></h3>
                <?php if ($search_q !== ''): ?>
                    <span class="orders-result-note">Results for &ldquo;<?php echo htmlspecialchars($search_q); ?>&rdquo;</span>
                <?php endif; ?>
            </div>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Order Number</th>
                            <th>Items Purchased</th>
                            <th>Customer</th>
                            <th>Fulfillment</th>
                            <th>Status</th>
                            <th>Payment Ref</th>
                            <th class="col-num">Total</th>
                            <th>Placed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_number = $offset + 1;
                        foreach ($orders as $order):
                            $placed_ts = strtotime((string) $order['created_at']);
                        ?>
                            <tr data-order-id="<?php echo (int) $order['id']; ?>">
                                <td><?php echo $row_number++; ?></td>
                                <td>
                                    <a class="order-link" href="order_details.php?id=<?php echo (int)$order['id']; ?>">
                                        <?php echo htmlspecialchars($order['order_number']); ?>
                                    </a>
                                </td>
                                <td class="cell-items">
                                    <?php
                                    $oi_list = $order['items_summary'] ? explode('<br>', $order['items_summary']) : [];
                                    if (!$oi_list) { echo 'N/A'; }
                                    foreach ($oi_list as $oi_i => $oi_line):
                                        if ($oi_i === 2): ?><div class="oi-more" hidden><?php endif; ?>
                                        <div class="oi" title="<?php echo htmlspecialchars($oi_line); ?>"><?php echo htmlspecialchars($oi_line); ?></div>
                                    <?php endforeach;
                                    if (count($oi_list) > 2): ?></div>
                                        <button type="button" class="oi-toggle" data-more="<?php echo count($oi_list) - 2; ?>">+<?php echo count($oi_list) - 2; ?> more</button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($order['customer_name'] ?: 'Unknown'); ?><small class="cell-sub"><?php echo htmlspecialchars($order['customer_email'] ?: ''); ?></small></td>
                                <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['fulfillment_type']))); ?></td>
                                <td>
                                    <form method="post" action="">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                        <select class="status-select" name="order_status" data-order-id="<?php echo (int)$order['id']; ?>" data-current="<?php echo htmlspecialchars((string) $order['order_status']); ?>" aria-label="Order status">
                                            <?php
                                            // "Ready for Pickup", "Out for Delivery", "To Ship" and "To Receive" are all
                                            // the same conceptual stage ("the order is on its way to the customer") —
                                            // the customer-facing tracker already groups them into one step. Showing
                                            // all four as separate choices here just makes staff guess which one
                                            // applies, so they're merged into a single option. The actual value
                                            // submitted still matches the order's real fulfillment type (or whichever
                                            // of the four the rider app already put it in), so nothing about the
                                            // underlying status values, rider workflow, or loyalty logic changes.
                                            $in_progress_statuses = ['ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive'];
                                            $current_status = $order['order_status'];
                                            $merged_progress_value = in_array($current_status, $in_progress_statuses, true)
                                                ? $current_status
                                                : ($order['fulfillment_type'] === 'pickup' ? 'ready_for_pickup' : 'out_for_delivery');
                                            ?>
                                            <option value="pending" <?php echo $current_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="confirmed" <?php echo $current_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="processing" <?php echo $current_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="<?php echo $merged_progress_value; ?>" <?php echo in_array($current_status, $in_progress_statuses, true) ? 'selected' : ''; ?>>Ready for Pickup / Out for Delivery</option>
                                            <option value="reviews" <?php echo $current_status === 'reviews' ? 'selected' : ''; ?>>Reviews</option>
                                            <option value="completed" <?php echo $current_status === 'completed' ? 'selected' : ''; ?>>Delivered / Picked Up</option>
                                            <option value="cancelled" <?php echo $current_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <?php /* Without JavaScript the dropdown cannot auto-submit, so offer a plain button. */ ?>
                                        <noscript><button type="submit" class="orders-btn">Update</button></noscript>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($order['payment_method'] === 'gcash'): ?>
                                        <span class="pay-gcash">GCash</span>
                                        <small class="cell-sub"><?php echo htmlspecialchars($order['payment_reference'] ?: 'Pending Ref'); ?></small>
                                    <?php else: ?>
                                        <span class="pay-other">COD</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-num">PHP <?php echo number_format((float)$order['total'], 2); ?></td>
                                <td>
                                    <?php if ($placed_ts): ?>
                                        <?php echo htmlspecialchars(date('M j, Y', $placed_ts)); ?>
                                        <small class="cell-sub"><?php echo htmlspecialchars(date('g:i A', $placed_ts)); ?></small>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string) $order['created_at']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="9" class="orders-empty">
                                    <i class="fas fa-search"></i>
                                    <?php if ($is_filtered): ?>
                                        No orders match your search or filters.
                                        <br><a class="orders-reset-link" href="orders.php">Clear search and filters</a>
                                    <?php else: ?>
                                        No orders found.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="orders-pagination-wrap">
            <?php if ($total_orders_count > 0): ?>
                <div class="orders-pagination">
                    <div class="orders-pagination-info">
                        Showing <?php echo number_format($showing_from); ?>&ndash;<?php echo number_format($showing_to); ?> of <?php echo number_format($total_orders_count); ?> <?php echo $is_filtered ? 'matching ' : ''; ?>orders
                    </div>
                    <nav class="orders-pagination-nav" aria-label="Orders pagination">
                        <?php if ($page > 1): ?>
                            <a class="page-btn" href="<?php echo htmlspecialchars(orders_page_url($page - 1)); ?>">
                                <i class="fas fa-chevron-left"></i> <span class="page-btn-label">Previous</span>
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled" aria-disabled="true">
                                <i class="fas fa-chevron-left"></i> <span class="page-btn-label">Previous</span>
                            </span>
                        <?php endif; ?>

                        <div class="page-numbers">
                            <?php foreach ($page_numbers as $pn): ?>
                                <?php if ($pn === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php elseif ((int) $pn === $page): ?>
                                    <span class="page-num active" aria-current="page"><?php echo (int) $pn; ?></span>
                                <?php else: ?>
                                    <a class="page-num" href="<?php echo htmlspecialchars(orders_page_url((int) $pn)); ?>"><?php echo (int) $pn; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($page < $total_pages): ?>
                            <a class="page-btn" href="<?php echo htmlspecialchars(orders_page_url($page + 1)); ?>">
                                <span class="page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled" aria-disabled="true">
                                <span class="page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
            </div><!-- /#orders-pagination-wrap -->
        </div>
        </div><!-- /#orders-live -->
      </div>
    </div>
<script>
    // Live list: the tabs, search box, table and pagination are swapped in place instead of reloading the
    // whole page, so nothing above them (the summary cards) ever moves. If anything goes wrong the
    // link/form simply behaves as a normal page load.
    (function () {
        var live = document.getElementById('orders-live');
        if (!live || !window.fetch || !window.DOMParser || !window.URL || !window.AbortController) return;

        var pending = null;

        function keepActiveTabVisible() {
            var strip = live.querySelector('.orders-tabs');
            var active = strip && strip.querySelector('.orders-tab.is-active');
            if (!active || strip.scrollWidth <= strip.clientWidth) return;
            var inView = active.offsetLeft >= strip.scrollLeft &&
                         active.offsetLeft + active.offsetWidth <= strip.scrollLeft + strip.clientWidth;
            if (!inView) strip.scrollLeft = active.offsetLeft - (strip.clientWidth - active.offsetWidth) / 2;
        }

        function load(url, push) {
            if (pending) pending.abort();
            var ctl = new AbortController();
            pending = ctl;
            live.classList.add('is-loading');

            fetch(url, { credentials: 'same-origin', signal: ctl.signal })
                .then(function (res) {
                    if (!res.ok) throw new Error('bad response');
                    // e.g. session expired -> redirected to the login page: do a real navigation instead
                    if (new URL(res.url).pathname !== location.pathname) throw new Error('redirected');
                    return res.text();
                })
                .then(function (html) {
                    var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('orders-live');
                    if (!fresh) throw new Error('no list in response');
                    var oldStrip = live.querySelector('.orders-tabs');
                    var left = oldStrip ? oldStrip.scrollLeft : 0;
                    live.innerHTML = fresh.innerHTML;
                    var strip = live.querySelector('.orders-tabs');
                    if (strip) strip.scrollLeft = left;
                    keepActiveTabVisible();
                    if (push) history.pushState({ ordersLive: true }, '', url);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    // Redirected (e.g. session expired -> login page): a real navigation is correct.
                    // Any other failure (network, server error): keep the page and tell the user.
                    if ((err && err.message === 'redirected') || !window.LiveUI) { window.location.href = url; return; }
                    LiveUI.toast('Could not load the orders list. Please check your connection and try again.', 'error');
                })
                .then(function () {
                    if (pending === ctl) { pending = null; live.classList.remove('is-loading'); }
                });
        }

        // Tabs, page numbers, Previous/Next, "clear search" and "reset filters" links.
        live.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var a = e.target.closest ? e.target.closest('a') : null;
            if (!a || !live.contains(a) || a.target) return;
            if (!a.matches('.orders-tab, .page-btn, .page-num, .orders-search-clear, .orders-reset-link')) return;
            var u = new URL(a.href, location.href);
            if (u.origin !== location.origin || u.pathname !== location.pathname) return;
            e.preventDefault();
            load(u.pathname + u.search, true);
        });

        // Search / payment filter form (GET). Empty fields are left out of the URL.
        live.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('.orders-toolbar')) return;
            e.preventDefault();
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, key) {
                if (String(value).trim() !== '') params.append(key, value);
            });
            var qs = params.toString();
            load(location.pathname + (qs ? '?' + qs : ''), true);
        });

        // Browser Back / Forward between the lists we pushed.
        window.addEventListener('popstate', function () { load(location.pathname + location.search, false); });

        keepActiveTabVisible();
    })();
</script>
<script>
    // Status changes without a page refresh:
    //   change dropdown -> fetch() POST -> JSON -> patch just that row, the summary cards, the tab
    //   counts and the sidebar badge. Rows are keyed by data-order-id, so nothing can be duplicated.
    (function () {
        var live = document.getElementById('orders-live');
        if (!live || !window.LiveUI) return;

        function fmtInt(n) { return Number(n || 0).toLocaleString('en-US'); }
        function fmtMoney(n) { return 'PHP ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        function updateStats(s) {
            if (!s) return;
            var fmt = { total_orders: fmtInt, total_sales: fmtMoney, pending_orders: fmtInt, completed_orders: fmtInt };
            Object.keys(fmt).forEach(function (k) {
                var el = document.querySelector('[data-stat="' + k + '"]');
                if (el && s[k] != null) LiveUI.setText(el, fmt[k](s[k]));
            });
        }

        // Rows we must not replace: a request is in flight, or staff is using that row's dropdown.
        function leaveRow(tr) {
            return tr.classList.contains('liveui-row-busy') || tr.contains(document.activeElement);
        }

        // Align the visible list with the server, replacing only rows that actually changed.
        var refreshing = null;
        function refreshList() {
            if (refreshing) return refreshing;
            refreshing = LiveUI.fetchDoc(location.pathname + location.search).then(function (doc) {
                var fresh = doc.getElementById('orders-live');
                if (!fresh) return;

                var curNav = live.querySelector('.orders-tabs'), frNav = fresh.querySelector('.orders-tabs');
                if (curNav && frNav) {
                    var left = curNav.scrollLeft;
                    if (LiveUI.setHTML(curNav, frNav)) curNav.scrollLeft = left;
                }

                var curBody = live.querySelector('.orders-table tbody'), frBody = fresh.querySelector('.orders-table tbody');
                if (curBody && frBody) {
                    var nFresh = frBody.querySelectorAll('tr[data-order-id]').length;
                    var nCur = curBody.querySelectorAll('tr[data-order-id]').length;
                    if (!nFresh || !nCur) LiveUI.setHTML(curBody, frBody);        // empty <-> has rows
                    else LiveUI.syncKeyed(curBody, frBody, 'data-order-id', { skip: leaveRow });
                }

                LiveUI.setHTML(live.querySelector('#orders-pagination-wrap'), fresh.querySelector('#orders-pagination-wrap'));

                var s = {};
                doc.querySelectorAll('[data-stat]').forEach(function (el) {
                    var cur = document.querySelector('[data-stat="' + el.getAttribute('data-stat') + '"]');
                    if (cur) LiveUI.setText(cur, el.textContent);
                });
                LiveUI.syncSidebar(doc);
            }).catch(function () { /* silent: next poll retries */ }).then(function () { refreshing = null; });
            return refreshing;
        }

        live.addEventListener('change', function (e) {
            var sel = e.target;
            if (!sel.matches || !sel.matches('select.status-select')) return;

            var id = sel.getAttribute('data-order-id');
            var previous = sel.getAttribute('data-current');
            if (sel.value === previous) return;

            var release = LiveUI.lock('order-' + id);
            if (!release) { sel.value = previous; return; }      // this order is already being updated

            var tr = sel.closest('tr');
            var done = LiveUI.busy(sel);
            if (tr) tr.classList.add('liveui-row-busy');

            var fd = new FormData();
            fd.append('order_id', id);
            fd.append('order_status', sel.value);

            LiveUI.post(location.pathname + location.search, fd).then(function (res) {
                sel.setAttribute('data-current', res.new_status);
                updateStats(res.summary);
                LiveUI.toast(res.message, 'success');
                return true;
            }).catch(function (err) {
                sel.value = previous;                               // put the dropdown back
                LiveUI.toast(err.message, 'error');
                return false;
            }).then(function (ok) {
                done();
                if (tr) tr.classList.remove('liveui-row-busy');
                release();
                if (ok) return refreshList();                       // tab counts, sidebar badge, rows entering/leaving the page
            });
        });

        // Pick up changes made elsewhere (e.g. a rider completing a delivery). A tiny probe (live_changes_api.php)
        // runs every ~5s while this tab is visible; the list is re-read ONLY when the orders fingerprint changed,
        // and only the changed rows are replaced. (Falls back to a 20s refresh if the probe is unavailable.)
        LiveUI.watch('orders', function () {
            if (live.classList.contains('is-loading')) return;
            return refreshList();
        }, { fallbackMs: 20000 });
    })();

// "+N more" toggle in the Items Purchased column
document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.oi-toggle') : null;
    if (!b) return;
    var more = b.previousElementSibling;
    if (!more || !more.classList.contains('oi-more')) return;
    more.hidden = !more.hidden;
    b.textContent = more.hidden ? '+' + b.dataset.more + ' more' : 'Show less';
});

// Orders | Abandoned Carts page tabs. #abandoned-carts in the URL (e.g. after a reminder) opens the carts tab.
(function () {
    var tabs = document.querySelectorAll('.page-tab');
    var live = document.getElementById('orders-live');
    var carts = document.getElementById('abandoned-carts');
    if (!tabs.length || !live || !carts) return;
    function show(name) {
        tabs.forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        live.hidden = name !== 'orders';
        carts.hidden = name !== 'carts';
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            show(t.dataset.tab);
            if (history.replaceState) history.replaceState(null, '', t.dataset.tab === 'carts' ? '#abandoned-carts' : location.pathname + location.search);
        });
    });
    show(location.hash === '#abandoned-carts' ? 'carts' : 'orders');

    // "Remind" without leaving the page
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('.cart-remind-form') || e.defaultPrevented || !window.LiveUI) return;   // confirm() cancelled -> defaultPrevented
        e.preventDefault();
        var field = form.querySelector('[name="send_cart_reminder"]');
        var release = LiveUI.lock('cart-remind-' + field.value);
        if (!release) return;
        var btn = e.submitter || form.querySelector('button[type="submit"]');
        var done = LiveUI.busy(btn, 'Sending…');
        LiveUI.post('orders.php', LiveUI.formData(form, btn)).then(function (res) {
            LiveUI.toast(res.message, 'success');
            done('Reminded ✓', 3000);
        }).catch(function (err) {
            LiveUI.toast(err.message, 'error');
            done();
        }).then(release);
    });
})();
</script>
</body>
</html>