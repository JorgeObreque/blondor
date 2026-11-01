# Notificaciones push del backoffice de Blondor

La agenda permite instalarse en el inicio del iPhone y activar Web Push desde una cuenta del equipo. La reserva pública no muestra controles de push ni registra el service worker.

## Activación para Guillermina

1. Abrir https://agenda.blondor.cl/index.php/agenda en Safari e ingresar al backoffice.
2. Compartir → Agregar a inicio.
3. Abrir Blondor desde el icono e iniciar sesión si se solicita.
4. Abrir la campana de la agenda o el menú **Notificaciones**.
5. Pulsar **Activar notificaciones** y aceptar el permiso del iPhone.

Se necesita iOS 16.4 o posterior. Desde Safari sin instalar, la página muestra los pasos y no solicita permiso. El permiso solo se pide al pulsar Activar. En otros navegadores compatibles también se puede activar desde esa página. Los modos de concentración, los permisos del sistema y la conexión pueden afectar la recepción; se conserva el correo como respaldo.

Los avisos muestran únicamente que hubo una reserva confirmada, un cambio de horario o una cancelación. No incluyen nombre, teléfono, servicio, importe ni enlaces públicos de gestión en la pantalla bloqueada. Al tocarlos se comprueba el acceso al backoffice y se abre la cita en su fecha/profesional. Si la sesión venció, se solicita ingresar.

Cada dispositivo requiere activación propia y puede desactivarse desde la misma pantalla. Cerrar sesión revoca la suscripción asociada a esa sesión; al volver a entrar se puede activar nuevamente. Máximo cinco dispositivos activos por cuenta. La suscripción push es independiente del interruptor de correos.

## Funcionamiento

- Migración 65: `push_subscriptions` y `push_deliveries`. No modifica citas ni importes.
- `Staff_push` valida cuentas y permisos: administradoras, profesional de la cita y secretarias vinculadas a esa profesional. Las clientas no pueden suscribirse.
- La suscripción se vincula al usuario autenticado, nunca a un id enviado por el navegador. Mutaciones POST con CSRF, respuestas privadas sin caché.
- Endpoint limitado a servicios de push de Apple, Google, Mozilla y Microsoft mediante HTTPS/443; sin direcciones internas, credenciales en la URL ni redirecciones HTTP.
- Las entregas se encolan junto al evento de la reserva. Un pago aún no aprobado no encola push. Los eventos cubiertos son reservas públicas pagadas y su reagenda/cancelación pública; no se anuncian como avisos de todas las operaciones administrativas.
- Correo y push se entregan de forma independiente: un fallo de correo no impide el push. La cola revalida propietario del dispositivo y permisos antes de enviar, y descarta avisos de citas canceladas que habían quedado pendientes como creación/reagenda.
- Reintentos con espera, máximo ocho intentos y caducidad de un día. Suscripciones vencidas (404/410) se desactivan. La etiqueta del aviso permite reemplazar duplicados visibles; la entrega es al menos una vez.
- El cron existente `console payments` entrega también push. No hay cron adicional ni nuevo servicio de pago.
- El service worker no tiene manejador fetch ni guarda páginas privadas para uso offline. Se registra desde la pantalla de notificaciones autenticada, con alcance técnico del origen para poder abrir login y las páginas de backoffice; no altera el flujo público.

## Dependencias y claves

Transporte: `minishlink/web-push` v9, instalado con su lock en `application/third_party/web_push`. Está separado del vendor original de Easy!Appointments. Su autoloader respeta las dependencias ya cargadas de la aplicación. El paquete conserva sus licencias y composer.lock; no se añade un proveedor comercial.

Las claves VAPID se crean una sola vez:

```sh
php index.php console migrate
php index.php console push_setup
```

Se conservan fuera del document root, en `../.blondor-private/web-push-vapid.json`, con directorio 0700 y archivo 0600. No se imprimen, no se incluyen en el manifiesto y están excluidas de Git. `push_setup` conserva una pareja existente; no rotarlas durante despliegues. Guardar su respaldo privado junto al respaldo de BD para conservar las suscripciones.

En Docker, si setup se ejecuta como root y Apache corre como www-data, el directorio privado debe pertenecer a www-data. La carpeta privada local está fuera del volumen agenda; conservarla al recrear contenedores o volver a activar las suscripciones de desarrollo. Producción conserva las claves en el filesystem persistente del hosting.

## Validación

