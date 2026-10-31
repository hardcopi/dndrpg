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
    python3 tools/apply_content_safely.py --restore-from backups/<db>-<stamp>-progress.json

All or nothing
--------------
The first version of this tool held the only copy of the snapshot in a Python
dict, piped content.sql through one mysql process, and then restored row by row
through hundreds more. content.sql opens with DELETEs and is not a transaction,
so a load that failed half-way had already committed the deletes — and the
snapshot that could have put them back died with the process.

So, on --apply, before anything is written:

  * the snapshot goes to disk as JSON (`--backup-dir`, default `backups/` at the
    repo root: outside src/, so deploy.sh can never publish somebody's save,
    and gitignored). `--restore-from` puts one back on its own.
  * a full mysqldump of the target database is taken beside it, through
    `--mysqldump`, which mirrors `--mysql`. Opt out with `--no-mysqldump` only.

Either failing stops the run with nothing written. Then content.sql, the restore
and the re-links go down ONE mysql session as one transaction, and the client
stops at the first error. A failure anywhere — a bad statement in content.sql, a
restore that will not insert, the connection dropping — ends the session with
the transaction open, and MySQL rolls the lot back. Players are exactly where
they were.

That only holds while content.sql is pure DML on InnoDB: DDL, TRUNCATE, LOCK
TABLES and friends commit implicitly, and a MyISAM table cannot roll back. Both
are checked before the backup is taken rather than assumed, because content.sql
is generated and load_content.py could start emitting something else tomorrow.

Against hermes, where `--mysql` is `~/rivermark/bin/hermes-mysql` (i.e.
`ssh root@hermes mysql`), the dump wants the same transport:

    python3 tools/apply_content_safely.py --apply \\
        --mysql ~/rivermark/bin/hermes-mysql \\
        --mysqldump "ssh -o BatchMode=yes root@hermes.five-star.com mysqldump"

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
import datetime
import gzip
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CONTENT_SQL = ROOT / "sql" / "content.sql"
BACKUP_DIR = ROOT.parent / "backups"

# --content-sql loads a file other than the real one. It exists so
# test_apply_content_safely.sh can hand over a copy with a broken statement
# half-way down and prove the rollback; pointed at a stale file on a live
# database it would quietly revert the world, so it is hidden from --help and
# refused unless this is set in the environment.
TEST_ENV = "APPLY_CONTENT_SAFELY_TEST"

COMPOSE_DIR = ROOT.parent
DB_SERVICE = "db"
DB_USER = "web"
DB_PASS = "devpassword"

# How to reach a mysql client. The default is this project's compose stack,
# which is where content is normally loaded. Production is not that: hermes
# runs mysql natively and authenticates root over the socket, so there is no
# `docker compose` to exec into and no password to pass. `--mysql` names the
# client to run instead, and everything below goes through client_cmd() so the
# two call sites cannot drift apart — one of them applies content.sql itself,
# and a prefix that was right for the snapshot and wrong for the load would
# take the snapshot from one database and rebuild another.
CLIENT: list[str] | None = None
DUMP_CLIENT: list[str] | None = None


def client_cmd() -> tuple[list[str], str | None]:
    """The mysql invocation and the directory to run it in."""
    if CLIENT is not None:
        return list(CLIENT) + ["--default-character-set=utf8mb4"], None
    return ([
        "docker", "compose", "exec", "-T", DB_SERVICE,
        "mysql", f"-u{DB_USER}", f"-p{DB_PASS}",
        "--default-character-set=utf8mb4",
    ], str(COMPOSE_DIR))


def dump_cmd() -> tuple[list[str], str | None]:
    """The mysqldump invocation, defaulted the same way as client_cmd()."""
    if DUMP_CLIENT is not None:
        return list(DUMP_CLIENT), None
    return ([
        "docker", "compose", "exec", "-T", DB_SERVICE,
        "mysqldump", f"-u{DB_USER}", f"-p{DB_PASS}",
    ], str(COMPOSE_DIR))


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


def _stderr(proc_stderr: str) -> str:
    return "\n".join(l for l in proc_stderr.splitlines()
                     if "Using a password" not in l)


