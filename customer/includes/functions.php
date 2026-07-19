<?php
require_once __DIR__ . '/db.php';

// Add the Composer autoloader to make vendor libraries available (optional).
// Some deployments may not run composer install, so fail softly.
$autoloadPath = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}


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
        "CREATE TABLE IF NOT EXISTS tbl_cart (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_customer_product (customer_id, product_id),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE
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
            delivery_phone VARCHAR(50) DEFAULT NULL,
            delivery_instructions TEXT DEFAULT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            bulk_order TINYINT(1) NOT NULL DEFAULT 0,
            free_delivery TINYINT(1) NOT NULL DEFAULT 0,
            loyalty_points_earned DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_after_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            voucher_id INT DEFAULT NULL,
            voucher_code VARCHAR(100) DEFAULT NULL,
            voucher_discount_type ENUM('fixed','percent') DEFAULT NULL,
            voucher_discount_value DECIMAL(10,2) DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method ENUM('cod','gcash','pay_at_shop','bank') NOT NULL DEFAULT 'cod',
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
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tbl_delivery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            delivery_type ENUM('pickup','delivery') NOT NULL DEFAULT 'delivery',
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

    // Ensure existing table ENUM values match the PHP status list
    $conn->query("ALTER TABLE tbl_orders MODIFY COLUMN order_status 
        ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled')
        NOT NULL DEFAULT 'pending'");

    // Ensure payment_method enum supports all checkout options
    $conn->query("ALTER TABLE tbl_orders MODIFY COLUMN payment_method 
        ENUM('cod','gcash','pay_at_shop','bank') NOT NULL DEFAULT 'cod'");

    // --- FIX: Add missing columns if they don't exist to prevent "Unknown column" errors ---
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER loyalty_points_earned");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS voucher_code VARCHAR(100) DEFAULT NULL AFTER discount_amount");

    // --- ADD: Columns for Bank Transfer payment ---
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL AFTER payment_reference");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS bank_account_name VARCHAR(255) DEFAULT NULL AFTER bank_name");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS payment_proof_path VARCHAR(255) DEFAULT NULL AFTER bank_account_name");

    // Add qr_confirmation_token to tbl_delivery
    $conn->query("ALTER TABLE tbl_delivery ADD COLUMN IF NOT EXISTS qr_confirmation_token VARCHAR(255) DEFAULT NULL AFTER delivered_at");

    // The other voucher columns from the original CREATE TABLE are not used in create_customer_order, so they are not strictly needed to fix this error.
    // You can add them here if other parts of your application need them.

    $conn->query(
        "CREATE OR REPLACE VIEW tbl_customer_records AS
         SELECT id, name, email, phone, gender, age, address, loyalty_points, created_at
         FROM customers"
    );

    $conn->query(
        "CREATE OR REPLACE VIEW tbl_user_accounts AS
         SELECT id, username, first_name, last_name, role, email, password, reset_token, reset_expiry, password_reset_at, login_attempts, lock_until
         FROM users"
    );

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

/**
 * Get current stock for a product
 */
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

/**
 * Deduct stock from inventory
 */
