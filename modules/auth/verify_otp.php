<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/trusted_device_helper.php';
app_session_start();

const OTP_MAX_ATTEMPTS = 5; // wrong codes allowed before a 5-minute lock

// Redirect kung walang valid 2FA session (login.php sends an already signed-in admin on to the
// dashboard, and explains when the code step had ended).
if (!isset($_SESSION['2fa_user'])) {
    header("Location: login.php" . ($_SERVER["REQUEST_METHOD"] === "POST" ? "?error=otp_session" : ""));
    exit();
}

$error_message = "";
$auto_code = ''; // filled in on a remembered device (see trusted_device_helper.php)

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['forget_device'])) {
    trusted_device_forget($conn);
    header('Location: verify_otp.php');
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && trusted_device_valid($conn, (int) $_SESSION['2fa_user']['id'])) {
    $code_stmt = $conn->prepare('SELECT otp_code FROM users WHERE id = ? AND otp_code IS NOT NULL AND otp_expiry > NOW() LIMIT 1');
    $code_stmt->bind_param('i', $_SESSION['2fa_user']['id']);
    $code_stmt->execute();
    $auto_code = (string) ($code_stmt->get_result()->fetch_assoc()['otp_code'] ?? '');
    $code_stmt->close();
}
$device_remembered = $auto_code !== '' || ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_COOKIE[TRUSTED_DEVICE_COOKIE]));

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Digits only: a code pasted from the email as "123 456" or "123-456" still works.
    $input_otp = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));
    $user_id = $_SESSION['2fa_user']['id'];
    if (strlen($input_otp) !== 6) {
        // Not a full code (typo, empty): ask again without spending an attempt.
        $error_message = 'Please enter the 6-digit code from your email.';
    } else {

    $lock_stmt = $conn->prepare('SELECT login_attempts, lock_until FROM users WHERE id = ? LIMIT 1');
    $lock_stmt->bind_param('i', $user_id);
    $lock_stmt->execute();
    $lock_state = $lock_stmt->get_result()->fetch_assoc() ?: [];
    $lock_stmt->close();
    $locked_until = !empty($lock_state['lock_until']) ? strtotime($lock_state['lock_until']) : 0;
    if ($locked_until > time()) {
        $error_message = 'Too many incorrect codes. Login is temporarily locked. Return to login and try again later.';
    } else {
    $current_time = date("Y-m-d H:i:s");
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND otp_code = ? AND otp_expiry > ? LIMIT 1");
    $stmt->bind_param("iss", $user_id, $input_otp, $current_time);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        // TAMA ANG OTP!
        // 1. Linisin ang OTP sa DB para hindi na magamit ulit
        $clear = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL, login_attempts = 0, lock_until = NULL WHERE id = ?");
        $clear->bind_param("i", $user_id);
        $clear->execute();

        // 2. I-issue ang JWT
        $user_data = $_SESSION['2fa_user'];
        $token = generateJWT($user_data['id'], $user_data['username'], $user_data['role']);
        if (!setJWTCookie($token)) {
            // Another account signed in on this browser in the meantime.
            unset($_SESSION['2fa_user']);
            $_SESSION['login_blocked'] = true;
            header("Location: login.php?blocked=1");
            exit();
        }

        if (($_POST['remember_device'] ?? '') === '1') {
            trusted_device_remember($conn, (int) $user_data['id']);
        }

        // Send a security alert about the successful login
        notifications_send_admin_login_alert($_SESSION['2fa_user']['email'] ?? SMTP_USERNAME, $user_data['username']);

        session_regenerate_id(true);
        unset($_SESSION['2fa_user']); // Tapos na ang 2FA process
        unset($_SESSION['csrf_token']);
        header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
        exit();
    } else {
        $attempts = ($locked_until > 0 && $locked_until <= time() ? 0 : (int) ($lock_state['login_attempts'] ?? 0)) + 1;
        if ($attempts >= OTP_MAX_ATTEMPTS) {
            $lock_until = date('Y-m-d H:i:s', time() + 300);
            $failed = $conn->prepare('UPDATE users SET login_attempts = ?, lock_until = ?, otp_code = NULL, otp_expiry = NULL WHERE id = ?');
            $failed->bind_param('isi', $attempts, $lock_until, $user_id);
            $failed->execute();
            $failed->close();
            unset($_SESSION['2fa_user']);
            header('Location: login.php?error=otp_locked');
            exit();
        }
        $failed = $conn->prepare('UPDATE users SET login_attempts = ?, lock_until = NULL WHERE id = ?');
        $failed->bind_param('ii', $attempts, $user_id);
        $failed->execute();
        $failed->close();
        $left = OTP_MAX_ATTEMPTS - $attempts;
        $error_message = 'That code is incorrect or has expired. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
    }
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
    <title>Verify OTP</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
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
    <h2>Two-Factor Authentication</h2>
    <?php if ($auto_code !== ''): ?>
    <p style="text-align: center; font-size: 0.9rem; margin-bottom: 20px;"><i class="fas fa-shield-halved" style="color:#16a34a;"></i> This device is remembered, so your code was filled in for you. Signing you in&hellip;</p>
    <?php else: ?>
    <p style="text-align: center; font-size: 0.9rem; margin-bottom: 20px;">Please enter the 6-digit code sent to your email.</p>
    <?php endif; ?>
    
    <?php if ($error_message != ""): ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="otpForm">
        <?php echo csrf_field(); ?>
        <div class="input-box">
            <input type="text" name="otp_code" id="otp_code" placeholder="Enter 6-digit OTP" required
                   inputmode="numeric" autocomplete="one-time-code" maxlength="12" autofocus
                   value="<?php echo htmlspecialchars($auto_code, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:0.85rem;margin:2px 0 16px;cursor:pointer;line-height:1.35;">
            <input type="checkbox" name="remember_device" value="1" checked style="margin-top:2px;width:auto;height:auto;">
            <span>Remember this device for <?php echo TRUSTED_DEVICE_DAYS; ?> days - the code fills in by itself next time. Untick on a shared computer.</span>
        </label>
        <div class="input-box button">
            <input type="submit" value="Verify Code" id="otpSubmit">
        </div>
        <div class="text"><h3><a href="login.php">Back to Login</a></h3></div>
    </form>
    <?php if ($device_remembered): ?>
    <form method="POST" style="text-align:center;margin-top:6px;">
        <?php echo csrf_field(); ?>
        <button type="submit" name="forget_device" value="1" style="background:none;border:0;color:#64748b;font-size:0.8rem;text-decoration:underline;cursor:pointer;">Forget this device</button>
    </form>
    <?php endif; ?>
</div>
<script>
(function () {
    var form = document.getElementById('otpForm');
    var input = document.getElementById('otp_code');
    var button = document.getElementById('otpSubmit');
    // Keep digits only as the code is typed or pasted ("123 456" -> "123456").
    input.addEventListener('input', function () {
        var digits = input.value.replace(/\D+/g, '').slice(0, 6);
        if (digits !== input.value) input.value = digits;
    });
    // Send the code once: a second tap would cancel the first request after the server already
    // used the code, and the page would land back on the login screen.
    var sent = false;
    form.addEventListener('submit', function (e) {
        if (sent) { e.preventDefault(); return; }
        sent = true;
        button.disabled = true;
        button.value = 'Verifying...';
    });
    // Coming back with the browser's Back button: allow submitting again.
    window.addEventListener('pageshow', function () { sent = false; button.disabled = false; button.value = 'Verify Code'; });
    // Remembered device: the code is already filled in - send it.
    if (<?php echo $auto_code !== '' ? 'true' : 'false'; ?>) {
        setTimeout(function () { if (!sent) { if (form.requestSubmit) form.requestSubmit(button); else { sent = true; button.disabled = true; button.value = 'Verifying...'; form.submit(); } } }, 700);
    }
})();
</script>
</body>
</html>