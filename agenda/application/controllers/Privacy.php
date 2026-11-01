<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     Easy!Appointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Privacy controller.
 *
 * Handles the privacy related operations.
 *
 * @package Controllers
 */
class Privacy extends EA_Controller
{
    /**
     * Privacy constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('customers_model');
        $this->load->model('booking_payments_model');
    }

    /**
     * Remove all customer data (including appointments) from the system.
     *
     * Blondor: si la clienta tiene citas pagadas, sus citas NO se borran (el pago quedaría sin registro): se
     * desvinculan de su ficha y sus datos se anonimizan. El dinero y el histórico financiero se conservan.
     */
    public function delete_personal_information(): void
    {
        try {
            $display_delete_personal_information = setting('display_delete_personal_information');

            if (!$display_delete_personal_information) {
                abort(403, 'Forbidden');
            }

            $customer_token = request('customer_token');

            if (empty($customer_token)) {
                throw new InvalidArgumentException('Invalid customer token value provided.');
            }

            $cache = &app_cache();

            $customer_id = $cache->get('customer-token-' . $customer_token);

            if (empty($customer_id)) {
                throw new InvalidArgumentException(
                    'Customer ID does not exist, please reload the page ' . 'and try again.',
                );
            }

            $this->anonymize_paid_appointments((int) $customer_id);

            $this->customers_model->delete((int) $customer_id);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Detach the appointments that have money on them, so the payment stays registered.
     *
     * @param int $customer_id Customer ID.
     */
    protected function anonymize_paid_appointments(int $customer_id): void
    {
        $appointments = $this->db
            ->select('id')
            ->from('appointments')
            ->where('id_users_customer', $customer_id)
            ->where(
                'IFNULL(payment_status, "") IN ("deposit_paid", "paid", "attention") OR IFNULL(paid_amount, 0) > 0',
                null,
                false,
            )
            ->get()
            ->result_array();

        foreach ($appointments as $appointment) {
            // La cita se queda (con su pago) pero sin Dueña.
            $this->db->update('appointments', ['id_users_customer' => null], ['id' => $appointment['id']]);

            $holds = $this->db
                ->select('id, customer_snapshot')
                ->from('booking_holds')
                ->where('id_appointments', $appointment['id'])
                ->get()
                ->result_array();

            foreach ($holds as $hold) {
                $snapshot = json_decode((string) $hold['customer_snapshot'], true) ?: [];

                foreach (['first_name', 'last_name', 'email', 'phone_number', 'address', 'city', 'zip_code', 'ip'] as $field) {
                    if (array_key_exists($field, $snapshot)) {
                        $snapshot[$field] = '';
                    }
                }

                $this->db->update(
                    'booking_holds',
                    [
                        'id_users_customer' => null,
                        'customer_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                        'update_datetime' => date('Y-m-d H:i:s'),
                    ],
                    ['id' => $hold['id']],
                );
            }
        }

        // Las reservas sin pagar tampoco quedan apuntando a una clienta que ya no existe.
        $this->db
            ->where('id_users_customer', $customer_id)
            ->where('id_appointments', null)
            ->update('booking_holds', ['id_users_customer' => null]);
    }
}
