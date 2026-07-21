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
            <span id="togglePassword" class="fa fa-eye toggle"></span>
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
    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');
    if (toggle) {
        toggle.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            toggle.classList.toggle('fa-eye');
            toggle.classList.toggle('fa-eye-slash');
        });
    }
</script>
</body>
</html>