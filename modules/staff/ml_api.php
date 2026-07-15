<?php
/**
 * Machine Learning Insights API
 * Provides customer segmentation data and system AI documentation.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/ml_helper.php';

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
        "step_2" => "Data Processing: Organizes data for analysis, focusing on spending/points as the main evaluation basis.",
        "step_3" => "Machine Learning Model: Uses Clustering (Low, Medium, High groups) and Classification (Regular, Frequent, Loyal labels).",
        "step_4" => "Output: Produces customer classification and loyalty levels for business decision-making."
    ],
    "defense_explanation" => [
        "short" => "Machine learning is used to analyze customer purchase data and classify customers based on their total spending. It uses clustering to group customers and classification to label them as regular, frequent, or loyal.",
        "panel_q_a" => [
            "question" => "Is it really AI?",
            "answer" => "Yes, because the system uses machine learning algorithms like clustering and classification to analyze data and generate patterns automatically."
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
        'At Risk' => 0
    ];

    while ($customer = mysqli_fetch_assoc($result)) {
        // Use the ML Helper logic
        $classification = getMLCustomerClassification(
            (float)$customer['loyalty_points'],
            (int)$customer['transactions'],
            $customer['last_activity'],
            $all_points
        );

        $segmented_customers[] = [
            'id' => $customer['id'],
            'name' => $customer['name'],
            'spending_metric' => (float)$customer['loyalty_points'],
            'transaction_count' => (int)$customer['transactions'],
            'ml_label' => $classification['label'],
            'status_class' => $classification['class']
        ];

        if (isset($stats[$classification['label']])) {
            $stats[$classification['label']]++;
        }
    }

    // 4. API RESPONSE
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('c'),
        'ai_info' => $ai_documentation,
        'ml_results' => [
            'summary' => $stats,
            'global_mean_points' => count($all_points) > 0 ? array_sum($all_points) / count($all_points) : 0,
            'total_processed' => count($segmented_customers),
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