<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$message = '';
// Define specific filter groups for Darius Poultry Supply
$filter_groups = [
    'Product Category' => ['Dog Food', 'Cat Food', 'Chicken', 'Dog Essentials', 'Cat Essentials'],
    'Lifestage' => ['Adult (1 - 7)', 'Kitten'],
    'Food Type' => ['Dry Food', 'Treats', 'Wet Food'],
    'Health Needs' => ['Indoor Cats'],
    'Brand' => ['FELIX', 'Fancy Feast', 'Friskies', 'Purina ONE®']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = (int) $_POST['product_id'];
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $product = get_product_by_id($product_id);
    if ($product) {
        $customer = current_customer();
        $available_stock = get_product_stock($product_id); // Assume this function exists in includes/functions.php
        if ($quantity > $available_stock) {
            $message = 'Insufficient stock for "' . htmlspecialchars($product['name']) . '". Only ' . $available_stock . ' available.';
        } elseif ($customer && add_customer_cart_item((int)$customer['id'], $product_id, $quantity)) {
            // Assuming add_customer_cart_item handles updating existing quantities or adding new.
            // If it updates, it should also respect stock limits.
            // For simplicity here, we've already checked, so it should succeed if cart logic is sound.
            $message = 'Product "' . htmlspecialchars($product['name']) . '" added to cart.';
        } else {
            $message = 'Unable to add the product to your cart.';
        }
    } else {
        $message = 'Product not found.';
    }
}

$selected_categories = $_GET['categories'] ?? [];
$all_products = get_customer_products();

// Filter products based on selected categories locally to save DB queries
if (!empty($selected_categories)) {
    $products = array_filter($all_products, function($p) use ($selected_categories) {
        // Split the product's category string into an array of individual categories
        $product_categories = array_map('trim', explode(',', $p['category']));
        // Check if there's any overlap between selected_categories and product_categories
        return !empty(array_intersect($selected_categories, $product_categories));
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
    width: 250px;
    background: #fff;
    border-radius: 10px;
    border: 1px solid #ddd;
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
    background: none;
    color: #1e293b;
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
    margin-bottom: 2px;
    font-size: 0.8rem;
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
        <h2>Darius Poultry Supply Shop</h2>
    </div>
    <div class="shop-container">
        <aside class="shop-filters">
            <form method="get" id="filter-form">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <h3 style="margin: 0;">Filters</h3>
                    <a href="products.php" class="filter-clear">Clear all</a>
                </div>

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
                            <form method="POST" action="">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                                <div class="quantity-row">
                                    <label for="quantity_<?php echo $product['id']; ?>">Qty</label>
                                    <input type="number" id="quantity_<?php echo $product['id']; ?>" name="quantity" value="1" min="1" />
                                </div>
                                <button type="submit" class="button add-cart-btn">Add to Cart</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Product Detail Modal -->
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

<script>
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

window.onclick = function(event) {
    const modal = document.getElementById("productDetailModal");
    if (event.target == modal) modal.style.display = "none";
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
