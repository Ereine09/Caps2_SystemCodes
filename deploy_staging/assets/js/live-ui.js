/* Live UI - shared client helpers for admin pages that update WITHOUT a page refresh.
 *
 *   action -> fetch() -> PHP/MySQL -> JSON -> update only the affected part of the page
 *
 * AJAX is only the transport here. The PHP page still does all authentication,
 * CSRF, role, validation and business-rule checks; this file just sends the request,
 * shows the result and patches the DOM.
 *
 * Provides:  LiveUI.toast, request/post, fetchDoc, busy, lock, syncKeyed, poll, watch, formData
 */
(function (w, d) {
    'use strict';

    // ---- where things live ------------------------------------------------
    var script = d.currentScript;
    var baseUrl = w.__DPS_BASE_URL || (script && script.src ? new URL(script.src, location.href).pathname.replace(/\/assets\/js\/live-ui\.js.*$/, '') : '');
    var loginUrl = baseUrl + '/modules/auth/login.php';

    var inflight = 0;      // requests started by a user action (polling pauses while > 0)
    var locks = {};        // per-item locks so the same item can't be submitted twice at once
    var expiredShown = false;

    // ---- toast (one shared notification component) ---------------------------
    function toast(message, type, opts) {
        type = type || 'success';
        opts = opts || {};
        message = String(message == null ? '' : message);
        if (!message) return;

        var box = d.getElementById('liveui-toasts');
        if (!box) {
            box = d.createElement('div');
            box.id = 'liveui-toasts';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            d.body.appendChild(box);
        }

        // Never stack the same message twice: restart the timer of the one already showing.
        var same = Array.prototype.find.call(box.children, function (t) {
            return t.dataset.msg === message && !t.classList.contains('leaving');
        });
        if (same) {
            clearTimeout(same._timer);
            same._timer = setTimeout(same._close, opts.duration || (type === 'error' ? 6500 : 3500));
            return;
        }

        var t = d.createElement('div');
        t.className = 'liveui-toast ' + type;
        t.dataset.msg = message;
        t.dataset.type = type;
        var ic = d.createElement('span'); ic.className = 'liveui-ic'; ic.setAttribute('aria-hidden', 'true');
        ic.textContent = { success: '✓', error: '✕', warn: '!', info: 'i' }[type] || 'i';
        var msg = d.createElement('span'); msg.className = 'liveui-msg'; msg.textContent = message;
        var x = d.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
        t.appendChild(ic); t.appendChild(msg); t.appendChild(x);

        t._close = function () {
            clearTimeout(t._timer);
            t.classList.add('leaving');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 200);
        };
        x.addEventListener('click', t._close);
        t._timer = setTimeout(t._close, opts.duration || (type === 'error' ? 6500 : 3500));
        box.appendChild(t);
        while (box.children.length > 4) { box.removeChild(box.firstChild); }
    }

    // ---- errors ------------------------------------------------------------
    function LiveError(message, code, data) {
        var e = new Error(message);
        e.name = 'LiveError';
        e.code = code || 'failed';
        e.data = data || null;
        return e;
    }

    function sessionExpired() {
        if (!expiredShown) {
            expiredShown = true;
            toast('Your session has expired. Please log in again.', 'warn', { duration: 4000 });
            // Navigating to the login page is intentional here (authentication), not a data refresh.
            setTimeout(function () { w.location.href = loginUrl; }, 1600);
        }
        return LiveError('Your session has expired. Please log in again.', 'auth');
    }

    function looksLikeLogin(res) {
        return res.redirected && /\/auth\/login/i.test(res.url || '');
    }

    // ---- requests ------------------------------------------------------------
    /**
     * POST/GET expecting JSON. Resolves with the parsed body when success !== false.
     * Rejects with a LiveError whose .message is safe to show to the user
     * (network failure, timeout, expired session, unreadable reply, or the server's own message).
     */
    function request(url, opts) {
        opts = opts || {};
        var ctl = new AbortController();
        var timedOut = false;
        var timer = setTimeout(function () { timedOut = true; ctl.abort(); }, opts.timeout || 30000);
        if (opts.signal) opts.signal.addEventListener('abort', function () { ctl.abort(); });

        var init = {
            method: (opts.method || 'POST').toUpperCase(),
            credentials: 'same-origin',
            signal: ctl.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        };
        var b = opts.body;
        if (b instanceof FormData || typeof b === 'string') {
            init.body = b;
        } else if (b && typeof b === 'object') {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(b);
        }

        inflight++;
        return fetch(url, init).then(function (res) {
            if (looksLikeLogin(res)) throw sessionExpired();
            var ct = res.headers.get('content-type') || '';
            if (ct.indexOf('json') === -1) {
                if (res.status === 401) throw sessionExpired();
                throw LiveError('The server sent an unexpected reply. Please try again.', 'badresponse');
            }
            return res.json().then(function (data) {
                if (res.status === 401 || (data && data.code === 'auth')) throw sessionExpired();
                if (!res.ok || !data || data.success === false) {
                    throw LiveError((data && (data.message || data.error)) || 'The request failed. Please try again.', 'failed', data);
                }
                return data;
            }, function () {
                throw LiveError('The server sent an unreadable reply. Please try again.', 'badjson');
            });
        }, function (err) {
            if (timedOut) throw LiveError('The server took too long to answer. Please try again.', 'timeout');
            if (err && err.name === 'AbortError') throw LiveError('Request cancelled.', 'aborted');
            throw LiveError('Network problem. Check your connection and try again.', 'network');
        }).then(function (data) {
            clearTimeout(timer); inflight--; return data;
        }, function (err) {
            clearTimeout(timer); inflight--; throw err;
        });
    }

    function post(url, body, opts) {
        opts = opts || {};
        opts.method = 'POST';
        opts.body = body;
        return request(url, opts);
    }

    /** GET a page and return it as a Document (used to read fresh server-rendered rows/counters). */
    function fetchDoc(url, opts) {
        opts = opts || {};
        return fetch(url, { credentials: 'same-origin', signal: opts.signal, headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest-doc' } })
            .then(function (res) {
                if (looksLikeLogin(res)) throw sessionExpired();
                if (res.status === 401) throw sessionExpired();
                if (!res.ok) throw LiveError('Could not refresh the list. Please try again.', 'failed');
                return res.text();
            }, function (err) {
                if (err && err.name === 'AbortError') throw LiveError('Request cancelled.', 'aborted');
                throw LiveError('Network problem. Check your connection and try again.', 'network');
            })
            .then(function (html) { return new DOMParser().parseFromString(html, 'text/html'); });
    }

    // ---- form helpers ---------------------------------------------------------
    /** FormData for a form, including the clicked submit button's name/value (like a real submit). */
    function formData(form, submitter) {
        var fd;
        try { fd = submitter ? new FormData(form, submitter) : new FormData(form); }
        catch (e) { fd = new FormData(form); }
        // A submit button that is already disabled (our "Saving…" state) is left out of FormData by the
        // browser; the server may rely on its name (e.g. name="update_customer"), so always include it.
        if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '');
        return fd;
    }

    // ---- busy state for ONE control (never the whole page) --------------------
    function busy(el, text) {
        if (!el) return function () {};
        var isSelect = el.tagName === 'SELECT';
        var orig = isSelect ? null : el.innerHTML;
        var wasDisabled = el.disabled;
        el.disabled = true;
        el.classList.add('liveui-busy');
        el.setAttribute('aria-busy', 'true');
        if (text && !isSelect) el.textContent = text;
        return function done(finalText, ms) {
            function restore() {
                if (!isSelect) el.innerHTML = orig;
                el.disabled = wasDisabled;
                el.classList.remove('liveui-busy');
                el.removeAttribute('aria-busy');
            }
            if (finalText && !isSelect) {
                el.textContent = finalText;
                el.classList.remove('liveui-busy');
                setTimeout(restore, ms || 900); // stays disabled while showing "Done" so it can't be double-clicked
            } else {
                restore();
            }
        };
    }

    /** Acquire a per-item lock. Returns a release() function, or false if that item is already being processed. */
    function lock(key) {
        if (locks[key]) return false;
        locks[key] = true;
        inflight++;
        return function release() {
            if (locks[key]) { delete locks[key]; inflight--; }
        };
    }

    function isBusy() { return inflight > 0; }

    // ---- DOM sync (keyed, so nothing is ever duplicated) -----------------------
    function normalized(el, ignore) {
        var c = el.cloneNode(true);
        (ignore || []).forEach(function (a) { c.removeAttribute(a); });
        Array.prototype.slice.call(c.classList).forEach(function (cls) { if (cls.indexOf('liveui-') === 0) c.classList.remove(cls); });
        if (!c.getAttribute('class')) c.removeAttribute('class');
        return c.outerHTML;
    }

    function flash(el) {
        el.classList.add('liveui-flash');
        setTimeout(function () { el.classList.remove('liveui-flash'); }, 1300);
    }

    /**
     * Make the keyed children of `cur` match those of `fresh` while touching as little as possible:
     *   - a child whose key is missing in `fresh` is removed,
     *   - a child whose markup changed is replaced,
     *   - a new key is inserted at the right position,
     *   - identical children are left alone (so open dropdowns, focus, scroll are not disturbed).
     * `attr` is the key attribute (e.g. 'data-order-id'); a key can therefore never appear twice.
     * opts: skip(el) -> true to leave an element untouched (e.g. request in flight),
     *       ignoreAttrs (default ['hidden','style']) ignored on the row element itself when comparing,
     *       flash (default true), onChange(el, 'updated'|'added')
     */
    function syncKeyed(cur, fresh, attr, opts) {
        opts = opts || {};
        var ignore = opts.ignoreAttrs || ['hidden', 'style'];
        var stats = { added: 0, updated: 0, removed: 0 };
        if (!cur || !fresh) return stats;

        var keyed = function (root) {
            return Array.prototype.filter.call(root.children, function (n) { return n.hasAttribute(attr); });
        };
        var curKids = keyed(cur), freshKids = keyed(fresh);
        var map = {}, seen = {};
        curKids.forEach(function (k) { map[k.getAttribute(attr)] = k; });
        freshKids.forEach(function (k) { seen[k.getAttribute(attr)] = true; });

        curKids.forEach(function (k) {
            if (!seen[k.getAttribute(attr)] && !(opts.skip && opts.skip(k))) { k.parentNode.removeChild(k); stats.removed++; }
        });

        var prev = null;
        freshKids.forEach(function (f) {
            var key = f.getAttribute(attr);
            var ex = map[key];
            if (ex && ex.parentNode !== cur) ex = null;
            if (ex && opts.skip && opts.skip(ex)) { prev = ex; return; }

            if (ex) {
                if (normalized(ex, ignore) !== normalized(f, ignore)) {
                    var repl = d.importNode(f, true);
                    ex.parentNode.replaceChild(repl, ex);
                    ex = repl; stats.updated++;
                    if (opts.flash !== false) flash(ex);
                    if (opts.onChange) opts.onChange(ex, 'updated');
                }
            } else {
                ex = d.importNode(f, true);
                cur.appendChild(ex); // moved into the right slot just below
                stats.added++;
                if (opts.flash !== false) flash(ex);
                if (opts.onChange) opts.onChange(ex, 'added');
            }

            if (prev) {
                if (ex.previousElementSibling !== prev) prev.parentNode.insertBefore(ex, prev.nextSibling);
            } else {
                var firstKeyed = Array.prototype.find.call(cur.children, function (n) { return n.hasAttribute(attr); });
                if (firstKeyed && firstKeyed !== ex) cur.insertBefore(ex, firstKeyed);
            }
            prev = ex;
        });
        return stats;
    }

    /** Set text only if it changed (avoids needless repaint / selection loss). */
    function setText(el, text) {
        if (el && el.textContent !== String(text)) el.textContent = text;
    }

    /** Copy innerHTML of `fresh` into `cur` only when different. */
    function setHTML(cur, fresh) {
        if (cur && fresh && cur.innerHTML !== fresh.innerHTML) { cur.innerHTML = fresh.innerHTML; return true; }
        return false;
    }

    /**
     * Update the sidebar count badges (Orders, Remittance) from a freshly fetched page.
     * Only those two links are touched; the Messages badge has its own live updater.
     */
    function syncSidebar(freshDoc) {
        if (!freshDoc) return;
        ['orders.php', 'remittance_management.php'].forEach(function (name) {
            var sel = '.sidebar a[href$="/' + name + '"], .sidebar a[href$="' + name + '"]';
            var cur = d.querySelector(sel), fr = freshDoc.querySelector(sel);
            if (cur && fr && cur.innerHTML.trim() !== fr.innerHTML.trim()) cur.innerHTML = fr.innerHTML;
        });
    }

    /** Remove a row/card with a short fade. */
    function removeNode(el, done) {
        if (!el) return;
        el.classList.add('liveui-removing');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); if (done) done(); }, 260);
    }

    // ---- gentle polling (existing pattern: setInterval, no new realtime system) --------
    /**
     * Runs fn every `ms` while the tab is visible, never overlapping itself and never while
     * the user has an action in flight. Also catches up when the tab becomes visible again.
     */
    function poll(fn, ms) {
        var running = false, last = Date.now();
        function tick() {
            if (d.hidden || running || inflight > 0) return;
            running = true; last = Date.now();
            Promise.resolve().then(fn).catch(function () { /* silent: the next tick retries */ }).then(function () { running = false; });
        }
        var id = setInterval(tick, ms);
        d.addEventListener('visibilitychange', function () { if (!d.hidden && Date.now() - last > ms) tick(); });
        return function stop() { clearInterval(id); };
    }

    // ---- change watcher: cheap probe first, page refresh only when something changed -----------------
    /**
     * watch(scope, onChange, opts)
     *   scope     - 'orders' | 'delivery' | 'reviews' | 'remittance' | 'customers' (see live_changes_api.php)
     *   onChange  - the page's OWN existing "reconcile the list" function; called only when the fingerprint
     *               of that scope changed (never for the first reading, never overlapping itself)
     *   opts      - { fallbackMs: 30000 } interval used ONLY if the probe endpoint keeps failing
     *
     * One shared loop per page asks live_changes_api.php for all watched scopes in a single tiny request every
     * ~5 s (only while the tab is visible and no user action is running). A 'dps:live-poke' event (fired by the
     * existing admin WebSocket when a notification arrives) makes it check immediately.
     */
    var WATCH_MS = 5000;
    var probeUrl = baseUrl + '/modules/admin/live_changes_api.php';
    var watchers = [];
    var probeTimer = null, probing = false, failures = 0, lastProbe = 0, useFallback = false;

    function runWatcher(wt) {
        if (wt.running) { wt.rerun = true; return; }        // never overlap the same page refresh
        wt.running = true;
        Promise.resolve().then(wt.onChange).catch(function () { /* silent: the next change retries */ }).then(function () {
            wt.running = false;
            if (wt.rerun) { wt.rerun = false; runWatcher(wt); }
        });
    }

    function startFallback() {
        if (useFallback) return;
        useFallback = true;                                  // the probe is unusable: fall back to the old interval refresh
        if (probeTimer) { clearInterval(probeTimer); probeTimer = null; }
        watchers.forEach(function (wt) { poll(function () { return wt.onChange(); }, wt.fallbackMs); });
    }

    function probe(force) {
        if (useFallback || probing || !watchers.length) return;
        if (d.hidden || inflight > 0) return;                // tab in background or the user is mid-action
        if (force && Date.now() - lastProbe < 1000) return;   // WebSocket bursts: at most one check per second
        probing = true; lastProbe = Date.now();

        var scopes = watchers.map(function (wt) { return wt.scope; });
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, 8000);
        fetch(probeUrl + '?scopes=' + encodeURIComponent(scopes.join(',')) + '&_=' + Date.now(), {
            credentials: 'same-origin', signal: ctl.signal, cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (res.status === 401) throw sessionExpired();
            var ct = res.headers.get('content-type') || '';
            if (!res.ok || ct.indexOf('json') === -1) throw LiveError('probe unavailable', 'failed');
            return res.json();
        }).then(function (body) {
            if (!body || body.success === false) throw LiveError('probe unavailable', 'failed');
            var unusable = false;
            watchers.forEach(function (wt) {
                var sig = body.data && body.data[wt.scope];
                if (sig == null || sig === 'na') { unusable = true; return; }   // scope unknown / query failed on the server
                if (wt.last === null) { wt.last = sig; return; }   // first reading = baseline
                if (sig !== wt.last) { wt.last = sig; runWatcher(wt); }
            });
            // A probe that cannot answer for a scope must never leave that page silently frozen: it counts as a
            // failure, and after 3 in a row the pages use their old interval refresh instead.
            if (unusable) throw LiveError('probe cannot answer this scope', 'failed');
            failures = 0;
        }).catch(function (err) {
            if (err && err.code === 'auth') return;
            if (++failures >= 3) startFallback();
        }).then(function () { clearTimeout(timer); probing = false; });
    }

    function watch(scope, onChange, opts) {
        opts = opts || {};
        var wt = { scope: scope, onChange: onChange, last: null, running: false, rerun: false, fallbackMs: opts.fallbackMs || 30000 };
        watchers.push(wt);
        if (useFallback) { poll(function () { return wt.onChange(); }, wt.fallbackMs); return wt; }
        if (!probeTimer) {
            probeTimer = setInterval(function () { probe(false); }, WATCH_MS);
            d.addEventListener('visibilitychange', function () { if (!d.hidden) probe(true); });
            d.addEventListener('dps:live-poke', function () { probe(true); });
        }
        probe(true);                                         // take the baseline right away
        return wt;
    }

    w.LiveUI = {
        toast: toast, request: request, post: post, fetchDoc: fetchDoc, formData: formData,
        busy: busy, lock: lock, isBusy: isBusy,
        syncKeyed: syncKeyed, setText: setText, setHTML: setHTML, removeNode: removeNode, flash: flash, syncSidebar: syncSidebar,
        poll: poll, watch: watch, loginUrl: loginUrl
    };
})(window, document);
