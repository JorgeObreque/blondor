/* ----------------------------------------------------------------------------
 * Blondor - Prueba de navegador de la reserva con pago (solo local).
 *
 * Maneja el Chrome local con puppeteer-core y recorre el flujo completo: servicio → hora → datos → pago →
 * Webpay (ambiente de integración de Transbank, con la tarjeta de prueba) → resultado.
 *
 * No mueve dinero real: la tarjeta de prueba es del ambiente de integración.
 *
 * Uso:
 *   npm i puppeteer-core            (una vez, en cualquier carpeta)
 *   node scripts/tests/booking_payment_browser.js [http://localhost:8088]
 * ---------------------------------------------------------------------------- */

const puppeteer = require(process.env.PUPPETEER_CORE || 'puppeteer-core');

const BASE_URL = process.argv[2] || 'http://localhost:8088';
const parsedBase = new URL(BASE_URL);
if (parsedBase.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(parsedBase.hostname) || parsedBase.port !== '8088') {
    throw new Error('Estas pruebas solo admiten HTTP local en el puerto 8088.');
}
const CHROME = process.env.CHROME_PATH || '/usr/bin/google-chrome';

let passed = 0;
const failed = [];

function check(condition, message) {
    if (condition) {
        passed += 1;
        console.log('ok   ' + message);
    } else {
        failed.push(message);
        console.log('FAIL ' + message);
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const page = await browser.newPage();

    await page.setViewport({width: 420, height: 900});

    page.on('console', (message) => {
        if (message.type() === 'error') {
            console.log('     (consola) ' + message.text());
        }
    });

    try {
        await page.goto(BASE_URL + '/index.php/booking', {waitUntil: 'networkidle2'});

        // 1. Servicio (uno con precio exacto, sin variantes).
        const serviceName = process.env.SERVICE_NAME || 'color raiz';

        await page.waitForSelector('.bk-svc');

        const clicked = await page.evaluate((name) => {
            const buttons = Array.from(document.querySelectorAll('.bk-svc'));

            const button = buttons.find((item) => item.textContent.toLowerCase().includes(name.toLowerCase()));

            if (!button) {
                return false;
            }

            button.click();

            return true;
        }, serviceName);

        check(clicked, 'se elige el servicio ' + serviceName);

        await page.waitForSelector('.bk-step[data-step="time"]:not([hidden])', {timeout: 15000});
        check(true, 'la página muestra el paso de día y hora');

        // 2. La hora: la página escoge sola el primer día con horas.
        await page.waitForFunction(
            () => document.querySelectorAll('#bk-slots .bk-slot').length > 0,
            {timeout: 30000},
        );

        await page.evaluate(() => {
            const slots = document.querySelectorAll('#bk-slots .bk-slot');

            slots[slots.length - 1].click();
        });

        await sleep(600);

        check(true, 'se elige día y hora');

        // 3. Datos.
        await page.click('#bk-next');

        await page.waitForSelector('.bk-step[data-step="details"]:not([hidden])', {timeout: 15000});

        await page.type('#first-name', 'Prueba');
        await page.type('#last-name', 'Navegador');
        await page.type('#email', 'zzbrowser_' + Date.now() + '@blondor.test');
        await page.type('#phone-number', '900000004');

        const accepts = await page.$$('#accept-to-terms-and-conditions');

        if (accepts.length) {
            await accepts[0].click();
        }

        const privacy = await page.$$('#accept-to-privacy-policy');

        if (privacy.length) {
            await privacy[0].click();
        }

        // 4. El paso de pago.
        await page.click('#bk-next');

        await page.waitForSelector('.bk-step[data-step="payment"]:not([hidden])', {timeout: 20000});

        const options = await page.$$eval('#bk-payment .bk-option', (items) =>
            items.map((item) => item.textContent.replace(/\s+/g, ' ').trim()),
        );

        check(options.length >= 1, 'el paso de pago muestra las opciones');
        console.log('     opciones: ' + JSON.stringify(options));

        const hasDeposit = options.some((text) => text.includes('Abono'));
        const hasFull = options.some((text) => text.includes('Pago completo'));

        check(hasDeposit, 'se puede pagar con abono');
        check(hasFull, 'se puede pagar el servicio completo');

        const total = await page.$eval('#bk-bar-text', (item) => item.textContent.replace(/\s+/g, ' ').trim());

        check(total.includes('$'), 'la barra inferior muestra el total: ' + total);

        // 5. Webpay (ambiente de integración).
        await page.click('#bk-next');

        await page.waitForNavigation({waitUntil: 'networkidle2', timeout: 30000});

        check(
            page.url().includes('transbank.cl'),
            'el navegador llega al formulario de Webpay (' + page.url().slice(0, 60) + ')',
        );

        if (new URL(page.url()).hostname !== 'webpay3gint.transbank.cl') {
            throw new Error('El formulario no está en el host de integración de Transbank.');
        }

        // La tarjeta de prueba del ambiente de integración de Transbank.
        await page.waitForSelector('#tarjetas', {timeout: 30000}).catch(() => null);

        const cardOption = await page.evaluateHandle(() => {
            const buttons = Array.from(document.querySelectorAll('button')).filter((item) => item.offsetParent !== null);

            return buttons.find((item) => /tarjeta|debit|crédit|credit/i.test(item.textContent));
        });

        if (cardOption.asElement()) {
            await cardOption.asElement().click();
        }

        await page.waitForSelector('#card-number', {timeout: 30000});

        await page.type('#card-number', '4051885600446623');

        const continueButton = await page.evaluateHandle(() => {
            const buttons = Array.from(document.querySelectorAll('button')).filter((item) => item.offsetParent !== null);

            return buttons.find((item) => item.textContent.trim() === 'Continuar');
        });

        await continueButton.asElement().click();

        await sleep(4000);

        await page.screenshot({path: '/tmp/blondor-browser-webpay.png', fullPage: true});

        const fields = await page.evaluate(() =>
            Array.from(document.querySelectorAll('input')).map((item) => ({
                id: item.id,
                type: item.type,
                placeholder: item.placeholder,
            })),
        );

        console.log('     campos: ' + JSON.stringify(fields));

        for (const field of fields) {
            if (!field.id) {
                continue;
            }

            const selector = '#' + field.id;

            if (field.id === 'card-cvv') {
                await page.type(selector, '123', {delay: 120});
            } else if (field.id === 'card-exp') {
                // El campo se formatea solo (MM/AA): se escriben los dígitos.
                await page.type(selector, '1227', {delay: 120});
            }
        }

        await sleep(1000);

        // El formulario de Webpay tiene varios pasos: se pulsa el botón visible hasta que vuelve al sitio.
        for (let step = 0; step < 4; step += 1) {
            const action = await page.evaluateHandle(() => {
                const buttons = Array.from(document.querySelectorAll('button')).filter(
                    (item) => item.offsetParent !== null && !item.disabled,
                );

                return buttons.find((item) => /^(pagar|continuar|confirmar)/i.test(item.textContent.trim()));
            });

            if (!action.asElement()) {
                break;
            }

            await action.asElement().click();

            await sleep(3500);

            if (page.url().includes('/booking_payment/result/')) {
                break;
            }
        }

        await sleep(3000);

        await page.screenshot({path: '/tmp/blondor-browser-webpay2.png', fullPage: true});

        console.log('     url: ' + page.url());

        if (page.url().includes('authenticator')) {
            console.log('     campos banco: ' + JSON.stringify(await page.evaluate(() =>
                Array.from(document.querySelectorAll('input')).map((item) => ({
                    name: item.name,
                    type: item.type,
                    id: item.id,
                })),
            )));
        }
        console.log('     texto: ' + (await page.evaluate(() => document.body.innerText)).slice(0, 300).replace(/\n/g, ' | '));

        // El banco de pruebas pide RUT y clave: se prueban los datos de prueba de Transbank.
        // Los datos de prueba del banco de integración (los de la documentación de Transbank).
        const credentials = process.env.WEBPAY_TEST_RUT
            ? [{rut: process.env.WEBPAY_TEST_RUT, clave: process.env.WEBPAY_TEST_CLAVE || '123'}]
            : [{rut: '11.111.111-1', clave: '123'}];

        for (const credential of credentials) {
            if (!page.url().includes('authenticator')) {
                break;
            }

            await page.evaluate((value) => {
                document.querySelector('#rutClient').value = value.rut;
                document.querySelector('#passwordClient').value = value.clave;
            }, credential);

            await page.evaluate(() => {
                const button = Array.from(document.querySelectorAll('button, input[type="submit"]')).find(
                    (item) => item.offsetParent !== null && !item.disabled,
                );

                if (button) {
                    button.click();
                }
            });

            await sleep(4000);
        }

        if (page.url().includes('authenticatorProcess')) {
            const html = await page.content();
            const index = html.indexOf('Aceptar');
            console.log('     html banco: ' + html.slice(Math.max(0, index - 700), index + 300).replace(/\s+/g, ' '));
        }

        // El banco pide confirmar la operación: "Aceptar" (un <select> con la opción TSY).
        if (await page.$('#vci')) {
            await page.select('#vci', 'TSY');

            await page.evaluate(() => {
                const input = document.querySelector('input[type="submit"]');

                if (input) {
                    input.click();
                }
            });

            await sleep(6000);
        }

        await page.waitForNavigation({waitUntil: 'networkidle2', timeout: 45000}).catch(() => null);

        await sleep(2000);

        await page.screenshot({path: '/tmp/blondor-browser-webpay3.png', fullPage: true}).catch(() => null);

        console.log('     url final: ' + page.url());
        console.log('     texto final: ' + (await page.evaluate(() => document.body.innerText)).slice(0, 400).replace(/\n/g, ' | '));

        // El banco de pruebas de Transbank pide un usuario registrado: sin esas credenciales el flujo no puede
        // terminar acá (la validación del pago del lado del servidor está en las pruebas de PHP).
        const bankText = await page.evaluate(() => document.body.innerText);

        if (/rut incorrecto|clave incorrecta|error/i.test(bankText)) {
            console.log('     (el banco de pruebas no acepta las credenciales: se omite la confirmación final)');
        }

        if (!/rut incorrecto|clave incorrecta|error/i.test(bankText)) {
            check(
                page.url().includes('/booking_payment/result/'),
                'vuelve a la página de resultado (' + page.url().slice(0, 80) + ')',
            );
        }

        const status = await page.$eval('[data-payment-status]', (item) => item.dataset.paymentStatus)
            .catch(() => null);

        if (status) {
            check(status === 'paid', 'el pago quedó confirmado (estado: ' + status + ')');

            const body = await page.evaluate(() => document.body.innerText);

            check(body.includes('Pagado'), 'la página muestra lo pagado');

            // 6. Reagendar la cita pagada: se cambia la hora sin cobrar de nuevo.
            await page.evaluate(() => {
                const link = Array.from(document.querySelectorAll('a')).find((item) =>
                    item.textContent.includes('Cambiar o cancelar'),
                );

                if (link) {
                    link.click();
                }
            });

            await page.waitForSelector('.bk-step[data-step="time"]:not([hidden])', {timeout: 20000});

            await page.waitForFunction(
                () => document.querySelectorAll('#bk-slots .bk-slot').length > 0,
                {timeout: 30000},
            );

            await page.evaluate(() => {
                const slots = document.querySelectorAll('#bk-slots .bk-slot');

                slots[Math.max(0, slots.length - 2)].click();
            });

            await sleep(600);

            await page.click('#bk-next');

            await page.waitForSelector('.bk-step[data-step="details"]:not([hidden])', {timeout: 15000});

            await page.click('#bk-next');

            await page.waitForSelector('.bk-step[data-step="payment"]:not([hidden])', {timeout: 20000});

            const paymentText = await page.evaluate(() => document.querySelector('#bk-payment').innerText);

            check(
                paymentText.includes('ya está pagada'),
                'la reagenda avisa que la cita ya está pagada',
            );

            await page.click('#bk-next');

            await page.waitForNavigation({waitUntil: 'networkidle2', timeout: 30000});

            check(
                page.url().includes('booking_confirmation/of/'),
                'la reagenda termina en la confirmación (' + page.url().slice(0, 80) + ')',
            );

            const confirmation = await page.evaluate(() => document.body.innerText);

            check(confirmation.includes('Pagado'), 'la confirmación sigue mostrando lo pagado');
        } else {
            check(false, 'No se verificó la aprobación final del banco de integración');
        }
    } catch (error) {
        await page.screenshot({path: '/tmp/blondor-browser-error.png', fullPage: true}).catch(() => null);

        console.log('     (pantalla de error en /tmp/blondor-browser-error.png)');

        failed.push('excepción: ' + error.message);
    } finally {
        await browser.close();
    }

    console.log();
    console.log('Pruebas de navegador: ' + passed + ' ok, ' + failed.length + ' con error.');

    return failed.length ? 1 : 0;
}

main()
    .then((code) => process.exit(code))
    .catch((error) => {
        console.error(error);
        process.exit(1);
    });
