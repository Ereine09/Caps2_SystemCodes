<?php
/**
 * Shared checks for the two-step checkout:
 *   step 1  customer/checkout.php  - fulfillment, address, voucher  -> saves a draft in the session
 *   step 2  customer/payment.php   - payment method + proof         -> places the order
 * Both steps run the same stock/voucher checks so they can't drift apart.
 */

/** Returns error strings for cart lines that are expired or ask for more than the available stock. */
function checkout_check_stock(array $cart): array
{
    $errors = [];
    foreach ($cart as $item) {
        $product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $variant_id = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
        $requested = (int) ($item['quantity'] ?? 0);
        $product = get_product_by_id($product_id);
        if ($product && product_expiry_status($product['expiry_date'])['state'] === 'expired') {
            $errors[] = htmlspecialchars($item['name']) . ' has expired and can no longer be ordered. Please remove it from your cart.';
            continue;
        }
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
function checkout_validate_voucher(mysqli $conn, array $customer, string $code, ?string $fulfillment = null, ?float $delivery_fee = null): array
{
    $fs_owner = 'NULL';
    if (is_file(__DIR__ . '/../../app/helpers/free_shipping_helper.php')) {
        require_once __DIR__ . '/../../app/helpers/free_shipping_helper.php';
        free_shipping_ensure_schema($conn); // tbl_vouchers.owner_customer_id + the 'free_shipping' type
        $fs_owner = 'v.owner_customer_id';
    }
    promo_ensure_schema($conn); // promo_kind / per_customer_limit (admin voucher promos)
    $errors = [];
    $discount = 0.00;

    // Owner: the Redeem-a-Reward redemption, or (Free Shipping vouchers) tbl_vouchers.owner_customer_id.
    $stmt = $conn->prepare(
        "SELECT v.*, COALESCE(rr.customer_id, $fs_owner) AS customer_id
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
        $is_public = ($voucher['promo_kind'] ?? '') === 'public'; // admin promo code anyone can use
        if (!$is_public && (int)$voucher['customer_id'] !== (int)$customer['id']) {
            $errors[] = 'This voucher does not belong to you.';
        }
        if ($is_public && (int)($voucher['per_customer_limit'] ?? 0) > 0
            && voucher_customer_use_count($conn, (string)$voucher['code'], (int)$customer['id']) >= (int)$voucher['per_customer_limit']) {
            $errors[] = 'You have already used this promo code the maximum number of times.';
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
        $free_ship = ($voucher['discount_type'] ?? '') === 'free_shipping';
        if ($free_ship && $fulfillment === 'pickup') {
            $errors[] = 'A Free Shipping voucher can only be used on a delivery order.';
        }
        if ($free_ship && $delivery_fee !== null && $delivery_fee <= 0 && !$errors) {
            $errors[] = 'This order already has free delivery. Keep your Free Shipping voucher for another order.';
        }
        if (!$errors && $free_ship) {
            // Takes the delivery fee off (known on the Payment step; 0 until then).
            return ['errors' => [], 'discount' => round(max(0.00, (float) $delivery_fee), 2), 'free_shipping' => true];
        }
        if (!$errors) {
            $total = cart_subtotal();
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
