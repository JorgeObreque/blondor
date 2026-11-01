<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Reserva pública con pago
 *
 * El recorrido completo:
 *
 *   1. prepare()      La clienta elige servicio, día y hora. Se valida todo en el servidor, se aparta el horario
 *                     (hold) y se calcula el abono según la política.
 *   2. start_payment() Se crea el intento de pago y se llama a Webpay FUERA de toda transacción.
 *   3. handle_webpay_return() Webpay vuelve: se toma el control del intento (claim), se confirma con Transbank y,
 *                     solo si el pago está autorizado, se crea la cita con su saldo y se encolan los avisos.
 *   4. reconcile()    El cron recupera los pagos a medio camino y expira las reservas que nadie terminó.
 *
 * Reglas que no se negocian:
 *   - La cita se confirma solo después de una autorización validada en el servidor.
 *   - El precio lo calcula el servidor; el navegador solo dice qué opción quiere.
 *   - Nunca se hace HTTP dentro de una transacción.
 *   - Un aviso que falla no deja de invalidar una cita pagada.
 * ---------------------------------------------------------------------------- */

/**
 * Booking payments library.
 *
 * @package Libraries
 */
class Booking_payments
{
    /**
     * Minutos que dura el apartamiento del horario. Webpay aguanta 15 minutos: damos un poco más.
     */
    const HOLD_TTL_MINUTES = 20;

    /**
     * Segundos que se toma el control de un intento de pago (lease).
     */
    const CLAIM_TTL_SECONDS = 180;

    /**
     * Vida de la sesión de Webpay (Transbank la cierra a los 15 minutos).
     */
    const WEBPAY_SESSION_TTL = 900;

    /**
     * Reservas nuevas por equipo en una hora (es una red de seguridad, no un límite del salón).
     */
    const MAX_HOLDS_PER_IP = 40;

    /**
     * Reservas nuevas por correo en una hora.
     */
    const MAX_HOLDS_PER_EMAIL = 20;

    /**
     * Intentos de pago por reserva.
     */
    const MAX_ATTEMPTS = 5;

    /**
     * Intentos de entrega de un aviso antes de dejarlo como fallido.
     */
    const OUTBOX_MAX_ATTEMPTS = 6;

    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Booking payments constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('appointments_model');
        $this->CI->load->model('booking_payments_model');
        $this->CI->load->model('consents_model');
        $this->CI->load->model('customers_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('service_variants_model');
        $this->CI->load->model('services_model');

        $this->CI->load->library('availability');
        $this->CI->load->library('deposit_policy');
        $this->CI->load->library('notifications');
        $this->CI->load->library('slot_lock');
        $this->CI->load->library('synchronization');
        $this->CI->load->library('webhooks_client');
        $this->CI->load->library('webpay');
    }

    /**
     * @return Booking_payments_model
     */
    protected function model(): Booking_payments_model
    {
        return $this->CI->booking_payments_model;
    }

    /**
     * @return Deposit_policy
     */
    protected function policy(): Deposit_policy
    {
        return $this->CI->deposit_policy;
    }

    /* ---------------------------------------------------------------------
     * 1. La reserva (hold)
     * ------------------------------------------------------------------ */

