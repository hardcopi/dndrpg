#!/usr/bin/env bash
# Security headers and private paths, over real HTTP against BASE (the docker
# stack by default). Read-only: GETs only, no account made.
#   BASE=http://localhost:8081 bash tools/test_security_headers.sh
set -uo pipefail
BASE=${BASE:-http://localhost:8081}
pass=0; fail=0
ok()  { printf '  ok   %s\n' "$1"; pass=$((pass+1)); }
bad() { printf '  FAIL %s\n' "$1"; fail=$((fail+1)); }
hdr() { curl -s -D - -o /dev/null "$BASE$1" | tr -d '\r'; }

for path in /login.php "/api/?r=auth/status" /races.php; do
    h=$(hdr "$path")
    grep -qi '^x-content-type-options: nosniff' <<<"$h" && ok "$path nosniff" || bad "$path nosniff"
    grep -qi "^content-security-policy: frame-ancestors 'self'" <<<"$h" && ok "$path frame-ancestors" || bad "$path frame-ancestors"
    grep -qi '^referrer-policy: strict-origin-when-cross-origin' <<<"$h" && ok "$path referrer-policy" || bad "$path referrer-policy"
    grep -qi '^permissions-policy: camera=()' <<<"$h" && ok "$path permissions-policy" || bad "$path permissions-policy"
done
# HSTS only over HTTPS; plain HTTP here must not claim it.
if [[ "$BASE" == http://* ]]; then
    hdr /login.php | grep -qi '^strict-transport-security' && bad "no HSTS over plain http" || ok "no HSTS over plain http"
fi
for path in /app/config/database.php /app/bootstrap.php /app/lib/Auth.php /app/ /sql/schema.sql /tools/verify.sh; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")
    [ "$code" = 404 ] && ok "$path -> 404" || bad "$path -> $code (want 404)"
done
for path in /login.php /api/index.php; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")
    [ "$code" != 404 ] && ok "$path still served ($code)" || bad "$path refused"
done
printf '%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
