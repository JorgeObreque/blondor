<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Caché de la app
 * ---------------------------------------------------------------------------- */

if (!function_exists('app_cache')) {
    /**
     * The cache of the app (files).
     *
     * This CodeIgniter fork keeps a static list of "supported" adapters between instances: when the cache driver
     * is loaded more than once in the same request, the second instance comes without its adapter and every
     * read blows up. Here the driver is always (re)loaded on the instance we hand out, so it always works.
     *
     * @return CI_Cache
     */
    function &app_cache()
    {
        static $cache = null;

        if ($cache === null) {
            $CI = &get_instance();

            $CI->load->driver('cache', ['adapter' => 'file']);

            $cache = $CI->cache;
        }

        $cache->load_driver('file');

        return $cache;
    }
}