def mysql(database: str, sql: str, batch: bool = True) -> str:
    """One statement (or several) against one database, named explicitly."""
    cmd, cwd = client_cmd()
    if batch:
        cmd += ["-N", "-B"]
    cmd += [database, "-e", sql]
    proc = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(f"mysql failed:\n{_stderr(proc.stderr)}")
    return proc.stdout


def quote(value: str | None) -> str:
    if value is None or value == "\\N":
        return "NULL"
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


# mysql -B escapes a field's backslash, tab, newline and NUL, and writes NULL
# as \N. Decoded here so the snapshot on disk holds the values themselves —
# quote() then escapes them once, rather than a second time over the client's
# escaping, which turned a stored newline into a literal backslash-n.
_BATCH_ESC = {"\\": "\\", "t": "\t", "n": "\n", "0": "\0"}


def unescape(field: str) -> str | None:
    if field == "\\N":
        return None
    return re.sub(r"\\(.)", lambda m: _BATCH_ESC.get(m.group(1), m.group(0)), field)


def snapshot(database: str) -> dict[str, list[list[str | None]]]:
    out = {}
    for spec in SNAPSHOTS:
        raw = mysql(database, " ".join(spec["select"].split()))
        rows = [[unescape(f) for f in line.split("\t")]
                for line in raw.splitlines() if line.strip()]
        out[spec["table"]] = rows
    return out


def restore_sql(snap: dict[str, list[list[str | None]]]) -> list[str]:
    """
    The statements that put a snapshot back, and a count after each table.

    Returned rather than run so the caller can put them inside the same
    transaction as the load: a restore that fails must take the load down with
    it, or the database is left holding new content and half a party.
    """
    out = []
    for spec in SNAPSHOTS:
        table, rows = spec["table"], snap[spec["table"]]
        for row in rows:
            params = {}
            for col, val in zip(spec["cols"], row):
                if val is None or (col in spec["nullable"] and val == ""):
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
            out.append(" ".join(sql.split()))
        out.append(f"SELECT '@@restored', '{table}', {len(rows)}, COUNT(*) FROM {table}")
    return out


# --- Is content.sql safe to wrap in a transaction? ------------------------
#
# A statement that commits implicitly (any DDL, TRUNCATE, LOCK TABLES, SET
# autocommit = 1, ...) would end the transaction half-way and leave everything
# before it committed — the exact failure this tool exists to prevent, arriving
# silently. So the check is an allow-list, not a deny-list: a statement whose
# verb is not one of these stops the apply before anything is backed up or
# written, and the remedy is to look at what load_content.py has started
# emitting, not to extend the list without thinking.
TXN_SAFE = {"INSERT", "UPDATE", "DELETE", "REPLACE", "SELECT", "SET"}

_TOKENS = re.compile(
    r"'(?:[^'\\]|\\.|'')*'"          # 'string', with \' and '' escapes
    r'|"(?:[^"\\]|\\.|"")*"'         # "string"
    r"|`[^`]*`"                      # `identifier`
    r"|--[^\n]*|#[^\n]*"             # line comments
    r"|/\*.*?\*/"                    # block comments (and /*! hints — see below)
    r"|;",
    re.S,
)
_LEADING_NOISE = re.compile(r"^(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|/\*(?!!).*?\*/)*", re.S)


def split_statements(sql: str) -> list[str]:
    """Split on the semicolons that are not inside a string, name or comment."""
    out, start = [], 0
    for m in _TOKENS.finditer(sql):
        if m.group(0) == ";":
            out.append(sql[start:m.start()])
            start = m.end()
    out.append(sql[start:])
    return [s for s in out if _LEADING_NOISE.sub("", s).strip()]


def verb(stmt: str) -> str:
    body = _LEADING_NOISE.sub("", stmt)
    m = re.match(r"[A-Za-z_]+", body)
    return m.group(0).upper() if m else body[:20]


