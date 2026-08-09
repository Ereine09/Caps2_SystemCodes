<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

require_once __DIR__ . '/../app/helpers/messaging_helper.php';
messaging_ensure_schema($conn);

// Fetch assigned rider server-side for first paint.
$rider_stmt = $conn->prepare(
    "SELECT o.rider_id, o.id AS order_id, o.order_number, o.order_status,
            u.id AS rider_user_id,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS rider_name,
            r.vehicle_type, r.plate_number
     FROM tbl_orders o
     JOIN riders r ON r.id = o.rider_id
     JOIN users u ON u.id = r.user_id
     WHERE o.customer_id = ? AND o.rider_id IS NOT NULL
       AND o.order_status NOT IN ('completed', 'cancelled')
     ORDER BY o.created_at DESC
     LIMIT 1"
);
$rider_stmt->bind_param('i', $customer_id);
$rider_stmt->execute();
$rider = $rider_stmt->get_result()->fetch_assoc();
$rider_stmt->close();

// Fallback: if there is no *active* order anymore (e.g. delivered/completed),
// still show the conversation with the most recent rider who actually messaged
// this customer so the chat thread is never lost (matches the unread badge which
// counts rider messages regardless of order status).
if (!$rider) {
    $fb_stmt = $conn->prepare(
        "SELECT NULL AS rider_id, NULL AS order_id, NULL AS order_number, NULL AS order_status,
                u.id AS rider_user_id,
                CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS rider_name,
                r.vehicle_type, r.plate_number
         FROM tbl_messages m
         JOIN users u ON u.id = m.user_id
         LEFT JOIN riders r ON r.user_id = u.id
         WHERE m.customer_id = ? AND m.sender_type IN ('customer','rider') AND m.user_id IS NOT NULL
         ORDER BY m.created_at DESC
         LIMIT 1"
    );
    $fb_stmt->bind_param('i', $customer_id);
    $fb_stmt->execute();
    $rider = $fb_stmt->get_result()->fetch_assoc();
    $fb_stmt->close();
}

