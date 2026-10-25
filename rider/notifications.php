<?php
/** Rider notifications (Flutter Notifications screen) — rider_notifications_api.php. */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Notifications', ['back' => BASE_URL . '/rider/dashboard.php']);
?>
<div class="rd-stack">
    <div style="display:flex;justify-content:flex-end;">
        <button type="button" class="rd-btn rd-btn-outline rd-btn-sm" id="mark-all" disabled><i class="fas fa-check-double"></i> Mark all as read</button>
    </div>
    <section class="rd-list" id="list" aria-live="polite"></section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var list = document.getElementById('list'), btn = document.getElementById('mark-all');
    var loaded = false;
    var ICON = { rider_reminder: 'fa-money-bill-wave', delivery_assigned: 'fa-box', remittance_submitted: 'fa-wallet', remittance_verified: 'fa-circle-check', remittance_discrepancy: 'fa-scale-unbalanced', remittance_rejected: 'fa-triangle-exclamation', customer_message: 'fa-comment', return_pickup: 'fa-rotate-left' };

    function load() {
        return R.call('rider_notifications_api.php?action=get_list&limit=20').then(function (res) {
            var rows = (res.data && res.data.notifications) || [];
            loaded = true;
            btn.disabled = !rows.some(function (n) { return +n.is_read === 0; });
            list.innerHTML = rows.length ? rows.map(function (n) {
                // Tap: opens what it is about (its order, the customer's chat, the remittance, the return pickup).
                var link = n.link && n.link.indexOf('/rider/notifications.php') === -1 ? n.link : '';
                var tag = link ? 'a' : 'div';
                return '<' + tag + ' class="rd-notif' + (+n.is_read === 0 ? ' is-unread' : '') + (link ? ' is-link' : '') + '"' +
                    (link ? ' href="' + esc(link) + '"' : '') + ' data-id="' + (+n.id) + '"><span class="rd-notif-dot" aria-hidden="true"></span>' +
                    '<div class="rd-notif-body"><strong><i class="fas ' + (ICON[n.type] || 'fa-bell') + '" style="color:var(--muted);margin-right:6px;"></i>' + esc(n.title) + '</strong>' +
                    '<p>' + esc(n.message) + '</p><time>' + esc(R.timeAgo(n.created_at)) + '</time></div>' +
                    (link ? '<i class="fas fa-chevron-right rd-notif-go" aria-hidden="true"></i>' : '') + '</' + tag + '>';
            }).join('') : '<div class="rd-empty"><i class="fas fa-bell-slash"></i><strong>No notifications yet</strong>New assignments and remittance updates show up here.</div>';
        }, function (err) {
            if (!loaded) list.innerHTML = R.errorBox(err);
        });
    }
    btn.addEventListener('click', function () {
        R.guarded(btn, 'Marking…', function () {
            return R.call('rider_notifications_api.php', { body: { action: 'mark_all_read' } });
        }).then(function () {
            if (window.dpsSetNotifCount) window.dpsSetNotifCount(0);
            return load();
        }, R.fail);
    });
    list.addEventListener('click', function (e) {
        if (e.target.closest('[data-retry]')) { load(); return; }
        var item = e.target.closest('.rd-notif');
        if (!item || !item.classList.contains('is-unread')) return; // read ones just follow their link
        // Unread: mark it read first (so the badge drops), then open it. Opens anyway if that fails.
        e.preventDefault();
        item.classList.remove('is-unread');
        var go = function () { if (item.href) window.location.href = item.href; };
        R.call('rider_notifications_api.php', { body: { action: 'mark_read', id: +item.getAttribute('data-id') }, timeout: 4000 }).then(function (res) {
            if (window.dpsSetNotifCount && res.data) window.dpsSetNotifCount(+res.data.unread_count || 0);
            btn.disabled = !list.querySelector('.rd-notif.is-unread');
        }, function () {}).then(go);
    });
    list.innerHTML = R.skeleton(4, 'rd-skel-line');
    load();
    R.pullToRefresh(load);
    R.live(load, 30000, 'notifications');
});
</script>
<?php rider_page_end(''); ?>
