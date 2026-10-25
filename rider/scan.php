<?php
/**
 * Delivery QR scanner (Flutter "Scan Parcel QR"), in the phone browser.
 *
 * Reads the EXISTING Delivery Confirmation QR — JSON {delivery_id, order_id?, token} built from
 * tbl_delivery.qr_confirmation_token (customer/orders.php, emailed receipts). Nothing is
 * decided in the browser:
 *   1. rider_qr_confirm_api.php (action "preview") validates the token, the order, its status
 *      and that this rider may act on it, and returns the order.
 *   2. The rider sees the order and must confirm.
 *   3. rider_update_status_api.php re-validates everything and moves the order one step:
 *      at the store To Ship -> Out for Delivery (pickup), at the customer -> Completed. Completing clears the token,
 *      so the same QR can never complete an order twice.
 * Camera: getUserMedia + the browser's BarcodeDetector when available, otherwise jsQR
 * (assets/vendor/jsqr, loaded only when needed). "Scan from photo" works without live camera
 * access (e.g. before HTTPS is set up).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

$expected_order = (int) ($_GET['order'] ?? 0);
rider_page_start('Scan QR', $expected_order > 0 ? ['back' => BASE_URL . '/rider/order.php?id=' . $expected_order] : []);
?>
<div class="rd-stack" id="scanner" data-expected="<?php echo $expected_order; ?>" data-jsqr="<?php echo rider_e(rider_asset('assets/vendor/jsqr/jsQR.js')); ?>">
    <div class="rd-scan" id="viewport">
        <video id="video" playsinline muted hidden></video>
        <div class="rd-scan-frame" id="frame" hidden><div class="rd-scan-line"></div></div>
        <div class="rd-scan-hint" id="hint" hidden>Point the camera at the Delivery QR</div>
        <div class="rd-scan-idle" id="idle">
            <i class="fas fa-qrcode" aria-hidden="true"></i>
            <p id="idle-text">Scan the customer's Delivery QR (or the QR on the parcel) to update the order.</p>
            <button type="button" class="rd-btn rd-btn-primary rd-btn-lg" id="start"><i class="fas fa-camera"></i> Open camera</button>
        </div>
    </div>
    <div class="rd-btn-row">
        <label class="rd-btn rd-btn-outline" for="photo"><i class="fas fa-image"></i> Scan from photo</label>
        <button type="button" class="rd-btn rd-btn-outline" id="stop" disabled><i class="fas fa-stop"></i> Stop camera</button>
    </div>
    <button type="button" class="rd-btn rd-btn-outline rd-btn-block" id="torch" hidden aria-pressed="false"><i class="fas fa-lightbulb"></i> <span>Turn on flashlight</span></button>
    <input type="file" id="photo" accept="image/*" capture="environment" hidden>
    <?php if ($expected_order > 0): ?>
        <div class="rd-note"><i class="fas fa-circle-info"></i><span>Scanning for a specific order. A QR from a different order will be rejected.</span></div>
    <?php endif; ?>
    <div class="rd-note is-warn" id="secure-note" hidden><i class="fas fa-lock"></i><span>Live camera needs a secure (https://) connection. Use <strong>Scan from photo</strong> instead, or open the portal over HTTPS.</span></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var root = document.getElementById('scanner');
    var expected = parseInt(root.dataset.expected, 10) || 0;
    var video = document.getElementById('video');
    var startBtn = document.getElementById('start'), stopBtn = document.getElementById('stop');
    var stream = null, detector = null, loopTimer = null, busy = false, wantCamera = false;
    var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
    var hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

    if (!hasCamera) {
        document.getElementById('secure-note').hidden = !(window.isSecureContext === false);
        document.getElementById('idle-text').textContent = window.isSecureContext === false
            ? 'Live camera is not available on this connection. Use "Scan from photo" below.'
            : 'This browser cannot open the camera here. Use "Scan from photo" below.';
        startBtn.hidden = true;
    }

    // ---- Decoders ---------------------------------------------------------------
    var jsqrReady = null;
    function loadJsQR() {
        if (window.jsQR) return Promise.resolve();
        if (!jsqrReady) {
            jsqrReady = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = root.dataset.jsqr;
                s.onload = resolve;
                s.onerror = function () { jsqrReady = null; reject(new R.RiderError('Could not load the QR reader. Check your connection.')); };
                document.head.appendChild(s);
            });
        }
        return jsqrReady;
    }
    function initDetector() {
        if (detector !== null) return Promise.resolve();
        if ('BarcodeDetector' in window && BarcodeDetector.getSupportedFormats) {
            return BarcodeDetector.getSupportedFormats().then(function (f) {
                detector = f.indexOf('qr_code') !== -1 ? new BarcodeDetector({ formats: ['qr_code'] }) : false;
                if (!detector) return loadJsQR();
            }, function () { detector = false; return loadJsQR(); });
        }
        detector = false;
        return loadJsQR();
    }
    /**
     * Live frames: the browser's detector first; every other frame (or always, without a
     * detector) jsQR on a zoomed crop of the frame box - more pixels per QR square, which
     * copes much better with a camera that keeps re-adjusting exposure on a bright screen.
     */
    var frameNo = 0;
    function decodeLive() {
        var w = video.videoWidth, h = video.videoHeight;
        frameNo++;
        var first = detector ? decode(video, w, h) : Promise.resolve(null);
        return first.then(function (text) {
            if (text || !window.jsQR || (detector && frameNo % 2)) return text;
            var side = Math.round(Math.min(w, h) * 0.75), out = Math.min(side, 640);
            canvas.width = out; canvas.height = out;
            ctx.drawImage(video, (w - side) / 2, (h - side) / 2, side, side, 0, 0, out, out);
            var img = ctx.getImageData(0, 0, out, out);
            var code = window.jsQR(img.data, out, out, { inversionAttempts: 'attemptBoth' });
            return code ? code.data : null;
        });
    }
    /** Continuous focus/exposure where the phone supports it, and the flashlight button. */
    function tuneCamera(track) {
        var caps = {};
        try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch (e) { /* not supported */ }
        var adv = [];
        if ((caps.focusMode || []).indexOf('continuous') !== -1) adv.push({ focusMode: 'continuous' });
        if ((caps.exposureMode || []).indexOf('continuous') !== -1) adv.push({ exposureMode: 'continuous' });
        if ((caps.whiteBalanceMode || []).indexOf('continuous') !== -1) adv.push({ whiteBalanceMode: 'continuous' });
        if (adv.length) track.applyConstraints({ advanced: adv }).catch(function () {});
        var torch = document.getElementById('torch');
        torch.hidden = !caps.torch;
        torch.setAttribute('aria-pressed', 'false');
        torch.querySelector('span').textContent = 'Turn on flashlight';
    }
    document.getElementById('torch').addEventListener('click', function () {
        if (!stream) return;
        var btn = this, on = btn.getAttribute('aria-pressed') !== 'true';
        stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: on }] }).then(function () {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.querySelector('span').textContent = on ? 'Turn off flashlight' : 'Turn on flashlight';
        }, function () { R.notify('This phone does not allow the flashlight here.', 'warn'); });
    });

    /** Decodes a QR from a video/image source. Resolves the text or null. */
    function decode(source, w, h, maxSide) {
        if (detector) {
            return detector.detect(source).then(function (codes) { return codes.length ? codes[0].rawValue : null; }, function () { return null; });
        }
        var scale = Math.min(1, (maxSide || 720) / Math.max(w, h));
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' }) : null;
        return Promise.resolve(code ? code.data : null);
    }

    // ---- Camera -----------------------------------------------------------------
    function showLive(on) {
        video.hidden = !on;
        document.getElementById('frame').hidden = !on;
        document.getElementById('hint').hidden = !on;
        document.getElementById('idle').hidden = on;
        stopBtn.disabled = !on;
    }
    function startCamera() {
        if (!hasCamera || stream) return Promise.resolve();
        wantCamera = true;
        R.busy(startBtn, true, 'Opening camera…');
        return initDetector().then(function () {
            loadJsQR().catch(function () {}); // backup decoder for hard frames, even when BarcodeDetector exists
            return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
        }).then(function (s) {
            stream = s;
            video.srcObject = s;
            tuneCamera(s.getVideoTracks()[0]);
            return video.play();
        }).then(function () {
            showLive(true);
            scanStarted = Date.now();
            setHint(false);
            loop();
        }).catch(function (err) {
            stopCamera();
            var msg = err && err.name === 'NotAllowedError' ? 'Camera permission was denied. Allow camera access for this site in your browser settings, or use "Scan from photo".'
                : err && err.name === 'NotFoundError' ? 'No camera was found on this device. Use "Scan from photo".'
                : (err && err.message) || 'Could not open the camera. Use "Scan from photo".';
            R.notify(msg, 'error', { title: 'Camera' });
        }).then(function () { R.busy(startBtn, false); });
    }
    var scanStarted = 0;
    function setHint(tip) {
        document.getElementById('hint').textContent = tip
            ? 'Scanning a screen? Lower its brightness, tilt to avoid glare, hold 15–25 cm away.'
            : 'Point the camera at the Delivery QR';
    }
    function stopCamera() {
        clearTimeout(loopTimer);
        document.getElementById('torch').hidden = true;
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null;
        video.srcObject = null;
        showLive(false);
    }
    function loop() {
        clearTimeout(loopTimer);
        if (!stream || busy) return;
        if (video.readyState < 2) { loopTimer = setTimeout(loop, 150); return; }
        decodeLive().then(function (text) {
            if (text && !busy) { handle(text); return; }
            if (scanStarted && Date.now() - scanStarted > 6000) setHint(true);
            loopTimer = setTimeout(loop, detector ? 100 : 150);
        });
    }
    startBtn.addEventListener('click', startCamera);
    stopBtn.addEventListener('click', function () { wantCamera = false; stopCamera(); });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { if (stream) stopCamera(); }
        else if (wantCamera && !busy) startCamera();
    });
    window.addEventListener('pagehide', stopCamera);
    // Re-open automatically when the camera was already allowed for this site.
    if (hasCamera && navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function (p) { if (p.state === 'granted') startCamera(); }, function () {});
    }

    // ---- Scan from photo --------------------------------------------------------
    document.getElementById('photo').addEventListener('change', function () {
        var f = this.files[0];
        this.value = '';
        if (!f || busy) return;
        var url = URL.createObjectURL(f);
        var img = new Image();
        img.onload = function () {
            initDetector().then(function () {
                return decode(img, img.naturalWidth, img.naturalHeight, 1200).then(function (t) {
                    return t || decode(img, img.naturalWidth, img.naturalHeight, 2000);
                });
            }).then(function (text) {
                URL.revokeObjectURL(url);
                if (text) handle(text);
                else R.notify('No QR code found in that photo. Take it closer, straight on and in good light.', 'warn', { title: 'No QR found' });
            }, function (err) { URL.revokeObjectURL(url); R.fail(err); });
        };
        img.onerror = function () { URL.revokeObjectURL(url); R.notify('Could not read that photo.', 'error'); };
        img.src = url;
    });

    // ---- Validate -> confirm -> update -------------------------------------------
    function resume() {
        busy = false;
        scanStarted = Date.now();
        setHint(false);
        if (wantCamera && !stream) startCamera(); else loop();
    }
    function problem(title, message) {
        var s = R.sheet(
            '<div class="rd-found"><div class="rd-found-icon is-error"><i class="fas fa-triangle-exclamation"></i></div>' +
            '<h2 class="rd-sheet-title">' + esc(title) + '</h2><p class="rd-sheet-sub">' + esc(message) + '</p></div>' +
            '<button type="button" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" data-sheet-close><i class="fas fa-qrcode"></i> Scan again</button>',
            { onClose: resume }
        );
        return s;
    }

    function handle(text) {
        busy = true;
        clearTimeout(loopTimer);
        if (navigator.vibrate) { try { navigator.vibrate(60); } catch (e) {} }
        var qr = null;
        try { qr = JSON.parse(text); } catch (e) { /* not JSON */ }
        var deliveryId = qr && parseInt(qr.delivery_id, 10);
        if (!qr || !deliveryId || !qr.token) {
            problem('Not a delivery QR', 'This QR code is not a DPS Delivery Confirmation QR.');
            return;
        }
        if (expected && qr.order_id && parseInt(qr.order_id, 10) !== expected) {
            problem('Different order', 'This QR belongs to a different order. Scan the QR for the order you opened.');
            return;
        }
        var loading = R.sheet('<div class="rd-found"><div class="rd-found-icon" style="background:var(--primary-soft);color:var(--primary);"><span class="rd-spin" style="width:28px;height:28px;"></span></div>' +
            '<h2 class="rd-sheet-title">Checking QR…</h2><p class="rd-sheet-sub">Verifying the order with the shop.</p></div>', { locked: function () { return true; } });

        R.call('rider_qr_confirm_api.php', { body: { action: 'preview', delivery_id: deliveryId, qr_code: text } }).then(function (res) {
            var p = res.data || {};
            if (expected && +p.order_id !== expected) {
                throw new R.RiderError('This QR belongs to order #' + (p.order_number || p.order_id) + ', not the order you opened.');
            }
            // Amount and payment for the confirmation (the details API applies the same access rule).
            return R.call('rider_orders_api.php?action=get_order_details&id=' + (+p.order_id)).then(function (d) {
                return { p: p, o: d.data || {} };
            }, function () { return { p: p, o: null }; });
        }).then(function (r) {
            loading.close();
            confirmStep(text, deliveryId, r.p, r.o);
        }, function (err) {
            loading.close();
            problem(err.network ? 'No connection' : 'Cannot use this QR', err.message);
        });
    }

    function confirmStep(text, deliveryId, p, o) {
        var st = String(p.order_status || '').toLowerCase();
        var next = R.NEXT[st];
        if (!next) { problem('Nothing to update', 'This order is not at a rider step (' + R.statusMeta(st)[0] + ').'); return; }
        if (!next.pickup) { confirmSheet(text, deliveryId, p, o, next, null); return; }
        // Pickup: show which e-bike gets recorded (the rider's pick in Profile > My E-Bike).
        var loading = R.sheet('<div class="rd-found"><div class="rd-found-icon" style="background:var(--primary-soft);color:var(--primary);"><span class="rd-spin" style="width:28px;height:28px;"></span></div>' +
            '<h2 class="rd-sheet-title">Loading e-bikes…</h2></div>', { locked: function () { return true; } });
        R.call('rider_ebikes_api.php').then(function (res) {
            loading.close();
            var d = res.data || {};
            confirmSheet(text, deliveryId, p, o, next, d.fleet_total > 0 ? d : null);
        }, function (err) {
            loading.close();
            problem(err.network ? 'No connection' : 'Cannot load e-bikes', err.message);
        });
    }

    // Read-only: the e-bike is chosen in Profile, never here. Missing one never blocks the pickup.
    function ebikeField(info) {
        if (!info) return '';
        var b = info.profile_bike;
        if (b && b.usable) {
            var extra = [b.model, b.plate_number].filter(Boolean).join(' · ');
            return '<div class="rd-note" style="margin-top:14px;"><i class="fas fa-bicycle"></i><span>E-bike: <strong>' + esc(b.bike_code) + '</strong>' +
                (extra ? ' (' + esc(extra) + ')' : '') + ' · <a href="profile.php">Change in Profile</a></span></div>';
        }
        return '<div class="rd-note is-warn" style="margin-top:14px;"><i class="fas fa-bicycle"></i><span>' +
            (b ? esc(b.bike_code) + ' is no longer available, so no e-bike will be recorded. ' : 'No e-bike selected, so none will be recorded. ') +
            '<a href="profile.php">Pick yours in Profile</a>.</span></div>';
    }

    // Pickup photo before leaving the shop / delivery photo at the customer. Asked for, never required:
    // continuing without it is allowed and the shop sees a "No ... photo" flag on the order.
    function photoStage(next) {
        if (next.pickup) return 'pickup';
        return next.status === 'completed' ? 'delivery' : null;
    }
    function photoField(stage, o) {
        if (!stage || !o) return '';
        var has = stage === 'pickup' ? o.pickup_photo : o.delivery_photo;
        var name = stage === 'pickup' ? 'pickup photo' : 'delivery photo';
        if (has) {
            return '<div class="rd-note" id="photo-note" style="margin-top:14px;"><i class="fas fa-circle-check" style="color:var(--success);"></i><span>' +
                (stage === 'pickup' ? 'Pickup' : 'Delivery') + ' photo uploaded.</span></div>';
        }
        return '<div class="rd-note is-warn" id="photo-note" style="margin-top:14px;"><i class="fas fa-camera"></i><span>No ' + name + ' yet. ' +
            'Please take one first. You can still continue without it, but the shop will see <strong>"No ' + name + '"</strong> on this order.' +
            '<br><button type="button" class="rd-btn rd-btn-outline rd-btn-sm" id="photo-take" style="margin-top:8px;"><i class="fas fa-camera"></i> Take photo now</button></span></div>';
    }

    function confirmSheet(text, deliveryId, p, o, next, ebikeInfo) {
        var st = String(p.order_status || '').toLowerCase();
        var stage = photoStage(next);
        var pm = String((o && o.payment_method) || '').toLowerCase();
        var total = o ? R.peso(o.total) : '';
        var codLine = '';
        if (o && pm === 'cod') {
            codLine = '<div class="rd-cod" style="margin-top:14px;"><div><div class="rd-cod-label">' + (next.status === 'completed' ? 'Collect before confirming' : 'COD amount') + '</div>' +
                '<div class="rd-cod-value">' + total + '</div></div><i class="fas fa-money-bill-wave" style="font-size:1.6rem;"></i></div>';
        } else if (o) {
            codLine = '<div class="rd-note" style="margin-top:14px;"><i class="fas fa-circle-info"></i><span>' + esc(R.paymentLabel(pm)) + ' · ' + esc(R.paymentState(o)[0]) + '. No cash to collect.</span></div>';
        }
        var s = R.sheet(
            '<div class="rd-found"><div class="rd-found-icon"><i class="fas fa-box-open"></i></div>' +
            '<h2 class="rd-sheet-title">Order found</h2></div>' +
            '<dl class="rd-kv" style="margin-top:6px;">' +
            '<dt>Order</dt><dd>#' + esc(p.order_number) + '</dd>' +
            '<dt>Customer</dt><dd>' + esc(p.customer_name || '—') + '</dd>' +
            (o ? '<dt>Amount</dt><dd>' + total + ' · ' + esc(R.paymentShort(pm)) + '</dd>' : '') +
            '<dt>Current status</dt><dd>' + R.statusBadge(st) + '</dd>' +
            '<dt>New status</dt><dd>' + R.statusBadge(next.status) + '</dd>' +
            '</dl>' + codLine +
            ebikeField(ebikeInfo) +
            photoField(stage, o) +
            '<p class="rd-help" style="margin:14px 0 0;">' + esc(next.text) + '</p>' +
            '<p class="rd-help is-error" id="confirm-error" hidden></p>' +
            '<div class="rd-btn-row" style="margin-top:16px;">' +
            '<button type="button" class="rd-btn rd-btn-outline rd-btn-lg" id="confirm-cancel">Cancel</button>' +
            '<button type="button" class="rd-btn ' + (next.status === 'completed' ? 'rd-btn-success' : 'rd-btn-primary') + ' rd-btn-lg" id="confirm-go"><i class="fas ' + next.icon + '"></i> ' + esc(next.action) + '</button>' +
            '</div>',
            { locked: function () { return sending; }, onClose: function () { if (!done) resume(); } }
        );
        var sending = false, done = false;
        var go = s.el.querySelector('#confirm-go');
        s.el.querySelector('#confirm-cancel').addEventListener('click', function () { if (!sending) s.close(); });
        var take = s.el.querySelector('#photo-take');
        if (take) {
            take.addEventListener('click', function () {
                if (sending) return;
                R.photoSheet({ id: +p.order_id, order_number: p.order_number, delivery_id: deliveryId }, stage).then(function (res) {
                    document.body.classList.add('rd-sheet-open'); // this confirm sheet is still open underneath
                    if (!res) return;
                    if (o) { if (stage === 'pickup') o.pickup_photo = res.data && res.data.proof_url; else o.delivery_photo = res.data && res.data.proof_url; }
                    var note = s.el.querySelector('#photo-note');
                    if (note) note.outerHTML = photoField(stage, o || { pickup_photo: 1, delivery_photo: 1 });
                });
            });
        }
        go.addEventListener('click', function () {
            if (sending) return;
            var errBox = s.el.querySelector('#confirm-error');
            sending = true;
            errBox.hidden = true;
            // No ebike_id: the server records the rider's Profile e-bike at pickup.
            var body = { order_id: +p.order_id, delivery_id: deliveryId, status: next.status, qr_code: text };
            R.guarded(go, 'Updating…', function () {
                return R.call('rider_update_status_api.php', {
                    body: body
                }).catch(function (err) {
                    if (!err.network) throw err;
                    // Connection dropped mid-request: check whether the update went through
                    // before telling the rider to retry (the server never applies it twice).
                    return R.call('rider_orders_api.php?action=get_order_details&id=' + (+p.order_id)).then(function (d) {
                        if (d.data && d.data.order_status === next.status) return { message: '', data: {} };
                        throw err;
                    }, function () { throw err; });
                });
            }).then(function (res) {
                sending = false;
                done = true;
                s.close();
                success(p, o, next, res.message);
            }, function (err) {
                sending = false;
                var box = s.el.querySelector('#confirm-error');
                box.textContent = err.message;
                box.hidden = false;
            });
        });
    }

    function success(p, o, next, serverMsg) {
        var pm = String((o && o.payment_method) || '').toLowerCase();
        var extra = '';
        if (next.status === 'completed' && pm === 'cod') {
            extra = '<div class="rd-note" style="margin-top:12px;text-align:left;"><i class="fas fa-wallet"></i><span>You collected <strong>' + R.peso(o.total) + '</strong>. Pay it to the shop in the <a href="remittance.php">Remittance</a> tab.</span></div>';
        }
        if (serverMsg && /could not|no valid email/i.test(serverMsg)) {
            extra += '<div class="rd-note is-warn" style="margin-top:10px;text-align:left;"><i class="fas fa-envelope"></i><span>' + esc(serverMsg) + '</span></div>';
        }
        R.notify('Order #' + p.order_number + ' is now ' + R.statusMeta(next.status)[0] + '.', 'ok', { title: next.status === 'completed' ? 'Delivery confirmed' : 'Status updated' });
        R.sheet(
            '<div class="rd-found"><div class="rd-found-icon"><i class="fas fa-circle-check"></i></div>' +
            '<h2 class="rd-sheet-title">' + (next.status === 'completed' ? 'Delivery confirmed' : 'Status updated') + '</h2>' +
            '<p class="rd-sheet-sub">Order #' + esc(p.order_number) + ' is now <strong>' + esc(R.statusMeta(next.status)[0]) + '</strong>.</p>' + extra + '</div>' +
            '<div class="rd-stack" style="margin-top:16px;">' +
            '<a class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" href="order.php?id=' + (+p.order_id) + '"><i class="fas fa-receipt"></i> View order</a>' +
            '<button type="button" class="rd-btn rd-btn-outline rd-btn-block" data-sheet-close><i class="fas fa-qrcode"></i> Scan another</button></div>',
            { onClose: resume }
        );
    }
});
</script>
<?php rider_page_end('scan'); ?>
