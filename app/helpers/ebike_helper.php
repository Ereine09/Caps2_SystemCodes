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
    // Admin-chosen e-bikes that may carry bulk orders (see ebike_bulk_check()).
    $col = $conn->query("SHOW COLUMNS FROM tbl_ebikes LIKE 'bulk_ok'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_ebikes ADD COLUMN bulk_ok TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled");
    }
}

/**
 * Bulk orders may only go out on e-bikes the admin marked "Bulk orders". Returns an error
 * message when $ebike_id is not one of them, or '' when it's fine. No bike marked at all =
 * no restriction (so pickups keep working until the shop picks its bulk e-bikes).
 */
function ebike_bulk_check(mysqli $conn, int $ebike_id): string
{
    ebike_ensure_schema($conn);
    $bulk = $conn->query("SELECT id, bike_code FROM tbl_ebikes WHERE bulk_ok = 1 AND status <> 'retired' ORDER BY bike_code")->fetch_all(MYSQLI_ASSOC);
    if (!$bulk || in_array($ebike_id, array_map('intval', array_column($bulk, 'id')), true)) {
        return '';
    }
    return 'This is a bulk order. Use an e-bike for bulk orders (' . implode(', ', array_column($bulk, 'bike_code'))
        . ') - change it in Profile > My E-Bike.';
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

/* ---------------------------------------------------------------------------
 * E-bike usage: which rider took which e-bike for which order.
 *
 * Recorded when the rider confirms PICKUP (To Ship -> To Receive, rider_update_status_api.php)
 * and closed when the order is delivered. A bike counts as "with" a rider while its usage
 * row is open AND the order is still on the road (To Receive / Out for Delivery), so an order
 * that staff cancel or move back never leaves a bike stuck with someone.
 * Recording usage does not change tbl_ebikes.status (that still decides delivery availability
 * at checkout), so one bike out on a trip never switches delivery off for new orders.
 * ------------------------------------------------------------------------- */

const EBIKE_ON_ROAD_STATUSES = "'to_receive','out_for_delivery'";

/**
 * Creates the table on first use. Runs its DDL once per request: CREATE TABLE commits any open
 * transaction, so callers that write inside a transaction call this BEFORE begin_transaction().
 */
function ebike_usage_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ebike_ensure_schema($conn);
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_ebike_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ebike_id INT NOT NULL,
        rider_user_id INT NOT NULL,
        rider_name VARCHAR(120) NOT NULL DEFAULT '',
        order_id INT NOT NULL,
        delivery_id INT NULL,
        picked_up_at DATETIME NOT NULL,
        returned_at DATETIME NULL,
        KEY idx_usage_bike (ebike_id, returned_at),
        KEY idx_usage_order (order_id),
        KEY idx_usage_rider (rider_user_id),
        FOREIGN KEY (ebike_id) REFERENCES tbl_ebikes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // The e-bike the rider picked in Rider Portal > Profile; used automatically at pickup.
    $col = $conn->query("SHOW COLUMNS FROM riders LIKE 'ebike_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE riders ADD COLUMN ebike_id INT NULL DEFAULT NULL");
    }
}

/**
 * E-bikes a rider may use: switched on, Available or In Use, not out on a delivery with ANOTHER
 * rider and not picked by another rider in their profile. The rider's own pick comes first
 * ('selected'); 'mine' = their pick or the bike they already have on this trip.
 */
function ebike_rider_choices(mysqli $conn, int $rider_user_id): array
{
    ebike_usage_ensure_schema($conn);
    $on_road = "SELECT 1 FROM tbl_ebike_usage u JOIN tbl_orders o ON o.id = u.order_id
                WHERE u.ebike_id = b.id AND u.returned_at IS NULL AND o.order_status IN (" . EBIKE_ON_ROAD_STATUSES . ")";
    $stmt = $conn->prepare(
        "SELECT b.id, b.bike_code, b.model, b.plate_number, b.bulk_ok,
                EXISTS (SELECT 1 FROM riders r WHERE r.ebike_id = b.id AND r.user_id = ?) AS selected,
                EXISTS ($on_road AND u.rider_user_id = ?) AS on_trip
         FROM tbl_ebikes b
         WHERE b.is_enabled = 1 AND b.status IN ('available','in_use')
           AND NOT EXISTS ($on_road AND u.rider_user_id <> ?)
           AND NOT EXISTS (SELECT 1 FROM riders r WHERE r.ebike_id = b.id AND r.user_id <> ?)
         ORDER BY selected DESC, on_trip DESC, b.bike_code"
    );
    $stmt->bind_param('iiii', $rider_user_id, $rider_user_id, $rider_user_id, $rider_user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['bulk_ok'] = (int)$r['bulk_ok'] === 1;
        $r['selected'] = (int)$r['selected'] === 1;
        $r['mine'] = $r['selected'] || (int)$r['on_trip'] === 1;
        unset($r['on_trip']);
    }
    return $rows;
}

