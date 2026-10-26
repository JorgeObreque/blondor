#!/usr/bin/env bash
# Con DEBUG_MODE=false la app carga los .min.js / .min.css. No hay minificador en el servidor,
# así que se publica una copia del archivo fuente con el nombre .min (funciona igual).
set -euo pipefail
cd "$(dirname "$0")/../agenda"

for src in \
  assets/js/pages/agenda.js \
  assets/js/http/agenda_http_client.js \
  assets/css/pages/agenda.css \
  assets/js/pages/service_prices.js \
  assets/js/http/service_prices_http_client.js \
  assets/css/pages/service_prices.css \
  assets/js/pages/register_service.js \
  assets/js/http/register_service_http_client.js \
  assets/js/pages/providers.js \
  assets/js/pages/booking.js \
  assets/js/http/booking_http_client.js \
  assets/css/pages/booking.css; do
  dst="${src%.*}.min.${src##*.}"
  cp "$src" "$dst"
  echo "$dst"
done
