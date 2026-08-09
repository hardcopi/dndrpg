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
EXCLUDES=(
    --exclude 'app/config/database.php'
    --exclude '.git'
    --exclude '.claude/settings.local.json'
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
