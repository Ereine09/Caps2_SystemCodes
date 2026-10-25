<?php
/**
 * Rider login (web). Same account + same session as the Flutter app's rider_login_api.php:
 * a `users` row with role 'rider', password_verify(), generateJWT() + setJWTCookie().
 * Adds the lockout the staff login already uses (3 wrong passwords -> 5 minutes, via the
 * existing users.login_attempts / users.lock_until columns) and a CSRF token, since this
 * form is on the open web.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/auth_rules_helper.php';

rider_security_headers();
active_session_device_hash(true); // give this browser its id now, so all its tabs share one (race-safe logins)

// Only same-portal relative targets (never an external URL).
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%-]*)?$#', $next)) {
    $next = 'dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && rider_current()) { // a submitted login is always processed (see customer/login.php)
    header('Location: ' . BASE_URL . '/rider/' . $next);
    exit();
}

$error = '';
$login_blocked = false;   // another account is signed in on this browser (active_session_helper.php)
$lock_seconds = 0;
$username_value = '';
$notice = [
    'logged_out' => 'You have been logged out.',
    'access_denied' => 'That page is for riders only. Log in with a rider account.',
    'password_reset' => 'Your password was changed. Log in with your new password.',
][$_GET['msg'] ?? ''] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $username_value = trim((string) ($_POST['username_email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username_value === '' || $password === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (!auth_login_input_ok($username_value, $password)) {
        $error = 'Invalid username or password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param('ss', $username_value, $username_value);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $now = time();
        $locked_until = ($user && $user['lock_until']) ? strtotime($user['lock_until']) : 0;

        if (!$user || strtolower(trim((string) $user['role'])) !== 'rider') {
            // Same message for "no such user" and "not a rider": no account enumeration.
            $error = 'Invalid username or password.';
        } elseif ($locked_until > $now) {
            $lock_seconds = $locked_until - $now;
            $error = 'Too many failed attempts. Try again in a few minutes.';
        } elseif (password_verify($password, (string) $user['password'])) {
            $reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?");
            $reset->bind_param('i', $user['id']);
            $reset->execute();
            $reset->close();

            if (!setJWTCookie(generateJWT((int) $user['id'], (string) $user['username'], 'rider'))) {
                $login_blocked = true; // right credentials, but another account is signed in on this browser
            } else {
                session_regenerate_id(true);
                header('Location: ' . BASE_URL . '/rider/' . $next);
                exit();
            }
        } else {
            $attempts = ($locked_until > 0 ? 0 : (int) $user['login_attempts']) + 1;
            if ($attempts >= 3) {
                $lock_seconds = 300;
                $until = date('Y-m-d H:i:s', $now + $lock_seconds);
                $upd = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = ? WHERE id = ?");
                $upd->bind_param('isi', $attempts, $until, $user['id']);
                $error = 'Too many failed attempts. Try again in a few minutes.';
            } else {
                $upd = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = NULL WHERE id = ?");
                $upd->bind_param('ii', $attempts, $user['id']);
                $error = 'Invalid username or password. ' . (3 - $attempts) . ' attempt(s) left.';
            }
            $upd->execute();
            $upd->close();
        }
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
    <title>Rider Login · DPS Rider</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/rider/assets/icons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/rider/manifest.json">
    <script>if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('<?php echo BASE_URL; ?>/sw.js',{scope:'<?php echo BASE_URL; ?>/'}).catch(function(){});});}</script>
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
            <h1>DPS Rider</h1>
            <p>Log in to see your deliveries</p>
        </div>
        <form class="rd-card rd-stack" method="post" action="<?php echo BASE_URL; ?>/rider/login.php" id="login-form" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo rider_e($next); ?>">
            <?php if ($login_blocked) echo active_session_blocked_html(); ?>
            <?php if ($notice && !$error): ?>
                <div class="rd-note"><i class="fas fa-circle-info"></i><span><?php echo rider_e($notice); ?></span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="rd-note is-danger" role="alert"><i class="fas fa-circle-exclamation"></i><span><?php echo rider_e($error); ?><?php if ($lock_seconds > 0): ?> <strong id="lock-timer" data-seconds="<?php echo (int) $lock_seconds; ?>"></strong><?php endif; ?></span></div>
            <?php endif; ?>
            <div class="rd-field">
                <label for="username_email">Username or email</label>
                <input class="rd-input" id="username_email" name="username_email" type="text" maxlength="100" autocomplete="username" autocapitalize="off" spellcheck="false" required value="<?php echo rider_e($username_value); ?>" <?php echo $username_value === '' ? 'autofocus' : ''; ?>>
            </div>
            <div class="rd-field">
                <label for="password">Password</label>
                <div class="rd-pass">
                    <input class="rd-input" id="password" name="password" type="password" maxlength="128" autocomplete="current-password" required <?php echo $username_value !== '' ? 'autofocus' : ''; ?>>
                    <button type="button" class="rd-icon-btn" id="toggle-pass" aria-label="Show password"><i class="fas fa-eye"></i></button>
                </div>
                <a class="rd-help rd-forgot-link" href="<?php echo BASE_URL; ?>/rider/forgot_password.php">Forgot password?</a>
            </div>
            <button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="login-btn"><i class="fas fa-right-to-bracket"></i> Log in</button>
            <p class="rd-help" style="text-align:center;margin:0;">Use the rider account the shop gave you.</p>
        </form>
    </div>
</main>
<script>
(function () {
    var form = document.getElementById('login-form');
    var btn = document.getElementById('login-btn');
    form.addEventListener('submit', function (e) {
        if (!form.username_email.value.trim() || !form.password.value) { e.preventDefault(); (form.username_email.value.trim() ? form.password : form.username_email).focus(); return; }
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="rd-spin"></span> Logging in…';
    });
    document.getElementById('toggle-pass').addEventListener('click', function () {
        var p = document.getElementById('password'), show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        this.innerHTML = '<i class="fas ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
    });
    var t = document.getElementById('lock-timer');
    if (t) {
        var s = parseInt(t.dataset.seconds, 10) || 0;
        (function tick() {
            t.textContent = '(' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + ')';
            if (s-- > 0) setTimeout(tick, 1000); else t.textContent = '';
        })();
    }
})();
</script>
</body>
</html>
