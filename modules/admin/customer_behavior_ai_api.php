<?php
/**
 * Customer buying-behavior analysis (Admin > Analytics > Customer Groups).
 *
 *   GET  ?action=profile&customer_id=5   -> the customer's K-Means group, RFM numbers, purchase stats
 *                                           and the saved Gemini analysis if there is one (no AI call)
 *   POST action=analyze, customer_id, force=0|1, csrf_token
 *                                        -> Gemini analysis (saved one reused; force = fresh, with a cooldown)
 *
 * Admin only (the Analytics page is admin only). K-Means does the classification; Gemini only explains
 * it and suggests actions - see app/helpers/customer_behavior_ai_helper.php. The Gemini key never
 * leaves the server.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
require_once __DIR__ . '/../../app/helpers/customer_behavior_ai_helper.php';

$token = getJWTFromCookie();
$payload = $token ? verifyJWT($token) : null;
if (!$payload || strtolower(trim((string) ($payload['role'] ?? ''))) !== 'admin') {
    ajax_json(['success' => false, 'code' => 'auth', 'message' => 'Only an admin can view customer analyses.'], $payload ? 403 : 401);
}
$admin_id = (int) ($payload['user_id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : (string) ($_GET['action'] ?? '');
$customer_id = (int) ($method === 'POST' ? ($_POST['customer_id'] ?? 0) : ($_GET['customer_id'] ?? 0));

if ($method === 'POST' && !csrf_check()) {
    ajax_json(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
}
if ($customer_id <= 0) {
    ajax_json(['success' => false, 'message' => 'Choose a customer first.'], 422);
}

try {
    $profile = cba_customer_profile($conn, $customer_id);
    if ($profile === null) {
        ajax_json(['success' => false, 'message' => 'Customer not found.'], 404);
    }
    // What the browser gets: never the raw prompt input beyond these display numbers, never the API key.
    $view = [
        'customer_id' => $profile['customer_id'],
        'name' => $profile['name'],
        'classification' => $profile['classification'],
        'classification_class' => $profile['classification_class'],
        'rfm' => $profile['rfm'],
        'rfm_scale' => $profile['rfm_scale'],
        'stats' => $profile['stats'],
    ];

    if ($action === 'profile') {
        $saved = cba_cached($conn, $customer_id);
        $analysis = null;
        if ($saved && $saved['classification'] === $profile['classification']
            && time() - strtotime($saved['created_at']) < CBA_CACHE_DAYS * 86400) {
            $analysis = json_decode($saved['analysis_json'], true);
        }
        ajax_json(['success' => true, 'data' => $view + [
            'analysis' => is_array($analysis) ? $analysis : null,
            'generated_at' => is_array($analysis) ? $saved['created_at'] : null,
            'stale' => is_array($analysis) && $saved['data_hash'] !== hash('sha256', json_encode($profile['ai_input'])),
            'engagement_labels' => CBA_ENGAGEMENT,
        ]]);
    }

    if ($action === 'analyze' && $method === 'POST') {
        if ($profile['classification'] === 'New') {
            ajax_json(['success' => false, 'message' => 'This customer has no purchases yet, so there is no buying behavior to analyze.'], 422);
        }
        $r = cba_analyze($conn, $profile, $admin_id, ($_POST['force'] ?? '0') === '1');
        if (!$r['ok']) {
            ajax_json(['success' => false, 'message' => $r['message'], 'data' => $view], 503);
        }
        ajax_json(['success' => true, 'data' => $view + [
            'analysis' => $r['analysis'], 'cached' => $r['cached'], 'generated_at' => $r['generated_at'],
            'stale' => $r['stale'], 'engagement_labels' => CBA_ENGAGEMENT,
        ]]);
    }

    ajax_json(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('customer_behavior_ai_api: ' . $e->getMessage());
    ajax_json(['success' => false, 'message' => 'Could not load the analysis. The customer groups are not affected.'], 500);
}
