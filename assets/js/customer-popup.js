/*
 * Customer profile pop-up (admin + staff).
 * Any link to customer_details.php opens the profile in a window over the current page instead of
 * leaving it. The profile itself is customer_details.php?embed=1 (no sidebar), shown in an iframe,
 * so it is exactly the same page and the same permission checks.
 * Ctrl/Cmd/Shift/middle-click still open the full page normally.
 */
(function () {
    'use strict';

    var modal, frame, fullLink, lastFocus = null;

    function build() {
        var css = document.createElement('style');
        css.textContent =
            '.cp-modal .cp-dialog{width:min(1100px,100%);height:min(88vh,900px);max-height:calc(100vh - 32px);padding:0;overflow:hidden}' +
            '.cp-modal .cp-body{flex:1;min-height:0;position:relative;background:var(--surface-2,#f1f5f9)}' +
            '.cp-modal iframe{display:block;width:100%;height:100%;border:0;background:transparent}' +
            '.cp-modal .cp-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;color:var(--muted,#64748b);font-size:.9rem}' +
            '.cp-modal .cp-actions{display:flex;align-items:center;gap:6px}' +
            '.cp-modal .cp-full{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:8px;font-size:.82rem;font-weight:600;color:var(--link,#4a3e94);text-decoration:none}' +
            '.cp-modal .cp-full:hover{background:var(--surface-2,#f1f5f9)}' +
            '@media (max-width:640px){.cp-modal{padding:0}.cp-modal .cp-dialog{height:100%;max-height:none;border-radius:0}}';
        document.head.appendChild(css);

        modal = document.createElement('div');
        modal.className = 'ui-modal cp-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'cp-title');
        modal.innerHTML =
            '<div class="ui-modal-dialog cp-dialog">' +
            '  <div class="ui-modal-head">' +
            '    <h2 class="ui-modal-title" id="cp-title"><i class="fas fa-user"></i> Customer Profile</h2>' +
            '    <div class="cp-actions">' +
            '      <a class="cp-full" href="#" title="Open the profile on its own page"><i class="fas fa-expand"></i> Full screen</a>' +
            '      <button type="button" class="ui-close" data-cp-close aria-label="Close"><i class="fas fa-times"></i></button>' +
            '    </div>' +
            '  </div>' +
            '  <div class="cp-body">' +
            '    <div class="cp-loading"><i class="fas fa-spinner fa-spin"></i> Loading profile…</div>' +
            '    <iframe title="Customer profile"></iframe>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(modal);
        frame = modal.querySelector('iframe');
        fullLink = modal.querySelector('.cp-full');

        frame.addEventListener('load', function () {
            var loading = modal.querySelector('.cp-loading');
            if (loading) loading.hidden = true;
            try {
                // Logged out meanwhile: the frame landed on the login page - go there for real.
                if (/\/modules\/auth\/login\.php/.test(frame.contentWindow.location.pathname)) {
                    window.location.href = frame.contentWindow.location.href;
                }
            } catch (e) {}
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('[data-cp-close]')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) close();
        });
    }

    function open(href) {
        if (!modal) build();
        lastFocus = document.activeElement;
        var url = new URL(href, window.location.href);
        fullLink.href = url.href;
        url.searchParams.set('embed', '1');
        modal.querySelector('.cp-loading').hidden = false;
        frame.src = url.href;
        modal.classList.add('open');
        document.documentElement.style.overflow = 'hidden';
        modal.querySelector('[data-cp-close]').focus();
    }

    function close() {
        modal.classList.remove('open');
        document.documentElement.style.overflow = '';
        frame.src = 'about:blank';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest('a[href*="customer_details.php"]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('data-no-popup')) return;
        e.preventDefault();
        open(a.href);
    });
})();
