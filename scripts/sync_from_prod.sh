#!/usr/bin/env bash
# Refresca la copia local desde producción (solo lectura en prod).
#   scripts/sync_from_prod.sh db     -> baja un dump nuevo de la BD y recrea la BD local
#   scripts/sync_from_prod.sh code   -> baja el código de la agenda (no pisa config.php local)
#   scripts/sync_from_prod.sh site   -> baja el sitio blondor.cl (public_html sin la agenda ni restos del hosting)
set -euo pipefail
cd "$(dirname "$0")/.."
export SSH_AUTH_SOCK="${SSH_AUTH_SOCK:-$HOME/.ssh/agent.sock}"

case "${1:-}" in
  db)
    umask 022  # el contenedor de MariaDB corre con otro uid y necesita leer el dump
    ssh blondor.web 'mysqldump --defaults-extra-file=~/.my_agenda.cnf --single-transaction --routines --triggers --no-tablespaces cbl116984_blondorc_agenda' \
      | gzip > docker/db/init/01_prod.sql.gz
    echo "Dump guardado: $(du -h docker/db/init/01_prod.sql.gz | cut -f1)"
    docker compose rm -sfv db >/dev/null 2>&1 || true
    docker volume rm -f "$(basename "$PWD")_db_data" >/dev/null 2>&1 || true
    docker compose up -d
    echo -n "Importando"; until docker compose exec -T db mariadb -uagenda -pagenda agenda -e "SELECT 1 FROM ea_webhooks LIMIT 1" >/dev/null 2>&1; do echo -n "."; sleep 2; done; echo
    echo "BD local recreada con los datos de producción."
    ;;
  code)
    ssh blondor.web 'tar czf - -C public_html --exclude="agenda/storage/sessions/*" --exclude="agenda/storage/cache/*" --exclude="agenda/storage/logs/*" --exclude=agenda/.well-known --exclude=agenda/cgi-bin --exclude=agenda/scrapper-mangas --exclude=agenda/.ftpquota --exclude=agenda/config.php agenda' \
      | tar xzf - -C .
    echo "Código actualizado. Revisa los cambios con: git status"
    ;;
  site)
    # includes/SVG (873 MB) no lo usa ninguna página; home.html e index2.html son páginas de relleno del hosting.
    ssh blondor.web 'tar czf - -C public_html --exclude=./agenda --exclude=./agenda_old --exclude=./cgi-bin --exclude=./.well-known --exclude=./.ftpquota --exclude=./includes/SVG --exclude=./home.html --exclude=./index2.html .' \
      | tar xzf - -C site
    echo "Sitio actualizado. Revisa los cambios con: git status site"
    ;;
  *)
    echo "Uso: $0 db|code|site" >&2; exit 1 ;;
esac
