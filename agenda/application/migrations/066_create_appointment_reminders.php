<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Create_appointment_reminders extends EA_Migration
{
    public function up()
    {
        $table = $this->db->dbprefix('appointment_reminders');
        $this->db->query("CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_appointments BIGINT UNSIGNED NOT NULL,
            schedule_key CHAR(64) NOT NULL,
            start_utc DATETIME NOT NULL,
            hours_before TINYINT UNSIGNED NOT NULL,
            audience VARCHAR(16) NOT NULL,
            id_users BIGINT UNSIGNED NOT NULL,
            recipient_hash CHAR(64) NOT NULL,
            due_at_utc DATETIME NOT NULL,
            expires_at_utc DATETIME NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at_utc DATETIME NOT NULL,
            claim_token CHAR(32) NULL,
            claim_expires_at_utc DATETIME NULL,
            sent_at_utc DATETIME NULL,
            create_utc DATETIME NOT NULL,
            update_utc DATETIME NOT NULL,
            UNIQUE KEY reminder_once (id_appointments, schedule_key, hours_before, recipient_hash),
            KEY reminders_pending (status, available_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$this->db->field_exists('reminder_schedule_changed_at_utc', 'appointments')) {
            $this->dbforge->add_column('appointments', ['reminder_schedule_changed_at_utc' => ['type' => 'DATETIME', 'null' => true]]);
        }
        foreach (['appointment_reminders_enabled' => '0', 'appointment_reminders_started_utc' => '',
            'appointment_reminder_staff_email' => 'gpi.siempremas18@gmail.com'] as $name => $value) {
            if (!$this->db->get_where('settings', ['name' => $name])->num_rows()) {
                $this->db->insert('settings', ['name' => $name, 'value' => $value]);
            }
        }
    }

    public function down()
    {
        $this->dbforge->drop_table('appointment_reminders', true);
        if ($this->db->field_exists('reminder_schedule_changed_at_utc', 'appointments')) {
            $this->dbforge->drop_column('appointments', 'reminder_schedule_changed_at_utc');
        }
    }
}
