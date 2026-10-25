/* ----------------------------------------------------------------------------
 * Blondor - Agenda móvil
 * ---------------------------------------------------------------------------- */

/**
 * Agenda page.
 *
 * Day view (appointments + free gaps), the 3-step booking flow (customer → service → time), the appointment sheet
 * with statuses and WhatsApp, the duplicate / overlap / existing-customer warnings, and the customer profile with
 * the technical record of each visit (fields by service group, photos).
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
    const $profile = $('#agenda-profile');
    const $profileBody = $('#profile-body');
    const $record = $('#agenda-record');
    const $recordBody = $('#record-body');
    const $recordFooter = $('#record-footer');

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
    const RECENT_DAYS_FOR_RECORD = 45;

    const DEVELOPER = ['10 vol', '20 vol', '30 vol', '40 vol'];

    /**
     * Campos de la ficha según el tipo de servicio. "match" decide qué grupos se abren solos según el nombre del
     * servicio de la cita (una promo puede abrir varios); igual se pueden sumar o quitar a mano.
     */
    const SERVICE_GROUPS = [
        {
            key: 'color',
            label: 'Color',
            match: /\bcolor\b|tinte/i,
            fields: [
                {key: 'brand', label: 'Marca', type: 'text', placeholder: 'Ej: Igora Royal'},
                {key: 'mix', label: 'Tonos y cantidad', type: 'mix'},
                {key: 'developer', label: 'Oxidante', type: 'pills', options: DEVELOPER},
                {key: 'time', label: 'Exposición', type: 'number', unit: 'min'},
                {key: 'zone', label: 'Zona', type: 'pills', options: ['Raíz', 'Medios y puntas', 'Completo']},
            ],
        },
        {
            key: 'mechas',
            label: 'Mechas / aclarado',
            match: /mecha|balay|babyl|visos|contour/i,
            fields: [
                {key: 'technique', label: 'Técnica', type: 'pills', options: ['Papel', 'Gorra', 'Balayage', 'Babylight']},
                {key: 'lightener', label: 'Aclarante y gramos', type: 'text', placeholder: 'Ej: Blondor polvo 40 g'},
                {key: 'developer', label: 'Oxidante', type: 'pills', options: DEVELOPER},
                {key: 'additive', label: 'Protector', type: 'pills', options: ['Ninguno', 'Olaplex', 'K18', 'Otro']},
                {key: 'time', label: 'Exposición', type: 'number', unit: 'min'},
                {key: 'level', label: 'Nivel alcanzado', type: 'text', placeholder: 'Ej: 9, amarillo pálido'},
                {key: 'toner', label: 'Matizador y tiempo', type: 'text', placeholder: 'Ej: Blondme ceniza 10 min'},
            ],
        },
        {
            key: 'alisado',
            label: 'Alisado / permanente',
            match: /alisado|^\s*permanente|ondas/i,
            fields: [
                {key: 'product', label: 'Producto y fuerza', type: 'text', placeholder: 'Ej: Inoar G-Hair, fuerza 2'},
                {key: 'rod', label: 'Bigudí / rulo', type: 'text', placeholder: 'Ej: rosado 8 mm'},
                {key: 'time', label: 'Tiempo de acción', type: 'number', unit: 'min'},
                {key: 'neutralizer', label: 'Neutralizante', type: 'text'},
                {key: 'iron', label: 'Temperatura de plancha', type: 'text', placeholder: 'Ej: 200 °C'},
                {key: 'zone', label: 'Zona', type: 'pills', options: ['Raíz', 'Medios y puntas', 'Completo', 'Chasquilla']},
            ],
        },
        {
            key: 'tratamiento',
            label: 'Tratamiento',
            match: /masaje (ampolla|k18|olaplex|total|hidrat|capilar)|masaje con ampolla|botox|ampolla/i,
            fields: [
                {key: 'product', label: 'Producto / ampolla', type: 'text', placeholder: 'Ej: K18 + ampolla Kérastase'},
                {key: 'condition', label: 'Estado del cabello', type: 'pills', options: ['Normal', 'Seco', 'Dañado', 'Poroso', 'Graso']},
                {key: 'time', label: 'Tiempo', type: 'number', unit: 'min'},
                {key: 'heat', label: 'Calor', type: 'pills', options: ['Sin calor', 'Vapor', 'Secador', 'Gorro térmico']},
                {key: 'frequency', label: 'Frecuencia recomendada', type: 'text', placeholder: 'Ej: cada 15 días'},
            ],
        },
        {
            key: 'corte',
            label: 'Corte',
            match: /corte/i,
            fields: [
                {key: 'length', label: 'Largo cortado', type: 'text', placeholder: 'Ej: 3 cm'},
                {key: 'technique', label: 'Técnica', type: 'pills', options: ['Recto', 'Capas', 'Desmechado', 'Bob', 'Pixie']},
                {key: 'tool', label: 'Herramienta', type: 'pills', options: ['Tijera', 'Navaja', 'Máquina']},
                {key: 'styling', label: 'Cómo peinarlo', type: 'textarea'},
            ],
        },
        {
            key: 'peinado',
            label: 'Peinado',
            match: /peinado|brushing/i,
            fields: [
                {key: 'style', label: 'Tipo de peinado', type: 'text', placeholder: 'Ej: ondas al agua, recogido bajo'},
                {key: 'products', label: 'Productos de fijación', type: 'text'},
            ],
        },
    ];

    const HAIR_FIELDS = [
        {key: 'type', label: 'Tipo', type: 'pills', options: ['Liso', 'Ondulado', 'Rizado', 'Afro']},
        {key: 'length', label: 'Largo', type: 'pills', options: ['Corto', 'Medio', 'Largo']},
        {key: 'thickness', label: 'Grosor', type: 'pills', options: ['Fino', 'Medio', 'Grueso']},
        {key: 'base', label: 'Base natural', type: 'text', placeholder: 'Ej: 6, rubio oscuro'},
        {key: 'grey', label: '% de canas', type: 'text', placeholder: 'Ej: 30%'},
    ];

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
        record: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7z"/><path d="M10 12h6M10 16h6"/></svg>',
        repeat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14-5.3L20 9M20 4v5h-5"/><path d="M20 12a8 8 0 0 1-14 5.3L4 15M4 20v-5h5"/></svg>',
        camera: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>',
        close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    };

    const state = {
        date: today(),
        providerId: initialProviderId(),
        day: null,
        highlightId: null,
    };

    let wizard = null;
    let profile = null;
    let rec = null;
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

    /**
     * Price of a service as the catalog shows it: "desde $95.500" when it depends on the hair.
     */
    function servicePrice(item) {
        const value = money(item.price);

        return value && item.price_type === 'from' ? 'desde ' + value : value;
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

    function isFullSheet($sheet) {
        return $sheet.hasClass('agenda-sheet--full');
    }

    function openSheet($sheet) {
        $sheet.prop('hidden', false);

        if (!isFullSheet($sheet)) {
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

        if (!topSheet()) {
            $('body').removeClass('agenda-locked');
        }

        if (!fromHistory && history.state && history.state.agendaSheet === $sheet.attr('id')) {
            skipPopstate++;
            history.back();
        }
    }

    function topSheet() {
        return [$alert, $detail, $record, $profile, $wizard].find(($sheet) => !$sheet.prop('hidden')) || null;
    }

    /**
     * Close a sheet and forget its state (the "back" button, the Android back gesture or a tap outside).
     */
    function dismissSheet($sheet, fromHistory = false) {
        if ($sheet.is($wizard)) {
            wizard = null;
        } else if ($sheet.is($profile)) {
            profile = null;
        } else if ($sheet.is($record)) {
            rec = null;
        }

        closeSheet($sheet, fromHistory);
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
            step: reschedule ? 3 : options.customer ? 2 : 1,
            customer: reschedule
                ? {id: reschedule.customer_id, name: reschedule.customer_name, phone_number: reschedule.phone_number}
                : options.customer || null,
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
                                            ${customer.alerts ? `<span class="wizard-option__alert">${ICONS.warn} ${esc(customer.alerts)}</span>` : ''}
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
                           <span class="wizard-favorite__row"><span>${esc(fav.name)}</span><span>${servicePrice(fav) || ''}</span></span>
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
                                servicePrice(item)
                                    ? `<span class="wizard-option__price">${servicePrice(item)}</span>`
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
                    <div class="wizard-summary__title">${servicePrice(svc) || ''}</div>
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
                    <h2 id="detail-name">
                        <button type="button" class="detail-name" id="detail-profile">${esc(item.customer_name)} ${ICONS.chevron}</button>
                    </h2>
                    <div class="detail-sub">${esc(item.service_name || 'Servicio')}${money(item.price) ? ' · ' + money(item.price) : ''}</div>
                    <div class="detail-when">${esc(dayLabel(date, true))} · ${hm(item.start)} a ${hm(item.end)}</div>
                </div>
                <span class="agenda-status" data-status="${esc(item.status)}">${esc(STATUS_LABELS[item.status] || item.status)}</span>
            </div>
            ${item.alerts ? `<div class="agenda-alertband" role="alert">${ICONS.warn}<span><strong>Alerta:</strong> ${esc(item.alerts)}</span></div>` : ''}
            ${
                !item.has_record && (item.status === 'Llegó' || item.status === 'Pagado')
                    ? `<button type="button" class="detail-complete" id="detail-record-cta">${ICONS.record} Completar ficha de esta visita</button>`
                    : ''
            }
            <div class="detail-actions">
                <a class="detail-action detail-action--wa" target="_blank" rel="noopener"
                   ${phone ? `href="https://wa.me/${phone}?text=${encodeURIComponent(whatsappText(item))}"` : 'aria-disabled="true"'}>
                    ${ICONS.whatsapp} WhatsApp
                </a>
                <a class="detail-action detail-action--call" ${telDigits ? `href="tel:${telDigits}"` : 'aria-disabled="true"'}>
                    ${ICONS.phone} Llamar
                </a>
                <button type="button" class="detail-action detail-action--record" id="detail-record">${ICONS.record} ${item.has_record ? 'Ver ficha' : 'Ficha'}</button>
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

    // Customer profile -----------------------------------------------------------------------------------------

    function shortMoney(value) {
        if (!(value > 0)) {
            return '—';
        }

        return value >= 10000 ? '$' + Math.round(value / 1000).toLocaleString('es-CL') + 'k' : money(value);
    }

    function ago(datetime) {
        if (!datetime) {
            return '—';
        }

        const days = Math.round((parseYmd(today()) - parseYmd(datetime.substring(0, 10))) / 86400000);

        if (days <= 0) {
            return 'hoy';
        }

        if (days < 14) {
            return days === 1 ? '1 día' : days + ' días';
        }

        if (days < 63) {
            return Math.round(days / 7) + ' sem';
        }

        const months = Math.round(days / 30);

        return months < 24 ? months + ' meses' : Math.round(days / 365) + ' años';
    }

    function shortDate(value) {
        const date = parseYmd(value.substring(0, 10));
        const year = date.getFullYear() !== new Date().getFullYear() ? ' ' + date.getFullYear() : '';

        return date.getDate() + ' ' + MONTHS[date.getMonth()] + year;
    }

    function openClients() {
        profile = {search: true, keyword: '', customerId: null, data: null};
        $('#profile-title').text('Clientas');
        openSheet($profile);
        renderClientSearch();
    }

    function openProfile(customerId) {
        profile = {search: false, customerId, data: null, tab: 'historial'};
        openSheet($profile);
        loadProfile();
    }

    function loadProfile() {
        const customerId = profile.customerId;

        $('#profile-title').text('Perfil');
        $profileBody.html('<div class="agenda-loading"></div><div class="agenda-loading"></div><div class="agenda-loading"></div>');
        $profile.scrollTop(0);

        return App.Http.Agenda.profile(customerId)
            .then((response) => {
                if (!profile || profile.customerId !== customerId) {
                    return;
                }

                profile.data = response;
                renderProfile();
            })
            .catch((error) => {
                $profileBody.html(
                    `<div class="agenda-error">No se pudo cargar el perfil: ${esc(error.message)}</div>
                     <button type="button" class="agenda-secondary" id="profile-retry">Reintentar</button>`,
                );
            });
    }

    function renderClientSearch() {
        $profileBody.html(`
            <div class="profile-search">
                <div class="wizard-search">
                    ${ICONS.search}
                    <input id="profile-search" type="search" autocomplete="off" inputmode="search"
                           aria-label="Buscar clienta" placeholder="Nombre o teléfono" value="${esc(profile.keyword)}">
                </div>
                <ul class="wizard-list" id="profile-customers"><li class="agenda-loading"></li></ul>
            </div>
        `);

        searchProfiles(profile.keyword);
        setTimeout(() => $('#profile-search').trigger('focus'), 50);
    }

    function searchProfiles(keyword) {
        const seq = ++searchSeq;

        App.Http.Agenda.customers(keyword)
            .then((customers) => {
                if (!profile || !profile.search || seq !== searchSeq) {
                    return;
                }

                $('#profile-customers').html(
                    customers.length
                        ? customers
                              .map((customer) => {
                                  const name = `${customer.first_name || ''} ${customer.last_name || ''}`.trim();

                                  return `
                                    <li>
                                        <button type="button" class="wizard-option" data-profile-id="${customer.id}">
                                            <span class="wizard-avatar" aria-hidden="true">${esc(initials(name))}</span>
                                            <span class="wizard-option__text">
                                                <span class="wizard-option__title">${esc(name)}</span>
                                                <span class="wizard-option__sub">${esc(maskPhone(customer.phone_number))} · ${customer.visits === 1 ? '1 visita' : customer.visits + ' visitas'}</span>
                                                ${customer.alerts ? `<span class="wizard-option__alert">${ICONS.warn} ${esc(customer.alerts)}</span>` : ''}
                                            </span>
                                            ${ICONS.chevron}
                                        </button>
                                    </li>`;
                              })
                              .join('')
                        : `<li class="wizard-hint">No hay clientas con «${esc(keyword)}».</li>`,
                );
            })
            .catch((error) => {
                if (seq === searchSeq) {
                    $('#profile-customers').html(`<li class="agenda-error">${esc(error.message)}</li>`);
                }
            });
    }

    function renderProfile() {
        const {customer, profile: fixed, stats, appointments, records} = profile.data;
        const name = `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
        const phone = waPhone(customer.phone_number);
        const telDigits = (customer.phone_number || '').replace(/[^\d+]/g, '');
        const tab = profile.tab;

        $('#profile-title').text(name);

        $profileBody.html(`
            <div class="profile-hero">
                <div class="profile-hero__who">
                    <span class="profile-avatar" aria-hidden="true">${esc(initials(name))}</span>
                    <div>
                        <h2>${esc(name)}</h2>
                        <span class="profile-hero__sub">${esc(customer.phone_number || 'sin teléfono')}${stats.since ? ' · desde ' + esc(shortDate(stats.since)) : ''}</span>
                    </div>
                </div>
                <div class="profile-hero__actions">
                    <a class="profile-action profile-action--wa" target="_blank" rel="noopener"
                       ${phone ? `href="https://wa.me/${phone}"` : 'aria-disabled="true"'}>${ICONS.whatsapp} WhatsApp</a>
                    <a class="profile-action" ${telDigits ? `href="tel:${telDigits}"` : 'aria-disabled="true"'}>${ICONS.phone} Llamar</a>
                    <button type="button" class="profile-action" id="profile-book">${ICONS.plus} Agendar</button>
                </div>
            </div>
            ${fixed.alerts ? `<div class="agenda-alertband" role="alert">${ICONS.warn}<span><strong>Alerta:</strong> ${esc(fixed.alerts)}</span></div>` : ''}
            <div class="profile-stats">
                <div><strong>${stats.visits}</strong><span>${stats.visits === 1 ? 'visita' : 'visitas'}</span></div>
                <div><strong>${esc(ago(stats.last_visit))}</strong><span>últ. visita</span></div>
                <div><strong>${esc(shortMoney(stats.spent))}</strong><span>gastado</span></div>
                <div><strong>${stats.no_shows}</strong><span>no vino</span></div>
            </div>
            <div class="profile-tabs" role="tablist" aria-label="Secciones">
                <button type="button" role="tab" data-profile-tab="historial" aria-selected="${tab === 'historial'}">Historial</button>
                <button type="button" role="tab" data-profile-tab="datos" aria-selected="${tab === 'datos'}">Datos y alertas</button>
            </div>
            <div class="profile-panel">
                ${tab === 'historial' ? renderHistory(appointments, records) : renderFixedData(fixed)}
            </div>
            ${tab === 'historial' ? `<button type="button" class="agenda-fab" id="profile-new-record">${ICONS.plus} Nueva ficha</button>` : ''}
        `);
    }

    function renderHistory(appointments, records) {
        const recordsByAppointment = {};

        records.forEach((record) => {
            if (record.appointment_id) {
                recordsByAppointment[record.appointment_id] = record;
            }
        });

        const upcoming = appointments
            .filter((item) => item.start.substring(0, 10) > today() && item.status !== 'Cancelado')
            .reverse()
            .slice(0, 3);

        const entries = [
            ...appointments
                .filter((item) => item.start.substring(0, 10) <= today() && item.status !== 'Cancelado')
                .map((item) => ({date: item.start, item, record: recordsByAppointment[item.id] || null})),
            ...records
                .filter((record) => !record.appointment_id)
                .map((record) => ({date: record.visit_date + ' 23:59', item: null, record})),
        ].sort((a, b) => (a.date < b.date ? 1 : -1));

        const recentLimit = addDays(today(), -RECENT_DAYS_FOR_RECORD);

        const html = [
            ...upcoming.map(
                (item) => `
                <div class="profile-next">
                    <span class="profile-next__label">PRÓXIMA</span>
                    <span class="profile-next__when">${esc(dayLabel(item.start.substring(0, 10), true))} · ${hm(item.start)} · ${esc(item.service_name || 'Cita')}</span>
                    ${item.provider_name ? `<span class="profile-next__who">con ${esc(item.provider_name)}</span>` : ''}
                </div>`,
            ),
            ...entries.map((entry) => {
                const item = entry.item;
                const record = entry.record;

                if (record) {
                    return recordCard(record, item);
                }

                if (item.status === 'No vino') {
                    return `
                        <div class="profile-visit profile-visit--muted">
                            <span>${esc(item.service_name || 'Cita')}</span>
                            <span>${esc(shortDate(item.start))} · No vino</span>
                        </div>`;
                }

                const recent = item.start.substring(0, 10) >= recentLimit;

                return `
                    <article class="profile-visit ${recent ? 'profile-visit--pending' : ''}">
                        <div class="profile-visit__top">
                            <h3>${esc(item.service_name || 'Cita')}</h3>
                            <span>${esc(shortDate(item.start))}</span>
                        </div>
                        <span class="profile-visit__sub">${esc([item.provider_name, money(item.price)].filter(Boolean).join(' · '))}</span>
                        ${
                            recent
                                ? `<button type="button" class="profile-complete" data-record-appointment="${item.id}">Sin ficha · Completar ahora</button>`
                                : `<button type="button" class="wizard-link profile-add-record" data-record-appointment="${item.id}">Agregar ficha</button>`
                        }
                    </article>`;
            }),
        ];

        return html.length ? html.join('') : '<p class="agenda-empty">Todavía no tiene visitas.</p>';
    }

    function groupOf(key) {
        return SERVICE_GROUPS.find((group) => group.key === key);
    }

    function fieldText(field, value) {
        if (!value) {
            return '';
        }

        if (field.type === 'mix') {
            return value
                .split('\n')
                .map((line) => {
                    const [tone, grams] = line.split('|');
                    return tone + (grams ? ` (${grams})` : '');
                })
                .join(' + ');
        }

        return field.unit ? `${value} ${field.unit}` : value;
    }

    function groupSummary(groupKey, values) {
        const group = groupOf(groupKey);

        if (!group || !values) {
            return '';
        }

        return group.fields
            .map((field) => fieldText(field, values[field.key]))
            .filter(Boolean)
            .join(' · ');
    }

    function recordCard(record, item) {
        const details = record.details || {};
        const groups = Object.keys(details).filter((key) => groupOf(key));
        const title = (item && item.service_name) || record.service_name || groups.map((key) => groupOf(key).label).join(' + ') || 'Ficha';
        const who = [(item && item.provider_name) || record.provider_name, item && money(item.price)].filter(Boolean);

        return `
            <article class="profile-visit">
                <div class="profile-visit__top">
                    <h3>${esc(title)}</h3>
                    <span>${esc(shortDate(record.visit_date))}</span>
                </div>
                ${who.length ? `<span class="profile-visit__sub">${esc(who.join(' · '))}</span>` : ''}
                ${groups
                    .map(
                        (key) => `
                    <div class="profile-formula">
                        <span class="profile-formula__label">${esc(groupOf(key).label.toUpperCase())}</span>
                        <span>${esc(groupSummary(key, details[key]))}</span>
                    </div>`,
                    )
                    .join('')}
                ${record.work ? `<p class="profile-visit__text">${esc(record.work)}</p>` : ''}
                ${record.result ? `<p class="profile-visit__text">${esc(record.result)}</p>` : ''}
                ${record.home_care ? `<p class="profile-visit__text"><strong>Para casa:</strong> ${esc(record.home_care)}</p>` : ''}
                ${renderPhotoStrip(record.photos, false)}
                <div class="profile-visit__actions">
                    <button type="button" class="agenda-secondary" data-record-id="${record.id}">Ver / editar ficha</button>
                </div>
            </article>`;
    }

    function renderPhotoStrip(photos, editable) {
        if (!photos || !photos.length) {
            return '';
        }

        return `
            <div class="record-photos">
                ${photos
                    .map(
                        (photo) => `
                    <div class="record-photo">
                        <a href="${App.Http.Agenda.photoUrl(photo.id)}" target="_blank" rel="noopener">
                            <img src="${App.Http.Agenda.photoUrl(photo.id)}" alt="Foto ${photo.kind === 'antes' ? 'antes' : 'después'}" loading="lazy">
                        </a>
                        <span class="record-photo__kind">${photo.kind === 'antes' ? 'Antes' : 'Después'}</span>
                        ${editable ? `<button type="button" class="record-photo__delete" data-delete-photo="${photo.id}" aria-label="Borrar foto">${ICONS.close}</button>` : ''}
                    </div>`,
                    )
                    .join('')}
            </div>`;
    }

    function renderField(field, value, scope) {
        const attrs = `data-scope="${esc(scope)}" data-field="${esc(field.key)}"`;
        const id = `f-${scope}-${field.key}`;

        if (field.type === 'pills') {
            return `
                <div class="wizard-field" role="group" aria-labelledby="${id}">
                    <span id="${id}">${esc(field.label)}</span>
                    <div class="record-pills">
                        ${field.options
                            .map(
                                (option) => `
                            <button type="button" class="record-pill" ${attrs} data-value="${esc(option)}"
                                    aria-pressed="${option === value}">${esc(option)}</button>`,
                            )
                            .join('')}
                    </div>
                </div>`;
        }

        if (field.type === 'mix') {
            const rows = (value || '').split('\n').filter(Boolean);

            if (!rows.length) {
                rows.push('');
            }

            return `
                <div class="wizard-field" role="group" aria-labelledby="${id}">
                    <span id="${id}">${esc(field.label)}</span>
                    <div class="record-mix" ${attrs}>
                        ${rows
                            .map((row) => {
                                const [tone, grams] = row.split('|');
                                return `
                                <div class="record-mix__row">
                                    <input aria-label="Tono" placeholder="Tono, ej: 7-1" value="${esc(tone || '')}" data-mix="tone">
                                    <input aria-label="Cantidad" placeholder="Cantidad, ej: 30 g" value="${esc(grams || '')}" data-mix="grams">
                                </div>`;
                            })
                            .join('')}
                    </div>
                    <button type="button" class="wizard-dashed" data-add-mix="${esc(scope)}">+ Agregar tono</button>
                </div>`;
        }

        if (field.type === 'textarea') {
            return `
                <label class="wizard-field">${esc(field.label)}
                    <textarea rows="2" ${attrs} placeholder="${esc(field.placeholder || '')}">${esc(value || '')}</textarea>
                </label>`;
        }

        return `
            <label class="wizard-field">${esc(field.label)}${field.unit ? ` (${esc(field.unit)})` : ''}
                <input ${attrs} value="${esc(value || '')}" placeholder="${esc(field.placeholder || '')}"
                       ${field.type === 'number' ? 'inputmode="numeric"' : ''} autocomplete="off">
            </label>`;
    }

    /**
     * Read every field of a form (data-scope / data-field) into {scope: {field: value}}.
     */
    function readFields($root) {
        const values = {};
        const put = (scope, field, value) => {
            values[scope] = values[scope] || {};
            values[scope][field] = value;
        };

        $root.find('input[data-field], textarea[data-field]').each((index, element) => {
            put(element.dataset.scope, element.dataset.field, element.value.trim());
        });

        $root.find('.record-pills').each((index, element) => {
            const $first = $(element).find('[data-field]').first();

            if (!$first.length) {
                return;
            }

            const $on = $(element).find('[aria-pressed="true"]');
            put($first.data('scope'), $first.data('field'), $on.length ? String($on.data('value')) : '');
        });

        $root.find('.record-mix').each((index, element) => {
            const lines = $(element)
                .find('.record-mix__row')
                .map((i, row) => {
                    const tone = $(row).find('[data-mix="tone"]').val().trim();
                    const grams = $(row).find('[data-mix="grams"]').val().trim();
                    return tone || grams ? `${tone}|${grams}` : null;
                })
                .get()
                .filter(Boolean);
            put(element.dataset.scope, element.dataset.field, lines.join('\n'));
        });

        return values;
    }

    function renderFixedData(fixed) {
        const hair = fixed.hair || {};

        return `
            <form class="wizard-form profile-form" id="profile-form" novalidate>
                <label class="wizard-field">Alertas
                    <textarea rows="2" data-scope="profile" data-field="alerts"
                              placeholder="Ej: alergia al amoníaco, cuero cabelludo sensible">${esc(fixed.alerts)}</textarea>
                </label>
                <p class="wizard-hint">Las alertas se muestran en rojo en cada cita y al agendar.</p>
                <h3 class="record-group__title">Cabello</h3>
                ${HAIR_FIELDS.map((field) => renderField(field, hair[field.key], 'hair')).join('')}
                <label class="wizard-field">Preferencias
                    <textarea rows="3" data-scope="profile" data-field="preferences"
                              placeholder="Ej: le gustan los tonos fríos, prefiere la mañana">${esc(fixed.preferences)}</textarea>
                </label>
                <button type="submit" class="agenda-primary" id="profile-save">Guardar datos</button>
            </form>`;
    }

    function saveFixedData() {
        const values = readFields($('#profile-form'));
        const data = {
            alerts: values.profile.alerts,
            preferences: values.profile.preferences,
            hair: values.hair || {},
        };
        const customerId = profile.customerId;

        $('#profile-save').prop('disabled', true).html('<span class="agenda-spinner" aria-hidden="true"></span> Guardando…');

        App.Http.Agenda.saveProfile(customerId, data)
            .then((response) => {
                if (profile && profile.customerId === customerId) {
                    profile.data.profile = response.profile;
                    renderProfile();
                }

                toast('Datos guardados');

                if (state.day) {
                    loadDay();
                }
            })
            .catch((error) => {
                $('#profile-save').prop('disabled', false).text('Guardar datos');
                toast('No se guardó: ' + error.message, {error: true});
            });
    }

    function bookFromProfile() {
        const customer = profile.data.customer;

        dismissSheet($profile);
        setTimeout(
            () =>
                openWizard({
                    customer: {
                        id: customer.id,
                        name: `${customer.first_name || ''} ${customer.last_name || ''}`.trim(),
                        first_name: customer.first_name,
                        phone_number: customer.phone_number,
                        favorite_service_id: customer.favorite_service_id,
                    },
                }),
            0,
        );
    }

    // Visit record -----------------------------------------------------------------------------------------------

    function groupsForService(serviceName) {
        const name = serviceName || '';
        return SERVICE_GROUPS.filter((group) => group.match.test(name)).map((group) => group.key);
    }

    /**
     * @param {Object} params {appointment_id} | {record_id} | {customer_id} (new record without appointment).
     */
    function openRecord(params) {
        rec = {params, data: null, saving: false, uploading: 0};
        openSheet($record);
        $recordFooter.prop('hidden', true).empty();
        $recordBody.html('<div class="agenda-loading"></div><div class="agenda-loading"></div>');

        App.Http.Agenda.record(params)
            .then((response) => {
                if (!rec || rec.params !== params) {
                    return;
                }

                const record = response.record;
                const details = (record && record.details) || {};
                const serviceName = (response.appointment && response.appointment.service_name) || '';

                rec.data = response;
                rec.recordId = record ? record.id : null;
                rec.photos = record ? record.photos : [];
                rec.groups = record && Object.keys(details).length ? Object.keys(details) : groupsForService(serviceName);
                rec.values = {...details, general: record || {}};
                rec.weeks = record && record.next_visit_weeks ? String(record.next_visit_weeks) : '';
                rec.visitDate = record ? record.visit_date : today();

                renderRecord();
            })
            .catch((error) => {
                $recordBody.html(`<div class="agenda-error">No se pudo abrir la ficha: ${esc(error.message)}</div>`);
            });
    }

    function collectRecord() {
        if (!rec || !rec.data) {
            return;
        }

        const values = readFields($recordBody);

        Object.keys(values).forEach((scope) => {
            rec.values[scope] = {...(rec.values[scope] || {}), ...values[scope]};
        });

        const $weeks = $recordBody.find('[data-weeks][aria-pressed="true"]');
        rec.weeks = $weeks.length ? String($weeks.data('weeks')) : '';

        const $date = $('#record-date');

        if ($date.length && $date.val()) {
            rec.visitDate = $date.val();
        }
    }

    function renderRecord() {
        const {appointment, customer, alerts, previous} = rec.data;
        const general = rec.values.general || {};
        const sub = appointment
            ? `${dayLabel(appointment.start.substring(0, 10), true)} · ${hm(appointment.start)}${provider(appointment.provider_id) ? ' · con ' + provider(appointment.provider_id).name : ''}`
            : null;

        $('#record-title').text(rec.recordId ? 'Ficha de la visita' : 'Nueva ficha');

        $recordBody.html(`
            <div class="record-head">
                <strong>${esc(customer.name)}${appointment && appointment.service_name ? ' · ' + esc(appointment.service_name) : ''}</strong>
                ${
                    sub
                        ? `<span>${esc(sub)}</span>`
                        : `<label class="wizard-field">Fecha de la visita
                               <input type="date" id="record-date" max="${today()}" value="${esc(rec.visitDate)}">
                           </label>`
                }
            </div>
            ${alerts ? `<div class="agenda-alertband" role="alert">${ICONS.warn}<span><strong>Alerta:</strong> ${esc(alerts)}</span></div>` : ''}
            <div class="wizard-field" role="group" aria-labelledby="record-groups-label">
                <span id="record-groups-label">¿Qué se hizo?</span>
                <div class="record-pills">
                    ${SERVICE_GROUPS.map(
                        (group) => `
                        <button type="button" class="record-pill" data-toggle-group="${group.key}"
                                aria-pressed="${rec.groups.includes(group.key)}">${esc(group.label)}</button>`,
                    ).join('')}
                </div>
            </div>
            ${SERVICE_GROUPS.filter((group) => rec.groups.includes(group.key))
                .map((group) => {
                    const last = previous[group.key];

                    return `
                    <section class="record-group" aria-labelledby="g-${group.key}">
                        <div class="record-group__head">
                            <h3 class="record-group__title" id="g-${group.key}">${esc(group.label)}</h3>
                            ${
                                last
                                    ? `<button type="button" class="record-repeat" data-repeat="${group.key}">${ICONS.repeat} Repetir ${esc(shortDate(last.date))}</button>`
                                    : ''
                            }
                        </div>
                        ${last ? `<p class="record-last">Última vez: ${esc(groupSummary(group.key, last.values))}</p>` : ''}
                        ${group.fields.map((field) => renderField(field, (rec.values[group.key] || {})[field.key], group.key)).join('')}
                    </section>`;
                })
                .join('')}
            <section class="record-group" aria-labelledby="g-general">
                <h3 class="record-group__title" id="g-general">Trabajo y resultado</h3>
                ${renderField({key: 'work', label: 'Qué se hizo', type: 'textarea', placeholder: 'Detalle del trabajo'}, general.work, 'general')}
                ${renderField({key: 'products', label: 'Productos usados', type: 'text', placeholder: 'Ej: Olaplex n°1, Blondme ceniza'}, general.products, 'general')}
                ${renderField({key: 'result', label: 'Resultado y observaciones', type: 'textarea', placeholder: 'Cómo quedó, qué cambiar la próxima vez'}, general.result, 'general')}
                ${renderField({key: 'home_care', label: 'Recomendación para casa', type: 'text', placeholder: 'Ej: shampoo matizador 1 vez por semana'}, general.home_care, 'general')}
                <div class="wizard-field" role="group" aria-labelledby="record-weeks-label">
                    <span id="record-weeks-label">Próxima visita sugerida</span>
                    <div class="record-pills">
                        ${['4', '6', '8', '12']
                            .map(
                                (weeks) => `
                            <button type="button" class="record-pill" data-weeks="${weeks}" aria-pressed="${weeks === rec.weeks}">${weeks} sem</button>`,
                            )
                            .join('')}
                    </div>
                </div>
            </section>
            <section class="record-group" aria-labelledby="g-photos">
                <h3 class="record-group__title" id="g-photos">Fotos</h3>
                ${renderPhotoStrip(rec.photos, true)}
                <div class="record-photo-add">
                    <label class="record-photo-btn">${ICONS.camera} Antes
                        <input type="file" accept="image/*" data-photo-kind="antes" hidden>
                    </label>
                    <label class="record-photo-btn">${ICONS.camera} Después
                        <input type="file" accept="image/*" data-photo-kind="despues" hidden>
                    </label>
                </div>
                ${rec.uploading ? '<p class="wizard-hint"><span class="agenda-spinner agenda-spinner--dark" aria-hidden="true"></span> Subiendo foto…</p>' : ''}
                <p class="wizard-hint">Las fotos solo se ven con sesión iniciada en la agenda.</p>
            </section>
        `);

        renderRecordFooter();
    }

    function renderRecordFooter() {
        $recordFooter.prop('hidden', false).html(`
            <button type="button" class="agenda-primary" id="record-save" ${rec.saving ? 'disabled' : ''}>
                ${rec.saving ? '<span class="agenda-spinner" aria-hidden="true"></span> Guardando…' : ICONS.check + ' Guardar ficha'}
            </button>`);
    }

    function recordPayload() {
        collectRecord();

        const details = {};

        rec.groups.forEach((key) => {
            details[key] = rec.values[key] || {};
        });

        const general = rec.values.general || {};
        const params = rec.data.appointment ? {appointment_id: rec.data.appointment.id} : {};

        return {
            id: rec.recordId || '',
            ...params,
            customer_id: rec.data.customer.id,
            visit_date: rec.visitDate,
            details,
            work: general.work || '',
            products: general.products || '',
            result: general.result || '',
            home_care: general.home_care || '',
            next_visit_weeks: rec.weeks,
        };
    }

    /**
     * Save the record (also before the first photo, which needs a record id).
     */
    function persistRecord() {
        return App.Http.Agenda.saveRecord(recordPayload()).then((response) => {
            if (rec) {
                rec.recordId = response.record.id;
                rec.photos = response.record.photos;
            }

            return response.record;
        });
    }

    function saveRecord() {
        if (!rec || rec.saving) {
            return;
        }

        rec.saving = true;
        renderRecordFooter();

        persistRecord()
            .then(() => {
                dismissSheet($record);
                toast('Ficha guardada');
                refreshAfterRecord();
            })
            .catch((error) => {
                if (rec) {
                    rec.saving = false;
                    renderRecordFooter();
                }

                toast('No se guardó la ficha: ' + error.message, {error: true});
            });
    }

    function refreshAfterRecord() {
        if (profile && profile.customerId) {
            loadProfile();
        }

        loadDay();
    }

    /**
     * Shrink a phone photo before uploading (max 1600 px, JPEG): a few hundred KB instead of several MB.
     */
    function compressImage(file) {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const image = new Image();

            image.onload = () => {
                const scale = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(image.naturalWidth * scale);
                canvas.height = Math.round(image.naturalHeight * scale);
                canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob((blob) => resolve(blob || file), 'image/jpeg', 0.82);
            };

            // Formats the browser cannot draw (e.g. HEIC on some phones) go as they are; the server checks them.
            image.onerror = () => {
                URL.revokeObjectURL(url);
                resolve(file);
            };

            image.src = url;
        });
    }

    function uploadPhoto(file, kind) {
        if (!rec || !file) {
            return;
        }

        const current = rec;

        collectRecord();
        current.uploading++;
        renderRecord();

        const ready = current.recordId ? Promise.resolve() : persistRecord();

        ready
            .then(() => compressImage(file))
            .then((blob) => App.Http.Agenda.uploadPhoto(current.recordId, kind, blob))
            .then((response) => {
                current.photos = response.record.photos;
            })
            .catch((error) => toast('No se subió la foto: ' + error.message, {error: true}))
            .finally(() => {
                current.uploading--;

                if (rec === current) {
                    collectRecord();
                    renderRecord();
                }
            });
    }

    function deletePhoto(photoId) {
        if (!rec || !window.confirm('¿Borrar esta foto?')) {
            return;
        }

        App.Http.Agenda.deletePhoto(photoId)
            .then((response) => {
                if (rec) {
                    collectRecord();
                    rec.photos = response.record.photos;
                    renderRecord();
                }
            })
            .catch((error) => toast('No se borró la foto: ' + error.message, {error: true}));
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

        // En escritorio el clic sobre el input no siempre abre el calendario; en el celular ya lo abre solo.
        $('#agenda-date-input').on('click', (event) => {
            try {
                event.target.showPicker && event.target.showPicker();
            } catch (error) {
                // El navegador ya mostró su selector o no lo permite.
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

        $('#agenda-clients').on('click', openClients);

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

        $detail.on('click', '#detail-profile', () => {
            const item = $detail.data('appointment');
            closeSheet($detail);
            setTimeout(() => openProfile(item.customer_id), 0);
        });

        $detail.on('click', '#detail-record, #detail-record-cta', () => {
            const item = $detail.data('appointment');
            closeSheet($detail);
            setTimeout(() => openRecord({appointment_id: item.id}), 0);
        });

        // Profile.
        $('[data-close-sheet]').on('click', (event) => dismissSheet($(event.currentTarget).closest('.agenda-sheet')));

        $profileBody.on('input', '#profile-search', (event) => {
            profile.keyword = event.target.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchProfiles(profile.keyword.trim()), 250);
        });

        $profileBody.on('click', '[data-profile-id]', (event) => {
            profile = {search: false, customerId: Number($(event.currentTarget).data('profile-id')), data: null, tab: 'historial'};
            loadProfile();
        });

        $profileBody.on('click', '#profile-retry', loadProfile);

        $profileBody.on('click', '[data-profile-tab]', (event) => {
            profile.tab = $(event.currentTarget).data('profile-tab');
            renderProfile();
        });

        $profileBody.on('click', '#profile-book', bookFromProfile);

        $profileBody.on('submit', '#profile-form', (event) => {
            event.preventDefault();
            saveFixedData();
        });

        $profileBody.on('click', '[data-record-appointment]', (event) => {
            openRecord({appointment_id: Number($(event.currentTarget).data('record-appointment'))});
        });

        $profileBody.on('click', '[data-record-id]', (event) => {
            openRecord({record_id: Number($(event.currentTarget).data('record-id'))});
        });

        $profileBody.on('click', '#profile-new-record', () => {
            // La cita de hoy sin ficha es casi siempre la que se quiere registrar.
            const pending = profile.data.appointments.find(
                (item) => item.start.substring(0, 10) === today() && !item.has_record && item.status !== 'Cancelado',
            );

            openRecord(pending ? {appointment_id: pending.id} : {customer_id: profile.customerId});
        });

        // Pills of the profile and record forms (single choice: tap again to clear).
        $page.on('click', '.record-pill[data-field], .record-pill[data-weeks]', (event) => {
            const $pill = $(event.currentTarget);
            const on = $pill.attr('aria-pressed') === 'true';
            $pill.siblings().attr('aria-pressed', 'false');
            $pill.attr('aria-pressed', on ? 'false' : 'true');
        });

        $page.on('click', '[data-add-mix]', (event) => {
            const $mix = $(event.currentTarget).siblings('.record-mix');
            const $row = $mix.find('.record-mix__row').first().clone();
            $row.find('input').val('');
            $mix.append($row);
            $row.find('input').first().trigger('focus');
        });

        // Record.
        $recordBody.on('click', '[data-toggle-group]', (event) => {
            const key = $(event.currentTarget).data('toggle-group');
            collectRecord();
            rec.groups = rec.groups.includes(key) ? rec.groups.filter((item) => item !== key) : [...rec.groups, key];
            renderRecord();
        });

        $recordBody.on('click', '[data-repeat]', (event) => {
            const key = $(event.currentTarget).data('repeat');
            collectRecord();
            rec.values[key] = {...rec.data.previous[key].values};
            renderRecord();
            toast('Copiada la ficha del ' + shortDate(rec.data.previous[key].date));
        });

        $recordBody.on('change', '[data-photo-kind]', (event) => {
            const input = event.target;
            const file = input.files && input.files[0];
            input.value = '';
            uploadPhoto(file, input.dataset.photoKind);
        });

        $recordBody.on('click', '[data-delete-photo]', (event) => {
            deletePhoto(Number($(event.currentTarget).data('delete-photo')));
        });

        $recordFooter.on('click', '#record-save', saveRecord);

        $detail.on('click', '#detail-reschedule', () => {
            const item = $detail.data('appointment');
            closeSheet($detail);
            setTimeout(() => openWizard({reschedule: item}), 0);
        });

        $backdrop.on('click', () => {
            const $top = topSheet();

            if ($top && !isFullSheet($top)) {
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

            if ($top) {
                dismissSheet($top, true);
            }
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
        openProfile,
        openRecord,
    };
})();
