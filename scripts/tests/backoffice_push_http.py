#!/usr/bin/env python3
"""Local-only authenticated push checks; synthetic staff, no push sends, no reservations or email."""
import base64, hashlib, json, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

base = 'http://localhost:8088/index.php/'
run = secrets.token_hex(6)
credentials = {'username': 'zz_push_http_' + run, 'password': secrets.token_urlsafe(24)}

def php(code):
    script = '''<?php
require 'config.php';
if (Config::DB_HOST !== 'db' || Config::DB_NAME !== 'agenda' || Config::WEBPAY_ENVIRONMENT !== 'integration') { exit(1); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(Config::DB_HOST,Config::DB_USERNAME,Config::DB_PASSWORD,Config::DB_NAME);
$db->set_charset('utf8mb4');
''' + code
    return subprocess.run(['docker','compose','exec','-T','app','php'], input=script, text=True, capture_output=True, check=True).stdout

client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
def request(path, data=None):
    req = urllib.request.Request(base + path, data=None if data is None else urllib.parse.urlencode(data).encode())
    try:
        with client.open(req, timeout=20) as response: return response.status, response.read().decode()
    except urllib.error.HTTPError as error: return error.code, error.read().decode()
def variables(body):
    return json.loads(re.search(r'const vars = (\{.*?\});', body, re.S).group(1))

encoded = base64.b64encode(json.dumps(credentials).encode()).decode()
user_id = int(php('''
$input=json_decode(base64_decode('%s'),true);
$db->query("INSERT INTO ea_users (first_name,last_name,email,id_roles,timezone,language) VALUES ('zz_test_push_http','Test','".$input['username']."@blondor.test',1,'America/Santiago','spanish')");
$id=$db->insert_id; $salt=bin2hex(random_bytes(32));
$half=(int)(strlen($salt)/2); $hash=hash('sha256',substr($salt,0,$half).$input['password'].substr($salt,$half));
for($i=0;$i<100000;$i++){$hash=hash('sha256',$hash);}
$stmt=$db->prepare("INSERT INTO ea_user_settings(id_users,username,password,salt,notifications,google_sync,caldav_sync) VALUES (?,?,?,?,'0',0,0)");
$stmt->bind_param('isss',$id,$input['username'],$hash,$salt);$stmt->execute();echo $id;
''' % encoded))
checks = 0
try:
    code, body = request('booking'); csrf = variables(body)['csrf_token']
    assert code == 200 and 'rel="manifest"' not in body; checks += 1
    for action in ['status','subscribe','unsubscribe']:
        code,_=request('backoffice_push/'+action, {'csrf_token':csrf})
        assert code == 403,(action,code); checks += 1
    code,body=request('backoffice_push/manifest'); manifest=json.loads(body)
    assert code==200 and manifest['display']=='standalone' and '/agenda' in manifest['start_url']; checks += 1
    code,body=request('login'); csrf=variables(body)['csrf_token']
    code,body=request('login/validate', dict(credentials,csrf_token=csrf))
    assert code==200 and json.loads(body)['success']; checks += 1
    code,body=request('backoffice_push'); values=variables(body); csrf=values['csrf_token']
    assert code==200 and 'push-enable' in body and 'privateKey' not in body; checks += 1
    key=re.search(r'data-key="([A-Za-z0-9_-]+)"',body).group(1)
    endpoint='https://web.push.apple.com/zz-local-http-'+run
    subscription={'endpoint':endpoint,'keys':{'p256dh':key,'auth':base64.urlsafe_b64encode(secrets.token_bytes(16)).decode().rstrip('=')}}
    for action in ['status','subscribe','unsubscribe']:
        code,_=request('backoffice_push/'+action)
        assert code==405,(action,code); checks += 1
        code,_=request('backoffice_push/'+action,{})
        assert code==403,(action,'csrf',code); checks += 1
    code,body=request('backoffice_push/status', {'csrf_token':csrf})
    assert code==200 and json.loads(body)=={'available':True,'subscribed':False}; checks += 1
    bad=dict(subscription,endpoint='http://127.0.0.1/private')
    code,_=request('backoffice_push/subscribe', {'subscription':json.dumps(bad),'csrf_token':csrf})
    assert code==400; checks += 1
    code,body=request('backoffice_push/subscribe', {'subscription':json.dumps(subscription),'id_users':5,'csrf_token':csrf})
    assert code==200 and json.loads(body)['success']; checks += 1
    owner=php("echo $db->query(\"SELECT id_users FROM ea_push_subscriptions WHERE endpoint_hash='"+hashlib.sha256(endpoint.encode()).hexdigest()+"'\")->fetch_row()[0];")
    assert int(owner)==user_id; checks += 1
    code,body=request('backoffice_push/status',{'endpoint':endpoint,'csrf_token':csrf})
    assert json.loads(body)['subscribed']; checks += 1
    code,body=request('backoffice_push/open/999999999'); assert code==404; checks += 1
    code,body=request('logout'); assert code==200; checks += 1
    enabled=php(f'echo $db->query("SELECT enabled FROM ea_push_subscriptions WHERE id_users={user_id}")->fetch_row()[0];')
    assert enabled.strip()=='0'; checks += 1
    Path('/tmp/blondor-push-browser-account.json').write_text(json.dumps(credentials))
    Path('/tmp/blondor-push-browser-account.json').chmod(0o600)
    browser = Path('scripts/tests/backoffice_push_browser.js')
    if browser.exists():
        subprocess.run(['node',str(browser)], check=True)
    print(f'Push HTTP: {checks} comprobaciones correctas; sin enviar avisos, reservas ni correos.')
finally:
    php(f"$db->query('DELETE FROM ea_push_deliveries WHERE id_users={user_id}');$db->query('DELETE FROM ea_push_subscriptions WHERE id_users={user_id}');$db->query('DELETE FROM ea_user_settings WHERE id_users={user_id}');$db->query('DELETE FROM ea_users WHERE id={user_id}');")
    account=Path('/tmp/blondor-push-browser-account.json')
    if account.exists(): account.unlink()
