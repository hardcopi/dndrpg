#!/usr/bin/env python3
"""
Composite a modular character out of the T&C Humans paperdoll layers.

A proof that the character creator scoped in docs/CHARACTER_CREATOR.md works,
and the thing that will do the baking when it exists. Each clothing layer in the
pack ships a complete animation set at the base body's exact dimensions, so
assembling a character is a plain alpha-over stack in a fixed order — no
alignment, no scaling, no per-frame work.

    python3 tools/bake_paperdoll.py --key pc_sample \
        --sex male --body 1 --feet 1 --pants 2 --top 3 --hair 2 --facialhair 1

Writes <key>_sheet.png, <key>_face.png and <key>_bust.png into
assets/images/npcs/, which is all a sprite_key needs to exist.

The layer order is the pack's own, from `Medieval T&C/Read Me.docx`:

    Shadow (optional) / Base template / Feet* / Pants* / Gloves / Top / Hair /
    Weapon / Accessory

with two additions the list omits — facial hair sits above the base and below
hair, head above hair — and one caveat the pack itself raises: feet and pants
sometimes want reversing depending on the boot, so ORDER is data rather than
control flow.
"""

from __future__ import annotations

import argparse
import glob
import os
import sys

try:
    from PIL import Image
except ImportError:
    sys.exit("This script needs Pillow: pip install Pillow")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PACK = os.environ.get("RPG_PACK_DIR", os.path.join(os.path.dirname(ROOT), "rpg-assets-src"))
NPCS_OUT = os.path.join(ROOT, "assets", "images", "npcs")

HUMANS = "Medieval T&C/Medieval_T&C_Humans"

# Bottom to top. `feet` before `pants` is the pack's default; the Read Me notes
# some boots want to sit above the trouser leg, which is why this is a list to be
# reordered rather than a sequence of calls.
ORDER = ["body", "feet", "pants", "top", "dress", "facialhair", "hair", "head", "accessory"]

# Categories the female set has that the male does not, and vice versa.
CATEGORIES = {
    "male":   ["feet", "pants", "top", "facialhair", "hair", "head", "accessory"],
    "female": ["feet", "pants", "top", "dress", "hair", "accessory"],
}

# What the pack calls each of our category names.
PACK_NAME = {
    "feet": "Feet", "pants": "Pants", "top": "Top", "dress": "Dress",
    "hair": "Hair", "facialhair": "FacialHair", "head": "Head",
    "accessory": "Accessory",
}

# Which file in a layer folder is which output. A layer's walk sheet is the
# cardinals; the diagonals are a separate file, stitched the same way
# slice_assets.py stitches an actor's.
PARTS = {
    "walk":      "*walking.png",
    "walk_diag": "*walking_diag.png",
    "face":      "*_Face.png",
    "bust":      "*_Bust*.png",
}


def sex_dir(sex: str) -> str:
    return f"{HUMANS}/Medieval_T&C_{'Males' if sex == 'male' else 'Females'}"


def body_dir(sex: str, n: int) -> str:
    return f"{sex_dir(sex)}/Medieval_T&C_{'Male' if sex == 'male' else 'Female'}_{n}"


def layer_dir(sex: str, category: str, choice: str) -> str:
    """
    A clothing folder. `choice` is "2" or "2_Alt_3" — the Alt variants are colour
    swaps of the same silhouette, verified by identical alpha channels, so they
    are a colour picker rather than separate styles.
    """
    who = "Male" if sex == "male" else "Female"
    name = PACK_NAME[category]
    return f"{sex_dir(sex)}/Medieval_T&C_{who}_Clothing/Medieval_T&C_{who}_{name}_{choice}"


def find(folder: str, pattern: str) -> str | None:
    hits = sorted(glob.glob(os.path.join(PACK, folder, pattern)))
    return hits[0] if hits else None


def open_layer(folder: str, pattern: str, size: tuple[int, int] | None):
    """
    One layer image, or None when this piece does not contribute to this output.

    A missing file is normal rather than exceptional — not every accessory has a
    bust, and a layer that does not appear in a portrait simply is not drawn in
    it. A size mismatch is NOT normal: the whole approach rests on the layers
    being identical dimensions, so it is reported rather than resized away.
    """
    path = find(folder, pattern)
    if not path:
        return None
    im = Image.open(path).convert("RGBA")
    if size is not None and im.size != size:
        print(f"  !! {os.path.basename(path)} is {im.size}, expected {size} — skipped")
        return None
    return im


