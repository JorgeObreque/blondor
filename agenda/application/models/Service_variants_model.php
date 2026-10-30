<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Precios según el largo del pelo
 * ---------------------------------------------------------------------------- */

/**
 * Service variants model.
 *
 * Services whose price depends on the hair length have one variant per length, each with its price and optionally
 * its own duration (NULL: the service duration). A service with variants is always booked with a length, so the
 * client knows the exact price and the agenda blocks the right time.
 *
 * @package Models
 */
class Service_variants_model extends EA_Model
{
    /**
     * The lengths, shortest first, as the client sees them.
     */
    const LENGTHS = [
        'short' => ['label' => 'Corto', 'hint' => 'Hasta el hombro'],
        'medium' => ['label' => 'Mediano', 'hint' => 'Hasta el brasier'],
        'long' => ['label' => 'Largo', 'hint' => 'Hasta la cintura'],
        'xlong' => ['label' => 'Extra largo', 'hint' => 'Bajo la cintura'],
    ];

    private ?bool $ready = null;

    /**
     * Variants of the given services (all when null), keyed by service id and ordered by length.
     *
     * @return array [service_id => [{hair_length, label, hint, price, duration}]]
     */
    public function by_service(?array $service_ids = null): array
    {
        if (!$this->ready() || $service_ids === []) {
            return [];
        }

        if ($service_ids !== null) {
            $this->db->where_in('id_services', array_map('intval', $service_ids));
        }

        $grouped = [];

        foreach ($this->db->get('service_variants')->result_array() as $row) {
            if (isset(self::LENGTHS[$row['hair_length']])) {
                $grouped[(int) $row['id_services']][$row['hair_length']] = $this->card($row);
            }
        }

        // Shortest first, whatever the insertion order.
        return array_map(
            fn($variants) => array_values(array_filter(array_map(fn($key) => $variants[$key] ?? null, array_keys(self::LENGTHS)))),
            $grouped,
        );
    }

    /**
     * Variants of one service, shortest first.
     */
    public function of_service(int $service_id): array
    {
        return $this->by_service([$service_id])[$service_id] ?? [];
    }

    /**
     * The service as it is booked with that length: the duration of the variant. Services without variants ignore
     * the length; services with variants require a valid one.
     *
     * @throws InvalidArgumentException
     */
    public function apply(array $service, ?string $hair_length): array
    {
        $variants = $this->of_service((int) $service['id']);

        if (!$variants) {
            return $service + ['hair_length' => null];
        }

        foreach ($variants as $variant) {
            if ($variant['hair_length'] === $hair_length) {
                $service['duration'] = $variant['duration'] ?: $service['duration'];
                $service['price'] = $variant['price'];
                $service['hair_length'] = $hair_length;

                return $service;
            }
        }

        throw new InvalidArgumentException('Elige el largo del pelo para este servicio.');
    }

    /**
     * Label of a length: "Largo". Empty for no length.
     */
    public static function label(?string $hair_length): string
    {
        return self::LENGTHS[$hair_length]['label'] ?? '';
    }

    /**
     * Replace the variants of a service. An empty list removes them (the service goes back to a single price).
     *
     * @param array $variants [{hair_length, price, duration?}]
     *
     * @return array Price changes: [{hair_length, old_price, new_price}] (old_price NULL for a new length).
     */
    public function replace(int $service_id, array $variants): array
    {
        $now = date('Y-m-d H:i:s');
        $current = [];

        foreach ($this->db->get_where('service_variants', ['id_services' => $service_id])->result_array() as $row) {
            $current[$row['hair_length']] = $row;
        }

        $wanted = [];

        foreach ($variants as $variant) {
            $length = (string) ($variant['hair_length'] ?? '');

            if (!isset(self::LENGTHS[$length])) {
                throw new InvalidArgumentException('Largo no válido: ' . $length);
            }

            if (isset($wanted[$length])) {
                throw new InvalidArgumentException('El largo ' . self::label($length) . ' está repetido.');
            }

            $price = is_numeric($variant['price'] ?? null) ? round((float) $variant['price']) : 0;

            if ($price <= 0 || $price > 10000000) {
                throw new InvalidArgumentException('Ponle precio al largo ' . self::label($length) . '.');
            }

            $duration = !empty($variant['duration']) ? (int) $variant['duration'] : null;

            if ($duration !== null && ($duration < EVENT_MINIMUM_DURATION || $duration > 600)) {
                throw new InvalidArgumentException(
                    'La duración debe estar entre ' . EVENT_MINIMUM_DURATION . ' y 600 minutos.',
                );
            }

            $wanted[$length] = ['price' => $price, 'duration' => $duration];
        }

        if (count($wanted) === 1) {
            throw new InvalidArgumentException('Con un solo largo, usa precio único.');
        }

        $changes = [];

        foreach ($wanted as $length => $values) {
            $old = $current[$length] ?? null;

            if (!$old) {
                $this->db->insert('service_variants', $values + [
                    'id_services' => $service_id,
                    'hair_length' => $length,
                    'create_datetime' => $now,
                    'update_datetime' => $now,
                ]);
            } elseif ((float) $old['price'] !== $values['price'] || ($old['duration'] ? (int) $old['duration'] : null) !== $values['duration']) {
                $this->db->update('service_variants', $values + ['update_datetime' => $now], ['id' => $old['id']]);
            }

            if (!$old || (float) $old['price'] !== $values['price']) {
                $changes[] = [
                    'hair_length' => $length,
                    'old_price' => $old ? (float) $old['price'] : null,
                    'new_price' => $values['price'],
                ];
            }
        }

        $removed = array_diff(array_keys($current), array_keys($wanted));

        if ($removed) {
            $this->db->where('id_services', $service_id)->where_in('hair_length', $removed)->delete('service_variants');
        }

        return $changes;
    }

    private function ready(): bool
    {
        // Until the migration runs in an install, everything behaves as before.
        return $this->ready ??= $this->db->table_exists('service_variants');
    }

    private function card(array $row): array
    {
        return [
            'hair_length' => $row['hair_length'],
            'label' => self::LENGTHS[$row['hair_length']]['label'],
            'hint' => self::LENGTHS[$row['hair_length']]['hint'],
            'price' => (float) $row['price'],
            'duration' => $row['duration'] ? (int) $row['duration'] : null,
        ];
    }
}
