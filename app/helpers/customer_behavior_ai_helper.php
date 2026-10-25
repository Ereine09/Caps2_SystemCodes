<?php
/**
 * Gemini AI buying-behavior analysis for ONE customer, on top of the K-Means customer groups.
 *
 * Two separate jobs:
 *   - K-MEANS (app/helpers/ml_helper.php) CLASSIFIES the customer (Loyal / Frequent / Regular /
 *     At Risk, or New with no purchases). This file never changes that: it reads the label from the
 *     exact same analytics_customer_groups() rows the Customer Groups table shows.
 *   - GEMINI (app/helpers/gemini_helper.php, the existing integration and API key) only EXPLAINS that
 *     label from the customer's aggregated purchase data and RECOMMENDS what the shop could do.
 *
 * Privacy: Gemini gets aggregates only - no name, email, phone, address, payment details or ids.
 * Safety: the reply must be JSON with fixed keys; it is validated, length-limited and shown as plain
 * text. Nothing it says is ever written back to customers, points, vouchers or orders.
 * Cost: on demand only (a button), cached per customer in tbl_customer_ai_analysis, with a cooldown
 * between fresh calls for the same customer.
 */

require_once __DIR__ . '/ml_helper.php';
require_once __DIR__ . '/gemini_helper.php';
require_once __DIR__ . '/analytics_helper.php';

const CBA_CACHE_DAYS = 7;          // a saved analysis is reused this long if the customer's data is unchanged
const CBA_REFRESH_COOLDOWN = 60;   // seconds between fresh Gemini calls for the same customer
const CBA_ENGAGEMENT = ['active' => 'Actively buying', 'occasional' => 'Buys occasionally', 'becoming_inactive' => 'Becoming inactive', 'inactive' => 'Inactive'];

