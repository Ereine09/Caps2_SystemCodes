<?php
/**
 * Profile photos for customers (customers.profile_photo) and staff/riders (users.profile_photo).
 *
 * Photos arrive as a data URI (the browser crops/scales them to a small square JPEG first),
 * are re-checked here as real images, and are stored in uploads/avatars/. The column holds the
 * project-relative path (e.g. "uploads/avatars/c12_ab12cd34.jpg"); NULL = no photo, show initials.
 *
 * Used by: customer/profile_photo_api.php, modules/rider/rider_profile_api.php (set_photo /
 * remove_photo), and the pages that show avatars (profile_avatar_html).
 */

const PROFILE_PHOTO_TABLES = ['customer' => 'customers', 'user' => 'users'];
const PROFILE_PHOTO_MAX_BYTES = 3 * 1024 * 1024;

/** Adds the profile_photo columns once per request (runtime migration). */
function profile_photo_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (PROFILE_PHOTO_TABLES as $table) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL DEFAULT NULL");
    }
}

/** Stored photo path for one account, or null. $kind: 'customer' | 'user'. */
function profile_photo_get(mysqli $conn, string $kind, int $id): ?string
{
    if (!isset(PROFILE_PHOTO_TABLES[$kind]) || $id <= 0) {
        return null;
    }
    profile_photo_ensure($conn);
    $stmt = $conn->prepare('SELECT profile_photo FROM `' . PROFILE_PHOTO_TABLES[$kind] . '` WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $path = trim((string) ($row['profile_photo'] ?? ''));
    return $path !== '' ? $path : null;
}

/** Photo paths for many accounts at once: [id => path] (ids without a photo are left out). */
function profile_photo_map(mysqli $conn, string $kind, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!isset(PROFILE_PHOTO_TABLES[$kind]) || !$ids) {
        return [];
    }
    profile_photo_ensure($conn);
    $res = $conn->query('SELECT id, profile_photo FROM `' . PROFILE_PHOTO_TABLES[$kind] . '` WHERE id IN (' . implode(',', $ids) . ') AND profile_photo IS NOT NULL AND profile_photo <> ""');
    $map = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $map[(int) $row['id']] = $row['profile_photo'];
    }
    return $map;
}

/**
 * Saves a new photo from a data URI and replaces the old one.
 * @return array{ok:bool, url?:string, error?:string}  url = project-relative path
 */
function profile_photo_save(mysqli $conn, string $kind, int $id, string $data_uri): array
{
    if (!isset(PROFILE_PHOTO_TABLES[$kind]) || $id <= 0) {
        return ['ok' => false, 'error' => 'Invalid account.'];
    }
    if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $data_uri, $m)) {
        return ['ok' => false, 'error' => 'Please choose a photo.'];
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'Invalid image data.'];
    }
    if (strlen($bytes) > PROFILE_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Photo is too large (max 3MB).'];
    }
    // Trust the bytes, not the declared type: they must really be an image.
    $probe = @getimagesizefromstring($bytes);
    $ext_by_type = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if ($probe === false || !isset($ext_by_type[$probe[2]])) {
        return ['ok' => false, 'error' => 'Please upload a JPG, PNG or WebP photo.'];
    }

    $dir = __DIR__ . '/../../uploads/avatars/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Server storage is not available.'];
    }
    $name = ($kind === 'customer' ? 'c' : 'u') . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext_by_type[$probe[2]];
    if (@file_put_contents($dir . $name, $bytes) === false) {
        return ['ok' => false, 'error' => 'Could not save the photo. Please try again.'];
    }
    @chmod($dir . $name, 0644);

    $old = profile_photo_get($conn, $kind, $id);
    $path = 'uploads/avatars/' . $name;
    $stmt = $conn->prepare('UPDATE `' . PROFILE_PHOTO_TABLES[$kind] . '` SET profile_photo = ? WHERE id = ?');
    $stmt->bind_param('si', $path, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        @unlink($dir . $name);
        return ['ok' => false, 'error' => 'Could not save the photo. Please try again.'];
    }
    profile_photo_delete_file($old);
    return ['ok' => true, 'url' => $path];
}

/** Removes the account's photo (back to initials). */
function profile_photo_remove(mysqli $conn, string $kind, int $id): bool
{
    if (!isset(PROFILE_PHOTO_TABLES[$kind]) || $id <= 0) {
        return false;
    }
    $old = profile_photo_get($conn, $kind, $id);
    $stmt = $conn->prepare('UPDATE `' . PROFILE_PHOTO_TABLES[$kind] . '` SET profile_photo = NULL WHERE id = ?');
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        profile_photo_delete_file($old);
    }
    return $ok;
}

/** Deletes a stored avatar file - only ever inside uploads/avatars/. */
function profile_photo_delete_file(?string $path): void
{
    if ($path && preg_match('#^uploads/avatars/[A-Za-z0-9_]+\.(jpg|png|webp)$#', $path)) {
        @unlink(__DIR__ . '/../../' . $path);
    }
}

/** Initials for the no-photo fallback ("Juan Dela Cruz" -> "JC"). */
function profile_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $i = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '');
    return mb_strtoupper($i !== '' ? $i : '?');
}

/**
 * Inner HTML for an avatar circle: the photo (filling the circle) or the initials.
 * The caller supplies the circle element itself, so each page keeps its own sizing/colors.
 */
function profile_avatar_inner(?string $photo, string $name, int $initial_letters = 2): string
{
    $initials = profile_initials($name);
    if ($initial_letters === 1) {
        $initials = mb_substr($initials, 0, 1);
    }
    if ($photo) {
        return '<img src="' . htmlspecialchars(BASE_URL . '/' . $photo, ENT_QUOTES) . '" alt="' . htmlspecialchars($name, ENT_QUOTES) . '"'
            . ' style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"'
            . ' onerror="this.replaceWith(document.createTextNode(' . htmlspecialchars(json_encode($initials), ENT_QUOTES) . '))">';
    }
    return htmlspecialchars($initials);
}
