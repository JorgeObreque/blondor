#!/usr/bin/env python3
"""Local read/validation smoke test. Never creates holds, transactions or appointments."""
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

base = 'http://localhost:8088/index.php/'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))

def request(path, data=None):
    req = urllib.request.Request(base + path, data=None if data is None else urllib.parse.urlencode(data).encode())
    try:
        with client.open(req, timeout=20) as response:
            return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()

code, body = request('booking')
assert code == 200, code
variables = json.loads(re.search(r'const vars = (\{.*?\});', body, re.S).group(1))
csrf = variables['csrf_token']
checks = 1
for endpoint in ['prepare', 'checkout', 'confirm', 'release']:
    code, _ = request('booking_payment/' + endpoint)
    assert code == 405, (endpoint, 'GET', code)
    code, _ = request('booking_payment/' + endpoint, {})
    assert code == 403, (endpoint, 'CSRF', code)
    checks += 2
code, _ = request('booking_payment/prepare', {'csrf_token': csrf})
assert code == 400, ('prepare invalid', code)
code, _ = request('booking/register', {})
assert code == 400, ('legacy', code)
print(f'{checks + 2} comprobaciones HTTP de seguridad correctas; sin escrituras ni llamadas a Webpay.')
