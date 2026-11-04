<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Read-only session probe: does not renew an idle login or expose user information. */
class Backoffice_session extends CI_Controller
{
    public function __construct()
    {
        // The ordinary CI session constructor rotates ids. A read-only probe must not rotate/write one.
        $config = &load_class('Config', 'core');
        $config->set_item('sess_time_to_update', 0);
        parent::__construct();
    }

    public function status(): void
    {
        $user_id = (int) ($_SESSION['user_id'] ?? 0);
        $last_activity = (int) ($_SESSION['backoffice_last_activity'] ?? $_SESSION['__ci_last_regenerate'] ?? 0);
        $expiration = (int) config('sess_expiration');
        if (session_status() === PHP_SESSION_ACTIVE) { session_abort(); }
        if ($this->input->method() !== 'get') { abort(405); }
        $expired = $expiration > 0 && $last_activity > 0 && time() - $last_activity >= $expiration;
        $exists = $user_id > 0 && $this->db->where('id', $user_id)->count_all_results('users') > 0;
        $authenticated = $exists && !$expired;
        json_response(['authenticated' => $authenticated], $authenticated ? 200 : 401, [
            'Cache-Control: no-store, private', 'Pragma: no-cache',
        ]);
    }
}
