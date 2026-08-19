<?php
/**
 * QR Code Generation Helper
 *
 * This helper centralizes the logic for creating QR codes using the installed
 * endroid/qr-code library (version 4.x), ensuring consistent implementation
 * across the application.
 */

// The Composer autoloader is now handled by a central bootstrap file,
// so this line is no longer needed here.

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Generates a data URI for a QR code from a given payload.
 *
 * @param string $payload The data to encode into the QR code.
 * @return string The generated data URI for the QR code image, or an empty string on failure.
 */
function generate_qr_code_uri(string $payload): string
{
    try {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($payload)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->build();

        return $result->getDataUri();
    } catch (Exception $e) {
        error_log('QR Code Generation Failed: ' . $e->getMessage());
        return ''; // Return empty string on failure, UI will handle this.
    }
}