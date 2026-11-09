#!/usr/bin/env bash
# Build a throwaway rpg_5e the way a fresh compose stack does, for CI.
#
# Mirrors docker/initdb/00-load.sh step for step (schema, seed, content, then
# the migrations BASELINE folds, then the rest, then content again) but talks
# to a database over TCP instead of from inside the mysql container. If the
# two drift, the compose stack is the reference: change 00-load.sh first.
#
#   DB_HOST=127.0.0.1 DB_PORT=3306 MYSQL_ROOT_PASSWORD=... .github/scripts/ci-load-db.sh
#
# Only ever pointed at a database GitHub (or you) just created. schema.sql
# drops every table; never run this with production connection details.
set -euo pipefail
cd "$(dirname "$0")/../../src"

: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_NAME:=rpg_5e}"
: "${MYSQL_ROOT_PASSWORD:?set MYSQL_ROOT_PASSWORD (the CI service password)}"

case "$DB_HOST" in
    127.0.0.1|localhost|::1) ;;
    *) echo "ci-load-db: refusing a non-local DB_HOST ($DB_HOST)" >&2; exit 2 ;;
esac

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
ROOT="mysql --default-character-set=utf8mb4 -h $DB_HOST -P $DB_PORT -uroot"

load() {
    echo "[ci-load-db] loading $1"
    $ROOT < "sql/$1"
}

for f in schema.sql seed_data.sql content.sql; do
    load "$f"
done

folded=()
while read -r name _; do
    case "$name" in ''|'#'*) continue ;; esac
    folded+=("$name")
done < sql/migrations/BASELINE

echo "[ci-load-db] migrations: baseline ${#folded[@]} folded"
bash sql/migrate.sh --mysql "$ROOT" --database "$DB_NAME" --baseline "${folded[@]}"
echo "[ci-load-db] migrations: apply the rest"
bash sql/migrate.sh --mysql "$ROOT" --database "$DB_NAME" --apply

load content.sql
echo "[ci-load-db] done"
