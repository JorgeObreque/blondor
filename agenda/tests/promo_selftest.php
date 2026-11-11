<?php defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__ . '/payments_selftest.php';

/* ----------------------------------------------------------------------------
 * Blondor - Pruebas de los servicios en promoción (días permitidos y cupos por día)
 *
 * Corre sobre la base aislada de scripts/run_payments_tests.sh con las fixtures sintéticas de las pruebas de pago.
 * ---------------------------------------------------------------------------- */

class Promo_selftest extends Payments_selftest
{
    public function run(): array
    {
        $this->set_up();
        $this->CI->load->model('service_catalog_model');

        $this->fixtures['services']['promo'] = $this->make_service('zz_test_promo', 81000, 'from', 30);
        $this->fixtures['services']['plain'] = $this->make_service('zz_test_normal_con_reglas', 95500, 'from', 30);

        try {
            foreach ([
                'test_service_without_promo_ignores_saved_rules',
                'test_promo_only_on_allowed_weekdays',
                'test_promo_without_days_is_every_day',
                'test_quota_closes_the_day',
                'test_cancelled_appointment_frees_a_slot',
                'test_rescheduled_appointment_keeps_its_slot',
                'test_pending_payment_uses_a_slot',
                'test_booking_is_rejected_when_full',
                'test_turning_promo_off_lifts_the_rules',
                'test_catalog_saves_and_validates_the_rules',
                'test_catalog_saves_the_regular_service',
                'test_promo_has_a_single_price',
            ] as $test) {
                try {
                    $this->reset_promo();
                    $this->$test();
                    $this->passed++;
                } catch (Throwable $e) {
                    $this->failures[] = $test . ': ' . $e->getMessage();
                }
            }
        } finally {
            $this->tear_down();
        }

        return ['passed' => $this->passed, 'failed' => count($this->failures), 'failures' => $this->failures];
    }

    /**
     * Each test starts with the promo service on Monday to Wednesday and 3 per day, and no appointments.
     */
    private function reset_promo(): void
    {
        $ids = [$this->fixtures['services']['promo'], $this->fixtures['services']['plain']];

        $this->CI->db->query(
            'DELETE FROM `' . $this->CI->db->dbprefix('booking_holds') . '` WHERE `idempotency_key` LIKE "zztestpromo%"',
        );
        $this->delete_unpaid_appointments(['id_services' => $ids]);

        $this->CI->db->update(
            'services',
            ['is_promo' => 1, 'promo_weekdays' => '1,2,3', 'promo_daily_quota' => 3, 'promo_regular_service_id' => null],
            ['id' => $this->fixtures['services']['promo']],
        );

        // Same rules saved, but not a promotion: they must not count.
        $this->CI->db->update(
            'services',
            ['is_promo' => 0, 'promo_weekdays' => '1,2,3', 'promo_daily_quota' => 1],
            ['id' => $this->fixtures['services']['plain']],
        );
    }

    /**
     * The next date (from the day after tomorrow) with the given ISO weekday.
     */
    private function next_weekday(int $weekday, int $weeks_ahead = 0): string
    {
        $date = new DateTimeImmutable('+2 days');

        while ((int) $date->format('N') !== $weekday) {
            $date = $date->modify('+1 day');
        }

        return $date->modify('+' . $weeks_ahead . ' weeks')->format('Y-m-d');
    }

    private function hours(string $service_key, string $date, ?int $exclude_appointment_id = null): array
    {
        return $this->CI->availability->get_available_hours(
            $date,
            $this->CI->services_model->find($this->fixtures['services'][$service_key]),
            $this->CI->providers_model->find($this->fixtures['provider_id']),
            $exclude_appointment_id,
        );
    }

    private function book(string $service_key, string $date, string $time, string $status = 'Agendado'): int
    {
        $start = $date . ' ' . $time . ':00';

        $this->CI->db->insert('appointments', [
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => $start,
            'end_datetime' => date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')),
            'hash' => 'zz_promo_' . bin2hex(random_bytes(8)),
            'notes' => 'zz_test promo',
            'is_unavailability' => 0,
            'id_users_provider' => $this->fixtures['provider_id'],
            'id_users_customer' => $this->fixtures['customer_id'],
            'id_services' => $this->fixtures['services'][$service_key],
            'price' => 81000,
            'status' => $status,
        ]);

        return (int) $this->CI->db->insert_id();
    }

    protected function test_service_without_promo_ignores_saved_rules(): void
    {
        $thursday = $this->next_weekday(4);
        $this->ok((bool) $this->hours('plain', $thursday), 'Un servicio normal se agenda el jueves aunque tenga días guardados');

        $monday = $this->next_weekday(1);
        $this->book('plain', $monday, '09:00');
        $this->ok((bool) $this->hours('plain', $monday), 'Un servicio normal no tiene cupo aunque lo tenga guardado');
    }

