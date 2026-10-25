<?php
/**
 * Messaging Helper Functions
 * Handles general messages between customers and staff/admin
 */

require_once __DIR__ . '/../config/config.php';
// I-include natin ang gemini helper para magamit ang getGeminiBusinessInsight function
// Siguraduhin na tama ang relative path nito base sa folder structure mo (e.g., helpers/gemini_helper.php)
require_once __DIR__ . '/gemini_helper.php';
// notifications_create() - reused below for the real-time Customer -> Admin
// new-message push (see notify_staff_new_customer_message()). Same file
// customer/includes/functions.php already requires for the same reason.
require_once __DIR__ . '/notification_helper.php';

/**
 * Ensure the messaging table exists
 */
function messaging_ensure_schema($conn) {
    $query = "CREATE TABLE IF NOT EXISTS tbl_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        user_id INT DEFAULT NULL,
        sender_type VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($query);
    messaging_ensure_extras_schema($conn);
}

/* ---------------------------------------------------------------------------
 * Replies + reactions (support chat: customer <-> admin/staff).
 *   tbl_messages.reply_to_id   -> the message being replied to (same customer thread)
 *   tbl_message_reactions      -> one emoji per person per message; picking it again removes it
 * ------------------------------------------------------------------------- */
const MESSAGE_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

