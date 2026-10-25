<?php
/**
 * Staff-Assisted Order — "Create Order for Customer"
 *
 * Lets an authenticated Admin OR Staff user place an order on behalf of an
 * EXISTING customer (e.g. phoned/chatted-in orders from customers who don't
 * use the website themselves). Lives under modules/admin/ for historical
 * reasons (it started Admin-only), but is a genuinely shared page now — see
 * $is_staff/$portal_base/$order_details_base below for how every link back
 * out of this page resolves to the correct portal. This intentionally
 * reuses the exact same order-creation core the real customer checkout uses
 * (create_customer_order() in customer/includes/functions.php) instead of
 * duplicating any pricing, stock, voucher, delivery, notification,
 * e-receipt, or QR logic — see README_DEPLOYMENT-style reasoning in the
 * project chat history for why.
 *
 * The only thing added on top of the shared order-creation core is a
 * follow-up UPDATE tagging the new order as staff-assisted
 * (tbl_orders.order_source / created_by_admin_id — see migration notes).
 * order_source has no separate 'staff' enum value at the DB level (out of
 * scope to add — see project constraint on not changing schema), so it's
 * set to 'admin' for both roles; created_by_admin_id still records the
 * exact acting user's real id either way, and neither field is currently
 * displayed anywhere, so this doesn't mislabel anything a user can see.
 * This is deliberately NOT baked into create_customer_order() itself so the
 * real customer checkout path stays byte-for-byte unchanged.
 */

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/upload_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php'; // pulls in db.php + create_customer_order() etc.

$token = getJWTFromCookie();
$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$admin_user_id = (int) ($payload['user_id'] ?? 0);
$admin_username = $payload['username'] ?? '';
$role = strtolower(trim($payload['role'] ?? ''));

// Admin OR Staff — both portals can place an order on behalf of an existing
// customer (e.g. phoned/chatted-in orders). Every link/redirect/include
// below that used to point at the Admin-only customers list or sidebar now
// branches on $role so a Staff user never gets bounced back through an
// admin-gated page after landing here — see the $portal_base/$is_staff
// helpers a few lines down.
if ($role !== 'admin' && $role !== 'staff') {
    header('Location: ' . BASE_URL . '/modules/staff/dashboard.php?error=access_denied');
    exit();
}

// Where to send this user for every "back to customers" / profile / order
// link on this page and on create_customer_order_success.php (which shares
// this scope via include). Staff's customers/customer_details/order_details
// pages are separate files from Admin's — not just a different auth check on
// the same one — so the base path itself must differ, not just the guard.
$is_staff = $role === 'staff';
$portal_base = $is_staff ? BASE_URL . '/modules/staff' : BASE_URL . '/modules/customers';
$order_details_base = $is_staff ? BASE_URL . '/modules/staff' : BASE_URL . '/modules/admin';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$current_page = 'customers'; // Admin sidebar: keep "Customers" nav item highlighted
$staff_current_page = 'customers.php'; // Staff sidebar: same, via its own override
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($admin_user_id);

$customer_id = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
if ($customer_id <= 0) {
    header('Location: ' . $portal_base . '/customers.php');
    exit();
}

$customer = get_customer_by_id($customer_id);
if (!$customer) {
    header('Location: ' . $portal_base . '/customers.php?error=customer_not_found');
    exit();
}

// --- Success view (after a completed order — GET only, no re-submission risk) ---
if (isset($_GET['success_order_id'])) {
    $success_order_id = (int) $_GET['success_order_id'];
    $success_order = get_order_by_id($success_order_id, $customer_id);
    if (!$success_order) {
        header('Location: ' . $portal_base . '/customers.php');
        exit();
    }
    include __DIR__ . '/create_customer_order_success.php';
    exit();
}

/**
 * The order-in-progress "cart" for this admin session, namespaced per
 * target customer so working on two different customers' orders in two
 * tabs doesn't cross-contaminate. Shape matches get_customer_cart()'s
 * array exactly (id, variant_id, size, name, unit_price, weight_kg,
 * quantity, image_url) so it can be handed straight to
 * create_customer_order() with zero transformation.
 */
if (!isset($_SESSION['admin_order_cart']) || !is_array($_SESSION['admin_order_cart'])) {
    $_SESSION['admin_order_cart'] = [];
}
if (!isset($_SESSION['admin_order_cart'][$customer_id])) {
    $_SESSION['admin_order_cart'][$customer_id] = [];
}
$cart =& $_SESSION['admin_order_cart'][$customer_id];

$errors = [];
$form_action = $_POST['form_action'] ?? '';

