<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Última hora de inicio para la reserva online
 *
 * latest_start_time: la hora más tarde a la que una clienta puede empezar el servicio al reservar online (por ejemplo
 * 16:00 para las decoloraciones largas). Vacío = sin límite. La agenda interna no lo aplica.
 * ---------------------------------------------------------------------------- */

class Migration_Add_service_latest_start_time extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->field_exists('latest_start_time', 'services')) {
            $this->dbforge->add_column('services', [
                'latest_start_time' => [
                    'type' => 'TIME',
                    'null' => true,
                    'after' => 'promo_regular_service_id',
                ],
            ]);

            // Las decoloraciones largas se reservan online solo hasta las 16:00: babylight, balayage, balayage promo,
            // mechas con papel y visos con gorra.
            $this->db
                ->where_in('id', [2, 3, 4, 5, 69])
                ->update('services', ['latest_start_time' => '16:00:00']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        if ($this->db->field_exists('latest_start_time', 'services')) {
            $this->dbforge->drop_column('services', 'latest_start_time');
        }
    }
}
