#!/usr/bin/env bash
# Refresca la copia local desde producción (solo lectura en prod).
#   scripts/sync_from_prod.sh db     -> baja un dump nuevo de la BD y recrea la BD local
#   scripts/sync_from_prod.sh code   -> baja el código (no pisa config.php local)
set -euo pipefail
cd "$(dirname "$0")/.."
export SSH_AUTH_SOCK="${SSH_AUTH_SOCK:-$HOME/.ssh/agent.sock}"

case "${1:-}" in
  db)
    umask 077
    ssh blondor.web 'mysqldump --defaults-extra-file=~/.my_agenda.cnf --single-transaction --routines --triggers --no-tablespaces cbl116984_blondorc_agenda' \
      | gzip > docker/db/init/01_prod.sql.gz
    echo "Dump guardado: $(du -h docker/db/init/01_prod.sql.gz | cut -f1)"
    docker compose down -v db >/dev/null 2>&1 || true
    docker compose up -d
    echo "BD local recreada con los datos de producción."
    ;;
  code)
    ssh blondor.web 'tar czf - -C public_html --exclude="agenda/storage/sessions/*" --exclude="agenda/storage/cache/*" --exclude="agenda/storage/logs/*" --exclude=agenda/.well-known --exclude=agenda/cgi-bin --exclude=agenda/scrapper-mangas --exclude=agenda/.ftpquota --exclude=agenda/config.php agenda' \
      | tar xzf - -C .
    echo "Código actualizado. Revisa los cambios con: git status"
    ;;
  *)
    echo "Uso: $0 db|code" >&2; exit 1 ;;
esac
