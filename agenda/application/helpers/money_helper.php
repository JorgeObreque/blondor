<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Formato de dinero
 * ---------------------------------------------------------------------------- */

if (!function_exists('money')) {
    /**
     * Chilean pesos, with the thousand separator the salon uses: $29.990.
     *
     * @param float|int|string|null $value Amount in pesos.
     *
     * @return string
     */
    function money($value): string
    {
        return '$' . number_format((float) $value, 0, ',', '.');
    }
}
