<?php
session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$role = strtolower(trim($payload['role'] ?? 'staff'));

if ($role !== 'admin' && $role !== 'staff') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

// Configuration of Filter Groups
$filter_groups = [
    'Product Category' => ['Dog Food', 'Cat Food', 'Chicken', 'Dog Essentials', 'Cat Essentials'],
    'Lifestage'    => ['Adult (1 - 7)', 'Kitten'],
    'Food Type'    => ['Dry Food', 'Treats', 'Wet Food'],
    'Health Needs' => ['Indoor Cats'],
    'Brand'        => ['FELIX', 'Fancy Feast', 'Friskies', 'Purina ONE®']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)$payload['user_id'];
    $message = '';
    $error = '';

    if ($action === 'add' || $action === 'update') {
        $sku = trim($_POST['sku']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $stock = (int)$_POST['stock'];
        
        // Collect all selected values from dropdowns
        $selected_cats = [];
        if (!empty($_POST['product_category_input'])) $selected_cats[] = $_POST['product_category_input'];
        if (!empty($_POST['lifestage_category']))    $selected_cats[] = $_POST['lifestage_category'];
        if (!empty($_POST['food_type_category']))    $selected_cats[] = $_POST['food_type_category'];
        if (!empty($_POST['health_needs_category'])) $selected_cats[] = $_POST['health_needs_category'];
        if (!empty($_POST['brand_category']))        $selected_cats[] = $_POST['brand_category'];

        $category = !empty($selected_cats) ? implode(', ', array_unique($selected_cats)) : 'General';
        $image_url = trim($_POST['image_url']);

        try {
            if ($action === 'add') {
                // Log the activity for adding a new product
                $stmt = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, stock, category, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssdiss", $sku, $name, $description, $price, $stock, $category, $image_url);
                if ($stmt->execute()) {
                    $message = 'Product added successfully!';
                    $log_action = "Added New Product";
                    $log_details = "User added product: $name (SKU: $sku) with initial stock: $stock.";
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            } else {
                $id = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);

                // Fetch existing data to compare for the log
                $old_stmt = $conn->prepare("SELECT * FROM tbl_product_inventory WHERE id = ?");
                $old_stmt->bind_param('i', $id);
                $old_stmt->execute();
                $old_data = $old_stmt->get_result()->fetch_assoc();
                $old_stmt->close();

                $stmt = $conn->prepare("UPDATE tbl_product_inventory SET sku=?, name=?, description=?, price=?, stock=?, category=?, image_url=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("sssdissi", $sku, $name, $description, $price, $stock, $category, $image_url, $id);
                if ($stmt->execute()) {
                    $message = 'Product updated successfully!';

                    // --- Enhanced Activity Logging ---
                    $log_action = "Updated Product";
                    $changes = [];
                    if ($old_data) {
                        if ($old_data['name'] !== $name) $changes[] = "Name from '{$old_data['name']}' to '$name'";
                        if ($old_data['sku'] !== $sku) $changes[] = "SKU from '{$old_data['sku']}' to '$sku'";
                        if ((float)$old_data['price'] != $price) $changes[] = "Price from " . number_format((float)$old_data['price'], 2) . " to " . number_format($price, 2);
                        if ((int)$old_data['stock'] !== $stock) $changes[] = "Stock from {$old_data['stock']} to $stock";
                        if ($old_data['category'] !== $category) $changes[] = "Category from '{$old_data['category']}' to '$category'";
                    }

                    $log_details = "User updated product: " . ($old_data['name'] ?? $name) . ". ";
                    $log_details .= empty($changes) ? "No changes detected." : "Changes: " . implode(', ', $changes) . ".";

                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = "success";
            header("Location: products.php");
            exit();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $_SESSION['message'] = "The SKU '$sku' is already used. Please provide a unique SKU.";
            } else {
                $_SESSION['message'] = "Database Error: " . $e->getMessage();
            }
            $_SESSION['message_type'] = "error";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);

        // Get product name before deletion for the log
        $name_stmt = $conn->prepare("SELECT name FROM tbl_product_inventory WHERE id = ?");
        $name_stmt->bind_param('i', $id);
        $name_stmt->execute();
        $name_data = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();

        $stmt = $conn->prepare("DELETE FROM tbl_product_inventory WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Product deleted successfully.";
            $_SESSION['message_type'] = "success";
            $log_action = "Deleted Product";
            $log_details = "User deleted product: " . ($name_data['name'] ?? 'ID: '.$id);
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
            $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
            $log_stmt->execute();
            $log_stmt->close();
        }
        header("Location: products.php");
        exit();
    }
}

// Fetch products logic
$selected_filters = $_GET['categories'] ?? [];
$search = trim($_GET['search'] ?? '');

// Get all products to calculate counts (similar to customer portal)
$all_inventory = get_available_products();
$category_counts = [];
foreach ($all_inventory as $p) {
    $product_categories = array_map('trim', explode(',', $p['category'] ?? 'General'));
    foreach ($product_categories as $cat) {
        if (!isset($category_counts[$cat])) $category_counts[$cat] = 0;
        $category_counts[$cat]++;
    }
}

// Filter products based on selected categories
if (!empty($selected_filters)) {
    $products = array_filter($all_inventory, function($p) use ($selected_filters) {
        $product_categories = array_map('trim', explode(',', $p['category'] ?? 'General'));
        return !empty(array_intersect($selected_filters, $product_categories));
    });
} else {
    $products = $all_inventory;
}

if ($search !== '') {
    $products = array_filter($products, function($p) use ($search) {
        return (stripos($p['name'], $search) !== false) || (stripos($p['sku'] ?? '', $search) !== false);
    });
}

// Calculate Inventory Summary Stats
$out_of_stock_count = 0;
foreach ($all_inventory as $item) {
    if (($item['stock'] ?? 0) <= 0) {
        $out_of_stock_count++;
    }
}
$total_products = count($all_inventory);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$user_id = (int)$payload['user_id'];
$unread_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css"> 
    <style>
        .admin-shop-container { display: flex; gap: 20px; padding: 20px; }
        .admin-filters { width: 250px; background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #ddd; height: fit-content; }
        .product-grid { flex: 1; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
        .product-card { background: white; border-radius: 8px; border: 1px solid #eee; overflow: hidden; padding: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
        .product-image-container { height: 150px; display: flex; align-items: center; justify-content: center; background: #fdfdfd; }
        .product-image-container img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .product-info { padding: 10px; display: flex; flex-direction: column; flex-grow: 1; }
        .product-actions { margin-top: auto; display: flex; gap: 5px; padding-top: 10px; }
        .product-actions button { flex: 1; cursor: pointer; padding: 8px; border-radius: 4px; border: 1px solid #ddd; background: #fff; }
        .stock-badge { 
            display: inline-block; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 0.75rem; 
            font-weight: bold; 
        }
        .stock-in { background: #dcfce7; color: #166534; }
        .stock-out { background: #fee2e2; color: #991b1b; }
        .inventory-summary { display: flex; gap: 20px; margin-bottom: 25px; }
        .summary-card { background: white; padding: 15px 20px; border-radius: 12px; flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #eee; display: flex; align-items: center; gap: 15px; }
        .summary-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .icon-blue { background: #eef2ff; color: #6366f1; }
        .icon-red { background: #fff1f2; color: #ef4444; }
        .product-actions .btn-delete { color: red; background: #fff5f5; border-color: #feb2b2; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 12px; width: 500px; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.85rem; color: #444; }
        .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; }
        .dropdown-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f8f9fa; padding: 10px; border-radius: 8px; border: 1px solid #eee; }
        
        .search-container { position: relative; width: 300px; }
        .search-container input { width: 100%; padding: 10px 35px 10px 15px; border-radius: 20px; border: 1px solid #ddd; outline: none; }
        .search-container i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #888; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content" style="margin-left: 260px;"> <!-- Adjust based on your sidebar width -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Product Management</h1>
            <div class="search-container">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search SKU or Name..." value="<?php echo htmlspecialchars($search); ?>">
                    <i class="fas fa-search"></i>
                </form>
            </div>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div style="background: <?php echo $_SESSION['message_type'] === 'success' ? '#eafaf1' : '#fff5f5'; ?>; color: <?php echo $_SESSION['message_type'] === 'success' ? '#27ae60' : '#e74c3c'; ?>; padding: 15px; border-radius: 8px; border-left: 5px solid <?php echo $_SESSION['message_type'] === 'success' ? '#27ae60' : '#e74c3c'; ?>; margin-bottom: 20px; font-weight: 600;" id="status-alert">
                <i class="fas <?php echo $_SESSION['message_type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <!-- Inventory Overview -->
        <div class="inventory-summary">
            <div class="summary-card">
                <div class="summary-icon icon-blue"><i class="fas fa-boxes"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Products</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #1e293b;"><?php echo $total_products; ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon icon-red"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Out of Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #ef4444;"><?php echo $out_of_stock_count; ?></div>
                </div>
            </div>
        </div>

        <div class="table-box" style="margin-bottom: 30px; border-left: 5px solid #4a3e94; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <h2 style="margin-top: 0; color: #4a3e94; font-size: 1.2rem;"><i class="fas fa-plus-circle"></i> Add New Product</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-top: 15px;">
                    <input type="text" name="sku" placeholder="SKU" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <input type="text" name="name" placeholder="Product Name" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <input type="number" step="0.01" name="price" placeholder="Price (PHP)" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <input type="number" name="stock" placeholder="Initial Stock" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    
                    <select name="product_category_input" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="">Product Category</option>
                        <?php foreach($filter_groups['Product Category'] as $o) echo "<option value='$o'>$o</option>"; ?>
                    </select>
                    <select name="lifestage_category" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="">Lifestage</option>
                        <?php foreach($filter_groups['Lifestage'] as $o) echo "<option value='$o'>$o</option>"; ?>
                    </select>
                    <select name="food_type_category" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="">Food Type</option>
                        <?php foreach($filter_groups['Food Type'] as $o) echo "<option value='$o'>$o</option>"; ?>
                    </select>
                    <select name="health_needs_category" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="">Health Needs</option>
                        <?php foreach($filter_groups['Health Needs'] as $o) echo "<option value='$o'>$o</option>"; ?>
                    </select>
                    <select name="brand_category" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="">Brand</option>
                        <?php foreach($filter_groups['Brand'] as $o) echo "<option value='$o'>$o</option>"; ?>
                    </select>

                    <input type="text" name="image_url" placeholder="Image URL" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <textarea name="description" placeholder="Product Description..." style="grid-column: 1 / -1; padding: 10px; border-radius: 5px; border: 1px solid #ddd;"></textarea>
                    
                    <div style="grid-column: 1 / -1; text-align: right;">
                        <button type="submit" style="background: #4a3e94; color: white; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: bold;">
                            Save New Product
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="admin-shop-container">
            <!-- Sidebar Filters -->
            <aside class="admin-filters">
                <h3>Filters</h3>
                <form id="filter-form" method="GET">
                    <?php foreach($filter_groups as $title => $opts): ?>
                        <div style="margin-bottom:10px;">
                            <strong style="font-size: 0.85rem; color: #4a3e94;"><?php echo $title; ?></strong><br>
                            <?php foreach($opts as $o): ?>
                                <label style="display:block; font-size:0.8rem; cursor:pointer; margin-bottom: 2px;">
                                    <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($o); ?>" 
                                    <?php echo in_array($o, $selected_filters) ? 'checked' : ''; ?> 
                                    onchange="this.form.submit()"> <?php echo htmlspecialchars($o); ?>
                                    <span style="color:#64748b;">(<?php echo $category_counts[$o] ?? 0; ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <a href="products.php" style="color:red; font-size:0.8rem;">Clear Filters</a>
                </form>
            </aside>

            <!-- Product Display -->
            <div class="product-grid">
                <?php foreach($products as $p): ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <img src="<?php echo $p['image_url']; ?>" onerror="this.src='https://via.placeholder.com/150'">
                        </div>
                        <div class="product-info">
                            <small style="color:#888;"><?php echo $p['category']; ?></small>
                            <h4 style="margin:5px 0;"><?php echo $p['name']; ?></h4>
                            <strong style="color:#4a3e94;">PHP <?php echo number_format($p['price'], 2); ?></strong>
                            <div style="margin-top: 8px;">
                                <?php if (($p['stock'] ?? 0) <= 0): ?>
                                    <span class="stock-badge stock-out"><i class="fas fa-times-circle"></i> Out of Stock</span>
                                <?php else: ?>
                                    <span class="stock-badge stock-in"><i class="fas fa-check-circle"></i> Stock: <?php echo number_format($p['stock']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="product-actions">
                                <button onclick='openModal("edit", <?php echo $p["id"]; ?>, <?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)'>Edit</button>
                                <button onclick="deleteProduct(<?php echo $p['id']; ?>)" class="btn-delete">Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add Product</h2>
            <form method="POST" id="productForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="product_id" id="productId">
                
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" id="sku" required>
                </div>
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="name" id="name" required>
                </div>

                <!-- CATEGORY DROPDOWNS (Separated like Lifestage) -->
                <label style="font-size: 0.85rem; font-weight: bold; color: #4a3e94;">Product Categories</label>
                <div class="dropdown-grid">
                    <div class="form-group">
                        <label>Main Category</label>
                        <select name="product_category_input" id="cat_product">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Product Category'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lifestage</label>
                        <select name="lifestage_category" id="cat_lifestage">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Lifestage'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Food Type</label>
                        <select name="food_type_category" id="cat_foodtype">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Food Type'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Health Needs</label>
                        <select name="health_needs_category" id="cat_health">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Health Needs'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Brand</label>
                        <select name="brand_category" id="cat_brand">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Brand'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description"></textarea>
                </div>

                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Price</label>
                        <input type="number" step="0.01" name="price" id="price" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Stock</label>
                        <input type="number" name="stock" id="stock" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Image URL</label>
                    <input type="text" name="image_url" id="image_url">
                </div>

                <div style="display:flex; justify-content: flex-end; gap: 10px; margin-top:10px;">
                    <button type="button" onclick="closeModal()">Cancel</button>
                    <button type="submit" style="background:#4a3e94; color:white; border:none; padding:8px 15px; border-radius:5px; cursor:pointer;">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
        const filterGroups = <?php echo json_encode($filter_groups); ?>;

        function openModal(mode, id = null, product = null) {
            const form = document.getElementById('productForm');
            form.reset();
            
            if(mode === 'add') {
                document.getElementById('modalTitle').innerText = "Add Product";
                document.getElementById('formAction').value = "add";
            } else {
                document.getElementById('modalTitle').innerText = "Edit Product";
                document.getElementById('formAction').value = "update";
                document.getElementById('productId').value = product.id;
                document.getElementById('sku').value = product.sku;
                document.getElementById('name').value = product.name;
                document.getElementById('description').value = product.description;
                document.getElementById('price').value = product.price;
                document.getElementById('stock').value = product.stock;
                document.getElementById('image_url').value = product.image_url;

                // Handle mapping the comma-separated string back to dropdowns
                if(product.category) {
                    const cats = product.category.split(',').map(s => s.trim());
                    
                    // Helper to auto-select dropdowns
                    Object.keys(filterGroups).forEach(groupKey => {
                        const selectId = groupKey === 'Product Category' ? 'cat_product' :
                                         groupKey === 'Lifestage' ? 'cat_lifestage' : 
                                         groupKey === 'Food Type' ? 'cat_foodtype' :
                                         groupKey === 'Health Needs' ? 'cat_health' : 
                                         groupKey === 'Brand' ? 'cat_brand' : null;
                        
                        const selectElement = selectId ? document.getElementById(selectId) : null;
                        const match = cats.find(c => filterGroups[groupKey].includes(c));
                        if(match && selectElement) selectElement.value = match;
                    });
                }
            }
            document.getElementById('productModal').classList.add('active');
        }

        function closeModal() { document.getElementById('productModal').classList.remove('active'); }

        function deleteProduct(id) {
            if(confirm("Delete this product?")) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Auto-hide alert after 3 seconds
        setTimeout(() => {
            const alert = document.getElementById('status-alert');
            if (alert) alert.style.opacity = '0';
            setTimeout(() => { if(alert) alert.style.display = 'none'; }, 500);
        }, 3000);
    </script>
</body>
</html>