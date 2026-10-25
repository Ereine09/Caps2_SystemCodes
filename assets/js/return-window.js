/**
 * Floating Return / Refund window on the admin and staff Orders pages (Return / Refund tab) and Order Details.
 * A row, or a Review / Receive / Inspect / Resolve button, opens the request; every step runs here
 * through modules/shared/return_request_api.php, which uses the same rules as Order Details.
 * Markup: return_request_window() in modules/shared/order_return.php.
 */
(function () {
    var modal = document.getElementById('rrModal');
    if (!modal || modal.dataset.ready) return;
    modal.dataset.ready = '1';
    var body = document.getElementById('rrmBody'), title = document.getElementById('rrmTitle'), msg = document.getElementById('rrmMsg');
    var api = (window.__DPS_BASE_URL || '') + '/modules/shared/return_request_api.php';
    var current = 0, changed = false;

    function note(text, bad) {
        msg.hidden = !text;
        msg.textContent = text || '';
        msg.className = 'rrm-msg ' + (bad ? 'is-bad' : 'is-good');
    }
    var onOrderPage = /order_details\.php$/.test(location.pathname);
    function show(data) {
        title.textContent = data.title || 'Return';
        body.innerHTML = data.html || '<p>Nothing to show.</p>';
        // Already on this order's page: the "Full order" link would only reload it.
        var full = body.querySelector('.rrm-full');
        if (full && onOrderPage) full.remove();
    }
    function open(id) {
        current = id;
        note('');
        title.textContent = 'Return #' + id;
        body.innerHTML = '<p class="rrm-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        fetch(api + '?id=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.html) show(d); else note(d.message || 'Could not load this request.', true); })
            .catch(function () { note('Could not load this request. Check your connection.', true); });
    }
    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (/^#return-\d+$/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search); // don't reopen on reload
        if (changed) {
            // Order Details refreshes in place (no reload); other pages reload so lists and badges follow.
            if (window.dpsLiveRefresh) window.dpsLiveRefresh(); else location.reload();
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.rrl-open');
        var row = btn ? null : e.target.closest('tr.rrl-row');
        if (row && e.target.closest('a, button, input, select, textarea')) row = null; // links (order number, evidence) keep working
        var id = btn ? btn.getAttribute('data-rr-id') : (row ? row.getAttribute('data-rr-id') : null);
        if (id) {
            e.preventDefault();
            open(parseInt(id, 10));
        }
    });
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-rrm-close]')) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    // A link to a request (order_details.php?id=..#return-3, e.g. from a notification) opens it right away.
    var hash = /^#return-(\d+)$/.exec(location.hash);
    if (hash && document.querySelector('[data-rr-id="' + hash[1] + '"]')) open(parseInt(hash[1], 10));

    // Approve, reject, receive, inspect, refund, replacement: all without leaving the page.
    // The forms' own confirm() prompts run first (inline handlers); a "Cancel" there stops the step.
    body.addEventListener('submit', function (e) {
        var form = e.target.closest('form.or-form');
        if (!form) return;
        var cancelled = e.defaultPrevented;
        e.preventDefault();
        if (cancelled) return;
        var fd = new FormData(form);
        if (e.submitter && e.submitter.name) fd.set(e.submitter.name, e.submitter.value);
        fd.set('id', current);
        var buttons = form.querySelectorAll('button');
        buttons.forEach(function (b) { b.disabled = true; });
        fetch(api, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.html) show(d);
                note(d.message || (d.success ? 'Saved.' : 'Could not save.'), !d.success);
                if (d.success) changed = true;
            })
            .catch(function () { note('Could not save. Check your connection and try again.', true); })
            .then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
    });
})();
