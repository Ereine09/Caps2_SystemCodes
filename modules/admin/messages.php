<?php
session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// JWT Authentication and Verification
$token = getJWTFromCookie();
$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

// Ensure session variables are set from the verified JWT payload
$_SESSION['user_id'] = $payload['user_id'];
$_SESSION['user_role'] = strtolower(trim($payload['role'] ?? ''));

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'staff'])) {
    http_response_code(403);
    exit('Access Denied');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($customer_id > 0 && !empty($message)) {
        if (send_message($customer_id, $user_id, $user_role, $message)) {
            $_SESSION['message'] = 'Message sent successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Failed to send message.';
            $_SESSION['message_type'] = 'error';
        }
        header('Location: messages.php?customer_id=' . $customer_id);
        exit;
    }
}

// Get selected customer
$selected_customer_id = intval($_GET['customer_id'] ?? 0);
$conversation = [];
$selected_customer = null;

if ($selected_customer_id > 0) {
    $conversation = get_conversation($selected_customer_id, $user_id);
    
    // Get customer details
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param("i", $selected_customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_customer = $result->fetch_assoc();
    $stmt->close();
    
    // Mark messages as read
    if ($conversation) {
        foreach ($conversation as $msg) {
            if ($msg['is_read'] == 0 && $msg['sender_type'] == 'customer') {
                mark_as_read($msg['id']);
            }
        }
    }
    
    // Refresh conversation
    $conversation = get_conversation($selected_customer_id, $user_id);
}

// Get all customers with messages
$customers_with_messages = get_customers_with_messages();

// Get unread count
$unread_count = get_unread_count_staff($user_id);

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .message {
            display: flex;
            flex-direction: column;
            width: fit-content;
            max-width: 80%;
            margin-bottom: 4px;
        }
        .message.sent {
            align-self: flex-end;
            align-items: flex-end;
        }
        .message.received {
            align-self: flex-start;
            align-items: flex-start;
        }
        .message-sender {
            font-size: 0.8rem;
            font-weight: 1000;
            color: #4a3e94 !important;
            margin-bottom: 2px;
            padding: 0 5px;
        }
        .message-content {
            padding: 10px 16px;
            border-radius: 18px;
            font-size: 0.95rem;
            line-height: 1.3;
            max-width: 100%;
            word-wrap: break-word;
        }
        .message.sent .message-content {
            background: #4a3e94;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .message.received .message-content {
            background: #e4e6eb;
            color: #050505;
            border-bottom-left-radius: 4px;
        }
        .message-time {
            font-size: 0.65rem;
            color: #bcc0c4;
            margin-top: 0px;
            padding: 0 10px;
        }
        .seen-status {
            font-size: 0.65rem;
            color: #8e8e8e;
            margin-top: 0px;
            padding: 0 10px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
<li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    <div class="main-content">
        <div class="page-title" style="margin-bottom: 25px;">
            <h1>Messaging System</h1>
        </div>
    
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert show <?php echo $_SESSION['message_type']; ?>">
                <?php 
                echo $_SESSION['message'];
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
                ?>
            </div>
        <?php endif; ?> 
    
        <div class="messages-wrapper">
            <!-- Customers List -->
            <div class="customers-list" id="customers-sidebar">
                <div class="customers-list-header" id="sidebar-header">
                    📬 Conversations <?php if ($unread_count > 0) echo "<span class='sidebar-total-unread'>(" . $unread_count . ")</span>"; ?>
                </div>
                
                <div id="conversations-list">
                <?php if (empty($customers_with_messages)): ?>
                    <div class="empty-list">
                        <p>No conversations yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($customers_with_messages as $customer): ?>
                        <div class="customer-item <?php echo ($customer['id'] == $selected_customer_id) ? 'active' : ''; ?>" 
                             onclick="location.href='?customer_id=<?php echo $customer['id']; ?>'">
                            <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                            <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                            <?php if ($customer['unread_count'] > 0): ?>
                                <span class="customer-unread"><?php echo $customer['unread_count']; ?> new</span>
                            <?php endif; ?>
                            <div class="customer-last-msg">
                                <?php echo date('M d, H:i', strtotime($customer['last_message_time'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
    
            <!-- Conversation Area -->
            <div class="conversation-area">
                <?php if ($selected_customer): ?>
                    <div class="conversation-header">
                        <div>
                            <h3><?php echo htmlspecialchars($selected_customer['name']); ?></h3>
                            <p><?php echo htmlspecialchars($selected_customer['email']); ?></p>
                        </div>
                    </div>
    
                    <div class="conversation-messages" id="conversation-messages" style="display: flex; flex-direction: column;">
                        <?php 
                        $last_seen_msg_id = null;
                        if (!empty($conversation)) {
                            foreach ($conversation as $msg) {
                                if (in_array($msg['sender_type'], ['admin', 'staff']) && $msg['is_read'] == 1) {
                                    $last_seen_msg_id = $msg['id'];
                                }
                            }
                        }
                        
                        if (empty($conversation)): ?>
                            <div class="no-conversation">
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversation as $msg): 
                                $is_sent = in_array($msg['sender_type'], ['admin', 'staff']);
                            ?>
                                <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                                    <div class="message-sender">
                                        <?php echo htmlspecialchars($msg['sender_name']); ?>
                                        <?php if (in_array($msg['sender_type'], ['admin', 'staff'])): ?>
                                            <i class="fas fa-user-shield" style="color: #4a3e94; margin-left: 5px;" title="Verified Staff"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-content">
                                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                                    </div>
                                    <?php if ($is_sent && $msg['id'] === $last_seen_msg_id): ?>
                                        <div class="seen-status">Seen</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
    
                    <form method="POST" class="conversation-input" id="chat-form">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="customer_id" value="<?php echo $selected_customer_id; ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <input type="hidden" name="sender_type" value="<?php echo $user_role; ?>">
                        <div class="input-group">
                            <textarea name="message" id="message-input" placeholder="Type your message..." required></textarea>
                            <button type="submit">Send</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="no-conversation">
                        <p>Select a customer to start messaging</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification Sound -->
    <audio id="notification-sound" preload="auto">
        <source src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" type="audio/mpeg">
    </audio>

    <script>
        const conversationMessages = document.getElementById('conversation-messages');
        const selectedCustomerId = <?php echo $selected_customer_id; ?>;

        /**
         * Periodically fetches the latest messages from the API
         */
        async function fetchMessages() {
            if (selectedCustomerId <= 0) return;

            try {
                const response = await fetch(`messaging_api.php?action=get_messages&customer_id=${selectedCustomerId}`);
                const result = await response.json();

                if (result.success && result.data) {
                    renderMessages(result.data);
                }
            } catch (error) {
                console.error("Auto-refresh error:", error);
            }
        }

        /**
         * Renders the fetched messages into the chat container
         */
        function renderMessages(messages) {
            let lastSeenId = null;
            messages.forEach(msg => {
                if (['admin', 'staff'].includes(msg.sender_type) && msg.is_read === 1) {
                    lastSeenId = msg.id;
                }
            });


            const html = messages.map(msg => {
                if (msg.sender_type === 'system') {
                    return `
                        <div class="system-message" style="width: 100%; text-align: center; margin: 15px 0; font-size: 0.8rem; color: #8e8e8e; font-weight: 600;">
                            <span style="background: #f0f2f5; padding: 5px 15px; border-radius: 12px;">${escapeHtml(msg.message)}</span>
                        </div>
                    `;
                }

                const isSent = ['admin', 'staff'].includes(msg.sender_type);
                const seenStatus = (isSent && msg.id === lastSeenId) ? '<div class="seen-status">Seen</div>' : '';
                const time = new Date(msg.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).replace(',', '');
                
                return `
                    <div class="message ${isSent ? 'sent' : 'received'}">
                        <div class="message-sender">${escapeHtml(msg.sender_name)}
                            ${isSent ? '<i class="fas fa-user-shield" style="color: #4a3e94; margin-left: 5px;" title="Verified Staff"></i>' : ''}</div>
                        <div class="message-content">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                        <div class="message-time">${time}</div>
                        ${seenStatus}
                    </div>
                `;
            }).join('');

            if (conversationMessages.innerHTML !== html) {
                const isAtBottom = conversationMessages.scrollHeight - conversationMessages.scrollTop <= conversationMessages.clientHeight + 100;
                conversationMessages.innerHTML = html;
                if (isAtBottom) scrollToBottom();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function scrollToBottom() {
            if (conversationMessages) {
                conversationMessages.scrollTop = conversationMessages.scrollHeight;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            scrollToBottom();
            
            // WebSocket realtime updates (admin)
            setupWs();



            // Handle AJAX form submission to prevent reload on send
            const chatForm = document.getElementById('chat-form');
            if (chatForm) {
                chatForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const formData = new FormData(chatForm);
                    formData.append('action', 'send_message');
                    
                    const msgInput = document.getElementById('message-input');
                    const originalText = msgInput.value;
                    msgInput.value = ''; // Clear input immediately

                    try {
                        const response = await fetch('messaging_api.php?action=send_message', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            // Trigger WS broadcast so other clients can refresh (and the WS server can push an event back)
                            try {
                                if (ws && ws.readyState === WebSocket.OPEN) {
                                    ws.send(JSON.stringify({
                                        type: 'new_message',
                                        customer_id: selectedCustomerId,
                                        message: originalText
                                    }));
                                }
                            } catch (err) {
                                console.error('WS send failed', err);
                            }
                            fetchMessages(); // Force refresh to show new message
                        } else {

                            msgInput.value = originalText;
                            alert("Failed to send: " + result.message);
                        }
                    } catch (error) {
                        msgInput.value = originalText;
                        console.error("Send error:", error);
                    }
                });
            }
        });

        // --- WebSocket realtime ---
        let ws = null;
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : '';
        }

        function setupWs() {
            if (!window.WebSocket) return;
            const token = getCookie('jwt_token') || getCookie('JWT_TOKEN') || '';
            if (!token) return;
            ws = new WebSocket(`ws://localhost:8080/?token=${encodeURIComponent(token)}`);

            ws.onopen = () => {
                console.log('WS connected');
            };
            ws.onclose = () => {
                console.log('WS disconnected');
            };
            ws.onerror = (e) => {
                console.error('WS error', e);
            };
            ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    if (!data || !data.type) return;

                    if (data.type === 'new_message' && data.customer_id == selectedCustomerId) {
                        // Force refresh from REST so UI stays consistent
                        fetchMessages();
                    }

                    if (data.type === 'typing' && data.customer_id == selectedCustomerId) {
                        // Optional: show typing UI (not required to work)
                        // console.log('Typing', data.is_typing);
                    }
                } catch (err) {
                    console.error(err);
                }
            };
        }
    </script>
</body>
</html>

