<?php
/**
 * Rider "Reset password" (web) - the page the rider/forgot_password.php email links to.
 * The link token is matched by its SHA-256 hash, only for a role 'rider' account, and only
 * until users.reset_expiry. Saving the new password:
 *   - clears the token (the link works once),
 *   - clears the login lockout, and
 *   - sets users.password_reset_at, which signs the rider out everywhere else
 *     (rider_current() rejects tokens issued before it).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../app/helpers/account_recovery_helper.php';

rider_security_headers();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$user = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token_hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, reset_expiry FROM users WHERE reset_token = ? AND LOWER(TRIM(role)) = 'rider' LIMIT 1");
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($user && (!$user['reset_expiry'] || strtotime($user['reset_expiry']) <= time())) {
        $user = null;
    }
}

$error = '';
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $rule_error = auth_password_error($password, [$user['username'], $user['email'], $user['first_name'], $user['last_name']]);

    if ($rule_error !== null) {
        $error = $rule_error;
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'The two passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');
        $upd = $conn->prepare("UPDATE users SET password = ?, password_reset_at = ?, reset_token = NULL, reset_expiry = NULL, login_attempts = 0, lock_until = NULL WHERE id = ?");
        $upd->bind_param('ssi', $hash, $now, $user['id']);
        $ok = $upd->execute();
        $upd->close();

        if ($ok) {
            $reset_name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            if ($reset_name === '') {
                $reset_name = (string) ($user['username'] ?? '');
            }
            recovery_send_password_changed_notice((string) ($user['email'] ?? ''), $reset_name, 'rider');
            header('Location: ' . BASE_URL . '/rider/login.php?msg=password_reset');
            exit();
        }
        $error = 'Could not save your new password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <meta name="referrer" content="no-referrer">
    <title>Reset Password · DPS Rider</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/rider/assets/icons/apple-touch-icon.png">
    <script src="<?php echo rider_asset('assets/js/theme.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo rider_asset('assets/css/ui-kit.css'); ?>">
    <link rel="stylesheet" href="<?php echo rider_asset('rider/assets/rider.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="rider-app">
<button type="button" class="rd-icon-btn rider-login-theme" data-theme-toggle aria-label="Switch theme"><i class="fas fa-moon" aria-hidden="true"></i></button>
<main class="rider-login">
    <div class="rider-login-card">
        <div class="rider-login-brand">
            <img src="<?php echo rider_e(SYSTEM_LOGO_URL); ?>" alt="<?php echo rider_e(SYSTEM_NAME); ?>">
            <h1>Reset password</h1>
            <p><?php echo $user ? 'Choose a new password for ' . rider_e($user['username']) : 'DPS Rider'; ?></p>
        </div>
        <?php if (!$user): ?>
            <div class="rd-card rd-stack">
                <div class="rd-note is-danger" role="alert"><i class="fas fa-link-slash"></i><span>This reset link is invalid, was already used, or has expired.</span></div>
                <a class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" href="<?php echo BASE_URL; ?>/rider/forgot_password.php"><i class="fas fa-paper-plane"></i> Send a new link</a>
                <a class="rd-btn rd-btn-ghost rd-btn-block" href="<?php echo BASE_URL; ?>/rider/login.php"><i class="fas fa-arrow-left"></i> Back to login</a>
            </div>
        <?php else: ?>
            <form class="rd-card rd-stack" method="post" action="<?php echo BASE_URL; ?>/rider/reset_password.php" id="reset-form" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo rider_e($token); ?>">
                <?php if ($error): ?>
                    <div class="rd-note is-danger" role="alert"><i class="fas fa-circle-exclamation"></i><span><?php echo rider_e($error); ?></span></div>
                <?php endif; ?>
                <input type="text" name="username" value="<?php echo rider_e($user['username']); ?>" autocomplete="username" hidden>
                <div class="rd-field">
                    <label for="password">New password</label>
                    <div class="rd-pass">
                        <input class="rd-input" id="password" name="password" type="password" maxlength="128" autocomplete="new-password" required autofocus>
                        <button type="button" class="rd-icon-btn" data-toggle-pass="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <span class="rd-help"><?php echo rider_e(auth_password_rules_text()); ?></span>
                </div>
                <div class="rd-field">
                    <label for="confirm_password">Confirm new password</label>
                    <div class="rd-pass">
                        <input class="rd-input" id="confirm_password" name="confirm_password" type="password" maxlength="128" autocomplete="new-password" required>
                        <button type="button" class="rd-icon-btn" data-toggle-pass="confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <span class="rd-help is-error" id="match-msg" hidden>The two passwords do not match.</span>
                </div>
                <button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="reset-btn"><i class="fas fa-key"></i> Save new password</button>
                <p class="rd-help" style="text-align:center;margin:0;">You'll be signed out on other devices.</p>
            </form>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
    var form = document.getElementById('reset-form');
    if (!form) return;
    var btn = document.getElementById('reset-btn');
    var msg = document.getElementById('match-msg');
    document.querySelectorAll('[data-toggle-pass]').forEach(function (b) {
        b.addEventListener('click', function () {
            var p = document.getElementById(b.dataset.togglePass), show = p.type === 'password';
            p.type = show ? 'text' : 'password';
            b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            b.innerHTML = '<i class="fas ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
        });
    });
    form.confirm_password.addEventListener('input', function () { msg.hidden = true; });
    form.addEventListener('submit', function (e) {
        if (!form.password.value) { e.preventDefault(); form.password.focus(); return; }
        if (form.password.value !== form.confirm_password.value) { e.preventDefault(); msg.hidden = false; form.confirm_password.focus(); return; }
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="rd-spin"></span> Saving…';
    });
})();
</script>
</body>
</html>
