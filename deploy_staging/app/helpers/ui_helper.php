<?php
/**
 * Presentation-only helpers for the shared UI kit (assets/css/ui-kit.css).
 *
 * Nothing here reads or writes data or changes any business rule — these
 * functions only turn values the pages already have into consistent markup
 * (status badges, money, initials) so every portal shows them the same way.
 */

if (!function_exists('ui_e')) {
    function ui_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_peso')) {
    /** Money in the format already used across the system ("PHP 1,234.00"). */
    function ui_peso($amount, int $decimals = 2): string
    {
        return 'PHP ' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('ui_order_status_meta')) {
    /** [label, badge tone, icon] for a tbl_orders.order_status value. */
    function ui_order_status_meta(?string $status): array
    {
        $map = [
            'pending'          => ['Pending', 'warning', 'fa-clock'],
            'confirmed'        => ['Confirmed', 'info', 'fa-check'],
            'processing'       => ['Processing', 'primary', 'fa-box-open'],
            'to_ship'          => ['To Ship', 'accent', 'fa-box'],
            'ready_for_pickup' => ['Ready for Pickup', 'accent', 'fa-store'],
            'out_for_delivery' => ['Out for Delivery', 'info', 'fa-truck'],
            'completed'        => ['Completed', 'success', 'fa-circle-check'],
            'cancelled'        => ['Cancelled', 'danger', 'fa-circle-xmark'],
        ];
        $key = strtolower(trim((string) $status));
        return $map[$key] ?? [ucwords(str_replace('_', ' ', $key ?: 'unknown')), 'neutral', 'fa-circle'];
    }
}

if (!function_exists('ui_order_status_badge')) {
    function ui_order_status_badge(?string $status): string
    {
        [$label, $tone] = ui_order_status_meta($status);
        return '<span class="ui-badge ui-badge-dot ui-badge-' . $tone . '">' . ui_e($label) . '</span>';
    }
}

if (!function_exists('ui_payment_method_label')) {
    function ui_payment_method_label(?string $method): string
    {
        $map = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'pay_at_shop' => 'Pay at Shop'];
        $key = strtolower(trim((string) $method));
        return $map[$key] ?? ucwords(str_replace('_', ' ', $key ?: '—'));
    }
}

if (!function_exists('ui_initials')) {
    function ui_initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';
        $out = mb_substr($first, 0, 1) . mb_substr($last, 0, 1);
        return $out !== '' ? mb_strtoupper($out) : '?';
    }
}

if (!function_exists('ui_time_ago')) {
    function ui_time_ago(?string $datetime): string
    {
        $ts = $datetime ? strtotime($datetime) : false;
        if (!$ts) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', $ts);
    }
}
