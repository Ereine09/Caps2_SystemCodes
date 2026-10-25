/**
 * account-guard.js — one account per browser, enforced in every open tab.
 *
 * Login cookies are shared by all tabs, so when a tab signs out and another account signs in,
 * the other tabs would keep showing the previous account's page until the next click. This
 * remembers which account was signed in when the page loaded (/session_status.php) and, when
 * the tab comes back into view (tab switch, window focus, Back/Forward cache), reloads the page
 * if a different account (or nobody) is signed in now. The server then shows the right page or
 * sends the visitor to the login screen.
 *
 * Needs window.__DPS_BASE_URL or a data-base-url on this <script>. Idempotent if included twice.
 */
(function (w, d) {
    'use strict';
    if (w.DPSAccountGuard) return;
    w.DPSAccountGuard = true;

    var me = d.currentScript;
    var base = (me && me.getAttribute('data-base-url')) || w.__DPS_BASE_URL || '';
    var url = base + '/session_status.php';
    var start;          // account key when the page loaded (undefined until known)
    var busy = false;

    function current() {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (j) { return j && j.account ? String(j.account) : null; });
    }

    function check() {
        if (busy || start === undefined || d.visibilityState === 'hidden') return;
        busy = true;
        current().then(function (now) {
            if (now !== start) w.location.reload();
        }).catch(function () { /* offline or server hiccup: try again next time */ })
          .then(function () { busy = false; });
    }

    current().then(function (key) { start = key; }).catch(function () {});

    d.addEventListener('visibilitychange', check);
    w.addEventListener('focus', check);
    w.addEventListener('pageshow', function (e) { if (e.persisted) check(); });
})(window, document);