/**
 * The rider's profile e-bike: ['id', 'bike_code', 'model', 'plate_number', 'usable' => bool]
 * or null. 'usable' is false when the shop switched it off / put it under maintenance since.
 */
function ebike_rider_profile_bike(mysqli $conn, int $rider_user_id): ?array
{
    ebike_usage_ensure_schema($conn);
    $stmt = $conn->prepare(
        "SELECT b.id, b.bike_code, b.model, b.plate_number, b.bulk_ok FROM riders r JOIN tbl_ebikes b ON b.id = r.ebike_id
         WHERE r.user_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $rider_user_id);
    $stmt->execute();
    $bike = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bike) {
        return null;
    }
    $bike['id'] = (int)$bike['id'];
    $bike['bulk_ok'] = (int)$bike['bulk_ok'] === 1;
    $bike['usable'] = in_array($bike['id'], array_column(ebike_rider_choices($conn, $rider_user_id), 'id'), true);
    return $bike;
}

/** Saves the rider's profile e-bike (null = not using a shop e-bike). Throws if it isn't free. */
function ebike_set_rider_bike(mysqli $conn, int $rider_user_id, ?int $ebike_id): void
{
    ebike_usage_ensure_schema($conn);
    if ($ebike_id !== null && !in_array($ebike_id, array_column(ebike_rider_choices($conn, $rider_user_id), 'id'), true)) {
        throw new Exception('That e-bike is not available right now. Pick another one.');
    }
    $stmt = $conn->prepare("UPDATE riders SET ebike_id = ? WHERE user_id = ?");
    $stmt->bind_param('ii', $ebike_id, $rider_user_id);
    $stmt->execute();
    $stmt->close();
}

/** Records the pickup. Call inside the status-update transaction. Throws if the bike isn't a valid choice. */
function ebike_record_pickup(mysqli $conn, int $ebike_id, int $rider_user_id, int $order_id, int $delivery_id): string
{
    $choice = null;
    foreach (ebike_rider_choices($conn, $rider_user_id) as $b) {
        if ($b['id'] === $ebike_id) { $choice = $b; break; }
    }
    if (!$choice) {
        throw new Exception('That e-bike is not available. Pick another one.');
    }
    $stmt = $conn->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS n, username FROM users WHERE id = ?");
    $stmt->bind_param('i', $rider_user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $name = trim((string)($u['n'] ?? '')) !== '' ? trim((string)$u['n']) : (string)($u['username'] ?? 'Rider');

    // A re-pickup of the same order replaces any earlier open record for it.
    $stmt = $conn->prepare("UPDATE tbl_ebike_usage SET returned_at = NOW() WHERE order_id = ? AND returned_at IS NULL");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    $delivery = $delivery_id > 0 ? $delivery_id : null;
    $stmt = $conn->prepare("INSERT INTO tbl_ebike_usage (ebike_id, rider_user_id, rider_name, order_id, delivery_id, picked_up_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('iisii', $ebike_id, $rider_user_id, $name, $order_id, $delivery);
    $stmt->execute();
    $stmt->close();
    return (string)$choice['bike_code'];
}

/** Closes the usage record(s) of an order (delivered). Safe to call when there are none. */
function ebike_record_return(mysqli $conn, int $order_id): void
{
    ebike_usage_ensure_schema($conn);
    $stmt = $conn->prepare("UPDATE tbl_ebike_usage SET returned_at = NOW() WHERE order_id = ? AND returned_at IS NULL");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();
}
