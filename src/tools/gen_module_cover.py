#!/usr/bin/env python3
"""
Draw the cover plate for a module — the picture on its card on the front page.

The third generator of its kind; `gen_location_art.py` and `gen_region_map.py`
are the others, and this borrows their transport wholesale. What is different
is the frame and the job: a cover is 3:2, it is the only picture of a module
anybody sees before they commit to playing it, and it has to sit beside the two
shipped ones without looking like a different game.

    python3 tools/gen_module_cover.py undervault
    python3 tools/gen_module_cover.py undervault --candidates 4   # pick by eye
    python3 tools/gen_module_cover.py undervault --seed 700 --apply

Nothing is written over an existing cover without `--apply`; candidates land in
`assets/images/modules/_cand/` and are yours to look at first.

**The negative prompt does nothing.** SANA-Sprint is a two-step distilled model
running without classifier-free guidance, so there is no unconditional branch
for a negative to push against — this is proved in CLAUDE.md rather than
assumed. It is still sent, because the endpoint's signature wants the argument,
but nothing that must be true may be left to it. Put it in the positive prompt,
and put it early: the model weights what it reads first.
"""

import argparse
import io
import json
import os
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content", "modules")
ART = os.path.join(ROOT, "assets", "images", "modules")
CAND = os.path.join(ART, "_cand")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

# 3:2, which is what the shipped covers are (900x600) and what `.module-cover`
# reserves with `aspect-ratio: 3 / 2`. Generated large and reduced, because the
# card is drawn at whatever width the row happens to give it and a plate at
# exactly card size is soft the moment the window is wide.
WIDTH, HEIGHT = 1152, 768
FINAL = (900, 600)
STEPS, GUIDANCE = 2, 4.5
QUALITY = 88

# Mean luminance of the two shipped covers (79.0 and 80.2), which a new plate
# is corrected onto. Measured, not chosen — see match_shelf().
SHELF_MEAN = 79.5

# Read off old_city.jpg and rivermark.jpg rather than invented: a painted plate
# with ink outlines, saturated colour, one strong light source in frame, and a
# scene rather than a portrait. The third card has to belong to the same shelf.
STYLE = (
    "fantasy tabletop roleplaying game cover illustration, painted in gouache "
    "with dark ink outlines, warm firelit palette of amber gold and deep "
    "green, rich saturated colour, dramatic composition, medieval period, "
    "detailed"
)

# What is shipped, and how to get it back. Generation is deterministic in the
# seed, so this is the whole provenance: the same seed against the same prompt
# reproduces the file byte for byte (checked, not assumed). Edit a prompt and
# its seed no longer reaches the same picture — pick again, and record it here.
SHIPPED_SEED = {"undervault": 201, "waerhaven": 302}

