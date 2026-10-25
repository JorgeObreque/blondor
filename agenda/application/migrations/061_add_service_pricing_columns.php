<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Servicios y precios
 *
 * Tipo de precio (fijo / desde), servicios ocultos del catálogo, el precio guardado en cada cita (el historial no
 * cambia al subir precios) y el registro de cambios de precio.
 * ---------------------------------------------------------------------------- */

class Migration_Add_service_pricing_columns extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->field_exists('price_type', 'services')) {
            $this->dbforge->add_column('services', [
                'price_type' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'fixed', 'after' => 'price'],
            ]);
        }

        if (!$this->db->field_exists('is_archived', 'services')) {
            $this->dbforge->add_column('services', [
                'is_archived' => ['type' => 'TINYINT', 'constraint' => 4, 'default' => 0, 'after' => 'is_private'],
            ]);
        }

        if (!$this->db->field_exists('price', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'price' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'status'],
            ]);

            // Citas existentes: el precio vigente del servicio. Sin precio ($0) queda NULL y se usa el del servicio.
            $this->db->query(
                'UPDATE `' . $this->db->dbprefix('appointments') . '` a
                 JOIN `' . $this->db->dbprefix('services') . '` s ON s.id = a.id_services
                 SET a.price = NULLIF(s.price, 0)
                 WHERE a.is_unavailability = 0',
            );
        }

        if (!$this->db->table_exists('service_price_log')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_services' => ['type' => 'INT', 'constraint' => 11],
                'old_price' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'new_price' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'id_users_author' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->add_key('id_services');
            $this->dbforge->create_table('service_price_log', true, ['engine' => 'InnoDB']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        $this->dbforge->drop_table('service_price_log', true);

        if ($this->db->field_exists('price', 'appointments')) {
            $this->dbforge->drop_column('appointments', 'price');
        }

        if ($this->db->field_exists('is_archived', 'services')) {
            $this->dbforge->drop_column('services', 'is_archived');
        }

        if ($this->db->field_exists('price_type', 'services')) {
            $this->dbforge->drop_column('services', 'price_type');
        }
    }
}
