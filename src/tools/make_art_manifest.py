#!/usr/bin/env python3
"""
Record the CSS size of every asset the client draws at its intrinsic size.

Most art is laid out explicitly and so does not care how many pixels the file
has: ground tiles are pinned by `background-size: var(--tile)`, animated decor
and combat clips are sized inline from their own `index.json`, and every face,
bust and battler sits in a fixed CSS box. Those all absorb a resolution change
for free.

Props, still decor and buildings are the exception. They render as
`<img class="map-prop-sprite">` with no width or height and `max-width: none`,
so the file's own pixel size *is* the layout. Ship 2x art without telling the
client and every barrel, door and house doubles in size on the map.

So this records what the browser should lay each one out at -- which is simply
its size before upscaling -- and `game.js` reads it back to set explicit
dimensions. Same idea as `anim/index.json`, and for the same reason: the
renderer cannot ask a file what size it ought to be.

    python3 tools/make_art_manifest.py           # write assets/images/art.json
    python3 tools/make_art_manifest.py --check    # verify, write nothing

IMPORTANT: run this while the art is still at 1x, i.e. *before* moving upscaled
files in. Running it afterwards would record the upscaled size as the CSS size
and the change would cancel itself out. `--check` catches exactly that: it
fails if a file is neither 1x nor art_scale x its recorded size.
"""

from __future__ import annotations

import argparse
import json
import os
import sys

try:
    from PIL import Image
except ImportError:
    sys.exit("needs Pillow: pip install pillow")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ART = os.path.join(ROOT, "assets", "images")
MANIFEST = os.path.join(ART, "art.json")

# Only what is drawn at intrinsic size. Adding a directory here without also
# giving the client a reason to read it does nothing.
DIRS = ("tiles", "decor", "buildings")

ART_SCALE = 2

COMMENT = (
    "CSS pixel sizes for art drawn at intrinsic size. The files on disk are "
    "art_scale times these; the client divides by laying them out at the size "
    "recorded here. Regenerate with tools/make_art_manifest.py."
)


def measure() -> dict[str, list[int]]:
    sizes: dict[str, list[int]] = {}
    for d in DIRS:
        path = os.path.join(ART, d)
        if not os.path.isdir(path):
            continue
        for name in sorted(os.listdir(path)):
            if not name.endswith(".png"):
                continue
            rel = f"{d}/{name}"
            with Image.open(os.path.join(ART, rel)) as im:
                sizes[rel] = list(im.size)
    return sizes


def check() -> int:
    if not os.path.isfile(MANIFEST):
        print(f"no manifest at {MANIFEST}")
        return 1
    man = json.load(open(MANIFEST))
    scale = man.get("art_scale", 1)
    recorded = man.get("sizes", {})
    on_disk = measure()

    problems = []
    for rel, (w, h) in recorded.items():
        if rel not in on_disk:
            problems.append(f"  {rel}: in the manifest but not on disk")
            continue
        dw, dh = on_disk[rel]
        if (dw, dh) not in ((w, h), (w * scale, h * scale)):
            problems.append(
                f"  {rel}: on disk {dw}x{dh}, expected {w}x{h} or "
                f"{w * scale}x{h * scale}"
            )
    for rel in on_disk.keys() - recorded.keys():
        problems.append(f"  {rel}: on disk but not in the manifest")

    at_scale = sum(
        1 for rel, (w, h) in recorded.items()
        if rel in on_disk and tuple(on_disk[rel]) == (w * scale, h * scale)
    )
    print(f"{len(recorded)} entries; {at_scale} are at {scale}x, "
          f"{len(recorded) - at_scale - len(problems)} still 1x")
    if problems:
        print(f"\n{len(problems)} problem(s):")
        print("\n".join(problems[:20]))
        return 1
    print("art.json agrees with what is on disk")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true",
                    help="verify the manifest against the files; write nothing")
    args = ap.parse_args()

    if args.check:
        return check()

    sizes = measure()
    with open(MANIFEST, "w") as f:
        json.dump({"_comment": COMMENT, "art_scale": ART_SCALE, "sizes": sizes},
                  f, indent=1, sort_keys=True)
        f.write("\n")
    print(f"wrote {os.path.relpath(MANIFEST, ROOT)} with {len(sizes)} entries")
    return 0


if __name__ == "__main__":
    sys.exit(main())
