#!/usr/bin/env python3
"""
Generate assets/css/dice.css — the face and rest transforms for a CSS 3D d20 and d6.

Why this is generated rather than written
-----------------------------------------
A d20 needs 20 face transforms and 20 resting orientations. Nobody should
hand-maintain forty 3x4 matrices, and the well-known CSS d20 demos all ship them
as opaque literals lifted from each other. Deriving them from the actual
icosahedron means the numbers can be re-derived when the size changes, and a
mistake is a mistake in ten lines of geometry rather than in a wall of constants.

Two coordinate systems, and the conversion between them is where these things
usually go wrong:

  * Maths, here: x right, y UP, z toward the viewer. Right-handed.
  * CSS:         x right, y DOWN, z toward the viewer. Left-handed.

So every vector crosses over via `to_css`, which negates y. That is a reflection,
not a rotation, which is exactly right: it is what makes a left-handed system out
of a right-handed one.

Faces are emitted as matrix3d. Exact, and they never animate.

Resting orientations are emitted as `rotateX() rotateY() rotateZ()` instead, and
that is deliberate. CSS interpolates two matrix3d values by decomposing to
quaternions and taking the shortest arc, so `matrix3d(...720 degrees of spin...)`
animates as no spin at all — the turns are lost in the decomposition. Matching
lists of rotate functions interpolate componentwise, so rotateX(1080deg) really is
three tumbles. The whole throw depends on this.

Usage:
    python3 tools/gen_dice_css.py            # write assets/css/dice.css
    python3 tools/gen_dice_css.py --check     # verify only, exit 1 on drift
    python3 tools/gen_dice_css.py --stdout    # print it
"""

from __future__ import annotations

import math
import os
import sys
from itertools import combinations

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "assets", "css", "dice.css")

# The same resting angles again, for JavaScript.
#
# The stylesheet's .show-N classes are the no-script and reduced-motion answer,
# but a throw needs whole turns added to those angles and CSS cannot do
# arithmetic on a class. Reading the angles back out of a computed style is no
# help either: the browser hands back a matrix, and the turns are exactly what a
# matrix cannot represent. So the numbers are emitted twice, from one derivation.
OUT_JS = os.path.join(ROOT, "assets", "js", "dice-geometry.js")

# Size of one triangular face's edge, in pixels, for the d20; and the cube edge
# for the d6. Everything else is derived, so these two numbers are the only
# dials — change them and re-run.
D20_EDGE = 92.0
D6_EDGE = 40.0

# The three later solids are dialled by circumradius instead of edge, because
# what matters is that a d8, a d10 and a d12 read as one set — and the edge that
# produces that differs per solid, so edge is the wrong dial. These three are
# tuned to settle at about 96px across, which the :root footprints confirm.
#
# The d6 stays at its own smaller scale: it is sized for a row of four on the
# creation screen, where the tray spacing is derived from it. A real dice set has
# the smallest d6 in it too, so nothing here tries to equalise them.
D8_RADIUS = 56.5
D10_RADIUS = 28.8
D12_RADIUS = 48.0

# Must match `perspective` on .die-stage in style.css. The projected silhouette
# depends on it, so the two cannot be chosen independently.
PERSPECTIVE = 900.0

PHI = (1.0 + math.sqrt(5.0)) / 2.0


# ---------------------------------------------------------------------------
# Small vector helpers. Deliberately not numpy: this script has to run on the
# host with nothing installed, like every other tool in here.
# ---------------------------------------------------------------------------

def sub(a, b):
    return (a[0] - b[0], a[1] - b[1], a[2] - b[2])


def add(a, b):
    return (a[0] + b[0], a[1] + b[1], a[2] + b[2])


def scale(a, k):
    return (a[0] * k, a[1] * k, a[2] * k)


def dot(a, b):
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]


def cross(a, b):
    return (
        a[1] * b[2] - a[2] * b[1],
        a[2] * b[0] - a[0] * b[2],
        a[0] * b[1] - a[1] * b[0],
    )


def norm(a):
    return math.sqrt(dot(a, a))


def unit(a):
    n = norm(a)
    if n < 1e-12:
        raise ValueError("cannot normalise a zero vector")
    return scale(a, 1.0 / n)


def to_css(v):
    """Maths (y up) to CSS (y down)."""
    return (v[0], -v[1], v[2])


# ---------------------------------------------------------------------------
# Solids
# ---------------------------------------------------------------------------

def icosahedron(edge: float):
    """
    The 12 vertices and 20 faces of a regular icosahedron centred on the origin.

    The canonical vertex set has edge length 2, so it is scaled by edge/2.
    Faces are found by taking every triple of vertices that are mutually one
    edge apart — there are exactly 20, which the caller asserts.
    """
    raw = []
    for s1 in (-1, 1):
        for s2 in (-1, 1):
            raw.append((0.0, s1 * 1.0, s2 * PHI))
            raw.append((s1 * 1.0, s2 * PHI, 0.0))
            raw.append((s1 * PHI, 0.0, s2 * 1.0))

    verts = [scale(v, edge / 2.0) for v in raw]

    faces = []
    for i, j, k in combinations(range(len(verts)), 3):
        d = (
            norm(sub(verts[i], verts[j])),
            norm(sub(verts[j], verts[k])),
            norm(sub(verts[i], verts[k])),
        )
        if all(abs(x - edge) < edge * 1e-6 for x in d):
            faces.append((verts[i], verts[j], verts[k]))
    return verts, faces


