<?php
require_once __DIR__ . '/../../app/helpers/session_helper.php';
app_session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/upload_helper.php';
require_once __DIR__ . '/../shared/inventory_view.php'; // Inventory view: Add / Adjust / History per product or variant
require_once __DIR__ . '/../../app/helpers/sku_helper.php'; // automatic SKUs: ABC-001

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

// Optional expiration date (migration; same column ensure_customer_tables() adds).
mysqli_query($conn, "ALTER TABLE tbl_product_inventory ADD COLUMN IF NOT EXISTS expiry_date DATE NULL DEFAULT NULL AFTER stock");

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
inventory_handle_history($conn); // GET ?stock_history=ID -> JSON for the History dialog
// One time only: every existing product gets an ABC-001 SKU (app/helpers/sku_helper.php).
if (($sku_renumbered = sku_renumber_all($conn, (int) $payload['user_id'])) > 0 && empty($_SESSION['message'])) {
    $_SESSION['message'] = "All product SKUs now use the new format (e.g. FRI-001). $sku_renumbered SKUs were updated; the old and new SKUs are listed in Activity Logs.";
    $_SESSION['message_type'] = 'success';
}
if (isset($_GET['sku_preview'])) { // GET ?sku_preview=<name> -> next SKU for the New Product form (preview only)
    header('Content-Type: application/json');
    echo json_encode(sku_preview($conn, mb_substr((string) $_GET['sku_preview'], 0, 255)));
    exit();
}

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

/**
 * Auto-generated SKU for a new product: first three letters of the name + a 4-digit number per prefix
 * (Amoxyclyn -> AMO-001, the next Amoxyclyn -> AMO-002). See app/helpers/sku_helper.php: next number after
 * the highest one in use, error at 999. The UNIQUE KEY on tbl_product_inventory.sku is the final guard.
 * @throws SkuLimitException
 */