```sh
bash scripts/run_payments_tests.sh
python3 scripts/tests/backoffice_push_http.py
node scripts/tests/backoffice_push_worker.js
```

El runner usa una base aislada con fixtures y dobles de Webpay, correo y push. Las siete pruebas push cubren endpoints no válidos/SSRF, cifrado AES128GCM y firma VAPID con HTTP simulado, suscripción exclusiva del personal e idempotencia, ausencia de aviso antes del pago, independencia del correo, caducidad/reintentos y revalidación de propietario/permisos.

Las 21 comprobaciones HTTP usan una cuenta sintética local que se elimina al terminar. Comprueban permisos, CSRF, manifiesto, suplantación del id de usuario, activación/desactivación y cierre de sesión. El navegador móvil usa APIs Push/Notification simuladas: comprueba activación solo por interacción, ajuste persistido, ausencia de desbordamiento y guía de instalación iPhone. No crea reservas ni envía mensajes. El service worker se comprueba separadamente con eventos simulados y enlaces internos/externos.

La recepción final en el iPhone físico requiere instalar y conceder permiso desde ese dispositivo. Ninguna prueba automatizada sustituye ese paso ni acredita recepción real en APNs.

Referencias: [WebKit — Web Push en iOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/) y [transporte PHP Web Push](https://github.com/web-push-libs/web-push-php).

## Entrega productiva

Publicada el 31 de octubre de 2026 (Chile), entrega `backoffice-push-20261101T005656Z` (UTC).

- 37 pruebas de pagos y 7 de push aprobadas; 21 comprobaciones HTTP autenticadas locales y pruebas de navegador/worker correctas.
- 1.017 archivos PHP locales sin errores de sintaxis; 693 PHP del paquete verificados en el hosting. Composer audit de las dependencias push sin avisos de vulnerabilidad.
- Migración 64 → 65 aplicada; 352 citas antes y después, reservas habilitadas.
- 24 comprobaciones HTTPS posteriores aprobadas: recursos y checksums, manifiesto/iconos, ausencia de instalación en la reserva pública, acceso privado, mutaciones POST y CSRF.
- Se corrigieron los permisos de lectura del directorio público de iconos (`assets/img/pwa`, 0755); las claves privadas conservan 0700/0600 fuera del document root.
- Cron anterior conservado, sin duplicarlo; su ejecución posterior no mostró errores. Las tablas de suscripciones/entregas estaban vacías al cerrar la entrega: requiere activación desde cada dispositivo.
- La configuración, los layouts Open Graph y la imagen social se verificaron sin cambios. Las notificaciones por correo de Guillermina siguen activas.
- Respaldo privado: `~/blondor_releases/backoffice-push-20261101T005656Z/`, incluido respaldo de BD, código anterior y claves VAPID. No restaurar una BD anterior sobre citas/pagos nuevos para revertir esta funcionalidad.
- Sin commits, push, cargos, correos ni notificaciones reales de prueba. La recepción en el iPhone físico queda pendiente de instalar y otorgar permiso desde el dispositivo.

## Prueba autorizada en producción y corrección de VAPID

El usuario solicitó expresamente una notificación de prueba en producción. El dispositivo de Guillermina estaba suscrito y activo. Los primeros intentos fueron rechazados por Apple con HTTP 403 `BadJwtToken`: en consola, `base_url()` producía una identidad HTTP, diferente de la ejecución web HTTPS.

Se corrigió `Staff_push` para usar como identidad VAPID el contacto `mailto:` de la empresa y forzar HTTPS en los enlaces de citas generados por consola para hosts productivos. No se rotaron las claves ni se reemplazó la suscripción. Se agregó una regresión de consola: ocho pruebas push y 37 de pagos aprobadas.

La prueba posterior fue aceptada por Apple con HTTP **201**, el 1 de noviembre de 2026 a las 01:39 UTC (31 de octubre en Chile). Se envió «Blondor · Notificación de prueba», sin datos de clientas; se registró el éxito del dispositivo. La aceptación del servicio está comprobada; que aparezca en la pantalla del iPhone debe confirmarlo la destinataria. No se crearon citas ni se enviaron correos. Los intentos compartieron la misma etiqueta para evitar duplicados visibles; solo uno fue aceptado.

Respaldo privado de la corrección: `~/blondor_releases/push-identity-<fecha UTC>/`. Los registros privados de cada intento están en `~/blondor_releases/push-test-<fecha UTC>/`, sin endpoints ni claves en la salida de diagnóstico.