def prepare_content(path: Path) -> tuple[str, list[str]]:
    """
    content.sql made ready to run inside a transaction, and anything in it that
    would stop that being true.

    The `USE` statement is dropped rather than trusted: it hardcodes `rpg_5e`,
    so a file piped at any other database silently lands on the live one
    instead. Dropped by STATEMENT, not by line — a line-based strip would also
    eat any line of a multi-line string that happened to begin "use ".
    """
    kept, problems = [], []
    for stmt in split_statements(path.read_text(encoding="utf-8")):
        v = verb(stmt)
        if v == "USE":
            continue
        brief = " ".join(stmt.split())[:100]
        if "/*!" in stmt:
            # An executable comment runs whatever is inside it, which the verb
            # check cannot see.
            problems.append(f"executable /*! comment: {brief}")
        elif v not in TXN_SAFE:
            problems.append(f"{v}: {brief}")
        elif v == "SET" and re.search(r"\b(autocommit|transaction)\b", stmt, re.I):
            problems.append(f"SET that ends or reshapes the transaction: {brief}")
        kept.append(stmt.strip())
    return ";\n".join(kept) + ";\n", problems


def non_innodb_tables(database: str) -> list[str]:
    """Tables a ROLLBACK would not reach."""
    raw = mysql(database,
                "SELECT CONCAT(TABLE_NAME, ' (', COALESCE(ENGINE, '?'), ')') "
                "FROM information_schema.TABLES "
                f"WHERE TABLE_SCHEMA = {quote(database)} AND TABLE_TYPE = 'BASE TABLE' "
                "AND COALESCE(ENGINE, '') <> 'InnoDB'")
    return [l for l in raw.splitlines() if l.strip()]


# --- Backups ----------------------------------------------------------------

SNAPSHOT_FORMAT = "apply_content_safely/1"


def write_snapshot(path: Path, database: str, snap: dict) -> None:
    """
    The snapshot as JSON, written to a temporary name, fsynced, renamed, and
    read back. A file that is not provably complete is not a backup; the caller
    treats any exception here as "write nothing".
    """
    doc = {
        "format": SNAPSHOT_FORMAT,
        "database": database,
        "taken_at": datetime.datetime.now().isoformat(timespec="seconds"),
        "tables": {spec["table"]: {"cols": spec["cols"], "rows": snap[spec["table"]]}
                   for spec in SNAPSHOTS},
    }
    tmp = path.with_name(path.name + ".partial")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=1)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)
    # Every value, not just the row counts: a file with the right number of
    # rows and a mangled key in one of them restores the wrong thing, quietly.
    if json.loads(path.read_text(encoding="utf-8")) != doc:
        raise RuntimeError(f"{path}: did not read back identical to what was written")
    load_snapshot(path)


def load_snapshot(path: Path) -> dict[str, list[list[str | None]]]:
    doc = json.loads(path.read_text(encoding="utf-8"))
    if doc.get("format") != SNAPSHOT_FORMAT:
        raise RuntimeError(f"{path}: not a snapshot written by this tool")
    out = {}
    for spec in SNAPSHOTS:
        t = doc["tables"].get(spec["table"])
        if t is None or t["cols"] != spec["cols"]:
            raise RuntimeError(f"{path}: {spec['table']} is missing or has other columns")
        out[spec["table"]] = t["rows"]
    return out


