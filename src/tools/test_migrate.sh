#!/bin/bash
# sql/migrate.sh, and the fresh build that uses it, against throwaway MySQL.
#
#   src/tools/test_migrate.sh          (on the host; needs docker and mysql:8.0)
#
# Touches nothing that exists already: every container and network it starts
# is named mtest-<pid>-*, keeps its data on tmpfs, publishes no port, and is
# removed on exit however the script ends. The rpg-dev stack is not used.
#
# What it proves, in order:
#
#   1. FRESH. docker/initdb/00-load.sh, run by the real mysql:8.0 entrypoint
#      with the same mounts as docker-compose.yml, ends with every migration
#      recorded (BASELINE's as 'baseline', the rest 'applied'), the road from
#      the Proving House both ways, and the _fp_* people saying exactly what
#      content.sql says — not the older lines 2026-08-25-freeplay-inn.sql
#      carries. Then --status has nothing pending and --apply does nothing.
#   2. LIVE. A database built the way production's was — schema, seed,
#      content, then all ten migrations by hand, and no schema_migrations —
#      shows them all pending, takes --baseline-all without changing a single
#      row of any other table (CHECKSUM TABLE before and after), and is then
#      clean.
#   3. NEW FILES. A new migration in a scratch copy of the directory is the only
#      thing --apply runs; a failing one exits non-zero and is not recorded, and
#      nothing after it runs; a file with USE is refused, including inside a
#      `/*! */` executable comment; an edited recorded file is reported and not
#      re-run, and a CRLF copy of the recorded files is not reported at all.
#   4. tools/test_freeplay_road.php against the fresh build, when the
#      rpg-dev-php image is on this machine.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MIGRATE="$ROOT/src/sql/migrate.sh"
TAG="mtest-$$"
FRESH="$TAG-fresh"; LIVE="$TAG-live"; NET="$TAG-net"
TMP=$(mktemp -d)
PW=rootdev

