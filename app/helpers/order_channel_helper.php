<?php
/**
 * Where an order came from ("Order received via").
 *
 * Orders customers place themselves on the website are "Online" (order_channel stays NULL).
 * Orders staff enter on the customer's behalf (modules/admin/create_customer_order.php) record how
 * the customer reached the store: walk-in, Facebook Messenger, SMS, email, phone call or other.
 * Stored in tbl_orders.order_channel; tbl_orders.order_source ('customer' / 'admin') is unchanged.
 */

const ORDER_CHANNELS = [
    'walk_in'   => ['Walk-in',            'fa-store'],
    'messenger' => ['Facebook Messenger', 'fa-brands fa-facebook-messenger'],
    'sms'       => ['SMS / Text',         'fa-comment-sms'],
    'email'     => ['Email',              'fa-envelope'],
    'phone'     => ['Phone call',         'fa-phone'],
    'other'     => ['Other',              'fa-ellipsis'],
];

function order_channel_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $col = $conn->query("SHOW COLUMNS FROM tbl_orders LIKE 'order_channel'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_orders ADD COLUMN order_channel VARCHAR(20) NULL DEFAULT NULL AFTER order_source");
    }
}

/** "Facebook Messenger", "Walk-in", ... or "Online" for a customer's own website order. */
function order_channel_label(?string $channel, ?string $source = 'customer'): string
{
    $channel = (string) $channel;
    if (isset(ORDER_CHANNELS[$channel])) {
        return ORDER_CHANNELS[$channel][0];
    }
    return $source === 'admin' ? 'Created by staff' : 'Online';
}

/** Font Awesome classes for the channel's icon. */
function order_channel_icon(?string $channel, ?string $source = 'customer'): string
{
    $channel = (string) $channel;
    if (isset(ORDER_CHANNELS[$channel])) {
        $icon = ORDER_CHANNELS[$channel][1];
        return strpos($icon, 'fa-brands') === 0 ? $icon : 'fas ' . $icon;
    }
    return $source === 'admin' ? 'fas fa-user-tie' : 'fas fa-globe';
}
