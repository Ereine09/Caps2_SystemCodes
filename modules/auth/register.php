<?php
// Show all errors (for debugging)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get database connection
require_once __DIR__ . '/../../app/config/config.php';

$success_message = ""; 
$error_message = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password_raw = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_input = $_POST['role'] ?? 'staff'; // Kukuha ng input mula sa dropdown
    $terms = isset($_POST['terms']) && $_POST['terms'] === 'on';

    // Password validation rules
    $passwordErrors = [];
    if (strlen($password_raw) < 8) $passwordErrors[] = 'at least 8 characters';
    if (!preg_match('/[A-Z]/', $password_raw)) $passwordErrors[] = 'one uppercase letter';
    if (!preg_match('/[a-z]/', $password_raw)) $passwordErrors[] = 'one lowercase letter';
    if (!preg_match('/[0-9]/', $password_raw)) $passwordErrors[] = 'one number';
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\"\\|,.<>\/?]/', $password_raw)) $passwordErrors[] = 'one special character';

    // Validation checks
    if ($username === '' || $first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
        $error_message = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (!in_array($role_input, ['admin', 'staff'])) {
        $error_message = 'Invalid role selected.';
    } elseif (!$terms) {
        $error_message = 'You must accept Terms & Conditions to register.';
    } elseif ($password_raw !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } elseif (count($passwordErrors) > 0) {
        $error_message = 'Password is invalid. It must contain: ' . implode(', ', $passwordErrors) . '.';
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
                $error_message = "Error: " . $stmt->error;
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
    <meta charset="UTF-8" />
    <title>Registration</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
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
            color: #666;
            z-index: 10;
        }
        /* Hide the default browser password reveal button */
        input::-ms-reveal, input::-ms-clear {
            display: none;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <h2>Registration</h2>
    <?php if ($success_message != ""): ?>
        <div class="text" style="color: green; text-align:center; margin-bottom: 15px;"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if ($error_message != ""): ?>
        <div class="text" style="color: #e74c3c; text-align:center; margin-bottom: 15px; font-weight: 600;"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-box">
            <input type="text" name="username" placeholder="Username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="first_name" placeholder="First Name" required value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="last_name" placeholder="Last Name" required value="<?php echo isset($last_name) ? htmlspecialchars($last_name) : ''; ?>" />
        </div>
        <div class="input-box">
            <input type="text" name="email" placeholder="Email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" />
        </div>
        <div class="input-box password-container">
            <input id="password" type="password" name="password" placeholder="Password" required />
            <span id="togglePassword" class="fa fa-eye toggle"></span>
            <div id="passwordHelp" class="password-rules">
                <p id="rule-length">❌ At least 8 characters</p>
                <p id="rule-upper">❌ One uppercase letter</p>
                <p id="rule-lower">❌ One lowercase letter</p>
                <p id="rule-number">❌ One number</p>
                <p id="rule-special">❌ One special character</p>
            </div>
        </div>
        <div class="input-box password-container">
            <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm Password" required />
            <span id="toggleConfirmPassword" class="fa fa-eye toggle"></span>
            <small id="confirmMessage">❌ Passwords do not match</small>
        </div>

        <div class="input-box" style="margin: 20px 0;">
            <label style="display: block; margin-bottom: 5px; font-size: 14px; color: #444; font-weight: 500;">Account Type:</label>
            <select name="role" required style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc;">
                <option value="staff">Staff Member</option>
                <option value="admin">Administrator</option>
            </select>
        </div>

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
const passwordHelp = document.getElementById('passwordHelp');
const confirmMessage = document.getElementById('confirmMessage');
const submitBtn = document.getElementById('submitBtn');

const ruleLength = document.getElementById('rule-length');
const ruleUpper = document.getElementById('rule-upper');
const ruleLower = document.getElementById('rule-lower');
const ruleNumber = document.getElementById('rule-number');
const ruleSpecial = document.getElementById('rule-special');

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

passwordInput.addEventListener('focus', () => passwordHelp.classList.add('show'));
passwordInput.addEventListener('blur', () => { if (passwordInput.value === '') passwordHelp.classList.remove('show'); });

function validatePassword() {
    const value = passwordInput.value;
    let valid = true;
    
    const check = (bool, el, text) => {
        if (bool) { el.classList.add('valid'); el.innerHTML = "✅ " + text; }
        else { el.classList.remove('valid'); el.innerHTML = "❌ " + text; valid = false; }
    };

    check(value.length >= 8, ruleLength, "At least 8 characters");
    check(/[A-Z]/.test(value), ruleUpper, "One uppercase letter");
    check(/[a-z]/.test(value), ruleLower, "One lowercase letter");
    check(/[0-9]/.test(value), ruleNumber, "One number");
    check(/[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]/.test(value), ruleSpecial, "One special character");

    return valid;
}

function checkPasswordMatch() {
    const password = passwordInput.value;
    const confirm = confirmInput.value;
    if (confirm === "") { confirmMessage.style.display = "none"; return false; }
    confirmMessage.style.display = "block";
    if (password === confirm) {
        confirmMessage.classList.add('valid');
        confirmMessage.innerHTML = "✅ Passwords match";
        return true;
    } else {
        confirmMessage.classList.remove('valid');
        confirmMessage.innerHTML = "❌ Passwords do not match";
        return false;
    }
}

function validateForm() {
    submitBtn.disabled = !(validatePassword() && checkPasswordMatch());
}

passwordInput.addEventListener('input', validateForm);
confirmInput.addEventListener('input', validateForm);
submitBtn.disabled = true;
</script>
</body>
</html>