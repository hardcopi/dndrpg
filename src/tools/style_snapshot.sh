#!/usr/bin/env bash
#
# Capture tools/style_snapshot.html to a plain text file, ready for diff(1).
#
# The point of this pair is that the repo has no UI test at all — verify.sh
# runs 40 harnesses and not one asserts on a class, a colour or a size. So the
# only way to port 6,800 lines of CSS onto tokens and know what you changed is
# to write down what the browser computed before and after, and diff it.
#
#   tools/style_snapshot.sh before.txt      # on the old stylesheet
#   ...make the change...
#   tools/style_snapshot.sh after.txt
#   diff -u before.txt after.txt | less
#
# Every differing line is a value that moved. An empty diff is a port that
# changed nothing, which for a mechanical stage is exactly the result wanted.
#
set -euo pipefail

OUT="${1:-/tmp/style_snapshot.txt}"
BASE="${RPG_BASE:-http://localhost:8081}"

CHROME="$(command -v google-chrome || command -v google-chrome-stable \
       || command -v chromium || command -v chromium-browser || true)"
if [ -z "$CHROME" ]; then
    echo "style_snapshot: no chrome/chromium on PATH" >&2
    exit 1
fi

if ! curl -sf -o /dev/null "$BASE/tools/style_snapshot.html"; then
    echo "style_snapshot: $BASE is not serving the benches — is the stack up?" >&2
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --virtual-time-budget has to outlast fourteen sequential frame loads; the
# page loads them one at a time on purpose (loading all of them at once raced,
# and several benches reported zero elements).
"$CHROME" --headless --no-sandbox --disable-gpu \
    --force-device-scale-factor=1 --window-size=1440,900 \
    --hide-scrollbars --virtual-time-budget=60000 --dump-dom \
    "$BASE/tools/style_snapshot.html" > "$TMP/dump.html" 2>/dev/null

python3 - "$TMP/dump.html" "$OUT" <<'PY'
import html, re, sys
dump, out = sys.argv[1], sys.argv[2]
s = open(dump, encoding='utf-8', errors='replace').read()
# Comments first. The page documents how it is captured, which means its own
# header contains the literal name of the element being searched for — and a
# non-greedy match found THAT and returned the tail of the comment as data.
s = re.sub(r'<!--.*?-->', '', s, flags=re.S)
m = re.search(r'<pre id="out">(.*?)</pre>', s, re.S)
if not m:
    sys.exit('style_snapshot: no readout in the dump — the page did not finish')
text = html.unescape(re.sub(r'<[^>]*>', '', m.group(1)))
if '# elements: 0' in text or len(text.strip().splitlines()) < 10:
    sys.exit('style_snapshot: readout is empty — benches did not render')
open(out, 'w', encoding='utf-8').write(text.rstrip() + '\n')
print(text.splitlines()[1])           # the per-bench element counts
print(text.splitlines()[2])           # the total
PY

echo "written to $OUT"
