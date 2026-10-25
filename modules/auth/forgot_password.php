<?php
// TIMEZONE
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/account_recovery_helper.php';

// Default message
$message = "Forgot password? Just enter your email and we'll send you a reset link.";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<span style='color:red;'>Invalid email format.</span>";
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $account_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($account_row) {
            $account = user_recovery_find($conn, (int) $account_row['id']);
            if ($account && !recovery_recently_sent($account['reset_expiry'])) {
                user_recovery_send_link($conn, $account);
            }
        }
        $message = "<span style='color:green;'>If an account with that email is eligible for password reset, a link will be sent. Check your inbox or spam folder.</span>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Forgot Password</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.55)),
                        url('<?php echo BASE_URL; ?>/assets/img/dpsbg.jpg') center / cover no-repeat fixed;
        }
        .wrapper {
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        }
        input::-ms-reveal, input::-ms-clear {
            display: none;
        }
    </style>
<div class="wrapper">
    <h2>Forgot Password</h2>
    <p style="font-size: 13px; margin-bottom: 15px; color: #666;">Enter your email to receive a reset link.</p>
    
    <?php if ($message != ""): ?>
        <div style="margin-bottom: 15px; font-weight: 600; text-align: center;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="input-box">
            <input type="email" name="email" placeholder="Enter your Gmail" required maxlength="100" />
        </div>
        <div class="input-box button">
            <input type="submit" value="Send Reset Link" />
        </div>
        <div class="text">
            <h3><a href="<?php echo BASE_URL; ?>/modules/auth/login.php" style="text-decoration: underline; color: #27ae60;">Back to Login</a></h3>
        </div>
    </form>
</div>
</body>
</html>