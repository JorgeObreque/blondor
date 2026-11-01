<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Webpay Plus (Transbank), API REST v1.2
 *
 * Tres llamadas, sin SDK: crear la transacción, confirmarla al volver de Webpay y consultar su estado.
 * Las credenciales van en config.php (WEBPAY_ENVIRONMENT, WEBPAY_COMMERCE_CODE, WEBPAY_API_KEY). Sin ellas el pago
 * online queda desactivado: nunca se cae al ambiente de pruebas en producción.
 *
 * Documentación: https://www.transbankdevelopers.cl/referencia/webpay
 * ---------------------------------------------------------------------------- */

class Webpay
{
    const HOSTS = [
        'integration' => 'https://webpay3gint.transbank.cl',
        'production' => 'https://webpay3g.transbank.cl',
    ];

    const PATH = '/rswebpaytransaction/api/webpay/v1.2/transactions';

    /**
     * Transport for the tests: a function that answers like Transbank (no real money).
     *
     * @var callable|null
     */
    protected $transport = null;

    /**
     * Whether Webpay is configured for this environment.
     */
    public function is_enabled(): bool
    {
        return defined('Config::WEBPAY_ENVIRONMENT') &&
            isset(self::HOSTS[Config::WEBPAY_ENVIRONMENT]) &&
            defined('Config::WEBPAY_COMMERCE_CODE') &&
            Config::WEBPAY_COMMERCE_CODE !== '' &&
            defined('Config::WEBPAY_API_KEY') &&
            Config::WEBPAY_API_KEY !== '';
    }

    public function environment(): string
    {
        return $this->is_enabled() ? Config::WEBPAY_ENVIRONMENT : '';
    }

    /**
     * Use a different transport (the tests answer like Transbank without moving money).
     *
     * The transport receives (method, path, body) and answers ['status' => int, 'body' => array|string].
     *
     * @param callable|null $transport
     */
    public function set_transport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Start a payment.
     *
     * @return array {token, url}: the browser must POST token_ws to url.
     */
    public function create(string $buy_order, string $session_id, int $amount, string $return_url): array
    {
        return $this->request('POST', self::PATH, [
            'buy_order' => $buy_order,
            'session_id' => $session_id,
            'amount' => $amount,
            'return_url' => $return_url,
        ]);
    }

    /**
     * Confirm the payment when the customer returns from Webpay. Only call it once per token.
     *
     * @return array Transbank response (status, response_code, amount, buy_order, card_detail…).
     */
    public function commit(string $token): array
    {
        return $this->request('PUT', self::PATH . '/' . rawurlencode($token));
    }

    /**
     * Current state of a transaction (also after commit).
     */
    public function status(string $token): array
    {
        return $this->request('GET', self::PATH . '/' . rawurlencode($token));
    }

    /**
     * A committed transaction is paid when Transbank authorized it (status AUTHORIZED and response_code 0).
     */
    public static function is_authorized(array $response): bool
    {
        return ($response['status'] ?? '') === 'AUTHORIZED'
            && in_array($response['response_code'] ?? null, [0, '0'], true);
    }

    /**
     * The state of a transaction, without throwing when it does not exist.
     *
     * This is what the maintenance job uses to find out whether a payment that was left halfway really happened,
     * without ever assuming that "no answer" means "not paid".
     *
     * @return array {state: string, response: array, message: string}
     *   state: authorized | rejected | aborted | pending | not_found | error
     */
    public function state(string $token): array
    {
        if (!$this->is_enabled()) {
            return ['state' => 'error', 'response' => [], 'message' => 'Webpay no está configurado.'];
        }

        try {
            $answer = $this->raw('GET', self::PATH . '/' . rawurlencode($token));
        } catch (Throwable $e) {
            return ['state' => 'error', 'response' => [], 'message' => $e->getMessage()];
        }

        $status = (int) $answer['status'];
        $body = $answer['body'];

        $response = is_array($body) ? $body : json_decode((string) $body, true);

        if (!is_array($response)) {
            return ['state' => 'error', 'response' => [], 'message' => 'Respuesta ilegible de Webpay.'];
        }

        if ($status === 404) {
            // En Transbank no existe esta transacción: no se cobró nada.
            return ['state' => 'not_found', 'response' => $response, 'message' => 'La transacción no existe.'];
        }

        if ($status >= 400) {
            return [
                'state' => 'error',
                'response' => $response,
                'message' => $response['error_message'] ?? ('Webpay respondió ' . $status),
            ];
        }

        $transaction_status = (string) ($response['status'] ?? '');

        if ($transaction_status === 'AUTHORIZED' || $transaction_status === 'COMPLETED') {
            return ['state' => 'authorized', 'response' => $response, 'message' => ''];
        }

        if ($transaction_status === 'INITIALIZED') {
            return ['state' => 'pending', 'response' => $response, 'message' => ''];
        }

        if (in_array($transaction_status, ['ABORTED', 'REJECTED', 'INVALID', 'FAILED', 'REVERSED', 'REFUNDED'], true)) {
            return [
                'state' => $transaction_status === 'ABORTED' ? 'aborted' : 'rejected',
                'response' => $response,
                'message' => '',
            ];
        }

        // PENDING_REVIEW, UNKNOWN y compañía: no se puede afirmar que no se cobró.
        return ['state' => 'unknown', 'response' => $response, 'message' => $transaction_status];
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->is_enabled()) {
            throw new RuntimeException('El pago con Webpay no está configurado.');
        }

        $answer = $this->raw($method, $path, $body);

        $status = (int) $answer['status'];
        $response = is_array($answer['body']) ? $answer['body'] : json_decode((string) $answer['body'], true);

        if ($status >= 400 || !is_array($response)) {
            $message = is_array($response) ? $response['error_message'] ?? json_encode($response) : (string) $response;

            throw new RuntimeException('Webpay respondió ' . $status . ': ' . $message);
        }

        return $response;
    }

    /**
     * The raw answer of the payment provider: the HTTP status and the body.
     *
     * @return array {status: int, body: array|string}
     */
    protected function raw(string $method, string $path, ?array $body = null): array
    {
        if ($this->transport) {
            return ($this->transport)($method, $path, $body);
        }

        if (!$this->is_enabled()) {
            throw new RuntimeException('El pago con Webpay no está configurado.');
        }

        $curl = curl_init(self::HOSTS[Config::WEBPAY_ENVIRONMENT] . $path);

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Tbk-Api-Key-Id: ' . Config::WEBPAY_COMMERCE_CODE,
                'Tbk-Api-Key-Secret: ' . Config::WEBPAY_API_KEY,
                'Content-Type: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('No hay conexión con Webpay: ' . $error);
        }

        return ['status' => (int) $status, 'body' => $raw];
    }
}
