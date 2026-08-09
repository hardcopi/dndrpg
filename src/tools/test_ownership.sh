#!/bin/bash
# Can one player reach another player's save?
#
# Driven over real HTTP with two cookie jars, because that is the layer the bug
# would live in. The PHP tests can prove Auth's arithmetic; only this can prove
# that the route a request actually travels applies it.
#
# Creates two throwaway accounts and a character for each, tries every way one
# has of touching the other, and deletes the accounts afterwards. Safe to run
# against a database somebody is playing on: it only ever removes what it made.
#
#   bash src/tools/test_ownership.sh
#
# Exits non-zero if any isolation check fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
A=$(mktemp); B=$(mktemp); ANON=$(mktemp)
SUFFIX=$RANDOM$RANDOM
PASS='a-good-long-password'
PASS_FAILED=0; PASS_OK=0

cleanup() {
  docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
    mysql -uweb -pdevpassword rpg_5e -e "
      DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'iso_${SUFFIX}_%');
      DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'iso_${SUFFIX}_%');
      DELETE FROM users WHERE username LIKE 'iso_${SUFFIX}_%';" 2>/dev/null
  rm -f "$A" "$B" "$ANON"
}
trap cleanup EXIT

api() { # api <jar> <route> [json]
  local jar=$1 route=$2
  if [ $# -ge 3 ]; then
    curl -s -b "$jar" -c "$jar" -X POST -H 'Content-Type: application/json' \
      -d "$3" "$BASE/api/index.php?r=$route"
  else
    curl -s -b "$jar" -c "$jar" "$BASE/api/index.php?r=$route"
  fi
}

# Assert a response is a refusal: ok:false. The message and code may vary (404
# to avoid confirming an id exists, 403 where it is already known to exist), so
# what is asserted is that it did NOT succeed.
refused() { # refused <name> <json>
  local name=$1 json=$2
  if echo "$json" | grep -q '"ok":false'; then
    PASS_OK=$((PASS_OK+1))
  else
    PASS_FAILED=$((PASS_FAILED+1))
    echo "FAIL  $name"
    echo "      got: $(echo "$json" | head -c 160)"
  fi
}

allowed() { # allowed <name> <json>
  local name=$1 json=$2
  if echo "$json" | grep -q '"ok":true'; then
    PASS_OK=$((PASS_OK+1))
  else
    PASS_FAILED=$((PASS_FAILED+1))
    echo "FAIL  $name"
    echo "      got: $(echo "$json" | head -c 160)"
  fi
}

# The HTML pages answer with a status code rather than with ok:false, so they
# need their own assertion. Note the trap this project sets for itself: the
# vhost ends in `try_files ... /index.php`, so a URL that does not exist on disk
# answers 200 with the homepage. A 200 here therefore has to be corroborated by
# what came back, which is why sheet_page() greps the body as well.
status_is() { # status_is <name> <expected> <actual>
  local name=$1 expected=$2 actual=$3
  if [ "$expected" = "$actual" ]; then
    PASS_OK=$((PASS_OK+1))
  else
    PASS_FAILED=$((PASS_FAILED+1))
    echo "FAIL  $name"
    echo "      expected HTTP $expected, got $actual"
  fi
}

# The printable character sheet, as a status code. -o /dev/null discards the
# body; --max-redirs 0 keeps the signed-out redirect visible as a 302 instead of
# following it to the login form's 200.
sheet_code() { # sheet_code <jar> <character_id>
  curl -s -o /dev/null --max-redirs 0 -w '%{http_code}' \
    -b "$1" "$BASE/sheet_print.php?character_id=$2"
}

jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval('d'+sys.argv[1]) if d else '')" "$1" 2>/dev/null; }

echo "== two players sign up =="
allowed "player A registers" "$(api "$A" auth/register "{\"username\":\"iso_${SUFFIX}_a\",\"password\":\"$PASS\"}")"
allowed "player B registers" "$(api "$B" auth/register "{\"username\":\"iso_${SUFFIX}_b\",\"password\":\"$PASS\"}")"

