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
        :root {
            --chat-primary: #4a3e94;
            --chat-primary-hover: #3b3178;
            --chat-bg: #f8fafc;
            --bubble-sent: #4a3e94;
            --bubble-received: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }

        body {
            background-color: #f1f5f9;
            overflow-x: hidden;
        }

        /* Messaging Grid Layout */
        .messages-wrapper {
            display: grid;
            grid-template-columns: 320px 1fr;
            height: calc(100vh - 120px);
            min-height: 520px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
            overflow: hidden;
            width: 100%;
            box-sizing: border-box;
        }

        /* Left Conversations Sidebar */
        .customers-list {
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            background: #ffffff;
            overflow: hidden;
        }

        .customers-list-header {
            padding: 18px 20px;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-dark);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }

        .sidebar-total-unread {
            background: #ef4444;
            color: #ffffff;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 700;
        }

        #conversations-list {
            overflow-y: auto;
            flex: 1;
        }

        .customer-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .customer-item:hover {
            background: #f8fafc;
        }

        .customer-item.active {
            background: #f1f5f9;
            border-left: 4px solid var(--chat-primary);
        }

        .customer-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .customer-email {
            font-size: 0.82rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .customer-unread {
            position: absolute;
            top: 14px;
            right: 18px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
        }

        .customer-last-msg {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        .empty-list {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        /* Right Conversation Area */
        .conversation-area {
            display: flex;
            flex-direction: column;
            background: var(--chat-bg);
            height: 100%;
            overflow: hidden;
        }

        .conversation-header {
            padding: 16px 24px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-back-btn {
            display: none;
            color: var(--chat-primary);
            font-size: 1.1rem;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 8px;
            background: #f1f5f9;
            margin-right: 4px;
        }

        .conversation-header h3 {
            margin: 0;
            font-size: 1.05rem;
            color: var(--text-dark);
        }

        .conversation-header p {
            margin: 2px 0 0 0;
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .conversation-messages {
            flex: 1;
            padding: 20px 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Message Bubbles */
        .message {
            display: flex;
            flex-direction: column;
            width: fit-content;
            max-width: 72%;
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
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--chat-primary);
            margin-bottom: 4px;
            padding: 0 4px;
        }

        .message-content {
            padding: 12px 18px;
            border-radius: 18px;
            font-size: 0.92rem;
            line-height: 1.45;
            word-wrap: break-word;
            word-break: break-word;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .message.sent .message-content {
            background: var(--bubble-sent);
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .message.received .message-content {
            background: var(--bubble-received);
            color: var(--text-dark);
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 0.68rem;
            color: #94a3b8;
            margin-top: 4px;
            padding: 0 6px;
        }

        .seen-status {
            font-size: 0.68rem;
            color: var(--chat-primary);
            margin-top: 2px;
            padding: 0 6px;
            font-weight: 700;
        }

        .no-conversation {
            margin: auto;
            text-align: center;
            color: var(--text-muted);
            padding: 20px;
        }

        .no-conversation i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 12px;
        }

        /* Input Area */
        .conversation-input {
            padding: 16px 24px;
            background: #ffffff;
            border-top: 1px solid var(--border-color);
        }

        .input-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .input-group textarea {
            flex: 1;
            height: 44px;
            padding: 11px 16px;
            border: 1px solid var(--border-color);
            border-radius: 22px;
            resize: none;
            outline: none;
            font-family: inherit;
            font-size: 0.92rem;
            background: #f8fafc;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .input-group textarea:focus {
            background: #ffffff;
            border-color: var(--chat-primary);
            box-shadow: 0 0 0 3px rgba(74, 62, 148, 0.1);
        }

        .input-group button {
            background: var(--chat-primary);
            color: #ffffff;
            border: none;
            padding: 0 22px;
            height: 44px;
            border-radius: 22px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
            flex-shrink: 0;
        }

        .input-group button:hover {
            background: var(--chat-primary-hover);
        }

        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ========================================================= */
        /* RESPONSIVE MEDIA QUERIES (Tablets and Mobile Devices)      */
        /* ========================================================= */

        @media (max-width: 850px) {
            .messages-wrapper {
                grid-template-columns: 1fr;
                height: calc(100vh - 140px);
                min-height: 480px;
                border-radius: 12px;
            }

            /* On Mobile: Toggle between Sidebar list and Chat Area */
            <?php if ($selected_customer_id > 0): ?>
                .customers-list {
                    display: none; /* Hide sidebar when a chat is open */
                }
                .conversation-area {
                    display: flex; /* Show chat area */
                }
                .mobile-back-btn {
                    display: inline-flex; /* Show back button */
                }
            <?php else: ?>
                .customers-list {
                    display: flex;
                    width: 100%;
                }
                .conversation-area {
                    display: none; /* Hide empty chat view when no chat selected */
                }
            <?php endif; ?>

            .conversation-header {
                padding: 12px 16px;
            }

            .conversation-messages {
                padding: 14px;
            }

            .message {
                max-width: 88%;
            }

            .message-content {
                padding: 10px 14px;
                font-size: 0.9rem;
            }

            .conversation-input {
                padding: 12px 16px;
            }
        }

        @media (max-width: 480px) {
            .messages-wrapper {
                height: calc(100vh - 110px);
            }

            .input-group {
                gap: 8px;
            }

            .input-group textarea {
                padding: 10px 14px;
                font-size: 0.88rem;
            }

            .input-group button {
                padding: 0 16px;
                font-size: 0.85rem;
            }

            .input-group button span {
                display: none; /* Hide text, keep paper plane icon on tiny screens */
            }
        }
        /* Sidebar Container Solution */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #1e293b;
            z-index: 1000;
            box-sizing: border-box;
        }

        .sidebar-brand {
            flex-shrink: 0;
            padding: 20px 15px;
            text-align: center;
        }

        /* Ginawang scrollable ang listahan kapag mahaba */
        .nav-links {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            padding: 0 10px !important;
            margin: 0;
        }

        /* Subtle Scrollbar Style */
        .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Sidebar Footer para sa Logout (Pinned at the bottom) */
        .sidebar-footer {
            flex-shrink: 0;
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background-color: #1e293b;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ef4444 !important;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s ease;
        }

        .logout-link:hover {
            opacity: 0.8;
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'class="active"' : ''; ?>><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-title" style="margin-bottom: 20px;">
            <h1 style="margin: 0; font-size: 1.5rem; color: #1e293b;"><i class="fas fa-comments" style="color: var(--chat-primary);"></i> Messaging System</h1>
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
            <!-- Customers List Sidebar -->
            <div class="customers-list" id="customers-sidebar">
                <div class="customers-list-header" id="sidebar-header">
                    <span><i class="fas fa-inbox" style="margin-right: 8px;"></i> Conversations</span>
                    <?php if ($unread_count > 0): ?>
                        <span class='sidebar-total-unread'><?php echo $unread_count; ?> new</span>
                    <?php endif; ?>
                </div>
                
                <div id="conversations-list">
                <?php if (empty($customers_with_messages)): ?>
                    <div class="empty-list">
                        <i class="fas fa-comment-slash" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                        <p style="margin: 0;">No active conversations</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($customers_with_messages as $customer): ?>
                        <div class="customer-item <?php echo ($customer['id'] == $selected_customer_id) ? 'active' : ''; ?>" 
                             onclick="location.href='?customer_id=<?php echo $customer['id']; ?>'">
                            <div class="customer-name">
                                <span><?php echo htmlspecialchars($customer['name']); ?></span>
                            </div>
                            <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                            <?php if ($customer['unread_count'] > 0): ?>
                                <span class="customer-unread"><?php echo $customer['unread_count']; ?></span>
                            <?php endif; ?>
                            <div class="customer-last-msg">
                                <i class="far fa-clock"></i> <?php echo date('M d, H:i', strtotime($customer['last_message_time'])); ?>
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
                        <a href="messages.php" class="mobile-back-btn" title="Back to customer list">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0e7ff; color: var(--chat-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            <?php echo strtoupper(substr($selected_customer['name'], 0, 2)); ?>
                        </div>
                        <div>
                            <h3><?php echo htmlspecialchars($selected_customer['name']); ?></h3>
                            <p><?php echo htmlspecialchars($selected_customer['email']); ?></p>
                        </div>
                    </div>
    
                    <div class="conversation-messages" id="conversation-messages">
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
                                <i class="fas fa-paper-plane"></i>
                                <p>No messages yet. Start the conversation below!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversation as $msg): 
                                $is_sent = in_array($msg['sender_type'], ['admin', 'staff']);
                            ?>
                                <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                                    <div class="message-sender">
                                        <?php echo htmlspecialchars($msg['sender_name']); ?>
                                        <?php if ($is_sent): ?>
                                            <i class="fas fa-user-shield" style="color: var(--chat-primary); margin-left: 4px;" title="Verified Staff"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-content">
                                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                                    </div>
                                    <?php if ($is_sent && $msg['id'] === $last_seen_msg_id): ?>
                                        <div class="seen-status"><i class="fas fa-check-double"></i> Seen</div>
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
                            <textarea name="message" id="message-input" placeholder="Type your message here..." required></textarea>
                            <button type="submit"><i class="fas fa-paper-plane"></i> <span>Send</span></button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="no-conversation">
                        <i class="fas fa-comments"></i>
                        <h3>Your Messages</h3>
                        <p>Select a customer from the left list to view and reply to conversations.</p>
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
                            <span style="background: #e2e8f0; padding: 5px 15px; border-radius: 12px; color: #475569;">${escapeHtml(msg.message)}</span>
                        </div>
                    `;
                }

                const isSent = ['admin', 'staff'].includes(msg.sender_type);
                const seenStatus = (isSent && msg.id === lastSeenId) ? '<div class="seen-status"><i class="fas fa-check-double"></i> Seen</div>' : '';
                const time = new Date(msg.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).replace(',', '');
                
                return `
                    <div class="message ${isSent ? 'sent' : 'received'}">
                        <div class="message-sender">${escapeHtml(msg.sender_name)}
                            ${isSent ? '<i class="fas fa-user-shield" style="color: var(--chat-primary); margin-left: 4px;" title="Verified Staff"></i>' : ''}</div>
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
                            // Trigger WS broadcast so other clients can refresh
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
                        fetchMessages();
                    }
                } catch (err) {
                    console.error(err);
                }
            };
        }
    </script>
</body>
</html>