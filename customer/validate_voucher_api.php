<?php
header('Content-Type: application/json');

// Correct path relative to customer/validate_voucher_api.php
require_once __DIR__ . '/includes/auth.php';

$response = [
    'success' => false,
    'message' => 'An unknown error occurred.',
    'discount_amount' => 0
];

try {
    $customer = current_customer();
    if (!$customer) {
        throw new Exception('Customer not logged in.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $voucher_code_input = trim($input['voucher_code'] ?? '');

    if (empty($voucher_code_input)) {
        throw new Exception('Voucher code cannot be empty.');
    }

    // Voucher Validation Logic (owner: the redemption, or tbl_vouchers.owner_customer_id for Free Shipping vouchers)
    $fs_owner = 'NULL';
    if (is_file(__DIR__ . '/../app/helpers/free_shipping_helper.php')) {
        require_once __DIR__ . '/../app/helpers/free_shipping_helper.php';
        free_shipping_ensure_schema($conn);
        $fs_owner = 'v.owner_customer_id';
    }
    promo_ensure_schema($conn); // promo_kind / per_customer_limit (admin voucher promos)
    $voucher_stmt = $conn->prepare(
        "SELECT v.*, COALESCE(rr.customer_id, $fs_owner) AS customer_id
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
        $is_public = ($voucher['promo_kind'] ?? '') === 'public'; // admin promo code anyone can use
        if (!$is_public && (int)$voucher['customer_id'] !== (int)$customer['id']) {
            throw new Exception('This voucher does not belong to you.');
        }
        if ($is_public && (int)($voucher['per_customer_limit'] ?? 0) > 0
            && voucher_customer_use_count($conn, (string)$voucher['code'], (int)$customer['id']) >= (int)$voucher['per_customer_limit']) {
            throw new Exception('You have already used this promo code the maximum number of times.');
        }
        if ((int)$voucher['used_count'] >= (int)$voucher['usage_limit'] && (int)$voucher['usage_limit'] > 0) {
            throw new Exception('This voucher has already been claimed.');
        }
        if ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
            throw new Exception('This voucher has expired.');
        }

        $cart_subtotal = cart_subtotal();
        if ($voucher['min_order_amount'] && $cart_subtotal < (float)$voucher['min_order_amount']) {
            throw new Exception('A minimum spend of PHP ' . number_format($voucher['min_order_amount'], 2) . ' is required.');
        }

        // All checks passed
        $response['success'] = true;
        $response['message'] = 'Voucher applied successfully!';
        $response['discount_amount'] = ($voucher['discount_type'] ?? '') === 'percent'
            ? round($cart_subtotal * ((float)$voucher['discount_value'] / 100), 2)
            : (float)$voucher['discount_value'];
        $response['discount_amount'] = max(0.0, min($response['discount_amount'], $cart_subtotal));
        if (($voucher['discount_type'] ?? '') === 'free_shipping') {
            // The page takes off whatever the delivery fee is for the chosen address.
            $response['free_shipping'] = true;
            $response['discount_amount'] = 0;
            $response['message'] = 'Free Shipping voucher applied: your delivery fee is removed.';
        }
    } else {
        // Not in tbl_vouchers at all — check whether it's a real IN-STORE
        // redemption code so the customer gets the actual reason instead of
        // a generic "invalid" message (in-store codes never get a
        // tbl_vouchers row; see modules/customers/reward_redemption.php).
        $instore_check = $conn->prepare("SELECT channel FROM reward_redemptions WHERE card_number = ? LIMIT 1");
        $instore_check->bind_param('s', $voucher_code_input);
        $instore_check->execute();
        $instore_row = $instore_check->get_result()->fetch_assoc();
        $instore_check->close();

        if ($instore_row && strtoupper((string)($instore_row['channel'] ?? '')) === 'IN_STORE') {
            throw new Exception('This redemption is valid for in-store use only and cannot be applied to online orders.');
        }
        throw new Exception('The voucher code is invalid or has expired.');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(200);
}

echo json_encode($response);