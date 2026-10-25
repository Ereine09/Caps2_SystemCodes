<?php

/**
 * Ensures `customer_login_credentials` has the reset_token / reset_expiry columns
 * used by the customer "Forgot password" flow. Idempotent, same column-check
 * pattern used elsewhere in this codebase (see db_schema_helper.php).
 */
function ensure_customer_reset_schema(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM `customer_login_credentials` LIKE 'reset_token'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE `customer_login_credentials` ADD COLUMN `reset_token` VARCHAR(100) NULL DEFAULT NULL AFTER `password`");
        $conn->query("ALTER TABLE `customer_login_credentials` ADD COLUMN `reset_expiry` DATETIME NULL DEFAULT NULL AFTER `reset_token`");
        $conn->query("ALTER TABLE `customer_login_credentials` ADD UNIQUE KEY `uniq_reset_token` (`reset_token`)");
    }
}

/**
 * Ensures `customer_login_credentials` has the login_attempts / lock_until columns
 * used by the customer login lockout (3 wrong passwords locks the account for a
 * few minutes). Mirrors the same two columns already used on `users` for the
 * admin/staff login lockout in modules/auth/login.php.
 */
function ensure_customer_lockout_schema(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM `customer_login_credentials` LIKE 'login_attempts'");
    if ($check && $check->num_rows === 0) {
        // No AFTER clause: this must not depend on ensure_customer_reset_schema()
        // having already run (column order doesn't matter functionally).
        $conn->query("ALTER TABLE `customer_login_credentials` ADD COLUMN `login_attempts` INT NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE `customer_login_credentials` ADD COLUMN `lock_until` DATETIME NULL DEFAULT NULL");
    }
}

/**
 * Ensures `customer_login_credentials` has password_changed_at. The JWT session
 * cookie is stateless (no server-side token store — see app/helpers/jwt_helper.php),
 * so this timestamp is what lets an already-issued token be invalidated after the
 * fact: customer_is_logged_in() (customer/includes/auth.php) rejects any token
 * whose iat predates it. Set whenever a password changes, self-service or
 * Admin-triggered (modules/customers/customers.php "Reset Password") — this is
 * how an old/stolen phone's existing session stops working once the password on
 * file changes, without a second auth system.
 *
 * Called with a `static $done` guard from the read path (customer_is_logged_in(),
 * hit on every customer request) so the column is guaranteed to exist before it's
 * queried, at the cost of only one SHOW COLUMNS per request.
 */
function ensure_customer_password_meta_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $check = $conn->query("SHOW COLUMNS FROM `customer_login_credentials` LIKE 'password_changed_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE `customer_login_credentials` ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL");
    }
}
