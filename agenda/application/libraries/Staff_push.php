<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Authenticated staff Web Push. Delivery is independent of mail and never stores client data in payloads. */
class Staff_push
{
    protected $CI;
    protected $transport = null;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    public function tables_ready(): bool
    {
        return $this->CI->db->table_exists('push_subscriptions') && $this->CI->db->table_exists('push_deliveries');
    }

    protected function load_transport(): bool
    {
        $path = APPPATH . 'third_party/web_push/vendor/autoload.php';
        if (!is_file($path)) { return false; }
        $loader = require $path;
        // Preserve the application's existing dependency versions when a namespace is shared.
        if ($loader instanceof Composer\Autoload\ClassLoader) { $loader->unregister(); $loader->register(false); }
        return true;
    }

    protected function key_file(): string
    {
        return dirname(rtrim(FCPATH, '/')) . '/.blondor-private/web-push-vapid.json';
    }

    protected function keys(): array
    {
        $file = $this->key_file();
        return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }

    public function setup(): void
    {
        if (!is_cli()) { throw new RuntimeException('Solo consola.'); }
        if (!$this->load_transport()) { throw new RuntimeException('Falta instalar el transporte Web Push.'); }
        if ($this->keys()) { return; }
        $directory = dirname($this->key_file());
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('No se pudo crear el directorio privado.'); }
        $handle = fopen($directory . '/setup.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new RuntimeException('No se pudo bloquear la configuración.'); }
        try {
            if (!$this->keys()) {
                $keys = Minishlink\WebPush\VAPID::createVapidKeys();
                $temporary = $this->key_file() . '.tmp';
                if (file_put_contents($temporary, json_encode($keys)) === false || !chmod($temporary, 0600)
                    || !rename($temporary, $this->key_file())) { throw new RuntimeException('No se pudieron guardar las claves.'); }
            }
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function available(): bool
    {
        $keys = $this->keys();
        return $this->tables_ready() && !empty($keys['publicKey']) && !empty($keys['privateKey'])
            && is_file(APPPATH . 'third_party/web_push/vendor/autoload.php');
    }

    public function public_key(): string
    {
        return $this->available() ? (string) $this->keys()['publicKey'] : '';
    }

    public function eligible_user(int $user_id, ?int $provider_id = null): bool
    {
        $user = $this->CI->db->select('u.id, r.slug, r.appointments')->from('users u')
            ->join('roles r', 'r.id=u.id_roles')->where('u.id', $user_id)->get()->row_array();
        if (!$user || (int) $user['appointments'] <= 0) { return false; }
        if ($user['slug'] === 'admin') { return true; }
        if ($user['slug'] === 'provider') { return $provider_id === null || $provider_id === $user_id; }
        if ($user['slug'] === 'secretary') {
            return $provider_id === null || $this->CI->db->get_where('secretaries_providers', [
                'id_users_secretary' => $user_id, 'id_users_provider' => $provider_id,
            ])->num_rows() > 0;
        }
        return false;
    }

    public function validate_subscription(array $subscription): array
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $url = parse_url($endpoint);
        $host = strtolower($url['host'] ?? '');
        $allowed = false;
        foreach (['web.push.apple.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'notify.windows.com'] as $vendor) {
            if ($host === $vendor || ($vendor === 'notify.windows.com' && str_ends_with($host, '.' . $vendor))) { $allowed = true; }
        }
        if (!$allowed || strlen($endpoint) > 2048 || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443) || empty($url['path']) || preg_match('/[\x00-\x20]/', $endpoint)) {
            throw new InvalidArgumentException('El navegador entregó una suscripción no válida.');
        }
        $decode = static function ($value): string {
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) { return ''; }
            return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: '';
        };
        $key = (string) ($subscription['keys']['p256dh'] ?? '');
        $auth = (string) ($subscription['keys']['auth'] ?? '');
        $decoded_key = $decode($key);
        if (strlen($decoded_key) !== 65 || $decoded_key[0] !== "\x04" || strlen($decode($auth)) !== 16) {
            throw new InvalidArgumentException('Las claves de la suscripción no son válidas.');
        }
        return ['endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'public_key' => $key, 'auth_token' => $auth];
    }

    public function subscribe(int $user_id, array $subscription): int
    {
        if (!$this->eligible_user($user_id)) { throw new RuntimeException('Sin acceso al backoffice.'); }
        $data = $this->validate_subscription($subscription);
        $db = $this->CI->db;
        $db->trans_begin();
        try {
            $db->query('SELECT id FROM `' . $db->dbprefix('users') . '` WHERE id=? FOR UPDATE', [$user_id]);
            $existing = $db->get_where('push_subscriptions', ['endpoint_hash' => $data['endpoint_hash']])->row_array();
            if ((!$existing || (int) $existing['id_users'] !== $user_id || !$existing['enabled']) && $db->where('id_users', $user_id)->where('enabled', 1)->count_all_results('push_subscriptions') >= 5) {
                throw new RuntimeException('Ya tienes cinco dispositivos activos. Desactiva uno antes de agregar otro.');
            }
            $now = date('Y-m-d H:i:s');
            $data += ['id_users' => $user_id, 'enabled' => 1, 'update_datetime' => $now];
            $fields = array_keys($data + ['create_datetime' => $now]);
            $values = array_values($data + ['create_datetime' => $now]);
            $updates = array_map(fn($field) => "`$field`=VALUES(`$field`)", array_keys($data));
            $db->query('INSERT INTO `' . $db->dbprefix('push_subscriptions') . '` (`' . implode('`,`', $fields) . '`)' .
                ' VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates), $values);
            if (!$db->trans_status()) { throw new RuntimeException('No se pudo guardar el dispositivo.'); }
            $row = $db->get_where('push_subscriptions', ['endpoint_hash' => $data['endpoint_hash']])->row_array();
            $db->trans_commit();
            return (int) $row['id'];
        } catch (Throwable $e) { $db->trans_rollback(); throw $e; }
    }

    public function active_for_user(int $user_id, string $endpoint): ?array
    {
        if (!$this->tables_ready() || $endpoint === '') { return null; }
        return $this->CI->db->get_where('push_subscriptions', [
            'id_users' => $user_id, 'endpoint_hash' => hash('sha256', $endpoint), 'enabled' => 1,
        ])->row_array() ?: null;
    }

    public function disable(int $user_id, string $endpoint): void
    {
        if (!$this->tables_ready()) { return; }
        $this->CI->db->update('push_subscriptions', ['enabled' => 0, 'update_datetime' => date('Y-m-d H:i:s')], [
            'id_users' => $user_id, 'endpoint_hash' => hash('sha256', $endpoint),
        ]);
    }

    public function disable_session(): void
    {
        $id = (int) session('push_subscription_id');
        if ($id && $this->tables_ready()) {
            $this->CI->db->update('push_subscriptions', ['enabled' => 0], ['id' => $id, 'id_users' => (int) session('user_id')]);
        }
    }

    /** Called in the appointment transaction, before any HTTP or email. */
    public function enqueue(int $appointment_id, string $event_key, string $event_type): void
    {
        if (!$this->tables_ready()) { return; }
        $appointment = $this->CI->db->get_where('appointments', ['id' => $appointment_id])->row_array();
        if (!$appointment) { return; }
        $subscriptions = $this->CI->db->get_where('push_subscriptions', ['enabled' => 1])->result_array();
        $now = date('Y-m-d H:i:s');
        foreach ($subscriptions as $subscription) {
            if (!$this->eligible_user((int) $subscription['id_users'], (int) $appointment['id_users_provider'])) { continue; }
            $this->CI->db->query('INSERT IGNORE INTO `' . $this->CI->db->dbprefix('push_deliveries') . '`' .
                ' (id_subscription,id_users,id_appointments,event_key,event_type,status,attempts,available_at,create_datetime,update_datetime)' .
                ' VALUES (?,?,?,?,?,\'pending\',0,?,?,?)', [
                    $subscription['id'], $subscription['id_users'], $appointment_id, $event_key, $event_type, $now, $now, $now,
                ]);
        }
    }

    public function set_transport(?callable $transport): void { $this->transport = $transport; }

    protected function vapid_subject(): string
    {
        $email = (string) setting('company_email');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $email : base_url('', 'https');
    }

    protected function appointment_url(int $appointment_id): string
    {
        $host = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
        $protocol = in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? null : 'https';
        return site_url('backoffice_push/open/' . $appointment_id, $protocol);
    }

    protected function send(array $subscription, array $payload): array
    {
        if ($this->transport) { return ($this->transport)($subscription, $payload); }
        if (str_starts_with($this->CI->db->database, 'agenda_payments_test_')) {
            throw new RuntimeException('Se requiere un doble de push en una base aislada.');
        }
        $this->load_transport();
        $keys = $this->keys();
        $reporting = error_reporting();
        error_reporting($reporting & ~E_USER_NOTICE); // Optional BCMath/GMP advice must not corrupt HTTP redirects.
        try {
        $sender = new Minishlink\WebPush\WebPush(['VAPID' => [
            'subject' => $this->vapid_subject(), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 86400, 'urgency' => 'normal'], 8, ['allow_redirects' => false, 'connect_timeout' => 4]);
        } finally { error_reporting($reporting); }
        $target = Minishlink\WebPush\Subscription::create(['endpoint' => $subscription['endpoint'], 'contentEncoding' => 'aes128gcm', 'keys' => [
            'p256dh' => $subscription['public_key'], 'auth' => $subscription['auth_token'],
        ]]);
        $report = $sender->sendOneNotification($target, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return ['success' => $report->isSuccess(), 'expired' => $report->isSubscriptionExpired()];
    }

    public function dispatch(int $limit = 20): int
    {
        if (!$this->tables_ready() || (!$this->transport && !$this->available())) { return 0; }
        $db = $this->CI->db;
        $now = date('Y-m-d H:i:s');
        $jobs = $db->where('status', 'pending')->where('available_at <=', $now)->order_by('id')->limit($limit)->get('push_deliveries')->result_array();
        $sent = 0;
        foreach ($jobs as $job) {
            $db->query('UPDATE `' . $db->dbprefix('push_deliveries') . '` SET claim_expires_at=? WHERE id=? AND status=\'pending\'' .
                ' AND available_at <= ? AND attempts=? AND (claim_expires_at IS NULL OR claim_expires_at < ?)',
                [date('Y-m-d H:i:s', time() + 120), $job['id'], $now, $job['attempts'], $now]);
            if ($db->affected_rows() !== 1) { continue; }
            $subscription = $db->get_where('push_subscriptions', ['id' => $job['id_subscription'], 'enabled' => 1, 'id_users' => $job['id_users']])->row_array();
            $appointment = $db->get_where('appointments', ['id' => $job['id_appointments']])->row_array();
            $discard = !$subscription || !$appointment || !$this->eligible_user((int) $job['id_users'], (int) ($appointment['id_users_provider'] ?? 0))
                || (time() - strtotime($job['create_datetime']) > 86400)
                || (($appointment['status'] ?? '') === 'Cancelado' && $job['event_type'] !== 'cancelled');
            if ($discard) { $db->update('push_deliveries', ['status' => 'discarded', 'claim_expires_at' => null], ['id' => $job['id']]); continue; }
            try {
                // Revalidate stored endpoints before every HTTP request; never follow redirects.
                $this->validate_subscription(['endpoint' => $subscription['endpoint'], 'keys' => ['p256dh' => $subscription['public_key'], 'auth' => $subscription['auth_token']]]);
                $title = match ($job['event_type']) {
                    'moved' => 'Una cita cambió de horario', 'cancelled' => 'Una cita fue cancelada', default => 'Nueva reserva confirmada',
                };
                $result = $this->send($subscription, ['title' => 'Blondor · ' . $title,
                    'body' => 'Abre tu agenda para ver el detalle.', 'tag' => 'blondor-' . $job['event_key'],
                    'url' => $this->appointment_url((int) $appointment['id']),
                ]);
                if (!empty($result['expired'])) { $db->update('push_subscriptions', ['enabled' => 0], ['id' => $subscription['id']]); }
                if (!empty($result['success'])) {
                    $db->update('push_deliveries', ['status' => 'sent', 'claim_expires_at' => null, 'update_datetime' => $now], ['id' => $job['id']]);
                    $db->update('push_subscriptions', ['last_success_at' => $now], ['id' => $subscription['id']]);
                    $sent++; continue;
                }
                if (!empty($result['expired'])) { $db->update('push_deliveries', ['status' => 'discarded', 'claim_expires_at' => null], ['id' => $job['id']]); continue; }
                throw new RuntimeException('El servicio push no aceptó el aviso.');
            } catch (Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                $db->update('push_deliveries', ['status' => $attempts >= 8 ? 'failed' : 'pending', 'attempts' => $attempts,
                    'claim_expires_at' => null, 'available_at' => date('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** $attempts)),
                    'update_datetime' => $now], ['id' => $job['id']]);
                // Endpoints, tokens and client data must never appear in logs.
                log_message('error', 'Backoffice push: entrega ' . (int) $job['id'] . ' pendiente de reintento.');
            }
        }
        return $sent;
    }
}
