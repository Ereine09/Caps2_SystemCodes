<?php
/**
 * "Riders right now" panel (admin E-Bike page): is each rider working (Online), on a break, or
 * off (Offline), why, since when, and which orders they still have. Riders set this themselves in
 * the Rider Portal (rider_dispatch_helper.php). The E-Bike page re-reads #riders every 20 s.
 */
require_once __DIR__ . '/../../app/helpers/rider_dispatch_helper.php';

function rider_board_rows(mysqli $conn): array
{
    rider_dispatch_ensure_schema($conn);
    $res = $conn->query(
        "SELECT u.id AS user_id, u.username, u.first_name, u.last_name,
                r.id AS riders_id, r.is_on_duty, r.availability_status, r.availability_reason, r.availability_details,
                r.availability_changed_at, r.last_seen
         FROM users u LEFT JOIN riders r ON r.user_id = u.id
         WHERE LOWER(TRIM(u.role)) = 'rider'
         ORDER BY u.username ASC"
    );
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $row['availability'] = rider_availability_from_row($row['riders_id'] === null ? null : $row);
        $row['active'] = rider_active_deliveries($conn, (int) $row['user_id'], (int) ($row['riders_id'] ?? 0));
        $full = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $row['display_name'] = $full !== '' ? $full : (string) $row['username'];
        $rows[] = $row;
    }
    return $rows;
}

function rider_board_html(mysqli $conn): string
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rows = rider_board_rows($conn);
    $counts = ['online' => 0, 'on_break' => 0, 'offline' => 0];
    foreach ($rows as $r) {
        $counts[$r['availability']['status']]++;
    }
    // [badge tone, what it means in plain words]
    $meaning = [
        'online'   => ['success', 'Working — can take new orders'],
        'on_break' => ['warning', 'On a break — no new orders'],
        'offline'  => ['neutral', 'Not working — no new orders'],
    ];

    $out = '<section class="content-card rb" id="riders">'
        . '<div class="rb-head"><h2><i class="fas fa-motorcycle"></i> Riders right now</h2>'
        . '<span class="rb-sum">'
        . '<span class="ui-badge ui-badge-success">' . $counts['online'] . ' working</span>'
        . '<span class="ui-badge ui-badge-warning">' . $counts['on_break'] . ' on break</span>'
        . '<span class="ui-badge ui-badge-neutral">' . $counts['offline'] . ' not working</span></span></div>'
        . '<p class="rb-help">Riders switch this themselves on their phone. Only riders who are <strong>Online</strong> can take new orders.</p>';
    if (!$rows) {
        return $out . '<p class="rb-help">No rider accounts yet. Add one in Staff Management.</p></section>';
    }
    $out .= '<div class="rb-list">';
    foreach ($rows as $r) {
        $a = $r['availability'];
        [$tone, $plain] = $meaning[$a['status']];
        $why = $a['reason_label'] !== '' ? $h($a['reason_label']) . ($a['details'] !== '' ? ' — ' . $h($a['details']) : '') : '';
        $since = $a['changed_at'] ? 'since ' . $h(date('M j, g:i A', strtotime((string) $a['changed_at']))) : '';
        $n = count($r['active']);
        // Short chips: "#AD1B0AD1" + where the parcel is; the full order number shows on hover.
        $stage = ['to_ship' => ['At the store', 'store'], 'to_receive' => ['At the store', 'store'], 'out_for_delivery' => ['On the way', 'road']];
        $chip = function ($o) use ($h, $stage) {
                $short = preg_match('/^[A-Z]+-([A-Z0-9]{4,})-\d+$/', (string) $o['order_number'], $m) ? $m[1] : (string) $o['order_number'];
                [$where, $cls] = $stage[$o['order_status']] ?? ['Active', 'store'];
                return '<a class="rb-chip" href="order_details.php?id=' . (int) $o['id'] . '" title="Order ' . $h($o['order_number']) . ' - open it">'
                    . '<i class="fas fa-box"></i><span class="rb-chip-no">#' . $h($short) . '</span>'
                    . '<span class="rb-chip-st is-' . $cls . '">' . $h($where) . '</span></a>';
        };
        // Only the first 4 chips; the rest open under "+N more" so a busy rider's row stays short.
        $shown = array_slice($r['active'], 0, 4);
        $more = array_slice($r['active'], 4);
        $orders = $n
            ? '<div class="rb-chips">' . implode('', array_map($chip, $shown))
                . ($more ? '<details class="rb-more"><summary><span class="rb-more-open">+' . count($more) . ' more</span><span class="rb-more-close">Show less</span></summary>'
                    . '<div class="rb-chips">' . implode('', array_map($chip, $more)) . '</div></details>' : '')
                . '</div>'
            : '';
        $stuck = $n && $a['status'] !== 'online';

        $out .= '<div class="rb-row' . ($stuck ? ' is-stuck' : '') . '" data-rider-id="' . (int) $r['user_id'] . '">'
            . '<div class="rb-who"><strong>' . $h($r['display_name']) . '</strong><small>@' . $h($r['username']) . '</small></div>'
            . '<div class="rb-state"><span class="ui-badge ui-badge-' . $tone . '">' . $h($a['label']) . '</span>'
                . '<small>' . $h($plain) . ($why !== '' ? '<br>Reason: ' . $why : '') . ($since !== '' ? '<br>' . $since : '') . '</small></div>'
            . '<div class="rb-orders">'
                . ($n ? '<small class="rb-orders-title">' . $n . ' order' . ($n === 1 ? '' : 's') . ' to deliver</small>' . $orders
                      : '<small>No orders to deliver</small>')
                . ($stuck ? '<p class="rb-alert"><i class="fas fa-triangle-exclamation"></i> Not working, but these orders are still with this rider. '
                    . 'Open an order and use <strong>Change rider</strong> to give it to someone who is Online.</p>' : '')
            . '</div></div>';
    }
    return $out . '</div></section>';
}

