# Agenda Blondor como SaaS: evaluación del refactor

Fecha: 2026-11-05. Base: código de `agenda/` en `main` (`d812a97c`).
Reemplaza una evaluación anterior que no quedó guardada.

## 1. Qué es hoy la agenda

| | |
|---|---|
| Base | Easy!Appointments 1.5.0 (PHP, CodeIgniter 3, jQuery). Licencia **GPLv3** |
| Tamaño | ~50 mil líneas PHP + ~23 mil JS propias de la app (sin vendor) |
| Lo hecho para Blondor (desde el snapshot del 23-oct) | 76 archivos nuevos (~32 mil líneas), 44 archivos de EA modificados, 9 migraciones (060–068) |
| Instalación | Una sola: un `config.php` con constantes (URL, BD, claves), una BD con prefijo `ea_`, en hosting cPanel |
| Marca fija | «Blondor» aparece ~170 veces en ~80 archivos (textos, correos, PWA, logos) |
| Chile fijo | CLP, Webpay Plus, zona horaria de Santiago, WhatsApp: 19 archivos |

### Qué parte es de Blondor y qué parte es de EA

**Ya es propio** (el valor comercial está aquí):
- agenda móvil del día, agendar en 3 pasos, estados y WhatsApp (`Agenda.php`, `agenda.js`)
- reserva pública móvil (EA modificado: `Booking.php`, `booking.js`, `booking.css`)
- catálogo de servicios con variantes por largo, alza masiva y precio guardado en la cita
- ficha de clienta y ficha técnica por visita
- pagos y abonos con Webpay, gift cards, bloqueo de horas mientras se paga (`Slot_lock`)
- push al personal, recordatorios, embudo de reserva y origen de la visita (`?ref=`)
- acceso al Publicador con la sesión de la agenda

**Sigue siendo EA** (lo que habría que reemplazar para «eliminar EA»):
- modelo de datos: usuarios, roles, prestadores, servicios, citas, ajustes
- **motor de disponibilidad** (`Availability.php`, ya modificado) y reglas de reserva
- login, permisos, recuperación de clave
- correos y notificaciones, API v1, webhooks
- sincronización con Google Calendar / CalDAV
- páginas viejas del panel: calendario, ajustes, prestadores, secretarias, LDAP, Matomo, instalador, actualizador

## 2. Qué falta para venderla

1. **Varios salones.** Hoy no existe el concepto de «salón»: la configuración es global (`ea_settings`, constantes en `config.php`) y ninguna tabla tiene `tenant_id`.
2. **Marca configurable.** Nombre, logo, colores, textos y correos tienen que salir de la configuración de cada salón, no del código.
3. **Pagos por salón.** Cada salón necesita su propio comercio Webpay (o Mercado Pago), y el cobro de la suscripción al salón es aparte.
4. **Infraestructura.** Un cPanel compartido no sirve para N clientes: hacen falta contenedores o un VPS, respaldos por salón, monitoreo y tareas programadas (recordatorios, push) por salón.
5. **Producto.** Registro y alta de un salón nuevo, planes y facturación, soporte, términos y política de privacidad. La nueva ley chilena de datos personales (21.719) entra en vigencia en diciembre de 2026, y aquí se guardan datos de clientas de terceros.
6. **Licencia.** GPLv3 permite ofrecerla como servicio sin publicar el código (no es AGPL). Si se **entrega** el software a un salón (instalarlo en su servidor), ese código tiene que ir con licencia GPL. Hay que mantener los avisos de copyright de EA en el código. Con una reescritura completa (opción C) la restricción desaparece.

## 3. Opciones

### A. Una instalación por salón sobre el código actual (no elimina EA)
- Sacar «Blondor» del código y llevarlo a la configuración: nombre, logo, colores, textos, correos, claves de Webpay.
- Script o plantilla Docker que crea un salón: BD, subdominio `salon.tudominio.cl`, `config.php`, tareas programadas.
- Panel mínimo propio para dar de alta salones y cobrar la suscripción.
- **Esfuerzo:** ~4–8 semanas a medio tiempo. **Riesgo:** bajo.
- **Contra:** se sigue cargando CodeIgniter 3 (sin desarrollo activo) y una instalación por cliente. Funciona hasta unas decenas de salones, no cientos.

### B. Volver multi-salón el propio EA (no recomendado)
- `tenant_id` en todas las tablas y filtrar cada consulta de cada modelo de EA.
- Es la opción más riesgosa: se toca todo el código heredado, una consulta sin filtro muestra datos de otro salón, y al final se sigue en CI3 + jQuery.

### C. Producto nuevo multi-salón, reemplazando EA por partes (elimina EA)
- App nueva, multi-salón desde el diseño (`salon_id` en todo, aislamiento probado con tests). Stack sugerido: Laravel, porque reutiliza PHP y el hosting que ya conoces; o TypeScript si prefieres un solo lenguaje con el Publicador.
- Se migra por partes, con Blondor como primer salón:
  1. modelo de datos + login/roles + ajustes por salón
  2. motor de disponibilidad, portado con los casos de prueba del actual
  3. reserva pública y agenda móvil (el JS/CSS propio se reaprovecha en gran parte)
  4. pagos, abonos, gift cards, Webpay por salón
  5. push, recordatorios, embudo, integración con Publicador
  6. migrar los datos de Blondor y apagar EA
  7. registro de salones, planes y cobro
- Se deja fuera de EA lo que no se usa: LDAP, Matomo, CalDAV, secretarias, instalador, actualizador.
- **Esfuerzo:** ~4–6 meses a medio tiempo. **Riesgo:** medio, sobre todo en la disponibilidad y en la migración de datos.

## 4. Recomendación

1. **No hacer B.**
2. Si el objetivo inmediato es **validar que hay salones dispuestos a pagar**, hacer **A**: 2–3 salones piloto en pocas semanas, sin reescribir nada. El trabajo de sacar la marca del código se aprovecha después en C.
3. Si el objetivo es **un producto propio sin EA** (o vender instalaciones sin GPL), hacer **C** por fases, con Blondor siempre en producción sobre la agenda actual hasta el paso 6.
4. Camino sugerido: A para validar → C cuando haya 3 o más salones pagando o cuando A empiece a doler.

## 5. Decisiones pendientes

- ¿Validar primero (A) o ir directo a producto propio (C)?
- Mercado: ¿solo Chile (Webpay/CLP) o también otros países?
- Stack de C: Laravel (PHP) o TypeScript.
- Precio y plan (mensual por salón o por profesional).
