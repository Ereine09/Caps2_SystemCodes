<?php
require_once __DIR__ . '/includes/auth.php';
// require_customer_login();

// Fetch live E-Bike Rider status from database
$is_rider_available = true; // Default
$rider_query = mysqli_query($conn, "SELECT is_available FROM tbl_rider_status LIMIT 1");
if ($rider_query && mysqli_num_rows($rider_query) > 0) {
    $is_rider_available = (bool)mysqli_fetch_assoc($rider_query)['is_available'];
}

// Handle quantity updates from checkout page form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_checkout_quantity'])) {
    $customer = current_customer();
    $product_id = (int)$_POST['product_id'];
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int)$_POST['variant_id'] : null;
    $quantity = (int)$_POST['quantity'];

    if ($customer && update_customer_cart_item((int)$customer['id'], $product_id, $quantity, $variant_id)) {
        header('Location: checkout.php');
        exit();
    }
}

$cart = get_customer_cart();
if (empty($cart)) {
    header('Location: ' . BASE_URL . '/customer/products.php');
    exit();
}
$customer = current_customer();

$errors = [];
$success_message = '';
$stock_deducted = false; 
$stock_deducted = false;
$order_details = null;

$admin_message = "Please scan the QR code below and enter the 13-digit GCash reference number after payment. Your order will be processed once payment is verified.";
$bank_admin_message = "Please transfer the total amount to the account details below. Upload a screenshot of your transaction receipt and enter the reference number to confirm.";

