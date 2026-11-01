<?php extend('layouts/backend_layout'); ?>
<?php section('content'); ?>
<section id="backoffice-notifications" class="container py-4" style="max-width: 640px"
         data-key="<?= e(vars('push_public_key')) ?>"
         data-worker="<?= e(base_url('backoffice-sw.js')) ?>" data-scope="<?= e(base_url()) ?>"
         data-api="<?= e(site_url('backoffice_push')) ?>">
    <h1 class="h3">Notificaciones de la agenda</h1>
    <p>Recibe un aviso cuando una clienta confirme su reserva online, cambie de horario o cancele su cita. Al tocarlo, se abrirá el detalle en tu agenda.</p>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">En este dispositivo</h2>
            <p id="push-status" role="status" aria-live="polite">Comprobando notificaciones…</p>
            <button id="push-enable" class="btn btn-primary" type="button" disabled>Activar notificaciones</button>
            <button id="push-disable" class="btn btn-outline-secondary" type="button" hidden>Desactivar en este dispositivo</button>
        </div>
    </div>
    <div id="push-install-guide" class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Instalar en tu iPhone</h2>
            <ol class="mb-0">
                <li>Abre la agenda en Safari.</li>
                <li>Toca Compartir y luego <strong>Agregar a inicio</strong>.</li>
                <li>Abre Blondor desde el nuevo icono e ingresa a tu cuenta.</li>
                <li>Entra a <strong>Notificaciones</strong>, pulsa Activar y permite los avisos.</li>
            </ol>
            <p class="small text-muted mt-3 mb-0">Necesitas iOS 16.4 o posterior. Los modos de concentración y los ajustes de notificaciones del iPhone pueden silenciar los avisos.</p>
        </div>
    </div>
    <p class="small text-muted">Los avisos no muestran datos de clientas en la pantalla bloqueada. El correo sigue funcionando como respaldo. No hay cobro por mensaje.</p>
    <a class="btn btn-link ps-0" href="<?= e(site_url('agenda')) ?>">Volver a la agenda</a>
</section>
<?php end_section('content'); ?>
<?php section('scripts'); ?>
<script src="<?= asset_url('assets/js/pages/backoffice_notifications.js') ?>"></script>
<?php end_section('scripts'); ?>
