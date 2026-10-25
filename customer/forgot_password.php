<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/customer_reset_schema_helper.php';
require_once __DIR__ . '/../app/helpers/account_recovery_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';

customer_redirect_if_logged_in();
ensure_customer_reset_schema($conn);

$message = "Enter the email on your account and we'll send you a link to reset your password.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<span style='color:#e74c3c;'>Invalid email format.</span>";
    } else {
        $customer = get_customer_by_email($email);
        if ($customer) {
            $recovery = customer_recovery_find($conn, (int) $customer['id']);
            if ($recovery && !recovery_recently_sent($recovery['reset_expiry'])) {
                customer_recovery_send_link($conn, $recovery);
            }
        }
        $message = "<span style='color:#2ecc71;'>If an account with that email is eligible for password reset, a link will be sent. Check your inbox or spam folder.</span>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Forgot Password | Darius Poultry Shop</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Card in the middle of the screen on phones too (ui-kit.css sets body to block there). */
        body { min-height: 100vh; min-height: 100dvh; display: grid !important; place-items: center; padding: 24px; box-sizing: border-box; }
        body > .wrapper { width: min(100%, 400px); margin: auto; }
        @media (max-height: 600px) { body { place-items: start center; } }
    </style>
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Forgot Password</h2>
    <div class="text" style="margin-bottom: 15px; font-weight: 600; text-align: center;">
        <?php echo $message; ?>
    </div>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required maxlength="100" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box button">
            <input type="submit" value="Send Reset Link" />
        </div>
        <div class="text">
            <p style="font-size: 0.82rem; color: #64748b; margin-top: 10px;">
                Can't access this email or your phone? Visit Darius Poultry Supplies in person and our Admin can help.
            </p>
        </div>
        <div class="text">
            <h3><a href="<?php echo BASE_URL; ?>/customer/login.php">Back to Login</a></h3>
        </div>
    </form>
</div>
</body>
</html>
