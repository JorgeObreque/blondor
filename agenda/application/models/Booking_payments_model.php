<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Reservas con pago
 *
 * Acceso a los datos de la reserva (hold), los intentos de pago y la bandeja de avisos (outbox). No hace
 * cálculos de dinero: eso vive en Deposit_policy. No llama a Webpay: eso vive en Booking_payments.
 * ---------------------------------------------------------------------------- */

/**
 * Booking payments model.
 *
 * @package Models
 */
class Booking_payments_model extends EA_Model
{
    /**
     * Status of a hold.
     */
    const HOLD_HELD = 'held';

    const HOLD_PAID = 'paid';

    const HOLD_RELEASED = 'released';

    const HOLD_EXPIRED = 'expired';

    const HOLD_ATTENTION = 'attention';

    /**
     * Status of a payment attempt.
     */
    const ATTEMPT_CREATED = 'created';

    const ATTEMPT_AUTHORIZED = 'authorized';

    const ATTEMPT_REJECTED = 'rejected';

    const ATTEMPT_ABORTED = 'aborted';

    const ATTEMPT_FAILED = 'failed';

    const ATTEMPT_UNKNOWN = 'unknown';

    const ATTEMPT_ATTENTION = 'attention';

    /**
     * @var array
     */
    protected array $casts = [
        'id' => 'integer',
        'id_booking_holds' => 'integer',
        'id_users_provider' => 'integer',
        'id_users_customer' => 'integer',
        'id_services' => 'integer',
        'id_appointments' => 'integer',
        'duration' => 'integer',
        'price_exact' => 'boolean',
        'total_amount' => 'float',
        'deposit_amount' => 'float',
        'paid_amount' => 'float',
        'amount_due' => 'float',
        'attempt_no' => 'integer',
        'amount' => 'float',
        'attempts' => 'integer',
    ];

