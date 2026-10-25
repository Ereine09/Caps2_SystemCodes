<?php
/**
 * Delivery photos taken by the rider, stored in `delivery_tracking` (one row per photo):
 *   pickup   - the parcel when the rider takes it from the shop      (status 'picked_up')
 *   delivery - the parcel at the customer's place (proof of delivery) (status 'delivered')
 *
 * Both are asked for but never block the rider: when a rider moves an order on without the photo,
 * a 'photo_skipped_pickup' / 'photo_skipped_delivery' row is recorded and admin/staff see a
 * "No pickup photo" / "No delivery photo" flag on the order until a photo is uploaded.
 * (Orders from before this feature have no skip row, so they are never flagged.)
 */

const DELIVERY_PHOTO_STATUS = ['pickup' => 'picked_up', 'delivery' => 'delivered'];
const DELIVERY_PHOTO_SKIPPED = ['pickup' => 'photo_skipped_pickup', 'delivery' => 'photo_skipped_delivery'];

/**
 * Photos of one order: ['pickup' => photo|null, 'delivery' => photo|null, 'missing' => ['pickup' => bool, 'delivery' => bool]].
 * photo = ['url' => relative path, 'notes' => string, 'created_at' => string] (the latest one per stage).
 */
function delivery_photos(mysqli $conn, int $order_id): array
{
    $out = ['pickup' => null, 'delivery' => null, 'missing' => ['pickup' => false, 'delivery' => false]];
    if ($order_id <= 0) {
        return $out;
    }
    $skipped = ['pickup' => false, 'delivery' => false];
    $stmt = $conn->prepare(
        "SELECT status, proof_image_url, notes, created_at FROM delivery_tracking
         WHERE order_id = ? AND ((status IN ('picked_up', 'delivered') AND proof_image_url IS NOT NULL AND proof_image_url <> '')
                                 OR status IN ('photo_skipped_pickup', 'photo_skipped_delivery'))
         ORDER BY id DESC"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        foreach (DELIVERY_PHOTO_STATUS as $stage => $status) {
            if ($r['status'] === $status && $out[$stage] === null) {
                $out[$stage] = ['url' => (string) $r['proof_image_url'], 'notes' => (string) ($r['notes'] ?? ''), 'created_at' => (string) $r['created_at']];
            }
            if ($r['status'] === DELIVERY_PHOTO_SKIPPED[$stage]) {
                $skipped[$stage] = true;
            }
        }
    }
    $stmt->close();
    foreach ($skipped as $stage => $was_skipped) {
        $out['missing'][$stage] = $was_skipped && $out[$stage] === null;
    }
    return $out;
}

/** The rider moved the order on without this stage's photo: record it (once) so staff see the flag. */
function delivery_photo_record_skip(mysqli $conn, int $order_id, int $rider_id, string $stage): void
{
    if ($order_id <= 0 || $rider_id <= 0 || !isset(DELIVERY_PHOTO_STATUS[$stage])) {
        return;
    }
    $photos = delivery_photos($conn, $order_id);
    if ($photos[$stage] !== null) {
        return; // photo already there
    }
    $status = DELIVERY_PHOTO_SKIPPED[$stage];
    $note = $stage === 'pickup' ? 'Rider picked up the parcel without a pickup photo.' : 'Rider completed the delivery without a delivery photo.';
    $exists = $conn->prepare("SELECT 1 FROM delivery_tracking WHERE order_id = ? AND status = ? LIMIT 1");
    $exists->bind_param('is', $order_id, $status);
    $exists->execute();
    $already = (bool) $exists->get_result()->fetch_row();
    $exists->close();
    if ($already) {
        return;
    }
    $ins = $conn->prepare("INSERT INTO delivery_tracking (order_id, rider_id, status, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
    $ins->bind_param('iiss', $order_id, $rider_id, $status, $note);
    $ins->execute();
    $ins->close();
}
