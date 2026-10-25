<?php
require_once __DIR__ . '/includes/auth.php';
// The page to return to after signing in (e.g. the product a guest tried to buy) - see customer_next_url().
$next_page = is_string($_REQUEST['next'] ?? null) ? $_REQUEST['next'] : '';
$next_qs = $next_page !== '' ? '?next=' . rawurlencode($next_page) : '';
require_once __DIR__ . '/../app/helpers/customer_reset_schema_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/auth_rules_helper.php';

active_session_device_hash(true); // give this browser its id now, so all its tabs share one (race-safe logins)
// Opening the page while signed in -> home. A submitted login is always processed, so a different
// account trying to sign in on this browser gets "Login blocked" instead of the current account's page.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    customer_redirect_if_logged_in();
}
ensure_customer_reset_schema($conn);
ensure_customer_lockout_schema($conn);

$login_error = '';
$login_blocked = false;   // another account is signed in on this browser (active_session_helper.php)
$lock_seconds_remaining = 0;
const CUSTOMER_LOGIN_MAX_ATTEMPTS = 3;
const CUSTOMER_LOGIN_LOCK_SECONDS = 180; // 3 minutes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $login_error = 'Please provide both email and password.';
    } elseif (!auth_login_input_ok($email, (string) $password)) {
        $login_error = 'Invalid email or password.';
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
                $reset = $conn->prepare('UPDATE customer_login_credentials SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE customer_id = ?');
                $reset->bind_param('i', $account['customer_id']);
                $reset->execute();

                if (!customer_login($account)) {
                    // Credentials are right, but another account is signed in on this browser.
                    $login_blocked = true;
                } else {

                // Log the customer login activity
                $customer_id = (int)($account['customer_id'] ?? 0);
                $log_action = "Customer Login";
                $log_details = "Customer '{$account['customer_name']}' (ID: {$customer_id}) logged in.";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (customer_id, action, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param('iss', $customer_id, $log_action, $log_details);
                $log_stmt->execute();

                header('Location: ' . customer_next_url($next_page));
                exit();
                }
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
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Customer Login | Darius Poultry Shop</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css"/>
    <style>
        body.customer-login-page {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid !important; /* ui-kit.css sets body to block on phones (for the portal pages) */
            place-items: center;
            padding: 24px;
        }
        body.customer-login-page > .wrapper {
            width: min(100%, 400px);
            margin: auto;
        }
        @media (max-height: 600px) {
            body.customer-login-page { place-items: start center; }
        }
        /* Hide default browser password reveal buttons */
        input::-ms-reveal,
        input::-ms-clear {
            display: none;
        }
        .password-container input {
            padding-right: 48px;
        }
        .password-container .password-toggle {
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
        .password-container .password-toggle:hover,
        .password-container .password-toggle:focus,
        .password-container .password-toggle:active {
            transform: translateY(-50%);
            background: transparent;
            background-color: transparent;
            border: 0;
            box-shadow: none;
        }
        form .forgot-password { margin-top: 6px; text-align: center; }
        .input-box input,
        .input-box.button input {
            transition: none;
        }
        .text a.auth-back-shop { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 0.85rem; font-style: normal; text-decoration: none; color: #64748b; }
        .text a.auth-back-shop:hover { color: #4f46e5; }
    </style>
    <?php require_once __DIR__ . '/../app/helpers/pwa_helper.php'; echo pwa_head_tags('shop'); // installable "DPS Shop" app ?>
</head>
<body class="customer-login-page">
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Customer Login</h2>
    <?php if ($login_error !== ''): ?>
        <div class="text" style="color: #e74c3c; margin-bottom: 15px; font-weight: 600; text-align: center;">
            <?php echo $login_error; // Server-generated only (no user input interpolated); may contain a <span id="timer"> for the lockout countdown. ?>
        </div>
    <?php endif; ?>

    <?php if ($login_blocked) echo active_session_blocked_html(); ?>
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <?php if ($next_page !== ''): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($next_page, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required maxlength="100" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box password-container">
            <input type="password" name="password" placeholder="Password" id="password" required maxlength="128" <?php echo $lock_seconds_remaining > 0 ? 'disabled' : ''; ?> />
            <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <div class="text forgot-password"><h3><a href="<?php echo BASE_URL; ?>/customer/forgot_password.php">Forgot Password?</a></h3></div>
        <div class="input-box button">
            <input type="submit" value="Login" <?php echo $lock_seconds_remaining > 0 ? 'disabled style="background: #ccc;"' : ''; ?> />
        </div>
        <div class="text"><p style="font-size: 0.8rem; color: #64748b;">Can't access your email or phone? Visit Darius Poultry Supplies in person and our Admin can help.</p></div>
        <div class="text">
            <h3>New to Darius Poultry Shop? <a href="<?php echo BASE_URL; ?>/customer/register.php<?php echo htmlspecialchars($next_qs); ?>">Create account</a></h3>
        </div>
        <div class="text"><a class="auth-back-shop" href="<?php echo BASE_URL; ?>/customer/products.php"><i class="fas fa-arrow-left"></i> Continue browsing the shop</a></div>
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