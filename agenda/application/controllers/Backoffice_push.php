<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Installation metadata is public; subscription management and appointment links require staff access. */
class Backoffice_push extends EA_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('staff_push', null, 'push_service');
        if (in_array($this->router->method, ['status', 'subscribe', 'unsubscribe'], true)) {
            if ($this->input->method() !== 'post') { abort(405); }
            $this->require_staff();
            $this->output->set_header('Cache-Control: no-store, private');
        }
    }

    protected function require_staff(): void
    {
        if (!session('user_id') || !$this->push_service->eligible_user((int) session('user_id'))) { abort(403); }
    }

    public function index(): void
    {
        if (!session('user_id')) {
            session(['dest_url' => site_url('backoffice_push')]); redirect('login'); return;
        }
        $this->require_staff();
        $this->output->set_header('Cache-Control: no-store, private');
        html_vars(['page_title' => 'Notificaciones', 'active_menu' => 'notifications',
            'push_public_key' => $this->push_service->public_key(),
            'user_display_name' => $this->accounts->get_user_display_name(session('user_id'))]);
        $this->load->view('pages/backoffice_notifications');
    }

    public function manifest(): void
    {
        $this->output->set_content_type('application/manifest+json')->set_output(json_encode([
            'id' => site_url('agenda'), 'name' => 'Blondor · Agenda', 'short_name' => 'Blondor',
            'description' => 'Agenda privada del equipo Blondor', 'lang' => 'es-CL',
            'start_url' => site_url('agenda'), 'scope' => base_url(), 'display' => 'standalone',
            'background_color' => '#ffffff', 'theme_color' => '#015288',
            'icons' => array_map(fn($size) => ['src' => base_url('assets/img/pwa/blondor-' . $size . '.png'),
                'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any'], [192, 512]),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function status(): void
    {
        $row = $this->push_service->active_for_user((int) session('user_id'), (string) request('endpoint'));
        if ($row) { session(['push_subscription_id' => (int) $row['id']]); }
        json_response(['available' => $this->push_service->available(), 'subscribed' => $row !== null]);
    }

    public function subscribe(): void
    {
        try {
            if (!$this->push_service->available()) { throw new RuntimeException('Las notificaciones no están disponibles en este momento.'); }
            $subscription = json_decode((string) request('subscription'), true);
            if (!is_array($subscription)) { throw new InvalidArgumentException('La suscripción está incompleta.'); }
            $id = $this->push_service->subscribe((int) session('user_id'), $subscription);
            session(['push_subscription_id' => $id]);
            json_response(['success' => true]);
        } catch (InvalidArgumentException $e) { json_response(['message' => $e->getMessage()], 400); }
        catch (Throwable $e) { json_response(['message' => 'No se pudo activar este dispositivo. Inténtalo otra vez.'], 503); }
    }

    public function unsubscribe(): void
    {
        $this->push_service->disable((int) session('user_id'), (string) request('endpoint'));
        session(['push_subscription_id' => null]);
        json_response(['success' => true]);
    }

    public function open(int $appointment_id = 0): void
    {
        if (!session('user_id')) {
            session(['dest_url' => site_url('backoffice_push/open/' . $appointment_id)]); redirect('login'); return;
        }
        $this->require_staff();
        $appointment = $this->db->get_where('appointments', ['id' => $appointment_id])->row_array();
        if (!$appointment) { abort(404); }
        if (!$this->push_service->eligible_user((int) session('user_id'), (int) $appointment['id_users_provider'])) { abort(403); }
        redirect(site_url('agenda') . '?' . http_build_query(['date' => substr($appointment['start_datetime'], 0, 10),
            'provider' => (int) $appointment['id_users_provider'], 'appointment' => $appointment_id]));
    }
}