    protected function test_promo_only_on_allowed_weekdays(): void
    {
        foreach ([1, 2, 3] as $day) {
            $this->ok((bool) $this->hours('promo', $this->next_weekday($day)), 'La promo se agenda el día ' . $day);
        }

        foreach ([4, 5, 6, 7] as $day) {
            $this->equals([], $this->hours('promo', $this->next_weekday($day)), 'La promo no se agenda el día ' . $day);
        }
    }

    protected function test_promo_without_days_is_every_day(): void
    {
        $this->CI->db->update('services', ['promo_weekdays' => null], ['id' => $this->fixtures['services']['promo']]);

        $this->ok((bool) $this->hours('promo', $this->next_weekday(5)), 'Sin días marcados, la promo se agenda el viernes');
    }

    protected function test_quota_closes_the_day(): void
    {
        $monday = $this->next_weekday(1);

        $this->book('promo', $monday, '09:00');
        $this->book('promo', $monday, '10:00');
        $this->ok((bool) $this->hours('promo', $monday), 'Con 2 de 3 cupos usados quedan horas');

        $this->book('promo', $monday, '11:00');
        $this->equals([], $this->hours('promo', $monday), 'Con 3 de 3 cupos el día se cierra');

        $this->ok((bool) $this->hours('promo', $this->next_weekday(1, 1)), 'El lunes siguiente tiene sus propios cupos');
        $this->ok((bool) $this->hours('exact', $monday), 'Los otros servicios siguen disponibles ese día');
    }

    protected function test_cancelled_appointment_frees_a_slot(): void
    {
        $tuesday = $this->next_weekday(2);

        $this->book('promo', $tuesday, '09:00');
        $this->book('promo', $tuesday, '10:00');
        $cancelled = $this->book('promo', $tuesday, '11:00');
        $this->equals([], $this->hours('promo', $tuesday), 'Día lleno antes de cancelar');

        $this->CI->db->update('appointments', ['status' => 'Cancelado'], ['id' => $cancelled]);
        $this->ok((bool) $this->hours('promo', $tuesday), 'Una cita cancelada libera su cupo');
    }

    protected function test_rescheduled_appointment_keeps_its_slot(): void
    {
        $wednesday = $this->next_weekday(3);

        $this->book('promo', $wednesday, '09:00');
        $this->book('promo', $wednesday, '10:00');
        $moving = $this->book('promo', $wednesday, '11:00');

        $this->ok(
            (bool) $this->hours('promo', $wednesday, $moving),
            'Al cambiar la hora de una cita de ese día, su propio cupo no la bloquea',
        );
    }

