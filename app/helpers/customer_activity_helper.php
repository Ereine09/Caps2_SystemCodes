<?php
/**
 * Customer profile "Activity Timeline" (admin + staff customer_details.php).
 *
 * The customer's journey with the store - joined, orders placed, received, cancelled,
 * return requests and refunds - newest first. Points and rewards are NOT repeated here:
 * they have their own Transaction History and Redemption History tabs.
 */
require_once __DIR__ . '/return_refund_helper.php';

if (!function_exists('customer_activity_sql')) {
    /** UNION of every event; columns: type, order_id, order_number, amount, extra, ts. Bind customer_id 7 times. */
    function customer_activity_sql(): string
    {
        return "
            (SELECT 'joined' AS type, NULL AS order_id, NULL AS order_number, NULL AS amount, NULL AS extra, c.created_at AS ts
               FROM customers c WHERE c.id = ? AND c.created_at IS NOT NULL)
            UNION ALL
            (SELECT 'placed', o.id, o.order_number, o.total, o.fulfillment_type, o.created_at
               FROM tbl_orders o WHERE o.customer_id = ?)
            UNION ALL
            (SELECT 'received', o.id, o.order_number, o.total, o.fulfillment_type,
                    COALESCE((SELECT MAX(d.delivered_at) FROM tbl_delivery d WHERE d.order_id = o.id), o.paid_at, o.updated_at)
               FROM tbl_orders o WHERE o.customer_id = ? AND (o.order_status IN ('completed', 'reviews') OR (o.order_status = 'refunded' AND o.paid_at IS NOT NULL)))
            UNION ALL
            (SELECT 'cancelled', o.id, o.order_number, o.total, NULL, o.updated_at
               FROM tbl_orders o WHERE o.customer_id = ? AND o.order_status = 'cancelled')
            UNION ALL
            (SELECT 'refunded', o.id, o.order_number, COALESCE(o.refund_amount, o.total), NULL, o.refunded_at
               FROM tbl_orders o WHERE o.customer_id = ? AND o.refunded_at IS NOT NULL)
            UNION ALL
            (SELECT 'return', r.order_id, o.order_number, NULL, r.reason, r.created_at
               FROM tbl_return_requests r JOIN tbl_orders o ON o.id = r.order_id WHERE r.customer_id = ?)
            UNION ALL
            (SELECT 'refunded', r.order_id, o.order_number, r.refund_amount, NULL, r.completed_at
               FROM tbl_return_requests r JOIN tbl_orders o ON o.id = r.order_id
              WHERE r.customer_id = ? AND r.status = 'refunded' AND r.order_item_id IS NOT NULL)";
    }
}

if (!function_exists('customer_activity_count')) {
    function customer_activity_count(mysqli $conn, int $customer_id): int
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM (' . customer_activity_sql() . ') x WHERE x.ts IS NOT NULL');
        $stmt->bind_param('iiiiiii', $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}

if (!function_exists('customer_activity_fetch')) {
    function customer_activity_fetch(mysqli $conn, int $customer_id, int $limit, int $offset = 0): array
    {
        rr_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT * FROM (' . customer_activity_sql() . ') x WHERE x.ts IS NOT NULL ORDER BY x.ts DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('iiiiiiiii', $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $limit, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('customer_activity_html')) {
    /** The timeline list; $order_url is the order details page (…/order_details.php) for this portal. */
    function customer_activity_html(array $rows, string $order_url): string
    {
        $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        if (!$rows) {
            return '<p style="padding: 20px; text-align: center; color: #7f8c8d;">No activity yet.</p>';
        }
        $kinds = [
            'joined'    => ['fa-user-plus',     '#4a3e94', 'Created an account'],
            'placed'    => ['fa-shopping-cart', '#3498db', 'Placed order'],
            'received'  => ['fa-box-open',      '#27ae60', 'Received order'],
            'cancelled' => ['fa-times-circle',  '#e74c3c', 'Cancelled order'],
            'return'    => ['fa-undo-alt',      '#f39c12', 'Asked to return order'],
            'refunded'  => ['fa-hand-holding-usd', '#8e44ad', 'Refunded order'],
        ];
        $out = '<ul class="ca-list">';
        foreach ($rows as $r) {
            [$icon, $color, $text] = $kinds[$r['type']] ?? ['fa-circle', '#95a5a6', ucfirst((string) $r['type'])];
            $line = $h($text);
            if ($r['order_id']) {
                $line .= ' <a target="_top" href="' . $h($order_url . '?id=' . (int) $r['order_id']) . '">#' . $h($r['order_number'] ?: $r['order_id']) . '</a>';
            }
            $sub = [];
            if ($r['type'] === 'placed' || $r['type'] === 'received') {
                $sub[] = 'PHP ' . number_format((float) $r['amount'], 2);
                $sub[] = ($r['extra'] ?? '') === 'pickup' ? 'Store pickup' : 'Delivery';
            } elseif ($r['type'] === 'refunded') {
                $sub[] = 'PHP ' . number_format((float) $r['amount'], 2) . ' returned to the customer';
            } elseif ($r['type'] === 'return') {
                $sub[] = rr_reason_label((string) $r['extra']);
            }
            $out .= '<li><span class="ca-icon" style="color: ' . $color . '; background: ' . $color . '1a;"><i class="fas ' . $icon . '"></i></span>'
                . '<div class="ca-body"><div class="ca-line">' . $line . '</div>'
                . ($sub ? '<div class="ca-sub">' . $h(implode(' · ', $sub)) . '</div>' : '') . '</div>'
                . '<time class="ca-time" datetime="' . $h(date('c', strtotime((string) $r['ts']))) . '">' . $h(date('M j, Y', strtotime((string) $r['ts']))) . '<small>' . $h(date('g:i A', strtotime((string) $r['ts']))) . '</small></time></li>';
        }
        return $out . '</ul>';
    }
}

if (!function_exists('customer_activity_styles')) {
    function customer_activity_styles(): string
    {
        return '<style>
            .ca-list { list-style: none; margin: 15px 0 0; padding: 0; position: relative; }
            .ca-list::before { content: ""; position: absolute; left: 17px; top: 8px; bottom: 8px; width: 2px; background: rgba(148,163,184,.3); }
            .ca-list li { position: relative; display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; }
            .ca-icon { position: relative; z-index: 1; width: 36px; height: 36px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .9rem; box-shadow: 0 0 0 4px var(--surface, #fff); background-color: var(--surface, #fff); }
            .ca-body { flex: 1; min-width: 0; padding-top: 2px; }
            .ca-line { font-weight: 600; font-size: .92rem; }
            .ca-line a { color: var(--dk-primary, #4a3e94); text-decoration: none; }
            .ca-line a:hover { text-decoration: underline; }
            .ca-sub { margin-top: 2px; font-size: .8rem; color: #7f8c8d; }
            .ca-time { flex-shrink: 0; text-align: right; font-size: .8rem; color: #64748b; padding-top: 2px; }
            .ca-time small { display: block; color: #95a5a6; }
            body.dark-mode .ca-line a { color: var(--dm-link, #a5b4fc); }
            body.dark-mode .ca-icon { box-shadow: 0 0 0 4px var(--dm-surface, #1c222b); }
        </style>';
    }
}
