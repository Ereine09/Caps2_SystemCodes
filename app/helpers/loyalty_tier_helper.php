<?php
/**
 * Loyalty tiers (Bronze / Silver / Gold / Platinum).
 *
 * A tier is earned from LIFETIME points (every point the customer has ever earned, less any
 * refund reversals), not from the usable balance - so redeeming a reward never drops a loyal
 * customer back to Bronze. Thresholds match notifications_reward_thresholds() (250 / 500 / 1000).
 * Points = order subtotal / 100, so 250 pts is about PHP 25,000 spent.
 *
 * Used by the admin + staff customer profiles and the loyalty statements.
 */

if (!function_exists('loyalty_tiers')) {
    /** Lowest tier first. */
    function loyalty_tiers(): array
    {
        return [
            // color = badge background; icon_color = the tier's own metal color for its icon
            ['label' => 'Bronze',   'min' => 0,    'color' => '#fab1a0', 'icon' => 'fa-star',  'icon_color' => '#cd7f32'],
            ['label' => 'Silver',   'min' => 250,  'color' => '#dfe6e9', 'icon' => 'fa-award', 'icon_color' => '#9ea7b3'],
            ['label' => 'Gold',     'min' => 500,  'color' => '#ffeaa7', 'icon' => 'fa-medal', 'icon_color' => '#e6b800'],
            ['label' => 'Platinum', 'min' => 1000, 'color' => '#55efc4', 'icon' => 'fa-crown', 'icon_color' => '#4fb8b0'],
        ];
    }
}

if (!function_exists('loyalty_tier_for')) {
    /**
     * Tier for a lifetime-points total, plus progress toward the next one:
     * label, color, icon, icon_color, min, next (next tier's label or null), next_min, to_next, progress (0-100).
     */
    function loyalty_tier_for(float $lifetime_points): array
    {
        $tiers = loyalty_tiers();
        $idx = 0;
        foreach ($tiers as $i => $t) {
            if ($lifetime_points >= $t['min']) {
                $idx = $i;
            }
        }
        $tier = $tiers[$idx];
        $next = $tiers[$idx + 1] ?? null;
        $tier['next'] = $next['label'] ?? null;
        $tier['next_min'] = $next['min'] ?? null;
        $tier['to_next'] = $next ? max(0, $next['min'] - $lifetime_points) : 0;
        $tier['progress'] = $next
            ? (int) floor(max(0, min(100, ($lifetime_points - $tier['min']) / ($next['min'] - $tier['min']) * 100)))
            : 100;
        return $tier;
    }
}

if (!function_exists('loyalty_tier_explain_html')) {
    /** "Why this tier" block for a profile: progress bar to the next tier + the tier ladder. */
    function loyalty_tier_explain_html(float $lifetime_points): string
    {
        $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $tier = loyalty_tier_for($lifetime_points);

        $out = '<div class="lt-explain">';
        $out .= '<p class="lt-basis">Based on <strong>' . number_format($lifetime_points, 2) . ' lifetime points earned</strong>. Redeeming rewards does not lower the tier.</p>';
        if ($tier['next']) {
            $out .= '<div class="lt-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $tier['progress'] . '" aria-label="Progress to ' . $h($tier['next']) . '"><span style="width: ' . $tier['progress'] . '%;"></span></div>'
                . '<p class="lt-next">' . number_format($tier['to_next'], 2) . ' more points to reach <strong>' . $h($tier['next']) . '</strong> (about PHP ' . number_format($tier['to_next'] * 100, 0) . ' more in purchases)</p>';
        } else {
            $out .= '<p class="lt-next"><strong>Highest tier reached.</strong></p>';
        }
        $out .= '<ul class="lt-ladder">';
        foreach (loyalty_tiers() as $t) {
            $here = $t['label'] === $tier['label'];
            $out .= '<li' . ($here ? ' class="lt-here"' : '') . '><span class="lt-dot" style="background: ' . $h($t['color']) . ';"></span>'
                . '<span class="lt-name">' . $h($t['label']) . '</span>'
                . '<span class="lt-min">' . ($t['min'] > 0 ? number_format($t['min']) . '+ pts' : 'Starting tier') . '</span></li>';
        }
        $out .= '</ul></div>';
        return $out;
    }
}

if (!function_exists('loyalty_tier_styles')) {
    function loyalty_tier_styles(): string
    {
        return '<style>
            .lt-explain { margin-top: 14px; text-align: left; }
            .lt-basis, .lt-next { margin: 0 0 8px; font-size: 0.8rem; line-height: 1.45; color: #64748b; }
            .lt-bar { height: 8px; border-radius: 999px; background: rgba(148,163,184,.25); overflow: hidden; margin: 4px 0 6px; }
            .lt-bar span { display: block; height: 100%; border-radius: inherit; background: var(--dk-primary, #4a3e94); }
            .lt-ladder { list-style: none; margin: 10px 0 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
            .lt-ladder li { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-radius: 8px; border: 1px solid rgba(148,163,184,.25); font-size: 0.78rem; }
            .lt-ladder li.lt-here { border-color: var(--dk-primary, #4a3e94); background: rgba(79,70,229,.06); font-weight: 700; }
            .lt-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; border: 1px solid rgba(0,0,0,.12); }
            .lt-name { flex: 1; color: inherit; }
            .lt-min { color: #94a3b8; white-space: nowrap; }
            body.dark-mode .lt-basis, body.dark-mode .lt-next { color: var(--dm-text-muted, #9aa5b1); }
            body.dark-mode .lt-ladder li.lt-here { background: rgba(165,180,252,.08); border-color: var(--dm-link, #a5b4fc); }
            body.dark-mode .lt-bar span { background: var(--dm-link, #a5b4fc); }
        </style>';
    }
}
