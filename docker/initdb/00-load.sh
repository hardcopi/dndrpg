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
# world became a location graph, and a schema that drops every table has no use
# for migrations that add columns to tables it just created.
for f in schema.sql seed_data.sql content.sql; do
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
done

echo "[initdb] done"