$voucher_code_input = '';
$discount_amount = 0.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_checkout_quantity'])) {
    $fulfillment_type = $_POST['fulfillment_type'] ?? 'pickup';
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $delivery_phone = trim($_POST['delivery_phone'] ?? '');
    $order_notes = trim($_POST['order_notes'] ?? '');
    $delivery_instructions = trim($_POST['delivery_instructions'] ?? '');
    $pickup_date = trim($_POST['pickup_date'] ?? '');
    $pickup_time = trim($_POST['pickup_time'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cod'; 
    $gcash_reference_number = trim($_POST['gcash_reference_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_reference_number = trim($_POST['bank_reference_number'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $payment_proof_path = '';
    $delivery_fee = 0;
    $voucher_code_input = trim($_POST['voucher_code'] ?? '');

    if ($fulfillment_type === 'delivery') {
        if ($delivery_address === '') {
            $errors[] = 'Delivery address is required for delivery orders.';
        }
        if ($delivery_phone === '') {
            $errors[] = 'Delivery phone number is required.';
        }
        if (!is_delivery_area_allowed($delivery_address)) {
            $errors[] = 'Delivery is currently limited to Caloocan, 10th Avenue, and Grace Park.';
        }

        // Calculate Delivery Fee for Backend
        $addr_lower = strtolower($delivery_address);
        $subtotal = cart_subtotal();
        if (strpos($addr_lower, '10th ave') !== false || strpos($addr_lower, '10th avenue') !== false || strpos($addr_lower, 'grace park') !== false) {
            $delivery_fee = 0;
        } elseif (strpos($addr_lower, 'caloocan') !== false) {
            $delivery_fee = ($subtotal >= 2000) ? 0 : 50;
        }

        if ($payment_method === 'pay_at_shop') {
            $errors[] = 'Pay at shop is only available for pickup orders.';
        }
    } else {
        if ($pickup_date === '') {
            $errors[] = 'Pick-up date is required for pickup orders.';
        }
        if ($pickup_time === '') {
            $errors[] = 'Pick-up time is required for pickup orders.';
        }
        if ($payment_method === 'cod') {
            $errors[] = 'Cash on delivery is only available for delivery orders.';
        }
    }

    if ($payment_method === 'gcash') {
        if ($gcash_reference_number === '') {
            $errors[] = 'GCash reference number is required for GCash payments.';
        } elseif (!preg_match('/^\d{13}$/', $gcash_reference_number)) {
            $errors[] = 'Please enter a valid 13-digit GCash reference number.';
        }
    }

    if ($payment_method === 'bank') {
        if ($bank_name === '') $errors[] = 'Bank Name is required for bank transfers.';
        if ($bank_account_name === '') $errors[] = 'Your Account Name is required for bank transfers.';
        if ($bank_reference_number === '') $errors[] = 'Bank Reference Number is required.';

        // --- Handle File Upload for Payment Proof ---
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['payment_proof'];
            $upload_dir = __DIR__ . '/../uploads/proofs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowed_types)) {
                $errors[] = 'Invalid file type. Please upload a JPG, PNG, or GIF image.';
            } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
                $errors[] = 'File is too large. Maximum size is 5MB.';
            } else {
                $filename = uniqid('proof_', true) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
                $destination = $upload_dir . $filename;
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $payment_proof_path = '/uploads/proofs/' . $filename; // Store relative path
                } else {
                    $errors[] = 'Failed to upload payment proof. Please try again.';
                }
            }
        } else {
            $errors[] = 'A payment proof screenshot is required for bank transfers.';
        }
    }

    $items_to_deduct = [];
    foreach ($cart as $item) {
        // Fix: Check for both 'product_id' and 'id' in case the cart array uses the product's primary key name
        $product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $requested_quantity = (int) ($item['quantity'] ?? 0);
        $available_stock = get_product_stock($product_id); 

        if ($requested_quantity > $available_stock) {
            $errors[] = 'Insufficient stock for ' . htmlspecialchars($item['name']) . '. Only ' . $available_stock . ' available.';
        } else {
            $items_to_deduct[] = ['product_id' => $product_id, 'quantity' => $requested_quantity];
        }
    }

    // --- Voucher Validation ---
    if (!empty($voucher_code_input)) {
        $voucher_stmt = $conn->prepare(
            "SELECT v.*, rr.customer_id 
             FROM tbl_vouchers v 
             LEFT JOIN reward_redemptions rr ON v.code = rr.card_number
             WHERE v.code = ? AND v.active = 1 
             LIMIT 1"
        );
        $voucher_stmt->bind_param('s', $voucher_code_input);
        $voucher_stmt->execute();
        $voucher_result = $voucher_stmt->get_result();
        $voucher = $voucher_result->fetch_assoc();
        $voucher_stmt->close();

        if ($voucher) {
            // Check if voucher belongs to the current customer
            if ((int)$voucher['customer_id'] !== (int)$customer['id']) {
                $errors[] = 'This voucher does not belong to you.';
            }
            // Check if voucher is already used
            if ((int)$voucher['used_count'] >= (int)$voucher['usage_limit'] && (int)$voucher['usage_limit'] > 0) {
                $errors[] = 'This voucher is already claimed.';
            }
            // Check expiry
            if ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
                $errors[] = 'This voucher has expired.';
            }
            // Check minimum spend
            if ($voucher['min_order_amount'] && cart_subtotal() < (float)$voucher['min_order_amount']) {
                $errors[] = 'A minimum spend of PHP ' . number_format($voucher['min_order_amount'], 2) . ' is required to use this voucher.';
            }

            if (empty($errors)) { // Only calculate discount if no voucher errors
                $discount_amount = (float)$voucher['discount_value'];
            }
        } else {
            $errors[] = 'The voucher code entered is invalid or has expired.';
        }
    }


    if (empty($errors)) {
        $details = [
            'delivery_address' => $delivery_address,
            'delivery_phone' => $delivery_phone,
            'subtotal' => cart_subtotal(), // Add subtotal
            'order_notes' => $order_notes,
            'delivery_instructions' => $delivery_instructions,
            'pickup_date' => $pickup_date,
            'pickup_time' => $pickup_time,
            'payment_method' => $payment_method,
            'gcash_reference_number' => ($payment_method === 'gcash') ? $gcash_reference_number : $bank_reference_number,
            'reference_number' => ($payment_method === 'gcash') ? $gcash_reference_number : $bank_reference_number,
            'delivery_fee' => $delivery_fee,
            'voucher_code' => $voucher_code_input, // Pass voucher to order creation
            'discount_amount' => $discount_amount, // Pass discount amount
            'bank_name' => $bank_name,
            'bank_account_name' => $bank_account_name,
            'payment_proof_path' => $payment_proof_path,
        ];

        // Ensure we are using the global connection defined in config.php
        $GLOBALS['conn']->begin_transaction();

        $deduction_successful = true;
        foreach ($items_to_deduct as $item) {
            if (!deduct_product_stock($item['product_id'], $item['quantity'])) {
                $errors[] = 'Failed to deduct stock for product ID ' . $item['product_id'] . '. Please try again.';
                $deduction_successful = false;
                break;
            }
        }

        if ($deduction_successful) {
            $stock_deducted = true; 
            $stock_deducted = true;
            $order_id = create_customer_order((int)$customer['id'], $fulfillment_type, $details, $cart);
            if ($order_id !== null) {
$GLOBALS['conn']->commit();
                
                // --- Loyalty Points Logic (MUST be BEFORE clear_customer_cart) ---
                $customer_id = (int) current_customer()['id'];
                
                // Capture subtotal BEFORE cart is cleared (use ex-VAT for points calculation)
                $order_cart_subtotal_net = cart_subtotal_ex_vat();
                
                // Fetch previous loyalty points before any updates
                $previous_points_query = mysqli_query($conn, "SELECT loyalty_points FROM customers WHERE id = " . $customer_id);
                $previous_points_row = mysqli_fetch_assoc($previous_points_query);
                $previous_points = (float)($previous_points_row['loyalty_points'] ?? 0.00);

                // Points are earned on the subtotal before discount (ex-VAT)
                $points_earned = round($order_cart_subtotal_net / 100, 2); // P100 = 1 point
                // --- End capture ---

                clear_customer_cart();

                // --- VOUCHER SINGLE-USE DEACTIVATION LOGIC ---
                if (!empty($voucher_code_input)) {
                    $v_code = mysqli_real_escape_string($conn, $voucher_code_input);
                    
                    // 1. Deactivates the checkout validity state
                    mysqli_query($conn, "UPDATE tbl_vouchers SET active = 0 WHERE code = '$v_code'");
                    
                    // 2. Updates the history tracking state to 'Used' for my_reward.php blocking
                    mysqli_query($conn, "UPDATE reward_redemptions SET status = 'Used' WHERE card_number = '$v_code'");
                }

                if ($points_earned > 0) {
                    // Get customer name and email for notifications
                    $client_stmt = $conn->prepare("SELECT name, email FROM customers WHERE id = ? LIMIT 1");
                    $client_stmt->bind_param("i", $customer_id);
                    $client_stmt->execute();
                    $client = $client_stmt->get_result()->fetch_assoc();
                    $client_name = $client['name'] ?? 'Customer';
                    $client_email = $client['email'] ?? '';

                    // Record loyalty transaction
                    $transaction_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned, order_id) VALUES (?, NULL, ?, ?, ?, ?)"); // user_id is NULL for customer-initiated transactions
                    // For purchases, product_name can be 'Online Purchase', quantity_kg can be 0 or derived if applicable
                    $product_name_for_transaction = 'Online Purchase (Order #' . $order_id . ')';
                    $zero_kg = 0.00; // No direct kg for this point earning method
                    if ($transaction_stmt) {
                        $transaction_stmt->bind_param("isddi", $customer_id, $product_name_for_transaction, $zero_kg, $points_earned, $order_id);
                        if (!$transaction_stmt->execute()) {
                            error_log('[checkout] Failed to insert loyalty transaction: ' . $transaction_stmt->error);
                        }
                        $transaction_id = (int) $conn->insert_id;
                        $transaction_stmt->close();
                    } else {
                        error_log('[checkout] Failed to prepare loyalty transaction statement: ' . $conn->error);
                        $transaction_id = 0;
                    }

                    // Sync points again after adding transaction
                    $new_total_points = notifications_sync_customer_loyalty_points($conn, $customer_id);

                    // Send notifications
                    notifications_create($conn, [
                        'user_id' => NULL, // Customer-initiated action, no staff user_id
                        'customer_id' => $customer_id,
                        'type' => 'points_earned',
                        'channel' => 'both',
                        'title' => 'You earned ' . notifications_format_points($points_earned) . ' points!',
                        'message' => $client_name . ' earned ' . notifications_format_points($points_earned) . ' points from your purchase. New usable balance: ' . notifications_format_points($new_total_points) . ' points. Points expire after ' . notifications_expiry_months() . ' months.',
                        'reference_table' => 'loyalty_transactions',
                        'reference_id' => $transaction_id,
                        'points_value' => $points_earned,
                        'email_to' => $client_email
                    ]);

                    foreach (notifications_crossed_thresholds($previous_points, $new_total_points) as $threshold) {
                        // --- FIX: Update customer's loyalty points balance ---
                        $update_points_stmt = $conn->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
                        if ($update_points_stmt) {
                            $update_points_stmt->bind_param("di", $new_total_points, $customer_id);
                            $update_points_stmt->execute();
                            $update_points_stmt->close();
                        }
                        notifications_create($conn, [
                            'user_id' => NULL, // Customer-initiated action, no staff user_id
                            'customer_id' => $customer_id,
                            'type' => 'reward_redeemable',
                            'channel' => 'in_app',
                            'title' => 'You can now redeem a reward',
                            'message' => $client_name . ' now has ' . notifications_format_points($new_total_points) . ' usable points and unlocked the ' . notifications_format_points($threshold) . '-point reward tier.',
                            'reference_table' => 'loyalty_transactions',
                            'reference_id' => $transaction_id,
                            'points_value' => $new_total_points,
                            'email_to' => $client_email
                        ]);
                    }
                }
                // --- End Loyalty Points Logic ---

                $order_details = get_order_by_id($order_id, (int)$customer['id']);
                $success_message = 'Your order was successfully placed.';
            } else {
                $GLOBALS['conn']->rollback();

                $real_err = $_SESSION['last_order_error'] ?? null;
                unset($_SESSION['last_order_error']);

                $errors[] = $real_err ? $real_err : 'Unable to place your order at this time. Please try again later.';
            }
        } else {
            $GLOBALS['conn']->rollback();
            $GLOBALS['conn']->rollback(); // Rollback if stock deduction fails
        }
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
    /* Layout fix */
    .checkout-grid {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 30px;
        align-items: start;
        margin-top: 20px;
    }

    .checkout-summary, .checkout-form {
        background: #fff;
        padding: 20px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
    }

    /* Fix for radio buttons and labels alignment */
    .option-group {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
    }

    .option-label {
        display: flex;
        align-items: center; /* Magic para pumantay sa gitna */
        gap: 8px;
        cursor: pointer;
        font-weight: 500;
        color: #334155;
    }

    .form-row {
        margin-bottom: 15px;
    }

    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #1e293b;
    }

    input[type="text"], input[type="date"], input[type="time"], textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        box-sizing: border-box;
    }

    /* Organized Summary Table */
    .summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    .summary-table th { text-align: left; padding: 10px; color: #64748b; font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid #f1f5f9; }
    .summary-table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; vertical-align: middle; }
    
    .summary-footer {
        background: #f8fafc;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
    }
    .summary-line { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.95rem; color: #475569; }
    .summary-line.total { border-top: 2px solid #e2e8f0; margin-top: 15px; padding-top: 15px; font-weight: 800; color: #1e293b; font-size: 1.2rem; }
    .summary-item-img { width: 45px; height: 45px; object-fit: contain; border-radius: 6px; background: #fff; border: 1px solid #eee; }

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
        background-color: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
    }
    .rider-status-box.unavailable {
        background-color: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }
    
    /* GCash Section Styling */
    .payment-options {
        border-top: 1px solid #eee;
        padding-top: 20px;
        margin-top: 20px;
    }

    .payment-method-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        border: 1px solid #e2e8f0;
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
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 15px;
        background-color: #f8fafc;
        margin-top: 10px;
    }

    .qr-wrapper {
        cursor: pointer;
        display: inline-block;
        border: 1px solid #cbd5e1;
        padding: 5px;
        background: #fff;
        text-align: center;
    }

    .qr-wrapper img { width: 150px; height: auto; }

    @media (max-width: 900px) {
        .checkout-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Checkout</h2>
        <p>Review your cart and provide delivery or pickup details.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert-message alert-error">
            <ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success_message && $order_details): ?>
        <div class="alert-message alert-success">
            <p><?php echo htmlspecialchars($success_message); ?></p>
            <p>Order Number: <strong><?php echo htmlspecialchars($order_details['order_number']); ?></strong></p>
            <a class="button" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo $order_details['id']; ?>">View Order Details</a>
        </div>
    <?php else: ?>
        <div class="checkout-grid">
            <div class="checkout-summary">
                <h3>Order Summary</h3>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:center;">Qty</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart as $item): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL.'/assets/img/placeholder.png'); ?>" class="summary-item-img">
                                        <span style="font-weight:600;"><?php echo htmlspecialchars($item['name']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <form method="POST" style="display:flex; align-items:center; gap:5px;">
                                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="variant_id" value="<?php echo $item['variant_id']; ?>">
                                        <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" class="qty-input" style="width: 60px;">
                                        <button type="submit" name="update_checkout_quantity" class="button" style="padding: 8px 12px; font-size: 0.75rem;"><i class="fas fa-sync"></i></button>
                                    </form>
                                </td>
                                <td style="text-align:right; font-weight:700;" class="item-subtotal">PHP <?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <div style="margin-top: 15px; text-align: center;">
                    <a href="cart.php" style="color: #6366f1; font-size: 0.85rem; text-decoration: none; font-weight: 600;"><i class="fas fa-edit"></i> Edit Items in Cart</a>
                </div>
            </div>

            <form method="POST" action="" class="checkout-form" id="checkout-form" enctype="multipart/form-data">
                <h3>Fulfillment Method</h3>

                <div class="form-row">
                    <label for="voucher_code">Voucher Code (from My Rewards)</label>
                    <div style="display: flex; gap: 10px;"><input type="text" name="voucher_code" id="voucher_code_input" placeholder="Enter voucher code" value="<?php echo htmlspecialchars($voucher_code_input); ?>" onkeyup="this.value = this.value.toUpperCase();">
                    <button type="button" id="apply_voucher_btn" class="button button-secondary" style="white-space: nowrap;">Apply</button></div>
                    <div id="voucher-message" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
                </div>

                <div class="option-group">
                    <label class="option-label">
                        <input type="radio" name="fulfillment_type" value="pickup" <?php echo (!isset($_POST['fulfillment_type']) || $_POST['fulfillment_type'] === 'pickup') ? 'checked' : ''; ?> onchange="toggleFulfillmentFields()"> Pick-up
                    </label>
                    <label class="option-label">
                        <input type="radio" name="fulfillment_type" value="delivery" <?php echo (isset($_POST['fulfillment_type']) && $_POST['fulfillment_type'] === 'delivery') ? 'checked' : ''; ?> onchange="toggleFulfillmentFields()"> Delivery
                    </label>
                </div>

                <!-- ADDED: E-Bike Rider Availability Status Indicator -->
                <div id="rider-status-container">
                    <?php if ($is_rider_available): ?>
                        <div class="rider-status-box available">
                            <i class="fas fa-motorcycle" style="font-size: 1.2rem; margin-top: 2px;"></i>
                            <div>
                                <div><span class="rider-status-badge badge-green">Available</span> <strong>E-Bike Delivery Rider</strong></div>
                                <div style="font-size: 0.82rem; margin-top: 2px;">Rider is active and ready to deliver your orders.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="rider-status-box unavailable">
                            <i class="fas fa-battery-quarter" style="font-size: 1.2rem; margin-top: 2px;"></i>
                            <div>
                                <div><span class="rider-status-badge badge-red">Unavailable</span> <strong>E-Bike is Charging</strong></div>
                                <div style="font-size: 0.82rem; margin-top: 2px;">Please wait 3–5 hours as our e-bike is currently charging. We appreciate your patience!</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="pickup-fields" style="<?php echo (!isset($_POST['fulfillment_type']) || $_POST['fulfillment_type'] === 'pickup') ? '' : 'display:none;'; ?>">
                    <div class="form-row">
                        <label for="pickup_date">Pick-up Date</label>
                        <input type="date" name="pickup_date" id="pickup_date" value="<?php echo htmlspecialchars($_POST['pickup_date'] ?? ''); ?>" />
                    </div>
                    <div class="form-row">
                        <label for="pickup_time">Pick-up Time</label>
                        <input type="time" name="pickup_time" id="pickup_time" value="<?php echo htmlspecialchars($_POST['pickup_time'] ?? ''); ?>" />
                    </div>
                </div>

                <div id="delivery-fields" style="<?php echo (isset($_POST['fulfillment_type']) && $_POST['fulfillment_type'] === 'delivery') ? '' : 'display:none;'; ?>">
                    <div class="form-row">
                        <label for="delivery_address">Delivery Address <small>(Pin location on map for accuracy)</small></label>
                        <input type="text" name="delivery_address" id="delivery_address" value="<?php echo htmlspecialchars($_POST['delivery_address'] ?? ''); ?>" oninput="updateDeliveryFeeDisplay()" />
                    </div>
                    <div class="form-row">
                        <label for="delivery_phone">Delivery Phone</label>
                        <input type="text" name="delivery_phone" id="delivery_phone" value="<?php echo htmlspecialchars($_POST['delivery_phone'] ?? ''); ?>" />
                    </div>
                    <div class="form-row">
                        <label for="delivery_instructions">Delivery Instructions</label>
                        <textarea name="delivery_instructions" id="delivery_instructions"><?php echo htmlspecialchars($_POST['delivery_instructions'] ?? ''); ?></textarea>
                    </div>
                    <!-- Map Selection for Delivery Area Verification -->
                    <div id="map-container" style="margin-top: 15px;">
                        <div id="map" style="height: 400px; border-radius: 8px; border: 1px solid #cbd5e1;"></div>
                        <input type="hidden" name="latitude" id="latitude">
                        <input type="hidden" name="longitude" id="longitude">
                    </div>
                </div>
                <div class="form-row" style="margin-top: 20px;">
                    <label for="order_notes">Order Notes (Optional)</label>
                    <textarea name="order_notes" id="order_notes" placeholder="e.g., 'Please pack in a box.' or 'My friend will pick it up.'"><?php echo htmlspecialchars($_POST['order_notes'] ?? ''); ?></textarea>
                </div>

                <div class="payment-options">
                    <h3>Payment Method</h3>
                    <div class="option-group">
                        <label class="option-label">
                            <input type="radio" name="payment_method" value="gcash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'gcash') ? 'checked' : ''; ?> onchange="togglePaymentFields()"> GCash
                        </label>
                        <label class="option-label" id="cod-option">
                            <input type="radio" name="payment_method" value="cod" <?php echo (!isset($_POST['payment_method']) || $_POST['payment_method'] === 'cod') ? 'checked' : ''; ?> onchange="togglePaymentFields()"> Cash on Delivery (COD)
                        </label>
                        <label class="option-label" id="pay_at_shop-option">
                            <input type="radio" name="payment_method" value="pay_at_shop" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'pay_at_shop') ? 'checked' : ''; ?> onchange="togglePaymentFields()"> Pay at Shop
                        </label>
                        <label class="option-label" id="bank-option">
                            <input type="radio" name="payment_method" value="bank" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'bank') ? 'checked' : ''; ?> onchange="togglePaymentFields()"> Bank Transfer
                        </label>
                    </div>

                    <div id="gcash-payment-details" class="gcash-details" style="<?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'gcash') ? '' : 'display:none;'; ?>">
                        <div class="admin-message">
                            <strong>Message from Staff:</strong>
                            <p style="font-size: 0.9rem;"><?php echo htmlspecialchars($admin_message); ?></p>
                        </div>

                        <div class="qr-section" style="text-align: center;">
                            <p><strong>Scan to Pay</strong></p>
                            <div class="qr-wrapper" onclick="openQRModal()">
                                <img id="gcash-qr" src="<?php echo BASE_URL; ?>/customer/assets/images/sampleqr.jpg" alt="GCash QR Code">
                                <p style="font-size: 10px; color: #666;">(Click to zoom)</p>
                            </div>
                        </div>

                        <div class="form-row" style="margin-top: 15px;">
                            <label for="gcash_reference_number">GCash Reference Number:</label>
                            <input type="text" name="gcash_reference_number" id="gcash_reference_number" placeholder="13-digit number" value="<?php echo htmlspecialchars($_POST['gcash_reference_number'] ?? ''); ?>">
                        </div>
                    </div>

                    <div id="bank-transfer-details" class="gcash-details" style="<?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'bank') ? '' : 'display:none;'; ?>">
                        <div class="admin-message">
                            <strong>Message from Staff:</strong>
                            <p style="font-size: 0.9rem;"><?php echo htmlspecialchars($bank_admin_message); ?></p>
                        </div>
                        <div style="margin-top: 15px; background: #fff; padding: 15px; border-radius: 8px;">
                            <p><strong>Bank:</strong> BDO Unibank<br><strong>Account Name:</strong> Darius Poultry Supply<br><strong>Account Number:</strong> 001234567890</p>
                        </div>
                        <div class="form-row" style="margin-top: 15px;">
                            <label for="bank_name">Bank Name (e.g., BPI, BDO):</label>
                            <input type="text" name="bank_name" id="bank_name" placeholder="The bank you transferred from" value="<?php echo htmlspecialchars($_POST['bank_name'] ?? ''); ?>">
                        </div>
                        <div class="form-row">
                            <label for="bank_account_name">Your Account Name:</label>
                            <input type="text" name="bank_account_name" id="bank_account_name" placeholder="The name on your bank account" value="<?php echo htmlspecialchars($_POST['bank_account_name'] ?? ''); ?>">
                        </div>
                        <div class="form-row">
                            <label for="bank_reference_number">Bank Reference Number:</label>
                            <input type="text" name="bank_reference_number" id="bank_reference_number" placeholder="From your transaction receipt" value="<?php echo htmlspecialchars($_POST['bank_reference_number'] ?? ''); ?>">
                        </div>
                        <div class="form-row">
                            <label for="payment_proof">Upload Proof of Payment:</label>
                            <input type="file" name="payment_proof" id="payment_proof" accept="image/png, image/jpeg, image/gif">
                        </div>
                    </div>

                    <input type="hidden" name="discount_amount" id="discount_amount_hidden" value="0">
                </div>
                <button type="submit" class="button" style="width:100%; margin-top:20px;">Place Order</button>
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
function updateTotals(discount = 0) {
    const fulfillmentType = document.querySelector('input[name="fulfillment_type"]:checked').value;
    const address = document.getElementById('delivery_address').value.toLowerCase();
    const subtotalNetEl = document.getElementById('subtotal-net-display');
    const subtotal_net = parseFloat(subtotalNetEl.dataset.value) || 0;
    const subtotal_gross = subtotal_net * 1.12;

    let fee = 0;
    document.getElementById('discount_amount_hidden').value = discount;

    if (fulfillmentType === 'delivery') {
        if (address.includes('10th ave') || address.includes('10th avenue') || address.includes('grace park')) {
            fee = 0;
        } else if (address.includes('caloocan')) {
            fee = (subtotal >= 2000) ? 0 : 50;
        } else if (address.trim() !== '') {
            fee = 120;
        }
    }

    const totalBeforeDiscount = subtotal_gross + fee;
    const finalTotal = Math.max(0, totalBeforeDiscount - discount);

    const feeDisplay = document.getElementById('delivery-fee-display');
    const totalDisplay = document.getElementById('total-to-pay-display');
    const vatDisplay = document.getElementById('vat-display');
    const discountLine = document.getElementById('discount-line');
    const discountDisplay = document.getElementById('discount-display');
    const pointsDisplay = document.getElementById('points-to-earn-display');

    feeDisplay.innerText = fee === 0 ? 'Free' : 'PHP ' + fee.toFixed(2);
    totalDisplay.innerText = 'PHP ' + finalTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    vatDisplay.innerText = 'PHP ' + (subtotal_net * 0.12).toFixed(2);

    // Update points earned based on discounted net subtotal
   const pointsEarned = Math.max(0, subtotal_net / 100);
    pointsDisplay.innerText = `+${pointsEarned.toFixed(2)} pts`;

    if (discount > 0) {
        discountLine.style.display = 'flex';
        discountDisplay.innerText = '-PHP ' + discount.toFixed(2);
    } else {
        discountLine.style.display = 'none';
    }
}

