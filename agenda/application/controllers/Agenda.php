<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Agenda móvil
 *
 * Vista del día y flujo de agendamiento en 3 pasos (clienta → servicio → hora)
 * pensado para el celular. Convive con el calendario original (Calendar.php).
 * ---------------------------------------------------------------------------- */

/**
 * Agenda controller.
 *
 * @package Controllers
 */
class Agenda extends EA_Controller
{
    /**
     * Estados de cita que usa la agenda (valor guardado en la BD).
     */
    const STATUSES = ['Agendado', 'Confirmado', 'Llegó', 'No vino', 'Pagado', 'Reagendado', 'Cancelado'];

    /**
     * Ventana en la que "Deshacer" puede borrar una cita recién creada (segundos).
     */
    const UNDO_WINDOW = 300;

    /**
     * Fotos por ficha y su tamaño máximo (px del lado mayor) al guardarlas.
     */
    const MAX_PHOTOS = 12;

    const PHOTO_SIZE = 1600;

    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('customers_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('secretaries_model');
        $this->load->model('roles_model');
        $this->load->model('customer_records_model');

        $this->load->library('accounts');
        $this->load->library('availability');
        $this->load->library('notifications');
        $this->load->library('synchronization');
        $this->load->library('webhooks_client');
    }

    /**
     * Render the agenda page.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('agenda')]);

        $user_id = session('user_id');

        if (cannot('view', PRIV_APPOINTMENTS)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $this->ensure_status_options();

        $providers = array_map(
            fn($provider) => [
                'id' => (int) $provider['id'],
                'name' => trim($provider['first_name'] . ' ' . $provider['last_name']),
                'services' => array_map('intval', $provider['services'] ?? []),
            ],
            $this->allowed_providers(),
        );

        $categories = [];

        foreach ($this->db->get('service_categories')->result_array() as $category) {
            $categories[] = ['id' => (int) $category['id'], 'name' => $category['name']];
        }

        $services = array_map(
            fn($service) => [
                'id' => (int) $service['id'],
                'name' => $service['name'],
                'duration' => (int) $service['duration'],
                'price' => (float) $service['price'],
                'price_type' => $service['price_type'] ?? 'fixed',
                'category_id' => $service['id_service_categories'] ? (int) $service['id_service_categories'] : null,
            ],
            $this->services_model->get_available_services(),
        );

        script_vars([
            'user_id' => $user_id,
            'role_slug' => session('role_slug'),
            'agenda_providers' => $providers,
            'agenda_services' => $services,
            'agenda_categories' => $categories,
            'agenda_statuses' => self::STATUSES,
            'company_name' => setting('company_name'),
        ]);

        html_vars([
            'page_title' => 'Agenda',
            'active_menu' => 'agenda',
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/agenda');
    }

    /**
     * Appointments and free periods of one provider for one date.
     */
    public function day(): void
    {
        try {
            $this->require_permission('view');

            $date = $this->valid_date(request('date'));
            $provider = $this->allowed_provider((int) request('provider_id'));

            $rows = $this->db
                ->select(
                    'a.id, a.start_datetime, a.end_datetime, a.status, a.notes, a.id_services, a.id_users_customer,
                    s.name AS service_name, s.duration, IFNULL(a.price, s.price) AS price,
                    c.first_name, c.last_name, c.phone_number',
                )
                ->from('appointments a')
                ->join('services s', 's.id = a.id_services', 'left')
                ->join('users c', 'c.id = a.id_users_customer', 'left')
                ->where('a.is_unavailability', false)
                ->where('a.id_users_provider', $provider['id'])
                ->where('a.start_datetime >=', $date . ' 00:00:00')
                ->where('a.start_datetime <=', $date . ' 23:59:59')
                ->order_by('a.start_datetime ASC, a.id ASC')
                ->get()
                ->result_array();

            json_response([
                'date' => $date,
                'provider_id' => (int) $provider['id'],
                'appointments' => $this->with_records(array_map([$this, 'appointment_card'], $rows)),
                'free_periods' => $this->availability->get_available_periods($date, $provider),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Start times where the service fits for the given date and provider.
     */
    public function available_hours(): void
    {
        try {
            $this->require_permission('view');

            $date = $this->valid_date(request('date'));
            $provider = $this->allowed_provider((int) request('provider_id'));
            $service = $this->services_model->find((int) request('service_id'));
            $exclude_id = request('exclude_appointment_id') ? (int) request('exclude_appointment_id') : null;

            $hours = $this->availability->get_available_hours($date, $service, $provider, $exclude_id);

            // Today: hide hours that already passed.
            if ($date === date('Y-m-d')) {
                $now = date('H:i');
                $hours = array_values(array_filter($hours, fn($hour) => $hour > $now));
            }

            json_response(['date' => $date, 'hours' => $hours]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Search customers by name or phone, with visit history.
     */
    public function customers(): void
    {
        try {
            $this->require_permission('view', PRIV_CUSTOMERS);

            $keyword = trim((string) request('keyword'));
            $digits = preg_replace('/\D/', '', $keyword);

            // Resolved first: it runs its own query and would reset the builder below.
            $role_id = $this->customers_model->get_customer_role_id();

            $query = $this->db
                ->select('u.id, u.first_name, u.last_name, u.email, u.phone_number')
                ->from('users u')
                ->where('u.id_roles', $role_id);

            if (strlen($digits) >= 3) {
                $query->where(
                    'REGEXP_REPLACE(u.phone_number, "[^0-9]", "") LIKE ' . $this->db->escape('%' . $digits . '%'),
                    null,
                    false,
                );
            } elseif ($keyword !== '') {
                foreach (preg_split('/\s+/', $keyword) as $word) {
                    $query
                        ->group_start()
                        ->like('u.first_name', $word)
                        ->or_like('u.last_name', $word)
                        ->or_like('u.email', $word)
                        ->group_end();
                }
            }

            $customers = $query->order_by('u.update_datetime DESC')->limit(20)->get()->result_array();

            json_response(array_map([$this, 'customer_card'], $customers));
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Create or update an appointment from the 3-step flow.
     *
     * Guards: idempotent per request_id, warns before a duplicate appointment, an overlap with another appointment
     * or a new customer whose phone already exists (the client retries with the matching "force" flag).
     */
    public function save(): void
    {
        try {
            $data = request('appointment') ?? [];
            $customer_data = request('customer') ?? [];
            $force = request('force') ?? [];
            $request_id = (string) request('request_id');

            $appointment_id = !empty($data['id']) ? (int) $data['id'] : null;

            $this->require_permission($appointment_id ? 'edit' : 'add');

            // Same request sent twice (double tap, network retry): answer with the first result.
            $done = session('agenda_requests') ?? [];

            if ($request_id !== '' && isset($done[$request_id])) {
                json_response(['success' => true, 'appointment' => $this->load_card($done[$request_id])]);

                return;
            }

            $provider = $this->allowed_provider((int) ($data['provider_id'] ?? 0));
            $service = $this->services_model->find((int) ($data['service_id'] ?? 0));
            $start = DateTime::createFromFormat('Y-m-d H:i', (string) ($data['start'] ?? ''));

            if (!$start) {
                throw new InvalidArgumentException('Fecha u hora inválida.');
            }

            $end = (clone $start)->modify('+' . (int) $service['duration'] . ' minutes');

            // Customer: existing one, or a new one that is only created after every check below passed.
            $new_customer = null;
            $customer_id = null;

            if (!empty($customer_data['id'])) {
                $customer_id = (int) $customer_data['id'];
                $this->customers_model->find($customer_id);
            } else {
                $this->require_permission('add', PRIV_CUSTOMERS);

                $new_customer = [
                    'first_name' => trim((string) ($customer_data['first_name'] ?? '')),
                    'last_name' => trim((string) ($customer_data['last_name'] ?? '')),
                    'phone_number' => trim((string) ($customer_data['phone_number'] ?? '')),
                    'email' => trim((string) ($customer_data['email'] ?? '')) ?: null,
                ];

                if ($new_customer['first_name'] === '' || preg_replace('/\D/', '', $new_customer['phone_number']) === '') {
                    throw new InvalidArgumentException('La clienta nueva necesita nombre y teléfono.');
                }

                $same_phone = $this->find_customer_by_phone($new_customer['phone_number']);

                if ($same_phone && empty($force['customer'])) {
                    json_response([
                        'success' => false,
                        'reason' => 'customer_exists',
                        'customer' => $this->customer_card($same_phone),
                    ]);

                    return;
                }
            }

            if ($customer_id && empty($force['duplicate'])) {
                $duplicate = $this->db
                    ->select('id')
                    ->from('appointments')
                    ->where('is_unavailability', false)
                    ->where('id_users_customer', $customer_id)
                    ->where('start_datetime', $start->format('Y-m-d H:i:s'))
                    ->where('status !=', 'Cancelado')
                    ->where('id !=', $appointment_id ?? 0)
                    ->get()
                    ->row_array();

                if ($duplicate) {
                    json_response([
                        'success' => false,
                        'reason' => 'duplicate',
                        'appointment' => $this->load_card((int) $duplicate['id']),
                    ]);

                    return;
                }
            }

            if (empty($force['overlap'])) {
                $overlap = $this->db
                    ->select('id')
                    ->from('appointments')
                    ->where('is_unavailability', false)
                    ->where('id_users_provider', $provider['id'])
                    ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
                    ->where('end_datetime >', $start->format('Y-m-d H:i:s'))
                    ->where('status !=', 'Cancelado')
                    ->where('id !=', $appointment_id ?? 0)
                    ->get()
                    ->row_array();

                if ($overlap) {
                    json_response([
                        'success' => false,
                        'reason' => 'overlap',
                        'appointment' => $this->load_card((int) $overlap['id']),
                    ]);

                    return;
                }
            }

            $appointment = $appointment_id ? $this->appointments_model->find($appointment_id) : [];

            if ($appointment_id) {
                $this->check_provider_access((int) $appointment['id_users_provider']);
            }

            if ($new_customer) {
                $customer_id = $this->customers_model->save($new_customer);
            }

            $appointment = array_merge($appointment, [
                'is_unavailability' => false,
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $end->format('Y-m-d H:i:s'),
                'id_users_provider' => (int) $provider['id'],
                'id_users_customer' => $customer_id,
                'id_services' => (int) $service['id'],
                'notes' => trim((string) ($data['notes'] ?? ($appointment['notes'] ?? ''))),
                'status' => $appointment_id ? 'Reagendado' : 'Agendado',
            ]);

            $saved_id = $this->appointments_model->save($appointment);

            if ($request_id !== '') {
                $done[$request_id] = $saved_id;
                session(['agenda_requests' => array_slice($done, -50, null, true)]);
            }

            if (!$appointment_id) {
                $created = session('agenda_created') ?? [];
                $created[$saved_id] = time();
                session(['agenda_created' => array_slice($created, -50, null, true)]);
            }

            // The appointment is saved: a failing email or sync must not turn this into an error for the user.
            $this->after_save($saved_id, (bool) $appointment_id);

            json_response(['success' => true, 'appointment' => $this->load_card($saved_id)]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Change the status of an appointment.
     */
    public function set_status(): void
    {
        try {
            $this->require_permission('edit');

            $status = (string) request('status');

            if (!in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException('Estado no válido: ' . $status);
            }

            $appointment = $this->appointments_model->find((int) request('appointment_id'));

            $this->check_provider_access((int) $appointment['id_users_provider']);

            $appointment['status'] = $status;

            $this->appointments_model->save($appointment);

            json_response(['success' => true, 'appointment' => $this->load_card((int) $appointment['id'])]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * "Deshacer": remove an appointment created in this session a few minutes ago.
     */
    public function undo(): void
    {
        try {
            $this->require_permission('delete');

            $appointment_id = (int) request('appointment_id');
            $created = session('agenda_created') ?? [];

            if (!isset($created[$appointment_id]) || time() - $created[$appointment_id] > self::UNDO_WINDOW) {
                throw new RuntimeException('Esta cita ya no se puede deshacer; cancélala desde su ficha.');
            }

            $appointment = $this->appointments_model->find($appointment_id);

            $this->check_provider_access((int) $appointment['id_users_provider']);

            $this->appointments_model->delete($appointment_id);

            unset($created[$appointment_id]);
            session(['agenda_created' => $created]);

            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }


    /**
     * Customer profile: fixed data, stats, appointments and visit records.
     */
    public function profile(): void
    {
        try {
            $this->require_permission('view', PRIV_CUSTOMERS);

            $customer = $this->customers_model->find((int) request('customer_id'));
            $customer_id = (int) $customer['id'];
            $now = date('Y-m-d H:i:s');

            $stats = $this->db
                ->select(
                    'COUNT(DISTINCT CASE WHEN a.start_datetime < ' . $this->db->escape($now) . ' AND IFNULL(a.status, "") NOT IN ("Cancelado", "No vino") THEN DATE(a.start_datetime) END) AS visits,
                    MAX(CASE WHEN a.start_datetime < ' . $this->db->escape($now) . ' AND IFNULL(a.status, "") NOT IN ("Cancelado", "No vino") THEN a.start_datetime END) AS last_visit,
                    SUM(a.status = "No vino") AS no_shows,
                    SUM(CASE WHEN a.start_datetime < ' . $this->db->escape($now) . ' AND IFNULL(a.status, "") NOT IN ("Cancelado", "No vino") THEN IFNULL(a.price, IFNULL(s.price, 0)) ELSE 0 END) AS spent,
                    MIN(a.start_datetime) AS since',
                    false,
                )
                ->from('appointments a')
                ->join('services s', 's.id = a.id_services', 'left')
                ->where('a.id_users_customer', $customer_id)
                ->where('a.is_unavailability', false)
                ->get()
                ->row_array();

            $rows = $this->db
                ->select(
                    'a.id, a.start_datetime, a.end_datetime, a.status, a.notes, a.id_services, a.id_users_customer,
                    a.id_users_provider, s.name AS service_name, s.duration, IFNULL(a.price, s.price) AS price,
                    c.first_name, c.last_name, c.phone_number, p.first_name AS provider_first_name',
                )
                ->from('appointments a')
                ->join('services s', 's.id = a.id_services', 'left')
                ->join('users c', 'c.id = a.id_users_customer', 'left')
                ->join('users p', 'p.id = a.id_users_provider', 'left')
                ->where('a.id_users_customer', $customer_id)
                ->where('a.is_unavailability', false)
                ->order_by('a.start_datetime DESC')
                ->limit(100)
                ->get()
                ->result_array();

            $appointments = $this->with_records(
                array_map(
                    fn($row) => $this->appointment_card($row) + ['provider_name' => $row['provider_first_name']],
                    $rows,
                ),
            );

            $card = $this->customer_card($customer);

            json_response([
                'customer' => $card,
                'profile' => $this->customer_records_model->get_profile($customer_id),
                'stats' => [
                    'visits' => (int) $stats['visits'],
                    'last_visit' => $stats['last_visit'],
                    'no_shows' => (int) $stats['no_shows'],
                    'spent' => (float) $stats['spent'],
                    'since' => $stats['since'],
                ],
                'appointments' => $appointments,
                'records' => $this->customer_records_model->get_records($customer_id),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Save the fixed data of a customer (alerts, hair, preferences).
     */
    public function save_profile(): void
    {
        try {
            $this->require_permission('edit', PRIV_CUSTOMERS);

            $customer = $this->customers_model->find((int) request('customer_id'));

            $this->customer_records_model->save_profile(
                (int) $customer['id'],
                request('profile') ?? [],
                (int) session('user_id'),
            );

            json_response([
                'success' => true,
                'profile' => $this->customer_records_model->get_profile((int) $customer['id']),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * The visit record of an appointment (or by id), plus what "Repetir última" can copy.
     */
    public function record(): void
    {
        try {
            $this->require_permission('view', PRIV_CUSTOMERS);

            $appointment = null;
            $record = null;

            if (request('record_id')) {
                $record = $this->customer_records_model->find_record((int) request('record_id'));

                if (!$record) {
                    throw new RuntimeException('La ficha no existe.');
                }

                if ($record['appointment_id']) {
                    $appointment = $this->load_card($record['appointment_id']);
                }
            } elseif (request('appointment_id')) {
                $appointment = $this->load_card((int) request('appointment_id'));
                $record = $this->customer_records_model->find_record_by_appointment($appointment['id']);
            }

            $customer_id = $appointment['customer_id'] ?? ($record['customer_id'] ?? (int) request('customer_id'));
            $customer = $this->customers_model->find($customer_id);

            json_response([
                'record' => $record,
                'appointment' => $appointment,
                'customer' => [
                    'id' => (int) $customer['id'],
                    'name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
                ],
                'alerts' => $this->customer_records_model->get_profile((int) $customer['id'])['alerts'],
                'previous' => $this->customer_records_model->previous_by_group(
                    (int) $customer['id'],
                    $record['id'] ?? null,
                ) ?: new stdClass(),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Create or update a visit record. One record per appointment: saving twice updates the same one.
     */
    public function save_record(): void
    {
        try {
            $this->require_permission('edit', PRIV_CUSTOMERS);

            $data = request('record') ?? [];
            $record = [
                'id' => !empty($data['id']) ? (int) $data['id'] : null,
                'details' => $data['details'] ?? [],
                'next_visit_weeks' => $data['next_visit_weeks'] ?? null,
            ];

            foreach (Customer_records_model::RECORD_FIELDS as $field) {
                $record[$field] = $data[$field] ?? '';
            }

            if ($record['id']) {
                $existing = $this->customer_records_model->find_record($record['id']);

                if (!$existing) {
                    throw new RuntimeException('La ficha no existe.');
                }

                $data['appointment_id'] = $existing['appointment_id'];
                $data['customer_id'] = $existing['customer_id'];
                $data['visit_date'] = $existing['visit_date'];
            }

            if (!empty($data['appointment_id'])) {
                $appointment = $this->appointments_model->find((int) $data['appointment_id']);

                $this->check_provider_access((int) $appointment['id_users_provider']);

                $record['id'] = $record['id'] ?: ($this->customer_records_model->find_record_by_appointment((int) $appointment['id'])['id'] ?? null);
                $record['appointment_id'] = (int) $appointment['id'];
                $record['customer_id'] = (int) $appointment['id_users_customer'];
                $record['provider_id'] = (int) $appointment['id_users_provider'];
                $record['service_id'] = (int) $appointment['id_services'];
                $record['visit_date'] = substr($appointment['start_datetime'], 0, 10);
            } else {
                $customer = $this->customers_model->find((int) ($data['customer_id'] ?? 0));

                $record['appointment_id'] = null;
                $record['customer_id'] = (int) $customer['id'];
                $record['provider_id'] = !empty($data['provider_id']) ? (int) $data['provider_id'] : null;
                $record['service_id'] = !empty($data['service_id']) ? (int) $data['service_id'] : null;
                $record['visit_date'] = $this->valid_date($data['visit_date'] ?? date('Y-m-d'));
            }

            $record_id = $this->customer_records_model->save_record($record, (int) session('user_id'));

            json_response(['success' => true, 'record' => $this->customer_records_model->find_record($record_id)]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Add a photo (antes / después) to a visit record.
     */
    public function upload_photo(): void
    {
        try {
            $this->require_permission('edit', PRIV_CUSTOMERS);

            $record = $this->customer_records_model->find_record((int) request('record_id'));

            if (!$record) {
                throw new RuntimeException('Guarda la ficha antes de subir fotos.');
            }

            if ($this->customer_records_model->count_photos($record['id']) >= self::MAX_PHOTOS) {
                throw new RuntimeException('Esta ficha ya tiene ' . self::MAX_PHOTOS . ' fotos.');
            }

            $upload = $_FILES['photo'] ?? null;

            if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                throw new RuntimeException('La foto no llegó completa. Inténtalo de nuevo.');
            }

            $file = bin2hex(random_bytes(16)) . '.jpg';

            $this->store_photo($upload['tmp_name'], $this->photo_dir($record['customer_id']) . $file);

            $this->customer_records_model->add_photo($record['id'], (string) request('kind'), $file);

            json_response(['success' => true, 'record' => $this->customer_records_model->find_record($record['id'])]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    public function delete_photo(): void
    {
        try {
            $this->require_permission('edit', PRIV_CUSTOMERS);

            $photo = $this->customer_records_model->find_photo((int) request('photo_id'));

            if (!$photo) {
                throw new RuntimeException('La foto no existe.');
            }

            $path = FCPATH . 'storage/uploads/visits/' . (int) $photo['id_users_customer'] . '/' . basename($photo['file']);

            if (is_file($path)) {
                unlink($path);
            }

            $this->customer_records_model->delete_photo((int) $photo['id']);

            json_response([
                'success' => true,
                'record' => $this->customer_records_model->find_record((int) $photo['id_visit_records']),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Serve a visit photo to a logged-in user.
     */
    public function photo(int $photo_id = 0): void
    {
        if (cannot('view', PRIV_CUSTOMERS)) {
            abort(403, 'Forbidden');
        }

        $photo = $this->customer_records_model->find_photo($photo_id);
        $path = $photo
            ? FCPATH . 'storage/uploads/visits/' . (int) $photo['id_users_customer'] . '/' . basename($photo['file'])
            : null;

        if (!$path || !is_file($path)) {
            abort(404, 'Not Found');
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit();
    }

    /**
     * Notifications, calendar sync and webhooks after a save. Errors are logged, never thrown.
     */
    private function after_save(int $appointment_id, bool $manage_mode): void
    {
        try {
            $appointment = $this->appointments_model->find($appointment_id);
            $provider = $this->providers_model->find($appointment['id_users_provider']);
            $customer = $this->customers_model->find($appointment['id_users_customer']);
            $service = $this->services_model->find($appointment['id_services']);

            $settings = [
                'company_name' => setting('company_name'),
                'company_link' => setting('company_link'),
                'company_email' => setting('company_email'),
                'date_format' => setting('date_format'),
                'time_format' => setting('time_format'),
            ];

            $this->synchronization->sync_appointment_saved($appointment, $service, $provider, $customer, $settings);

            $this->notifications->notify_appointment_saved(
                $appointment,
                $service,
                $provider,
                $customer,
                $settings,
                $manage_mode,
            );

            $this->webhooks_client->trigger(WEBHOOK_APPOINTMENT_SAVE, $appointment);
        } catch (Throwable $e) {
            log_message('error', 'Agenda: la cita ' . $appointment_id . ' se guardó, pero falló el aviso: ' . $e->getMessage());
        }
    }

    /**
     * Make sure the status setting offers every status the agenda uses.
     */
    private function ensure_status_options(): void
    {
        $options = json_decode((string) setting('appointment_status_options'), true) ?: [];
        $merged = array_values(array_unique(array_merge($options, self::STATUSES)));

        if ($merged !== $options) {
            setting(['appointment_status_options' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function require_permission(string $action, string $resource = PRIV_APPOINTMENTS): void
    {
        if (cannot($action, $resource)) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Providers the current user may see: own calendar for providers, assigned ones for secretaries.
     */
    private function allowed_providers(): array
    {
        $providers = $this->providers_model->get_available_providers();
        $user_id = (int) session('user_id');
        $role_slug = session('role_slug');

        if ($role_slug === DB_SLUG_PROVIDER) {
            $providers = array_filter($providers, fn($provider) => (int) $provider['id'] === $user_id);
        }

        if ($role_slug === DB_SLUG_SECRETARY) {
            $secretary = $this->secretaries_model->find($user_id);
            $providers = array_filter($providers, fn($provider) => in_array($provider['id'], $secretary['providers']));
        }

        return array_values($providers);
    }

    private function allowed_provider(int $provider_id): array
    {
        $this->check_provider_access($provider_id);

        return $this->providers_model->find($provider_id);
    }

    private function check_provider_access(int $provider_id): void
    {
        $ids = array_map(fn($provider) => (int) $provider['id'], $this->allowed_providers());

        if (!in_array($provider_id, $ids, true)) {
            abort(403, 'Forbidden');
        }
    }

    private function valid_date(?string $date): string
    {
        $parsed = DateTime::createFromFormat('Y-m-d', (string) $date);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Fecha inválida.');
        }

        return $date;
    }

    private function find_customer_by_phone(string $phone): ?array
    {
        $digits = substr(preg_replace('/\D/', '', $phone), -8);

        if (strlen($digits) < 8) {
            return null;
        }

        $role_id = $this->customers_model->get_customer_role_id();

        return $this->db
            ->select('id, first_name, last_name, email, phone_number')
            ->from('users')
            ->where('id_roles', $role_id)
            ->where('REGEXP_REPLACE(phone_number, "[^0-9]", "") LIKE ' . $this->db->escape('%' . $digits), null, false)
            ->get()
            ->row_array() ?: null;
    }

    private function customer_card(array $customer): array
    {
        $history = $this->db
            ->select('COUNT(DISTINCT DATE(start_datetime)) AS visits, MAX(start_datetime) AS last_visit')
            ->from('appointments')
            ->where('id_users_customer', $customer['id'])
            ->where('is_unavailability', false)
            ->where('start_datetime <', date('Y-m-d H:i:s'))
            ->get()
            ->row_array();

        $favorite = $this->db
            ->select('id_services, COUNT(*) AS times')
            ->from('appointments')
            ->where('id_users_customer', $customer['id'])
            ->where('is_unavailability', false)
            ->where('id_services IS NOT NULL', null, false)
            ->group_by('id_services')
            ->order_by('times DESC, MAX(start_datetime) DESC', '', false)
            ->limit(1)
            ->get()
            ->row_array();

        return [
            'id' => (int) $customer['id'],
            'first_name' => $customer['first_name'],
            'last_name' => $customer['last_name'],
            'email' => $customer['email'],
            'phone_number' => $customer['phone_number'],
            'visits' => (int) ($history['visits'] ?? 0),
            'last_visit' => $history['last_visit'] ?? null,
            'favorite_service_id' => $favorite ? (int) $favorite['id_services'] : null,
            'alerts' => $this->customer_records_model->get_alerts([$customer['id']])[$customer['id']] ?? '',
        ];
    }

    /**
     * Add the customer alert and whether a visit record exists to appointment cards.
     */
    private function with_records(array $cards): array
    {
        $alerts = $this->customer_records_model->get_alerts(array_column($cards, 'customer_id'));
        $with_record = $this->customer_records_model->appointments_with_record(array_column($cards, 'id'));

        foreach ($cards as &$card) {
            $card['alerts'] = $alerts[$card['customer_id']] ?? '';
            $card['has_record'] = in_array($card['id'], $with_record, true);
        }

        return $cards;
    }

    private function photo_dir(int $customer_id): string
    {
        $base = FCPATH . 'storage/uploads/visits/';
        $dir = $base . $customer_id . '/';

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de fotos.');
        }

        // Las fotos solo se sirven con sesión (Agenda::photo), nunca por URL directa.
        if (!is_file($base . '.htaccess')) {
            file_put_contents(
                $base . '.htaccess',
                "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^ - [F]\n</IfModule>\n<IfModule !mod_rewrite.c>\n    Require all denied\n</IfModule>\n",
            );
        }

        return $dir;
    }

    /**
     * Re-encode an uploaded image as JPEG, at most PHOTO_SIZE px (drops EXIF data such as the location).
     */
    private function store_photo(string $source, string $target): void
    {
        $info = @getimagesize($source);

        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('El archivo no es una foto (JPG, PNG o WEBP).');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG => imagecreatefrompng($source),
            IMAGETYPE_WEBP => imagecreatefromwebp($source),
        };

        if (!$image) {
            throw new InvalidArgumentException('No se pudo leer la foto.');
        }

        [$width, $height] = $info;
        $scale = min(1, self::PHOTO_SIZE / max($width, $height));

        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $resized;
        }

        $ok = imagejpeg($image, $target, 82);
        imagedestroy($image);

        if (!$ok) {
            throw new RuntimeException('No se pudo guardar la foto.');
        }
    }

    private function load_card(int $appointment_id): array
    {
        $row = $this->db
            ->select(
                'a.id, a.start_datetime, a.end_datetime, a.status, a.notes, a.id_services, a.id_users_customer,
                a.id_users_provider, s.name AS service_name, s.duration, IFNULL(a.price, s.price) AS price,
                c.first_name, c.last_name, c.phone_number',
            )
            ->from('appointments a')
            ->join('services s', 's.id = a.id_services', 'left')
            ->join('users c', 'c.id = a.id_users_customer', 'left')
            ->where('a.id', $appointment_id)
            ->get()
            ->row_array();

        if (!$row) {
            throw new RuntimeException('La cita no existe.');
        }

        return $this->with_records([$this->appointment_card($row)])[0];
    }

    private function appointment_card(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'start' => $row['start_datetime'],
            'end' => $row['end_datetime'],
            'status' => $row['status'] ?: 'Agendado',
            'notes' => $row['notes'],
            'service_id' => $row['id_services'] ? (int) $row['id_services'] : null,
            'service_name' => $row['service_name'],
            'price' => (float) $row['price'],
            'provider_id' => isset($row['id_users_provider']) ? (int) $row['id_users_provider'] : null,
            'customer_id' => (int) $row['id_users_customer'],
            'customer_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'phone_number' => $row['phone_number'],
        ];
    }
}
