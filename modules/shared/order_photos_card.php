<?php
/**
 * Order details (admin + staff): the rider's PICKUP photo and the "no photo" flags.
 * The delivery photo keeps its own "Proof of Delivery" card on the page.
 * Expects $od_photos = delivery_photos($conn, $order_id) (app/helpers/delivery_proof_helper.php)
 * and the page's openProofModal(url, title) viewer.
 */
if (empty($od_photos) || (!$od_photos['pickup'] && !$od_photos['missing']['pickup'] && !$od_photos['missing']['delivery'])) {
    return;
}
$od_pickup = $od_photos['pickup'];
$od_pickup_url = $od_pickup ? BASE_URL . '/' . $od_pickup['url'] : '';
?>
<div class="info-card" style="border-left: 5px solid <?php echo ($od_photos['missing']['pickup'] || $od_photos['missing']['delivery']) ? '#dc2626' : '#6366f1'; ?>;">
    <h4><i class="fas fa-images"></i> Rider Photos</h4>
    <?php if ($od_photos['missing']['pickup']): ?>
        <div style="display: flex; gap: 8px; align-items: flex-start; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 12px; margin-bottom: 10px; font-size: 0.85rem; font-weight: 600;">
            <i class="fas fa-triangle-exclamation" style="margin-top: 2px;"></i>
            <span>No pickup photo. The rider took the parcel from the shop without one.</span>
        </div>
    <?php endif; ?>
    <?php if ($od_photos['missing']['delivery']): ?>
        <div style="display: flex; gap: 8px; align-items: flex-start; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 12px; margin-bottom: 10px; font-size: 0.85rem; font-weight: 600;">
            <i class="fas fa-triangle-exclamation" style="margin-top: 2px;"></i>
            <span>No delivery photo. The rider completed the delivery without proof.</span>
        </div>
    <?php endif; ?>
    <?php if ($od_pickup): ?>
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
            <img src="<?php echo htmlspecialchars($od_pickup_url); ?>" alt="Pickup photo thumbnail" class="receipt-thumb"
                 onclick="openProofModal('<?php echo htmlspecialchars($od_pickup_url, ENT_QUOTES); ?>', 'Pickup Photo')"
                 onerror="this.replaceWith(Object.assign(document.createElement('span'), {textContent: 'Pickup photo unavailable', style: 'color:#b91c1c; font-size:0.8rem; font-weight:600;'}))">
            <button type="button" class="button-link" onclick="openProofModal('<?php echo htmlspecialchars($od_pickup_url, ENT_QUOTES); ?>', 'Pickup Photo')">
                <i class="fas fa-expand"></i> View pickup photo
            </button>
        </div>
        <div class="info-item"><span class="info-label">Picked up</span><span class="info-value"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($od_pickup['created_at']))); ?></span></div>
        <?php if ($od_pickup['notes'] !== ''): ?>
            <div class="info-item" style="flex-direction: column; gap: 5px;">
                <span class="info-label">Pickup notes</span>
                <span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($od_pickup['notes'])); ?></span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
