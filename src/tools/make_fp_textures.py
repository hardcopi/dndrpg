#!/usr/bin/env python3
"""
The wall, floor and side textures for the first-person view, from the Synty packs.

    python3 tools/make_fp_textures.py            # write the swatches
    python3 tools/make_fp_textures.py --check    # report, write nothing

These are the Synty textures themselves, downsampled and nothing else — we hold
full rights to them, commercial and non-commercial, so the only reason to touch
the pixels is payload. A 2048px source is a megabyte; a 256px tile of it is
thirty kilobytes and is being drawn into quads a couple of hundred pixels
across, where the difference is invisible.

HOW THE DEPTH BANDS SURVIVE A TEXTURE. `ui-firstperson.js` used to paint flat
quads whose colour came entirely from the `--fp-*` tokens, which is what lets
the whole cave be relit from the stylesheet. A texture as the fill would take
that away. So the pattern is the texture with a SCRIM over it in the band's own
token colour, and the scrim's opacity climbs with distance: a near wall is
almost all Synty and a far one is almost all `--fp-wall-5`. The tokens still
decide what the dark looks like, the texture decides what the stone looks like,
and turning the swatches off leaves the flat cave exactly as it was.

The sources live in the Unity project, which is a separate repository and is not
required to be present: with it absent this prints what it would have done and
writes nothing, the way tools/test_mapgen.php skips itself without its
container. The swatches are committed, so a clone builds the view without ever
needing the packs.
"""

from __future__ import annotations

import argparse
import os
import sys

SRC = "/home/richard/code/rpg-unity/Assets/Synty/PolygonGeneric/Textures"
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                   "assets", "images", "fp")

# texture name -> (source file, output size)
#
# TWO, NOT THREE, AND THAT IS THE POINT. The first cut gave the walls running
# away from the viewer their own texture — Generic_Rock — so the two planes
# would read apart. They read apart all right: the rock is 5% saturated and the
# brick is 43%, so a corridor's side walls came out grey while the wall ahead
# was brown, and the same masonry appeared to change material depending on
# which way you were facing.
#
# That was a category error. Orientation should change the LIGHT, not the
# stone, and it already does — `--fp-side-*` is a lighter set than `--fp-wall-*`
# and exists for exactly this. So both wall planes share one brick and the
# tokens do the separating.
#
# There is no `roof`: it is nearly black at every band, and a texture nobody can
# see is a request nobody should pay for.
SWATCHES = {
    "wall":  ("Generic_Brick.png", 256),
    "floor": ("Generic_Dirt.png",  256),
}


def derive(path: str, size: int):
    """One source texture, down to a tile the view can afford."""
    from PIL import Image

    # RGB, not RGBA: every one of these is opaque and the alpha channel on the
    # sources is a mask the pack uses for something else. Carrying it would be a
    # third of the file for nothing.
    im = Image.open(path).convert("RGB").resize((size, size), Image.LANCZOS)
    px = list(im.getdata())
    mean = sum(sum(p) / 3 for p in px) / len(px)
    return im, mean


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true",
                    help="report what would be written and write nothing")
    args = ap.parse_args()

    if not os.path.isdir(SRC):
        print(f"No {SRC} — the Unity project is not on this machine.")
        print("Nothing to do: the swatches in assets/images/fp are committed.")
        return 0

    try:
        import PIL  # noqa: F401
    except ImportError:
        print("Pillow is not installed; cannot derive the swatches.", file=sys.stderr)
        return 1

    if not args.check:
        os.makedirs(OUT, exist_ok=True)

    for key, (src, size) in SWATCHES.items():
        path = os.path.join(SRC, src)
        if not os.path.isfile(path):
            print(f"  {key:<6} MISSING {src}")
            continue
        img, mean = derive(path, size)
        dest = os.path.join(OUT, f"{key}.png")
        if args.check:
            print(f"  {key:<6} <- {src} (mean {mean:.1f}) -> {os.path.relpath(dest)}")
            continue
        img.save(dest, "PNG", optimize=True)
        print(f"  {key:<6} <- {src:<20} {size}px  {os.path.getsize(dest) // 1024} KB")

    return 0


if __name__ == "__main__":
    sys.exit(main())
