# Blondor — Agenda (Easy!Appointments 1.5) en local

Copia local de `agenda.blondor.cl` para desarrollar sin tocar producción.

## Levantar

```bash
scripts/sync_from_prod.sh db   # primera vez: baja la BD de prod (requiere ssh blondor.web)
docker compose up -d --build
```

| Servicio | URL |
|---|---|
| Agenda | http://localhost:8088 (mismos usuarios que prod) |
| Correos enviados (Mailpit) | http://localhost:8025 |
| MariaDB | `localhost:3307`, usuario/clave `agenda`/`agenda`, BD `agenda` |

Los correos nunca salen a clientes reales: PHP los entrega a Mailpit.
Webhooks y sincronización de calendarios se desactivan al importar (`docker/db/init/02_local.sql`).

## Refrescar desde producción

- `scripts/sync_from_prod.sh db` — dump nuevo y BD local recreada (borra cambios locales en la BD).
- `scripts/sync_from_prod.sh code` — código de prod sobre `agenda/` (no pisa `config.php`); revisar con `git diff`.

## Notas

- `agenda/config.php` es la configuración local; la de prod queda en `agenda/config.prod.php` (no versionada).
- Con `DEBUG_MODE = false` la app carga los `.min.js`: cada `.js` modificado necesita su `.min.js` actualizado.

## Producción

`agenda.blondor.cl` se sirve desde un clon de este repo en el servidor:
`~/public_html/agenda` → enlace a `~/blondor/agenda`. Desplegar:

```bash
ssh blondor.web 'cd ~/blondor && git pull --ff-only'
```

Antes de hacer push, si cambiaste algún `.js`/`.css`, ejecuta `scripts/build_min.sh` (producción usa los `.min`).
En el servidor quedan fuera de git: `agenda/config.php`, `agenda/.well-known`, `agenda/cgi-bin` y el contenido de `agenda/storage`.
