#!/usr/bin/env bash
# Stop a deploy whose code needs a migration Hermes has not had.
#
# CLAUDE.md's rule: run `migrate.sh --status` against Hermes before a deploy,
# and if anything is pending, back up and `--apply` BY HAND first, because new
# code against an old schema 500s every game screen. This automates the
# check and deliberately not the apply: a migration on the live database is a
# person's decision. Read-only (--status writes nothing, not even its table).
set -euo pipefail
cd "$(dirname "$0")/../.."
OUT=$(bash src/sql/migrate.sh --mysql "$PWD/.github/scripts/hermes-mysql" --database rpg_5e --status 2>&1)
printf '%s\n' "$OUT"
SUMMARY=$(printf '%s\n' "$OUT" | grep -E '^[0-9]+ recorded, [0-9]+ pending\.$' | tail -n 1)
[ -n "$SUMMARY" ] || { echo "could not read migrate.sh's summary line" >&2; exit 1; }
PENDING=$(printf '%s' "$SUMMARY" | sed -E 's/^[0-9]+ recorded, ([0-9]+) pending\.$/\1/')
if [ "$PENDING" -gt 0 ]; then
    {
        echo "::error title=Migrations pending on Hermes::${PENDING} migration(s) not applied on Hermes. Not deploying."
        echo "Apply them by hand first (CLAUDE.md, Migrations): back up, then"
        echo "  src/sql/migrate.sh --mysql <hermes-mysql> --database rpg_5e --apply"
        echo "then re-run this workflow."
    } >&2
    exit 1
fi
echo "migrations: none pending on Hermes"
