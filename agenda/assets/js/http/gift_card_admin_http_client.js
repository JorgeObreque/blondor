/* ----------------------------------------------------------------------------
 * Blondor - Gift cards (backoffice)
 * ---------------------------------------------------------------------------- */

/**
 * Gift card admin HTTP client.
 *
 * This module implements the gift card back office related HTTP requests.
 */
App.Http.GiftCardAdmin = (function () {
    function request(uri, data, method = 'POST') {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: App.Utils.Url.siteUrl('giftcards/' + uri),
                method,
                data: {csrf_token: vars('csrf_token'), ...data},
                dataType: 'json',
                timeout: 20000,
                global: false,
            })
                .done(resolve)
                .fail((jqXHR, textStatus) => {
                    let message = 'No hay conexión con el servidor.';

                    if (textStatus === 'timeout') {
                        message = 'El servidor tardó demasiado en responder.';
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    } else if (jqXHR.status === 403 || jqXHR.status === 401) {
                        message = 'La sesión expiró. Vuelve a iniciar sesión.';
                    } else if (jqXHR.status) {
                        message = 'Error del servidor (' + jqXHR.status + ').';
                    }

                    reject(new Error(message));
                });
        });
    }

    /**
     * @return {Promise<Array>}
     */
    function search(keyword, status) {
        return request('search', {keyword, status}, 'GET');
    }

    /**
     * @return {Promise<Object>} {gift_card, redemptions}
     */
    function detail(id) {
        return request('detail', {id}, 'GET');
    }

    /**
     * @return {Promise<Object>} {gift_card, redemptions}
     */
    function redeem(id, amount, notes) {
        return request('redeem', {id, amount, notes});
    }

    /**
     * @return {Promise<Object>} {gift_card, redemptions}
     */
    function create(data) {
        return request('create', data);
    }

    return {
        search,
        detail,
        redeem,
        create,
    };
})();
