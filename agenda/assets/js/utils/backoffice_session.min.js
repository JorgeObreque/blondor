/* Loaded only by the authenticated backoffice layout. Never replays a failed mutation. */
App.Utils.BackofficeSession = (() => {
    const nativeFetch = window.fetch.bind(window);
    let pending = null;
    let redirecting = false;

    function redirectToLogin() {
        if (redirecting) return;
        redirecting = true;
        window.location.replace(App.Utils.Url.siteUrl('login'));
    }

    function check() {
        if (redirecting) return Promise.resolve(false);
        if (pending) return pending;
        pending = nativeFetch(App.Utils.Url.siteUrl('backoffice_session/status'), {
            method: 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        }).then(async response => {
            if (response.status === 401) { redirectToLogin(); return false; }
            if (!response.ok) return null;
            const state = await response.json();
            if (state.authenticated === false) { redirectToLogin(); return false; }
            return state.authenticated === true ? true : null;
        }).catch(() => null).finally(() => { pending = null; });
        return pending;
    }

    function handleResponse(response) {
        if (response && (response.status === 401 || response.status === 403)) return check();
        if (response?.redirected && response.url) {
            try {
                const url = new URL(response.url, window.location.href);
                const login = new URL(App.Utils.Url.siteUrl('login'), window.location.href);
                if (url.origin === login.origin && url.pathname === login.pathname) return check();
            } catch (_) { /* An invalid URL is handled by the original request. */ }
        }
        return Promise.resolve(null);
    }

    // Covers fetch consumers in any backoffice screen, including notification settings.
    window.fetch = async (...args) => {
        const response = await nativeFetch(...args);
        const requested = new URL(typeof args[0] === 'string' || args[0] instanceof URL ? args[0] : args[0].url, window.location.href);
        if (requested.origin === window.location.origin) await handleResponse(response);
        return response;
    };

    function checkWhenVisible() {
        if (document.visibilityState !== 'hidden') check();
    }

    function initialize() {
        // global:false requests (the mobile agenda) call handleResponse explicitly in their client.
        $(document).ajaxError((_event, xhr) => { handleResponse(xhr); });
        document.addEventListener('visibilitychange', checkWhenVisible);
        window.addEventListener('focus', checkWhenVisible);
        window.addEventListener('pageshow', checkWhenVisible);
        window.addEventListener('online', checkWhenVisible);
        window.setInterval(checkWhenVisible, 60000);
        checkWhenVisible();
    }
    document.addEventListener('DOMContentLoaded', initialize);
    return {check, handleResponse};
})();
