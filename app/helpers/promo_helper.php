<?php
/**
 * Promotions (admin: modules/admin/promotions.php)
 *
 *   Sale prices  - a temporary discount on one product (all its sizes/variants): either a percent off
 *                  or a fixed peso amount off, with an optional start and end. Stored on
 *                  tbl_product_inventory (promo_type / promo_value / promo_starts_at / promo_ends_at).
 *                  "Pull" clears those columns and the product goes back to its regular price.
 *                  `price` always stays the REGULAR price - the sale price is worked out when the
 *                  product is shown / added to a cart, so editing a product never saves a sale price.
 *
 *   Voucher promos - rows in tbl_vouchers made by the admin:
 *                  promo_kind = 'public'   one code anyone can type at checkout (total usage_limit,
 *                                          per_customer_limit uses per customer)
 *                  promo_kind = 'assigned' one single-use code per chosen customer (owner_customer_id),
 *                                          grouped by promo_batch so the whole batch can be pulled back
 *                  "Pull" sets active = 0 (unused codes stop working); it can be switched back on.
 */

if (!function_exists('promo_ensure_schema')) {
    function promo_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $product_cols = [
            'promo_type'      => "VARCHAR(10) NULL DEFAULT NULL",
            'promo_value'     => "DECIMAL(10,2) NULL DEFAULT NULL",
            'promo_starts_at' => "DATETIME NULL DEFAULT NULL",
            'promo_ends_at'   => "DATETIME NULL DEFAULT NULL",
        ];
        foreach ($product_cols as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM tbl_product_inventory LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE tbl_product_inventory ADD COLUMN $col $def");
            }
        }

        // owner_customer_id + the 'free_shipping' discount type come from the Free Shipping feature.
        $fs = __DIR__ . '/free_shipping_helper.php';
        if (is_file($fs)) {
            require_once $fs;
            free_shipping_ensure_schema($conn);
        }
        $voucher_cols = [
            'promo_kind'         => "VARCHAR(20) NULL DEFAULT NULL",
            'promo_batch'        => "VARCHAR(40) NULL DEFAULT NULL",
            'per_customer_limit' => "INT NULL DEFAULT NULL",
            'created_by'         => "INT NULL DEFAULT NULL",
        ];
        foreach ($voucher_cols as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM tbl_vouchers LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE tbl_vouchers ADD COLUMN $col $def");
            }
        }
    }
}

/* ---------------------------------------------------------------- sale prices */

if (!function_exists('promo_active_sql')) {
    /** SQL condition: the product (table alias $p) has a sale running right now. */
    function promo_active_sql(string $p = 'p'): string
    {
        return "($p.promo_type IN ('percent','amount') AND $p.promo_value > 0"
            . " AND ($p.promo_starts_at IS NULL OR $p.promo_starts_at <= NOW())"
            . " AND ($p.promo_ends_at IS NULL OR $p.promo_ends_at > NOW()))";
    }
}

if (!function_exists('promo_price_sql')) {
    /** SQL expression: $base (e.g. "COALESCE(v.price, p.price)") with product $p's running sale taken off. */
    function promo_price_sql(string $base, string $p = 'p'): string
    {
        return "(CASE WHEN " . promo_active_sql($p) . " THEN GREATEST(ROUND(IF($p.promo_type = 'percent',"
            . " $base * (1 - $p.promo_value / 100), $base - $p.promo_value), 2), 0) ELSE $base END)";
    }
}

if (!function_exists('promo_state')) {
    /** none | scheduled | active | ended, for a product row that has the promo_* columns. */
    function promo_state(array $product): string
    {
        $type = (string) ($product['promo_type'] ?? '');
        if (!in_array($type, ['percent', 'amount'], true) || (float) ($product['promo_value'] ?? 0) <= 0) {
            return 'none';
        }
        $now = time();
        if (!empty($product['promo_starts_at']) && strtotime($product['promo_starts_at']) > $now) {
            return 'scheduled';
        }
        if (!empty($product['promo_ends_at']) && strtotime($product['promo_ends_at']) <= $now) {
            return 'ended';
        }
        return 'active';
    }
}

if (!function_exists('promo_apply')) {
    /** $base with the product's sale taken off (or $base unchanged when no sale is running). */
    function promo_apply(float $base, array $product): float
    {
        if (promo_state($product) !== 'active') {
            return $base;
        }
        $value = (float) $product['promo_value'];
        $sale = $product['promo_type'] === 'percent' ? $base * (1 - $value / 100) : $base - $value;
        return max(0.0, round($sale, 2));
    }
}

if (!function_exists('promo_label')) {
    /** "15% OFF" / "₱50 OFF", or '' when the product has no sale set. */
    function promo_label(array $product): string
    {
        if (promo_state($product) === 'none') {
            return '';
        }
        $value = (float) $product['promo_value'];
        return $product['promo_type'] === 'percent'
            ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% OFF'
            : '₱' . rtrim(rtrim(number_format($value, 2), '0'), '.') . ' OFF';
    }
}

if (!function_exists('promo_decorate_product')) {
    /**
     * For customer-facing pages: when a sale is running, `price` (and each variant's `price`) becomes
     * the sale price and `regular_price` keeps the original, plus `promo_label` / `promo_ends_at`.
     * Never save a decorated product back to the database.
     */
    function promo_decorate_product(array $product): array
    {
        $active = promo_state($product) === 'active';
        $product['promo_label'] = $active ? promo_label($product) : '';
        $product['regular_price'] = (float) ($product['price'] ?? 0);
        if ($active) {
            $product['price'] = promo_apply((float) $product['price'], $product);
        }
        if (!empty($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as &$v) {
                $v['regular_price'] = (float) ($v['price'] ?? 0);
                if ($active) {
                    $v['price'] = promo_apply((float) $v['price'], $product);
                }
            }
            unset($v);
        }
        return $product;
    }
}

/* ---------------------------------------------------------------- vouchers */

if (!function_exists('voucher_customer_use_count')) {
    /** How many of this customer's orders (not cancelled/refunded) used this voucher code. */
    function voucher_customer_use_count(mysqli $conn, string $code, int $customer_id): int
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM tbl_orders
             WHERE customer_id = ? AND voucher_code = ? AND order_status NOT IN ('cancelled', 'refunded')"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('is', $customer_id, $code);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}

if (!function_exists('voucher_discount_label')) {
    function voucher_discount_label(array $v): string
    {
        $type = (string) ($v['discount_type'] ?? 'fixed');
        if ($type === 'free_shipping') {
            return 'Free delivery';
        }
        $value = (float) ($v['discount_value'] ?? 0);
        return $type === 'percent'
            ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% off'
            : '₱' . number_format($value, 2) . ' off';
    }
}

if (!function_exists('voucher_generate_code')) {
    /** A code that isn't in tbl_vouchers yet, e.g. "PROMO-7KQ2XD". */
    function voucher_generate_code(mysqli $conn, string $prefix = 'PROMO'): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix)) ?: 'PROMO';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 20; $try++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = substr($prefix, 0, 20) . '-' . $suffix;
            $stmt = $conn->prepare("SELECT 1 FROM tbl_vouchers WHERE code = ? LIMIT 1");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $taken = (bool) $stmt->get_result()->fetch_row();
            $stmt->close();
            if (!$taken) {
                return $code;
            }
        }
        return substr($prefix, 0, 20) . '-' . strtoupper(bin2hex(random_bytes(5)));
    }
}