    /**
     * Prepare a booking: validate everything, hold the slot and calculate the payment options.
     *
     * @param array $request The booking request (post_data).
     * @param array $context ['idempotency_key', 'manage_token', 'ip', 'email'].
     *
     * @return array The booking state for the browser.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function prepare(array $request, array $context = []): array
    {
        $idempotency_key = substr(trim((string) ($context['idempotency_key'] ?? '')), 0, 80);

        if ($idempotency_key === '') {
            throw new InvalidArgumentException('Falta la llave de la reserva. Recarga la página.');
        }

        $plan = $this->plan($request, $context);

        $payload_hash = hash('sha256', json_encode($plan['signature']));

        $existing = $this->model()->find_by_idempotency($idempotency_key);

        if ($existing) {
            if (!hash_equals((string) $existing['payload_hash'], $payload_hash)) {
                throw new RuntimeException('Esta reserva ya se está armando con otros datos. Vuelve a empezar.');
            }

            if ($existing['status'] === Booking_payments_model::HOLD_PAID) {
                return $this->state($existing);
            }

            if (
                $existing['status'] === Booking_payments_model::HOLD_HELD &&
                $existing['expires_at'] > date('Y-m-d H:i:s')
            ) {
                return $this->state($existing);
            }

            if ($existing['status'] === Booking_payments_model::HOLD_ATTENTION) {
                // Hay un pago en revisión: no se abre otra ventana de cobro para la misma reserva.
                return $this->state($existing);
            }

            throw new RuntimeException('Esta reserva venció. Vuelve a elegir la hora para crear otra reserva.');
        }

        $this->check_quota($context);

        $date = substr($plan['start_datetime'], 0, 10);

        $provider_ids = $this->candidate_providers($plan);

        // El bloqueo real del horario: fila por profesional y fecha, tomada con FOR UPDATE.
        $hold = $this->CI->slot_lock->locked(
            $provider_ids,
            $date,
            function (array $locked) use ($plan, $idempotency_key, $payload_hash, $context) {
                $existing = $this->model()->find_by_idempotency($idempotency_key);
                if ($existing) {
                    if (!hash_equals((string) $existing['payload_hash'], $payload_hash)) {
                        throw new RuntimeException('La llave de reserva ya se usó con otros datos.');
                    }
                    return $existing;
                }

                $provider_id = $this->resolve_provider($plan, $locked);

                if (!$provider_id) {
                    throw new RuntimeException('Ese horario ya no está disponible. Elige otra hora.');
                }

                $check = $this->CI->slot_lock->check_slot(
                    $provider_id,
                    $plan['start_datetime'],
                    $plan['end_datetime'],
                    $plan['service'],
                    // La cita que se está moviendo no bloquea su propio horario.
                    $plan['appointment_id'],
                );

                if (!$check['free']) {
                    if ($check['reason'] !== 'pending_payment') {
                        log_message('error', 'El horario no está disponible (' . $check['reason'] . ').');
                    }

                    throw new RuntimeException(
                        $check['reason'] === 'pending_payment'
                            ? 'Ese horario está apartado por otra clienta que está pagando. Elige otra hora.'
                            : 'Ese horario ya no está disponible. Elige otra hora.',
                    );
                }

                $now = date('Y-m-d H:i:s');

                $hold = [
                    'hash' => bin2hex(random_bytes(16)),
                    'idempotency_key' => $idempotency_key,
                    'payload_hash' => $payload_hash,
                    'status' => Booking_payments_model::HOLD_HELD,
                    'mode' => null,
                    'id_users_provider' => $provider_id,
                    'id_services' => (int) $plan['service']['id'],
                    'hair_length' => $plan['hair_length'],
                    'start_datetime' => $plan['start_datetime'],
                    'end_datetime' => $plan['end_datetime'],
                    'duration' => (int) $plan['service']['duration'],
                    'service_name' => $plan['service']['name'],
                    'price_type' => $plan['price_type'],
                    'total_amount' => $plan['total'],
                    'price_exact' => $plan['price_exact'] ? 1 : 0,
                    'deposit_amount' => $plan['options']['deposit'],
                    'paid_amount' => 0,
                    'amount_due' => null,
                    'customer_snapshot' => json_encode($plan['customer'], JSON_UNESCAPED_UNICODE),
                    'notes' => $plan['notes'],
                    'attribution_code' => $plan['attribution_code'],
                    'id_users_customer' => $plan['customer_id'],
                    'id_appointments' => $plan['appointment_id'],
                    'ip_hash' => $this->fingerprint((string) ($context['ip'] ?? '')),
                    'email_hash' => $this->fingerprint((string) ($context['email'] ?? '')),
                    'expires_at' => $this->expiry(),
                    'create_datetime' => $now,
                    'update_datetime' => $now,
                ];

                $id = $this->model()->insert_hold($hold);

                return $this->model()->find_hold($id);
            },
        );

        return $this->state($hold);
    }

    /**
     * Validate the request and work out the price, the duration and the payment options.
     *
     * @return array The plan of the booking.
     */
    protected function plan(array $request, array $context): array
    {
        $appointment = $request['appointment'] ?? [];
        $customer = $request['customer'] ?? [];

        if (!is_array($appointment) || !is_array($customer)) {
            throw new InvalidArgumentException('La reserva no está completa.');
        }

        $service_id = (int) ($appointment['id_services'] ?? 0);

        if ($service_id <= 0) {
            throw new InvalidArgumentException('Elige un servicio.');
        }

        $service = $this->CI->services_model->find($service_id);

        // El servicio tiene que ser público: ni privado ni archivado.
        if (!empty($service['is_private'])) {
            throw new InvalidArgumentException('Ese servicio no se puede reservar en línea.');
        }

        if (!empty($service['is_archived'])) {
            throw new InvalidArgumentException('Ese servicio ya no está disponible.');
        }

        $hair_length = trim((string) ($appointment['hair_length'] ?? '')) ?: null;

        $variants = $this->CI->service_variants_model->of_service($service_id);

        // Blondor: con variantes, el largo decide el precio y la duración (el navegador no los manda).
        $applied = $this->CI->service_variants_model->apply($service, $hair_length);

        $price_exact = true;
        $price = (float) ($applied['price'] ?? 0);
        $duration = (int) ($applied['duration'] ?? 0);

        if ($variants) {
            $hair_length = $applied['hair_length'] ?? null;
        } else {
            $hair_length = null;

            // "Desde $X": el mínimo no es el total, así que el precio final no es exacto.
            if (($service['price_type'] ?? 'fixed') !== 'fixed') {
                $price_exact = false;
            }
        }

        // La duración del largo manda sobre la del servicio.
        $service['duration'] = $duration;

        if ($price <= 0) {
            throw new InvalidArgumentException('Este servicio no tiene precio. Escríbenos y lo arreglamos.');
        }

        if ($duration <= 0) {
            throw new InvalidArgumentException('Este servicio no tiene duración. Escríbenos y lo arreglamos.');
        }

        $start_datetime = trim((string) ($appointment['start_datetime'] ?? ''));

        if (!validate_datetime($start_datetime)) {
            throw new InvalidArgumentException('La fecha y hora no son válidas.');
        }

        // El final lo calcula el servidor con la duración del servicio.
        $end_datetime = date(
            'Y-m-d H:i:s',
            strtotime($start_datetime . ' +' . $duration . ' minutes'),
        );

        $options = $this->policy()->options($price_exact ? $price : null, $price);

        $appointment_id = null;
        $existing_appointment = null;

        if (!empty($request['manage_mode'])) {
            $existing_appointment = $this->authorized_appointment($request, $context);

            $appointment_id = (int) $existing_appointment['id'];

            $this->check_paid_change($existing_appointment, $service_id, $hair_length, $price, $price_exact);

            if ($this->is_paid($existing_appointment)) {
                // Cita pagada: se conserva lo que se pagó y no se vuelve a cobrar.
                $total = $existing_appointment['total_amount'] !== null
                    ? (float) $existing_appointment['total_amount']
                    : null;

                $options = $this->policy()->options($total, $price);

                $options['options'] = [];

                $price_exact = $total !== null;
            }
        }

        $provider_request = $appointment['id_users_provider'] ?? null;

        $this->check_customer_is_free($customer, $start_datetime, $end_datetime, $appointment_id);

        return [
            'service' => $service,
            'hair_length' => $hair_length,
            'price_exact' => $price_exact,
            'price_type' => $service['price_type'] ?? 'fixed',
            'total' => $price_exact ? ($options['total'] ?? $price) : null,
            'options' => $options,
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'provider_request' => $provider_request,
            'appointment_id' => $appointment_id,
            'customer_id' => $existing_appointment['id_users_customer'] ?? null,
            'existing_appointment' => $existing_appointment,
            'notes' => mb_substr(trim((string) ($customer['notes'] ?? '')), 0, 500),
            // Origen de la reserva: solo en reservas nuevas (una reagenda no la trajo un anuncio). Queda fuera de la
            // firma porque no cambia qué se reserva ni cuánto se cobra.
            'attribution_code' => $appointment_id === null
                ? $this->attribution_code($request['attribution_code'] ?? null)
                : null,
            'customer' => $this->customer_snapshot($customer, (string) ($context['ip'] ?? '')),
            'signature' => [
                'service' => $service_id,
                'length' => $hair_length,
                'start' => $start_datetime,
                'provider' => is_scalar($provider_request) ? (string) $provider_request : '',
                'appointment' => $appointment_id,
                'email' => mb_strtolower(trim((string) ($customer['email'] ?? ''))),
            ],
        ];
    }

    /**
     * The customer data needed to finish the booking without the browser.
     */
    protected function customer_snapshot(array $customer, string $ip = ''): array
    {
        $clean = fn($key, $max) => mb_substr(trim((string) ($customer[$key] ?? '')), 0, $max);
        $phone = preg_replace('/\D/', '', $clean('phone_number', 60));
        if (str_starts_with($phone, '0056') && strlen($phone) === 13) {
            $phone = substr($phone, 4);
        } elseif (str_starts_with($phone, '56') && strlen($phone) === 11) {
            $phone = substr($phone, 2);
        }
        if ($phone !== '' && !preg_match('/^[0-9]{9}$/D', $phone)) {
            throw new InvalidArgumentException('Ingresa los 9 dígitos del teléfono, sin +56.');
        }


        return [
            'first_name' => $clean('first_name', 100),
            'last_name' => $clean('last_name', 120),
            'email' => mb_strtolower($clean('email', 120)),
            'phone_number' => $phone !== '' ? '+56' . $phone : '',
            'address' => $clean('address', 120),
            'city' => $clean('city', 120),
            'zip_code' => $clean('zip_code', 20),
            'timezone' => $clean('timezone', 64),
            'custom_field_1' => $clean('custom_field_1', 255),
            'custom_field_2' => $clean('custom_field_2', 255),
            'custom_field_3' => $clean('custom_field_3', 255),
            'custom_field_4' => $clean('custom_field_4', 255),
            'custom_field_5' => $clean('custom_field_5', 255),
            // El IP se guarda como en los consentimientos: no se muestra en ninguna vista.
            'ip' => mb_substr(trim($ip), 0, 45),
        ];
    }

