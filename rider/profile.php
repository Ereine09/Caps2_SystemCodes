<?php
/**
 * Rider profile (Flutter Profile + Settings + Check In + Help Center). Account details come
 * from the rider's users/riders rows. Edits: name, My E-Bike, plate number (vehicle is always E-bike)
 * (rider_profile_api.php) and Available / Offline (rider_status_api.php).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../app/helpers/ebike_helper.php';
require_once __DIR__ . '/../app/helpers/profile_photo_helper.php';
$rider = require_rider();
$photo = profile_photo_get($conn, 'user', (int) $rider['id']);
$my_bike = ebike_rider_profile_bike($conn, (int) $rider['id']);
$has_fleet = ebike_fleet_summary($conn)['total'] > 0;

rider_page_start('Profile');
$initial = mb_strtoupper(mb_substr($rider['display_name'], 0, 1));
?>
<div class="rd-stack">
    <section class="rd-card rd-profile-head">
        <div class="rd-avatar-wrap">
            <div class="rd-avatar" id="pp-avatar" aria-hidden="true"><?php echo profile_avatar_inner($photo, $rider['display_name'], 1); ?></div>
            <label class="rd-avatar-edit" for="pp-file" title="Change profile photo" aria-label="Change profile photo"><i class="fas fa-camera"></i></label>
            <input type="file" id="pp-file" accept="image/*" hidden>
        </div>
        <h2 id="head-name"><?php echo rider_e($rider['display_name']); ?></h2>
        <div class="rd-muted">@<?php echo rider_e($rider['username']); ?></div>
        <span class="rd-badge rd-badge-success">Active rider account</span>
        <div class="rd-photo-actions">
            <label for="pp-file"><i class="fas fa-camera"></i> <span id="pp-label"><?php echo $photo ? 'Change photo' : 'Add profile photo'; ?></span></label>
            <button type="button" id="pp-remove"<?php echo $photo ? '' : ' hidden'; ?>><i class="fas fa-trash-can"></i> Remove</button>
        </div>
    </section>

    <button type="button" class="rd-duty" id="duty" data-on="<?php echo (int) $rider['is_on_duty'] === 1 ? '1' : '0'; ?>" aria-pressed="false">
        <span class="rd-duty-dot" aria-hidden="true"></span>
        <span class="rd-duty-text"><strong>Offline</strong><span></span></span>
        <span class="rd-switch" aria-hidden="true"></span>
    </button>

    <div class="rd-section-title">Account <a href="#" id="edit-name">Edit</a></div>
    <section class="rd-card">
        <dl class="rd-kv">
            <dt>Name</dt><dd id="kv-name"><?php echo trim($rider['first_name'] . ' ' . $rider['last_name']) !== '' ? rider_e(trim($rider['first_name'] . ' ' . $rider['last_name'])) : '<span class="rd-muted">Not set</span>'; ?></dd>
            <dt>Username</dt><dd><?php echo rider_e($rider['username']); ?></dd>
            <dt>Email</dt><dd><?php echo $rider['email'] ? rider_e($rider['email']) : '<span class="rd-muted">Not set</span>'; ?></dd>
            <dt>Status</dt><dd>Active</dd>
        </dl>
        <p class="rd-help" style="margin:12px 0 0;">To change your username or email, ask the shop admin.</p>
        <?php if ($rider['email']): ?>
            <a class="rd-btn rd-btn-block" href="<?php echo BASE_URL; ?>/rider/forgot_password.php" style="margin-top:12px;"><i class="fas fa-key"></i> Reset password</a>
        <?php endif; ?>
    </section>

    <?php if ($has_fleet): ?>
    <div class="rd-section-title">My E-Bike <a href="#" id="edit-ebike">Change</a></div>
    <section class="rd-card">
        <div class="rd-ebike" id="ebike-view">
            <span class="rd-ico"><i class="fas fa-bicycle"></i></span>
            <div>
                <?php if ($my_bike): ?>
                    <strong><?php echo rider_e($my_bike['bike_code']); ?></strong>
                    <span class="rd-muted"><?php echo rider_e(implode(' · ', array_filter([$my_bike['model'], $my_bike['plate_number'], $my_bike['bulk_ok'] ? 'For bulk orders' : '']))); ?></span>
                    <?php if (!$my_bike['usable']): ?><span class="rd-help is-error">The shop switched this e-bike off or put it under maintenance. Pick another one.</span><?php endif; ?>
                <?php else: ?>
                    <strong>No e-bike selected</strong>
                    <span class="rd-muted">Pick the shop e-bike you ride today.</span>
                <?php endif; ?>
            </div>
        </div>
        <p class="rd-help" style="margin:12px 0 0;">Used automatically when you scan a parcel at the store, so the shop knows who has which e-bike.</p>
    </section>
    <?php endif; ?>

    <div class="rd-section-title">Vehicle <a href="#" id="edit-vehicle">Edit</a></div>
    <section class="rd-card">
        <dl class="rd-kv">
            <dt>Vehicle type</dt><dd id="v-type"><?php echo $rider['vehicle_type'] ? rider_e($rider['vehicle_type']) : '<span class="rd-muted">Not set</span>'; ?></dd>
            <dt>Plate number</dt><dd id="v-plate"><?php echo $rider['plate_number'] ? rider_e($rider['plate_number']) : '<span class="rd-muted">Not set</span>'; ?></dd>
        </dl>
    </section>

    <div class="rd-section-title">More</div>
    <nav class="rd-list">
        <a class="rd-list-item" href="notifications.php"><i class="fas fa-bell"></i><span>Notifications</span><i class="fas fa-chevron-right"></i></a>
        <a class="rd-list-item" href="messages.php"><i class="fas fa-comments"></i><span>Customer messages</span><i class="fas fa-chevron-right"></i></a>
        <a class="rd-list-item" href="earnings.php"><i class="fas fa-chart-line"></i><span>Earnings &amp; performance<small>Delivery fees, tips and history</small></span><i class="fas fa-chevron-right"></i></a>
        <button type="button" class="rd-list-item" data-theme-toggle data-theme-label><i class="fas fa-moon"></i></button>
    </nav>

    <div class="rd-section-title">Help</div>
    <section class="rd-list">
        <details class="rd-faq"><summary>How do I go online?</summary><p>Tap the Available / Offline switch on Home or here. While you are available the shop can assign deliveries to you.</p></details>
        <details class="rd-faq"><summary>How do I update an order's status?</summary><p>Just two scans with <strong>Scan</strong>: 1) at the store, scan the parcel QR when you pick it up - the order goes <strong>Out for Delivery</strong> and the customer is told you are on the way; 2) at the customer, scan their QR when they receive it - the order is <strong>Completed</strong>. You always confirm before anything changes.</p></details>
        <details class="rd-faq"><summary>Which e-bike am I using?</summary><p>Pick it under <strong>My E-Bike</strong> on this page. When you scan a parcel at the store it is recorded with your deliveries automatically. Change it here whenever you switch e-bikes.</p></details>
        <details class="rd-faq"><summary>The camera won't open</summary><p>Allow camera access for this site in your browser. If live camera still isn't available, use <strong>Scan from photo</strong> and take a clear picture of the QR.</p></details>
        <details class="rd-faq"><summary>Where do I upload proof of delivery?</summary><p>Open the order and tap <strong>Pickup photo</strong> at the shop and <strong>Delivery photo</strong> at the customer. When you scan, the app also reminds you if a photo is missing. You can add a short note with each photo.</p></details>
        <details class="rd-faq"><summary>How do I pay the shop for cash (COD) orders?</summary><p><strong>Cash:</strong> bring it to the store and give it to the staff. They record it there, so you don't need to do anything in the app.<br><strong>GCash or bank transfer:</strong> send the money, then open <strong>Remittance</strong>, pick the orders, tap <strong>Send by GCash / Bank</strong> and enter the reference no. from your receipt. The shop checks that the money arrived and then approves it.</p></details>
    </section>

    <a class="rd-btn rd-btn-danger rd-btn-block rd-btn-lg" href="logout.php" id="logout"><i class="fas fa-right-from-bracket"></i> Log out</a>
</div>

<script src="<?php echo rider_asset('assets/js/profile-photo.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;

    // ---- Profile photo (rider_profile_api.php set_photo / remove_photo) ----------------
    (function () {
        var file = document.getElementById('pp-file'), avatar = document.getElementById('pp-avatar');
        var removeBtn = document.getElementById('pp-remove'), label = document.getElementById('pp-label');
        function show(url) {
            avatar.textContent = '';
            if (url) {
                var img = document.createElement('img');
                img.src = url; img.alt = '';
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
                avatar.appendChild(img);
            } else {
                avatar.textContent = (document.getElementById('head-name').textContent.trim().charAt(0) || '?').toUpperCase();
            }
            removeBtn.hidden = !url;
            label.textContent = url ? 'Change photo' : 'Add profile photo';
        }
        file.addEventListener('change', function () {
            var f = file.files[0];
            file.value = '';
            if (!f) return;
            R.notify('Uploading photo…', 'info');
            ProfilePhoto.toSquare(f).then(function (dataUri) {
                return R.call('rider_profile_api.php', { body: { action: 'set_photo', image: dataUri } });
            }).then(function (res) {
                show(res.data.photo_url);
                R.notify(res.message || 'Profile photo updated.', 'ok');
            }, function (err) { R.notify((err && err.message) || 'Could not save the photo.', 'error'); });
        });
        removeBtn.addEventListener('click', function () {
            if (!confirm('Remove your profile photo?')) return;
            R.call('rider_profile_api.php', { body: { action: 'remove_photo' } }).then(function (res) {
                show(null);
                R.notify(res.message || 'Profile photo removed.', 'ok');
            }, function (err) { R.notify((err && err.message) || 'Could not remove the photo.', 'error'); });
        });
    })();
    R.initDuty(document.getElementById('duty'));
    var vehicle = { type: <?php echo json_encode((string) $rider['vehicle_type']); ?>, plate: <?php echo json_encode((string) $rider['plate_number']); ?> };

    document.getElementById('edit-vehicle').addEventListener('click', function (e) {
        e.preventDefault();
        var s = R.sheet(
            '<h2 class="rd-sheet-title">Vehicle</h2><p class="rd-sheet-sub">Shown to the shop for your deliveries.</p>' +
            '<form class="rd-stack" id="vehicle-form">' +
            '<div class="rd-field"><label>Vehicle type</label><div class="rd-input" style="opacity:.75;">E-bike</div></div>' +
            '<div class="rd-field"><label for="vp">Plate number</label><input class="rd-input" id="vp" maxlength="20" autocapitalize="characters" value="' + esc(vehicle.plate) + '" autofocus></div>' +
            '<button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="vsave"><i class="fas fa-check"></i> Save</button>' +
            '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Cancel</button></form>'
        );
        s.el.querySelector('#vehicle-form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var p = s.el.querySelector('#vp').value.trim();
            R.guarded(s.el.querySelector('#vsave'), 'Saving…', function () {
                return R.call('rider_profile_api.php', { body: { plate_number: p } });
            }).then(function (res) {
                vehicle = { type: res.data.vehicle_type, plate: res.data.plate_number };
                document.getElementById('v-type').innerHTML = vehicle.type ? esc(vehicle.type) : '<span class="rd-muted">Not set</span>';
                document.getElementById('v-plate').innerHTML = vehicle.plate ? esc(vehicle.plate) : '<span class="rd-muted">Not set</span>';
                s.close();
                R.notify(res.message || 'Profile updated.', 'ok');
            }, R.fail);
        });
    });
    // ---- Edit name --------------------------------------------------------------------
    var names = { first: <?php echo json_encode((string) $rider['first_name']); ?>, last: <?php echo json_encode((string) $rider['last_name']); ?> };
    document.getElementById('edit-name').addEventListener('click', function (e) {
        e.preventDefault();
        var s = R.sheet(
            '<h2 class="rd-sheet-title">Edit profile</h2><p class="rd-sheet-sub">Your name is shown to the shop and to customers on their orders.</p>' +
            '<form class="rd-stack" id="name-form">' +
            '<div class="rd-field"><label for="fn">First name</label><input class="rd-input" id="fn" maxlength="50" autocomplete="given-name" required value="' + esc(names.first) + '" autofocus></div>' +
            '<div class="rd-field"><label for="ln">Last name</label><input class="rd-input" id="ln" maxlength="50" autocomplete="family-name" value="' + esc(names.last) + '"></div>' +
            '<p class="rd-help is-error" id="name-error" hidden></p>' +
            '<button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="nsave"><i class="fas fa-check"></i> Save</button>' +
            '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Cancel</button></form>'
        );
        s.el.querySelector('#name-form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var f = s.el.querySelector('#fn').value.trim(), l = s.el.querySelector('#ln').value.trim();
            var box = s.el.querySelector('#name-error');
            if (!f) { box.textContent = 'Enter your first name.'; box.hidden = false; return; }
            box.hidden = true;
            R.guarded(s.el.querySelector('#nsave'), 'Saving…', function () {
                return R.call('rider_profile_api.php', { body: { action: 'update_name', first_name: f, last_name: l } });
            }).then(function (res) {
                names = { first: res.data.first_name, last: res.data.last_name };
                var full = res.data.display_name;
                document.getElementById('kv-name').textContent = full;
                document.getElementById('head-name').textContent = full;
                var av = document.querySelector('.rd-avatar'); if (av && !av.querySelector('img')) av.textContent = full.charAt(0).toUpperCase();
                s.close();
                R.notify(res.message || 'Profile updated.', 'ok');
            }, function (err) { box.textContent = err.message; box.hidden = false; });
        });
    });

    // ---- My E-Bike ---------------------------------------------------------------------
    var ebikeLink = document.getElementById('edit-ebike');
    if (ebikeLink) ebikeLink.addEventListener('click', function (e) {
        e.preventDefault();
        var loading = R.sheet('<h2 class="rd-sheet-title">My E-Bike</h2><p class="rd-sheet-sub"><span class="rd-spin"></span> Loading e-bikes…</p>');
        R.call('rider_ebikes_api.php').then(function (res) {
            loading.close();
            var d = res.data || {}, bikes = d.ebikes || [], cur = d.profile_bike && d.profile_bike.usable ? d.profile_bike.id : 0;
            var opts = bikes.map(function (b) {
                var extra = [b.model, b.plate_number, b.bulk_ok ? 'For bulk orders' : ''].filter(Boolean).join(' · ');
                return '<label class="rd-choice"><input type="radio" name="bike" value="' + (+b.id) + '"' + (+b.id === cur ? ' checked' : '') + '>' +
                    '<span><strong>' + esc(b.bike_code) + '</strong>' + (extra ? '<small>' + esc(extra) + '</small>' : '') + '</span></label>';
            }).join('');
            opts += '<label class="rd-choice"><input type="radio" name="bike" value="0"' + (cur ? '' : ' checked') + '><span><strong>Not using a shop e-bike</strong><small>My own vehicle</small></span></label>';
            var s = R.sheet(
                '<h2 class="rd-sheet-title">My E-Bike</h2><p class="rd-sheet-sub">Pick the shop e-bike you ride today. E-bikes in use by other riders or under maintenance are not listed.</p>' +
                '<form class="rd-stack" id="ebike-form"><div class="rd-choices">' + opts + '</div>' +
                (bikes.length ? '' : '<p class="rd-help">No shop e-bike is free right now.</p>') +
                '<button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="esave"><i class="fas fa-check"></i> Save</button>' +
                '<button type="button" class="rd-btn rd-btn-ghost rd-btn-block" data-sheet-close>Cancel</button></form>'
            );
            s.el.querySelector('#ebike-form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var picked = s.el.querySelector('input[name="bike"]:checked');
                R.guarded(s.el.querySelector('#esave'), 'Saving…', function () {
                    return R.call('rider_profile_api.php', { body: { action: 'set_ebike', ebike_id: picked ? +picked.value : 0 } });
                }).then(function (res) {
                    var b = res.data && res.data.ebike;
                    document.getElementById('ebike-view').querySelector('div').innerHTML = b
                        ? '<strong>' + esc(b.bike_code) + '</strong><span class="rd-muted">' + esc([b.model, b.plate_number].filter(Boolean).join(' · ')) + '</span>'
                        : '<strong>No e-bike selected</strong><span class="rd-muted">Pick the shop e-bike you ride today.</span>';
                    s.close();
                    R.notify(res.message, 'ok');
                }, R.fail);
            });
        }, function (err) { loading.close(); R.fail(err); });
    });

    document.getElementById('logout').addEventListener('click', function (e) {
        if (!confirm('Log out of the rider portal?')) e.preventDefault();
    });
});
</script>
<?php rider_page_end('profile'); ?>