// --- Add item to cart ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_action === 'add_item') {
    csrf_require();
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $variant_id = !empty($_POST['variant_id']) ? (int) $_POST['variant_id'] : null;
    $quantity = (int) ($_POST['quantity'] ?? 0);

    $product = $product_id > 0 ? get_product_by_id($product_id) : null;
    if (!$product) {
        $errors[] = 'Selected product was not found.';
    } elseif ($quantity <= 0) {
        $errors[] = 'Quantity must be at least 1.';
    } else {
        $unit_price = $product['price'];
        $weight_kg = $product['weight_kg'];
        $size = null;
        $name = $product['name'];

        if ($variant_id !== null) {
            // Server-side: confirm the variant genuinely belongs to this product.
            $variant_match = null;
            foreach (get_product_variants($product_id) as $v) {
                if ((int) $v['id'] === $variant_id) {
                    $variant_match = $v;
                    break;
                }
            }
            if (!$variant_match) {
                $errors[] = 'Selected variant does not belong to this product.';
            } else {
                $unit_price = (float) $variant_match['price'];
                $weight_kg = (float) $variant_match['weight_kg'];
                $size = $variant_match['size'];
                $name = $product['name'] . ' (' . $size . ')';
            }
        }

        if (empty($errors)) {
            $available_stock = get_product_stock($product_id, $variant_id);
            if ($quantity > $available_stock) {
                $errors[] = "Insufficient stock for {$name}. Only {$available_stock} available.";
            } else {
                $cart_key = $product_id . '-' . ($variant_id ?? '0');
                $existing_qty = isset($cart[$cart_key]) ? (int) $cart[$cart_key]['quantity'] : 0;
                $new_qty = $existing_qty + $quantity;
                if ($new_qty > $available_stock) {
                    $errors[] = "Cannot add {$quantity} more of {$name} — only {$available_stock} in stock and {$existing_qty} already in this order.";
                } else {
                    $cart[$cart_key] = [
                        'id' => $product_id,
                        'variant_id' => $variant_id ?? 0,
                        'size' => $size,
                        'name' => $name,
                        'unit_price' => $unit_price,
                        'weight_kg' => $weight_kg,
                        'quantity' => $new_qty,
                        'image_url' => $product['image_url'],
                    ];
                }
            }
        }
    }

    if (empty($errors)) {
        header('Location: create_customer_order.php?customer_id=' . $customer_id . '#order-cart');
        exit();
    }
}

// --- Remove item from cart ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_action === 'remove_item') {
    csrf_require();
    $cart_key = (string) ($_POST['cart_key'] ?? '');
    unset($cart[$cart_key]);
    header('Location: create_customer_order.php?customer_id=' . $customer_id . '#order-cart');
    exit();
}

