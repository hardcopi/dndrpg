#!/usr/bin/env python3
"""Refuse a content load that would delete content that only exists live.

content.sql retires content by key: `DELETE FROM npcs WHERE npc_key NOT IN
(...every key in the files...)`. The database is the source of truth once
somebody uses /content.php (CLAUDE.md, "The database is the source of
truth"), so an NPC, quest or place made in the live editor and never exported
to src/content/ would be deleted by the next load. This runs each of those
DELETEs as a SELECT against the target first and stops if any row would go.

    content-drift.py <mysql client command...>     e.g. .github/scripts/hermes-mysql

It cannot see an EDIT made live to a row the files also have (the load
overwrites those); export before editing live, or the edit is lost.
"""
import re, subprocess, sys
from pathlib import Path

sql = (Path(__file__).resolve().parents[2] / "src/sql/content.sql").read_text(encoding="utf-8")
client = sys.argv[1:] or ["mysql"]
checks = []
for m in re.finditer(r"^DELETE FROM (\w+) WHERE (.+?);$", sql, re.M):
    table, where = m.group(1), m.group(2)
    if " NOT IN " not in where or where.startswith("quest_id ="):
        continue
    key = re.search(r"(\w+) NOT IN ", where).group(1)
    checks.append((table, key, where))

if not checks:
    sys.exit("content-drift: found no retire statements in content.sql (format changed?)")

script = "\n".join(
    f"SELECT '{t}', {k} FROM {t} WHERE {w};" for t, k, w in checks) + "\n"
proc = subprocess.run(client + ["-N", "-B", "rpg_5e"], input=script,
                      capture_output=True, text=True)
if proc.returncode != 0:
    sys.exit(f"content-drift: query failed: {proc.stderr.strip()}")
rows = [l.split("\t") for l in proc.stdout.splitlines() if l.strip()]
print(f"content-drift: checked {len(checks)} tables for live-only content")
if rows:
    print("::error title=Live-only content::the load would delete content that exists only in the live database")
    for t, k in rows:
        print(f"  {t}: {k}")
    print("Export it first (/content.php -> Export to files, or tools/export_content.php),")
    print("commit the JSON, and deploy again. Nothing was loaded.")
    sys.exit(1)
print("content-drift: none; every live key is in the files")
