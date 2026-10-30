/* ----------------------------------------------------------------------------
 * Blondor - blondor.cl
 *
 * Los servicios y precios vienen de la agenda (public_catalog), así el sitio siempre muestra lo mismo que la
 * reserva. Cada servicio abre la reserva con ese servicio ya elegido (?service=ID). Los que se cobran según el largo
 * del pelo se despliegan en la misma fila con una tarjeta por largo (silueta y precio), y cada tarjeta abre la reserva
 * con ese largo elegido (&length=long).
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

    /**
     * Same lengths as the agenda (App.Utils.HairLength in agenda/assets/js/utils/hair_length.js). "end" is where the
     * hair ends in the figure.
     */
    const LENGTHS = [
        {key: 'short', label: 'Corto', hint: 'Hasta el hombro', end: 40},
        {key: 'medium', label: 'Mediano', hint: 'Hasta el brasier', end: 58},
        {key: 'long', label: 'Largo', hint: 'Hasta la cintura', end: 76},
        {key: 'xlong', label: 'Extra largo', hint: 'Bajo la cintura', end: 97},
    ];

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

    function bookUrl(serviceId, length) {
        return serviceId ? BOOK + '?service=' + serviceId + (length ? '&length=' + length : '') : BOOK;
    }

    /**
     * Back view of a figure with the hair down to that length, and a line where it ends (same figure as the agenda).
     */
    function hairFigure(length) {
        const end = length.end;
        const spread = Math.min(5, (end - 40) / 12 + 1);

        return `
            <svg class="hair-figure" viewBox="0 0 80 120" aria-hidden="true">
                <path class="hair-figure__body"
                      d="M35 26h10v6c9 1 15 4 16 10l-3 20c-1 6-3 10-4 14 3 6 5 14 5 24l-1 20H22l-1-20c0-10 2-18 5-24-1-4-3-8-4-14l-3-20c1-6 7-9 16-10z"/>
                <path class="hair-figure__body" d="M62 40c4 2 5 7 5 13l1 34c0 4-5 4-5 0l-2-27z"/>
                <path class="hair-figure__body" d="M18 40c-4 2-5 7-5 13l-1 34c0 4 5 4 5 0l2-27z"/>
                <ellipse class="hair-figure__body" cx="40" cy="17" rx="10" ry="11"/>
                <path class="hair-figure__hair"
                      d="M29 21C28 2 52 2 51 21L${52 + spread} ${end - 5}Q40 ${end + 3} ${28 - spread} ${end - 5}Z"/>
                <path class="hair-figure__mark" d="M6 ${end}H74"/>
            </svg>`;
    }

    function linkBookingButtons() {
        document.querySelectorAll('[data-book]').forEach((link) => {
            link.href = bookUrl(link.dataset.book);
        });
    }

    function renderPromo(services) {
        const section = document.getElementById('promo');
        const button = document.getElementById('promo-book');
        const promos = services.filter((service) => PROMO.test(normalize(service.name)) && service.price > 0);
        // La promo destacada es la que trae la página; si ya no está activa, la más barata.
        const promo = promos.find((service) => String(service.id) === button.dataset.book)
            || promos.sort((a, b) => a.price - b.price)[0];

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

        button.dataset.book = promo.id;
        button.href = bookUrl(promo.id);
    }

    /**
     * A service priced by hair length: the row looks like the others (price as set in the agenda, "desde" included)
     * and opens in place to choose the length, one card per length with its figure and price. Each card books with
     * that length.
     */
    function variantRow(service) {
        const durations = [...new Set(service.variants.map((variant) => variant.duration))];
        const meta = durations.length > 1
            ? duration(Math.min(...durations)) + ' a ' + duration(Math.max(...durations))
            : duration(durations[0]);
        const pickerId = 'svc-lengths-' + service.id;

        return `
            <li class="svc-item">
                <button type="button" class="svc svc--toggle" aria-expanded="false" aria-controls="${pickerId}">
                    <span class="svc__name">${esc(displayName(service.name))}</span>
                    <span class="svc__meta">${esc(meta)}</span>
                    <span class="svc__price">
                        ${service.price > 0 ? `<strong>${service.price_type === 'from' ? '<small>desde</small>' : ''}${esc(money(service.price))}</strong>` : ''}
                        <span class="svc__go">Elegir largo <span class="svc__chevron" aria-hidden="true">›</span></span>
                    </span>
                </button>
                <div class="svc__picker" id="${pickerId}">
                    <div class="svc__picker-inner">
                        <p class="svc__picker-lead">¿Qué largo tienes? Mídelo con el pelo suelto, por la espalda.</p>
                        <div class="svc__lengths">
                            ${service.variants
                                .map((variant) => {
                                    const length = LENGTHS.find((item) => item.key === variant.hair_length);

                                    return `
                                        <a class="svc__length" href="${esc(bookUrl(service.id, variant.hair_length))}" tabindex="-1"
                                           aria-label="Reservar ${esc(displayName(service.name))}, pelo ${esc(length.label.toLowerCase())}, ${esc(money(variant.price))}">
                                            ${hairFigure(length)}
                                            <strong>${esc(length.label)}</strong>
                                            <span>${esc(length.hint)}</span>
                                            <b>${esc(money(variant.price))}</b>
                                        </a>`;
                                })
                                .join('')}
                        </div>
                    </div>
                </div>
            </li>`;
    }

    /**
     * Open one service priced by length (and close the one that was open).
     */
    function toggleLengths(button) {
        const open = button.getAttribute('aria-expanded') !== 'true';

        document.querySelectorAll('.svc--toggle[aria-expanded="true"]').forEach((other) => {
            if (other !== button) {
                setExpanded(other, false);
            }
        });

        setExpanded(button, open);
    }

    function setExpanded(button, open) {
        button.setAttribute('aria-expanded', String(open));
        document.getElementById(button.getAttribute('aria-controls')).querySelectorAll('a').forEach((link) => {
            link.tabIndex = open ? 0 : -1;
        });
    }

    function serviceRow(service) {
        if ((service.variants || []).length) {
            return variantRow(service);
        }

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

        panels.addEventListener('click', (event) => {
            const toggle = event.target.closest('.svc--toggle');

            if (toggle) {
                toggleLengths(toggle);
            }
        });

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

    /**
     * Gift cards: the products slide away (right to left) and the form comes in with the chosen card; "Elegir otra"
     * slides back. The form posts to the agenda, which sends the buyer to Webpay.
     */
    function setupGiftCards() {
        const slider = document.getElementById('gc-slider');

        if (!slider) {
            return;
        }

        const MIN = 10000;
        const MAX = 500000;
        const SLIDE_MS = 450;
        const form = document.getElementById('gc-form');
        const productsPane = document.getElementById('gc-pane-products');
        const formPane = document.getElementById('gc-pane-form');
        const other = document.getElementById('gc-other');
        const otherInput = document.getElementById('gc-other-amount');
        const amount = document.getElementById('gc-amount');
        const selectedAmount = document.getElementById('gc-selected-amount');
        const pay = document.getElementById('gc-pay');
        const error = document.getElementById('gc-error');
        let selected = '30000';
        let lastProduct = null;

        form.action = AGENDA + '/index.php/gift_cards/checkout';

        function currentAmount() {
            return selected === 'other' ? Number(otherInput.value.replace(/\D/g, '')) || 0 : Number(selected);
        }

        function update() {
            const value = currentAmount();

            other.hidden = selected !== 'other';
            amount.value = value;
            selectedAmount.textContent = value ? money(value) : 'Otro monto';
            pay.textContent = value ? 'Pagar ' + money(value) + ' con Webpay' : 'Pagar con Webpay';
            error.hidden = true;
            fitHeight();
        }

        // The slider takes the height of the visible pane, so the section does not keep the taller one's space.
        function fitHeight() {
            const pane = slider.dataset.pane === 'form' ? formPane : productsPane;

            slider.style.height = pane.offsetHeight + 'px';
        }

        function show(pane) {
            const toForm = pane === 'form';

            slider.dataset.pane = pane;
            productsPane.inert = toForm;
            formPane.inert = !toForm;
            fitHeight();

            // On the phone, bring the top of the card into view if it scrolled away.
            const top = slider.getBoundingClientRect().top;

            if (top < 70 || top > window.innerHeight * 0.6) {
                slider.scrollIntoView({behavior: 'smooth', block: 'start'});
            }

            setTimeout(() => {
                const target = toForm ? (selected === 'other' ? otherInput : form.elements.buyer_name) : lastProduct;

                target?.focus({preventScroll: true});
            }, SLIDE_MS);
        }

        productsPane.addEventListener('click', (event) => {
            const product = event.target.closest('.gc-product');

            if (!product) {
                return;
            }

            lastProduct = product;
            selected = product.dataset.amount;
            update();
            show('form');
        });

        document.getElementById('gc-back').addEventListener('click', () => show('products'));

        otherInput.addEventListener('input', () => {
            const digits = otherInput.value.replace(/\D/g, '');

            otherInput.value = digits ? money(Number(digits)) : '';
            update();
        });

        form.addEventListener('submit', (event) => {
            const value = currentAmount();
            let message = '';

            if (value < MIN || value > MAX) {
                message = 'El monto debe estar entre ' + money(MIN) + ' y ' + money(MAX) + '.';
            } else if (!form.checkValidity()) {
                message = 'Completa tu nombre y un correo válido: ahí te enviamos la gift card.';
            }

            if (message) {
                event.preventDefault();
                error.textContent = message;
                error.hidden = false;
                fitHeight();
                return;
            }

            pay.disabled = true;
            pay.textContent = 'Abriendo Webpay…';
        });

        // Volver con el botón atrás desde Webpay no debe dejar el botón bloqueado.
        window.addEventListener('pageshow', () => {
            pay.disabled = false;
            update();
        });

        window.addEventListener('resize', fitHeight);
        window.addEventListener('load', fitHeight); // Las fuentes cambian la altura al cargar.

        update();
    }

    linkBookingButtons();
    loadCatalog();
    setupGiftCards();
})();
