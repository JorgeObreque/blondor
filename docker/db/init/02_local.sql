-- Ajustes para el entorno local, se aplican después del dump de producción.
DELETE FROM ea_webhooks;
UPDATE ea_user_settings SET google_sync = 0, caldav_sync = 0;
