<?php
/**
 * Machine Learning Helper for Customer Segmentation.
 *
 * 1. CLUSTERING (unsupervised): K-Means (k = 4, k-means++ seeding) groups every
 *    customer by RFM features:
 *      R - Recency:   days since the customer's last loyalty activity (capped at 365)
 *      F - Frequency: number of loyalty transactions (purchases)
 *      M - Monetary:  loyalty points (1 point per PHP 100 spent)
 *    Features are min-max normalised to 0..1 so no single feature dominates the
 *    Euclidean distance. Customers with no purchases yet have no behavior to
 *    measure, so they are not clustered - they are labelled "New".
 * 2. LABELLING: each cluster is named from its centroid - the valuable cluster
 *    that went inactive is "At Risk", the rest are ranked by value into
 *    "Loyal", "Frequent" and "Regular".
 * 3. CLASSIFICATION: a customer is assigned the label of the NEAREST centroid
 *    (nearest-centroid classifier), so new customers are classified with the
 *    same trained model.
 *
 * The model is trained once per request and cached; getMLCustomerClassification()
 * keeps its original signature so every page that calls it works unchanged.
 */

const ML_KMEANS_K = 4;
const ML_KMEANS_MAX_ITERATIONS = 100;
const ML_KMEANS_SEED = 42;          // fixed seed: the same data always gives the same clusters
const ML_RECENCY_CAP_DAYS = 365;

/** Days since $last_activity (capped), or the cap when the customer has no activity. */
function ml_recency_days(?string $last_activity): float
{
    if (empty($last_activity) || strtotime($last_activity) === false) {
        return (float) ML_RECENCY_CAP_DAYS;
    }
    $days = (time() - strtotime($last_activity)) / 86400;
    return (float) max(0, min(ML_RECENCY_CAP_DAYS, $days));
}

function ml_distance_sq(array $a, array $b): float
{
    $sum = 0.0;
    foreach ($a as $i => $v) {
        $sum += ($v - $b[$i]) ** 2;
    }
    return $sum;
}

/**
 * K-Means with k-means++ seeding on already-normalised points.
 * Returns ['centroids' => [...], 'assignments' => [...], 'iterations' => int].
 */
function ml_kmeans(array $points, int $k): array
{
    $n = count($points);
    $k = max(1, min($k, $n));
    mt_srand(ML_KMEANS_SEED);

    // k-means++: first centroid at random, each next one far from those already chosen.
    $centroids = [$points[mt_rand(0, $n - 1)]];
    while (count($centroids) < $k) {
        $weights = [];
        $total = 0.0;
        foreach ($points as $p) {
            $nearest = min(array_map(static fn($c) => ml_distance_sq($p, $c), $centroids));
            $weights[] = $nearest;
            $total += $nearest;
        }
        if ($total <= 0) {
            break; // every remaining point duplicates a centroid
        }
        $r = (mt_rand() / mt_getrandmax()) * $total;
        foreach ($weights as $i => $w) {
            $r -= $w;
            if ($r <= 0) {
                $centroids[] = $points[$i];
                break;
            }
        }
    }
    $k = count($centroids);

    $assignments = array_fill(0, $n, -1);
    $iterations = 0;
    for ($iter = 1; $iter <= ML_KMEANS_MAX_ITERATIONS; $iter++) {
        $iterations = $iter;
        // Assignment step: each point joins its nearest centroid.
        $changed = false;
        foreach ($points as $i => $p) {
            $best = 0;
            $best_d = INF;
            foreach ($centroids as $c => $centroid) {
                $d = ml_distance_sq($p, $centroid);
                if ($d < $best_d) {
                    $best_d = $d;
                    $best = $c;
                }
            }
            if ($assignments[$i] !== $best) {
                $assignments[$i] = $best;
                $changed = true;
            }
        }
        if (!$changed) {
            break; // converged
        }
        // Update step: each centroid moves to the mean of its members.
        $dims = count($points[0]);
        $sums = array_fill(0, $k, array_fill(0, $dims, 0.0));
        $counts = array_fill(0, $k, 0);
        foreach ($points as $i => $p) {
            $c = $assignments[$i];
            $counts[$c]++;
            foreach ($p as $d => $v) {
                $sums[$c][$d] += $v;
            }
        }
        foreach ($centroids as $c => $_) {
            if ($counts[$c] > 0) {
                $centroids[$c] = array_map(static fn($s) => $s / $counts[$c], $sums[$c]);
            }
        }
    }

    return ['centroids' => $centroids, 'assignments' => $assignments, 'iterations' => $iterations];
}

/**
 * Trains (once per request) the K-Means model on every customer's RFM features.
 * Returns null when there is no customer data yet.
 */
