<?php
/**
 * Machine Learning Insights API
 * Provides customer segmentation data and system AI documentation.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';

// 1. SECURITY: JWT Verification
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

// 2. AI SYSTEM DOCUMENTATION (As requested for project defense)
$ai_documentation = [
    "title" => "How Machine Learning is Used in the System?",
    "overview" => "Machine Learning is used to analyze customer purchasing behavior and automatically classify customers based on their total amount spent (represented by loyalty points and transactions).",
    "how_it_works" => [
        "step_1" => "Data Collection: Collects customer info, transaction records, and total points earned to solve manual data organization problems.",
        "step_2" => "Data Processing: Builds RFM features for every customer - Recency (days since last purchase), Frequency (number of purchases) and Monetary (loyalty points, 1 point per PHP 100 spent) - and min-max normalizes them to 0-1.",
        "step_3" => "Machine Learning Model: K-Means clustering (k = 4, k-means++ initialization) groups customers with similar RFM behavior; each cluster is named from its centroid (Loyal, Frequent, Regular, At Risk), and every customer is classified by its nearest centroid.",
        "step_4" => "Output: Produces customer classification and loyalty levels for business decision-making."
    ],
    "defense_explanation" => [
        "short" => "Machine learning is used to analyze customer purchase data and classify customers based on their total spending. It uses clustering to group customers and classification to label them as regular, frequent, or loyal.",
        "panel_q_a" => [
            "question" => "Is it really AI?",
            "answer" => "Yes. The K-Means algorithm learns the customer groups from the transaction data itself (unsupervised learning) - no thresholds are hand-coded - and the trained centroids are then used to classify each customer."
        ]
    ]
];

// 3. DATA PROCESSING: Fetch and Classify Customers
try {
    // Get global points for ML thresholds
    $all_points = [];
    $points_res = mysqli_query($conn, "SELECT loyalty_points FROM customers");
    while ($row = mysqli_fetch_assoc($points_res)) {
        $all_points[] = (float)$row['loyalty_points'];
    }

    // Fetch detailed customer data
    $query = "
        SELECT 
            c.id, 
            c.name, 
            c.loyalty_points,
            COUNT(lt.id) as transactions,
            MAX(lt.created_at) as last_activity
        FROM customers c
        LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
        GROUP BY c.id
    ";
    $result = mysqli_query($conn, $query);
    
    $segmented_customers = [];
    $stats = [
        'Loyal' => 0,
        'Frequent' => 0,
        'Regular' => 0,
        'At Risk' => 0,
        'New' => 0
    ];

    while ($customer = mysqli_fetch_assoc($result)) {
        // Use the ML Helper logic
        $classification = getMLCustomerClassification(
            (float)$customer['loyalty_points'],
            (int)$customer['transactions'],
            $customer['last_activity'],
            $all_points
        );

        // Map classification to Spender Groups for the "Clustering" requirement
        $spender_group = match($classification['label']) {
            'Loyal' => 'High Spender',
            'Frequent' => 'Medium Spender',
            'Regular' => 'Low Spender',
            'New' => 'New Customer',
            default => 'At Risk'
        };

        $segmented_customers[] = [
            'id' => $customer['id'],
            'name' => $customer['name'],
            'spending_metric' => (float)$customer['loyalty_points'],
            'transaction_count' => (int)$customer['transactions'],
            'ml_label' => $classification['label'],
            'status_class' => $classification['class'],
            'clustering_group' => $spender_group
        ];

        if (isset($stats[$classification['label']])) {
            $stats[$classification['label']]++;
        }
    }

    // 3.5 Behavioral Analysis: Frequently Bought Products
    $behavioral_data = [];
    $pref_customer_id = intval($_GET['customer_id'] ?? 0);
    if ($pref_customer_id > 0) {
        $pref_query = "
            SELECT product_name, COUNT(*) as frequency 
            FROM loyalty_transactions 
            WHERE customer_id = ? AND product_name NOT LIKE 'Manual:%'
            GROUP BY product_name 
            ORDER BY frequency DESC 
            LIMIT 5";
        $pref_stmt = $conn->prepare($pref_query);
        $pref_stmt->bind_param("i", $pref_customer_id);
        $pref_stmt->execute();
        $pref_res = $pref_stmt->get_result();
        while ($pref = $pref_res->fetch_assoc()) {
            $behavioral_data[] = $pref;
        }
    }

    // 3.5 Generate AI Business Strategy using Gemini
    $ai_strategy = getGeminiBusinessInsight($stats);

    // 4. API RESPONSE
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('c'),
        'ai_info' => $ai_documentation,
        'gemini_insight' => $ai_strategy,
        'user_behavior' => [
            'customer_id' => $pref_customer_id,
            'frequently_bought' => $behavioral_data
        ],
        'ml_results' => [
            'summary' => $stats,
            'global_mean_points' => count($all_points) > 0 ? array_sum($all_points) / count($all_points) : 0,
            'total_processed' => count($segmented_customers),
            'model' => ($ml_model = ml_customer_model($conn)) === null ? null : [
                'algorithm' => $ml_model['algorithm'],
                'features' => $ml_model['features'],
                'iterations_to_converge' => $ml_model['iterations'],
                'clusters' => array_map(static fn($c, $centroid) => [
                    'cluster' => $c,
                    'label' => $ml_model['labels'][$c] ?? 'Regular',
                    'size' => $ml_model['sizes'][$c] ?? 0,
                    // Centroid in original units (de-normalized) so it reads naturally.
                    'centroid' => array_combine(['recency_days', 'frequency', 'monetary_points'], array_map(
                        static fn($v, $d) => round($ml_model['min'][$d] + $v * ($ml_model['max'][$d] - $ml_model['min'][$d]), 2),
                        $centroid, array_keys($centroid)
                    )),
                ], array_keys($ml_model['centroids']), $ml_model['centroids']),
            ],
            'customers' => $segmented_customers
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'ML Processing failed: ' . $e->getMessage()
    ]);
}

exit();
?>