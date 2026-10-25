<?php
/**
 * Rider dispatch: availability (Online / On Break / Offline) and self-assignment of To Ship orders.
 *
 * Availability reuses the existing riders columns, so older readers keep working:
 *   online   = is_on_duty 1, availability_status 'available'
 *   on_break = is_on_duty 1, availability_status 'unavailable'
 *   offline  = is_on_duty 0
 * plus availability_reason / availability_details / availability_changed_at (added here).
 * Being Offline never logs the rider out: login (JWT) and availability are separate.
 *
 * Assignment uses the SAME column as the admin "Assign rider" (tbl_delivery.rider_id = users.id),
 * so the admin's order page shows who accepted, and the store QR scan (rider_update_status_api.php)
 * treats the rider as assigned. tbl_orders.rider_id (riders.id) is still set by that scan, as before.
 * Both the rider's Accept and the admin's Assign lock the order row (SELECT ... FOR UPDATE) inside a
 * transaction, so two people can never end up assigned to the same order.
 */
require_once __DIR__ . '/db_schema_helper.php';
require_once __DIR__ . '/notification_helper.php';

const RIDER_STATUSES = ['online', 'on_break', 'offline'];

if (!function_exists('rider_dispatch_ensure_schema')) {
    function rider_dispatch_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ensureRiderSchema($conn);
        $cols = [
            'availability_reason'     => "VARCHAR(50) NULL",
            'availability_details'    => "VARCHAR(255) NULL",
            'availability_changed_at' => "DATETIME NULL",
        ];
        foreach ($cols as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM `riders` LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `riders` ADD COLUMN `$col` $def");
            }
        }
    }
}

if (!function_exists('rider_break_reasons')) {
    function rider_break_reasons(): array
    {
        return [
            'lunch_break'         => 'Lunch Break',
            'rest_break'          => 'Rest Break',
            'vehicle_maintenance' => 'Vehicle Maintenance',
            'personal_emergency'  => 'Personal Emergency',
            'end_of_shift'        => 'End of Shift',
            'other'               => 'Other',
        ];
    }
}

if (!function_exists('rider_status_label')) {
    function rider_status_label(string $status): string
    {
        return ['online' => 'Online', 'on_break' => 'On Break', 'offline' => 'Offline'][$status] ?? 'Offline';
    }
}

if (!function_exists('rider_profile_row')) {
    /** The riders row for a rider's users.id, created if missing (same as the other rider APIs do). */
    function rider_profile_row(mysqli $conn, int $user_id): ?array
    {
        rider_dispatch_ensure_schema($conn);
        $sel = $conn->prepare("SELECT * FROM riders WHERE user_id = ? LIMIT 1");
        $sel->bind_param('i', $user_id);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row) {
            $ins = $conn->prepare("INSERT IGNORE INTO riders (user_id, is_on_duty) VALUES (?, 0)");
            $ins->bind_param('i', $user_id);
            $ins->execute();
            $ins->close();
            $sel = $conn->prepare("SELECT * FROM riders WHERE user_id = ? LIMIT 1");
            $sel->bind_param('i', $user_id);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();
        }
        return $row ?: null;
    }
}

if (!function_exists('rider_availability_from_row')) {
    function rider_availability_from_row(?array $row): array
    {
        $status = 'offline';
        if ($row && (int) ($row['is_on_duty'] ?? 0) === 1) {
            $status = ($row['availability_status'] ?? 'available') === 'unavailable' ? 'on_break' : 'online';
        }
        $reason_key = $status === 'online' ? '' : (string) ($row['availability_reason'] ?? '');
        return [
            'status'       => $status,
            'label'        => rider_status_label($status),
            'reason'       => $reason_key,
            'reason_label' => $reason_key !== '' ? (rider_break_reasons()[$reason_key] ?? $reason_key) : '',
            'details'      => $status === 'online' ? '' : (string) ($row['availability_details'] ?? ''),
            'changed_at'   => $row['availability_changed_at'] ?? null,
        ];
    }
}

