<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

// If the user confirmed the logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    // Admin area only: its login cookie and its LM_ADMIN session. The customer area stays logged in.
    clearJWTCookie();
    app_session_end();
    header("Location: " . BASE_URL . "/modules/auth/login.php"); // Redirect to login page
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Logout</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <style>
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
        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        .confirm-buttons .input-box.button {
            flex: 1;
            margin-top: 0;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Logout</h2>
    <p style="text-align: center; margin: 20px 0; color: #333; font-weight: 500;">Are you sure you want to logout?</p>
    
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="confirm-buttons">
            <div class="input-box button">
                <input type="submit" name="confirm_logout" value="Yes, Logout" />
            </div>
            <div class="input-box button">
                <input type="button" onclick="window.history.back()" value="No, Cancel" style="background: #95a5a6;" />
            </div>
        </div>
    </form>
</div>
</body>
</html>