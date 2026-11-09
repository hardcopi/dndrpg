#!/usr/bin/env bash
# Is the live site up and serving what deploy.sh just sent? GETs only.
#
#   .github/scripts/smoke.sh [https://rpg.five-star.com]
set -uo pipefail
SITE="${1:-https://rpg.five-star.com}"
FAIL=0
body=$(mktemp); trap 'rm -f "$body"' EXIT

# Prints "<status> <content-type>". Status alone proves nothing for a static
# file: the vhost's try_files falls back to index.php, so a MISSING asset is a
# 200 carrying the homepage (CLAUDE.md, "Things that will bite you").
get() { curl -sS -o "$body" -w '%{http_code} %{content_type}' --max-time 20 --retry 3 --retry-delay 5 --retry-all-errors "$SITE$1" 2>/dev/null || echo "000 none"; }
ok()   { printf 'ok    %-34s %s\n' "$1" "$2"; }
bad()  { printf 'FAIL  %-34s %s\n' "$1" "$2"; echo "::error title=Smoke check::$1 $2"; FAIL=1; }
warn() { printf 'warn  %-34s %s\n' "$1" "$2"; echo "::warning title=Smoke check::$1 $2"; }

# Must answer, with content of the right type.
check() { # path, content-type pattern
    read -r code ctype <<< "$(get "$1")"; size=$(stat -c %s "$body")
    if [ "$code" = 200 ] && [ "$size" -gt 0 ] && [[ "$ctype" == $2 ]]; then
        ok "$1" "200, ${ctype%%;*}, ${size} bytes"
    else
        bad "$1" "HTTP $code, ${ctype:-no type}, ${size} bytes"
    fi
}
check /                      'text/html*'
check /assets/css/style.css  'text/css*'
check /assets/js/api.js      '*javascript*'


# PHP + DB end to end: the API answers JSON.
read -r code _ <<< "$(get '/api/?r=session/status')"
if [ "$code" = 200 ] && python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$body" 2>/dev/null; then
    ok "/api/?r=session/status" "200, JSON"
else
    bad "/api/?r=session/status" "HTTP $code, not JSON"
fi

# Must NOT be served. deploy.sh never sends sql/, so a 200 here means something
# else put it there; that is a spoiler leak and fails the run.
# (A 200 with text/html is the index.php fallback, i.e. not the file.)
read -r code ctype <<< "$(get /sql/content.sql)"
if [ "$code" = 200 ] && [[ "$ctype" != text/html* ]]; then
    bad /sql/content.sql "served (HTTP 200, $ctype)"
else
    ok /sql/content.sql "not served (HTTP $code)"
fi

# Credentials file. PHP runs it and it prints nothing, so today it is a 200
# with an empty body; docs/ops/HERMES_SECURITY.md makes it a 404. Any BODY is a
# leak and fails; an empty 200 is a reminder that the snippet is not applied.
read -r code _ <<< "$(get /app/config/database.php)"; size=$(stat -c %s "$body")
if [ "$code" = 200 ] && [ "$size" -gt 0 ]; then
    bad /app/config/database.php "served with a body (${size} bytes)"
elif [ "$code" = 200 ]; then
    warn /app/config/database.php "200 (empty) - HERMES_SECURITY.md /app/ block not applied yet"
else
    ok /app/config/database.php "blocked (HTTP $code)"
fi

# Header checks: reported, never failing. The first ships with the code
# (bootstrap.php, #54); the second needs docs/ops/HERMES_NGINX.md applied by hand.
hdrs=$(curl -sS -o /dev/null -D - --max-time 20 "$SITE/login.php" 2>/dev/null | tr -d '\r')
if printf '%s' "$hdrs" | grep -qi '^content-security-policy:'; then
    ok "security headers (PHP)" "Content-Security-Policy present"
else
    warn "security headers (PHP)" "no Content-Security-Policy on /login.php (code from #54 not live yet?)"
fi
V=$(curl -s --max-time 20 "$SITE/login.php" | grep -o 'assets/css/style.css?v=[0-9]*' | head -n 1)
if [ -n "$V" ]; then
    h=$(curl -sS -o /dev/null -D - -H 'Accept-Encoding: gzip' --max-time 20 "$SITE/$V" 2>/dev/null | tr -d '\r')
    if printf '%s' "$h" | grep -qi '^content-encoding: gzip' && printf '%s' "$h" | grep -qi '^cache-control:.*immutable'; then
        ok "gzip + immutable (nginx)" "$V"
    else
        warn "gzip + immutable (nginx)" "not yet - see docs/ops/HERMES_NGINX.md"
    fi
fi

exit $FAIL
