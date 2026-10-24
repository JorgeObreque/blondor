<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Perfil de clienta
 * ---------------------------------------------------------------------------- */

/**
 * Customer records model.
 *
 * Fixed customer data (alerts, hair, preferences), the technical record of each visit and its photos.
 *
 * @package Models
 */
class Customer_records_model extends EA_Model
{
    /**
     * Fields of a visit record the agenda can write.
     */
    const RECORD_FIELDS = ['work', 'products', 'result', 'home_care'];

    /**
     * Get the fixed data of a customer (empty values when she has none yet).
     */
    public function get_profile(int $customer_id): array
    {
        $row = $this->db->get_where('customer_profiles', ['id_users' => $customer_id])->row_array();

        return [
            'alerts' => $row['alerts'] ?? '',
            'hair' => json_decode($row['hair'] ?? '', true) ?: new stdClass(),
            'preferences' => $row['preferences'] ?? '',
            'update_datetime' => $row['update_datetime'] ?? null,
        ];
    }

    public function save_profile(int $customer_id, array $data, int $author_id): void
    {
        $row = [
            'id_users' => $customer_id,
            'alerts' => trim((string) ($data['alerts'] ?? '')),
            'hair' => json_encode($this->clean_values($data['hair'] ?? []), JSON_UNESCAPED_UNICODE),
            'preferences' => trim((string) ($data['preferences'] ?? '')),
            'update_datetime' => date('Y-m-d H:i:s'),
            'id_users_author' => $author_id,
        ];

        $this->db->replace('customer_profiles', $row);
    }

    /**
     * Alerts of several customers, keyed by customer id (only customers that have one).
     */
    public function get_alerts(array $customer_ids): array
    {
        $customer_ids = array_values(array_unique(array_filter(array_map('intval', $customer_ids))));

        if (!$customer_ids) {
            return [];
        }

        $alerts = [];

        $rows = $this->db
            ->select('id_users, alerts')
            ->from('customer_profiles')
            ->where_in('id_users', $customer_ids)
            ->where('alerts !=', '')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $alerts[(int) $row['id_users']] = $row['alerts'];
        }

