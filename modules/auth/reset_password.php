<?php 
// CONSIDER: session_start(); should be in a central bootstrap file, not in individual pages.
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../../app/helpers/account_recovery_helper.php';
date_default_timezone_set('Asia/Manila'); 

$error = ''; 
$message = ''; 
$token = $_GET['token'] ?? ''; 
$seconds_left = 0;

if (empty($token)) {
    die("<div style='text-align:center; margin-top:50px;'><h2>Invalid Request</h2></div>");
}

// Links from Staff Management > Recover Account store only the token's SHA-256 hash
// (app/helpers/account_recovery_helper.php); older self-service links store the token itself.
$token_hash = hash('sha256', (string) $token);
$stmt = $conn->prepare('SELECT id, username, first_name, email, role, reset_expiry FROM users WHERE reset_token IN (?, ?) LIMIT 1');
$stmt->bind_param('ss', $token, $token_hash);
$stmt->execute(); 
$res = $stmt->get_result(); 

if ($user = $res->fetch_assoc()) {
    $now = time();
    $expiry_time = strtotime($user['reset_expiry']);
    $seconds_left = $expiry_time - $now;

    if ($seconds_left <= 0) {
        die("<div style='text-align:center; margin-top:50px;'><h2>Link Expired</h2><p>Request a new one <a href='" . BASE_URL . "/modules/auth/forgot_password.php'>here</a>.</p></div>");
    }
} else {
    die("<div style='text-align:center; margin-top:50px;'><h2>Invalid Reset Token</h2></div>");
}

// Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? ''; 
    $confirm = $_POST['confirm_password'] ?? ''; 

    // Password validation rules (similar to register.php)
    $passwordErrors = [];
    if (strlen($password) < 8) {
        $passwordErrors[] = 'at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $passwordErrors[] = 'one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $passwordErrors[] = 'one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $passwordErrors[] = 'one number';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\"\\|,.<>\/?]/', $password)) {
        $passwordErrors[] = 'one special character (e.g. !@#$%)';
    }
    // Shared password rules (app/helpers/auth_rules_helper.php) replace the checks above.
    $password_rule_error = auth_password_error((string) $password);
 
    if ($confirm !== $password) {
        $error = 'Passwords do not match'; 
    } elseif ($password_rule_error !== null) {
        $error = $password_rule_error;
    } 
 
    if (empty($error)) { 
        $hash = password_hash($password, PASSWORD_DEFAULT); 
        $current_date = date("Y-m-d H:i:s");

        $up = $conn->prepare('UPDATE users SET password = ?, password_reset_at = ?, reset_token = NULL, reset_expiry = NULL, login_attempts = 0, lock_until = NULL WHERE id = ?'); 
        $up->bind_param('ssi', $hash, $current_date, $user['id']); 
        
        if ($up->execute()) { 
            $reset_name = trim((string) ($user['first_name'] ?? '')) ?: (string) ($user['username'] ?? '');
            recovery_send_password_changed_notice((string) ($user['email'] ?? ''), $reset_name, (string) ($user['role'] ?? 'account'));
            $message = 'Password reset successful! <a href="' . BASE_URL . '/modules/auth/login.php" style="color:#27ae60;">Login here</a>';
            $seconds_left = 0; // the timer stops
        } else { 
            $error = 'Database error. Try again.'; 
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
    <title>Reset Password</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
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
                    window.location.href = "<?php echo BASE_URL; ?>/modules/auth/forgot_password.php";
                }
            }, 1000);
        }

        window.onload = function () {
            var timeLeft = <?php echo $seconds_left; ?>;
            if (timeLeft > 0) {
                var display = document.querySelector('#reset-timer');
                startResetTimer(timeLeft, display);
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Same background as the login page (login.php) */
        body {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            background: linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.55)),
                        url('<?php echo BASE_URL; ?>/assets/img/dpsbg.jpg') center / cover no-repeat fixed;
        }
        .wrapper {
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        }
    </style>
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>New Password</h2>
    <?php if ($seconds_left > 0): ?>
        <p style="font-size: 12px; color: #e74c3c; font-weight: bold; margin-bottom: 10px;">
            Link expires in: <span id="reset-timer">05:00</span>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 10px; font-size: 14px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div style="color: #27ae60; text-align:center; font-weight:bold;"><?php echo $message; ?></div> <?php // XSS: $message contains HTML link, so not escaping it. If it were plain text, it should be escaped. ?>
    <?php else: ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="input-box">
                <input type="password" name="password" placeholder="New Password" required minlength="8" maxlength="64" data-password-rules data-confirm="confirm_password" title="<?php echo htmlspecialchars(auth_password_rules_text()); ?>">
            </div>
            <div class="input-box">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required maxlength="64">
            </div>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="input-box button">
                <input type="submit" value="Update Password">
            </div>
        </form>
    <?php endif; ?>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/password-rules.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/password-rules.js') ?: time(); ?>" defer></script>
</body>
</html>