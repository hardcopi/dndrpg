#!/usr/bin/env python3
"""
Cut the modular paperdoll library out of the T&C Humans pack.

The pack ships base bodies and clothing as separate layers, each with a complete
animation set at identical dimensions, meant to be stacked. This slices them into
`assets/images/paperdoll/<sex>/` and writes an `index.json` describing what can
be worn, so the creator can offer choices and Paperdoll.php can bake them.

    python3 tools/slice_paperdoll.py                     # everything
    python3 tools/slice_paperdoll.py --sex male          # one sex
    python3 tools/slice_paperdoll.py --only hair,top     # one or more categories
    python3 tools/slice_paperdoll.py --index-only        # rewrite index.json only

Batchable for the same reason slice_assets.py is: the pack is 2.8 GB on a slow
mount and 143 layers do not finish inside a short-lived shell.

NOTHING HERE IS TRIMMED. That is the whole trick and the easiest thing to get
wrong: these images only stack because every layer shares the base body's
coordinate space, and cropping a hat to its own bounding box moves it. Trimming
happens once, in Paperdoll.php, after the layers have been composited.
"""

from __future__ import annotations

import argparse
import glob
import json
import os
import re
import sys

try:
    from PIL import Image
except ImportError:
    sys.exit("This script needs Pillow: pip install Pillow")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PACK = os.environ.get("RPG_PACK_DIR", os.path.join(os.path.dirname(ROOT), "rpg-assets-src"))
OUT = os.path.join(ROOT, "assets", "images", "paperdoll")

HUMANS = "Medieval T&C/Medieval_T&C_Humans"
CHAR = 128                  # PVGames character frame
WALK_FRAMES = list(range(8))  # the whole cycle, as slice_assets.py cuts it
# Must match slice_assets.py and Paperdoll.php: a character baked from these
# layers sits beside actors cut by the other tool, and a bust that disagrees
# would be visibly a different size in the same conversation.
BUST_PX = 512
FACE_PX = 144

# Bottom to top, from the pack's own Read Me. `head` and `facialhair` are not in
# its list; the overlap of their head crops puts facial hair above the base and
# below hair, and head above hair.
#
# The Read Me also notes feet and pants sometimes want reversing depending on the
# boot, which is why this is data the baker reads rather than a sequence of calls.
LAYER_ORDER = [
    "body", "feet", "pants", "dress", "gloves", "top",
    "facialhair", "hair", "head", "accessory",
]

# Categories offered per sex, and the label the picker shows. Weapons are
# deliberately absent: what a character is holding is their equipped item, and
# baking a sword into the sprite would make it a haircut.
CATEGORIES = {
    "male": {
        "body": "Build", "feet": "Boots", "pants": "Trousers", "top": "Tunic",
        "facialhair": "Beard", "hair": "Hair", "head": "Headwear",
        "accessory": "Accessory",
    },
    "female": {
        "body": "Build", "feet": "Boots", "pants": "Trousers", "top": "Top",
        "dress": "Dress", "gloves": "Gloves", "hair": "Hair",
        "accessory": "Accessory",
    },
}

# Our category name -> the pack's folder word.
PACK_WORD = {
    "feet": "Feet", "pants": "Pants", "top": "Top", "dress": "Dress",
    "gloves": "Gloves", "hair": "Hair", "facialhair": "FacialHair",
    "head": "Head", "accessory": "Accessory",
}

# Categories a character may leave empty. Only the body is required — the base
# bodies are bald and barefoot, so every other layer is genuinely a choice, hair
# included. Making hair mandatory would forbid a shaved head, which is a look a
# player will want and which the art already supports.
OPTIONAL = {
    "feet", "pants", "dress", "gloves", "top",
    "facialhair", "hair", "head", "accessory",
}


def who(sex: str) -> str:
    return "Male" if sex == "male" else "Female"


def sex_root(sex: str) -> str:
    return f"{HUMANS}/Medieval_T&C_{who(sex)}s"


def clothing_root(sex: str) -> str:
    return f"{sex_root(sex)}/Medieval_T&C_{who(sex)}_Clothing"


def pack_path(rel: str) -> str:
    return os.path.join(PACK, rel)


