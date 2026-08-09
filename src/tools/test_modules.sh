#!/bin/bash
# Can a party in one module see or reach another module's game?
#
# Driven over real HTTP for the same reason test_ownership.sh is: the boundary
# is enforced in the routes a request actually travels, and a PHP unit test can
# only prove the arithmetic of the clause, not that the clause is on the query
# the player's browser hits.
#
# Modules are kept apart by the exit graph — two clusters with no exit between
# them cannot be walked between, and nothing in the player UI lists regions
# globally. The job board is the exception, because it is a query rather than a
# walk, and that is what most of this exercises.
#
# Builds a throwaway second module (region, two locations, a job board, an NPC
# and a quest on the board), an account, and a party in each module. Removes
# everything it made afterwards, so it is safe to run against a database
# somebody is playing on.
#
#   bash src/tools/test_modules.sh
#
# Exits non-zero if any boundary check fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="mod_${SUFFIX}"
PASS='a-good-long-password'
MODKEY="testmod_${SUFFIX}"
FAILED=0; OK=0

db() {
  docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
    mysql -uweb -pdevpassword --default-character-set=utf8mb4 rpg_5e "$@" 2>/dev/null
}

cleanup() {
  # Order matters even with the FK cascade: the quest and NPC hang off the
  # locations, and the locations off the region, which cascades from the module.
  # Deleting the module alone would do it, but naming each makes a partial
  # failure legible instead of leaving mystery rows behind.
  db -e "
    DELETE FROM quests   WHERE quest_key = 'q_${MODKEY}';
    DELETE FROM npcs     WHERE npc_key   = 'npc_${MODKEY}';
    DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM users    WHERE username  = '${USER}';
    DELETE FROM modules  WHERE module_key = '${MODKEY}';"
  rm -f "$JAR"
}
trap cleanup EXIT

api() { # api <route> [json]
  local route=$1
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -X POST -H 'Content-Type: application/json' \
      -d "$2" "$BASE/api/index.php?r=$route"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/index.php?r=$route"
  fi
}

say() { printf '\n== %s ==\n' "$1"; }

