<?php
// The autoloader must be included before any `use` statements for vendor libraries.
// auth.php includes functions.php, which in turn includes the Composer autoloader.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/return_refund_helper.php';
require_once __DIR__ . '/../app/helpers/delivery_proof_helper.php'; // rider's pickup photo (parcel collected at the shop)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    require_customer_login();
    $customer = current_customer();
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);
    $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');

    if ($order_id_to_cancel <= 0 || $cancellation_reason === '') {
        $_SESSION['message'] = 'Please provide a reason for cancelling the order.';
        $_SESSION['message_type'] = 'error';
    } else {
        $order = get_order_by_id($order_id_to_cancel, (int)$customer['id']);

        if ($order && in_array($order['order_status'], ['pending', 'confirmed'])) {
            $conn->begin_transaction();
            try {
                // 1. Restore stock ONLY if the order was already confirmed (as stock is now deducted on confirmation)
                if ($order['order_status'] === 'confirmed') {
                    $order_items_to_restore = get_order_items($order_id_to_cancel);
                    foreach ($order_items_to_restore as $item) {
                        $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                        restore_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity']);
                    }
                }

                // 2. Update the order status to 'cancelled'
                $update_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled', cancellation_reason = ? WHERE id = ?");
                $update_stmt->bind_param('si', $cancellation_reason, $order_id_to_cancel);
                $update_stmt->execute();
                $update_stmt->close();

                $conn->commit();
                $_SESSION['message'] = 'Your order has been successfully cancelled.';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['message'] = 'There was an error cancelling your order. Please contact support.';
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = 'This order can no longer be cancelled.';
            $_SESSION['message_type'] = 'error';
        }
    }
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id_to_cancel);
    exit();
}

require_customer_login();

$customer = current_customer();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_delivery_address') {
    $order_id_to_update = (int)($_POST['order_id'] ?? 0);
    $address_id = (int)($_POST['address_id'] ?? 0);
    $address = '';
    $phone = '';
    $latitude = null;
    $longitude = null;

    if ($address_id > 0) {
        $address_stmt = $conn->prepare(
            "SELECT full_address, phone, latitude, longitude
             FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1"
        );
        $address_stmt->bind_param('ii', $address_id, $customer['id']);
        $address_stmt->execute();
        $saved_address = $address_stmt->get_result()->fetch_assoc();
        $address_stmt->close();
        if ($saved_address) {
            $address = trim($saved_address['full_address']);
            $phone = trim($saved_address['phone'] ?? '');
            $latitude = is_numeric($saved_address['latitude']) ? (float)$saved_address['latitude'] : null;
            $longitude = is_numeric($saved_address['longitude']) ? (float)$saved_address['longitude'] : null;
        }
    } else {
        $address = trim($_POST['new_delivery_address'] ?? '');
        $phone = trim($_POST['new_delivery_phone'] ?? '');
        $latitude = is_numeric($_POST['latitude'] ?? null) ? (float)$_POST['latitude'] : null;
        $longitude = is_numeric($_POST['longitude'] ?? null) ? (float)$_POST['longitude'] : null;
    }

    $address_update = update_customer_order_delivery(
        $order_id_to_update,
        (int)$customer['id'],
        $address,
        $phone,
        $latitude,
        $longitude
    );
    $_SESSION['message'] = $address_update['message'];
    $_SESSION['message_type'] = $address_update['success'] ? 'success' : 'error';
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id_to_update);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_e_receipt') {
    $receipt_order_id = (int)($_POST['order_id'] ?? 0);
    $receipt_order = $receipt_order_id > 0 ? get_order_by_id($receipt_order_id, (int)$customer['id']) : null;

    if (!$receipt_order) {
        $_SESSION['message'] = 'That order could not be found.';
        $_SESSION['message_type'] = 'error';
    } elseif ($receipt_order['order_status'] === 'cancelled') {
        $_SESSION['message'] = 'A cancelled order has no delivery QR to email.';
        $_SESSION['message_type'] = 'error';
    } else {
        // Same token/QR the page displays — resolved through the one shared helper.
        $receipt_delivery = ensure_order_delivery_token($receipt_order);
        $receipt_items = get_order_items($receipt_order_id);

        $mail_result = ['success' => false, 'error' => 'Unable to prepare the e-receipt.'];
        if ($receipt_delivery && !empty($receipt_delivery['qr_confirmation_token'])) {
            $mail_result = notifications_send_order_receipt($receipt_order, $receipt_items, $receipt_delivery, $customer, $conn);
        }

        if ($mail_result['success']) {
            $_SESSION['message'] = 'Your e-receipt with the delivery QR has been emailed to ' . $customer['email'] . '.';
            $_SESSION['message_type'] = 'success';
            notifications_create($conn, [
                'customer_id'     => (int)$customer['id'],
                'type'            => 'e_receipt_sent',
                'channel'         => 'in_app',
                'title'           => 'E-receipt sent',
                'message'         => 'Your e-receipt for order #' . $receipt_order['order_number'] . ' was emailed to ' . $customer['email'] . '.',
                'reference_table' => 'tbl_orders',
                'reference_id'    => $receipt_order_id,
            ]);
        } else {
            $_SESSION['message'] = 'We could not send your e-receipt right now (' . ($mail_result['error'] ?? 'unknown error') . '). Please try again shortly.';
            $_SESSION['message_type'] = 'error';
        }
    }

    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $receipt_order_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy_again') {
    // Buy Again / Reorder: re-adds this order's real items to the customer's
    // CURRENT cart (never places a new order directly) so they still review
    // availability, price, variant, delivery, and payment at checkout - same
    // as any other cart addition. Reuses the existing cart table/functions;
    // no separate reorder system.
    $reorder_order_id = (int)($_POST['order_id'] ?? 0);
    $reorder_order = $reorder_order_id > 0 ? get_order_by_id($reorder_order_id, (int)$customer['id']) : null;

    if (!$reorder_order || $reorder_order['order_status'] !== 'completed') {
        $_SESSION['message'] = 'Only completed orders can be reordered.';
        $_SESSION['message_type'] = 'error';
    } else {
        $reorder_items = get_order_items($reorder_order_id);
        $added_count = 0;
        $unavailable = [];

        foreach ($reorder_items as $item) {
            $product_id = (int)$item['product_id'];
            $variant_id = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $available_stock = get_product_stock($product_id, $variant_id);
            $display_name = $item['product_name'] . (!empty($item['variant_size']) ? ' (' . $item['variant_size'] . ')' : '');

            if ($available_stock <= 0) {
                $unavailable[] = $display_name;
                continue;
            }

            // tbl_cart only allows ONE row per (customer_id, product_id) regardless
            // of variant - a different variant of the same product already sitting
            // in the cart would otherwise crash add_customer_cart_item() on that
            // constraint. Detect it here and skip gracefully instead.
            $existing_variant_stmt = $conn->prepare("SELECT variant_id FROM tbl_cart WHERE customer_id = ? AND product_id = ? LIMIT 1");
            $existing_variant_stmt->bind_param('ii', $customer['id'], $product_id);
            $existing_variant_stmt->execute();
            $existing_row = $existing_variant_stmt->get_result()->fetch_assoc();
            $existing_variant_stmt->close();
            $existing_variant_id = ($existing_row && $existing_row['variant_id'] !== null) ? (int)$existing_row['variant_id'] : null;

            if ($existing_row && $existing_variant_id !== $variant_id) {
                $unavailable[] = $display_name . ' (a different variant of this product is already in your cart)';
                continue;
            }

            // Never add more than what's actually in stock right now, even if
            // the customer originally ordered more of it.
            $qty_to_add = min((int)$item['quantity'], $available_stock);
            add_customer_cart_item((int)$customer['id'], $product_id, $qty_to_add, $variant_id);
            $added_count++;
        }

        if ($added_count > 0) {
            // This page's flash banner only has success/error styling - an order
            // that's partly reordered is still a success, just with a note.
            $_SESSION['message'] = $added_count . ' item' . ($added_count === 1 ? '' : 's') . ' added to your cart.'
                . (!empty($unavailable) ? ' Unavailable and skipped: ' . implode(', ', $unavailable) . '.' : '');
            $_SESSION['message_type'] = 'success';
            header('Location: ' . BASE_URL . '/customer/cart.php');
            exit();
        }

        $_SESSION['message'] = 'None of the items from that order are available right now' . (!empty($unavailable) ? ' (' . implode(', ', $unavailable) . ')' : '') . '.';
        $_SESSION['message_type'] = 'error';
    }

    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $reorder_order_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_return') {
    // Return / refund request on a received order (rules: app/helpers/return_refund_helper.php).
    csrf_require();
    $return_order_id = (int)($_POST['order_id'] ?? 0);
    $return_order = $return_order_id > 0 ? get_order_by_id($return_order_id, (int)$customer['id']) : null;
    if (!$return_order) {
        $_SESSION['message'] = 'That order could not be found.';
        $_SESSION['message_type'] = 'error';
        header('Location: ' . BASE_URL . '/customer/orders.php');
        exit();
    }
    $return_result = rr_submit($conn, $return_order, (int)$customer['id'], [
        'order_item_id' => $_POST['return_item_id'] ?? 0,
        'quantity'      => $_POST['return_quantity'] ?? 0,
        'reason'        => (string)($_POST['return_reason'] ?? ''),
        'details'       => (string)($_POST['return_details'] ?? ''),
        'resolution'    => (string)($_POST['return_resolution'] ?? ''),
    ], $_FILES['return_photo'] ?? null);
    $_SESSION['message'] = $return_result['message'];
    $_SESSION['message_type'] = $return_result['ok'] ? 'success' : 'error';
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $return_order_id . ($return_result['ok'] ? '#return-requests' : '&return=1'));
    exit();
}

