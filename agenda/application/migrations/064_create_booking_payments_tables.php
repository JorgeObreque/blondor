<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Pago obligatorio antes de confirmar la reserva
 *
 * Cuatro tablas y unas columnas en las citas:
 *
 *   booking_holds     Intento de reserva: aparta el horario mientras la clienta paga (con su ficha de precio y sus
 *                     datos, para poder terminar la cita sin depender del navegador).
 *   payment_attempts  Intentos de pago (uno por cada transacción de Webpay), con su orden de compra y su estado.
 *   outbox_events     Avisos durables (correo, calendario y webhook) que se disparan solo cuando la cita ya está
 *                     pagada y confirmada.
 *   slot_locks        Fila de bloqueo por profesional y fecha: serializa de verdad el acceso a un horario, para que
 *                     dos reservas simultáneas no vendan la misma hora.
 *
 * En las citas: con qué reserva y pago se creó, cuánto se pagó y cuánto queda.
 * ---------------------------------------------------------------------------- */

class Migration_Create_booking_payments_tables extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up()
    {
        if (!$this->db->table_exists('booking_holds')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                // Token opaco de la reserva (alta entropía): el único que ve la clienta.
                'hash' => ['type' => 'VARCHAR', 'constraint' => 64],
                // Llave de idempotencia que envía el navegador: liga la reserva a su contenido.
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
                'payload_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
                // held | paid | released | expired | attention
                'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'held'],
                // deposit | full (NULL cuando la cita se reagenda sin cobro).
                'mode' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
                'id_users_provider' => ['type' => 'INT', 'constraint' => 11],
                'id_services' => ['type' => 'INT', 'constraint' => 11],
                'hair_length' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
                'start_datetime' => ['type' => 'DATETIME'],
                'end_datetime' => ['type' => 'DATETIME'],
                'duration' => ['type' => 'INT', 'constraint' => 11],
                // Ficha del servicio en el momento de la reserva (el precio no se recalcula después).
                'service_name' => ['type' => 'VARCHAR', 'constraint' => 255],
                'price_type' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'fixed'],
                // Precio final: NULL cuando no es exacto ("desde"), nunca se adivina.
                'total_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'price_exact' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'deposit_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                'paid_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                // Saldo pendiente: NULL cuando el total todavía no se conoce.
                'amount_due' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                // Datos de la clienta y de la cita, para poder terminarla sin el navegador.
                'customer_snapshot' => ['type' => 'TEXT', 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'id_users_customer' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                // Huellas (no datos) para contar los intentos por equipo y por correo.
                'ip_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'email_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                // Cita que se actualiza al confirmar (reagenda) o que se crea al confirmar (reserva nueva).
                'id_appointments' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'expires_at' => ['type' => 'DATETIME'],
                // Lease atómico: uno solo puede confirmar un pago a la vez.
                'claim_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'claim_expires_at' => ['type' => 'DATETIME', 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('booking_holds', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('booking_holds');
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `booking_holds_hash` (`hash`)");
            $this->db->query(
                "ALTER TABLE `$table` ADD UNIQUE KEY `booking_holds_idempotency` (`idempotency_key`)",
            );
            $this->db->query(
                "ALTER TABLE `$table` ADD KEY `booking_holds_provider_date` (`id_users_provider`, `start_datetime`)",
            );
            $this->db->query("ALTER TABLE `$table` ADD KEY `booking_holds_status` (`status`, `expires_at`)");
        }

        if (!$this->db->table_exists('payment_attempts')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_booking_holds' => ['type' => 'INT', 'constraint' => 11],
                'attempt_no' => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
                // Orden de compra de Webpay: única y de máximo 26 caracteres.
                'buy_order' => ['type' => 'VARCHAR', 'constraint' => 26],
                'token' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                // La URL del formulario de Webpay (la que devuelve Transbank).
                'payment_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'session_id' => ['type' => 'VARCHAR', 'constraint' => 64],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
                'provider' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'webpay'],
                // created | authorized | rejected | aborted | failed | unknown | attention
                'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'created'],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
                'response_data' => ['type' => 'TEXT', 'null' => true],
                'committed_at' => ['type' => 'DATETIME', 'null' => true],
                // Lease atómico: uno solo confirma el pago a la vez.
                'claim_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'claim_expires_at' => ['type' => 'DATETIME', 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('payment_attempts', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('payment_attempts');
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `payment_attempts_buy_order` (`buy_order`)");
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `payment_attempts_token` (`token`)");
            // La llave de idempotencia es única: dos clics en "Continuar a Webpay" no pueden crear dos transacciones.
            $this->db->query(
                "ALTER TABLE `$table` ADD UNIQUE KEY `payment_attempts_idempotency` (`idempotency_key`)",
            );
            $this->db->query("ALTER TABLE `$table` ADD KEY `payment_attempts_holds` (`id_booking_holds`)");
            $this->db->query("ALTER TABLE `$table` ADD KEY `payment_attempts_status` (`status`)");
        }

        if (!$this->db->table_exists('outbox_events')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'event' => ['type' => 'VARCHAR', 'constraint' => 40],
                // Dedupe: una sola vez por evento y cita.
                'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 120],
                'id_appointments' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'id_booking_holds' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'payload' => ['type' => 'TEXT', 'null' => true],
                // pending | processing | done | failed
                'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
                'attempts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'available_at' => ['type' => 'DATETIME'],
                'claim_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'claim_expires_at' => ['type' => 'DATETIME', 'null' => true],
                'last_error' => ['type' => 'TEXT', 'null' => true],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('outbox_events', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('outbox_events');
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `outbox_events_dedupe` (`dedupe_key`)");
            $this->db->query(
                "ALTER TABLE `$table` ADD KEY `outbox_events_ready` (`status`, `available_at`)",
            );
        }

        if (!$this->db->table_exists('slot_locks')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'id_users_provider' => ['type' => 'INT', 'constraint' => 11],
                'lock_date' => ['type' => 'DATE'],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('slot_locks', true, ['engine' => 'InnoDB']);

            $table = $this->db->dbprefix('slot_locks');
            $this->db->query("ALTER TABLE `$table` ADD UNIQUE KEY `slot_locks_provider_date` (`id_users_provider`, `lock_date`)");
        }

        // Citas: con qué reserva nacieron y cómo están sus finanzas.
        if (!$this->db->field_exists('id_booking_holds', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'id_booking_holds' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'hair_length'],
            ]);
        }

        if (!$this->db->field_exists('payment_status', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                // internal | legacy | deposit_paid | paid | attention | cancelled
                'payment_status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'internal', 'after' => 'id_booking_holds'],
            ]);
        }

        if (!$this->db->field_exists('payment_mode', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'payment_mode' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'payment_status'],
            ]);
        }

        if (!$this->db->field_exists('paid_amount', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'paid_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0, 'after' => 'payment_mode'],
            ]);
        }

        if (!$this->db->field_exists('amount_due', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                // NULL: el total final todavía no se conoce (servicio "desde").
                'amount_due' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'paid_amount'],
            ]);
        }

        if (!$this->db->field_exists('total_amount', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'total_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'amount_due'],
            ]);
        }

        // Las citas que ya existían se quedan como "legacy": sin pago registrado, no se les inventa dinero.
        $this->db->query(
            "UPDATE `" . $this->db->dbprefix('appointments') . "` SET `payment_status` = 'legacy'" .
            " WHERE `payment_status` = 'internal' AND `id_booking_holds` IS NULL",
        );
    }

    /**
     * Downgrade method.
     */
    public function down()
    {
        $this->dbforge->drop_table('slot_locks', true);
        $this->dbforge->drop_table('outbox_events', true);
        $this->dbforge->drop_table('payment_attempts', true);
        $this->dbforge->drop_table('booking_holds', true);

        foreach (['total_amount', 'amount_due', 'paid_amount', 'payment_mode', 'payment_status', 'id_booking_holds'] as $field) {
            if ($this->db->field_exists($field, 'appointments')) {
                $this->dbforge->drop_column('appointments', $field);
            }
        }
    }
}
