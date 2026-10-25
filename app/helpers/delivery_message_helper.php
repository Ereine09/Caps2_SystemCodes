<?php
/**
 * Automatic "DPS DELIVERY!" customer message for the delivery "To Receive" transition.
 *
 * Reuses the existing messaging + notification infrastructure - nothing new:
 *   - when a rider is assigned, the message is a row in tbl_messages in that
 *     customer's Rider chat (sender_type 'rider', user_id = the rider's users.id);
 *     orders without a resolvable rider retain the system support-thread fallback;
 *   - the unread badge comes from get_unread_count_customer() (counts it automatically);
 *   - the bell / sound / live refresh come from notifications_create() with
 *     reference_table 'tbl_messages' (the same pipeline notify_customer_new_staff_message()
 *     uses, pushed over the existing WebSocket).
 *
 * Exactly-once: the caller only invokes this on a real status TRANSITION into
 * 'to_receive', and the function additionally claims the order with one atomic UPDATE on
 * tbl_orders.to_receive_msg_sent_at - so page refreshes, Rider App polling, re-opening the
 * order or two staff/rider actions racing can never create a second message.
 */

require_once __DIR__ . '/notification_helper.php';

const DELIVERY_MESSAGE_MAX_ITEM_LINES = 8;

function delivery_message_ensure_column(mysqli $conn): void
{
    $col = $conn->query("SHOW COLUMNS FROM tbl_orders LIKE 'to_receive_msg_sent_at'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_orders ADD COLUMN to_receive_msg_sent_at DATETIME NULL DEFAULT NULL");
    }
}

/** "Chicken Wings — Size: 1kg × 2" (human-readable only - no internal ids). */
function delivery_message_item_line(array $item): string
{
    $name = trim((string)$item['product_name']);
    $variant = trim((string)($item['variant_size'] ?? ''));
    if ($variant !== '') {
        // order items store the variant inside the name too, e.g. "Name (Size)": show it once.
        $suffix = ' (' . $variant . ')';
        if (mb_substr($name, -mb_strlen($suffix)) === $suffix) {
            $name = rtrim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)));
        }
    }
    $name = mb_strimwidth($name, 0, 80, '…');
    $line = '• ' . $name . ($variant !== '' ? ' — Size: ' . mb_strimwidth($variant, 0, 60, '…') : '');
    return $line . ' × ' . (int)$item['quantity'];
}

/** Builds the message text from real order data. */
function delivery_message_build(array $order, array $items, string $rider_name): string
{
    $lines = [];
    foreach ($items as $item) {
        $lines[] = delivery_message_item_line($item);
    }
    if (count($lines) > DELIVERY_MESSAGE_MAX_ITEM_LINES) {
        $extra = count($lines) - DELIVERY_MESSAGE_MAX_ITEM_LINES;
        $lines = array_slice($lines, 0, DELIVERY_MESSAGE_MAX_ITEM_LINES);
        $lines[] = "• …and {$extra} more " . ($extra === 1 ? 'item' : 'items') . ' (see your order details)';
    }

    $method = strtolower(trim((string)($order['payment_method'] ?? '')));
    if ($method === 'cod') {
        // Not yet paid: tell them exactly how much cash to have ready.
        $payment = 'Balance to pay: ₱' . number_format((float)$order['total'], 2) . ".\n\n"
            . 'Please prepare the exact amount. You can pay by cash or conveniently online when the rider arrives.';
    } else {
        // Already paid (GCash/Bank Transfer): nothing left to collect, say so explicitly
        // rather than just "recorded as paid" - avoids the customer wondering if the
        // rider will still ask them for money.
        $label = ['gcash' => 'GCash', 'bank' => 'Bank Transfer'][$method] ?? null;
        $payment = 'Balance to pay: ₱0.00' . ($label ? " (already paid via {$label})" : ' (already paid)') . ".\n\n"
            . 'Please have your delivery confirmation ready when the rider arrives.';
    }

    return "DPS DELIVERY!\n\n"
        . 'ID: ' . $order['order_number'] . "\n"
        . 'Rider: ' . $rider_name . "\n\n"
        . "Order:\n" . implode("\n", $lines) . "\n\n"
        . $payment . "\n\n"
        . "Please note that a photo proof of delivery is required.\n\n"
        . 'Thank you for choosing DPS Express Philippines! Stay safe and have a great day ahead.';
}

