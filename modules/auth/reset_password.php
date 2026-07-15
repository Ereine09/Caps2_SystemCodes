<?php 
// CONSIDER: session_start(); should be in a central bootstrap file, not in individual pages.
require_once __DIR__ . '/../../app/config/config.php';
date_default_timezone_set('Asia/Manila'); 

$error = ''; 
$message = ''; 
$token = $_GET['token'] ?? ''; 
$seconds_left = 0;

if (empty($token)) {
    die("<div style='text-align:center; margin-top:50px;'><h2>Invalid Request</h2></div>");
}

$stmt = $conn->prepare('SELECT id, reset_expiry FROM users WHERE reset_token = ? LIMIT 1'); 
$stmt->bind_param('s', $token); 
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
 
    if ($confirm !== $password) {
        $error = 'Passwords do not match'; 
    } elseif (count($passwordErrors) > 0) {
        $error = 'Password is invalid. It must contain: ' . implode(', ', $passwordErrors) . '.';
    } 
 
    if (empty($error)) { 
        $hash = password_hash($password, PASSWORD_DEFAULT); 
        $current_date = date("Y-m-d H:i:s");

        $up = $conn->prepare('UPDATE users SET password = ?, password_reset_at = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?'); 
        $up->bind_param('ssi', $hash, $current_date, $user['id']); 
        
        if ($up->execute()) { 
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
    <meta charset="UTF-8">
    <title>Reset Password</title>
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
</head>
<body>
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
            <div class="input-box">
                <input type="password" name="password" placeholder="New Password" required>
            </div>
            <div class="input-box">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            </div>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="input-box button">
                <input type="submit" value="Update Password">
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>