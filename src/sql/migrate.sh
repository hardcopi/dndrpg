#!/bin/bash
# Apply sql/migrations/*.sql to ONE database, and remember which ones it has had.
#
#   migrate.sh --database rpg_5e [--status | --dry-run | --apply
#                                 | --baseline FILE... | --baseline-all]
#              [--mysql "<client command>"] [--dir <migrations dir>]
#
# Bash and the mysql client only, on purpose: the same script runs inside the
# mysql:8.0 initdb container (docker/initdb/00-load.sh), which has no python,
# and against production through ~/rivermark/bin/hermes-mysql.
#
# The migrations were always written to be safe to run twice, so for a long
# time nothing recorded which ones a database had had and they were applied by
# hand. That held until a migration carried CONTENT rather than a column: a
# fresh build never got 2026-10-29-freeplay-road.sql, so the Proving House had
# no way out, and nothing anywhere said so. `schema_migrations` is that record.
#
# Rules this script keeps:
#
#   - Files run in filename order (LC_ALL=C), each followed in the SAME client
#     session by its INSERT into schema_migrations. The client stops at the
#     first error and exits non-zero, so a file that fails is never recorded,
#     and the run stops there rather than applying later files on top of it.
#   - A recorded file is never run again, even if it has changed since. A
#     changed checksum is reported by --status and --apply; deciding what to do
#     about an edited migration is a person's job, not this script's.
#   - The database is named on the command line and nowhere else. A file
#     containing `USE` is refused outright: content.sql's literal `USE rpg_5e`
#     is how a live save was once destroyed while testing against a scratch
#     database, and a migration must not be able to do the same.
#   - SQL goes on stdin and the database is passed as the client's last
#     argument, because hermes-mysql is `ssh root@hermes mysql "$@"`, and ssh
#     re-splits arguments: a query passed with -e would arrive in pieces.
#   - --dry-run (the default) and --status write nothing, not even the table.
set -euo pipefail
export LC_ALL=C

usage() {
    sed -n '2,7p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-2}"
}
die() { echo "migrate: $*" >&2; exit 2; }

MYSQL_CMD=mysql
DB=
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/migrations"
MODE=
NAMED=()

set_mode() {
    [ -z "$MODE" ] || die "choose one of --status, --dry-run, --apply, --baseline, --baseline-all"
    MODE=$1
}

