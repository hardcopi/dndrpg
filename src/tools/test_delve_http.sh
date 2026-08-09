#!/bin/bash
# Does a delve work through the API, the way the browser drives it?
#
# tools/test_delve.php proves the engine against the database. This proves the
# other half: that the routes exist, that the scene carries the `delve` block
# the buttons are drawn from, and that descending and climbing out actually
# move a real signed-in party through real HTTP.
#
# Creates a throwaway account and party in the Undervault, and deletes them
# again. Safe to run against a database somebody is playing on: it only ever
# removes what it made.
#
#   bash src/tools/test_delve_http.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="delve_${SUFFIX}"
PASS='a-good-long-password'
OK=0; BAD=0

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

cleanup() {
  sql "
    DELETE dd FROM dungeon_delves dd
      INNER JOIN parties p ON p.id = dd.party_id
      INNER JOIN users u ON u.id = p.user_id WHERE u.username = '${USER}';
    DELETE r FROM regions r WHERE r.region_key LIKE '\\_dg\\_%'
      AND r.id NOT IN (SELECT region_id FROM dungeon_delves WHERE region_id IS NOT NULL);
    DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM users      WHERE username = '${USER}';"
  rm -f "$JAR"
}
trap cleanup EXIT

pass() { OK=$((OK+1)); echo "  ok   $1"; }
fail() { BAD=$((BAD+1)); echo "  FAIL $1${2:+  ($2)}"; }
check() { if [ "$2" = "1" ]; then pass "$1"; else fail "$1" "${3:-}"; fi }

api() {  # api <path> [json-body]
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
         -X POST -d "$2" "$BASE/api/?r=$1"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/?r=$1"
  fi
}

# `grep -q` in a pipeline would race curl and, with pipefail set, report a
# match as a failure — see the note in test_ownership.sh. Everything below
# captures first.
has() { printf '%s' "$1" | grep -q "$2" && echo 1 || echo 0; }