        return $alerts;
    }

    /**
     * Appointment ids (from the given ones) that already have a visit record.
     */
    public function appointments_with_record(array $appointment_ids): array
    {
        $appointment_ids = array_values(array_filter(array_map('intval', $appointment_ids)));

        if (!$appointment_ids) {
            return [];
        }

        $rows = $this->db
            ->select('id_appointments')
            ->from('visit_records')
            ->where_in('id_appointments', $appointment_ids)
            ->get()
            ->result_array();

        return array_map(fn($row) => (int) $row['id_appointments'], $rows);
    }

    /**
     * Every visit record of a customer, newest first, with its photos.
     */
    public function get_records(int $customer_id): array
    {
        $rows = $this->db
            ->select('r.*, s.name AS service_name, p.first_name AS provider_first_name')
            ->from('visit_records r')
            ->join('services s', 's.id = r.id_services', 'left')
            ->join('users p', 'p.id = r.id_users_provider', 'left')
            ->where('r.id_users_customer', $customer_id)
            ->order_by('r.visit_date DESC, r.id DESC')
            ->get()
            ->result_array();

        return $this->with_photos(array_map([$this, 'record_row'], $rows));
    }

    public function find_record(int $record_id): ?array
    {
        $row = $this->db->get_where('visit_records', ['id' => $record_id])->row_array();

        return $row ? $this->with_photos([$this->record_row($row)])[0] : null;
    }

    public function find_record_by_appointment(int $appointment_id): ?array
    {
        $row = $this->db->get_where('visit_records', ['id_appointments' => $appointment_id])->row_array();

        return $row ? $this->with_photos([$this->record_row($row)])[0] : null;
    }

    /**
     * Latest values of each service group (color, corte...) in earlier records, for "Repetir última".
     */
    public function previous_by_group(int $customer_id, ?int $exclude_record_id): array
    {
        $rows = $this->db
            ->select('id, visit_date, details')
            ->from('visit_records')
            ->where('id_users_customer', $customer_id)
            ->where('id !=', $exclude_record_id ?? 0)
            ->order_by('visit_date DESC, id DESC')
            ->limit(50)
            ->get()
            ->result_array();

        $previous = [];

        foreach ($rows as $row) {
            $details = json_decode((string) $row['details'], true) ?: [];

            foreach ($details as $group => $values) {
                if (!isset($previous[$group]) && is_array($values) && array_filter($values)) {
                    $previous[$group] = ['date' => $row['visit_date'], 'values' => $values];
                }
            }
        }

        return $previous;
    }

    /**
     * Insert or update a visit record and return its id.
     */
    public function save_record(array $record, int $author_id): int
    {
        $row = [
            'id_users_customer' => (int) $record['customer_id'],
            'id_appointments' => $record['appointment_id'] ?: null,
            'id_users_provider' => $record['provider_id'] ?: null,
            'id_services' => $record['service_id'] ?: null,
            'visit_date' => $record['visit_date'],
            'details' => json_encode($this->clean_details($record['details'] ?? []), JSON_UNESCAPED_UNICODE),
            'next_visit_weeks' => (int) ($record['next_visit_weeks'] ?? 0) ?: null,
            'update_datetime' => date('Y-m-d H:i:s'),
            'id_users_author' => $author_id,
        ];

        foreach (self::RECORD_FIELDS as $field) {
            $row[$field] = trim((string) ($record[$field] ?? ''));
        }

        if (!empty($record['id'])) {
            $this->db->update('visit_records', $row, ['id' => (int) $record['id']]);

            return (int) $record['id'];
        }

        $row['create_datetime'] = $row['update_datetime'];
        $this->db->insert('visit_records', $row);

        return (int) $this->db->insert_id();
    }

    public function add_photo(int $record_id, string $kind, string $file): int
    {
        $this->db->insert('visit_photos', [
            'id_visit_records' => $record_id,
            'kind' => $kind === 'antes' ? 'antes' : 'despues',
            'file' => $file,
            'create_datetime' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insert_id();
    }

    /**
     * A photo with the customer it belongs to.
     */
    public function find_photo(int $photo_id): ?array
    {
        return $this->db
            ->select('ph.*, r.id_users_customer')
            ->from('visit_photos ph')
            ->join('visit_records r', 'r.id = ph.id_visit_records')
            ->where('ph.id', $photo_id)
            ->get()
            ->row_array() ?: null;
    }

    public function count_photos(int $record_id): int
    {
        return $this->db->where('id_visit_records', $record_id)->count_all_results('visit_photos');
    }

    public function delete_photo(int $photo_id): void
    {
        $this->db->delete('visit_photos', ['id' => $photo_id]);
    }

    private function record_row(array $row): array
    {
        $record = [
            'id' => (int) $row['id'],
            'customer_id' => (int) $row['id_users_customer'],
            'appointment_id' => $row['id_appointments'] ? (int) $row['id_appointments'] : null,
            'provider_id' => $row['id_users_provider'] ? (int) $row['id_users_provider'] : null,
            'service_id' => $row['id_services'] ? (int) $row['id_services'] : null,
            'visit_date' => $row['visit_date'],
            'details' => json_decode((string) $row['details'], true) ?: new stdClass(),
            'next_visit_weeks' => $row['next_visit_weeks'] ? (int) $row['next_visit_weeks'] : null,
            'update_datetime' => $row['update_datetime'],
        ];

        foreach (self::RECORD_FIELDS as $field) {
            $record[$field] = $row[$field] ?? '';
        }

        if (array_key_exists('service_name', $row)) {
            $record['service_name'] = $row['service_name'];
            $record['provider_name'] = $row['provider_first_name'];
        }

        return $record;
    }

    private function with_photos(array $records): array
    {
        if (!$records) {
            return $records;
        }

        $photos = [];

        $rows = $this->db
            ->select('id, id_visit_records, kind')
            ->from('visit_photos')
            ->where_in('id_visit_records', array_column($records, 'id'))
            ->order_by('kind ASC, id ASC')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $photos[(int) $row['id_visit_records']][] = ['id' => (int) $row['id'], 'kind' => $row['kind']];
        }

        foreach ($records as &$record) {
            $record['photos'] = $photos[$record['id']] ?? [];
        }

        return $records;
    }

    /**
     * Keep only {group: {field: text}} with short, trimmed strings (the form defines which groups and fields exist).
     */
    private function clean_details($details): array
    {
        $clean = [];

        foreach ((array) $details as $group => $values) {
            if (!preg_match('/^[a-z_]{1,32}$/', (string) $group) || !is_array($values)) {
                continue;
            }

            $values = $this->clean_values($values);

            if ($values) {
                $clean[$group] = $values;
            }
        }

        return $clean;
    }

    private function clean_values($values): array
    {
        $clean = [];

        foreach ((array) $values as $key => $value) {
            if (!preg_match('/^[a-z_]{1,32}$/', (string) $key) || is_array($value)) {
                continue;
            }

            $value = trim(mb_substr((string) $value, 0, 2000));

            if ($value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
