<?php
require_once __DIR__ . '/includes/auth.php';
// The page to return to after signing in (e.g. the product a guest tried to buy) - see customer_next_url().
$next_page = is_string($_REQUEST['next'] ?? null) ? $_REQUEST['next'] : '';
$next_qs = $next_page !== '' ? '?next=' . rawurlencode($next_page) : '';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../app/helpers/registration_limit_helper.php'; // max 2 new accounts per device per 24 h
customer_redirect_if_logged_in();

active_session_device_hash(true); // give this browser its id now, so all its tabs share one (race-safe logins)
$register_error = '';
$register_success = '';
$register_blocked = false;
// Anti-spam: this device already made REG_LIMIT_MAX accounts in the last 24 hours?
$reg_limit = reg_limit_status($conn);
$register_limited = $reg_limit['blocked'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $release_reg_lock = reg_limit_lock($conn);
    $reg_limit = reg_limit_status($conn); // re-checked under the lock (two tabs submitting at once)
    $register_limited = $reg_limit['blocked'];
    if ($register_limited) {
        $register_error = reg_limit_message($reg_limit);
    } elseif ($name === '' || $email === '' || $phone === '' || $address === '' || $password === '' || $confirm_password === '') {
        $register_error = 'Please fill in all required fields.';
    } elseif (($rule_error = auth_name_error($name, 'Full name', AUTH_FULL_NAME_MAX)
            ?? auth_email_error($email)
            ?? auth_phone_error($phone)
            ?? (mb_strlen($address) > 255 ? 'Delivery address must be 255 characters or fewer.' : null)
            ?? auth_password_error((string) $password, [$name, $email])) !== null) {
        $register_error = $rule_error;
    } elseif ($password !== $confirm_password) {
        $register_error = 'Passwords do not match.';
    } elseif (get_customer_by_email($email)) {
        $register_error = 'An account with that email already exists.';
    } else {
        $payload = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'password' => $password,
        ];

        if (create_customer_account($payload)) {
            $account = get_customer_account_by_email($email);
            reg_limit_record($conn, $account ? (int) $account['customer_id'] : null);
            $release_reg_lock();
            if ($account && customer_login($account)) {
                header('Location: ' . customer_next_url($next_page));
                exit();
            }
            if ($account) {
                // Created, but another account is signed in on this browser: no automatic sign-in.
                $register_blocked = true;
            }
        }

        if (!$register_blocked) {
            $register_error = 'Unable to create your account. Please try again.';
        }
    }
    $release_reg_lock();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register | Darius Poultry Supply</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css" />
        <style>
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
        .input-box input,
        .input-box.button input {
            transition: none;
        }
        .text a.auth-back-shop { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 0.85rem; font-style: normal; text-decoration: none; color: #64748b; }
        .text a.auth-back-shop:hover { color: #4f46e5; }
    </style>
    <?php require_once __DIR__ . '/../app/helpers/pwa_helper.php'; echo pwa_head_tags('shop'); // installable "DPS Shop" app ?>
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Create Your Customer Account</h2>
    <?php if ($register_limited && !$register_error): ?>
        <div class="text" style="color: #b45309; margin-bottom: 15px; text-align: center; font-weight: 600;">
            <?php echo htmlspecialchars(reg_limit_message($reg_limit)); ?>
        </div>
    <?php endif; ?>
    <?php if ($register_error): ?>
        <div class="text" style="color: #e74c3c; margin-bottom: 15px; text-align: center; font-weight: 600;">
            <?php echo htmlspecialchars($register_error); ?>
        </div>
    <?php endif; ?>
    <?php if ($register_blocked): ?>
        <div class="text" style="color: var(--success, #15803d); margin-bottom: 10px; font-weight: 600; text-align: center;">Your account was created.</div>
        <?php echo active_session_blocked_html(); ?>
        <p style="text-align:center;margin:0 0 12px;"><a href="<?php echo BASE_URL; ?>/customer/login.php">Go to login</a></p>
    <?php endif; ?>
    <form method="POST" action=""<?php echo ($register_blocked || $register_limited) ? ' hidden' : ''; ?>>
        <?php echo csrf_field(); ?>
        <?php if ($next_page !== ''): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($next_page, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <div class="input-box">
            <input type="text" name="name" placeholder="Full Name" required maxlength="100" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required maxlength="100" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="tel" name="phone" placeholder="Phone Number (e.g. 09171234567)" required maxlength="11" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="address" placeholder="Delivery Address" required maxlength="255" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" />
        </div>
        <div class="input-box password-container">
            <input type="password" name="password" placeholder="Password (8+ with A-Z, a-z, 0-9, symbol)" id="password" required minlength="8" maxlength="64" data-password-rules data-avoid="name,email" data-confirm="confirm_password" title="<?php echo htmlspecialchars(auth_password_rules_text()); ?>" />
            <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <div class="input-box password-container">
            <input type="password" name="confirm_password" placeholder="Confirm Password" id="confirm_password" required maxlength="64" />
            <button type="button" id="toggleConfirmPassword" class="password-toggle" aria-label="Show confirm password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <div class="input-box button">
            <input type="submit" value="Create Account" />
        </div>
        <div class="text">
            <h3>Already have an account? <a href="<?php echo BASE_URL; ?>/customer/login.php<?php echo htmlspecialchars($next_qs); ?>">Login now</a></h3>
        </div>
        <div class="text"><a class="auth-back-shop" href="<?php echo BASE_URL; ?>/customer/products.php"><i class="fas fa-arrow-left"></i> Continue browsing the shop</a></div>
    </form>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');

        function attachPasswordToggle(input, toggle, visibleLabel, hiddenLabel) {
            if (!input || !toggle) return;
            const icon = toggle.querySelector('svg, i');
            toggle.addEventListener('click', function () {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                if (icon) {
                    icon.classList.toggle('fa-eye', !isHidden);
                    icon.classList.toggle('fa-eye-slash', isHidden);
                }
                toggle.setAttribute('aria-label', isHidden ? hiddenLabel : visibleLabel);
                toggle.setAttribute('aria-pressed', String(isHidden));
            });
        }

        attachPasswordToggle(password, togglePassword, 'Show password', 'Hide password');
        attachPasswordToggle(confirmPassword, toggleConfirmPassword, 'Show confirm password', 'Hide confirm password');
    });
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/password-rules.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/password-rules.js') ?: time(); ?>" defer></script>
</body>
</html>