# A cover per module, written here rather than derived from the blurb. A blurb
# is prose about what playing it is like; a cover needs a place and a moment,
# and the two are not the same sentence. Leading clause first — see the header.
COVERS = {
    # The direction comes first, and so does the word "down". The first pass
    # said "a square-cut stone stair descending into a black hole in a bare
    # hillside" and every one of four seeds drew a stair climbing UP to a
    # doorway in a tower — "stair" plus "stone" plus "lintel" is a temple
    # entrance in the weights, and a clause later in the sentence does not
    # outvote it. So: a pit, seen from above, with the steps going into it.
    "undervault": (
        "a deep square pit sunk into the ground, seen from above at its rim, "
        "cut stone steps going down into the pit and disappearing into "
        "blackness far below, no building and no tower, "
        "torchlight from the rim falling a little way down the steps and "
        "then failing, three armoured adventurers crouched at the edge of the "
        "pit holding torches out over the drop, an old timber winch with "
        "coiled rope leaning over the hole, weathered canvas tents and a big "
        "orange campfire on the bare hillside around the rim, a grey spoil "
        "heap of broken rock behind the tents, evening sky at the top of the "
        "picture"
    ),
    # The module is the whole of Morris's book and the card is read before any
    # of it, so the cover is the promise rather than the first act: somebody
    # small, leaving, on a road that does not stop.
    #
    # Deliberately NOT the Well. "a well" draws a village wishing-well with a
    # bucket on a rope, which is the least mysterious object in the language —
    # and the Well is four acts away and no player will have earned the sight
    # of it. What is on the card is the moment Act 1 actually ends on.
    #
    # The leading clause is the road and the wood, in that order, because the
    # composition IS the road: a rider anywhere in the first clause makes a
    # portrait of a horseman and the landscape goes to background. "no castle"
    # is in the positive prompt on purpose — a small stone hall in a green
    # valley is a castle in the weights unless told otherwise, and the negative
    # prompt is inert on this model.
    # Three things had to be beaten out of the first draft, and all three are
    # the same fault: a clause that arrives late loses.
    #
    # 1. "a vast dark forest" sat in the middle of the sentence and simply did
    #    not appear — four seeds of open rolling valley with distant hills. It
    #    leads now, and the wood is a WALL of trees rather than a region of the
    #    map, because "forest filling the far half" is a proportion and this
    #    model draws objects.
    # 2. "a tall weathered stone waymark post" came back as a round stone tower
    #    on three seeds of four. Tall + stone + standing alone is a tower. It
    #    is waist-high and leaning now, and "no tower" is stated positively.
    # 3. The rider was "small in the landscape" and the card is 900px wide in a
    #    row of three. The shipped covers all have a figure you can read at
    #    card size and one object doing the composition; a wide empty landscape
    #    is a fine picture and reads as a failed thumbnail beside them.
    # The city's own subject is a chain across a harbour mouth, and it is the
    # one object in Waerhaven that reads at card size: heavy, dark, horizontal,
    # and obviously a decision somebody made. A wide harbour view came back as
    # four seeds of generic port. A figure on the quay gives it the scale the
    # shipped covers all have.
    "waerhaven": (
        "a huge heavy black iron chain slung taut across the mouth of a "
        "harbour just above the water in the foreground, links as thick as a "
        "man's thigh, running from a squat stone tower on the left out of "
        "frame to the right, one figure in a dark coat standing on the stone "
        "quay beside it seen from behind and small against it, "
        "behind them a shipyard of tall bare wooden hull frames on slipways "
        "and a ridge of steep grey roofs, cold flat northern light, "
        "grey water, low cloud, no sun, no sails, no ships afloat"
    ),
}

NEGATIVE = "text, watermark, signature, letterbox, black bars, blurry, modern"


