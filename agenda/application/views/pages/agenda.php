<?php extend('layouts/backend_layout'); ?>

<?php section('styles'); ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/agenda.css') ?>">

<?php end_section('styles'); ?>

<?php section('content'); ?>

<div id="agenda-page" class="agenda">

    <header class="agenda-head">
        <div class="agenda-head__row">
            <h1 id="agenda-title" class="agenda-head__title">Hoy</h1>
            <div class="agenda-head__actions">
                <button type="button" id="agenda-today" class="agenda-iconbtn" aria-label="Ir a hoy">Hoy</button>
                <label id="agenda-pick-date" class="agenda-iconbtn agenda-datepick" title="Elegir fecha">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
                    <input type="date" id="agenda-date-input" class="agenda-date-input" aria-label="Elegir fecha">
                </label>
            </div>
        </div>
        <nav id="agenda-days" class="agenda-days" aria-label="Días"></nav>
        <div id="agenda-providers" class="agenda-providers" role="tablist" aria-label="Profesional" hidden></div>
    </header>

    <div id="agenda-summary" class="agenda-summary" aria-live="polite"></div>

    <main id="agenda-timeline" class="agenda-timeline" aria-live="polite"></main>

    <button type="button" id="agenda-add" class="agenda-fab">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Agendar
    </button>

    <!-- Flujo de 3 pasos -->
    <section id="agenda-wizard" class="agenda-sheet agenda-sheet--full" role="dialog" aria-modal="true"
             aria-labelledby="wizard-title" hidden>
        <header class="wizard-head">
            <div class="wizard-head__row">
                <button type="button" id="wizard-back" class="agenda-iconbtn agenda-iconbtn--plain" aria-label="Volver">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                </button>
                <span id="wizard-title" class="wizard-head__title">Nueva cita</span>
                <button type="button" id="wizard-close" class="agenda-iconbtn agenda-iconbtn--plain" aria-label="Cerrar">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <ol id="wizard-steps" class="wizard-steps" aria-label="Pasos"></ol>
        </header>
        <div id="wizard-body" class="wizard-body"></div>
        <footer id="wizard-footer" class="wizard-footer" hidden></footer>
    </section>

    <!-- Ficha de una cita -->
    <section id="agenda-detail" class="agenda-sheet" role="dialog" aria-modal="true" aria-labelledby="detail-name"
             hidden></section>

    <!-- Avisos (duplicado, choque, clienta existente) -->
    <section id="agenda-alert" class="agenda-sheet" role="alertdialog" aria-modal="true" aria-labelledby="alert-title"
             hidden></section>

    <div id="agenda-backdrop" class="agenda-backdrop" hidden></div>

    <div id="agenda-toast" class="agenda-toast" role="status" hidden></div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/js/http/agenda_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/agenda.js') ?>"></script>

<?php end_section('scripts'); ?>
