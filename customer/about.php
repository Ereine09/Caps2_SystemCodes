<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();
$page_title = 'About Us';
include __DIR__ . '/includes/header.php';
?>

<style>
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
</style>

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

<?php include __DIR__ . '/includes/footer.php'; ?>