<?php
session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

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

// Admin Broadcast / Announcements - reuses the EXISTING notifications table
// and notifications_create() pipeline (same one every order/points/pickup
// notification already goes through). One row per real customer, so it
// shows up automatically in each customer's existing Notifications page -
// no new customer-facing feature, no second notification system.
// Admin-only (a store-wide broadcast is a bigger action than a 1:1 reply,
// which staff can already send).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_broadcast') {
    if ($user_role !== 'admin') {
        http_response_code(403);
        exit('Only admins can send broadcasts.');
    }

    require_once __DIR__ . '/../../app/helpers/notification_helper.php';
    require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
    csrf_require();

    $broadcast_title = trim($_POST['broadcast_title'] ?? '');
    $broadcast_message = trim($_POST['broadcast_message'] ?? '');
    $also_email = isset($_POST['broadcast_email']);

    if ($broadcast_title === '' || $broadcast_message === '') {
        $_SESSION['message'] = 'Broadcast title and message are required.';
        $_SESSION['message_type'] = 'error';
    } else {
        $recipients_res = $conn->query("SELECT id, email FROM customers");
        $sent_count = 0;
        while ($recipient = $recipients_res->fetch_assoc()) {
            $ok = notifications_create($conn, [
                'customer_id' => (int) $recipient['id'],
                'type'        => 'announcement',
                'channel'     => $also_email ? 'both' : 'in_app',
                'title'       => $broadcast_title,
                'message'     => $broadcast_message,
                'email_to'    => $recipient['email'],
            ]);
            if ($ok) {
                $sent_count++;
            }
        }

        $log_details = "Sent broadcast \"$broadcast_title\" to $sent_count customer(s)" . ($also_email ? ' (with email)' : ' (in-app only)') . ".";
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Broadcast Sent', ?)");
        $log_stmt->bind_param('is', $user_id, $log_details);
        $log_stmt->execute();
        $log_stmt->close();

        $_SESSION['message'] = "Broadcast sent to $sent_count customer(s).";
        $_SESSION['message_type'] = 'success';
    }

    header('Location: messages.php');
    exit;
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

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;

// Real recipient count for the Broadcast composer's confirmation step —
// the exact same unconditional "every customer" set the send_broadcast
// handler above already loops over (SELECT id, email FROM customers), so
// the number shown before sending can never drift from what actually gets
// sent. Admin-only feature, so only computed for admins.
$broadcast_recipient_count = 0;
if ($user_role === 'admin') {
    $broadcast_recipient_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM customers"))['c'] ?? 0);
}

// First letter of the first and last name, for the round avatars (display only).
function chat_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = mb_substr($parts[0] ?? '', 0, 1);
    if (count($parts) > 1) {
        $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
    }
    return mb_strtoupper($initials !== '' ? $initials : '?');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
