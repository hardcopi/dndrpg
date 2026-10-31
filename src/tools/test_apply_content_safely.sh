#!/usr/bin/env bash
# Proves apply_content_safely.py is all-or-nothing, against a THROWAWAY MySQL.
#
#   bash tools/test_apply_content_safely.sh
#
# Starts its own mysql:8.0 container (no published port, data on tmpfs), loads
# schema + seed + content into a scratch database that is deliberately NOT
# called rpg_5e, writes some player progress, and then:
#
#   1. --apply             progress identical by key before and after; backups on disk
#   2. forced failure      a broken statement half-way down a copy of content.sql:
#                          non-zero exit, recovery steps printed, and every player
#                          table and the content tables byte-identical (CHECKSUM
#                          TABLE, ids included) — nothing half-applied
#   3. --restore-from      progress deleted by hand comes back from run 1's JSON
#   4. backup failure      unwritable --backup-dir, then a mysqldump that cannot
#                          log in: both abort before a single write
#
# Touches nothing but the container it starts, which the trap removes. Never
# point this at a real database; it does not take one as an argument.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(dirname "$HERE")"
TOOL="$HERE/apply_content_safely.py"
CTR="acs-test-$$-$RANDOM"
PW="scratch-$RANDOM$RANDOM"
DB=acs_scratch
WORK="$(mktemp -d /tmp/acs-test.XXXXXX)"