def find_one(folder: str, pattern: str) -> str | None:
    hits = sorted(glob.glob(os.path.join(pack_path(folder), pattern)))
    return hits[0] if hits else None


def load(folder: str, pattern: str) -> Image.Image | None:
    p = find_one(folder, pattern)
    return Image.open(p).convert("RGBA") if p else None


def stitch_walk(cardinals: Image.Image, diagonals: Image.Image | None) -> Image.Image:
    """
    4 frames x 8 facings at 128px, row order S, W, E, N, SW, NW, SE, NE.

    The same layout cut_walk_sheet() in slice_assets.py produces, because the
    renderer animates it with a CSS background-position and cannot be told that
    one kind of character is laid out differently.
    """
    rows = 8 if diagonals is not None else 4
    out = Image.new("RGBA", (CHAR * len(WALK_FRAMES), CHAR * rows), (0, 0, 0, 0))
    for src, row_offset in ((cardinals, 0), (diagonals, 4)):
        if src is None:
            continue
        for row in range(4):
            for i, frame in enumerate(WALK_FRAMES):
                cell = src.crop((frame * CHAR, row * CHAR,
                                 (frame + 1) * CHAR, (row + 1) * CHAR))
                out.paste(cell, (i * CHAR, (row_offset + row) * CHAR))
    return out


def emit_layer(folder: str, out_dir: str, key: str) -> dict:
    """
    One layer's four outputs. Returns which of them exist.

    A missing bust or face is ordinary — not every accessory appears in a
    portrait — and the baker simply does not draw that layer in that output.
    A missing walk sheet means the folder is not a wearable layer at all.
    """
    made = {}
    os.makedirs(out_dir, exist_ok=True)

    cardinals = load(folder, "*walking.png")
    if cardinals is None:
        return made
    diagonals = load(folder, "*walking_diag.png")
    sheet = stitch_walk(cardinals, diagonals)
    sheet.save(os.path.join(out_dir, f"{key}_sheet.png"))
    made["sheet"] = True

    # The south-facing standing frame, full 128x128 and NOT trimmed, so the
    # browser can stack these for the live preview and have them line up.
    sheet.crop((0, 0, CHAR, CHAR)).save(os.path.join(out_dir, f"{key}_prev.png"))
    made["prev"] = True

    bust = load(folder, "*_Bust*.png")
    if bust is not None:
        # Resized, never cropped: a uniform scale keeps every layer in the same
        # coordinate space, which is what lets the busts stack too.
        bust.resize((BUST_PX, BUST_PX), Image.LANCZOS).save(
            os.path.join(out_dir, f"{key}_bust.png"))
        made["bust"] = True

    face = load(folder, "*_Face.png")
    if face is not None:
        # A faceset is a grid of 144px expression cells; the first is neutral.
        face.crop((0, 0, 144, 144)).resize((FACE_PX, FACE_PX), Image.LANCZOS).save(
            os.path.join(out_dir, f"{key}_face.png"))
        made["face"] = True

    return made


def scan(sex: str) -> dict:
    """
    What the pack offers this sex, grouped into styles and colour variants.

    `Hair_1` and `Hair_1_Alt_3` are one style in two colours — verified by their
    alpha channels being identical — so they are presented as a style with a
    colour picker rather than as five separate hairstyles.
    """
    found = {}

    bodies = sorted(glob.glob(pack_path(f"{sex_root(sex)}/Medieval_T&C_{who(sex)}_[0-9]")))
    found["body"] = {
        os.path.basename(b).rsplit("_", 1)[-1]: {"colours": []} for b in bodies
    }

    for cat, word in PACK_WORD.items():
        if cat not in CATEGORIES[sex]:
            continue
        styles: dict = {}
        pattern = pack_path(f"{clothing_root(sex)}/Medieval_T&C_{who(sex)}_{word}_*")
        for path in sorted(glob.glob(pattern)):
            name = os.path.basename(path)
            m = re.match(rf"Medieval_T&C_{who(sex)}_{word}_(\d+)(?:_Alt_(\d+))?$", name)
            if not m:
                continue
            style, alt = m.group(1), m.group(2)
            styles.setdefault(style, {"colours": []})
            if alt:
                styles[style]["colours"].append(alt)
        if styles:
            found[cat] = {k: v for k, v in sorted(styles.items(), key=lambda kv: int(kv[0]))}

    return found


