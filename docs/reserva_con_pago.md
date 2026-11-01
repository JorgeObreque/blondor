# Reserva pública con pago (Webpay Plus)

La reserva pública de Blondor ya no confirma citas sin pago: la clienta aparta su hora, paga el abono o el
servicio completo con Webpay (Transbank) y recién ahí la cita queda confirmada.

Todo el dinero y los estados viven en el servidor: el navegador solo dice **qué** quiere pagar, nunca **cuánto**.

---

## 1. La política del abono

| Precio final del servicio | Abono | Saldo que queda en el salón |
|---|---|---|
| $30.000 o más | $15.000 | total − $15.000 |
| menos de $30.000 | $8.000 | total − $8.000 |
| servicio más barato que el abono | el total | $0 |

Reglas que la siguen:

- La clienta elige **abono** o **servicio completo** cuando el precio final es exacto (un precio fijo o el precio
  de una variante de largo).
- Con precio **“desde”** (sin precio final exacto) solo se puede pagar **abono**: el mínimo no es el total y no se
  etiqueta como si lo fuera. El saldo queda “a confirmar en el salón”.
- El abono nunca pasa del total: un servicio de $5.000 se paga completo (el abono es $5.000 y el saldo $0).
- Con un servicio “desde”, el abono se calcula con el mínimo conocido (que es el piso del precio final) y se
  topa a ese mínimo, para que siempre sea cobrable.

El cálculo vive en `agenda/application/libraries/Deposit_policy.php` (sin base de datos, se puede probar solo).

## 2. El recorrido

```
Reserva (3 pasos)  →  Paso 4: elegir abono o completo  →  Webpay  →  Resultado (pagado / rechazado / pendiente / en revisión)
```

| Ruta | Qué hace | CSRF |
|---|---|---|
| `booking_payment/prepare` (POST) | Valida todo, **aparta el horario** y devuelve las opciones de pago | sí (ruta propia, fuera de `booking/.*`) |
| `booking_payment/checkout` (POST) | Crea el intento de pago y devuelve el formulario de Webpay | sí |
| `booking_payment/confirm` (POST) | Confirma una reagenda que **no** necesita cobro | sí |
| `booking_payment/release` (POST) | Suelta una reserva que la clienta dejó a medias | sí |
| `booking_payment/result/{token}` (GET) | Estado del pago | no (es el retorno) |
| `booking/webpay_return` (POST) | Vuelve de Webpay: confirma el pago y recién ahí crea la cita | no (es la ruta de Webpay) |

`booking/register` (la vía pública vieja) **ya no crea citas**: responde con un mensaje que manda a la clienta
al flujo con pago. No hay atajo sin pagar.

## 3. Las tablas

| Tabla | Para qué |
|---|---|
| `ea_booking_holds` | La reserva: aparta el horario, guarda la ficha del precio (servicio, largo, duración, total, abono) y los datos de la clienta para poder terminar la cita sin el navegador. |
| `ea_payment_attempts` | Los intentos de pago: orden de compra, token, monto, estado y el lease que hace que solo uno confirme. |
| `ea_outbox_events` | Los avisos (correo, calendario, webhook) que se disparan solo cuando la cita ya está pagada. |
| `ea_slot_locks` | Una fila por profesional y fecha: es el bloqueo real del horario (ver más abajo). |

En `ea_appointments` se agregaron: `id_booking_holds`, `payment_status` (`internal`, `legacy`, `deposit_paid`,
`paid`, `attention`), `payment_mode`, `paid_amount`, `amount_due` y `total_amount`.

## 4. El bloqueo real del horario

Leer las citas “vacías” con `FOR UPDATE` no alcanza: dos procesos pueden leer lo mismo y los dos vender la hora.
Por eso hay una fila por **profesional y fecha** (`ea_slot_locks`) que se toma con `SELECT … FOR UPDATE` dentro
de una transacción corta:

1. Se toma (o se crea) la fila del profesional y la fecha y se bloquea.
2. Se comprueban las citas, las indisponibilidades, los períodos bloqueados y **las reservas a la espera de pago**.
3. Se escribe la cita o la reserva y se confirma la transacción.

Los profesionales se bloquean en orden ascendente, así que dos reagendas que se cruzan no se traban.

Vías que respetan el bloqueo: `Slot_lock` (reservas y reagendas), la agenda interna, la API y el calendario, y los
bloqueos de indisponibilidad (`Unavailabilities_model`). La reserva interna **no** pide Webpay, pero sí respeta
las horas que una clienta está pagando.

Nunca se hace HTTP dentro de una transacción.

## 5. El pago

- `Webpay::create()` se llama **fuera** de toda transacción; el intento de pago se guarda antes (con su orden de
  compra) para no perder el rastro si algo se cae.
- Al volver de Webpay se toma el control del intento con un **claim atómico** (lease): si dos callbacks llegan
  juntos, solo uno confirma; el otro ve que hay otro trabajando.
