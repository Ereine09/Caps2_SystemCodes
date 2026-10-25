<?php
/**
 * Rider deliveries. Only what rider_orders_api.php returns for this rider: orders assigned to
 * them, plus unassigned To Ship delivery orders (the Available tab, with Accept - only while
 * Online; rider_accept_order_api.php). Grouped by the real statuses.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Deliveries');
?>
<div class="rd-stack">
    <div class="rd-tabs" role="tablist" id="tabs">
        <button type="button" class="rd-tab" role="tab" data-tab="available">Available <span class="rd-tab-count" data-count="available">0</span></button>
        <button type="button" class="rd-tab" role="tab" data-tab="todo">To Do <span class="rd-tab-count" data-count="todo">0</span></button>
        <button type="button" class="rd-tab" role="tab" data-tab="out">Out for Delivery <span class="rd-tab-count" data-count="out">0</span></button>
        <button type="button" class="rd-tab" role="tab" data-tab="done">Completed <span class="rd-tab-count" data-count="done">0</span></button>
        <button type="button" class="rd-tab" role="tab" data-tab="hold">On Hold <span class="rd-tab-count" data-count="hold">0</span></button>
    </div>
    <div class="rd-toolbar">
        <label class="rd-search">
            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
            <input class="rd-input" type="search" id="q" placeholder="Search order or customer" aria-label="Search order or customer" autocomplete="off">
        </label>
        <select class="rd-select" id="sort" aria-label="Sort">
            <option value="default">Default</option>
            <option value="newest">Newest</option>
            <option value="oldest">Oldest</option>
        </select>
    </div>
    <p class="rd-help" id="tab-help" style="margin:-4px 2px 0;"></p>
    <section class="rd-orders" id="list" aria-live="polite"></section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider;
    var TABS = {
        available: { statuses: [], help: 'To Ship orders no rider has taken yet. Accept one (you must be Online), then pick it up at the store.', empty: ['fa-box-open', 'No orders waiting', 'New To Ship orders appear here automatically.'] },
        todo: { statuses: ['pending', 'confirmed', 'processing', 'ready_for_pickup', 'to_ship', 'to_receive'], help: 'Orders you accepted or were assigned, ready at the store. Scan the parcel QR when you pick one up to start the delivery.', empty: ['fa-mug-hot', 'Nothing to do yet', 'Accept an order from Available, or wait for the admin to assign one.'] },
        out: { statuses: ['out_for_delivery'], help: 'On the way to the customer. Scan the customer\'s QR to confirm delivery.', empty: ['fa-truck-fast', 'No deliveries on the road', 'Orders you start delivering appear here.'] },
        done: { statuses: ['completed'], help: 'Delivered orders. For cash (COD) orders, pay the shop in the Remittance tab.', empty: ['fa-circle-check', 'No completed deliveries yet', ''] },
        hold: { statuses: ['cancelled'], help: 'Cancelled orders that were assigned to you.', empty: ['fa-circle-pause', 'Nothing on hold', ''] }
    };
    var params = new URLSearchParams(location.search);
    var tab = TABS[params.get('tab')] ? params.get('tab') : 'todo';
    var online = true;
    R.onAvailability(function (a) { online = a.status === 'online'; if (loaded) render(); });
    R.loadAvailability().catch(function () {});
    var rows = [], loaded = false;
    var list = document.getElementById('list');
    var q = document.getElementById('q');
    var sort = document.getElementById('sort');

    function tabOf(o) {
        if (o.is_available) return 'available';
        if (!o.assigned_to_me && (o.order_status === 'to_ship' || o.order_status === 'to_receive')) return null;
        for (var k in TABS) if (TABS[k].statuses.indexOf(o.order_status) !== -1) return k;
        return null;
    }
    function render() {
        document.querySelectorAll('.rd-tab').forEach(function (b) {
            var on = b.dataset.tab === tab;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        var counts = { available: 0, todo: 0, out: 0, done: 0, hold: 0 };
        rows.forEach(function (o) { var t = tabOf(o); if (t) counts[t]++; });
        Object.keys(counts).forEach(function (k) { document.querySelector('[data-count="' + k + '"]').textContent = counts[k]; });
        document.getElementById('tab-help').textContent = TABS[tab].help;

        var term = q.value.trim().toLowerCase();
        var shown = rows.filter(function (o) {
            if (tabOf(o) !== tab) return false;
            if (!term) return true;
            return String(o.order_number || '').toLowerCase().indexOf(term) !== -1 || String(o.customer_name || '').toLowerCase().indexOf(term) !== -1;
        });
        if (sort.value !== 'default') {
            shown.sort(function (a, b) {
                var x = String(a.created_at || ''), y = String(b.created_at || '');
                return sort.value === 'newest' ? y.localeCompare(x) : x.localeCompare(y);
            });
        }
        if (tab === 'available' && !online) {
            list.innerHTML = '<div class="rd-card rd-empty"><i class="fas fa-moon"></i><strong>You are not Online</strong>Go Online on the Home page to accept To Ship orders.</div>';
            return;
        }
        if (!shown.length) {
            var e = TABS[tab].empty;
            list.innerHTML = '<div class="rd-card rd-empty"><i class="fas ' + (term ? 'fa-magnifying-glass' : e[0]) + '"></i><strong>' +
                (term ? 'No matching orders' : e[1]) + '</strong>' + R.esc(term ? 'Try another order number or name.' : e[2]) + '</div>';
            return;
        }
        var pg = R.paged('deliveries-' + tab, shown);   // 10 per page
        list.innerHTML = pg.items.map(tab === 'available' ? R.availableCard : R.orderCard).join('') + R.pager(pg);
    }
    R.onPager(list, render);
    function load() {
        return R.call('rider_orders_api.php').then(function (res) {
            rows = (res.data && res.data.assignments) || [];
            loaded = true;
            render();
        }, function (err) {
            if (!loaded) list.innerHTML = R.errorBox(err);
        });
    }

    document.getElementById('tabs').addEventListener('click', function (e) {
        var b = e.target.closest('[data-tab]');
        if (!b) return;
        tab = b.dataset.tab;
        R.resetPage('deliveries-' + tab);
        params.set('tab', tab);
        history.replaceState(null, '', '?' + params.toString());
        b.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' });
        if (loaded) render();
    });
    q.addEventListener('input', function () { R.resetPage('deliveries-' + tab); if (loaded) render(); });
    sort.addEventListener('change', function () { R.resetPage('deliveries-' + tab); if (loaded) render(); });
    list.addEventListener('click', function (e) {
        if (e.target.closest('[data-retry]')) { list.innerHTML = R.skeleton(3); load(); return; }
        var b = e.target.closest('[data-accept]');
        if (b) R.acceptOrder(b.dataset.accept, b).then(function () { tab = 'todo'; params.set('tab', tab); history.replaceState(null, '', '?' + params.toString()); return load(); }, load);
    });

    render();
    list.innerHTML = R.skeleton(3);
    load();
    R.pullToRefresh(load);
    R.live(load, 30000, 'deliveries');
});
</script>
<?php rider_page_end('deliveries'); ?>
