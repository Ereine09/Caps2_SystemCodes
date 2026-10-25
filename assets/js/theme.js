/**
 * theme.js — light/dark theme for every portal (Admin, Staff, Customer, login pages).
 *
 * State: data-theme="light|dark" on <html>, saved separately for each portal.
 * The dark palette lives in assets/css/ui-kit.css (:root[data-theme="dark"]).
 * body.dark-mode is kept in sync because older page CSS/JS targets it.
 *
 * Any element with [data-theme-toggle] becomes a toggle: moon icon in light mode,
 * sun icon in dark mode. Add data-theme-label to also show "Dark mode"/"Light mode".
 * Fires 'dps:theme-change' ({ detail: { dark } }) so charts can recolor.
 *
 * Each page's <head> applies the saved theme before first paint with a tiny inline
 * script; this file then keeps everything in sync (including pages restored by
 * Back/Forward and other open tabs). Idempotent if included twice.
 */
(function (w, d) {
    'use strict';
    if (w.DPSTheme) return;

    var path = (w.location.pathname || '').toLowerCase();
    var KEY = path.indexOf('/customer/') !== -1 && path.indexOf('/modules/') === -1
        ? 'customerTheme'
        : (path.indexOf('/rider/') !== -1 ? 'riderTheme' : 'dps_admin_theme');

    function saved() {
        try {
            var t = localStorage.getItem(KEY);
            if (t === 'dark' || t === 'light') return t;
            // Migrate the former shared preference once per portal, then never
            // read that shared key again while this portal has its own setting.
            var old = localStorage.getItem('theme');
            if (old === 'dark' || old === 'light') {
                localStorage.setItem(KEY, old);
                return old;
            }
        } catch (e) { /* storage blocked: fall back to light */ }
        return 'light';
    }

    function current() {
        return d.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function syncBody(theme) {
        if (d.body) d.body.classList.toggle('dark-mode', theme === 'dark');
    }

    function renderButton(btn) {
        var dark = current() === 'dark';
        var text = dark ? 'Light mode' : 'Dark mode';
        // Rebuilt each time so the Font Awesome SVG build (customer pages) re-renders the icon.
        btn.innerHTML = '<i class="fas ' + (dark ? 'fa-sun' : 'fa-moon') + '" aria-hidden="true"></i>' +
            (btn.hasAttribute('data-theme-label') ? '<span class="theme-toggle-label">' + text + '</span>' : '');
        btn.setAttribute('aria-label', 'Switch to ' + (dark ? 'light' : 'dark') + ' mode');
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        btn.title = btn.getAttribute('aria-label');
    }

    function renderButtons() {
        var btns = d.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < btns.length; i++) renderButton(btns[i]);
    }

    function apply(theme) {
        d.documentElement.setAttribute('data-theme', theme);
        syncBody(theme);
        renderButtons();
    }

    function changed(theme) {
        d.dispatchEvent(new CustomEvent('dps:theme-change', { detail: { dark: theme === 'dark' } }));
    }

    function set(theme) {
        theme = theme === 'dark' ? 'dark' : 'light';
        apply(theme);
        try { localStorage.setItem(KEY, theme); } catch (e) { /* just won't persist */ }
        changed(theme);
    }

    // Re-read the saved choice when this page may be showing an old one.
    function resync() {
        var theme = saved();
        if (theme === current()) return;
        apply(theme);
        changed(theme);
    }

    // Back/Forward can restore a page from the browser's page cache exactly as it was
    // left (no reload, so the <head> snippet doesn't run again) - e.g. still light after
    // the user switched to dark on the next page. Also follow a switch made in another tab.
    w.addEventListener('pageshow', resync);
    w.addEventListener('storage', function (e) {
        if (e.key === KEY || e.key === null) resync();
    });

    // One delegated handler covers every toggle, including ones created later.
    d.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
        if (!btn) return;
        e.preventDefault();
        set(current() === 'dark' ? 'light' : 'dark');
    });

    w.DPSTheme = {
        get: current,
        set: set,
        toggle: function () { set(current() === 'dark' ? 'light' : 'dark'); },
        refreshButtons: renderButtons
    };

    apply(saved());
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { syncBody(current()); renderButtons(); });
    }
})(window, document);