    /**
     * A hold by its public token.
     */
    public function find_by_hash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('booking_holds', ['hash' => $hash])->row_array());
    }

    /**
     * A hold by the idempotency key sent by the browser.
     */
    public function find_by_idempotency(string $idempotency_key): ?array
    {
        if ($idempotency_key === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('booking_holds', ['idempotency_key' => $idempotency_key])->row_array());
    }

    public function find_hold(int $id): ?array
    {
        return $this->cast_row($this->db->get_where('booking_holds', ['id' => $id])->row_array());
    }

    /**
     * Save a new hold.
     */
    public function insert_hold(array $hold): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->insert('booking_holds', $hold + [
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $this->db->insert_id();
    }

    /**
     * Update a hold.
     */
    public function update_hold(int $id, array $data): void
    {
        $this->db->update(
            'booking_holds',
            $data + ['update_datetime' => date('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    /** Expiry alone cannot release a slot while Webpay may have collected money. */
    protected function blocking_hold_condition(): string
    {
        $holds = $this->db->dbprefix('booking_holds');
        $attempts = $this->db->dbprefix('payment_attempts');
        $now = $this->db->escape(date('Y-m-d H:i:s'));

        return "(`$holds`.`status` = 'attention' OR (`$holds`.`status` = 'held' AND (" .
            "`$holds`.`expires_at` > $now OR EXISTS (SELECT 1 FROM `$attempts` p" .
            " WHERE p.id_booking_holds = `$holds`.id AND p.status IN ('created', 'unknown', 'attention', 'authorized')))))";
    }

    /**
     * Holds that are still blocking a slot (waiting for a payment).
     */
    public function active_holds(int $exclude_hold_id = null): array
    {
        $this->db
            ->where($this->blocking_hold_condition(), null, false);

        if ($exclude_hold_id) {
            $this->db->where('id !=', $exclude_hold_id);
        }

        $rows = $this->db->get('booking_holds')->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Active holds of a provider that overlap the given period.
     */
    public function holds_overlapping(
        int $provider_id,
        string $start_datetime,
        string $end_datetime,
        int $exclude_hold_id = null,
    ): array {
        $this->db
            ->where($this->blocking_hold_condition(), null, false)
            ->where('id_users_provider', $provider_id)
            ->where('start_datetime <', $end_datetime)
            ->where('end_datetime >', $start_datetime);

        if ($exclude_hold_id) {
            $this->db->where('id !=', $exclude_hold_id);
        }

        $rows = $this->db->get('booking_holds')->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Active holds of any provider that overlap the given period.
     *
     * Used by the salon-wide blocks (the salon closes and everything stops).
     *
     * @param string $start_datetime Period start (Y-m-d H:i:s).
     * @param string $end_datetime Period end (Y-m-d H:i:s).
     */
    public function holds_overlapping_period(string $start_datetime, string $end_datetime): array
    {
        $rows = $this->db
            ->where($this->blocking_hold_condition(), null, false)
            ->where('start_datetime <', $end_datetime)
            ->where('end_datetime >', $start_datetime)
            ->get('booking_holds')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Holds waiting for a payment, oldest first.
     */
    public function expired_holds(int $limit = 50): array
    {
        $rows = $this->db
            ->where('status', self::HOLD_HELD)
            ->where('expires_at <=', date('Y-m-d H:i:s'))
            ->order_by('expires_at', 'ASC')
            ->limit($limit)
            ->get('booking_holds')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Active holds of a provider that touch the given day.
     */
    public function holds_for_provider_date(int $provider_id, string $date, int $exclude_hold_id = null): array
    {
        $this->db
            ->where($this->blocking_hold_condition(), null, false)
            ->where('id_users_provider', $provider_id)
            ->where('DATE(start_datetime) <=', $date)
            ->where('DATE(end_datetime) >=', $date);

        if ($exclude_hold_id) {
            $this->db->where('id !=', $exclude_hold_id);
        }

        $rows = $this->db->get('booking_holds')->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * How many pending payments of the same service overlap the given period.
     */
    public function holds_count_for_period(
        DateTime $start,
        DateTime $end,
        int $service_id,
        int $provider_id,
        int $exclude_hold_id = null,
    ): int {
        $this->db
            ->where($this->blocking_hold_condition(), null, false)
            ->where('id_users_provider', $provider_id)
            ->where('id_services', $service_id)
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'));

        if ($exclude_hold_id) {
            $this->db->where('id !=', $exclude_hold_id);
        }

        $rows = $this->db->get('booking_holds')->result_array();

        return count($rows);
    }

    /**
     * How many pending payments of another service overlap the given period.
     */
    public function other_service_holds_count(
        DateTime $start,
        DateTime $end,
        int $service_id,
        int $provider_id,
        int $exclude_hold_id = null,
    ): int {
        $this->db
            ->where($this->blocking_hold_condition(), null, false)
            ->where('id_users_provider', $provider_id)
            ->where('id_services !=', $service_id)
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'));

        if ($exclude_hold_id) {
            $this->db->where('id !=', $exclude_hold_id);
        }

        $rows = $this->db->get('booking_holds')->result_array();

        return count($rows);
    }

    /**
     * Save a payment attempt.
     */
    public function insert_attempt(array $attempt): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->insert('payment_attempts', $attempt + [
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $this->db->insert_id();
    }

    /**
     * Save a payment attempt only once (the idempotency key is unique).
     *
     * Two clicks on "Continuar a Webpay" arrive at the same time: the second one must not create a second
     * transaction, or the client would be charged twice. The insert is done with ON DUPLICATE KEY UPDATE so the
     * database itself decides who wins (and no error is shown when the key already exists).
     *
     * @param array $attempt Payment attempt data.
     *
     * @return array {id: int, created: bool} The attempt and whether this call is the one that created it.
     */
    public function insert_attempt_once(array $attempt): array
    {
        $now = date('Y-m-d H:i:s');

        $attempt += [
            'create_datetime' => $now,
            'update_datetime' => $now,
        ];

        $fields = array_keys($attempt);

        $sql = 'INSERT INTO `' . $this->db->dbprefix('payment_attempts') . '` (`' .
            implode('`, `', $fields) . '`) VALUES (' .
            implode(', ', array_fill(0, count($fields), '?')) . ') ' .
            'ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)';

        $this->db->query($sql, array_values($attempt));

        $id = (int) $this->db->insert_id();

        if ($id <= 0) {
            return ['id' => 0, 'created' => false];
        }

        $existing = $this->find_attempt($id);

        // The row is ours only when the buy order is the one we just made.
        $created = $existing && (string) $existing['buy_order'] === (string) $attempt['buy_order'];

        return ['id' => $id, 'created' => $created];
    }

    public function find_attempt(int $id): ?array
    {
        return $this->cast_row($this->db->get_where('payment_attempts', ['id' => $id])->row_array());
    }

    public function find_attempt_by_token(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('payment_attempts', ['token' => $token])->row_array());
    }

    public function find_attempt_by_buy_order(string $buy_order): ?array
    {
        if ($buy_order === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('payment_attempts', ['buy_order' => $buy_order])->row_array());
    }

    public function find_attempt_by_idempotency(string $idempotency_key): ?array
    {
        if ($idempotency_key === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('payment_attempts', ['idempotency_key' => $idempotency_key])->row_array());
    }

    /**
     * The attempts of a hold, newest first.
     */
    public function attempts_of_hold(int $hold_id): array
    {
        $rows = $this->db
            ->where('id_booking_holds', $hold_id)
            ->order_by('attempt_no', 'DESC')
            ->get('payment_attempts')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Attempts that still need to be checked with the payment provider.
     */
    public function pending_attempts(int $limit = 50): array
    {
        $rows = $this->db
            ->where_in('status', [self::ATTEMPT_CREATED, self::ATTEMPT_UNKNOWN])
            ->where('create_datetime <', date('Y-m-d H:i:s', time() - 120))
            ->order_by('create_datetime', 'ASC')
            ->limit($limit)
            ->get('payment_attempts')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    public function update_attempt(int $id, array $data): void
    {
        $this->db->update(
            'payment_attempts',
            $data + ['update_datetime' => date('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    /**
     * Add an event to the outbox. The unique dedupe key makes it idempotent.
     *
     * @return int The event ID (0 when it already existed).
     */
    public function enqueue(string $event, string $dedupe_key, array $payload = [], array $links = []): int
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->get_where('outbox_events', ['dedupe_key' => $dedupe_key])->row_array();

        if ($existing) {
            return 0;
        }

        $this->db->insert('outbox_events', [
            'event' => $event,
            'dedupe_key' => $dedupe_key,
            'id_appointments' => $links['id_appointments'] ?? null,
            'id_booking_holds' => $links['id_booking_holds'] ?? null,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        $id = (int) $this->db->insert_id();
        $kind = ['appointment_paid' => 'created', 'appointment_moved' => 'moved', 'appointment_cancelled' => 'cancelled'][$event] ?? null;
        if ($kind && !empty($links['id_appointments'])) {
            $ci = &get_instance();
            if (!($ci->staff_push instanceof Staff_push)) { $ci->load->library('staff_push'); }
            $ci->staff_push->enqueue((int) $links['id_appointments'], $dedupe_key, $kind);
        }
        return $id;
    }

    /**
     * Events waiting to be delivered.
     */
    public function pending_events(int $limit = 20): array
    {
        $rows = $this->db
            ->where_in('status', ['pending', 'processing'])
            ->where('available_at <=', date('Y-m-d H:i:s'))
            ->order_by('available_at', 'ASC')
            ->limit($limit)
            ->get('outbox_events')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    public function find_event(int $id): ?array
    {
        return $this->cast_row($this->db->get_where('outbox_events', ['id' => $id])->row_array());
    }

    public function update_event(int $id, array $data): void
    {
        $this->db->update(
            'outbox_events',
            $data + ['update_datetime' => date('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    /**
     * Events that could not be delivered after several tries (visible for the team).
     */
    public function failed_events(int $limit = 50): array
    {
        $rows = $this->db
            ->where('status', 'failed')
            ->order_by('update_datetime', 'DESC')
            ->limit($limit)
            ->get('outbox_events')
            ->result_array();

        return array_map(fn($row) => $this->cast_row($row), $rows);
    }

    /**
     * Money already paid for an appointment (the ledger of the salon).
     */
    public function paid_total_for_appointment(int $appointment_id): float
    {
        $row = $this->db
            ->select('COALESCE(SUM(paid_amount), 0) AS paid')
            ->get_where('booking_holds', ['id_appointments' => $appointment_id])
            ->row_array();

        return (float) ($row['paid'] ?? 0);
    }

    protected function cast_row(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        foreach ($this->casts as $field => $type) {
            if (!array_key_exists($field, $row) || $row[$field] === null) {
                continue;
            }

            switch ($type) {
                case 'integer':
                    $row[$field] = (int) $row[$field];
                    break;

                case 'float':
                    $row[$field] = (float) $row[$field];
                    break;

                case 'boolean':
                    $row[$field] = (bool) $row[$field];
                    break;
            }
        }

        return $row;
    }
}
