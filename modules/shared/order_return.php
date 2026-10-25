<?php
/**
 * Return / Refund section of the admin/staff Order Details pages (shared so both follow the same rules).
 *
 *   order_return_handle_post() - rr_approve, rr_reject, rr_receive, rr_inspect, rr_refund, rr_replace
 *                                on one of the order's return requests (request_id).
 *   order_return_panel()       - every request on the order with the buttons its current step allows.
 *
 * Workflow, stock and refund rules live in app/helpers/return_refund_helper.php (rr_process()).
 */
require_once __DIR__ . '/../../app/helpers/return_refund_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

/** Handles a return action POST. Redirects and exits when it handled one; returns false otherwise. */
function order_return_handle_post(mysqli $conn, array $order, ?array $customer, int $actor_id, string $action): bool
{
    $map = ['rr_approve' => 'approve', 'rr_reject' => 'reject', 'rr_receive' => 'receive', 'rr_inspect' => 'inspect', 'rr_refund' => 'refund', 'rr_replace' => 'replace'];
    if (!isset($map[$action])) {
        return false;
    }
    csrf_require(); // the return forms always carry the token, on the admin and the staff page
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $result = rr_process($conn, $order, $request_id, $map[$action], $actor_id, [
        'note'            => (string) ($_POST['staff_note'] ?? ''),
        'condition'       => (string) ($_POST['item_condition'] ?? ''),
        'inspection_note' => (string) ($_POST['inspection_note'] ?? ''),
    ]);
    if (!$result['ok']) {
        $_SESSION['error_message'] = $result['message'];
    }
    header('Location: order_details.php?id=' . (int) $order['id'] . ($result['ok'] ? '&status_updated=1' : '') . '#return-' . $request_id);
    exit();
}

/**
 * Renders the return requests card, or '' when the order has none. $csrf_html is the page's CSRF field.
 * $only_request_id > 0 shows just that request (the floating window on the Return / Refund tab).
 */
