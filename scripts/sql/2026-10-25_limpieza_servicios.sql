-- Limpieza de servicios (25-10-2026)
--
-- Borra definitivamente 31 servicios que nunca se agendaron y que ya no se ofrecen: depilación, cejas y rebaje,
-- manicure y pedicura, faciales, masajes corporales y restos de pruebas. Se conservan los 9 servicios de
-- peluquería activos sin citas (K18, Olaplex, alisado de coronilla, corte niños, ondas, visos y permanentes).
--
-- Al borrar un servicio, MariaDB borra en cascada sus asignaciones a profesionales (ea_services_providers).
-- Antes de borrar se vuelve a comprobar que cada servicio siga sin citas: si alguno se agendó entretanto, se salta.
-- También se borran su historial de precios y las categorías que esta limpieza deja vacías (no las que ya
-- estaban vacías, que parecen servicios nuevos en preparación).
--
-- Respaldo previo y ejecución en producción: ver scripts/sql/README.md.

START TRANSACTION;

CREATE TEMPORARY TABLE limpieza_servicios AS
SELECT s.id, s.name, s.id_service_categories
FROM ea_services s
WHERE s.id IN (46, 55, 57, 39, 40, 54, 47, 43, 49, 53, 44, 42, 52, 51, 45, 38, 50,
               59, 58, 31, 32, 33,
               20, 21, 22, 24, 23,
               56, 66, 65, 64)
  AND NOT EXISTS (SELECT 1 FROM ea_appointments a WHERE a.id_services = s.id);

CREATE TEMPORARY TABLE limpieza_categorias AS
SELECT DISTINCT l.id_service_categories AS id
FROM limpieza_servicios l
WHERE l.id_service_categories IS NOT NULL;

SELECT COUNT(*) AS servicios_a_borrar FROM limpieza_servicios;

DELETE p FROM ea_service_price_log p JOIN limpieza_servicios l ON l.id = p.id_services;
DELETE s FROM ea_services s JOIN limpieza_servicios l ON l.id = s.id;

DELETE c
FROM ea_service_categories c
JOIN limpieza_categorias lc ON lc.id = c.id
WHERE NOT EXISTS (SELECT 1 FROM ea_services s WHERE s.id_service_categories = c.id);

SELECT COUNT(*) AS servicios_restantes FROM ea_services;
SELECT COUNT(*) AS categorias_restantes FROM ea_service_categories;

COMMIT;
