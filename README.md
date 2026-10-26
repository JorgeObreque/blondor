# Blondor

Monorepo de Blondor: el sitio público y la agenda. Lo que está en `main` es lo que corre en producción.

| Carpeta | Qué es | Producción |
|---|---|---|
| `site/` | Sitio público (HTML estático + `includes/mail.php`) | https://blondor.cl |
| `agenda/` | Agenda, Easy!Appointments 1.5 con cambios de Blondor | https://agenda.blondor.cl |
| `docker/`, `docker-compose.yml` | Entorno local (solo desarrollo) | — |
| `scripts/` | Sincronizar desde prod, generar `.min`, enlazar el sitio en prod | — |

## Local

```bash
scripts/sync_from_prod.sh db   # primera vez: baja la BD de prod (requiere ssh blondor.web)
docker compose up -d --build
```

| Servicio | URL |
|---|---|
| Sitio (blondor.cl) | http://localhost:8087 |
| Agenda (agenda.blondor.cl) | http://localhost:8088 (mismos usuarios que prod) |
| Reserva pública | http://localhost:8088/index.php/booking |
| Correos enviados (Mailpit) | http://localhost:8025 |
| MariaDB | `localhost:3307`, usuario/clave `agenda`/`agenda`, BD `agenda` |

Los correos nunca salen a clientes reales: PHP los entrega a Mailpit (también los del formulario del sitio).
Webhooks y sincronización de calendarios se desactivan al importar (`docker/db/init/02_local.sql`).

### Refrescar desde producción

- `scripts/sync_from_prod.sh db` — dump nuevo y BD local recreada (borra cambios locales en la BD).
- `scripts/sync_from_prod.sh code` — código de la agenda de prod sobre `agenda/` (no pisa `config.php`).
- `scripts/sync_from_prod.sh site` — sitio de prod sobre `site/`.

Después de sincronizar, revisa con `git status`: cualquier diferencia es algo que se cambió en prod fuera del repo.

## Producción

El hosting es un cPanel compartido: no hay contenedores. Producción es un clon de este repo en `~/blondor`
y `public_html` apunta a él con enlaces:

```
~/public_html/agenda      -> ~/blondor/agenda
~/public_html/index.html  -> ~/blondor/site/index.html   (cada entrada de site/ tiene su enlace)
```

Para enlazar el sitio la primera vez (mueve lo anterior a `~/respaldo_sitio_<fecha>/`, no borra nada):

```bash
ssh blondor.web 'bash -s' < scripts/prod_link_site.sh
```

Si agregas un archivo nuevo en la raíz de `site/`, vuelve a ejecutarlo para que tenga su enlace.

### Desplegar

```bash
scripts/build_min.sh                      # si cambiaste .js/.css de la agenda (prod usa los .min)
git push
ssh blondor.web 'cd ~/blondor && git pull --ff-only && cd agenda && php index.php console migrate'
```

Fuera de git en el servidor: `agenda/config.php`, `agenda/.well-known`, `agenda/cgi-bin`, el contenido de
`agenda/storage` y, en `public_html`, `.well-known`, `cgi-bin`, `agenda_old` y las páginas del hosting.

## Notas

- El sitio lee servicios y precios de la agenda (`agenda.blondor.cl/index.php/public_catalog`, solo lectura):
  cambiar un precio en `/precios` lo cambia también en blondor.cl. Los orígenes permitidos están en
  `agenda/application/controllers/Public_catalog.php`.

- `agenda/config.php` es la configuración local; la de prod queda en `agenda/config.prod.php` (no versionada).
- Con `DEBUG_MODE = false` la app carga los `.min.js`/`.min.css`: `scripts/build_min.sh` los regenera.
  Al cambiar assets, sube `cache_busting_token` en `agenda/application/config/app.php` para que los
  teléfonos no sigan usando la versión guardada.
