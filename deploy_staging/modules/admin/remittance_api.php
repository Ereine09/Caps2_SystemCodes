<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

/** Reads the JSON body of a POST request; mutating actions are POST-only. */
function remit_api_post_body(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RemittanceException('This action requires a POST request.');
    }
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

/** Runs $fn inside a transaction and returns its result. */
function remit_api_transaction(mysqli $conn, callable $fn): array
{
    $conn->begin_transaction();
    try {
        $result = $fn();
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

try {
    // --- Authentication ---
    $token = getJWTFromCookie();
    if (!$token || !($payload = verifyJWT($token)) || !in_array($payload['role'], ['admin', 'staff'])) {
        // 401 + code 'auth' lets the page (assets/js/live-ui.js) send the user to the login page.
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'auth', 'message' => 'Your session has expired. Please log in again.', 'data' => null]);
        exit;
    }
    $staff_user_id = (int)$payload['user_id'];
    ensure_remittance_schema($conn);

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_pending_remittances':
            // Riders holding completed COD orders that have not been submitted or
            // received yet (orders in an open remittance are listed in the table).
            $riders = $conn->query("
                SELECT r.id AS rider_id, u.id AS user_id, u.username,
                       TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS rider_name
                FROM riders r JOIN users u ON r.user_id = u.id
                WHERE EXISTS (SELECT 1 FROM tbl_orders o WHERE o.rider_id = r.id AND o.payment_method = 'cod'
                              AND o.order_status = 'completed' AND o.payment_settled = 0)
                ORDER BY rider_name
            ")->fetch_all(MYSQLI_ASSOC);

            $remittances = [];
            foreach ($riders as $rider) {
                $orders = remittance_eligible_orders($conn, (int)$rider['rider_id']);
                $total = remittance_orders_total($orders);
                if ($total <= 0) {
                    continue;
                }
                $rider['rider_name'] = $rider['rider_name'] !== '' ? $rider['rider_name'] : $rider['username'];
                $rider['total_unremitted_cod'] = $total;
                $rider['order_count'] = count($orders);
                $remittances[] = $rider;
            }

            $response['success'] = true;
            $response['message'] = 'Pending remittances fetched.';
            $response['data'] = $remittances;
            break;

        case 'get_remittance_details':
            // Either a submitted remittance (remittance_id) or a rider's not-yet-submitted
            // COD orders (rider_id = riders.id) that staff will record on their behalf.
            $remittance_id = (int)($_GET['remittance_id'] ?? 0);
            $rider_id = (int)($_GET['rider_id'] ?? 0);

            if ($remittance_id > 0) {
                $stmt = $conn->prepare(
                    "SELECT rr.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_name,
                            u.username AS rider_username,
                            TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS received_by_name,
                            rb.username AS received_by_username,
                            TRIM(CONCAT(COALESCE(pb.first_name,''),' ',COALESCE(pb.last_name,''))) AS recorded_by_name,
                            pb.username AS recorded_by_username
                     FROM tbl_rider_remittances rr
                     JOIN users u ON u.id = rr.rider_id
                     LEFT JOIN users rb ON rb.id = COALESCE(rr.received_by_user_id, rr.processed_by_user_id)
                     LEFT JOIN users pb ON pb.id = rr.processed_by_user_id
                     WHERE rr.id = ? LIMIT 1"
                );
                $stmt->bind_param('i', $remittance_id);
                $stmt->execute();
                $rem = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$rem) {
                    throw new RemittanceException('Remittance not found.');
                }

                $stmt = $conn->prepare(
                    "SELECT o.id AS order_id, o.order_number, o.total, o.created_at, c.name AS customer_name
                     FROM tbl_rider_remittance_items i
                     JOIN tbl_orders o ON o.id = i.order_id
                     LEFT JOIN customers c ON c.id = o.customer_id
                     WHERE i.remittance_id = ? ORDER BY o.created_at ASC"
                );
                $stmt->bind_param('i', $remittance_id);
                $stmt->execute();
                $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $expected = $rem['expected_amount'] !== null ? (float)$rem['expected_amount'] : remittance_orders_total($orders);
                $breakdown = remittance_row_breakdown($rem);
                $received_by = trim((string)$rem['received_by_name']) !== '' ? trim($rem['received_by_name']) : ($rem['received_by_username'] ?? null);
                if (in_array($rem['status'], ['pending', 'rejected'], true)) {
                    $received_by = null; // nobody received a rejected / still-submitted handover
                }
                // Who recorded it in the system (only worth showing when it is someone other than the receiver).
                $recorded_by = trim((string)$rem['recorded_by_name']) !== '' ? trim($rem['recorded_by_name']) : ($rem['recorded_by_username'] ?? null);
                if ($recorded_by === $received_by) {
                    $recorded_by = null;
                }

                $data = [
                    'remittance_id'      => (int)$rem['id'],
                    'number'             => remittance_number((int)$rem['id']),
                    'remittance_type'    => (string)($rem['remittance_type'] ?? 'store'),
                    'type_label'         => remittance_type_label($rem['remittance_type'] ?? 'store'),
                    'transaction_id'     => (string)($rem['transaction_id'] ?? ''),
                    'status'             => $rem['status'],
                    'status_label'       => remittance_status_label($rem['status']),
                    'rider_name'         => trim((string)$rem['rider_name']) !== '' ? trim($rem['rider_name']) : $rem['rider_username'],
                    'submitted_at'       => $rem['requested_at'],
                    'expected'           => $expected,
                    'breakdown'          => $breakdown,
                    'difference'         => round($breakdown['total'] - $expected, 2),
                    'reference_number'   => (string)($rem['reference_number'] ?? ''),
                    'proof_url'          => !empty($rem['proof_image_path']) ? rtrim(BASE_URL, '/') . '/' . ltrim($rem['proof_image_path'], '/') : null,
                    'rider_notes'        => (string)($rem['rider_notes'] ?? ''),
                    'staff_notes'        => (string)($rem['notes'] ?? ''),
                    'discrepancy_reason' => (string)($rem['discrepancy_reason'] ?? ''),
                    'received_by'        => $received_by,
                    'received_at'        => $rem['received_at'] ?? $rem['processed_at'],
                    'recorded_by'        => $recorded_by,
                    'orders'             => $orders,
                ];
            } else {
                if ($rider_id <= 0) {
                    throw new RemittanceException('Invalid Rider ID.');
                }
                $stmt = $conn->prepare(
                    "SELECT TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rider_name, u.username
                     FROM riders r JOIN users u ON u.id = r.user_id WHERE r.id = ? LIMIT 1"
                );
                $stmt->bind_param('i', $rider_id);
                $stmt->execute();
                $rider = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$rider) {
                    throw new RemittanceException('Rider not found.');
                }
                $orders = remittance_eligible_orders($conn, $rider_id);
                $expected = remittance_orders_total($orders);
                $data = [
                    'remittance_id'      => null,
                    'number'             => null,
                    'remittance_type'    => null,
                    'type_label'         => null,
                    'transaction_id'     => null,
                    'status'             => null,
                    'status_label'       => 'Not yet submitted',
                    'rider_id'           => $rider_id,
                    'rider_name'         => $rider['rider_name'] !== '' ? $rider['rider_name'] : $rider['username'],
                    'expected'           => $expected,
                    'breakdown'          => ['cash' => $expected, 'gcash' => 0, 'bank' => 0, 'total' => $expected],
                    'difference'         => 0,
                    'reference_number'   => '',
                    'proof_url'          => null,
                    'rider_notes'        => '',
                    'staff_notes'        => '',
                    'discrepancy_reason' => '',
                    'received_by'        => null,
                    'received_at'        => null,
                    'recorded_by'        => null,
                    'orders'             => $orders,
                ];
            }

            $response['success'] = true;
            $response['message'] = 'Remittance details fetched.';
            $response['data'] = $data;
            break;

        case 'receive_remittance':
            // Staff/admin confirm the physical handover. The expected amount is always
            // recomputed from the orders; verified only when the amounts match.
            $input = remit_api_post_body();
            $remittance_id = (int)($input['remittance_id'] ?? 0);
            $rider_id = (int)($input['rider_id'] ?? 0);

            $result = remit_api_transaction($conn, function () use ($conn, $input, $remittance_id, $rider_id, $staff_user_id) {
                if ($remittance_id <= 0) {
                    // Rider did not submit in the app: record the handover for their
                    // eligible COD orders (all of them, or the selected subset).
                    if ($rider_id <= 0) {
                        throw new RemittanceException('Select a rider or a submitted remittance.');
                    }
                    $stmt = $conn->prepare("SELECT user_id FROM riders WHERE id = ? LIMIT 1");
                    $stmt->bind_param('i', $rider_id);
                    $stmt->execute();
                    $rider = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$rider) {
                        throw new RemittanceException('Rider not found.');
                    }
                    $order_ids = null;
                    if (!empty($input['order_ids']) && is_array($input['order_ids'])) {
                        $order_ids = array_values(array_unique(array_filter(array_map('intval', $input['order_ids']), static fn($id) => $id > 0)));
                    }
                    $orders = remittance_eligible_orders($conn, $rider_id, $order_ids, true);
                    if (!$orders || ($order_ids !== null && count($orders) !== count($order_ids))) {
                        throw new RemittanceException('One or more orders are not eligible for remittance.');
                    }
                    // Staff is recording the handover right now, in person: that IS a Store Remittance by definition.
                    $remittance_id = remittance_insert_pending(
                        $conn, (int)$rider['user_id'], $orders,
                        ['cash' => 0.0, 'gcash' => 0.0, 'bank' => 0.0, 'total' => 0.0], null, null, null, true, 'store'
                    );
                }
                return remittance_apply_receipt($conn, $remittance_id, $staff_user_id, $input);
            });

            remittance_audit_receipt($conn, $staff_user_id, $result);
            remittance_notify_rider($conn, $result);

            $response['success'] = true;
            $response['message'] = $result['status'] === 'approved'
                ? "Remittance {$result['number']} received" . (!empty($result['received_by']) ? " by {$result['received_by']}" : '') . " and verified. Orders marked as settled."
                : "Remittance {$result['number']} received with a discrepancy of " . remittance_peso($result['difference']) . '. It is not verified until resolved.';
            $response['data'] = [
                'id' => $result['id'], 'number' => $result['number'], 'status' => $result['status'],
                'difference' => $result['difference'], 'received_by' => $result['received_by'] ?? null,
                'remittance_type' => $result['remittance_type'] ?? 'store', 'transaction_id' => $result['transaction_id'] ?? '',
            ];
            break;

        case 'resolve_discrepancy':
            $input = remit_api_post_body();
            $remittance_id = (int)($input['remittance_id'] ?? 0);
            $result = remit_api_transaction($conn, function () use ($conn, $input, $remittance_id, $staff_user_id) {
                return remittance_apply_resolution($conn, $remittance_id, $staff_user_id, (string)($input['resolution'] ?? ''));
            });
            remittance_audit($conn, $staff_user_id, 'Remittance Verified',
                "Resolved discrepancy on {$result['number']} from {$result['rider_name']} and verified it. Resolution: {$result['reason']}");
            remittance_notify_rider($conn, $result);

            $response['success'] = true;
            $response['message'] = "Discrepancy on {$result['number']} resolved. Remittance verified and orders settled.";
            $response['data'] = ['id' => $result['id'], 'status' => 'approved'];
            break;

        case 'reject_remittance':
            $input = remit_api_post_body();
            $remittance_id = (int)($input['remittance_id'] ?? 0);
            $result = remit_api_transaction($conn, function () use ($conn, $input, $remittance_id, $staff_user_id) {
                return remittance_apply_rejection($conn, $remittance_id, $staff_user_id, (string)($input['notes'] ?? ''));
            });
            remittance_audit($conn, $staff_user_id, 'Remittance Rejected',
                "Rejected {$result['number']} from {$result['rider_name']}. Reason: {$result['reason']}");
            remittance_notify_rider($conn, $result);

            $response['success'] = true;
            $response['message'] = "Remittance {$result['number']} rejected. The rider can submit a corrected remittance.";
            $response['data'] = null;
            break;

        default:
            throw new RemittanceException('Invalid action specified.');
    }

} catch (RemittanceException $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
} catch (Throwable $e) {
    error_log('remittance_api error: ' . $e->getMessage());
    $response['message'] = 'Something went wrong while processing the remittance. Please try again.';
    http_response_code(500);
}

echo json_encode($response);
