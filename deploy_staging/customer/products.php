<?php
require_once __DIR__ . '/includes/auth.php';
// require_customer_login();
require_once __DIR__ . '/../app/helpers/messaging_helper.php';


$customer = current_customer();
$customer_id = (int)($customer['id'] ?? 0);
$unread_count = get_unread_count_customer($customer_id);


// Define specific filter groups for Darius Poultry Supply
$filter_groups = [
    'Product Category' => ['Dog Food', 'Cat Food', 'Chicken', 'Chicken Feed', 'Dog Essentials', 'Cat Essentials', 'Dog & Cat Medicine', 'Skin Care & Treatment'],
    'Lifestage' => ['Adult (1 - 7)', 'Kitten'],
    'Food Type' => ['Dry Food', 'Treats', 'Wet Food'],
    'Health Needs' => ['Indoor Cats', 'Sensitive Skin', 'Small Breeds'],
    'Brand' => ['FELIX', 'Fancy Feast', 'Friskies', 'Purina ONE®', "Beggin'"]
];


// Load dynamic custom categories from database and merge into filter_groups
$custom_health = [];
$custom_brands = [];
$custom_lifestages = [];
$result = $conn->query("SELECT group_name, category_value FROM tbl_custom_categories ORDER BY category_value ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['group_name'] === 'Health Needs') {
            $custom_health[] = $row['category_value'];
        } elseif ($row['group_name'] === 'Brand') {
            $custom_brands[] = $row['category_value'];
        } elseif ($row['group_name'] === 'Lifestage') {
            $custom_lifestages[] = $row['category_value'];
        }
    }
    $result->close();
}
if (!empty($custom_health)) {
    $filter_groups['Health Needs'] = array_unique(array_merge($filter_groups['Health Needs'], $custom_health));
}
if (!empty($custom_brands)) {
    $filter_groups['Brand'] = array_unique(array_merge($filter_groups['Brand'], $custom_brands));
}
if (!empty($custom_lifestages)) {
    $filter_groups['Lifestage'] = array_unique(array_merge($filter_groups['Lifestage'], $custom_lifestages));
}

// An option must live in exactly one group: drop repeats (e.g. a custom "Health Needs" entry that duplicates "Cat Food"),
// so a tick box never appears twice and each selected filter belongs to one group.
$option_group = [];
foreach ($filter_groups as $g => $opts) {
    $keep = [];
    foreach ($opts as $o) {
        if (!isset($option_group[$o])) { $option_group[$o] = $g; $keep[] = $o; }
    }
    $filter_groups[$g] = $keep;
}


function add_session_cart_item(array $product, int $quantity, ?int $variant_id = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }


    $variant = null;
    if ($variant_id) {
        foreach (get_product_variants((int)$product['id']) as $candidate) {
            if ((int)$candidate['id'] === $variant_id) {
                $variant = $candidate;
                break;
            }
        }
        if (!$variant) {
            return false;
        }
    }


    $cart = $_SESSION['customer_cart'] ?? [];
    $cart_key = $product['id'] . '-' . ($variant_id ?? '0');
    if (isset($cart[$cart_key])) {
        $cart[$cart_key]['quantity'] += $quantity;
    } else {
        $cart[$cart_key] = [
            'id' => (int)$product['id'],
            'variant_id' => $variant_id ?? 0,
            'name' => $product['name'] . ($variant ? ' (' . $variant['size'] . ')' : ''),
            'unit_price' => (float)($variant['price'] ?? $product['price']),
            'quantity' => $quantity,
            'image_url' => $product['image_url'] ?? null,
        ];
    }


    $_SESSION['customer_cart'] = $cart;
    return true;
}


// AJAX & POST Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = (int) $_POST['product_id'];
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $product = get_product_by_id($product_id);
    $variant_id = isset($_POST['variant_id']) && !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $is_buy_now = ($_POST['action'] ?? '') === 'buy_now';


    $message = '';
    $message_type = 'error';
    $success = false;


    if ($product) {
        $available_stock = get_product_stock($product_id, $variant_id);
        if ($quantity > $available_stock) {
            $message = 'Insufficient stock for "' . htmlspecialchars($product['name']) . '". Only ' . $available_stock . ' available.';
        } elseif ($is_buy_now) {
            // Skip the persistent cart entirely — checkout.php/payment.php will see
            // only this item (get_customer_cart() -> get_buy_now_cart()), so whatever
            // is already in the real cart is left untouched and out of this order.
            start_buy_now($product_id, $quantity, $variant_id);
            $message = 'Proceeding to checkout with "' . htmlspecialchars($product['name']) . '".';
            $message_type = 'success';
            $success = true;
        } elseif ($customer
            ? add_customer_cart_item((int)$customer['id'], $product_id, $quantity, $variant_id)
            : add_session_cart_item($product, $quantity, $variant_id)) {
            $message = 'Product "' . htmlspecialchars($product['name']) . '" added to cart.';
            $message_type = 'success';
            $success = true;
        } else {
            $message = 'Unable to add the product to your cart.';
        }
    } else {
        $message = 'Product not found.';
    }


    // Kung galing sa AJAX Fetch request ang submit, magbato ng JSON response para hindi mag-refresh ang page
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'message_type' => $message_type,
            'cart_count' => cart_item_count()
        ]);
        exit();
    }


    // Fallback/Backup (kung sakaling hindi gumana ang JS ng browser, normal redirect)
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;


    $redirect_url = 'products.php';
    if (!empty($_GET)) {
        $redirect_url .= '?' . http_build_query($_GET);
    }
    header("Location: " . $redirect_url);
    exit();
}


$selected_categories = array_values(array_unique(array_filter(
    array_map('trim', array_filter((array) ($_GET['categories'] ?? []), 'is_string')),
    'strlen'
)));
$search_query = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
$search_query = function_exists('mb_substr') ? mb_substr($search_query, 0, 100) : substr($search_query, 0, 100);


// --- Server-side search + category filter (prepared statements) ---
// Every whitespace-separated search word must match at least one of: name,
// description, category, brand, food type, lifestage, health needs, SKU, or a
// variant's flavor/size. A product matches a category filter if ANY selected
// category is in its comma-separated category list (same rule as before).
$where_sql = [];
$where_types = '';
$where_params = [];

$search_words = $search_query === '' ? [] : array_slice(preg_split('/\s+/u', $search_query, -1, PREG_SPLIT_NO_EMPTY), 0, 6);
foreach ($search_words as $word) {
    $like = '%' . addcslashes($word, '\\%_') . '%';
    $where_sql[] = '(p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ? OR p.brand LIKE ? OR p.food_type LIKE ? OR p.lifestage LIKE ? OR p.health_needs LIKE ? OR p.sku LIKE ?'
        . ' OR EXISTS (SELECT 1 FROM tbl_product_variants v WHERE v.product_id = p.id AND v.size LIKE ?))';
    $where_types .= str_repeat('s', 9);
    for ($i = 0; $i < 9; $i++) {
        $where_params[] = $like;
    }
}

// Filters combine like a normal shop: options in the SAME group are alternatives (Dry Food OR Wet Food),
// different groups all have to match (Dog Food AND Dry Food AND Friskies).
if ($selected_categories) {
    $by_group = [];
    foreach ($selected_categories as $cat) {
        $by_group[$option_group[$cat] ?? '_other'][] = $cat;
    }
    foreach ($by_group as $cats) {
        $cat_clauses = [];
        foreach ($cats as $cat) {
            $cat_clauses[] = "FIND_IN_SET(?, REPLACE(REPLACE(p.category, ' ,', ','), ', ', ','))";
            $where_types .= 's';
            $where_params[] = $cat;
        }
        $where_sql[] = '(' . implode(' OR ', $cat_clauses) . ')';
    }
}
$where_clause = $where_sql ? 'WHERE ' . implode(' AND ', $where_sql) : '';

// Pagination
$products_per_page = 12;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS n FROM tbl_product_inventory p $where_clause");
if ($where_params) {
    $count_stmt->bind_param($where_types, ...$where_params);
}
$count_stmt->execute();
$total_products = (int) ($count_stmt->get_result()->fetch_assoc()['n'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int) ceil($total_products / $products_per_page));
$current_page = min(max(1, (int) ($_GET['page'] ?? 1)), $total_pages);
$offset = ($current_page - 1) * $products_per_page;

// Fetch the current page, sorted by popularity (most purchased first).
// p.id is a tie-breaker so products never repeat/skip between pages.
$list_stmt = $conn->prepare(
    "SELECT p.*, COUNT(oi.id) AS sales_count
     FROM tbl_product_inventory p
     LEFT JOIN tbl_order_items oi ON p.id = oi.product_id
     $where_clause
     GROUP BY p.id
     ORDER BY sales_count DESC, p.id ASC
     LIMIT ? OFFSET ?"
);
$list_params = array_merge($where_params, [$products_per_page, $offset]);
$list_stmt->bind_param($where_types . 'ii', ...$list_params);
$list_stmt->execute();
$products = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();
foreach ($products as &$product) {
    $product['image_url'] = product_image_src($product['image_url'] ?? '');
    $product['variants'] = get_product_variants((int)$product['id']);
}
unset($product);
$all_products = $products; // products on this page (used by the page script)

