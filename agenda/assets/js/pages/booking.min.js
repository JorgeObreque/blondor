/* ----------------------------------------------------------------------------
 * Blondor - Reserva pública, móvil primero
 *
 * Reemplaza el asistente de 4 pasos de Easy!Appointments por el flujo de Fresha y Booksy:
 *   1. Servicio: lista por familia con duración y precio; un toque elige y avanza. Los servicios que se cobran
 *      según el largo muestran el precio de cada largo y preguntan «¿Qué largo tienes?» con una infografía.
 *   2. Día y hora: tira de días (los sin horas aparecen apagados) y horas agrupadas en mañana, tarde y noche.
 *   3. Tus datos y confirmar: resumen de la cita, formulario corto y un solo botón.
 * La profesional solo se pregunta cuando más de una hace el servicio. El botón «atrás» del teléfono vuelve un paso.
 * ---------------------------------------------------------------------------- */

/**
 * Booking page.
 *
 * This module implements the functionality of the booking page.
 */
App.Pages.Booking = (function () {
    const $page = $('#booking-page');
    const $title = $('#bk-title');
    const $count = $('#bk-count');
    const $back = $('#bk-back');
    const $services = $('#bk-services');
    const $search = $('#bk-search');
    const $pickedService = $('#bk-picked-service');
    const $providers = $('#bk-providers');
    const $providerChips = $('#bk-provider-chips');
    const $monthLabel = $('#bk-month-label');
    const $days = $('#bk-days');
    const $slots = $('#bk-slots');
    const $summary = $('#bk-summary');
    const $form = $('#bk-form');
    const $bar = $('#bk-bar');
    const $barText = $('#bk-bar-text');
    const $next = $('#bk-next');
    const $toast = $('#bk-toast');
    const $lengths = $('#bk-lengths');
    const $lengthChips = $('#bk-length-chips');
    const $lengthSheet = $('#bk-length-sheet');
    const $lengthOptions = $('#bk-length-options');
    const moment = window.moment;
    const HairLength = App.Utils.HairLength;

    const STEPS = ['service', 'time', 'details'];
    const ANY_PROVIDER = 'any-provider';
    const STORAGE_KEY = 'blondor.booking.customer';

    /**
     * Families group the services as clients look for them; the first match wins (same rules as /precios).
     */
    const FAMILIES = [
        {key: 'promo', label: 'Promociones', test: /^promo/},
        {key: 'color', label: 'Color y mechas', test: /color|balay|babyl|mechas|visos|contouring|canas|decolor/},
        {key: 'corte', label: 'Cortes', test: /^corte\b/},
        {key: 'tratamiento', label: 'Tratamientos', test: /botox|olaplex|k18|ampolla|total mask|hidratante/},
        {key: 'alisado', label: 'Alisado', test: /alisado/},
        {key: 'peinado', label: 'Peinados', test: /brushing|peinado|ondas/},
        {key: 'permanente', label: 'Permanente', test: /^permanente/},
        {key: 'masaje', label: 'Masajes', test: /masaje/},
        {key: 'manos', label: 'Manos y pies', test: /manos|pies|manicure|pedicur/},
        {key: 'depilacion', label: 'Depilación', test: /depilaci|rebaje|cejas/},
        {key: 'facial', label: 'Facial', test: /facial/},
        {key: 'otros', label: 'Otros', test: /./},
    ];

    const DAY_SHORT = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    const DAY_LONG = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    const MONTHS = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    const manageMode = Boolean(vars('manage_mode'));
    const appointmentData = vars('appointment_data');
    const appointmentId = manageMode && appointmentData ? appointmentData.id : null;

    const providers = (vars('available_providers') || []).map((provider) => ({
        ...provider,
        id: Number(provider.id),
        services: (provider.services || []).map(Number),
    }));

    const services = (vars('available_services') || [])
        .map((service) => ({
            ...service,
            id: Number(service.id),
            duration: Number(service.duration) || 30,
            price: Number(service.price) || 0,
            variants: (service.variants || []).map((variant) => ({
                ...variant,
                price: Number(variant.price) || 0,
                duration: Number(variant.duration) || null,
            })),
        }))
        .filter((service) => providersFor(service.id).length > 0);

    const state = {
        step: 'service',
        depth: 0,
        keyword: '',
        serviceId: null,
        hairLength: null,
        providerId: null,
        date: null,
        time: null,
        unavailable: {}, // "service|length|provider" → {"YYYY-MM": Set of Y-m-d}
        hours: {}, // "service|length|provider|date" → ["HH:mm"]
        loadingMonths: false,
        saving: false,
        deleteArmed: false,
    };

    let toastTimer = null;

    // Helpers ---------------------------------------------------------------------------------------------------

    function esc(value) {
        return App.Utils.String.escapeHtml(String(value ?? ''));
    }

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .trim();
    }

    /**
     * "MECHAS CON PAPEL" → "Mechas con papel", "color raiz" → "Color raiz".
     */
    function displayName(service) {
        const name = String(service.name || '').trim().replace(/\s+/g, ' ');
        const lower = name === name.toUpperCase() ? name.toLowerCase() : name;

        return lower.charAt(0).toUpperCase() + lower.slice(1);
    }

    function money(value) {
        return '$' + Math.round(value).toLocaleString('es-CL');
    }

    function priceText(service) {
        if (!(service.price > 0)) {
            return '';
        }

        return (service.price_type === 'from' && !service.hair_length ? 'desde ' : '') + money(service.price);
    }

    function durationText(total) {
        if (total < 60) {
            return total + ' min';
        }

        const hours = Math.floor(total / 60);
        const minutes = total % 60;

        return hours + ' h' + (minutes ? ' ' + minutes + ' min' : '');
    }

    function timeText(hhmm) {
        if (vars('time_format') !== 'regular') {
            return hhmm;
        }

        const [hours, minutes] = hhmm.split(':').map(Number);
        const suffix = hours < 12 ? 'a. m.' : 'p. m.';

        return (hours % 12 || 12) + ':' + String(minutes).padStart(2, '0') + ' ' + suffix;
    }

    function dateLong(date) {
        const day = moment(date);

        return DAY_LONG[day.day()] + ' ' + day.date() + ' de ' + MONTHS[day.month()];
    }

    function dateShort(date) {
        const day = moment(date);

        return DAY_SHORT[day.day()] + ' ' + day.date() + ' ' + MONTHS[day.month()].slice(0, 3);
    }

    function service(id) {
        return services.find((item) => item.id === Number(id));
    }

    /**
     * The chosen service as it is booked: with the price and duration of the chosen hair length.
     */
    function booked() {
        const item = service(state.serviceId);
        const variant = item && item.variants.find((entry) => entry.hair_length === state.hairLength);

        if (!variant) {
            return item;
        }

        return {
            ...item,
            hair_length: variant.hair_length,
            price: variant.price,
            duration: variant.duration || item.duration,
        };
    }

    /**
     * "Balayage · Largo"
     */
    function bookedName() {
        const item = booked();
        const length = HairLength.label(item.hair_length);

        return displayName(item) + (length ? ' · ' + length : '');
    }

    function providersFor(serviceId) {
        return providers.filter((provider) => provider.services.includes(Number(serviceId)));
    }

    function familyOf(item) {
        const name = normalize(item.name);

        return FAMILIES.find((family) => family.test.test(name));
    }

    function availabilityKey() {
        return state.serviceId + '|' + (state.hairLength || '') + '|' + state.providerId;
    }

    /**
     * Provider used to ask for hours: the chosen one, or the only one that does the service.
     */
    function defaultProviderId(serviceId) {
        const candidates = providersFor(serviceId);

        if (candidates.length === 1) {
            return candidates[0].id;
        }

        return vars('display_any_provider') === '1' ? ANY_PROVIDER : candidates[0]?.id ?? null;
    }

    function providerName(providerId) {
        const provider = providers.find((item) => item.id === Number(providerId));

        if (!provider) {
            return '';
        }

        const first = String(provider.first_name || '').trim();

        return first.charAt(0).toUpperCase() + first.slice(1);
    }

    function showToast(message, isError = true) {
        clearTimeout(toastTimer);
        $toast.text(message).toggleClass('bk-toast--error', isError).prop('hidden', false);
        toastTimer = setTimeout(() => $toast.prop('hidden', true), 5000);
    }

    function readSavedCustomer() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (error) {
            return null;
        }
    }

    function writeSavedCustomer(customer) {
        try {
            if (customer) {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(customer));
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (error) {
            // Private mode or blocked storage: the booking works the same without it.
        }
    }

    /**
     * Services priced by hair length need one before choosing a time.
     */
    function lengthReady() {
        const item = service(state.serviceId);

        return !item || !item.variants.length || item.variants.some((variant) => variant.hair_length === state.hairLength);
    }

    // Navigation ------------------------------------------------------------------------------------------------

    const TITLES = {
        service: 'Elige tu servicio',
        time: 'Elige día y hora',
        details: manageMode ? 'Revisa y guarda' : 'Tus datos',
    };

    function render(step) {
        state.step = step;

        const index = STEPS.indexOf(step);

        $page.attr('data-step', step);
        $page.find('.bk-step').each((i, el) => {
            el.hidden = el.dataset.step !== step;
        });
        $title.text(TITLES[step]);
        $count.text('Paso ' + (index + 1) + ' de ' + STEPS.length);
        $back.prop('hidden', index === 0);

        if (step === 'service') {
            renderServices();
        } else if (step === 'time') {
            renderTimeStep();
        } else {
            renderSummary();
        }

        renderBar();
        window.scrollTo(0, 0);
    }

    function goTo(step) {
        state.depth += 1;
        history.pushState({bkStep: step, depth: state.depth}, '');
        render(step);
    }

    function goBack() {
        if (state.depth > 0) {
            history.back();
            return;
        }

        // Entered on a later step (link with ?service=…): go back inside the page.
        const previous = STEPS[Math.max(0, STEPS.indexOf(state.step) - 1)];
        history.replaceState({bkStep: previous, depth: 0}, '');
        render(previous);
    }

    function renderBar() {
        if (state.step === 'service') {
            $bar.prop('hidden', true);
            return;
        }

        $bar.prop('hidden', false);

        if (state.step === 'time') {
            const ready = Boolean(state.date && state.time);

            $barText.html(
                ready
                    ? `<strong>${esc(dateShort(state.date))} · ${esc(timeText(state.time))}</strong>
                       <span>${esc(bookedName())}</span>`
                    : '<span>Elige una hora para continuar</span>',
            );
            $next.text('Continuar').prop('disabled', !ready);
            return;
        }

        const price = priceText(booked());

        $barText.html(
            price ? `<span>Total</span><strong>${esc(price)}</strong>` : `<strong>${esc(bookedName())}</strong>`,
        );
        $next.text(state.saving ? 'Guardando…' : manageMode ? 'Guardar cambios' : 'Confirmar reserva');
        $next.prop('disabled', state.saving);
    }

    // Step 1: service -------------------------------------------------------------------------------------------

    function serviceRow(item) {
        const price = priceText(item);
        const description = String(item.description || '').trim();

        if (item.variants.length) {
            return variantRow(item, description);
        }

        return `
            <li>
                <button type="button" class="bk-svc ${item.id === state.serviceId ? 'is-selected' : ''}" data-service="${item.id}">
                    <span class="bk-svc__text">
                        <span class="bk-svc__name">${esc(displayName(item))}</span>
                        <span class="bk-svc__meta">${esc(durationText(item.duration))}</span>
                        ${description ? `<span class="bk-svc__desc">${esc(description)}</span>` : ''}
                    </span>
                    <span class="bk-svc__price">${price ? esc(price) : '<small>Precio a confirmar</small>'}</span>
                    <svg class="bk-svc__go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </li>`;
    }

    /**
     * A service priced by hair length: the price of each length, no "desde".
     */
    function variantRow(item, description) {
        const durations = [...new Set(item.variants.map((variant) => variant.duration || item.duration))];
        const meta = durations.length > 1
            ? durationText(Math.min(...durations)) + ' a ' + durationText(Math.max(...durations))
            : durationText(durations[0]);

        return `
            <li>
                <button type="button" class="bk-svc bk-svc--variants ${item.id === state.serviceId ? 'is-selected' : ''}" data-service="${item.id}">
                    <span class="bk-svc__text">
                        <span class="bk-svc__name">${esc(displayName(item))}</span>
                        <span class="bk-svc__meta">${esc(meta)} · precio según tu largo</span>
                        ${description ? `<span class="bk-svc__desc">${esc(description)}</span>` : ''}
                        <span class="bk-svc__lengths">
                            ${item.variants
                                .map(
                                    (variant) => `
                                        <span class="bk-svc__length">
                                            <span>${esc(HairLength.label(variant.hair_length))}</span>
                                            <strong>${esc(money(variant.price))}</strong>
                                        </span>`,
                                )
                                .join('')}
                        </span>
                    </span>
                    <svg class="bk-svc__go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </li>`;
    }

    function renderServices() {
        const keyword = normalize(state.keyword);

        if (keyword) {
            const matches = services.filter((item) =>
                keyword
                    .split(/\s+/)
                    .every((word) => normalize(item.name).includes(word) || normalize(familyOf(item).label).includes(word)),
            );

            $services.html(
                matches.length
                    ? `<ul class="bk-list">${matches.map(serviceRow).join('')}</ul>`
                    : `<p class="bk-empty">No encontramos «${esc(state.keyword)}». Prueba con otra palabra o
                       <a href="${whatsappUrl('Hola, quiero reservar: ' + state.keyword)}" target="_blank" rel="noopener">escríbenos por WhatsApp</a>.</p>`,
            );
            return;
        }

        const popular = (vars('popular_service_ids') || []).map(service).filter(Boolean);

        const groups = FAMILIES.map((family) => ({
            family,
            items: services
                .filter((item) => familyOf(item) === family)
                .sort((a, b) => displayName(a).localeCompare(displayName(b), 'es')),
        })).filter((group) => group.items.length);

        const nav = groups
            .map((group) => `<a class="bk-chip" href="#bk-family-${group.family.key}">${esc(group.family.label)}</a>`)
            .join('');

        $services.html(`
            <nav class="bk-chips bk-chips--scroll" aria-label="Familias">${nav}</nav>
            ${popular.length
                ? `<section class="bk-group">
                       <h2 class="bk-group__title">Más pedidos</h2>
                       <ul class="bk-list">${popular.map(serviceRow).join('')}</ul>
                   </section>`
                : ''}
            ${groups
                .map(
                    (group) => `
                        <section class="bk-group" id="bk-family-${group.family.key}">
                            <h2 class="bk-group__title">${esc(group.family.label)}</h2>
                            <ul class="bk-list">${group.items.map(serviceRow).join('')}</ul>
                        </section>`,
                )
                .join('')}
        `);
    }

    function selectService(id) {
        if (state.serviceId !== id) {
            state.serviceId = id;
            state.hairLength = null;
            state.providerId = defaultProviderId(id);
            state.date = null;
            state.time = null;
        }

        if (service(id).variants.length) {
            openLengthSheet(true);
            return;
        }

        goTo('time');
    }

    // Hair length -----------------------------------------------------------------------------------------------

    /**
     * "¿Qué largo tienes?": one card per length with its figure and price. Choosing one goes on to day and time.
     */
    function openLengthSheet(open) {
        $('#bk-backdrop, #bk-length-sheet').prop('hidden', !open);
        $('body').toggleClass('bk-locked', open);

        if (!open) {
            return;
        }

        const item = service(state.serviceId);

        $('#bk-length-title').text('¿Qué largo tienes?');
        $('#bk-length-service').text(displayName(item));
        $lengthOptions.html(
            item.variants
                .map((variant) => {
                    const length = HairLength.find(variant.hair_length);
                    const minutes = variant.duration || item.duration;

                    return `
                        <button type="button" class="bk-length" role="radio" data-length="${variant.hair_length}"
                                aria-checked="${variant.hair_length === state.hairLength}">
                            <span class="bk-length__figure">${HairLength.figure(variant.hair_length)}</span>
                            <span class="bk-length__name">${esc(length.label)}</span>
                            <span class="bk-length__hint">${esc(length.hint)}</span>
                            <strong class="bk-length__price">${esc(money(variant.price))}</strong>
                            <span class="bk-length__time">${esc(durationText(minutes))}</span>
                        </button>`;
                })
                .join(''),
        );

        // Focus inside the dialog without looking like a choice was made.
        const $checked = $lengthSheet.find('[aria-checked="true"]');

        ($checked.length ? $checked : $('#bk-length-title').attr('tabindex', '-1')).trigger('focus');
    }

    function selectLength(length) {
        if (state.hairLength !== length) {
            state.hairLength = length;
            state.date = null;
            state.time = null;
        }

        openLengthSheet(false);

        if (state.step === 'time') {
            renderTimeStep();
            renderBar();
        } else {
            goTo('time');
        }
    }

    function renderLengths() {
        const item = service(state.serviceId);

        $lengths.prop('hidden', !item.variants.length);

        if (!item.variants.length) {
            return;
        }

        $lengthChips.html(
            item.variants
                .map(
                    (variant) => `
                        <button type="button" class="bk-chip" role="radio" data-length="${variant.hair_length}"
                                aria-checked="${variant.hair_length === state.hairLength}">
                            ${esc(HairLength.label(variant.hair_length))} · ${esc(money(variant.price))}
                        </button>`,
                )
                .join(''),
        );
    }

    // Step 2: day and time --------------------------------------------------------------------------------------

    function renderTimeStep() {
        const item = booked();
        const price = priceText(item);

        $pickedService
            .find('.bk-picked__text')
            .html(
                `<strong>${esc(bookedName())}</strong>
                 <span>${esc([durationText(item.duration), price].filter(Boolean).join(' · '))}</span>`,
            );

        renderLengths();
        renderProviders();
        renderDays();
        loadAvailability();

        if (state.date) {
            loadHours();
        }
    }

    function renderProviders() {
        const candidates = providersFor(state.serviceId);

        $providers.prop('hidden', candidates.length < 2);

        if (candidates.length < 2) {
            return;
        }

        const options = [];

        if (vars('display_any_provider') === '1') {
            options.push({id: ANY_PROVIDER, label: 'Sin preferencia'});
        }

        candidates.forEach((provider) => options.push({id: provider.id, label: providerName(provider.id)}));

        $providerChips.html(
            options
                .map(
                    (option) => `
                        <button type="button" class="bk-chip" role="radio" data-provider="${option.id}"
                                aria-checked="${String(option.id) === String(state.providerId)}">${esc(option.label)}</button>`,
                )
                .join(''),
        );
    }

    function bookingDays() {
        const days = [];
        const first = moment().startOf('day');
        const limit = Number(vars('future_booking_limit')) || 90;

        for (let i = 0; i <= limit; i++) {
            days.push(first.clone().add(i, 'days').format('YYYY-MM-DD'));
        }

        return days;
    }

    function isUnavailable(date) {
        const months = state.unavailable[availabilityKey()] || {};
        const month = months[date.slice(0, 7)];

        return month ? month.has(date) : null; // null: not loaded yet
    }

    function renderDays() {
        const html = bookingDays()
            .map((date) => {
                const unavailable = isUnavailable(date);
                const day = moment(date);
                const isFirstOfMonth = day.date() === 1;

                return `
                    ${isFirstOfMonth ? `<span class="bk-days__month">${esc(MONTHS[day.month()].slice(0, 3))}</span>` : ''}
                    <button type="button" class="bk-day ${unavailable === null ? 'is-loading' : ''}" role="radio"
                            data-date="${date}" aria-checked="${date === state.date}"
                            ${unavailable ? 'disabled aria-disabled="true"' : ''}
                            aria-label="${esc(dateLong(date))}${unavailable ? ', sin horas' : ''}">
                        <span class="bk-day__name">${esc(DAY_SHORT[day.day()])}</span>
                        <span class="bk-day__num">${day.date()}</span>
                    </button>`;
            })
            .join('');

        $days.html(html);
        $monthLabel.text(state.date ? capitalize(dateLong(state.date)) : 'Buscando horas disponibles…');

        const selected = $days.find('[aria-checked="true"]')[0];

        if (selected) {
            selected.scrollIntoView({block: 'nearest', inline: 'center'});
        }
    }

    function capitalize(text) {
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
    }

    /**
     * Load the unavailable days month by month, select the first day with free hours and keep loading the rest.
     */
    async function loadAvailability() {
        const key = availabilityKey();

        if (!state.providerId) {
            renderNoHours();
            return;
        }

        state.unavailable[key] = state.unavailable[key] || {};

        const months = [...new Set(bookingDays().map((date) => date.slice(0, 7)))];
        let failed = false;

        for (const month of months) {
            if (availabilityKey() !== key) {
                return; // The service or provider changed meanwhile.
            }

            if (!state.unavailable[key][month]) {
                try {
                    const dates = await App.Http.Booking.getUnavailableDates({
                        serviceId: state.serviceId,
                        hairLength: state.hairLength,
                        providerId: state.providerId,
                        date: month + '-01',
                        appointmentId,
                    });

                    state.unavailable[key][month] = new Set(dates);
                } catch (error) {
                    failed = true;
                    showToast(error.message);
                    break;
                }

                if (availabilityKey() !== key || state.step !== 'time') {
                    return;
                }

                renderDays();
            }

            if (!state.date) {
                const first = bookingDays().find((date) => date.startsWith(month) && isUnavailable(date) === false);

                if (first) {
                    selectDate(first);
                }
            }
        }

        if (!state.date && !failed) {
            renderNoHours();
        }
    }

    function selectDate(date) {
        state.date = date;
        state.time = null;
        renderDays();
        renderBar();
        loadHours();
    }

    async function loadHours() {
        const date = state.date;
        const key = availabilityKey() + '|' + date;

        if (!state.hours[key]) {
            $slots.html('<div class="bk-slots__loading"><span></span><span></span><span></span><span></span></div>');

            try {
                state.hours[key] = await App.Http.Booking.getAvailableHours({
                    serviceId: state.serviceId,
                    hairLength: state.hairLength,
                    providerId: state.providerId,
                    date,
                    duration: booked().duration,
                    appointmentId,
                });
            } catch (error) {
                $slots.html(`<p class="bk-empty">${esc(error.message)}</p>`);
                return;
            }
        }

        if (state.date !== date) {
            return;
        }

        renderSlots(state.hours[key]);
    }

    function renderSlots(hours) {
        if (!hours.length) {
            $slots.html('<p class="bk-empty">Este día ya no quedan horas. Elige otro día.</p>');
            return;
        }

        const groups = [
            {label: 'Mañana', items: hours.filter((hhmm) => hhmm < '12:00')},
            {label: 'Tarde', items: hours.filter((hhmm) => hhmm >= '12:00' && hhmm < '18:00')},
            {label: 'Noche', items: hours.filter((hhmm) => hhmm >= '18:00')},
        ].filter((group) => group.items.length);

        $slots.html(
            groups
                .map(
                    (group) => `
                        <div class="bk-slots__group">
                            <p class="bk-label">${group.label}</p>
                            <div class="bk-slots__grid" role="radiogroup" aria-label="${group.label}">
                                ${group.items
                                    .map(
                                        (hhmm) => `
                                            <button type="button" class="bk-slot" role="radio" data-time="${hhmm}"
                                                    aria-checked="${hhmm === state.time}">${esc(timeText(hhmm))}</button>`,
                                    )
                                    .join('')}
                            </div>
                        </div>`,
                )
                .join(''),
        );
    }

    function renderNoHours() {
        $monthLabel.text('Sin horas disponibles');
        $slots.html(`
            <div class="bk-empty bk-empty--card">
                <strong>No quedan horas online para este servicio.</strong>
                <span>Escríbenos y te buscamos un espacio.</span>
                <a class="bk-secondary" href="${whatsappUrl('Hola, quiero reservar ' + bookedName())}"
                   target="_blank" rel="noopener">Escribir por WhatsApp</a>
            </div>`);
    }

    function whatsappUrl(text) {
        return 'https://wa.me/' + $page.data('whatsapp') + '?text=' + encodeURIComponent(text);
    }

    // Step 3: details and confirmation --------------------------------------------------------------------------

    function renderSummary() {
        const item = booked();
        const price = priceText(item);
        const choseProvider = providersFor(state.serviceId).length > 1 && state.providerId !== ANY_PROVIDER;

        $summary.html(`
            <p class="bk-summary__service">${esc(bookedName())}</p>
            <p class="bk-summary__when">${esc(capitalize(dateLong(state.date)))}, ${esc(timeText(state.time))}</p>
            <ul class="bk-summary__facts">
                <li>${esc(durationText(item.duration))}</li>
                ${price ? `<li>${esc(price)}</li>` : ''}
                ${choseProvider ? `<li>Con ${esc(providerName(state.providerId))}</li>` : ''}
            </ul>
            <p class="bk-summary__where">${esc($page.data('address'))}</p>
            <button type="button" class="bk-summary__edit" data-goto="time">Cambiar día u hora</button>
        `);
    }

    function fillCustomer() {
        const customer = manageMode ? vars('customer_data') : readSavedCustomer();
        const fields = {
            'first-name': 'first_name',
            'last-name': 'last_name',
            'phone-number': 'phone_number',
            'email': 'email',
            'address': 'address',
            'city': 'city',
            'zip-code': 'zip_code',
        };

        Object.entries(fields).forEach(([id, key]) => {
            const $input = $('#' + id);

            if ($input.length && customer && customer[key]) {
                $input.val(customer[key]);
            }
        });

        if (manageMode && appointmentData && appointmentData.notes) {
            $('#notes').val(appointmentData.notes);
        }

        // Links can prefill the form (?first_name=…&phone=…), as in Easy!Appointments.
        const params = {first_name: 'first-name', last_name: 'last-name', email: 'email', phone: 'phone-number'};

        Object.entries(params).forEach(([param, id]) => {
            const value = App.Utils.Url.queryParam(param);

            if (value) {
                $('#' + id).val(value);
            }
        });

        if (manageMode) {
            $('#bk-remember').closest('.bk-check').prop('hidden', true);
        }
    }

    function setFieldError($input, message) {
        const $error = $input.closest('.bk-field').find('.bk-field__error');

        $input.toggleClass('is-invalid', Boolean(message)).attr('aria-invalid', message ? 'true' : null);
        $error.text(message || '').prop('hidden', !message);
    }

    function validateForm() {
        let firstInvalid = null;

        $form.find('input, textarea').each((index, el) => {
            const $input = $(el);

            if (el.type === 'checkbox' || el.id === 'captcha-text') {
                return;
            }

            const value = String($input.val() || '').trim();
            let message = '';

            if ((el.required || $input.hasClass('required')) && !value) {
                message = 'Completa este dato.';
            } else if (el.id === 'email' && value && !App.Utils.Validation.email(value)) {
                message = 'Revisa el correo, parece incompleto.';
            } else if (el.id === 'phone-number' && value && (value.replace(/\D/g, '').length < 8 || !App.Utils.Validation.phone(value))) {
                message = 'Revisa el teléfono, debe tener al menos 8 dígitos.';
            }

            setFieldError($input, message);

            if (message && !firstInvalid) {
                firstInvalid = el;
            }
        });

        ['#accept-to-terms-and-conditions', '#accept-to-privacy-policy'].forEach((selector) => {
            const $check = $(selector);
            const missing = $check.length && !$check.prop('checked');

            $check.closest('.bk-check').toggleClass('is-invalid', Boolean(missing));

            if (missing && !firstInvalid) {
                firstInvalid = $check[0];
            }
        });

        const $captcha = $('#captcha-text');

        if ($captcha.length) {
            const missing = !String($captcha.val() || '').trim();

            setFieldError($captcha, missing ? 'Escribe el código de la imagen.' : '');

            if (missing && !firstInvalid) {
                firstInvalid = $captcha[0];
            }
        }

        if (firstInvalid) {
            firstInvalid.focus({preventScroll: true});
            firstInvalid.scrollIntoView({block: 'center', behavior: 'smooth'});
        }

        return !firstInvalid;
    }

    function customerTimezone() {
        const browser = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (browser && moment.tz.zone(browser)) {
            return browser;
        }

        const provider = providers.find((item) => item.id === Number(state.providerId));

        return provider?.timezone || vars('default_timezone') || 'America/Santiago';
    }

    function postData() {
        const item = booked();
        const start = moment(state.date + ' ' + state.time, 'YYYY-MM-DD HH:mm');
        const value = (id) => String($('#' + id).val() ?? '').trim();

        const data = {
            customer: {
                first_name: value('first-name'),
                last_name: value('last-name'),
                email: value('email'),
                phone_number: value('phone-number'),
                address: value('address'),
                city: value('city'),
                zip_code: value('zip-code'),
                timezone: customerTimezone(),
                custom_field_1: value('custom-field-1'),
                custom_field_2: value('custom-field-2'),
                custom_field_3: value('custom-field-3'),
                custom_field_4: value('custom-field-4'),
                custom_field_5: value('custom-field-5'),
            },
            appointment: {
                start_datetime: start.format('YYYY-MM-DD HH:mm:ss'),
                end_datetime: start.clone().add(item.duration, 'minutes').format('YYYY-MM-DD HH:mm:ss'),
                notes: value('notes'),
                is_unavailability: false,
                id_users_provider: state.providerId,
                id_services: state.serviceId,
                hair_length: state.hairLength || '',
            },
            manage_mode: Number(manageMode),
        };

        if (manageMode) {
            data.appointment.id = appointmentData.id;
            data.customer.id = vars('customer_data').id;
        }

        return data;
    }

    async function submit() {
        if (state.saving || !validateForm()) {
            return;
        }

        const data = postData();
        const $captcha = $('#captcha-text');

        state.saving = true;
        renderBar();

        try {
            const response = await App.Http.Booking.registerAppointment(
                data,
                $captcha.length ? $captcha.val() : undefined,
            );

            if (response.captcha_verification === false) {
                setFieldError($captcha, 'El código no coincide. Prueba con el nuevo.');
                refreshCaptcha();
                return;
            }

            if (!manageMode) {
                const remember = $('#bk-remember').prop('checked');
                const {first_name, last_name, phone_number, email} = data.customer;

                writeSavedCustomer(remember ? {first_name, last_name, phone_number, email} : null);
            }

            window.location.href = App.Utils.Url.siteUrl('booking_confirmation/of/' + response.appointment_hash);
        } catch (error) {
            showToast(error.message);

            // Someone else took the hour: show the fresh hours of that day.
            if (/disponible|available/i.test(error.message)) {
                delete state.hours[availabilityKey() + '|' + state.date];
                state.time = null;
                goTo('time');
            }

            refreshCaptcha();
        } finally {
            state.saving = false;
            renderBar();
        }
    }

    function refreshCaptcha() {
        $('#bk-captcha-img').attr('src', App.Utils.Url.siteUrl('captcha?' + Date.now()));
    }

    // Manage mode: cancel the appointment or delete the personal data ----------------------------------------

    function openCancelSheet(open) {
        $('#bk-backdrop, #bk-cancel-sheet').prop('hidden', !open);

        if (open) {
            $('#bk-cancel-reason').trigger('focus');
        }
    }

    function confirmCancel() {
        const $reason = $('#bk-cancel-reason');
        const reason = String($reason.val() || '').trim();

        setFieldError($reason, reason ? '' : 'Escribe el motivo para cancelar.');

        if (!reason) {
            return;
        }

        $('#hidden-cancellation-reason').val(reason);
        $('#cancel-appointment-form').trigger('submit');
    }

    async function deletePersonalData() {
        const $button = $('#bk-delete-data');

        if (!state.deleteArmed) {
            state.deleteArmed = true;
            $button.text('Toca otra vez para borrar tus datos');
            return;
        }

        try {
            await App.Http.Booking.deletePersonalInformation(vars('customer_token'));
            window.location.href = vars('base_url');
        } catch (error) {
            showToast(error.message);
        }
    }

    // Events ----------------------------------------------------------------------------------------------------

    function addEventListeners() {
        $back.on('click', goBack);

        window.addEventListener('popstate', (event) => {
            const step = event.state && event.state.bkStep;

            if (!step) {
                return;
            }

            state.depth = event.state.depth || 0;

            // Never show a step whose choices are missing (e.g. forward to "details" after changing the service).
            openLengthSheet(false);

            if (step !== 'service' && (!state.serviceId || !lengthReady())) {
                render('service');
            } else if (step === 'details' && !state.time) {
                render('time');
            } else {
                render(step);
            }
        });

        $search.on('input', () => {
            state.keyword = $search.val();
            renderServices();
        });

        $services.on('click', '[data-service]', (event) => {
            selectService(Number(event.currentTarget.dataset.service));
        });

        $services.on('click', 'a[href^="#bk-family-"]', (event) => {
            event.preventDefault();
            document.querySelector(event.currentTarget.getAttribute('href'))?.scrollIntoView({behavior: 'smooth'});
        });

        $pickedService.on('click', () => goBack());

        $lengthOptions.on('click', '[data-length]', (event) => selectLength(event.currentTarget.dataset.length));
        $lengthChips.on('click', '[data-length]', (event) => selectLength(event.currentTarget.dataset.length));
        $('#bk-length-close').on('click', () => openLengthSheet(false));

        $providerChips.on('click', '[data-provider]', (event) => {
            const value = event.currentTarget.dataset.provider;

            state.providerId = value === ANY_PROVIDER ? ANY_PROVIDER : Number(value);
            state.date = null;
            state.time = null;
            renderTimeStep();
            renderBar();
        });

        $days.on('click', '.bk-day:not(:disabled)', (event) => {
            selectDate(event.currentTarget.dataset.date);
        });

        $slots.on('click', '[data-time]', (event) => {
            state.time = event.currentTarget.dataset.time;
            $slots.find('[data-time]').attr('aria-checked', 'false');
            $(event.currentTarget).attr('aria-checked', 'true');
            renderBar();
        });

        $summary.on('click', '[data-goto]', () => goBack());

        $next.on('click', () => {
            if (state.step === 'time' && state.date && state.time) {
                goTo('details');
            } else if (state.step === 'details') {
                submit();
            }
        });

        $form.on('submit', (event) => {
            event.preventDefault();
            submit();
        });

        $form.on('input', 'input, textarea', (event) => {
            if ($(event.target).hasClass('is-invalid')) {
                setFieldError($(event.target), '');
            }
        });

        $('#bk-captcha-refresh').on('click', refreshCaptcha);
        $('#bk-cancel-open').on('click', () => openCancelSheet(true));
        $('#bk-cancel-close').on('click', () => openCancelSheet(false));
        $('#bk-backdrop').on('click', () => {
            openCancelSheet(false);
            openLengthSheet(false);
        });
        $('#bk-cancel-confirm').on('click', confirmCancel);
        $('#bk-delete-data').on('click', deletePersonalData);

        $(document).on('keydown', (event) => {
            if (event.key === 'Escape') {
                openCancelSheet(false);
                openLengthSheet(false);
            }
        });
    }

    function initializeCookieNotice() {
        if (!Boolean(Number(vars('display_cookie_notice'))) || !window.cookieconsent) {
            return;
        }

        cookieconsent.initialise({
            palette: {
                popup: {background: '#ffffff', text: '#3f4b59'},
                button: {background: '#015288', text: '#ffffff'},
            },
            content: {
                message: lang('website_using_cookies_to_ensure_best_experience'),
                dismiss: 'OK',
            },
        });
    }

    /**
     * Initialize the module.
     */
    function initialize() {
        initializeCookieNotice();
        addEventListeners();
        fillCustomer();

        let step = 'service';

        if (manageMode && appointmentData) {
            const start = moment(appointmentData.start_datetime);

            state.serviceId = Number(appointmentData.id_services);
            state.hairLength = appointmentData.hair_length || null;
            state.providerId = Number(appointmentData.id_users_provider);
            state.date = start.format('YYYY-MM-DD');
            state.time = start.format('HH:mm');
            step = service(state.serviceId) && lengthReady() ? 'time' : 'service';
        } else {
            // Links from blondor.cl open the chosen service directly (?service=62), optionally with a provider and,
            // for services priced by length, the length (?length=long).
            const fromLink = service(App.Utils.Url.queryParam('service'));

            if (fromLink) {
                const length = App.Utils.Url.queryParam('length');

                state.serviceId = fromLink.id;
                state.hairLength = fromLink.variants.some((variant) => variant.hair_length === length) ? length : null;
                state.providerId = defaultProviderId(fromLink.id);

                const providerParam = Number(App.Utils.Url.queryParam('provider'));

                if (providersFor(fromLink.id).some((provider) => provider.id === providerParam)) {
                    state.providerId = providerParam;
                }

                step = lengthReady() ? 'time' : 'service';
            }
        }

        history.replaceState({bkStep: step, depth: 0}, '');
        render(step);

        // A link to a service priced by length, without the length: ask it right away.
        if (step === 'service' && state.serviceId && !lengthReady()) {
            openLengthSheet(true);
        }
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {};
})();
