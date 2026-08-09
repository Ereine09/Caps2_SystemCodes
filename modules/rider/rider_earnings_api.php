<?php
/**
 * Rider Earnings API
 *
* Provides Phase 2 Earning and Performance data for the authenticated rider:
 *  - Summary metrics (total earnings, deliveries, tips, delivery fees)
 *  - Earnings history (completed deliveries)
 *  - Remittance history
 *  - Weekly & Monthly chart data for the earnings dashboard
 *
 * All endpoints require a rider JWT.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    require_once __DIR__ . '/../../app/config/config.php';
    require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
    require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
    require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
} catch (Throwable $e) {
    // DB/server unavailable — return clean JSON so the app can show a retry
    // instead of a PHP fatal HTML page (which Dio reads as a network error).
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Service temporarily unavailable. Please check your connection and try again.',
        'data' => null,
    ]);
    exit;
}

try {
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    if (empty($token)) {
        $token = getJWTFromCookie();
    }

    $payload = verifyJWT($token);
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }

    $rider_user_id = (int)$payload['user_id'];
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account.');
    }

    ensureRiderSchema($conn);
    ensure_remittance_schema($conn);

    // Resolve riders.id (used by tbl_orders.rider_id) from users.id (used by remittances)
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

    // --- Ensure a `tip` column exists on tbl_orders (rider earnings source) ---
    $check_tip = $conn->query("SHOW COLUMNS FROM `tbl_orders` LIKE 'tip'");
    if ($check_tip && $check_tip->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_orders` ADD COLUMN `tip` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_fee`");
    }

    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    // === SUMMARY ===
    if ($action === 'get_summary') {
        $summary = $conn->prepare(
            "SELECT
                COUNT(*) AS total_deliveries,
                COALESCE(SUM(COALESCE(o.delivery_fee,0) + COALESCE(o.tip,0)), 0) AS total_earnings,
                COALESCE(SUM(COALESCE(o.tip,0)), 0) AS total_tips,
                COALESCE(SUM(COALESCE(o.delivery_fee,0)), 0) AS total_delivery_fees
FROM tbl_orders o
             WHERE o.rider_id = ?
               AND o.order_status = 'completed'
               AND o.fulfillment_type = 'delivery'"
        );
        $summary->bind_param('i', $rider_id);
        $summary->execute();
        $summary_row = $summary->get_result()->fetch_assoc();
        $summary->close();

        $response['success'] = true;
        $response['message'] = 'Summary fetched successfully.';
        $response['data'] = [
            'total_deliveries' => (int)($summary_row['total_deliveries'] ?? 0),
            'total_earnings' => (float)($summary_row['total_earnings'] ?? 0),
            'total_tips' => (float)($summary_row['total_tips'] ?? 0),
            'total_delivery_fees' => (float)($summary_row['total_delivery_fees'] ?? 0),
        ];
        echo json_encode($response);
        exit;
    }

    // === EARNINGS HISTORY (completed deliveries) ===
    if ($action === 'get_earnings_history') {
        $history = $conn->prepare(
            "SELECT o.id AS order_id, o.order_number, o.delivery_fee, o.tip, o.total,
                    COALESCE(o.delivery_fee,0) + COALESCE(o.tip,0) AS earnings,
                    o.created_at
FROM tbl_orders o
             WHERE o.rider_id = ?
               AND o.order_status = 'completed'
               AND o.fulfillment_type = 'delivery'
             ORDER BY o.created_at DESC
             LIMIT 100"
        );
        $history->bind_param('i', $rider_id);
        $history->execute();
        $rows = $history->get_result()->fetch_all(MYSQLI_ASSOC);
        $history->close();

        $data = array_map(function ($r) {
            return [
                'order_id' => (int)$r['order_id'],
                'order_number' => $r['order_number'],
                'delivery_fee' => (float)$r['delivery_fee'],
                'tip' => (float)$r['tip'],
                'total' => (float)$r['total'],
                'earnings' => (float)$r['earnings'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        $response['success'] = true;
        $response['message'] = 'Earnings history fetched successfully.';
        $response['data'] = ['earnings_history' => $data];
        echo json_encode($response);
        exit;
    }

    // === REMITTANCE HISTORY ===
    if ($action === 'get_remittance_history') {
        $remit = $conn->prepare(
            "SELECT r.id, r.amount, r.status, r.notes, r.requested_at
             FROM tbl_rider_remittances r
             WHERE r.rider_id = ?
             ORDER BY r.requested_at DESC
             LIMIT 100"
        );
        $remit->bind_param('i', $rider_user_id);
        $remit->execute();
        $rows = $remit->get_result()->fetch_all(MYSQLI_ASSOC);
        $remit->close();

        $data = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'amount' => (float)$r['amount'],
                'status' => $r['status'],
                'notes' => $r['notes'],
                'requested_at' => $r['requested_at'],
            ];
        }, $rows);

        $response['success'] = true;
        $response['message'] = 'Remittance history fetched successfully.';
        $response['data'] = ['remittance_history' => $data];
        echo json_encode($response);
        exit;
    }

    // === CHART DATA (weekly / monthly aggregation) ===
    if ($action === 'get_chart') {
        $period = strtolower(trim((string)($_GET['period'] ?? 'monthly')));
        if (!in_array($period, ['weekly', 'monthly'], true)) {
            $period = 'monthly';
        }

        $history = $conn->prepare(
            "SELECT o.total, o.delivery_fee, o.tip, o.created_at
             FROM tbl_orders o
             WHERE o.rider_id = ?
               AND o.order_status = 'completed'
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             ORDER BY o.created_at ASC"
        );
        $history->bind_param('i', $rider_id);
        $history->execute();
        $rows = $history->get_result()->fetch_all(MYSQLI_ASSOC);
        $history->close();

        $labels = [];
        $values = [];

        if ($period === 'weekly') {
            // Last 7 weeks (including current)
            for ($i = 6; $i >= 0; $i--) {
                $start = new DateTime('monday this week');
                $start->modify("-$i week");
                $label = $start->format('M d');
                $labels[] = $label;
                $values[] = 0.0;
            }
            foreach ($rows as $r) {
                $dt = new DateTime($r['created_at']);
                $weekStart = (clone $dt)->modify('monday this week');
                $diff = $weekStart->diff(new DateTime('monday this week'))->days / 7;
                $idx = 6 - (int)round($diff);
                if ($idx >= 0 && $idx < 7) {
                    $values[$idx] += (float)$r['delivery_fee'] + (float)$r['tip'];
                }
            }
        } else {
            // Monthly: last 6 months
            for ($i = 5; $i >= 0; $i--) {
                $m = new DateTime('first day of this month');
                $m->modify("-$i month");
                $labels[] = $m->format('M');
                $values[] = 0.0;
            }
            foreach ($rows as $r) {
                $dt = new DateTime($r['created_at']);
                $now = new DateTime('first day of this month');
                $diff = $dt->diff($now)->days / 30;
                $idx = 5 - (int)round($diff);
                if ($idx >= 0 && $idx < 6) {
                    $values[$idx] += (float)$r['delivery_fee'] + (float)$r['tip'];
                }
            }
        }

        $response['success'] = true;
        $response['message'] = 'Chart data fetched successfully.';
        $response['data'] = [
            'period' => $period,
            'labels' => $labels,
            'values' => array_map('floatval', $values),
        ];
        echo json_encode($response);
        exit;
    }

    // === DEFAULT: full dashboard payload in one call ===
    $summary = $conn->prepare(
        "SELECT
            COUNT(*) AS total_deliveries,
            COALESCE(SUM(COALESCE(o.delivery_fee,0) + COALESCE(o.tip,0)), 0) AS total_earnings,
            COALESCE(SUM(COALESCE(o.tip,0)), 0) AS total_tips,
            COALESCE(SUM(COALESCE(o.delivery_fee,0)), 0) AS total_delivery_fees
FROM tbl_orders o
         WHERE o.rider_id = ? AND o.order_status = 'completed'
           AND o.fulfillment_type = 'delivery'"
    );
    $summary->bind_param('i', $rider_id);
    $summary->execute();
    $summary_row = $summary->get_result()->fetch_assoc();
    $summary->close();

    $history = $conn->prepare(
        "SELECT o.id AS order_id, o.order_number, o.delivery_fee, o.tip, o.total,
                COALESCE(o.delivery_fee,0) + COALESCE(o.tip,0) AS earnings, o.created_at
         FROM tbl_orders o
         WHERE o.rider_id = ? AND o.order_status = 'completed'
           AND o.fulfillment_type = 'delivery'
         ORDER BY o.created_at DESC
         LIMIT 100"
    );
    $history->bind_param('i', $rider_id);
    $history->execute();
    $history_rows = $history->get_result()->fetch_all(MYSQLI_ASSOC);
    $history->close();

    $remit = $conn->prepare(
        "SELECT r.id, r.amount, r.status, r.notes, r.requested_at
         FROM tbl_rider_remittances r
         WHERE r.rider_id = ?
         ORDER BY r.requested_at DESC
         LIMIT 100"
    );
    $remit->bind_param('i', $rider_user_id);
    $remit->execute();
    $remit_rows = $remit->get_result()->fetch_all(MYSQLI_ASSOC);
    $remit->close();

    // Chart (monthly default)
    $chart_rows = $conn->prepare(
        "SELECT o.delivery_fee, o.tip, o.created_at
         FROM tbl_orders o
         WHERE o.rider_id = ? AND o.order_status = 'completed'
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         ORDER BY o.created_at ASC"
    );
    $chart_rows->bind_param('i', $rider_id);
    $chart_rows->execute();
    $chart_rows_data = $chart_rows->get_result()->fetch_all(MYSQLI_ASSOC);
    $chart_rows->close();

    $labels = [];
    $values = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = new DateTime('first day of this month');
        $m->modify("-$i month");
        $labels[] = $m->format('M');
        $values[] = 0.0;
    }
    foreach ($chart_rows_data as $r) {
        $dt = new DateTime($r['created_at']);
        $now = new DateTime('first day of this month');
        $diff = $dt->diff($now)->days / 30;
        $idx = 5 - (int)round($diff);
        if ($idx >= 0 && $idx < 6) {
            $values[$idx] += (float)$r['delivery_fee'] + (float)$r['tip'];
        }
    }

    $response['success'] = true;
    $response['message'] = 'Earnings dashboard fetched successfully.';
    $response['data'] = [
        'summary' => [
            'total_deliveries' => (int)($summary_row['total_deliveries'] ?? 0),
            'total_earnings' => (float)($summary_row['total_earnings'] ?? 0),
            'total_tips' => (float)($summary_row['total_tips'] ?? 0),
            'total_delivery_fees' => (float)($summary_row['total_delivery_fees'] ?? 0),
        ],
        'earnings_history' => array_map(function ($r) {
            return [
                'order_id' => (int)$r['order_id'],
                'order_number' => $r['order_number'],
                'delivery_fee' => (float)$r['delivery_fee'],
                'tip' => (float)$r['tip'],
                'total' => (float)$r['total'],
                'earnings' => (float)$r['earnings'],
                'created_at' => $r['created_at'],
            ];
        }, $history_rows),
        'remittance_history' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'amount' => (float)$r['amount'],
                'status' => $r['status'],
                'notes' => $r['notes'],
                'requested_at' => $r['requested_at'],
            ];
        }, $remit_rows),
        'chart' => [
            'period' => 'monthly',
            'labels' => $labels,
            'values' => array_map('floatval', $values),
        ],
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
