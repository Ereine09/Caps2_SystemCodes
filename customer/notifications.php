<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

require_once __DIR__ . '/../app/helpers/notification_helper.php';
notifications_ensure_schema($conn);

// Fetch unread count + recent notifications server-side for fast first paint.
$unread_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE customer_id = ? AND is_read = 0");
$unread_stmt->bind_param('i', $customer_id);
$unread_stmt->execute();
$unread_count = (int)($unread_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$unread_stmt->close();

$list_stmt = $conn->prepare(
    "SELECT id, type, title, message, reference_table, reference_id, is_read, created_at
     FROM notifications
     WHERE customer_id = ?
     ORDER BY created_at DESC
     LIMIT 100"
);
$list_stmt->bind_param('i', $customer_id);
$list_stmt->execute();
$notifications = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();
?>
<?php $page_title = 'Notifications'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .notif-container { max-width: 780px; }
    .notif-header {
        display: flex; justify-content: space-between; align-items: center;
        background: #fff; padding: 18px 22px; border-radius: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05); margin-bottom: 18px;
        flex-wrap: wrap; gap: 10px;
    }
    .notif-header h1 { margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    .notif-count-badge {
        background: #6366f1; color: #fff; font-size: 0.75rem; font-weight: 700;
        padding: 3px 10px; border-radius: 999px;
    }
    .notif-list { display: flex; flex-direction: column; gap: 12px; }
    .notif-card {
        background: #fff; border-radius: 12px; padding: 16px 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        border-left: 4px solid #cbd5e1;
        display: flex; gap: 14px; align-items: flex-start;
    }
    .notif-card.unread { border-left-color: #6366f1; background: #f8f9ff; }
    .notif-icon {
        width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: #eef2ff; color: #6366f1; font-size: 1rem;
    }
    .notif-body { flex: 1; }
    .notif-title { font-weight: 700; color: #1e293b; font-size: 0.95rem; margin: 0 0 4px; }
    .notif-msg { color: #64748b; font-size: 0.88rem; margin: 0 0 6px; line-height: 1.45; }
    .notif-time { color: #94a3b8; font-size: 0.75rem; }
    .notif-dot { width: 8px; height: 8px; background: #6366f1; border-radius: 50%; margin-top: 6px; flex-shrink: 0; }
    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; background: #fff; border-radius: 12px; }
    .empty-state i { font-size: 2.6rem; color: #cbd5e1; margin-bottom: 12px; }
    .mark-all-btn {
        background: #6366f1; color: #fff; border: none; padding: 10px 18px;
        border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.85rem;
        display: inline-flex; align-items: center; gap: 8px;
    }
    .mark-all-btn:hover { background: #4f46e5; }
    .back-link {
        display: inline-flex; align-items: center; gap: 8px; color: #64748b;
        text-decoration: none; font-weight: 600; font-size: 0.9rem;
        padding: 8px 14px; border-radius: 8px; background: #fff;
        border: 1px solid #e2e8f0; margin-top: 18px;
    }
</style>

<section class="customer-panel">
    <div class="notif-container">
        <div class="notif-header">
            <h1>
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="notif-count-badge"><?php echo (int)$unread_count; ?> new</span>
                <?php endif; ?>
            </h1>
            <?php if ($unread_count > 0): ?>
                <button class="mark-all-btn" id="mark-all-btn">
                    <i class="fas fa-check-double"></i> Mark all as read
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p style="font-weight:600; color:#64748b; margin:0 0 4px;">No notifications yet</p>
                <p style="font-size:0.88rem; margin:0;">You'll be notified here when your rider accepts an order.</p>
            </div>
        <?php else: ?>
            <div class="notif-list" id="notif-list">
                <?php foreach ($notifications as $n):
                    $isRead = (int)$n['is_read'] === 1;
                    $created = DateTime::createFromFormat('Y-m-d H:i:s', $n['created_at']);
                    $time = $created ? $created->format('M d, Y h:i A') : $n['created_at'];
                    $icon = 'fas fa-info-circle';
                    $link = '#'; // Default link

                    // Determine icon and link based on notification type
                    switch ($n['type']) {
                        case 'order_accepted':
                        case 'order_confirmed':
                        case 'order_status_update':
                            $icon = 'fas fa-shopping-bag';
                            if (!empty($n['reference_table']) && $n['reference_table'] === 'tbl_orders' && !empty($n['reference_id'])) {
                                $link = 'orders.php?id=' . (int)$n['reference_id'];
                            }
                            break;
                        case 'rider_message':
                            $icon = 'fas fa-comment-dots';
                            $link = 'rider_chat.php';
                            break;
                    }
                ?>
                    <a href="<?php echo $link; ?>" class="notif-card <?php echo $isRead ? '' : 'unread'; ?>" style="text-decoration: none; display: flex;">
                        <div class="notif-icon"><i class="<?php echo $icon; ?>"></i></div>
                        <div class="notif-body">
                            <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                            <p class="notif-msg"><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>
                            <span class="notif-time"><?php echo htmlspecialchars($time); ?></span>
                        </div>
                        <?php if (!$isRead): ?>
                            <div class="notif-dot"></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const markAllBtn = document.getElementById('mark-all-btn');
        if (!markAllBtn) return;

        markAllBtn.addEventListener('click', async function () {
            markAllBtn.disabled = true;
            try {
                const res = await fetch('notifications_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'mark_all_read' })
                });
                const data = await res.json();
                if (data.success) {
                    document.querySelectorAll('.notif-card.unread').forEach(card => card.classList.remove('unread'));
                    const badge = document.querySelector('.notif-count-badge');
                    if (badge) badge.remove();
                    markAllBtn.remove();
                }
            } catch (e) {
                console.error(e);
            } finally {
                markAllBtn.disabled = false;
            }
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
