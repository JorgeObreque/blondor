<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Recorrido de la reserva (API para Publicador)
 *
 * GET api/v1/booking_funnel?from=YYYY-MM-DD&till=YYYY-MM-DD
 *     [{attributionCode, steps: {site, agenda, service, time, details, webpay}, paid, paidAmount}]
 * GET api/v1/booking_abandoned?from=YYYY-MM-DD&till=YYYY-MM-DD
 *     [{id, created, attributionCode, reason, serviceId, serviceName, hairLength, start, totalAmount, depositAmount,
 *       firstName, lastName, email, phone, recovered}]
 *
 * Solo lectura y con el mismo usuario de la API que el resto (Basic Auth o token).
 * ---------------------------------------------------------------------------- */

class Booking_funnel_api_v1 extends EA_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->library('api');

        $this->api->auth();

        $this->load->library('booking_funnel');
    }

    public function index(): void
    {
        try {
            $rows = $this->booking_funnel->report((string) request('from'), (string) request('till'));

            json_response(
                array_map(
                    fn($row) => [
                        'attributionCode' => $row['attribution_code'],
                        'steps' => $row['steps'],
                        'paid' => $row['paid'],
                        'paidAmount' => $row['paid_amount'],
                    ],
                    $rows,
                ),
            );
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    public function abandoned(): void
    {
        try {
            $rows = $this->booking_funnel->abandoned((string) request('from'), (string) request('till'));

            json_response(
                array_map(
                    fn($row) => [
                        'id' => $row['id'],
                        'created' => $row['created'],
                        'attributionCode' => $row['attribution_code'],
                        'reason' => $row['reason'],
                        'serviceId' => $row['service_id'],
                        'serviceName' => $row['service_name'],
                        'hairLength' => $row['hair_length'],
                        'start' => $row['start'],
                        'totalAmount' => $row['total_amount'],
                        'depositAmount' => $row['deposit_amount'],
                        'firstName' => $row['first_name'],
                        'lastName' => $row['last_name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'],
                        'recovered' => $row['recovered'],
                    ],
                    $rows,
                ),
            );
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