$order = null;
$order_items = [];
$delivery_details = null;
$delivery_proof = null;
$pickup_photo = null;
$orders = [];
$customer_addresses = [];
$address_change_allowed = false;
if (isset($_GET['id'])) {
    $order_id = (int) $_GET['id'];
    $order = get_order_by_id($order_id, (int) $customer['id']);
    $address_change_allowed = $order
        && $order['fulfillment_type'] === 'delivery'
        && in_array($order['order_status'], ['pending', 'confirmed', 'processing'], true);
    $delivery_details = $order ? get_delivery_details_by_order_id($order['id']) : null;
    $delivery_proof = $order ? get_order_delivery_proof($order['id']) : null;
    // Photo the rider took when collecting the parcel at the shop (delivery orders only).
    $pickup_photo = $order && $order['fulfillment_type'] === 'delivery' ? (delivery_photos($conn, (int) $order['id'])['pickup'] ?? null) : null;
    if ($pickup_photo) {
        $rn = $conn->prepare("SELECT TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS name, u.username
                              FROM tbl_delivery d JOIN users u ON u.id = d.rider_id WHERE d.order_id = ? LIMIT 1");
        $rn->bind_param('i', $order_id);
        $rn->execute();
        $rr = $rn->get_result()->fetch_assoc();
        $rn->close();
        $pickup_photo['rider'] = $rr ? ($rr['name'] !== '' ? $rr['name'] : (string) $rr['username']) : '';
    }
    $return_requests = $order ? rr_get_for_order($conn, (int)$order['id']) : [];
    $return_check = $order ? rr_eligibility($conn, $order) : ['ok' => false];
    $return_items = $order && !empty($return_check['ok']) ? rr_returnable_items($conn, $order) : [];


    // --- DYNAMICALLY ENSURE DELIVERY RECORD & QR TOKEN FOR ANY ORDER ID ---
    // (shared with the e-receipt email handler below via ensure_order_delivery_token()
    //  so both always resolve to the exact same Delivery Confirmation QR)
    if ($order) {
        $delivery_details = ensure_order_delivery_token($order);
    }

    if ($order) {
        $order_items = get_order_items($order['id']);
    }

    $customer_addresses = [];
    if ($order && $order['fulfillment_type'] === 'delivery') {
        $address_stmt = $conn->prepare(
            "SELECT id, label, full_address, phone, latitude, longitude
             FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC"
        );
        $address_stmt->bind_param('i', $customer['id']);
        $address_stmt->execute();
        $customer_addresses = $address_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $address_stmt->close();
    }

    // Include the new QR helper
    require_once __DIR__ . '/../modules/admin/qr_helper.php';
} else {
    // ---- Order list: tab + date-range filter + pagination (display only; same data as before) ----
    $all_orders = get_customer_orders((int) $customer['id']);
    $return_map = rr_get_for_customer($conn, (int) $customer['id']); // order_id => return request

    // Tabs group the existing order statuses. "Return Refund" instead lists orders with a return
    // request (app/helpers/return_refund_helper.php) plus any refunded order.
    $tab_groups = [
        'all'        => ['label' => 'All',           'statuses' => null],
        'to_pay'     => ['label' => 'To Pay',        'statuses' => ['pending']],
        'to_ship'    => ['label' => 'To Ship',       'statuses' => ['confirmed', 'processing', 'ready_for_pickup', 'to_ship']],
        'to_receive' => ['label' => 'To Receive',    'statuses' => ['to_receive', 'out_for_delivery']],
        'completed'  => ['label' => 'Completed',     'statuses' => ['completed', 'reviews']],
        'cancelled'  => ['label' => 'Cancelled',     'statuses' => ['cancelled']],
        'returns'    => ['label' => 'Return Refund', 'statuses' => []],
    ];
    $range_options = ['all' => 'All time', '30' => 'Past 30 days', '180' => 'Past 6 months', '365' => 'Past 1 year'];
    $list_tab = is_string($_GET['tab'] ?? null) && isset($tab_groups[$_GET['tab']]) ? $_GET['tab'] : 'all';
    $list_range = is_string($_GET['range'] ?? null) && isset($range_options[$_GET['range']]) ? $_GET['range'] : 'all';

    $list_q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
    $list_q = mb_substr($list_q, 0, 100);

    $in_range = $all_orders;
    if ($list_range !== 'all') {
        $cutoff = strtotime('-' . (int) $list_range . ' days');
        $in_range = array_values(array_filter($in_range, static fn ($o) => strtotime((string) $o['created_at']) >= $cutoff));
    }
    if ($list_q !== '') {
        // Match the order number (with or without "#") or any product name in the order.
        $needle = ltrim($list_q, '#');
        $in_range = array_values(array_filter($in_range, static fn ($o) =>
            stripos((string) $o['order_number'], $needle) !== false
            || stripos((string) ($o['items_summary'] ?? ''), $list_q) !== false));
    }
    $in_tab = static function (array $o, string $key) use ($tab_groups, $return_map): bool {
        if ($key === 'returns') {
            return isset($return_map[(int) $o['id']]) || $o['order_status'] === 'refunded';
        }
        return $tab_groups[$key]['statuses'] === null || in_array($o['order_status'], $tab_groups[$key]['statuses'], true);
    };
    $tab_counts = [];
    foreach ($tab_groups as $key => $group) {
        $tab_counts[$key] = count(array_filter($in_range, static fn ($o) => $in_tab($o, $key)));
    }
    $shown_orders = array_values(array_filter($in_range, static fn ($o) => $in_tab($o, $list_tab)));

    $per_page = 5;
    $total_pages = max(1, (int) ceil(count($shown_orders) / $per_page));
    $list_page = min(max(1, (int) ($_GET['page'] ?? 1)), $total_pages);
    $orders = array_slice($shown_orders, ($list_page - 1) * $per_page, $per_page);

    // Products (with pictures) and review state for just the orders on this page - two queries, not one per order.
    $order_products = [];
    $reviewed_pairs = [];
    if ($orders) {
        $page_ids = array_map(static fn ($o) => (int) $o['id'], $orders);
        $marks = implode(',', array_fill(0, count($page_ids), '?'));
        $types = str_repeat('i', count($page_ids));

        $item_stmt = $conn->prepare(
            "SELECT oi.order_id, oi.product_id, oi.product_name, oi.variant_size, oi.quantity, oi.unit_price, oi.total_price, p.image_url
             FROM tbl_order_items oi
             LEFT JOIN tbl_product_inventory p ON oi.product_id = p.id
             WHERE oi.order_id IN ($marks) ORDER BY oi.id ASC"
        );
        $item_stmt->bind_param($types, ...$page_ids);
        $item_stmt->execute();
        foreach ($item_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $order_products[(int) $row['order_id']][] = $row;
        }
        $item_stmt->close();

        $rev_stmt = $conn->prepare("SELECT order_id, product_id FROM tbl_product_reviews WHERE customer_id = ? AND order_id IN ($marks)");
        $rev_types = 'i' . $types;
        $rev_args = array_merge([(int) $customer['id']], $page_ids);
        $rev_stmt->bind_param($rev_types, ...$rev_args);
        $rev_stmt->execute();
        foreach ($rev_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $reviewed_pairs[(int) $row['order_id'] . ':' . (int) $row['product_id']] = true;
        }
        $rev_stmt->close();
    }

    $list_url = static function (array $override = []) use ($list_tab, $list_range, $list_page, $list_q): string {
        $params = array_merge(['tab' => $list_tab, 'range' => $list_range, 'q' => $list_q, 'page' => $list_page], $override);
        if ($params['tab'] === 'all') { unset($params['tab']); }
        if ($params['range'] === 'all') { unset($params['range']); }
        if ($params['q'] === '') { unset($params['q']); }
        if ((int) $params['page'] <= 1) { unset($params['page']); }
        return BASE_URL . '/customer/orders.php' . ($params ? '?' . http_build_query($params) : '');
    };

    // Numbered pager window: 1 … 4 5 6 … 12 (numbers are page numbers, null = "…").
    $page_window = [];
    for ($p = 1; $p <= $total_pages; $p++) {
        if ($p === 1 || $p === $total_pages || abs($p - $list_page) <= 1) {
            $page_window[] = $p;
        } elseif (end($page_window) !== null) {
            $page_window[] = null;
        }
    }
}
?>
<?php $page_title = 'Your Orders'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php if (!empty($address_change_allowed)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<?php endif; ?>

<style>
    /* ==========================================================================
       Order details (.oc-*): page header, then a main card (status, items,
       delivery/pickup, payment, proof) and a side card (summary, actions,
       loyalty, documents, help). Sections are divided by hairlines inside
       each card rather than boxed individually. Colors are UI-kit tokens
       (assets/css/ui-kit.css), so dark mode needs no separate rules.
       Breakpoints are container queries on the real content width, so the
       layout is right with the sidebar expanded or collapsed and at any zoom.
       ========================================================================== */
    .oc {
        min-width: 0;
        container: oc / inline-size;
    }
    .oc *, .oc *::before, .oc *::after { box-sizing: border-box; }
    /* The detail view has its own header; the list-page heading above it is redundant here. */
    .customer-panel:has(.oc) > .section-header { display: none; }
    .oc-shell { background: none; border: 0; padding: 0; box-shadow: none; }

    /* Header */
    .oc-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 10px 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .oc-head-main { min-width: 0; }
    .oc .oc-back { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: var(--fs-sm); font-weight: 500; color: var(--muted); text-decoration: none; }
    .oc .oc-back::before { content: "\2190"; }
    .oc .oc-back:hover { color: var(--link); }
    .oc .oc-title { margin: 0; font-size: var(--fs-2xl); font-weight: 600; line-height: 1.25; color: var(--text); overflow-wrap: anywhere; letter-spacing: -.01em; }
    .oc .oc-date { margin-top: 4px; font-size: var(--fs-base); color: var(--muted); }
    .oc-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .oc .oc-chip {
        display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: var(--radius-pill);
        font-size: var(--fs-xs); font-weight: 600; white-space: nowrap;
        background: var(--surface); border: 1px solid var(--border); color: var(--text-2);
    }
    .oc .oc-chip-ok { background: var(--success-soft); border-color: transparent; color: var(--success); }
    .oc .oc-chip-warn { background: var(--warning-soft); border-color: transparent; color: var(--warning); }
    .oc .oc-chip-muted { background: var(--surface-2); color: var(--muted); }
    .oc .oc-chip-bad { background: var(--danger-soft); border-color: transparent; color: var(--danger); }

    /* Layout: single column on phones; main + side from ~720px of real content width */
    .oc-body { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; }
    .oc-main, .oc-side {
        display: flex; flex-direction: column; min-width: 0;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm);
    }
    @container oc (min-width: 720px) {
        .oc-body { grid-template-columns: minmax(0, 1fr) minmax(270px, 320px); gap: 18px; }
        .oc-side { position: sticky; top: calc(var(--topbar-h) + 16px); }
    }
    @container oc (min-width: 1100px) {
        .oc-body { grid-template-columns: minmax(0, 1fr) 350px; gap: 22px; }
    }

    /* Sections live inside the column card, separated by hairlines */
    .oc .oc-sec { background: none; border: 0; border-radius: 0; box-shadow: none; padding: 18px 20px; min-width: 0; }
    .oc .oc-sec + .oc-sec { border-top: 1px solid var(--border); }
    .oc .oc-h {
        margin: 0 0 12px; padding: 0; border: 0; display: flex; align-items: center; gap: 8px;
        font-size: var(--fs-xs); font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--muted);
    }
    .oc .oc-count { margin-left: auto; font-size: var(--fs-xs); font-weight: 500; letter-spacing: 0; text-transform: none; color: var(--muted); }
    /* Quiet sections (documents, help): same info, lighter visual weight */
    .oc .oc-sec-quiet { background: var(--surface-2); padding: 16px 20px; }
    .oc-side .oc-sec:last-child { border-radius: 0 0 var(--radius) var(--radius); }

    /* Key / value rows */
    .oc-row { display: flex; justify-content: space-between; align-items: baseline; gap: 14px; padding: 4px 0; font-size: var(--fs-base); }
    .oc-row.oc-stack { flex-direction: column; gap: 2px; }
    .oc .oc-k { color: var(--muted); font-weight: 400; flex: 0 0 auto; }
    .oc .oc-v { color: var(--text); font-weight: 500; text-align: right; min-width: 0; }
    .oc-row.oc-stack .oc-v { text-align: left; }
    .oc-wrap { overflow-wrap: anywhere; line-height: 1.5; }
    .oc .oc-normal { font-weight: 400; }
    .oc-mono { font-family: ui-monospace, Consolas, monospace; font-size: var(--fs-sm); overflow-wrap: anywhere; }
    .oc .oc-discount .oc-k, .oc .oc-discount .oc-v { color: var(--success); }
    .oc .oc-earned { color: var(--gold); font-weight: 600; }
    .oc .oc-total { margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--border); }
    .oc .oc-total .oc-k, .oc .oc-total .oc-v { color: var(--text); font-size: var(--fs-lg); font-weight: 600; }
    .oc .oc-total .oc-v { font-weight: 700; }
    .oc .oc-hint { margin: 6px 0 0; font-size: var(--fs-xs); line-height: 1.45; color: var(--muted); }
    .oc .oc-note-box { margin-top: 8px; padding: 8px 12px; font-size: var(--fs-sm); border-radius: var(--radius-sm); background: var(--surface-2); color: var(--text); }

    /* Items */
    .oc-items { list-style: none; margin: 0; padding: 0; max-height: 360px; overflow: auto; -webkit-overflow-scrolling: touch; }
    .oc-item { display: grid; grid-template-columns: 56px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
    .oc-item:first-child { padding-top: 0; }
    .oc-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .oc .oc-img { width: 56px; height: 56px; border-radius: 10px; object-fit: cover; background: var(--surface-2); border: 1px solid var(--border); }
    .oc-item-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; align-items: flex-start; }
    .oc .oc-item-name { font-size: var(--fs-base); font-weight: 500; line-height: 1.35; overflow-wrap: anywhere; color: var(--text); }
    .oc .oc-item-meta { font-size: var(--fs-xs); color: var(--muted); }
    .oc-item-price { display: flex; flex-direction: column; align-items: flex-end; text-align: right; white-space: nowrap; }
    .oc .oc-item-price strong { font-size: var(--fs-base); font-weight: 600; color: var(--text); }
    .oc .oc-item-price small { font-size: var(--fs-xs); color: var(--muted); }
    .oc .oc-reviewed { margin-top: 2px; font-size: var(--fs-xs); color: var(--success); font-weight: 500; }
    .oc .oc-review-btn {
        margin-top: 4px; min-height: 0; padding: 4px 10px; display: inline-flex; line-height: 1.4;
        font-size: var(--fs-xs); font-weight: 600; border-radius: 7px;
        background: var(--gold-soft); color: var(--gold); border: 1px solid color-mix(in srgb, var(--gold) 25%, transparent);
    }
    .oc .oc-review-btn:hover { background: var(--gold-soft); border-color: var(--gold); }

    /* Status: plain-English note first, then the step tracker (scrolls sideways on phones) */
    .oc-status-sec { display: flex; flex-direction: column; }
    .oc-status-note { order: -1; margin: 0 0 18px; display: flex; flex-direction: column; gap: 2px; }
    .oc .oc-status-note strong { font-size: var(--fs-xl); font-weight: 600; color: var(--text); }
    .oc .oc-status-note span { font-size: var(--fs-base); line-height: 1.5; color: var(--text-2); }
    .oc-track-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; padding: 4px 2px 6px; margin: 0 -2px; }
    .oc-track { list-style: none; margin: 0; padding: 0; display: flex; position: relative; min-width: calc(var(--steps, 6) * 78px); }
    .oc-step { position: relative; flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
    .oc-step::before { content: ''; position: absolute; top: 12px; left: -50%; width: 100%; height: 2px; background: var(--border); }
    .oc-step:first-child::before { display: none; }
    .oc-step.is-done::before, .oc-step.is-current::before { background: var(--success); }
    .oc .oc-step-dot {
        position: relative; z-index: 1; width: 26px; height: 26px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600;
        border: 2px solid var(--border-strong); background: var(--surface); color: var(--muted);
    }
    .oc .oc-step.is-done .oc-step-dot { background: var(--success); border-color: var(--success); color: #fff; }
    .oc .oc-step.is-current .oc-step-dot { background: var(--primary); border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-ring); }
    .oc-step.is-current .oc-step-dot::after { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--dk-surface, #fff); }
    .oc .oc-step-label { font-size: var(--fs-xs); line-height: 1.25; font-weight: 500; color: var(--text-2); overflow-wrap: break-word; }
    .oc .is-todo .oc-step-label { color: var(--faint); font-weight: 400; }
    .oc .is-current .oc-step-label { color: var(--link); font-weight: 600; }
    .oc-cancelled { display: flex; flex-direction: column; gap: 3px; padding: 12px 14px; border-radius: var(--radius-sm); background: var(--danger-soft); }
    .oc .oc-cancelled strong { color: var(--danger); font-size: var(--fs-md); font-weight: 600; }
    .oc .oc-cancelled span { color: var(--text-2); font-size: var(--fs-base); }

    /* Summary */
    .oc-summary { padding-bottom: 18px; }
    .oc-summary .oc-v { white-space: nowrap; }
    /* The side card already offers Contact Staff, so the floating shortcut would only cover content. */
    body:has(.oc) .contact-staff-fab { display: none; }

    /* Documents: e-receipt + delivery QR (QR keeps its white quiet zone so it always scans) */
    .oc-inline-form { margin: 0; }
    .oc-qr-body { display: flex; align-items: center; gap: 14px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
    .oc .oc-qr-img { width: 116px; height: 116px; flex: 0 0 116px; padding: 6px; border-radius: var(--radius-sm); background: var(--dk-surface, #fff); border: 1px solid var(--border); }
    .oc-qr-text { min-width: 0; }
    .oc .oc-qr-ref { font-size: var(--fs-sm); font-weight: 600; color: var(--text); }

    /* Buttons */
    .oc-act-order { display: flex; flex-direction: column; gap: 8px; }
    .oc-act-order .oc-h { margin-bottom: 4px; }
    .oc .oc-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 7px; width: 100%; min-height: 40px; margin: 0;
        padding: 8px 14px; border-radius: var(--radius-sm); border: 1px solid transparent;
        font-size: var(--fs-base); font-weight: 500; text-align: center; text-decoration: none; cursor: pointer;
    }
    .oc .oc-btn-auto { width: auto; }
    .oc .oc-btn-primary { min-height: 44px; background: var(--primary); color: var(--on-primary); }
    .oc .oc-btn-primary:hover { background: var(--primary-hover); }
    .oc .oc-btn-danger { min-height: 44px; background: var(--surface); border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
    .oc .oc-btn-danger:hover { background: var(--danger-soft); }
    .oc .oc-btn-outline { min-height: 44px; background: var(--surface); border-color: var(--primary); color: var(--primary); }
    .oc .oc-btn-outline:hover { background: var(--primary); color: var(--on-primary); }
    .oc .oc-addr-btns.delivery-address-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .oc .oc-addr-btns .oc-btn { width: auto; flex: 0 1 auto; }
    .oc .oc-btn-secondary { min-height: 36px; padding: 6px 12px; font-size: var(--fs-sm); background: var(--surface); border-color: var(--border-strong); color: var(--text); }
    .oc .oc-btn-secondary:hover { background: var(--surface-2); }
    .oc-addr-btns + small { display: block; margin-top: 6px; }
    .oc .oc-btn-doc { background: var(--surface); border-color: var(--border-strong); color: var(--text); }
    .oc .oc-btn-doc::before { content: ""; width: 14px; height: 14px; background: currentColor; -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2'%3E%3Cpath d='M4 4h16v16H4z'/%3E%3Cpath d='m4 7 8 6 8-6'/%3E%3C/svg%3E") center / contain no-repeat; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2'%3E%3Cpath d='M4 4h16v16H4z'/%3E%3Cpath d='m4 7 8 6 8-6'/%3E%3C/svg%3E") center / contain no-repeat; }
    .oc .oc-btn-doc:hover { background: var(--surface-3); }
    .oc-help-btns { display: flex; flex-wrap: wrap; gap: 8px; }
    .oc-help-btns .oc-btn { flex: 1 1 130px; width: auto; }
    .oc .oc-btn-help { min-height: 38px; background: var(--surface); border-color: var(--border-strong); color: var(--link); }
    .oc .oc-btn-help:hover { background: var(--primary-soft); }

    /* Proof of delivery */
    .oc-pod-body { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
    .oc-pod-img { flex: 0 0 auto; }
    .oc .oc-pod-img img { display: block; width: 140px; max-width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--border); cursor: zoom-in; }
    .oc-pod-info { flex: 1 1 200px; min-width: 0; }

    /* Existing pieces still used by the address dialog / script */
    .info-label { color: var(--muted); font-weight: 500; }
    .delivery-address-value { overflow-wrap: anywhere; line-height: 1.5; }
    .address-change-modal-content { width: min(620px, calc(100% - 30px)); }
    .item-img { object-fit: cover; }

    @container oc (max-width: 460px) {
        .oc .oc-sec { padding: 16px; }
        .oc-item { grid-template-columns: 48px minmax(0, 1fr) auto; gap: 10px; }
        .oc .oc-img { width: 48px; height: 48px; }
        .oc .oc-title { font-size: var(--fs-xl); }
        .oc .oc-qr-img { width: 108px; height: 108px; flex-basis: 108px; }
    }
    /* Modal Header/Footer Styling */
    .modal-header {
        padding: 15px 25px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .modal-header h3 { margin: 0; }
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 15px;
        background: rgba(15, 23, 42, 0.55);
    }
    .modal-content {
        background: var(--surface);
        border-radius: 12px;
        width: 600px;
        max-width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
    }
    .cancel-modal-content .close-modal {
        position: static;
        border: 0;
        background: transparent;
        color: var(--muted);
        font-size: 26px;
        line-height: 1;
        cursor: pointer;
    }
    .modal-footer {
        padding: 15px 25px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }
    .cancel-modal-content {
        width: min(560px, calc(100% - 30px));
    }
    /* Return / Refund: request form + status pills (list cards and the order page) */
    .rr-form { display: flex; flex-direction: column; gap: 6px; }
    .rr-form label { font-weight: 600; margin-top: 8px; }
    .rr-form select, .rr-form textarea, .rr-form input[type="file"] { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; }
    .rr-form textarea { min-height: 110px; resize: vertical; }
    .rr-note { margin: 12px 0 0; padding: 10px 12px; border-radius: 8px; font-size: 0.85rem; background: var(--surface-2); border: 1px solid var(--border); color: var(--muted); }
    .rr-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
    .rr-warning { background: var(--warning-soft); color: var(--warning); }
    .rr-info { background: var(--info-soft, var(--surface-2)); color: var(--info, var(--link)); }
    .rr-danger { background: var(--danger-soft); color: var(--danger); }
    .rr-success { background: var(--success-soft); color: var(--success); }
    .rr-sec .rr-pill { margin-bottom: 10px; }
    .rr-item + .rr-item { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
    .rr-form input[type="number"] { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; }
    .rr-choice { display: flex; gap: 18px; flex-wrap: wrap; }
    .rr-choice label { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; margin: 0; }
    .rr-estimate { margin: 10px 0 0; font-size: 0.9rem; }
    /* Landscape: two columns side by side, stacked again on phones */
    .cancel-modal-content.rr-modal { width: min(960px, calc(100% - 30px)); max-width: none; }
    .rr-modal .rr-form { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 4px 28px; align-items: start; }
    .rr-modal .rr-order { grid-column: 1 / -1; }
    .rr-col { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .rr-modal .rr-form textarea { min-height: 150px; }
    @media (max-width: 760px) { .rr-modal .rr-form { grid-template-columns: 1fr; } }
    .status-refunded { background: var(--info-soft, var(--surface-2)); color: var(--info, var(--link)); }
    .cancellation-warning {
        margin: 15px 0 0;
        padding: 12px 14px;
        color: var(--warning);
        background: var(--warning-soft);
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 0.9rem;
    }
    .cancel-reasons { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 12px; }
    .cancel-reason { padding: 8px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-2); font: inherit; font-size: .85rem; font-weight: 600; cursor: pointer; line-height: 1.2; }
    /* Soft hover (the site-wide button:hover would paint it solid blue and hide the text). */
    .cancel-reason:hover, .cancel-reason:focus-visible { border-color: var(--dk-primary, #6366f1); background-color: var(--primary-soft); color: var(--text); }
    .cancel-reason[aria-pressed="true"]:hover { background-color: var(--danger-soft, #fef2f2); color: var(--danger, #b91c1c); }
    .cancel-reason[aria-pressed="true"] { border-color: var(--danger, #ef4444); background: var(--danger-soft, #fef2f2); color: var(--danger, #b91c1c); }
    #cancellation_reason {
        display: block;
        width: 100%;
        min-height: 130px;
        box-sizing: border-box;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font: inherit;
        line-height: 1.5;
        resize: vertical;
    }
    #cancellation_reason:focus {
        outline: 2px solid rgba(99, 102, 241, 0.2);
        border-color: var(--dk-primary, #6366f1);
    }
    .delivery-address-value { overflow-wrap: anywhere; line-height: 1.5; }
    .delivery-address-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
    .address-change-modal-content { width: min(620px, calc(100% - 30px)); }
    .address-change-body { padding: 25px; }
    .address-change-body select, .address-change-body input[type="text"] { width: 100%; box-sizing: border-box; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; }
    .address-change-body label { display: block; margin: 0 0 6px; color: var(--text-2); font-weight: 600; font-size: 0.9rem; }
    .address-change-map { height: 280px; margin-top: 15px; border: 1px solid var(--border); border-radius: 8px; }
    .address-change-divider { margin: 18px 0; text-align: center; color: var(--muted); font-weight: 700; }
    .address-change-status { margin-top: 10px; font-size: 0.9rem; }
    /* Status Pill Colors */
    .status-pill { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: var(--fs-xs); font-weight: 600; background: var(--neutral-soft); color: var(--text-2); }
    .status-pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .status-pending { background: var(--warning-soft); color: var(--warning); }
    .status-confirmed, .status-out_for_delivery { background: var(--info-soft); color: var(--info); }
    .status-processing { background: var(--primary-soft); color: var(--link); }
    .status-ready_for_pickup, .status-to_ship, .status-to_receive { background: var(--accent-soft); color: var(--accent); }
    .status-reviews { background: var(--gold-soft); color: var(--gold); }
    .status-completed { background: var(--success-soft); color: var(--success); }
    .status-cancelled { background: var(--danger-soft); color: var(--danger); }

    /* ==========================================================================
       Order list (.ol-*): tabs + date range, then one card per order.
       Same approach as .oc above: --customer-* variables (flip in dark mode),
       text colors scoped `.customer-panel .ol` to out-rank the dark-mode
       `color: inherit` rule, container queries so it fits any width/zoom.
       ========================================================================== */
    .customer-panel .ol {
        --ol-surface: var(--customer-surface, #fff);
        --ol-surface-2: var(--customer-surface-2, #f8fafc);
        --ol-text: var(--customer-text, #1e293b);
        --ol-muted: var(--customer-muted, #64748b);
        --ol-border: var(--customer-border, #e2e8f0);
        --ol-link: var(--customer-link, var(--primary));
        min-width: 0;
        container: ol / inline-size;
    }
    .ol *, .ol *::before, .ol *::after { box-sizing: border-box; }
    .ol-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    /* Status tabs: one full-width strip, equal columns, underline on the active tab (scrolls sideways on very narrow screens) */
    .ol-tabs { display: flex; margin-bottom: 14px; background: var(--ol-surface); border: 1px solid var(--ol-border); border-radius: 12px; overflow-x: auto; overflow-y: hidden; scrollbar-width: thin; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06); }
    .customer-panel .ol .ol-tab { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-width: 96px; padding: 14px 16px 12px; font-size: 0.88rem; font-weight: 600; text-decoration: none; white-space: nowrap; color: var(--ol-text); border-bottom: 3px solid transparent; }
    .customer-panel .ol .ol-tab:hover { background: var(--ol-surface-2); color: var(--ol-link); }
    .customer-panel .ol .ol-tab.is-active { color: var(--link); border-bottom-color: var(--dk-primary, #4f46e5); font-weight: 800; }
    html[data-theme="dark"] .customer-panel .ol .ol-tab.is-active { color: var(--primary); border-bottom-color: var(--primary); }
    .customer-panel .ol .ol-tab-count { font-size: 0.68rem; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: var(--ol-surface-2); border: 1px solid var(--ol-border); color: var(--ol-muted); }
    .customer-panel .ol .ol-tab.is-active .ol-tab-count { background: var(--dk-primary, #4f46e5); border-color: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }

    /* Search + date range */
    .ol-filter { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
    .ol-search { position: relative; flex: 1 1 280px; min-width: 0; }
    .ol-search input { width: 100%; min-height: 42px; padding: 9px 38px 9px 40px; border-radius: 10px; font: inherit; font-size: 0.9rem; background: var(--ol-surface); border: 1.5px solid var(--ol-border); color: var(--ol-text); }
    .ol-search input::placeholder { color: var(--ol-muted); }
    .ol-search input:focus { outline: none; border-color: var(--ol-link); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
    .customer-panel .ol .ol-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--ol-muted); pointer-events: none; font-size: 0.9rem; }
    .customer-panel .ol .ol-search-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; line-height: 1; text-decoration: none; color: var(--ol-muted); }
    .customer-panel .ol .ol-search-clear:hover { background: var(--ol-surface-2); color: var(--ol-text); }
    .ol-filter select { appearance: auto; padding: 9px 12px; min-height: 42px; border-radius: 10px; font: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer;
        background: var(--ol-surface); border: 1.5px solid var(--ol-border); color: var(--ol-text); }
    .ol-filter select:focus { outline: none; border-color: var(--ol-link); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
    .ol-filter .ol-btn { min-height: 42px; padding: 6px 22px; }
    .customer-panel .ol .ol-result-note { margin: -4px 2px 14px; font-size: 0.82rem; color: var(--ol-muted); }

    /* Each order is its own clearly outlined card; its header band and every product are outlined too, so orders never read as one block. */
    .customer-panel .ol { --ol-edge: color-mix(in srgb, var(--ol-text) 24%, var(--ol-border)); }
    .ol-card { background: var(--ol-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 0 18px 14px; margin-bottom: 16px; overflow: hidden; box-shadow: var(--shadow-sm); transition: border-color .15s, box-shadow .15s; }
    .ol-card:hover { border-color: var(--border-strong); box-shadow: var(--shadow); }
    .ol-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px 24px; flex-wrap: wrap; margin: 0 -18px; padding: 14px 18px; background: var(--surface-2); border-bottom: 1px solid var(--border); }
    .ol-meta { display: grid; grid-template-columns: max-content max-content minmax(0, 1fr); gap: 8px 40px; margin: 0; flex: 1 1 420px; min-width: 0; }
    .ol-meta > div { min-width: 0; }
    .customer-panel .ol .ol-meta dt { font-size: 0.75rem; color: var(--ol-muted); margin: 0 0 3px; font-weight: 500; }
    .customer-panel .ol .ol-meta dd { margin: 0; font-size: 0.88rem; font-weight: 700; color: var(--ol-text); }
    .ol-ship dd { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ol-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; margin-left: auto; }
    .customer-panel .ol .ol-no { font-size: 0.85rem; color: var(--ol-muted); }
    .customer-panel .ol .ol-no strong { font-size: 0.95rem; color: var(--ol-text); letter-spacing: 0.01em; overflow-wrap: anywhere; }
    .ol-btns { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .ol-form { margin: 0; }
    .ol .ol-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 6px 16px; border-radius: 8px; font: inherit; font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid transparent; white-space: nowrap; margin: 0; }
    .customer-panel .ol .ol-btn-primary { background: var(--dk-primary, #4f46e5); border-color: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }
    .customer-panel .ol .ol-btn-primary:hover { background: var(--dk-primary-hover, #4338ca); border-color: var(--dk-primary-hover, #4338ca); }
    .customer-panel .ol .ol-btn-ghost { background: var(--ol-surface); border-color: var(--ol-border); color: var(--ol-text); }
    .customer-panel .ol .ol-btn-ghost:hover { border-color: var(--ol-link); color: var(--ol-link); }
    /* System-purple outline (Buy Again, Return / Refund); fills in on hover */
    .customer-panel .ol .ol-btn-outline { background: var(--ol-surface); border-color: var(--dk-primary, #4f46e5); color: var(--dk-primary, #4f46e5); }
    .customer-panel .ol .ol-btn-outline:hover { background: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }
    html[data-theme="dark"] .customer-panel .ol .ol-btn-outline { border-color: var(--primary); color: var(--primary); }
    html[data-theme="dark"] .customer-panel .ol .ol-btn-outline:hover { background: var(--primary); color: var(--on-primary, #fff); }
    /* Footer row under the items: note on the left, buttons on the right */
    .ol-foot { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px 12px; margin: 4px -18px -14px; padding: 12px 18px; border-top: 1px solid var(--border); background: var(--ol-surface-2); }
    .customer-panel .ol .ol-foot-note { margin-right: auto; font-size: 0.78rem; color: var(--ol-muted); }
    .customer-panel .ol .ol-foot-note i { color: var(--dk-primary, #4f46e5); margin-right: 4px; }
    html[data-theme="dark"] .customer-panel .ol .ol-foot-note i { color: var(--primary); }

    .customer-panel .ol .ol-notice { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 8px 12px; border-radius: 8px; font-size: 0.8rem; text-decoration: none;
        background: var(--dk-warning-soft, #fefce8); border: 1px solid #fde68a; color: var(--warning); }
    .customer-panel .ol .ol-notice i { color: #f59e0b; flex: 0 0 auto; }
    .ol-notice span { flex: 1 1 auto; min-width: 0; }
    .customer-panel .ol .ol-notice strong { margin-left: auto; flex: 0 0 auto; color: var(--warning); text-decoration: underline; }
    html[data-theme="dark"] .customer-panel .ol .ol-notice { background: #33290f; border-color: #5c4516; color: var(--warning); }
    html[data-theme="dark"] .customer-panel .ol .ol-notice strong { color: #fde68a; }

    .ol-state { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 12px; margin: 14px 0 4px; }
    .customer-panel .ol .ol-state-title { font-size: 1rem; font-weight: 800; color: var(--ol-text); }
    .customer-panel .ol .ol-state-note { font-size: 0.82rem; color: var(--ol-muted); }
    .customer-panel .ol .ol-pay { margin-left: auto; font-size: 0.75rem; font-weight: 600; color: var(--ol-muted); white-space: nowrap; }

    .ol-items { list-style: none; margin: 0; padding: 0; }
    .ol-item { display: flex; gap: 14px; align-items: flex-start; padding: 12px 0; margin: 0; border: 0; border-top: 1px solid var(--border); border-radius: 0; background: none; }
    .ol-item:first-child { border-top: 0; }
    .ol .ol-thumb { flex: 0 0 76px; width: 76px; height: 76px; border-radius: 10px; overflow: hidden; background: var(--ol-surface-2); border: 1px solid var(--ol-border); display: block; }
    .ol .ol-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ol-item-main { display: flex; flex-direction: column; gap: 4px; min-width: 0; padding-top: 2px; }
    .customer-panel .ol .ol-item-name { font-size: 0.92rem; line-height: 1.35; color: var(--ol-text); overflow-wrap: anywhere; }
    .customer-panel .ol .ol-item-meta { font-size: 0.78rem; color: var(--ol-muted); }
    .ol-item-links { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 4px; font-size: 0.78rem; font-weight: 600; }
    .customer-panel .ol .ol-item-links a, .customer-panel .ol .ol-more a { color: var(--ol-link); text-decoration: none; }
    .customer-panel .ol .ol-item-links a:hover, .customer-panel .ol .ol-more a:hover { text-decoration: underline; }
    .customer-panel .ol .ol-more { margin-top: 10px; padding: 9px 12px; border: 1px dashed var(--border-strong); border-radius: var(--radius-sm); text-align: center; font-size: 0.8rem; font-weight: 600; color: var(--ol-muted); }

    /* Numbered pager:  ‹ 1 2 3 … 9 › */
    .ol-pager { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 6px; margin: 8px 0 4px; }
    .customer-panel .ol .ol-pg { min-width: 38px; height: 38px; padding: 0 10px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 0.9rem; font-weight: 700; text-decoration: none;
        background: var(--ol-surface); border: 1.5px solid var(--ol-border); color: var(--ol-text); }
    .customer-panel .ol a.ol-pg:hover { border-color: var(--ol-link); color: var(--ol-link); }
    .customer-panel .ol .ol-pg.is-current { background: var(--dk-primary, #4f46e5); border-color: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.35); }
    .customer-panel .ol .ol-pg-arrow { font-size: 1.3rem; line-height: 1; padding-bottom: 3px; }
    .customer-panel .ol .ol-pg.is-off { opacity: 0.4; }
    .customer-panel .ol .ol-pg-gap { border-color: transparent; background: transparent; min-width: 22px; padding: 0; color: var(--ol-muted); }
    .customer-panel .ol .ol-pager-note { margin: 6px 0 2px; text-align: center; font-size: 0.78rem; color: var(--ol-muted); }
    .ol-empty { text-align: center; padding: 40px 16px; border: 1px dashed var(--ol-border); border-radius: 12px; }
    .customer-panel .ol .ol-empty i { font-size: 2rem; color: var(--ol-muted); }
    .customer-panel .ol .ol-empty p { margin: 8px 0 14px; color: var(--ol-muted); }

    @container ol (max-width: 640px) {
        .ol-card { padding: 0 14px 14px; }
        .ol-top { margin: 0 -14px; padding: 12px 14px; }
        .ol-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 16px; flex-basis: 100%; }
        .ol-ship { grid-column: 1 / -1; }
        .ol-side { flex-direction: row; justify-content: space-between; align-items: center; width: 100%; margin-left: 0; flex-wrap: wrap; }
        .ol-btns { justify-content: flex-start; }
        .ol-foot { margin: 4px -14px -14px; padding: 12px 14px; }
        .ol-foot .ol-btns { flex: 1 1 100%; }
        .ol-foot .ol-btns > *, .ol-foot .ol-btns .ol-btn { flex: 1 1 0; }
        .customer-panel .ol .ol-pay { margin-left: 0; flex-basis: 100%; }
        .ol .ol-thumb { flex-basis: 60px; width: 60px; height: 60px; }
        .ol-filter select { flex: 1 1 140px; }
        .ol-filter .ol-btn { flex: 1 1 100px; }
        .customer-panel .ol .ol-tab { min-width: 0; padding: 12px 14px 10px; font-size: 0.82rem; }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Your Orders</h2>
        <p>Track fulfillment status, delivery details, and order history.</p>
    </div>
    <?php if ($order): ?>
        <?php
            $address_change_allowed = $order['fulfillment_type'] === 'delivery'
                && in_array($order['order_status'], ['pending', 'confirmed', 'processing'], true);
            $flash_message = $_SESSION['message'] ?? '';
            $flash_type = $_SESSION['message_type'] ?? 'success';
            unset($_SESSION['message'], $_SESSION['message_type']);
        ?>
        <?php if ($flash_message): ?>
            <div class="alert-message alert-<?php echo $flash_type === 'error' ? 'error' : 'success'; ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($flash_message); ?></div>
        <?php endif; ?>
        <?php
            $status = $order['order_status'];
            $is_pickup = $order['fulfillment_type'] === 'pickup';
        ?>

        <?php
            // ---- Display-only helpers (no queries, no business logic) ----
            $pm = strtolower((string)$order['payment_method']);
            $pm_names  = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
            $pm_short  = ['cod' => 'COD', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
            $pm_name   = $pm_names[$pm] ?? strtoupper($pm);
            $pm_badge  = $pm_short[$pm] ?? strtoupper($pm);
            if ($status === 'cancelled') {
                $pay_status = 'Cancelled'; $pay_tone = 'muted'; $amount_label = 'Order total';
            } else {
                // Payment status is its own field (never inferred from the order status).
                $ps = (string) ($order['payment_status'] ?? 'unpaid');
                $paid = $ps === 'paid';
                $pay_map = [
                    'paid' => ['Paid', 'ok'], 'pending' => ['Payment pending', 'warn'], 'pending_verification' => ['Pending verification', 'warn'],
                    'review' => ['Under review', 'warn'], 'failed' => ['Payment failed', 'bad'], 'expired' => ['Payment expired', 'bad'],
                    'cancelled' => ['Not paid', 'muted'], 'refunded' => ['Refunded', 'muted'],
                ];
                $unpaid_label = $pm === 'cod' ? 'Pay the rider on delivery' : ($pm === 'pay_at_shop' ? 'Pay at the shop' : 'Not paid yet');
                [$pay_status, $pay_tone] = $pay_map[$ps] ?? [$unpaid_label, 'warn'];
                if ($ps === 'unpaid' && $pm === 'cod' && in_array($status, ['completed', 'reviews'], true)) {
                    [$pay_status, $pay_tone] = ['Paid to the rider', 'ok']; // collected on delivery; the shop settles it with the rider
                }
                $amount_label = $paid ? 'Amount paid' : 'Amount due';
            }
            // Online (PayMongo) order that still needs a completed payment -> link to the payment page.
            $pay_link = in_array($pm, ['gcash', 'bank'], true) && ($order['payment_provider'] ?? '') !== 'manual'
                && in_array((string) ($order['payment_status'] ?? ''), ['unpaid', 'pending', 'failed', 'expired', 'cancelled'], true)
                && !in_array($status, ['cancelled', 'refunded'], true);
            $show_qr = $delivery_details && $status !== 'cancelled';
            $can_cancel = in_array($status, ['pending', 'confirmed'], true);
            $can_reorder = $status === 'completed';
            $can_return = !empty($return_check['ok']);
            $can_contact_rider = !$is_pickup && !empty($order['rider_id']) && in_array($status, ['to_ship', 'to_receive', 'out_for_delivery'], true);
            $item_name = static function (array $item): string {
                // the stored name already ends with "(variant)" for variant products - show the variant once
                $name = trim((string)$item['product_name']);
                $variant = trim((string)($item['variant_size'] ?? ''));
                if ($variant !== '' && substr($name, -strlen(' (' . $variant . ')')) === ' (' . $variant . ')') {
                    $name = rtrim(substr($name, 0, -strlen(' (' . $variant . ')')));
                }
                return $name;
            };

            // ---- Progress tracker: the applicable stages of the existing workflow (visual only) ----
            $flow = $is_pickup
                ? ['pending' => 'Order Placed', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'ready_for_pickup' => 'Ready for Pickup', 'completed' => 'Picked Up']
                : ['pending' => 'Order Placed', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'to_ship' => 'To Ship', 'to_receive' => 'To Receive', 'out_for_delivery' => 'Out for Delivery', 'completed' => 'Delivered'];
            $flow_keys = array_keys($flow);
            $cur_key = $status === 'reviews' ? 'completed' : $status;
            if (!isset($flow[$cur_key])) {
                // a status that isn't a stage of this order type (e.g. "ready for pickup" on a delivery order): closest stage
                $cur_key = $is_pickup ? 'ready_for_pickup' : ($cur_key === 'ready_for_pickup' ? 'to_ship' : 'pending');
            }
            $cur_idx = (int)array_search($cur_key, $flow_keys, true);
            $is_final = $cur_key === 'completed';
            $status_desc = [
                'pending' => in_array($pm, ['gcash', 'bank'], true)
                    ? 'We received your order and are verifying your payment.'
                    : 'We received your order and are waiting for the shop to confirm it.',
                'confirmed' => 'Your order is confirmed and will be prepared soon.',
                'processing' => 'Your order is currently being prepared by the shop.',
                'ready_for_pickup' => 'Your order is ready. Please pick it up at the shop on your scheduled date and time.',
                'to_ship' => 'Your order is packed and waiting for the rider to pick it up.',
                'to_receive' => 'Your rider has your order. Please have your Delivery Confirmation QR ready.',
                'out_for_delivery' => 'Your order is currently with the rider and is on its way to you.',
                'completed' => $is_pickup ? 'Your order was picked up. Thank you for shopping with us!' : 'Your order was delivered. Thank you for shopping with us!',
            ][$cur_key] ?? '';

            // Loyalty points are credited once the order reaches fulfillment (same qualifying statuses as loyalty_helper.php).
            $points_earned = (float)$order['loyalty_points_earned'];
            if ($status === 'cancelled') { $points_state = 'Not credited (order cancelled)'; }
            elseif ($status === 'refunded') { $points_state = 'Removed (order refunded)'; }
            elseif (in_array($status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed', 'reviews'], true)) { $points_state = 'Credited'; }
            else { $points_state = 'Pending - credited once your order is being processed'; }
        ?>
        <div class="oc">
          <div class="oc-shell">
            <!-- ORDER HEADER -->
            <div class="oc-head">
                <div class="oc-head-main">
                    <a class="oc-back" href="<?php echo BASE_URL; ?>/customer/orders.php">Back to Orders</a>
                    <h3 class="oc-title">Order #<?php echo htmlspecialchars($order['order_number']); ?></h3>
                    <div class="oc-date"><?php echo htmlspecialchars(date('F j, Y · g:i A', strtotime($order['created_at']))); ?></div>
                </div>
                <div class="oc-chips">
                    <span class="status-pill status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></span>
                    <span class="oc-chip"><?php echo $is_pickup ? 'Pickup' : 'Delivery'; ?></span>
                    <span class="oc-chip"><?php echo htmlspecialchars($pm_badge); ?></span>
                </div>
            </div>

            <?php
                $has_address_actions = !$is_pickup && $address_change_allowed;
                $has_order_actions = $can_cancel || $can_reorder || $can_return;
            ?>
            <div class="oc-body">
              <!-- ================= MAIN COLUMN ================= -->
              <div class="oc-main">
                <!-- ORDER STATUS / DELIVERY PROGRESS (the only status progression on the page) -->
                <section class="oc-sec oc-status-sec">
                    <h4 class="oc-h"><?php echo $is_pickup ? 'Order Status' : 'Delivery Progress'; ?></h4>
                    <?php if ($status === 'cancelled'): ?>
                        <div class="oc-cancelled">
                            <strong>Order cancelled</strong>
                            <span>Reason: <?php echo htmlspecialchars($order['cancellation_reason'] ?: 'Not provided'); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="oc-track-wrap">
                            <ol class="oc-track" style="--steps: <?php echo count($flow); ?>">
                                <?php $step_no = 0; foreach ($flow as $key => $label): $step_no++; ?>
                                    <?php
                                        $idx = array_search($key, $flow_keys, true);
                                        $state = $is_final || $idx < $cur_idx ? 'done' : ($idx === $cur_idx ? 'current' : 'todo');
                                    ?>
                                    <li class="oc-step is-<?php echo $state; ?>"<?php echo $state === 'current' ? ' aria-current="step"' : ''; ?>>
                                        <span class="oc-step-dot"><?php if ($state === 'done'): ?><i class="fas fa-check"></i><?php elseif ($state === 'todo'): ?><?php echo $step_no; ?><?php endif; ?></span>
                                        <span class="oc-step-label"><?php echo htmlspecialchars($label); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                        <?php if ($status_desc !== ''): ?>
                            <div class="oc-status-note"><strong><?php echo htmlspecialchars($flow[$cur_key]); ?></strong><span><?php echo htmlspecialchars($status_desc); ?></span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <!-- ORDER ITEMS -->
                <section class="oc-sec oc-items-sec">
                    <h4 class="oc-h">Order Items <span class="oc-count"><?php echo count($order_items); ?> <?php echo count($order_items) === 1 ? 'item' : 'items'; ?></span></h4>
                    <ul class="oc-items">
                        <?php foreach ($order_items as $item): ?>
                            <?php $variant_label = trim((string)($item['variant_size'] ?? '')); ?>
                            <li class="oc-item">
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img oc-img" alt="" loading="lazy">
                                <div class="oc-item-main">
                                    <strong class="oc-item-name"><?php echo htmlspecialchars($item_name($item)); ?></strong>
                                    <span class="oc-item-meta"><?php echo $variant_label !== '' ? htmlspecialchars($variant_label) . ' • ' : ''; ?>Qty <?php echo (int)$item['quantity']; ?></span>
                                    <?php if ($status === 'completed'): ?>
                                        <?php if (has_customer_reviewed_product((int)$customer['id'], (int)$item['product_id'], (int)$order['id'])): ?>
                                            <span class="oc-reviewed">Thank you for your review!</span>
                                        <?php else: ?>
                                            <a href="submit_review.php?order_id=<?php echo $order['id']; ?>&product_id=<?php echo $item['product_id']; ?>" class="button oc-review-btn">Write a Review</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="oc-item-price">
                                    <strong>PHP <?php echo number_format($item['total_price'], 2); ?></strong>
                                    <small>PHP <?php echo number_format($item['unit_price'], 2); ?> each</small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <!-- DELIVERY / PICKUP INFORMATION (address actions live here) -->
                <section class="oc-sec oc-fulfillment">
                    <h4 class="oc-h"><?php echo $is_pickup ? 'Pickup Information' : 'Delivery Information'; ?></h4>
                    <?php if ($is_pickup): ?>
                        <?php if (defined('SHOP_ADDRESS') && SHOP_ADDRESS !== ''): ?>
                            <div class="oc-row oc-stack"><span class="oc-k">Pickup location</span><span class="oc-v oc-wrap"><?php echo htmlspecialchars(SHOP_ADDRESS); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($order['pickup_date'])): ?><div class="oc-row"><span class="oc-k">Date</span><span class="oc-v"><?php echo htmlspecialchars($order['pickup_date']); ?></span></div><?php endif; ?>
                        <?php if (!empty($order['pickup_time'])): ?><div class="oc-row"><span class="oc-k">Time</span><span class="oc-v"><?php echo htmlspecialchars($order['pickup_time']); ?></span></div><?php endif; ?>
                        <?php if (!empty($customer['phone'])): ?><div class="oc-row"><span class="oc-k">Contact</span><span class="oc-v"><?php echo htmlspecialchars($customer['phone']); ?></span></div><?php endif; ?>
                    <?php else: ?>
                        <div class="oc-row oc-stack"><span class="oc-k">Address</span><span class="oc-v oc-wrap delivery-address-value"><?php echo htmlspecialchars($order['delivery_address']); ?></span></div>
                        <?php if (!empty($order['delivery_phone'])): ?><div class="oc-row"><span class="oc-k">Contact</span><span class="oc-v"><?php echo htmlspecialchars($order['delivery_phone']); ?></span></div><?php endif; ?>
                        <?php if (!empty($delivery_proof['rider_name']) && trim($delivery_proof['rider_name']) !== ''): ?>
                            <div class="oc-row"><span class="oc-k">Rider</span><span class="oc-v"><?php echo htmlspecialchars($delivery_proof['rider_name']); ?></span></div>
                        <?php endif; ?>
                        <div class="oc-row"><span class="oc-k">Delivery fee</span><span class="oc-v"><?php echo (float)$order['delivery_fee'] > 0 ? 'PHP ' . number_format($order['delivery_fee'], 2) : 'Free'; ?></span></div>
                        <?php if ($has_address_actions): ?>
                            <div class="delivery-address-actions oc-addr-btns">
                                <button type="button" class="button oc-btn oc-btn-secondary" onclick="confirmDeliveryAddress()">Confirm Address</button>
                                <button type="button" class="button oc-btn oc-btn-secondary" onclick="openAddressChangeModal()">Change Address</button>
                            </div>
                        <?php else: ?>
                            <p class="oc-hint">Address changes are no longer available because this order is already being fulfilled.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($order['order_notes'])): ?>
                        <div class="oc-row oc-stack"><span class="oc-k">Order notes</span><span class="oc-v oc-wrap oc-normal"><?php echo nl2br(htmlspecialchars($order['order_notes'])); ?></span></div>
                    <?php endif; ?>
                </section>

                <!-- PAYMENT INFORMATION -->
                <section class="oc-sec oc-payment">
                    <h4 class="oc-h">Payment Information</h4>
                    <div class="oc-row"><span class="oc-k">Method</span><span class="oc-v"><?php echo htmlspecialchars($pm_name); ?></span></div>
                    <div class="oc-row"><span class="oc-k">Status</span><span class="oc-v"><span class="oc-chip oc-chip-<?php echo $pay_tone; ?>"><?php echo htmlspecialchars($pay_status); ?></span></span></div>
                    <div class="oc-row"><span class="oc-k"><?php echo htmlspecialchars($amount_label); ?></span><span class="oc-v">PHP <?php echo number_format($order['total'], 2); ?></span></div>
                    <?php if ($pay_link): ?>
                        <div class="oc-row"><span class="oc-k">Online payment</span><span class="oc-v"><?php $pay_failed = in_array((string) ($order['payment_status'] ?? ''), ['failed', 'expired', 'cancelled'], true); ?>
                            <a class="button-link" href="<?php echo BASE_URL; ?>/customer/payment_status.php?order=<?php echo (int) $order['id']; ?><?php echo $pay_failed ? '#change-method' : ''; ?>"><?php echo ($order['payment_status'] ?? '') === 'pending' ? 'Check payment status' : ($pay_failed ? 'Pay again or change payment method' : 'Complete payment'); ?></a></span></div>
                    <?php endif; ?>
                    <?php if ($order['payment_method'] === 'bank'): ?>
                        <div class="oc-row"><span class="oc-k">Bank</span><span class="oc-v"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                        <div class="oc-row"><span class="oc-k">Account name</span><span class="oc-v"><?php echo htmlspecialchars($order['bank_account_name']); ?></span></div>
                        <div class="oc-row"><span class="oc-k">Reference #</span><span class="oc-v oc-mono"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php elseif ($order['payment_reference']): ?>
                        <div class="oc-row"><span class="oc-k">Reference</span><span class="oc-v oc-mono"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php endif; ?>
                    <?php if ($order['payment_proof_path']): ?>
                        <div class="oc-row"><span class="oc-k">Payment proof</span><span class="oc-v"><button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>')">✓ View Receipt</button></span></div>
                    <?php endif; ?>
                </section>

                <!-- PICKED UP FROM THE SHOP (rider's pickup photo) -->
                <?php if ($pickup_photo): ?>
                    <?php $pickup_url = BASE_URL . '/' . ltrim($pickup_photo['url'], '/'); ?>
                    <section class="oc-sec oc-pod">
                        <h4 class="oc-h">Picked Up from the Shop</h4>
                        <div class="oc-pod-body">
                            <a href="<?php echo htmlspecialchars($pickup_url); ?>" target="_blank" title="View full pickup photo" class="oc-pod-img">
                                <img src="<?php echo htmlspecialchars($pickup_url); ?>" alt="Rider picking up your order at the shop" loading="lazy">
                            </a>
                            <div class="oc-pod-info">
                                <?php if ($pickup_photo['rider'] !== ''): ?>
                                    <div class="oc-row"><span class="oc-k">Rider</span><span class="oc-v"><?php echo htmlspecialchars($pickup_photo['rider']); ?></span></div>
                                <?php endif; ?>
                                <div class="oc-row"><span class="oc-k">Picked up at</span><span class="oc-v"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($pickup_photo['created_at']))); ?></span></div>
                                <p class="oc-hint">Your rider took this photo when collecting your order from the shop.</p>
                                <a href="<?php echo htmlspecialchars($pickup_url); ?>" target="_blank" class="button oc-btn oc-btn-auto">View Photo</a>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- PROOF OF DELIVERY -->
                <?php if ($delivery_proof): ?>
                    <section class="oc-sec oc-pod">
                        <h4 class="oc-h">Proof of Delivery</h4>
                        <div class="oc-pod-body">
                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" title="View full proof image" class="oc-pod-img">
                                <img src="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" alt="Proof of Delivery" loading="lazy">
                            </a>
                            <div class="oc-pod-info">
                                <?php if (!empty($delivery_proof['rider_name']) && trim($delivery_proof['rider_name']) !== ''): ?>
                                    <div class="oc-row"><span class="oc-k">Delivered by</span><span class="oc-v"><?php echo htmlspecialchars($delivery_proof['rider_name']); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($delivery_proof['plate_number'])): ?>
                                    <div class="oc-row"><span class="oc-k">Vehicle</span><span class="oc-v"><?php echo htmlspecialchars($delivery_proof['vehicle_type'] . ' - ' . $delivery_proof['plate_number']); ?></span></div>
                                <?php endif; ?>
                                <div class="oc-row"><span class="oc-k">Delivered at</span><span class="oc-v"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($delivery_proof['created_at']))); ?></span></div>
                                <?php if (!empty($delivery_proof['notes'])): ?>
                                    <div class="oc-note-box"><strong>Delivery notes:</strong> <?php echo nl2br(htmlspecialchars($delivery_proof['notes'])); ?></div>
                                <?php endif; ?>
                                <p class="oc-hint">This photo confirms your order was delivered. If you were not home, check the notes for where it was left.</p>
                                <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" class="button oc-btn oc-btn-auto">View Proof</a>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
              </div>

              <!-- ================= SIDE COLUMN ================= -->
              <aside class="oc-side">
                <!-- ORDER SUMMARY (values come straight from the saved order) -->
                <section class="oc-sec oc-summary">
                    <h4 class="oc-h">Order Summary</h4>
                    <div class="oc-row"><span class="oc-k">Subtotal</span><span class="oc-v">PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                    <div class="oc-row"><span class="oc-k">VAT (12%)</span><span class="oc-v">PHP <?php echo number_format($order['vat_amount'], 2); ?></span></div>
                    <!-- IMPORTANT: Display SAVED delivery fee from database, NEVER recalculate -->
                    <div class="oc-row"><span class="oc-k">Delivery Fee</span><span class="oc-v"><?php echo (float)$order['delivery_fee'] > 0 ? 'PHP ' . number_format($order['delivery_fee'], 2) : 'Free'; ?></span></div>
                    <div class="oc-row<?php echo (float)$order['discount_amount'] > 0 ? ' oc-discount' : ''; ?>"><span class="oc-k">Discount<?php echo !empty($order['voucher_code']) ? ' (' . htmlspecialchars($order['voucher_code']) . ')' : ''; ?></span><span class="oc-v"><?php echo (float)$order['discount_amount'] > 0 ? '-PHP ' . number_format($order['discount_amount'], 2) : 'PHP 0.00'; ?></span></div>
                    <div class="oc-row oc-total"><span class="oc-k">Total</span><span class="oc-v">PHP <?php echo number_format($order['total'], 2); ?></span></div>
                </section>

                <!-- ORDER ACTIONS (only when the order is actually cancellable / re-orderable) -->
                <?php if ($has_order_actions): ?>
                    <section class="oc-sec oc-act-order">
                        <h4 class="oc-h">Order Actions</h4>
                        <?php if ($can_cancel): ?>
                            <button type="button" class="button oc-btn oc-btn-danger" onclick="openCancelModal()">Cancel Order</button>
                        <?php endif; ?>
                        <?php if ($can_reorder): ?>
                            <form method="POST" class="oc-inline-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="buy_again">
                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                <button type="submit" class="button oc-btn oc-btn-primary">Buy Again</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($can_return): ?>
                            <button type="button" class="button oc-btn oc-btn-outline rr-open">Request Return / Refund</button>
                            <p class="oc-hint">You can ask for a return until <?php echo htmlspecialchars(date('M j, Y', (int)$return_check['deadline'])); ?>.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- RETURN / REFUND REQUESTS (one per returned item) -->
                <?php if ($return_requests): ?>
                    <section class="oc-sec rr-sec" id="return-requests">
                        <h4 class="oc-h">Return / Refund</h4>
                        <?php foreach ($return_requests as $rr): ?>
                            <?php [$rr_label, $rr_tone] = rr_status_label((string)$rr['status']); ?>
                            <div class="rr-item">
                                <span class="rr-pill rr-<?php echo htmlspecialchars($rr_tone); ?>"><?php echo htmlspecialchars($rr_label); ?></span>
                                <?php if ($rr['order_item_id'] !== null): ?>
                                    <div class="oc-row"><span class="oc-k">Item</span><span class="oc-v"><?php echo htmlspecialchars($rr['product_name'] ?? 'Item'); ?> &times; <?php echo (int)$rr['quantity']; ?></span></div>
                                    <div class="oc-row"><span class="oc-k">You asked for</span><span class="oc-v"><?php echo htmlspecialchars(rr_resolutions()[$rr['resolution']] ?? $rr['resolution']); ?></span></div>
                                <?php endif; ?>
                                <div class="oc-row"><span class="oc-k">Reason</span><span class="oc-v"><?php echo htmlspecialchars(rr_reason_label((string)$rr['reason'])); ?></span></div>
                                <div class="oc-row"><span class="oc-k">Sent</span><span class="oc-v"><?php echo htmlspecialchars(date('M j, Y', strtotime($rr['created_at']))); ?></span></div>
                                <?php if ($rr['status'] === 'refunded'): ?>
                                    <div class="oc-row"><span class="oc-k">Refunded</span><span class="oc-v">PHP <?php echo number_format((float)$rr['refund_amount'], 2); ?></span></div>
                                <?php elseif ($rr['order_item_id'] !== null && !in_array($rr['status'], ['rejected', 'replaced'], true)): ?>
                                    <div class="oc-row"><span class="oc-k">Refund amount</span><span class="oc-v">PHP <?php echo number_format((float)$rr['requested_amount'], 2); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($rr['staff_note'])): ?>
                                    <div class="oc-note-box"><strong>From the shop:</strong> <?php echo nl2br(htmlspecialchars($rr['staff_note'])); ?></div>
                                <?php endif; ?>
                                <?php
                                    // Free rider pickup the next business day; refund 5-7 business days after the item reaches the shop.
                                    $rr_is_refund = ($rr['resolution'] ?? '') !== 'replacement';
                                    $rr_window = !empty($rr['received_at']) ? rr_refund_window((string) $rr['received_at']) : null;
                                    $rr_refund_when = $rr_window ? ' Your refund will be released within ' . RR_REFUND_MIN_DAYS . '-' . RR_REFUND_MAX_DAYS . ' business days, between ' . rr_day($rr_window['from']) . ' and ' . rr_day($rr_window['to']) . '.' : '';
                                    $rr_hint = [
                                        'pending'   => 'We are reviewing your request.',
                                        'approved'  => !empty($rr['picked_up_at'])
                                            ? 'A rider has picked up the item and is bringing it to the shop.'
                                            : (!empty($rr['pickup_date'])
                                                ? 'Approved - a rider will pick up the item on ' . rr_day((string) $rr['pickup_date']) . ', free of charge. Please have it packed and ready.'
                                                : 'Approved - please send or bring the item back to the shop. We will check it once it arrives.'),
                                        'received'  => 'The item has reached the shop and will be checked next.' . ($rr_is_refund ? $rr_refund_when : ''),
                                        'inspected' => 'The item has been checked. Your ' . ($rr_is_refund ? 'refund' : 'replacement') . ' is being processed.' . ($rr_is_refund ? $rr_refund_when : ''),
                                        'rejected'  => 'Your request was declined. Message our staff if you have questions.',
                                        'refunded'  => 'Your refund has been processed.',
                                        'replaced'  => 'Your replacement has been processed.',
                                    ][$rr['status']] ?? '';
                                ?>
                                <?php if ($rr['status'] === 'approved' && !empty($rr['pickup_date'])): ?>
                                    <div class="oc-row"><span class="oc-k">Pickup</span><span class="oc-v"><?php echo htmlspecialchars(rr_day((string) $rr['pickup_date'])); ?> &middot; free</span></div>
                                <?php endif; ?>
                                <p class="oc-hint"><?php echo htmlspecialchars($rr_hint); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <!-- LOYALTY POINTS -->
                <section class="oc-sec oc-loyalty">
                    <h4 class="oc-h">Loyalty Points</h4>
                    <div class="oc-row"><span class="oc-k">Points earned</span><span class="oc-v oc-earned">+<?php echo number_format($points_earned, 2); ?> pts</span></div>
                    <p class="oc-hint"><?php echo htmlspecialchars($points_state); ?></p>
                </section>

                <!-- ORDER DOCUMENTS: e-receipt + delivery confirmation QR -->
                <?php if ($show_qr): ?>
                    <?php
                    // Dynamic JSON payload per order
                    $qr_data = json_encode([
                        'delivery_id' => (int)$delivery_details['id'],
                        'order_id'    => (int)$order['id'],
                        'token'       => $delivery_details['qr_confirmation_token']
                    ]);

                    $qr_code_uri = null;

                    // Option 1: Try Endroid PHP Library
                    if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
                        try {
                            $qrCode = \Endroid\QrCode\QrCode::create($qr_data);
                            $writer = new \Endroid\QrCode\Writer\PngWriter();
                            $qr_code_uri = $writer->write($qrCode)->getDataUri();
                        } catch (Throwable $t) {
                            $qr_code_uri = null;
                        }
                    }

                    // Option 2: Fallback to QuickChart API (Works reliably for any order)
                    if (!$qr_code_uri) {
                        $qr_code_uri = "https://quickchart.io/qr?size=250&text=" . urlencode($qr_data);
                    }
                    ?>
                    <section class="oc-sec oc-docs oc-sec-quiet">
                        <h4 class="oc-h">Receipt &amp; QR Code</h4>
                        <form method="POST" action="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo (int)$order['id']; ?>" class="oc-inline-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="send_e_receipt">
                            <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                            <button type="submit" class="button oc-btn oc-btn-doc" title="Emails the full order summary and this exact QR to <?php echo htmlspecialchars($customer['email']); ?>">E-Receipt &amp; QR</button>
                        </form>
                        <p class="oc-hint">We'll email your electronic receipt and QR to <?php echo htmlspecialchars($customer['email']); ?>.</p>
                        <div class="oc-qr-body">
                            <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" class="oc-qr-img">
                            <div class="oc-qr-text">
                                <div class="oc-qr-ref">Delivery QR · Ref #<?php echo (int)$delivery_details['id']; ?></div>
                                <p class="oc-hint">Show this QR to the rider when your order arrives to confirm you received it.</p>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- NEED HELP? -->
                <section class="oc-sec oc-act-help oc-sec-quiet">
                    <h4 class="oc-h">Need Help?</h4>
                    <p class="oc-hint" style="margin-top:0;margin-bottom:8px;">Need help with your order?</p>
                    <div class="oc-help-btns">
                        <a class="button oc-btn oc-btn-help" href="<?php echo BASE_URL; ?>/customer/messages.php">Contact Staff</a>
                        <?php if ($can_contact_rider): ?>
                            <a class="button oc-btn oc-btn-help" href="<?php echo BASE_URL; ?>/customer/rider_chat.php">Contact Rider</a>
                        <?php endif; ?>
                    </div>
                </section>
              </aside>
            </div>
            <script>
                // Phones: the progress strip scrolls sideways - bring the current step into view (scrolls only the strip, not the page)
                document.querySelectorAll('.oc-track-wrap').forEach(function (wrap) {
                    var step = wrap.querySelector('.oc-step.is-current') || wrap.querySelector('.oc-step:last-child');
                    if (step && wrap.scrollWidth > wrap.clientWidth) {
                        wrap.scrollLeft = step.offsetLeft - (wrap.clientWidth - step.offsetWidth) / 2;
                    }
                });
            </script>
          </div>
        </div>
    <?php else: ?>
        <?php if ($flash_message = ($_SESSION['message'] ?? '')): ?>
            <?php $flash_kind = ($_SESSION['message_type'] ?? '') === 'error' ? 'error' : 'success'; unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <div class="alert-message alert-<?php echo $flash_kind; ?>" style="margin-bottom: 16px;"><?php echo htmlspecialchars($flash_message); ?></div>
        <?php endif; ?>
        <?php if ($all_orders): ?>
            <div class="ol">
                <nav class="ol-tabs" aria-label="Order status">
                    <?php foreach ($tab_groups as $key => $group): ?>
                        <a class="ol-tab<?php echo $key === $list_tab ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($list_url(['tab' => $key, 'page' => 1])); ?>"<?php echo $key === $list_tab ? ' aria-current="page"' : ''; ?>>
                            <?php echo htmlspecialchars($group['label']); ?><?php if ($tab_counts[$key] > 0): ?><span class="ol-tab-count"><?php echo (int) $tab_counts[$key]; ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <form method="get" class="ol-filter" action="<?php echo BASE_URL; ?>/customer/orders.php" role="search">
                    <?php if ($list_tab !== 'all'): ?><input type="hidden" name="tab" value="<?php echo htmlspecialchars($list_tab); ?>"><?php endif; ?>
                    <div class="ol-search">
                        <i class="fas fa-search ol-search-icon" aria-hidden="true"></i>
                        <label class="ol-sr" for="olSearch">Search your orders</label>
                        <input type="search" id="olSearch" name="q" value="<?php echo htmlspecialchars($list_q); ?>" maxlength="100" placeholder="Search by order number or product name" autocomplete="off">
                        <?php if ($list_q !== ''): ?>
                            <a class="ol-search-clear" href="<?php echo htmlspecialchars($list_url(['q' => '', 'page' => 1])); ?>" title="Clear search" aria-label="Clear search">&times;</a>
                        <?php endif; ?>
                    </div>
                    <label class="ol-sr" for="olRange">Show orders from</label>
                    <select id="olRange" name="range" onchange="this.form.submit()">
                        <?php foreach ($range_options as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars((string) $value); ?>"<?php echo (string) $value === $list_range ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="ol-btn ol-btn-primary">Search</button>
                </form>
                <?php if ($list_q !== '' || $list_range !== 'all'): ?>
                    <p class="ol-result-note"><?php echo count($shown_orders); ?> order<?php echo count($shown_orders) === 1 ? '' : 's'; ?> found<?php echo $list_q !== '' ? ' for &ldquo;' . htmlspecialchars($list_q) . '&rdquo;' : ''; ?>.</p>
                <?php endif; ?>

                <?php if ($orders): ?>
                    <?php
                        $list_pm = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
                        $list_desc = [
                            'pending' => 'Waiting for the shop to confirm your order.',
                            'confirmed' => 'Confirmed - the shop will prepare it soon.',
                            'processing' => 'The shop is preparing your order.',
                            'ready_for_pickup' => 'Ready - pick it up at the shop.',
                            'to_ship' => 'Packed and waiting for the rider.',
                            'to_receive' => 'With your rider - have your QR ready.',
                            'out_for_delivery' => 'On its way to you.',
                        ];
                        $max_rows = 2;
                    ?>
                    <?php foreach ($orders as $o): ?>
                        <?php
                            $o_id = (int) $o['id'];
                            $o_status = (string) $o['order_status'];
                            $o_pickup = ($o['fulfillment_type'] ?? '') === 'pickup';
                            $o_products = $order_products[$o_id] ?? [];
                            $o_done = in_array($o_status, ['completed', 'reviews'], true);
                            $o_detail = BASE_URL . '/customer/orders.php?id=' . $o_id;
                            $o_ship = $o_pickup ? 'Store pickup' : trim((string) ($o['delivery_address'] ?? ''));
                            $o_ship = $o_ship !== '' ? $o_ship : '-';
                            $o_when = strtotime((string) (($o['updated_at'] ?? '') ?: $o['created_at']));
                            $o_return = $return_map[$o_id] ?? null;
                            $o_return_check = $o_done ? rr_eligibility($conn, $o) : ['ok' => false];
                            $o_unrated = 0;
                            if ($o_status === 'completed') {
                                foreach ($o_products as $p) {
                                    if (!isset($reviewed_pairs[$o_id . ':' . (int) $p['product_id']])) { $o_unrated++; }
                                }
                            }
                        ?>
                        <article class="ol-card">
                            <div class="ol-top">
                                <dl class="ol-meta">
                                    <div><dt>Order Date</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $o['created_at']))); ?></dd></div>
                                    <div><dt>Total Amount</dt><dd>PHP <?php echo number_format((float) $o['total'], 2); ?></dd></div>
                                    <div class="ol-ship"><dt><?php echo $o_pickup ? 'Fulfillment' : 'Ship To'; ?></dt><dd title="<?php echo htmlspecialchars($o_ship); ?>"><?php echo htmlspecialchars($o_ship); ?></dd></div>
                                </dl>
                                <div class="ol-side">
                                    <div class="ol-no">Order : <strong>#<?php echo htmlspecialchars($o['order_number']); ?></strong></div>
                                    <div class="ol-btns">
                                        <a class="ol-btn ol-btn-primary" href="<?php echo htmlspecialchars($o_detail); ?>">View Order</a>
                                    </div>
                                </div>
                            </div>

                            <?php if ($o_unrated > 0): ?>
                                <a class="ol-notice" href="<?php echo htmlspecialchars($o_detail); ?>">
                                    <i class="fas fa-star"></i>
                                    <span>Please rate your experience with <?php echo $o_unrated === 1 ? 'this product' : 'these ' . $o_unrated . ' products'; ?>.</span>
                                    <strong>Rate now</strong>
                                </a>
                            <?php endif; ?>

                            <div class="ol-state">
                                <?php if ($o_status === 'cancelled'): ?>
                                    <span class="ol-state-title">Cancelled <?php echo htmlspecialchars(date('M j', $o_when)); ?></span>
                                <?php elseif ($o_status === 'refunded'): ?>
                                    <span class="ol-state-title">Refunded <?php echo htmlspecialchars(date('M j', strtotime((string) (($o['refunded_at'] ?? '') ?: $o['created_at'])))); ?></span>
                                <?php elseif ($o_done): ?>
                                    <span class="ol-state-title"><?php echo $o_pickup ? 'Picked up ' : 'Delivered '; ?><?php echo htmlspecialchars(date('M j', $o_when)); ?></span>
                                <?php else: ?>
                                    <span class="status-pill status-<?php echo htmlspecialchars($o_status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $o_status))); ?></span>
                                    <span class="ol-state-note"><?php echo htmlspecialchars($list_desc[$o_status] ?? ''); ?></span>
                                <?php endif; ?>
                                <?php if ($o_return): ?>
                                    <?php [$o_rr_label, $o_rr_tone] = rr_status_label((string) $o_return['status']); ?>
                                    <span class="rr-pill rr-<?php echo htmlspecialchars($o_rr_tone); ?>"><i class="fas fa-undo-alt"></i> <?php echo htmlspecialchars($o_rr_label); ?></span>
                                <?php endif; ?>
                                <span class="ol-pay"><i class="fas <?php echo $o_pickup ? 'fa-store' : 'fa-truck'; ?>"></i> <?php echo $o_pickup ? 'Pickup' : 'Delivery'; ?> &middot; <?php echo htmlspecialchars($list_pm[strtolower((string) $o['payment_method'])] ?? strtoupper((string) $o['payment_method'])); ?></span>
                            </div>

                            <ul class="ol-items">
                                <?php foreach (array_slice($o_products, 0, $max_rows) as $p): ?>
                                    <?php
                                        $p_name = trim((string) $p['product_name']);
                                        $p_var = trim((string) ($p['variant_size'] ?? ''));
                                        if ($p_var !== '' && substr($p_name, -strlen(' (' . $p_var . ')')) === ' (' . $p_var . ')') {
                                            $p_name = rtrim(substr($p_name, 0, -strlen(' (' . $p_var . ')')));
                                        }
                                        $p_search = BASE_URL . '/customer/products.php?search=' . rawurlencode($p_name);
                                    ?>
                                    <li class="ol-item">
                                        <a class="ol-thumb" href="<?php echo htmlspecialchars($p_search); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo htmlspecialchars($p['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" alt="" loading="lazy"></a>
                                        <div class="ol-item-main">
                                            <strong class="ol-item-name"><?php echo htmlspecialchars($p_name); ?></strong>
                                            <span class="ol-item-meta"><?php echo $p_var !== '' ? htmlspecialchars($p_var) . ' &bull; ' : ''; ?>Qty <?php echo (int) $p['quantity']; ?> &bull; PHP <?php echo number_format((float) $p['total_price'], 2); ?></span>
                                            <span class="ol-item-links">
                                                <a href="<?php echo htmlspecialchars($p_search); ?>"><i class="far fa-eye"></i> View Product</a>
                                                <?php if ($o_status === 'completed' && !isset($reviewed_pairs[$o_id . ':' . (int) $p['product_id']])): ?>
                                                    <a href="submit_review.php?order_id=<?php echo $o_id; ?>&amp;product_id=<?php echo (int) $p['product_id']; ?>"><i class="far fa-star"></i> Write a Review</a>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (count($o_products) > $max_rows): ?>
                                    <li class="ol-more"><a href="<?php echo htmlspecialchars($o_detail); ?>">+ <?php echo count($o_products) - $max_rows; ?> more item<?php echo count($o_products) - $max_rows === 1 ? '' : 's'; ?> in this order</a></li>
                                <?php endif; ?>
                                <?php if (!$o_products): ?>
                                    <li class="ol-more">No item details available for this order.</li>
                                <?php endif; ?>
                            </ul>

                            <?php if ($o_status === 'completed' || $o_return_check['ok']): ?>
                                <!-- After-sale actions, under the items (Buy Again / Return / Refund) -->
                                <div class="ol-foot">
                                    <?php if ($o_return_check['ok']): ?>
                                        <span class="ol-foot-note"><i class="fas fa-undo-alt"></i> Return or refund until <?php echo htmlspecialchars(date('M j', (int) $o_return_check['deadline'])); ?></span>
                                    <?php endif; ?>
                                    <div class="ol-btns">
                                        <?php if ($o_status === 'completed'): ?>
                                            <form method="POST" action="<?php echo BASE_URL; ?>/customer/orders.php" class="ol-form">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="buy_again">
                                                <input type="hidden" name="order_id" value="<?php echo $o_id; ?>">
                                                <button type="submit" class="ol-btn ol-btn-outline" title="Add the items from this order to your cart">Buy Again</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($o_return_check['ok']): ?>
                                            <a class="ol-btn ol-btn-outline" href="<?php echo htmlspecialchars($o_detail . '&return=1'); ?>"><i class="fas fa-undo-alt"></i>&nbsp;Return / Refund</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                    <?php if ($total_pages > 1): ?>
                        <nav class="ol-pager" aria-label="Order pages">
                            <?php if ($list_page > 1): ?>
                                <a class="ol-pg ol-pg-arrow" href="<?php echo htmlspecialchars($list_url(['page' => $list_page - 1])); ?>" aria-label="Previous page" rel="prev">&lsaquo;</a>
                            <?php else: ?>
                                <span class="ol-pg ol-pg-arrow is-off" aria-hidden="true">&lsaquo;</span>
                            <?php endif; ?>
                            <?php foreach ($page_window as $pg): ?>
                                <?php if ($pg === null): ?>
                                    <span class="ol-pg ol-pg-gap" aria-hidden="true">&hellip;</span>
                                <?php elseif ($pg === $list_page): ?>
                                    <span class="ol-pg is-current" aria-current="page"><?php echo $pg; ?></span>
                                <?php else: ?>
                                    <a class="ol-pg" href="<?php echo htmlspecialchars($list_url(['page' => $pg])); ?>" aria-label="Page <?php echo $pg; ?>"><?php echo $pg; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($list_page < $total_pages): ?>
                                <a class="ol-pg ol-pg-arrow" href="<?php echo htmlspecialchars($list_url(['page' => $list_page + 1])); ?>" aria-label="Next page" rel="next">&rsaquo;</a>
                            <?php else: ?>
                                <span class="ol-pg ol-pg-arrow is-off" aria-hidden="true">&rsaquo;</span>
                            <?php endif; ?>
                        </nav>
                        <p class="ol-pager-note">Showing <?php echo ($list_page - 1) * $per_page + 1; ?>&ndash;<?php echo min($list_page * $per_page, count($shown_orders)); ?> of <?php echo count($shown_orders); ?> orders</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ol-empty">
                        <?php if ($list_tab === 'returns'): ?>
                            <i class="fas fa-undo-alt"></i>
                            <p>You have no return or refund requests.<br>To return an item, open a received order within <?php echo RR_WINDOW_DAYS; ?> days and tap <strong>Return / Refund</strong>.</p>
                            <a class="ol-btn ol-btn-primary" href="<?php echo htmlspecialchars($list_url(['tab' => 'completed', 'page' => 1])); ?>">See completed orders</a>
                        <?php else: ?>
                            <i class="fas fa-box-open"></i>
                            <p><?php echo $list_q !== '' ? 'No orders match your search.' : 'No orders in this view.'; ?></p>
                            <a class="ol-btn ol-btn-ghost" href="<?php echo htmlspecialchars(BASE_URL . '/customer/orders.php'); ?>">Show all orders</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="empty-state">You have no orders yet. Start shopping to place your first order.</p>
            <a class="button" href="<?php echo BASE_URL; ?>/customer/products.php">Browse Products</a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Proof of Payment Modal -->
    <div id="proofModal" class="modal">
        <div class="modal-content" style="max-width: 600px; padding: 0;">
            <span class="close-modal" onclick="closeProofModal()" style="top: 15px; right: 25px; color: #fff; font-size: 30px;">&times;</span>
            <img id="proofModalImage" style="width: 100%; border-radius: 12px;">
        </div>
    </div>

    <!-- Return / Refund request for one item of this order (opened by .rr-open) -->
    <?php if ($order && $return_items): ?>
    <div id="returnModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="return-title" aria-hidden="true">
        <div class="modal-content cancel-modal-content rr-modal">
            <div class="modal-header">
                <h3 id="return-title">Request Return / Refund</h3>
                <button type="button" class="close-modal" onclick="closeReturnModal()" aria-label="Close return dialog">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="<?php echo BASE_URL; ?>/customer/orders.php" onsubmit="return confirm('Send this return request?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="request_return">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <div class="form-group rr-form" style="padding: 25px;">
                    <p class="rr-order" style="font-weight: 700; margin: 0 0 8px;">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                    <div class="rr-col">
                    <label for="return_item_id">Item to return</label>
                    <select name="return_item_id" id="return_item_id" required>
                        <option value="">Choose an item</option>
                        <?php foreach ($return_items as $ri): ?>
                            <option value="<?php echo (int)$ri['id']; ?>" data-max="<?php echo (int)$ri['returnable_qty']; ?>" data-unit-refund="<?php echo htmlspecialchars(number_format($ri['refund_per_unit'], 4, '.', '')); ?>"<?php echo $ri['returnable_qty'] < 1 ? ' disabled' : ''; ?>>
                                <?php echo htmlspecialchars($ri['product_name']); ?> (<?php echo $ri['returnable_qty'] < 1 ? 'already requested' : 'up to ' . (int)$ri['returnable_qty']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="return_quantity">Quantity</label>
                    <input type="number" name="return_quantity" id="return_quantity" min="1" max="1" step="1" value="1" required>
                    <label for="return_reason">Reason</label>
                    <select name="return_reason" id="return_reason" required>
                        <option value="">Choose a reason</option>
                        <?php foreach (rr_reasons() as $rr_value => $rr_text): ?>
                            <option value="<?php echo htmlspecialchars($rr_value); ?>" data-photo="<?php echo rr_reason_needs_photo($rr_value) ? '1' : '0'; ?>"><?php echo htmlspecialchars($rr_text); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>What would you like?</label>
                    <div class="rr-choice">
                        <?php foreach (rr_resolutions() as $res_value => $res_text): ?>
                            <label><input type="radio" name="return_resolution" value="<?php echo htmlspecialchars($res_value); ?>" required<?php echo $res_value === 'refund' ? ' checked' : ''; ?>> <?php echo htmlspecialchars($res_text); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <label for="return_photo">Photo of the item <small id="return_photo_hint">(optional, up to 5MB)</small></label>
                    <input type="file" name="return_photo" id="return_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                    <div class="rr-col">
                    <label for="return_details">What happened?</label>
                    <textarea name="return_details" id="return_details" required maxlength="1000" placeholder="Describe the problem with the item."></textarea>
                    <p class="rr-estimate">Estimated refund: <strong id="return_estimate">-</strong></p>
                    <p class="rr-note"><i class="fas fa-info-circle"></i> Our staff will review your request. If approved, send or bring the item back; we check it before refunding or replacing. The refund covers the item's share of what you paid (delivery fee and tip are not included), and loyalty points earned on a refunded item are removed.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeReturnModal()">Close</button>
                    <button type="submit" class="button">Send Request</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($order && in_array($order['order_status'], ['pending', 'confirmed'], true)): ?>
    <div id="cancellationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="cancellation-title" aria-hidden="true">
        <div class="modal-content cancel-modal-content">
            <div class="modal-header">
                <h3 id="cancellation-title">Cancel Order</h3>
                <button type="button" class="close-modal" onclick="closeCancelModal()" aria-label="Close cancellation dialog">&times;</button>
            </div>
            <form method="POST" id="cancel-order-form" onsubmit="return confirm('Are you sure you want to cancel this order? This action cannot be undone.');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <div class="form-group" style="padding: 25px;">
                    <p style="font-weight: 700; margin: 0 0 20px;">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                    <label for="cancellation_reason">Why do you want to cancel this order?</label>
                    <?php
                        // Tap a reason to fill the box (it can still be edited), or "Other" to type one.
                        $cancel_reasons = ['Changed my mind', 'Ordered by mistake', 'Wrong item or quantity', 'Found a better price elsewhere', 'Want to use a different payment method'];
                        $cancel_reasons[] = $order['fulfillment_type'] === 'delivery' ? 'Delivery address is wrong' : 'Cannot pick it up on the chosen date';
                        $cancel_reasons[] = $order['fulfillment_type'] === 'delivery' ? 'Delivery is taking too long' : 'Will order again later';
                    ?>
                    <div class="cancel-reasons" role="group" aria-label="Suggested reasons">
                        <?php foreach ($cancel_reasons as $cr): ?>
                            <button type="button" class="cancel-reason" data-reason="<?php echo htmlspecialchars($cr); ?>" aria-pressed="false"><?php echo htmlspecialchars($cr); ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="cancel-reason" data-reason="" aria-pressed="false"><i class="fas fa-pen"></i> Other</button>
                    </div>
                    <textarea name="cancellation_reason" id="cancellation_reason" required maxlength="1000" style="min-height: 100px;" placeholder="Pick a reason above or type your own."></textarea>
                    <p class="cancellation-warning"><i class="fas fa-exclamation-triangle"></i> Please note: Cancelling this order is final and cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeCancelModal()">Keep Order</button>
                    <button type="submit" class="button" style="background: #ef4444; color: white;">Yes, Cancel This Order</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($order && $address_change_allowed): ?>
    <div id="addressChangeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="address-change-title" aria-hidden="true">
        <div class="modal-content address-change-modal-content">
            <div class="modal-header">
                <h3 id="address-change-title">Change Delivery Address</h3>
                <button type="button" class="close-modal" onclick="closeAddressChangeModal()" aria-label="Close address dialog">&times;</button>
            </div>
            <form method="POST" id="address-change-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="change_delivery_address">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <div class="address-change-body">
                    <label for="change_address_id">Select Saved Address</label>
                    <select name="address_id" id="change_address_id">
                        <option value="">Choose a saved address</option>
                        <?php foreach ($customer_addresses as $address): ?>
                            <option value="<?php echo (int)$address['id']; ?>" data-phone="<?php echo htmlspecialchars($address['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-lat="<?php echo htmlspecialchars((string) ($address['latitude'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-lon="<?php echo htmlspecialchars((string) ($address['longitude'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($address['label'] . ' - ' . $address['full_address']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="address-change-divider">OR</div>
                    <button type="button" class="button button-secondary" onclick="showNewAddressMap()"><i class="fas fa-map"></i> Choose a new address from map</button>
                    <div id="change-map-section" style="display:none;">
                        <div id="change-address-map" class="address-change-map"></div>
                        <input type="hidden" name="latitude" id="change_address_latitude">
                        <input type="hidden" name="longitude" id="change_address_longitude">
                        <div id="change-address-status" class="address-change-status"></div>
                        <div style="margin-top: 15px;">
                            <label for="new_delivery_address">Selected Address</label>
                            <input type="text" name="new_delivery_address" id="new_delivery_address" readonly placeholder="Pin a location on the map">
                        </div>
                        <div style="margin-top: 15px;">
                            <label for="new_delivery_phone">Contact Number</label>
                            <input type="text" name="new_delivery_phone" id="new_delivery_phone" value="<?php echo htmlspecialchars($order['delivery_phone'] ?? $customer['phone'] ?? ''); ?>" maxlength="50">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <label for="change_delivery_phone">Contact Number</label>
                        <input type="text" id="change_delivery_phone" readonly value="<?php echo htmlspecialchars($order['delivery_phone'] ?? ''); ?>">
                    </div>
                    <div style="margin-top: 15px; display:flex; justify-content:space-between; gap:15px; flex-wrap:wrap;">
                        <span class="info-label">Estimated Delivery Fee</span>
                        <strong id="change_delivery_fee">Select an address</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeAddressChangeModal()">Cancel</button>
                    <button type="submit" class="button">Save Address Change</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let changeAddressMap = null;
        let changeAddressMarker = null;
        const addressChangeModal = document.getElementById('addressChangeModal');

        function confirmDeliveryAddress() {
            const status = document.querySelector('.delivery-address-actions');
            if (status) status.insertAdjacentHTML('afterend', '<small style="display:block;color:#16a34a;margin-top:8px;">Address confirmed for this order.</small>');
        }

        function openAddressChangeModal() {
            if (!addressChangeModal) return;
            addressChangeModal.style.display = 'flex';
            addressChangeModal.setAttribute('aria-hidden', 'false');
            updateChangeAddressSelection();
        }

        function closeAddressChangeModal() {
            if (!addressChangeModal) return;
            addressChangeModal.style.display = 'none';
            addressChangeModal.setAttribute('aria-hidden', 'true');
        }

        function showNewAddressMap() {
            const section = document.getElementById('change-map-section');
            const select = document.getElementById('change_address_id');
            section.style.display = 'block';
            select.value = '';
            document.getElementById('change_delivery_phone').value = document.getElementById('new_delivery_phone').value;
            if (!changeAddressMap) {
                changeAddressMap = L.map('change-address-map').setView([14.652023833372462, 120.97593745927361], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(changeAddressMap);
                L.Control.geocoder({ defaultMarkGeocode: false, placeholder: 'Search for address...' })
                    .on('markgeocode', e => setChangeAddressLocation(e.geocode.center, e.geocode.name))
                    .addTo(changeAddressMap);
                changeAddressMap.on('click', e => {
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
                        .then(response => response.json())
                        .then(data => setChangeAddressLocation(e.latlng, data.display_name || ''))
                        .catch(() => document.getElementById('change-address-status').textContent = 'Address lookup failed. Please try again.')
                });
            }
            setTimeout(() => changeAddressMap.invalidateSize(), 100);
        }

        function setChangeAddressLocation(latlng, address) {
            if (changeAddressMarker) changeAddressMap.removeLayer(changeAddressMarker);
            changeAddressMarker = L.marker(latlng).addTo(changeAddressMap);
            changeAddressMap.setView(latlng, 16);
            document.getElementById('change_address_latitude').value = latlng.lat;
            document.getElementById('change_address_longitude').value = latlng.lng;
            document.getElementById('new_delivery_address').value = address;
            document.getElementById('change_delivery_phone').value = document.getElementById('new_delivery_phone').value;
            updateChangeFee(latlng.lat, latlng.lng);
        }

        function updateChangeAddressSelection() {
            const select = document.getElementById('change_address_id');
            if (!select) return;
            const option = select.options[select.selectedIndex];
            const section = document.getElementById('change-map-section');
            section.style.display = select.value ? 'none' : section.style.display;
            document.getElementById('change_delivery_phone').value = select.value ? option.dataset.phone : document.getElementById('new_delivery_phone').value;
            const lat = parseFloat(option && option.dataset.lat), lon = parseFloat(option && option.dataset.lon);
            if (select.value && !isNaN(lat) && !isNaN(lon)) { updateChangeFee(lat, lon); return; }
            document.getElementById('change_delivery_fee').textContent = select.value ? 'Same as your current delivery fee' : 'Select an address';
        }

        // Same distance table as checkout / delivery_fee_for_km() on the server.
        function deliveryFeeForKm(km) {
            km = Math.round(Math.max(0, km) * 10) / 10;
            if (km <= 5) return 15 + 5 * Math.max(0, Math.ceil(km) - 1);
            return 35 + 5 * Math.ceil(Math.round((km - 5) * 10) / 10);
        }

        function updateChangeFee(lat, lon) {
            const storeLat = 14.652023833372462, storeLon = 120.97593745927361;
            const toRadians = value => value * Math.PI / 180;
            const dLat = toRadians(lat - storeLat), dLon = toRadians(lon - storeLon);
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRadians(storeLat)) * Math.cos(toRadians(lat)) * Math.sin(dLon / 2) ** 2;
            const distance = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            // Matches the server: distance-based fee within the supported range (<= 5 km), rejected beyond it.
            const inRange = distance <= 5;
            // Free delivery when this order's items total (VAT included) is PHP 2,000 or more.
            const freeDelivery = <?php echo json_encode(isset($order) && $order && ((float) $order['subtotal'] + (float) $order['vat_amount']) >= FREE_DELIVERY_MIN_TOTAL); ?>;
            document.getElementById('change_delivery_fee').textContent = !inRange ? 'Outside supported delivery area'
                : (freeDelivery ? 'Free (orders PHP 2,000 and up)' : 'PHP ' + deliveryFeeForKm(distance).toFixed(2));
            document.getElementById('change-address-status').textContent = inRange
                ? `Selected location: ${distance.toFixed(2)} km from the store.`
                : 'This location is beyond the supported delivery range.';
        }

        const changeAddressSelect = document.getElementById('change_address_id');
        if (changeAddressSelect) changeAddressSelect.addEventListener('change', updateChangeAddressSelection);

        function toggleNewAddressForm() {
        const selectedOption = document.querySelector('input[name="address_option"]:checked');
        const isNew = selectedOption && selectedOption.value === 'new';
        const newAddressFields = document.getElementById('new-address-fields');
        const newAddressInput = document.getElementById('delivery_address');
        const savedAddressText = document.getElementById('saved_address_text');
        const savedAddressPhone = document.getElementById('saved_address_phone');
        if (isNew) {
            newAddressFields.style.display = 'block';
            savedAddressText.value = ''; 
            savedAddressPhone.value = '';
        } else {
            newAddressFields.style.display = 'none';
            newAddressInput.value = ''; 
            if (selectedOption) {
                savedAddressText.value = selectedOption.dataset.address;
                savedAddressPhone.value = selectedOption.dataset.phone;
            }
        } 
        updateDeliveryFeeDisplay(); 
    }

        const proofModal = document.getElementById('proofModal');
        const modalImg = document.getElementById('proofModalImage');
        const cancelModal = document.getElementById('cancellationModal');

        function openProofModal(src) {
            proofModal.style.display = "flex";
            modalImg.src = src;
        }
        function closeProofModal() {
            proofModal.style.display = "none";
        }
        function openCancelModal() {
            if (!cancelModal) return;
            cancelModal.style.display = "flex";
            cancelModal.setAttribute('aria-hidden', 'false');
            document.getElementById('cancellation_reason').focus();
            if (!cancelModal.dataset.reasonsReady) {
                cancelModal.dataset.reasonsReady = '1';
                const box = document.getElementById('cancellation_reason');
                const chips = cancelModal.querySelectorAll('.cancel-reason');
                chips.forEach(function (chip) {
                    chip.addEventListener('click', function () {
                        chips.forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
                        const reason = chip.getAttribute('data-reason');
                        box.value = reason;            // "Other" clears the box for the customer's own words
                        box.focus();
                        box.setSelectionRange(box.value.length, box.value.length);
                    });
                });
                box.addEventListener('input', function () {
                    // Typed something different: no suggestion is selected any more.
                    chips.forEach(function (c) { if (c.getAttribute('aria-pressed') === 'true' && c.getAttribute('data-reason') !== '' && c.getAttribute('data-reason') !== box.value) c.setAttribute('aria-pressed', 'false'); });
                });
            }
        }
        function closeCancelModal() {
            if (!cancelModal) return;
            cancelModal.style.display = "none";
            cancelModal.setAttribute('aria-hidden', 'true');
        }

        // Return / refund modal (order details only). The refund shown is an estimate for the customer;
        // the server recomputes it from the order (app/helpers/return_refund_helper.php).
        const returnModal = document.getElementById('returnModal');
        function openReturnModal() {
            if (!returnModal) return;
            returnModal.style.display = 'flex';
            returnModal.setAttribute('aria-hidden', 'false');
            document.getElementById('return_item_id').focus();
        }
        function closeReturnModal() {
            if (!returnModal) return;
            returnModal.style.display = 'none';
            returnModal.setAttribute('aria-hidden', 'true');
        }
        document.querySelectorAll('.rr-open').forEach((btn) => btn.addEventListener('click', openReturnModal));
        if (returnModal) {
            const itemSel = document.getElementById('return_item_id');
            const qtyIn = document.getElementById('return_quantity');
            const reasonSel = document.getElementById('return_reason');
            const photoIn = document.getElementById('return_photo');
            const estimate = document.getElementById('return_estimate');
            const updateReturnForm = () => {
                const opt = itemSel.selectedOptions[0];
                const max = opt && opt.value ? parseInt(opt.dataset.max, 10) : 1;
                qtyIn.max = max;
                if (parseInt(qtyIn.value, 10) > max) qtyIn.value = max;
                const qty = Math.max(1, Math.min(max, parseInt(qtyIn.value, 10) || 1));
                estimate.textContent = opt && opt.value
                    ? 'PHP ' + (parseFloat(opt.dataset.unitRefund) * qty).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    : '-';
                const needsPhoto = reasonSel.selectedOptions[0] && reasonSel.selectedOptions[0].dataset.photo === '1';
                photoIn.required = needsPhoto;
                document.getElementById('return_photo_hint').textContent = needsPhoto ? '(required for this reason, up to 5MB)' : '(optional, up to 5MB)';
            };
            [itemSel, qtyIn, reasonSel].forEach((el) => el.addEventListener('input', updateReturnForm));
            updateReturnForm();
            if (new URLSearchParams(location.search).get('return') === '1') openReturnModal();
        }

        window.onclick = (event) => {
            if (event.target == returnModal) closeReturnModal();
            if (event.target == proofModal) closeProofModal();
            if (cancelModal && event.target == cancelModal) closeCancelModal();
            if (event.target == addressChangeModal) closeAddressChangeModal();
        };
    </script>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>