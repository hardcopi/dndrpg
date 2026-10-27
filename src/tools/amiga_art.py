#!/usr/bin/env python3
"""Amiga-era portrait painter and an opt-in prompt profile.

The checked-in spike samples are drawn here. They are not a resize of the
shipped busts: those stayed painterly no matter how few colours they were
cut down to, and that is the look that was rejected. A dialogue portrait in
the AmiDungeon mockups is an early-90s adventure painting — a readable
silhouette, mottled charcoal ground, and shading made of pixel clusters —
so this file paints one, with Pillow and nothing else. The same drawing is
sampled twice: once for the bust, once tighter for the face, so the rail
and the theatre cannot disagree about who the person is.

``gen_npc_art.py --amiga`` and ``gen_region_map.py --amiga`` still only
change the prompt and the output folder. They are the opt-in model path.
``--samples`` never calls it, and never reads ``assets/images/npcs`` or
``assets/images/maps``.
"""

from __future__ import annotations

import argparse
import json
import math
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPIKE_ART = os.path.join(ROOT, "assets", "images", "amiga-spike")
RIVERMARK_JSON = os.path.join(ROOT, "content", "locations", "rivermark.json")

AMIGA_PORTRAIT_STYLE = (
    "Amiga-era pixel-art portrait, early-1990s adventure-game digital painting, "
    "hand-painted bitmap shading with crisp pixel clusters, compact 32-colour "
    "palette, mottled charcoal ground and restrained cobalt blue #378EF0 "
    "accents, readable head-and-shoulders silhouette, no photographic texture, "
    "no smooth modern illustration"
)

AMIGA_MAP_STYLE = (
    "Amiga-era bitmap cartography on warm parchment, fine dark-ink plan lines, "
    "crisp pixel clusters, compact 32-colour palette with restrained cobalt "
    "blue #378EF0 accents, generous blank paper, quiet map texture, no lettering"
)

# AGA preview ceilings. RTG delivery stays the host's own contract
# (144 face, 384×512 bust, 1536×1152 plate); these are the small, ≤32-colour
# pictures a stock AGA screen can show without a second rescale.
AGA_SIZES = {
    "face": (64, 64),
    "bust": (160, 212),
    "map": (320, 240),
}
AGA_COLORS = 32

# Reference bust the portrait is designed on. Every other size samples this
# same drawing; nothing is redrawn by hand at the destination size.
REF_W, REF_H = 160, 212

# A square on the reference bust, in reference pixels: crown through chin.
# The face file is this window, not a lanczos shrink of the bust — lanczos
# would melt the clusters back into the smooth painting that was rejected.
FACE_WINDOW = (16, 18, 144, 146)

# ---------------------------------------------------------------- palette
# Thirty-two colours, grouped into ramps. Floyd–Steinberg runs inside one
# ramp at a time: a global palette with a cobalt in it freckles the forehead
# blue wherever a skin highlight is nearer the accent than the next skin.
GROUND = (
    (22, 18, 16),
    (40, 32, 26),
    (62, 50, 40),
    (86, 70, 56),
    (112, 92, 74),
)
SKIN = (
    (72, 44, 36),
    (108, 68, 52),
    (146, 96, 74),
    (178, 126, 98),
    (206, 156, 124),
    (226, 184, 154),
    (242, 208, 182),
)
HAIR = (
    (26, 16, 12),
    (54, 34, 24),
    (86, 56, 36),
    (122, 86, 58),
    (162, 126, 92),
)
ROBE = (
    (36, 24, 18),
    (64, 42, 30),
    (96, 66, 46),
    (128, 92, 66),
    (158, 118, 86),
)
LINEN = (
    (168, 146, 116),
    (198, 178, 148),
    (226, 208, 180),
)
BRASS = (
    (112, 78, 28),
    (168, 124, 48),
    (210, 172, 88),
)
COBALT = (
    (24, 60, 116),
    (55, 142, 240),  # #378EF0
)
EYE = (
    (232, 222, 210),
    (252, 248, 242),
)
PALETTE = GROUND + SKIN + HAIR + ROBE + LINEN + BRASS + COBALT + EYE

# Paper ramp for the region plate. Ink is locked, not dithered, so a road
# stays a road instead of dissolving into the stain beside it.
# Dark to light, same as the portrait ramps: t = 1 is the lit end.
PAPER = (
    (118, 96, 70),
    (150, 126, 92),
    (176, 152, 114),
    (196, 174, 134),
    (210, 190, 150),
    (224, 206, 168),
    (236, 220, 186),
)
RIVER = (
    (134, 128, 112),
    (162, 154, 134),
    (188, 178, 152),
)
INK = (42, 30, 22)
INK_SOFT = (78, 58, 42)
MAP_PALETTE = PAPER + RIVER + (INK, INK_SOFT)

assert len(PALETTE) == 32 and len(set(PALETTE)) == 32
assert len(MAP_PALETTE) <= 32 and len(set(MAP_PALETTE)) == len(MAP_PALETTE)