function rider_board_styles(): string
{
    return '<style>
        .rb { margin-bottom: 24px; }
        .rb-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px 14px; }
        .rb-head h2 { margin: 0; font-size: 1.15rem; }
        .rb-sum { display: inline-flex; gap: 6px; flex-wrap: wrap; }
        .rb-help { margin: 6px 0 14px; font-size: .86rem; color: var(--muted); }
        .rb-list { display: grid; gap: 10px; }
        .rb-row { display: grid; grid-template-columns: minmax(140px, 1fr) minmax(180px, 1.2fr) minmax(0, 2fr); gap: 10px 18px; align-items: start;
                  padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
        .rb-row.is-stuck { border-left: 4px solid var(--warning, #d97706); }
        .rb-row small { display: block; color: var(--muted); font-size: .8rem; line-height: 1.45; margin-top: 4px; }
        .rb-who strong { display: block; }
        .rb-orders-title { font-weight: 600; color: var(--text-2) !important; }
        .rb-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .rb-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 10px; border: 1px solid var(--border); border-radius: 999px;
                   background: var(--surface-2); color: var(--text); text-decoration: none; font-size: .8rem; line-height: 1; transition: border-color .15s, background .15s; }
        .rb-chip:hover { border-color: var(--primary, #4f46e5); background: var(--primary-soft, #eef2ff); }
        .rb-chip i { color: var(--muted); font-size: .75rem; }
        .rb-chip-no { font-weight: 700; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .02em; }
        .rb-chip-st { padding: 3px 8px; border-radius: 999px; font-size: .7rem; font-weight: 700; }
        .rb-chip-st.is-store { background: var(--warning-soft, #fef3c7); color: var(--warning-text, #92400e); }
        .rb-more { flex-basis: 100%; }
        .rb-more > summary { display: inline-flex; list-style: none; cursor: pointer; padding: 5px 12px; border-radius: 999px; border: 1px dashed var(--border-strong, #cbd5e1);
                             font-size: .78rem; font-weight: 700; color: var(--link, #4f46e5); }
        .rb-more > summary::-webkit-details-marker { display: none; }
        .rb-more > summary:hover { border-color: var(--primary, #4f46e5); }
        .rb-more .rb-more-close, .rb-more[open] .rb-more-open { display: none; }
        .rb-more[open] .rb-more-close { display: inline; }
        .rb-more[open] > summary { order: 2; margin-top: 6px; }
        .rb-more[open] { display: flex; flex-direction: column; align-items: flex-start; }
        .rb-more .rb-chips { margin-top: 0; }
        .rb-chip-st.is-road { background: var(--info-soft, #dbeafe); color: var(--info, #1d4ed8); }
        .rb-alert { margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; background: var(--warning-soft, #fef3c7); color: var(--warning-text, #92400e); font-size: .82rem; line-height: 1.45; }
        @media (max-width: 760px) { .rb-row { grid-template-columns: minmax(0, 1fr); } }
    </style>';
}
