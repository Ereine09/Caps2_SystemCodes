<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();
require_once __DIR__ . '/../app/helpers/messaging_helper.php';

$customer = current_customer();
$customer_id = (int)($customer['id'] ?? 0);
$unread_count = get_unread_count_customer($customer_id);

// Define specific filter groups for Darius Poultry Supply
$filter_groups = [
    'Product Category' => ['Dog Food', 'Cat Food', 'Chicken', 'Dog Essentials', 'Cat Essentials'],
    'Lifestage' => ['Adult (1 - 7)', 'Kitten'],
    'Food Type' => ['Dry Food', 'Treats', 'Wet Food'],
    'Health Needs' => ['Indoor Cats', 'Sensitive Skin', 'Small Breeds'],
    'Brand' => ['FELIX', 'Fancy Feast', 'Friskies', 'Purina ONE®']
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

// AJAX & POST Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = (int) $_POST['product_id'];
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $product = get_product_by_id($product_id);
    
    $message = '';
    $message_type = 'error';
    $success = false;

    if ($product) {
        $available_stock = get_product_stock($product_id); // Assume this function exists in includes/functions.php
        if ($quantity > $available_stock) {
            $message = 'Insufficient stock for "' . htmlspecialchars($product['name']) . '". Only ' . $available_stock . ' available.';
        } elseif ($customer && add_customer_cart_item((int)$customer['id'], $product_id, $quantity)) {
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
            'message_type' => $message_type
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

$selected_categories = $_GET['categories'] ?? [];
$search_query = trim($_GET['search'] ?? '');

// Fetch products sorted by popularity (most purchased first)
// This counts how many times a product appears in order_items
$popularity_query = "
    SELECT p.*, COUNT(oi.id) as sales_count
    FROM tbl_product_inventory p
    LEFT JOIN tbl_order_items oi ON p.id = oi.product_id
    GROUP BY p.id 
    ORDER BY sales_count DESC";

$result = mysqli_query($conn, $popularity_query);
$all_products = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Filter products based on search and selected categories locally
if (!empty($selected_categories) || !empty($search_query)) {
    $products = array_filter($all_products, function($p) use ($selected_categories, $search_query) {
        $matches_search = empty($search_query) || 
                         (stripos($p['name'], $search_query) !== false) || 
                         (stripos($p['description'], $search_query) !== false);

        $matches_category = empty($selected_categories) || 
                           !empty(array_intersect($selected_categories, array_map('trim', explode(',', $p['category'] ?? ''))));

        return $matches_search && $matches_category;
    });
} else {
    $products = $all_products;
}

// Get product counts per category from the master list (prevents N+1 query issue)
$category_counts = [];
foreach ($all_products as $p) {
    // Split the product's category string into an array and count each one
    $product_categories = array_map('trim', explode(',', $p['category'] ?? 'General'));
    foreach ($product_categories as $cat) {
        if (!isset($category_counts[$cat])) $category_counts[$cat] = 0;
        $category_counts[$cat]++;
    }
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
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(15,23,42,0.07);
    padding: 20px;
    height: fit-content;
}
.shop-filters h3 {
    font-size: 1.05rem;
    font-weight: 800;
    margin-bottom: 10px;
    color: #1e293b;
}
.shop-filters .filter-section {
    margin-bottom: 18px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 12px;
}
.shop-filters .filter-section:last-child {
    border-bottom: none;
}
.filter-toggle-btn {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    background: none;
    border: none;
    color: #1e293b;
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
    background: #f1f5f9;
    color: #6366f1;
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
    color: #334155;
    cursor: pointer;
}
.shop-filters input[type="checkbox"] {
    accent-color: #6366f1;
}
.shop-filters .filter-clear {
    color: #6366f1;
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
    background: #eef2ff;
    color: #3730a3;
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
    background: white;
    border-radius: 8px;
    border: 1px solid #eee;
    overflow: hidden;
    padding: 10px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    height: 100%;
}
.product-card img {
    width: 100%;
    height: 150px;
    object-fit: contain;
    background: #fdfdfd;
    cursor: zoom-in;
}
.product-content {
    padding: 10px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.product-content h3 {
    margin-bottom: 8px;
    font-size: 1.08rem;
    color: #1e293b;
    font-weight: 700;
}
.product-content p {
    margin-bottom: 15px;
    color: #475569;
    font-size: 0.97rem;
    line-height: 1.5;
    /* Truncate description to keep cards uniform */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex-grow: 0;
}
.product-meta {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}
.product-meta .meta-tag {
    background: #eef2ff;
    color: #3730a3;
    border-radius: 8px;
    padding: 2px 10px;
    font-size: 0.92rem;
    font-weight: 600;
}
.product-price {
    font-weight: 800;
    color: #312e81;
    margin-bottom: 15px;
    font-size: 1.1rem;
    margin-top: auto; /* Pushes the price and form to the bottom */
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
    border: 1px solid #cbd5e1;
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
.alert-box.success { background: #eafaf1; color: #27ae60; border-left: 5px solid #27ae60; }
.alert-box.error { background: #fff5f5; color: #e74c3c; border-left: 5px solid #e74c3c; }

/* Detail Zoom Modal Styles */
.modal-zoomer {
    display: none;
    position: fixed;
    z-index: 10000;
    padding-top: 60px;
    left: 0; top: 0;
    width: 100%; height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.85);
    backdrop-filter: blur(5px);
}
.modal-content-wrapper {
    margin: auto;
    display: block;
    width: 90%;
    max-width: 600px;
    background: white;
    border-radius: 20px;
    overflow: hidden;
    animation: zoomIn 0.3s;
}
@keyframes zoomIn { from {transform:scale(0.8); opacity: 0;} to {transform:scale(1); opacity: 1;} }
.close-zoomer {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #f1f1f1;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
}
@media (max-width: 900px) {
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
</style>
<section class="customer-panel">
    <div class="section-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; width: 100%;">
            <h2 style="margin: 0;">Darius Poultry Supply Shop</h2>
            
            <form method="GET" action="" style="display: flex; gap: 10px; max-width: 400px; width: 100%;">
                <?php foreach ($selected_categories as $cat): ?>
                    <input type="hidden" name="categories[]" value="<?php echo htmlspecialchars($cat); ?>">
                <?php endforeach; ?>
                <div style="position: relative; flex: 1;">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search products..." style="width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9rem;">
                    <i class="fas fa-search" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #64748b;"></i>
                </div>
                <button type="submit" class="button" style="padding: 10px 20px; white-space: nowrap;">Search</button>
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
        <div style="margin-top: 25px;"></div>
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
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <?php if ($product['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" onclick='openProductModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES, 'UTF-8'); ?>)' />
                        <?php endif; ?>
                        <div class="product-content">
                            <h3 style="cursor:pointer;" onclick='openProductModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES, 'UTF-8'); ?>)'><?php echo htmlspecialchars($product['name']); ?></h3>
                            <div class="product-meta">
                                <span class="meta-tag"><?php echo htmlspecialchars($product['category'] ?? 'General'); ?></span>
                            </div>
                            <p><?php echo htmlspecialchars($product['description']); ?></p>
                            <div class="product-price">PHP <?php echo number_format($product['price'], 2); ?></div>
                            
                            <form method="POST" action="" class="cart-ajax-form">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                <div class="quantity-row">
                                    <label for="quantity_<?php echo $product['id']; ?>">Qty</label>
                                    <input type="number" id="quantity_<?php echo $product['id']; ?>" name="quantity" value="1" min="1" />
                                </div>
                                <button type="submit" class="button add-cart-btn">Add to Cart</button>
                            </form>
                            <button type="button" class="button ask-product-btn" onclick='openProductInquiryModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES, 'UTF-8'); ?>)'>Ask about this product</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<div id="productDetailModal" class="modal-zoomer">
    <span class="close-zoomer" onclick="closeProductModal()">&times;</span>
    <div class="modal-content-wrapper">
        <img id="modalImg" style="width: 100%; height: 350px; object-fit: contain; background: #f8fafc;">
        <div style="padding: 25px;">
            <h2 id="modalName" style="margin-bottom: 10px; color: #1e293b;"></h2>
            <div id="modalMeta" style="margin-bottom: 15px;"></div>
            <p id="modalDesc" style="color: #475569; line-height: 1.6; margin-bottom: 20px; font-size: 1rem;"></p>
            <div id="modalPrice" style="font-size: 1.4rem; font-weight: 800; color: #312e81;"></div>
        </div>
    </div>
</div>

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

function openProductModal(product) {
    document.getElementById("modalImg").src = product.image_url || 'https://via.placeholder.com/300';
    document.getElementById("modalName").innerText = product.name;
    document.getElementById("modalDesc").innerText = product.description;
    document.getElementById("modalPrice").innerText = "PHP " + parseFloat(product.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    const metaBox = document.getElementById("modalMeta");
    metaBox.innerHTML = `<span class="meta-tag" style="background: #eef2ff; color: #3730a3; border-radius: 8px; padding: 2px 10px; font-size: 0.92rem; font-weight: 600;">${product.category || 'General'}</span>`;
    
    document.getElementById("productDetailModal").style.display = "block";
}

function closeProductModal() {
    document.getElementById("productDetailModal").style.display = "none";
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
    if (event.target == modal) modal.style.display = "none";
}

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

    loadFrequentlyBought();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
