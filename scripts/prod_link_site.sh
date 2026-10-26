#!/usr/bin/env bash
# Una sola vez en producción: blondor.cl pasa a servirse desde el clon del repo, igual que la agenda.
#   ~/public_html/index.html  -> ~/blondor/site/index.html   (y lo mismo con cada entrada de site/)
# Lo que había en public_html se mueve a ~/respaldo_sitio_<fecha>/ (no se borra nada).
# Se ejecuta desde el equipo local:
#   ssh blondor.web 'bash -s' < scripts/prod_link_site.sh
set -euo pipefail

SITE="$HOME/blondor/site"
WEB="$HOME/public_html"
BACKUP="$HOME/respaldo_sitio_$(date +%Y%m%d_%H%M%S)"

if [ ! -d "$SITE" ]; then
  echo "No existe $SITE: primero haz git pull en ~/blondor." >&2
  exit 1
fi

mkdir -p "$BACKUP"

for src in "$SITE"/* "$SITE"/.[!.]*; do
  [ -e "$src" ] || continue
  name="$(basename "$src")"
  dst="$WEB/$name"

  if [ -L "$dst" ] && [ "$(readlink "$dst")" = "$src" ]; then
    echo "ya enlazado: $name"
    continue
  fi

  if [ -e "$dst" ] || [ -L "$dst" ]; then
    mv "$dst" "$BACKUP/"
  fi

  ln -s "$src" "$dst"
  echo "enlazado: $name -> $src"
done

rmdir "$BACKUP" 2>/dev/null && echo "Nada que respaldar." || echo "Respaldo en: $BACKUP"
