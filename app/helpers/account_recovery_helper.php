<?php
/**
 * Account recovery for every kind of account, in one place:
 *   - `users` rows (admin, staff, rider)   -> Staff Management > "Recover Account"
 *   - customers (customer_login_credentials) -> Customer Management > "Account Recovery" tab
 *   - the rider's own "Forgot password" page (rider/forgot_password.php)
 *
 * Three actions: email a one-time reset link, set a temporary password (for someone who
 * can't reach their email), or just unlock (too many wrong passwords).
 *
 * Reset links carry a random token; only its SHA-256 hash is stored in reset_token, so a
 * leaked database row can't be used as a link. The reset pages accept the hash (and still
 * the raw token of links issued by the older self-service forms).
 *
 * Changing a password also signs the account out everywhere else: users.password_reset_at
 * (checked in verifyJWT()) and customer_login_credentials.password_changed_at
 * (customer_token_is_stale()).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/customer_reset_schema_helper.php';

const ACCOUNT_RESET_TTL_MINUTES = 15;

/* ------------------------------------------------------------------ shared */

/** New link token: [token for the URL, hash to store]. */
function recovery_new_token(): array
{
    $token = bin2hex(random_bytes(32));
    return [$token, hash('sha256', $token)];
}

/** True while a link issued less than a minute ago is still fresh (don't send another yet). */
function recovery_recently_sent(?string $reset_expiry): bool
{
    return !empty($reset_expiry)
        && strtotime($reset_expiry) > time() + (ACCOUNT_RESET_TTL_MINUTES * 60) - 60;
}

function recovery_site_url(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . $path;
}

/** Sends the reset-link email. Returns true when the mail server accepted it. */
function recovery_send_email(string $to, string $name, string $account_label, string $link, bool $by_admin): bool
{
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $intro = $by_admin
        ? 'The shop admin started a password reset for your account'
        : 'We received a request to reset the password of your account';
    try {
        $mail = notifications_mailer();
        $mail->addAddress($to, $name);
        $mail->Subject = SYSTEM_NAME . ' - Reset your password';
        $mail->Body = "
            <div style='font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; border: 1px solid #e0e0e0; padding: 30px; border-radius: 8px; max-width: 600px; color: #333;'>
                <div style='text-align: center; margin-bottom: 25px;'>
                    <img src='" . $e(SYSTEM_LOGO_URL) . "' alt='" . $e(SYSTEM_NAME) . " Logo' style='max-height: 80px; width: auto;'>
                </div>
                <h2 style='color: #4a3e94; margin-bottom: 20px; border-bottom: 2px solid #f4f4f4; padding-bottom: 10px;'>Reset your password</h2>
                <p>Hi " . $e($name) . ",</p>
                <p>" . $intro . " (<strong>" . $e($account_label) . "</strong>). Tap the button below to choose a new one:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . $e($link) . "' style='background-color: #4a3e94; color: #ffffff; padding: 14px 25px; text-decoration: none; font-weight: bold; border-radius: 4px; display: inline-block; font-size: 16px;'>Reset my password</a>
                </div>
                <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #4a3e94; margin-top: 25px;'>
                    <p style='margin: 0; font-size: 13px; color: #666;'>
                        <strong>Important:</strong><br>
                        &bull; This link works once and expires in <strong>" . ACCOUNT_RESET_TTL_MINUTES . " minutes</strong>.<br>
                        &bull; If you didn't expect this, ignore this email - your password stays the same.
                    </p>
                </div>
                <p style='font-size: 12px; color: #999; text-align: center; margin-top: 20px;'>&copy; " . date('Y') . ' ' . $e(SYSTEM_NAME) . ".</p>
            </div>";
        $mail->AltBody = "Reset your password: $link\nThis link expires in " . ACCOUNT_RESET_TTL_MINUTES . ' minutes.';
        $mail->send();
        return true;
    } catch (Throwable $ex) {
        error_log('Password reset email delivery failed.');
        return false;
    }
}

/** Sends a best-effort security notice after a password was successfully changed. */
function recovery_send_password_changed_notice(string $to, string $name, string $role): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $role_label = match (strtolower(trim($role))) {
        'customer' => 'customer',
        'rider' => 'rider',
        'admin' => 'admin',
        'staff' => 'staff',
        default => 'account',
    };
    $greeting = trim($name) !== '' ? 'Hi ' . trim($name) . ',' : 'Hello,';
    $result = notifications_send_email(
        $to,
        'Password changed',
        $greeting . "\n\nThe password for your {$role_label} account was changed successfully. If you did not make this change, contact the shop immediately.",
        true
    );

    if (empty($result['success'])) {
        error_log('Password-change security email delivery failed.');
    }
}

