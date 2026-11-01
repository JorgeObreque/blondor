<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Recorrido de la reserva
 *
 * Hasta qué paso llegó cada visita (sitio, agenda, servicio, hora, datos, Webpay) y con qué origen (?ref=). Sirve
 * para ver dónde se van las clientas que llegan de un anuncio. No guarda datos personales: solo un identificador de
 * visita al azar que dura 30 minutos.
 * ---------------------------------------------------------------------------- */

class Migration_Create_booking_funnel_events extends EA_Migration
{
    public function up()
    {
        if ($this->db->table_exists('booking_funnel_events')) {
            return;
        }

        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'session_id' => ['type' => 'VARCHAR', 'constraint' => 40],
            'step' => ['type' => 'VARCHAR', 'constraint' => 16],
            'attribution_code' => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
            'id_services' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'create_datetime' => ['type' => 'DATETIME'],
        ]);

        $this->dbforge->add_key('id', true);

        $this->dbforge->create_table('booking_funnel_events', true, ['engine' => 'InnoDB']);

        $table = $this->db->dbprefix('booking_funnel_events');

        $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `booking_funnel_session_step` (`session_id`, `step`)");
        $this->db->query("ALTER TABLE `$table` ADD KEY `booking_funnel_created` (`create_datetime`)");
    }

    public function down()
    {
        $this->dbforge->drop_table('booking_funnel_events', true);
    }
}
