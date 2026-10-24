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
    function post(path, data, options = {}) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: App.Utils.Url.siteUrl(path),
                method: 'POST',
                data: data instanceof FormData ? data : {csrf_token: vars('csrf_token'), ...data},
                dataType: 'json',
                timeout: options.timeout || 20000,
                global: false,
                ...(data instanceof FormData ? {processData: false, contentType: false} : {}),
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

    function profile(customerId) {
        return post('agenda/profile', {customer_id: customerId});
    }

    function saveProfile(customerId, profile) {
        return post('agenda/save_profile', {customer_id: customerId, profile});
    }

    function record(params) {
        return post('agenda/record', params);
    }

    function saveRecord(record) {
        return post('agenda/save_record', {record});
    }

    function uploadPhoto(recordId, kind, blob) {
        const data = new FormData();
        data.append('csrf_token', vars('csrf_token'));
        data.append('record_id', recordId);
        data.append('kind', kind);
        data.append('photo', blob, 'foto.jpg');

        return post('agenda/upload_photo', data, {timeout: 90000});
    }

    function deletePhoto(photoId) {
        return post('agenda/delete_photo', {photo_id: photoId});
    }

    function photoUrl(photoId) {
        return App.Utils.Url.siteUrl('agenda/photo/' + photoId);
    }

    return {
        day,
        availableHours,
        customers,
        save,
        setStatus,
        undo,
        profile,
        saveProfile,
        record,
        saveRecord,
        uploadPhoto,
        deletePhoto,
        photoUrl,
    };
})();
