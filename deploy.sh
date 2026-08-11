#!/usr/bin/env bash
# Push local rpg changes to hermes (local -> PRODUCTION).
#
# This writes to the live site at https://rpg.five-star.com. It is dry-run
# unless you pass --apply, and it always shows the diff and asks first.
set -euo pipefail

REMOTE="root@hermes.five-star.com:/home/www/rpg/"
LOCAL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/src/"
SSH="ssh -o BatchMode=yes -i ${HOME}/.ssh/id_ed25519"

# database.php: local dev credentials must never overwrite prod's.
# sql/: schema files are applied deliberately, not swept along with a deploy.
#
# tools/: NONE of the 53 PHP files in there guards on php_sapi_name(), and the
# web root is public — so deploying them puts repair_save.php,
# end_open_delves.php, the backfill_* scripts and forty test harnesses on
# https://rpg.five-star.com/tools/*.php, ready to run against the live
# database. test_delve.php alone opens with `DELETE FROM regions`. Nothing the
# running site does needs that directory: every reference to `tools/` in the
# app is a comment, and the one generated file it produces (assets/css/
# dice.css) is deployed as an asset. If an operational tool is ever wanted on
# hermes, the fix is a CLI guard on that tool, not this exclusion removed.
#
# __pycache__: build litter, and .pyc files served from a public root.
EXCLUDES=(
    --exclude 'app/config/database.php'
    --exclude '.git'
    --exclude '.claude/settings.local.json'
    --exclude 'tools/'
    --exclude '__pycache__/'
)

# NOTE: no --delete. A stray local deletion should not remove live files.
echo "DEPLOY  ${LOCAL}  ->  hermes:/home/www/rpg  (LIVE SITE)"
echo
echo "--- changes that would be made on production ---"
rsync -a --itemize-changes --dry-run "${EXCLUDES[@]}" -e "$SSH" "$LOCAL" "$REMOTE"
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

rsync -a --info=stats2 "${EXCLUDES[@]}" -e "$SSH" "$LOCAL" "$REMOTE"

# Files land as root:root over SSH; the site runs as www-data.
echo "Fixing ownership on hermes..."
ssh -o BatchMode=yes -i "${HOME}/.ssh/id_ed25519" root@hermes.five-star.com \
    'chown -R www-data:www-data /home/www/rpg && echo "  ownership set to www-data"'

echo "Deploy complete."