def cube(edge: float):
    """
    The 6 faces of a cube, in the order 1..6 with opposite faces summing to 7 —
    the arrangement every real die uses, so 1 opposite 6, 2 opposite 5, 3
    opposite 4. Returned as (centre, normal, up) triples.
    """
    h = edge / 2.0
    return [
        # centre,            normal,           local "up"
        ((0, 0, h), (0, 0, 1), (0, 1, 0)),      # 1 front
        ((h, 0, 0), (1, 0, 0), (0, 1, 0)),      # 2 right
        ((0, h, 0), (0, 1, 0), (0, 0, -1)),     # 3 top
        ((0, -h, 0), (0, -1, 0), (0, 0, 1)),    # 4 bottom
        ((-h, 0, 0), (-1, 0, 0), (0, 1, 0)),    # 5 left
        ((0, 0, -h), (0, 0, -1), (0, 1, 0)),    # 6 back
    ]


def octahedron(radius: float):
    """The 6 vertices and 8 triangular faces of a regular octahedron."""
    verts = [(radius, 0.0, 0.0), (-radius, 0.0, 0.0),
             (0.0, radius, 0.0), (0.0, -radius, 0.0),
             (0.0, 0.0, radius), (0.0, 0.0, -radius)]
    faces = [((sx * radius, 0.0, 0.0), (0.0, sy * radius, 0.0), (0.0, 0.0, sz * radius))
             for sx in (1, -1) for sy in (1, -1) for sz in (1, -1)]
    return verts, faces


def hull_faces(verts, tol: float):
    """
    The faces of a convex point set, as tuples of the vertices lying on each.

    Every plane through three vertices is tested for having the whole set on one
    side of it; the survivors are the hull's face planes, deduped by normal. Slow
    in principle and instant here — a dodecahedron is 1140 triples.

    Derived rather than declared because a face normal that is asserted can be
    asserted wrongly, and the failure mode is a die with a face missing rather
    than an error. Guessing the dodecahedron's normals was exactly that mistake:
    the twelve directions that look right are the icosahedron's vertices, which
    on this solid point at corners, not faces.
    """
    faces = {}
    for a, b, c in combinations(verts, 3):
        cr = cross(sub(b, a), sub(c, a))
        if norm(cr) < tol:
            continue
        n = unit(cr)
        d = dot(n, a)
        if d < 0:
            n, d = scale(n, -1.0), -d
        if d < tol:
            continue
        if any(dot(n, v) > d + tol for v in verts):
            continue
        key = tuple(round(x, 6) for x in n)
        if key not in faces:
            faces[key] = tuple(v for v in verts if abs(dot(n, v) - d) < tol)
    return list(faces.values())


def dodecahedron(radius: float):
    """
    The 20 vertices and 12 pentagonal faces of a regular dodecahedron.

    The canonical vertex set has circumradius sqrt(3), so it is scaled to the
    radius asked for.
    """
    s = radius / math.sqrt(3.0)
    inv = 1.0 / PHI
    raw = [(float(sx), float(sy), float(sz))
           for sx in (-1, 1) for sy in (-1, 1) for sz in (-1, 1)]
    for s1 in (-1, 1):
        for s2 in (-1, 1):
            raw.append((0.0, s1 * inv, s2 * PHI))
            raw.append((s1 * inv, s2 * PHI, 0.0))
            raw.append((s1 * PHI, 0.0, s2 * inv))
    verts = [scale(v, s) for v in raw]

    faces = hull_faces(verts, radius * 1e-6)
    if len(faces) != 12:
        raise ValueError(f"dodecahedron has {len(faces)} faces, expected 12")
    for f in faces:
        if len(f) != 5:
            raise ValueError(f"dodecahedron face has {len(f)} corners, expected 5")
    return verts, faces


