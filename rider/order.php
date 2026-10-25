<?php
/**
 * Order details for the rider (Flutter "Shipment Details"). Loaded from
 * rider_orders_api.php?action=get_order_details, which only returns orders assigned to this
 * rider (or unclaimed ones they can see in their list). Status changes happen only through
 * the QR scan (scan.php -> rider_update_status_api.php); proof photos go to rider_proof_api.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

$order_id = (int) ($_GET['id'] ?? 0);
if ($order_id <= 0) {
    header('Location: ' . BASE_URL . '/rider/deliveries.php');
    exit();
}

rider_page_start('Order details', ['back' => BASE_URL . '/rider/deliveries.php']);
?>
<div class="rd-stack" id="view" data-order-id="<?php echo $order_id; ?>">
    <div class="rd-skel" style="height:190px;"></div>
    <div class="rd-skel" style="height:96px;"></div>
    <div class="rd-skel" style="height:220px;"></div>
</div>
<div class="rd-actionbar" id="actionbar" hidden><div class="rd-actionbar-inner" id="actions"></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var view = document.getElementById('view');
    var orderId = parseInt(view.dataset.orderId, 10);
    var order = null;
    // Two scans: store (To Ship -> Out for Delivery), customer (-> Completed). A To Receive order
    // (older flow / moved by staff) still shows as "at the store" until it is scanned out.
    var STEPS = ['to_ship', 'out_for_delivery', 'completed'];

    function row(label, value) { return '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>'; }
    function mapsUrl(o) {
        var lat = parseFloat(o.delivery_latitude), lng = parseFloat(o.delivery_longitude);
        var q = isFinite(lat) && isFinite(lng) && (lat || lng) ? lat + ',' + lng : (o.delivery_address || '');
        return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q);
    }
    function telHref(p) { return 'tel:' + String(p || '').replace(/[^\d+]/g, ''); }

    function render(o) {
        var st = String(o.order_status || '').toLowerCase();
        var pm = String(o.payment_method || '').toLowerCase();
        var pay = R.paymentState(o);
        var stepIdx = st === 'to_receive' ? 0 : (st === 'reviews' ? 2 : STEPS.indexOf(st));
        var mine = !!o.assigned_to_me;
        var next = R.NEXT[st];
        // Unassigned To Ship orders are ACCEPTED first (Accept button); an unassigned order already on
        // the road (moved by staff) can still be taken over by scanning, as before.
        var canAct = !!next && (mine || (!!o.is_unclaimed && !o.is_available));
        var phone = o.customer_phone || o.delivery_phone || '';

        var html = '';
        // Hero: number, status, total, progress through the rider's QR steps.
        html += '<section class="rd-hero">' +
            '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">' +
            '<strong style="font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">#' + esc(o.order_number) + '</strong>' +
            '<span class="rd-badge no-dot" style="background:rgba(255,255,255,.2);color:inherit;">' + esc(R.statusMeta(st)[0]) + '</span></div>' +
            '<div class="rd-hero-label" style="margin-top:14px;">Order total</div>' +
            '<div class="rd-hero-value">' + R.peso(o.total) + '</div>' +
            (st === 'cancelled' ? '<div class="rd-hero-sub">This order was cancelled.</div>' :
                '<div class="rd-steps" aria-hidden="true">' + STEPS.map(function (s, i) { return '<span class="rd-step' + (i <= stepIdx ? ' is-done' : '') + '"></span>'; }).join('') + '</div>' +
                '<div class="rd-steps-label"><span>At the store</span><span>On the way</span><span>Delivered</span></div>') +
            '</section>';

        // COD: how much to collect (or what happened to it).
        if (pm === 'cod' && st !== 'cancelled') {
            var collected = st === 'completed' || st === 'reviews';
            html += '<section class="rd-cod' + (collected ? ' is-paid' : '') + '">' +
                '<div><div class="rd-cod-label">' + (collected ? 'COD collected' : 'COD amount to collect') + '</div>' +
                '<div class="rd-cod-value">' + R.peso(o.total) + '</div></div>' +
                '<span class="rd-badge rd-badge-' + pay[1] + '">' + esc(pay[0]) + '</span></section>';
        } else if (st !== 'cancelled') {
            html += '<div class="rd-note"><i class="fas fa-circle-info"></i><span><strong>' + esc(R.paymentLabel(pm)) + ':</strong> ' + esc(pay[0]) + '. ' +
                (pay[1] === 'success' ? 'No cash to collect on delivery.' : 'Check the payment with the shop before handing over the order.') + '</span></div>';
        }

        if (o.needs_response) {
            html += '<div class="rd-note is-warn"><i class="fas fa-bell"></i><span><strong>The shop assigned this order to you.</strong> Accept it to deliver it, or decline so the shop can assign another rider.</span></div>';
        }
        if (st === 'processing' || st === 'ready_for_pickup' || st === 'pending' || st === 'confirmed') {
            html += '<div class="rd-note is-warn"><i class="fas fa-hourglass-half"></i><span>The shop is still preparing this order. You can pick it up once it is <strong>To Ship</strong>.</span></div>';
        } else if (o.is_available) {
            html += '<div class="rd-note"><i class="fas fa-hand"></i><span>Waiting for a rider. <strong>Accept</strong> it to get the customer’s details, then pick it up at the store and scan the parcel QR.</span></div>';
        } else if (o.is_unclaimed && next) {
            html += '<div class="rd-note"><i class="fas fa-hand"></i><span>No rider is assigned to this order yet. Scanning the customer’s QR assigns this delivery to you.</span></div>';
        }

        // Customer & delivery
        html += '<section class="rd-card"><h2 class="rd-card-title"><span class="rd-ico"><i class="fas fa-user"></i></span>Customer &amp; delivery</h2>' +
            '<dl class="rd-kv">' +
            row('Customer', mine ? esc(o.customer_name || '—') : '<span class="rd-muted">Shown after you accept</span>') +
            row('Contact', phone ? '<a href="' + telHref(phone) + '">' + esc(phone) + '</a>' : (mine ? '—' : '<span class="rd-muted">Shown after you accept</span>')) +
            (o.delivery_phone && o.delivery_phone !== o.customer_phone ? row('Delivery phone', '<a href="' + telHref(o.delivery_phone) + '">' + esc(o.delivery_phone) + '</a>') : '') +
            row('Address', esc(o.delivery_address || '—')) +
            (o.delivery_instructions ? row('Instructions', esc(o.delivery_instructions)) : '') +
            '</dl>' +
            '<div class="rd-btn-row" style="margin-top:14px;' + (mine && o.customer_id ? 'grid-template-columns:1fr 1fr 1fr;' : '') + '">' +
            (phone ? '<a class="rd-btn rd-btn-outline rd-btn-sm" href="' + telHref(phone) + '"><i class="fas fa-phone"></i> Call</a>' : '<button class="rd-btn rd-btn-outline rd-btn-sm" disabled><i class="fas fa-phone"></i> Call</button>') +
            '<a class="rd-btn rd-btn-outline rd-btn-sm" href="' + mapsUrl(o) + '" target="_blank" rel="noopener"><i class="fas fa-diamond-turn-right"></i> Navigate</a>' +
            (mine && o.customer_id ? '<a class="rd-btn rd-btn-outline rd-btn-sm" href="chat.php?customer_id=' + (+o.customer_id) + '&order=' + orderId + '"><i class="fas fa-comment"></i> Chat</a>' : '') +
            '</div></section>';

        // Order info
        html += '<section class="rd-card"><h2 class="rd-card-title"><span class="rd-ico"><i class="fas fa-receipt"></i></span>Order info</h2><dl class="rd-kv">' +
            row('Order no.', '#' + esc(o.order_number)) +
            row('Status', R.statusBadge(st)) +
            row('Placed', esc(R.fmtDate(o.created_at))) +
            row('Payment', esc(R.paymentLabel(pm))) +
            row('Payment status', '<span class="rd-badge rd-badge-' + pay[1] + '">' + esc(pay[0]) + '</span>') +
            row('Assigned rider', mine ? 'You' : (o.is_unclaimed ? 'Not yet claimed' : '—')) +
            (o.order_notes ? row('Order notes', esc(o.order_notes)) : '') +
            '</dl></section>';

        // Items
        var items = o.items || [];
        html += '<section class="rd-card"><h2 class="rd-card-title"><span class="rd-ico"><i class="fas fa-bag-shopping"></i></span>Items (' + items.length + ')</h2>';
        if (!items.length) {
            html += '<p class="rd-muted" style="margin:0;">No items found for this order.</p>';
        } else {
            html += '<ul class="rd-items">' + items.map(function (it) {
                return '<li><span class="rd-qty">×' + esc(it.quantity) + '</span><span class="rd-item-name">' + esc(it.product_name) +
                    '<small>' + (it.variant_size ? esc(it.variant_size) + ' · ' : '') + R.peso(it.unit_price) + ' each</small></span>' +
                    '<span class="rd-item-price">' + R.peso(it.total_price) + '</span></li>';
            }).join('') + '</ul>';
        }
        var extra = '';
        if (R.num(o.subtotal)) extra += '<div class="rd-sumline"><span class="rd-muted">Subtotal</span><span>' + R.peso(o.subtotal) + '</span></div>';
        if (R.num(o.delivery_fee)) extra += '<div class="rd-sumline"><span class="rd-muted">Delivery fee</span><span>' + R.peso(o.delivery_fee) + '</span></div>';
        if (R.num(o.discount_amount)) extra += '<div class="rd-sumline"><span class="rd-muted">Discount</span><span>−' + R.peso(o.discount_amount) + '</span></div>';
        if (R.num(o.tip)) extra += '<div class="rd-sumline"><span class="rd-muted">Tip</span><span>' + R.peso(o.tip) + '</span></div>';
        html += (extra ? '<div style="margin-top:8px;">' + extra + '</div>' : '') +
            '<div class="rd-total-row"><span>Total</span><strong>' + R.peso(o.total) + '</strong></div></section>';

        view.innerHTML = html;

        // Sticky actions: the rider's next QR step + the two parcel photos (pickup at the shop, delivery
        // at the customer). A ✓ shows once a photo is uploaded; taking it again replaces the shown one.
        var bar = document.getElementById('actionbar'), acts = document.getElementById('actions');
        if (o.needs_response) {
            acts.innerHTML = '<div class="rd-btn-row">' +
                '<button type="button" class="rd-btn rd-btn-outline rd-btn-danger rd-btn-lg" data-assign-decline="' + orderId + '"><i class="fas fa-xmark"></i> Decline</button>' +
                '<button type="button" class="rd-btn rd-btn-primary rd-btn-lg" data-assign-accept="' + orderId + '"><i class="fas fa-check"></i> Accept</button></div>';
            bar.hidden = false;
            document.body.classList.add('has-actionbar');
        } else if (o.is_available) {
            acts.innerHTML = '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" data-accept="' + orderId + '"><i class="fas fa-hand"></i> Accept Order</button>';
            bar.hidden = false;
            document.body.classList.add('has-actionbar');
        } else if (canAct) {
            var photoBtn = function (stage, done, label) {
                return '<button type="button" class="rd-btn ' + (done ? 'rd-btn-ghost' : 'rd-btn-outline') + ' rd-btn-sm" data-photo="' + stage + '">' +
                    '<i class="fas ' + (done ? 'fa-circle-check' : 'fa-camera') + '"' + (done ? ' style="color:var(--success);"' : '') + '></i> ' + label + '</button>';
            };
            var photos = (mine || o.is_unclaimed ? photoBtn('pickup', !!o.pickup_photo, 'Pickup photo') : '') +
                (mine ? photoBtn('delivery', !!o.delivery_photo, 'Delivery photo') : '');
            acts.innerHTML =
                '<a class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" href="scan.php?order=' + orderId + '"><i class="fas fa-qrcode"></i> Scan QR · ' + esc(next.action) + '</a>' +
                (photos ? '<div class="rd-btn-row">' + photos + '</div>' : '');
            bar.hidden = false;
            document.body.classList.add('has-actionbar');
        } else {
            bar.hidden = true;
            document.body.classList.remove('has-actionbar');
        }
    }

    function load() {
        return R.call('rider_orders_api.php?action=get_order_details&id=' + orderId).then(function (res) {
            order = res.data || {};
            render(order);
        }, function (err) {
            if (order) return; // keep the loaded order on a failed background refresh
            view.innerHTML = '<div class="rd-card">' + R.errorBox(err) + '</div>' +
                '<a class="rd-btn rd-btn-outline rd-btn-block" href="deliveries.php"><i class="fas fa-arrow-left"></i> Back to deliveries</a>';
        });
    }

    // ---- Pickup / delivery photos (shared sheet: Rider.photoSheet -> rider_proof_api.php) ----
    document.getElementById('actions').addEventListener('click', function (e) {
        var acc = e.target.closest('[data-accept]');
        if (acc) { R.acceptOrder(orderId, acc).then(load, load); return; }
        var ya = e.target.closest('[data-assign-accept]');
        if (ya) { R.respondAssignment(orderId, 'accept', ya).then(load, load); return; }
        var no = e.target.closest('[data-assign-decline]');
        if (no) {
            R.respondAssignment(orderId, 'decline', no, order && order.order_number).then(function (r) {
                if (r) window.location.href = 'dashboard.php'; // no longer this rider's order
            }, load);
            return;
        }
        var btn = e.target.closest('[data-photo]');
        if (!btn || !order) return;
        R.photoSheet(order, btn.getAttribute('data-photo')).then(function (res) { if (res) load(); });
    });
    view.addEventListener('click', function (e) { if (e.target.closest('[data-retry]')) { load(); } });

    load();
    R.pullToRefresh(load);
    R.live(load, 30000, 'order:' + orderId);
});
</script>
<?php rider_page_end('deliveries'); ?>