# ---------------------------------------------------------------- portrait
# Brother Aldric, drawn for this spike rather than traced off the shipped
# bust: short hair, a close beard, linen at the throat, a borrowed sun-disc.
# Proportions are the usual ones — eyes at mid-skull, nose halfway to the
# chin — because a face that breaks them reads as a mask at 64px.
CX = 80.0
HEAD_CY = 94.0
HEAD_RX = 37.0
HEAD_RY = 50.0
EYE_Y = 93.0
EYE_LX = 66.5
EYE_RX_POS = 93.5
EYE_OPEN_RX = 8.6
EYE_OPEN_RY = 5.0
IRIS_R = 3.15
PUPIL_R = 1.55
BROW_Y = 80.5
NOSE_TOP = 84.0
NOSE_TIP = 116.0
MOUTH_Y = 128.0
CHIN_Y = 145.0
DISC_Y = 176.0
DISC_R = 12.5
COBALT_R = 3.15

KEY = (-0.46, -0.58, 0.67)  # normalised below
FILL = (0.35, -0.12, 0.93)


def _norm3(x: float, y: float, z: float) -> tuple[float, float, float]:
    inv = 1.0 / math.sqrt(x * x + y * y + z * z)
    return x * inv, y * inv, z * inv


KEY = _norm3(*KEY)
FILL = _norm3(*FILL)


def _clamp(v: float, lo: float = 0.0, hi: float = 1.0) -> float:
    return lo if v < lo else hi if v > hi else v


def _hash01(ix: int, iy: int) -> float:
    n = (int(ix) * 374761393 + int(iy) * 668265263) & 0xFFFFFFFF
    n = ((n ^ (n >> 13)) * 1274126177) & 0xFFFFFFFF
    return ((n >> 8) & 0xFFFF) / 65535.0


def _noise(x: float, y: float, scale: float, seed: int) -> float:
    x /= scale
    y /= scale
    x0 = math.floor(x)
    y0 = math.floor(y)
    fx = x - x0
    fy = y - y0
    sx = fx * fx * (3.0 - 2.0 * fx)
    sy = fy * fy * (3.0 - 2.0 * fy)

    def h(ix: int, iy: int) -> float:
        return _hash01(ix + seed * 101, iy + seed * 17)

    v00 = h(x0, y0)
    v10 = h(x0 + 1, y0)
    v01 = h(x0, y0 + 1)
    v11 = h(x0 + 1, y0 + 1)
    return (v00 * (1 - sx) + v10 * sx) * (1 - sy) + (v01 * (1 - sx) + v11 * sx) * sy


def _ramp_rgb(ramp: tuple, t: float) -> tuple[float, float, float]:
    t = _clamp(t)
    pos = t * (len(ramp) - 1)
    i = int(pos)
    if i >= len(ramp) - 1:
        r, g, b = ramp[-1]
        return float(r), float(g), float(b)
    f = pos - i
    a = ramp[i]
    bcol = ramp[i + 1]
    return tuple(a[c] * (1.0 - f) + bcol[c] * f for c in range(3))


def _shade(normal: tuple[float, float, float], bias: float = 0.0) -> float:
    """Key from the upper left, a little fill so the shadow cheek still has features."""
    key = max(0.0, normal[0] * KEY[0] + normal[1] * KEY[1] + normal[2] * KEY[2])
    fill = max(0.0, normal[0] * FILL[0] + normal[1] * FILL[1] + normal[2] * FILL[2])
    light = 0.74 * key + 0.26 * fill
    t = 0.16 + 0.84 * light + bias
    # Edge-on faces go dark. That is the rim that separates the head from the
    # ground; without it a brown beard sits on a brown ground and disappears.
    t *= 0.78 + 0.22 * max(0.0, normal[2])
    return _clamp(t)


def _skull_r2(x: float, y: float) -> float:
    """Egg with a narrower chin. A pure ellipse reads as a balloon at this size."""
    dx = (x - CX) / HEAD_RX
    dy = (y - HEAD_CY) / HEAD_RY
    if dy > 0.0:
        dx /= 1.0 - 0.30 * min(dy, 0.98)
    else:
        dx /= 1.0 + 0.05 * min(-dy, 1.0)
    return dx * dx + dy * dy


def _skull_normal(x: float, y: float) -> tuple[float, float, float] | None:
    r2 = _skull_r2(x, y)
    if r2 >= 1.0:
        return None
    eps = 0.85
    gx = (_skull_r2(x + eps, y) - _skull_r2(x - eps, y)) / (2.0 * eps)
    gy = (_skull_r2(x, y + eps) - _skull_r2(x, y - eps)) / (2.0 * eps)
    z = math.sqrt(max(1e-4, 1.0 - r2))
    return _norm3(gx / (2.0 * z), gy / (2.0 * z), 1.0)


def _ellipse_r2(x: float, y: float, cx: float, cy: float, rx: float, ry: float) -> float:
    return ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2


def _ellipse_normal(x: float, y: float, cx: float, cy: float, rx: float, ry: float):
    dx = (x - cx) / rx
    dy = (y - cy) / ry
    r2 = dx * dx + dy * dy
    if r2 >= 1.0:
        return None
    z = math.sqrt(1.0 - r2)
    # Squash back into pixel space so a flat ellipse doesn't light like a sphere.
    return _norm3(dx / rx, dy / ry, z / min(rx, ry)), r2


