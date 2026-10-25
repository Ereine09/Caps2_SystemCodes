<?php
/**
 * Rider "Forgot password" (web). Emails a one-time reset link for a `users` row with role
 * 'rider', using the existing users.reset_token / users.reset_expiry columns.
 *
 * - Only a hash of the token is stored, so a leaked DB row can't be used as a link.
 * - The reply is the same whether or not the email belongs to a rider (no account enumeration).
 * - One email per minute per account, so the form can't be used to flood an inbox.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/account_recovery_helper.php';

rider_security_headers();

// Also reachable while signed in (Profile > Reset password): the email is pre-filled.
$signed_in = rider_current();

$sent = false;
$error = '';
$email_value = $signed_in ? (string) $signed_in['email'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email_value = trim((string) ($_POST['email'] ?? ''));

    if (!filter_var($email_value, FILTER_VALIDATE_EMAIL) || strlen($email_value) > 255) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, LOWER(TRIM(role)) AS role, reset_expiry FROM users WHERE email = ? AND LOWER(TRIM(role)) = 'rider' LIMIT 1");
        $stmt->bind_param('s', $email_value);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // A link issued less than a minute ago is still fresh: don't send another one.
        if ($user && !recovery_recently_sent($user['reset_expiry'])) {
            user_recovery_send_link($conn, $user);
        }
        // Same answer either way (see the note at the top).
        $sent = true;
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
    <title>Forgot Password · DPS Rider</title>
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
            <h1>Forgot password</h1>
            <p>We'll email you a link to reset it</p>
        </div>
        <?php if ($sent): ?>
            <div class="rd-card rd-stack">
                <div class="rd-note" role="status"><i class="fas fa-envelope-circle-check"></i><span>If <strong><?php echo rider_e($email_value); ?></strong> belongs to a rider account, a reset link is on its way. Check your inbox and spam folder. The link expires in <?php echo ACCOUNT_RESET_TTL_MINUTES; ?> minutes.</span></div>
                <a class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" href="<?php echo BASE_URL; ?>/rider/<?php echo $signed_in ? 'profile.php' : 'login.php'; ?>"><i class="fas fa-arrow-left"></i> <?php echo $signed_in ? 'Back to profile' : 'Back to login'; ?></a>
                <p class="rd-help" style="text-align:center;margin:0;">No email? Ask the shop admin to reset your password.</p>
            </div>
        <?php else: ?>
            <form class="rd-card rd-stack" method="post" action="<?php echo BASE_URL; ?>/rider/forgot_password.php" id="forgot-form" novalidate>
                <?php echo csrf_field(); ?>
                <?php if ($error): ?>
                    <div class="rd-note is-danger" role="alert"><i class="fas fa-circle-exclamation"></i><span><?php echo rider_e($error); ?></span></div>
                <?php endif; ?>
                <div class="rd-field">
                    <label for="email">Email on your rider account</label>
                    <input class="rd-input" id="email" name="email" type="email" maxlength="255" autocomplete="email" autocapitalize="off" spellcheck="false" required autofocus value="<?php echo rider_e($email_value); ?>">
                </div>
                <button type="submit" class="rd-btn rd-btn-primary rd-btn-block rd-btn-lg" id="forgot-btn"><i class="fas fa-paper-plane"></i> Send reset link</button>
                <a class="rd-btn rd-btn-ghost rd-btn-block" href="<?php echo BASE_URL; ?>/rider/<?php echo $signed_in ? 'profile.php' : 'login.php'; ?>"><i class="fas fa-arrow-left"></i> <?php echo $signed_in ? 'Back to profile' : 'Back to login'; ?></a>
            </form>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
    var form = document.getElementById('forgot-form');
    if (!form) return;
    var btn = document.getElementById('forgot-btn');
    form.addEventListener('submit', function (e) {
        if (!form.email.value.trim()) { e.preventDefault(); form.email.focus(); return; }
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="rd-spin"></span> Sending…';
    });
})();
</script>
</body>
</html>
