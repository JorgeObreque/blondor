<?php defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__ . '/payments_selftest.php';

/** Isolated DB, fake payment/notification transports, real encryption through Guzzle MockHandler. */
class Push_selftest extends Payments_selftest
{
    private int $admin_id;
    private int $other_provider_id;
    private array $sent = [];

    public function run(): array
    {
        $this->set_up();
        $this->CI->load->library('staff_push');
        require_once APPPATH . 'third_party/web_push/vendor/autoload.php';
        $this->admin_id = $this->make_staff('zz_test_push_admin', 1);
        $this->other_provider_id = $this->make_staff('zz_test_push_other', 2);
        try {
            foreach (['test_cli_identity_and_links', 'test_endpoint_validation', 'test_encryption_with_mock_http', 'test_staff_only_and_idempotent_subscription',
                'test_payment_push_and_mail_failure', 'test_expiration_and_retry', 'test_owner_and_permission_recheck',
                'test_disable_only_affects_owner'] as $test) {
                try { $this->$test(); $this->passed++; }
                catch (Throwable $e) { $this->failures[] = $test . ': ' . $e->getMessage(); }
            }
        } finally {
            $this->CI->db->query('DELETE e FROM `' . $this->CI->db->dbprefix('outbox_events') . '` e JOIN `' . $this->CI->db->dbprefix('appointments') . '` a ON a.id=e.id_appointments WHERE a.id_users_provider=?', [$this->fixtures['provider_id']]);
            $this->CI->staff_push->set_transport(null);
            $this->CI->db->empty_table('push_deliveries');
            $this->CI->db->empty_table('push_subscriptions');
            $this->tear_down();
        }
        return ['passed' => $this->passed, 'failed' => count($this->failures), 'failures' => $this->failures];
    }

    private function make_staff(string $name, int $role): int
    {
        $this->CI->db->insert('users', ['first_name' => $name, 'last_name' => 'Test', 'email' => $name . '@blondor.test',
            'phone_number' => '+56900000002', 'id_roles' => $role, 'timezone' => 'America/Santiago', 'language' => 'spanish']);
        $id = (int) $this->CI->db->insert_id();
        $this->CI->db->insert('user_settings', ['id_users' => $id, 'notifications' => '0', 'google_sync' => 0, 'caldav_sync' => 0]);
        return $id;
    }

    private function subscription(string $key): array
    {
        $keys = Minishlink\WebPush\VAPID::createVapidKeys();
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/zz-test-' . $key, 'contentEncoding' => 'aes128gcm',
            'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]];
    }

    private function fake_push(string $scenario = 'ok'): void
    {
        $this->CI->staff_push->set_transport(function ($subscription, $payload) use ($scenario) {
            $active = $this->CI->db->query('SELECT @@in_transaction AS active')->row_array();
            $this->equals(0, (int) $active['active'], 'Push siempre fuera de la transacción');
            $this->sent[] = $payload;
            return ['success' => $scenario === 'ok', 'expired' => $scenario === 'expired'];
        });
    }

