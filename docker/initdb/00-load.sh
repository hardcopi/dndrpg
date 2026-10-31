#!/bin/bash
# Load the project's SQL files in dependency order.
#
# This exists because MySQL's entrypoint runs /docker-entrypoint-initdb.d in
# ALPHABETICAL order, which here would be content -> schema -> seed_data: every
# file would run before the tables it needs exist. The files carry their own
# CREATE DATABASE / USE rpg_5e, so no database is named here.
set -euo pipefail

# Three files, in dependency order:
#
#   schema.sql     every table, dropped and recreated
#   seed_data.sql  the rules a character sheet is built from (races, classes,
#                  avatars) plus the admin account
#   content.sql    everything with a story attached — regions and locations
#                  first, then the cast, the fights and the quests placed in
#                  them. Generated from content/**/*.json by
#                  tools/load_content.py; regenerate it before rebuilding if
#                  you have edited the JSON.
#
# The migrate_*.sql files are gone: they were folded into schema.sql when the
# world became a location graph. Their successors in /sql/migrations/ are run
# below, through the same runner production uses.
load() {
    local f=$1
    if [ -f "/sql/$f" ]; then
        echo "[initdb] loading $f"
        # --default-character-set=utf8mb4 is not optional. Without it the
        # client negotiates its compiled-in default (latin1), so every
        # multi-byte character in these files is read as two latin1 characters
        # and re-encoded on the way into a utf8mb4 column. The result is
        # mojibake that looks like a font problem: every em-dash in the
        # authored dialogue arrived as "â€”" and stayed that way, in thirteen
        # rows, until an export round-trip made it visible.
        mysql --default-character-set=utf8mb4 -uroot -p"${MYSQL_ROOT_PASSWORD}" < "/sql/$f"
    else
        echo "[initdb] WARNING: /sql/$f not found, skipping"
    fi
}

for f in schema.sql seed_data.sql content.sql; do
    load "$f"
done

# Then the migrations, so a fresh build has everything production has and a
# schema_migrations row for each file. Some are only content that
# load_content.py will not write — the free-play house and the road out of it
# live under `_` keys it skips — and without them a new party starts in a room
# with no door.
#
# The ones whose whole effect is already in the three files above are recorded
# as 'baseline' and not run; that list, and the reason for each, is
# /sql/migrations/BASELINE. The rest are applied, after content.sql, because
# they need its rows: the road joins the West Gate, and the shop is stocked
# from `items`.
#
# The runner gets the password through MYSQL_PWD rather than on its --mysql
# command, which it word-splits.
folded=()
while read -r name _; do
    case "$name" in ''|'#'*) continue ;; esac
    folded+=("$name")
done < /sql/migrations/BASELINE

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"
echo "[initdb] migrations: baseline ${#folded[@]} folded"
bash /sql/migrate.sh --mysql "mysql -uroot" --database rpg_5e --baseline "${folded[@]}"
echo "[initdb] migrations: apply the rest"
bash /sql/migrate.sh --mysql "mysql -uroot" --database rpg_5e --apply
unset MYSQL_PWD

# content.sql once more, last. A migration is frozen when it is written and
# content/ is not: 2026-08-25-freeplay-inn.sql still carries the August
# dialogue for the house's three people, which content/dialog/_fp_*.json has
# since rewritten, so running it after content.sql would put the old lines
# back. Production never saw that, because its content loads came after the
# migration. Reloading puts this build in the same order — content wins every
# row both write — and also resolves content.sql's own references to
# `_freeplay_cellar`, which did not exist the first time it ran. It is safe
# only because the database is empty of players; see "The database is the
# source of truth" in CLAUDE.md before doing this anywhere else.
load content.sql

echo "[initdb] done"
