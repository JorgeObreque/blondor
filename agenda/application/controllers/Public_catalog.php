<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Catálogo público
 *
 * Los servicios que se pueden reservar online, con duración y precio (y el precio de cada largo en los que se cobran
 * según el largo), para el sitio blondor.cl. Así los precios del sitio son los mismos de la agenda: cambiarlos en
 * /precios actualiza el sitio.
 * ---------------------------------------------------------------------------- */

class Public_catalog extends EA_Controller
{
    /**
     * Sitios que pueden leer el catálogo desde el navegador.
     */
    const ALLOWED_ORIGINS = ['https://blondor.cl', 'https://www.blondor.cl', 'http://localhost:8087'];

    public function __construct()
    {
        parent::__construct();

        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('service_variants_model');

        $this->load->library('availability');
    }

    /**
     * GET public_catalog: [{id, name, duration, price, price_type, is_promo, promo_weekdays, promo_daily_quota,
     * variants: [{hair_length, label, price, duration}]}]
     * de los servicios reservables online.
     */
    public function index(): void
    {
        try {
            $this->allow_site();

            header('Cache-Control: public, max-age=300');

            // Igual que la reserva: solo servicios públicos que alguna profesional pública atiende.
            $bookable = [];

            foreach ($this->providers_model->get_available_providers(true) as $provider) {
                foreach ($provider['services'] as $service_id) {
                    $bookable[(int) $service_id] = true;
                }
            }

            $services = [];
            $variants = $this->service_variants_model->by_service(array_keys($bookable));

            foreach ($this->services_model->get_available_services(true) as $service) {
                if (empty($bookable[(int) $service['id']])) {
                    continue;
                }

                $services[] = [
                    'id' => (int) $service['id'],
                    'name' => $service['name'],
                    'duration' => (int) $service['duration'],
                    'price' => (float) $service['price'],
                    'price_type' => $service['price_type'] ?? 'fixed',
                    // Blondor: promoción con días y cupos (solo informativo; la agenda aplica las reglas).
                    'is_promo' => (bool) ($service['is_promo'] ?? false),
                    'promo_weekdays' => !empty($service['is_promo'])
                        ? $this->availability->promo_rules($service)['weekdays']
                        : [],
                    'promo_daily_quota' => !empty($service['is_promo'])
                        ? $this->availability->promo_rules($service)['daily_quota']
                        : null,
                    'variants' => array_map(
                        fn($variant) => [
                            'hair_length' => $variant['hair_length'],
                            'label' => $variant['label'],
                            'price' => $variant['price'],
                            'duration' => $variant['duration'] ?: (int) $service['duration'],
                        ],
                        $variants[(int) $service['id']] ?? [],
                    ),
                ];
            }

            json_response($services);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * GET public_catalog/next_slots?service=62: [{date: "2026-11-20", hours: ["11:00", "16:30"]}]
     *
     * Las primeras horas libres de un servicio público (hasta 3 días con horas, la primera y la última de cada día, buscando 14 días),
     * para que la landing de una promoción las muestre. Son las mismas reglas de la reserva online (promo, anticipación,
     * bloqueos); la reserva vuelve a comprobar la hora al confirmar.
     */
    public function next_slots(): void
    {
        try {
            $this->allow_site();

            header('Cache-Control: public, max-age=60');

            $service_id = (int) $this->input->get('service');
            $service = null;

            foreach ($this->services_model->get_available_services(true) as $candidate) {
                if ((int) $candidate['id'] === $service_id) {
                    $service = $candidate;
                }
            }

            $providers = array_filter(
                $this->providers_model->get_available_providers(true),
                fn($provider) => in_array($service_id, array_map('intval', $provider['services']), true),
            );

            $days = [];

            if ($service && $providers) {
                $date = new DateTime('today');

                for ($i = 0; $i < 14 && count($days) < 3; $i++, $date->modify('+1 day')) {
                    $hours = [];

                    foreach ($providers as $provider) {
                        $hours = array_merge(
                            $hours,
                            $this->availability->get_available_hours($date->format('Y-m-d'), $service, $provider),
                        );
                    }

                    $hours = array_values(array_unique($hours));
                    sort($hours);

                    if ($hours) {
                        // La primera y la última del día: quien trabaja suele buscar la de la tarde.
                        $days[] = [
                            'date' => $date->format('Y-m-d'),
                            'hours' => array_values(array_unique([$hours[0], $hours[count($hours) - 1]])),
                        ];
                    }
                }
            }

            json_response($days);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Deja leer la respuesta desde blondor.cl (y desde el sitio local, también por la IP de la red para el celular).
     */
    protected function allow_site(): void
    {
        $origin = $this->input->get_request_header('Origin') ?? '';
        $local = preg_match('#^http://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}):8087$#', $origin);

        if ($local || in_array($origin, self::ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
}
