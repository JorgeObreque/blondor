# Recordatorios de citas por correo

Blondor programa dos recordatorios por cita, 24 horas y 2 horas antes del inicio. Se envían a la clienta y a Guillermina, usando el correo de administradora configurado (`gpi.siempremas18@gmail.com`). El cron de pagos existente comprueba también los recordatorios cada cinco minutos; el envío puede ocurrir unos minutos después del umbral.

## Reglas

- Los horarios se interpretan en la zona de la profesional y los plazos se calculan en UTC: 24 horas son 86.400 segundos, incluso durante el cambio de hora en Chile.
- Se incluyen reservas públicas confirmadas tras el pago y citas administrativas/anteriores válidas. No se crean recordatorios para holds sin cita, pagos en revisión, bloqueos, citas canceladas, inasistencias, citas con llegada registrada ni citas ya iniciadas.
- Cada destinataria recibe como máximo un envío por horario y plazo. El mismo correo no recibe dos copias si coincide la clienta con la destinataria administrativa.
- Reagendar invalida los pendientes del horario anterior. El nuevo horario recibe recordatorios cuyos plazos aún no habían pasado cuando se reagendó. Cambiar notas no reinicia la programación.
- Una reserva con menos de 24 horas de anticipación no recibe un «24 horas antes» inmediato; puede recibir el aviso de 2 horas si ese plazo todavía es futuro. Si se reserva con menos de 2 horas, la confirmación sigue siendo el aviso disponible.
- La activación no envía retrospectivamente plazos ya vencidos. Las citas existentes sí reciben los plazos que aún sean futuros.
- Se respetan `customer_notifications` y el interruptor de correo de la administradora. No se envía a una dirección inexistente o inválida ni a otros administradores.

## Entrega y duplicados

`appointment_reminders` guarda el plazo, la identidad del horario, la destinataria mediante hash y el estado. El índice único y la reclamación atómica evitan duplicados entre cron repetido y procesos concurrentes. Antes de enviar se vuelven a comprobar la cita, el horario, la cancelación, el correo actual y los permisos.

Los estados distinguen la preparación de la llamada a correo. Si el proceso se interrumpe antes de enviar, se puede recuperar. Si la llamada a correo queda con resultado incierto, pasa a `uncertain` y no se reenvía automáticamente: SMTP no garantiza idempotencia y reenviarlo a ciegas podría duplicarlo. Los fallos de preparación se reintentan con espera, hasta ocho veces; un fallo de una destinataria no bloquea a la otra. Los pendientes de 24 horas caducan al llegar al plazo de 2 horas, para no juntar ambos avisos; los de 2 horas caducan al empezar la cita.

Los correos muestran fecha, hora y zona horaria, servicio, profesional y dirección del salón. La clienta tiene un enlace de gestión; Guillermina, un enlace autenticado al backoffice y los datos de contacto de la clienta. No se adjuntan nuevas invitaciones de calendario ni se incluyen notas privadas.

## Operación

Migración 66: crea la cola, la marca de revisión de horario de la cita y los ajustes; comienza desactivada.

```sh
php index.php console migrate
php index.php console reminders_setup    # activa una vez, sin enviar correos de prueba
php index.php console reminders_status   # consulta sin encolar ni enviar
```

`console payments`, ya instalado cada cinco minutos con flock, ejecuta `Booking_reminders::run()` después de la recuperación de pagos/avisos. No se agrega otro cron. Su resumen incluye `reminders` con queued/sent/skipped/uncertain/retry. Los logs solo mencionan ids y estados; no imprimen direcciones ni contenido de correos.

Ajustes:

- `appointment_reminders_enabled`: 1 para activar, 0 para pausar.
- `appointment_reminders_started_utc`: comienzo de la programación, conservado al ejecutar setup nuevamente.
- `appointment_reminder_staff_email`: administradora destinataria.

Las citas conservan `reminder_schedule_changed_at_utc`, calculada por servidor al crearse o cambiar de horario/profesional. No puede imponerse desde un payload del navegador. Para citas anteriores se usa book_datetime en la zona horaria del servidor.

## Pruebas

`bash scripts/run_payments_tests.sh` corre 37 pruebas de pagos, 8 de push y 12 de recordatorios en una base aislada. Las pruebas de recordatorios usan correo simulado y reloj fijo; incluyen los dos plazos, dos procesos concurrentes, cancelación, reagenda, plazos pasados, activación, preferencias, envíos inciertos, recuperación antes de SMTP, horario de verano, protección de la revisión de horario y destinatarias independientes. No envían correos reales.

## Publicación

Activados en producción el 31 de octubre de 2026 (Chile), a las 01:56:12 UTC del 1 de noviembre. Entrega `booking-reminders-20261101T015419Z`.

- Cinco archivos publicados tras comparar checksums y validar PHP en el hosting.
- Migración 65 → 66; las 352 citas existentes se conservaron.
- Cron existente preservado, sin nuevos trabajos. Configuración, claves VAPID e imagen social verificadas sin cambios.
- Destinataria administrativa: la cuenta administradora con `gpi.siempremas18@gmail.com`, respetando sus notificaciones activadas.
- Activación sin correos retroactivos; la consulta posterior mostró cero destinatarios vencidos y ninguna fila en la cola, sin ejecutar envíos de prueba.
- 37 pruebas de pagos, 8 de push y 12 de recordatorios aprobadas. Las 11 comprobaciones HTTP de reserva y las 21 comprobaciones de backoffice locales pasaron; las 24 comprobaciones HTTPS de producción posteriores también.
- Respaldo privado de código/BD: `~/blondor_releases/booking-reminders-20261101T015419Z/`.

La primera ejecución programada con recordatorios queda registrada en `storage/logs/payments.log`, dentro del resumen `reminders`. `console reminders_status` es una consulta sin envíos. No se mandaron correos reales para probar, no se hicieron cargos ni commits/push.

### Comprobación posterior solicitada por el usuario

Se confirmó una ejecución programada del cron con el nuevo bloque `reminders`, sin errores ni envíos pendientes/inciertos. La última ejecución revisada fue el 1 de noviembre de 2026 a las 02:15:04 UTC.

La auditoría de solo lectura confirmó que tanto los correos de clientas como los de Guillermina están habilitados. Hay dos destinatarias previstas para cada uno de los plazos (24 y 2 horas); el siguiente plazo está programado para el 1 de noviembre de 2026 a las 18:15, hora de Chile. Esta comprobación no encoló ni envió correos ni modificó citas.
