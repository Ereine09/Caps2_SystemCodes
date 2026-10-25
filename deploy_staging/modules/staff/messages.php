<?php
/**
 * Staff — Messaging UI.
 * Talks only to modules/staff/messaging_api.php (never the admin API).
 * Visual design mirrors modules/admin/messages.php for a consistent look.
 * Styles live in assets/css/staff_style.css.
 */

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload   = require_staff();
$user_id   = (int) $payload['user_id'];
$user_role = 'staff';

// Handle a non-AJAX message send (progressive-enhancement fallback).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    csrf_require();
    $customer_id = (int) ($_POST['customer_id'] ?? 0);
    $message     = trim($_POST['message'] ?? '');
    if ($customer_id > 0 && $message !== '') {
        if (send_message($customer_id, $user_id, 'staff', $message)) {
            $_SESSION['message'] = 'Message sent successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Failed to send message.';
            $_SESSION['message_type'] = 'error';
        }
    }
    header('Location: messages.php?customer_id=' . $customer_id);
    exit();
}

$selected_customer_id = (int) ($_GET['customer_id'] ?? 0);
$conversation = [];
$selected_customer = null;

if ($selected_customer_id > 0) {
    $conversation = get_conversation($selected_customer_id, $user_id);

    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param('i', $selected_customer_id);
    $stmt->execute();
    $selected_customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conversation) {
        foreach ($conversation as $msg) {
            if ((int) $msg['is_read'] === 0 && $msg['sender_type'] === 'customer') {
                mark_as_read($msg['id']);
            }
        }
        $conversation = get_conversation($selected_customer_id, $user_id);
    }
}

