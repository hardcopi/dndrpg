#!/bin/bash
# What a browser is told when the server fails, against throwaway containers.
#
#   src/tools/test_api_errors.sh       (on the host; needs docker, mysql:8.0,
#                                       nginx:1.24-alpine and the rpg-dev-php image)
#
# Touches nothing that exists already: every container and network is named
# apierr-<pid>-*, the database lives on tmpfs, nothing publishes a port except
# nginx on a random one bound to 127.0.0.1, and all of it is removed on exit.
# The rpg-dev stack is not used. No test hook ships in the app: the failures
# are made from outside, by renaming a table and then stopping the database.
#
# What it proves:
#
#   1. A normal sign-in and a game action (create a character, look around)
#      still work with the CSRF token from auth/status.
#   2. A refusal written for the player (InvalidArgumentException) still comes
#      back as a 4xx carrying its own text.
#   3. A broken query is a 500 with the generic sentence and an error_id, and
#      no SQL, table name, path or class name in the body — while the PHP log
#      has the PDOException, its message and a file:line under the same id.
#   4. The database gone altogether: the API answers the same way, and a page
#      (races.php) answers with generic HTML rather than a stack trace.
#   5. The log holds no password, CSRF token or session id.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
TAG="apierr-$$"
DB="$TAG-db"; PHP="$TAG-php"; WEB="$TAG-web"; NET="$TAG-net"
TMP=$(mktemp -d)
PW=rootdev
PASS="pw-$$-$RANDOM-secret"

