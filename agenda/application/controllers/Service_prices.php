<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Servicios y precios
 *
 * Catálogo de servicios pensado para el celular: ordenado por uso, precios en un toque, alza masiva con redondeo,
 * precio «desde» y limpieza de servicios sin uso. Convive con la página original de servicios (Services.php).
 * ---------------------------------------------------------------------------- */

/**
 * Service prices controller.
 *
 * @package Controllers
 */
class Service_prices extends EA_Controller
{
    /**
     * Cambios por envío (alza masiva incluida).
     */
    const MAX_CHANGES = 100;

    public function __construct()
    {
        parent::__construct();

        $this->load->model('service_catalog_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
    }

    /**
     * Render the service prices page.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('precios')]);

        $user_id = session('user_id');

        if (cannot('view', PRIV_SERVICES)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $categories = [];

        foreach ($this->db->order_by('name ASC')->get('service_categories')->result_array() as $category) {
            $categories[] = ['id' => (int) $category['id'], 'name' => trim($category['name'])];
        }

        script_vars([
            'user_id' => $user_id,
            'role_slug' => session('role_slug'),
            'catalog_services' => $this->service_catalog_model->get_catalog(),
            'catalog_categories' => $categories,
            'catalog_can_edit' => can('edit', PRIV_SERVICES),
            'catalog_recent_months' => Service_catalog_model::RECENT_MONTHS,
        ]);

        html_vars([
            'page_title' => 'Servicios y precios',
            'active_menu' => PRIV_SERVICES,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/service_prices');
    }

    /**
     * Save quick edits: one service, a bulk price change or an undo.
     */
    public function save(): void
    {
        try {
            if (cannot('edit', PRIV_SERVICES)) {
                abort(403, 'Forbidden');
            }

            $changes = request('changes');

            if (!is_array($changes) || !$changes || count($changes) > self::MAX_CHANGES) {
                throw new InvalidArgumentException('No hay cambios para guardar.');
            }

            $saved = $this->service_catalog_model->save_changes(array_values($changes), (int) session('user_id'));

            json_response(['success' => true, 'saved' => $saved]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
