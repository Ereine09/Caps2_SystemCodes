<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

session_start();

// Check if user already logged in via JWT
$token = getJWTFromCookie();
if ($token && verifyJWT($token)) {
    header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
    exit();
}

$login_message = ""; 
$lock_seconds_remaining = 0; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username_email = trim($_POST['username_email']);
    $password = $_POST['password'];

    // 'role' SELECT QUERY
    $stmt = $conn->prepare("SELECT id, username, email, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $username_email, $username_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $now = time(); 
        $lock_until_timestamp = $user['lock_until'] ? strtotime($user['lock_until']) : 0;

        if ($lock_until_timestamp && $lock_until_timestamp > $now) {
            $lock_seconds_remaining = $lock_until_timestamp - $now;
            $login_message = "Account locked. Please wait: <span id='timer' style='color:#e74c3c;'></span>";
        } else {
            if ($lock_until_timestamp > 0 && $lock_until_timestamp <= $now) {
                $user['login_attempts'] = 0;
            }

            if (password_verify($password, $user['password'])) {
                
                $reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?");
                $reset->bind_param("i", $user['id']);
                $reset->execute();

                if (strtolower(trim($user['role'])) === 'admin') {
                    // --- 2FA OTP GENERATION FOR ADMIN ---
                    $otp_code = sprintf("%06d", mt_rand(0, 999999));
                    $otp_expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

                    $update_otp = $conn->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE id = ?");
                    $update_otp->bind_param("ssi", $otp_code, $otp_expiry, $user['id']);
                    $update_otp->execute();

                    // --- SEND OTP VIA EMAIL ---
                    // We use the helper to send it to the Admin's configured email
                    $email_sent = notifications_send_login_otp($user['email'], $otp_code);

                    if (!$email_sent['success']) {
                        error_log("OTP Email failed: " . $email_sent['error']);
                    }

                        // Store user info in session for verify_otp.php
                        $_SESSION['2fa_user'] = [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'role' => $user['role']
                        ];

                        header("Location: verify_otp.php");
                        exit();

                    // } catch (Exception $e) {
                    //     // Log the actual error for debugging
                    //     error_log("SMTP Error: " . $e->getMessage());
                    //     $login_message = "Error sending verification code. Please try again later. (" . $e->getMessage() . ")";
                    // }
                } else {
                    $token = generateJWT($user['id'], $user['username'], $user['role']);
                    setJWTCookie($token);

                    if (strtolower(trim($user['role'])) === 'admin') {
                        header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
                    } else {
                        header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
                    }
                    exit();
                }

            } else {
                $new_attempts = $user['login_attempts'] + 1;
                $max_attempts = 3; 

                if ($new_attempts >= $max_attempts) {
                    $lock_duration = 300; 
                    $lock_until_time = date("Y-m-d H:i:s", strtotime("+$lock_duration seconds"));
                    $lock_seconds_remaining = $lock_duration;
                    $login_message = "Too many failed attempts. Locked for: <span id='timer' style='color:#e74c3c;'></span>";
                    
                    $update = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = ? WHERE id = ?");
                    $update->bind_param("isi", $new_attempts, $lock_until_time, $user['id']);
                } else {
                    $remaining = $max_attempts - $new_attempts;
                    $login_message = "Invalid password. $remaining attempt(s) left.";
                    $update = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = NULL WHERE id = ?");
                    $update->bind_param("ii", $new_attempts, $user['id']);
                }
                $update->execute();
            }
        }
    } else { // Generic message to prevent user enumeration.
        $login_message = "Invalid username or password.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Login</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        input::-ms-reveal, input::-ms-clear {
            display: none;
        }
    </style>
    <script>
        function startTimer(duration, display) {
            var timer = duration, minutes, seconds;
            var interval = setInterval(function () {
                minutes = parseInt(timer / 60, 10);
                seconds = parseInt(timer % 60, 10);
                minutes = minutes < 10 ? "0" + minutes : minutes;
                seconds = seconds < 10 ? "0" + seconds : seconds;
                display.textContent = minutes + ":" + seconds;
                if (--timer < 0) {
                    clearInterval(interval);
                    location.reload(); 
                }
            }, 1000);
        }
        window.onload = function () {
            var secondsRemaining = <?php echo $lock_seconds_remaining; ?>;
            if (secondsRemaining > 0) {
                var display = document.querySelector('#timer');
                if(display) startTimer(secondsRemaining, display);
            }
        };

        document.addEventListener("DOMContentLoaded", function () {
            const password = document.getElementById("password");
            const toggle = document.getElementById("togglePassword");

            if (toggle) {
                toggle.addEventListener("click", function () {
                    const type = password.getAttribute("type") === "password" ? "text" : "password";
                    password.setAttribute("type", type);

                    // Optional: change icon
                    toggle.classList.toggle("fa-eye");
                    toggle.classList.toggle("fa-eye-slash");
                });
            }
        });
    </script>
</head>
<body>
<div class="wrapper">
    <h2>Login</h2>
    <?php if ($login_message != ""): // XSS: $login_message is output directly. ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;">
            <?php echo $login_message; ?>
        </div>
    <?php endif; ?>
    <form method="POST">
        <div class="input-box">
            <input type="text" name="username_email" placeholder="Username or Email" required 
                   value="<?php echo isset($_POST['username_email']) ? htmlspecialchars($_POST['username_email']) : ''; ?>">
        </div>
        <div class="input-box" style="position: relative;">
            <input type="password" name="password" id="password" placeholder="Password" required />
            
            <span id="togglePassword" class="fa fa-eye"
                style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; cursor: pointer;">
            </span>
        </div>
        <div class="input-box button">
            <input type="submit" value="Login" <?php echo ($lock_seconds_remaining > 0) ? 'disabled style="background: #ccc;"' : ''; ?> />
        </div>
        <div class="text"><h3><a href="<?php echo BASE_URL; ?>/modules/auth/forgot_password.php">Forgot Password?</a></h3></div>
        <div class="text"><h3><a href="<?php echo BASE_URL; ?>/index.php">Sign Up Now</a></h3></div>
    </form>
</div>
</body>
</html>