function order_return_panel(mysqli $conn, array $order, string $csrf_html = '', int $only_request_id = 0): string
{
    $requests = rr_get_for_order($conn, (int) $order['id']);
    if ($only_request_id > 0) {
        $requests = array_values(array_filter($requests, static fn($r) => (int) $r['id'] === $only_request_id));
    }
    if (!$requests) {
        return '';
    }
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $peso = static fn($v) => 'PHP ' . number_format((float) $v, 2);
    $conditions = rr_conditions();

    $out = '<div class="info-card or-card" id="return-request">';
    if ($only_request_id <= 0) {
        $out .= '<h4><i class="fas fa-undo-alt"></i> Returns &amp; Refunds <small class="or-count">' . count($requests) . ' request' . (count($requests) === 1 ? '' : 's') . '</small></h4>';
    }
    $out .= '<p class="or-hint">The order keeps its own status. Stock only changes when a returned item is inspected: <strong>resellable</strong> items go back to available stock, damaged / expired / non-resellable ones do not.</p>';

    foreach ($requests as $r) {
        $rid = (int) $r['id'];
        $status = (string) $r['status'];
        [$label, $tone] = rr_status_label($status);
        $legacy = $r['order_item_id'] === null;

        $out .= '<section class="or-req" id="return-' . $rid . '">';
        $out .= '<div class="or-req-head"><strong>Return #' . $rid . '</strong>'
            . ($legacy ? '<span class="or-muted">Whole order (older request)</span>' : '<span>' . $h($r['product_name'] ?? 'Item') . ' &times; ' . (int) $r['quantity'] . '</span>')
            . '<span class="or-pill or-' . $h($tone) . '">' . $h($label) . '</span></div>';

        // Workflow steps
        $steps = ['pending' => 'Review', 'approved' => 'Approved', 'received' => 'Received', 'inspected' => 'Inspected', 'done' => $status === 'replaced' ? 'Replaced' : 'Refunded'];
        if (!$legacy && $status !== 'rejected') {
            $order_idx = array_search(in_array($status, ['refunded', 'replaced'], true) ? 'done' : $status, array_keys($steps), true);
            $out .= '<ol class="or-steps">';
            $i = 0;
            foreach ($steps as $text) {
                $out .= '<li class="' . ($i < $order_idx || in_array($status, ['refunded', 'replaced'], true) ? 'done' : ($i === $order_idx ? 'now' : '')) . '">' . $h($text) . '</li>';
                $i++;
            }
            $out .= '</ol>';
        }

        $out .= '<dl class="or-grid">';
        $out .= '<dt>Requested</dt><dd>' . $h(date('M j, Y g:i A', strtotime((string) $r['created_at']))) . '</dd>';
        if (!$legacy) {
            $out .= '<dt>Item price</dt><dd>' . $peso($r['unit_price']) . ' each &middot; bought ' . (int) $r['purchased_qty'] . ', returning ' . (int) $r['quantity'] . '</dd>';
            $out .= '<dt>Refund amount</dt><dd>' . $peso($status === 'refunded' ? $r['refund_amount'] : $r['requested_amount']) . ' <small class="or-muted">(' . ($status === 'refunded' ? 'refunded' : 'computed by the system') . ')</small></dd>';
            $out .= '<dt>Customer wants</dt><dd>' . $h(rr_resolutions()[$r['resolution']] ?? $r['resolution']) . '</dd>';
        }
        $out .= '<dt>Reason</dt><dd>' . $h(rr_reason_label((string) $r['reason'])) . '</dd>';
        $out .= '<dt>Description</dt><dd>' . nl2br($h($r['details'])) . '</dd>';
        if (!empty($r['photo_path'])) {
            $src = BASE_URL . $r['photo_path'];
            $out .= '<dt>Evidence</dt><dd><a href="' . $h($src) . '" target="_blank" rel="noopener"><img class="or-photo" src="' . $h($src) . '" alt="Photo from the customer"></a></dd>';
        }
        if (!empty($r['pickup_date'])) {
            $rider = $r['pickup_rider_name'] ?? '';
            $pickup = rr_day((string) $r['pickup_date']) . ' &middot; free';
            if (!empty($r['picked_up_at'])) {
                $pickup .= ' &middot; picked up by ' . $h($rider ?: 'a rider') . ' ' . $h(date('M j, g:i A', strtotime((string) $r['picked_up_at'])));
            } elseif ($rider !== '' && $status === 'approved') {
                $pickup .= ' &middot; claimed by ' . $h($rider) . ', not collected yet';
            } elseif ($status === 'approved') {
                $pickup .= ' &middot; <span class="or-muted">waiting for a rider to claim it</span>';
            }
            $out .= '<dt>Rider pickup</dt><dd>' . $pickup . '</dd>';
        }
        if (!empty($r['received_at'])) {
            $out .= '<dt>Received</dt><dd>' . $h(date('M j, Y g:i A', strtotime((string) $r['received_at']))) . '</dd>';
            if ($r['resolution'] === 'refund' && in_array($status, ['received', 'inspected'], true)) {
                $win = rr_refund_window((string) $r['received_at']);
                $out .= '<dt>Release refund</dt><dd>' . $h(rr_day($win['from'])) . ' &ndash; ' . $h(rr_day($win['to']))
                    . ' <small class="or-muted">(' . RR_REFUND_MIN_DAYS . '-' . RR_REFUND_MAX_DAYS . ' business days, Saturdays not counted)</small>'
                    . (rr_refund_overdue($r) ? ' <span class="or-pill or-danger" style="margin-left:6px;">Overdue</span>' : '') . '</dd>';
            }
        }
        if (!empty($r['item_condition'])) {
            $c = $conditions[$r['item_condition']] ?? [$r['item_condition'], false];
            $out .= '<dt>Condition</dt><dd>' . $h($c[0]) . ' &middot; ' . ($c[1] ? 'returned to available stock' : 'not returned to stock') . '</dd>';
            if (!empty($r['inspection_note'])) {
                $out .= '<dt>Inspection</dt><dd>' . nl2br($h($r['inspection_note'])) . '</dd>';
            }
        }
        if (!empty($r['staff_note'])) {
            $out .= '<dt>Staff note</dt><dd>' . nl2br($h($r['staff_note'])) . '</dd>';
        }
        if ($status === 'refunded' && (float) $r['points_reversed'] > 0) {
            $out .= '<dt>Points</dt><dd>-' . number_format((float) $r['points_reversed'], 2) . ' reversed</dd>';
        }
        if ($legacy && $status === 'refunded') {
            $out .= '<dt>Refunded</dt><dd>' . $peso($r['refund_amount']) . ((int) $r['restocked'] === 1 ? ' &middot; items put back in stock' : '') . '</dd>';
        }
        $moves = $legacy ? [] : rr_movements($conn, $rid);
        if ($moves) {
            $out .= '<dt>Stock</dt><dd><ul class="or-moves">';
            foreach ($moves as $m) {
                $chg = (int) $m['stock_change'];
                $out .= '<li><code>' . $h($m['movement_type']) . '</code> qty ' . (int) $m['quantity'] . ' &middot; available ' . ($chg > 0 ? '+' : '') . $chg
                    . ' <small class="or-muted">' . $h(date('M j, g:i A', strtotime((string) $m['created_at']))) . '</small></li>';
            }
            $out .= '</ul></dd>';
        }
        $out .= '</dl>';

        if ($legacy) {
            $out .= '</section>';
            continue;
        }

        $out .= order_return_actions($conn, $order, $r, $csrf_html);
        $out .= '</section>';
    }
    return $out . '</div>';
}

