#!/bin/bash
# Can a player delete their own character, and nobody else's, and is a deleted
# character really gone from everything that reads one?
#
# `character/delete` is retiring plus `characters.deleted_at`. What is worth
# asserting is where the two differ and where delete must hold a line retire
# never had to:
#
#   * the CSRF token, the method and the session are required, like any POST;
#   * only the OWNER may delete — another account gets a 404 and the row is
#     untouched, and so does an admin, who may read anybody's save but not
#     delete it from here;
#   * a companion is refused, as retire refuses one;
#   * a party on a battlefield is refused with 409, because the fight holds its
#     combatants in state_json;
#   * once deleted, the character is in no list, no sheet and no party, and
#     restore — retire's undo — refuses them;
#   * the row is still there with deleted_at set, so a mistake is an UPDATE;
#   * the last character may go (retire refuses that), and a second browser
#     still holding a deleted character is let go of on its next status check.
#
# Creates throwaway accounts and deletes them again. Safe to run against a
# database somebody is playing on.
#
#   bash src/tools/test_char_delete.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp); JAR2=$(mktemp); OTHER=$(mktemp); ADMIN=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="cdel_${SUFFIX}"
STRANGER="cdelx_${SUFFIX}"
BOSS="cdeladm_${SUFFIX}"
PASS='a-good-long-password'
OK=0; BAD=0

csrf_of() { # csrf_of <jar>
  curl -s -b "$1" -c "$1" "$BASE/api/index.php?r=auth/status" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("csrf_token") or "")'
}

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

php_() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T php \
  php -r "require '/var/www/html/app/bootstrap.php'; $1" 2>/dev/null; }

MINE="SELECT id FROM users WHERE username IN ('${USER}','${STRANGER}','${BOSS}')"
cleanup() {
  sql "
    DELETE cs FROM combat_sessions cs
      INNER JOIN characters c ON c.id = cs.character_id WHERE c.user_id IN (${MINE});
    DELETE e FROM encounters e
      INNER JOIN parties p ON e.encounter_key = CONCAT('_skirmish_party_', p.id)
      WHERE p.user_id IN (${MINE});
    DELETE pc FROM party_companions pc
      INNER JOIN parties p ON p.id = pc.party_id WHERE p.user_id IN (${MINE});
    DELETE cp FROM character_party cp
      INNER JOIN parties p ON p.id = cp.party_id WHERE p.user_id IN (${MINE});
    DELETE FROM characters WHERE user_id IN (${MINE});
    DELETE FROM parties    WHERE user_id IN (${MINE});
    DELETE FROM users      WHERE username IN ('${USER}','${STRANGER}','${BOSS}');"
  rm -f "$JAR" "$JAR2" "$OTHER" "$ADMIN"
}
trap cleanup EXIT

pass() { OK=$((OK+1)); echo "  ok   $1"; }
fail() { BAD=$((BAD+1)); echo "  FAIL $1${2:+  ($2)}"; }
check() { if [ "$2" = "1" ]; then pass "$1"; else fail "$1" "${3:-}"; fi }

