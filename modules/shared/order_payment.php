<?php
/**
 * Payment section of the admin/staff Order Details pages (shared so both follow the same rules).
 *
 *   order_payment_handle_post() - the payment actions: confirm a manual transfer / cash at the
 *       shop, reject a manual transfer, and "Check with PayMongo" (server-side inquiry).
 *       Online (PayMongo) payments can NOT be confirmed by hand.
 *   order_payment_panel()       - payment status, provider, provider transaction id, amount,
 *       paid at, how it was verified, the buttons that apply, and the payment audit trail.
 */
require_once __DIR__ . '/../../app/helpers/payment_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

/** Handles a payment action POST. Redirects and exits when it handled one; returns false otherwise. */
function order_payment_handle_post(mysqli $conn, array $order, ?array $customer, int $actor_id, string $action): bool
{
    $order_id = (int) $order['id'];
    $back = static function (?string $error = null) use ($order_id) {
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
        }
        header('Location: order_details.php?id=' . $order_id . ($error === null ? '&status_updated=1' : ''));
        exit();
    };

    if ($action === 'approve_payment' || $action === 'confirm_shop_payment') {
        $r = payment_confirm_by_staff($conn, $order_id, $actor_id);
        if (!$r['ok']) {
            $back($r['message']);
        }
        notifications_ensure_schema($conn);
        notifications_create($conn, [
            'customer_id' => (int) $order['customer_id'], 'type' => 'order_confirmed', 'channel' => 'both',
            'title' => 'Payment received',
            'message' => 'We received your payment for order #' . $order['order_number'] . '.' . ($order['order_status'] === 'pending' ? ' Your order is confirmed.' : ''),
            'reference_table' => 'tbl_orders', 'reference_id' => $order_id, 'email_to' => (string) ($customer['email'] ?? ''),
        ]);
        $back();
    }
    if ($action === 'reject_payment') {
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'We could not find your transfer in our account.';
        $r = payment_reject_by_staff($conn, $order_id, $actor_id, $reason);
        if (!$r['ok']) {
            $back($r['message']);
        }
        notifications_ensure_schema($conn);
        notifications_create($conn, [
            'customer_id' => (int) $order['customer_id'], 'type' => 'order_status_update', 'channel' => 'both',
            'title' => 'Order #' . $order['order_number'] . ' cancelled',
            'message' => "Your order #{$order['order_number']} was cancelled because the payment could not be verified.\n\nReason: {$reason}",
            'reference_table' => 'tbl_orders', 'reference_id' => $order_id, 'email_to' => (string) ($customer['email'] ?? ''),
        ]);
        $back();
    }
    if ($action === 'check_provider') {
        $last = payment_latest($conn, 'order', $order_id);
        if (!$last) {
            $back('There is no online payment to check for this order.');
        }
        $r = payment_inquire($conn, (int) $last['id']);
        $back($r['ok'] ? null : $r['message']);
    }
    return false;
}