    protected function test_pending_payment_uses_a_slot(): void
    {
        $monday = $this->next_weekday(1, 2);

        $this->book('promo', $monday, '09:00');
        $this->book('promo', $monday, '10:00');

        $this->fake_webpay('authorized');
        $start = $monday . ' 12:00:00';
        $this->prepare('promo', 'promohold', [$start, date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')), $monday]);

        $this->equals([], $this->hours('promo', $monday), 'Una reserva esperando el pago ocupa un cupo');
    }

    protected function test_booking_is_rejected_when_full(): void
    {
        $tuesday = $this->next_weekday(2, 2);

        $this->book('promo', $tuesday, '09:00');
        $this->book('promo', $tuesday, '10:00');
        $this->book('promo', $tuesday, '11:00');

        $this->fake_webpay('authorized');
        $start = $tuesday . ' 14:00:00';

        try {
            $this->prepare('promo', 'promofull', [$start, date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')), $tuesday]);
            $rejected = false;
        } catch (Throwable $e) {
            $rejected = true;
        }

        $this->ok($rejected, 'La cuarta reserva del día se rechaza aunque se envíe directo');
        $this->equals(
            0,
            $this->CI->db->like('idempotency_key', 'zztestpromofull', 'after')->count_all_results('booking_holds'),
            'No queda una reserva retenida para la cuarta',
        );
    }

    protected function test_turning_promo_off_lifts_the_rules(): void
    {
        $thursday = $this->next_weekday(4);
        $this->equals([], $this->hours('promo', $thursday), 'Con promo, el jueves está cerrado');

        $this->CI->service_catalog_model->save_changes(
            [['id' => $this->fixtures['services']['promo'], 'is_promo' => 'false']],
            0,
        );

        $this->ok((bool) $this->hours('promo', $thursday), 'Sin promo, el jueves se puede agendar');

        $row = $this->CI->db->get_where('services', ['id' => $this->fixtures['services']['promo']])->row_array();
        $this->equals('1,2,3', $row['promo_weekdays'], 'Los días guardados se conservan al desmarcar');
        $this->equals(3, (int) $row['promo_daily_quota'], 'Los cupos guardados se conservan al desmarcar');
    }

    protected function test_catalog_saves_and_validates_the_rules(): void
    {
        $id = $this->fixtures['services']['promo'];

        $saved = $this->CI->service_catalog_model->save_changes(
            [['id' => $id, 'is_promo' => 'true', 'promo_weekdays' => '3,1,2,1', 'promo_daily_quota' => '5']],
            0,
        );

        $card = $saved[0]['service'];
        $this->equals(true, $card['is_promo'], 'La ficha dice que es promo');
        $this->equals([1, 2, 3], $card['promo_weekdays'], 'Los días quedan ordenados y sin repetir');
        $this->equals(5, $card['promo_daily_quota'], 'El cupo queda guardado');
        $this->equals([1, 2, 3], $saved[0]['previous']['promo_weekdays'], 'Deshacer conoce los días anteriores');

        $this->CI->service_catalog_model->save_changes([['id' => $id, 'promo_weekdays' => '', 'promo_daily_quota' => '']], 0);
        $row = $this->CI->db->get_where('services', ['id' => $id])->row_array();
        $this->ok($row['promo_weekdays'] === null && $row['promo_daily_quota'] === null, 'Vacío deja sin días ni límite');

        foreach ([['promo_weekdays' => '8'], ['promo_weekdays' => 'lunes'], ['promo_daily_quota' => '0'],
            ['promo_daily_quota' => '51'], ['promo_daily_quota' => '2.5']] as $invalid) {
            try {
                $this->CI->service_catalog_model->save_changes([['id' => $id] + $invalid], 0);
                $failed = false;
            } catch (InvalidArgumentException $e) {
                $failed = true;
                // The rejected change wrote nothing; close the transaction it left open.
                $this->CI->db->trans_complete();
            }

            $this->ok($failed, 'Se rechaza ' . json_encode($invalid));
        }
    }

    protected function test_catalog_saves_the_regular_service(): void
    {
        $id = $this->fixtures['services']['promo'];
        $regular = $this->fixtures['services']['plain'];

        $saved = $this->CI->service_catalog_model->save_changes([['id' => $id, 'promo_regular_service_id' => (string) $regular]], 0);
        $this->equals($regular, $saved[0]['service']['promo_regular_service_id'], 'La ficha conoce el servicio de precio normal');
        $this->equals(null, $saved[0]['previous']['promo_regular_service_id'], 'Deshacer sabe que antes no tenía');

        $this->CI->service_catalog_model->save_changes([['id' => $id, 'promo_regular_service_id' => '']], 0);
        $row = $this->CI->db->get_where('services', ['id' => $id])->row_array();
        $this->ok($row['promo_regular_service_id'] === null, 'Vacío deja de mostrar el precio normal');

        foreach ([(string) $id, '999999999', 'balayage', '2.5'] as $invalid) {
            try {
                $this->CI->service_catalog_model->save_changes([['id' => $id, 'promo_regular_service_id' => $invalid]], 0);
                $failed = false;
            } catch (InvalidArgumentException $e) {
                $failed = true;
                $this->CI->db->trans_complete();
            }

            $this->ok($failed, 'Se rechaza el precio normal ' . json_encode($invalid));
        }
    }

    protected function test_promo_has_a_single_price(): void
    {
        $promo = $this->fixtures['services']['promo'];
        $plain = $this->fixtures['services']['plain'];
        $lengths = [['hair_length' => 'short', 'price' => 81000], ['hair_length' => 'xlong', 'price' => 91000]];

        $rejected = function (array $change, string $message) {
            try {
                $this->CI->service_catalog_model->save_changes([$change], 0);
                $failed = false;
            } catch (InvalidArgumentException $e) {
                $failed = true;
                $this->CI->db->trans_complete();
            }

            $this->ok($failed, $message);
        };

        $rejected(['id' => $promo, 'variants' => $lengths], 'Una promo no acepta precios por largo');
        $this->equals(0, count($this->CI->service_variants_model->of_service($promo)), 'La promo sigue sin largos');

        $this->CI->service_catalog_model->save_changes([['id' => $plain, 'variants' => $lengths]], 0);
        $rejected(['id' => $plain, 'is_promo' => 'true'], 'Un servicio con largos no se vuelve promo sin quitarlos');
        $this->equals(0, (int) $this->CI->db->get_where('services', ['id' => $plain])->row()->is_promo, 'Sigue sin ser promo');

        $saved = $this->CI->service_catalog_model->save_changes(
            [['id' => $plain, 'is_promo' => 'true', 'variants' => [], 'price' => '81000', 'price_type' => 'from']],
            0,
        );
        $this->equals(true, $saved[0]['service']['is_promo'], 'Quitando los largos sí se vuelve promo');
        $this->equals([], $saved[0]['service']['variants'], 'Quedó sin largos');
        $this->equals('fixed', $saved[0]['service']['price_type'], 'Una promo tiene precio fijo, no «desde»');

        $this->CI->service_catalog_model->save_changes([['id' => $plain, 'is_promo' => 'false']], 0);
    }
}
