# Sesión PWA y anticipación de reservas — 3 de noviembre de 2026

Autorización: «despliega en prod». Entrega `session-margin-20261103T171358Z` sobre la base Git local/remota `3a14c62daa3e214efec6798c7a16dd55ab02bd83`. Sin commits ni push.

Se publicaron 11 archivos de aplicación con manifiesto SHA-256, validación de la versión remota previa y sintaxis PHP 8.2 antes de sustituir cada archivo mediante rename. Respaldo privado del estado anterior: `~/blondor/.blondor-private/releases/session-margin-20261103T171358Z/before.tar.gz`; `deployed.json` registra hashes anteriores y finales. No hubo migraciones ni cambios de configuración de pagos, cron o datos de negocio. Se verificó que config.php, ambos layouts públicos y la imagen social conservaran sus hashes.

La PWA verifica la sesión al volver a primer plano y ante respuestas de autenticación; redirige al login cuando venció, sin repetir operaciones. La consulta periódica de sesión no renueva la actividad. Un error de permisos con sesión válida o un fallo de conexión no fuerza el login. `/booking` exige un mínimo de 30 minutos tanto al listar horarios como al crear un hold nuevo; conserva márgenes configurados mayores y los reintentos idempotentes de holds existentes. No impone este margen a las reservas administrativas. Caché de assets: `BL261103S1`.

Verificación local: 10 comprobaciones HTTP de sesión y pruebas JS de redirección única, permisos, desconexión, reactivación y ausencia de reintentos POST. Suite aislada: 40 pruebas de pagos, 8 de push y 12 de recordatorios aprobadas. Sintaxis PHP/JS y git diff --check correctos.

Verificación productiva: 10 comprobaciones HTTPS aprobadas. Endpoint de sesión sin autenticar: 401 JSON y no-store; agenda sin autenticar: redirección al login; booking y login: 200 sin errores PHP; los cuatro assets JS coinciden por SHA-256. Una consulta de disponibilidad pública para hoy devolvió ocho horarios, todos al menos 30 minutos posteriores al momento de consulta. No se crearon reservas, pagos ni correos de prueba. El recorrido autenticado en la PWA del iPhone queda para comprobación con la usuaria; el comportamiento de expiración y reingreso se verificó localmente con una cuenta sintética.

El incidente previo sobre liberación de horarios tras cancelar no tiene una corrección incluida en esta entrega: sigue pendiente de reproducir con la fecha/hora y la vía de cancelación afectadas.
