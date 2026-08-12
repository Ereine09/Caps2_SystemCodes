<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    $payload = verifyJWT($token);
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }

    $rider_user_id = (int)$payload['user_id'];
    ensureRiderSchema($conn);

    // Get specific riders.id from users.id
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();

    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    }

    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    $action = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'get_my_balance':
            // Query total unremitted COD balance
            $balance_query = "
                SELECT COALESCE(SUM(o.total), 0) AS total_unremitted_cod
                FROM tbl_orders o
                WHERE o.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0;
            ";
            $stmt_balance = $conn->prepare($balance_query);
            $stmt_balance->bind_param('i', $rider_id);
            $stmt_balance->execute();
            $balance_result = $stmt_balance->get_result()->fetch_assoc();
            $stmt_balance->close();

            // Query individual unremitted COD orders
            $orders_query = "
                SELECT o.id AS order_id, o.order_number, o.total, o.created_at
                FROM tbl_orders o
                WHERE o.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                ORDER BY o.created_at ASC;
            ";
            $stmt_orders = $conn->prepare($orders_query);
            $stmt_orders->bind_param('i', $rider_id);
            $stmt_orders->execute();
            $orders = $stmt_orders->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_orders->close();

            $response['success'] = true;
            $response['message'] = 'Balance fetched successfully.';
            $response['data'] = array_merge($balance_result, ['orders' => $orders]);
            break;

        case 'submit_remittance':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('This action requires a POST request.');
            }

            $remitted_amount = (float)($input['amount'] ?? 0);
            $reference_number = trim((string)($input['reference_number'] ?? ''));
            $order_ids = $input['order_ids'] ?? [];

            if ($remitted_amount <= 0) throw new Exception('Invalid remittance amount.');
            if (empty($reference_number)) throw new Exception('Reference number is required.');
            if (empty($order_ids) || !is_array($order_ids)) throw new Exception('No orders specified for remittance.');

            $conn->begin_transaction();
            try {
                // Auto-fix missing columns dynamically if old schema exists
                $check_ref = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances` LIKE 'reference_number'");
                if ($check_ref && $check_ref->num_rows === 0) {
                    $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `amount`");
                }

                $check_remitted_at = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances` LIKE 'remitted_at'");
                if ($check_remitted_at && $check_remitted_at->num_rows === 0) {
                    $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `remitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                }

                $check_status = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances` LIKE 'status'");
                if ($check_status && $check_status->num_rows === 0) {
                    $conn->query("ALTER TABLE `tbl_rider_remittances` ADD COLUMN `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
                }

                // --- FIX: Insert the correct rider_id from the riders table ---
                $stmt_remit = $conn->prepare(
                    "INSERT INTO tbl_rider_remittances (rider_id, amount, reference_number, status, remitted_at) 
                     VALUES (?, ?, ?, 'pending', NOW())"
                );
                $stmt_remit->bind_param('ids', $rider_id, $remitted_amount, $reference_number);
                $stmt_remit->execute();
                $remittance_id = $conn->insert_id;
                $stmt_remit->close();

                // Link the submitted orders to this remittance record
                $conn->query("
                    CREATE TABLE IF NOT EXISTS tbl_rider_remittance_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        remittance_id INT NOT NULL,
                        order_id INT NOT NULL,
                        FOREIGN KEY (remittance_id) REFERENCES tbl_rider_remittances(id) ON DELETE CASCADE,
                        FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $stmt_item = $conn->prepare("INSERT INTO tbl_rider_remittance_items (remittance_id, order_id) VALUES (?, ?)");
                foreach ($order_ids as $order_id) {
                    $order_id = (int)$order_id;
                    if ($order_id <= 0) continue;
                    $stmt_item->bind_param('ii', $remittance_id, $order_id);
                    $stmt_item->execute();
                }
                $stmt_item->close();

                $conn->commit();
                $response['success'] = true;
                $response['message'] = 'Remittance submitted successfully! Awaiting Admin verification.';
                $response['data'] = ['remittance_id' => $remittance_id];
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        default:
            throw new Exception('Invalid action specified.');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);