check() { # check <description> <condition-result> [detail]
  if [ "$2" = "1" ]; then
    OK=$((OK + 1))
    printf '   ok   %s\n' "$1"
  else
    FAILED=$((FAILED + 1))
    printf '   FAIL %s\n' "$1"
    [ $# -ge 3 ] && printf '        %s\n' "$3"
  fi
}

# JSON field read without assuming jq is installed.
field() { python3 -c "
import json,sys
try: d=json.load(sys.stdin)
except Exception: print(''); sys.exit()
for k in sys.argv[1].split('.'):
    if isinstance(d, list):
        d = d[int(k)] if k.isdigit() and int(k) < len(d) else ''
    else:
        d = d.get(k, '') if isinstance(d, dict) else ''
print(d if d is not None else '')" "$1"; }

# ---------------------------------------------------------------------------
say "Build a throwaway second module"
# A whole small world: one region, two locations joined both ways, a job board
# on the opening one, an NPC to give work and a quest for them to post.
db -e "
INSERT INTO modules (module_key, name, blurb, start_location_key, level_min, level_max, sort_order)
VALUES ('${MODKEY}', 'Test Module', 'A throwaway world.', 'start_${MODKEY}', 1, 6, 900);

INSERT INTO regions (region_key, module_id, name, region_type, sort_order)
VALUES ('region_${MODKEY}', (SELECT id FROM modules WHERE module_key='${MODKEY}'),
        'Test Region', 'town', 900);

INSERT INTO locations (location_key, region_id, name, description, location_type,
                       map_x, map_y, has_job_board, sort_order)
VALUES
 ('start_${MODKEY}', (SELECT id FROM regions WHERE region_key='region_${MODKEY}'),
  'Test Start', 'The opening room of the throwaway module.', 'room', 30, 50, 1, 0),
 ('far_${MODKEY}',   (SELECT id FROM regions WHERE region_key='region_${MODKEY}'),
  'Test Far Room', 'The other room.', 'room', 70, 50, 0, 10);

INSERT INTO location_exits (from_location_id, to_location_id, label)
VALUES
 ((SELECT id FROM locations WHERE location_key='start_${MODKEY}'),
  (SELECT id FROM locations WHERE location_key='far_${MODKEY}'), 'Onward'),
 ((SELECT id FROM locations WHERE location_key='far_${MODKEY}'),
  (SELECT id FROM locations WHERE location_key='start_${MODKEY}'), 'Back');

INSERT INTO npcs (npc_key, name, location_id, role)
VALUES ('npc_${MODKEY}', 'Test Giver',
        (SELECT id FROM locations WHERE location_key='start_${MODKEY}'), 'Quartermaster');

INSERT INTO quests (quest_key, title, description, giver_npc_id, on_job_board,
                    required_level, is_active, act)
VALUES ('q_${MODKEY}', 'A Test Errand', 'Posted only in the test module.',
        (SELECT id FROM npcs WHERE npc_key='npc_${MODKEY}'), 1, 1, 1, 1);"

built=$(db -N -e "SELECT COUNT(*) FROM modules WHERE module_key='${MODKEY}';")
check "the second module exists" "$([ "$built" = "1" ] && echo 1 || echo 0)" "got '$built'"

# ---------------------------------------------------------------------------
say "An account to play both with"
api 'auth/register' "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" >/dev/null
logged=$(api 'auth/login' "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" | field ok)
check "registered and signed in" "$([ "$logged" = "True" ] && echo 1 || echo 0)" "got '$logged'"

mods=$(api 'session/modules')
listed=$(echo "$mods" | python3 -c "
import json,sys
d=json.load(sys.stdin)
keys=[m['module_key'] for m in d.get('modules',[])]
print('1' if 'rivermark' in keys and '${MODKEY}' in keys else '0')")
check "session/modules lists both games" "$listed" "$(echo "$mods" | head -c 300)"

# ---------------------------------------------------------------------------
say "A party made in the test module starts there"
mk_char() { # mk_char <name> <module-key-or-empty>
  local body="{\"name\":\"$1\",\"race\":\"Human\",\"class\":\"Fighter\",\"method\":\"standard\",\"abilities\":{\"strength\":15,\"dexterity\":14,\"constitution\":13,\"intelligence\":12,\"wisdom\":10,\"charisma\":8},\"background\":\"Soldier\",\"alignment\":\"Neutral\""
  [ -n "$2" ] && body="${body},\"module\":\"$2\""
  api 'character/create' "${body}}"
}

made=$(mk_char "Testmod Hero ${SUFFIX}" "${MODKEY}")
cid=$(echo "$made" | field character.id)
pid=$(echo "$made" | field party_id)
check "character created in the test module" \
  "$([ -n "$cid" ] && echo 1 || echo 0)" "$(echo "$made" | head -c 300)"

where=$(db -N -e "
  SELECT l.location_key FROM characters c
  JOIN locations l ON l.id = c.current_location_id WHERE c.id = ${cid:-0};")
check "starts at the test module's own opening room" \
  "$([ "$where" = "start_${MODKEY}" ] && echo 1 || echo 0)" \
  "started at '${where}', wanted 'start_${MODKEY}'"

pmod=$(db -N -e "
  SELECT m.module_key FROM parties p
  JOIN modules m ON m.id = p.module_id WHERE p.id = ${pid:-0};")
check "the party records which game it is playing" \
  "$([ "$pmod" = "${MODKEY}" ] && echo 1 || echo 0)" "party module is '${pmod}'"

# ---------------------------------------------------------------------------
say "The job board carries only this module's work"
api 'session/select' "{\"character_id\":${cid:-0}}" >/dev/null
# POST, and standing at the board: quest/board is the act of reading the notices
# where they hang, not a listing you can fetch from anywhere.
board=$(api 'quest/board' '{}')
board_keys=$(echo "$board" | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(' '.join(q['quest_key'] for q in d.get('board',[])))")
check "the test module's own job is posted" \
  "$(echo " $board_keys " | grep -q " q_${MODKEY} " && echo 1 || echo 0)" \
  "board = ${board_keys:-<empty>}"

riv_on_board=$(db -N -e "
  SELECT COUNT(*) FROM quests q
  JOIN npcs n ON n.id = q.giver_npc_id
  JOIN locations l ON l.id = n.location_id
  JOIN regions r ON r.id = l.region_id
  JOIN modules m ON m.id = r.module_id
  WHERE q.on_job_board = 1 AND m.module_key = 'rivermark'
    AND q.quest_key IN ($(echo "$board_keys" | tr ' ' '\n' | grep . |
        sed "s/.*/'&'/" | paste -sd, -                    || echo "''"));")
check "no Rivermark work leaks onto it" \
  "$([ "${riv_on_board:-0}" = "0" ] && echo 1 || echo 0)" \
  "${riv_on_board} Rivermark quest(s) appeared on the test module's board"

# ---------------------------------------------------------------------------
say "And the reverse, from a Rivermark party"
made2=$(mk_char "Rivermark Hero ${SUFFIX}" "rivermark")
cid2=$(echo "$made2" | field character.id)
check "character created in Rivermark" \
  "$([ -n "$cid2" ] && echo 1 || echo 0)" "$(echo "$made2" | head -c 300)"

where2=$(db -N -e "
  SELECT l.location_key FROM characters c
  JOIN locations l ON l.id = c.current_location_id WHERE c.id = ${cid2:-0};")
check "starts in the Golden Flagon, not the test module" \
  "$([ "$where2" = "flagon_common_room" ] && echo 1 || echo 0)" \
  "started at '${where2}'"

api 'session/select' "{\"character_id\":${cid2:-0}}" >/dev/null
board2=$(api 'quest/board' '{}' | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(' '.join(q['quest_key'] for q in d.get('board',[])))")
check "the test module's job is NOT on Rivermark's board" \
  "$(echo " $board2 " | grep -q " q_${MODKEY} " && echo 0 || echo 1)" \
  "board = ${board2:-<empty>}"
check "Rivermark's own board is not empty" \
  "$([ -n "$board2" ] && echo 1 || echo 0)" \
  "an empty board would make the check above pass for the wrong reason"

# ---------------------------------------------------------------------------
say "The two worlds are not joined by any exit"
crossing=$(db -N -e "
  SELECT COUNT(*) FROM location_exits e
  JOIN locations lf ON lf.id = e.from_location_id
  JOIN locations lt ON lt.id = e.to_location_id
  JOIN regions rf ON rf.id = lf.region_id
  JOIN regions rt ON rt.id = lt.region_id
  WHERE rf.module_id <> rt.module_id;")
check "no exit crosses a module boundary" \
  "$([ "${crossing:-1}" = "0" ] && echo 1 || echo 0)" \
  "${crossing} exit(s) cross between modules"

printf '\n----------------------------------------------------\n'
printf '%d passed, %d failed\n' "$OK" "$FAILED"
[ "$FAILED" -eq 0 ]
