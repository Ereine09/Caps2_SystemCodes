<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';

// If the user confirmed the logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    $customer = current_customer();
    if ($customer) {
        $customer_id = (int)($customer['id'] ?? 0);
        $log_action = "Customer Logout";
        $log_details = "Customer '{$customer['name']}' (ID: {$customer_id}) logged out.";
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (customer_id, action, details) VALUES (?, ?, ?)");
        $log_stmt->bind_param('iss', $customer_id, $log_action, $log_details);
        $log_stmt->execute();
    }
    customer_logout();
    header('Location: ' . BASE_URL . '/customer/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Logout | Darius Poultry Supply</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css" />
    <style>
        /* Same dialog as the in-portal logout confirm (ui-kit .ui-modal-confirm), shown as a page. */
        .logout-page.ui-modal { position: static; display: flex; min-height: 100vh; min-height: 100dvh; background: none; backdrop-filter: none; }
    </style>
</head>
<body class="customer-portal">
<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/theme.js') ?: time(); ?>"></script>
<div class="ui-modal ui-modal-confirm logout-page">
    <div class="ui-modal-dialog" role="dialog" aria-labelledby="logout-title" aria-describedby="logout-text">
        <div class="ui-confirm-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></div>
        <h1 class="ui-modal-title" id="logout-title">Logout</h1>
        <p class="ui-confirm-text" id="logout-text">Are you sure you want to log out of your account?</p>
        <form method="POST" class="ui-modal-foot">
            <?php echo csrf_field(); ?>
            <button type="button" class="ui-btn" onclick="if (history.length > 1) history.back(); else location.href = '<?php echo BASE_URL; ?>/customer/dashboard.php';">Cancel</button>
            <button type="submit" name="confirm_logout" value="1" class="ui-btn ui-btn-danger"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout</button>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
</body>
</html>