def _hairline(x: float) -> float:
    t = (x - CX) / HEAD_RX
    return 66.0 + 7.5 * t * t


def _in_face(x: float, y: float) -> bool:
    return _skull_r2(x, y) < 1.0 and y >= _hairline(x)


# Locks of hair. Each is its own little volume, which is what stops the hair
# reading as a helmet. Biases push a few locks toward grey at the temples.
HAIR_CLUMPS = (
    (80, 46, 36, 18, 0.0),
    (61, 44, 15, 13, 0.02),
    (99, 43, 15, 13, 0.0),
    (48, 64, 13, 18, 0.04),
    (112, 64, 13, 18, 0.02),
    (42, 82, 9, 13, 0.05),
    (118, 82, 9, 13, 0.03),
    (70, 36, 12, 11, 0.0),
    (91, 35, 12, 10, 0.02),
    (56, 54, 10, 11, 0.0),
    (104, 52, 10, 11, 0.0),
    (80, 32, 16, 9, 0.0),
)


def _ground_t(x: float, y: float) -> float:
    vign = math.hypot((x - 80.0) / 86.0, (y - 100.0) / 120.0)
    vign_t = _clamp(1.08 - vign)
    n = 0.62 * _noise(x, y, 26.0, 3) + 0.38 * _noise(x, y, 9.0, 8)
    grain = (_hash01(int(x), int(y)) - 0.5) * 0.08
    # Centre is warm umber, the way the mockup's portrait ground is; the
    # corners fall off to charcoal so the figure still sits in the chrome.
    t = 0.20 + 0.62 * vign_t + 0.28 * (n - 0.40) + grain
    for cx, cy, rad, amp in (
        (36, 30, 26, -0.07),
        (124, 48, 22, 0.06),
        (28, 170, 30, -0.05),
        (118, 150, 28, 0.05),
        (80, 70, 34, 0.04),
    ):
        d = math.hypot(x - cx, y - cy) / rad
        if d < 1.0:
            t += amp * (1.0 - d * d)
    return _clamp(t)


def _skin_t(x: float, y: float, normal: tuple[float, float, float]) -> float:
    t = _shade(normal)
    # Eye sockets. Painted, not drawn: a darkening, so the eyes sit in the
    # head instead of on it.
    for cx in (EYE_LX, EYE_RX_POS):
        r2 = _ellipse_r2(x, y, cx, EYE_Y + 0.4, 12.0, 8.0)
        if r2 < 1.0:
            t -= 0.20 * (1.0 - r2)
    # Brow ridge catches the key light just above the sockets.
    for cx in (EYE_LX, EYE_RX_POS):
        r2 = _ellipse_r2(x, y, cx, BROW_Y - 1.0, 10.0, 3.4)
        if r2 < 1.0:
            t += 0.07 * (1.0 - r2)
    # Nose as a form. The bridge is a highlight, the far side and the nostrils
    # are shadow. A line would read as a cartoon at 64px; a value change reads
    # as a nose.
    if NOSE_TOP <= y <= NOSE_TIP + 3.5:
        span = _clamp((y - NOSE_TOP) / (NOSE_TIP - NOSE_TOP))
        half = 1.5 + span * 4.6
        dx = x - CX
        ax = abs(dx)
        if ax <= half:
            u = dx / half
            t += 0.10 * (1.0 - abs(u)) * (0.35 + 0.65 * span)
            if dx > 0.6:
                t -= 0.09 * min(1.0, dx / half)
            if span > 0.72 and ax > half * 0.42:
                t -= 0.16
            if span > 0.82 and ax < half * 0.28:
                t += 0.06
        elif ax < half + 3.0 and dx > 0.0 and span > 0.3:
            t -= 0.05 * (1.0 - (ax - half) / 3.0)
        if NOSE_TIP <= y <= NOSE_TIP + 3.2 and ax < half + 1.0:
            t -= 0.14 * (1.0 - (y - NOSE_TIP) / 3.2)
    # Cheeks. A little warmer and lighter on the lit side, so the face isn't
    # one gradient from forehead to chin.
    for cx, amp in ((CX - 15.0, 0.07), (CX + 14.0, 0.04)):
        r2 = _ellipse_r2(x, y, cx, EYE_Y + 16.0, 11.0, 8.0)
        if r2 < 1.0:
            t += amp * (1.0 - r2)
    # Mouth. Corners sit a hair higher than the centre: a closed, patient
    # smile, not a grin. The line is dark skin, the lower lip catches light.
    half = 9.2
    dx = x - (CX + 0.4)
    if abs(dx) < half + 2.0:
        curve = 2.3 * (dx / half) ** 2
        line = MOUTH_Y - curve
        dist = y - line
        if abs(dist) <= 1.15 and abs(dx) < half:
            t -= 0.34 * (1.0 - abs(dist) / 1.15)
        if 0.4 < dist < 4.6 and abs(dx) < half * 0.92:
            lip_u = dx / (half * 0.92)
            lip_v = (dist - 0.4) / 4.2
            if lip_u * lip_u + ((lip_v - 0.35) / 0.75) ** 2 < 1.0:
                t += 0.05 - 0.10 * lip_v
        if -2.8 < dist < -0.2 and abs(dx) < half * 0.78:
            t -= 0.06
    # Nasolabial shade, very slight. Enough to break the egg, not enough to
    # make him old.
    for sign in (-1.0, 1.0):
        fx = CX + sign * (4.0 + 0.16 * (y - NOSE_TIP))
        if NOSE_TIP - 2.0 < y < MOUTH_Y + 2.0 and abs(x - fx) < 1.6:
            t -= 0.05
    # Low-frequency blotches, not just grain. A forehead that lands on one
    # ramp stop dithers to a flat field, which is the smooth look again.
    blot = (_noise(x, y, 6.5, 11) - 0.5) * 0.11
    grain = (_hash01(int(x * 2), int(y * 2)) - 0.5) * 0.04
    return _clamp(t + blot + grain)


