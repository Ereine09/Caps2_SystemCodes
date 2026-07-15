<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

// If the user confirmed the logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    clearJWTCookie(); // Clear JWT cookie
    header("Location: " . BASE_URL . "/modules/auth/login.php"); // Redirect to login page
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Logout</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <style>
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
</head>
<body>
<div class="wrapper">
    <h2>Logout</h2>
    <p style="text-align: center; margin: 20px 0; color: #333; font-weight: 500;">Are you sure you want to logout?</p>
    
    <form method="POST">
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