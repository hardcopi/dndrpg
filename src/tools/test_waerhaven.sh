#!/bin/bash
# Can Waerhaven actually be played?
#
# Driven over real HTTP for the same reason test_modules.sh is: the loader
# proves the files are consistent with each other and nothing else. This proves
# a party can be made in the module, can walk every location in it, can get the
# scene the whole act hangs off, and can read the board.
#
# Makes an account and a party, walks the world, and deletes both afterwards.
# Safe against a database somebody is playing on — it touches nothing it did
# not create.
#
#   bash src/tools/test_waerhaven.sh
#
# Exits non-zero if any check fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="wh_${SUFFIX}"
PASS='a-good-long-password'
FAILED=0; OK=0

db() {
  docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
    mysql -uweb -pdevpassword --default-character-set=utf8mb4 rpg_5e "$@" 2>/dev/null
}

cleanup() {
  db -e "
    DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM users      WHERE username = '${USER}';"
  rm -f "$JAR"
}
trap cleanup EXIT

api() {
  local route=$1
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -X POST -H 'Content-Type: application/json' \
      -d "$2" "$BASE/api/index.php?r=$route"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/index.php?r=$route"
  fi
}

say()   { printf '\n== %s ==\n' "$1"; }
check() {
  if [ "$2" = "1" ]; then OK=$((OK + 1)); printf '   ok   %s\n' "$1"
  else FAILED=$((FAILED + 1)); printf '   FAIL %s\n' "$1"; [ $# -ge 3 ] && printf '        %s\n' "$3"; fi
}
field() { python3 -c "
import json,sys
try: d=json.load(sys.stdin)
except Exception: print(''); sys.exit()
for k in sys.argv[1].split('.'):
    if isinstance(d, list): d = d[int(k)] if k.isdigit() and int(k) < len(d) else ''
    else: d = d.get(k, '') if isinstance(d, dict) else ''
print(d if d is not None else '')" "$1"; }

# ---------------------------------------------------------------------------
say "The module is on the shelf"
api 'auth/register' "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" >/dev/null
logged=$(api 'auth/login' "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" | field ok)
check "registered and signed in" "$([ "$logged" = "True" ] && echo 1 || echo 0)" "got '$logged'"

listed=$(api 'session/modules' | python3 -c "
import json,sys
d=json.load(sys.stdin)
print('1' if 'waerhaven' in [m['module_key'] for m in d.get('modules',[])] else '0')")
check "session/modules offers Eleven Weeks" "$listed"

gone=$(api 'session/modules' | python3 -c "
import json,sys
d=json.load(sys.stdin)
print('1' if 'worlds_end' not in [m['module_key'] for m in d.get('modules',[])] else '0')")
check "The Well at the World's End is not on the shelf" "$gone"

# ---------------------------------------------------------------------------
say "A party made in Waerhaven starts at the Waerman's Rest"
made=$(api 'character/create' "{\"name\":\"Gate Road Stranger ${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"method\":\"standard\",\"abilities\":{\"strength\":15,\"dexterity\":14,\"constitution\":13,\"intelligence\":12,\"wisdom\":10,\"charisma\":8},\"background\":\"Sailor\",\"alignment\":\"Neutral Good\",\"module\":\"waerhaven\"}")
cid=$(echo "$made" | field character.id)
check "character created" "$([ -n "$cid" ] && echo 1 || echo 0)" "$(echo "$made" | head -c 300)"
api 'session/select' "{\"character_id\":${cid:-0}}" >/dev/null

where=$(db -N -e "SELECT l.location_key FROM characters c JOIN locations l ON l.id=c.current_location_id WHERE c.id=${cid:-0};")
check "starts at waermans_rest" "$([ "$where" = "waermans_rest" ] && echo 1 || echo 0)" "started at '${where}'"

# ---------------------------------------------------------------------------
say "Every location in the module can be walked to"
# Walk the exit graph breadth-first through the real travel route, so a scene
# that cannot be reached in play fails here rather than in somebody's session.
walked=$(python3 - "$JAR" "$BASE" <<'PY'
import json, subprocess, sys, collections
jar, base = sys.argv[1], sys.argv[2]
def api(route, body=None):
    cmd = ['curl','-s','-b',jar,'-c',jar]
    if body is not None:
        cmd += ['-X','POST','-H','Content-Type: application/json','-d',json.dumps(body)]
    cmd += [f'{base}/api/index.php?r={route}']
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    try: return json.loads(out)
    except Exception: return {}
seen, order = set(), collections.deque()
state = api('location/current')
here = state.get('location', {})
seen.add(here.get('key'))
order.append(here.get('id'))
blocked = []
while order:
    lid = order.popleft()
    api('location/travel', {'location_id': lid})
    st = api('location/current')
    # An ambush placed on a location fires on arrival and holds the party
    # there. Flee it: the walk is about the graph, not about the fight.
    for _ in range(6):
        if not st.get('combat'):
            break
        api('combat/action', {'action': 'flee'})
        st = api('location/current')
    if st.get('combat'):
        blocked.append((st.get('location') or {}).get('key'))
        continue
    for ex in (st.get('location') or {}).get('exits') or []:
        key = ex.get('to_key')
        tid = ex.get('to_location_id')
        if key and key not in seen:
            seen.add(key); order.append(tid)
sys.stderr.write('held by a live ambush: %s\n' % (blocked or 'none'))
print(json.dumps(sorted(k for k in seen if k)))
PY
)
authored=$(db -N -e "
  SELECT GROUP_CONCAT(l.location_key ORDER BY l.location_key SEPARATOR ',')
  FROM locations l JOIN regions r ON l.region_id=r.id JOIN modules m ON r.module_id=m.id
  WHERE m.module_key='waerhaven';")
missing=$(python3 -c "
import json,sys
seen=set(json.loads(sys.argv[1])); want=set(sys.argv[2].split(','))
print(','.join(sorted(want-seen)) or 'none')" "$walked" "$authored")
# The Quiet Charter and the Underquay steps are hidden until Searched or told
# about, so they are allowed to be missing from a blind walk. Nothing else is.
missing=$(python3 -c "
import sys
m=[k for k in sys.argv[1].split(',') if k not in
   ('the_quiet_charter','underquay_stair','the_low_cellars','the_tide_gate',
    'the_drowned_room','the_deep_store','none')]
print(','.join(m) or 'none')" "$missing")
check "no unhidden location is cut off from the inn" \
  "$([ "$missing" = "none" ] && echo 1 || echo 0)" "unreachable: ${missing}"

# ---------------------------------------------------------------------------
say "The granary scene plays, and it is the one the act hangs off"
for _ in 1 2 3 4 5 6; do
  [ -n "$(api 'location/current' | field combat.id)" ] || break
  api 'combat/action' '{"action":"flee"}' >/dev/null
done
house=$(db -N -e "SELECT id FROM locations WHERE location_key='the_factors_house';")
api 'location/travel' "{\"location_id\":${house:-0}}" >/dev/null
node=$(api 'dialogue/node' "{\"npc_key\":\"bevis_culm\"}")
opens=$(echo "$node" | python3 -c "
import json,sys
d=json.load(sys.stdin).get('node') or {}
print('1' if 'Bevis Culm' in (d.get('text') or '') else '0')")
check "the Factor opens" "$opens" "$(echo "$node" | head -c 250)"

# greeting: 'Show me the granary.' -> the_tour, then walk past him into bay three
api 'dialogue/choose' "{\"npc_key\":\"bevis_culm\",\"node_key\":\"greeting\",\"choice\":0}" >/dev/null
api 'dialogue/choose' "{\"npc_key\":\"bevis_culm\",\"node_key\":\"the_tour\",\"choice\":0}" >/dev/null

pid=$(db -N -e "SELECT party_id FROM party_members WHERE character_id=${cid:-0} LIMIT 1;")
[ -z "$pid" ] && pid=$(db -N -e "SELECT id FROM parties WHERE user_id=(SELECT id FROM users WHERE username='${USER}') LIMIT 1;")
flags=$(db -N -e "SELECT GROUP_CONCAT(flag_key) FROM world_flags WHERE party_id=${pid:-0};")
check "granary_floor_seen is set" \
  "$(echo "$flags" | grep -q 'granary_floor_seen' && echo 1 || echo 0)" "flags: ${flags}"

quest=$(db -N -e "
  SELECT q.quest_key FROM party_quests pq JOIN quests q ON q.id=pq.quest_id
  WHERE pq.party_id=${pid:-0} AND q.quest_key='eleven_weeks';")
check "Eleven Weeks is in the journal" \
  "$([ "$quest" = "eleven_weeks" ] && echo 1 || echo 0)" "got '${quest}'"

# ---------------------------------------------------------------------------
say "The board at the Waerman's Rest carries this module's work and no other's"
for _ in 1 2 3 4 5 6; do
  [ -n "$(api 'location/current' | field combat.id)" ] || break
  api 'combat/action' '{"action":"flee"}' >/dev/null
done
rest=$(db -N -e "SELECT id FROM locations WHERE location_key='waermans_rest';")
api 'location/travel' "{\"location_id\":${rest:-0}}" >/dev/null
standing=$(db -N -e "SELECT l.location_key FROM characters c JOIN locations l ON l.id=c.current_location_id WHERE c.id=${cid:-0};")
check "the party can get back to the inn" \
  "$([ "$standing" = "waermans_rest" ] && echo 1 || echo 0)" "standing at '${standing}'"
board=$(api 'quest/board' '{}')
keys=$(echo "$board" | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(' '.join(q.get('quest_key','') for q in (d.get('board') or [])))")
check "Waerhaven work is posted" \
  "$(echo "$keys" | grep -qE 'the_carters_cellar|the_short_weight|nine_masts|the_light_goes_out' && echo 1 || echo 0)" \
  "board: ${keys}"
check "no other module's work leaks onto it" \
  "$(echo "$keys" | grep -qE 'cellar_rats|oldcity_|ledger_and_lie|uv_' && echo 0 || echo 1)" \
  "board: ${keys}"

# ---------------------------------------------------------------------------
printf '\n----------------------------------------------------\n'
printf '%d passed, %d failed\n' "$OK" "$FAILED"
[ "$FAILED" -eq 0 ] || exit 1
