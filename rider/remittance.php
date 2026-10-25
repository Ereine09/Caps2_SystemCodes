<?php
/**
 * Rider remittance (Flutter "My Remittance"), laid out like a wallet app: a balance card with
 * the two ways to pay, the cash orders to pay, and the payment history as a transaction list.
 * Backed entirely by rider_remittance_api.php: get_overview (summary, COD orders still to remit,
 * history), check_reference and submit_remittance. The expected amount is always calculated by
 * the server from the real orders.
 *
 * Two ways to pay the shop:
 *   - Cash: the rider brings it to the physical store and the staff record it there
 *     (admin/staff Remittance page). Nothing is sent from here.
 *   - GCash / Bank Transfer, two ways:
 *       Pay now      - PayMongo hosted checkout (start_online_remittance). Approved ONLY when
 *                      PayMongo confirms it (signed webhook or server-side status check via
 *                      check_online_remittance). Coming back to this page never approves anything.
 *       Already sent - the rider sent it to the shop's account and enters the real reference no.
 *                      (submit_remittance). Stays pending until staff verify it.
 * The reference is the rider's ACTUAL GCash/bank reference and is never generated here - the
 * system Transaction ID is separate and assigned by the server.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Pay the Shop');
?>
<div class="rd-stack" id="view">
    <div class="rd-skel" style="height:220px;"></div>
    <div class="rd-skel" style="height:200px;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var view = document.getElementById('view');
    var data = null, selected = {}, loaded = false, lastJson = '';

    function sel() { return (data.pending_orders || []).filter(function (o) { return selected[o.order_id]; }); }
    function sum(list) { return Math.round(list.reduce(function (s, o) { return s + R.num(o.total); }, 0) * 100) / 100; }
    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }
    function shortOver(diff) { return diff > 0 ? 'Over ' + R.peso(diff) : 'Short ' + R.peso(-diff); }
    function kv(k, v) { return '<dt>' + esc(k) + '</dt><dd>' + v + '</dd>'; }

    // Plain-English status words (the stored values are unchanged).
    var STATUS_WORDS = { pending: 'Waiting for shop', approved: 'Approved', discrepancy: 'Amount didn’t match', rejected: 'Rejected' };
    var VIA_ICON = { store: 'fa-store', gcash: 'fa-mobile-screen', bank: 'fa-building-columns' };

    function render() {
        var s = data.summary || {};
        var pending = data.pending_orders || [];
        var history = data.history || [];
        var waiting = +s.awaiting_review_count || 0;
        var picked = sel();
        var html = '';

        // Balance card: what the rider still owes, what is waiting on the shop, and the two ways to pay.
        html += '<section class="rd-hero rd-wallet">' +
            '<div class="rd-hero-label">Money to give the shop</div>' +
            '<div class="rd-hero-value">' + R.peso(s.total_pending_amount) + '</div>' +
            '<div class="rd-hero-sub">' + plural(+s.orders_pending || 0, 'cash order') + ' not yet paid</div>' +
            '<div class="rd-wallet-inner">' + (waiting
                ? '<div><small>Sent, waiting for the shop to check</small>' + plural(waiting, 'payment') + '</div><strong>' + R.peso(s.awaiting_review_amount) + '</strong>'
                : '<div><small>Paid to the shop so far</small>' + plural(+s.orders_remitted || 0, 'order') + '</div><strong>' + R.peso(s.total_remitted) + '</strong>') +
            '</div>' +
            '<div class="rd-wallet-actions">' +
            '<button type="button" class="rd-wallet-btn" id="pay-store"' + (pending.length ? '' : ' disabled') + '><i class="fas fa-store"></i> Pay at Store</button>' +
            '<button type="button" class="rd-wallet-btn" id="pay-online"' + (picked.length ? '' : ' disabled') + '><i class="fas fa-paper-plane"></i> GCash / Bank</button>' +
            '</div></section>';

        // Cash (COD) orders not yet paid to the shop
        var all = pending.length && pending.every(function (o) { return selected[o.order_id]; });
        html += '<div class="rd-section-title">Cash orders to pay' +
            (pending.length ? '<a href="#" id="toggle-all">' + (all ? 'Clear' : 'Select all') + '</a>' : '') + '</div>';
        if (!pending.length) {
            html += '<div class="rd-card rd-empty"><i class="fas fa-circle-check"></i><strong>Nothing to pay</strong>You have paid the shop for all your cash orders.</div>';
        } else {
            var pgP = R.paged('remit-pending', pending);   // 10 per page ("Select all" still picks every order)
            html += '<div class="rd-list">' + pgP.items.map(function (o) {
                return '<label class="rd-remit-row"><input type="checkbox" data-order="' + (+o.order_id) + '"' + (selected[o.order_id] ? ' checked' : '') + '>' +
                    '<span><strong>#' + esc(o.order_number) + '</strong><small>' + esc(o.customer_name || 'Customer') + ' · ' + esc(R.fmtDate(o.created_at, false)) + '</small></span>' +
                    '<span class="rd-remit-amt">' + R.peso(o.total) + '</span></label>';
            }).join('') + '</div>' + R.pager(pgP) +
            '<p class="rd-help" style="margin:-6px 2px 0;">' + plural(picked.length, 'order') + ' picked for GCash / Bank · <strong>' + R.peso(sum(picked)) + '</strong></p>';
        }

        // Payment history, as a transaction list (tap one for the details)
        html += '<div class="rd-section-title">Recent payments</div>';
        if (!history.length) {
            html += '<div class="rd-card rd-empty"><i class="fas fa-receipt"></i><strong>No payments yet</strong>Your payments to the shop and their status show here.</div>';
        } else {
            var pgH = R.paged('remit-history', history);   // 10 per page
            html += '<div class="rd-txn-list">' + pgH.items.map(function (h) {
                var diff = R.num(h.difference);
                var viaPm = h.online ? ' through PayMongo' : '';
                var statusLine = h.online && h.status === 'approved' ? 'Verified automatically by PayMongo · ' + R.fmtDate(h.processed_at)
                    : h.online && h.status === 'pending' ? 'Waiting for PayMongo to confirm the payment'
                    : h.status === 'approved' ? 'Approved by the shop · ' + R.fmtDate(h.processed_at)
                    : h.status === 'discrepancy' ? 'Shop got a different amount · ' + R.fmtDate(h.received_at)
                    : h.status === 'rejected' ? 'Rejected by the shop · ' + R.fmtDate(h.processed_at)
                    : 'Sent · waiting for the shop to verify the reference no.';
                return '<details class="rd-history"><summary><div class="rd-txn">' +
                    '<span class="rd-txn-icon"><i class="fas ' + (VIA_ICON[h.remittance_type] || 'fa-store') + '"></i></span>' +
                    '<span class="rd-txn-main"><strong>' + esc(h.type_label || 'Cash at Store') + esc(viaPm) + '</strong><small>' + esc(R.fmtDate(h.requested_at)) + ' · ' + plural(+h.order_count, 'order') + '</small></span>' +
                    '<span class="rd-txn-end"><span class="rd-txn-amt is-' + esc(h.status) + '">' + R.peso(h.amount) + '</span><small>' + esc(STATUS_WORDS[h.status] || h.status) + '</small></span>' +
                    '</div></summary><div class="rd-history-body"><dl class="rd-kv">' +
                    kv('Transaction ID', esc(h.transaction_id || h.remittance_number || '—')) +
                    kv('How paid', esc(h.type_label || 'Cash at Store')) +
                    kv('Date', esc(R.fmtDate(h.requested_at))) +
                    kv('Orders', String(+h.order_count)) +
                    kv('Amount to pay', R.peso(h.expected_amount)) +
                    kv('Amount paid', R.peso(h.amount)) +
                    kv('Short / over', Math.abs(diff) < 0.005 ? 'Exact amount' : '<span style="color:var(--danger);">' + shortOver(diff) + '</span>') +
                    (h.reference_number ? kv('GCash / bank ref. no.', esc(h.reference_number)) : '') +
                    kv('Status', esc(statusLine)) +
                    (h.received_by ? kv('Checked by', esc(h.received_by)) : '') +
                    (h.rider_notes ? kv('Your note', esc(h.rider_notes)) : '') +
                    '</dl>' +
                    (h.status === 'discrepancy' && h.discrepancy_reason ? '<div class="rd-note is-warn" style="margin-top:12px;"><i class="fas fa-triangle-exclamation"></i><span>Why it didn’t match: ' + esc(h.discrepancy_reason) + '</span></div>' : '') +
                    (h.status === 'rejected' && h.notes ? '<div class="rd-note is-danger" style="margin-top:12px;"><i class="fas fa-circle-xmark"></i><span>Reason: ' + esc(h.notes) + '</span></div>' : '') +
                    (h.online && h.online.checkout_url ? '<a class="rd-btn rd-btn-primary rd-btn-sm" style="margin-top:12px;" href="' + esc(h.online.checkout_url) + '"><i class="fas fa-arrow-up-right-from-square"></i> Continue payment</a> ' +
                        '<button type="button" class="rd-btn rd-btn-outline rd-btn-sm" style="margin-top:12px;" data-check="' + (+h.id) + '"><i class="fas fa-rotate"></i> Check status</button>' : '') +
                    (h.has_proof && h.proof_image_url ? '<button type="button" class="rd-btn rd-btn-outline rd-btn-sm" style="margin-top:12px;" data-receipt="' + esc(h.proof_image_url) + '"><i class="fas fa-receipt"></i> View receipt</button>' : '') +
                    '<div class="rd-section-title" style="margin-top:14px;">Orders in this payment</div>' +
                    '<div style="margin-top:6px;">' + (h.orders || []).map(function (o) {
                        return '<div class="rd-sumline"><span>#' + esc(o.order_number) + ' · ' + esc(o.customer_name || 'Customer') + '</span><strong>' + R.peso(o.total) + '</strong></div>';
                    }).join('') + '</div></div></details>';
            }).join('') + '</div>' + R.pager(pgH);
        }

        view.innerHTML = html;
    }
    R.onPager(view, render);

    function load() {
        return R.call('rider_remittance_api.php?action=get_overview').then(function (res) {
            var json = JSON.stringify(res.data || {});
            if (loaded && json === lastJson) return; // unchanged: keep open history cards as they are
            lastJson = json;
            data = res.data || {};
            var ids = {};
            (data.pending_orders || []).forEach(function (o) { ids[o.order_id] = true; });
            // First load: everything selected. Refreshes: keep the rider's still-valid picks.
            if (!loaded) {
                selected = ids;
            } else {
                var kept = {};
                Object.keys(selected).forEach(function (k) { if (ids[k] && selected[k]) kept[k] = true; });
                selected = kept;
            }
            loaded = true;
            render();
        }, function (err) {
            if (!loaded) view.innerHTML = '<div class="rd-card">' + R.errorBox(err) + '</div>';
        });
    }

    view.addEventListener('change', function (e) {
        var cb = e.target.closest('input[data-order]');
        if (!cb) return;
        selected[cb.dataset.order] = cb.checked;
        render();
    });
    view.addEventListener('click', function (e) {
        if (e.target.closest('[data-retry]')) { load(); return; }
        if (e.target.closest('#pay-store')) { openStoreSheet(); return; }
        if (e.target.closest('#pay-online')) { openSheet(); return; }
        var ck = e.target.closest('[data-check]');
        if (ck) { checkOnline(+ck.dataset.check); return; }
        var t = e.target.closest('#toggle-all');
        if (t) {
            e.preventDefault();
            var pending = data.pending_orders || [];
            var all = pending.every(function (o) { return selected[o.order_id]; });
            selected = {};
            if (!all) pending.forEach(function (o) { selected[o.order_id] = true; });
            render();
            return;
        }
        var rc = e.target.closest('[data-receipt]');
        if (rc) {
            R.sheet('<h2 class="rd-sheet-title">Receipt</h2><img class="rd-receipt-img" style="margin-top:10px;" alt="Payment receipt" src="' + esc(rc.dataset.receipt) + '">' +
                '<button type="button" class="rd-btn rd-btn-outline rd-btn-block" style="margin-top:14px;" data-sheet-close>Close</button>');
        }
    });

    // ---- Pay at Store: cash is handed over in person, so this only explains what to do ----
    function openStoreSheet() {
        var pending = data.pending_orders || [];
        R.sheet(
            '<div class="rd-pay-head"><div class="rd-pay-label">Cash to bring to the store</div>' +
            '<div class="rd-pay-amount">' + R.peso(sum(pending)) + '</div>' +
            '<div class="rd-pay-sub">' + plural(pending.length, 'cash order') + '</div></div>' +
            '<div class="rd-stack">' +
            '<ol class="rd-howto">' +
            '<li><strong>Go to the store</strong><span>Bring the cash for your delivered COD orders.</span></li>' +
            '<li><strong>Hand it to the staff</strong><span>They count it and record it in the system.</span></li>' +
            '<li><strong>Get notified</strong><span>You’ll get a notification once it is approved. Nothing to send here.</span></li>' +
            '</ol>' +
            '<div class="rd-sumbox">' + pending.map(function (o) {
                return '<div class="rd-sumline"><span>Order #' + esc(o.order_number) + '</span><span>' + R.peso(o.total) + '</span></div>';
            }).join('') + '</div>' +
            '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg rd-btn-pill" data-sheet-close>Got it</button>' +
            '</div>'
        );
    }

    // ---- GCash / Bank: amount sheet --------------------------------------------------
    var TYPES = {
        gcash: { label: 'GCash', sub: 'Sent to the shop’s GCash', ref: 'GCash reference no.', refHelp: 'Copy it from your GCash receipt.', placeholder: 'e.g. 1234567890123' },
        bank:  { label: 'Bank Transfer', sub: 'Sent to the shop’s bank account', ref: 'Bank reference no.', refHelp: 'Copy it from your bank transfer receipt.', placeholder: 'Reference / transaction no.' }
    };
    function openSheet() {
        var orders = sel();
        if (!orders.length) { R.notify('Pick the orders you are paying for first.', 'warn'); return; }
        var expected = sum(orders);
        var account = function (key) {
            return '<button type="button" class="rd-account" data-type="' + key + '" aria-pressed="' + (key === 'gcash') + '">' +
                '<span class="rd-account-logo"><i class="fas ' + VIA_ICON[key] + '"></i></span>' +
                '<span class="rd-account-main"><strong>' + TYPES[key].label + '</strong><small>' + TYPES[key].sub + '</small></span>' +
                '<i class="fas fa-circle-check rd-account-check"></i></button>';
        };
        var online = !!data.online_payment_available;
        var s = R.sheet(
            '<div class="rd-pay-head"><label class="rd-pay-label" for="amt">Enter Amount</label>' +
            '<div class="rd-pay-amount">₱<input id="amt" inputmode="decimal" autocomplete="off" placeholder="0.00"></div>' +
            '<div class="rd-pay-sub">' + plural(orders.length, 'order') + ' · total to pay ' + R.peso(expected) + ' · <span id="sum-diff"></span></div>' +
            '<button type="button" class="rd-pay-note" id="note-toggle"><i class="fas fa-pen"></i> <span>Add note</span></button></div>' +

            '<div class="rd-stack">' +
            '<div class="rd-field" id="note-field" hidden><label for="notes" id="notes-label">Note (optional)</label><textarea class="rd-textarea" id="notes" maxlength="500"></textarea></div>' +
            '<div class="rd-type-grid">' +
                '<button type="button" class="rd-type" data-mode="online" aria-pressed="' + online + '"' + (online ? '' : ' disabled') + '><i class="fas fa-shield-halved"></i><strong>Pay now</strong><span>' +
                    (online ? 'Secure PayMongo page. Confirmed by PayMongo.' : 'Not available right now.') + '</span></button>' +
                '<button type="button" class="rd-type" data-mode="manual" aria-pressed="' + !online + '"><i class="fas fa-receipt"></i><strong>Already sent</strong><span>Enter the real reference no. The shop verifies it.</span></button>' +
                '</div>' +
            '<div><div class="rd-label" style="margin-bottom:8px;">Send via</div>' + account('gcash') + account('bank') + '</div>' +
            '<div class="rd-sendto" data-manual id="send-to"></div>' +
            '<p class="rd-help" id="online-help" hidden>You\u2019ll pay on PayMongo\u2019s secure page. We never ask for your MPIN, password or OTP. Your remittance is approved only after PayMongo confirms the payment.</p>' +
            '<div class="rd-field" data-manual><label for="ref" id="ref-label">GCash reference no.</label><input class="rd-input" id="ref" autocomplete="off" spellcheck="false" maxlength="64">' +
            '<span class="rd-help" id="ref-help"></span></div>' +
            '<div class="rd-field" data-manual><span class="rd-label">Photo of receipt (optional)</span>' +
            '<div style="display:flex;align-items:center;gap:12px;"><img id="rcpt-thumb" class="rd-thumb" alt="Receipt" hidden>' +
            '<label class="rd-btn rd-btn-outline rd-btn-sm" for="rcpt"><i class="fas fa-image"></i> <span id="rcpt-label">Add photo</span></label>' +
            '<button type="button" class="rd-btn rd-btn-ghost rd-btn-sm" id="rcpt-remove" hidden>Remove</button></div>' +
            '<input type="file" id="rcpt" accept="image/*" hidden></div>' +
            '<p class="rd-help is-error" id="form-error" hidden></p>' +
            '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg rd-btn-pill" id="submit">Proceed to Pay</button>' +
            '</div>',
            { locked: function () { return submitting; } }
        );
        var el = s.el, submitting = false, type = 'gcash', receipt = null, mode = online ? 'online' : 'manual';
        var $ = function (q) { return el.querySelector(q); };
        $('#amt').value = expected.toFixed(2);

        function amount() { return Math.round(R.num($('#amt').value) * 100) / 100; }
        function diff() { return Math.round((amount() - expected) * 100) / 100; }
        function refHelp(text, bad) { var h = $('#ref-help'); h.className = 'rd-help' + (bad ? ' is-error' : ''); h.textContent = text; }
        function showNote(on) {
            $('#note-field').hidden = !on;
            $('#note-toggle').classList.toggle('is-on', on);
            $('#note-toggle span').textContent = on ? 'Note added' : 'Add note';
        }
        // Where to send a manual transfer: the shop's real account from Admin > Settings. Never a placeholder.
        function renderSendTo() {
            var a = (data.shop_accounts || {})[type] || {};
            var box = $('#send-to');
            if (!a.ready) {
                box.innerHTML = '<div class="rd-note is-warn"><i class="fas fa-triangle-exclamation"></i><span>The shop has not added its ' + esc(TYPES[type].label) +
                    ' details yet. Ask the staff for the account, or pay cash at the store.</span></div>';
                return;
            }
            box.innerHTML = '<div class="rd-label" style="margin-bottom:8px;">Send ' + R.peso(expected) + ' to</div>' +
                '<div class="rd-sendto-card">' +
                (a.qr_url ? '<button type="button" class="rd-sendto-qr" data-zoom="' + esc(a.qr_url) + '" aria-label="Enlarge QR code"><img src="' + esc(a.qr_url) + '" alt="' + esc(TYPES[type].label) + ' QR code"><span><i class="fas fa-magnifying-glass-plus"></i> Tap to enlarge</span></button>' : '') +
                '<dl class="rd-kv">' +
                (type === 'bank' ? kv('Bank', esc(a.bank_name)) : '') +
                (a.account_name ? kv('Account name', esc(a.account_name)) : '') +
                (a.account_number ? kv(type === 'gcash' ? 'GCash number' : 'Account number', '<span class="rd-copy-val">' + esc(a.account_number) + '</span> <button type="button" class="rd-copy" data-copy="' + esc(a.account_number) + '"><i class="fas fa-copy"></i> Copy</button>') : '') +
                (!a.account_name && !a.account_number ? kv('How', 'Scan the QR code') : '') +
                '</dl></div>' +
                '<p class="rd-help" style="margin:8px 0 0;">' + (a.qr_url ? 'Scan the QR with your ' + (type === 'gcash' ? 'GCash' : 'banking') + ' app, or send' : 'Send') +
                ' exactly ' + R.peso(expected) + ', then enter the reference no. from your receipt below.</p>';
        }
        function refresh() {
            var t = TYPES[type];
            renderSendTo();
            el.querySelectorAll('[data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false'); });
            el.querySelectorAll('[data-manual]').forEach(function (f) { f.hidden = mode === 'online'; });
            $('#online-help').hidden = mode !== 'online';
            $('#amt').readOnly = mode === 'online';   // PayMongo charges exactly what is due
            if (mode === 'online') $('#amt').value = expected.toFixed(2);
            $('#submit').textContent = mode === 'online' ? 'Continue to Secure Payment' : 'Submit for Verification';
            el.querySelectorAll('[data-type]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.type === type ? 'true' : 'false'); });
            $('#ref-label').textContent = t.ref;
            $('#ref').placeholder = t.placeholder;
            $('#amt').style.width = Math.max(4, $('#amt').value.length + 1) + 'ch';
            var d = diff(), match = Math.abs(d) < 0.005;
            $('#sum-diff').textContent = match ? 'exact amount' : shortOver(d).toLowerCase();
            $('#notes-label').textContent = match ? 'Note (optional)' : 'Note (required — say why the amount is different)';
            if (!match) showNote(true);
        }
        function err(msg) { var b = $('#form-error'); b.textContent = msg || ''; b.hidden = !msg; }

        el.addEventListener('click', function (e) {
            var mb = e.target.closest('[data-mode]');
            if (mb && !submitting && mb.dataset.mode !== mode) { mode = mb.dataset.mode; err(); refresh(); return; }
            var tb = e.target.closest('[data-type]');
            if (tb && !submitting && tb.dataset.type !== type) {
                type = tb.dataset.type;
                refHelp(TYPES[type].refHelp);
                err(); refresh(); return;
            }
            var cp = e.target.closest('[data-copy]');
            if (cp) {
                var v = cp.dataset.copy;
                (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function () { R.notify('Copied ' + v, 'ok'); }, function () { R.notify(v, 'info', { title: 'Copy this number' }); });
                return;
            }
            var zq = e.target.closest('[data-zoom]');
            if (zq) {
                R.sheet('<h2 class="rd-sheet-title">' + esc(TYPES[type].label) + ' QR code</h2><img class="rd-receipt-img rd-qr-big" style="margin-top:10px;" alt="QR code" src="' + esc(zq.dataset.zoom) + '">' +
                    '<button type="button" class="rd-btn rd-btn-outline rd-btn-block" style="margin-top:14px;" data-sheet-close>Close</button>');
                return;
            }
            if (e.target.closest('#note-toggle')) { showNote($('#note-field').hidden); if (!$('#note-field').hidden) $('#notes').focus(); return; }
            if (e.target.closest('#rcpt-remove')) { receipt = null; $('#rcpt-thumb').hidden = true; $('#rcpt-remove').hidden = true; $('#rcpt-label').textContent = 'Add photo'; }
        });
        el.addEventListener('input', function (e) {
            if (e.target.matches('#amt')) {
                e.target.value = e.target.value.replace(/[^\d.]/g, '').replace(/^(\d*\.?\d{0,2}).*$/, '$1');
            }
            err(); refresh();
        });
        // Live duplicate check for the reference (rider_remittance_api.php?action=check_reference).
        $('#ref').addEventListener('blur', function () {
            var v = this.value.trim();
            if (!v) { refHelp(TYPES[type].refHelp); return; }
            R.call('rider_remittance_api.php', { body: { action: 'check_reference', reference_number: v } }).then(function (res) {
                var ok = res.data && res.data.available;
                refHelp(ok ? 'Reference no. looks good.' : 'This reference no. was already used. Check your receipt.', !ok);
            }, function () { /* checked again on submit */ });
        });
        $('#rcpt').addEventListener('change', function () {
            var f = this.files[0];
            if (!f) return;
            R.imageToDataUri(f).then(function (uri) {
                receipt = uri;
                $('#rcpt-thumb').src = uri; $('#rcpt-thumb').hidden = false;
                $('#rcpt-remove').hidden = false; $('#rcpt-label').textContent = 'Change';
            }, R.fail);
        });

        $('#submit').addEventListener('click', function () {
            var btn = this;
            if (submitting) return;
            var ref = $('#ref').value.trim();
            if (mode === 'online') {
                submitting = true;
                R.guarded(btn, 'Opening PayMongo…', function () {
                    return R.call('rider_remittance_api.php', { body: { action: 'start_online_remittance', remittance_type: type, order_ids: orders.map(function (o) { return +o.order_id; }) }, timeout: 60000 });
                }).then(function (res) {
                    var url = res.data && res.data.checkout_url;
                    // Only ever leave for a real HTTPS checkout page returned by the server.
                    if (typeof url !== 'string' || !/^https:\/\/[^\s]+$/.test(url)) {
                        submitting = false;
                        err('Unable to create the secure payment session.');
                        return;
                    }
                    window.location.href = url;
                }, function (e) { submitting = false; err(e.message); });
                return;
            }
            if (amount() <= 0) { err('Enter how much you sent.'); $('#amt').focus(); return; }
            if (!ref) { err('Enter the ' + TYPES[type].ref + ' from your receipt.'); $('#ref').focus(); return; }
            if (Math.abs(diff()) >= 0.005 && !$('#notes').value.trim()) { err('The amount is different from the total. Add a note to say why.'); showNote(true); $('#notes').focus(); return; }
            err();
            submitting = true;
            var ids = orders.map(function (o) { return +o.order_id; });
            var body = {
                action: 'submit_remittance', order_ids: ids, remittance_type: type,
                reference_number: ref, rider_notes: $('#notes').value.trim()
            };
            body[type + '_amount'] = amount();
            if (receipt) body.proof_image = receipt;

            R.guarded(btn, 'Submitting…', function () {
                return R.call('rider_remittance_api.php', { body: body, timeout: 60000 }).catch(function (e) {
                    if (!e.network) throw e;
                    // Connection dropped: if these orders are no longer waiting, the remittance was saved.
                    return R.call('rider_remittance_api.php?action=get_overview').then(function (o) {
                        var still = (o.data.pending_orders || []).some(function (p) { return ids.indexOf(+p.order_id) !== -1; });
                        if (still) throw e;
                        return { message: '', data: {} };
                    }, function () { throw e; });
                });
            }).then(function (res) {
                submitting = false;
                s.close();
                var txn = res.data && res.data.transaction_id;
                R.notify((txn ? 'Transaction ID: ' + txn + '. ' : '') + 'Pending verification: the shop will check that your ' + TYPES[type].label + ' transfer arrived.', 'ok', { title: 'Submitted for verification', duration: 9000 });
                selected = {};
                loaded = false;
                load();
            }, function (e) { submitting = false; err(e.message); });
        });
        refHelp(TYPES[type].refHelp);
        refresh();
    }

    // Back from PayMongo (or "Check status"): the server asks PayMongo and reports what it says.
    // The page never decides the result itself.
    function checkOnline(id, cancelled) {
        var wait = R.sheet('<div class="rd-pay-head"><div class="rd-pay-label">Checking your payment…</div>' +
            '<div class="rd-pay-amount"><i class="fas fa-spinner fa-spin"></i></div><div class="rd-pay-sub">Asking PayMongo for the result</div></div>');
        return R.call('rider_remittance_api.php', { body: { action: 'check_online_remittance', remittance_id: id, cancelled: !!cancelled }, timeout: 45000 }).then(function (res) {
            wait.close();
            showResult(res.data || {}, res.message);
            loaded = false;
            load();
        }, function (e) { wait.close(); R.fail(e); });
    }
    function showResult(d, message) {
        var st = d.status;
        var view = st === 'approved' ? ['fa-circle-check', 'Payment successful']
            : st === 'rejected' ? ['fa-circle-xmark', 'Payment failed or was cancelled']
            : st === 'discrepancy' ? ['fa-triangle-exclamation', 'Amount did not match']
            : ['fa-hourglass-half', 'Payment is being verified'];
        var rows = kv('Transaction ID', esc(d.transaction_id || '—')) +
            kv('Amount', R.peso(d.amount_paid != null ? d.amount_paid : d.amount_due)) +
            kv('Payment method', esc(d.method || '') + ' via PayMongo') +
            kv('Date', esc(R.fmtDate(d.date))) +
            kv('Orders covered', (d.orders || []).map(function (o) { return '#' + esc(o.order_number); }).join(', ') || '—') +
            (d.provider_reference ? kv('GCash / bank ref. no.', esc(d.provider_reference)) : '') +
            kv('Status', esc(d.status_label || st || ''));
        var s = R.sheet(
            '<div class="rd-pay-head"><div class="rd-pay-amount"><i class="fas ' + view[0] + '"></i></div><div class="rd-pay-label">' + esc(view[1]) + '</div>' +
            '<div class="rd-pay-sub">' + esc(st === 'pending' && d.verification_available === false ? message : (st === 'pending' ? 'We are waiting for PayMongo to confirm it. Please do not pay again.' : message)) + '</div></div>' +
            '<div class="rd-stack"><dl class="rd-kv">' + rows + '</dl>' +
            (d.note ? '<div class="rd-note is-warn"><i class="fas fa-circle-info"></i><span>' + esc(d.note) + '</span></div>' : '') +
            (st === 'pending' ? '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-pill" data-recheck>Check again</button>' : '') +
            (st === 'rejected' ? '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-pill" data-retry-pay>Try again</button>' : '') +
            '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Close</button></div>'
        );
        s.el.addEventListener('click', function (e) {
            if (e.target.closest('[data-recheck]')) { s.close(); checkOnline(d.remittance_id, false); }
            if (e.target.closest('[data-retry-pay]')) {
                // The orders were released when the payment failed: pick the same ones again.
                s.close();
                var numbers = (d.orders || []).map(function (o) { return o.order_number; });
                load().then(function () {
                    selected = {};
                    (data.pending_orders || []).forEach(function (o) { if (numbers.indexOf(o.order_number) !== -1) selected[o.order_id] = true; });
                    render();
                    if (sel().length) openSheet();
                });
            }
        });
    }
    var qs = new URLSearchParams(location.search);
    var back = +(qs.get('check') || 0);
    if (back) {
        history.replaceState(null, '', location.pathname);
        checkOnline(back, qs.get('cancelled') === '1');
    }

    load();
    R.pullToRefresh(load);
    R.live(load, 45000, 'remittance');
});
</script>
<?php rider_page_end('remittance'); ?>
