<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/auth_rules_helper.php';

active_session_device_hash(true);

if (session_status() === PHP_SESSION_NONE) {
    app_session_start();
}

// Already signed in here -> that account's dashboard. A token of another role left in this
// cookie from before riders had their own cookie is simply cleared (it would loop otherwise).
$token = getJWTFromCookie();
$existing = $token ? verifyJWT($token) : false;
if (is_array($existing)) {
    $existing_role = strtolower(trim((string) ($existing['role'] ?? '')));
    if (in_array($existing_role, ['admin', 'staff'], true)) {
        header("Location: " . BASE_URL . ($existing_role === 'admin' ? "/modules/admin/dashboard.php" : "/modules/staff/dashboard.php"));
        exit();
    }
    clearJWTCookie();
}

$login_message = "";
// Sent back from the 2FA step (verify_otp.php): say why instead of a silent return to this page.
$otp_return = (string) ($_GET['error'] ?? '');
if ($otp_return === 'otp_locked') {
    $login_message = "Too many incorrect codes. For your security, please wait 5 minutes, then log in again to get a new code.";
} elseif ($otp_return === 'otp_session') {
    $login_message = "Your verification step ended. Please log in again to get a new code.";
}
$login_blocked = false;   // another account is signed in on this browser (active_session_helper.php)
$lock_seconds_remaining = 0; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    csrf_require();

    $username_email = trim((string) ($_POST['username_email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Over-long input never reaches the database or password hashing.
    if (!auth_login_input_ok($username_email, $password)) {
        $login_message = "Invalid username or password.";
    } else {

        // 'role' SELECT QUERY
        $stmt = $conn->prepare("SELECT id, username, email, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username_email, $username_email);
        $stmt->execute();
        $result = $stmt->get_result();

        $user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
        // This form is for the admin area only: admin and staff accounts. Customers and riders
        // have their own login pages; they get the same "invalid" answer as an unknown account.
        if ($user && !in_array(strtolower(trim((string) $user['role'])), ['admin', 'staff'], true)) {
            $user = null;
        }

        if ($user) {
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

                    if (!active_session_can_login((int) $user['id'], strtolower(trim((string) $user['role'])))) {
                        $login_blocked = true;
                    } else {
                        $reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?");
                        $reset->bind_param("i", $user['id']);
                        $reset->execute();

                        if (strtolower(trim($user['role'])) === 'admin' && ADMIN_AUTH_ENABLED) {
                        // --- 2FA OTP GENERATION FOR ADMIN ---
                        // Skipped entirely (falls to the else branch below) when Settings >
                        // Security has admin login turned OFF — that toggle is meant to remove
                        // every login friction point for local dev/demo, OTP included, not just
                        // the "must be logged in at all" check on admin/customers pages.
                        $otp_code = sprintf("%06d", random_int(0, 999999));
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
                            session_regenerate_id(true);
                            $_SESSION['2fa_user'] = [
                                'id' => $user['id'],
                                'username' => $user['username'],
                                'role' => $user['role'],
                                'email' => $user['email'],
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
                            if (!setJWTCookie($token)) {
                                $login_blocked = true;
                            } else {
                                session_regenerate_id(true);
                                if (strtolower(trim($user['role'])) === 'admin') {
                                    header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
                                } else {
                                    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
                                }
                                exit();
                            }
                        }
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Login</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            min-height: 100dvh; /* keeps the card in the visible area on mobile - see assets/css/style.css */
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
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Login</h2>
    <?php if ($login_message != ""): // XSS: $login_message is output directly. ?>
        <div style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;">
            <?php echo $login_message; ?>
        </div>
    <?php endif; ?>
    <?php if ($login_blocked) echo active_session_blocked_html(); ?>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="input-box">
            <input type="text" name="username_email" placeholder="Username or Email" required maxlength="100"
                   value="<?php echo isset($_POST['username_email']) ? htmlspecialchars($_POST['username_email']) : ''; ?>">
        </div>
        <div class="input-box" style="position: relative;">
            <input type="password" name="password" id="password" placeholder="Password" required maxlength="128" />
            
            <span id="togglePassword" class="fa fa-eye"
                style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; cursor: pointer;">
            </span>
        </div>
        <div class="input-box button">
            <input type="submit" value="Login" <?php echo ($lock_seconds_remaining > 0) ? 'disabled style="background: #ccc;"' : ''; ?> />
        </div>
        <div class="text"><h3><a href="<?php echo BASE_URL; ?>/modules/auth/forgot_password.php">Forgot Password?</a></h3></div>
        <div class="text"><h3>Staff accounts are created by an administrator.</h3></div>
    </form>
</div>
</body>
</html>