def write_dump(path: Path, database: str) -> None:
    """
    A full mysqldump of the target, gzipped. --single-transaction so the game
    can keep running while it is taken; --no-tablespaces because it needs a
    privilege (PROCESS) the app's user does not have and nothing here wants.
    Complete means mysqldump exited 0 AND wrote its closing comment — a dump cut
    off by a dropped ssh connection can exit 0 from the far side's point of view
    and still be half a database.
    """
    cmd, cwd = dump_cmd()
    cmd += ["--single-transaction", "--routines", "--triggers", "--no-tablespaces",
            "--default-character-set=utf8mb4", database]
    tmp = path.with_name(path.name + ".partial")
    tail = b""
    with gzip.open(tmp, "wb") as gz:
        proc = subprocess.Popen(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        assert proc.stdout is not None and proc.stderr is not None
        for chunk in iter(lambda: proc.stdout.read(1 << 20), b""):
            gz.write(chunk)
            tail = (tail + chunk)[-512:]
        err = proc.stderr.read().decode("utf-8", "replace")
        rc = proc.wait()
    if rc != 0:
        tmp.unlink(missing_ok=True)
        raise RuntimeError(f"mysqldump exited {rc}:\n{_stderr(err)}")
    if b"-- Dump completed" not in tail:
        tmp.unlink(missing_ok=True)
        raise RuntimeError("mysqldump output has no '-- Dump completed' trailer; "
                           "treating it as truncated")
    with open(tmp, "rb") as fh:
        os.fsync(fh.fileno())
    os.replace(tmp, path)


# Columns that a content load NULLS rather than deletes, and the key that can
# put them back. The snapshot machinery above cannot help here: it restores rows
# that went missing, and these rows never go missing — a foreign key declared
# ON DELETE SET NULL quietly empties a column and leaves the row sitting there
# looking fine.
#
# `dungeon_delves.mouth_location_id` is the one that mattered. It records where
# a party currently underground went in, and every content load erased it under
# them; DelveEngine::mouthOf() then fell back to a literal `uv_mouth` and
# surfaced free-play parties in the Undervault. The engine now trusts the key
# first, so this is repair rather than rescue — but a foreign key that is
# allowed to stay broken is one nobody can trust to mean anything.
RELINKS = [
    {
        # The floor the party is standing on, nulled by the same rule and with
        # a worse consequence: descend() reads region_id to know what to DROP
        # behind it, so a nulled one leaks the whole floor — twenty locations,
        # its encounters and its generated quest — and nothing ever collects
        # them. Recoverable without a stored key because a generated region's
        # key is deterministic; see DelveEngine::regionKey().
        "what": "dungeon_delves.region_id",
        "sql": """
            UPDATE dungeon_delves d
              INNER JOIN regions r ON r.region_key = CONCAT('_dg_', d.party_id, '_', d.depth)
                SET d.region_id = r.id
              WHERE d.region_id IS NULL
        """,
        "count": """
            SELECT COUNT(*) FROM dungeon_delves d
             WHERE d.region_id IS NULL
               AND EXISTS (SELECT 1 FROM regions r
                            WHERE r.region_key = CONCAT('_dg_', d.party_id, '_', d.depth))
        """,
    },
    {
        "what": "dungeon_delves.mouth_location_id",
        "sql": """
            UPDATE dungeon_delves d
              INNER JOIN locations l ON l.location_key = d.mouth_location_key
                SET d.mouth_location_id = l.id
              WHERE d.mouth_location_id IS NULL AND d.mouth_location_key IS NOT NULL
        """,
        "count": """
            SELECT COUNT(*) FROM dungeon_delves
             WHERE mouth_location_id IS NULL AND mouth_location_key IS NOT NULL
        """,
    },
]


def relink_sql() -> list[str]:
    """Put back the ids a content load nulled, resolving them through their keys."""
    out = []
    for spec in RELINKS:
        count = " ".join(spec["count"].split())
        out.append(f"SELECT '@@relink_before', '{spec['what']}', ({count})")
        out.append(" ".join(spec["sql"].split()))
        out.append(f"SELECT '@@relink_after', '{spec['what']}', ({count})")
    return out


def run_transaction(database: str, body: list[str]) -> tuple[bool, list[list[str]], str]:
    """
    Run statements as ONE transaction in ONE mysql session.

    Returns (committed, marker rows, stderr). The client is told --skip-force so
    that a `force` line in somebody's my.cnf cannot make it carry on past an
    error and reach the COMMIT; without --force the batch client stops at the
    first failing statement and disconnects, and the server rolls the open
    transaction back. `@@committed` is selected only after COMMIT returns, so
    its presence is the evidence, not the exit code alone.
    """
    script = "\n".join([
        "SET NAMES utf8mb4;",
        "START TRANSACTION;",
        *(s if s.rstrip().endswith(";") else s + ";" for s in body),
        "SET FOREIGN_KEY_CHECKS = 1;",
        "COMMIT;",
        "SELECT '@@committed';",
    ]) + "\n"
    cmd, cwd = client_cmd()
    cmd += ["--skip-force", "-N", "-B", database]
    proc = subprocess.run(cmd, cwd=cwd, input=script, capture_output=True, text=True)
    markers = [line.split("\t") for line in proc.stdout.splitlines()
               if line.startswith("@@")]
    committed = proc.returncode == 0 and ["@@committed"] in markers
    return committed, markers, _stderr(proc.stderr)


def recovery_steps(args: argparse.Namespace, snap_path: Path | None,
                   dump_path: Path | None) -> str:
    me = f"python3 {Path(__file__).resolve()} --database {shlex.quote(args.database)}"
    if args.mysql:
        me += f" --mysql {shlex.quote(args.mysql)}"
    if CLIENT is not None:
        client = " ".join(shlex.quote(c) for c in CLIENT)
        where = ""
    else:
        client = f"docker compose exec -T {DB_SERVICE} mysql -u{DB_USER} -p{DB_PASS}"
        where = f"   (from {COMPOSE_DIR})"
    lines = [
        "Recovery:",
        f"  1. See where things stand (read-only):\n       {me} --check",
    ]
    if snap_path:
        lines.append("  2. If player progress is missing, put the snapshot back "
                     "(inserts only what is absent; safe to repeat):\n"
                     f"       {me} --restore-from {shlex.quote(str(snap_path))}")
    if dump_path:
        lines.append("  3. Last resort — the whole database as it was before this run "
                     "(loses anything players did since):\n"
                     f"       gunzip -c {shlex.quote(str(dump_path))} | {client} "
                     f"--default-character-set=utf8mb4 {shlex.quote(args.database)}{where}")
    return "\n".join(lines)


def report_restore(markers: list[list[str]]) -> int:
    lost = 0
    for m in markers:
        if m[0] != "@@restored":
            continue
        table, wanted, after = m[1], int(m[2]), int(m[3])
        flag = "" if after >= wanted else f"   <-- {wanted - after} NOT RESTORED"
        lost += max(0, wanted - after)
        print(f"  {table:26s} {wanted:5d} snapshotted, {after:5d} present{flag}")
    return lost


def report_relinks(markers: list[list[str]]) -> None:
    before = {m[1]: int(m[2]) for m in markers if m[0] == "@@relink_before"}
    after = {m[1]: int(m[2]) for m in markers if m[0] == "@@relink_after"}
    notes = [f"  {what:34s} {n - after.get(what, 0):3d} re-linked by key"
             + ("" if after.get(what, 0) == 0 else f", {after[what]} still unresolved")
             for what, n in before.items() if n]
    if notes:
        print("re-linked ids the load nulled:")
        print("\n".join(notes))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--database", default="rpg_5e",
                    help="target database (default: rpg_5e)")
    ap.add_argument("--apply", action="store_true",
                    help="actually do it; without this nothing is written")
    ap.add_argument("--check", action="store_true",
                    help="report what is at risk and exit")
    ap.add_argument("--restore-from", metavar="SNAPSHOT.json", type=Path,
                    help="put a snapshot written by --apply back, on its own, and exit")
    ap.add_argument("--force-database", action="store_true",
                    help="with --restore-from: allow a snapshot taken from another "
                         "database than --database")
    ap.add_argument("--mysql", default=None,
                    help="mysql client to run, as a shell-style command "
                         "(e.g. --mysql mysql). Default: this project's "
                         "docker compose stack. Use it where there is none.")
    ap.add_argument("--mysqldump", default=None,
                    help="mysqldump to run for the pre-apply backup, shell-style; "
                         "mirrors --mysql (e.g. \"ssh root@hermes mysqldump\"). "
                         "Default: this project's docker compose stack.")
    ap.add_argument("--no-mysqldump", action="store_true",
                    help="skip the full database dump (the JSON snapshot is still "
                         "written). An explicit opt-out; there is no default that skips it.")
    ap.add_argument("--backup-dir", type=Path, default=BACKUP_DIR,
                    help=f"where backups go (default: {BACKUP_DIR})")
    ap.add_argument("--content-sql", type=Path, default=None, help=argparse.SUPPRESS)
    args = ap.parse_args()
    # Piped into a log, stdout is block-buffered and the FAILED on stderr would
    # print above the report it concludes.
    sys.stdout.reconfigure(line_buffering=True)

    global CLIENT, DUMP_CLIENT
    if args.mysql:
        CLIENT = shlex.split(args.mysql)
    if args.mysqldump:
        DUMP_CLIENT = shlex.split(args.mysqldump)

    content_sql = CONTENT_SQL
    if args.content_sql is not None:
        if os.environ.get(TEST_ENV) != "1":
            print(f"--content-sql is for the test harness and needs {TEST_ENV}=1.",
                  file=sys.stderr)
            return 2
        content_sql = args.content_sql

    print(f"target database: {args.database}\n")

    if args.restore_from:
        return restore_only(args)

    if not content_sql.exists():
        print(f"No {content_sql}. Run tools/load_content.py first.", file=sys.stderr)
        return 1

    before = snapshot(args.database)
    print("player progress currently held:")
    for spec in SNAPSHOTS:
        print(f"  {spec['table']:26s} {len(before[spec['table']]):5d} row(s)")

    content, problems = prepare_content(content_sql)
    soft = non_innodb_tables(args.database)
    if problems or soft:
        print(f"\n{content_sql.name} cannot be applied as one transaction:", file=sys.stderr)
        for p in problems[:20]:
            print(f"  implicit commit risk: {p}", file=sys.stderr)
        for t in soft:
            print(f"  not InnoDB, so a rollback would not reach it: {t}", file=sys.stderr)
        print("Nothing written. Fix the generator or the table before loading.", file=sys.stderr)
        return 1

    if not args.apply:
        print(f"\n{content_sql.name} is pure DML on InnoDB: --apply will run it as one transaction.")
        print("Nothing written. Re-run with --apply to load content and carry this across.")
        return 0

    # Backups, before a single write. Any failure here ends the run.
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    snap_path = args.backup_dir / f"{args.database}-{stamp}-progress.json"
    dump_path = None if args.no_mysqldump else args.backup_dir / f"{args.database}-{stamp}.sql.gz"
    try:
        args.backup_dir.mkdir(parents=True, exist_ok=True)
        write_snapshot(snap_path, args.database, before)
        print(f"\nsnapshot written: {snap_path}")
        if dump_path:
            print("dumping the database ...")
            write_dump(dump_path, args.database)
            print(f"dump written:     {dump_path} ({dump_path.stat().st_size:,} bytes)")
        else:
            print("mysqldump skipped (--no-mysqldump)")
    except Exception as exc:  # noqa: BLE001 — every failure means the same thing here
        print(f"\nBACKUP FAILED, nothing written to {args.database}:\n  {exc}", file=sys.stderr)
        return 1

    print(f"\napplying {content_sql.name}, restoring progress and re-linking, "
          "as one transaction ...")
    committed, markers, err = run_transaction(
        args.database, [content] + restore_sql(before) + relink_sql())

    if not committed:
        print("\nFAILED — the transaction was not committed.", file=sys.stderr)
        if err:
            print(err, file=sys.stderr)
        print("\nmysql stopped at the first error and the server rolled back the open "
              "transaction,\nso the content, the restore and the re-links should all "
              "be exactly as before this run.\nIf the connection dropped after COMMIT "
              "was sent, it may instead have committed — check.\n", file=sys.stderr)
        print(recovery_steps(args, snap_path, dump_path), file=sys.stderr)
        return 1

    report_relinks(markers)
    print("\nresult (committed):")
    lost = report_restore(markers)
    if lost:
        print(f"\n{lost} row(s) could not be restored. That means the content they "
              f"pointed at is no longer in content/ — a retired quest, npc or item. "
              f"Check that this was intended.")
    else:
        print("\nAll player progress carried across intact.")
    print(f"backups kept: {snap_path}" + (f", {dump_path}" if dump_path else ""))
    return 0


def restore_only(args: argparse.Namespace) -> int:
    """--restore-from: the snapshot's rows, put back in one transaction."""
    try:
        snap = load_snapshot(args.restore_from)
    except Exception as exc:  # noqa: BLE001
        print(f"Cannot read {args.restore_from}: {exc}", file=sys.stderr)
        return 1
    taken = json.loads(args.restore_from.read_text(encoding="utf-8")).get("database")
    if taken != args.database:
        if not args.force_database:
            print(f"Refusing: snapshot was taken from {taken!r}, not {args.database!r}.\n"
                  f"Pass --force-database to restore it into {args.database!r} anyway.",
                  file=sys.stderr)
            return 1
        print(f"note: snapshot was taken from {taken!r}, restoring into "
              f"{args.database!r} (--force-database)")
    print(f"restoring {args.restore_from} (only rows that are absent) ...")
    committed, markers, err = run_transaction(args.database, restore_sql(snap))
    if not committed:
        print(f"\nFAILED — nothing committed.\n{err}", file=sys.stderr)
        return 1
    print("\nresult (committed):")
    lost = report_restore(markers)
    if lost:
        print(f"\n{lost} row(s) point at content that is not in this database.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
