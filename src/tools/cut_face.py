#!/usr/bin/env python3
"""
Cut a `<key>_face.png` out of a `<key>_bust.png`.

Hand-made character art arrives as one painted bust. The game needs two files
from it: the bust for the dialogue theatre, and a small square face for the
party rail, the people list and the combat cards. Cutting that square by hand
is a step somebody forgets, and a missing face is invisible until a character
turns up in a fight as a broken image.

    python3 tools/cut_face.py kessa                 # report
    python3 tools/cut_face.py kessa --apply
    python3 tools/cut_face.py --all --apply         # every bust with no face

The crop is a square taken from the top of the bust, because that is where a
head is in every piece of art in this project — the busts are all
waist-or-chest-up portraits. It is nudged down slightly so a tall hairstyle or
a headdress is not sliced off at the crown; Ilse wears antlers, and a naive
top-aligned square beheaded her.

FACE_PX matches Paperdoll::FACE_PX. If that constant moves, this moves with it.
"""

import argparse
import os
import sys

try:
    from PIL import Image
except ImportError:  # pragma: no cover - depends on the host
    sys.exit("Pillow is required: pip install --user Pillow")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Both folders, in the order a key is searched for. Busts live in two places —
# `rebuild_busts.py` has scanned both since it was written — and cutting faces
# from only one of them meant the half of the cast that fights you could not be
# re-cut by the tool whose whole job is that a forgotten face is invisible until
# somebody turns up in a fight as a broken image.
ART_DIRS = (
    os.path.join(ROOT, "assets", "images", "npcs"),
    os.path.join(ROOT, "assets", "images", "monsters"),
)

# Matches Paperdoll::FACE_PX — the baked art and the hand-made art have to come
# out the same size or the party rail draws two different things.
FACE_PX = 144

# The square's side, as a fraction of the bust's height. A head is about this
# much of a waist-up portrait; the rest of the frame is the shoulders it sits
# on, which is what makes a face read as a face rather than as a small person.
HEAD_FRACTION = 0.44

# Headroom above the crown, as a fraction of the square. Without it a hat or a
# set of antlers touches the top edge and the crop looks like a mistake.
HEADROOM = 0.06


def _subject_box(im: Image.Image) -> tuple[int, int, int, int]:
    """The opaque part of a cut-out bust, or the whole frame if it has none."""
    alpha = im.getchannel("A")
    box = alpha.getbbox()
    return box or (0, 0, im.size[0], im.size[1])


def _head_centre_x(im: Image.Image, top: int, depth: int) -> int:
    """
    Where the head is, horizontally.

    Taken from the opaque pixels in the top slice of the subject rather than
    the middle of the image, because these portraits are three-quarter poses:
    the body is off to one side and centring on the frame puts an ear where the
    nose should be. Kessa leans left, Ilse's antlers are wider than her
    shoulders, and both crop correctly off this.
    """
    slice_ = im.crop((0, top, im.size[0], min(im.size[1], top + depth)))
    box = slice_.getchannel("A").getbbox()
    if not box:
        return im.size[0] // 2
    return (box[0] + box[2]) // 2


def face_from_bust(bust: Image.Image) -> Image.Image:
    """A square of head, cut from the top of a bust and sized to FACE_PX."""
    w, h = bust.size
    left0, top0, right0, bottom0 = _subject_box(bust)
    subject_h = max(1, bottom0 - top0)

    side = max(16, int(subject_h * HEAD_FRACTION))
    side = min(side, w, h)

    # Start at the crown, backed off by the headroom.
    top = max(0, top0 - int(side * HEADROOM))
    top = min(top, max(0, h - side))

    cx = _head_centre_x(bust, top, max(1, side // 2))
    left = max(0, min(cx - side // 2, w - side))

    square = bust.crop((left, top, left + side, top + side))
    return square.resize((FACE_PX, FACE_PX), Image.LANCZOS)


def art_dir_for(key: str) -> str | None:
    """The folder holding `<key>_bust.png`, or None if no folder does."""
    for d in ART_DIRS:
        if os.path.exists(os.path.join(d, f"{key}_bust.png")):
            return d
    return None


def keys_missing_faces() -> list[str]:
    out = []
    for d in ART_DIRS:
        for name in sorted(os.listdir(d)):
            if not name.endswith("_bust.png"):
                continue
            key = name[: -len("_bust.png")]
            if not os.path.exists(os.path.join(d, f"{key}_face.png")):
                out.append(key)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("key", nargs="?", help="sprite key, e.g. kessa")
    ap.add_argument("--all", action="store_true",
                    help="every bust that has no face beside it")
    ap.add_argument("--apply", action="store_true", help="write the file")
    args = ap.parse_args()

    if args.all:
        keys = keys_missing_faces()
        if not keys:
            print("Every bust already has a face beside it.")
            return 0
    elif args.key:
        keys = [args.key]
    else:
        ap.error("name a key, or pass --all")

    wrote = 0
    for key in keys:
        art = art_dir_for(key)
        if art is None:
            print(f"{key:<14} no {key}_bust.png — nothing to cut from")
            continue
        bust_path = os.path.join(art, f"{key}_bust.png")
        face_path = os.path.join(art, f"{key}_face.png")

        with Image.open(bust_path) as im:
            bust = im.convert("RGBA")
            face = face_from_bust(bust)
            print(f"{key:<14} {os.path.basename(art):<8} "
                  f"{bust.size[0]}x{bust.size[1]} -> {FACE_PX}x{FACE_PX}", end="")
            if not args.apply:
                exists = " (would overwrite)" if os.path.exists(face_path) else ""
                print(f"  [dry run]{exists}")
                continue
            face.save(face_path)
            wrote += 1
            print("  written")

    if not args.apply:
        print("\nNothing was written. Pass --apply.")
    else:
        print(f"\n{wrote} face(s) written.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
