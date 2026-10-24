<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Perfil de clienta
 *
 * Datos fijos de cada clienta (alertas, cabello, preferencias), la ficha técnica de cada visita y sus fotos.
 * ---------------------------------------------------------------------------- */

class Migration_Create_customer_records_tables extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->table_exists('customer_profiles')) {
            $this->dbforge->add_field([
                'id_users' => ['type' => 'INT', 'constraint' => 11],
                'alerts' => ['type' => 'TEXT', 'null' => true],
                'hair' => ['type' => 'TEXT', 'null' => true],
                'preferences' => ['type' => 'TEXT', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
                'id_users_author' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            ]);
            $this->dbforge->add_key('id_users', true);
            $this->dbforge->create_table('customer_profiles', true, ['engine' => 'InnoDB']);
        }

        if (!$this->db->table_exists('visit_records')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_users_customer' => ['type' => 'INT', 'constraint' => 11],
                'id_appointments' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'id_users_provider' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'id_services' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'visit_date' => ['type' => 'DATE'],
                'details' => ['type' => 'TEXT', 'null' => true],
                'work' => ['type' => 'TEXT', 'null' => true],
                'products' => ['type' => 'TEXT', 'null' => true],
                'result' => ['type' => 'TEXT', 'null' => true],
                'home_care' => ['type' => 'TEXT', 'null' => true],
                'next_visit_weeks' => ['type' => 'TINYINT', 'constraint' => 3, 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
                'id_users_author' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->add_key('id_users_customer');
            $this->dbforge->create_table('visit_records', true, ['engine' => 'InnoDB']);

            $this->db->query(
                'ALTER TABLE `' . $this->db->dbprefix('visit_records') . '` ADD UNIQUE KEY `visit_records_appointment` (`id_appointments`)',
            );
        }

        if (!$this->db->table_exists('visit_photos')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_visit_records' => ['type' => 'INT', 'constraint' => 11],
                'kind' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'despues'],
                'file' => ['type' => 'VARCHAR', 'constraint' => 128],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->add_key('id_visit_records');
            $this->dbforge->create_table('visit_photos', true, ['engine' => 'InnoDB']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        $this->dbforge->drop_table('visit_photos', true);
        $this->dbforge->drop_table('visit_records', true);
        $this->dbforge->drop_table('customer_profiles', true);
    }
}
