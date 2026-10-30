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
    }

    /**
     * GET public_catalog: [{id, name, duration, price, price_type, variants: [{hair_length, label, price, duration}]}]
     * de los servicios reservables online.
     */
    public function index(): void
    {
        try {
            $origin = $this->input->get_request_header('Origin') ?? '';

            if (in_array($origin, self::ALLOWED_ORIGINS, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }

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
}