def trapezohedron10(radius: float):
    """
    The 12 vertices and 10 kite faces of a pentagonal trapezohedron — a d10.

    Not a Platonic solid, so its proportions are not free: the four corners of a
    kite are only coplanar when the apex height and the ring height are in a
    fixed ratio. Solving that for the canonical solid — the dual of the uniform
    pentagonal antiprism — gives a ring at height R(1 - cos36) and an apex at
    R(1 + cos36), which is tidier than the derivation deserves.

    Getting it wrong is not subtle: a non-planar "face" is drawn as a flat
    quadrilateral that cannot lie against the neighbouring one, so the die opens
    along its seams as it turns.
    """
    c36 = math.cos(math.radians(36.0))
    h = radius * (1.0 - c36)
    apex = radius * (1.0 + c36)

    def ring(i, offset, y):
        t = math.radians(72.0 * i + offset)
        return (radius * math.cos(t), y, radius * math.sin(t))

    upper = [ring(i, 0.0, h) for i in range(5)]
    lower = [ring(i, 36.0, -h) for i in range(5)]
    north, south = (0.0, apex, 0.0), (0.0, -apex, 0.0)

    faces = []
    for i in range(5):
        faces.append((north, upper[i], lower[i], upper[(i + 1) % 5]))
    for i in range(5):
        faces.append((south, lower[i], upper[(i + 1) % 5], lower[(i + 1) % 5]))

    verts = [north, south] + upper + lower
    for face in faces:
        n = unit(cross(sub(face[1], face[0]), sub(face[2], face[0])))
        off = max(abs(dot(sub(p, face[0]), n)) for p in face)
        if off > radius * 1e-9:
            raise ValueError(f"trapezohedron face is not planar (off by {off:g})")
    return verts, faces


def order_corners(corners, centre, normal):
    """Corners sorted round the face, so `polygon()` traces a simple outline."""
    n = unit(normal)
    seed = sub(corners[0], centre)
    right = unit(sub(seed, scale(n, dot(seed, n))))
    up = cross(n, right)
    return sorted(
        corners,
        key=lambda p: math.atan2(dot(sub(p, centre), up), dot(sub(p, centre), right)),
    )


def numbered_frames(faces, sides: int, polar_up: bool = False):
    """
    Face frames in die order, numbered so opposite faces sum to sides + 1.

    That is the one rule every real die obeys, and it is the only reason the
    numbering here is not arbitrary. Which face gets the 1 is arbitrary; the
    ordering is made deterministic by sorting on height then angle, so a rebuild
    never reshuffles what dice.css binds by `:nth-child`.

    `polar_up` picks the in-plane "up" for a kite, whose four corners are not
    interchangeable: it must point at the polar apex on every face or the ten
    kites end up rotated differently inside identical clip paths. A triangle or
    a regular pentagon has no such constraint, so any vertex serves.
    """
    prepared = []
    for corners in faces:
        k = 1.0 / len(corners)
        centre = (sum(p[0] for p in corners) * k,
                  sum(p[1] for p in corners) * k,
                  sum(p[2] for p in corners) * k)
        n = unit(cross(sub(corners[1], corners[0]), sub(corners[2], corners[0])))
        if dot(n, centre) < 0:
            n = scale(n, -1.0)
        ordered = order_corners(list(corners), centre, n)
        key = ((lambda p: (round(abs(p[1]), 6), round(p[0], 6), round(p[2], 6))) if polar_up
               else (lambda p: (round(p[1], 6), round(p[0], 6), round(p[2], 6))))
        prepared.append((centre, n, sub(max(ordered, key=key), centre), ordered))

    prepared.sort(key=lambda f: (-round(f[0][1], 6), round(math.atan2(f[0][2], f[0][0]), 6)))

    numbered = [None] * sides
    used = [False] * len(prepared)
    nxt = 1
    for i, frame in enumerate(prepared):
        if used[i]:
            continue
        # Its opposite is the face whose centre is this one's negated.
        j = min(range(len(prepared)), key=lambda k: norm(add(prepared[k][0], frame[0])))
        if j == i or used[j]:
            raise ValueError(f"face {i} has no unused opposite")
        used[i] = used[j] = True
        numbered[nxt - 1] = frame
        numbered[sides - nxt] = prepared[j]
        nxt += 1
    if any(f is None for f in numbered):
        raise ValueError("could not pair every face with an opposite")
    return numbered


# ---------------------------------------------------------------------------
# Orientation
# ---------------------------------------------------------------------------

def face_basis(centre, normal, up):
    """
    The 3x3 that maps a face's own element space into the die's space, in CSS
    coordinates.

    Column 0 is where the element's local +x (right) ends up, column 1 its local
    +y — which in CSS points DOWN the element, hence the negated `up` — and
    column 2 its local +z, which is the outward normal so the face's front side
    faces out and backface-visibility can cull the rest.
    """
    n = unit(normal)
    u = unit(up)
    # Guard against an `up` that is not actually in the face's plane.
    u = unit(sub(u, scale(n, dot(u, n))))
    right = cross(u, n)          # (right, up, n) is right-handed in maths coords

    c0 = to_css(right)
    c1 = to_css(scale(u, -1.0))  # local +y is down
    c2 = to_css(n)
    c3 = to_css(centre)
    return (c0, c1, c2), c3


def face_outline(centre, normal, up, corners):
    """
    A face's own outline, as (x, y) offsets in its element's plane.

    The face elements are flat boxes that `clip-path` cuts to shape, and the
    shape has to agree with the solid exactly or neighbouring faces meet with a
    gutter or an overhang between them. Deriving it from the same vertices the
    3D transform was built from is what keeps them in step — the d20's triangle
    was written by hand, and this reproduces it to the fifth decimal, which is
    the check that says the derivation is right.

    x runs along the face's local right, y DOWN the element, matching CSS and
    therefore matching `face_basis`'s second column.
    """
    n = unit(normal)
    u = unit(sub(up, scale(n, dot(up, n))))
    right = cross(u, n)
    out = []
    for p in corners:
        d = sub(p, centre)
        out.append((dot(d, right), -dot(d, u)))
    return out


