/* ----------------------------------------------------------------------------
 * Blondor - Reserva pública
 *
 * Basado en el cliente de Easy!Appointments (FrontendBookApi); ahora solo habla con el servidor y devuelve
 * promesas: la página decide cómo mostrar cada respuesta.
 * ---------------------------------------------------------------------------- */

/**
 * Booking HTTP client.
 *
 * This module implements the booking related HTTP requests.
 */
App.Http.Booking = (function () {
    /**
     * Turn a failed request into a message the customer can understand.
     *
     * @param {Object} jqXHR
     * @param {String} textStatus
     *
     * @return {Error}
     */
    function toError(jqXHR, textStatus) {
        let message = 'No hay conexión. Revisa tu internet e inténtalo de nuevo.';

        if (textStatus === 'timeout') {
            message = 'El servidor tardó demasiado en responder. Inténtalo de nuevo.';
        } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
            message = jqXHR.responseJSON.message;
        } else if (jqXHR.status) {
            message = 'No pudimos completar la reserva (error ' + jqXHR.status + ').';
        }

        return new Error(message);
    }

    function request(options) {
        return new Promise((resolve, reject) => {
            $.ajax({dataType: 'json', timeout: 20000, global: false, ...options})
                .done(resolve)
                .fail((jqXHR, textStatus) => reject(toError(jqXHR, textStatus)));
        });
    }

    /**
     * Free start times ("HH:mm", provider timezone) of a day.
     *
     * @param {Object} params {serviceId, providerId, date (Y-m-d), duration, appointmentId}
     *
     * @return {Promise<Array>}
     */
    function getAvailableHours(params) {
        return request({
            url: App.Utils.Url.siteUrl('booking/get_available_hours'),
            method: 'POST',
            data: {
                csrf_token: vars('csrf_token'),
                service_id: params.serviceId,
                provider_id: params.providerId,
                selected_date: params.date,
                service_duration: params.duration,
                manage_mode: Number(Boolean(params.appointmentId)),
                appointment_id: params.appointmentId,
            },
        }).then((response) => (Array.isArray(response) ? response : []));
    }

    /**
     * Days without any free time in the month of the given date.
     *
     * @param {Object} params {serviceId, providerId, date (Y-m-d), appointmentId}
     *
     * @return {Promise<Array>} Y-m-d dates; every day of the month when the month is full.
     */
    function getUnavailableDates(params) {
        return request({
            url: App.Utils.Url.siteUrl('booking/get_unavailable_dates'),
            method: 'GET',
            data: {
                csrf_token: vars('csrf_token'),
                provider_id: params.providerId,
                service_id: params.serviceId,
                selected_date: params.date,
                manage_mode: Number(Boolean(params.appointmentId)),
                appointment_id: params.appointmentId,
            },
        }).then((response) => {
            if (Array.isArray(response)) {
                return response;
            }

            // The whole month is unavailable.
            const month = moment(params.date);
            const days = [];

            for (let day = 1; day <= month.daysInMonth(); day++) {
                days.push(month.clone().date(day).format('YYYY-MM-DD'));
            }

            return days;
        });
    }

    /**
     * Save the appointment (new or rescheduled).
     *
     * @param {Object} postData {appointment, customer, manage_mode}
     * @param {String} [captcha]
     *
     * @return {Promise<Object>} {appointment_hash} or {captcha_verification: false}
     */
    function registerAppointment(postData, captcha) {
        const data = {
            csrf_token: vars('csrf_token'),
            post_data: postData,
        };

        if (captcha !== undefined) {
            data.captcha = captcha;
        }

        if (postData.manage_mode) {
            data.exclude_appointment_id = postData.appointment.id;
        }

        return request({
            url: App.Utils.Url.siteUrl('booking/register'),
            method: 'POST',
            data,
        });
    }

    /**
     * Delete the personal information of the customer (manage mode).
     *
     * @param {String} customerToken
     *
     * @return {Promise}
     */
    function deletePersonalInformation(customerToken) {
        return request({
            url: App.Utils.Url.siteUrl('privacy/delete_personal_information'),
            method: 'POST',
            data: {
                csrf_token: vars('csrf_token'),
                customer_token: customerToken,
            },
        });
    }

    return {
        getAvailableHours,
        getUnavailableDates,
        registerAppointment,
        deletePersonalInformation,
    };
})();
