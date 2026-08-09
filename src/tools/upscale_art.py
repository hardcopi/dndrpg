#!/usr/bin/env python3
"""
Super-resolve the art that has no more pixels to give.

Most of this game's art is already larger than the box it draws into -- busts,
faces, battlers and character sheets are all downsampled on screen, so raising
`slice_assets.py`'s caps was enough for them. This tool is for the other half:
ground tiles, props, decor, buildings, animated strips and combat clips, which
are drawn at their intrinsic size, 1:1, and have nothing left in the pack.

At the 1.5x device pixel ratio this is played at, a 48px tile in a 48 CSS px box
needs 72 real pixels and has 48. That is the gap this closes.

    python3 tools/upscale_art.py --dir tiles --out /tmp/sr     # one directory
    python3 tools/upscale_art.py --sample                      # the comparison set
    python3 tools/upscale_art.py --dir decor --model realesr-animevideov3

Nothing is written into assets/images/ -- output goes to --out, so the live tree
is untouched until somebody looks at the result and moves it deliberately.

Why the alpha bleed
-------------------
Real-ESRGAN's ncnn build feeds the network three channels and upscales alpha
separately with bicubic, so alpha survives on its own. The problem is the *other*
channel: every fully-transparent pixel in this art measures RGB [0,0,0], and the
network cannot see alpha, so it happily reconstructs edges by mixing that black
into the visible rim. Over the dark UI you would never notice; over grass or a
lit floor it reads as a grey halo on every sprite.

So before upscaling, push the opaque colour outward into the transparent region.
Alpha is untouched -- the bled pixels stay invisible -- but the network now sees
a continuation of the sprite instead of a cliff into black.
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import tempfile

try:
    import numpy as np
    from PIL import Image
except ImportError:
    sys.exit("needs Pillow and numpy: pip install pillow numpy")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ART = os.path.join(ROOT, "assets", "images")

# The binary and its models, installed outside the repo -- it is a 45 MB
# third-party download, not something to vendor into the site.
ESRGAN_DIR = os.environ.get("REALESRGAN_DIR", "/home/richard/tools/realesrgan")
ESRGAN_BIN = os.path.join(ESRGAN_DIR, "realesrgan-ncnn-vulkan")

# Vulkan enumerates Intel first, NVIDIA second, llvmpipe third on this machine.
# Defaulting to auto would land on the iGPU and be roughly an order of magnitude
# slower, so the discrete card is named explicitly.
GPU = os.environ.get("REALESRGAN_GPU", "1")

# Everything drawn 1:1 by the client. Deliberately NOT npcs/monsters/battlers
# (already downsampled on screen) or paperdoll (a coordinate space that only
# works because every layer shares it -- scaling that is its own job).
TARGET_DIRS = ("tiles", "decor", "buildings", "anim", "combat")

# The scale the art ships at. 2x covers DPR 1.5 with headroom, and covers the
# map's own zoom (ZOOM_MAX = 2.2) far better than 1x did.
ART_SCALE = 2

# The model runs at 4x and the result is filtered down to 2x. That is
# consistently sharper than asking for 2x directly, and the downsample also
# cleans up the ringing a 4x pass leaves behind.
MODEL_SCALE = 4

# One asset per failure mode, for the comparison page.
SAMPLE = [
    ("tiles/grass.png",          "flat seamless ground -- the hardest case; watch for invented texture and seams"),
    ("tiles/floor_stone.png",    "the 1:1 worst case, tiled across the whole map"),
    ("tiles/hearth.png",         "17.8% transparent, soft alpha edge -- the fringe test"),
    ("tiles/door_wood.png",      "hard straight edges"),
    ("decor/alchemist_table.png", "fine detail"),
    ("buildings/house_timber.png", "largest assets, most texture"),
]


def bleed(rgba: np.ndarray, iters: int = 8) -> np.ndarray:
    """
    Push opaque colour outward into transparent pixels.

    A plain 4-neighbour flood: each pass, any still-unknown pixel adjacent to a
    known one takes the mean of its known neighbours. Eight passes cover an 8px
    halo, comfortably more than a 4x kernel can reach back into.

    Alpha is returned untouched. This changes only what the network sees under
    the transparent region, never what the browser composites.
    """
    out = rgba.copy()
    rgb = out[..., :3].astype(np.float32)
    known = out[..., 3] > 0

    for _ in range(iters):
        if known.all():
            break
        # Sum of known neighbours, and how many there were, via shifts.
        acc = np.zeros_like(rgb)
        cnt = np.zeros(known.shape, np.float32)
        for axis, shift in ((0, 1), (0, -1), (1, 1), (1, -1)):
            r = np.roll(rgb, shift, axis=axis)
            k = np.roll(known, shift, axis=axis)
            # A roll wraps; blank the wrapped edge so colour cannot cross the image.
            idx = [slice(None)] * 2
            idx[axis] = 0 if shift > 0 else -1
            k = k.copy()
            k[tuple(idx)] = False
            acc += r * k[..., None]
            cnt += k
        fill = (~known) & (cnt > 0)
        if not fill.any():
            break
        rgb[fill] = acc[fill] / cnt[fill][..., None]
        known = known | fill

    out[..., :3] = np.clip(rgb, 0, 255).astype(np.uint8)
    return out


def is_ground_tile(path: str) -> bool:
    """
    A tile the map repeats across the terrain layer.

    Ground is drawn as a CSS background at exactly `--tile`, so it is authored at
    48x48 and it wraps. Props in the same directory (a door, a hearth) are larger
    and are drawn once, so they do not.
    """
    if os.path.basename(os.path.dirname(path)) != "tiles":
        return False
    with Image.open(path) as im:
        return im.size == (48, 48)


def upscale(src: str, dst: str, model: str, use_bleed: bool = True,
            wrap: bool | None = None) -> tuple[int, int]:
    """
    Upscale one file to exactly ART_SCALE x its size. Returns the new size.

    `wrap` upscales the tile as the centre of a 3x3 block of itself, then crops
    the middle back out. A super-resolution model has no idea that a ground tile
    tiles: run naively it reconstructs the left and right edges independently,
    they stop lining up, and the mismatch draws a grid across the whole terrain
    layer. Measured seam-to-detail ratio on floor_wood: 1.06 at 1x, 2.02 upscaled
    naively, 1.03 upscaled wrapped. Defaults to on for 48x48 tiles.
    """
    if wrap is None:
        wrap = is_ground_tile(src)

    im = Image.open(src).convert("RGBA")
    w, h = im.size

    tmpdir = tempfile.mkdtemp(prefix="upscale_art_")
    try:
        prepped = os.path.join(tmpdir, "in.png")
        big = os.path.join(tmpdir, "big.png")
        src_arr = np.array(im)
        prep = Image.fromarray(bleed(src_arr) if use_bleed else src_arr)

        if wrap:
            block = Image.new("RGBA", (w * 3, h * 3))
            for i in range(3):
                for j in range(3):
                    block.paste(prep, (i * w, j * h))
            prep = block
        prep.save(prepped)

        subprocess.run(
            [ESRGAN_BIN, "-i", prepped, "-o", big, "-n", model,
             "-s", str(MODEL_SCALE), "-g", GPU, "-m", os.path.join(ESRGAN_DIR, "models")],
            check=True, capture_output=True,
        )

        out = Image.open(big).convert("RGBA")
        if wrap:
            # Scale the whole block down first, so the crop lands on exact pixels.
            out = out.resize((w * 3 * ART_SCALE, h * 3 * ART_SCALE), Image.LANCZOS)
            out = out.crop((w * ART_SCALE, h * ART_SCALE,
                            w * ART_SCALE * 2, h * ART_SCALE * 2))
        target = (w * ART_SCALE, h * ART_SCALE)
        if out.size != target:
            out = out.resize(target, Image.LANCZOS)
        os.makedirs(os.path.dirname(dst) or ".", exist_ok=True)
        out.save(dst)
        return out.size
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dir", action="append", choices=TARGET_DIRS,
                    help="art directory to process; repeatable. Default: all of them.")
    ap.add_argument("--out", default=os.path.join(ROOT, "tools", "_upscaled"),
                    help="where to write; never assets/images/ itself")
    ap.add_argument("--model", default="realesrgan-x4plus",
                    help="realesrgan-x4plus | realesrgan-x4plus-anime | realesr-animevideov3")
    ap.add_argument("--no-bleed", action="store_true",
                    help="skip the alpha bleed, to see what it is buying")
    ap.add_argument("--sample", action="store_true",
                    help="just the comparison set, into <out>/<model>/")
    args = ap.parse_args()

    if not os.path.isfile(ESRGAN_BIN):
        sys.exit(f"upscaler not found at {ESRGAN_BIN}\nSet REALESRGAN_DIR.")

    if args.sample:
        files = [os.path.join(ART, rel) for rel, _ in SAMPLE]
    else:
        files = []
        for d in (args.dir or TARGET_DIRS):
            for name in sorted(os.listdir(os.path.join(ART, d))):
                if name.endswith(".png"):
                    files.append(os.path.join(ART, d, name))

    ok = fail = 0
    for i, src in enumerate(files, 1):
        rel = os.path.relpath(src, ART)
        dst = os.path.join(args.out, args.model, rel)
        try:
            size = upscale(src, dst, args.model, use_bleed=not args.no_bleed)
            ok += 1
            print(f"  [{i:3d}/{len(files)}] {rel:44s} -> {size[0]}x{size[1]}")
        except subprocess.CalledProcessError as e:
            fail += 1
            print(f"  [{i:3d}/{len(files)}] {rel:44s} FAIL "
                  f"{e.stderr.decode(errors='replace').strip()[:120]}")

    print(f"\n{ok} written, {fail} failed -> {os.path.join(args.out, args.model)}")
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
