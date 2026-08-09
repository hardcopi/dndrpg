#!/usr/bin/env bash
# Refresh the local copy of rpg from hermes (production -> local).
#
# Safe by default: shows what would change and asks before touching anything.
set -euo pipefail

REMOTE="root@hermes.five-star.com:/home/www/rpg/"
LOCAL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/src/"
SSH="ssh -o BatchMode=yes -i ${HOME}/.ssh/id_ed25519"

# database.php holds prod credentials - never bring it down.
# .claude/settings.local.json is per-machine.
EXCLUDES=(
    --exclude 'app/config/database.php'
    --exclude '.git'
    --exclude '.claude/settings.local.json'
)

echo "PULL  hermes:/home/www/rpg  ->  ${LOCAL}"
echo
echo "--- changes that would be made ---"
rsync -a --delete --itemize-changes --dry-run "${EXCLUDES[@]}" -e "$SSH" "$REMOTE" "$LOCAL"
echo

read -r -p "Apply these changes locally? [y/N] " reply
if [[ ! "$reply" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

rsync -a --delete --info=stats2 "${EXCLUDES[@]}" -e "$SSH" "$REMOTE" "$LOCAL"
echo "Pull complete."