def _hair_t(normal: tuple[float, float, float], bias: float, x: float, y: float) -> float:
    # The mass stays dark. Highlights are a few locks, not a blonde cap —
    # a light crown against a light ground loses the silhouette.
    t = _shade(normal, bias - 0.14)
    key = max(0.0, normal[0] * KEY[0] + normal[1] * KEY[1] + normal[2] * KEY[2])
    if key > 0.80:
        t += (key - 0.80) * 0.70
    if x < 54.0 or x > 106.0:
        t += 0.08
    t += (_hash01(int(x), int(y)) - 0.5) * 0.06
    return _clamp(t)


def _robe_t(x: float, y: float) -> float:
    nx = (x - CX) / 72.0
    nx = max(-1.0, min(1.0, nx))
    nz = math.sqrt(max(0.0, 1.0 - nx * nx))
    t = _shade((nx * 0.85, 0.12, nz))
    # Folds: vertical grooves, plus a deeper one under the disc where the
    # cloth actually parts.
    groove = math.sin((x - CX) * 0.145) ** 2
    t -= 0.10 * groove
    if abs(x - CX) < 7.0 and y > DISC_Y:
        t -= 0.07
    t -= 0.06 * _clamp((y - 168.0) / 44.0)
    # Chin shadow falling down-right of the key light.
    r2 = _ellipse_r2(x, y, CX + 6.0, 152.0, 26.0, 10.0)
    if r2 < 1.0 and y > 146.0:
        t -= 0.10 * (1.0 - r2)
    t += (_noise(x, y, 7.0, 5) - 0.5) * 0.06
    return _clamp(t)


def _linen_t(x: float, y: float) -> float:
    nx = (x - CX) / 40.0
    t = 0.45 + 0.40 * (1.0 - abs(nx))
    t += (_hash01(int(x * 3), int(y)) - 0.5) * 0.05
    if y > 154:
        t -= 0.08
    return _clamp(t)


def _in_linen(x: float, y: float) -> bool:
    """A V of linen at the throat. The opening stays empty so the neck and the
    disc read as themselves, not as a white bib."""
    if y < 140.0 or y > 166.0:
        return False
    gap = 2.4 + (y - 140.0) * 0.55
    outer = gap + 8.5
    ax = abs(x - CX)
    if gap <= ax <= outer and y <= 160.0:
        return True
    # Shoulder band of the same cloth, outside the V.
    if 150.0 <= y <= 160.0 and outer - 1.0 <= ax <= 30.0:
        return True
    return False


def _in_cowl(x: float, y: float) -> bool:
    return _ellipse_r2(x, y, CX, 152.0, 34.0, 16.0) <= 1.0 and y >= 136.0


def _in_robe(x: float, y: float) -> bool:
    if y < 154.0:
        return False
    if _ellipse_r2(x, y, CX, 196.0, 86.0, 62.0) <= 1.0:
        return True
    return y >= 188.0 and abs(x - CX) < 78.0


def _in_neck(x: float, y: float) -> bool:
    return _ellipse_r2(x, y, CX, 138.0, 13.0, 20.0) <= 1.0 and 124.0 <= y <= 158.0


def _in_moustache(x: float, y: float) -> bool:
    if _ellipse_r2(x, y, CX + 0.3, 122.2, 11.0, 3.3) > 1.0:
        return False
    # Philtrum. Without the gap the nose and the moustache fuse into a snout.
    if abs(x - CX) < 2.1 and y < 121.6:
        return False
    return True


def _beard_mask(x: float, y: float) -> float:
    if y < 132.0 or y > CHIN_Y + 2.0:
        return 0.0
    r2 = _ellipse_r2(x, y, CX, 134.0, 27.0, 15.5)
    if r2 > 1.0:
        return 0.0
    if _skull_r2(x, y) > 1.02 and y < CHIN_Y - 1.0:
        return 0.0
    # Leave the mouth.
    if _ellipse_r2(x, y, CX + 0.4, MOUTH_Y + 0.5, 10.5, 6.5) < 1.0 and y < 134.5:
        return 0.0
    fade = _clamp((y - 132.0) / 4.5)
    edge = _clamp((1.0 - r2) / 0.22)
    return fade * edge


def _in_sideburn(x: float, y: float) -> bool:
    for cx in (CX - 30.0, CX + 30.0):
        if _ellipse_r2(x, y, cx, 112.0, 5.5, 20.0) <= 1.0:
            return True
    return False