<style>
    :root {
        --chat-primary: #4a3e94;
        --chat-primary-hover: #3b3178;
        --chat-bg: #f8fafc;
        --bubble-sent: #4a3e94;
        --bubble-received: #ffffff;
        --text-dark: #1e293b;
        --text-muted: #64748b;
        --border-color: var(--border);
    }

    body {
        background-color: var(--surface-2);
        overflow-x: hidden;
        margin: 0;
        font-family: inherit;
    }

    /* Messaging Grid Layout */
    .messages-wrapper {
        display: grid;
        grid-template-columns: 320px 1fr;
        height: calc(100vh - 120px);
        min-height: 520px;
        background: var(--surface);
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
        background: var(--surface);
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
        background: var(--surface);
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
        border-bottom: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .customer-item:hover {
        background: var(--surface-2);
    }

    .customer-item.active {
        background: var(--surface-2);
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
        color: var(--muted);
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
        background: var(--surface);
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .mobile-back-btn {
        display: none;
        flex-shrink: 0;
        color: var(--chat-primary);
        font-size: 1.1rem;
        text-decoration: none;
        padding: 6px 10px;
        border-radius: 8px;
        background: var(--surface-2);
    }

    /* Name + email block: must be allowed to shrink (min-width: 0) inside
       the flex row and truncate its own text, or a long name/email pushes
       the header wider than the card instead of ellipsizing. */
    .conversation-header-info {
        min-width: 0;
        flex: 1;
    }

    .conversation-header h3 {
        margin: 0;
        font-size: 1.05rem;
        color: var(--text-dark);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .conversation-header p {
        margin: 2px 0 0 0;
        font-size: 0.82rem;
        color: var(--text-muted);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Messages Container & Bubbles */
    .conversation-messages {
        flex: 1;
        padding: 20px 24px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .message {
        display: flex;
        flex-direction: column;
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
        width: fit-content;
        max-width: 100%;
        padding: 12px 18px;
        border-radius: 18px;
        font-size: 0.92rem;
        line-height: 1.45;
        /* Render the message exactly as typed: preserve real line breaks,
           wrap normally between words, hard-break only an unbreakable run. */
        white-space: pre-wrap;
        overflow-wrap: break-word;
        word-break: normal;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
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
        color: var(--muted);
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
        background: var(--surface);
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
        background: var(--surface-2);
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .input-group textarea:focus {
        background: var(--surface);
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
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
    }
    .alert.success { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
    .alert.error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }
    /* Dark-mode values for .alert.success/.alert.error already come from
       assets/css/admin_style.css (identical colors, defined centrally) - not
       repeated here. */

    /* Responsive Media Queries
       Aligned to 900px to match the shared Admin/Staff mobile drawer
       breakpoint in assets/css/admin_style.css (below that width, its
       56px-tall .mobile-topbar becomes visible above .main-content).
       Sizing this card with a fixed-number `calc(100vh - 140px)` meant it
       had to be re-tuned by hand every time something above it changed
       height — that's exactly what went stale when the topbar was added,
       leaving the card taller than the actual remaining viewport space and
       forcing a scroll that cropped the header row. Using flex layout with
       min-height: 0 instead makes it size itself to whatever room is
       actually left, so it can't drift out of sync again. */
    @media (max-width: 900px) {
        .main-content {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 56px);
            box-sizing: border-box;
            overflow: hidden;
        }
        .page-title,
        .alert {
            flex-shrink: 0;
        }

        .messages-wrapper {
            grid-template-columns: 1fr;
            flex: 1;
            min-height: 0;
            height: auto;
            border-radius: 12px;
        }

        <?php if ($selected_customer_id > 0): ?>
            .customers-list {
                display: none;
            }
            .conversation-area {
                display: flex;
            }
            .mobile-back-btn {
                display: inline-flex;
            }
        <?php else: ?>
            .customers-list {
                display: flex;
                width: 100%;
            }
            .conversation-area {
                display: none;
            }
        <?php endif; ?>

        .conversation-header {
            padding: 12px 16px;
            gap: 10px;
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
            display: none;
        }
    }

    /* ======================================================================
       "New Broadcast" header button.
       Root cause of the clipped/broken look: the element only ever had
       class="button" - a class that isn't actually defined anywhere in
       admin_style.css or this page (verified by search), so the browser
       fell back to native UA button chrome: 0 padding (zeroed by the site's
       own "* { padding:0 }" reset), a stark 2px outset black border, and
       default (near-invisible on a dark purple background) text color. That
       reads as "clipped/cut off" even though nothing was actually cropping
       it - confirmed live: the button's own bounding box never exceeded its
       container or the viewport at any tested size. A dedicated class here
       (scoped to this page only - .button itself is reused by 11 other
       admin/staff/customer pages, so styling it globally would have changed
       buttons on pages nobody asked to touch) gives it real padding, colors,
       and icon/text alignment, while flex-shrink:0 + white-space:nowrap
       (per the requested structure) stop it from ever wrapping/squeezing -
       .page-title already has display:flex + flex-wrap:wrap, so on a narrow
       screen the whole button just drops to its own line under the title
       instead of clipping.
       ====================================================================== */
    .new-broadcast-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
        flex-shrink: 0;
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        background: var(--chat-primary);
        color: #fff;
        font-family: inherit;
        font-size: 0.9rem;
        font-weight: 600;
        line-height: 1.2;
        cursor: pointer;
        box-shadow: 0 2px 6px rgba(74, 62, 148, 0.25);
        transition: background 0.15s ease;
    }
    .new-broadcast-btn:hover { background: var(--chat-primary-hover); }
    .new-broadcast-btn i { font-size: 0.95em; }

    /* ======================================================================
       BROADCAST COMPOSER
       Uses this page's own local CSS variables (--chat-primary, --text-dark,
       --text-muted, --border-color) wherever the surrounding chat UI already
       does — those are the exact variables assets/css/admin_style.css
       redefines under body.dark-mode (see its "DARK MODE — AUDIT PASS 2"
       section), so this whole panel follows Light/Dark Mode automatically,
       the same as the rest of this page. Raw var(--dm-surface*) tokens (also
       global, same file) fill in for panel/input backgrounds, which this
       page didn't already have a local variable for — identical technique to
       the pagination just added to orders.php / customers.php.
       ====================================================================== */
    .bc-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .bc-modal-shell {
        background: var(--dm-surface, #fff);
        color: var(--text-dark);
        border-radius: 16px;
        width: 100%;
        max-width: min(920px, calc(100vw - 32px));
        max-height: calc(100vh - 32px);
        overflow-y: auto;
        box-sizing: border-box;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }

    .bc-confirm-shell {
        background: var(--dm-surface, #fff);
        color: var(--text-dark);
        border-radius: 14px;
        width: 100%;
        max-width: min(440px, calc(100vw - 32px));
        box-sizing: border-box;
        padding: 26px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }
    .bc-confirm-shell h3 {
        margin: 0 0 12px;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bc-confirm-shell p {
        margin: 0 0 20px;
        color: var(--text-muted);
        line-height: 1.55;
        font-size: 0.92rem;
    }
    .bc-confirm-shell p strong { color: var(--text-dark); }
    .bc-confirm-actions { display: flex; gap: 10px; }

    .bc-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        padding: 24px 28px 18px;
        border-bottom: 1px solid var(--border-color);
    }
    .bc-modal-header h2 {
        margin: 0 0 4px;
        font-size: 1.2rem;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bc-modal-header h2 i { color: var(--chat-primary); }
    .bc-modal-header p {
        margin: 0;
        font-size: 0.85rem;
        color: var(--text-muted);
        line-height: 1.45;
    }
    .bc-modal-close {
        cursor: pointer;
        font-size: 1.5rem;
        line-height: 1;
        color: var(--text-muted);
        flex-shrink: 0;
    }
    .bc-modal-close:hover { color: var(--text-dark); }

    .bc-grid {
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        gap: 28px;
        padding: 22px 28px 28px;
        box-sizing: border-box;
    }

    .bc-field { margin-bottom: 16px; }
    .bc-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 6px;
    }
    .bc-hint {
        margin: 6px 0 0;
        font-size: 0.76rem;
        color: var(--text-muted);
    }
    .bc-input, .bc-textarea {
        width: 100%;
        padding: 11px 14px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-sizing: border-box;
        font-family: inherit;
        font-size: 0.92rem;
        background: var(--dm-surface-2, #f8fafc);
        color: var(--text-dark);
        transition: border-color .15s, box-shadow .15s;
    }
    .bc-input::placeholder, .bc-textarea::placeholder { color: var(--text-muted); }
    .bc-input:focus, .bc-textarea:focus {
        outline: none;
        border-color: var(--chat-primary);
        box-shadow: 0 0 0 3px rgba(74, 62, 148, 0.15);
        background: var(--dm-surface, #fff);
    }
    .bc-textarea {
        min-height: 160px;
        resize: vertical;
        line-height: 1.55;
    }

    .bc-char-counter {
        text-align: right;
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 6px;
    }
    .bc-char-counter.over { color: var(--warning); font-weight: 700; }

    .bc-recipients { display: flex; flex-direction: column; gap: 8px; }
    .bc-recipient-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        cursor: default;
    }
    .bc-recipient-option.selected {
        border-color: var(--chat-primary);
        background: rgba(74, 62, 148, 0.06);
    }
    .bc-recipient-option.disabled { opacity: 0.6; }
    .bc-recipient-option input[type="radio"] { accent-color: var(--chat-primary); flex-shrink: 0; }
    .bc-recipient-text { display: flex; flex-direction: column; gap: 1px; }
    .bc-recipient-text strong { font-size: 0.88rem; color: var(--text-dark); }
    .bc-recipient-text small { font-size: 0.76rem; color: var(--text-muted); }

    .bc-email-toggle {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 0.85rem;
        color: var(--text-dark);
        margin: 18px 0 4px;
        cursor: pointer;
    }
    .bc-email-toggle input { accent-color: var(--chat-primary); margin-top: 2px; flex-shrink: 0; }
    .bc-email-toggle em { color: var(--text-muted); font-style: normal; }

    .bc-actions { display: flex; gap: 10px; margin-top: 22px; }
    .bc-btn-cancel, .bc-btn-send {
        flex: 1;
        padding: 12px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        box-sizing: border-box;
    }
    .bc-btn-cancel {
        border: 1px solid var(--border-color);
        background: var(--dm-surface, #fff);
        color: var(--text-dark);
    }
    .bc-btn-cancel:hover { background: var(--dm-surface-2, #f1f5f9); }
    .bc-btn-send {
        border: none;
        background: var(--chat-primary);
        color: #fff;
    }
    .bc-btn-send:hover { background: var(--chat-primary-hover); }

    .bc-preview-col {
        border-left: 1px solid var(--border-color);
        padding-left: 28px;
    }
    .bc-preview-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }
    .bc-preview-card {
        background: var(--dm-surface-2, #f8fafc);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 24px 20px;
    }
    .bc-preview-logo { text-align: center; margin-bottom: 14px; }
    .bc-preview-logo img { max-height: 56px; width: auto; }
    .bc-preview-title {
        text-align: center;
        margin: 0 0 14px;
        font-size: 1.05rem;
        color: var(--chat-primary);
        word-break: break-word;
    }
    .bc-preview-divider { border: 0; border-top: 1px solid var(--border-color); margin: 0 0 14px; }
    .bc-preview-greeting { margin: 0 0 10px; font-size: 0.88rem; color: var(--text-dark); }
    .bc-preview-body {
        margin: 0 0 14px;
        font-size: 0.88rem;
        color: var(--text-dark);
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .bc-preview-signoff { margin: 0; font-size: 0.85rem; color: var(--text-muted); }
    .bc-preview-note {
        margin: 10px 0 0;
        font-size: 0.75rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    @media (max-width: 860px) {
        .bc-grid { grid-template-columns: 1fr; gap: 8px; padding: 18px 20px 22px; }
        .bc-preview-col { border-left: none; border-top: 1px solid var(--border-color); padding-left: 0; padding-top: 18px; margin-top: 4px; }
        .bc-modal-header { padding: 20px 20px 16px; }
    }

    @media (max-width: 480px) {
        .bc-actions { flex-direction: column; }
        .bc-modal-header h2 { font-size: 1.05rem; }
    }

    /* ======================================================================
       VISUAL POLISH LAYER (look only - no markup logic / behaviour changes).
       Loads after admin_style.css, so these win at equal specificity. Surfaces
       use the same var(--dm-*, light-fallback) tokens as the broadcast composer
       so Light/Dark follow the site toggle; the few gradients get an explicit
       body.dark-mode value below.
       ====================================================================== */
    .main-content .page-title h1 { display: flex; align-items: center; gap: 12px; letter-spacing: -0.02em; }
    .main-content .page-title h1 > i {
        display: inline-flex; align-items: center; justify-content: center;
        width: 42px; height: 42px; border-radius: 12px; font-size: 1.05rem;
        background: linear-gradient(135deg, #6d5bd0, #4a3e94); color: #fff !important;
        box-shadow: 0 6px 14px rgba(74, 62, 148, 0.35);
    }
    .new-broadcast-btn { background: linear-gradient(135deg, #6d5bd0, #4a3e94); box-shadow: 0 6px 14px rgba(74, 62, 148, 0.35); transition: transform .15s ease, box-shadow .15s ease; }
    .new-broadcast-btn:hover { background: linear-gradient(135deg, #7a68dc, #4a3e94); transform: translateY(-1px); box-shadow: 0 8px 18px rgba(74, 62, 148, 0.42); }

    .messages-wrapper { border-radius: 20px; border: 1px solid var(--border-color); box-shadow: 0 12px 34px rgba(30, 27, 75, 0.10); }

    /* Conversation list */
    .customers-list-header {
        padding: 18px 20px; color: #fff; border-bottom: none;
        background: linear-gradient(135deg, #5b4bc4 0%, #4a3e94 55%, #3b3178 100%);
    }
    .customers-list-header i { opacity: 0.9; }
    .sidebar-total-unread { background: var(--surface); color: var(--link); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18); }
    .customer-item { flex-direction: row; align-items: center; gap: 12px; padding: 13px 16px; border-left: 4px solid transparent; }
    .customer-item:hover { background: rgba(99, 82, 200, 0.06); }
    .customer-item.active { background: linear-gradient(90deg, rgba(99, 82, 200, 0.16), rgba(99, 82, 200, 0.03)); border-left-color: var(--chat-primary); }
    .ci-body { flex: 1 1 auto; min-width: 0; }
    .ci-avatar {
        flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.9rem; font-weight: 800; letter-spacing: 0.02em; color: #fff;
        box-shadow: 0 3px 8px rgba(30, 27, 75, 0.25), 0 0 0 2px var(--dm-surface, #fff);
    }
    .ci-avatar-lg { width: 46px; height: 46px; }
    .ci-avatar.av-0 { background: linear-gradient(135deg, #6d5bd0, #4a3e94); }
    .ci-avatar.av-1 { background: linear-gradient(135deg, #f59e0b, #ea580c); }
    .ci-avatar.av-2 { background: linear-gradient(135deg, #10b981, #0f766e); }
    .ci-avatar.av-3 { background: linear-gradient(135deg, #ec4899, #be185d); }
    .ci-avatar.av-4 { background: linear-gradient(135deg, #38bdf8, #2563eb); }
    .customer-name { font-weight: 700; }
    .customer-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .customer-unread {
        position: static; flex: 0 0 auto; min-width: 22px; text-align: center; padding: 3px 8px; font-size: 0.72rem;
        background: linear-gradient(135deg, #f43f5e, #dc2626); box-shadow: 0 3px 8px rgba(220, 38, 38, 0.4);
    }
    .empty-list i, .no-conversation i { color: #a5b4fc; }

    /* Conversation header + chat canvas */
    .conversation-header {
        padding: 14px 24px; gap: 14px; position: relative;
        background: var(--dm-surface, #fff); box-shadow: 0 2px 10px rgba(30, 27, 75, 0.06); z-index: 1;
    }
    .conversation-header::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: linear-gradient(90deg, #6d5bd0, #a78bfa, #38bdf8); opacity: 0.85; }
    .conversation-header h3 { font-size: 1.1rem; font-weight: 800; }
    .conversation-messages {
        gap: 14px; padding: 22px 26px;
        background-color: var(--chat-bg);
        background-image: radial-gradient(rgba(99, 82, 200, 0.10) 1px, transparent 1.4px);
        background-size: 20px 20px;
    }

    /* Bubbles */
    .message-sender { font-size: 0.74rem; letter-spacing: 0.02em; }
    .message-content { padding: 11px 17px; border-radius: 20px; box-shadow: 0 2px 8px rgba(30, 27, 75, 0.08); }
    .message.sent .message-content {
        background: linear-gradient(135deg, #6d5bd0 0%, #4a3e94 100%); color: #fff; border-bottom-right-radius: 6px;
        box-shadow: 0 6px 16px rgba(74, 62, 148, 0.30);
    }
    .message.received .message-content { border-color: var(--border-color); border-bottom-left-radius: 6px; }
    .message .message-time, .message.sent .message-time { color: var(--text-muted); opacity: 0.85; font-size: 0.7rem; }
    .seen-status { color: #0ea5e9; }
    .system-message span { box-shadow: 0 1px 4px rgba(30, 27, 75, 0.08); }

    /* Composer */
    .conversation-input { padding: 14px 22px 16px; box-shadow: 0 -4px 14px rgba(30, 27, 75, 0.05); }
    .input-group { gap: 10px; }
    .input-group textarea { height: 48px; padding: 13px 20px; border-radius: 26px; border: 1.5px solid var(--border-color); background: var(--dm-surface-2, #f8fafc); color: var(--text-dark); }
    .input-group textarea:focus { border-color: #7c6be0; box-shadow: 0 0 0 4px rgba(124, 107, 224, 0.18); }
    .input-group button {
        height: 48px; padding: 0 24px; border-radius: 26px; font-weight: 700;
        background: linear-gradient(135deg, #6d5bd0, #4a3e94); box-shadow: 0 6px 14px rgba(74, 62, 148, 0.35);
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .input-group button:hover { background: linear-gradient(135deg, #7a68dc, #4a3e94); transform: translateY(-1px); box-shadow: 0 8px 18px rgba(74, 62, 148, 0.42); }
    .input-group button:active { transform: translateY(0); }
    .input-group button i { transition: transform .2s ease; }
    .input-group button:hover i { transform: translateX(2px) rotate(-12deg); }

    /* Empty states (also fixes the icon / title / text sitting in one row) */
    .no-conversation { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; max-width: 360px; }
    .no-conversation i {
        display: inline-flex; align-items: center; justify-content: center;
        width: 96px; height: 96px; margin-bottom: 10px; border-radius: 50%; font-size: 2.4rem;
        color: #6d5bd0; background: radial-gradient(circle at 30% 25%, rgba(167, 139, 250, 0.35), rgba(99, 82, 200, 0.10));
        box-shadow: 0 0 0 10px rgba(99, 82, 200, 0.06);
    }
    .no-conversation h3 { margin: 0; font-size: 1.15rem; color: var(--text-dark); }
    .no-conversation p { margin: 0; line-height: 1.5; }

    /* Dark mode: swap the light-only tints for dark equivalents */
    body.dark-mode .messages-wrapper { box-shadow: 0 12px 34px rgba(0, 0, 0, 0.45); }
    body.dark-mode .customers-list-header { background: linear-gradient(135deg, #4b3fa8 0%, #3a3184 55%, #2b2566 100%); border-color: transparent; color: #fff; }
    body.dark-mode .customer-item.active { background: linear-gradient(90deg, rgba(139, 124, 240, 0.22), rgba(139, 124, 240, 0.04)); }
    body.dark-mode .customer-item:hover { background: rgba(139, 124, 240, 0.10); }
    body.dark-mode .conversation-messages { background-image: radial-gradient(rgba(165, 180, 252, 0.10) 1px, transparent 1.4px); }
    body.dark-mode .message.sent .message-content { background: linear-gradient(135deg, #7a68dc 0%, #5443b5 100%); color: #fff; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4); }
    body.dark-mode .message.received .message-content { background: var(--dm-surface-2); color: var(--dm-text); border-color: var(--dm-border); }
    body.dark-mode .conversation-header { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35); }
    body.dark-mode .conversation-input { box-shadow: 0 -4px 14px rgba(0, 0, 0, 0.3); }
    body.dark-mode .empty-list i, body.dark-mode .no-conversation i { color: #a5b4fc; }
    body.dark-mode .no-conversation i { background: radial-gradient(circle at 30% 25%, rgba(165, 180, 252, 0.28), rgba(99, 82, 200, 0.10)); box-shadow: 0 0 0 10px rgba(165, 180, 252, 0.06); }
    body.dark-mode .main-content .page-title h1 { color: var(--dm-text) !important; }
    body.dark-mode .seen-status { color: #38bdf8; }

    @media (max-width: 900px) {
        .main-content .page-title h1 > i { width: 36px; height: 36px; }
        .conversation-header { padding: 12px 16px; }
        .conversation-messages { padding: 16px 14px; }
        .conversation-input { padding: 12px 14px; }
        .messages-wrapper { border-radius: 16px; }
    }
    @media (max-width: 480px) {
        .input-group button { padding: 0 18px; }
        .ci-avatar { width: 40px; height: 40px; }
    }
</style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-title" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h1 style="margin: 0; font-size: 1.5rem; color: #1e293b;"><i class="fas fa-comments" style="color: var(--chat-primary);"></i> Messaging System</h1>
            <?php if ($user_role === 'admin'): ?>
                <button type="button" class="new-broadcast-btn" onclick="document.getElementById('broadcastModal').style.display='flex';">
                    <i class="fas fa-bullhorn"></i> New Broadcast
                </button>
            <?php endif; ?>
        </div>
    
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert show <?php echo $_SESSION['message_type']; ?>">
                <i class="fas <?php echo $_SESSION['message_type'] === 'success' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
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
                            <span class="ci-avatar av-<?php echo (int)$customer['id'] % 5; ?>"><?php echo htmlspecialchars(chat_initials($customer['name'])); ?></span>
                            <div class="ci-body">
                                <div class="customer-name">
                                    <span><?php echo htmlspecialchars($customer['name']); ?></span>
                                </div>
                                <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                                <div class="customer-last-msg">
                                    <i class="far fa-clock"></i> <?php echo date('M d, H:i', strtotime($customer['last_message_time'])); ?>
                                </div>
                            </div>
                            <?php if ($customer['unread_count'] > 0): ?>
                                <span class="customer-unread"><?php echo $customer['unread_count']; ?></span>
                            <?php endif; ?>
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
                        <span class="ci-avatar ci-avatar-lg av-<?php echo (int)$selected_customer['id'] % 5; ?>"><?php echo htmlspecialchars(chat_initials($selected_customer['name'])); ?></span>
                        <div class="conversation-header-info">
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
                            <?php foreach ($conversation as $msg): ?>
                                <?php if ($msg['sender_type'] === 'system'): ?>
                                    <div class="system-message" style="align-self: center; width: 100%; text-align: center; margin: 12px 0; font-size: 0.8rem; color: #64748b; font-weight: 600;">
                                        <span style="background: #e2e8f0; padding: 5px 16px; border-radius: 12px; color: #475569;"><?php echo htmlspecialchars($msg['message']); ?></span>
                                    </div>
                                <?php else:
                                    $is_sent = in_array($msg['sender_type'], ['admin', 'staff']);
                                ?>
                                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                                        <div class="message-sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <?php if ($is_sent): ?>
                                                <i class="fas fa-user-shield" style="color: var(--chat-primary); margin-left: 4px;" title="Verified Staff"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="message-content"><?php echo htmlspecialchars($msg['message']); ?></div>
                                        <div class="message-time">
                                            <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                                        </div>
                                        <?php if ($is_sent && $msg['id'] === $last_seen_msg_id): ?>
                                            <div class="seen-status"><i class="fas fa-check-double"></i> Seen</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
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
        <source src="https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3" type="audio/mpeg">
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
                        <div class="message-content">${escapeHtml(msg.message)}</div>
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
                    const parts = String(c.name || '').trim().split(/\s+/);
                    const initials = ((parts[0] || '').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase() || '?';
                    return `<div class="customer-item${active}" onclick="location.href='?customer_id=${id}'">
                        <span class="ci-avatar av-${id % 5}">${escapeHtml(initials)}</span>
                        <div class="ci-body">
                            <div class="customer-name"><span>${escapeHtml(c.name)}</span></div>
                            <div class="customer-email">${escapeHtml(c.email)}</div>
                            <div class="customer-last-msg"><i class="far fa-clock"></i> ${t}</div>
                        </div>
                        ${unread}
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

            // WebSocket realtime updates (admin)
            setupWs();

            // AJAX polling so nothing needs a manual page refresh:
            //  - open thread every 4s, conversation list every 6s
            //  - the WebSocket above still gives instant updates when it is running
            fetchMessages();
            setInterval(fetchMessages, 4000);
            fetchConversations();
            setInterval(fetchConversations, 6000);

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
        });

        // --- WebSocket realtime ---
        let ws = null;
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : '';
        }

        function setupWs() {
            if (!window.WebSocket) return;
            // Real cookie name is jwt_token_staff (see app/helpers/jwt_helper.php's
            // setJWTCookie() - already correct in modules/staff/messages.php).
            // This was reading 'jwt_token'/'JWT_TOKEN', which never existed, so
            // THIS page's own WebSocket connection has never actually opened -
            // any live update on this exact page has only ever come from the
            // 4s/6s polling below, never from WS.
            const token = getCookie('jwt_token_staff') || '';
            if (!token) return;
            // Derive the WS host from the page's own hostname (works on
            // localhost, LAN IPs, and a real domain alike) instead of a
            // hardcoded "localhost" that would only ever resolve to the
            // visitor's own machine in production.
            ws = new WebSocket(`ws://${window.location.hostname}:<?php echo WS_PORT; ?>/?token=${encodeURIComponent(token)}`);

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

        function closeBroadcastModal() {
            document.getElementById('broadcastModal').style.display = 'none';
        }

        <?php if ($user_role === 'admin'): ?>
        // --- Broadcast composer: live char counter + live preview (both
        // frontend-only, never touch the backend) and a proper confirmation
        // step in place of the old native confirm(). The actual send is
        // untouched: confirming just calls broadcastForm.submit(), which
        // posts through the exact same existing send_broadcast handler.
        // Deferred to DOMContentLoaded because this <script> block runs
        // before the broadcast modal's own markup (further down the page)
        // exists in the DOM — running immediately would make every
        // getElementById() below resolve to null.
        document.addEventListener('DOMContentLoaded', function () {
            const titleInput = document.getElementById('broadcast_title');
            const messageInput = document.getElementById('broadcast_message');
            const charCountEl = document.getElementById('bcCharCount');
            const charCounterWrap = charCountEl ? charCountEl.closest('.bc-char-counter') : null;
            const previewTitle = document.getElementById('bcPreviewTitle');
            const previewBody = document.getElementById('bcPreviewBody');
            const broadcastForm = document.getElementById('broadcastForm');
            const emailCheckbox = document.getElementById('broadcast_email');
            const sendBtn = document.getElementById('bcSendBtn');
            const confirmModal = document.getElementById('broadcastConfirmModal');
            const confirmCount = document.getElementById('bcConfirmCount');
            const confirmEmailNote = document.getElementById('bcConfirmEmailNote');

            function updateCharCount() {
                if (!messageInput || !charCountEl) return;
                const len = messageInput.value.length;
                charCountEl.textContent = len;
                // Purely a visual guideline - the notifications.message column
                // has no real length limit, so this never blocks typing.
                if (charCounterWrap) charCounterWrap.classList.toggle('over', len > 1000);
            }

            function updatePreview() {
                if (previewTitle) {
                    previewTitle.textContent = (titleInput && titleInput.value.trim()) || 'Your title will appear here';
                }
                if (previewBody) {
                    previewBody.textContent = (messageInput && messageInput.value) || 'Your message will appear here...';
                }
            }

            if (messageInput) messageInput.addEventListener('input', function () { updateCharCount(); updatePreview(); });
            if (titleInput) titleInput.addEventListener('input', updatePreview);
            updateCharCount();
            updatePreview();

            window.closeBroadcastConfirm = function () {
                if (confirmModal) confirmModal.style.display = 'none';
            };
            window.confirmSendBroadcast = function () {
                window.closeBroadcastConfirm();
                if (broadcastForm) broadcastForm.submit();
            };

            if (sendBtn && broadcastForm) {
                sendBtn.addEventListener('click', function () {
                    if (!broadcastForm.reportValidity()) return;
                    if (confirmCount) confirmCount.textContent = <?php echo (int) $broadcast_recipient_count; ?>;
                    if (confirmEmailNote) {
                        confirmEmailNote.textContent = (emailCheckbox && emailCheckbox.checked)
                            ? ' (an email will also be sent to each customer)'
                            : '';
                    }
                    if (confirmModal) confirmModal.style.display = 'flex';
                });
            }

            window.addEventListener('click', function (e) {
                if (e.target.id === 'broadcastConfirmModal') window.closeBroadcastConfirm();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') window.closeBroadcastConfirm();
            });
        });
        <?php endif; ?>
    </script>

    <?php if ($user_role === 'admin'): ?>
    <!-- Admin Broadcast / Announcements modal -->
    <div id="broadcastModal" class="bc-modal-overlay" style="display: none;">
        <div class="bc-modal-shell">
            <div class="bc-modal-header">
                <div>
                    <h2><i class="fas fa-bullhorn"></i> Broadcast Announcement</h2>
                    <p>Send an announcement or important update to your customers.</p>
                </div>
                <span class="bc-modal-close" onclick="closeBroadcastModal()">&times;</span>
            </div>

            <form method="POST" id="broadcastForm" class="bc-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="send_broadcast">

                <div class="bc-composer">
                    <div class="bc-field">
                        <label class="bc-label" for="broadcast_title">Broadcast Title</label>
                        <input type="text" id="broadcast_title" name="broadcast_title" class="bc-input" required maxlength="150" placeholder="e.g. Weekend Promotion, Store Announcement">
                        <p class="bc-hint">This becomes the notification/email subject.</p>
                    </div>

                    <div class="bc-field">
                        <label class="bc-label" for="broadcast_message">Message</label>
                        <textarea id="broadcast_message" name="broadcast_message" class="bc-textarea" required rows="6" placeholder="Write your announcement here..."></textarea>
                        <div class="bc-char-counter"><span id="bcCharCount">0</span> / 1000 characters</div>
                    </div>

                    <div class="bc-field">
                        <span class="bc-label">Recipients</span>
                        <div class="bc-recipients">
                            <label class="bc-recipient-option selected">
                                <input type="radio" name="bc_recipient_scope_display" checked disabled>
                                <span class="bc-recipient-text">
                                    <strong>All Customers</strong>
                                    <small><?php echo number_format($broadcast_recipient_count); ?> registered customer<?php echo $broadcast_recipient_count === 1 ? '' : 's'; ?></small>
                                </span>
                            </label>
                            <label class="bc-recipient-option disabled">
                                <input type="radio" name="bc_recipient_scope_display" disabled>
                                <span class="bc-recipient-text">
                                    <strong>Selected Customers</strong>
                                    <small>Coming soon</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <label class="bc-email-toggle">
                        <input type="checkbox" name="broadcast_email" id="broadcast_email" value="1">
                        <span>Also email every customer <em>(in-app notification is always sent)</em></span>
                    </label>

                    <div class="bc-actions">
                        <button type="button" class="bc-btn-cancel" onclick="closeBroadcastModal()">Cancel</button>
                        <button type="button" class="bc-btn-send" id="bcSendBtn"><i class="fas fa-paper-plane"></i> Send Broadcast</button>
                    </div>
                </div>

                <div class="bc-preview-col">
                    <div class="bc-preview-label"><i class="fas fa-eye"></i> Live Preview</div>
                    <div class="bc-preview-card">
                        <div class="bc-preview-logo">
                            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="<?php echo htmlspecialchars(SYSTEM_NAME); ?> Logo">
                        </div>
                        <h3 class="bc-preview-title" id="bcPreviewTitle">Your title will appear here</h3>
                        <hr class="bc-preview-divider">
                        <p class="bc-preview-greeting">Hello valued customer,</p>
                        <p class="bc-preview-body" id="bcPreviewBody">Your message will appear here...</p>
                        <p class="bc-preview-signoff"><?php echo htmlspecialchars(SYSTEM_NAME); ?> Team</p>
                    </div>
                    <p class="bc-preview-note"><i class="fas fa-circle-info"></i> Preview only — nothing is sent until you confirm.</p>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm-before-send step (real count, real backend send on confirm) -->
    <div id="broadcastConfirmModal" class="bc-modal-overlay" style="display: none;">
        <div class="bc-confirm-shell">
            <h3><i class="fas fa-paper-plane"></i> Send Broadcast?</h3>
            <p>You are about to send this announcement to <strong id="bcConfirmCount"><?php echo (int) $broadcast_recipient_count; ?></strong> customer<?php echo $broadcast_recipient_count === 1 ? '' : 's'; ?><span id="bcConfirmEmailNote"></span>.</p>
            <div class="bc-confirm-actions">
                <button type="button" class="bc-btn-cancel" onclick="closeBroadcastConfirm()">Cancel</button>
                <button type="button" class="bc-btn-send" onclick="confirmSendBroadcast()"><i class="fas fa-paper-plane"></i> Send Broadcast</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>