function generate_product_sku(mysqli $conn, string $name): string
{
    return sku_generate($conn, $name);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)$payload['user_id'];
    $message = '';
    $error = '';
    inventory_handle_post($conn, $user_id); // action=stock_move (Inventory view) - redirects

    if ($action === 'add' || $action === 'update') {
        // New products get an auto-generated SKU (ABC-001) from the name, made here on the server - whatever
        // the form sends is ignored. An existing product always keeps its SKU, even when it is renamed, so
        // inventory, orders and history that refer to it are never affected.
        $name = trim($_POST['name']);
        if ($action === 'add') {
            try {
                $sku = generate_product_sku($conn, $name);
            } catch (SkuLimitException $e) {
                $_SESSION['message'] = $e->getMessage();
                $_SESSION['message_type'] = 'error';
                header('Location: products.php');
                exit();
            }
        } else {
            $sku_stmt = $conn->prepare("SELECT sku FROM tbl_product_inventory WHERE id = ?");
            $sku_id = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);
            $sku_stmt->bind_param('i', $sku_id);
            $sku_stmt->execute();
            $sku = (string)($sku_stmt->get_result()->fetch_row()[0] ?? '');
            $sku_stmt->close();
        }
        $description = trim($_POST['description']);
        if ($description === '') {
            // Left empty: write one automatically (AI, or a template) - app/helpers/product_description_helper.php
            require_once __DIR__ . '/../../app/helpers/product_description_helper.php';
            $description = product_description_generate($_POST)['text'];
        }
        $price = (float)$_POST['price'];
        $stock = (int)$_POST['stock'];
        $weight_kg = (float)($_POST['weight_kg'] ?? 0);
        $low_stock_threshold = max(0, (int)($_POST['low_stock_threshold'] ?? 10));
        $expiry_raw = trim((string)($_POST['expiry_date'] ?? ''));
        $expiry_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_raw) && strtotime($expiry_raw) ? $expiry_raw : null; // blank = no expiration date

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
        // An uploaded file (if any) wins over the pasted URL - the URL field
        // stays pre-filled with the current image on Edit, so without this
        // check a freshly uploaded picture would be silently ignored.
        if (isset($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = save_uploaded_image($_FILES['image_file'], __DIR__ . '/../../uploads/products/', '/uploads/products/', 'product_', 5 * 1024 * 1024);
            if ($upload['ok']) {
                $image_url = $upload['url'];
            } else {
                $_SESSION['message'] = $upload['error'];
                $_SESSION['message_type'] = 'error';
                header('Location: products.php');
                exit();
            }
        }

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
                $stmt = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, weight_kg, stock, expiry_date, category, image_url, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssddisssi", $sku, $name, $description, $price, $weight_kg, $stock, $expiry_date, $category, $image_url, $low_stock_threshold);
                if ($stmt->execute()) {
                    $message = "Product added successfully! SKU: $sku";
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

                $stmt = $conn->prepare("UPDATE tbl_product_inventory SET sku=?, name=?, description=?, price=?, weight_kg=?, stock=?, expiry_date=?, category=?, image_url=?, low_stock_threshold=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("sssddisssii", $sku, $name, $description, $price, $weight_kg, $stock, $expiry_date, $category, $image_url, $low_stock_threshold, $id);
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
                        if (($old_data['expiry_date'] ?? null) !== $expiry_date) $changes[] = "Expiration date from " . ($old_data['expiry_date'] ?: 'none') . " to " . ($expiry_date ?: 'none');
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
    } elseif ($action === 'set_stock') {
        // Quick stock edit from a product card: sets tbl_product_inventory.stock directly.
        $id = (int)($_POST['product_id'] ?? 0);
        $new_stock = max(0, (int)($_POST['stock'] ?? 0));
        $return_qs = preg_replace('/[^A-Za-z0-9_\-\.%&=\[\]+]/', '', (string)($_POST['return_qs'] ?? ''));

        $old_stmt = $conn->prepare("SELECT name, stock FROM tbl_product_inventory WHERE id = ?");
        $old_stmt->bind_param('i', $id);
        $old_stmt->execute();
        $old_data = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        if (!$old_data) {
            $_SESSION['message'] = 'Product not found.';
            $_SESSION['message_type'] = 'error';
        } elseif ((int)$old_data['stock'] === $new_stock) {
            $_SESSION['message'] = "Stock for \"{$old_data['name']}\" is already $new_stock.";
            $_SESSION['message_type'] = 'success';
        } else {
            $stmt = $conn->prepare("UPDATE tbl_product_inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $new_stock, $id);
            $stmt->execute();
            $stmt->close();
            evaluate_stock_alert($conn, 'tbl_product_inventory', $id);
            inventory_log($conn, $user_id, $id, 0, (int)$old_data['stock'], $new_stock, 'card_edit');

            $log_action = "Updated Stock";
            $log_details = "User changed stock of {$old_data['name']} from {$old_data['stock']} to $new_stock.";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
            $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
            $log_stmt->execute();
            $log_stmt->close();

            $_SESSION['message'] = "Stock for \"{$old_data['name']}\" updated: {$old_data['stock']} → $new_stock.";
            $_SESSION['message_type'] = 'success';
        }
        header('Location: products.php' . ($return_qs !== '' ? '?' . $return_qs : ''));
        exit();
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
// Calculate Inventory Summary Stats (product id sets, so each summary card can
// also act as a filter via ?stock=out|critical|low|expiry)
$out_of_stock_ids = [];
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
        $out_of_stock_ids[$product_id] = true;
    }
}
$out_of_stock_count = count($out_of_stock_ids);
$total_products = count($all_inventory);
$expired_count = $expiring_soon_count = 0;
$expiry_ids = [];
foreach ($all_inventory as $item) {
    $state = product_expiry_status($item['expiry_date'] ?? null)['state'];
    if ($state === 'expired') { $expired_count++; } elseif ($state === 'soon') { $expiring_soon_count++; }
    if ($state === 'expired' || $state === 'soon') { $expiry_ids[(int)$item['id']] = true; }
}

// Inventory Alerts (Low Stock Monitoring) - real counts from the event-based
// stock_alert_level column that evaluate_stock_alert() keeps in sync on every
// deduction/restore. Counts distinct PRODUCTS that have at least one row
// (base stock or any variant) currently sitting in that band.
$alert_ids = ['low' => [], 'critical' => []];
$alert_res = mysqli_query($conn,
    "SELECT DISTINCT p.id, p.stock_alert_level AS lvl_p, v.stock_alert_level AS lvl_v
     FROM tbl_product_inventory p
     LEFT JOIN tbl_product_variants v ON v.product_id = p.id
     WHERE p.stock_alert_level IN ('low', 'critical') OR v.stock_alert_level IN ('low', 'critical')"
);
while ($alert_res && ($row = mysqli_fetch_assoc($alert_res))) {
    foreach (['lvl_p', 'lvl_v'] as $k) {
        if (isset($alert_ids[(string)$row[$k]])) { $alert_ids[$row[$k]][(int)$row['id']] = true; }
    }
}
$low_stock_count = count($alert_ids['low']);
$critical_stock_count = count($alert_ids['critical']);

$stock_filters = [
    'out' => ['label' => 'Out of Stock', 'ids' => $out_of_stock_ids],
    'critical' => ['label' => 'Critical Stock', 'ids' => $alert_ids['critical']],
    'low' => ['label' => 'Low Stock', 'ids' => $alert_ids['low']],
    'expiry' => ['label' => 'Expired / Expiring Soon', 'ids' => $expiry_ids],
];
$stock_filter = $_GET['stock'] ?? '';
if (!isset($stock_filters[$stock_filter])) {
    $stock_filter = '';
}
if ($stock_filter !== '') {
    $wanted_ids = $stock_filters[$stock_filter]['ids'];
    $products = array_filter($products, function($p) use ($wanted_ids) {
        return isset($wanted_ids[(int)$p['id']]);
    });
}
$products = array_values($products); // re-index after array_filter so array_slice below works by position
$inventory_view = ($_GET['view'] ?? '') === 'inventory';

// --- Pagination ---
// $products is already the fully filtered/searched set (filtering happens in
// PHP above, not SQL), so pagination here just slices that array - no extra
// query needed. $products stays the full filtered set (still used for the
// "Total: N" filter count); $products_page is what actually gets rendered.
$per_page = 8;
$total_filtered = count($products);
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;
$products_page = array_slice($products, $offset, $per_page);
$showing_from = $total_filtered > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_filtered);

/** Builds a products.php URL for a given page number, preserving the current filters/search. */
function products_page_url(int $target_page): string
{
    $params = $_GET;
    $params['page'] = $target_page;
    $qs = http_build_query($params);
    return 'products.php' . ($qs !== '' ? '?' . $qs : '');
}

// Windowed page-number list with ellipsis (first, last, and a few around the
// current page) so the nav stays a fixed width no matter how many pages exist.
$page_window = 2;
$page_numbers = [];
for ($i = 1; $i <= $total_pages; $i++) {
    if ($i === 1 || $i === $total_pages || ($i >= $page - $page_window && $i <= $page + $page_window)) {
        $page_numbers[] = $i;
    } elseif (end($page_numbers) !== '...') {
        $page_numbers[] = '...';
    }
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$user_id = (int)$payload['user_id'];
$unread_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .admin-shop-container { display: flex; gap: 20px; padding: 20px; }
        .admin-filters { width: 250px; background: var(--surface); padding: 20px; border-radius: 10px; border: 1px solid var(--border); height: fit-content; }
        .product-grid { flex: 1; min-width: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr)); gap: 20px; align-content: start; }
        @media (max-width: 900px) { .admin-shop-container { flex-direction: column; padding: 12px 0; } .admin-filters { width: 100%; } }
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
        .expiry-soon { background: var(--warning-soft); color: var(--warning); }
        .expiry-ok { background: var(--surface-2); color: var(--muted); }
        .product-info .stock-badge { margin: 0 4px 4px 0; }
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
        .dot.active { background: var(--dk-primary, #4a3e94); }
        .inventory-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .variants-container { margin-top: 15px; border-top: 1px solid var(--border); padding-top: 15px; }
        .variant-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1.2fr 0.08fr; gap: 8px; margin-bottom: 10px; align-items: end; }
        .variant-row input { padding: 8px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.85rem; }
        .variant-row label { font-size: 0.75rem; font-weight: 600; color: var(--link); display: block; margin-bottom: 3px; }
        .variant-row-group { display: flex; flex-direction: column; }
        #add-variant-btn { background: var(--primary-soft); color: var(--link); border: 1px solid #c7d2fe; font-size: 0.8rem; font-weight: bold; }
        .btn-remove-variant { background: var(--danger-soft); color: var(--danger); border: 1px solid #fca5a5; padding: 6px 8px; cursor: pointer; border-radius: 4px; font-weight: bold; }

        .summary-card { background: var(--surface); padding: 15px 20px; border-radius: 12px; min-width: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border); display: flex; align-items: center; gap: 15px; }
        a.summary-link { color: inherit; text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s, border-color .15s; }
        a.summary-link:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0,0,0,0.08); border-color: var(--primary, #6366f1); }
        a.summary-link.is-active { border: 2px solid var(--primary, #6366f1); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .summary-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .icon-blue { background: var(--primary-soft); color: var(--link); }
        .icon-red { background: var(--dk-danger-soft, #fff1f2); color: var(--danger); }
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

        .products-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .products-pagination-info { font-size: 0.85rem; color: var(--muted); white-space: nowrap; }
        .products-pagination-nav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; max-width: 100%; }
        .page-numbers { display: flex; align-items: center; gap: 4px; flex-wrap: nowrap; overflow-x: auto; max-width: 100%; padding-bottom: 2px; }
        .page-btn, .page-num {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
            border: 1px solid var(--border); background: var(--surface); color: var(--text);
            text-decoration: none; font-size: 0.82rem; font-weight: 600; flex-shrink: 0;
            transition: background .15s, color .15s, border-color .15s;
        }
        a.page-btn:hover, a.page-num:hover { background: var(--surface-2); border-color: var(--dk-primary, #4a3e94); }
        .page-num.active { background: var(--dk-primary, #4a3e94); border-color: var(--dk-primary, #4a3e94); color: var(--dk-on-primary, #fff); }
        .page-btn.disabled { opacity: 0.4; cursor: not-allowed; }
        .page-ellipsis { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; color: var(--muted); font-size: 0.82rem; flex-shrink: 0; }
        @media (max-width: 640px) {
            .products-pagination { justify-content: center; text-align: center; }
            .products-pagination-nav { width: 100%; justify-content: center; }
            .page-btn-label { display: none; }
        }
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
        /* Colour, so the Add New Product card stands out from the plain white cards (theme tokens, works in dark mode) */
        .np-card { border: 1px solid var(--primary); border-left: 6px solid var(--primary); overflow: hidden; box-shadow: 0 6px 18px rgba(79, 70, 229, .12); }
        .np-card .np-summary { background: var(--primary-soft); }
        .np-card .np-summary .ui-card-title { color: var(--primary); font-size: 1.08rem; }
        .np-card .np-summary .ui-card-title i { color: var(--primary); }
        .np-card .np-summary:hover { filter: brightness(.98); }
        .np-card[open] .np-summary { border-bottom-color: var(--primary); }
        .np-card > .ui-form { background: var(--surface-2); }
        .np-card .ui-fieldset { background: var(--surface); border: 1px solid var(--border); border-top: 3px solid var(--primary); border-radius: 12px; padding: 14px 16px 16px; margin-bottom: 16px; }
        .np-card .ui-fieldset legend { padding: 3px 12px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: .85rem; }
        .np-card .ui-field label { color: var(--text-2); font-weight: 600; }
        .np-card .ui-input:focus, .np-card .ui-select:focus, .np-card .ui-textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        .np-card .ui-form-actions .ui-btn-primary { padding-left: 22px; padding-right: 22px; box-shadow: 0 4px 12px rgba(79, 70, 229, .3); }
        /* Edit Product window: same colours as the Add New Product card, bigger and clearer text boxes */
        #productModal .modal-content { padding: 0; width: 780px; border: 1px solid var(--primary); border-top: 6px solid var(--primary); border-radius: 16px; box-shadow: 0 20px 50px rgba(15, 23, 42, .3); }
        #productModal .pm-head { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 22px; background: var(--primary-soft); border-bottom: 1px solid var(--primary); }
        #productModal #modalTitle { margin: 0; font-size: 1.2rem; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        #productModal #modalTitle i { color: var(--primary); }
        #productModal .pm-x { border: 0; background: var(--surface); color: var(--muted); width: 34px; height: 34px; border-radius: 50%; cursor: pointer; font-size: 1rem; }
        #productModal .pm-x:hover { color: var(--danger); }
        #productModal form { padding: 18px 22px 20px; background: var(--surface-2); }
        #productModal .form-group { margin-bottom: 14px; }
        #productModal .form-group label,
        #productModal .variant-row label { display: block; margin-bottom: 6px; font-size: .82rem; font-weight: 700; color: var(--text-2); letter-spacing: .01em; }
        #productModal input[type="text"], #productModal input[type="number"], #productModal input[type="date"],
        #productModal select, #productModal textarea {
            width: 100%; box-sizing: border-box; min-height: 42px; padding: 9px 12px; font: inherit; font-size: .92rem;
            color: var(--text); background: var(--surface); border: 1.5px solid var(--border); border-radius: 10px;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        #productModal textarea { min-height: 110px; resize: vertical; line-height: 1.5; }
        #productModal input::placeholder, #productModal textarea::placeholder { color: var(--muted); opacity: .8; }
        #productModal input:hover, #productModal select:hover, #productModal textarea:hover { border-color: var(--primary); }
        #productModal input:focus, #productModal select:focus, #productModal textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        #productModal input[readonly] { background: var(--surface-2); color: var(--muted); border-style: dashed; cursor: not-allowed; }
        #productModal input[type="file"] { width: 100%; padding: 8px; border: 1.5px dashed var(--border); border-radius: 10px; background: var(--surface); }
        #productModal .pm-section { background: var(--surface); border: 1px solid var(--border); border-top: 3px solid var(--primary); border-radius: 12px; padding: 14px 16px 4px; margin-bottom: 16px; }
        #productModal .pm-section.pm-variants { padding-bottom: 12px; }
        #productModal .pm-section-title { display: inline-block; margin: 0 0 12px; padding: 3px 12px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: .85rem; }
        #productModal .dropdown-grid { background: transparent; border: 0; padding: 0; gap: 4px 14px; }
        #productModal .pm-row { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
        #productModal .pm-note { margin: -4px 0 12px; font-size: .78rem; color: var(--muted); }
        #productModal .variant-row { background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px; grid-template-columns: 1.3fr 1fr 1fr 1fr 1.4fr auto; }
        #productModal .variant-row input { min-height: 38px; padding: 7px 10px; }
        #productModal .btn-remove-variant { height: 38px; width: 38px; border-radius: 10px; }
        #productModal #add-variant-btn { margin-top: 4px; padding: 9px 14px; border-radius: 10px; border: 1.5px dashed var(--primary); background: var(--primary-soft); color: var(--primary); cursor: pointer; }
        #productModal .pm-actions { position: sticky; bottom: 0; display: flex; justify-content: flex-end; gap: 10px; margin: 6px -22px -20px; padding: 14px 22px; background: var(--surface); border-top: 1px solid var(--border); }
        #productModal .pm-actions .ui-btn-primary { padding-left: 22px; padding-right: 22px; box-shadow: 0 4px 12px rgba(79, 70, 229, .3); }
        @media (max-width: 760px) {
            #productModal .pm-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            #productModal .dropdown-grid { grid-template-columns: minmax(0, 1fr); }
            #productModal .variant-row { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 1100px) { .np-grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .np-grid-4 { grid-template-columns: minmax(0, 1fr); } .np-span-2 { grid-column: auto; } .np-pair { grid-template-columns: minmax(0, 1fr); } }
        /* Inventory batches on each product card */
        .product-batches { margin-top: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); font-size: 0.8rem; text-align: left; }
        .product-batches summary { cursor: pointer; padding: 7px 10px; font-weight: 700; color: var(--text-2); list-style-position: inside; }
        .product-batches ul { list-style: none; margin: 0; padding: 0 10px; display: grid; gap: 6px; }
        .product-batches li { display: flex; justify-content: space-between; gap: 8px; padding: 6px 8px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); }
        .product-batches li > div:first-child { display: grid; gap: 2px; min-width: 0; }
        .product-batches li small { color: var(--muted); }
        .product-batches .pb-var { color: var(--text-2); }
        .product-batches .pb-qty { text-align: right; white-space: nowrap; color: var(--muted); }
        .product-batches .pb-qty b { color: var(--text); font-size: 0.9rem; }
        .product-batches .pb-qty em { display: block; font-style: normal; color: var(--danger); font-weight: 700; }
        .product-batches li.pb-soon { border-color: var(--warning); }
        .product-batches li.pb-off { opacity: .6; }
        .product-batches .pb-empty, .product-batches .pb-note { margin: 6px 10px; color: var(--muted); }
        .product-batches .pb-links { display: flex; gap: 12px; flex-wrap: wrap; padding: 8px 10px; }
        .product-batches .pb-links a { color: var(--link); font-weight: 700; text-decoration: none; }

        /* Product cards (system colour only) */
        .product-card { padding: 0; border-radius: 14px; box-shadow: 0 2px 8px rgba(15, 23, 42, .06); transition: transform .15s, box-shadow .15s, border-color .15s; }
        .product-card:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: 0 10px 22px rgba(79, 70, 229, .12); }
        .product-image-container { height: 170px; padding: 12px; background: var(--primary-soft); border-bottom: 1px solid var(--border); }
        .product-image-container img { border-radius: 8px; }
        .product-info { padding: 12px 14px 14px; gap: 6px; min-width: 0; }
        .pc-top { display: flex; align-items: center; gap: 6px; min-width: 0; }
        .pc-sku { flex-shrink: 0; padding: 2px 8px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); font-size: .7rem; font-weight: 800; letter-spacing: .03em; font-family: ui-monospace, Menlo, Consolas, monospace; }
        .pc-cat { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-size: .72rem; }
        .pc-name { margin: 2px 0 0; font-size: .95rem; line-height: 1.3; color: var(--text); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.6em; }
        .pc-price { color: var(--primary); font-size: 1.05rem; font-weight: 800; }
        .pc-badges { display: flex; flex-wrap: wrap; gap: 4px; }
        .product-info .pc-badges .stock-badge { margin: 0; }
        .product-variants { margin-top: 4px; }
        .product-actions { gap: 8px; padding-top: 12px; }
        .product-actions button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 10px; border-radius: 10px; font-weight: 700; font-size: .85rem; transition: background .15s, color .15s, border-color .15s; }
        .product-actions .btn-edit { background: var(--primary-soft); color: var(--primary); border-color: var(--primary); }
        .product-actions .btn-edit:hover { background: var(--primary); color: #fff; }
        .product-actions .btn-delete { color: var(--danger); border-color: var(--border); background: var(--surface); }
        .product-actions .btn-delete:hover { background: var(--danger-soft); border-color: var(--danger); }

        /* Batches dropdown: stays inside the card (text wraps, long lists scroll) */
        .product-batches { margin-top: 6px; min-width: 0; overflow: hidden; }
        .product-batches summary { display: flex; align-items: center; gap: 8px; list-style: none; padding: 8px 10px; color: var(--primary); }
        .product-batches summary::-webkit-details-marker { display: none; }
        .product-batches summary > span:first-child { flex: 1; }
        .product-batches .pb-count { min-width: 22px; padding: 1px 7px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); font-size: .72rem; text-align: center; }
        .product-batches .pb-chev { font-size: .7rem; color: var(--muted); transition: transform .15s; }
        .product-batches[open] { border-color: var(--primary); background: var(--surface); }
        .product-batches[open] .pb-chev { transform: rotate(180deg); }
        .product-batches[open] summary { border-bottom: 1px solid var(--border); }
        .product-batches ul { max-height: 210px; overflow-y: auto; padding: 8px 8px 2px; }
        .product-batches li { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 6px; padding: 7px 8px; }
        .product-batches li strong { font-size: .78rem; word-break: break-all; }
        .product-batches li small { font-size: .7rem; line-height: 1.35; overflow-wrap: anywhere; }
        .product-batches .pb-var { font-size: .72rem; overflow-wrap: anywhere; }
        .product-batches .pb-qty { font-size: .72rem; }
        .product-batches .pb-qty b { font-size: .85rem; }
        .product-batches .pb-note { font-size: .7rem; }
        .product-batches .pb-links { flex-direction: column; gap: 6px; padding: 6px 10px 10px; }
        .product-batches .pb-links a { font-size: .76rem; color: var(--primary); }
    </style>
    <?php echo inventory_assets(); ?>
    <style>
        .pm-views { margin-bottom: 16px; }
        .pm-views a { display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    </style>

    <style>
        .desc-label { display: flex !important; align-items: center; justify-content: space-between; gap: 8px; }
        .desc-gen { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px !important; border-radius: 999px !important; border: 1px solid var(--primary, #4f46e5) !important;
                    background: var(--primary-soft, #eef2ff) !important; color: var(--primary, #4f46e5) !important; font-size: .78rem !important; font-weight: 700; cursor: pointer; width: auto !important; }
        .desc-gen:disabled { opacity: .6; cursor: wait; }
        .desc-note { display: block; margin-top: 4px; font-size: .75rem; color: var(--muted); }
    </style>
    <script>
    // Product description from the form's own fields (product_description_api.php: Gemini, or a template).
    function generateDescription(btn) {
        var form = btn.closest('form');
        var box = form.querySelector('textarea[name="description"]');
        var name = form.querySelector('[name="name"]');
        if (!name || !name.value.trim()) { (window.toastOr || alert)('Type the product name first.', 'error'); if (name) name.focus(); return; }
        if (box.value.trim() && !confirm('Replace the current description with a generated one?')) return;
        var fd = new FormData(form);
        fd.set('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        var label = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Writing…';
        fetch(<?php echo json_encode(BASE_URL . '/modules/admin/product_description_api.php'); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error((d && d.message) || 'Could not generate a description.');
                box.value = d.data.text;
                box.dispatchEvent(new Event('input'));
                var note = box.parentNode.querySelector('.desc-note') || box.parentNode.appendChild(document.createElement('small'));
                note.className = 'desc-note';
                note.textContent = d.data.source === 'ai' ? 'Written by AI (Google Gemini) - check it and edit if needed.' : 'Written from a template (AI not available) - edit if needed.';
            })
            .catch(function (e) { (window.toastOr || alert)(e.message || 'Could not generate a description.', 'error'); })
            .then(function () { btn.disabled = false; btn.innerHTML = label; });
    }
    </script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/filters-mobile.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/filters-mobile.css') ?: 1; ?>">
</head>
<body>

    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

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
            <a href="products.php#product-list" class="summary-card summary-link<?php echo $stock_filter === '' ? ' is-active' : ''; ?>" title="Show all products">
                <div class="summary-icon icon-blue"><i class="fas fa-boxes"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Products</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #1e293b;"><?php echo $total_products; ?></div>
                </div>
            </a>
            <a href="<?php echo $stock_filter === 'out' ? 'products.php' : 'products.php?stock=out#product-list'; ?>" class="summary-card summary-link<?php echo $stock_filter === 'out' ? ' is-active' : ''; ?>" title="Show out-of-stock products">
                <div class="summary-icon icon-red"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Out of Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #ef4444;"><?php echo $out_of_stock_count; ?></div>
                </div>
            </a>
            <a href="<?php echo $stock_filter === 'critical' ? 'products.php' : 'products.php?stock=critical#product-list'; ?>" class="summary-card summary-link<?php echo $stock_filter === 'critical' ? ' is-active' : ''; ?>" title="Stock at or below the product's critical threshold (5 units by default)">
                <div class="summary-icon" style="background: #fee2e2; color: #dc2626;"><i class="fas fa-fire"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">🔴 Critical Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #dc2626;"><?php echo $critical_stock_count; ?></div>
                </div>
            </a>
            <a href="<?php echo $stock_filter === 'low' ? 'products.php' : 'products.php?stock=low#product-list'; ?>" class="summary-card summary-link<?php echo $stock_filter === 'low' ? ' is-active' : ''; ?>" title="Stock at or below the product's configured low-stock threshold">
                <div class="summary-icon" style="background: #ffedd5; color: #ea580c;"><i class="fas fa-battery-quarter"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">🟠 Low Stock</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #ea580c;"><?php echo $low_stock_count; ?></div>
                </div>
            </a>
            <a href="<?php echo $stock_filter === 'expiry' ? 'products.php' : 'products.php?stock=expiry#product-list'; ?>" class="summary-card summary-link<?php echo $stock_filter === 'expiry' ? ' is-active' : ''; ?>" title="Expired products can't be ordered by customers. Expiring soon = within 30 days.">
                <div class="summary-icon" style="background: var(--warning-soft); color: var(--warning);"><i class="fas fa-calendar-xmark"></i></div>
                <div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Expired / Expiring Soon</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--danger);"><?php echo $expired_count; ?> <span style="color: var(--warning); font-size: 1rem;">/ <?php echo $expiring_soon_count; ?></span></div>
                </div>
            </a>
        </div>

        <details class="ui-card np-card" style="margin-bottom: 24px;">
            <summary class="ui-card-head np-summary">
                <span>
                    <span class="ui-card-title"><i class="fas fa-plus-circle"></i> Add New Product</span>
                    <span class="ui-card-sub">Create a catalog item. Required fields are marked *.</span>
                </span>
                <span class="ui-btn ui-btn-primary ui-btn-sm np-toggle"><i class="fas fa-plus"></i> New product</span>
            </summary>
            <form method="POST" enctype="multipart/form-data" class="ui-card-body ui-form">
                <input type="hidden" name="action" value="add">

                <fieldset class="ui-fieldset">
                    <legend>Basic information</legend>
                    <div class="ui-form-grid np-grid-4">
                        <div class="ui-field">
                            <label for="np_sku">SKU</label>
                            <input type="text" id="np_sku" class="ui-input" value="" placeholder="Type the product name" readonly disabled title="Generated automatically from the product name when you save">
                            <small class="np-sku-hint" id="np_sku_hint" style="display:block;margin-top:4px;color:var(--muted);font-size:.78rem;">Made from the first 3 letters of the name, e.g. AMO-001.</small>
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
                        <div class="ui-field">
                            <label for="np_expiry">Expiration date</label>
                            <input type="date" id="np_expiry" name="expiry_date" class="ui-input" title="Leave blank if the product doesn't expire. Customers can't order it after this date.">
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
                    <div class="ui-field">
                        <label for="np_image_file">Upload image</label>
                        <input type="file" id="np_image_file" name="image_file" accept="image/png, image/jpeg, image/gif, image/webp" class="ui-input">
                        <p class="ui-hint">PNG, JPG, GIF or WebP from your device.</p>
                    </div>
                    <div class="ui-field">
                        <label for="np_image_url">…or image URL</label>
                        <input type="text" id="np_image_url" name="image_url" placeholder="https://" class="ui-input">
                    </div>
                        <div class="ui-field span-2">
                            <label for="np_desc" class="desc-label">Description <button type="button" class="desc-gen" onclick="generateDescription(this)" title="Write the description from the name, categories, brand, weight, price and sizes"><i class="fas fa-wand-magic-sparkles"></i> Generate</button></label>
                            <textarea id="np_desc" name="description" rows="3" class="ui-textarea" placeholder="Click Generate, type your own, or leave empty to write one automatically when you save"></textarea>
                        </div>
                    </div>
                </fieldset>

                <div class="ui-form-actions">
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Save New Product</button>
                </div>
            </form>
        </details>

        <?php
        $pm_view_qs = $_GET;
        unset($pm_view_qs['view'], $pm_view_qs['page'], $pm_view_qs['istatus']);
        $pm_cards_url = 'products.php' . ($pm_view_qs ? '?' . http_build_query($pm_view_qs) : '');
        $pm_inv_url = 'products.php?' . http_build_query(array_merge($pm_view_qs, ['view' => 'inventory']));
        ?>
        <!-- Same full-width tabs as the dashboard (ui-kit .ui-tabs-fill) -->
        <nav class="ui-tabs ui-tabs-fill pm-views" aria-label="Products view">
            <a href="<?php echo htmlspecialchars($pm_cards_url); ?>" class="<?php echo $inventory_view ? '' : 'active'; ?>"<?php echo $inventory_view ? '' : ' aria-current="page"'; ?>><i class="fas fa-th-large"></i> Product cards</a>
            <a href="<?php echo htmlspecialchars($pm_inv_url); ?>" class="<?php echo $inventory_view ? 'active' : ''; ?>"<?php echo $inventory_view ? ' aria-current="page"' : ''; ?>><i class="fas fa-clipboard-list"></i> Inventory</a>
        </nav>

        <?php if ($inventory_view): ?>
            <?php echo inventory_html($conn, $products, http_build_query(array_diff_key($_GET, ['stock_history' => 1]))); ?>
        <?php else: ?>
        <div class="admin-shop-container">
            <!-- Sidebar Filters -->
            <aside class="admin-filters fc">
                <h3 class="fc-title">Filters</h3>
                <?php $fc_sel = (string) ($selected_filters[0] ?? ''); ?>
                <button type="button" class="fc-toggle" aria-expanded="false" onclick="var a=this.parentNode;a.classList.toggle('is-open');this.setAttribute('aria-expanded',a.classList.contains('is-open'));">
                    <i class="fas fa-sliders"></i> Filters
                    <span class="fc-current<?php echo $fc_sel !== '' ? ' is-set' : ''; ?>"><?php echo $fc_sel !== '' ? htmlspecialchars($fc_sel) : 'All categories'; ?></span>
                    <i class="fas fa-chevron-down fc-chev"></i>
                </button>
                <form id="filter-form" method="GET" class="fc-body">
                    <?php if ($stock_filter !== ''): ?><input type="hidden" name="stock" value="<?php echo htmlspecialchars($stock_filter); ?>"><?php endif; ?>
                    <?php foreach($filter_groups as $title => $opts): ?>
                        <div style="margin-bottom:10px;">
                            <strong style="font-size: 0.85rem; color: #4a3e94;"><?php echo $title; ?></strong><br>
                            <?php foreach($opts as $o): ?>
                                <label style="display:block; font-size:0.8rem; cursor:pointer; margin-bottom: 2px;">
                                    <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($o); ?>" 
                                    <?php echo in_array($o, $selected_filters) ? 'checked' : ''; ?> 
                                    onchange="if(this.checked){this.form.querySelectorAll('input[name=&quot;categories[]&quot;]').forEach(function(c){if(c!==this)c.checked=false;},this);}this.form.submit()"> <?php echo htmlspecialchars($o); ?>
                                    <span style="color:#64748b;">(<?php echo $category_counts[$o] ?? 0; ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <a href="products.php" style="color:red; font-size:0.8rem;">Clear Filters</a>
                </form>
            </aside>

            <!-- Product Display -->
            <div class="product-grid" id="product-list">
                <?php if ($stock_filter !== ''): ?>
                    <div style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--primary, #6366f1); border-radius: 10px; padding: 8px 14px; font-size: 0.85rem; font-weight: 600; align-self: start;">
                        <span><i class="fas fa-filter"></i> Showing: <?php echo htmlspecialchars($stock_filters[$stock_filter]['label']); ?> (<?php echo $total_filtered; ?>)</span>
                        <a href="products.php" style="color: var(--danger, #dc2626); font-size: 0.85rem;"><i class="fas fa-times"></i> Show all products</a>
                    </div>
                    <?php if ($total_filtered === 0): ?>
                        <div style="grid-column: 1 / -1; text-align: center; color: #64748b; padding: 30px;">No products in this group right now.</div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php
                    // Inventory batches of the products on this page (one query). Batches come from
                    // Delivery > Receive Delivery; see app/helpers/inventory_batch_helper.php.
                    inv_batches_ensure_schema($conn);
                    inv_batches_reconcile_all($conn); // stock edited on this page shows up as a batch right away
                    inv_expire_due_batches($conn);
                    $batches_by_product = [];
                    $page_ids = array_map(static fn($x) => (int) $x['id'], $products_page);
                    if ($page_ids && inv_batches_ready($conn)) {
                        $b_res = $conn->query(
                            "SELECT product_id, batch_code, variant_label, source, lot_number, qty_received, qty_remaining, expiry_date, received_at, status
                             FROM tbl_inventory_batches
                             WHERE product_id IN (" . implode(',', $page_ids) . ") AND status <> 'reversed'
                               AND (qty_remaining > 0 OR status <> 'available')
                             ORDER BY status = 'available' DESC, expiry_date IS NULL, expiry_date, received_at"
                        );
                        while ($b_res && ($b = $b_res->fetch_assoc())) {
                            $batches_by_product[(int) $b['product_id']][] = $b;
                        }
                    }
                    $batch_status_labels = inv_batch_statuses();
                ?>
                <?php foreach($products_page as $p): ?>
                    <?php $product_variants = get_product_variants((int)$p['id']); ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <img src="<?php echo $p['image_url'] ?: 'https://placehold.co/150'; ?>" onerror="this.src='https://placehold.co/150'">
                        </div>
                        <div class="product-info">
                            <div class="pc-top">
                                <span class="pc-sku"><?php echo htmlspecialchars($p['sku'] ?? ''); ?></span>
                                <small class="pc-cat" title="<?php echo htmlspecialchars($p['category']); ?>"><?php echo htmlspecialchars($p['category']); ?></small>
                            </div>
                            <h4 class="pc-name" title="<?php echo htmlspecialchars($p['name']); ?>"><?php echo htmlspecialchars($p['name']); ?></h4>
                            <strong class="pc-price">PHP <?php echo number_format($p['price'], 2); ?></strong>
                            <div class="pc-badges">
                                <?php if (($p['stock'] ?? 0) <= 0): ?>
                                    <span class="stock-badge stock-out"><i class="fas fa-times-circle"></i> Out of Stock</span>
                                <?php else: ?>
                                    <span class="stock-badge stock-in"><i class="fas fa-check-circle"></i> Stock: <?php echo number_format($p['stock']); ?></span>
                                <?php endif; ?>
                                <?php $expiry = product_expiry_status($p['expiry_date'] ?? null); ?>
                                <?php if ($expiry['state'] === 'expired'): ?>
                                    <span class="stock-badge stock-out" title="Customers can no longer order this product"><i class="fas fa-ban"></i> Expired <?php echo htmlspecialchars($expiry['label']); ?></span>
                                <?php elseif ($expiry['state'] === 'soon'): ?>
                                    <span class="stock-badge expiry-soon"><i class="fas fa-hourglass-half"></i> Expires <?php echo htmlspecialchars($expiry['label']); ?> (<?php echo $expiry['days'] === 0 ? 'today' : $expiry['days'] . ' day' . ($expiry['days'] === 1 ? '' : 's'); ?>)</span>
                                <?php elseif ($expiry['state'] === 'ok'): ?>
                                    <span class="stock-badge expiry-ok"><i class="fas fa-calendar-check"></i> Expires <?php echo htmlspecialchars($expiry['label']); ?></span>
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

                            <?php $p_batches = $batches_by_product[(int) $p['id']] ?? []; ?>
                            <details class="product-batches">
                                <summary><span><i class="fas fa-layer-group"></i> Batches</span> <span class="pb-count"><?php echo count($p_batches); ?></span> <i class="fas fa-chevron-down pb-chev"></i></summary>
                                <?php if (!$p_batches): ?>
                                    <p class="pb-empty">No batches yet. New stock from a supplier becomes a batch when you receive it.</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($p_batches as $b):
                                            $b_exp = product_expiry_status($b['expiry_date']);
                                            $b_cls = $b['status'] !== 'available' ? 'pb-off' : ($b_exp['state'] === 'expired' ? 'pb-off' : ($b_exp['state'] === 'soon' ? 'pb-soon' : ''));
                                        ?>
                                            <li class="<?php echo $b_cls; ?>">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($b['batch_code'] ?: ($b['source'] === 'opening' ? 'Opening stock' : 'Batch')); ?></strong>
                                                    <?php if (!empty($b['variant_label'])): ?><span class="pb-var"><?php echo htmlspecialchars($b['variant_label']); ?></span><?php endif; ?>
                                                    <small>
                                                        <?php echo $b['expiry_date'] ? 'Exp. ' . htmlspecialchars($b_exp['label']) : 'No expiry'; ?>
                                                        <?php echo $b['lot_number'] ? ' &middot; Lot ' . htmlspecialchars($b['lot_number']) : ''; ?>
                                                        &middot; In <?php echo date('M j, Y', strtotime($b['received_at'])); ?>
                                                        <?php echo $b['source'] === 'adjustment' ? ' &middot; manual add' : ($b['source'] === 'opening' ? ' &middot; opening stock' : ''); ?>
                                                    </small>
                                                </div>
                                                <div class="pb-qty">
                                                    <b><?php echo number_format((int) $b['qty_remaining']); ?></b>/<?php echo number_format((int) $b['qty_received']); ?>
                                                    <?php if ($b['status'] !== 'available'): ?><em><?php echo htmlspecialchars($batch_status_labels[$b['status']] ?? $b['status']); ?></em><?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p class="pb-note">Sells first from the batch that expires first.</p>
                                <?php endif; ?>
                                <div class="pb-links">
                                    <a href="delivery.php#deliveries"><i class="fas fa-plus"></i> Add batch (receive delivery)</a>
                                    <a href="delivery.php#batches"><i class="fas fa-list"></i> All batches</a>
                                </div>
                            </details>

                            <div class="product-actions">
                                <button type="button" class="btn-edit" onclick='openModal("edit", <?php echo $p["id"]; ?>, <?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)'><i class="fas fa-pen"></i> Edit</button>
                                <button type="button" onclick="deleteProduct(<?php echo $p['id']; ?>)" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; /* inventory view */ ?>

        <?php if (!$inventory_view && $total_filtered > 0): ?>
            <div class="products-pagination">
                <div class="products-pagination-info">
                    Showing <?php echo number_format($showing_from); ?>&ndash;<?php echo number_format($showing_to); ?> of <?php echo number_format($total_filtered); ?> products
                </div>
                <nav class="products-pagination-nav" aria-label="Products pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?php echo htmlspecialchars(products_page_url($page - 1)); ?>">
                            <i class="fas fa-chevron-left"></i> <span class="page-btn-label">Previous</span>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled" aria-disabled="true">
                            <i class="fas fa-chevron-left"></i> <span class="page-btn-label">Previous</span>
                        </span>
                    <?php endif; ?>

                    <div class="page-numbers">
                        <?php foreach ($page_numbers as $pn): ?>
                            <?php if ($pn === '...'): ?>
                                <span class="page-ellipsis">&hellip;</span>
                            <?php elseif ((int) $pn === $page): ?>
                                <span class="page-num active" aria-current="page"><?php echo (int) $pn; ?></span>
                            <?php else: ?>
                                <a class="page-num" href="<?php echo htmlspecialchars(products_page_url((int) $pn)); ?>"><?php echo (int) $pn; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a class="page-btn" href="<?php echo htmlspecialchars(products_page_url($page + 1)); ?>">
                            <span class="page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled" aria-disabled="true">
                            <span class="page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="pm-head">
                <h2 id="modalTitle"><i class="fas fa-pen-to-square"></i> <span>Edit Product</span></h2>
                <button type="button" class="pm-x" onclick="closeModal()" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" id="productForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="product_id" id="productId">

                <div class="pm-section">
                    <div class="pm-section-title">Basic information</div>
                    <div class="dropdown-grid">
                        <div class="form-group">
                            <label for="sku">SKU</label>
                            <input type="text" name="sku" id="sku" readonly placeholder="Auto-generated on save" title="The SKU is set once and never changes, even if the product is renamed">
                        </div>
                        <div class="form-group">
                            <label for="name">Product name *</label>
                            <input type="text" name="name" id="name" required placeholder="e.g. Friskies Adult Dry Cat Food">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="desc-label" for="description">Description <button type="button" class="desc-gen" onclick="generateDescription(this)" title="Write the description from the name, categories, brand, weight, price and sizes"><i class="fas fa-wand-magic-sparkles"></i> Generate</button></label>
                        <textarea name="description" id="description" rows="4" placeholder="Click Generate, type your own, or leave empty to write one automatically when you save"></textarea>
                    </div>
                </div>

                <div class="pm-section pm-stock">
                    <div class="pm-section-title">Price, stock &amp; expiry</div>
                    <div class="pm-row">
                        <div class="form-group">
                            <label for="price">Price (PHP) *</label>
                            <input type="number" step="0.01" name="price" id="price" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="weight_kg">Weight (kg)</label>
                            <input type="number" step="0.001" name="weight_kg" id="weight_kg" placeholder="0.000">
                        </div>
                        <div class="form-group">
                            <label for="stock">Stock *</label>
                            <input type="number" name="stock" id="stock" required placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="low_stock_threshold">Low-stock alert at</label>
                            <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" placeholder="10" title="Admin gets alerted by email once stock falls to/below this number">
                        </div>
                        <div class="form-group">
                            <label for="expiry_date">Expiration date</label>
                            <input type="date" name="expiry_date" id="expiry_date" title="Leave blank if the product doesn't expire. Customers can't order it after this date.">
                        </div>
                    </div>
                    <p class="pm-note">Weight is used to report kilograms purchased in the customer's loyalty history. Leave it as 0 if it doesn't apply.</p>
                </div>

                <div class="pm-section pm-cats">
                    <div class="pm-section-title">Categories</div>
                    <div class="dropdown-grid">
                        <div class="form-group">
                            <label for="cat_product">Main category</label>
                            <select name="product_category_input" id="cat_product">
                                <option value="">None</option>
                                <?php foreach($filter_groups['Product Category'] as $o) echo "<option value='$o'>$o</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cat_foodtype">Food type</label>
                            <select name="food_type_category" id="cat_foodtype">
                                <option value="">None</option>
                                <?php foreach($filter_groups['Food Type'] as $o) echo "<option value='$o'>$o</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cat_lifestage">Lifestage</label>
                            <select name="lifestage_category" id="cat_lifestage">
                                <option value="">None</option>
                                <?php foreach($filter_groups['Lifestage'] as $o) echo "<option value='$o'>$o</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cat_lifestage_custom">…or a new lifestage</label>
                            <input type="text" name="lifestage_custom" id="cat_lifestage_custom" placeholder="Type a new lifestage">
                        </div>
                        <div class="form-group">
                            <label for="cat_health">Health needs</label>
                            <select name="health_needs_category" id="cat_health">
                                <option value="">None</option>
                                <?php foreach($filter_groups['Health Needs'] as $o) echo "<option value='$o'>$o</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cat_health_custom">…or a new health need</label>
                            <input type="text" name="health_needs_custom" id="cat_health_custom" placeholder="Type a new health need">
                        </div>
                        <div class="form-group">
                            <label for="cat_brand">Brand</label>
                            <select name="brand_category" id="cat_brand">
                                <option value="">None</option>
                                <?php foreach($filter_groups['Brand'] as $o) echo "<option value='$o'>$o</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="cat_brand_custom">…or a new brand</label>
                            <input type="text" name="brand_custom" id="cat_brand_custom" placeholder="Type a new brand">
                        </div>
                    </div>
                </div>

                <div class="pm-section pm-variants variants-container">
                    <div class="pm-section-title">Flavors &amp; variants</div>
                    <p class="pm-note">Add sizes or flavors (e.g. Chicken, Tuna, 340G, 1.5KG), each with its own price and stock. If you add none, the price and stock above are used.</p>
                    <div id="variants-list">
                        <!-- Variant rows will be injected here by JS -->
                    </div>
                    <button type="button" id="add-variant-btn" onclick="addVariantRow()"><i class="fas fa-plus"></i> Add flavor / size</button>
                </div>

                <div class="pm-section">
                    <div class="pm-section-title">Picture</div>
                    <div class="dropdown-grid">
                        <div class="form-group">
                            <label>Upload a new picture</label>
                            <input type="file" name="image_file" accept="image/png, image/jpeg, image/gif, image/webp">
                            <small style="color: var(--muted); font-size: .75rem; display: block; margin-top: 4px;">Leave empty to keep the current picture.</small>
                        </div>
                        <div class="form-group">
                            <label for="image_url">…or image URL</label>
                            <input type="text" name="image_url" id="image_url" placeholder="https://">
                        </div>
                    </div>
                </div>

                <div class="pm-actions">
                    <button type="button" class="ui-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Save Product</button>
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
            foreach ($products_page as $p) {
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
                document.querySelector('#modalTitle span').textContent = "Add Product";
                document.getElementById('formAction').value = "add";
            } else {
                const fullProductData = allProductsWithVariants.find(p => p.id == id);
                if (!fullProductData) {
                    toastOr('Product data not found.', 'error');
                    return;
                }
                product = fullProductData;
                document.querySelector('#modalTitle span').textContent = "Edit Product";
                document.getElementById('formAction').value = "update";
                document.getElementById('productId').value = product.id;
                document.getElementById('sku').value = product.sku;
                document.getElementById('name').value = product.name;
                document.getElementById('description').value = product.description;
                document.getElementById('price').value = product.price;
                document.getElementById('weight_kg').value = product.weight_kg;
                document.getElementById('stock').value = product.stock;
                document.getElementById('low_stock_threshold').value = product.low_stock_threshold;
                document.getElementById('expiry_date').value = product.expiry_date || '';
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
<script>
// New Product: read-only preview of the SKU the server will generate (the final SKU is made on save).
(function () {
    var name = document.getElementById('np_name'), out = document.getElementById('np_sku'), hint = document.getElementById('np_sku_hint');
    if (!name || !out) return;
    var t = null, seq = 0;
    function update() {
        var v = name.value.trim(), my = ++seq;
        if (!v) { out.value = ''; return; }
        fetch('products.php?sku_preview=' + encodeURIComponent(v), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (my !== seq) return;
                out.value = d.ok ? d.sku : '';
                if (hint) { hint.textContent = d.ok ? 'Preview - the final SKU is assigned when you save.' : d.message; hint.style.color = d.ok ? '' : 'var(--danger, #dc2626)'; }
            }).catch(function () {});
    }
    name.addEventListener('input', function () { clearTimeout(t); t = setTimeout(update, 250); });
})();
</script>
</body>
</html>
