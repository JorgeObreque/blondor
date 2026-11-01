const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const handlers = {};
const shown = [];
const opened = [];
let windows = [];
const context = {
    URL,
    self: {
        location: {origin: 'https://agenda.blondor.cl'},
        registration: {scope: 'https://agenda.blondor.cl/', showNotification: async (title, options) => shown.push({title, options})},
        clients: {matchAll: async () => windows, openWindow: async url => opened.push(url)},
        addEventListener: (type, handler) => {handlers[type] = handler;},
    },
};
vm.runInNewContext(fs.readFileSync('agenda/backoffice-sw.js', 'utf8'), context);
async function fire(type, data) {
    let pending;
    handlers[type]({...data, waitUntil: promise => {pending = promise;}});
    await pending;
}
(async () => {
    await fire('push', {data: {json: () => ({title:'Blondor',url:'https://evil.test/private',body:'Aviso'})}});
    assert.equal(shown[0].options.data.url, 'https://agenda.blondor.cl/index.php/agenda');
    await fire('push', {data: {json: () => {throw new Error('invalid');}}});
    assert.equal(shown.length, 2, 'Todo push muestra una notificación visible');
    await fire('push', {data: {json: () => ({url:'https://agenda.blondor.cl/index.php/backoffice_push/open/42'})}});
    const notification = {data: shown[2].options.data, close(){}};
    await fire('notificationclick', {notification});
    assert.equal(opened[0], 'https://agenda.blondor.cl/index.php/backoffice_push/open/42');
    let navigated, focused = false;
    windows = [{url:'https://agenda.blondor.cl/index.php/agenda',navigate:async url => {navigated=url;},focus:async () => {focused=true;}}];
    await fire('notificationclick', {notification});
    assert.equal(navigated, opened[0]); assert.ok(focused);
    await fire('notificationclick', {notification:{data:{url:'https://evil.test'},close(){}}});
    assert.equal(opened.length,1);
    console.log('Service worker: aviso visible, enlaces externos bloqueados y cita abierta/reutilizada en el backoffice.');
})().catch(error => {console.error(error); process.exitCode=1;});
