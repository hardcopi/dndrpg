#!/usr/bin/env python3
"""
Load authored content into a database that somebody is still playing.

Why this exists
---------------
`sql/content.sql` is generated to rebuild the world from `content/**/*.json`,
and it is safe to run against an empty database. It is *not* safe to run against
a populated one, and the failure is quiet enough to be worth spelling out.

Three things in it destroy player progress:

  1. It opens by deleting `party_quests`, `party_quest_stages` and
     `character_known_npcs` for every key the files mention. A party three
     quests into Act I loses all three — not the quest definitions, the
     *progress*. Row counts elsewhere still look right, so nothing announces it.

  2. It rebuilds `location_exits` and `location_items` wholesale
     (`DELETE FROM ...` then re-insert). Those tables hand out new auto-increment
     ids, and `party_exits_found` and `party_items_taken` point at the old ones.
     The foreign keys cascade, so the party forgets which secret doors it has
     found and which ground loot it has already taken — the second of which is
     an item duplication bug rather than a loss.

  3. It retires content by key: any row whose key is no longer in the files is
     deleted. That is correct and wanted, but it cascades into player data too.

This tool does the load with the progress carried across it. It snapshots the
player-side tables **by content key rather than by id**, applies the content,
then puts them back by resolving those keys to whatever ids the load produced.
Keys are stable across a rebuild by design — that is the whole point of
`docs/CONTENT.md`'s "Keys, not ids" rule — so this is a mapping the tool can
always make, and the only rows it cannot restore are ones whose content genuinely
went away.

    python3 tools/apply_content_safely.py --check      # report, change nothing
    python3 tools/apply_content_safely.py --apply

A trap worth knowing about
--------------------------
`sql/content.sql` contains a literal `USE rpg_5e;`. Piping it into a *different*
database does not send it there — the USE wins and it lands on the live one.
That is how this tool's author destroyed a live save while trying to test the
destruction safely in a scratch database. Every statement here is therefore sent
with an explicit database argument and the `USE` line is stripped, so `--database`
means what it says.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CONTENT_SQL = ROOT / "sql" / "content.sql"

COMPOSE_DIR = ROOT.parent
DB_SERVICE = "db"
DB_USER = "web"
DB_PASS = "devpassword"


# Each entry: the player table, a SELECT that renders its rows as content keys
# instead of ids, the INSERT that turns keys back into ids, and an `identity`
# clause that says when a row is already there.
#
# Written out longhand rather than derived from foreign keys because the mapping
# is not mechanical: `party_quests.current_stage_id` needs the quest key *and*
# the stage key to be unambiguous, and `party_items_taken` needs the location and
# the item. A generic version of this would have to know that anyway.
#
# `identity` exists because only some of these tables are actually damaged by a
# content load. `character_inventory` and `character_spells` are untouched unless
# an item or spell is retired, so a blind re-insert doubles them — 24 items
# became 48 the first time this ran, and `INSERT IGNORE` did not help because
# there is no unique key on (character_id, item_id) to ignore against. Restoring
# is therefore conditional on the row being genuinely absent, which also makes
# the whole tool safe to run twice.
SNAPSHOTS = [
    {
        "table": "party_quests",
        "select": """
            SELECT pq.party_id, q.quest_key, COALESCE(cs.stage_key,''), pq.status,
                   COALESCE(pq.resolution,''), pq.is_tracked,
                   pq.started_at, COALESCE(pq.ended_at,'')
              FROM party_quests pq
              JOIN quests q ON q.id = pq.quest_id
              LEFT JOIN quest_stages cs ON cs.id = pq.current_stage_id
        """,
        "restore": """
            INSERT IGNORE INTO party_quests
                (party_id, quest_id, status, current_stage_id, resolution, is_tracked, started_at, ended_at)
            SELECT {party_id}, q.id, {status}, cs.id, {resolution}, {is_tracked}, {started_at}, {ended_at}
              FROM quests q
              LEFT JOIN quest_stages cs
                     ON cs.quest_id = q.id AND cs.stage_key = {stage_key}
             WHERE q.quest_key = {quest_key}
        """,
        "cols": ["party_id", "quest_key", "stage_key", "status", "resolution",
                 "is_tracked", "started_at", "ended_at"],
        "nullable": {"ended_at", "stage_key", "resolution"},
        "identity": "x.party_id = {party_id} AND x.quest_id = q.id",
    },
    {
        "table": "party_quest_stages",
        "select": """
            SELECT pqs.party_id, q.quest_key, s.stage_key, pqs.entered_at
              FROM party_quest_stages pqs
              JOIN quests q ON q.id = pqs.quest_id
              JOIN quest_stages s ON s.id = pqs.stage_id
        """,
        "restore": """
            INSERT IGNORE INTO party_quest_stages (party_id, quest_id, stage_id, entered_at)
            SELECT {party_id}, q.id, s.id, {entered_at}
              FROM quests q JOIN quest_stages s ON s.quest_id = q.id
             WHERE q.quest_key = {quest_key} AND s.stage_key = {stage_key}
        """,
        "cols": ["party_id", "quest_key", "stage_key", "entered_at"],
        "nullable": set(),
        "identity": "x.party_id = {party_id} AND x.quest_id = q.id AND x.stage_id = s.id",
    },
    {
        "table": "character_known_npcs",
        "select": """
            SELECT ckn.character_id, n.npc_key, ckn.known_at
              FROM character_known_npcs ckn JOIN npcs n ON n.id = ckn.npc_id
        """,
        "restore": """
            INSERT IGNORE INTO character_known_npcs (character_id, npc_id, known_at)
            SELECT {character_id}, n.id, {known_at} FROM npcs n WHERE n.npc_key = {npc_key}
        """,
        "cols": ["character_id", "npc_key", "known_at"],
        "nullable": set(),
        "identity": "x.character_id = {character_id} AND x.npc_id = n.id",
    },
    {
        "table": "party_items_taken",
        "select": """
            SELECT pit.party_id, l.location_key, i.item_key, pit.taken_at
              FROM party_items_taken pit
              JOIN location_items li ON li.id = pit.location_item_id
              JOIN locations l ON l.id = li.location_id
              JOIN items i ON i.id = li.item_id
        """,
        "restore": """
            INSERT IGNORE INTO party_items_taken (party_id, location_item_id, taken_at)
            SELECT {party_id}, li.id, {taken_at}
              FROM location_items li
              JOIN locations l ON l.id = li.location_id
              JOIN items i ON i.id = li.item_id
             WHERE l.location_key = {location_key} AND i.item_key = {item_key}
        """,
        "cols": ["party_id", "location_key", "item_key", "taken_at"],
        "nullable": set(),
        "identity": "x.party_id = {party_id} AND x.location_item_id = li.id",
    },
    {
        "table": "party_exits_found",
        "select": """
            SELECT pef.party_id, f.location_key, t.location_key, pef.found_at
              FROM party_exits_found pef
              JOIN location_exits e ON e.id = pef.exit_id
              JOIN locations f ON f.id = e.from_location_id
              JOIN locations t ON t.id = e.to_location_id
        """,
        "restore": """
            INSERT IGNORE INTO party_exits_found (party_id, exit_id, found_at)
            SELECT {party_id}, e.id, {found_at}
              FROM location_exits e
              JOIN locations f ON f.id = e.from_location_id
              JOIN locations t ON t.id = e.to_location_id
             WHERE f.location_key = {from_key} AND t.location_key = {to_key}
        """,
        "cols": ["party_id", "from_key", "to_key", "found_at"],
        "nullable": set(),
        "identity": "x.party_id = {party_id} AND x.exit_id = e.id",
    },
    {
        "table": "party_encounters_cleared",
        # Runtime encounters are skipped rather than carried. PitEngine mints one
        # per party as `_pit_party_<id>` (PitEngine.php:327) and recreates it on
        # demand, so content.sql retiring it is harmless and self-healing. Trying
        # to restore the cleared-record would fail anyway — the row it pointed at
        # is gone by definition — and it would be reported as content loss, which
        # is the one thing this tool's report must not cry wolf about.
        "select": """
            SELECT pec.party_id, e.encounter_key, pec.cleared_at
              FROM party_encounters_cleared pec JOIN encounters e ON e.id = pec.encounter_id
             WHERE e.encounter_key NOT LIKE '\\_%'
        """,
        "restore": """
            INSERT IGNORE INTO party_encounters_cleared (party_id, encounter_id, cleared_at)
            SELECT {party_id}, e.id, {cleared_at} FROM encounters e WHERE e.encounter_key = {encounter_key}
        """,
        "cols": ["party_id", "encounter_key", "cleared_at"],
        "nullable": set(),
        "identity": "x.party_id = {party_id} AND x.encounter_id = e.id",
    },
    {
        "table": "party_locations_visited",
        "select": """
            SELECT plv.party_id, l.location_key, plv.first_visited_at
              FROM party_locations_visited plv JOIN locations l ON l.id = plv.location_id
        """,
        "restore": """
            INSERT IGNORE INTO party_locations_visited (party_id, location_id, first_visited_at)
            SELECT {party_id}, l.id, {first_visited_at} FROM locations l WHERE l.location_key = {location_key}
        """,
        "cols": ["party_id", "location_key", "first_visited_at"],
        "nullable": set(),
        "identity": "x.party_id = {party_id} AND x.location_id = l.id",
    },
    {
        "table": "character_inventory",
        "select": """
            SELECT ci.character_id, i.item_key, ci.quantity, ci.is_equipped
              FROM character_inventory ci JOIN items i ON i.id = ci.item_id
        """,
        "restore": """
            INSERT IGNORE INTO character_inventory (character_id, item_id, quantity, is_equipped)
            SELECT {character_id}, i.id, {quantity}, {is_equipped} FROM items i WHERE i.item_key = {item_key}
        """,
        "cols": ["character_id", "item_key", "quantity", "is_equipped"],
        "nullable": set(),
        "identity": "x.character_id = {character_id} AND x.item_id = i.id",
    },
    {
        "table": "character_spells",
        "select": """
            SELECT cs.character_id, s.spell_key, cs.prepared
              FROM character_spells cs JOIN spells s ON s.id = cs.spell_id
        """,
        "restore": """
            INSERT IGNORE INTO character_spells (character_id, spell_id, prepared)
            SELECT {character_id}, s.id, {prepared} FROM spells s WHERE s.spell_key = {spell_key}
        """,
        "cols": ["character_id", "spell_key", "prepared"],
        "nullable": set(),
        "identity": "x.character_id = {character_id} AND x.spell_id = s.id",
    },
]


def mysql(database: str, sql: str, batch: bool = True) -> str:
    """One statement (or several) against one database, named explicitly."""
    cmd = ["docker", "compose", "exec", "-T", DB_SERVICE,
           "mysql", f"-u{DB_USER}", f"-p{DB_PASS}",
           "--default-character-set=utf8mb4"]
    if batch:
        cmd += ["-N", "-B"]
    cmd += [database, "-e", sql]
    proc = subprocess.run(cmd, cwd=COMPOSE_DIR, capture_output=True, text=True)
    if proc.returncode != 0:
        err = "\n".join(l for l in proc.stderr.splitlines()
                        if "Using a password" not in l)
        raise RuntimeError(f"mysql failed:\n{err}")
    return proc.stdout


def quote(value: str | None) -> str:
    if value is None or value == "\\N":
        return "NULL"
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def snapshot(database: str) -> dict[str, list[list[str]]]:
    out = {}
    for spec in SNAPSHOTS:
        raw = mysql(database, " ".join(spec["select"].split()))
        rows = [line.split("\t") for line in raw.splitlines() if line.strip()]
        out[spec["table"]] = rows
    return out


def restore(database: str, snap: dict[str, list[list[str]]]) -> dict[str, tuple[int, int]]:
    """Put the snapshot back. Returns table -> (rows wanted, rows actually there)."""
    report = {}
    for spec in SNAPSHOTS:
        table, rows = spec["table"], snap[spec["table"]]
        for row in rows:
            values = dict(zip(spec["cols"], row))
            params = {}
            for col, val in values.items():
                if col in spec["nullable"] and val == "":
                    params[col] = "NULL"
                elif val.lstrip("-").isdigit():
                    params[col] = val
                else:
                    params[col] = quote(val)
            sql = spec["restore"].format(**params)
            # Only insert what is genuinely missing. The guard references the
            # target table, which MySQL permits inside an INSERT ... SELECT
            # subquery (the 1093 restriction is UPDATE/DELETE only).
            sql += (" AND NOT EXISTS (SELECT 1 FROM " + table + " x WHERE "
                    + spec["identity"].format(**params) + ")")
            mysql(database, " ".join(sql.split()), batch=False)
        after = int(mysql(database, f"SELECT COUNT(*) FROM {table}").strip() or 0)
        report[table] = (len(rows), after)
    return report


def apply_content(database: str) -> None:
    """
    Run content.sql against a named database.

    The `USE` line is stripped rather than trusted: it hardcodes `rpg_5e`, so a
    file piped at any other database silently lands on the live one instead.
    """
    sql = CONTENT_SQL.read_text(encoding="utf-8")
    cleaned = "\n".join(
        line for line in sql.splitlines()
        if not line.strip().lower().startswith("use ")
    )
    cmd = ["docker", "compose", "exec", "-T", DB_SERVICE,
           "mysql", f"-u{DB_USER}", f"-p{DB_PASS}",
           "--default-character-set=utf8mb4", database]
    proc = subprocess.run(cmd, cwd=COMPOSE_DIR, input=cleaned,
                          capture_output=True, text=True)
    if proc.returncode != 0:
        err = "\n".join(l for l in proc.stderr.splitlines()
                        if "Using a password" not in l)
        raise RuntimeError(f"content.sql failed:\n{err}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--database", default="rpg_5e",
                    help="target database (default: rpg_5e)")
    ap.add_argument("--apply", action="store_true",
                    help="actually do it; without this nothing is written")
    ap.add_argument("--check", action="store_true",
                    help="report what is at risk and exit")
    args = ap.parse_args()

    if not CONTENT_SQL.exists():
        print(f"No {CONTENT_SQL}. Run tools/load_content.py first.", file=sys.stderr)
        return 1

    print(f"target database: {args.database}\n")
    before = snapshot(args.database)
    print("player progress currently held:")
    for spec in SNAPSHOTS:
        print(f"  {spec['table']:26s} {len(before[spec['table']]):5d} row(s)")

    if not args.apply:
        print("\nNothing written. Re-run with --apply to load content and carry this across.")
        print("Take a database backup first — this tool is careful, not magic.")
        return 0

    print("\napplying content.sql ...")
    apply_content(args.database)

    print("restoring progress by key ...")
    report = restore(args.database, before)

    print("\nresult:")
    lost = 0
    for table, (wanted, after) in report.items():
        flag = "" if after >= wanted else f"   <-- {wanted - after} NOT RESTORED"
        if after < wanted:
            lost += wanted - after
        print(f"  {table:26s} {wanted:5d} snapshotted, {after:5d} present{flag}")

    if lost:
        print(f"\n{lost} row(s) could not be restored. That means the content they "
              f"pointed at is no longer in content/ — a retired quest, npc or item. "
              f"Check that this was intended.")
    else:
        print("\nAll player progress carried across intact.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
