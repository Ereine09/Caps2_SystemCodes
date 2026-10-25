<?php
/**
 * Shared checks for the two-step checkout:
 *   step 1  customer/checkout.php  - fulfillment, address, voucher  -> saves a draft in the session
 *   step 2  customer/payment.php   - payment method + proof         -> places the order
 * Both steps run the same stock/voucher checks so they can't drift apart.
 */

/** Returns error strings for cart lines that ask for more than the available stock. */
function checkout_check_stock(array $cart): array
{
    $errors = [];
    foreach ($cart as $item) {
        $product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $variant_id = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
        $requested = (int) ($item['quantity'] ?? 0);
        $available = get_product_stock($product_id, $variant_id);
        if ($requested > $available) {
            $errors[] = 'Insufficient stock for ' . htmlspecialchars($item['name']) . '. Only ' . $available . ' available.';
        }
    }
    return $errors;
}

/**
 * Validates a voucher code for this customer/cart (same rules the order
 * transaction enforces authoritatively in create_customer_order()).
 * Returns ['errors' => string[], 'discount' => float] - the discount is only a
 * preview for display; the saved discount is always recomputed on order creation.
 */
function checkout_validate_voucher(mysqli $conn, array $customer, string $code): array
{
    $errors = [];
    $discount = 0.00;

    $stmt = $conn->prepare(
        "SELECT v.*, rr.customer_id
         FROM tbl_vouchers v
         LEFT JOIN reward_redemptions rr ON v.code = rr.card_number
         WHERE v.code = ? AND v.active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $voucher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($voucher) {
        if ((int)$voucher['customer_id'] !== (int)$customer['id']) {
            $errors[] = 'This voucher does not belong to you.';
        }
        if ((int)$voucher['used_count'] >= (int)$voucher['usage_limit'] && (int)$voucher['usage_limit'] > 0) {
            $errors[] = 'This voucher is already claimed.';
        }
        if ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
            $errors[] = 'This voucher has expired.';
        }
        if ($voucher['min_order_amount'] && cart_subtotal() < (float)$voucher['min_order_amount']) {
            $errors[] = 'A minimum spend of PHP ' . number_format($voucher['min_order_amount'], 2) . ' is required to use this voucher.';
        }
        if (!$errors) {
            $total = cart_subtotal(); // delivery fee is always 0.00 (see create_customer_order)
            $discount = ($voucher['discount_type'] ?? '') === 'percent'
                ? round($total * ((float)$voucher['discount_value'] / 100), 2)
                : round((float)$voucher['discount_value'], 2);
            $discount = max(0.00, min($discount, $total));
        }
        return ['errors' => $errors, 'discount' => $discount];
    }

    // Not in tbl_vouchers - give the real reason for in-store redemption codes.
    $stmt = $conn->prepare("SELECT channel FROM reward_redemptions WHERE card_number = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $errors[] = ($row && strtoupper((string)($row['channel'] ?? '')) === 'IN_STORE')
        ? 'This redemption is valid for in-store use only and cannot be applied to online orders.'
        : 'The voucher code entered is invalid or has expired.';
    return ['errors' => $errors, 'discount' => 0.00];
}