echo "== each makes a character =="
# Creation is a two-step on purpose: meta/roll_abilities puts six totals in the
# session, and character/create takes an arrangement of exactly those. The
# server decides what was rolled and the client only says where each score goes,
# so a body claiming six 18s is rejected. Same shape as the level-up hit die.
make_character() { # make_character <jar> <name>
  local jar=$1 name=$2
  local rolled
  rolled=$(api "$jar" meta/roll_abilities '{}' \
    | python3 -c "
import json,sys
sets = json.load(sys.stdin)['sets']
abilities = ['strength','dexterity','constitution','intelligence','wisdom','charisma']
print(json.dumps(dict(zip(abilities, [s['total'] for s in sets]))))
")
  api "$jar" character/create "$(python3 -c "
import json,sys
print(json.dumps({
    'method': 'random', 'name': sys.argv[2], 'race': 'Human', 'class': 'Fighter',
    'abilities': json.loads(sys.argv[1]),
}))
" "$rolled" "$name")"
}

RA=$(make_character "$A" "IsoA$SUFFIX")
RB=$(make_character "$B" "IsoB$SUFFIX")
allowed "A's character is created" "$RA"
allowed "B's character is created" "$RB"

CA=$(echo "$RA" | jget "['character']['id']")
CB=$(echo "$RB" | jget "['character']['id']")
PB=$(echo "$RB" | jget "['party_id']")
echo "   A=$CA  B=$CB (B's party $PB)"

if [ -z "$CA" ] || [ -z "$CB" ]; then
  echo "FAIL  could not create characters; the rest cannot run"
  exit 1
fi

echo "== the character list is scoped =="
LIST_A=$(api "$A" session/list)
if echo "$LIST_A" | grep -q "\"id\":$CB,"; then
  echo "FAIL  A's character list contains B's character"
  PASS_FAILED=$((PASS_FAILED+1))
else
  PASS_OK=$((PASS_OK+1))
fi
if echo "$LIST_A" | grep -q "\"id\":$CA,"; then PASS_OK=$((PASS_OK+1)); else
  echo "FAIL  A's list is missing A's own character"; PASS_FAILED=$((PASS_FAILED+1)); fi

echo "== A cannot reach B's character =="
refused "select B's character"        "$(api "$A" session/select "{\"character_id\":$CB}")"
refused "read B's sheet"              "$(api "$A" "character/sheet&character_id=$CB")"
refused "retire B's character"        "$(api "$A" character/retire "{\"character_id\":$CB}")"
refused "restore B's character"       "$(api "$A" character/restore "{\"character_id\":$CB}")"
refused "change B's combat rank"      "$(api "$A" character/rank "{\"character_id\":$CB,\"rank\":\"front\"}")"
refused "re-dress B's character"      "$(api "$A" character/avatar "{\"character_id\":$CB,\"sprite_key\":\"bard\"}")"
refused "join B's party"              "$(api "$A" character/create "{\"method\":\"random\",\"name\":\"Sneak$SUFFIX\",\"race\":\"Human\",\"class\":\"Rogue\",\"party_id\":$PB}")"
# `session/select` takes a party as well as a character now, because the
# character list plays a party rather than one of its members. That is a second
# id in the same body, and an unchecked one would hand over B's save by the
# scenic route -- the route would resolve it to one of B's characters itself and
# never ask whose it was.
refused "select B's party"            "$(api "$A" session/select "{\"party_id\":$PB}")"
# Two ids again, and the second one names somebody to hand kit to. Unchecked,
# A could post B's character as the recipient; the item would land in a save
# that is not theirs and the party rule in InventoryService::give() would never
# be reached, because it is asked after this.
refused "hand an item to B"           "$(api "$A" inventory/give "{\"inventory_id\":1,\"to_character_id\":$CB}")"
refused "equip out of B's bag"        "$(api "$A" inventory/equip "{\"character_id\":$CB,\"inventory_id\":1}")"

echo "== and A's own character still works =="
allowed "select A's own character"    "$(api "$A" session/select "{\"character_id\":$CA}")"
allowed "read A's own sheet"          "$(api "$A" "character/sheet&character_id=$CA")"

echo "== even while playing, B stays out of reach =="
# The sharpest case: A has an active session and party, which is exactly the
# state the old party-membership check would have accepted things under.
refused "read B's sheet mid-game"     "$(api "$A" "character/sheet&character_id=$CB")"

# inventory/list takes no character id — it reads the session's active character
# and ignores the query string entirely. So the assertion is not "B is refused"
# but "the parameter cannot redirect the route at somebody else": passing B's id
# must still return A's own inventory.
INV=$(api "$A" "inventory/list&character_id=$CB")
if echo "$INV" | grep -q "\"character_id\":$CB,"; then
  echo "FAIL  inventory/list honoured a character_id that is not the session's"
  PASS_FAILED=$((PASS_FAILED+1))
else
  PASS_OK=$((PASS_OK+1))
fi

echo "== the printable sheet is scoped too =="
# sheet_print.php reads the database directly rather than through a route, so
# none of the API's gates cover it — it has to stand behind Ownership on its
# own, and this is the only place that can prove it does. A 404 rather than a
# 403 for somebody else's character, deliberately: a 403 confirms the id exists,
# which would turn the print page into a way of counting other people's saves.
status_is "A prints their own sheet"          200 "$(sheet_code "$A" "$CA")"
status_is "A cannot print B's sheet"          404 "$(sheet_code "$A" "$CB")"
status_is "a sheet needs a character id"      400 "$(sheet_code "$A" 0)"
status_is "signed out, no sheet at all"       302 "$(sheet_code "$ANON" "$CA")"

# And what came back on the allowed one is really the sheet. A status-only check
# would pass for a file that is not there: the vhost falls through to index.php
# and serves the homepage with a 200.
#
# Captured first, and deliberately NOT `curl … | grep -q`. This file sets
# `pipefail`, and `grep -q` exits the moment it matches — which hands curl an
# EPIPE, kills it with a non-zero status, and makes pipefail report the whole
# pipeline as failed even though the match succeeded. Whether curl has finished
# writing before grep quits is a race, so it failed about one run in six and
# always on the assertion that was passing.
SHEET_BODY=$(curl -s -b "$A" "$BASE/sheet_print.php?character_id=$CA")
if printf '%s' "$SHEET_BODY" | grep -q 'Passive Wisdom'; then
  PASS_OK=$((PASS_OK+1))
else
  echo "FAIL  A's own sheet did not render (${#SHEET_BODY} bytes back)"
  PASS_FAILED=$((PASS_FAILED+1))
fi

echo "== the world routes answer for the session, not for a passed id =="
# location/* is the whole of standing somewhere: state, travel, search, loot,
# rest, camp. None of them takes a character id — every one reads the session's
# active character, the same shape as inventory/list above. So the assertion is
# again not "B is refused" but "no parameter can aim these at somebody else's
# save", which is the stronger claim: there is nothing to aim.
#
# It matters more here than on inventory/list because these routes WRITE. A
# forged id on location/travel would not leak B's position, it would move B.
allowed "A can look around"           "$(api "$A" location/state)"

STATE=$(api "$A" "location/state&character_id=$CB")
if [ "$(echo "$STATE" | jget "['character']['id']")" = "$CA" ]; then
  PASS_OK=$((PASS_OK+1))
else
  echo "FAIL  location/state honoured a character_id that is not the session's"
  echo "      got: $(echo "$STATE" | head -c 160)"
  PASS_FAILED=$((PASS_FAILED+1))
fi

# B stands somewhere. Note it through B's own session, so the check below is
# reading the world the way B's client would and not through a back door.
allowed "B is playing too"            "$(api "$B" session/select "{\"character_id\":$CB}")"
LB_BEFORE=$(api "$B" location/state | jget "['character']['current_location_id']")

# A walks. The destination is A's own first exit, read from A's own state —
# nothing here needs to know the content graph, so this keeps working when the
# world is re-authored.
DEST=$(api "$A" location/state | jget "['location']['exits'][0]['to_location_id']")
if [ -n "$DEST" ]; then
  TRAVEL=$(api "$A" location/travel "{\"location_id\":$DEST,\"character_id\":$CB}")
  allowed "A travels"                 "$TRAVEL"
  if [ "$(echo "$TRAVEL" | jget "['character']['id']")" = "$CA" ]; then
    PASS_OK=$((PASS_OK+1))
  else
    echo "FAIL  location/travel moved a character that is not the session's"
    PASS_FAILED=$((PASS_FAILED+1))
  fi
  LB_AFTER=$(api "$B" location/state | jget "['character']['current_location_id']")
  if [ "$LB_AFTER" = "$LB_BEFORE" ]; then
    PASS_OK=$((PASS_OK+1))
  else
    echo "FAIL  A's travel moved B: $LB_BEFORE -> $LB_AFTER"
    PASS_FAILED=$((PASS_FAILED+1))
  fi
else
  echo "FAIL  A's location has no exits; the travel checks cannot run"
  PASS_FAILED=$((PASS_FAILED+1))
fi

# The other writing routes, same story: they act on A or they refuse, and either
# way B's row is untouched. searching and camping are allowed to fail on their
# own terms (nothing to find, no ground to camp on) — what is asserted is that
# the smuggled id changed nothing.
api "$A" location/search "{\"character_id\":$CB}" >/dev/null
api "$A" location/camp   "{\"character_id\":$CB}" >/dev/null
api "$A" location/rest   "{\"character_id\":$CB}" >/dev/null
LB_STILL=$(api "$B" location/state | jget "['character']['current_location_id']")
if [ "$LB_STILL" = "$LB_BEFORE" ]; then
  PASS_OK=$((PASS_OK+1))
else
  echo "FAIL  search/camp/rest with B's id disturbed B"
  PASS_FAILED=$((PASS_FAILED+1))
fi

# And travel is bounded by the graph, not by whatever integer arrives: a
# location that does not exist is refused rather than written to the row.
refused "travel to a location that does not exist" \
  "$(api "$A" location/travel '{"location_id":999999}')"

echo "== signed out, nothing is reachable =="
refused "anonymous character list"    "$(api "$ANON" session/list)"
refused "anonymous sheet"             "$(api "$ANON" "character/sheet&character_id=$CA")"
refused "anonymous location state"    "$(api "$ANON" location/state)"
refused "anonymous travel"            "$(api "$ANON" location/travel "{\"location_id\":${DEST:-1}}")"
refused "anonymous content editor"    "$(api "$ANON" content/npcs)"

echo "== an ordinary player is not an admin =="
refused "player reaches admin users"       "$(api "$A" admin/users)"
refused "player opens the content editor"  "$(api "$A" content/npcs)"
refused "player rewrites an NPC"           "$(api "$A" content/save_npc '{"id":1,"name":"Trespass"}')"
refused "player moves an NPC"              "$(api "$A" content/place_npc '{"id":1,"location_id":1}')"
refused "player rewrites a quest"          "$(api "$A" content/save_quest '{"id":1,"title":"Trespass"}')"
refused "player exports the world"         "$(api "$A" admin/export '{}')"
refused "player makes themselves admin"    "$(api "$A" admin/set_role "{\"user_id\":1,\"role\":\"user\"}")"
# The tile editor is gone with the tile maps. Its whole route group must be
# unreachable, not merely unlisted — a leftover handler answering here would be
# a second door into content with no idea who is signed in, which is what
# RPG_EDITOR_PASSWORD was.
refused "the tile editor is gone"          "$(api "$A" editor/maps)"
refused "the tile writer is gone"          "$(api "$A" editor/tiles '{"map_id":1,"tiles":[]}')"
refused "the old editor password door is gone" "$(api "$A" editor/unlock '{"password":""}')"

echo "== the admin can =="
# Signed in as the real administrator, so this also proves the role gate is the
# thing granting access rather than the gate simply refusing everybody.
ADMIN_JAR=$(mktemp)
if [ -n "${ADMIN_PASS:-}" ]; then
  allowed "admin signs in"        "$(api "$ADMIN_JAR" auth/login "{\"username\":\"admin\",\"password\":\"$ADMIN_PASS\"}")"
  allowed "admin opens the content editor" "$(api "$ADMIN_JAR" content/npcs)"
  allowed "admin lists quests"    "$(api "$ADMIN_JAR" content/quests)"
  allowed "admin lists users"     "$(api "$ADMIN_JAR" admin/users)"
  allowed "admin sees every character" "$(api "$ADMIN_JAR" session/list)"
  refused "admin cannot demote the last admin" "$(api "$ADMIN_JAR" admin/set_role '{"user_id":1,"role":"user"}')"
else
  echo "   (skipped: set ADMIN_PASS to exercise the admin side)"
fi
rm -f "$ADMIN_JAR"

echo
echo "----------------------------------------------------"
echo "$PASS_OK passed, $PASS_FAILED failed"
exit $(( PASS_FAILED > 0 ? 1 : 0 ))
