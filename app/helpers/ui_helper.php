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

if (!function_exists('ui_paginate')) {
    /**
     * Page math for a list: clamps the requested page into 1..pages.
     * Returns ['page', 'pages', 'per_page', 'offset', 'from', 'to', 'total'].
     */
    function ui_paginate(int $total, int $requested_page, int $per_page = 10): array
    {
        $pages = max(1, (int) ceil($total / max(1, $per_page)));
        $page = min(max(1, $requested_page), $pages);
        $offset = ($page - 1) * $per_page;
        return [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $per_page,
            'offset' => $offset,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $per_page, $total),
            'total' => $total,
        ];
    }
}

if (!function_exists('ui_pagination')) {
    /**
     * Numbered pager (Prev · 1 … 4 5 6 … 12 · Next) in the shared .ui-pagination style.
     * $url_for(int $page): string builds the link for a page number.
     * Renders nothing when everything fits on one page.
     */
    function ui_pagination(int $page, int $pages, callable $url_for, string $label = 'Pagination'): string
    {
        if ($pages <= 1) {
            return '';
        }
        $numbers = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 1) {
                $numbers[] = $i;
            } elseif (end($numbers) !== null) {
                $numbers[] = null;
            }
        }
        $html = '<nav class="ui-pagination" aria-label="' . ui_e($label) . '">';
        $html .= $page > 1
            ? '<a href="' . ui_e($url_for($page - 1)) . '" rel="prev"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="ui-pagination-label">&nbsp;Prev</span></a>'
            : '<span class="disabled" aria-disabled="true"><i class="fas fa-chevron-left" aria-hidden="true"></i><span class="ui-pagination-label">&nbsp;Prev</span></span>';
        foreach ($numbers as $n) {
            if ($n === null) {
                $html .= '<span class="gap" aria-hidden="true">&hellip;</span>';
            } elseif ($n === $page) {
                $html .= '<span class="active" aria-current="page">' . $n . '</span>';
            } else {
                $html .= '<a href="' . ui_e($url_for($n)) . '">' . $n . '</a>';
            }
        }
        $html .= $page < $pages
            ? '<a href="' . ui_e($url_for($page + 1)) . '" rel="next"><span class="ui-pagination-label">Next&nbsp;</span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>'
            : '<span class="disabled" aria-disabled="true"><span class="ui-pagination-label">Next&nbsp;</span><i class="fas fa-chevron-right" aria-hidden="true"></i></span>';
        return $html . '</nav>';
    }
}

if (!function_exists('ui_page_url')) {
    /** Current URL's query string with some params replaced (null removes one), plus an optional #fragment. */
    function ui_page_url(array $set, string $fragment = ''): string
    {
        $params = $_GET;
        foreach ($set as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        $qs = http_build_query($params);
        return basename($_SERVER['PHP_SELF']) . ($qs !== '' ? '?' . $qs : '') . ($fragment !== '' ? '#' . $fragment : '');
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
