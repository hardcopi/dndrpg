#!/bin/bash
# Does the front page / characters.php split hold up over real HTTP?
#
# The page groups by party in JavaScript, which bash cannot run. What it can
# check is the contract that grouping stands on — that `session/list` actually
# returns a party id, a party NAME and a module key per character — and the
# things around it that are server-side: the guard, the module catalogue, and
# the cover art the cards draw.
#
# The party name is the point. It was not in the query at all until this page
# needed it; the list came back with `party_id` and nothing to call it, so
# every heading would have read "A party" and the grouping would have looked
# broken rather than unlabelled.
#
# Creates a throwaway account, two parties in one module, and deletes them
# again. Safe to run against a database somebody is playing on.
#
#   bash src/tools/test_characters_page.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="chars_${SUFFIX}"
PASS='a-good-long-password'
OK=0; BAD=0

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

cleanup() {
  sql "
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

echo "== the page is behind the guard =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/characters.php")
check "a signed-out visitor is redirected away" \
  "$([ "$CODE" = "302" ] || [ "$CODE" = "303" ] && echo 1 || echo 0)" "HTTP $CODE"

curl -s -c "$JAR" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register" >/dev/null

CT=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{content_type}' \
     "$BASE/characters.php?module=rivermark")
check "and a signed-in one gets the page" \
  "$(has "$CT" '^200 text/html')" "got '$CT'"

echo "== the catalogue has every module on it =="
MODS=$(api "session/modules")
COUNT=$(jq_ "$MODS" "
import json,sys
print(len(json.load(sys.stdin)['modules']))")
check "session/modules returns three or more" \
  "$([ "${COUNT:-0}" -ge 3 ] 2>/dev/null && echo 1 || echo 0)" "$COUNT module(s)"

# The cards draw cover art by convention -- assets/images/modules/<key>.jpg --
# and a missing one is invisible, because the vhost answers with the homepage
# HTML and a 200. Content type is the only honest check; see CLAUDE.md.
KEYS=$(jq_ "$MODS" "
import json,sys
print(' '.join(m['module_key'] for m in json.load(sys.stdin)['modules']))")
for K in $KEYS; do
  TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/assets/images/modules/$K.jpg")
  check "$K has cover art" "$(has "$TYPE" 'image/')" "served '$TYPE'"
done

echo "== two parties in one module, and the list can tell them apart =="
A=$(api "character/create" \
  "{\"name\":\"Alba${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"rivermark\"}")
B=$(api "character/create" \
  "{\"name\":\"Bram${SUFFIX}\",\"race\":\"Elf\",\"class\":\"Rogue\",\"module\":\"rivermark\"}")
check "two characters are made" \
  "$([ "$(has "$A" '"ok":true')" = 1 ] && [ "$(has "$B" '"ok":true')" = 1 ] && echo 1 || echo 0)"

# The second joins the first's party, so one party has two members and the
# other has one -- a grouping that collapses to one block per character would
# still pass a test where every party held exactly one.
PID=$(jq_ "$A" "
import json,sys
d=json.load(sys.stdin);print(d.get('party_id') or d.get('party',{}).get('id') or 0)")
C=$(api "character/create" \
  "{\"name\":\"Cass${SUFFIX}\",\"race\":\"Dwarf\",\"class\":\"Cleric\",\"module\":\"rivermark\",\"party_id\":${PID}}")
check "and a third joins the first party" "$(has "$C" '"ok":true')" "$(head -c 160 <<<"$C")"

LIST=$(api "session/list")
FIELDS=$(jq_ "$LIST" "
import json,sys
cs = json.load(sys.stdin)['characters']
want = ('party_id', 'party_name', 'module_key', 'sprite_key')
print(1 if cs and all(k in c for c in cs for k in want) else 0)")
check "every row carries party_id, party_name, module_key and sprite_key" "$FIELDS"

NAMED=$(jq_ "$LIST" "
import json,sys
cs = json.load(sys.stdin)['characters']
print(1 if cs and all(c['party_name'] for c in cs if c['party_id']) else 0)")
check "and the party name is actually populated, not NULL" "$NAMED"

SHAPE=$(jq_ "$LIST" "
import json,sys
from collections import Counter
cs = [c for c in json.load(sys.stdin)['characters'] if c['module_key'] == 'rivermark']
sizes = sorted(Counter(c['party_id'] for c in cs).values(), reverse=True)
print(1 if sizes[:2] == [2, 1] else 0, sizes)")
check "the three group into a party of two and a party of one" \
  "${SHAPE%% *}" "sizes ${SHAPE#* }"

echo "== one Play per party: session/select takes the party =="
# The page draws a single Play on each party's heading, and it sends the party
# id. Who that means is the server's decision -- the leader, or whoever you were
# already playing -- so what is asserted here is that a party id selects a
# member of that party at all, and that it keeps you as the one you were.
SEL=$(api "session/select" "{\"party_id\":${PID}}")
CHOSEN=$(jq_ "$SEL" "
import json,sys
d=json.load(sys.stdin);print(d.get('character',{}).get('id') or 0)")
MEMBERS=$(jq_ "$LIST" "
import json,sys
cs=json.load(sys.stdin)['characters']
print(' '.join(str(c['id']) for c in cs if c['party_id'] == ${PID}))")
check "a party id selects one of that party's characters" \
  "$(has " $MEMBERS " " $CHOSEN ")" "picked $CHOSEN of [$MEMBERS]"

# The other member of the same party, made active by name so the next call has
# somebody to prefer that is NOT whoever the leader rule would pick.
OTHER_ID=$(jq_ "$LIST" "
import json,sys
cs=json.load(sys.stdin)['characters']
print(next((str(c['id']) for c in cs if c['party_id'] == ${PID} and c['id'] != ${CHOSEN}), ''))")
if [ -n "$OTHER_ID" ]; then
  api "session/select" "{\"character_id\":${OTHER_ID}}" >/dev/null
  AGAIN=$(jq_ "$(api "session/select" "{\"party_id\":${PID}}")" "
import json,sys
print(json.load(sys.stdin).get('character',{}).get('id') or 0)")
  check "and playing the party again resumes whoever you left it as" \
    "$([ "$AGAIN" = "$OTHER_ID" ] && echo 1 || echo 0)" "wanted $OTHER_ID, got $AGAIN"
fi

echo "== and the module scopes the page =="
OTHER=$(jq_ "$LIST" "
import json,sys
cs = json.load(sys.stdin)['characters']
print(sum(1 for c in cs if c['module_key'] != 'rivermark'))")
check "nothing of this account's sits outside the module it was made in" \
  "$([ "$OTHER" = "0" ] && echo 1 || echo 0)" "$OTHER stray"

echo
echo "----------------------------------------------------"
echo "$OK passed, $BAD failed"
[ "$BAD" -eq 0 ]
