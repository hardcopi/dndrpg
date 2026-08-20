#!/bin/bash
# Can a party take a fight from the front page, and only a sane one?
#
# `combat/random` is the picker's second door: the fighting pit's own match,
# taken wherever the party is standing rather than at the arena in the Quarry
# Wilds — which is one location, in one module of four. What is worth asserting
# is not that a fight happens (tools/test_grid_combat.sh drives a real one) but
# the things that make this door different from that one:
#
#   * it works away from an arena, which combat/pit deliberately refuses;
#   * the difficulty the player pressed is the difficulty that gets built;
#   * it cannot deal a party two fights at once;
#   * it refuses a party with nobody on their feet, rather than dealing a fight
#     that ends on the first monster turn;
#   * its scratch encounter is unplaced — no region, no location — so the row it
#     writes can never be stumbled into on a travel roll;
#   * and it leaves the pit's own scratch row alone.
#
# What it does NOT cover is the level-up: the experience is granted by
# CombatEngine inside the action that ends the fight (settle -> finalizeCombat
# -> LevelUpService::record), which test_grid_combat.sh's fight already exercises,
# and the part that changed is the client offering the ceremony on the victory
# bar rather than after it. That is ui-combat.js and bash cannot press it.
#
# Creates a throwaway account and deletes it again. Safe to run against a
# database somebody is playing on.
#
#   bash src/tools/test_skirmish.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="skirm_${SUFFIX}"
PASS='a-good-long-password'
OK=0; BAD=0

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

cleanup() {
  sql "
    DELETE cs FROM combat_sessions cs
      INNER JOIN characters c ON c.id = cs.character_id
      INNER JOIN users u ON u.id = c.user_id WHERE u.username = '${USER}';
    DELETE e FROM encounters e
      INNER JOIN parties p ON e.encounter_key = CONCAT('_skirmish_party_', p.id)
      INNER JOIN users u ON u.id = p.user_id WHERE u.username = '${USER}';
    DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
    DELETE FROM users      WHERE username = '${USER}';"
  rm -f "$JAR"
}
trap cleanup EXIT

pass() { OK=$((OK+1)); echo "  ok   $1"; }
fail() { BAD=$((BAD+1)); echo "  FAIL $1${2:+  ($2)}"; }
check() { if [ "$2" = "1" ]; then pass "$1"; else fail "$1" "${3:-}"; fi }

api() {
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
         -X POST -d "$2" "$BASE/api/?r=$1"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/?r=$1"
  fi
}
# grep in a pipeline races curl under pipefail — see test_ownership.sh.
has() { printf '%s' "$1" | grep -q "$2" && echo 1 || echo 0; }
jq_() { python3 -c "$2" <<<"$1" 2>/dev/null || echo '?'; }

echo "== signed out, there is no fight to be had =="
OUT=$(curl -s -H 'Content-Type: application/json' -X POST -d '{}' "$BASE/api/?r=combat/random")
check "combat/random needs a session" "$(has "$OUT" '"ok":false')" "$(head -c 120 <<<"$OUT")"

curl -s -c "$JAR" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register" >/dev/null

A=$(api "character/create" \
  "{\"name\":\"Thrud${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"rivermark\"}")
