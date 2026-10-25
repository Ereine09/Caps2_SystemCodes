<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../modules/customers/achievements_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/upload_helper.php';
require_once __DIR__ . '/includes/checkout_helpers.php';
require_once __DIR__ . '/../app/helpers/ebike_helper.php';
require_customer_login(); // Checkout requires a logged-in customer.

// Delivery is offered while at least one e-bike is available (per-bike On/Off + status,
// see app/helpers/ebike_helper.php). Pickup is never affected.
$is_rider_available = ebike_delivery_available($conn);

// Handle quantity updates from checkout page form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_checkout_quantity'])) {
    $customer = current_customer();
    $quantity = (int)$_POST['quantity'];

    if ($customer && is_buy_now_active()) {
        // Buy Now mode: adjust the session item, tbl_cart is never involved.
        if ($quantity <= 0) {
            clear_buy_now();
            header('Location: ' . BASE_URL . '/customer/products.php');
        } else {
            update_buy_now_quantity($quantity);
            header('Location: checkout.php');
        }
        exit();
    }

    $product_id = (int)$_POST['product_id'];
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int)$_POST['variant_id'] : null;
    if ($customer && update_customer_cart_item((int)$customer['id'], $product_id, $quantity, $variant_id)) {
        header('Location: checkout.php');
        exit();
    }
}

// Trash icon on a product row in the checkout Order Summary: drop that item and
// come right back to checkout.php (which redirects to the cart if that emptied it).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_checkout_item'])) {
    $customer = current_customer();

    if ($customer && is_buy_now_active()) {
        // Only one item exists in Buy Now mode - removing it leaves nothing to check out.
        clear_buy_now();
        header('Location: ' . BASE_URL . '/customer/products.php');
        exit();
    }

    $product_id = (int)$_POST['product_id'];
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int)$_POST['variant_id'] : null;
    if ($customer) {
        remove_customer_cart_item((int)$customer['id'], $product_id, $variant_id);
    }
    header('Location: checkout.php');
    exit();
}

$cart = get_customer_cart(true); // only the items ticked on the Cart page
if (empty($cart)) {
    if (!is_buy_now_active() && cart_item_count() > 0) {
        // Items in the cart, but none ticked.
        $_SESSION['message'] = 'Select at least one item to check out.';
        $_SESSION['message_type'] = 'error';
        header('Location: ' . BASE_URL . '/customer/cart.php');
        exit();
    }
    header('Location: ' . BASE_URL . '/customer/products.php');
    exit();
}
$customer = current_customer();

$errors = [];
$success_message = '';
$stock_deducted = false; 
$order_details = null;

// Fetch customer's saved addresses
$customer_addresses = [];
$addresses_res = mysqli_query($conn, "SELECT * FROM customer_addresses WHERE customer_id = " . (int)$customer['id'] . " ORDER BY is_default DESC, created_at DESC");
if ($addresses_res) while ($row = mysqli_fetch_assoc($addresses_res)) $customer_addresses[] = $row;

$admin_message = "Please scan the QR code below and enter the 13-digit GCash reference number after payment. Your order will be processed once payment is verified.";
$bank_admin_message = "Please transfer the total amount to the account details below. Upload a screenshot of your transaction receipt and enter the reference number to confirm.";

$voucher_code_input = '';
$discount_amount = 0.00;

// Claimed-voucher picker (Step 2 below): lets the customer pick from vouchers
// they've already redeemed instead of hunting for the code on My Rewards and
// pasting it in. Same ownership/channel rule checkout_validate_voucher()
// enforces server-side — only ONLINE-channel redemptions ever get a
// tbl_vouchers row (see customer/reward_redemption.php), so this naturally
// excludes in-store-only codes. Used/expired ones are still listed (greyed
// out, disabled) so the customer can see why they're not selectable instead
// of them just silently disappearing.
try {
    if (is_file(__DIR__ . '/../app/helpers/free_shipping_helper.php')) {
        require_once __DIR__ . '/../app/helpers/free_shipping_helper.php';
        free_shipping_sync($conn, (int) $customer['id']); // a 10th order this month may just have been completed
    }
} catch (Throwable $e) {
    error_log('[checkout] Free Shipping sync failed: ' . $e->getMessage());
}
$my_vouchers = [];
$has_usable_voucher = false;
promo_ensure_schema($conn); // promo_kind (admin voucher promos)
$voucher_list_res = mysqli_query(
    $conn,
    "SELECT rr.reward_name, rr.status AS redemption_status, rr.card_number AS code,
            v.discount_type, v.discount_value, v.expires_at, v.active, v.usage_limit, v.used_count,
            NULL AS promo_kind, NULL AS per_customer_limit
     FROM reward_redemptions rr
     JOIN tbl_vouchers v ON v.code = rr.card_number
     WHERE rr.customer_id = " . (int)$customer['id'] . " AND rr.channel = 'ONLINE'
     UNION ALL
     SELECT v.description AS reward_name, 'Active' AS redemption_status, v.code,
            v.discount_type, v.discount_value, v.expires_at, v.active, v.usage_limit, v.used_count,
            v.promo_kind, v.per_customer_limit
     FROM tbl_vouchers v
     WHERE (v.owner_customer_id = " . (int)$customer['id'] . " AND (v.discount_type = 'free_shipping' OR v.promo_kind = 'assigned'))
        OR (v.promo_kind = 'public' AND v.active = 1)"
);
if ($voucher_list_res) {
    while ($row = mysqli_fetch_assoc($voucher_list_res)) {
        $is_used = strtolower((string)$row['redemption_status']) === 'used';
        $is_expired = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
        $is_exhausted = $row['usage_limit'] !== null && (int)$row['usage_limit'] > 0 && (int)$row['used_count'] >= (int)$row['usage_limit'];
        if (($row['promo_kind'] ?? '') === 'public' && (int)($row['per_customer_limit'] ?? 0) > 0
            && voucher_customer_use_count($conn, (string)$row['code'], (int)$customer['id']) >= (int)$row['per_customer_limit']) {
            $is_exhausted = true; // this customer already used the promo code
        }
        if (($row['promo_kind'] ?? '') !== '' && (int)$row['active'] !== 1) {
            continue; // pulled by the admin
        }
        $usable = !$is_used && !$is_expired && !$is_exhausted && (int)$row['active'] === 1;
        if ($is_used || $is_exhausted) {
            continue; // already used vouchers are not listed
        }
        if ($is_used || $is_exhausted) {
            continue; // already-used vouchers are not listed
        }

        $discount_label = $row['discount_type'] === 'free_shipping' ? 'Free delivery' : ($row['discount_type'] === 'percent'
            ? (rtrim(rtrim(number_format((float)$row['discount_value'], 2), '0'), '.') . '% off')
            : ('₱' . number_format((float)$row['discount_value'], 0) . ' off'));
        $status_label = $is_used ? 'Already used' : ($is_expired ? 'Expired' : ($is_exhausted ? 'Unavailable' : ''));

        $my_vouchers[] = [
            'code' => $row['code'],
            'reward_name' => $row['reward_name'],
            'discount_label' => $discount_label,
            'expires_label' => !empty($row['expires_at']) ? date('M d, Y', strtotime($row['expires_at'])) : null,
            'status_label' => $status_label,
            'usable' => $usable,
        ];
        if ($usable) {
            $has_usable_voucher = true;
        }
    }
}

