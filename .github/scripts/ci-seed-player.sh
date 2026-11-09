#!/usr/bin/env bash
# Give the CI database one player, the way a dev database always has one.
#
# A few harnesses (test_delve_quest, test_furnishing, test_art) borrow an
# existing party rather than building their own, because every dev and live
# database has players in it. A database built from the SQL files has none, so
# CI makes one through the real API: register, create a character, then recruit
# two companions (test_art checks a companion's portrait).
#
# Throwaway only: php's built-in server on a loopback port, the CI service DB.
set -euo pipefail
cd "$(dirname "$0")/../../src"

PORT="${SEED_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
JAR="$(mktemp)"
php -S "127.0.0.1:${PORT}" -t . >/tmp/ci-seed-php.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true; rm -f "$JAR"' EXIT
for _ in $(seq 1 50); do
    curl -fs -o /dev/null "$BASE/api/?r=auth/status" && break
    sleep 0.2
done

U="ci_$(date +%s)"
PASS="ci-only-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
csrf() { curl -s -b "$JAR" -c "$JAR" "$BASE/api/?r=auth/status" \
    | python3 -c 'import json,sys;print(json.load(sys.stdin).get("csrf_token") or "")'; }
post() { curl -s -H "X-CSRF-Token: $(csrf)" -b "$JAR" -c "$JAR" \
    -H 'Content-Type: application/json' -X POST -d "$2" "$BASE/api/?r=$1"; }

post auth/register "{\"username\":\"$U\",\"password\":\"$PASS\"}" >/dev/null
OUT=$(post character/create '{"name":"Wren","race":"Human","class":"Fighter","module":"rivermark"}')
PID=$(printf '%s' "$OUT" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["party_id"]) if d.get("ok") else sys.exit("character/create: "+str(d))')
echo "[ci-seed] user $U, party $PID"

php -r '
require "app/bootstrap.php";
$svc = new CompanionService(db());
foreach (["kessa", "aldric"] as $k) { $svc->recruit((int) $argv[1], $k); }
echo "[ci-seed] recruited kessa, aldric\n";
' "$PID"
