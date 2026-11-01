#!/usr/bin/env python3
"""
Bake the character creator's look library: every pose2 look, in every outfit,
as the four pictures the game draws of a player character.

The Odin creator (imagine_creator.py, v7) does not store pictures of outfits.
It keeps 1,170 hair looks, two Dragonborn bases, and per-character neck-down
outfit layers, and COMPOSES an outfit on request:

    out = ((look * (255 - A) + V * A + 127) // 255)

That is right for a creator, where the next click is a different combination,
and wrong for a game: the game asks for the same few hundred people over and
over, from PHP on a host with no Python and no image model. So this tool runs
the creator's own arithmetic once per combination — 1,172 looks x 11 outfit
variants = 12,892 — and writes the results as static WebP that nginx serves
like any other asset:

    <out>/<char>/<stem>/<variant>.<kind>.webp

      full  384x1024  the whole figure, RGB on pure black (the creator's picture)
      bust  384x512   head and shoulders, RGB (the dialogue theatre, the sheet)
      fig   192x320   RGBA board figure, transparent (the battle map)
      face  144x144   cut from the bust (the party rail)

`variant` is `tunic` or `<outfit>__<colour_slug>`, and the key a character
stores is the creator's own, byte for byte (`resolve()` in imagine_creator.py):

    p2:look:<char>/<stem>[|<outfit>__<colour_slug>]
    p2:base:<char>[|<outfit>__<colour_slug>]          (Dragonborn)

Keeping the key identical is the point. The creator is where somebody chooses
a face; the game is where that face is shown; a key the two spelled
differently would be a translation table, and a translation table is a second
copy of the catalogue that can disagree with the first.

THE BOARD FIGURE is the only kind that needs more than composition, because
the source has no alpha: every look is painted on a black backdrop. The hand
drawn figures in assets/images/figures/ are 192x320, transparent, with the
figure filling the height from y=4 to y=316 — the board scales by size class at
draw time, so every figure must fill its canvas whatever its race, or a dwarf
would be drawn at half a halfling's height. Four things about how the cutout is
made were each learned the hard way, and each is written where it happens:

  * the matte is rembg's isnet-general-use, kept soft (`matte()`);
  * one matte serves every colour of an outfit (`bake_group()`);
  * the edge is un-premultiplied against the MEASURED backdrop (`cutout()`);
  * masculine_Dwarf was painted on grey haze, not black (`backdrop()`).

The library is NOT committed (~0.5 GB, ~51k files) and NOT sent by deploy.sh —
see .gitignore and rsync-excludes. It is a build product of the source
snapshot named in its manifest.json.

    PY=/home/richard/rivermark/creator-src/.venv/bin/python
    $PY tools/bake_looks.py --src SRC --out /tmp/looks --select trial --jobs 4 \\
        --contact-sheets /tmp/sheets --hard-cases /tmp/sheets
    $PY tools/bake_looks.py --src SRC --select all --jobs 4      # the whole library
    $PY tools/bake_looks.py --src SRC --write-options             # assets/data/looks_options.json

Run it as richard: rembg finds its model under ~/.rembg, and an agent running
as root has a different ~.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing as mp
import os
import re
import sys
import time

import numpy as np
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))     # src/
LIBRARY_VERSION = "v1"
DEFAULT_OUT = os.path.join(ROOT, "assets", "looks", LIBRARY_VERSION)
OPTIONS_PATH = os.path.join(ROOT, "assets", "data", "looks_options.json")
PATH_TEMPLATE = "assets/looks/" + LIBRARY_VERSION + "/{char}/{stem}/{variant}.{kind}.webp"

# Copied from imagine_creator.py rather than imported. Importing it is not
# possible from the snapshot: it loads the v1 (front pose) manifest at import
# time, and the snapshot carries pose2 only. tools/test_bake_looks.py pins the
# keys these produce against strings written out by hand from resolve(), which
# is what keeps the copy honest.
OUTFITS = {"tunic": ["default"], "plate": ["steel", "bronze", "blackened"],
           "leather": ["grey-brown", "brown", "black"],
           "robe": ["grey", "blue", "crimson", "green"]}
OUTFIT_LABEL = {"tunic": "Tunic (travelling clothes)", "plate": "Plate armour",
                "leather": "Leather armour", "robe": "Mage robes"}
NO_COLOUR = ["bald or shaven", "hidden under a hood"]
HAIRLESS = ["Dragonborn"]
GENDERS = ["masculine", "feminine"]

# What a new character is wearing before anybody touches the picker. A
# Barbarian in mage robes is a picture arguing with the sheet beside it, and
# the sheet wins. Monk is the one class whose kit is the tunic: unarmoured
# defence is the class.
CLASS_OUTFIT = {"Barbarian": "leather", "Bard": "leather", "Cleric": "plate",
                "Druid": "robe", "Fighter": "plate", "Monk": "tunic",
                "Paladin": "plate", "Ranger": "leather", "Rogue": "leather",
                "Sorcerer": "robe", "Warlock": "robe", "Wizard": "robe"}

KINDS = ("full", "bust", "fig", "face")
FULL_W, FULL_H = 384, 1024
BUST_W, BUST_H = 384, 512
FIG_W, FIG_H = 192, 320
FACE = 144

# The board figure's frame, measured off the hand-drawn figures rather than
# chosen: aldric.png's opaque box is (38, 4, 154, 316) on a 192x320 canvas.
# Top and bottom margins of 4 px, and a 2 px side margin so Lanczos ringing at
# a figure's widest point cannot reach the canvas edge.
FIG_TOP, FIG_BOTTOM, FIG_MAX_W = 4, 316, 188

PARCHMENT = (214, 190, 150)
MID_GREY = (128, 128, 128)

# A backdrop whose median border pixel is brighter than this in any channel is
# not black, and gets the full image re-composited onto black through the
# matte. Measured over the snapshot: eighteen characters sit at 0-1 on the
# full image; masculine_Dwarf sits at 7 (and 10 on the bust) with a grey haze.
# masculine_Half-Elf's busts measure exactly 2, which is JPEG noise on black,
# not a backdrop, hence `>` and not `>=`.
BACKDROP_LIMIT = 2


# ------------------------------------------------------------------ the catalogue

def slug(s: str) -> str:
    return s.replace(" ", "_")


def variants() -> list[tuple[str, str | None]]:
    """(outfit, colour) for all 11 variants, tunic first, in OUTFITS order —
    the creator's own order, so 'the first colour of an outfit' means what it
    means there."""
    return [("tunic", None)] + [(o, c) for o, cs in OUTFITS.items() if o != "tunic" for c in cs]


def variant_name(outfit: str, colour: str | None) -> str:
    return "tunic" if outfit == "tunic" else f"{outfit}__{slug(colour)}"


def creator_version(src: str) -> str:
    """The creator's version, read off the first line of its docstring
    ("... for /creator (v7, 1 Nov 2026)") so the manifest cannot claim a
    version the snapshot is not."""
    head = open(os.path.join(src, "imagine_creator.py"), encoding="utf-8").read(400)
    m = re.search(r"\((v\d+)[,)]", head)
    return m.group(1) if m else "unknown"


def load_catalogue(src: str) -> tuple[dict, list[dict]]:
    """Every entry the creator can resolve in pose2, outfits included, in the
    order `all_entries(pose='pose2', outfits=True)` yields them — variant
    outermost, then the hair looks, then the Dragonborn bases.

    Each entry carries what the bake needs (where its pictures come from) and
    what the game needs (the key and the choices that produce it)."""
    man = json.load(open(os.path.join(src, "imagine_looks_pose2", "manifest.json"), encoding="utf-8"))
    out = []
    for o, oc in variants():
        v = variant_name(o, oc)
        for e in man["looks"]:
            ch, stem = e["file"][:-4].split("/", 1)
            out.append(_entry(src, ch, stem, e["gender"], e["race"], e["hair_style"],
                              e["hair_color"], e["style_key"], e.get("review_flags") or [], o, oc, v,
                              "p2:look:" + e["key"]))
        for g in GENDERS:
            for r in HAIRLESS:
                ch = f"{g}_{r}"
                out.append(_entry(src, ch, "base", g, r, None, None, "base", [], o, oc, v, f"p2:base:{ch}"))
    return man, out


def _entry(src, ch, stem, g, r, style, colour, style_key, flags, outfit, ocol, v, key) -> dict:
    hairless = r in HAIRLESS
    if outfit != "tunic":
        key += f"|{v}"
    return {
        "key": key, "char": ch, "stem": stem, "variant": v, "style": style_key,
        "outfit": outfit, "outfit_color": ocol,
        "choices": {"gender": g, "race": r, "hair_style": style, "hair_color": colour,
                    "pose": "pose2", "outfit": outfit, "outfit_color": ocol},
        "review_flags": flags,
        "src_full": os.path.join(src, "imagine_bases_pose2", f"{ch}.png") if hairless
        else os.path.join(src, "imagine_looks_pose2", ch, f"{stem}.png"),
        "src_bust": os.path.join(src, "imagine_busts_pose2", ch, f"{stem}.png"),
    }


def out_paths(out: str, e: dict) -> dict[str, str]:
    d = os.path.join(out, e["char"], e["stem"])
    return {k: os.path.join(d, f"{e['variant']}.{k}.webp") for k in KINDS}


# ------------------------------------------------------------------ selection

def select_trial(entries: list[dict]) -> list[dict]:
    """The representative sample: ~100 entries chosen to put every hard thing
    the bake has to get right in front of a reviewer, deterministically, so a
    second trial after a fix is the same hundred pictures and can be compared.

    Four rotation passes over the eighteen hair characters, each pass shifting
    style, colour and outfit by a different stride, so every race meets several
    styles and every outfit colour lands on at least two races. Then the hard
    cases by name — `why` on each says which, and those are what the close-up
    sheet draws."""
    by_key = {e["key"]: e for e in entries}
    looks = [e for e in entries if e["variant"] == "tunic" and e["choices"]["race"] not in HAIRLESS]
    chars = sorted({e["char"] for e in looks}, key=lambda c: (c.split("_")[1], c))
    colour_styles = ["cropped", "shoulder", "long_loose", "braids", "topknot", "shaggy", "tied_back"]
    colours = ["black", "dark_brown", "chestnut", "auburn", "red", "blond", "grey", "white", "purple"]
    vs = [variant_name(o, c) for o, c in variants()]

    picked: dict[str, set] = {}

    def take(ch, stem, v, why):
        e = next(x for x in entries if x["char"] == ch and x["stem"] == stem and x["variant"] == v)
        picked.setdefault(e["key"], set()).add(why)

    for p, (ds, dc, dv) in enumerate([(0, 0, 0), (3, 4, 5), (5, 2, 8), (2, 7, 3)]):
        for i, ch in enumerate(chars):
            j = i + p * 18
            take(ch, f"{colour_styles[(j + ds) % 7]}__{colours[(j * 2 + dc) % 9]}", vs[(j + dv) % 11], "rotation")

    hard = [
        ("feminine_Elf", "long_loose__black", "plate__blackened", "black hair on blackened plate"),
        ("masculine_Human", "shaggy__black", "plate__blackened", "black hair on blackened plate"),
        ("feminine_Tiefling", "braids__black", "plate__blackened", "black hair on blackened plate"),
        ("masculine_Half-Orc", "tied_back__black", "plate__blackened", "black hair on blackened plate"),
        ("feminine_Halfling", "cropped__black", "leather__black", "black hair on black leather"),
        ("masculine_Sarsen", "long_loose__black", "leather__black", "black hair on black leather"),
        ("masculine_Dwarf", "braids__dark_brown", "tunic", "grey backdrop"),
        ("masculine_Dwarf", "braids__dark_brown", "plate__blackened", "grey backdrop"),
        ("masculine_Dwarf", "braids__dark_brown", "robe__grey", "grey backdrop"),
        ("feminine_Half-Orc", "bald", "tunic", "bald"),
        ("masculine_Elf", "bald", "leather__brown", "bald"),
        ("feminine_Human", "hood", "tunic", "hood"),
        ("masculine_Tiefling", "hood", "robe__green", "hood with an outfit"),
        ("feminine_Sarsen", "long_loose__red", "plate__steel", "hair over armour"),
        ("masculine_Gnome", "shaggy__blond", "plate__bronze", "hair over armour"),
        ("feminine_Dwarf", "long_loose__white", "plate__blackened", "hair over armour"),
        ("masculine_Half-Elf", "shaggy__auburn", "plate__steel", "hair over armour"),
    ]
    for g in GENDERS:
        for v in ("tunic", "plate__blackened", "robe__crimson"):
            hard.append((f"{g}_Dragonborn", "base", v, "Dragonborn"))
    for ch, stem, v, why in hard:
        take(ch, stem, v, "hard: " + why)

    sel = [dict(by_key[k], why=sorted(picked[k])) for k in sorted(picked)]
    # The promises the docstring makes, checked rather than hoped for: a
    # rotation that looks fair can still leave one colour on a single race.
    for v in vs:
        races = {e["choices"]["race"] for e in sel if e["variant"] == v}
        assert len(races) >= 2, f"trial: {v} appears on {races} only"
    assert len({e["char"] for e in sel}) == 20, "trial: not every race x gender"
    return sel


def select(spec: str, entries: list[dict]) -> list[dict]:
    """trial | all | a comma list of key prefixes or <char>[/<stem>[/<variant>]]."""
    if spec == "trial":
        return select_trial(entries)
    if spec == "all":
        return entries
    want = [s.strip() for s in spec.split(",") if s.strip()]
    return [e for e in entries
            if any(e["key"].startswith(w) or f"{e['char']}/{e['stem']}/{e['variant']}".startswith(w) for w in want)]


# ------------------------------------------------------------------ the image work

def compose(look_path: str, ch: str, style: str, outfit: str, ocol: str | None, kind: str, src: str) -> np.ndarray:
    """The creator's outfit_png(), the same integer arithmetic to the bit: the
    look darkened by (255 - A), the layer added by A, rounded half up. A tunic
    is the look itself — there is no tunic layer."""
    look = np.asarray(Image.open(look_path).convert("RGB"), dtype=np.uint16)
    if outfit == "tunic":
        return look.astype(np.uint8)
    d = os.path.join(src, "outfits_pose2", "placed" if kind == "full" else "bust", ch)
    V = np.asarray(Image.open(os.path.join(d, f"{outfit}__{slug(ocol)}.png")).convert("RGB"), dtype=np.uint16)
    A = np.asarray(Image.open(os.path.join(d, f"{outfit}__{style}__alpha.png")).convert("L"), dtype=np.uint16)[..., None]
    return ((look * (255 - A) + V * A + 127) // 255).astype(np.uint8)


def backdrop(rgb: np.ndarray, border: int = 8) -> np.ndarray:
    """The backdrop colour, as the median of an 8 px border.

    The creator's looks are on black, except where they are not: every
    masculine_Dwarf image was painted on ~(7,7,7) with a grey haze, and its
    busts on ~(10,10,10). Assuming 0 would un-premultiply that haze into a
    grey rim round every dwarf on the board, and leave the dwarf's full
    picture visibly lighter than the nineteen beside it in the creator's
    grid. Measuring costs nothing and covers the next snapshot's surprise too."""
    b = border
    ring = np.concatenate([rgb[:b].reshape(-1, 3), rgb[-b:].reshape(-1, 3),
                           rgb[:, :b].reshape(-1, 3), rgb[:, -b:].reshape(-1, 3)])
    return np.median(ring, axis=0)


