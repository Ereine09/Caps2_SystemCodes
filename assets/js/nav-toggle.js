/**
 * nav-toggle.js — shared mobile drawer controller for the sidebar nav.
 *
 * Used by Admin, Staff, and Customer pages. Works with either sidebar
 * markup already in the DOM:
 *   - Admin/Staff: <div class="sidebar"> ... </div>
 *   - Customer:    <div class="customer-sidebar"> ... </div>
 *
 * On small screens (driven by the shared app-shell CSS in
 * assets/css/ui-kit.css) this
 * script injects two elements at runtime:
 *   1. .mobile-topbar  — sticky bar with a hamburger button + page title,
 *      inserted as the very first child of <body>.
 *   2. .nav-backdrop   — full-screen dim layer appended to <body>, shown
 *      while the drawer is open.
 *
 * The drawer opens/closes by toggling a `nav-open` class on <body>; the
 * top bar stays visible and its hamburger turns into the close (X). It
 * also closes via the backdrop, picking a nav link, or the Escape key.
 * On desktop, `sidebar-collapsed` on <body> switches to the icon rail.
 *
 * Idempotent: safe if this file is ever included more than once on a page.
 */
(function () {
    if (window.__dpsNavToggleInit) return;
    window.__dpsNavToggleInit = true;

    var SCROLL_KEY = 'dps_admin_sidebar_scroll';
    // Customer portal's own key — kept separate from SCROLL_KEY above so the
    // two sidebars' remembered positions can never collide or overwrite each
    // other even though they share the same underlying restoration function.
    var CUSTOMER_SCROLL_KEY = 'customerSidebarScrollTop';

    // Theme (light/dark) is owned by assets/js/theme.js; this file only places the toggle buttons.

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var sidebar = document.querySelector('.sidebar, .customer-sidebar');
        if (!sidebar) return;

        // --- Page title for the mobile top bar --------------------------------
        var brandLink = sidebar.querySelector(
            '.sidebar-brand h2, .sidebar-brand a, .customer-sidebar-brand a'
        );
        var activeLink = sidebar.querySelector('.nav-links a.active, .customer-sidebar-nav a.active');
        var activeLabel = activeLink && (activeLink.querySelector('.app-nav-label') || activeLink);
        var title =
            (activeLabel && activeLabel.textContent.trim()) ||
            (brandLink && brandLink.textContent.trim()) ||
            document.title ||
            'Menu';

        // --- Mobile top bar (hamburger + title) --------------------------------
        var topbar = document.createElement('div');
        topbar.className = 'mobile-topbar';
        topbar.innerHTML =
            '<button type="button" class="mobile-nav-toggle" aria-label="Open menu" aria-expanded="false">' +
            '<span></span><span></span><span></span>' +
            '</button>' +
            '<span class="mobile-topbar-title"></span>';
        topbar.querySelector('.mobile-topbar-title').textContent = title;
        document.body.insertBefore(topbar, document.body.firstChild);

        var toggleBtn = topbar.querySelector('.mobile-nav-toggle');

        // --- Backdrop ------------------------------------------------------------
        var backdrop = document.createElement('div');
        backdrop.className = 'nav-backdrop';
        document.body.appendChild(backdrop);

        // --- Dark Mode toggle (Admin/Staff only — never on the Customer sidebar).
        // Runs after the top bar above so both button copies can be created.
        if (sidebar.classList.contains('sidebar')) {
            initThemeToggle();
            // Admin/Staff — the sidebar nav list (.nav-links) is the element
            // that actually scrolls; .sidebar itself is a fixed,
            // overflow:hidden frame around it. See initSidebarScrollMemory()
            // below for why this is needed at all.
            initSidebarScrollMemory(sidebar.querySelector('.nav-links'), SCROLL_KEY);
            initAdminMessageNotifications();
        }

        // --- Dark Mode toggle (Customer portal only). A separate mechanism
        // from the Admin/Staff one above on purpose (different localStorage
        // key, different DOM attribute — data-theme on <html> vs a class on
        // <body>) so the two can never cross-affect each other even though
        // this file is shared. See initCustomerThemeToggle() below.
        if (sidebar.classList.contains('customer-sidebar')) {
            initCustomerThemeToggle();
            // Customer portal's structure differs from Admin/Staff: there is
            // no separate inner nav-list element with its own overflow —
            // .customer-sidebar itself is the scrolling container (brand
            // header + nav + logout footer all scroll together; see the
            // "overflow-y: auto" rule on .customer-sidebar in
            // customer/includes/header.php). So the SAME function is reused
            // here pointed at .customer-sidebar directly, with its own
            // sessionStorage key so it can never collide with the Admin/Staff
            // value even though this is the same underlying mechanism.
            initSidebarScrollMemory(sidebar.querySelector('.app-nav') || sidebar, CUSTOMER_SCROLL_KEY);
            initCustomerMessageNotifications();
        }

        // Desktop collapsed/expanded rail (ui-kit.css .app-sidebar). One key
        // per portal, like the theme keys, so Admin/Staff and Customer never
        // share a remembered state. The saved state is applied before first
        // paint by the inline script in each portal's sidebar/header partial.
        initSidebarCollapse(sidebar, sidebar.classList.contains('customer-sidebar')
            ? 'customerSidebarCollapsed'
            : 'dps_admin_sidebar_collapsed');

        // No dedicated close (X) button: the drawer closes via the backdrop,
        // the hamburger toggle, picking a nav link, or Escape (below).

        function openNav() {
            document.body.classList.add('nav-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.setAttribute('aria-label', 'Close menu');
        }

        function closeNav() {
            document.body.classList.remove('nav-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.setAttribute('aria-label', 'Open menu');
        }

        toggleBtn.addEventListener('click', function () {
            if (document.body.classList.contains('nav-open')) {
                closeNav();
            } else {
                openNav();
            }
        });

        backdrop.addEventListener('click', closeNav);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
                closeNav();
            }
        });

        // Selecting a nav link closes the drawer (mobile only; harmless on desktop).
        var navLinks = sidebar.querySelectorAll('.nav-links a, .customer-sidebar-nav a');
        for (var i = 0; i < navLinks.length; i++) {
            navLinks[i].addEventListener('click', closeNav);
        }
    });

    /**
     * Theme toggle placement for Admin/Staff: a labelled button in the sidebar
     * footer (next to Logout) and an icon-only copy in the mobile top bar.
     * State, icons and persistence are handled by assets/js/theme.js.
     */
    function initThemeToggle() {
        // Sidebar footer (labelled) + mobile top bar (icon only).
        placeThemeButton(document.querySelector('.sidebar-footer'), true, true);
        placeThemeButton(document.querySelector('.mobile-topbar'), false, false);
    }

    /** Creates a [data-theme-toggle] button; assets/js/theme.js renders and handles it. */
    function placeThemeButton(container, labelled, prepend) {
        if (!container || container.querySelector('[data-theme-toggle]')) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'theme-toggle-btn';
        btn.setAttribute('data-theme-toggle', '');
        if (labelled) btn.setAttribute('data-theme-label', '');
        if (prepend) container.insertBefore(btn, container.firstChild); else container.appendChild(btn);
        if (window.DPSTheme) window.DPSTheme.refreshButtons();
    }

    function dpsGetCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : '';
    }

    /**
     * Real-time Customer -> Admin/Staff new-message notification (badge +
     * sound), site-wide. Reuses the exact WebSocket relay already built for
     * this project (ws/ChatServer.php's 'push_notification' case, fed by
     * app/helpers/ws_push_helper.php's ws_push_notification() — already
     * called from inside notifications_create(), see
     * notify_staff_new_customer_message() in messaging_helper.php) — the
     * same connection pattern modules/admin/messages.php already opens for
     * itself, just made to run on every OTHER Admin/Staff page too. Skips
     * itself on messages.php on purpose: that page already has its own
     * complete WebSocket connection + notification sound
     * (<audio id="notification-sound"> + maybeNotify()) scoped to the open
     * conversation — running both here would open a second connection and
     * risk playing the sound twice for the same message.
     */
    function initAdminMessageNotifications() {
        // modules/admin/messages.php AND modules/staff/messages.php each
        // already have their own complete WebSocket + notification-sound
        // handling scoped to the open conversation - skip on both so this
        // site-wide version never opens a second connection or double-plays
        // the sound on those two specific pages.
        var path = window.location.pathname;
        if (path.indexOf('/admin/messages.php') !== -1 || path.indexOf('/staff/messages.php') !== -1) return;

        var token = dpsGetCookie('jwt_token_staff');
        if (!token || !window.WebSocket) return;

        if (window.__DPS_WS_ENABLED === false) return;
        var wsPort = window.__DPS_WS_PORT || 8080;
        var baseUrl = window.__DPS_BASE_URL || '';
        var sound = null;
        try {
            sound = new Audio('https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3');
        } catch (e) { /* no audio support — badge/panel still work */ }

        function refreshMessagesBadge() {
            fetch(baseUrl + '/modules/admin/messaging_api.php?action=get_unread_count')
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    var badge = document.getElementById('sidebar-messages-badge');
                    if (!badge || !result || !result.success) return;
                    var count = (result.data && result.data.unread_count) || 0;
                    badge.textContent = count;
                    badge.style.display = count > 0 ? '' : 'none';
                })
                .catch(function () {});
        }

        var ws;
        try {
            ws = new WebSocket((window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.hostname + ':' + wsPort + '/?token=' + encodeURIComponent(token));
        } catch (e) { return; }

        ws.onmessage = function (event) {
            try {
                var data = JSON.parse(event.data);
                if (!data || data.type !== 'notification') return;
                // Live admin pages (orders, reviews, remittance, customers, delivery) check for changes right away on
                // ANY notification instead of waiting for their next ~5s probe. No extra socket: this is the existing one.
                document.dispatchEvent(new CustomEvent('dps:live-poke', { detail: data }));
                if (data.reference_table !== 'tbl_messages') return;

                if (sound) { try { sound.currentTime = 0; sound.play().catch(function () {}); } catch (e) {} }
                refreshMessagesBadge();
            } catch (e) { /* ignore malformed frames */ }
        };
        ws.onerror = function () { /* silent — badge/sound simply won't be live this session */ };
    }

    /**
     * Real-time Admin/Staff -> Customer new-message notification (badge +
     * sound), site-wide on the Customer portal. Exact mirror of
     * initAdminMessageNotifications() above — same WS relay
     * (ws/ChatServer.php's 'push_notification' case), same
     * notifications_create()/ws_push_notification() pipeline (now also
     * pushing to customer_id targets — see notify_customer_new_staff_message()
     * in messaging_helper.php), just the jwt_token_customer cookie and the
     * Customer-portal endpoints. Skips itself on customer/messages.php: that
     * page already has its own WebSocket connection + reload-on-new-message
     * handling (now also playing this same sound - see setupWs() there).
     */
    function initCustomerMessageNotifications() {
        if (window.location.pathname.indexOf('/customer/messages.php') !== -1) return;

        var token = dpsGetCookie('jwt_token_customer');
        if (!token || !window.WebSocket) return;

        if (window.__DPS_WS_ENABLED === false) return;
        var wsPort = window.__DPS_WS_PORT || 8080;
        var baseUrl = window.__DPS_BASE_URL || '';
        var sound = null;
        try {
            sound = new Audio('https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3');
        } catch (e) { /* no audio support — badge/panel still work */ }

        function refreshMessagesBadge() {
            fetch(baseUrl + '/customer/messaging_api.php?action=get_unread_count')
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    var badge = document.getElementById('sidebar-messages-badge');
                    if (!badge || !result || !result.success) return;
                    var count = (result.data && result.data.unread_count) || 0;
                    badge.textContent = count;
                    badge.style.display = count > 0 ? '' : 'none';
                })
                .catch(function () {});
        }

        // The bell badge and toasts are owned by assets/js/toast.js; a push just asks it to check now.
        function refreshNotifBell() {
            if (window.dpsNotifRefresh) window.dpsNotifRefresh();
        }

        var ws;
        try {
            ws = new WebSocket((window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.hostname + ':' + wsPort + '/?token=' + encodeURIComponent(token));
        } catch (e) { return; }

        ws.onmessage = function (event) {
            try {
                var data = JSON.parse(event.data);
                if (!data || data.type !== 'notification') return;
                // Any pushed notification: toast.js polls right away (toast + bell count).
                document.dispatchEvent(new CustomEvent('dps:live-poke', { detail: data }));
                if (data.reference_table !== 'tbl_messages') return;

                if (sound) { try { sound.currentTime = 0; sound.play().catch(function () {}); } catch (e) {} }
                refreshMessagesBadge();
                refreshNotifBell();
            } catch (e) { /* ignore malformed frames */ }
        };
        ws.onerror = function () { /* silent — badge/sound simply won't be live this session */ };
    }

    /**
     * Sidebar scroll memory — shared by Admin/Staff (.nav-links) and the
     * Customer portal (.customer-sidebar itself — see call site above for
     * why that element differs between the two).
     *
     * Every Admin/Staff/Customer page is a normal, full server-rendered PHP
     * page load (not an SPA) — clicking a sidebar link navigates the browser
     * to a brand new document, so the scrolling element is torn down and
     * rebuilt from scratch on every click. Browsers only restore whole-page
     * scroll position on back/forward, never an inner element's scrollTop,
     * and never on a normal forward link click — so with many nav links at
     * 100% zoom, the sidebar always re-opened scrolled to its top on every
     * single navigation, no matter how far down the user had scrolled.
     *
     * Fix: persist scrollTop to sessionStorage on every scroll, and re-apply
     * it on the next page load — a lightweight stand-in for the scroll
     * restoration a real SPA would get for free. sessionStorage (not
     * localStorage) is used deliberately: this is "where was I scrolled to
     * in this browsing session", not a preference that should survive a
     * closed tab or outlive the session the way the dark-mode choice does.
     * Each portal gets its own storageKey so the two remembered positions
     * can never collide or overwrite each other.
     *
     * This never touches `nav-open` / the mobile drawer's open-closed state
     * — only the sidebar's internal scroll offset — so restoring it can
     * never leave the off-canvas mobile menu open after navigating; it just
     * means the drawer is already scrolled correctly the next time it opens.
     */
    function initSidebarScrollMemory(navLinksEl, storageKey) {
        if (!navLinksEl) return;

        var hasSavedPosition = false;
        try {
            var saved = sessionStorage.getItem(storageKey);
            if (saved !== null) {
                var savedTop = parseInt(saved, 10);
                if (!isNaN(savedTop) && savedTop > 0) {
                    hasSavedPosition = true;
                    // Deferred a frame: right at DOMContentLoaded, web fonts/
                    // icon glyphs may not have finished laying out yet, so
                    // .nav-links' real scrollHeight isn't reliable yet.
                    requestAnimationFrame(function () {
                        navLinksEl.scrollTop = savedTop;
                    });
                }
            }
        } catch (e) { /* sessionStorage unavailable — restoring is just skipped */ }

        // Only when this tab/session has no remembered position yet: if the
        // active (current-page) link would otherwise be hidden below/above
        // the visible part of the list, bring it into view. A saved
        // position always wins over this — never fight the user's own
        // scroll position once one exists.
        if (!hasSavedPosition) {
            requestAnimationFrame(function () {
                var active = navLinksEl.querySelector('a.active');
                if (!active) return;
                var listRect = navLinksEl.getBoundingClientRect();
                var linkRect = active.getBoundingClientRect();
                var isVisible = linkRect.top >= listRect.top && linkRect.bottom <= listRect.bottom;
                if (!isVisible) {
                    active.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        // Save continuously while scrolling (rAF-throttled to at most one
        // write per frame) so the position is already current the instant a
        // link is clicked and the browser starts tearing down the page —
        // no separate click/beforeunload handler needed.
        var scrollSaveQueued = false;
        navLinksEl.addEventListener('scroll', function () {
            if (scrollSaveQueued) return;
            scrollSaveQueued = true;
            requestAnimationFrame(function () {
                scrollSaveQueued = false;
                try {
                    sessionStorage.setItem(storageKey, String(navLinksEl.scrollTop));
                } catch (e) { /* sessionStorage unavailable — position just won't persist */ }
            });
        }, { passive: true });
    }

    function initCustomerThemeToggle() {
        // Sidebar footer (labelled, as before) + mobile top bar; the desktop top bar has its own (header.php).
        placeThemeButton(document.querySelector('.customer-sidebar-logout'), true, true);
        placeThemeButton(document.querySelector('.mobile-topbar'), false, false);
    }


    /**
     * Desktop sidebar collapse (full <-> icon rail). Mobile keeps using the
     * off-canvas drawer; the collapse button is hidden there by CSS, and the
     * `sidebar-collapsed` class has no effect below 901px.
     */
    function initSidebarCollapse(sidebar, storageKey) {
        // Tooltips for the icon-only rail: each link's visible label.
        var links = sidebar.querySelectorAll('.app-nav a, .app-sidebar-footer a');
        for (var i = 0; i < links.length; i++) {
            var lbl = links[i].querySelector('.app-nav-label, .app-footer-label');
            if (lbl && !links[i].title) links[i].title = lbl.textContent.trim();
        }

        var btn = sidebar.querySelector('.app-sidebar-collapse');
        if (!btn) return;

        function sync() {
            var collapsed = document.body.classList.contains('sidebar-collapsed');
            var text = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            btn.setAttribute('aria-label', text);
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.title = text;
        }

        btn.addEventListener('click', function () {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            try {
                localStorage.setItem(storageKey, collapsed ? '1' : '0');
            } catch (e) { /* state just won't persist */ }
            sync();
            // Charts and other size-aware widgets re-measure after the width transition.
            setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 240);
        });
        sync();
    }
})();
