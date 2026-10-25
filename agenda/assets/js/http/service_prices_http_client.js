/* ----------------------------------------------------------------------------
 * Blondor - Servicios y precios
 * ---------------------------------------------------------------------------- */

/**
 * Service prices HTTP client.
 *
 * This module implements the service prices related HTTP requests.
 */
App.Http.ServicePrices = (function () {
    /**
     * Save quick edits. Each change has the service id and only the fields to change.
     *
     * @param {Array} changes
     *
     * @return {Promise<Object>}
     */
    function save(changes) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: App.Utils.Url.siteUrl('service_prices/save'),
                method: 'POST',
                data: {csrf_token: vars('csrf_token'), changes},
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

    return {
        save,
    };
})();
