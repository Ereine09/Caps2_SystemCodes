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

    // Voucher Validation Logic
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
        if ((int)$voucher['customer_id'] !== (int)$customer['id']) {
            throw new Exception('This voucher does not belong to you.');
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
        $response['discount_amount'] = (float)$voucher['discount_value'];
    } else {
        throw new Exception('The voucher code is invalid or has expired.');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(200);
}

echo json_encode($response);