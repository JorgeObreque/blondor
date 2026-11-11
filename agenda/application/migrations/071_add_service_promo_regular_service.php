<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Precio normal de una promoción
 *
 * promo_regular_service_id: el servicio normal del que la promoción es un descuento (por ejemplo, Balayage para
 * "Balayage promo"). La reserva online muestra su precio tachado junto al de la promoción. Vacío = no se muestra.
 * ---------------------------------------------------------------------------- */

class Migration_Add_service_promo_regular_service extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->field_exists('promo_regular_service_id', 'services')) {
            $this->dbforge->add_column('services', [
                'promo_regular_service_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => true,
                    'after' => 'promo_daily_quota',
                ],
            ]);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        if ($this->db->field_exists('promo_regular_service_id', 'services')) {
            $this->dbforge->drop_column('services', 'promo_regular_service_id');
        }
    }
}
