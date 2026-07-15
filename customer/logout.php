<?php
require_once __DIR__ . '/includes/auth.php';

// If the user confirmed the logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    customer_logout();
    header('Location: ' . BASE_URL . '/customer/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Logout | Darius Poultry Supply</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css" />
    <style>
        body {
            background: linear-gradient(rgba(20, 24, 48, 0.35), rgba(74, 62, 148, 0.35)),
                        url('<?php echo BASE_URL; ?>/customer/assets/css/knowledgeville.png') center center / cover no-repeat fixed;
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .wrapper {
            width: 100%;
            max-width: 450px;
            padding: 20px;
            box-sizing: border-box;
        }
        .logout-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(99, 102, 241, 0.12);
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            padding: 26px 28px;
        }
        .logout-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .logout-title h2 {
            margin: 0;
            font-size: 26px;
        }
        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        .confirm-buttons .input-box.button {
            flex: 1;
            margin-top: 0;
        }
        .btn-cancel {
            color: rgba(255, 255, 255, 0.96);
            background: !important;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="logout-card">
        <div class="logout-title">
            <i class="fas fa-sign-out-alt" style="color:#4a3e94;font-size:26px;"></i>
            <h2>Logout</h2>
        </div>

        <p style="text-align: center; margin: 14px 0 0; color: #333; font-weight: 500;">
            Are you sure you want to logout?
        </p>

        <form method="POST" style="margin-top: 10px;">
            <div class="confirm-buttons">
                <div class="input-box button">
                    <input type="submit" name="confirm_logout" value="Yes, Logout" />
                </div>
                <div class="input-box button">
                    <input type="button" class="btn-cancel" onclick="window.history.back()" value="No, Cancel" />
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
</body>
</html>