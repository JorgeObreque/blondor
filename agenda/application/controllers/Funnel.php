<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Recorrido de la reserva (público)
 *
 * POST funnel/track con {session, step, ref, service}. Lo llaman blondor.cl y la reserva con navigator.sendBeacon
 * (texto plano, sin cookies de la agenda ni preflight). Responde siempre 204: un evento inválido se descarta en
 * silencio y nunca afecta a la reserva.
 * ---------------------------------------------------------------------------- */

class Funnel extends EA_Controller
{
    /**
     * Un evento es pequeño: más que esto no es nuestro.
     */
    const MAX_BODY = 1024;

    public function __construct()
    {
        parent::__construct();

        $this->load->library('booking_funnel');
    }

    public function track(): void
    {
        try {
            if ($this->input->method() === 'post') {
                $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);

                $event = strlen($body) <= self::MAX_BODY ? json_decode($body, true) : null;

                if (is_array($event)) {
                    $this->booking_funnel->track($event);
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'Funnel track: ' . $e->getMessage());
        }

        $this->output->set_status_header(204);
    }
}
