<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$customer = current_customer();
$cart_count = cart_item_count();
$unread_msg_count = $customer ? get_unread_count_customer((int)$customer['id']) : 0;

// Customer notification unread count (bell) from the shared notifications table.
$notif_count = 0;
$rider_msg_unread = 0;
if ($customer) {
    $cid = (int)$customer['id'];
    notifications_ensure_schema($conn);
    $n_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE customer_id = ? AND is_read = 0");
    $n_stmt->bind_param('i', $cid);
    $n_stmt->execute();
    $notif_count = (int)($n_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $n_stmt->close();

    $rm_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_messages WHERE customer_id = ? AND sender_type = 'rider' AND is_read = 0");
    $rm_stmt->bind_param('i', $cid);
    $rm_stmt->execute();
    $rider_msg_unread = (int)($rm_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $rm_stmt->close();
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($page_title ?? 'Darius Poultry Supply Customer Portal'); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
    <style>
        /* Sidebar Layout for Customer Portal */
        body {
            background: linear-gradient(180deg, #f6f4ff 0%, #eef1ff 45%, #ffffff 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
.customer-sidebar {
            width: 260px;
            background: #1f2937;
            color: white;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        .customer-sidebar-brand {
            text-align: center;
            padding: 20px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            margin-bottom: 20px;
        }
        .customer-sidebar-brand a {
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            display: block;
        }
        .customer-sidebar-brand p {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.7);
            margin-top: 5px;
        }
        .customer-sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            flex: 1;
        }
        .customer-sidebar-nav li {
            margin: 8px 0;
        }
        .customer-sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 10px;
            transition: 0.3s;
            font-weight: 500;
        }
        .customer-sidebar-nav a:hover,
        .customer-sidebar-nav a.active {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        .customer-sidebar-nav a i {
            width: 20px;
            text-align: center;
        }
        .customer-sidebar-nav .cart-badge {
            background: #ef4444;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: auto;
        }
        .customer-sidebar-logout {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
        }
        .customer-sidebar-logout a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: #fca5a5;
            text-decoration: none;
            border-radius: 10px;
            transition: 0.3s;
        }
        .customer-sidebar-logout a:hover {
            background: rgba(239, 68, 68, 0.2);
        }
        .customer-main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            width: calc(100% - 260px);
            box-sizing: border-box;
        }
        .customer-welcome-banner {
            background: white;
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .customer-welcome-banner h1 {
            margin: 0 0 5px;
            color: #000000;
            font-size: 1.5rem;
        }
        .customer-welcome-banner p {
            margin: 0;
            color: #64748b;
        }
        /* Hide old wrapper styles */
        .customer-wrapper, .customer-header, .customer-nav, .customer-main {
            display: none !important;
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .customer-sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding: 10px;
                border-bottom: 2px solid rgba(255,255,255,0.1);
            }
            .customer-sidebar-brand {
                padding: 10px;
                margin-bottom: 10px;
                border-bottom: none;
            }
            .customer-main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            .customer-sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                justify-content: center;
            }
            .customer-sidebar-nav li {
                margin: 0;
                flex: 1 1 auto;
            }
            .customer-sidebar-nav a {
                padding: 10px;
                font-size: 0.85rem;
                justify-content: center;
            }
.customer-sidebar-logout {
                margin-top: 10px;
                padding-top: 10px;
            }
        }

        /* ===== Floating Notification Bell (top-right) ===== */
        .notif-bell-wrap {
            position: fixed;
            top: 20px;
            right: 24px;
            z-index: 1000;
        }
        .notif-bell-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #ffffff;
            color: #1f2937;
            font-size: 1.15rem;
            border: none;
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
            cursor: pointer;
            transition: 0.3s;
            position: relative;
        }
        .notif-bell-btn:hover { background: #eef2ff; color: #6366f1; }
        .notif-bell-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 2px #fff;
        }
        .notif-panel {
            position: absolute;
            top: 56px;
            right: 0;
            width: 360px;
            max-width: calc(100vw - 40px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.18);
            overflow: hidden;
            display: none;
            flex-direction: column;
        }
        .notif-panel.open { display: flex; }
        .notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 18px;
            border-bottom: 1px solid #eef1f6;
            background: #f8f9ff;
        }
        .notif-panel-header h3 {
            margin: 0;
            font-size: 1rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notif-panel-markall {
            background: #6366f1;
            color: #fff;
            border: none;
            padding: 7px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.72rem;
            cursor: pointer;
        }
        .notif-panel-markall:hover { background: #4f46e5; }
        .notif-panel-body {
            max-height: 380px;
            overflow-y: auto;
            padding: 8px;
        }
        .notif-panel-item {
            display: flex;
            gap: 12px;
            padding: 12px 10px;
            border-radius: 10px;
            align-items: flex-start;
        }
        .notif-panel-item:hover { background: #f8f9ff; }
        .notif-panel-item.unread { background: #eef2ff; }
        .notif-panel-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #6366f1;
            font-size: 0.9rem;
        }
        .notif-panel-title { font-weight: 700; color: #1e293b; font-size: 0.85rem; margin: 0 0 3px; }
        .notif-panel-msg { color: #64748b; font-size: 0.78rem; margin: 0 0 4px; line-height: 1.4; }
        .notif-panel-time { color: #94a3b8; font-size: 0.7rem; }
        .notif-panel-empty { text-align: center; padding: 34px 16px; color: #94a3b8; }
        .notif-panel-empty i { font-size: 2rem; color: #cbd5e1; margin-bottom: 8px; }
        .notif-panel-footer {
            border-top: 1px solid #eef1f6;
            padding: 10px;
            text-align: center;
        }
        .notif-panel-footer a {
            color: #6366f1;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .notif-panel-footer a:hover { text-decoration: underline; }
        @media (max-width: 768px) {
            .notif-bell-wrap { top: 12px; right: 14px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="customer-sidebar">
        <div class="customer-sidebar-brand">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <a href="<?php echo BASE_URL; ?>/customer/dashboard.php" style="font-size: 1.2rem;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></a>
                <p style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 5px;">Customer Portal</p>
        </div>
        <ul class="customer-sidebar-nav">
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/profile.php" class="<?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle"></i> My Account
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/addresses.php" class="<?php echo $current_page === 'addresses' ? 'active' : ''; ?>">
                    <i class="fas fa-map-marker-alt"></i> My Address
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/orders.php" class="<?php echo $current_page === 'orders' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Purchase History
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/products.php" class="<?php echo $current_page === 'products' ? 'active' : ''; ?>">
                    <i class="fas fa-store"></i> Shop
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/reward_catalog.php" class="<?php echo $current_page === 'reward_catalog' ? 'active' : ''; ?>">
                    <i class="fas fa-award"></i> Reward Catalog
                </a>
            </li>
<li>
                <a href="<?php echo BASE_URL; ?>/customer/messages.php" class="<?php echo $current_page === 'messages' ? 'active' : ''; ?>">
                    <i class="fas fa-comment-dots"></i> Messages
                    <?php if ($unread_msg_count > 0): ?>
                        <span class="cart-badge" style="background: #e74c3c;"><?php echo (int)$unread_msg_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/rider_chat.php" class="<?php echo $current_page === 'rider_chat' ? 'active' : ''; ?>">
                    <i class="fas fa-motorcycle"></i> Message Rider
                    <?php if ($rider_msg_unread > 0): ?>
                        <span class="cart-badge" style="background: #0d9488;"><?php echo (int)$rider_msg_unread; ?></span>
                    <?php endif; ?>
                </a>
            </li>
<li>
                <a href="<?php echo BASE_URL; ?>/customer/cart.php" class="<?php echo $current_page === 'cart' ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart"></i> Cart
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-badge"><?php echo $cart_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
<a href="<?php echo BASE_URL; ?>/customer/my_rewards.php" class="<?php echo $current_page === 'my_rewards' ? 'active' : ''; ?>">
                    <i class="fas fa-gift"></i> My Rewards
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/customer/about.php" class="<?php echo $current_page === 'about' ? 'active' : ''; ?>">
                    <i class="fas fa-info-circle"></i> About Us
                </a>
            </li>
        </ul>
        <div class="customer-sidebar-logout">
            <a href="<?php echo BASE_URL; ?>/customer/logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

<!-- Main Content -->
    <div class="customer-main-content">
        <?php if ($customer): ?>
            <div class="customer-welcome-banner">
                <h1>Welcome back, <?php echo htmlspecialchars($customer['name']); ?>! 👋</h1>
                <p>Your loyalty points: <strong style="color: #000000;"><?php echo number_format($customer['loyalty_points'] ?? 0, 2); ?></strong></p>
            </div>
        <?php endif; ?>

        <?php if ($customer): ?>
        <!-- Floating Notification Bell -->
        <div class="notif-bell-wrap" id="notif-bell-wrap">
            <button class="notif-bell-btn" id="notif-bell-btn" aria-label="Notifications" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($notif_count > 0): ?>
                    <span class="notif-bell-badge" id="notif-bell-badge"><?php echo (int)$notif_count; ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-panel" id="notif-panel">
                <div class="notif-panel-header">
                    <h3><i class="fas fa-bell"></i> Notifications</h3>
                    <button class="notif-panel-markall" id="notif-panel-markall" style="<?php echo $notif_count > 0 ? '' : 'display:none;'; ?>">
                        <i class="fas fa-check-double"></i> Mark all
                    </button>
                </div>
                <div class="notif-panel-body" id="notif-panel-body">
                    <div class="notif-panel-empty"><i class="fas fa-bell-slash"></i><p style="margin:0;">Loading notifications...</p></div>
                </div>
                <div class="notif-panel-footer">
                    <a href="<?php echo BASE_URL; ?>/customer/notifications.php"><i class="fas fa-bell"></i> View all notifications</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