# api <jar> <route> [json body]   — POSTs carry that jar's own CSRF token.
api() {
  local jar=$1 route=$2
  if [ $# -ge 3 ]; then
    curl -s -H "X-CSRF-Token: $(csrf_of "$jar")" -b "$jar" -c "$jar" \
         -H 'Content-Type: application/json' -X POST -d "$3" "$BASE/api/?r=$route"
  else
    curl -s -b "$jar" -c "$jar" "$BASE/api/?r=$route"
  fi
}
# The status code and the body, for the refusals where the code is the point.
code_of() { # code_of <jar> <route> <body> [csrf header value]
  local hdr=()
  [ $# -ge 4 ] && hdr=(-H "X-CSRF-Token: $4")
  curl -s -o /dev/null -w '%{http_code}' "${hdr[@]}" -b "$1" -c "$1" \
       -H 'Content-Type: application/json' -X POST -d "$3" "$BASE/api/?r=$2"
}
has() { printf '%s' "$1" | grep -q "$2" && echo 1 || echo 0; }
jq_() { python3 -c "$2" <<<"$1" 2>/dev/null || echo '?'; }
deleted_at() { sql "SELECT COALESCE(deleted_at,'null') FROM characters WHERE id = $1"; }
register() { curl -s -c "$1" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"$2\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register" >/dev/null; }
id_of() { jq_ "$1" "
import json,sys
print(json.load(sys.stdin)['character']['id'])"; }

echo "== setting up: a party of two and a companion, and a stranger =="
register "$JAR" "$USER"
register "$OTHER" "$STRANGER"
register "$ADMIN" "$BOSS"
sql "UPDATE users SET role = 'admin' WHERE username = '${BOSS}';"

A=$(api "$JAR" "character/create" \
  "{\"name\":\"Thrud${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"rivermark\"}")
THRUD=$(id_of "$A")
PID=$(jq_ "$A" "
import json,sys
print(json.load(sys.stdin).get('party_id') or 0)")
MIRA=$(id_of "$(api "$JAR" "character/create" \
  "{\"name\":\"Mira${SUFFIX}\",\"race\":\"Elf\",\"class\":\"Cleric\",\"module\":\"rivermark\",\"party_id\":${PID}}")")
php_ "(new CompanionService(db()))->recruit(${PID}, 'aldric');"
COMPID=$(sql "SELECT c.id FROM characters c
                INNER JOIN character_party cp ON cp.character_id = c.id
               WHERE cp.party_id = ${PID} AND c.companion_key IS NOT NULL LIMIT 1")
BOB=$(id_of "$(api "$OTHER" "character/create" \
  "{\"name\":\"Bob${SUFFIX}\",\"race\":\"Human\",\"class\":\"Rogue\",\"module\":\"rivermark\"}")")
check "Thrud, Mira, a companion and a stranger's Bob all exist" \
  "$([ "${THRUD:-0}" -gt 0 ] && [ "${MIRA:-0}" -gt 0 ] && [ "${COMPID:-0}" -gt 0 ] \
      && [ "${BOB:-0}" -gt 0 ] 2>/dev/null && echo 1 || echo 0)" \
  "thrud ${THRUD} mira ${MIRA} companion ${COMPID} bob ${BOB}"

echo "== it is a POST like the others: session, method, token =="
C=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
    -X POST -d "{\"character_id\":${THRUD}}" "$BASE/api/?r=character/delete")
check "signed out is 401" "$([ "$C" = "401" ] && echo 1 || echo 0)" "got ${C}"
C=$(code_of "$JAR" "character/delete" "{\"character_id\":${THRUD}}")
check "no CSRF token is 403" "$([ "$C" = "403" ] && echo 1 || echo 0)" "got ${C}"
C=$(code_of "$JAR" "character/delete" "{\"character_id\":${THRUD}}" "not-the-token")
check "a wrong CSRF token is 403" "$([ "$C" = "403" ] && echo 1 || echo 0)" "got ${C}"
C=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/api/?r=character/delete&character_id=${THRUD}")
check "a GET is 405" "$([ "$C" = "405" ] && echo 1 || echo 0)" "got ${C}"
check "and after all that Thrud is untouched" \
  "$([ "$(deleted_at "$THRUD")" = "null" ] && echo 1 || echo 0)"

echo "== only the owner =="
R=$(api "$OTHER" "character/delete" "{\"character_id\":${THRUD}}")
check "a stranger deleting Thrud is told there is no such character" \
  "$(has "$R" 'No such character')" "$(head -c 140 <<<"$R")"
R=$(api "$ADMIN" "character/delete" "{\"character_id\":${THRUD}}")
check "and so is an admin" "$(has "$R" 'No such character')" "$(head -c 140 <<<"$R")"
ACTIVE=$(sql "SELECT is_active FROM characters WHERE id = ${THRUD}")
check "and Thrud is still there, active and not deleted" \
  "$([ "$(deleted_at "$THRUD")" = "null" ] && [ "$ACTIVE" = "1" ] && echo 1 || echo 0)"

echo "== not a companion =="
C=$(code_of "$JAR" "character/delete" "{\"character_id\":${COMPID}}" "$(csrf_of "$JAR")")
check "deleting Brother Aldric is 403" "$([ "$C" = "403" ] && echo 1 || echo 0)" "got ${C}"
check "and he is not marked deleted" "$([ "$(deleted_at "$COMPID")" = "null" ] && echo 1 || echo 0)"

echo "== not mid-fight =="
api "$JAR" "session/select" "{\"character_id\":${THRUD}}" >/dev/null
F=$(api "$JAR" "combat/random" '{}')
check "the party takes a fight" "$(has "$F" '"ok":true')" "$(head -c 140 <<<"$F")"
C=$(code_of "$JAR" "character/delete" "{\"character_id\":${MIRA}}" "$(csrf_of "$JAR")")
check "deleting a member while it is on is 409" "$([ "$C" = "409" ] && echo 1 || echo 0)" "got ${C}"
R=$(api "$JAR" "character/delete" "{\"character_id\":${MIRA}}")
check "and says why" "$(has "$R" 'fight on')" "$(head -c 140 <<<"$R")"
check "and Mira is not deleted" "$([ "$(deleted_at "$MIRA")" = "null" ] && echo 1 || echo 0)"
sql "UPDATE combat_sessions cs INNER JOIN characters c ON c.id = cs.character_id
     SET cs.is_active = 0 WHERE c.user_id IN (${MINE});"

echo "== deleting Thrud, whom the session is playing =="
# A second browser on the same account, also playing Thrud: session_step_off()
# only reaches the session that pressed the button.
curl -s -c "$JAR2" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/login" >/dev/null
api "$JAR2" "session/select" "{\"character_id\":${THRUD}}" >/dev/null

R=$(api "$JAR" "character/delete" "{\"character_id\":${THRUD}}")
check "the owner may delete them" "$(has "$R" '"ok":true')" "$(head -c 160 <<<"$R")"
NEXT=$(jq_ "$R" "
import json,sys
print(json.load(sys.stdin).get('active_character_id') or 0)")
check "and the session moves to Mira, who is left" \
  "$([ "$NEXT" = "$MIRA" ] && echo 1 || echo 0)" "moved to ${NEXT}"

LIST=$(api "$JAR" "session/list")
GONE=$(jq_ "$LIST" "
import json,sys
ids = [c['id'] for c in json.load(sys.stdin)['characters']]
print(1 if ${THRUD} not in ids and ${MIRA} in ids else 0)")
check "Thrud is gone from session/list, Mira is not" "$GONE" "$(head -c 160 <<<"$LIST")"
C=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/api/?r=session/sheet&character_id=${THRUD}")
check "session/sheet refuses them (404)" "$([ "$C" = "404" ] && echo 1 || echo 0)" "got ${C}"
C=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/api/?r=character/sheet&character_id=${THRUD}")
check "character/sheet refuses them" "$([ "$C" = "403" ] || [ "$C" = "404" ] && echo 1 || echo 0)" "got ${C}"
C=$(code_of "$JAR" "session/select" "{\"character_id\":${THRUD}}" "$(csrf_of "$JAR")")
check "session/select refuses them (404)" "$([ "$C" = "404" ] && echo 1 || echo 0)" "got ${C}"
ROSTER=$(jq_ "$(api "$JAR" "session/sheet&character_id=${MIRA}")" "
import json,sys
print(' '.join(str(m['id']) for m in json.load(sys.stdin).get('party') or []))")
check "Mira's party tabs no longer list them" \
  "$(case " $ROSTER " in *" $THRUD "*) echo 0 ;; *) echo 1 ;; esac)" "party: ${ROSTER}"
INPARTY=$(sql "SELECT COUNT(*) FROM character_party WHERE character_id = ${THRUD}")
check "and no party row points at them" "$([ "$INPARTY" = "0" ] && echo 1 || echo 0)" "${INPARTY} row(s)"
R=$(api "$JAR" "character/restore" "{\"character_id\":${THRUD}}")
check "restore refuses them" "$(has "$R" 'No such character')" "$(head -c 140 <<<"$R")"
R=$(api "$JAR" "character/delete" "{\"character_id\":${THRUD}}")
check "and deleting them twice is a 404, not a second timestamp" \
  "$(has "$R" 'No such character')" "$(head -c 140 <<<"$R")"

ROW=$(sql "SELECT is_active, deleted_at IS NOT NULL FROM characters WHERE id = ${THRUD}")
check "the row is still there: is_active 0, deleted_at set" \
  "$([ "$ROW" = "$(printf '0\t1')" ] && echo 1 || echo 0)" "row: ${ROW}"

S2=$(api "$JAR2" "session/status")
check "the other browser playing Thrud is let go of" \
  "$(jq_ "$S2" "
import json,sys
print(1 if json.load(sys.stdin).get('character_id') is None else 0)")" "$(head -c 140 <<<"$S2")"

R=$(api "$JAR" "character/create" \
  "{\"name\":\"Thrud${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"rivermark\"}")
check "the name is free again" "$(has "$R" '"ok":true')" "$(head -c 140 <<<"$R")"
THRUD2=$(id_of "$R")

echo "== the last character may go, unlike retire =="
api "$JAR" "character/delete" "{\"character_id\":${THRUD2}}" >/dev/null
api "$JAR" "session/select" "{\"character_id\":${MIRA}}" >/dev/null
R=$(api "$JAR" "character/delete" "{\"character_id\":${MIRA}}")
check "deleting the only one left is allowed" "$(has "$R" '"ok":true')" "$(head -c 140 <<<"$R")"
N=$(jq_ "$(api "$JAR" "session/list")" "
import json,sys
print(len(json.load(sys.stdin)['characters']))")
check "and the list is empty" "$([ "$N" = "0" ] && echo 1 || echo 0)" "${N} left"
S=$(api "$JAR" "session/status")
check "and the session is playing nobody, in no party" "$(jq_ "$S" "
import json,sys
d = json.load(sys.stdin)
print(1 if d.get('character_id') is None and d.get('party_id') is None else 0)")" "$(head -c 140 <<<"$S")"

check "the stranger's Bob was never touched" \
  "$([ "$(deleted_at "$BOB")" = "null" ] && echo 1 || echo 0)"

echo
echo "----------------------------------------------------"
echo "$OK passed, $BAD failed"
[ "$BAD" -eq 0 ]
