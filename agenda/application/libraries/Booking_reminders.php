<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Scheduled email reminders. All scheduler timestamps are UTC; appointments use the provider's timezone. */
class Booking_reminders
{
    protected $CI;
    protected $transport = null;

    public function __construct() { $this->CI = &get_instance(); }

    public function ready(): bool
    {
        return $this->CI->db->table_exists('appointment_reminders');
    }

    public function setup(): void
    {
        if (!is_cli() || !$this->ready()) { throw new RuntimeException('Aplica la migración antes de activar recordatorios.'); }
        if (!setting('appointment_reminders_started_utc')) {
            setting(['appointment_reminders_started_utc' => gmdate('Y-m-d H:i:s')]);
        }
        setting(['appointment_reminders_enabled' => '1']);
    }

    public function set_transport(?callable $transport): void { $this->transport = $transport; }

    protected function utc(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    protected function stamp(DateTimeImmutable $date): string { return $date->format('Y-m-d H:i:s'); }

    protected function load_appointment(int $id): ?array
    {
        return $this->CI->db->select('a.*,p.timezone AS provider_timezone,p.first_name AS provider_first,p.last_name AS provider_last,
                c.email AS customer_email,c.first_name AS customer_first,c.last_name AS customer_last,c.phone_number AS customer_phone,
                s.name AS service_name')
            ->from('appointments a')->join('users p', 'p.id=a.id_users_provider')
            ->join('users c', 'c.id=a.id_users_customer')->join('services s', 's.id=a.id_services')
            ->where('a.id', $id)->get()->row_array() ?: null;
    }

    protected function eligible(array $appointment): bool
    {
        return empty($appointment['is_unavailability'])
            && !in_array($appointment['status'] ?? '', ['Cancelado', 'No vino', 'Llegó'], true)
            && !in_array($appointment['payment_status'] ?? 'legacy', ['attention', 'cancelled'], true);
    }

    protected function start(array $appointment): DateTimeImmutable
    {
        return (new DateTimeImmutable($appointment['start_datetime'], new DateTimeZone($appointment['provider_timezone'] ?: 'America/Santiago')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    protected function schedule_key(array $appointment): string
    {
        return hash('sha256', $appointment['id'] . '|' . $this->start($appointment)->format('c'));
    }

    protected function changed_at(array $appointment): DateTimeImmutable
    {
        if (!empty($appointment['reminder_schedule_changed_at_utc'])) {
            return new DateTimeImmutable($appointment['reminder_schedule_changed_at_utc'], new DateTimeZone('UTC'));
        }
        // Legacy book_datetime was written with PHP's server timezone, independently of provider timezone.
        $value = $appointment['book_datetime'] ?: ($appointment['create_datetime'] ?? null);
        if (!$value) { return new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')); }
        return (new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'));
    }

    protected function recipients(array $appointment): array
    {
        $result = [];
        $email = strtolower(trim((string) $appointment['customer_email']));
        if (filter_var(setting('customer_notifications'), FILTER_VALIDATE_BOOLEAN) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result[] = ['audience' => 'customer', 'id_users' => (int) $appointment['id_users_customer'], 'email' => $email];
        }
        $email = strtolower(trim((string) setting('appointment_reminder_staff_email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $staff = $this->CI->db->select('u.id')->from('users u')->join('roles r', 'r.id=u.id_roles')
                ->join('user_settings s', 's.id_users=u.id')->where('LOWER(u.email)', $email)
                ->where('r.slug', 'admin')->where('r.appointments >', 0)->where('s.notifications', '1')->get()->row_array();
            if ($staff && (!$result || $result[0]['email'] !== $email)) {
                $result[] = ['audience' => 'staff', 'id_users' => (int) $staff['id'], 'email' => $email];
            }
        }
        return $result;
    }

    /** Due windows, not a catch-up blast: 24h expires at the 2h window; 2h expires when the appointment starts. */
    protected function due_jobs(DateTimeImmutable $now): array
    {
        $activated = (string) setting('appointment_reminders_started_utc');
        if ($activated === '') { return []; }
        $activation = new DateTimeImmutable($activated, new DateTimeZone('UTC'));
        $ids = $this->CI->db->select('id')->from('appointments')->where('is_unavailability', 0)
            ->where('start_datetime >=', $this->stamp($now->modify('-2 days')))
            ->where('start_datetime <=', $this->stamp($now->modify('+3 days')))->order_by('start_datetime')->get()->result_array();
        $jobs = [];
        foreach ($ids as $row) {
            $appointment = $this->load_appointment((int) $row['id']);
            if (!$appointment || !$this->eligible($appointment)) { continue; }
            try { $start = $this->start($appointment); $changed = $this->changed_at($appointment); }
            catch (Throwable $e) { continue; }
            if ($start <= $now) { continue; }
            foreach ([24, 2] as $hours) {
                $due = $start->modify('-' . $hours . ' hours');
                $expires = $hours === 24 ? $start->modify('-2 hours') : $start;
                if ($due < $activation || $due > $now || $expires <= $now || $changed > $due) { continue; }
                foreach ($this->recipients($appointment) as $recipient) {
                    $jobs[] = ['id_appointments' => (int) $appointment['id'], 'schedule_key' => $this->schedule_key($appointment),
                        'start_utc' => $this->stamp($start), 'hours_before' => $hours, 'audience' => $recipient['audience'],
                        'id_users' => $recipient['id_users'], 'recipient_hash' => hash('sha256', $recipient['email']),
                        'due_at_utc' => $this->stamp($due), 'expires_at_utc' => $this->stamp($expires)];
                }
            }
        }
        return $jobs;
    }

    public function preview(?DateTimeImmutable $now = null): array
    {
        if (!$this->ready()) { return ['enabled' => false, 'due_recipients' => 0]; }
        $enabled = setting('appointment_reminders_enabled') === '1';
        return ['enabled' => $enabled, 'due_recipients' => $enabled ? count($this->due_jobs($this->utc($now))) : 0,
            'statuses' => $this->CI->db->select('status,COUNT(*) AS total', false)->group_by('status')->get('appointment_reminders')->result_array()];
    }

    protected function link(string $path): string
    {
        $host = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
        return site_url($path, in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? null : 'https');
    }

    protected function message(array $job, array $appointment, string $email): array
    {
        $is_staff = $job['audience'] === 'staff';
        $start = $this->start($appointment)->setTimezone(new DateTimeZone($appointment['provider_timezone'] ?: 'America/Santiago'));
        $date = $start->format('d/m/Y'); $time = $start->format('H:i');
        $heading = $is_staff ? 'Recordatorio de cita en tu agenda' : 'Te esperamos en Blondor';
        $subject = $is_staff ? 'Agenda Blondor · Recordatorio de cita (' . $job['hours_before'] . ' h)'
            : ((int) $job['hours_before'] === 2 ? 'Tu cita en Blondor se acerca' : 'Recordatorio de tu cita en Blondor');
        $link = $is_staff ? $this->link('backoffice_push/open/' . (int) $appointment['id'])
            : $this->link('booking/reschedule/' . $appointment['hash']);
        $this->CI->load->model('service_variants_model');
        $html = $this->CI->load->view('emails/appointment_reminder_email', [
            'heading' => $heading, 'is_staff' => $is_staff, 'date' => $date, 'time' => $time,
            'timezone' => $appointment['provider_timezone'] ?: 'America/Santiago',
            'service' => $appointment['service_name'], 'hair_length' => Service_variants_model::label($appointment['hair_length'] ?? null),
            'provider' => trim($appointment['provider_first'] . ' ' . $appointment['provider_last']),
            'customer' => trim($appointment['customer_first'] . ' ' . $appointment['customer_last']),
            'phone' => $appointment['customer_phone'], 'link' => $link,
        ], true);
        return ['to' => $email, 'subject' => $subject, 'html' => $html];
    }

    protected function send(array $message): void
    {
        if ($this->transport) { ($this->transport)($message); return; }
        if (str_starts_with($this->CI->db->database, 'agenda_payments_test_')) { throw new RuntimeException('Se requiere correo simulado.'); }
        if (!$this->CI->email) { $this->CI->load->library('email'); }
        $mail = $this->CI->email;
        $mail->clear(true);
        $mail->set_mailtype('html');
        $mail->from(config('from_address') ?: setting('company_email'), config('from_name') ?: setting('company_name'));
        $reply = config('reply_to') ?: setting('company_email');
        if ($reply) { $mail->reply_to($reply); }
        $mail->to($message['to']); $mail->subject($message['subject']); $mail->message($message['html']);
        if (!$mail->send(false)) { throw new RuntimeException('Resultado del envío no confirmado.'); }
    }

    public function run(?DateTimeImmutable $now = null, bool $deliver = true): array
    {
        $summary = ['queued' => 0, 'sent' => 0, 'skipped' => 0, 'uncertain' => 0, 'retry' => 0];
        if (!$this->ready() || setting('appointment_reminders_enabled') !== '1') { return $summary; }
        $real_clock = $now === null;
        $now = $this->utc($now); $stamp = $this->stamp($now); $db = $this->CI->db;
        $table = $db->dbprefix('appointment_reminders');
        // An interrupted SMTP call may already have delivered: never resend it blindly.
        $db->query("UPDATE `$table` SET status='uncertain',update_utc=? WHERE status='sending' AND claim_expires_at_utc < ?", [$stamp, $stamp]);
        $db->query("UPDATE `$table` SET status='pending',claim_token=NULL,claim_expires_at_utc=NULL,update_utc=? WHERE status='processing' AND claim_expires_at_utc < ?", [$stamp, $stamp]);
        foreach ($this->due_jobs($now) as $job) {
            $data = $job + ['status' => 'pending', 'attempts' => 0, 'available_at_utc' => $stamp, 'create_utc' => $stamp, 'update_utc' => $stamp];
            $fields = array_keys($data);
            $db->query("INSERT IGNORE INTO `$table` (`" . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($data));
            if ($db->affected_rows() === 1) { $summary['queued']++; }
        }
        if (!$deliver) { return $summary; }
        $jobs = $db->where('status', 'pending')->where('available_at_utc <=', $stamp)->order_by('due_at_utc')->order_by('id')->limit(100)->get('appointment_reminders')->result_array();
        foreach ($jobs as $job) {
            if ($real_clock) { $now = $this->utc(); $stamp = $this->stamp($now); }
            $token = bin2hex(random_bytes(16));
            $db->query("UPDATE `$table` SET status='processing',claim_token=?,claim_expires_at_utc=?,update_utc=? WHERE id=? AND status='pending' AND available_at_utc<=?", [
                $token, $this->stamp($now->modify('+5 minutes')), $stamp, $job['id'], $stamp,
            ]);
            if ($db->affected_rows() !== 1) { continue; }
            $sending = false;
            try {
                $appointment = $this->load_appointment((int) $job['id_appointments']);
                $recipient = null;
                if ($appointment && $this->eligible($appointment)) {
                    foreach ($this->recipients($appointment) as $candidate) {
                        if ($candidate['audience'] === $job['audience'] && $candidate['id_users'] === (int) $job['id_users']
                            && hash('sha256', $candidate['email']) === $job['recipient_hash']) { $recipient = $candidate; }
                    }
                }
                $valid = $appointment && $recipient && $job['expires_at_utc'] > $stamp && $job['due_at_utc'] <= $stamp
                    && $this->schedule_key($appointment) === $job['schedule_key']
                    && $this->changed_at($appointment) <= new DateTimeImmutable($job['due_at_utc'], new DateTimeZone('UTC'));
                if (!$valid) {
                    $db->update('appointment_reminders', ['status' => 'skipped', 'update_utc' => $stamp], ['id' => $job['id'], 'claim_token' => $token]);
                    $summary['skipped']++; continue;
                }
                $message = $this->message($job, $appointment, $recipient['email']);
                $db->query("UPDATE `$table` SET status='sending',update_utc=? WHERE id=? AND status='processing' AND claim_token=? AND claim_expires_at_utc>=?", [$stamp, $job['id'], $token, $stamp]);
                if ($db->affected_rows() !== 1) { continue; }
                $sending = true;
                $this->send($message);
                $db->update('appointment_reminders', ['status' => 'sent', 'sent_at_utc' => $stamp, 'update_utc' => $stamp], ['id' => $job['id'], 'claim_token' => $token]);
                $summary['sent']++;
            } catch (Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                $db->update('appointment_reminders', ['status' => $sending ? 'uncertain' : ($attempts >= 8 ? 'failed' : 'pending'),
                    'attempts' => $attempts, 'claim_token' => null, 'claim_expires_at_utc' => null,
                    'available_at_utc' => $this->stamp($now->modify('+5 minutes')), 'update_utc' => $stamp], ['id' => $job['id'], 'claim_token' => $token]);
                $summary[$sending ? 'uncertain' : 'retry']++;
                log_message('error', 'Recordatorio ' . (int) $job['id'] . ': ' . ($sending ? 'envío sin confirmar; revisar antes de reenviar.' : 'pendiente de reintento.'));
            }
        }
        return $summary;
    }
}
