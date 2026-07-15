<?php
// TIMEZONE
date_default_timezone_set('Asia/Manila');

// I-load ang PHPMailer files
require __DIR__ . '/../../PHPMailer/Exception.php';
require __DIR__ . '/../../PHPMailer/PHPMailer.php';
require __DIR__ . '/../../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../app/config/config.php';

// Default message
$message = "Forgot password? Just enter your email and we'll send you a reset link.";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<span style='color:red;'>Invalid email format.</span>";
    } else {
        // 2. GENERATE SECURITY TOKEN & 5-MINUTE EXPIRY
        $token = bin2hex(random_bytes(50));
        // Ginawa nating +5 minutes para hindi masyadong bitin sa user
        $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        // Update database - Siguraduhin na 'reset_expiry' ang column name mo
        $stmt = $conn->prepare("UPDATE users SET reset_token=?, reset_expiry=? WHERE email=?");
        $stmt->bind_param("sss", $token, $expiry, $email);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            
            // --- DYNAMIC URL GENERATION ---
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST']; 
            $resetLink = "$protocol://$host" . BASE_URL . "/modules/auth/reset_password.php?token=$token";

            $mail = new PHPMailer(true);
            $mail->SMTPDebug = 0; 

            try {
                // --- SMTP Configuration ---
                $mail->isSMTP();    
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = SMTP_AUTH;
                $mail->Username   = SMTP_USERNAME;
                $mail->Password   = SMTP_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

                // --- Email Details ---
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($email); 
                
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request';
                $logo_src = SYSTEM_LOGO_URL;
                
                // Professional Email Body with 5-minute notice
                $mail->Body = "
                    <div style='font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; border: 1px solid #e0e0e0; padding: 30px; border-radius: 8px; max-width: 600px; color: #333;'>
                        <div style='text-align: center; margin-bottom: 25px;'>
                            <img src='" . $logo_src . "' alt='" . SYSTEM_NAME . " Logo' style='max-height: 80px; width: auto;'>
                        </div>
                        <h2 style='color: #4a3e94; margin-bottom: 20px; border-bottom: 2px solid #f4f4f4; padding-bottom: 10px;'>Password Reset Request</h2>
                        <p>Dear User,</p>
                        <p>We received a request to reset the password for your account. To proceed, please click the button below:</p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='$resetLink' style='background-color: #4a3e94; color: #ffffff; padding: 14px 25px; text-decoration: none; font-weight: bold; border-radius: 4px; display: inline-block; font-size: 16px;'>
                                Reset Your Password
                            </a>
                        </div>
                        <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #4a3e94; margin-top: 25px;'>
                            <p style='margin: 0; font-size: 13px; color: #666;'>
                                <strong>Important Security Notice:</strong><br>
                                • This link is valid for <strong>5 minutes</strong> only.<br>
                                • If you didn't request this, please ignore this email.
                            </p>
                        </div>
                        <p style='font-size: 12px; color: #999; text-align: center; margin-top: 20px;'>
                            © " . date("Y") . " " . SYSTEM_NAME . " Administration.
                        </p>
                    </div>";

                $mail->send();
                $message = "<span style='color:green;'>Sent! Please check your Gmail (Inbox or Spam).</span>";
            } catch (Exception $e) {
                $message = "<span style='color:red;'>Error: Mail could not be sent.</span>";
            }
        } else {
            $message = "<span style='color:red;'>Email not found in our records.</span>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<div class="wrapper">
    <h2>Forgot Password</h2>
    <p style="font-size: 13px; margin-bottom: 15px; color: #666;">Enter your email to receive a reset link.</p>
    
    <?php if ($message != ""): ?>
        <div style="margin-bottom: 15px; font-weight: 600; text-align: center;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-box">
            <input type="email" name="email" placeholder="Enter your Gmail" required />
        </div>
        <div class="input-box button">
            <input type="submit" value="Send Reset Link" />
        </div>
        <div class="text">
            <h3><a href="<?php echo BASE_URL; ?>/modules/auth/login.php" style="text-decoration: underline; color: #27ae60;">Back to Login</a></h3>
        </div>
    </form>
</div>
</body>
</html>