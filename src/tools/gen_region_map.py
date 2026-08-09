#!/usr/bin/env python3
"""
Draw the region map backdrops with the local image model.

The companion to gen_location_art.py, and the same two HTTP calls to the same
Gradio app — a POST that returns an event id, and an SSE GET that returns the
result path. What differs is the subject and the frame.

    python3 tools/gen_region_map.py --list
    python3 tools/gen_region_map.py --only old_city
    python3 tools/gen_region_map.py --only old_city --candidates 4
    python3 tools/gen_region_map.py --all --missing-only

Finished maps land in assets/images/maps/<region_key>.png, which is where
ui-map.js looks for them — the region key, never the name.

A LOCATION plate is a scene you are standing in. A REGION map is a plan of the
whole place seen from above, and the two want opposite things from the model:
the plate wants eye level and atmosphere, this wants orthographic flatness and
legible walls. Asking for one with the other's prompt gets you a landscape
painting of a dungeon, which is a fine picture and useless as a backdrop.

WHY THE NODES STILL READ
-----------------------
The chart draws place names and roads on top of this at 0-100 by 0-75. So the
map has to stay quiet: no labels of its own, no numbered keys, nothing with
high local contrast where a name will sit. The prompt asks for muted parchment
and the post-step drops the contrast further, because a map that competes with
the text on it is worse than no map — `.is-bare` is a perfectly good fallback
and ui-map.js reaches for it on its own if the file is missing.

The negative prompt is passed and is inert; see gen_location_art.py's `debar`
for the proof — this is a two-step distilled model running without classifier-
free guidance, so there is no unconditional branch for a negative to push
against. Anything that must be true is enforced after the fact, below.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content", "locations")
ART = os.path.join(ROOT, "assets", "images", "maps")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

# 4:3, matching the shipped maps and the chart's own 100x75 field, so the
# backdrop and the node positions agree without anything being stretched.
WIDTH, HEIGHT = 1024, 768
FINAL = (1536, 1152)
STEPS, GUIDANCE = 2, 4.5

# The house style, read off the three shipped plates: an ink plan on stained
# parchment, walls drawn in section, hatched shadow outside the walls, and
# emphatically no lettering.
STYLE = (
    "top-down orthographic dungeon floor plan map, hand-inked cartography, "
    "fine black pen linework on cream paper, pale off-white floors, "
    "stone walls drawn in plan view as thick black outlines, "
    "light cross-hatched shadow just outside the walls, "
    "a single connected complex sitting as an island on the page, "
    "large areas of blank empty aged parchment around it, "
    "coffee-stained torn parchment edges, high key and light, sparse, "
    "minimal shading inside the rooms, restrained sepia wash, "
    "no labels, no numbers, old-school tabletop battlemap, "
    "flat overhead view, no perspective"
)
# The first pass came back dense and dark — the model filled the whole frame
# with grey stonework, which is a handsome picture and a bad backdrop: the
# chart writes place names over this and they vanished into it. The terms
# above that are doing the work are the ones asking for emptiness ("island on
# the page", "large areas of blank", "high key", "sparse"), because what the
# shipped plates actually have and this lacked is negative space.

NEGATIVE = (
    "text, letters, words, numbers, labels, legend, key, compass rose, scale "
    "bar, grid numbers, watermark, signature, title, ui, hud, logo, "
    "photorealistic, photograph, 3d render, isometric, perspective view, "
    "eye level, oblique, cutaway, character art, people, portrait, "
    "oversaturated, neon, blurry, lowres, letterbox, black bars, vignette"
)

# Art direction per region_type, so a sewer does not come back as a hillside.
BY_TYPE = {
    "town": "streets and blocks of buildings, market square, town wall",
    "wilderness": "open country, tracks and watercourses, scattered woodland",
    "dungeon": "enclosed underground complex, corridors and chambers, "
               "rubble and broken walls",
}


def load_regions() -> list[dict]:
    out = []
    for name in sorted(os.listdir(CONTENT)):
        if not name.endswith(".json"):
            continue
        with open(os.path.join(CONTENT, name), encoding="utf-8") as fh:
            r = json.load(fh)
        out.append({
            "region_key": r.get("region_key", ""),
            "name": r.get("name", ""),
            "region_type": r.get("region_type", ""),
            "description": r.get("description", ""),
            "locations": r.get("locations", {}),
        })
    return [r for r in out if r["region_key"]]


def prompt_for(region: dict) -> str:
    """
    The region's own description leads, then the shape of its graph.

    Naming a few of the actual places is what stops every dungeon coming back
    as the same dungeon — "two channels converging on a shaft" is a plan the
    model can draw, where "an underground complex" is not. Capped at six
    because past that the sentence averages into mush, the same way the
    location prompts do past fifty words.
    """
    body = " ".join((region.get("description") or "").split())
    places = [loc.get("name", "") for loc in list(region["locations"].values())[:6]]
    bits = [region["name"] + "."]
    if body:
        bits.append(body)
    if places:
        bits.append("Featuring: " + ", ".join(p for p in places if p) + ".")
    hint = BY_TYPE.get(region["region_type"])
    if hint:
        bits.append(hint + ".")
    bits.append(STYLE)
    return " ".join(bits)


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


def finish(src: str, dest: str) -> str:
    """
    Crop any bars, fit the 4:3 frame, and calm the whole thing down.

    The contrast pull is not a taste decision. The chart writes place names
    over this at small sizes with a thin stroke, and a map that keeps its full
    dynamic range eats them — the shipped plates are noticeably flatter than
    what the model hands back, which is the same conclusion reached by
    whoever drew them.
    """
    from PIL import Image, ImageEnhance

    im = Image.open(src).convert("RGB")
    w, h = im.size
    px = im.load()
    step_x, step_y = max(1, w // 64), max(1, h // 64)
    dark = lambda vals: max(vals) < 18

    top = 0
    while top < h // 3 and dark([sum(px[x, top]) / 3 for x in range(0, w, step_x)]):
        top += 1
    bottom = h - 1
    while bottom > h * 2 // 3 and dark([sum(px[x, bottom]) / 3 for x in range(0, w, step_x)]):
        bottom -= 1
    left = 0
    while left < w // 3 and dark([sum(px[left, y]) / 3 for y in range(0, h, step_y)]):
        left += 1
    right = w - 1
    while right > w * 2 // 3 and dark([sum(px[right, y]) / 3 for y in range(0, h, step_y)]):
        right -= 1

    im = im.crop((left, top, right + 1, bottom + 1))

    tw, th = FINAL
    cw, ch = im.size
    scale = max(tw / cw, th / ch)
    im = im.resize((max(1, round(cw * scale)), max(1, round(ch * scale))), Image.LANCZOS)
    rw, rh = im.size
    im = im.crop(((rw - tw) // 2, (rh - th) // 2, (rw - tw) // 2 + tw, (rh - th) // 2 + th))

    im = ImageEnhance.Contrast(im).enhance(0.82)
    im = ImageEnhance.Brightness(im).enhance(1.06)
    im = ImageEnhance.Color(im).enhance(0.80)

    os.makedirs(os.path.dirname(dest), exist_ok=True)
    im.save(dest)
    return f"{w}x{h} -> {tw}x{th}"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--only", nargs="*", default=[])
    ap.add_argument("--missing-only", action="store_true")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--candidates", type=int, default=1,
                    help="draw N seeds and keep them all as <key>_cN.png to "
                         "choose between; the plain <key>.png is left alone")
    args = ap.parse_args()

    regions = load_regions()
    if args.list:
        for r in regions:
            have = os.path.isfile(os.path.join(ART, r["region_key"] + ".png"))
            print(f"{'[art]' if have else '[   ]'} {r['region_key']:16} "
                  f"{r['region_type']:11} {r['name']}")
        return 0

    wanted = [r for r in regions if r["region_key"] in args.only] if args.only \
        else regions if args.all else []
    if not wanted:
        ap.error("nothing to do — pass --only <region_key>, --all, or --list")

    for r in wanted:
        dest = os.path.join(ART, r["region_key"] + ".png")
        if args.missing_only and os.path.isfile(dest):
            print(f"skip {r['region_key']} (has art)")
            continue
        if os.path.isfile(dest) and not args.force and args.candidates == 1:
            print(f"skip {r['region_key']} (exists; --force to replace)")
            continue

        prompt = prompt_for(r)
        print(f"\n{r['region_key']}  ({r['region_type']})")
        print(f"  {prompt[:150]}...")
        for i in range(args.candidates):
            seed = args.seed + i * 1000
            out = dest if args.candidates == 1 \
                else os.path.join(ART, f"{r['region_key']}_c{i + 1}.png")
            t0 = time.time()
            path = generate(prompt, seed)
            if not path:
                print(f"  seed {seed}: FAILED")
                continue
            note = finish(path, out)
            print(f"  seed {seed}: {os.path.basename(out)}  {note}  "
                  f"({time.time() - t0:.1f}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
