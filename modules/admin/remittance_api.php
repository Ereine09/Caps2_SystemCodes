<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    // --- Authentication ---
    $token = getJWTFromCookie();
    if (!$token || !($payload = verifyJWT($token)) || !in_array($payload['role'], ['admin', 'staff'])) {
        throw new Exception('Unauthorized access.');
    }
    $staff_user_id = (int)$payload['user_id'];
    ensure_remittance_schema($conn);

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_pending_remittances':
            // Finds all riders who have unremitted completed COD orders (payment_settled = 0)
            $query = "
                SELECT
                    r.id AS rider_id,
                    u.id AS user_id,
                    u.username,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS rider_name,
                    COALESCE(SUM(o.total), 0) AS total_unremitted_cod   
                FROM riders r
                JOIN users u ON r.user_id = u.id
                JOIN tbl_orders o ON r.id = o.rider_id
                WHERE o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                GROUP BY r.id, u.id, u.username, rider_name
                HAVING total_unremitted_cod > 0
                ORDER BY rider_name;
            ";
            $result = $conn->query($query);
            $remittances = $result->fetch_all(MYSQLI_ASSOC);

            $response['success'] = true;
            $response['message'] = 'Pending remittances fetched.';
            $response['data'] = $remittances;
            break;

        case 'get_remittance_details':
            $rider_id = (int)($_GET['rider_id'] ?? 0);
            if ($rider_id <= 0) {
                throw new Exception('Invalid Rider ID.');
            }

            // Fetches specific unremitted COD orders for this rider
            $query = "
                SELECT
                    o.id AS order_id,
                    o.order_number,
                    o.total,
                    o.created_at
                FROM tbl_orders o
                WHERE o.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                ORDER BY o.created_at ASC;
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $rider_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $orders = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Remittance details fetched.';
            $response['data'] = $orders;
            break;

        case 'approve_remittance':
            $input = json_decode(file_get_contents('php://input'), true);
            $rider_id = (int)($input['rider_id'] ?? 0);
            $remitted_amount = (float)($input['amount'] ?? 0);
            $order_ids = $input['order_ids'] ?? [];

            if ($rider_id <= 0 || $remitted_amount <= 0 || empty($order_ids)) {
                throw new Exception('Invalid data for approval.');
            }

            $order_ids = array_values(array_unique(array_map('intval', $order_ids)));
            $order_ids = array_values(array_filter($order_ids, static fn($order_id) => $order_id > 0));
            if (empty($order_ids)) {
                throw new Exception('No valid orders supplied for approval.');
            }

            // Get user_id corresponding to rider_id for tbl_rider_remittances lookup
            $user_stmt = $conn->prepare("SELECT user_id FROM riders WHERE id = ? LIMIT 1");
            $user_stmt->bind_param('i', $rider_id);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();
            $rider_user_id = $user_res['user_id'] ?? $rider_id;

            $conn->begin_transaction();

            try {
                $order_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                $eligible_stmt = $conn->prepare(
                    "SELECT id, total FROM tbl_orders
                     WHERE rider_id = ? AND payment_method = 'cod'
                       AND order_status = 'completed' AND payment_settled = 0
                       AND id IN ($order_placeholders)"
                );
                $eligible_types = 'i' . str_repeat('i', count($order_ids));
                $eligible_params = array_merge([$rider_id], $order_ids);
                $eligible_stmt->bind_param($eligible_types, ...$eligible_params);
                $eligible_stmt->execute();
                $eligible_rows = $eligible_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $eligible_stmt->close();

                if (count($eligible_rows) !== count($order_ids)) {
                    throw new Exception('One or more orders are no longer eligible for approval.');
                }
                $eligible_total = array_sum(array_map(static fn($row) => (float)$row['total'], $eligible_rows));
                if (abs($eligible_total - $remitted_amount) > 0.01) {
                    throw new Exception('Approval amount does not match the selected COD orders.');
                }

                // 1. Look up any pending remittance submitted by this rider
                $stmt_find = $conn->prepare(
                    "SELECT id FROM tbl_rider_remittances 
                     WHERE (rider_id = ? OR rider_id = ?) AND status = 'pending' 
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt_find->bind_param('ii', $rider_id, $rider_user_id);
                $stmt_find->execute();
                $found = $stmt_find->get_result()->fetch_assoc();
                $stmt_find->close();

                if ($found) {
                    $remittance_id = (int)$found['id'];
                    // Approve the rider's pending submission
                    $stmt1 = $conn->prepare(
                        "UPDATE tbl_rider_remittances 
                         SET status = 'approved', processed_by_user_id = ?, processed_at = NOW() 
                         WHERE id = ?"
                    );
                    $stmt1->bind_param('ii', $staff_user_id, $remittance_id);
                    $stmt1->execute();
                    $stmt1->close();
                } else {
                    // Fallback: No pending submission exists, insert an approved record
                    $stmt_ins = $conn->prepare(
                        "INSERT INTO tbl_rider_remittances (rider_id, amount, reference_number, status, processed_by_user_id, processed_at) 
                         VALUES (?, ?, NULL, 'approved', ?, NOW())"
                    );
                    $stmt_ins->bind_param('idii', $rider_user_id, $remitted_amount, $staff_user_id);
                    $stmt_ins->execute();
                    $remittance_id = $conn->insert_id;
                    $stmt_ins->close();
                }

                // 2. NOW mark the orders as payment_settled = 1 to credit the rider's balance
                $order_ids_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                $stmt2 = $conn->prepare("UPDATE tbl_orders SET payment_settled = 1 WHERE rider_id = ? AND payment_method = 'cod' AND order_status = 'completed' AND payment_settled = 0 AND id IN ($order_ids_placeholders)");
                $types = 'i' . str_repeat('i', count($order_ids));
                $update_params = array_merge([$rider_id], $order_ids);
                $stmt2->bind_param($types, ...$update_params);
                $stmt2->execute();
                $stmt2->close();

                // 3. Link orders to remittance items table
                $conn->query("
                    CREATE TABLE IF NOT EXISTS tbl_rider_remittance_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        remittance_id INT NOT NULL,
                        order_id INT NOT NULL,
                        FOREIGN KEY (remittance_id) REFERENCES tbl_rider_remittances(id) ON DELETE CASCADE,
                        FOREIGN KEY (order_id) REFERENCES tbl_orders(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                if ($remittance_id > 0) {
                    $stmt3 = $conn->prepare("INSERT INTO tbl_rider_remittance_items (remittance_id, order_id) VALUES (?, ?)");
                    foreach ($order_ids as $order_id) {
                        $stmt3->bind_param('ii', $remittance_id, $order_id);
                        $stmt3->execute();
                    }
                    $stmt3->close();
                }

                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Remittance approved! Orders marked as settled and balance credited.';

            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() === 1062 && strpos($e->getMessage(), 'uniq_remittance_order') !== false) {
                    throw new Exception('One of the selected orders is already included in this remittance.');
                }
                throw $e;
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception('Database transaction failed: ' . $e->getMessage());
            }
            break;

        case 'reject_remittance':
            $input = json_decode(file_get_contents('php://input'), true);
            $rider_id = (int)($input['rider_id'] ?? 0);
            $notes = trim($input['notes'] ?? '');

            if ($rider_id <= 0 || empty($notes)) {
                throw new Exception('Rider ID and rejection reason are required.');
            }

            // Get user_id corresponding to rider_id
            $user_stmt = $conn->prepare("SELECT user_id FROM riders WHERE id = ? LIMIT 1");
            $user_stmt->bind_param('i', $rider_id);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();
            $rider_user_id = $user_res['user_id'] ?? $rider_id;

            $stmt = $conn->prepare(
                "UPDATE tbl_rider_remittances 
                 SET status = 'rejected', notes = ?, processed_by_user_id = ?, processed_at = NOW() 
                 WHERE (rider_id = ? OR rider_id = ?) AND status = 'pending'"
            );
            $stmt->bind_param('siii', $notes, $staff_user_id, $rider_id, $rider_user_id);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Remittance rejection logged.';
            $response['data'] = null;
            break;

        default:
            throw new Exception('Invalid action specified.');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);