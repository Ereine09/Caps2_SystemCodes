<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();
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
        if (send_message($customer_id, $user_id, 'customer', $message)) {
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

// Kunin ang pinakabagong sequence ng listahan
$messages = get_customer_messages($customer_id);

// Para sa pagpapakita ng chat flow, kailangan nating i-reverse ang array 
// dahil ang get_customer_messages() ay karaniwang may ORDER BY created_at DESC
if ($messages) {
    $messages = array_reverse($messages);
}
?>
<?php $page_title = 'Customer Support Messages'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .messages-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        overflow: hidden;
        margin-top: 20px;
    }
    .messages-header {
        background: #fff;
        color: #262626;
        padding: 15px 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #dbdbdb;
    }
    .messages-header h2 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
    }
    .messages-header p { color: #8e8e8e; font-size: 0.85rem; margin-top: 4px; }

    .unread-badge {
        background: #e74c3c;
        color: white;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: bold;
        margin-left: 5px;
        display: inline-block;
    }

    .messages-list {
        padding: 20px;
        height: 600px;
        overflow-y: auto;
        border-bottom: 1px solid #eee;
        background: #f8f9fa;
        display: flex;
        flex-direction: column;
        gap: 12px;
        gap: 6px;
    }
    .message-sender {
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 2px;
        padding: 0 5px;
    }
    .message.sent .message-sender {
        color: #64748b; /* Customer's own name */
    }
    .message.received .message-sender {
        color: #4a3e94; /* Admin/Staff name */
    }


    /* FIX: Inayos ang structure ng flex rows para laging nasa gilid ang content */
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

    .message-content {
        padding: 10px 16px;
        border-radius: 18px;
        font-size: 0.95rem;
        line-height: 1.3;
        position: relative;
        margin-bottom: 2px;
        margin-bottom: 1px;
    }
    .message.sent .message-content {
        background: #0084ff;
        color: white;
        border-bottom-right-radius: 4px;
    }
    .message.received .message-content {
        background: #e4e6eb;
        color: #050505;
        border-bottom-left-radius: 4px;
    }

    .message-text {
        word-wrap: break-word;
    }

    .message-time {
        font-size: 0.65rem;
        color: #bcc0c4;
        margin-top: 0px;
        margin-bottom: 1px;
        padding: 0 10px;
        line-height: 1;
    }
    .message.sent .message-time {
        text-align: right;
    }

    .seen-status {
        font-size: 0.65rem;
        color: #8e8e8e;
        margin-top: 0px;
        padding: 0 10px;
        line-height: 1;
        font-weight: 600;
        text-align: right;
    }

    .message-form {
        padding: 20px;
        background: white;
    }
    .msg-form-group {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .msg-form-group textarea {
        flex: 1;
        padding: 12px 20px;
        border: 1px solid #e4e6eb;
        border-radius: 20px;
        font-family: inherit;
        resize: none;
        height: 44px;
        background: #f0f2f5;
        outline: none;
    }
    .msg-form-group button {
        background: #0084ff;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 20px;
        cursor: pointer;
        font-weight: bold;
        transition: 0.2s;
    }
    .msg-form-group button:hover {
        opacity: 0.8;
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #999;
        margin: auto;
    }
    .empty-state p {
        margin: 0;
        font-size: 16px;
    }

    .alert {
        padding: 15px;
        margin: 20px;
        border-radius: 8px;
        display: none;
    }
    .alert.show { display: block; }
    .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .back-link { display: inline-block; margin: 20px; color: #667eea; text-decoration: none; font-weight: bold; }
    .back-link:hover { text-decoration: underline; }
</style>

<style>
    .chat-search-highlight {
        background: #fff3cd;
        color: #111;
        padding: 0 3px;
        border-radius: 4px;
        font-weight: 700;
    }
</style>

<section class="customer-panel">
    <div class="messages-container">
        <div class="messages-header">
            <div style="flex: 1;">
                <h2 style="color:#262626;">Support Inbox</h2>
                <p>Chat with our support team</p>
            </div>

            <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; margin-right: 15px;">
                <div style="position: relative; display:flex; align-items:center; gap:8px;">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search messages..." style="padding: 8px 12px 8px 35px; border: 1px solid #dbdbdb; border-radius: 20px; font-size: 0.85rem; width: 200px;" id="chat-search-input">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #8e8e8e; font-size: 0.8rem;"></i>
                </div>

                <?php if (!empty($search_query)): ?>
                    <button type="button" id="chat-search-prev" style="background:#6366f1;color:white;border:none;border-radius:999px;padding:6px 12px;font-size:0.8rem;font-weight:700;cursor:pointer;">Prev</button>
                    <button type="button" id="chat-search-next" style="background:#4f46e5;color:white;border:none;border-radius:999px;padding:6px 12px;font-size:0.8rem;font-weight:700;cursor:pointer;">Next</button>
                    <a href="messages.php" style="color: #6366f1; font-size: 0.8rem; font-weight: 600;">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($unread_count > 0): ?>
                <div class="unread-badge"><?php echo (int)$unread_count; ?></div>
            <?php endif; ?>
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
                    <p>No messages yet. Start a conversation by sending a message below!</p>
                </div>
            <?php else: ?>
                <?php 
                // FIX: Hanapin ang nag-iisang ID ng pinakahuling sent message ng customer na nabasa na ng admin
                $last_seen_msg_id = null;
                if (!empty($messages)) {
                    // Iterate through the messages in chronological order (already reversed for display)
                    // to find the most recent customer message that has been read.
                    // By removing the break, $last_seen_msg_id will correctly hold the ID of the latest read customer message.
                    foreach ($messages as $msg) {
                        if ($msg['sender_type'] === 'customer' && $msg['is_read'] == 1) {
                            $last_seen_msg_id = $msg['id'];
                        }
                    }
                }

                foreach ($messages as $msg): 
                    if ($msg['sender_type'] === 'system'): ?>
                        <div class="system-message" style="align-self: center; width: 100%; text-align: center; margin: 15px 0; font-size: 0.8rem; color: #8e8e8e; font-weight: 600;">
                            <span style="background: #f0f2f5; padding: 5px 15px; border-radius: 12px;"><?php echo htmlspecialchars($msg['message']); ?></span>
                        </div>
                    <?php continue; endif;

                    $is_sent = $msg['sender_type'] === 'customer';
                ?>
                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                        <div class="message-sender">
                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                            <?php if (!$is_sent): // If it's a received message (from admin/staff) ?>
                                <i class="fas fa-user-shield" style="color: #4a3e94; margin-left: 5px;" title="Verified Staff"></i>
                            <?php endif; ?>
                        </div>
                    <div class="message-content">
                            <?php
                            $escapedMessage = htmlspecialchars($msg['message']);
                            if (!empty($search_query)) {
                                // Highlight searched words in message text (case-insensitive)
                                $pattern = '/(' . preg_quote($search_query, '/') . ')/i';
                                $escapedMessage = preg_replace($pattern, '<mark class="chat-search-highlight">$1</mark>', $escapedMessage);
                            }
                            ?>
                            <div class="message-text"><?php echo nl2br($escapedMessage); ?></div>
                        </div>
                        <div class="message-time">
                            <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                        </div>
                        
                        <?php if ($is_sent && $msg['id'] === $last_seen_msg_id): ?>
                            <div class="seen-status">Seen</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" class="message-form" id="customer-chat-form">
            <input type="hidden" name="action" value="send_message">
            <div class="msg-form-group">
                <textarea name="message" id="customer-message-input" placeholder="Type your message here..." required></textarea>
                <button type="submit">Send</button>
            </div>
        </form>
    </div>
    <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
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

                // When admin replies, server should broadcast new_message for this customer_id.
                if (data.type === 'new_message' && data.customer_id == customerId) {
                    // Refresh via REST for consistency
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
                const originalText = msgInput.value;
                msgInput.value = '';

                try {
                const response = await fetch('messages.php?action=send_message', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json().catch(() => (null));
                    if (result && result.success) {
                        // Let WS/server reload show the new messages (prevents double-save)
                        return;
                    }
                    // If server returns HTML redirect (not JSON), fall back to normal form submit
                    msgInput.value = originalText;
                    chatForm.removeEventListener('submit', () => {});
                    chatForm.submit();
                    return;
                } catch (err) {
                    msgInput.value = originalText;
                    console.error(err);
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
        // Scroll messages container (not the whole window)
        try {
            const container = messagesList;
            const elRect = el.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();
            const offsetTop = elRect.top - containerRect.top + container.scrollTop;
            container.scrollTop = offsetTop - 20;

            // Flash highlight
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

        // Disable WS reload while user is viewing search results to prevent resetting highlights/search state
        if (<?php echo !empty($search_query) ? 'true' : 'false'; ?>) {
            // no-op
        }

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

        // If searching, immediately jump to first match
        if (<?php echo !empty($search_query) ? 'true' : 'false'; ?>) {
            refreshChatSearchMatches();
            focusCurrentHighlight();
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