    private function paid_appointment(string $key): array
    {
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'push' . $key);
        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestpush' . $key);
        $token = array_key_first($this->transactions);
        $this->CI->booking_payments->handle_webpay_return(['token_ws' => $token]);
        return $this->appointment_of($booking['hold_token']);
    }

    protected function test_cli_identity_and_links(): void
    {
        $subject = (new ReflectionMethod(Staff_push::class, 'vapid_subject'))->invoke($this->CI->staff_push);
        $this->ok(str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL),
            'La identidad VAPID no depende del esquema HTTP inferido por la consola');
        $original = config('base_url');
        config(['base_url' => 'http://agenda.blondor.cl/']);
        try {
            $url = (new ReflectionMethod(Staff_push::class, 'appointment_url'))->invoke($this->CI->staff_push, 42);
            $this->ok(str_starts_with($url, 'https://agenda.blondor.cl/'), 'El enlace del cron conserva HTTPS');
            $this->ok(str_ends_with($url, '/backoffice_push/open/42'), 'El aviso abre la cita autenticada');
        } finally { config(['base_url' => $original]); }
    }

    protected function test_endpoint_validation(): void
    {
        foreach (['http://127.0.0.1/private', 'https://web.push.apple.com.evil.test/test',
            'https://user:pass@web.push.apple.com/token', 'https://web.push.apple.com:8443/test'] as $url) {
            $subscription = $this->subscription('bad'); $subscription['endpoint'] = $url;
            $failed = false;
            try { $this->CI->staff_push->validate_subscription($subscription); } catch (InvalidArgumentException $e) { $failed = true; }
            $this->ok($failed, 'No se acepta SSRF o endpoint ajeno');
        }
        $this->ok($this->CI->staff_push->validate_subscription($this->subscription('valid')), 'Endpoint y claves válidas');
    }

    protected function test_encryption_with_mock_http(): void
    {
        $history = [];
        $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(201)]));
        $stack->push(GuzzleHttp\Middleware::history($history));
        $sender = new Minishlink\WebPush\WebPush(['VAPID' => Minishlink\WebPush\VAPID::createVapidKeys() + ['subject' => (new ReflectionMethod(Staff_push::class, 'vapid_subject'))->invoke($this->CI->staff_push)]],
            ['TTL' => 86400, 'urgency' => 'normal'], 8, ['handler' => $stack, 'allow_redirects' => false]);
        $report = $sender->sendOneNotification(Minishlink\WebPush\Subscription::create($this->subscription('crypto')), '{"title":"Blondor"}');
        $this->ok($report->isSuccess(), 'El transporte cifra y firma con las dependencias existentes');
        $request = $history[0]['request'];
        $this->ok(str_contains($request->getHeaderLine('Authorization'), 'vapid'), 'VAPID firmado');
        $this->equals('aes128gcm', $request->getHeaderLine('Content-Encoding'), 'Carga cifrada');
        $this->ok(!str_contains((string) $request->getBody(), 'Blondor'), 'El título no sale en texto plano');
    }

    protected function test_staff_only_and_idempotent_subscription(): void
    {
        $subscription = $this->subscription('same');
        $failed = false;
        try { $this->CI->staff_push->subscribe($this->fixtures['customer_id'], $subscription); }
        catch (RuntimeException $e) { $failed = true; }
        $this->ok($failed, 'Una clienta no registra dispositivos del backoffice');
        $id = $this->CI->staff_push->subscribe($this->admin_id, $subscription);
        $this->equals($id, $this->CI->staff_push->subscribe($this->admin_id, $subscription), 'Mismo dispositivo no se duplica');
        $this->CI->staff_push->disable($this->admin_id, $subscription['endpoint']);
    }

    protected function test_payment_push_and_mail_failure(): void
    {
        $admin = $this->subscription('admin'); $other = $this->subscription('other-provider');
        $this->CI->staff_push->subscribe($this->admin_id, $admin);
        $this->CI->staff_push->subscribe($this->other_provider_id, $other);
        $this->fake_push(); $this->sent = [];
        $this->fake_webpay('authorized');
        $booking = $this->prepare('exact', 'pushmail');
        $this->equals(0, $this->CI->db->where('status', 'pending')->count_all_results('push_deliveries'), 'No hay push antes del pago');
        $this->CI->booking_payments->start_payment($booking['hold_token'], 'deposit', 'zztestpushmail');
        $push_instance = $this->CI->staff_push;
        $this->ok((new ReflectionProperty(Staff_push::class, 'transport'))->getValue($this->CI->staff_push) !== null, 'Doble instalado antes del callback');
        $normal = $this->CI->notifications;
        $this->CI->notifications = new class { public function __call($name, $args) { throw new RuntimeException('Correo simulado falló'); } };
        try {
            $token = array_key_first($this->transactions);
            $result = $this->CI->booking_payments->handle_webpay_return(['token_ws' => $token]);
            $this->equals('paid', $result['status'], 'El correo fallido no invalida el pago');
            $this->ok($push_instance === $this->CI->staff_push, 'La instancia del doble no cambió');
            $this->ok((new ReflectionProperty(Staff_push::class, 'transport'))->getValue($this->CI->staff_push) !== null, 'Doble conservado tras callback');
            $this->equals(1, count($this->sent), 'Admin recibe push aunque falle el correo; profesional ajeno no');
            $this->ok(!str_contains(json_encode($this->sent[0]), '@blondor.test'), 'Sin correo de clienta en la carga');
            $this->CI->booking_payments->handle_webpay_return(['token_ws' => $token]);
            $this->equals(1, count($this->sent), 'Callback repetido no reenvía el push');
        } finally { $this->CI->notifications = $normal; }
        $this->CI->staff_push->disable($this->admin_id, $admin['endpoint']);
        $this->CI->staff_push->disable($this->other_provider_id, $other['endpoint']);
    }

    protected function test_expiration_and_retry(): void
    {
        $subscription = $this->subscription('retry');
        $this->CI->staff_push->subscribe($this->admin_id, $subscription);
        $this->fake_push('fail');
        $appointment = $this->paid_appointment('retry');
        $job = $this->CI->db->get_where('push_deliveries', ['id_appointments' => $appointment['id']])->row_array();
        $this->equals('pending', $job['status'], 'Un fallo se conserva para cron');
        $this->equals(1, $job['attempts'], 'Se cuenta el intento');
        $this->ok($job['available_at'] > date('Y-m-d H:i:s'), 'Reintento con espera');
        $this->CI->db->update('push_deliveries', ['available_at' => date('Y-m-d H:i:s', time()-60)], ['id' => $job['id']]);
        $this->fake_push('expired'); $this->CI->staff_push->dispatch();
        $this->equals(null, $this->CI->staff_push->active_for_user($this->admin_id, $subscription['endpoint']), '410/404 desactiva el dispositivo vencido');
        $this->equals('discarded', $this->CI->db->get_where('push_deliveries', ['id' => $job['id']])->row_array()['status'], 'No reintenta tokens vencidos');
    }

    protected function test_owner_and_permission_recheck(): void
    {
        $subscription = $this->subscription('owner');
        $id = $this->CI->staff_push->subscribe($this->admin_id, $subscription);
        $this->fake_push('fail'); $appointment = $this->paid_appointment('owner');
        $this->CI->staff_push->subscribe($this->other_provider_id, $subscription);
        $this->CI->db->update('push_deliveries', ['available_at' => date('Y-m-d H:i:s', time()-60)], ['id_subscription' => $id]);
        $this->fake_push(); $this->sent = []; $this->CI->staff_push->dispatch();
        $this->equals(0, count($this->sent), 'Cambiar de cuenta no recibe avisos del dueño anterior');
        $this->CI->staff_push->subscribe($this->admin_id, $subscription);
        $this->CI->staff_push->enqueue($appointment['id'], 'zz_test_permission', 'created');
        $this->CI->db->update('users', ['id_roles' => 3], ['id' => $this->admin_id]);
        try { $this->CI->staff_push->dispatch(); $this->equals(0, count($this->sent), 'Revalida permisos antes de enviar'); }
        finally { $this->CI->db->update('users', ['id_roles' => 1], ['id' => $this->admin_id]); }
        $this->CI->staff_push->disable($this->admin_id, $subscription['endpoint']);
    }

    protected function test_disable_only_affects_owner(): void
    {
        $subscription = $this->subscription('disable');
        $this->CI->staff_push->subscribe($this->admin_id, $subscription);
        $this->CI->staff_push->disable($this->other_provider_id, $subscription['endpoint']);
        $this->ok($this->CI->staff_push->active_for_user($this->admin_id, $subscription['endpoint']), 'Otra cuenta no puede desactivar el dispositivo');
        $this->CI->staff_push->disable($this->admin_id, $subscription['endpoint']);
        $this->equals(null, $this->CI->staff_push->active_for_user($this->admin_id, $subscription['endpoint']), 'La dueña sí puede desactivarlo');
    }
}
