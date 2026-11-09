#!/usr/bin/env bash
# Dump the live rpg_5e over SSH and leave ONLY an encrypted copy on disk.
#
#   .github/scripts/hermes-backup.sh <label> [out_dir]
#   -> <out_dir>/rpg_5e-<UTC stamp>-<label>.sql.gz.age   (path printed last)
#
# The same mysqldump CLAUDE.md uses before a migration, run on Hermes as root
# (socket auth: no database password in GitHub). Compressed on Hermes, checked
# here (non-trivial size, gzip intact, mysqldump's own "Dump completed"
# trailer), then encrypted with age to BACKUP_AGE_RECIPIENT before it can go
# anywhere.
#
# Why encrypted: hardcopi/dndrpg is a PUBLIC repository, and artifacts of a
# public repository can be downloaded by anyone signed in to GitHub. A dump
# holds every account's login name and password hash. The recipient is a
# PUBLIC key; the matching private key lives only with Rich (see
# docs/ops/GITHUB_ACTIONS.md, "Backups"). With no recipient set, this refuses
# to produce anything rather than fall back to plaintext.
set -euo pipefail
LABEL="${1:?usage: hermes-backup.sh <label> [out_dir]}"
OUT_DIR="${2:-backups}"
: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT is not set: refusing to make an unencrypted backup}"
case "$BACKUP_AGE_RECIPIENT" in
    age1*) ;;
    *) echo "BACKUP_AGE_RECIPIENT does not look like an age public key (age1...)" >&2; exit 1 ;;
esac
command -v age >/dev/null || { echo "age is not installed" >&2; exit 1; }

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$OUT_DIR/rpg_5e-${STAMP}-${LABEL}.sql.gz"
umask 077
trap 'rm -f "$BASE"' EXIT

ssh -o BatchMode=yes -o ServerAliveInterval=15 -i "${HOME}/.ssh/id_ed25519" \
    root@hermes.five-star.com \
    "set -o pipefail; mysqldump --single-transaction --quick --routines --triggers \
       --default-character-set=utf8mb4 rpg_5e | gzip -c" > "$BASE"

BYTES=$(stat -c %s "$BASE")
[ "$BYTES" -gt 10240 ] || { echo "dump is only ${BYTES} bytes; treating as failed" >&2; exit 1; }
gzip -t "$BASE"
gzip -dc "$BASE" | tail -n 1 | grep -q '^-- Dump completed' \
    || { echo "dump has no 'Dump completed' trailer; it was cut short" >&2; exit 1; }
TABLES=$(gzip -dc "$BASE" | grep -c '^CREATE TABLE' || true)
echo "dump ok: ${BYTES} bytes compressed, ${TABLES} tables"

age -r "$BACKUP_AGE_RECIPIENT" -o "${BASE}.age" "$BASE"
rm -f "$BASE"
echo "${BASE}.age"
