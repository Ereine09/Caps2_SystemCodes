<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_customer_login();
// require_customer_login();
require_once __DIR__ . '/../app/helpers/messaging_helper.php';

$customer = current_customer();
$customer_id = (int)$customer['id'];
$unread_count = get_unread_count_customer($customer_id);
$search_query = trim($_GET['search'] ?? '');

// Ensure tracking columns exist in customers table to prevent "Unknown column" errors
$check_cols = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'admin_typing_at'");
if ($check_cols && mysqli_num_rows($check_cols) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN admin_typing_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN last_typing_at TIMESTAMP NULL DEFAULT NULL");
}

// Handle "Connect to Staff" handoff request (Smart Assistance -> Human Handoff).
// Reuses the existing tbl_messages thread + AI-suppression mechanism -
// see request_staff_handoff() in messaging_helper.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'connect_staff') {
    // Guard against an unauthenticated/invalid customer_id (0) creating an
    // orphaned system message - the same class of issue already on record
    // for this thread (see AI Auto-Reply's customer_id=0 bug).
    $handoff_ok = $customer_id > 0 ? request_staff_handoff($conn, $customer_id) : false;

    $is_ajax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$handoff_ok]);
        exit;
    }

    $_SESSION['message'] = $handoff_ok ? 'We\'ve let our staff know - they\'ll join shortly!' : 'Could not reach staff right now, please try again.';
    $_SESSION['message_type'] = $handoff_ok ? 'success' : 'error';
    header('Location: messages.php');
    exit;
}

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $message = trim($_POST['message'] ?? '');
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : null;
    
    if (!empty($message)) {
        // Prepend product info if this is an inquiry from the shop
        if ($product_id) {
            $product = get_product_by_id($product_id);
            if ($product) {
                $message = "[Inquiry: " . $product['name'] . "]\n" . $message;
            }
        }

        $user_id = null; // Will be assigned by admin/staff
        $sent_success = send_message($customer_id, $user_id, 'customer', $message, (int) ($_POST['reply_to_id'] ?? 0));

        // Check if request is AJAX
        $is_ajax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool)$sent_success]);
            exit;
        }

        if ($sent_success) {
            $_SESSION['message'] = 'Message sent successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Failed to send message.';
            $_SESSION['message_type'] = 'error';
        }
        header('Location: messages.php');
        exit;
    }
}

// Mark unread messages as read (for customer viewing)
$messages = get_customer_messages($customer_id);

if (!empty($search_query)) {
    $messages = array_filter($messages, function($m) use ($search_query) {
            return stripos($m['message'], $search_query) !== false || 
                   stripos($m['sender_name'], $search_query) !== false;
    });

    // After filtering, re-index so JS next/prev works reliably
    $messages = array_values($messages);
}

if ($messages) {
    foreach ($messages as $msg) {
        if ($msg['is_read'] == 0 && $msg['sender_type'] != 'customer') {
            mark_as_read($msg['id']);
        }
    }
}

// Fetch latest message list sequence
$messages = get_customer_messages($customer_id);

