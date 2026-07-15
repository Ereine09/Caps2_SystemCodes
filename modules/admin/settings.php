<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/settings_helper.php';

// 1. SECURITY: Admin Only
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

settings_ensure_schema($conn);
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $to_save = [
        'SMTP_HOST' => $_POST['smtp_host'],
        'SMTP_PORT' => $_POST['smtp_port'],
        'SMTP_USERNAME' => $_POST['smtp_username'],
        'SMTP_PASSWORD' => $_POST['smtp_password'],
        'SMTP_FROM_EMAIL' => $_POST['smtp_from_email'],
        'SMTP_FROM_NAME' => $_POST['smtp_from_name'],
        'GEMINI_API_KEY' => $_POST['gemini_api_key'],
        'LOYALTY_POINTS_EXPIRY_MONTHS' => $_POST['loyalty_points_expiry_months'],
        'SYSTEM_NAME' => $_POST['system_name'],
        'SYSTEM_LOGO_URL' => $_POST['system_logo_url']
    ];

    foreach ($to_save as $key => $val) {
        settings_update($conn, $key, trim($val));
    }
    $success = "System settings updated successfully. Changes are now live.";
    
    // Log the action
    $user_id = (int)$payload['user_id'];
    $details = "Updated system API and SMTP configurations.";
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'SYSTEM_SETTINGS', ?)");
    $log->bind_param("is", $user_id, $details);
    $log->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
</head>
<body>
    <div class="main-content" style="margin-left: 0; padding: 40px; display: flex; justify-content: center;">
        <div class="table-box" style="max-width: 900px; width: 100%;">
            <h1><i class="fas fa-cogs" style="color: #4a3e94;"></i> System Configuration</h1>
            <p>Manage external API keys and email server credentials.</p>

            <?php if ($success): ?>
                <div style="background: #eafaf1; color: #27ae60; padding: 15px; border-radius: 8px; border-left: 5px solid #27ae60; margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                    <!-- SMTP SECTION -->
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <h3 style="border-bottom: 2px solid #eee; padding-bottom: 10px;"><i class="fas fa-envelope"></i> SMTP Settings</h3>
                        
                        <label>SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo SMTP_HOST; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        
                        <label>SMTP Port</label>
                        <input type="number" name="smtp_port" value="<?php echo SMTP_PORT; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        
                        <label>SMTP Username (Email)</label>
                        <input type="email" name="smtp_username" value="<?php echo SMTP_USERNAME; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        
                        <label>SMTP Password / App Password</label>
                        <input type="password" name="smtp_password" value="<?php echo SMTP_PASSWORD; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>

                    <!-- IDENTITY & AI -->
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <h3 style="border-bottom: 2px solid #eee; padding-bottom: 10px;"><i class="fas fa-id-card"></i> Brand & Identity</h3>

                        <label>System Name</label>
                        <input type="text" name="system_name" value="<?php echo SYSTEM_NAME; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">

                        <label>System Logo URL</label>
                        <input type="text" name="system_logo_url" value="<?php echo $db_settings['SYSTEM_LOGO_URL'] ?? 'assets/img/loyalty_logo.png'; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <p style="font-size: 0.75rem; color: #666;">Use a full URL (http://...) for emails.</p>
                        
                        <label>Sender Email Address</label>
                        <input type="email" name="smtp_from_email" value="<?php echo SMTP_FROM_EMAIL; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        
                        <label>Sender Display Name</label>
                        <input type="text" name="smtp_from_name" value="<?php echo SMTP_FROM_NAME; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">

                        <h3 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 20px;"><i class="fas fa-star"></i> Loyalty Rules</h3>
                        
                        <label>Points Expiry (Months)</label>
                        <input type="number" name="loyalty_points_expiry_months" value="<?php echo LOYALTY_POINTS_EXPIRY_MONTHS; ?>" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">

                        <div style="margin-top: 20px; background: #f0f7ff; padding: 20px; border-radius: 10px; border: 1px solid #d0e7ff;">
                            <label style="font-weight: bold; color: #004085;">Google Gemini API Key</label>
                            <input type="text" name="gemini_api_key" value="<?php echo GEMINI_API_KEY; ?>" required 
                                   style="width: 100%; margin-top: 10px; padding: 12px; border: 1px solid #b8daff; border-radius: 5px; font-family: monospace;">
                            <p style="font-size: 0.8rem; color: #666; margin-top: 10px;">Required for AI Business Insights in Analytics.</p>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 40px; display: flex; gap: 15px;">
                    <button type="submit" name="save_settings" style="background: #4a3e94; color: white; border: none; padding: 15px 30px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 1rem; flex: 2;">
                        Save All Configuration
                    </button>
                    <a href="dashboard.php" style="background: #eee; color: #333; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; text-align: center; flex: 1;">
                        Back
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>