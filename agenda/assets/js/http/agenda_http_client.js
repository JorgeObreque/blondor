/* ----------------------------------------------------------------------------
 * Blondor - Agenda móvil
 * ---------------------------------------------------------------------------- */

/**
 * Agenda HTTP client.
 *
 * This module implements the agenda related HTTP requests.
 */
App.Http.Agenda = (function () {
    /**
     * POST helper that always resolves to the JSON body or rejects with a readable message.
     *
     * @param {String} path
     * @param {Object} data
     *
     * @return {Promise<Object>}
     */
    function post(path, data) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: App.Utils.Url.siteUrl(path),
                method: 'POST',
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
                    } else if (textStatus === 'parsererror') {
                        message = 'Respuesta inesperada del servidor. Toca Reintentar: no se duplicará.';
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

    function day(date, providerId) {
        return post('agenda/day', {date, provider_id: providerId});
    }

    function availableHours(date, serviceId, providerId, excludeAppointmentId) {
        return post('agenda/available_hours', {
            date,
            service_id: serviceId,
            provider_id: providerId,
            exclude_appointment_id: excludeAppointmentId || '',
        });
    }

    function customers(keyword) {
        return post('agenda/customers', {keyword});
    }

    function save(appointment, customer, requestId, force) {
        return post('agenda/save', {appointment, customer, request_id: requestId, force: force || {}});
    }

    function setStatus(appointmentId, status) {
        return post('agenda/set_status', {appointment_id: appointmentId, status});
    }

    function undo(appointmentId) {
        return post('agenda/undo', {appointment_id: appointmentId});
    }

    return {
        day,
        availableHours,
        customers,
        save,
        setStatus,
        undo,
    };
})();
