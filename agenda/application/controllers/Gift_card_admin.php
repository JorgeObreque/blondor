<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Gift cards en el backoffice (/giftcards)
 *
 * Buscar por código o nombre, ver saldo e historial, canjear todo o una parte y registrar las vendidas en el salón.
 * Mismos permisos que la agenda: ver citas para mirar, editar citas para canjear y crear.
 * ---------------------------------------------------------------------------- */

class Gift_card_admin extends EA_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('gift_cards_model');
        $this->load->library('accounts');
    }

    /**
     * Render the gift cards page.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('giftcards')]);

        $user_id = session('user_id');

        if (cannot('view', PRIV_APPOINTMENTS)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        script_vars([
            'user_id' => $user_id,
            'role_slug' => session('role_slug'),
            'gift_cards' => $this->gift_cards_model->search(),
            'gift_cards_can_edit' => can('edit', PRIV_APPOINTMENTS),
            'gift_cards_amounts' => Gift_cards_model::AMOUNTS,
        ]);

        html_vars([
            'page_title' => 'Gift cards',
            'active_menu' => 'giftcards',
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/gift_card_admin');
    }

    /**
     * Search gift cards by code, names or emails.
     */
    public function search(): void
    {
        try {
            if (cannot('view', PRIV_APPOINTMENTS)) {
                abort(403, 'Forbidden');
            }

            json_response(
                $this->gift_cards_model->search((string) request('keyword', ''), (string) request('status', '')),
            );
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * One gift card with its redemptions.
     */
    public function detail(): void
    {
        try {
            if (cannot('view', PRIV_APPOINTMENTS)) {
                abort(403, 'Forbidden');
            }

            $gift_card = $this->gift_cards_model->find((int) request('id'));

            if (!$gift_card || $gift_card['status'] === 'pending') {
                throw new InvalidArgumentException('No encontramos esta gift card.');
            }

            json_response([
                'gift_card' => $this->gift_cards_model->public_fields($gift_card),
                'redemptions' => $this->gift_cards_model->redemptions($gift_card['id']),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Use part or all of the balance.
     */
    public function redeem(): void
    {
        try {
            if (cannot('edit', PRIV_APPOINTMENTS)) {
                abort(403, 'Forbidden');
            }

            $gift_card = $this->gift_cards_model->redeem(
                (int) request('id'),
                (int) preg_replace('/\D/', '', (string) request('amount')),
                (string) request('notes', ''),
                (int) session('user_id'),
            );

            json_response([
                'gift_card' => $this->gift_cards_model->public_fields($gift_card),
                'redemptions' => $this->gift_cards_model->redemptions($gift_card['id']),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Register a gift card sold in the salon (paid at the TUU machine).
     */
    public function create(): void
    {
        try {
            if (cannot('edit', PRIV_APPOINTMENTS)) {
                abort(403, 'Forbidden');
            }

            $gift_card = $this->gift_cards_model->create_salon(
                [
                    'amount' => request('amount'),
                    'buyer_name' => request('buyer_name'),
                    'recipient_name' => request('recipient_name'),
                    'notes' => request('notes'),
                ],
                (int) session('user_id'),
            );

            json_response(['gift_card' => $this->gift_cards_model->public_fields($gift_card), 'redemptions' => []]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
