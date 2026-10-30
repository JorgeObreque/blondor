-- Precios por largo del pelo (29-10-2026) — PROPUESTA, revisar los valores antes de ejecutar.
--
-- Carga los precios de corto, mediano, largo y extra largo de los servicios que dependen del largo. Corto es el
-- precio que tiene hoy el servicio; mediano, largo y extra largo suben ~15 %, ~30 % y ~45 %, redondeados a $1.000.
-- La duración queda en NULL (usa la del servicio) mientras se corrigen las duraciones; se puede poner por largo en
-- /precios.
--
-- Requiere la migración 063 (tabla ea_service_variants). Los precios se pueden cambiar después en /precios.
-- No toca las citas ya agendadas: conservan su precio. Respaldo previo y ejecución: ver scripts/sql/README.md
-- (agregar ea_service_variants a las tablas del respaldo).

START TRANSACTION;

INSERT INTO ea_service_variants (id_services, hair_length, price, duration, create_datetime, update_datetime)
SELECT v.id_services, v.hair_length, v.price, NULL, NOW(), NOW()
FROM (
              SELECT  2 AS id_services, 'short' AS hair_length,  95500 AS price  -- balayage
    UNION ALL SELECT  2, 'medium', 110000 UNION ALL SELECT  2, 'long', 124000 UNION ALL SELECT  2, 'xlong', 138000
    UNION ALL SELECT  3, 'short',   75000                                                     -- babylight
    UNION ALL SELECT  3, 'medium',  86000 UNION ALL SELECT  3, 'long',  98000 UNION ALL SELECT  3, 'xlong', 109000
    UNION ALL SELECT  8, 'short',   55000                                                     -- bloque de color
    UNION ALL SELECT  8, 'medium',  63000 UNION ALL SELECT  8, 'long',  72000 UNION ALL SELECT  8, 'xlong',  80000
    UNION ALL SELECT  7, 'short',   49900                                                     -- color completo
    UNION ALL SELECT  7, 'medium',  57000 UNION ALL SELECT  7, 'long',  65000 UNION ALL SELECT  7, 'xlong',  72000
    UNION ALL SELECT 16, 'short',   45000                                                     -- alisado completo
    UNION ALL SELECT 16, 'medium',  52000 UNION ALL SELECT 16, 'long',  59000 UNION ALL SELECT 16, 'xlong',  65000
    UNION ALL SELECT 25, 'short',   36000                                                     -- botox capilar
    UNION ALL SELECT 25, 'medium',  41000 UNION ALL SELECT 25, 'long',  47000 UNION ALL SELECT 25, 'xlong',  52000
    UNION ALL SELECT 17, 'short',   16000                                                     -- brushing
    UNION ALL SELECT 17, 'medium',  18000 UNION ALL SELECT 17, 'long',  21000 UNION ALL SELECT 17, 'xlong',  23000
    UNION ALL SELECT 26, 'short',   20000                                                     -- masaje hidratante
    UNION ALL SELECT 26, 'medium',  23000 UNION ALL SELECT 26, 'long',  26000 UNION ALL SELECT 26, 'xlong',  29000
    UNION ALL SELECT 28, 'short',   22000                                                     -- masaje total mask
    UNION ALL SELECT 28, 'medium',  25000 UNION ALL SELECT 28, 'long',  29000 UNION ALL SELECT 28, 'xlong',  32000
) v
JOIN ea_services s ON s.id = v.id_services
WHERE NOT EXISTS (SELECT 1 FROM ea_service_variants e WHERE e.id_services = v.id_services);

-- El precio del servicio queda en el del corto (lo que aún no conoce los largos lo muestra como «desde»).
UPDATE ea_services s
JOIN ea_service_variants v ON v.id_services = s.id AND v.hair_length = 'short'
SET s.price = v.price, s.price_type = 'from', s.update_datetime = NOW();

INSERT INTO ea_service_price_log (id_services, hair_length, old_price, new_price, id_users_author, create_datetime)
SELECT v.id_services, v.hair_length, NULL, v.price, NULL, NOW()
FROM ea_service_variants v
WHERE v.create_datetime >= NOW() - INTERVAL 1 MINUTE;

SELECT s.name, v.hair_length, v.price
FROM ea_service_variants v JOIN ea_services s ON s.id = v.id_services
ORDER BY s.name, FIELD(v.hair_length, 'short', 'medium', 'long', 'xlong');

COMMIT;
