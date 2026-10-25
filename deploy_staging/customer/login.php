<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/customer_reset_schema_helper.php';

customer_redirect_if_logged_in();
ensure_customer_reset_schema($conn);
ensure_customer_lockout_schema($conn);

$login_error = '';
$lock_seconds_remaining = 0;
const CUSTOMER_LOGIN_MAX_ATTEMPTS = 3;
const CUSTOMER_LOGIN_LOCK_SECONDS = 180; // 3 minutes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $login_error = 'Please provide both email and password.';
    } else {
        $account = get_customer_account_by_email($email);

        if (!$account) {
            // Generic message: don't reveal whether the email exists.
            $login_error = 'Invalid email or password.';
        } else {
            $now = time();
            $lock_until_ts = $account['lock_until'] ? strtotime($account['lock_until']) : 0;

            if ($lock_until_ts && $lock_until_ts > $now) {
                $lock_seconds_remaining = $lock_until_ts - $now;
                $login_error = "Too many failed attempts. Account locked. Try again in <span id='timer'></span>.";
            } elseif (password_verify($password, $account['password'])) {
                $reset = $conn->prepare('UPDATE customer_login_credentials SET login_attempts = 0, lock_until = NULL WHERE customer_id = ?');
                $reset->bind_param('i', $account['customer_id']);
                $reset->execute();

                customer_login($account);

                // Log the customer login activity
                $customer_id = (int)($account['customer_id'] ?? 0);
                $log_action = "Customer Login";
                $log_details = "Customer '{$account['customer_name']}' (ID: {$customer_id}) logged in.";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (customer_id, action, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param('iss', $customer_id, $log_action, $log_details);
                $log_stmt->execute();

                header('Location: ' . BASE_URL . '/customer/dashboard.php');
                exit();
            } else {
                // A lock that has already expired resets the counter before counting this attempt.
                $current_attempts = ($lock_until_ts > 0 && $lock_until_ts <= $now) ? 0 : (int)$account['login_attempts'];
                $new_attempts = $current_attempts + 1;

                if ($new_attempts >= CUSTOMER_LOGIN_MAX_ATTEMPTS) {
                    $lock_seconds_remaining = CUSTOMER_LOGIN_LOCK_SECONDS;
                    $lock_until_time = date('Y-m-d H:i:s', strtotime('+' . CUSTOMER_LOGIN_LOCK_SECONDS . ' seconds'));
                    $login_error = "Too many failed attempts. Account locked. Try again in <span id='timer'></span>.";
                    $update = $conn->prepare('UPDATE customer_login_credentials SET login_attempts = ?, lock_until = ? WHERE customer_id = ?');
                    $update->bind_param('isi', $new_attempts, $lock_until_time, $account['customer_id']);
                } else {
                    $remaining = CUSTOMER_LOGIN_MAX_ATTEMPTS - $new_attempts;
                    $login_error = "Invalid email or password. $remaining attempt(s) left.";
                    $update = $conn->prepare('UPDATE customer_login_credentials SET login_attempts = ?, lock_until = NULL WHERE customer_id = ?');
                    $update->bind_param('ii', $new_attempts, $account['customer_id']);
                }
                $update->execute();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Customer Login | Darius Poulty Shop</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css"/>
    <style>
        /* Hide default browser password reveal buttons */
        input::-ms-reveal,
        input::-ms-clear {
            display: none;
        }
        .password-container input {
            padding-right: 48px;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 2;
            padding: 6px;
            background: transparent;
            color: var(--muted);
            border: 0;
            cursor: pointer;
            appearance: none;
            transition: none;
        }
        .password-toggle:hover,
        .password-toggle:focus {
            transform: translateY(-50%);
            box-shadow: none;
        }
        .input-box input,
        .input-box.button input {
            transition: none;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <h2>Customer Login</h2>
    <?php if ($login_error !== ''): ?>
        <div class="text" style="color: #e74c3c; margin-bottom: 15px; font-weight: 600; text-align: center;">
            <?php echo $login_error; // Server-generated only (no user input interpolated); may contain a <span id="timer"> for the lockout countdown. ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box password-container">
            <input type="password" name="password" placeholder="Password" id="password" required <?php echo $lock_seconds_remaining > 0 ? 'disabled' : ''; ?> />
            <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <div class="input-box button">
            <input type="submit" value="Login" <?php echo $lock_seconds_remaining > 0 ? 'disabled style="background: #ccc;"' : ''; ?> />
        </div>
        <div class="text"><h3><a href="<?php echo BASE_URL; ?>/customer/forgot_password.php">Forgot Password?</a></h3></div>
        <div class="text"><p style="font-size: 0.8rem; color: #64748b;">Can't access your email or phone? Visit Darius Poultry Supplies in person and our Admin can help.</p></div>
        <div class="text">
            <h3>New to Darius Poulty Shop? <a href="<?php echo BASE_URL; ?>/customer/register.php">Create account</a></h3>
        </div>
    </form>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const password = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');
        if (!password || !toggle) return;
        const icon = toggle.querySelector('svg, i');

        toggle.addEventListener('click', function () {
            const isHidden = password.type === 'password';
            password.type = isHidden ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
            }
            toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', String(isHidden));
        });
    });

    // Lockout countdown: ticks the "Try again in mm:ss" span, then reloads so the
    // server can re-check (the lock has usually already expired by then).
    function startLockoutTimer(duration, display) {
        var timer = duration, minutes, seconds;
        var interval = setInterval(function () {
            minutes = parseInt(timer / 60, 10);
            seconds = parseInt(timer % 60, 10);
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            display.textContent = minutes + ':' + seconds;
            if (--timer < 0) {
                clearInterval(interval);
                location.reload();
            }
        }, 1000);
    }
    window.addEventListener('DOMContentLoaded', function () {
        var secondsRemaining = <?php echo (int)$lock_seconds_remaining; ?>;
        var display = document.getElementById('timer');
        if (secondsRemaining > 0 && display) {
            startLockoutTimer(secondsRemaining, display);
        }
    });
</script>
</body>
</html>