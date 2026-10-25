/**
 * Live password strength bar + requirement chips for every "new password" box.
 *
 * Mark the input:  <input type="password" data-password-rules
 *                         data-confirm="confirm_password"          (optional: name of the confirm box)
 *                         data-avoid="username,first_name,email">  (optional: names of fields it must not contain)
 *
 * The rules are the same as the server's (app/helpers/auth_rules_helper.php); the server
 * still checks everything. The input fires a `pwrules` event ({ detail: { ok } }) whenever
 * the result changes, so a page can enable its submit button only when all rules pass.
 */
(function () {
    'use strict';

    var MIN = 8, MAX = 64;
    var COMMON = ['password', 'passw0rd', 'qwerty', '12345678', 'iloveyou', 'admin123', 'welcome', 'letmein', 'abc12345'];

    function injectStyle() {
        if (document.getElementById('pwrules-style')) return;
        var s = document.createElement('style');
        s.id = 'pwrules-style';
        s.textContent =
            '.pwrules{margin:8px 0 14px;padding:0;text-align:left;font-size:.8rem;line-height:1.3}' +
            '.pwrules .pwr-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:6px;color:var(--muted,#64748b);font-weight:600}' +
            '.pwrules .pwr-level{font-weight:700;color:var(--muted,#94a3b8)}' +
            '.pwrules .pwr-bar{height:4px;border-radius:99px;background:rgba(127,127,127,.18);overflow:hidden;margin-bottom:10px}' +
            '.pwrules .pwr-fill{display:block;height:100%;width:0;border-radius:inherit;background:#ef4444;transition:width .25s ease,background-color .25s ease}' +
            '.pwrules[data-level="weak"] .pwr-level{color:#ef4444}' +
            '.pwrules[data-level="fair"] .pwr-level{color:#f59e0b}.pwrules[data-level="fair"] .pwr-fill{background:#f59e0b}' +
            '.pwrules[data-level="good"] .pwr-level{color:#3b82f6}.pwrules[data-level="good"] .pwr-fill{background:#3b82f6}' +
            '.pwrules[data-level="strong"] .pwr-level{color:#16a34a}.pwrules[data-level="strong"] .pwr-fill{background:#16a34a}' +
            '.pwrules ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px 14px}' +
            '.pwrules li{display:inline-flex;align-items:center;gap:6px;color:var(--muted,#94a3b8);transition:color .2s}' +
            '.pwrules .pwr-dot{flex:none;width:14px;height:14px;border-radius:50%;background:rgba(127,127,127,.28);display:inline-grid;place-items:center;' +
            'font-size:9px;font-weight:700;line-height:1;color:transparent;transition:background-color .2s}' +
            '.pwrules li.is-met{color:var(--success,#16a34a);font-weight:600}' +
            '.pwrules li.is-met .pwr-dot{background:var(--success,#16a34a);color:#fff}' +
            '.pwrules .pwr-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}';
        document.head.appendChild(s);
    }

    function words(form, names) {
        var out = [];
        (names || '').split(',').forEach(function (n) {
            n = n.trim();
            if (!n || !form) return;
            var el = form.querySelector('[name="' + n + '"]');
            var v = el ? String(el.value || '').trim().toLowerCase() : '';
            if (v.indexOf('@') !== -1) v = v.split('@')[0];      // the name part of an email
            v.split(/\s+/).concat([v.replace(/\s+/g, '')]).forEach(function (w) { if (w.length >= 4) out.push(w); });
        });
        return out;
    }

    function setup(input) {
        if (input.dataset.pwrulesReady) return;
        input.dataset.pwrulesReady = '1';
        var form = input.form;
        var confirmEl = input.dataset.confirm && form ? form.querySelector('[name="' + input.dataset.confirm + '"]') : null;
        var avoidNames = input.dataset.avoid || '';

        var rules = [
            { short: MIN + ' Chars', text: MIN + '–' + MAX + ' characters', test: function (p) { var n = Array.from(p).length; return n >= MIN && n <= MAX; } },
            { short: 'A-Z', text: 'An uppercase letter (A–Z)', test: function (p) { return /\p{Lu}/u.test(p); } },
            { short: 'a-z', text: 'A lowercase letter (a–z)', test: function (p) { return /\p{Ll}/u.test(p); } },
            { short: '123', text: 'A number (0–9)', test: function (p) { return /\d/.test(p); } },
            { short: '@#$', text: 'A symbol (e.g. ! @ # $)', test: function (p) { return /[^\p{L}\p{N}\s]/u.test(p); } },
            { short: 'No edge spaces', text: 'No space at the start or end', test: function (p) { return p !== '' && p.trim() === p; } },
            { short: 'Not common', text: 'Not an easy password', test: function (p) {
                var flat = p.toLowerCase().replace(/[^a-z0-9]/g, '');
                return p !== '' && !COMMON.some(function (c) { return flat.indexOf(c) !== -1; });
            } }
        ];
        if (avoidNames) {
            rules.push({ short: 'No name/email', text: 'Doesn’t use your name or email', test: function (p) {
                var low = p.toLowerCase();
                return p !== '' && !words(form, avoidNames).some(function (w) { return low.indexOf(w) !== -1; });
            } });
        }
        if (confirmEl) {
            rules.push({ short: 'Match', text: 'Both passwords match', test: function (p) { return p !== '' && confirmEl.value === p; } });
        }

        // Strength bar + one dot chip per rule (green with a check once met).
        var list = document.createElement('div');
        list.className = 'pwrules';
        list.id = (input.id || input.name) + '-rules';
        list.innerHTML = '<div class="pwr-head"><span>Password Strength</span><span class="pwr-level" aria-live="polite"></span></div>' +
            '<div class="pwr-bar" aria-hidden="true"><span class="pwr-fill"></span></div><ul aria-label="Password requirements"></ul>';
        var ul = list.querySelector('ul'), levelEl = list.querySelector('.pwr-level'), fillEl = list.querySelector('.pwr-fill');
        rules.forEach(function (r) {
            var li = document.createElement('li');
            li.title = r.text;
            li.innerHTML = '<span class="pwr-dot" aria-hidden="true">✓</span><span class="pwr-text" aria-hidden="true"></span><span class="pwr-sr"></span>';
            li.querySelector('.pwr-text').textContent = r.short;
            r.li = li;
            ul.appendChild(li);
        });
        // Below the input's wrapper (the box that also holds the show/hide eye), or the input itself.
        var anchor = input.closest('.input-box, .password-field, .password-container') || input;
        anchor.insertAdjacentElement('afterend', list);
        var described = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        described.push(list.id);
        input.setAttribute('aria-describedby', described.join(' '));

        var lastOk = null;
        function check() {
            var p = input.value || '';
            var ok = true, metCount = 0;
            rules.forEach(function (r) {
                var met = r.test(p);
                ok = ok && met;
                if (met) metCount++;
                r.li.classList.toggle('is-met', met);
                r.li.querySelector('.pwr-sr').textContent = r.text + (met ? ' (done)' : ' (not yet)');
            });
            var ratio = p === '' ? 0 : metCount / rules.length;
            var level = p === '' ? '' : ok ? 'strong' : ratio >= 0.75 ? 'good' : ratio >= 0.5 ? 'fair' : 'weak';
            list.dataset.level = level;
            levelEl.textContent = level ? level.charAt(0).toUpperCase() + level.slice(1) : '';
            fillEl.style.width = Math.round(ratio * 100) + '%';
            // Browser-level block too (the server checks again anyway).
            input.setCustomValidity(ok || p === '' ? '' : 'Your password does not meet all the requirements yet.');
            if (ok !== lastOk) {
                lastOk = ok;
                input.dispatchEvent(new CustomEvent('pwrules', { detail: { ok: ok } }));
            }
        }
        input.addEventListener('input', check);
        if (confirmEl) confirmEl.addEventListener('input', check);
        if (avoidNames && form) form.addEventListener('input', function (e) { if (e.target !== input) check(); });
        if (form) form.addEventListener('reset', function () { setTimeout(check, 0); });
        check();
    }

    function init() {
        injectStyle();
        document.querySelectorAll('input[data-password-rules]').forEach(setup);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