$showing_from = $total_products > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $products_per_page, $total_products);
$shop_is_filtered = $search_query !== '' || !empty($selected_categories);


// Get product counts per category (one light query; no per-product variant lookups)
$category_counts = [];
$cat_result = $conn->query("SELECT category FROM tbl_product_inventory");
if ($cat_result) {
    while ($cat_row = $cat_result->fetch_assoc()) {
        foreach (array_map('trim', explode(',', $cat_row['category'] ?? 'General')) as $cat) {
            $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;
        }
    }
    $cat_result->close();
}


/** products.php URL keeping the current search/category filters (+ overrides). */
function shop_url(array $override = [], array $drop = []): string
{
    global $search_query, $selected_categories;
    $params = [];
    if ($search_query !== '') {
        $params['search'] = $search_query;
    }
    if ($selected_categories) {
        $params['categories'] = $selected_categories;
    }
    foreach ($override as $k => $v) {
        $params[$k] = $v;
    }
    foreach ($drop as $k) {
        unset($params[$k]);
    }
    $qs = http_build_query($params);
    return 'products.php' . ($qs !== '' ? '?' . $qs : '');
}

/** Product descriptions are stored with markdown-style ** and * markers; show them as plain text on cards. */
function shop_plain_description(?string $text): string
{
    $t = str_replace('**', '', (string) $text);
    $t = preg_replace('/(^|\s)\*(?=\s)/u', ' • ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t, " •\t\n\r");
}
?>
<?php $page_title = 'Shop Products'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>
<style>
.shop-container {
    display: flex;
    gap: 32px;
}
.shop-filters {
    width: 260px;
    background: var(--surface);
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(15,23,42,0.07);
    padding: 20px;
    height: fit-content;
}
.shop-filters h3 {
    font-size: 1.05rem;
    font-weight: 800;
    margin-bottom: 10px;
    color: var(--text);
}
.shop-filters .filter-section {
    margin-bottom: 18px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 12px;
}
.shop-filters .filter-section:last-child {
    border-bottom: none;
}
.filter-toggle-btn {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text-2);
    background: none;
    border: none;
    color: var(--text);
    width: 100%;
    text-align: left;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 10px 0;
    font-size: 1.05rem;
    font-weight: 800;
    cursor: pointer;
    margin-bottom: 8px;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 10px;
}
.filter-toggle-btn:hover {
    background: var(--surface-2);
    color: var(--link);
}
.toggle-arrow {
    font-family: monospace;
    font-size: 1.1rem;
    width: 15px;
    display: inline-block;
}
.shop-filters label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    font-size: 0.85rem;
    color: var(--text-2);
    cursor: pointer;
}
.shop-filters input[type="checkbox"] {
    accent-color: #6366f1;
}
.shop-filters .filter-clear {
    color: var(--link);
    font-weight: 600;
    font-size: 0.97rem;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    margin-left: 8px;
}
.shop-filters .filter-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}
.shop-filters .filter-tag {
    background: var(--primary-soft);
    color: var(--link);
    border-radius: 8px;
    padding: 2px 10px;
    font-size: 0.92rem;
    font-weight: 600;
}
.shop-products {
    flex: 1;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 20px;
}
.product-card {
    background: var(--surface);
    border-radius: 14px;
    border: 1px solid var(--border);
    overflow: hidden;
    padding: 12px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    height: 100%;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    border-color: #fed7aa;
}
.product-image-box {
    position: relative;
    width: 100%;
    height: 220px;
    overflow: hidden;
    border-radius: 12px;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-in;
    margin-bottom: 14px;
}
.product-card .product-image-box img {
    width: 100%;
    height: 150px;
    object-fit: contain;
    background: var(--surface-2);
    cursor: zoom-in;
    transition: transform 0.25s ease;
    display: block;
}
.product-image-box:hover img {
    transform: scale(1.03);
}
.product-image-box::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(15, 23, 42, 0.12), transparent 35%);
    opacity: 0;
    transition: opacity 0.2s ease;
    pointer-events: none;
}
.product-image-box:hover::after {
    opacity: 1;
}
.image-zoom-badge {
    position: absolute;
    right: 10px;
    bottom: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    z-index: 1;
}


