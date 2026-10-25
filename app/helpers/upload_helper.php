<?php
/**
 * Secure image upload helper.
 *
 * Replaces the old pattern of trusting $_FILES['type'] (a client-supplied header)
 * and reusing the client-supplied file extension, which allowed uploading .php
 * files and getting remote code execution.
 *
 * This helper:
 *   - verifies the bytes are a real image via getimagesize()
 *   - derives the extension from the detected image type, never from the client
 *   - writes a random filename so a caller can never be tricked into an unsafe name
 */

if (!function_exists('save_uploaded_image')) {
    /**
     * @param array  $file        One entry from $_FILES (e.g. $_FILES['payment_proof'])
     * @param string $dest_dir    Absolute filesystem directory to write into (trailing slash optional)
     * @param string $url_prefix  Public URL prefix stored/returned (e.g. '/uploads/proofs/')
     * @param string $name_prefix Filename prefix (e.g. 'proof_')
     * @param int    $max_bytes   Maximum accepted size in bytes
     * @return array{ok:bool, url?:string, path?:string, error?:string}
     */
    function save_uploaded_image(array $file, string $dest_dir, string $url_prefix, string $name_prefix = 'img_', int $max_bytes = 5242880): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'No file was uploaded or the upload failed.'];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_bytes) {
            return ['ok' => false, 'error' => 'File is too large. Maximum size is ' . round($max_bytes / 1048576) . 'MB.'];
        }

        // Verify the actual bytes are an image and get the *real* type.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Invalid file. Please upload a real JPG, PNG, GIF or WebP image.'];
        }

        $ext_map = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!isset($ext_map[$info[2]])) {
            return ['ok' => false, 'error' => 'Unsupported image type. Allowed: JPG, PNG, GIF, WebP.'];
        }
        $ext = $ext_map[$info[2]];

        if (!is_dir($dest_dir)) {
            // 0755: owner writable, others read/traverse only — never 0777.
            if (!@mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
                return ['ok' => false, 'error' => 'Server storage is not available.'];
            }
        }

        try {
            $filename = $name_prefix . bin2hex(random_bytes(16)) . '.' . $ext;
        } catch (Exception $e) {
            $filename = $name_prefix . uniqid('', true) . '.' . $ext;
        }

        $destination = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['ok' => false, 'error' => 'Failed to store the uploaded image. Please try again.'];
        }

        @chmod($destination, 0644);

        return [
            'ok'   => true,
            'url'  => rtrim($url_prefix, '/') . '/' . $filename,
            'path' => $destination,
        ];
    }
}