function messaging_ensure_extras_schema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $col = $conn->query("SHOW COLUMNS FROM tbl_messages LIKE 'reply_to_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_messages ADD COLUMN reply_to_id INT NULL DEFAULT NULL AFTER message");
    }
    // Unsend: set when the sender takes a message back (the text is cleared; the row stays so the
    // thread order and replies that quote it still make sense).
    $col = $conn->query("SHOW COLUMNS FROM tbl_messages LIKE 'unsent_at'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_messages ADD COLUMN unsent_at DATETIME NULL DEFAULT NULL AFTER read_at");
    }
    // "Delete for me": hides a message from ONE person's view only (customer, or one staff/admin member).
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_message_hidden (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        viewer_type VARCHAR(10) NOT NULL,
        viewer_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_viewer (message_id, viewer_type, viewer_id),
        KEY idx_viewer (viewer_type, viewer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_message_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        reactor_type VARCHAR(10) NOT NULL,
        reactor_id INT NOT NULL,
        emoji VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reactor (message_id, reactor_type, reactor_id),
        KEY idx_message (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** A support-thread message of this customer, or null. */
function messaging_thread_message($conn, $message_id, $customer_id) {
    $filter = messaging_support_thread_filter('m');
    $mid = (int) $message_id;
    $cid = (int) $customer_id;
    $stmt = $conn->prepare("SELECT m.id, m.customer_id FROM tbl_messages m WHERE m.id = ? AND m.customer_id = ? AND $filter LIMIT 1");
    $stmt->bind_param('ii', $mid, $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Add, change or remove (same emoji again, or '') a reaction.
 * $reactor_type: 'customer' (reactor_id = customers.id) or 'staff' (reactor_id = users.id, admin or staff).
 * Returns false when the message isn't in this customer's support thread or the emoji isn't allowed.
 */
function messaging_toggle_reaction($conn, $message_id, $customer_id, $reactor_type, $reactor_id, $emoji) {
    messaging_ensure_extras_schema($conn);
    $reactor_type = $reactor_type === 'customer' ? 'customer' : 'staff';
    $reactor_id = (int) $reactor_id;
    if ($reactor_id <= 0 || !messaging_thread_message($conn, $message_id, $customer_id)) return false;
    if ($emoji !== '' && !in_array($emoji, MESSAGE_REACTIONS, true)) return false;
    return messaging_apply_reaction($conn, (int) $message_id, $reactor_type, $reactor_id, $emoji);
}

/**
 * Customer <-> rider chat: a message of that thread, or null. Pass $rider_user_id to require that
 * rider's thread (the rider's own view); 0 = any rider thread of this customer (the customer's view).
 */
function messaging_rider_thread_message($conn, $message_id, $customer_id, $rider_user_id = 0) {
    $filter = messaging_rider_thread_filter('m');
    $mid = (int) $message_id;
    $cid = (int) $customer_id;
    $rid = (int) $rider_user_id;
    $stmt = $conn->prepare("SELECT m.id, m.customer_id, m.user_id FROM tbl_messages m
                            WHERE m.id = ? AND m.customer_id = ? AND $filter AND (? = 0 OR m.user_id = ?) LIMIT 1");
    $stmt->bind_param('iiii', $mid, $cid, $rid, $rid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Reaction in the customer <-> rider chat. The customer reacts as 'customer' (customers.id); the rider
 * as 'staff' with their users.id (same id space as staff, so the shared reaction display just works).
 */
function messaging_rider_toggle_reaction($conn, $message_id, $customer_id, $rider_user_id, $reactor_type, $reactor_id, $emoji) {
    messaging_ensure_extras_schema($conn);
    $reactor_type = $reactor_type === 'customer' ? 'customer' : 'staff';
    if ((int) $reactor_id <= 0 || !messaging_rider_thread_message($conn, $message_id, $customer_id, $rider_user_id)) return false;
    if ($emoji !== '' && !in_array($emoji, MESSAGE_REACTIONS, true)) return false;
    return messaging_apply_reaction($conn, (int) $message_id, $reactor_type, (int) $reactor_id, $emoji);
}

/** Sets, changes or removes (same emoji again, or '') one person's reaction on a message. */
function messaging_apply_reaction($conn, $mid, $reactor_type, $reactor_id, $emoji) {
    $mid = (int) $mid;
    $reactor_id = (int) $reactor_id;
    $cur = $conn->prepare("SELECT emoji FROM tbl_message_reactions WHERE message_id = ? AND reactor_type = ? AND reactor_id = ?");
    $cur->bind_param('isi', $mid, $reactor_type, $reactor_id);
    $cur->execute();
    $existing = $cur->get_result()->fetch_assoc();
    $cur->close();

    if ($emoji === '' || ($existing && $existing['emoji'] === $emoji)) {
        $del = $conn->prepare("DELETE FROM tbl_message_reactions WHERE message_id = ? AND reactor_type = ? AND reactor_id = ?");
        $del->bind_param('isi', $mid, $reactor_type, $reactor_id);
        $del->execute();
        $del->close();
        return true;
    }
    $up = $conn->prepare("INSERT INTO tbl_message_reactions (message_id, reactor_type, reactor_id, emoji) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = CURRENT_TIMESTAMP");
    $up->bind_param('isis', $mid, $reactor_type, $reactor_id, $emoji);
    $up->execute();
    $up->close();
    return true;
}

/** Text shown in place of a message its sender deleted for everyone ("unsent"). */
const MESSAGE_UNSENT_TEXT = 'This message was deleted';
const MESSAGE_UNSENT_OWN_TEXT = 'You deleted this message';

/**
 * Delete for me: hides one support-chat message from THIS viewer only; everyone else still sees it.
 * Any message in the viewer's thread can be hidden (theirs, the other side's, or a deleted placeholder).
 * $viewer_type: 'customer' (viewer_id = customers.id) or 'staff' (viewer_id = users.id).
 */
function messaging_hide_for_me($conn, $message_id, $customer_id, $viewer_type, $viewer_id) {
    messaging_ensure_extras_schema($conn);
    $viewer_type = $viewer_type === 'customer' ? 'customer' : 'staff';
    $mid = (int) $message_id;
    $vid = (int) $viewer_id;
    if ($mid <= 0 || $vid <= 0 || !messaging_thread_message($conn, $mid, $customer_id)) return false;
    if ($viewer_type === 'customer' && $vid !== (int) $customer_id) return false;
    $ins = $conn->prepare("INSERT IGNORE INTO tbl_message_hidden (message_id, viewer_type, viewer_id) VALUES (?, ?, ?)");
    $ins->bind_param('isi', $mid, $viewer_type, $vid);
    $ins->execute();
    $ins->close();
    // A customer hiding an unread shop reply shouldn't keep an unread badge for it.
    if ($viewer_type === 'customer') {
        $conn->query("UPDATE tbl_messages SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = $mid AND sender_type <> 'customer' AND is_read = 0");
    }
    return true;
}

/** Drops the messages this viewer deleted for themselves. */
function messaging_remove_hidden($conn, array $messages, $viewer_type, $viewer_id) {
    if (!$messages || (int) $viewer_id <= 0) return $messages;
    messaging_ensure_extras_schema($conn);
    $viewer_type = $viewer_type === 'customer' ? 'customer' : 'staff';
    $ids = implode(',', array_map(static fn($m) => (int) $m['id'], $messages));
    $hidden = [];
    $st = $conn->prepare("SELECT message_id FROM tbl_message_hidden WHERE viewer_type = ? AND viewer_id = ? AND message_id IN ($ids)");
    $vid = (int) $viewer_id;
    $st->bind_param('si', $viewer_type, $vid);
    $st->execute();
    foreach ($st->get_result()->fetch_all() as $r) $hidden[(int) $r[0]] = true;
    $st->close();
    if (!$hidden) return $messages;
    return array_values(array_filter($messages, static fn($m) => !isset($hidden[(int) $m['id']])));
}

/** Did this viewer send this support-chat message? ('customer' = customers.id, 'staff' = users.id) */
function messaging_is_own_message(array $m, $viewer_type, $viewer_id) {
    if ($viewer_type === 'customer') {
        return ($m['sender_type'] ?? '') === 'customer' && empty($m['user_id']) && (int) $m['customer_id'] === (int) $viewer_id;
    }
    return in_array($m['sender_type'] ?? '', ['admin', 'staff'], true) && (int) ($m['user_id'] ?? 0) === (int) $viewer_id;
}

/**
 * Unsend: the SENDER takes back their own support-chat message, for everyone. The text is cleared,
 * its reactions removed, its notification preview replaced, and it no longer counts as unread.
 * $actor_type: 'customer' (actor_id = customers.id) or 'staff' (actor_id = users.id, admin or staff).
 * Returns false when the message isn't this actor's own (or is already unsent).
 */
function messaging_unsend($conn, $message_id, $customer_id, $actor_type, $actor_id) {
    messaging_ensure_extras_schema($conn);
    $actor_type = $actor_type === 'customer' ? 'customer' : 'staff';
    $mid = (int) $message_id;
    $cid = (int) $customer_id;
    if ($mid <= 0 || (int) $actor_id <= 0 || !messaging_thread_message($conn, $mid, $cid)) return false;

    $st = $conn->prepare("SELECT id, customer_id, user_id, sender_type, unsent_at FROM tbl_messages WHERE id = ? LIMIT 1");
    $st->bind_param('i', $mid);
    $st->execute();
    $m = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$m || $m['unsent_at'] !== null || !messaging_is_own_message($m, $actor_type, $actor_id)) return false;

    $up = $conn->prepare("UPDATE tbl_messages SET message = '', unsent_at = NOW(), is_read = 1 WHERE id = ? AND unsent_at IS NULL");
    $up->bind_param('i', $mid);
    $up->execute();
    $changed = $up->affected_rows > 0;
    $up->close();
    if (!$changed) return false;

    $del = $conn->prepare("DELETE FROM tbl_message_reactions WHERE message_id = ?");
    $del->bind_param('i', $mid);
    $del->execute();
    $del->close();
    // The "new message" notification carried a preview of the text: take that back too.
    $text = MESSAGE_UNSENT_TEXT;
    $note = $conn->prepare("UPDATE notifications SET message = ? WHERE reference_table = 'tbl_messages' AND reference_id = ?");
    if ($note) {
        $note->bind_param('si', $text, $mid);
        $note->execute();
        $note->close();
    }
    return true;
}

/** Messages straight from the database (first page render): show unsent ones as "Message unsent". */
function messaging_mask_unsent(array $messages) {
    foreach ($messages as &$m) {
        if (!empty($m['unsent_at'])) $m['message'] = MESSAGE_UNSENT_TEXT;
    }
    unset($m);
    return $messages;
}

/**
 * Adds to each message: 'reply_to' => [id, sender_type, sender_name, message] (or null),
 * 'reactions' => [[emoji, count, mine]], 'unsent' (bool) and 'can_unsend' (the viewer sent it).
 * $viewer_type/$viewer_id mark the viewer's own reaction / own messages.
 */
function messaging_attach_extras($conn, array $messages, $viewer_type, $viewer_id) {
    if (!$messages) return $messages;
    messaging_ensure_extras_schema($conn);
    $viewer_type = $viewer_type === 'customer' ? 'customer' : 'staff';
    $messages = messaging_remove_hidden($conn, $messages, $viewer_type, $viewer_id);
    if (!$messages) return $messages;
    $ids = array_map(static fn($m) => (int) $m['id'], $messages);
    $reply_ids = array_values(array_unique(array_filter(array_map(static fn($m) => (int) ($m['reply_to_id'] ?? 0), $messages))));

    $replies = [];
    if ($reply_ids) {
        $in = implode(',', $reply_ids);
        $res = $conn->query("SELECT m.id, m.customer_id, m.sender_type, m.message, m.unsent_at,
                    CASE WHEN m.sender_type = 'customer' THEN c.name
                         WHEN m.user_id IS NULL THEN 'DPS Express'
                         ELSE TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) END AS sender_name
                FROM tbl_messages m
                LEFT JOIN customers c ON c.id = m.customer_id
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.id IN ($in)");
        while ($res && ($r = $res->fetch_assoc())) {
            $text = $r['unsent_at'] !== null ? MESSAGE_UNSENT_TEXT : html_entity_decode(strip_tags((string) $r['message']), ENT_QUOTES, 'UTF-8');
            $replies[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'customer_id' => (int) $r['customer_id'],
                'sender_type' => (string) $r['sender_type'],
                'sender_name' => trim((string) $r['sender_name']) ?: 'Staff',
                'message' => mb_strlen($text) > 140 ? mb_substr($text, 0, 137) . '...' : $text,
            ];
        }
    }

    $reacts = [];
    $in = implode(',', $ids);
    $res = $conn->query("SELECT message_id, reactor_type, reactor_id, emoji FROM tbl_message_reactions WHERE message_id IN ($in) ORDER BY created_at ASC");
    while ($res && ($r = $res->fetch_assoc())) {
        $mid = (int) $r['message_id'];
        $e = (string) $r['emoji'];
        if (!isset($reacts[$mid][$e])) $reacts[$mid][$e] = ['emoji' => $e, 'count' => 0, 'mine' => false];
        $reacts[$mid][$e]['count']++;
        if ($r['reactor_type'] === $viewer_type && (int) $r['reactor_id'] === (int) $viewer_id) {
            $reacts[$mid][$e]['mine'] = true;
        }
    }

    foreach ($messages as &$m) {
        $rid = (int) ($m['reply_to_id'] ?? 0);
        $m['reply_to'] = ($rid && isset($replies[$rid]) && $replies[$rid]['customer_id'] === (int) $m['customer_id']) ? $replies[$rid] : null;
        $m['reactions'] = array_values($reacts[(int) $m['id']] ?? []);
        $own = messaging_is_own_message($m, $viewer_type, $viewer_id);
        $m['unsent'] = !empty($m['unsent_at']);
        $m['can_unsend'] = $own && !$m['unsent'];
        if ($m['unsent']) {
            $m['message'] = $own ? MESSAGE_UNSENT_OWN_TEXT : MESSAGE_UNSENT_TEXT;
            $m['reply_to'] = null;
            $m['reactions'] = [];
        }
    }
    unset($m);
    return $messages;
}

/**
 * Builds a compact, FACTUAL data snapshot for one customer, straight from
 * the real database - never invented - used to ground the AI Auto-Reply
 * (Smart Customer Assistance) so it can answer stock/price/order/points
 * questions with real numbers instead of guessing. No internal DB ids,
 * table names, or SQL are included - only customer-facing facts (names,
 * order numbers, amounts) that already appear elsewhere in the app.
 */
function build_customer_ai_context($conn, $customer_id, $raw_message) {
    $lines = [];

    // --- Identity + real loyalty points (customers.loyalty_points is the
    // same synced balance shown everywhere else in the app) ---
    $cust_stmt = $conn->prepare("SELECT name, loyalty_points FROM customers WHERE id = ?");
    $cust_stmt->bind_param("i", $customer_id);
    $cust_stmt->execute();
    $customer = $cust_stmt->get_result()->fetch_assoc();
    $cust_stmt->close();

    if ($customer) {
        $lines[] = "Customer name: " . $customer['name'];
        $lines[] = "Customer's current loyalty points balance: " . number_format((float)$customer['loyalty_points'], 2);
    }

    // --- Real reward catalog (not expired) ---
    $rewards_res = $conn->query(
        "SELECT name, points, stock FROM rewards
         WHERE (expiry_date IS NULL OR expiry_date >= CURDATE())
         ORDER BY points ASC LIMIT 8"
    );
    if ($rewards_res && $rewards_res->num_rows > 0) {
        $reward_lines = [];
        while ($r = $rewards_res->fetch_assoc()) {
            $avail = ((int)$r['stock'] > 0) ? "in stock" : "currently out of stock";
            $reward_lines[] = $r['name'] . " (" . number_format((float)$r['points'], 0) . " pts, " . $avail . ")";
        }
        $lines[] = "Rewards the customer can redeem with points: " . implode('; ', $reward_lines);
    }

    // --- Customer's own recent orders (order_number is already
    // customer-facing everywhere else - never the internal numeric id) ---
    $orders_stmt = $conn->prepare(
        "SELECT order_number, order_status, fulfillment_type, total, created_at
         FROM tbl_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3"
    );
    $orders_stmt->bind_param("i", $customer_id);
    $orders_stmt->execute();
    $orders_res = $orders_stmt->get_result();
    $order_lines = [];
    while ($o = $orders_res->fetch_assoc()) {
        $status_readable = ucwords(str_replace('_', ' ', $o['order_status']));
        $order_lines[] = $o['order_number'] . ": " . $status_readable . " (" . $o['fulfillment_type']
            . ", placed " . date('M j, Y', strtotime($o['created_at'])) . ", total PHP " . number_format((float)$o['total'], 2) . ")";
    }
    $orders_stmt->close();
    $lines[] = !empty($order_lines)
        ? "Customer's recent orders (most recent first): " . implode(' | ', $order_lines)
        : "Customer has no past orders on file.";

    // --- Product/variant lookup, but ONLY for keywords the customer actually
    // typed - never a guessed product. This is what lets the assistant answer
    // "is the 1.5kg available?" or "magkano ang Felix?" with real numbers. ---
    $stopwords = ['the','and','for','are','you','your','have','has','with','this','that','yung','ang','mga',
        'po','ba','ko','sa','ito','naman','pa','available','stock','price','presyo','magkano','meron','mayroon','order','status','points'];
    preg_match_all('/[a-zA-Z0-9.]{3,}/', $raw_message, $matches);
    $keywords = array_slice(array_diff(array_unique(array_map('strtolower', $matches[0] ?? [])), $stopwords), 0, 6);

    $product_lines = [];
    if (!empty($keywords)) {
        $conditions = [];
        $params = [];
        $types = '';
        foreach ($keywords as $kw) {
            $conditions[] = "(p.name LIKE ? OR p.category LIKE ? OR p.brand LIKE ? OR v.size LIKE ?)";
            $like = '%' . $kw . '%';
            array_push($params, $like, $like, $like, $like);
            $types .= 'ssss';
        }
        $sql = "SELECT DISTINCT p.id, p.name, p.price AS base_price, p.stock AS base_stock
                FROM tbl_product_inventory p
                LEFT JOIN tbl_product_variants v ON v.product_id = p.id
                WHERE " . implode(' OR ', $conditions) . "
                LIMIT 5";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($p = $res->fetch_assoc()) {
                $variant_stmt = $conn->prepare("SELECT size, price, stock FROM tbl_product_variants WHERE product_id = ?");
                $variant_stmt->bind_param("i", $p['id']);
                $variant_stmt->execute();
                $vres = $variant_stmt->get_result();
                $variant_parts = [];
                while ($v = $vres->fetch_assoc()) {
                    $vstock = (int)$v['stock'] > 0 ? "in stock" : "out of stock";
                    $variant_parts[] = $v['size'] . " - PHP " . number_format((float)$v['price'], 2) . " (" . $vstock . ")";
                }
                $variant_stmt->close();

                $base_stock_text = ((int)$p['base_stock'] > 0) ? "in stock" : "out of stock";
                $line = $p['name'] . ": base price PHP " . number_format((float)$p['base_price'], 2) . " (" . $base_stock_text . ")";
                if (!empty($variant_parts)) {
                    $line .= "; variants: " . implode(', ', $variant_parts);
                }
                $product_lines[] = $line;
            }
            $stmt->close();
        }
    }
    if (!empty($product_lines)) {
        $lines[] = "Products matching this question, with REAL current stock/price: " . implode(' || ', $product_lines);
    }

    // Real, static facts from the checkout schema (payment_method enum) - not invented.
    $lines[] = "Supported payment methods: Cash on Delivery (COD), GCash, Pay at Shop, Bank Transfer.";

    return implode("\n", $lines);
}

/**
 * Customer-triggered "Connect to Staff" handoff. Reuses the EXACT existing
 * suppression mechanism a real staff reply already relies on
 * (customers.admin_typing_at) to silence the AI Auto-Reply for the same
 * 30-minute window, and posts one system message into the SAME tbl_messages
 * thread - so staff see the request in their existing conversation, with
 * no new table and no duplicate conversation created.
 */
function request_staff_handoff($conn, $customer_id) {
    messaging_ensure_schema($conn);

    $suppress_stmt = $conn->prepare("UPDATE customers SET admin_typing_at = NOW() WHERE id = ?");
    $suppress_stmt->bind_param("i", $customer_id);
    $suppress_stmt->execute();
    $suppress_stmt->close();

    // Give staff quick, real context - order NUMBER only, never the internal id.
    $order_stmt = $conn->prepare("SELECT order_number, order_status FROM tbl_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 1");
    $order_stmt->bind_param("i", $customer_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    $note = "Customer requested to speak with a staff member.";
    if ($order) {
        $status_readable = ucwords(str_replace('_', ' ', $order['order_status']));
        $note .= " (Most recent order: " . $order['order_number'] . " - " . $status_readable . ")";
    }

    $stmt = $conn->prepare("INSERT INTO tbl_messages (customer_id, sender_type, message) VALUES (?, 'system', ?)");
    $stmt->bind_param("is", $customer_id, $note);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Real-time Customer -> Admin/Staff new-message notification.
 *
 * Reuses the EXISTING per-user notifications system end to end - the same
 * `notifications` table + notifications_create() already used for rider/
 * points/pickup notifications, whose bell-icon/badge/panel UI is already
 * built and proven on modules/staff/loyalty_points.php (notifications_get_recent(),
 * notifications_get_unread_count(), notifications_mark_all_read()) - and its
 * already-built-in WebSocket push (ws_push_notification(), called from
 * inside notifications_create() whenever user_id > 0). No second
 * notification system, no new table, no new WS message type.
 *
 * One row per real admin/staff user (this is a shared inbox - any of them
 * can reply, matching how the sidebar's Messages badge is already a shared
 * count, not per-recipient).
 */
function notify_staff_new_customer_message($conn, $customer_id, $message_id, $raw_message) {
    $cust_stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
    $cust_stmt->bind_param("i", $customer_id);
    $cust_stmt->execute();
    $customer_name = $cust_stmt->get_result()->fetch_assoc()['name'] ?? 'A customer';
    $cust_stmt->close();

    $preview = mb_strlen($raw_message) > 140 ? (mb_substr($raw_message, 0, 140) . '...') : $raw_message;

    $staff_res = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'staff')");
    if (!$staff_res) {
        return;
    }
    while ($staff = $staff_res->fetch_assoc()) {
        notifications_create($conn, [
            'user_id'         => (int) $staff['id'],
            'type'            => 'new_customer_message',
            'channel'         => 'in_app',
            'title'           => 'New message from ' . $customer_name,
            'message'         => $preview,
            'reference_table' => 'tbl_messages',
            'reference_id'    => (int) $message_id,
        ]);
    }
}

/**
 * Real-time Admin/Staff -> Customer new-message notification. Mirrors
 * notify_staff_new_customer_message() above exactly, just the other
 * direction - same notifications table, same notifications_create(), same
 * WS push. Only called for a real human reply (see the one call site in
 * send_message()); the AI auto-reply path never calls this.
 */
function notify_customer_new_staff_message($conn, $customer_id, $message_id, $raw_message) {
    $preview = mb_strlen($raw_message) > 140 ? (mb_substr($raw_message, 0, 140) . '...') : $raw_message;

    notifications_create($conn, [
        'customer_id'     => (int) $customer_id,
        'type'            => 'new_staff_message',
        'channel'         => 'in_app',
        'title'           => 'New message from support',
        'message'         => $preview,
        'reference_table' => 'tbl_messages',
        'reference_id'    => (int) $message_id,
    ]);
}

/**
 * Send a message
 */
function send_message($customer_id, $user_id, $sender_type, $message, $reply_to_id = null) {
    global $conn;
    
    // Auto-fix if table is missing
    messaging_ensure_schema($conn);

    // I-save muna ang orihinal na mensahe para sa AI bago i-htmlspecialchars
    $raw_message = $message;
    
    // FIX 1: I-encode lamang ang mensahe kung ito ay nanggaling sa customer para sa XSS defense.
    // Kapag AI ang nagreply mamaya, iiwasan nating i-encode para hindi maging HTML entities ang mga bantas.
    if ($sender_type === 'customer') {
        $message = htmlspecialchars($message);
    } else if ($user_id !== null) {
        // Detect if human is taking over from AI agent (based on the 30-minute auto-reply rule)
        $status_stmt = $conn->prepare("
            SELECT (
                (admin_typing_at IS NULL OR admin_typing_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                AND NOT EXISTS (
                    SELECT 1 FROM tbl_messages 
                    WHERE customer_id = ? 
                    AND sender_type IN ('admin', 'staff') 
                    AND user_id IS NOT NULL
                    AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                )
            ) as is_taking_over FROM customers WHERE id = ?");
        $status_stmt->bind_param("ii", $customer_id, $customer_id);
        $status_stmt->execute();
        $status_res = $status_stmt->get_result()->fetch_assoc();
        if ((bool)($status_res['is_taking_over'] ?? true)) {
            $takeover_msg = "You're no longer chatting with an AI agent. A staff member has joined the conversation.";
            $takeover_stmt = $conn->prepare("INSERT INTO tbl_messages (customer_id, sender_type, message) VALUES (?, 'system', ?)");
            $takeover_stmt->bind_param("is", $customer_id, $takeover_msg);
            $takeover_stmt->execute();
            $takeover_stmt->close();
        }
        $status_stmt->close();
    }
    
    $stmt = $conn->prepare("INSERT INTO tbl_messages (customer_id, user_id, sender_type, message) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $customer_id, $user_id, $sender_type, $message);
    
    if ($stmt->execute()) {
        $new_message_id = $conn->insert_id; // capture before any other query runs on $conn
        $stmt->close();

        // Reply: only to a message in this same customer's support thread.
        if ((int) $reply_to_id > 0 && messaging_thread_message($conn, (int) $reply_to_id, $customer_id)) {
            $rt = (int) $reply_to_id;
            $nid = (int) $new_message_id;
            $conn->query("UPDATE tbl_messages SET reply_to_id = $rt WHERE id = $nid");
        }

        // Suppression Logic: If a human (admin/staff) sends a message, update the typing
        // timestamp to suppress the AI for the next 30 minutes.
        if ($sender_type !== 'customer' && $user_id !== null) {
            $suppress_stmt = $conn->prepare("UPDATE customers SET admin_typing_at = NOW() WHERE id = ?");
            $suppress_stmt->bind_param("i", $customer_id);
            $suppress_stmt->execute();
            $suppress_stmt->close();

            // Real-time Admin/Staff -> Customer notification (bell/badge/sound),
            // mirroring notify_staff_new_customer_message() below for the other
            // direction. Deliberately reuses this exact condition (real human,
            // user_id set) rather than a broader "$sender_type !== 'customer'"
            // check, so it can never fire for the AI's own auto-reply insert
            // (which happens in a completely separate code path below, with
            // user_id always null) - the AI flow is not touched by this at all.
            notify_customer_new_staff_message($conn, $customer_id, $new_message_id, $raw_message);
        }

        // Real-time Customer -> Admin/Staff notification (bell/badge/sound).
        // See notify_staff_new_customer_message() - reuses the existing
        // notifications table + WS push, nothing new.
        if ($sender_type === 'customer') {
            notify_staff_new_customer_message($conn, $customer_id, $new_message_id, $raw_message);
        }

        // --- AI AUTO-REPLY LOGIC ---
        // Kung ang nagpadala ay customer, magre-reply ang AI nang awtomatiko
        if ($sender_type === 'customer') {
            // Check if Admin is "Offline": 
            // 1. No typing activity in the last 30 minutes.
            // 2. No human messages (user_id IS NOT NULL) sent by staff in the last 30 minutes.
            $status_stmt = $conn->prepare("
                SELECT (
                    (admin_typing_at IS NULL OR admin_typing_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                    AND NOT EXISTS (
                        SELECT 1 FROM tbl_messages 
                        WHERE customer_id = ? 
                        AND sender_type IN ('admin', 'staff') 
                        AND user_id IS NOT NULL
                        AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    )
                ) as is_offline FROM customers WHERE id = ?");
            $status_stmt->bind_param("ii", $customer_id, $customer_id);
            $status_stmt->execute();
            $status_res = $status_stmt->get_result()->fetch_assoc();
            $isOffline = (bool)($status_res['is_offline'] ?? true);
            $status_stmt->close();

            if ($isOffline) {
                // Debug: ensure AI is actually being triggered (check server logs)
                error_log("AI Auto-Reply Triggered for customer_id={$customer_id}. raw_message=" . substr($raw_message, 0, 120));
                // Fetch best-selling available products to provide as context to AI
                $best_sellers_query = "
                    SELECT p.name, p.price
                    FROM tbl_product_inventory p
                    LEFT JOIN tbl_order_items oi ON p.id = oi.product_id
                    WHERE p.stock > 0
                    GROUP BY p.id 
                    ORDER BY COUNT(oi.id) DESC
                    LIMIT 5";
                $bs_res = $conn->query($best_sellers_query);
                $bs_list = [];
                if ($bs_res) {
                    while ($bs_row = $bs_res->fetch_assoc()) {
                        $bs_list[] = $bs_row['name'] . " (PHP " . number_format($bs_row['price'], 2) . ")";
                    }
                }
                $best_sellers_text = !empty($bs_list) ? implode(", ", $bs_list) : "No specific data available right now";

                // Real, per-customer data snapshot (stock/price/order status/points/rewards)
                // so the AI answers with actual facts instead of guessing. See
                // build_customer_ai_context() above - it never invents numbers.
                $customer_data_context = build_customer_ai_context($conn, $customer_id, $raw_message);

                // Ihanda ang data package na inaasahan ng gemini_helper natin
                $stats_summary = [
                    'context' => 'customer_inquiry',
                    'system' => 'Customer Management and Loyalty System for DARIUS POULTRY SUPPLY & GEN. MERCHANDISE',
                    'persona' => 'Darius Poultry Supply Virtual Assistant - Friendly, knowledgeable, and animal-focused.',
                    'instructions' => "Greet the customer politely and answer concisely using ONLY the REAL DATA below for facts about stock, prices, orders, or points - never invent numbers. "
                        . "Our product categories include Dog Food, Cat Food, Chicken, Chicken Feed, Dog Essentials, Cat Essentials, Dog & Cat Medicine, and Skin Care & Treatment - if a customer asks whether we carry something in one of these categories, confirm we do, but still use the REAL DATA below (never invented numbers) for any specific stock, price, or product name. "
                        . "If the customer asks for the 'best products', recommendations, or what is popular, you MUST only suggest from this list of best-selling available products: " . $best_sellers_text . ". "
                        . "If answering needs a fact that is NOT present in the REAL DATA below, say so honestly and end your reply with exactly this sentence: \"Would you like me to connect you with our staff?\" "
                        . "Never mention database IDs, tables, SQL, or internal systems.\n\nREAL DATA:\n" . $customer_data_context,
                    'message' => $raw_message
                ];
                
                // Tawagin ang Gemini API gamit ang inayos nating helper
                $ai_reply = getGeminiBusinessInsight($stats_summary);
                
                // Kung matagumpay ang AI at walang error prefix, i-save ang reply sa database
                if ($ai_reply && strpos($ai_reply, 'AI Error:') === false && strpos($ai_reply, 'Could not connect') === false) {
                    $ai_sender_type = 'admin'; // Isave natin bilang admin/staff para lumabas sa UI ng customer
                    $ai_user_id = null;        // Null dahil system/AI ang nagreply, walang physical staff id
                    
                    $ai_stmt = $conn->prepare("INSERT INTO tbl_messages (customer_id, user_id, sender_type, message) VALUES (?, ?, ?, ?)");
                    $ai_reply_prefixed = "[AI Auto-Reply]: " . $ai_reply;
                    $ai_stmt->bind_param("iiss", $customer_id, $ai_user_id, $ai_sender_type, $ai_reply_prefixed);
                    $ai_stmt->execute();
                    $ai_stmt->close();
                } else {
                    error_log("Automation Auto-Reply Failed: " . $ai_reply);
                }
            }
        }
        return true;
    }
    
    $stmt->close();
    return false;
}

/**
 * Where clause fragment that identifies the CUSTOMER <-> ADMIN/STAFF support
 * thread only. Rider messages (sender_type='rider') and customer messages sent
 * to a rider (customer with a user_id set = the rider's user id) are EXCLUDED.
 */
function messaging_support_thread_filter($alias = 'm') {
    $a = $alias;
    return "($a.sender_type IN ('admin','staff','system') OR ($a.sender_type = 'customer' AND $a.user_id IS NULL))";
}

/**
 * Where clause fragment that identifies the CUSTOMER <-> RIDER thread only.
 * Both customer messages sent TO a rider and rider replies carry the rider's
 * `user_id`, so we can isolate this thread by `user_id` regardless of whether
 * the customer also has a support thread (which uses user_id = NULL).
 */
function messaging_rider_thread_filter($alias = 'm') {
    $a = $alias;
    return "($a.sender_type IN ('customer','rider') AND $a.user_id IS NOT NULL)";
}

/**
 * Get all messages for a customer (support/helpdesk thread only).
 * Rider <-> customer conversations are excluded to keep chats isolated.
 */
function get_customer_messages($customer_id) {
    global $conn;
    
    // Auto-fix if table is missing
    messaging_ensure_schema($conn);

    $filter = messaging_support_thread_filter('m');
    $stmt = $conn->prepare("
        SELECT m.*, 
               CASE 
                   WHEN m.sender_type = 'customer' THEN c.name
                   WHEN m.user_id IS NULL AND m.sender_type IN ('admin','staff') THEN 'DPS Express'
                   ELSE CONCAT(u.first_name, ' ', u.last_name)
               END as sender_name,
               u.role
        FROM tbl_messages m
        LEFT JOIN customers c ON m.customer_id = c.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.customer_id = ?
          AND $filter
        ORDER BY m.created_at DESC
    ");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // The customer's own "Delete for me" messages are left out.
    return messaging_remove_hidden($conn, messaging_mask_unsent($messages), 'customer', (int) $customer_id);
}

/**
 * Get unread message count for customer (support/helpdesk thread only).
 */
function get_unread_count_customer($customer_id) {
    global $conn;

    $filter = messaging_support_thread_filter('m');
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM tbl_messages m
         WHERE m.customer_id = ?
           AND m.is_read = 0
           AND NOT (m.sender_type = 'customer')
           AND $filter"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'];
}

/**
 * Get all messages for a staff/admin member
 */
function get_staff_messages($user_id) {
    global $conn;

    $filter = messaging_support_thread_filter('m');

    $stmt = $conn->prepare("
        SELECT m.*, 
               c.name as customer_name,
               CASE 
                   WHEN m.sender_type = 'customer' THEN c.name
                   ELSE CONCAT(u.first_name, ' ', u.last_name)
               END as sender_name
        FROM tbl_messages m
        LEFT JOIN customers c ON m.customer_id = c.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.customer_id IN (
            SELECT DISTINCT customer_id FROM tbl_messages WHERE $filter
        )
          AND $filter
        ORDER BY m.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return messaging_mask_unsent($messages); // unsent messages show as "Message unsent"
}

/**
 * Get the customer <-> support conversation.
 *
 * The admin/staff support inbox is ONE shared thread per customer: every admin
 * and every staff member sees the same messages and can reply in the same
 * conversation. The customer already sees this unified thread via
 * get_customer_messages().
 *
 * $user_id is still accepted (callers pass their own id) but is NOT used to
 * scope which rows come back — the support-thread filter already isolates this
 * conversation from rider messages, so there is nothing more to narrow by.
 */
function get_conversation($customer_id, $user_id = null) {
    global $conn;

    $filter = messaging_support_thread_filter('m');

    $stmt = $conn->prepare("
        SELECT m.*,
               CASE
                   WHEN m.sender_type = 'customer' THEN c.name
                   ELSE CONCAT(u.first_name, ' ', u.last_name)
               END as sender_name
        FROM tbl_messages m
        LEFT JOIN customers c ON m.customer_id = c.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.customer_id = ?
          AND $filter
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $customer_id);

    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // The viewing staff member's "Delete for me" messages are left out (no viewer = everything).
    return messaging_remove_hidden($conn, messaging_mask_unsent($messages), 'staff', (int) $user_id);
}

/**
 * Mark message as read
 */
function mark_as_read($message_id) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE tbl_messages SET is_read = 1, read_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $message_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Mark all messages as read for a customer
 */
function mark_all_as_read($customer_id) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE tbl_messages SET is_read = 1, read_at = NOW() WHERE customer_id = ? AND is_read = 0");
    $stmt->bind_param("i", $customer_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Get unread count for staff
 */
function get_unread_count_staff($user_id) {
    global $conn;

    // Count unread customer messages in the SUPPORT thread only.
    // The EXISTS check matters: get_customers_with_messages() below only
    // ever lists threads for a real customers row (FROM customers c WHERE
    // EXISTS ...) — a message whose customer_id doesn't match any customer
    // (e.g. a stray customer_id = 0 row from an unrelated bug elsewhere)
    // could never be opened or marked read through the admin Messages UI,
    // which would leave this badge permanently stuck. Counting only
    // messages that are actually reachable keeps the two in sync.
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM tbl_messages m
         WHERE m.is_read = 0
           AND m.sender_type = 'customer'
           AND m.user_id IS NULL
           AND EXISTS (SELECT 1 FROM customers c WHERE c.id = m.customer_id)"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['count'];
}

/**
 * Get distinct customers with messages
 */
function get_customers_with_messages() {
    global $conn;

    // Only support-thread messages (customer <-> admin/staff) count for the
    // admin messaging list. Rider messages are excluded.
    $stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name, c.email,
               (SELECT COUNT(*) FROM tbl_messages m2
                WHERE m2.customer_id = c.id AND m2.is_read = 0 AND m2.sender_type = 'customer' AND m2.user_id IS NULL) as unread_count,
               (SELECT MAX(m3.created_at) FROM tbl_messages m3
                WHERE m3.customer_id = c.id
                  AND (m3.sender_type IN ('admin','staff','system') OR (m3.sender_type = 'customer' AND m3.user_id IS NULL))) as last_message_time
        FROM customers c
        WHERE EXISTS (
            SELECT 1 FROM tbl_messages m4
            WHERE m4.customer_id = c.id
              AND (m4.sender_type IN ('admin','staff','system') OR (m4.sender_type = 'customer' AND m4.user_id IS NULL))
        )
        ORDER BY last_message_time DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $customers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $customers;
}

/**
 * Delete a message
 */
function delete_message($message_id, $user_id = null, $customer_id = null) {
    global $conn;
    
    if ($user_id && $customer_id) {
        $stmt = $conn->prepare("
            DELETE FROM tbl_messages 
            WHERE id = ? AND (
                (sender_type = 'customer' AND customer_id = ?) OR 
                (sender_type IN ('admin', 'staff') AND user_id = ?)
            )
        ");
        $stmt->bind_param("iii", $message_id, $customer_id, $user_id);
    } elseif ($user_id) {
        $stmt = $conn->prepare("
            DELETE FROM tbl_messages 
            WHERE id = ? AND sender_type IN ('admin', 'staff') AND user_id = ?
        ");
        $stmt->bind_param("ii", $message_id, $user_id);
    } else {
        $stmt = $conn->prepare("
            DELETE FROM tbl_messages 
            WHERE id = ? AND sender_type = 'customer' AND customer_id = ?
        ");
        $stmt->bind_param("ii", $message_id, $customer_id);
    }
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}
?>