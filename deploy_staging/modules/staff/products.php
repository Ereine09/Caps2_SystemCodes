<?php
/**
 * Staff — Product management.
 * Same page as modules/admin/products.php (filters, Add/Edit/Delete, variants, image
 * carousel, Inventory Alerts) — only the auth gate and sidebar/link paths differ.
 */
session_start();
require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// Ensure variants table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tbl_product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    size VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    image_url VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_size (product_id, size)
)");

// Add image_url column if it doesn't exist (migration)
$result = mysqli_query($conn, "SHOW COLUMNS FROM tbl_product_variants LIKE 'image_url'");
if (mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE tbl_product_variants ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER stock");
}

// Add weight_kg columns if they don't exist (migration) — lets "Online Purchase"
// loyalty transactions report a real quantity_kg instead of a hardcoded 0.00.
$result = mysqli_query($conn, "SHOW COLUMNS FROM tbl_product_inventory LIKE 'weight_kg'");
if (mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE tbl_product_inventory ADD COLUMN weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER price");
}
$result = mysqli_query($conn, "SHOW COLUMNS FROM tbl_product_variants LIKE 'weight_kg'");
if (mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE tbl_product_variants ADD COLUMN weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER price");
}

// Add low_stock_threshold / stock_alert_level columns if they don't exist
// (migration) — required by the Inventory Alerts counters below and by
// evaluate_stock_alert() (app/helpers/notification_helper.php).
ensure_stock_alert_columns($conn);

$payload = require_staff();
$username = $payload['username'];
$role = 'staff';

