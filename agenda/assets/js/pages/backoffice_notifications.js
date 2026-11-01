(() => {
    const panel = document.getElementById('backoffice-notifications');
    if (!panel) return;
    const enable = document.getElementById('push-enable');
    const disable = document.getElementById('push-disable');
    const status = document.getElementById('push-status');
    const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    let registration;
    let busy = false;

    async function api(action, values = {}) {
        const data = new URLSearchParams({...values, csrf_token: vars('csrf_token')});
        const response = await fetch(panel.dataset.api + '/' + action, {
            method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data,
        });
        if (!response.ok) throw new Error(response.status === 403 ? 'Tu sesión terminó. Vuelve a ingresar a la agenda.' : 'No se pudo guardar el ajuste. Inténtalo otra vez.');
        return response.json();
    }

    function keyBytes(encoded) {
        const value = encoded.replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(value + '='.repeat((4 - value.length % 4) % 4)), (char) => char.charCodeAt(0));
    }

    function show(active) {
        enable.hidden = active;
        disable.hidden = !active;
        enable.disabled = busy;
        disable.disabled = busy;
        status.textContent = active ? 'Notificaciones activadas para tu cuenta en este dispositivo.' : 'Las notificaciones están desactivadas en este dispositivo.';
    }

    async function initialize() {
        if (ios && !standalone) {
            status.textContent = 'Primero agrega la agenda a la pantalla de inicio y ábrela desde ese icono.';
            return;
        }
        if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            status.textContent = 'Este navegador no admite notificaciones. En iPhone, usa Safari y agrega la agenda a inicio.';
            return;
        }
        if (!panel.dataset.key) { status.textContent = 'Las notificaciones no están disponibles en este momento.'; return; }
        try {
            registration = await navigator.serviceWorker.register(panel.dataset.worker, {scope: panel.dataset.scope});
            // Do not request permission on page load; Safari requires a direct tap on Activate.
            const subscription = await registration.pushManager.getSubscription();
            const saved = await api('status', {endpoint: subscription?.endpoint || ''});
            if (!saved.available) { status.textContent = 'Las notificaciones no están disponibles en este momento.'; return; }
            if (Notification.permission === 'denied') {
                status.textContent = 'Los avisos están bloqueados. Habilítalos en los ajustes de notificaciones de tu dispositivo.';
                if (subscription) { await api('unsubscribe', {endpoint: subscription.endpoint}); }
                return;
            }
            show(Boolean(subscription && saved.subscribed && Notification.permission === 'granted'));
        } catch (error) { status.textContent = error.message || 'No se pudo preparar este dispositivo. Recarga e inténtalo otra vez.'; }
    }

    enable.addEventListener('click', async () => {
        if (busy || !registration) return;
        busy = true; enable.disabled = true;
        try {
            // Call synchronously from the tap, before awaiting any network request.
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') { throw new Error('No se otorgó permiso. Puedes activarlo después desde los ajustes de tu dispositivo.'); }
            await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();
            if (subscription && subscription.options?.applicationServerKey) {
                const previous = new Uint8Array(subscription.options.applicationServerKey);
                const current = keyBytes(panel.dataset.key);
                if (previous.length !== current.length || previous.some((value, index) => value !== current[index])) {
                    await subscription.unsubscribe(); subscription = null;
                }
            }
            subscription = subscription || await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(panel.dataset.key)});
            await api('subscribe', {subscription: JSON.stringify(subscription.toJSON())});
            busy = false; show(true);
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; enable.disabled = false; }
    });

    disable.addEventListener('click', async () => {
        if (busy || !registration) return;
        busy = true; disable.disabled = true;
        try {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await api('unsubscribe', {endpoint: subscription.endpoint});
                await subscription.unsubscribe();
            }
            busy = false; show(false);
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; disable.disabled = false; }
    });
    initialize();
})();
