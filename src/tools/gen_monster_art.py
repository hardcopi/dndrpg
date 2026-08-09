#!/usr/bin/env python3
"""
Draw the creature busts with the local image model, in the NPC set's style.

The fourth of the art generators and the one that was missing. The bestiary is
the only art set in this project nobody could regenerate: `assets/images/monsters`
was shipped as a pack and every creature added since has had to borrow somebody
else's picture. That is survivable in a fight, where a token is 30px and named
underneath — and it is not survivable in the adventure book, where the stat
blocks print in a column and Giant Toad and Grey Ooze come out as the same
animal because they share the `ooze` sprite key.

Busts only, and faces are CUT from them by tools/cut_face.py rather than drawn
beside them — the rule gen_npc_art.py states and for the same reason: two
generations of "a pale burrowing thing" are two different animals, and the
board and the book would then disagree about what the party is fighting.

Deliberately does not touch:
  * `<key>.png`, `_sheet`, `_idle`, `_kneel` — the pack's walk frames. Not
    paintings and not ours to redraw.
  * `battlers/<key>.png` — the nine full-body cuts. ui-combat.js already falls
    back to the bust when one is missing, which is the whole reason a creature
    can have a new sprite key without a battler being drawn for it.

    python3 tools/gen_monster_art.py --list
    python3 tools/gen_monster_art.py --only wolf worg
    python3 tools/gen_monster_art.py --missing        # every book creature with no bust
    python3 tools/gen_monster_art.py --missing --faces

A creature is prompted from its OWN row — size, type, and the traits that say
what it does — because unlike an NPC sprite key, a monster key is never shared
by two different animals once this tool has been run over them.
"""

from __future__ import annotations

import argparse
import glob
import json
import os
import subprocess
import time
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ART = os.path.join(ROOT, "assets", "images", "monsters")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

GEN_W, GEN_H = 768, 1024
BUST_W, BUST_H = 384, 512
STEPS, GUIDANCE = 2, 4.5

# The same three leading clauses gen_npc_art.py uses, and for the reason its
# header records at length: this model runs without classifier-free guidance,
# so the negative prompt is inert and the positive prompt is weighted toward
# what it reads first. Anything that must be true has to be said early.
#
# PERIOD is about the world rather than the wardrobe here — a creature wears
# nothing — but it still earns its place: "City Guard" and "Bandit" are in this
# set, they are people, and they are exactly the job titles that come back in a
# peaked cap when nothing anchors the century.
PERIOD = (
    "Medieval fantasy. Pre-industrial. No modern objects of any kind — no "
    "machinery, no plastics, no printed insignia, nothing manufactured."
)

FRAMING = (
    "A creature portrait, head and upper body filling the frame, seen from the "
    "front, centred. Plain dark neutral background, no scenery, no floor, no "
    "horizon, no other creatures."
)

# An ooze has no head, and FRAMING above demands one — "head and upper body
# filling the frame, seen from the front" — so the model painted Grey Ooze as a
# grey humanoid with a face and a jaw. That is the leading-clause trap this file
# already documents for wardrobe, met a third way: the framing instruction is
# obeyed even when the creature it is being applied to has no such anatomy.
#
# So a formless creature gets its own framing, and the clause that would have
# given it a face is replaced rather than argued with.
FRAMING_FORMLESS = (
    "A close study of a formless creature filling the frame: a shapeless mass "
    "of wet translucent matter, pooling and running. It has NO face, no eyes, "
    "no head, no mouth, no limbs and no bones. Plain dark neutral background, "
    "no scenery, no floor, no horizon."
)

# Which creatures that applies to, by their own `type`. A type rather than a
# key list: the next slime somebody authors gets it for free.
FORMLESS_TYPES = ("ooze", "slime")

STYLE = (
    "stylised painterly digital creature portrait, hand-painted concept art, "
    "visible brushwork, muted desaturated palette, soft directional light, "
    "painted texture, grounded low fantasy, plain dark neutral background"
)

