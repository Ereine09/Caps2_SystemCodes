<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || !in_array($payload['role'], ['admin', 'staff'])) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}
$username = htmlspecialchars($payload['username']);
$user_role = htmlspecialchars($payload['role']);
$user_id = (int)$payload['user_id'];

// Fetch counts for sidebar badges
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About The System - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        body {
            overflow-x: hidden;
            margin: 0;
            background-color: #f8fafc;
        }

        /* Sidebar Flexbox Solution */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 260px !important;
            height: 100vh !important;
            display: flex !important;
            flex-direction: column !important;
            background-color: #1e293b !important;
            z-index: 1000 !important;
            box-sizing: border-box !important;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar-brand {
            flex-shrink: 0 !important;
            padding: 20px 15px !important;
            text-align: center !important;
        }

        /* Scrollable Navigation Links */
        .sidebar .nav-links {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            list-style: none !important;
            padding: 0 10px 10px 10px !important;
            margin: 0 !important;
        }

        .sidebar .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Pinned Footer Logout Area */
        .sidebar-footer {
            flex-shrink: 0 !important;
            padding: 16px 20px !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
            background-color: #1e293b !important;
            margin-top: auto !important;
        }

        .sidebar-footer .logout-link {
            position: static !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            color: #ef4444 !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            transition: opacity 0.2s ease;
        }

        .sidebar-footer .logout-link:hover {
            opacity: 0.8;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .about-us-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.07);
            color: #334155;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .about-us-container h1, .about-us-container h2, .about-us-container h3, .about-us-container h4 {
            color: #0C2340; /* Navy Blue */
        }
        .about-us-container h1 {
            font-size: 2rem;
            text-align: center;
            margin-bottom: 10px;
            color: #C8102E; /* Deep Red */
        }
        .about-us-container .tagline {
            text-align: center;
            font-style: italic;
            color: #64748b;
            margin-bottom: 30px;
        }
        .about-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .about-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .about-section h3 {
            font-size: 1.5rem;
            color: #0C2340; /* Navy Blue */
            border-left: 4px solid #FFC72C; /* Bright Gold/Yellow */
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .about-section p, .about-section ul {
            line-height: 1.7;
            font-size: 1rem;
        }
        .about-section ul {
            list-style-type: none;
            padding-left: 0;
        }
        .about-section ul li {
            padding-left: 25px;
            position: relative;
            margin-bottom: 10px;
        }
        .about-section ul li::before {
            content: '🐾';
            position: absolute;
            left: 0;
            color: #FFC72C;
        }
        .contact-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .contact-table td {
            padding: 12px;
            border: 1px solid #e2e8f0;
        }
        .contact-table td:first-child {
            font-weight: 600;
            background-color: #f8f9fa;
            width: 30%;
            color: #0C2340;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php"><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php"><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php"><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php">
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'class="active"' : ''; ?>><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php">
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php"><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="about-us-container">
            <h1>Darius Poultry Supply & Gen. Merchandise</h1>
            <p class="tagline">Your Trusted Partner in Premium Animal Care, Feeds & General Supplies Since 2018</p>

            <div class="about-section">
                <h3><i class="fas fa-store"></i> Business Overview</h3>
                <p>
                    Darius Poultry Supply & Gen. Merchandise is a trusted local provider of high-quality poultry feeds, gamefowl nutrition, pet care supplies, and general livestock essentials. Established in 2018, the business serves pet owners, poultry raisers, breeders, and local farmers in Metro Manila by offering reliable products, affordable prices, and exceptional customer service.
                </p>
                <p>
                    Whether catering to household pets, birds, gamefowls, or small farm animals, Darius Poultry Supply ensures that every animal gets the nutrition and care it deserves.
                </p>
            </div>

            <div class="about-section">
                <h3><i class="fas fa-bullseye"></i> Vision & Mission</h3>
                <h4>Our Vision</h4>
                <p>To be the premier one-stop poultry and pet supply destination in Caloocan City, recognized for quality livestock products, modern customer convenience, and strong community trust.</p>
                <h4>Our Mission</h4>
                <ul>
                    <li><strong>Quality Products:</strong> Supply top-grade feeds, supplements, and accessories for poultry, gamefowls, birds, dogs, cats, and small animals.</li>
                    <li><strong>Customer Value:</strong> Provide fair pricing with rewarding customer loyalty initiatives and seamless order fulfillment.</li>
                    <li><strong>Expert Support:</strong> Offer reliable guidance to animal raisers, breeders, and pet lovers for optimal animal health and growth.</li>
                </ul>
            </div>

            <div class="about-section">
                <h3><i class="fas fa-paw"></i> Product & Service Categories</h3>
                <ul>
                    <li><strong>Poultry & Gamefowl Care:</strong> Feeds, gamefowl grains, vitamins, conditioning supplements, and farm accessories.</li>
                    <li><strong>Bird & Avian Essentials:</strong> Seeds, bird cages, feeders, and care products for pigeons, parrots, lovebirds, and exotic birds.</li>
                    <li><strong>Pet Supplies:</strong> Premium food, treats, litter, and grooming accessories for Dogs, Cats, Rabbits, Hamsters, and Guinea Pigs.</li>
                    <li><strong>General Merchandise:</strong> Retail and wholesale animal nutrition products tailored for local raisers and pet owners.</li>
                </ul>
            </div>

            <div class="about-section">
                <h3><i class="fas fa-phone-alt"></i> Contact & Location Details</h3>
                <table class="contact-table">
                    <tr><td>Business Name</td><td>Darius Poultry Supply & Gen. Merchandise</td></tr>
                    <tr><td>Established</td><td>2018</td></tr>
                    <tr><td>Store Address</td><td>109 P. Burgos St., 10th Avenue, Caloocan City</td></tr>
                    <tr><td>Landline Numbers</td><td>(02) 8290-9381 / (02) 8359-5593</td></tr>
                    <tr><td>Mobile Number</td><td>+63 947 427 8111</td></tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>