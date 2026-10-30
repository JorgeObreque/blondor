<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Precios según el largo del pelo
 *
 * Un servicio puede tener un precio (y opcionalmente una duración) por largo: corto, mediano, largo y extra largo.
 * La cita guarda el largo elegido junto a su precio.
 * ---------------------------------------------------------------------------- */

class Migration_Create_service_variants_table extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->table_exists('service_variants')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_services' => ['type' => 'INT', 'constraint' => 11],
                // short | medium | long | xlong (ver Service_variants_model::LENGTHS)
                'hair_length' => ['type' => 'VARCHAR', 'constraint' => 8],
                'price' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
                // NULL: la duración del servicio.
                'duration' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('service_variants', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('service_variants');
            $this->db->query(
                "ALTER TABLE `$table` ADD UNIQUE KEY `service_variants_length` (`id_services`, `hair_length`)",
            );
        }

        if (!$this->db->field_exists('hair_length', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'hair_length' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'after' => 'price'],
            ]);
        }

        if (!$this->db->field_exists('hair_length', 'service_price_log')) {
            $this->dbforge->add_column('service_price_log', [
                'hair_length' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'after' => 'id_services'],
            ]);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        if ($this->db->field_exists('hair_length', 'service_price_log')) {
            $this->dbforge->drop_column('service_price_log', 'hair_length');
        }

        if ($this->db->field_exists('hair_length', 'appointments')) {
            $this->dbforge->drop_column('appointments', 'hair_length');
        }

        $this->dbforge->drop_table('service_variants', true);
    }
}