_SESSION = None


def session():
    """One rembg session per process. Built lazily so the main process only
    pays for it when it actually mattes something (the close-up sheet)."""
    global _SESSION
    if _SESSION is None:
        import onnxruntime as ort
        from rembg import new_session
        so = ort.SessionOptions()
        so.intra_op_num_threads = int(os.environ.get("BAKE_ORT_THREADS", "0"))
        so.inter_op_num_threads = 1
        _SESSION = new_session("isnet-general-use", sess_opts=so)
    return _SESSION


def matte(rgb: np.ndarray) -> np.ndarray:
    """A soft alpha in 0..1 at the image's own resolution.

    isnet-general-use, because the two cheaper answers were tried first and
    both fail on exactly the pictures that matter. A luminance key cannot tell
    blackened plate or black hair from a black backdrop and eats both — the
    knight comes out as a floating face and a pair of hands. u2net keeps the
    armour but returns a blurred edge, which at board size is a halo. isnet is
    crisp and keeps the dark kit.

    Kept soft, never thresholded: the 0..255 fringe is what lets hair read as
    hair against parchment instead of as a paper cut-out."""
    from rembg import remove
    m = remove(Image.fromarray(rgb), session=session(), only_mask=True, post_process_mask=False)
    if m.size != (rgb.shape[1], rgb.shape[0]):
        m = m.resize((rgb.shape[1], rgb.shape[0]), Image.LANCZOS)
    return np.asarray(m.convert("L"), dtype=np.float32) / 255.0