def variant_key(cat: str, style: str, alt: str | None) -> str:
    return f"{cat}_{style}" + (f"_alt{alt}" if alt else "")


def variant_folder(sex: str, cat: str, style: str, alt: str | None) -> str:
    if cat == "body":
        return f"{sex_root(sex)}/Medieval_T&C_{who(sex)}_{style}"
    word = PACK_WORD[cat]
    name = f"Medieval_T&C_{who(sex)}_{word}_{style}" + (f"_Alt_{alt}" if alt else "")
    return f"{clothing_root(sex)}/{name}"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--sex", choices=["male", "female"], action="append", default=None)
    ap.add_argument("--only", help="comma-separated categories")
    ap.add_argument("--index-only", action="store_true",
                    help="rescan and rewrite index.json without cutting art")
    # The pack sits on a slow mount and hair alone is thirty variants, which is
    # more than a short-lived shell will sit through. Resuming makes a run that
    # was cut off worth something instead of starting over.
    ap.add_argument("--resume", action="store_true",
                    help="skip variants whose sheet is already on disk")
    args = ap.parse_args()

    if not os.path.isdir(PACK):
        sys.exit(f"pack tree not found: {PACK}\nSet RPG_PACK_DIR.")

    sexes = args.sex or ["male", "female"]
    wanted = set(args.only.split(",")) if args.only else None

    index_path = os.path.join(OUT, "index.json")
    index = {"layer_order": LAYER_ORDER, "optional": sorted(OPTIONAL), "sexes": {}}
    if os.path.exists(index_path):
        try:
            with open(index_path) as fh:
                existing = json.load(fh)
            index["sexes"] = existing.get("sexes", {})
        except (OSError, ValueError):
            pass

    cut = skipped = 0
    for sex in sexes:
        catalogue = scan(sex)
        out_sex = os.path.join(OUT, sex)
        entry = index["sexes"].setdefault(sex, {"categories": {}})

        for cat, styles in catalogue.items():
            entry["categories"].setdefault(cat, {
                "label": CATEGORIES[sex].get(cat, cat.title()),
                "optional": cat in OPTIONAL,
                "styles": {},
            })
            for style, meta in styles.items():
                variants = [None] + meta["colours"]
                style_rec = {"colours": []}
                for alt in variants:
                    key = variant_key(cat, style, alt)
                    on_disk = os.path.exists(os.path.join(out_sex, f"{key}_sheet.png"))
                    if args.index_only or (wanted and cat not in wanted) \
                            or (args.resume and on_disk):
                        # Trust what is on disk rather than re-cutting it.
                        if on_disk:
                            style_rec["colours"].append({
                                "key": key, "alt": alt,
                                "bust": os.path.exists(os.path.join(out_sex, f"{key}_bust.png")),
                                "face": os.path.exists(os.path.join(out_sex, f"{key}_face.png")),
                            })
                            skipped += 1
                        continue
                    made = emit_layer(variant_folder(sex, cat, style, alt), out_sex, key)
                    if not made.get("sheet"):
                        print(f"  !! {sex}/{key}: no walk sheet, skipped")
                        continue
                    style_rec["colours"].append({
                        "key": key, "alt": alt,
                        "bust": bool(made.get("bust")), "face": bool(made.get("face")),
                    })
                    cut += 1
                    print(f"  ok  {sex}/{key}")
                if style_rec["colours"]:
                    entry["categories"][cat]["styles"][style] = style_rec

    os.makedirs(OUT, exist_ok=True)
    with open(index_path, "w") as fh:
        json.dump(index, fh, indent=2, sort_keys=True)

    print(f"\ncut {cut} layers, kept {skipped} already on disk")
    for sex, e in index["sexes"].items():
        for cat, c in e["categories"].items():
            n = sum(len(s["colours"]) for s in c["styles"].values())
            print(f"  {sex:7} {cat:11} {len(c['styles'])} styles, {n} variants")
    print(f"\nwrote {os.path.relpath(index_path, ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
