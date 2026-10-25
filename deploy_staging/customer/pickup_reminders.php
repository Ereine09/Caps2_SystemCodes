<?php
/**
 * Pickup Reminder Script
 *
 * Mirrors customer/auto_confirm_orders.php's dual-mode pattern:
 * 1. Manually from the command line (CLI) for debugging.
 * 2. Included by customer/includes/background_tasks.php, running silently
 *    on a throttle every time a customer page loads (the same
 *    page-refresh-driven pattern already used for auto-confirm - there is
 *    no true cron/task-scheduler in this project, so this is the existing
 *    mechanism, not a new one).
 *
 * Finds orders with an upcoming pickup (today or tomorrow) that haven't
 * been reminded yet, and sends ONE reminder per order using the existing
 * notifications_create() pipeline (in-app + Gmail, same PHPMailer infra
 * every other notification already uses). "Already reminded" is tracked
 * via the existing `notifications` table (type='pickup_reminder',
 * reference_table='tbl_orders', reference_id=<order id>) - no schema
 * change, reusing the exact dedupe pattern notification_helper.php's own
 * points_expiring seeding already uses.
 */

if (PHP_SAPI === 'cli') {
    set_time_limit(300);
}

date_default_timezone_set('Asia/Manila');

if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/../app/config/config.php';
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/../app/helpers/notification_helper.php';
}

function run_pickup_reminders($conn) {
    $log_output = [];
    $log_output[] = "Cron Job: Pickup Reminders - " . date('Y-m-d H:i:s');

    // Pickup orders that are confirmed/being prepared (never 'pending' - an
    // unconfirmed order isn't a real commitment yet) with a real scheduled
    // pickup date of today or tomorrow, that haven't reached pickup/done.
    $result = $conn->query(
        "SELECT o.id, o.order_number, o.customer_id, o.pickup_date, o.pickup_time,
                c.name AS customer_name, c.email AS customer_email
         FROM tbl_orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE o.fulfillment_type = 'pickup'
           AND o.order_status IN ('confirmed', 'processing')
           AND o.pickup_date IS NOT NULL
           AND o.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.type = 'pickup_reminder'
                 AND n.reference_table = 'tbl_orders'
                 AND n.reference_id = o.id
           )"
    );

    if (!$result) {
        $log_output[] = "Database query failed: " . $conn->error;
        return implode("\n", $log_output);
    }

    if ($result->num_rows === 0) {
        $log_output[] = "No pickup reminders to send.";
        return implode("\n", $log_output);
    }

    $sent_count = 0;
    while ($order = $result->fetch_assoc()) {
        $order_id = (int) $order['id'];
        $pickup_when = $order['pickup_date'] === date('Y-m-d') ? 'today' : 'tomorrow';
        $time_text = !empty($order['pickup_time']) ? ' at ' . $order['pickup_time'] : '';

        $message = "Hi " . $order['customer_name'] . "! This is a reminder that your order #"
            . $order['order_number'] . " is scheduled for pickup " . $pickup_when . $time_text . ". "
            . "We look forward to seeing you!";

        $created = notifications_create($conn, [
            'customer_id'     => (int) $order['customer_id'],
            'type'            => 'pickup_reminder',
            'channel'         => 'both',
            'title'           => 'Pickup Reminder',
            'message'         => $message,
            'reference_table' => 'tbl_orders',
            'reference_id'    => $order_id,
            'email_to'        => $order['customer_email'],
        ]);

        if ($created) {
            $sent_count++;
            $log_output[] = "Reminded Order #" . $order['order_number'];
        } else {
            $log_output[] = "Failed to create reminder for Order #" . $order['order_number'];
        }
    }

    $log_output[] = "Finished. Sent $sent_count pickup reminder(s).";
    return implode("\n", $log_output);
}

if (PHP_SAPI === 'cli') {
    $output = run_pickup_reminders($conn);
    echo $output . "\n";
}
