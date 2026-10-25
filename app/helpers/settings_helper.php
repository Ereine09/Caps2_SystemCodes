<?php
/**
 * Settings Helper
 * Handles persistence of system configuration in the database.
 */

if (!function_exists('settings_ensure_schema')) {
    function settings_ensure_schema(mysqli $conn): void
    {
        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
    }
}

if (!function_exists('settings_update')) {
    function settings_update(mysqli $conn, string $key, string $value): bool
    {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = ?");
        if (!$stmt) return false;
        $stmt->bind_param("sss", $key, $value, $value);
        return $stmt->execute();
    }
}

if (!function_exists('settings_get_all')) {
    function settings_get_all(mysqli $conn): array
    {
        $settings = [];
        $res = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
        if ($res) while ($row = mysqli_fetch_assoc($res)) $settings[$row['setting_key']] = $row['setting_value'];
        return $settings;
    }
}