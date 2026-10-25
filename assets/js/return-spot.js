/*
 * "Return to the same spot" for list pages (admin, staff and customer portals).
 *
 * Every page remembers its full address (filters, tab, page number) and scroll position when you
 * leave it. Coming back - with the browser's Back button OR an in-page "Back to ..." link (a link
 * with an arrow-left icon, .od-back, .ui-back or data-back) - reopens that same address and scrolls
 * to where you were, even on pages whose lists load or refresh after the first paint.
 * Stored per browser tab in sessionStorage; nothing is sent anywhere.
 */
(function () {
    'use strict';
    var PREFIX = 'returnSpot:';

    function store() { try { return window.sessionStorage; } catch (e) { return null; } }
    var ss = store();
    if (!ss) return;

    function keyFor(pathname) { return PREFIX + pathname.toLowerCase(); }
    function read(pathname) {
        try { return JSON.parse(ss.getItem(keyFor(pathname)) || 'null'); } catch (e) { return null; }
    }

    // Remember this page on the way out.
    function save() {
        try {
            ss.setItem(keyFor(location.pathname), JSON.stringify({
                url: location.pathname + location.search + location.hash,
                y: Math.round(window.scrollY || window.pageYOffset || 0)
            }));
        } catch (e) {}
    }
    window.addEventListener('pagehide', save);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') save(); });

    // Restore the scroll position, retrying for a moment while late content (live lists) fills in.
    function restore(y) {
        if (!(y > 0)) return;
        var tries = 0;
        (function tick() {
            window.scrollTo(0, y);
            if (Math.abs((window.scrollY || 0) - y) > 2 && ++tries < 20) setTimeout(tick, 100);
        })();
    }

    var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
    var cameBack = nav && nav.type === 'back_forward';
    var flagged = ss.getItem(PREFIX + 'restore') === location.pathname.toLowerCase();
    if (flagged) ss.removeItem(PREFIX + 'restore');

    if (cameBack || flagged) {
        var spot = read(location.pathname);
        if (spot && spot.url === location.pathname + location.search + location.hash) {
            if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
            if (document.readyState === 'complete') restore(spot.y);
            else window.addEventListener('load', function () { restore(spot.y); });
        }
    }
    // Pages restored from the back/forward cache keep their own scroll; nothing to do.

    // In-page "Back to ..." links go to the remembered address of that list instead of its first page.
    function isBackLink(a) {
        return a.hasAttribute('data-back') || a.classList.contains('od-back') || a.classList.contains('ui-back')
            || !!a.querySelector('.fa-arrow-left, [data-icon="arrow-left"]');
    }
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target === '_blank' || !isBackLink(a)) return;
        var href = a.getAttribute('href') || '';
        if (/^javascript:/i.test(href) || href.charAt(0) === '#') return;
        var dest;
        try { dest = new URL(a.href, location.href); } catch (err) { return; }
        if (dest.origin !== location.origin) return;
        var spot = read(dest.pathname);
        if (!spot) return;
        // A plain "Back to List" (no filters of its own) returns to the list exactly as it was left.
        if (dest.search && dest.search !== spot.url.replace(/#.*$/, '').replace(/^[^?]*/, '')) return;
        e.preventDefault();
        ss.setItem(PREFIX + 'restore', dest.pathname.toLowerCase());
        location.href = spot.url;
    });
})();