- El pago se acepta solo si: `status = AUTHORIZED`, `response_code = 0`, el **monto es exactamente** el esperado, y
  la `buy_order` y la `session_id` son las de la reserva.
- Doble clic o doble callback: la misma llave de idempotencia devuelve la misma transacción y la misma cita.
- Rechazo o cancelación en el formulario de Webpay: la reserva se suelta y **no se cobra nada**.
- Si la conexión se cae en el `commit`, el pago queda *desconocido*: no se assume nada, el cron pregunta a
  Transbank por el estado de la transacción y sigue desde ahí.

## 6. El aviso (outbox)

Cuando el pago se confirma, en la **misma transacción** que crea la cita se encola el aviso
(`appointment_paid`, con una llave única por cita). El aviso se entrega después (correo, calendario y webhook).

- Si un aviso falla, se reintenta con espera creciente; la **cita pagada no se invalida**.
- La entrega es **at-least-once**: un reintento puede mandar el mismo correo otra vez. No se promete
  exactly-once porque el correo y el webhook no aceptan idempotencia.
- La llave única evita encolar dos veces el mismo aviso. Una entrega parcial puede repetir correo, calendario o webhook al reintentar; no hay garantía de entrega exactamente una vez.

## 7. El mantenimiento (cron)

```bash
php index.php console payments
```

Hacerlo cada 5 minutos (o cada minuto). Es seguro correrlo las veces que sea: cada paso es idempotente.

1. **Recupera pagos a medio camino**: pregunta a Transbank el estado de las transacciones sin confirmar. Si
   están autorizadas, crea la cita y el saldo; si no se cobró, suelta la reserva.
2. **Expira las reservas que nadie terminó**: una reserva vencida con un pago sin confirmar **no** se suelta
   (el dinero puede ser real): pasa a “en revisión” y se sigue reconciliando. Solo se suelta cuando Transbank
   confirma que no se cobró (o que la sesión venció sin pago).
3. **Entrega los avisos** que quedaron pendientes.

## 8. La reagenda

- La reagenda necesita una **capacidad** (token) que se entrega en el formulario y se contrasta con la cita en el
  servidor: no se aceptan ids sueltos.
- **Mismo servicio y mismo precio**: se conserva el abono, no se cobra otra vez y la cita se muda de hora.
- **Cambio de servicio o de precio en una cita pagada**: no se permite en línea (se explica y se deriva al equipo).
  No hay cobros automáticos inventados.
- Una cita **sin** pago (las antiguas, `legacy`) se puede cambiar y pagar normalmente.

## 9. La cancelación

- La cancelación pública es **lógica**: la cita queda con estado `Cancelado` (así el horario se libera) y **no se
  borra**. El dinero y el histórico financiero se conservan.
- Todavía **no hay política de cancelación ni de no-presentación**: no se devuelve nada y no se pierde crédito
  automáticamente. El caso se ve en la agenda (estado “en revisión”) y se resuelve con el equipo.
- Una cita pagada **no se puede borrar** desde la API, la agenda ni la privacidad: se cancela, para que el pago
  quede registrado. Al borrar los datos de una clienta con citas pagadas, sus citas quedan desvinculadas y
  anonimizadas (el pago se conserva sin datos personales).

## 10. Los precios y las citas pagadas

- Cambiar el precio de un servicio **no revalora** las citas que ya están pagadas (ni las futuras con abono).
- La agenda muestra lo pagado y el saldo por separado.

## 11. Seguridad

- `prepare` y `checkout` exigen el token CSRF de la app (la ruta `booking_payment/…` no está en la lista de
  exclusión global; la vuelta de Webpay sí lo está, como la de las gift cards).
- El precio, el monto y el saldo los calcula el servidor. El navegador no puede alterarlos.
- El servicio tiene que ser público (no privado, no archivado) y con precio.
- La reserva exige el captcha (si está activo) **antes** de apartar el horario, y hay cuotas por equipo y por
  correo (40 y 20 reservas por hora) para que no se llene la agenda de basura.
- La llave de idempotencia viene del navegador y está **ligada al contenido de la reserva**: la misma llave con
  otros datos se rechaza.
- Los tokens de resultado son de alta entropía (32 bytes) y no se guardan datos personales en la URL.

## 12. Las pruebas

```bash
scripts/run_payments_tests.sh          # PHP aislado; Webpay y avisos simulados
```

| Prueba | Qué cubre |
|---|---|
| `agenda/tests/payments_selftest.php` (vía `php index.php console test_payments`) | Política (29999/30000/5000/“desde”), hold, doble clic, doble callback, rechazo, aborto, caída y recuperación, caducidad sin resultado, reserva interna vs hold, cambio de precio, reagenda sin cobro, cambio de servicio en cita pagada, cancelación que conserva el pago, no se puede borrar una cita pagada. Usa un **doble de Webpay**: no se mueve dinero. No corre si el ambiente es `production`. |
| `scripts/tests/booking_payment_http.py` | El flujo HTTP contra la app local: CSRF, opciones de pago, precio alterado por el navegador (se ignora), checkout, doble clic y la vuelta de Webpay sin pagar (no se crea cita). |
| `scripts/tests/booking_payment_browser.js` | El flujo completo en el navegador, con la tarjeta de prueba de Transbank (ambiente de integración): llega al formulario de Webpay con el monto correcto y, si el banco acepta las credenciales, la cita queda pagada. |

