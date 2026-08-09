#!/bin/bash
# Does a fight on the grid actually work over HTTP?
#
# tools/test_combat.php proves the geometry in isolation — that a cone covers
# the cells it should, that a path costs what it says. This proves the other
# half: that a real encounter, started through the real route against the real
# schema, puts people on a real battlefield and lets them walk about on it.
#
# The one test here worth more than all the others is the migration check. A
# session written by the rank engine has no coordinates, and the engine has to
# retire it rather than strand somebody on a board it cannot resolve. That path
# runs once, on the day this ships, on saves nobody can reproduce afterwards.
#
# Creates a throwaway account and character, fights, and deletes them again.
# Safe to run against a database somebody is playing on: it only ever removes
# what it made.
#
#   bash src/tools/test_grid_combat.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="grid_${SUFFIX}"
PASS='a-good-long-password'
PASS_FAILED=0; PASS_OK=0

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

cleanup() {
  sql "
    DELETE cs FROM combat_sessions cs
      INNER JOIN characters c ON c.id = cs.character_id
      INNER JOIN users u ON u.id = c.user_id WHERE u.username = '${USER}';
    DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM users WHERE username = '${USER}';"
  rm -f "$JAR"
}
trap cleanup EXIT

api() { # api <route> [json]
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -X POST -H 'Content-Type: application/json' \
      -d "$2" "$BASE/api/index.php?r=$1"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/index.php?r=$1"
  fi
}