NEGATIVE = (
    "text, letters, watermark, signature, logo, frame, border, "
    "photograph, 3d render, cartoon, cute, chibi, anime, "
    "multiple creatures, full body, tiny head, weapon held up to camera"
)


def monsters() -> dict[str, dict]:
    """monster_key -> its content row."""
    out = {}
    for path in glob.glob(os.path.join(ROOT, "content/monsters/*.json")):
        with open(path, encoding="utf-8") as fh:
            d = json.load(fh)
        out[d["monster_key"]] = d
    return out


def art_key(row: dict) -> str:
    """Which file a creature's picture is in. Its sprite key, or its own."""
    return (row.get("sprite_key") or row["monster_key"]).strip()


def build_prompt(row: dict) -> str:
    """
    What this creature is, from its own row.

    The traits are where a bestiary says what a thing LOOKS like — "Peat water
    preserves what it takes", "the horror has been in the black pools long
    enough to be part of them" — so they go in ahead of the bare type. A prompt
    built from `size` and `type` alone paints the dictionary definition of an
    aberration and not this one.
    """
    name = row.get("name", row["monster_key"])
    kind = " ".join(x for x in [row.get("size"), row.get("type")] if x)
    traits = row.get("traits") or {}
    if isinstance(traits, dict):
        told = " ".join(f"{k}: {v}" for k, v in traits.items())
    elif isinstance(traits, list):
        told = " ".join(
            f"{t.get('name', '')}: {t.get('desc', t.get('text', ''))}"
            for t in traits if isinstance(t, dict)
        )
    else:
        told = str(traits or "")
    told = " ".join(told.split())
    if len(told) > 260:
        cut = told[:260]
        told = cut[:cut.rfind(".") + 1] if "." in cut else cut

    kind_l = (row.get("type") or "").lower()
    framing = FRAMING_FORMLESS if any(t in kind_l for t in FORMLESS_TYPES) else FRAMING
    bits = [PERIOD, framing, f"{name}, a {kind}." if kind else f"{name}.", told, STYLE]
    return " ".join(b for b in bits if b)