    /**
     * The appointment the customer is allowed to change (the capability of the reschedule link).
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    protected function authorized_appointment(array $request, array $context): array
    {
        $manage_token = trim((string) ($context['manage_token'] ?? ''));

        if ($manage_token === '') {
            throw new RuntimeException('Esta cita no se puede cambiar desde aquí. Abre el enlace que te enviamos.');
        }

        $appointment_id = (int) ($request['appointment']['id'] ?? 0);

        if ($appointment_id <= 0) {
            throw new RuntimeException('Esta cita no se puede cambiar desde aquí. Abre el enlace que te enviamos.');
        }

        $cache = &app_cache();

        $capability = $cache->get('manage-token-' . $manage_token);

        if (!$capability || (int) $capability !== $appointment_id) {
            throw new RuntimeException('Esta cita no se puede cambiar desde aquí. Abre el enlace que te enviamos.');
        }

        $appointment = $this->CI->appointments_model->find($appointment_id);

        return $appointment;
    }

    /**
     * A paid appointment cannot change its service or its price online: it is managed by hand.
     *
     * The same service with the same length is always allowed: the price that was paid stays (even if the salon
     * changed the price of the catalog afterwards). What is not allowed is changing the service or the length,
     * because that changes the price that was paid.
     *
     * @throws RuntimeException
     */
    protected function check_paid_change(
        array $appointment,
        int $service_id,
        ?string $hair_length,
        float $price,
        bool $price_exact,
    ): void {
        if (!$this->is_paid($appointment)) {
            return;
        }

        $same_service = (int) ($appointment['id_services'] ?? 0) === $service_id;
        $same_length = ($appointment['hair_length'] ?? null) === $hair_length;

        if ($same_service && $same_length) {
            return;
        }

        throw new RuntimeException(
            'Esta cita ya tiene un pago, así que su servicio y su precio no se pueden cambiar en línea.' .
            ' Escríbenos por WhatsApp y lo vemos con el equipo.',
        );
    }

    /**
     * Whether the appointment already has money on it.
     */
    public function is_paid(array $appointment): bool
    {
        return in_array(
            $appointment['payment_status'] ?? '',
            ['deposit_paid', 'paid', 'attention'],
            true,
        ) || (float) ($appointment['paid_amount'] ?? 0) > 0;
    }

    /**
     * The customer cannot have two appointments at the same time.
     *
     * @throws RuntimeException
     */
    protected function check_customer_is_free(
        array $customer,
        string $start_datetime,
        string $end_datetime,
        ?int $exclude_appointment_id,
    ): void {
        $email = mb_strtolower(trim((string) ($customer['email'] ?? '')));

        if ($email === '' || !$this->CI->customers_model->exists(['email' => $email])) {
            return;
        }

        $customer_id = (int) $this->CI->customers_model->find_record_id(['email' => $email]);

        if ($customer_id <= 0) {
            return;
        }

        $existing = $this->CI->db
            ->select('id')
            ->from('appointments')
            ->where('is_unavailability', false)
            ->where('id_users_customer', $customer_id)
            // Una cita cancelada no estorba: la clienta puede volver a reservar esa hora.
            ->where('IFNULL(status, "") != "Cancelado"', null, false)
            ->where('start_datetime <=', $start_datetime)
            ->where('end_datetime >=', $end_datetime)
            ->group_start()
            ->where('id !=', $exclude_appointment_id ?: 0)
            ->group_end()
            ->get()
            ->result_array();

        if ($existing) {
            throw new RuntimeException('Ya tienes una cita a esa hora. Cambia la hora o escríbenos.');
        }
    }

    /**
     * The providers that could do the service (ordered, to lock them in a deterministic order).
     */
    protected function candidate_providers(array $plan): array
    {
        $requested = $plan['provider_request'];

        if ($requested === ANY_PROVIDER || $requested === null || $requested === '') {
            $providers = $this->CI->providers_model->get_available_providers(true);

            $ids = [];

            foreach ($providers as $provider) {
                if (in_array((int) $plan['service']['id'], array_map('intval', $provider['services'] ?? []), true)) {
                    $ids[] = (int) $provider['id'];
                }
            }

            sort($ids);

            return $ids;
        }

        $provider_id = (int) $requested;

        $provider = $this->CI->providers_model->find($provider_id);

        $services = array_map('intval', $provider['services'] ?? []);

        if (!in_array((int) $plan['service']['id'], $services, true)) {
            throw new InvalidArgumentException('Esa profesional no hace ese servicio.');
        }

        return [$provider_id];
    }

    /**
     * The provider that takes the slot (inside the lock).
     */
    protected function resolve_provider(array $plan, array $locked): ?int
    {
        $requested = $plan['provider_request'];

        if ($requested !== ANY_PROVIDER && $requested !== null && $requested !== '') {
            return (int) $requested;
        }

        foreach ($locked as $provider_id) {
            $check = $this->CI->slot_lock->check_slot(
                (int) $provider_id,
                $plan['start_datetime'],
                $plan['end_datetime'],
                $plan['service'],
                $plan['appointment_id'],
            );

            if ($check['free']) {
                return (int) $provider_id;
            }
        }

        return null;
    }

    /**
     * Too many tries from the same device or the same email: ask to wait.
     */
    protected function check_quota(array $context): void
    {
        $ip_hash = $this->fingerprint((string) ($context['ip'] ?? ''));
        $email_hash = $this->fingerprint((string) ($context['email'] ?? ''));

        if ($ip_hash === '' || $email_hash === '') {
            return;
        }

        $since = date('Y-m-d H:i:s', time() - 3600);

        $by_ip = $this->CI->db
            ->where('ip_hash', $ip_hash)
            ->where('create_datetime >', $since)
            ->count_all_results('booking_holds');

        if ($by_ip >= self::MAX_HOLDS_PER_IP) {
            throw new RuntimeException('Hicimos demasiados intentos desde este equipo. Espera unos minutos.');
        }

        $by_email = $this->CI->db
            ->where('email_hash', $email_hash)
            ->where('create_datetime >', $since)
            ->count_all_results('booking_holds');

        if ($by_email >= self::MAX_HOLDS_PER_EMAIL) {
            throw new RuntimeException('Hicimos demasiados intentos con este correo. Espera unos minutos.');
        }
    }