while [ $# -gt 0 ]; do
    case "$1" in
        --mysql)        [ $# -ge 2 ] || die "--mysql needs a command"; MYSQL_CMD=$2; shift 2 ;;
        --database)     [ $# -ge 2 ] || die "--database needs a name"; DB=$2; shift 2 ;;
        --dir)          [ $# -ge 2 ] || die "--dir needs a path"; DIR=$2; shift 2 ;;
        --status)       set_mode status; shift ;;
        --dry-run)      set_mode dry-run; shift ;;
        --apply)        set_mode apply; shift ;;
        --baseline-all) set_mode baseline-all; shift ;;
        --baseline)
            set_mode baseline; shift
            while [ $# -gt 0 ] && [[ $1 != --* ]]; do NAMED+=("$(basename "$1")"); shift; done
            [ ${#NAMED[@]} -gt 0 ] || die "--baseline needs at least one file (or use --baseline-all)"
            ;;
        -h|--help)      usage 0 ;;
        *)              die "unknown argument: $1 (try --help)" ;;
    esac
done
MODE=${MODE:-dry-run}

# No default database, and a strict name: it travels through ssh to a remote
# shell, and a typo should fail here rather than create or hit something else.
[ -n "$DB" ] || die "--database is required"
[[ $DB =~ ^[A-Za-z0-9_]+$ ]] || die "refusing database name '$DB'"
[ -d "$DIR" ] || die "no migrations directory at $DIR"

# Word-split on purpose, so "mysql -uroot" or a wrapper path both work.
read -r -a MYSQL <<< "$MYSQL_CMD"
[ ${#MYSQL[@]} -gt 0 ] || die "--mysql is empty"

# utf8mb4 for the reason docker/initdb/00-load.sh gives: without it the client
# negotiates latin1 and every em-dash in a migration's dialogue is mojibake.
sql()   { "${MYSQL[@]}" --default-character-set=utf8mb4 --batch --skip-column-names "$DB"; }
sql_v() { "${MYSQL[@]}" --default-character-set=utf8mb4 --batch "$DB"; }

# Must match the CREATE TABLE in schema.sql. Repeated here because a database
# built before the table existed only ever sees this copy.
CREATE_TABLE="CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(191) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    how ENUM('applied','baseline') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"

# Hashed with carriage returns removed, so a checkout that turned the files to
# CRLF still matches what an LF checkout recorded. For an LF file this is the
# same number sha256sum gives, which is what production's rows were made with;
# .gitattributes keeps these files LF in the first place.
checksum() { tr -d '\r' < "$1" | sha256sum | cut -d' ' -f1; }

# A statement that would change which database the session writes to: USE, or
# the client's own \u. Matched at a statement boundary — start of file, a new
# line, a `;`, the end of a comment — and also straight after the opening of an
# executable comment, because `/*! USE other */;` and `/*!40000 USE other */;`
# are run by MySQL exactly as if the comment marks were not there. Ordinary
# comments need no stripping: `-- USE x;` has `--` before the USE, not a
# boundary. It can refuse a file that only looks like this (a line of a quoted
# string reading "use them;"), which is the right way round to be wrong.
#
# Matched against the file with every line break turned into `;` (see
# check_file): grep cannot put a newline in a pattern, and this way a line
# start is a boundary like any other and a USE split over lines is one match.
SWITCH_DB='(^|;|\*/|/\*![0-9]*)[[:space:]]*(USE[[:space:];]+|\\u[[:space:];]*)`?[A-Za-z0-9_$]+`?[[:space:]]*(;|\*/|$)'

# Refuse what the runner cannot apply safely, before anything is sent.
check_file() {
    local f=$1 name last
    name=$(basename "$f")
    # Interpolated into an INSERT below, so the name is held to a safe shape.
    [[ $name =~ ^[A-Za-z0-9._-]+\.sql$ ]] || die "refusing file name '$name'"
    if tr '\r\n' ';;' < "$f" | grep -Eiq "$SWITCH_DB"; then
        die "$name contains a USE statement; migrations run against --database only"
    fi
    # The INSERT that records the file is appended to it; an unterminated last
    # statement would swallow it.
    last=$(grep -Ev '^[[:space:]]*(--.*)?$' "$f" | tail -n 1)
    [[ $last =~ \;[[:space:]]*$ ]] || die "$name: last statement is not terminated with ';'"
}

# --- what the database already has ---------------------------------------
[ "$(echo 'SELECT DATABASE();' | sql)" = "$DB" ] || die "could not connect to database '$DB'"

HAS_TABLE=$(echo "SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations';" | sql)

declare -A REC_SUM=() REC_HOW=() REC_AT=()
load_recorded() {
    REC_SUM=(); REC_HOW=(); REC_AT=()
    [ "$HAS_TABLE" = 1 ] || return 0
    local name sum how at
    while IFS=$'\t' read -r name sum how at; do
        [ -n "$name" ] || continue
        REC_SUM[$name]=$sum; REC_HOW[$name]=$how; REC_AT[$name]=$at
    done < <(echo "SELECT filename, checksum, how, applied_at FROM schema_migrations ORDER BY filename;" | sql)
}
load_recorded

shopt -s nullglob
FILES=("$DIR"/*.sql)
declare -A ON_DISK=()
PENDING=()
for f in "${FILES[@]}"; do
    name=$(basename "$f")
    ON_DISK[$name]=1
    [ -n "${REC_SUM[$name]+x}" ] || PENDING+=("$f")
done

warn_changed() {
    local f name sum
    for f in "${FILES[@]}"; do
        name=$(basename "$f")
        [ -n "${REC_SUM[$name]+x}" ] || continue
        sum=$(checksum "$f")
        if [ "$sum" != "${REC_SUM[$name]}" ]; then
            echo "WARNING: $name has changed since it was recorded (${REC_HOW[$name]} ${REC_AT[$name]})." >&2
            echo "         It will NOT be re-run. Write a new migration for the difference." >&2
        fi
    done
}

ensure_table() {
    echo "$CREATE_TABLE" | sql
    HAS_TABLE=1
}

case "$MODE" in
status)
    [ "$HAS_TABLE" = 1 ] || echo "(no schema_migrations table in $DB yet: nothing recorded)"
    for f in "${FILES[@]}"; do
        name=$(basename "$f")
        if [ -n "${REC_SUM[$name]+x}" ]; then
            flag=
            [ "$(checksum "$f")" = "${REC_SUM[$name]}" ] || flag="  CHANGED since recorded"
            printf '%-9s %-45s %s%s\n' "${REC_HOW[$name]}" "$name" "${REC_AT[$name]}" "$flag"
        else
            printf '%-9s %s\n' pending "$name"
        fi
    done
    for name in "${!REC_SUM[@]}"; do
        [ -n "${ON_DISK[$name]+x}" ] || printf '%-9s %-45s %s  (recorded, no such file here)\n' \
            "${REC_HOW[$name]}" "$name" "${REC_AT[$name]}"
    done
    echo "${#REC_SUM[@]} recorded, ${#PENDING[@]} pending."
    warn_changed
    ;;

dry-run)
    [ "$HAS_TABLE" = 1 ] || echo "(no schema_migrations table in $DB yet; --apply or --baseline will create it)"
    for f in "${PENDING[@]}"; do check_file "$f"; echo "would apply: $(basename "$f")"; done
    [ ${#PENDING[@]} -gt 0 ] || echo "nothing pending."
    warn_changed
    echo "(dry run: nothing written; pass --apply, or --baseline for files this database already has)"
    ;;

apply)
    # Check every pending file first, so a refused file late in the list stops
    # the run before anything earlier has been applied.
    for f in "${PENDING[@]}"; do check_file "$f"; done
    warn_changed
    ensure_table
    for f in "${PENDING[@]}"; do
        name=$(basename "$f"); sum=$(checksum "$f")
        echo "== applying $name"
        if ! { cat "$f"; printf '\nINSERT INTO schema_migrations (filename, checksum, how) VALUES (%s, %s, %s);\n' \
                   "'$name'" "'$sum'" "'applied'"; } | sql_v; then
            echo "migrate: $name FAILED; it is not recorded, and nothing after it was run." >&2
            exit 1
        fi
        echo "== recorded $name"
    done
    [ ${#PENDING[@]} -gt 0 ] || echo "nothing pending."
    ;;

baseline|baseline-all)
    if [ "$MODE" = baseline-all ]; then
        TARGETS=("${PENDING[@]}")
    else
        TARGETS=()
        for name in "${NAMED[@]}"; do
            [ -f "$DIR/$name" ] || die "no such migration: $DIR/$name"
            TARGETS+=("$DIR/$name")
        done
    fi
    for f in "${TARGETS[@]}"; do check_file "$f"; done
    ensure_table
    load_recorded
    batch=
    for f in "${TARGETS[@]}"; do
        name=$(basename "$f")
        if [ -n "${REC_SUM[$name]+x}" ]; then
            echo "already recorded (${REC_HOW[$name]}): $name"
            continue
        fi
        batch+="INSERT INTO schema_migrations (filename, checksum, how) VALUES ('$name', '$(checksum "$f")', 'baseline');"$'\n'
        echo "baseline: $name (recorded, not run)"
    done
    [ -z "$batch" ] || printf '%s' "$batch" | sql
    ;;
esac
