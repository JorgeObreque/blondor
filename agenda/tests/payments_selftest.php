<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Pruebas de la reserva con pago
 *
 * Corre dentro de la app (php index.php console test_payments) y usa un doble de Webpay: no se mueve dinero real
 * ni se llama a Transbank. Las fixtures son sintéticas (prefijo "zz_test_") y se limpian al final.
 *
 * Guardas: la suite no corre si el ambiente de Webpay es "production" ni si la base no es la de desarrollo.
 * ---------------------------------------------------------------------------- */

/**
 * Payments self test.
 *
 * @package Tests
 */
class Payments_selftest
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected $CI;

    /**
     * @var int
     */
    protected array $original_integrations = [];

    protected int $commit_calls = 0;

    protected int $passed = 0;

    /**
     * @var array
     */
    protected array $failures = [];

    /**
     * The transactions of the Webpay double.
     */
    protected array $transactions = [];

    /**
     * What the double answers: authorized | rejected | aborted | crash | mismatch.
     */
    protected string $scenario = 'authorized';

    /**
     * Counter of the slots used by the tests.
     */
    protected int $slot_counter = 0;

    /**
     * Fixtures created for the run.
     */
    protected array $fixtures = [
        'services' => [],
        'provider_id' => null,
        'customer_id' => null,
    ];

    /**
     * Constructor.
     */
    public function __construct($CI)
    {
        $this->CI = $CI;
    }

    /**
     * Run every test.
     */
    public function run(): array
    {
        $this->set_up();

        $tests = [
            'test_policy_limits',
            'test_prepare_holds_the_slot',
            'test_prepare_rejects_archived_and_free_services',
            'test_checkout_ignores_the_amount_of_the_browser',
            'test_double_click_creates_one_attempt',
            'test_concurrent_double_click_creates_one_attempt',
            'test_payment_creates_the_appointment_and_the_notice',
            'test_no_notice_before_the_payment',
            'test_double_callback_does_not_charge_twice',
            'test_parallel_callbacks_create_one_appointment',
            'test_parallel_public_and_internal_dispute',
            'test_parallel_public_clients_dispute',
            'test_rejected_payment_releases_the_hold',
            'test_aborted_payment_releases_the_hold',
            'test_abort_does_not_override_authorized_payment',
            'test_retry_after_timeout_uses_status',
            'test_pending_reconciliation_preserves_first_commit',
            'test_checkout_different_keys_uses_one_transaction',
            'test_http_422_is_uncertain',
            'test_variants_and_from_checkout',
            'test_catalog_change_during_payment_keeps_snapshot',
            'test_crash_is_reconciled_with_the_status',
            'test_expired_hold_is_not_released_without_a_result',
            'test_internal_booking_respects_the_hold',
            'test_price_change_does_not_revalue_a_paid_appointment',
            'test_catalog_change_does_not_revalue_a_paid_appointment',
            'test_attribution_code_reaches_the_appointment_and_the_api',
            'test_funnel_counts_each_step_once_and_lists_unpaid_bookings',
            'test_reschedule_keeps_the_payment',
            'test_reschedule_survives_a_catalog_price_change',
            'test_reschedule_of_a_paid_appointment_cannot_change_the_service',
            'test_reschedule_needs_the_capability_token',
            'test_cancellation_keeps_the_payment_and_frees_the_slot',
            'test_public_cancellation_keeps_the_payment',
            'test_paid_appointment_cannot_be_deleted',
            'test_entity_deletion_cannot_cascade_paid_history',
            'test_authorized_but_wrong_amount_goes_to_attention',
            'test_failing_notice_does_not_invalidate_the_appointment',
            'test_sync_failure_is_propagated_for_outbox',
        ];

        foreach ($tests as $test) {
            try {
                $this->$test();

                $this->passed++;
            } catch (Throwable $e) {
                $this->failures[] = $test . ': ' . $e->getMessage() . ' @ ' .
                    str_replace(__DIR__, '', $e->getFile() . ':' . $e->getLine());
            }
        }

        $this->tear_down();

        return [
            'passed' => $this->passed,
            'failed' => count($this->failures),
            'failures' => $this->failures,
        ];
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param mixed $value
     * @param string $message
     */
    protected function ok($value, string $message): void
    {
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    protected function equals($expected, $actual, string $message): void
    {
        if ($expected != $actual) {
            throw new RuntimeException(
                $message . ' (se esperaba ' . var_export($expected, true) . ' y llegó ' . var_export($actual, true) . ')',
            );
        }
    }

    /**
     * The Webpay double: it answers like Transbank, without moving money.
     */
    protected function fake_webpay(string $scenario = 'authorized', bool $reset = true): void
    {
        $this->scenario = $scenario;

        if ($reset) {
            $this->transactions = [];
        }

        $this->CI->webpay->set_transport(function (string $method, string $path, ?array $body) {
            $active = $this->CI->db->query('SELECT @@in_transaction AS active')->row_array();
            $this->equals(0, (int) $active['active'], 'Webpay se llama fuera de una transacción de BD');
            if ($method === 'POST') {
                $token = 'tok_' . count($this->transactions) . '_' . bin2hex(random_bytes(3));

                $this->transactions[$token] = $body;

                return [
                    'status' => 200,
                    'body' => [
                        'token' => $token,
                        'url' => 'https://webpay.test/form',
                    ],
                ];
            }

            $token = basename($path);

            if ($method === 'PUT') {
                $this->commit_calls++;
                if ($this->scenario === 'crash') {
                    throw new RuntimeException('Se cortó la conexión con Webpay.');
                }

                $transaction = $this->transactions[$token] ?? [];

                if ($this->scenario === 'rejected') {
                    return [
                        'status' => 200,
                        'body' => [
                            'status' => 'REJECTED',
                            'response_code' => -1,
                            'response_message' => 'Rechazada',
                            'amount' => $transaction['amount'] ?? 0,
                            'buy_order' => $transaction['buy_order'] ?? '',
                            'session_id' => $transaction['session_id'] ?? '',
                        ],
                    ];
                }

                $amount = $transaction['amount'] ?? 0;

                if ($this->scenario === 'mismatch') {
                    $amount = (int) $amount + 1;
                }

                return [
                    'status' => 200,
                    'body' => [
                        'status' => 'AUTHORIZED',
                        'response_code' => 0,
                        'response_message' => 'Autorizada',
                        'amount' => $amount,
                        'buy_order' => $transaction['buy_order'] ?? '',
                        'session_id' => $transaction['session_id'] ?? '',
                    ],
                ];
            }

            // GET: the state of the transaction.
            if (!isset($this->transactions[$token])) {
                return ['status' => 404, 'body' => ['error_message' => 'No existe']];
            }

            $transaction = $this->transactions[$token];

            $status = 'INITIALIZED';

            if ($this->scenario === 'authorized') {
                $status = 'AUTHORIZED';
            } elseif ($this->scenario === 'rejected') {
                $status = 'REJECTED';
            } elseif ($this->scenario === 'aborted') {
                $status = 'ABORTED';
            }

            return [
                'status' => 200,
                'body' => [
                    'status' => $status,
                    'response_code' => $status === 'AUTHORIZED' ? 0 : -1,
                    'amount' => $transaction['amount'] ?? 0,
                    'buy_order' => $transaction['buy_order'] ?? '',
                    'session_id' => $transaction['session_id'] ?? '',
                ],
            ];
        });
    }

    /**
     * Fixtures: a provider that works every day and a few services.
     */
    protected function set_up(): void
    {
        $this->CI->load->model('appointments_model');
        $this->CI->load->model('booking_payments_model');
        $this->CI->load->model('customers_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('service_variants_model');
        $this->CI->load->model('services_model');

        $this->CI->load->library('availability');
        $this->CI->load->library('booking_payments');
        $this->CI->load->library('booking_funnel');
        $this->CI->load->library('deposit_policy');
        $this->CI->load->library('slot_lock');
        $this->CI->load->library('webpay');

        // Refuse to reconcile or deliver unrelated local work; never contact external integrations.
        foreach (['payment_attempts' => 'idempotency_key', 'booking_holds' => 'idempotency_key'] as $table => $key) {
            if ($this->CI->db->not_like($key, 'zztest', 'after')->count_all_results($table)) {
                throw new RuntimeException('La suite requiere una BD local sin pagos ajenos a sus fixtures.');
            }
        }
        if ($this->CI->db->where_in('status', ['pending', 'processing'])->count_all_results('outbox_events')) {
            throw new RuntimeException('Hay avisos pendientes: no ejecutar pruebas sobre ellos.');
        }
        foreach (['notifications', 'synchronization', 'webhooks_client'] as $name) {
            $this->original_integrations[$name] = $this->CI->$name;
            $this->CI->$name = new class {
                public function __call($name, $arguments) { return null; }
            };
        }

        $this->clean_up();

        $now = date('Y-m-d H:i:s');

        // Provider with a working plan every day.
        $this->CI->db->insert('users', [
            'create_datetime' => $now,
            'update_datetime' => $now,
            'first_name' => 'zz_test_profesional',
            'last_name' => 'zz_test',
            'email' => 'zz_test_provider@blondor.test',
            'phone_number' => '+56900000001',
            'timezone' => 'America/Santiago',
            'language' => 'spanish',
            'id_roles' => 2,
        ]);

        $provider_id = (int) $this->CI->db->insert_id();

        $working_plan = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $working_plan[$day] = ['start' => '08:00', 'end' => '20:00', 'breaks' => []];
        }

        $this->CI->db->insert('user_settings', [
            'id_users' => $provider_id,
            'working_plan' => json_encode($working_plan),
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'caldav_sync' => 0,
            'sync_past_days' => 30,
            'sync_future_days' => 90,
            'calendar_view' => 'default',
        ]);

        $this->fixtures['provider_id'] = $provider_id;

        $this->fixtures['services']['exact'] = $this->make_service('zz_test_exacto', 45000, 'fixed', 30);
        $this->fixtures['services']['from'] = $this->make_service('zz_test_desde', 27990, 'from', 30);
        $this->fixtures['services']['cheap'] = $this->make_service('zz_test_barato', 5000, 'fixed', 30);
        $this->fixtures['services']['archived'] = $this->make_service('zz_test_archivado', 10000, 'fixed', 30);
        $this->fixtures['services']['free'] = $this->make_service('zz_test_gratis', 0, 'fixed', 30);
        $this->fixtures['services']['variant'] = $this->make_service('zz_test_variante', 25000, 'from', 30);

        // Variants of the service priced by length: short (29999) and long (30000).
        $this->CI->db->insert('service_variants', [
            'id_services' => $this->fixtures['services']['variant'],
            'hair_length' => 'short',
            'price' => 29999,
            'duration' => null,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        $this->CI->db->insert('service_variants', [
            'id_services' => $this->fixtures['services']['variant'],
            'hair_length' => 'long',
            'price' => 30000,
            'duration' => null,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        // The archived service is not offered.
        $this->CI->db->update('services', ['is_archived' => 1], ['id' => $this->fixtures['services']['archived']]);

        // Customer of the tests.
        $this->CI->db->insert('users', [
            'create_datetime' => $now,
            'update_datetime' => $now,
            'first_name' => 'zz_test_clienta',
            'last_name' => 'zz_test',
            'email' => 'zz_test_cliente@blondor.test',
            'phone_number' => '+56900000002',
            'timezone' => 'America/Santiago',
            'language' => 'spanish',
            'id_roles' => 3,
        ]);

        $this->fixtures['customer_id'] = (int) $this->CI->db->insert_id();
    }

    /**
     * A service of the tests.
     */
    protected function make_service(string $name, float $price, string $price_type, int $duration): int
    {
        $now = date('Y-m-d H:i:s');

        $this->CI->db->insert('services', [
            'create_datetime' => $now,
            'update_datetime' => $now,
            'name' => $name,
            'duration' => $duration,
            'price' => $price,
            'price_type' => $price_type,
            'availabilities_type' => 'flexible',
            'attendants_number' => 1,
            'is_private' => 0,
            'is_archived' => 0,
            'color' => '#7cbae8',
        ]);

        $service_id = (int) $this->CI->db->insert_id();

        $this->CI->db->insert('services_providers', [
            'id_users' => $this->fixtures['provider_id'],
            'id_services' => $service_id,
        ]);

        return $service_id;
    }

    /**
     * Clean the fixtures of a previous run.
     *
     * The appointments that were PAID are never deleted: they are financial history. Only the ones that came from a
     * test booking (or that have no money on them) are removed.
     */
    protected function clean_up(): void
    {
        // Las citas que nacieron de una reserva de prueba se borran (son de prueba).
        $this->CI->db->query(
            'DELETE a FROM `' . $this->CI->db->dbprefix('appointments') . '` a' .
            ' INNER JOIN `' . $this->CI->db->dbprefix('booking_holds') . '` h ON h.id_appointments = a.id' .
            ' WHERE h.idempotency_key LIKE "zztest%"',
        );

        $services = $this->CI->db
            ->select('id')
            ->from('services')
            ->like('name', 'zz_test_')
            ->get()
            ->result_array();

        $service_ids = array_map(fn($row) => (int) $row['id'], $services);

        if ($service_ids) {
            $this->CI->db->where_in('id_services', $service_ids)->delete('service_variants');
            $this->CI->db->where_in('id_services', $service_ids)->delete('services_providers');
            $this->delete_unpaid_appointments(['id_services' => $service_ids]);
            $this->CI->db->where_in('id', $service_ids)->delete('services');
        }

        $providers = $this->CI->db
            ->select('id')
            ->from('users')
            ->like('first_name', 'zz_test_')
            ->get()
            ->result_array();

        $provider_ids = array_map(fn($row) => (int) $row['id'], $providers);

        if ($provider_ids) {
            $this->delete_unpaid_appointments(['id_users_provider' => $provider_ids]);
            $this->CI->db->where_in('id_users', $provider_ids)->delete('user_settings');
            $this->CI->db->where_in('id', $provider_ids)->delete('users');
        }

        $customers = $this->CI->db
            ->select('id')
            ->from('users')
            ->like('email', 'zz_test_')
            ->get()
            ->result_array();

        $customer_ids = array_map(fn($row) => (int) $row['id'], $customers);

        if ($customer_ids) {
            $this->delete_unpaid_appointments(['id_users_customer' => $customer_ids]);
            $this->CI->db->where_in('id', $customer_ids)->delete('users');
        }

        // The bookings of the tests.
        $this->CI->db->query(
            'DELETE FROM `' . $this->CI->db->dbprefix('booking_holds') . '` WHERE `idempotency_key` LIKE "zztest%"',
        );
        $this->CI->db->query(
            'DELETE FROM `' . $this->CI->db->dbprefix('payment_attempts') . '` WHERE `idempotency_key` LIKE "zztest%"',
        );
        $this->CI->db->query(
            'DELETE FROM `' . $this->CI->db->dbprefix('outbox_events') . '` WHERE `dedupe_key` LIKE "%zz_test_%"',
        );
        $this->CI->db->query(
            'DELETE FROM `' . $this->CI->db->dbprefix('booking_funnel_events') . '` WHERE `session_id` LIKE "zztest%"',
        );
    }

    /**
     * Delete the appointments that match the filter and that have no money on them.
     *
     * A paid appointment is financial history: a test never deletes it.
     *
     * @param array $where Filter of the appointments.
     */
    protected function delete_unpaid_appointments(array $where): void
    {
        foreach ($where as $column => $value) {
            if (is_array($value)) {
                $this->CI->db->where_in($column, $value);
            } else {
                $this->CI->db->where($column, $value);
            }
        }

        $this->CI->db
            ->where('IFNULL(payment_status, "") NOT IN ("deposit_paid", "paid", "attention")')
            ->where('IFNULL(paid_amount, 0) = 0')
            ->delete('appointments');
    }

    /**
     * Remove the fixtures.
     */
    protected function tear_down(): void
    {
        $this->clean_up();

        $this->CI->webpay->set_transport(null);
        foreach ($this->original_integrations as $name => $integration) {
            $this->CI->$name = $integration;
        }
    }

    /**
     * The next free slot of the tests: every test gets its own hour (45 minutes apart), so the tests do not
     * fight each other for the same slot.
     */
    protected function next_slot(): array
    {
        $index = $this->slot_counter++;

        $per_day = 16;
        $day = 1 + intdiv($index, $per_day);
        $slot = $index % $per_day;

        $start = date('Y-m-d', strtotime('+' . $day . ' days')) . ' ' .
            date('H:i', strtotime('08:00 +' . ($slot * 45) . ' minutes'));

        return [$start . ':00', date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')), substr($start, 0, 10)];
    }

    /**
     * The booking request of the tests.
     */
    protected function request(
        string $service_key,
        ?array $slot = null,
        ?string $hair_length = null,
        string $email = 'zz_test_cliente@blondor.test',
    ): array {
        $slot = $slot ?: $this->next_slot();

        [$start, $end] = $slot;

        return [
            'appointment' => [
                'start_datetime' => $start,
                'end_datetime' => $end,
                'notes' => 'prueba zz_test',
                'is_unavailability' => false,
                'id_users_provider' => $this->fixtures['provider_id'],
                'id_services' => $this->fixtures['services'][$service_key],
                'hair_length' => $hair_length ?? '',
            ],
            'customer' => [
                'first_name' => 'ZZ Test',
                'last_name' => 'Cliente',
                'email' => $email,
                'phone_number' => '+56900000002',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => 'America/Santiago',
            ],
            'manage_mode' => 0,
        ];
    }

    /**
     * The appointment of a hold (NULL when the booking has no appointment yet).
     */
    protected function appointment_of(string $hold_token): ?array
    {
        $hold = $this->CI->booking_payments_model->find_by_hash($hold_token);

        if (!$hold || !$hold['id_appointments']) {
            return null;
        }

        return $this->CI->appointments_model->find((int) $hold['id_appointments']);
    }

    /**
     * Each test has its own client and its own "device", so the quotas of the app do not get in the way.
     */
    protected function test_email(string $key): string
    {
        return 'zz_test_' . $key . '@blondor.test';
    }

    protected function test_ip(string $key): string
    {
        return '10.0.0.' . (crc32($key) % 250 + 1);
    }

    /**
     * Prepare a booking of the tests.
     */
    protected function prepare(string $service_key, string $key, ?array $slot = null, ?string $hair_length = null): array
    {
        return $this->CI->booking_payments->prepare(
            $this->request($service_key, $slot, $hair_length, $this->test_email($key)),
            [
                'idempotency_key' => 'zztest' . $key,
                'ip' => $this->test_ip($key),
                'email' => $this->test_email($key),
            ],
        );
    }

    /* ---------------------------------------------------------------------
     * Tests
     * ------------------------------------------------------------------ */

    protected function test_policy_limits(): void
    {
        $policy = $this->CI->deposit_policy;
        $this->ok(!Webpay::is_authorized(['status' => 'AUTHORIZED', 'response_code' => 'invalid']),
            'Una respuesta inválida no equivale a response_code cero');

        $options = $policy->options(29999);
        $this->equals(8000.0, $options['deposit'], 'Con 29999 el abono es de 8000');
        $this->equals(2, count($options['options']), 'Con precio exacto hay dos formas de pagar');
        $this->equals(21999.0, $policy->balance($options, 'deposit'), 'El saldo es el total menos el abono');

        $options = $policy->options(30000);
        $this->equals(15000.0, $options['deposit'], 'Con 30000 el abono es de 15000');
        $this->equals(15000.0, $policy->balance($options, 'deposit'), 'El saldo con 30000 es 15000');

        $options = $policy->options(5000);
        $this->equals(5000.0, $options['deposit'], 'El abono nunca pasa del total');
        $this->equals(0.0, $policy->balance($options, 'deposit'), 'Un servicio más barato queda pagado');

        // "Desde": el mínimo no es el total, así que solo hay abono.
        $options = $policy->options(null, 27990);
        $this->equals(1, count($options['options']), 'Con precio "desde" solo se puede pagar abono');
        $this->equals(8000.0, $options['deposit'], 'El abono de un servicio "desde" se calcula con el mínimo');
        $this->equals(null, $options['total'], 'El total no se inventa cuando el precio es "desde"');
        $this->equals(null, $policy->balance($options, 'deposit'), 'El saldo queda sin conocer');

        // El modo completo no existe cuando el precio no es exacto.
        $this->equals(null, $policy->amount($options, 'full'), 'No se puede pagar el total si no se conoce');
    }

    protected function test_prepare_holds_the_slot(): void
    {
        $booking = $this->prepare('exact', 'hold1');

        $this->ok(!empty($booking['hold_token']), 'La reserva devuelve su token');
        $this->equals(2, count($booking['options']), 'Hay abono y pago completo');
        $this->equals(15000.0, $booking['options'][0]['amount'], 'El abono de un servicio de 45000 es 15000');

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->ok($hold !== null, 'La reserva queda guardada');
        $this->equals('held', $hold['status'], 'La reserva queda apartada');

        // A second booking for the same hour does not get through.
        $failed = false;

        try {
            $this->prepare('exact', 'hold2', [$booking['when']['start'], $booking['when']['end']]);
        } catch (Throwable $e) {
            $failed = true;
        }

        $this->ok($failed, 'El horario apartado no se puede volver a tomar');
    }

    protected function test_prepare_rejects_archived_and_free_services(): void
    {
        $failed = false;

        try {
            $this->prepare('archived', 'archived');
        } catch (Throwable $e) {
            $failed = true;

            $this->ok(
                str_contains($e->getMessage(), 'archivado') || str_contains($e->getMessage(), 'disponible'),
                'El servicio archivado se explica: ' . $e->getMessage(),
            );
        }

        $this->ok($failed, 'Un servicio archivado no se puede reservar');

        $failed = false;

        try {
            $this->prepare('free', 'free');
        } catch (Throwable $e) {
            $failed = true;
        }

        $this->ok($failed, 'Un servicio sin precio no se puede reservar');
    }

    protected function test_checkout_ignores_the_amount_of_the_browser(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'amount');

        // The browser asks for the full payment of a service whose price is exact: the server does the math.
        $payment = $this->CI->booking_payments->start_payment(
            $booking['hold_token'],
            'full',
            'zztestamount',
        );

        $this->equals(45000.0, (float) $payment['amount'], 'El monto lo calcula el servidor');

        $attempt = $this->CI->booking_payments_model->find_attempt_by_idempotency('zztestamount');

        $this->equals(45000.0, (float) $attempt['amount'], 'El intento guarda el monto del servidor');
        $this->equals(1, count($this->transactions), 'Se creó una sola transacción de Webpay');
    }

    protected function test_double_click_creates_one_attempt(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'double');

        $first = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestdouble');
        $second = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestdouble');

        $this->equals($first['token'], $second['token'], 'El doble clic devuelve la misma transacción');
        $this->equals(1, count($this->transactions), 'El doble clic no crea dos transacciones');

        $attempts = $this->CI->booking_payments_model->attempts_of_hold(
            (int) $this->CI->booking_payments_model->find_by_hash($booking['hold_token'])['id'],
        );

        $this->equals(1, count($attempts), 'El doble clic no crea dos intentos');
    }

    protected function test_payment_creates_the_appointment_and_the_notice(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'paid');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestpaid');

        $result = $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => array_key_first($this->transactions),
            'TBK_ORDEN_COMPRA' => $this->transactions[array_key_first($this->transactions)]['buy_order'],
            'TBK_ID_SESION' => $this->transactions[array_key_first($this->transactions)]['session_id'],
        ]);

        $this->equals('paid', $result['status'], 'El pago queda confirmado');

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $this->ok($state['appointment'] !== null, 'La cita existe después del pago');
        $this->equals(15000.0, (float) $state['paid_amount'], 'Se guardó lo pagado');
        $this->equals(30000.0, (float) $state['amount_due'], 'Se guardó el saldo');

        $appointment = $state['appointment'];

        $this->equals('deposit_paid', $appointment['payment_status'], 'La cita queda con abono pagado');
        $this->equals(45000.0, (float) $appointment['total_amount'], 'La cita guarda el total');
        $this->equals(45000.0, (float) $appointment['price'], 'La cita guarda el precio');

        // The notice is queued in the same transaction that confirms the appointment.
        $events = $this->CI->db
            ->get_where('outbox_events', ['dedupe_key' => 'appointment_paid:' . $appointment['id']])
            ->row_array();

        $this->ok($events !== null, 'El aviso quedó en la bandeja');
    }

    protected function test_no_notice_before_the_payment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'nonotice');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestnonotice');

        $this->equals(null, $this->appointment_of($booking['hold_token']), 'No hay cita antes del pago');

        $events = $this->CI->db
            ->where('id_booking_holds', $this->CI->booking_payments_model->find_by_hash($booking['hold_token'])['id'])
            ->get('outbox_events')
            ->result_array();

        $this->equals(0, count($events), 'No hay aviso antes del pago');
    }

    protected function test_double_callback_does_not_charge_twice(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'doublecallback');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestdoublecallback');

        $token = array_key_first($this->transactions);

        $payload = [
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
            'TBK_ID_SESION' => $this->transactions[$token]['session_id'],
        ];

        $first = $this->CI->booking_payments->handle_webpay_return($payload);
        $second = $this->CI->booking_payments->handle_webpay_return($payload);

        $this->equals('paid', $first['status'], 'El primer aviso paga');
        $this->equals('paid', $second['status'], 'El segundo aviso no cobra otra vez');

        $this->ok(
            $this->appointment_of($booking['hold_token']) !== null,
            'El doble aviso no crea dos citas',
        );

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $this->equals(15000.0, (float) $state['paid_amount'], 'El pago se cuenta una sola vez');
    }

    protected function test_rejected_payment_releases_the_hold(): void
    {
        $this->fake_webpay('rejected');

        $booking = $this->prepare('exact', 'rejected');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestrejected');

        $token = array_key_first($this->transactions);

        $result = $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $this->equals('rejected', $result['status'], 'El pago rechazado se informa');

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->equals('released', $hold['status'], 'La reserva se suelta');

        $this->equals(null, $this->appointment_of($booking['hold_token']), 'No hay cita con un pago rechazado');
    }

    protected function test_aborted_payment_releases_the_hold(): void
    {
        $this->fake_webpay('aborted');

        $booking = $this->prepare('exact', 'aborted');

        $payment = $this->CI->booking_payments->start_payment(
            $booking['hold_token'],
            'deposit',
            'zztestaborted',
        );

        // The client cancels on the Webpay form: it comes back with TBK_TOKEN and no token_ws.
        $result = $this->CI->booking_payments->handle_webpay_return([
            'TBK_TOKEN' => $payment['token'],
            'TBK_ORDEN_COMPRA' => $this->transactions[$payment['token']]['buy_order'],
        ]);

        $this->equals('aborted', $result['status'], 'La cancelación en Webpay se informa');

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->equals('released', $hold['status'], 'La reserva se suelta');
        $this->equals(0.0, (float) $hold['paid_amount'], 'No se cobró nada');
    }

    protected function test_abort_does_not_override_authorized_payment(): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'lateabort');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestlateabort');
        $result = $this->CI->booking_payments->handle_webpay_return(['TBK_TOKEN' => $payment['token']]);
        $this->equals('paid', $result['status'], 'Un retorno de abandono consulta el pago aprobado');
        $this->CI->booking_payments->handle_webpay_return(['TBK_TOKEN' => $payment['token']]);
        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);
        $this->equals('paid', $hold['status'], 'Un abandono tardío no borra un pago');
    }

    protected function test_retry_after_timeout_uses_status(): void
    {
        $this->fake_webpay('crash');
        $booking = $this->prepare('exact', 'retrytimeout');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestretrytimeout');
        $before = $this->commit_calls;
        $this->CI->booking_payments->handle_webpay_return(['token_ws' => $payment['token']]);
        $this->fake_webpay('authorized', false);
        $result = $this->CI->booking_payments->handle_webpay_return(['token_ws' => $payment['token']]);
        $this->equals('paid', $result['status'], 'El reintento recupera la autorización');
        $this->equals($before + 1, $this->commit_calls, 'No se repite commit después de un timeout');
    }

    protected function test_pending_reconciliation_preserves_first_commit(): void
    {
        $this->fake_webpay('pending');
        $booking = $this->prepare('exact', 'pendingfirst');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestpendingfirst');
        $this->CI->db->update('payment_attempts', ['create_datetime' => date('Y-m-d H:i:s', time() - 180)],
            ['idempotency_key' => 'zztestpendingfirst']);
        $this->CI->booking_payments->reconcile();
        $attempt = $this->CI->booking_payments_model->find_attempt_by_token($payment['token']);
        $this->equals('created', $attempt['status'], 'Consultar INITIALIZED no consume el primer commit');
        $this->fake_webpay('authorized', false);
        $result = $this->CI->booking_payments->handle_webpay_return(['token_ws' => $payment['token']]);
        $this->equals('paid', $result['status'], 'La clienta puede terminar tras pasar el cron');
    }

    protected function test_checkout_different_keys_uses_one_transaction(): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'twokeys');
        $first = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztesttwokeys1');
        $second = $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztesttwokeys2');
        $this->equals($first['token'], $second['token'], 'Otra pestaña reutiliza el intento vigente');
        $this->equals(1, count($this->transactions), 'Solo se crea una transacción por reserva');
    }

    protected function test_http_422_is_uncertain(): void
    {
        $this->CI->webpay->set_transport(fn() => ['status' => 422, 'body' => ['error_message' => 'Unknown']]);
        $state = $this->CI->webpay->state('zz_test_422');
        $this->equals('error', $state['state'], '422 no prueba que la transacción no exista');
    }

    protected function test_variants_and_from_checkout(): void
    {
        $this->fake_webpay('authorized');
        foreach (['short' => 8000, 'long' => 15000] as $length => $amount) {
            $booking = $this->prepare('variant', 'variant' . $length, null, $length);
            $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestvariant' . $length);
            $this->equals($amount, $payment['amount'], 'La variante determina el abono en servidor');
        }
        $booking = $this->prepare('from', 'fromonly');
        $failed = false;
        try {
            $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestfromfull');
        } catch (InvalidArgumentException $e) { $failed = true; }
        $this->ok($failed, 'Un servicio desde no permite pago completo');
    }

    protected function test_catalog_change_during_payment_keeps_snapshot(): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'snapshotcheckout');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestsnapshotcheckout');
        $this->CI->db->update('services', ['price' => 99000], ['id' => $this->fixtures['services']['exact']]);
        try {
            $this->CI->booking_payments->handle_webpay_return(['token_ws' => $payment['token']]);
            $appointment = $this->appointment_of($booking['hold_token']);
            $this->equals(45000.0, (float) $appointment['price'], 'El precio contratado sobrevive al cambio durante Webpay');
            $this->equals(45000.0, (float) $appointment['total_amount'], 'El total conserva el snapshot');
        } finally {
            $this->CI->db->update('services', ['price' => 45000], ['id' => $this->fixtures['services']['exact']]);
        }
    }

    protected function test_parallel_callbacks_create_one_appointment(): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'parallelcallbacks');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestparallelcallbacks');
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, 'index.php', 'console', 'payments_test_worker',
                $this->CI->db->database, $payment['token']],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FCPATH);
            $this->ok(is_resource($process), 'El worker paralelo se inicia');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->equals(0, proc_close($process), 'El worker termina correctamente: ' . $error . $output);
        }
        $log = sys_get_temp_dir() . '/blondor-payment-' . hash('sha256', $payment['token']);
        try {
            $this->equals("commit\n", file_get_contents($log), 'Dos procesos ejecutan un solo commit');
            $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);
            $this->equals('paid', $hold['status'], 'El pago paralelo confirma la reserva');
            $this->equals(1, $this->CI->db->where('id_booking_holds', $hold['id'])->count_all_results('appointments'),
                'Los callbacks crean una sola cita');
        } finally {
            if (is_file($log)) { unlink($log); }
        }
    }

    protected function test_parallel_public_and_internal_dispute(): void
    {
        $this->run_parallel_slot_dispute(['public', 'internal'], 'parallelslot');
    }

    protected function test_parallel_public_clients_dispute(): void
    {
        $this->run_parallel_slot_dispute(['public', 'public'], 'parallelclients');
    }

    protected function run_parallel_slot_dispute(array $actions, string $key): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', $key);
        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);
        $this->CI->booking_payments_model->update_hold($hold['id'], ['status' => 'released']);
        $workers = [];
        foreach ($actions as $action) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, 'index.php', 'console', 'payments_test_worker',
                $this->CI->db->database, $booking['hold_token'], $action],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FCPATH);
            $this->ok(is_resource($process), 'Se inicia la disputa paralela');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->equals(0, proc_close($process), 'La disputa termina: ' . $error);
        }
        sort($results);
        $this->equals(['lost', 'won'], $results, 'Solo una vía adjudica el horario disputado');
        $appointments = $this->CI->db->where('id_users_provider', $hold['id_users_provider'])
            ->where('start_datetime', $hold['start_datetime'])->count_all_results('appointments');
        $holds = count($this->CI->booking_payments_model->holds_overlapping(
            $hold['id_users_provider'], $hold['start_datetime'], $hold['end_datetime'],
        ));
        $this->equals(1, $appointments + $holds, 'La capacidad se adjudica una sola vez');
    }

    protected function test_entity_deletion_cannot_cascade_paid_history(): void
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'cascade');
        $payment = $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestcascade');
        $this->CI->booking_payments->handle_webpay_return(['token_ws' => $payment['token']]);
        $appointment = $this->appointment_of($booking['hold_token']);
        foreach ([['customers_model', 'id_users_customer'], ['providers_model', 'id_users_provider'],
            ['services_model', 'id_services']] as [$model, $field]) {
            $failed = false;
            try { $this->CI->$model->delete((int) $appointment[$field]); }
            catch (RuntimeException $e) { $failed = true; }
            $this->ok($failed, 'El borrado relacionado no destruye citas pagadas: ' . $model);
        }
        $this->ok($this->appointment_of($booking['hold_token']) !== null, 'La cita financiera se conserva');
    }

    protected function test_sync_failure_is_propagated_for_outbox(): void
    {
        $synchronization = $this->original_integrations['synchronization'];
        $failed = false;
        try {
            $synchronization->sync_appointment_saved([], [], [
                'id' => $this->fixtures['provider_id'],
                'settings' => ['google_sync' => 1, 'google_token' => '', 'caldav_sync' => 0],
            ], [], [], true);
        } catch (RuntimeException $e) {
            $failed = true;
        }
        $this->ok($failed, 'La sincronización propaga errores al outbox sin contactar Google');
    }

    protected function test_crash_is_reconciled_with_the_status(): void
    {
        $this->fake_webpay('crash');

        $booking = $this->prepare('exact', 'crash');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestcrash');

        $token = array_key_first($this->transactions);

        // The commit falls down: the payment may or may not have happened.
        $result = $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $this->equals('unknown', $result['status'], 'Una caída no se toma como pago');

        $this->equals(
            null,
            $this->appointment_of($booking['hold_token']),
            'No hay cita mientras no se sepa el resultado',
        );

        // The maintenance job asks Transbank what happened (the same transaction, now authorized).
        $this->fake_webpay('authorized', false);

        // The attempt is old enough to be looked at (the job ignores the fresh ones).
        $this->CI->db->update(
            'payment_attempts',
            ['create_datetime' => date('Y-m-d H:i:s', time() - 600)],
            ['idempotency_key' => 'zztestcrash'],
        );

        $summary = $this->CI->booking_payments->reconcile();

        $this->ok($summary['reconciled'] >= 1, 'La conciliación recupera el pago');

        $this->ok(
            $this->appointment_of($booking['hold_token']) !== null,
            'La cita se crea al recuperar el pago',
        );

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $this->equals(15000.0, (float) $state['paid_amount'], 'El dinero queda registrado');
    }

    protected function test_expired_hold_is_not_released_without_a_result(): void
    {
        $this->fake_webpay('crash');

        $booking = $this->prepare('exact', 'expired');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestexpired');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        // The hold runs out of time with a payment that is still unknown.
        $this->CI->booking_payments_model->update_hold(
            (int) $this->CI->booking_payments_model->find_by_hash($booking['hold_token'])['id'],
            ['expires_at' => date('Y-m-d H:i:s', time() - 60)],
        );

        // The provider does not answer: the money may be real.
        $this->CI->webpay->set_transport(function () {
            return ['status' => 500, 'body' => ['error_message' => 'Sin conexión']];
        });

        $this->CI->booking_payments->reconcile();

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->ok(
            in_array($hold['status'], ['attention', 'held'], true),
            'Sin resultado confirmado la reserva no se suelta (queda en ' . $hold['status'] . ')',
        );

        $this->equals(0.0, (float) $hold['paid_amount'], 'No se tira el dinero');
        $this->ok(count($this->CI->booking_payments_model->holds_overlapping(
            (int) $hold['id_users_provider'], $hold['start_datetime'], $hold['end_datetime'],
        )) > 0, 'El horario sigue bloqueado aunque venció el hold con pago incierto');
    }

    protected function test_internal_booking_respects_the_hold(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'internal');

        // The agenda (internal booking) does not need a payment, but it does respect the slot.
        $failed = false;

        try {
            $this->CI->appointments_model->save([
                'id_users_provider' => $this->fixtures['provider_id'],
                'id_users_customer' => $this->fixtures['customer_id'],
                'id_services' => $this->fixtures['services']['exact'],
                'start_datetime' => $booking['when']['start'],
                'end_datetime' => $booking['when']['end'],
                'notes' => 'zz_test interno',
                'is_unavailability' => false,
            ]);
        } catch (Throwable $e) {
            $failed = true;
        }

        $this->ok($failed, 'La agenda no puede tomar un horario que se está pagando');
    }

    protected function test_price_change_does_not_revalue_a_paid_appointment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'pricechange');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestpricechange');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment_id = (int) $state['appointment']['id'];

        // The salon changes the price of the service.
        $this->CI->db->update('services', ['price' => 99000], ['id' => $this->fixtures['services']['exact']]);

        $this->CI->appointments_model->save(
            array_merge($state['appointment'], ['notes' => 'notas nuevas']),
        );

        $appointment = $this->CI->appointments_model->find($appointment_id);

        $this->equals(45000.0, (float) $appointment['price'], 'Una cita pagada no se revalora');
        $this->equals(45000.0, (float) $appointment['total_amount'], 'El total pagado no cambia');
        $this->equals(45000.0, (float) $appointment['paid_amount'], 'Lo pagado no cambia');

        // The price of the service goes back to what it was (the other tests use it).
        $this->CI->db->update('services', ['price' => 45000], ['id' => $this->fixtures['services']['exact']]);
    }

    /**
     * El origen (?ref=) viaja de la reserva apartada a la cita pagada y sale por la API; uno inválido se descarta y
     * una reagenda no lo pisa.
     */
    protected function test_attribution_code_reaches_the_appointment_and_the_api(): void
    {
        $this->fake_webpay('authorized');

        $request = $this->request('exact', null, null, $this->test_email('attribution'));
        $request['attribution_code'] = ' ads:cmp-abc123-wxyz ';

        $booking = $this->CI->booking_payments->prepare($request, [
            'idempotency_key' => 'zztestattribution',
            'ip' => $this->test_ip('attribution'),
            'email' => $this->test_email('attribution'),
        ]);

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->equals('ADS:CMP-ABC123-WXYZ', $hold['attribution_code'], 'La reserva apartada guarda el origen');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestattribution');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $appointment = $this->appointment_of($booking['hold_token']);

        $this->equals('ADS:CMP-ABC123-WXYZ', $appointment['attribution_code'], 'La cita pagada guarda el origen');

        $resource = $appointment;

        $this->CI->appointments_model->api_encode($resource);

        $this->equals('ADS:CMP-ABC123-WXYZ', $resource['attributionCode'], 'La API entrega el origen');
        $this->equals('deposit_paid', $resource['paymentStatus'], 'La API entrega el estado del pago');
        $this->equals(15000.0, $resource['paidAmount'], 'La API entrega lo pagado');
        $this->equals(45000.0, $resource['totalAmount'], 'La API entrega el total');
        $this->equals(30000.0, $resource['amountDue'], 'La API entrega el saldo');

        // La API no deja escribir el origen ni el pago.
        $decoded = ['attributionCode' => 'HACK', 'paidAmount' => 1, 'paymentStatus' => 'paid'];

        $this->CI->appointments_model->api_decode($decoded);

        $this->ok(
            !array_key_exists('attribution_code', $decoded) && !array_key_exists('paid_amount', $decoded)
                && !array_key_exists('payment_status', $decoded),
            'La API no acepta origen ni pago',
        );

        // Una reagenda con otro ?ref no cambia el origen de la cita.
        $move = $this->request('exact', $this->next_slot(), null, $this->test_email('attribution2'));
        $move['manage_mode'] = 1;
        $move['appointment']['id'] = (int) $appointment['id'];
        $move['attribution_code'] = 'ADS:OTRO-ANUNCIO';

        $manage_token = 'zztest_manage_' . bin2hex(random_bytes(8));

        app_cache()->save('manage-token-' . $manage_token, (int) $appointment['id'], 600);

        $moved_booking = $this->CI->booking_payments->prepare($move, [
            'idempotency_key' => 'zztestattribution2',
            'manage_token' => $manage_token,
            'ip' => $this->test_ip('attribution2'),
            'email' => $this->test_email('attribution2'),
        ]);

        $this->CI->booking_payments->confirm_free_hold($moved_booking['hold_token']);

        $moved = $this->CI->appointments_model->find((int) $appointment['id']);

        $this->equals('ADS:CMP-ABC123-WXYZ', $moved['attribution_code'], 'La reagenda conserva el origen');

        // Un origen con caracteres raros se descarta (la reserva sigue igual).
        foreach (['<script>', 'a', str_repeat('A', 49), ['ADS:X']] as $index => $bad) {
            $this->equals(null, $this->CI->booking_payments->attribution_code($bad), 'Origen inválido #' . $index);
        }
    }

    protected function test_funnel_counts_each_step_once_and_lists_unpaid_bookings(): void
    {
        $funnel = $this->CI->booking_funnel;
        $code = 'ADS:CMP-ZZFUNNEL-' . strtoupper(bin2hex(random_bytes(2)));
        $today = date('Y-m-d');

        $row_of = function () use ($funnel, $code, $today) {
            foreach ($funnel->report($today, $today) as $row) {
                if ($row['attribution_code'] === $code) {
                    return $row;
                }
            }

            return null;
        };

        // Dos visitas del anuncio: una llega a elegir hora, la otra se va en el sitio.
        foreach (['site', 'agenda', 'service', 'time', 'time'] as $step) {
            $this->ok(
                $funnel->track(['session' => 'zztestfunnelvisit1', 'step' => $step, 'ref' => strtolower($code)]),
                'Paso válido: ' . $step,
            );
        }

        $funnel->track(['session' => 'zztestfunnelvisit2', 'step' => 'site', 'ref' => $code, 'service' => '62']);

        // Inválidos: se descartan sin error.
        $this->ok(!$funnel->track(['session' => 'zz', 'step' => 'site']), 'Visita con identificador corto');
        $this->ok(!$funnel->track(['session' => 'zztestfunnelvisit3', 'step' => 'hack']), 'Paso desconocido');
        $this->ok(!$funnel->track(['session' => "zztest'; drop", 'step' => 'site']), 'Identificador con símbolos');

        $funnel->track(['session' => 'zztestfunnelvisit3', 'step' => 'site', 'ref' => '<script>']);

        $stored = $this->CI->db->get_where('booking_funnel_events', ['session_id' => 'zztestfunnelvisit3'])->row_array();

        $this->ok($stored && $stored['attribution_code'] === null, 'Un origen raro se guarda vacío');

        $row = $row_of();

        $this->equals(2, $row['steps']['site'] ?? null, 'Dos visitas en el sitio');
        $this->equals(1, $row['steps']['agenda'], 'Una llegó a la agenda');
        $this->equals(1, $row['steps']['time'], 'Repetir un paso no suma');
        $this->equals(0, $row['steps']['details'], 'Nadie envió sus datos');

        // Aparta hora y se arrepiente: queda en la lista para recuperarla.
        $this->fake_webpay('authorized');

        // Teléfono propio: el resto de las pruebas comparte uno y ya tiene citas.
        $email = $this->test_email('funnel');
        $phone = '+569' . random_int(10000000, 99999999);
        $request = $this->request('exact', null, null, $email);
        $request['customer']['phone_number'] = $phone;
        $request['attribution_code'] = $code;

        $gave_up = $this->CI->booking_payments->prepare($request, [
            'idempotency_key' => 'zztestfunnel1',
            'ip' => $this->test_ip('funnel'),
            'email' => $email,
        ]);

        $hold = $this->CI->booking_payments_model->find_by_hash($gave_up['hold_token']);

        $this->CI->booking_payments->release_hold((int) $hold['id']);

        $find = function () use ($funnel, $today, $hold) {
            foreach ($funnel->abandoned($today, $today) as $item) {
                if ($item['id'] === (int) $hold['id']) {
                    return $item;
                }
            }

            return null;
        };

        $item = $find();

        $this->ok($item !== null, 'La reserva sin pagar aparece en la lista');
        $this->equals($code, $item['attribution_code'] ?? null, 'Con su origen');
        $this->equals('gave_up', $item['reason'] ?? null, 'Y el motivo');
        $this->equals($email, $item['email'] ?? null, 'Y el correo para contactarla');
        $this->equals(false, $item['recovered'] ?? null, 'Todavía no reservó');

        // Vuelve y paga otra hora: queda como recuperada y cuenta como pagada para el anuncio.
        $request = $this->request('exact', $this->next_slot(), null, $email);
        $request['customer']['phone_number'] = $phone;
        $request['attribution_code'] = $code;

        $paid = $this->CI->booking_payments->prepare($request, [
            'idempotency_key' => 'zztestfunnel2',
            'ip' => $this->test_ip('funnel'),
            'email' => $email,
        ]);

        $this->CI->booking_payments->start_payment($paid['hold_token'], 'deposit', 'zztestfunnel2');

        $token = array_key_last($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $this->equals(true, $find()['recovered'] ?? null, 'Después de pagar queda como recuperada');

        $row = $row_of();

        $this->equals(1, $row['paid'], 'El anuncio suma una reserva pagada');
        $this->equals(15000.0, $row['paid_amount'], 'Con lo cobrado');

        $paid_ids = array_column(
            array_filter($funnel->abandoned($today, $today), fn($item) => $item['attribution_code'] === $code),
            'id',
        );

        $this->equals([(int) $hold['id']], array_values($paid_ids), 'La reserva pagada no aparece como abandonada');
    }

    protected function test_reschedule_keeps_the_payment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'reschedule');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestreschedule');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        // The customer comes back to move the appointment to another hour.
        $request = $this->request('exact', $this->next_slot(), null, $this->test_email('reschedule2'));

        $request['manage_mode'] = 1;
        $request['appointment']['id'] = (int) $appointment['id'];

        $manage_token = 'zztest_manage_' . bin2hex(random_bytes(8));

        app_cache()->save('manage-token-' . $manage_token, (int) $appointment['id'], 600);

        $new_booking = $this->CI->booking_payments->prepare(
            $request,
            [
                'idempotency_key' => 'zztestreschedule2',
                'manage_token' => $manage_token,
                'ip' => $this->test_ip('reschedule2'),
                'email' => $this->test_email('reschedule2'),
            ],
        );

        $this->equals(false, $new_booking['payment_required'], 'Una cita pagada no se vuelve a cobrar');
        $this->equals(0, count($new_booking['options']), 'No hay opciones de pago en una reagenda pagada');

        $this->CI->booking_payments->confirm_free_hold($new_booking['hold_token']);

        $moved = $this->CI->appointments_model->find((int) $appointment['id']);

        $this->equals($request['appointment']['start_datetime'], $moved['start_datetime'], 'La cita se movió de hora');
        $this->equals(15000.0, (float) $moved['paid_amount'], 'El abono se conserva');
        $this->equals(30000.0, (float) $moved['amount_due'], 'El saldo se conserva');
        $this->equals('deposit_paid', $moved['payment_status'], 'La cita sigue con abono pagado');
    }

    protected function test_reschedule_of_a_paid_appointment_cannot_change_the_service(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'changeservice');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestchangeservice');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        $request = $this->request('cheap', $this->next_slot(), null, $this->test_email('changeservice2'));

        $request['manage_mode'] = 1;
        $request['appointment']['id'] = (int) $appointment['id'];

        $manage_token = 'zztest_manage_' . bin2hex(random_bytes(8));

        app_cache()->save('manage-token-' . $manage_token, (int) $appointment['id'], 600);

        $failed = false;

        try {
            $this->CI->booking_payments->prepare(
                $request,
                [
                    'idempotency_key' => 'zztestchangeservice2',
                    'manage_token' => $manage_token,
                    'ip' => $this->test_ip('changeservice2'),
                    'email' => $this->test_email('changeservice2'),
                ],
            );
        } catch (Throwable $e) {
            $failed = true;

            $this->ok(
                str_contains($e->getMessage(), 'pago'),
                'El cambio de servicio en una cita pagada se explica: ' . $e->getMessage(),
            );
        }

        $this->ok($failed, 'Una cita pagada no cambia de servicio en línea');
    }

    protected function test_cancellation_keeps_the_payment_and_frees_the_slot(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'cancel');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestcancel');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        // The customer cancels: the appointment is kept (with its payment) and marked as cancelled.
        $this->CI->appointments_model->save(
            array_merge($appointment, ['status' => 'Cancelado']),
        );

        $cancelled = $this->CI->appointments_model->find((int) $appointment['id']);

        $this->ok($cancelled !== null, 'La cita cancelada no se borra');
        $this->equals('Cancelado', $cancelled['status'], 'La cita queda cancelada');
        $this->equals(45000.0, (float) $cancelled['paid_amount'], 'El pago se conserva');

        // The hour is free again.
        $provider = $this->CI->providers_model->find($this->fixtures['provider_id']);

        $service = $this->CI->services_model->find($this->fixtures['services']['exact']);

        $date = substr((string) $appointment['start_datetime'], 0, 10);
        $hour = date('H:i', strtotime((string) $appointment['start_datetime']));

        $hours = $this->CI->availability->get_available_hours($date, $service, $provider);

        $this->ok(in_array($hour, $hours, true), 'La hora de la cita cancelada queda libre');
    }

    protected function test_paid_appointment_cannot_be_deleted(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'delete');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestdelete');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment_id = (int) $state['appointment']['id'];

        $failed = false;

        try {
            $this->CI->appointments_model->assert_can_be_deleted($appointment_id);
        } catch (Throwable $e) {
            $failed = true;

            $this->ok(
                str_contains($e->getMessage(), 'Cancelado'),
                'El borrado de una cita pagada explica qué hacer: ' . $e->getMessage(),
            );
        }

        $this->ok($failed, 'Una cita pagada no se puede borrar');
    }

    /**
     * Two clicks at the same time: the database (the unique idempotency key) keeps one single attempt, so the client
     * is never charged twice.
     */
    protected function test_concurrent_double_click_creates_one_attempt(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'concurrent');

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $attempt = [
            'id_booking_holds' => (int) $hold['id'],
            'attempt_no' => 1,
            'token' => null,
            'amount' => 15000,
            'provider' => 'webpay',
            'status' => Booking_payments_model::ATTEMPT_CREATED,
            'idempotency_key' => 'zztestconcurrent',
        ];

        $first = $this->CI->booking_payments_model->insert_attempt_once(
            $attempt + ['buy_order' => 'BKzebra0001', 'session_id' => 'zzsess01'],
        );

        $this->ok($first['created'], 'El primer intento se crea');

        // The second click arrives at the same time: the key is already taken.
        $second = $this->CI->booking_payments_model->insert_attempt_once(
            $attempt + ['buy_order' => 'BKzebra0002', 'session_id' => 'zzsess02'],
        );

        $this->ok(!$second['created'], 'El segundo clic no crea otro intento');
        $this->equals($first['id'], $second['id'], 'Los dos clics apuntan al mismo intento');

        $attempts = $this->CI->booking_payments_model->attempts_of_hold((int) $hold['id']);

        $this->equals(1, count($attempts), 'Solo queda un intento de pago (no se cobra dos veces)');
    }

    /**
     * The salon changes the price of the catalog: the appointment that was already paid keeps the price it was paid.
     */
    protected function test_catalog_change_does_not_revalue_a_paid_appointment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'catalog');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestcatalog');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment_id = (int) $state['appointment']['id'];

        // The salon changes the price of the service in the catalog.
        $this->CI->load->model('service_catalog_model');
        $this->CI->service_catalog_model->save_changes(
            [['id' => $this->fixtures['services']['exact'], 'price' => 99000]],
            $this->fixtures['provider_id'],
        );

        $appointment = $this->CI->appointments_model->find($appointment_id);

        $this->equals(45000.0, (float) $appointment['price'], 'El catálogo no revalora una cita pagada');
        $this->equals(45000.0, (float) $appointment['total_amount'], 'El total pagado no cambia');
        $this->equals(15000.0, (float) $appointment['paid_amount'], 'Lo pagado no cambia');

        // The price of the service goes back to what it was (the other tests use it).
        $this->CI->service_catalog_model->save_changes(
            [['id' => $this->fixtures['services']['exact'], 'price' => 45000]],
            $this->fixtures['provider_id'],
        );
    }

    /**
     * The salon changes the price of the catalog after the payment: the client can still move the appointment
     * (same service) and is not charged again.
     */
    protected function test_reschedule_survives_a_catalog_price_change(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'catalogmove');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestcatalogmove');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        // The salon changes the price of the catalog.
        $this->CI->db->update('services', ['price' => 99000], ['id' => $this->fixtures['services']['exact']]);

        $request = $this->request('exact', $this->next_slot(), null, $this->test_email('catalogmove2'));

        $request['manage_mode'] = 1;
        $request['appointment']['id'] = (int) $appointment['id'];

        $manage_token = 'zztest_manage_' . bin2hex(random_bytes(8));

        app_cache()->save('manage-token-' . $manage_token, (int) $appointment['id'], 600);

        $new_booking = $this->CI->booking_payments->prepare(
            $request,
            [
                'idempotency_key' => 'zztestcatalogmove2',
                'manage_token' => $manage_token,
                'ip' => $this->test_ip('catalogmove2'),
                'email' => $this->test_email('catalogmove2'),
            ],
        );

        $this->equals(false, $new_booking['payment_required'], 'No se vuelve a cobrar');

        $this->CI->booking_payments->confirm_free_hold($new_booking['hold_token']);

        $moved = $this->CI->appointments_model->find((int) $appointment['id']);

        $this->equals(45000.0, (float) $moved['total_amount'], 'El total pagado se conserva');
        $this->equals(15000.0, (float) $moved['paid_amount'], 'El abono se conserva');
        $this->equals(30000.0, (float) $moved['amount_due'], 'El saldo se conserva');

        // The price of the service goes back to what it was (the other tests use it).
        $this->CI->db->update('services', ['price' => 45000], ['id' => $this->fixtures['services']['exact']]);
    }

    /**
     * A reschedule needs the capability of that appointment: a bare ID is not enough.
     */
    protected function test_reschedule_needs_the_capability_token(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'capability');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestcapability');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        $request = $this->request('exact', $this->next_slot(), null, $this->test_email('capability2'));

        $request['manage_mode'] = 1;
        $request['appointment']['id'] = (int) $appointment['id'];

        // Without the capability token.
        $failed = false;

        try {
            $this->CI->booking_payments->prepare(
                $request,
                [
                    'idempotency_key' => 'zztestcapability2',
                    'manage_token' => '',
                    'ip' => $this->test_ip('capability2'),
                    'email' => $this->test_email('capability2'),
                ],
            );
        } catch (Throwable $e) {
            $failed = true;
        }

        $this->ok($failed, 'Sin la capacidad no se puede tocar una cita ajena');

        // With a capability that belongs to another appointment.
        $other = $this->CI->booking_payments->public_state($booking['hold_token']);

        app_cache()->save('manage-token-zztest_wrong', (int) $other['appointment']['id'] + 1000, 600);

        $failed = false;

        try {
            $this->CI->booking_payments->prepare(
                $request,
                [
                    'idempotency_key' => 'zztestcapability3',
                    'manage_token' => 'zztest_wrong',
                    'ip' => $this->test_ip('capability3'),
                    'email' => $this->test_email('capability3'),
                ],
            );
        } catch (Throwable $e) {
            $failed = true;
        }

        $this->ok($failed, 'La capacidad tiene que ser de esa cita');
    }

    /**
     * The public cancellation (the same path the controller uses): the appointment stays with its payment.
     */
    protected function test_public_cancellation_keeps_the_payment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'publiccancel');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestpubliccancel');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment = $state['appointment'];

        // This is what Booking_cancellation does (the row stays, only the status changes).
        $cancelled = $this->CI->appointments_model->cancel((int) $appointment['id'], 'No puedo ir');

        $this->ok($cancelled !== null, 'La cita cancelada sigue en la base de datos');
        $this->equals('Cancelado', $cancelled['status'], 'La cita queda cancelada');
        $this->equals(45000.0, (float) $cancelled['paid_amount'], 'El pago se conserva');
        $this->equals(45000.0, (float) $cancelled['total_amount'], 'El total se conserva');
        $this->equals(45000.0, (float) $cancelled['price'], 'El precio pagado no se toca');

        // The hour is free again.
        $provider = $this->CI->providers_model->find($this->fixtures['provider_id']);

        $service = $this->CI->services_model->find($this->fixtures['services']['exact']);

        $date = substr((string) $appointment['start_datetime'], 0, 10);
        $hour = date('H:i', strtotime((string) $appointment['start_datetime']));

        $hours = $this->CI->availability->get_available_hours($date, $service, $provider);

        $this->ok(in_array($hour, $hours, true), 'La hora de la cita cancelada queda libre');
    }

    /**
     * Transbank authorizes but the amount is not the one we asked for: the money is registered and the case goes to
     * review (the appointment is not confirmed).
     */
    protected function test_authorized_but_wrong_amount_goes_to_attention(): void
    {
        $this->fake_webpay('mismatch');

        $booking = $this->prepare('exact', 'mismatch');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestmismatch');

        $token = array_key_first($this->transactions);

        $result = $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $this->equals('attention', $result['status'], 'Un monto que no cuadra va a revisión');

        $this->equals(null, $this->appointment_of($booking['hold_token']), 'No se confirma una cita con un pago raro');

        $hold = $this->CI->booking_payments_model->find_by_hash($booking['hold_token']);

        $this->equals('attention', $hold['status'], 'La reserva queda en revisión');
        $this->ok((float) $hold['paid_amount'] > 0, 'El dinero cobrado queda registrado');
    }

    /**
     * A notice that fails does not invalidate the paid appointment.
     */
    protected function test_failing_notice_does_not_invalidate_the_appointment(): void
    {
        $this->fake_webpay('authorized');

        $booking = $this->prepare('exact', 'badnotice');

        $this->CI->booking_payments->start_payment($booking['hold_token'], 'full', 'zztestbadnotice');

        $token = array_key_first($this->transactions);

        $this->CI->booking_payments->handle_webpay_return([
            'token_ws' => $token,
            'TBK_ORDEN_COMPRA' => $this->transactions[$token]['buy_order'],
        ]);

        $state = $this->CI->booking_payments->public_state($booking['hold_token']);

        $appointment_id = (int) $state['appointment']['id'];

        // A notice that cannot be delivered (it points to a citation that does not exist).
        $this->CI->booking_payments_model->enqueue(
            'appointment_paid',
            'zz_test_broken_notice',
            ['appointment_id' => 999999999],
            ['id_appointments' => 999999999],
        );

        $this->CI->booking_payments->dispatch_outbox();

        $appointment = $this->CI->appointments_model->find($appointment_id);

        $this->ok($appointment !== null, 'La cita pagada sigue viva');
        $this->equals(45000.0, (float) $appointment['paid_amount'], 'El pago sigue registrado');
        $this->equals('paid', $appointment['payment_status'], 'La cita sigue pagada');

        $event = $this->CI->db
            ->get_where('outbox_events', ['dedupe_key' => 'zz_test_broken_notice'])
            ->row_array();

        $this->ok($event !== null, 'El aviso que falló queda en la bandeja para reintentar');
        $this->ok((int) $event['attempts'] >= 1, 'El aviso que falló se reintenta después');
        $this->ok($event['status'] !== 'done', 'El aviso que falló no se marca como entregado');
    }
}