// Reverse array for chronological display flow
if ($messages) {
    $messages = array_reverse($messages);
}
?>
<?php $page_title = 'Customer Support Messages'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    :root {
        --customer-primary: #2563eb;
        --customer-primary-hover: #1d4ed8;
        --staff-purple: var(--dk-primary, #4a3e94);
        --chat-bg: #f8fafc;
        --border-color: var(--border);
        --text-dark: #1e293b;
        --text-muted: #64748b;
    }

    /* Outer Container Base Layout */
    .messages-container {
        background: var(--surface);
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-color);
        overflow: hidden;
        margin-top: 15px;
        display: flex;
        flex-direction: column;
        height: calc(100vh - 180px);
        min-height: 520px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    /* Header Bar */
    .messages-header {
        background: var(--surface);
        padding: 16px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        gap: 15px;
        flex-wrap: wrap;
    }

    .messages-header-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .support-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: var(--info-soft);
        color: var(--customer-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .messages-header h2 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .messages-header p {
        color: var(--text-muted);
        font-size: 0.82rem;
        margin: 2px 0 0 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .status-dot {
        width: 8px;
        height: 8px;
        background: #22c55e;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }

    /* Header Search Box */
    .header-actions-group {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .chat-search-form {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .search-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .search-input-wrapper input {
        padding: 8px 14px 8px 36px;
        border: 1px solid var(--border-color);
        border-radius: 20px;
        font-size: 0.85rem;
        width: 200px;
        background: var(--surface-2);
        outline: none;
        transition: all 0.2s ease;
    }

    .search-input-wrapper input:focus {
        background: var(--surface);
        border-color: var(--customer-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .search-input-wrapper i {
        position: absolute;
        left: 12px;
        color: var(--muted);
        font-size: 0.85rem;
    }

    .btn-search-nav {
        background: var(--surface-2);
        color: var(--text-dark);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 6px 14px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .btn-search-nav:hover {
        background: #e2e8f0;
    }

    .search-clear-link {
        color: var(--danger);
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        padding: 0 4px;
    }

    .unread-badge {
        background: #ef4444;
        color: #ffffff;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
    }

    /* Messages Scroll Area */
    .messages-list {
        padding: 20px 24px;
        flex: 1;
        overflow-y: auto;
        background: var(--chat-bg);
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* Message Bubbles */
    .message {
        max-width: 75%;
        display: flex;
        flex-direction: column;
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
        margin-bottom: 4px;
        padding: 0 4px;
    }

    .message.sent .message-sender {
        color: var(--text-muted);
    }

    .message.received .message-sender {
        color: var(--staff-purple);
    }

    .message-content {
        width: fit-content;
        max-width: 100%;
        padding: 12px 18px;
        border-radius: 18px;
        font-size: 0.92rem;
        line-height: 1.45;
        position: relative;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .message.sent .message-content {
        background: var(--customer-primary);
        color: #ffffff;
        border-bottom-right-radius: 4px;
    }

    .message.received .message-content {
        background: var(--surface);
        color: var(--text-dark);
        border: 1px solid var(--border-color);
        border-bottom-left-radius: 4px;
    }

    .message-text {
        /* Render the message exactly as typed: preserve real line breaks,
           wrap normally between words, hard-break only an unbreakable run. */
        white-space: pre-wrap;
        overflow-wrap: break-word;
        word-break: normal;
    }

    .message-time {
        font-size: 0.68rem;
        color: var(--muted);
        margin-top: 4px;
        padding: 0 6px;
    }

    .message.sent .message-time {
        text-align: right;
    }

    .seen-status {
        font-size: 0.68rem;
        color: var(--customer-primary);
        margin-top: 2px;
        padding: 0 6px;
        font-weight: 700;
        text-align: right;
    }

    /* Input Area */
    .message-form {
        padding: 16px 24px;
        background: var(--surface);
        border-top: 1px solid var(--border-color);
    }

    .msg-form-group {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .msg-form-group textarea {
        flex: 1;
        padding: 11px 18px;
        border: 1px solid var(--border-color);
        border-radius: 22px;
        font-family: inherit;
        font-size: 0.92rem;
        resize: none;
        height: 44px;
        background: var(--surface-2);
        outline: none;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .msg-form-group textarea:focus {
        background: var(--surface);
        border-color: var(--customer-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .msg-form-group button {
        background: var(--customer-primary);
        color: #ffffff;
        border: none;
        padding: 0 24px;
        height: 44px;
        border-radius: 22px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: background 0.2s ease;
        flex-shrink: 0;
    }

    .msg-form-group button:hover {
        background: var(--customer-primary-hover);
    }

    .empty-state {
        text-align: center;
        padding: 50px 20px;
        color: var(--text-muted);
        margin: auto;
    }

    .empty-state i {
        font-size: 2.8rem;
        color: #cbd5e1;
        margin-bottom: 12px;
    }

    .alert {
        padding: 12px 18px;
        margin: 15px 24px 0 24px;
        border-radius: 8px;
        display: none;
    }

    .alert.show { display: block; }
    .alert.success { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
    .alert.error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }

    .back-btn-container {
        margin-top: 18px;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--text-muted);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 8px 14px;
        border-radius: 8px;
        background: var(--surface);
        border: 1px solid var(--border-color);
        transition: all 0.2s ease;
    }

    .back-link:hover {
        background: var(--surface-2);
        color: var(--text-dark);
    }

    .chat-search-highlight {
        background: var(--dk-warning-soft, #fef08a);
        color: var(--text);
        padding: 1px 4px;
        border-radius: 4px;
        font-weight: 700;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .messages-container {
            height: calc(100vh - 140px);
            min-height: 480px;
            border-radius: 12px;
            margin-top: 10px;
        }

        .messages-header {
            padding: 12px 16px;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
        }

        .messages-header-info {
            justify-content: space-between;
            width: 100%;
        }

        .header-actions-group {
            width: 100%;
            justify-content: space-between;
        }

        .chat-search-form {
            width: 100%;
            display: flex;
            gap: 6px;
        }

        .search-input-wrapper {
            flex: 1;
        }

        .search-input-wrapper input {
            width: 100%;
        }

        .messages-list {
            padding: 14px;
            gap: 10px;
        }

        .message {
            max-width: 88%;
        }

        .message-content {
            padding: 10px 14px;
            font-size: 0.9rem;
        }

        .message-form {
            padding: 12px 16px;
        }

        .alert {
            margin: 10px 16px 0 16px;
        }
    }

    @media (max-width: 480px) {
        .messages-container {
            height: calc(100vh - 110px);
            min-height: 420px;
        }

        .support-avatar {
            width: 36px;
            height: 36px;
            font-size: 0.95rem;
        }

        .messages-header h2 {
            font-size: 1rem;
        }

        .message {
            max-width: 92%;
        }

        .msg-form-group {
            gap: 8px;
        }

        .msg-form-group textarea {
            padding: 10px 14px;
            font-size: 0.88rem;
        }

        .msg-form-group button {
            padding: 0 16px;
            font-size: 0.85rem;
        }

        .msg-form-group button span {
            display: none;
        }
    }

    /* Customer Messages — Dark Mode only.
       CSS/UI patch only: notification, WebSocket and messaging logic are untouched. */
    html[data-theme="dark"] .messages-container {
        background: #171411;
        border-color: #3a332d;
    }

    html[data-theme="dark"] .messages-header {
        background: #171411;
        border-bottom-color: #3a332d;
    }

    html[data-theme="dark"] .messages-header h2 {
        color: #f5f5f4;
    }

    html[data-theme="dark"] .messages-header p,
    html[data-theme="dark"] .message.sent .message-sender,
    html[data-theme="dark"] .message-time,
    html[data-theme="dark"] .empty-state {
        color: #a8a29e;
    }

    html[data-theme="dark"] .messages-list {
        background: #12100e;
    }

    html[data-theme="dark"] .message.received .message-content {
        background: #211d19;
        color: #f5f5f4;
        border-color: #3a332d;
    }

    html[data-theme="dark"] .message-form {
        background: #171411;
        border-top-color: #3a332d;
    }

    html[data-theme="dark"] .msg-form-group textarea {
        background: #211d19;
        color: #f5f5f4;
        border-color: #57504a;
        caret-color: #f5f5f4;
    }

    html[data-theme="dark"] .msg-form-group textarea::placeholder {
        color: #8f8881;
        opacity: 1;
    }

    html[data-theme="dark"] .msg-form-group textarea:focus {
        background: #26211d;
        color: #ffffff;
        border-color: var(--customer-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
    }

    html[data-theme="dark"] .search-input-wrapper input {
        background: #211d19;
        color: #f5f5f4;
        border-color: #57504a;
    }

    html[data-theme="dark"] .search-input-wrapper input::placeholder {
        color: #8f8881;
        opacity: 1;
    }

    html[data-theme="dark"] .search-input-wrapper input:focus {
        background: #26211d;
        color: #ffffff;
    }

    html[data-theme="dark"] .btn-search-nav,
    html[data-theme="dark"] .back-link {
        background: #211d19;
        color: #e7e5e4;
        border-color: #3a332d;
    }

    html[data-theme="dark"] .btn-search-nav:hover,
    html[data-theme="dark"] .back-link:hover {
        background: #2c2722;
        color: #ffffff;
    }

    html[data-theme="dark"] .system-message span {
        background: #2c2722 !important;
        color: #d6d3d1 !important;
    }

    /* ---- Tidy layout (header, search, chat height, composer), phone first ---- */
    .messages-header { gap: 12px; }
    .messages-header-info { flex: 1 1 auto; min-width: 0; justify-content: space-between; }
    .msg-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .msg-staff-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-color: transparent; background: var(--primary-soft); color: var(--customer-primary); }
    .msg-staff-btn:hover { background: var(--info-soft); }
    .msg-search-ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); display: flex; color: var(--muted); font-size: .85rem; pointer-events: none; }
    .search-input-wrapper input { padding-left: 38px; }
    .message-content { box-shadow: 0 1px 2px rgba(15, 23, 42, .06); }
    .message-time { margin-top: 3px; }
    /* React / reply / more: on a computer they appear when you point at a message (always shown on phones). */
    @media (hover: hover) {
        .message .cx-tools { opacity: 0; transition: opacity .15s ease; }
        .message:hover .cx-tools, .message:focus-within .cx-tools { opacity: 1; }
    }
    @media (max-width: 768px) {
        .message .cx-tools button { width: 30px; height: 30px; font-size: .78rem; }
    }

    @media (max-width: 768px) {
        /* Fill the screen under the app bars instead of a fixed height that made the page scroll. */
        .customer-panel:has(.messages-container) { padding-bottom: 8px; }
        .messages-container { height: calc(100dvh - 150px); min-height: 360px; margin-top: 4px; }
        .back-btn-container { display: none; }
        .messages-header { padding: 12px 14px; gap: 10px; }
        .messages-header-info { flex-direction: row; align-items: center; }
        .header-actions-group { width: 100%; }
        .chat-search-form { flex-wrap: nowrap; }
        .search-input-wrapper input { padding-top: 9px; padding-bottom: 9px; }
        .msg-staff-btn { padding: 8px 12px; }
        .messages-list { padding: 12px; }
        .message-form { padding: 10px 12px; }
        .msg-form-group { gap: 8px; }
        .msg-form-group button { width: 44px; padding: 0; justify-content: center; border-radius: 50%; }
        .msg-form-group button span { display: none; }
    }
    @media (max-width: 400px) {
        .msg-staff-label { display: none; }  /* icon only on very small phones */
        .msg-staff-btn { width: 40px; height: 40px; padding: 0; justify-content: center; border-radius: 50%; }
        .messages-header p { font-size: .76rem; }
    }
</style>

<section class="customer-panel">
    <div class="messages-container">
        <div class="messages-header">
            <div class="messages-header-info">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="support-avatar">
                        <i class="fas fa-headset"></i>
                    </div>
                    <div>
                        <h2>Support Inbox</h2>
                        <p><span class="status-dot"></span> Online & ready to assist</p>
                    </div>
                </div>

                <div class="msg-head-actions">
                    <?php if ($unread_count > 0): ?>
                        <div class="unread-badge"><?php echo (int)$unread_count; ?> new</div>
                    <?php endif; ?>
                    <button type="button" id="connect-staff-btn" class="btn-search-nav msg-staff-btn" title="Ask a real staff member to join this conversation">
                        <i class="fas fa-headset"></i> <span class="msg-staff-label">Talk to staff</span>
                    </button>
                </div>
            </div>

            <div class="header-actions-group">

                <form method="GET" action="" class="chat-search-form">
                    <div class="search-input-wrapper">
                        <span class="msg-search-ico" aria-hidden="true"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search messages..." id="chat-search-input">
                    </div>

                    <?php if (!empty($search_query)): ?>
                        <button type="button" id="chat-search-prev" class="btn-search-nav">Prev</button>
                        <button type="button" id="chat-search-next" class="btn-search-nav">Next</button>
                        <a href="messages.php" class="search-clear-link">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
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

        <div class="messages-list" id="messages-list">
            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <i class="fas fa-comments"></i>
                    <p style="font-weight: 600; color: var(--text-dark); margin-bottom: 4px;">No messages yet</p>
                    <p style="font-size: 0.88rem; margin: 0;">Start a conversation by sending a message below!</p>
                </div>
            <?php else: ?>
                <?php 
                // Find latest customer message read by admin
                $last_seen_msg_id = null;
                if (!empty($messages)) {
                    foreach ($messages as $msg) {
                        if ($msg['sender_type'] === 'customer' && $msg['is_read'] == 1) {
                            $last_seen_msg_id = $msg['id'];
                        }
                    }
                }

                foreach ($messages as $msg): 
                    if ($msg['sender_type'] === 'system'): ?>
                        <div class="system-message" style="align-self: center; width: 100%; text-align: center; margin: 12px 0; font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">
                            <span style="background: var(--surface-3); padding: 5px 16px; border-radius: 12px; color: var(--text-2);"><?php echo htmlspecialchars($msg['message']); ?></span>
                        </div>
                    <?php continue; endif;

                    $is_sent = $msg['sender_type'] === 'customer';
                ?>
                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                        <div class="message-sender">
                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                            <?php if (!$is_sent): ?>
                                <i class="fas fa-user-shield" style="color: var(--staff-purple); margin-left: 4px;" title="Verified Staff"></i>
                            <?php endif; ?>
                        </div>
                        <div class="message-content">
                            <?php
                            $escapedMessage = htmlspecialchars($msg['message']);
                            if (!empty($search_query)) {
                                $pattern = '/(' . preg_quote($search_query, '/') . ')/i';
                                $escapedMessage = preg_replace($pattern, '<mark class="chat-search-highlight">$1</mark>', $escapedMessage);
                            }
                            ?>
                            <div class="message-text"><?php echo $escapedMessage; ?></div>
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

        <form method="POST" class="message-form" id="customer-chat-form">
            <?php echo csrf_field(); ?>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send_message">
            <div class="msg-form-group">
                <textarea name="message" id="customer-message-input" placeholder="Type your message here..." required></textarea>
                <button type="submit"><i class="fas fa-paper-plane"></i> <span>Send</span></button>
            </div>
        </form>
    </div>

    <div class="back-btn-container">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</section>

<!-- Notification Sound - same element/source as modules/admin/messages.php's -->
<audio id="notification-sound" preload="auto">
    <source src="https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3" type="audio/mpeg">
</audio>

<script src="<?php echo BASE_URL; ?>/assets/js/chat-extras.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/chat-extras.js') ?: time(); ?>"></script>
<script>
    const messagesList = document.getElementById('messages-list');
    const customerId = <?php echo (int)$customer_id; ?>;

    // Browsers may block audio until the page receives a user gesture.
    // Prime the existing notification sound once on first interaction.
    let notificationAudioPrimed = false;
    function primeNotificationAudio() {
        if (notificationAudioPrimed) return;
        const audio = document.getElementById('notification-sound');
        if (!audio) return;
        try {
            const oldVolume = audio.volume;
            audio.volume = 0;
            const p = audio.play();
            if (p && typeof p.then === 'function') {
                p.then(() => {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.volume = oldVolume;
                    notificationAudioPrimed = true;
                }).catch(() => { audio.volume = oldVolume; });
            }
        } catch (e) {}
    }
    document.addEventListener('pointerdown', primeNotificationAudio, { once: true });
    document.addEventListener('keydown', primeNotificationAudio, { once: true });
    // Actively searching: leave the search-highlighted DOM alone on live
    // updates rather than replacing it with a plain re-render (which would
    // lose the existing <mark class="chat-search-highlight"> feature).
    const hasActiveSearch = <?php echo !empty($search_query) ? 'true' : 'false'; ?>;
    const searchQuery = <?php echo json_encode($search_query); ?>;

    function scrollToBottom() {
        if (messagesList) {
            messagesList.scrollTop = messagesList.scrollHeight;
        }
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : '';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function highlightSearch(escapedText) {
        if (!hasActiveSearch || !searchQuery) return escapedText;
        const escapedQuery = escapeHtml(searchQuery).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (!escapedQuery) return escapedText;
        return escapedText.replace(new RegExp('(' + escapedQuery + ')', 'ig'), '<mark class="chat-search-highlight">$1</mark>');
    }

    // Seeded from the SAME $messages array PHP already rendered below, so the
    // very first fetchMessages() call never treats already-displayed history
    // as "new" (which would otherwise replay the sound on every page load -
    // exactly what STEP 10 warns against).
    let lastMaxMsgId = <?php echo !empty($messages) ? (int) max(array_column($messages, 'id')) : -1; ?>;

    /**
     * Mirrors modules/admin/messages.php's maybeNotify() exactly, with one
     * deliberate difference: Admin's "inbound" is sender_type === 'customer'
     * (Admin is receiving FROM the customer); here "inbound" is
     * sender_type === 'admin' || 'staff' (Customer is receiving FROM
     * support) - this also naturally covers the AI's own auto-reply, since
     * it's saved with sender_type='admin' the same as a real human reply
     * (see send_message() in app/helpers/messaging_helper.php). Never
     * 'customer' (the customer's own outgoing message) or 'system'.
     *
     * De-dupes the same way Admin's does: only a message id greater than the
     * highest one already seen counts as new, so calling this redundantly
     * from both the WS trigger and the next poll tick for the very same
     * message never plays the sound or bumps a badge twice.
     */
    function maybeNotify(messages) {
        let maxId = lastMaxMsgId;
        let gotInbound = false;
        messages.forEach(m => {
            const id = Number(m.id);
            if (id > maxId) maxId = id;
            if (id > lastMaxMsgId && (m.sender_type === 'admin' || m.sender_type === 'staff')) {
                gotInbound = true;
            }
        });
        if (gotInbound) {
            try {
                const audio = document.getElementById('notification-sound');
                // Autoplay can be blocked before the user has interacted with
                // the page - that's a browser policy, not a messaging
                // failure, so the rejected promise is swallowed here on
                // purpose (badges/UI still update either way).
                if (audio) { audio.currentTime = 0; audio.play().catch(() => {}); }
            } catch (e) {}
            refreshSidebarBadges();
        }
        lastMaxMsgId = maxId;
    }

    /** Mirrors modules/admin/messages.php's renderMessages() structure. */
    function renderMessages(messages) {
        maybeNotify(messages);

        if (hasActiveSearch) return;

        if (!messages || messages.length === 0) {
            const empty = '<div class="empty-state"><i class="fas fa-comments"></i>' +
                '<p style="font-weight: 600; color: var(--text-dark); margin-bottom: 4px;">No messages yet</p>' +
                '<p style="font-size: 0.88rem; margin: 0;">Start a conversation by sending a message below!</p></div>';
            if (messagesList.innerHTML !== empty) messagesList.innerHTML = empty;
            return;
        }

        // API returns newest-first (see get_customer_messages()); the
        // conversation itself renders oldest-first.
        const ordered = messages.slice().reverse();

        let lastSeenId = null;
        ordered.forEach(m => {
            if (m.sender_type === 'customer' && Number(m.is_read) === 1) lastSeenId = Number(m.id);
        });

        const html = ordered.map(m => {
            if (m.sender_type === 'system') {
                return '<div class="system-message" style="align-self: center; width: 100%; text-align: center; margin: 12px 0; font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">' +
                    '<span style="background: var(--surface-3); padding: 5px 16px; border-radius: 12px; color: var(--text-2);">' + escapeHtml(m.message) + '</span></div>';
            }

            const isSent = m.sender_type === 'customer';
            const time = new Date(String(m.created_at).replace(' ', 'T'))
                .toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
            const seenStatus = (isSent && Number(m.id) === lastSeenId)
                ? '<div class="seen-status"><i class="fas fa-check-double"></i> Seen</div>' : '';

            return '<div class="message ' + (isSent ? 'sent' : 'received') + '"' + ChatExtras.attrs(m) + '>' +
                '<div class="message-sender">' + escapeHtml(m.sender_name) +
                (!isSent ? ' <i class="fas fa-user-shield" style="color: var(--staff-purple); margin-left: 4px;" title="Verified Staff"></i>' : '') + '</div>' +
                ChatExtras.quote(m) +
                '<div class="message-content"><div class="message-text">' + highlightSearch(escapeHtml(m.message)) + '</div></div>' +
                ChatExtras.reactions(m) + ChatExtras.tools(m) +
                '<div class="message-time">' + time + '</div>' +
                seenStatus +
                '</div>';
        }).join('');

        if (messagesList.innerHTML !== html) {
            const isAtBottom = messagesList.scrollHeight - messagesList.scrollTop <= messagesList.clientHeight + 100;
            messagesList.innerHTML = html;
            if (isAtBottom) scrollToBottom();
        }
    }

    /**
     * customer/messaging_api.php's get_messages case (fixed last turn to
     * actually authenticate, and now also mirrors Admin's own get_messages
     * case by marking fetched admin/staff messages as read).
     */
    let messagesFetchInFlight = false;
    async function fetchMessages() {
        if (messagesFetchInFlight) return;
        messagesFetchInFlight = true;
        try {
            const response = await fetch(
                'messaging_api.php?action=get_messages&_=' + Date.now(),
                {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }
            );
            const result = await response.json();
            if (result && result.success && Array.isArray(result.data)) {
                renderMessages(result.data);
            }
        } catch (e) {
            console.error('fetchMessages error:', e);
        } finally {
            messagesFetchInFlight = false;
        }
    }

    /**
     * Updates the two sidebar badges live (same elements/endpoints
     * nav-toggle.js's site-wide initCustomerMessageNotifications() already
     * uses - duplicated here in a few lines because this page deliberately
     * runs its own connection instead of that one; see setupWs() below).
     */
    function refreshSidebarBadges() {
        fetch('messaging_api.php?action=get_unread_count')
            .then(r => r.json())
            .then(result => {
                const badge = document.getElementById('sidebar-messages-badge');
                if (!badge || !result || !result.success) return;
                const count = (result.data && result.data.unread_count) || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? '' : 'none';
            })
            .catch(() => {});

        fetch('notifications_api.php?action=get_unread_count')
            .then(r => r.json())
            .then(result => {
                let badge = document.getElementById('notif-bell-badge');
                const count = (result && result.success && result.data && result.data.unread_count) || 0;
                if (count > 0) {
                    if (!badge) {
                        const btn = document.getElementById('notif-bell-btn');
                        if (!btn) return;
                        badge = document.createElement('span');
                        badge.id = 'notif-bell-badge';
                        badge.className = 'notif-bell-badge';
                        btn.appendChild(badge);
                    }
                    badge.textContent = count;
                    badge.style.display = 'flex';
                } else if (badge) {
                    badge.remove();
                }
            })
            .catch(() => {});
    }

    let ws = null;
    function setupWs() {
        if (!window.WebSocket || !<?php echo WS_ENABLED ? 'true' : 'false'; ?>) return;
        // Real cookie name is jwt_token_customer (see app/helpers/jwt_helper.php's
        // setJWTCookie()) - this was reading 'jwt_token'/'JWT_TOKEN', which never
        // existed, so this WS connection has never actually opened until now.
        const token = getCookie('jwt_token_customer') || '';
        if (!token) return;

        // Derive the WS host from the page's own hostname (works on
        // localhost, LAN IPs, and a real domain alike) instead of a
        // hardcoded "localhost" that would only ever resolve to the
        // visitor's own machine in production.
        const wsScheme = window.location.protocol === 'https:' ? 'wss' : 'ws';
        ws = new WebSocket(`${wsScheme}://${window.location.hostname}:<?php echo WS_PORT; ?>/?token=${encodeURIComponent(token)}`);

        ws.onopen = () => {
            console.log('Customer WS connected');
        };
        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (!data || !data.type) return;

                // Two different events can mean "there's a new message here":
                //  - 'new_message' is only ever sent by another browser tab's
                //    own JS right after IT successfully posts (see
                //    modules/admin/messages.php) - best-effort, only reaches
                //    us if that admin's WS happened to be open at that exact
                //    moment. Kept for backward compatibility.
                //  - 'notification' is the reliable one: pushed by the PHP
                //    backend itself (notify_customer_new_staff_message() in
                //    messaging_helper.php, via the same notifications_create()
                //    -> ws_push_notification() pipeline the Admin side already
                //    relies on) every single time a human admin/staff message
                //    is saved - not dependent on any other browser's JS state.
                // Either way, this now just triggers an immediate fetch - the
                // actual render/notify/dedupe all happens in fetchMessages()'s
                // pipeline above, exactly like Admin's WS handler triggering
                // fetchMessages() instead of touching the DOM itself. No full
                // page reload either way.
                const isLegacyBroadcast = data.type === 'new_message' && data.customer_id == customerId;
                const isReliablePush = data.type === 'notification' && data.reference_table === 'tbl_messages';

                if (isLegacyBroadcast || isReliablePush) {
                    fetchMessages();
                }
            } catch (e) {
                console.error(e);
            }
        };
        ws.onerror = (e) => {
            console.error('Customer WS error', e);
        };
        ws.onclose = () => {
            console.log('Customer WS disconnected');
            ws = null;
            setTimeout(setupWs, 2000);
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        scrollToBottom();
        setupWs();

        // Primary, always-reliable update mechanism - exactly mirrors Admin's
        // own setInterval(fetchMessages, 4000) in modules/admin/messages.php.
        // WS (setupWs() above) just triggers this sooner when it's connected;
        // this poll is what guarantees delivery even if WS is ever down,
        // without ever reloading the page.
        // 2s like Admin, not faster: on shared hosting every open tab's poll counts against the
        // account's process limit (508 "Resource Limit Reached" when exceeded).
        setInterval(() => { if (!document.hidden) fetchMessages(); }, 2000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) fetchMessages();
        });
        window.addEventListener('focus', fetchMessages);
        window.addEventListener('focus', fetchMessages);

        const connectStaffBtn = document.getElementById('connect-staff-btn');
        if (connectStaffBtn) {
            connectStaffBtn.addEventListener('click', async () => {
                connectStaffBtn.disabled = true;
                const originalHtml = connectStaffBtn.innerHTML;
                connectStaffBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...';
                try {
                    const formData = new FormData();
                    formData.append('action', 'connect_staff');
                    formData.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
                    const response = await fetch('messages.php?action=connect_staff&ajax=1', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                        body: formData
                    });
                    const result = await response.json().catch(() => null);
                    if (result && result.success) {
                        connectStaffBtn.disabled = false;
                        connectStaffBtn.innerHTML = originalHtml;
                        fetchMessages(); // shows the new "staff notified" system message immediately
                    } else {
                        connectStaffBtn.disabled = false;
                        connectStaffBtn.innerHTML = originalHtml;
                        toastOr("Couldn't reach staff right now. Please try again.", "error");
                    }
                } catch (err) {
                    connectStaffBtn.disabled = false;
                    connectStaffBtn.innerHTML = originalHtml;
                    console.error('Connect to staff error:', err);
                }
            });
        }

        const chatForm = document.getElementById('customer-chat-form');
        // Replies + reactions (assets/js/chat-extras.js).
        ChatExtras.init({
            container: messagesList,
            form: chatForm,
            react: { url: 'messaging_api.php', csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
            refresh: fetchMessages
        });
        fetchMessages(); // replace the first server-rendered list with one that has replies/reactions
        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData(chatForm);
                const msgInput = document.getElementById('customer-message-input');
                const originalText = msgInput.value.trim();

                if (!originalText) return;
                msgInput.value = '';

                try {
                    // Start the save request without freezing the visible chat.
                    const sendRequest = fetch('messages.php?action=send_message&ajax=1', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json'
                        },
                        body: formData
                    });

                    // Admin can already see the saved row before this POST finishes
                    // (for example while synchronous AI work is still running).
                    // Refresh the customer's authoritative DB conversation in parallel
                    // so the real message appears here too, without a fake "Sending..." bubble.
                    fetchMessages();
                    const pendingRefresh = setInterval(fetchMessages, 300);

                    const response = await sendRequest;
                    const result = await response.json().catch(() => null);

                    clearInterval(pendingRefresh);

                    if (result && result.success) {
                        await fetchMessages();
                    } else {
                        msgInput.value = originalText;
                        await fetchMessages();
                        toastOr("Failed to send message. Please try again.", "error");
                    }
                } catch (err) {
                    msgInput.value = originalText;
                    console.error('Send error:', err);
                }
            });

            // Enter = send, Shift+Enter = newline.
            const msgInputEl = document.getElementById('customer-message-input');
            if (msgInputEl) {
                msgInputEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                        e.preventDefault();
                        if (msgInputEl.value.trim() !== '') chatForm.requestSubmit();
                    }
                });
            }
        }
    });
    
    // Search navigation (Messenger-like next/prev)
    const highlightSelector = 'mark.chat-search-highlight';
    let chatSearchMatches = [];
    let chatSearchIndex = 0;

    function refreshChatSearchMatches() {
        chatSearchMatches = Array.from(document.querySelectorAll(highlightSelector));
        chatSearchIndex = 0;
    }

    function focusCurrentHighlight() {
        if (!chatSearchMatches.length) return;
        const el = chatSearchMatches[chatSearchIndex];
        try {
            const container = messagesList;
            const elRect = el.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();
            const offsetTop = elRect.top - containerRect.top + container.scrollTop;
            container.scrollTop = offsetTop - 20;

            el.style.boxShadow = '0 0 0 3px rgba(255, 193, 7, 0.45)';
            setTimeout(() => {
                el.style.boxShadow = '';
            }, 900);
        } catch (e) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function goNextHighlight() {
        if (!chatSearchMatches.length) return;
        chatSearchIndex = (chatSearchIndex + 1) % chatSearchMatches.length;
        focusCurrentHighlight();
    }

    function goPrevHighlight() {
        if (!chatSearchMatches.length) return;
        chatSearchIndex = (chatSearchIndex - 1 + chatSearchMatches.length) % chatSearchMatches.length;
        focusCurrentHighlight();
    }

    document.addEventListener('DOMContentLoaded', () => {
        refreshChatSearchMatches();

        const nextBtn = document.getElementById('chat-search-next');
        const prevBtn = document.getElementById('chat-search-prev');

        if (nextBtn) nextBtn.addEventListener('click', goNextHighlight);
        if (prevBtn) prevBtn.addEventListener('click', goPrevHighlight);

        const searchInput = document.getElementById('chat-search-input');
        if (searchInput) {
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    goNextHighlight();
                }
            });
        }

        if (<?php echo !empty($search_query) ? 'true' : 'false'; ?>) {
            refreshChatSearchMatches();
            focusCurrentHighlight();
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>