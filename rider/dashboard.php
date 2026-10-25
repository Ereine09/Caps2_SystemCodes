<?php
/**
 * Rider Home: greeting, Available/Offline, today's numbers, quick scan, amount to remit and
 * the active deliveries. Data: rider_orders_api.php (assigned deliveries), rider_status_api.php
 * (duty), rider_remittance_api.php?action=get_overview (COD to hand over).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$rider = require_rider();

rider_page_start('Home');
?>
<div class="rd-stack">
    <section class="rd-card rd-stack">
        <div class="rd-greet">
            <div class="rd-avatar" aria-hidden="true" style="overflow:hidden;"><?php require_once __DIR__ . '/../app/helpers/profile_photo_helper.php'; echo profile_avatar_inner(profile_photo_get($conn, 'user', (int) $rider['id']), $rider['display_name'], 1); ?></div>
            <div class="rd-greet-text">
                <p class="rd-greet-hello" id="greet-hello">Hello,</p>
                <p class="rd-greet-name"><?php echo rider_e($rider['display_name']); ?></p>
            </div>
        </div>
        <button type="button" class="rd-duty" id="duty" data-on="<?php echo (int) $rider['is_on_duty'] === 1 ? '1' : '0'; ?>" aria-pressed="false">
            <span class="rd-duty-dot" aria-hidden="true"></span>
            <span class="rd-duty-text"><strong>Offline</strong><span></span></span>
            <span class="rd-switch" aria-hidden="true"></span>
        </button>
    </section>

    <div id="assign-wrap" hidden>
        <div class="rd-section-title">New assignments <span class="rd-badge rd-badge-primary no-dot" id="assign-count">0</span></div>
        <p class="rd-help" style="margin:-4px 2px 0;">The shop assigned these orders to you. Accept to deliver them, or decline so the shop can assign another rider.</p>
        <section class="rd-orders" id="assign-list" aria-live="polite"></section>
    </div>

    <!-- Today's earnings (delivery fees + tips), tap for the daily breakdown -->
    <a class="rd-earn-card" href="earnings.php" id="earn-card">
        <span class="rd-earn-ico"><i class="fas fa-coins"></i></span>
        <span class="rd-earn-main">
            <small>Today's earnings</small>
            <strong id="earn-today">₱0.00</strong>
            <span id="earn-sub">Delivery fees + tips</span>
        </span>
        <span class="rd-earn-side"><small>This week</small><b id="earn-week">₱0.00</b><i class="fas fa-chevron-right"></i></span>
    </a>

    <div class="rd-section-title">Today</div>
    <section class="rd-stats" id="stats" aria-live="polite">
        <a class="rd-stat rd-tone-primary" href="deliveries.php?tab=todo"><span class="rd-stat-top">Assigned <i class="fas fa-clipboard-list"></i></span><span class="rd-stat-value" data-stat="assigned">–</span></a>
        <a class="rd-stat rd-tone-warning" href="deliveries.php?tab=todo"><span class="rd-stat-top">To pick up <i class="fas fa-box"></i></span><span class="rd-stat-value" data-stat="pickup">–</span></a>
        <a class="rd-stat rd-tone-info" href="deliveries.php?tab=out"><span class="rd-stat-top">Out for delivery <i class="fas fa-truck-fast"></i></span><span class="rd-stat-value" data-stat="out">–</span></a>
        <a class="rd-stat rd-tone-success" href="deliveries.php?tab=done"><span class="rd-stat-top">Completed <i class="fas fa-circle-check"></i></span><span class="rd-stat-value" data-stat="done">–</span></a>
    </section>

    <a class="rd-scan-cta" href="scan.php">
        <i class="fas fa-qrcode" aria-hidden="true"></i>
        <span><strong>Scan Delivery QR</strong><span>At the store to start a delivery, at the customer to finish it</span></span>
    </a>

    <a class="rd-note is-warn" href="returns.php" id="returns-cta" style="text-decoration:none;" hidden>
        <i class="fas fa-rotate-left"></i><span><strong id="returns-count">0 return pickups</strong> waiting. Collect returned items from customers and bring them to the shop. <u>Open</u></span>
    </a>

    <a class="rd-hero" href="remittance.php" id="remit-hero" style="text-decoration:none;" hidden>
        <div class="rd-hero-label"><i class="fas fa-wallet"></i> Money to give the shop</div>
        <div class="rd-hero-value" id="remit-amount">₱0.00</div>
        <div class="rd-hero-sub" id="remit-sub"></div>
    </a>

    <div class="rd-section-title">Available orders <a href="deliveries.php?tab=available">See all</a></div>
    <p class="rd-help" id="avail-help" style="margin:-4px 2px 0;">To Ship orders no rider has taken yet. Accept one, then pick it up at the store.</p>
    <section class="rd-orders" id="avail-list"><?php // filled by JS ?></section>

    <div class="rd-section-title">My deliveries <a href="deliveries.php">See all</a></div>
    <section class="rd-orders" id="active-list"><?php // filled by JS ?></section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider;
    var h = new Date().getHours();
    document.getElementById('greet-hello').textContent = h < 12 ? 'Good morning,' : (h < 18 ? 'Good afternoon,' : 'Good evening,');
    R.initDuty(document.getElementById('duty'));

    var list = document.getElementById('active-list');
    var avail = document.getElementById('avail-list');
    var loaded = false, online = <?php echo (int) $rider['is_on_duty'] === 1 ? 'true' : 'false'; ?>, lastAvailable = [];
    avail.innerHTML = R.skeleton(1);

    function renderAvailable() {
        if (!online) {
            avail.innerHTML = '<div class="rd-card rd-empty"><i class="fas fa-moon"></i><strong>You are not Online</strong>Go Online (top of this page) to see and accept To Ship orders.</div>';
            return;
        }
        var pgA = R.paged('home-available', lastAvailable);
        avail.innerHTML = lastAvailable.length
            ? pgA.items.map(R.availableCard).join('') + R.pager(pgA)
            : '<div class="rd-card rd-empty"><i class="fas fa-box-open"></i><strong>No orders waiting</strong>New To Ship orders appear here automatically.</div>';
    }
    R.onAvailability(function (a) { online = a.status === 'online'; if (loaded) renderAvailable(); });
    avail.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accept]');
        if (!b) return;
        R.acceptOrder(b.dataset.accept, b).then(refresh, refresh); // either way the list must show the truth
    });
    // Orders the admin assigned to this rider: Accept / Decline (rider_assignment_api.php).
    var assignWrap = document.getElementById('assign-wrap'), assignList = document.getElementById('assign-list');
    assignList.addEventListener('click', function (e) {
        var a = e.target.closest('[data-assign-accept]'), d = e.target.closest('[data-assign-decline]');
        if (a) R.respondAssignment(a.dataset.assignAccept, 'accept', a).then(refresh, refresh);
        else if (d) R.respondAssignment(d.dataset.assignDecline, 'decline', d, d.dataset.number).then(function (r) { if (r) refresh(); }, refresh);
    });
    var ACTIVE = ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery'];
    var lastActive = [];
    // 10 per page: Prev / Next re-draw the list from what is already loaded.
    R.onPager(avail, renderAvailable);
    R.onPager(list, function () { var pg = R.paged('home-mine', lastActive); list.innerHTML = pg.items.map(R.orderCard).join('') + R.pager(pg); });
    R.onPager(assignList, function () { refresh(); });
    list.innerHTML = R.skeleton(2);

    function setStat(k, v) { document.querySelector('[data-stat="' + k + '"]').textContent = v; }

    function loadOrders() {
        return R.call('rider_orders_api.php').then(function (res) {
            var rows = (res.data && res.data.assignments) || [];
            var mine = rows.filter(function (o) { return o.assigned_to_me; });
            lastAvailable = rows.filter(function (o) { return o.is_available; });
            var pending = rows.filter(function (o) { return o.needs_response; });
            assignWrap.hidden = !pending.length;
            document.getElementById('assign-count').textContent = pending.length;
            var pgN = R.paged('home-assign', pending);
            assignList.innerHTML = pgN.items.map(R.assignmentCard).join('') + R.pager(pgN);
            // "My deliveries" below = mine (+ old unassigned on-the-road ones), minus those still waiting for an answer
            rows = rows.filter(function (o) { return !o.is_available && !o.needs_response; });
            setStat('assigned', mine.filter(function (o) { return ACTIVE.indexOf(o.order_status) !== -1; }).length);
            setStat('pickup', mine.filter(function (o) { return o.order_status === 'to_ship'; }).length);
            setStat('out', mine.filter(function (o) { return o.order_status === 'out_for_delivery'; }).length);
            setStat('done', mine.filter(function (o) { return o.order_status === 'completed' && R.isToday(o.delivered_at); }).length);

            // Most urgent first: out for delivery, then to receive, to ship, and the rest.
            var rank = { out_for_delivery: 0, to_receive: 1, to_ship: 2, ready_for_pickup: 3, processing: 4 };
            var active = rows.filter(function (o) { return ACTIVE.indexOf(o.order_status) !== -1; })
                .sort(function (a, b) { return (rank[a.order_status] - rank[b.order_status]) || (a.assigned_to_me === b.assigned_to_me ? 0 : (a.assigned_to_me ? -1 : 1)); });
            lastActive = active;
            var pgM = R.paged('home-mine', active);
            list.innerHTML = active.length
                ? pgM.items.map(R.orderCard).join('') + R.pager(pgM)
                : '<div class="rd-card rd-empty"><i class="fas fa-mug-hot"></i><strong>No active deliveries</strong>Orders you accept show up here.</div>';
            loaded = true;
            renderAvailable();
        }, function (err) {
            // A failed background refresh keeps what is on screen (the offline banner explains it).
            if (!loaded) { list.innerHTML = R.errorBox(err); avail.innerHTML = ''; }
        });
    }

    function loadRemit() {
        return R.call('rider_remittance_api.php?action=get_overview').then(function (res) {
            var s = (res.data && res.data.summary) || {};
            var hero = document.getElementById('remit-hero');
            var pending = R.num(s.total_pending_amount);
            var waiting = +s.awaiting_review_count || 0;
            hero.hidden = pending <= 0 && waiting === 0;
            document.getElementById('remit-amount').textContent = R.peso(pending);
            document.getElementById('remit-sub').textContent = (+s.orders_pending || 0) + ' cash order' + ((+s.orders_pending === 1) ? '' : 's') + ' not yet paid' +
                (waiting ? ' · ' + waiting + ' sent, waiting for the shop to check' : '');
        }, function () { /* the deliveries list already shows connection problems */ });
    }

    function loadReturns() {
        return R.call('rider_returns_api.php').then(function (res) {
            var rows = (res.data && res.data.pickups) || [];
            var cta = document.getElementById('returns-cta');
            cta.hidden = rows.length === 0;
            document.getElementById('returns-count').textContent = rows.length + ' return pickup' + (rows.length === 1 ? '' : 's');
        }, function () { /* the deliveries list already shows connection problems */ });
    }

    function loadEarnings() {
        return R.call('rider_earnings_api.php?action=get_daily').then(function (res) {
            var d = res.data || {}, t = d.today || {}, wk = d.week || {};
            document.getElementById('earn-today').textContent = R.peso(t.earnings || 0);
            document.getElementById('earn-sub').textContent = (+t.deliveries || 0) + ' ' + ((+t.deliveries || 0) === 1 ? 'delivery' : 'deliveries') + ' today · fees + tips';
            document.getElementById('earn-week').textContent = R.peso(wk.earnings || 0);
        }, function () { /* the deliveries list already shows connection problems */ });
    }
    function refresh() { return Promise.all([loadOrders(), loadRemit(), loadReturns(), loadEarnings()]); }
    list.addEventListener('click', function (e) { if (e.target.closest('[data-retry]')) { list.innerHTML = R.skeleton(2); refresh(); } });
    refresh();
    R.pullToRefresh(refresh);
    R.live(refresh, 30000, 'deliveries');
});
</script>
<?php rider_page_end('home'); ?>
