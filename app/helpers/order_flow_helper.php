<?php
/**
 * Step-by-step order status rules, shared by the admin/staff Orders pages and the admin app API.
 *
 * An order moves ONE step forward at a time - no jumping from Pending straight to Completed:
 *   Delivery: Pending -> Confirmed -> Processing -> To Ship (packed, waiting for the rider)
 *             -> [rider scans at the store] Out for Delivery -> [rider scans the customer's QR] Completed
 *   Pickup:   Pending -> Confirmed -> Processing -> Ready for Pickup -> Completed
 *
 * - Out for Delivery / To Receive / Completed of a delivery order are the RIDER's steps (QR scans);
 *   staff can't set them, so "Completed" really means the customer has the parcel (points are
 *   awarded on completion). Admin may still complete a delivery the rider already has, as a
 *   fallback if the rider can't scan.
 * - Cancel is allowed until a rider has the order (admin may still cancel after that).
 * - Admin may step back ONE early step to undo a mis-click (never once a rider has it).
 * - Completed, Reviews, Cancelled and Refunded are final.
 * The real guard is order_flow_check(); the dropdown only shows what it allows.
 */

const ORDER_RIDER_STAGES = ['to_receive', 'out_for_delivery'];
const ORDER_FINAL_STAGES = ['completed', 'reviews', 'cancelled', 'refunded'];

const ORDER_FLOW_LABELS = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'to_ship' => 'To Ship',
    'ready_for_pickup' => 'Ready for Pickup',
    'out_for_delivery' => 'Out for Delivery',
    'to_receive' => 'To Receive',
    'reviews' => 'Reviews',
    'completed' => 'Delivered / Picked Up',
    'cancelled' => 'Cancelled',
    'refunded' => 'Refunded',
];

/** The staff-controlled steps for this kind of order, in order. */
function order_flow_steps(string $fulfillment): array
{
    return $fulfillment === 'pickup'
        ? ['pending', 'confirmed', 'processing', 'ready_for_pickup', 'completed']
        : ['pending', 'confirmed', 'processing', 'to_ship'];
}

/** Statuses this person may move the order to from $current: [status => label], next step first. */
function order_flow_allowed(string $current, string $fulfillment, bool $is_admin): array
{
    $current = strtolower(trim($current));
    $allowed = [];
    if (in_array($current, ORDER_FINAL_STAGES, true)) {
        return $allowed;
    }

    if (in_array($current, ORDER_RIDER_STAGES, true)) {
        // The rider has the parcel; their scan finishes it. Admin-only fallback.
        if ($is_admin) {
            $allowed['completed'] = ORDER_FLOW_LABELS['completed'];
            $allowed['cancelled'] = ORDER_FLOW_LABELS['cancelled'];
        }
        return $allowed;
    }

    $steps = order_flow_steps($fulfillment);
    $i = array_search($current, $steps, true);
    if ($i === false) {
        // A status that doesn't belong to this order type (e.g. old data): let it rejoin the
        // flow at Processing, or be cancelled.
        $allowed['processing'] = ORDER_FLOW_LABELS['processing'];
    } else {
        if (isset($steps[$i + 1])) {
            $allowed[$steps[$i + 1]] = ORDER_FLOW_LABELS[$steps[$i + 1]];
        }
        if ($is_admin && $i > 0) {
            $allowed[$steps[$i - 1]] = 'Back to ' . ORDER_FLOW_LABELS[$steps[$i - 1]];
        }
    }
    $allowed['cancelled'] = ORDER_FLOW_LABELS['cancelled'];
    return $allowed;
}

/** Why this change isn't allowed, or null when it is. */
function order_flow_check(string $old, string $new, string $fulfillment, bool $is_admin): ?string
{
    $old = strtolower(trim($old));
    if ($old === $new) {
        return null;
    }
    if (isset(order_flow_allowed($old, $fulfillment, $is_admin)[$new])) {
        return null;
    }

    $old_label = ORDER_FLOW_LABELS[$old] ?? ucwords(str_replace('_', ' ', $old));
    if (in_array($old, ORDER_FINAL_STAGES, true)) {
        return "This order is already {$old_label} and its status can no longer be changed.";
    }
    if (in_array($old, ORDER_RIDER_STAGES, true)) {
        return "A rider already has this order ({$old_label}). It is completed by the rider's QR scan"
            . ($is_admin ? ', or you can mark it Delivered or Cancelled.' : '; only an admin can change it now.');
    }
    if ($fulfillment !== 'pickup' && in_array($new, array_merge(ORDER_RIDER_STAGES, ['completed']), true)) {
        return 'Delivery orders go Out for Delivery and Completed only when the rider scans them. Move it to To Ship when it is packed.';
    }
    $next = array_key_first(order_flow_allowed($old, $fulfillment, $is_admin));
    $hint = ($next && $next !== 'cancelled') ? ' The next step is ' . ORDER_FLOW_LABELS[$next] . '.' : '';
    return "Orders move one step at a time and can't skip from {$old_label} to "
        . (ORDER_FLOW_LABELS[$new] ?? $new) . '.' . $hint;
}

/** The status dropdown on the Orders pages: the current status plus only the allowed moves. */
function order_flow_render_options(string $current, string $fulfillment, bool $is_admin = false): string
{
    $current = strtolower(trim($current));
    $label = ORDER_FLOW_LABELS[$current] ?? ucwords(str_replace('_', ' ', $current));
    $html = '<option value="' . htmlspecialchars($current) . '" selected>' . htmlspecialchars($label) . '</option>';

    $allowed = order_flow_allowed($current, $fulfillment, $is_admin);
    if (!isset($allowed['completed']) && !isset($allowed['to_ship'])) {
        $waiting = $current === 'to_ship' ? 'Waiting for the rider\'s pickup scan' : 'Waiting for the rider\'s scan';
        if ($current === 'to_ship' || in_array($current, ORDER_RIDER_STAGES, true)) {
            $html .= '<option disabled>' . htmlspecialchars($waiting) . '</option>';
        }
    }
    foreach ($allowed as $value => $text) {
        $prefix = $value === 'cancelled' || strpos($text, 'Back to') === 0 ? '' : 'Next: ';
        $html .= '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($prefix . $text) . '</option>';
    }
    return $html;
}
