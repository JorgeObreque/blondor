<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Create_backoffice_push_tables extends EA_Migration
{
    public function up()
    {
        $subscriptions = $this->db->dbprefix('push_subscriptions');
        $deliveries = $this->db->dbprefix('push_deliveries');
        $this->db->query("CREATE TABLE IF NOT EXISTS `$subscriptions` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_users BIGINT UNSIGNED NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            public_key VARCHAR(100) NOT NULL,
            auth_token VARCHAR(40) NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            create_datetime DATETIME NOT NULL,
            update_datetime DATETIME NOT NULL,
            last_success_at DATETIME NULL,
            UNIQUE KEY push_endpoint (endpoint_hash),
            KEY push_user_enabled (id_users, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->query("CREATE TABLE IF NOT EXISTS `$deliveries` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_subscription BIGINT UNSIGNED NOT NULL,
            id_users BIGINT UNSIGNED NOT NULL,
            id_appointments BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(120) NOT NULL,
            event_type VARCHAR(30) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            claim_expires_at DATETIME NULL,
            create_datetime DATETIME NOT NULL,
            update_datetime DATETIME NOT NULL,
            UNIQUE KEY push_delivery_event (id_subscription, event_key),
            KEY push_delivery_pending (status, available_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        $this->dbforge->drop_table('push_deliveries', true);
        $this->dbforge->drop_table('push_subscriptions', true);
    }
}
