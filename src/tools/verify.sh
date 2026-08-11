#!/usr/bin/env bash
# Run every check in one go and print one report.
#
# This exists because the agent that wrote most of Act 1 could not execute any
# of it — no PHP, no MySQL, no network to the host — so the run/diagnose loop is
# split across two machines. One command that produces one pasteable blob is a
# lot less friction than eight commands and eight copy-pastes.
#
#   tools/verify.sh              # static checks + PHP harnesses
#   tools/verify.sh --db         # also load SQL and probe the HTTP API
#
# Never exits early: a failing harness should not hide the state of the five
# checks after it, because when several things are broken at once the useful
# information is which ones.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

PASS=0
FAIL=0
FAILED_NAMES=()

run() {
    local name="$1"; shift
    printf '\n\033[1m── %s ─────────────────────────────────\033[0m\n' "$name"
    if "$@" 2>&1; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        FAILED_NAMES+=("$name")
        printf '\033[31m^^ %s FAILED (exit %d)\033[0m\n' "$name" "$?"
    fi
}

printf '\033[1mrpg verification — %s\033[0m\n' "$(date '+%Y-%m-%d %H:%M')"
printf 'php:    %s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'NOT FOUND')"
# The SERVER version, not the client's. `mysql --version` reports whichever
# client binary is installed, which on this host is MariaDB while the server it
# connects to is MySQL 8 — reading the client once produced a whole afternoon of
# worrying about production drift that did not exist.
printf 'db:     %s\n' "$(mysql -h 127.0.0.1 -P "${DB_PORT:-3307}" -u "${DB_USER:-web}" \
    -p"${DB_PASS:-devpassword}" -N -B -e 'SELECT VERSION()' 2>/dev/null \
    || echo 'could not connect — see tools/check_db_compat.php')"
printf 'client: %s\n' "$(mysql --version 2>/dev/null || echo 'NOT FOUND')"
printf 'python: %s\n' "$(python3 --version 2>/dev/null | sed 's/Python //' || echo 'NOT FOUND')"

# Static first. These need nothing but the filesystem, so when the harnesses
# below fail these tell you whether it is the code or the environment.
run "lint_php"        python3 tools/lint_php.py
run "lint_js"         python3 tools/lint_js.py
run "content_valid"   python3 tools/load_content.py --check
run "content_trace"   python3 tools/trace_content.py
# Proves the icosahedron and cube geometry, and that the generated files on disk
# match what the generator would produce now. The browser's agreement with those
# numbers is a separate claim and needs a browser: /tools/dice_preview.html.
run "dice_geometry"   python3 tools/gen_dice_css.py --check
# Every var() resolves to a declaration. This is the one CSS claim that can be
# checked without a browser, and it is worth checking here because the failure
# it catches is silent: a token nobody declared renders its fallback and looks
# like it worked. Six had accumulated that way.
run "token_check"     python3 tools/token_check.py

# The PHP harnesses. There is no PHP CLI on the dev host, so when `php` is
# absent they run inside the compose stack's php container instead — same
# interpreter and same env vars the site itself runs under.
PHP_MODE=none
if command -v php >/dev/null 2>&1; then
    PHP_MODE=host
elif [ -f ../docker-compose.yml ] && command -v docker >/dev/null 2>&1 \
        && docker compose --project-directory .. ps php >/dev/null 2>&1; then
    PHP_MODE=docker
fi
phprun() {
    if [ "$PHP_MODE" = host ]; then
        php "tools/$1"
    else
        docker compose --project-directory .. exec -T php php "/var/www/html/tools/$1"
    fi
}