/** The buttons/forms for a request's current step (approve, reject, receive, inspect, refund, replacement). */
function order_return_actions(mysqli $conn, array $order, array $r, string $csrf_html): string
{
    if ($r['order_item_id'] === null) {
        return ''; // older whole-order requests: view only
    }
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $peso = static fn($v) => 'PHP ' . number_format((float) $v, 2);
    $conditions = rr_conditions();
    $rid = (int) $r['id'];
    $status = (string) $r['status'];
    $form = static fn(string $inner, string $confirm = '') => '<form method="POST" class="or-form"' . ($confirm !== '' ? ' onsubmit="return confirm(' . $h(json_encode($confirm)) . ');"' : '') . '>'
        . $csrf_html . '<input type="hidden" name="request_id" value="' . $rid . '">' . $inner . '</form>';
    $out = '';
    $note_field = static fn(string $id, string $label, string $ph) => '<label for="' . $id . '">' . $label . '</label>'
        . '<textarea id="' . $id . '" name="staff_note" maxlength="1000" rows="2" placeholder="' . $h($ph) . '"></textarea>';

    if ($status === 'pending') {
        $nid = 'or_note_' . $rid;
        $out .= $form($note_field($nid, 'Note to the customer <small>(required to reject)</small>', 'e.g. Please pack the item with its receipt')
            . '<div class="or-actions">'
            . '<button type="submit" name="action" value="rr_approve" class="button or-approve"><i class="fas fa-check"></i> Approve Return</button>'
            . '<button type="submit" name="action" value="rr_reject" class="button or-reject" onclick="if(!document.getElementById(\'' . $nid . '\').value.trim()){alert(\'Please write the reason for rejecting.\');return false;}return confirm(\'Reject this return request?\');"><i class="fas fa-times"></i> Reject</button>'
            . '</div>');
    } elseif ($status === 'approved') {
        $nid = 'or_note_' . $rid;
        $out .= $form('<p class="or-hint">' . (!empty($r['pickup_date'])
                ? 'A rider collects the item on ' . $h(rr_day((string) $r['pickup_date'])) . ' and it is marked received automatically when they drop it off. If the customer brought it in themselves, mark it received here.'
                : 'Mark the item as received once it is physically back in the shop.') . ' This does <strong>not</strong> change stock.</p>'
            . $note_field($nid, 'Note <small>(required to reject)</small>', 'e.g. Customer did not send the item back')
            . '<div class="or-actions">'
            . '<button type="submit" name="action" value="rr_receive" class="button or-approve"><i class="fas fa-box-open"></i> Mark Item as Received</button>'
            . '<button type="submit" name="action" value="rr_reject" class="button or-reject" onclick="if(!document.getElementById(\'' . $nid . '\').value.trim()){alert(\'Please write the reason for rejecting.\');return false;}return confirm(\'Reject this return request?\');"><i class="fas fa-times"></i> Reject</button>'
            . '</div>');
    } elseif ($status === 'received') {
        $radios = '';
        foreach ($conditions as $key => [$text, $sellable]) {
            $radios .= '<label class="or-check"><input type="radio" name="item_condition" value="' . $h($key) . '" required> ' . $h($text)
                . ' <small class="or-muted">' . ($sellable ? 'stock +' . (int) $r['quantity'] : 'stock +0') . '</small></label>';
        }
        $out .= $form('<input type="hidden" name="action" value="rr_inspect">'
            . '<label>Condition of the returned item</label>' . $radios
            . '<label for="or_insp_' . $rid . '">Inspection notes <small>(optional)</small></label>'
            . '<textarea id="or_insp_' . $rid . '" name="inspection_note" maxlength="1000" rows="2" placeholder="e.g. Sealed and unused / bag torn / past expiry date"></textarea>'
            . '<div class="or-actions"><button type="submit" class="button or-approve"><i class="fas fa-clipboard-check"></i> Save Inspection</button></div>',
            'Save this inspection? Stock is updated from the condition you chose and this cannot be undone.');
    } elseif ($status === 'inspected') {
        $prefers = $r['resolution'];
        $out .= $form($note_field('or_note_' . $rid, 'Note to the customer <small>(optional, e.g. how the money was sent)</small>', 'e.g. Sent via GCash to 09xx...')
            . '<p class="or-hint">Refund: <strong>' . $peso(rr_compute_refund($conn, $order, (int) $r['order_item_id'], (int) $r['quantity'], $rid)) . '</strong> (computed from the order). '
            . 'Replacement: takes ' . (int) $r['quantity'] . ' from stock. The customer asked for a <strong>' . $h(strtolower(rr_resolutions()[$prefers] ?? $prefers)) . '</strong>.</p>'
            . '<div class="or-actions">'
            . '<button type="submit" name="action" value="rr_refund" class="button ' . ($prefers === 'refund' ? 'or-approve' : 'or-secondary') . '" onclick="return confirm(\'Process this refund? This cannot be undone.\');"><i class="fas fa-peso-sign"></i> Process Refund</button>'
            . '<button type="submit" name="action" value="rr_replace" class="button ' . ($prefers === 'replacement' ? 'or-approve' : 'or-secondary') . '" onclick="return confirm(\'Send a replacement? ' . (int) $r['quantity'] . ' will be taken from stock.\');"><i class="fas fa-exchange-alt"></i> Process Replacement</button>'
            . '</div>');
    }
    return $out;
}