def _in_ear(x: float, y: float) -> bool:
    for cx in (CX - 42.0, CX + 42.0):
        if _ellipse_r2(x, y, cx, 98.0, 6.5, 11.0) <= 1.0:
            return True
    return False


def _ear_t(x: float, y: float) -> float:
    for cx, lit in ((CX - 42.0, 0.06), (CX + 42.0, -0.02)):
        hit = _ellipse_normal(x, y, cx, 98.0, 6.5, 11.0)
        if not hit:
            continue
        normal, _r2 = hit
        t = _shade(normal, lit)
        inner = _ellipse_r2(x, y, cx + (2.2 if cx < CX else -2.2), 99.0, 2.4, 5.5)
        if inner < 1.0:
            t -= 0.18 * (1.0 - inner)
        return _clamp(t)
    return 0.5


def _hair_hit(x: float, y: float):
    """Front-most lock covering this pixel, or the base cap behind them."""
    if _in_face(x, y):
        return None
    best = None
    for cx, cy, rx, ry, bias in HAIR_CLUMPS:
        hit = _ellipse_normal(x, y, cx, cy, rx, ry)
        if not hit:
            continue
        normal, r2 = hit
        score = normal[2] - r2 * 0.15
        if best is None or score > best[0]:
            best = (score, normal, bias, r2)
    cap = _ellipse_normal(x, y, CX, 72.0, 46.0, 42.0)
    if cap and not _in_face(x, y):
        normal, r2 = cap
        # Cap only fills what the locks don't, and only around the skull —
        # otherwise it becomes a brown oval behind the shoulders.
        if _skull_r2(x, y) < 1.55 or y < 100.0:
            if best is None:
                best = (normal[2] - 1.0, normal, 0.0, r2)
    if best is None:
        return None
    return best[1], best[2]


def _disc_t(x: float, y: float) -> float | None:
    dx = (x - CX) / DISC_R
    dy = (y - DISC_Y) / DISC_R
    r2 = dx * dx + dy * dy
    if r2 >= 1.0:
        return None
    z = math.sqrt(1.0 - r2)
    return _shade((dx, dy, z), 0.05)


def _crisp(x: float, y: float, px: float) -> tuple[int, int, int] | None:
    """Features that have to stay crisp: eyes, the sun-disc's stone, its rays.
    Everything else is allowed to dither."""
    # Sun stone and rays, in front of the robe.
    dx = x - CX
    dy = y - DISC_Y
    dist = math.hypot(dx, dy)
    if dist <= COBALT_R:
        return COBALT[1] if dist <= COBALT_R * 0.45 else COBALT[0]
    if DISC_R + 0.6 < dist < DISC_R + 5.4:
        for i in range(8):
            ang = i * math.pi / 4.0 - math.pi / 2.0
            cross = abs(dx * math.sin(ang) - dy * math.cos(ang))
            along = dx * math.cos(ang) + dy * math.sin(ang)
            if along > 0.0 and cross <= 1.15:
                return BRASS[1] if cross < 0.45 else BRASS[0]
    # Cord from the collar to the disc.
    if abs(dx) <= max(0.7, px * 0.65) and 162.0 <= y <= DISC_Y - DISC_R:
        return HAIR[0]

    # Eyes. Both irises share one gaze offset; opposing offsets read as a squint
    # crossed the wrong way.
    gaze_x, gaze_y = 0.35, 0.45
    for ex, inward in ((EYE_LX, 1.0), (EYE_RX_POS, -1.0)):
        colour = _eye(x, y, ex, EYE_Y, inward, gaze_x, gaze_y, px)
        if colour is not None:
            return colour
    # Nostrils, locked so dither cannot sand them off at 64px.
    for sx in (-3.1, 3.1):
        if _ellipse_r2(x, y, CX + sx, NOSE_TIP + 0.6, 1.55, 1.15) <= 1.0:
            return SKIN[0]
    # Mouth line, locked for the same reason: a one-pixel darkening in a
    # bright cheek gets error-diffused back to the cheek colour, and at 64px
    # the face then has no mouth.
    half = 8.4
    dx = x - (CX + 0.4)
    if abs(dx) <= half:
        curve = 2.1 * (dx / half) ** 2
        line = MOUTH_Y - curve
        if abs(y - line) <= max(1.15, px * 0.75):
            return SKIN[0] if abs(dx) < half - 0.6 else SKIN[1]
    return None