function updateDeliveryFeeDisplay() {
    updateTotals(parseFloat(document.getElementById('discount_amount_hidden').value) || 0);
}

function toggleFulfillmentFields() {
    const type = document.querySelector('input[name="fulfillment_type"]:checked').value;
    document.getElementById('pickup-fields').style.display = type === 'pickup' ? 'block' : 'none';
    document.getElementById('delivery-fields').style.display = type === 'delivery' ? 'block' : 'none';
    
    const mapContainer = document.getElementById('map-container');
    if (mapContainer) mapContainer.style.display = type === 'delivery' ? 'block' : 'none';
    if (type === 'delivery') setTimeout(initFreeMap, 100);

    const codOption = document.getElementById('cod-option');
    const payAtShopOption = document.getElementById('pay_at_shop-option');
    
    if (type === 'pickup') {
        codOption.style.display = 'none';
        payAtShopOption.style.display = 'flex';
        if (document.querySelector('input[name="payment_method"][value="cod"]').checked) {
            document.querySelector('input[name="payment_method"][value="pay_at_shop"]').checked = true;
        }
    } else {
        codOption.style.display = 'flex';
        payAtShopOption.style.display = 'none';
        if (document.querySelector('input[name="payment_method"][value="pay_at_shop"]').checked) {
            document.querySelector('input[name="payment_method"][value="cod"]').checked = true;
        }
    }
    togglePaymentFields();
    updateTotals(parseFloat(document.getElementById('discount_amount_hidden').value) || 0);
}

