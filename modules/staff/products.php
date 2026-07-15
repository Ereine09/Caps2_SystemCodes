<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_role = strtolower(trim($payload['role'] ?? ''));
if (!in_array($user_role, ['staff', 'admin'], true)) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = (int) ($payload['user_id'] ?? 0);
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $stock = intval($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');
        $image_url = trim($_POST['image_url'] ?? '');
        
        if ($sku && $name && $price > 0) {
            $stmt = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, stock, category, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssdiss', $sku, $name, $description, $price, $stock, $category, $image_url);
            if ($stmt->execute()) {
                $message = 'Product added successfully!';
                
                // Log the activity
                $log_action = "Added New Product";
                $log_details = "User added product: $name (SKU: $sku) with initial stock: $stock.";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $error = 'Failed to add product. SKU might already exist.';
            }
            $stmt->close();
        } else {
            $error = 'Please fill in required fields (SKU, Name, Price).';
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $stock = intval($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');
        $image_url = trim($_POST['image_url'] ?? '');
        
        if ($id > 0 && $sku && $name && $price > 0) {
            // Fetch existing data to calculate stock differences for the log
            $old_stmt = $conn->prepare("SELECT name, stock FROM tbl_product_inventory WHERE id = ?");
            $old_stmt->bind_param('i', $id);
            $old_stmt->execute();
            $old_data = $old_stmt->get_result()->fetch_assoc();
            $old_stmt->close();

            $stmt = $conn->prepare("UPDATE tbl_product_inventory SET sku = ?, name = ?, description = ?, price = ?, stock = ?, category = ?, image_url = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('sssdissi', $sku, $name, $description, $price, $stock, $category, $image_url, $id);
            if ($stmt->execute()) {
                $message = 'Product updated successfully!';
                
                // Log the activity
                $log_action = "Updated Product Details/Stock";
                $log_details = "User updated product: " . ($old_data['name'] ?? 'Unknown') . ". Stock changed from " . ($old_data['stock'] ?? 0) . " to $stock.";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $error = 'Failed to update product.';
            }
            $stmt->close();
        } else {
            $error = 'Invalid product data.';
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // Get product name before deletion for the log
            $name_stmt = $conn->prepare("SELECT name FROM tbl_product_inventory WHERE id = ?");
            $name_stmt->bind_param('i', $id);
            $name_stmt->execute();
            $name_data = $name_stmt->get_result()->fetch_assoc();
            $name_stmt->close();

            $stmt = $conn->prepare("DELETE FROM tbl_product_inventory WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $message = 'Product deleted successfully!';
                
                // Log the activity
                $log_action = "Deleted Product";
                $log_details = "User deleted product: " . ($name_data['name'] ?? 'ID: '.$id);
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $error = 'Failed to delete product. It might be in use.';
            }
            $stmt->close();
        }
    }
}

// Fetch pending orders count for the sidebar badge
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;

// Get all products
$products = get_available_products();
$categories = get_all_categories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management - TOMORO</title>
    <link rel="stylesheet" href="../../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .product-management {
            padding: 24px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .page-header h1 {
            color: #1e293b;
            font-size: 1.8rem;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }
        .btn-primary {
            background: #6366f1;
            color: white;
        }
        .btn-primary:hover {
            background: #4f46e5;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .product-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .product-image {
            width: 100%;
            height: 180px;
            object-fit: contain;
            background: #f8fafc;
        }
        .product-details {
            padding: 18px;
        }
        .product-details h3 {
            margin: 0 0 8px;
            color: #1e293b;
            font-size: 1.1rem;
        }
        .product-sku {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 8px;
        }
        .product-description {
            color: #475569;
            font-size: 0.95rem;
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .product-price {
            font-size: 1.3rem;
            font-weight: 800;
            color: #312e81;
        }
        .product-stock {
            background: #eef2ff;
            color: #3730a3;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .product-category {
            display: inline-block;
            background: #f0fdf4;
            color: #166534;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .product-actions {
            display: flex;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 28px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h2 {
            margin: 0;
            color: #1e293b;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #334155;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.95rem;
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>><i class="fas fa-comment-dots"></i> Messages</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/settings.php" <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'class="active"' : ''; ?>><i class="fas fa-cogs"></i> System Settings</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    
    <main class="admin-content">
        <div class="product-management">
            <div class="page-header">
                <h1><i class="fas fa-boxes"></i> Product Management</h1>
                <button class="btn btn-primary" onclick="openModal('add')">
                    <i class="fas fa-plus"></i> Add Product
                </button>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <?php if ($product['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                        <?php else: ?>
                            <div class="product-image" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;">
                                <i class="fas fa-image fa-3x"></i>
                            </div>
                        <?php endif; ?>
                        <div class="product-details">
                            <span class="product-sku"><?php echo htmlspecialchars($product['sku']); ?></span>
                            <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                            <span class="product-category"><?php echo htmlspecialchars($product['category'] ?? 'General'); ?></span>
                            <p class="product-description"><?php echo htmlspecialchars($product['description'] ?? ''); ?></p>
                            <div class="product-meta">
                                <span class="product-price">PHP <?php echo number_format($product['price'], 2); ?></span>
                                <span class="product-stock">Stock: <?php echo intval($product['stock']); ?></span>
                            </div>
                            <div class="product-actions">
                                <button class="btn btn-success btn-sm" onclick="openModal('edit', <?php echo $product['id']; ?>, '<?php echo htmlspecialchars(json_encode($product)); ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $product['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($products)): ?>
                    <p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 40px;">
                        No products found. Add your first product!
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <!-- Add/Edit Modal -->
    <div class="modal" id="productModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Product</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="productForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="productId" value="">
                
                <div class="form-group">
                    <label for="sku">SKU *</label>
                    <input type="text" id="sku" name="sku" required placeholder="e.g., TOMORO-DOG-01">
                </div>
                
                <div class="form-group">
                    <label for="name">Product Name *</label>
                    <input type="text" id="name" name="name" required placeholder="e.g., Premium Dog Food">
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Product description..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="price">Price (PHP) *</label>
                        <input type="number" id="price" name="price" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="stock">Stock</label>
                        <input type="number" id="stock" name="stock" min="0" value="0" placeholder="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="Dog Essentials">Dog Essentials</option>
                        <option value="Cat Essentials">Cat Essentials</option>
                        <option value="Pet Essentials">Pet Essentials</option>
                        <option value="General">General</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="image_url">Image URL</label>
                    <input type="text" id="image_url" name="image_url" placeholder="/assets/img/product.png">
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </form>
        </div>
    </div>
    
    <!-- Delete Form -->
    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId" value="">
    </form>
    
    <script>
        function openModal(mode, id = null, productData = null) {
            const modal = document.getElementById('productModal');
            const form = document.getElementById('productForm');
            const title = document.getElementById('modalTitle');
            const action = document.getElementById('formAction');
            
            if (mode === 'add') {
                title.textContent = 'Add Product';
                action.value = 'add';
                form.reset();
                document.getElementById('productId').value = '';
            } else if (mode === 'edit' && productData) {
                title.textContent = 'Edit Product';
                action.value = 'update';
                const product = typeof productData === 'string' ? JSON.parse(productData) : productData;
                document.getElementById('productId').value = product.id;
                document.getElementById('sku').value = product.sku || '';
                document.getElementById('name').value = product.name || '';
                document.getElementById('description').value = product.description || '';
                document.getElementById('price').value = product.price || '';
                document.getElementById('stock').value = product.stock || '';
                document.getElementById('category').value = product.category || 'General';
                document.getElementById('image_url').value = product.image_url || '';
            }
            
            modal.classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('productModal').classList.remove('active');
        }
        
        function deleteProduct(id) {
            if (confirm('Are you sure you want to delete this product?')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('productModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>