if [ "$PHP_MODE" != none ]; then
    # Before anything is executed: does every PHP file in the tree parse?
    #
    # This is first because it is the cheapest question and the one whose
    # absence cost the most. lint_php.py above is a cross-reference checker and
    # says in its own docstring that it is not a parser; every harness below
    # runs one file and exercises the engine. So the forty pages a player
    # actually requests were the only PHP nothing looked at, and two of them
    # shipped with a parse error that nobody saw until the live site returned a
    # 500 for a character sheet.
    run "php_syntax"          phprun lint_syntax.php

    # Statics next: pure arithmetic over the rules tables, no PDO behind them.
    run "test_rules"          phprun test_rules.php
    run "test_checks"         phprun test_checks.php
    run "test_combat"         phprun test_combat.php
    run "test_content_rules"  phprun test_content_rules.php
    run "test_actions"        phprun test_actions.php
    run "test_class_features" phprun test_class_features.php
    run "test_races"          phprun test_races.php
    run "test_subclasses"     phprun test_subclasses.php
    run "test_dash"           phprun test_dash.php
    run "test_dying"          phprun test_dying.php
    run "test_extra_attack"   phprun test_extra_attack.php
    run "test_movement"       phprun test_movement.php
    run "test_sneak"          phprun test_sneak.php
    run "test_wards"          phprun test_wards.php
    run "test_focus"          phprun test_focus.php
    run "test_paperdoll"      phprun test_paperdoll.php

    # DB-backed. Every one of these is safe against a database somebody is
    # playing on: scratch rows inside a transaction they roll back, or a
    # throwaway party they remove on the way out.
    run "test_creation"       phprun test_creation.php
    run "test_route"          phprun test_route.php
    run "test_auth"           phprun test_auth.php
    run "test_levelup"        phprun test_levelup.php
    run "test_feats"          phprun test_feats.php
    run "test_inventory"      phprun test_inventory.php
    run "test_spellbook"      phprun test_spellbook.php
    run "test_romance"        phprun test_romance.php
    run "test_haven"          phprun test_haven.php
    run "test_pressure"       phprun test_pressure.php
    run "test_quest_module"   phprun test_quest_module.php
    run "test_quest_marks"    phprun test_quest_marks.php
    run "test_companions"     phprun test_companions.php
    run "test_dialogue_once"  phprun test_dialogue_once.php
    run "test_dialogue_lint"  phprun test_dialogue_lint.php
    run "test_content_export" phprun test_content_export.php
    run "test_book"           phprun test_book.php
    run "test_art"            phprun test_art.php

    run "image_libs"          phprun check_image_libs.php
    run "db_compat"           phprun check_db_compat.php
else
    printf '\n\033[33mSKIPPED the PHP harnesses — no php on PATH and no compose\n'
    printf 'stack. They run in the container:\n'
    printf 'docker compose exec -T php php /var/www/html/tools/<name>.php\033[0m\n'
fi

if [ "${1:-}" = "--db" ]; then
    : "${DB_USER:=web}" "${DB_PASS:=devpassword}" "${DB_NAME:=rpg_5e}" "${DB_PORT:=3307}"
    : "${SITE:=http://localhost:8081}"

    printf '\n\033[1;33mLoading SQL. schema.sql DROPS EVERY TABLE.\033[0m\n'
    read -r -p "Type 'load' to continue: " confirm
    if [ "$confirm" = "load" ]; then
        run "generate_content_sql" python3 tools/load_content.py
        # The same three files docker/initdb/00-load.sh loads, in the same
        # dependency order. There is no maps.sql or migrate_*.sql any more: the
        # world is a location graph carried in content.sql, and the migrations
        # were folded into schema.sql when it moved.
        #
        # --default-character-set=utf8mb4 is not optional; without it the client
        # uses its compiled-in latin1 and every em-dash in the authored dialogue
        # lands as mojibake. See the note in 00-load.sh.
        for f in schema seed_data content; do
            run "sql:$f" mysql --default-character-set=utf8mb4 \
                -h 127.0.0.1 -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
                "$DB_NAME" -e "SOURCE sql/${f}.sql"
        done
        # A count of what actually landed is the fastest way to see a file that
        # loaded without error but matched nothing. locations and location_exits
        # are the two that matter most: a world with rooms and no doors loads
        # perfectly and cannot be walked.
        run "row_counts" mysql -h 127.0.0.1 -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
            "$DB_NAME" -e "
              SELECT 'npcs' t, COUNT(*) n FROM npcs
              UNION SELECT 'quests', COUNT(*) FROM quests
              UNION SELECT 'quest_stages', COUNT(*) FROM quest_stages
              UNION SELECT 'companions', COUNT(*) FROM companions
              UNION SELECT 'monsters', COUNT(*) FROM monsters
              UNION SELECT 'items', COUNT(*) FROM items
              UNION SELECT 'encounters', COUNT(*) FROM encounters
              UNION SELECT 'regions', COUNT(*) FROM regions
              UNION SELECT 'locations', COUNT(*) FROM locations
              UNION SELECT 'location_exits', COUNT(*) FROM location_exits;"
    else
        printf 'skipped.\n'
    fi

    # The API is the real integration test: it exercises bootstrap, the PDO
    # config, the autoloader and the router in one request.
    printf '\n\033[1m── http ─────────────────────────────────\033[0m\n'
    for route in 'meta/races' 'meta/classes' 'session/status'; do
        code=$(curl -s -o /tmp/rpg_probe -w '%{http_code}' "${SITE}/api/?r=${route}")
        printf '%-18s HTTP %s  %s\n' "$route" "$code" "$(head -c 160 /tmp/rpg_probe)"
    done
fi

printf '\n\033[1m═══ %d passed, %d failed ═══\033[0m\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then
    printf 'failed: %s\n' "${FAILED_NAMES[*]}"
    exit 1
fi
