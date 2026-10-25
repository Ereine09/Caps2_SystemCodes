<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/settings_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/upload_helper.php';
require_once __DIR__ . '/../../app/helpers/payment_helper.php';

// 1. SECURITY: Admin Only
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}
$user_id = (int) $payload['user_id'];

// Badge counts for the shared sidebar (same pattern as every other admin page).
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

settings_ensure_schema($conn);
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    csrf_require();
    $to_save = [
        'SMTP_HOST' => $_POST['smtp_host'],
        'SMTP_PORT' => $_POST['smtp_port'],
        'SMTP_USERNAME' => $_POST['smtp_username'],
        'SMTP_FROM_EMAIL' => $_POST['smtp_from_email'],
        'SMTP_FROM_NAME' => $_POST['smtp_from_name'],
        'LOYALTY_POINTS_EXPIRY_MONTHS' => $_POST['loyalty_points_expiry_months'],
        'SYSTEM_NAME' => $_POST['system_name'],
        'SYSTEM_LOGO_URL' => $_POST['system_logo_url'],
        'SHOP_ADDRESS' => $_POST['shop_address'],
        'ADMIN_AUTH_ENABLED' => isset($_POST['admin_auth_enabled']) ? '1' : '0',
    ];

    foreach ($to_save as $key => $val) {
        settings_update($conn, $key, trim($val));
    }
    $smtp_password = trim((string) ($_POST['smtp_password'] ?? ''));
    if ($smtp_password !== '') {
        settings_update($conn, 'SMTP_PASSWORD', $smtp_password);
    }

    // --- Payment Accounts: where customers/riders send manual GCash / bank transfers ---
    $pay_errors = [];
    $gcash_number = preg_replace('/[\s-]+/', '', (string) ($_POST['pay_gcash_number'] ?? ''));
    if ($gcash_number !== '' && !preg_match('/^(09\d{9}|\+639\d{9})$/', $gcash_number)) {
        $pay_errors[] = 'GCash number must be a PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).';
    }
    $bank_number = trim((string) ($_POST['pay_bank_account_number'] ?? ''));
    if ($bank_number !== '' && !preg_match('/^[0-9][0-9 -]{4,29}$/', $bank_number)) {
        $pay_errors[] = 'Bank account number may only contain digits, spaces and dashes.';
    }
    if (!$pay_errors) {
        settings_update($conn, 'PAY_GCASH_NAME', trim((string) ($_POST['pay_gcash_name'] ?? '')));
        settings_update($conn, 'PAY_GCASH_NUMBER', $gcash_number);
        settings_update($conn, 'PAY_BANK_NAME', trim((string) ($_POST['pay_bank_name'] ?? '')));
        settings_update($conn, 'PAY_BANK_ACCOUNT_NAME', trim((string) ($_POST['pay_bank_account_name'] ?? '')));
        settings_update($conn, 'PAY_BANK_ACCOUNT_NUMBER', $bank_number);
    }
    // QR codes: an uploaded image replaces the old one; "remove" clears it.
    foreach (['gcash' => 'PAY_GCASH_QR', 'bank' => 'PAY_BANK_QR'] as $kind => $key) {
        if (!empty($_POST["remove_{$kind}_qr"])) {
            settings_update($conn, $key, '');
        }
        if (isset($_FILES["pay_{$kind}_qr"]) && $_FILES["pay_{$kind}_qr"]['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = save_uploaded_image($_FILES["pay_{$kind}_qr"], __DIR__ . '/../../uploads/payment_qr/', 'uploads/payment_qr/', $kind . '_qr_', 3 * 1024 * 1024);
            if ($up['ok']) {
                settings_update($conn, $key, $up['url']);
            } else {
                $pay_errors[] = ($kind === 'gcash' ? 'GCash' : 'Bank') . ' QR: ' . $up['error'];
            }
        }
    }
    $success = $pay_errors ? '' : "System settings updated successfully. Changes are now live.";

    // Log the action
    $details = "Updated system API and SMTP configurations.";
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'SYSTEM_SETTINGS', ?)");
    $log->bind_param("is", $user_id, $details);
    $log->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <title>System Settings - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .settings-header { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }
        .settings-header .header-icon {
            width: 50px; height: 50px; border-radius: 14px; flex-shrink: 0;
            background: linear-gradient(135deg, var(--dk-primary, #4a3e94), #6c5ce7);
            display: flex; align-items: center; justify-content: center;
            color: var(--dk-on-primary, #fff); font-size: 1.3rem;
            box-shadow: 0 6px 16px rgba(74,62,148,0.28);
        }
        .settings-header h1 { font-size: 1.45rem; margin-bottom: 4px; line-height: 1.25; }
        .settings-header p { color: var(--muted); font-size: 0.9rem; }
        body.dark-mode .settings-header p { color: var(--dm-text-muted); }

        .settings-banner {
            display: flex; align-items: center; gap: 10px;
            background: var(--success-soft); color: #1e7e42; padding: 14px 18px;
            border-radius: 10px; border-left: 5px solid #27ae60; margin-bottom: 22px;
            font-weight: 500;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 24px;
            align-items: start;
        }
        /* Grid items default to min-width:auto, which lets a long unbroken
           value (a URL, an email) push its column wider than the track and
           overflow the page on narrow screens. Force them shrinkable. */
        .settings-grid > * { min-width: 0; }

        .settings-card { padding: 28px; overflow-wrap: break-word; transition: box-shadow 0.2s ease; }
        .settings-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.08); }
        body.dark-mode .settings-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.35); }
        .settings-card.full-width { grid-column: 1 / -1; }
        .settings-card h3 {
            display: flex; align-items: center; gap: 12px;
            font-size: 1.05rem; padding-bottom: 14px; margin-bottom: 20px;
            border-bottom: 1px solid #f1f1f4;
        }
        body.dark-mode .settings-card h3 { border-bottom-color: var(--dm-border); }
        .settings-card h3 i {
            width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
            background: rgba(74,62,148,0.08); color: var(--link);
            display: flex; align-items: center; justify-content: center; font-size: 0.95rem;
        }
        body.dark-mode .settings-card h3 i { background: rgba(96,165,250,0.12); color: var(--dm-link); }

        .form-field { margin-bottom: 18px; }
        .form-field:last-child { margin-bottom: 0; }
        .form-field label { display: block; font-weight: 600; font-size: 0.85rem; color: var(--text-2); margin-bottom: 6px; }
        body.dark-mode .form-field label { color: var(--dm-text-muted); }
        .form-field .hint { font-size: 0.75rem; color: var(--muted); margin-top: 6px; }
        body.dark-mode .form-field .hint { color: var(--dm-text-muted); }

        .form-field input, .form-field textarea, .ai-callout input {
            width: 100%; padding: 11px 12px; border: 1px solid var(--dk-border, #dfe3e8); border-radius: 8px;
            font-family: inherit; font-size: 0.9rem; transition: border-color 0.15s ease;
        }
        .form-field input:focus, .form-field textarea:focus, .ai-callout input:focus {
            outline: none; border-color: var(--dk-primary, #4a3e94); box-shadow: 0 0 0 3px rgba(74,62,148,0.1);
        }
        body.dark-mode .form-field input, body.dark-mode .form-field textarea, body.dark-mode .ai-callout input {
            background: var(--dm-surface-2); color: var(--dm-text); border-color: var(--dm-border);
        }
        body.dark-mode .form-field input:focus, body.dark-mode .form-field textarea:focus, body.dark-mode .ai-callout input:focus {
            border-color: var(--dm-link); box-shadow: 0 0 0 3px rgba(96,165,250,0.15);
        }
        .form-field textarea { min-height: 80px; resize: vertical; }

        .field-with-toggle { position: relative; }
        .field-with-toggle input { padding-right: 42px; }
        .field-with-toggle .toggle-eye {
            position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
            color: var(--muted); cursor: pointer; font-size: 0.95rem;
        }
        .field-with-toggle .toggle-eye:hover { color: var(--link); }

        .ai-callout {
            background: var(--info-soft); border: 1px solid var(--border); border-radius: 10px; padding: 18px;
        }
        body.dark-mode .ai-callout { background: var(--dm-surface-2); border-color: var(--dm-border); }

        .security-toggle-row {
            display: flex; align-items: center; gap: 14px; margin-bottom: 4px;
        }
        .switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch-track {
            position: absolute; cursor: pointer; inset: 0; background: #cbd5e1;
            border-radius: 999px; transition: background 0.2s ease;
        }
        .switch-track::before {
            content: ""; position: absolute; height: 20px; width: 20px; left: 3px; bottom: 3px;
            background: var(--surface); border-radius: 50%; transition: transform 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .switch input:checked + .switch-track { background: #27ae60; }
        .switch input:checked + .switch-track::before { transform: translateX(20px); }
        .switch input:disabled + .switch-track { opacity: 0.5; cursor: not-allowed; }
        .security-status-label { font-weight: 700; }
        .security-note {
            font-size: 0.82rem; color: var(--muted); margin-top: 12px; line-height: 1.5;
        }
        body.dark-mode .security-note { color: var(--dm-text-muted); }
        .security-note code { background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 4px; }
        body.dark-mode .security-note code { background: rgba(255,255,255,0.08); }

        .settings-actions {
            display: flex; gap: 14px; margin-top: 26px; position: sticky; bottom: 20px;
        }
        .btn-save {
            background: var(--dk-primary, #4a3e94); color: var(--dk-on-primary, white); border: none; padding: 15px 30px; border-radius: 10px;
            cursor: pointer; font-weight: 700; font-size: 0.95rem; flex: 2;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 8px 20px rgba(74,62,148,0.28); transition: transform 0.15s ease;
        }
        .btn-save:hover { transform: translateY(-1px); }
        .btn-back {
            background: var(--surface); color: var(--text-2); padding: 15px 30px; border-radius: 10px;
            text-decoration: none; font-weight: 700; text-align: center; flex: 1;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            border: 1px solid var(--dk-border, #dfe3e8);
        }
        body.dark-mode .btn-back { background: var(--dm-surface-2); color: var(--dm-text); border-color: var(--dm-border); }

        .settings-tabs {
            display: flex; gap: 4px; margin-bottom: 26px;
            background: var(--dk-surface-2, #f1f1f6); padding: 6px; border-radius: 12px;
            flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
        }
        .settings-tabs::-webkit-scrollbar { height: 0; }
        body.dark-mode .settings-tabs { background: var(--dm-surface-2); }
        .settings-tab-btn {
            display: flex; align-items: center; gap: 8px; flex-shrink: 0; white-space: nowrap;
            background: transparent; border: none; cursor: pointer;
            padding: 10px 16px; font-weight: 600; font-size: 0.88rem; color: var(--muted);
            border-radius: 9px;
            transition: color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }
        .settings-tab-btn:hover { color: var(--link); }
        .settings-tab-btn.active {
            background: var(--surface); color: var(--link);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        body.dark-mode .settings-tab-btn { color: var(--dm-text-muted); }
        body.dark-mode .settings-tab-btn:hover { color: var(--dm-link); }
        body.dark-mode .settings-tab-btn.active {
            background: var(--dm-surface); color: var(--dm-link);
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .settings-tab-panel { display: none; }
        .settings-tab-panel.active { display: block; animation: settingsTabFade 0.15s ease; }
        /* Centered settings column: header, banner, tabs, cards and actions share one width. */
        .main-content > .settings-header,
        .main-content > .settings-banner,
        .main-content > form { width: 100%; max-width: 780px; margin-left: auto; margin-right: auto; }
        /* Centered tabs that still scroll without clipping the first one on narrow screens */
        .settings-tab-btn:first-child { margin-left: auto; }
        .settings-tab-btn:last-child { margin-right: auto; }
        .settings-tab-panel .settings-card { max-width: none; }
        @keyframes settingsTabFade {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) {
            .settings-grid { grid-template-columns: 1fr; }
            .settings-actions { position: static; }
        }

        @media (max-width: 640px) {
            .settings-header { gap: 12px; margin-bottom: 22px; }
            .settings-header .header-icon { width: 42px; height: 42px; font-size: 1.1rem; border-radius: 12px; }
            .settings-header h1 { font-size: 1.2rem; }
            .settings-header p { font-size: 0.82rem; }
            .settings-card { padding: 20px; }
            .settings-tab-btn { padding: 9px 13px; font-size: 0.82rem; }
            .settings-actions { flex-direction: column; }
            .btn-save, .btn-back { flex: 1; width: 100%; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="settings-header">
            <div class="header-icon"><i class="fas fa-cogs"></i></div>
            <div>
                <h1>System Configuration</h1>
                <p>Manage brand identity, email delivery, loyalty rules, AI, and admin login security.</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="settings-banner"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!empty($pay_errors)): ?>
            <div class="settings-banner" style="background: var(--danger-soft); color: var(--danger);"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars(implode(' ', $pay_errors)); ?> Payment account details were not saved.</div>
        <?php endif; ?>
        <?php $pay_accounts = shop_payment_accounts($conn, false); $pay_live = shop_payment_accounts($conn); ?>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="settings-tabs" role="tablist">
                <button type="button" class="settings-tab-btn" data-tab="brand"><i class="fas fa-id-card"></i> Brand & Identity</button>
                <button type="button" class="settings-tab-btn" data-tab="smtp"><i class="fas fa-envelope"></i> SMTP / Email</button>
                <button type="button" class="settings-tab-btn" data-tab="loyalty"><i class="fas fa-star"></i> Loyalty & AI</button>
                <button type="button" class="settings-tab-btn" data-tab="security"><i class="fas fa-user-shield"></i> Security</button>
                <button type="button" class="settings-tab-btn" data-tab="payments"><i class="fas fa-wallet"></i> Payment Accounts</button>
            </div>

            <div class="settings-tab-panel" id="tab-brand">
                <!-- BRAND & IDENTITY -->
                <div class="table-box settings-card">
                    <h3><i class="fas fa-id-card"></i> Brand & Identity</h3>

                    <div class="form-field">
                        <label>System Name</label>
                        <input type="text" name="system_name" value="<?php echo SYSTEM_NAME; ?>" required>
                    </div>

                    <div class="form-field">
                        <label>System Logo URL</label>
                        <input type="text" name="system_logo_url" value="<?php echo $db_settings['SYSTEM_LOGO_URL'] ?? 'assets/img/loyalty_logo.png'; ?>" required>
                        <p class="hint">Use a full URL (http://...) so it also displays correctly in emails.</p>
                    </div>

                    <div class="form-field">
                        <label>Shop Address</label>
                        <textarea name="shop_address" required><?php echo SHOP_ADDRESS; ?></textarea>
                        <p class="hint">Street, barangay, city, postal code, and country.</p>
                    </div>
                </div>
            </div>

            <div class="settings-tab-panel" id="tab-smtp">
                <!-- SMTP / EMAIL -->
                <div class="table-box settings-card">
                    <h3><i class="fas fa-envelope"></i> SMTP / Email</h3>

                    <div class="form-field">
                        <label>SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo SMTP_HOST; ?>" required>
                    </div>

                    <div class="form-field">
                        <label>SMTP Port</label>
                        <input type="number" name="smtp_port" value="<?php echo SMTP_PORT; ?>" required>
                    </div>

                    <div class="form-field">
                        <label>SMTP Username (Email)</label>
                        <input type="email" name="smtp_username" value="<?php echo SMTP_USERNAME; ?>" required>
                    </div>

                    <div class="form-field">
                        <label>SMTP Password / App Password</label>
                        <div class="field-with-toggle">
                            <input type="password" name="smtp_password" id="smtp_password" autocomplete="new-password" placeholder="Leave blank to keep the saved password">
                            <i class="fas fa-eye toggle-eye" data-target="smtp_password"></i>
                        </div>
                        <p class="hint">For security, the saved password is never displayed. Enter a new value only when replacing it.</p>
                    </div>

                    <div class="form-field">
                        <label>Sender Email Address</label>
                        <input type="email" name="smtp_from_email" value="<?php echo SMTP_FROM_EMAIL; ?>" required>
                    </div>

                    <div class="form-field">
                        <label>Sender Display Name</label>
                        <input type="text" name="smtp_from_name" value="<?php echo SMTP_FROM_NAME; ?>" required>
                    </div>
                </div>
            </div>

            <div class="settings-tab-panel" id="tab-loyalty">
                <!-- LOYALTY + AI -->
                <div class="table-box settings-card">
                    <h3><i class="fas fa-star"></i> Loyalty & AI</h3>

                    <div class="form-field">
                        <label>Points Expiry (Months)</label>
                        <input type="number" name="loyalty_points_expiry_months" value="<?php echo LOYALTY_POINTS_EXPIRY_MONTHS; ?>" required>
                    </div>

                    <?php /* Gemini API key: no longer shown here; the saved key (system_settings) is kept and still used. */ ?>
                </div>
            </div>

            <div class="settings-tab-panel" id="tab-security">
                <!-- SECURITY -->
                <div class="table-box settings-card full-width">
                    <h3><i class="fas fa-user-shield"></i> Security</h3>

                    <div class="security-toggle-row">
                        <label class="switch">
                            <input type="checkbox" name="admin_auth_enabled" id="admin_auth_enabled" value="1"
                                   <?php echo ADMIN_AUTH_ENABLED ? 'checked' : ''; ?>
                                   <?php echo APP_ENV === 'production' ? 'disabled' : ''; ?>
                                   onchange="document.getElementById('security-status-label').textContent = this.checked ? 'Admin login required' : 'Admin login OFF';">
                            <span class="switch-track"></span>
                        </label>
                        <span id="security-status-label" class="security-status-label" style="color: <?php echo ADMIN_AUTH_ENABLED ? '#27ae60' : '#e74c3c'; ?>;">
                            <?php echo ADMIN_AUTH_ENABLED ? 'Admin login required' : 'Admin login OFF'; ?>
                        </span>
                    </div>

                    <?php if (APP_ENV === 'production'): ?>
                        <p class="security-note">Always ON in production — this can only be turned off on a local/dev copy of the site.</p>
                    <?php else: ?>
                        <p class="security-note">Uncheck this only for local dev/demo convenience (e.g. defense run-throughs) so the <strong>Admin</strong> pages (including Customers, Loyalty Points and Reward Redemption) — plus the admin login's OTP step — don't require logging in. Everything you do while it's off is logged under the first admin account. Staff and customer logins are never affected. Turn it back on afterwards.</p>
                    <?php endif; ?>
                </div>

            </div>

            <div class="settings-tab-panel" id="tab-payments">
                <!-- PAYMENT ACCOUNTS: shown to customers (manual GCash / bank checkout) and riders (remittance) -->
                <?php if ($pay_live['gcash']['is_default'] || $pay_live['bank']['is_default']): ?>
                    <div class="settings-banner full-width" style="grid-column: 1 / -1; background: var(--warning-soft); color: var(--text);"><i class="fas fa-triangle-exclamation"></i>
                        Checkout is currently showing the old sample details<?php echo $pay_live['gcash']['is_default'] ? ' (GCash QR image)' : ''; ?><?php echo $pay_live['bank']['is_default'] ? ' (BDO &middot; Darius Poultry Supply &middot; 001234567890)' : ''; ?>. Enter the shop's real accounts below so customers and riders pay the right account.</div>
                <?php endif; ?>
                <div class="table-box settings-card">
                    <h3><i class="fas fa-mobile-screen"></i> Shop GCash</h3>
                    <div class="form-field">
                        <label>Account name (as shown in GCash)</label>
                        <input type="text" name="pay_gcash_name" maxlength="100" value="<?php echo htmlspecialchars($pay_accounts['gcash']['account_name']); ?>" placeholder="e.g. JUAN D. DELA CRUZ">
                    </div>
                    <div class="form-field">
                        <label>GCash number</label>
                        <input type="text" name="pay_gcash_number" maxlength="16" inputmode="tel" value="<?php echo htmlspecialchars($pay_accounts['gcash']['account_number']); ?>" placeholder="09XXXXXXXXX">
                    </div>
                    <div class="form-field">
                        <label>GCash QR code (image)</label>
                        <?php if ($pay_accounts['gcash']['qr_url']): ?>
                            <img src="<?php echo htmlspecialchars($pay_accounts['gcash']['qr_url']); ?>" alt="Current GCash QR" style="display:block; width:140px; height:140px; object-fit:contain; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; background:#fff;">
                            <label style="font-weight:400;"><input type="checkbox" name="remove_gcash_qr" value="1" style="width:auto;"> Remove this QR</label>
                        <?php endif; ?>
                        <input type="file" name="pay_gcash_qr" accept="image/png, image/jpeg, image/webp">
                        <p class="hint">Download it from GCash &rarr; QR &rarr; Receive/Generate QR. PNG/JPG, up to 3 MB.</p>
                    </div>
                </div>
                <div class="table-box settings-card">
                    <h3><i class="fas fa-building-columns"></i> Shop Bank Account</h3>
                    <div class="form-field">
                        <label>Bank</label>
                        <input type="text" name="pay_bank_name" maxlength="100" value="<?php echo htmlspecialchars($pay_accounts['bank']['bank_name']); ?>" placeholder="e.g. BDO Unibank">
                    </div>
                    <div class="form-field">
                        <label>Account name</label>
                        <input type="text" name="pay_bank_account_name" maxlength="100" value="<?php echo htmlspecialchars($pay_accounts['bank']['account_name']); ?>">
                    </div>
                    <div class="form-field">
                        <label>Account number</label>
                        <input type="text" name="pay_bank_account_number" maxlength="30" inputmode="numeric" value="<?php echo htmlspecialchars($pay_accounts['bank']['account_number']); ?>">
                    </div>
                    <div class="form-field">
                        <label>Bank / InstaPay QR code (optional)</label>
                        <?php if ($pay_accounts['bank']['qr_url']): ?>
                            <img src="<?php echo htmlspecialchars($pay_accounts['bank']['qr_url']); ?>" alt="Current bank QR" style="display:block; width:140px; height:140px; object-fit:contain; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; background:#fff;">
                            <label style="font-weight:400;"><input type="checkbox" name="remove_bank_qr" value="1" style="width:auto;"> Remove this QR</label>
                        <?php endif; ?>
                        <input type="file" name="pay_bank_qr" accept="image/png, image/jpeg, image/webp">
                        <p class="hint">Customers and riders see these details when they pay by manual transfer. Payments to them are still verified by staff.</p>
                    </div>
                </div>
            </div>

            <div class="settings-actions">
                <button type="submit" name="save_settings" class="btn-save"><i class="fas fa-save"></i> Save All Configuration</button>
                <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </form>
    </div>

    <script>
        document.querySelectorAll('.toggle-eye').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.dataset.target);
                var isPassword = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPassword ? 'text' : 'password');
                btn.classList.toggle('fa-eye');
                btn.classList.toggle('fa-eye-slash');
            });
        });

        (function () {
            var tabButtons = document.querySelectorAll('.settings-tab-btn');
            var tabPanels = document.querySelectorAll('.settings-tab-panel');
            var validTabs = ['brand', 'smtp', 'loyalty', 'security'];
            var STORAGE_KEY = 'adminSettingsActiveTab';

            function activateTab(tab, updateHistory) {
                if (validTabs.indexOf(tab) === -1) tab = 'brand';
                tabButtons.forEach(function (btn) {
                    btn.classList.toggle('active', btn.dataset.tab === tab);
                });
                tabPanels.forEach(function (panel) {
                    panel.classList.toggle('active', panel.id === 'tab-' + tab);
                });
                try { localStorage.setItem(STORAGE_KEY, tab); } catch (e) {}
                if (updateHistory) {
                    history.replaceState(null, '', '#' + tab);
                }
            }

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateTab(btn.dataset.tab, true);
                });
            });

            var initialTab = (window.location.hash || '').replace('#', '');
            if (!initialTab) {
                try { initialTab = localStorage.getItem(STORAGE_KEY) || ''; } catch (e) { initialTab = ''; }
            }
            activateTab(initialTab || 'brand', false);
        })();
    </script>
    <script defer src="<?php echo BASE_URL; ?>/assets/js/nav-toggle.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/nav-toggle.js') ?: time(); ?>"></script>
</body>
</html>
