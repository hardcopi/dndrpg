#!/usr/bin/env bash
# Load authored content (src/sql/content.sql) into the live rpg_5e, safely.
#
#   .github/scripts/hermes-content.sh check   # read-only: is it needed, what is at risk
#   .github/scripts/hermes-content.sh apply   # load it if it changed since the last load
#
# deploy.sh ships code only; dialogue, quests and locations live in the
# database and reach it through src/tools/apply_content_safely.py, which
# snapshots player progress by content key, loads content.sql and restores
# progress in ONE transaction (see that file's docstring). This is the same
# tool CLAUDE.md runs from Thor, driven through .github/scripts/hermes-mysql.
#
# "Changed" is a sha256 of content.sql against the one recorded on Hermes at
# /root/.rpg-content.sha256 after the last successful load (outside the web
# root). No record yet = load once and record.
#
# --no-mysqldump because the deploy job has already taken a full encrypted
# dump before this runs; the progress snapshot JSON the tool writes is
# encrypted by the workflow before it is kept.
set -euo pipefail
cd "$(dirname "$0")/../.."
MODE="${1:?check|apply}"
SSH=(ssh -o BatchMode=yes -o ServerAliveInterval=15 -i "${HOME}/.ssh/id_ed25519" root@hermes.five-star.com)
WANT=$(sha256sum src/sql/content.sql | cut -d' ' -f1)
HAVE=$("${SSH[@]}" 'cat /root/.rpg-content.sha256 2>/dev/null || true' | tr -d '[:space:]')
echo "content.sql: repo ${WANT:0:12}, last loaded on hermes ${HAVE:-none recorded}"

if [ "$WANT" = "$HAVE" ]; then
    echo "content: unchanged since the last load; nothing to do"
    exit 0
fi

MYSQL="$PWD/.github/scripts/hermes-mysql"
python3 .github/scripts/content-drift.py "$MYSQL"
if [ "$MODE" = check ]; then
    python3 src/tools/apply_content_safely.py --check --database rpg_5e --mysql "$MYSQL"
    echo "content: WOULD load (dry run)"
    exit 0
fi

mkdir -p backups/content
python3 src/tools/apply_content_safely.py --apply --database rpg_5e \
    --mysql "$MYSQL" --no-mysqldump --backup-dir backups/content
printf '%s\n' "$WANT" | "${SSH[@]}" 'umask 077; cat > /root/.rpg-content.sha256'
echo "content: loaded and recorded ${WANT:0:12}"
