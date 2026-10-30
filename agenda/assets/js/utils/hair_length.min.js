/* ----------------------------------------------------------------------------
 * Blondor - Largos del pelo
 *
 * Los cuatro largos con que se cobran los servicios que dependen del largo, y la infografía de cada uno: una
 * silueta de espalda con el pelo hasta el largo y una línea que marca dónde termina. La usan la reserva online, la
 * agenda y /precios (el sitio blondor.cl tiene la misma en assets/js/site.js).
 * ---------------------------------------------------------------------------- */

window.App.Utils.HairLength = (function () {
    /**
     * Same keys and texts as Service_variants_model::LENGTHS. "end" is where the hair ends in the figure.
     */
    const LENGTHS = [
        {key: 'short', label: 'Corto', hint: 'Hasta el hombro', end: 40},
        {key: 'medium', label: 'Mediano', hint: 'Hasta el brasier', end: 58},
        {key: 'long', label: 'Largo', hint: 'Hasta la cintura', end: 76},
        {key: 'xlong', label: 'Extra largo', hint: 'Bajo la cintura', end: 97},
    ];

    function find(key) {
        return LENGTHS.find((item) => item.key === key) || null;
    }

    function label(key) {
        const item = find(key);

        return item ? item.label : '';
    }

    /**
     * Back view of a figure with the hair down to the given length. Colors come from CSS: the body uses
     * --hair-body, the hair currentColor and the mark --hair-mark.
     */
    function figure(key) {
        const item = find(key);

        if (!item) {
            return '';
        }

        const end = item.end;
        // Longer hair falls a little wider over the back.
        const spread = Math.min(5, (end - 40) / 12 + 1);

        return `
            <svg class="hair-figure" viewBox="0 0 80 120" role="img" aria-label="${item.label}: ${item.hint.toLowerCase()}">
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

    return {
        LENGTHS,
        find,
        label,
        figure,
    };
})();