/** Renders the payment panel. $csrf_html is the page's CSRF field (empty string when the page has none). */
function order_payment_panel(mysqli $conn, array $order, string $csrf_html = ''): string
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $pm = (string) $order['payment_method'];
    $ps = (string) ($order['payment_status'] ?? 'unpaid');
    $provider = (string) ($order['payment_provider'] ?? '');
    $last = payment_latest($conn, 'order', (int) $order['id']);
    $events = payment_events_for_order($conn, (int) $order['id']);
    $tone = ['paid' => 'success', 'pending' => 'info', 'pending_verification' => 'warning', 'review' => 'danger', 'failed' => 'danger', 'expired' => 'neutral', 'cancelled' => 'neutral', 'refunded' => 'neutral'][$ps] ?? 'warning';
    $label = payment_status_label($ps);
    if ($ps === 'unpaid' && $pm === 'cod' && in_array($order['order_status'], ['completed', 'reviews'], true)) {
        $label = 'Collected by rider - awaiting remittance';
    }
    $provider_label = $provider === 'paymongo' ? 'PayMongo (' . ($pm === 'bank' ? 'online banking' : 'GCash') . ')'
        : ($provider === 'manual' ? 'Manual transfer (reference + receipt)' : ($pm === 'cod' ? 'Cash to rider' : ($pm === 'pay_at_shop' ? 'Cash at the shop' : '—')));

    $rows = [
        ['Payment status', '<span class="ui-badge ui-badge-' . $tone . '">' . $h($label) . '</span>'],
        ['Provider', $h($provider_label)],
        ['Amount', 'PHP ' . number_format((float) $order['total'], 2)],
    ];
    if ($last) {
        $rows[] = ['Provider transaction ID', $last['provider_payment_id'] ? '<code>' . $h($last['provider_payment_id']) . '</code>' : '—'];
        if ($last['provider_reference']) {
            $rows[] = ['GCash / bank reference', '<code>' . $h($last['provider_reference']) . '</code>'];
        }
        if ($last['amount_paid'] !== null) {
            $rows[] = ['Amount reported by PayMongo', $h($last['currency']) . ' ' . number_format((float) $last['amount_paid'], 2)];
        }
        $rows[] = ['Environment', $last['environment'] === 'production' ? 'Live' : 'Sandbox (test)'];
        if ($last['failure_reason']) {
            $rows[] = ['Note', $h($last['failure_reason'])];
        }
    }
    if ($ps === 'paid') {
        $rows[] = ['Paid at', $order['paid_at'] ? $h(date('M j, Y g:i A', strtotime($order['paid_at']))) : '—'];
        $rows[] = ['Verification', $h(payment_verified_via_label($order['payment_verified_via'] ?? ''))];
    }

    $out = '<div class="op-panel"><h4 class="op-title"><i class="fas fa-shield-halved"></i> Payment verification</h4><dl class="op-grid">';
    foreach ($rows as [$k, $v]) {
        $out .= '<dt>' . $h($k) . '</dt><dd>' . $v . '</dd>';
    }
    $out .= '</dl>';

    $open = !in_array($order['order_status'], ['cancelled', 'refunded'], true);
    $btn = static fn($action, $label, $class, $confirm = '') =>
        '<form method="POST"' . ($confirm !== '' ? ' onsubmit="return confirm(' . $h(json_encode($confirm)) . ');"' : '') . '>' . $csrf_html
        . '<input type="hidden" name="action" value="' . $action . '"><button type="submit" class="ui-btn ' . $class . ' ui-btn-sm">' . $label . '</button></form>';
    $actions = '';
    if ($provider === 'paymongo' && in_array($ps, ['pending', 'unpaid', 'failed'], true) && $last) {
        $actions .= $btn('check_provider', '<i class="fas fa-rotate"></i> Check with PayMongo', 'ui-btn-primary');
    }
    if ($provider === 'manual' && $ps === 'pending_verification' && $open) {
        $actions .= $btn('approve_payment', '<i class="fas fa-check"></i> Transfer found - mark paid', 'ui-btn-success',
            'Only confirm if you found this exact transfer (reference and amount) in the shop\'s ' . ($pm === 'gcash' ? 'GCash' : 'bank') . ' account. Mark as paid?');
        $actions .= '<form method="POST" onsubmit="return confirm(\'Reject this payment and cancel the order?\');">' . $csrf_html
            . '<input type="hidden" name="action" value="reject_payment"><input type="text" name="reason" placeholder="Reason (the customer will see it)" class="op-reason">'
            . '<button type="submit" class="ui-btn ui-btn-danger ui-btn-sm"><i class="fas fa-times"></i> Not found - reject</button></form>';
    }
    if ($pm === 'pay_at_shop' && $ps !== 'paid' && $open) {
        $actions .= $btn('confirm_shop_payment', '<i class="fas fa-cash-register"></i> Cash received at the shop', 'ui-btn-success',
            'Confirm the customer paid PHP ' . number_format((float) $order['total'], 2) . ' at the shop?');
    }
    if ($provider === 'paymongo' && $ps === 'review') {
        $out .= '<p class="op-hint op-warn">PayMongo reported a different amount than the order total, so it was not marked paid. Check the payment in the PayMongo dashboard and settle it with the customer (e.g. refund and re-pay).</p>';
    } elseif ($provider === 'paymongo' && $ps !== 'paid') {
        $out .= '<p class="op-hint">Online payments are confirmed by PayMongo only (webhook or status check). They cannot be marked paid by hand.</p>';
    } elseif ($pm === 'cod' && $ps !== 'paid') {
        $out .= '<p class="op-hint">COD is marked paid when the rider\'s remittance for this order is verified on the Remittance page.</p>';
    }
    if ($actions !== '') {
        $out .= '<div class="op-actions">' . $actions . '</div>';
    }

    if ($events) {
        $out .= '<details class="op-log"><summary>Payment history (' . count($events) . ')</summary><ul>';
        foreach ($events as $e) {
            $who = $e['actor_name'] ? ' · ' . $h(trim($e['actor_name'])) : '';
            $out .= '<li><time>' . $h(date('M j, Y g:i A', strtotime($e['created_at']))) . '</time> <strong>' . $h(str_replace('_', ' ', $e['event'])) . '</strong>'
                . ($e['amount'] !== null ? ' · PHP ' . number_format((float) $e['amount'], 2) : '')
                . ' · ' . $h(str_replace('_', ' ', $e['source'])) . $who
                . ($e['reference'] ? ' · ref ' . $h($e['reference']) : '')
                . ($e['details'] ? '<br><small>' . $h($e['details']) . '</small>' : '') . '</li>';
        }
        $out .= '</ul></details>';
    }
    $out .= '</div>';
    return $out;
}

function order_payment_styles(): string
{
    return '<style>
        .op-panel { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); }
        .op-title { margin: 0 0 10px; font-size: .95rem; color: var(--text); display: flex; gap: 8px; align-items: center; }
        .op-grid { display: grid; grid-template-columns: minmax(120px, auto) 1fr; gap: 6px 14px; margin: 0; font-size: .87rem; }
        .op-grid dt { color: var(--muted); }
        .op-grid dd { margin: 0; color: var(--text); font-weight: 600; overflow-wrap: anywhere; }
        .op-grid code { font-size: .82rem; }
        .op-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .op-actions form { display: flex; gap: 6px; flex-wrap: wrap; margin: 0; }
        .op-reason { padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; font-size: .85rem; min-width: 200px; }
        .op-hint { margin: 10px 0 0; font-size: .82rem; color: var(--muted); }
        .op-warn { color: var(--danger); }
        .op-log { margin-top: 12px; font-size: .82rem; }
        .op-log summary { cursor: pointer; color: var(--link); font-weight: 600; }
        .op-log ul { margin: 8px 0 0; padding-left: 18px; color: var(--text); }
        .op-log li { margin-bottom: 6px; }
        .op-log time, .op-log small { color: var(--muted); }
    </style>';
}
