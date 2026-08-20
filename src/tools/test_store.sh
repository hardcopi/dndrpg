#!/bin/bash
# The general store and the bag, from the character picker.
#
# Both are the game's own machinery pointed at a character the session is not
# playing: `inventory/equip`, `buy` and `sell` are the routes the shop panel in
# game uses, and the only new thing is a shop with no shopkeeper —
# GeneralStore writes `shop_inventory` rows under a reserved key so that
# everything downstream of it is the ordinary path.
#
# What is worth asserting is the shelf's rule and the widened gate:
#
#   * nothing on the shelf is enchanted past +1, and nothing is a quest item;
#   * re-stocking twice does not double the shelf;
#   * buying and selling and equipping all work for an owned character who is
#     NOT the one the session is playing — which is every character on that
#     screen except one;
#   * and none of it works for somebody else's character.
#
# Creates a throwaway account and deletes it again. Safe to run against a
# database somebody is playing on.
#
#   bash src/tools/test_store.sh
#
# Exits non-zero if anything fails.
set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
JAR=$(mktemp); JAR2=$(mktemp)
SUFFIX=$RANDOM$RANDOM
USER="store_${SUFFIX}"; USER2="store_b_${SUFFIX}"
PASS='a-good-long-password'
OK=0; BAD=0

sql() { docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
  mysql -uweb -pdevpassword --default-character-set=utf8mb4 -N -B rpg_5e -e "$1" 2>/dev/null; }