function ml_customer_model(?mysqli $conn = null): ?array
{
    static $model = false;
    if ($model !== false) {
        return $model;
    }
    if ($conn === null) {
        $conn = $GLOBALS['conn'] ?? null;
    }
    if (!$conn instanceof mysqli) {
        return $model = null;
    }

    $res = $conn->query(
        "SELECT c.id, c.loyalty_points, COUNT(lt.id) AS transactions, MAX(lt.created_at) AS last_activity
         FROM customers c
         LEFT JOIN loyalty_transactions lt ON lt.customer_id = c.id
         GROUP BY c.id
         HAVING COUNT(lt.id) > 0"
    );
    $raw = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $raw[(int) $row['id']] = [
            ml_recency_days($row['last_activity']),
            (float) $row['transactions'],
            max(0.0, (float) $row['loyalty_points']),
        ];
    }
    if (empty($raw)) {
        return $model = null;
    }

    // Min-max normalisation per feature.
    $min = $max = array_values(reset($raw));
    foreach ($raw as $v) {
        foreach ($v as $d => $x) {
            $min[$d] = min($min[$d], $x);
            $max[$d] = max($max[$d], $x);
        }
    }
    $normalise = static function (array $v) use ($min, $max): array {
        $out = [];
        foreach ($v as $d => $x) {
            $range = $max[$d] - $min[$d];
            $out[$d] = $range > 0 ? max(0.0, min(1.0, ($x - $min[$d]) / $range)) : 0.0;
        }
        return $out;
    };
    $points = array_map($normalise, array_values($raw));

    $result = ml_kmeans($points, ML_KMEANS_K);
    $centroids = $result['centroids'];

    // Name each cluster from its centroid: value = (F + M) / 2, inactivity = R.
    $value = [];
    $inactive = [];
    foreach ($centroids as $c => $centroid) {
        $value[$c] = ($centroid[1] + $centroid[2]) / 2;
        $inactive[$c] = $centroid[0];
    }
    $labels = [];
    $remaining = array_keys($centroids);
    if (count($remaining) >= 3) {
        // "At Risk": the cluster that is most valuable-but-inactive, if it really is inactive.
        $risk = null;
        $risk_score = 0.0;
        foreach ($remaining as $c) {
            $score = $value[$c] * $inactive[$c];
            if ($inactive[$c] >= 0.5 && $score > $risk_score) {
                $risk_score = $score;
                $risk = $c;
            }
        }
        if ($risk !== null) {
            $labels[$risk] = 'At Risk';
            $remaining = array_values(array_diff($remaining, [$risk]));
        }
    }
    usort($remaining, static fn($a, $b) => $value[$b] <=> $value[$a]);
    $ranked = ['Loyal', 'Frequent', 'Regular'];
    foreach ($remaining as $i => $c) {
        $labels[$c] = $ranked[min($i, 2)];
    }
    if (count($remaining) === 1) {
        $labels[$remaining[0]] = 'Regular';
    }

    $sizes = array_fill(0, count($centroids), 0);
    foreach ($result['assignments'] as $c) {
        $sizes[$c]++;
    }

    return $model = [
        'algorithm' => 'K-Means clustering (k = ' . count($centroids) . ', k-means++) + nearest-centroid classification',
        'features' => ['Recency (days)', 'Frequency (transactions)', 'Monetary (loyalty points)'],
        'min' => $min,
        'max' => $max,
        'normalise' => $normalise,
        'centroids' => $centroids,
        'labels' => $labels,
        'sizes' => $sizes,
        'iterations' => $result['iterations'],
        'trained_on' => count($points),
    ];
}

/** Nearest-centroid classification of one RFM vector. Returns [cluster index, label]. */
function ml_classify_rfm(array $model, float $recency, float $frequency, float $monetary): array
{
    $p = ($model['normalise'])([$recency, $frequency, max(0.0, $monetary)]);
    $best = 0;
    $best_d = INF;
    foreach ($model['centroids'] as $c => $centroid) {
        $d = ml_distance_sq($p, $centroid);
        if ($d < $best_d) {
            $best_d = $d;
            $best = $c;
        }
    }
    return [$best, $model['labels'][$best] ?? 'Regular'];
}

/**
 * Classifies a customer with the trained K-Means model.
 *
 * @param float $points Current loyalty points
 * @param int $transactions Total number of transactions
 * @param string|null $last_activity Last transaction date
 * @param array $all_points Array of all customer points (used for the goal-progress bar)
 */
function getMLCustomerClassification($points, $transactions, $last_activity, $all_points) {
    $points = (float) $points;
    $recency = ml_recency_days($last_activity);

    // No purchases yet: nothing to cluster on, so the customer is New (not part of the model).
    if ((int) $transactions <= 0) {
        return ['label' => 'New', 'class' => 'badge-info', 'icon' => 'fa-seedling', 'note' => 'No purchases yet.'];
    }

    $model = ml_customer_model();

    if ($model === null) {
        return ['label' => 'Regular', 'class' => 'badge-secondary', 'icon' => 'fa-user'];
    }
    [$cluster, $label] = ml_classify_rfm($model, $recency, (float) $transactions, $points);

    // Progress toward the next statistical tier (mean, then mean + 1 standard deviation).
    $progress = null;
    if (!empty($all_points)) {
        $mean = array_sum($all_points) / count($all_points);
        $variance = 0.0;
        foreach ($all_points as $p) {
            $variance += ($p - $mean) ** 2;
        }
        $std_dev = sqrt($variance / count($all_points));
        $next_goal = ($points < $mean) ? $mean : ($mean + $std_dev);
        $progress = $next_goal > 0 ? min(100, ($points / $next_goal) * 100) : 100;
    }

    $base = ['cluster' => $cluster];
    switch ($label) {
        case 'At Risk':
            return $base + [
                'label' => 'At Risk',
                'class' => 'badge-danger',
                'icon' => 'fa-exclamation-triangle',
                'note' => 'Inactive for ' . floor($recency) . ' days.',
                'risk_score' => floor($recency / 7),
            ];
        case 'Loyal':
            return $base + ['label' => 'Loyal', 'class' => 'badge-success', 'icon' => 'fa-crown', 'goal_progress' => $progress];
        case 'Frequent':
            return $base + ['label' => 'Frequent', 'class' => 'badge-primary', 'icon' => 'fa-star', 'goal_progress' => $progress];
        default:
            return $base + ['label' => 'Regular', 'class' => 'badge-secondary', 'icon' => 'fa-user'];
    }
}
