<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Gift cards
 *
 * Una gift card nace «pending» al iniciar el pago online y pasa a «active» cuando Webpay lo confirma; recién ahí recibe
 * su código. Las vendidas en el salón (POS TUU) nacen activas. Se canjean por partes hasta dejar el saldo en 0 («used»).
 * No vencen.
 * ---------------------------------------------------------------------------- */

class Gift_cards_model extends EA_Model
{
    /**
     * Montos sugeridos en el sitio; también se acepta un monto libre entre MIN_AMOUNT y MAX_AMOUNT.
     */
    const AMOUNTS = [20000, 30000, 50000];

    const MIN_AMOUNT = 10000;

    const MAX_AMOUNT = 500000;

    /**
     * Sin letras ni números que se confundan al dictarlos (0/O, 1/I/L).
     */
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    const STATUSES = ['pending', 'active', 'used', 'failed', 'cancelled'];

    /**
     * Validate the gift card data sent by the buyer and normalize it.
     *
     * @throws InvalidArgumentException
     */
    public function validate_purchase(array $data): array
    {
        $amount = (int) preg_replace('/\D/', '', (string) ($data['amount'] ?? ''));

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException(
                'El monto debe estar entre $' . number_format(self::MIN_AMOUNT, 0, ',', '.') . ' y $' .
                    number_format(self::MAX_AMOUNT, 0, ',', '.') . '.',
            );
        }

        $clean = fn($key, $max) => mb_substr(trim((string) ($data[$key] ?? '')), 0, $max);

        $purchase = [
            'amount' => $amount,
            'buyer_name' => $clean('buyer_name', 120),
            'buyer_email' => mb_strtolower($clean('buyer_email', 190)),
            'buyer_phone' => $clean('buyer_phone', 40),
            'recipient_name' => $clean('recipient_name', 120),
            'recipient_email' => mb_strtolower($clean('recipient_email', 190)),
            'message' => $clean('message', 500),
        ];

        if ($purchase['buyer_name'] === '') {
            throw new InvalidArgumentException('Escribe tu nombre.');
        }