def _eye(
    x: float, y: float, ex: float, ey: float, inward: float,
    gaze_x: float, gaze_y: float, px: float,
) -> tuple[int, int, int] | None:
    lx = (x - ex) / EYE_OPEN_RX
    ly = (y - ey) / EYE_OPEN_RY
    # Upper lash, one output-pixel thick, just above the opening.
    lash = max(1.05, px * 1.15)
    above = ey - EYE_OPEN_RY
    if abs(x - ex) <= EYE_OPEN_RX * 0.96 and above - lash * 0.2 <= y <= above + lash:
        arch = 1.6 * (1.0 - ((x - ex) / EYE_OPEN_RX) ** 2)
        if abs(y - (above + 0.3 - arch * 0.15)) <= lash * 0.55:
            return HAIR[0]
    if lx * lx + ly * ly > 1.0:
        return None
    # Lids cut the ellipse into an opening. A full ellipse is a startled eye.
    if ly < -0.18 or ly > 0.72:
        return None
    ix = ex + inward * 0.15 + gaze_x
    iy = ey + gaze_y
    d = math.hypot(x - ix, y - iy)
    # Catchlight before the pupil. It sits on the lit side of the iris, and
    # the face window's samples are two reference pixels apart, so the only
    # sample near it may also fall inside the pupil. A white pixel there is
    # the catchlight; letting the pupil win deletes it.
    catch_r = max(1.15, px * 0.7)
    if d <= IRIS_R and math.hypot(x - (ix - 1.45), y - (iy - 1.35)) <= catch_r:
        return EYE[1]
    if d <= PUPIL_R:
        return HAIR[0]
    if d <= IRIS_R:
        if x < ix - 0.2 and y > iy:
            return HAIR[3]
        return HAIR[2]
    return EYE[0]


def _brow(x: float, y: float) -> tuple[float, float, float] | None:
    """Returns hair rgb, or None. Arched, thicker toward the nose, relaxed."""
    spans = (
        (EYE_LX - 9.2, EYE_LX + 8.4, BROW_Y + 0.4, 1.1, 3.1),
        (EYE_RX_POS - 8.4, EYE_RX_POS + 9.2, BROW_Y - 0.2, -1.1, 3.1),
    )
    for x0, x1, y_base, tilt, arch in spans:
        if x < x0 - 0.8 or x > x1 + 0.8:
            continue
        t = (x - x0) / (x1 - x0)
        # Outer is x0 on the left brow and x1 on the right. `tilt` raises the
        # outer end: positive tilt lowers the inner end of the left brow.
        cy = y_base + tilt * (t if x0 < CX else (1.0 - t)) - arch * 4.0 * t * (1.0 - t)
        inner = t if x0 < CX else (1.0 - t)
        thick = 1.55 + 1.15 * inner
        if abs(y - cy) <= thick:
            bias = 0.10 + 0.20 * (abs(y - cy) / thick)
            return _ramp_rgb(HAIR, _clamp(bias))
    return None


def sample_bust(x: float, y: float, px: float = 1.0):
    """One reference-space pixel of Aldric.

    Returns ``(r, g, b, ramp_or_None)``. A ramp of None is a locked colour
    (eyes, stone, rays) that dither must not move.
    """
    locked = _crisp(x, y, px)
    if locked is not None:
        return (float(locked[0]), float(locked[1]), float(locked[2]), None)

    brow = _brow(x, y)
    if brow is not None and _in_face(x, y):
        return (*brow, HAIR)

    if _in_moustache(x, y):
        return (*_ramp_rgb(HAIR, _hair_t((0.1, -0.2, 0.97), -0.22, x, y)), HAIR)

    beard = _beard_mask(x, y)
    if beard > 0.55:
        return (*_ramp_rgb(HAIR, _hair_t((0.0, 0.25, 0.97), -0.08 + beard * 0.05, x, y)), HAIR)

    if _in_face(x, y):
        normal = _skull_normal(x, y)
        if normal is not None:
            return (*_ramp_rgb(SKIN, _skin_t(x, y, normal)), SKIN)

    if _in_sideburn(x, y) and not _in_face(x, y):
        return (*_ramp_rgb(HAIR, _hair_t((-0.2, 0.1, 0.95), 0.02, x, y)), HAIR)

    if _in_ear(x, y) and not _in_face(x, y):
        return (*_ramp_rgb(SKIN, _ear_t(x, y)), SKIN)

    hair = _hair_hit(x, y)
    if hair is not None:
        normal, bias = hair
        return (*_ramp_rgb(HAIR, _hair_t(normal, bias, x, y)), HAIR)

    if _in_linen(x, y):
        return (*_ramp_rgb(LINEN, _linen_t(x, y)), LINEN)

    if _in_neck(x, y) and not _in_face(x, y):
        # Under the chin the neck is in shadow regardless of the cylinder.
        t = 0.28 + 0.22 * (1.0 - abs((x - CX) / 13.0))
        if y < 148.0:
            t -= 0.10
        return (*_ramp_rgb(SKIN, _clamp(t)), SKIN)

    disc = _disc_t(x, y)
    if disc is not None:
        return (*_ramp_rgb(BRASS, disc), BRASS)

    if _in_cowl(x, y) and not _in_face(x, y):
        return (*_ramp_rgb(ROBE, _clamp(_robe_t(x, y) - 0.10)), ROBE)

    if _in_robe(x, y):
        return (*_ramp_rgb(ROBE, _robe_t(x, y)), ROBE)

    return (*_ramp_rgb(GROUND, _ground_t(x, y)), GROUND)


