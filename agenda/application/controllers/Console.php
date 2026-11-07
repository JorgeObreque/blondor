<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.2
 * ---------------------------------------------------------------------------- */

require_once __DIR__ . '/Google.php';

/**
 * Console controller.
 *
 * Handles all the Console related operations.
 */
class Console extends EA_Controller
{
    /**
     * Console constructor.
     */
    public function __construct()
    {
        if (!is_cli()) {
            exit('No direct script access allowed');
        }

        parent::__construct();

        $this->load->dbutil();

        $this->load->library('instance');

        $this->load->model('admins_model');
        $this->load->model('customers_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('settings_model');
    }

    /**
     * Perform a console installation.
     *
     * Use this method to install Easy!Appointments directly from the terminal.
     *
     * Usage:
     *
     * php index.php console install
     *
     * @throws Exception
     */
    public function install(): void
    {
        $this->instance->migrate('fresh');

        $password = $this->instance->seed();

        response(
            PHP_EOL . '⇾ Installation completed, login with "administrator" / "' . $password . '".' . PHP_EOL . PHP_EOL,
        );
    }

    /**
     * Migrate the database to the latest state.
     *
     * Use this method to upgrade an Easy!Appointments instance to the latest database state.
     *
     * Notice:
     *
     * Do not use this method to install the app as it will not seed the database with the initial entries (admin,
     * provider, service, settings etc.).
     *
     * Usage:
     *
     * php index.php console migrate
     *
     * php index.php console migrate fresh
     *
     * @param string $type
     */
    public function migrate(string $type = ''): void
    {
        $this->instance->migrate($type);
    }

    /**
     * Seed the database with test data.
     *
     * Use this method to add test data to your database
     *
     * Usage:
     *
     * php index.php console seed
     * @throws Exception
     */
    public function seed(): void
    {
        $this->instance->seed();
    }

    /**
     * Create a database backup file.
     *
     * Use this method to back up your Easy!Appointments data.
     *
     * Usage:
     *
     * php index.php console backup
     *
     * php index.php console backup /path/to/backup/folder
     *
     * @throws Exception
     */
    public function backup(): void
    {
        $this->instance->backup($GLOBALS['argv'][3] ?? null);
    }

    /**
     * Trigger the synchronization of all provider calendars with Google Calendar.
     *
     * Use this method in a cronjob to automatically sync events between Easy!Appointments and Google Calendar.
     *
     * Notice:
     *
     * Google syncing must first be enabled for each individual provider from inside the backend calendar page.
     *
     * Usage:
     *
     * php index.php console sync
     */
    public function sync(): void
    {
        $providers = $this->providers_model->get();

        foreach ($providers as $provider) {
            if (filter_var($provider['settings']['google_sync'], FILTER_VALIDATE_BOOLEAN)) {
                Google::sync((string) $provider['id']);
            }

            if (filter_var($provider['settings']['caldav_sync'], FILTER_VALIDATE_BOOLEAN)) {
                Caldav::sync((string) $provider['id']);
            }
        }
    }

    /**
     * Maintenance of the bookings with payment.
     *
     * Reconciles the payments that were left halfway, expires the holds nobody finished and delivers the pending
     * notices (email, calendar and webhook). It is safe to run as often as needed: every step is idempotent.
     *
     * Usage:
     *
     * php index.php console payments
     */
    public function payments(): void
    {
        $this->load->library('booking_payments');

        $summary = $this->booking_payments->reconcile();

        $this->load->library('booking_reminders');
        $summary['reminders'] = $this->booking_reminders->run();

        // Ficha pendiente: push cada hora a Guille hasta que la complete.
        try {
            $this->load->library('staff_push');
            $summary['record_reminders'] = $this->staff_push->enqueue_record_reminders();
            $this->staff_push->dispatch();
        } catch (Throwable $e) {
            log_message('error', 'Aviso de ficha pendiente: se reintentará en el próximo cron.');
        }

        response(
            PHP_EOL . '⇾ Pagos: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL,
        );
    }

    /** Activate reminder scheduling once, without backfilling old deadlines. */
    public function reminders_setup(): void
    {
        $this->load->library('booking_reminders');
        $this->booking_reminders->setup();
        echo 'Recordatorios de 24 y 2 horas activados, sin envíos de prueba.' . PHP_EOL;
    }

    /** Read-only status: never queues or sends email. */
    public function reminders_status(): void
    {
        $this->load->library('booking_reminders');
        echo json_encode($this->booking_reminders->preview(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    /** Provision VAPID once; keys stay outside the document root and are never printed. */
    public function push_setup(): void
    {
        $this->load->library('staff_push');
        $this->staff_push->setup();
        echo 'Notificaciones push configuradas; claves privadas conservadas en el servidor.' . PHP_EOL;
    }

    /**
     * Self test of the bookings with payment.
     *
     * It uses a Webpay double (no real money, no call to Transbank) and synthetic fixtures that it cleans up.
     * It refuses to run in production.
     *
     * Usage:
     *
     * php index.php console test_payments
     */
    public function test_payments(string $test_database = ''): void
    {
        if (!is_cli() || Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda'
            || !defined('Config::WEBPAY_ENVIRONMENT') || Config::WEBPAY_ENVIRONMENT !== 'integration') {
            echo PHP_EOL . 'El self test no corre en producción.' . PHP_EOL;

            exit(1);
        }

        if (!preg_match('/^agenda_payments_test_[a-f0-9]{12}$/D', $test_database)) {
            echo 'Usa scripts/run_payments_tests.sh para crear una base aislada.' . PHP_EOL;
            exit(1);
        }
        if (!$this->db->db_select($test_database)) {
            throw new RuntimeException('No se pudo abrir la base de pruebas.');
        }

        require_once __DIR__ . '/../../tests/payments_selftest.php';

        $test = new Payments_selftest($this);

        $result = $test->run();

        foreach ($result['failures'] as $failure) {
            echo PHP_EOL . '✗ ' . $failure . PHP_EOL;
        }

        echo PHP_EOL . '⇾ Pruebas de pago: ' . $result['passed'] . ' ok, ' . $result['failed'] . ' con error.' .
            PHP_EOL . PHP_EOL;

        if ($result['failed'] > 0) {
            exit(1);
        }
    }

    /** Isolated push tests. Never use the production DB or real delivery transports. */
    public function test_push(string $database = ''): void
    {
        if (Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda' || Config::WEBPAY_ENVIRONMENT !== 'integration'
            || !preg_match('/^agenda_payments_test_[a-f0-9]{12}$/D', $database)) { exit(1); }
        $this->db->db_select($database);
        require_once APPPATH . '../tests/push_selftest.php';
        $result = (new Push_selftest($this))->run();
        foreach ($result['failures'] as $failure) { echo 'FAIL ' . $failure . PHP_EOL; }
        echo 'Push: ' . $result['passed'] . ' ok, ' . $result['failed'] . ' con error.' . PHP_EOL;
        if ($result['failed']) { exit(1); }
    }

    /** Reminder tests use only the isolated local database and fake email. */
    public function test_reminders(string $database = ''): void
    {
        if (Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda' || Config::WEBPAY_ENVIRONMENT !== 'integration'
            || !preg_match('/^agenda_payments_test_[a-f0-9]{12}$/D', $database)) { exit(1); }
        $this->db->db_select($database);
        require_once APPPATH . '../tests/reminders_selftest.php';
        $result = (new Reminders_selftest($this))->run();
        foreach ($result['failures'] as $failure) { echo 'FAIL ' . $failure . PHP_EOL; }
        echo 'Recordatorios: ' . $result['passed'] . ' ok, ' . $result['failed'] . ' con error.' . PHP_EOL;
        if ($result['failed']) { exit(1); }
    }

    /** Parallel reminder worker: isolated local DB and synthetic mail only. */
    public function reminders_test_worker(string $database = '', string $clock = ''): void
    {
        if (Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda' || Config::WEBPAY_ENVIRONMENT !== 'integration'
            || !preg_match('/^agenda_payments_test_[a-f0-9]{12}$/D', $database)) { exit(1); }
        $this->db->db_select($database);
        $this->load->library('booking_reminders');
        $this->booking_reminders->set_transport(function ($message) use ($database) {
            if (!str_ends_with($message['to'], '@blondor.test')) { throw new RuntimeException('Solo destinatarios sintéticos.'); }
            usleep(200000);
            file_put_contents(sys_get_temp_dir() . '/blondor-reminders-' . $database . '.log',
                hash('sha256', $message['to'] . $message['subject'] . $message['html']) . "\n", FILE_APPEND | LOCK_EX);
        });
        echo json_encode($this->booking_reminders->run(new DateTimeImmutable($clock))) . PHP_EOL;
    }

    /** CLI worker for real parallel callbacks in the isolated self test. */
    public function payments_test_worker(string $database = '', string $token = '', string $action = 'callback'): void
    {
        if (Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda'
            || !defined('Config::WEBPAY_ENVIRONMENT') || Config::WEBPAY_ENVIRONMENT !== 'integration'
            || !preg_match('/^agenda_payments_test_[a-f0-9]{12}$/D', $database)) {
            exit(1);
        }
        $this->db->db_select($database);
        $this->load->library('booking_payments');
        if (in_array($action, ['public', 'internal'], true)) {
            $hold = $this->booking_payments_model->find_by_hash($token);
            if (!$hold || !str_starts_with($hold['idempotency_key'], 'zztest')) { exit(1); }
            $appointment = array_intersect_key($hold, array_flip([
                'id_users_provider', 'id_services', 'start_datetime', 'end_datetime', 'hair_length',
            ]));
            $customer = $this->db->get_where('users', ['email' => 'zz_test_cliente@blondor.test'])->row_array();
            $appointment['id_users_customer'] = (int) $customer['id'];
            $appointment['notes'] = 'zz_test race';
            $appointment['is_unavailability'] = false;
            try {
                if ($action === 'internal') {
                    $this->appointments_model->save($appointment);
                } else {
                    $this->booking_payments->prepare([
                        'appointment' => $appointment,
                        'customer' => json_decode($hold['customer_snapshot'], true), 'manage_mode' => 0,
                    ], ['idempotency_key' => 'zztestrace' . bin2hex(random_bytes(6)), 'ip' => '10.1.2.3',
                        'email' => 'zz_test_race@blondor.test']);
                }
                echo 'won';
            } catch (RuntimeException $e) { echo 'lost'; }
            return;
        }
        $attempt = $this->booking_payments_model->find_attempt_by_token($token);
        if (!$attempt || !str_starts_with($attempt['idempotency_key'], 'zztest')) {
            exit(1);
        }
        foreach (['notifications', 'synchronization', 'webhooks_client'] as $name) {
            $this->$name = new class {
                public function __call($name, $arguments) { return null; }
            };
        }
        $this->webpay->set_transport(function ($method) use ($attempt, $token) {
            if ($method === 'PUT') {
                file_put_contents(sys_get_temp_dir() . '/blondor-payment-' . hash('sha256', $token),
                    "commit\n", FILE_APPEND | LOCK_EX);
                usleep(200000);
            }
            return ['status' => 200, 'body' => [
                'status' => 'AUTHORIZED', 'response_code' => 0, 'amount' => $attempt['amount'],
                'buy_order' => $attempt['buy_order'], 'session_id' => $attempt['session_id'],
            ]];
        });
        $result = $this->booking_payments->handle_webpay_return(['token_ws' => $token]);
        echo $result['status'];
    }

    /**
     * Show help information about the console capabilities.
     *
     * Use this method to see the available commands.
     *
     * Usage:
     *
     * php index.php console help
     */
    public function help(): void
    {
        $help = [
            '',
            'Easy!Appointments ' . config('version'),
            '',
            'Usage:',
            '',
            '⇾ php index.php console [command] [arguments]',
            '',
            'Commands:',
            '',
            '⇾ php index.php console migrate',
            '⇾ php index.php console migrate fresh',
            '⇾ php index.php console migrate up',
            '⇾ php index.php console migrate down',
            '⇾ php index.php console seed',
            '⇾ php index.php console install',
            '⇾ php index.php console backup',
            '⇾ php index.php console sync',
            '⇾ php index.php console payments',
            '⇾ php index.php console test_payments',
            '',
            '',
        ];

        response(implode(PHP_EOL, $help));
    }
}
