# Scripts SQL de datos

Cambios de datos puntuales en la BD de la agenda (no son migraciones de esquema). Cada script se prueba
primero en local y se ejecuta en producción una sola vez, con respaldo previo.

## Probar en local

```bash
docker exec -i blondor-db-1 mysql -uagenda -pagenda agenda < scripts/sql/<script>.sql
```

## Producción

1. Respaldo de las tablas que toca el script (queda en el servidor):

   ```bash
   ssh blondor.web 'mysqldump --defaults-extra-file=~/.my_agenda.cnf --no-tablespaces cbl116984_blondorc_agenda \
     ea_services ea_services_providers ea_service_categories ea_service_price_log \
     > ~/respaldo_servicios_$(date +%Y%m%d_%H%M%S).sql'
   ```

2. Ejecutar:

   ```bash
   ssh blondor.web 'mysql --defaults-extra-file=~/.my_agenda.cnf cbl116984_blondorc_agenda' < scripts/sql/<script>.sql
   ```

Para volver atrás: `ssh blondor.web 'mysql --defaults-extra-file=~/.my_agenda.cnf cbl116984_blondorc_agenda < ~/respaldo_servicios_<fecha>.sql'`
(restaura esas tablas completas tal como estaban al respaldar).

## Historial

| Script | Qué hace | Producción |
|---|---|---|
| `2026-10-25_limpieza_servicios.sql` | Borra 31 servicios nunca agendados (depilación, manicure, faciales, masajes corporales, pruebas) y las 3 categorías que quedan vacías | ejecutado 25-10-2026 21:54 (respaldo `~/respaldo_servicios_20261025_215419.sql`) |
| `2026-10-29_precios_por_largo.sql` | Precios de corto, mediano, largo y extra largo para 9 servicios (propuesta: corto = precio actual, luego +15/30/45 %). Requiere la migración 063 | pendiente |
