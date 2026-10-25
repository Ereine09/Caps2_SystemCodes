<?php
/**
 * Rider ↔ customer chat (Flutter ChatScreen) — rider_messaging_api.php get_conversation /
 * send_message / react. The API only allows customers this rider has a delivery for.
 * "Seen" under the rider's latest message the customer has read; reply + react on any message
 * (assets/js/chat-extras.js, shared with the support chat; no delete here).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

$customer_id = (int) ($_GET['customer_id'] ?? 0);
$from_order = (int) ($_GET['order'] ?? 0);
if ($customer_id <= 0) {
    header('Location: ' . BASE_URL . '/rider/messages.php');
    exit();
}
$back = $from_order > 0 ? BASE_URL . '/rider/order.php?id=' . $from_order : BASE_URL . '/rider/messages.php';
rider_page_start('Chat', ['back' => $back, 'body_class' => 'rd-chat-page']);
?>
<style>
    .rd-msg { display: flex; flex-direction: column; max-width: 88%; --chat-primary: var(--primary); }
    .rd-msg.sent { align-self: flex-end; align-items: flex-end; }
    .rd-msg.received { align-self: flex-start; align-items: flex-start; }
    .rd-msg .rd-bubble { max-width: 100%; align-self: auto; }
    .rd-msg .cx-tools button { width: 30px; height: 30px; font-size: .8rem; }
    .rd-msg .cx-reacts { margin-top: -4px; }
    .rd-msg .cx-quote { border: 0; border-left: 3px solid var(--primary); font-size: .8rem; padding: 6px 10px; }
    .rd-seen { font-size: .72rem; color: var(--muted); margin-top: 3px; padding: 0 4px; display: flex; align-items: center; gap: 4px; }
    .rd-seen i { color: var(--primary); }
    .rd-composer form { flex-wrap: wrap; }
    .rd-composer form .rd-input { flex: 1; min-width: 0; }
    .rd-composer .cx-bar { flex-basis: 100%; margin: 0; }
    /* Phones (no hover): tap a message for reply / react; press and hold to react. */
    @media (hover: none) {
        .rd-msg .cx-tools { display: none; }
        .rd-msg.cx-open .cx-tools { display: flex; }
    }
</style>
<div class="rd-chat" id="chat" data-customer="<?php echo $customer_id; ?>" aria-live="polite"></div>
<div class="rd-composer">
    <form id="composer" autocomplete="off">
        <input class="rd-input" id="msg" name="message" maxlength="1000" placeholder="Type a message…" aria-label="Message" enterkeyhint="send">
        <button type="submit" class="rd-btn rd-btn-primary" id="send" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </form>
</div>
<script src="<?php echo rider_asset('assets/js/chat-extras.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc, CX = window.ChatExtras;
    window.toastOr = window.toastOr || function (m, t) { R.notify(m, t); };
    var chat = document.getElementById('chat');
    var cid = parseInt(chat.dataset.customer, 10);
    var lastSig = '', loaded = false;
    // "Seen 12:55 PM" (today) or "Seen Oct 12" (earlier).
    function seenTime(at) {
        var d = R.parseDate ? R.parseDate(at) : new Date(String(at || '').replace(' ', 'T'));
        if (!d || isNaN(d)) return '';
        if (R.isToday && !R.isToday(at)) return ' ' + d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        return ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    var form = document.getElementById('composer'), input = document.getElementById('msg'), send = document.getElementById('send');

    // Customer name for the title (display only).
    R.call('rider_messaging_api.php?action=get_conversations').then(function (res) {
        var c = ((res.data && res.data.conversations) || []).filter(function (x) { return +x.customer_id === cid; })[0];
        if (c && c.customer_name) {
            document.querySelector('.rd-topbar-title').textContent = c.customer_name;
            document.title = c.customer_name + ' · DPS Rider';
        }
    }, function () {});

    function load() {
        return R.call('rider_messaging_api.php?action=get_conversation&customer_id=' + cid).then(function (res) {
            var msgs = (res.data && res.data.messages) || [];
            // Re-render when anything visible changes: new messages, reactions, replies or "Seen".
            var sig = JSON.stringify(msgs.map(function (m) { return [m.id, m.is_read, m.reactions, m.reply_to ? m.reply_to.id : 0]; }));
            loaded = true;
            if (sig === lastSig) return;
            var first = lastSig === '';
            var atBottom = (window.innerHeight + window.scrollY) >= document.body.scrollHeight - 120;
            lastSig = sig;
            // "Seen" goes under your latest message the customer has read.
            var seenId = 0;
            msgs.forEach(function (m) { if (m.sender_type === 'rider' && +m.is_read === 1) seenId = +m.id; });
            chat.innerHTML = msgs.length ? msgs.map(function (m) {
                var me = m.sender_type === 'rider';
                if (me) m.sender_name = 'You'; // shown in the reply bar / quotes
                if (m.reply_to && m.reply_to.sender_type === 'rider') m.reply_to.sender_name = 'You';
                return '<div class="rd-msg message ' + (me ? 'sent' : 'received') + '"' + (CX ? CX.attrs(m) : '') + '>' +
                    (CX ? CX.quote(m) : '') +
                    '<div class="rd-bubble message-content ' + (me ? 'is-me' : 'is-them') + '">' + esc(m.message) + '<time>' + esc(R.fmtDate(m.created_at)) + '</time></div>' +
                    (CX ? CX.reactions(m) + CX.tools(m) : '') +
                    (me && +m.id === seenId ? '<div class="rd-seen"><i class="fas fa-check-double"></i> Seen' + seenTime(m.read_at) + '</div>' : '') +
                    '</div>';
            }).join('') : '<div class="rd-empty"><i class="fas fa-comments"></i><strong>No messages yet</strong>Send the customer a message about their delivery.</div>';
            if (first || atBottom) window.scrollTo(0, document.body.scrollHeight);
        }, function (err) {
            if (!loaded) {
                chat.innerHTML = R.errorBox(err);
                if (!err.network) { input.disabled = true; send.disabled = true; }
            }
        });
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;
        var replyField = form.querySelector('[name="reply_to_id"]');
        var replyTo = replyField ? (+replyField.value || 0) : 0; // read now: ChatExtras clears it right after submit
        R.guarded(send, '', function () {
            return R.call('rider_messaging_api.php', { body: { action: 'send_message', customer_id: cid, message: text, reply_to_id: replyTo } });
        }).then(function () {
            input.value = '';
            lastSig = '';
            return load();
        }, R.fail);
    });
    chat.addEventListener('click', function (e) { if (e.target.closest('[data-retry]')) load(); });
    if (CX) {
        CX.init({
            container: chat,
            form: form,
            react: { url: R.BASE + '/modules/rider/rider_messaging_api.php', csrf: window.__DPS_CSRF_TOKEN || '', data: { customer_id: cid } },
            refresh: function () { lastSig = ''; load(); },
            noDelete: true
        });
    }
    chat.innerHTML = R.skeleton(3, 'rd-skel-line');
    load();
    R.live(load, 8000, 'chat:' + cid);
});
</script>
<?php rider_page_end(''); ?>