cleanup() {
  for U in "$USER" "$USER2"; do
    sql "
      DELETE FROM character_inventory WHERE character_id IN
        (SELECT id FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${U}'));
      DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${U}');
      DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${U}');
      DELETE FROM users      WHERE username = '${U}';"
  done
  rm -f "$JAR" "$JAR2"
}
trap cleanup EXIT

pass() { OK=$((OK+1)); echo "  ok   $1"; }
fail() { BAD=$((BAD+1)); echo "  FAIL $1${2:+  ($2)}"; }
check() { if [ "$2" = "1" ]; then pass "$1"; else fail "$1" "${3:-}"; fi }
api() {
  if [ $# -ge 2 ]; then
    curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' -X POST -d "$2" "$BASE/api/?r=$1"
  else
    curl -s -b "$JAR" -c "$JAR" "$BASE/api/?r=$1"
  fi
}
has() { printf '%s' "$1" | grep -q "$2" && echo 1 || echo 0; }
jq_() { python3 -c "$2" <<<"$1" 2>/dev/null || echo '?'; }

curl -s -c "$JAR" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register" >/dev/null
A=$(api "character/create" \
  "{\"name\":\"Buyer${SUFFIX}\",\"race\":\"Human\",\"class\":\"Fighter\",\"module\":\"rivermark\"}")
PID=$(jq_ "$A" "
import json,sys
print(json.load(sys.stdin).get('party_id') or 0)")
B=$(api "character/create" \
  "{\"name\":\"Other${SUFFIX}\",\"race\":\"Elf\",\"class\":\"Rogue\",\"module\":\"rivermark\",\"party_id\":${PID}}")
# The SECOND character, deliberately: the session is playing the first, and the
# whole point of the picker's store is that it acts on whoever is on screen.
MATE=$(jq_ "$B" "
import json,sys
print(json.load(sys.stdin)['character']['id'])")
check "a party of two, and the session is not playing the second" \
  "$([ "${MATE:-0}" -gt 0 ] 2>/dev/null && echo 1 || echo 0)" "id ${MATE}"
sql "UPDATE characters SET gold = 900 WHERE id = ${MATE};"

echo "== the shelf, and what it refuses to carry =="
STORE=$(api "inventory/store&character_id=${MATE}")
SHELF=$(jq_ "$STORE" "
import json,sys
d = json.load(sys.stdin)
items = d.get('items') or []
print(1 if d.get('ok') and items and d.get('gold') == 900 else 0, len(items))")
read -r OK_SHELF COUNT <<<"$SHELF"
check "the store opens for a character the session is not playing" "$OK_SHELF" "${COUNT} items"

# The rule, read off the rows themselves rather than trusted: every bonus any
# item grants has to be +1 or less, and nothing carrying a quest may be sold.
RULE=$(jq_ "$STORE" "
import json,sys
keys = ('attack_bonus','damage_bonus','ac_bonus','armor_bonus','save_bonus',
        'spell_attack_bonus','spell_dc_bonus')
bad = []
for it in json.load(sys.stdin)['items']:
    props = json.loads(it['properties']) if it.get('properties') else {}
    if props.get('quest_item'):
        bad.append(it['name'] + ' (quest item)')
    for k in keys:
        v = props.get(k)
        if isinstance(v, (int, float)) and v > 1:
            bad.append(f\"{it['name']} ({k} +{v})\")
    if it['item_type'] == 'treasure':
        bad.append(it['name'] + ' (treasure)')
print(1 if not bad else 0, '; '.join(bad[:4]) or '-')")
check "nothing on it is enchanted past +1, a quest item or treasure" \
  "${RULE%% *}" "${RULE#* }"

PLUSONE=$(jq_ "$STORE" "
import json,sys
n = 0
for it in json.load(sys.stdin)['items']:
    props = json.loads(it['properties']) if it.get('properties') else {}
    if any(props.get(k) == 1 for k in ('attack_bonus','ac_bonus','save_bonus')):
        n += 1
print(1 if n else 0, n)")
check "and the +1s are actually on it, so the cap is a cap and not a ban" \
  "${PLUSONE%% *}" "${PLUSONE#* } of them"

echo "== opening it twice does not double the shelf =="
AGAIN=$(jq_ "$(api "inventory/store&character_id=${MATE}")" "
import json,sys
print(len(json.load(sys.stdin)['items']))")
check "the second visit shows the same number of things" \
  "$([ "$AGAIN" = "$COUNT" ] && echo 1 || echo 0)" "${COUNT} then ${AGAIN}"
ROWS=$(sql "SELECT COUNT(*) FROM shop_inventory WHERE npc_key = '_general_store'")
check "and the shop table holds one row per item, not two" \
  "$([ "$ROWS" = "$COUNT" ] && echo 1 || echo 0)" "${ROWS} rows for ${COUNT} items"

echo "== buying, equipping and selling, for somebody the session is not =="
# The cheapest thing that can be worn, so the test does not depend on the purse.
# Cheap, so the test does not depend on the purse — but not SO cheap that it
# cannot be sold back: `sell()` refuses anything whose half-price rounds to
# nothing, which is the rule that keeps a 1 gp dart out of a sale list.
ITEM=$(jq_ "$STORE" "
import json,sys
wearable = [i for i in json.load(sys.stdin)['items']
            if i['item_type'] in ('weapon', 'armor') and i['price'] >= 2]
wearable.sort(key=lambda i: i['price'])
print(wearable[0]['id'], wearable[0]['price'], wearable[0]['name'])")
read -r ITEM_ID PRICE ITEM_NAME <<<"$ITEM"
BUY=$(api "inventory/buy" "{\"character_id\":${MATE},\"item_id\":${ITEM_ID},\"npc_key\":\"_general_store\"}")
check "buying puts it in their pack" "$(has "$BUY" 'Purchased')" "$(head -c 140 <<<"$BUY")"
PURSE=$(sql "SELECT gold FROM characters WHERE id = ${MATE}")
check "and takes the price out of their purse, not somebody else's" \
  "$([ "${PURSE:-0}" = "$((900 - PRICE))" ] && echo 1 || echo 0)" "left with ${PURSE}"

LISTED=$(api "inventory/list&character_id=${MATE}")
INVID=$(jq_ "$LISTED" "
import json,sys
rows = [r for r in json.load(sys.stdin)['inventory'] if r['item_id'] == ${ITEM_ID}]
print(rows[0]['id'] if rows else 0)")
SLOTS=$(jq_ "$LISTED" "
import json,sys
print(1 if all('equip_slot' in r for r in json.load(sys.stdin)['inventory']) else 0)")
check "the pack says which slot each thing goes in, so the button is honest" "$SLOTS"

EQ=$(api "inventory/equip" "{\"character_id\":${MATE},\"inventory_id\":${INVID},\"equip\":true}")
check "equipping works for them" "$(has "$EQ" '"ok":true')" "$(head -c 140 <<<"$EQ")"
WORN=$(sql "SELECT is_equipped FROM character_inventory WHERE id = ${INVID}")
check "and the row says it is worn" "$([ "$WORN" = "1" ] && echo 1 || echo 0)" "is_equipped ${WORN}"

OFF=$(api "inventory/equip" "{\"character_id\":${MATE},\"inventory_id\":${INVID},\"equip\":false}")
SOLD=$(api "inventory/sell" "{\"character_id\":${MATE},\"inventory_id\":${INVID}}")
check "and it can be sold once it is off" "$(has "$SOLD" '"ok":true')" "$(head -c 140 <<<"$SOLD")"

echo "== handing things across the party =="
# The bag's Give: one target for the panel, a button on every row. The route is
# the game's own and does the work — it refuses anybody outside the party, takes
# the thing off if it was being worn, and merges the stack into one the receiver
# already has. What is checked here is that the picker can drive it for a member
# the session is not playing, in both directions.
LEAD=$(jq_ "$A" "
import json,sys
print(json.load(sys.stdin)['character']['id'])")
GIFT=$(api "inventory/buy" "{\"character_id\":${MATE},\"item_id\":${ITEM_ID},\"npc_key\":\"_general_store\"}")
GIFTID=$(jq_ "$(api "inventory/list&character_id=${MATE}")" "
import json,sys
rows = [r for r in json.load(sys.stdin)['inventory'] if r['item_id'] == ${ITEM_ID}]
print(rows[0]['id'] if rows else 0)")
HANDED=$(api "inventory/give" \
  "{\"character_id\":${MATE},\"inventory_id\":${GIFTID},\"to_character_id\":${LEAD}}")
check "one party member can hand a thing to another" \
  "$(has "$HANDED" '"ok":true')" "$(head -c 140 <<<"$HANDED")"
LANDED=$(sql "SELECT COUNT(*) FROM character_inventory
               WHERE character_id = ${LEAD} AND item_id = ${ITEM_ID}")
check "and it is in the other one's pack afterwards" \
  "$([ "${LANDED:-0}" -ge 1 ] 2>/dev/null && echo 1 || echo 0)" "${LANDED} row(s)"

# Somebody in another party entirely. `give()` asks sameParty(), so this is the
# service's rule rather than the route's, and it is the one that stops a party
# being used as a way to move things onto a character who is not in it.
LONER=$(jq_ "$(api "character/create" \
  "{\"name\":\"Loner${SUFFIX}\",\"race\":\"Human\",\"class\":\"Cleric\",\"module\":\"rivermark\"}")" "
import json,sys
print(json.load(sys.stdin)['character']['id'])")
BACK=$(sql "SELECT id FROM character_inventory
             WHERE character_id = ${LEAD} AND item_id = ${ITEM_ID} LIMIT 1")
STRAY=$(api "inventory/give" \
  "{\"character_id\":${LEAD},\"inventory_id\":${BACK},\"to_character_id\":${LONER}}")
check "but not to somebody in another party, even your own" \
  "$(has "$STRAY" 'travelling with you')" "$(head -c 140 <<<"$STRAY")"

echo "== and none of it reaches somebody else's character =="
curl -s -c "$JAR2" -H 'Content-Type: application/json' -X POST \
  -d "{\"username\":\"${USER2}\",\"password\":\"${PASS}\"}" "$BASE/api/?r=auth/register" >/dev/null
for ROUTE in "inventory/list&character_id=${MATE}"; do
  DENIED=$(curl -s -b "$JAR2" "$BASE/api/?r=${ROUTE}")
  check "reading their pack is refused" "$(has "$DENIED" '"ok":false')" "$(head -c 120 <<<"$DENIED")"
done
DENIED=$(curl -s -b "$JAR2" -H 'Content-Type: application/json' -X POST \
  -d "{\"character_id\":${MATE},\"item_id\":${ITEM_ID},\"npc_key\":\"_general_store\"}" \
  "$BASE/api/?r=inventory/buy")
check "and buying on their account is refused" "$(has "$DENIED" '"ok":false')" "$(head -c 120 <<<"$DENIED")"
sql "DELETE FROM users WHERE username = '${USER2}';"

echo
echo "----------------------------------------------------"
echo "$OK passed, $BAD failed"
[ "$BAD" -eq 0 ]