def clip_polygon(outline, box: float) -> str:
    """`clip-path: polygon(...)` for one face outline inside a box-sized square."""
    pts = [
        f"{fmt(50.0 + 100.0 * x / box)}% {fmt(50.0 + 100.0 * y / box)}%"
        for x, y in outline
    ]
    return "clip-path: polygon(" + ", ".join(pts) + ");"


def face_box(outline) -> float:
    """
    The square the face's element has to be for that outline to fit inside it.

    Twice the furthest corner, so the polygon is inscribed and touches the box
    at its extremes — the same relationship the hand-written d20 triangle has to
    its 106px box.
    """
    return 2.0 * max(math.hypot(x, y) for x, y in outline)


def inverse_rows(cols):
    """
    The rows of the inverse of the matrix whose columns are given.

    For an orthonormal matrix the inverse is the transpose, and the transpose's
    rows are just the original columns — so this is the identity function on the
    tuple, and saying so is the whole point of naming it. The first version of
    this returned the *rows of the matrix itself*, which is a different thing
    entirely: it made every resting orientation rotate the die the wrong way
    round, leaving faces pointing away from the viewer. The self-check below
    caught it on 18 of 20 faces.
    """
    return tuple(cols)


def rot_x(t):
    s, c = math.sin(t), math.cos(t)
    return ((1, 0, 0), (0, c, -s), (0, s, c))


def rot_y(t):
    s, c = math.sin(t), math.cos(t)
    return ((c, 0, s), (0, 1, 0), (-s, 0, c))


def rot_z(t):
    s, c = math.sin(t), math.cos(t)
    return ((c, -s, 0), (s, c, 0), (0, 0, 1))


def mat_mul(m, o):
    return tuple(
        tuple(sum(m[r][k] * o[k][c] for k in range(3)) for c in range(3))
        for r in range(3)
    )


def mat_apply(m, v):
    return tuple(sum(m[r][k] * v[k] for k in range(3)) for r in range(3))


def compose_xyz(a_deg, b_deg, c_deg):
    """The matrix CSS produces for `rotateX(a) rotateY(b) rotateZ(c)`."""
    return mat_mul(
        mat_mul(rot_x(math.radians(a_deg)), rot_y(math.radians(b_deg))),
        rot_z(math.radians(c_deg)),
    )


def silhouette(verts, rests, perspective: float) -> float:
    """
    How much room the solid actually needs, in px, across every resting pose.

    A face box is not the answer and using it was visibly wrong: a d20 whose faces
    are 106px squares draws a solid about 135px across, so a row of them overlaps.
    The honest number is the widest the projected silhouette ever gets, which means
    rotating the vertex set into all twenty rest poses, dividing by CSS's
    perspective foreshortening, and taking the extreme.

    Returned as a full width, rounded up to a whole pixel, so a layout can just
    reserve a box of that size and never clip a corner.
    """
    worst = 0.0
    for angles in rests:
        m = compose_xyz(*angles)
        for v in verts:
            x, y, z = mat_apply(m, v)
            # CSS perspective divides by (1 - z/p) — a vertex nearer the viewer
            # projects further from the centre.
            k = perspective / max(1.0, perspective - z)
            worst = max(worst, abs(x) * k, abs(y) * k)
    return math.ceil(worst * 2.0)


def tumble_extent(verts, perspective: float) -> int:
    """
    The widest the solid can ever get, at any orientation — not just at rest.

    Worth having separately because the two differ a lot for a cube: at every
    resting pose it is axis-aligned and exactly one edge wide, but mid-throw it
    turns through its diagonal and swells by 70%. Sizing a tray on the resting
    width alone gives dice that collide with their neighbours for the duration of
    the tumble and then tidily separate, which looks like a bug.

    Bounded by the circumradius, which is orientation-independent. Slightly
    conservative — no vertex is simultaneously at maximum lateral offset and
    maximum z — and conservative is the right direction for a spacing allowance.
    """
    r = max(norm(v) for v in verts)
    k = perspective / max(1.0, perspective - r)
    return math.ceil(2.0 * r * k)