def generate(prompt: str, seed: int, timeout: int = 300) -> str | None:
    """POST for an event id, then read the SSE stream for the result path."""
    payload = json.dumps({
        "data": [prompt, NEGATIVE, WIDTH, HEIGHT, STEPS, GUIDANCE, seed, False]
    }).encode()
    req = urllib.request.Request(
        f"{BASE}/gradio_api/call/generate",
        data=payload,
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            event_id = json.loads(resp.read()).get("event_id")
    except urllib.error.URLError as e:
        print(f"  cannot reach {BASE}: {e}", file=sys.stderr)
        return None
    if not event_id:
        return None

    url = f"{BASE}/gradio_api/call/generate/{event_id}"
    deadline = time.time() + timeout
    with urllib.request.urlopen(url, timeout=timeout) as stream:
        for raw in stream:
            if time.time() > deadline:
                return None
            line = raw.decode("utf-8", "replace").strip()
            if not line.startswith("data:"):
                continue
            body = line[5:].strip()
            if not body or body == "null":
                continue
            try:
                data = json.loads(body)
            except json.JSONDecodeError:
                continue
            if isinstance(data, list) and data:
                first = data[0]
                if isinstance(first, dict):
                    path = first.get("path") or first.get("name")
                    if path:
                        return path
                elif isinstance(first, str):
                    return first
    return None


def fetch(path: str) -> bytes:
    """The result is either a file this process can read, or a URL to it."""
    if os.path.isfile(path):
        return open(path, "rb").read()
    url = f"{BASE}/gradio_api/file={path}"
    with urllib.request.urlopen(url, timeout=60) as resp:
        return resp.read()


def match_shelf(im, target: float = SHELF_MEAN):
    """
    Lift the plate to the brightness the other covers sit at.

    Gamma rather than a brightness multiply, and the difference matters here.
    A multiply scales everything, so pulling a mean-52 plate up to 79 blows the
    campfire — the brightest thing in frame and the reason the composition
    works — to flat white. Gamma moves the midtones and the shadows a long way
    and the highlights hardly at all, which is the shape of correction a night
    scene wants: the pit stays black, the fire stays a fire, the ground between
    them comes up.

    Solved for rather than tuned: bisect the exponent until the measured mean
    lands on target. A hand-picked 0.7 would be right for this one plate and
    wrong for the next, and the next is the one nobody will check.
    """
    from PIL import Image

    lum = im.convert("L")
    mean = sum(lum.getdata()) / (im.width * im.height)
    if abs(mean - target) < 1.5:
        return im

    lo, hi = 0.25, 3.0
    for _ in range(24):
        g = (lo + hi) / 2
        lut = [min(255, round(255 * (i / 255) ** g)) for i in range(256)]
        got = sum(lum.point(lut).getdata()) / (im.width * im.height)
        # Smaller exponent is brighter, so the bisection runs the other way.
        if got < target:
            hi = g
        else:
            lo = g
    return im.point(lut * 3)


def finish(raw: bytes, dest: str) -> None:
    """
    Trim any bars, fit the 3:2 frame, match the shelf, and save as JPEG.

    Nothing is drawn over a cover, so unlike a region map it is allowed to be
    as loud as it likes — but it is not allowed to be a different brightness
    from the cards beside it. Three panels in a row are read as one row, and
    the dark one reads as "this image failed to load properly" rather than as
    a night scene. The Undervault's first pass came back at mean 52 against
    the two shipped covers' 79 and 80, which is exactly that.

    The one thing it must not do is arrive letterboxed, because `object-fit:
    cover` would then crop the picture to the middle of the bars.
    """
    from PIL import Image, ImageChops

    im = Image.open(io.BytesIO(raw)).convert("RGB")

    # Bars are a flat border the same colour all round; ImageChops finds the
    # box that differs from the corner pixel. A picture that genuinely runs to
    # a flat edge loses nothing, because the crop is bounded below.
    bg = Image.new("RGB", im.size, im.getpixel((0, 0)))
    box = ImageChops.difference(im, bg).convert("L").point(lambda p: 255 if p > 12 else 0).getbbox()
    if box and (box[2] - box[0]) > im.width * 0.6 and (box[3] - box[1]) > im.height * 0.6:
        im = im.crop(box)

    # Centre-crop to 3:2, then down to the shipped size.
    want = FINAL[0] / FINAL[1]
    have = im.width / im.height
    if have > want:
        w = int(im.height * want)
        im = im.crop(((im.width - w) // 2, 0, (im.width - w) // 2 + w, im.height))
    elif have < want:
        h = int(im.width / want)
        im = im.crop((0, (im.height - h) // 2, im.width, (im.height - h) // 2 + h))
    im = im.resize(FINAL, Image.LANCZOS)
    im = match_shelf(im)

    os.makedirs(os.path.dirname(dest), exist_ok=True)
    im.save(dest, "JPEG", quality=QUALITY, optimize=True)


def known_modules() -> set[str]:
    out = set()
    if os.path.isdir(CONTENT):
        for name in os.listdir(CONTENT):
            if name.endswith(".json"):
                try:
                    out.add(json.load(open(os.path.join(CONTENT, name)))["module_key"])
                except (KeyError, json.JSONDecodeError):
                    pass
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("module", help="module_key, e.g. undervault")
    ap.add_argument("--seed", type=int, default=1,
                    help="the shipped plate's seed is in SHIPPED_SEED")
    ap.add_argument("--candidates", type=int, default=1,
                    help="generate N, seeds counting up from --seed")
    ap.add_argument("--apply", action="store_true",
                    help="write the (single) result to the shipped path")
    args = ap.parse_args()

    key = args.module
    if key not in COVERS:
        print(f"no cover prompt for '{key}'. Add one to COVERS.", file=sys.stderr)
        mods = known_modules()
        if mods:
            print(f"modules on disk: {', '.join(sorted(mods))}", file=sys.stderr)
        return 2
    if args.apply and args.candidates != 1:
        print("--apply takes one candidate; pick a seed first.", file=sys.stderr)
        return 2

    prompt = f"{STYLE}, {COVERS[key]}"
    for i in range(args.candidates):
        seed = args.seed + i
        dest = (os.path.join(ART, f"{key}.jpg") if args.apply
                else os.path.join(CAND, f"{key}_s{seed}.jpg"))
        print(f"  seed {seed} -> {os.path.relpath(dest, ROOT)}")
        path = generate(prompt, seed)
        if not path:
            print("    generation failed", file=sys.stderr)
            return 1
        finish(fetch(path), dest)
    return 0


if __name__ == "__main__":
    sys.exit(main())
