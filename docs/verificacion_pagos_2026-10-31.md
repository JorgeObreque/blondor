# Verificación local de reservas con pago — 31 de octubre de 2026

Esta entrega continúa el código previo. No constituye autorización de despliegue. No se hicieron commits, push, conexiones a producción, cargos reales ni envíos a servicios externos para probar.

## Resultado reproducible

- `bash scripts/run_payments_tests.sh`: **37 pruebas aprobadas, 0 fallidas**. La última base aislada es `agenda_payments_test_e696b2aea283`; se conserva para inspección.
- `python3 scripts/tests/booking_payment_security_http.py`: **11 comprobaciones HTTP aprobadas**, contra localhost:8088, sin crear reservas ni transacciones.
- Sintaxis PHP: **331 archivos** de application/tests sin errores, usando PHP del contenedor.
- `node --check`: booking.js, booking_http_client.js, agenda.js y booking_payment_browser.js correctos.
- `bash -n scripts/run_payments_tests.sh` y `git diff --check`: correctos.
- `scripts/build_min.sh`: ejecutado; los cinco pares fuente/.min de agenda y reserva son idénticos. Token de caché: `BL261031P2`.
- La BD local original estaba en migración **64**. No se reseteó, no se sincronizó desde producción ni se borraron volúmenes.

El runner crea una base nueva con esquema, settings, roles y versión de migración. No copia clientas ni reservas. La suite sustituye Webpay, notificaciones, sincronización y webhooks; comprueba también que el doble de Webpay no se invoque dentro de una transacción SQL. Los workers paralelos exigen consola, configuración Docker local, ambiente integration y una base aislada con nombre validado.

## Defectos corregidos en esta entrega

1. Un retorno de abandono ya no declara un intento abortado basándose en campos del navegador: consulta el estado remoto y conserva pagos aprobados o inciertos.
2. El primer commit marca durablemente el intento como incierto antes de llamar a Webpay. Tras timeout/crash se recupera con consulta de estado; no se repite commit. El cron no consume el primer commit al consultar una transacción INITIALIZED.
3. Los holds vencidos con intentos pendientes o inciertos, y los casos en revisión, continúan bloqueando disponibilidad y escrituras internas. La liberación no puede ignorar esos intentos.
4. El checkout reserva el intento en una transacción corta serializada con el horario; otra llave/pestaña reutiliza la transacción vigente. La llamada HTTP se hace después del commit SQL.
5. Las escrituras de citas, indisponibilidades y cierres del salón toman los mismos bloqueos por profesional/fecha que las reservas públicas. Consultar un hold antes de escribir sin bloqueo no era suficiente.
6. El precio de una cita creada después del pago conserva el snapshot aunque cambie el catálogo mientras la clienta está en Webpay. Las citas pagadas conservan sus campos financieros y no admiten cambios implícitos de servicio/largo.
7. El borrado directo de citas y el borrado por cascada desde clientas, profesionales o servicios no pueden eliminar historial pagado. La cancelación conserva los importes y libera el horario.
8. Los cuatro endpoints de mutación exigen POST y mantienen la protección CSRF. La ruta antigua booking/register sigue cerrada.
9. Los errores de notificaciones, sincronización y webhooks se propagan en modo estricto al outbox, para que no se marquen como entregados por haber sido solo registrados en logs. El comportamiento de sus otros consumidores se conserva.
10. HTTP 422 ya no equivale a transacción inexistente; un response_code inválido tampoco equivale a cero.

La distinción entre 404 y 422 se contrastó con la [guía oficial de errores de Transbank](https://ayuda.transbank.cl/errores-webpay-plus). La recuperación usa la [consulta de estado documentada por Transbank](https://proyecto-ejemplo-php.transbankdevelopers.cl/api-reference/webpay-plus).

## Cobertura observada

La suite verifica límites $29.999/$30.000, total $5.000, variantes y servicios desde; aprobación, rechazo, abandono, respuesta incorrecta e incertidumbre; snapshot antes y después de editar tarifas; reagenda autorizada sin nuevo cobro; cancelación, saldo y trazabilidad; ausencia de cita/aviso antes del pago; errores de outbox y recuperación.

Hay pruebas con **dos procesos PHP simultáneos**, además de las secuenciales: callbacks con un solo commit y una sola cita; dos clientas disputando capacidad uno; y reserva pública frente a escritura interna disputando el mismo horario. Los últimos dos casos verifican un ganador y una sola adjudicación entre holds y citas. La escritura interna pasa por el modelo compartido por agenda y API; no es una prueba HTTP autenticada de cada controlador administrativo.

Las comprobaciones HTTP verifican página de reserva, rechazo de GET a prepare/checkout/confirm/release, rechazo de POST sin CSRF, validación de entrada y cierre de booking/register.

## Pendiente antes de producción

- Completar una aceptación de navegador con aprobación y rechazo reales del **ambiente de integración** de Transbank, incluido el retorno final. Esta entrega no ejecutó el script de tarjeta/banco; no se presenta un flujo incompleto del banco como aprobado. El script ahora falla si no verifica el estado final y exige el host de integración antes de ingresar la tarjeta de prueba.
- Probar la actualización desde un esquema 63 en una copia aislada representativa. El esquema local ya era 64 y el runner parte de ese esquema; esto no demuestra la migración 63 → 64.
- Revisar visualmente resultado, confirmación y agenda con los datos de pago, y ejecutar aceptación HTTP autenticada de agenda/API y cierres con los permisos habituales.
- Instalar y verificar el cron en producción al desplegar, con la ruta real de PHP 8.2 de cPanel. No se modificó el cron del hosting. Comando documentado: `*/5 * * * * cd ~/blondor/agenda && php index.php console payments >> storage/logs/payments.log 2>&1` (ajustar el binario de PHP).
- Revisar entrega con proveedores de correo/calendario/webhooks en un entorno de prueba controlado. En esta entrega se usaron dobles. El outbox entrega al menos una vez: un fallo parcial puede repetir acciones externas; no garantiza entrega exactamente una vez.

La política de devolución, pérdida o crédito ante cancelación/inasistencia sigue pendiente de decisión del usuario. No se implementaron pérdidas, devoluciones ni cobros adicionales automáticos.

Se preservaron los cambios Open Graph, la imagen social, su script, .claude, untitled.md y .tmp_verify. Esta última contiene utilidades previas de navegador/CDP y Webpay; no se borró ni se ejecutó sin revisión.