def despeckle(a: np.ndarray) -> np.ndarray:
    """Keep the figure and nothing else.

    isnet occasionally lifts a fleck of backdrop noise or a stray highlight
    as foreground, and on the board that is a dot floating beside the figure
    which the hit-testing cannot know is not part of it. Components of
    alpha > 32 not joined to the largest are dropped — and the faint fringe
    round them with them, by keeping only what lies within a few pixels of the
    survivor, or each removed fleck would leave its own ghost."""
    from scipy import ndimage
    lab, n = ndimage.label(a > 32 / 255)
    if n <= 1:
        return a
    sizes = ndimage.sum(np.ones_like(a), lab, index=np.arange(1, n + 1))
    keep = lab == (int(np.argmax(sizes)) + 1)
    keep = ndimage.binary_dilation(keep, iterations=3)
    return np.where(keep, a, 0.0).astype(np.float32)


def cutout(rgb: np.ndarray, a: np.ndarray, bg: np.ndarray) -> np.ndarray:
    """The figure with its backdrop removed from the edge: float RGBA, 0..1,
    straight (not premultiplied) colour.

    A fringe pixel is figure and backdrop mixed by alpha: obs = F*a + B*(1-a).
    Leaving obs as the colour keeps the backdrop's share in it, which on black
    is a dark rim round every figure — invisible on the creator's black page,
    an outline on the board's parchment. Solving for F takes the backdrop back
    out. B is the measured backdrop, not 0, for the dwarf's sake (`backdrop()`).
    Below a ~2% alpha the division is noise and the colour is invisible anyway,
    so it is left alone."""
    obs = rgb.astype(np.float32) / 255.0
    B = (bg.astype(np.float32) / 255.0)[None, None, :]
    a3 = a[..., None]
    F = np.where(a3 > 0.02, (obs - B * (1.0 - a3)) / np.maximum(a3, 1e-3), obs)
    F = np.clip(F, 0.0, 1.0)
    return np.concatenate([F, a3], axis=2)


