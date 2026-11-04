#!/usr/bin/env python3
"""Local-only authenticated push checks; synthetic staff, no push sends, no reservations or email."""
import base64, hashlib, json, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

base = 'http://localhost:8088/index.php/'
run = secrets.token_hex(6)
credentials = {'username': 'zz_session_http_' + run, 'password': secrets.token_urlsafe(24)}

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
$db->query("INSERT INTO ea_users (first_name,last_name,email,id_roles,timezone,language) VALUES ('zz_test_session_http','Test','".$input['username']."@blondor.test',1,'America/Santiago','spanish')");
$id=$db->insert_id; $salt=bin2hex(random_bytes(32));
$half=(int)(strlen($salt)/2); $hash=hash('sha256',substr($salt,0,$half).$input['password'].substr($salt,$half));
for($i=0;$i<100000;$i++){$hash=hash('sha256',$hash);}
$stmt=$db->prepare("INSERT INTO ea_user_settings(id_users,username,password,salt,notifications,google_sync,caldav_sync) VALUES (?,?,?,?,'0',0,0)");
$stmt->bind_param('isss',$id,$input['username'],$hash,$salt);$stmt->execute();echo $id;
''' % encoded))
checks = 0
try:
    code,body=request('backoffice_session/status')
    assert code==401 and json.loads(body)=={'authenticated':False}; checks+=1
    code,body=request('login'); csrf=variables(body)['csrf_token']
    code,body=request('login/validate',dict(credentials,csrf_token=csrf))
    assert code==200 and json.loads(body)['success']; checks+=1
    code,body=request('agenda'); csrf=variables(body)['csrf_token']
    assert code==200 and 'utils/backoffice_session' in body; checks+=1
    code,body=request('backoffice_session/status')
    assert code==200 and json.loads(body)=={'authenticated':True}; checks+=1
    # Only mutate this synthetic user's session, identified by serialized ownership.
    fixture=f"storage/sessions/ea_session*"
    session_path=php(f"foreach(glob('{fixture}') as $file){{if(str_contains(file_get_contents($file),'user_id|i:{user_id};')){{echo $file;break;}}}}")
    assert re.fullmatch(r'storage/sessions/ea_session[a-zA-Z0-9,-]+',session_path),session_path
    php(f"touch('{session_path}',time()-30);")
    before=php(f"echo filemtime('{session_path}');")
    code,body=request('backoffice_session/status')
    after=php(f"echo filemtime('{session_path}');")
    assert code==200 and before==after,('probe extended session',before,after); checks+=1
    php(f"$p='{session_path}';$s=file_get_contents($p);if(!str_contains($s,'user_id|i:{user_id};'))exit(1);$s=preg_replace('/backoffice_last_activity\\|i:[0-9]+;/', 'backoffice_last_activity|i:'.(time()-7260).';',$s,1,$count);if($count!==1)exit(2);file_put_contents($p,$s);")
    code,body=request('backoffice_session/status')
    assert code==401 and not json.loads(body)['authenticated']; checks+=1
    code,body=request('agenda/day',{'csrf_token':csrf,'date':'2026-11-03'})
    assert code==401,code; checks+=1
    code,body=request('login'); csrf=variables(body)['csrf_token']
    code,body=request('login/validate',dict(credentials,csrf_token=csrf))
    assert code==200 and json.loads(body)['success']; checks+=1
    code,body=request('agenda'); assert code==200 and 'utils/backoffice_session' in body; checks+=1
    code,body=request('booking'); assert code==200 and 'utils/backoffice_session' not in body; checks+=1
    request('logout')
    print(f'Session HTTP: {checks} comprobaciones correctas; sin reservas, correos ni pagos.')
finally:
    php(f"$db->query('DELETE FROM ea_user_settings WHERE id_users={user_id}');$db->query('DELETE FROM ea_users WHERE id={user_id}');")