function cba_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_customer_ai_analysis (
        customer_id INT NOT NULL PRIMARY KEY,
        classification VARCHAR(20) NOT NULL,
        data_hash CHAR(64) NOT NULL,
        analysis_json TEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Aggregated purchase profile of one customer + their K-Means group (the same one the Customer Groups
 * table shows). Returns null when the customer doesn't exist. 'ai_input' is everything Gemini sees.
 */
function cba_customer_profile(mysqli $conn, int $customer_id): ?array
{
    // The K-Means label, exactly as the Customer Groups table computes it (same function, same inputs).
    $row = null;
    foreach (analytics_customer_groups($conn, 0)['top'] as $r) {
        if ((int) $r['id'] === $customer_id) { $row = $r; break; }
    }
    if ($row === null) return null;

    $st = $conn->prepare("SELECT
            COUNT(*) AS orders_total,
            SUM(order_status IN ('completed','reviews')) AS orders_completed,
            COALESCE(SUM(CASE WHEN order_status IN ('completed','reviews') THEN total - COALESCE(refund_amount, 0) END), 0) AS spent,
            MIN(created_at) AS first_order, MAX(created_at) AS last_order,
            SUM(created_at >= NOW() - INTERVAL 30 DAY) AS orders_30d,
            SUM(created_at >= NOW() - INTERVAL 90 DAY) AS orders_90d,
            SUM(fulfillment_type = 'delivery') AS deliveries,
            SUM(fulfillment_type = 'pickup') AS pickups
        FROM tbl_orders WHERE customer_id = ? AND order_status NOT IN ('cancelled','refunded')");
    $st->bind_param('i', $customer_id);
    $st->execute();
    $o = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    // What they buy: top product categories and products by quantity (non-cancelled orders).
    $st = $conn->prepare("SELECT oi.product_name, p.category, SUM(oi.quantity) AS qty
        FROM tbl_order_items oi
        JOIN tbl_orders o ON o.id = oi.order_id AND o.order_status NOT IN ('cancelled','refunded')
        LEFT JOIN tbl_product_inventory p ON p.id = oi.product_id
        WHERE o.customer_id = ? GROUP BY oi.product_id, oi.product_name, p.category");
    $st->bind_param('i', $customer_id);
    $st->execute();
    $cats = $prods = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
        $q = (int) $it['qty'];
        $prods[preg_replace('/\s*\(.*$/', '', (string) $it['product_name'])] = ($prods[preg_replace('/\s*\(.*$/', '', (string) $it['product_name'])] ?? 0) + $q;
        foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $it['category']))), 0, 2) as $c) {
            $cats[$c] = ($cats[$c] ?? 0) + $q; // first two tags = kind of product (e.g. "Cat Food, Kitten")
        }
    }
    $st->close();
    arsort($cats);
    arsort($prods);

    // Loyalty points: earned (all time) and used on rewards.
    $st = $conn->prepare("SELECT
            (SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = ? AND points_earned > 0) AS earned,
            (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = ?) AS redeemed,
            (SELECT COUNT(*) FROM reward_redemptions WHERE customer_id = ?) AS redemptions");
    $st->bind_param('iii', $customer_id, $customer_id, $customer_id);
    $st->execute();
    $pts = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    // Rewards the shop really offers (so Gemini can only point to real ones).
    $rewards = [];
    $res = $conn->query("SELECT name, points FROM rewards WHERE stock > 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY points ASC");
    while ($res && ($rw = $res->fetch_assoc())) {
        $rewards[] = ['reward' => (string) $rw['name'], 'points_needed' => (float) $rw['points']];
    }

    $orders = (int) ($o['orders_total'] ?? 0);
    $completed = (int) ($o['orders_completed'] ?? 0);
    $spent = round((float) ($o['spent'] ?? 0), 2);
    $first = $o['first_order'] ?? null;
    $last = $o['last_order'] ?? null;
    $days_since = $last ? (int) floor((time() - strtotime($last)) / 86400) : null;
    $span_days = ($first && $last) ? max(0, (strtotime($last) - strtotime($first)) / 86400) : 0;
    $balance = round((float) $row['loyalty_points'], 2);

    // The three K-Means inputs, as the model reads them for this customer.
    $rfm = [
        'recency_days' => (int) round(ml_recency_days($row['last_activity'] ?? null)),
        'frequency' => (int) $row['purchased_count'],
        'monetary_points' => $balance,
    ];

    $ai_input = [
        'kmeans_classification' => $row['ml_label'],
        'rfm_features_used_by_kmeans' => [
            'recency_days_since_last_loyalty_activity' => $rfm['recency_days'],
            'frequency_items_purchased' => $rfm['frequency'],
            'monetary_loyalty_points_balance' => $rfm['monetary_points'],
        ],
        'orders' => [
            'total_orders' => $orders,
            'completed_orders' => $completed,
            'orders_last_30_days' => (int) ($o['orders_30d'] ?? 0),
            'orders_last_90_days' => (int) ($o['orders_90d'] ?? 0),
            'customer_for_days' => $first ? (int) floor((time() - strtotime($first)) / 86400) : 0,
            'days_since_last_order' => $days_since,
            'average_days_between_orders' => $orders > 1 ? round($span_days / ($orders - 1), 1) : null,
            'prefers' => (int) ($o['deliveries'] ?? 0) >= (int) ($o['pickups'] ?? 0) ? 'delivery' : 'store pickup',
        ],
        'spending_php' => [
            'total_spent_completed_orders' => $spent,
            'average_order_value' => $completed > 0 ? round($spent / $completed, 2) : null,
        ],
        'top_product_categories' => array_slice(array_keys($cats), 0, 4),
        'top_products' => array_slice(array_keys($prods), 0, 3),
        'loyalty_points' => [
            'current_balance' => $balance,
            'earned_all_time' => round((float) ($pts['earned'] ?? 0), 2),
            'redeemed_all_time' => round((float) ($pts['redeemed'] ?? 0), 2),
            'rewards_redeemed_count' => (int) ($pts['redemptions'] ?? 0),
        ],
        // Which real rewards this customer's balance covers today (so a suggestion is never out of reach).
        'rewards_the_store_offers' => array_map(static fn($rw) => $rw + [
            'customer_can_redeem_now' => $balance >= $rw['points_needed'],
            'points_still_needed' => max(0, round($rw['points_needed'] - $balance, 2)),
        ], $rewards),
    ];

    return [
        'customer_id' => $customer_id,
        'name' => (string) $row['name'],                // for the admin screen only - never sent to Gemini
        'classification' => (string) $row['ml_label'],
        'classification_class' => (string) $row['ml_class'],
        'rfm' => $rfm,
        // Where this customer sits between the lowest and highest customer on each K-Means feature
        // (0..1, the model's own min-max normalisation) - drawn as meters on the screen.
        'rfm_scale' => cba_rfm_scale($conn, $rfm),
        'stats' => [
            'orders' => $orders, 'completed' => $completed, 'spent' => $spent,
            'aov' => $ai_input['spending_php']['average_order_value'],
            'days_since_last_order' => $days_since, 'last_order' => $last,
            'orders_90d' => (int) ($o['orders_90d'] ?? 0),
            'points_balance' => $balance, 'points_earned' => $ai_input['loyalty_points']['earned_all_time'],
            'points_redeemed' => $ai_input['loyalty_points']['redeemed_all_time'],
            'top_categories' => $ai_input['top_product_categories'],
        ],
        'ai_input' => $ai_input,
    ];
}