def euler_xyz(rows):
    """
    Decompose a rotation into the angles of `rotateX(a) rotateY(b) rotateZ(c)`.

    CSS applies a transform list left to right as R = Rx(a).Ry(b).Rz(c) acting on
    column vectors, which expands to

        R[0][2] =  sin b
        R[1][2] = -sin a cos b        R[2][2] = cos a cos b
        R[0][0] =  cos b cos c        R[0][1] = -cos b sin c

    so b comes out of R02 and the other two from ratios. But asin only ever
    returns an angle in [-90, 90], and b just as legitimately lives in the other
    half where cos b is negative — in which case every ratio above flips sign and
    the naive answer is wrong. Exactly one of the icosahedron's twenty faces lands
    in that branch, which is the worst possible number: enough to be a real bug,
    few enough to look like a rounding problem and get waved through.

    So rather than reason about which branch applies, both are tried and the one
    that reproduces the input matrix is returned. The decomposition is then
    correct by construction, and the gimbal case where cos b is zero falls out of
    the same test instead of needing its own careful handling.
    """
    r02 = max(-1.0, min(1.0, rows[0][2]))

    candidates = []
    for b in (math.asin(r02), math.pi - math.asin(r02)):
        cb = math.cos(b)
        if abs(cb) < 1e-9:
            # Gimbal: sin b is +-1, so the Y rotation has folded X and Z onto the
            # same axis and only their combination is determined. Pinning c = 0
            # and solving the remaining row gives
            #     R[1][0] = sin a . sin b        R[1][1] = cos a
            # so the sign of sin b has to come along, which the first version of
            # this dropped. Exactly one of the icosahedron's faces lands here, and
            # with the sign missing it was the only one that came out wrong.
            sb = math.sin(b)
            a = math.atan2(rows[1][0] * (1.0 if sb >= 0 else -1.0), rows[1][1])
            c = 0.0
        else:
            a = math.atan2(-rows[1][2] / cb, rows[2][2] / cb)
            c = math.atan2(-rows[0][1] / cb, rows[0][0] / cb)
        candidates.append(tuple(math.degrees(x) for x in (a, b, c)))

    best, best_err = None, None
    for cand in candidates:
        got = compose_xyz(*cand)
        err = max(abs(got[r][c] - rows[r][c]) for r in range(3) for c in range(3))
        if best_err is None or err < best_err:
            best, best_err = cand, err

    if best_err > 1e-9:
        raise ValueError(f"could not decompose rotation (residual {best_err:g})")
    return best


def fmt(x: float) -> str:
    """Trim float noise so the stylesheet is diffable and -0 never appears."""
    s = f"{x:.5f}".rstrip("0").rstrip(".")
    return "0" if s in ("-0", "") else s


def matrix3d(cols, translation) -> str:
    c0, c1, c2 = cols
    parts = [
        c0[0], c0[1], c0[2], 0,
        c1[0], c1[1], c1[2], 0,
        c2[0], c2[1], c2[2], 0,
        translation[0], translation[1], translation[2], 1,
    ]
    return "matrix3d(" + ", ".join(fmt(p) for p in parts) + ")"


# ---------------------------------------------------------------------------
# Emit
# ---------------------------------------------------------------------------

def icosa_frames(edge: float):
    """
    Every face of the icosahedron as (centre, outward normal, in-plane up), in a
    deterministic order: top cap, upper band, lower band, bottom cap, each going
    round the vertical axis.

    The order is load-bearing — dice.css binds face transforms with
    `:nth-child(N)` — so it must not depend on the order `combinations` happens to
    emit triples in, which is arbitrary and would reshuffle if the vertex list were
    ever touched.
    """
    verts, faces = icosahedron(edge)
    assert len(verts) == 12, f"expected 12 vertices, got {len(verts)}"
    assert len(faces) == 20, f"expected 20 faces, got {len(faces)}"

    def sort_key(f):
        c = scale(add(add(f[0], f[1]), f[2]), 1.0 / 3.0)
        return (-round(c[1] / (edge * 0.1)), math.atan2(c[2], c[0]))

    frames = []
    for p0, p1, p2 in sorted(faces, key=sort_key):
        centre = scale(add(add(p0, p1), p2), 1.0 / 3.0)
        n = unit(cross(sub(p1, p0), sub(p2, p0)))
        if dot(n, centre) < 0:
            n = scale(n, -1.0)
        # Point the local "up" at one vertex, so every face's triangle is apex-up
        # in its own element and the 3D rotation does the rest. Which vertex is
        # arbitrary, but it has to be the same one on every run.
        apex = max((p0, p1, p2),
                   key=lambda p: (round(p[1], 6), round(p[0], 6), round(p[2], 6)))
        frames.append((centre, n, sub(apex, centre)))
    return verts, frames


def cube_corners(edge: float):
    h = edge / 2.0
    return [(sx * h, sy * h, sz * h)
            for sx in (-1, 1) for sy in (-1, 1) for sz in (-1, 1)]


def rest_angles(frames):
    """The .show-N Euler angles for a list of (centre, normal, up) face frames."""
    return [euler_xyz(inverse_rows(face_basis(c, n, u)[0])) for c, n, u in frames]


def solid(sides: int, verts, faces, polar_up: bool = False):
    """Everything the emitter needs about one die, measured once."""
    frames = numbered_frames(faces, sides, polar_up)
    rests = rest_angles([(c, n, u) for c, n, u, _ in frames])
    outline = face_outline(*frames[0][:3], frames[0][3])
    return {
        "sides": sides,
        "frames": frames,
        "rests": rests,
        "outline": outline,
        "box": face_box(outline),
        "rest_px": silhouette(verts, rests, PERSPECTIVE),
        "tumble_px": tumble_extent(verts, PERSPECTIVE),
    }