/**
 * The floating window's content for one request: who/what, a step tracker, the customer's request next to
 * the handling details, and the actions for the current step. ($top = the order summary strip.)
 */
function order_return_card(mysqli $conn, array $order, int $request_id, string $csrf_html, string $top = ''): string
{
    $r = null;
    foreach (rr_get_for_order($conn, (int) $order['id']) as $row) {
        if ((int) $row['id'] === $request_id) {
            $r = $row;
        }
    }
    if (!$r) {
        return '<p class="or-muted">This return request was not found.</p>';
    }
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $peso = static fn($v) => 'PHP ' . number_format((float) $v, 2);
    $status = (string) $r['status'];
    [$label, $tone] = rr_status_label($status);
    $legacy = $r['order_item_id'] === null;
    $done = in_array($status, ['refunded', 'replaced'], true);

    $img = '';
    if (!empty($r['product_id'])) {
        $st = $conn->prepare('SELECT image_url FROM tbl_product_inventory WHERE id = ?');
        $pid = (int) $r['product_id'];
        $st->bind_param('i', $pid);
        $st->execute();
        $img = (string) ($st->get_result()->fetch_assoc()['image_url'] ?? '');
        $st->close();
    }
    $name = $legacy ? 'Whole order' : (string) ($r['product_name'] ?? 'Item');
    $amount = $status === 'refunded' ? (float) $r['refund_amount'] : (float) ($legacy ? $order['total'] : $r['requested_amount']);

    $out = '<div class="rw">' . $top;

    // Hero: the item, the status and the money at stake
    $out .= '<div class="rw-hero">'
        . ($img !== '' ? '<img class="rw-thumb" src="' . $h($img) . '" alt="">' : '<span class="rw-thumb rw-thumb-empty"><i class="fas fa-box"></i></span>')
        . '<div class="rw-hero-main"><span class="rw-kicker">Return #' . (int) $r['id'] . ' &middot; ' . $h(date('M j, Y g:i A', strtotime((string) $r['created_at']))) . '</span>'
        . '<strong class="rw-name">' . $h($name) . '</strong>'
        . (!$legacy ? '<span class="rw-sub">Returning ' . (int) $r['quantity'] . ' of ' . (int) $r['purchased_qty'] . ' &middot; ' . $peso($r['unit_price']) . ' each</span>' : '')
        . '</div>'
        . '<div class="rw-hero-side"><span class="or-pill or-' . $h($tone) . '">' . $h($label) . '</span>'
        . '<strong class="rw-amount">' . $peso($amount) . '</strong><small>' . ($status === 'refunded' ? 'refunded' : ($legacy ? 'order total' : 'refund amount')) . '</small></div>'
        . '</div>';

    // Step tracker
    if (!$legacy && $status !== 'rejected') {
        $steps = ['pending' => 'Review', 'approved' => 'Approved', 'received' => 'Received', 'inspected' => 'Inspected', 'done' => $status === 'replaced' ? 'Replaced' : 'Refunded'];
        $now = array_search($done ? 'done' : $status, array_keys($steps), true);
        $out .= '<ol class="rw-steps">';
        $i = 0;
        foreach ($steps as $text) {
            $cls = ($i < $now || $done) ? 'done' : ($i === $now ? 'now' : '');
            $out .= '<li class="' . $cls . '"><span class="rw-dot">' . ($cls === 'done' ? '<i class="fas fa-check"></i>' : $i + 1) . '</span><span class="rw-step-label">' . $h($text) . '</span></li>';
            $i++;
        }
        $out .= '</ol>';
    } elseif ($status === 'rejected') {
        $out .= '<div class="rw-banner is-bad"><i class="fas fa-ban"></i> This request was rejected' . (!empty($r['staff_note']) ? ': ' . $h($r['staff_note']) : '.') . '</div>';
    }

    // Two boxes: what the customer asked for / how it is being handled
    $want = $legacy ? 'Refund' : (rr_resolutions()[$r['resolution']] ?? $r['resolution']);
    $out .= '<div class="rw-grid">';
    $out .= '<section class="rw-box"><h5><i class="fas fa-user"></i> Customer\'s request</h5>'
        . '<div class="rw-tags"><span class="rw-tag"><i class="fas ' . ($want === 'Replacement' ? 'fa-exchange-alt' : 'fa-peso-sign') . '"></i> Wants ' . $h(strtolower($want)) . '</span>'
        . '<span class="rw-tag">' . $h(rr_reason_label((string) $r['reason'])) . '</span></div>'
        . '<blockquote class="rw-quote">' . nl2br($h($r['details'])) . '</blockquote>';
    if (!empty($r['photo_path'])) {
        $src = BASE_URL . $r['photo_path'];
        $out .= '<a class="rw-photo" href="' . $h($src) . '" target="_blank" rel="noopener" title="Open the full photo"><img src="' . $h($src) . '" alt="Photo from the customer"><span><i class="fas fa-search-plus"></i> View photo</span></a>';
    }
    $out .= '</section>';

    $facts = [];
    if (!empty($r['pickup_date'])) {
        $rider = $r['pickup_rider_name'] ?? '';
        $p = rr_day((string) $r['pickup_date']) . ' &middot; free';
        if (!empty($r['picked_up_at'])) {
            $p .= '<small>Picked up by ' . $h($rider ?: 'a rider') . ', ' . $h(date('M j, g:i A', strtotime((string) $r['picked_up_at']))) . '</small>';
        } elseif ($rider !== '' && $status === 'approved') {
            $p .= '<small>Claimed by ' . $h($rider) . ', not collected yet</small>';
        } elseif ($status === 'approved') {
            $p .= '<small>Waiting for a rider to claim it</small>';
        }
        $facts[] = ['fa-motorcycle', 'Rider pickup', $p];
    }
    if (!empty($r['received_at'])) {
        $facts[] = ['fa-box-open', 'Received', $h(date('M j, Y g:i A', strtotime((string) $r['received_at'])))];
        if ($r['resolution'] === 'refund' && in_array($status, ['received', 'inspected'], true)) {
            $win = rr_refund_window((string) $r['received_at']);
            $facts[] = ['fa-calendar-check', 'Release refund', $h(rr_day($win['from'])) . ' &ndash; ' . $h(rr_day($win['to']))
                . (rr_refund_overdue($r) ? ' <span class="or-pill or-danger">Overdue</span>' : '')
                . '<small>' . RR_REFUND_MIN_DAYS . '-' . RR_REFUND_MAX_DAYS . ' business days, Saturdays not counted</small>'];
        }
    }
    if (!empty($r['item_condition'])) {
        $c = rr_conditions()[$r['item_condition']] ?? [$r['item_condition'], false];
        $facts[] = ['fa-clipboard-check', 'Condition', $h($c[0]) . '<small>' . ($c[1] ? 'Returned to available stock' : 'Not returned to stock') . (!empty($r['inspection_note']) ? ' &middot; ' . $h($r['inspection_note']) : '') . '</small>'];
    }
    if (!empty($r['staff_note']) && $status !== 'rejected') {
        $facts[] = ['fa-comment', 'Staff note', nl2br($h($r['staff_note']))];
    }
    if ($status === 'refunded' && (float) $r['points_reversed'] > 0) {
        $facts[] = ['fa-star', 'Points', '-' . number_format((float) $r['points_reversed'], 2) . ' reversed'];
    }
    if (!$legacy) {
        foreach (rr_movements($conn, (int) $r['id']) as $m) {
            $chg = (int) $m['stock_change'];
            $facts[] = ['fa-boxes-stacked', 'Stock', $h(str_replace('_', ' ', (string) $m['movement_type'])) . ' &middot; qty ' . (int) $m['quantity'] . ', available ' . ($chg > 0 ? '+' : '') . $chg
                . '<small>' . $h(date('M j, g:i A', strtotime((string) $m['created_at']))) . '</small>'];
        }
    }
    $out .= '<section class="rw-box"><h5><i class="fas fa-clipboard-list"></i> Handling</h5>';
    if ($facts) {
        $out .= '<ul class="rw-facts">';
        foreach ($facts as [$icon, $k, $v]) {
            $out .= '<li><i class="fas ' . $icon . '"></i><div><span>' . $h($k) . '</span><strong>' . $v . '</strong></div></li>';
        }
        $out .= '</ul>';
    } else {
        $out .= '<p class="rw-empty">Nothing handled yet. Review the request first.</p>';
    }
    $out .= '</section>';

    // The current step's actions: a third column beside the request and the handling (landscape window)
    $actions = order_return_actions($conn, $order, $r, $csrf_html);
    if ($actions !== '') {
        $next = ['pending' => 'Review this request', 'approved' => 'Wait for the item, then mark it received', 'received' => 'Inspect the returned item', 'inspected' => 'Send the refund or a replacement'][$status] ?? 'Next step';
        $out .= '<div class="rw-action"><h5><i class="fas fa-arrow-right"></i> Next step: ' . $h($next) . '</h5>' . $actions . '</div>';
    }
    $out .= '</div>';
    if ($actions === '' && $done) {
        $out .= '<div class="rw-banner is-good"><i class="fas fa-circle-check"></i> Completed: ' . ($status === 'refunded' ? 'refund sent.' : 'replacement sent.') . '</div>';
    }
    $out .= '<p class="rw-foot"><i class="fas fa-circle-info"></i> Stock changes only at inspection: resellable items go back to stock; damaged, expired or opened ones do not. The order keeps its own status.</p>';
    return $out . '</div>';
}

