/**
 * toast.js — the one toast component for every portal + the notification poller.
 *
 *   toast(message, type = 'ok' | 'error' | 'info' | 'warn', { title, link, linkText, duration })
 *
 * Toasts stack bottom-right in #toasts (.toast-stack, styled in assets/css/ui-kit.css)
 * and fade out on their own (3.5s normal, 6s errors, or `duration`). All text is set
 * with textContent — never innerHTML — so message data can't inject markup.
 * LiveUI.toast (assets/js/live-ui.js) forwards here, so there is a single toast system.
 *
 * Poller: reads api/notifications_poll.php (the existing notifications table).
 * First call gets latest_id only (old notifications are never re-toasted on page
 * load); then every 20s it asks for rows after that id, toasts each (oldest first),
 * and updates every [data-notif-count] badge. Paused while the tab is hidden, polls
 * right away when it becomes visible, and stops on 401. A WebSocket push (when the
 * chat server is running) triggers an immediate poll via the 'dps:live-poke' event.
 *
 * Needs window.__DPS_PORTAL ('admin' | 'staff' | 'rider' | 'customer') and window.__DPS_BASE_URL, set by
 * the shared sidebar/header partials. Idempotent if included twice.
 */
(function (w, d) {
    'use strict';
    if (w.toast && w.toast.__dps) return;

    var DEFAULT_MS = 3500, ERROR_MS = 6000, MAX_VISIBLE = 4;
    var TONES = { ok: 'ok', success: 'ok', error: 'error', danger: 'error', warn: 'warn', warning: 'warn', info: 'info' };
    var ICON_PATHS = {
        ok: 'M20 6 9 17l-5-5',
        error: 'M18 6 6 18M6 6l12 12',
        warn: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
        info: 'M12 16v-4m0-4h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z'
    };
    var SVG_NS = 'http://www.w3.org/2000/svg';

    function stack() {
        var box = d.getElementById('toasts');
        if (!box) {
            box = d.createElement('div');
            box.id = 'toasts';
            box.className = 'toast-stack';
            (d.body || d.documentElement).appendChild(box);
        }
        return box;
    }

    function icon(tone) {
        var svg = d.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', 'toast-icon');
        var p = d.createElementNS(SVG_NS, 'path');
        p.setAttribute('d', ICON_PATHS[tone]);
        svg.appendChild(p);
        return svg;
    }

    function toast(message, type, opts) {
        opts = opts || {};
        message = String(message == null ? '' : message).trim();
        var title = opts.title ? String(opts.title).trim() : '';
        if (!message && !title) return null;
        var tone = TONES[type] || 'ok';
        var ms = opts.duration || (tone === 'error' || tone === 'warn' ? ERROR_MS : DEFAULT_MS);
        var box = stack();
        var key = tone + '|' + title + '|' + message;

        // Same toast already showing: just restart its timer instead of stacking a copy.
        for (var i = 0; i < box.children.length; i++) {
            var existing = box.children[i];
            if (existing._key === key && !existing.classList.contains('is-leaving')) {
                existing._restart(ms);
                return existing;
            }
        }

        var el = d.createElement('div');
        el.className = 'toast toast-' + tone;
        el.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        el.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        el.setAttribute('aria-atomic', 'true');
        el._key = key;

        el.appendChild(icon(tone));
        var body = d.createElement('div');
        body.className = 'toast-body';
        if (title) {
            var t = d.createElement('strong');
            t.className = 'toast-title';
            t.textContent = title;
            body.appendChild(t);
        }
        if (message) {
            var m = d.createElement('span');
            m.className = 'toast-msg';
            m.textContent = message;
            body.appendChild(m);
        }
        if (opts.link) {
            var href = String(opts.link);
            // Only same-site relative/absolute paths or http(s) URLs — never javascript: etc.
            if (/^(\/(?!\/)|https?:\/\/)/i.test(href)) {
                var a = d.createElement('a');
                a.className = 'toast-link';
                a.href = href;
                a.textContent = opts.linkText || 'View';
                body.appendChild(a);
            }
        }
        el.appendChild(body);

        var close = d.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.textContent = '×';
        el.appendChild(close);

        var timer = null, remaining = ms, startedAt = 0;
        function dismiss() {
            clearTimeout(timer);
            if (el.classList.contains('is-leaving')) return;
            el.classList.add('is-leaving');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
        }
        function start(duration) {
            clearTimeout(timer);
            remaining = duration;
            startedAt = Date.now();
            timer = setTimeout(dismiss, duration);
        }
        el._restart = start;
        el._dismiss = dismiss;
        // Pause while hovered/focused so a toast can actually be read or clicked.
        function pause() { clearTimeout(timer); remaining = Math.max(1200, remaining - (Date.now() - startedAt)); }
        function resume() { start(remaining); }
        el.addEventListener('mouseenter', pause);
        el.addEventListener('mouseleave', resume);
        el.addEventListener('focusin', pause);
        el.addEventListener('focusout', resume);
        close.addEventListener('click', dismiss);

        box.appendChild(el);
        var visible = Array.prototype.filter.call(box.children, function (c) { return !c.classList.contains('is-leaving'); });
        for (var v = 0; v < visible.length - MAX_VISIBLE; v++) {
            if (visible[v]._dismiss) visible[v]._dismiss(); else box.removeChild(visible[v]);
        }
        start(ms);
        return el;
    }
    toast.__dps = true;
    w.toast = toast;
    // For feedback that used to be alert(): same call shape, toast when available.
    w.toastOr = function (message, type, opts) { return toast(message, type, opts); };

    // ---- badge counts ---------------------------------------------------------
    function setNotifCount(n) {
        n = Math.max(0, parseInt(n, 10) || 0);
        var els = d.querySelectorAll('[data-notif-count]');
        for (var i = 0; i < els.length; i++) {
            els[i].textContent = n > 99 ? '99+' : String(n);
            els[i].hidden = n === 0;
        }
    }
    w.dpsSetNotifCount = setNotifCount;

    // ---- poller -------------------------------------------------------------------
    // 3s keeps new-order/message toasts and the sidebar badges near real time on every page (no WS server on shared
    // hosting); polls pause while the tab is hidden, so idle tabs cost nothing.
    var POLL_MS = 3000, NOTIF_MS = 9000;
    var portal = w.__DPS_PORTAL;
    var endpoint = (w.__DPS_BASE_URL || '') + '/api/notifications_poll.php';
    var latestId = null, stopped = false, inflight = false, interval = null;

    function poll(force) {
        if (!portal || stopped || inflight) return;
        if (d.hidden && !force) return;
        inflight = true;
        var url = endpoint + '?portal=' + encodeURIComponent(portal) + (latestId !== null ? '&after=' + latestId : '');
        fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (res.status === 401) { stop(); return null; } // logged out: stop instead of looping
                return res.ok ? res.json() : null;
            })
            .then(function (data) {
                if (!data || !data.ok) return;
                if (latestId !== null && data.new && data.new.length) {
                    data.new.forEach(function (n) {
                        if (n.id > latestId) toast(n.message, n.type, { title: n.title, link: n.link, duration: NOTIF_MS });
                    });
                    // Pages with live lists (e.g. the Rider Portal's deliveries) refresh on this.
                    try { d.dispatchEvent(new CustomEvent('dps:notifications-new', { detail: data.new })); } catch (e) {}
                }
                setNotifCount(data.unread);
                if (data.badges) {
                    // Admin/staff sidebar: Orders and Remittance counts, live on every page.
                    Object.keys(data.badges).forEach(function (k) {
                        var el = d.getElementById('sidebar-' + k + '-badge'), n = Number(data.badges[k]) || 0;
                        if (!el) return;
                        el.textContent = String(n);
                        el.style.display = n > 0 ? '' : 'none';
                    });
                }
                if (data.nav) {
                    // Reviews / E-Bike counts: unread notifications, until that page is opened.
                    Object.keys(data.nav).forEach(function (k) {
                        var el = d.querySelector('[data-nav-count="' + k + '"]'), n = Number(data.nav[k]) || 0;
                        if (!el) return;
                        el.textContent = n > 99 ? '99+' : String(n);
                        el.style.display = n > 0 ? '' : 'none';
                    });
                }
                if (typeof data.latest_id === 'number' && (latestId === null || data.latest_id > latestId)) latestId = data.latest_id;
            })
            .catch(function () { /* offline / server hiccup: try again next tick */ })
            .then(function () { inflight = false; });
    }
    function stop() {
        stopped = true;
        if (interval) clearInterval(interval);
    }
    // Other scripts (e.g. the customer bell after "mark all read") can ask for a fresh count.
    w.dpsNotifRefresh = function () { poll(true); };

    function startPolling() {
        if (!portal) return;
        poll(true);
        interval = setInterval(poll, POLL_MS);
        d.addEventListener('visibilitychange', function () { if (!d.hidden) poll(); });
        // Real-time push from the existing WebSocket relay (nav-toggle.js): check right away.
        d.addEventListener('dps:live-poke', function () { poll(true); });
    }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', startPolling);
    else startPolling();
})(window, document);
