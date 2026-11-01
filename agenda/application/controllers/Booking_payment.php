<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Reserva pública con pago (Webpay)
 *
 * Rutas:
 *   booking_payment/prepare   (POST, con CSRF) Valida los datos, aparta el horario y devuelve las opciones de pago.
 *   booking_payment/checkout  (POST, con CSRF) Crea el intento de pago y devuelve el formulario de Webpay.
 *   booking_payment/confirm   (POST, con CSRF) Confirma una reagenda que no necesita cobro.
 *   booking_payment/release   (POST, con CSRF) Suelta una reserva que la clienta dejó a medias.
 *   booking_payment/result    (GET)            Estado del pago (pagado, rechazado, pendiente o en revisión).
 *
 * La vuelta de Webpay entra por booking/webpay_return (ruta sin CSRF, como la de las gift cards).
 * ---------------------------------------------------------------------------- */

/**
 * Booking payment controller.
 *
 * @package Controllers
 */
class Booking_payment extends EA_Controller
{
    /**
     * Booking_payment constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('booking_payments_model');
        $this->load->model('services_model');
        $this->load->model('service_variants_model');

        $this->load->library('booking_payments');
        $this->load->library('deposit_policy');
        $this->load->library('webpay');

        if (in_array($this->router->method, ['prepare', 'checkout', 'confirm', 'release'], true)
            && $this->input->method() !== 'post') {
            abort(405);
        }
    }

    /**
     * Prepare the booking: validate, hold the slot and calculate the payment options.
     */
    public function prepare(): void
    {
        try {
            if (setting('disable_booking')) {
                abort(403);
            }

            if (!$this->webpay->is_enabled()) {
                throw new RuntimeException('El pago en línea no está disponible en este momento.');
            }

            $post_data = request('post_data');
            $captcha = (string) request('captcha');

            if (!is_array($post_data)) {
                throw new InvalidArgumentException('La reserva no está completa.');
            }

            if (!$this->captcha_is_valid($captcha)) {
                json_response(['captcha_verification' => false]);

                return;
            }

            $customer = $post_data['customer'] ?? [];

            $state = $this->booking_payments->prepare(
                $post_data,
                [
                    'idempotency_key' => (string) request('idempotency_key'),
                    'manage_token' => (string) ($post_data['manage_token'] ?? request('manage_token')),
                    'ip' => $this->input->ip_address(),
                    'email' => (string) ($customer['email'] ?? ''),
                ],
            );

            json_response($state);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            log_message('error', 'Booking payment prepare: ' . $e->getMessage());

            json_response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Start the payment of a booking.
     */
    public function checkout(): void
    {
        try {
            if (setting('disable_booking')) {
                abort(403);
            }

            if (!$this->webpay->is_enabled()) {
                throw new RuntimeException('El pago en línea no está disponible en este momento.');
            }

            $hold_token = (string) request('hold_token');
            $mode = (string) request('mode');

            $payment = $this->booking_payments->start_payment(
                $hold_token,
                $mode,
                (string) request('idempotency_key'),
            );

            json_response($payment);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            log_message('error', 'Booking payment checkout: ' . $e->getMessage());

            json_response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm a reschedule that does not need a new charge (the appointment is already paid).
     */
    public function confirm(): void
    {
        try {
            if (setting('disable_booking')) {
                abort(403);
            }

            $state = $this->booking_payments->confirm_free_hold((string) request('hold_token'));

            json_response($state);
        } catch (Throwable $e) {
            log_message('error', 'Booking payment confirm: ' . $e->getMessage());

            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Give up a booking that was not paid.
     */
    public function release(): void
    {
        try {
            $hold = $this->booking_payments_model->find_by_hash((string) request('hold_token'));

            if ($hold) {
                $this->booking_payments->release_hold((int) $hold['id']);
            }

            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * The result of a payment: paid, rejected, pending or in review.
     */
    public function result(string $token = ''): void
    {
        $state = $this->booking_payments->public_state($token);

        if (!$state) {
            html_vars([
                'page_title' => 'Reserva',
                'company_color' => setting('company_color'),
                'problem' => 'No encontramos esta reserva.',
                'google_analytics_code' => setting('google_analytics_code'),
                'matomo_analytics_url' => setting('matomo_analytics_url'),
                'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
            ]);

            $this->load->view('pages/booking_payment_result');

            return;
        }

        $appointment = $state['appointment'];

        html_vars([
            'page_title' => 'Reserva',
            'company_color' => setting('company_color'),
            'state' => $state,
            'summary' => $this->summary($state),
            'manage_url' => $appointment ? site_url('booking/reschedule/' . $appointment['hash']) : '',
            'google_analytics_code' => setting('google_analytics_code'),
            'matomo_analytics_url' => setting('matomo_analytics_url'),
            'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
        ]);

        $this->load->view('pages/booking_payment_result');
    }

    /**
     * The summary of the booking for the result page.
     */
    protected function summary(array $state): array
    {
        $start = new DateTime((string) $state['start_datetime']);

        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = [
            'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        ];

        $service_name = trim((string) $state['service_name']);
        $service_name = $service_name === mb_strtoupper($service_name) ? mb_strtolower($service_name) : $service_name;
        $service_name = mb_strtoupper(mb_substr($service_name, 0, 1)) . mb_substr($service_name, 1);

        $total = $state['total'];

        if ($total !== null && !$state['total_is_exact']) {
            $total_text = 'desde ' . money($total);
        } elseif ($total !== null) {
            $total_text = money($total);
        } else {
            $total_text = 'A confirmar en el salón';
        }

        return [
            'service' => $service_name,
            'hair_length' => Service_variants_model::label($state['hair_length']),
            'when' => ucfirst($days[(int) $start->format('w')]) . ' ' . $start->format('j') . ' de ' .
                $months[(int) $start->format('n') - 1] . ', ' . $start->format('H:i'),
            'total' => $total_text,
            'paid' => money($state['paid_amount']),
            'due' => $state['amount_due'] !== null ? money($state['amount_due']) : null,
        ];
    }

    /**
     * The CAPTCHA of the booking page (when it is turned on).
     */
    protected function captcha_is_valid(string $captcha): bool
    {
        if (!setting('require_captcha')) {
            return true;
        }

        $phrase = (string) session('captcha_phrase');

        return $phrase !== '' && strtoupper($phrase) === strtoupper(trim($captcha));
    }
}
