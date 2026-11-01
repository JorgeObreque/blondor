<?php defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__ . '/payments_selftest.php';

class Reminders_selftest extends Payments_selftest
{
    private int $staff_id;
    private array $messages = [];
    private DateTimeImmutable $clock;

    public function run(): array
    {
        $this->set_up();
        $this->CI->load->library('booking_reminders');
        $this->CI->db->insert('users', ['first_name' => 'zz_test_reminder_admin', 'last_name' => 'Test',
            'email' => 'zz_test_reminder_staff@blondor.test', 'id_roles' => 1, 'timezone' => 'America/Santiago', 'language' => 'spanish']);
        $this->staff_id = (int) $this->CI->db->insert_id();
        $this->CI->db->insert('user_settings', ['id_users' => $this->staff_id, 'notifications' => '1', 'google_sync' => 0, 'caldav_sync' => 0]);
        $this->clock = new DateTimeImmutable('2030-01-15T12:00:00Z');
        try {
            foreach (['test_parallel_cron_does_not_duplicate', 'test_unconfirmed_and_unavailable_are_ignored', 'test_24_and_2_hours_once', 'test_cancelled_and_past_are_skipped', 'test_reschedule_invalidates_old_jobs',
                'test_short_notice_and_activation_cutoff', 'test_preferences_and_missing_email', 'test_uncertain_send_is_not_retried',
                'test_recover_claim_before_send', 'test_dst_and_utc', 'test_schedule_revision_cannot_be_forged',
                'test_template_and_independent_recipients'] as $test) {
                $this->reset_fixtures();
                try { $this->$test(); $this->passed++; }
                catch (Throwable $e) { $this->failures[] = $test . ': ' . $e->getMessage(); }
            }
        } finally {
            $this->reset_fixtures();
            $this->CI->booking_reminders->set_transport(null);
            $this->tear_down();
        }
        return ['passed' => $this->passed, 'failed' => count($this->failures), 'failures' => $this->failures];
    }

    private function reset_fixtures(): void
    {
        $this->CI->db->empty_table('appointment_reminders');
        $this->CI->db->where('id_users_provider', $this->fixtures['provider_id'])->delete('appointments');
        $this->CI->db->update('users', ['email' => 'zz_test_cliente@blondor.test'], ['id' => $this->fixtures['customer_id']]);
        $this->CI->db->update('user_settings', ['notifications' => '1'], ['id_users' => $this->staff_id]);
        setting(['appointment_reminders_enabled' => '1', 'appointment_reminders_started_utc' => '2020-01-01 00:00:00',
            'appointment_reminder_staff_email' => 'zz_test_reminder_staff@blondor.test', 'customer_notifications' => '1']);
        $this->messages = [];
        $this->CI->booking_reminders->set_transport(function ($message) {
            $active = $this->CI->db->query('SELECT @@in_transaction AS active')->row_array();
            $this->equals(0, (int) $active['active'], 'No se mantiene una transacción durante el correo');
            $this->messages[] = $message;
        });
    }

    private function appointment(DateTimeImmutable $start, ?DateTimeImmutable $changed = null): array
    {
        $timezone = new DateTimeZone('America/Santiago');
        $local = $start->setTimezone($timezone);
        $this->CI->db->insert('appointments', [
            'start_datetime' => $local->format('Y-m-d H:i:s'), 'end_datetime' => $local->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'book_datetime' => $start->modify('-2 days')->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
            'reminder_schedule_changed_at_utc' => ($changed ?? $start->modify('-2 days'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'hash' => 'zz_reminder_' . bin2hex(random_bytes(8)), 'notes' => 'zz_reminder_private_note', 'is_unavailability' => 0,
            'id_users_provider' => $this->fixtures['provider_id'], 'id_users_customer' => $this->fixtures['customer_id'],
            'id_services' => $this->fixtures['services']['exact'], 'price' => 45000, 'status' => 'Confirmado', 'payment_status' => 'legacy',
        ]);
        return $this->CI->appointments_model->find((int) $this->CI->db->insert_id());
    }

    private function run_at(DateTimeImmutable $time, bool $deliver = true): array
    {
        return $this->CI->booking_reminders->run($time, $deliver);
    }

    protected function test_parallel_cron_does_not_duplicate(): void
    {
        $this->appointment($this->clock->modify('+24 hours'));
        $workers = [];
        $log = sys_get_temp_dir() . '/blondor-reminders-' . $this->CI->db->database . '.log';
        for ($i=0; $i<2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, 'index.php', 'console', 'reminders_test_worker',
                $this->CI->db->database, $this->clock->format('c')],
                [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, FCPATH);
            $this->ok(is_resource($process), 'Se inicia el worker de recordatorios');
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        try {
            foreach ($workers as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->equals(0, proc_close($process), 'Worker sin errores: ' . $error . $output);
            }
            $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->equals(2, count($lines), 'Dos procesos solo mandan un aviso por destinataria');
            $this->equals(2, count(array_unique($lines)), 'Cada correo es de una destinataria distinta');
            $this->equals(2, $this->CI->db->where('status', 'sent')->count_all_results('appointment_reminders'), 'Ambos quedan marcados como enviados');
        } finally { if (is_file($log)) { unlink($log); } }
    }

    protected function test_unconfirmed_and_unavailable_are_ignored(): void
    {
        $appointment = $this->appointment($this->clock->modify('+24 hours'));
        $this->CI->db->update('appointments', ['payment_status' => 'attention'], ['id' => $appointment['id']]);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'No recuerda un pago en revisión');
        $this->CI->db->update('appointments', ['payment_status' => 'legacy', 'is_unavailability' => 1], ['id' => $appointment['id']]);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'No envía indisponibilidades');
    }