def emit_solid(w, die, title: str):
    """The CSS block for one of the derived solids."""
    n = die["sides"]
    w(f"/* --- d{n}: {title} --- */")
    w(f"/* {fmt(die['box'])}px faces draw a solid {die['rest_px']}px across at rest, "
      f"{die['tumble_px']}px mid-tumble. */")
    w(f".die{n} {{ --die-box: {fmt(die['box'])}px; }}")
    w(f".die{n} .die-face {{")
    w(f"  width: {fmt(die['box'])}px;")
    w(f"  height: {fmt(die['box'])}px;")
    w("  /* Derived from the solid's own corners, so neighbouring faces meet")
    w("     along a shared edge with no gutter between them. Every face of this")
    w("     solid is congruent and identically oriented in its own element, which")
    w("     is what lets one polygon serve all of them. */")
    w("  " + clip_polygon(die["outline"], die["box"]))
    w("}")
    w(f".stage{n} {{ width: var(--die{n}-rest); height: var(--die{n}-rest); }}")
    w("")

    for idx, (centre, nrm, up, _) in enumerate(die["frames"], start=1):
        cols, tr = face_basis(centre, nrm, up)
        w(f".die{n} .die-face:nth-child({idx}) {{ transform: {matrix3d(cols, tr)}; }}")
    w("")

    for idx, (a, b, c) in enumerate(die["rests"], start=1):
        w(f".die{n}.show-{idx} {{ transform: rotateX({fmt(a)}deg) "
          f"rotateY({fmt(b)}deg) rotateZ({fmt(c)}deg); }}")
    w("")


