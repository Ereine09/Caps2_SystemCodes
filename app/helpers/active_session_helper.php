<?php
/**
 * ONE SIGNED-IN ACCOUNT PER PORTAL PER BROWSER/DEVICE - enforced by the server (MySQL). The three
 * portals - admin/staff, customer, rider - may each be signed in on the same browser (or phone, where
 * the installed apps share Chrome's login) at the same time, each in its own slot. Two accounts of the
 * same portal never share one: two customers, two riders, or an admin and a staff account.
 *
 * Slots: each browser has a staff slot (admin/staff), a customer slot and a rider slot - one
 * tbl_active_sessions row each. The staff slot's key is the plain device hash (so rows made before
 * slots existed still work); the other slots use sha256(device hash | slot).
 *
 * How it works
 *  - Each browser gets a random device id in the `lm_device` cookie (HttpOnly). The server stores
 *    only its SHA-256 hash.
 *  - tbl_active_sessions holds ONE row per device: who is signed in there (user id + role), the
 *    SHA-256 hash of their login token (JWT), created / last activity / expiry, and ended_at.
 *  - Login (setJWTCookie -> active_session_claim) locks that device's row (SELECT ... FOR UPDATE in a
 *    transaction). If a DIFFERENT account holds a live session there, the login is refused. The same
 *    account signing in again just replaces its own session.
 *  - Every request (verifyJWT -> active_session_validate) accepts a login token only while it is the
 *    live session of its device. So a token can't be used once the session ended, was replaced, went
 *    stale, or the password was reset.
 *  - Logout (clearJWTCookie -> active_session_release_token) ends the row, freeing the device.
 *
 * A session is live while: not ended, used within ACTIVE_SESSION_IDLE_SECONDS, the token not expired,
 * and the account still exists with the same role and no password change since sign-in. Anything
 * else counts as stale and is ignored (and marked ended), so a crashed/closed browser never locks a
 * device forever.
 */

require_once __DIR__ . '/../config/config.php';

const ACTIVE_SESSION_IDLE_SECONDS = 0;          // idle limit; 0 = none: a login stays until Log out (or a password reset)
const ACTIVE_SESSION_TOUCH_SECONDS = 60;        // refresh last_activity at most once a minute
const ACTIVE_SESSION_DEVICE_COOKIE = 'lm_device';
const ACTIVE_SESSION_BLOCKED_MESSAGE = 'Another account of this kind is currently logged in on this device. Please log out of it first before signing in with a different account.';

function active_session_ensure_schema(): void
{
    static $done = false;
    global $conn;
    if ($done || !($conn instanceof mysqli)) {
        return;
    }
    $done = true;
    // DDL: runs once per request, never inside a transaction (it would commit it).
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_active_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_hash CHAR(64) NOT NULL,
        user_id INT NULL,
        role VARCHAR(20) NULL,
        token_hash CHAR(64) NULL,
        created_at DATETIME NULL,
        last_activity DATETIME NULL,
        expires_at DATETIME NULL,
        ended_at DATETIME NULL,
        end_reason VARCHAR(20) NULL,
        UNIQUE KEY uniq_device (device_hash),
        UNIQUE KEY uniq_token (token_hash),
        KEY idx_account (role, user_id),
        KEY idx_live (ended_at, last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // The browser a slot row belongs to (slot rows are keyed by a hash of it); used to recover the device.
    $conn->query("ALTER TABLE tbl_active_sessions ADD COLUMN IF NOT EXISTS device_base CHAR(64) NULL AFTER device_hash");
}

/** Which sign-in slot a role uses on a browser. */
function active_session_slot(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, ['customer', 'rider'], true) ? $role : 'staff';
}

/** The tbl_active_sessions key of a slot on a device (the staff slot keeps the plain device hash). */
function active_session_slot_key(string $device, string $slot): string
{
    return $slot === 'staff' ? $device : hash('sha256', $device . '|' . $slot);
}

/**
 * May these two roles be signed in on the same browser at once? Yes when they use different portals
 * (admin/staff + customer + rider); admin and staff share one portal, so never together.
 */
function active_session_roles_together(string $a, string $b): bool
{
    return active_session_slot($a) !== active_session_slot($b);
}

