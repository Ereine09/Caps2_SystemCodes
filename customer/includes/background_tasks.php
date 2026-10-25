<?php
// This script is included in customer/includes/header.php and in the admin and staff sidebars,
// so a page view in any portal runs the due tasks (on Hostinger a cron job can run them too).

// Define constants for background task management
if (!defined('AUTO_CONFIRM_LAST_RUN_FILE')) {
    define('AUTO_CONFIRM_LAST_RUN_FILE', __DIR__ . '/last_auto_confirm_run.txt');
}
if (!defined('AUTO_CONFIRM_RUN_INTERVAL_SECONDS')) {
    define('AUTO_CONFIRM_RUN_INTERVAL_SECONDS', 60); // Run every 60 seconds (1 minute)
}
// Pickup Reminders - a separate lock/last-run file so a slow or failing
// auto-confirm run never blocks this one (or vice versa). Reminders don't
// need to be checked as often as auto-confirm since they're date-based
// (today/tomorrow), not minute-based.
if (!defined('PICKUP_REMINDER_LAST_RUN_FILE')) {
    define('PICKUP_REMINDER_LAST_RUN_FILE', __DIR__ . '/last_pickup_reminder_run.txt');
}
if (!defined('PICKUP_REMINDER_RUN_INTERVAL_SECONDS')) {
    define('PICKUP_REMINDER_RUN_INTERVAL_SECONDS', 900); // Run at most every 15 minutes
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
        if (!function_exists('deduct_order_stock_once')) {
            // Admin/staff pages (via their sidebars) don't load the customer helpers.
            require_once __DIR__ . '/functions.php';
        }
        require_once __DIR__ . '/../../customer/auto_confirm_orders.php';
        // Free Shipping rewards (10 completed orders in a month) for recently completed orders.
        try {
            if (is_file(__DIR__ . '/../../app/helpers/free_shipping_helper.php')) {
                require_once __DIR__ . '/../../app/helpers/free_shipping_helper.php';
                free_shipping_sync_recent($conn);
            }
        } catch (Throwable $e) {
            error_log('Free shipping sync: ' . $e->getMessage());
        }
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

if (!function_exists('trigger_pickup_reminders_if_needed')) {
function trigger_pickup_reminders_if_needed($conn) {
    $lock_path = PICKUP_REMINDER_LAST_RUN_FILE . '.lock';
    $lock_handle = @fopen($lock_path, 'c');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock_handle)) fclose($lock_handle);
        return;
    }

    try {
        $last_run_time = file_exists(PICKUP_REMINDER_LAST_RUN_FILE)
            ? (int) @file_get_contents(PICKUP_REMINDER_LAST_RUN_FILE)
            : 0;

        if ((time() - $last_run_time) < PICKUP_REMINDER_RUN_INTERVAL_SECONDS) {
            return;
        }

        @file_put_contents(PICKUP_REMINDER_LAST_RUN_FILE, time(), LOCK_EX);
        ob_start();
        require_once __DIR__ . '/../../customer/pickup_reminders.php';
        if (function_exists('run_pickup_reminders')) {
            $log = run_pickup_reminders($conn);
            if (strpos($log, 'Failed to create') !== false || strpos($log, 'Database query failed') !== false) {
                error_log('Pickup reminder task: ' . $log);
            }
        }
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        error_log('Pickup reminder trigger failed: ' . $e->getMessage());
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}

if (isset($conn)) {
    trigger_pickup_reminders_if_needed($conn);
}
}
// Inventory batches - a separate lock/last-run file again. Takes batches past their expiration date
// out of sellable stock, and brings batches back in line with stock changed outside the batch-aware
// paths (e.g. a direct stock edit on the Products page). See app/helpers/inventory_batch_helper.php.
if (!defined('BATCH_MAINTENANCE_LAST_RUN_FILE')) {
    define('BATCH_MAINTENANCE_LAST_RUN_FILE', __DIR__ . '/last_batch_maintenance_run.txt');
}
if (!defined('BATCH_MAINTENANCE_RUN_INTERVAL_SECONDS')) {
    define('BATCH_MAINTENANCE_RUN_INTERVAL_SECONDS', 600); // at most every 10 minutes
}
if (!function_exists('trigger_batch_maintenance_if_needed')) {
function trigger_batch_maintenance_if_needed($conn) {
    $lock_path = BATCH_MAINTENANCE_LAST_RUN_FILE . '.lock';
    $lock_handle = @fopen($lock_path, 'c');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock_handle)) fclose($lock_handle);
        return;
    }
    try {
        $last_run_time = file_exists(BATCH_MAINTENANCE_LAST_RUN_FILE)
            ? (int) @file_get_contents(BATCH_MAINTENANCE_LAST_RUN_FILE)
            : 0;
        if ((time() - $last_run_time) < BATCH_MAINTENANCE_RUN_INTERVAL_SECONDS) {
            return;
        }
        @file_put_contents(BATCH_MAINTENANCE_LAST_RUN_FILE, time(), LOCK_EX);
        require_once __DIR__ . '/../../app/helpers/inventory_batch_helper.php';
        // Only once the Suppliers & Deliveries page has created the batch tables.
        if (inv_batches_ready($conn) && !inv_in_transaction($conn)) {
            inv_batches_reconcile_all($conn);
            inv_expire_due_batches($conn);
        }
    } catch (Throwable $e) {
        error_log('Batch maintenance failed: ' . $e->getMessage());
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}
if (isset($conn)) {
    trigger_batch_maintenance_if_needed($conn);
}
}
