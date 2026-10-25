/**
 * DPS Web Rider Portal — shared client helpers.
 *
 * Every call goes to the EXISTING rider APIs in modules/rider/ with the rider's login
 * cookie (same-origin). The server decides who the rider is and what they may do;
 * nothing here sends a rider id, and ids sent for orders are re-checked server-side.
 */
(function (w, d) {
    'use strict';

    var BASE = w.__DPS_BASE_URL || '';
    var API = BASE + '/modules/rider/';
    var CONN_MSG = 'Connection lost. Please check your internet connection and try again.';

    // ---- API -----------------------------------------------------------------------
    function RiderError(message, extra) {
        this.message = message;
        this.network = !!(extra && extra.network);
        this.status = extra && extra.status;
        this.data = extra && extra.data;
    }

    /**
     * call('rider_orders_api.php')                                   -> GET
     * call('rider_status_api.php', { method: 'POST', body: {...} })  -> POST JSON
     * Resolves with the API's JSON ({ success, message, data }); rejects with RiderError.
     */
    function call(path, opts) {
        opts = opts || {};
        var method = opts.method || (opts.body ? 'POST' : 'GET');
        var ctrl = w.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, opts.timeout || 25000) : null;
        var headers = { Accept: 'application/json' };
        if (opts.body) headers['Content-Type'] = 'application/json';
        if (method.toUpperCase() !== 'GET' && w.__DPS_CSRF_TOKEN) {
            headers['X-CSRF-Token'] = w.__DPS_CSRF_TOKEN;
        }

        return fetch(API + path, {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers,
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { /* not JSON */ }
                if (!data) throw new RiderError('The server returned an unexpected response. Please try again.', { status: res.status });
                if (!data.success) {
                    var msg = data.message || 'Something went wrong. Please try again.';
                    // Session gone (logged out, expired, password changed): back to the login page.
                    if (/unauthori[sz]ed (access|role|token)|invalid rider (account|user)/i.test(msg)) {
                        w.location.href = BASE + '/rider/login.php?next=' + encodeURIComponent(w.location.pathname.split('/').pop() + w.location.search);
                    }
                    throw new RiderError(msg, { status: res.status, data: data });
                }
                return data;
            });
        }, function () {
            throw new RiderError(CONN_MSG, { network: true });
        }).then(function (data) {
            if (timer) clearTimeout(timer);
            return data;
        }, function (err) {
            if (timer) clearTimeout(timer);
            throw err instanceof RiderError ? err : new RiderError(CONN_MSG, { network: true });
        });
    }

    function notify(message, type, opts) {
        if (typeof w.toast === 'function') w.toast(message, type || 'info', opts || {});
        else w.alert(message);
    }
    function fail(err) {
        notify((err && err.message) || CONN_MSG, 'error', { title: err && err.network ? 'No connection' : 'Could not complete' });
    }

    // ---- Formatting ----------------------------------------------------------------
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
    function peso(v) {
        return '₱' + num(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function parseDate(s) {
        if (!s || /^0000/.test(s)) return null;
        var dt = new Date(String(s).replace(' ', 'T'));
        return isNaN(dt) ? null : dt;
    }
    function fmtDate(s, withTime) {
        var dt = parseDate(s);
        if (!dt) return '—';
        var o = { month: 'short', day: 'numeric', year: 'numeric' };
        if (withTime !== false) { o.hour = 'numeric'; o.minute = '2-digit'; }
        return dt.toLocaleString('en-PH', o);
    }
    function timeAgo(s) {
        var dt = parseDate(s);
        if (!dt) return '';
        var sec = Math.round((Date.now() - dt.getTime()) / 1000);
        if (sec < 60) return 'Just now';
        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
        if (sec < 604800) return Math.floor(sec / 86400) + 'd ago';
        return fmtDate(s, false);
    }
    function isToday(s) {
        var dt = parseDate(s), n = new Date();
        return !!dt && dt.getFullYear() === n.getFullYear() && dt.getMonth() === n.getMonth() && dt.getDate() === n.getDate();
    }
    function initials(name) {
        var p = String(name || '').trim().split(/\s+/).filter(Boolean);
        return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
    }

    // ---- Order vocabulary (the system's existing status values) --------------------
    var STATUS = {
        pending: ['Pending', 'warning', 'fa-clock'],
        confirmed: ['Confirmed', 'info', 'fa-check'],
        processing: ['Processing', 'primary', 'fa-box-open'],
        ready_for_pickup: ['Ready for Pickup', 'accent', 'fa-store'],
        to_ship: ['To Ship', 'accent', 'fa-box'],
        to_receive: ['To Receive', 'info', 'fa-hand-holding'],
        out_for_delivery: ['Out for Delivery', 'info', 'fa-truck-fast'],
        completed: ['Completed', 'success', 'fa-circle-check'],
        cancelled: ['Cancelled', 'danger', 'fa-circle-xmark']
    };
    function statusMeta(s) {
        s = String(s || '').toLowerCase();
        return STATUS[s] || [s.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) || 'Unknown', 'neutral', 'fa-circle'];
    }
    function statusBadge(s) {
        var m = statusMeta(s);
        return '<span class="rd-badge rd-badge-' + m[1] + '">' + esc(m[0]) + '</span>';
    }

    /**
     * The rider's QR-confirmed steps — the same map rider_update_status_api.php enforces.
     * Two scans: at the store (To Ship -> Out for Delivery), then at the customer (-> Completed).
     * To Receive only appears on older orders or ones staff moved by hand; one scan moves it on.
     */
    var NEXT = {
        to_ship: { status: 'out_for_delivery', action: 'Picked Up · Start Delivery', icon: 'fa-truck-fast', pickup: true, text: 'You have the parcel from the store. The order goes Out for Delivery and the customer is told you are on the way.' },
        to_receive: { status: 'out_for_delivery', action: 'Start Delivery', icon: 'fa-truck-fast', text: 'You are leaving for the customer. The order moves to Out for Delivery.' },
        out_for_delivery: { status: 'completed', action: 'Confirm Delivery', icon: 'fa-circle-check', text: 'The customer received the order. The order moves to Completed.' }
    };

    function paymentLabel(m) {
        m = String(m || '').toLowerCase();
        return { cod: 'Cash on Delivery', gcash: 'GCash', bank: 'Bank Transfer', pay_at_shop: 'Pay at Shop' }[m] || (m ? m.toUpperCase() : '—');
    }
    function paymentShort(m) {
        m = String(m || '').toLowerCase();
        return { cod: 'COD', gcash: 'GCash', bank: 'Bank', pay_at_shop: 'Pay at shop' }[m] || (m ? m.toUpperCase() : '');
    }
    /** Display-only payment state — same wording rule as the admin order page (modules/admin/order_details.php). */
    function paymentState(o) {
        var st = String(o.order_status || '').toLowerCase();
        var pm = String(o.payment_method || '').toLowerCase();
        if (st === 'cancelled') return ['Cancelled', 'neutral'];
        if (pm === 'gcash' || pm === 'bank') return st === 'pending' ? ['Awaiting verification', 'warning'] : ['Paid online', 'success'];
        if (pm === 'cod') {
            if (+o.payment_settled === 1) return ['Collected · paid to shop', 'success'];
            if (st === 'completed' || st === 'reviews') return ['Collected · pay the shop', 'info'];
            return ['Collect on delivery', 'warning'];
        }
        return +o.payment_settled === 1 || st === 'completed' ? ['Paid', 'success'] : ['Pay at shop', 'warning'];
    }

    function orderCard(o) {
        var id = +o.order_id || +o.id || 0;
        var pm = String(o.payment_method || '').toLowerCase();
        var claimed = o.is_unclaimed ? '<span class="rd-tag"><i class="fas fa-hand"></i> Not yet claimed</span>' : '';
        return '<a class="rd-order" href="' + BASE + '/rider/order.php?id=' + id + '">' +
            '<div class="rd-order-head"><span class="rd-order-no">#' + esc(o.order_number || id) + '</span>' + statusBadge(o.order_status) + '</div>' +
            '<div class="rd-order-cust"><i class="fas fa-user"></i><span>' + esc(o.customer_name || 'Customer') + '</span></div>' +
            '<div class="rd-order-addr"><i class="fas fa-location-dot"></i><span>' + esc(o.address || 'No address on file') + '</span></div>' +
            '<div class="rd-order-foot"><div class="rd-order-amt"><small>' + (pm === 'cod' ? 'COD · collect' : esc(paymentShort(pm))) + '</small>' + peso(o.total) + '</div>' +
            (claimed || '<span class="rd-order-go">View order <i class="fas fa-chevron-right"></i></span>') + '</div>' +
            '</a>';
    }

    // ---- Busy buttons (no double taps) ---------------------------------------------
    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            if (btn.dataset.busy === '1') return;
            btn.dataset.busy = '1';
            btn.dataset.html = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="rd-spin" aria-hidden="true"></span><span>' + esc(label || 'Please wait…') + '</span>';
        } else {
            btn.dataset.busy = '';
            btn.disabled = false;
            btn.classList.remove('is-loading');
            btn.removeAttribute('aria-busy');
            if (btn.dataset.html != null) btn.innerHTML = btn.dataset.html;
        }
    }
    /** Runs fn once at a time for this button; the button shows a spinner meanwhile. */
    function guarded(btn, label, fn) {
        if (btn && btn.dataset.busy === '1') return Promise.resolve();
        busy(btn, true, label);
        return Promise.resolve().then(fn).then(function (r) { busy(btn, false); return r; }, function (e) { busy(btn, false); throw e; });
    }

    // ---- Bottom sheet ----------------------------------------------------------------
    function sheet(html, opts) {
        opts = opts || {};
        var back = d.createElement('div');
        back.className = 'rd-sheet-backdrop';
        var el = d.createElement('div');
        el.className = 'rd-sheet';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.innerHTML = '<div class="rd-sheet-grip" aria-hidden="true"></div>' + html;
        d.body.appendChild(back);
        d.body.appendChild(el);
        d.body.classList.add('rd-sheet-open');
        requestAnimationFrame(function () { back.classList.add('is-open'); el.classList.add('is-open'); });
        var closed = false;
        function close() {
            if (closed) return;
            closed = true;
            back.classList.remove('is-open');
            el.classList.remove('is-open');
            d.body.classList.remove('rd-sheet-open');
            setTimeout(function () { back.remove(); el.remove(); }, 260);
            d.removeEventListener('keydown', onKey);
            if (opts.onClose) opts.onClose();
        }
        function onKey(e) { if (e.key === 'Escape' && !opts.locked) close(); }
        d.addEventListener('keydown', onKey);
        back.addEventListener('click', function () { if (!opts.locked || !opts.locked()) close(); });
        el.addEventListener('click', function (e) {
            if (e.target.closest('[data-sheet-close]')) close();
        });
        var first = el.querySelector('[autofocus]');
        if (first) setTimeout(function () { first.focus(); }, 280);
        return { el: el, close: close };
    }

    // ---- Pull to refresh ---------------------------------------------------------------
    function pullToRefresh(onRefresh) {
        var ind = d.createElement('div');
        ind.className = 'rd-ptr';
        ind.innerHTML = '<i class="fas fa-rotate-right"></i>';
        d.body.appendChild(ind);
        var startY = null, dist = 0, busyNow = false;
        d.addEventListener('touchstart', function (e) {
            if (busyNow || w.scrollY > 0 || d.body.classList.contains('rd-sheet-open')) { startY = null; return; }
            startY = e.touches[0].clientY; dist = 0;
        }, { passive: true });
        d.addEventListener('touchmove', function (e) {
            if (startY === null) return;
            dist = e.touches[0].clientY - startY;
            if (dist <= 0) { ind.classList.remove('is-pulling'); return; }
            var y = Math.min(dist * 0.5, 70) - 50;
            ind.classList.add('is-pulling');
            ind.style.transform = 'translateY(' + y + 'px) rotate(' + dist * 2 + 'deg)';
        }, { passive: true });
        d.addEventListener('touchend', function () {
            if (startY === null) return;
            startY = null;
            ind.classList.remove('is-pulling');
            ind.style.transform = '';
            if (dist > 110) {
                busyNow = true;
                ind.classList.add('is-refreshing');
                Promise.resolve(onRefresh()).catch(function () {}).then(function () {
                    busyNow = false;
                    ind.classList.remove('is-refreshing');
                });
            }
        });
    }

    /**
     * Keeps a view fresh without full reloads: runs `fn` every `ms` while the tab is visible,
     * when the tab comes back, when the connection returns, and right away when the existing
     * notification poller (assets/js/toast.js) reports a new notification (e.g. a new assignment).
     */
    /**
     * live(fn, ms, scope) - keeps a page current.
     * With a scope ('deliveries', 'order:12', 'chat:5', 'messages', 'notifications', 'remittance'),
     * one shared loop asks rider_live_api.php every ~4 s "did anything change?" (a tiny request) and
     * calls fn only when that scope's fingerprint changed - so updates appear within seconds.
     * The old interval (ms) stays as a slower safety net. Pauses while the tab is hidden.
     */
    var LIVE_MS = 4000, liveWatchers = [], liveTimer = null, liveBusy = false;
    function liveProbe() {
        if (liveBusy || d.hidden || !liveWatchers.length) return;
        liveBusy = true;
        var scopes = liveWatchers.map(function (x) { return x.scope; }).join(',');
        fetch(API + 'rider_live_api.php?scopes=' + encodeURIComponent(scopes) + '&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (body) {
                var data = (body && body.success && body.data) || {};
                liveWatchers.forEach(function (x) {
                    var sig = data[x.scope];
                    if (sig == null || sig === 'na') return;
                    if (x.last === null) { x.last = sig; return; }   // first answer = baseline
                    if (sig !== x.last) { x.last = sig; x.tick(true); }
                });
            }).catch(function () { /* offline: the safety interval and 'online' event cover it */ })
            .then(function () { liveBusy = false; });
    }
    function live(fn, ms, scope) {
        var running = false;
        function tick(force) {
            if (running || (d.hidden && !force)) return;
            running = true;
            Promise.resolve(fn(true)).catch(function () {}).then(function () { running = false; });
        }
        setInterval(tick, scope ? Math.max(ms || 30000, 60000) : (ms || 30000));
        d.addEventListener('visibilitychange', function () { if (!d.hidden) { tick(); liveProbe(); } });
        w.addEventListener('online', function () { tick(true); });
        d.addEventListener('dps:notifications-new', function () { tick(true); });
        if (scope) {
            liveWatchers.push({ scope: scope, last: null, tick: tick });
            if (!liveTimer) liveTimer = setInterval(liveProbe, LIVE_MS);
            setTimeout(liveProbe, 300);
        }
    }

    function skeleton(n, cls) {
        var s = '';
        for (var i = 0; i < (n || 3); i++) s += '<div class="rd-skel ' + (cls || 'rd-skel-card') + '"></div>';
        return s;
    }
    function errorBox(err, retryAttr) {
        return '<div class="rd-empty"><i class="fas ' + (err && err.network ? 'fa-wifi' : 'fa-triangle-exclamation') + '"></i>' +
            '<strong>' + (err && err.network ? 'No connection' : 'Could not load') + '</strong>' + esc((err && err.message) || CONN_MSG) +
            '<div><button type="button" class="rd-btn rd-btn-outline rd-btn-sm" ' + (retryAttr || 'data-retry') + '><i class="fas fa-rotate-right"></i> Try again</button></div></div>';
    }

    /** Reads an image File, scales it down (max 1600px, JPEG) and resolves a data URI. */
    function imageToDataUri(file, max) {
        max = max || 1600;
        return new Promise(function (resolve, reject) {
            if (!file || !/^image\//.test(file.type)) { reject(new RiderError('Please choose a photo.')); return; }
            var reader = new FileReader();
            reader.onerror = function () { reject(new RiderError('Could not read the photo.')); };
            reader.onload = function () {
                var img = new Image();
                img.onerror = function () { reject(new RiderError('Could not read the photo.')); };
                img.onload = function () {
                    var scale = Math.min(1, max / Math.max(img.width, img.height));
                    var c = d.createElement('canvas');
                    c.width = Math.round(img.width * scale);
                    c.height = Math.round(img.height * scale);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    resolve(c.toDataURL('image/jpeg', 0.8));
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }

    // ---- Availability: Online / On Break / Offline — rider_status_api.php ----------------
    // Going On Break or Offline asks for a reason (required; "Other" also needs details) and warns
    // about deliveries the rider still has - those stay assigned. Being Offline does not log out.
    var AV = {
        online: ['Online', 'You can accept To Ship orders', 'is-on'],
        on_break: ['On Break', 'You will not get new orders', 'is-break'],
        offline: ['Offline', 'Tap to go online and accept orders', '']
    };
    var availability = null; // last answer from rider_status_api.php (GET)
    var availabilityListeners = [];
    function onAvailability(fn) { availabilityListeners.push(fn); if (availability) fn(availability); }
    function setAvailability(a) {
        availability = a;
        availabilityListeners.forEach(function (fn) { try { fn(a); } catch (e) { /* keep the others */ } });
    }
    function loadAvailability() {
        return call('rider_status_api.php').then(function (res) { setAvailability(res.data || {}); return res.data; });
    }

    function availabilitySheet() {
        var cur = (availability && availability.status) || 'offline';
        var reasons = (availability && availability.reasons) || {};
        var active = (availability && availability.active) || [];
        var pick = cur, sending = false;
        var s = sheet(
            '<h2 class="rd-sheet-title">Change availability</h2>' +
            '<p class="rd-sheet-sub">Only Online riders can accept new To Ship orders.</p>' +
            '<div class="rd-stack">' +
            '<div class="rd-av-opts" role="radiogroup" aria-label="Availability">' +
            ['online', 'on_break', 'offline'].map(function (k) {
                return '<button type="button" class="rd-av-opt' + (k === cur ? ' is-selected' : '') + '" role="radio" aria-checked="' + (k === cur) + '" data-status="' + k + '">' +
                    '<span class="rd-duty-dot" aria-hidden="true"></span><span><strong>' + AV[k][0] + (k === cur ? ' (now)' : '') + '</strong><small>' + AV[k][1] + '</small></span></button>';
            }).join('') + '</div>' +
            '<div id="av-why" class="rd-stack" hidden>' +
            '<div class="rd-field"><label for="av-reason">Reason <span style="color:var(--danger)">*</span></label>' +
            '<select class="rd-select" id="av-reason" style="width:100%"><option value="">Choose a reason…</option>' +
            Object.keys(reasons).map(function (k) { return '<option value="' + esc(k) + '">' + esc(reasons[k]) + '</option>'; }).join('') + '</select></div>' +
            '<div class="rd-field"><label for="av-details">Additional details <span id="av-req" style="color:var(--danger)" hidden>*</span></label>' +
            '<textarea class="rd-textarea" id="av-details" maxlength="255" placeholder="Optional (required for Other)"></textarea></div>' +
            (active.length ? '<div class="rd-note is-warn"><i class="fas fa-triangle-exclamation"></i><span>You still have ' + active.length + ' active ' +
                (active.length === 1 ? 'delivery' : 'deliveries') + ' (' + active.map(function (o) { return '#' + esc(o.order_number); }).join(', ') +
                '). They stay assigned to you - finish them, or ask the admin to reassign.</span></div>' : '') +
            '</div>' +
            '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="av-save"><i class="fas fa-check"></i> Confirm status change</button>' +
            '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Cancel</button></div>',
            { locked: function () { return sending; } }
        );
        var why = s.el.querySelector('#av-why'), reason = s.el.querySelector('#av-reason'), details = s.el.querySelector('#av-details');
        var save = s.el.querySelector('#av-save'), req = s.el.querySelector('#av-req');
        function sync() {
            s.el.querySelectorAll('.rd-av-opt').forEach(function (b) {
                var on = b.dataset.status === pick;
                b.classList.toggle('is-selected', on);
                b.setAttribute('aria-checked', on ? 'true' : 'false');
            });
            why.hidden = pick === 'online';
            req.hidden = reason.value !== 'other';
            save.disabled = pick === cur && pick === 'online';
        }
        s.el.querySelector('.rd-av-opts').addEventListener('click', function (e) {
            var b = e.target.closest('.rd-av-opt');
            if (b) { pick = b.dataset.status; sync(); }
        });
        reason.addEventListener('change', sync);
        save.addEventListener('click', function () {
            if (pick !== 'online') {
                if (!reason.value) { notify('Please choose a reason.', 'error'); reason.focus(); return; }
                if (reason.value === 'other' && !details.value.trim()) { notify('Please describe the reason.', 'error'); details.focus(); return; }
            }
            sending = true;
            guarded(save, 'Saving…', function () {
                return call('rider_status_api.php', { body: { status: pick, reason: pick === 'online' ? '' : reason.value, details: pick === 'online' ? '' : details.value.trim() } });
            }).then(function (res) {
                sending = false;
                var a = res.data || {};
                a.reasons = reasons;
                setAvailability(a);
                notify(res.message || 'Status updated.', pick === 'online' ? 'ok' : 'info');
                s.close();
            }, function (err) { sending = false; fail(err); });
        });
        sync();
    }

    function initDuty(btn) {
        if (!btn) return;
        if (!btn.querySelector('.rd-duty-chev')) {
            var sw = btn.querySelector('.rd-switch');
            if (sw) sw.outerHTML = '<i class="fas fa-chevron-down rd-duty-chev" aria-hidden="true"></i>';
        }
        btn.setAttribute('aria-haspopup', 'dialog');
        btn.removeAttribute('aria-pressed');
        function render(a) {
            var k = AV[a.status] ? a.status : 'offline';
            btn.classList.remove('is-on', 'is-break');
            if (AV[k][2]) btn.classList.add(AV[k][2]);
            btn.querySelector('strong').textContent = AV[k][0];
            btn.querySelector('.rd-duty-text span').textContent = k === 'online' ? AV[k][1]
                : (a.reason_label ? a.reason_label + (a.details ? ' · ' + a.details : '') + ' — tap to change' : AV[k][1]);
        }
        render({ status: btn.dataset.on === '1' ? 'online' : 'offline' });
        onAvailability(render);
        if (!availability) loadAvailability().catch(function () { /* the page shows connection problems */ });
        btn.addEventListener('click', function () {
            if (btn.getAttribute('aria-busy') === 'true') return;
            btn.setAttribute('aria-busy', 'true');
            loadAvailability().then(availabilitySheet, fail).then(function () { btn.removeAttribute('aria-busy'); });
        });
    }

    // ---- Available Orders: unassigned To Ship delivery orders (rider_accept_order_api.php) ----
    function availableCard(o) {
        var id = +o.order_id || +o.id || 0;
        return '<div class="rd-order rd-avail-card" data-available="' + id + '">' +
            '<div class="rd-order-head"><span class="rd-order-no">#' + esc(o.order_number || id) + '</span>' + statusBadge(o.order_status) + '</div>' +
            '<div class="rd-avail-meta">' +
            '<span><i class="fas fa-store"></i><span>Pick up at the store</span></span>' +
            '<span><i class="fas fa-location-dot"></i><span>' + esc(o.address || 'Address shown after you accept') + '</span></span>' +
            '<span><i class="fas fa-calendar"></i><span>Ordered ' + esc(fmtDate(o.created_at)) + '</span></span>' +
            (o.items_summary ? '<span><i class="fas fa-box"></i><span>' + esc(o.items_summary) + '</span></span>' : '') +
            '<span><i class="fas fa-wallet"></i><span>' + (String(o.payment_method || '').toLowerCase() === 'cod' ? 'COD · collect ' + peso(o.total) : esc(paymentShort(o.payment_method)) + ' · ' + peso(o.total)) + '</span></span>' +
            '</div>' +
            '<div class="rd-avail-actions">' +
            '<button type="button" class="rd-btn rd-btn-primary" data-accept="' + id + '"><i class="fas fa-hand"></i> Accept Order</button>' +
            '<a class="rd-btn rd-btn-outline" href="' + BASE + '/rider/order.php?id=' + id + '">View</a>' +
            '</div></div>';
    }
    // ---- Admin-assigned orders: Accept / Decline (rider_assignment_api.php) ----------------
    /** Card for an order an admin assigned to this rider that still needs an answer. */
    function assignmentCard(o) {
        var id = +o.order_id || +o.id || 0;
        return '<div class="rd-order rd-assign-card" data-assignment="' + id + '">' +
            '<div class="rd-order-head"><span class="rd-order-no">#' + esc(o.order_number || id) + '</span>' +
            '<span class="rd-tag is-new"><i class="fas fa-bell"></i> Assigned to you</span></div>' +
            '<div class="rd-avail-meta">' +
            '<span><i class="fas fa-store"></i><span>Pick up at the store · ' + esc(statusMeta(o.order_status)[0]) + '</span></span>' +
            '<span><i class="fas fa-location-dot"></i><span>' + esc(o.address || 'No address on file') + '</span></span>' +
            (o.items_summary ? '<span><i class="fas fa-box"></i><span>' + esc(o.items_summary) + '</span></span>' : '') +
            '<span><i class="fas fa-wallet"></i><span>' + (String(o.payment_method || '').toLowerCase() === 'cod' ? 'COD · collect ' + peso(o.total) : esc(paymentShort(o.payment_method)) + ' · ' + peso(o.total)) + '</span></span>' +
            '</div>' +
            '<div class="rd-assign-actions">' +
            '<button type="button" class="rd-btn rd-btn-primary" data-assign-accept="' + id + '"><i class="fas fa-check"></i> Accept</button>' +
            '<button type="button" class="rd-btn rd-btn-outline rd-btn-danger" data-assign-decline="' + id + '" data-number="' + esc(o.order_number || id) + '"><i class="fas fa-xmark"></i> Decline</button>' +
            '<a class="rd-btn rd-btn-ghost" href="' + BASE + '/rider/order.php?id=' + id + '">View</a>' +
            '</div></div>';
    }
    /**
     * Accept or decline an assigned order. Decline first asks for a reason (optional) in a sheet.
     * Resolves with the API result (null if the rider closed the sheet); rejects on refusal.
     */
    function respondAssignment(orderId, decision, btn, orderNumber) {
        function send(reason, button) {
            return guarded(button, decision === 'accept' ? 'Accepting…' : 'Declining…', function () {
                return call('rider_assignment_api.php', { body: { order_id: +orderId, decision: decision, reason: reason || '' } });
            }).then(function (res) {
                notify(res.message || 'Saved.', decision === 'accept' ? 'ok' : 'info');
                return res;
            }, function (err) { fail(err); throw err; });
        }
        if (decision === 'accept') return send('', btn);
        var reasons = ['Too far', 'E-bike problem', 'Too many deliveries', 'Not feeling well'];
        return new Promise(function (resolve, reject) {
            var done = false;
            var s = sheet(
                '<h2 class="rd-sheet-title">Decline order #' + esc(orderNumber || orderId) + '?</h2>' +
                '<p class="rd-sheet-sub">The shop will be told so they can assign another rider.</p>' +
                '<form class="rd-stack" id="decline-form">' +
                '<div class="rd-field"><label>Reason (optional)</label><div class="rd-chips">' +
                reasons.map(function (r) { return '<button type="button" class="rd-chip" aria-pressed="false" data-reason="' + esc(r) + '">' + esc(r) + '</button>'; }).join('') +
                '</div></div>' +
                '<div class="rd-field"><label for="decline-note">Other reason</label><input class="rd-input" id="decline-note" maxlength="200" placeholder="e.g. Already on another delivery"></div>' +
                '<button type="submit" class="rd-btn rd-btn-danger rd-btn-block rd-btn-lg" id="decline-go"><i class="fas fa-xmark"></i> Decline order</button>' +
                '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Keep it</button></form>',
                { onClose: function () { if (!done) resolve(null); } }
            );
            var note = s.el.querySelector('#decline-note');
            s.el.querySelector('.rd-chips').addEventListener('click', function (e) {
                var c = e.target.closest('[data-reason]');
                if (!c) return;
                var on = c.getAttribute('aria-pressed') !== 'true';
                s.el.querySelectorAll('[data-reason]').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            s.el.querySelector('#decline-form').addEventListener('submit', function (e) {
                e.preventDefault();
                var picked = s.el.querySelector('[data-reason][aria-pressed="true"]');
                var reason = [picked ? picked.getAttribute('data-reason') : '', note.value.trim()].filter(Boolean).join(' - ');
                send(reason, s.el.querySelector('#decline-go')).then(function (res) { done = true; s.close(); resolve(res); }, function (err) { done = true; s.close(); reject(err); });
            });
        });
    }

    // ---- Paging: lists show 10 at a time with Prev / Next ---------------------------------------
    var pageState = {};
    /** The slice of `items` for list `key` (page kept across live refreshes; clamped if the list shrank). */
    function paged(key, items, size) {
        size = size || 10;
        var count = Math.max(1, Math.ceil(items.length / size));
        var page = Math.min(Math.max(1, pageState[key] || 1), count);
        pageState[key] = page;
        return { key: key, items: items.slice((page - 1) * size, page * size), page: page, pages: count,
                 total: items.length, from: items.length ? (page - 1) * size + 1 : 0, to: Math.min(page * size, items.length) };
    }
    /** Prev / "Page 1 of 3" / Next bar (empty when everything fits on one page). */
    function pager(pg) {
        if (pg.pages <= 1) return '';
        return '<nav class="rd-pager" data-pager="' + esc(pg.key) + '" aria-label="Pages">' +
            '<button type="button" class="rd-pager-btn" data-page="' + (pg.page - 1) + '"' + (pg.page <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i> Prev</button>' +
            '<span class="rd-pager-info"><strong>Page ' + pg.page + ' of ' + pg.pages + '</strong><small>' + pg.from + '–' + pg.to + ' of ' + pg.total + '</small></span>' +
            '<button type="button" class="rd-pager-btn" data-page="' + (pg.page + 1) + '"' + (pg.page >= pg.pages ? ' disabled' : '') + '>Next <i class="fas fa-chevron-right"></i></button>' +
            '</nav>';
    }
    /** Pager clicks inside `container`: change page, re-render, and bring the list back into view. */
    function onPager(container, rerender) {
        container.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-pager] [data-page]');
            if (!b || b.disabled) return;
            e.preventDefault();
            var nav = b.closest('[data-pager]');
            var key = nav.getAttribute('data-pager');
            pageState[key] = +b.getAttribute('data-page');
            rerender();
            var again = container.querySelector('[data-pager="' + key + '"]');
            var anchor = (again && again.parentNode) || container;
            var top = anchor.getBoundingClientRect().top + w.scrollY - 80;
            w.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        });
    }
    function resetPage(key) { pageState[key] = 1; }

    /** Accept an order (server checks everything again). Resolves with the API result, rejects on refusal. */
    function acceptOrder(orderId, btn) {
        return guarded(btn, 'Accepting…', function () {
            return call('rider_accept_order_api.php', { body: { order_id: +orderId } });
        }).then(function (res) {
            notify(res.message || 'Order accepted.', 'ok');
            return res;
        }, function (err) {
            fail(err);
            throw err;
        });
    }

    // ---- Page chrome ---------------------------------------------------------------------
    function initChrome() {
        var banner = d.getElementById('rd-offline');
        function net() { if (banner) banner.hidden = navigator.onLine !== false; }
        w.addEventListener('online', function () { net(); notify('Back online.', 'ok'); });
        w.addEventListener('offline', net);
        net();
        // Back arrow: go back in history when we came from inside the portal.
        d.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('[data-back]');
            if (!a) return;
            try {
                if (d.referrer && new URL(d.referrer).origin === w.location.origin && w.history.length > 1) {
                    e.preventDefault();
                    w.history.back();
                }
            } catch (err) { /* follow the link */ }
        });
    }
    /**
     * Pickup / delivery photo (rider_proof_api.php). stage: 'pickup' (parcel leaving the shop) or
     * 'delivery' (parcel at the customer's place). order needs id, order_number, delivery_id.
     * Resolves with the API result once uploaded, or null if the rider closed the sheet.
     */
    function photoSheet(order, stage) {
        var pickup = stage === 'pickup';
        return new Promise(function (resolve) {
            var uploaded = null, dataUri = null, sending = false;
            var s = sheet(
                '<h2 class="rd-sheet-title">' + (pickup ? 'Pickup photo' : 'Delivery photo') + '</h2>' +
                '<p class="rd-sheet-sub">Order #' + esc(order.order_number) + ' · ' +
                (pickup ? 'take a photo of the parcel as you receive it at the shop.' : 'take a photo of the parcel at the customer\'s place.') + '</p>' +
                '<div class="rd-stack">' +
                '<label class="rd-btn rd-btn-outline rd-btn-block" for="photo-file"><i class="fas fa-camera"></i> <span id="photo-pick">Take or choose a photo</span></label>' +
                '<input type="file" id="photo-file" accept="image/*" capture="environment" hidden>' +
                '<img id="photo-preview" alt="Selected photo" class="rd-receipt-img" hidden>' +
                '<div class="rd-field"><label for="photo-notes">Note (optional)</label>' +
                '<textarea class="rd-textarea" id="photo-notes" maxlength="500" placeholder="' +
                (pickup ? 'e.g. 2 sacks, sealed, no damage' : 'e.g. Handed to the customer at the gate') + '"></textarea></div>' +
                '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="photo-send" disabled><i class="fas fa-upload"></i> Upload photo</button>' +
                '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Cancel</button></div>',
                { locked: function () { return sending; }, onClose: function () { resolve(uploaded); } }
            );
            var file = s.el.querySelector('#photo-file'), send = s.el.querySelector('#photo-send'), prev = s.el.querySelector('#photo-preview');
            file.addEventListener('change', function () {
                if (!file.files[0]) return;
                imageToDataUri(file.files[0]).then(function (uri) {
                    dataUri = uri;
                    prev.src = uri;
                    prev.hidden = false;
                    s.el.querySelector('#photo-pick').textContent = 'Retake photo';
                    send.disabled = false;
                }, fail);
            });
            send.addEventListener('click', function () {
                if (!dataUri) return;
                sending = true;
                guarded(send, 'Uploading…', function () {
                    return call('rider_proof_api.php', { body: {
                        order_id: +order.id, delivery_id: +order.delivery_id || 0, stage: pickup ? 'pickup' : 'delivery',
                        image: dataUri, notes: s.el.querySelector('#photo-notes').value.trim()
                    }, timeout: 60000 });
                }).then(function (res) {
                    sending = false;
                    uploaded = res;
                    notify(res.message || 'Photo uploaded.', 'ok');
                    s.close();
                }, function (err) { sending = false; fail(err); });
            });
        });
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', initChrome);
    else initChrome();

    w.Rider = {
        BASE: BASE, CONN_MSG: CONN_MSG, NEXT: NEXT, RiderError: RiderError,
        call: call, notify: notify, fail: fail,
        esc: esc, num: num, peso: peso, parseDate: parseDate, fmtDate: fmtDate, timeAgo: timeAgo, isToday: isToday, initials: initials,
        statusMeta: statusMeta, statusBadge: statusBadge, paymentLabel: paymentLabel, paymentShort: paymentShort, paymentState: paymentState,
        orderCard: orderCard, busy: busy, guarded: guarded, sheet: sheet, pullToRefresh: pullToRefresh, live: live,
        skeleton: skeleton, errorBox: errorBox, imageToDataUri: imageToDataUri, initDuty: initDuty, photoSheet: photoSheet,
        paged: paged, pager: pager, onPager: onPager, resetPage: resetPage,
        availableCard: availableCard, acceptOrder: acceptOrder, assignmentCard: assignmentCard, respondAssignment: respondAssignment, onAvailability: onAvailability, loadAvailability: loadAvailability
    };
})(window, document);
