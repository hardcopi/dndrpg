#!/usr/bin/env python3
"""
Derive the tab icon from the Rivermark wordmark.

The site had no favicon: browsers asked for /favicon.ico and got the homepage
as text/html (nginx try_files falling through to index.php). This builds a
square badge from art already in the repository — the letter R out of
assets/images/login/logo.webp, on the plaque's navy field, with a gold bezel
sampled from the same frame — and writes:

  favicon.ico                         16, 32 and 48, at the site root
  assets/images/site/favicon.svg      a vector companion (navy plate, gold
                                      bezel, a path of the same R)
  assets/images/site/apple-touch-icon.png   180×180, for the iOS home screen

Nothing here is a new painting. Re-run if the wordmark is replaced:

    python3 tools/gen_favicon.py
"""

from __future__ import annotations

import os
import struct
import sys
from io import BytesIO
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOGO = os.path.join(ROOT, "assets", "images", "login", "logo.webp")
OUT_ICO = os.path.join(ROOT, "favicon.ico")
OUT_SVG = os.path.join(ROOT, "assets", "images", "site", "favicon.svg")
OUT_APPLE = os.path.join(ROOT, "assets", "images", "site", "apple-touch-icon.png")

# Tight box around the first letter of RIVERMARK, including its carved shade
# and a sliver of the navy field. Measured on logo.webp (1154×456).
R_BOX = (140, 100, 248, 296)

# A quiet patch of the plaque's navy, below and to the right of "Chronicles".
NAVY_BOX = (844, 296, 892, 344)

# Colours sampled from the plaque frame (median gold, highlight, carve).
BRONZE_OUTER = (42, 24, 10, 255)
BRONZE_DEEP = (120, 74, 28, 255)
GOLD = (183, 123, 55, 255)
GOLD_HI = (214, 168, 90, 255)
CARVE = (70, 40, 16, 255)

MASTER = 512


def load_logo() -> Image.Image:
    if not os.path.isfile(LOGO):
        sys.exit(f"missing wordmark: {LOGO}")
    return Image.open(LOGO).convert("RGBA")


def letter_rgba(logo: Image.Image) -> Image.Image:
    """The R, with a soft alpha around its carved edge, navy made transparent."""
    crop = logo.crop(R_BOX)
    arr = np.array(crop)
    rgb = arr[:, :, :3].astype(np.int16)
    a = arr[:, :, 3]
    luma = 0.3 * rgb[:, :, 0] + 0.59 * rgb[:, :, 1] + 0.11 * rgb[:, :, 2]
    navy_like = (rgb[:, :, 2] > rgb[:, :, 0] + 8) & (rgb[:, :, 0] < 55) & (luma < 70)
    letter = (~navy_like) & (a > 20)
    # A 1-px dilate so the dark outline stays with the letter.
    pad = np.pad(letter, 1, mode="constant")
    dil = pad.copy()
    for dy in (-1, 0, 1):
        for dx in (-1, 0, 1):
            dil |= np.roll(np.roll(pad, dy, 0), dx, 1)
    dil = dil[1:-1, 1:-1]
    # Feather one pixel at the rim.
    rim = dil & (~letter)
    alpha = np.where(letter, 255, np.where(rim, 160, 0)).astype(np.uint8)
    out = arr.copy()
    out[:, :, 3] = np.minimum(out[:, :, 3], alpha)
    return Image.fromarray(out)


def navy_square(side: int, logo: Image.Image) -> Image.Image:
    patch = logo.crop(NAVY_BOX).convert("RGBA")
    return patch.resize((side, side), Image.Resampling.LANCZOS)


