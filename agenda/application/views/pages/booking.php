<?php
/**
 * Blondor - Reserva pública, móvil primero.
 *
 * Tres pasos, como Fresha y Booksy: servicio → día y hora → tus datos y confirmar. La profesional solo se pregunta
 * cuando más de una hace el servicio; con una sola se asigna sola. Los servicios que se cobran según el largo del pelo
 * preguntan el largo en una hoja con la infografía de cada uno.
 */

extend('layouts/booking_mobile_layout');

$field = function (string $id, string $name, string $label, string $type, string $autocomplete, int $maxlength, ?string $hint = null) {
    $display = vars('display_' . $name);
    $required = vars('require_' . $name);

    if (!$display) {
        return;
    } ?>
    <label class="bk-field" for="<?= $id ?>">
        <span class="bk-field__label">
            <?= $label ?>
            <?php if (!$required): ?><span class="bk-field__optional">opcional</span><?php endif; ?>
        </span>
        <?php if ($type === 'textarea'): ?>
            <textarea id="<?= $id ?>" rows="2" maxlength="<?= $maxlength ?>" <?= $required ? 'required' : '' ?>
                      placeholder="<?= e($hint ?? '') ?>"></textarea>
        <?php else: ?>
            <input id="<?= $id ?>" type="<?= $type ?>" autocomplete="<?= $autocomplete ?>" maxlength="<?= $maxlength ?>"
                   <?= $type === 'tel' ? 'inputmode="tel"' : '' ?> <?= $required ? 'required' : '' ?>
                   placeholder="<?= e($hint ?? '') ?>">
        <?php endif; ?>
        <span class="bk-field__error" hidden></span>
    </label>
    <?php
};

$appointment = vars('appointment_data');
$manage_mode = vars('manage_mode');
?>

<?php section('content'); ?>

