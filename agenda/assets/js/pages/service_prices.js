/* ----------------------------------------------------------------------------
 * Blondor - Servicios y precios
 * ---------------------------------------------------------------------------- */

/**
 * Service prices page.
 *
 * The catalog ordered by use and grouped by family (corte → mujer, hombre, niñas…), editing a price in one tap,
 * the "sin precio" queue, the bulk price change with rounding and preview, and the cleanup suggestions (hide unused
 * services, fix suspicious prices). Every save shows "Deshacer".
 */
App.Pages.ServicePrices = (function () {
    const $page = $('#prices-page');
    const $list = $('#prices-list');
    const $filters = $('#prices-filters');
    const $banner = $('#prices-cleanup-banner');
    const $search = $('#prices-search');
    const $adjust = $('#prices-adjust');
    const $selectbar = $('#prices-selectbar');
    const $edit = $('#prices-edit');
    const $bulk = $('#prices-bulk');
    const $bulkBody = $('#bulk-body');
    const $bulkFooter = $('#bulk-footer');
    const $cleanup = $('#prices-cleanup');
    const $cleanupBody = $('#cleanup-body');
    const $backdrop = $('#prices-backdrop');
    const $toast = $('#prices-toast');

    const services = vars('catalog_services') || [];
    const categories = vars('catalog_categories') || [];
    const canEdit = !!vars('catalog_can_edit');
    const recentMonths = vars('catalog_recent_months') || 6;

    /**
     * Families group services that are variants of one another. The first match wins; "strip" is removed from the
     * name to show the variant ("corte mujer" → "Mujer").
     */
    const FAMILIES = [
        {key: 'promo', label: 'Promociones', test: /^promo/},
        {key: 'corte', label: 'Corte', test: /^corte\b/, strip: /^corte\s+(de\s+pelo\s+)?/},
        {key: 'color', label: 'Color y mechas', test: /color|balay|babyl|mechas|visos|contouring|canas|decolor/},
        {key: 'alisado', label: 'Alisado', test: /alisado/, strip: /^alisado\s+(permanente\s+)?/},
        {key: 'tratamiento', label: 'Tratamientos capilares', test: /botox|olaplex|k18|ampolla|total mask|hidratante/},
        {key: 'permanente', label: 'Permanente', test: /^permanente/, strip: /^permanente\s+/},
        {key: 'peinado', label: 'Peinados', test: /brushing|peinado|ondas/},
        {key: 'manos', label: 'Manos y pies', test: /manos|pies|manicure|pedicur/},
        {key: 'masaje', label: 'Masajes', test: /masaje/, strip: /^masaje\s+(de\s+)?/},
        {key: 'depilacion', label: 'Depilación', test: /depilaci|rebaje|cejas/, strip: /^depilaci[oó]n\s+/},
        {key: 'facial', label: 'Facial', test: /facial/, strip: /^limpieza\s+facial\s+/},
        {key: 'otros', label: 'Otros', test: /./},
    ];

    const DURATIONS = [15, 30, 45, 60, 90, 120, 180];

    const ROUNDINGS = [
        {key: '990', label: 'Terminar en 990', apply: (value) => Math.max(990, Math.round((value + 10) / 1000) * 1000 - 10)},
        {key: '1000', label: 'A los $1.000', apply: (value) => Math.max(1000, Math.round(value / 1000) * 1000)},
        {key: 'none', label: 'Sin redondeo', apply: (value) => Math.max(0, Math.round(value))},
    ];

    const TOP_COUNT = 6;

    const HairLength = App.Utils.HairLength;

    /**
     * Suggested increase of each length over the short one (%), when a service starts being priced by length.
     */
    const LENGTH_INCREASE = {short: 0, medium: 15, long: 30, xlong: 45};

    const ICONS = {
        check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>',
        warn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
        broom: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4l6 6M12 6l6 6-7 7H5v-6z"/><path d="M8 13l3 3"/></svg>',
    };

    const state = {
        filter: 'all',
        keyword: '',
        selecting: false,
        selected: new Set(),
        editId: null,
        queue: null,
        bulk: {mode: 'percent', value: '10', rounding: '990'},
        saving: false,
    };

    let toastTimer = null;
    let skipPopstate = 0;

    // Helpers ---------------------------------------------------------------------------------------------------

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .trim();
    }

    function capitalize(text) {
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
    }

    function money(value) {
        return '$' + Math.round(value).toLocaleString('es-CL');
    }

    function priceText(item) {
        if (item.variants && item.variants.length) {
            const prices = item.variants.map((variant) => variant.price);

            return money(Math.min(...prices)) + '–' + money(Math.max(...prices));
        }

        if (!(item.price > 0)) {
            return null;
        }

        return (item.price_type === 'from' ? 'desde ' : '') + money(item.price);
    }

    function parsePrice(text) {
        const digits = String(text || '').replace(/\D/g, '');

        return digits ? Number(digits) : 0;
    }

    function duration(total) {
        if (total < 60) {
            return total + ' min';
        }

        const h = Math.floor(total / 60);
        const m = total % 60;

        return h + ' h' + (m ? ' ' + m : '');
    }

    function service(id) {
        return services.find((item) => item.id === Number(id));
    }

    function category(id) {
        return categories.find((item) => item.id === Number(id));
    }

    function familyOf(item) {
        const name = normalize(item.name);

        return FAMILIES.find((family) => family.test.test(name));
    }

    /**
     * Name inside its family: "corte mujer" → "Mujer"; names that do not start with the family word stay whole.
     */
    function variantName(item) {
        const family = familyOf(item);
        const name = item.name.trim().replace(/\s+/g, ' ');

        if (family.strip) {
            const stripped = name.replace(new RegExp(family.strip.source, 'i'), '');

            if (stripped && stripped.length < name.length) {
                return capitalize(stripped);
            }
        }

        return capitalize(name);
    }

    function usageText(item) {
        if (item.recent_uses) {
            return item.recent_uses + (item.recent_uses === 1 ? ' cita' : ' citas') + ' en ' + recentMonths + ' meses';
        }

        if (item.uses) {
            return 'Sin citas en ' + recentMonths + ' meses';
        }

        return 'Nunca agendado';
    }

    function visible() {
        return services.filter((item) => !item.is_archived);
    }

    function matchesKeyword(item) {
        const keyword = normalize(state.keyword);

        if (!keyword) {
            return true;
        }

        const family = familyOf(item);

        return keyword
            .split(/\s+/)
            .every((word) => normalize(item.name).includes(word) || normalize(family.label).includes(word));
    }

    function byUse(a, b) {
        return b.recent_uses - a.recent_uses || b.uses - a.uses || a.name.localeCompare(b.name);
    }

    // Sheets & history ------------------------------------------------------------------------------------------

    function sheets() {
        return [$edit, $bulk, $cleanup];
    }

    function topSheet() {
        return sheets().find(($sheet) => !$sheet.prop('hidden')) || null;
    }

    function openSheet($sheet) {
        $sheet.prop('hidden', false);

        if (!$sheet.hasClass('agenda-sheet--full')) {
            $backdrop.prop('hidden', false);
        }

        $('body').addClass('agenda-locked');

        if (!history.state || history.state.pricesSheet !== $sheet.attr('id')) {
            history.pushState({pricesSheet: $sheet.attr('id')}, '');
        }
    }

    function closeSheet($sheet, fromHistory = false) {
        if ($sheet.prop('hidden')) {
            return;
        }

        $sheet.prop('hidden', true);

        if ($edit.prop('hidden')) {
            $backdrop.prop('hidden', true);
        }

        if (!topSheet()) {
            $('body').removeClass('agenda-locked');
        }

        if ($sheet.is($edit)) {
            state.editId = null;
            state.queue = null;
        }

        if (!fromHistory && history.state && history.state.pricesSheet === $sheet.attr('id')) {
            skipPopstate++;
            history.back();
        }
    }

    // Toast -----------------------------------------------------------------------------------------------------

    function toast(text, options = {}) {
        clearTimeout(toastTimer);

        $toast
            .toggleClass('agenda-toast--error', !!options.error)
            .html(
                `<span class="agenda-toast__icon">${options.error ? ICONS.warn : ICONS.check}</span>
                 <span class="agenda-toast__text">${esc(text)}</span>
                 ${options.action ? `<button type="button">${esc(options.action.label)}</button>` : ''}`,
            )
            .prop('hidden', false);

        if (options.action) {
            $toast.find('button').one('click', () => {
                $toast.prop('hidden', true);
                options.action.run();
            });
        }

        toastTimer = setTimeout(() => $toast.prop('hidden', true), options.action ? 8000 : 3500);
    }

    // Saving ----------------------------------------------------------------------------------------------------

    /**
     * Send changes, update the local catalog and offer "Deshacer" (it sends back the previous values).
     */
    function save(changes, message, options = {}) {
        if (state.saving || !changes.length) {
            return Promise.resolve(false);
        }

        state.saving = true;

        return App.Http.ServicePrices.save(changes)
            .then((response) => {
                const undo = [];

                response.saved.forEach(({service: saved, previous}, index) => {
                    Object.assign(service(saved.id), saved);

                    const back = {id: saved.id};

                    Object.keys(changes[index]).forEach((key) => {
                        if (key !== 'id') {
                            back[key] = previous[key];
                        }
                    });

                    undo.push(back);
                });

                render();

                toast(message, {
                    action: options.noUndo
                        ? null
                        : {label: 'Deshacer', run: () => save(undo, 'Cambio deshecho', {noUndo: true})},
                });

                return true;
            })
            .catch((error) => {
                toast(error.message, {error: true});

                return false;
            })
            .finally(() => {
                state.saving = false;
            });
    }

    // List ------------------------------------------------------------------------------------------------------

    function cleanupItems() {
        const items = [];
        const seen = new Set();
        const add = (kind, item) => {
            if (!seen.has(item.id)) {
                seen.add(item.id);
                items.push({kind, item});
            }
        };

        visible().forEach((item) => {
            if (item.price > 0 && item.price < 1000) {
                add('price', item);
            }
        });

        visible().forEach((item) => {
            if (!item.providers || /^(service|servicio|test|prueba)$/i.test(item.name.trim())) {
                add('nobody', item);
            }
        });

        visible().forEach((item) => {
            if (!item.uses && !item.upcoming) {
                add('unused', item);
            }
        });

        visible().forEach((item) => {
            if (!item.category_id) {
                add('category', item);
            }
        });

        return items;
    }

    function renderFilters() {
        const missing = visible().filter((item) => !(item.price > 0)).length;
        const archived = services.length - visible().length;
        const filters = [
            {key: 'all', label: 'Todos', count: visible().length},
            {key: 'missing', label: 'Sin precio', count: missing, warn: missing > 0},
            {key: 'archived', label: 'Ocultos', count: archived},
        ];

        $filters.html(
            filters
                .map(
                    (filter) => `
                <button type="button" role="tab" class="wizard-tab prices-filter${filter.warn ? ' prices-filter--warn' : ''}"
                        data-filter="${filter.key}" aria-selected="${state.filter === filter.key}">
                    ${esc(filter.label)} <span class="prices-filter__count">${filter.count}</span>
                </button>`,
                )
                .join(''),
        );

        const pending = canEdit ? cleanupItems().length : 0;

        $banner.html(
            pending && state.filter === 'all' && !state.selecting
                ? `<button type="button" id="prices-open-cleanup" class="prices-banner">
                       <span class="prices-banner__icon">${ICONS.broom}</span>
                       <span class="prices-banner__text">
                           <strong>Ordenar el catálogo</strong>
                           <span>${pending} ${pending === 1 ? 'sugerencia' : 'sugerencias'}: servicios sin uso, sin profesional o con precio raro</span>
                       </span>
                       ${ICONS.chevron}
                   </button>`
                : '',
        );
    }

    function row(item) {
        const price = priceText(item);
        const selectable = state.selecting && item.price > 0;
        const selected = state.selected.has(item.id);
        const warn = !item.providers ? 'Ninguna profesional lo ofrece' : null;

        return `
            <li>
                <button type="button" class="prices-row${selected ? ' is-selected' : ''}" data-service-id="${item.id}"
                        ${state.selecting ? `role="checkbox" aria-checked="${selected}"` : ''}
                        ${state.selecting && !selectable ? 'disabled' : ''}>
                    ${state.selecting ? `<span class="prices-check" aria-hidden="true">${ICONS.check}</span>` : ''}
                    <span class="prices-row__text">
                        <span class="prices-row__title">${esc(state.filter === 'all' && !state.keyword ? variantName(item) : capitalize(item.name.trim()))}</span>
                        <span class="prices-row__sub">
                            ${esc(usageText(item))} · ${duration(item.duration)}${item.variants.length ? ' · ' + item.variants.length + ' largos' : ''}
                            ${warn ? `<span class="prices-row__warn">· ${esc(warn)}</span>` : ''}
                        </span>
                    </span>
                    ${
                        price
                            ? `<span class="prices-row__price">${esc(price)}</span>`
                            : '<span class="wizard-option__price--none">Sin precio</span>'
                    }
                </button>
            </li>`;
    }

    function renderTop() {
        const top = visible()
            .filter((item) => item.recent_uses > 0)
            .sort(byUse)
            .slice(0, TOP_COUNT);

        if (!top.length) {
            return '';
        }

        return `
            <section class="prices-group">
                <h2 class="prices-group__title">Más pedidos</h2>
                <div class="prices-top">
                    ${top
                        .map(
                            (item) => `
                        <button type="button" class="prices-top__card" data-service-id="${item.id}">
                            <span class="prices-top__name">${esc(capitalize(item.name.trim()))}</span>
                            ${
                                priceText(item)
                                    ? `<span class="prices-top__price">${esc(priceText(item))}</span>`
                                    : '<span class="prices-top__price prices-top__price--none">Poner precio</span>'
                            }
                            <span class="prices-top__sub">${item.recent_uses} citas · ${duration(item.duration)}</span>
                        </button>`,
                        )
                        .join('')}
                </div>
            </section>`;
    }

    function renderGroups(items) {
        const groups = FAMILIES.map((family) => ({
            family,
            items: items.filter((item) => familyOf(item) === family).sort(byUse),
        })).filter((group) => group.items.length);

        groups.sort(
            (a, b) =>
                b.items.reduce((sum, item) => sum + item.uses, 0) - a.items.reduce((sum, item) => sum + item.uses, 0),
        );

        return groups
            .map((group) => {
                const selectable = group.items.filter((item) => item.price > 0);
                const allSelected = selectable.length && selectable.every((item) => state.selected.has(item.id));

                return `
                <section class="prices-group">
                    <div class="prices-group__head">
                        <h2 class="prices-group__title">${esc(group.family.label)}
                            <span class="prices-group__count">${group.items.length}</span></h2>
                        ${
                            state.selecting && selectable.length
                                ? `<button type="button" class="prices-group__all" data-select-family="${group.family.key}">
                                       ${allSelected ? 'Quitar' : 'Elegir todos'}</button>`
                                : ''
                        }
                    </div>
                    <ul class="prices-rows">${group.items.map(row).join('')}</ul>
                </section>`;
            })
            .join('');
    }

    function renderList() {
        let html = '';

        if (state.filter === 'missing') {
            const items = visible()
                .filter((item) => !(item.price > 0) && matchesKeyword(item))
                .sort(byUse);

            html = items.length
                ? `<p class="wizard-hint prices-hint">Empieza por los más agendados: así el gasto de cada clienta y la reserva
                       online muestran valores reales.</p>
                   ${
                       canEdit
                           ? `<button type="button" id="prices-fill-queue" class="agenda-primary prices-queue-btn">
                                  Completar precios uno a uno (${items.length})</button>`
                           : ''
                   }
                   <ul class="prices-rows prices-rows--card">${items.map(row).join('')}</ul>`
                : '<div class="agenda-empty">Todos los servicios tienen precio.</div>';
        } else if (state.filter === 'archived') {
            const items = services.filter((item) => item.is_archived && matchesKeyword(item)).sort(byUse);

            html = items.length
                ? `<p class="wizard-hint prices-hint">No aparecen al agendar ni en la reserva online. Sus citas pasadas se
                       conservan. Tócalos para volver a mostrarlos.</p>
                   <ul class="prices-rows prices-rows--card">${items.map(row).join('')}</ul>`
                : '<div class="agenda-empty">No hay servicios ocultos.</div>';
        } else {
            const items = visible().filter(matchesKeyword);

            html =
                (!state.keyword && !state.selecting ? renderTop() : '') +
                (items.length ? renderGroups(items) : '<div class="agenda-empty">Ningún servicio coincide.</div>');
        }

        $list.html(html);
    }

    function renderSelectbar() {
        $adjust.prop('hidden', !canEdit || state.selecting || state.filter !== 'all');
        $selectbar.prop('hidden', !state.selecting);

        if (!state.selecting) {
            return;
        }

        const count = state.selected.size;

        $selectbar.html(`
            <button type="button" id="prices-select-cancel" class="agenda-secondary">Cancelar</button>
            <button type="button" id="prices-select-next" class="agenda-primary" ${count ? '' : 'disabled'}>
                ${count ? `Ajustar ${count} ${count === 1 ? 'precio' : 'precios'}` : 'Elige servicios'}
            </button>
        `);
    }

    function render() {
        renderFilters();
        renderList();
        renderSelectbar();

        if (!$cleanup.prop('hidden')) {
            renderCleanup();
        }
    }

    // Edit one service ------------------------------------------------------------------------------------------

    function openEdit(id, queue = null) {
        const item = service(id);

        if (!item || !canEdit) {
            return;
        }

        state.editId = item.id;
        state.queue = queue;

        renderEdit();
        openSheet($edit);

        // The price is what is edited most: focus it so the numeric keyboard opens right away.
        setTimeout(() => $edit.find('#edit-price, [data-length-price]').first().trigger('focus'), 50);
    }

    /**
     * Prices to start with when a service starts being priced by length: the short one is the current price and
     * the others go up by LENGTH_INCREASE, rounded to $1.000.
     */
    function suggestedVariants(base) {
        if (!(base > 0)) {
            return [];
        }

        return HairLength.LENGTHS.map((length) => ({
            hair_length: length.key,
            price: length.key === 'short' ? base : Math.round((base * (1 + LENGTH_INCREASE[length.key] / 100)) / 1000) * 1000,
            duration: null,
        }));
    }

    /**
     * One price (fixed or "desde"), or one price and optionally a duration per hair length.
     */
    function priceBody(item, lengths, base) {
        if (!lengths) {
            const price = base ?? item.price;

            return `
                <div class="prices-price__input">
                    <span aria-hidden="true">$</span>
                    <input type="text" id="edit-price" inputmode="numeric" autocomplete="off" aria-label="Precio"
                           value="${price > 0 ? Math.round(price).toLocaleString('es-CL') : ''}"
                           placeholder="0">
                </div>
                <div class="prices-segment" role="radiogroup" aria-label="Tipo de precio">
                    <button type="button" role="radio" data-price-type="fixed" aria-checked="${item.price_type !== 'from'}">Precio fijo</button>
                    <button type="button" role="radio" data-price-type="from" aria-checked="${item.price_type === 'from'}">Desde</button>
                </div>
                <p class="wizard-hint">«Desde» se muestra como «desde $X». Si el valor depende del largo del pelo, activa
                    los diferentes largos: la clienta ve el precio exacto del suyo.</p>`;
        }

        const variants = {};
        const start = item.variants.length ? item.variants : suggestedVariants(base ?? item.price);

        start.forEach((variant) => (variants[variant.hair_length] = variant));

        return `
            <div class="prices-lengths">
                ${HairLength.LENGTHS.map((length) => {
                    const variant = variants[length.key];

                    return `
                        <div class="prices-length">
                            <span class="prices-length__figure">${HairLength.figure(length.key)}</span>
                            <span class="prices-length__text">
                                <strong>${esc(length.label)}</strong>
                                <span>${esc(length.hint)}</span>
                            </span>
                            <label class="prices-length__price">
                                <span aria-hidden="true">$</span>
                                <input type="text" inputmode="numeric" autocomplete="off" data-length-price="${length.key}"
                                       aria-label="Precio ${esc(length.label.toLowerCase())}"
                                       value="${variant ? Math.round(variant.price).toLocaleString('es-CL') : ''}"
                                       placeholder="0">
                            </label>
                            <label class="prices-length__time">
                                <input type="number" min="5" max="600" step="5" inputmode="numeric" data-length-duration="${length.key}"
                                       aria-label="Duración ${esc(length.label.toLowerCase())} en minutos"
                                       value="${variant && variant.duration ? variant.duration : ''}" placeholder="${item.duration}">
                                <span>min</span>
                            </label>
                        </div>`;
                }).join('')}
            </div>
            <p class="wizard-hint">${
                item.variants.length
                    ? ''
                    : `Precios sugeridos: corto es el precio actual; mediano, largo y extra largo suben ${LENGTH_INCREASE.medium} %,
                       ${LENGTH_INCREASE.long} % y ${LENGTH_INCREASE.xlong} %. Corrígelos si hace falta. `
            }Deja vacío el largo que no se ofrece. Sin duración, usa la del servicio (abajo).</p>`;
    }

    function renderEdit() {
        const item = service(state.editId);
        const lengths = item.variants.length > 0;
        const cat = category(item.category_id);
        const otherDuration = !DURATIONS.includes(item.duration);
        const remaining = state.queue ? state.queue.length : 0;

        $edit.html(`
            <span class="agenda-sheet__grab" aria-hidden="true"></span>
            <div>
                <h2 id="edit-title">${esc(capitalize(item.name.trim()))}</h2>
                <div class="detail-sub">${esc([familyOf(item).label, usageText(item)].join(' · '))}</div>
            </div>
            ${
                !item.providers
                    ? `<div class="agenda-error">Ninguna profesional lo ofrece: no se puede agendar. Asígnalo en
                           Usuarios → Proveedores, u ocúltalo.</div>`
                    : ''
            }
            <form id="edit-form" class="wizard-form" novalidate>
                <div class="prices-price">
                    <span class="prices-price__label">Precio</span>
                    <label class="prices-toggle">
                        <input type="checkbox" id="edit-lengths" ${lengths ? 'checked' : ''}>
                        <span>
                            <strong>Activar diferentes largos</strong>
                            <span>Corto, mediano, largo y extra largo, cada uno con su precio. La clienta elige su largo al reservar.</span>
                        </span>
                    </label>
                    <div id="edit-price-body" class="prices-price">${priceBody(item, lengths)}</div>
                </div>

                <div class="wizard-field">
                    <span>Duración</span>
                    <div class="prices-chips" role="radiogroup" aria-label="Duración">
                        ${DURATIONS.map(
                            (minutes) => `
                            <button type="button" role="radio" class="prices-chip" data-duration="${minutes}"
                                    aria-checked="${item.duration === minutes}">${duration(minutes)}</button>`,
                        ).join('')}
                        <input type="number" id="edit-duration" class="prices-chip prices-chip--input" min="5" max="600"
                               step="5" inputmode="numeric" placeholder="otra" aria-label="Otra duración en minutos"
                               value="${otherDuration ? item.duration : ''}">
                    </div>
                </div>

                <label class="wizard-field">
                    <span>Categoría</span>
                    <select id="edit-category" class="prices-select">
                        <option value="">Sin categoría</option>
                        ${categories
                            .map(
                                (option) =>
                                    `<option value="${option.id}" ${cat && cat.id === option.id ? 'selected' : ''}>${esc(option.name)}</option>`,
                            )
                            .join('')}
                    </select>
                </label>

                <label class="prices-toggle">
                    <input type="checkbox" id="edit-archived" ${item.is_archived ? 'checked' : ''}>
                    <span>
                        <strong>Ocultar del catálogo</strong>
                        <span>No aparece al agendar ni en la reserva online. Sus citas pasadas se conservan.</span>
                    </span>
                </label>

                <div class="prices-edit__actions">
                    ${
                        remaining
                            ? `<button type="button" id="edit-skip" class="agenda-secondary">Saltar</button>
                               <button type="submit" class="agenda-primary">Guardar y seguir (${remaining})</button>`
                            : '<button type="submit" class="agenda-primary">Guardar</button>'
                    }
                </div>
            </form>
        `);
    }

    function readEdit() {
        const item = service(state.editId);
        const change = {id: item.id};
        const lengths = $edit.find('#edit-lengths').prop('checked');
        const priceType = $edit.find('[data-price-type][aria-checked="true"]').data('price-type') || 'fixed';
        const typed = Number($edit.find('#edit-duration').val());
        const chosen = Number($edit.find('[data-duration][aria-checked="true"]').data('duration'));
        const minutes = typed || chosen || item.duration;
        const categoryId = Number($edit.find('#edit-category').val()) || null;
        const archived = $edit.find('#edit-archived').prop('checked');

        if (lengths) {
            const variants = HairLength.LENGTHS.map((length) => ({
                hair_length: length.key,
                price: parsePrice($edit.find(`[data-length-price="${length.key}"]`).val()),
                duration: Number($edit.find(`[data-length-duration="${length.key}"]`).val()) || null,
            })).filter((variant) => variant.price > 0);

            const same = (a, b) =>
                a.length === b.length &&
                a.every((variant, index) =>
                    variant.hair_length === b[index].hair_length &&
                    variant.price === Math.round(b[index].price) &&
                    (variant.duration || null) === (b[index].duration || null));

            if (!same(variants, item.variants)) {
                // Price and type travel along so "Deshacer" restores them too.
                change.variants = variants;
                change.price = item.price;
                change.price_type = item.price_type || 'fixed';
            }
        } else {
            const price = parsePrice($edit.find('#edit-price').val());

            if (item.variants.length) {
                change.variants = [];
            }

            if (price !== Math.round(item.price) || item.variants.length) {
                change.price = price;
            }

            if (priceType !== (item.price_type || 'fixed')) {
                change.price_type = priceType;
            }
        }

        if (minutes !== item.duration) {
            change.duration = minutes;
        }

        if (categoryId !== item.category_id) {
            change.category_id = categoryId || '';
        }

        if (archived !== item.is_archived) {
            change.is_archived = archived;
        }

        return change;
    }

    function nextInQueue() {
        const next = state.queue && state.queue.shift();

        if (next) {
            state.editId = next;
            renderEdit();
            $edit.scrollTop(0);
            setTimeout(() => $edit.find('#edit-price, [data-length-price]').first().trigger('focus'), 50);
        } else {
            closeSheet($edit);
        }
    }

    function submitEdit() {
        const change = readEdit();
        const item = service(change.id);

        if (Object.keys(change).length === 1) {
            if (state.queue && state.queue.length) {
                nextInQueue();
            } else {
                closeSheet($edit);
            }

            return;
        }

        const $button = $edit.find('[type="submit"]').prop('disabled', true);
        const message = change.is_archived === true
            ? 'Servicio oculto'
            : change.is_archived === false
              ? 'Servicio visible otra vez'
              : 'variants' in change && change.variants.length
                ? capitalize(item.name.trim()) + ': precios por largo guardados'
                : 'price' in change
                  ? capitalize(item.name.trim()) + ': ' + (change.price ? money(change.price) : 'sin precio')
                  : 'Cambios guardados';

        save([change], message).then((ok) => {
            if (!ok) {
                $button.prop('disabled', false);
                return;
            }

            if (state.queue && state.queue.length) {
                nextInQueue();
            } else {
                closeSheet($edit);
            }
        });
    }

    // Bulk price change -----------------------------------------------------------------------------------------

    function bulkPreview() {
        const rounding = ROUNDINGS.find((item) => item.key === state.bulk.rounding);
        const value = Number(String(state.bulk.value).replace(',', '.')) || 0;

        return [...state.selected]
            .map(service)
            .filter(Boolean)
            .sort(byUse)
            .map((item) => {
                const apply = (price) =>
                    rounding.apply(Math.max(0, state.bulk.mode === 'percent' ? price * (1 + value / 100) : price + value));

                // Priced by length: every length changes; the row shows the shortest and the longest.
                if (item.variants.length) {
                    const variants = item.variants.map((variant) => ({...variant, price: apply(variant.price)}));
                    const before = item.variants.map((variant) => Math.round(variant.price));
                    const after = variants.map((variant) => variant.price);

                    return {
                        item,
                        before: Math.min(...before),
                        after: Math.min(...after),
                        beforeMax: Math.max(...before),
                        afterMax: Math.max(...after),
                        variants,
                        changed: after.some((price, index) => price !== before[index]),
                    };
                }

                const before = Math.round(item.price);
                const after = apply(item.price);

                return {item, before, after, changed: after !== before};
            });
    }

    function openBulk() {
        renderBulk();
        openSheet($bulk);
    }

    function renderBulk() {
        const rows = bulkPreview();
        const changed = rows.filter((row) => row.changed);

        $bulkBody.html(`
            <h2>¿Cuánto cambian?</h2>
            <div class="prices-segment" role="radiogroup" aria-label="Tipo de ajuste">
                <button type="button" role="radio" data-bulk-mode="percent" aria-checked="${state.bulk.mode === 'percent'}">Porcentaje</button>
                <button type="button" role="radio" data-bulk-mode="amount" aria-checked="${state.bulk.mode === 'amount'}">Monto fijo</button>
            </div>
            <div class="prices-price__input">
                <span aria-hidden="true">${state.bulk.mode === 'percent' ? '%' : '$'}</span>
                <input type="text" id="bulk-value" inputmode="decimal" autocomplete="off" value="${esc(state.bulk.value)}"
                       aria-label="${state.bulk.mode === 'percent' ? 'Porcentaje' : 'Monto'}">
            </div>
            <p class="wizard-hint">Usa un número negativo para bajar (por ejemplo −10).</p>

            <div class="wizard-field">
                <span>Redondeo</span>
                <div class="prices-chips" role="radiogroup" aria-label="Redondeo">
                    ${ROUNDINGS.map(
                        (item) => `
                        <button type="button" role="radio" class="prices-chip" data-rounding="${item.key}"
                                aria-checked="${state.bulk.rounding === item.key}">${esc(item.label)}</button>`,
                    ).join('')}
                </div>
            </div>

            <div id="bulk-preview">${renderBulkPreview(rows)}</div>
        `);

        renderBulkFooter(changed.length);
    }

    function renderBulkPreview(rows) {
        return `
            <h3 class="prices-group__title">Vista previa</h3>
            <ul class="prices-rows prices-rows--card">
                ${rows
                    .map(
                        (row) => `
                    <li class="prices-preview">
                        <span class="prices-row__title">${esc(capitalize(row.item.name.trim()))}</span>
                        <span class="prices-preview__values">
                            <s>${money(row.before)}${row.variants ? '–' + money(row.beforeMax) : ''}</s>
                            <strong class="${row.after > row.before ? 'is-up' : row.after < row.before ? 'is-down' : ''}">${money(row.after)}${row.variants ? '–' + money(row.afterMax) : ''}</strong>
                        </span>
                    </li>`,
                    )
                    .join('')}
            </ul>`;
    }

    function renderBulkFooter(count) {
        $bulkFooter.html(`
            <button type="button" id="bulk-apply" class="agenda-primary" ${count ? '' : 'disabled'}>
                ${count ? `Aplicar a ${count} ${count === 1 ? 'servicio' : 'servicios'}` : 'Sin cambios'}
            </button>
        `);
    }

    function refreshBulkPreview() {
        const rows = bulkPreview();

        $('#bulk-preview').html(renderBulkPreview(rows));
        renderBulkFooter(rows.filter((row) => row.changed).length);
    }

    function applyBulk() {
        const changes = bulkPreview()
            .filter((row) => row.changed)
            .map((row) =>
                row.variants
                    ? {
                          id: row.item.id,
                          variants: row.variants.map(({hair_length, price, duration}) => ({hair_length, price, duration})),
                          price: row.item.price,
                          price_type: row.item.price_type,
                      }
                    : {id: row.item.id, price: row.after},
            );

        const $button = $('#bulk-apply').prop('disabled', true);

        save(changes, changes.length + (changes.length === 1 ? ' precio actualizado' : ' precios actualizados')).then(
            (ok) => {
                if (!ok) {
                    $button.prop('disabled', false);
                    return;
                }

                state.selecting = false;
                state.selected.clear();
                closeSheet($bulk);
                render();
            },
        );
    }

    // Cleanup ---------------------------------------------------------------------------------------------------

    const CLEANUP_KINDS = [
        {
            kind: 'price',
            title: 'Precio que parece un error',
            hint: 'Un precio menor a $1.000 suele ser un error de tipeo (por ejemplo 29,99 en vez de 29.990).',
        },
        {
            kind: 'nobody',
            title: 'Nadie los ofrece',
            hint: 'Ninguna profesional los tiene asignados, así que no se pueden agendar. Si ya no se hacen, ocúltalos.',
            hideAll: true,
        },
        {
            kind: 'unused',
            title: 'Nunca agendados',
            hint: 'Nadie los ha reservado. Ocultarlos acorta la lista al agendar; puedes volver a mostrarlos cuando quieras.',
            hideAll: true,
        },
        {
            kind: 'category',
            title: 'Sin categoría',
            hint: 'En la reserva online quedan sueltos. Asígnales una categoría.',
        },
    ];

    function openCleanup() {
        renderCleanup();
        openSheet($cleanup);
    }

    function renderCleanup() {
        const items = cleanupItems();

        if (!items.length) {
            $cleanupBody.html('<div class="agenda-empty">El catálogo está en orden. ✨</div>');
            return;
        }

        $cleanupBody.html(
            CLEANUP_KINDS.map((section) => {
                const list = items.filter((entry) => entry.kind === section.kind).map((entry) => entry.item);

                if (!list.length) {
                    return '';
                }

                return `
                    <section class="prices-cleanup">
                        <div class="prices-group__head">
                            <h2 class="prices-group__title">${esc(section.title)}
                                <span class="prices-group__count">${list.length}</span></h2>
                            ${
                                section.hideAll && list.length > 1
                                    ? `<button type="button" class="prices-group__all" data-hide-kind="${section.kind}">Ocultar todos</button>`
                                    : ''
                            }
                        </div>
                        <p class="wizard-hint">${esc(section.hint)}</p>
                        <ul class="prices-rows prices-rows--card">
                            ${list.map((item) => cleanupRow(section.kind, item)).join('')}
                        </ul>
                    </section>`;
            }).join(''),
        );
    }

    function cleanupRow(kind, item) {
        let action = `<button type="button" class="prices-mini" data-hide-id="${item.id}">Ocultar</button>`;
        let sub = usageText(item) + ' · ' + (priceText(item) || 'sin precio');

        if (kind === 'price') {
            const fixed = Math.round(item.price * 1000);

            action = `<button type="button" class="prices-mini prices-mini--brand" data-fix-id="${item.id}" data-fix-price="${fixed}">
                          Corregir a ${money(fixed)}</button>`;
            sub = 'Precio actual: $' + String(item.price).replace('.', ',');
        } else if (kind === 'category') {
            action = `<button type="button" class="prices-mini" data-edit-id="${item.id}">Asignar</button>`;
        }

        return `
            <li class="prices-cleanup__row">
                <span class="prices-row__text">
                    <span class="prices-row__title">${esc(capitalize(item.name.trim()))}</span>
                    <span class="prices-row__sub">${esc(sub)}</span>
                </span>
                ${action}
            </li>`;
    }

    function hide(ids) {
        const changes = ids.map((id) => ({id, is_archived: true}));

        save(changes, ids.length === 1 ? 'Servicio oculto' : ids.length + ' servicios ocultos');
    }

    // Events ----------------------------------------------------------------------------------------------------

    function startSelecting() {
        state.selecting = true;
        state.selected.clear();
        state.keyword = '';
        $search.val('');
        render();
        window.scrollTo(0, 0);
    }

    function stopSelecting() {
        state.selecting = false;
        state.selected.clear();
        render();
    }

    function toggleSelected(id) {
        if (state.selected.has(id)) {
            state.selected.delete(id);
        } else {
            state.selected.add(id);
        }

        render();
    }

    function bindEvents() {
        $filters.on('click', '[data-filter]', (event) => {
            state.filter = $(event.currentTarget).data('filter');

            if (state.filter !== 'all') {
                state.selecting = false;
                state.selected.clear();
            }

            render();
        });

        $search.on('input', (event) => {
            state.keyword = event.target.value;
            renderList();
        });

        $list.on('click', '[data-service-id]', (event) => {
            const id = Number($(event.currentTarget).data('service-id'));

            if (state.selecting) {
                toggleSelected(id);
            } else {
                openEdit(id);
            }
        });

        $list.on('click', '[data-select-family]', (event) => {
            const key = $(event.currentTarget).data('select-family');
            const items = visible().filter((item) => familyOf(item).key === key && item.price > 0 && matchesKeyword(item));
            const all = items.every((item) => state.selected.has(item.id));

            items.forEach((item) => (all ? state.selected.delete(item.id) : state.selected.add(item.id)));
            render();
        });

        $list.on('click', '#prices-fill-queue', () => {
            const queue = visible()
                .filter((item) => !(item.price > 0) && matchesKeyword(item))
                .sort(byUse)
                .map((item) => item.id);

            openEdit(queue.shift(), queue);
        });

        $banner.on('click', '#prices-open-cleanup', openCleanup);

        $adjust.on('click', startSelecting);

        $selectbar.on('click', '#prices-select-cancel', stopSelecting);
        $selectbar.on('click', '#prices-select-next', openBulk);

        // Edit sheet.
        $edit.on('input', '#edit-price, [data-length-price]', (event) => {
            const value = parsePrice(event.target.value);
            event.target.value = value ? value.toLocaleString('es-CL') : '';
        });

        // Lengths on: the suggested prices start from the price typed; off: the single price starts from the short one.
        $edit.on('change', '#edit-lengths', (event) => {
            const item = service(state.editId);
            const lengths = event.target.checked;
            const base = lengths
                ? parsePrice($edit.find('#edit-price').val())
                : parsePrice($edit.find('[data-length-price="short"]').val()) || null;

            $edit.find('#edit-price-body').html(priceBody(item, lengths, base));
        });

        $edit.on('click', '[data-price-type]', (event) => {
            $edit.find('[data-price-type]').attr('aria-checked', 'false');
            $(event.currentTarget).attr('aria-checked', 'true');
        });

        $edit.on('click', '[data-duration]', (event) => {
            $edit.find('[data-duration]').attr('aria-checked', 'false');
            $(event.currentTarget).attr('aria-checked', 'true');
            $edit.find('#edit-duration').val('');
        });

        $edit.on('input', '#edit-duration', (event) => {
            if (event.target.value) {
                $edit.find('[data-duration]').attr('aria-checked', 'false');
            }
        });

        $edit.on('submit', '#edit-form', (event) => {
            event.preventDefault();
            submitEdit();
        });

        $edit.on('click', '#edit-skip', nextInQueue);

        // Bulk sheet.
        $bulkBody.on('click', '[data-bulk-mode]', (event) => {
            const mode = $(event.currentTarget).data('bulk-mode');

            if (mode !== state.bulk.mode) {
                state.bulk.mode = mode;
                state.bulk.value = mode === 'percent' ? '10' : '2000';
                renderBulk();
            }
        });

        $bulkBody.on('input', '#bulk-value', (event) => {
            state.bulk.value = event.target.value.replace(/[^\d,.\-−]/g, '').replace('−', '-');
            refreshBulkPreview();
        });

        $bulkBody.on('click', '[data-rounding]', (event) => {
            state.bulk.rounding = $(event.currentTarget).data('rounding');
            $bulkBody.find('[data-rounding]').attr('aria-checked', 'false');
            $(event.currentTarget).attr('aria-checked', 'true');
            refreshBulkPreview();
        });

        $bulkFooter.on('click', '#bulk-apply', applyBulk);

        // Cleanup sheet.
        $cleanupBody.on('click', '[data-hide-id]', (event) => hide([Number($(event.currentTarget).data('hide-id'))]));

        $cleanupBody.on('click', '[data-hide-kind]', (event) => {
            const kind = $(event.currentTarget).data('hide-kind');

            hide(cleanupItems().filter((entry) => entry.kind === kind).map((entry) => entry.item.id));
        });

        $cleanupBody.on('click', '[data-fix-id]', (event) => {
            const $button = $(event.currentTarget);

            save([{id: Number($button.data('fix-id')), price: Number($button.data('fix-price'))}], 'Precio corregido');
        });

        $cleanupBody.on('click', '[data-edit-id]', (event) => {
            const id = Number($(event.currentTarget).data('edit-id'));

            closeSheet($cleanup);
            setTimeout(() => openEdit(id), 0);
        });

        $page.on('click', '[data-close-sheet]', (event) => closeSheet($(event.currentTarget).closest('.agenda-sheet')));

        $backdrop.on('click', () => closeSheet($edit));

        // Android "back" closes the open sheet instead of leaving the page.
        window.addEventListener('popstate', () => {
            if (skipPopstate > 0) {
                skipPopstate--;
                return;
            }

            const $top = topSheet();

            if ($top) {
                closeSheet($top, true);
            } else if (state.selecting) {
                stopSelecting();
            }
        });
    }

    function initialize() {
        if (!$page.length) {
            return;
        }

        bindEvents();
        render();
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {
        render,
    };
})();
