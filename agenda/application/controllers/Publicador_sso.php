<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Blondor - Entrar al Publicador con la sesión de la agenda.
 *
 * Un administrador con sesión en la agenda recibe un pase firmado de un solo uso (60 s) y se le redirige a
 * Publicador, que lo cambia por su propia cookie de sesión. Sin sesión, pasa por el login y vuelve aquí.
 * Formato del pase: base64url(JSON) . "." . base64url(HMAC-SHA256), con Config::PUBLICADOR_SSO_SECRET
 * (la misma clave que PUBLICADOR_SSO_SECRET en el .env del Publicador).
 */
class Publicador_sso extends EA_Controller
{
    public function index(): void
    {
        $this->output->set_header('Cache-Control: no-store, private');
        $this->output->set_header('Referrer-Policy: no-referrer');

        if (!session('user_id')) {
            session(['dest_url' => site_url('publicador_sso')]);
            redirect('login');
            return;
        }
        if (cannot('view', PRIV_SYSTEM_SETTINGS)) {
            abort(403, 'Solo los administradores pueden entrar al Publicador.');
        }

        $secret = defined('Config::PUBLICADOR_SSO_SECRET') ? (string) Config::PUBLICADOR_SSO_SECRET : '';
        $url = defined('Config::PUBLICADOR_URL') && Config::PUBLICADOR_URL ? rtrim((string) Config::PUBLICADOR_URL, '/') : '';
        if (strlen($secret) < 32 || $url === '') {
            abort(503, 'El acceso al Publicador no está configurado.');
        }

        $user_id = (int) session('user_id');
        $now = time();
        $token = self::sign_token([
            'typ' => 'agenda-sso',
            'sub' => (string) $user_id,
            'name' => (string) $this->accounts->get_user_display_name($user_id),
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(16)),
        ], $secret);

        redirect($url . '/api/v1/auth/agenda-sso?token=' . rawurlencode($token), 'location', 303);
    }

    public static function sign_token(array $payload, string $secret): string
    {
        $body = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $secret, true));
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
