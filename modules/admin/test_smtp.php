<?php
/**
 * SMTP Configuration Test Utility
 * Verifies email connectivity using settings from config.php
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

// 1. SECURITY: Admin Only
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$role = strtolower(trim($payload['role'] ?? 'staff'));
if ($role !== 'admin') {
    die("Access denied. Only administrators can run connectivity tests.");
}

$status = '';
$error = '';
$debug_info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $test_email = filter_var($_POST['test_email'] ?? '', FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid recipient email address.";
    } else {
        try {
            // Initialize mailer using existing helper logic
            $mail = notifications_mailer();
            
            // Enable debug output for the test script to help troubleshoot
            $mail->SMTPDebug = 2; 
            ob_start();
            
            $mail->addAddress($test_email);
            $mail->Subject = "SMTP Connectivity Test - " . date('Y-m-d H:i:s');
            $mail->Body = "
                <h2>SMTP Test Successful</h2>
                <p>Your system is correctly configured to send emails using <b>" . SMTP_HOST . "</b>.</p>
                <p>Timestamp: " . date('r') . "</p>
                <hr>
                <p>Sent from the DPS Loyalty Management System.</p>
            ";
            
            if ($mail->send()) {
                $status = "success";
            } else {
                $error = "Mailer Error: " . $mail->ErrorInfo;
            }
            
            $debug_info = ob_get_clean();
        } catch (Exception $e) {
            $error = "System Error: " . $e->getMessage();
            if (ob_get_length()) ob_end_clean();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMTP Test - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
</head>
<body>
    <div class="main-content" style="margin-left: 0; padding: 40px; display: flex; justify-content: center;">
        <div class="table-box" style="max-width: 750px; width: 100%;">
            <h1><i class="fas fa-paper-plane" style="color: #4a3e94;"></i> SMTP Configuration Test</h1>
            <p style="margin-bottom: 25px;">This utility verifies that the email server settings in <code>app/config/config.php</code> are functional.</p>

            <?php if ($status === 'success'): ?>
                <div style="background: #eafaf1; color: #27ae60; padding: 15px; border-radius: 8px; border-left: 5px solid #27ae60; margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i> <strong>Success!</strong> The test email has been sent. Check your inbox.
                </div>
            <?php elseif ($error): ?>
                <div style="background: #fff5f5; color: #e74c3c; padding: 15px; border-radius: 8px; border-left: 5px solid #e74c3c; margin-bottom: 20px;">
                    <i class="fas fa-times-circle"></i> <strong>Failed!</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="background: #f8f9fa; padding: 25px; border-radius: 10px; border: 1px solid #eee;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <label style="font-weight: 600; color: #555;">Recipient Address for Test Email:</label>
                    <input type="email" name="test_email" required placeholder="e.g. your-email@example.com" 
                           style="padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem;"
                           value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>">
                    
                    <button type="submit" name="send_test" style="background: #4a3e94; color: white; border: none; padding: 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 1rem; transition: background 0.3s;">
                        Run Connectivity Test
                    </button>
                    <a href="dashboard.php" style="text-align: center; color: #666; margin-top: 10px; text-decoration: none; font-size: 0.9rem;"><i class="fas fa-arrow-left"></i> Return to Dashboard</a>
                </div>
            </form>

            <?php if ($debug_info): ?>
                <div style="margin-top: 30px; background: #1e272e; color: #d2dae2; padding: 20px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; font-size: 0.85rem; overflow-x: auto;">
                    <h3 style="margin-top: 0; color: #0fbcf9; border-bottom: 1px solid #3d4e5f; padding-bottom: 10px;">SMTP Debug Conversation</h3>
                    <pre style="white-space: pre-wrap;"><?php echo htmlspecialchars($debug_info); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>