<?php
/**
 * Return pickups: items customers are returning, collected for free on the pickup date and
 * brought back to the shop. Any rider can claim an open pickup. Steps (rider_returns_api.php):
 * Claim -> Picked up from customer -> Dropped off at shop (= item received; staff then inspect it).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Return pickups', ['back' => BASE_URL . '/rider/dashboard.php']);
?>
<div class="rd-stack">
    <div class="rd-note"><i class="fas fa-rotate-left"></i><span>Collect returned items from customers (free for them) and bring them to the shop.
        Claim a pickup, mark it <strong>picked up</strong> at the customer, then <strong>dropped off</strong> at the shop.</span></div>
    <section class="rd-orders" id="list" aria-live="polite"></section>
</div>
<style>
    .rd-ret-item { margin-top: 8px; font-weight: 600; }
    .rd-ret-meta { margin-top: 4px; color: var(--muted); font-size: .86rem; display: flex; gap: 8px; }
    .rd-ret-meta i { width: 16px; text-align: center; color: var(--faint); margin-top: 2px; }
    .rd-ret-links { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .rd-ret-actions { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border); display: grid; gap: 8px; }
    .rd-tag.is-mine { background: var(--primary-soft); color: var(--primary); }
    .rd-tag.is-done { background: var(--success-soft); color: var(--success); }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var list = document.getElementById('list');
    var loaded = false;
    list.innerHTML = R.skeleton(2);

    function mapsUrl(p) {
        var lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
        var q = isFinite(lat) && isFinite(lng) && (lat || lng) ? lat + ',' + lng : (p.address || '');
        return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q);
    }

    function card(p) {
        var tag = p.picked_up ? '<span class="rd-tag is-done"><i class="fas fa-box"></i> With you</span>'
            : (p.mine ? '<span class="rd-tag is-mine"><i class="fas fa-hand"></i> Claimed by you</span>' : '<span class="rd-tag"><i class="fas fa-circle-dot"></i> Open</span>');
        var item = esc(p.product_name || 'Item') + (p.variant_size && String(p.product_name || '').indexOf(p.variant_size) === -1 ? ' (' + esc(p.variant_size) + ')' : '') + ' &times; ' + (+p.quantity || 1);
        var when = p.due ? (p.pickup_label ? 'Pick up today (scheduled ' + esc(p.pickup_label) + ')' : 'Pick up today') : 'Pick up on ' + esc(p.pickup_label);
        var actions;
        if (!p.mine) {
            actions = '<button type="button" class="rd-btn rd-btn-primary rd-btn-block" data-act="claim" data-id="' + p.id + '"><i class="fas fa-hand"></i> Claim this pickup</button>';
        } else if (!p.picked_up) {
            actions = '<button type="button" class="rd-btn rd-btn-primary rd-btn-block" data-act="picked_up" data-id="' + p.id + '"><i class="fas fa-box"></i> Picked up from customer</button>' +
                '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-act="release" data-id="' + p.id + '">Release (let another rider take it)</button>';
        } else {
            actions = '<button type="button" class="rd-btn rd-btn-success rd-btn-block" data-act="dropped_off" data-id="' + p.id + '"><i class="fas fa-store"></i> Dropped off at shop</button>';
        }
        return '<div class="rd-order">' +
            '<div class="rd-order-head"><span class="rd-order-no">Return #' + p.id + ' · Order #' + esc(p.order_number) + '</span>' + tag + '</div>' +
            '<div class="rd-ret-item">' + item + '</div>' +
            '<div class="rd-order-cust"><i class="fas fa-user"></i><span>' + esc(p.customer_name || 'Customer') + '</span></div>' +
            '<div class="rd-order-addr"><i class="fas fa-location-dot"></i><span>' + esc(p.address || 'No address on file. Call the customer.') + '</span></div>' +
            '<div class="rd-ret-meta"><i class="fas fa-calendar-day"></i><span>' + when + ' · free for the customer</span></div>' +
            '<div class="rd-ret-links">' +
                (p.address ? '<a class="rd-btn rd-btn-outline rd-btn-sm" href="' + mapsUrl(p) + '" target="_blank" rel="noopener"><i class="fas fa-diamond-turn-right"></i> Navigate</a>' : '') +
                (p.phone ? '<a class="rd-btn rd-btn-outline rd-btn-sm" href="tel:' + esc(String(p.phone).replace(/[^\d+]/g, '')) + '"><i class="fas fa-phone"></i> Call</a>' : '') +
            '</div>' +
            '<div class="rd-ret-actions">' + actions + '</div>' +
            '</div>';
    }

    function load() {
        return R.call('rider_returns_api.php').then(function (res) {
            var rows = (res.data && res.data.pickups) || [];
            loaded = true;
            list.innerHTML = rows.length ? rows.map(card).join('')
                : '<div class="rd-card rd-empty"><i class="fas fa-rotate-left"></i><strong>No return pickups</strong>Approved returns waiting for a rider show up here.</div>';
        }, function (err) {
            if (!loaded) list.innerHTML = R.errorBox(err);
        });
    }

    var CONFIRM = {
        claim: 'Claim this pickup? You will collect the item from the customer on the pickup date.',
        release: 'Release this pickup so another rider can take it?',
        picked_up: 'Did you collect the item from the customer?',
        dropped_off: 'Did you hand the item over at the shop?'
    };
    list.addEventListener('click', function (e) {
        if (e.target.closest('[data-retry]')) { list.innerHTML = R.skeleton(2); load(); return; }
        var btn = e.target.closest('[data-act]');
        if (!btn || !window.confirm(CONFIRM[btn.dataset.act])) return;
        R.guarded(btn, 'Saving…', function () {
            return R.call('rider_returns_api.php', { body: { action: btn.dataset.act, id: +btn.dataset.id } });
        }).then(function (res) {
            R.notify(res.message || 'Saved.', 'ok');
            return load();
        }, function (err) {
            R.fail(err);
            return load();
        });
    });

    load();
    R.pullToRefresh(load);
    R.live(load, 30000);
});
</script>
<?php rider_page_end(''); ?>
