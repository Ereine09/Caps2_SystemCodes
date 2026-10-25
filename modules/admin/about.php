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
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About The System - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        body {
            overflow-x: hidden;
            margin: 0;
            background-color: var(--surface-2);
        }

        .about-us-container {
            background-color: var(--surface);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.07);
            color: var(--text-2);
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
            color: var(--muted);
            margin-bottom: 30px;
        }
        .about-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
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
            border: 1px solid var(--border);
        }
        .contact-table td:first-child {
            font-weight: 600;
            background-color: var(--surface-2);
            width: 30%;
            color: #0C2340;
        }

        /* Long unbroken strings (the full shop address, etc.) must wrap inside
           their box instead of forcing it wider than .main-content — that's
           what was pushing text out past .main-content's left edge and under
           the fixed sidebar. */
        .about-us-container,
        .about-us-container p,
        .about-us-container li,
        .contact-table td {
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .contact-table {
            table-layout: fixed;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>
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
                    <tr><td>Store Address</td><td><?php echo SHOP_ADDRESS; ?></td></tr>
                    <tr><td>Landline Numbers</td><td>(02) 8290-9381 / (02) 8359-5593</td></tr>
                    <tr><td>Mobile Number</td><td>+63 947 427 8111</td></tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>