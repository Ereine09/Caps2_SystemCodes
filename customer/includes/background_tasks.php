<?php
// This script will be included in customer/includes/header.php

// Define constants for background task management
if (!defined('AUTO_CONFIRM_LAST_RUN_FILE')) {
    define('AUTO_CONFIRM_LAST_RUN_FILE', __DIR__ . '/last_auto_confirm_run.txt');
}
if (!defined('AUTO_CONFIRM_RUN_INTERVAL_SECONDS')) {
    define('AUTO_CONFIRM_RUN_INTERVAL_SECONDS', 60); // Run every 60 seconds (1 minute)
}

// Ensure necessary helper functions are available
// config.php and customer/includes/functions.php are typically included by header.php (via auth.php)
// notification_helper.php might not be, so include it here.
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

// Function to safely run the auto-confirm logic
if (!function_exists('trigger_auto_confirm_if_needed')) {
function trigger_auto_confirm_if_needed($conn) {
    $lock_path = AUTO_CONFIRM_LAST_RUN_FILE . '.lock';
    $lock_handle = @fopen($lock_path, 'c');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock_handle)) fclose($lock_handle);
        return;
    }

    try {
        $last_run_time = file_exists(AUTO_CONFIRM_LAST_RUN_FILE)
            ? (int)@file_get_contents(AUTO_CONFIRM_LAST_RUN_FILE)
            : 0;

        if ((time() - $last_run_time) < AUTO_CONFIRM_RUN_INTERVAL_SECONDS) {
            return;
        }

        // Claim the interval while holding the lock, then run silently.
        @file_put_contents(AUTO_CONFIRM_LAST_RUN_FILE, time(), LOCK_EX);
        ob_start();
        require_once __DIR__ . '/../../customer/auto_confirm_orders.php';
        if (function_exists('run_auto_confirm_orders')) {
            $log = run_auto_confirm_orders($conn);
            if (strpos($log, 'Error processing') !== false || strpos($log, 'Database query failed') !== false) {
                error_log('Auto-confirm task: ' . $log);
            }
        }
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        error_log('Auto-confirm trigger failed: ' . $e->getMessage());
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}

// Call the trigger function during page load
// Ensure $conn is available (it should be from config.php included in header.php)
if (isset($conn)) {
    trigger_auto_confirm_if_needed($conn);
}
}