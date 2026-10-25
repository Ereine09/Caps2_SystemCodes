<?php
/**
 * "Return / Refund" tab of the admin and staff Orders pages (orders.php?status=returns):
 * every customer return request (one per returned item) with its own status tabs (?rstatus=), a search and paging.
 * Clicking a row (or its Review / Receive / Inspect / Resolve button) opens a floating window with that request,
 * where staff do every step without leaving the list (assets/js/return-window.js + modules/shared/return_request_api.php,
 * same rules as the order's details page - modules/shared/order_return.php). The order number still opens the full page.
 */
require_once __DIR__ . '/order_return.php';
require_once __DIR__ . '/../../app/helpers/return_refund_helper.php';
require_once __DIR__ . '/../../app/helpers/ui_helper.php';

function return_requests_list(mysqli $conn): string
{
    rr_ensure_schema($conn);
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $tabs = ['pending' => 'Pending Review', 'approved' => 'Approved', 'received' => 'Received', 'inspected' => 'Inspected',
             'refunded' => 'Refunded', 'replaced' => 'Replaced', 'rejected' => 'Rejected', 'all' => 'All'];
    $tab = is_string($_GET['rstatus'] ?? null) && isset($tabs[$_GET['rstatus']]) ? $_GET['rstatus'] : 'pending';
    $q = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 100);

    $counts = array_fill_keys(array_keys($tabs), 0);
    $res = $conn->query("SELECT status, COUNT(*) AS n FROM tbl_return_requests GROUP BY status");
    while ($res && ($row = $res->fetch_assoc())) {
        $counts[$row['status']] = (int) $row['n'];
        $counts['all'] += (int) $row['n'];
    }

    $where = [];
    $types = '';
    $params = [];
    if ($tab !== 'all') {
        $where[] = 'r.status = ?';
        $types .= 's';
        $params[] = $tab;
    }
    if ($q !== '') {
        $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR oi.product_name LIKE ? OR r.id = ?)';
        $like = '%' . ltrim($q, '#') . '%';
        $types .= 'ssssi';
        array_push($params, $like, $like, $like, $like, (int) ltrim($q, '#'));
    }
    $from = " FROM tbl_return_requests r
              JOIN tbl_orders o ON o.id = r.order_id
              LEFT JOIN customers c ON c.id = r.customer_id
              LEFT JOIN tbl_order_items oi ON oi.id = r.order_item_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $count_stmt = $conn->prepare('SELECT COUNT(*) AS n' . $from);
    if ($params) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int) ($count_stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $count_stmt->close();

    $pg = ui_paginate($total, (int) ($_GET['page'] ?? 1), 10);
    $list_stmt = $conn->prepare(
        'SELECT r.*, o.order_number, o.total, o.payment_method, o.fulfillment_type, c.name AS customer_name, c.email AS customer_email, oi.product_name'
        . $from . ' ORDER BY (r.status = \'pending\') DESC, r.created_at DESC LIMIT ? OFFSET ?'
    );
    $list_params = array_merge($params, [$pg['per_page'], $pg['offset']]);
    $list_stmt->bind_param($types . 'ii', ...$list_params);
    $list_stmt->execute();
    $rows = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $list_stmt->close();

    $pay = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];

    $out = '<div class="table-box rrl">';
    $out .= '<div>';

    // Status tabs
    $out .= '<nav class="ui-tabs" aria-label="Return request status">';
    foreach ($tabs as $key => $label) {
        $active = $key === $tab;
        $out .= '<a href="' . $h(ui_page_url(['rstatus' => $key, 'page' => null])) . '" class="' . ($active ? 'active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
            . $h($label) . ($counts[$key] > 0 ? ' <span class="ui-tab-count' . (in_array($key, rr_open_statuses(), true) ? ' rrl-attn' : '') . '">' . $counts[$key] . '</span>' : '') . '</a>';
    }
    $out .= '</nav>';

    // Search
    $out .= '<form method="get" class="rrl-search" role="search">'
        . '<input type="hidden" name="status" value="returns">'
        . '<input type="hidden" name="rstatus" value="' . $h($tab) . '">'
        . '<input type="search" name="q" value="' . $h($q) . '" maxlength="100" placeholder="Search return #, order number, product, customer name or email" aria-label="Search return requests">'
        . '<button type="submit" class="rrl-btn rrl-btn-primary"><i class="fas fa-search"></i> Search</button>'
        . ($q !== '' ? '<a class="rrl-btn" href="' . $h(ui_page_url(['q' => null, 'page' => null])) . '">Clear</a>' : '')
        . '</form>';

    if (!$rows) {
        $out .= '<div class="ui-empty"><i class="fas fa-undo-alt"></i><strong>'
            . ($q !== '' ? 'No requests match your search.' : 'No ' . ($tab === 'all' ? '' : strtolower($tabs[$tab]) . ' ') . 'return requests.')
            . '</strong><p>Customers can ask for a return within ' . RR_WINDOW_DAYS . ' days of receiving their order.</p></div>';
        return $out . '</div></div>';
    }

    $out .= '<div class="ui-table-wrap"><table class="ui-table rrl-table"><thead><tr>'
        . '<th>Return</th><th>Order</th><th>Customer</th><th>Item</th><th>Reason</th><th>Wants</th><th>Requested</th><th>Refund</th><th>Status</th><th></th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        [$label, $tone] = rr_status_label((string) $r['status']);
        $tone = ['warning' => 'warning', 'info' => 'info', 'danger' => 'danger', 'success' => 'success'][$tone] ?? 'neutral';
        $link = 'order_details.php?id=' . (int) $r['order_id'] . '#return-' . (int) $r['id'];
        $legacy = $r['order_item_id'] === null;
        $amount = $r['status'] === 'refunded' ? (float) $r['refund_amount'] : (float) ($legacy ? $r['total'] : $r['requested_amount']);
        $open = in_array($r['status'], rr_open_statuses(), true);
        $next = ['pending' => 'Review', 'approved' => 'Receive', 'received' => 'Inspect', 'inspected' => 'Resolve'][$r['status']] ?? 'View';
        $out .= '<tr class="rrl-row" data-rr-id="' . (int) $r['id'] . '">'
            . '<td data-label="Return">#' . (int) $r['id'] . '</td>'
            . '<td data-label="Order"><a class="rrl-order" href="' . $h($link) . '">#' . $h($r['order_number']) . '</a>'
                . '<small>' . $h($pay[$r['payment_method']] ?? strtoupper((string) $r['payment_method'])) . ' &middot; ' . (($r['fulfillment_type'] ?? '') === 'pickup' ? 'Pickup' : 'Delivery') . '</small></td>'
            . '<td data-label="Customer"><strong>' . $h($r['customer_name'] ?: 'Customer') . '</strong><small>' . $h($r['customer_email']) . '</small></td>'
            . '<td data-label="Item">' . ($legacy ? '<em>Whole order</em>' : '<strong>' . $h($r['product_name'] ?? 'Item') . '</strong><small>&times; ' . (int) $r['quantity'] . ' &middot; PHP ' . number_format((float) $r['unit_price'], 2) . ' each</small>') . '</td>'
            . '<td data-label="Reason"><strong>' . $h(rr_reason_label((string) $r['reason'])) . '</strong>'
                . '<small class="rrl-details" title="' . $h($r['details']) . '">' . $h(mb_strimwidth((string) $r['details'], 0, 70, '…')) . '</small>'
                . (!empty($r['photo_path']) ? '<small><a href="' . $h(BASE_URL . $r['photo_path']) . '" target="_blank" rel="noopener"><i class="fas fa-image"></i> Evidence</a></small>' : '') . '</td>'
            . '<td data-label="Wants">' . $h($legacy ? 'Refund' : (rr_resolutions()[$r['resolution']] ?? $r['resolution'])) . '</td>'
            . '<td data-label="Requested">' . $h(date('M j, Y', strtotime((string) $r['created_at']))) . '<small>' . $h(ui_time_ago((string) $r['created_at'])) . '</small></td>'
            . '<td data-label="Refund">PHP ' . number_format($amount, 2) . ($r['status'] === 'refunded' ? '<small>refunded</small>' : '') . '</td>'
            . '<td data-label="Status"><span class="ui-badge ui-badge-' . $tone . '">' . $h($label) . '</span>'
            . ($r['status'] === 'approved' && !empty($r['pickup_date']) ? '<small>' . (!empty($r['picked_up_at']) ? 'With the rider' : 'Pickup ' . $h(rr_day((string) $r['pickup_date']))) . '</small>' : '')
            . (!$legacy && $r['resolution'] === 'refund' && in_array($r['status'], ['received', 'inspected'], true) && !empty($r['received_at'])
                ? '<small>Release by ' . $h(rr_day(rr_refund_window((string) $r['received_at'])['to'])) . '</small>' . (rr_refund_overdue($r) ? '<span class="ui-badge ui-badge-danger">Refund overdue</span>' : '')
                : '')
            . '</td>'
            . '<td class="rrl-act"><button type="button" class="rrl-btn rrl-open ' . ($open ? 'rrl-btn-primary' : '') . '" data-rr-id="' . (int) $r['id'] . '">' . $next . '</button></td>'
            . '</tr>';
    }
    $out .= '</tbody></table></div>';

    $out .= '<div class="ui-pager-bar"><span class="ui-pager-count">Showing ' . $pg['from'] . '&ndash;' . $pg['to'] . ' of ' . $total . ' request' . ($total === 1 ? '' : 's') . '</span>'
        . ui_pagination($pg['page'], $pg['pages'], static fn(int $p): string => ui_page_url(['page' => $p]), 'Return request pages') . '</div>';

    return $out . '</div></div>' . return_request_window();
}

function return_requests_list_styles(): string
{
    return '<style>
        .rrl .ui-tab-count.rrl-attn { background: var(--danger); color: #fff; }
        .rrl-search { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 16px; }
        .rrl-search input { flex: 1 1 260px; min-width: 0; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; }
        .rrl-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-size: .85rem; font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .rrl-btn:hover { border-color: var(--primary); color: var(--link); }
        .rrl-btn-primary { background: var(--primary); border-color: var(--primary); color: var(--on-primary, #fff); }
        .rrl-btn-primary:hover { background: var(--primary-hover, var(--primary)); color: var(--on-primary, #fff); }
        .rrl-table td { vertical-align: top; }
        .rrl-table td small { display: block; margin-top: 3px; color: var(--muted); font-size: .78rem; }
        .rrl-order { font-weight: 700; color: var(--link); text-decoration: none; overflow-wrap: anywhere; }
        .rrl-order:hover { text-decoration: underline; }
        .rrl-details { max-width: 260px; }
        .rrl-act { text-align: right; }
        .rrl-row { cursor: pointer; }
        .rrl-row:hover td { background: var(--surface-2); }
    </style>' . order_return_styles();
}