/** The K-Means model's normalised position (0..1) of each RFM value, or null without a model. */
function cba_rfm_scale(mysqli $conn, array $rfm): ?array
{
    $model = ml_customer_model($conn);
    if ($model === null) return null;
    [$r, $f, $m] = ($model['normalise'])([(float) $rfm['recency_days'], (float) $rfm['frequency'], (float) $rfm['monetary_points']]);
    return ['recency' => round($r, 3), 'frequency' => round($f, 3), 'monetary' => round($m, 3)];
}

/** Builds the Gemini request (instructions + aggregated data only). */
function cba_prompt(array $profile): array
{
    $system = "You are a retail customer-retention analyst for Darius Poultry Supplies, a pet food, poultry feed and pet supplies store in Caloocan City, Philippines.\n"
        . "A K-Means machine-learning model has ALREADY classified this customer. You must NOT change, question or re-assign that classification - you only explain it and recommend actions.\n"
        . "Use ONLY the numbers in the data. Never invent purchases, dates, amounts, products, discounts, vouchers or promotions. "
        . "A promotion may only mention a reward listed in rewards_the_store_offers (by its exact name) or a non-monetary action (e.g. a friendly reminder message, restock notice for the products they buy, inviting them to use their points). "
        . "Only call a reward redeemable now if customer_can_redeem_now is true; for other rewards say how many more points the customer needs (points_still_needed). "
        . "Never state a price, percentage or discount that is not in the data. Money is in Philippine Pesos (PHP or the peso sign). "
        . "Write every field for the STORE OWNER about the customer (say 'this customer', never 'you'). "
        . "Write plain, friendly English for a store owner: no markdown, no lists, 2-3 sentences per field.\n"
        . "Reply with ONLY a JSON object with exactly these keys:\n"
        . "{\"behavior_explanation\": string (why these purchase patterns match the K-Means classification),\n"
        . " \"engagement_level\": one of \"active\", \"occasional\", \"becoming_inactive\", \"inactive\",\n"
        . " \"engagement_analysis\": string (is the customer actively buying, buying occasionally, or becoming inactive, and why),\n"
        . " \"recommended_strategy\": string (a retention strategy that fits this customer),\n"
        . " \"suggested_promotion\": string (one suitable promotion or loyalty incentive, using only real rewards or non-monetary actions)}";
    $prompt = "Customer purchase data (aggregated, anonymous):\n" . json_encode($profile['ai_input'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return ['system' => $system, 'prompt' => $prompt];
}

/** Validates Gemini's reply. Returns the clean analysis array, or null when it isn't usable. */
function cba_parse_reply($text): ?array
{
    if (!is_string($text) || $text === '' || preg_match('/^(AI Error|Could not connect)/', $text)) return null;
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text); // tolerate a ```json fence
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($data)) return null;

    $out = [];
    foreach (['behavior_explanation', 'engagement_analysis', 'recommended_strategy', 'suggested_promotion'] as $k) {
        $v = $data[$k] ?? null;
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['**', '__', '`'], '', $v))));
        if ($v === '') return null;
        $out[$k] = mb_strlen($v) > 700 ? mb_substr($v, 0, 697) . '...' : $v;
    }
    $level = strtolower(trim((string) ($data['engagement_level'] ?? '')));
    $out['engagement_level'] = isset(CBA_ENGAGEMENT[$level]) ? $level : 'occasional';
    return $out;
}

