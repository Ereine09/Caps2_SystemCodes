/*
 * Chat replies + reactions + delete (for me / for everyone), shared by the admin, staff and customer support chats.
 *
 * Page wiring (each page keeps its own fetch/render/send code):
 *   ChatExtras.init({ container, form, react: { url, csrf, data }, refresh, noDelete });
 *   (noDelete: the ⋮ menu offers only "Copy text" - used by the customer <-> rider chat)
 *   in its message template:  '<div class="message ..."' + ChatExtras.attrs(m) + '>'
 *                              + ChatExtras.quote(m) + bubble + ChatExtras.reactions(m) + ChatExtras.tools(m) + '</div>'
 * The form gets a hidden reply_to_id field, so the page's existing FormData(form) send carries it.
 * The bubble and its tools (more, reply, react) are moved into one .cx-row so the round buttons sit beside it.
 * Delete: "for me" posts action=delete_for_me (anyone); "for everyone" posts action=unsend (sender only),
 * both to the same endpoint as reactions (react.url / data / csrf).
 * Server side: app/helpers/messaging_helper.php (messaging_attach_extras / messaging_toggle_reaction /
 * messaging_unsend / messaging_hide_for_me).
 */
(function () {
    'use strict';

    var EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    var cfg = null, byId = {}, picker = null, pickerFor = 0, bar = null, hidden = null, confirmBox = null;

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }
    // Messages may arrive HTML-encoded (customer text is stored escaped); show them as plain text.
    function plain(t) {
        var d = document.createElement('textarea');
        d.innerHTML = t == null ? '' : String(t);
        return d.value;
    }
    function snippet(t) {
        t = plain(t).replace(/\s+/g, ' ').trim();
        return t.length > 120 ? t.slice(0, 117) + '…' : t;
    }

    function css() {
        var s = document.createElement('style');
        s.textContent =
            '.message[data-mid]{position:relative}' +
            '.cx-quote{display:block;max-width:100%;margin:2px 0 4px;padding:6px 10px;border-left:3px solid var(--chat-primary,#6d5bd0);border-radius:8px;background:rgba(99,82,200,.08);color:inherit;font-size:.78rem;line-height:1.35;cursor:pointer;text-align:left;opacity:.9}' +
            '.cx-quote b{display:block;font-size:.72rem;margin-bottom:1px;color:var(--chat-primary,#6d5bd0)}' +
            '.cx-quote:hover{opacity:1}' +
            '.cx-reacts{display:flex;flex-wrap:wrap;gap:4px;margin-top:-6px;position:relative;z-index:1}' +
            '.message.sent .cx-reacts{justify-content:flex-end}' +
            '.cx-chip{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:999px;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);font-size:.8rem;line-height:1.5;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.08);color:inherit;font-family:inherit}' +
            '.cx-chip.mine{border-color:var(--chat-primary,#6d5bd0);background:rgba(99,82,200,.12)}' +
            '.cx-chip small{font-size:.7rem;font-weight:700}' +
            // Always visible under the bubble (no hunting with the mouse); brighter when the message is hovered.
            /* Messenger-style: round icon buttons beside the bubble (left of sent, right of received), shown on hover/tap */
            '.cx-row{display:flex;align-items:center;gap:6px;max-width:100%}' +
            '.message.sent .cx-row{flex-direction:row-reverse}' +
            '.cx-row>.message-content{min-width:0}' +
            /* Always shown (the user found hover-only buttons too easy to lose). */
            '.cx-tools{display:flex;flex-shrink:0;gap:4px;padding:4px}' +
            '.message.received .cx-tools{flex-direction:row-reverse}' +
            '.cx-tools button{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border:0;border-radius:50%;background:var(--surface-2,#f1f5f9);color:var(--muted,#64748b);cursor:pointer;font-size:.85rem;transition:background .12s ease,color .12s ease}' +
            '.cx-tools button:hover{background:var(--border,#e2e8f0);color:var(--text,#1e293b)}' +
            '.message[data-mid] .message-content{cursor:default}' +
            '.cx-picker{position:fixed;z-index:4000;display:flex;gap:2px;padding:4px 6px;border-radius:999px;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);box-shadow:0 8px 24px rgba(0,0,0,.18)}' +
            '.cx-picker button{border:0;background:transparent;font-size:1.35rem;width:38px;height:38px;border-radius:50%;cursor:pointer;transition:transform .12s ease}' +
            '.cx-picker button:hover{transform:scale(1.25);background:var(--surface-2,#f1f5f9)}' +
            '.cx-picker button.mine{background:rgba(99,82,200,.15)}' +
            '.cx-bar{display:flex;align-items:center;gap:10px;margin:0 0 8px;padding:8px 12px;border-left:3px solid var(--chat-primary,#6d5bd0);border-radius:8px;background:rgba(99,82,200,.08);font-size:.82rem}' +
            '.cx-bar div{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
            '.cx-bar b{color:var(--chat-primary,#6d5bd0)}' +
            '.cx-bar button{border:0;background:transparent;cursor:pointer;color:var(--muted,#64748b);font-size:1rem}' +
            // A message deleted for everyone: a quiet outlined placeholder instead of the normal bubble.
            '.message[data-cx-unsent] .message-content{background:transparent!important;color:var(--muted,#64748b)!important;border:1px dashed var(--border,#cbd5e1)!important;box-shadow:none!important;font-style:italic}' +
            '.cx-confirm{position:fixed;z-index:4000;width:220px;padding:6px;border-radius:12px;background:var(--surface,#fff);color:var(--text,#1e293b);border:1px solid var(--border,#e2e8f0);box-shadow:0 8px 24px rgba(0,0,0,.18);font-size:.82rem}' +
            '.cx-confirm p{margin:0 0 10px;line-height:1.4}' +
            '.cx-confirm p small{display:block;margin-top:4px;color:var(--muted,#64748b)}' +
            '.cx-confirm div{display:grid;gap:2px}' +
            '.cx-confirm button{height:36px;padding:0 12px;border-radius:8px;border:0;background:transparent;color:inherit;cursor:pointer;font:inherit;font-weight:600;text-align:left;display:flex;align-items:center;gap:8px}' +
            '.cx-confirm button:hover{background:var(--surface-2,#f1f5f9)}' +
            '.cx-confirm button i{width:16px;text-align:center;color:var(--muted,#64748b)}' +
            '.cx-confirm button[data-cx-del-all],.cx-confirm button[data-cx-del-all] i{color:#dc2626}' +
            '.message.cx-flash .message-content{animation:cx-flash 1.2s ease}' +
            '@keyframes cx-flash{0%,60%{box-shadow:0 0 0 4px rgba(99,82,200,.45)}100%{box-shadow:none}}';
        document.head.appendChild(s);
    }

    /* ---- template helpers ---- */
    function attrs(m) {
        if (!m || m.sender_type === 'system') return '';
        byId[m.id] = m;
        return ' data-mid="' + Number(m.id) + '"' + (m.unsent ? ' data-cx-unsent="1"' : '');
    }
    function quote(m) {
        var r = m && m.reply_to;
        if (!r) return '';
        return '<button type="button" class="cx-quote" data-cx-goto="' + Number(r.id) + '"><b><i class="fas fa-reply"></i> ' +
            esc(r.sender_name) + '</b>' + esc(snippet(r.message)) + '</button>';
    }
    function reactions(m) {
        var list = (m && m.reactions) || [];
        if (!list.length) return '';
        return '<div class="cx-reacts">' + list.map(function (r) {
            return '<button type="button" class="cx-chip' + (r.mine ? ' mine' : '') + '" data-cx-react="' + esc(r.emoji) +
                '" data-cx-mid="' + Number(m.id) + '" title="' + (r.mine ? 'Remove your reaction' : 'React') + '">' +
                esc(r.emoji) + (r.count > 1 ? ' <small>' + Number(r.count) + '</small>' : '') + '</button>';
        }).join('') + '</div>';
    }
    function tools(m) {
        if (!m || m.sender_type === 'system') return '';
        // Order: more (⋮), reply, react - mirrored for received messages by CSS.
        var more = '<button type="button" data-cx-delete="' + Number(m.id) + '" title="More" aria-label="More options"><i class="fas fa-ellipsis-vertical"></i></button>';
        if (m.unsent) return '<div class="cx-tools">' + more + '</div>'; // a deleted placeholder can still be cleared from your view
        return '<div class="cx-tools">' + more +
            '<button type="button" data-cx-reply="' + Number(m.id) + '" title="Reply" aria-label="Reply"><i class="fas fa-reply"></i></button>' +
            '<button type="button" data-cx-pick="' + Number(m.id) + '" title="React" aria-label="React"><i class="far fa-face-smile"></i></button></div>';
    }

    /* ---- delete: for me (anyone, own view only) / for everyone (sender only) ---- */
    function closeConfirm() {
        if (confirmBox) { confirmBox.remove(); confirmBox = null; }
        document.querySelectorAll('.message.cx-busy').forEach(function (el) { el.classList.remove('cx-busy'); });
    }
    function copyText(id) {
        closeConfirm();
        var t = plain((byId[id] || {}).message);
        var done = function () { (window.toastOr || function () {})('Message copied.', 'success'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(t).then(done, function () {});
        } else {
            var ta = document.createElement('textarea');
            ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            ta.remove();
        }
    }

    /* ---- layout: put the bubble and its tools on one row ---- */
    function arrange() {
        cfg.container.querySelectorAll('.message[data-mid]').forEach(function (msg) {
            var bubble = msg.querySelector(':scope > .message-content'), tools = msg.querySelector(':scope > .cx-tools');
            if (!bubble || !tools) return;
            var row = document.createElement('div');
            row.className = 'cx-row';
            msg.insertBefore(row, bubble);
            row.appendChild(bubble);
            row.appendChild(tools);
        });
    }
    function askDelete(id, anchor) {
        closePicker();
        closeConfirm();
        var m = byId[id] || {};
        var forAll = !!m.can_unsend && !cfg.noDelete;
        confirmBox = document.createElement('div');
        confirmBox.className = 'cx-confirm';
        confirmBox.setAttribute('data-for', String(id));
        confirmBox.setAttribute('role', 'menu');
        confirmBox.setAttribute('aria-label', 'Message options');
        confirmBox.innerHTML = '<div>' +
            (!m.unsent ? '<button type="button" data-cx-copy="' + id + '"><i class="far fa-copy"></i> Copy text</button>' : '') +
            (forAll ? '<button type="button" data-cx-del-all="' + id + '" title="Removes it for both sides"><i class="fas fa-users"></i> Delete for everyone</button>' : '') +
            (!cfg.noDelete ? '<button type="button" data-cx-del-me="' + id + '" title="Hides it only from your chat"><i class="fas fa-user"></i> Delete for me</button>' : '') + '</div>';
        document.body.appendChild(confirmBox);
        confirmAt = '.message[data-mid="' + id + '"] [data-cx-delete]';
        placeConfirm(anchor);
        confirmBox.querySelector('button').focus();
        var msg = anchor.closest('.message');
        if (msg) msg.classList.add('cx-busy'); // keep the tools visible while the menu is open
    }
    function deleteMessage(id, forEveryone) {
        closeConfirm();
        if (hidden && String(hidden.value) === String(id)) cancelReply();
        if (forEveryone) {
            post('unsend', { message_id: id }, 'Could not delete the message for everyone.');
        } else {
            // Hide it right away; the refresh confirms it.
            var el = cfg.container.querySelector('.message[data-mid="' + id + '"]');
            if (el) el.remove();
            post('delete_for_me', { message_id: id }, 'Could not delete the message.');
        }
    }

    /* ---- reply ---- */
    function startReply(id) {
        var m = byId[id];
        if (!m || !cfg.form) return;
        hidden.value = id;
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'cx-bar';
            cfg.form.insertBefore(bar, cfg.form.firstChild);
        }
        bar.innerHTML = '<i class="fas fa-reply"></i><div>Replying to <b>' + esc(m.sender_name || 'message') + '</b>: ' +
            esc(snippet(m.message)) + '</div><button type="button" data-cx-cancel aria-label="Cancel reply"><i class="fas fa-times"></i></button>';
        var input = cfg.form.querySelector('textarea, input[name="message"]');
        if (input) input.focus();
    }
    function cancelReply() {
        if (hidden) hidden.value = '';
        if (bar) { bar.remove(); bar = null; }
    }

    /* ---- reactions ---- */
    function closePicker() {
        if (picker) { picker.remove(); picker = null; pickerFor = 0; }
    }
    function openPicker(id, anchor) {
        closePicker();
        var m = byId[id] || {};
        var mine = ((m.reactions || []).filter(function (r) { return r.mine; })[0] || {}).emoji;
        picker = document.createElement('div');
        picker.className = 'cx-picker';
        picker.innerHTML = EMOJIS.map(function (e) {
            return '<button type="button" data-cx-react="' + e + '" data-cx-mid="' + id + '"' + (e === mine ? ' class="mine"' : '') + '>' + e + '</button>';
        }).join('');
        document.body.appendChild(picker);
        pickerFor = id;
        pickerAt = anchor.hasAttribute('data-cx-pick') ? '.message[data-mid="' + id + '"] [data-cx-pick]' : '.message[data-mid="' + id + '"] .message-content';
        placePicker(anchor);
    }

    /* ---- keep an open menu/picker beside its message ----
       The chat refreshes and scrolls by itself every few seconds; instead of closing the popup
       then, it follows its message (and only closes if that message is gone). */
    var confirmAt = '', pickerAt = '';
    function findAnchor(sel) {
        return sel && cfg.container ? cfg.container.querySelector(sel) : null;
    }
    function placeConfirm(anchor) {
        anchor = anchor || findAnchor(confirmAt);
        if (!confirmBox) return;
        if (!anchor) { closeConfirm(); return; }
        var r = anchor.getBoundingClientRect(), w = confirmBox.offsetWidth, h = confirmBox.offsetHeight;
        confirmBox.style.left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8) + 'px';
        confirmBox.style.top = (r.top - h - 6 < 8 ? r.bottom + 6 : r.top - h - 6) + 'px';
    }
    function placePicker(anchor) {
        anchor = anchor || findAnchor(pickerAt);
        if (!picker) return;
        if (!anchor) { closePicker(); return; }
        var r = anchor.getBoundingClientRect(), w = picker.offsetWidth, h = picker.offsetHeight;
        picker.style.left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8) + 'px';
        picker.style.top = (r.top - h - 6 < 8 ? r.bottom + 6 : r.top - h - 6) + 'px';
    }
    function follow() {
        placeConfirm();
        placePicker();
    }
    function react(id, emoji) {
        closePicker();
        post('react', { message_id: id, emoji: emoji }, 'Could not save the reaction.');
    }

    // POST an action to the page's chat endpoint (react.url) with its extra data + CSRF, then refresh.
    function post(action, fields, failText) {
        var fd = new FormData();
        fd.append('action', action);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        var data = cfg.react.data || {};
        Object.keys(data).forEach(function (k) { fd.append(k, typeof data[k] === 'function' ? data[k]() : data[k]); });
        var headers = { 'Accept': 'application/json' };
        if (cfg.react.csrf) { fd.append('csrf_token', cfg.react.csrf); headers['X-CSRF-Token'] = cfg.react.csrf; }
        fetch(cfg.react.url, { method: 'POST', body: fd, headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (d) {
                if (!d || !d.success) { (window.toastOr || alert)((d && d.message) || failText, 'error'); }
                if (cfg.refresh) cfg.refresh();
            })
            .catch(function () { (window.toastOr || alert)('Could not reach the server.', 'error'); });
    }

    function goto(id) {
        var el = cfg.container.querySelector('.message[data-mid="' + id + '"]');
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.remove('cx-flash');
        void el.offsetWidth;
        el.classList.add('cx-flash');
    }

    function init(options) {
        cfg = options;
        if (!cfg || !cfg.container) return;
        css();
        arrange();
        new MutationObserver(function () { arrange(); follow(); }).observe(cfg.container, { childList: true, subtree: true });
        if (cfg.form) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'reply_to_id';
            cfg.form.appendChild(hidden);
            // The page builds FormData(form) synchronously in its own submit handler; clear afterwards.
            cfg.form.addEventListener('submit', function () { setTimeout(cancelReply, 0); });
        }
        document.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-cx-reply],[data-cx-pick],[data-cx-react],[data-cx-goto],[data-cx-cancel],[data-cx-delete],[data-cx-del-me],[data-cx-del-all],[data-cx-del-no],[data-cx-copy]') : null;
            if (!t) {
                // Phones have no hover: tapping a bubble shows its tools.
                var tapped = e.target.closest ? e.target.closest('.message[data-mid]') : null;
                cfg.container.querySelectorAll('.message.cx-open').forEach(function (el) { if (el !== tapped) el.classList.remove('cx-open'); });
                if (tapped && cfg.container.contains(tapped) && e.target.closest('.message-content')) tapped.classList.toggle('cx-open');
                if (picker && !e.target.closest('.cx-picker')) closePicker();
                if (confirmBox && !e.target.closest('.cx-confirm')) closeConfirm();
                return;
            }
            if (t.hasAttribute('data-cx-delete') && confirmBox && confirmBox.getAttribute('data-for') === t.getAttribute('data-cx-delete')) closeConfirm();
            else if (t.hasAttribute('data-cx-delete')) askDelete(+t.getAttribute('data-cx-delete'), t);
            else if (t.hasAttribute('data-cx-del-me')) deleteMessage(+t.getAttribute('data-cx-del-me'), false);
            else if (t.hasAttribute('data-cx-del-all')) deleteMessage(+t.getAttribute('data-cx-del-all'), true);
            else if (t.hasAttribute('data-cx-del-no')) closeConfirm();
            else if (t.hasAttribute('data-cx-copy')) copyText(+t.getAttribute('data-cx-copy'));
            else if (t.hasAttribute('data-cx-reply')) startReply(+t.getAttribute('data-cx-reply'));
            else if (t.hasAttribute('data-cx-pick')) {
                var id = +t.getAttribute('data-cx-pick');
                if (pickerFor === id) closePicker(); else openPicker(id, t);
            }
            else if (t.hasAttribute('data-cx-react')) react(+t.getAttribute('data-cx-mid'), t.getAttribute('data-cx-react'));
            else if (t.hasAttribute('data-cx-goto')) goto(+t.getAttribute('data-cx-goto'));
            else if (t.hasAttribute('data-cx-cancel')) cancelReply();
        });
        // Shortcuts: double-click a bubble (or press and hold it on a phone) to open the emoji picker.
        function bubbleOf(e) {
            // An unsent message can't be reacted to.
            var c = e.target.closest ? e.target.closest('.message[data-mid]:not([data-cx-unsent]) .message-content') : null;
            return c && cfg.container.contains(c) ? c : null;
        }
        cfg.container.addEventListener('dblclick', function (e) {
            var c = bubbleOf(e);
            if (!c) return;
            if (window.getSelection) window.getSelection().removeAllRanges();
            openPicker(+c.closest('.message').getAttribute('data-mid'), c);
        });
        var holdTimer = null;
        cfg.container.addEventListener('touchstart', function (e) {
            var c = bubbleOf(e);
            if (!c) return;
            holdTimer = setTimeout(function () { openPicker(+c.closest('.message').getAttribute('data-mid'), c); }, 500);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
            cfg.container.addEventListener(ev, function () { clearTimeout(holdTimer); }, { passive: true });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { if (confirmBox) closeConfirm(); else if (picker) closePicker(); else if (bar) cancelReply(); }
        });
        window.addEventListener('scroll', follow, true);
        window.addEventListener('resize', follow);
    }

    window.ChatExtras = { init: init, attrs: attrs, quote: quote, reactions: reactions, tools: tools };
})();