PID=$(jq_ "$A" "
import json,sys
print(json.load(sys.stdin).get('party_id') or 0)")
api "character/create" \
  "{\"name\":\"Mira${SUFFIX}\",\"race\":\"Elf\",\"class\":\"Cleric\",\"module\":\"rivermark\",\"party_id\":${PID}}" >/dev/null
check "a party of two is made" "$([ "${PID:-0}" -gt 0 ] 2>/dev/null && echo 1 || echo 0)" "party ${PID}"

echo "== a fight, from wherever they are standing =="
# The party opens at the Golden Flagon, which is an inn and not an arena — so
# this is the case combat/pit refuses outright.
WHERE=$(sql "SELECT l.location_type FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             INNER JOIN locations l ON l.id = c.current_location_id
             WHERE cp.party_id = ${PID} LIMIT 1")
check "they are somewhere that is not an arena" \
  "$([ "$WHERE" != "arena" ] && echo 1 || echo 0)" "standing in a '${WHERE}'"

PIT=$(api "combat/pit" '{"tier":"fair"}')
check "and combat/pit refuses them there" "$(has "$PIT" '"ok":false')" "$(head -c 120 <<<"$PIT")"

R=$(api "combat/random" '{}')
SHAPE=$(jq_ "$R" "
import json,sys
d = json.load(sys.stdin)
c = d.get('combat') or {}
s = c.get('state') or {}
foes = [x for x in s.get('combatants', []) if x.get('side') == 'foe']
party = [x for x in s.get('combatants', []) if x.get('side') == 'party']
print(1 if d.get('ok') and c.get('is_active') and s.get('status') == 'active'
        and foes and len(party) == 2 else 0,
      len(foes), len(party), s.get('status'))")
check "combat/random deals them a live fight" "${SHAPE%% *}" "$(head -c 200 <<<"$R")"
read -r _ FOES PARTY STATUS <<<"$SHAPE"
check "with foes on the board and the whole party on it" \
  "$([ "${FOES:-0}" -ge 1 ] && [ "${PARTY:-0}" = "2" ] && echo 1 || echo 0)" \
  "${FOES} foes, ${PARTY} party, status ${STATUS}"

echo "== the row it fights out of is machinery, and unplaced =="
ROW=$(sql "SELECT COUNT(*), COALESCE(region_id,'null'), COALESCE(location_id,'null'), is_random
             FROM encounters WHERE encounter_key = '_skirmish_party_${PID}'")
read -r COUNT REGION LOC RANDOM_FLAG <<<"$ROW"
check "the scratch encounter exists" "$([ "${COUNT:-0}" = "1" ] && echo 1 || echo 0)" "${COUNT} row(s)"
check "and is in no region and no location, so nothing can walk into it" \
  "$([ "$REGION" = "null" ] && [ "$LOC" = "null" ] && echo 1 || echo 0)" \
  "region ${REGION}, location ${LOC}"
check "and is not in any travel roll's pool" \
  "$([ "${RANDOM_FLAG:-1}" = "0" ] && echo 1 || echo 0)" "is_random ${RANDOM_FLAG}"

MONS=$(sql "SELECT COALESCE(SUM(em.quantity),0) FROM encounter_monsters em
              INNER JOIN encounters e ON e.id = em.encounter_id
              WHERE e.encounter_key = '_skirmish_party_${PID}'")
check "and it has monsters in it" \
  "$([ "${MONS:-0}" -ge 1 ] 2>/dev/null && echo 1 || echo 0)" "${MONS} monster(s)"

PITROW=$(sql "SELECT COUNT(*) FROM encounters WHERE encounter_key = '_pit_party_${PID}'")
check "and the pit's own row was not touched" \
  "$([ "${PITROW:-1}" = "0" ] && echo 1 || echo 0)" "${PITROW} pit row(s)"

echo "== asking twice does not deal a second fight =="
FIRST=$(jq_ "$R" "
import json,sys
print((json.load(sys.stdin).get('combat') or {}).get('id') or 0)")
AGAIN=$(api "combat/random" '{}')
SAME=$(jq_ "$AGAIN" "
import json,sys
d = json.load(sys.stdin)
print(1 if d.get('resumed') and (d.get('combat') or {}).get('id') == ${FIRST} else 0,
      (d.get('combat') or {}).get('id'))")
check "the second ask hands back the fight they are already in" \
  "${SAME%% *}" "wanted ${FIRST}, got ${SAME#* }"

LIVE=$(sql "SELECT COUNT(*) FROM combat_sessions cs
              INNER JOIN characters c ON c.id = cs.character_id
              INNER JOIN users u ON u.id = c.user_id
              WHERE u.username = '${USER}' AND cs.is_active = 1")
check "and there is exactly one live session" \
  "$([ "${LIVE:-0}" = "1" ] && echo 1 || echo 0)" "${LIVE} session(s)"

echo "== the three difficulties are the engine's, and the one pressed is built =="
# The picker draws a button per row of PitEngine::TIERS as `session/sheet` ships
# them, so the page cannot offer a tier the engine does not have. What is
# checked on the way back is that the tier travelled: skirmish() writes the
# chosen tier's own blurb into the encounter's description, so the row says
# which of the three was actually rolled.
MINE=$(jq_ "$(api "session/list")" "
import json,sys
print(json.load(sys.stdin)['characters'][0]['id'])")
TIERS=$(jq_ "$(api "session/sheet&character_id=${MINE}")" "
import json,sys
ts = json.load(sys.stdin).get('tiers') or []
print(1 if [t['tier'] for t in ts] == ['warmup','fair','hard']
        and all(t.get('label') and t.get('blurb') for t in ts) else 0,
      ','.join(t['tier'] for t in ts) or '-')")
check "session/sheet ships the three tiers, easiest first" \
  "${TIERS%% *}" "got ${TIERS#* }"

for T in warmup fair hard; do
  # One fight at a time is CombatEngine's rule, so the last one is closed
  # before the next tier is asked for.
  sql "UPDATE combat_sessions cs
         INNER JOIN characters c ON c.id = cs.character_id
         INNER JOIN users u ON u.id = c.user_id
       SET cs.is_active = 0 WHERE u.username = '${USER}';"
  OUT=$(api "combat/random" "{\"tier\":\"${T}\"}")
  LIVE=$(jq_ "$OUT" "
import json,sys
d = json.load(sys.stdin)
s = (d.get('combat') or {}).get('state') or {}
print(1 if d.get('ok') and not d.get('resumed') and s.get('status') == 'active' else 0)")
  BLURB=$(sql "SELECT description FROM encounters WHERE encounter_key = '_skirmish_party_${PID}'")
  case "$T" in
    warmup) WANT='break a sweat' ;;
    fair)   WANT='evenly made' ;;
    hard)   WANT='money back' ;;
  esac
  check "a '${T}' fight starts" "$LIVE" "$(head -c 140 <<<"$OUT")"
  check "and the row it wrote is the ${T} one" \
    "$(has "$BLURB" "$WANT")" "description was '${BLURB}'"
done

# A tier nobody offers is not an error page: the picker sends what the server
# gave it, so anything else is a request that did not come from the picker, and
# a fair match is the honest thing to answer with.
sql "UPDATE combat_sessions cs
       INNER JOIN characters c ON c.id = cs.character_id
       INNER JOIN users u ON u.id = c.user_id
     SET cs.is_active = 0 WHERE u.username = '${USER}';"
ODD=$(api "combat/random" '{"tier":"impossible"}')
check "an unknown tier falls back rather than failing" \
  "$(has "$ODD" '"ok":true')" "$(head -c 140 <<<"$ODD")"
BADROW=$(sql "SELECT description FROM encounters WHERE encounter_key = '_skirmish_party_${PID}'")
check "and what it falls back to is the fair match" \
  "$(has "$BADROW" 'evenly made')" "description was '${BADROW}'"

echo "== nobody on their feet, no fight =="
sql "UPDATE combat_sessions cs
       INNER JOIN characters c ON c.id = cs.character_id
       INNER JOIN users u ON u.id = c.user_id
     SET cs.is_active = 0 WHERE u.username = '${USER}';
     UPDATE characters c INNER JOIN users u ON u.id = c.user_id
     SET c.current_hp = 0 WHERE u.username = '${USER}';"
DOWN=$(api "combat/random" '{}')
check "a wiped party is told to rest, not dealt monsters" \
  "$(has "$DOWN" '"ok":false')" "$(head -c 140 <<<"$DOWN")"
check "and the refusal says why" "$(has "$DOWN" 'Rest first')" "$(head -c 140 <<<"$DOWN")"

echo
echo "----------------------------------------------------"
echo "$OK passed, $BAD failed"
[ "$BAD" -eq 0 ]