// --- Place the order ---
$form_values = [
    'fulfillment_type' => 'pickup',
    'address_option' => 'new',
    'new_delivery_address' => '',
    'new_delivery_phone' => trim((string) ($customer['phone'] ?? '')),
    'order_notes' => '',
    'delivery_instructions' => '',
    'pickup_date' => '',
    'pickup_time' => '',
    'payment_method' => 'cod',
    'gcash_reference_number' => '',
    'bank_name' => '',
    'bank_account_name' => '',
    'bank_reference_number' => '',
    'voucher_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_action === 'place_order') {
    csrf_require();

    foreach ($form_values as $key => $default) {
        $form_values[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    if (empty($cart)) {
        $errors[] = 'Add at least one product before creating the order.';
    }

    $fulfillment_type = ($form_values['fulfillment_type'] === 'delivery') ? 'delivery' : 'pickup';
    $delivery_address = '';
    $delivery_phone = '';
    $latitude = null;
    $longitude = null;

    if ($fulfillment_type === 'delivery') {
        if ($form_values['address_option'] === 'new') {
            $delivery_address = $form_values['new_delivery_address'];
            $delivery_phone = $form_values['new_delivery_phone'];
        } else {
            $saved_id = (int) $form_values['address_option'];
            $saved_stmt = $conn->prepare("SELECT full_address, phone, latitude, longitude FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1");
            $saved_stmt->bind_param('ii', $saved_id, $customer_id);
            $saved_stmt->execute();
            $saved_address = $saved_stmt->get_result()->fetch_assoc();
            $saved_stmt->close();

            if ($saved_address) {
                $delivery_address = trim($saved_address['full_address']);
                $delivery_phone = trim($saved_address['phone'] ?? '') ?: $form_values['new_delivery_phone'];
                $latitude = is_numeric($saved_address['latitude']) ? (float) $saved_address['latitude'] : null;
                $longitude = is_numeric($saved_address['longitude']) ? (float) $saved_address['longitude'] : null;
            } else {
                $errors[] = 'Please select a valid saved delivery address.';
            }
        }

        if ($delivery_address === '') {
            $errors[] = 'Delivery address is required for delivery orders.';
        }
        if ($delivery_phone === '') {
            $errors[] = 'Delivery phone number is required.';
        }
        if ($form_values['payment_method'] === 'pay_at_shop') {
            $errors[] = 'Pay at Shop is only available for pickup orders.';
        }
    } else {
        if ($form_values['pickup_date'] === '') {
            $errors[] = 'Pickup date is required for pickup orders.';
        }
        if ($form_values['pickup_time'] === '') {
            $errors[] = 'Pickup time is required for pickup orders.';
        }
        if ($form_values['payment_method'] === 'cod') {
            $errors[] = 'Cash on Delivery is only available for delivery orders.';
        }
    }

    $payment_method = in_array($form_values['payment_method'], ['cod', 'gcash', 'pay_at_shop', 'bank'], true)
        ? $form_values['payment_method']
        : 'cod';

    $payment_proof_path = '';
    $payment_proof_absolute_path = '';

    if ($payment_method === 'gcash') {
        $gcash_ref = $form_values['gcash_reference_number'];
        if ($gcash_ref === '') {
            $errors[] = 'GCash reference number is required for GCash payments.';
        } elseif (!preg_match('/^\d{13}$/', $gcash_ref)) {
            $errors[] = 'Please enter a valid 13-digit GCash reference number.';
        } else {
            $ref_stmt = $conn->prepare("SELECT id FROM tbl_orders WHERE payment_reference = ? LIMIT 1");
            $ref_stmt->bind_param('s', $gcash_ref);
            $ref_stmt->execute();
            if ($ref_stmt->get_result()->num_rows > 0) {
                $errors[] = 'This GCash reference number has already been used.';
            }
            $ref_stmt->close();
        }

        if (isset($_FILES['gcash_payment_proof']) && $_FILES['gcash_payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload = save_uploaded_image($_FILES['gcash_payment_proof'], __DIR__ . '/../../uploads/proofs/', '/uploads/proofs/', 'gcash_receipt_', 5 * 1024 * 1024);
            if ($upload['ok']) {
                $payment_proof_path = $upload['url'];
                $payment_proof_absolute_path = $upload['path'];
            } else {
                $errors[] = $upload['error'];
            }
        } else {
            $errors[] = 'A screenshot of the GCash payment receipt is required.';
        }
    }

    if ($payment_method === 'bank') {
        if ($form_values['bank_name'] === '') $errors[] = 'Bank name is required for bank transfers.';
        if ($form_values['bank_account_name'] === '') $errors[] = 'Account name is required for bank transfers.';
        $bank_ref = $form_values['bank_reference_number'];
        if ($bank_ref === '') {
            $errors[] = 'Bank reference number is required.';
        } else {
            $ref_stmt = $conn->prepare("SELECT id FROM tbl_orders WHERE payment_reference = ? LIMIT 1");
            $ref_stmt->bind_param('s', $bank_ref);
            $ref_stmt->execute();
            if ($ref_stmt->get_result()->num_rows > 0) {
                $errors[] = 'This bank reference number has already been used.';
            }
            $ref_stmt->close();
        }

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload = save_uploaded_image($_FILES['payment_proof'], __DIR__ . '/../../uploads/proofs/', '/uploads/proofs/', 'proof_', 5 * 1024 * 1024);
            if ($upload['ok']) {
                $payment_proof_path = $upload['url'];
                $payment_proof_absolute_path = $upload['path'];
            } else {
                $errors[] = $upload['error'];
            }
        } else {
            $errors[] = 'A payment proof screenshot is required for bank transfers.';
        }
    }

    // Re-validate stock server-side right before committing — never trust
    // what was true when items were added to the cart minutes ago.
    if (empty($errors)) {
        foreach ($cart as $item) {
            $available = get_product_stock((int) $item['id'], $item['variant_id'] ?: null);
            if ((int) $item['quantity'] > $available) {
                $errors[] = "Insufficient stock for {$item['name']}. Only {$available} available.";
            }
        }
    }

    if (empty($errors)) {
        // Delivery fee: the live customer checkout currently applies a flat
        // 0.00 (see customer/checkout.php) rather than calling
        // calculate_delivery_fee() — matched here exactly so an admin-
        // assisted order's totals are computed under the identical rule a
        // customer placing it themselves would get today.
        $delivery_fee = 0.00;

        $reference_number = ($payment_method === 'gcash')
            ? $form_values['gcash_reference_number']
            : $form_values['bank_reference_number'];

        $details = [
            'delivery_address' => $delivery_address,
            'delivery_phone' => $delivery_phone,
            'order_notes' => $form_values['order_notes'],
            'delivery_instructions' => $form_values['delivery_instructions'],
            'pickup_date' => $form_values['pickup_date'],
            'pickup_time' => $form_values['pickup_time'],
            'payment_method' => $payment_method,
            'gcash_reference_number' => $reference_number,
            'bank_reference_number' => $reference_number,
            'delivery_fee' => $delivery_fee,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'voucher_code' => $form_values['voucher_code'],
            'bank_name' => $form_values['bank_name'],
            'bank_account_name' => $form_values['bank_account_name'],
            'payment_proof_path' => $payment_proof_path,
        ];

        $order_id = create_customer_order($customer_id, $fulfillment_type, $details, $cart);

        if ($order_id !== null) {
            // Tag the order as admin-created — see the class-level docblock
            // for why this is a deliberate follow-up UPDATE rather than a
            // change to create_customer_order() itself.
            $tag_stmt = $conn->prepare("UPDATE tbl_orders SET order_source = 'admin', created_by_admin_id = ? WHERE id = ?");
            $tag_stmt->bind_param('ii', $admin_user_id, $order_id);
            $tag_stmt->execute();
            $tag_stmt->close();

            $order_row = get_order_by_id($order_id, $customer_id);
            $order_number = $order_row['order_number'] ?? ('#' . $order_id);
            $details_text = "Created order {$order_number} for " . $customer['name'] . " (Customer ID: {$customer_id})";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Created Customer Order', ?)");
            $log_stmt->bind_param('is', $admin_user_id, $details_text);
            $log_stmt->execute();
            $log_stmt->close();

            unset($_SESSION['admin_order_cart'][$customer_id]);

            header('Location: create_customer_order.php?customer_id=' . $customer_id . '&success_order_id=' . $order_id);
            exit();
        } else {
            $real_err = $_SESSION['last_order_error'] ?? null;
            unset($_SESSION['last_order_error']);
            $errors[] = $real_err ?: 'Unable to create the order right now. Please try again.';
            if ($payment_proof_absolute_path !== '' && is_file($payment_proof_absolute_path)) {
                @unlink($payment_proof_absolute_path);
            }
        }
    }
}

// --- Product search (server-side, prepared statement — same convention as
// the rest of the Admin panel's search/filter pages) ---
$product_search = trim($_GET['product_search'] ?? '');
$products = [];
if ($product_search !== '') {
    $like = "%{$product_search}%";
    $p_stmt = $conn->prepare("SELECT * FROM tbl_product_inventory WHERE (name LIKE ? OR sku LIKE ?) ORDER BY name ASC LIMIT 30");
    $p_stmt->bind_param('ss', $like, $like);
    $p_stmt->execute();
    $p_res = $p_stmt->get_result();
    while ($row = $p_res->fetch_assoc()) {
        $products[] = $row;
    }
    $p_stmt->close();
} else {
    $p_res = mysqli_query($conn, "SELECT * FROM tbl_product_inventory ORDER BY name ASC LIMIT 30");
    while ($row = mysqli_fetch_assoc($p_res)) {
        $products[] = $row;
    }
}

// Saved addresses for this customer
$customer_addresses = [];
$addr_stmt = $conn->prepare("SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC");
$addr_stmt->bind_param('i', $customer_id);
$addr_stmt->execute();
$addr_res = $addr_stmt->get_result();
while ($row = $addr_res->fetch_assoc()) {
    $customer_addresses[] = $row;
}
$addr_stmt->close();

// Cart totals (display only — the authoritative recalculation happens
// server-side inside create_customer_order() itself on submit).
$cart_subtotal_ex_vat = 0.00;
$cart_total_items = 0;
foreach ($cart as $item) {
    $cart_subtotal_ex_vat += (float) $item['unit_price'] * (int) $item['quantity'];
    $cart_total_items += (int) $item['quantity'];
}
$cart_vat = round($cart_subtotal_ex_vat * 0.12, 2);
$cart_subtotal_inc_vat = $cart_subtotal_ex_vat + $cart_vat;
$cart_delivery_fee = 0.00; // matches the live checkout's current flat rule — see the place_order handler above
$cart_grand_total = $cart_subtotal_inc_vat + $cart_delivery_fee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Order for Customer - DPS <?php echo $is_staff ? 'Staff' : 'Admin'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        /* ==================================================================
           Layout
           ================================================================== */
        .aco-page-header { margin-bottom: 22px; }
        .aco-page-header h1 { font-size: 1.6rem; margin: 0; display: flex; align-items: center; gap: 10px; }
        .aco-page-header p { margin: 6px 0 0; color: var(--muted); font-size: 0.92rem; }

        .aco-layout { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(320px, 0.85fr); gap: 22px; align-items: start; }
        @media (max-width: 1024px) { .aco-layout { grid-template-columns: 1fr; } }

        .aco-section { margin-bottom: 20px; }
        .aco-section:last-child { margin-bottom: 0; }
        .aco-card { background: var(--surface); border-radius: 14px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); padding: 22px; box-sizing: border-box; }
        .aco-card + .aco-card { margin-top: 20px; }
        .aco-card-title { font-size: 1.05rem; font-weight: 700; margin: 0 0 16px; display: flex; align-items: center; gap: 9px; color: var(--text); }
        .aco-card-title .sub { display: block; font-size: 0.78rem; font-weight: 500; color: var(--muted); margin-top: 3px; }

        /* Right column sticks alongside the cart on tall desktop viewports,
           but never on mobile/tablet, and never overlaps the sidebar/header
           (it's sticky within the content column's own scroll, not the
           viewport, and the sidebar is a separate fixed element). */
        .aco-right-col { position: sticky; top: 20px; }
        @media (max-width: 1024px) { .aco-right-col { position: static; } }

        /* ==================================================================
           Customer card
           ================================================================== */
        .aco-customer-card { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .aco-customer-card .avatar { width: 52px; height: 52px; border-radius: 50%; background: var(--primary-soft); color: var(--link); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .aco-customer-card .details { flex: 1; min-width: 200px; }
        .aco-customer-card .name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .aco-customer-card .name { font-size: 1.15rem; font-weight: 700; color: var(--text); }
        .aco-customer-card .cid-pill { background: var(--surface-2); color: var(--muted); font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
        .aco-customer-card .contact-list { margin-top: 8px; display: flex; flex-direction: column; gap: 4px; }
        .aco-customer-card .contact-list span { font-size: 0.85rem; color: var(--text-2); display: flex; align-items: center; gap: 8px; }
        .aco-customer-card .contact-list i { color: var(--muted); width: 14px; text-align: center; }
        .aco-view-profile-btn { align-self: center; background: var(--surface-2); color: var(--link); border: 1px solid var(--border); padding: 9px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 7px; }
        .aco-view-profile-btn:hover { background: var(--surface-2); }

        /* ==================================================================
           Add Products
           ================================================================== */
        .aco-search-box { position: relative; margin-bottom: 16px; }
        .aco-search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
        .aco-search-box input { width: 100%; padding: 11px 14px 11px 38px; border-radius: 9px; border: 1px solid var(--border); font-size: 0.92rem; box-sizing: border-box; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .aco-search-box input:focus { outline: none; border-color: #4a3e94; box-shadow: 0 0 0 3px rgba(74,62,148,0.12); }

        .aco-product-list { max-height: 460px; overflow-y: auto; border: 1px solid var(--border); border-radius: 12px; scrollbar-width: thin; }
        .aco-product-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-bottom: 1px solid var(--border); transition: background 0.12s ease; flex-wrap: wrap; }
        .aco-product-row:last-child { border-bottom: none; }
        .aco-product-row:hover { background: var(--surface-2); }
        .aco-product-row .thumb { width: 48px; height: 48px; border-radius: 9px; object-fit: cover; background: var(--surface-2); flex-shrink: 0; }
        .aco-product-row .info { flex: 1 1 180px; min-width: 140px; }
        .aco-product-row .info strong { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text); line-height: 1.3; }
        .aco-product-row .info .meta { font-size: 0.78rem; color: var(--muted); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
        .aco-product-row .info .meta .price { color: var(--success); font-weight: 700; font-size: 0.85rem; }

        .aco-add-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .aco-add-form select { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.82rem; max-width: 180px; }
        .aco-qty-stepper { display: flex; align-items: center; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; height: 36px; }
        .aco-qty-stepper button { width: 30px; height: 100%; border: none; background: var(--surface-2); color: var(--text-2); font-weight: 700; cursor: pointer; font-size: 0.95rem; line-height: 1; }
        .aco-qty-stepper button:hover { background: #eef1f5; }
        .aco-qty-stepper input { width: 42px; height: 100%; border: none; border-left: 1px solid var(--border); border-right: 1px solid var(--border); text-align: center; font-size: 0.88rem; -moz-appearance: textfield; }
        .aco-qty-stepper input::-webkit-outer-spin-button, .aco-qty-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .aco-add-btn { background: #27ae60; color: #fff; border: none; padding: 0 16px; height: 36px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 700; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
        .aco-add-btn:hover { background: #229954; }
        .aco-add-btn:disabled { background: #cbd5e1; cursor: not-allowed; }

        .aco-empty-list { text-align: center; padding: 40px 16px; color: var(--muted); }
        .aco-empty-list i { font-size: 1.9rem; color: #cbd5e1; display: block; margin-bottom: 10px; }
        .aco-empty-list p.main { color: var(--muted); font-weight: 600; margin: 0; }
        .aco-empty-list p.sub { margin: 4px 0 0; font-size: 0.82rem; }

        /* ==================================================================
           Order Cart
           ================================================================== */
        .aco-cart-table { width: 100%; border-collapse: collapse; }
        .aco-cart-table th, .aco-cart-table td { padding: 12px 10px; text-align: left; font-size: 0.87rem; border-bottom: 1px solid var(--border); }
        .aco-cart-table th { background: var(--surface-2); font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); font-weight: 700; }
        .aco-cart-table td.num { text-align: right; }
        .aco-cart-table .prod-name { font-weight: 600; color: var(--text); }
        .aco-cart-table .variant-chip { display: inline-block; margin-top: 3px; font-size: 0.74rem; color: var(--accent); background: var(--primary-soft); padding: 2px 8px; border-radius: 999px; }
        .aco-remove-btn { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 1rem; padding: 6px; border-radius: 6px; }
        .aco-remove-btn:hover { background: var(--danger-soft); }

        /* Mobile: cart becomes stacked cards instead of a cramped table */
        @media (max-width: 640px) {
            .aco-cart-table thead { display: none; }
            .aco-cart-table, .aco-cart-table tbody, .aco-cart-table tr, .aco-cart-table td { display: block; width: 100%; }
            .aco-cart-table tr { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 10px; padding: 10px 12px; }
            .aco-cart-table td { border-bottom: none; padding: 4px 0; display: flex; justify-content: space-between; align-items: center; }
            .aco-cart-table td.num { text-align: right; }
            .aco-cart-table td[data-label]::before { content: attr(data-label); font-size: 0.72rem; color: var(--muted); font-weight: 700; text-transform: uppercase; }
            .aco-cart-table td.prod-cell::before { display: none; }
            .aco-cart-table td.prod-cell { display: block; }
        }

        /* ==================================================================
           Fulfillment segmented control
           ================================================================== */
        .aco-fulfillment-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
        .aco-fulfillment-card { position: relative; border: 2px solid var(--border); border-radius: 10px; padding: 14px; cursor: pointer; transition: border-color 0.12s ease, background 0.12s ease; }
        .aco-fulfillment-card input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
        .aco-fulfillment-card .icon { font-size: 1.2rem; color: var(--muted); margin-bottom: 6px; }
        .aco-fulfillment-card .label { font-weight: 700; font-size: 0.92rem; color: var(--text); }
        .aco-fulfillment-card .desc { font-size: 0.76rem; color: var(--muted); margin-top: 2px; }
        .aco-fulfillment-card.active { border-color: #4a3e94; background: #f8f7ff; }
        .aco-fulfillment-card.active .icon { color: var(--link); }

        .aco-field-group { margin-bottom: 16px; }
        .aco-field-group:last-child { margin-bottom: 0; }
        .aco-field-group label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
        .aco-field-group .hint { font-weight: 500; color: var(--muted); font-size: 0.76rem; margin-left: 4px; }
        .aco-field-group input, .aco-field-group select, .aco-field-group textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem;
            box-sizing: border-box; font-family: inherit; transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .aco-field-group input:focus, .aco-field-group select:focus, .aco-field-group textarea:focus {
            outline: none; border-color: #4a3e94; box-shadow: 0 0 0 3px rgba(74,62,148,0.12);
        }
        .aco-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 480px) { .aco-field-row { grid-template-columns: 1fr; } }

        .aco-address-warning { display: flex; align-items: flex-start; gap: 10px; background: var(--warning-soft); border: 1px solid #fde68a; color: var(--warning); border-radius: 8px; padding: 12px 14px; font-size: 0.85rem; margin-bottom: 14px; }
        .aco-address-warning i { margin-top: 2px; }

        /* ==================================================================
           Alerts / validation
           ================================================================== */
        .aco-alert { padding: 13px 16px; border-radius: 10px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem; }
        .aco-alert.error { background: var(--danger-soft); color: var(--danger); border-left: 4px solid #e74c3c; }
        .aco-alert ul { margin: 6px 0 0; padding-left: 20px; font-weight: 500; }
        .aco-field-error { color: var(--danger); font-size: 0.78rem; font-weight: 600; margin-top: 5px; display: none; }
        .aco-field-error.show { display: block; }

        /* ==================================================================
           Order Summary — the most visually important card
           ================================================================== */
        .aco-summary-card { border: 1px solid #e4e0f7; box-shadow: 0 6px 16px rgba(74,62,148,0.08); }
        .aco-summary-row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 0.88rem; color: var(--muted); }
        .aco-summary-row span:last-child { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--text-2); }
        .aco-summary-row.total { border-top: 2px solid var(--border); margin-top: 10px; padding-top: 14px; align-items: baseline; }
        .aco-summary-row.total span:first-child { font-size: 0.95rem; font-weight: 700; color: var(--text); }
        .aco-summary-row.total span:last-child { font-size: 1.5rem; font-weight: 800; color: var(--link); }

        .aco-submit-btn {
            width: 100%; background: #4a3e94; color: #fff; border: none; padding: 15px; border-radius: 10px;
            font-weight: 700; font-size: 0.98rem; cursor: pointer; margin-top: 18px; box-sizing: border-box;
            display: flex; align-items: center; justify-content: center; gap: 8px; transition: background 0.12s ease;
        }
        .aco-submit-btn:hover:not(:disabled) { background: #3d3379; }
        .aco-submit-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
        .aco-cancel-link { display: block; text-align: center; margin-top: 12px; color: var(--muted); font-size: 0.85rem; text-decoration: none; padding: 6px; }
        .aco-cancel-link:hover { color: var(--text-2); text-decoration: underline; }

        .aco-payment-fields { display: none; margin-top: 14px; padding: 14px; background: var(--surface-2); border-radius: 10px; border: 1px solid var(--border); }
        .aco-payment-fields.active { display: block; }
        .aco-payment-note { display: flex; gap: 8px; font-size: 0.78rem; color: var(--muted); margin-top: 12px; line-height: 1.5; }
        .aco-payment-note i { margin-top: 2px; flex-shrink: 0; }
    </style>
</head>
<body>
    <?php include $is_staff ? __DIR__ . '/../staff/sidebar.php' : __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="aco-page-header">
            <h1><i class="fas fa-cart-plus" style="color:#4a3e94;"></i> Create Order for Customer</h1>
            <p>Place an order on behalf of a customer. The order will be saved directly under their account.</p>
        </div>

        <div class="aco-card aco-section">
            <div class="aco-customer-card">
                <div class="avatar"><i class="fas fa-user"></i></div>
                <div class="details">
                    <div class="name-row">
                        <span class="name"><?php echo htmlspecialchars($customer['name']); ?></span>
                        <span class="cid-pill">Customer ID: #<?php echo $customer_id; ?></span>
                    </div>
                    <div class="contact-list">
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($customer['email']); ?></span>
                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                <a href="<?php echo $portal_base; ?>/customer_details.php?id=<?php echo $customer_id; ?>" class="aco-view-profile-btn"><i class="fas fa-eye"></i> View Customer Profile</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="aco-alert error">
                <i class="fas fa-exclamation-circle"></i> Please fix the following before creating the order:
                <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="create_customer_order.php" enctype="multipart/form-data" id="placeOrderForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
            <input type="hidden" name="form_action" value="place_order">

            <div class="aco-layout">
                <!-- LEFT: Product search + cart -->
                <div>
                    <div class="aco-card aco-section">
                        <h2 class="aco-card-title"><i class="fas fa-boxes-stacked" style="color:#4a3e94;"></i> Add Products <span class="sub">Search products by name or SKU</span></h2>
                        <form method="GET" action="create_customer_order.php" class="aco-search-box">
                            <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                            <i class="fas fa-search"></i>
                            <input type="text" name="product_search" value="<?php echo htmlspecialchars($product_search); ?>" placeholder="Search product name or SKU..." onchange="this.form.submit()">
                        </form>

                        <div class="aco-product-list">
                            <?php if (empty($products)): ?>
                                <div class="aco-empty-list">
                                    <i class="fas fa-box-open"></i>
                                    <p class="main">No products found</p>
                                    <p class="sub">Try a different name or SKU.</p>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($products as $p):
                                $variants = get_product_variants((int) $p['id']);
                                $img = $p['image_url'] ? (preg_match('#^https?://#', $p['image_url']) ? $p['image_url'] : BASE_URL . '/' . ltrim($p['image_url'], '/')) : '';
                                $row_uid = 'p' . (int) $p['id'];
                            ?>
                                <div class="aco-product-row">
                                    <?php if ($img): ?><img class="thumb" src="<?php echo htmlspecialchars($img); ?>" alt=""><?php else: ?><div class="thumb"></div><?php endif; ?>
                                    <div class="info">
                                        <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                                        <div class="meta">
                                            <span class="price">₱<?php echo number_format((float) $p['price'], 2); ?></span>
                                            <span>Stock: <?php echo (int) $p['stock']; ?></span>
                                        </div>
                                    </div>
                                    <form method="POST" action="create_customer_order.php" class="aco-add-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="add_item">
                                        <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                                        <input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
                                        <?php if (!empty($variants)): ?>
                                            <select name="variant_id" required>
                                                <?php foreach ($variants as $v): ?>
                                                    <option value="<?php echo (int) $v['id']; ?>">
                                                        <?php echo htmlspecialchars($v['size']); ?> — ₱<?php echo number_format((float) $v['price'], 2); ?> (<?php echo (int) $v['stock']; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                        <div class="aco-qty-stepper">
                                            <button type="button" onclick="acoStep('<?php echo $row_uid; ?>', -1)" aria-label="Decrease quantity">−</button>
                                            <input type="number" name="quantity" id="qty-<?php echo $row_uid; ?>" value="1" min="1" max="<?php echo (int) $p['stock']; ?>" required>
                                            <button type="button" onclick="acoStep('<?php echo $row_uid; ?>', 1)" aria-label="Increase quantity">+</button>
                                        </div>
                                        <button type="submit" class="aco-add-btn" <?php echo ((int) $p['stock'] <= 0) ? 'disabled title="Out of stock"' : ''; ?>><i class="fas fa-plus"></i> Add</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="aco-card aco-section" id="order-cart">
                        <h2 class="aco-card-title"><i class="fas fa-cart-shopping" style="color:#4a3e94;"></i> Order Cart</h2>
                        <?php if (empty($cart)): ?>
                            <div class="aco-empty-list">
                                <i class="fas fa-cart-arrow-down"></i>
                                <p class="main">Your order is empty</p>
                                <p class="sub">Add products from the list above.</p>
                            </div>
                        <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="aco-cart-table">
                                <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($cart as $key => $item):
                                        $base_name = $item['name'];
                                        if (!empty($item['size']) && str_ends_with($base_name, ' (' . $item['size'] . ')')) {
                                            $base_name = substr($base_name, 0, -(strlen($item['size']) + 3));
                                        }
                                    ?>
                                        <tr>
                                            <td class="prod-cell">
                                                <span class="prod-name"><?php echo htmlspecialchars($base_name); ?></span>
                                                <?php if (!empty($item['size'])): ?><span class="variant-chip"><?php echo htmlspecialchars($item['size']); ?></span><?php endif; ?>
                                            </td>
                                            <td data-label="Qty"><?php echo (int) $item['quantity']; ?></td>
                                            <td class="num" data-label="Unit Price">₱<?php echo number_format((float) $item['unit_price'], 2); ?></td>
                                            <td class="num" data-label="Subtotal">₱<?php echo number_format((float) $item['unit_price'] * (int) $item['quantity'], 2); ?></td>
                                            <td>
                                                <form method="POST" action="create_customer_order.php" style="display:inline;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="form_action" value="remove_item">
                                                    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                                    <button type="submit" class="aco-remove-btn" title="Remove"><i class="fas fa-trash"></i></button>
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

                <!-- RIGHT: Customer / delivery / payment / summary -->
                <div class="aco-right-col">
                    <div class="aco-card aco-section">
                        <h2 class="aco-card-title"><i class="fas fa-truck-fast" style="color:#4a3e94;"></i> Fulfillment</h2>

                        <div class="aco-fulfillment-options">
                            <label class="aco-fulfillment-card" id="pickup-card">
                                <input type="radio" name="fulfillment_type" value="pickup" <?php echo $form_values['fulfillment_type'] === 'pickup' ? 'checked' : ''; ?> onchange="acoToggleFulfillment()">
                                <div class="icon"><i class="fas fa-store"></i></div>
                                <div class="label">Pickup</div>
                                <div class="desc">Customer picks up in-store</div>
                            </label>
                            <label class="aco-fulfillment-card" id="delivery-card">
                                <input type="radio" name="fulfillment_type" value="delivery" <?php echo $form_values['fulfillment_type'] === 'delivery' ? 'checked' : ''; ?> onchange="acoToggleFulfillment()">
                                <div class="icon"><i class="fas fa-motorcycle"></i></div>
                                <div class="label">Delivery</div>
                                <div class="desc">Deliver to their address</div>
                            </label>
                        </div>

                        <div id="pickup-fields">
                            <div class="aco-field-row">
                                <div class="aco-field-group">
                                    <label>Pickup Date</label>
                                    <input type="date" name="pickup_date" value="<?php echo htmlspecialchars($form_values['pickup_date']); ?>">
                                </div>
                                <div class="aco-field-group">
                                    <label>Pickup Time</label>
                                    <input type="time" name="pickup_time" value="<?php echo htmlspecialchars($form_values['pickup_time']); ?>">
                                </div>
                            </div>
                        </div>

                        <div id="delivery-fields" style="display:none;">
                            <div class="aco-field-group">
                                <label>Delivery Address</label>
                                <?php if (empty($customer_addresses)): ?>
                                    <div class="aco-address-warning">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        <span>No saved delivery address found for this customer. Enter one below for this order.</span>
                                    </div>
                                <?php endif; ?>
                                <select name="address_option" id="address_option" onchange="acoToggleAddress()">
                                    <?php foreach ($customer_addresses as $addr): ?>
                                        <option value="<?php echo (int) $addr['id']; ?>" <?php echo (string) $addr['id'] === $form_values['address_option'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($addr['label']); ?> — <?php echo htmlspecialchars(mb_strimwidth($addr['full_address'], 0, 50, '...')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="new" <?php echo ($form_values['address_option'] === 'new' || empty($customer_addresses)) ? 'selected' : ''; ?>>-- Enter a new address --</option>
                                </select>
                            </div>
                            <div id="new-address-fields" style="<?php echo (!empty($customer_addresses) && $form_values['address_option'] !== 'new') ? 'display:none;' : ''; ?>">
                                <div class="aco-field-group">
                                    <label>Address</label>
                                    <input type="text" name="new_delivery_address" value="<?php echo htmlspecialchars($form_values['new_delivery_address']); ?>" placeholder="Full delivery address">
                                </div>
                                <div class="aco-field-group">
                                    <label>Contact Number</label>
                                    <input type="text" name="new_delivery_phone" value="<?php echo htmlspecialchars($form_values['new_delivery_phone']); ?>" placeholder="09XXXXXXXXX">
                                </div>
                            </div>
                            <div class="aco-field-group">
                                <label>Delivery Instructions <span class="hint">Optional</span></label>
                                <textarea name="delivery_instructions" rows="2"><?php echo htmlspecialchars($form_values['delivery_instructions']); ?></textarea>
                            </div>
                        </div>

                        <div class="aco-field-group">
                            <label>Order Notes <span class="hint">Optional</span></label>
                            <textarea name="order_notes" rows="2" placeholder="e.g. Ordered via phone call / Facebook chat"><?php echo htmlspecialchars($form_values['order_notes']); ?></textarea>
                        </div>
                    </div>

                    <div class="aco-card aco-section">
                        <h2 class="aco-card-title"><i class="fas fa-credit-card" style="color:#4a3e94;"></i> Payment</h2>
                        <div class="aco-field-group">
                            <label>Payment Method</label>
                            <select name="payment_method" id="payment_method" onchange="acoTogglePayment()">
                                <option value="cod" <?php echo $form_values['payment_method'] === 'cod' ? 'selected' : ''; ?>>Cash on Delivery</option>
                                <option value="pay_at_shop" <?php echo $form_values['payment_method'] === 'pay_at_shop' ? 'selected' : ''; ?>>Cash (Pay at Shop)</option>
                                <option value="gcash" <?php echo $form_values['payment_method'] === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                                <option value="bank" <?php echo $form_values['payment_method'] === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                            </select>
                        </div>

                        <div class="aco-payment-fields" id="gcash-fields">
                            <div class="aco-field-group">
                                <label>GCash Reference Number <span class="hint">13 digits</span></label>
                                <input type="text" name="gcash_reference_number" value="<?php echo htmlspecialchars($form_values['gcash_reference_number']); ?>" maxlength="13">
                            </div>
                            <div class="aco-field-group">
                                <label>Receipt Screenshot</label>
                                <input type="file" name="gcash_payment_proof" accept="image/*">
                            </div>
                        </div>

                        <div class="aco-payment-fields" id="bank-fields">
                            <div class="aco-field-group">
                                <label>Bank Name</label>
                                <input type="text" name="bank_name" value="<?php echo htmlspecialchars($form_values['bank_name']); ?>">
                            </div>
                            <div class="aco-field-group">
                                <label>Account Name Used</label>
                                <input type="text" name="bank_account_name" value="<?php echo htmlspecialchars($form_values['bank_account_name']); ?>">
                            </div>
                            <div class="aco-field-group">
                                <label>Reference Number</label>
                                <input type="text" name="bank_reference_number" value="<?php echo htmlspecialchars($form_values['bank_reference_number']); ?>">
                            </div>
                            <div class="aco-field-group">
                                <label>Proof of Payment</label>
                                <input type="file" name="payment_proof" accept="image/*">
                            </div>
                        </div>

                        <p class="aco-payment-note">
                            <i class="fas fa-circle-info"></i> If the customer already paid (chat/phone), enter the reference number and upload the screenshot they sent — the order still goes through the same Pending → Confirmed verification staff/admin already use for online orders. It is never marked Paid automatically.
                        </p>
                    </div>

                    <div class="aco-card aco-summary-card aco-section">
                        <h2 class="aco-card-title"><i class="fas fa-receipt" style="color:#4a3e94;"></i> Order Summary</h2>
                        <div class="aco-summary-row"><span>Subtotal</span><span>₱<?php echo number_format($cart_subtotal_ex_vat, 2); ?></span></div>
                        <div class="aco-summary-row"><span>VAT (12%)</span><span>₱<?php echo number_format($cart_vat, 2); ?></span></div>
                        <div class="aco-summary-row"><span>Delivery Fee</span><span>₱<?php echo number_format($cart_delivery_fee, 2); ?></span></div>
                        <div class="aco-summary-row"><span>Discount</span><span>-₱0.00</span></div>
                        <div class="aco-summary-row total"><span>Grand Total</span><span>₱<?php echo number_format($cart_grand_total, 2); ?></span></div>

                        <div class="aco-field-error" id="cartValidationError">Add at least one product to continue.</div>
                        <button type="submit" class="aco-submit-btn" id="submitOrderBtn" <?php echo empty($cart) ? 'disabled' : ''; ?>><i class="fas fa-check"></i> Create Order</button>
                        <a href="<?php echo $portal_base; ?>/customers.php" class="aco-cancel-link">Cancel and back to Customers</a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function acoToggleFulfillment() {
            const isDelivery = document.querySelector('input[name="fulfillment_type"]:checked').value === 'delivery';
            document.getElementById('pickup-fields').style.display = isDelivery ? 'none' : 'block';
            document.getElementById('delivery-fields').style.display = isDelivery ? 'block' : 'none';
            document.getElementById('pickup-card').classList.toggle('active', !isDelivery);
            document.getElementById('delivery-card').classList.toggle('active', isDelivery);
        }
        function acoToggleAddress() {
            const sel = document.getElementById('address_option');
            document.getElementById('new-address-fields').style.display = (sel.value === 'new') ? 'block' : 'none';
        }
        function acoTogglePayment() {
            const method = document.getElementById('payment_method').value;
            document.getElementById('gcash-fields').classList.toggle('active', method === 'gcash');
            document.getElementById('bank-fields').classList.toggle('active', method === 'bank');
        }
        function acoStep(uid, delta) {
            const input = document.getElementById('qty-' + uid);
            if (!input) return;
            const min = parseInt(input.min || '1', 10);
            const max = parseInt(input.max || '999999', 10);
            let next = (parseInt(input.value, 10) || min) + delta;
            if (next < min) next = min;
            if (next > max) next = max;
            input.value = next;
        }
        acoToggleFulfillment();
        acoTogglePayment();

        // Prevent double-submission on a slow connection / accidental double-click.
        document.getElementById('placeOrderForm').addEventListener('submit', function () {
            const btn = this.querySelector('.aco-submit-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Order...'; }
        });
    </script>
</body>
</html>
