<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Gift cards: compra online con Webpay Plus
 *
 *   blondor.cl (formulario) → POST gift_cards/checkout → Webpay → gift_cards/webpay_return → gift_cards/ver/{hash}
 *
 * El pago se confirma siempre con Transbank desde el servidor (commit); el navegador nunca decide si se pagó.
 * Sin CSRF (config.php: csrf_exclude_uris): el formulario viene de blondor.cl y Webpay vuelve con un POST propio.
 * ---------------------------------------------------------------------------- */

class Gift_cards extends EA_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('gift_cards_model');
        $this->load->library('webpay');
        $this->load->library('email');
    }

    /**
     * Start the purchase: save the gift card as pending and send the buyer to Webpay.
     */
    public function checkout(): void
    {
        try {
            if (!$this->webpay->is_enabled()) {
                throw new RuntimeException('La venta online de gift cards no está disponible en este momento.');
            }

            $purchase = $this->gift_cards_model->validate_purchase($this->input->post() ?? []);
            $gift_card = $this->gift_cards_model->create_pending($purchase, 'webpay');

            $transaction = $this->webpay->create(
                $gift_card['buy_order'],
                $gift_card['hash'],
                $gift_card['amount'],
                site_url('gift_cards/webpay_return'),
            );

            $this->gift_cards_model->set_payment_token($gift_card['id'], $transaction['token']);

            html_vars([
                'page_title' => 'Ir a Webpay',
                'redirect_url' => $transaction['url'],
                'token' => $transaction['token'],
            ]);

            $this->load->view('pages/gift_card_redirect');
        } catch (InvalidArgumentException $e) {
            $this->show_problem($e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Gift card checkout: ' . $e->getMessage());

            $this->show_problem('No pudimos iniciar el pago. Inténtalo de nuevo en unos minutos.');
        }
    }

    /**
     * Webpay sends the buyer back here: confirm the payment with Transbank and show the gift card.
     *
     * - Paid or rejected: token_ws.
     * - Cancelled on the Webpay form: TBK_TOKEN (+ TBK_ORDEN_COMPRA, TBK_ID_SESION), no token_ws.
     * - Timeout on the form: only TBK_ORDEN_COMPRA and TBK_ID_SESION.
     */
    public function webpay_return(): void
    {
        $token = (string) (request('token_ws') ?? '');
        $abort_token = (string) (request('TBK_TOKEN') ?? '');
        $buy_order = (string) (request('TBK_ORDEN_COMPRA') ?? '');

        try {
            if ($token === '' || $abort_token !== '') {
                // Cancelled or expired before paying: nothing was charged.
                $gift_card =
                    $this->gift_cards_model->find_by('payment_token', $abort_token) ??
                    $this->gift_cards_model->find_by('buy_order', $buy_order);

                if ($gift_card) {
                    $this->gift_cards_model->mark_failed($gift_card['id'], ['aborted' => true]);
                    redirect('gift_cards/ver/' . $gift_card['hash']);

                    return;
                }

                $this->show_problem('El pago se canceló y no se hizo ningún cargo.');

                return;
            }

            $gift_card = $this->gift_cards_model->find_by('payment_token', $token);

            if (!$gift_card) {
                $this->show_problem('No encontramos esta compra. Si se hizo un cargo, escríbenos por WhatsApp.');

                return;
            }

            if ($gift_card['status'] === 'pending') {
                $response = $this->webpay->commit($token);

                $paid =
                    Webpay::is_authorized($response) &&
                    (int) ($response['amount'] ?? 0) === $gift_card['amount'] &&
                    ($response['buy_order'] ?? '') === $gift_card['buy_order'];

                if ($paid) {
                    [$gift_card, $activated] = $this->gift_cards_model->activate($gift_card['id'], $response);

                    if ($activated) {
                        $this->send_emails($gift_card);
                    }
                } else {
                    $this->gift_cards_model->mark_failed($gift_card['id'], $response);
                }
            }

            redirect('gift_cards/ver/' . $gift_card['hash']);
        } catch (Throwable $e) {
            log_message('error', 'Gift card webpay_return: ' . $e->getMessage());

            $this->show_problem(
                'No pudimos confirmar el pago con Webpay. Si se hizo un cargo, escríbenos por WhatsApp y lo revisamos.',
            );
        }
    }

    /**
     * The gift card page: paid (with its code), waiting, or rejected.
     */
    public function ver(string $hash = ''): void
    {
        $gift_card = $this->gift_cards_model->find_by('hash', $hash);

        if (!$gift_card) {
            $this->show_problem('No encontramos esta gift card.');

            return;
        }

        html_vars([
            'page_title' => 'Gift card Blondor',
            'gift_card' => $gift_card,
            'site_url' => self::site_base_url(),
        ]);

        $this->load->view('pages/gift_card');
    }

    /**
     * blondor.cl in production, the local site (:8087) when the agenda runs on localhost.
     */
    public static function site_base_url(): string
    {
        return str_contains(Config::BASE_URL, 'localhost') ? 'http://localhost:8087' : 'https://blondor.cl';
    }

    protected function show_problem(string $message): void
    {
        html_vars([
            'page_title' => 'Gift card Blondor',
            'problem' => $message,
            'site_url' => self::site_base_url(),
        ]);

        $this->load->view('pages/gift_card');
    }

    /**
     * Send the gift card to the buyer and, if given, to the person who receives it.
     */
    protected function send_emails(array $gift_card): void
    {
        $recipients = [['email' => $gift_card['buyer_email'], 'for_buyer' => true]];

        if ($gift_card['recipient_email'] && $gift_card['recipient_email'] !== $gift_card['buyer_email']) {
            $recipients[] = ['email' => $gift_card['recipient_email'], 'for_buyer' => false];
        }

        foreach ($recipients as $recipient) {
            try {
                $html = $this->load->view(
                    'emails/gift_card_email',
                    [
                        'gift_card' => $gift_card,
                        'for_buyer' => $recipient['for_buyer'],
                        'view_url' => site_url('gift_cards/ver/' . $gift_card['hash']),
                        'booking_url' => site_url('booking'),
                    ],
                    true,
                );

                $company_email = setting('company_email');

                $this->email->clear();
                $this->email->from(config('from_address') ?: $company_email, config('from_name') ?: 'Blondor');

                if ($company_email) {
                    $this->email->reply_to(config('reply_to') ?: $company_email);
                }

                $this->email->to($recipient['email']);
                $this->email->subject(
                    $recipient['for_buyer']
                        ? 'Tu gift card Blondor está lista'
                        : ($gift_card['buyer_name'] ?: 'Alguien') . ' te regaló una gift card Blondor',
                );
                $this->email->message($html);

                if (!$this->email->send(false)) {
                    throw new RuntimeException($this->email->print_debugger());
                }
            } catch (Throwable $e) {
                // The gift card is already paid and visible on its page: a failed email must not break the purchase.
                log_message('error', 'Gift card email to ' . $recipient['email'] . ': ' . $e->getMessage());
            }
        }
    }
}