/** Every slot row of a device (locked when $lock, inside the caller's transaction). */
function active_session_slot_rows(string $device, bool $lock = false): array
{
    global $conn;
    $keys = [active_session_slot_key($device, 'staff'), active_session_slot_key($device, 'customer'), active_session_slot_key($device, 'rider')];
    $st = $conn->prepare('SELECT * FROM tbl_active_sessions WHERE device_hash IN (?, ?, ?)' . ($lock ? ' FOR UPDATE' : ''));
    $st->bind_param('sss', ...$keys);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Would (user, role) be allowed next to the live sessions in these rows? */
function active_session_rows_allow(array $rows, int $user_id, string $role): bool
{
    $role = strtolower(trim($role));
    foreach ($rows as $row) {
        if (!active_session_row_live($row)) {
            continue;
        }
        $same_account = (int) $row['user_id'] === $user_id && $row['role'] === $role;
        if (!$same_account && !active_session_roles_together((string) $row['role'], $role)) {
            return false; // another account is signed in on this browser
        }
    }
    return true;
}

/** Is this session row on this browser (any of its slots, or a row from before slots)? */
function active_session_row_on_device(array $row, string $device): bool
{
    return $row['device_hash'] === $device
        || (!empty($row['device_base']) && $row['device_base'] === $device)
        || $row['device_hash'] === active_session_slot_key($device, active_session_slot((string) $row['role']));
}

function active_session_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Recover the current browser's server-side device key from a still-valid portal JWT. */
function active_session_cookie_device_hash(): ?string
{
    global $conn, $jwt_secret;
    if (!defined('JWT_PORTAL_COOKIES') || !($conn instanceof mysqli) || empty($jwt_secret)) {
        return null;
    }

    active_session_ensure_schema();
    $device_hashes = [];
    foreach (JWT_PORTAL_COOKIES as $cookie_name) {
        $token = (string) ($_COOKIE[$cookie_name] ?? '');
        if ($token === '') {
            continue;
        }

        try {
            $payload = (array) \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($jwt_secret, 'HS256'));
        } catch (Throwable $e) {
            continue;
        }
        if ((int) ($payload['exp'] ?? 0) <= time()) {
            continue;
        }

        $token_hash = hash('sha256', $token);
        $st = $conn->prepare('SELECT * FROM tbl_active_sessions WHERE token_hash = ? LIMIT 1');
        $st->bind_param('s', $token_hash);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row || !active_session_row_live($row)
            || (int) $row['user_id'] !== (int) ($payload['user_id'] ?? 0)
            || $row['role'] !== strtolower(trim((string) ($payload['role'] ?? '')))) {
            continue;
        }
        $device_hashes[!empty($row['device_base']) ? $row['device_base'] : $row['device_hash']] = true;
    }

    return count($device_hashes) === 1 ? (string) array_key_first($device_hashes) : null;
}

