#!/bin/bash
# Ship the character look library to hermes: assets/looks/<version>/ under the
# web root, built by src/tools/bake_looks.py and kept out of git and out of
# deploy.sh (see .gitignore and rsync-excludes for why).
#
#   ./deploy_looks.sh [LIBRARY_DIR]            # dry run: what would go, and the counts
#   echo upload | ./deploy_looks.sh --apply [LIBRARY_DIR]
#   ./deploy_looks.sh --verify [LIBRARY_DIR]   # compare file count and bytes, local vs hermes
#
# LIBRARY_DIR defaults to ~/rivermark/creator-src/looks/v1; its basename is the
# library version, so v1 lands at /home/www/rpg/assets/looks/v1/.
#
# Why its own script rather than a line in deploy.sh:
#   - ~51k small files. deploy.sh scans the whole tree every run; folding this in
#     would make every code deploy pay for a scan of pictures that change only
#     when the creator snapshot is re-baked.
#   - hermes resets long transfers (it did, every minute or so, on the first
#     v1 upload). This goes one character at a time (twenty chunks of ~2.6k
#     files), pausing between them and retrying a chunk that fails up to
#     TRIES times; BWLIMIT=<KiB/s> slows it down if the resets keep coming.
#     rsync only sends what is missing or different, so a run that died
#     halfway is resumed by running it again — nothing is redone.
#
# Never deletes anything on hermes (no --delete): a library version is
# immutable once players wear it, and a new version gets a new directory.
set -euo pipefail

APPLY=0; VERIFY=0
case "${1:-}" in
    --apply)  APPLY=1;  shift ;;
    --verify) VERIFY=1; shift ;;
esac
LIB="${1:-$HOME/rivermark/creator-src/looks/v1}"
LIB="${LIB%/}"
VER="$(basename "$LIB")"
HOST="root@hermes.five-star.com"
DEST="/home/www/rpg/assets/looks/${VER}"
SSH="ssh -o BatchMode=yes -o ServerAliveInterval=15 -i ${HOME}/.ssh/id_ed25519"
PAUSE="${PAUSE:-5}"
TRIES="${TRIES:-40}"
BWLIMIT="${BWLIMIT:-}"          # KiB/s; hermes resets long full-speed transfers

[ -f "$LIB/manifest.json" ] || { echo "no manifest.json in $LIB — is this a baked library?" >&2; exit 1; }

local_count() { find "$LIB" -type f -name '*.webp' | wc -l; }
local_bytes() { find "$LIB" -type f -name '*.webp' -printf '%s\n' | awk '{s+=$1} END {print s+0}'; }
remote_stats() {
    $SSH "$HOST" "if [ -d '$DEST' ]; then find '$DEST' -type f -name '*.webp' -printf '%s\n' | awk '{n++; s+=\$1} END {print n+0, s+0}'; else echo 0 0; fi"
}

verify() {
    local lc lb rc rb
    lc="$(local_count)"; lb="$(local_bytes)"
    read -r rc rb < <(remote_stats)
    echo "local : ${lc} files, ${lb} bytes  ($LIB)"
    echo "hermes: ${rc} files, ${rb} bytes  ($DEST)"
    if [ "$lc" = "$rc" ] && [ "$lb" = "$rb" ] && $SSH "$HOST" "test -f '$DEST/manifest.json'"; then
        echo "OK: counts and bytes match, manifest present"
        return 0
    fi
    echo "MISMATCH" >&2
    return 1
}

if [ "$VERIFY" = 1 ]; then verify; exit $?; fi

CHUNKS=$(cd "$LIB" && find . -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
echo "LOOKS  ${LIB}  ->  hermes:${DEST}  (LIVE SITE)"
echo "chunks: $(echo "$CHUNKS" | wc -w)   files: $(local_count)   bytes: $(local_bytes)"

if [ "$APPLY" = 0 ]; then
    read -r rc rb < <(remote_stats)
    echo "hermes has ${rc} files, ${rb} bytes there now."
    echo "Dry run only. To upload:  echo upload | $0 --apply $LIB"
    exit 0
fi

read -r -p "Type 'upload' to send the look library to the live site: " ok
[ "$ok" = "upload" ] || { echo "Not confirmed; nothing sent."; exit 1; }

$SSH "$HOST" "mkdir -p '$DEST'"
n=0
for c in $CHUNKS; do
    n=$((n+1)); try=1
    until rsync -a --partial --timeout=120 ${BWLIMIT:+--bwlimit=$BWLIMIT} -e "$SSH" "$LIB/$c" "$HOST:$DEST/"; do
        [ "$try" -ge "$TRIES" ] && { echo "chunk $c failed ${TRIES} times; run again to resume" >&2; exit 1; }
        echo "  chunk $c failed (try $try); pausing $((PAUSE*(try<6?try:6)*2))s and retrying"
        sleep $((PAUSE*(try<6?try:6)*2)); try=$((try+1))
    done
    echo "  [$n] $c"
    sleep "$PAUSE"
done
# The manifest and stats last: a manifest on hermes means every chunk above it landed.
rsync -a --timeout=120 -e "$SSH" "$LIB/manifest.json" "$LIB/bake_stats.json" "$HOST:$DEST/"

# Files land as root:root over SSH; the site runs as www-data (as deploy.sh does).
$SSH "$HOST" "chown -R www-data:www-data /home/www/rpg/assets/looks"
verify
