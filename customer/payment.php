<?php
/**
 * Checkout step 2: choose the payment method and place the order.
 * Step 1 (customer/checkout.php) saved the fulfillment/address/voucher details
 * in $_SESSION['checkout_draft']; no order exists until it is placed here.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../modules/customers/achievements_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/upload_helper.php';
require_once __DIR__ . '/includes/checkout_helpers.php';
require_once __DIR__ . '/../app/helpers/ebike_helper.php';
require_once __DIR__ . '/../app/helpers/payment_helper.php';
require_customer_login();
payment_ensure_schema($conn); // DDL first: never inside the order transaction

// GCash / Bank can be paid two ways:
//   online - PayMongo hosted checkout; the order is marked paid only when PayMongo confirms it
//            (signed webhook or server-side status check), never by this page.
//   manual - customer transfers to the shop's QR/account and submits the reference + receipt;
//            payment stays "Pending verification" until staff find it in the shop's account.
$pay_cfg = payment_config();
$online_ok = $pay_cfg['online_enabled'];
$manual_ok = $pay_cfg['allow_manual'];

$customer = current_customer();
$cart = get_customer_cart(true); // only the items ticked on the Cart page
if (empty($cart)) {
    unset($_SESSION['checkout_draft']);
    header('Location: ' . BASE_URL . '/customer/products.php');
    exit();
}

$draft = $_SESSION['checkout_draft'] ?? null;
if (!$draft || (int)($draft['customer_id'] ?? 0) !== (int)$customer['id']) {
    header('Location: checkout.php');
    exit();
}

$fulfillment_type = $draft['fulfillment_type'] === 'delivery' ? 'delivery' : 'pickup';
// Delivery fee from the pinned location's distance to the shop (delivery_fee_for_km()); never from the browser.
$delivery_quote = $fulfillment_type === 'delivery'
    ? delivery_quote(is_numeric($draft['latitude'] ?? null) ? (float) $draft['latitude'] : null, is_numeric($draft['longitude'] ?? null) ? (float) $draft['longitude'] : null)
    : null;
$delivery_free = $delivery_quote !== null && cart_subtotal() >= FREE_DELIVERY_MIN_TOTAL; // free delivery at PHP 2,000+
$delivery_fee = $delivery_quote !== null ? delivery_fee_charged($delivery_quote['fee'], cart_subtotal()) : 0.00;
$delivery_km = $delivery_quote['km'] ?? null;
$allowed_methods = $fulfillment_type === 'delivery' ? ['gcash', 'cod', 'bank'] : ['gcash', 'pay_at_shop', 'bank'];
// Manual transfer needs the shop's REAL account (Admin > Settings > Payment Accounts); never a placeholder.
$shop_accounts = shop_payment_accounts($conn);
$manual_ready = [
    'gcash' => $manual_ok && $shop_accounts['gcash']['ready'],
    'bank'  => $manual_ok && $shop_accounts['bank']['ready'],
];
// A method with no way to take or verify the money stays visible but disabled (and is refused
// server-side), so customers see why instead of it silently disappearing.
$unavailable_methods = [];
foreach (['gcash', 'bank'] as $m) {
    if (in_array($m, $allowed_methods, true) && !$online_ok && !$manual_ready[$m]) {
        $unavailable_methods[] = $m;
        $allowed_methods = array_values(array_diff($allowed_methods, [$m]));
    }
}

$errors = [];
$delivery_blocked = $fulfillment_type === 'delivery' && !ebike_delivery_available($conn);
if ($delivery_blocked) {
    $errors[] = EBIKE_DELIVERY_UNAVAILABLE_MESSAGE;
}
$success_message = '';
$order_details = null;

$admin_message = "Please scan the QR code above and enter the 13-digit GCash reference number after payment. Your order will be processed once payment is verified.";
$bank_admin_message = "Please transfer the total amount to the account shown above. Upload a screenshot of your transaction receipt and enter the reference number to confirm.";

// Voucher preview (the saved discount is always recomputed when the order is created).
$voucher_code = (string)($draft['voucher_code'] ?? '');
$discount_amount = 0.00;
if ($voucher_code !== '') {
    $v = checkout_validate_voucher($conn, $customer, $voucher_code, $fulfillment_type, $delivery_fee);
    $discount_amount = $v['discount'];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $errors = array_merge($errors, $v['errors']);
    }
}

$posted_method = $_POST['payment_method'] ?? ($fulfillment_type === 'delivery' ? 'cod' : 'pay_at_shop');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $payment_method = $_POST['payment_method'] ?? '';
    $is_transfer = in_array($payment_method, ['gcash', 'bank'], true);
    $pay_mode = ($_POST['pay_mode'] ?? '') === 'manual' || !$online_ok ? 'manual' : 'online';
    if ($is_transfer && $pay_mode === 'manual' && !$manual_ready[$payment_method]) {
        $errors[] = $online_ok ? 'Please use online payment for this method.' : 'This payment method is not available right now. Please choose another payment method.';
    }
    $manual_transfer = $is_transfer && $pay_mode === 'manual';
    $gcash_reference_number = trim($_POST['gcash_reference_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_reference_number = trim($_POST['bank_reference_number'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $payment_proof_path = '';
    $payment_proof_absolute_path = ''; // only used to remove an orphaned upload if order creation fails

    if (!in_array($payment_method, $allowed_methods, true)) {
        $errors[] = $fulfillment_type === 'delivery'
            ? 'Please choose GCash, Cash on Delivery or Bank Transfer for a delivery order.'
            : 'Please choose GCash, Pay at Shop or Bank Transfer for a pick-up order.';
    }

    if ($payment_method === 'gcash' && $manual_transfer) {
        if ($gcash_reference_number === '') {
            $errors[] = 'GCash reference number is required for GCash payments.';
        } elseif (!preg_match('/^\d{13}$/', $gcash_reference_number)) {
            $errors[] = 'Please enter a valid 13-digit GCash reference number.';
        } else {
            $ref_stmt = $conn->prepare("SELECT id FROM tbl_orders WHERE payment_reference = ? LIMIT 1");
            $ref_stmt->bind_param('s', $gcash_reference_number);
            $ref_stmt->execute();
            if ($ref_stmt->get_result()->num_rows > 0) {
                $errors[] = 'This GCash reference number has already been used. Please double-check your transaction or contact support if you believe this is an error.';
            }
            $ref_stmt->close();
        }

        if (isset($_FILES['gcash_payment_proof']) && $_FILES['gcash_payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload = save_uploaded_image($_FILES['gcash_payment_proof'], __DIR__ . '/../uploads/proofs/', '/uploads/proofs/', 'gcash_receipt_', 5 * 1024 * 1024);
            if ($upload['ok']) {
                $payment_proof_path = $upload['url'];
                $payment_proof_absolute_path = $upload['path'];
            } else {
                $errors[] = $upload['error'];
            }
        } else {
            $errors[] = 'A screenshot of your GCash payment receipt is required. Please upload proof of your GCash payment.';
        }
    }

    if ($payment_method === 'bank' && $manual_transfer) {
        if ($bank_name === '') $errors[] = 'Bank Name is required for bank transfers.';
        if ($bank_account_name === '') $errors[] = 'Your Account Name is required for bank transfers.';
        if ($bank_reference_number === '') {
            $errors[] = 'Bank Reference Number is required.';
        } else {
            $ref_stmt = $conn->prepare("SELECT id FROM tbl_orders WHERE payment_reference = ? LIMIT 1");
            $ref_stmt->bind_param('s', $bank_reference_number);
            $ref_stmt->execute();
            if ($ref_stmt->get_result()->num_rows > 0) {
                $errors[] = 'This Bank reference number has already been used. Please use a unique reference for each transaction.';
            }
            $ref_stmt->close();
        }

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload = save_uploaded_image($_FILES['payment_proof'], __DIR__ . '/../uploads/proofs/', '/uploads/proofs/', 'proof_', 5 * 1024 * 1024);
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

    // Delivery availability may have changed since step 1 (checked again at the moment of ordering).
    if ($fulfillment_type === 'delivery' && !ebike_delivery_available($conn) && !$delivery_blocked) {
        $errors[] = EBIKE_DELIVERY_UNAVAILABLE_MESSAGE;
        $delivery_blocked = true;
    }

    // Stock/voucher may have changed since step 1.
    $errors = array_merge($errors, checkout_check_stock($cart));
    if ($voucher_code !== '') {
        $errors = array_merge($errors, checkout_validate_voucher($conn, $customer, $voucher_code, $fulfillment_type, $delivery_fee)['errors']);
    }

    if (empty($errors)) {
        $reference = !$manual_transfer ? null : ($payment_method === 'gcash' ? $gcash_reference_number : $bank_reference_number);
        $details = [
            'delivery_address' => $draft['delivery_address'],
            'delivery_phone' => $draft['delivery_phone'],
            'subtotal' => cart_subtotal(),
            'order_notes' => $draft['order_notes'],
            'delivery_instructions' => $draft['delivery_instructions'],
            'pickup_date' => $draft['pickup_date'],
            'pickup_time' => $draft['pickup_time'],
            'payment_method' => $payment_method,
            'gcash_reference_number' => $reference,
            'reference_number' => $reference,
            'delivery_fee' => $delivery_fee, // distance table, computed on the server (delivery_quote())
            'latitude' => $draft['latitude'],
            'longitude' => $draft['longitude'],
            'voucher_code' => $voucher_code,
            'discount_amount' => $discount_amount,
            'bank_name' => $bank_name,
            'bank_account_name' => $bank_account_name,
            'payment_proof_path' => $payment_proof_path,
        ];

        $order_id = create_customer_order((int)$customer['id'], $fulfillment_type, $details, $cart);
        if ($order_id !== null) {
            // Buy Now never touched tbl_cart, so only leave buy-now mode here -
            // clearing the real cart would wipe items this order never included.
            if (is_buy_now_active()) {
                clear_buy_now();
            } else {
                clear_customer_cart();
            }
            unset($_SESSION['checkout_draft']);
            $order_details = get_order_by_id($order_id, (int)$customer['id']);
            $success_message = 'Your order was successfully placed.';

            // Payment status is separate from the order status. COD / Pay at shop start "unpaid".
            if ($manual_transfer) {
                $conn->query("UPDATE tbl_orders SET payment_status = 'pending_verification', payment_provider = 'manual' WHERE id = " . (int) $order_id);
                payment_log_event($conn, ['order_id' => $order_id, 'event' => 'payment_submitted', 'status' => 'pending_verification',
                    'amount' => (float) $order_details['total'], 'reference' => $reference, 'source' => 'customer',
                    'details' => 'Manual ' . ($payment_method === 'gcash' ? 'GCash' : 'bank') . ' transfer; waiting for staff to verify.']);
            } elseif ($is_transfer) {
                // Online: GCash goes straight to the GCash approval step (bank: PayMongo's page). Paid only after PayMongo confirms.
                $return = SITE_URL . '/customer/payment_status.php?order=' . (int) $order_id;
                $start = payment_start_checkout($conn, [
                    'purpose' => 'order', 'order_id' => $order_id, 'amount' => (float) $order_details['total'],
                    'method' => $payment_method, 'description' => 'Order #' . $order_details['order_number'],
                    'success_url' => $return, 'cancel_url' => $return . '&cancelled=1',
                    'name' => (string) ($customer['name'] ?? ''), 'email' => (string) ($customer['email'] ?? ''),
                ]);
                header('Location: ' . ($start['ok'] ? $start['checkout_url'] : $return));
                exit();
            }
        } else {
            $real_err = $_SESSION['last_order_error'] ?? null;
            unset($_SESSION['last_order_error']);
            $errors[] = $real_err ? $real_err : 'Unable to place your order at this time. Please try again later.';
            if ($payment_proof_absolute_path !== '' && is_file($payment_proof_absolute_path)) {
                @unlink($payment_proof_absolute_path);
            }
        }
    } elseif ($payment_proof_absolute_path !== '' && is_file($payment_proof_absolute_path)) {
        // Validation failed after the receipt was saved; the customer must re-select it anyway.
        @unlink($payment_proof_absolute_path);
    }
}

$subtotal_inc_vat = cart_subtotal();
$total_to_pay = max(0.00, $subtotal_inc_vat + $delivery_fee - $discount_amount);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<?php $page_title = 'Payment'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .checkout-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 30px; align-items: start; margin-top: 20px; }
    .pm-mode { display: grid; gap: 10px; margin: 14px 0; }
    .pm-mode-opt { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; background: var(--surface); }
    .pm-mode-opt:has(input:checked) { border-color: var(--primary); background: var(--primary-soft); }
    .pm-mode-opt input { margin-top: 3px; }
    .pm-mode-opt strong { display: block; color: var(--text); }
    .pm-mode-opt small { color: var(--muted); }
    .checkout-summary, .checkout-form { background: var(--surface); padding: 20px; border-radius: 10px; border: 1px solid var(--border); }
    .page-back { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; padding: 8px 14px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); color: var(--text-2); text-decoration: none; font-weight: 600; font-size: .9rem; }
    .page-back:hover { background: var(--surface-2); color: var(--text); }
    .form-row { margin-bottom: 15px; }
    .form-row label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text); }
    input[type="text"] { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; font-size: 1rem; }
    input[type="file"] { max-width: 100%; }
    .summary-line { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: .95rem; color: var(--text-2); }
    .summary-line.total { border-top: 2px solid var(--border); margin-top: 15px; padding-top: 15px; font-weight: 800; color: var(--text); font-size: 1.2rem; }
    .summary-footer { background: var(--surface-2); padding: 20px; border-radius: 12px; border: 1px solid var(--border); margin-top: 16px; }
    .gcash-details { border: 1px solid var(--border); border-radius: 8px; padding: 15px; background-color: var(--surface-2); margin-top: 10px; }
    .qr-wrapper { cursor: pointer; display: inline-block; border: 1px solid var(--border); padding: 5px; background: var(--surface); text-align: center; }
    .qr-wrapper img { width: 150px; height: auto; }
    .modal-zoomer { display: none; position: fixed; z-index: 9999; padding-top: 100px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,.9); }
    .modal-content-zoomer { margin: auto; display: block; width: 80%; max-width: 500px; }
    .close-zoomer { position: absolute; top: 50px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
    .scan-to-pay { border: 1px solid var(--customer-border, #e2e8f0); border-radius: 12px; padding: 18px; margin-bottom: 18px; background: var(--customer-surface-2, #f8fafc); }
    .scan-to-pay h4 { margin: 0 0 14px; text-align: center; font-size: 1rem; font-weight: 800; letter-spacing: .06em; color: var(--customer-text, #1e293b); }
    .stp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
    .stp-card { background: var(--customer-surface, #fff); border: 2px solid var(--customer-border, #e2e8f0); border-radius: 10px; padding: 14px; text-align: center; }
    .stp-card-label { font-weight: 800; font-size: .85rem; letter-spacing: .04em; color: var(--customer-text, #1e293b); margin-bottom: 10px; }
    .stp-qr-box { background: var(--surface); border: 1px solid var(--customer-border, #e2e8f0); border-radius: 8px; padding: 10px; display: flex; align-items: center; justify-content: center; min-height: 150px; }
    .stp-qr-box img { width: 100%; max-width: 160px; height: auto; object-fit: contain; display: block; }
    .stp-account-icon { width: 100%; min-height: 150px; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; background: var(--surface); border: 1px solid var(--customer-border, #e2e8f0); border-radius: 8px; }
    .stp-account-text { margin-top: 10px; font-size: .8rem; color: var(--customer-muted, #64748b); word-break: break-word; line-height: 1.5; }
    .stp-account-text strong { color: var(--customer-text, #1e293b); }
    @media (max-width: 900px) { .checkout-grid { grid-template-columns: 1fr; } }

    /* ==========================================================================
       Payment look-and-feel (.co-* / .pm-*): same system as the redesigned Checkout.
       Order Summary keeps to a few rows (extra items in a collapsed, scrollable list)
       and stays in view; payment methods are selectable cards; the QR / bank
       details appear once, not twice. Colours use --customer-* variables (dark mode);
       text colours are scoped `.customer-panel .co` to out-rank the dark-mode rule.
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
    .co-item { display: grid; grid-template-columns: 44px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--k-border); }
    .co-item:last-child { border-bottom: 0; }
    .co-thumb { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; background: var(--k-surface-2); border: 1px solid var(--k-border); }
    .co-item-main { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
    .customer-panel .co .co-item-name { font-size: 0.85rem; font-weight: 600; line-height: 1.3; color: var(--k-text);
        display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .customer-panel .co .co-item-meta { font-size: 0.75rem; color: var(--k-muted); }
    /* Quantity: bigger and easy to spot */
    .customer-panel .co .co-item-qty { align-self: flex-start; margin-top: 4px; padding: 2px 10px; border-radius: 999px; background: var(--primary-soft); color: var(--k-link, var(--link)); font-size: 0.85rem; font-weight: 800; }
    .customer-panel .co .co-item-total { font-size: 0.85rem; font-weight: 700; color: var(--k-text); white-space: nowrap; }
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
    .customer-panel .co .summary-line.voucher > span { color: var(--success); font-weight: 600; }
    .customer-panel .co .summary-line.total { margin: 10px 0 0; padding-top: 10px; font-size: 1.2rem; border-top: 2px solid var(--k-edge); }
    .customer-panel .co .summary-line.total > span { color: var(--k-text); }

    .co-recv { display: flex; gap: 12px; align-items: flex-start; margin-top: 12px; padding: 12px 14px; border-radius: 12px; background: var(--k-surface); border: 1.5px solid var(--k-edge); }
    .co-recv > i { flex: 0 0 auto; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: color-mix(in srgb, var(--dk-primary, #4f46e5) 12%, var(--k-surface)); color: var(--link); }
    .customer-panel .co .co-recv strong { display: block; font-size: 0.9rem; color: var(--k-text); }
    .customer-panel .co .co-recv span { display: block; font-size: 0.82rem; line-height: 1.45; color: var(--k-muted); overflow-wrap: anywhere; }
    .customer-panel .co .co-recv a { font-size: 0.8rem; font-weight: 700; text-decoration: none; color: var(--k-link); }
    .customer-panel .co .co-recv a:hover { text-decoration: underline; }


    /* Form */
    .customer-panel .co .co-h { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 1rem; }
    .co-num { flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800; color: var(--dk-on-primary, #fff); background: var(--dk-primary, #4f46e5); }
    .pm-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .pm-option { position: relative; display: block; cursor: pointer; }
    .pm-option-off { cursor: not-allowed; opacity: .55; }
    .pm-option-off .pm-body { border-style: dashed; }
    .pm-option input { position: absolute; opacity: 0; pointer-events: none; }
    .pm-body { display: grid; grid-template-columns: 38px 1fr; grid-template-areas: "ic t" "ic s"; column-gap: 12px; align-items: center; padding: 12px 14px; height: 100%; border-radius: 12px;
        background: var(--k-surface); border: 2px solid var(--k-border); transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; }
    .pm-body i { grid-area: ic; width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: var(--k-surface-2); color: var(--k-link); font-size: 1rem; }
    .customer-panel .co .pm-body strong { grid-area: t; font-size: 0.92rem; color: var(--k-text); }
    .customer-panel .co .pm-body small { grid-area: s; font-size: 0.75rem; line-height: 1.35; color: var(--k-muted); }
    .pm-option:hover .pm-body { border-color: var(--k-edge); }
    .pm-option:has(input:checked) .pm-body { border-color: var(--dk-primary, #4f46e5); background: color-mix(in srgb, var(--dk-primary, #4f46e5) 9%, var(--k-surface)); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16); }
    .pm-option:has(input:checked) .pm-body i { background: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }
    .pm-option:has(input:focus-visible) .pm-body { outline: 2px solid var(--dk-primary, #4f46e5); outline-offset: 2px; }

    .customer-panel .co .scan-to-pay { background: var(--k-surface-2); border: 1.5px solid var(--k-edge); }
    .customer-panel .co .stp-card { background: var(--k-surface); border-color: var(--k-border); }
    .stp-qr-box.is-zoom { cursor: zoom-in; }
    /* Keep the shop's GCash / bank QR a scannable size (tap it to zoom); nothing else may enlarge it. */
    .customer-panel .stp-qr-box { min-height: 0; padding: 8px; width: fit-content; margin: 0 auto; }
    .customer-panel .stp-qr-box img { width: 180px !important; max-width: 100% !important; height: auto !important; }
    .customer-panel .co .stp-hint { display: block; margin-top: 6px; font-size: 0.72rem; color: var(--k-muted); }
    .customer-panel .co .gcash-details { background: var(--k-surface-2); border: 1.5px solid var(--k-border); color: var(--k-text); }
    .customer-panel .co .admin-message { margin-bottom: 12px; padding: 10px 12px; border-radius: 10px; font-size: 0.85rem; background: color-mix(in srgb, var(--dk-primary, #4f46e5) 8%, var(--k-surface)); border: 1px solid color-mix(in srgb, var(--dk-primary, #4f46e5) 25%, var(--k-border)); color: var(--k-text); }
    .customer-panel .co .admin-message p { margin: 4px 0 0; }
    .customer-panel .co .form-row label { color: var(--k-text); }
    .customer-panel .co input[type="text"], .customer-panel .co input[type="file"] { background: var(--k-surface); color: var(--k-text); border: 1.5px solid var(--k-border); }
    .customer-panel .co input[type="file"] { width: 100%; padding: 8px; border-radius: 8px; }
    .customer-panel .co input:focus { outline: none; border-color: var(--dk-primary, #4f46e5); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16); }
    .customer-panel .co .pm-hint { display: block; margin-top: 4px; font-size: 0.78rem; color: var(--k-muted); }
    .customer-panel .co .co-place { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; margin-top: 16px; padding: 14px; border-radius: 12px; font-size: 1rem; font-weight: 800; }
    .customer-panel .co .co-back { display: block; text-align: center; margin-top: 12px; font-size: 0.85rem; font-weight: 600; text-decoration: none; color: var(--k-muted); }

    /* dark mode: pieces that were hard-coded light */
    .customer-panel .page-back { background: var(--customer-surface, #fff); border-color: var(--customer-border, #cbd5e1); color: var(--customer-muted, #475569); }
    .customer-panel .page-back:hover { background: var(--customer-surface-2, #f8fafc); color: var(--customer-text, #1e293b); }

    /* order placed */
    .pay-success { max-width: 560px; margin: 24px auto; padding: 30px 26px; text-align: center; border-radius: 18px; background: var(--customer-surface, #fff); border: 2px solid #86efac; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.15); }
    .pay-success .ps-icon { width: 68px; height: 68px; margin: 0 auto 14px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.9rem; color: #fff; background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 8px 20px rgba(5, 150, 105, 0.35); }
    .customer-panel .pay-success h3 { margin: 0 0 6px; font-size: 1.3rem; color: var(--customer-text, #1e293b); }
    .customer-panel .pay-success p { margin: 0 0 6px; color: var(--customer-muted, #64748b); }
    .customer-panel .pay-success .ps-no { display: inline-block; margin: 8px 0 18px; padding: 8px 16px; border-radius: 10px; font-weight: 800; letter-spacing: .02em; background: var(--customer-surface-2, #f8fafc); border: 1.5px dashed var(--customer-border, #cbd5e1); color: var(--customer-text, #1e293b); overflow-wrap: anywhere; }
    .pay-success .ps-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
    .customer-panel .pay-success .ps-actions a { padding: 11px 20px; border-radius: 10px; font-weight: 700; text-decoration: none; }
    .customer-panel .pay-success .ps-actions a.ps-ghost { background: var(--customer-surface, #fff); color: var(--customer-link, var(--dk-primary, #6366f1)); border: 1.5px solid var(--customer-border, #cbd5e1); }

    @media (max-width: 900px) {
        .customer-panel .co { grid-template-columns: minmax(0, 1fr); }
        .co-summary { position: static; }
    }
    @media (max-width: 520px) {
        .pm-options { grid-template-columns: 1fr; }
        .customer-panel .co .checkout-summary, .customer-panel .co .checkout-form { padding: 14px; }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <a href="checkout.php" class="page-back"><i class="fas fa-arrow-left"></i> Back to Checkout</a>
        <?php require_once __DIR__ . '/includes/checkout_steps.php'; echo checkout_steps($success_message && $order_details ? 5 : 3); ?>
        <h2>Payment Method</h2>
        <p>Choose how you'd like to pay. Your order is placed when you confirm below.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert-message alert-error">
            <ul><?php foreach ($errors as $error): ?><li><?php echo $h($error); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success_message && $order_details): ?>
        <div class="pay-success" role="status">
            <div class="ps-icon"><i class="fas fa-check"></i></div>
            <h3><?php echo $h($success_message); ?></h3>
            <p>Order Number</p>
            <div class="ps-no"><?php echo $h($order_details['order_number']); ?></div>
            <div class="ps-actions">
                <a class="button" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo (int)$order_details['id']; ?>">View Order Details</a>
                <a class="ps-ghost" href="<?php echo BASE_URL; ?>/customer/products.php">Continue Shopping</a>
            </div>
        </div>
    <?php else: ?>
        <?php
            // Order summary rows: the first few products are shown, the rest sit in a collapsed, scrollable list.
            $co_all = array_values($cart);
            $co_visible = 3;
            $co_render = static function (array $item) use ($h): void {
                $size = trim((string)($item['size'] ?? ''));
                $name = trim((string)$item['name']);
                if ($size !== '' && substr($name, -strlen(' (' . $size . ')')) === ' (' . $size . ')') {
                    $name = rtrim(substr($name, 0, -strlen(' (' . $size . ')')));
                }
                ?>
                <li class="co-item">
                    <img src="<?php echo $h($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="co-thumb" alt="" loading="lazy">
                    <div class="co-item-main">
                        <span class="co-item-name"><?php echo $h($name); ?></span>
                        <span class="co-item-meta"><?php echo $size !== '' ? $h($size) . ' &bull; ' : ''; ?>PHP <?php echo number_format((float)$item['unit_price'], 2); ?> each</span>
                        <span class="co-item-qty">Qty: <?php echo (int)$item['quantity']; ?></span>
                    </div>
                    <span class="co-item-total">PHP <?php echo number_format((float)$item['unit_price'] * (int)$item['quantity'], 2); ?></span>
                </li>
                <?php
            };
            $pm_meta = [
                // Online, the "GCash" option opens PayMongo with every wallet/card in PAYMONGO_METHODS (e.g. GCash, Maya, Card).
                'gcash'       => ['fa-mobile-alt',     $online_ok ? implode(' / ', array_map(static fn($l) => $l === 'Credit/Debit Card' ? 'Card' : $l, payment_online_wallet_labels())) : 'GCash',
                                  $online_ok ? (count(payment_online_wallet_labels()) > 1 ? 'Pay online with ' . implode(', ', payment_online_wallet_labels()) . ', verified instantly' : 'Pay securely online, verified instantly') : 'Scan the QR, then upload your receipt'],
                'cod'         => ['fa-money-bill-wave', 'Cash on Delivery',      'Pay cash to the rider on arrival'],
                'pay_at_shop' => ['fa-store',          'Pay at Shop',           'Pay cash when you pick up'],
                'bank'        => ['fa-university',     'Bank Transfer',         $online_ok ? 'BPI, UnionBank, BDO, Metrobank, Landbank online' : 'Transfer, then upload your proof'],
            ];
        ?>
        <div class="checkout-grid co">
            <div class="checkout-summary co-summary">
                <h3><i class="fas fa-receipt"></i> Order Summary <span class="co-count"><?php echo count($co_all); ?> item<?php echo count($co_all) === 1 ? '' : 's'; ?></span></h3>
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
                    <div class="summary-line"><span>Subtotal (incl. VAT)</span><span>PHP <?php echo number_format($subtotal_inc_vat, 2); ?></span></div>
                    <?php if ($fulfillment_type === 'delivery'): ?>
                        <div class="summary-line"><span>Delivery Fee<?php echo $delivery_km !== null ? ' (' . number_format($delivery_km, 2) . ' km)' : ''; ?></span><span><?php echo $delivery_free ? 'Free <small>(orders PHP ' . number_format(FREE_DELIVERY_MIN_TOTAL, 0) . '+)</small>' : 'PHP ' . number_format($delivery_fee, 2); ?></span></div>
                    <?php endif; ?>
                    <?php if ($discount_amount > 0): ?>
                        <div class="summary-line voucher"><span>Voucher (<?php echo $h($voucher_code); ?>)</span><span>-PHP <?php echo number_format($discount_amount, 2); ?></span></div>
                    <?php endif; ?>
                    <div class="summary-line total"><span>Total to Pay</span><span>PHP <?php echo number_format($total_to_pay, 2); ?></span></div>
                </div>

                <div class="co-recv">
                    <?php if ($fulfillment_type === 'delivery'): ?>
                        <i class="fas fa-truck"></i>
                        <div>
                            <strong>Delivery</strong>
                            <span><?php echo $h($draft['delivery_address']); ?></span>
                            <span>Contact: <?php echo $h($draft['delivery_phone']); ?></span>
                            <a href="checkout.php"><i class="fas fa-edit"></i> Edit details</a>
                        </div>
                    <?php else: ?>
                        <i class="fas fa-store"></i>
                        <div>
                            <strong>Pick-up</strong>
                            <span><?php echo $h($draft['pickup_date']); ?> at <?php echo $h($draft['pickup_time']); ?></span>
                            <a href="checkout.php"><i class="fas fa-edit"></i> Edit details</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" class="checkout-form" id="payment-form" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <h3 class="co-h"><span class="co-num">1</span> Choose payment method</h3>
                <div class="pm-options">
                    <?php foreach ($allowed_methods as $m): ?>
                        <label class="pm-option">
                            <input type="radio" name="payment_method" value="<?php echo $m; ?>" <?php echo $posted_method === $m ? 'checked' : ''; ?> onchange="togglePaymentFields()">
                            <span class="pm-body"><i class="fas <?php echo $pm_meta[$m][0]; ?>"></i><strong><?php echo $pm_meta[$m][1]; ?></strong><small><?php echo $pm_meta[$m][2]; ?></small></span>
                        </label>
                    <?php endforeach; ?>
                    <?php foreach ($unavailable_methods as $m): ?>
                        <label class="pm-option pm-option-off" title="Not available yet">
                            <input type="radio" disabled>
                            <span class="pm-body"><i class="fas <?php echo $pm_meta[$m][0]; ?>"></i><strong><?php echo $pm_meta[$m][1]; ?></strong><small>Not available yet &mdash; the shop is setting up its <?php echo $m === 'gcash' ? 'GCash' : 'bank'; ?> account.</small></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php $posted_mode = ($_POST['pay_mode'] ?? '') === 'manual' || !$online_ok ? 'manual' : 'online'; ?>
                <?php if ($online_ok): ?>
                <div id="pay-mode-panel" class="pm-mode" style="display:none;">
                    <label class="pm-mode-opt"><input type="radio" name="pay_mode" value="online" <?php echo $posted_mode === 'online' ? 'checked' : ''; ?> onchange="togglePaymentFields()">
                        <span><strong><i class="fas fa-shield-alt"></i> Pay online now</strong><small>Approve it in GCash (or your bank) through PayMongo. Your payment is confirmed automatically.</small></span></label>
                    <?php if ($manual_ok): ?>
                    <label class="pm-mode-opt"><input type="radio" name="pay_mode" value="manual" <?php echo $posted_mode === 'manual' ? 'checked' : ''; ?> onchange="togglePaymentFields()">
                        <span><strong><i class="fas fa-receipt"></i> I'll transfer manually</strong><small>Scan our QR / use our account, then upload the receipt. Staff verify it before your order is confirmed.</small></span></label>
                    <?php endif; ?>
                </div>
                <div id="online-note" class="gcash-details" style="display:none;"><i class="fas fa-lock"></i> After you place the order <span id="online-note-method">you'll go straight to GCash to approve the payment</span>. We never ask for your GCash MPIN, bank password or OTP. Your order is confirmed only once PayMongo confirms the payment.</div>
                <?php else: ?>
                <input type="hidden" name="pay_mode" value="manual">
                <?php endif; ?>

                <!-- One place for the QR / account info (it used to be shown twice) -->
                <div class="scan-to-pay" id="scan-to-pay-panel" style="display:none;">
                    <h4>SCAN TO PAY</h4>
                    <div class="stp-grid">
                        <?php $ga = $shop_accounts['gcash']; $ba = $shop_accounts['bank']; ?>
                        <div class="stp-card" id="stp-gcash-card">
                            <div class="stp-card-label">GCASH</div>
                            <?php if ($ga['qr_url']): ?>
                                <div class="stp-qr-box is-zoom" onclick="openQRModal(this.querySelector('img').src)" title="Click to zoom"><img src="<?php echo $h($ga['qr_url']); ?>" alt="Shop GCash QR code"></div>
                            <?php else: ?>
                                <div class="stp-account-icon"><i class="fas fa-mobile-alt"></i></div>
                            <?php endif; ?>
                            <p class="stp-account-text">
                                <?php if ($ga['account_name'] !== ''): ?><strong><?php echo $h($ga['account_name']); ?></strong><br><?php endif; ?>
                                <?php if ($ga['account_number'] !== ''): ?>GCash: <strong><?php echo $h($ga['account_number']); ?></strong><?php endif; ?>
                                <?php if ($ga['qr_url']): ?><span class="stp-hint">Scan with your GCash app (click the QR to zoom)</span><?php endif; ?></p>
                        </div>
                        <div class="stp-card" id="stp-bank-card">
                            <div class="stp-card-label"><?php echo $h(strtoupper($ba['bank_name'])); ?></div>
                            <?php if ($ba['qr_url']): ?>
                                <div class="stp-qr-box is-zoom" onclick="openQRModal(this.querySelector('img').src)" title="Click to zoom"><img src="<?php echo $h($ba['qr_url']); ?>" alt="Shop bank QR code"></div>
                            <?php else: ?>
                                <div class="stp-account-icon">🏦</div>
                            <?php endif; ?>
                            <p class="stp-account-text"><strong><?php echo $h($ba['account_name']); ?></strong><br>Account #: <strong><?php echo $h($ba['account_number']); ?></strong></p>
                        </div>
                    </div>
                </div>

                <div id="cod-note" class="gcash-details" style="display:none;"><i class="fas fa-motorcycle"></i> Pay in cash to the rider when your order arrives.</div>
                <div id="shop-note" class="gcash-details" style="display:none;"><i class="fas fa-store"></i> Pay in cash at the shop when you pick up your order.</div>

                <div id="gcash-payment-details" class="gcash-details" style="display:none;">
                    <div class="admin-message">
                        <strong>Message from Staff:</strong>
                        <p><?php echo $h($admin_message); ?></p>
                    </div>
                    <div class="form-row">
                        <label for="gcash_reference_number">GCash Reference Number</label>
                        <input type="text" name="gcash_reference_number" id="gcash_reference_number" placeholder="13-digit number" inputmode="numeric" maxlength="13" value="<?php echo $h($_POST['gcash_reference_number'] ?? ''); ?>">
                    </div>
                    <div class="form-row" style="margin-bottom:0;">
                        <label for="gcash_payment_proof">Upload GCash Payment Receipt <span style="color:#dc2626;">*</span></label>
                        <input type="file" name="gcash_payment_proof" id="gcash_payment_proof" accept="image/png, image/jpeg">
                        <small class="pm-hint">Accepted: JPG, JPEG, PNG. Maximum size: 5 MB. Please upload a screenshot of your successful GCash transaction.</small>
                    </div>
                </div>

                <div id="bank-transfer-details" class="gcash-details" style="display:none;">
                    <div class="admin-message">
                        <strong>Message from Staff:</strong>
                        <p><?php echo $h($bank_admin_message); ?></p>
                    </div>
                    <div class="form-row">
                        <label for="bank_name">Bank Name (e.g., BPI, BDO)</label>
                        <input type="text" name="bank_name" id="bank_name" placeholder="The bank you transferred from" value="<?php echo $h($_POST['bank_name'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label for="bank_account_name">Your Account Name</label>
                        <input type="text" name="bank_account_name" id="bank_account_name" placeholder="The name on your bank account" value="<?php echo $h($_POST['bank_account_name'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label for="bank_reference_number">Bank Reference Number</label>
                        <input type="text" name="bank_reference_number" id="bank_reference_number" placeholder="From your transaction receipt" value="<?php echo $h($_POST['bank_reference_number'] ?? ''); ?>">
                    </div>
                    <div class="form-row" style="margin-bottom:0;">
                        <label for="payment_proof">Upload Proof of Payment</label>
                        <input type="file" name="payment_proof" id="payment_proof" accept="image/png, image/jpeg, image/gif">
                    </div>
                </div>

                <button type="submit" class="button co-place" <?php echo $delivery_blocked ? 'disabled' : ''; ?>><i class="fas fa-lock"></i> Place Order &middot; PHP <?php echo number_format($total_to_pay, 2); ?></button>
                <a href="checkout.php" class="co-back"><i class="fas fa-arrow-left"></i> Back to details</a>
            </form>
        </div>
    <?php endif; ?>
</section>

<!-- QR Zoomer Modal -->
<div id="qrModal" class="modal-zoomer">
    <span class="close-zoomer" onclick="closeQRModal()">&times;</span>
    <img class="modal-content-zoomer" id="imgQR">
</div>

<script>
function togglePaymentFields() {
    const checked = document.querySelector('input[name="payment_method"]:checked');
    const method = checked ? checked.value : '';
    const show = (id, on) => { const el = document.getElementById(id); if (el) el.style.display = on ? 'block' : 'none'; };
    const transfer = method === 'gcash' || method === 'bank';
    const manualRadio = document.querySelector('input[name="pay_mode"][value="manual"]');
    if (manualRadio && manualRadio.type === 'radio') {
        const canManual = !!MANUAL_READY[method];
        manualRadio.disabled = !canManual;
        manualRadio.closest('.pm-mode-opt').style.display = canManual ? '' : 'none';
        if (!canManual && manualRadio.checked) document.querySelector('input[name="pay_mode"][value="online"]').checked = true;
    }
    const modeEl = document.querySelector('input[name="pay_mode"]:checked') || document.querySelector('input[name="pay_mode"]');
    const manual = !modeEl || modeEl.value === 'manual';
    show('pay-mode-panel', transfer);
    show('online-note', transfer && !manual);
    const mn = document.getElementById('online-note-method');
    if (mn) mn.textContent = method === 'bank' ? "you'll be taken to PayMongo's secure page to pay with online banking" : "you'll go straight to GCash to approve the payment";
    show('gcash-payment-details', method === 'gcash' && manual);
    show('bank-transfer-details', method === 'bank' && manual);
    show('cod-note', method === 'cod');
    show('shop-note', method === 'pay_at_shop');
    show('scan-to-pay-panel', transfer && manual);
    const gc = document.getElementById('stp-gcash-card'), bk = document.getElementById('stp-bank-card');
    if (gc) gc.style.display = method === 'gcash' ? '' : 'none';
    if (bk) bk.style.display = method === 'bank' ? '' : 'none';
    document.getElementById('gcash_reference_number').required = (method === 'gcash' && manual);
    document.getElementById('bank_reference_number').required = (method === 'bank' && manual);
}
function openQRModal(src) {
    document.getElementById('qrModal').style.display = 'block';
    document.getElementById('imgQR').src = src;
}
// Manual transfer is only offered when the shop's real account for that method is set up.
const MANUAL_READY = <?php echo json_encode($manual_ready); ?>;
function closeQRModal() { document.getElementById('qrModal').style.display = 'none'; }

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('payment-form');
    if (!form) return;
    togglePaymentFields();
    form.addEventListener('submit', function (e) {
        const checked = document.querySelector('input[name="payment_method"]:checked');
        if (!checked) { e.preventDefault(); toastOr('Please choose a payment method.', 'warn'); return; }
        const mode = document.querySelector('input[name="pay_mode"]:checked') || document.querySelector('input[name="pay_mode"]');
        if (checked.value === 'gcash' && (!mode || mode.value === 'manual')) {
            const receipt = document.getElementById('gcash_payment_proof');
            if (!receipt.files || receipt.files.length === 0) {
                e.preventDefault();
                toastOr('Please upload a screenshot of your GCash payment receipt before placing your order.', 'warn');
                receipt.focus();
            }
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