def _dither(pixels: list, w: int, h: int) -> list[tuple[int, int, int]]:
    """Floyd–Steinberg, serpentine, confined to each pixel's own ramp.

    Locked pixels (ramp is None) are already palette colours. Error that
    would land on one is dropped, so the catchlight stays a catchlight.
    """
    buf = [[p[0], p[1], p[2]] for p in pixels]
    locked = [p[3] is None for p in pixels]
    ramps = [p[3] for p in pixels]
    out: list[tuple[int, int, int] | None] = [None] * (w * h)

    def nearest(r: float, g: float, b: float, ramp: tuple) -> tuple[int, int, int]:
        best = ramp[0]
        bestd = 1e18
        for colour in ramp:
            d = (r - colour[0]) ** 2 + (g - colour[1]) ** 2 + (b - colour[2]) ** 2
            if d < bestd:
                bestd = d
                best = colour
        return best

    for y in range(h):
        forward = (y % 2) == 0
        xs = range(w) if forward else range(w - 1, -1, -1)
        step = 1 if forward else -1
        for x in xs:
            i = y * w + x
            if locked[i]:
                src = pixels[i]
                out[i] = (int(src[0]), int(src[1]), int(src[2]))
                continue
            r, g, b = buf[i]
            r = _clamp(r, 0.0, 255.0)
            g = _clamp(g, 0.0, 255.0)
            b = _clamp(b, 0.0, 255.0)
            nr, ng, nb = nearest(r, g, b, ramps[i])
            out[i] = (nr, ng, nb)
            er, eg, eb = r - nr, g - ng, b - nb
            for tx, ty, factor in (
                (x + step, y, 7 / 16),
                (x - step, y + 1, 3 / 16),
                (x, y + 1, 5 / 16),
                (x + step, y + 1, 1 / 16),
            ):
                if tx < 0 or ty < 0 or tx >= w or ty >= h:
                    continue
                j = ty * w + tx
                if locked[j]:
                    continue
                buf[j][0] += er * factor
                buf[j][1] += eg * factor
                buf[j][2] += eb * factor
    return out  # type: ignore[return-value]


def _render_window(size: tuple[int, int], window: tuple[float, float, float, float]):
    from PIL import Image

    w, h = size
    x0, y0, x1, y1 = window
    px = ((x1 - x0) / w + (y1 - y0) / h) / 2.0
    raw = []
    for y in range(h):
        fy = y0 + (y + 0.5) / h * (y1 - y0)
        for x in range(w):
            fx = x0 + (x + 0.5) / w * (x1 - x0)
            raw.append(sample_bust(fx, fy, px))
    image = Image.new("RGB", (w, h))
    image.putdata(_dither(raw, w, h))
    return image


def render_aldric_bust(size: tuple[int, int] = AGA_SIZES["bust"]):
    """Waist-up Aldric at ``size``. Default is the AGA preview, 160×212."""
    if size[0] > 384 or size[1] > 512:
        raise ValueError(f"bust {size[0]}×{size[1]} exceeds the 384×512 contract")
    return _render_window(size, (0.0, 0.0, float(REF_W), float(REF_H)))


def render_aldric_face(size: tuple[int, int] = AGA_SIZES["face"]):
    """The head of the same drawing, framed for the party rail."""
    if size[0] > 144 or size[1] > 144:
        raise ValueError(f"face {size[0]}×{size[1]} exceeds the 144×144 contract")
    return _render_window(size, FACE_WINDOW)


# ---------------------------------------------------------------- map
def _rivermark_segments() -> list[tuple[tuple[float, float], tuple[float, float]]]:
    """Public roads of Rivermark, in the chart's 0–100 by 0–75 field.

    Hidden exits and rooms the chart keeps dark until you have been there are
    left off. This plate is the town a party can already see; the client draws
    the discs and the names, so neither is baked in.
    """
    with open(RIVERMARK_JSON, encoding="utf-8") as fh:
        data = json.load(fh)
    locs = data["locations"]
    segs = set()
    for key, loc in locs.items():
        if loc.get("hidden_until_visited"):
            continue
        here = tuple(loc["map_pos"])
        for exit_ in loc.get("exits") or []:
            if exit_.get("hidden"):
                continue
            other = locs.get(exit_.get("to"))
            if not other or other.get("hidden_until_visited"):
                continue
            there = tuple(other["map_pos"])
            segs.add(tuple(sorted((here, there))))
    return [(a, b) for a, b in sorted(segs)]


def _bresenham(x0: int, y0: int, x1: int, y1: int):
    dx = abs(x1 - x0)
    dy = -abs(y1 - y0)
    sx = 1 if x0 < x1 else -1
    sy = 1 if y0 < y1 else -1
    err = dx + dy
    while True:
        yield x0, y0
        if x0 == x1 and y0 == y1:
            break
        e2 = 2 * err
        if e2 >= dy:
            err += dy
            x0 += sx
        if e2 <= dx:
            err += dx
            y0 += sy


