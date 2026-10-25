<?php
/**
 * The checkout progress tracker shown on checkout.php (step 2) and payment.php (step 3; all done once
 * the order is placed): Cart -> Details -> Payment -> Order placed.
 */
function checkout_steps(int $current): string
{
    $steps = [
        ['fa-shopping-cart', 'Cart', 'Items ready'],
        ['fa-map-marker-alt', 'Details', 'Pickup or delivery'],
        ['fa-credit-card', 'Payment', 'Choose how to pay'],
        ['fa-receipt', 'Order placed', 'We start preparing it'],
    ];
    $out = '<ol class="ck-steps" aria-label="Checkout progress">';
    foreach ($steps as $i => [$icon, $label, $hint]) {
        $n = $i + 1;
        $state = $n < $current ? 'done' : ($n === $current ? 'now' : 'next');
        $out .= '<li class="ck-step is-' . $state . '"' . ($state === 'now' ? ' aria-current="step"' : '') . '>'
            . '<span class="ck-dot">' . ($state === 'done' ? '<i class="fas fa-check"></i>' : '<i class="fas ' . $icon . '"></i>') . '</span>'
            . '<span class="ck-text"><strong>' . $label . '</strong><small>' . ($state === 'done' ? 'Done' : $hint) . '</small></span>'
            . '</li>';
    }
    $out .= '</ol>';

    static $styled = false;
    if (!$styled) {
        $styled = true;
        $out .= '<style>
            .ck-steps { list-style: none; margin: 4px 0 16px; padding: 14px 16px; display: grid; grid-template-columns: repeat(4, 1fr);
                background: var(--customer-surface, #fff); border: 1px solid var(--customer-border, #e2e8f0); border-radius: 14px; }
            .ck-step { position: relative; display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; min-width: 0; }
            .ck-step:not(:first-child)::before { content: ""; position: absolute; top: 18px; right: calc(50% + 24px); left: calc(-50% + 24px); height: 3px; border-radius: 3px;
                background: var(--customer-border, #e2e8f0); }
            .ck-step.is-done:not(:first-child)::before, .ck-step.is-now:not(:first-child)::before { background: var(--success, #16a34a); }
            .ck-dot { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .95rem;
                background: var(--customer-surface-2, #f1f5f9); color: var(--customer-muted, #94a3b8); border: 2px solid var(--customer-border, #e2e8f0); }
            .ck-step.is-done .ck-dot { background: var(--success, #16a34a); border-color: var(--success, #16a34a); color: #fff; }
            .ck-step.is-now .ck-dot { background: var(--dk-primary, #4f46e5); border-color: var(--dk-primary, #4f46e5); color: #fff; box-shadow: 0 0 0 5px rgba(79, 70, 229, .18); }
            .ck-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
            .ck-text strong { font-size: .86rem; color: var(--customer-text, #1e293b); }
            .ck-text small { font-size: .74rem; color: var(--customer-muted, #64748b); }
            .ck-step.is-next .ck-text strong { color: var(--customer-muted, #94a3b8); }
            .ck-step.is-now .ck-text strong { color: var(--dk-primary, #4f46e5); }
            html[data-theme="dark"] .ck-step.is-now .ck-text strong { color: #a5b4fc; }
            @media (max-width: 560px) {
                .ck-steps { padding: 12px 8px; }
                .ck-dot { width: 32px; height: 32px; font-size: .8rem; }
                .ck-step:not(:first-child)::before { top: 15px; right: calc(50% + 20px); left: calc(-50% + 20px); }
                .ck-text strong { font-size: .74rem; }
                .ck-text small { display: none; }
            }
        </style>';
    }
    return $out;
}
