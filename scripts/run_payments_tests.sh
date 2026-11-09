#!/usr/bin/env bash
# Suite aislada: solo esquema y settings/roles locales; ningún dato de clientas.
# Las bases se conservan para revisar resultados. No se borran bases ni volúmenes.
set -euo pipefail
cd "$(dirname "$0")/.."
TEST_DB="agenda_payments_test_$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
echo "Base aislada: $TEST_DB"
docker compose exec -T db mariadb -uroot -proot -e "CREATE DATABASE $TEST_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $TEST_DB.* TO 'agenda'@'%';"
docker compose exec -T db mariadb-dump -uroot -proot --no-data agenda | docker compose exec -T db mariadb -uroot -proot "$TEST_DB"
docker compose exec -T db mariadb-dump -uroot -proot --no-create-info agenda ea_settings ea_roles ea_migrations | docker compose exec -T db mariadb -uroot -proot "$TEST_DB"
docker compose exec -T app php index.php console test_payments "$TEST_DB"
docker compose exec -T app php index.php console test_push "$TEST_DB"
docker compose exec -T app php index.php console test_reminders "$TEST_DB"
docker compose exec -T app php index.php console test_promo "$TEST_DB"
echo "Resultados conservados en $TEST_DB. HTTP/navegador requieren verificación separada."