cleanup() {
    docker rm -f "$DB" "$PHP" "$WEB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT

pass=0; fail=0
ok() { if [ "$2" = 0 ]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1"; fi; }
check() { local n=$1; shift; if "$@"; then ok "$n" 0; else ok "$n" 1; fi; }
lacks() { ! grep -qiE "$1" <<< "$2"; }
q() { docker exec -i -e MYSQL_PWD=$PW "$DB" mysql -uroot -N -B rpg_5e <<< "$1"; }

docker network create "$NET" >/dev/null

echo "== fresh database through docker/initdb/00-load.sh"
docker run -d --name "$DB" --network "$NET" --network-alias db --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD=$PW -e MYSQL_DATABASE=rpg_5e -e MYSQL_USER=web -e MYSQL_PASSWORD=devpassword \
    -v "$ROOT/src/sql:/sql:ro" -v "$ROOT/docker/initdb:/docker-entrypoint-initdb.d:ro" \
    mysql:8.0 >/dev/null
for i in $(seq 1 300); do
    docker ps -q --filter "name=^$DB\$" | grep -q . || { docker logs "$DB" 2>&1 | tail -30; echo "db died"; exit 1; }
    docker exec -e MYSQL_PWD=$PW "$DB" mysql -uroot -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 2
done

# The site, minus app/config (database.php is local-only and may hold real
# credentials) and minus anything env-shaped. A throwaway config reading the
# variables set below is written in its place.
W="$TMP/www"; mkdir -p "$W"
tar -C "$ROOT/src" --exclude='app/config' --exclude='.env*' --exclude='*.env' \
    -cf - api app $(cd "$ROOT/src" && ls *.php) | tar -C "$W" -xf -
mkdir -p "$W/app/config"
cat > "$W/app/config/database.php" <<'PHP'
<?php
return ['host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'), 'dbname' => getenv('DB_NAME'),
        'username' => getenv('DB_USERNAME'), 'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8mb4'];
PHP
chmod -R a+rX "$W"

docker run -d --name "$PHP" --network "$NET" --network-alias php -v "$W:/var/www/html:ro" \
    -e DB_HOST=db -e DB_PORT=3306 -e DB_NAME=rpg_5e -e DB_USERNAME=web -e DB_PASSWORD=devpassword \
    rpg-dev-php >/dev/null
docker run -d --name "$WEB" --network "$NET" -p 127.0.0.1::80 -v "$W:/var/www/html:ro" \
    -v "$ROOT/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "$ROOT/docker/nginx/deny-private.conf:/etc/nginx/snippets/rpg-deny-private.conf:ro" \
    nginx:1.24-alpine >/dev/null
BASE="http://$(docker port "$WEB" 80/tcp | head -1)"
for i in $(seq 1 30); do curl -s -o /dev/null "$BASE/api/?r=auth/status" && break; sleep 1; done
echo "   site at $BASE"

echo "== bootstrap exception and partial-output guards"
guard=$(docker exec -i "$PHP" php <<'PHP'
<?php
require '/var/www/html/app/bootstrap.php';
class LibraryInvalidArgumentException extends InvalidArgumentException {}
if (!exception_is_player_facing(new InvalidArgumentException('safe'))
    || exception_is_player_facing(new LibraryInvalidArgumentException('/internal/library/path'))) {
    fwrite(STDERR, "exception allow-list failed\n"); exit(1);
}
echo "prefix";
flush(); // Ensure headers_sent() and no output buffer for the final branch.
page_exception_handler(new RuntimeException('/internal/path'));
PHP
)
echo "   $guard"
check "only exact app refusal class is player-facing" grep -q '^prefix<p>Something went wrong' <<< "$guard"
check "already-sent output gets no second document or internal text" \
    python3 -c 'import sys; s=sys.stdin.read(); sys.exit(0 if s.count("<!DOCTYPE") == 0 and "internal/path" not in s and s.count("<p>") == 1 else 1)' <<< "$guard"
buffered_guard=$(docker exec -i "$PHP" php <<'PHP'
<?php
require '/var/www/html/app/bootstrap.php';
ob_start();
echo 'buffered-prefix';
ob_flush(); flush();
page_exception_handler(new RuntimeException('/internal/buffered-path'));
PHP
)
check "started output buffer is discarded before the replacement page" \
    python3 -c 'import sys; s=sys.stdin.read(); sys.exit(0 if s.count("<!DOCTYPE") == 1 and "buffered-prefix" in s and "internal/buffered-path" not in s and s.count("<html") == 1 else 1)' <<< "$buffered_guard"

JAR="$TMP/jar"
csrf() { curl -s -b "$JAR" -c "$JAR" "$BASE/api/index.php?r=auth/status" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token") or "")'; }
# api <route> [json] -> "<status> <body>"
api() {
    if [ $# -ge 2 ]; then
        curl -s -w '\n%{http_code}' -H "X-CSRF-Token: $(csrf)" -b "$JAR" -c "$JAR" \
            -X POST -H 'Content-Type: application/json' --data "$2" "$BASE/api/index.php?r=$1"
    else
        curl -s -w '\n%{http_code}' -b "$JAR" -c "$JAR" "$BASE/api/index.php?r=$1"
    fi | python3 -c 'import sys; b=sys.stdin.read().rsplit("\n",1); print(b[1], b[0])'
}
status() { cut -d' ' -f1 <<< "$1"; }
body() { cut -d' ' -f2- <<< "$1"; }
field() { python3 -c "import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2],''))" "$(body "$1")" "$2"; }

# What may never be in an unexpected-error body.
LEAK='SQLSTATE|PDO|Exception|Error:|/var/www|\.php|races|SELECT|rpg_5e|stack|#0'

echo "== 1. sign in and play"
r=$(api auth/register "{\"username\":\"apierr_$$\",\"password\":\"$PASS\"}"); echo "   register: $r"
check "register answers 200" test "$(status "$r")" = 200
rolled=$(api meta/roll_abilities '{}' | cut -d' ' -f2- | python3 -c "
import json,sys
sets = json.load(sys.stdin)['sets']
ab = ['strength','dexterity','constitution','intelligence','wisdom','charisma']
print(json.dumps(dict(zip(ab, [s['total'] for s in sets]))))")
r=$(api character/create "{\"method\":\"random\",\"name\":\"Errtest$$\",\"race\":\"Human\",\"class\":\"Fighter\",\"abilities\":$rolled}")
echo "   create: $(status "$r") $(body "$r" | cut -c1-120)…"
check "character/create answers 200" test "$(status "$r")" = 200
r=$(api location/state); echo "   state: $(status "$r") $(body "$r" | cut -c1-120)…"
check "location/state answers 200" test "$(status "$r")" = 200

echo "== 2. a refusal written for the player keeps its text"
r=$(api character/create '{"method":"random","name":"Cheat","race":"Human","class":"Fighter","abilities":{"strength":18,"dexterity":18,"constitution":18,"intelligence":18,"wisdom":18,"charisma":18}}')
echo "   $r"
check "it is a 4xx" grep -q '^4' <<< "$(status "$r")"
check "it carries a sentence, not the generic one" test -n "$(field "$r" error)" -a -z "$(field "$r" error_id)"
r=$(api character/create '{}'); echo "   $r"
check "an empty create is a 4xx with its own text" grep -q '^4' <<< "$(status "$r")"

echo "== 3. a broken query"
q 'RENAME TABLE races TO races_apierr_hidden'
r=$(api meta/races); echo "   $r"
q 'RENAME TABLE races_apierr_hidden TO races'
ID=$(field "$r" error_id)
check "it is a 500" test "$(status "$r")" = 500
check "it has an 8-hex error_id" grep -qE '^[0-9a-f]{8}$' <<< "$ID"
check "the error sentence names that id" grep -q "ref $ID" <<< "$(field "$r" error)"
check "the body has no SQL, path or class" lacks "$LEAK" "$(body "$r")"
check "the body has the same keys as any error (ok, error) and no 'type'" \
    python3 -c "import json,sys; d=json.loads(sys.argv[1]); sys.exit(0 if set(d)=={'ok','error','error_id'} and d['ok'] is False else 1)" "$(body "$r")"
sleep 1
LOG=$(docker logs "$PHP" 2>&1)
hit=$(grep -A3 "rpg api \[$ID\]" <<< "$LOG" || true); echo "$hit" | sed 's/^/   log: /' | cut -c1-220
check "the log has the id, the route and PDOException" grep -q "rpg api \[$ID\] meta/races PDOException" <<< "$hit"
check "the log has the real message" grep -q "races' doesn't exist" <<< "$hit"
check "the log has a file:line" grep -qE '/var/www/html/[^ ]+\.php:[0-9]+' <<< "$hit"

TOKEN=$(csrf)
echo "== 4. the database gone"
docker stop "$DB" >/dev/null
r=$(api location/state); echo "   $r"
ID2=$(field "$r" error_id)
check "the API is a 500 with an id" test "$(status "$r")" = 500 -a -n "$ID2"
check "and no SQL, path or class" lacks "$LEAK|getaddrinfo|db:3306|Connection refused" "$(body "$r")"
page=$(curl -s -w '\n%{http_code}' "$BASE/races.php")
echo "$page" | sed 's/^/   page: /' | cut -c1-200
check "races.php is a 500" test "$(tail -1 <<< "$page")" = 500
check "races.php says the generic sentence with a ref" grep -qE 'mention ref [0-9a-f]{8}' <<< "$page"
check "races.php shows no trace, path or class" lacks 'SQLSTATE|PDO|/var/www|#0|Fatal' "$(head -n -1 <<< "$page")"
PID=$(grep -oE 'ref [0-9a-f]{8}' <<< "$page" | head -1 | cut -c5-)
sleep 1
LOG=$(docker logs "$PHP" 2>&1)
check "the API failure is logged under its id" grep -q "rpg api \[$ID2\] location/state PDOException" <<< "$LOG"
check "the page failure is logged under its id" grep -q "rpg page \[$PID\] /races.php PDOException" <<< "$LOG"

echo "== 5. nothing secret in the log"
check "no password" lacks "$PASS" "$LOG"
check "no CSRF token" lacks "$TOKEN" "$LOG"
SID=$(awk '$6=="PHPSESSID"{print $7}' "$JAR" | head -1)
check "a session id was issued (so the next check means something)" test -n "$SID"
check "no session id" lacks "${SID:-x}" "$LOG"
check "no DB password" lacks 'devpassword' "$LOG"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
