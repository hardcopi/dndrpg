#!/usr/bin/env python3
"""
Pull a handful of Too Many Tokens variants for each of our monsters.

The pack is 16k faces / 590 MB. We only fight ~36 kinds of thing, and a
fight never needs more than a dozen faces of one of them, so this keeps
twelve evenly-spaced pictures per key and writes assets/images/tokens/.

Source: https://github.com/IsThisMyRealName/too-many-tokens-dnd
Licence: the pack's own README — Bing Image Creator, "license free".
"""

from __future__ import annotations

import json
import os
import ssl
import sys
import time
import urllib.request
from pathlib import Path

# tools/ -> src/
ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "images" / "tokens"
TREE_URL = (
    "https://api.github.com/repos/IsThisMyRealName/too-many-tokens-dnd"
    "/git/trees/main?recursive=1"
)
RAW = "https://raw.githubusercontent.com/IsThisMyRealName/too-many-tokens-dnd/main/"
PER = 12

# Duplicated from TokenArt::PACK so the importer can run without PHP.
PACK = {
    "bandit": "Bandit",
    "thief_captain": "Bandit Captain",
    "bugbear": "Bugbear",
    "carrion_crawler": "Carrion Crawler",
    "bog_crawler": "Carrion Crawler",
    "pale_crawler": "Carrion Crawler",
    "city_guard": "Guard",
    "concern_warden": "Guard",
    "warden_serjeant": "Guard",
    "city_engineer": "Commoner",
    "dire_wolf": "Dire Wolf",
    "giant_centipede": "Giant Centipede",
    "giant_rat": "Giant Rat",
    "giant_spider": "Giant Spider",
    "broodmother_spider": "Giant Spider",
    "giant_toad": "Giant Toad",
    "gnoll": "Gnoll",
    "goblin": "Goblin",
    "grey_ooze": "Gray Ooze",
    "peat_ooze": "Ochre Jelly",
    "crust": "Black Pudding",
    "kobold": "Kobold",
    "ogre": "Ogre",
    "stirge": "Stirge",
    "wererat": "Wererat",
    "wight": "Wight",
    "wolf": "Wolf",
    "worg": "Worg",
    "drowned_man": "Zombie",
    "drowned_clerk": "Ghoul",
    "fen_horror": "Shadow",
    "fen_howler": "Death Dog",
    "pit_champion": "Gladiator",
    "the_growth": "Shambling Mound",
    "deep_gremlin": "Nothic",
    "sump_gremlin": "Troglodyte",
}


def fetch(url: str) -> bytes:
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "rpg-token-import"})
    with urllib.request.urlopen(req, context=ctx, timeout=60) as r:
        return r.read()


def pick(paths: list[str], n: int) -> list[str]:
    if not paths:
        return []
    if len(paths) <= n:
        return paths
    # Evenly spaced so we do not take twelve Forest-Melee-1..12 and skip
    # the rest of the creature.
    return [paths[round(i * (len(paths) - 1) / (n - 1))] for i in range(n)]


def main() -> int:
    print("listing pack…", flush=True)
    tree = json.loads(fetch(TREE_URL))
    by_folder: dict[str, list[str]] = {}
    for t in tree.get("tree", []):
        if t.get("type") != "blob":
            continue
        p = t["path"]
        if not p.endswith(".webp"):
            continue
        folder, _, _ = p.partition("/")
        by_folder.setdefault(folder, []).append(p)
    for folder in by_folder:
        by_folder[folder].sort()

    index: dict[str, list[str]] = {}
    # Several of our keys share a pack folder (three crawlers, three guards).
    # Download each folder once, then copy into each key's directory.
    cache: dict[str, list[Path]] = {}

    for key, folder in PACK.items():
        dest = OUT / key
        dest.mkdir(parents=True, exist_ok=True)
        if folder not in cache:
            chosen = pick(by_folder.get(folder, []), PER)
            saved: list[Path] = []
            for i, rel in enumerate(chosen):
                local = dest / f"{i:02d}.webp"
                # First key to claim the folder writes into its own dest;
                # later keys copy from these files.
                url = RAW + urllib.request.quote(rel, safe="/")
                print(f"  {folder}: {rel}", flush=True)
                try:
                    data = fetch(url)
                except Exception as e:
                    print(f"    FAIL {e}", file=sys.stderr)
                    continue
                if len(data) < 1000:
                    print(f"    tiny ({len(data)} B), skip", file=sys.stderr)
                    continue
                local.write_bytes(data)
                saved.append(local)
                time.sleep(0.05)
            cache[folder] = saved
        else:
            for src in cache[folder]:
                (dest / src.name).write_bytes(src.read_bytes())
        faces = sorted(p.name for p in dest.glob("*.webp"))
        index[key] = [f"tokens/{key}/{name}" for name in faces]
        print(f"{key}: {len(faces)} from {folder}", flush=True)

    (OUT / "index.json").write_text(json.dumps(index, indent=2, sort_keys=True) + "\n")
    (OUT / "README.md").write_text(
        "Circular monster tokens from Too Many Tokens (DnD),\n"
        "https://github.com/IsThisMyRealName/too-many-tokens-dnd\n"
        "Bing Image Creator / DALL·E, license-free per that repo's README.\n"
        "Regenerate with `python3 tools/import_tmt_tokens.py`.\n"
    )
    print("wrote", OUT / "index.json")
    return 0


if __name__ == "__main__":
    sys.exit(main())
