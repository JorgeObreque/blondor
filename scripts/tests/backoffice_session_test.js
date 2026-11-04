/* Node-only session regression tests. No network or browser dependencies. */
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('agenda/assets/js/utils/backoffice_session.js', 'utf8');
function fixture(fetch) {
    const events = {}, redirects = [];
    const document = {visibilityState:'visible',addEventListener:(name,fn)=>{events[name]=fn;}};
    const window = {fetch,location:{href:'http://localhost/index.php/agenda',origin:'http://localhost',replace:url=>redirects.push(url)},addEventListener:(name,fn)=>{events[name]=fn;},setInterval:fn=>{events.interval=fn;}};
    const App = {Utils:{Url:{siteUrl:path=>'http://localhost/index.php/'+path}}};
    vm.runInNewContext(source,{window,document,App,URL,$:()=>({ajaxError:fn=>{events.ajaxError=fn;}})});
    return {api:App.Utils.BackofficeSession,events,redirects,window,document};
}
const response = (status,authenticated)=>({status,ok:status===200,json:async()=>({authenticated})});
(async()=>{
    let calls=0;
    const expired=fixture(async()=>{calls++;return response(401,false);});
    await Promise.all([expired.api.handleResponse({status:403}),expired.api.handleResponse({status:401})]);
    assert.equal(calls,1);assert.deepEqual(expired.redirects,['http://localhost/index.php/login']);
    await expired.api.check();assert.equal(calls,1);
    const valid=fixture(async()=>response(200,true));
    assert.equal(await valid.api.handleResponse({status:403}),true);assert.equal(valid.redirects.length,0);
    for(const fetch of [async()=>{throw Error('offline');},async()=>response(500)]){
        const broken=fixture(fetch);assert.equal(await broken.api.check(),null);assert.equal(broken.redirects.length,0);
    }
    calls=0;
    const resume=fixture(async()=>{calls++;return response(200,true);});
    resume.events.DOMContentLoaded();await resume.api.check();
    for(const event of ['focus','pageshow','online','visibilitychange','interval']){
        const before=calls;resume.events[event]();await resume.api.check();assert.equal(calls,before+1,event);
    }
    resume.document.visibilityState='hidden';const before=calls;resume.events.visibilitychange();assert.equal(calls,before);
    const requests=[];
    const mutation=fixture(async(url,options)=>{requests.push([url,options]);return response(url.endsWith('/status')?401:403,false);});
    await mutation.window.fetch('http://localhost/index.php/agenda/set_status',{method:'POST'});
    assert.equal(requests.length,2);assert.equal(requests.filter(([,opts])=>opts.method==='POST').length,1);
    assert.equal(mutation.redirects.length,1);
    console.log('Session JS: redirección única, permisos, desconexión, reactivación y POST sin reintentos correctos.');
})().catch(error=>{console.error(error);process.exitCode=1;});
