<?php
/**
 * Small input-validation helpers.
 */

if (!function_exists('clean_date')) {
    /**
     * Returns $value only if it is a real YYYY-MM-DD date, otherwise $default.
     * Use this before putting any request-supplied date into a SQL string.
     */
    function clean_date($value, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            if (checkdate($m, $d, $y)) {
                return $value;
            }
        }
        return $default;
    }
}
