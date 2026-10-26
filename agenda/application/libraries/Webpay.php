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
        return ($response['status'] ?? '') === 'AUTHORIZED' && (int) ($response['response_code'] ?? -1) === 0;
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
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

        $response = json_decode($raw, true);

        if ($status >= 400 || !is_array($response)) {
            $message = is_array($response) ? $response['error_message'] ?? $raw : $raw;

            throw new RuntimeException('Webpay respondió ' . $status . ': ' . $message);
        }

        return $response;
    }
}
