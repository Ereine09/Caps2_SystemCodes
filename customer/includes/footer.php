</div>
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
            if (n.reference_table === 'tbl_orders' && Number(n.reference_id) > 0) {
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
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'mark_read', notification_id: Number(item.dataset.notificationId) }),
                    keepalive: true
                }).catch(function () {});
            });
        });
        updateBadge(items.filter(function (item) { return String(item.is_read) !== '1'; }).length);
    }

    function updateBadge(count) {
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.id = 'notif-bell-badge';
                badge.className = 'notif-bell-badge';
                btn.appendChild(badge);
            }
            badge.textContent = count;
            badge.style.display = 'flex';
        } else if (badge) {
            badge.remove();
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
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