def apply_bezel(img: Image.Image) -> tuple[Image.Image, int]:
    """A gold plate-frame, drawn as filled rounded rings so the corners stay clean."""
    side = img.size[0]
    frame = Image.new("RGBA", img.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(frame)
    radius = int(side * 0.07)
    # (colour, width as a fraction of the side), outside in.
    rings = [
        (BRONZE_OUTER, 0.012),
        (BRONZE_DEEP, 0.016),
        (GOLD, 0.022),
        (GOLD_HI, 0.010),
        (GOLD, 0.010),
        (CARVE, 0.008),
    ]
    inset = 0
    for color, frac in rings:
        box = [inset, inset, side - 1 - inset, side - 1 - inset]
        rr = max(0, radius - inset)
        draw.rounded_rectangle(box, radius=rr, fill=color)
        inset += max(1, int(round(side * frac)))
    # Punch the centre so the navy field shows through.
    hole = Image.new("L", img.size, 0)
    hd = ImageDraw.Draw(hole)
    box = [inset, inset, side - 1 - inset, side - 1 - inset]
    rr = max(0, radius - inset)
    hd.rounded_rectangle(box, radius=rr, fill=255)
    fa = np.array(frame)
    ha = np.array(hole)
    fa[:, :, 3] = (fa[:, :, 3].astype(np.uint16) * (255 - ha) // 255).astype(np.uint8)
    frame = Image.fromarray(fa)
    out = img.copy()
    out.paste(frame, (0, 0), frame)
    return out, inset


def compose_master(logo: Image.Image) -> Image.Image:
    letter = letter_rgba(logo)
    bg = navy_square(MASTER, logo)
    # Fit the R inside the bezel with a little breathing room.
    # Bezel eats ~0.078 of each side; leave another ~0.06 of padding.
    pad = 0.145
    inner = MASTER * (1 - 2 * pad)
    rw, rh = letter.size
    scale = inner / rh
    tw, th = int(rw * scale), int(rh * scale)
    rs = letter.resize((tw, th), Image.Resampling.LANCZOS)
    x = (MASTER - tw) // 2
    y = (MASTER - th) // 2
    bg.paste(rs, (x, y), rs)
    badged, _ = apply_bezel(bg)
    return badged


def down(im: Image.Image, side: int, contrast: float = 1.0) -> Image.Image:
    out = im.resize((side, side), Image.Resampling.LANCZOS)
    if side <= 48:
        out = ImageEnhance.Contrast(out).enhance(1.08 if contrast == 1.0 else contrast)
        out = out.filter(ImageFilter.UnsharpMask(radius=0.55, percent=90, threshold=1))
    return out.convert("RGBA")


# ---------------------------------------------------------------------------
# Letter outline → SVG path
# ---------------------------------------------------------------------------

def letter_paths(logo: Image.Image, scale: float, ox: float, oy: float) -> str:
    """SVG path data for the R, mapped into the 512 viewBox.

    OpenCV traces the painted letter, including the counter in the bowl, and
    the path is filled even-odd so that hole stays a hole.
    """
    import cv2

    letter = letter_rgba(logo)
    arr = np.array(letter)
    mask = (arr[:, :, 3] > 80).astype(np.uint8) * 255
    # Upscale so approxPolyDP has something to simplify rather than a jaggy
    # 100-px outline.
    up = 4
    mask = cv2.resize(mask, (mask.shape[1] * up, mask.shape[0] * up), interpolation=cv2.INTER_NEAREST)
    contours, _ = cv2.findContours(mask, cv2.RETR_CCOMP, cv2.CHAIN_APPROX_NONE)
    parts: list[str] = []
    min_area = (up * 3) ** 2  # drop flecks, keep the bowl and the letter
    for c in contours:
        if cv2.contourArea(c) < min_area:
            continue
        approx = cv2.approxPolyDP(c, epsilon=1.6, closed=True)
        if len(approx) < 6:
            continue
        cmds = []
        for i, pt in enumerate(approx.reshape(-1, 2)):
            vx = ox + (pt[0] / up) * scale
            vy = oy + (pt[1] / up) * scale
            cmds.append(("M" if i == 0 else "L") + f"{vx:.2f} {vy:.2f}")
        cmds.append("Z")
        parts.append(" ".join(cmds))
    return " ".join(parts)


def write_svg(logo: Image.Image, dest: str) -> None:
    # Same placement as compose_master, so the path sits where the painted R does.
    pad = 0.145
    inner = MASTER * (1 - 2 * pad)
    rw, rh = (R_BOX[2] - R_BOX[0], R_BOX[3] - R_BOX[1])
    scale = inner / rh
    tw, th = rw * scale, rh * scale
    ox = (MASTER - tw) / 2
    oy = (MASTER - th) / 2
    path = letter_paths(logo, scale, ox, oy)
    if not path:
        sys.exit("failed to trace the letter R")

    # Bezel insets matching apply_bezel, in viewBox units.
    fracs = [0.012, 0.016, 0.022, 0.010, 0.010, 0.008]
    colors = [
        "#2a180a",
        "#784a1c",
        "#b77b37",
        "#d6a85a",
        "#b77b37",
        "#462810",
    ]
    radius = MASTER * 0.07
    inset = 0
    rects = []
    for color, frac in zip(colors, fracs):
        w = max(1, round(MASTER * frac))
        rr = max(0, radius - inset)
        rects.append((inset, MASTER - inset, rr, color))
        inset += w
    # Inner navy plate
    rr_navy = max(0, radius - inset)

    svg_rects = []
    for x0, x1, rr, color in rects:
        svg_rects.append(
            f'  <rect x="{x0:.1f}" y="{x0:.1f}" width="{x1-x0:.1f}" height="{x1-x0:.1f}" rx="{rr:.1f}" fill="{color}"/>'
        )
    svg_rects.append(
        f'  <rect x="{inset:.1f}" y="{inset:.1f}" width="{MASTER-2*inset:.1f}" height="{MASTER-2*inset:.1f}" rx="{rr_navy:.1f}" fill="#051028"/>'
    )

    # A quiet navy texture isn't available in vector; a slight radial lift
    # keeps the plate from reading as flat printer-ink.
    text = f'''<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {MASTER} {MASTER}" width="{MASTER}" height="{MASTER}" role="img" aria-label="Rivermark Chronicles">
  <title>Rivermark Chronicles</title>
  <defs>
    <radialGradient id="plate" cx="50%" cy="42%" r="65%">
      <stop offset="0%" stop-color="#163056"/>
      <stop offset="100%" stop-color="#051028"/>
    </radialGradient>
    <linearGradient id="gold" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#d6a85a"/>
      <stop offset="45%" stop-color="#c48c40"/>
      <stop offset="100%" stop-color="#8a5a22"/>
    </linearGradient>
  </defs>
{chr(10).join(svg_rects[:-1])}
  <rect x="{inset:.1f}" y="{inset:.1f}" width="{MASTER-2*inset:.1f}" height="{MASTER-2*inset:.1f}" rx="{rr_navy:.1f}" fill="url(#plate)"/>
  <path fill="url(#gold)" fill-rule="evenodd" d="{path}"/>
</svg>
'''
    Path(dest).parent.mkdir(parents=True, exist_ok=True)
    with open(dest, "w", encoding="utf-8") as f:
        f.write(text)


def write_ico(master: Image.Image, dest: str) -> None:
    """Write a multi-size ICO from pre-sharpened rasters (Pillow's ICO saver
    would resize the master itself and skip the 16-px hinting)."""
    sizes = (16, 32, 48)
    images = [down(master, s).convert("RGBA") for s in sizes]
    # ICO with embedded PNG images (the modern form, supports 32-bit).
    # Hand-rolled so each size is the hinted raster, not a fresh downsample.
    entries = []
    payloads = []
    offset = 6 + 16 * len(images)
    for im, side in zip(images, sizes):
        buf = BytesIO()
        im.save(buf, format="PNG")
        data = buf.getvalue()
        # width/height are stored as a byte; 0 means 256.
        w = side if side < 256 else 0
        # ICONDIRENTRY: w, h, palette, reserved, planes, bitcount, size, offset
        entries.append(struct.pack("<BBBBHHII", w, w, 0, 0, 1, 32, len(data), offset))
        payloads.append(data)
        offset += len(data)
    # ICONDIR: reserved, type=1 (icon), count
    header = struct.pack("<HHH", 0, 1, len(images))
    with open(dest, "wb") as f:
        f.write(header)
        f.write(b"".join(entries))
        f.write(b"".join(payloads))


def main() -> int:
    logo = load_logo()
    master = compose_master(logo)
    write_ico(master, OUT_ICO)
    write_svg(logo, OUT_SVG)
    apple = down(master, 180)
    # iOS masks the rounded square itself; give it a full-bleed plate.
    apple.convert("RGB").save(OUT_APPLE, format="PNG", optimize=True)

    for path in (OUT_ICO, OUT_SVG, OUT_APPLE):
        print(f"wrote {os.path.relpath(path, ROOT)} ({os.path.getsize(path)} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