// Coming back from the Payment step ("Edit details"): re-fill the form from the saved draft.
// The template below reads its default values from $_POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['checkout_draft'])
    && (int)($_SESSION['checkout_draft']['customer_id'] ?? 0) === (int)$customer['id']) {
    $draft = $_SESSION['checkout_draft'];
    foreach (['fulfillment_type', 'pickup_date', 'pickup_time', 'order_notes'] as $k) {
        if (isset($draft[$k])) $_POST[$k] = $draft[$k];
    }
    $voucher_code_input = (string)($draft['voucher_code'] ?? '');
    if (!$is_rider_available && ($_POST['fulfillment_type'] ?? '') === 'delivery') {
        $_POST['fulfillment_type'] = 'pickup';
        $errors[] = EBIKE_DELIVERY_UNAVAILABLE_MESSAGE;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_checkout_quantity']) && !isset($_POST['remove_checkout_item'])) {
    $fulfillment_type = $_POST['fulfillment_type'] ?? 'pickup';
    $address_option = $_POST['address_option'] ?? 'new';
    $delivery_address = '';
    $delivery_phone = '';

    $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;    
    if ($address_option === 'new') {
        $delivery_address = trim($_POST['new_delivery_address'] ?? '');
        $delivery_phone = trim($_POST['new_delivery_phone'] ?? '');
    } else {
        $saved_address_stmt = $conn->prepare(
            "SELECT full_address, phone, latitude, longitude
             FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1"
        );
        $saved_address_id = (int)$address_option;
        $saved_address_stmt->bind_param('ii', $saved_address_id, $customer['id']);
        $saved_address_stmt->execute();
        $saved_address = $saved_address_stmt->get_result()->fetch_assoc();
        $saved_address_stmt->close();

        if ($saved_address) {
            $delivery_address = trim($saved_address['full_address']);
            $delivery_phone = trim($saved_address['phone'] ?? '');
            $latitude = is_numeric($saved_address['latitude']) ? (float)$saved_address['latitude'] : null;
            $longitude = is_numeric($saved_address['longitude']) ? (float)$saved_address['longitude'] : null;
        } else {
            $errors[] = 'Please select a valid saved delivery address.';
        }
    }
    $order_notes = trim($_POST['order_notes'] ?? '');
    $delivery_instructions = trim($_POST['delivery_instructions'] ?? '');
    $pickup_date = trim($_POST['pickup_date'] ?? '');
    $pickup_time = trim($_POST['pickup_time'] ?? '');
    $voucher_code_input = trim($_POST['voucher_code'] ?? '');

    if ($fulfillment_type === 'delivery') {
        if ($delivery_address === '') {
            $errors[] = 'Delivery address is required for delivery orders.';
        }
        if ($delivery_phone === '') {
            $errors[] = 'Delivery phone number is required.';
        }
        // The is_delivery_area_allowed function seems to be legacy. The logic is handled by distance or address string checks now.
        // if (!is_delivery_area_allowed($delivery_address)) {
        //     $errors[] = 'Delivery is currently limited to Caloocan, 10th Avenue, and Grace Park.';
        // }
        
        if ($latitude === null || $longitude === null) {
            // A saved address used to silently pass through here with a null lat/lon (only
            // 'new' addresses were checked) and only get rejected later, at payment.php's
            // "A delivery location is required" - catch it here instead, at the actual step
            // where the customer can still do something about it.
            $errors[] = $address_option === 'new'
                ? 'Please select your location on the map for a new delivery address.'
                : 'This saved address has no map location on file. Please update it in your profile (re-pin it on the map) before checking out.';
        } elseif ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $errors[] = 'The selected delivery location is invalid. Please update it in your profile.';
        }
    } else {
        if ($pickup_date === '') {
            $errors[] = 'Pick-up date is required for pickup orders.';
        }
        if ($pickup_time === '') {
            $errors[] = 'Pick-up time is required for pickup orders.';
        }
    }

    $errors = array_merge($errors, checkout_check_stock($cart));

    if ($voucher_code_input !== '') {
        $errors = array_merge($errors, checkout_validate_voucher($conn, $customer, $voucher_code_input, $fulfillment_type)['errors']);
    }

    // Availability may have changed since this page was opened - re-check the live fleet state.
    if ($fulfillment_type === 'delivery' && !ebike_delivery_available($conn)) {
        $errors = [EBIKE_DELIVERY_UNAVAILABLE_MESSAGE];
        $is_rider_available = false;
        $_POST['fulfillment_type'] = 'pickup'; // re-render the form on Pick-up
    }

    if (empty($errors)) {
        // Step 1 done: keep the details in the session and move on to the
        // separate Payment step (customer/payment.php), which places the order.
        $_SESSION['checkout_draft'] = [
            'customer_id' => (int)$customer['id'],
            'fulfillment_type' => $fulfillment_type,
            'delivery_address' => $delivery_address,
            'delivery_phone' => $delivery_phone,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'order_notes' => $order_notes,
            'delivery_instructions' => $delivery_instructions,
            'pickup_date' => $pickup_date,
            'pickup_time' => $pickup_time,
            'voucher_code' => $voucher_code_input,
        ];
        header('Location: payment.php');
        exit();
    }
}
?>
<?php $page_title = 'Checkout'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<!-- Free Map Alternative: Leaflet.js (No API Key or Billing Required) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

