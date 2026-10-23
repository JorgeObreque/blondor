/* ----------------------------------------------------------------------------
 * Blondor - Agenda móvil
 * ---------------------------------------------------------------------------- */

/**
 * Agenda page.
 *
 * Day view (appointments + free gaps), the 3-step booking flow (customer → service → time), the appointment sheet
 * with statuses and WhatsApp, and the duplicate / overlap / existing-customer warnings.
 */
App.Pages.Agenda = (function () {
    const $page = $('#agenda-page');
    const $timeline = $('#agenda-timeline');
    const $summary = $('#agenda-summary');
    const $days = $('#agenda-days');
    const $providers = $('#agenda-providers');
    const $wizard = $('#agenda-wizard');
    const $wizardBody = $('#wizard-body');
    const $wizardFooter = $('#wizard-footer');
    const $wizardSteps = $('#wizard-steps');
    const $detail = $('#agenda-detail');
    const $alert = $('#agenda-alert');
    const $backdrop = $('#agenda-backdrop');
    const $toast = $('#agenda-toast');

    const providers = vars('agenda_providers') || [];
    const services = vars('agenda_services') || [];
    const categories = vars('agenda_categories') || [];
    const companyName = vars('company_name') || 'Blondor';

    const STATUS_LABELS = {
        'Agendado': 'Por confirmar',
        'Confirmado': 'Confirmó',
        'Llegó': 'Llegó',
        'No vino': 'No vino',
        'Pagado': 'Pagó',
        'Reagendado': 'Reagendada',
        'Cancelado': 'Cancelada',
    };
    const STATUS_BUTTONS = ['Agendado', 'Confirmado', 'Llegó', 'No vino', 'Pagado', 'Cancelado'];
    const DOW = ['do', 'lu', 'ma', 'mi', 'ju', 'vi', 'sá'];
    const DOW_LONG = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const MIN_FREE_MINUTES = 15;

    const ICONS = {
        plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
        search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
        check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>',
        warn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>',
        whatsapp:
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/><path d="M9 9.5c.5 2 2 3.5 4.5 4.5l1-1.2 2 1-.5 1.7c-3.5.3-7.2-3.4-7-7l1.7-.5 1 2z"/></svg>',
        phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>',
        clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/></svg>',
    };

    const state = {
        date: today(),
        providerId: initialProviderId(),
        day: null,
        highlightId: null,
    };

    let wizard = null;
    let skipPopstate = 0;
    let searchTimer = null;
    let searchSeq = 0;
    let toastTimer = null;

    // Helpers ---------------------------------------------------------------------------------------------------

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function ymd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function today() {
        return ymd(new Date());
    }

    function parseYmd(value) {
        const [y, m, d] = value.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    function addDays(value, days) {
        const date = parseYmd(value);
        date.setDate(date.getDate() + days);
        return ymd(date);
    }

    function hm(datetime) {
        return datetime.substring(11, 16);
    }

    function minutes(time) {
        const [h, m] = time.split(':').map(Number);
        return h * 60 + m;
    }

    function fromMinutes(total) {
        return pad(Math.floor(total / 60)) + ':' + pad(total % 60);
    }

    function duration(total) {
        if (total < 60) {
            return total + ' min';
        }

        const h = Math.floor(total / 60);
        const m = total % 60;

        return h + ' h' + (m ? ' ' + m + ' min' : '');
    }

    function money(value) {
        return value > 0 ? '$' + Math.round(value).toLocaleString('es-CL') : null;
    }

    function dayLabel(value, long = false) {
        const date = parseYmd(value);
        const diff = Math.round((date - parseYmd(today())) / 86400000);
        const base = DOW_LONG[date.getDay()] + ' ' + date.getDate();

        if (diff === 0) {
            return long ? 'Hoy, ' + base : 'Hoy';
        }

        if (diff === 1) {
            return long ? 'Mañana, ' + base : 'Mañana';
        }

        return base.charAt(0).toUpperCase() + base.slice(1) + (long ? ' de ' + MONTHS[date.getMonth()] : '');
    }

    function initials(name) {
        return (name || '?')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('');
    }

    function shortName(first, last) {
        return [first, last ? last.trim().charAt(0) + '.' : ''].filter(Boolean).join(' ');
    }

    function waPhone(phone) {
        let digits = (phone || '').replace(/\D/g, '');

        if (digits.length === 9 && digits.startsWith('9')) {
            digits = '56' + digits;
        } else if (digits.length === 8) {
            digits = '569' + digits;
        }

        return digits.length >= 10 ? digits : null;
    }

    function maskPhone(phone) {
        const digits = (phone || '').replace(/\D/g, '');
        return digits ? '•••• ' + digits.slice(-4) : 'sin teléfono';
    }

    function service(id) {
        return services.find((item) => item.id === Number(id));
    }

    function provider(id) {
        return providers.find((item) => item.id === Number(id));
    }

    function newRequestId() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    function storage(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }

            window.localStorage.setItem(key, value);
        } catch (error) {
            return null;
        }
    }

    function initialProviderId() {
        const userId = Number(vars('user_id'));
        const own = provider(userId);

        if (own) {
            return own.id;
        }

        const saved = provider(storage('agenda.provider'));

        return saved ? saved.id : providers[0] ? providers[0].id : null;
    }

    // Sheets & history ------------------------------------------------------------------------------------------

    function openSheet($sheet) {
        $sheet.prop('hidden', false);

        if (!$sheet.is($wizard)) {
            $backdrop.prop('hidden', false);
        }

        $('body').addClass('agenda-locked');

        if (!history.state || history.state.agendaSheet !== $sheet.attr('id')) {
            history.pushState({agendaSheet: $sheet.attr('id')}, '');
        }
    }

    function closeSheet($sheet, fromHistory = false) {
        if ($sheet.prop('hidden')) {
            return;
        }

        $sheet.prop('hidden', true);

        if ($detail.prop('hidden') && $alert.prop('hidden')) {
            $backdrop.prop('hidden', true);
        }

        if ($wizard.prop('hidden') && $detail.prop('hidden') && $alert.prop('hidden')) {
            $('body').removeClass('agenda-locked');
        }

        if (!fromHistory && history.state && history.state.agendaSheet === $sheet.attr('id')) {
            skipPopstate++;
            history.back();
        }
    }

    function topSheet() {
        if (!$alert.prop('hidden')) {
            return $alert;
        }

        if (!$detail.prop('hidden')) {
            return $detail;
        }

        if (!$wizard.prop('hidden')) {
            return $wizard;
        }

        return null;
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

    // Day view --------------------------------------------------------------------------------------------------

    function renderHeader() {
        $('#agenda-title').text(dayLabel(state.date, true));
        $('#agenda-date-input').val(state.date);

        const selected = parseYmd(state.date);
        const monday = new Date(selected);
        monday.setDate(selected.getDate() - ((selected.getDay() + 6) % 7));

        let html = '';

        for (let i = 0; i < 7; i++) {
            const date = new Date(monday);
            date.setDate(monday.getDate() + i);
            const value = ymd(date);

            html += `
                <button type="button" class="agenda-day ${value === today() ? 'agenda-day--today' : ''}"
                        data-date="${value}" ${value === state.date ? 'aria-current="date"' : ''}
                        aria-label="${esc(dayLabel(value, true))}">
                    <span class="agenda-day__dow">${DOW[date.getDay()]}</span>
                    <span class="agenda-day__num">${date.getDate()}</span>
                </button>`;
        }

        $days.html(html);

        if (providers.length > 1) {
            $providers.prop('hidden', false).html(
                providers
                    .map(
                        (item) => `
                    <button type="button" role="tab" class="agenda-chip-toggle" data-provider-id="${item.id}"
                            aria-selected="${item.id === state.providerId}">${esc(item.name)}</button>`,
                    )
                    .join(''),
            );
        }
    }

    function loadDay() {
        renderHeader();

        $summary.empty();
        $timeline.html('<div class="agenda-loading"></div><div class="agenda-loading"></div><div class="agenda-loading"></div>');

        if (!state.providerId) {
            $timeline.html('<p class="agenda-empty">No hay profesionales disponibles para tu usuario.</p>');
            return Promise.resolve();
        }

        const requested = state.date + '|' + state.providerId;

        return App.Http.Agenda.day(state.date, state.providerId)
            .then((response) => {
                if (requested !== state.date + '|' + state.providerId) {
                    return;
                }

                state.day = response;
                renderDay();
            })
            .catch((error) => {
                $timeline.html(
                    `<div class="agenda-error">No se pudo cargar la agenda: ${esc(error.message)}</div>
                     <button type="button" class="agenda-secondary" id="agenda-retry">Reintentar</button>`,
                );
            });
    }

    function renderDay() {
        const appointments = state.day.appointments;
        const active = appointments.filter((item) => item.status !== 'Cancelado');
        const pending = active.filter((item) => item.status === 'Agendado' || item.status === 'Reagendado');
        const isToday = state.date === today();
        const isPast = state.date < today();
        const nowMinutes = minutes(new Date().toTimeString().substring(0, 5));

        // Same customer at the same time twice: flag both so they get noticed.
        const seen = {};
        const duplicates = new Set();

        active.forEach((item) => {
            const key = item.customer_id + '|' + item.start;

            if (seen[key]) {
                duplicates.add(item.id);
                duplicates.add(seen[key]);
            }

            seen[key] = item.id;
        });

        const free = isPast
            ? []
            : state.day.free_periods
                  .map((period) => {
                      let start = minutes(period.start);
                      const end = minutes(period.end);

                      if (isToday) {
                          start = Math.max(start, Math.ceil(nowMinutes / 15) * 15);
                      }

                      return {start, end};
                  })
                  .filter((period) => period.end - period.start >= MIN_FREE_MINUTES);

        const freeMinutes = free.reduce((total, period) => total + period.end - period.start, 0);

        $summary.html(
            [
                `<span class="agenda-pill">${active.length} ${active.length === 1 ? 'cita' : 'citas'}</span>`,
                pending.length ? `<span class="agenda-pill">${pending.length} por confirmar</span>` : '',
                duplicates.size
                    ? `<span class="agenda-pill" style="color: var(--ag-warn)">${duplicates.size} repetidas</span>`
                    : '',
                !isPast ? `<span class="agenda-pill agenda-pill--brand">${freeMinutes ? duration(freeMinutes) + ' libres' : 'Sin horas libres'}</span>` : '',
            ].join(''),
        );

        const items = [
            ...appointments.map((item) => ({type: 'appointment', at: minutes(hm(item.start)), item})),
            ...free.map((period) => ({type: 'free', at: period.start, period})),
        ].sort((a, b) => a.at - b.at || (a.type === 'free' ? 1 : -1));

        if (!items.length) {
            $timeline.html(
                `<p class="agenda-empty">${isPast ? 'Sin citas este día.' : 'Este día no hay horario de atención.'}</p>`,
            );
            return;
        }

        $timeline.html(
            items
                .map((entry) => {
                    if (entry.type === 'free') {
                        const length = entry.period.end - entry.period.start;
                        return `
                            <div class="agenda-row">
                                <span class="agenda-row__time">${fromMinutes(entry.period.start)}</span>
                                <button type="button" class="agenda-free" data-free-start="${fromMinutes(entry.period.start)}">
                                    ${ICONS.plus} Libre · ${duration(length)}
                                </button>
                            </div>`;
                    }

                    const item = entry.item;
                    const svc = item.service_name || 'Servicio';
                    const classes = ['agenda-card'];

                    if (item.status === 'Cancelado') {
                        classes.push('agenda-card--cancelled');
                    }

                    if (duplicates.has(item.id)) {
                        classes.push('agenda-card--dup');
                    }

                    return `
                        <div class="agenda-row" data-row-id="${item.id}">
                            <span class="agenda-row__time">${hm(item.start)}</span>
                            <button type="button" class="${classes.join(' ')}" data-appointment-id="${item.id}">
                                <span class="agenda-card__top">
                                    <span class="agenda-card__name">${esc(item.customer_name)}</span>
                                    <span class="agenda-status" data-status="${esc(item.status)}">${esc(STATUS_LABELS[item.status] || item.status)}</span>
                                </span>
                                <span class="agenda-card__meta">${esc(svc)} · hasta ${hm(item.end)}</span>
                                ${duplicates.has(item.id) ? '<span class="agenda-card__warn">Cita repetida: revisa y cancela una</span>' : ''}
                            </button>
                        </div>`;
                })
                .join(''),
        );

        if (state.highlightId) {
            const $row = $timeline.find(`[data-row-id="${state.highlightId}"]`);

            if ($row.length) {
                $row[0].scrollIntoView({block: 'center', behavior: 'smooth'});
                $row.find('.agenda-card').css('box-shadow', '0 0 0 3px #12508a');
                setTimeout(() => $row.find('.agenda-card').css('box-shadow', ''), 2500);
            }

            state.highlightId = null;
        }
    }

    function goTo(date) {
        state.date = date;
        loadDay();
    }

    // Wizard ----------------------------------------------------------------------------------------------------

    function openWizard(options = {}) {
        const reschedule = options.reschedule || null;

        wizard = {
            step: reschedule ? 3 : 1,
            customer: reschedule
                ? {id: reschedule.customer_id, name: reschedule.customer_name, phone_number: reschedule.phone_number}
                : null,
            newCustomer: null,
            creating: false,
            serviceId: reschedule ? reschedule.service_id : null,
            categoryId: null,
            date: reschedule ? reschedule.start.substring(0, 10) : options.date || state.date,
            hour: options.hour || null,
            notes: reschedule ? reschedule.notes || '' : '',
            reschedule,
            hours: null,
            requestId: null,
            requestKey: null,
            force: {},
            saving: false,
            error: null,
        };

        if (wizard.date < today()) {
            wizard.date = today();
        }

        $('#wizard-title').text(reschedule ? 'Reagendar cita' : 'Nueva cita');
        openSheet($wizard);
        renderWizard();
    }

    function closeWizard() {
        wizard = null;
        closeSheet($wizard);
    }

    function renderSteps() {
        const customerLabel = wizard.customer ? wizard.customer.name : 'Clienta';
        const svc = service(wizard.serviceId);
        const labels = [
            wizard.step > 1 ? customerLabel : '1 Clienta',
            wizard.step > 2 && svc ? svc.name : '2 Servicio',
            '3 Hora',
        ];

        $wizardSteps.html(
            labels
                .map((label, index) => {
                    const step = index + 1;
                    const css = step < wizard.step ? 'is-done' : step === wizard.step ? 'is-current' : '';

                    return `<li class="${css}" ${step === wizard.step ? 'aria-current="step"' : ''}><span>${esc(label)}</span></li>`;
                })
                .join(''),
        );
    }

    function renderWizard() {
        renderSteps();
        $wizardFooter.prop('hidden', true).empty();

        if (wizard.step === 1) {
            wizard.creating ? renderNewCustomer() : renderCustomerStep();
        } else if (wizard.step === 2) {
            renderServiceStep();
        } else {
            renderTimeStep();
        }

        $wizard.scrollTop(0);
    }

    // Step 1: customer.

    function renderCustomerStep() {
        $wizardBody.html(`
            <label for="wizard-customer-search"><h2>¿Para quién es la cita?</h2></label>
            <div class="wizard-search">
                ${ICONS.search}
                <input id="wizard-customer-search" type="search" autocomplete="off" inputmode="search"
                       placeholder="Nombre o teléfono" value="${esc(wizard.keyword || '')}">
            </div>
            <p class="wizard-hint">Busca por nombre o por los últimos dígitos del teléfono.</p>
            <ul class="wizard-list" id="wizard-customers"><li class="agenda-loading"></li></ul>
            <button type="button" class="agenda-fab wizard-fab" id="wizard-new-customer">${ICONS.plus}<span>Clienta nueva</span></button>
        `);

        searchCustomers(wizard.keyword || '');
        updateNewCustomerLabel();

        setTimeout(() => $('#wizard-customer-search').trigger('focus'), 50);
    }

    function updateNewCustomerLabel() {
        const keyword = (wizard.keyword || '').trim();
        $('#wizard-new-customer span').text(keyword ? `Crear «${keyword}»` : 'Clienta nueva');
    }

    function searchCustomers(keyword) {
        const seq = ++searchSeq;

        App.Http.Agenda.customers(keyword)
            .then((customers) => {
                if (!wizard || seq !== searchSeq) {
                    return;
                }

                if (!customers.length) {
                    $('#wizard-customers').html(
                        `<li class="wizard-hint">No hay clientas con «${esc(keyword)}». Créala con el botón «Crear».</li>`,
                    );
                    return;
                }

                $('#wizard-customers').html(
                    customers
                        .map((customer) => {
                            const name = `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
                            const fav = service(customer.favorite_service_id);
                            const visits = customer.visits === 1 ? '1 visita' : `${customer.visits} visitas`;

                            return `
                                <li>
                                    <button type="button" class="wizard-option" data-customer='${esc(JSON.stringify(customer))}'>
                                        <span class="wizard-avatar" aria-hidden="true">${esc(initials(name))}</span>
                                        <span class="wizard-option__text">
                                            <span class="wizard-option__title">${esc(name)}</span>
                                            <span class="wizard-option__sub">${esc(maskPhone(customer.phone_number))} · ${visits}</span>
                                            ${fav ? `<span class="wizard-option__fav">Siempre: ${esc(fav.name)}</span>` : ''}
                                        </span>
                                        ${ICONS.chevron}
                                    </button>
                                </li>`;
                        })
                        .join(''),
                );
            })
            .catch((error) => {
                if (seq === searchSeq) {
                    $('#wizard-customers').html(`<li class="agenda-error">${esc(error.message)}</li>`);
                }
            });
    }

    function pickCustomer(customer) {
        const name = `${customer.first_name || ''} ${customer.last_name || ''}`.trim();

        wizard.customer = {
            id: customer.id,
            name,
            first_name: customer.first_name,
            phone_number: customer.phone_number,
            favorite_service_id: customer.favorite_service_id,
        };
        wizard.newCustomer = null;
        wizard.creating = false;
        wizard.force = {};
        wizard.step = 2;
        renderWizard();
    }

    function renderNewCustomer() {
        const keyword = (wizard.keyword || '').trim();
        const isPhone = /^[\d\s+]+$/.test(keyword) && keyword.replace(/\D/g, '').length >= 3;
        const draft = wizard.newCustomer || {
            first_name: isPhone ? '' : keyword.split(/\s+/)[0] || '',
            last_name: isPhone ? '' : keyword.split(/\s+/).slice(1).join(' '),
            phone_number: isPhone ? keyword : '',
            email: '',
        };

        $wizardBody.html(`
            <h2>Clienta nueva</h2>
            <form class="wizard-form" id="wizard-customer-form" novalidate>
                <label class="wizard-field">Nombre *
                    <input name="first_name" autocomplete="off" required value="${esc(draft.first_name)}">
                </label>
                <label class="wizard-field">Apellido
                    <input name="last_name" autocomplete="off" value="${esc(draft.last_name)}">
                </label>
                <label class="wizard-field">Teléfono *
                    <input name="phone_number" type="tel" inputmode="tel" autocomplete="off" required
                           placeholder="+56 9 1234 5678" value="${esc(draft.phone_number)}">
                </label>
                <label class="wizard-field">Email (opcional)
                    <input name="email" type="email" inputmode="email" autocomplete="off" value="${esc(draft.email)}">
                </label>
                <div class="agenda-error" id="wizard-customer-error" hidden></div>
                <button type="submit" class="agenda-primary">Continuar</button>
            </form>
        `);

        setTimeout(() => $('#wizard-customer-form input').filter((i, el) => !el.value).first().trigger('focus'), 50);
    }

    function submitNewCustomer(form) {
        const data = Object.fromEntries(new FormData(form).entries());
        Object.keys(data).forEach((key) => (data[key] = data[key].trim()));

        let error = null;

        if (!data.first_name) {
            error = 'Falta el nombre.';
        } else if (data.phone_number.replace(/\D/g, '').length < 8) {
            error = 'El teléfono debe tener al menos 8 dígitos.';
        } else if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
            error = 'El email no es válido.';
        }

        if (error) {
            $('#wizard-customer-error').text(error).prop('hidden', false);
            return;
        }

        wizard.newCustomer = data;
        wizard.customer = {
            id: null,
            name: `${data.first_name} ${data.last_name}`.trim(),
            first_name: data.first_name,
            phone_number: data.phone_number,
        };
        wizard.creating = false;
        wizard.force = {};
        wizard.step = 2;
        renderWizard();
    }

    // Step 2: service.

    function renderServiceStep() {
        const prov = provider(state.providerId);
        const allowed = services.filter((item) => !prov || prov.services.includes(item.id));
        const usedCategories = categories.filter((category) => allowed.some((item) => item.category_id === category.id));
        const fav = service(wizard.customer && wizard.customer.favorite_service_id);

        if (!wizard.categoryId || !usedCategories.some((category) => category.id === wizard.categoryId)) {
            wizard.categoryId = (fav && fav.category_id) || (usedCategories[0] && usedCategories[0].id) || null;
        }

        const list = allowed.filter((item) => item.category_id === wizard.categoryId || !usedCategories.length);
        const firstName = wizard.customer ? wizard.customer.first_name || wizard.customer.name.split(' ')[0] : '';

        $wizardBody.html(`
            <h2>¿Qué se va a hacer?</h2>
            ${
                fav && allowed.includes(fav)
                    ? `<button type="button" class="wizard-favorite" data-service-id="${fav.id}">
                           <span class="wizard-favorite__label">LO DE SIEMPRE DE ${esc(firstName.toUpperCase())}</span>
                           <span class="wizard-favorite__row"><span>${esc(fav.name)}</span><span>${money(fav.price) || ''}</span></span>
                           <span style="font-size: 13px; opacity: .9">${duration(fav.duration)}</span>
                       </button>`
                    : ''
            }
            <div class="wizard-tabs" role="tablist" aria-label="Categorías">
                ${usedCategories
                    .map(
                        (category) => `
                    <button type="button" role="tab" class="wizard-tab" data-category-id="${category.id}"
                            aria-selected="${category.id === wizard.categoryId}">${esc(category.name)}</button>`,
                    )
                    .join('')}
            </div>
            <ul class="wizard-list">
                ${list
                    .map(
                        (item) => `
                    <li>
                        <button type="button" class="wizard-option wizard-option--line" data-service-id="${item.id}">
                            <span class="wizard-option__text">
                                <span class="wizard-option__title">${esc(item.name)}</span>
                                <span class="wizard-option__sub">${duration(item.duration)}</span>
                            </span>
                            ${
                                money(item.price)
                                    ? `<span class="wizard-option__price">${money(item.price)}</span>`
                                    : '<span class="wizard-option__price--none">Sin precio</span>'
                            }
                        </button>
                    </li>`,
                    )
                    .join('')}
            </ul>
        `);
    }

    // Step 3: time.

    function renderTimeStep() {
        const svc = service(wizard.serviceId);
        const dates = [0, 1, 2, 3].map((offset) => addDays(today(), offset));

        if (!dates.includes(wizard.date)) {
            dates[3] = wizard.date;
        }

        $wizardBody.html(`
            <h2>¿Cuándo?</h2>
            <div class="wizard-dates">
                ${dates
                    .map((value) => {
                        const date = parseYmd(value);
                        const top = value === today() ? 'Hoy' : DOW[date.getDay()];
                        return `<button type="button" class="wizard-date" data-date="${value}" aria-pressed="${value === wizard.date}">
                                    <small>${top}</small>${date.getDate()}
                                </button>`;
                    })
                    .join('')}
                <label class="wizard-date" aria-label="Elegir otra fecha" style="position: relative">
                    <small>otro</small>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
                    <input type="date" id="wizard-other-date" min="${today()}" value="${wizard.date}"
                           style="position:absolute; inset:0; opacity:0; width:100%; height:100%">
                </label>
            </div>
            <p class="wizard-hint">${esc(dayLabel(wizard.date, true))} · solo horas donde caben los ${duration(svc.duration)} de ${esc(svc.name.toLowerCase())}.</p>
            <div id="wizard-hours"><div class="agenda-loading"></div></div>
            <button type="button" class="wizard-link" id="wizard-manual-time">Otra hora (sobrecupo)…</button>
            <div id="wizard-manual" hidden>
                <label class="wizard-field">Hora de inicio
                    <input type="time" id="wizard-manual-input" step="300">
                </label>
            </div>
            <label class="wizard-field">Nota para la cita (opcional)
                <textarea id="wizard-notes" rows="2" placeholder="Ej: trae foto del color">${esc(wizard.notes)}</textarea>
            </label>
        `);

        loadHours();
        renderFooter();
    }

    function loadHours() {
        const key = wizard.date + '|' + wizard.serviceId;
        wizard.hoursKey = key;

        App.Http.Agenda.availableHours(
            wizard.date,
            wizard.serviceId,
            state.providerId,
            wizard.reschedule ? wizard.reschedule.id : null,
        )
            .then((response) => {
                if (!wizard || wizard.hoursKey !== key) {
                    return;
                }

                wizard.hours = response.hours;
                renderHours();
            })
            .catch((error) => {
                $('#wizard-hours').html(`<div class="agenda-error">${esc(error.message)}</div>`);
            });
    }

    function renderHours() {
        const hours = wizard.hours || [];

        // A free-gap start where this service does not fit has to be picked again.
        if (wizard.hour && !hours.includes(wizard.hour)) {
            wizard.hour = null;
        }

        if (!hours.length) {
            $('#wizard-hours').html('<p class="agenda-empty" style="padding: 12px 0">No quedan horas libres este día. Prueba otro día o usa «Otra hora».</p>');
            renderFooter();
            return;
        }

        const groups = [
            ['Mañana', hours.filter((hour) => hour < '13:00')],
            ['Tarde', hours.filter((hour) => hour >= '13:00')],
        ].filter((group) => group[1].length);

        $('#wizard-hours').html(
            groups
                .map(
                    ([title, list]) => `
                <p class="wizard-slots-title">${title}</p>
                <div class="wizard-slots">
                    ${list
                        .map(
                            (hour) =>
                                `<button type="button" class="wizard-slot" data-hour="${hour}" aria-pressed="${hour === wizard.hour}">${hour}</button>`,
                        )
                        .join('')}
                </div>`,
                )
                .join(''),
        );

        renderFooter();
    }

    function renderFooter() {
        const svc = service(wizard.serviceId);

        if (!wizard.hour || !svc) {
            $wizardFooter.prop('hidden', true).empty();
            return;
        }

        const end = fromMinutes(minutes(wizard.hour) + svc.duration);
        const prov = provider(state.providerId);
        const label = wizard.saving
            ? '<span class="agenda-spinner" aria-hidden="true"></span> Guardando…'
            : wizard.error
              ? 'Reintentar'
              : `${ICONS.check} ${wizard.reschedule ? 'Confirmar cambio' : 'Confirmar cita'}`;

        $wizardFooter
            .prop('hidden', false)
            .html(
                `<div class="wizard-summary">
                    <div>
                        <div class="wizard-summary__title">${esc(wizard.customer.name)} · ${esc(svc.name)}</div>
                        <div class="wizard-summary__sub">${esc(dayLabel(wizard.date, true))} · ${wizard.hour} a ${end}${prov && providers.length > 1 ? ' · con ' + esc(prov.name) : ''}</div>
                    </div>
                    <div class="wizard-summary__title">${money(svc.price) || ''}</div>
                </div>
                ${wizard.error ? `<div class="agenda-error" role="alert">No se guardó: ${esc(wizard.error)}</div>` : ''}
                <button type="button" class="agenda-primary" id="wizard-confirm" ${wizard.saving ? 'disabled' : ''}>${label}</button>`,
            );
    }

    function confirmWizard() {
        if (!wizard || wizard.saving) {
            return;
        }

        wizard.notes = ($('#wizard-notes').val() || '').trim();

        const appointment = {
            id: wizard.reschedule ? wizard.reschedule.id : '',
            provider_id: state.providerId,
            service_id: wizard.serviceId,
            start: wizard.date + ' ' + wizard.hour,
            notes: wizard.notes,
        };
        const customer = wizard.customer.id ? {id: wizard.customer.id} : wizard.newCustomer;
        const key = JSON.stringify([appointment, customer, wizard.force]);

        // Same data as the last attempt (retry after a network error): reuse the request id so the server
        // never creates it twice.
        if (key !== wizard.requestKey) {
            wizard.requestKey = key;
            wizard.requestId = newRequestId();
        }

        wizard.saving = true;
        wizard.error = null;
        renderFooter();

        App.Http.Agenda.save(appointment, customer, wizard.requestId, wizard.force)
            .then((response) => {
                if (!wizard) {
                    return;
                }

                wizard.saving = false;

                if (response.success) {
                    saved(response.appointment, !wizard.reschedule);
                    return;
                }

                renderFooter();
                showWarning(response);
            })
            .catch((error) => {
                if (!wizard) {
                    return;
                }

                wizard.saving = false;
                wizard.error = error.message;
                renderFooter();
            });
    }

    function saved(appointment, isNew) {
        closeWizard();

        state.highlightId = appointment.id;
        state.date = appointment.start.substring(0, 10);

        loadDay().then(() => openDetail(appointment));

        toast(isNew ? 'Cita guardada' : 'Cita reagendada', {
            action: isNew
                ? {
                      label: 'Deshacer',
                      run: () =>
                          App.Http.Agenda.undo(appointment.id)
                              .then(() => {
                                  closeSheet($detail);
                                  toast('Cita eliminada');
                                  loadDay();
                              })
                              .catch((error) => toast(error.message, {error: true})),
                  }
                : null,
        });
    }

    // Warnings --------------------------------------------------------------------------------------------------

    function showWarning(response) {
        let html = '';

        if (response.reason === 'duplicate') {
            const item = response.appointment;
            html = `
                <h2 id="alert-title">${esc(wizard.customer.name.split(' ')[0])} ya tiene esta cita</h2>
                <div class="alert-box">
                    <strong>${esc(item.service_name || 'Cita')} · ${esc(dayLabel(item.start.substring(0, 10)))} ${hm(item.start)}</strong>
                    <span class="wizard-option__sub">${esc(STATUS_LABELS[item.status] || item.status)}</span>
                </div>
                <p class="alert-text">Para no duplicarla, no se guardó otra igual. Si de verdad es una segunda cita, puedes agendarla igual.</p>
                <button type="button" class="agenda-primary" data-alert="view">Ver la cita existente</button>
                <button type="button" class="agenda-secondary" data-alert="force" data-force="duplicate">Agendar otra igual de todas formas</button>`;
        } else if (response.reason === 'overlap') {
            const item = response.appointment;
            html = `
                <h2 id="alert-title">Ese horario ya está ocupado</h2>
                <div class="alert-box">
                    <strong>${esc(item.customer_name)} · ${hm(item.start)} a ${hm(item.end)}</strong>
                    <span class="wizard-option__sub">${esc(item.service_name || '')}</span>
                </div>
                <p class="alert-text">Puedes elegir otra hora o agendarla igual como sobrecupo.</p>
                <button type="button" class="agenda-primary" data-alert="close">Elegir otra hora</button>
                <button type="button" class="agenda-secondary" data-alert="force" data-force="overlap">Agendar igual (sobrecupo)</button>`;
        } else if (response.reason === 'customer_exists') {
            const customer = response.customer;
            const name = `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
            html = `
                <h2 id="alert-title">Ese teléfono ya es de una clienta</h2>
                <div class="alert-box">
                    <strong>${esc(name)}</strong>
                    <span class="wizard-option__sub">${esc(customer.phone_number || '')} · ${customer.visits} visitas</span>
                </div>
                <p class="alert-text">¿Es ella? Usar su ficha evita tener a la misma clienta dos veces.</p>
                <button type="button" class="agenda-primary" data-alert="use-customer" data-customer='${esc(JSON.stringify(customer))}'>Sí, usar a ${esc(customer.first_name || name)}</button>
                <button type="button" class="agenda-secondary" data-alert="force" data-force="customer">No, es otra persona</button>`;
        } else {
            wizard.error = 'Respuesta inesperada del servidor.';
            renderFooter();
            return;
        }

        $alert
            .data('appointment', response.appointment || null)
            .html(`<span class="agenda-sheet__grab" aria-hidden="true"></span><span class="alert-icon" aria-hidden="true">${ICONS.warn}</span>${html}`);
        openSheet($alert);
    }

    function handleAlert(action, $button) {
        closeSheet($alert);

        if (!wizard) {
            return;
        }

        if (action === 'force') {
            wizard.force[$button.data('force')] = 1;
            confirmWizard();
        } else if (action === 'use-customer') {
            const customer = $button.data('customer');
            const svc = wizard.serviceId;
            pickCustomer(customer);
            wizard.serviceId = svc;
            wizard.step = 3;
            renderWizard();
        } else if (action === 'view') {
            const item = $alert.data('appointment');
            closeWizard();
            state.date = item.start.substring(0, 10);
            state.highlightId = item.id;
            loadDay().then(() => openDetail(item));
        }
    }

    // Appointment sheet -----------------------------------------------------------------------------------------

    function openDetailById(id) {
        const local = state.day && state.day.appointments.find((item) => item.id === id);

        if (local) {
            openDetail(local);
        }
    }

    function whatsappText(item) {
        const first = (item.customer_name || '').split(' ')[0];
        const date = item.start.substring(0, 10);
        const when = date === today() ? 'hoy' : date === addDays(today(), 1) ? 'mañana' : 'el ' + dayLabel(date, true).toLowerCase();

        return `Hola ${first}, te esperamos ${when} a las ${hm(item.start)} en ${companyName} para tu ${(item.service_name || 'cita').toLowerCase()}. ¿Nos confirmas?`;
    }

    function openDetail(item) {
        const phone = waPhone(item.phone_number);
        const telDigits = (item.phone_number || '').replace(/[^\d+]/g, '');
        const date = item.start.substring(0, 10);

        $detail.data('appointment', item).html(`
            <span class="agenda-sheet__grab" aria-hidden="true"></span>
            <div class="detail-top">
                <div>
                    <h2 id="detail-name">${esc(item.customer_name)}</h2>
                    <div class="detail-sub">${esc(item.service_name || 'Servicio')}${money(item.price) ? ' · ' + money(item.price) : ''}</div>
                    <div class="detail-when">${esc(dayLabel(date, true))} · ${hm(item.start)} a ${hm(item.end)}</div>
                </div>
                <span class="agenda-status" data-status="${esc(item.status)}">${esc(STATUS_LABELS[item.status] || item.status)}</span>
            </div>
            <div class="detail-actions">
                <a class="detail-action detail-action--wa" target="_blank" rel="noopener"
                   ${phone ? `href="https://wa.me/${phone}?text=${encodeURIComponent(whatsappText(item))}"` : 'aria-disabled="true"'}>
                    ${ICONS.whatsapp} WhatsApp
                </a>
                <a class="detail-action detail-action--call" ${telDigits ? `href="tel:${telDigits}"` : 'aria-disabled="true"'}>
                    ${ICONS.phone} Llamar
                </a>
                <button type="button" class="detail-action" id="detail-reschedule">${ICONS.clock} Reagendar</button>
            </div>
            <div>
                <p class="wizard-slots-title" style="margin-bottom: 8px">Estado</p>
                <div class="detail-statuses">
                    ${STATUS_BUTTONS.map(
                        (status) => `
                        <button type="button" class="detail-status" data-status="${esc(status)}" aria-pressed="${status === item.status}">
                            ${esc(STATUS_LABELS[status])}
                        </button>`,
                    ).join('')}
                </div>
            </div>
            ${item.notes ? `<p class="detail-notes">${esc(item.notes)}</p>` : ''}
            <div class="detail-footer">
                <button type="button" class="is-plain" id="detail-close">Cerrar</button>
                ${phone ? '' : '<span class="wizard-hint">Sin teléfono válido para WhatsApp</span>'}
            </div>
        `);

        openSheet($detail);
    }

    function setStatus(status, $button) {
        const item = $detail.data('appointment');

        if (!item || item.status === status) {
            return;
        }

        $detail.find('.detail-status').prop('disabled', true);
        $button.html('<span class="agenda-spinner" style="border-color: rgba(18,80,138,.3); border-top-color: #12508a; display:inline-block"></span>');

        App.Http.Agenda.setStatus(item.id, status)
            .then((response) => {
                openDetail(response.appointment);
                toast('Estado: ' + STATUS_LABELS[status]);
                loadDay();
            })
            .catch((error) => {
                openDetail(item);
                toast('No se cambió el estado: ' + error.message, {error: true});
            });
    }

    // Events ----------------------------------------------------------------------------------------------------

    function bindEvents() {
        $days.on('click', '.agenda-day', (event) => goTo($(event.currentTarget).data('date')));

        $providers.on('click', '[data-provider-id]', (event) => {
            state.providerId = Number($(event.currentTarget).data('provider-id'));
            storage('agenda.provider', String(state.providerId));
            loadDay();
        });

        $('#agenda-today').on('click', () => goTo(today()));

        $('#agenda-pick-date').on('click', () => {
            const input = document.getElementById('agenda-date-input');

            if (input.showPicker) {
                input.showPicker();
            } else {
                input.focus();
                input.click();
            }
        });

        $('#agenda-date-input').on('change', (event) => event.target.value && goTo(event.target.value));

        $timeline.on('click', '#agenda-retry', loadDay);

        $timeline.on('click', '[data-appointment-id]', (event) => {
            openDetailById(Number($(event.currentTarget).data('appointment-id')));
        });

        $timeline.on('click', '[data-free-start]', (event) => {
            openWizard({date: state.date, hour: $(event.currentTarget).data('free-start')});
        });

        $('#agenda-add').on('click', () => openWizard());

        // Wizard.
        $('#wizard-close').on('click', closeWizard);

        $('#wizard-back').on('click', () => {
            if (!wizard) {
                return;
            }

            if (wizard.step === 1 && wizard.creating) {
                wizard.creating = false;
                renderWizard();
            } else if (wizard.step === 1 || (wizard.reschedule && wizard.step === 3)) {
                closeWizard();
            } else {
                wizard.step -= 1;
                wizard.error = null;
                renderWizard();
            }
        });

        $wizardBody.on('input', '#wizard-customer-search', (event) => {
            wizard.keyword = event.target.value;
            updateNewCustomerLabel();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchCustomers(wizard.keyword.trim()), 250);
        });

        $wizardBody.on('click', '[data-customer]', (event) => pickCustomer($(event.currentTarget).data('customer')));

        $wizardBody.on('click', '#wizard-new-customer', () => {
            wizard.creating = true;
            wizard.newCustomer = null;
            renderWizard();
        });

        $wizardBody.on('submit', '#wizard-customer-form', (event) => {
            event.preventDefault();
            submitNewCustomer(event.target);
        });

        $wizardBody.on('click', '[data-category-id]', (event) => {
            wizard.categoryId = Number($(event.currentTarget).data('category-id'));
            renderServiceStep();
        });

        $wizardBody.on('click', '[data-service-id]', (event) => {
            wizard.serviceId = Number($(event.currentTarget).data('service-id'));
            wizard.force = {};
            wizard.step = 3;
            renderWizard();
        });

        $wizardBody.on('click', '[data-date]', (event) => {
            wizard.date = $(event.currentTarget).data('date');
            wizard.hour = null;
            wizard.force = {};
            wizard.error = null;
            renderTimeStep();
        });

        $wizardBody.on('change', '#wizard-other-date', (event) => {
            if (event.target.value) {
                wizard.date = event.target.value;
                wizard.hour = null;
                wizard.force = {};
                renderTimeStep();
            }
        });

        $wizardBody.on('click', '[data-hour]', (event) => {
            wizard.hour = $(event.currentTarget).data('hour');
            wizard.force = {};
            wizard.error = null;
            $wizardBody.find('[data-hour]').attr('aria-pressed', 'false');
            $(event.currentTarget).attr('aria-pressed', 'true');
            $('#wizard-manual').prop('hidden', true);
            renderFooter();
        });

        $wizardBody.on('click', '#wizard-manual-time', () => {
            $('#wizard-manual').prop('hidden', false);
            $('#wizard-manual-input').trigger('focus');
        });

        $wizardBody.on('change', '#wizard-manual-input', (event) => {
            if (event.target.value) {
                wizard.hour = event.target.value.substring(0, 5);
                wizard.force = {};
                $wizardBody.find('[data-hour]').attr('aria-pressed', 'false');
                renderFooter();
            }
        });

        $wizardFooter.on('click', '#wizard-confirm', confirmWizard);

        // Alerts and sheet.
        $alert.on('click', '[data-alert]', (event) => {
            const $button = $(event.currentTarget);
            const action = $button.data('alert');

            if (action === 'close') {
                closeSheet($alert);
                return;
            }

            handleAlert(action, $button);
        });

        $detail.on('click', '#detail-close', () => closeSheet($detail));

        $detail.on('click', '.detail-status', (event) => {
            const $button = $(event.currentTarget);
            setStatus(String($button.data('status')), $button);
        });

        $detail.on('click', '#detail-reschedule', () => {
            const item = $detail.data('appointment');
            closeSheet($detail);
            setTimeout(() => openWizard({reschedule: item}), 0);
        });

        $backdrop.on('click', () => {
            const $top = topSheet();

            if ($top && !$top.is($wizard)) {
                closeSheet($top);
            }
        });

        // Android "back" closes the open sheet instead of leaving the page.
        window.addEventListener('popstate', () => {
            if (skipPopstate > 0) {
                skipPopstate--;
                return;
            }

            const $top = topSheet();

            if (!$top) {
                return;
            }

            if ($top.is($wizard)) {
                wizard = null;
            }

            closeSheet($top, true);
        });

        // Coming back to the tab after a while: refresh so the agenda is never stale.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !topSheet()) {
                loadDay();
            }
        });
    }

    function initialize() {
        if (!$page.length) {
            return;
        }

        bindEvents();
        loadDay();
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {
        loadDay,
        openWizard,
    };
})();