/** SHA-256 of this browser's device id; creates the cookie when $create and it is missing. */
function active_session_device_hash(bool $create = false): ?string
{
    $id = (string) ($_COOKIE[ACTIVE_SESSION_DEVICE_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
        $recovered = active_session_cookie_device_hash();
        if ($recovered !== null) {
            return $recovered;
        }
        if (!$create) {
            return null;
        }
        $id = bin2hex(random_bytes(32));
        if (!headers_sent()) {
            setcookie(ACTIVE_SESSION_DEVICE_COOKIE, $id, [
                'expires' => time() + 365 * 86400, 'path' => '/', 'domain' => '',
                'secure' => active_session_https(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[ACTIVE_SESSION_DEVICE_COOKIE] = $id;
    }
    return hash('sha256', $id);
}

/** True when the account behind a session row no longer allows it (deleted, role changed, password changed). */
function active_session_revoked(array $row): bool
{
    global $conn;
    $uid = (int) $row['user_id'];
    $created = strtotime((string) $row['created_at']);
    try {
        if ($row['role'] === 'customer') {
            $st = $conn->prepare("SELECT c.id, l.password_changed_at FROM customers c LEFT JOIN customer_login_credentials l ON l.customer_id = c.id WHERE c.id = ? LIMIT 1");
            $st->bind_param('i', $uid);
            $st->execute();
            $acc = $st->get_result()->fetch_assoc();
            $st->close();
            return !$acc || (!empty($acc['password_changed_at']) && strtotime($acc['password_changed_at']) > $created);
        }
        $st = $conn->prepare("SELECT LOWER(TRIM(role)) AS role, password_reset_at FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $acc = $st->get_result()->fetch_assoc();
        $st->close();
        return !$acc || $acc['role'] !== $row['role']
            || (!empty($acc['password_reset_at']) && strtotime($acc['password_reset_at']) > $created);
    } catch (Throwable $e) {
        return false; // a lookup problem never frees someone else's device
    }
}

/** Is this session row a live sign-in right now? */
function active_session_row_live(?array $row): bool
{
    if (!$row || $row['ended_at'] !== null || empty($row['token_hash']) || empty($row['last_activity'])) {
        return false;
    }
    $now = time();
    if (ACTIVE_SESSION_IDLE_SECONDS > 0 && strtotime($row['last_activity']) < $now - ACTIVE_SESSION_IDLE_SECONDS) {
        return false;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) <= $now) {
        return false;
    }
    return !active_session_revoked($row);
}

/** Ends every session that went stale (idle or expired). Cheap, indexed; runs at each login. */
function active_session_end_stale(): void
{
    global $conn;
    $idle = (int) ACTIVE_SESSION_IDLE_SECONDS;
    $idle_sql = $idle > 0 ? "last_activity < NOW() - INTERVAL $idle SECOND OR " : '';
    $conn->query("UPDATE tbl_active_sessions SET ended_at = NOW(), end_reason = 'expired'
                  WHERE ended_at IS NULL AND ({$idle_sql}expires_at <= NOW())");
}

/**
 * Login: claim this browser for (user, role) with this token. Atomic: the device row is locked for
 * the whole check-and-set, so two logins at the same moment can't both win. Returns false when a
 * different account holds a live session on this device (the login must be refused).
 */
function active_session_claim(int $user_id, string $role, string $token, int $expires_ts): bool
{
    global $conn;
    active_session_ensure_schema();
    $device = active_session_device_hash(true);
    $token_hash = hash('sha256', $token);
    $role = strtolower(trim($role));
    $key = active_session_slot_key($device, active_session_slot($role));
    active_session_end_stale();

    // All three slot rows always exist before we lock them, so FOR UPDATE locks real rows (no gap-lock race).
    foreach (['staff', 'customer', 'rider'] as $slot) {
        $slot_key = active_session_slot_key($device, $slot);
        $ins = $conn->prepare("INSERT IGNORE INTO tbl_active_sessions (device_hash, device_base, ended_at, end_reason) VALUES (?, ?, NOW(), 'new')");
        $ins->bind_param('ss', $slot_key, $device);
        $ins->execute();
        $ins->close();
    }

    $conn->begin_transaction();
    try {
        if (!active_session_rows_allow(active_session_slot_rows($device, true), $user_id, $role)) {
            $conn->rollback();
            return false; // another account is signed in on this browser of the same portal (admin/staff, customer, rider)
        }
        $base = $device;
        $device = $key; // write this role's own slot row

        // The same account's session in another slot of this browser (e.g. a customer login saved before
        // slots existed) is replaced by this one, so it can't linger and block the browser after logout.
        $old_keys = [active_session_slot_key($base, 'staff'), active_session_slot_key($base, 'customer'), active_session_slot_key($base, 'rider')];
        $old = $conn->prepare("UPDATE tbl_active_sessions SET ended_at = NOW(), end_reason = 'replaced'
                               WHERE device_hash IN (?, ?, ?) AND device_hash <> ? AND user_id = ? AND role = ? AND ended_at IS NULL");
        $old->bind_param('ssssis', $old_keys[0], $old_keys[1], $old_keys[2], $device, $user_id, $role);
        $old->execute();
        $old->close();

        $expires = date('Y-m-d H:i:s', $expires_ts);
        // The same token can't be live on two devices (uniq_token): free it anywhere else first.
        $free = $conn->prepare("UPDATE tbl_active_sessions SET token_hash = NULL, ended_at = COALESCE(ended_at, NOW()), end_reason = COALESCE(end_reason, 'replaced') WHERE token_hash = ? AND device_hash <> ?");
        $free->bind_param('ss', $token_hash, $device);
        $free->execute();
        $free->close();
        $up = $conn->prepare("UPDATE tbl_active_sessions SET user_id = ?, role = ?, token_hash = ?, created_at = NOW(), last_activity = NOW(),
                                     expires_at = ?, ended_at = NULL, end_reason = NULL, device_base = ? WHERE device_hash = ?");
        $up->bind_param('isssss', $user_id, $role, $token_hash, $expires, $base, $device);
        $up->execute();
        $up->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('active_session_claim failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Non-locking pre-check (e.g. before sending the admin 2FA code): would this account be allowed to
 * sign in on this device right now? The real, atomic decision is still made by active_session_claim().
 */
function active_session_can_login(int $user_id, string $role): bool
{
    global $conn;
    $device = active_session_device_hash(false);
    if ($device === null || !($conn instanceof mysqli)) {
        return true;
    }
    active_session_ensure_schema();
    return active_session_rows_allow(active_session_slot_rows($device), $user_id, $role);
}

/** This device's session row (or null). */
function active_session_device_row(): ?array
{
    global $conn;
    $device = active_session_device_hash(false);
    if ($device === null || !($conn instanceof mysqli)) {
        return null;
    }
    active_session_ensure_schema();
    $st = $conn->prepare("SELECT * FROM tbl_active_sessions WHERE device_hash = ? LIMIT 1");
    $st->bind_param('s', $device);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Does this browser have a live signed-in session (any role, or only in $slot: 'staff' / 'customer' / 'rider')? */
function active_session_device_active(?string $slot = null): bool
{
    global $conn;
    static $cache = [];
    $k = $slot ?? '*';
    if (!isset($cache[$k])) {
        $cache[$k] = false;
        $device = active_session_device_hash(false);
        if ($device !== null && $conn instanceof mysqli) {
            active_session_ensure_schema();
            foreach (active_session_slot_rows($device) as $row) {
                if ($slot === null || active_session_slot((string) $row['role']) === $slot) {
                    $cache[$k] = $cache[$k] || active_session_row_live($row);
                }
            }
        }
    }
    return $cache[$k];
}

/**
 * Every request: is this login token the live session of its device? Refreshes last_activity (at most
 * once a minute). Cached per request, so pages that check the token several times cost one query.
 */
function active_session_validate(string $token): bool
{
    global $conn;
    static $cache = [];
    $token_hash = hash('sha256', $token);
    if (isset($cache[$token_hash])) {
        return $cache[$token_hash];
    }
    if (!($conn instanceof mysqli)) {
        return $cache[$token_hash] = false;
    }
    active_session_ensure_schema();
    $st = $conn->prepare("SELECT * FROM tbl_active_sessions WHERE token_hash = ? LIMIT 1");
    $st->bind_param('s', $token_hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $row_live = active_session_row_live($row);
    // Only a session that is stale on its own terms is ended here. A request from the wrong browser
    // (a copied cookie) is refused below but must NOT end the real owner's session.
    if ($row && !$row_live && $row['ended_at'] === null) {
        $end = $conn->prepare("UPDATE tbl_active_sessions SET ended_at = NOW(), end_reason = 'expired' WHERE id = ? AND ended_at IS NULL");
        $end->bind_param('i', $row['id']);
        $end->execute();
        $end->close();
    }
    // A cookie token must also belong to THIS browser (a copied cookie from another device doesn't count).
    $device = active_session_device_hash(false);
    $live = $row_live && ($device === null || active_session_row_on_device($row, $device));
    if ($live && strtotime($row['last_activity']) < time() - ACTIVE_SESSION_TOUCH_SECONDS) {
        $touch = $conn->prepare("UPDATE tbl_active_sessions SET last_activity = NOW() WHERE id = ? AND ended_at IS NULL");
        $touch->bind_param('i', $row['id']);
        $touch->execute();
        $touch->close();
    }
    return $cache[$token_hash] = $live;
}

/** Logout: ends the session that belongs to this token (if it is still open). */
function active_session_release_token(string $token, string $reason = 'logout'): void
{
    global $conn;
    if ($token === '' || !($conn instanceof mysqli)) {
        return;
    }
    active_session_ensure_schema();
    $token_hash = hash('sha256', $token);
    $st = $conn->prepare("UPDATE tbl_active_sessions SET ended_at = NOW(), end_reason = ? WHERE token_hash = ? AND ended_at IS NULL");
    $st->bind_param('ss', $reason, $token_hash);
    $st->execute();
    $st->close();
}

/**
 * Generic conflict message. It deliberately offers no logout action, so a rejected login cannot
 * release or clear the currently authenticated identity.
 */
function active_session_blocked_html(): string
{
    return '<div role="alert" style="border:1px solid var(--danger, #dc2626);background:var(--danger-soft, #fef2f2);color:var(--text, #1f2937);border-radius:12px;padding:14px 16px;margin:0 0 16px;font-size:.92rem;line-height:1.5;text-align:left;">'
        . '<strong style="display:block;margin-bottom:4px;color:var(--danger, #b91c1c);">Login blocked</strong>'
        . htmlspecialchars(ACTIVE_SESSION_BLOCKED_MESSAGE, ENT_QUOTES, 'UTF-8')
        . '</div>';
}
