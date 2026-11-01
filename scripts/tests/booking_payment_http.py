#!/usr/bin/env python3
"""
Blondor - Prueba HTTP de la reserva con pago (solo local).

Recorre el flujo público contra la app local (http://localhost:8088 por defecto):

  1. Carga la página de reserva (toma el token CSRF y el catálogo).
  2. Pide una hora libre.
  3. Prepara la reserva (el servidor aparta el horario y devuelve las opciones de pago).
  4. Intenta mandar un precio alterado: el servidor lo ignora.
  5. Pide el checkout (crea la transacción en Webpay, ambiente de integración).
  6. Simula la vuelta de Webpay sin pagar: la cita NO se crea y el horario se suelta.
  7. Comprueba que la página de resultado muestra el estado.

No mueve dinero: la transacción de Webpay queda "iniciada" en el ambiente de integración y expira sola.

Uso:  python3 scripts/tests/booking_payment_http.py [http://localhost:8088]
"""

import datetime
import json
import re
import uuid
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

BASE_URL = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8088"
parsed_base = urllib.parse.urlparse(BASE_URL)
if parsed_base.scheme != "http" or parsed_base.hostname not in ("localhost", "127.0.0.1") or parsed_base.port != 8088:
    raise SystemExit("Estas pruebas solo admiten http://localhost:8088 o http://127.0.0.1:8088")
INDEX = BASE_URL + "/index.php/"

passed = 0
failed = []


def check(condition, message):
    global passed
    if condition:
        passed += 1
        print("ok   " + message)
    else:
        failed.append(message)
        print("FAIL " + message)


def encode(data, prefix=""):
    """Form data with nested names (post_data[appointment][start_datetime]=…)."""
    parts = []

    for key, value in data.items():
        name = prefix + "[" + key + "]" if prefix else key

        if isinstance(value, dict):
            parts.append(encode(value, name))
        elif isinstance(value, bool):
            parts.append(urllib.parse.quote_plus(name) + "=" + ("1" if value else "0"))
        elif isinstance(value, (list, tuple)):
            for index, item in enumerate(value):
                parts.append(encode(item, name + "[" + str(index) + "]"))
        else:
            parts.append(urllib.parse.quote_plus(name) + "=" + urllib.parse.quote_plus(str(value)))

    return "&".join(parts)


class Client:
    def __init__(self):
        self.jar = CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            urllib.request.HTTPRedirectHandler(),
        )

    def get(self, path):
        request = urllib.request.Request(INDEX + path, headers={"User-Agent": "blondor-tests"})
        with self.opener.open(request, timeout=30) as response:
            return response.getcode(), response.read().decode("utf-8", "replace")

    def post(self, path, data, csrf=None):
        payload = dict(data)
        if csrf is not None:
            payload["csrf_token"] = csrf
        encoded = encode(payload).encode("utf-8")
        request = urllib.request.Request(
            INDEX + path,
            data=encoded,
            headers={"User-Agent": "blondor-tests", "Content-Type": "application/x-www-form-urlencoded"},
        )
        try:
            with self.opener.open(request, timeout=60) as response:
                return response.getcode(), response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace")


def vars_of(html):
    match = re.search(r"const vars = (\{.*?\});", html, re.S)
    if not match:
        raise RuntimeError("No se encontró la información de la página de reserva.")
    return json.loads(match.group(1))