def deshadow(rgba: np.ndarray, obs: np.ndarray, bg: np.ndarray) -> np.ndarray:
    """Take the painted floor shadow off a board figure's feet.

    The looks were painted standing on something, and the shadow they cast is
    black on black: invisible in the creator, but isnet calls it foreground at
    5-25% alpha. On the board that is a smudge, and worse, it sets the bottom
    of the bounding box — masculine_Sarsen's ran thirty pixels past his soles
    to the bottom of the canvas, so `fig_of()` stood him on his shadow and he
    floated over his own cell.

    Two rules, both confined to the legs so the hair fringe (black hair's
    included) is never touched. Below the SOLE LINE — the last row with any
    alpha > 0.5 — nothing is figure, so all of it goes. And in the bottom 40%
    of the figure, a pixel that is less than solid and no brighter than the
    backdrop carries no colour at all: it is shadow, or backdrop seen through
    the gap between the legs, and it goes too.

    The band was 5%, then 15%, and each left something: the trial's
    masculine_Sarsen in black leather has the backdrop between his thighs
    matted at 15-78% alpha, which on parchment is a dark wedge from the knee
    to the crotch — 62-82% of the way down him. 40% reaches it and stays
    below where any hairstyle ends (long_loose stops at the shoulder blades).
    The ceiling of 0.8 rather than 0.5 is for the same wedge: its core is
    more opaque than a cast shadow's. A black boot's own soft edge can lose a
    pixel to this; at board size that is invisible, and the wedge was not.

    The full picture keeps its shadow: on black it is part of the painting."""
    a = rgba[..., 3]
    solid = np.nonzero((a > 0.5).any(axis=1))[0]
    if len(solid) == 0:
        return rgba
    top, sole = int(solid.min()), int(solid.max())
    out = rgba.copy()
    out[sole + 1:, :, 3] = 0.0
    y0 = sole - max(4, int(0.40 * (sole - top)))
    lum = obs[y0:sole + 1].astype(np.float32).max(axis=2) - float(bg.max())
    seg = out[y0:sole + 1, :, 3]
    seg[(seg < 0.8) & (lum < 6)] = 0.0
    return out


def on_black(rgba: np.ndarray) -> np.ndarray:
    """A cut-out re-composited onto pure black, as uint8 RGB."""
    return np.clip(rgba[..., :3] * rgba[..., 3:4] * 255.0 + 0.5, 0, 255).astype(np.uint8)


def _resize_f(x: np.ndarray, size: tuple[int, int]) -> np.ndarray:
    return np.asarray(Image.fromarray(x.astype(np.float32), "F").resize(size, Image.LANCZOS), dtype=np.float32)


def fig_of(rgba: np.ndarray) -> np.ndarray:
    """A full-size cut-out to a 192x320 board figure, uint8 RGBA.

    Height first: the figure fills y 4..316 whatever its race, matching the
    hand-drawn figures, because the board scales by size class when it draws
    and a figure that did not fill its canvas would be scaled twice. A
    figure that would then be wider than 188 px (a robe flaring, arms out) is
    fitted to the width instead and still stands on y=316 — feet on the line
    is what makes a row of tokens look like they share a floor.

    Resampled premultiplied, then un-premultiplied: Lanczos on straight colour
    averages the invisible black of alpha=0 pixels into the edge and puts the
    dark rim back that `cutout()` took out.

    Centred on the FEET, not the box: the alpha-weighted centroid of the
    bottom 15%. These are three-quarter poses with a hand or a cloak out to
    one side, and centring the box would stand the figure off its own cell."""
    a = rgba[..., 3]
    ys, xs = np.nonzero(a > 8 / 255)
    if len(ys) == 0:
        raise ValueError("empty matte")
    y0, y1, x0, x1 = ys.min(), ys.max() + 1, xs.min(), xs.max() + 1
    crop = rgba[y0:y1, x0:x1].copy()
    crop[..., 3] = np.where(crop[..., 3] > 8 / 255, crop[..., 3], 0)   # what was cut off must not leak back
    h, w = crop.shape[:2]
    target_h = FIG_BOTTOM - FIG_TOP
    s = target_h / h
    if w * s > FIG_MAX_W:
        s = FIG_MAX_W / w
    nw, nh = max(1, round(w * s)), max(1, round(h * s))
    pa = crop[..., 3]
    planes = [_resize_f(crop[..., c] * pa, (nw, nh)) for c in range(3)]
    al = np.clip(_resize_f(pa, (nw, nh)), 0.0, 1.0)
    al[al < 2 / 255] = 0.0                                             # Lanczos ringing in the clear
    rgb = np.stack([np.clip(p / np.maximum(al, 1e-4), 0, 1) for p in planes], axis=2)
    rgb[al == 0] = 0.0

    foot = al[int(nh * 0.85):]
    cols = foot.sum(axis=0)
    cx = float((cols * np.arange(nw)).sum() / cols.sum()) if cols.sum() > 0 else nw / 2
    left = int(round(FIG_W / 2 - cx))
    left = max(2, min(FIG_W - 2 - nw, left)) if nw <= FIG_W - 4 else (FIG_W - nw) // 2
    top = FIG_BOTTOM - nh

    canvas = np.zeros((FIG_H, FIG_W, 4), dtype=np.float32)
    canvas[top:top + nh, left:left + nw, :3] = rgb
    canvas[top:top + nh, left:left + nw, 3] = al
    return np.clip(canvas * 255.0 + 0.5, 0, 255).astype(np.uint8)


def face_of(bust: np.ndarray, side: int = 200, cx: int = 192, top: int = 20) -> np.ndarray:
    """The party-rail face, cut from the bust — never generated separately, for
    the reason tools/cut_face.py gives: two paintings are two people.

    One window for every character works because the busts are already
    normalised: imagine_busts_pose2 gives each character its own
    head-and-shoulders window (face ~30% of the bust's width, crown ~10% from
    the top), so the face sits in the same place on all twenty."""
    x0 = cx - side // 2
    crop = Image.fromarray(bust).crop((x0, top, x0 + side, top + side))
    return np.asarray(crop.resize((FACE, FACE), Image.LANCZOS))


def save_webp(arr: np.ndarray, path: str, kind: str) -> int:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    im = Image.fromarray(arr)
    if kind == "fig":
        # Alpha at 100 is lossless alpha in libwebp: the edge IS the picture
        # on a board, and a lossy alpha plane bands the soft hair fringe.
        im.save(tmp, "WEBP", quality=90, alpha_quality=100, method=4)
    else:
        im.save(tmp, "WEBP", quality=85, method=4)
    os.replace(tmp, path)                    # a killed bake never leaves half a file that --force-less reruns trust
    return os.path.getsize(path)


# ------------------------------------------------------------------ one group

