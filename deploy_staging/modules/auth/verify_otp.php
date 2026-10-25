<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
session_start();

// Redirect kung walang valid 2FA session
if (!isset($_SESSION['2fa_user'])) {
    header("Location: login.php");
    exit();
}

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_otp = trim($_POST['otp_code']);
    $user_id = $_SESSION['2fa_user']['id'];

    // I-verify ang OTP gamit ang current time mula sa PHP para iwas sa timezone mismatch
    $current_time = date("Y-m-d H:i:s");
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND otp_code = ? AND otp_expiry > ? LIMIT 1");
    $stmt->bind_param("iss", $user_id, $input_otp, $current_time);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        // TAMA ANG OTP!
        // 1. Linisin ang OTP sa DB para hindi na magamit ulit
        $clear = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL WHERE id = ?");
        $clear->bind_param("i", $user_id);
        $clear->execute();

        // 2. I-issue ang JWT
        $user_data = $_SESSION['2fa_user'];
        $token = generateJWT($user_data['id'], $user_data['username'], $user_data['role']);
        setJWTCookie($token);

        // Send a security alert about the successful login
        notifications_send_admin_login_alert($_SESSION['2fa_user']['email'] ?? SMTP_USERNAME, $user_data['username']);

        unset($_SESSION['2fa_user']); // Tapos na ang 2FA process
        header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
        exit();
    } else {
        $error_message = "Invalid or expired OTP code.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify OTP</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<div class="wrapper">
    <h2>Two-Factor Authentication</h2>
    <p style="text-align: center; font-size: 0.9rem; margin-bottom: 20px;">Please enter the 6-digit code sent to your email.</p>
    
    <?php if ($error_message != ""): ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-box">
            <input type="text" name="otp_code" placeholder="Enter 6-digit OTP" required maxlength="6" autofocus>
        </div>
        <div class="input-box button">
            <input type="submit" value="Verify Code">
        </div>
        <div class="text"><h3><a href="login.php">Back to Login</a></h3></div>
    </form>
</div>
</body>
</html>