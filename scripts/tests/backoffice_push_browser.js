/* Local synthetic account only; browser Push/Notification are stubs, no messages leave the test. */
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const credentials = JSON.parse(fs.readFileSync('/tmp/blondor-push-browser-account.json', 'utf8'));
const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    const port = 9500 + Math.floor(Math.random() * 300);
    const directory = fs.mkdtempSync(os.tmpdir() + '/blondor-push-browser-');
    const chrome = spawn('/usr/bin/google-chrome', ['--headless=new','--no-sandbox','--disable-dev-shm-usage',
        `--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'], {stdio:'ignore'});
    let ws;
    try {
        let target;
        for (let i=0;i<60;i++) {
            try { target=(await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find(item=>item.type==='page'); if(target)break; } catch (_) {}
            await sleep(200);
        }
        if(!target)throw new Error('Chrome no inició');
        ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve);ws.addEventListener('error',reject);});
        let id=0; const pending=new Map();
        ws.addEventListener('message',event=>{const result=JSON.parse(event.data);const request=pending.get(result.id);if(request){pending.delete(result.id);result.error?request.reject(new Error(result.error.message)):request.resolve(result.result);}});
        const send=(method,params={})=>new Promise((resolve,reject)=>{const seq=++id;pending.set(seq,{resolve,reject});ws.send(JSON.stringify({id:seq,method,params}));});
        const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
        await send('Page.enable'); await send('Runtime.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await send('Page.addScriptToEvaluateOnNewDocument',{source:`
            window.__pushRequests=0;
            const notification={permission:'default',requestPermission:async()=>{window.__pushRequests++;notification.permission='granted';return 'granted';}};
            Object.defineProperty(window,'Notification',{value:notification,configurable:true});
            let subscription=null;
            const registration={pushManager:{getSubscription:async()=>subscription,subscribe:async()=>{
                const key=document.querySelector('#backoffice-notifications').dataset.key;
                subscription={endpoint:'https://web.push.apple.com/zz-local-browser-'+Math.random(),toJSON(){return {endpoint:this.endpoint,keys:{p256dh:key,auth:btoa('1234567890123456').replace(/=/g,'')}};},unsubscribe:async()=>{subscription=null;return true;}};
                return subscription;
            }}};
            Object.defineProperty(navigator,'serviceWorker',{value:{register:async()=>registration,ready:Promise.resolve(registration)},configurable:true});
        `});
        await send('Page.navigate',{url:'http://localhost:8088/index.php/login'}); await sleep(1500);
        const logged=await evaluate(`(async()=>{const response=await fetch('/index.php/login/validate',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...${JSON.stringify(credentials)},csrf_token:vars('csrf_token')})});return (await response.json()).success;})()`);
        if(!logged)throw new Error('No se pudo ingresar con la fixture local');
        await send('Page.navigate',{url:'http://localhost:8088/index.php/backoffice_push'}); await sleep(1500);
        const before=await evaluate(`({requests:window.__pushRequests,ready:!document.querySelector('#push-enable').disabled,overflow:document.documentElement.scrollWidth>window.innerWidth})`);
        if(before.requests!==0||!before.ready||before.overflow)throw new Error('Inicialización incorrecta: '+JSON.stringify(before));
        await evaluate(`document.querySelector('#push-enable').click()`); await sleep(800);
        const enabled=await evaluate(`({requests:window.__pushRequests,text:document.querySelector('#push-status').textContent,visible:!document.querySelector('#push-disable').hidden})`);
        if(enabled.requests!==1||!enabled.visible||!enabled.text.includes('activadas para tu cuenta'))throw new Error('No se activaron los avisos: '+JSON.stringify(enabled));
        await evaluate(`document.querySelector('#push-disable').click()`); await sleep(600);
        if(!await evaluate(`document.querySelector('#push-status').textContent.includes('desactivadas')`))throw new Error('No se desactivaron');
        await send('Network.setUserAgentOverride',{userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'});
        await send('Page.navigate',{url:'http://localhost:8088/index.php/backoffice_push'}); await sleep(1200);
        const ios=await evaluate(`({requests:window.__pushRequests,disabled:document.querySelector('#push-enable').disabled,text:document.querySelector('#push-status').textContent})`);
        if(ios.requests!==0||!ios.disabled||!ios.text.includes('pantalla de inicio'))throw new Error('iOS sin instalar debe mostrar instrucciones');
        console.log('Push navegador: móvil sin desbordamiento; permiso solo tras pulsar; activar/desactivar y guía de instalación iPhone correctos. APIs push simuladas.');
    } finally {
        if(ws)ws.close(); chrome.kill();
    }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
