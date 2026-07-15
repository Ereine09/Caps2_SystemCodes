<?php
require_once __DIR__ . '/includes/auth.php';
customer_redirect_if_logged_in();

$register_error = '';
$register_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || $address === '' || $password === '' || $confirm_password === '') {
        $register_error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = 'Please enter a valid email address.';
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
            if ($account) {
                customer_login($account);
                header('Location: ' . BASE_URL . '/customer/dashboard.php');
                exit();
            }
        }

        $register_error = 'Unable to create your account. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register | Darius Poultry Supply</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css" />
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
    <h2>Create Your Customer Account</h2>
    <?php if ($register_error): ?>
        <div class="text" style="color: #e74c3c; margin-bottom: 15px; text-align: center; font-weight: 600;">
            <?php echo htmlspecialchars($register_error); ?>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <div class="input-box">
            <input type="text" name="name" placeholder="Full Name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="phone" placeholder="Phone Number" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="address" placeholder="Delivery Address" required value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" />
        </div>
        <div class="input-box password-container">
            <input type="password" name="password" placeholder="Password" id="password" required />
            <span id="togglePassword" class="fa fa-eye toggle"></span>
        </div>
        <div class="input-box password-container">
            <input type="password" name="confirm_password" placeholder="Confirm Password" id="confirm_password" required />
            <span id="toggleConfirmPassword" class="fa fa-eye toggle"></span>
        </div>
        <div class="input-box button">
            <input type="submit" value="Create Account" />
        </div>
        <div class="text">
            <h3>Already have an account? <a href="<?php echo BASE_URL; ?>/customer/login.php">Login now</a></h3>
        </div>
    </form>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js"></script>
<script>
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const togglePassword = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');

    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            togglePassword.classList.toggle('fa-eye');
            togglePassword.classList.toggle('fa-eye-slash');
        });
    }

    if (toggleConfirmPassword) {
        toggleConfirmPassword.addEventListener('click', function () {
            const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPassword.setAttribute('type', type);
            toggleConfirmPassword.classList.toggle('fa-eye');
            toggleConfirmPassword.classList.toggle('fa-eye-slash');
        });
    }
</script>
</body>
</html>
