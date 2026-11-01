<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Origen de la reserva
 *
 * Código de origen (?ref=ADS:CMP-XXXXXX-ABCD de un anuncio, IG-BIO, etc.) con el que la clienta llegó a la reserva
 * pública. Viaja de la reserva apartada (booking_holds) a la cita pagada (appointments) y Publicador lo lee por la
 * API para saber qué anuncio trajo cada cita. Solo sirve para medir: no cambia precios ni montos.
 * ---------------------------------------------------------------------------- */

class Migration_Add_attribution_code extends EA_Migration
{
    public function up()
    {
        foreach (['booking_holds', 'appointments'] as $table) {
            if (!$this->db->field_exists('attribution_code', $table)) {
                $this->dbforge->add_column($table, [
                    'attribution_code' => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
                ]);
            }
        }

        $appointments = $this->db->dbprefix('appointments');

        if (!$this->db->query("SHOW INDEX FROM `$appointments` WHERE Key_name = 'appointments_attribution_code'")
            ->num_rows()) {
            $this->db->query("ALTER TABLE `$appointments` ADD KEY `appointments_attribution_code` (`attribution_code`)");
        }
    }

    public function down()
    {
        foreach (['booking_holds', 'appointments'] as $table) {
            if ($this->db->field_exists('attribution_code', $table)) {
                $this->dbforge->drop_column($table, 'attribution_code');
            }
        }
    }
}