function togglePaymentFields() {
    const method = document.querySelector('input[name="payment_method"]:checked').value;
    const gcashDetails = document.getElementById('gcash-payment-details');
    const bankDetails = document.getElementById('bank-transfer-details');
    const refInput = document.getElementById('gcash_reference_number');

    gcashDetails.style.display = method === 'gcash' ? 'block' : 'none';
    bankDetails.style.display = method === 'bank' ? 'block' : 'none';

    refInput.required = (method === 'gcash');
    document.getElementById('bank_reference_number').required = (method === 'bank');
}

let leafletMap, marker;
function initFreeMap() {
    if (leafletMap) {
        leafletMap.invalidateSize();
        return;
    }
    leafletMap = L.map('map').setView([14.6416, 120.9762], 13);
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
        if (marker) leafletMap.removeLayer(marker);
        marker = L.marker(latlng).addTo(leafletMap);
        leafletMap.setView(latlng, 16);

        document.getElementById('latitude').value = latlng.lat;
        document.getElementById('longitude').value = latlng.lng;
        document.getElementById('delivery_address').value = e.geocode.name;
        updateTotals();
    })
    .addTo(leafletMap);

    leafletMap.on('click', function(e) {
        if (marker) leafletMap.removeLayer(marker);
        marker = L.marker(e.latlng).addTo(leafletMap);
        document.getElementById('latitude').value = e.latlng.lat;
        document.getElementById('longitude').value = e.latlng.lng;

        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
            .then(response => response.json())
            .then(data => {
                if (data.display_name) {
                    document.getElementById('delivery_address').value = data.display_name;
                    updateTotals();
                }
            })
            .catch(err => console.error('Geocoding error:', err));
    });
}