// Fetch existing conversation (RIDER thread only). We must scope by the
// rider's user_id so that the customer's SUPPORT messages (sent to admin with
// user_id = NULL) are NOT mixed into this rider chat. Both customer->rider and
// rider->customer messages carry the rider's user_id.
$messages = [];
if ($rider) {
    $rider_thread_filter = messaging_rider_thread_filter('m');
    $msg_stmt = $conn->prepare(
        "SELECT m.id, m.sender_type, m.message, m.is_read, m.created_at
         FROM tbl_messages m
         WHERE m.customer_id = ? AND $rider_thread_filter
         ORDER BY m.created_at ASC"
    );
    $msg_stmt->bind_param('i', $customer_id);
    $msg_stmt->execute();
    $messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $msg_stmt->close();

    // Mark rider messages read on view.
    $mark = $conn->prepare(
        "UPDATE tbl_messages SET is_read = 1, read_at = NOW()
         WHERE customer_id = ? AND sender_type = 'rider' AND is_read = 0"
    );
    $mark->bind_param('i', $customer_id);
    $mark->execute();
    $mark->close();
}
?>
<?php $page_title = 'Message Rider'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    :root {
        --rider-primary: #0d9488;
        --rider-primary-hover: #0f766e;
        --chat-bg: #f2fbf9;
        --border-color: #e2e8f0;
        --text-dark: #1e293b;
        --text-muted: #64748b;
    }
    .rider-chat-container {
        background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        border: 1px solid var(--border-color); overflow: hidden; margin-top: 15px;
        display: flex; flex-direction: column; height: calc(100vh - 180px);
        min-height: 520px; width: 100%; max-width: 100%; box-sizing: border-box;
    }
    .rider-chat-header {
        background: #fff; padding: 16px 24px; display: flex; justify-content: space-between;
        align-items: center; border-bottom: 1px solid var(--border-color); gap: 15px; flex-wrap: wrap;
    }
    .rider-avatar { width: 42px; height: 42px; border-radius: 50%; background: var(--rider-primary);
        color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .rider-chat-header h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-dark); }
    .rider-chat-header p { color: var(--text-muted); font-size: 0.82rem; margin: 2px 0 0; display: flex; align-items: center; gap: 6px; }
    .status-dot { width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block; }
    .rider-messages-list { padding: 20px 24px; flex: 1; overflow-y: auto; background: var(--chat-bg);
        display: flex; flex-direction: column; gap: 12px; }
    .rider-message { max-width: 75%; display: flex; flex-direction: column; width: fit-content; }
    .rider-message.sent { align-self: flex-end; align-items: flex-end; }
    .rider-message.received { align-self: flex-start; align-items: flex-start; }
    .rider-message .sender { font-size: 0.78rem; font-weight: 700; margin-bottom: 4px; padding: 0 4px; }
    .rider-message.sent .sender { color: var(--text-muted); }
    .rider-message.received .sender { color: var(--rider-primary); }
    .rider-message .bubble { padding: 12px 18px; border-radius: 18px; font-size: 0.92rem; line-height: 1.45; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .rider-message.sent .bubble { background: var(--rider-primary); color: #fff; border-bottom-right-radius: 4px; }
    .rider-message.received .bubble { background: #fff; color: var(--text-dark); border: 1px solid var(--border-color); border-bottom-left-radius: 4px; }
    .rider-message .time { font-size: 0.68rem; color: #94a3b8; margin-top: 4px; padding: 0 6px; }
    .rider-message.sent .time { text-align: right; }
    .rider-chat-form { padding: 16px 24px; background: #fff; border-top: 1px solid var(--border-color); }
    .rider-form-group { display: flex; gap: 12px; align-items: center; }
    .rider-form-group textarea { flex: 1; padding: 11px 18px; border: 1px solid var(--border-color); border-radius: 22px;
        font-family: inherit; font-size: 0.92rem; resize: none; height: 44px; background: #f8fafc; outline: none; box-sizing: border-box; }
    .rider-form-group textarea:focus { background: #fff; border-color: var(--rider-primary); box-shadow: 0 0 0 3px rgba(13,148,136,0.1); }
    .rider-form-group button { background: var(--rider-primary); color: #fff; border: none; padding: 0 24px; height: 44px;
        border-radius: 22px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .rider-form-group button:hover { background: var(--rider-primary-hover); }
    .rider-form-group button:disabled { opacity: 0.6; cursor: not-allowed; }
    .quick-suggests { display: flex; gap: 8px; flex-wrap: wrap; padding: 10px 24px 0; background: #fff; }
    .quick-chip { background: #e6f7f4; color: var(--rider-primary); border: 1px solid #b7e8e0; border-radius: 20px;
        padding: 6px 14px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .quick-chip:hover { background: var(--rider-primary); color: #fff; }
    .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); margin: auto; }
    .empty-state i { font-size: 2.8rem; color: #cbd5e1; margin-bottom: 12px; }
    .no-rider-box { background: #fff; border-radius: 16px; padding: 40px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-top: 15px; }
    .no-rider-box i { font-size: 3rem; color: #cbd5e1; margin-bottom: 14px; }
    .no-rider-box h3 { color: var(--text-dark); margin: 0 0 8px; }
    .no-rider-box p { color: var(--text-muted); margin: 0 0 18px; }
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); text-decoration: none;
        font-weight: 600; font-size: 0.9rem; padding: 8px 14px; border-radius: 8px; background: #fff;
        border: 1px solid var(--border-color); margin-top: 18px; }
</style>

<section class="customer-panel">
    <?php if (!$rider): ?>
        <div class="no-rider-box">
            <i class="fas fa-motorcycle"></i>
            <h3>No active rider yet</h3>
            <p>Once a rider accepts your delivery order, you'll be able to chat with them here to check their location or share delivery instructions.</p>
            <a href="orders.php" class="back-link"><i class="fas fa-shopping-bag"></i> View My Orders</a>
        </div>
    <?php else: ?>
        <div class="rider-chat-container">
            <div class="rider-chat-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div class="rider-avatar"><i class="fas fa-motorcycle"></i></div>
                    <div>
                        <h2><?php echo htmlspecialchars($rider['rider_name']); ?></h2>
                        <p><span class="status-dot"></span> Delivering your order
                            <?php if (!empty($rider['plate_number'])): ?>
                                · Plate: <?php echo htmlspecialchars($rider['plate_number']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted);">
                    Order #<?php echo htmlspecialchars($rider['order_number']); ?>
                </div>
            </div>

            <div class="quick-suggests">
                <button type="button" class="quick-chip" data-msg="Where are you right now?">📍 Where are you?</button>
                <button type="button" class="quick-chip" data-msg="Please prepare the exact amount.">💰 Prepare exact amount</button>
                <button type="button" class="quick-chip" data-msg="I'll get ready to receive the delivery.">🤝 I'm ready to receive</button>
            </div>

            <div class="rider-messages-list" id="rider-messages-list">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p style="font-weight:600; color:var(--text-dark); margin-bottom:4px;">No messages yet</p>
                        <p style="font-size:0.88rem; margin:0;">Say hello to your rider to coordinate the delivery.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg):
                        $isSent = $msg['sender_type'] === 'customer';
                        $created = DateTime::createFromFormat('Y-m-d H:i:s', $msg['created_at']);
                        $time = $created ? $created->format('M d, h:i A') : $msg['created_at'];
                        $sender = $isSent ? 'You' : htmlspecialchars($rider['rider_name']);
                    ?>
                        <div class="rider-message <?php echo $isSent ? 'sent' : 'received'; ?>">
                            <div class="sender"><?php echo $sender; ?></div>
                            <div class="bubble"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="time"><?php echo $time; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form class="rider-chat-form" id="rider-chat-form">
                <div class="rider-form-group">
                    <textarea id="rider-message-input" placeholder="Type your message to your rider..." required></textarea>
                    <button type="submit" id="rider-send-btn"><i class="fas fa-paper-plane"></i> <span>Send</span></button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($rider): ?>
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <?php endif; ?>
</section>

<?php if ($rider): ?>
<script>
    const riderMessagesList = document.getElementById('rider-messages-list');
    const customerId = <?php echo (int)$customer_id; ?>;
    const riderName = <?php echo json_encode($rider['rider_name']); ?>;

    function scrollToBottom() {
        if (riderMessagesList) {
            riderMessagesList.scrollTop = riderMessagesList.scrollHeight;
        }
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : '';
    }

    // Real-time via the shared WS server (customer connections keyed by customer_id).
    let ws = null;
    function setupWs() {
        if (!window.WebSocket) return;
        const token = getCookie('jwt_token') || getCookie('JWT_TOKEN') || '';
        if (!token) return;
        ws = new WebSocket(`ws://localhost:8080/?token=${encodeURIComponent(token)}`);
        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'new_message' && data.customer_id == customerId) {
                    window.location.reload();
                }
            } catch (e) { console.error(e); }
        };
        ws.onerror = (e) => console.error('WS error', e);
    }

    async function sendMessage(text) {
        const btn = document.getElementById('rider-send-btn');
        const input = document.getElementById('rider-message-input');
        btn.disabled = true;
        try {
            const res = await fetch('rider_chat_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ action: 'send_message', message: text })
            });
            const data = await res.json();
            if (data.success) {
                input.value = '';
                window.location.reload();
            } else {
                alert(data.message || 'Failed to send message.');
            }
        } catch (e) {
            console.error(e);
            alert('Failed to send message.');
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        scrollToBottom();
        setupWs();

        const form = document.getElementById('rider-chat-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const input = document.getElementById('rider-message-input');
                const text = input.value.trim();
                if (!text) return;
                sendMessage(text);
            });
        }

        document.querySelectorAll('.quick-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                sendMessage(chip.getAttribute('data-msg'));
            });
        });
    });
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