        if (!filter_var($purchase['buyer_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Revisa tu correo: ahí te enviamos la gift card.');
        }

        if ($purchase['recipient_email'] !== '' && !filter_var($purchase['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Revisa el correo de quien recibe el regalo.');
        }

        return $purchase;
    }

    /**
     * Save an online purchase waiting for payment.
     */
    public function create_pending(array $purchase, string $provider): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->insert('gift_cards', [
            ...$purchase,
            'balance' => 0,
            'status' => 'pending',
            'channel' => 'online',
            'hash' => $this->random_hash(),
            // Orden de compra para Webpay: única y de máximo 26 caracteres.
            'buy_order' => 'GC' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3))),
            'payment_provider' => $provider,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return $this->find((int) $this->db->insert_id());
    }

    /**
     * Register a gift card sold in the salon (paid at the POS): active from the start.
     */
    public function create_salon(array $data, int $author_id): array
    {
        $amount = (int) preg_replace('/\D/', '', (string) ($data['amount'] ?? ''));

        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('Escribe el monto de la gift card.');
        }

        $now = date('Y-m-d H:i:s');

        $this->db->trans_start();

        $this->db->insert('gift_cards', [
            'code' => $this->unique_code(),
            'hash' => $this->random_hash(),
            'amount' => $amount,
            'balance' => $amount,
            'status' => 'active',
            'channel' => 'salon',
            'buyer_name' => mb_substr(trim((string) ($data['buyer_name'] ?? '')), 0, 120) ?: null,
            'recipient_name' => mb_substr(trim((string) ($data['recipient_name'] ?? '')), 0, 120) ?: null,
            'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
            'payment_provider' => 'pos',
            'paid_datetime' => $now,
            'id_users_author' => $author_id,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        $id = (int) $this->db->insert_id();

        $this->db->trans_complete();

        return $this->find($id);
    }

    public function set_payment_token(int $id, string $token): void
    {
        $this->db->update(
            'gift_cards',
            ['payment_token' => $token, 'update_datetime' => date('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    /**
     * Mark a pending gift card as paid and give it its code. Safe to call twice: an active one is returned as is.
     *
     * @return array [gift card, bool newly activated]
     */
    public function activate(int $id, array $payment_data): array
    {
        $this->db->trans_start();

        $gift_card = $this->db->query(
            'SELECT * FROM `' . $this->db->dbprefix('gift_cards') . '` WHERE id = ? FOR UPDATE',
            [$id],
        )->row_array();

        $activated = false;

        if ($gift_card && $gift_card['status'] === 'pending') {
            $this->db->update(
                'gift_cards',
                [
                    'code' => $this->unique_code(),
                    'balance' => $gift_card['amount'],
                    'status' => 'active',
                    'payment_data' => json_encode($payment_data),
                    'paid_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ],
                ['id' => $id],
            );

            $activated = true;
        }

        $this->db->trans_complete();

        return [$this->find($id), $activated];
    }

    public function mark_failed(int $id, array $payment_data): void
    {
        $this->db
            ->where('id', $id)
            ->where('status', 'pending')
            ->update('gift_cards', [
                'status' => 'failed',
                'payment_data' => json_encode($payment_data),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Use part or all of the balance.
     *
     * @throws InvalidArgumentException
     */
    public function redeem(int $id, int $amount, string $notes, int $author_id): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Escribe el monto a descontar.');
        }

        $this->db->trans_start();

        $gift_card = $this->db->query(
            'SELECT * FROM `' . $this->db->dbprefix('gift_cards') . '` WHERE id = ? FOR UPDATE',
            [$id],
        )->row_array();

        if (!$gift_card || $gift_card['status'] !== 'active') {
            $this->db->trans_complete();

            throw new InvalidArgumentException('Esta gift card no tiene saldo disponible.');
        }

        $balance = (int) round($gift_card['balance']);

        if ($amount > $balance) {
            $this->db->trans_complete();

            throw new InvalidArgumentException('El saldo disponible es $' . number_format($balance, 0, ',', '.') . '.');
        }

        $after = $balance - $amount;
        $now = date('Y-m-d H:i:s');

        $this->db->update(
            'gift_cards',
            ['balance' => $after, 'status' => $after === 0 ? 'used' : 'active', 'update_datetime' => $now],
            ['id' => $id],
        );

        $this->db->insert('gift_card_redemptions', [
            'id_gift_cards' => $id,
            'amount' => $amount,
            'balance_after' => $after,
            'notes' => mb_substr(trim($notes), 0, 255) ?: null,
            'id_users_author' => $author_id,
            'create_datetime' => $now,
        ]);

        $this->db->trans_complete();

        return $this->find($id);
    }

    public function find(int $id): ?array
    {
        return $this->cast_row($this->db->get_where('gift_cards', ['id' => $id])->row_array());
    }

    public function find_by(string $field, string $value): ?array
    {
        if (!in_array($field, ['hash', 'code', 'buy_order', 'payment_token'], true) || $value === '') {
            return null;
        }

        return $this->cast_row($this->db->get_where('gift_cards', [$field => $value])->row_array());
    }

    /**
     * Gift cards for the back office: newest first, filtered by code, names or emails.
     */
    public function search(string $keyword = '', string $status = '', int $limit = 100): array
    {
        $this->db->from('gift_cards')->where('status !=', 'pending');

        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $this->db->where('status', $status);
        }

        $keyword = trim($keyword);

        if ($keyword !== '') {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $keyword));

            $this->db->group_start();
            $this->db->like('buyer_name', $keyword);
            $this->db->or_like('recipient_name', $keyword);
            $this->db->or_like('buyer_email', $keyword);
            $this->db->or_like('recipient_email', $keyword);

            if ($code !== '') {
                $this->db->or_where("REPLACE(code, '-', '') LIKE " . $this->db->escape('%' . $code . '%'), null, false);
            }

            $this->db->group_end();
        }

        $rows = $this->db->order_by('create_datetime', 'DESC')->limit($limit)->get()->result_array();

        return array_map(fn($row) => $this->public_fields($this->cast_row($row)), $rows);
    }

    public function redemptions(int $id): array
    {
        $rows = $this->db
            ->select('r.amount, r.balance_after, r.notes, r.create_datetime, u.first_name AS author')
            ->from('gift_card_redemptions r')
            ->join('users u', 'u.id = r.id_users_author', 'left')
            ->where('r.id_gift_cards', $id)
            ->order_by('r.create_datetime', 'DESC')
            ->get()
            ->result_array();

        return array_map(
            fn($row) => [
                'amount' => (int) round($row['amount']),
                'balance_after' => (int) round($row['balance_after']),
                'notes' => $row['notes'],
                'date' => $row['create_datetime'],
                'author' => $row['author'],
            ],
            $rows,
        );
    }

    /**
     * Fields the back office needs (no payment payload).
     */
    public function public_fields(array $gift_card): array
    {
        return array_intersect_key(
            $gift_card,
            array_flip([
                'id', 'code', 'amount', 'balance', 'status', 'channel', 'buyer_name', 'buyer_email', 'buyer_phone',
                'recipient_name', 'recipient_email', 'message', 'notes', 'paid_datetime', 'create_datetime',
            ]),
        );
    }

    protected function cast_row(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['amount'] = (int) round($row['amount']);
        $row['balance'] = (int) round($row['balance']);

        return $row;
    }

    protected function unique_code(): string
    {
        do {
            $chars = '';

            for ($i = 0; $i < 8; $i++) {
                $chars .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            $code = 'BLD-' . substr($chars, 0, 4) . '-' . substr($chars, 4);
        } while ($this->db->get_where('gift_cards', ['code' => $code])->num_rows());

        return $code;
    }

    protected function random_hash(): string
    {
        return bin2hex(random_bytes(16));
    }
}
