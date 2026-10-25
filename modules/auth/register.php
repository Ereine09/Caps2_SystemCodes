<?php
// Get database connection
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

// Public sign-up is closed: Admin and Staff accounts are created in Staff Management
// (modules/admin/staff_management.php). This page only works while the system has NO admin
// account yet (a brand-new install), so nobody can create an admin for themselves here.
$admin_res = $conn->query("SELECT 1 FROM users WHERE LOWER(TRIM(role)) = 'admin' LIMIT 1");
if (!$admin_res || $admin_res->num_rows > 0) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php', true, 302);
    exit();
}

$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password_raw = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_input = $_POST['role'] ?? 'staff';
    $terms = isset($_POST['terms']) && $_POST['terms'] === 'on';

    // Shared account rules (app/helpers/auth_rules_helper.php)
    $rule_error = auth_username_error($username)
        ?? auth_name_error($first_name, 'First name')
        ?? auth_name_error($last_name, 'Last name')
        ?? auth_email_error($email)
        ?? auth_password_error((string) $password_raw, [$username, $first_name, $last_name, $email]);

    // Validation checks
    if ($username === '' || $first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
        $error_message = 'All fields are required.';
    } elseif ($rule_error !== null) {
        $error_message = $rule_error;
    } elseif (!in_array($role_input, ['admin', 'staff'], true)) {
        $error_message = 'Invalid role selected.';
    } elseif (!$terms) {
        $error_message = 'You must accept Terms & Conditions to register.';
    } elseif ($password_raw !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } else {

        // Check for existing username or email
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $error_message = 'Username or email already exists.';
        } else {
            $password = password_hash($password_raw, PASSWORD_DEFAULT);

            // UPDATED: Kasama na ang 'role' column sa INSERT
            $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $username, $first_name, $last_name, $email, $password, $role_input);

            if ($stmt->execute()) {
                $success_message = "Registration successful as " . ucfirst($role_input) . "! You can now <a href='" . BASE_URL . "/modules/auth/login.php'>Login</a>.";
                $username = $first_name = $last_name = $email = '';
            } else {
                error_log('User registration failed: ' . $stmt->error);
                $error_message = "Registration could not be completed. Please try again later.";
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Registration</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.55)),
                        url('<?php echo BASE_URL; ?>/assets/img/dpsbg.jpg') center / cover no-repeat fixed;
        }
        .wrapper {
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        }
        .password-container {
            position: relative;
        }
        /* Perfectly centers the eye toggle vertically relative to the input height */
        .password-container .toggle {
            position: absolute;
            right: 15px;
            top: 26px; /* Adjust this value (e.g., 22px to 28px) to match the vertical center of your input */
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--dk-muted, #666);
            z-index: 10;
        }
        /* Hide the default browser password reveal button */
        input::-ms-reveal, input::-ms-clear {
            display: none;
        }
    </style>
</head>
<body>
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
<div class="wrapper">
    <h2>Registration</h2>
    <?php if ($success_message != ""): ?>
        <div class="text" style="color: green; text-align:center; margin-bottom: 15px;"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if ($error_message != ""): ?>
        <div class="text" style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="input-box">
            <input type="text" name="username" placeholder="Username (4–30 letters, numbers, . or _)" required minlength="4" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._]*" title="4–30 characters: letters, numbers, dots or underscores" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="first_name" placeholder="First Name" required maxlength="50" value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="last_name" placeholder="Last Name" required maxlength="50" value="<?php echo isset($last_name) ? htmlspecialchars($last_name) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="email" placeholder="Email" required maxlength="100" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" />
        </div>
        <div class="input-box password-container">
            <input id="password" type="password" name="password" placeholder="Password" required minlength="8" maxlength="64" data-password-rules data-avoid="username,first_name,last_name,email" data-confirm="confirm_password" title="<?php echo htmlspecialchars(auth_password_rules_text()); ?>" />
            <span id="togglePassword" class="fa fa-eye toggle"></span>
        </div>
        <div class="input-box password-container">
            <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm Password" required maxlength="64" />
            <span id="toggleConfirmPassword" class="fa fa-eye toggle"></span>
        </div>

        <?php if ($creator_is_admin): ?>
        <div class="input-box" style="margin: 20px 0;">
            <label style="display: block; margin-bottom: 5px; font-size: 14px; color: #444; font-weight: 500;">Account Type:</label>
            <select name="role" required style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc;">
                <option value="staff">Staff Member</option>
                <option value="admin">Administrator</option>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="role" value="staff" />
        <?php endif; ?>

        <div class="policy" id="termsBox">
            <input type="checkbox" name="terms" <?php echo isset($terms) && $terms ? 'checked' : ''; ?> />
            <label style="margin-left: 8px;"> I accept all the terms & condition</label>
        </div>
        <div class="input-box button">
            <input type="submit" id="submitBtn" value="Sign Up" />
        </div>
        <div class="text">
            <h3>Already have an account? <a href="<?php echo BASE_URL; ?>/modules/auth/login.php">Login</a></h3>
        </div>
    </form>
</div>

<script>
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const submitBtn = document.getElementById('submitBtn');

document.addEventListener("DOMContentLoaded", function () {
    // Logic for Main Password Toggle
    const password = document.getElementById("password");
    const toggle = document.getElementById("togglePassword");

    if (toggle) {
        toggle.addEventListener("click", function () {
            const type = password.getAttribute("type") === "password" ? "text" : "password";
            password.setAttribute("type", type);
            toggle.classList.toggle("fa-eye");
            toggle.classList.toggle("fa-eye-slash");
        });
    }

    // Logic for Confirm Password Toggle
    const confirmPassword = document.getElementById("confirm_password");
    const toggleConfirm = document.getElementById("toggleConfirmPassword");

    if (toggleConfirm) {
        toggleConfirm.addEventListener("click", function () {
            const type = confirmPassword.getAttribute("type") === "password" ? "text" : "password";
            confirmPassword.setAttribute("type", type);
            toggleConfirm.classList.toggle("fa-eye");
            toggleConfirm.classList.toggle("fa-eye-slash");
        });
    }
});

// The ✗/✓ checklist is assets/js/password-rules.js; it reports when every rule (and the
// confirm box) is met, and Register stays disabled until then.
passwordInput.addEventListener('pwrules', function (e) { submitBtn.disabled = !e.detail.ok; });
submitBtn.disabled = true;
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/password-rules.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/password-rules.js') ?: time(); ?>" defer></script>
</body>
</html>