<div id="booking-page" class="bk" data-step="service"
     data-whatsapp="56966756614" data-address="Av. Apoquindo 4900, local 53, Las Condes">

    <header class="bk-top">
        <div class="bk-top__row">
            <button type="button" class="bk-iconbtn" id="bk-back" aria-label="Volver" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <a class="bk-brand" href="https://blondor.cl" aria-label="<?= e(vars('company_name')) ?>">
                <img src="<?= asset_url('assets/img/blondor-mark.png') ?>" alt="">
                <span>BLONDOR</span>
            </a>
            <span class="bk-top__count" id="bk-count">Paso 1 de 3</span>
        </div>
        <h1 class="bk-top__title" id="bk-title">Elige tu servicio</h1>
        <div class="bk-progress" aria-hidden="true"><span></span><span></span><span></span></div>
    </header>

    <?php if ($manage_mode): ?>
        <div class="bk-manage">
            <div>
                <strong>Estás cambiando tu cita</strong>
                <span>Elige otro día u hora y guarda los cambios.</span>
            </div>
            <button type="button" class="bk-link-danger" id="bk-cancel-open">Cancelar cita</button>
        </div>
    <?php endif; ?>

    <main class="bk-main">

        <!-- Paso 1: servicio -->
        <section class="bk-step" data-step="service" aria-labelledby="bk-title">
            <p class="bk-where">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                Av. Apoquindo 4900, local 53, Las Condes
            </p>

            <label class="bk-search" for="bk-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
                <input id="bk-search" type="search" placeholder="Buscar: corte, color, botox…" autocomplete="off">
            </label>

            <div id="bk-services"><!-- JS --></div>
        </section>

        <!-- Paso 2: día y hora -->
        <section class="bk-step" data-step="time" hidden>
            <button type="button" class="bk-picked" id="bk-picked-service">
                <span class="bk-picked__text"><!-- JS --></span>
                <span class="bk-picked__change">Cambiar</span>
            </button>

            <div class="bk-providers" id="bk-lengths" hidden>
                <p class="bk-label">Tu largo</p>
                <div class="bk-chips bk-chips--scroll" id="bk-length-chips" role="radiogroup" aria-label="Largo del pelo"></div>
            </div>

            <div class="bk-providers" id="bk-providers" hidden>
                <p class="bk-label">¿Con quién?</p>
                <div class="bk-chips" id="bk-provider-chips" role="radiogroup" aria-label="Profesional"></div>
            </div>

            <div class="bk-month">
                <p class="bk-label" id="bk-month-label"></p>
            </div>
            <div class="bk-days" id="bk-days" role="radiogroup" aria-label="Día"></div>

            <div id="bk-slots" class="bk-slots" aria-live="polite"><!-- JS --></div>
        </section>

        <!-- Paso 3: tus datos y confirmar -->
        <section class="bk-step" data-step="details" hidden>
            <div class="bk-summary" id="bk-summary"><!-- JS --></div>

            <form id="bk-form" class="bk-form" novalidate>
                <p class="bk-label">Tus datos</p>
                <div class="bk-form__row">
                    <?php $field('first-name', 'first_name', 'Nombre', 'text', 'given-name', 100); ?>
                    <?php $field('last-name', 'last_name', 'Apellido', 'text', 'family-name', 120); ?>
                </div>
                <?php $field('phone-number', 'phone_number', 'Teléfono (WhatsApp)', 'tel', 'tel', 60, '+56 9 1234 5678'); ?>
                <?php $field('email', 'email', 'Correo', 'email', 'email', 120, 'Para enviarte la confirmación'); ?>
                <?php $field('address', 'address', 'Dirección', 'text', 'street-address', 120); ?>
                <?php $field('city', 'city', 'Comuna', 'text', 'address-level2', 120); ?>
                <?php $field('zip-code', 'zip_code', 'Código postal', 'text', 'postal-code', 120); ?>
                <?php $field('notes', 'notes', 'Comentarios', 'textarea', 'off', 500, 'Ej.: largo del pelo, color que buscas…'); ?>

                <?php component('custom_fields'); ?>

                <label class="bk-check" for="bk-remember">
                    <input type="checkbox" id="bk-remember" checked>
                    <span>Recordar mis datos en este teléfono</span>
                </label>

                <?php if (vars('display_terms_and_conditions')): ?>
                    <label class="bk-check" for="accept-to-terms-and-conditions">
                        <input type="checkbox" id="accept-to-terms-and-conditions">
                        <span><?= strtr(lang('read_and_agree_to_terms_and_conditions'), [
                            '{$link}' => '<a href="#" data-bs-toggle="modal" data-bs-target="#terms-and-conditions-modal">',
                            '{/$link}' => '</a>',
                        ]) ?></span>
                    </label>
                <?php endif; ?>

                <?php if (vars('display_privacy_policy')): ?>
                    <label class="bk-check" for="accept-to-privacy-policy">
                        <input type="checkbox" id="accept-to-privacy-policy">
                        <span><?= strtr(lang('read_and_agree_to_privacy_policy'), [
                            '{$link}' => '<a href="#" data-bs-toggle="modal" data-bs-target="#privacy-policy-modal">',
                            '{/$link}' => '</a>',
                        ]) ?></span>
                    </label>
                <?php endif; ?>

                <?php if (setting('require_captcha')): ?>
                    <div class="bk-captcha">
                        <img class="bk-captcha__img" id="bk-captcha-img" src="<?= site_url('captcha') ?>" alt="Código de verificación">
                        <button type="button" class="bk-iconbtn bk-iconbtn--plain" id="bk-captcha-refresh" aria-label="Otro código">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6"/></svg>
                        </button>
                        <label class="bk-field" for="captcha-text">
                            <span class="bk-field__label">Escribe el código</span>
                            <input id="captcha-text" type="text" autocomplete="off" autocapitalize="characters">
                            <span class="bk-field__error" hidden></span>
                        </label>
                    </div>
                <?php endif; ?>

                <?php if ($manage_mode && vars('display_delete_personal_information')): ?>
                    <button type="button" class="bk-link-danger" id="bk-delete-data">Borrar mis datos personales</button>
                <?php endif; ?>
            </form>
        </section>
    </main>

    <!-- Barra inferior fija: resumen + acción del paso -->
    <footer class="bk-bar" id="bk-bar" hidden>
        <div class="bk-bar__text" id="bk-bar-text"></div>
        <button type="button" class="bk-primary" id="bk-next">Continuar</button>
    </footer>

    <div class="bk-backdrop" id="bk-backdrop" hidden></div>

    <div class="bk-sheet bk-sheet--lengths" id="bk-length-sheet" role="dialog" aria-modal="true" aria-labelledby="bk-length-title" hidden>
        <div class="bk-sheet__head">
            <div>
                <p class="bk-sheet__eyebrow" id="bk-length-service"></p>
                <h2 id="bk-length-title">¿Qué largo tienes?</h2>
            </div>
            <button type="button" class="bk-iconbtn bk-iconbtn--plain" id="bk-length-close" aria-label="Cerrar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <p>El precio depende del largo. Mídelo con el pelo suelto, por la espalda.</p>
        <div class="bk-lengths" id="bk-length-options" role="radiogroup" aria-labelledby="bk-length-title"></div>
    </div>

    <?php if ($manage_mode): ?>
        <form id="cancel-appointment-form" method="post"
              action="<?= site_url('booking_cancellation/of/' . $appointment['hash']) ?>">
            <input id="hidden-cancellation-reason" name="cancellation_reason" type="hidden">
        </form>

        <div class="bk-sheet" id="bk-cancel-sheet" role="dialog" aria-modal="true" aria-labelledby="bk-cancel-title" hidden>
            <h2 id="bk-cancel-title">¿Cancelar tu cita?</h2>
            <p>Cuéntanos el motivo. Si quieres, también puedes cambiarla a otro día.</p>
            <label class="bk-field" for="bk-cancel-reason">
                <span class="bk-field__label">Motivo</span>
                <textarea id="bk-cancel-reason" rows="3" maxlength="500"></textarea>
                <span class="bk-field__error" hidden>Escribe el motivo para cancelar.</span>
            </label>
            <div class="bk-sheet__actions">
                <button type="button" class="bk-secondary" id="bk-cancel-close">Volver</button>
                <button type="button" class="bk-primary bk-primary--danger" id="bk-cancel-confirm">Cancelar cita</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="bk-toast" id="bk-toast" role="status" hidden></div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/js/utils/hair_length.js') ?>"></script>
<script src="<?= asset_url('assets/js/http/booking_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/booking.js') ?>"></script>

<?php end_section('scripts'); ?>
