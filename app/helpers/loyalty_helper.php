<?php
require_once __DIR__ . '/notification_helper.php';

if (!function_exists('loyalty_award_completed_order')) {
    /**
    * Award an order's points exactly once after it reaches a qualifying stage.
    * Call inside the caller's transaction.
     */
    function loyalty_award_completed_order(mysqli $conn, int $order_id): array
    {
        $result = [
            'awarded' => false,
            'already_awarded' => false,
            'points' => 0.00,
            'customer_id' => 0,
            'customer_name' => 'Customer',
            'customer_email' => '',
            'transaction_id' => 0,
        ];

        $order_stmt = $conn->prepare(
            'SELECT id, customer_id, subtotal, order_status
             FROM tbl_orders WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $order_stmt->bind_param('i', $order_id);
        $order_stmt->execute();
        $order = $order_stmt->get_result()->fetch_assoc();
        $order_stmt->close();

        $qualifying_statuses = [
            'processing',
            'ready_for_pickup',
            'to_ship',
            'to_receive',
            'out_for_delivery',
            'completed',
        ];
        if (!$order || !in_array($order['order_status'], $qualifying_statuses, true)) {
            return $result;
        }

        $result['customer_id'] = (int) $order['customer_id'];
        $duplicate_stmt = $conn->prepare(
            'SELECT id, points_earned FROM loyalty_transactions WHERE order_id = ? LIMIT 1 FOR UPDATE'
        );
        $duplicate_stmt->bind_param('i', $order_id);
        $duplicate_stmt->execute();
        $existing = $duplicate_stmt->get_result()->fetch_assoc();
        $duplicate_stmt->close();

        if ($existing) {
            $result['already_awarded'] = true;
            $result['transaction_id'] = (int) $existing['id'];
            $result['points'] = (float) $existing['points_earned'];
            $balance_stmt = $conn->prepare('SELECT loyalty_points FROM customers WHERE id = ? LIMIT 1');
            $balance_stmt->bind_param('i', $result['customer_id']);
            $balance_stmt->execute();
            $balance = $balance_stmt->get_result()->fetch_assoc();
            $balance_stmt->close();
            $result['balance'] = (float) ($balance['loyalty_points'] ?? 0.00);
            return $result;
        }

        $customer_stmt = $conn->prepare(
            'SELECT name, email FROM customers WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $customer_stmt->bind_param('i', $result['customer_id']);
        $customer_stmt->execute();
        $customer = $customer_stmt->get_result()->fetch_assoc();
        $customer_stmt->close();
        if (!$customer) {
            return $result;
        }

        $result['customer_name'] = trim((string) ($customer['name'] ?? '')) ?: 'Customer';
        $result['customer_email'] = (string) ($customer['email'] ?? '');
        $result['points'] = round((float) $order['subtotal'] / 100, 2);
        if ($result['points'] <= 0) {
            return $result;
        }

        $description = 'Online Purchase (Order #' . $order_id . ')';
        $transaction_stmt = $conn->prepare(
            'INSERT INTO loyalty_transactions
             (customer_id, user_id, product_name, quantity_kg, points_earned, order_id)
             VALUES (?, NULL, ?, 0.00, ?, ?)'
        );
        $transaction_stmt->bind_param('isdi', $result['customer_id'], $description, $result['points'], $order_id);
        $transaction_stmt->execute();
        $result['transaction_id'] = (int) $conn->insert_id;
        $transaction_stmt->close();

        $new_balance = notifications_sync_customer_loyalty_points($conn, $result['customer_id']);
        $result['balance'] = $new_balance;
        $balance_stmt = $conn->prepare('UPDATE tbl_orders SET loyalty_points_earned = ? WHERE id = ?');
        $balance_stmt->bind_param('di', $result['points'], $order_id);
        $balance_stmt->execute();
        $balance_stmt->close();
        $result['awarded'] = true;

        notifications_create($conn, [
            'customer_id' => $result['customer_id'],
            'type' => 'points_earned',
            'channel' => 'both',
            'title' => 'Loyalty points earned',
            'message' => $result['customer_name'] . ' earned ' . notifications_format_points($result['points'])
                . ' points from order #' . $order_id . '. Current balance: '
                . notifications_format_points($new_balance) . ' points.',
            'reference_table' => 'tbl_orders',
            'reference_id' => $order_id,
            'points_value' => $result['points'],
            'email_to' => $result['customer_email'],
        ]);
        return $result;
    }
}