def build():
    out = []
    w = out.append

    w("/*")
    w(" * CSS 3D dice — GENERATED by tools/gen_dice_css.py. Do not hand-edit.")
    w(" *")
    w(" * Re-run the generator after changing D20_EDGE or D6_EDGE there; every")
    w(" * number below is derived from those two, including the clip-path that")
    w(" * cuts each square face into a triangle.")
    w(" *")
    w(" * Only geometry lives here. Colour, shading and timing are in style.css,")
    w(" * so the palette stays in one place and this file never needs a designer.")
    w(" */")
    w("")

    # Measure everything up front, so the :root block can be written before the
    # rules that reference it.
    verts, ico_frames = icosa_frames(D20_EDGE)
    d20_rest = rest_angles(ico_frames)
    cube_frames = cube(D6_EDGE)
    d6_rest = rest_angles(cube_frames)
    corners = cube_corners(D6_EDGE)

    d20_extent = silhouette(verts, d20_rest, PERSPECTIVE)
    d20_tumble = tumble_extent(verts, PERSPECTIVE)
    d6_extent = silhouette(corners, d6_rest, PERSPECTIVE)
    d6_tumble = tumble_extent(corners, PERSPECTIVE)

    # The other three hit dice. Derived wholesale rather than written out, which
    # is the only reason adding them was a day's work and not a fortnight's.
    d8 = solid(8, *octahedron(D8_RADIUS))
    d10 = solid(10, *trapezohedron10(D10_RADIUS), polar_up=True)
    d12 = solid(12, *dodecahedron(D12_RADIUS))

    box = D20_EDGE * 2.0 / math.sqrt(3.0)

    w("/* Measured footprints, published at :root rather than on each stage because")
    w("   a custom property inherits downward only — a tray spacing its dice by")
    w("   these sits above them, and reading them off .stage6 left every calc()")
    w("   silently falling back.")
    w("")
    w("   `rest` is how wide the solid is once it has settled, and is what layout")
    w("   should reserve. `tumble` is how wide it gets while turning, and is what")
    w("   neighbouring dice have to allow for between them. The two differ most for")
    w("   the cube, which is axis-aligned at every resting pose and passes through")
    w("   its diagonal on the way there. */")
    w(":root {")
    w(f"  --die20-rest: {d20_extent}px;")
    w(f"  --die20-tumble: {d20_tumble}px;")
    w(f"  --die6-rest: {d6_extent}px;")
    w(f"  --die6-tumble: {d6_tumble}px;")
    for die in (d8, d10, d12):
        w(f"  --die{die['sides']}-rest: {die['rest_px']}px;")
        w(f"  --die{die['sides']}-tumble: {die['tumble_px']}px;")
    w("}")
    w("")

    # ---- d20 -------------------------------------------------------------
    base_y = (0.5 + 0.25) * 100.0                      # centroid + inradius
    base_x = (0.5 + math.sqrt(3.0) / 4.0) * 100.0      # centroid +- edge/2

    w("/* --- d20: 20 triangular faces of an icosahedron ------------------- */")
    w(f"/* {fmt(box)}px faces draw a solid {d20_extent}px across at rest. */")
    w(".die20 { --die-box: " + fmt(box) + "px; }")
    w(".die20 .die-face {")
    w(f"  width: {fmt(box)}px;")
    w(f"  height: {fmt(box)}px;")
    w("  /* An equilateral triangle inscribed with its centroid at the box's")
    w("     centre. These three points are what make neighbouring faces meet")
    w("     along a shared edge with no gutter between them. */")
    w(
        f"  clip-path: polygon(50% 0%, "
        f"{fmt(base_x)}% {fmt(base_y)}%, {fmt(100 - base_x)}% {fmt(base_y)}%);"
    )
    w("}")
    w(".stage20 { width: var(--die20-rest); height: var(--die20-rest); }")
    w("")

    for idx, (centre, n, up) in enumerate(ico_frames, start=1):
        cols, tr = face_basis(centre, n, up)
        w(f".die20 .die-face:nth-child({idx}) {{ transform: {matrix3d(cols, tr)}; }}")
    w("")

    w("/* Resting orientations: .show-N brings face N to the front, upright.")
    w("   Euler angles rather than matrix3d so a tumble can be added by pushing")
    w("   whole turns into rotateX/rotateY — see the note at the top. */")
    for idx, (a, b, c) in enumerate(d20_rest, start=1):
        w(
            f".die20.show-{idx} {{ transform: rotateX({fmt(a)}deg) "
            f"rotateY({fmt(b)}deg) rotateZ({fmt(c)}deg); }}"
        )
    w("")

    # ---- d6 --------------------------------------------------------------
    w("/* --- d6: a cube, opposite faces summing to 7 ---------------------- */")
    w(f"/* {fmt(D6_EDGE)}px cube: {d6_extent}px across at rest, {d6_tumble}px mid-tumble. */")
    w(".die6 { --die-box: " + fmt(D6_EDGE) + "px; }")
    w(".die6 .die-face {")
    w(f"  width: {fmt(D6_EDGE)}px;")
    w(f"  height: {fmt(D6_EDGE)}px;")
    w("  clip-path: none;")
    w("}")
    w(".stage6 { width: var(--die6-rest); height: var(--die6-rest); }")
    w("")

    for idx, (centre, n, up) in enumerate(cube_frames, start=1):
        cols, tr = face_basis(centre, n, up)
        w(f".die6 .die-face:nth-child({idx}) {{ transform: {matrix3d(cols, tr)}; }}")
    w("")

    for idx, (a, b, c) in enumerate(d6_rest, start=1):
        w(
            f".die6.show-{idx} {{ transform: rotateX({fmt(a)}deg) "
            f"rotateY({fmt(b)}deg) rotateZ({fmt(c)}deg); }}"
        )
    w("")

    emit_solid(w, d8, "8 triangular faces of an octahedron")
    emit_solid(w, d10, "10 kite faces of a pentagonal trapezohedron")
    emit_solid(w, d12, "12 pentagonal faces of a dodecahedron")

    return "\n".join(out) + "\n", build_js(
        d20_rest, d6_rest, {d["sides"]: d["rests"] for d in (d8, d10, d12)}
    )


def build_js(d20_rest, d6_rest, extra) -> str:
    def table(rest):
        return ",\n".join(
            f"      {i}: [{fmt(a)}, {fmt(b)}, {fmt(c)}]"
            for i, (a, b, c) in enumerate(rest, start=1)
        )

    others = "".join(
        f"    {sides}: {{\n{table(extra[sides])}\n    }},\n"
        for sides in sorted(extra)
    )

    return f"""/**
 * Resting orientations for the CSS 3D dice — GENERATED by tools/gen_dice_css.py.
 * Do not hand-edit; re-run the generator.
 *
 * `REST[sides][value]` is the [rotateX, rotateY, rotateZ] in degrees that brings
 * that face to the front, upright. The same numbers are in assets/css/dice.css as
 * .show-N classes, which is what a browser with JavaScript off, or a player who
 * has asked for reduced motion, gets instead.
 *
 * They are duplicated here rather than read back from the stylesheet because a
 * throw adds whole turns to these angles, and getComputedStyle returns a matrix —
 * from which the turns have already been lost.
 */
window.DiceGeometry = {{
  REST: {{
    20: {{
{table(d20_rest)}
    }},
    6: {{
{table(d6_rest)}
    }},
{others}  }},
}};
"""


