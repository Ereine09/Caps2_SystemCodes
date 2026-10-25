<?php
/**
 * Admin QR scanner (web). The Flutter Admin App it once mirrored has been removed.
 *
 * Same flow as Flutter: scan the EXISTING Delivery QR -> the server checks it (scan_lookup_api.php)
 * -> the admin sees the order -> "Open order" goes to order_details.php, where any status change
 * still has its own confirm step. Scanning never changes an order by itself.
 * Reached from the "Scan QR" buttons on the Dashboard and Orders pages (deliberately not in the sidebar).
 * Camera: getUserMedia + BarcodeDetector, with jsQR (assets/vendor/jsqr, loaded only when needed)
 * as the fallback - the same approach as the rider scanner (rider/scan.php).
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}
if (strtolower(trim($payload['role'] ?? 'staff')) !== 'admin' && !defined('STAFF_PORTAL_MODE')) { // modules/staff/scan.php shows this page in staff mode
    header('Location: ' . BASE_URL . '/modules/staff/dashboard.php');
    exit();
}
$user_id = (int) $payload['user_id'];
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
$current_page = 'orders'; // highlight Orders in the sidebar; the scanner itself has no sidebar item

$v = function (string $path): string {
    return BASE_URL . '/' . $path . '?v=' . (@filemtime(__DIR__ . '/../../' . $path) ?: time());
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $v('assets/css/ui-kit.css'); ?>">
    <link rel="stylesheet" href="<?php echo $v('assets/css/admin_style.css'); ?>">
    <style>
        .scan-wrap { max-width: 560px; margin: 0 auto; display: grid; gap: 16px; }
        .scan-view { position: relative; width: 100%; aspect-ratio: 1 / 1; max-width: 420px; margin: 0 auto; border-radius: var(--radius-lg); overflow: hidden; background: var(--navy); border: 1px solid var(--border); box-shadow: var(--shadow); }
        .scan-view video { width: 100%; height: 100%; object-fit: cover; display: block; }
        .scan-frame { position: absolute; inset: 12%; border-radius: 18px; pointer-events: none;
            background:
                linear-gradient(var(--primary), var(--primary)) top left / 34px 4px no-repeat,
                linear-gradient(var(--primary), var(--primary)) top left / 4px 34px no-repeat,
                linear-gradient(var(--primary), var(--primary)) top right / 34px 4px no-repeat,
                linear-gradient(var(--primary), var(--primary)) top right / 4px 34px no-repeat,
                linear-gradient(var(--primary), var(--primary)) bottom left / 34px 4px no-repeat,
                linear-gradient(var(--primary), var(--primary)) bottom left / 4px 34px no-repeat,
                linear-gradient(var(--primary), var(--primary)) bottom right / 34px 4px no-repeat,
                linear-gradient(var(--primary), var(--primary)) bottom right / 4px 34px no-repeat; }
        .scan-hint { position: absolute; left: 12px; right: 12px; bottom: 12px; padding: 8px 12px; border-radius: var(--radius-pill); background: rgba(15, 23, 42, .65); color: #fff; font-size: var(--fs-sm); text-align: center; }
        .scan-tools { position: absolute; top: 10px; right: 10px; display: grid; gap: 8px; }
        .scan-tools button { width: 38px; height: 38px; border-radius: 50%; border: 0; background: rgba(0, 0, 0, .5); color: #fff; cursor: pointer; }
        .scan-tools button[aria-pressed="true"] { background: var(--warning); }
        .scan-idle { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; padding: 24px; text-align: center; color: #e2e8f0; background: var(--navy); }
        .scan-idle i.fa-qrcode { font-size: 3rem; opacity: .8; }
        .scan-idle p { margin: 0; max-width: 300px; font-size: var(--fs-sm); }
        .scan-busy { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 10px; background: rgba(255, 255, 255, .8); color: #0f172a; font-weight: 600; }
        .scan-actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .scan-recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .scan-recent a { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); color: var(--text); text-decoration: none; }
        .scan-recent a:hover { border-color: var(--primary); }
        .scan-recent .num { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .scan-recent small { color: var(--muted); }
        .scan-found { text-align: center; margin-bottom: 14px; }
        .scan-found-icon { width: 56px; height: 56px; margin: 0 auto 8px; border-radius: 50%; display: grid; place-items: center; font-size: 1.5rem; background: var(--success-soft); color: var(--success); }
        .scan-found-icon.is-error { background: var(--danger-soft); color: var(--danger); }
        [hidden] { display: none !important; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="ui-page-head">
            <div>
                <a class="ui-back" href="orders.php"><i class="fas fa-arrow-left"></i> Orders</a>
                <h1 class="ui-page-title">Scan a delivery QR</h1>
                <p class="ui-page-sub">Point the camera at the QR on the customer’s order. You will see the order before any status change.</p>
            </div>
        </div>

        <div class="scan-wrap" id="scanner" data-jsqr="<?php echo htmlspecialchars($v('assets/vendor/jsqr/jsQR.js')); ?>">
            <div class="scan-view">
                <video id="video" playsinline muted hidden></video>
                <div class="scan-frame" id="frame" hidden></div>
                <div class="scan-hint" id="hint" hidden>Point the camera at the Delivery QR</div>
                <div class="scan-tools" id="tools" hidden>
                    <button type="button" id="torch" aria-pressed="false" title="Flashlight" aria-label="Flashlight" hidden><i class="fas fa-bolt"></i></button>
                    <button type="button" id="switch" title="Switch camera" aria-label="Switch camera"><i class="fas fa-camera-rotate"></i></button>
                </div>
                <div class="scan-idle" id="idle">
                    <i class="fas fa-qrcode" aria-hidden="true"></i>
                    <p id="idle-text">Scan the customer’s Delivery QR (from their order page or e-receipt).</p>
                    <button type="button" class="ui-btn ui-btn-primary ui-btn-lg" id="start"><i class="fas fa-camera"></i> Open camera</button>
                </div>
                <div class="scan-busy" id="busy" hidden><span class="ui-spinner"></span> Finding order…</div>
            </div>

            <div class="scan-actions">
                <label class="ui-btn" for="photo"><i class="fas fa-image"></i> Scan from photo</label>
                <button type="button" class="ui-btn" id="stop" disabled><i class="fas fa-stop"></i> Stop camera</button>
            </div>
            <input type="file" id="photo" accept="image/*" hidden>

            <div class="ui-alert ui-alert-warning" id="secure-note" hidden><i class="fas fa-lock"></i> Live camera needs a secure (https://) connection. Use <strong>Scan from photo</strong> instead.</div>
            <div class="ui-alert ui-alert-danger" id="error" hidden></div>

            <section class="ui-card" id="recent-card" hidden>
                <div class="ui-card-head">
                    <h2 class="ui-card-title">Recent scans</h2>
                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" id="recent-clear">Clear</button>
                </div>
                <div class="ui-card-body"><ul class="scan-recent" id="recent"></ul></div>
            </section>
        </div>
    </div>

    <div class="ui-modal" id="found" role="dialog" aria-modal="true" aria-labelledby="found-title">
        <div class="ui-modal-dialog">
            <div class="ui-modal-head">
                <h2 class="ui-modal-title" id="found-title">Order found</h2>
                <button type="button" class="ui-close" data-close aria-label="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="ui-modal-body" id="found-body"></div>
            <div class="ui-modal-foot">
                <button type="button" class="ui-btn" data-close><i class="fas fa-qrcode"></i> Scan another</button>
                <a class="ui-btn ui-btn-primary" id="found-open" href="#"><i class="fas fa-receipt"></i> Open order</a>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('scanner');
    var video = document.getElementById('video');
    var startBtn = document.getElementById('start'), stopBtn = document.getElementById('stop');
    var errorBox = document.getElementById('error');
    var modal = document.getElementById('found');
    var stream = null, detector = null, loopTimer = null, busy = false, wantCamera = false, facing = 'environment';
    var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
    var hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    var RECENT_KEY = 'dps_admin_recent_scans';
    var STATUS = {
        pending: ['Pending', 'warning'], confirmed: ['Confirmed', 'info'], processing: ['Processing', 'info'],
        ready_for_pickup: ['Ready for Pickup', 'accent'], to_ship: ['To Ship', 'primary'], to_receive: ['To Receive', 'primary'],
        out_for_delivery: ['Out for Delivery', 'accent'], reviews: ['Reviews', 'gold'], completed: ['Completed', 'success'], cancelled: ['Cancelled', 'danger']
    };
    var PAY = { cod: 'Cash on Delivery', gcash: 'GCash', bank: 'Bank Transfer', pay_at_shop: 'Pay at Shop', cash: 'Cash', walk_in: 'Walk-in' };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function badge(st) { var m = STATUS[st] || [String(st || '—').replace(/_/g, ' '), 'neutral']; return '<span class="ui-badge ui-badge-' + m[1] + '">' + esc(m[0]) + '</span>'; }
    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function showError(msg) { errorBox.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + esc(msg); errorBox.hidden = !msg; }

    if (!hasCamera) {
        document.getElementById('secure-note').hidden = window.isSecureContext !== false;
        document.getElementById('idle-text').textContent = 'Live camera is not available here. Use “Scan from photo” below.';
        startBtn.hidden = true;
    }

    // ---- Recent scans (this browser only, like the Flutter app's local list) ----
    function loadRecent() { try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; } catch (e) { return []; } }
    function saveRecent(list) { try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) {} }
    function renderRecent() {
        var list = loadRecent();
        document.getElementById('recent-card').hidden = !list.length;
        document.getElementById('recent').innerHTML = list.map(function (r) {
            return '<li><a href="order_details.php?id=' + (+r.id) + '"><span class="num">#' + esc(r.number) + '<br><small>' + esc(new Date(r.at).toLocaleString()) + '</small></span>' +
                badge(r.status) + '<i class="fas fa-chevron-right" style="color:var(--faint)"></i></a></li>';
        }).join('');
    }
    function addRecent(o) {
        var list = loadRecent().filter(function (r) { return +r.id !== o.id; });
        list.unshift({ id: o.id, number: o.order_number, status: o.order_status, at: Date.now() });
        saveRecent(list.slice(0, 10));
        renderRecent();
    }
    document.getElementById('recent-clear').addEventListener('click', function () { saveRecent([]); renderRecent(); });
    renderRecent();

    // ---- Decoders (same approach as rider/scan.php) ----
    var jsqrReady = null;
    function loadJsQR() {
        if (window.jsQR) return Promise.resolve();
        if (!jsqrReady) {
            jsqrReady = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = root.dataset.jsqr;
                s.onload = resolve;
                s.onerror = function () { jsqrReady = null; reject(new Error('Could not load the QR reader. Check your connection.')); };
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
    function decode(source, w, h, maxSide) {
        if (detector) {
            return detector.detect(source).then(function (c) { return c.length ? c[0].rawValue : null; }, function () { return null; });
        }
        var scale = Math.min(1, (maxSide || 720) / Math.max(w, h));
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' }) : null;
        return Promise.resolve(code ? code.data : null);
    }
    var frameNo = 0;
    function decodeLive() {
        var w = video.videoWidth, h = video.videoHeight;
        frameNo++;
        var first = detector ? decode(video, w, h) : Promise.resolve(null);
        return first.then(function (text) {
            if (text || !window.jsQR || (detector && frameNo % 2)) return text;
            // Zoomed centre crop: more pixels per QR square, copes better with glare / exposure changes.
            var side = Math.round(Math.min(w, h) * 0.75), out = Math.min(side, 640);
            canvas.width = out; canvas.height = out;
            ctx.drawImage(video, (w - side) / 2, (h - side) / 2, side, side, 0, 0, out, out);
            var code = window.jsQR(ctx.getImageData(0, 0, out, out).data, out, out, { inversionAttempts: 'attemptBoth' });
            return code ? code.data : null;
        });
    }

    // ---- Camera ----
    function showLive(on) {
        video.hidden = !on;
        document.getElementById('frame').hidden = !on;
        document.getElementById('hint').hidden = !on;
        document.getElementById('tools').hidden = !on;
        document.getElementById('idle').hidden = on;
        stopBtn.disabled = !on;
    }
    function tuneCamera(track) {
        var caps = {};
        try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch (e) {}
        var adv = [];
        ['focusMode', 'exposureMode', 'whiteBalanceMode'].forEach(function (k) {
            if ((caps[k] || []).indexOf('continuous') !== -1) { var o = {}; o[k] = 'continuous'; adv.push(o); }
        });
        if (adv.length) track.applyConstraints({ advanced: adv }).catch(function () {});
        var torch = document.getElementById('torch');
        torch.hidden = !caps.torch;
        torch.setAttribute('aria-pressed', 'false');
    }
    function startCamera() {
        if (!hasCamera || stream) return Promise.resolve();
        wantCamera = true;
        startBtn.disabled = true;
        showError('');
        return initDetector().then(function () {
            loadJsQR().catch(function () {});
            return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
        }).then(function (s) {
            stream = s;
            video.srcObject = s;
            tuneCamera(s.getVideoTracks()[0]);
            return video.play();
        }).then(function () {
            showLive(true);
            loop();
        }).catch(function (err) {
            stopCamera();
            wantCamera = false;
            showError(err && err.name === 'NotAllowedError' ? 'Camera permission was denied. Allow camera access for this site in your browser settings, or use “Scan from photo”.'
                : err && err.name === 'NotFoundError' ? 'No camera was found on this device. Use “Scan from photo”.'
                : (err && err.message) || 'Could not open the camera. Use “Scan from photo”.');
        }).then(function () { startBtn.disabled = false; });
    }
    function stopCamera() {
        clearTimeout(loopTimer);
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
            loopTimer = setTimeout(loop, detector ? 100 : 150);
        });
    }
    startBtn.addEventListener('click', startCamera);
    stopBtn.addEventListener('click', function () { wantCamera = false; stopCamera(); });
    document.getElementById('switch').addEventListener('click', function () {
        facing = facing === 'environment' ? 'user' : 'environment';
        stopCamera();
        startCamera();
    });
    document.getElementById('torch').addEventListener('click', function () {
        if (!stream) return;
        var btn = this, on = btn.getAttribute('aria-pressed') !== 'true';
        stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: on }] }).then(function () {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        }, function () { showError('This device does not allow the flashlight here.'); });
    });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { if (stream) stopCamera(); }
        else if (wantCamera && !busy && !modal.classList.contains('open')) startCamera();
    });
    window.addEventListener('pagehide', stopCamera);
    if (hasCamera && navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function (p) { if (p.state === 'granted') startCamera(); }, function () {});
    }

    // ---- Scan from photo ----
    document.getElementById('photo').addEventListener('change', function () {
        var f = this.files[0];
        this.value = '';
        if (!f || busy) return;
        var url = URL.createObjectURL(f), img = new Image();
        img.onload = function () {
            initDetector().then(function () {
                return decode(img, img.naturalWidth, img.naturalHeight, 1200).then(function (t) {
                    return t || decode(img, img.naturalWidth, img.naturalHeight, 2000);
                });
            }).then(function (text) {
                URL.revokeObjectURL(url);
                if (text) handle(text);
                else showError('No QR code found in that photo. Take it closer, straight on and in good light.');
            }, function (err) { URL.revokeObjectURL(url); showError(err.message); });
        };
        img.onerror = function () { URL.revokeObjectURL(url); showError('Could not read that photo.'); };
        img.src = url;
    });

    // ---- Validate on the server -> show the order -> admin opens it ----
    function resume() {
        busy = false;
        document.getElementById('busy').hidden = true;
        if (wantCamera && !stream) startCamera(); else loop();
    }
    function looksLikeDeliveryQr(text) {
        try { var q = JSON.parse(text); return q && typeof q === 'object' && q.delivery_id && q.token; } catch (e) { return false; }
    }
    function handle(text) {
        busy = true;
        clearTimeout(loopTimer);
        showError('');
        if (!looksLikeDeliveryQr(text)) {
            showError('That is not a valid delivery QR code.');
            setTimeout(resume, 1500);
            return;
        }
        if (navigator.vibrate) { try { navigator.vibrate(60); } catch (e) {} }
        document.getElementById('busy').hidden = false;
        fetch('scan_lookup_api.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ qr_data: text })
        }).then(function (r) {
            if (r.status === 401) { location.href = '<?php echo BASE_URL; ?>/modules/auth/login.php'; throw new Error('Your login has expired.'); }
            return r.json().catch(function () { throw new Error('The server sent an unexpected reply. Please try again.'); });
        }).then(function (res) {
            document.getElementById('busy').hidden = true;
            if (!res.success) throw new Error(res.message || 'No matching order was found for this QR code.');
            addRecent(res.data);
            openFound(res.data);
        }).catch(function (err) {
            document.getElementById('busy').hidden = true;
            showError(err instanceof TypeError ? 'No connection. Check your internet and try again.' : err.message);
            setTimeout(resume, 1500);
        });
    }
    function openFound(o) {
        if (stream) stopCamera();
        var pickup = o.fulfillment_type === 'pickup';
        var pay = PAY[o.payment_method] || (o.payment_method ? o.payment_method.toUpperCase() : '—');
        document.getElementById('found-body').innerHTML =
            '<div class="scan-found"><div class="scan-found-icon"><i class="fas fa-box-open"></i></div>' +
            '<strong>#' + esc(o.order_number) + '</strong></div>' +
            '<dl class="ui-kv">' +
            '<dt>Customer</dt><dd>' + esc(o.customer_name || 'Walk-in / guest') + '</dd>' +
            '<dt>Status</dt><dd>' + badge(o.order_status) + '</dd>' +
            '<dt>Type</dt><dd>' + (pickup ? 'Pickup' : 'Delivery') + '</dd>' +
            (pickup
                ? (o.pickup_date ? '<dt>Pickup</dt><dd>' + esc(o.pickup_date + (o.pickup_time ? ' ' + o.pickup_time : '')) + '</dd>' : '')
                : '<dt>Address</dt><dd>' + esc(o.delivery_address || '—') + '</dd><dt>Rider</dt><dd>' + esc(o.rider_name || 'Not assigned') + '</dd>') +
            '<dt>Items</dt><dd>' + (+o.item_count) + '</dd>' +
            '<dt>Total</dt><dd>' + peso(o.total) + '</dd>' +
            '<dt>Payment</dt><dd>' + esc(pay) + (o.payment_settled ? ' · <span class="ui-badge ui-badge-success">Paid</span>' : '') + '</dd>' +
            '<dt>Ordered</dt><dd>' + esc(o.created_at) + '</dd>' +
            '</dl>' +
            '<p class="ui-hint" style="margin:14px 0 0;">Nothing has been changed. Open the order to update its status.</p>';
        document.getElementById('found-open').href = 'order_details.php?id=' + (+o.id);
        modal.classList.add('open');
        document.getElementById('found-open').focus();
    }
    function closeFound() {
        if (!modal.classList.contains('open')) return;
        modal.classList.remove('open');
        resume();
    }
    modal.addEventListener('click', function (e) { if (e.target === modal || e.target.closest('[data-close]')) closeFound(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeFound(); });
});
</script>
</body>
</html>
