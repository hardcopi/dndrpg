#!/usr/bin/env bash
# Can a client sign in with a Bearer token and play without a cookie?
#
# Driven over real HTTP with no cookie jar on the token path, because that
# is the layer Unity will use. Cookie login is still asserted so the
# workshop is not the price of the door.
#
# Creates a throwaway account and a character, then deletes them. Safe
# against a database somebody is playing on.
#
#   bash src/tools/test_token_auth.sh
#
# Exits non-zero if any check fails.

set -uo pipefail

BASE=${BASE:-http://localhost:8081}
COMPOSE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SUFFIX=$RANDOM$RANDOM
PASS='a-good-long-password'
USER="tok_${SUFFIX}"
PASS_FAILED=0
PASS_OK=0

cleanup() {
  docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T db \
    mysql -uweb -pdevpassword rpg_5e -e "
      DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
      DELETE FROM parties    WHERE user_id IN (SELECT id FROM users WHERE username = '${USER}');
      DELETE FROM users WHERE username = '${USER}';" 2>/dev/null
}
trap cleanup EXIT

# POST or GET with a Bearer token and deliberately no cookie jar.
token_api() { # token_api <token> <route> [json]
  local token=$1 route=$2
  if [ $# -ge 3 ]; then
    curl -s -X POST \
      -H 'Content-Type: application/json' \
      -H "Authorization: Bearer ${token}" \
      -d "$3" "$BASE/api/index.php?r=$route"
  else
    curl -s -H "Authorization: Bearer ${token}" \
      "$BASE/api/index.php?r=$route"
  fi
}

cookie_api() { # cookie_api <jar> <route> [json]
  local jar=$1 route=$2
  if [ $# -ge 3 ]; then
    curl -s -b "$jar" -c "$jar" -X POST -H 'Content-Type: application/json' \
      -d "$3" "$BASE/api/index.php?r=$route"
  else
    curl -s -b "$jar" -c "$jar" "$BASE/api/index.php?r=$route"
  fi
}

ok() {
  PASS_OK=$((PASS_OK+1))
}
fail() {
  PASS_FAILED=$((PASS_FAILED+1))
  echo "FAIL  $1"
  if [ -n "${2:-}" ]; then
    echo "      got: $(echo "$2" | head -c 200)"
  fi
}

allowed() {
  if echo "$2" | grep -q '"ok":true'; then ok; else fail "$1" "$2"; fi
}
refused() {
  if echo "$2" | grep -q '"ok":false'; then ok; else fail "$1" "$2"; fi
}

jget() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval('d'+sys.argv[1]) if d else '')" "$1" 2>/dev/null; }

echo "== register issues a token =="
REG=$(curl -s -X POST -H 'Content-Type: application/json' \
  -d "{\"username\":\"${USER}\",\"password\":\"${PASS}\",\"client\":\"unity\"}" \
  "$BASE/api/index.php?r=auth/register")
allowed "register succeeds" "$REG"
TOKEN=$(echo "$REG" | jget "['token']")
if [ -n "$TOKEN" ] && echo "$TOKEN" | grep -q '^rpg_'; then ok; else
  fail "register returned a token" "$REG"
fi

echo "== the token works with no cookie =="
STATUS=$(token_api "$TOKEN" auth/status)
allowed "auth/status with Bearer" "$STATUS"
if echo "$STATUS" | grep -q '"via":"token"'; then ok; else
  fail "status says via token" "$STATUS"
fi
allowed "session/list with Bearer" "$(token_api "$TOKEN" session/list)"

echo "== a missing or garbage token is refused =="
refused "no credentials" "$(curl -s "$BASE/api/index.php?r=session/list")"
refused "garbage Bearer" "$(token_api "rpg_not_a_real_token_value" session/list)"

echo "== cookie login still works =="
JAR=$(mktemp)
COOKIE_LOGIN=$(cookie_api "$JAR" auth/login "{\"username\":\"${USER}\",\"password\":\"${PASS}\"}")
allowed "cookie login succeeds" "$COOKIE_LOGIN"
allowed "cookie session/list succeeds" "$(cookie_api "$JAR" session/list)"
rm -f "$JAR"

echo "== the token can select a character and keep it =="
ROLLED=$(token_api "$TOKEN" meta/roll_abilities '{}')
ABILITIES=$(echo "$ROLLED" | python3 -c "
import json,sys
sets = json.load(sys.stdin)['sets']
abilities = ['strength','dexterity','constitution','intelligence','wisdom','charisma']
print(json.dumps(dict(zip(abilities, [s['total'] for s in sets]))))
")
CREATED=$(token_api "$TOKEN" character/create "$(python3 -c "
import json,sys
print(json.dumps({
    'method': 'random', 'name': sys.argv[1], 'race': 'Human', 'class': 'Fighter',
    'abilities': json.loads(sys.argv[2]),
}))
" "Tok${SUFFIX}" "$ABILITIES")")
allowed "create a character over Bearer" "$CREATED"
CID=$(echo "$CREATED" | jget "['character']['id']")
if [ -z "$CID" ]; then
  echo "FAIL  could not create a character; the rest cannot run"
  echo "      got: $(echo "$CREATED" | head -c 200)"
  exit 1
fi

# A fresh request — no cookie — must still know who is being played.
STATE=$(token_api "$TOKEN" location/state)
allowed "location/state after create, no cookie" "$STATE"

echo "== logout revokes that token =="
allowed "logout" "$(token_api "$TOKEN" auth/logout '{}')"
refused "revoked token cannot list" "$(token_api "$TOKEN" session/list)"

echo "== CORS preflight for a local origin =="
PRE=$(curl -s -D - -o /dev/null -X OPTIONS \
  -H 'Origin: http://localhost:5500' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: Authorization, Content-Type' \
  "$BASE/api/index.php?r=session/list")
CODE=$(echo "$PRE" | head -n 1 | tr -d '\r')
if echo "$CODE" | grep -q '204'; then ok; else fail "OPTIONS is 204" "$CODE"; fi
if echo "$PRE" | grep -qi 'Access-Control-Allow-Origin: http://localhost:5500'; then ok; else
  fail "preflight reflects the local origin" "$PRE"
fi
if echo "$PRE" | grep -qi 'Access-Control-Allow-Headers:.*Authorization'; then ok; else
  fail "preflight allows Authorization" "$PRE"
fi

echo "== a foreign origin is not reflected =="
FOREIGN=$(curl -s -D - -o /dev/null -X OPTIONS \
  -H 'Origin: https://evil.example' \
  "$BASE/api/index.php?r=session/list")
if echo "$FOREIGN" | grep -qi 'Access-Control-Allow-Origin: https://evil.example'; then
  fail "foreign origin must not be reflected" "$FOREIGN"
else
  ok
fi

echo "== missing assets 404 rather than serving the homepage =="
MISS=$(curl -s -o /tmp/rpg_missing_asset -w '%{http_code} %{content_type}' \
  "$BASE/assets/images/definitely_not_a_real_file.png")
if echo "$MISS" | grep -q '^404'; then ok; else fail "missing png is 404" "$MISS"; fi
if grep -qi '<html' /tmp/rpg_missing_asset; then
  fail "missing png body is not the homepage" "$(head -c 80 /tmp/rpg_missing_asset)"
else
  ok
fi
rm -f /tmp/rpg_missing_asset

HIT=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/assets/css/style.css")
if echo "$HIT" | grep -q 'text/css'; then ok; else fail "existing css is still served" "$HIT"; fi

echo
echo "$PASS_OK passed, $PASS_FAILED failed"
exit $((PASS_FAILED > 0 ? 1 : 0))
