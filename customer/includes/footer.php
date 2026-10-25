    </div><!-- /.customer-page -->
</div><!-- /.customer-main-content -->
<?php
// Floating "Contact Staff" shortcut on every customer page except the pages
// where it would be redundant: the chat itself, Message Rider (its own chat),
// and the Dashboard (which already has its own "Contact Staff" button inline
// in Order Status Summary).
$__hide_contact_fab_on = ['messages.php', 'dashboard.php', 'rider_chat.php'];
if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $__hide_contact_fab_on, true)):
?>
<a class="contact-staff-fab" href="<?php echo BASE_URL; ?>/customer/messages.php">
    <i class="fas fa-headset"></i> <span>Contact Staff</span>
</a>
<?php endif; ?>
<div id="toasts" class="toast-stack"></div>
<!-- Confirm logout: centered dialog (ui-kit .ui-modal-confirm) when any link to logout.php is clicked.
     It posts to logout.php, which does the actual logout (and stays the no-JS fallback page). -->
<div class="ui-modal ui-modal-confirm" id="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title" aria-describedby="logout-modal-text" hidden>
    <div class="ui-modal-dialog">
        <div class="ui-confirm-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></div>
        <h2 class="ui-modal-title" id="logout-modal-title">Logout</h2>
        <p class="ui-confirm-text" id="logout-modal-text">Are you sure you want to log out of your account?</p>
        <form id="logout-form" method="POST" action="<?php echo BASE_URL; ?>/customer/logout.php" hidden>
            <?php echo csrf_field(); ?>
        </form>
        <div class="ui-modal-foot">
            <button type="button" class="ui-btn" data-logout-close>Cancel</button>
            <button type="submit" form="logout-form" name="confirm_logout" value="1" class="ui-btn ui-btn-danger"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('logout-modal');
    if (!modal) return;
    var form = document.getElementById('logout-form');
    var yes = modal.querySelector('button[type=submit]');
    var lastFocus = null;
    function open(e) {
        e.preventDefault();
        lastFocus = document.activeElement;
        modal.hidden = false;
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        modal.querySelector('[data-logout-close]').focus(); // safe default for a sign-out prompt
    }
    function close() {
        modal.classList.remove('open');
        modal.hidden = true;
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    // Delegated, so logout links inside live-swapped regions work too.
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href$="/customer/logout.php"]') : null;
        if (a) open(e);
    });
    modal.addEventListener('click', function (e) {
        // Backdrop click closes, like the other ui-kit modals.
        if (e.target === modal || e.target.closest('[data-logout-close]')) close();
    });
    form.addEventListener('submit', function () {
        yes.classList.add('is-loading'); // one click = one logout request
    });
    // Coming Back to this page from the browser's page cache: never show a stale dialog.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && !modal.hidden) { yes.classList.remove('is-loading'); close(); }
    });
    document.addEventListener('keydown', function (e) {
        if (modal.hidden) return;
        if (e.key === 'Escape') { close(); return; }
        if (e.key === 'Tab') { // keep focus inside the dialog
            var f = modal.querySelectorAll('button:not([disabled])');
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
})();
</script>
<script defer src="<?php echo BASE_URL; ?>/assets/js/nav-toggle.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/nav-toggle.js') ?: time(); ?>"></script>
<script defer src="<?php echo BASE_URL; ?>/assets/js/return-spot.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/return-spot.js') ?: time(); ?>"></script>
<script>
// The sidebar Cart badge mirrors the top-bar one (#cart-count-badge), which is the one that
// products.php, cart.php and the mini cart update.
(function () {
    var top = document.getElementById('cart-count-badge');
    var side = document.getElementById('sidebar-cart-badge');
    if (!top || !side || !window.MutationObserver) return;
    new MutationObserver(function () {
        side.textContent = top.textContent;
        side.style.display = top.style.display;
    }).observe(top, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['style'] });
})();
</script>
<script>
// Floating mini cart (top-bar cart icon). Loads fresh from cart_mini_api.php every time it opens,
// so items added on the shop page show up without a reload. On the cart page itself the icon
// just stays a link (that page already shows everything).
(function () {
    var wrap = document.getElementById('mini-cart-wrap');
    if (!wrap) return;
    var btn = document.getElementById('mini-cart-btn');
    var panel = document.getElementById('mini-cart-panel');
    var body = document.getElementById('mini-cart-body');
    var foot = document.getElementById('mini-cart-foot');
    var totalEl = document.getElementById('mini-cart-total');
    var qtyEl = document.getElementById('mini-cart-qty');
    var badge = document.getElementById('cart-count-badge');
    var api = <?php echo json_encode(BASE_URL . '/customer/cart_mini_api.php'); ?>;
    var shopUrl = <?php echo json_encode(BASE_URL . '/customer/products.php'); ?>;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    if (/\/customer\/cart\.php$/.test(location.pathname)) return;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function money(n) {
        return 'PHP ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function setBadge(count) {
        if (!badge) return;
        badge.textContent = count;
        badge.style.display = count > 0 ? '' : 'none';
    }
    function render(data) {
        setBadge(data.count);
        qtyEl.textContent = data.count > 0 ? '· ' + data.count + ' item' + (data.count === 1 ? '' : 's') : '';
        if (!data.items.length) {
            body.innerHTML = '<div class="notif-panel-empty"><i class="fas fa-cart-arrow-down"></i>'
                + '<p style="margin:0;">Your cart is empty</p>'
                + '<a class="mini-cart-empty-cta" href="' + esc(shopUrl) + '">Browse the shop <i class="fas fa-arrow-right"></i></a></div>';
            foot.hidden = true;
            return;
        }
        body.innerHTML = data.items.map(function (it) {
            return '<div class="mini-cart-item">'
                + '<img src="' + esc(it.image) + '" alt="" loading="lazy">'
                + '<div class="mini-cart-info"><p class="mini-cart-name" title="' + esc(it.name) + '">' + esc(it.name) + '</p>'
                + '<p class="mini-cart-meta">' + it.quantity + ' × ' + money(it.unit_price) + '</p></div>'
                + '<span class="mini-cart-line">' + money(it.line_total) + '</span>'
                + '<button type="button" class="mini-cart-remove" data-product="' + it.product_id + '" data-variant="' + it.variant_id + '" aria-label="Remove ' + esc(it.name) + '" title="Remove"><i class="fas fa-trash"></i></button>'
                + '</div>';
        }).join('');
        totalEl.textContent = money(data.subtotal_gross);
        foot.hidden = false;
    }
    function fail(msg) {
        body.innerHTML = '<div class="notif-panel-empty"><i class="fas fa-exclamation-circle"></i><p style="margin:0;">' + esc(msg || 'Could not load your cart.') + '</p></div>';
        foot.hidden = true;
    }
    function load(options) {
        return fetch(api, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}))
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data && data.success) render(data); else fail(data && data.message); })
            .catch(function () { fail(); });
    }

    function open() {
        var notif = document.getElementById('notif-panel');
        if (notif) notif.classList.remove('open');
        panel.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        load();
    }
    function close() {
        panel.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        panel.classList.contains('open') ? close() : open();
    });
    document.getElementById('mini-cart-close').addEventListener('click', close);
    document.addEventListener('click', function (e) {
        if (panel.classList.contains('open') && !wrap.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) { close(); btn.focus(); }
    });
    // Opening the notifications closes the cart.
    var bell = document.getElementById('notif-bell-btn');
    if (bell) bell.addEventListener('click', close);

    body.addEventListener('click', function (e) {
        var rm = e.target.closest('.mini-cart-remove');
        if (!rm) return;
        e.stopPropagation();
        rm.disabled = true;
        var fd = new FormData();
        fd.append('action', 'remove');
        fd.append('product_id', rm.dataset.product);
        fd.append('variant_id', rm.dataset.variant);
        fd.append('csrf_token', csrf);
        load({ method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } });
    });
})();
</script>
<script>
(function () {
    var wrap = document.getElementById('notif-bell-wrap');
    if (!wrap) return;
    var btn = document.getElementById('notif-bell-btn');
    var panel = document.getElementById('notif-panel');
    var body = document.getElementById('notif-panel-body');
    var markAllBtn = document.getElementById('notif-panel-markall');
    var badge = document.getElementById('notif-bell-badge');

    function esc(s) {
        s = String(s == null ? '' : s);
        s = s.replace(/&/g, String.fromCharCode(38) + 'amp;');
        s = s.replace(/</g, String.fromCharCode(38) + 'lt;');
        s = s.replace(/>/g, String.fromCharCode(38) + 'gt;');
        s = s.replace(/"/g, String.fromCharCode(38) + 'quot;');
        s = s.replace(/'/g, String.fromCharCode(38) + '#39;');
        return s;
    }
    function iconFor(type) {
        if (type === 'order_accepted') return 'fas fa-motorcycle';
        if (type === 'order_status_update' || type === 'order_confirmed' || type === 'order_completed' || type === 'order_cancelled') return 'fas fa-shopping-bag';
        if (type === 'points_earned') return 'fas fa-star';
        if (type === 'rider_message') return 'fas fa-comment-dots';
        if (type === 'ORDER') return 'fas fa-shopping-bag';
        return 'fas fa-info-circle';
    }
    function fmtTime(ts) {
        if (!ts) return '';
        var d = new Date(ts.replace(' ', 'T'));
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function renderList(items) {
        if (!items || items.length === 0) {
            body.innerHTML = '<div class="notif-panel-empty"><i class="fas fa-bell-slash"></i><p style="margin:0;">No notifications yet</p></div>';
            return;
        }
        var html = '';
        items.forEach(function (n) {
            var unread = String(n.is_read) !== '1';
            var href = 'notifications.php';
            if (n.reference_table === 'rider_chat') {
                href = 'rider_chat.php';
            } else if (n.reference_table === 'tbl_orders' && Number(n.reference_id) > 0) {
                href = 'orders.php?id=' + encodeURIComponent(n.reference_id);
            }
            html += '<a href="' + esc(href) + '" class="notif-panel-item' + (unread ? ' unread' : '') + '" data-notification-id="' + Number(n.id) + '" style="text-decoration:none;">' +
                '<div class="notif-panel-icon"><i class="' + iconFor(n.type) + '"></i></div>' +
                '<div style="flex:1;min-width:0;">' +
                    '<p class="notif-panel-title">' + esc(n.title) + '</p>' +
                    '<p class="notif-panel-msg">' + esc(n.message) + '</p>' +
                    '<span class="notif-panel-time">' + esc(fmtTime(n.created_at)) + '</span>' +
                '</div>' +
            '</a>';
        });
        body.innerHTML = html;
        body.querySelectorAll('[data-notification-id]').forEach(function (item) {
            item.addEventListener('click', function () {
                if (String(item.classList.contains('unread')) !== 'true') return;
                fetch('notifications_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
                    body: JSON.stringify({ action: 'mark_read', notification_id: Number(item.dataset.notificationId) }),
                    keepalive: true
                }).catch(function () {});
            });
        });
        // The list holds only the latest 10, so ask the poller for the real unread total.
        if (window.dpsNotifRefresh) window.dpsNotifRefresh();
    }

    // Every [data-notif-count] badge is kept in sync by assets/js/toast.js.
    function updateBadge(count) {
        if (window.dpsSetNotifCount) {
            window.dpsSetNotifCount(count);
        } else if (badge) {
            badge.textContent = count;
            badge.hidden = !(count > 0);
        }
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = panel.classList.contains('open');
        if (isOpen) {
            panel.classList.remove('open');
        } else {
            panel.classList.add('open');
            fetch('notifications_api.php?action=get_list&limit=10', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        renderList(data.data.notifications);
                    } else {
                        body.innerHTML = '<div class="notif-panel-empty"><i class="fas fa-exclamation-triangle"></i><p style="margin:0;">Could not load notifications.</p></div>';
                    }
                })
                .catch(function () {
                    body.innerHTML = '<div class="notif-panel-empty"><i class="fas fa-exclamation-triangle"></i><p style="margin:0;">Could not load notifications.</p></div>';
                });
        }
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) {
            panel.classList.remove('open');
        }
    });

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            markAllBtn.disabled = true;
            fetch('notifications_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
                body: JSON.stringify({ action: 'mark_all_read' })
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        body.querySelectorAll('.notif-panel-item.unread').forEach(function (el) { el.classList.remove('unread'); });
                        markAllBtn.style.display = 'none';
                        updateBadge(0);
                    }
                })
                .catch(function () {})
                .finally(function () { markAllBtn.disabled = false; });
        });
    }
})();
</script>
</body>
</html>
