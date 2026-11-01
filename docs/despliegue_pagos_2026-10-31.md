# Entrega de pagos en producción — 31 de octubre de 2026

Entrega: `payments-20261031T202048Z`.
Destino: https://agenda.blondor.cl.
Autorización: el usuario pidió «listo pasemos a prod» después de revisar el flujo y los ajustes visuales.

## Publicación

Se publicaron 50 archivos de agenda mediante un paquete con manifiesto y SHA-256, sin commits ni push. La base Git local y remota era `7fbea2bc`. Se verificó que los archivos remotos coincidieran con esa base antes de sustituirlos. El checkout remoto queda con los cambios publicados sin commit; un futuro despliegue por Git deberá considerar este estado.

La configuración `agenda/config.php`, ambos layouts con los cambios Open Graph y la imagen social se conservaron y verificaron por SHA-256 antes y después. No se publicó .claude, .tmp_verify, untitled.md, configuración local, fixtures, scripts de navegador ni datos locales.

Cambios visibles incluidos: pago obligatorio antes de confirmar, abono o pago completo según precio calculado por servidor, +56 fijo con número completo almacenado, «Favoritos de nuestras clientas» y vuelta a blondor.cl tras 10 segundos solamente en el resultado de pago aprobado. No se incluyó el aviso inicial ni su aceptación, eliminados a petición del usuario.

## Verificación previa

- 37 pruebas PHP aprobadas en `agenda_payments_test_96e78acdc7e4`, sin llamadas externas.
- 11 comprobaciones HTTP locales aprobadas.
- Migración 63 → 64 ensayada en `agenda_migration_test_27eceae46cad`: una cita sintética previa conservó su precio, quedó como legacy y no recibió un pago ficticio; se crearon las cuatro tablas nuevas.
- La base local contiene un intento de integración autorizado y su cita con abono, resultado del recorrido manual del flujo. No se hicieron cargos reales para verificar la entrega.
- El paquete se preparó en un runtime privado del hosting. Sus 36 archivos PHP pasaron `php -l` con PHP 8.2.35 antes de publicar.

## Activación y comprobaciones posteriores

Se pausaron brevemente las reservas públicas durante la migración y la copia de archivos. Se restauró su configuración original al terminar: reservas habilitadas.

- Migración productiva: **63 → 64**.
- Citas antes/después: **352 / 352**; canceladas: **1 / 1**.
- Webpay se mantiene en **production**, con credenciales presentes y sin modificarlas.
- **32 comprobaciones HTTPS aprobadas**, incluidas página pública, prefijo fijo, ausencia del aviso eliminado, rechazo de GET en mutaciones, CSRF, validación de entrada, bloqueo de booking/register, resultado desconocido sin redirección automática, acceso administrativo y API sin autorización.
- **14 assets** servidos por HTTPS coincidieron exactamente con el manifiesto. Versión de caché: `BL261031P7`.
- Las verificaciones no crearon reservas, transacciones de Webpay ni correos de prueba en producción. Las cuatro tablas nuevas estaban vacías al finalizar.

## Mantenimiento

Se instaló un único trabajo de pagos, preservando el crontab anterior:

```cron
*/5 * * * * cd /home2/cbl116984/blondor/agenda && /usr/bin/flock -n storage/cache/payments-maintenance.lock /usr/local/bin/php index.php console payments >> storage/logs/payments.log 2>&1
```

`flock` impide ejecuciones superpuestas del mantenimiento. El registro es `~/blondor/agenda/storage/logs/payments.log`. Se verificó también la primera ejecución programada: terminó sin errores, con reconciled/rejected/attention/expired/outbox en cero. Se comprobaron la instalación del trabajo, la existencia del binario y los permisos de cache/logs. No se forzó un envío real para probarlo.

## Respaldo y recuperación

Directorio privado en el hosting: `~/blondor_releases/payments-20261031T202048Z/`.

- `before-code.tar.gz`: código anterior y archivos protegidos.
- `before-db.sql.gz`: respaldo de BD previo a la migración.
- `before-crontab.txt`: cron previo.
- `manifest.json`, `protected-hashes.json`, `before-state.json`, `activation.json` y `final-check.json`: estado y comprobaciones.
- `new/` y `runtime/`: paquete y preparación privada.

Ante una incidencia, pausar reservas nuevas antes de revertir código. La reversión debe conservar la BD y su historial financiero: no ejecutar migration down ni restaurar el dump sobre pagos o reservas recibidos después de la publicación. El procedimiento de activación tenía recuperación de código y cron en caso de error, conservando la BD y dejando reservas pausadas; no fue necesario activarla.

Sigue pendiente la decisión comercial sobre devolución, pérdida o crédito ante cancelación/inasistencia. La entrega no aplica pérdidas, devoluciones ni cobros adicionales automáticos.
