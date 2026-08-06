<?php
require_once __DIR__ . '/includes/auth.php';
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
        $sent_success = send_message($customer_id, $user_id, 'customer', $message);

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
        --staff-purple: #4a3e94;
        --chat-bg: #f8fafc;
        --border-color: #e2e8f0;
        --text-dark: #1e293b;
        --text-muted: #64748b;
    }

    /* Outer Container Base Layout */
    .messages-container {
        background: #ffffff;
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
        background: #ffffff;
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
        background: #eff6ff;
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
        background: #f8fafc;
        outline: none;
        transition: all 0.2s ease;
    }

    .search-input-wrapper input:focus {
        background: #ffffff;
        border-color: var(--customer-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .search-input-wrapper i {
        position: absolute;
        left: 12px;
        color: #94a3b8;
        font-size: 0.85rem;
    }

    .btn-search-nav {
        background: #f1f5f9;
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
        color: #ef4444;
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
        width: fit-content;
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
        background: #ffffff;
        color: var(--text-dark);
        border: 1px solid var(--border-color);
        border-bottom-left-radius: 4px;
    }

    .message-text {
        word-wrap: break-word;
        word-break: break-word;
    }

    .message-time {
        font-size: 0.68rem;
        color: #94a3b8;
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
        background: #ffffff;
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
        background: #f8fafc;
        outline: none;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .msg-form-group textarea:focus {
        background: #ffffff;
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
    .alert.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .alert.error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

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
        background: #ffffff;
        border: 1px solid var(--border-color);
        transition: all 0.2s ease;
    }

    .back-link:hover {
        background: #f8fafc;
        color: var(--text-dark);
    }

    .chat-search-highlight {
        background: #fef08a;
        color: #0f172a;
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

                <?php if ($unread_count > 0): ?>
                    <div class="unread-badge"><?php echo (int)$unread_count; ?> new</div>
                <?php endif; ?>
            </div>

            <div class="header-actions-group">
                <form method="GET" action="" class="chat-search-form">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search"></i>
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
                            <span style="background: #e2e8f0; padding: 5px 16px; border-radius: 12px; color: #475569;"><?php echo htmlspecialchars($msg['message']); ?></span>
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
                            <div class="message-text"><?php echo nl2br($escapedMessage); ?></div>
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

<script>
    const messagesList = document.getElementById('messages-list');
    const customerId = <?php echo (int)$customer_id; ?>;

    function scrollToBottom() {
        if (messagesList) {
            messagesList.scrollTop = messagesList.scrollHeight;
        }
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : '';
    }

    let ws = null;
    function setupWs() {
        if (!window.WebSocket) return;
        const token = getCookie('jwt_token') || getCookie('JWT_TOKEN') || '';
        if (!token) return;

        ws = new WebSocket(`ws://localhost:8080/?token=${encodeURIComponent(token)}`);

        ws.onopen = () => {
            console.log('Customer WS connected');
        };
        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (!data || !data.type) return;

                if (data.type === 'new_message' && data.customer_id == customerId) {
                    window.location.reload();
                }
            } catch (e) {
                console.error(e);
            }
        };
        ws.onerror = (e) => {
            console.error('Customer WS error', e);
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        scrollToBottom();
        setupWs();

        const chatForm = document.getElementById('customer-chat-form');
        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData(chatForm);
                const msgInput = document.getElementById('customer-message-input');
                const originalText = msgInput.value.trim();

                if (!originalText) return;
                msgInput.value = '';

                try {
                    const response = await fetch('messages.php?action=send_message&ajax=1', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json'
                        },
                        body: formData
                    });

                    const result = await response.json().catch(() => null);
                    if (result && result.success) {
                        window.location.reload();
                    } else {
                        msgInput.value = originalText;
                        alert("Failed to send message. Please try again.");
                    }
                } catch (err) {
                    msgInput.value = originalText;
                    console.error('Send error:', err);
                }
            });
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