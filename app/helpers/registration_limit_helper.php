<?php
/**
 * Anti-spam registration: at most REG_LIMIT_MAX new customer accounts per device in
 * REG_LIMIT_WINDOW_HOURS hours (customer/register.php). After that, sign-up on that device is
 * blocked until the oldest of those registrations is more than 24 hours old.
 *
 * "Device" is recognised two ways, and either one counts:
 *   - the browser's long-lived device id (lm_device cookie, active_session_helper.php), and
 *   - a fingerprint of the IP address + browser (user agent), so clearing cookies or opening a
 *     private window on the same phone does not reset the count.
 * Only hashes are stored, never the raw IP or cookie. Accounts created by admin/staff are not counted.
 */
require_once __DIR__ . '/active_session_helper.php';

const REG_LIMIT_MAX = 2;
const REG_LIMIT_WINDOW_HOURS = 24;

if (!function_exists('reg_limit_ensure_schema')) {
    function reg_limit_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_registration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_hash CHAR(64) NOT NULL,
            fingerprint_hash CHAR(64) NOT NULL,
            customer_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reg_device (device_hash, created_at),
            KEY idx_reg_fp (fingerprint_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('reg_limit_keys')) {
    /** [device hash, IP+browser fingerprint hash] for the current request. */
    function reg_limit_keys(): array
    {
        $device = active_session_device_hash(true) ?? hash('sha256', 'no-device');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return [$device, hash('sha256', 'reg|' . $ip . '|' . $ua)];
    }
}

if (!function_exists('reg_limit_status')) {
    /**
     * ['blocked' => bool, 'used' => int, 'retry_at' => int|null (unix time the next sign-up is allowed)].
     */
    function reg_limit_status(mysqli $conn): array
    {
        reg_limit_ensure_schema($conn);
        [$device, $fp] = reg_limit_keys();
        $stmt = $conn->prepare(
            "SELECT created_at FROM tbl_registration_log
             WHERE (device_hash = ? OR fingerprint_hash = ?) AND created_at > NOW() - INTERVAL " . REG_LIMIT_WINDOW_HOURS . " HOUR
             ORDER BY created_at ASC"
        );
        $stmt->bind_param('ss', $device, $fp);
        $stmt->execute();
        $times = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'created_at');
        $stmt->close();
        $used = count($times);
        if ($used < REG_LIMIT_MAX) {
            return ['blocked' => false, 'used' => $used, 'retry_at' => null];
        }
        // Allowed again once enough of the counted sign-ups are older than the window.
        $retry_at = strtotime($times[$used - REG_LIMIT_MAX]) + REG_LIMIT_WINDOW_HOURS * 3600;
        return ['blocked' => true, 'used' => $used, 'retry_at' => $retry_at];
    }
}

if (!function_exists('reg_limit_message')) {
    function reg_limit_message(array $status): string
    {
        $when = $status['retry_at'] ? date('M j, Y g:i A', (int) $status['retry_at']) : 'later';
        return 'This device has already created ' . REG_LIMIT_MAX . ' accounts in the last ' . REG_LIMIT_WINDOW_HOURS
            . ' hours. To prevent spam accounts, you can create another account after ' . $when
            . '. If you already have an account, please log in instead.';
    }
}

if (!function_exists('reg_limit_record')) {
    /** Counts a successful registration against this device. */
    function reg_limit_record(mysqli $conn, ?int $customer_id): void
    {
        reg_limit_ensure_schema($conn);
        [$device, $fp] = reg_limit_keys();
        $stmt = $conn->prepare("INSERT INTO tbl_registration_log (device_hash, fingerprint_hash, customer_id) VALUES (?, ?, ?)");
        $stmt->bind_param('ssi', $device, $fp, $customer_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('reg_limit_lock')) {
    /**
     * Serialises sign-ups from one device, so two forms submitted at the same moment cannot both
     * slip under the limit. Returns a release function.
     */
    function reg_limit_lock(mysqli $conn): callable
    {
        [$device] = reg_limit_keys();
        $name = 'dps_reg_' . substr($device, 0, 40);
        $stmt = $conn->prepare("SELECT GET_LOCK(?, 10)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
        return static function () use ($conn, $name): void {
            $stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->close();
        };
    }
}