function deduct_product_stock(int $product_id, int $quantity): bool {
    global $customer_db;
    // Ensure we don't go below zero using a WHERE clause check
    $stmt = $customer_db->prepare("UPDATE tbl_product_inventory SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $stmt->bind_param('iii', $quantity, $product_id, $quantity);
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

function calculate_delivery_fee(string $fulfillment_type, float $subtotal, string $address = ''): float {
    if ($fulfillment_type === 'pickup') {
        return 0.00;
    }

    $address_norm = normalize_address($address);

    // Rule: Free delivery for 10th Ave and Grace Park
    if (is_free_delivery_area($address)) {
        return 0.00;
    }

    // Rule: ₱50.00 fee for Caloocan, or Free if subtotal is ₱2,000+
    if (strpos($address_norm, 'caloocan') !== false) {
        if ($subtotal >= 2000.00) {
            return 0.00;
        }
        return 50.00;
    }

    return 120.00;
}

function calculate_loyalty_points(float $amount): int {
    return (int) floor($amount / 100);
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

        $stmt = $customer_db->prepare(
            "SELECT c.product_id, p.name, p.price AS unit_price, c.quantity, p.image_url
             FROM tbl_cart c
             JOIN tbl_product_inventory p ON c.product_id = p.id
             WHERE c.customer_id = ?"
        );
        $stmt->bind_param('i', $customer['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $cart = [];
        while ($row = $result->fetch_assoc()) {
            $cart[(int)$row['product_id']] = [
                'id' => (int)$row['product_id'],
                'name' => $row['name'],
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

function add_customer_cart_item(int $customer_id, int $product_id, int $quantity): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $existing = $customer_db->prepare("SELECT quantity FROM tbl_cart WHERE customer_id = ? AND product_id = ? LIMIT 1");
    $existing->bind_param('ii', $customer_id, $product_id);
    $existing->execute();
    $result = $existing->get_result();
    $row = $result->fetch_assoc();
    $existing->close();

    if ($row) {
        $newQuantity = max(1, (int)$row['quantity'] + $quantity);
        $stmt = $customer_db->prepare("UPDATE tbl_cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND product_id = ?");
        $stmt->bind_param('iii', $newQuantity, $customer_id, $product_id);
    } else {
        $stmt = $customer_db->prepare("INSERT INTO tbl_cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->bind_param('iii', $customer_id, $product_id, $quantity);
    }

    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function update_customer_cart_item(int $customer_id, int $product_id, int $quantity): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    if ($quantity <= 0) {
        return remove_customer_cart_item($customer_id, $product_id);
    }

    $stmt = $customer_db->prepare("UPDATE tbl_cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND product_id = ?");
    $stmt->bind_param('iii', $quantity, $customer_id, $product_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function remove_customer_cart_item(int $customer_id, int $product_id): bool {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $stmt = $customer_db->prepare("DELETE FROM tbl_cart WHERE customer_id = ? AND product_id = ?");
    $stmt->bind_param('ii', $customer_id, $product_id);
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

function cart_subtotal(): float {
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

function get_customer_products(): array {
    return get_available_products();
}

function create_customer_order(int $customer_id, string $fulfillment_type, array $details, array $cart_items): ?int {
    global $customer_db;
    ensure_customer_tables($customer_db);

    $subtotal = 0.00;
    $total_items = 0;
    foreach ($cart_items as $item) {
        $subtotal += ((float)$item['unit_price'] * (int)$item['quantity']);
        $total_items += (int)$item['quantity'];
    }

    $delivery_fee = calculate_delivery_fee($fulfillment_type, $subtotal, $details['delivery_address'] ?? '');
    $free_delivery = $delivery_fee <= 0.00 ? 1 : 0;
    $bulk_order = detect_bulk_order($subtotal, $total_items) ? 1 : 0;
    $loyalty_points = calculate_loyalty_points($subtotal);

    $total = $subtotal + $delivery_fee;
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
                if ($subtotal < $min) {
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

    $order_number = generate_order_number();
    
    $payment_method = (string)($details['payment_method'] ?? 'cod');


    // Start transaction for order creation
    $customer_db->begin_transaction();
    try {
        $stmt = $customer_db->prepare(
            "INSERT INTO tbl_orders (customer_id, order_number, fulfillment_type, pickup_date, pickup_time, delivery_address, delivery_phone, delivery_instructions, subtotal, delivery_fee, bulk_order, free_delivery, loyalty_points_earned, discount_amount, voucher_code, total, payment_method, payment_reference, bank_name, bank_account_name, payment_proof_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $delivery_address = $details['delivery_address'] ?? null;
        $delivery_phone = $details['delivery_phone'] ?? null;
        $delivery_instructions = $details['delivery_instructions'] ?? null;
        $pickup_date = $details['pickup_date'] ?? null;
        $pickup_time = $details['pickup_time'] ?? null;

        // Ensure we have discount fields coming from $details (checkout passes these)
        $final_total = $subtotal + $delivery_fee - ($details['discount_amount'] ?? 0.00);
        $discount_to_save = (float)($details['discount_amount'] ?? 0.00);
        $voucher_code_to_save = $details['voucher_code'] ?? null;

        // Consolidate reference number logic
        $payment_reference = $details['gcash_reference_number'] ?? null;
        $bank_name = ($payment_method === 'bank') ? ($details['bank_name'] ?? null) : null;
        $bank_account_name = ($payment_method === 'bank') ? ($details['bank_account_name'] ?? null) : null;
        $payment_proof_path = ($payment_method === 'bank') ? ($details['payment_proof_path'] ?? null) : null;
        $stmt->bind_param('isssssssddiisdsssssss', $customer_id, $order_number, $fulfillment_type, $pickup_date, $pickup_time, $delivery_address, $delivery_phone, $delivery_instructions, $subtotal, $delivery_fee, $bulk_order, $free_delivery, $loyalty_points, $discount_to_save, $voucher_code_to_save, $final_total, $payment_method, $payment_reference, $bank_name, $bank_account_name, $payment_proof_path);
        $stmt->execute();
        $order_id = $customer_db->insert_id;
        $stmt->close();

        $item_stmt = $customer_db->prepare(
            "INSERT INTO tbl_order_items (order_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($cart_items as $item) {
            $line_total = ((float)$item['unit_price'] * (int)$item['quantity']);
            $item_stmt->bind_param('iisidd', $order_id, $item['id'], $item['name'], $item['quantity'], $item['unit_price'], $line_total);
            $item_stmt->execute();
        }
        $item_stmt->close();

        if ($fulfillment_type === 'delivery') {
            $confirmation_token = bin2hex(random_bytes(32));
            $delivery_stmt = $customer_db->prepare(
                "INSERT INTO tbl_delivery (order_id, delivery_type, address, phone, instructions, status, qr_confirmation_token, created_at) VALUES (?, 'delivery', ?, ?, ?, 'pending', ?, NOW())"
            );
            $delivery_stmt->bind_param('issss', $order_id, $delivery_address, $delivery_phone, $delivery_instructions, $confirmation_token);
            $delivery_stmt->execute();
            $delivery_id = $customer_db->insert_id;
            $delivery_stmt->close();

            send_order_ereceipt($customer_id, $order_id, $delivery_id, $confirmation_token);
        } else {
            // For pickup, we could also generate a token if needed for confirmation at the shop
            $pickup_stmt = $customer_db->prepare(
                "INSERT INTO tbl_delivery (order_id, delivery_type, address, phone, instructions, status, scheduled_at, created_at) VALUES (?, 'pickup', NULL, NULL, NULL, 'pending', CONCAT(?, ' ', ?), NOW())"
            );
            $pickup_stmt->bind_param('iss', $order_id, $pickup_date, $pickup_time);
            $pickup_stmt->execute();
            $pickup_stmt->close();
        }

        $customer_db->query("UPDATE customers SET loyalty_points = loyalty_points + $loyalty_points WHERE id = $customer_id");

        record_order_notification($customer_db, $customer_id, $order_id, $order_number, $subtotal, $fulfillment_type, $bulk_order, $free_delivery);

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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

function send_order_ereceipt(int $customer_id, int $order_id, int $delivery_id, string $token): void {
    global $customer_db;
    $customer = get_customer_by_id($customer_id);
    $order = get_order_by_id($order_id, $customer_id);
    $order_items = get_order_items($order_id);

    if (!$customer || !$order) {
        error_log("Could not send e-receipt: customer or order not found for order_id: $order_id");
        return;
    }

    // Generate QR Code
    $qr_data = json_encode(['delivery_id' => $delivery_id, 'token' => $token]);
    $qrCode = QrCode::create($qr_data);
    $writer = new PngWriter();
    $qr_code_uri = $writer->write($qrCode)->getDataUri();

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
            <hr>
            <div style='text-align: center; margin-top: 20px;'>
                <h3>Delivery Confirmation QR Code</h3>
                <p>For rider to scan upon delivery.</p>
                <img src='" . $qr_code_uri . "' alt='QR Code for delivery confirmation' style='width: 200px; height: 200px;'/>
            </div>
            <p style='text-align: center; font-size: 0.9em; color: #777; margin-top: 20px;'>Thank you for shopping with us!</p>
        </div>
    ";

    // Send Email using PHPMailer
    $mail = new PHPMailer(true);

    try {
        //Server settings - replace with your SMTP details
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Set the SMTP server to send through
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your_email@gmail.com'; // SMTP username
        $mail->Password   = 'your_gmail_app_password'; // SMTP password or App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        //Recipients
        $mail->setFrom('no-reply@your-domain.com', htmlspecialchars(SYSTEM_NAME));
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
