/* ----------------------------------------------------------------------------
 * Blondor - blondor.cl
 *
 * Los servicios y precios vienen de la agenda (public_catalog), así el sitio siempre muestra lo mismo que la
 * reserva. Cada servicio abre la reserva con ese servicio ya elegido (?service=ID).
 * ---------------------------------------------------------------------------- */

(function () {
    // En local el sitio corre en :8087 y la agenda en :8088; en producción, agenda.blondor.cl.
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    const AGENDA = isLocal ? 'http://localhost:8088' : document.body.dataset.agenda;
    const BOOK = AGENDA + '/index.php/booking';

    /**
     * Families shown on the site, in this order. Same matching rules as the agenda (/precios and the booking page).
     */
    const FAMILIES = [
        {key: 'color', label: 'Color y mechas', test: /color|balay|babyl|mechas|visos|contouring|canas|decolor/},
        {key: 'corte', label: 'Cortes', test: /^corte\b/},
        {key: 'tratamiento', label: 'Tratamientos', test: /botox|olaplex|k18|ampolla|total mask|hidratante|masaje/},
        {key: 'alisado', label: 'Alisado y peinados', test: /alisado|brushing|peinado|ondas|permanente/},
        {key: 'otros', label: 'Otros', test: /./},
    ];

    const PROMO = /^promo/;

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .trim();
    }

    function esc(text) {
        return String(text ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[c]);
    }

    /**
     * "MECHAS CON PAPEL" → "Mechas con papel", "color raiz" → "Color raiz".
     */
    function displayName(name) {
        const clean = String(name || '').trim().replace(/\s+/g, ' ');
        const lower = clean === clean.toUpperCase() ? clean.toLowerCase() : clean;

        return lower.charAt(0).toUpperCase() + lower.slice(1);
    }

    function money(value) {
        return '$' + Math.round(value).toLocaleString('es-CL');
    }

    function duration(minutes) {
        if (minutes < 60) {
            return minutes + ' min';
        }

        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;

        return hours + ' h' + (rest ? ' ' + rest + ' min' : '');
    }

    function bookUrl(serviceId) {
        return serviceId ? BOOK + '?service=' + serviceId : BOOK;
    }

    function linkBookingButtons() {
        document.querySelectorAll('[data-book]').forEach((link) => {
            link.href = bookUrl(link.dataset.book);
        });
    }

    function renderPromo(services) {
        const promo = services.filter((service) => PROMO.test(normalize(service.name)) && service.price > 0).sort((a, b) => a.price - b.price)[0];
        const section = document.getElementById('promo');

        if (!promo) {
            section.hidden = true;
            return;
        }

        // "Promo corte - masaje capilar - peinado" → "Corte, masaje capilar y peinado".
        const parts = displayName(promo.name.replace(/^promo(ci[oó]n)?\s*/i, ''))
            .split(/\s+-\s+/)
            .map((part, index) => (index ? part.toLowerCase() : part));
        const title = parts.length > 1 ? parts.slice(0, -1).join(', ') + ' y ' + parts[parts.length - 1] : parts[0];

        document.getElementById('promo-title').textContent = title;
        document.getElementById('promo-price').textContent = (promo.price_type === 'from' ? 'desde ' : '') + money(promo.price);
        document.getElementById('promo-fine').textContent = 'Dura ' + duration(promo.duration) + '.';

        const button = document.getElementById('promo-book');
        button.dataset.book = promo.id;
        button.href = bookUrl(promo.id);
    }

    function serviceRow(service) {
        const price = service.price > 0
            ? `<strong>${service.price_type === 'from' ? '<small>desde</small>' : ''}${esc(money(service.price))}</strong>`
            : '<span class="svc__ask">Precio a confirmar</span>';

        return `
            <li>
                <a class="svc" href="${esc(bookUrl(service.id))}">
                    <span class="svc__name">${esc(displayName(service.name))}</span>
                    <span class="svc__meta">${esc(duration(service.duration))}</span>
                    <span class="svc__price">${price}<span class="svc__go">Reservar ›</span></span>
                </a>
            </li>`;
    }

    function renderServices(services) {
        const tabs = document.getElementById('tabs');
        const panels = document.getElementById('panels');

        const groups = FAMILIES.map((family) => ({
            family,
            items: services
                .filter((service) => !PROMO.test(normalize(service.name)))
                .filter((service) => FAMILIES.find((candidate) => candidate.test.test(normalize(service.name))) === family)
                .sort((a, b) => displayName(a.name).localeCompare(displayName(b.name), 'es')),
        })).filter((group) => group.items.length);

        tabs.innerHTML = groups
            .map(
                (group, index) => `
                    <button class="tab" role="tab" id="tab-${group.family.key}" aria-controls="panel-${group.family.key}"
                            aria-selected="${index === 0}">${esc(group.family.label)}</button>`,
            )
            .join('');

        panels.innerHTML = groups
            .map(
                (group, index) => `
                    <div class="svc-panel" role="tabpanel" id="panel-${group.family.key}" aria-labelledby="tab-${group.family.key}" ${index ? 'hidden' : ''}>
                        <ul class="svc-list">${group.items.map(serviceRow).join('')}</ul>
                    </div>`,
            )
            .join('');

        tabs.addEventListener('click', (event) => {
            const tab = event.target.closest('.tab');

            if (!tab) {
                return;
            }

            tabs.querySelectorAll('.tab').forEach((item) => {
                const selected = item === tab;

                item.setAttribute('aria-selected', String(selected));
                document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
            });
        });
    }

    function renderFallback() {
        document.getElementById('panels').innerHTML = `
            <div class="svc-fallback">
                <p>No pudimos cargar la lista de precios. Puedes verlos al reservar.</p>
                <a class="btn btn--blue" href="${esc(BOOK)}">Ver servicios y reservar</a>
            </div>`;
    }

    async function loadCatalog() {
        try {
            const response = await fetch(AGENDA + '/index.php/public_catalog', {credentials: 'omit'});

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const services = await response.json();

            renderPromo(services);
            renderServices(services);
        } catch (error) {
            renderFallback();
        }
    }

    linkBookingButtons();
    loadCatalog();
})();
