<?php
// The Composer autoloader is now handled by a central bootstrap file.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// Now that vendor classes are available, we can include our other files.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

if (!function_exists('product_image_src')) {
    /**
     * A product/variant image_url is either a full external URL (existing
     * hotlinked catalog images, e.g. https://www.purina.ph/...) or a local
     * path returned by save_uploaded_image() (e.g. /uploads/products/xxx.jpg).
     * Only the local kind needs BASE_URL prepended - prefixing an already-
     * absolute URL would break it.
     */
    function product_image_src(?string $url): string {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        return preg_match('#^https?://#i', $url) ? $url : BASE_URL . $url;
    }
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

        "CREATE TABLE IF NOT EXISTS tbl_product_variants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            size VARCHAR(100) NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            image_url VARCHAR(255) DEFAULT NULL,
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

        // Optional photos a customer attaches to a review (files live in uploads/reviews/).
        "CREATE TABLE IF NOT EXISTS tbl_review_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            review_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (review_id) REFERENCES tbl_product_reviews(id) ON DELETE CASCADE,
            INDEX (review_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // The shop's (admin's) public reply to a customer review. One reply per review.
        "CREATE TABLE IF NOT EXISTS tbl_review_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            review_id INT NOT NULL,
            admin_id INT DEFAULT NULL,
            reply_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_review_reply (review_id),
            FOREIGN KEY (review_id) REFERENCES tbl_product_reviews(id) ON DELETE CASCADE
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
    // The order-items row for a variant purchase only ever stored the parent
    // product_id, so stock deduction always fell back to tbl_product_inventory
    // instead of the actual tbl_product_variants row — the variant's own stock
    // count never moved. This column lets create_customer_order() keep the
    // cart's variant_id all the way through to deduct_product_stock().
    $conn->query("ALTER TABLE tbl_order_items ADD COLUMN IF NOT EXISTS variant_id INT NULL DEFAULT NULL AFTER product_id");
    // Weight tracking: lets "Online Purchase" loyalty transactions report a real
    // quantity_kg instead of a hardcoded 0.00. weight_kg lives on the product and
    // (optionally, per-size) the variant; tbl_order_items snapshots the resulting
    // line weight (unit weight × quantity) at the moment the order is placed.
    $conn->query("ALTER TABLE tbl_product_inventory ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER price");
    $conn->query("ALTER TABLE tbl_product_variants ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER price");
    $conn->query("ALTER TABLE tbl_order_items ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER total_price");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS payment_proof_path VARCHAR(255) DEFAULT NULL AFTER bank_account_name");
    $conn->query("ALTER TABLE tbl_delivery ADD COLUMN IF NOT EXISTS qr_confirmation_token VARCHAR(255) DEFAULT NULL AFTER delivered_at");
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS cancellation_reason TEXT DEFAULT NULL AFTER order_status");
    // Tracks whether product stock has already been deducted for this order, so
    // deduct_order_stock_once() never double-deducts no matter which status
    // transition (GCash/bank "Approve Payment", or reaching "Processing" via the
    // admin/staff status dropdown) ends up triggering it first.
    $conn->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER order_status");

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
                'weight_kg' => (float)($row['weight_kg'] ?? 0),
                'stock' => (int)$row['stock'],
                'category' => $row['category'] ?? 'General',
                'image_url' => product_image_src($row['image_url']),
                'low_stock_threshold' => (int)($row['low_stock_threshold'] ?? 10),
                'stock_alert_level' => $row['stock_alert_level'] ?? 'normal',
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
            $row['image_url'] = product_image_src($row['image_url'] ?? '');
            $variants[] = $row;
        }
        $result->close();
    }
    $stmt->close();
    return $variants;
}

function get_product_stock(int $product_id, ?int $variant_id = null): int {
    global $customer_db;
    if ($variant_id) {
        $stmt = $customer_db->prepare("SELECT stock FROM tbl_product_variants WHERE id = ? AND product_id = ?");
        $stmt->bind_param('ii', $variant_id, $product_id);
    } else {
        $stmt = $customer_db->prepare("SELECT stock FROM tbl_product_inventory WHERE id = ?");
        $stmt->bind_param('i', $product_id);
    }
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

    if ($success && $affected > 0) {
        // Event-based Low Stock Monitoring: only fires when the stock LEVEL
        // actually crosses a band, not on every unit sold. See evaluate_stock_alert().
        evaluate_stock_alert($customer_db, $variant_id ? 'tbl_product_variants' : 'tbl_product_inventory', $variant_id ?: $product_id);
    }

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

    if ($success && $affected > 0) {
        // Restocking can cross back down through the bands (e.g. out -> normal),
        // which is exactly what resets stock_alert_level so a future sale can
        // re-trigger a fresh alert instead of staying silently stuck.
        evaluate_stock_alert($customer_db, $variant_id ? 'tbl_product_variants' : 'tbl_product_inventory', $variant_id ?: $product_id);
    }

    return $success && $affected > 0;
}

