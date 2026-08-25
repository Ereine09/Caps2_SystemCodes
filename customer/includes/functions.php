<?php
// The Composer autoloader is now handled by a central bootstrap file.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// Now that vendor classes are available, we can include our other files.
require_once __DIR__ . '/db.php';

function ensure_customer_tables(mysqli $conn): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS tbl_product_inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock INT NOT NULL DEFAULT 0,
            category VARCHAR(100) DEFAULT 'General',
            image_url VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_product_variants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            size VARCHAR(100) NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_product_size (product_id, size)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_cart (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            variant_id INT NULL,
            UNIQUE KEY uniq_customer_product_variant (customer_id, product_id, variant_id),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
            FOREIGN KEY (variant_id) REFERENCES tbl_product_variants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            order_number VARCHAR(100) NOT NULL UNIQUE,
            order_status ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled') NOT NULL DEFAULT 'pending',
            fulfillment_type ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
            pickup_date DATE DEFAULT NULL,
            pickup_time VARCHAR(50) DEFAULT NULL,
            delivery_address TEXT DEFAULT NULL,
            order_notes TEXT DEFAULT NULL,
            delivery_phone VARCHAR(50) DEFAULT NULL,
            delivery_instructions TEXT DEFAULT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            bulk_order TINYINT(1) NOT NULL DEFAULT 0,
            free_delivery TINYINT(1) NOT NULL DEFAULT 0,
            loyalty_points_earned DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_after_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            voucher_id INT DEFAULT NULL,
            voucher_code VARCHAR(100) DEFAULT NULL,
            voucher_discount_type ENUM('fixed', 'percent') DEFAULT NULL,
            voucher_discount_value DECIMAL(10,2) DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method ENUM('cod', 'gcash', 'pay_at_shop', 'bank') NOT NULL DEFAULT 'cod',
            payment_reference VARCHAR(100) DEFAULT NULL,
            bank_name VARCHAR(100) DEFAULT NULL,
            bank_account_name VARCHAR(255) DEFAULT NULL,
            payment_proof_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            variant_size VARCHAR(100) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
            INDEX (variant_size)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_product_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            customer_id INT NOT NULL,
            rating INT NOT NULL,
            review_text TEXT,
            is_approved TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_delivery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            delivery_type ENUM('pickup', 'delivery') NOT NULL DEFAULT 'delivery',
            address TEXT DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            instructions TEXT DEFAULT NULL,
            status ENUM('pending','in_transit','delivered','failed') NOT NULL DEFAULT 'pending',
            scheduled_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            qr_confirmation_token VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS customer_addresses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            label VARCHAR(50) NOT NULL,
            full_address TEXT NOT NULL,
            phone VARCHAR(20) NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            INDEX (customer_id, is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tbl_vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            min_order_amount DECIMAL(10,2) DEFAULT NULL,
            usage_limit INT DEFAULT NULL,
            used_count INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        $conn->query($sql);
    }

    // Schema Adjustments
    $conn->query("ALTER TABLE tbl_orders MODIFY COLUMN order_status ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled') NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE tbl_orders MODIFY COLUMN payment_method ENUM('cod','gcash','pay_at_shop','bank') NOT NULL DEFAULT 'cod'");

    $conn->query("ALTER TABLE customer_addresses ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL AFTER phone");
    $conn->query("ALTER TABLE customer_addresses ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL AFTER latitude");

    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER loyalty_points_earned");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS voucher_code VARCHAR(100) DEFAULT NULL AFTER discount_amount");

    $res_delivery_status = $conn->query("SHOW COLUMNS FROM `tbl_delivery` LIKE 'status'");
    if ($res_delivery_status && $res_delivery_status->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_delivery` MODIFY COLUMN `status` ENUM('pending','in_transit','delivered','failed') NOT NULL DEFAULT 'pending'");
    }

    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL AFTER payment_reference");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS bank_account_name VARCHAR(255) DEFAULT NULL AFTER bank_name");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS order_notes TEXT DEFAULT NULL AFTER delivery_address");
    
    $conn->query("ALTER TABLE tbl_cart ADD COLUMN IF NOT EXISTS variant_id INT NULL AFTER quantity");
    $conn->query("ALTER TABLE tbl_order_items ADD COLUMN IF NOT EXISTS variant_size VARCHAR(100) DEFAULT NULL AFTER product_name");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS payment_proof_path VARCHAR(255) DEFAULT NULL AFTER bank_account_name");
    $conn->query("ALTER TABLE tbl_delivery ADD COLUMN IF NOT EXISTS qr_confirmation_token VARCHAR(255) DEFAULT NULL AFTER delivered_at");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS cancellation_reason TEXT DEFAULT NULL AFTER order_status");

    // Loyalty Transactions Adjustment
    $lt_table = mysqli_query($conn, "SHOW TABLES LIKE 'loyalty_transactions'");
    if ($lt_table && mysqli_num_rows($lt_table) > 0) {
        $col_null = mysqli_query($conn, "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='loyalty_transactions' AND COLUMN_NAME='user_id' AND TABLE_SCHEMA=DATABASE()");
        if ($col_null) {
            $row = mysqli_fetch_assoc($col_null);
            if ($row && $row['IS_NULLABLE'] === 'NO') {
                $conn->query("ALTER TABLE loyalty_transactions MODIFY COLUMN user_id INT NULL");
            }
        }
        $check_order_id = mysqli_query($conn, "SHOW COLUMNS FROM loyalty_transactions LIKE 'order_id'");
        if ($check_order_id && mysqli_num_rows($check_order_id) === 0) {
            $conn->query("ALTER TABLE loyalty_transactions ADD COLUMN order_id INT DEFAULT NULL AFTER points_earned");
        }
    }

    // Views
    $conn->query("CREATE OR REPLACE VIEW tbl_customer_records AS SELECT id, name, email, phone, gender, age, address, loyalty_points, created_at FROM customers");
    $conn->query("CREATE OR REPLACE VIEW tbl_user_accounts AS SELECT id, username, first_name, last_name, role, email, password, reset_token, reset_expiry, password_reset_at, login_attempts, lock_until FROM users");

    ensure_default_products($conn);
}

function ensure_default_products(mysqli $conn): void {
    $result = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_product_inventory");
    $count = $result ? (int)$result->fetch_assoc()['cnt'] : 0;

    if ($count === 0) {
        $products = [
            [
                'sku' => 'TOMORO-DOG-01',
                'name' => 'Tomoro Premium Dog Food',
                'description' => 'Nutritious premium dog food for your furry friend.',
                'price' => 450.00,
                'stock' => 50,
                'category' => 'Dog Essentials',
                'image_url' => '/assets/img/dog-food.png'
            ],
            [
                'sku' => 'TOMORO-DOG-02',
                'name' => 'Tomoro Dog Treats',
                'description' => 'Delicious and healthy treats for your dog.',
                'price' => 150.00,
                'stock' => 80,
                'category' => 'Dog Essentials',
                'image_url' => '/assets/img/dog-treats.png'
            ],
            [
                'sku' => 'TOMORO-CAT-01',
                'name' => 'Tomoro Premium Cat Food',
                'description' => 'Nutritious premium cat food for your feline friend.',
                'price' => 380.00,
                'stock' => 60,
                'category' => 'Cat Essentials',
                'image_url' => '/assets/img/cat-food.png'
            ],
            [
                'sku' => 'TOMORO-CAT-02',
                'name' => 'Tomoro Cat Litter',
                'description' => 'High-quality cat litter for your cat\'s hygiene.',
                'price' => 220.00,
                'stock' => 70,
                'category' => 'Cat Essentials',
                'image_url' => '/assets/img/cat-litter.png'
            ]
        ];

        $stmt = $conn->prepare("INSERT INTO tbl_product_inventory (sku, name, description, price, stock, category, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $product) {
            $stmt->bind_param(
                'sssdiss',
                $product['sku'],
                $product['name'],
                $product['description'],
                $product['price'],
                $product['stock'],
                $product['category'],
                $product['image_url']
            );
            $stmt->execute();
        }
        $stmt->close();
    }
}

function get_available_products(): array {
    global $customer_db;
    ensure_customer_tables($customer_db);
    $products = [];
    $result = $customer_db->query("SELECT * FROM tbl_product_inventory ORDER BY name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'id' => (int)$row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => (float)$row['price'],
                'stock' => (int)$row['stock'],
                'category' => $row['category'] ?? 'General',
                'image_url' => $row['image_url'],
            ];
        }
        $result->close();
    }
    return $products;
}

function get_product_variants(int $product_id): array {
    global $customer_db;
    $variants = [];
    $stmt = $customer_db->prepare("SELECT * FROM tbl_product_variants WHERE product_id = ? ORDER BY price ASC");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $variants[] = $row;
        }
        $result->close();
    }
    $stmt->close();
    return $variants;
}

function get_product_stock(int $product_id): int {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT stock FROM tbl_product_inventory WHERE id = ?");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['stock'] : 0;
}

function deduct_product_stock(int $product_id, ?int $variant_id, int $quantity): bool {
    global $customer_db;
    if ($variant_id) {
        $stmt = $customer_db->prepare("UPDATE tbl_product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmt->bind_param('iii', $quantity, $variant_id, $quantity);
    } else {
        $stmt = $customer_db->prepare("UPDATE tbl_product_inventory SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmt->bind_param('iii', $quantity, $product_id, $quantity);
    }
    $success = $stmt->execute();
    $affected = $customer_db->affected_rows;
    $stmt->close();
    return $success && $affected > 0;
}

function restore_product_stock(int $product_id, ?int $variant_id, int $quantity): bool {
    global $customer_db;
    if ($variant_id) {
        $stmt = $customer_db->prepare("UPDATE tbl_product_variants SET stock = stock + ? WHERE id = ?");
        $stmt->bind_param('ii', $quantity, $variant_id);
    } else {
        $stmt = $customer_db->prepare("UPDATE tbl_product_inventory SET stock = stock + ? WHERE id = ?");
        $stmt->bind_param('ii', $quantity, $product_id);
    }
    $success = $stmt->execute();
    $affected = $customer_db->affected_rows;
    $stmt->close();
    return $success && $affected > 0;
}

function get_products_by_category(string $category): array {
    global $customer_db;
    ensure_customer_tables($customer_db);
    $products = [];
    $search_term = '%' . $category . '%';
    $stmt = $customer_db->prepare("SELECT * FROM tbl_product_inventory WHERE category LIKE ? ORDER BY name ASC");
    $stmt->bind_param('s', $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'id' => (int)$row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => (float)$row['price'],
                'stock' => (int)$row['stock'],
                'category' => $row['category'] ?? 'General',
                'image_url' => $row['image_url'],
            ];
        }
        $result->close();
    }
    $stmt->close();
    return $products;
}

function get_all_categories(): array {
    global $customer_db;
    ensure_customer_tables($customer_db);
    $categories = [];
    $result = $customer_db->query("SELECT DISTINCT category FROM tbl_product_inventory WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row['category'];
        }
        $result->close();
    }
    return $categories;
}

function get_product_by_id(int $product_id): ?array {
    global $customer_db;
    ensure_customer_tables($customer_db);
    $stmt = $customer_db->prepare("SELECT * FROM tbl_product_inventory WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    $stmt->close();
    if (!$product) {
        return null;
    }
    return [
        'id' => (int)$product['id'],
        'sku' => $product['sku'],
        'name' => $product['name'],
        'description' => $product['description'],
        'price' => (float)$product['price'],
        'stock' => (int)$product['stock'],
        'image_url' => $product['image_url'],
    ];
}

function get_customer_by_id(int $customer_id): ?array {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT * FROM tbl_customer_records WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();
    return $customer ?: null;
}

function get_customer_by_email(string $email): ?array {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT * FROM tbl_customer_records WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();
    return $customer ?: null;
}

function get_customer_account_by_email(string $email): ?array {
    global $customer_db;
    $stmt = $customer_db->prepare(
        "SELECT ca.*, c.id AS customer_id, c.email AS customer_email, c.name AS customer_name
        FROM customer_login_credentials ca
        JOIN tbl_customer_records c ON ca.customer_id = c.id
        WHERE c.email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->fetch_assoc();
    $stmt->close();
    return $account ?: null;
}

function create_customer_account(array $payload): bool {
    global $customer_db;
    $customer_db->begin_transaction();
    try {
        $stmt = $customer_db->prepare("INSERT INTO customers (name, email, phone, address, loyalty_points) VALUES (?, ?, ?, ?, 0.00)");
        $stmt->bind_param('ssss', $payload['name'], $payload['email'], $payload['phone'], $payload['address']);
        $stmt->execute();
        $customer_id = $customer_db->insert_id;
        $stmt->close();

        $password_hash = password_hash($payload['password'], PASSWORD_DEFAULT);
        $stmt = $customer_db->prepare("INSERT INTO customer_login_credentials (customer_id, password) VALUES (?, ?)");
        $stmt->bind_param('is', $customer_id, $password_hash);
        $stmt->execute();
        $stmt->close();

        $customer_db->commit();
        return true;
    } catch (Exception $e) {
        $customer_db->rollback();
        return false;
    }
}

function get_serviceable_delivery_areas(): array {
    return [
        'caloocan', '10th ave', '10th avenue', 'grace park'
    ];
}

function get_nearby_delivery_areas(): array {
    return [
        'caloocan', '10th ave', '10th avenue', 'grace park'
    ];
}

function normalize_address(string $address): string {
    return strtolower(trim(preg_replace('/[^a-z0-9\s]/i', ' ', $address)));
}

function is_delivery_area_allowed(string $address): bool {
    $address_norm = normalize_address($address);
    foreach (get_serviceable_delivery_areas() as $area) {
        if (strpos($address_norm, $area) !== false) {
            return true;
        }
    }
    return false;
}

function is_free_delivery_area(string $address): bool {
    $address_norm = normalize_address($address);
    $free_areas = ['10th ave', '10th avenue', 'grace park'];
    foreach ($free_areas as $area) {
        if (strpos($address_norm, $area) !== false) {
            return true;
        }
    }
    return false;
}

function is_nearby_delivery_area(string $address): bool {
    $address_norm = normalize_address($address);
    foreach (get_nearby_delivery_areas() as $area) {
        if (strpos($address_norm, $area) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Calculates the distance between two points on Earth given their latitudes and longitudes.
 * @return float The distance in kilometers.
 */
function calculate_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth_radius = 6371; // Earth's radius in kilometers

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius * $c;
}

/**
 * Checks if the given coordinates are within the Caloocan service area.
 * Uses Nominatim reverse geocoding.
 * @return bool True if the location is in Caloocan City.
 */
function is_location_in_service_area(?float $lat, ?float $lon): bool {
    if ($lat === null || $lon === null) {
        return false; // Cannot validate without coordinates
    }

    // Use Nominatim to get address details from coordinates
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lon}&addressdetails=1";
    $opts = ['http' => ['header' => "User-Agent: DariusPoultrySupply/1.0\r\n"]];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response) {
        $data = json_decode($response, true);
        $city = $data['address']['city'] ?? $data['address']['town'] ?? '';
        return strcasecmp(trim($city), 'Caloocan') === 0;
    }
    return false; // Fail-safe: if geocoding fails, assume it's outside
}

function calculate_delivery_fee(string $fulfillment_type, float $subtotal, string $address = '', ?float $lat = null, ?float $lon = null): float {
    if ($fulfillment_type === 'pickup') {
        return 0.00;
    }

    // Rule: Free delivery for purchases of ₱2,000 or more, regardless of distance.
    if ($subtotal >= 2000.00) {
        return 0.00;
    }

    // --- New Distance-Based & Service Area Fee Logic ---
    if ($lat !== null && $lon !== null) {
        // 1. First, check if the location is inside the service area (Caloocan)
        if (!is_location_in_service_area($lat, $lon)) {
            return -1.0; // Use a special value to indicate "outside service area"
        }

        // 2. If inside the service area, calculate distance-based fee
        // Store coordinates (Darius Poultry Supply, 109 P. Burgos St, Caloocan)
        $store_lat = 14.6594;
        $store_lon = 120.9838;

        $distance = calculate_distance_km($store_lat, $store_lon, $lat, $lon);

        // CRITICAL FIX: If distance calculation fails, it's an invalid area.
        if ($distance === null) {
            return -1.0;
        }

        // Tiered Fee Rules
        if ($distance <= 2.0) {
            return 0.00; // FREE within 2km
        }
        if ($distance <= 3.0) return 50.00;
        if ($distance <= 4.0) return 60.00;
        if ($distance <= 5.0) return 70.00;

        return -1.0; // If distance is beyond the max tier (e.g., > 5km), treat as outside service area
    }

    // Fallback fee if no coordinates are provided (should not happen with new UI)
    return 50.00;
}

function update_customer_order_delivery(int $order_id, int $customer_id, string $address, string $phone, ?float $latitude, ?float $longitude): array {
    global $customer_db;

    $address = trim($address);
    $phone = trim($phone);
    if ($order_id <= 0 || $customer_id <= 0 || $address === '' || $phone === '') {
        return ['success' => false, 'message' => 'A complete delivery address and contact number are required.'];
    }
    if (($latitude === null) !== ($longitude === null)
        || ($latitude !== null && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180))) {
        return ['success' => false, 'message' => 'The selected map location is invalid.'];
    }

    $order_stmt = $customer_db->prepare(
        "SELECT id, order_status, fulfillment_type, subtotal, vat_amount, discount_amount
         FROM tbl_orders
         WHERE id = ? AND customer_id = ? LIMIT 1"
    );
    $order_stmt->bind_param('ii', $order_id, $customer_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order) {
        return ['success' => false, 'message' => 'Order not found.'];
    }
    if ($order['fulfillment_type'] !== 'delivery') {
        return ['success' => false, 'message' => 'A delivery address cannot be changed for a pickup order.'];
    }
    if (!in_array($order['order_status'], ['pending', 'confirmed', 'processing'], true)) {
        return ['success' => false, 'message' => 'Address changes are no longer available because this order is already being fulfilled.'];
    }

    if ($latitude !== null && $longitude !== null) {
        if (!is_location_in_service_area($latitude, $longitude)) {
            return ['success' => false, 'message' => 'This location is outside the supported Caloocan delivery area.'];
        }
        $delivery_fee = calculate_delivery_fee(
            'delivery',
            (float)$order['subtotal'] + (float)$order['vat_amount'],
            $address,
            $latitude,
            $longitude
        );
        if ($delivery_fee < 0) {
            return ['success' => false, 'message' => 'This location is outside the supported Caloocan delivery area or delivery range.'];
        }
    } elseif (!is_delivery_area_allowed($address)) {
        return ['success' => false, 'message' => 'Please select a saved address in the supported Caloocan delivery area or choose a location from the map.'];
    } else {
        $delivery_fee = calculate_delivery_fee(
            'delivery',
            (float)$order['subtotal'] + (float)$order['vat_amount'],
            $address
        );
    }

    $discount = (float)$order['discount_amount'];
    $total = max(0, (float)$order['subtotal'] + (float)$order['vat_amount'] + $delivery_fee - $discount);
    $free_delivery = $delivery_fee <= 0 ? 1 : 0;

    $customer_db->begin_transaction();
    try {
        $update_order = $customer_db->prepare(
            "UPDATE tbl_orders
             SET delivery_address = ?, delivery_phone = ?, delivery_fee = ?, free_delivery = ?, total = ?, updated_at = NOW()
             WHERE id = ? AND customer_id = ? AND order_status IN ('pending', 'confirmed', 'processing')"
        );
        $update_order->bind_param('ssdidii', $address, $phone, $delivery_fee, $free_delivery, $total, $order_id, $customer_id);
        if (!$update_order->execute()) {
            $update_order->close();
            throw new Exception('The order could not be updated because its status changed.');
        }
        $update_order->close();

        $update_delivery = $customer_db->prepare(
            "UPDATE tbl_delivery SET address = ?, phone = ? WHERE order_id = ?"
        );
        $update_delivery->bind_param('ssi', $address, $phone, $order_id);
        $update_delivery->execute();
        $update_delivery->close();

        $customer_db->commit();
        return ['success' => true, 'message' => 'Delivery address updated successfully.'];
    } catch (Throwable $e) {
        $customer_db->rollback();
        error_log('[update_customer_order_delivery] failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'The delivery address could not be updated. Please try again.'];
    }
}

function calculate_loyalty_points(float $amount): float {
    return round($amount / 100, 2);
}

function detect_bulk_order(float $subtotal, int $total_items): bool {
    return $subtotal >= 2000.00 || $total_items >= 10;
}

function get_customer_cart(): array {
    if (!isset($_SESSION)) {
        session_start();
    }

    $customer = current_customer();
    if ($customer) {
        global $customer_db;
        ensure_customer_tables($customer_db);

        $stmt = $customer_db->prepare("
            SELECT 
                c.product_id, 
                c.variant_id,
                p.name, 
                COALESCE(v.price, p.price) AS unit_price, 
                v.size,
                c.quantity, 
                p.image_url
            FROM tbl_cart c
            JOIN tbl_product_inventory p ON c.product_id = p.id
            LEFT JOIN tbl_product_variants v ON c.variant_id = v.id
            WHERE c.customer_id = ?
        ");
        $stmt->bind_param('i', $customer['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $cart = [];
        while ($row = $result->fetch_assoc()) {
            $cart_key = $row['product_id'] . '-' . ($row['variant_id'] ?? '0');
            $cart[$cart_key] = [
                'id' => (int)$row['product_id'],
                'variant_id' => (int)($row['variant_id'] ?? 0),
                'name' => $row['name'] . (!empty($row['size']) ? ' (' . $row['size'] . ')' : ''),
                'unit_price' => (float)$row['unit_price'],
                'quantity' => (int)$row['quantity'],
                'image_url' => $row['image_url'],
            ];
        }

        $stmt->close();
        return $cart;
    }

    return $_SESSION['customer_cart'] ?? [];
}

function add_customer_cart_item(int $customer_id, int $product_id, int $quantity, ?int $variant_id = null): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $existing = $customer_db->prepare("SELECT quantity FROM tbl_cart WHERE customer_id = ? AND product_id = ? AND variant_id <=> ? LIMIT 1");
    $existing->bind_param('iii', $customer_id, $product_id, $variant_id);
    $existing->execute();
    $result = $existing->get_result();
    $row = $result->fetch_assoc();
    $existing->close();

    if ($row) {
        $newQuantity = max(1, (int)$row['quantity'] + $quantity);
        $stmt = $customer_db->prepare("UPDATE tbl_cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND product_id = ? AND variant_id <=> ?");
        $stmt->bind_param('iiii', $newQuantity, $customer_id, $product_id, $variant_id);
    } else {
        $stmt = $customer_db->prepare("INSERT INTO tbl_cart (customer_id, product_id, quantity, variant_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiii', $customer_id, $product_id, $quantity, $variant_id);
    }

    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function update_customer_cart_item(int $customer_id, int $product_id, int $quantity, ?int $variant_id = null): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    if ($quantity <= 0) {
        return remove_customer_cart_item($customer_id, $product_id, $variant_id);
    }

    $stmt = $customer_db->prepare("UPDATE tbl_cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND product_id = ? AND variant_id <=> ?");
    $stmt->bind_param('iiii', $quantity, $customer_id, $product_id, $variant_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function remove_customer_cart_item(int $customer_id, int $product_id, ?int $variant_id = null): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $stmt = $customer_db->prepare("DELETE FROM tbl_cart WHERE customer_id = ? AND product_id = ? AND variant_id <=> ?");
    $stmt->bind_param('iii', $customer_id, $product_id, $variant_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function save_customer_cart(array $cart): void {
    if (!isset($_SESSION)) {
        session_start();
    }

    $customer = current_customer();
    if ($customer) {
        global $customer_db;
        ensure_customer_tables($customer_db);
        $customer_id = $customer['id'];
        $customer_db->query("DELETE FROM tbl_cart WHERE customer_id = $customer_id");

        $stmt = $customer_db->prepare("INSERT INTO tbl_cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
        foreach ($cart as $item) {
            $product_id = (int)$item['id'];
            $quantity = max(1, (int)$item['quantity']);
            $stmt->bind_param('iii', $customer_id, $product_id, $quantity);
            $stmt->execute();
        }
        $stmt->close();
        return;
    }

    $_SESSION['customer_cart'] = $cart;
}

function clear_customer_cart(): void {
    if (!isset($_SESSION)) {
        session_start();
    }

    $customer = current_customer();
    if ($customer) {
        global $customer_db;
        ensure_customer_tables($customer_db);
        $stmt = $customer_db->prepare("DELETE FROM tbl_cart WHERE customer_id = ?");
        $stmt->bind_param('i', $customer['id']);
        $stmt->execute();
        $stmt->close();
        return;
    }

    unset($_SESSION['customer_cart']);
}

function cart_item_count(): int {
    $customer = current_customer();
    if ($customer) {
        global $customer_db;
        ensure_customer_tables($customer_db);
        $stmt = $customer_db->prepare("SELECT COALESCE(SUM(quantity),0) AS total_qty FROM tbl_cart WHERE customer_id = ?");
        $stmt->bind_param('i', $customer['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (int)($row['total_qty'] ?? 0);
    }

    $cart = get_customer_cart();
    return array_sum(array_column($cart, 'quantity'));
}

function cart_subtotal_ex_vat(): float {
    $customer = current_customer();
    if ($customer) {
        $cart = get_customer_cart();
        $subtotal = 0.00;
        foreach ($cart as $item) {
            $subtotal += ((float)$item['unit_price'] * (int)$item['quantity']);
        }
        return $subtotal;
    }

    $cart = get_customer_cart();
    $subtotal = 0.00;
    foreach ($cart as $item) {
        $subtotal += ((float)$item['unit_price'] * (int)$item['quantity']);
    }
    return $subtotal;
}

// Cart subtotal including 12% VAT.
function cart_subtotal(): float {
    $subtotal_ex_vat = cart_subtotal_ex_vat();
    $vat_rate = 0.12;
    return round($subtotal_ex_vat * (1 + $vat_rate), 2);
}


function get_customer_products(): array {
    return get_available_products();
}

function create_customer_order(int $customer_id, string $fulfillment_type, array $details, array $cart_items): ?int {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $subtotal_ex_vat = 0.00;
    $total_items = 0;
    foreach ($cart_items as $item) {
        $subtotal_ex_vat += ((float)$item['unit_price'] * (int)$item['quantity']);
        $total_items += (int)$item['quantity'];
    }

    $vat_rate = 0.12;
    $vat_amount = round($subtotal_ex_vat * $vat_rate, 2);
    $subtotal_inc_vat = $subtotal_ex_vat + $vat_amount;

    $delivery_fee = calculate_delivery_fee(
        $fulfillment_type,
        $subtotal_inc_vat,
        $details['delivery_address'] ?? '',
        isset($details['latitude']) && is_numeric($details['latitude']) ? (float)$details['latitude'] : null,
        isset($details['longitude']) && is_numeric($details['longitude']) ? (float)$details['longitude'] : null
    );

    $total = $subtotal_inc_vat + $delivery_fee;
    $discount_amount = 0.00;
    $total_after_discount = $total;
    $voucher_id = null;
    $voucher_code = null;
    $voucher_discount_type = null;
    $voucher_discount_value = 0.00;

    $voucher_code_input = trim((string)($details['voucher_code'] ?? ''));
    if ($voucher_code_input !== '') {
        $voucher_stmt = $customer_db->prepare(
            "SELECT id, code, discount_type, discount_value, min_order_amount, usage_limit, used_count, active, expires_at
             FROM tbl_vouchers
             WHERE code = ? AND active = 1
             LIMIT 1"
        );
        $voucher_stmt->bind_param('s', $voucher_code_input);
        $voucher_stmt->execute();
        $voucher = $voucher_stmt->get_result()->fetch_assoc();
        $voucher_stmt->close();

        if ($voucher) {
            if (!empty($voucher['expires_at'])) {
                $exp = new DateTime((string)$voucher['expires_at']);
                $now = new DateTime('now');
                if ($exp < $now) {
                    $voucher = null;
                }
            }

            if ($voucher && $voucher['min_order_amount'] !== null) {
                $min = (float)$voucher['min_order_amount'];
                if ($subtotal_inc_vat < $min) {
                    $voucher = null;
                }
            }

            if ($voucher && $voucher['usage_limit'] !== null) {
                $limit = (int)$voucher['usage_limit'];
                $used = (int)$voucher['used_count'];
                if ($limit > 0 && $used >= $limit) {
                    $voucher = null;
                }
            }

            if ($voucher) {
                $voucher_id = (int)$voucher['id'];
                $voucher_code = $voucher['code'];
                $voucher_discount_type = $voucher['discount_type'];
                $voucher_discount_value = (float)$voucher['discount_value'];

                if ($voucher_discount_type === 'percent') {
                    $discount_amount = round($total * ($voucher_discount_value / 100), 2);
                } else {
                    $discount_amount = round($voucher_discount_value, 2);
                }

                if ($discount_amount < 0) {
                    $discount_amount = 0.00;
                }
                if ($discount_amount > $total) {
                    $discount_amount = $total;
                }

                $total_after_discount = $total - $discount_amount;
            }
        }
    }

    // --- Final Calculations after Discount ---
    $free_delivery = $delivery_fee <= 0.00 ? 1 : 0;
    $bulk_order = detect_bulk_order($subtotal_inc_vat, $total_items) ? 1 : 0;
    $loyalty_points = 0.00;

    $order_number = generate_order_number();
    error_log("DEBUG: Order #{$order_number} - Calculated Loyalty Points: {$loyalty_points} from (Subtotal ex-VAT: {$subtotal_ex_vat})");
    
    $payment_method = (string)($details['payment_method'] ?? 'cod');


    // Start transaction for order creation
    $customer_db->begin_transaction();
    try {
        $stmt = $customer_db->prepare(
            "INSERT INTO tbl_orders (customer_id, order_number, fulfillment_type, pickup_date, pickup_time, delivery_address, order_notes, delivery_phone, delivery_instructions, subtotal, vat_amount, delivery_fee, bulk_order, free_delivery, loyalty_points_earned, discount_amount, voucher_code, total, payment_method, payment_reference, bank_name, bank_account_name, payment_proof_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $delivery_address = $details['delivery_address'] ?? null;
        $order_notes = $details['order_notes'] ?? null;
        $delivery_phone = $details['delivery_phone'] ?? null;
        $delivery_instructions = $details['delivery_instructions'] ?? null;
        $pickup_date = $details['pickup_date'] ?? null;
        $pickup_time = $details['pickup_time'] ?? null;
        
        // Ensure we have discount fields coming from $details (checkout passes these)
        $final_total = $subtotal_inc_vat + $delivery_fee - ($details['discount_amount'] ?? 0.00);
        $discount_to_save = (float)($details['discount_amount'] ?? 0.00);
        $voucher_code_to_save = $details['voucher_code'] ?? null;
        
        // Consolidate reference number logic
        $payment_reference = $details['gcash_reference_number'] ?? $details['bank_reference_number'] ?? null;
        $bank_name = ($payment_method === 'bank') ? ($details['bank_name'] ?? null) : null;
        $bank_account_name = ($payment_method === 'bank') ? ($details['bank_account_name'] ?? null) : null;
        $payment_proof_path = ($payment_method === 'bank') ? ($details['payment_proof_path'] ?? null) : null;
        $stmt->bind_param('isssssssdddiisdssssssss', $customer_id, $order_number, $fulfillment_type, $pickup_date, $pickup_time, $delivery_address, $order_notes, $delivery_phone, $delivery_instructions, $subtotal_ex_vat, $vat_amount, $delivery_fee, $bulk_order, $free_delivery, $loyalty_points, $discount_to_save, $voucher_code_to_save, $final_total, $payment_method, $payment_reference, $bank_name, $bank_account_name, $payment_proof_path);
        $stmt->execute();
        $order_id = $customer_db->insert_id;
        $stmt->close();

        $item_stmt = $customer_db->prepare(
            "INSERT INTO tbl_order_items (order_id, product_id, product_name, variant_size, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($cart_items as $item) {
            $line_total = ((float)$item['unit_price'] * (int)$item['quantity']);
            $variant_size = $item['size'] ?? null;
            $product_name = $item['name'];
            $item_stmt->bind_param('iissidd', $order_id, $item['id'], $product_name, $variant_size, $item['quantity'], $item['unit_price'], $line_total);
            $item_stmt->execute();
        }
        $item_stmt->close();

        // --- E-Receipt & Delivery Record Creation ---
        // Generate a unique token for QR confirmation, regardless of fulfillment type.
        $confirmation_token = bin2hex(random_bytes(16)); // Shortened for QR density

        if ($fulfillment_type === 'pickup') {
            $scheduled_at_sql = "CONCAT(?, ' ', ?)";
            $delivery_stmt = $customer_db->prepare(
                "INSERT INTO tbl_delivery (order_id, delivery_type, address, phone, instructions, status, qr_confirmation_token, scheduled_at, created_at) 
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, $scheduled_at_sql, NOW())"
            );
            $delivery_stmt->bind_param('isssssss', $order_id, $fulfillment_type, $delivery_address, $delivery_phone, $delivery_instructions, $confirmation_token, $pickup_date, $pickup_time);
        } else {
            $delivery_stmt = $customer_db->prepare(
                "INSERT INTO tbl_delivery (order_id, delivery_type, address, phone, instructions, status, qr_confirmation_token, created_at) 
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())"
            );
            $delivery_stmt->bind_param('isssss', $order_id, $fulfillment_type, $delivery_address, $delivery_phone, $delivery_instructions, $confirmation_token);
        }
        $delivery_stmt->execute();
        $delivery_id = $customer_db->insert_id;
        $delivery_stmt->close();

        // Send the e-receipt email for ALL order types.
        send_order_ereceipt($customer_id, $order_id, $delivery_id, $confirmation_token);
        

        record_order_notification($customer_db, $customer_id, $order_id, $order_number, $subtotal_inc_vat, $fulfillment_type, $bulk_order, $free_delivery);

        $customer_db->commit();
        return $order_id;
    } catch (Exception $e) {
        $customer_db->rollback();
        // Expose real error for debugging (developer/admin).
        error_log('[create_customer_order] failed: ' . $e->getMessage());

        // Also expose it in session so checkout.php can display the real reason.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['last_order_error'] = $e->getMessage();

        return null;
    }
}

function send_order_ereceipt(int $customer_id, int $order_id, int $delivery_id, string $token): void {
    global $customer_db;
    $customer = get_customer_by_id($customer_id);
    $order = get_order_by_id($order_id, $customer_id);
    $order_items = get_order_items($order_id);

    if (!$customer || !$order) {
        error_log("Could not send e-receipt: customer or order not found for order_id: $order_id");
        return;
    }

    // Generate QR Code (optional dependency)
    $qr_code_uri = null;
    $qr_data = json_encode(['delivery_id' => $delivery_id, 'token' => $token]);

    if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
        try {
            $qrCode = QrCode::create($qr_data);
            $writer = new PngWriter();
            $qr_code_uri = $writer->write($qrCode)->getDataUri();
        } catch (Throwable $t) {
            error_log('[send_order_ereceipt] QR generation failed: ' . $t->getMessage());
            $qr_code_uri = null;
        }
    } else {
        error_log('[send_order_ereceipt] Endroid\u0027s qr-code library not installed; skipping QR generation.');
        $qr_code_uri = null;
    }


    // Email Body
    $items_html = '';
    foreach ($order_items as $item) {
        $items_html .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($item['product_name']) . " (x" . (int)$item['quantity'] . ")</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'>PHP " . number_format((float)$item['total_price'], 2) . "</td></tr>";
    }

    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; padding: 20px;'>
            <h2 style='color: #333;'>Your Order is Confirmed!</h2>
            <p>Hi " . htmlspecialchars($customer['name']) . ",</p>
            <p>Thank you for your order. Here is your e-receipt. Please have the QR code below ready for the rider to scan upon delivery.</p>
            <hr>
            <h3>Order Summary (Order #" . htmlspecialchars($order['order_number']) . ")</h3>
            <table style='width: 100%; border-collapse: collapse;'>
                <thead><tr><th style='text-align: left; padding: 8px; border-bottom: 2px solid #333;'>Item</th><th style='text-align: right; padding: 8px; border-bottom: 2px solid #333;'>Price</th></tr></thead>
                <tbody>" . $items_html . "</tbody>
                <tfoot>
                    <tr><td style='padding: 8px; text-align: right;'>Subtotal:</td><td style='padding: 8px; text-align: right;'>PHP " . number_format((float)$order['subtotal'], 2) . "</td></tr>
                    <tr><td style='padding: 8px; text-align: right;'>Delivery Fee:</td><td style='padding: 8px; text-align: right;'>PHP " . number_format((float)$order['delivery_fee'], 2) . "</td></tr>
                    <tr><td style='padding: 8px; text-align: right;'>Discount:</td><td style='padding: 8px; text-align: right;'>- PHP " . number_format((float)$order['discount_amount'], 2) . "</td></tr>
                    <tr><th style='padding: 8px; text-align: right;'>Total:</th><th style='padding: 8px; text-align: right;'>PHP " . number_format((float)$order['total'], 2) . "</th></tr>
                </tfoot>
            </table>
            " . ($qr_code_uri ? "
                <hr>
                <div style='text-align: center; margin-top: 20px;'>
                    <h3>Delivery Confirmation QR Code</h3>
                    <p>For rider to scan upon delivery.</p>
                    <img src='" . $qr_code_uri . "' alt='QR Code for delivery confirmation' style='width: 200px; height: 200px;'/>
                </div>
            " : "<!-- QR Code generation failed or library not installed -->") . "
            <p style='text-align: center; font-size: 0.9em; color: #777; margin-top: 20px;'>Thank you for shopping with us!</p>
        </div>
    ";

    // Send Email using PHPMailer
    $mail = new PHPMailer(true);

    try {
        //Server settings - replace with your SMTP details
        $mail->isSMTP(); // Use SMTP
        $mail->Host       = SMTP_HOST; // Your SMTP server
        $mail->SMTPAuth   = SMTP_AUTH; // Enable authentication
        $mail->Username   = SMTP_USERNAME; // Your SMTP username
        $mail->Password   = SMTP_PASSWORD; // Your SMTP password or App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SMTPS
        $mail->Port       = SMTP_PORT; // TCP port

        //Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, htmlspecialchars(SMTP_FROM_NAME));
        $mail->addAddress($customer['email'], htmlspecialchars($customer['name']));

        //Attachments
        // $mail->addStringAttachment(base64_decode(explode(',', $qr_code_uri)[1]), 'qrcode.png', 'base64', 'image/png');

        //Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Order Confirmation & E-Receipt from ' . htmlspecialchars(SYSTEM_NAME);
        $mail->Body    = $body;
        $mail->AltBody = 'Your order ' . htmlspecialchars($order['order_number']) . ' is confirmed. Total: PHP ' . number_format((float)$order['total'], 2) . '. Please check your email for the full receipt and QR code.';

        $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

/**
 * Generates a QR code for a confirmed order and sends a payment confirmation email.
 *
 * @param int    $order_id The ID of the order.
 * @param string $customer_email The customer's email address.
 * @param string $customer_name The customer's name.
 * @return bool True on success, false on failure.
 */
function send_payment_confirmation_email(int $order_id, string $customer_email, string $customer_name): bool {
    global $customer_db;

    try {
        $order = get_order_by_id($order_id);
        if (!$order) {
            throw new Exception("Order not found for ID: $order_id");
        }

        // 1. Generate a unique token for the QR code
        $token = 'CONFIRMED-' . bin2hex(random_bytes(16)) . '-' . $order_id;

        // 2. Generate the QR code image
        $qr_code_uri = null;
        if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
            $qrCode = QrCode::create($token);
            $writer = new PngWriter();
            $qr_code_uri = $writer->write($qrCode)->getDataUri();
        } else {
            error_log('[send_payment_confirmation_email] QR Code library not found.');
            // We can continue without a QR code, but we should log it.
        }

        // 3. Save the QR code to a file (if generated)
        $qr_code_path = null;
        if ($qr_code_uri) {
            $qr_image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $qr_code_uri));
            $qr_filename = 'qr_order_' . $order['order_number'] . '.png';
            // IMPORTANT: Ensure this directory exists and is writable by your web server!
            $qr_directory = __DIR__ . '/../../uploads/qrcodes/';
            if (!is_dir($qr_directory)) {
                mkdir($qr_directory, 0755, true);
            }
            $qr_code_path = $qr_directory . $qr_filename;
            file_put_contents($qr_code_path, $qr_image_data);

            // 4. Update the database with the QR code path and the unique token
            $stmt = $customer_db->prepare("UPDATE tbl_orders SET qr_code_path = ?, payment_confirmation_token = ? WHERE id = ?");
            $relative_path = 'uploads/qrcodes/' . $qr_filename;
            $stmt->bind_param('ssi', $relative_path, $token, $order_id);
            $stmt->execute();
            $stmt->close();
        }
        // 5. Send the confirmation email using PHPMailer
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, htmlspecialchars(SMTP_FROM_NAME));
        $mail->addAddress($customer_email, htmlspecialchars($customer_name));

        $mail->isHTML(true);
        $mail->Subject = 'Your Order #' . htmlspecialchars($order['order_number']) . ' is Confirmed!';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; padding: 20px;'>
                <h2 style='color: #27ae60;'>Payment Confirmed!</h2>
                <p>Hi " . htmlspecialchars($customer_name) . ",</p>
                <p>We are happy to let you know that the payment for your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been confirmed.</p>
                " . ($qr_code_path ? "<p>You can present the QR code below when you pickup your order or receive your delivery.</p>
                <div style='text-align: center; margin: 20px 0;'><img src='" . $qr_code_uri . "' alt='Order QR Code'></div>" : "") . "
                <p>Thank you for your purchase!</p>
            </div>";
        $mail->AltBody = 'Your payment for order #' . htmlspecialchars($order['order_number']) . ' has been confirmed. Thank you for your purchase!';

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send payment confirmation for order $order_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Sends an order status update email to the customer that includes the delivery
 * confirmation QR code. The QR code encodes the JSON payload the rider app scans
 * in `rider_qr_confirm_api.php` ({ "delivery_id": X, "token": "..." }), so the
 * rider can confirm delivery directly from the email without opening the web system.
 *
 * @param int    $order_id
 * @param string $customer_email
 * @param string $customer_name
 * @param string $new_status_label
 * @param string $order_number
 * @return bool
 */
function send_order_status_email_with_qr(int $order_id, string $customer_email, string $customer_name, string $new_status_label, string $order_number): bool {
    global $customer_db;

    try {
        // Fetch the delivery record to get the delivery_id + confirmation token.
        $delivery = get_delivery_details_by_order_id($order_id);
        if (!$delivery || empty($delivery['qr_confirmation_token'])) {
            // No QR token available -> fall back to a plain status email.
            return false;
        }

        $delivery_id = $delivery['id'];
        $token = $delivery['qr_confirmation_token'];

        // Build the QR payload exactly as rider_qr_confirm_api.php expects.
        $qr_data = json_encode([
            'delivery_id' => (int)$delivery_id,
            'token'       => $token,
        ]);

        // Generate the QR code image.
        $qr_code_uri = null;
        if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
            try {
                $qrCode = QrCode::create($qr_data);
                $writer = new PngWriter();
                $qr_code_uri = $writer->write($qrCode)->getDataUri();
            } catch (Throwable $t) {
                error_log('[send_order_status_email_with_qr] QR generation failed: ' . $t->getMessage());
                $qr_code_uri = null;
            }
        } else {
            error_log('[send_order_status_email_with_qr] Endroid QR library not installed; skipping QR.');
        }

        // Always include a readable fallback token so the rider can still confirm.
        $token_readable = $delivery_id . '|' . $token;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, htmlspecialchars(SMTP_FROM_NAME));
        $mail->addAddress($customer_email, htmlspecialchars($customer_name));
        $mail->isHTML(true);
        $mail->Subject = 'Order ' . $order_number . ' - ' . $new_status_label;

        $qr_html = $qr_code_uri
            ? "<div style='text-align:center; margin:20px 0;'>
                 <h3 style='color:#4a3e94; margin-bottom:6px;'>Delivery Confirmation QR Code</h3>
                 <p style='font-size:13px; color:#666; margin-bottom:12px;'>Present this to your rider so they can scan it and confirm delivery.</p>
                 <img src='" . $qr_code_uri . "' alt='Delivery QR Code' style='width:190px; height:190px; border:4px solid #eef0f4; border-radius:8px;'/>
               </div>"
            : "<p style='color:#888;'>Please have your order number <strong>" . htmlspecialchars($order_number) . "</strong> ready for the rider.</p>";

        $mail->Body = "
            <div style='font-family:Arial, sans-serif; max-width:600px; margin:auto; border:1px solid #eee; padding:20px;'>
                <h2 style='color:#4a3e94;'>Order Status Update</h2>
                <p>Hi " . htmlspecialchars($customer_name) . ",</p>
                <p>Your order <strong>" . htmlspecialchars($order_number) . "</strong> has been updated to: <strong style='color:#27ae60;'>" . htmlspecialchars($new_status_label) . "</strong>.</p>
                " . $qr_html . "
                <hr style='border:none; border-top:1px solid #eee; margin:20px 0;'>
                <p style='font-size:12px; color:#999;'>If you have any questions, please contact us. This is an automated message from " . SYSTEM_NAME . ".</p>
            </div>";
        $mail->AltBody = "Your order $order_number has been updated to: $new_status_label. Delivery confirmation code: $token_readable";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send order status email with QR for order $order_id: " . $e->getMessage());
        return false;
    }
}

function record_order_notification(mysqli $conn, int $customer_id, int $order_id, string $order_number, float $subtotal, string $fulfillment_type, int $bulk_order, int $free_delivery): void {
    $title = 'New Customer Order';
    $message = "Order #$order_number for PHP " . number_format($subtotal, 2) . " was placed as " . ucfirst($fulfillment_type) . ".";
    if ($bulk_order) {
        $message .= ' Bulk order flagged for priority handling.';
    }
    if ($free_delivery) {
        $message .= ' Free delivery applied.';
    }

    $stmt = $conn->prepare(
        "INSERT INTO notifications (customer_id, type, channel, title, message, reference_table, reference_id, is_read) VALUES (?, 'ORDER', 'in_app', ?, ?, 'tbl_orders', ?, 0)"
    );
    $stmt->bind_param('issi', $customer_id, $title, $message, $order_id);
    $stmt->execute();
    $stmt->close();
}

function generate_order_number(): string {
    return 'DPS-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();
}

function get_customer_orders(int $customer_id): array {
    global $customer_db;
    $stmt = $customer_db->prepare("
        SELECT o.*, GROUP_CONCAT(oi.product_name SEPARATOR ', ') as items_summary
        FROM tbl_orders o
        LEFT JOIN tbl_order_items oi ON o.id = oi.order_id
        WHERE o.customer_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
    return $orders;
}

function get_order_by_id(int $order_id, ?int $customer_id = null): ?array {
    global $customer_db;
    if ($customer_id !== null) {
        $stmt = $customer_db->prepare("SELECT * FROM tbl_orders WHERE id = ? AND customer_id = ? LIMIT 1");
        $stmt->bind_param('ii', $order_id, $customer_id);
    } else {
        $stmt = $customer_db->prepare("SELECT * FROM tbl_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $order_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();
    return $order ?: null;
}

function get_delivery_details_by_order_id(int $order_id): ?array {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT id, qr_confirmation_token FROM tbl_delivery WHERE order_id = ? LIMIT 1");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $delivery = $result->fetch_assoc();
    $stmt->close();
    return $delivery ? [
        'id' => (int)$delivery['id'],
        'qr_confirmation_token' => $delivery['qr_confirmation_token']
    ] : null;
}

/**
 * Fetch the proof-of-delivery record for an order.
 *
 * The rider uploads a proof photo via rider_proof_api.php which is logged in
 * the `delivery_tracking` table with status='delivered', proof_image_url and
 * notes. We join riders + users to also surface the rider's name.
 *
 * @param int $order_id
 * @return array|null Returns the latest delivered proof record, or null if none.
 */
function get_order_delivery_proof(int $order_id): ?array {
    global $customer_db;
    $stmt = $customer_db->prepare(
        "SELECT dt.id, dt.status, dt.notes, dt.proof_image_url, dt.created_at,
                CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS rider_name,
                r.vehicle_type, r.plate_number
         FROM delivery_tracking dt
         JOIN riders r ON r.id = dt.rider_id
         LEFT JOIN users u ON u.id = r.user_id
         WHERE dt.order_id = ? AND dt.status = 'delivered' AND dt.proof_image_url IS NOT NULL
         ORDER BY dt.created_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $proof = $result->fetch_assoc();
    $stmt->close();
    return $proof ?: null;
}

function get_order_items(int $order_id): array {
    global $customer_db;
    $stmt = $customer_db->prepare("
        SELECT oi.*, p.image_url 
        FROM tbl_order_items oi 
        LEFT JOIN tbl_product_inventory p ON oi.product_id = p.id 
        WHERE oi.order_id = ? ORDER BY oi.id ASC");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

/**
 * Check if a customer has already reviewed a specific product from a specific order.
 *
 * @param integer $customer_id
 * @param integer $product_id
 * @param integer $order_id
 * @return boolean
 */
function has_customer_reviewed_product(int $customer_id, int $product_id, int $order_id): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT id FROM tbl_product_reviews WHERE customer_id = ? AND product_id = ? AND order_id = ? LIMIT 1");
    $stmt->bind_param('iii', $customer_id, $product_id, $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result->num_rows > 0;
}

/**
 * Submit a new product review.
 *
 * @param array $data
 * @return boolean
 */
function submit_product_review(array $data): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("INSERT INTO tbl_product_reviews (order_id, product_id, customer_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param(
        'iiiis',
        $data['order_id'],
        $data['product_id'],
        $data['customer_id'],
        $data['rating'],
        $data['review_text']
    );
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

/**
 * Get all reviews for a product, optionally only approved ones.
 *
 * @param integer $product_id
 * @param boolean $approved_only
 * @return array
 */
function get_product_reviews(int $product_id, bool $approved_only = true): array {
    global $customer_db;
    $reviews = [];
    $sql = "SELECT r.*, c.name as customer_name 
            FROM tbl_product_reviews r 
            JOIN customers c ON r.customer_id = c.id 
            WHERE r.product_id = ?";
    if ($approved_only) {
        $sql .= " AND r.is_approved = 1";
    }
    $sql .= " ORDER BY r.created_at DESC";

    $stmt = $customer_db->prepare($sql);
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt->close();
    return $reviews;
}

/**
 * Get all reviews for the admin panel.
 *
 * @return array
 */
function get_all_reviews(): array {
    global $customer_db;
    $reviews = [];
    $sql = "SELECT r.*, p.name as product_name, c.name as customer_name 
            FROM tbl_product_reviews r 
            JOIN tbl_product_inventory p ON r.product_id = p.id
            JOIN customers c ON r.customer_id = c.id 
            ORDER BY r.created_at DESC";
    $result = $customer_db->query($sql);
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    return $reviews;
}

function approve_review(int $review_id): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("UPDATE tbl_product_reviews SET is_approved = 1 WHERE id = ?");
    $stmt->bind_param('i', $review_id);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function delete_review(int $review_id): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("DELETE FROM tbl_product_reviews WHERE id = ?");
    $stmt->bind_param('i', $review_id);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function get_product_average_rating(int $product_id): array {
    global $customer_db;
    $stmt = $customer_db->prepare("SELECT AVG(rating) as avg_rating, COUNT(id) as review_count FROM tbl_product_reviews WHERE product_id = ? AND is_approved = 1");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['avg' => (float)($result['avg_rating'] ?? 0), 'count' => (int)($result['review_count'] ?? 0)];
}

function get_all_orders_summary(): array {
    global $customer_db;
    $orders = [];
    $result = $customer_db->query("SELECT order_status, fulfillment_type, COUNT(*) AS count_orders, SUM(total) AS total_sales FROM tbl_orders GROUP BY order_status, fulfillment_type");
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    return $orders;
}

// Initialize tables automatically when helper loads.
ensure_customer_tables($customer_db);