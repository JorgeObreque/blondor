<?php extend('layouts/backend_layout'); ?>

<?php section('styles'); ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/agenda.css') ?>">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/service_prices.css') ?>">

<?php end_section('styles'); ?>

<?php section('content'); ?>

<div id="prices-page" class="agenda prices">

    <header class="agenda-head">
        <div class="agenda-head__row">
            <h1 class="agenda-head__title">Servicios y precios</h1>
            <div class="agenda-head__actions">
                <a href="<?= site_url('agenda') ?>" class="agenda-iconbtn" aria-label="Volver a la agenda">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
                </a>
            </div>
        </div>
        <label class="prices-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="prices-search" placeholder="Buscar servicio" autocomplete="off" aria-label="Buscar servicio">
        </label>
    </header>

    <nav id="prices-filters" class="prices-filters" role="tablist" aria-label="Filtro"></nav>

    <div id="prices-cleanup-banner"></div>

    <main id="prices-list" class="prices-list" aria-live="polite"></main>

    <button type="button" id="prices-adjust" class="agenda-fab" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"/></svg>
        Ajustar precios
    </button>

    <div id="prices-selectbar" class="prices-selectbar" hidden></div>

    <!-- Editar un servicio -->
    <section id="prices-edit" class="agenda-sheet" role="dialog" aria-modal="true" aria-labelledby="edit-title"
             hidden></section>

    <!-- Alza masiva -->
    <section id="prices-bulk" class="agenda-sheet agenda-sheet--full" role="dialog" aria-modal="true"
             aria-labelledby="bulk-title" hidden>
        <header class="wizard-head">
            <div class="wizard-head__row">
                <button type="button" class="agenda-iconbtn agenda-iconbtn--plain" data-close-sheet aria-label="Volver">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                </button>
                <span id="bulk-title" class="wizard-head__title">Ajustar precios</span>
                <span style="width: 44px"></span>
            </div>
        </header>
        <div id="bulk-body" class="wizard-body"></div>
        <footer id="bulk-footer" class="wizard-footer"></footer>
    </section>

    <!-- Limpieza del catálogo -->
    <section id="prices-cleanup" class="agenda-sheet agenda-sheet--full" role="dialog" aria-modal="true"
             aria-labelledby="cleanup-title" hidden>
        <header class="wizard-head">
            <div class="wizard-head__row">
                <button type="button" class="agenda-iconbtn agenda-iconbtn--plain" data-close-sheet aria-label="Volver">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                </button>
                <span id="cleanup-title" class="wizard-head__title">Ordenar el catálogo</span>
                <span style="width: 44px"></span>
            </div>
        </header>
        <div id="cleanup-body" class="wizard-body"></div>
    </section>

    <div id="prices-backdrop" class="agenda-backdrop" hidden></div>

    <div id="prices-toast" class="agenda-toast" role="status" hidden></div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/js/http/service_prices_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/service_prices.js') ?>"></script>

<?php end_section('scripts'); ?>
