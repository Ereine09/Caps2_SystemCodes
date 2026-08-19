<?php
/**
 * Auto-Confirm Orders Script
 *
 * This script can be run in two ways:
 * 1. Manually from the command line (CLI) for debugging, where it will echo its progress.
 * 2. Included by a web page (like background_tasks.php), where it will run silently.
 *
 * It finds 'pending' orders and automatically updates their status to 'confirmed'.
 */

// Only set these when running from CLI. Web servers have their own configurations.
if (PHP_SAPI === 'cli') {
    set_time_limit(300);
}

// Use a different timezone if your server is not in Asia/Manila
date_default_timezone_set('Asia/Manila');

// When included by a web page, dependencies are already loaded.
// Only require them if running directly from the command line.
if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/../app/config/config.php';
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/../app/helpers/notification_helper.php';
}

function run_auto_confirm_orders($conn) {
    $log_output = [];
    $log_output[] = "Cron Job: Auto-Confirming Orders - " . date('Y-m-d H:i:s');

    // Find pending orders older than 2 minutes that can be auto-confirmed
    $auto_confirm_minutes = 2;
    $result = $conn->query(
        "SELECT id, order_number, customer_id FROM tbl_orders 
         WHERE order_status = 'pending' 
         AND payment_method IN ('cod', 'pay_at_shop')
         AND created_at <= (NOW() - INTERVAL $auto_confirm_minutes MINUTE)"
    );

    if (!$result) {
        $log_output[] = "Database query failed: " . $conn->error;
        return implode("\n", $log_output);
    }

    if ($result->num_rows === 0) {
        $log_output[] = "No orders to auto-confirm.";
        return implode("\n", $log_output);
    }

    $confirmed_count = 0;
    while ($order = $result->fetch_assoc()) {
        $order_id = (int)$order['id'];

        $conn->begin_transaction();
        try {
            // 1. Deduct stock for each item in the order
            $order_items = get_order_items($order_id);
            foreach ($order_items as $item) {
                $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                if (!deduct_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity'])) {
                    throw new Exception("Insufficient stock for product ID {$item['product_id']} in order #{$order['order_number']}.");
                }
            }

            // 2. Update order status to 'confirmed'
            $update_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'confirmed' WHERE id = ?");
            $update_stmt->bind_param('i', $order_id);
            $update_stmt->execute();
            $update_stmt->close();

            // 3. Notify the customer
            notifications_create($conn, [
                'customer_id' => (int)$order['customer_id'],
                'type' => 'order_status_update',
                'channel' => 'both',
                'title' => 'Order Confirmed!',
                'message' => 'Your order #' . $order['order_number'] . ' has been confirmed and is now being processed.',
                'reference_table' => 'tbl_orders',
                'reference_id' => $order_id,
            ]);

            $conn->commit();
            $log_output[] = "Confirmed Order #" . $order['order_number'];
            $confirmed_count++;
        } catch (Throwable $e) {
            $conn->rollback();
            // Use error_log for server-side logging instead of echoing in a web context
            error_log("Error processing Order #" . $order['order_number'] . ": " . $e->getMessage());
            $log_output[] = "Error processing Order #" . $order['order_number'] . ": " . $e->getMessage();
        }
    }
    $log_output[] = "Finished. Confirmed $confirmed_count order(s).";
    return implode("\n", $log_output);
}

// This block ensures that if the script is run directly from the command line,
// it will execute the function and print the results to the terminal.
// When included by background_tasks.php, this block is ignored.
if (PHP_SAPI === 'cli') {
    // The $conn variable is available here because we required config.php above inside the CLI check.
    $output = run_auto_confirm_orders($conn);
    echo $output . "\n";
}