/* ----------------------------------------------------------------------------
 * Blondor - Landings de promociones (promo-balayage.html, promo-corte.html)
 *
 * Igual que el resto del sitio: guarda el origen de la visita (?ref=), lo lleva a la agenda y a WhatsApp y anota la
 * visita en el embudo. Precio, días y cupos se leen de la agenda (public_catalog) para que la página diga siempre lo
 * mismo que la reserva; si la agenda no responde, quedan los textos escritos en el HTML.
 * ---------------------------------------------------------------------------- */

(function () {
    // En local el sitio corre en :8087 y la agenda en :8088 del mismo equipo; en producción, agenda.blondor.cl.
    const isLocal = location.port === '8087';
    const AGENDA = isLocal ? `http://${location.hostname}:8088` : document.body.dataset.agenda;
    const SERVICE = Number(document.body.dataset.service);
    const REGULAR = Number(document.body.dataset.regular);
    // Precio normal escrito a mano (data-was) cuando la promo junta varios servicios: corte + masaje + peinado.
    const FIXED_WAS = Number(document.body.dataset.was) || 0;
    const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábados', 'domingos'];

    // Origen de la visita: mismas reglas que assets/js/site.js (cookie bl_ref de .blondor.cl por 7 días).
    const REF_PATTERN = /^[A-Z0-9][A-Z0-9:_-]{1,47}$/;

    function cleanRef(value) {
        const ref = String(value || '').trim().toUpperCase();

        return REF_PATTERN.test(ref) ? ref : null;
    }

    function cookieScope() {
        return (location.hostname.endsWith('blondor.cl') ? '; Domain=.blondor.cl' : '')
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    /**
     * Visitas que llegan desde un asistente de IA: mismas reglas que assets/js/site.js.
     */
    const AI_SOURCES = [
        {code: 'AI-CHATGPT', test: /(^|\.)(chatgpt\.com|chat\.openai\.com)$/},
        {code: 'AI-PERPLEXITY', test: /(^|\.)perplexity\.ai$/},
        {code: 'AI-GEMINI', test: /(^|\.)gemini\.google\.com$/},
        {code: 'AI-COPILOT', test: /(^|\.)copilot\.microsoft\.com$/},
        {code: 'AI-CLAUDE', test: /(^|\.)claude\.ai$/},
    ];

    function aiSource() {
        const hosts = [];

        try {
            hosts.push(String(new URLSearchParams(window.location.search).get('utm_source') || '').toLowerCase());
            hosts.push(document.referrer ? new URL(document.referrer).hostname.toLowerCase() : '');
        } catch (error) {
            // Enlace u origen raro: no se anota nada.
        }

        for (const host of hosts) {
            const source = AI_SOURCES.find((item) => item.test.test(host));

            if (source) {
                return source.code;
            }
        }

        return null;
    }

    function captureRef() {
        const fromUrl = cleanRef(new URLSearchParams(location.search).get('ref')) || aiSource();

        if (fromUrl) {
            document.cookie = 'bl_ref=' + encodeURIComponent(fromUrl) + '; Max-Age=604800; Path=/; SameSite=Lax' + cookieScope();

            return fromUrl;
        }

        const match = document.cookie.match(/(?:^|;\s*)bl_ref=([^;]*)/);

        try {
            return match ? cleanRef(decodeURIComponent(match[1])) : null;
        } catch (error) {
            return null;
        }
    }

    const REF = captureRef();

    function visitId() {
        const match = document.cookie.match(/(?:^|;\s*)bl_sid=([a-z0-9]{16,40})/);
        let id = match ? match[1] : '';

        if (!id) {
            const bytes = new Uint8Array(12);
            crypto.getRandomValues(bytes);
            id = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        }

        document.cookie = 'bl_sid=' + id + '; Max-Age=1800; Path=/; SameSite=Lax' + cookieScope();

        return id;
    }

    function track(step) {
        try {
            navigator.sendBeacon(AGENDA + '/index.php/funnel/track', JSON.stringify({session: visitId(), step, ref: REF}));
        } catch (error) {
            // Medir nunca debe romper la página.
        }
    }

    /**
     * Qué hace la visita en la página, para saber dónde se va (Booking_funnel::LANDING en la agenda). Cada cosa
     * cuenta una vez por visita.
     */
    function watchVisit() {
        const sent = new Set();
        const once = (step) => {
            if (!sent.has(step)) {
                sent.add(step);
                track(step);
            }
        };

        // Se quedó: 10 segundos con la página a la vista (no cuenta el tiempo en otra pestaña o con el teléfono bloqueado).
        let visible = 0;
        const clock = setInterval(() => {
            if (document.visibilityState === 'visible' && ++visible >= 10) {
                clearInterval(clock);
                once('lp_stay');
            }
        }, 1000);

        // Hasta dónde bajó: cada sección cuenta cuando pasa del borde de abajo y ocupa parte de la pantalla.
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            once(entry.target.dataset.pbSeen);
                            observer.unobserve(entry.target);
                        }
                    });
                },
                {rootMargin: '0px 0px -30% 0px'},
            );

            document.querySelectorAll('[data-pb-seen]').forEach((element) => observer.observe(element));
        }

        // Qué botón tocó. sendBeacon sale aunque el toque cambie de página.
        document.addEventListener(
            'click',
            (event) => {
                const link = event.target.closest
                    ? event.target.closest('[data-promo-book], [data-promo-wa], [data-promo-slot]')
                    : null;

                if (link) {
                    // Tocar una hora libre también es ir a reservar.
                    once(link.hasAttribute('data-promo-wa') ? 'lp_wa' : 'lp_book');
                }
            },
            true,
        );
    }

    function money(value) {
        return '$' + Math.round(value).toLocaleString('es-CL');
    }

    /**
     * The booking of the promo (or the whole catalog when the promo is not bookable any more), with the origin.
     */
    function linkButtons(serviceId) {
        const params = new URLSearchParams();

        if (serviceId) {
            params.set('service', serviceId);
        }

        if (REF) {
            params.set('ref', REF);
        }

        const query = params.toString();

        document.querySelectorAll('[data-promo-book]').forEach((link) => {
            link.href = AGENDA + '/index.php/booking' + (query ? '?' + query : '');
        });

        if (!REF) {
            return;
        }

        document.querySelectorAll('[data-promo-wa]').forEach((link) => {
            const url = new URL(link.href);

            if (!/\(ref: /.test(url.searchParams.get('text') || '')) {
                url.searchParams.set('text', (url.searchParams.get('text') || '') + ' (ref: ' + REF + ')');
                link.href = url.toString();
            }
        });
    }

    function daysText(weekdays) {
        const days = (weekdays || []).slice().sort((a, b) => a - b);

        if (!days.length || days.length === 7) {
            return '';
        }

        const consecutive = days.length > 2 && days.every((day, index) => !index || day === days[index - 1] + 1);

        return consecutive
            ? DAYS[days[0] - 1] + ' a ' + DAYS[days[days.length - 1] - 1]
            : days.map((day) => DAYS[day - 1]).join(', ').replace(/, ([^,]*)$/, ' y $1');
    }

    function setText(selector, text) {
        document.querySelectorAll(selector).forEach((element) => {
            element.textContent = text;
        });
    }

    /**
     * Price, days and slots as the agenda has them now.
     */
    function render(services) {
        // Una promo es la marcada en la agenda (con días y cupos) o un servicio cuyo nombre empieza con "Promo".
        const promo = services.find(
            (service) => service.id === SERVICE && (service.is_promo || /^promo/i.test(String(service.name).trim())),
        );

        if (!promo) {
            // The promo ended or is hidden: the buttons open the whole catalog instead of a service that is gone.
            linkButtons(null);

            return;
        }

        const regular = services.find((service) => service.id === REGULAR);
        const prices = regular ? regular.variants.map((variant) => Number(variant.price)).filter((price) => price > 0) : [];
        const was = FIXED_WAS || (prices.length ? Math.min(...prices) : regular ? Number(regular.price) : 0);
        const from = !FIXED_WAS && (prices.length > 1 || (regular && regular.price_type === 'from'));
        const days = daysText(promo.promo_weekdays);
        const quota = promo.promo_daily_quota;

        setText('#pb-now, [data-pb-now]', money(promo.price));

        document.querySelectorAll('#pb-was, [data-pb-was]').forEach((element) => {
            element.hidden = !(was > promo.price);
            element.textContent = (from ? 'desde ' : '') + money(was);
            element.setAttribute('aria-label', 'Precio normal ' + element.textContent);
        });

        setText('#pb-eyebrow', 'Promoción' + (days ? ' · ' + days : ''));
        setText(
            '#pb-lead',
            'Corto o extra largo, pagas lo mismo.'
                + (days ? ' Solo de ' + days : '')
                + (days && quota ? ', con ' + quota + ' cupos por día.' : days ? '.' : quota ? ' Con ' + quota + ' cupos por día.' : ''),
        );
        setText(
            '#pb-step-days',
            (days ? 'La promoción es de ' + days + '. ' : '') + 'En la agenda ves los días y horas libres.',
        );
        setText(
            '#pb-step-quota',
            quota ? 'Hay ' + quota + ' cupos por día. Cuando se llenan, ese día deja de aparecer.' : 'Eliges la hora y queda reservada al momento.',
        );
        setText(
            '#pb-fine',
            ['Precio único para todo largo', days, quota ? quota + ' cupos por día' : ''].filter(Boolean).join(' · '),
        );
    }

    /**
     * "Precio promo reservando hasta el …": cuántos días quedan, y se oculta pasada la fecha (data-until, YYYY-MM-DD).
     */
    function renderDeadline() {
        const until = document.body.dataset.until;
        const element = document.querySelector('[data-pb-deadline]');

        if (!until || !element) {
            return;
        }

        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const [year, month, day] = until.split('-').map(Number);
        const left = Math.round((new Date(year, month - 1, day) - start) / 86400000);

        if (left < 0) {
            element.hidden = true;
            return;
        }

        element.querySelector('[data-pb-left]').textContent =
            left === 0 ? ' · último día' : left === 1 ? ' · queda 1 día' : ' · quedan ' + left + ' días';
    }

    function localDate(offset) {
        const date = new Date();
        date.setDate(date.getDate() + offset);

        return [date.getFullYear(), date.getMonth() + 1, date.getDate()]
            .map((part, index) => (index ? String(part).padStart(2, '0') : part))
            .join('-');
    }

    function dayLabel(date) {
        if (date === localDate(0)) {
            return 'Hoy';
        }

        if (date === localDate(1)) {
            return 'Mañana';
        }

        const [year, month, day] = date.split('-').map(Number);
        const name = new Date(year, month - 1, day).toLocaleDateString('es-CL', {weekday: 'long'});

        return name.charAt(0).toUpperCase() + name.slice(1) + ' ' + day;
    }

    /**
     * Las próximas horas libres de la agenda; tocar una abre la reserva con ese día y esa hora ya elegidos.
     */
    function renderSlots(days) {
        const box = document.querySelector('[data-pb-slots]');

        if (!box || !Array.isArray(days) || !days.length) {
            return;
        }

        const list = box.querySelector('.pb-slots__list');

        days.forEach((day) => {
            day.hours.forEach((hour) => {
                const params = new URLSearchParams({service: SERVICE, date: day.date, time: hour});

                if (REF) {
                    params.set('ref', REF);
                }

                const link = document.createElement('a');
                link.className = 'pb-slot';
                link.dataset.promoSlot = '';
                link.href = AGENDA + '/index.php/booking?' + params.toString();

                const label = document.createElement('small');
                label.textContent = dayLabel(day.date);

                const time = document.createElement('strong');
                time.textContent = hour;

                link.append(label, time);
                link.setAttribute('aria-label', 'Reservar ' + label.textContent.toLowerCase() + ' a las ' + hour);
                list.append(link);
            });
        });

        box.hidden = false;
    }

    track('site');
    watchVisit();
    renderDeadline();
    linkButtons(SERVICE);

    if (document.querySelector('[data-pb-slots]')) {
        fetch(AGENDA + '/index.php/public_catalog/next_slots?service=' + SERVICE)
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('slots ' + response.status))))
            .then(renderSlots)
            .catch(() => {
                // Sin agenda quedan los botones de siempre.
            });
    }

    fetch(AGENDA + '/index.php/public_catalog')
        .then((response) => (response.ok ? response.json() : Promise.reject(new Error('catalog ' + response.status))))
        .then(render)
        .catch(() => {
            // Sin agenda quedan los textos del HTML.
        });
})();
