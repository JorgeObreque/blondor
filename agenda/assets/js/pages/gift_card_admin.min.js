/* ----------------------------------------------------------------------------
 * Blondor - Gift cards (backoffice)
 *
 * Lista con búsqueda por código o nombre, hoja de detalle con saldo, canje (todo o una parte) e historial, y alta de
 * gift cards vendidas en el salón. Mismo estilo que la agenda móvil y /precios.
 * ---------------------------------------------------------------------------- */

/**
 * Gift card admin page.
 *
 * This module implements the functionality of the gift card back office page.
 */
App.Pages.GiftCardAdmin = (function () {
    const $page = $('#gca-page');
    const $list = $('#gca-list');
    const $search = $('#gca-search');
    const $detail = $('#gca-detail');
    const $create = $('#gca-create');
    const $backdrop = $('#gca-backdrop');
    const $toast = $('#gca-toast');
    const canEdit = Boolean(vars('gift_cards_can_edit'));

    const STATUS = {
        active: {label: 'Con saldo', className: 'gca-pill--active'},
        used: {label: 'Usada', className: 'gca-pill--used'},
        failed: {label: 'Pago rechazado', className: 'gca-pill--muted'},
        cancelled: {label: 'Anulada', className: 'gca-pill--muted'},
    };

    const ICONS = {
        check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>',
        warn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>',
        copy: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/></svg>',
    };

    const state = {
        status: 'active',
        keyword: '',
        items: vars('gift_cards') || [],
        current: null, // {gift_card, redemptions}
        saving: false,
    };

    let searchTimer = null;
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

    function money(value) {
        return '$' + Math.round(Number(value) || 0).toLocaleString('es-CL');
    }

    function parseAmount(text) {
        return Number(String(text || '').replace(/\D/g, '')) || 0;
    }

    function dateText(value) {
        return value ? moment(value).format('DD-MM-YYYY') : '';
    }

    function people(item) {
        const parts = [];

        if (item.recipient_name) {
            parts.push('Para ' + item.recipient_name);
        }

        if (item.buyer_name) {
            parts.push('De ' + item.buyer_name);
        }

        return parts.join(' · ');
    }

    function toast(text, options = {}) {
        clearTimeout(toastTimer);

        $toast
            .toggleClass('agenda-toast--error', Boolean(options.error))
            .html(
                `<span class="agenda-toast__icon">${options.error ? ICONS.warn : ICONS.check}</span>
                 <span class="agenda-toast__text">${esc(text)}</span>`,
            )
            .prop('hidden', false);

        toastTimer = setTimeout(() => $toast.prop('hidden', true), options.error ? 6000 : 3500);
    }

    function openSheet($sheet) {
        $('.agenda-sheet').not($sheet).prop('hidden', true);
        $sheet.prop('hidden', false);
        $backdrop.prop('hidden', false);
        $('body').addClass('agenda-locked');
    }

    function closeSheets() {
        $('.agenda-sheet').prop('hidden', true);
        $backdrop.prop('hidden', true);
        $('body').removeClass('agenda-locked');
        state.current = null;
    }

    // List ------------------------------------------------------------------------------------------------------

    function renderList() {
        if (!state.items.length) {
            $list.html(
                `<p class="agenda-empty">${
                    state.keyword ? 'No hay gift cards que coincidan con «' + esc(state.keyword) + '».' : 'Todavía no hay gift cards aquí.'
                }</p>`,
            );
            return;
        }

        $list.html(
            state.items
                .map((item) => {
                    const status = STATUS[item.status] || STATUS.cancelled;
                    const partial = item.status === 'active' && item.balance < item.amount;

                    return `
                        <button type="button" class="gca-item" data-id="${item.id}">
                            <span class="gca-item__main">
                                <span class="gca-item__code">${esc(item.code || '—')}</span>
                                <span class="gca-item__who">${esc(people(item) || (item.channel === 'salon' ? 'Vendida en el salón' : 'Online'))}</span>
                                <span class="gca-item__meta">
                                    <span class="gca-pill ${status.className}">${status.label}</span>
                                    ${esc(item.channel === 'salon' ? 'Salón' : 'Online')} · ${esc(dateText(item.create_datetime))}
                                </span>
                            </span>
                            <span class="gca-item__amount">
                                <strong>${esc(money(item.status === 'active' ? item.balance : item.amount))}</strong>
                                ${partial ? `<small>de ${esc(money(item.amount))}</small>` : ''}
                            </span>
                        </button>`;
                })
                .join(''),
        );
    }

    async function reload() {
        try {
            state.items = await App.Http.GiftCardAdmin.search(state.keyword, state.keyword ? '' : state.status);
            renderList();
        } catch (error) {
            toast(error.message, {error: true});
        }
    }

    // Detail and redemption -------------------------------------------------------------------------------------

    function renderDetail() {
        const {gift_card: card, redemptions} = state.current;
        const status = STATUS[card.status] || STATUS.cancelled;
        const canRedeem = canEdit && card.status === 'active';

        $detail.html(`
            <span class="agenda-sheet__grab" aria-hidden="true"></span>
            <div class="gca-card">
                <div class="gca-card__row">
                    <span class="gca-pill ${status.className}">${status.label}</span>
                    <span class="gca-card__channel">${esc(card.channel === 'salon' ? 'Vendida en el salón' : 'Comprada online')} · ${esc(dateText(card.paid_datetime || card.create_datetime))}</span>
                </div>
                <button type="button" class="gca-card__code" id="gca-detail-code" data-copy="${esc(card.code)}" aria-label="Copiar código ${esc(card.code)}">
                    ${esc(card.code)} ${ICONS.copy}
                </button>
                <div class="gca-card__balance">
                    <span>Saldo</span>
                    <strong>${esc(money(card.balance))}</strong>
                    <small>de ${esc(money(card.amount))}</small>
                </div>
            </div>

            <dl class="gca-facts">
                ${card.recipient_name ? `<div><dt>Para</dt><dd>${esc(card.recipient_name)}${card.recipient_email ? `<br><small>${esc(card.recipient_email)}</small>` : ''}</dd></div>` : ''}
                ${card.buyer_name ? `<div><dt>De</dt><dd>${esc(card.buyer_name)}${card.buyer_email ? `<br><small>${esc(card.buyer_email)}</small>` : ''}${card.buyer_phone ? `<br><small>${esc(card.buyer_phone)}</small>` : ''}</dd></div>` : ''}
                ${card.message ? `<div><dt>Mensaje</dt><dd>«${esc(card.message)}»</dd></div>` : ''}
                ${card.notes ? `<div><dt>Nota</dt><dd>${esc(card.notes)}</dd></div>` : ''}
            </dl>

            ${canRedeem
                ? `<form class="wizard-form gca-redeem" id="gca-redeem-form" novalidate>
                       <h2>Canjear</h2>
                       <div class="gca-redeem__row">
                           <label class="wizard-field">Monto a descontar
                               <input type="text" inputmode="numeric" id="gca-redeem-amount" autocomplete="off" placeholder="${esc(money(card.balance))}">
                           </label>
                           <button type="button" class="agenda-secondary" id="gca-redeem-all">Todo</button>
                       </div>
                       <label class="wizard-field">Servicio o nota <span class="gca-optional">opcional</span>
                           <input type="text" id="gca-redeem-notes" maxlength="200" autocomplete="off" placeholder="Ej.: color raíz">
                       </label>
                       <button type="submit" class="agenda-primary" id="gca-redeem-submit" disabled>Descontar</button>
                   </form>`
                : ''}

            <section class="gca-history">
                <h2>Historial</h2>
                ${redemptions.length
                    ? `<ul>${redemptions
                          .map(
                              (item) => `
                                <li>
                                    <span><strong>−${esc(money(item.amount))}</strong> ${item.notes ? '· ' + esc(item.notes) : ''}<br>
                                    <small>${esc(dateText(item.date))}${item.author ? ' · ' + esc(item.author) : ''}</small></span>
                                    <span class="gca-history__after">Quedan ${esc(money(item.balance_after))}</span>
                                </li>`,
                          )
                          .join('')}</ul>`
                    : '<p class="gca-hint">Todavía no se ha usado.</p>'}
            </section>
        `);
    }

    async function openDetail(id) {
        try {
            state.current = await App.Http.GiftCardAdmin.detail(id);
            renderDetail();
            openSheet($detail);
        } catch (error) {
            toast(error.message, {error: true});
        }
    }

    function updateRedeemButton() {
        const amount = parseAmount($('#gca-redeem-amount').val());
        const balance = state.current.gift_card.balance;
        const $submit = $('#gca-redeem-submit');

        $submit.prop('disabled', !amount || amount > balance || state.saving);
        $submit.text(
            amount > balance ? 'El saldo es ' + money(balance) : amount ? 'Descontar ' + money(amount) : 'Descontar',
        );
    }

    async function redeem() {
        const card = state.current.gift_card;
        const amount = parseAmount($('#gca-redeem-amount').val());

        if (!amount || amount > card.balance || state.saving) {
            return;
        }

        state.saving = true;
        $('#gca-redeem-submit').prop('disabled', true).text('Guardando…');

        try {
            state.current = await App.Http.GiftCardAdmin.redeem(card.id, amount, $('#gca-redeem-notes').val());
            renderDetail();

            const after = state.current.gift_card.balance;
            toast(after ? 'Se descontaron ' + money(amount) + '. Quedan ' + money(after) + '.' : 'Gift card usada completa.');
            reload();
        } catch (error) {
            toast(error.message, {error: true});
            updateRedeemButton();
        } finally {
            state.saving = false;
        }
    }

    // Create (sold in the salon) --------------------------------------------------------------------------------

    function openCreate() {
        $('#gca-create-form')[0].reset();
        $('#gca-create-amounts').html(
            (vars('gift_cards_amounts') || [])
                .map((value) => `<button type="button" class="wizard-tab" data-amount="${value}" aria-selected="false">${esc(money(value))}</button>`)
                .join(''),
        );
        openSheet($create);
    }

    async function create() {
        const amount = parseAmount($('#gca-create-amount').val());

        if (!amount) {
            toast('Escribe el monto de la gift card.', {error: true});
            $('#gca-create-amount').trigger('focus');
            return;
        }

        if (state.saving) {
            return;
        }

        state.saving = true;
        $('#gca-create-submit').prop('disabled', true).text('Creando…');

        try {
            state.current = await App.Http.GiftCardAdmin.create({
                amount,
                buyer_name: $('#gca-create-buyer').val(),
                recipient_name: $('#gca-create-recipient').val(),
                notes: $('#gca-create-notes').val(),
            });

            renderDetail();
            openSheet($detail);
            toast('Gift card creada: ' + state.current.gift_card.code);
            reload();
        } catch (error) {
            toast(error.message, {error: true});
        } finally {
            state.saving = false;
            $('#gca-create-submit').prop('disabled', false).text('Crear gift card');
        }
    }

    // Events ----------------------------------------------------------------------------------------------------

    function addEventListeners() {
        $search.on('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.keyword = $search.val().trim();
                reload();
            }, 300);
        });

        $page.on('click', '.gca-filters [data-status]', (event) => {
            const $tab = $(event.currentTarget);

            state.status = $tab.data('status');
            $('.gca-filters [data-status]').attr('aria-selected', 'false');
            $tab.attr('aria-selected', 'true');
            reload();
        });

        $list.on('click', '.gca-item', (event) => openDetail($(event.currentTarget).data('id')));

        $detail.on('click', '[data-copy]', async (event) => {
            const code = event.currentTarget.dataset.copy;

            try {
                await navigator.clipboard.writeText(code);
                toast('Código copiado: ' + code);
            } catch (error) {
                toast(code);
            }
        });

        $detail.on('input', '#gca-redeem-amount', (event) => {
            const amount = parseAmount(event.target.value);

            event.target.value = amount ? money(amount) : '';
            updateRedeemButton();
        });

        $detail.on('click', '#gca-redeem-all', () => {
            $('#gca-redeem-amount').val(money(state.current.gift_card.balance));
            updateRedeemButton();
        });

        $detail.on('submit', '#gca-redeem-form', (event) => {
            event.preventDefault();
            redeem();
        });

        $('#gca-new').on('click', openCreate);

        $create.on('click', '[data-amount]', (event) => {
            const value = Number($(event.currentTarget).data('amount'));

            $create.find('[data-amount]').attr('aria-selected', 'false');
            $(event.currentTarget).attr('aria-selected', 'true');
            $('#gca-create-amount').val(money(value));
        });

        $('#gca-create-amount').on('input', (event) => {
            const amount = parseAmount(event.target.value);

            event.target.value = amount ? money(amount) : '';
            $create.find('[data-amount]').attr('aria-selected', 'false');
        });

        $('#gca-create-form').on('submit', (event) => {
            event.preventDefault();
            create();
        });

        $page.on('click', '[data-close-sheet]', closeSheets);
        $backdrop.on('click', closeSheets);

        $(document).on('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSheets();
            }
        });
    }

    /**
     * Initialize the module.
     */
    function initialize() {
        $('#gca-new').prop('hidden', !canEdit);
        state.items = state.items.filter((item) => item.status === state.status);
        renderList();
        addEventListeners();

        // Links like /giftcards?code=BLD-XXXX-XXXX open that gift card.
        const code = App.Utils.Url.queryParam('code');

        if (code) {
            $search.val(code);
            state.keyword = code;
            reload();
        }
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {};
})();
