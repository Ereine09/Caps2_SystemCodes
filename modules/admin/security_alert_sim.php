<?php
/**
 * Security Alert Simulation Tool
 * Sends a simulated security warning to test user responsiveness.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

// 1. SECURITY: Admin Only
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$status = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_alert'])) {
    $target_email = filter_var($_POST['target_email'] ?? '', FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid recipient email address.";
    } else {
        // The "Prank" / Simulated Message Content
        $subject = "URGENT: Unauthorized Access Detected";
        $message = "WARNING: Our monitoring system has detected that your account is being spied on by an external entity. An unauthorized hack attempt is currently in progress. For your protection, you need to change your password right away to secure your credentials.";
        
        $result = notifications_send_email($target_email, $subject, $message, true);
        
        if ($result['success']) {
            $status = "success";
            // Log the simulation for audit purposes
            $user_id = (int)$payload['user_id'];
            $details = "Sent simulated security alert to: $target_email";
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Security Simulation', ?)");
            $log->bind_param("is", $user_id, $details);
            $log->execute();
        } else {
            $error = "Simulation Failed: " . $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Simulator - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
</head>
<body>
    <div class="main-content" style="margin-left: 0; padding: 40px; display: flex; justify-content: center;">
        <div class="table-box" style="max-width: 600px; width: 100%;">
            <h1><i class="fas fa-user-secret" style="color: #e74c3c;"></i> Security Alert Simulator</h1>
            <p>Send a simulated security warning to test user awareness.</p>

            <?php if ($status === 'success'): ?>
                <div style="background: #eafaf1; color: #27ae60; padding: 15px; border-radius: 8px; border-left: 5px solid #27ae60; margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i> <strong>Simulation Sent!</strong> The alert has been delivered to the target.
                </div>
            <?php elseif ($error): ?>
                <div style="background: #fff5f5; color: #e74c3c; padding: 15px; border-radius: 8px; border-left: 5px solid #e74c3c; margin-bottom: 20px;">
                    <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="background: #f8f9fa; padding: 25px; border-radius: 10px; border: 1px solid #eee;">
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <label style="font-weight: bold;">Target Email Address:</label>
                    <input type="email" name="target_email" required placeholder="victim@example.com" 
                           style="padding: 12px; border: 1px solid #ddd; border-radius: 6px;">
                    
                    <div style="background: #fff; padding: 15px; border: 1px dashed #e74c3c; border-radius: 6px;">
                        <small style="color: #e74c3c; font-weight: bold;">PREVIEW MESSAGE:</small><br>
                        <p style="font-size: 0.9rem; color: #555; margin-top: 5px;">"Our monitoring system has detected that your account is being spied on... You need to change your password right away..."</p>
                    </div>

                    <button type="submit" name="send_alert" style="background: #e74c3c; color: white; border: none; padding: 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 1rem;">
                        Deploy Simulation
                    </button>
                    <a href="dashboard.php" style="text-align: center; color: #666; text-decoration: none; font-size: 0.9rem;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>