def render_rivermark_map(size: tuple[int, int] = AGA_SIZES["map"]):
    """Warm parchment, a river bank, and the town's roads. No labels, no discs.

    The blue current-location marker in the explore mockup is the client's,
    and so are the names. Baking either would put a second copy under the
    chart the day this plate is promoted.
    """
    from PIL import Image

    w, h = size
    if w > 1536 or h > 1152:
        raise ValueError(f"map {w}×{h} exceeds the 1536×1152 contract")

    def bank_y(px: float) -> float:
        u = px / max(1, w - 1)
        wave = 0.012 * math.sin(u * 7.0) + 0.008 * math.sin(u * 19.0 + 1.3)
        return h * (0.115 + wave)

    raw = []
    ramps = []
    for y in range(h):
        for x in range(w):
            u = x / max(1, w - 1)
            v = y / max(1, h - 1)
            n = 0.50 * _noise(x, y, 22.0, 2) + 0.30 * _noise(x, y, 8.0, 4) + 0.20 * _noise(x, y, 3.0, 9)
            edge = min(u, v, 1.0 - u, 1.0 - v)
            vign = _clamp(edge / 0.08)
            # Light sheet. A regular laid line reads as graph paper under the
            # chart's own roads, so the tooth is noise, not a ruling.
            t = 0.74 + 0.14 * (n - 0.5) - 0.10 * (1.0 - vign)
            for cx, cy, rad, amp in (
                (0.22, 0.30, 0.16, -0.08),
                (0.74, 0.62, 0.14, -0.06),
                (0.48, 0.18, 0.10, 0.04),
                (0.85, 0.22, 0.08, -0.05),
            ):
                d = math.hypot((u - cx) / rad, (v - cy) / (rad * 0.8))
                if d < 1.0:
                    t += amp * (1.0 - d * d)
            by = bank_y(x)
            if y < by - 1.0:
                # River. Cooler than the paper, still brown — a blue wash would
                # fight the client's own cobalt marker.
                depth = _clamp((by - y) / (h * 0.10))
                rt = _clamp(0.25 + 0.6 * (1.0 - depth) + 0.1 * (n - 0.5))
                raw.append(_ramp_rgb(RIVER, rt))
                ramps.append(RIVER)
            else:
                raw.append(_ramp_rgb(PAPER, _clamp(t)))
                ramps.append(PAPER)

    locked = [None] * (w * h)

    def stamp(px: int, py: int, colour: tuple[int, int, int], radius: int) -> None:
        for oy in range(-radius, radius + 1):
            for ox in range(-radius, radius + 1):
                if ox * ox + oy * oy > radius * radius + radius:
                    continue
                sx, sy = px + ox, py + oy
                if 0 <= sx < w and 0 <= sy < h:
                    locked[sy * w + sx] = colour

    # Bank, one pixel, softer than the roads so it reads as shore not street.
    for x in range(w):
        stamp(x, int(round(bank_y(x))), INK_SOFT, 0)

    # A few cracks in the sheet. They are not roads: they don't join the graph
    # and they use the soft ink.
    cracks = (
        ((0.08, 0.22), (0.16, 0.28), (0.18, 0.36)),
        ((0.70, 0.16), (0.78, 0.20), (0.84, 0.18)),
        ((0.40, 0.70), (0.46, 0.78), (0.44, 0.86)),
        ((0.12, 0.62), (0.20, 0.66)),
    )
    for crack in cracks:
        pts = [(p[0] * (w - 1), p[1] * (h - 1)) for p in crack]
        for (ax, ay), (bx, by) in zip(pts, pts[1:]):
            for px, py in _bresenham(int(ax), int(ay), int(bx), int(by)):
                stamp(px, py, INK_SOFT, 0)

    for (x0, y0), (x1, y1) in _rivermark_segments():
        ax = x0 / 100.0 * (w - 1)
        ay = y0 / 75.0 * (h - 1)
        bx = x1 / 100.0 * (w - 1)
        by = y1 / 75.0 * (h - 1)
        for px, py in _bresenham(int(round(ax)), int(round(ay)), int(round(bx)), int(round(by))):
            # Two pixels. One is a hairline that vanishes beside the client's
            # own roads; two still reads as crisp ink on this preview.
            stamp(px, py, INK, 1)

    pixels = []
    for i, rgb in enumerate(raw):
        if locked[i] is not None:
            colour = locked[i]
            pixels.append((float(colour[0]), float(colour[1]), float(colour[2]), None))
        else:
            pixels.append((rgb[0], rgb[1], rgb[2], ramps[i]))
    image = Image.new("RGB", (w, h))
    image.putdata(_dither(pixels, w, h))
    return image


def write_aga_samples(output_dir: str = SPIKE_ART) -> list[str]:
    """Write the three AGA previews. No image service, no production PNGs."""
    try:
        from PIL import Image  # noqa: F401
    except ImportError as exc:
        raise RuntimeError("Pillow is required: pip install --user Pillow") from exc

    jobs = (
        ("npcs/aldric_face.png", render_aldric_face()),
        ("npcs/aldric_bust.png", render_aldric_bust()),
        ("maps/rivermark.png", render_rivermark_map()),
    )
    written = []
    for relative, image in jobs:
        dest = os.path.join(output_dir, relative)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        image.save(dest, format="PNG", optimize=True)
        written.append(dest)
    return written


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--samples",
        action="store_true",
        help="paint Aldric's face and bust, and a Rivermark parchment, at AGA size",
    )
    args = parser.parse_args()
    if not args.samples:
        parser.error("pass --samples")
    try:
        for path in write_aga_samples():
            print(os.path.relpath(path, ROOT))
    except (OSError, RuntimeError, ValueError) as exc:
        parser.error(str(exc))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