def main():
    client = Client()

    # Cada corrida usa sus propias llaves de idempotencia.
    run_id = "zzhttp" + uuid.uuid4().hex[:12]

    code, html = client.get("booking")
    check(code == 200, "la página de reserva carga")
    vars = vars_of(html)
    csrf = vars["csrf_token"]
    check(bool(csrf), "la página trae su token CSRF")

    services = [s for s in vars["available_services"] if not s["variants"] and float(s["price"]) > 0]
    check(bool(services), "hay servicios públicos con precio")

    service = sorted(services, key=lambda s: -float(s["price"]))[0]
    provider_id = vars["available_providers"][0]["id"]
    price = float(service["price"])
    print(f"     servicio: {service['name']} ({price})")
    print(f"     reservation_id: {run_id}")

    # Una hora libre de los próximos días (dentro del límite de la agenda).
    hours = []
    date = ""
    day_offset = 0

    for offset in range(1, 45):
        candidate = (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()
        code, body = client.post(
            "booking/get_available_hours",
            {
                "service_id": service["id"],
                "hair_length": "",
                "provider_id": provider_id,
                "selected_date": candidate,
                "manage_mode": 0,
            },
            csrf,
        )
        candidate_hours = json.loads(body) if body.strip().startswith("[") else []

        if candidate_hours:
            hours = candidate_hours
            date = candidate
            day_offset = offset
            break

    check(isinstance(hours, list) and bool(hours), "las horas libres se pueden pedir")

    if not hours:
        print("     (no hay horas libres: la agenda local está llena)")
        return 1

    # Se usa la última hora del día (las primeras se quedan ocupadas por las corridas anteriores).
    print(f"     día principal: {date} hora: {hours[-1]}")
    start = f"{date} {hours[-1]}:00"

    # Otro día para probar el precio alterado sin chocar con la reserva anterior.
    other_date = ""
    other_hours = []

    for offset in range(day_offset + 1, 60):
        candidate = (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()
        code, body = client.post(
            "booking/get_available_hours",
            {
                "service_id": service["id"],
                "hair_length": "",
                "provider_id": provider_id,
                "selected_date": candidate,
                "manage_mode": 0,
            },
            csrf,
        )
        candidate_hours = json.loads(body) if body.strip().startswith("[") else []

        if candidate_hours:
            other_date = candidate
            other_hours = candidate_hours
            break

    post_data = {
        "appointment": {
            "start_datetime": start,
            "end_datetime": start,
            "notes": "prueba http",
            "is_unavailability": False,
            "id_users_provider": provider_id,
            "id_services": service["id"],
            "hair_length": "",
        },
        "customer": {
            "first_name": "Prueba",
            "last_name": "Local",
            "email": run_id + "@blondor.test",
            "phone_number": "+56900000003",
            "address": "",
            "city": "",
            "zip_code": "",
            "timezone": "America/Santiago",
        },
        "manage_mode": 0,
    }

    # Sin CSRF no se puede preparar una reserva.
    code, body = client.post(
        "booking_payment/prepare",
        {"post_data": post_data, "idempotency_key": run_id + "nocsrf"},
        None,
    )
    check(code == 403, "sin token CSRF la reserva no se puede preparar")

    # Sin CSRF tampoco se puede cobrar.
    code, body = client.post(
        "booking_payment/checkout",
        {"hold_token": "cualquiera", "mode": "deposit", "idempotency_key": run_id + "nocsrf2"},
        None,
    )
    check(code == 403, "sin token CSRF el checkout no se puede hacer")

    # La vía pública vieja está cerrada: no crea citas sin pago.
    legacy_hours = other_hours[:-1] if len(other_hours) > 1 else []
    legacy_post = json.loads(json.dumps(post_data))
    legacy_start = ""
    legacy_date = ""

    if legacy_hours:
        legacy_date = other_date
        legacy_start = f"{other_date} {legacy_hours[-1]}:00"
        legacy_post["appointment"]["start_datetime"] = legacy_start
        legacy_post["appointment"]["end_datetime"] = legacy_start
    else:
        legacy_date = date
        legacy_start = f"{date} {hours[0]}:00"
        legacy_post["appointment"]["start_datetime"] = legacy_start
        legacy_post["appointment"]["end_datetime"] = legacy_start

    code, body = client.post("booking/register", {"post_data": legacy_post}, csrf)
    legacy = json.loads(body) if body.strip().startswith("{") else {}
    check(
        code >= 400 and "pago" in str(legacy.get("message", "")).lower(),
        "la vía pública vieja está cerrada y manda al flujo con pago",
    )

    # La hora que intentó usar la vía vieja sigue libre: no se creó ninguna cita.
    code, body = client.post(
        "booking/get_available_hours",
        {
            "service_id": service["id"],
            "hair_length": "",
            "provider_id": provider_id,
            "selected_date": legacy_date,
            "manage_mode": 0,
        },
        csrf,
    )
    legacy_available = json.loads(body) if body.strip().startswith("[") else []
    check(
        legacy_start[11:16] in legacy_available,
        "la vía vieja no creó ninguna cita (la hora sigue libre)",
    )

    code, body = client.post(
        "booking_payment/prepare",
        {"post_data": post_data, "idempotency_key": run_id + "prepare1"},
        csrf,
    )
    booking = json.loads(body) if body.strip().startswith("{") else {}
    if not booking.get("hold_token"):
        print("     respuesta: " + body[:400])
    check(bool(booking.get("hold_token")), "la reserva se prepara y devuelve su token")
    check(
        booking.get("payment_required") is True,
        "una reserva nueva necesita pago",
    )

    # La hora apartada ya no se ofrece.
    code, body = client.post(
        "booking/get_available_hours",
        {
            "service_id": service["id"],
            "hair_length": "",
            "provider_id": provider_id,
            "selected_date": date,
            "manage_mode": 0,
        },
        csrf,
    )
    held_hours = json.loads(body) if body.strip().startswith("[") else []
    check(
        hours[-1] not in held_hours,
        "la hora apartada por una clienta que está pagando no se ofrece",
    )

    options = booking.get("options") or []
    check(len(options) >= 1, "la reserva trae opciones de pago")

    deposit = 15000 if price >= 30000 else 8000
    deposit = min(deposit, price)

    by_mode = {option["mode"]: option for option in options}
    check("deposit" in by_mode, "se puede pagar con abono")
    check(abs(float(by_mode.get("deposit", {}).get("amount", 0)) - deposit) < 1, "el abono es el de la política")
    check(
        abs(float(by_mode.get("deposit", {}).get("due", 0)) - (price - deposit)) < 1,
        "el saldo es el total menos el abono",
    )
    if abs(price - deposit) > 0.01:
        check("full" in by_mode, "se puede pagar el servicio completo")
        check(abs(float(by_mode.get("full", {}).get("amount", 0)) - price) < 1, "el pago completo es el total")

    # Un precio alterado en el navegador no cambia nada (en otra hora, para no chocar con la reserva anterior).
    tampered = json.loads(json.dumps(post_data))
    tampered["appointment"]["start_datetime"] = f"{other_date} {other_hours[-1]}:00"
    tampered["appointment"]["end_datetime"] = f"{other_date} {other_hours[-1]}:00"
    tampered["appointment"]["price"] = 1
    tampered["appointment"]["amount"] = 1
    code, body = client.post(
        "booking_payment/prepare",
        {"post_data": tampered, "idempotency_key": run_id + "prepare2"},
        csrf,
    )
    tampered_booking = json.loads(body) if body.strip().startswith("{") else {}
    if not tampered_booking.get("options"):
        print("     respuesta: " + body[:300])
        print("     otro día: " + other_date + " " + str(other_hours[:3]))
    tampered_options = {option["mode"]: option for option in (tampered_booking.get("options") or [])}
    check(
        abs(float(tampered_options.get("deposit", {}).get("amount", 0)) - deposit) < 1,
        "el precio alterado por el navegador se ignora",
    )

    # El checkout crea la transacción en Webpay (integración).
    code, body = client.post(
        "booking_payment/checkout",
        {
            "hold_token": booking["hold_token"],
            "mode": "deposit",
            "idempotency_key": run_id + "pay1",
        },
        csrf,
    )
    payment = json.loads(body) if body.strip().startswith("{") else {}
    if not payment.get("token"):
        print("     respuesta: " + body[:400])
    check(bool(payment.get("token")), "el checkout devuelve el token de Webpay")
    check("webpay" in str(payment.get("url", "")), "el checkout devuelve la URL de Webpay")
    check(
        abs(float(payment.get("amount", 0)) - deposit) < 1,
        "el monto del pago lo pone el servidor",
    )

    # Doble clic: el mismo intento devuelve la misma transacción.
    code, body = client.post(
        "booking_payment/checkout",
        {
            "hold_token": booking["hold_token"],
            "mode": "deposit",
            "idempotency_key": run_id + "pay1",
        },
        csrf,
    )
    again = json.loads(body) if body.strip().startswith("{") else {}
    check(again.get("token") == payment.get("token"), "el doble clic no crea otra transacción")

    # La vuelta de Webpay sin pagar: no se crea ninguna cita.
    code, body = client.post(
        "booking/webpay_return",
        {"token_ws": payment.get("token", ""), "TBK_ORDEN_COMPRA": "ZZTEST"},
        None,
    )
    check(code == 200, "la vuelta de Webpay responde")

    code, html = client.get("booking_payment/result/" + booking["hold_token"])
    check(code == 200, "la página de resultado carga")
    check("data-payment-status" in html, "la página de resultado muestra el estado del pago")
    check(
        re.search(r'data-payment-status="(paid|rejected|expired|attention|pending)"', html) is not None,
        "el estado del pago es uno de los conocidos",
    )

    # Se sueltan las reservas de la prueba (también la que se preparó con el precio alterado).
    for token in [booking.get("hold_token"), tampered_booking.get("hold_token")]:
        if not token:
            continue
        code, body = client.post(
            "booking_payment/release",
            {"hold_token": token},
            csrf,
        )
        check(code == 200, "la reserva se puede soltar")

    print()
    print(f"Pruebas HTTP: {passed} ok, {len(failed)} con error.")

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