$customers_with_messages = get_customers_with_messages();
$unread_count            = get_unread_count_staff($user_id);
$unread_messages_count   = $unread_count;
$pending_orders_count    = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
    <title>Messages - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff_style.css">
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-title" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h1 style="margin: 0; font-size: 1.5rem; color: #1e293b;"><i class="fas fa-comments" style="color: var(--chat-primary);"></i> Messaging System</h1>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert show <?php echo htmlspecialchars($_SESSION['message_type']); ?>">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message'], $_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <div class="messages-wrapper<?php echo $selected_customer_id > 0 ? ' has-conversation' : ''; ?>">
            <!-- Customers List Sidebar -->
            <div class="customers-list" id="customers-sidebar">
                <div class="customers-list-header" id="sidebar-header">
                    <span><i class="fas fa-inbox" style="margin-right: 8px;"></i> Conversations</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="sidebar-total-unread"><?php echo (int) $unread_count; ?> new</span>
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
                             onclick="location.href='?customer_id=<?php echo (int) $customer['id']; ?>'">
                            <div class="customer-name">
                                <span><?php echo htmlspecialchars($customer['name']); ?></span>
                            </div>
                            <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                            <?php if ($customer['unread_count'] > 0): ?>
                                <span class="customer-unread"><?php echo (int) $customer['unread_count']; ?></span>
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
                        foreach ($conversation as $msg) {
                            if (in_array($msg['sender_type'], ['admin', 'staff'], true) && (int) $msg['is_read'] === 1) {
                                $last_seen_msg_id = $msg['id'];
                            }
                        }
                        if (empty($conversation)): ?>
                            <div class="no-conversation">
                                <i class="fas fa-paper-plane"></i>
                                <p>No messages yet. Start the conversation below!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversation as $msg): ?>
                                <?php if ($msg['sender_type'] === 'system'): ?>
                                    <div class="system-message" style="align-self: center; width: 100%; text-align: center; margin: 12px 0; font-size: 0.8rem; color: #64748b; font-weight: 600;">
                                        <span style="background: #e2e8f0; padding: 5px 16px; border-radius: 12px; color: #475569;"><?php echo htmlspecialchars($msg['message']); ?></span>
                                    </div>
                                <?php else:
                                    $is_sent = in_array($msg['sender_type'], ['admin', 'staff'], true);
                                ?>
                                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                                        <div class="message-sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <?php if ($is_sent): ?><i class="fas fa-user-shield" style="color: var(--chat-primary); margin-left: 4px;" title="Verified Staff"></i><?php endif; ?>
                                        </div>
                                        <div class="message-content"><?php echo htmlspecialchars($msg['message']); ?></div>
                                        <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                        <?php if ($is_sent && $msg['id'] === $last_seen_msg_id): ?><div class="seen-status"><i class="fas fa-check-double"></i> Seen</div><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="conversation-input" id="chat-form">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                        <input type="hidden" name="customer_id" value="<?php echo (int) $selected_customer_id; ?>">
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

    <audio id="notification-sound" preload="auto">
        <source src="https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3" type="audio/mpeg">
    </audio>

    <script>
        const conversationMessages = document.getElementById('conversation-messages');
        const selectedCustomerId = <?php echo (int) $selected_customer_id; ?>;
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

        async function fetchMessages() {
            if (selectedCustomerId <= 0) return;
            try {
                const response = await fetch(`messaging_api.php?action=get_messages&customer_id=${selectedCustomerId}`);
                const result = await response.json();
                if (result.success && result.data) renderMessages(result.data);
            } catch (error) {
                console.error('Auto-refresh error:', error);
            }
        }

        let lastMaxMsgId = -1;
        function maybeNotify(messages) {
            let maxId = -1, gotInbound = false;
            messages.forEach(m => {
                const id = Number(m.id);
                if (id > maxId) maxId = id;
                if (id > lastMaxMsgId && m.sender_type === 'customer') gotInbound = true;
            });
            if (lastMaxMsgId !== -1 && gotInbound) {
                const audio = document.getElementById('notification-sound');
                if (audio) { try { audio.currentTime = 0; audio.play().catch(() => {}); } catch (e) {} }
            }
            if (maxId > lastMaxMsgId) lastMaxMsgId = maxId;
        }

        function renderMessages(messages) {
            maybeNotify(messages);

            let lastSeenId = null;
            messages.forEach(msg => { if ((msg.sender_type === 'staff' || msg.sender_type === 'admin') && msg.is_read == 1) lastSeenId = msg.id; });

            const html = messages.map(msg => {
                if (msg.sender_type === 'system') {
                    return `<div class="system-message" style="width:100%; text-align:center; margin:15px 0; font-size:0.8rem; color:#8e8e8e; font-weight:600;">
                        <span style="background:#e2e8f0; padding:5px 15px; border-radius:12px; color:#475569;">${escapeHtml(msg.message)}</span></div>`;
                }
                const isSent = msg.sender_type === 'staff' || msg.sender_type === 'admin';
                const seenStatus = (isSent && msg.id === lastSeenId) ? '<div class="seen-status"><i class="fas fa-check-double"></i> Seen</div>' : '';
                const time = new Date(msg.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).replace(',', '');
                return `<div class="message ${isSent ? 'sent' : 'received'}">
                    <div class="message-sender">${escapeHtml(msg.sender_name)}${isSent ? ' <i class="fas fa-user-shield" style="color:var(--chat-primary); margin-left:4px;" title="Verified Staff"></i>' : ''}</div>
                    <div class="message-content">${escapeHtml(msg.message)}</div>
                    <div class="message-time">${time}</div>${seenStatus}</div>`;
            }).join('');

            if (conversationMessages.innerHTML !== html) {
                const atBottom = conversationMessages.scrollHeight - conversationMessages.scrollTop <= conversationMessages.clientHeight + 100;
                conversationMessages.innerHTML = html;
                if (atBottom) scrollToBottom();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text ?? '';
            return div.innerHTML;
        }
        function scrollToBottom() { if (conversationMessages) conversationMessages.scrollTop = conversationMessages.scrollHeight; }

        /**
         * Re-render the left conversation list from the API so new chats,
         * unread badges and ordering update without a page refresh.
         */
        function renderConversations(list) {
            const container = document.getElementById('conversations-list');
            if (!container) return;

            let html;
            if (!list || list.length === 0) {
                html = '<div class="empty-list"><i class="fas fa-comment-slash" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i><p style="margin: 0;">No active conversations</p></div>';
            } else {
                html = list.map(c => {
                    const id = Number(c.id);
                    const active = (id === selectedCustomerId) ? ' active' : '';
                    const unread = Number(c.unread_count) > 0
                        ? `<span class="customer-unread">${Number(c.unread_count)}</span>` : '';
                    let t = '';
                    if (c.last_message_time) {
                        t = new Date(String(c.last_message_time).replace(' ', 'T'))
                            .toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
                    }
                    return `<div class="customer-item${active}" onclick="location.href='?customer_id=${id}'">
                        <div class="customer-name"><span>${escapeHtml(c.name)}</span></div>
                        <div class="customer-email">${escapeHtml(c.email)}</div>
                        ${unread}
                        <div class="customer-last-msg"><i class="far fa-clock"></i> ${t}</div>
                    </div>`;
                }).join('');
            }
            if (container.innerHTML.replace(/\s+/g, ' ').trim() !== html.replace(/\s+/g, ' ').trim()) {
                container.innerHTML = html;
            }

            const totalUnread = (list || []).reduce((s, c) => s + Number(c.unread_count || 0), 0);
            const header = document.getElementById('sidebar-header');
            if (header) {
                let badge = header.querySelector('.sidebar-total-unread');
                if (totalUnread > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'sidebar-total-unread';
                        header.appendChild(badge);
                    }
                    badge.textContent = totalUnread + ' new';
                } else if (badge) {
                    badge.remove();
                }
            }
        }

        async function fetchConversations() {
            try {
                const res = await fetch('messaging_api.php?action=get_conversations');
                const result = await res.json();
                if (result.success && Array.isArray(result.data)) renderConversations(result.data);
            } catch (e) {
                console.error('Conversation refresh error:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            scrollToBottom();
            setupWs();
            fetchConversations();
            setInterval(fetchConversations, 6000);

            const chatForm = document.getElementById('chat-form');
            if (chatForm) {
                chatForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const formData = new FormData(chatForm);
                    formData.set('action', 'send_message');
                    formData.set('csrf_token', CSRF_TOKEN);

                    const msgInput = document.getElementById('message-input');
                    const originalText = msgInput.value;
                    msgInput.value = '';

                    try {
                        const response = await fetch('messaging_api.php?action=send_message', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': CSRF_TOKEN },
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            try {
                                if (ws && ws.readyState === WebSocket.OPEN) {
                                    ws.send(JSON.stringify({ type: 'new_message', customer_id: selectedCustomerId, message: originalText }));
                                }
                            } catch (err) { console.error('WS send failed', err); }
                            fetchMessages();
                        } else {
                            msgInput.value = originalText;
                            alert('Failed to send: ' + (result.message || 'unknown error'));
                        }
                    } catch (error) {
                        msgInput.value = originalText;
                        console.error('Send error:', error);
                    }
                });

                // Enter = send, Shift+Enter = newline.
                const msgInputEl = document.getElementById('message-input');
                if (msgInputEl) {
                    msgInputEl.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                            e.preventDefault();
                            if (msgInputEl.value.trim() !== '') chatForm.requestSubmit();
                        }
                    });
                }
            }

            setInterval(fetchMessages, 4000);
        });

        // --- WebSocket realtime ---
        let ws = null;
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : '';
        }
        function setupWs() {
            if (!window.WebSocket) return;
            const token = getCookie('jwt_token_staff');   // correct staff cookie name
            if (!token) return;
            // Derive the WS host from the page's own hostname (works on
            // localhost, LAN IPs, and a real domain alike) instead of a
            // hardcoded "localhost" that would only ever resolve to the
            // visitor's own machine in production.
            ws = new WebSocket(`ws://${window.location.hostname}:<?php echo WS_PORT; ?>/?token=${encodeURIComponent(token)}`);
            ws.onopen = () => console.log('WS connected');
            ws.onclose = () => console.log('WS disconnected');
            ws.onerror = (e) => console.error('WS error', e);
            ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    if (data && data.type === 'new_message' && data.customer_id == selectedCustomerId) fetchMessages();
                } catch (err) { console.error(err); }
            };
        }
    </script>
</body>
</html>