    /**
     * The origin code the browser sent (?ref= of the link), or null when it is missing or does not look like one.
     * Anyone can type it in the URL, so it is only used to measure: it never changes a price or an amount.
     */
    public function attribution_code($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z0-9][A-Z0-9:_-]{1,47}$/', $code) ? $code : null;
    }

    /**
     * A fingerprint that is not the data itself (no IP, no email in the database).
     */
    protected function fingerprint(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        return hash('sha256', 'blondor-hold|' . $value);
    }

    protected function expiry(): string
    {
        return date('Y-m-d H:i:s', time() + self::HOLD_TTL_MINUTES * 60);
    }

    /**
     * What the browser gets to know about the booking.
     */
    public function state(array $hold): array
    {
        $options = $this->policy()->options(
            $hold['price_exact'] ? (float) $hold['total_amount'] : null,
            (float) $hold['deposit_amount'],
        );

        // Con el precio ya guardado, la política se recalcula solo con el total.
        if ($hold['price_exact']) {
            $options = $this->policy()->options((float) $hold['total_amount'], (float) $hold['total_amount']);
        }

        $paid = $this->is_paid_appointment((int) ($hold['id_appointments'] ?? 0));

        $state = [
            'hold_token' => $hold['hash'],
            'expires_at' => $hold['expires_at'],
            'status' => $hold['status'],
            'payment_required' => !$paid,
            'service' => [
                'name' => $hold['service_name'],
                'hair_length' => $hold['hair_length'],
                'duration' => (int) $hold['duration'],
            ],
            'when' => [
                'start' => $hold['start_datetime'],
                'end' => $hold['end_datetime'],
            ],
            'total' => $hold['total_amount'] !== null ? (float) $hold['total_amount'] : null,
            'total_is_exact' => (bool) $hold['price_exact'],
            'price_type' => $hold['price_type'],
            'deposit' => (float) $hold['deposit_amount'],
            // Una cita ya pagada no tiene opciones: se reagenda sin cobrar otra vez.
            'options' => $paid ? [] : $options['options'],
        ];

        if ($hold['id_appointments']) {
            $state['appointment_id'] = (int) $hold['id_appointments'];

            $appointment = $this->CI->appointments_model->find((int) $hold['id_appointments']);

            $state['appointment_hash'] = $appointment['hash'];
        }

        return $state;
    }

    protected function is_paid_appointment(int $appointment_id): bool
    {
        if ($appointment_id <= 0) {
            return false;
        }

        $appointment = $this->CI->appointments_model->find($appointment_id);

        return $this->is_paid($appointment);
    }

    /* ---------------------------------------------------------------------
     * 2. El pago
     * ------------------------------------------------------------------ */

    /**
     * Start the payment of a hold.
     *
     * @param string $hold_token The public token of the hold.
     * @param string $mode "deposit" or "full".
     * @param string $idempotency_key Key of the payment attempt.
     *
     * @return array {url, token, attempt_id, amount}
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function start_payment(string $hold_token, string $mode, string $idempotency_key): array
    {
        $hold = $this->model()->find_by_hash($hold_token);
        if (!$hold) {
            throw new RuntimeException('No encontramos esta reserva.');
        }
        $reserved = $this->CI->slot_lock->locked(
            [(int) $hold['id_users_provider']], substr($hold['start_datetime'], 0, 10),
            function () use ($hold_token, $mode, $idempotency_key) {
                return $this->reserve_payment_attempt($hold_token, $mode, $idempotency_key);
            },
        );
        if (!isset($reserved['new_attempt_id'])) {
            return $reserved;
        }
        $attempt_id = $reserved['new_attempt_id'];
        $buy_order = $reserved['buy_order'];
        $session_id = $reserved['session_id'];
        $amount = $reserved['amount'];

        // La llamada a Webpay va FUERA de toda transacción de base de datos.
        try {
            $transaction = $this->CI->webpay->create(
                $buy_order,
                $session_id,
                (int) round($amount),
                site_url('booking/webpay_return'),
            );
        } catch (Throwable $e) {
            $this->model()->update_attempt($attempt_id, [
                'status' => Booking_payments_model::ATTEMPT_FAILED,
            ]);

            log_message('error', 'Webpay create failed: ' . $e->getMessage());

            throw new RuntimeException('No pudimos iniciar el pago con Webpay. Inténtalo de nuevo en un momento.');
        }

        $this->model()->update_attempt($attempt_id, [
            'token' => $transaction['token'],
            'payment_url' => (string) ($transaction['url'] ?? ''),
            'status' => Booking_payments_model::ATTEMPT_CREATED,
        ]);

        $attempt = $this->model()->find_attempt($attempt_id);

        return $this->attempt_response($attempt);
    }

    /** Reserve one attempt while the slot lock serializes checkout and internal writes. */
    protected function reserve_payment_attempt(string $hold_token, string $mode, string $idempotency_key): array
    {
        $hold = $this->CI->db->query(
            'SELECT * FROM `' . $this->CI->db->dbprefix('booking_holds') . '` WHERE hash = ? FOR UPDATE',
            [$hold_token],
        )->row_array();

        if (!$hold) {
            throw new RuntimeException('No encontramos esta reserva.');
        }

        if ($hold['status'] === Booking_payments_model::HOLD_PAID) {
            throw new RuntimeException('Esta reserva ya está pagada.');
        }

        if ($hold['status'] !== Booking_payments_model::HOLD_HELD) {
            throw new RuntimeException('Esta reserva ya no está disponible. Vuelve a elegir la hora.');
        }

        if ($hold['expires_at'] <= date('Y-m-d H:i:s')) {
            throw new RuntimeException('Se nos pasó el tiempo para pagar. Vuelve a elegir la hora.');
        }

        $options = $this->policy()->options(
            $hold['price_exact'] ? (float) $hold['total_amount'] : null,
            (float) $hold['deposit_amount'],
        );

        $amount = $this->policy()->amount($options, $mode);

        if ($amount === null) {
            throw new InvalidArgumentException('Esa forma de pago no está disponible para este servicio.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('El monto a pagar no es válido.');
        }

        $idempotency_key = substr(trim($idempotency_key), 0, 80);

        if ($idempotency_key === '') {
            throw new InvalidArgumentException('Falta la llave del pago. Recarga la página.');
        }

        // Doble clic: el mismo intento devuelve la misma transacción de Webpay.
        $existing = $this->model()->find_attempt_by_idempotency($idempotency_key);

        if ($existing) {
            if ((int) $existing['id_booking_holds'] !== (int) $hold['id']) {
                throw new RuntimeException('Esta reserva ya tiene un pago en curso. Revisa el resultado del pago.');
            }

            if ($existing['token'] && $existing['status'] === Booking_payments_model::ATTEMPT_CREATED) {
                return $this->attempt_response($existing);
            }

            if ($existing['status'] === Booking_payments_model::ATTEMPT_AUTHORIZED) {
                throw new RuntimeException('Este pago ya fue confirmado.');
            }
        }

        $attempts = $this->model()->attempts_of_hold((int) $hold['id']);

        foreach ($attempts as $pending) {
            if (in_array($pending['status'], ['created', 'unknown', 'attention', 'authorized'], true)) {
                if ($pending['status'] === 'created' && $pending['token']) {
                    return $this->attempt_response($pending);
                }
                throw new RuntimeException('Esta reserva ya tiene un pago en curso. Revisa su resultado.');
            }
        }

        if (count($attempts) >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Demasiados intentos de pago para esta reserva. Escríbenos por WhatsApp.');
        }

        $buy_order = 'BK' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $session_id = bin2hex(random_bytes(8));

        // La llave de idempotencia es única: si otro clic ganó la carrera, se usa ese intento y no se crea otro.
        $inserted = $this->model()->insert_attempt_once([
            'id_booking_holds' => (int) $hold['id'],
            'attempt_no' => count($attempts) + 1,
            'buy_order' => $buy_order,
            'token' => null,
            'session_id' => $session_id,
            'amount' => $amount,
            'provider' => 'webpay',
            'status' => Booking_payments_model::ATTEMPT_CREATED,
            'idempotency_key' => $idempotency_key,
        ]);

        if (!$inserted['created']) {
            // El doble clic: se devuelve la misma transacción (la que el otro proceso está armando).
            $existing = $inserted['id'] > 0
                ? $this->model()->find_attempt($inserted['id'])
                : $this->model()->find_attempt_by_idempotency($idempotency_key);

            if ($existing) {
                if ((int) $existing['id_booking_holds'] !== (int) $hold['id']) {
                    throw new RuntimeException('Esta reserva ya tiene un pago en curso. Revisa el resultado del pago.');
                }

                if ($existing['token']) {
                    return $this->attempt_response($existing);
                }
            }

            throw new RuntimeException('Ya estamos armando el pago de esta reserva. Vuelve a intentarlo en un momento.');
        }

        $attempt_id = $inserted['id'];

        $this->model()->update_hold((int) $hold['id'], [
            'mode' => $mode,
            'deposit_amount' => $mode === Deposit_policy::MODE_DEPOSIT
                ? $amount
                : (float) $hold['deposit_amount'],
        ]);

        return ['new_attempt_id' => $attempt_id, 'buy_order' => $buy_order,
            'session_id' => $session_id, 'amount' => $amount];
    }

    /**
     * The data the browser needs to go to Webpay.
     */
    protected function attempt_response(array $attempt): array
    {
        return [
            // The URL comes from Transbank (it knows the environment of the transaction).
            'url' => (string) ($attempt['payment_url'] ?? ''),
            'token' => $attempt['token'],
            'attempt_id' => (int) $attempt['id'],
            'amount' => (float) $attempt['amount'],
        ];
    }

    /**
     * Wait for the token of an attempt that another process is creating.
     *
     * The token only exists after Webpay answers, so the second click waits a few seconds instead of creating a
     * second transaction (which would charge the client twice).
     *
     * @param int $attempt_id Payment attempt ID.
     * @param float $seconds How long to wait.
     *
     * @return array|null The attempt (NULL when there is no token yet).
     */
    protected function wait_for_token(int $attempt_id, float $seconds = 3.0): ?array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $attempt = $this->model()->find_attempt($attempt_id);

            if ($attempt && $attempt['token']) {
                return $attempt;
            }

            usleep(100000); // 0.1 seconds
        } while (microtime(true) < $deadline);

        return $this->model()->find_attempt($attempt_id);
    }

    /* ---------------------------------------------------------------------
     * 3. La vuelta de Webpay
     * ------------------------------------------------------------------ */

    /**
     * Webpay came back: confirm the payment and, only then, create the appointment.
     *
     * @param array $request The POST of Webpay.
     *
     * @return array The state of the payment.
     */
    public function handle_webpay_return(array $request): array
    {
        $token = trim((string) ($request['token_ws'] ?? ''));
        $abort_token = trim((string) ($request['TBK_TOKEN'] ?? ''));
        $buy_order = trim((string) ($request['TBK_ORDEN_COMPRA'] ?? ''));

        // Callback fields are untrusted. Only a stored token identifies a transaction.
        $attempt = $this->model()->find_attempt_by_token($token !== '' ? $token : $abort_token);

        if (!$attempt && $token === '' && $abort_token === '' && $buy_order !== '') {
            $attempt = $this->model()->find_attempt_by_buy_order($buy_order);
        }

        if (!$attempt) {
            return ['status' => 'unknown', 'hold_token' => ''];
        }

        $hold = $this->model()->find_hold((int) $attempt['id_booking_holds']);

        // Ya estaba confirmado: el doble clic no cobra dos veces.
        if ($attempt['status'] === Booking_payments_model::ATTEMPT_AUTHORIZED) {
            return ['status' => 'paid', 'hold_token' => $hold['hash'] ?? ''];
        }

        if (
            in_array(
                $attempt['status'],
                [Booking_payments_model::ATTEMPT_REJECTED, Booking_payments_model::ATTEMPT_ABORTED],
                true,
            )
        ) {
            return ['status' => 'rejected', 'hold_token' => $hold['hash'] ?? ''];
        }

        if ($attempt['status'] === Booking_payments_model::ATTEMPT_ATTENTION) {
            return ['status' => 'attention', 'hold_token' => $hold['hash'] ?? ''];
        }

        // Persist uncertainty BEFORE commit: after a crash only status() may recover it.
        if (!$this->claim_attempt((int) $attempt['id'], $token !== '' && $attempt['status'] === Booking_payments_model::ATTEMPT_CREATED)) {
            return ['status' => 'processing', 'hold_token' => $hold['hash'] ?? ''];
        }

        if ($token === '' || $attempt['status'] === Booking_payments_model::ATTEMPT_UNKNOWN) {
            $state = $this->CI->webpay->state((string) $attempt['token']);

            if (in_array($state['state'], ['authorized', 'rejected', 'aborted'], true)) {
                return $this->apply_payment($attempt, $state['response'] ?? []);
            }

            $this->model()->update_attempt((int) $attempt['id'], [
                'status' => $attempt['status'],
                'claim_token' => null,
                'claim_expires_at' => null,
            ]);

            return ['status' => 'unknown', 'hold_token' => $hold['hash'] ?? ''];
        }

        // La llamada a Transbank va FUERA de toda transacción.
        try {
            $response = $this->CI->webpay->commit((string) $attempt['token']);
        } catch (Throwable $e) {
            $this->model()->update_attempt((int) $attempt['id'], [
                'status' => Booking_payments_model::ATTEMPT_UNKNOWN,
            ]);

            $this->model()->update_attempt((int) $attempt['id'], ['claim_token' => null, 'claim_expires_at' => null]);

            log_message('error', 'Webpay commit failed: ' . $e->getMessage());

            return ['status' => 'unknown', 'hold_token' => $hold['hash'] ?? ''];
        }

        return $this->apply_payment($attempt, $response);
    }

    /**
     * Take the control of a payment attempt (atomic lease).
     */
    protected function claim_attempt(int $attempt_id, bool $first_commit = false): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->CI->db->query(
            'UPDATE `' . $this->CI->db->dbprefix('payment_attempts') . '`' .
            ' SET ' . ($first_commit ? "`status` = 'unknown', " : '') .
            '`claim_token` = ?, `claim_expires_at` = ?, `update_datetime` = ?' .
            ' WHERE `id` = ?' .
            ' AND (`claim_token` IS NULL OR `claim_expires_at` IS NULL OR `claim_expires_at` < ?)' .
            ($first_commit ? ' AND `status` = "created"' : ' AND `status` IN ("created", "unknown")'),
            [bin2hex(random_bytes(8)), date('Y-m-d H:i:s', time() + self::CLAIM_TTL_SECONDS), $now, $attempt_id, $now],
        );

        return $this->CI->db->affected_rows() === 1;
    }

    /**
     * Apply the answer of Transbank: authorize, create the appointment and queue the notices.
     */
    public function apply_payment(array $attempt, array $response): array
    {
        $hold = $this->model()->find_hold((int) $attempt['id_booking_holds']);

        if (!$hold) {
            return ['status' => 'unknown', 'hold_token' => ''];
        }

        $amount = (int) round((float) $attempt['amount']);

        $authorized = Webpay::is_authorized($response)
            && is_numeric($response['amount'] ?? null)
            && (float) $response['amount'] === (float) $amount
            && (string) ($response['buy_order'] ?? '') === (string) $attempt['buy_order']
            && (string) ($response['session_id'] ?? '') === (string) $attempt['session_id'];

        if (!$authorized) {
            $transbank_status = (string) ($response['status'] ?? '');

            // Autorizado pero con otro monto u otra orden: no se aplica solo, se revisa a mano.
            $needs_review = !in_array($transbank_status, ['REJECTED', 'ABORTED', 'FAILED'], true);

            $this->model()->update_attempt((int) $attempt['id'], [
                'status' => $needs_review
                    ? Booking_payments_model::ATTEMPT_ATTENTION
                    : Booking_payments_model::ATTEMPT_REJECTED,
                'response_data' => $this->safe_response($response),
                'committed_at' => date('Y-m-d H:i:s'),
                'claim_token' => null,
                'claim_expires_at' => null,
            ]);

            if ($needs_review) {
                // Transbank dice que se cobró, pero no cuadra con lo que pedimos: el dinero queda registrado
                // (no se descarta) y el caso lo ve el equipo.
                $this->model()->update_hold((int) $hold['id'], [
                    'status' => Booking_payments_model::HOLD_ATTENTION,
                    'paid_amount' => $transbank_status === 'AUTHORIZED' ? (float) ($response['amount'] ?? 0) : 0,
                ]);

                return ['status' => 'attention', 'hold_token' => $hold['hash']];
            }

            $this->release_hold((int) $hold['id'], Booking_payments_model::HOLD_RELEASED);

            return ['status' => $transbank_status === 'ABORTED' ? 'aborted' : 'rejected', 'hold_token' => $hold['hash']];
        }

        $applied = $this->confirm_booking($hold, $attempt, $response);

        if (!$applied) {
            // Se cobró, pero el horario ya no está: el caso queda para que el equipo lo revise.
            $this->model()->update_attempt((int) $attempt['id'], [
                'status' => Booking_payments_model::ATTEMPT_ATTENTION,
                'response_data' => $this->safe_response($response),
                'committed_at' => date('Y-m-d H:i:s'),
                'claim_token' => null,
                'claim_expires_at' => null,
            ]);

            $this->model()->update_hold((int) $hold['id'], [
                'status' => Booking_payments_model::HOLD_ATTENTION,
                'paid_amount' => $amount,
            ]);

            return ['status' => 'attention', 'hold_token' => $hold['hash']];
        }

        $this->model()->update_attempt((int) $attempt['id'], [
            'status' => Booking_payments_model::ATTEMPT_AUTHORIZED,
            'response_data' => $this->safe_response($response),
            'committed_at' => date('Y-m-d H:i:s'),
            'claim_token' => null,
            'claim_expires_at' => null,
        ]);

        // Los avisos se entregan ya, pero quedan en la bandeja por si fallan.
        $this->dispatch_outbox();

        return ['status' => 'paid', 'hold_token' => $hold['hash']];
    }

    /**
     * Create (or move) the appointment of a paid hold, with its ledger and its notices.
     *
     * @return bool Whether the appointment was created.
     */
    protected function confirm_booking(array $hold, array $attempt, array $response): bool
    {
        $amount = (int) round((float) $attempt['amount']);

        $date = substr($hold['start_datetime'], 0, 10);

        $pairs = [[(int) $hold['id_users_provider'], $date]];

        if ($hold['id_appointments']) {
            // Reagenda: se bloquean el origen y el destino, en orden.
            $appointment = $this->CI->appointments_model->find((int) $hold['id_appointments']);

            $origin_date = substr((string) $appointment['start_datetime'], 0, 10);

            if ($origin_date !== $date) {
                $pairs[] = [(int) $appointment['id_users_provider'], $origin_date];
            }
        }

        $result = $this->CI->slot_lock->locked_pairs(
            $pairs,
            function () use ($hold, $attempt, $amount) {
                $hold = $this->CI->db->query(
                    'SELECT * FROM `' . $this->CI->db->dbprefix('booking_holds') . '` WHERE id = ? FOR UPDATE',
                    [(int) $hold['id']],
                )->row_array();

                if ($hold['status'] === Booking_payments_model::HOLD_PAID) {
                    return true;
                }

                $check = $this->CI->slot_lock->check_slot(
                    (int) $hold['id_users_provider'],
                    $hold['start_datetime'],
                    $hold['end_datetime'],
                    [
                        'id' => (int) $hold['id_services'],
                        'duration' => (int) $hold['duration'],
                    ],
                    $hold['id_appointments'] ? (int) $hold['id_appointments'] : null,
                    (int) $hold['id'],
                );

                if (!$check['free']) {
                    return false;
                }

                $appointment_id = $this->save_appointment($hold, $amount);

                $this->model()->update_hold((int) $hold['id'], [
                    'status' => Booking_payments_model::HOLD_PAID,
                    'paid_amount' => $amount,
                    'amount_due' => $hold['total_amount'] !== null
                        ? round((float) $hold['total_amount'] - $amount, 2)
                        : null,
                    'id_appointments' => $appointment_id,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                ]);

                // El aviso se encola en la misma transacción que confirma la cita.
                $this->model()->enqueue(
                    'appointment_paid',
                    'appointment_paid:' . $appointment_id,
                    ['appointment_id' => $appointment_id],
                    [
                        'id_appointments' => $appointment_id,
                        'id_booking_holds' => (int) $hold['id'],
                    ],
                );

                return true;
            },
        );

        return (bool) $result;
    }

    /**
     * Save the appointment of a paid hold (new booking or reschedule).
     */
    protected function save_appointment(array $hold, int $amount): int
    {
        $customer = json_decode((string) $hold['customer_snapshot'], true) ?: [];

        $customer_id = $this->save_customer($customer);

        $this->save_consents($customer);

        $service = $this->CI->services_model->find((int) $hold['id_services']);

        $total = $hold['total_amount'] !== null ? (float) $hold['total_amount'] : null;

        $due = $total !== null ? round($total - $amount, 2) : null;

        $payment_status = 'paid';

        if ($due === null || $due > 0) {
            $payment_status = 'deposit_paid';
        }

        $appointment = [
            'start_datetime' => $hold['start_datetime'],
            'end_datetime' => $hold['end_datetime'],
            'notes' => $hold['notes'],
            'is_unavailability' => false,
            'id_users_provider' => (int) $hold['id_users_provider'],
            'id_users_customer' => $customer_id,
            'id_services' => (int) $hold['id_services'],
            'hair_length' => $hold['hair_length'],
            'price' => $total ?? (float) $hold['deposit_amount'],
            'total_amount' => $total,
            'paid_amount' => $amount,
            'amount_due' => $due,
            'payment_status' => $payment_status,
            'payment_mode' => $hold['mode'] ?: Deposit_policy::MODE_DEPOSIT,
            'id_booking_holds' => (int) $hold['id'],
        ];

        if ($hold['id_appointments']) {
            $appointment['id'] = (int) $hold['id_appointments'];
        } else {
            $appointment['status'] = $this->default_status();
            $appointment['color'] = $service['color'] ?? null;
            // Solo la cita nueva recibe el origen: una cita que ya existía conserva el suyo.
            $appointment['attribution_code'] = $hold['attribution_code'] ?? null;
        }

        $appointment_id = $this->CI->appointments_model->save($appointment, (int) $hold['id']);

        return (int) $appointment_id;
    }

    /**
     * The status a new appointment gets (the first of the configured ones).
     */
    protected function default_status(): string
    {
        $options = json_decode((string) setting('appointment_status_options', '[]'), true) ?: [];

        return (string) ($options[0] ?? 'Agendado');
    }

    /**
     * Save the consents of the booking (the same ones the old public form saved).
     */
    protected function save_consents(array $customer): void
    {
        $consent = [
            'first_name' => $customer['first_name'] ?? '-',
            'last_name' => $customer['last_name'] ?? '-',
            'email' => $customer['email'] ?? '-',
            'ip' => $customer['ip'] ?? '-',
        ];

        if (setting('display_terms_and_conditions')) {
            $this->CI->consents_model->save($consent + ['type' => 'terms-and-conditions']);
        }

        if (setting('display_privacy_policy')) {
            $this->CI->consents_model->save($consent + ['type' => 'privacy-policy']);
        }
    }

    /**
     * Save the customer of the booking (reusing the record when the email is known).
     */
    protected function save_customer(array $customer): int
    {
        $allowed = [
            'id', 'first_name', 'last_name', 'email', 'phone_number', 'address', 'city', 'state', 'zip_code',
            'timezone', 'language', 'custom_field_1', 'custom_field_2', 'custom_field_3', 'custom_field_4',
            'custom_field_5',
        ];

        if (empty($customer['email'])) {
            throw new RuntimeException('Falta el correo de la clienta.');
        }

        if ($this->CI->customers_model->exists($customer)) {
            $customer['id'] = $this->CI->customers_model->find_record_id($customer);

            $stored = $this->CI->customers_model->find($customer['id']);

            foreach (['first_name', 'last_name', 'phone_number', 'address', 'city', 'zip_code'] as $field) {
                if (trim((string) ($customer[$field] ?? '')) === '' && !empty($stored[$field])) {
                    $customer[$field] = $stored[$field];
                }
            }
        }

        $customer['language'] = session('language') ?? config('language');

        $this->CI->customers_model->only($customer, $allowed);

        return (int) $this->CI->customers_model->save($customer);
    }

    /**
     * Only the fields of the answer of Transbank that are safe to keep.
     */
    protected function safe_response(array $response): string
    {
        $safe = array_intersect_key(
            $response,
            array_flip(['status', 'response_code', 'response_message', 'amount', 'buy_order', 'session_id', 'order_date']),
        );

        return json_encode($safe, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Give up a hold (the client did not pay, or the payment was rejected).
     */
    public function release_hold(int $hold_id, string $status = Booking_payments_model::HOLD_RELEASED): void
    {
        $holds = $this->CI->db->dbprefix('booking_holds');
        $attempts = $this->CI->db->dbprefix('payment_attempts');
        $this->CI->db->query(
            "UPDATE `$holds` h SET h.status = ?, h.claim_token = NULL, h.claim_expires_at = NULL" .
            " WHERE h.id = ? AND h.status IN ('held', 'attention') AND h.paid_amount = 0" .
            " AND NOT EXISTS (SELECT 1 FROM `$attempts` p WHERE p.id_booking_holds = h.id" .
            " AND p.status IN ('created', 'unknown', 'authorized', 'attention'))",
            [$status, $hold_id],
        );
    }

    /* ---------------------------------------------------------------------
     * 4. Reagenda sin cobro (cita ya pagada)
     * ------------------------------------------------------------------ */

    /**
     * Move a paid appointment to the slot of its hold (no new charge).
     */
    public function confirm_free_hold(string $hold_token): array
    {
        $hold = $this->model()->find_by_hash($hold_token);

        if (!$hold) {
            throw new RuntimeException('No encontramos esta reserva.');
        }

        if ($hold['status'] === Booking_payments_model::HOLD_PAID) {
            // Ya estaba confirmada (misma llave de idempotencia): no se cobra ni se mueve otra vez.
            return $this->state($hold);
        }

        if ($hold['status'] !== Booking_payments_model::HOLD_HELD) {
            throw new RuntimeException('Esta reserva ya no está disponible.');
        }

        if (!$hold['id_appointments']) {
            throw new RuntimeException('Esta reserva necesita un pago.');
        }

        $appointment = $this->CI->appointments_model->find((int) $hold['id_appointments']);

        $date = substr($hold['start_datetime'], 0, 10);

        $origin_date = substr((string) $appointment['start_datetime'], 0, 10);

        $pairs = [[(int) $hold['id_users_provider'], $date]];

        if ($origin_date !== $date) {
            $pairs[] = [(int) $appointment['id_users_provider'], $origin_date];
        }

        $moved = $this->CI->slot_lock->locked_pairs(
            $pairs,
            function () use ($hold, $appointment) {
                $check = $this->CI->slot_lock->check_slot(
                    (int) $hold['id_users_provider'],
                    $hold['start_datetime'],
                    $hold['end_datetime'],
                    [
                        'id' => (int) $hold['id_services'],
                        'duration' => (int) $hold['duration'],
                    ],
                    (int) $appointment['id'],
                    (int) $hold['id'],
                );

                if (!$check['free']) {
                    return false;
                }

                // La cita conserva su pago: solo cambia de hora.
                $this->CI->appointments_model->save(array_merge($appointment, [
                    'start_datetime' => $hold['start_datetime'],
                    'end_datetime' => $hold['end_datetime'],
                    'status' => 'Reagendado',
                ]), (int) $hold['id']);

                $this->model()->update_hold((int) $hold['id'], [
                    'status' => Booking_payments_model::HOLD_PAID,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                ]);

                $this->model()->enqueue(
                    'appointment_moved',
                    'appointment_moved:' . $appointment['id'] . ':' . $hold['start_datetime'],
                    ['appointment_id' => (int) $appointment['id']],
                    [
                        'id_appointments' => (int) $appointment['id'],
                        'id_booking_holds' => (int) $hold['id'],
                    ],
                );

                return true;
            },
        );

        if (!$moved) {
            $this->release_hold((int) $hold['id']);

            throw new RuntimeException('Ese horario ya no está disponible. Elige otra hora.');
        }

        $this->dispatch_outbox();

        $hold = $this->model()->find_hold((int) $hold['id']);

        return $this->state($hold);
    }

    /* ---------------------------------------------------------------------
     * 5. Mantenimiento (cron)
     * ------------------------------------------------------------------ */

    /**
     * Reconcile the payments that were left halfway and expire the holds nobody finished.
     *
     * @return array A summary of what happened.
     */
    public function reconcile(): array
    {
        $summary = [
            'reconciled' => 0,
            'rejected' => 0,
            'attention' => 0,
            'expired' => 0,
            'outbox' => 0,
        ];

        // 1. Los intentos que quedaron a medio camino.
        foreach ($this->model()->pending_attempts() as $attempt) {
            if (!$attempt['token'] || !$this->claim_attempt((int) $attempt['id'])) {
                continue;
            }

            $state = $this->CI->webpay->state($attempt['token']);

            if ($state['state'] === 'authorized') {
                $this->apply_payment($attempt, $state['response'] ?? []);

                $summary['reconciled']++;

                continue;
            }

            if (in_array($state['state'], ['rejected', 'aborted'], true)) {
               $this->model()->update_attempt((int) $attempt['id'], [
                    'status' => $state['state'] === 'aborted'
                        ? Booking_payments_model::ATTEMPT_ABORTED
                        : Booking_payments_model::ATTEMPT_REJECTED,
                    'response_data' => $this->safe_response($state['response'] ?? []),
                    'committed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->release_hold((int) $attempt['id_booking_holds']);

                $summary['rejected']++;

                continue;
            }

            if ($state['state'] === 'pending') {
                // La transacción existe pero nadie la ha pagado todavía.

                if (time() - strtotime((string) $attempt['create_datetime']) > self::WEBPAY_SESSION_TTL) {
                    // La sesión de Webpay ya venció: no se cobró nada.
                   $this->model()->update_attempt((int) $attempt['id'], [
                        'status' => Booking_payments_model::ATTEMPT_ABORTED,
                        'committed_at' => date('Y-m-d H:i:s'),
                    ]);

                    $this->release_hold((int) $attempt['id_booking_holds']);

                    $summary['rejected']++;

                    continue;
                }

               $this->model()->update_attempt((int) $attempt['id'], [
                    'status' => $attempt['status'],
                    'claim_token' => null,
                    'claim_expires_at' => null,
                ]);

                $summary['attention']++;

                continue;
            }

            if ($state['state'] === 'not_found') {
                // En Transbank no existe: no se cobró nada.
               $this->model()->update_attempt((int) $attempt['id'], [
                    'status' => Booking_payments_model::ATTEMPT_ABORTED,
                    'committed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->release_hold((int) $attempt['id_booking_holds']);

                $summary['rejected']++;

                continue;
            }

            // No se pudo saber: conservar si todavía corresponde el primer commit.
            $this->model()->update_attempt((int) $attempt['id'], [
                'status' => $attempt['status'],
                'claim_token' => null,
                'claim_expires_at' => null,
            ]);

            $summary['attention']++;
        }

        // 2. Las reservas que nadie terminó.
        foreach ($this->model()->expired_holds() as $hold) {
            if ($hold['status'] !== Booking_payments_model::HOLD_HELD) {
                continue;
            }

            $attempts = $this->model()->attempts_of_hold((int) $hold['id']);

            $pending = array_filter(
                $attempts,
                fn($attempt) => in_array(
                    $attempt['status'],
                    [Booking_payments_model::ATTEMPT_CREATED, Booking_payments_model::ATTEMPT_UNKNOWN],
                    true,
                ),
            );

            if ($pending) {
                // Con un pago sin confirmar no se suelta la reserva: el dinero puede ser real.
                $this->model()->update_hold((int) $hold['id'], [
                    'status' => Booking_payments_model::HOLD_ATTENTION,
                ]);

                $summary['attention']++;

                continue;
            }

            $this->model()->update_hold((int) $hold['id'], [
                'status' => Booking_payments_model::HOLD_EXPIRED,
            ]);

            $summary['expired']++;
        }

        // 3. Los avisos pendientes.
        $summary['outbox'] = $this->dispatch_outbox();

        return $summary;
    }

    /**
     * Deliver the pending notices (email, calendar and webhook).
     *
     * The delivery is at-least-once: a retry can send the same email again. The appointment is never touched here.
     *
     * @return int How many events were delivered.
     */
    public function dispatch_outbox(int $limit = 20): int
    {
        $delivered = 0;

        foreach ($this->model()->pending_events($limit) as $event) {
            if (!$this->claim_event((int) $event['id'])) {
                continue;
            }

            try {
                $this->deliver($event);

                $this->model()->update_event((int) $event['id'], [
                    'status' => 'done',
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'last_error' => null,
                ]);

                $delivered++;
            } catch (Throwable $e) {
                $attempts = (int) $event['attempts'] + 1;

                $this->model()->update_event((int) $event['id'], [
                    'status' => $attempts >= self::OUTBOX_MAX_ATTEMPTS ? 'failed' : 'pending',
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'available_at' => date(
                        'Y-m-d H:i:s',
                        time() + min(3600, 60 * (2 ** min($attempts, 6))),
                    ),
                    'claim_token' => null,
                    'claim_expires_at' => null,
                ]);

                // Un aviso que falla no invalida la cita pagada.
                log_message('error', 'Outbox ' . $event['event'] . ': ' . $e->getMessage());
            }
        }

        try {
            if (!($this->CI->staff_push instanceof Staff_push)) { $this->CI->load->library('staff_push'); }
            $this->CI->staff_push->dispatch();
        } catch (Throwable $e) {
            log_message('error', 'Backoffice push: mantenimiento pendiente; se reintentará por cron.');
        }
        return $delivered;
    }

    /**
     * Take the control of an event (atomic lease).
     */
    protected function claim_event(int $event_id): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->CI->db->query(
            'UPDATE `' . $this->CI->db->dbprefix('outbox_events') . '`' .
            ' SET `claim_token` = ?, `claim_expires_at` = ?, `update_datetime` = ?' .
            ' WHERE `id` = ?' .
            ' AND (`claim_token` IS NULL OR `claim_expires_at` IS NULL OR `claim_expires_at` < ?)' .
            ' AND `status` IN ("pending", "processing")',
            [
                bin2hex(random_bytes(8)),
                date('Y-m-d H:i:s', time() + self::CLAIM_TTL_SECONDS),
                $now,
                $event_id,
                $now,
            ],
        );

        return $this->CI->db->affected_rows() === 1;
    }

    /**
     * Do what the event says (email, calendar and webhook).
     */
    protected function deliver(array $event): void
    {
        $payload = json_decode((string) $event['payload'], true) ?: [];

        $appointment_id = (int) ($event['id_appointments'] ?? ($payload['appointment_id'] ?? 0));

        if ($appointment_id <= 0) {
            throw new RuntimeException('El aviso no tiene cita.');
        }

        $appointment = $this->CI->appointments_model->find($appointment_id);

        if (
            $event['event'] !== 'appointment_cancelled' &&
            !empty($appointment['status']) &&
            $appointment['status'] === 'Cancelado'
        ) {
            // Una cita cancelada no avisa: el dinero se conserva, pero no hay nada que confirmar.
            return;
        }

        $service = $this->CI->services_model->find((int) $appointment['id_services']);

        $provider = $this->CI->providers_model->find((int) $appointment['id_users_provider']);

        $customer = $this->CI->customers_model->find((int) $appointment['id_users_customer']);

        $settings = [
            'company_name' => setting('company_name'),
            'company_email' => setting('company_email'),
            'company_link' => setting('company_link'),
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
        ];

        $manage_mode = $event['event'] === 'appointment_moved';

        if ($event['event'] === 'appointment_cancelled') {
            // Blondor: la cita se conserva (con su pago) pero sale de la agenda y del calendario.
            $this->CI->synchronization->sync_appointment_deleted($appointment, $provider, true);

            $this->CI->notifications->notify_appointment_deleted(
                $appointment,
                $service,
                $provider,
                $customer,
                $settings,
                (string) ($payload['reason'] ?? ''),
                true,
            );

            $this->CI->webhooks_client->trigger(WEBHOOK_APPOINTMENT_DELETE, $appointment, true);

            return;
        }

        $this->CI->notifications->notify_appointment_saved(
            $appointment,
            $service,
            $provider,
            $customer,
            $settings,
            $manage_mode,
            true,
        );

        $this->CI->synchronization->sync_appointment_saved(
            $appointment,
            $service,
            $provider,
            $customer,
            $settings,
            true,
        );

        $this->CI->webhooks_client->trigger(WEBHOOK_APPOINTMENT_SAVE, $appointment, true);
    }

    /* ---------------------------------------------------------------------
     * Consultas para las vistas
     * ------------------------------------------------------------------ */

    /**
     * The public state of a booking (for the result page).
     */
    public function public_state(string $hold_token): ?array
    {
        $hold = $this->model()->find_by_hash($hold_token);

        if (!$hold) {
            return null;
        }

        $attempts = $this->model()->attempts_of_hold((int) $hold['id']);

        $status = $hold['status'];

        if ($status === Booking_payments_model::HOLD_PAID) {
            $status = 'paid';
        } elseif ($status === Booking_payments_model::HOLD_ATTENTION) {
            $status = 'attention';
        } elseif ($status === Booking_payments_model::HOLD_RELEASED) {
            $status = 'rejected';
        } elseif ($status === Booking_payments_model::HOLD_EXPIRED) {
            $status = 'expired';
        } elseif ($status === Booking_payments_model::HOLD_HELD) {
            // Todavía no sabemos si el pago se concretó.
            $status = 'pending';
        }

        $appointment = null;

        if ($hold['id_appointments']) {
            $appointment = $this->CI->appointments_model->find((int) $hold['id_appointments']);
        }

        return [
            'status' => $status,
            'hold_status' => $hold['status'],
            'service_name' => $hold['service_name'],
            'hair_length' => $hold['hair_length'],
            'start_datetime' => $hold['start_datetime'],
            'end_datetime' => $hold['end_datetime'],
            'total' => $hold['total_amount'] !== null ? (float) $hold['total_amount'] : null,
            'total_is_exact' => (bool) $hold['price_exact'],
            'price_type' => $hold['price_type'],
            'paid_amount' => (float) $hold['paid_amount'],
            'amount_due' => $hold['amount_due'] !== null ? (float) $hold['amount_due'] : null,
            'mode' => $hold['mode'],
            'appointment' => $appointment,
            'attempts' => count($attempts),
            'expires_at' => $hold['expires_at'],
        ];
    }
}
