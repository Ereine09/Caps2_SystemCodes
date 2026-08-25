<?php
require_once __DIR__ . '/includes/auth.php';

customer_redirect_if_logged_in();

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $login_error = 'Please provide both email and password.';
    } else {
        $account = get_customer_account_by_email($email);
        if (!$account || !password_verify($password, $account['password'])) {
            $login_error = 'Invalid email or password.';
        } else {
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
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Login | Darius Poulty Shop</title>
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
            color: #6b7280;
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
            <?php echo htmlspecialchars($login_error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box password-container">
            <input type="password" name="password" placeholder="Password" id="password" required />
            <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <div class="input-box button">
            <input type="submit" value="Login" />
        </div>
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
</script>
</body>
</html>