def self_check() -> int:
    """
    Prove the geometry before it reaches a browser.

    Cheap invariants that would each have caught a real mistake: the solid is
    regular, every face's basis is orthonormal, and — the one that matters — the
    resting rotation really does bring that face's normal to face the viewer and
    its apex to the top.
    """
    problems = []

    for label, edge in (("d20", D20_EDGE), ("d6", D6_EDGE)):
        if label == "d20":
            verts, faces = icosahedron(edge)
            if len(faces) != 20:
                problems.append(f"{label}: {len(faces)} faces, expected 20")
            triples = [
                (
                    scale(add(add(a, b), c), 1 / 3.0),
                    unit(cross(sub(b, a), sub(c, a))),
                    sub(max((a, b, c), key=lambda p: (round(p[1], 6), round(p[0], 6), round(p[2], 6))),
                        scale(add(add(a, b), c), 1 / 3.0)),
                )
                for a, b, c in faces
            ]
            # every face the same distance from the centre
            radii = [norm(t[0]) for t in triples]
            if max(radii) - min(radii) > edge * 1e-6:
                problems.append(f"{label}: face centres are not equidistant")
        else:
            triples = [(c, n, u) for c, n, u in cube(edge)]

        problems += check_frames(label, triples)

    # The three derived solids, which get the same treatment plus the two
    # invariants that only matter once a shape is computed rather than written:
    # opposite faces have to sum to sides + 1, and every face has to carry the
    # identical outline or the one shared clip-path is wrong for most of them.
    for label, sides, (verts, faces), polar in (
        ("d8", 8, octahedron(D8_RADIUS), False),
        ("d10", 10, trapezohedron10(D10_RADIUS), True),
        ("d12", 12, dodecahedron(D12_RADIUS), False),
    ):
        frames = numbered_frames(faces, sides, polar)
        if len(frames) != sides:
            problems.append(f"{label}: {len(frames)} faces, expected {sides}")

        for i, (centre, _, _, _) in enumerate(frames, start=1):
            opposite = frames[sides - i][0]
            if norm(add(centre, opposite)) > max(abs(x) for x in centre) * 1e-6:
                problems.append(f"{label}: face {i} and face {sides + 1 - i} are not opposite")

        shapes = [
            sorted((round(x, 5), round(y, 5)) for x, y in face_outline(c, n, u, co))
            for c, n, u, co in frames
        ]
        for i, shape in enumerate(shapes[1:], start=2):
            if shape != shapes[0]:
                problems.append(f"{label}: face {i}'s outline differs from face 1's — "
                                "one clip-path cannot serve both")

        problems += check_frames(label, [(c, n, u) for c, n, u, _ in frames])

    if problems:
        for p in problems:
            print("  FAIL " + p)
        print(f"\n{len(problems)} problems")
        return 1
    print("geometry ok: d6 d8 d10 d12 d20, every show-N presents its face upright, "
          "opposite faces sum correctly")
    return 0


def check_frames(label: str, triples):
    """Orthonormality, and that show-N really does present that face upright."""
    problems = []
    for i, (centre, n, up) in enumerate(triples, start=1):
        if dot(n, centre) < 0:
            n = scale(n, -1.0)
        cols, _ = face_basis(centre, n, up)

        # Orthonormal?
        for j, cj in enumerate(cols):
            if abs(norm(cj) - 1.0) > 1e-9:
                problems.append(f"{label} face {i}: column {j} is not unit length")
        for j, k in ((0, 1), (1, 2), (0, 2)):
            if abs(dot(cols[j], cols[k])) > 1e-9:
                problems.append(f"{label} face {i}: columns {j},{k} not perpendicular")

        # Does the resting rotation actually present this face?
        rows = inverse_rows(cols)
        a, b, c = euler_xyz(rows)

        rebuilt = compose_xyz(a, b, c)

        # The face's normal, in CSS coords, should end up pointing at the viewer.
        got_n = mat_apply(rebuilt, cols[2])
        if abs(got_n[2] - 1.0) > 1e-6:
            problems.append(
                f"{label} face {i}: show-{i} leaves the normal at z={got_n[2]:.4f}, want 1"
            )
        # And its local down should still be down, i.e. no roll left over.
        got_y = mat_apply(rebuilt, cols[1])
        if abs(got_y[1] - 1.0) > 1e-6:
            problems.append(
                f"{label} face {i}: show-{i} leaves a roll of "
                f"{math.degrees(math.acos(max(-1, min(1, got_y[1])))):.2f} deg"
            )
    return problems


def main() -> int:
    if "--check" in sys.argv:
        rc = self_check()
        if rc:
            return rc
        css, js = build()
        stale = []
        for path, want in ((OUT, css), (OUT_JS, js)):
            rel = os.path.relpath(path, ROOT)
            if not os.path.exists(path):
                stale.append(f"{rel} is missing")
                continue
            with open(path, encoding="utf-8") as fh:
                if fh.read() != want:
                    stale.append(f"{rel} is stale")
        if stale:
            for s in stale:
                print(f"  FAIL {s} — re-run tools/gen_dice_css.py")
            return 1
        print("dice.css and dice-geometry.js are up to date")
        return 0

    css, js = build()
    if "--stdout" in sys.argv:
        sys.stdout.write(css)
        sys.stdout.write("\n/* ---- dice-geometry.js ---- */\n")
        sys.stdout.write(js)
        return 0

    rc = self_check()
    if rc:
        return rc
    for path, text in ((OUT, css), (OUT_JS, js)):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(text)
        print(f"wrote {os.path.relpath(path, ROOT)} ({len(text)} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