function openQRModal() {
    const modal = document.getElementById("qrModal");
    const modalImg = document.getElementById("imgQR");
    const srcImg = document.getElementById("gcash-qr");
    modal.style.display = "block";
    modalImg.src = srcImg.src;
}

function closeQRModal() {
    document.getElementById("qrModal").style.display = "none";
}

document.addEventListener('DOMContentLoaded', function() {
    const voucherInput = document.getElementById('voucher_code_input');
    const applyBtn = document.getElementById('apply_voucher_btn');
    const voucherMessage = document.getElementById('voucher-message');

    async function validateVoucher() {
        const code = voucherInput ? voucherInput.value.trim() : '';

        if (!code) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Please enter a voucher code.';
                voucherMessage.style.color = '#dc2626';
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

            if (!response.ok) {
                throw new Error(data.message || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message;
                    voucherMessage.style.color = '#16a34a';
                }
                const hiddenDiscount = document.getElementById('discount_amount_hidden');
                if (hiddenDiscount) hiddenDiscount.value = data.discount_amount;
                
                // Recalculate summary & totals dynamically
                updateTotals(parseFloat(data.discount_amount));
            } else {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message || 'An unknown error occurred.';
                    voucherMessage.style.color = '#dc2626';
                }
                const hiddenDiscount = document.getElementById('discount_amount_hidden');
                if (hiddenDiscount) hiddenDiscount.value = 0;
                
                // Reset totals if voucher is invalid
                updateTotals(0);
            }
        } catch (error) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Could not connect to the server. Check path/file.';
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
        voucherInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                validateVoucher();
            }
        });
    }
});