/** Assigned rider identity for the chat and message text. */
function delivery_message_rider_identity(mysqli $conn, int $order_rider_id): array
{
    if ($order_rider_id > 0) {
        // tbl_orders.rider_id normally holds riders.id; a few legacy paths stored users.id.
        $stmt = $conn->prepare(
            "SELECT u.id AS user_id,
                    TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS full_name, u.username
             FROM users u
             WHERE u.id = COALESCE((SELECT r.user_id FROM riders r WHERE r.id = ? LIMIT 1), ?) AND u.role = 'rider'
             LIMIT 1"
        );
        $stmt->bind_param('ii', $order_rider_id, $order_rider_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'user_id' => (int) $row['user_id'],
                'name' => trim((string)$row['full_name']) !== '' ? trim((string)$row['full_name']) : (string)$row['username'],
            ];
        }
    }
    return ['user_id' => 0, 'name' => 'To be assigned'];
}

/**
 * Call right after an order actually moved INTO 'to_receive'. Safe to call from any
 * status-change path; it decides everything from the live database row.
 *
 * @return string 'sent' | 'skipped_not_delivery' | 'skipped_not_to_receive' | 'skipped_already_sent' | 'error'
 */
function delivery_message_send_on_to_receive(mysqli $conn, int $order_id): string
{
    try {
        delivery_message_ensure_column($conn);

        $stmt = $conn->prepare(
            "SELECT id, customer_id, rider_id, order_number, order_status, fulfillment_type, payment_method, total
             FROM tbl_orders WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || (int)$order['customer_id'] <= 0) {
            return 'error';
        }
        if ($order['fulfillment_type'] !== 'delivery') {
            return 'skipped_not_delivery';
        }
        // 'out_for_delivery' too: the rider's store pickup scan moves To Ship straight to Out for Delivery.
        if (!in_array($order['order_status'], ['to_receive', 'out_for_delivery'], true)) {
            return 'skipped_not_to_receive';
        }

        // Atomic once-only claim: only one caller can flip NULL -> NOW().
        $claim = $conn->prepare(
            "UPDATE tbl_orders SET to_receive_msg_sent_at = NOW()
             WHERE id = ? AND to_receive_msg_sent_at IS NULL AND order_status IN ('to_receive', 'out_for_delivery')"
        );
        $claim->bind_param('i', $order_id);
        $claim->execute();
        $claimed = $claim->affected_rows === 1;
        $claim->close();
        if (!$claimed) {
            return 'skipped_already_sent';
        }

        try {
            $stmt = $conn->prepare("SELECT product_name, variant_size, quantity FROM tbl_order_items WHERE order_id = ? ORDER BY id");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $rider = delivery_message_rider_identity($conn, (int)$order['rider_id']);
            $rider_user_id = (int) $rider['user_id'];
            $rider_name = (string) $rider['name'];
            $text = delivery_message_build($order, $items, $rider_name);

            $customer_id = (int)$order['customer_id'];
            if ($rider_user_id > 0) {
                $ins = $conn->prepare("INSERT INTO tbl_messages (customer_id, user_id, sender_type, message) VALUES (?, ?, 'rider', ?)");
                $ins->bind_param('iis', $customer_id, $rider_user_id, $text);
            } else {
                $ins = $conn->prepare("INSERT INTO tbl_messages (customer_id, user_id, sender_type, message) VALUES (?, NULL, 'admin', ?)");
                $ins->bind_param('is', $customer_id, $text);
            }
            $ins->execute();
            $message_id = (int)$conn->insert_id;
            $ins->close();
        } catch (Throwable $e) {
            // Release the claim so a later, valid attempt is not blocked by a failed send.
            $release = $conn->prepare("UPDATE tbl_orders SET to_receive_msg_sent_at = NULL WHERE id = ?");
            $release->bind_param('i', $order_id);
            $release->execute();
            $release->close();
            throw $e;
        }

        // Unread badge is automatic (is_read = 0); this adds the bell, sound and live refresh.
        try {
            notifications_create($conn, [
                'customer_id'     => $customer_id,
                'type'            => $rider_user_id > 0 ? 'rider_message' : 'delivery_message',
                'channel'         => 'in_app',
                'title'           => $rider_user_id > 0 ? 'Message from your rider' : 'DPS Delivery',
                'message'         => $rider_user_id > 0
                    ? 'Your rider shared a delivery update. Open Rider Chat for the details.'
                    : 'Your order ' . $order['order_number'] . ' is on its way. Open Messages for the delivery details.',
                'reference_table' => $rider_user_id > 0 ? 'rider_chat' : 'tbl_messages',
                'reference_id'    => $message_id,
            ]);
        } catch (Throwable $e) {
            error_log('[delivery message] notification failed for order ' . $order_id . ': ' . $e->getMessage());
        }
        return 'sent';
    } catch (Throwable $e) {
        error_log('[delivery message] failed for order ' . $order_id . ': ' . $e->getMessage());
        return 'error';
    }
}
