<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Gift cards
 *
 * Gift cards vendidas online (Webpay) o en el salón, con su saldo y el historial de canjes. Sin vencimiento.
 * ---------------------------------------------------------------------------- */

class Migration_Create_gift_cards_tables extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->table_exists('gift_cards')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                // Código que la clienta presenta en el salón; se genera al confirmar el pago.
                'code' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                // Enlace privado para ver la gift card (no se usa el código en las URLs).
                'hash' => ['type' => 'VARCHAR', 'constraint' => 40],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
                'balance' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                // pending (esperando pago) | active | used (saldo 0) | failed (pago rechazado) | cancelled
                'status' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'pending'],
                // online | salon
                'channel' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'online'],
                'buyer_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'buyer_email' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'buyer_phone' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'recipient_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'recipient_email' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'message' => ['type' => 'TEXT', 'null' => true],
                'payment_provider' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'buy_order' => ['type' => 'VARCHAR', 'constraint' => 26, 'null' => true],
                'payment_token' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'payment_data' => ['type' => 'TEXT', 'null' => true],
                'paid_datetime' => ['type' => 'DATETIME', 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'id_users_author' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('gift_cards', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('gift_cards');
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `gift_cards_code` (`code`)");
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `gift_cards_hash` (`hash`)");
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `gift_cards_buy_order` (`buy_order`)");
            $this->db->query("ALTER TABLE `$table` ADD KEY `gift_cards_payment_token` (`payment_token`)");
        }

        if (!$this->db->table_exists('gift_card_redemptions')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_gift_cards' => ['type' => 'INT', 'constraint' => 11],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
                'balance_after' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
                'notes' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'id_users_author' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->add_key('id_gift_cards');
            $this->dbforge->create_table('gift_card_redemptions', true, ['engine' => 'InnoDB']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        $this->dbforge->drop_table('gift_card_redemptions', true);
        $this->dbforge->drop_table('gift_cards', true);
    }
}