El runner crea una base `agenda_payments_test_<12 hex>` con solo el esquema, settings, roles y versión de migración locales. No copia reservas ni clientas. Las fixtures sintéticas (`zz_test_…`) se limpian al terminar; la base se conserva para inspección. La consola exige Docker local, ambiente integration y nombre de base aislada. Correo, calendarios y webhooks usan dobles, al igual que Webpay.

Los scripts HTTP/navegador están separados: crean holds en la app local y pueden contactar Webpay de integración; no forman parte del runner aislado. Solo admiten HTTP local, puerto 8088. No deben usarse como prueba de aprobación si omiten el resultado final del banco.

## 13. Puesta en marcha

1. Correr la migración: `php index.php console migrate` (crea las tablas y las columnas nuevas).
2. Regenerar los `.min`: `scripts/build_min.sh`.
3. Subir `cache_busting_token` en `agenda/application/config/app.php` (para que los teléfonos no usen la versión
   guardada).
4. Programar el cron: `*/5 * * * * cd ~/blondor/agenda && php index.php console payments >> storage/logs/payments.log 2>&1`.
5. Revisar que `WEBPAY_ENVIRONMENT` sea `production` en `config.prod.php` (ya está) y que las credenciales
   sean las de producción.

Nada de esto se puede probar en producción: los pagos de prueba son del ambiente de integración.

## 14. El origen de la reserva (`?ref=`)

Para saber qué anuncio trajo cada cita pagada (lo lee Publicador):

```
Anuncio → blondor.cl/?ref=ADS:CMP-XXXXXX-ABCD → agenda …/booking?service=62&ref=… → cita pagada con su origen
```

- `site/assets/js/site.js` toma `?ref=`, lo agrega a todos los enlaces de reserva y lo guarda en la cookie `bl_ref`
  (`Domain=.blondor.cl`, 7 días, gana el último clic). La agenda está en otro subdominio y no ve el `localStorage`
  del sitio: por eso la cookie.
- `booking.js` usa el `ref` del enlace o, si no viene, la cookie, y lo manda como `post_data.attribution_code`. Si la
  clienta llega directo a la agenda con `?ref=`, la agenda también guarda la cookie.
- El servidor lo valida (`^[A-Z0-9][A-Z0-9:_-]{1,47}$`, en mayúsculas) y descarta lo demás; queda fuera de la firma de
  la reserva. Va de `ea_booking_holds.attribution_code` a `ea_appointments.attribution_code` solo en reservas nuevas:
  una reagenda conserva el origen de la cita.
- La API (`/api/v1/appointments`) entrega `attributionCode`, `paymentStatus`, `paymentMode`, `totalAmount`,
  `paidAmount` y `amountDue`. Son de solo lectura: `api_decode` no los acepta.
- Cualquiera puede escribir un `?ref`: solo sirve para medir y nunca cambia un precio ni un monto.
- Para tráfico propio sin anuncio se puede usar otro código, por ejemplo `?ref=IG-BIO` en el enlace de Instagram.
- Si la clienta prefiere escribir, los botones de WhatsApp del sitio y de la reserva agregan `(ref: CÓDIGO)` al
  mensaje. La operadora lo copia en las notas de la cita y Publicador lo encuentra ahí.

## 15. El recorrido de la reserva y las reservas sin pagar

Para ver en qué paso se van las clientas de cada anuncio, y escribirles a las que apartaron hora sin pagar:

- El sitio y la reserva mandan cada paso con `navigator.sendBeacon` a `POST funnel/track` (texto plano, sin CSRF,
  responde siempre 204): `site` (entró a blondor.cl), `agenda` (abrió la reserva), `service`, `time`, `details` (envió
  sus datos y la hora quedó apartada) y `webpay` (salió a pagar). Las reagendas no cuentan.
- La visita se reconoce con la cookie `bl_sid` de `.blondor.cl`: un identificador al azar que vence tras 30 minutos sin
  actividad. No se guardan datos personales; cada visita cuenta una vez por paso (`ea_booking_funnel_events`).
- `GET /api/v1/booking_funnel?from=&till=` entrega, por origen, las visitas de cada paso y las reservas pagadas (la
  primera reserva pagada de cada cita, no sus reagendas) con lo cobrado.
- `GET /api/v1/booking_abandoned?from=&till=` entrega las reservas nuevas que apartaron hora y terminaron sin pago
  (`released`/`expired`), con el motivo (`gave_up`, `expired_unpaid`, `left_webpay`, `rejected`), los datos de
  contacto y `recovered` (la misma clienta, por correo o los 9 dígitos del teléfono, reservó después).
- Ambas usan el mismo usuario de la API. Publicador las muestra en "Reservas web".