/** The floating window (behaviour: assets/js/return-window.js). */
function return_request_window(): string
{
    $js = BASE_URL . '/assets/js/return-window.js?v=' . (@filemtime(__DIR__ . '/../../assets/js/return-window.js') ?: 1);
    return '<div class="rrm" id="rrModal" hidden>'
        . '<div class="rrm-card" role="dialog" aria-modal="true" aria-labelledby="rrmTitle">'
        . '<div class="rrm-head"><h3 id="rrmTitle">Return</h3><button type="button" class="rrm-x" aria-label="Close" data-rrm-close><i class="fas fa-times"></i></button></div>'
        . '<div class="rrm-msg" id="rrmMsg" hidden></div>'
        . '<div class="rrm-body" id="rrmBody"></div>'
        . '</div></div>'
        . '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '" defer></script>';
}

/**
 * Order Details: a short summary of the order's return requests (item, status, Open). Open shows the request
 * in the floating window (same window as the Orders page's Return / Refund tab), where every step is done.
 */
function order_return_summary(mysqli $conn, array $order): string
{
    $requests = rr_get_for_order($conn, (int) $order['id']);
    if (!$requests) {
        return '';
    }
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $out = '<div class="info-card or-card or-summary" id="return-request">'
        . '<h4><i class="fas fa-undo-alt"></i> Returns &amp; Refunds <small class="or-count">' . count($requests) . ' request' . (count($requests) === 1 ? '' : 's') . '</small></h4>';
    foreach ($requests as $r) {
        [$label, $tone] = rr_status_label((string) $r['status']);
        $open = in_array($r['status'], rr_open_statuses(), true);
        $next = ['pending' => 'Review', 'approved' => 'Receive', 'received' => 'Inspect', 'inspected' => 'Resolve'][$r['status']] ?? 'View';
        $item = $r['order_item_id'] === null ? 'Whole order' : ($r['product_name'] ?? 'Item') . ' &times; ' . (int) $r['quantity'];
        $out .= '<div class="or-sum-row" id="return-' . (int) $r['id'] . '">'
            . '<div class="or-sum-main"><strong>Return #' . (int) $r['id'] . '</strong><span>' . ($r['order_item_id'] === null ? $item : $h($r['product_name'] ?? 'Item') . ' &times; ' . (int) $r['quantity']) . '</span>'
            . '<small>' . $h(rr_reason_label((string) $r['reason'])) . ' &middot; ' . $h(date('M j, g:i A', strtotime((string) $r['created_at']))) . '</small></div>'
            . '<span class="or-pill or-' . $h($tone) . '">' . $h($label) . '</span>'
            . '<button type="button" class="or-open rrl-open' . ($open ? ' is-primary' : '') . '" data-rr-id="' . (int) $r['id'] . '">' . $next . '</button>'
            . '</div>';
    }
    return $out . '</div>' . return_request_window();
}