if (!function_exists('rider_active_deliveries')) {
    /** The rider's deliveries that are still open (assigned through either column, not finished). */
    function rider_active_deliveries(mysqli $conn, int $user_id, int $riders_id): array
    {
        $stmt = $conn->prepare(
            "SELECT o.id, o.order_number, o.order_status
             FROM tbl_orders o
             LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
             WHERE o.fulfillment_type = 'delivery'
               AND o.order_status IN ('to_ship', 'to_receive', 'out_for_delivery')
               AND ((o.rider_id = ? AND o.rider_id > 0) OR (d.rider_id = ? AND d.rider_id > 0))
             ORDER BY o.id"
        );
        $stmt->bind_param('ii', $riders_id, $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('rider_display_name')) {
    function rider_display_name(mysqli $conn, int $user_id): string
    {
        $stmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        return $full !== '' ? $full : (string) ($u['username'] ?? 'A rider');
    }
}

if (!function_exists('rider_notify_admins')) {
    /** In-app notification to every admin account (the existing bell + toast poller shows it). */
    function rider_notify_admins(mysqli $conn, string $type, string $title, string $message, string $ref_table, int $ref_id): void
    {
        try {
            notifications_ensure_schema($conn);
            $res = $conn->query("SELECT id FROM users WHERE LOWER(TRIM(role)) = 'admin'");
            while ($res && ($a = $res->fetch_assoc())) {
                notifications_create($conn, [
                    'user_id'         => (int) $a['id'],
                    'type'            => $type,
                    'channel'         => 'in_app',
                    'title'           => $title,
                    'message'         => $message,
                    'reference_table' => $ref_table,
                    'reference_id'    => $ref_id,
                ]);
            }
        } catch (Throwable $e) {
            error_log('rider_notify_admins failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rider_availability_set')) {
    /**
     * Changes the rider's availability. On Break / Offline need a reason (and details for "Other").
     * Active deliveries are NEVER unassigned; they are returned so the rider is reminded of them.
     * Returns ['ok', 'message', 'availability', 'active' => [...], 'changed' => bool].
     */
    function rider_availability_set(mysqli $conn, int $user_id, string $status, string $reason = '', string $details = ''): array
    {
        $row = rider_profile_row($conn, $user_id);
        if (!$row) {
            return ['ok' => false, 'message' => 'Rider profile not found.'];
        }
        if (!in_array($status, RIDER_STATUSES, true)) {
            return ['ok' => false, 'message' => 'Choose Online, On Break or Offline.'];
        }
        $details = mb_substr(trim($details), 0, 255);
        if ($status !== 'online') {
            if (!isset(rider_break_reasons()[$reason])) {
                return ['ok' => false, 'message' => 'Please choose a reason.'];
            }
            if ($reason === 'other' && $details === '') {
                return ['ok' => false, 'message' => 'Please describe the reason.'];
            }
        } else {
            $reason = '';
            $details = '';
        }

        $before = rider_availability_from_row($row);
        $on_duty = $status === 'offline' ? 0 : 1;
        $avail = $status === 'on_break' ? 'unavailable' : 'available';
        $reason_db = $reason !== '' ? $reason : null;
        $details_db = $details !== '' ? $details : null;
        $riders_id = (int) $row['id'];
        $up = $conn->prepare(
            "UPDATE riders SET is_on_duty = ?, availability_status = ?, availability_reason = ?, availability_details = ?,
                    availability_changed_at = NOW(), last_seen = NOW()
             WHERE id = ?"
        );
        $up->bind_param('isssi', $on_duty, $avail, $reason_db, $details_db, $riders_id);
        $up->execute();
        $up->close();

        $row = rider_profile_row($conn, $user_id);
        $after = rider_availability_from_row($row);
        $active = rider_active_deliveries($conn, $user_id, $riders_id);
        $changed = $before['status'] !== $after['status'] || $before['reason'] !== $after['reason'] || $before['details'] !== $after['details'];

        if ($changed) {
            $name = rider_display_name($conn, $user_id);
            if ($status === 'online') {
                $msg = "$name is now Online and available for deliveries.";
            } else {
                $msg = "$name is now " . $after['label'] . '. Reason: ' . $after['reason_label']
                    . ($details !== '' ? " ($details)" : '') . '.';
                if ($active) {
                    $msg .= ' Still assigned: ' . implode(', ', array_map(fn($o) => '#' . $o['order_number'], $active)) . '.';
                }
            }
            rider_notify_admins($conn, 'rider_availability', 'Rider availability updated', $msg, 'riders', $riders_id);
            @$conn->query("INSERT INTO activity_logs (user_id, action, details) VALUES (" . (int) $user_id . ", 'Rider Availability', '" . $conn->real_escape_string($msg) . "')");
        }

        $message = $status === 'online'
            ? 'You are now Online. You can accept To Ship orders.'
            : 'You are now ' . $after['label'] . '. You will not get new orders.';
        if ($status !== 'online' && $active) {
            $message .= ' You still have ' . count($active) . ' active ' . (count($active) === 1 ? 'delivery' : 'deliveries') . ' - finish them or ask the admin to reassign.';
        }
        return ['ok' => true, 'message' => $message, 'availability' => $after, 'active' => $active, 'changed' => $changed];
    }
}

if (!function_exists('rider_accept_order')) {
    /**
     * Rider self-assignment. Only an ONLINE rider, only an unassigned To Ship DELIVERY order.
     * The order row is locked for the whole check-and-assign, so of two riders tapping Accept at the
     * same moment exactly one succeeds. Repeating the request after success is a no-op (no second
     * notification). Returns ['ok', 'message', 'code', 'order_number'].
     */
    function rider_accept_order(mysqli $conn, int $user_id, int $order_id): array
    {
        $row = rider_profile_row($conn, $user_id);
        if (!$row) {
            return ['ok' => false, 'code' => 'profile', 'message' => 'Rider profile not found.'];
        }
        $avail = rider_availability_from_row($row);
        if ($avail['status'] !== 'online') {
            return ['ok' => false, 'code' => 'unavailable', 'message' => 'You are ' . $avail['label'] . '. Go Online to accept orders.'];
        }
        $riders_id = (int) $row['id'];
        rider_assignment_ensure_schema($conn); // before the transaction: ALTER TABLE would commit it

        $conn->begin_transaction();
        try {
            $o = $conn->prepare("SELECT id, order_number, order_status, fulfillment_type, COALESCE(rider_id, 0) AS rider_id
                                 FROM tbl_orders WHERE id = ? FOR UPDATE");
            $o->bind_param('i', $order_id);
            $o->execute();
            $order = $o->get_result()->fetch_assoc();
            $o->close();
            if (!$order) {
                $conn->rollback();
                return ['ok' => false, 'code' => 'missing', 'message' => 'Order not found.'];
            }
            $d = $conn->prepare("SELECT id, COALESCE(rider_id, 0) AS rider_id, status FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $d->bind_param('i', $order_id);
            $d->execute();
            $delivery = $d->get_result()->fetch_assoc();
            $d->close();

            $order_rider = (int) $order['rider_id'];
            $delivery_rider = (int) ($delivery['rider_id'] ?? 0);
            $number = (string) $order['order_number'];

            // Already mine (double tap / retry): success, nothing written, nobody notified again.
            if (($delivery_rider === $user_id) || ($order_rider > 0 && $order_rider === $riders_id)) {
                $conn->commit();
                return ['ok' => true, 'code' => 'already_mine', 'message' => "Order #$number is already assigned to you.", 'order_number' => $number];
            }
            if ($order_rider > 0 || $delivery_rider > 0) {
                $conn->rollback();
                return ['ok' => false, 'code' => 'taken', 'message' => "Order #$number was already taken by another rider."];
            }
            if (($order['fulfillment_type'] ?? '') !== 'delivery') {
                $conn->rollback();
                return ['ok' => false, 'code' => 'pickup', 'message' => 'This is a store pickup order; it has no delivery.'];
            }
            if ($order['order_status'] !== 'to_ship') {
                $conn->rollback();
                return ['ok' => false, 'code' => 'status', 'message' => "Order #$number is not ready to ship right now."];
            }
            if ($delivery && !in_array($delivery['status'], ['pending'], true)) {
                $conn->rollback();
                return ['ok' => false, 'code' => 'status', 'message' => "Order #$number is no longer waiting for a rider."];
            }

            if ($delivery) {
                // The rider chose this order, so it counts as accepted (no Accept / Decline step).
                $u = $conn->prepare("UPDATE tbl_delivery SET rider_id = ?, rider_response = 'accepted', rider_response_at = NOW(), updated_at = NOW() WHERE id = ? AND COALESCE(rider_id, 0) = 0");
                $u->bind_param('ii', $user_id, $delivery['id']);
                $u->execute();
                $hit = $u->affected_rows;
                $u->close();
                if ($hit !== 1) {
                    $conn->rollback();
                    return ['ok' => false, 'code' => 'taken', 'message' => "Order #$number was already taken by another rider."];
                }
            } else {
                $tok = bin2hex(random_bytes(16));
                $u = $conn->prepare("INSERT INTO tbl_delivery (order_id, rider_id, delivery_type, status, qr_confirmation_token, rider_response, rider_response_at) VALUES (?, ?, 'delivery', 'pending', ?, 'accepted', NOW())");
                $u->bind_param('iis', $order_id, $user_id, $tok);
                $u->execute();
                $u->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('rider_accept_order failed: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error', 'message' => 'Could not accept the order. Please try again.'];
        }

        $name = rider_display_name($conn, $user_id);
        $when = date('M j, Y g:i A');
        rider_notify_admins($conn, 'rider_accepted_order', 'Order accepted by rider',
            "$name has accepted Order #$number for delivery ($when). Status: assigned, waiting for pickup at the store.",
            'tbl_orders', $order_id);
        @$conn->query("INSERT INTO activity_logs (user_id, action, details) VALUES (" . (int) $user_id . ", 'Rider Accepted Order', '"
            . $conn->real_escape_string("$name accepted order #$number for delivery.") . "')");

        return ['ok' => true, 'code' => 'accepted', 'message' => "Order #$number is now yours. Pick it up at the store and scan the parcel QR.", 'order_number' => $number];
    }
}


if (!function_exists('rider_assignment_ensure_schema')) {
    /**
     * Rider's answer to an admin assignment, on the order's latest tbl_delivery row:
     *   rider_response = 'pending'  - an admin assigned this rider; waiting for Accept / Decline
     *                    'accepted' - the rider accepted (or took the order themselves)
     *                    NULL       - no answer needed (assigned before this existed, or no rider)
     * A rider who scans the parcel at the store without answering has accepted it in practice
     * (tbl_orders.rider_id is then set and the order can no longer be declined).
     */
    function rider_assignment_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach (['rider_response' => 'VARCHAR(10) NULL', 'rider_response_at' => 'DATETIME NULL'] as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM `tbl_delivery` LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `tbl_delivery` ADD COLUMN `$col` $def");
            }
        }
    }
}

if (!function_exists('rider_respond_assignment')) {
    /**
     * The logged-in rider accepts or declines an order an admin assigned to them.
     * Decline frees the order (no rider; it is offered to all riders again once To Ship) and tells
     * the admins, with the rider's reason, so they can assign someone else.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    function rider_respond_assignment(mysqli $conn, int $user_id, int $order_id, string $decision, string $reason = ''): array
    {
        if (!in_array($decision, ['accept', 'decline'], true)) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'Invalid choice.'];
        }
        rider_assignment_ensure_schema($conn); // before the transaction: ALTER TABLE would commit it
        $reason = mb_substr(trim($reason), 0, 200);

        $conn->begin_transaction();
        try {
            $o = $conn->prepare("SELECT id, order_number, order_status, COALESCE(rider_id, 0) AS rider_id FROM tbl_orders WHERE id = ? FOR UPDATE");
            $o->bind_param('i', $order_id);
            $o->execute();
            $order = $o->get_result()->fetch_assoc();
            $o->close();
            $d = $conn->prepare("SELECT id, COALESCE(rider_id, 0) AS rider_id, rider_response FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $d->bind_param('i', $order_id);
            $d->execute();
            $delivery = $d->get_result()->fetch_assoc();
            $d->close();

            if (!$order || !$delivery || (int) $delivery['rider_id'] !== $user_id) {
                $conn->rollback();
                return ['ok' => false, 'code' => 'not_mine', 'message' => 'This order is no longer assigned to you.'];
            }
            $number = (string) $order['order_number'];
            if (in_array($order['order_status'], ['cancelled', 'completed', 'reviews', 'refunded'], true)) {
                $conn->rollback();
                return ['ok' => false, 'code' => 'closed', 'message' => "Order #$number is already closed."];
            }
            if ($decision === 'accept') {
                if (($delivery['rider_response'] ?? '') !== 'pending') {
                    $conn->commit();
                    return ['ok' => true, 'code' => 'already', 'message' => "Order #$number is already yours."];
                }
                $u = $conn->prepare("UPDATE tbl_delivery SET rider_response = 'accepted', rider_response_at = NOW(), updated_at = NOW() WHERE id = ?");
                $u->bind_param('i', $delivery['id']);
                $u->execute();
                $u->close();
            } else {
                if ((int) $order['rider_id'] > 0) {
                    $conn->rollback();
                    return ['ok' => false, 'code' => 'picked_up', 'message' => "You already picked up Order #$number at the store, so it can no longer be declined. Please message the shop."];
                }
                $u = $conn->prepare("UPDATE tbl_delivery SET rider_id = NULL, rider_response = NULL, rider_response_at = NULL, updated_at = NOW() WHERE id = ?");
                $u->bind_param('i', $delivery['id']);
                $u->execute();
                $u->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('rider_respond_assignment failed: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error', 'message' => 'Could not save your answer. Please try again.'];
        }

        $name = rider_display_name($conn, $user_id);
        if ($decision === 'accept') {
            rider_notify_admins($conn, 'rider_accepted_order', 'Rider accepted the delivery',
                "$name accepted Order #$number. Waiting for pickup at the store.", 'tbl_orders', $order_id);
            $log = "$name accepted the assigned order #$number.";
            $msg = "Order #$number accepted. Pick it up at the store and scan the parcel QR.";
        } else {
            rider_notify_admins($conn, 'rider_declined_order', 'Rider declined the delivery',
                "$name declined Order #$number" . ($reason !== '' ? " (reason: $reason)" : '') . '. Please assign another rider.', 'tbl_orders', $order_id);
            $log = "$name declined the assigned order #$number" . ($reason !== '' ? " - $reason" : '') . '.';
            $msg = "Order #$number declined. The shop has been told so they can assign another rider.";
        }
        $act = $decision === 'accept' ? 'Rider Accepted Order' : 'Rider Declined Order';
        $st = $conn->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)');
        if ($st) {
            $st->bind_param('iss', $user_id, $act, $log);
            @$st->execute();
            $st->close();
        }
        return ['ok' => true, 'code' => $decision === 'accept' ? 'accepted' : 'declined', 'message' => $msg];
    }
}