.product-content {
    padding: 2px 4px 4px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.product-content h3 {
    margin-bottom: 8px;
    font-size: 1.08rem;
    line-height: 1.45;
    color: var(--text);
    font-weight: 700;
}
.product-content p {
    margin: 0 0 14px;
    color: var(--text-2);
    font-size: 0.95rem;
    line-height: 1.6;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex-grow: 0;
}
.product-card .price-stock-row {
    margin-top: auto;
    padding-top: 10px;
}
.product-meta {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}
.product-meta .meta-tag {
    background: var(--primary-soft);
    color: var(--link);
    border-radius: 8px;
    padding: 2px 10px;
    font-size: 0.92rem;
    font-weight: 600;
}
.product-price {
    font-weight: 700;
    color: var(--link);
    margin: 0 0 12px;
    font-size: 1.02rem;
    line-height: 1.4;
}
.variant-stock-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    background: var(--success-soft);
    color: var(--success);
}
.variant-stock-indicator.low {
    background: var(--primary-soft);
    color: #9a5b00;
}
.variant-stock-indicator.out {
    background: var(--danger-soft);
    color: var(--danger);
}
.variant-summary {
    display: none; /* Hides the variant list on the product cards */
    margin-top: 12px;
    flex-direction: column;
    gap: 8px;
}
.variant-summary-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-2);
}
.variant-summary-item img {
    width: 42px;
    height: 42px;
    object-fit: cover;
    border-radius: 8px;
    flex-shrink: 0;
    background: var(--surface);
    border: 1px solid var(--border);
}
.variant-summary-info {
    flex: 1;
    min-width: 0;
}
.variant-summary-name {
    display: block;
    font-weight: 700;
    color: var(--text);
    font-size: 0.8rem;
    margin-bottom: 2px;
}
.variant-summary-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 0.72rem;
    color: var(--text-2);
}
.variant-summary-meta span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.quantity-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.quantity-row input {
    width: 60px;
    text-align: center;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 4px 0;
}
.add-cart-btn {
    width: 100%;
}
.ask-product-btn {
    width: 100%;
    margin-top: 10px;
    background: #10b981;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 700;
}
.ask-product-btn:hover {
    background: #059669;
}
.alert-box {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: opacity 0.5s ease;
}
.alert-box.success { background: var(--success-soft); color: var(--success); border-left: 5px solid #27ae60; }
.alert-box.error { background: var(--danger-soft); color: var(--danger); border-left: 5px solid #e74c3c; }


.modal-zoomer {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.72);
    padding: 36px 16px;
    overflow-y: auto;
}
.modal-content-wrapper {
    position: relative;
    width: min(1180px, 92vw);
    margin: 0 auto;
}
body.modal-open {
    overflow: hidden;
}
/* Reserves the scrollbar's width permanently so opening/closing the product
   modal (which toggles body.modal-open above) never changes the page's
   content width. Without this, the scrollbar disappearing/reappearing shifts
   every card a few pixels sideways under a mouse that hasn't moved, so the
   card the cursor was over no longer matches :hover once the modal closes -
   its lift/shadow effect looks "stuck off" until the mouse is moved again. */
html {
    scrollbar-gutter: stable;
}
#modalImg {
    cursor: zoom-in;
    transition: transform 0.2s ease;
    transform-origin: center center;
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
}
#modalImg.zoomed {
    transform: scale(1.25);
    cursor: zoom-out;
}
/* Detail Zoom Modal Styles */
.product-detail-shell {
    max-width: 1180px !important;
    display: grid !important;
    grid-template-columns: 1.05fr 1.15fr;
    background: var(--surface) !important;
    border-radius: 18px !important;
    overflow: hidden !important;
    box-shadow: 0 22px 60px rgba(15, 23, 42, 0.18) !important;
}
/* Product Ratings row: spans both columns under the gallery + details */
.product-detail-ratings {
    grid-column: 1 / -1;
    padding: 22px 24px 26px;
    border-top: 1px solid var(--border);
    background: var(--surface);
    min-width: 0;
}
html[data-theme="dark"] .product-detail-ratings { background: #1c222b; border-top-color: #333c48; }
.product-detail-gallery {
    background: var(--surface-2);
    padding: 20px;
    border-right: 1px solid var(--border);
}
.product-gallery-main {
    position: relative;
    background: var(--surface);
    border-radius: 18px;
    border: 1px solid #eef2f7;
    overflow: hidden;
    height: 540px;
    max-height: 70vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-gallery-main img {
    width: 100%;
    height: 100%;
    max-height: 70vh;
    object-fit: contain;
    display: block;
    background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
    transition: transform 0.2s ease;
}
.product-gallery-main img:hover {
    transform: scale(1.01);
}
.gallery-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.6);
    color: #fff;
    font-size: 1.3rem;
    cursor: pointer;
    z-index: 2;
}
.gallery-arrow.prev { left: 12px; }
.gallery-arrow.next { right: 12px; }
.gallery-thumbs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 18px;
}
.gallery-thumb {
    width: 100%;
    height: 84px;
    border-radius: 12px;
    border: 2px solid var(--border);
    background: var(--surface);
    object-fit: cover;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 0;
}
.gallery-thumb.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(249,115,22,0.1);
}
.product-detail-info {
    padding: 28px 28px 26px;
    background: var(--surface);
}
.product-detail-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    gap: 10px;
}
.product-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-soft);
    color: var(--link);
    border: 1px solid #fed7aa;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.72rem;
    padding: 6px 10px;
}
.product-detail-name {
    font-size: clamp(2rem, 2.4vw, 2.6rem);
    line-height: 1.2;
    margin: 0 0 16px;
    color: var(--text);
    font-weight: 800;
}
.product-detail-rating-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border);
    color: var(--text-2);
    font-size: 0.85rem;
}
.product-rating {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #f59e0b;
    font-weight: 500;
}
.product-price-box {
    margin-bottom: 18px;
    padding: 18px 18px;
    border-radius: 14px;
    background: linear-gradient(180deg, var(--primary-soft) 0%, var(--surface) 100%);
    border: 1px solid #fed7aa;
}
.product-price-label {
    display: block;
    font-size: 0.8rem;
    color: #7c2d12;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 6px;
}
.product-price {
    font-size: x-large;
    color: var(--link);
    font-weight: 900;
    letter-spacing: -0.04em;
}
.product-ship-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}
.ship-feature {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-2);
    color: var(--text-2);
    font-size: 0.78rem;
    font-weight: 600;
}
.ship-feature i {
    color: var(--success);
}
.variant-panel {
    margin-bottom: 18px;
}
.variant-panel h4 {
    margin: 0 0 10px;
    font-size: 0.86rem;
    color: var(--text-2);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.variant-button-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.variant-button {
    border: 1px solid #dbe2ea;
    background: var(--surface);
    border-radius: 10px;
    color: var(--text-2);
    padding: 10px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 42px;
}
.variant-button:hover { border-color: var(--primary); color: var(--link); }
.variant-button.selected {
    background: var(--primary-soft);
    border-color: var(--primary);
    color: var(--link);
    box-shadow: 0 0 0 2px rgba(249,115,22,0.08);
}
.variant-button.disabled {
    opacity: 0.45;
    cursor: not-allowed;
    text-decoration: line-through;
}
.variant-button img {
    width: 26px;
    height: 26px;
    object-fit: cover;
    border-radius: 6px;
    vertical-align: middle;
    margin-right: 6px;
}
.stock-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 12px 0 16px;
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-2);
}
.stock-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 999px;
    font-weight: 800;
}
.stock-pill.in-stock {
    background: var(--success-soft);
    color: var(--success);
}
.stock-pill.low-stock {
    background: var(--primary-soft);
    color: var(--warning);
}
.stock-pill.out-stock {
    background: var(--danger-soft);
    color: var(--danger);
}
.quantity-inline {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
}
.quantity-inline .qty-box {
    display: inline-flex;
    align-items: center;
    border: 1px solid #dbe2ea;
    border-radius: 12px;
    overflow: hidden;
    background: var(--surface);
}
.qty-box button {
    width: 42px;
    height: 42px;
    border: none;
    background: var(--surface-2);
    color: var(--text-2);
    font-size: 1.3rem;
    cursor: pointer;
}
.qty-box input {
    width: 56px;
    height: 42px;
    border: none;
    text-align: center;
    font-size: 1rem;
    font-weight: 700;
    color: var(--text);
    background: transparent;
}
.detail-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 18px;
}
.detail-actions .add-cart-btn,
.detail-actions .buy-now-btn {
    flex: 1;
    min-height: 52px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
}
.detail-actions .add-cart-btn {
    background: var(--primary);
    color: #fff;
}
.detail-actions .buy-now-btn {
    background: var(--text);
    color: var(--bg);
}
.detail-actions button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.product-description-box {
    margin-top: 26px;
    border-top: 1px solid var(--border);
    padding-top: 20px;
}
.product-description-box h3 {
    margin: 0 0 12px;
    color: var(--text);
    font-size: 1.1rem;
    font-weight: 800;
}
.product-description-box p {
    margin: 0;
    color: var(--text-2);
    line-height: 1.75;
    white-space: normal;
    overflow-wrap: anywhere;
}
.product-detail-shell .close-zoomer {
    position: absolute;
    right: 26px;
    top: 18px;
    color: #fff;
    font-size: 2.5rem;
    text-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
@media (max-width: 900px) {
    .product-detail-shell {
        grid-template-columns: 1fr;
    }
    .product-detail-gallery {
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
    .product-gallery-main {
        min-height: 360px;
    }
    .product-detail-info {
        padding: 18px 16px 20px;
    }
    .product-ship-grid {
        grid-template-columns: 1fr;
    }
    .detail-actions {
        flex-direction: column;
    }
    .modal-zoomer {
        padding-top: 20px;
    }
    .shop-container {
        flex-direction: column;
    }
    .shop-filters {
        width: 100%;
        margin-bottom: 18px;
    }
    .shop-products {
        width: 100%;
    }
}

/* ==========================================================================
   Shop UI cleanup: search toolbar, equal-height cards, pagination, modal.
   Colors use the portal's --customer-* variables (light values on :root,
   dark on html[data-theme="dark"]). The portal's dark theme forces
   "color: inherit" on everything inside .customer-panel / .product-card /
   .product-detail-shell, so any element that needs its OWN color (muted
   text, accents) also gets an explicit html[data-theme="dark"] rule below.
   ========================================================================== */
.shop-container { align-items: flex-start; margin-top: 20px; }
.shop-filters { flex: 0 0 260px; box-sizing: border-box; }
.shop-products { flex: 1 1 0; min-width: 0; }
.filter-toggle-btn { font-size: 0.95rem; padding: 8px 0; margin-bottom: 4px; justify-content: flex-start; }

.shop-header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; width: 100%; }
.shop-header-row h2 { margin: 0; }
.shop-search { display: flex; gap: 10px; flex: 1 1 380px; max-width: 560px; min-width: 0; }
.shop-search-box { position: relative; flex: 1; min-width: 0; }
.shop-search-box > .shop-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--customer-muted, #64748b); pointer-events: none; }
.shop-search-box input[type="search"] {
    width: 100%; box-sizing: border-box; height: 44px;
    padding: 0 40px 0 40px;
    border: 1px solid var(--customer-border, #cbd5e1); border-radius: 10px;
    background: var(--customer-input-bg, #fff); color: var(--customer-text, #1e293b);
    font: inherit; font-size: 0.92rem;
    -webkit-appearance: none; appearance: none;
}
.shop-search-box input[type="search"]::-webkit-search-cancel-button { display: none; }
.shop-search-box input::placeholder { color: var(--customer-muted, #94a3b8); opacity: 1; }
.shop-search-box input:focus { outline: 2px solid rgba(99,102,241,.35); border-color: #6366f1; }
.shop-search-clear {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: 6px; text-decoration: none; color: var(--customer-muted, #64748b);
}
.shop-search-clear:hover { background: var(--customer-border, #e2e8f0); }
.shop-search .button { height: 44px; padding: 0 22px; white-space: nowrap; }

.shop-resultbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-bottom: 16px; }
.shop-result-text { font-size: 0.88rem; color: var(--customer-muted, #64748b); font-weight: 600; }
.shop-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.shop-chip {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 10px;
    border-radius: 999px; background: var(--primary-soft); color: var(--link);
    font-size: 0.8rem; font-weight: 600; text-decoration: none; max-width: 100%;
}
.shop-chip i, .shop-chip svg { font-size: 0.7rem; width: 0.7em; height: 0.7em; opacity: .7; padding: 2px 4px; box-sizing: content-box; }
.shop-chip:hover { background: var(--primary-soft); }
.shop-clear-all { font-size: 0.82rem; font-weight: 600; color: var(--link); text-decoration: none; }
.shop-clear-all:hover { text-decoration: underline; }

/* Cards: identical structure so title / price / buttons line up across a row. */
.product-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); align-items: stretch; }
.product-card { padding: 12px; box-sizing: border-box; min-width: 0; }
.product-card .product-image-box { height: 200px; margin-bottom: 12px; }
.product-card .product-image-box img { height: 100%; padding: 8px; box-sizing: border-box; }
.product-image-box.no-image { cursor: default; color: var(--customer-muted, #94a3b8); font-size: 2.2rem; }
.product-content { padding: 2px 4px 4px; min-width: 0; }
.product-content h3.product-title {
    margin: 0 0 6px; font-size: 0.98rem; line-height: 1.35; font-weight: 700; cursor: pointer;
    display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
    min-height: calc(3 * 1.35em);
}
.product-rating-line { min-height: 1.4em; margin-bottom: 6px; font-size: 0.85rem; color: #f59e0b; font-weight: 700; }
.product-rating-line .rating-count { color: var(--customer-muted, #64748b); font-weight: 500; font-size: 0.78rem; }
.product-rating-line.empty { color: var(--customer-muted, #94a3b8); font-weight: 400; font-size: 0.78rem; }
.product-content p.product-desc {
    margin: 0 0 10px; font-size: 0.85rem; line-height: 1.5; color: var(--customer-muted, #475569);
    -webkit-line-clamp: 2; line-clamp: 2; min-height: calc(2 * 1.5em);
}
.product-card .product-price { margin: 0 0 10px; font-size: 1.15rem; font-weight: 800; color: var(--link); }
.product-card .price-stock-row { margin-top: auto; padding-top: 0; }
.product-card .form-group select { box-sizing: border-box; height: 38px; font-size: 0.82rem; background: var(--customer-input-bg, #fff); color: var(--customer-text, #1e293b); }
.product-card .form-group label { display: block; margin-bottom: 4px; }
.product-card .variant-stock-indicator { margin-top: 6px; padding: 4px 10px; }
.stock-inline { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px; font-size: 0.78rem; font-weight: 700; color: var(--success); }
.product-card .quantity-row { margin-bottom: 10px; }
.product-card .quantity-row label { font-size: 0.82rem; font-weight: 600; margin: 0; }
.product-card .quantity-row input { width: 64px; height: 34px; box-sizing: border-box; background: var(--customer-input-bg, #fff); color: var(--customer-text, #1e293b); }
.card-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.card-actions .add-cart-btn, .card-actions .buy-now-card-btn {
    width: 100%; height: 40px; padding: 0 8px; border: none; border-radius: 10px;
    font-size: 0.85rem; font-weight: 700; cursor: pointer; white-space: nowrap; color: #fff;
}
.card-actions .buy-now-card-btn { background: var(--text); color: var(--bg); }
.card-actions .buy-now-card-btn:hover { background: var(--text-2); }
.card-actions button:disabled { opacity: .6; cursor: wait; }
.ask-link {
    display: block; width: 100%; margin-top: 8px; padding: 6px 0; background: none !important; box-shadow: none !important;
    border: none; color: var(--success); font-size: 0.8rem; font-weight: 700; cursor: pointer; text-align: center;
}
.ask-link:hover { text-decoration: underline; transform: none !important; }

/* Pagination */
.shop-pagination { display: flex; flex-direction: column; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--customer-border, #e2e8f0); }
.shop-pagination-info { font-size: 0.85rem; color: var(--customer-muted, #64748b); }
.shop-pagination-nav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; max-width: 100%; }
.shop-page-numbers { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.shop-page-btn, .shop-page-num {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-width: 36px; height: 36px; padding: 0 10px; box-sizing: border-box; border-radius: 8px;
    border: 1px solid var(--customer-border, #e2e8f0); background: var(--customer-surface, #fff);
    color: var(--customer-text, #1e293b); text-decoration: none; font-size: 0.85rem; font-weight: 600;
}
a.shop-page-btn:hover, a.shop-page-num:hover { border-color: #6366f1; background: var(--customer-surface-2, #f1f5f9); }
.shop-page-num.active { background: #6366f1; border-color: #6366f1; color: #fff; }
.shop-page-btn.disabled { opacity: .4; cursor: not-allowed; }
.shop-page-ellipsis { color: var(--customer-muted, #94a3b8); padding: 0 4px; }

.shop-empty { text-align: center; padding: 48px 16px; border: 1px dashed var(--customer-border, #e2e8f0); border-radius: 14px; color: var(--customer-muted, #64748b); }
.shop-empty i { display: block; font-size: 2rem; margin-bottom: 10px; opacity: .6; }
.shop-empty a { color: var(--link); font-weight: 700; text-decoration: none; }

/* Modal */
.modal-zoomer > .close-zoomer {
    position: fixed; top: 14px; right: 18px; z-index: 100001;
    width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; background: rgba(15,23,42,.75); color: #fff; font-size: 1.7rem; line-height: 1; cursor: pointer;
}
.modal-zoomer > .close-zoomer:hover { background: rgba(15,23,42,.95); }
.product-detail-shell { width: min(1100px, 94vw); }
.product-detail-info { min-width: 0; }
.product-detail-name { font-size: clamp(1.35rem, 1.2rem + 0.9vw, 1.85rem); line-height: 1.25; margin-bottom: 12px; overflow-wrap: anywhere; }
.product-gallery-main { height: 460px; }
.product-detail-shell .product-price { font-size: 1.6rem; }
.detail-actions { flex-wrap: wrap; margin-bottom: 10px; }
.product-detail-info .ask-product-btn { margin: 0 0 6px; }
.product-description-box { margin-top: 20px; padding-top: 16px; }
/* Under the image: long descriptions scroll inside this box instead of stretching the popup. */
.product-detail-gallery .product-description-box {
    margin-top: 16px; padding: 14px 6px 0 0;
    max-height: 240px; overflow-y: auto;
    border-top: 1px solid var(--customer-border, #edf2f7);
}
.product-detail-gallery .product-description-box h3 { font-size: 1rem; margin-bottom: 8px; }
.product-description-box p, .product-description-box ul { margin: 0 0 10px; font-size: 0.92rem; color: var(--customer-muted, #475569); line-height: 1.7; overflow-wrap: anywhere; }
.product-description-box ul { padding-left: 20px; }
.product-description-box li { margin-bottom: 6px; }
.product-description-box strong { color: var(--customer-text, #1e293b); }

@media (max-width: 900px) {
    .shop-filters { flex: 0 0 auto; }
    .shop-search { flex-basis: 100%; max-width: none; }
    .product-gallery-main { height: 340px; min-height: 0; }
    .modal-zoomer { padding: 12px 8px; }
    .detail-actions { flex-direction: row; }
    .product-detail-ratings { padding: 16px; }
    /* Single column: keep the description short so price and buttons stay close to the image. */
    .product-detail-gallery .product-description-box { max-height: 150px; }
}
@media (max-width: 480px) {
    .product-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .product-card .product-image-box { height: 150px; }
    .card-actions { grid-template-columns: 1fr; }
    .product-detail-name { font-size: 1.3rem; }
    .detail-actions .add-cart-btn, .detail-actions .buy-now-btn { min-height: 46px; font-size: 0.92rem; }
    .gallery-thumbs { grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .gallery-thumb { height: 60px; }
    .shop-page-btn-label { display: none; }
    .shop-search .button { padding: 0 16px; }
}
@media (max-width: 380px) {
    .product-grid { grid-template-columns: 1fr; }
}

/* Dark mode overrides for elements that carry their own color. */
html[data-theme="dark"] .product-desc,
html[data-theme="dark"] .shop-result-text,
html[data-theme="dark"] .shop-pagination-info,
html[data-theme="dark"] .shop-search-box > .shop-search-icon,
html[data-theme="dark"] .shop-search-clear,
html[data-theme="dark"] .shop-empty,
html[data-theme="dark"] .product-rating-line .rating-count,
html[data-theme="dark"] .product-rating-line.empty,
html[data-theme="dark"] .product-description-box p,
html[data-theme="dark"] .product-description-box ul,
html[data-theme="dark"] .shop-page-ellipsis { color: var(--customer-muted); }
html[data-theme="dark"] .product-description-box strong { color: var(--customer-text); }
html[data-theme="dark"] .shop-chip { background: rgba(99,102,241,.2); color: #c7d2fe; }
html[data-theme="dark"] .shop-chip:hover { background: rgba(99,102,241,.32); }
html[data-theme="dark"] .shop-clear-all,
html[data-theme="dark"] .shop-empty a { color: var(--customer-link); }
html[data-theme="dark"] .shop-search-box input[type="search"] { background: var(--customer-input-bg); color: var(--customer-text); border-color: var(--customer-border); }
html[data-theme="dark"] .shop-page-btn,
html[data-theme="dark"] .shop-page-num { background: var(--customer-surface); color: var(--customer-text); border-color: var(--customer-border); }
html[data-theme="dark"] .shop-page-num.active { background: #6366f1; border-color: #6366f1; color: #fff; }
html[data-theme="dark"] .product-price,
html[data-theme="dark"] .product-detail-shell .product-price { color: var(--link); }
html[data-theme="dark"] .product-rating-line { color: #fbbf24; }
html[data-theme="dark"] .stock-inline { color: #4ade80; }
html[data-theme="dark"] .ask-link { color: #34d399; }
html[data-theme="dark"] .card-actions .add-cart-btn,
html[data-theme="dark"] .card-actions .buy-now-card-btn,
html[data-theme="dark"] .detail-actions .add-cart-btn,
html[data-theme="dark"] .detail-actions .buy-now-btn,
html[data-theme="dark"] .product-detail-info .ask-product-btn { color: #fff; }
html[data-theme="dark"] .card-actions .buy-now-card-btn,
html[data-theme="dark"] .detail-actions .buy-now-btn { background: #0b1020; border: 1px solid var(--customer-border); }
html[data-theme="dark"] .product-card .form-group select,
html[data-theme="dark"] .product-card .quantity-row input { background: var(--customer-input-bg); color: var(--customer-text); border-color: var(--customer-border); }
</style>
<section class="customer-panel">
    <div class="section-header">
        <div class="shop-header-row">
            <h2>Darius Poultry Supply Shop</h2>

            <!-- GET form without `page`, so a new search always starts on page 1. Selected category filters are kept. -->
            <form class="shop-search" method="GET" action="products.php" role="search">
                <?php foreach ($selected_categories as $cat): ?>
                    <input type="hidden" name="categories[]" value="<?php echo htmlspecialchars($cat); ?>">
                <?php endforeach; ?>
                <div class="shop-search-box">
                    <i class="fas fa-search shop-search-icon" aria-hidden="true"></i>
                    <input type="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" maxlength="100"
                           placeholder="Search by product, brand, flavor, or category..." aria-label="Search products" autocomplete="off">
                    <?php if ($search_query !== ''): ?>
                        <a class="shop-search-clear" href="<?php echo htmlspecialchars(shop_url([], ['search'])); ?>" title="Clear search" aria-label="Clear search"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="button">Search</button>
            </form>
        </div>
    </div>


    <div id="alert-container">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert-box <?php echo $_SESSION['message_type']; ?>" id="status-alert">
                <i class="fas <?php echo $_SESSION['message_type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <span><?php echo $_SESSION['message']; ?></span>
                <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>
    </div>


    <div class="shop-container">
        <aside class="shop-filters">
            <form method="get" id="filter-form">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <h3 style="margin: 0;">Filters</h3>
                    <a href="products.php" class="filter-clear">Clear all</a>
                </div>
                    <?php if (!empty($search_query)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                    <?php endif; ?>


                <?php foreach ($filter_groups as $group_name => $options): ?>
                    <?php
                        // Check if any option in this group is currently selected to auto-expand
                        $is_expanded = false;
                        foreach($options as $o) if(in_array($o, $selected_categories)) $is_expanded = true;
                    ?>
                    <div class="filter-section">
                        <button type="button" class="filter-toggle-btn" onclick="toggleFilterGroup(this)">
                            <span class="toggle-arrow"><?php echo $is_expanded ? 'v' : '>'; ?></span> <?php echo htmlspecialchars($group_name); ?>
                        </button>
                        <div class="filter-content" style="display: <?php echo $is_expanded ? 'block' : 'none'; ?>; padding-left: 10px;">
                            <?php foreach ($options as $opt): ?>
                                <label>
                                    <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($opt); ?>"
                                    <?php echo in_array($opt, $selected_categories ?? []) ? 'checked' : ''; ?>
                                    onchange="document.getElementById('filter-form').submit()">
                                    <?php echo htmlspecialchars($opt); ?>
                                    <span style="color:#64748b;">(<?php echo $category_counts[$opt] ?? 0; ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        </aside>
        <div class="shop-products">
            <div class="shop-resultbar">
                <span class="shop-result-text">
                    <?php if ($total_products > 0): ?>
                        Showing <?php echo number_format($showing_from); ?>&ndash;<?php echo number_format($showing_to); ?> of <?php echo number_format($total_products); ?> <?php echo $shop_is_filtered ? 'matching ' : ''; ?>product<?php echo $total_products === 1 ? '' : 's'; ?>
                    <?php else: ?>
                        0 products found
                    <?php endif; ?>
                </span>
                <?php if ($shop_is_filtered): ?>
                    <div class="shop-chips">
                        <?php if ($search_query !== ''): ?>
                            <a class="shop-chip" href="<?php echo htmlspecialchars(shop_url([], ['search'])); ?>" title="Remove search">&ldquo;<?php echo htmlspecialchars($search_query); ?>&rdquo; <i class="fas fa-times"></i></a>
                        <?php endif; ?>
                        <?php foreach ($selected_categories as $chip_cat): ?>
                            <?php $remaining = array_values(array_diff($selected_categories, [$chip_cat])); ?>
                            <a class="shop-chip" href="<?php echo htmlspecialchars(shop_url(['categories' => $remaining], $remaining ? [] : ['categories'])); ?>" title="Remove filter"><?php echo htmlspecialchars($chip_cat); ?> <i class="fas fa-times"></i></a>
                        <?php endforeach; ?>
                    </div>
                    <a class="shop-clear-all" href="products.php">Clear all</a>
                <?php endif; ?>
            </div>

            <?php if (empty($products)): ?>
                <div class="shop-empty">
                    <i class="fas fa-search"></i>
                    No products match your search or filters.<br>
                    <a href="products.php">Show all products</a>
                </div>
            <?php endif; ?>

            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <?php $variants = $product['variants'] ?? []; ?>
                    <?php $product_json = htmlspecialchars(json_encode($product), ENT_QUOTES, 'UTF-8'); ?>
                    <div class="product-card">
                        <?php if ($product['image_url']): ?>
                            <div class="product-image-box" onclick='openProductModal(<?php echo $product_json; ?>)'>
                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" />
                                <span class="image-zoom-badge"><i class="fas fa-search-plus"></i> View Image</span>
                            </div>
                        <?php else: ?>
                            <div class="product-image-box no-image" aria-hidden="true"><i class="fas fa-box-open"></i></div>
                        <?php endif; ?>
                        <div class="product-content">
                            <h3 class="product-title" title="<?php echo htmlspecialchars($product['name']); ?>" onclick='openProductModal(<?php echo $product_json; ?>)'><?php echo htmlspecialchars($product['name']); ?></h3>
                            <?php $rating_info = get_product_average_rating($product['id']); ?>
                            <?php if ($rating_info['count'] > 0): ?>
                                <div class="product-rating-line" title="Average rating of <?php echo number_format($rating_info['avg'], 1); ?> from <?php echo $rating_info['count']; ?> review(s)">
                                    <?php echo str_repeat('★', round($rating_info['avg'])); ?><?php echo str_repeat('☆', 5 - round($rating_info['avg'])); ?>
                                    <span class="rating-count">(<?php echo $rating_info['count']; ?>)</span>
                                </div>
                            <?php else: ?>
                                <div class="product-rating-line empty">No reviews yet</div>
                            <?php endif; ?>


                            <p class="product-desc"><?php echo htmlspecialchars(shop_plain_description($product['description'])); ?></p>

                            <div class="product-price" id="price-<?php echo $product['id']; ?>">
                                PHP <?php echo number_format($variants[0]['price'] ?? $product['price'], 2); ?>
                            </div>
                            <div class="price-stock-row">
                                <form method="POST" action="" class="cart-ajax-form">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                <?php if (!empty($variants)): ?>
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label for="variant_<?php echo $product['id']; ?>" style="font-size: 0.8rem; font-weight: 600; color: #475569;">Flavor / Size:</label>
                                        <select name="variant_id" id="variant_<?php echo $product['id']; ?>" class="product-variant-select" data-product-id="<?php echo $product['id']; ?>" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                            <?php foreach ($variants as $variant): ?>
                                                <?php $variant_stock = (int)($variant['stock'] ?? 0); ?>
                                                <option value="<?php echo $variant['id']; ?>" data-price="<?php echo $variant['price']; ?>" data-stock="<?php echo $variant_stock; ?>" data-image-url="<?php echo htmlspecialchars($variant['image_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $variant_stock <= 0 ? 'disabled' : ''; ?>>
                                                    <?php echo htmlspecialchars($variant['size']); ?> (PHP <?php echo number_format($variant['price'], 2); ?>) - Stock: <?php echo $variant_stock; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="variant-stock-<?php echo $product['id']; ?>" class="variant-stock-indicator" data-product-id="<?php echo $product['id']; ?>">
                                            <i class="fas fa-boxes"></i>
                                            <span>Stock: <?php echo (int)($variants[0]['stock'] ?? 0); ?></span>
                                        </div>


                                        <div class="variant-summary" aria-label="Available product variants">
                                            <?php foreach ($variants as $variant): ?>
                                                <?php $variant_stock = (int)($variant['stock'] ?? 0); ?>
                                                <div class="variant-summary-item">
                                                    <img src="<?php echo htmlspecialchars($variant['image_url'] ?: $product['image_url'] ?: 'https://placehold.co/64'); ?>" alt="<?php echo htmlspecialchars($variant['size']); ?>" onerror="this.src='https://placehold.co/64'">
                                                    <div class="variant-summary-info">
                                                        <span class="variant-summary-name"><?php echo htmlspecialchars($variant['size']); ?></span>
                                                        <div class="variant-summary-meta">
                                                            <span><i class="fas fa-peso-sign"></i> <?php echo number_format((float)$variant['price'], 2); ?></span>
                                                            <span><i class="fas fa-boxes"></i> <?php echo $variant_stock > 0 ? $variant_stock . ' in stock' : 'Out of stock'; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                       




                                    </div>
                                <?php else: ?>
                                    <div class="stock-inline">
                                        <i class="fas fa-check-circle"></i> In Stock: <?php echo (int)$product['stock']; ?>
                                    </div>
                                <?php endif; ?>


                                <div class="quantity-row">
                                    <label for="quantity_<?php echo $product['id']; ?>">Qty</label>
                                    <input type="number" id="quantity_<?php echo $product['id']; ?>" name="quantity" value="1" min="1" />
                                </div>
                                <div class="card-actions">
                                    <button type="submit" class="button add-cart-btn">Add to Cart</button>
                                    <button type="button" class="button buy-now-card-btn">Buy Now</button>
                                </div>
                            </form>
                            <button type="button" class="ask-link" onclick='openProductInquiryModal(<?php echo $product_json; ?>)'><i class="fas fa-comment-dots"></i> Ask about this product</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <?php
                $shop_page_numbers = [];
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i === 1 || $i === $total_pages || abs($i - $current_page) <= 2) {
                        $shop_page_numbers[] = $i;
                    } elseif (end($shop_page_numbers) !== '...') {
                        $shop_page_numbers[] = '...';
                    }
                }
                ?>
                <div class="shop-pagination">
                    <div class="shop-pagination-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></div>
                    <nav class="shop-pagination-nav" aria-label="Product pages">
                        <?php if ($current_page > 1): ?>
                            <a class="shop-page-btn" href="<?php echo htmlspecialchars(shop_url(['page' => $current_page - 1])); ?>"><i class="fas fa-chevron-left"></i> <span class="shop-page-btn-label">Previous</span></a>
                        <?php else: ?>
                            <span class="shop-page-btn disabled" aria-disabled="true"><i class="fas fa-chevron-left"></i> <span class="shop-page-btn-label">Previous</span></span>
                        <?php endif; ?>
                        <div class="shop-page-numbers">
                            <?php foreach ($shop_page_numbers as $pn): ?>
                                <?php if ($pn === '...'): ?>
                                    <span class="shop-page-ellipsis">&hellip;</span>
                                <?php elseif ($pn === $current_page): ?>
                                    <span class="shop-page-num active" aria-current="page"><?php echo $pn; ?></span>
                                <?php else: ?>
                                    <a class="shop-page-num" href="<?php echo htmlspecialchars(shop_url(['page' => $pn])); ?>"><?php echo $pn; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($current_page < $total_pages): ?>
                            <a class="shop-page-btn" href="<?php echo htmlspecialchars(shop_url(['page' => $current_page + 1])); ?>"><span class="shop-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span class="shop-page-btn disabled" aria-disabled="true"><span class="shop-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>


<div id="productDetailModal" class="modal-zoomer">
    <span class="close-zoomer" onclick="closeProductModal()">&times;</span>
    <div class="modal-content-wrapper product-detail-shell">
        <div class="product-detail-gallery">
            <div id="modalCarousel" class="product-gallery-main">
                <button type="button" id="modalCarouselPrev" class="gallery-arrow prev" aria-label="Previous image">&#10094;</button>
                <img id="modalImg" alt="Product image">
                <button type="button" id="modalCarouselNext" class="gallery-arrow next" aria-label="Next image">&#10095;</button>
            </div>
            <div id="modalThumbs" class="gallery-thumbs"></div>

            <!-- Description sits under the image so the details column stays short. -->
            <div class="product-description-box">
                <h3>Product Description</h3>
                <div id="modalDesc"></div>
            </div>
        </div>


        <div class="product-detail-info">
            <div class="product-detail-meta">
                <span class="product-badge">Featured</span>
                <span id="modalMeta" style="font-size: 0.8rem; color: #64748b;"></span>
            </div>


            <h2 id="modalName" class="product-detail-name"></h2>


            <div class="product-detail-rating-row">
                <div class="product-rating" id="modalRating"></div>
                <div id="modalStockSummary"></div>
            </div>


            <div class="product-price-box">
                <span class="product-price-label">Price</span>
                <div id="modalPrice" class="product-price"></div>
            </div>


            <div class="product-ship-grid">
                <div class="ship-feature"><i class="fas fa-shield-check"></i> Guaranteed quality</div>
                <div class="ship-feature"><i class="fas fa-truck"></i> Fast delivery</div>
                <div class="ship-feature"><i class="fas fa-undo-alt"></i> Easy returns</div>
            </div>


            <div id="modalFlavorPanel" class="variant-panel"></div>
            <div id="modalSizePanel" class="variant-panel"></div>


            <div class="stock-line">
                <span id="modalAvailableLabel">Available</span>
                <span id="modalStockPill" class="stock-pill"></span>
            </div>


            <div class="quantity-inline">
                <label for="modalQuantity" style="font-size: 0.8rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">Quantity</label>
                <div class="qty-box">
                    <button type="button" id="modalQtyMinus" aria-label="Decrease quantity">−</button>
                    <input id="modalQuantity" type="number" min="1" value="1" />
                    <button type="button" id="modalQtyPlus" aria-label="Increase quantity">+</button>
                </div>
            </div>


            <div class="detail-actions">
                <button type="button" id="modalAddToCart" class="add-cart-btn">Add to Cart</button>
                <button type="button" id="modalBuyNow" class="buy-now-btn">Buy Now</button>
            </div>
            <button type="button" id="modalAsk" class="ask-product-btn"><i class="fas fa-comment-dots"></i> Ask about this product</button>
        </div>

        <div id="modalRatings" class="pr-scope product-detail-ratings" aria-live="polite"></div>
    </div>
</div>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/product_ratings.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/product_ratings.css') ?: time(); ?>">
<script src="<?php echo BASE_URL; ?>/assets/js/product-ratings.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/product-ratings.js') ?: time(); ?>"></script>


<div id="productInquiryModal" class="modal-zoomer">
    <span class="close-zoomer" onclick="closeProductInquiryModal()">&times;</span>
    <div class="modal-content-wrapper" style="max-width: 600px;">
        <div style="padding: 25px; background: white;">
            <h2 id="inquiryProductName" style="margin-bottom: 10px; color: #1e293b;">Product Inquiry</h2>
            <p style="margin: 0 0 15px 0; color: #475569;">Ask a question about this product and our team will get back to you.</p>
            <form method="POST" action="messages.php">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="product_id" id="inquiry_product_id" value="">
                <div style="margin-bottom: 15px;">
                    <label for="inquiry_message" style="display:block; margin-bottom: 8px; font-weight: 600; color: #1e293b;">Your question</label>
                    <textarea id="inquiry_message" name="message" required style="width:100%; min-height:140px; padding:12px; border:1px solid #cbd5e1; border-radius:12px; font-family: inherit;"></textarea>
                </div>
                <button type="submit" class="button add-cart-btn">Send inquiry</button>
            </form>
        </div>
    </div>
</div>


<script>
const allProducts = <?php echo json_encode(array_values($all_products)); ?>;
const currentCustomerId = <?php echo (int)($customer['id'] ?? 0); ?>;


function toggleFilterGroup(btn) {
    const content = btn.nextElementSibling;
    const isHidden = content.style.display === 'none' || content.style.display === '';
    content.style.display = isHidden ? 'block' : 'none';
   
    const arrow = btn.querySelector('.toggle-arrow');
    arrow.textContent = isHidden ? 'v' : '>';
}


function parseVariantLabel(value) {
    const text = String(value || '').trim();
    if (!text) {
        return { flavor: 'Default', size: '' };
    }


    const cleaned = text.replace(/\s+/g, ' ');
    const tokens = cleaned.split(/[\-\/|,]/).map(part => part.trim()).filter(Boolean);


    if (tokens.length <= 1) {
        return { flavor: tokens[0] || 'Default', size: '' };
    }


    const last = tokens[tokens.length - 1];
    const size = last.toLowerCase().includes('kg') || last.toLowerCase().includes('g') || last.toLowerCase().includes('lb') ? last : '';
    const flavor = size ? tokens.slice(0, -1).join(' ') : cleaned;


    return { flavor: flavor || 'Default', size: size || cleaned };
}


function getVariantImageUrls(product, variants) {
    const urls = [];
    const seen = new Set();
    const add = (url) => {
        if (!url) return;
        const cleaned = String(url).trim();
        if (cleaned && !seen.has(cleaned)) {
            seen.add(cleaned);
            urls.push(cleaned);
        }
    };


    add(product.image_url);
    variants.forEach(variant => add(variant.image_url));
    return urls;
}


function openProductModal(product) {
    const variants = Array.isArray(product.variants) && product.variants.length
        ? product.variants
        : [{
            id: 0,
            product_id: product.id,
            size: product.name,
            price: product.price,
            stock: product.stock,
            image_url: product.image_url
        }];


    const modal = document.getElementById('productDetailModal');
    const modalImg = document.getElementById('modalImg');
    const modalThumbs = document.getElementById('modalThumbs');
    const modalPrice = document.getElementById('modalPrice');
    const modalName = document.getElementById('modalName');
    const modalDesc = document.getElementById('modalDesc');
    const modalMeta = document.getElementById('modalMeta');
    const modalStockSummary = document.getElementById('modalStockSummary');
    const modalAvailableLabel = document.getElementById('modalAvailableLabel');
    const modalStockPill = document.getElementById('modalStockPill');
    const flavorPanel = document.getElementById('modalFlavorPanel');
    const sizePanel = document.getElementById('modalSizePanel');
    const modalQty = document.getElementById('modalQuantity');
    const modalAddToCart = document.getElementById('modalAddToCart');
    const modalBuyNow = document.getElementById('modalBuyNow');


    let activeVariantId = null;


    modal.style.display = 'block';
    modal.classList.add('lightbox-open');
    document.body.classList.add('modal-open');
    loadProductRatings(product.id);


    if (modalImg) {
        modalImg.classList.remove('zoomed');
        modalImg.onclick = function() {
            modalImg.classList.toggle('zoomed');
        };
    }


    const selectedSelect = document.querySelector(`#variant_${product.id}`);
    if (selectedSelect && selectedSelect.value) {
        activeVariantId = Number(selectedSelect.value);
    }
    if (!activeVariantId) {
        activeVariantId = Number(variants[0].id ?? 0);
    }


    const images = getVariantImageUrls(product, variants);
    let galleryIndex = 0;
    const selectedVariant = variants.find(v => Number(v.id) === Number(activeVariantId)) || variants[0];


    function getSelectedVariant() {
        return variants.find(v => Number(v.id) === Number(activeVariantId)) || variants[0];
    }


    function renderThumbs() {
        modalThumbs.innerHTML = '';
        images.forEach((src, index) => {
            const btn = document.createElement('img');
            btn.src = src;
            btn.alt = 'Product thumbnail';
            btn.className = 'gallery-thumb' + (index === galleryIndex ? ' active' : '');
            btn.addEventListener('click', () => {
                galleryIndex = index;
                renderImage();
            });
            modalThumbs.appendChild(btn);
        });
    }


    function renderImage() {
        const imageSource = images[galleryIndex] || selectedVariant.image_url || product.image_url || 'https://placehold.co/600';
        modalImg.src = imageSource;
        renderThumbs();
    }


    function renderVariantButtons() {
        const flavorMap = {};
        const sizeMap = {};


        variants.forEach(variant => {
            const parsed = parseVariantLabel(variant.size || product.name);
            const flavorName = parsed.flavor || 'Default';
            const sizeName = parsed.size || 'Standard';
            flavorMap[flavorName] = flavorMap[flavorName] || [];
            flavorMap[flavorName].push(variant);
            sizeMap[sizeName] = sizeMap[sizeName] || [];
            sizeMap[sizeName].push(variant);
        });


        const flavorEntries = Object.keys(flavorMap);
        const sizeEntries = Object.keys(sizeMap);


        flavorPanel.innerHTML = '<h4>Flavor</h4><div class="variant-button-group">' + flavorEntries.map(flavor => {
            const list = flavorMap[flavor];
            const buttonVariant = list.find(v => Number(v.id) === Number(activeVariantId)) || list[0];
            const selected = Number(buttonVariant.id) === Number(activeVariantId);
            return '<button type="button" class="variant-button' + (selected ? ' selected' : '') + '" data-variant-id="' + Number(buttonVariant.id) + '">' + escapeHtml(flavor) + '</button>';
        }).join('') + '</div>';


        sizePanel.innerHTML = '<h4>Size</h4><div class="variant-button-group">' + sizeEntries.map(size => {
            const sizeVariants = sizeMap[size];
            const matchedVariant = sizeVariants.find(v => Number(v.id) === Number(activeVariantId)) || sizeVariants[0];
            const selected = Number(matchedVariant.id) === Number(activeVariantId);
            const disabled = sizeVariants.every(v => Number(v.stock) <= 0);
            return '<button type="button" class="variant-button' + (selected ? ' selected' : '') + (disabled ? ' disabled' : '') + '" data-variant-id="' + Number(matchedVariant.id) + '">' + escapeHtml(size) + '</button>';
        }).join('') + '</div>';


        document.querySelectorAll('#modalFlavorPanel .variant-button').forEach(button => {
            button.addEventListener('click', () => {
                const variantId = Number(button.dataset.variantId);
                if (!variantId) return;
                const match = variants.find(v => Number(v.id) === variantId);
                if (match) {
                    activeVariantId = variantId;
                    renderSelectedVariant();
                }
            });
        });


        document.querySelectorAll('#modalSizePanel .variant-button').forEach(button => {
            button.addEventListener('click', () => {
                const variantId = Number(button.dataset.variantId);
                if (!variantId || button.classList.contains('disabled')) return;
                const match = variants.find(v => Number(v.id) === variantId);
                if (match) {
                    activeVariantId = variantId;
                    renderSelectedVariant();
                }
            });
        });
    }


    function updateStockState(value) {
        const available = Number(value || 0);
        modalAvailableLabel.textContent = available > 0 ? 'Available' : 'Out of stock';
        if (available <= 0) {
            modalStockPill.textContent = 'Out of stock';
            modalStockPill.className = 'stock-pill out-stock';
            modalAddToCart.disabled = true;
            modalBuyNow.disabled = true;
            modalQty.value = 1;
            modalQty.disabled = true;
            return;
        }
        modalQty.disabled = false;
        modalAddToCart.disabled = false;
        modalBuyNow.disabled = false;
        if (available <= 5) {
            modalStockPill.textContent = 'Only ' + available + ' left';
            modalStockPill.className = 'stock-pill low-stock';
        } else {
            modalStockPill.textContent = available + ' stocks available';
            modalStockPill.className = 'stock-pill in-stock';
        }
        const maxQty = available;
        const currentQty = Math.max(1, Math.min(Number(modalQty.value || 1), maxQty));
        modalQty.value = currentQty;
        modalQty.max = maxQty;
    }


    function renderSelectedVariant() {
        const variant = getSelectedVariant();
        const parsed = parseVariantLabel(variant.size || product.name);
        const price = Number(variant.price ?? product.price ?? 0);
        const stock = Number(variant.stock ?? 0);
        const imageList = getVariantImageUrls(product, variants);
        const variantImage = variant.image_url || product.image_url || imageList[0];
        if (variantImage) {
            const idx = imageList.indexOf(variantImage);
            galleryIndex = idx >= 0 ? idx : 0;
        }


        modalName.textContent = product.name;
        modalDesc.innerHTML = formatDescription(product.description);
        modalMeta.innerHTML = '<span class="meta-tag" style="background: #eef2ff; color: #3730a3; border-radius: 8px; padding: 2px 10px; font-size: 0.8rem; font-weight: 700;">' + escapeHtml(product.category || 'General') + '</span>';
        modalPrice.textContent = '₱' + price.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        modalStockSummary.textContent = parsed.flavor ? parsed.flavor + (parsed.size ? ' • ' + parsed.size : '') : 'Selected variant';
        renderVariantButtons();
        renderImage();
        updateStockState(stock);


        const quantityInput = document.getElementById('modalQuantity');
        quantityInput.value = 1;
        quantityInput.max = stock > 0 ? stock : 1;


        const hiddenVariantId = document.createElement('input');
        hiddenVariantId.type = 'hidden';
        hiddenVariantId.name = 'variant_id';
        hiddenVariantId.value = variant.id || 0;
        const existing = document.querySelector('#detailFormVariant');
        if (existing) existing.remove();
        hiddenVariantId.id = 'detailFormVariant';
        modalAddToCart.setAttribute('data-variant-id', variant.id || 0);
        modalBuyNow.setAttribute('data-variant-id', variant.id || 0);
        modalAddToCart.parentNode.appendChild(hiddenVariantId);
    }


    modalAddToCart.onclick = async () => {
        const variant = getSelectedVariant();
        const quantity = Math.max(1, Math.min(Number(modalQty.value || 1), Number(variant.stock || 0) || 1));
        const formData = new FormData();
        formData.append('product_id', product.id);
        formData.append('variant_id', variant.id || 0);
        formData.append('quantity', quantity);
        formData.append('action', 'add_to_cart');


        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const result = await response.json();
            showFloatingAlert(result.message || 'Product added to cart.', result.message_type || 'success');
            const cartBadge = document.getElementById('cart-count-badge');
            if (cartBadge && typeof result.cart_count !== 'undefined') {
                cartBadge.textContent = result.cart_count;
                cartBadge.style.display = result.cart_count > 0 ? '' : 'none';
            }
        } catch (error) {
            showFloatingAlert('Unable to add the selected variant to cart.', 'error');
        }
    };


    document.getElementById('modalAsk').onclick = () => {
        closeProductModal();
        openProductInquiryModal(product);
    };


    modalBuyNow.onclick = async () => {
        const variant = getSelectedVariant();
        const quantity = Math.max(1, Math.min(Number(modalQty.value || 1), Number(variant.stock || 0) || 1));
        const formData = new FormData();
        formData.append('product_id', product.id);
        formData.append('variant_id', variant.id || 0);
        formData.append('quantity', quantity);
        // 'buy_now', not 'add_to_cart': this checks out ONLY this item, leaving
        // whatever else is already in the cart untouched (see start_buy_now()).
        formData.append('action', 'buy_now');


        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                window.location.href = 'checkout.php';
            } else {
                showFloatingAlert(result.message || 'Unable to proceed to checkout.', 'error');
            }
        } catch (error) {
            showFloatingAlert('Unable to proceed to checkout.', 'error');
        }
    };


    modal.style.display = 'block';
    document.getElementById('modalCarouselPrev').onclick = () => {
        galleryIndex = (galleryIndex - 1 + images.length) % Math.max(images.length, 1);
        renderImage();
    };
    document.getElementById('modalCarouselNext').onclick = () => {
        galleryIndex = (galleryIndex + 1) % Math.max(images.length, 1);
        renderImage();
    };
    document.getElementById('modalQtyMinus').onclick = () => {
        const currentValue = Number(modalQty.value || 1);
        const newValue = Math.max(1, currentValue - 1);
        modalQty.value = newValue;
    };
    document.getElementById('modalQtyPlus').onclick = () => {
        const maxQty = Number(modalQty.max || 99);
        const currentValue = Number(modalQty.value || 1);
        modalQty.value = Math.min(maxQty, currentValue + 1);
    };
    modalQty.oninput = () => {
        const maxQty = Number(modalQty.max || 99);
        const cleanValue = Math.max(1, Math.min(Number(modalQty.value || 1), maxQty));
        modalQty.value = cleanValue;
    };


    renderSelectedVariant();
}


let ratingsRequestId = 0;
function loadProductRatings(productId) {
    const box = document.getElementById('modalRatings');
    if (!box || !window.PRRatings) return;
    const requestId = ++ratingsRequestId; // ignore a slow response if another product was opened meanwhile
    box.innerHTML = '<h3 class="pr-title">Product Ratings</h3><div class="pr-empty">Loading reviews…</div>';
    fetch('reviews_api.php?product_id=' + encodeURIComponent(productId), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (requestId !== ratingsRequestId) return;
            if (!data.success) {
                box.innerHTML = '<h3 class="pr-title">Product Ratings</h3><div class="pr-empty">' + escapeHtml(data.message || 'Reviews could not be loaded.') + '</div>';
                return;
            }
            PRRatings.renderCustomer(box, data);
        })
        .catch(() => {
            if (requestId !== ratingsRequestId) return;
            box.innerHTML = '<h3 class="pr-title">Product Ratings</h3><div class="pr-empty">Reviews could not be loaded.</div>';
        });
}

function closeProductModal() {
    const modal = document.getElementById("productDetailModal");
    if (modal) {
        modal.style.display = "none";
    }
    document.body.classList.remove('modal-open');
}


function openProductInquiryModal(product) {
    document.getElementById('inquiry_product_id').value = product.id;
    document.getElementById('inquiryProductName').innerText = 'Product Inquiry: ' + product.name;
    document.getElementById('productInquiryModal').style.display = 'block';
}


function closeProductInquiryModal() {
    document.getElementById('productInquiryModal').style.display = 'none';
    document.getElementById('inquiry_message').value = '';
}


function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}


// Descriptions are stored with markdown-style markers ("**bold**", " * bullet ").
// Escape first, then turn those markers into real bold text and a bullet list.
function formatDescription(raw) {
    const text = String(raw || '').trim();
    if (!text) return '<p>No description available.</p>';
    const bold = s => escapeHtml(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    const parts = text.split(/(?:^|\s)\*(?=\s)/).map(s => s.trim()).filter(Boolean);
    if (parts.length <= 1) return '<p>' + bold(text) + '</p>';
    const startsWithBullet = /^\*\s/.test(text);
    const intro = startsWithBullet ? '' : '<p>' + bold(parts.shift()) + '</p>';
    return intro + '<ul>' + parts.map(p => '<li>' + bold(p) + '</li>').join('') + '</ul>';
}


async function loadFrequentlyBought() {
    if (!currentCustomerId) return;


    try {
        const response = await fetch(`../modules/admin/ml_api.php?customer_id=${currentCustomerId}`);
        const result = await response.json();


        if (result.user_behavior && result.user_behavior.frequently_bought.length > 0) {
            const container = document.getElementById('behavioral-recs-container');
            if(!container) return;
            const items = result.user_behavior.frequently_bought;


            let html = `
                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                    <h3 style="font-size: 1rem; color: #312e81;"><i class="fas fa-star" style="color: #f1c40f;"></i> Your Top Picks</h3>
                    <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 15px;">Products you buy most often.</p>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
            `;


            items.forEach(item => {
                const product = allProducts.find(p => p.name === item.product_name);
                if (product) {
                    html += `
                        <div class="product-card" style="flex-direction: row; align-items: center; padding: 10px; gap: 15px; cursor: pointer;" onclick='openProductModal(${JSON.stringify(product)})'>
                            <img src="${product.image_url}" style="width: 50px; height: 50px; margin: 0; border-radius: 5px;">
                            <div style="flex: 1;">
                                <h4 style="font-size: 0.85rem; margin: 0;">${product.name}</h4>
                                <strong style="font-size: 0.8rem; color: #4a3e94;">PHP ${parseFloat(product.price).toFixed(2)}</strong>
                            </div>
                        </div>
                    `;
                }
            });


            html += `</div></div>`;
            container.innerHTML = html;
        }
    } catch (error) {
        console.error("Failed to load behavioral recommendations:", error);
    }
}


window.onclick = function(event) {
    const modal = document.getElementById("productDetailModal");
    const inquiryModal = document.getElementById("productInquiryModal");
    if (event.target == modal) {
        closeProductModal();
    }
    if (event.target == inquiryModal) {
        closeProductInquiryModal();
    }
}


document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('productDetailModal');
        const inquiryModal = document.getElementById('productInquiryModal');
        if (modal && modal.style.display === 'block') {
            closeProductModal();
        }
        if (inquiryModal && inquiryModal.style.display === 'block') {
            closeProductInquiryModal();
        }
    }
});


// Function para sa dynamic alert styling at rendering
function showFloatingAlert(message, type) {
    const alertContainer = document.getElementById('alert-container');
    const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
   
    alertContainer.innerHTML = `
        <div class="alert-box ${type}" id="status-alert" style="opacity: 1;">
            <i class="fas ${iconClass}"></i>
            <span>${message}</span>
        </div>
    `;
   
    // Kusang mawawala pagkalipas ng 3 segundo
    setTimeout(hideAlert, 3000);
}


function hideAlert() {
    const alert = document.getElementById('status-alert');
    if (alert) {
        alert.style.opacity = '0';
        setTimeout(() => { alert.remove(); }, 500);
    }
}


document.addEventListener('DOMContentLoaded', () => {
    // 1. Unset native server alert if exists after 3 seconds
    if (document.getElementById('status-alert')) {
        setTimeout(hideAlert, 3000);
    }


    // 2. Intercept at i-AJAX ang lahat ng Add to Cart forms
    document.querySelectorAll('.cart-ajax-form').forEach(form => {
        form.addEventListener('submit', async function(e) {
            e.preventDefault(); // Pipigilan nito ang page refresh!
           
            const btn = form.querySelector('.add-cart-btn');
            const originalBtnText = btn.innerText;
           
            // Loading effect temporary state
            btn.disabled = true;
            btn.innerText = 'Adding...';
           
            const formData = new FormData(form);
           
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest' // Tanda para sa PHP handler sa itaas
                    },
                    body: formData
                });
               
                const result = await response.json();


                if (result.success) {
                    const cartBadge = document.getElementById('cart-count-badge');
                    if (cartBadge) {
                        cartBadge.textContent = result.cart_count;
                        cartBadge.style.display = result.cart_count > 0 ? '' : 'none';
                    }
                }
               
                // Ipakita ang banner announcement box nang walang refresh
                showFloatingAlert(result.message, result.message_type);
               
            } catch (error) {
                console.error('Cart submission error:', error);
                showFloatingAlert('Network error. Failed to communicate with the store server.', 'error');
            } finally {
                // Ibalik ang button sa normal state
                btn.disabled = false;
                btn.innerText = originalBtnText;
            }
        });
    });


    // Buy Now on a card: checks out ONLY this item (server-side action=buy_now
    // skips the persistent cart entirely — see start_buy_now()), then goes
    // straight to checkout. Whatever's already in the real cart is untouched.
    document.querySelectorAll('.buy-now-card-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const form = btn.closest('form');
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = 'Please wait...';
            try {
                const formData = new FormData(form);
                formData.set('action', 'buy_now');
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    window.location.href = 'checkout.php';
                    return;
                }
                showFloatingAlert(result.message || 'Unable to proceed to checkout.', 'error');
            } catch (error) {
                showFloatingAlert('Unable to proceed to checkout.', 'error');
            }
            btn.disabled = false;
            btn.innerText = originalText;
        });
    });


    document.querySelectorAll('.product-variant-select').forEach(select => {
        const productId = select.dataset.productId;
        const stockDisplay = document.getElementById(`variant-stock-${productId}`);


        function updateVariantDisplay() {
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.dataset.price;
            const stock = Number(selectedOption.dataset.stock || 0);
            const selectedImage = selectedOption.dataset.imageUrl;


            document.getElementById(`price-${productId}`).innerText = 'PHP ' + parseFloat(price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});


            if (selectedImage) {
                const preview = document.getElementById(`variant-preview-${productId}`);
                if (preview) {
                    preview.src = selectedImage;
                    preview.style.display = 'block';
                }
            }


            if (stockDisplay) {
                stockDisplay.classList.remove('low', 'out');
                if (stock <= 0) {
                    stockDisplay.classList.add('out');
                    stockDisplay.innerHTML = '<i class="fas fa-times-circle"></i><span>Out of stock</span>';
                } else if (stock <= 5) {
                    stockDisplay.classList.add('low');
                    stockDisplay.innerHTML = '<i class="fas fa-boxes"></i><span>Only ' + stock + ' left</span>';
                } else {
                    stockDisplay.innerHTML = '<i class="fas fa-boxes"></i><span>Stock: ' + stock + '</span>';
                }
            }
        }


        updateVariantDisplay();
        select.addEventListener('change', updateVariantDisplay);
    });


    loadFrequentlyBought();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>





