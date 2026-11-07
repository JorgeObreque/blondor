<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Aviso de ficha pendiente
 *
 * Push cada hora a Guille cuando una visita terminó hace 4 horas o más y su ficha sigue vacía.
 * - record_reminder_user_ids: cuentas que reciben el aviso (ids separados por coma). Se siembra con las cuentas del
 *   equipo de apellido Pari (Guillermina y guillecita); Jorge no lo recibe.
 * - record_reminders_started: solo cuentan las visitas que terminan después de activar el aviso, para no despertar
 *   con un montón de fichas antiguas.
 * ---------------------------------------------------------------------------- */

class Migration_Add_record_reminder_settings extends EA_Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        if (!$this->db->get_where('settings', ['name' => 'record_reminder_user_ids'])->num_rows()) {
            $ids = array_column(
                $this->db
                    ->select('u.id')
                    ->from('users u')
                    ->join('roles r', 'r.id = u.id_roles')
                    ->where_in('r.slug', ['admin', 'provider', 'secretary'])
                    ->where('LOWER(TRIM(u.last_name))', 'pari')
                    ->order_by('u.id')
                    ->get()
                    ->result_array(),
                'id',
            );

            $this->db->insert('settings', [
                'name' => 'record_reminder_user_ids',
                'value' => implode(',', $ids),
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'record_reminders_started'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'record_reminders_started',
                'value' => $now,
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]);
        }
    }

    public function down()
    {
        $this->db->where_in('name', ['record_reminder_user_ids', 'record_reminders_started'])->delete('settings');
    }
}
