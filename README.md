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

Para probar desde el celular u otro equipo de la casa: en PowerShell como administrador ejecuta
`scripts/dev_lan.ps1` (deja el sitio en `http://<IP del PC>:8087` y la agenda en `:8088`; `-Off` lo cierra).
Si se reinicia WSL, su IP cambia y hay que volver a ejecutarlo.

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

## Gift cards (Webpay Plus)

- Venta en blondor.cl (sección «Gift cards») → `agenda/gift_cards/checkout` → Webpay → la gift card se activa solo
  cuando Transbank confirma el pago desde el servidor, y se envía por correo. Canje y gift cards del salón en `/giftcards`.
- Credenciales en `agenda/config.php` (`WEBPAY_ENVIRONMENT`, `WEBPAY_COMMERCE_CODE`, `WEBPAY_API_KEY`, ver
  `config-sample.php`). En local: ambiente de pruebas de Transbank (tarjeta 4051 8856 0044 6623, CVV 123,
  RUT 11.111.111-1, clave 123). Sin credenciales la venta online queda desactivada.

## Reserva pública con pago

La reserva de `http://localhost:8088/index.php/booking` **pide pago**: la clienta aparta la hora, elige abono
($15.000 con servicios de $30.000 o más, $8.000 con los más baratos) o pago completo, y paga con Webpay. La cita
solo se confirma cuando el servidor validó la autorización.

- Detalle del diseño, las tablas, el cron y las pruebas: [`docs/reserva_con_pago.md`](docs/reserva_con_pago.md).
- Mantenimiento (pagos a medio camino, reservas vencidas y avisos): `php index.php console payments` (cron cada
  5 minutos).
- Pruebas locales: `scripts/run_payments_tests.sh` (base aislada; Webpay, correo, calendarios y webhooks simulados).

## Galería de trabajos (portada de blondor.cl)

La portada de blondor.cl es la galería. Las fotos salen de la subcarpeta **«imagenes»** de `blondor_api`, la
carpeta de Drive de Publicador (`GOOGLE_DRIVE_ROOT_FOLDER_ID`, cuenta `publicador-drive-reader`): lo que se pone
ahí aparece en el sitio en menos de una hora, y lo que se saca desaparece. Los videos (`videos`) todavía no.

- Orden: los archivos que empiezan con número («01 Balayage miel.jpg») van primero y en ese orden; el resto, de la
  más nueva a la más antigua. El nombre sin número ni extensión es el pie de foto; los nombres de cámara
  (`IMG_1234`, `PXL_…`, `WhatsApp Image…`) quedan sin pie. Se publican hasta 40; la portada muestra 10 y
  «Ver más trabajos» muestra las demás.
- `scripts/galeria/` corre en jorgesv (`blondor-galeria.timer`, cada hora): baja las fotos con la llave de
  Publicador (`/srv/publicador/secrets/google-drive-service-account.json`), las deja en webp de 1200 y 600 px sin
  EXIF y las sube por ssh a `~/blondor/site/assets/galeria/` del cPanel junto con `galeria.json`
  (no versionado).
- Instalar o actualizar: `scripts/galeria/install_jorgesv.sh`. La primera vez imprime una llave pública que hay
  que agregar a `~/.ssh/authorized_keys` del cPanel. Ejecutar a mano: `ssh jorgesv sudo systemctl start
  blondor-galeria.service`; registro: `ssh jorgesv journalctl -u blondor-galeria`.
- Si falla Drive, se mantiene el último `galeria.json` bueno. Si no existe o tiene menos de 4 fotos, el sitio
  muestra las 4 de respaldo que vienen en `site/index.html`.
- Pruebas (Drive simulado): `cd scripts/galeria && npm install && npm test`.

## Notas

- El sitio lee servicios y precios de la agenda (`agenda.blondor.cl/index.php/public_catalog`, solo lectura):
  cambiar un precio en `/precios` lo cambia también en blondor.cl. Los orígenes permitidos están en
  `agenda/application/controllers/Public_catalog.php`.

- `agenda/config.php` es la configuración local; la de prod queda en `agenda/config.prod.php` (no versionada).
- Con `DEBUG_MODE = false` la app carga los `.min.js`/`.min.css`: `scripts/build_min.sh` los regenera.
  Al cambiar assets, sube `cache_busting_token` en `agenda/application/config/app.php` para que los
  teléfonos no sigan usando la versión guardada.
