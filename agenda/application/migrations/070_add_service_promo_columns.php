<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Servicios en promoción
 *
 * Un servicio puede marcarse como promoción (por defecto no). Solo entonces cuentan:
 * - promo_weekdays: días en que se puede agendar, ISO separados por coma (1 = lunes ... 7 = domingo). Vacío = todos.
 * - promo_daily_quota: citas como máximo por día de ese servicio. Vacío = sin límite.
 * Si se desmarca la promoción, los días y cupos guardados se ignoran (no se borran).
 * ---------------------------------------------------------------------------- */

class Migration_Add_service_promo_columns extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->field_exists('is_promo', 'services')) {
            $this->dbforge->add_column('services', [
                'is_promo' => ['type' => 'TINYINT', 'constraint' => 4, 'default' => 0, 'after' => 'is_archived'],
            ]);
        }

        if (!$this->db->field_exists('promo_weekdays', 'services')) {
            $this->dbforge->add_column('services', [
                'promo_weekdays' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'is_promo'],
            ]);
        }

        if (!$this->db->field_exists('promo_daily_quota', 'services')) {
            $this->dbforge->add_column('services', [
                'promo_daily_quota' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'promo_weekdays'],
            ]);
        }
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        foreach (['promo_daily_quota', 'promo_weekdays', 'is_promo'] as $column) {
            if ($this->db->field_exists($column, 'services')) {
                $this->dbforge->drop_column('services', $column);
            }
        }
    }
}