def bake_group(task: dict) -> dict:
    """Bake every colour of one (char, stem, outfit).

    The unit of work is the group and not the entry because the matte is
    shared: the silhouette depends on who is wearing which outfit, never on
    what colour it was dyed. So the matte is computed ONCE, from the outfit's
    first colour (steel, grey-brown, grey — the creator's order), and reused
    for the rest. That is 4,688 isnet runs instead of 12,892, and it is also
    why a steel knight and a blackened one have the same edge: two mattes
    could disagree by a pixel of hair, and a player flicking through the
    colours would see the outline twitch."""
    src, out, force, face_args = task["src"], task["out"], task["force"], task["face"]
    first = task["first"]                         # the entry whose composite the matte comes from
    st = {"compose": 0.0, "matte": 0.0, "cut": 0.0, "encode": 0.0, "mattes": 0,
          "bytes": {k: 0 for k in KINDS}, "written": {k: 0 for k in KINDS}, "skipped": 0,
          "entries": 0, "normalised": {}, "errors": []}
    mattes: dict[str, np.ndarray] = {}

    def get_matte(kind: str) -> np.ndarray:
        if kind not in mattes:
            t = time.perf_counter()
            img = compose(first[f"src_{kind}"], first["char"], first["style"], first["outfit"],
                          first["outfit_color"], kind, src)
            t1 = time.perf_counter()
            mattes[kind] = despeckle(matte(img))
            st["compose"] += t1 - t
            st["matte"] += time.perf_counter() - t1
            st["mattes"] += 1
        return mattes[kind]

    for e in task["entries"]:
        paths = out_paths(out, e)
        todo = [k for k in KINDS if force or not os.path.exists(paths[k])]
        if not todo:
            st["skipped"] += 1
            continue
        st["entries"] += 1
        try:
            t = time.perf_counter()
            full = compose(e["src_full"], e["char"], e["style"], e["outfit"], e["outfit_color"], "full", src)
            bust = compose(e["src_bust"], e["char"], e["style"], e["outfit"], e["outfit_color"], "bust", src)
            st["compose"] += time.perf_counter() - t
            bg_full, bg_bust = backdrop(full), backdrop(bust)
            norm_full = float(bg_full.max()) > BACKDROP_LIMIT
            norm_bust = float(bg_bust.max()) > BACKDROP_LIMIT
            imgs: dict[str, np.ndarray] = {}

            if "fig" in todo or (norm_full and "full" in todo):
                a = get_matte("full")
                t = time.perf_counter()
                cut = cutout(full, a, bg_full)
                if "fig" in todo:
                    imgs["fig"] = fig_of(deshadow(cut, full, bg_full))
                if norm_full:
                    imgs["full"] = on_black(cut)
                st["cut"] += time.perf_counter() - t
            if "full" in todo and "full" not in imgs:
                imgs["full"] = full
            if norm_bust and ("bust" in todo or "face" in todo):
                a = get_matte("bust")
                t = time.perf_counter()
                bust = on_black(cutout(bust, a, bg_bust))
                st["cut"] += time.perf_counter() - t
            if "bust" in todo:
                imgs["bust"] = bust
            if "face" in todo:
                imgs["face"] = face_of(bust, *face_args)
            if norm_full or norm_bust:
                st["normalised"][e["char"]] = {"full": bg_full.tolist(), "bust": bg_bust.tolist()}

            t = time.perf_counter()
            for k in todo:
                st["bytes"][k] += save_webp(imgs[k], paths[k], k)
                st["written"][k] += 1
            st["encode"] += time.perf_counter() - t
        except Exception as ex:                    # one bad source must not sink a 12,892-entry bake
            st["errors"].append(f"{e['key']}: {type(ex).__name__}: {ex}")
    return st


def init_worker(threads: int) -> None:
    os.environ["BAKE_ORT_THREADS"] = str(threads)
    os.environ["OMP_NUM_THREADS"] = str(threads)


def groups_of(sel: list[dict], all_entries: list[dict]) -> list[dict]:
    """Selected entries grouped by (char, stem, outfit), each with the entry its
    matte is taken from — which may not itself be selected (a trial that wants
    only blackened plate still mattes steel)."""
    firsts = {}
    for e in all_entries:
        firsts.setdefault((e["char"], e["stem"], e["outfit"]), e)
    g: dict[tuple, list] = {}
    for e in sel:
        g.setdefault((e["char"], e["stem"], e["outfit"]), []).append(e)
    return [{"first": firsts[k], "entries": v} for k, v in g.items()]


# ------------------------------------------------------------------ manifest, stats, sheets

def sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


SOURCE_MANIFESTS = ["imagine_looks_pose2/manifest.json", "imagine_bases_pose2/imagine_bases.json",
                    "imagine_busts_pose2/busts.json", "outfits_pose2/manifest.json"]


def write_manifest(out: str, src: str, entries: list[dict], params: dict, normalised: dict) -> dict:
    """The library's own account of itself, rebuilt from what is ON DISK rather
    than from what this run did — so a bake done in five sittings, or a trial
    followed by `--select all`, ends with one manifest describing everything
    present. An entry is listed only when all four of its pictures exist; a
    half-baked entry is not something the game should be told it can show."""
    present, total = [], {k: 0 for k in KINDS}
    for e in entries:
        paths = out_paths(out, e)
        if not all(os.path.exists(p) for p in paths.values()):
            continue
        for k, p in paths.items():
            total[k] += os.path.getsize(p)
        present.append({"key": e["key"], "choices": e["choices"],
                        "files": {k: os.path.relpath(p, out) for k, p in paths.items()},
                        "review_flags": e["review_flags"]})
    old = {}
    mp_path = os.path.join(out, "manifest.json")
    if os.path.exists(mp_path):
        try:
            old = json.load(open(mp_path, encoding="utf-8")).get("normalised_backdrops", {})
        except ValueError:
            pass
    src_hashes = {p: sha256(os.path.join(src, p)) for p in SOURCE_MANIFESTS if os.path.exists(os.path.join(src, p))}
    combined = hashlib.sha256("".join(f"{k}={v}\n" for k, v in sorted(src_hashes.items())).encode()).hexdigest()
    man = {
        "library_version": LIBRARY_VERSION,
        "creator_version": creator_version(src),
        "pose": "pose2",
        "source": os.path.abspath(src),
        # sha256 despite the name the brief gave it: one digest over the
        # per-file digests, and each file's own below.
        "source_sha256": combined,
        "source_files_sha256": src_hashes,
        "baked": time.strftime("%Y-%m-%dT%H:%M:%S"),
        "params": params,
        "path_template": PATH_TEMPLATE,
        "normalised_backdrops": {**old, **normalised},
        "counts": {"entries": len(present), "possible": len(entries), "files": len(present) * len(KINDS)},
        "bytes": {**total, "total": sum(total.values())},
        "entries": present,
    }
    tmp = mp_path + ".tmp"
    os.makedirs(out, exist_ok=True)
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(man, f, indent=1, ensure_ascii=False)
    os.replace(tmp, mp_path)
    return man


