#!/bin/bash
# Does the front page / characters.php split hold up over real HTTP?
#
# Both pages group by party in JavaScript, which bash cannot run. What it can
# check is the contract that grouping stands on — that `session/list` actually
# returns a party id, a party NAME and a module key per character — and the
# things around it that are server-side: the guard, the module catalogue, the
# cover art the cards draw, and `session/sheet`, which is what the picker on
# the front page draws beside the list.
#
# The layout itself is judged in tools/home_preview.php, which draws both pages
# against fixtures with no account and no database behind them.
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

# Recruiting a companion is a thing that happens in a conversation, and playing
# one from bash is not what is being tested here. This calls the service the
# conversation would have called.
php_() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T php \
  php -r "require '/var/www/html/app/bootstrap.php'; $1" 2>/dev/null; }

cleanup() {
  sql "
    DELETE pc FROM party_companions pc
      INNER JOIN parties p ON p.id = pc.party_id
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

echo "== the page is behind the guard =="
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/characters.php")
check "a signed-out visitor is redirected away" \
  "$([ "$CODE" = "302" ] || [ "$CODE" = "303" ] && echo 1 || echo 0)" "HTTP $CODE"

CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php")
check "and so is the picker on the front page" \
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

echo "== the picker reads a sheet, and only your own =="
# The front page opens a character sheet beside the list, and it is deliberately
# NOT character/sheet: that route asks whether the character is in the game the
# session is playing, which is false for every character on this page except
# one. This is the wider question — is it yours — and the widening is the whole
# reason the route exists, so it is what gets tested.
MINE=$(jq_ "$LIST" "
import json,sys
print(json.load(sys.stdin)['characters'][0]['id'])")
SHEET=$(api "session/sheet&character_id=${MINE}")
KEYS=$(jq_ "$SHEET" "
import json,sys
d = json.load(sys.stdin)
s = d.get('sheet', {})
want = ('character','abilities','saves','skills','attacks','features',
        'proficiencies','spellcasting','proficiency_bonus','initiative',
        'passive_perception','hit_dice','inventory','carried_weight')
print(1 if all(k in s for k in want) else 0)")
check "session/sheet returns every box the page draws" "$KEYS" "$(head -c 160 <<<"$SHEET")"

# And the fields INSIDE those boxes, which is where a rename hides: the page
# prints `a.abbr` and `v.proficient` straight into the markup, so a column that
# quietly became something else does not fail — it draws the word "undefined"
# in a bubble on the front page.
ROWS=$(jq_ "$SHEET" "
import json,sys
s = json.load(sys.stdin)['sheet']
want = {
  'abilities':     ('abbr', 'score', 'mod'),
  'saves':         ('label', 'mod', 'proficient'),
  'skills':        ('label', 'ability', 'mod', 'proficient', 'expertise'),
  'attacks':       ('name', 'bonus', 'damage', 'damage_type', 'equipped', 'notes'),
  'features':      ('source', 'name', 'detail'),
  'proficiencies': ('label', 'value'),
}
missing = [f'{box}.{k}' for box, keys in want.items()
           for row in s.get(box) or [] for k in keys if k not in row]
print(1 if not missing else 0, ' '.join(sorted(set(missing))) or '-')")
check "and every row in them carries the fields the page prints" \
  "${ROWS%% *}" "missing ${ROWS#* }"

# The character row itself. `armor_class` and `gold` are pills on the sheet and
# `sprite_key` is the portrait; none of them are things CharacterSheet computes,
# so they come along on the row or not at all.
CHAR=$(jq_ "$SHEET" "
import json,sys
c = json.load(sys.stdin)['sheet']['character']
want = ('name','race','subrace','class','subclass','level','background','alignment',
        'current_hp','max_hp','armor_class','speed','gold','sprite_key')
print(1 if all(k in c for k in want) else 0,
      ' '.join(k for k in want if k not in c) or '-')")
check "and the character row carries the header and the pills" \
  "${CHAR%% *}" "missing ${CHAR#* }"

# The Play button is labelled with the adventure, which is the one thing on the
# sheet that is not on the sheet: a module belongs to the party, so it has to
# arrive in the context block or the button has nothing to say.
CTX=$(jq_ "$SHEET" "
import json,sys
c = json.load(sys.stdin).get('context', {})
print(1 if c.get('module_name') and c.get('party_id') and c.get('party_name') else 0, c.get('module_name'))")
check "and names the adventure the character is on" "${CTX%% *}" "context ${CTX#* }"

# A second account, to prove the route is not simply Ownership-shaped in its
# comments. 404 rather than 403 on purpose: a 403 confirms the id exists.
JAR2=$(mktemp)
USER2="chars_b_${SUFFIX}"
curl -s -c "$JAR2" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER2}\",\"password\":\"${PASS}\"}" \
  "$BASE/api/?r=auth/register" >/dev/null
DENIED=$(curl -s -b "$JAR2" "$BASE/api/?r=session/sheet&character_id=${MINE}")
check "somebody else's sheet is refused" "$(has "$DENIED" '"ok":false')" \
  "$(head -c 120 <<<"$DENIED")"
check "and refused as a 404, which does not confirm the id exists" \
  "$(has "$DENIED" 'No such character')" "$(head -c 120 <<<"$DENIED")"
sql "DELETE FROM users WHERE username = '${USER2}';"
rm -f "$JAR2"

echo "== the sheet carries the party, companions included =="
# The tabs across the top of a sheet are drawn from this, and the reason it is
# shipped by the server rather than filtered out of `session/list` is exactly
# the companion: that list is the characters you may PLAY and has none in it,
# so a party of two and Brother Aldric would have drawn two tabs and quietly
# lost the third.
php_ "(new CompanionService(db()))->recruit(${PID}, 'aldric');"
SHEET=$(api "session/sheet&character_id=${MINE}")
ROSTER=$(jq_ "$SHEET" "
import json,sys
p = json.load(sys.stdin).get('party') or []
comp = [m for m in p if m.get('companion')]
want = ('id','name','class','level','current_hp','max_hp','sprite_key','companion')
print(1 if len(p) == 3 and len(comp) == 1
        and all(k in m for m in p for k in want) else 0,
      len(p), len(comp))")
read -r OK_ROSTER NMEM NCOMP <<<"$ROSTER"
check "session/sheet ships the marching party with the companion in it" \
  "$OK_ROSTER" "${NMEM} members, ${NCOMP} companion(s)"

COMPID=$(jq_ "$SHEET" "
import json,sys
p = json.load(sys.stdin).get('party') or []
print(next((m['id'] for m in p if m.get('companion')), 0))")
COMPSHEET=$(api "session/sheet&character_id=${COMPID}")
check "and a companion's own sheet can be read, so the tab opens" \
  "$(has "$COMPSHEET" '"ok":true')" "$(head -c 140 <<<"$COMPSHEET")"

# The widening is for READING and stops there. Retiring still goes through
# assert_character_manageable, which is the gate the companion rule belongs to.
NORETIRE=$(api "character/retire" "{\"character_id\":${COMPID}}")
check "but they still cannot be retired from here" \
  "$(has "$NORETIRE" 'dismissed at camp')" "$(head -c 140 <<<"$NORETIRE")"

echo "== retiring somebody, which the picker is the only door to =="
# The route had no UI at all until the sheet grew a Retire button. What is
# asserted is the two rules that button now has to live with: not while the
# party is on a battlefield, and gone from the list afterwards.
FIGHT=$(api "combat/random" '{"tier":"warmup"}')
check "a fight is on" "$(has "$FIGHT" '"ok":true')" "$(head -c 120 <<<"$FIGHT")"
MIDFIGHT=$(api "character/retire" "{\"character_id\":${MINE}}")
check "retiring mid-fight is refused" \
  "$(has "$MIDFIGHT" 'fight on')" "$(head -c 140 <<<"$MIDFIGHT")"

sql "UPDATE combat_sessions cs
       INNER JOIN characters c ON c.id = cs.character_id
       INNER JOIN users u ON u.id = c.user_id
     SET cs.is_active = 0 WHERE u.username = '${USER}';"
GONE=$(api "character/retire" "{\"character_id\":${MINE}}")
check "and accepted once it is over" "$(has "$GONE" '"ok":true')" "$(head -c 140 <<<"$GONE")"

LEFT=$(jq_ "$(api "session/list")" "
import json,sys
print(sum(1 for c in json.load(sys.stdin)['characters'] if c['id'] == ${MINE}))")
check "and they are off the list the picker draws" \
  "$([ "${LEFT:-1}" = "0" ] && echo 1 || echo 0)" "${LEFT} row(s) left"

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
