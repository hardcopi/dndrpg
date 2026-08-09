#!/usr/bin/env python3
"""
Draw the location backdrops with the local image model.

Talks to the Gradio app on 127.0.0.1:7860 over its plain REST interface rather
than through `gradio_client`, which is not installed and is not worth a
dependency for two HTTP calls. The call is a POST that returns an event id and
an SSE GET that returns the result, and both are in the standard library.

    python3 tools/gen_location_art.py --list
    python3 tools/gen_location_art.py --only flagon_common_room high_street
    python3 tools/gen_location_art.py --all --missing-only
    python3 tools/gen_location_art.py --all            # regenerate everything

Finished images land in assets/images/locations/<location_key>.png, which is
where the location screen looks for them — the key, never the pretty name.

Nothing is overwritten without --force except when --all is given without
--missing-only, which is the "regenerate the world" switch and says so.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content", "locations")
ART = os.path.join(ROOT, "assets", "images", "locations")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

WIDTH, HEIGHT = 1280, 720          # 16:9, which is what the location screen crops to
STEPS, GUIDANCE = 2, 4.5           # the model's own defaults; it is a 2-step sprint

# ---------------------------------------------------------------------------
# The style contract.
#
# Deliberately NOT the photographic one in LOCATION_PROMPTS.md. That document
# asks for "a still from a prestige fantasy series"; this asks for a painting,
# because a stylised set holds together far better when it is generated in one
# batch by one model — small incoherences read as brushwork rather than as
# mistakes, which is exactly what photorealism cannot forgive.
#
# The negative prompt is doing as much work as the positive one. Text is the
# main enemy: a sign with letters on it in a fantasy town will be gibberish
# every time, and the location screen already carries the name in prose.
# ---------------------------------------------------------------------------
STYLE = (
    "stylised painterly digital illustration, hand-painted concept art, "
    "visible brushwork, muted desaturated palette with one warm accent, "
    "strong readable silhouette, soft volumetric light, painted texture, "
    "grounded low fantasy, lived-in and weathered, atmospheric depth, "
    "wide establishing shot, eye level, no people in the foreground"
)

NEGATIVE = (
    "text, letters, words, signage, watermark, signature, caption, ui, hud, "
    "logo, frame, border, photorealistic, photograph, 3d render, "
    "cgi, anime, cartoon, chibi, cel shading, oversaturated, neon, lens flare, "
    "bloom, god rays, blurry, lowres, deformed, extra limbs, close-up portrait, "
    # The first batch came back with black bars top and bottom on the darker
    # interiors — the model reads "cinematic" in the style block and obliges.
    # The screen crops its own 16:9, so baked-in bars cost real picture.
    "letterbox, letterboxed, black bars, cinematic bars, matte bars, "
    "widescreen bars, pillarbox, vignette, film strip"
)

# A few words of art direction per location TYPE, so a room does not come back
# looking like a valley. The prose in the content file supplies the specifics.
BY_TYPE = {
    "town": "town exterior, cobbles, timber and stone buildings",
    "square": "open market square, stalls, crowd kept distant and small",
    "street": "narrow street between buildings, receding perspective",
    "gate": "fortified gate in a wall, road passing through",
    # `building` is a place you go INSIDE — the Flagon's common room, Tobin's
    # shop, the counting house — so it is an interior, which is what the
    # shipped art for it already is. Read off the existing plates rather than
    # guessed from the word.
    "building": "interior, enclosed room, light from windows and a fire",
    "room": "interior, enclosed, light from a window or fire",
    "site": "outdoor site in open country, wide sky",
    "camp": "small outdoor camp, tents and a fire",
    "dungeon": "underground, cut stone and rough rock, lantern light",
    "wilderness": "open wild country, weather in the sky",
    "arena": "enclosed fighting pit, banked earth, packed ground",
}


def load_locations() -> list[dict]:
    out = []
    for name in sorted(os.listdir(CONTENT)):
        if not name.endswith(".json"):
            continue
        with open(os.path.join(CONTENT, name), encoding="utf-8") as fh:
            region = json.load(fh)
        rtype = region.get("region_type", "")
        for key, loc in region.get("locations", {}).items():
            out.append({
                "key": key,
                "region": region.get("region_key", ""),
                "region_type": rtype,
                "name": loc.get("name", key),
                "type": loc.get("type", ""),
                "description": (loc.get("description") or "").strip(),
            })
    return out


def build_prompt(loc: dict) -> str:
    """
    The scene first, the style second.

    The authored description is the subject and leads, because it is the only
    part that makes this location and not a location. It is truncated: past
    roughly fifty words the model starts averaging the sentences together and
    the specific detail that made the place — the hooks still on the wall, the
    price board with a second column — is what gets averaged away.
    """
    body = " ".join(loc["description"].split())
    if len(body) > 340:
        cut = body[:340]
        body = cut[:cut.rfind(".") + 1] if "." in cut else cut
    hint = BY_TYPE.get(loc["type"]) or BY_TYPE.get(loc["region_type"]) or ""

    # A location's TYPE describes its shape; the REGION says whether there is
    # sky over it, and the shape hints alone happily put one there. The Old
    # City is thirty feet under a city and its rooms are typed `site`, `gate`
    # and `street` like anywhere else — so the stagnant pool came back as a
    # marsh at noon under an open horizon, correct in every detail except the
    # one that matters.
    #
    # FIRST, not last. Appending it left sky in the frame anyway: this is a
    # two-step distilled model with no classifier-free guidance, so the
    # negative prompt is inert (see `debar`) and the positive prompt is all
    # there is — and what it weights most is what it reads first. The authored
    # descriptions work against it too, because several of them mention
    # daylight coming down through the gratings, which is true and is exactly
    # the wrong thing for the model to seize on.
    lead = ""
    if loc["region_type"] == "dungeon":
        lead = ("Underground interior beneath a city. A low vaulted stone "
                "ceiling closes overhead and fills the top of the frame. "
                "No sky, no horizon, no open air. Lit by torches and lantern "
                "light in the dark.")

    bits = [lead, loc["name"] + ".", body]
    if hint:
        bits.append(hint + ".")
    bits.append(STYLE)
    return " ".join(b for b in bits if b)


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
    with urllib.request.urlopen(req, timeout=30) as resp:
        event_id = json.loads(resp.read()).get("event_id")
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


def debar(path: str, target=(WIDTH, HEIGHT), threshold: int = 18) -> str | None:
    """
    Crop baked-in black bars, then square the frame back to 16:9.

    Needed because the NEGATIVE prompt above does nothing. This is a two-step
    distilled model: at two steps it runs without classifier-free guidance, so
    there is no unconditional branch for a negative to push against, and the
    whole block is inert. Proved rather than assumed — regenerating one location
    with an extra nine anti-letterbox terms produced a byte-identical PNG, same
    md5. Every word of that negative, including the anti-text terms, has been
    decoration.

    So the bars are removed after the fact, where a deterministic answer is
    available and the sampler's opinion does not come into it. Returns the new
    size, or None if there was nothing to do.
    """
    try:
        from PIL import Image
    except ImportError:
        return None

    im = Image.open(path).convert("RGB")
    w, h = im.size
    px = im.load()
    step_x = max(1, w // 64)
    step_y = max(1, h // 64)

    def row_dark(y: int) -> bool:
        return max(sum(px[x, y]) / 3 for x in range(0, w, step_x)) < threshold

    def col_dark(x: int) -> bool:
        return max(sum(px[x, y]) / 3 for y in range(0, h, step_y)) < threshold

    top = 0
    while top < h // 3 and row_dark(top):
        top += 1
    bottom = h - 1
    while bottom > h * 2 // 3 and row_dark(bottom):
        bottom -= 1
    left = 0
    while left < w // 3 and col_dark(left):
        left += 1
    right = w - 1
    while right > w * 2 // 3 and col_dark(right):
        right -= 1

    cropped = im.crop((left, top, right + 1, bottom + 1))
    # Cover the target box and centre-crop, so the aspect is exact and nothing
    # is squashed — the location screen letterboxes it again if it disagrees.
    tw, th = target
    cw, ch = cropped.size
    scale = max(tw / cw, th / ch)
    resized = cropped.resize((max(1, round(cw * scale)), max(1, round(ch * scale))), Image.LANCZOS)
    rw, rh = resized.size
    box = ((rw - tw) // 2, (rh - th) // 2, (rw - tw) // 2 + tw, (rh - th) // 2 + th)
    final = resized.crop(box)
    if final.size == (w, h) and (top, left) == (0, 0) and (bottom, right) == (h - 1, w - 1):
        return None
    final.save(path)
    return f"{w}x{h} -> {tw}x{th} (cropped t{top} b{h - 1 - bottom} l{left} r{w - 1 - right})"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--only", nargs="*", default=[])
    ap.add_argument("--missing-only", action="store_true",
                    help="skip locations that already have a PNG")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--dry-run", action="store_true", help="print prompts, generate nothing")
    ap.add_argument("--fix-letterbox", action="store_true",
                    help="crop black bars off images already on disk and exit")
    args = ap.parse_args()

    if args.fix_letterbox:
        fixed = 0
        for name in sorted(os.listdir(ART)):
            if not name.endswith(".png"):
                continue
            note = debar(os.path.join(ART, name))
            if note:
                print(f"  {name:30s} {note}")
                fixed += 1
        print(f"\n{fixed} image(s) debarred")
        return 0

    locs = load_locations()
    if args.only:
        wanted = set(args.only)
        locs = [l for l in locs if l["key"] in wanted]
        missing = wanted - {l["key"] for l in locs}
        for m in sorted(missing):
            print(f"no such location: {m}", file=sys.stderr)
    elif not args.all and not args.list:
        ap.error("give --all, --only <keys>, or --list")

    if args.missing_only:
        locs = [l for l in locs
                if not os.path.exists(os.path.join(ART, l["key"] + ".png"))]

    if args.list:
        for l in locs:
            has = os.path.exists(os.path.join(ART, l["key"] + ".png"))
            print(f"  {'[art]' if has else '[   ]'} {l['key']:26s} {l['region']:16s} {l['type']}")
        print(f"\n{len(locs)} location(s)")
        return 0

    os.makedirs(ART, exist_ok=True)
    done = failed = skipped = 0
    for i, loc in enumerate(locs, 1):
        dest = os.path.join(ART, loc["key"] + ".png")
        if os.path.exists(dest) and not args.force and args.missing_only:
            skipped += 1
            continue
        prompt = build_prompt(loc)
        if args.dry_run:
            print(f"\n--- {loc['key']} ---\n{prompt}")
            continue
        # Seed derived from the key, so a rerun of one location reproduces it
        # and two locations never share a composition by accident.
        seed = (args.seed + sum(ord(c) for c in loc["key"]) * 7919) % (2 ** 31)
        print(f"[{i}/{len(locs)}] {loc['key']:26s} ", end="", flush=True)
        try:
            path = generate(prompt, seed)
        except (urllib.error.URLError, OSError) as exc:
            print(f"FAILED ({exc})")
            failed += 1
            continue
        if not path or not os.path.exists(path):
            print(f"FAILED (no image back: {path!r})")
            failed += 1
            continue
        shutil.copyfile(path, dest)
        print(f"ok -> {os.path.relpath(dest, ROOT)}")
        done += 1

    if not args.dry_run:
        print(f"\n{done} generated, {skipped} skipped, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
