<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Servicios y precios
 * ---------------------------------------------------------------------------- */

/**
 * Service catalog model.
 *
 * The service list with its usage (to order and clean the catalog) and the quick edits of the mobile price page:
 * price, price type, duration, category and hidden services. Every price change is logged and also applies to the
 * upcoming appointments of that service; past appointments keep the price they had.
 *
 * @package Models
 */
class Service_catalog_model extends EA_Model
{
    const PRICE_TYPES = ['fixed', 'from'];

    /**
     * Months that count as "recent use".
     */
    const RECENT_MONTHS = 6;

    /**
     * Every service (hidden ones too) with its usage and how many providers offer it.
     */
    public function get_catalog(): array
    {
        $now = date('Y-m-d H:i:s');
        $recent = date('Y-m-d H:i:s', strtotime('-' . self::RECENT_MONTHS . ' months'));

        $usage = [];

        $rows = $this->db
            ->select(
                'id_services,
                SUM(start_datetime < ' . $this->db->escape($now) . ') AS done,
                SUM(start_datetime >= ' . $this->db->escape($recent) . ' AND start_datetime < ' . $this->db->escape($now) . ') AS recent,
                SUM(start_datetime >= ' . $this->db->escape($now) . ') AS upcoming,
                MAX(CASE WHEN start_datetime < ' . $this->db->escape($now) . ' THEN start_datetime END) AS last_used',
                false,
            )
            ->from('appointments')
            ->where('is_unavailability', false)
            ->where('IFNULL(status, "") != "Cancelado"', null, false)
            ->where('id_services IS NOT NULL', null, false)
            ->group_by('id_services')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $usage[(int) $row['id_services']] = $row;
        }

        $providers = [];

        foreach ($this->db->select('id_services, COUNT(*) AS total')->group_by('id_services')->get('services_providers')->result_array() as $row) {
            $providers[(int) $row['id_services']] = (int) $row['total'];
        }

        $services = $this->db->order_by('name ASC')->get('services')->result_array();

        return array_map(function ($service) use ($usage, $providers) {
            $id = (int) $service['id'];
            $use = $usage[$id] ?? [];

            return $this->card($service) + [
                'uses' => (int) ($use['done'] ?? 0),
                'recent_uses' => (int) ($use['recent'] ?? 0),
                'upcoming' => (int) ($use['upcoming'] ?? 0),
                'last_used' => $use['last_used'] ?? null,
                'providers' => $providers[$id] ?? 0,
            ];
        }, $services);
    }

    /**
     * Apply several quick edits in one transaction. Each change has the service id and only the fields to change.
     *
     * @return array The saved services, with their previous values (what "Deshacer" sends back).
     */
    public function save_changes(array $changes, int $author_id): array
    {
        $category_ids = array_map('intval', array_column($this->db->select('id')->get('service_categories')->result_array(), 'id'));
        $now = date('Y-m-d H:i:s');
        $saved = [];

        $this->db->trans_start();

        foreach ($changes as $change) {
            $service = $this->db->get_where('services', ['id' => (int) ($change['id'] ?? 0)])->row_array();

            if (!$service) {
                throw new InvalidArgumentException('El servicio no existe: ' . ($change['id'] ?? ''));
            }

            $update = [];

            if (array_key_exists('price', $change)) {
                $price = $this->valid_price($change['price']);

                if ($price !== (float) $service['price']) {
                    $update['price'] = $price;
                }
            }

            if (array_key_exists('price_type', $change)) {
                if (!in_array($change['price_type'], self::PRICE_TYPES, true)) {
                    throw new InvalidArgumentException('Tipo de precio no válido.');
                }

                $update['price_type'] = $change['price_type'];
            }

            if (array_key_exists('duration', $change)) {
                $duration = (int) $change['duration'];

                if ($duration < EVENT_MINIMUM_DURATION || $duration > 600) {
                    throw new InvalidArgumentException('La duración debe estar entre ' . EVENT_MINIMUM_DURATION . ' y 600 minutos.');
                }

                $update['duration'] = $duration;
            }

            if (array_key_exists('category_id', $change)) {
                $category_id = $change['category_id'] ? (int) $change['category_id'] : null;

                if ($category_id && !in_array($category_id, $category_ids, true)) {
                    throw new InvalidArgumentException('La categoría no existe.');
                }

                $update['id_service_categories'] = $category_id;
            }

            if (array_key_exists('is_archived', $change)) {
                $update['is_archived'] = filter_var($change['is_archived'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            if ($update) {
                $update['update_datetime'] = $now;
                $this->db->update('services', $update, ['id' => $service['id']]);
            }

            if (isset($update['price'])) {
                $this->db->insert('service_price_log', [
                    'id_services' => $service['id'],
                    'old_price' => $service['price'],
                    'new_price' => $update['price'],
                    'id_users_author' => $author_id,
                    'create_datetime' => $now,
                ]);

                // Las citas que vienen se cobran al precio nuevo; las pasadas conservan el suyo.
                $this->db
                    ->where('id_services', $service['id'])
                    ->where('is_unavailability', false)
                    ->where('start_datetime >=', $now)
                    ->update('appointments', ['price' => $update['price'] > 0 ? $update['price'] : null]);
            }

            $saved[] = [
                'service' => $this->card($this->db->get_where('services', ['id' => $service['id']])->row_array()),
                'previous' => $this->card($service),
            ];
        }

        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            throw new RuntimeException('No se pudieron guardar los cambios.');
        }

        return $saved;
    }

    private function valid_price($value): float
    {
        $price = is_numeric($value) ? round((float) $value) : null;

        if ($price === null || $price < 0 || $price > 10000000) {
            throw new InvalidArgumentException('Precio no válido.');
        }

        return $price;
    }

    private function card(array $service): array
    {
        return [
            'id' => (int) $service['id'],
            'name' => trim($service['name']),
            'price' => (float) $service['price'],
            'price_type' => $service['price_type'] ?? 'fixed',
            'duration' => (int) $service['duration'],
            'category_id' => $service['id_service_categories'] ? (int) $service['id_service_categories'] : null,
            'is_archived' => (bool) ($service['is_archived'] ?? false),
        ];
    }
}