/**
 * Deducts stock for every item in an order — but only once per order, ever.
 *
 * Some orders already have their stock deducted earlier in their lifecycle
 * (a GCash/bank order's "Approve Payment" action deducts stock when the
 * order becomes "confirmed"). Other orders (mainly COD) never go through
 * that action, so nothing deducted their stock at all — which is the bug
 * this closes: whichever status-change handler is the first to call this
 * (normally when an order reaches "processing") does the deduction, and the
 * `tbl_orders.stock_deducted` flag it sets makes every later call for the
 * same order a safe no-op, no matter how many more times the status changes.
 *
 * @return array{ok: bool, error: ?string} `ok` is false only when stock ran
 *         out for one of the items — nothing is deducted for the order in
 *         that case, and the caller should roll back its own transaction.
 */
function deduct_order_stock_once(mysqli $conn, int $order_id): array {
    $lock_stmt = $conn->prepare("SELECT stock_deducted FROM tbl_orders WHERE id = ? FOR UPDATE");
    $lock_stmt->bind_param('i', $order_id);
    $lock_stmt->execute();
    $row = $lock_stmt->get_result()->fetch_assoc();
    $lock_stmt->close();

    if (!$row) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    if ((int)$row['stock_deducted'] === 1) {
        return ['ok' => true, 'error' => null];
    }

    foreach (get_order_items($order_id) as $item) {
        $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
        if (!deduct_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity'])) {
            $name = $item['product_name'] ?? ('#' . (int)$item['product_id']);
            return ['ok' => false, 'error' => "Insufficient stock for product: $name"];
        }
    }

    $mark_stmt = $conn->prepare("UPDATE tbl_orders SET stock_deducted = 1 WHERE id = ?");
    $mark_stmt->bind_param('i', $order_id);
    $mark_stmt->execute();
    $mark_stmt->close();

    return ['ok' => true, 'error' => null];
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
                'weight_kg' => (float)($row['weight_kg'] ?? 0),
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
        'weight_kg' => (float)($product['weight_kg'] ?? 0),
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
        // Store coordinates (Darius Poultry Supply, M. Hizon 1 St, Caloocan)
        $store_lat = 14.652023833372462;
        $store_lon = 120.97593745927361;

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

/**
 * Update delivery address for a PENDING/CONFIRMED/PROCESSING order.
 *
 * ⚠️ CRITICAL: This is the ONLY place where an existing order's delivery fee should be recalculated.
 *
 * This function allows customers to change their delivery address BEFORE the order is fulfilled.
 * When they do, the delivery fee is RECALCULATED based on the new address.
 *
 * Important distinctions:
 * - New orders: delivery_fee is calculated once during checkout and saved as a SNAPSHOT
 * - Existing orders: delivery_fee remains fixed UNLESS the customer explicitly changes the address via this function
 * - Profile changes: updating customer_addresses does NOT affect existing orders
 *
 * @param int $order_id
 * @param int $customer_id
 * @param string $address
 * @param string $phone
 * @param float|null $latitude
 * @param float|null $longitude
 * @return array ['success' => bool, 'message' => string]
 */
function update_customer_order_delivery(int $order_id, int $customer_id, string $address, string $phone, ?float $latitude, ?float $longitude): array {
    global $customer_db;

    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_latitude DECIMAL(10, 8) NULL AFTER delivery_fee");
    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_longitude DECIMAL(11, 8) NULL AFTER delivery_latitude");
    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_distance_km DECIMAL(8, 2) NULL AFTER delivery_longitude");

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

    // --- Delivery fee: mirror the live checkout flow exactly ---------------
    // customer/checkout.php + create_customer_order() ALWAYS snapshot a 0.00
    // delivery fee for delivery orders (see the explicit comment in
    // create_customer_order()). The tiered calculate_delivery_fee() table, and
    // especially its "no coordinates -> return 50.00" fallback, are legacy paths
    // that checkout deliberately bypasses. This function was the ONLY place still
    // calling them, which is why picking a saved address (saved addresses have no
    // lat/lon in the DB) recalculated the fee to a phantom PHP 50.
    //
    // So here we ONLY validate the delivery area, then reuse the same 0.00 fee
    // the checkout flow persists. We never call calculate_delivery_fee() or the
    // slow is_location_in_service_area() reverse-geocode.
    $STORE_LAT = 14.652023833372462;
    $STORE_LON = 120.97593745927361;
    $MAX_DELIVERY_KM = 5.0; // same outer limit the checkout map enforces

    $new_distance_km = null;

    if ($latitude !== null && $longitude !== null) {
        // Coordinate-based area check — identical rule to the checkout map:
        // deliverable within MAX_DELIVERY_KM of the store, otherwise rejected.
        $new_distance_km = calculate_distance_km($STORE_LAT, $STORE_LON, $latitude, $longitude);
        if ($new_distance_km > $MAX_DELIVERY_KM) {
            return ['success' => false, 'message' => 'This location is beyond the supported Caloocan delivery range.'];
        }
    } elseif (!is_delivery_area_allowed($address)) {
        // Saved address with no stored coordinates: fall back to the address-text
        // service-area check so obviously out-of-area addresses are still rejected.
        return ['success' => false, 'message' => 'Please select a saved address inside the supported Caloocan delivery area, or pick the location on the map.'];
    }

    // Same delivery fee the checkout flow persists for every delivery order.
    $delivery_fee = 0.00;

    $discount = (float)$order['discount_amount'];
    $total = max(0, (float)$order['subtotal'] + (float)$order['vat_amount'] + $delivery_fee - $discount);
    $free_delivery = $delivery_fee <= 0 ? 1 : 0;

    $customer_db->begin_transaction();
    try {
        $update_order = $customer_db->prepare(
            "UPDATE tbl_orders
             SET delivery_address = ?, delivery_phone = ?, delivery_fee = ?, delivery_latitude = ?, delivery_longitude = ?, delivery_distance_km = ?, free_delivery = ?, total = ?, updated_at = NOW()
             WHERE id = ? AND customer_id = ? AND order_status IN ('pending', 'confirmed', 'processing')"
        );
        $update_order->bind_param('ssddddidii', $address, $phone, $delivery_fee, $latitude, $longitude, $new_distance_km, $free_delivery, $total, $order_id, $customer_id);
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

    // Buy Now: every checkout-pipeline caller (checkout.php, payment.php,
    // cart_subtotal(), the voucher validator, ...) goes through this one
    // function, so checking the session flag here — instead of in each of
    // them — is what keeps the rest of the persistent cart out of a Buy Now
    // order. tbl_cart itself is never touched by Buy Now. cart.php clears
    // this flag as soon as the customer views their real cart, so it always
    // sees the full tbl_cart again.
    if (!empty($_SESSION['buy_now_item'])) {
        return get_buy_now_cart();
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
                COALESCE(v.weight_kg, p.weight_kg) AS unit_weight_kg,
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
                'size' => $row['size'] ?? null,
                'name' => $row['name'] . (!empty($row['size']) ? ' (' . $row['size'] . ')' : ''),
                'unit_price' => (float)$row['unit_price'],
                'weight_kg' => (float)$row['unit_weight_kg'],
                'quantity' => (int)$row['quantity'],
                'image_url' => $row['image_url'],
            ];
        }

        $stmt->close();
        return $cart;
    }

    return $_SESSION['customer_cart'] ?? [];
}