def font(size: int):
    try:
        return ImageFont.load_default(size=size)
    except TypeError:                               # Pillow < 10.1
        return ImageFont.load_default()


def label_of(e: dict) -> str:
    return f"{e['char']}\n{e['stem']}\n{e['variant']}"


def _grid(cells: list[Image.Image], labels: list[str], cols: int, bg, lab_h: int = 40) -> Image.Image:
    w = max(c.width for c in cells)
    h = max(c.height for c in cells)
    rows = (len(cells) + cols - 1) // cols
    sheet = Image.new("RGB", (cols * (w + 6) + 6, rows * (h + lab_h + 6) + 6), bg)
    d = ImageDraw.Draw(sheet)
    f = font(11)
    fg = (0, 0, 0) if sum(bg) > 300 else (235, 235, 235)
    for i, (c, t) in enumerate(zip(cells, labels)):
        x, y = 6 + (i % cols) * (w + 6), 6 + (i // cols) * (h + lab_h + 6)
        sheet.paste(c, (x, y), c if c.mode == "RGBA" else None)
        d.multiline_text((x, y + h + 2), t, fill=fg, font=f, spacing=1)
    return sheet


def on_colour(fig: Image.Image, colour) -> Image.Image:
    bg = Image.new("RGBA", fig.size, colour + (255,))
    return Image.alpha_composite(bg, fig.convert("RGBA")).convert("RGB")


def contact_sheets(out: str, sel: list[dict], dest: str) -> list[str]:
    """Four sheets a person can judge the bake from in a minute: the pictures
    at reduced size, and the board figures at 1:1 on the two grounds that
    matter — parchment, which is the board, and mid grey, which shows a dark
    rim and a light halo equally badly."""
    os.makedirs(dest, exist_ok=True)
    have = [e for e in sel if all(os.path.exists(p) for p in out_paths(out, e).values())]
    labels = [label_of(e) for e in have]
    written = []

    def load(e, k):
        return Image.open(out_paths(out, e)[k])

    full = [load(e, "full").convert("RGB").resize((144, 384), Image.LANCZOS) for e in have]
    _grid(full, labels, 12, (24, 24, 24)).save(p := os.path.join(dest, "contact_full.jpg"), quality=88)
    written.append(p)
    bust = [load(e, "bust").convert("RGB").resize((192, 256), Image.LANCZOS) for e in have]
    _grid(bust, labels, 10, (24, 24, 24)).save(p := os.path.join(dest, "contact_bust.jpg"), quality=88)
    written.append(p)
    face = [load(e, "face").convert("RGB") for e in have]
    _grid(face, labels, 14, (24, 24, 24)).save(p := os.path.join(dest, "contact_face.jpg"), quality=90)
    written.append(p)
    pairs = []
    for e in have:
        fg = load(e, "fig").convert("RGBA")
        pair = Image.new("RGB", (FIG_W * 2, FIG_H))
        pair.paste(on_colour(fg, PARCHMENT), (0, 0))
        pair.paste(on_colour(fg, MID_GREY), (FIG_W, 0))
        pairs.append(pair)
    _grid(pairs, labels, 8, PARCHMENT).save(p := os.path.join(dest, "contact_fig.png"), optimize=True)
    written.append(p)
    return written


def checker(w: int, h: int, n: int = 16) -> Image.Image:
    yy, xx = np.mgrid[0:h, 0:w]
    c = np.where(((yy // n) + (xx // n)) % 2 == 0, 200, 150).astype(np.uint8)
    return Image.fromarray(np.stack([c, c, c], axis=2))


def hard_cases(out: str, src: str, sel: list[dict], dest: str) -> str | None:
    """cutout_closeups.png: for every hard case, the shipped figure at 2x
    nearest-neighbour (every pixel of the edge visible, nothing smoothed by the
    viewer) on parchment and on a checkerboard — the checkerboard is where a
    leftover speck or a half-transparent hole shows — and the full-resolution
    cut-out's head and shoulders at 1:1 on parchment, which is where black hair
    against black armour is won or lost.

    The full-resolution cut-out is not stored, so it is matted again here,
    from the same first-colour composite the bake used."""
    cases = [e for e in sel if any(w.startswith("hard") for w in e.get("why", []))
             and os.path.exists(out_paths(out, e)["fig"])]
    if not cases:
        return None
    os.makedirs(dest, exist_ok=True)
    firsts = {(g["first"]["char"], g["first"]["stem"], g["first"]["outfit"]): g["first"]
              for g in groups_of(cases, load_catalogue(src)[1])}
    rows = []
    f = font(13)
    for e in cases:
        fig = Image.open(out_paths(out, e)["fig"]).convert("RGBA")
        big = fig.resize((FIG_W * 2, FIG_H * 2), Image.NEAREST)
        first = firsts[(e["char"], e["stem"], e["outfit"])]
        a = despeckle(matte(compose(first["src_full"], first["char"], first["style"], first["outfit"],
                                    first["outfit_color"], "full", src)))
        full = compose(e["src_full"], e["char"], e["style"], e["outfit"], e["outfit_color"], "full", src)
        cut = cutout(full, a, backdrop(full))
        ys = np.nonzero(cut[..., 3].max(axis=1) > 8 / 255)[0]
        y0 = max(0, int(ys.min()) - 8) if len(ys) else 0
        rgba = Image.fromarray(np.clip(cut * 255 + 0.5, 0, 255).astype(np.uint8), "RGBA").crop((0, y0, FULL_W, y0 + 384))
        row = Image.new("RGB", (FIG_W * 4 + FULL_W + 40, FIG_H * 2 + 24), (40, 40, 40))
        row.paste(on_colour(big, PARCHMENT), (0, 24))
        row.paste(Image.alpha_composite(checker(*big.size).convert("RGBA"), big).convert("RGB"), (FIG_W * 2 + 20, 24))
        row.paste(on_colour(rgba, PARCHMENT), (FIG_W * 4 + 40, 24))
        ImageDraw.Draw(row).text((4, 4), f"{e['char']} / {e['stem']} / {e['variant']}   -   "
                                 + ", ".join(w[6:] for w in e["why"] if w.startswith("hard")),
                                 fill=(235, 235, 235), font=f)
        rows.append(row)
    sheet = Image.new("RGB", (rows[0].width, sum(r.height + 8 for r in rows)), (20, 20, 20))
    y = 0
    for r in rows:
        sheet.paste(r, (0, y))
        y += r.height + 8
    p = os.path.join(dest, "cutout_closeups.png")
    sheet.save(p, optimize=True)
    return p


# ------------------------------------------------------------------ options

def read_classes() -> list[str]:
    """The class list off the seed, so a class added there without a default
    outfit fails here rather than shipping a picker that has no answer for it."""
    seed = os.path.join(ROOT, "sql", "seed_data.sql")
    try:
        txt = open(seed, encoding="utf-8").read()
        block = txt.split("INSERT INTO classes", 1)[1].split(";\n", 1)[0]
        names = re.findall(r"^\('([A-Za-z]+)',\s*\d+,", block, re.M)
        if names:
            return names
    except (OSError, IndexError):
        pass
    return list(CLASS_OUTFIT)


def write_options(src: str, path: str = OPTIONS_PATH) -> dict:
    """assets/data/looks_options.json — the closed lists the game's picker
    draws and its server validates against. Generated from the source
    manifests, never written by hand, so the picker cannot offer a hair colour
    the library has no picture of."""
    man = json.load(open(os.path.join(src, "imagine_looks_pose2", "manifest.json"), encoding="utf-8"))
    styles, races, genders = {}, [], []
    for e in man["looks"]:
        styles.setdefault(e["hair_style"], e["style_key"])
        if e["race"] not in races:
            races.append(e["race"])
        if e["gender"] not in genders:
            genders.append(e["gender"])
    races += [r for r in HAIRLESS if r not in races]
    classes = read_classes()
    missing = [c for c in classes if c not in CLASS_OUTFIT]
    if missing:
        raise SystemExit(f"no default outfit for class(es) {missing}: add them to CLASS_OUTFIT")
    opts = {
        "library_version": LIBRARY_VERSION,
        "creator_version": creator_version(src),
        "pose": "pose2",
        "path_template": PATH_TEMPLATE,
        # How {stem} and {variant} in the path are spelled from the choices —
        # the same rules as the look key, so a server building a path and the
        # creator building a key cannot disagree about a colour with a space in it.
        "stem_rule": {"coloured": "{style_key}__{hair_colour with spaces as _}",
                      "no_colour": "{style_key}", "hairless": "base"},
        "variant_rule": {"tunic": "tunic", "other": "{outfit}__{colour with spaces as _}"},
        "key_template": "p2:look:{char}/{stem}[|{variant}] or p2:base:{char}[|{variant}]; no |variant for tunic",
        "kinds": list(KINDS),
        "genders": genders,
        "races": races,
        "hairless_races": HAIRLESS,
        "hair_styles": [{"label": k, "style_key": v} for k, v in styles.items()],
        "hair_colours": man["colours"],
        "no_colour_styles": NO_COLOUR,
        "outfits": [{"key": o, "label": OUTFIT_LABEL[o], "colours": cs,
                     "colour_slugs": [slug(c) for c in cs] if o != "tunic" else []}
                    for o, cs in OUTFITS.items()],
        "class_outfit": {c: CLASS_OUTFIT[c] for c in classes},
    }
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(opts, f, indent=1, ensure_ascii=False)
        f.write("\n")
    return opts


# ------------------------------------------------------------------ main

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--src", required=True, help="the creator snapshot (contains imagine_creator.py)")
    ap.add_argument("--out", default=DEFAULT_OUT)
    ap.add_argument("--select", default="trial", help="trial | all | comma list of key or char/stem/variant prefixes")
    ap.add_argument("--jobs", type=int, default=1)
    ap.add_argument("--limit", type=int, default=0, help="bake only the first N selected entries")
    ap.add_argument("--dry-run", action="store_true", help="say what would be baked and stop")
    ap.add_argument("--force", action="store_true", help="re-bake outputs that already exist")
    ap.add_argument("--contact-sheets", metavar="DIR")
    ap.add_argument("--hard-cases", metavar="DIR")
    ap.add_argument("--figures-dir", default=os.path.join(ROOT, "assets", "images", "figures"),
                    help="the hand-drawn figures the board figure frame is checked against")
    ap.add_argument("--face", default="200,192,20", help="face window: side,centre_x,top on the bust")
    ap.add_argument("--write-options", action="store_true", help="write assets/data/looks_options.json and stop")
    args = ap.parse_args()

    if args.write_options:
        o = write_options(args.src)
        print(f"wrote {os.path.relpath(OPTIONS_PATH)}: {len(o['races'])} races, {len(o['hair_styles'])} styles, "
              f"{len(o['hair_colours'])} colours, {len(o['outfits'])} outfits, {len(o['class_outfit'])} classes")
        return 0

    check_figure_frame(args.figures_dir)
    face_args = tuple(int(x) for x in args.face.split(","))
    _, entries = load_catalogue(args.src)
    assert len(entries) == 12892, f"catalogue has {len(entries)} entries, the creator has 12,892"
    sel = select(args.select, entries)
    if args.limit:
        sel = sel[:args.limit]
    groups = groups_of(sel, entries)
    todo = [e for e in sel if args.force or not all(os.path.exists(p) for p in out_paths(args.out, e).values())]
    print(f"selected {len(sel)} entries in {len(groups)} matte groups; {len(todo)} need baking")
    if args.dry_run:
        for e in todo[:40]:
            print("  ", e["key"], "  ", ", ".join(e.get("why", [])))
        if len(todo) > 40:
            print(f"   ... and {len(todo) - 40} more")
        return 0

    jobs = max(1, args.jobs)
    threads = max(1, (os.cpu_count() or 4) // jobs)
    tasks = [dict(g, src=args.src, out=args.out, force=args.force, face=face_args) for g in groups]
    t0 = time.perf_counter()
    tot = {"compose": 0.0, "matte": 0.0, "cut": 0.0, "encode": 0.0, "mattes": 0, "entries": 0, "skipped": 0,
           "bytes": {k: 0 for k in KINDS}, "written": {k: 0 for k in KINDS}, "normalised": {}, "errors": []}

    def fold(st):
        for k in ("compose", "matte", "cut", "encode", "mattes", "entries", "skipped"):
            tot[k] += st[k]
        for k in KINDS:
            tot["bytes"][k] += st["bytes"][k]
            tot["written"][k] += st["written"][k]
        tot["normalised"].update(st["normalised"])
        tot["errors"] += st["errors"]

    done = 0
    if jobs == 1:
        init_worker(threads)
        it = map(bake_group, tasks)
        pool = None
    else:
        pool = mp.get_context("spawn").Pool(jobs, initializer=init_worker, initargs=(threads,))
        it = pool.imap_unordered(bake_group, tasks)
    for st in it:
        fold(st)
        done += 1
        if done % 10 == 0 or done == len(tasks):
            el = time.perf_counter() - t0
            print(f"  {done}/{len(tasks)} groups, {tot['entries']} entries baked, {tot['skipped']} skipped, {el:.1f}s",
                  flush=True)
    if pool:
        pool.close()
        pool.join()
    wall = time.perf_counter() - t0

    params = {"select": args.select, "jobs": jobs, "ort_threads": threads, "matte_model": "isnet-general-use",
              "backdrop_limit": BACKDROP_LIMIT, "fig": [FIG_W, FIG_H], "fig_rows": [FIG_TOP, FIG_BOTTOM],
              "fig_max_w": FIG_MAX_W, "face": dict(zip(("side", "cx", "top"), face_args)), "face_size": FACE,
              "webp": {"full": "q85 m4", "bust": "q85 m4", "face": "q85 m4", "fig": "q90 alpha100 m4"}}
    man = write_manifest(args.out, args.src, entries, params, tot["normalised"])
    stats = extrapolate(tot, wall, jobs, entries)
    # A rerun that found everything done measured nothing, and must not
    # overwrite the run that did measure with a file of zeroes.
    if tot["entries"]:
        with open(os.path.join(args.out, "bake_stats.json"), "w", encoding="utf-8") as f:
            json.dump(stats, f, indent=1)

    print(f"\nbaked {tot['entries']} entries ({tot['mattes']} mattes) in {wall:.1f}s wall with --jobs {jobs}; "
          f"{tot['skipped']} already done")
    print("stage seconds (summed over workers): " +
          ", ".join(f"{k} {tot[k]:.1f}" for k in ("compose", "matte", "cut", "encode")))
    if tot["written"]["full"]:
        print("mean bytes: " + ", ".join(f"{k} {tot['bytes'][k] / max(1, tot['written'][k]) / 1024:.1f} KiB" for k in KINDS))
    if stats.get("extrapolation"):
        x = stats["extrapolation"]
        print(f"extrapolated to all {x['entries']}: ~{x['wall_hours']:.2f} h wall at --jobs {jobs}, "
              f"{x['mattes']} mattes, {x['files']} files, {x['bytes_total'] / 2**30:.2f} GiB "
              f"({', '.join(f'{k} {v / 2**20:.0f} MiB' for k, v in x['bytes'].items())})")
    print(f"normalised backdrops: {sorted(man['normalised_backdrops'])}")
    print(f"library: {man['counts']['entries']} entries on disk, {man['bytes']['total'] / 2**20:.1f} MiB")
    if tot["errors"]:
        print(f"\n{len(tot['errors'])} ERRORS:")
        for e in tot["errors"][:20]:
            print("  ", e)

    if args.contact_sheets:
        for p in contact_sheets(args.out, sel, args.contact_sheets):
            print("wrote", p)
    if args.hard_cases:
        os.environ.setdefault("BAKE_ORT_THREADS", str(os.cpu_count() or 4))
        p = hard_cases(args.out, args.src, sel, args.hard_cases)
        if p:
            print("wrote", p)
    return 1 if tot["errors"] else 0


def extrapolate(tot: dict, wall: float, jobs: int, entries: list[dict]) -> dict:
    """What `--select all` will cost, from what this run measured.

    Mattes and everything else scale differently — one matte per (char, stem,
    outfit) group, everything else per entry — so they are projected
    separately and then brought back to wall time by this run's own ratio of
    wall to summed worker time, which folds in whatever parallel efficiency
    --jobs actually achieved here rather than assuming it was perfect."""
    full_groups = len({(e["char"], e["stem"], e["outfit"]) for e in entries})
    bust_groups = len({(e["char"], e["stem"], e["outfit"]) for e in entries
                       if e["char"] in tot["normalised"]})
    s = {"wall_seconds": round(wall, 2), "jobs": jobs, "entries_baked": tot["entries"], "mattes": tot["mattes"],
         "skipped": tot["skipped"],
         "seconds": {k: round(tot[k], 2) for k in ("compose", "matte", "cut", "encode")},
         "bytes": tot["bytes"], "files_written": tot["written"], "errors": len(tot["errors"])}
    n = tot["entries"]
    if n == 0 or tot["mattes"] == 0:
        return s
    worker = tot["compose"] + tot["matte"] + tot["cut"] + tot["encode"]
    eff = wall / worker if worker else 1.0 / jobs
    per_entry = (tot["compose"] + tot["cut"] + tot["encode"]) / n
    per_matte = tot["matte"] / tot["mattes"]
    mattes = full_groups + bust_groups
    worker_all = per_entry * len(entries) + per_matte * mattes
    per_kind = {k: tot["bytes"][k] / max(1, tot["written"][k]) * len(entries) for k in KINDS}
    s["extrapolation"] = {
        "entries": len(entries), "files": len(entries) * len(KINDS), "mattes": mattes,
        "seconds_per_entry": round(per_entry, 4), "seconds_per_matte": round(per_matte, 4),
        "worker_seconds": round(worker_all, 1), "wall_to_worker_ratio": round(eff, 4),
        "wall_seconds": round(worker_all * eff, 1), "wall_hours": round(worker_all * eff / 3600, 3),
        "bytes": {k: int(v) for k, v in per_kind.items()}, "bytes_total": int(sum(per_kind.values())),
    }
    return s


def check_figure_frame(figures_dir: str) -> None:
    """Refuse to bake into a frame the hand-drawn figures no longer use. The
    192x320, y 4..316 frame was measured off them; if somebody re-cuts those
    to a new frame, baking 12,892 figures into the old one would be a day's
    work drawn at the wrong size."""
    p = os.path.join(figures_dir, "aldric.png")
    if not os.path.exists(p):
        print(f"note: {p} not found, figure frame not checked")
        return
    im = Image.open(p)
    box = im.getchannel("A").point(lambda v: 255 if v > 8 else 0).getbbox() if im.mode == "RGBA" else None
    if im.size != (FIG_W, FIG_H) or not box or box[1] != FIG_TOP or box[3] != FIG_BOTTOM:
        raise SystemExit(f"{p} is {im.size} with box {box}; this tool bakes {FIG_W}x{FIG_H} "
                         f"rows {FIG_TOP}..{FIG_BOTTOM} — update FIG_* first")


if __name__ == "__main__":
    sys.exit(main())
