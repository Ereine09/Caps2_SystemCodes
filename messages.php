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
            if ($msg['is_read'] == 0 && $msg['sender_type'] != 'customer') {
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Admin Panel</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>><i class="fas fa-shopping-cart"></i> Orders</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
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
    <div class="main-content" style="margin-left: 260px;">
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
            <div class="customers-list">
                <div class="customers-list-header">
                    📬 Conversations <?php if ($unread_count > 0) echo "(" . $unread_count . ")"; ?>
                </div>
                
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
    
            <!-- Conversation Area -->
            <div class="conversation-area">
                <?php if ($selected_customer): ?>
                    <div class="conversation-header">
                        <div>
                            <h3><?php echo htmlspecialchars($selected_customer['name']); ?></h3>
                            <p><?php echo htmlspecialchars($selected_customer['email']); ?></p>
                        </div>
                    </div>
    
                    <div class="conversation-messages">
                        <?php if (empty($conversation)): ?>
                            <div class="no-conversation">
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversation as $msg): 
                                $is_sent = in_array($msg['sender_type'], ['admin', 'staff']);
                            ?>
                                <div class="message <?php echo $is_sent ? 'sent' : ''; ?>">
                                    <div class="message-content">
                                        <div class="message-sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                        </div>
                                        <div><?php echo htmlspecialchars($msg['message']); ?></div>
                                        <div class="message-time">
                                            <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                                        </div>
                                    </div>
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
        const selectedCustomerId = <?php echo $selected_customer_id; ?>;
        const conversationMessages = document.querySelector('.conversation-messages');
        const chatForm = document.getElementById('chat-form');
        const messageInput = document.getElementById('message-input');
        const notificationSound = document.getElementById('notification-sound');

        let lastProcessedMessageId = 0;
        let isInitialLoad = true;

        function formatDate(dateString) {
            const options = { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false };
            return new Date(dateString).toLocaleDateString('en-US', options).replace(',', '');
        }

        // Setup real-time poll
        async function fetchMessages() {
            if (!selectedCustomerId) return;
            try {
                const response = await fetch(`modules/admin/messaging_api.php?action=get_messages&customer_id=${selectedCustomerId}`);
                const result = await response.json();
                if (result.success && result.data) {
                    handleNewMessageNotification(result.data);
                    renderMessages(result.data);
                }
            } catch (error) { console.error('Error fetching messages:', error); }
        }

        function handleNewMessageNotification(messages) {
            if (messages.length === 0) return;
            const latestMsg = messages[messages.length - 1];
            
            if (!isInitialLoad && latestMsg.id > lastProcessedMessageId && notificationSound) {
                if (latestMsg.sender_type === 'customer') {
                    notificationSound.play().catch(e => console.log("Sound blocked"));
                }
            }
            lastProcessedMessageId = latestMsg.id;
            isInitialLoad = false;
        }

        function renderMessages(messages) {
            if (messages.length === 0) return;
            const html = messages.map(msg => {
                const isSent = ['admin', 'staff'].includes(msg.sender_type);
                return `
                    <div class="message ${isSent ? 'sent' : ''}">
                        <div class="message-content">
                            <div class="message-sender">${msg.sender_name}</div>
                            <div>${msg.message.replace(/\n/g, '<br>')}</div>
                            <div class="message-time">${formatDate(msg.created_at)}</div>
                        </div>
                    </div>`;
            }).join('');
            conversationMessages.innerHTML = html;
            conversationMessages.scrollTop = conversationMessages.scrollHeight;
        }

        // Handle AJAX Send
        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(chatForm);
                if (!messageInput.value.trim()) return;

                try {
                    const response = await fetch('modules/admin/messaging_api.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        messageInput.value = '';
                        await fetchMessages(); // Immediate refresh
                    }
                } catch (error) { console.error('Error sending:', error); }
            });
        }

        // Typing Status logic
        let typingTimer;
        if (messageInput) {
            messageInput.addEventListener('input', () => {
                if (!selectedCustomerId || typingTimer) return;
                const formData = new FormData();
                formData.append('action', 'set_typing_status');
                formData.append('customer_id', selectedCustomerId);
                fetch('modules/admin/messaging_api.php', { method: 'POST', body: formData });
                typingTimer = setTimeout(() => { typingTimer = null; }, 3000);
            });
        }

        // Auto-refresh every 3 seconds
        setInterval(fetchMessages, 3000);

        document.addEventListener('DOMContentLoaded', () => {
            if (selectedCustomerId) fetchMessages();
        });
    </script>
</body>
</html>
