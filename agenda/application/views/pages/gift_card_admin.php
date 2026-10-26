<?php extend('layouts/backend_layout'); ?>

<?php section('styles'); ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/agenda.css') ?>">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/gift_card_admin.css') ?>">

<?php end_section('styles'); ?>

<?php section('content'); ?>

<div id="gca-page" class="agenda gca">

    <header class="agenda-head">
        <div class="agenda-head__row">
            <h1 class="agenda-head__title">Gift cards</h1>
            <div class="agenda-head__actions">
                <a href="<?= site_url('agenda') ?>" class="agenda-iconbtn" aria-label="Volver a la agenda">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
                </a>
            </div>
        </div>
        <label class="gca-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="gca-search" placeholder="Código, nombre o correo" autocomplete="off"
                   autocapitalize="characters" aria-label="Buscar gift card">
        </label>
    </header>

    <nav class="gca-filters" role="tablist" aria-label="Estado">
        <button type="button" role="tab" class="wizard-tab" data-status="active" aria-selected="true">Con saldo</button>
        <button type="button" role="tab" class="wizard-tab" data-status="used" aria-selected="false">Usadas</button>
        <button type="button" role="tab" class="wizard-tab" data-status="" aria-selected="false">Todas</button>
    </nav>

    <main id="gca-list" class="gca-list" aria-live="polite"></main>

    <button type="button" id="gca-new" class="agenda-fab" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Nueva gift card
    </button>

    <!-- Detalle y canje -->
    <section id="gca-detail" class="agenda-sheet" role="dialog" aria-modal="true" aria-labelledby="gca-detail-code"
             hidden></section>

    <!-- Registrar una vendida en el salón -->
    <section id="gca-create" class="agenda-sheet" role="dialog" aria-modal="true" aria-labelledby="gca-create-title"
             hidden>
        <span class="agenda-sheet__grab" aria-hidden="true"></span>
        <h2 id="gca-create-title">Nueva gift card</h2>
        <p class="gca-hint">Para las que se venden en el salón: cóbrala en la máquina TUU y regístrala aquí para tener su código.</p>
        <form id="gca-create-form" class="wizard-form" novalidate>
            <div class="gca-amounts" id="gca-create-amounts"></div>
            <label class="wizard-field">Monto
                <input type="text" inputmode="numeric" id="gca-create-amount" autocomplete="off" placeholder="$30.000">
            </label>
            <label class="wizard-field">¿Quién la compra? <span class="gca-optional">opcional</span>
                <input type="text" id="gca-create-buyer" maxlength="120" autocomplete="off">
            </label>
            <label class="wizard-field">¿Para quién es? <span class="gca-optional">opcional</span>
                <input type="text" id="gca-create-recipient" maxlength="120" autocomplete="off">
            </label>
            <label class="wizard-field">Nota <span class="gca-optional">opcional</span>
                <input type="text" id="gca-create-notes" maxlength="200" autocomplete="off" placeholder="Ej.: boleta TUU n.º 1234">
            </label>
            <div class="gca-actions">
                <button type="button" class="agenda-secondary" data-close-sheet>Cancelar</button>
                <button type="submit" class="agenda-primary" id="gca-create-submit">Crear gift card</button>
            </div>
        </form>
    </section>

    <div id="gca-backdrop" class="agenda-backdrop" hidden></div>

    <div id="gca-toast" class="agenda-toast" role="status" hidden></div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/js/http/gift_card_admin_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/gift_card_admin.js') ?>"></script>

<?php end_section('scripts'); ?>