/* -------------------------------------------------- users (admin/staff/rider) */

/** A `users` account by id, optionally only if it has $role. */
function user_recovery_find(mysqli $conn, int $user_id, ?string $role = null): ?array
{
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, LOWER(TRIM(role)) AS role, reset_expiry, lock_until FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || ($role !== null && $row['role'] !== $role)) {
        return null;
    }
    return $row;
}

/** Emails a reset link. Riders reset on the Rider Portal page, admin/staff on the staff one. */
function user_recovery_send_link(mysqli $conn, array $user, bool $by_admin = false): bool
{
    [$token, $hash] = recovery_new_token();
    $expiry = date('Y-m-d H:i:s', time() + ACCOUNT_RESET_TTL_MINUTES * 60);
    $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
    $upd->bind_param('ssi', $hash, $expiry, $user['id']);
    $upd->execute();
    $upd->close();

    $page = $user['role'] === 'rider' ? '/rider/reset_password.php' : '/modules/auth/reset_password.php';
    $name = trim((string) $user['first_name']) !== '' ? $user['first_name'] : $user['username'];
    $sent = recovery_send_email((string) $user['email'], $name, (string) $user['username'], recovery_site_url($page . '?token=' . $token), $by_admin);
    if (!$sent) {
        $clear = $conn->prepare('UPDATE users SET reset_token = NULL, reset_expiry = NULL WHERE id = ? AND reset_token = ?');
        $clear->bind_param('is', $user['id'], $hash);
        $clear->execute();
        $clear->close();
    }
    return $sent;
}

/** Temporary password: also unlocks, cancels a pending link and signs out every device. */
function user_recovery_set_password(mysqli $conn, int $user_id, string $password): bool
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = date('Y-m-d H:i:s');
    $upd = $conn->prepare("UPDATE users SET password = ?, password_reset_at = ?, reset_token = NULL, reset_expiry = NULL, login_attempts = 0, lock_until = NULL WHERE id = ?");
    $upd->bind_param('ssi', $hash, $now, $user_id);
    $upd->execute();
    $ok = $upd->affected_rows === 1;
    $upd->close();
    return $ok;
}

/** Clears a login lockout without touching the password. */
function user_recovery_unlock(mysqli $conn, int $user_id): void
{
    $upd = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?");
    $upd->bind_param('i', $user_id);
    $upd->execute();
    $upd->close();
}

/* --------------------------------------------------------------- customers */

/** A customer with an online account (login credentials), or null. */
function customer_recovery_find(mysqli $conn, int $customer_id): ?array
{
    ensure_customer_reset_schema($conn);
    ensure_customer_lockout_schema($conn);
    ensure_customer_password_meta_schema($conn);
    $stmt = $conn->prepare(
        "SELECT c.id, c.name, c.email, l.reset_expiry, l.lock_until, l.login_attempts
         FROM customers c JOIN customer_login_credentials l ON l.customer_id = c.id
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function customer_recovery_send_link(mysqli $conn, array $customer, bool $by_admin = false): bool
{
    [$token, $hash] = recovery_new_token();
    $expiry = date('Y-m-d H:i:s', time() + ACCOUNT_RESET_TTL_MINUTES * 60);
    $upd = $conn->prepare("UPDATE customer_login_credentials SET reset_token = ?, reset_expiry = ? WHERE customer_id = ?");
    $upd->bind_param('ssi', $hash, $expiry, $customer['id']);
    $upd->execute();
    $upd->close();
    $name = trim((string) $customer['name']) !== '' ? $customer['name'] : 'there';
    $sent = recovery_send_email((string) $customer['email'], $name, (string) $customer['email'], recovery_site_url('/customer/reset_password.php?token=' . $token), $by_admin);
    if (!$sent) {
        $clear = $conn->prepare('UPDATE customer_login_credentials SET reset_token = NULL, reset_expiry = NULL WHERE customer_id = ? AND reset_token = ?');
        $clear->bind_param('is', $customer['id'], $hash);
        $clear->execute();
        $clear->close();
    }
    return $sent;
}

function customer_recovery_unlock(mysqli $conn, int $customer_id): void
{
    $upd = $conn->prepare("UPDATE customer_login_credentials SET login_attempts = 0, lock_until = NULL WHERE customer_id = ?");
    $upd->bind_param('i', $customer_id);
    $upd->execute();
    $upd->close();
}

/** Cancels a pending (unused) reset link. */
function customer_recovery_cancel_link(mysqli $conn, int $customer_id): void
{
    $upd = $conn->prepare("UPDATE customer_login_credentials SET reset_token = NULL, reset_expiry = NULL WHERE customer_id = ?");
    $upd->bind_param('i', $customer_id);
    $upd->execute();
    $upd->close();
}
