#!/usr/bin/env python3
"""
Rebuild busts.json from the art that is actually on disk.

Why this exists
---------------
`busts.json` says how many expression busts were cut for each sprite key, and
two different things read it:

  * `load_content.py` writes `npcs.bust_count` / `companions.bust_count` from
    it, and the engine clamps a variant's `expression` to that column.
  * `DialogueLint` bounds `expression` by it directly, so a number above the
    count is a save-refusing error.

It is normally written by `tools/slice_assets.py` as a by-product of cutting the
art. That is the right generator and this is not a replacement for it — but it
only runs when the source art packs are present, and the manifest can drift
without them: five keys understated their busts by up to seven, `saurial` saying
1 where eight distinct images sit in the folder. When that happens the two
readers above disagree with each other and with the files, and the symptom is a
linter refusing an expression the engine would have rendered perfectly.

Counting the folder needs no art packs, so this can be run any time the manifest
is suspected. The naming rule is `DialogEngine::bust()`'s — `<key>_bust.png` is
expression 1 and `<key>_bust_N.png` is expression N — and it is restated here
because the two live in different languages; if that rule ever changes, it
changes in both.

    python3 tools/rebuild_busts.py --check     # report, write nothing
    python3 tools/rebuild_busts.py             # write

A gap is reported and never written. `_bust.png` and `_bust_3.png` with no
`_bust_2.png` is not a count of 3: it is a hole, and recording 3 would let an
author name an expression that resolves to a file the vhost answers with the
homepage.
"""

import argparse
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ART_DIRS = ("assets/images/npcs", "assets/images/monsters")

# DialogEngine::bust() — expression 1 is the bare `_bust`, N > 1 is `_bust_N`.
BUST_RE = re.compile(r"(.+?)_bust(?:_(\d+))?\.png$")


def scan(art_dir):
    """sprite key -> the set of expression numbers present in the folder."""
    found = {}
    for name in os.listdir(art_dir):
        m = BUST_RE.fullmatch(name)
        if not m:
            continue
        key = m.group(1)
        n = int(m.group(2)) if m.group(2) else 1
        found.setdefault(key, set()).add(n)
    return found


def counts_and_gaps(found):
    """
    Turn each key's expression set into a count, or a complaint.

    The count is how many expressions are usable from 1 upwards, which is only
    the highest number present when nothing is missing underneath it.
    """
    counts, gaps = {}, {}
    for key, present in sorted(found.items()):
        top = max(present)
        missing = [n for n in range(1, top + 1) if n not in present]
        if missing:
            gaps[key] = (top, missing)
        counts[key] = len(present) if not missing else top - len(missing)
        if missing:
            # Usable run from 1, so a gap costs everything above it rather than
            # just itself: the engine walks expressions by number, not by rank.
            counts[key] = min(missing) - 1
    return counts, gaps


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--check", action="store_true", help="report only; write nothing")
    args = ap.parse_args()

    changed = failed = 0
    for rel in ART_DIRS:
        art = os.path.join(ROOT, rel)
        manifest_path = os.path.join(art, "busts.json")
        if not os.path.isdir(art):
            continue

        counts, gaps = counts_and_gaps(scan(art))
        for key, (top, missing) in sorted(gaps.items()):
            print(f"  GAP    {rel}/{key}: highest is {top} but "
                  f"{', '.join(f'_bust_{n}' for n in missing)} missing — "
                  f"recording {counts[key]}")
            failed += 1

        old = {}
        if os.path.isfile(manifest_path):
            with open(manifest_path, encoding="utf-8") as fh:
                old = json.load(fh)

        for key in sorted(set(old) | set(counts)):
            was, now = old.get(key), counts.get(key)
            if was == now:
                continue
            changed += 1
            if now is None:
                print(f"  DROP   {rel}/{key}: in manifest as {was}, no files on disk")
            elif was is None:
                print(f"  ADD    {rel}/{key}: {now}")
            else:
                print(f"  FIX    {rel}/{key}: {was} -> {now}")

        if args.check or old == counts:
            continue

        # Written the way slice_assets.py writes it — sorted, one key per line,
        # trailing newline — so a rebuild that changes nothing is a no-op in the
        # diff rather than a reformat.
        with open(manifest_path, "w", encoding="utf-8") as fh:
            fh.write("{\n")
            fh.write(",\n".join(f' "{k}": {counts[k]}' for k in sorted(counts)))
            fh.write("\n}\n")
        print(f"  wrote  {rel}/busts.json ({len(counts)} keys)")

    if not changed:
        print("busts.json already matches the art on disk.")
    elif args.check:
        print(f"\n{changed} key(s) would change. Re-run without --check to write.")
    else:
        print(f"\n{changed} key(s) updated.")

    if failed:
        print(f"{failed} key(s) have gaps — fix the art, then run again.", file=sys.stderr)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