def stack(sex: str, picks: dict, part: str) -> Image.Image | None:
    """Composite one output (walk sheet, face, bust) from the chosen layers."""
    pattern = PARTS[part]

    base = open_layer(body_dir(sex, picks["body"]), pattern, None)
    if base is None:
        return None
    canvas = base.copy()

    for category in ORDER:
        if category in ("body",) or category not in picks:
            continue
        choice = picks[category]
        if choice in (None, "", "none"):
            continue
        im = open_layer(layer_dir(sex, category, str(choice)), pattern, canvas.size)
        if im is None:
            continue
        # alpha_composite, not paste: paste with a mask would punch the layer's
        # own transparency through everything already drawn underneath it.
        canvas = Image.alpha_composite(canvas, im)

    return canvas


def stitch_walk(cardinals: Image.Image, diagonals: Image.Image | None) -> Image.Image:
    """
    The 8-facing sheet the renderer expects: 4 frames wide, 8 facings tall, in
    the row order slice_assets.py established — S, W, E, N, SW, NW, SE, NE.

    The pack's sheets are 8 frames x 4 rows of 128px, and all eight are kept so
    a CSS background-position can animate the full cycle without a spritesheet
    library. Must match WALK_FRAMES in slice_assets.py and slice_paperdoll.py.
    """
    CHAR = 128
    frames = list(range(8))
    rows = 8 if diagonals is not None else 4
    out = Image.new("RGBA", (CHAR * len(frames), CHAR * rows), (0, 0, 0, 0))

    for src, row_offset in ((cardinals, 0), (diagonals, 4)):
        if src is None:
            continue
        for row in range(4):
            for i, frame in enumerate(frames):
                cell = src.crop((frame * CHAR, row * CHAR, (frame + 1) * CHAR, (row + 1) * CHAR))
                out.paste(cell, (i * CHAR, (row_offset + row) * CHAR))
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--key", required=True, help="output sprite_key")
    ap.add_argument("--sex", default="male", choices=["male", "female"])
    ap.add_argument("--body", default="1")
    for c in ("feet", "pants", "top", "dress", "hair", "facialhair", "head", "accessory"):
        ap.add_argument(f"--{c}", default=None,
                        help=f"{c} piece, e.g. 2 or 2_Alt_3 (colour variant)")
    ap.add_argument("--out", default=NPCS_OUT)
    args = ap.parse_args()

    if not os.path.isdir(PACK):
        sys.exit(f"pack tree not found: {PACK}\nSet RPG_PACK_DIR.")

    picks = {"body": args.body}
    for c in CATEGORIES[args.sex]:
        v = getattr(args, c, None)
        if v:
            picks[c] = v

    print(f"baking {args.key}: {args.sex} body {args.body}")
    for c in ORDER:
        if c in picks and c != "body":
            print(f"  {c:11} {picks[c]}")

    os.makedirs(args.out, exist_ok=True)
    written = []

    cardinals = stack(args.sex, picks, "walk")
    if cardinals is None:
        sys.exit("no base walk sheet — check --sex and --body")
    diagonals = stack(args.sex, picks, "walk_diag")
    sheet = stitch_walk(cardinals, diagonals)
    p = os.path.join(args.out, f"{args.key}_sheet.png")
    sheet.save(p)
    written.append((p, sheet.size))

    # A standing frame, facing south — what the map draws when nobody is moving.
    still = sheet.crop((0, 0, 128, 128))
    box = still.getbbox()
    if box:
        still = still.crop(box)
    p = os.path.join(args.out, f"{args.key}.png")
    still.save(p)
    written.append((p, still.size))

    # Caps must track slice_assets.py, slice_paperdoll.py and Paperdoll.php.
    # This tool is the offline proof-of-concept rather than the runtime path, so
    # it is the one most likely to be forgotten and drift.
    for part, suffix, cap in (("face", "_face", 144), ("bust", "_bust", 512)):
        im = stack(args.sex, picks, part)
        if im is None:
            print(f"  no {part} layers — skipped")
            continue
        if part == "face":
            # A faceset is a grid of expressions; the first cell is neutral.
            im = im.crop((0, 0, 144, 144))
        box = im.getbbox()
        if box:
            im = im.crop(box)
        im.thumbnail((cap, cap), Image.LANCZOS)
        p = os.path.join(args.out, f"{args.key}{suffix}.png")
        im.save(p)
        written.append((p, im.size))

    print()
    for path, size in written:
        print(f"  wrote {os.path.relpath(path, ROOT)}  {size}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
