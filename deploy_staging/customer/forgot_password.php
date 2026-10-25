<?php
date_default_timezone_set('Asia/Manila');

require __DIR__ . '/../PHPMailer/Exception.php';
require __DIR__ . '/../PHPMailer/PHPMailer.php';
require __DIR__ . '/../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/customer_reset_schema_helper.php';

customer_redirect_if_logged_in();
ensure_customer_reset_schema($conn);

$message = "Enter the email on your account and we'll send you a link to reset your password.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<span style='color:#e74c3c;'>Invalid email format.</span>";
    } else {
        $customer = get_customer_by_email($email);

        if (!$customer) {
            $message = "<span style='color:#e74c3c;'>Email not found in our records.</span>";
        } else {
            $token = bin2hex(random_bytes(50));
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $stmt = $conn->prepare("UPDATE customer_login_credentials SET reset_token = ?, reset_expiry = ? WHERE customer_id = ?");
            $stmt->bind_param('ssi', $token, $expiry, $customer['id']);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $resetLink = "$protocol://$host" . BASE_URL . "/customer/reset_password.php?token=$token";

                $mail = new PHPMailer(true);
                $mail->SMTPDebug = 0;

                try {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = SMTP_AUTH;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = SMTP_PORT;

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($email, $customer['name'] ?? '');

                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request';
                    $logo_src = SYSTEM_LOGO_URL;

                    $mail->Body = "
                        <div style='font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; border: 1px solid #e0e0e0; padding: 30px; border-radius: 8px; max-width: 600px; color: #333;'>
                            <div style='text-align: center; margin-bottom: 25px;'>
                                <img src='" . $logo_src . "' alt='" . SYSTEM_NAME . " Logo' style='max-height: 80px; width: auto;'>
                            </div>
                            <h2 style='color: #4a3e94; margin-bottom: 20px; border-bottom: 2px solid #f4f4f4; padding-bottom: 10px;'>Password Reset Request</h2>
                            <p>Hi " . htmlspecialchars($customer['name'] ?? 'there') . ",</p>
                            <p>We received a request to reset the password for your " . SYSTEM_NAME . " account. To proceed, please click the button below:</p>
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='$resetLink' style='background-color: #4a3e94; color: #ffffff; padding: 14px 25px; text-decoration: none; font-weight: bold; border-radius: 4px; display: inline-block; font-size: 16px;'>
                                    Reset Your Password
                                </a>
                            </div>
                            <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #4a3e94; margin-top: 25px;'>
                                <p style='margin: 0; font-size: 13px; color: #666;'>
                                    <strong>Important Security Notice:</strong><br>
                                    • This link is valid for <strong>15 minutes</strong> only.<br>
                                    • If you didn't request this, please ignore this email.<br>
                                    • Not you, or lost access to this email too? Visit " . SYSTEM_NAME . " in person and our Admin can help.
                                </p>
                            </div>
                            <p style='font-size: 12px; color: #999; text-align: center; margin-top: 20px;'>
                                © " . date('Y') . " " . SYSTEM_NAME . ".
                            </p>
                        </div>";

                    $mail->send();
                    $message = "<span style='color:#2ecc71;'>Sent! Please check your email (Inbox or Spam).</span>";
                } catch (Exception $e) {
                    $message = "<span style='color:#e74c3c;'>Error: Mail could not be sent.</span>";
                }
            } else {
                $message = "<span style='color:#e74c3c;'>This account isn't set up for password login. Please contact support.</span>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Forgot Password | Darius Poulty Shop</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css"/>
</head>
<body>
<div class="wrapper">
    <h2>Forgot Password</h2>
    <div class="text" style="margin-bottom: 15px; font-weight: 600; text-align: center;">
        <?php echo $message; ?>
    </div>

    <form method="POST" action="">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>
        <div class="input-box button">
            <input type="submit" value="Send Reset Link" />
        </div>
        <div class="text">
            <p style="font-size: 0.82rem; color: #64748b; margin-top: 10px;">
                Can't access this email or your phone? Visit Darius Poultry Supplies in person and our Admin can help.
            </p>
        </div>
        <div class="text">
            <h3><a href="<?php echo BASE_URL; ?>/customer/login.php">Back to Login</a></h3>
        </div>
    </form>
</div>
</body>
</html>
