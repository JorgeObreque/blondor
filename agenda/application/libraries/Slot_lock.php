<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Exclusión real de horarios
 *
 * Un horario se aparta con una fila de bloqueo por profesional y fecha (slot_locks) que se toma con SELECT ... FOR
 * UPDATE dentro de una transacción corta. Con esa fila tomada, dos procesos que CROMA al mismo horario se serializan:
 * el segundo espera y después ve la cita del primero.
 *
 * Leer las citas "vacías" con FOR UPDATE no alcanza: el bloqueo tiene que ser por profesional y fecha, y las
 * reservas pendientes de pago (holds) también cuentan como horario ocupado.
 *
 * Nunca se hace HTTP dentro de la transacción.
 * ---------------------------------------------------------------------------- */

/**
 * Slot lock library.
 *
 * @package Libraries
 */
class Slot_lock
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Slot_lock constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('appointments_model');
        $this->CI->load->model('blocked_periods_model');
        $this->CI->load->model('booking_payments_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('unavailabilities_model');

        $this->CI->load->library('availability');
    }

    /**
     * Run the callback inside a short transaction with the (provider, date) rows locked.
     *
     * The providers are locked in ascending order so two reschedules that cross each other never deadlock.
     *
     * @param array $provider_ids Providers to lock.
     * @param string $date Date of the slot (Y-m-d).
     * @param callable $callback What to do with the locked providers.
     *
     * @return mixed The result of the callback.
     *
     * @throws Throwable
     */
    public function locked(array $provider_ids, string $date, callable $callback)
    {
        return $this->locked_pairs(
            array_map(fn($provider_id) => [(int) $provider_id, $date], $provider_ids),
            $callback,
        );
    }

    /**
     * Run the callback inside a short transaction with the given (provider, date) rows locked.
     *
     * A reschedule takes two slots: the one it leaves and the one it takes. Both are locked, in a deterministic
     * order (date, then provider), so two reschedules that cross each other never deadlock.
     *
     * @param array $pairs Pairs [provider_id, date].
     * @param callable $callback What to do with the locked slots.
     *
     * @return mixed The result of the callback.
     *
     * @throws Throwable
     */
    public function locked_pairs(array $pairs, callable $callback)
    {
        $normalized = [];

        foreach ($pairs as $pair) {
            $provider_id = (int) ($pair[0] ?? 0);
            $date = (string) ($pair[1] ?? '');

            if ($provider_id <= 0 || $date === '') {
                continue;
            }

            $normalized[$provider_id . '|' . $date] = [$provider_id, $date];
        }

        // Deterministic order: no deadlocks between reschedules that cross each other.
        usort($normalized, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $pairs = array_values($normalized);

        $provider_ids = array_values(array_unique(array_column($pairs, 0)));

        $this->CI->db->trans_begin();

        try {
            $this->lock_rows($pairs);

            $result = $callback($provider_ids);

            if (!$this->CI->db->trans_status()) {
                throw new RuntimeException('No se pudo reservar el horario.');
            }

            $this->CI->db->trans_commit();

            return $result;
        } catch (Throwable $e) {
            $this->CI->db->trans_rollback();

            throw $e;
        }
    }

    /** Serialize every day touched by a write, including salon-wide blocks. */
    public function locked_interval(array $provider_ids, string $start, string $end, callable $callback)
    {
        $pairs = [];
        $date = new DateTime(substr($start, 0, 10));
        $last = new DateTime(substr($end, 0, 10));
        while ($date <= $last) {
            foreach ($provider_ids as $provider_id) {
                $pairs[] = [(int) $provider_id, $date->format('Y-m-d')];
            }
            $date->modify('+1 day');
        }
        return $this->locked_pairs($pairs, $callback);
    }

    /**
     * Take the lock rows of the given providers and dates.
     *
     * @param array $pairs Pairs [provider_id, date].
     */
    protected function lock_rows(array $pairs): void
    {
        $table = $this->CI->db->dbprefix('slot_locks');

        foreach ($pairs as [$provider_id, $date]) {
            $this->CI->db->query(
                "INSERT IGNORE INTO `$table` (`id_users_provider`, `lock_date`, `create_datetime`) VALUES (?, ?, ?)",
                [$provider_id, $date, date('Y-m-d H:i:s')],
            );
        }

        foreach ($pairs as [$provider_id, $date]) {
            $this->CI->db->query(
                "SELECT `id` FROM `$table` WHERE `id_users_provider` = ? AND `lock_date` = ? FOR UPDATE",
                [$provider_id, $date],
            );
        }
    }

    /**
     * Fail when a pending payment (a hold) is keeping the slot.
     *
     * The internal bookings (agenda, API and calendar) do not need a payment, but they do respect the slots that a
     * customer is paying right now.
     *
     * @param int $provider_id Provider that would take the slot.
     * @param string $start_datetime Slot start (Y-m-d H:i:s).
     * @param string $end_datetime Slot end (Y-m-d H:i:s).
     *
     * @throws RuntimeException
     */
    public function assert_no_pending_hold(
        int $provider_id,
        string $start_datetime,
        string $end_datetime,
    ): void {
        $holds = $this->CI->booking_payments_model->holds_overlapping(
            $provider_id,
            $start_datetime,
            $end_datetime,
        );

        if ($holds) {
            throw new RuntimeException(
                'Ese horario está apartado por una clienta que está pagando. Intenta en un momento.',
            );
        }
    }

    /**
     * Whether the slot can be taken, looking at the working plan, the appointments, the unavailabilities, the blocked
     * periods and the pending payments.
     *
     * The appointments, the unavailabilities and the holds are read with FOR UPDATE: they are "current reads", so a
     * transaction always sees what other transactions have already committed.
     *
     * @param int $provider_id Provider that would take the slot.
     * @param string $start_datetime Slot start (Y-m-d H:i:s).
     * @param string $end_datetime Slot end (Y-m-d H:i:s).
     * @param array $service Service data (duration, attendants_number…).
     * @param int|null $exclude_appointment_id Appointment that is being moved (it does not block itself).
     * @param int|null $exclude_hold_id Hold that is being confirmed (it does not block itself).
     *
     * @return array {free: bool, reason: string}
     */
    public function check_slot(
        int $provider_id,
        string $start_datetime,
        string $end_datetime,
        array $service,
        int $exclude_appointment_id = null,
        int $exclude_hold_id = null,
    ): array {
        $date = substr($start_datetime, 0, 10);

        // The service of the database (working plan rules) with the values of the caller (duration of the variant).
        $service_id = (int) ($service['id'] ?? 0);

        if ($service_id) {
            $service = array_merge($this->CI->services_model->find($service_id), $service);
        }

        // The working plan, the breaks and the blocked days.
        $provider = $this->CI->providers_model->find($provider_id);

        $available_hours = $this->CI->availability->get_available_hours(
            $date,
            $service,
            $provider,
            $exclude_appointment_id,
            $exclude_hold_id,
        );

        if (!in_array(date('H:i', strtotime($start_datetime)), $available_hours, true)) {
            return ['free' => false, 'reason' => 'outside_hours'];
        }

        // A pending payment holds the slot too.
        $holds = $this->CI->booking_payments_model->holds_overlapping(
            $provider_id,
            $start_datetime,
            $end_datetime,
            $exclude_hold_id,
        );

        foreach ($holds as $hold) {
            // A service with room for more attendants can share the hour with a hold of the same service.
            if ((int) ($service['attendants_number'] ?? 1) > 1 && (int) $hold['id_services'] === (int) $service['id']) {
                $same_service_holds = 1;

                foreach ($holds as $other) {
                    if ((int) $other['id_services'] === (int) $service['id']) {
                        $same_service_holds++;
                    }
                }

                if ($same_service_holds <= (int) $service['attendants_number']) {
                    continue;
                }
            }

            return ['free' => false, 'reason' => 'pending_payment'];
        }

        // Appointments that are not cancelled.
        $appointments = $this->CI->db->query(
            'SELECT `id`, `id_services`, `is_unavailability` FROM `' . $this->CI->db->dbprefix('appointments') . '`' .
            ' WHERE `id_users_provider` = ?' .
            ' AND `is_unavailability` = 0' .
            ' AND IFNULL(`status`, "") != "Cancelado"' .
            ' AND `start_datetime` < ? AND `end_datetime` > ?' .
            ($exclude_appointment_id ? ' AND `id` != ' . (int) $exclude_appointment_id : '') .
            ' FOR UPDATE',
            [$provider_id, $end_datetime, $start_datetime],
        )->result_array();

        $attendants_number = (int) ($service['attendants_number'] ?? 1);

        if ($attendants_number > 1) {
            $same_service = array_filter(
                $appointments,
                fn($row) => (int) $row['id_services'] === (int) $service['id'],
            );

            $other_service = array_filter(
                $appointments,
                fn($row) => (int) $row['id_services'] !== (int) $service['id'],
            );

            // Another service at the same time uses the professional: no room.
            if ($other_service) {
                return ['free' => false, 'reason' => 'booked'];
            }

            if (count($same_service) >= $attendants_number) {
                return ['free' => false, 'reason' => 'booked'];
            }
        } elseif ($appointments) {
            return ['free' => false, 'reason' => 'booked'];
        }

        // Unavailabilities (the "not available" blocks of the professional).
        $unavailabilities = $this->CI->db->query(
            'SELECT `id` FROM `' . $this->CI->db->dbprefix('appointments') . '`' .
            ' WHERE `id_users_provider` = ?' .
            ' AND `is_unavailability` = 1' .
            ' AND `start_datetime` < ? AND `end_datetime` > ?' .
            ' FOR UPDATE',
            [$provider_id, $end_datetime, $start_datetime],
        )->result_array();

        if ($unavailabilities) {
            return ['free' => false, 'reason' => 'unavailable'];
        }

        // Blocked periods (the salon closes that time).
        foreach ($this->CI->blocked_periods_model->get_for_period($date, $date) as $blocked_period) {
            if (
                $blocked_period['start_datetime'] < $end_datetime &&
                $blocked_period['end_datetime'] > $start_datetime
            ) {
                return ['free' => false, 'reason' => 'blocked'];
            }
        }

        return ['free' => true, 'reason' => ''];
    }
}
