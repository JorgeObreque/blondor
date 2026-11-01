<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Recorrido de la reserva
 *
 * 1. track(): el sitio y la reserva anotan hasta qué paso llegó cada visita (sin datos personales).
 * 2. report(): por origen (?ref=), cuántas visitas llegaron a cada paso y cuántas pagaron.
 * 3. abandoned(): las clientas que llegaron a pagar y no pagaron, para escribirles. Se marca si después reservaron
 *    igual (por correo o teléfono).
 *
 * Publicador lee 2 y 3 por la API (api/v1/booking_funnel y api/v1/booking_abandoned).
 * ---------------------------------------------------------------------------- */

class Booking_funnel
{
    /**
     * Los pasos, en orden: blondor.cl, la reserva abierta, servicio elegido, hora elegida, datos enviados (la hora
     * quedó apartada) y salida a Webpay.
     */
    const STEPS = ['site', 'agenda', 'service', 'time', 'details', 'webpay'];

    const SESSION_PATTERN = '/^[a-z0-9]{16,40}$/D';

    const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9:_-]{1,47}$/D';

    /**
     * @var EA_Controller|CI_Controller
     */
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * Anota un paso. Devuelve false si el evento no es válido (se ignora sin error). Cada visita cuenta una vez por
     * paso.
     */
    public function track(array $event): bool
    {
        $session_id = is_string($event['session'] ?? null) ? strtolower(trim($event['session'])) : '';
        $step = is_string($event['step'] ?? null) ? $event['step'] : '';

        if (!preg_match(self::SESSION_PATTERN, $session_id) || !in_array($step, self::STEPS, true)) {
            return false;
        }

        $code = null;

        if (is_string($event['ref'] ?? null) && $event['ref'] !== '') {
            $code = strtoupper(trim($event['ref']));

            if (!preg_match(self::CODE_PATTERN, $code)) {
                $code = null;
            }
        }

        $service_id = filter_var($event['service'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $table = $this->CI->db->dbprefix('booking_funnel_events');

        $this->CI->db->query(
            "INSERT IGNORE INTO `$table` (session_id, step, attribution_code, id_services, create_datetime)" .
            ' VALUES (?, ?, ?, ?, ?)',
            [$session_id, $step, $code, $service_id ?: null, date('Y-m-d H:i:s')],
        );

        return true;
    }

    /**
     * Por origen: visitas en cada paso y reservas pagadas (con lo cobrado) entre $from y $till (fechas Y-m-d,
     * incluidas). El origen vacío ('') son las visitas sin ?ref.
     *
     * @return array [{attribution_code, steps: {site: n, …}, paid: n, paid_amount: float}]
     */
    public function report(string $from, string $till): array
    {
        [$start, $end] = $this->period($from, $till);

        $events = $this->CI->db->dbprefix('booking_funnel_events');
        $holds = $this->CI->db->dbprefix('booking_holds');

        $rows = [];

        $blank = fn() => ['steps' => array_fill_keys(self::STEPS, 0), 'paid' => 0, 'paid_amount' => 0.0];

        $steps = $this->CI->db
            ->query(
                "SELECT COALESCE(attribution_code, '') AS code, step, COUNT(DISTINCT session_id) AS visits" .
                " FROM `$events` WHERE create_datetime >= ? AND create_datetime < ? GROUP BY code, step",
                [$start, $end],
            )
            ->result_array();

        foreach ($steps as $row) {
            $rows[$row['code']] ??= $blank();
            $rows[$row['code']]['steps'][$row['step']] = (int) $row['visits'];
        }

        // Pagadas: reservas nuevas (no reagendas) cobradas por Webpay.
        $paid = $this->CI->db
            ->query(
                "SELECT COALESCE(h.attribution_code, '') AS code, COUNT(*) AS paid, SUM(h.paid_amount) AS amount" .
                " FROM `$holds` h WHERE h.status = 'paid' AND h.paid_amount > 0 AND h.id_appointments IS NOT NULL" .
                ' AND h.create_datetime >= ? AND h.create_datetime < ?' .
                // La primera reserva pagada de la cita; las reagendas posteriores no son ventas nuevas.
                " AND NOT EXISTS (SELECT 1 FROM `$holds` o WHERE o.id_appointments = h.id_appointments" .
                " AND o.id < h.id AND o.status = 'paid')" .
                ' GROUP BY code',
                [$start, $end],
            )
            ->result_array();

        foreach ($paid as $row) {
            $rows[$row['code']] ??= $blank();
            $rows[$row['code']]['paid'] = (int) $row['paid'];
            $rows[$row['code']]['paid_amount'] = (float) $row['amount'];
        }

        ksort($rows);

        $result = [];

        foreach ($rows as $code => $row) {
            $result[] = ['attribution_code' => $code === '' ? null : (string) $code] + $row;
        }

        return $result;
    }

    /**
     * Reservas nuevas que apartaron hora y no se pagaron (expiraron, la clienta se arrepintió o Webpay rechazó)
     * entre $from y $till. "recovered" dice si esa clienta tiene una cita reservada después.
     */
    public function abandoned(string $from, string $till): array
    {
        [$start, $end] = $this->period($from, $till);

        $holds = $this->CI->db->dbprefix('booking_holds');
        $attempts = $this->CI->db->dbprefix('payment_attempts');

        $rows = $this->CI->db
            ->query(
                'SELECT h.id, h.status, h.id_services, h.service_name, h.hair_length, h.start_datetime,' .
                ' h.total_amount, h.deposit_amount, h.customer_snapshot, h.attribution_code, h.create_datetime,' .
                " (SELECT p.status FROM `$attempts` p WHERE p.id_booking_holds = h.id ORDER BY p.id DESC LIMIT 1)" .
                ' AS last_attempt' .
                " FROM `$holds` h WHERE h.status IN ('released', 'expired') AND h.id_appointments IS NULL" .
                ' AND h.create_datetime >= ? AND h.create_datetime < ? ORDER BY h.create_datetime DESC',
                [$start, $end],
            )
            ->result_array();

        $result = [];

        foreach ($rows as $row) {
            $customer = json_decode((string) $row['customer_snapshot'], true) ?: [];
            $email = (string) ($customer['email'] ?? '');
            $phone = (string) ($customer['phone_number'] ?? '');

            $result[] = [
                'id' => (int) $row['id'],
                'created' => $row['create_datetime'],
                'attribution_code' => $row['attribution_code'],
                'reason' => $this->reason($row['status'], $row['last_attempt']),
                'service_id' => (int) $row['id_services'],
                'service_name' => $row['service_name'],
                'hair_length' => $row['hair_length'],
                'start' => $row['start_datetime'],
                'total_amount' => $row['total_amount'] === null ? null : (float) $row['total_amount'],
                'deposit_amount' => (float) $row['deposit_amount'],
                'first_name' => (string) ($customer['first_name'] ?? ''),
                'last_name' => (string) ($customer['last_name'] ?? ''),
                'email' => $email,
                'phone' => $phone,
                'recovered' => $this->booked_since($email, $phone, $row['create_datetime']),
            ];
        }

        return $result;
    }

    /**
     * expired_unpaid (no llegó a pagar a tiempo), left_webpay (salió de Webpay), rejected (Webpay rechazó) o
     * gave_up (la clienta canceló la reserva antes de pagar).
     */
    protected function reason(string $status, ?string $last_attempt): string
    {
        if ($last_attempt === 'rejected' || $last_attempt === 'failed') {
            return 'rejected';
        }

        if ($last_attempt === 'aborted') {
            return 'left_webpay';
        }

        return $status === 'released' ? 'gave_up' : 'expired_unpaid';
    }

    /**
     * Si la clienta (mismo correo o mismos 9 dígitos de teléfono) tiene una cita reservada desde $since.
     */
    protected function booked_since(string $email, string $phone, string $since): bool
    {
        $digits = substr(preg_replace('/\D/', '', $phone), -9);

        if ($email === '' && strlen($digits) !== 9) {
            return false;
        }

        $appointments = $this->CI->db->dbprefix('appointments');
        $users = $this->CI->db->dbprefix('users');

        $conditions = [];
        $params = [$since];

        if ($email !== '') {
            $conditions[] = 'LOWER(u.email) = ?';
            $params[] = mb_strtolower($email);
        }

        if (strlen($digits) === 9) {
            $conditions[] = "RIGHT(REGEXP_REPLACE(COALESCE(u.phone_number, ''), '[^0-9]', ''), 9) = ?";
            $params[] = $digits;
        }

        return (bool) $this->CI->db
            ->query(
                "SELECT 1 FROM `$appointments` a INNER JOIN `$users` u ON u.id = a.id_users_customer" .
                ' WHERE a.book_datetime >= ? AND (' . implode(' OR ', $conditions) . ') LIMIT 1',
                $params,
            )
            ->num_rows();
    }

    /**
     * [inicio, fin) en hora local de la agenda. Sin fechas válidas, los últimos 30 días.
     */
    protected function period(string $from, string $till): array
    {
        $valid = fn($date) => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && strtotime($date) !== false;

        $till = $valid($till) ? $till : date('Y-m-d');
        $from = $valid($from) ? $from : date('Y-m-d', strtotime($till . ' -29 days'));

        return [$from . ' 00:00:00', date('Y-m-d', strtotime($till . ' +1 day')) . ' 00:00:00'];
    }
}