    protected function test_24_and_2_hours_once(): void
    {
        $start = $this->clock->modify('+24 hours 10 minutes');
        $this->appointment($start);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'No adelanta el aviso de 24h');
        $due = $this->clock->modify('+11 minutes');
        $this->equals(2, $this->run_at($due)['sent'], 'Aviso 24h para clienta y administradora');
        $this->equals(0, $this->run_at($due->modify('+5 minutes'))['sent'], 'Cron repetido no duplica');
        $this->equals(2, $this->run_at($start->modify('-2 hours'))['sent'], 'Aviso 2h para ambas');
        $this->equals(0, $this->run_at($start->modify('-1 hour'))['sent'], 'No duplica el aviso 2h');
        $this->equals(4, count($this->messages), 'Dos correos por destinataria en total');
    }

    protected function test_cancelled_and_past_are_skipped(): void
    {
        $appointment = $this->appointment($this->clock->modify('+24 hours'));
        $this->run_at($this->clock, false);
        $this->CI->appointments_model->cancel($appointment['id']);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'Cancelar invalida correos en cola');
        $this->equals(2, $this->CI->db->where('status', 'skipped')->count_all_results('appointment_reminders'), 'Cola cancelada queda auditada');
        $past = $this->appointment($this->clock->modify('-1 minute'));
        $this->equals(0, $this->run_at($this->clock)['sent'], 'No avisa después de la cita');
    }

    protected function test_reschedule_invalidates_old_jobs(): void
    {
        $appointment = $this->appointment($this->clock->modify('+24 hours'));
        $this->run_at($this->clock, false);
        $new = $this->clock->modify('+48 hours')->setTimezone(new DateTimeZone('America/Santiago'));
        $this->CI->db->update('appointments', ['start_datetime' => $new->format('Y-m-d H:i:s'),
            'end_datetime' => $new->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'reminder_schedule_changed_at_utc' => $this->clock->format('Y-m-d H:i:s')], ['id' => $appointment['id']]);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'No manda el horario anterior');
        $this->equals(2, $this->run_at($this->clock->modify('+24 hours'))['sent'], 'Programa la fecha nueva');
    }

    protected function test_short_notice_and_activation_cutoff(): void
    {
        $start = $this->clock->modify('+10 hours');
        $this->appointment($start, $this->clock);
        $this->equals(0, $this->run_at($this->clock)['sent'], 'Reserva reciente no recibe un 24h tardío');
        $this->equals(2, $this->run_at($start->modify('-2 hours'))['sent'], 'Conserva el 2h futuro');
        $this->reset_fixtures();
        setting(['appointment_reminders_started_utc' => $this->clock->format('Y-m-d H:i:s')]);
        $this->appointment($this->clock->modify('+23 hours'));
        $this->equals(0, $this->run_at($this->clock)['sent'], 'Al activar no envía plazos que ya pasaron');
    }

    protected function test_preferences_and_missing_email(): void
    {
        $this->appointment($this->clock->modify('+24 hours'));
        setting(['customer_notifications' => '0']);
        $this->equals(1, $this->run_at($this->clock)['sent'], 'Respeta correos desactivados para clientas');
        $this->equals('zz_test_reminder_staff@blondor.test', $this->messages[0]['to'], 'Solo la administradora configurada');
        $this->reset_fixtures();
        $this->CI->db->update('users', ['email' => ''], ['id' => $this->fixtures['customer_id']]);
        $this->CI->db->update('user_settings', ['notifications' => '0'], ['id_users' => $this->staff_id]);
        $this->appointment($this->clock->modify('+24 hours'));
        $this->equals(0, $this->run_at($this->clock)['sent'], 'Sin correo o sin permiso no envía');
    }

    protected function test_uncertain_send_is_not_retried(): void
    {
        $this->appointment($this->clock->modify('+24 hours'));
        $this->CI->booking_reminders->set_transport(function ($message) { throw new RuntimeException('SMTP simulado incierto'); });
        $this->equals(2, $this->run_at($this->clock)['uncertain'], 'El envío ambiguo queda para revisar');
        $this->equals(0, $this->run_at($this->clock->modify('+10 minutes'))['queued'], 'No recrea envíos ambiguos');
        $this->equals(2, $this->CI->db->where('status', 'uncertain')->count_all_results('appointment_reminders'), 'No reenvía a ciegas');
    }

    protected function test_recover_claim_before_send(): void
    {
        $this->appointment($this->clock->modify('+24 hours'));
        $this->run_at($this->clock, false);
        $rows = $this->CI->db->get('appointment_reminders')->result_array();
        $this->CI->db->update('appointment_reminders', ['status' => 'processing', 'claim_expires_at_utc' => $this->clock->modify('-1 minute')->format('Y-m-d H:i:s')], ['id' => $rows[0]['id']]);
        $this->CI->db->update('appointment_reminders', ['status' => 'sending', 'claim_expires_at_utc' => $this->clock->modify('-1 minute')->format('Y-m-d H:i:s')], ['id' => $rows[1]['id']]);
        $this->equals(1, $this->run_at($this->clock)['sent'], 'Recupera solo el trabajo que aún no llegó a SMTP');
        $this->equals(1, $this->CI->db->where('status', 'uncertain')->count_all_results('appointment_reminders'), 'El envío interrumpido no se duplica');
    }

    protected function test_dst_and_utc(): void
    {
        $start = new DateTimeImmutable('2026-09-06T13:00:00Z');
        $this->appointment($start);
        $now = $start->modify('-24 hours');
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try { $this->run_at($now, false); } finally { date_default_timezone_set($timezone); }
        $row = $this->CI->db->get('appointment_reminders')->row_array();
        $this->ok($row !== null, 'El cambio de hora de Chile no pierde recordatorios');
        $seconds = strtotime($row['start_utc'] . ' UTC') - strtotime($row['due_at_utc'] . ' UTC');
        $this->equals(86400, $seconds, '24 horas reales, sin depender del reloj del servidor');
    }

    protected function test_schedule_revision_cannot_be_forged(): void
    {
        $appointment = $this->appointment($this->clock->modify('+25 hours'));
        $old = $appointment['reminder_schedule_changed_at_utc'];
        $appointment['notes'] = 'nueva nota';
        $appointment['reminder_schedule_changed_at_utc'] = '2099-01-01 00:00:00';
        $this->CI->appointments_model->save($appointment);
        $current = $this->CI->appointments_model->find($appointment['id']);
        $this->equals($old, $current['reminder_schedule_changed_at_utc'], 'Notas o payload no alteran la revisión del horario');
        $current['start_datetime'] = date('Y-m-d H:i:s', strtotime($current['start_datetime'] . ' +1 hour'));
        $current['end_datetime'] = date('Y-m-d H:i:s', strtotime($current['end_datetime'] . ' +1 hour'));
        $this->CI->appointments_model->save($current);
        $this->ok($this->CI->appointments_model->find($current['id'])['reminder_schedule_changed_at_utc'] !== $old, 'Reagenda actualiza la marca en servidor');
    }

    protected function test_template_and_independent_recipients(): void
    {
        $this->appointment($this->clock->modify('+24 hours'));
        $this->CI->booking_reminders->set_transport(function ($message) {
            if ($message['to'] === 'zz_test_cliente@blondor.test') { throw new RuntimeException('Fallo clienta simulado'); }
            $this->messages[] = $message;
        });
        $result = $this->run_at($this->clock);
        $this->equals(1, $result['sent'], 'El fallo de clienta no impide avisar a la administradora');
        $this->equals(1, $result['uncertain'], 'El fallo queda separado');
        $this->ok(str_contains($this->messages[0]['html'], '/backoffice_push/open/'), 'Link privado para la administradora');
        $this->ok(!str_contains($this->messages[0]['html'], 'zz_reminder_private_note'), 'No incluye notas privadas');
    }
}