cleanup() {
    docker rm -f "$FRESH" "$LIVE" "$TAG-php" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT

pass=0; fail=0
ok() { # name, condition-exit-status
    if [ "$2" = 0 ]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1"; fi
}
check() { local n=$1; shift; if "$@"; then ok "$n" 0; else ok "$n" 1; fi; }

# The client inside a container, as the runner's --mysql. This is the same
# shape as hermes-mysql: a wrapper that takes the client's arguments and
# reads SQL on stdin.
client() { echo "docker exec -i -e MYSQL_PWD=$PW $1 mysql -uroot"; }
q() { # container, sql -> tab-separated rows
    docker exec -i -e MYSQL_PWD=$PW "$1" mysql -uroot --default-character-set=utf8mb4 -N -B rpg_5e <<< "$2"
}
migrate() { local c=$1; shift; bash "$MIGRATE" --mysql "$(client "$c")" --database rpg_5e "$@"; }

wait_ready() { # container: up on TCP, which the entrypoint's init server never is
    local c=$1 i
    for i in $(seq 1 300); do
        if ! docker ps -q --filter "name=^$c\$" | grep -q .; then
            docker logs "$c" 2>&1 | tail -30; echo "container $c died"; exit 1
        fi
        if docker exec -e MYSQL_PWD=$PW "$c" mysql -uroot -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    echo "timed out waiting for $c"; exit 1
}

docker network create "$NET" >/dev/null

echo "== 1. fresh build through docker/initdb/00-load.sh"
docker run -d --name "$FRESH" --network "$NET" --network-alias db --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD=$PW -e MYSQL_DATABASE=rpg_5e -e MYSQL_USER=web -e MYSQL_PASSWORD=devpassword \
    -v "$ROOT/src/sql:/sql:ro" -v "$ROOT/docker/initdb:/docker-entrypoint-initdb.d:ro" \
    mysql:8.0 >/dev/null
wait_ready "$FRESH"
docker logs "$FRESH" 2>&1 | grep -E '^\[initdb\]|^== |^baseline:|^migrate' | sed 's/^/    /'

total=$(ls "$ROOT"/src/sql/migrations/*.sql | wc -l)
folded=$(grep -Ev '^[[:space:]]*(#|$)' "$ROOT/src/sql/migrations/BASELINE" | awk '{print $1}' | sort)
q "$FRESH" "SELECT how, filename FROM schema_migrations ORDER BY filename" | sed 's/^/    /'
check "every migration file is recorded ($total)" \
    test "$(q "$FRESH" 'SELECT COUNT(*) FROM schema_migrations')" = "$total"
check "the baseline rows are exactly BASELINE's list" \
    test "$(q "$FRESH" "SELECT filename FROM schema_migrations WHERE how='baseline' ORDER BY filename")" = "$folded"
check "every other row was applied" \
    test "$(q "$FRESH" "SELECT COUNT(*) FROM schema_migrations WHERE how='applied'")" = "$((total - $(echo "$folded" | wc -l)))"
check "recorded checksums match the files" \
    test "$(q "$FRESH" 'SELECT filename, checksum FROM schema_migrations ORDER BY filename')" = \
         "$(cd "$ROOT/src/sql/migrations" && for f in *.sql; do printf '%s\t%s\n' "$f" "$(sha256sum "$f" | cut -d' ' -f1)"; done)"

road=$(q "$FRESH" "SELECT CONCAT(f.location_key,'>',t.location_key) FROM location_exits e
                    JOIN locations f ON f.id=e.from_location_id JOIN locations t ON t.id=e.to_location_id
                   WHERE (f.location_key,t.location_key) IN (('_freeplay_yard','west_gate'),('west_gate','_freeplay_yard'))
                   ORDER BY 1")
echo "    road: $(echo $road)"
check "the road runs both ways" test "$road" = $'_freeplay_yard>west_gate\nwest_gate>_freeplay_yard'
check "the Undervault mouth and the Pit have stairs, the common room does not" \
    test "$(q "$FRESH" "SELECT GROUP_CONCAT(location_key, '=', has_delve ORDER BY location_key)
                         FROM locations WHERE location_key IN ('uv_mouth','_freeplay_yard','_freeplay_cellar')")" \
         = "_freeplay_cellar=1,_freeplay_yard=0,uv_mouth=1"
check "content.sql's fp quests found the Pit on the second load" \
    test "$(q "$FRESH" "SELECT COUNT(*) FROM quests WHERE quest_key LIKE 'fp\\_watch\\_%' AND target_location_id IS NULL")" = 0

# The reference: content.sql alone in a scratch database in the same server,
# its USE / CREATE DATABASE pointed there so nothing reaches rpg_5e.
for f in schema.sql seed_data.sql content.sql; do
    sed -E 's/^(USE|CREATE DATABASE IF NOT EXISTS) rpg_5e/\1 ref_content/' "$ROOT/src/sql/$f"
done | docker exec -i -e MYSQL_PWD=$PW "$FRESH" mysql -uroot --default-character-set=utf8mb4
fp="SELECT npc_key, SHA2(description,256), SHA2(dialogue_json,256), name, role FROM %s.npcs
     WHERE npc_key LIKE '\\_fp\\_%%' ORDER BY npc_key"
mine=$(q "$FRESH" "$(printf "$fp" rpg_5e)"); ref=$(q "$FRESH" "$(printf "$fp" ref_content)")
check "the _fp_* people are what content.sql wrote ($(echo "$ref" | wc -l) rows)" test -n "$ref" -a "$mine" = "$ref"
check "and none of them carries freeplay-inn's stale \"action\" choices" \
    test "$(q "$FRESH" "SELECT COUNT(*) FROM npcs WHERE npc_key LIKE '\\_fp\\_%' AND dialogue_json LIKE '%\"action\"%'")" = 0

st=$(migrate "$FRESH" --status); echo "$st" | tail -1 | sed 's/^/    /'
check "--status: nothing pending" bash -c '! grep -q "^pending" <<< "$1" && grep -q " 0 pending" <<< "$1"' _ "$st"
before=$(q "$FRESH" 'SELECT filename, applied_at FROM schema_migrations ORDER BY filename')
out=$(migrate "$FRESH" --apply); echo "    $out"
check "a second --apply is a no-op" test "$out" = "nothing pending." \
    -a "$(q "$FRESH" 'SELECT filename, applied_at FROM schema_migrations ORDER BY filename')" = "$before"

echo "== 2. production's shape: migrations applied by hand, no table"
docker run -d --name "$LIVE" --tmpfs /var/lib/mysql -e MYSQL_ROOT_PASSWORD=$PW -e MYSQL_DATABASE=rpg_5e \
    mysql:8.0 >/dev/null
wait_ready "$LIVE"
for f in schema.sql seed_data.sql content.sql; do
    docker exec -i -e MYSQL_PWD=$PW "$LIVE" mysql -uroot --default-character-set=utf8mb4 < "$ROOT/src/sql/$f"
done
q "$LIVE" 'DROP TABLE schema_migrations' >/dev/null
for f in "$ROOT"/src/sql/migrations/*.sql; do
    docker exec -i -e MYSQL_PWD=$PW "$LIVE" mysql -uroot --default-character-set=utf8mb4 rpg_5e < "$f" >/dev/null
done
has_table() { q "$LIVE" "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='rpg_5e' AND TABLE_NAME='schema_migrations'"; }
checksums() {
    local t; t=$(q "$LIVE" "SELECT GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA='rpg_5e' AND TABLE_NAME <> 'schema_migrations'")
    q "$LIVE" "CHECKSUM TABLE $t"
}

st=$(migrate "$LIVE" --status); echo "$st" | sed 's/^/    /'
check "--status: all $total pending" test "$(grep -c '^pending' <<< "$st")" = "$total"
dr=$(migrate "$LIVE"); echo "$dr" | tail -2 | sed 's/^/    /'
check "the default is a dry run listing all $total" test "$(grep -c '^would apply' <<< "$dr")" = "$total"
check "and neither wrote anything (no table yet)" test "$(has_table)" = 0

sum_before=$(checksums)
migrate "$LIVE" --baseline-all | sed 's/^/    /'
sum_after=$(checksums)
check "CHECKSUM TABLE of every other table ($(echo "$sum_before" | wc -l)) is unchanged by --baseline-all" \
    test -n "$sum_before" -a "$sum_before" = "$sum_after"
st=$(migrate "$LIVE" --status); echo "$st" | tail -1 | sed 's/^/    /'
check "--status: none pending, all $total baseline" \
    test "$(grep -c '^baseline' <<< "$st")" = "$total" -a "$(grep -c '^pending' <<< "$st")" = 0
check "--apply runs nothing" test "$(migrate "$LIVE" --apply)" = "nothing pending."
check "--baseline-all again records nothing new" \
    test "$(migrate "$LIVE" --baseline-all; q "$LIVE" 'SELECT COUNT(*) FROM schema_migrations')" = "$total"

echo "== 3. a new migration, a broken one, a USE, an edited one"
D="$TMP/migrations"; cp -r "$ROOT/src/sql/migrations" "$D"
cat > "$D/2026-12-01-zz-dummy.sql" <<'SQL'
-- test only: never committed
CREATE TABLE IF NOT EXISTS zz_migrate_dummy (id INT PRIMARY KEY);
INSERT IGNORE INTO zz_migrate_dummy VALUES (1);
SQL
out=$(migrate "$LIVE" --dir "$D" --apply); echo "$out" | sed 's/^/    /'
check "--apply runs only the new file" test "$(grep -c '^== applying' <<< "$out")" = 1 \
    -a "$(grep '^== applying' <<< "$out")" = "== applying 2026-12-01-zz-dummy.sql"
check "and records it as applied" \
    test "$(q "$LIVE" "SELECT how FROM schema_migrations WHERE filename='2026-12-01-zz-dummy.sql'")" = applied
check "and it really ran" test "$(q "$LIVE" 'SELECT COUNT(*) FROM zz_migrate_dummy')" = 1

cat > "$D/2026-12-02-zz-broken.sql" <<'SQL'
INSERT IGNORE INTO zz_migrate_dummy VALUES (2);
SELECT * FROM zz_no_such_table;
SQL
cat > "$D/2026-12-03-zz-after.sql" <<'SQL'
INSERT IGNORE INTO zz_migrate_dummy VALUES (3);
SQL
set +e; out=$(migrate "$LIVE" --dir "$D" --apply 2>&1); rc=$?; set -e
echo "$out" | sed 's/^/    /'
check "a failing migration exits non-zero (rc=$rc)" test "$rc" -ne 0
check "and is not recorded" \
    test "$(q "$LIVE" "SELECT COUNT(*) FROM schema_migrations WHERE filename LIKE '2026-12-0_-zz-b%'")" = 0
check "and the file after it was not run or recorded" \
    test "$(q "$LIVE" "SELECT COUNT(*) FROM zz_migrate_dummy WHERE id=3")$(q "$LIVE" "SELECT COUNT(*) FROM schema_migrations WHERE filename='2026-12-03-zz-after.sql'")" = 00
rm "$D/2026-12-02-zz-broken.sql" "$D/2026-12-03-zz-after.sql"

# Every way a file can switch database, each in a directory of its own so
# nothing else is pending beside it. The executable-comment forms are the ones
# MySQL runs as if the comment marks were absent.
i=0
for body in 'USE mysql;\nSELECT 1;\n' 'use\n  `mysql`\n;\n' 'SELECT 1; USE mysql;\n' \
            '/* why */ USE mysql;\n' 'SELECT 1;\n/*! USE mysql */;\n' '/*!40000 USE mysql */;\n' \
            '/*!USE mysql*/;\n' '\\u mysql\nSELECT 1;\n' 'SELECT 1;\r\nUSE mysql\r\n'; do
    i=$((i+1)); U="$TMP/use$i"; mkdir "$U"; printf "$body" > "$U/2026-12-04-use.sql"
    set +e; out=$(migrate "$LIVE" --dir "$U" --apply 2>&1); rc=$?; set -e
    check "refused before anything runs: $(printf "$body" | tr '\r\n' '~|')" \
        test "$rc" -ne 0 -a -n "$(grep 'contains a USE' <<< "$out")" \
             -a "$(q "$LIVE" "SELECT COUNT(*) FROM schema_migrations WHERE filename='2026-12-04-use.sql'")" = 0
done
echo "    e.g. $out"
U="$TMP/use-comment"; mkdir "$U"; printf -- '-- USE mysql; would be wrong here\nSELECT 1;\n' > "$U/2026-12-04-ok.sql"
out=$(migrate "$LIVE" --dir "$U" --dry-run 2>&1) && rc=0 || rc=$?
check "but a USE in an ordinary comment is not refused" \
    test "$rc" = 0 -a -n "$(grep '^would apply: 2026-12-04-ok.sql' <<< "$out")"

# A checkout with CRLF line endings must not make every recorded file look
# edited: the runner hashes with CRs removed, and for an LF file that is the
# plain sha256sum production's rows were recorded with (asserted in part 1).
C="$TMP/crlf"; mkdir "$C"
for f in "$ROOT"/src/sql/migrations/*.sql; do sed 's/$/\r/' "$f" > "$C/$(basename "$f")"; done
check "the CRLF copy really differs byte for byte" bash -c '! cmp -s "$1" "$2"' _ \
    "$ROOT/src/sql/migrations/2026-10-29-freeplay-road.sql" "$C/2026-10-29-freeplay-road.sql"
st=$(migrate "$LIVE" --dir "$C" --status 2>&1)
check "--status on a CRLF copy: all $total match, nothing CHANGED" \
    test "$(grep -c '^baseline' <<< "$st")" = "$total" -a -z "$(grep -E 'CHANGED|WARNING' <<< "$st")"

echo "-- edited after the fact" >> "$D/2026-12-01-zz-dummy.sql"
st=$(migrate "$LIVE" --dir "$D" --status 2>&1); grep -E 'CHANGED|WARNING' <<< "$st" | sed 's/^/    /'
check "--status flags an edited, recorded file" grep -q 'zz-dummy.sql.*CHANGED' <<< "$st"
out=$(migrate "$LIVE" --dir "$D" --apply 2>/dev/null)
check "and --apply does not re-run it" test "$out" = "nothing pending."

echo "== 4. tools/test_freeplay_road.php against the fresh build"
if docker image inspect rpg-dev-php >/dev/null 2>&1; then
    # app/config/ holds database.php, which is local-only and on a working
    # checkout holds real credentials. It is excluded from the copy, along with
    # anything shaped like an env file, so it cannot travel into the temp tree
    # even by accident; a throwaway one reading the variables compose sets is
    # written in its place.
    W="$TMP/www"; mkdir -p "$W/tools"
    tar -C "$ROOT/src" --exclude='app/config' --exclude='.env*' --exclude='*.env' -cf - app | tar -C "$W" -xf -
    check "no config or env file was copied" test -z "$(find "$W/app" -path '*/config*' -o -name '*.env' -o -name '.env*')"
    mkdir -p "$W/app/config"
    cp "$ROOT/src/tools/test_freeplay_road.php" "$W/tools/"
    cat > "$W/app/config/database.php" <<'PHP'
<?php
return ['host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'), 'dbname' => getenv('DB_NAME'),
        'username' => getenv('DB_USERNAME'), 'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8mb4'];
PHP
    set +e
    out=$(docker run --rm --name "$TAG-php" --network "$NET" -v "$W:/var/www/html:ro" \
        -e DB_HOST=db -e DB_PORT=3306 -e DB_NAME=rpg_5e -e DB_USERNAME=web -e DB_PASSWORD=devpassword \
        rpg-dev-php php /var/www/html/tools/test_freeplay_road.php 2>&1); rc=$?
    set -e
    echo "$out" | sed 's/^/    /'
    check "test_freeplay_road.php passes on a fresh build (rc=$rc)" test "$rc" = 0 -a -n "$out"
else
    echo "    (skipped: no rpg-dev-php image on this machine)"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
