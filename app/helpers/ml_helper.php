<?php
/**
 * Machine Learning Helper for Customer Segmentation
 * Implements Heuristic Machine Learning and Predictive Analytics.
 */

/**
 * Classifies customers based on engagement and transaction history.
 * Uses a weighted scoring model (RFM-lite approach).
 * 
 * @param float $points Current loyalty points
 * @param int $transactions Total number of transactions
 * @param string|null $last_activity Last transaction date
 * @param array $all_points Array of all customer points for global stats
 */
function getMLCustomerClassification($points, $transactions, $last_activity, $all_points) {
    if (empty($all_points)) return ['label' => 'Regular', 'class' => 'badge-secondary', 'icon' => 'fa-user'];

    $count = count($all_points);
    $sum = array_sum($all_points);
    $mean = $sum / $count;

    $variance = 0;
    foreach($all_points as $p) {
        $variance += pow(($p - $mean), 2);
    }
    $std_dev = sqrt($variance / $count);

    // 1. ENGAGEMENT SCORE CALCULATION (Heuristic Model)
    // Higher weights for points and frequency
    $score = ($points * 0.6) + ($transactions * 10);
    $threshold = ($mean * 0.6) + (5 * 10); // Baseline comparison

    // 2. CHURN PREDICTION (Predictive Logic)
    $is_at_risk = false;
    if ($last_activity) {
        $days_since_last = (time() - strtotime($last_activity)) / (60 * 60 * 24);
        // If no activity for 30+ days despite being a high spender, predict "At Risk"
        if ($days_since_last > 30 && $points > $mean) {
            $is_at_risk = true;
        }
    }

    // 3. DYNAMIC SEGMENTATION
    if ($is_at_risk) {
        // Calculate how many weeks they've been gone as a risk multiplier
        $risk_level = floor($days_since_last / 7); 
        return [
            'label' => 'At Risk',
            'class' => 'badge-danger',
            'icon' => 'fa-exclamation-triangle',
            'note' => 'Inactive for ' . floor($days_since_last) . ' days.',
            'risk_score' => $risk_level
        ];
    }

    // 4. PREDICTIVE GOAL TRACKING
    // Logic to calculate how close a user is to the next statistical tier
    $next_goal = ($points < $mean) ? $mean : ($mean + $std_dev);
    $progress = ($points / $next_goal) * 100;

    if ($points >= ($mean + $std_dev) || $score > ($threshold * 1.5)) {
        return [
            'label' => 'Loyal',
            'class' => 'badge-success',
            'icon' => 'fa-crown',
            'goal_progress' => min(100, $progress)
        ];
    } elseif ($points >= $mean || $transactions >= 5) {
        return [
            'label' => 'Frequent',
            'class' => 'badge-primary',
            'icon' => 'fa-star',
            'goal_progress' => min(100, $progress)
        ];
    } else {
        return [
            'label' => 'Regular',
            'class' => 'badge-secondary',
            'icon' => 'fa-user'
        ];
    }
}
?>