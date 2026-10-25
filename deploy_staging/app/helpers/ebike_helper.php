<?php
/**
 * E-bike fleet availability - the ONE place that decides whether delivery can be
 * offered. Used by the Admin E-Bike page, customer checkout and the payment step.
 *
 * Source of truth: tbl_ebikes (per-bike On/Off = is_enabled, plus status).
 * An e-bike counts as AVAILABLE for delivery only when it is switched ON and its
 * status is 'available'. Switched-off, in-use, maintenance and retired bikes do
 * not count. Delivery is offered for NEW orders while at least one e-bike is
 * available; one bike being off never turns delivery off by itself.
 */

function ebike_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_ebikes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bike_code VARCHAR(50) NOT NULL,
        model VARCHAR(100) NULL,
        plate_number VARCHAR(20) NULL,
        status ENUM('available','in_use','maintenance','retired') NOT NULL DEFAULT 'available',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ebike_code (bike_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Per-bike on/off switch (e.g. switch one bike off while its battery is low) - independent of `status`.
    $col = $conn->query("SHOW COLUMNS FROM tbl_ebikes LIKE 'is_enabled'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_ebikes ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    }
}

/**
 * Live fleet counts. Each e-bike lands in exactly ONE bucket
 * (priority: retired > maintenance > switched off > in use > available).
 */
function ebike_fleet_summary(mysqli $conn): array
{
    ebike_ensure_schema($conn);
    $row = $conn->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(status = 'retired'), 0) AS retired,
            COALESCE(SUM(status = 'maintenance'), 0) AS maintenance,
            COALESCE(SUM(status NOT IN ('retired','maintenance') AND is_enabled = 0), 0) AS off_count,
            COALESCE(SUM(status = 'in_use' AND is_enabled = 1), 0) AS in_use,
            COALESCE(SUM(status = 'available' AND is_enabled = 1), 0) AS available
         FROM tbl_ebikes"
    )->fetch_assoc();

    return [
        'total'       => (int)$row['total'],
        'available'   => (int)$row['available'],
        'in_use'      => (int)$row['in_use'],
        'maintenance' => (int)$row['maintenance'],
        'off'         => (int)$row['off_count'],
        'retired'     => (int)$row['retired'],
    ];
}

/** True while at least one e-bike is available, i.e. NEW delivery orders can be accepted. */
function ebike_delivery_available(mysqli $conn): bool
{
    return ebike_fleet_summary($conn)['available'] > 0;
}

function ebike_plural(int $n, string $one = 'e-bike', string $many = 'e-bikes'): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** Admin-facing sentence describing the real fleet state. */
function ebike_fleet_message(array $s): string
{
    if ($s['available'] === 0) {
        return $s['total'] === 0
            ? 'No e-bikes are registered, so delivery is unavailable. Add an e-bike to offer delivery. Customers can still choose Pickup.'
            : 'No e-bikes are currently available for delivery. Customers can still choose Pickup.';
    }
    $parts = [];
    if ($s['maintenance'] > 0) {
        $parts[] = ebike_plural($s['maintenance']) . ($s['maintenance'] === 1 ? ' is' : ' are') . ' under maintenance.';
        $parts[] = ebike_plural($s['available']) . ($s['available'] === 1 ? ' remains' : ' remain') . ' available for delivery.';
    } else {
        $parts[] = ebike_plural($s['available']) . ($s['available'] === 1 ? ' is' : ' are') . ' currently available for delivery.';
    }
    if ($s['off'] > 0) {
        $parts[] = ebike_plural($s['off']) . ($s['off'] === 1 ? ' is' : ' are') . ' switched off.';
    }
    return implode(' ', $parts);
}

/** Customer-facing wording (no internal details). */
const EBIKE_DELIVERY_UNAVAILABLE_MESSAGE =
    'Delivery is currently unavailable because no e-bikes are available. Please select Pickup or try again later.';