def generate(prompt: str, seed: int, timeout: int = 300) -> str | None:
    """Post to the model's Gradio API and wait for the file it wrote."""
    payload = json.dumps({
        "data": [prompt, NEGATIVE, GEN_W, GEN_H, STEPS, GUIDANCE, seed, False]
    }).encode()
    req = urllib.request.Request(
        f"{BASE}/gradio_api/call/generate", data=payload,
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        event_id = json.loads(resp.read()).get("event_id")
    if not event_id:
        return None
    deadline = time.time() + timeout
    with urllib.request.urlopen(
        f"{BASE}/gradio_api/call/generate/{event_id}", timeout=timeout
    ) as stream:
        for raw in stream:
            if time.time() > deadline:
                return None
            line = raw.decode("utf-8", "replace").strip()
            if not line.startswith("data:"):
                continue
            body = line[5:].strip()
            # KEEP READING past a null. The stream sends `event: heartbeat`
            # with `data: null` every few seconds while the model works, and
            # returning on the first one means every generation reports "no
            # image" a second after it was asked for. That is exactly how the
            # first eighteen creatures failed.
            if not body or body == "null":
                continue
            try:
                data = json.loads(body)
            except json.JSONDecodeError:
                continue
            if isinstance(data, list) and data:
                first = data[0]
                if isinstance(first, dict):
                    got = first.get("path") or first.get("name")
                    if got:
                        return got
                elif isinstance(first, str):
                    return first
    return None


def place(src: str, key: str) -> None:
    """Trim the letterboxing, scale to the shipped bust size, write it."""
    from PIL import Image

    im = Image.open(src).convert("RGB")
    w, h = im.size
    px = im.load()
    sx = max(1, w // 64)
    dark = lambda vals: max(vals) < 18
    top = 0
    while top < h // 3 and dark([sum(px[x, top]) / 3 for x in range(0, w, sx)]):
        top += 1
    bot = h - 1
    while bot > h * 2 // 3 and dark([sum(px[x, bot]) / 3 for x in range(0, w, sx)]):
        bot -= 1
    im = im.crop((0, top, w, bot + 1))

    cw, ch = im.size
    scale = max(BUST_W / cw, BUST_H / ch)
    im = im.resize((max(1, round(cw * scale)), max(1, round(ch * scale))), Image.LANCZOS)
    rw, _ = im.size
    # Top-anchored, as the NPC busts are: cut_face.py takes its square from the
    # top, and a head loses its chin more gracefully than its crown.
    left = (rw - BUST_W) // 2
    im.crop((left, 0, left + BUST_W, BUST_H)).save(os.path.join(ART, f"{key}_bust.png"))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--only", nargs="*", default=[], help="monster keys")
    ap.add_argument("--missing", action="store_true",
                    help="every creature whose art key has no bust")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--faces", action="store_true", help="cut faces afterwards")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--seed", type=int, default=11)
    args = ap.parse_args()

    rows = monsters()

    if args.list:
        shared: dict[str, list[str]] = {}
        for key, row in sorted(rows.items()):
            shared.setdefault(art_key(row), []).append(row["name"])
        for k, names in sorted(shared.items()):
            has = "y" if os.path.exists(os.path.join(ART, f"{k}_bust.png")) else "-"
            print(f"  {k:20s} bust:{has}  {', '.join(names)}")
        print(f"\n{len(shared)} art key(s), {len(rows)} creature(s)")
        return 0

    keys = list(args.only)
    if args.missing:
        for key, row in sorted(rows.items()):
            if not os.path.exists(os.path.join(ART, f"{art_key(row)}_bust.png")):
                keys.append(key)
    if not keys:
        ap.error("give --only <monster keys>, --missing, or --list")

    done = failed = 0
    for i, key in enumerate(keys, 1):
        row = rows.get(key)
        if not row:
            print(f"[{i}/{len(keys)}] {key:20s} SKIPPED (no content row)")
            failed += 1
            continue
        prompt = build_prompt(row)
        if args.dry_run:
            print(f"\n--- {key} ({art_key(row)}) ---\n{prompt}")
            continue
        seed = (args.seed + sum(ord(c) for c in key) * 7919) % (2 ** 31)
        print(f"[{i}/{len(keys)}] {art_key(row):20s} ", end="", flush=True)
        try:
            path = generate(prompt, seed)
        except (urllib.error.URLError, OSError) as exc:
            print(f"FAILED ({exc})")
            failed += 1
            continue
        if not path or not os.path.exists(path):
            print("FAILED (no image)")
            failed += 1
            continue
        place(path, art_key(row))
        print("ok")
        done += 1

    if args.dry_run:
        return 0

    if args.faces:
        print("\ncutting faces:")
        for key in keys:
            row = rows.get(key)
            if not row:
                continue
            subprocess.run(
                [sys.executable, os.path.join(ROOT, "tools", "cut_face.py"),
                 art_key(row), "--apply"],
                cwd=ROOT, check=False,
            )

    # busts.json is what load_content.py reads `bust_count` from, and a key it
    # does not list warns and is assumed to have one expression. Told the truth
    # about what is now on disk.
    index = os.path.join(ART, "busts.json")
    with open(index, encoding="utf-8") as fh:
        counts = json.load(fh)
    for path in glob.glob(os.path.join(ART, "*_bust.png")):
        counts.setdefault(os.path.basename(path)[:-len("_bust.png")], 1)
    with open(index, "w", encoding="utf-8") as fh:
        json.dump(counts, fh, indent=1, sort_keys=True)
        fh.write("\n")

    print(f"\n{done} bust(s) generated, {failed} failed; busts.json updated")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
