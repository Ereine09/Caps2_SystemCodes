<?php
/**
 * Messaging Helper Functions
 * Handles general messages between customers and staff/admin
 */

require_once __DIR__ . '/../config/config.php';
// I-include natin ang gemini helper para magamit ang getGeminiBusinessInsight function
// Siguraduhin na tama ang relative path nito base sa folder structure mo (e.g., helpers/gemini_helper.php)
require_once __DIR__ . '/gemini_helper.php'; 

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
}

/**
 * Send a message
 */
function send_message($customer_id, $user_id, $sender_type, $message) {
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
        $stmt->close();
        
        // Suppression Logic: If a human (admin/staff) sends a message, update the typing 
        // timestamp to suppress the AI for the next 30 minutes.
        if ($sender_type !== 'customer' && $user_id !== null) {
            $suppress_stmt = $conn->prepare("UPDATE customers SET admin_typing_at = NOW() WHERE id = ?");
            $suppress_stmt->bind_param("i", $customer_id);
            $suppress_stmt->execute();
            $suppress_stmt->close();
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
            
                // Ihanda ang data package na inaasahan ng gemini_helper natin
                $stats_summary = [
                    'context' => 'customer_inquiry',
                    'system' => 'Customer Management and Loyalty System for DARIUS POULTRY SUPPLY & GEN. MERCHANDISE',
                    'persona' => 'Darius Poultry Supply Virtual Assistant - Friendly, knowledgeable, and animal-focused.',
                    'instructions' => "Greet the customer politely. Answer their product question concisely. If the customer asks for the 'best products', recommendations, or what is popular, you MUST only suggest from this list of our best-selling available products: " . $best_sellers_text . ". Do not recommend any other products.",
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
    
    return $messages;
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
    
    return $messages;
}

/**
 * Get conversation between customer and staff
 */
function get_conversation($customer_id, $user_id = null) {
    global $conn;

    $filter = messaging_support_thread_filter('m');
    
    if ($user_id) {
        $stmt = $conn->prepare("
            SELECT m.*, 
                   CASE 
                       WHEN m.sender_type = 'customer' THEN c.name
                       ELSE CONCAT(u.first_name, ' ', u.last_name)
                   END as sender_name
            FROM tbl_messages m
            LEFT JOIN customers c ON m.customer_id = c.id
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.customer_id = ? AND (m.user_id = ? OR m.user_id IS NULL)
              AND $filter
            ORDER BY m.created_at ASC
        ");
        $stmt->bind_param("ii", $customer_id, $user_id);
    } else {
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
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $messages;
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
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM tbl_messages m
         WHERE m.is_read = 0
           AND m.sender_type = 'customer'
           AND m.user_id IS NULL"
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