// Configuration of Filter Groups (Base / Static)
$filter_groups = [
    'Product Category' => ['Dog Food', 'Cat Food', 'Chicken', 'Chicken Feed', 'Dog Essentials', 'Cat Essentials', 'Dog & Cat Medicine', 'Skin Care & Treatment'],
    'Lifestage'    => ['Adult (1 - 7)', 'Kitten'],
    'Food Type'    => ['Dry Food', 'Treats', 'Wet Food'],
    'Health Needs' => ['Indoor Cats', 'Sensitive Skin', 'Small Breeds'],
    'Brand'        => ['FELIX', 'Fancy Feast', 'Friskies', 'Purina ONE®']
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

// Merge custom categories into filter groups (avoiding duplicates)
if (!empty($custom_health)) {
    $filter_groups['Health Needs'] = array_unique(array_merge($filter_groups['Health Needs'], $custom_health));
}
if (!empty($custom_brands)) {
    $filter_groups['Brand'] = array_unique(array_merge($filter_groups['Brand'], $custom_brands));
}
if (!empty($custom_lifestages)) {
    $filter_groups['Lifestage'] = array_unique(array_merge($filter_groups['Lifestage'], $custom_lifestages));
}

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
        $weight_kg = (float)($_POST['weight_kg'] ?? 0);
        $low_stock_threshold = max(0, (int)($_POST['low_stock_threshold'] ?? 10));
        
        // Collect all selected values from dropdowns and custom inputs
        $selected_cats = [];
        if (!empty($_POST['product_category_input'])) $selected_cats[] = $_POST['product_category_input'];
        if (!empty($_POST['lifestage_category']))    $selected_cats[] = $_POST['lifestage_category'];
        if (!empty($_POST['lifestage_custom']))      $selected_cats[] = $_POST['lifestage_custom'];
        if (!empty($_POST['food_type_category']))    $selected_cats[] = $_POST['food_type_category'];
        if (!empty($_POST['health_needs_category'])) $selected_cats[] = $_POST['health_needs_category'];
        if (!empty($_POST['health_needs_custom']))   $selected_cats[] = $_POST['health_needs_custom'];
        if (!empty($_POST['brand_category']))        $selected_cats[] = $_POST['brand_category'];
        if (!empty($_POST['brand_custom']))          $selected_cats[] = $_POST['brand_custom'];

        $category = !empty($selected_cats) ? implode(', ', array_unique($selected_cats)) : 'General';
        $image_url = trim($_POST['image_url']);

        // Save any custom categories to the database for future dropdown/filter use
        $custom_lifestage_val = trim($_POST['lifestage_custom'] ?? '');
        $custom_health_val = trim($_POST['health_needs_custom'] ?? '');
        $custom_brand_val = trim($_POST['brand_custom'] ?? '');
        
        if (!empty($custom_lifestage_val)) {
            $ins = $conn->prepare("INSERT IGNORE INTO tbl_custom_categories (group_name, category_value) VALUES ('Lifestage', ?)");
            $ins->bind_param('s', $custom_lifestage_val);
            $ins->execute();
            $ins->close();
        }
        if (!empty($custom_health_val)) {
            $ins = $conn->prepare("INSERT IGNORE INTO tbl_custom_categories (group_name, category_value) VALUES ('Health Needs', ?)");
            $ins->bind_param('s', $custom_health_val);
            $ins->execute();
            $ins->close();
        }
        if (!empty($custom_brand_val)) {
            $ins = $conn->prepare("INSERT IGNORE INTO tbl_custom_categories (group_name, category_value) VALUES ('Brand', ?)");
            $ins->bind_param('s', $custom_brand_val);
            $ins->execute();
            $ins->close();
        }

        try {
            if ($action === 'add') {
                // Log the activity for adding a new product
                $stmt = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, weight_kg, stock, category, image_url, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssddissi", $sku, $name, $description, $price, $weight_kg, $stock, $category, $image_url, $low_stock_threshold);
                if ($stmt->execute()) {
                    $message = 'Product added successfully!';
                    $log_action = "Added New Product";
                    $log_details = "User added product: $name (SKU: $sku) with initial stock: $stock.";
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                    $log_stmt->execute();
                    $log_stmt->close();

                    // Handle variants
                    $product_id = $stmt->insert_id;
                    if (isset($_POST['variant_size']) && is_array($_POST['variant_size'])) {
                        $variant_stmt = $conn->prepare("INSERT INTO tbl_product_variants (product_id, size, price, weight_kg, stock, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($_POST['variant_size'] as $key => $size) {
                            if (!empty($size)) {
                                $v_price = (float)($_POST['variant_price'][$key] ?? 0);
                                $v_weight_kg = (float)($_POST['variant_weight_kg'][$key] ?? 0);
                                $v_stock = (int)($_POST['variant_stock'][$key] ?? 0);
                                $v_image = trim((string)($_POST['variant_image_url'][$key] ?? ''));
                                $variant_stmt->bind_param('isddis', $product_id, $size, $v_price, $v_weight_kg, $v_stock, $v_image);
                                $variant_stmt->execute();                            }
                        }
                        $variant_stmt->close();
                    }
                }
            } else {
                $id = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);

                // Fetch existing data to compare for the log
                $old_stmt = $conn->prepare("SELECT * FROM tbl_product_inventory WHERE id = ?");
                $old_stmt->bind_param('i', $id);
                $old_stmt->execute();
                $old_data = $old_stmt->get_result()->fetch_assoc();
                $old_stmt->close();

                $stmt = $conn->prepare("UPDATE tbl_product_inventory SET sku=?, name=?, description=?, price=?, weight_kg=?, stock=?, category=?, image_url=?, low_stock_threshold=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("sssddissii", $sku, $name, $description, $price, $weight_kg, $stock, $category, $image_url, $low_stock_threshold, $id);
                if ($stmt->execute()) {
                    $message = 'Product updated successfully!';
                    // An admin directly editing stock/threshold here is a real
                    // stock change too - recompute the alert level immediately
                    // instead of waiting for the next order to touch this product.
                    evaluate_stock_alert($conn, 'tbl_product_inventory', $id);

                    // --- Enhanced Activity Logging ---
                    $log_action = "Updated Product";
                    $changes = [];
                    if ($old_data) {
                        if ($old_data['name'] !== $name) $changes[] = "Name from '{$old_data['name']}' to '$name'";
                        if ($old_data['sku'] !== $sku) $changes[] = "SKU from '{$old_data['sku']}' to '$sku'";
                        if ((float)$old_data['price'] != $price) $changes[] = "Price from " . number_format((float)$old_data['price'], 2) . " to " . number_format($price, 2);
                        if ((float)($old_data['weight_kg'] ?? 0) != $weight_kg) $changes[] = "Weight from " . number_format((float)($old_data['weight_kg'] ?? 0), 3) . "kg to " . number_format($weight_kg, 3) . "kg";
                        if ((int)$old_data['stock'] !== $stock) $changes[] = "Stock from {$old_data['stock']} to $stock";
                        if ($old_data['category'] !== $category) $changes[] = "Category from '{$old_data['category']}' to '$category'";
                    }

                    $log_details = "User updated product: " . ($old_data['name'] ?? $name) . ". ";
                    $log_details .= empty($changes) ? "No changes detected." : "Changes: " . implode(', ', $changes) . ".";

                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
                    $log_stmt->execute();
                    $log_stmt->close();

                    // --- UPDATE/ADD/DELETE VARIANTS ---
                    $conn->query("DELETE FROM tbl_product_variants WHERE product_id = $id"); // Simple approach: clear and re-insert
                    if (isset($_POST['variant_size']) && is_array($_POST['variant_size'])) {
                        $variant_stmt = $conn->prepare("INSERT INTO tbl_product_variants (product_id, size, price, weight_kg, stock, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($_POST['variant_size'] as $key => $size) {
                            if (!empty($size)) {
                                $v_price = (float)($_POST['variant_price'][$key] ?? 0);
                                $v_weight_kg = (float)($_POST['variant_weight_kg'][$key] ?? 0);
                                $v_stock = (int)($_POST['variant_stock'][$key] ?? 0);
                                $v_image = trim((string)($_POST['variant_image_url'][$key] ?? ''));
                                $variant_stmt->bind_param('isddis', $id, $size, $v_price, $v_weight_kg, $v_stock, $v_image);
                                $variant_stmt->execute();
                            }
                        }
                        $variant_stmt->close();
                    }
                    // In a more complex system, you would compare existing variants and update/insert/delete individually
                    // to preserve variant IDs, but for this scope, re-inserting is simpler.


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

// --- Pagination (over the already-filtered/searched list above) ---
$products = array_values($products); // re-index after array_filter
$total_filtered_products = count($products);
$per_page = 12;
$total_pages = max(1, (int) ceil($total_filtered_products / $per_page));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;
$products = array_slice($products, ($page - 1) * $per_page, $per_page);

/**
 * Builds a products.php URL for the given page number, preserving the current
 * search/category filters (everything already in $_GET) so paging never loses them.
 */
function products_page_url(int $target_page): string
{
    $params = $_GET;
    $params['page'] = $target_page;
    $qs = http_build_query($params);
    return 'products.php' . ($qs !== '' ? '?' . $qs : '');
}

// Calculate Inventory Summary Stats
$out_of_stock_count = 0;
foreach ($all_inventory as $item) {
    $product_id = (int)$item['id'];
    $variants = get_product_variants($product_id);
    
    // Check if product itself is out of stock
    $product_stock = (int)($item['stock'] ?? 0);
    
    // Check if ALL variants are out of stock (if variants exist)
    $all_variants_out = false;
    $has_variants = !empty($variants);
    if ($has_variants) {
        $all_variants_out = true;
        foreach ($variants as $variant) {
            if ((int)($variant['stock'] ?? 0) > 0) {
                $all_variants_out = false;
                break;
            }
        }
    }
    
    // Count as out of stock if: (no variants and product stock is 0) OR (has variants and all are out)
    if ((!$has_variants && $product_stock <= 0) || ($has_variants && $all_variants_out)) {
        $out_of_stock_count++;
    }
}
$total_products = count($all_inventory);

// Inventory Alerts (Low Stock Monitoring) - real counts from the event-based
// stock_alert_level column that evaluate_stock_alert() keeps in sync on every
// deduction/restore. Counts distinct PRODUCTS that have at least one row
// (base stock or any variant) currently sitting in that band.
$low_stock_count = (int) (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT p.id) AS c FROM tbl_product_inventory p
     LEFT JOIN tbl_product_variants v ON v.product_id = p.id
     WHERE p.stock_alert_level = 'low' OR v.stock_alert_level = 'low'"
))['c'] ?? 0);
$critical_stock_count = (int) (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT p.id) AS c FROM tbl_product_inventory p
     LEFT JOIN tbl_product_variants v ON v.product_id = p.id
     WHERE p.stock_alert_level = 'critical' OR v.stock_alert_level = 'critical'"
))['c'] ?? 0);

$pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$user_id = (int) $payload['user_id'];
$unread_messages_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Management - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .admin-shop-container { display: flex; gap: 20px; padding: 20px; }
        .admin-filters { width: 250px; background: var(--surface); padding: 20px; border-radius: 10px; border: 1px solid var(--border); height: fit-content; }
        .product-grid { flex: 1; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
        .product-card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); overflow: hidden; padding: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
        .product-image-container { height: 150px; display: flex; align-items: center; justify-content: center; background: var(--surface-2); }
        .product-image-container img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .product-info { padding: 10px; display: flex; flex-direction: column; flex-grow: 1; }
        .product-actions { margin-top: auto; display: flex; gap: 5px; padding-top: 10px; }
        .product-actions button { flex: 1; cursor: pointer; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--surface); }
        .stock-badge { 
            display: inline-block; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 0.75rem; 
            font-weight: bold; 
        }
        .stock-in { background: var(--success-soft); color: var(--success); }
        .stock-out { background: var(--danger-soft); color: var(--danger); }
        .product-variants { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); position: relative; }
        .product-variants-label { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; color: var(--link); text-transform: uppercase; margin-bottom: 8px; display: block; }
        .carousel-container { position: relative; height: 120px; background: var(--surface-2); border-radius: 6px; overflow: hidden; display: flex; align-items: center; }
        .carousel-wrapper { display: flex; height: 100%; width: 100%; transition: transform 0.3s ease-in-out; }
        .carousel-item { flex: 0 0 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 8px; text-align: center; border: 1px solid var(--border); }
        .carousel-item img { max-width: 80px; max-height: 60px; object-fit: contain; margin-bottom: 4px; border-radius: 4px; }
        .carousel-item-info { font-size: 0.7rem; font-weight: 600; color: var(--text); }
        .carousel-item-details { font-size: 0.65rem; color: var(--muted); }
        .carousel-nav { position: absolute; top: 50%; width: 100%; display: flex; justify-content: space-between; padding: 0 4px; pointer-events: none; z-index: 10; }
        .carousel-btn { pointer-events: all; background: rgba(74, 62, 148, 0.8); color: white; border: none; width: 24px; height: 24px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; transition: background 0.2s; }
        .carousel-btn:hover { background: rgba(74, 62, 148, 1); }
        .carousel-dots { display: flex; justify-content: center; gap: 4px; margin-top: 6px; }
        .dot { width: 6px; height: 6px; border-radius: 50%; background: #cbd5e1; cursor: pointer; transition: background 0.2s; }
        .dot.active { background: #4a3e94; }
        .inventory-summary { display: flex; gap: 20px; margin-bottom: 25px; }
        .variants-container { margin-top: 15px; border-top: 1px solid var(--border); padding-top: 15px; }
        .variant-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1.2fr 0.08fr; gap: 8px; margin-bottom: 10px; align-items: end; }
        .variant-row input { padding: 8px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.85rem; }
        .variant-row label { font-size: 0.75rem; font-weight: 600; color: var(--link); display: block; margin-bottom: 3px; }
        .variant-row-group { display: flex; flex-direction: column; }
        #add-variant-btn { background: var(--primary-soft); color: var(--link); border: 1px solid #c7d2fe; font-size: 0.8rem; font-weight: bold; }
        .btn-remove-variant { background: var(--danger-soft); color: var(--danger); border: 1px solid #fca5a5; padding: 6px 8px; cursor: pointer; border-radius: 4px; font-weight: bold; }

        .summary-card { background: var(--surface); padding: 15px 20px; border-radius: 12px; flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border); display: flex; align-items: center; gap: 15px; }
        .summary-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .icon-blue { background: var(--primary-soft); color: var(--link); }
        .icon-red { background: #fff1f2; color: var(--danger); }
        .product-actions .btn-delete { color: red; background: var(--danger-soft); border-color: #feb2b2; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 25px; border-radius: 12px; width: 700px; max-width: 90vw; max-height: 90vh; overflow-y: auto; box-sizing: border-box; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.85rem; color: var(--text-2); }
        .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 5px; box-sizing: border-box; }
        .dropdown-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: var(--surface-2); padding: 10px; border-radius: 8px; border: 1px solid var(--border); }
        
        .search-container { position: relative; width: 300px; }
        .search-container input { width: 100%; padding: 10px 35px 10px 15px; border-radius: 20px; border: 1px solid var(--border); outline: none; }
        .search-container i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--muted); }

        .pg-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; margin: 24px 0 6px; }
        .pg-pagination a, .pg-pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.85rem; font-weight: 600; text-decoration: none; color: var(--link); background: var(--surface); }
        .pg-pagination a:hover { background: #f1f0fb; border-color: #4a3e94; }
        .pg-pagination span.pg-current { background: #4a3e94; border-color: #4a3e94; color: #fff; }
        .pg-pagination span.pg-disabled, .pg-pagination span.pg-ellipsis { opacity: 0.45; cursor: not-allowed; border-color: transparent; background: transparent; }
        .pg-info { text-align: center; color: var(--muted); font-size: 0.85rem; margin: 0 0 10px; }
            /* Add New Product (labeled, grouped form in a collapsible card) */
        .np-summary { cursor: pointer; list-style: none; border-bottom: 0; }
        .np-summary::-webkit-details-marker { display: none; }
        .np-card[open] .np-summary { border-bottom: 1px solid var(--border); }
        .np-card[open] .np-toggle { display: none; }
        .np-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .np-span-2 { grid-column: span 2; }
        .np-pair { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px; }
        .np-card input.ui-input, .np-card select.ui-select { height: 42px; min-height: 42px; padding: 8px 12px; margin: 0; }
        .np-card input[type="file"].ui-input { padding: 6px 8px; }
        @media (max-width: 1100px) { .np-grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .np-grid-4 { grid-template-columns: minmax(0, 1fr); } .np-span-2 { grid-column: auto; } .np-pair { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
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
            <div class="summary-card" title="Stock at or below the product's critical threshold (5 units by default)">
                <div class="summary-icon" style="background: #fee2e2; color: #dc2626;"><i class="fas fa-fire"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">🔴 Critical Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #dc2626;"><?php echo $critical_stock_count; ?></div>
                </div>
            </div>
            <div class="summary-card" title="Stock at or below the product's configured low-stock threshold">
                <div class="summary-icon" style="background: #ffedd5; color: #ea580c;"><i class="fas fa-battery-quarter"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">🟠 Low Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #ea580c;"><?php echo $low_stock_count; ?></div>
                </div>
            </div>
        </div>

        <details class="ui-card np-card" style="margin-bottom: 24px;">
            <summary class="ui-card-head np-summary">
                <span>
                    <span class="ui-card-title"><i class="fas fa-plus-circle"></i> Add New Product</span>
                    <span class="ui-card-sub">Create a catalog item. Required fields are marked *.</span>
                </span>
                <span class="ui-btn ui-btn-primary ui-btn-sm np-toggle"><i class="fas fa-plus"></i> New product</span>
            </summary>
            <form method="POST" class="ui-card-body ui-form">
                <input type="hidden" name="action" value="add">

                <fieldset class="ui-fieldset">
                    <legend>Basic information</legend>
                    <div class="ui-form-grid np-grid-4">
                        <div class="ui-field">
                            <label for="np_sku">SKU *</label>
                            <input type="text" id="np_sku" name="sku" required class="ui-input">
                        </div>
                        <div class="ui-field np-span-2">
                            <label for="np_name">Product name *</label>
                            <input type="text" id="np_name" name="name" required class="ui-input">
                        </div>
                        <div class="ui-field">
                            <label for="np_price">Price (PHP) *</label>
                            <input type="number" step="0.01" id="np_price" name="price" required class="ui-input">
                        </div>
                        <div class="ui-field">
                            <label for="np_weight">Weight (kg)</label>
                            <input type="number" step="0.001" id="np_weight" name="weight_kg" class="ui-input">
                        </div>
                        <div class="ui-field">
                            <label for="np_stock">Initial stock *</label>
                            <input type="number" id="np_stock" name="stock" required class="ui-input">
                        </div>
                        <div class="ui-field">
                            <label for="np_low">Low-stock alert at</label>
                            <input type="number" id="np_low" name="low_stock_threshold" value="10" min="0" class="ui-input" title="Admin gets alerted by email once stock falls to/below this number">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="ui-fieldset">
                    <legend>Categories</legend>
                    <div class="ui-form-grid">
                        <div class="ui-field">
                            <label for="np_cat">Product category</label>
                            <select id="np_cat" name="product_category_input" class="ui-select">
                                <option value="">Select category</option>
                                <?php foreach ($filter_groups['Product Category'] as $o) echo '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; ?>
                            </select>
                        </div>
                        <div class="ui-field">
                            <label for="np_food">Food type</label>
                            <select id="np_food" name="food_type_category" class="ui-select">
                                <option value="">Select food type</option>
                                <?php foreach ($filter_groups['Food Type'] as $o) echo '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; ?>
                            </select>
                        </div>
                        <div class="ui-field">
                            <label for="np_life">Lifestage</label>
                            <div class="np-pair">
                                <select id="np_life" name="lifestage_category" class="ui-select">
                                    <option value="">Select lifestage</option>
                                    <?php foreach ($filter_groups['Lifestage'] as $o) echo '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; ?>
                                </select>
                                <input type="text" name="lifestage_custom" placeholder="or add a new one" class="ui-input" aria-label="Custom lifestage">
                            </div>
                        </div>
                        <div class="ui-field">
                            <label for="np_health">Health needs</label>
                            <div class="np-pair">
                                <select id="np_health" name="health_needs_category" class="ui-select">
                                    <option value="">Select health need</option>
                                    <?php foreach ($filter_groups['Health Needs'] as $o) echo '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; ?>
                                </select>
                                <input type="text" name="health_needs_custom" placeholder="or add a new one" class="ui-input" aria-label="Custom health need">
                            </div>
                        </div>
                        <div class="ui-field">
                            <label for="np_brand">Brand</label>
                            <div class="np-pair">
                                <select id="np_brand" name="brand_category" class="ui-select">
                                    <option value="">Select brand</option>
                                    <?php foreach ($filter_groups['Brand'] as $o) echo '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; ?>
                                </select>
                                <input type="text" name="brand_custom" placeholder="or add a new one" class="ui-input" aria-label="Custom brand">
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="ui-fieldset">
                    <legend>Image &amp; description</legend>
                    <div class="ui-form-grid">
                    <div class="ui-field span-2">
                        <label for="np_image_url">Image URL</label>
                        <input type="text" id="np_image_url" name="image_url" placeholder="https://" class="ui-input">
                    </div>
                        <div class="ui-field span-2">
                            <label for="np_desc">Description</label>
                            <textarea id="np_desc" name="description" rows="3" class="ui-textarea"></textarea>
                        </div>
                    </div>
                </fieldset>

                <div class="ui-form-actions">
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Save New Product</button>
                </div>
            </form>
        </details>

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
                    <?php $product_variants = get_product_variants((int)$p['id']); ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <img src="<?php echo $p['image_url'] ?: 'https://placehold.co/150'; ?>" onerror="this.src='https://placehold.co/150'">
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
                            
                            <?php if (!empty($product_variants)): ?>
                                <div class="product-variants">
                                    <div class="product-variants-label">Flavors & Variants</div>
                                    <div class="carousel-container" data-product-id="<?php echo $p['id']; ?>">
                                        <div class="carousel-wrapper" id="carousel-<?php echo $p['id']; ?>">
                                            <?php foreach ($product_variants as $idx => $variant): ?>
                                                <div class="carousel-item">
                                                    <?php if (!empty($variant['image_url'])): ?>
                                                        <img src="<?php echo $variant['image_url']; ?>" alt="<?php echo htmlspecialchars($variant['size']); ?>" onerror="this.src='https://placehold.co/80'">
                                                    <?php else: ?>
                                                        <img src="https://placehold.co/80" alt="No image">
                                                    <?php endif; ?>
                                                    <div class="carousel-item-info"><?php echo htmlspecialchars($variant['size']); ?></div>
                                                    <div class="carousel-item-details">PHP <?php echo number_format((float)$variant['price'], 2); ?> • <span style="<?php echo ((int)($variant['stock'] ?? 0) > 0) ? 'color:#166534;' : 'color:#991b1b;'; ?>"><?php echo (int)($variant['stock'] ?? 0); ?> left</span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($product_variants) > 1): ?>
                                            <div class="carousel-nav">
                                                <button type="button" class="carousel-btn" onclick="carouselSlide(<?php echo $p['id']; ?>, -1)">❮</button>
                                                <button type="button" class="carousel-btn" onclick="carouselSlide(<?php echo $p['id']; ?>, 1)">❯</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (count($product_variants) > 1): ?>
                                        <div class="carousel-dots" id="dots-<?php echo $p['id']; ?>">
                                            <?php foreach ($product_variants as $idx => $variant): ?>
                                                <div class="dot <?php echo $idx === 0 ? 'active' : ''; ?>" onclick="carouselGoTo(<?php echo $p['id']; ?>, <?php echo $idx; ?>)"></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="product-actions">
                                <button onclick='openModal("edit", <?php echo $p["id"]; ?>, <?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)'>Edit</button>
                                <button onclick="deleteProduct(<?php echo $p['id']; ?>)" class="btn-delete">Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav class="pg-pagination" aria-label="Products pagination">
            <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars(products_page_url($page - 1)); ?>">&laquo; Prev</a>
            <?php else: ?>
                <span class="pg-disabled">&laquo; Prev</span>
            <?php endif; ?>

            <?php
            $pg_window = 2;
            for ($i = 1; $i <= $total_pages; $i++):
                if ($i === 1 || $i === $total_pages || ($i >= $page - $pg_window && $i <= $page + $pg_window)):
            ?>
                <?php if ($i === $page): ?>
                    <span class="pg-current" aria-current="page"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars(products_page_url($i)); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php elseif ($i === $page - $pg_window - 1 || $i === $page + $pg_window + 1): ?>
                <span class="pg-ellipsis">&hellip;</span>
            <?php endif; endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?php echo htmlspecialchars(products_page_url($page + 1)); ?>">Next &raquo;</a>
            <?php else: ?>
                <span class="pg-disabled">Next &raquo;</span>
            <?php endif; ?>
        </nav>
        <p class="pg-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &middot; <?php echo number_format($total_filtered_products); ?> product<?php echo $total_filtered_products === 1 ? '' : 's'; ?></p>
        <?php endif; ?>
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
                        <label>Custom Lifestage</label>
                        <input type="text" name="lifestage_custom" id="cat_lifestage_custom" placeholder="Type custom lifestage...">
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
                        <label>Custom Health Need</label>
                        <input type="text" name="health_needs_custom" id="cat_health_custom" placeholder="Type custom health need...">
                    </div>
                    <div class="form-group">
                        <label>Brand</label>
                        <select name="brand_category" id="cat_brand">
                            <option value="">None</option>
                            <?php foreach($filter_groups['Brand'] as $o) echo "<option value='$o'>$o</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom Brand</label>
                        <input type="text" name="brand_custom" id="cat_brand_custom" placeholder="Type custom brand...">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description"></textarea>
                </div>

                <!-- Variants Section -->
                <div class="variants-container">
                    <label style="font-weight: bold; color: #4a3e94;">Flavors & Variants</label>
                    <div id="variants-list">
                        <!-- Variant rows will be injected here by JS -->
                    </div>
                    <button type="button" id="add-variant-btn" onclick="addVariantRow()">+ Add Flavor / Size</button>
                    <p style="font-size: 0.75rem; color: #666;">
                        Add product variations such as Chicken, Tuna, Beef, or 340G / 1.5KG with their own price and stock.
                        The 'Stock' and 'Price' fields above will be used if no variants are added.
                    </p>
                </div>


                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Price</label>
                        <input type="number" step="0.01" name="price" id="price" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Weight (kg)</label>
                        <input type="number" step="0.001" name="weight_kg" id="weight_kg" placeholder="0.000">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Stock</label>
                        <input type="number" name="stock" id="stock" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Low Stock Threshold</label>
                        <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" title="Admin gets alerted by email once stock falls to/below this number">
                    </div>
                </div>
                <p style="font-size: 0.75rem; color: #666; margin-top: -8px;">
                    Weight is used to report kilograms purchased in the customer's loyalty history — leave as 0 if not applicable.
                </p>

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
        const allProductsWithVariants = <?php
            $products_with_variants = [];
            foreach ($products as $p) {
                $p['variants'] = get_product_variants($p['id']);
                $products_with_variants[] = $p;
            }
            echo json_encode($products_with_variants);
        ?>;

        function addVariantRow(size = '', price = '', stock = '', image_url = '', weight_kg = '') {
            const list = document.getElementById('variants-list');
            const row = document.createElement('div');
            row.className = 'variant-row';
            row.innerHTML = `
                <div class="variant-row-group">
                    <label>Flavor/Size</label>
                    <input type="text" name="variant_size[]" placeholder="e.g., 1.5KG" value="${size}">
                </div>
                <div class="variant-row-group">
                    <label>Price</label>
                    <input type="number" step="0.01" name="variant_price[]" placeholder="0.00" value="${price}">
                </div>
                <div class="variant-row-group">
                    <label>Weight (kg)</label>
                    <input type="number" step="0.001" name="variant_weight_kg[]" placeholder="0.000" value="${weight_kg}">
                </div>
                <div class="variant-row-group">
                    <label>Stock</label>
                    <input type="number" name="variant_stock[]" placeholder="0" value="${stock}">
                </div>
                <div class="variant-row-group">
                    <label>Image URL</label>
                    <input type="text" name="variant_image_url[]" placeholder="https://..." value="${image_url}">
                </div>
                <button type="button" class="btn-remove-variant" onclick="this.parentElement.remove()">×</button>
            `;
            list.appendChild(row);
        }

        function clearVariants() {
            document.getElementById('variants-list').innerHTML = '';
        }


        function openModal(mode, id = null, product = null) {
            const form = document.getElementById('productForm');
            form.reset();
            
            if(mode === 'add') {
                document.getElementById('modalTitle').innerText = "Add Product";
                document.getElementById('formAction').value = "add";
            } else {
                const fullProductData = allProductsWithVariants.find(p => p.id == id);
                if (!fullProductData) {
                    alert('Product data not found!');
                    return;
                }
                product = fullProductData;
                document.getElementById('modalTitle').innerText = "Edit Product";
                document.getElementById('formAction').value = "update";
                document.getElementById('productId').value = product.id;
                document.getElementById('sku').value = product.sku;
                document.getElementById('name').value = product.name;
                document.getElementById('description').value = product.description;
                document.getElementById('price').value = product.price;
                document.getElementById('weight_kg').value = product.weight_kg;
                document.getElementById('stock').value = product.stock;
                document.getElementById('low_stock_threshold').value = product.low_stock_threshold;
                document.getElementById('image_url').value = product.image_url;

                // Populate variants
                clearVariants();
                if (product.variants && product.variants.length > 0) {
                    product.variants.forEach(v => addVariantRow(v.size, v.price, v.stock, v.image_url, v.weight_kg));
                }

                // Handle mapping the comma-separated string back to dropdowns
                if(product.category) {
                    const cats = product.category.split(',').map(s => s.trim());
                    
                    // Helper to auto-select dropdowns, and put unmatched items in custom fields
                    Object.keys(filterGroups).forEach(groupKey => {
                        const selectId = groupKey === 'Product Category' ? 'cat_product' :
                                         groupKey === 'Lifestage' ? 'cat_lifestage' : 
                                         groupKey === 'Food Type' ? 'cat_foodtype' :
                                         groupKey === 'Health Needs' ? 'cat_health' : 
                                         groupKey === 'Brand' ? 'cat_brand' : null;
                        
                        const selectElement = selectId ? document.getElementById(selectId) : null;
                        const match = cats.find(c => filterGroups[groupKey].includes(c));
                        if(match && selectElement) selectElement.value = match;
                        
                        // If no match found in dropdown, put it in the corresponding custom field
                        if (!match && groupKey === 'Lifestage') {
                            const customVal = cats.find(c => !filterGroups[groupKey].includes(c));
                            if (customVal) {
                                document.getElementById('cat_lifestage_custom').value = customVal;
                            }
                        }
                        if (!match && groupKey === 'Health Needs') {
                            const customVal = cats.find(c => !filterGroups[groupKey].includes(c));
                            if (customVal) {
                                document.getElementById('cat_health_custom').value = customVal;
                            }
                        }
                        if (!match && groupKey === 'Brand') {
                            const customVal = cats.find(c => !filterGroups[groupKey].includes(c));
                            if (customVal) {
                                document.getElementById('cat_brand_custom').value = customVal;
                            }
                        }
                    });
                }
            }
            document.getElementById('productModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('productModal').classList.remove('active');
            clearVariants();
        }

        function deleteProduct(id) {
            if(confirm("Delete this product?")) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Carousel functionality
        const carouselStates = {};

        function carouselSlide(productId, direction) {
            if (!carouselStates[productId]) {
                carouselStates[productId] = { currentIndex: 0 };
            }
            const carousel = document.getElementById(`carousel-${productId}`);
            const items = carousel.querySelectorAll('.carousel-item');
            const totalItems = items.length;
            
            carouselStates[productId].currentIndex += direction;
            if (carouselStates[productId].currentIndex >= totalItems) {
                carouselStates[productId].currentIndex = 0;
            } else if (carouselStates[productId].currentIndex < 0) {
                carouselStates[productId].currentIndex = totalItems - 1;
            }
            
            updateCarouselDisplay(productId);
        }

        function carouselGoTo(productId, index) {
            if (!carouselStates[productId]) {
                carouselStates[productId] = { currentIndex: 0 };
            }
            carouselStates[productId].currentIndex = index;
            updateCarouselDisplay(productId);
        }

        function updateCarouselDisplay(productId) {
            const carousel = document.getElementById(`carousel-${productId}`);
            const items = carousel.querySelectorAll('.carousel-item');
            const index = carouselStates[productId].currentIndex;
            const offset = -index * 100;
            carousel.style.transform = `translateX(${offset}%)`;
            
            // Update dots
            const dots = document.querySelectorAll(`#dots-${productId} .dot`);
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });
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
