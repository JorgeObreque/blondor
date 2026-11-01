<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     Easy!Appointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/**
 * Booking cancellation controller.
 *
 * Handles the booking cancellation related operations.
 *
 * @package Controllers
 */
class Booking_cancellation extends EA_Controller
{
    /**
     * Booking_cancellation constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('customers_model');
        $this->load->model('booking_payments_model');

        $this->load->library('booking_payments');
        $this->load->library('synchronization');
        $this->load->library('notifications');
        $this->load->library('webhooks_client');
    }

    /**
     * Cancel an existing appointment.
     *
     * This method removes an appointment from the company's schedule. In order for the appointment to be deleted, the
     * hash string must be provided. The customer can only cancel the appointment if the edit time period is not over
     * yet.
     *
     * Blondor: la cancelación es lógica. La cita queda con estado "Cancelado" (así el horario se libera) pero NO se
     * borra: si tiene un pago, ese dinero queda registrado y se resuelve con el equipo, sin devoluciones ni
     * penalizaciones inventadas.
     *
     * @param string $appointment_hash This appointment hash identifier.
     */
    public function of(string $appointment_hash): void
    {
        try {
            $disable_booking = setting('disable_booking');

            if ($disable_booking) {
                abort(403);
            }

            $cancellation_reason = request('cancellation_reason');

            if ($this->input->method() !== 'post' || empty($cancellation_reason)) {
                abort(403, 'Forbidden');
            }

            $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

            if (empty($occurrences)) {
                html_vars([
                    'page_title' => lang('appointment_not_found'),
                    'company_color' => setting('company_color'),
                    'message_title' => lang('appointment_not_found'),
                    'message_text' => lang('appointment_does_not_exist_in_db'),
                    'message_icon' => base_url('assets/img/error.png'),
                    'google_analytics_code' => setting('google_analytics_code'),
                    'matomo_analytics_url' => setting('matomo_analytics_url'),
                    'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
                ]);

                $this->load->view('pages/booking_message');

                return;
            }

            $appointment = $occurrences[0];

            if ($appointment['status'] === 'Cancelado') {
                // Ya estaba cancelada: no se avisa dos veces.
                $this->show_cancelled();

                return;
            }

            // Blondor: cancelación lógica. La fila se conserva (con su pago) y solo cambia el estado.
            $appointment = $this->appointments_model->cancel(
                (int) $appointment['id'],
                (string) $cancellation_reason,
            );

            // El aviso (correo, calendario y webhook) queda en la bandeja: si falla, se reintenta.
            $this->booking_payments_model->enqueue(
                'appointment_cancelled',
                'appointment_cancelled:' . $appointment['id'] . ':' . $appointment['update_datetime'],
                [
                    'appointment_id' => (int) $appointment['id'],
                    'reason' => mb_substr(trim((string) $cancellation_reason), 0, 200),
                ],
                ['id_appointments' => (int) $appointment['id']],
            );

            $this->booking_payments->dispatch_outbox();
        } catch (Throwable $e) {
            log_message('error', 'Booking Cancellation Exception: ' . $e->getMessage());

            // Blondor: no se confirma una cancelación que no ocurrió.
            html_vars([
                'page_title' => lang('appointment_cancelled_title'),
                'company_color' => setting('company_color'),
                'message_title' => 'No pudimos cancelar la cita',
                'message_text' => 'Vuelve a intentarlo o escríbenos por WhatsApp para cancelarla.',
                'message_icon' => base_url('assets/img/error.png'),
                'google_analytics_code' => setting('google_analytics_code'),
                'matomo_analytics_url' => setting('matomo_analytics_url'),
                'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
            ]);

            $this->load->view('pages/booking_message');

            return;
        }

        $this->show_cancelled();
    }

    /**
     * The "cancelled" page.
     */
    protected function show_cancelled(): void
    {
        html_vars([
            'page_title' => lang('appointment_cancelled_title'),
            'company_color' => setting('company_color'),
            'google_analytics_code' => setting('google_analytics_code'),
            'matomo_analytics_url' => setting('matomo_analytics_url'),
            'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
        ]);

        $this->load->view('pages/booking_cancellation');
    }
}