cleanup() {
    docker rm -f "$CTR" >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

sql() {  # sql <db> <statement>  — batch, no column names
    docker exec -i "$CTR" mysql -uroot -p"$PW" --default-character-set=utf8mb4 -N -B "$1" -e "$2" 2>/dev/null
}

MYSQL="docker exec -i $CTR mysql -uroot -p$PW"
DUMP="docker exec -i $CTR mysqldump -uroot -p$PW"
tool() { python3 "$TOOL" --database "$DB" --mysql "$MYSQL" "$@"; }

PLAYER_TABLES="party_quests party_quest_stages character_known_npcs party_items_taken
party_exits_found party_encounters_cleared party_locations_visited
character_inventory character_spells dungeon_delves"
CONTENT_TABLES="locations location_exits location_items quests quest_stages npcs
encounters items regions modules companions"

# Row count and CHECKSUM of each table — ids included, so "identical" means
# identical, not merely "the same keys came back".
id_fingerprint() {
    local t out=""
    for t in $PLAYER_TABLES $CONTENT_TABLES; do
        out+="$t $(sql "$DB" "SELECT COUNT(*) FROM $t") $(sql "$DB" "CHECKSUM TABLE $t EXTENDED" | cut -f2)"$'\n'
    done
    printf '%s' "$out"
}

# Progress as the tool itself sees it — by content KEY — hashed per table. A
# successful load renumbers location_exits and location_items, so ids are
# expected to move there; keys are what must survive.
key_fingerprint() {
    python3 - "$HERE" "$MYSQL" "$DB" <<'PY'
import hashlib, json, shlex, sys
sys.path.insert(0, sys.argv[1])
import apply_content_safely as m
m.CLIENT = shlex.split(sys.argv[2])
snap = m.snapshot(sys.argv[3])
for t, rows in snap.items():
    h = hashlib.sha256(json.dumps(sorted(rows, key=lambda r: [str(x) for x in r])).encode()).hexdigest()[:16]
    print(f"{t} {len(rows)} {h}")
PY
}

# --- a throwaway server ------------------------------------------------------
echo "starting $CTR ..."
docker run -d --name "$CTR" --tmpfs /var/lib/mysql:rw,size=2g \
    -e MYSQL_ROOT_PASSWORD="$PW" mysql:8.0 >/dev/null
# TCP is skipped while the entrypoint's temporary init server runs, so a
# successful TCP query means the real server is up.
for _ in $(seq 1 90); do
    docker exec "$CTR" mysql -uroot -p"$PW" -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 2
done
docker exec "$CTR" mysql -uroot -p"$PW" -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1 \
    || fail "mysql did not come up"

# The scratch database, plus an empty decoy named rpg_5e. content.sql says
# `USE rpg_5e`; if the tool ever let that through, the decoy would grow tables
# (or the load would fail on it) — either way the check below catches it.
sql mysql "CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
           CREATE DATABASE rpg_5e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "loading schema, seed, content into $DB ..."
for f in schema.sql seed_data.sql content.sql; do
    # Same order as docker/initdb/00-load.sh, minus the lines that name rpg_5e.
    sed -e '/^USE rpg_5e;/d' -e '/^CREATE DATABASE/,/;/d' "$SRC/sql/$f" \
        | docker exec -i "$CTR" mysql -uroot -p"$PW" --default-character-set=utf8mb4 "$DB" 2>/dev/null \
        || fail "loading $f"
done

echo "writing player progress ..."
sql "$DB" "
INSERT INTO users (username) VALUES ('acs_tester');
INSERT INTO parties (user_id, name) SELECT id, 'Test Party' FROM users WHERE username='acs_tester';
SET @p = LAST_INSERT_ID();
INSERT INTO characters (user_id, name, race, class, strength, dexterity, constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class)
  SELECT id, 'Ada', 'Human', 'Fighter', 15,12,14,10,11,9, 12,12,16 FROM users WHERE username='acs_tester';
SET @c1 = LAST_INSERT_ID();
INSERT INTO characters (user_id, name, race, class, strength, dexterity, constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class)
  SELECT id, 'Bo', 'Elf', 'Wizard', 8,14,12,16,12,10, 7,7,12 FROM users WHERE username='acs_tester';
SET @c2 = LAST_INSERT_ID();
INSERT INTO party_quests (party_id, quest_id, status, current_stage_id, resolution, is_tracked, started_at, ended_at)
  SELECT @p, q.id, 'active', (SELECT MIN(s.id) FROM quest_stages s WHERE s.quest_id = q.id), NULL, 1, '2026-10-01 10:00:00', NULL
    FROM quests q WHERE q.quest_key NOT LIKE '\\_%' ORDER BY q.quest_key LIMIT 4;
INSERT INTO party_quests (party_id, quest_id, status, current_stage_id, resolution, is_tracked, started_at, ended_at)
  SELECT @p, q.id, 'completed', NULL, 'done', 0, '2026-10-02 10:00:00', '2026-10-03 10:00:00'
    FROM quests q WHERE q.quest_key NOT LIKE '\\_%' ORDER BY q.quest_key DESC LIMIT 1;
INSERT INTO party_quest_stages (party_id, quest_id, stage_id, entered_at)
  SELECT @p, s.quest_id, s.id, '2026-10-01 11:00:00' FROM quest_stages s
    WHERE s.quest_id IN (SELECT quest_id FROM party_quests WHERE party_id=@p);
INSERT INTO character_known_npcs (character_id, npc_id, known_at)
  SELECT @c1, id, '2026-10-01 12:00:00' FROM npcs ORDER BY npc_key LIMIT 6;
INSERT INTO character_known_npcs (character_id, npc_id, known_at)
  SELECT @c2, id, '2026-10-01 12:30:00' FROM npcs ORDER BY npc_key DESC LIMIT 3;
INSERT INTO party_exits_found (party_id, exit_id, found_at)
  SELECT @p, id, '2026-10-01 13:00:00' FROM location_exits ORDER BY id LIMIT 7;
INSERT INTO party_items_taken (party_id, location_item_id, taken_at)
  SELECT @p, id, '2026-10-01 14:00:00' FROM location_items ORDER BY id LIMIT 4;
INSERT INTO party_encounters_cleared (party_id, encounter_id, cleared_at)
  SELECT @p, id, '2026-10-01 15:00:00' FROM encounters WHERE encounter_key NOT LIKE '\\_%' ORDER BY encounter_key LIMIT 3;
INSERT INTO party_locations_visited (party_id, location_id, first_visited_at)
  SELECT @p, id, '2026-10-01 16:00:00' FROM locations ORDER BY location_key LIMIT 8;
INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
  SELECT @c1, id, 1, 1 FROM items ORDER BY item_key LIMIT 3;
INSERT INTO character_spells (character_id, spell_id, prepared)
  SELECT @c2, id, 1 FROM spells ORDER BY spell_key LIMIT 3;
INSERT INTO dungeon_delves (party_id, seed, depth, mouth_location_id, mouth_location_key)
  SELECT @p, 12345, 1, id, location_key FROM locations WHERE location_key = 'uv_mouth';
"

echo; echo "player progress written:"
for t in $PLAYER_TABLES; do printf '  %-26s %s\n' "$t" "$(sql "$DB" "SELECT COUNT(*) FROM $t")"; done
[ "$(sql "$DB" 'SELECT COUNT(*) FROM party_quests')" -gt 0 ] || fail "no progress written"

ORIG_KEYS="$(key_fingerprint)"

# --- Run 1: a normal apply ---------------------------------------------------
echo; echo "================ RUN 1: --apply ================"
B1="$WORK/backups1"
set +e
tool --apply --mysqldump "$DUMP" --backup-dir "$B1" 2>&1 | grep -v 'Using a password'
rc=${PIPESTATUS[0]}
set -e
[ "$rc" -eq 0 ] || fail "run 1 exited $rc"
AFTER1_KEYS="$(key_fingerprint)"
echo "--- progress by key, before vs after:"
paste -d' ' <(echo "$ORIG_KEYS") <(echo "$AFTER1_KEYS" | cut -d' ' -f2-) | column -t
[ "$ORIG_KEYS" = "$AFTER1_KEYS" ] || fail "run 1 changed player progress"
SNAP1="$(ls "$B1"/*-progress.json)"; DUMP1="$(ls "$B1"/*.sql.gz)"
[ -s "$SNAP1" ] && [ -s "$DUMP1" ] || fail "run 1 backups missing"
gunzip -c "$DUMP1" | tail -1 | grep -q 'Dump completed' || fail "dump has no trailer"
ls -l "$B1"
[ "$(sql rpg_5e 'SHOW TABLES' | wc -l)" -eq 0 ] || fail "decoy rpg_5e was written to — USE leaked"
pass "run 1: progress identical by key, snapshot + dump written, decoy rpg_5e untouched"

# --- Run 2: forced failure half-way through content.sql ---------------------
echo; echo "================ RUN 2: forced mid-load failure ================"
BROKEN="$WORK/content-broken.sql"
python3 - "$HERE" "$SRC/sql/content.sql" "$BROKEN" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
import apply_content_safely as m
stmts = m.split_statements(open(sys.argv[2], encoding="utf-8").read())
half = len(stmts) // 2
stmts.insert(half, "\nINSERT INTO this_table_does_not_exist VALUES (1)")
open(sys.argv[3], "w", encoding="utf-8").write(";".join(stmts) + ";\n")
print(f"injected a failing statement at {half} of {len(stmts) - 1}; the "
      f"{sum(1 for s in stmts[:half] if m.verb(s) == 'DELETE')} DELETEs before it "
      f"would have wiped progress had they committed")
PY
BEFORE2="$(id_fingerprint)"
B2="$WORK/backups2"
set +e
APPLY_CONTENT_SAFELY_TEST=1 tool --apply --mysqldump "$DUMP" --backup-dir "$B2" \
    --content-sql "$BROKEN" > "$WORK/run2.out" 2>&1
rc=$?
set -e
grep -v 'Using a password' "$WORK/run2.out"
[ "$rc" -ne 0 ] || fail "run 2 should have failed"
grep -q -- '--restore-from' "$WORK/run2.out" || fail "run 2 printed no recovery steps"
AFTER2="$(id_fingerprint)"
echo "--- table count checksum, before vs after:"
paste -d' ' <(echo "$BEFORE2") <(echo "$AFTER2" | cut -d' ' -f2-) | column -t
[ "$BEFORE2" = "$AFTER2" ] || fail "run 2 left the database changed"
pass "run 2: exit $rc, recovery printed, every player and content table identical (ids and checksums)"

# The guard on the test-only flag.
set +e
tool --check --content-sql "$BROKEN" >/dev/null 2>&1; rc=$?
set -e
[ "$rc" -ne 0 ] || fail "--content-sql worked without $APPLY_CONTENT_SAFELY_TEST"
pass "--content-sql refused without APPLY_CONTENT_SAFELY_TEST=1"

# --- Run 3: --restore-from on its own ---------------------------------------
echo; echo "================ RUN 3: --restore-from ================"
sql "$DB" "DELETE FROM party_quest_stages; DELETE FROM party_quests; DELETE FROM character_known_npcs;
           DELETE FROM party_exits_found; DELETE FROM party_items_taken; DELETE FROM party_locations_visited;"
echo "after deleting by hand:"; key_fingerprint | sed 's/^/  /'
# A snapshot labelled with another database is refused without --force-database.
OTHER="$WORK/other-db-progress.json"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d["database"]="some_other_db"; json.dump(d, open(sys.argv[2],"w"))' "$SNAP1" "$OTHER"
BEFORE3="$(key_fingerprint)"
set +e
tool --restore-from "$OTHER" >"$WORK/run3a.out" 2>&1; rc=$?
set -e
[ "$rc" -eq 1 ] || fail "run 3a: mismatched snapshot label exited $rc, expected 1"
grep -q -- '--force-database' "$WORK/run3a.out" || fail "run 3a: refusal did not name --force-database"
[ "$BEFORE3" = "$(key_fingerprint)" ] || fail "run 3a: refused restore wrote to the database"
pass "run 3a: snapshot from another database refused (exit 1), nothing written"
tool --restore-from "$SNAP1" 2>&1 | grep -v 'Using a password'
AFTER3_KEYS="$(key_fingerprint)"
[ "$ORIG_KEYS" = "$AFTER3_KEYS" ] || { paste -d' ' <(echo "$ORIG_KEYS") <(echo "$AFTER3_KEYS"); fail "run 3 did not restore"; }
pass "run 3: progress identical by key to the original after --restore-from"
tool --restore-from "$OTHER" --force-database >"$WORK/run3b.out" 2>&1 \
    || { cat "$WORK/run3b.out"; fail "run 3b: --force-database restore failed"; }
[ "$ORIG_KEYS" = "$(key_fingerprint)" ] || fail "run 3b: forced re-restore changed progress"
pass "run 3b: --force-database accepts it (idempotent over restored rows)"

# --- Run 4: backups that cannot be written ----------------------------------
echo; echo "================ RUN 4: backup failure aborts ================"
BEFORE4="$(id_fingerprint)"
touch "$WORK/not-a-dir"
set +e
tool --apply --mysqldump "$DUMP" --backup-dir "$WORK/not-a-dir/backups" 2>&1 | grep -v 'Using a password'
rc=${PIPESTATUS[0]}
set -e
[ "$rc" -ne 0 ] || fail "run 4a should have failed"
[ "$BEFORE4" = "$(id_fingerprint)" ] || fail "run 4a wrote to the database"
pass "run 4a: unwritable --backup-dir, exit $rc, database unchanged"

B4="$WORK/backups4"
set +e
tool --apply --mysqldump "docker exec -i $CTR mysqldump -uroot -pnot-the-password" \
    --backup-dir "$B4" 2>&1 | grep -v 'Using a password'
rc=${PIPESTATUS[0]}
set -e
[ "$rc" -ne 0 ] || fail "run 4b should have failed"
[ "$BEFORE4" = "$(id_fingerprint)" ] || fail "run 4b wrote to the database"
[ -z "$(ls "$B4"/*.sql.gz* 2>/dev/null)" ] || fail "run 4b left a dump file behind"
pass "run 4b: mysqldump failure, exit $rc, database unchanged, no partial dump left"

# --- Control: is run 2 capable of failing? ----------------------------------
# The same broken file, piped the way the tool used to pipe it — no
# transaction. If this does NOT damage progress, run 2's "identical" proved
# nothing. Last, because it does exactly the damage it is looking for.
echo; echo "================ CONTROL: the broken file without a transaction ================"
before_pq="$(sql "$DB" 'SELECT COUNT(*) FROM party_quests')"
sed '/^USE rpg_5e;/d' "$BROKEN" \
    | docker exec -i "$CTR" mysql -uroot -p"$PW" --default-character-set=utf8mb4 "$DB" 2>/dev/null \
    && fail "control: the broken file loaded cleanly"
after_pq="$(sql "$DB" 'SELECT COUNT(*) FROM party_quests')"
echo "party_quests: $before_pq before, $after_pq after a non-transactional load that failed half-way"
[ "$after_pq" -lt "$before_pq" ] || fail "control: a non-transactional failure did no damage, so run 2 is not a test"
pass "control: without the transaction the same failure loses progress ($before_pq -> $after_pq)"

echo; echo "ALL PASS"