pass() { PASS_OK=$((PASS_OK+1)); }
fail() { PASS_FAILED=$((PASS_FAILED+1)); echo "FAIL  $1"; [ $# -ge 2 ] && echo "      $2"; }
check() { if [ "$2" = "1" ]; then pass; else fail "$1" "${3:-}"; fi; }

# Run a snippet of python over the last combat JSON. Prints 1 or 0.
probe() { python3 -c "
import json,sys
d = json.load(sys.stdin)
s = (d.get('combat') or {}).get('state') or {}
print(1 if bool($1) else 0)
" 2>/dev/null <<<"$2"; }

jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval('d'+sys.argv[1]) if d else '')" "$1" 2>/dev/null; }

echo "== a player signs up and rolls a fighter =="
R=$(api auth/register "{\"username\":\"$USER\",\"password\":\"$PASS\"}")
check "registration" "$(echo "$R" | grep -c '"ok":true')" "$(head -c 200 <<<"$R")"

ROLLED=$(api meta/roll_abilities '{}' | python3 -c "
import json,sys
sets = json.load(sys.stdin)['sets']
keys = ['strength','dexterity','constitution','intelligence','wisdom','charisma']
print(json.dumps(dict(zip(keys, [s['total'] for s in sets]))))
")
RC=$(api character/create "$(python3 -c "
import json,sys
print(json.dumps({'method':'random','name':'Gridder','race':'Human','class':'Fighter',
                  'abilities': json.loads(sys.argv[1])}))
" "$ROLLED")")
CID=$(echo "$RC" | jget "['character']['id']")
check "character created" "$([ -n "$CID" ] && echo 1 || echo 0)" "$(head -c 200 <<<"$RC")"
[ -z "$CID" ] && { echo "cannot continue"; exit 1; }

echo "== a fight begins on a real battlefield =="
C=$(api combat/start '{"encounter_key":"cellar_rats_swarm"}')
check "combat started" "$(echo "$C" | grep -c '"ok":true')" "$(head -c 200 <<<"$C")"

check "the state declares its engine version" "$(probe "s.get('engine_version') == 2" "$C")"
check "there is a grid" "$(probe "'grid' in s" "$C")"
check "twelve rows of terrain" "$(probe "len(s['grid']['terrain']) == 12" "$C")"
check "sixteen characters wide" \
  "$(probe "all(len(r) == 16 for r in s['grid']['terrain'])" "$C")"
check "every terrain character is one the engine knows" \
  "$(probe "set(''.join(s['grid']['terrain'])) <= set('.,~on#v')" "$C")"
check "both deployment zones are declared" "$(probe "'party' in s['grid']['zones'] and 'foe' in s['grid']['zones']" "$C")"

check "everybody has integer coordinates" \
  "$(probe "all(isinstance(c.get('x'), int) and isinstance(c.get('y'), int) for c in s['combatants'])" "$C")"
check "nobody shares a cell" \
  "$(probe "len({(c['x'], c['y']) for c in s['combatants']}) == len(s['combatants'])" "$C")"
check "nobody is standing in a wall" \
  "$(probe "all(s['grid']['terrain'][c['y']][c['x']] in '.,~' for c in s['combatants'])" "$C")"
check "the party deployed in the party's zone" "$(probe "
all(s['grid']['zones']['party'][0] <= c['x'] <= s['grid']['zones']['party'][2]
    for c in s['combatants'] if c['side'] == 'party')" "$C")"
check "and the monsters in theirs" "$(probe "
all(s['grid']['zones']['foe'][0] <= c['x'] <= s['grid']['zones']['foe'][2]
    for c in s['combatants'] if c['side'] == 'foe')" "$C")"
check "everybody has a speed and an allowance of feet" \
  "$(probe "all(c.get('speed') is not None and c.get('move_left') is not None for c in s['combatants'])" "$C")"
check "and a reach" "$(probe "all('reach_ft' in c for c in s['combatants'])" "$C")"

echo "== the monsters take their turns, if they won initiative =="
# combat/start does not resolve monster turns — the client posts an action and
# the engine runs whatever monsters are ahead of it in the order. Doing that
# here is not a workaround: it is the first real exercise of the pathfinder,
# and if the AI cannot decide what to do on a generated map it hangs right here.
for _ in $(seq 1 20); do
  ACTOR=$(python3 -c "
import json,sys
s = json.load(sys.stdin)['combat']['state']
cid = s['order'][s['turn_index']]
print([c['type'] for c in s['combatants'] if c['cid'] == cid][0])" <<<"$C" 2>/dev/null)
  [ "$ACTOR" = "pc" ] && break
  C=$(api combat/action "{\"character_id\":$CID,\"action\":\"ai\"}")
done
check "it is a party member's turn" "$([ "$ACTOR" = "pc" ] && echo 1 || echo 0)" "actor was '$ACTOR'"
check "the fight survived the monster turns" "$(probe "s.get('status') == 'active'" "$C")"

echo "== the board is told what it may do =="
# The whole point of the derived block: the client never computes geometry.
check "the server ships a ui block" "$(probe "'ui' in s" "$C")"
check "it names reachable cells" "$(probe "len(s['ui']['reach']) > 1" "$C")"
check "each carries its cost and its predecessor" "$(probe "
all('cost' in v and 'from' in v and 'end' in v and 'provokes' in v for v in s['ui']['reach'].values())" "$C")"
check "the origin costs nothing and has no predecessor" "$(probe "
any(v['cost'] == 0 and v['from'] is None for v in s['ui']['reach'].values())" "$C")"
check "and the predecessor chain always terminates" "$(probe "
all(v['from'] is None or v['from'] in s['ui']['reach'] for v in s['ui']['reach'].values())" "$C")"
# `targets` and `threat` are PHP arrays, so an empty one comes back as [] and a
# populated one as an object. JavaScript does not care and neither does this,
# but a reader who assumed an object would.
check "it reports shot quality per target" "$(probe "
all(set(('dist','cover','long','mode')) <= set(t) for t in dict(s['ui']['targets']).values())" "$C")"

echo "== walking =="
# Pick a reachable, standable cell that is not where we already are, and that
# NOBODY GETS A FREE SWING AT US FOR REACHING.
#
# This used to take the most expensive cell in the set, which is the one most
# likely to cross somebody's reach — and a first-level fighter walking alone
# into two opportunity attacks is sometimes cut down on the way. When that
# happened the mover did not arrive, the party was wiped, the session closed,
# and seven assertions failed in a row: three about the walk and four about a
# fight that was already over. It failed about one run in three and had nothing
# to do with movement being wrong.
#
# `provokes` is on every reach cell already, put there by deriveUi() for the
# board to draw danger with. Preferring the furthest free cell keeps the test
# meaningful — it is still a real walk of real distance — and makes it settled.
DEST=$(python3 -c "
import json,sys
s = json.load(sys.stdin)['combat']['state']
u = s['ui']
best = safe = None
for key, v in u['reach'].items():
    if not v['end'] or v['cost'] == 0: continue
    if best is None or v['cost'] > best[1]:
        best = (key, v['cost'])
    if not v.get('provokes') and (safe is None or v['cost'] > safe[1]):
        safe = (key, v['cost'])
pick = safe or best
print(pick[0] if pick else '')
" <<<"$C")

if [ -n "$DEST" ]; then
  DX=${DEST%,*}; DY=${DEST#*,}
  BEFORE=$(probe "1" "$C" >/dev/null; python3 -c "
import json,sys
s = json.load(sys.stdin)['combat']['state']
me = [c for c in s['combatants'] if c['cid'] == s['ui']['actor']][0]
print(me['move_left'])" <<<"$C")

  M=$(api combat/action "{\"character_id\":$CID,\"action\":\"move\",\"x\":$DX,\"y\":$DY}")
  check "a legal move is accepted" "$(echo "$M" | grep -c '"ok":true')" "$(head -c 200 <<<"$M")"
  check "the mover ends up where they were sent" "$(python3 -c "
import json,sys
d = json.load(sys.stdin); s = d['combat']['state']
print(1 if any(c['x'] == $DX and c['y'] == $DY for c in s['combatants']) else 0)" <<<"$M")"
  check "a move event was emitted" "$(probe "any(e['type'] == 'move' for e in s.get('events', []))" "$M")"
  check "the path in the event starts and ends in the right places" "$(python3 -c "
import json,sys
s = json.load(sys.stdin)['combat']['state']
ev = [e for e in s.get('events', []) if e['type'] == 'move']
print(1 if ev and ev[0]['path'][-1] == [$DX, $DY] and ev[0]['path'][0] == ev[0]['from'] else 0)" <<<"$M")"
  check "and the feet were charged" "$(python3 -c "
import json,sys
s = json.load(sys.stdin)['combat']['state']
ev = [e for e in s.get('events', []) if e['type'] == 'move']
me = [c for c in s['combatants'] if c['x'] == $DX and c['y'] == $DY][0]
print(1 if ev and me['move_left'] == $BEFORE - ev[0]['cost'] else 0)" <<<"$M")"
else
  fail "could not find a cell to walk to"
fi

echo "== illegal moves are refused =="
BAD=$(api combat/action "{\"character_id\":$CID,\"action\":\"move\",\"x\":99,\"y\":99}")
check "off the board is refused" "$(echo "$BAD" | grep -c '"ok":false')" "$(head -c 200 <<<"$BAD")"

# A cell nobody could afford. The far corner of the enemy zone is always further
# than one turn's walk across a sixteen-wide board.
FAR=$(api combat/action "{\"character_id\":$CID,\"action\":\"move\",\"x\":14,\"y\":10}")
check "somewhere too far to walk is refused" "$(echo "$FAR" | grep -c '"ok":false')" "$(head -c 200 <<<"$FAR")"

GONE=$(api combat/action "{\"character_id\":$CID,\"action\":\"attack\",\"target_cid\":\"mon_nobody_0_0\"}")
check "attacking nothing is refused" "$(echo "$GONE" | grep -c '"ok":false')"

SWAP=$(api combat/action "{\"character_id\":$CID,\"action\":\"swap_rank\"}")
check "swap_rank is gone rather than quietly aliased" "$(echo "$SWAP" | grep -c '"ok":false')" "$(head -c 200 <<<"$SWAP")"

echo "== the fight reaches an end without deadlocking =="
# The AI now pathfinds, so a board it cannot cross would hang the turn loop
# rather than fail loudly. Ending turns until somebody wins proves it does not.
STATUS=active
ROUNDS=0
for _ in $(seq 1 120); do
  E=$(api combat/action "{\"character_id\":$CID,\"action\":\"end_turn\"}")
  read -r STATUS ROUNDS <<<"$(python3 -c "
import json,sys
try:
    s = json.load(sys.stdin)['combat']['state'] or {}
    print(s.get('status','?'), s.get('round',0))
except Exception: print('? 0')" <<<"$E")"
  [ "$STATUS" = "active" ] || break
done
check "the fight ended" "$([ "$STATUS" = "victory" ] || [ "$STATUS" = "defeat" ] && echo 1 || echo 0)" \
  "status was '$STATUS' after 120 turns"
# A monster that dithers in cover instead of engaging would still terminate the
# loop above — the party would simply forfeit every turn — so the round count is
# what actually proves the AI closes. Four rats crossing eighty feet and killing
# a stationary fighter should not take twenty rounds.
check "and did not drag on" "$([ "$ROUNDS" -le 15 ] && echo 1 || echo 0)" "took $ROUNDS rounds"

echo "== a session from the old engine is retired, not resumed =="
# Hand-written rank-engine state: combatants with a rank and no coordinates,
# and no engine_version at all. getActiveSession has to close it.
sql "INSERT INTO combat_sessions (character_id, location_id, encounter_id, party_id, current_turn, state_json, is_active)
     SELECT id, current_location_id, 1, NULL, 0,
       '{\"status\":\"active\",\"round\":1,\"turn_index\":0,\"order\":[\"pc_1\"],\"log\":[],\"events\":[],\"combatants\":[{\"cid\":\"pc_1\",\"side\":\"party\",\"rank\":\"front\",\"alive\":true,\"hp\":5,\"max_hp\":5,\"ac\":10,\"conditions\":[]}]}',
       1 FROM characters WHERE id = ${CID};"

OLD=$(api combat/status)
check "the old session is not handed back" "$(python3 -c "
import json,sys
print(1 if json.load(sys.stdin).get('combat') is None else 0)" <<<"$OLD")" "$(head -c 200 <<<"$OLD")"
LEFT=$(sql "SELECT COUNT(*) FROM combat_sessions WHERE character_id = ${CID} AND is_active = 1;")
check "and it has been marked inactive in the database" "$([ "${LEFT:-1}" = "0" ] && echo 1 || echo 0)" \
  "still $LEFT active"

echo
echo "----------------------------------------------------"
echo "$PASS_OK passed, $PASS_FAILED failed"
exit $([ "$PASS_FAILED" -gt 0 ] && echo 1 || echo 0)
