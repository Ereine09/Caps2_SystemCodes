<?php
/**
 * Automatic product SKUs: ABC-001 (3 uppercase letters + hyphen + 3 digits).
 *
 *   Prefix  = the first three LETTERS of the product name, uppercase (accents dropped, digits/spaces
 *             skipped). Names with fewer than three letters are padded with "X": "B1" -> BXX, "123" -> XXX.
 *   Number  = the next number for that prefix: highest ABC-### already in tbl_product_inventory + 1.
 *             No extra table; the UNIQUE KEY on tbl_product_inventory.sku is the final guard.
 *   Limit   = 999 per prefix; after that sku_generate() throws SkuLimitException.
 *
 * The SKU is set once when a product is created; renaming the product later keeps it.
 * sku_renumber_all() rewrote every existing SKU into this format once (system_settings SKU_FORMAT).
 */

class SkuLimitException extends RuntimeException
{
}

const SKU_MAX_NUMBER = 999;
const SKU_FORMAT_VERSION = 'ABC-000';

/** Kept for older callers: SKUs need no schema of their own any more. */
function sku_ensure_schema(mysqli $conn): void
{
}

/** "Amoxyclyn" -> AMO, "Ña 1" -> NAX, "123" -> XXX. */
function sku_prefix(string $name): string
{
    $ascii = $name;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) {
            $ascii = $t;
        }
    }
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ascii));
    return str_pad(substr($letters, 0, 3), 3, 'X');
}

function sku_format(string $prefix, int $n): string
{
    return $prefix . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
}

/** Highest number already used in tbl_product_inventory for this prefix (ABC-### only). */
function sku_existing_max(mysqli $conn, string $prefix): int
{
    $pattern = '^' . $prefix . '-[0-9]{3}$';
    $st = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)), 0) FROM tbl_product_inventory WHERE sku REGEXP ?");
    $st->bind_param('s', $pattern);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/** What the next SKU would be (for the read-only preview on the New Product form). */
function sku_preview(mysqli $conn, string $name): array
{
    $prefix = sku_prefix($name);
    $next = sku_existing_max($conn, $prefix) + 1;
    if ($next > SKU_MAX_NUMBER) {
        return ['ok' => false, 'sku' => null, 'message' => "All SKU numbers for $prefix are used ($prefix-999). Please contact the administrator."];
    }
    return ['ok' => true, 'sku' => sku_format($prefix, $next), 'message' => ''];
}

/**
 * The next SKU for $name, e.g. AMO-002.
 * @throws SkuLimitException when the prefix has reached 999.
 */
function sku_generate(mysqli $conn, string $name): string
{
    $p = sku_preview($conn, $name);
    if (!$p['ok']) {
        throw new SkuLimitException($p['message']);
    }
    return $p['sku'];
}

/**
 * One-time: gives EVERY product a SKU in the ABC-001 format, numbered per prefix in the order the
 * products were added (oldest = 001). Runs only while system_settings SKU_FORMAT isn't 'ABC-000'
 * (called from the admin Products page). The old -> new list is written to activity_logs.
 * SKUs live only in tbl_product_inventory (orders, batches and POs use the product id), so nothing
 * else needs changing. Returns how many SKUs changed.
 */
function sku_renumber_all(mysqli $conn, ?int $user_id = null): int
{
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'SKU_FORMAT' LIMIT 1");
    if ($res && ($row = $res->fetch_row()) && $row[0] === SKU_FORMAT_VERSION) {
        return 0;
    }

    $products = $conn->query("SELECT id, sku, name FROM tbl_product_inventory ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    $counts = [];
    $plan = [];
    foreach ($products as $p) {
        $prefix = sku_prefix((string) $p['name']);
        $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
        if ($counts[$prefix] > SKU_MAX_NUMBER) {
            error_log("sku_renumber_all: more than " . SKU_MAX_NUMBER . " products start with $prefix; product #{$p['id']} keeps {$p['sku']}");
            continue;
        }
        $plan[(int) $p['id']] = [$p['sku'], sku_format($prefix, $counts[$prefix])];
    }

    $changed = 0;
    $conn->begin_transaction();
    try {
        // Two passes so a new SKU never clashes with an old one that hasn't been renamed yet.
        $tmp = $conn->prepare("UPDATE tbl_product_inventory SET sku = ? WHERE id = ?");
        foreach ($plan as $id => [$old, $new]) {
            if ($old !== $new) {
                $t = '~TMP-' . $id;
                $tmp->bind_param('si', $t, $id);
                $tmp->execute();
            }
        }
        $log = [];
        foreach ($plan as $id => [$old, $new]) {
            if ($old !== $new) {
                $tmp->bind_param('si', $new, $id);
                $tmp->execute();
                $log[] = "$old -> $new";
                $changed++;
            }
        }
        $tmp->close();

        $v = SKU_FORMAT_VERSION;
        $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('SKU_FORMAT', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->bind_param('s', $v);
        $st->execute();
        $st->close();

        if ($changed > 0) {
            $details = "Renumbered $changed product SKUs to the ABC-001 format: " . implode('; ', $log);
            $action = 'Renumber SKUs';
            $st = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
            $st->bind_param('iss', $user_id, $action, $details);
            $st->execute();
            $st->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('sku_renumber_all failed: ' . $e->getMessage());
        return 0;
    }
    return $changed;
}
