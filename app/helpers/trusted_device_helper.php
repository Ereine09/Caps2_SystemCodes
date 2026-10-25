<?php
/**
 * "Remember this device" for the admin login code (modules/auth/verify_otp.php).
 *
 * After the owner enters a correct code with the box ticked, the browser gets a random token
 * (HttpOnly cookie; only its SHA-256 hash is stored). On the next login that browser's code page
 * fills in the code by itself and sends it, so the owner does not have to open the email.
 * The password check on the login page is unchanged, and the code itself is still verified.
 *
 * A remembered device stops working after TRUSTED_DEVICE_DAYS, when the admin's password changes
 * (the row keeps a fingerprint of the password hash), or when "Forget this device" is used.
 */
require_once __DIR__ . '/jwt_helper.php'; // jwt_cookie_options()

const TRUSTED_DEVICE_COOKIE = 'dps_admin_device';
const TRUSTED_DEVICE_DAYS = 30;

function trusted_device_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_admin_trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        password_sig CHAR(64) NOT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function trusted_device_password_sig(mysqli $conn, int $user_id): string
{
    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $pw = (string) ($stmt->get_result()->fetch_assoc()['password'] ?? '');
    $stmt->close();
    return hash('sha256', 'dps-device|' . $pw);
}

/** Is this browser remembered for this admin? */
function trusted_device_valid(mysqli $conn, int $user_id): bool
{
    $token = (string) ($_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '');
    if ($user_id <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    trusted_device_ensure_schema($conn);
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id, password_sig FROM tbl_admin_trusted_devices WHERE token_hash = ? AND user_id = ? AND expires_at > NOW() LIMIT 1');
    $stmt->bind_param('si', $hash, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !hash_equals($row['password_sig'], trusted_device_password_sig($conn, $user_id))) return false;
    $conn->query('UPDATE tbl_admin_trusted_devices SET last_used_at = NOW() WHERE id = ' . (int) $row['id']);
    return true;
}

/** Remember this browser (after a correct code). */
function trusted_device_remember(mysqli $conn, int $user_id): void
{
    trusted_device_ensure_schema($conn);
    trusted_device_forget($conn); // replace this browser's old token, if any
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $sig = trusted_device_password_sig($conn, $user_id);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $expires = date('Y-m-d H:i:s', time() + TRUSTED_DEVICE_DAYS * 86400);
    $stmt = $conn->prepare('INSERT INTO tbl_admin_trusted_devices (user_id, token_hash, password_sig, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $user_id, $hash, $sig, $ua, $expires);
    $stmt->execute();
    $stmt->close();
    $conn->query('DELETE FROM tbl_admin_trusted_devices WHERE expires_at <= NOW()');
    setcookie(TRUSTED_DEVICE_COOKIE, $token, jwt_cookie_options(time() + TRUSTED_DEVICE_DAYS * 86400));
    $_COOKIE[TRUSTED_DEVICE_COOKIE] = $token;
}

/** Stop remembering this browser. */
function trusted_device_forget(mysqli $conn): void
{
    $token = (string) ($_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        trusted_device_ensure_schema($conn);
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare('DELETE FROM tbl_admin_trusted_devices WHERE token_hash = ?');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
    }
    if (isset($_COOKIE[TRUSTED_DEVICE_COOKIE])) {
        setcookie(TRUSTED_DEVICE_COOKIE, '', jwt_cookie_options(time() - 3600));
        unset($_COOKIE[TRUSTED_DEVICE_COOKIE]);
    }
}