echo "== a delver signs up and takes a party into the Undervault =="
REG=$(curl -s -c "$JAR" -H 'Content-Type: application/json' -X POST \
      -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register")
check "the account is made" "$(has "$REG" '"ok":true')" "$(head -c 120 <<<"$REG")"

CHAR=$(api "character/create" \
  "{\"name\":\"Delver${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"undervault\"}")
check "a character is rolled in the module" "$(has "$CHAR" '"ok":true')" "$(head -c 200 <<<"$CHAR")"
CID=$(python3 -c "
import json,sys
d = json.load(sys.stdin)
print(d.get('character',{}).get('id') or d.get('id') or 0)" <<<"$CHAR" 2>/dev/null || echo 0)
check "and it has an id" "$([ "${CID:-0}" -gt 0 ] && echo 1 || echo 0)" "got '${CID}'"

STATE=$(api "location/state&character_id=$CID")
START=$(python3 -c "
import json,sys
print(json.load(sys.stdin)['location']['key'])" <<<"$STATE" 2>/dev/null || echo '?')
check "the party starts at the camp" "$([ "$START" = "uv_camp" ] && echo 1 || echo 0)" "at '$START'"

echo "== the scene carries the stair =="
DELVE_AT_CAMP=$(python3 -c "
import json,sys
d = json.load(sys.stdin)['location'].get('delve')
print('none' if d is None else ('yes' if d['can_descend'] else 'no'))" <<<"$STATE" 2>/dev/null || echo '?')
check "the camp says the stair is elsewhere" \
  "$([ "$DELVE_AT_CAMP" = "no" ] && echo 1 || echo 0)" "got '$DELVE_AT_CAMP'"

DENIED=$(api "location/descend" '{}')
check "and descending from the camp is refused" "$(has "$DENIED" '"ok":false')" "$(head -c 140 <<<"$DENIED")"

# Walk to the Mouth.
MOUTH_ID=$(sql "SELECT id FROM locations WHERE location_key='uv_mouth';")
MOVE=$(api "location/travel" "{\"character_id\":$CID,\"location_id\":$MOUTH_ID}")
check "the party walks to the Mouth" "$(has "$MOVE" '"ok":true')" "$(head -c 140 <<<"$MOVE")"

STATE=$(api "location/state&character_id=$CID")
AT_MOUTH=$(python3 -c "
import json,sys
d = json.load(sys.stdin)['location'].get('delve') or {}
print(1 if d.get('can_descend') else 0)" <<<"$STATE" 2>/dev/null || echo 0)
check "the Mouth offers the stair" "$AT_MOUTH"

echo "== down =="
DOWN=$(api "location/descend" '{}')
check "descending is accepted" "$(has "$DOWN" '"ok":true')" "$(head -c 200 <<<"$DOWN")"
DEPTH=$(python3 -c "
import json,sys
print(json.load(sys.stdin).get('depth', 0))" <<<"$DOWN" 2>/dev/null || echo 0)
check "and lands on level one" "$([ "$DEPTH" = "1" ] && echo 1 || echo 0)" "depth '$DEPTH'"

STATE=$(api "location/state&character_id=$CID")
UNDER=$(python3 -c "
import json,sys
s = json.load(sys.stdin)
loc = s['location']
d = loc.get('delve') or {}
print(1 if loc['key'].startswith('_dg_') and d.get('can_leave') and loc['description'] else 0)" \
  <<<"$STATE" 2>/dev/null || echo 0)
check "the party is underground, in a described room, able to climb out" "$UNDER"

NODES=$(python3 -c "
import json,sys
print(len(json.load(sys.stdin)['region_map']['nodes']))" <<<"$STATE" 2>/dev/null || echo 0)
check "and the region chart has the whole floor on it" \
  "$([ "${NODES:-0}" -ge 9 ] && echo 1 || echo 0)" "$NODES nodes"

# The floor plan. A generated region has no painted plate and never will, so
# the chart draws the level's own shape instead -- rooms and passages, rebuilt
# from the seed. Checked here rather than only in test_dungeon.php because the
# geometry being right is half of it; the other half is that it survives the
# round trip and arrives keyed by LOCATION ids, which is what ui-map.js
# matches its nodes and edges against.
PLAN=$(python3 -c "
import json,sys
m = json.load(sys.stdin)['region_map']
p = m.get('floorplan')
if not p:
    print('0 no-floorplan'); raise SystemExit
ids = {n['id'] for n in m['nodes']}
halls = {n['id'] for n in m['nodes'] if n['type'] == 'passage'}
rooms = ids - halls
# A rectangle per room, and a drawn run per passage. Passages are LOCATIONS
# now, so each run carries its own location_id rather than the ids of the two
# rooms it used to be a line between.
rooms_ok = len(p['rooms']) == len(rooms) and all(r.get('location_id') in rooms for r in p['rooms'])
runs_ok  = bool(p['corridors']) and {c.get('location_id') for c in p['corridors']} == halls
# And the graph goes room, passage, room: no exit joins two rooms directly.
direct = [e for e in m['edges'] if e['from'] not in halls and e['to'] not in halls]
print(1 if rooms_ok and runs_ok and not direct else 0,
      f\"{len(p['rooms'])}r/{len(p['corridors'])}c rooms_ok={rooms_ok} runs_ok={runs_ok} direct={len(direct)}\")" \
  <<<"$STATE" 2>/dev/null || echo '0 parse-failed')
check "the chart carries a floor plan keyed by location" "${PLAN%% *}" "${PLAN#* }"

echo "== and out =="
OUT=$(api "location/leave_dungeon" '{}')
check "climbing out is accepted" "$(has "$OUT" '"ok":true')" "$(head -c 140 <<<"$OUT")"

STATE=$(api "location/state&character_id=$CID")
BACK=$(python3 -c "
import json,sys
print(json.load(sys.stdin)['location']['key'])" <<<"$STATE" 2>/dev/null || echo '?')
check "and the party is back at the Mouth" "$([ "$BACK" = "uv_mouth" ] && echo 1 || echo 0)" "at '$BACK'"

# Above ground there is a painted plate and no plan. The two are exclusive by
# construction -- a plan under a painting would be two maps of one place
# disagreeing -- so the surface having one would mean planFor() is matching
# regions it has no business in.
SURFACE=$(python3 -c "
import json,sys
print(1 if json.load(sys.stdin)['region_map'].get('floorplan') is None else 0)" \
  <<<"$STATE" 2>/dev/null || echo 0)
check "and the surface has no floor plan, only its painting" "$SURFACE"

# Scoped to THIS party's floors. It used to count every _dg_ region in the
# database, so the check failed whenever anybody else was mid-delve -- which
# makes a liar of the promise that this script is safe to run against a
# database somebody is playing on.
PARTY=$(sql "SELECT p.id FROM parties p
  INNER JOIN users u ON u.id = p.user_id WHERE u.username = '${USER}' LIMIT 1;")
LEFT=$(sql "SELECT COUNT(*) FROM regions WHERE region_key LIKE '\\_dg\\_${PARTY:-0}\\_%';")
check "with no generated floor left behind" "$([ "${LEFT:-1}" = "0" ] && echo 1 || echo 0)" "$LEFT region(s)"

echo
echo "----------------------------------------------------"
echo "$OK passed, $BAD failed"
[ "$BAD" -eq 0 ]