<style>
    .page-back { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; padding: 8px 14px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); color: var(--text-2); text-decoration: none; font-weight: 600; font-size: .9rem; }
    .page-back:hover { background: var(--surface-2); color: var(--text); }
    /* Layout fix */
    .checkout-grid {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 30px;
        align-items: start;
        margin-top: 20px;
    }

    .checkout-summary, .checkout-form {
        background: var(--surface);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--border);
    }

    /* Fix for radio buttons and labels alignment */
    .option-group {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 20px;
        margin-bottom: 20px;
    }

    .option-label {
        display: flex;
        align-items: center; /* Magic para pumantay sa gitna */
        gap: 8px;
        cursor: pointer;
        font-weight: 500;
        color: var(--text-2);
    }

    .form-row {
        margin-bottom: 15px;
    }

    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--text);
    }

    input[type="text"], input[type="date"], input[type="time"], textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        box-sizing: border-box;
        font-size: 1rem; /* >=16px avoids iOS Safari auto-zoom on focus */
    }

    input[type="file"] { max-width: 100%; }

    #map, #map-container { max-width: 100%; }
    /* Type-your-address box with suggestions (new delivery address) */
    .addr-search { position: relative; margin-top: 15px; }
    .addr-search-input { position: relative; }
    .addr-search-ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); display: flex; color: var(--muted); pointer-events: none; } /* a span: Font Awesome may swap the <i> for an <svg> */
    .addr-search-input input { width: 100%; padding-left: 40px !important; padding-right: 40px !important; box-sizing: border-box; }
    .addr-search-clear { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); display: grid; place-items: center; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 50%; background: transparent; color: var(--muted); cursor: pointer; }
    .addr-search-clear[hidden] { display: none; }
    .addr-search-clear:hover { background: var(--surface-2); color: var(--text); }
    .addr-suggest { position: absolute; z-index: 1100; left: 0; right: 0; top: calc(100% + 6px); margin: 0; padding: 6px; list-style: none; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 12px 30px rgba(15, 23, 42, .14); max-height: 300px; overflow-y: auto; }
    .addr-suggest li { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 8px; cursor: pointer; }
    .addr-suggest .addr-pin { flex-shrink: 0; color: var(--dk-primary, #6366f1); margin-top: 2px; }
    .addr-suggest li strong { display: block; font-size: .92rem; color: var(--text); }
    .addr-suggest li small { display: block; font-size: .8rem; color: var(--muted); line-height: 1.4; }
    .addr-suggest li:hover, .addr-suggest li.is-active { background: var(--primary-soft); }
    .addr-suggest li.is-info { cursor: default; color: var(--muted); font-size: .88rem; }
    .addr-suggest li.is-info:hover { background: transparent; }
    .addr-hint { margin: 6px 2px 0; font-size: .8rem; color: var(--muted); }

    /* Organized Summary Table */
    .summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    .summary-table th { text-align: left; padding: 10px; color: var(--muted); font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid var(--border); }
    .summary-table td { padding: 12px 10px; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }
    
    .summary-footer {
        background: var(--surface-2);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    .summary-line { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.95rem; color: var(--text-2); }
    .summary-line.total { border-top: 2px solid var(--border); margin-top: 15px; padding-top: 15px; font-weight: 800; color: var(--text); font-size: 1.2rem; }
    .summary-item-img { width: 45px; height: 45px; object-fit: contain; border-radius: 6px; background: var(--surface); border: 1px solid var(--border); }

    /* applying style css */
    .btn-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.8s linear infinite;
    margin-right: 6px;
    vertical-align: middle;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Subtle pulse glow effect during loading */
    .button-loading {
        opacity: 0.8;
        cursor: not-allowed !important;
        animation: pulse-glow 1.5s ease-in-out infinite;
    }

    @keyframes pulse-glow {
        0% { opacity: 0.8; }
        50% { opacity: 0.5; }
        100% { opacity: 0.8; }
    }

    /* Delivery validation message styles */
    .delivery-validation-msg {
        font-size: 0.9rem; font-weight: 600; padding: 10px; border-radius: 8px; margin-top: 10px;
        text-align: center;
    }
    .delivery-validation-msg.valid { background: var(--dk-success-soft, #eefbf3); color: var(--success); border: 1px solid #a7f3d0; }
    .delivery-validation-msg.invalid { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }

    /* Rider Status Badge Styles */
    .rider-status-box {
        margin-top: 10px;
        margin-bottom: 20px;
        padding: 12px 15px;
        border-radius: 10px;
        font-size: 0.88rem;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        line-height: 1.4;
    }
    .rider-status-box.available {
        background-color: var(--success-soft);
        border: 1px solid #a7f3d0;
        color: var(--success);
    }
    .rider-status-box.unavailable {
        background-color: var(--danger-soft);
        border: 1px solid #fecaca;
        color: var(--danger);
    }
    
    /* GCash Section Styling */
    .payment-options {
        border-top: 1px solid var(--border);
        padding-top: 20px;
        margin-top: 20px;
    }

    .payment-method-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        margin-bottom: 10px;
        cursor: pointer;
    }

    /* Modal Zoomer */
    .modal-zoomer {
        display: none;
        position: fixed;
        z-index: 9999;
        padding-top: 100px;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.9);
    }

    .modal-content-zoomer {
        margin: auto;
        display: block;
        width: 80%;
        max-width: 500px;
        animation: zoom 0.6s;
    }

    @keyframes zoom { from {transform:scale(0)} to {transform:scale(1)} }

    .address-card-option { background: var(--surface-2); border-radius: 12px; padding: 15px; border: 2px solid var(--border); margin-bottom: 10px; cursor: pointer; }
    .address-card-option label { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
    .address-card-option input[type="radio"] { margin-top: 5px; }
    .address-card-option.default { border-color: var(--dk-primary, #6366f1); }
    .address-card-option:has(input:checked) { border-color: var(--dk-primary, #6366f1); background: var(--primary-soft); }
    .address-details .address-label { font-weight: 700; color: var(--text); }
    .address-details .address-text { font-size: 0.9rem; color: var(--text-2); margin: 4px 0 0; }
    .address-details .address-phone { font-size: 0.85rem; color: var(--muted); margin: 4px 0 0; }
    .default-badge {
        background: var(--dk-primary, #6366f1); color: var(--dk-on-primary, white); padding: 2px 8px;
        border-radius: 5px; margin-left: 8px; font-size: 0.7rem;
        text-transform: uppercase;
    }




    .close-zoomer {
        position: absolute;
        top: 50px;
        right: 35px;
        color: #f1f1f1;
        font-size: 40px;
        font-weight: bold;
        cursor: pointer;
    }

    .gcash-details {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 15px;
        background-color: var(--surface-2);
        margin-top: 10px;
    }

    .qr-wrapper {
        cursor: pointer;
        display: inline-block;
        border: 1px solid var(--border);
        padding: 5px;
        background: var(--surface);
        text-align: center;
    }

    .qr-wrapper img { width: 150px; height: auto; }

    /* ----------------------------------------------------------------
       "SCAN TO PAY" payment-info panel — a reference board shown above
       the existing per-method payment forms so the customer can see the
       real payment channels before picking one. Purely informational:
       it never changes what's submitted (the radios/forms above still
       drive that exactly as before). Uses the customer portal's shared
       --customer-* theme variables (assets/css/customer_style.css) so it
       follows Light/Dark Mode automatically with no separate dark-mode
       block needed here; the QR image itself is never themed/filtered,
       only its white card background, per "QR must stay scannable".
       ---------------------------------------------------------------- */
    .scan-to-pay {
        border: 1px solid var(--customer-border, #e2e8f0);
        border-radius: 12px;
        padding: 18px;
        margin-bottom: 18px;
        background: var(--customer-surface-2, #f8fafc);
    }
    .scan-to-pay h4 {
        margin: 0 0 14px;
        text-align: center;
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        color: var(--customer-text, #1e293b);
    }
    .stp-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 14px;
    }
    .stp-card {
        background: var(--customer-surface, #ffffff);
        border: 2px solid var(--customer-border, #e2e8f0);
        border-radius: 10px;
        padding: 14px;
        text-align: center;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .stp-card.active {
        border-color: var(--dk-primary, #6366f1);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    }
    .stp-card-label {
        font-weight: 800;
        font-size: 0.85rem;
        letter-spacing: 0.04em;
        color: var(--customer-text, #1e293b);
        margin-bottom: 10px;
    }
    .stp-qr-box {
        background: var(--surface); /* always white/light — never themed — so the QR stays scannable in dark mode */
        border: 1px solid var(--customer-border, #e2e8f0);
        border-radius: 8px;
        padding: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 150px;
    }
    .stp-qr-box img {
        width: 100%;
        max-width: 160px;
        height: auto;
        object-fit: contain;
        display: block;
    }
    .stp-account-icon {
        width: 100%;
        min-height: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.4rem;
        background: var(--surface);
        border: 1px solid var(--customer-border, #e2e8f0);
        border-radius: 8px;
    }
    .stp-account-text {
        margin-top: 10px;
        font-size: 0.8rem;
        color: var(--customer-muted, #64748b);
        word-break: break-word;
        line-height: 1.5;
    }
    .stp-account-text strong { color: var(--customer-text, #1e293b); }
    .stp-note {
        margin: 12px 2px 0;
        font-size: 0.78rem;
        color: var(--customer-muted, #64748b);
        text-align: center;
    }

    @media (max-width: 900px) {
        .checkout-grid {
            grid-template-columns: 1fr;
        }
    }

    /* ==========================================================================
       Checkout look-and-feel (.co-*). Order Summary keeps to a few rows (extra
       items live in a collapsed, scrollable list) and stays in view while the form
       scrolls; the form is split into numbered sections. Colours use the
       --customer-* variables so dark mode follows; text colours are scoped
       `.customer-panel .co` to out-rank the dark-mode "inherit" rule.
       ========================================================================== */
    .customer-panel .co {
        --k-surface: var(--customer-surface, #fff);
        --k-surface-2: var(--customer-surface-2, #f8fafc);
        --k-text: var(--customer-text, #1e293b);
        --k-muted: var(--customer-muted, #64748b);
        --k-border: var(--customer-border, #e2e8f0);
        --k-edge: color-mix(in srgb, var(--k-text) 22%, var(--k-border));
        --k-link: var(--customer-link, var(--primary));
        grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.3fr);
        gap: 22px;
    }
    .co *, .co *::before, .co *::after { box-sizing: border-box; }
    .customer-panel .co .checkout-summary, .customer-panel .co .checkout-form {
        background: var(--k-surface); border: 2px solid var(--k-edge); border-radius: 16px; padding: 18px 20px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07);
    }
    .co-summary { position: sticky; top: 16px; }
    .customer-panel .co h3 { color: var(--k-text); }
    .customer-panel .co .co-summary > h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; padding-bottom: 10px; border-bottom: 1.5px solid var(--k-edge); font-size: 1.05rem; }
    .customer-panel .co .co-count { margin-left: auto; padding: 2px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: var(--k-surface-2); border: 1px solid var(--k-border); color: var(--k-muted); }

    .co-items { list-style: none; margin: 0; padding: 0; }
    .co-item { display: grid; grid-template-columns: 44px minmax(0, 1fr) auto auto; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--k-border); }
    .co-item:last-child { border-bottom: 0; }
    .co-thumb { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; background: var(--k-surface-2); border: 1px solid var(--k-border); }
    .co-item-main { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
    .customer-panel .co .co-item-name { font-size: 0.85rem; font-weight: 600; line-height: 1.3; color: var(--k-text);
        display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .customer-panel .co .co-item-meta { font-size: 0.75rem; color: var(--k-muted); }
    /* Quantity: bigger and easy to spot */
    .customer-panel .co .co-item-qty { align-self: flex-start; margin-top: 4px; padding: 2px 10px; border-radius: 999px; background: var(--primary-soft); color: var(--k-link, var(--link)); font-size: 0.85rem; font-weight: 800; }
    .customer-panel .co .co-item-total { font-size: 0.85rem; font-weight: 700; color: var(--k-text); white-space: nowrap; }
    .co-item-remove { margin: 0; }
    .co-item-remove button {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; padding: 0; border-radius: 8px;
        background: transparent; border: 1.5px solid var(--k-border); color: var(--danger);
        cursor: pointer; font-size: 0.82rem; transition: background .15s ease, border-color .15s ease;
    }
    .co-item-remove button:hover { background: var(--danger-soft); border-color: #fecaca; }

    .co-more { margin: 2px 0 6px; }
    .co-more > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 8px 12px; border-radius: 10px; font-size: 0.82rem; font-weight: 700;
        background: var(--k-surface-2); border: 1.5px dashed var(--k-edge); }
    .customer-panel .co .co-more > summary { color: var(--k-link); }
    .co-more > summary::-webkit-details-marker { display: none; }
    .co-more > summary:hover { border-style: solid; }
    .co-more .co-more-close { display: none; }
    .co-more[open] .co-more-open { display: none; }
    .co-more[open] .co-more-close { display: inline; }
    .co-items-scroll { max-height: 264px; overflow-y: auto; margin-top: 6px; padding: 0 8px; border: 1.5px solid var(--k-edge); border-radius: 12px; background: var(--k-surface); }

    .customer-panel .co .summary-footer { margin-top: 10px; padding: 12px 14px; border-radius: 12px; background: var(--k-surface-2); border: 1.5px solid var(--k-edge); }
    .customer-panel .co .summary-line { margin-bottom: 6px; font-size: 0.9rem; color: var(--k-muted); }
    .customer-panel .co .summary-line > span:last-child { color: var(--k-text); }
    .customer-panel .co .summary-line[style*="10b981"] > span, .customer-panel .co .summary-line[style*="10b981"] > span:last-child { color: var(--success); }
    .customer-panel .co .summary-line.total { margin: 10px 0 0; padding-top: 10px; font-size: 1.2rem; border-top: 2px solid var(--k-edge); }
    .customer-panel .co .summary-line.total > span { color: var(--k-text); }
    .customer-panel .co .summary-line[style*="font-weight: 600; color: #334155"] > span { color: var(--k-text); }
    .co-edit { margin-top: 12px; text-align: center; }
    .customer-panel .co .co-edit a { font-size: 0.82rem; font-weight: 700; text-decoration: none; color: var(--k-link); }
    .customer-panel .co .co-edit a:hover { text-decoration: underline; }

    /* Form sections */
    .co-sec { padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1.5px dashed var(--k-border); }
    .co-sec:last-of-type { border-bottom: 0; margin-bottom: 4px; }
    .customer-panel .co .co-h { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 1rem; }
    .customer-panel .co .co-h small { font-weight: 500; color: var(--k-muted); font-size: 0.8rem; }
    .co-num { flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800; color: var(--dk-on-primary, #fff); background: var(--dk-primary, #4f46e5); }
    .co-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .co-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
    .co-option { position: relative; display: block; cursor: pointer; }
    .co-option input { position: absolute; opacity: 0; pointer-events: none; }
    .co-opt-body { display: grid; grid-template-columns: 34px 1fr; grid-template-areas: "ic t" "ic s"; column-gap: 12px; align-items: center; padding: 12px 14px; border-radius: 12px;
        background: var(--k-surface); border: 2px solid var(--k-border); transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; }
    .co-opt-body i { grid-area: ic; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: var(--k-surface-2); color: var(--k-link); font-size: 0.95rem; }
    .customer-panel .co .co-opt-body strong { grid-area: t; font-size: 0.92rem; color: var(--k-text); }
    .customer-panel .co .co-opt-body small { grid-area: s; font-size: 0.75rem; color: var(--k-muted); }
    .co-option:hover .co-opt-body { border-color: var(--k-edge); }
    .co-option:has(input:checked) .co-opt-body { border-color: var(--dk-primary, #4f46e5); background: color-mix(in srgb, var(--dk-primary, #4f46e5) 9%, var(--k-surface)); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16); }
    .co-option:has(input:checked) .co-opt-body i { background: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }
    .co-option:has(input:focus-visible) .co-opt-body { outline: 2px solid var(--dk-primary, #4f46e5); outline-offset: 2px; }
    .co-option:has(input:disabled) { cursor: not-allowed; }
    .co-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
    .co-voucher { display: flex; gap: 10px; }
    .co-voucher input { flex: 1 1 auto; min-width: 0; }
    .co-voucher-select { width: 100%; padding: 11px 12px; border-radius: 10px; font-size: 0.92rem;
        background: var(--k-surface); color: var(--k-text); border: 1.5px solid var(--k-border); }
    .co-voucher-select:disabled { opacity: 0.65; cursor: not-allowed; }
    .co-voucher-select:focus { outline: none; border-color: var(--dk-primary, #4f46e5); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16); }
    .co-voucher-select option:disabled { color: var(--muted); }
    .co-voucher-hint { margin-top: 8px; font-size: 0.8rem; color: var(--k-muted); }
    .co-voucher-hint a { color: var(--link); font-weight: 700; }
    .co-voucher-toggle { background: none; border: none; padding: 0; color: var(--k-link); font-size: 0.82rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    /* A link-style button: keep it light on hover (the site-wide button:hover would paint it solid blue). */
    .co-voucher-toggle { padding: 6px 10px; margin-left: -10px; border-radius: 8px; transition: background-color .15s ease; }
    .co-voucher-toggle:hover, .co-voucher-toggle:focus-visible { background-color: var(--primary-soft); color: var(--k-link); text-decoration: none; }

    .co-buy-now-banner {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        background: var(--warning-soft); color: var(--warning); border: 1px solid #fed7aa;
        border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.88rem; font-weight: 600;
    }
    .co-buy-now-banner i { color: #ea580c; }
    .co-buy-now-banner a { margin-left: auto; color: var(--warning); text-decoration: underline; font-weight: 700; white-space: nowrap; }
    html[data-theme="dark"] .co-buy-now-banner { background: #3a2410; color: #fdba74; border-color: #7c4a12; }
    html[data-theme="dark"] .co-buy-now-banner a { color: #fdba74; }
    .customer-panel .co .form-row label { color: var(--k-text); }
    .customer-panel .co input[type="text"], .customer-panel .co input[type="date"], .customer-panel .co input[type="time"], .customer-panel .co textarea {
        background: var(--k-surface); color: var(--k-text); border: 1.5px solid var(--k-border); }
    .customer-panel .co textarea { min-height: 0; height: 72px; resize: vertical; }
    .customer-panel .co input:focus, .customer-panel .co textarea:focus { outline: none; border-color: var(--dk-primary, #4f46e5); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16); }
    .customer-panel .co .co-continue { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; margin-top: 8px; padding: 14px; border-radius: 12px; font-size: 1rem; font-weight: 800; }
    .customer-panel .co .rider-status-box { margin: 0 0 14px; }
    .customer-panel .co .custom-select-trigger, .customer-panel .co .custom-select-options { background: var(--k-surface); color: var(--k-text); border-color: var(--k-border); }
    .customer-panel .co .custom-select-option { color: var(--k-text); border-color: var(--k-border); }
    .customer-panel .co .custom-select-option:hover { background: var(--k-surface-2); }

    /* dark mode: pieces that were hard-coded light before */
    .customer-panel .page-back { background: var(--customer-surface, #fff); border-color: var(--customer-border, #cbd5e1); color: var(--customer-muted, #475569); }
    .customer-panel .page-back:hover { background: var(--customer-surface-2, #f8fafc); color: var(--customer-text, #1e293b); }
    .customer-panel .co .co-voucher .button-secondary { background: var(--k-surface-2); color: var(--k-link); border: 1.5px solid var(--k-edge); font-weight: 700; }
    html[data-theme="dark"] .customer-panel .co .rider-status-box.available { background: #0f2f22; border-color: #1f5a3f; color: #a7f3d0; }
    html[data-theme="dark"] .customer-panel .co .rider-status-box.unavailable { background: var(--danger-soft); border-color: #7f1d1d; color: var(--danger); }

    @media (max-width: 900px) {
        .customer-panel .co { grid-template-columns: minmax(0, 1fr); }
        .co-summary { position: static; }
    }
    @media (max-width: 520px) {
        .co-options, .co-two { grid-template-columns: 1fr; }
        .customer-panel .co .checkout-summary, .customer-panel .co .checkout-form { padding: 14px; }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <a href="cart.php" class="page-back"><i class="fas fa-arrow-left"></i> Back to Cart</a>
        <?php require_once __DIR__ . '/includes/checkout_steps.php'; echo checkout_steps(2); ?>
        <h2>Checkout</h2>
        <p>Review your cart and provide delivery or pickup details. You'll choose how to pay on the next step.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert-message alert-error">
            <ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if (is_buy_now_active()): ?>
        <div class="co-buy-now-banner">
            <i class="fas fa-bolt"></i>
            <span>Buying this item now — the rest of your cart is untouched and will still be there after.</span>
            <a href="cart.php">View full cart</a>
        </div>
    <?php endif; ?>

        <div class="checkout-grid co">
            <div class="checkout-summary co-summary">
                <h3><i class="fas fa-receipt"></i> Order Summary <span class="co-count"><?php echo count($cart); ?> item<?php echo count($cart) === 1 ? '' : 's'; ?></span></h3>
                <?php
                    // Show the first few products; the rest sit in a collapsed, scrollable list so a big cart never makes the page long.
                    $co_all = array_values($cart);
                    $co_visible = 3;
                    $co_render = static function (array $item): void {
                        $size = trim((string) ($item['size'] ?? ''));
                        $name = trim((string) $item['name']);
                        if ($size !== '' && substr($name, -strlen(' (' . $size . ')')) === ' (' . $size . ')') {
                            $name = rtrim(substr($name, 0, -strlen(' (' . $size . ')')));
                        }
                        ?>
                        <li class="co-item">
                            <img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="co-thumb" alt="" loading="lazy">
                            <div class="co-item-main">
                                <span class="co-item-name"><?php echo htmlspecialchars($name); ?></span>
                                <span class="co-item-meta"><?php echo $size !== '' ? htmlspecialchars($size) . ' &bull; ' : ''; ?>PHP <?php echo number_format($item['unit_price'], 2); ?> each</span>
                                <span class="co-item-qty">Qty: <?php echo (int) $item['quantity']; ?></span>
                            </div>
                            <span class="co-item-total item-subtotal">PHP <?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?></span>
                            <form method="POST" class="co-item-remove" onsubmit="return confirm('Remove this item from your order?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int) $item['id']; ?>">
                                <input type="hidden" name="variant_id" value="<?php echo (int) ($item['variant_id'] ?? 0) > 0 ? (int) $item['variant_id'] : ''; ?>">
                                <input type="hidden" name="remove_checkout_item" value="1">
                                <button type="submit" title="Remove from order" aria-label="Remove <?php echo htmlspecialchars($name); ?> from order"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                        <?php
                    };
                ?>
                <ul class="co-items">
                    <?php foreach (array_slice($co_all, 0, $co_visible) as $item) { $co_render($item); } ?>
                </ul>
                <?php if (count($co_all) > $co_visible): ?>
                    <details class="co-more">
                        <summary>
                            <span class="co-more-open"><i class="fas fa-chevron-down"></i> Show <?php echo count($co_all) - $co_visible; ?> more item<?php echo count($co_all) - $co_visible === 1 ? '' : 's'; ?></span>
                            <span class="co-more-close"><i class="fas fa-chevron-up"></i> Hide extra items</span>
                        </summary>
                        <ul class="co-items co-items-scroll">
                            <?php foreach (array_slice($co_all, $co_visible) as $item) { $co_render($item); } ?>
                        </ul>
                    </details>
                <?php endif; ?>

                <div class="summary-footer">
                    <?php
                        $subtotal_net = cart_subtotal_ex_vat();
                        $vat_amount = round($subtotal_net * 0.12, 2);
                        $subtotal_gross = $subtotal_net + $vat_amount; // This is the base for calculations
                    ?>
                    <div class="summary-line">
                        <span>Subtotal (Net)</span>
                        <span id="subtotal-net-display" data-value="<?php echo $subtotal_net; ?>">
                            PHP <?php echo number_format($subtotal_net, 2); ?>
                        </span>
                    </div>
                    <div class="summary-line">
                        <span>VAT (12%)</span>
                        <span id="vat-display">PHP <?php echo number_format($vat_amount, 2); ?></span>
                    </div>

                    <!-- ADDED: Subtotal Gross (Net + VAT) -->
                    <div class="summary-line" style="font-weight: 600; color: #334155;">
                        <span>Subtotal</span>
                        <span id="subtotal-gross-display">PHP <?php echo number_format($subtotal_gross, 2); ?></span>
                    </div>

                    <div class="summary-line">
                        <span>Delivery Fee</span>
                        <span id="delivery-fee-display" style="font-weight: 700; color: #1e293b;">PHP 0.00</span>
                    </div>
                    <div class="summary-line" id="discount-line" style="color: #10b981; font-weight: 600; display: none;">
                        <span>Discount</span>
                        <span id="discount-display">-PHP 0.00</span>
                        <input type="hidden" id="discount-value" value="<?php echo $discount_amount; ?>">
                    </div>
                    <div class="summary-line" style="color: #10b981; font-weight: 600;">
                        <span>Points to Earn</span>
                        <span id="points-to-earn-display">
                            +<?php echo number_format($subtotal_net / 100, 2); ?> pts
                        </span>
                    </div>
                    <div class="summary-line total">
                        <span>Total to Pay</span>
                        <span id="total-to-pay-display">PHP <?php echo number_format($subtotal_gross, 2); ?></span>
                    </div>
                </div>
                <div class="co-edit">
                    <a href="cart.php"><i class="fas fa-edit"></i> <?php echo is_buy_now_active() ? 'Cancel Buy Now &amp; view full cart' : 'Edit items or quantities in cart'; ?></a>
                </div>
            </div>

            <form method="POST" action="" class="checkout-form co-form" id="checkout-form" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <section class="co-sec">
                    <h3 class="co-h"><span class="co-num">1</span> How do you want to receive your order?</h3>
                    <div class="co-options">
                        <label class="co-option">
                            <input type="radio" name="fulfillment_type" value="pickup" <?php echo (!isset($_POST['fulfillment_type']) || $_POST['fulfillment_type'] === 'pickup' || !$is_rider_available) ? 'checked' : ''; ?> onchange="toggleFulfillmentFields()">
                            <span class="co-opt-body"><i class="fas fa-store"></i><strong>Pick-up</strong><small>Get it at our shop</small></span>
                        </label>
                        <label class="co-option" id="delivery-option-label" style="<?php echo $is_rider_available ? '' : 'opacity:.6; cursor:not-allowed;'; ?>">
                            <input type="radio" id="fulfillment-delivery" name="fulfillment_type" value="delivery" <?php echo ($is_rider_available && isset($_POST['fulfillment_type']) && $_POST['fulfillment_type'] === 'delivery') ? 'checked' : ''; ?> <?php echo $is_rider_available ? '' : 'disabled'; ?> onchange="toggleFulfillmentFields()">
                            <span class="co-opt-body"><i class="fas fa-truck"></i><strong id="delivery-label-text"><?php echo $is_rider_available ? 'Delivery' : 'Delivery &mdash; Currently Unavailable'; ?></strong><small>We bring it to you</small></span>
                        </label>
                    </div>
                <!-- ADDED: E-Bike Rider Availability Status Indicator -->
                <div id="rider-status-container">
                    <div class="rider-status-box available" id="rider-box-available" style="<?php echo $is_rider_available ? '' : 'display:none;'; ?>">
                        <i class="fas fa-motorcycle" style="font-size: 1.2rem; margin-top: 2px;"></i>
                        <div>
                            <div><span class="rider-status-badge badge-green">Available</span> <strong>E-Bike Delivery Rider</strong></div>
                            <div style="font-size: 0.82rem; margin-top: 2px;">Rider is active and ready to deliver your orders.</div>
                        </div>
                    </div>
                    <div class="rider-status-box unavailable" id="rider-box-unavailable" style="<?php echo $is_rider_available ? 'display:none;' : ''; ?>">
                        <i class="fas fa-battery-quarter" style="font-size: 1.2rem; margin-top: 2px;"></i>
                        <div>
                            <div><span class="rider-status-badge badge-red">Unavailable</span> <strong>Delivery is currently unavailable</strong></div>
                            <div style="font-size: 0.82rem; margin-top: 2px;">All delivery e-bikes are currently unavailable. Please choose Pickup or try again later.</div>
                            <div id="delivery-switch-note" style="font-size: 0.82rem; margin-top: 4px; font-weight: 600; display:none;">Delivery just became unavailable, so we switched you to Pick-up.</div>
                        </div>
                    </div>
                </div>

                <div id="pickup-fields" style="<?php echo (!isset($_POST['fulfillment_type']) || $_POST['fulfillment_type'] === 'pickup') ? '' : 'display:none;'; ?>">
                    <div class="co-two">
                        <div class="form-row">
                            <label for="pickup_date">Pick-up Date</label>
                            <input type="date" name="pickup_date" id="pickup_date" value="<?php echo htmlspecialchars($_POST['pickup_date'] ?? ''); ?>" />
                        </div>
                        <div class="form-row">
                            <label for="pickup_time">Pick-up Time</label>
                            <input type="time" name="pickup_time" id="pickup_time" value="<?php echo htmlspecialchars($_POST['pickup_time'] ?? ''); ?>" />
                        </div>
                    </div>
                </div>
                <div id="delivery-fields" style="<?php echo (isset($_POST['fulfillment_type']) && $_POST['fulfillment_type'] === 'delivery') ? '' : 'display:none;'; ?>">
                    <style>
                        .custom-select-container { position: relative; }
                        .custom-select-trigger {
                            display: flex; align-items: center; justify-content: space-between;
                            padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
                            background: var(--surface); cursor: pointer;
                            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                            /* Allow vertical expansion for wrapped text */
                            height: auto; min-height: 44px;
                        }
                        .custom-select-trigger .trigger-text {
                            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                            /* Allow the text to shrink and wrap instead of forcing parent wider */
                            min-width: 0;
                        }
                        .custom-select-trigger::after {
                            content: '▼'; font-size: 0.7rem; color: var(--muted); margin-left: 10px;
                        }
                        .custom-select-options {
                            display: none; /* No longer absolutely positioned */
                            background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100;
                            margin-top: 4px; max-height: 250px; overflow-y: auto; overflow-x: hidden;
                        }
                        .custom-select-option {
                            z-index: 1000; /* Ensure options are above map */
                            padding: 12px 16px; cursor: pointer;
                            border-bottom: 1px solid var(--border);
                            /* Allow text wrapping inside options */
                            white-space: normal;
                            line-height: 1.4;
                        }
                        .custom-select-option:last-child { border-bottom: none; }
                        .custom-select-option:hover { background: var(--surface-2); }
                        .custom-select-option.selected {
                            background: var(--primary-soft); color: var(--link); font-weight: 700;
                        }
                        .custom-select-option .option-label {
                            font-weight: 700; display: block;
                        }
                        .custom-select-option .option-address {
                            font-size: 0.88rem; color: var(--muted);
                            white-space: normal;
                            word-break: break-word; /* Break long words/addresses */
                            overflow-wrap: anywhere;
                        }
                        .custom-select-option.selected .option-address { color: var(--link); }
                        .custom-select-option.new-address-opt {
                            background: var(--surface-2); font-weight: 700; color: var(--link);
                            text-align: center;
                        }
                    </style>

                    <div class="form-row">
                        <label for="address_option">Delivery Address</label>
                        
                        <!-- Hidden native select to hold the actual form value -->
                        <select name="address_option" id="address_option" onchange="toggleNewAddressForm()" style="display:none;">
                            <?php foreach ($customer_addresses as $addr): ?>
                                <option value="<?php echo $addr['id']; ?>" 
                                        data-address="<?php echo htmlspecialchars($addr['full_address']); ?>" 
                                        data-phone="<?php echo htmlspecialchars($addr['phone']); ?>"
                                        data-label="<?php echo htmlspecialchars($addr['label']); ?>"
                                        data-latitude="<?php echo htmlspecialchars($addr['latitude'] ?? ''); ?>"
                                        data-longitude="<?php echo htmlspecialchars($addr['longitude'] ?? ''); ?>"
                                        <?php echo $addr['is_default'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($addr['label']); ?> — <?php echo htmlspecialchars($addr['full_address']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="new" <?php if (empty($customer_addresses)) echo 'selected'; ?>>-- Choose a new address from map --</option>
                        </select>

                        <!-- Custom Dropdown UI -->
                        <div class="custom-select-container" id="custom-address-select">
                            <div class="custom-select-trigger">
                                <span class="trigger-text">Loading...</span>
                            </div>
                            <div class="custom-select-options">
                                <?php foreach ($customer_addresses as $addr): ?>
                                    <div class="custom-select-option" data-value="<?php echo $addr['id']; ?>">
                                        <span class="option-label"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($addr['label']); ?></span>
                                        <span class="option-address"><?php echo htmlspecialchars($addr['full_address']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="custom-select-option new-address-opt" data-value="new">
                                    <i class="fas fa-map"></i> Choose a new address from map
                                </div>
                            </div>
                        </div>
                    </div>

                        <!-- Type your address: suggestions near the shop; picking one pins it on the map -->
                        <div class="addr-search" id="addr-search-box">
                            <label for="addr-search"><?php echo empty($customer_addresses) ? 'Type your address' : 'Or type a new address'; ?></label>
                            <div class="addr-search-input">
                                <span class="addr-search-ico" aria-hidden="true"><i class="fas fa-magnifying-glass"></i></span>
                                <input type="text" id="addr-search" autocomplete="off" spellcheck="false" placeholder="e.g. 10th Avenue, Grace Park" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="addr-suggest">
                                <button type="button" class="addr-search-clear" id="addr-search-clear" aria-label="Clear" hidden><i class="fas fa-xmark"></i></button>
                            </div>
                            <ul class="addr-suggest" id="addr-suggest" role="listbox" hidden></ul>
                            <p class="addr-hint">Pick a suggestion to pin it on the map<?php echo empty($customer_addresses) ? ', or tap your exact spot on the map below' : ''; ?>.</p>
                        </div>
                    <div id="new-address-fields" style="<?php echo empty($customer_addresses) ? '' : 'display:none;'; ?>">
                        <!-- Map Selection for Delivery Area Verification -->
                        <div id="map-container" style="margin-top: 12px;">
                            <div id="map" style="height: 300px; border-radius: 8px; border: 1px solid #cbd5e1;"></div>
                            <input type="hidden" name="latitude" id="latitude">
                            <input type="hidden" name="longitude" id="longitude">
                        </div>
                        <div id="delivery-validation-container"></div>
                        <div class="form-row" style="margin-top:15px;"><label for="delivery_address">Selected Address</label><input type="text" name="new_delivery_address" id="delivery_address" placeholder="Address will appear here after pinning a location" readonly></div>
                        <div class="form-row"><label for="delivery_phone">Contact Number</label><input type="text" name="new_delivery_phone" id="delivery_phone" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"></div>
                    </div>

                    <input type="hidden" name="saved_address_text" id="saved_address_text">
                    <input type="hidden" name="saved_address_phone" id="saved_address_phone">
                </div>
                </section>

                <section class="co-sec">
                    <h3 class="co-h"><span class="co-num">2</span> Voucher <small>(optional)</small></h3>

                    <div class="form-row">
                        <label for="voucher_select" class="co-sr">Choose a claimed voucher</label>
                        <select id="voucher_select" class="co-voucher-select" <?php echo empty($my_vouchers) ? 'disabled' : ''; ?>>
                            <option value="">
                                <?php echo $has_usable_voucher ? '-- Select a claimed voucher --' : 'No claimed vouchers available'; ?>
                            </option>
                            <?php foreach ($my_vouchers as $v): ?>
                                <?php if ($v['usable']): ?>
                                    <option value="<?php echo htmlspecialchars($v['code']); ?>" <?php echo $voucher_code_input === $v['code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($v['reward_name']); ?> — <?php echo htmlspecialchars($v['discount_label']); ?><?php echo $v['expires_label'] ? ' (expires ' . htmlspecialchars($v['expires_label']) . ')' : ''; ?>
                                    </option>
                                <?php else: ?>
                                    <option disabled>
                                        <?php echo htmlspecialchars($v['reward_name']); ?> — <?php echo htmlspecialchars($v['status_label']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$has_usable_voucher): ?>
                            <p class="co-voucher-hint">You don't have a claimed voucher ready to use yet. <a href="reward_catalog.php">Redeem a reward</a> to unlock one, or use a promo code below.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-row" style="margin-top: 6px;">
                        <button type="button" id="toggle_promo_code" class="co-voucher-toggle">
                            <i class="fas fa-tag"></i> Have a promo code instead?
                        </button>
                        <div id="promo_code_row" class="co-voucher" style="<?php echo $voucher_code_input !== '' ? '' : 'display:none;'; ?> margin-top: 10px;">
                            <input type="text" name="voucher_code" id="voucher_code_input" placeholder="Enter promo code" value="<?php echo htmlspecialchars($voucher_code_input); ?>" onkeyup="this.value = this.value.toUpperCase();">
                            <button type="button" id="apply_voucher_btn" class="button button-secondary" style="white-space: nowrap;">Apply</button>
                        </div>
                        <div id="voucher-message" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
                    </div>
                </section>

                <section class="co-sec">
                    <h3 class="co-h"><span class="co-num">3</span> Order notes <small>(optional)</small></h3>
                    <div class="form-row" style="margin-bottom: 0;">
                        <label for="order_notes" class="co-sr">Order Notes (Optional)</label>
                        <textarea name="order_notes" id="order_notes" rows="2" placeholder="e.g., 'Please pack in a box.' or 'My friend will pick it up.'"><?php echo htmlspecialchars($_POST['order_notes'] ?? ''); ?></textarea>
                    </div>
                </section>

                <input type="hidden" name="discount_amount" id="discount_amount_hidden" value="0">
                <button type="submit" class="button co-continue">Continue to Payment <i class="fas fa-arrow-right"></i></button>
            </form>
        </div>
</section>

<script>
let leafletMap, marker;

function toggleFulfillmentFields() {
    const type = document.querySelector('input[name="fulfillment_type"]:checked').value;
    document.getElementById('pickup-fields').style.display = type === 'pickup' ? 'block' : 'none';
    document.getElementById('delivery-fields').style.display = type === 'delivery' ? 'block' : 'none';

    const mapContainer = document.getElementById('map-container');
    if (mapContainer) mapContainer.style.display = type === 'delivery' ? 'block' : 'none';
    if (type === 'delivery') { setTimeout(initFreeMap, 100); checkDeliveryAvailability(); }

    updateDeliveryFeeDisplay();
}

// ---- Delivery availability (same fleet state the Admin E-Bike page shows) ----
function applyDeliveryAvailability(available) {
    const radio = document.getElementById('fulfillment-delivery');
    if (!radio) return;
    const wasAvailable = !radio.disabled;
    radio.disabled = !available;
    document.getElementById('delivery-label-text').innerHTML = available ? 'Delivery' : 'Delivery &mdash; Currently Unavailable';
    const label = document.getElementById('delivery-option-label');
    label.style.opacity = available ? '' : '.6';
    label.style.cursor = available ? '' : 'not-allowed';
    document.getElementById('rider-box-available').style.display = available ? '' : 'none';
    document.getElementById('rider-box-unavailable').style.display = available ? 'none' : '';
    if (!available && radio.checked) {
        // The last e-bike just went unavailable while Delivery was selected: fall back to Pick-up.
        document.querySelector('input[name="fulfillment_type"][value="pickup"]').checked = true;
        document.getElementById('delivery-switch-note').style.display = 'block';
        toggleFulfillmentFields();
    } else if (available && !wasAvailable) {
        document.getElementById('delivery-switch-note').style.display = 'none';
    }
}

// Returns true when delivery is available (or the check could not be made - the server enforces it anyway).
async function checkDeliveryAvailability() {
    try {
        const res = await fetch('delivery_availability_api.php', { cache: 'no-store', credentials: 'same-origin' });
        const data = await res.json();
        if (typeof data.available !== 'boolean') return true;
        applyDeliveryAvailability(data.available);
        return data.available;
    } catch (e) {
        return true;
    }
}

function toggleNewAddressForm() {
    const addressSelect = document.getElementById('address_option');
    const selectedOption = addressSelect.options[addressSelect.selectedIndex];
    const isNew = selectedOption.value === 'new';

    const newAddressFields = document.getElementById('new-address-fields');
    const savedAddressText = document.getElementById('saved_address_text');
    const savedAddressPhone = document.getElementById('saved_address_phone');
    const deliveryPhoneInput = document.getElementById('delivery_phone');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const customSelectContainer = document.getElementById('custom-address-select'); // Get the custom container
    
    if (isNew) {
        newAddressFields.style.display = 'block';
        savedAddressText.value = ''; 
        savedAddressPhone.value = '';
        latitudeInput.value = '';
        longitudeInput.value = '';
        deliveryPhoneInput.value = '<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>'; // Reset to default customer phone
        setTimeout(initFreeMap, 100); // Initialize map when shown
        if (customSelectContainer) { // Ensure the custom dropdown is closed
            customSelectContainer.classList.remove('open');
        }
    } else {
        newAddressFields.style.display = 'none';
        if (selectedOption) {
            savedAddressText.value = selectedOption.dataset.address;
            savedAddressPhone.value = selectedOption.dataset.phone;
            // Populate latitude and longitude from saved address
            latitudeInput.value = selectedOption.dataset.latitude || '';
            longitudeInput.value = selectedOption.dataset.longitude || '';
            deliveryPhoneInput.value = selectedOption.dataset.phone; // Use phone from saved address
            if (!selectedOption.dataset.latitude || !selectedOption.dataset.longitude) {
                resolveSavedAddressCoordinates(selectedOption);
            }
        }
    }
    updateTotals();
}

async function resolveSavedAddressCoordinates(selectedOption) {
    const address = (selectedOption.dataset.address || '').trim();
    if (!address) return false;

    const validationContainer = document.getElementById('delivery-validation-container');
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(address)}`);
        if (!response.ok) throw new Error('Address lookup failed');
        const results = await response.json();
        const result = results[0];
        if (!result || !isFinite(parseFloat(result.lat)) || !isFinite(parseFloat(result.lon))) {
            throw new Error('Address coordinates not found');
        }

        const addressSelect = document.getElementById('address_option');
        if (addressSelect.value !== selectedOption.value) return false;
        document.getElementById('latitude').value = result.lat;
        document.getElementById('longitude').value = result.lon;
        selectedOption.dataset.latitude = result.lat;
        selectedOption.dataset.longitude = result.lon;
        updateTotals();
        return true;
    } catch (error) {
        // updateTotals() (via updateDeliveryFeeDisplay's own branch for a saved address with
        // no resolvable coordinates) sets the actual message shown to the customer - this
        // catch only needs to trigger that recheck now that the auto-lookup has failed.
        updateTotals();
        return false;
    }
}

function syncSelectedSavedAddress() {
    const addressSelect = document.getElementById('address_option');
    const selectedOption = addressSelect.options[addressSelect.selectedIndex];
    if (!selectedOption || selectedOption.value === 'new') return;

    document.getElementById('saved_address_text').value = selectedOption.dataset.address || '';
    document.getElementById('saved_address_phone').value = selectedOption.dataset.phone || '';
    document.getElementById('latitude').value = selectedOption.dataset.latitude || '';
    document.getElementById('longitude').value = selectedOption.dataset.longitude || '';
    updateDeliveryFeeDisplay();
}

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('custom-address-select');
    if (!container) return;

    const trigger = container.querySelector('.custom-select-trigger');    
    const optionsContainer = container.querySelector('.custom-select-options');
    const options = container.querySelectorAll('.custom-select-option');
    const hiddenSelect = document.getElementById('address_option');

    function updateDisplay() {
        const selectedOption = hiddenSelect.options[hiddenSelect.selectedIndex];
        const triggerText = container.querySelector('.trigger-text');
        if (selectedOption.value === 'new') {
            triggerText.innerHTML = `<i class="fas fa-map"></i> Choose a new address from map`;
        } else {
            // Allow wrapping in the trigger text
            trigger.style.whiteSpace = 'normal';
            triggerText.style.whiteSpace = 'normal';
            triggerText.style.textOverflow = 'unset';
            triggerText.innerHTML = `<i class="fas fa-map-marker-alt"></i> <strong>${selectedOption.dataset.label}</strong> – <span style="word-break: break-word;">${selectedOption.dataset.address}</span>`;
        }

        // Update selected state in custom dropdown
        options.forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.value === selectedOption.value);
        });
    }

    hiddenSelect.addEventListener('change', updateDisplay); // e.g. picking a typed-address suggestion switches to "new"

    trigger.addEventListener('click', () => {
        const isOpen = optionsContainer.style.display === 'block';
        optionsContainer.style.display = isOpen ? 'none' : 'block';
    });

    options.forEach(option => {
        option.addEventListener('click', () => {
            hiddenSelect.value = option.dataset.value;
            // Manually trigger the onchange event for compatibility
            const changeEvent = new Event('change');
            hiddenSelect.dispatchEvent(changeEvent);
            
            updateDisplay();
            optionsContainer.style.display = 'none';
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!container.contains(e.target) && optionsContainer.style.display === 'block') {
            optionsContainer.style.display = 'none';
        }
    });

    updateDisplay(); // Initial display update
    syncSelectedSavedAddress();
    const initialOption = hiddenSelect.options[hiddenSelect.selectedIndex];
    if (initialOption && initialOption.value !== 'new' && (!initialOption.dataset.latitude || !initialOption.dataset.longitude)) {
        resolveSavedAddressCoordinates(initialOption);
    }
});

/**
 * Delivery fee by distance (km rounded to 0.1): 0-1 km PHP 15, 1.1-2 PHP 20, 2.1-3 PHP 25,
 * 3.1-4 PHP 30, 4.1-5 PHP 35, then PHP 5 more per additional km. Mirrors delivery_fee_for_km().
 */
function deliveryFeeForKm(km) {
    km = Math.round(Math.max(0, km) * 10) / 10;
    if (km <= 5) return 15 + 5 * Math.max(0, Math.ceil(km) - 1);
    return 35 + 5 * Math.ceil(Math.round((km - 5) * 10) / 10);
}

function updateDeliveryFeeDisplay() {
    const discount = parseFloat(document.getElementById('discount_amount_hidden').value) || 0;
    updateTotals(discount);
}

let freeShipVoucher = false; // set when a Free Shipping voucher is applied: the discount follows the delivery fee

function updateTotals(discount = 0) {
    const fulfillmentType = document.querySelector('input[name="fulfillment_type"]:checked').value;
    const lat = parseFloat(document.getElementById('latitude').value);
    const lon = parseFloat(document.getElementById('longitude').value);
    const subtotalNetEl = document.getElementById('subtotal-net-display');
    const validationContainer = document.getElementById('delivery-validation-container');
    const subtotal_net = parseFloat(subtotalNetEl.dataset.value) || 0;
    const subtotal_gross = subtotal_net * 1.12;
    let fee = 0;
    
    document.getElementById('discount_amount_hidden').value = discount;
    
    if (fulfillmentType === 'delivery') {
        validationContainer.innerHTML = ''; // Clear previous messages
        if (!isNaN(lat) && !isNaN(lon)) {
            const storeLat = 14.652023833372462;
            const storeLon = 120.97593745927361;
            const R = 6371; 
            const dLat = (lat - storeLat) * (Math.PI / 180);
            const dLon = (lon - storeLon) * (Math.PI / 180);
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(storeLat * (Math.PI / 180)) * Math.cos(lat * (Math.PI / 180)) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            const distance = R * c; 

            // Distance table (same as delivery_fee_for_km() on the server); deliveries stop at 5 km.
            fee = distance <= DELIVERY_SERVICE_MAX_KM ? deliveryFeeForKm(distance) : -1;
            // Free delivery once the items total (VAT included) reaches PHP 2,000 (FREE_DELIVERY_MIN_TOTAL).
            const freeDelivery = fee >= 0 && subtotal_gross >= <?php echo json_encode(FREE_DELIVERY_MIN_TOTAL); ?>;
            if (freeDelivery) fee = 0;

            // Display validation message
            if (fee >= 0) {
                validationContainer.innerHTML = `<div class="delivery-validation-msg valid">✓ Delivery available! Distance: <strong>${distance.toFixed(2)} km</strong> &middot; ${freeDelivery ? '<strong>Free delivery</strong> (orders PHP 2,000 and up)' : 'Delivery fee: <strong>PHP ' + fee.toFixed(2) + '</strong>'}</div>`;
            } else {
                validationContainer.innerHTML = `<div class="delivery-validation-msg invalid">✕ Sorry, this location is outside our Caloocan service area.</div>`;
            }
        } else if (document.getElementById('address_option').value !== 'new') {
            // A saved address whose map location could not be resolved (e.g. it was saved
            // before this address picker required pinning a location). This used to still
            // show a fee and let the order through, only to fail later at payment.php with
            // "A delivery location is required" - block it here instead, with a way out.
            fee = -1;
            validationContainer.innerHTML = '<div class="delivery-validation-msg invalid">✕ We could not verify the map location for this saved address. <a href="<?php echo BASE_URL; ?>/customer/profile.php" target="_blank" rel="noopener">Update it in your profile</a> and re-pin it on the map, or choose "new address" above to pin a location now.</div>';
        } else {
            fee = -1;
            validationContainer.innerHTML = '<div class="delivery-validation-msg invalid">Please select a saved address with a valid map location or choose a location from the map.</div>';
        }
    }
    
    if (freeShipVoucher) {
        discount = fee > 0 ? fee : 0;
        document.getElementById('discount_amount_hidden').value = discount;
    }

    // If fee is -1, it's an invalid area, so don't add to total and disable checkout button
    const placeOrderBtn = document.querySelector('#checkout-form button[type="submit"]');
    placeOrderBtn.disabled = (fee === -1);

    const totalBeforeDiscount = subtotal_gross + fee;
    // If fee is -1, don't calculate a real total
    const finalTotal = (fee === -1) ? subtotal_gross : Math.max(0, totalBeforeDiscount - discount);
    
    document.getElementById('delivery-fee-display').innerText = fee === 0 ? 'Free' : 'PHP ' + fee.toFixed(2);
    document.getElementById('total-to-pay-display').innerText = 'PHP ' + finalTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('vat-display').innerText = 'PHP ' + (subtotal_net * 0.12).toFixed(2);
    
    const pointsEarned = Math.max(0, subtotal_net / 100);
    document.getElementById('points-to-earn-display').innerText = `+${pointsEarned.toFixed(2)} pts`;
    
    const discountLine = document.getElementById('discount-line');
    const discountDisplay = document.getElementById('discount-display');
    if (discount > 0) {
        discountLine.style.display = 'flex';
        discountDisplay.innerText = '-PHP ' + discount.toFixed(2);
    } else {
        discountLine.style.display = 'none';
    }
}

// Same shop coordinates and 5km ceiling used by the tiered delivery fee in
// updateTotals() below (0/50/60/70 for 2/3/4/5km, nothing beyond) - this is
// the actual Caloocan City / 10th Avenue / Grace Park service area, kept as
// ONE number so the map and the fee logic can never disagree.
const STORE_LAT = 14.652023833372462;
const STORE_LON = 120.97593745927361;
const DELIVERY_SERVICE_MAX_KM = 5.0;

function distanceFromStoreKm(lat, lon) {
    const R = 6371;
    const dLat = (lat - STORE_LAT) * (Math.PI / 180);
    const dLon = (lon - STORE_LON) * (Math.PI / 180);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(STORE_LAT * (Math.PI / 180)) * Math.cos(lat * (Math.PI / 180)) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// A click, search result, or geocoded pin outside the service area (e.g. Visayas)
// is refused immediately instead of silently accepting it and only disabling the
// submit button later - no marker, no address filled in, just a clear reason why.
function rejectMapLocation() {
    if (marker) { leafletMap.removeLayer(marker); marker = null; }
    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('delivery_address').value = '';
    // updateDeliveryFeeDisplay() (via updateTotals()) rewrites the validation box itself for
    // the now-empty lat/lon, so it must run BEFORE this message, not after - otherwise it
    // silently overwrites this specific reason with its own generic fallback text.
    updateDeliveryFeeDisplay();
    const validationContainer = document.getElementById('delivery-validation-container');
    if (validationContainer) {
        validationContainer.innerHTML = '<div class="delivery-validation-msg invalid">✕ Sorry, that location is outside our delivery area. We currently deliver within Caloocan City (Caloocan, 10th Avenue, Grace Park), up to 5km from our shop.</div>';
    }
}

function acceptMapLocation(latlng, addressText) {
    if (marker) leafletMap.removeLayer(marker);
    marker = L.marker(latlng).addTo(leafletMap);
    document.getElementById('latitude').value = latlng.lat;
    document.getElementById('longitude').value = latlng.lng;
    if (addressText) document.getElementById('delivery_address').value = addressText;
    updateDeliveryFeeDisplay();
}

// ---- "Type your address" box: suggestions from OpenStreetMap (Nominatim) near the shop ----
// Debounced (and cached) so typing sends at most one lookup per pause, within Nominatim's usage
// limits. Results are limited to a box around the shop; picking one runs the same delivery-area
// checks as tapping the map (5km + Caloocan City, verifyAndAcceptLocation()).
function initAddressSearch() {
    const input = document.getElementById('addr-search');
    const list = document.getElementById('addr-suggest');
    const clear = document.getElementById('addr-search-clear');
    if (!input || !list) return;
    const cache = {};
    let timer = null, results = [], active = -1, seq = 0;
    const d = 0.06; // ~6-7km around the shop
    const viewbox = [STORE_LON - d, STORE_LAT + d, STORE_LON + d, STORE_LAT - d].join(',');
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function show(html) { list.innerHTML = html; list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
    function hide() { list.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function render() {
        if (!results.length) { show('<li class="is-info">No matching address nearby. Try a street or barangay, or tap the map.</li>'); return; }
        show(results.map((r, i) => {
            const parts = String(r.display_name || '').split(',');
            const main = parts.slice(0, 2).join(',').trim();
            const rest = parts.slice(2).join(',').trim();
            return '<li role="option" data-i="' + i + '"' + (i === active ? ' class="is-active" aria-selected="true"' : '') + '>' +
                '<span class="addr-pin" aria-hidden="true"><i class="fas fa-location-dot"></i></span><span><strong>' + esc(main) + '</strong><small>' + esc(rest) + '</small></span></li>';
        }).join(''));
    }
    function search(q) {
        const key = q.toLowerCase();
        if (cache[key]) { results = cache[key]; active = -1; render(); return; }
        const mine = ++seq;
        show('<li class="is-info"><i class="fas fa-spinner fa-spin"></i>&nbsp; Searching…</li>');
        fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=6&countrycodes=ph&bounded=1&viewbox=' + viewbox + '&q=' + encodeURIComponent(q), { headers: { 'Accept-Language': 'en' } })
            .then(r => r.json())
            .then(rows => {
                if (mine !== seq) return; // a newer search is on its way
                results = cache[key] = (rows || []).filter(r => distanceFromStoreKm(+r.lat, +r.lon) <= DELIVERY_SERVICE_MAX_KM + 1);
                active = -1;
                render();
            })
            .catch(() => { if (mine === seq) show('<li class="is-info">Could not load suggestions. Check your connection, or tap the map.</li>'); });
    }
    function pick(i) {
        const r = results[i];
        if (!r) return;
        hide();
        input.value = String(r.display_name || '').split(',').slice(0, 2).join(',').trim();
        // A saved address was selected: switch to "new address" so the map + Selected Address show.
        const picker = document.getElementById('address_option');
        if (picker && picker.value !== 'new') {
            picker.value = 'new';
            picker.dispatchEvent(new Event('change')); // toggleNewAddressForm() + the custom dropdown's label
        }
        const latlng = L.latLng(+r.lat, +r.lon);
        if (distanceFromStoreKm(latlng.lat, latlng.lng) > DELIVERY_SERVICE_MAX_KM) { rejectMapLocation(); return; }
        initFreeMap();
        leafletMap.setView(latlng, 17);
        verifyAndAcceptLocation(latlng, r.display_name, true);
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        clear.hidden = !input.value;
        clearTimeout(timer);
        if (q.length < 3) { hide(); return; }
        timer = setTimeout(() => search(q), 600);
    });
    input.addEventListener('keydown', function (e) {
        if (list.hidden || !results.length) {
            if (e.key === 'Enter') e.preventDefault(); // never submit the checkout form from here
            return;
        }
        if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % results.length; render(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + results.length) % results.length; render(); }
        else if (e.key === 'Enter') { e.preventDefault(); pick(active >= 0 ? active : 0); }
        else if (e.key === 'Escape') { hide(); }
    });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus so blur doesn't close it first
    list.addEventListener('click', function (e) {
        const li = e.target.closest('li[data-i]');
        if (li) pick(+li.dataset.i);
    });
    input.addEventListener('blur', function () { setTimeout(hide, 150); });
    input.addEventListener('focus', function () { if (results.length && input.value.trim().length >= 3) render(); });
    clear.addEventListener('click', function () { input.value = ''; clear.hidden = true; results = []; hide(); input.focus(); });
}

function cityFromNominatim(data) {
    const addr = (data && data.address) || {};
    return (addr.city || addr.municipality || addr.town || addr.city_district || '').toLowerCase();
}
function isCaloocanCity(city) {
    return city.includes('caloocan') || city.includes('kalookan');
}

// The 5km circle above also reaches into neighboring cities - Quezon City, Malabon and
// Valenzuela all border Caloocan, so parts of them fall within 5km of the shop too. This
// resolves the REAL city via reverse geocoding (same rule the server enforces server-side
// in create_customer_order()/is_location_in_service_area()) and rejects anything that
// isn't actually Caloocan. Reuses this one lookup for the address text as well, so a pin
// never calls Nominatim twice. On a network error, falls back to accepting the pin (the
// distance check already passed) rather than blocking the customer over a lookup hiccup.
function verifyAndAcceptLocation(latlng, fallbackAddressText, keepAddressText) {
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=${latlng.lat}&lon=${latlng.lng}`)
        .then(response => response.json())
        .then(data => {
            if (!isCaloocanCity(cityFromNominatim(data))) {
                rejectMapLocation();
                return;
            }
            // keepAddressText: the customer picked this exact address from the suggestions, so keep
            // its wording instead of the reverse-geocoded one (which may start with a nearby shop's name).
            acceptMapLocation(latlng, (keepAddressText && fallbackAddressText) || data.display_name || fallbackAddressText);
        })
        .catch(err => {
            console.error('Geocoding error:', err);
            acceptMapLocation(latlng, fallbackAddressText);
        });
}

function initFreeMap() {
    if (leafletMap) {
        leafletMap.invalidateSize();
        return;
    }
    leafletMap = L.map('map').setView([STORE_LAT, STORE_LON], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(leafletMap);

    L.Control.geocoder({
        defaultMarkGeocode: false,
        placeholder: "Search for address...",
        errorMessage: "Address not found."
    })
    .on('markgeocode', function(e) {
        const latlng = e.geocode.center;
        if (distanceFromStoreKm(latlng.lat, latlng.lng) > DELIVERY_SERVICE_MAX_KM) {
            rejectMapLocation();
            return;
        }
        leafletMap.setView(latlng, 16);
        verifyAndAcceptLocation(latlng, e.geocode.name);
    })
    .addTo(leafletMap);

    leafletMap.on('click', function(e) {
        if (distanceFromStoreKm(e.latlng.lat, e.latlng.lng) > DELIVERY_SERVICE_MAX_KM) {
            rejectMapLocation();
            return;
        }
        verifyAndAcceptLocation(e.latlng, '');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    toggleFulfillmentFields();
    initAddressSearch();
    checkDeliveryAvailability();
    setInterval(checkDeliveryAvailability, 30000);

    const voucherInput = document.getElementById('voucher_code_input');
    const applyBtn = document.getElementById('apply_voucher_btn');
    const voucherMessage = document.getElementById('voucher-message');

    async function validateVoucher() {
        const code = voucherInput ? voucherInput.value.trim() : '';
        if (!code) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Please enter a voucher code.';
                voucherMessage.style.color = '#dc2626'; // Red
            }
            return;
        }

        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Applying...';
        }

        try {
            const response = await fetch('validate_voucher_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ voucher_code: code })
            });

            const data = await response.json();

            if (data.success) {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message;
                    voucherMessage.style.color = '#16a34a'; // Green
                }
                // Update the hidden input and recalculate totals
                freeShipVoucher = !!data.free_shipping;
                updateTotals(parseFloat(data.discount_amount));
            } else {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message || 'An unknown error occurred.';
                    voucherMessage.style.color = '#dc2626'; // Red
                }
                // Reset discount if voucher is invalid
                freeShipVoucher = false;
                updateTotals(0);
            }
        } catch (error) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Could not connect to the server.';
                voucherMessage.style.color = '#dc2626';
            }
            console.error('Voucher API Error:', error);
        } finally {
            if (applyBtn) {
                applyBtn.disabled = false;
                applyBtn.textContent = 'Apply';
            }
        }
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', validateVoucher);
    }

    if (voucherInput) {
        voucherInput.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); validateVoucher(); } });
        if (voucherInput.value.trim() !== '') validateVoucher();
    }

    // Voucher picker: selecting a claimed voucher fills the same code field
    // the Apply button/backend already use, then validates it right away —
    // no copy-pasting from My Rewards needed.
    const voucherSelect = document.getElementById('voucher_select');
    const promoRow = document.getElementById('promo_code_row');
    const promoToggle = document.getElementById('toggle_promo_code');
    if (voucherSelect) {
        voucherSelect.addEventListener('change', function () {
            if (this.value && voucherInput) {
                voucherInput.value = this.value;
                validateVoucher();
            }
        });
        // A voucher is already picked (e.g. returning from the Payment step) — reflect it, but
        // don't re-run validateVoucher() again since the block above already did.
        if (voucherInput && voucherInput.value.trim() !== '') {
            for (const opt of voucherSelect.options) {
                if (opt.value === voucherInput.value.trim()) { voucherSelect.value = opt.value; break; }
            }
        }
    }
    if (promoToggle && promoRow) {
        promoToggle.addEventListener('click', function () {
            const isHidden = promoRow.style.display === 'none';
            promoRow.style.display = isHidden ? 'flex' : 'none';
            this.innerHTML = isHidden
                ? '<i class="fas fa-tag"></i> Hide promo code'
                : '<i class="fas fa-tag"></i> Have a promo code instead?';
            if (isHidden) voucherInput.focus();
        });
    }

    // Add client-side validation before form submission
    const checkoutForm = document.getElementById('checkout-form');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', async function(e) {
            const fulfillmentType = document.querySelector('input[name="fulfillment_type"]:checked').value;

            if (fulfillmentType === 'delivery') {
                const addressSelect = document.getElementById('address_option');
                const isUsingNewAddress = addressSelect.value === 'new';

                if (isUsingNewAddress) {
                    const lat = document.getElementById('latitude').value;
                    const lon = document.getElementById('longitude').value;
                    if (!lat || !lon) {
                        e.preventDefault(); // Stop form submission
                        toastOr("Please select a valid location on the map for your new address.", "warn");
                        return;
                    }
                }

                // Last-second availability check: if the final e-bike was switched off, don't submit a delivery order.
                e.preventDefault();
                if (await checkDeliveryAvailability()) {
                    checkoutForm.submit();
                } else {
                    toastOr('Delivery is currently unavailable because no e-bikes are available. Please select Pickup or try again later.', 'warn');
                }
            }
        });
    }
});

</script>
<?php include __DIR__ . '/includes/footer.php'; ?>