</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
function updateTotals (discount = 0) {
    const fulfillmentInput = document.querySelector('input[name="fulfillment_type"]:checked');
    const fulfillmentType = fulfillmentInput ? fulfillmentInput.value : 'pickup';
    const addressEl = document.getElementById('delivery_address');
    const address = addressEl ? addressEl.value.toLowerCase() : '';
    const subtotalNetEl = document.getElementById('subtotal-net-display');
    const subtotal_net = parseFloat(subtotalNetEl ? subtotalNetEl.dataset.value : 0);
    const subtotal_gross = subtotal_net * 1.12;
    let fee = 0;

    const hiddenDiscount = document.getElementById('discount_amount_hidden');
    if (hiddenDiscount) hiddenDiscount.value = discount;

    if (fulfillmentType === 'delivery') {
        if (address.includes('10th ave') || address.includes('10th avenue') || address.includes('grace park')) {
            fee = 0;
        } else if (address.includes('caloocan')) {
            fee = (subtotal_gross > 2000) ? 0 : 50;
        } else if (address.trim() === '') {
            fee = 120;
        }
    }

    const totalBeforeDiscount = subtotal_gross + fee;
    const finalTotal = Math.max(0, totalBeforeDiscount - discount);

    const feeDisplay = document.getElementById('delivery-fee-display');
    const totalDisplay = document.getElementById('total-to-pay-display');
    const vatDisplay = document.getElementById('vat-display');
    const discountLine = document.getElementById('discount-line');
    const discountDisplay = document.getElementById('discount-display');
    const pointsDisplay = document.getElementById('points-to-earn-display');

    if (feeDisplay) feeDisplay.innerText = fee === 0 ? 'Free' : 'PHP ' + fee.toFixed(2);
    if (totalDisplay) totalDisplay.innerText = 'PHP ' + finalTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    if (vatDisplay) vatDisplay.innerText = 'PHP ' + (subtotal_net * 0.12).toFixed(2);

    // FIXED: Points earned calculated on pre-discount net subtotal
    const pointsEarned = Math.max(0, subtotal_net / 100);
    if (pointsDisplay) pointsDisplay.innerText = `+${pointsEarned.toFixed(2)} pts`;

    if (discountLine && discountDisplay) {
        if (discount > 0) {
            discountLine.style.display = 'flex';
            discountDisplay.innerText = '-PHP ' + discount.toFixed(2);
        } else {
            discountLine.style.display = 'none';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const voucherInput = document.getElementById('voucher_code_input');
    const applyBtn = document.getElementById('apply_voucher_btn');
    const voucherMessage = document.getElementById('voucher-message');

    async function validateVoucher() {
        const code = voucherInput ? voucherInput.value.trim() : '';
        if (!code) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Please enter a voucher code.';
                voucherMessage.style.color = '#dc2626';
            }
            return;
        }

        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Applying...';
        }

        try {
            const response = await fetch('api/validate_voucher_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ voucher_code: code })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success) {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message;
                    voucherMessage.style.color = '#16a34a';
                }
                const hiddenDiscount = document.getElementById('discount_amount_hidden');
                if (hiddenDiscount) hiddenDiscount.value = data.discount_amount;
                updateTotals(parseFloat(data.discount_amount));
            } else {
                if (voucherMessage) {
                    voucherMessage.textContent = data.message || 'An unknown error occurred.';
                    voucherMessage.style.color = '#dc2626';
                }
                const hiddenDiscount = document.getElementById('discount_amount_hidden');
                if (hiddenDiscount) hiddenDiscount.value = 0;
                updateTotals(0);
            }
        } catch (error) {
            if (voucherMessage) {
                voucherMessage.textContent = 'Could not connect to the server. Check path/file.';
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
        voucherInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                validateVoucher();
            }
        });

        // Auto validate on load if field is not empty
        if (voucherInput.value.trim() !== '') {
            validateVoucher();
        }
    }
});
</script>
