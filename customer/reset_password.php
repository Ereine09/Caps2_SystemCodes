<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/customer_reset_schema_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../app/helpers/account_recovery_helper.php';

// Deliberately no customer_redirect_if_logged_in() here (matches modules/auth/reset_password.php):
// a valid emailed link should always be able to set a new password, even if the browser
// still has an old session open.
ensure_customer_reset_schema($conn);
ensure_customer_password_meta_schema($conn);

$error = '';
$message = '';
$token = $_GET['token'] ?? '';
$seconds_left = 0;

if ($token === '') {
    die("<div style='text-align:center; margin-top:50px;'><h2>Invalid Request</h2></div>");
}

// Links sent from Customer Management > Account Recovery store only the token's SHA-256 hash
// (app/helpers/account_recovery_helper.php); the self-service form stores the token itself.
$token_hash = hash('sha256', (string) $token);
$stmt = $conn->prepare("SELECT customer_id, reset_expiry FROM customer_login_credentials WHERE reset_token IN (?, ?) LIMIT 1");
$stmt->bind_param('ss', $token, $token_hash);
$stmt->execute();
$res = $stmt->get_result();

if ($credential = $res->fetch_assoc()) {
    $seconds_left = strtotime($credential['reset_expiry']) - time();

    if ($seconds_left <= 0) {
        die("<div style='text-align:center; margin-top:50px;'><h2>Link Expired</h2><p>Request a new one <a href='" . BASE_URL . "/customer/forgot_password.php'>here</a>.</p></div>");
    }
} else {
    die("<div style='text-align:center; margin-top:50px;'><h2>Invalid Reset Token</h2></div>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (($password_rule_error = auth_password_error((string) $password)) !== null) {
        $error = $password_rule_error;
    } elseif ($confirm !== $password) {
        $error = 'Passwords do not match.';
    }

    if ($error === '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // password_changed_at also revokes any JWT session issued before this moment
        // (customer_token_is_stale() in customer/includes/auth.php) - a lost/stolen phone
        // that was still logged in stops being authenticated once this reset completes,
        // the same as the Admin-triggered "Reset Password" in Customer Management.
        $up = $conn->prepare("UPDATE customer_login_credentials SET password = ?, password_changed_at = NOW(), reset_token = NULL, reset_expiry = NULL, login_attempts = 0, lock_until = NULL WHERE customer_id = ?");
        $up->bind_param('si', $hash, $credential['customer_id']);

        if ($up->execute()) {
            $reset_customer = get_customer_by_id((int) $credential['customer_id']);
            recovery_send_password_changed_notice(
                (string) ($reset_customer['email'] ?? ''),
                (string) ($reset_customer['name'] ?? ''),
                'customer'
            );
            $message = 'Password reset successful! <a href="' . BASE_URL . '/customer/login.php" style="color:#2ecc71;">Login here</a>';
            $seconds_left = 0;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reset Password | Darius Poultry Shop</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css"/>
    <script>
        function startResetTimer(duration, display) {
            var timer = duration, minutes, seconds;
            var interval = setInterval(function () {
                minutes = parseInt(timer / 60, 10);
                seconds = parseInt(timer % 60, 10);
                minutes = minutes < 10 ? "0" + minutes : minutes;
                seconds = seconds < 10 ? "0" + seconds : seconds;
                display.textContent = minutes + ":" + seconds;
                if (--timer < 0) {
                    clearInterval(interval);
                    alert("Time's up! This link has expired.");
                    window.location.href = "<?php echo BASE_URL; ?>/customer/forgot_password.php";
                }
            }, 1000);
        }
        window.onload = function () {
            var timeLeft = <?php echo (int)$seconds_left; ?>;
            if (timeLeft > 0) {
                startResetTimer(timeLeft, document.querySelector('#reset-timer'));
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>New Password</h2>
    <?php if ($seconds_left > 0): ?>
        <p style="font-size: 12px; color: #e74c3c; font-weight: bold; margin-bottom: 10px; text-align: center;">
            Link expires in: <span id="reset-timer">15:00</span>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 10px; font-size: 14px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div style="color: #2ecc71; text-align:center; font-weight:bold;"><?php echo $message; ?></div>
    <?php else: ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="input-box">
                <input type="password" name="password" placeholder="New Password" required minlength="8" maxlength="64" data-password-rules data-confirm="confirm_password" title="<?php echo htmlspecialchars(auth_password_rules_text()); ?>">
            </div>
            <div class="input-box">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required maxlength="64">
            </div>
            <div class="input-box button">
                <input type="submit" value="Update Password">
            </div>
        </form>
    <?php endif; ?>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/password-rules.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/password-rules.js') ?: time(); ?>" defer></script>
</body>
</html>
