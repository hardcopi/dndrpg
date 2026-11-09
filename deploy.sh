#!/usr/bin/env bash
# Push local rpg changes to hermes (local -> PRODUCTION).
#
# This writes to the live site at https://rpg.five-star.com. It is dry-run
# unless you pass --apply, and it always shows the diff and asks first.
set -euo pipefail

REMOTE="root@hermes.five-star.com:/home/www/rpg/"
LOCAL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/src/"
SSH="ssh -o BatchMode=yes -i ${HOME}/.ssh/id_ed25519"

# What is NOT sent lives in rsync-excludes, beside this script, with the reason
# for every line: credentials, sql/ (content.sql is every quest spoiler in the
# game), tools/, docs/, every .md/.py/.sh, dotfiles. The web root is public
# and nginx reads no .htaccess, so anything sent is downloadable. pull.sh reads
# the same file, because a path this script stops sending is a path a pull
# with --delete would otherwise remove from the local copy.
EXCLUDES=(--exclude-from "$(dirname "${LOCAL%/}")/rsync-excludes")

# How a file is judged "changed". Default (-a): size + mtime, exactly as this
# script always worked from Thor. DEPLOY_COMPARE=checksum is what
# .github/workflows/deploy.yml sets: a fresh CI checkout gives every file a new
# mtime and the runner's owner/permissions, so -a would list (and touch) every
# file on every run, and asset()'s ?v=<filemtime> would bust every player's
# cache on every deploy. Comparing by content, and leaving times/perms/owner to
# the receiving side, means only files whose bytes changed are sent and listed,
# and only those get a new mtime (so only their ?v= changes). Ownership is set
# by the chown below either way.
RSYNC_MODE=(-a)
if [[ "${DEPLOY_COMPARE:-}" == "checksum" ]]; then
    RSYNC_MODE=(-rlD --checksum)
fi

# NOTE: no --delete. A stray local deletion should not remove live files.
echo "DEPLOY  ${LOCAL}  ->  hermes:/home/www/rpg  (LIVE SITE)"
echo
echo "--- changes that would be made on production ---"
rsync "${RSYNC_MODE[@]}" --itemize-changes --dry-run "${EXCLUDES[@]}" -e "$SSH" "$LOCAL" "$REMOTE"
echo

if [[ "${1:-}" != "--apply" ]]; then
    echo "Dry run only. Re-run with --apply to push these changes."
    exit 0
fi

read -r -p "Push the above to PRODUCTION? Type 'deploy' to confirm: " reply
if [[ "$reply" != "deploy" ]]; then
    echo "Aborted."
    exit 1
fi

rsync "${RSYNC_MODE[@]}" --info=stats2 "${EXCLUDES[@]}" -e "$SSH" "$LOCAL" "$REMOTE"

# Files land as root:root over SSH; the site runs as www-data.
echo "Fixing ownership on hermes..."
ssh -o BatchMode=yes -i "${HOME}/.ssh/id_ed25519" root@hermes.five-star.com \
    'chown -R www-data:www-data /home/www/rpg && echo "  ownership set to www-data"'

echo "Deploy complete."