function cba_cached(mysqli $conn, int $customer_id): ?array
{
    cba_ensure_schema($conn);
    $st = $conn->prepare("SELECT * FROM tbl_customer_ai_analysis WHERE customer_id = ?");
    $st->bind_param('i', $customer_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/**
 * The analysis for one customer: the saved one when still valid (same data, same K-Means group, under
 * CBA_CACHE_DAYS old), otherwise a fresh Gemini call. $force asks for a fresh call but still respects
 * CBA_REFRESH_COOLDOWN. Returns ['ok', 'analysis'?, 'cached'?, 'generated_at'?, 'message'?].
 */
function cba_analyze(mysqli $conn, array $profile, int $admin_id, bool $force = false): array
{
    cba_ensure_schema($conn);
    $cid = (int) $profile['customer_id'];
    $hash = hash('sha256', json_encode($profile['ai_input']));
    $saved = cba_cached($conn, $cid);
    $age = $saved ? time() - strtotime($saved['created_at']) : PHP_INT_MAX;

    $saved_analysis = $saved ? json_decode($saved['analysis_json'], true) : null;
    $reply_saved = static fn() => ['ok' => true, 'analysis' => $saved_analysis, 'cached' => true,
        'generated_at' => $saved['created_at'], 'stale' => $saved['data_hash'] !== $hash];

    // Reuse the saved analysis while it still describes this K-Means group and is not too old.
    $valid = is_array($saved_analysis) && $saved['classification'] === $profile['classification'] && $age < CBA_CACHE_DAYS * 86400;
    if ($valid && (!$force || $age < CBA_REFRESH_COOLDOWN)) {
        return $reply_saved();
    }
    // Asked again within the cooldown (e.g. a double click): no new Gemini call.
    if ($age < CBA_REFRESH_COOLDOWN) {
        return is_array($saved_analysis) ? $reply_saved()
            : ['ok' => false, 'message' => 'This customer was just analyzed. Please wait a minute before analyzing again.'];
    }
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return ['ok' => false, 'message' => 'Gemini AI is not set up yet (no API key in Settings). The K-Means customer groups still work.'];
    }

    $p = cba_prompt($profile);
    $reply = getGeminiBusinessInsight(['context' => 'customer_behavior', 'system_instruction' => $p['system'], 'prompt' => $p['prompt']]);
    $analysis = cba_parse_reply($reply);
    if ($analysis === null) {
        error_log('Customer behavior AI: unusable reply for customer ' . $cid . ': ' . substr((string) $reply, 0, 200));
        return ['ok' => false, 'message' => 'Gemini AI is unavailable right now or gave an unusable answer. Please try again in a moment. The K-Means customer groups are not affected.'];
    }

    $json = json_encode($analysis, JSON_UNESCAPED_UNICODE);
    $label = (string) $profile['classification'];
    $st = $conn->prepare("INSERT INTO tbl_customer_ai_analysis (customer_id, classification, data_hash, analysis_json, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE classification = VALUES(classification), data_hash = VALUES(data_hash),
            analysis_json = VALUES(analysis_json), created_by = VALUES(created_by), created_at = NOW()");
    $st->bind_param('isssi', $cid, $label, $hash, $json, $admin_id);
    $st->execute();
    $st->close();
    return ['ok' => true, 'analysis' => $analysis, 'cached' => false, 'generated_at' => date('Y-m-d H:i:s'), 'stale' => false];
}