/**
 * "Buy Now": checkout just this one item, bypassing whatever else is already
 * in the persistent cart. Stored in the session only — tbl_cart is never
 * touched — so it's a one-shot shortcut, not cart state.
 */
function start_buy_now(int $product_id, int $quantity, ?int $variant_id = null): void {
    if (!isset($_SESSION)) {
        session_start();
    }
    $_SESSION['buy_now_item'] = [
        'product_id' => $product_id,
        'variant_id' => $variant_id,
        'quantity' => max(1, $quantity),
    ];
}

function is_buy_now_active(): bool {
    if (!isset($_SESSION)) {
        session_start();
    }
    return !empty($_SESSION['buy_now_item']);
}

function clear_buy_now(): void {
    if (!isset($_SESSION)) {
        session_start();
    }
    unset($_SESSION['buy_now_item']);
}

function update_buy_now_quantity(int $quantity): void {
    if (!isset($_SESSION)) {
        session_start();
    }
    if (!empty($_SESSION['buy_now_item'])) {
        $_SESSION['buy_now_item']['quantity'] = max(1, $quantity);
    }
}

/** Builds the single-item "cart" for the active Buy Now session, in the same shape get_customer_cart() returns. */
function get_buy_now_cart(): array {
    if (!isset($_SESSION)) {
        session_start();
    }
    $item = $_SESSION['buy_now_item'] ?? null;
    if (!$item) {
        return [];
    }

    global $customer_db;
    $product_id = (int) $item['product_id'];
    $variant_id = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
    $quantity = max(1, (int) $item['quantity']);

    $stmt = $customer_db->prepare(
        "SELECT p.id AS product_id, p.name, COALESCE(v.price, p.price) AS unit_price,
                COALESCE(v.weight_kg, p.weight_kg) AS unit_weight_kg, v.size, p.image_url
         FROM tbl_product_inventory p
         LEFT JOIN tbl_product_variants v ON v.id = ?
         WHERE p.id = ?"
    );
    $stmt->bind_param('ii', $variant_id, $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // Product/variant no longer exists - nothing left to buy, leave buy-now mode.
        unset($_SESSION['buy_now_item']);
        return [];
    }

    $cart_key = $product_id . '-' . ($variant_id ?? '0');
    return [
        $cart_key => [
            'id' => $product_id,
            'variant_id' => $variant_id ?? 0,
            'size' => $row['size'] ?? null,
            'name' => $row['name'] . (!empty($row['size']) ? ' (' . $row['size'] . ')' : ''),
            'unit_price' => (float) $row['unit_price'],
            'weight_kg' => (float) $row['unit_weight_kg'],
            'quantity' => $quantity,
            'image_url' => $row['image_url'],
        ],
    ];
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

    // Ensure delivery snapshot columns exist in tbl_orders (migration)
    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_latitude DECIMAL(10, 8) NULL AFTER delivery_fee");
    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_longitude DECIMAL(11, 8) NULL AFTER delivery_latitude");
    $customer_db->query("ALTER TABLE tbl_orders ADD COLUMN IF NOT EXISTS delivery_distance_km DECIMAL(8, 2) NULL AFTER delivery_longitude");

    $subtotal_ex_vat = 0.00;
    $total_items = 0;
    foreach ($cart_items as $item) {
        $subtotal_ex_vat += ((float)$item['unit_price'] * (int)$item['quantity']);
        $total_items += (int)$item['quantity'];
    }

    $vat_rate = 0.12;
    $vat_amount = round($subtotal_ex_vat * $vat_rate, 2);
    $subtotal_inc_vat = $subtotal_ex_vat + $vat_amount;

    // Capture the latitude, longitude, and distance used for THIS order
    $order_latitude = isset($details['latitude']) && is_numeric($details['latitude']) ? (float)$details['latitude'] : null;
    $order_longitude = isset($details['longitude']) && is_numeric($details['longitude']) ? (float)$details['longitude'] : null;
    $order_distance_km = null;
    
    // Calculate distance if coordinates are provided (for snapshot)
    if ($order_latitude !== null && $order_longitude !== null) {
        $store_lat = 14.652023833372462;
        $store_lon = 120.97593745927361;
        $order_distance_km = calculate_distance_km($store_lat, $store_lon, $order_latitude, $order_longitude);
    }

    // Snapshot the fee calculated by the checkout flow. Recalculating here can
    // make the amount shown at checkout differ from the saved order total.
    $delivery_fee = isset($details['delivery_fee']) && is_numeric($details['delivery_fee'])
        ? (float)$details['delivery_fee']
        : 0.00;
    if ($delivery_fee < 0) {
        throw new InvalidArgumentException('Invalid delivery fee.');
    }

    $total = $subtotal_inc_vat + $delivery_fee;
    $discount_amount = 0.00;
    $total_after_discount = $total;
    $voucher_id = null;
    $voucher_code = null;

    // The actual voucher lookup/lock/validation happens INSIDE the order
    // transaction below (see "Authoritative voucher re-validation") — not
    // here — so that locking the voucher row and locking "has this order
    // already consumed it" happen atomically with the order insert itself.
    // Only the raw input is captured at this point.
    $voucher_code_input = trim((string)($details['voucher_code'] ?? ''));

    // --- Final Calculations after Discount ---
    $free_delivery = $delivery_fee <= 0.00 ? 1 : 0;
    $bulk_order = detect_bulk_order($subtotal_inc_vat, $total_items) ? 1 : 0;
    $loyalty_points = max(0, $subtotal_ex_vat / 100);

    $order_number = generate_order_number();
    error_log("DEBUG: Order #{$order_number} - Calculated Loyalty Points: {$loyalty_points} from (Subtotal ex-VAT: {$subtotal_ex_vat})");
    
    $payment_method = (string)($details['payment_method'] ?? 'cod');


    // Authoritative delivery-area check, done BEFORE the transaction starts (it makes an
    // external HTTP call, and a transaction should never sit idle while waiting on that).
    // checkout.php's map already refuses to pin a location outside the service area, but
    // that is only a UI convenience - a direct POST to payment.php could otherwise submit
    // any coordinates. This is the one place that actually decides an order is acceptable.
    if ($fulfillment_type === 'delivery') {
        if ($order_latitude === null || $order_longitude === null) {
            $_SESSION['last_order_error'] = 'A delivery location is required. Please select your address on the map.';
            return null;
        }
        if ($order_distance_km === null || $order_distance_km > 5.0) {
            $_SESSION['last_order_error'] = 'Sorry, that location is outside our delivery service area (Caloocan City, within 5km of our shop).';
            return null;
        }
        // A plain radius also catches slivers of neighboring cities (Quezon City, Malabon,
        // Valenzuela all border Caloocan and can fall within 5km) - is_location_in_service_area()
        // (already in this file, just never wired up) resolves the real city via reverse
        // geocoding instead of a circle. It fails closed on a lookup error, which is fine
        // here since the customer can simply retry - unlike inside a held transaction.
        if (!is_location_in_service_area($order_latitude, $order_longitude)) {
            $_SESSION['last_order_error'] = 'Sorry, we only deliver within Caloocan City (Caloocan, 10th Avenue, Grace Park). Please choose a location inside our service area, or contact us if you believe this is a mistake.';
            return null;
        }
    }

    $customer_db->begin_transaction();
    try {
        // --- Authoritative voucher re-validation (server-side, inside the
        // order transaction) -------------------------------------------------
        // The browser/checkout.php may have already shown a discount preview,
        // but that preview is NEVER trusted for the actual saved amount —
        // $details['discount_amount'] is not read below. This is the one
        // place that decides the real discount:
        //   1. SELECT ... FOR UPDATE locks the voucher row so two orders
        //      racing on the same code can't both apply/consume it.
        //   2. Ownership: if the code is linked to a reward_redemptions row
        //      (i.e. it came from Redeem a Reward), that redemption's
        //      customer_id must match the customer placing THIS order.
        //   3. Channel: an IN_STORE redemption is never written into
        //      tbl_vouchers in the first place (see
        //      modules/customers/reward_redemption.php), so this can never
        //      actually match one — this check exists as the explicit,
        //      defense-in-depth server-side guard the checkout flow is
        //      required to make, independent of that architectural fact.
        //   4. Usual expiry / minimum-spend / usage-limit checks.
        $discount_amount = 0.00;
        $voucher_id = null;
        $voucher_code = null;

        if ($voucher_code_input !== '') {
            $voucher_stmt = $customer_db->prepare(
                "SELECT v.id, v.code, v.discount_type, v.discount_value, v.min_order_amount,
                        v.usage_limit, v.used_count, v.expires_at,
                        rr.customer_id AS redemption_customer_id, rr.channel AS redemption_channel
                 FROM tbl_vouchers v
                 LEFT JOIN reward_redemptions rr ON rr.card_number = v.code
                 WHERE v.code = ? AND v.active = 1
                 FOR UPDATE"
            );
            $voucher_stmt->bind_param('s', $voucher_code_input);
            $voucher_stmt->execute();
            $voucher = $voucher_stmt->get_result()->fetch_assoc();
            $voucher_stmt->close();

            // Ownership — only enforced when the code is actually linked to a
            // reward redemption; a voucher created directly by an admin (not
            // via Redeem a Reward) has no owner to check.
            if ($voucher && $voucher['redemption_customer_id'] !== null
                && (int)$voucher['redemption_customer_id'] !== $customer_id) {
                $voucher = null;
            }
            // Channel — reject anything explicitly recorded as IN_STORE.
            if ($voucher && $voucher['redemption_channel'] !== null
                && strtoupper((string)$voucher['redemption_channel']) !== 'ONLINE') {
                $voucher = null;
            }
            if ($voucher && !empty($voucher['expires_at'])
                && new DateTime((string)$voucher['expires_at']) < new DateTime('now')) {
                $voucher = null;
            }
            if ($voucher && $voucher['min_order_amount'] !== null
                && $subtotal_inc_vat < (float)$voucher['min_order_amount']) {
                $voucher = null;
            }
            if ($voucher && $voucher['usage_limit'] !== null) {
                $limit = (int)$voucher['usage_limit'];
                $used = (int)$voucher['used_count'];
                if ($limit > 0 && $used >= $limit) {
                    $voucher = null;
                }
            }

            if ($voucher) {
                $discount_amount = $voucher['discount_type'] === 'percent'
                    ? round($total * ((float)$voucher['discount_value'] / 100), 2)
                    : round((float)$voucher['discount_value'], 2);
                if ($discount_amount < 0) {
                    $discount_amount = 0.00;
                }
                if ($discount_amount > $total) {
                    $discount_amount = $total;
                }
                $voucher_id = (int)$voucher['id'];
                $voucher_code = $voucher['code'];
            }
        }

        $stmt = $customer_db->prepare(
            "INSERT INTO tbl_orders (customer_id, order_number, fulfillment_type, pickup_date, pickup_time, delivery_address, order_notes, delivery_phone, delivery_instructions, subtotal, vat_amount, delivery_fee, delivery_latitude, delivery_longitude, delivery_distance_km, bulk_order, free_delivery, loyalty_points_earned, discount_amount, voucher_code, total, payment_method, payment_reference, bank_name, bank_account_name, payment_proof_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $delivery_address = $details['delivery_address'] ?? null;
        $order_notes = $details['order_notes'] ?? null;
        $delivery_phone = $details['delivery_phone'] ?? null;
        $delivery_instructions = $details['delivery_instructions'] ?? null;
        $pickup_date = $details['pickup_date'] ?? null;
        $pickup_time = $details['pickup_time'] ?? null;

        // Discount actually saved comes ONLY from the re-validation above —
        // never from $details['discount_amount'].
        $final_total = $subtotal_inc_vat + $delivery_fee - $discount_amount;
        $discount_to_save = $discount_amount;
        $voucher_code_to_save = $voucher_code;

        // Consolidate reference number logic
        $payment_reference = $details['gcash_reference_number'] ?? $details['bank_reference_number'] ?? null;
        $bank_name = ($payment_method === 'bank') ? ($details['bank_name'] ?? null) : null;
        $bank_account_name = ($payment_method === 'bank') ? ($details['bank_account_name'] ?? null) : null;
        // Proof-of-payment applies to both Bank Transfer (existing) and
        // GCash (the receipt screenshot uploaded at checkout) — both are
        // validated/uploaded the same way in checkout.php before this
        // function is ever called, into the same tbl_orders.payment_proof_path
        // column. Any other payment method never carries a proof file.
        $payment_proof_path = in_array($payment_method, ['bank', 'gcash'], true) ? ($details['payment_proof_path'] ?? null) : null;
        $stmt->bind_param('issssssssddddddiiddsdsssss', $customer_id, $order_number, $fulfillment_type, $pickup_date, $pickup_time, $delivery_address, $order_notes, $delivery_phone, $delivery_instructions, $subtotal_ex_vat, $vat_amount, $delivery_fee, $order_latitude, $order_longitude, $order_distance_km, $bulk_order, $free_delivery, $loyalty_points, $discount_to_save, $voucher_code_to_save, $final_total, $payment_method, $payment_reference, $bank_name, $bank_account_name, $payment_proof_path);
        $stmt->execute();
        $order_id = $customer_db->insert_id;
        $stmt->close();

        // Consume the voucher (and close out the reward redemption it came
        // from, if any) atomically with the order — same transaction, so a
        // failure anywhere below rolls this back too and the code stays
        // usable. This is what actually prevents the same code being
        // applied to a second order (see TEST: voucher already used).
        if ($voucher_id !== null) {
            $consume_stmt = $customer_db->prepare("UPDATE tbl_vouchers SET used_count = used_count + 1 WHERE id = ?");
            $consume_stmt->bind_param('i', $voucher_id);
            $consume_stmt->execute();
            $consume_stmt->close();

            $mark_used_stmt = $customer_db->prepare("UPDATE reward_redemptions SET status = 'USED' WHERE card_number = ?");
            $mark_used_stmt->bind_param('s', $voucher_code);
            $mark_used_stmt->execute();
            $mark_used_stmt->close();
        }

        $item_stmt = $customer_db->prepare(
            "INSERT INTO tbl_order_items (order_id, product_id, variant_id, product_name, variant_size, quantity, unit_price, total_price, weight_kg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($cart_items as $item) {
            $line_total = ((float)$item['unit_price'] * (int)$item['quantity']);
            $line_weight_kg = ((float)($item['weight_kg'] ?? 0) * (int)$item['quantity']);
            // Cart items carry variant_id as 0 when there's no variant (see
            // get_customer_cart()) — normalize that to NULL for the DB/deduction logic.
            $variant_id = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $variant_size = $item['size'] ?? null;
            $product_name = $item['name'];
            $item_stmt->bind_param('iiissiddd', $order_id, $item['id'], $variant_id, $product_name, $variant_size, $item['quantity'], $item['unit_price'], $line_total, $line_weight_kg);
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
 * Ensures a `tbl_delivery` row (and a live `qr_confirmation_token`) exists for
 * the given order, creating/repairing it if necessary. This is the single
 * source of truth for the QR payload shown on customer/orders.php AND emailed
 * as the e-receipt — both read the token through this same function so they
 * always show the exact same Delivery Confirmation QR for a given order.
 *
 * Extracted from customer/orders.php (previously inlined there) so the
 * e-receipt flow can reuse it instead of duplicating the token logic.
 *
 * @param array $order The order row (must at least contain 'id', 'fulfillment_type', 'order_status').
 * @return array|null   The delivery details (id, qr_confirmation_token), or null if not applicable.
 */
function ensure_order_delivery_token(array $order): ?array {
    global $customer_db;

    if (!$order || ($order['order_status'] ?? '') === 'cancelled') {
        return null;
    }

    $delivery_details = get_delivery_details_by_order_id((int)$order['id']);

    if (!$delivery_details) {
        // Create the shared delivery record used by the admin and rider flows.
        $token = bin2hex(random_bytes(16));
        $insert_stmt = $customer_db->prepare("INSERT INTO tbl_delivery (order_id, delivery_type, status, qr_confirmation_token) VALUES (?, ?, 'pending', ?)");
        if ($insert_stmt) {
            $delivery_type = $order['fulfillment_type'] ?? 'delivery';
            $insert_stmt->bind_param("iss", $order['id'], $delivery_type, $token);
            $insert_stmt->execute();
            $insert_stmt->close();
            // Refresh details
            $delivery_details = get_delivery_details_by_order_id((int)$order['id']);
        }
    } elseif (empty($delivery_details['qr_confirmation_token'])) {
        // Auto-generate token if record exists but token is missing
        $token = bin2hex(random_bytes(16));
        $update_stmt = $customer_db->prepare("UPDATE tbl_delivery SET qr_confirmation_token = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param("si", $token, $delivery_details['id']);
            $update_stmt->execute();
            $update_stmt->close();
            $delivery_details['qr_confirmation_token'] = $token;
        }
    }

    return $delivery_details;
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
function submit_product_review(array $data, ?int &$review_id = null): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("INSERT INTO tbl_product_reviews (order_id, product_id, customer_id, rating, review_text, is_approved) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bind_param(
        'iiiis',
        $data['order_id'],
        $data['product_id'],
        $data['customer_id'],
        $data['rating'],
        $data['review_text']
    );
    $success = $stmt->execute();
    $review_id = $success ? (int) $customer_db->insert_id : null;
    $stmt->close();
    return $success;
}

const REVIEW_MAX_IMAGES = 5;
const REVIEW_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/**
 * Validates and stores the photos posted in $_FILES['review_images'].
 * Returns project-relative paths (uploads/reviews/...). Throws an Exception with
 * a customer-friendly message when a file is not a real image, is too large, or
 * there are too many. Anything already saved is removed again on failure.
 *
 * @param array|null $files the $_FILES['review_images'] entry
 * @return string[]
 */
function save_review_images(?array $files): array {
    if (!$files || !isset($files['name'])) {
        return [];
    }
    $names = (array) $files['name'];
    $count = count($names);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $dir = __DIR__ . '/../../uploads/reviews/';
    $saved = [];

    try {
        $picked = 0;
        for ($i = 0; $i < $count; $i++) {
            $err = (int) ((array) $files['error'])[$i];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (++$picked > REVIEW_MAX_IMAGES) {
                throw new Exception('You can attach up to ' . REVIEW_MAX_IMAGES . ' photos.');
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception('Each photo must be 5MB or smaller.');
            }
            $tmp = ((array) $files['tmp_name'])[$i];
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                throw new Exception('A photo could not be uploaded. Please try again.');
            }
            if (filesize($tmp) > REVIEW_MAX_IMAGE_BYTES) {
                throw new Exception('Each photo must be 5MB or smaller.');
            }
            $probe = @getimagesize($tmp);
            if ($probe === false || !isset($allowed[$probe[2]])) {
                throw new Exception('Only JPG, PNG or WEBP photos are allowed.');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('Could not store the photo. Please try again.');
            }
            $filename = 'review_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$probe[2]];
            if (!move_uploaded_file($tmp, $dir . $filename)) {
                throw new Exception('Could not store the photo. Please try again.');
            }
            $saved[] = 'uploads/reviews/' . $filename;
        }
    } catch (Exception $e) {
        delete_review_image_files($saved);
        throw $e;
    }
    return $saved;
}

function delete_review_image_files(array $paths): void {
    foreach ($paths as $path) {
        $path = (string) $path;
        if (strpos($path, 'uploads/reviews/') === 0 && strpos($path, '..') === false) {
            @unlink(__DIR__ . '/../../' . $path);
        }
    }
}

function add_review_images(int $review_id, array $paths): void {
    global $customer_db;
    if (!$paths) {
        return;
    }
    $stmt = $customer_db->prepare("INSERT INTO tbl_review_images (review_id, image_path) VALUES (?, ?)");
    foreach ($paths as $path) {
        $stmt->bind_param('is', $review_id, $path);
        $stmt->execute();
    }
    $stmt->close();
}

/** Adds an `images` list (absolute URLs) to each review row. */
function attach_review_images(array $reviews): array {
    global $customer_db;
    foreach ($reviews as &$r) {
        $r['images'] = [];
    }
    unset($r);
    if (!$reviews) {
        return $reviews;
    }
    $ids = array_map(static fn($r) => (int) $r['id'], $reviews);
    $res = $customer_db->query(
        "SELECT review_id, image_path FROM tbl_review_images WHERE review_id IN (" . implode(',', $ids) . ") ORDER BY id"
    );
    $by_review = [];
    while ($res && ($img = $res->fetch_assoc())) {
        $by_review[(int) $img['review_id']][] = rtrim(BASE_URL, '/') . '/' . ltrim($img['image_path'], '/');
    }
    foreach ($reviews as &$r) {
        $r['images'] = $by_review[(int) $r['id']] ?? [];
    }
    unset($r);
    return $reviews;
}

/** Adds `reply` (['text', 'created_at', 'updated_at'] or null) to each review row. */
function attach_review_replies(array $reviews): array {
    global $customer_db;
    foreach ($reviews as &$r) {
        $r['reply'] = null;
    }
    unset($r);
    if (!$reviews) {
        return $reviews;
    }
    $ids = array_map(static fn($r) => (int) $r['id'], $reviews);
    $res = $customer_db->query(
        "SELECT review_id, reply_text, created_at, updated_at FROM tbl_review_replies WHERE review_id IN (" . implode(',', $ids) . ")"
    );
    $by_review = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $by_review[(int) $row['review_id']] = [
            'text'       => (string) $row['reply_text'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
    foreach ($reviews as &$r) {
        $r['reply'] = $by_review[(int) $r['id']] ?? null;
    }
    unset($r);
    return $reviews;
}

/**
 * Creates or updates the shop's reply to a review.
 * Returns 'created', 'updated', or false (empty text / unknown review / DB error).
 */
function save_review_reply(int $review_id, int $admin_id, string $text) {
    global $customer_db;
    $text = trim($text);
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 1000) : substr($text, 0, 1000);
    if ($review_id <= 0 || $text === '') {
        return false;
    }
    $check = $customer_db->prepare("SELECT id FROM tbl_product_reviews WHERE id = ?");
    $check->bind_param('i', $review_id);
    $check->execute();
    $exists = (bool) $check->get_result()->fetch_assoc();
    $check->close();
    if (!$exists) {
        return false;
    }
    $had = $customer_db->prepare("SELECT id FROM tbl_review_replies WHERE review_id = ?");
    $had->bind_param('i', $review_id);
    $had->execute();
    $is_update = (bool) $had->get_result()->fetch_assoc();
    $had->close();

    $admin = $admin_id > 0 ? $admin_id : null;
    $stmt = $customer_db->prepare(
        "INSERT INTO tbl_review_replies (review_id, admin_id, reply_text) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE reply_text = VALUES(reply_text), admin_id = VALUES(admin_id), updated_at = NOW()"
    );
    $stmt->bind_param('iis', $review_id, $admin, $text);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ($is_update ? 'updated' : 'created') : false;
}

function delete_review_reply(int $review_id): bool {
    global $customer_db;
    $stmt = $customer_db->prepare("DELETE FROM tbl_review_replies WHERE review_id = ?");
    $stmt->bind_param('i', $review_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** "Maria Santos" -> "M*****s" (public display name, as on marketplace review lists). */
function mask_review_name(?string $name): string {
    $name = preg_replace('/\s+/u', '', trim((string) $name));
    if ($name === '') {
        return 'Customer';
    }
    $len = mb_strlen($name);
    if ($len <= 2) {
        return mb_substr($name, 0, 1) . '*****';
    }
    return mb_substr($name, 0, 1) . '*****' . mb_substr($name, -1);
}

/**
 * Rating summary for a list of reviews: average, per-star counts, and how many
 * have a written comment / at least one photo. Pure presentation math over rows
 * that were already loaded; nothing is stored.
 */
function summarize_reviews(array $reviews): array {
    $stars = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $sum = 0;
    $with_comments = 0;
    $with_media = 0;
    foreach ($reviews as $r) {
        $rating = max(1, min(5, (int) $r['rating']));
        $stars[$rating]++;
        $sum += $rating;
        if (trim((string) ($r['review_text'] ?? '')) !== '') {
            $with_comments++;
        }
        if (!empty($r['images'])) {
            $with_media++;
        }
    }
    $count = count($reviews);
    return [
        'avg' => $count ? $sum / $count : 0.0,
        'count' => $count,
        'stars' => $stars,
        'with_comments' => $with_comments,
        'with_media' => $with_media,
    ];
}

/** SQL fragment: the variation(s) the customer actually ordered for this review's product. */
const REVIEW_VARIATION_SQL = "(SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(oi.variant_size), '') SEPARATOR ', ')
                              FROM tbl_order_items oi
                              WHERE oi.order_id = r.order_id AND oi.product_id = r.product_id) AS variation";

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
    $sql = "SELECT r.*, c.name as customer_name, " . REVIEW_VARIATION_SQL . "
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
    return attach_review_replies(attach_review_images($reviews));
}

/**
 * Get all reviews for the admin panel.
 *
 * @return array
 */
function get_all_reviews(): array {
    global $customer_db;
    $reviews = [];
    $sql = "SELECT r.*, p.name as product_name, p.image_url as product_image, c.name as customer_name, " . REVIEW_VARIATION_SQL . "
            FROM tbl_product_reviews r
            JOIN tbl_product_inventory p ON r.product_id = p.id
            JOIN customers c ON r.customer_id = c.id
            ORDER BY r.created_at DESC";
    $result = $customer_db->query($sql);
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    return attach_review_replies(attach_review_images($reviews));
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
    // Remember the photo files first: the rows are removed by ON DELETE CASCADE.
    $files = [];
    $img_stmt = $customer_db->prepare("SELECT image_path FROM tbl_review_images WHERE review_id = ?");
    if ($img_stmt) {
        $img_stmt->bind_param('i', $review_id);
        $img_stmt->execute();
        $img_res = $img_stmt->get_result();
        while ($img = $img_res->fetch_assoc()) {
            $files[] = $img['image_path'];
        }
        $img_stmt->close();
    }
    $stmt = $customer_db->prepare("DELETE FROM tbl_product_reviews WHERE id = ?");
    $stmt->bind_param('i', $review_id);
    $success = $stmt->execute();
    $stmt->close();
    if ($success) {
        delete_review_image_files($files);
    }
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