function order_return_styles(): string
{
    return '<style>
        .or-card h4 { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .or-count { margin-left: auto; color: var(--muted); font-weight: 600; font-size: .8rem; }
        .or-req { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); scroll-margin-top: 80px; }
        .or-req-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; font-size: .9rem; color: var(--text); }
        .or-pill { margin-left: auto; padding: 3px 10px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
        .or-warning { background: var(--warning-soft, #fef3c7); color: var(--warning, #b45309); }
        .or-info { background: var(--info-soft, #dbeafe); color: var(--info, #1d4ed8); }
        .or-danger { background: var(--danger-soft, #fee2e2); color: var(--danger, #b91c1c); }
        .or-success { background: var(--success-soft, #dcfce7); color: var(--success, #15803d); }
        .or-muted { color: var(--muted); font-weight: 500; }
        .or-steps { display: flex; flex-wrap: wrap; gap: 4px; list-style: none; margin: 0 0 12px; padding: 0; counter-reset: s; }
        .or-steps li { counter-increment: s; padding: 3px 10px; border-radius: 999px; font-size: .72rem; font-weight: 600; background: var(--surface-2, #f1f5f9); color: var(--muted); }
        .or-steps li::before { content: counter(s) ". "; }
        .or-steps li.done { background: var(--success-soft, #dcfce7); color: var(--success, #15803d); }
        .or-steps li.now { background: var(--primary, #4f46e5); color: var(--on-primary, #fff); }
        .or-grid { display: grid; grid-template-columns: minmax(100px, auto) 1fr; gap: 6px 14px; margin: 0; font-size: .87rem; }
        .or-grid dt { color: var(--muted); }
        .or-grid dd { margin: 0; color: var(--text); font-weight: 600; overflow-wrap: anywhere; }
        .or-moves { margin: 0; padding-left: 16px; }
        .or-moves code { font-size: .78rem; }
        .or-photo { max-width: 160px; max-height: 160px; border-radius: 8px; border: 1px solid var(--border); object-fit: cover; }
        .or-form { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
        .or-form label { font-size: .8rem; font-weight: 600; color: var(--muted); }
        .or-form textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; font-size: .87rem; box-sizing: border-box; }
        .or-form .or-check { display: flex; align-items: center; gap: 8px; color: var(--text); font-weight: 500; }
        .or-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .or-actions .button { margin: 0; }
        .or-approve { background: var(--primary, #4f46e5); }
        .or-secondary { background: var(--muted, #64748b); }
        .or-reject { background: var(--danger, #dc2626); }
        .or-hint { margin: 0 0 4px; font-size: .8rem; color: var(--muted); }
        /* Floating Return / Refund window */
        .rrm { position: fixed; inset: 0; z-index: 3000; display: flex; align-items: flex-start; justify-content: center; padding: 3vh 16px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
        .rrm[hidden] { display: none; }
        .rrm-card { width: min(100%, 1180px); background: var(--surface); color: var(--text); border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, .3); overflow: hidden; }
        .rrm-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--border); }
        .rrm-head h3 { margin: 0; font-size: 1.05rem; }
        .rrm-x { width: 34px; height: 34px; border: 0; border-radius: 50%; background: var(--surface-2); color: var(--muted); cursor: pointer; }
        .rrm-body { padding: 16px 18px 18px; }
        .rrm-body .or-card { border: 0; box-shadow: none; padding: 0; margin: 12px 0 0; background: none; }
        .rrm-body .or-req { margin-top: 10px; padding-top: 0; border-top: 0; }
        .rrm-msg { margin: 12px 18px 0; padding: 10px 12px; border-radius: 10px; font-size: .88rem; font-weight: 600; }
        .rrm-msg.is-good { background: var(--success-soft, #dcfce7); color: var(--success, #15803d); }
        .rrm-msg.is-bad { background: var(--danger-soft, #fee2e2); color: var(--danger, #b91c1c); }
        .rrm-loading { color: var(--muted); margin: 8px 0; }
        .rrm-body .or-actions { margin-top: 4px; }
        .rrm-body .or-actions .button { display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; border: 0; border-radius: 10px; color: #fff; font: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; }
        .rrm-body .or-actions .button:hover { filter: brightness(1.08); }
        .rrm-body .or-actions .button:disabled { opacity: .6; cursor: wait; }
        .rrm-order { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 10px 14px; align-items: start; padding: 12px 14px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--border); font-size: .85rem; }
        .rrm-order span { display: block; color: var(--muted); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .rrm-order strong { display: block; overflow-wrap: anywhere; }
        .rrm-order small, .rrm-order a[href^="tel"] { display: block; color: var(--muted); font-size: .78rem; margin-top: 2px; }
        .rrm-full { align-self: center; white-space: nowrap; color: var(--link); font-weight: 700; text-decoration: none; font-size: .82rem; }
        .rrm-full:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            .rrm { padding: 0; align-items: stretch; }
            .rrm-card { border-radius: 0; min-height: 100%; }
            .rrm-order { grid-template-columns: 1fr 1fr; }
            .rrm-body .or-grid { grid-template-columns: 1fr; }
        }
        /* Floating window: redesigned request card (order_return_card) */
        .rw { display: flex; flex-direction: column; gap: 14px; }
        .rw-hero { display: flex; align-items: center; gap: 14px; padding: 14px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
        .rw-thumb { width: 64px; height: 64px; flex: 0 0 64px; border-radius: 12px; object-fit: contain; background: var(--surface-2); border: 1px solid var(--border); }
        .rw-thumb-empty { display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 1.4rem; }
        .rw-hero-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .rw-kicker { font-size: .74rem; color: var(--muted); font-weight: 600; }
        .rw-name { font-size: 1rem; line-height: 1.3; color: var(--text); overflow-wrap: anywhere; }
        .rw-sub { font-size: .8rem; color: var(--muted); }
        .rw-hero-side { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; text-align: right; flex: 0 0 auto; }
        .rw-hero-side .or-pill { margin: 0; }
        .rw-amount { font-size: 1.25rem; font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; }
        .rw-hero-side small { font-size: .72rem; color: var(--muted); }
        .rw-steps { display: grid; grid-template-columns: repeat(5, 1fr); list-style: none; margin: 0; padding: 0; }
        .rw-steps li { position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; }
        .rw-steps li:not(:first-child)::before { content: ""; position: absolute; top: 14px; right: 50%; width: 100%; height: 3px; background: var(--border); z-index: 0; }
        .rw-steps li.done:not(:first-child)::before, .rw-steps li.now:not(:first-child)::before { background: var(--success, #16a34a); }
        .rw-dot { position: relative; z-index: 1; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; background: var(--surface-2); color: var(--muted); border: 2px solid var(--border); }
        .rw-steps li.done .rw-dot { background: var(--success, #16a34a); border-color: var(--success, #16a34a); color: #fff; }
        .rw-steps li.now .rw-dot { background: var(--primary, #4f46e5); border-color: var(--primary, #4f46e5); color: #fff; box-shadow: 0 0 0 4px var(--primary-soft, #e0e7ff); }
        .rw-step-label { font-size: .74rem; font-weight: 700; color: var(--muted); }
        .rw-steps li.now .rw-step-label { color: var(--link); }
        .rw-steps li.done .rw-step-label { color: var(--text); }
        .rw-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; align-items: start; }
        .rw-box { padding: 14px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); min-width: 0; }
        .rw-box h5, .rw-action h5 { margin: 0 0 10px; font-size: .82rem; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 7px; text-transform: uppercase; letter-spacing: .03em; }
        .rw-box h5 i, .rw-action h5 i { color: var(--link); }
        .rw-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .rw-tag { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border); font-size: .76rem; font-weight: 700; color: var(--text); }
        .rw-quote { margin: 0; padding: 10px 12px; border-left: 3px solid var(--primary, #4f46e5); background: var(--surface-2); border-radius: 0 10px 10px 0; font-size: .86rem; color: var(--text); overflow-wrap: anywhere; }
        .rw-photo { position: relative; display: block; margin-top: 10px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); background: var(--surface-2); }
        .rw-photo img { display: block; width: 100%; max-height: 200px; object-fit: contain; }
        .rw-photo span { position: absolute; right: 8px; bottom: 8px; padding: 4px 10px; border-radius: 999px; background: rgba(15, 23, 42, .75); color: #fff; font-size: .74rem; font-weight: 700; }
        .rw-facts { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        .rw-facts li { display: flex; gap: 10px; align-items: flex-start; }
        .rw-facts li > i { width: 28px; height: 28px; flex: 0 0 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--primary-soft, #eef2ff); color: var(--link); font-size: .8rem; }
        .rw-facts span { display: block; font-size: .72rem; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .rw-facts strong { display: block; font-size: .86rem; color: var(--text); overflow-wrap: anywhere; }
        .rw-facts small { display: block; font-weight: 500; color: var(--muted); font-size: .76rem; margin-top: 2px; }
        .rw-empty { margin: 0; font-size: .85rem; color: var(--muted); }
        .rw-action { padding: 14px; border-radius: 14px; border: 2px solid var(--primary, #4f46e5); background: var(--surface); min-width: 0; }
        .rw-action .or-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .rw-action textarea { min-height: 70px; }
        .rw-action .or-form { margin-top: 0; }
        .rw-action .or-check { padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; }
        .rw-action .or-check:hover { border-color: var(--primary, #4f46e5); }
        .rw-action .or-check:has(input:checked) { border-color: var(--primary, #4f46e5); background: var(--primary-soft, #eef2ff); }
        .rw-action .or-check input { accent-color: var(--primary, #4f46e5); }
        .rw-banner { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-radius: 12px; font-weight: 700; font-size: .88rem; }
        .rw-banner.is-good { background: var(--success-soft, #dcfce7); color: var(--success, #15803d); }
        .rw-banner.is-bad { background: var(--danger-soft, #fee2e2); color: var(--danger, #b91c1c); }
        .rw-foot { margin: 0; font-size: .75rem; color: var(--muted); display: flex; gap: 6px; align-items: flex-start; }
        @media (max-width: 600px) {
            .rw-grid { grid-template-columns: 1fr; }
            .rw-hero { flex-wrap: wrap; }
            .rw-hero-side { flex-direction: row; align-items: center; width: 100%; justify-content: space-between; text-align: left; }
            .rw-step-label { font-size: .66rem; }
        }
        /* Order Details summary (the full request opens in the floating window) */
        .or-sum-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 0; border-top: 1px solid var(--border); scroll-margin-top: 80px; }
        .or-sum-row:first-of-type { border-top: 0; }
        .or-sum-main { flex: 1 1 200px; min-width: 0; display: flex; flex-direction: column; gap: 2px; font-size: .88rem; }
        .or-sum-main span { color: var(--text); }
        .or-sum-main small { color: var(--muted); font-size: .78rem; }
        .or-summary .or-pill { margin-left: 0; }
        .or-open { padding: 8px 16px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; }
        .or-open:hover { border-color: var(--primary, #4f46e5); color: var(--link); }
        .or-open.is-primary { background: var(--primary, #4f46e5); border-color: var(--primary, #4f46e5); color: var(--on-primary, #fff); }
    </style>';
}
