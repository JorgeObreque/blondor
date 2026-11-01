/* Backoffice notifications only. No fetch handler: private pages are never cached or served offline. */
self.addEventListener('push', (event) => {
    let payload = {};
    try { payload = event.data ? event.data.json() : {}; } catch (_) { /* Always show a visible notification. */ }
    const root = self.registration.scope;
    let url = new URL('index.php/agenda', root).href;
    try {
        const requested = new URL(payload.url, root);
        if (requested.origin === self.location.origin && /\/backoffice_push\/open\/\d+$/.test(requested.pathname)) {
            url = requested.href;
        }
    } catch (_) { /* Untrusted links fall back to the authenticated agenda. */ }
    event.waitUntil(self.registration.showNotification(payload.title || 'Blondor · Agenda', {
        body: payload.body || 'Abre tu agenda para ver las novedades.',
        icon: new URL('assets/img/pwa/blondor-192.png', root).href,
        tag: payload.tag || 'blondor-agenda', data: {url},
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || 'index.php/agenda', self.registration.scope);
    if (url.origin !== self.location.origin) { return; }
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        const existing = windows.find((client) => client.url.includes('/agenda') || client.url.includes('/backoffice_push'));
        if (existing) {
            await existing.navigate(url.href);
            return existing.focus();
        }
        return self.clients.openWindow(url.href);
    })());
});
