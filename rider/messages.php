<?php
/** Rider ↔ customer conversations (Flutter "Messages") — rider_messaging_api.php?action=get_conversations. */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Messages', ['back' => BASE_URL . '/rider/profile.php']);
?>
<div class="rd-stack">
    <p class="rd-help" style="margin:0 2px;">Customers you are delivering (or delivered) to.</p>
    <section class="rd-list" id="list" aria-live="polite"></section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var list = document.getElementById('list'), loaded = false;
    function load() {
        return R.call('rider_messaging_api.php?action=get_conversations').then(function (res) {
            var rows = (res.data && res.data.conversations) || [];
            loaded = true;
            list.innerHTML = rows.length ? rows.map(function (c) {
                var preview = c.last_message ? (c.last_sender === 'rider' ? 'You: ' : '') + c.last_message : 'No messages yet — say hello';
                return '<a class="rd-list-item" href="chat.php?customer_id=' + (+c.customer_id) + '">' +
                    '<span class="rd-avatar" style="width:42px;height:42px;font-size:.9rem;flex:none;">' + esc(R.initials(c.customer_name)) + '</span>' +
                    '<span><strong style="display:block;">' + esc(c.customer_name || 'Customer') + '</strong>' +
                    '<small style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(preview) + '</small></span>' +
                    (+c.unread_count ? '<span class="rd-badge rd-badge-danger no-dot">' + (+c.unread_count) + '</span>' : '<small class="rd-muted">' + esc(R.timeAgo(c.last_message_time)) + '</small>') +
                    '</a>';
            }).join('') : '<div class="rd-empty"><i class="fas fa-comments"></i><strong>No conversations yet</strong>Customers you deliver to appear here.</div>';
        }, function (err) { if (!loaded) list.innerHTML = R.errorBox(err); });
    }
    list.addEventListener('click', function (e) { if (e.target.closest('[data-retry]')) load(); });
    list.innerHTML = R.skeleton(3, 'rd-skel-line');
    load();
    R.pullToRefresh(load);
    R.live(load, 20000, 'messages');
});
</script>
<?php rider_page_end(''); ?>
