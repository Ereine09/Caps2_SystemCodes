/**
 * Active Cart Activity — shared client for modules/admin/active_carts.php
 * and modules/staff/active_carts.php.
 *
 * Both pages hit the SAME modules/admin/active_carts_api.php (role-gated for
 * admin/staff, same as modules/admin/notifications_api.php already is) —
 * one API, one client script, no duplicated logic between the two portals.
 *
 * Real-time behavior: polling only (setInterval), the same "always-reliable"
 * pattern already used for Admin/Staff/Customer messaging on this project
 * (see modules/admin/messages.php's own setInterval(fetchMessages, 4000)).
 * No WebSocket/notification system was added for this feature - cart
 * mutations (add/update/remove/clear/checkout) aren't wired into any push
 * pipeline anywhere in the existing codebase, and instrumenting every one of
 * those call sites (customer/products.php, customer/cart.php,
 * customer/orders.php's Buy Again, customer/checkout.php) just to shave a
 * few seconds off a background admin report isn't worth the risk of
 * touching checkout/cart business logic that must stay untouched. Polling
 * every 8s keeps this a single lightweight indexed query per tick.
 */
(function () {
    var baseUrl = window.__DPS_BASE_URL || '';
    var apiUrl = baseUrl + '/modules/admin/active_carts_api.php';

    var tbody = document.getElementById('active-carts-tbody');
    var countLabel = document.getElementById('cart-count-label');
    var searchInput = document.getElementById('cart-search-input');
    var detailModal = document.getElementById('cart-detail-modal');
    var detailName = document.getElementById('cart-detail-name');
    var detailEmail = document.getElementById('cart-detail-email');
    var detailBody = document.getElementById('cart-detail-body');

    if (!tbody) return; // not on an Active Cart Activity page

    var latestCarts = [];

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function money(n) {
        return 'PHP ' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function timeAgo(isoString) {
        var seconds = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
        if (seconds < 15) return 'Just now';
        if (seconds < 60) return seconds + 's ago';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + (minutes === 1 ? ' minute ago' : ' minutes ago');
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
        var days = Math.floor(hours / 24);
        return days + (days === 1 ? ' day ago' : ' days ago');
    }

    function statusPill(status) {
        if (status === 'active') return '<span class="cart-status-pill cart-status-active">🟢 Active</span>';
        if (status === 'idle') return '<span class="cart-status-pill cart-status-idle">🟡 Idle</span>';
        // Never actually reached from this API (see active_carts_api.php's
        // HAVING clause), kept only so the status vocabulary is complete.
        return '<span class="cart-status-pill cart-status-abandoned">🔴 Abandoned</span>';
    }

    function render(carts) {
        var filter = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        var visible = filter
            ? carts.filter(function (c) {
                return (c.customer_name || '').toLowerCase().indexOf(filter) !== -1
                    || (c.customer_email || '').toLowerCase().indexOf(filter) !== -1;
            })
            : carts;

        if (countLabel) {
            countLabel.textContent = carts.length + (carts.length === 1 ? ' Active Cart' : ' Active Carts')
                + (filter && visible.length !== carts.length ? ' (' + visible.length + ' shown)' : '');
        }

        if (visible.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5"><div class="cart-empty-state"><i class="fas fa-cart-shopping"></i>'
                + (carts.length === 0 ? 'No customers currently have items in their cart.' : 'No customers match your search.')
                + '</div></td></tr>';
            return;
        }

        tbody.innerHTML = visible.map(function (c) {
            return '<tr data-customer-id="' + c.customer_id + '">'
                + '<td><div class="cart-customer-name">' + escapeHtml(c.customer_name) + '</div>'
                + '<div class="cart-customer-email">' + escapeHtml(c.customer_email) + '</div></td>'
                + '<td><div>' + c.product_count + (c.product_count === 1 ? ' product' : ' products') + '</div>'
                + '<div class="cart-items-summary">' + c.items_summary + '</div></td>'
                + '<td style="font-weight:700;">' + money(c.cart_total) + '</td>'
                + '<td>' + timeAgo(c.last_activity_iso) + '</td>'
                + '<td>' + statusPill(c.status) + '</td>'
                + '</tr>';
        }).join('');
    }

    function fetchActiveCarts() {
        fetch(apiUrl + '?action=get_list')
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result || !result.success) return;
                latestCarts = result.data.carts || [];
                render(latestCarts);
            })
            .catch(function () { /* keep showing the last good render */ });
    }

    function openCartDetail(customerId) {
        fetch(apiUrl + '?action=get_detail&customer_id=' + encodeURIComponent(customerId))
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result || !result.success) return;
                var d = result.data;
                detailName.textContent = d.customer.name;
                detailEmail.textContent = d.customer.email;

                if (!d.is_active || !d.items.length) {
                    detailBody.innerHTML = '<div class="cart-empty-state"><i class="fas fa-circle-check"></i>'
                        + 'This cart is no longer active — the customer checked out or cleared it.</div>';
                } else {
                    detailBody.innerHTML = d.items.map(function (item) {
                        var label = item.product_name + (item.size ? ' (' + item.size + ')' : '');
                        return '<div class="cart-modal-item">'
                            + '<div><div class="cart-modal-item-name">' + escapeHtml(label) + '</div>'
                            + '<div class="cart-modal-item-meta">' + money(item.unit_price) + ' &times; ' + item.quantity + '</div></div>'
                            + '<div class="cart-modal-item-subtotal">' + money(item.subtotal) + '</div>'
                            + '</div>';
                    }).join('') + '<div class="cart-modal-total"><span>Cart Total</span><span>' + money(d.cart_total) + '</span></div>';
                }

                detailModal.classList.add('open');
            })
            .catch(function () {});
    }

    window.closeCartDetailModal = function () {
        detailModal.classList.remove('open');
    };

    tbody.addEventListener('click', function (e) {
        var row = e.target.closest('tr[data-customer-id]');
        if (row) openCartDetail(row.getAttribute('data-customer-id'));
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () { render(latestCarts); });
    }

    window.addEventListener('click', function (e) {
        if (e.target === detailModal) window.closeCartDetailModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeCartDetailModal();
    });

    fetchActiveCarts();
    setInterval(fetchActiveCarts, 8000);
})();
