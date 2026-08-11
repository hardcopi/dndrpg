#!/usr/bin/env python3
"""
Draw the character busts with the local image model, in the location set's style.

Busts only. The face is CUT from the bust afterwards by tools/cut_face.py rather
than generated beside it — two independent generations of "a weathered miner"
produce two different men, and the party rail and the dialogue theatre would
then be showing different people for the same character. One painting, two
crops, one person.

Deliberately does not touch:
  * `<key>_sheet.png`  — walk sheets, baked from layered paperdoll art by
                         Paperdoll.php. Not paintings and not ours to redraw.
  * `_idle`, `_kneel`, `_sleeping`, and the bare `<key>.png` standing frame.
  * `pc_*` and `npc_kessa` — player paperdoll bakes, tied to a character's
                         chosen appearance rather than to any NPC.

    python3 tools/gen_npc_art.py --list
    python3 tools/gen_npc_art.py --only kessa aldric
    python3 tools/gen_npc_art.py --all
    python3 tools/gen_npc_art.py --all --faces      # ...and re-cut every face

A sprite key is often SHARED — `farmer` dresses four different people — so a
shared key is prompted as an archetype and a key belonging to exactly one named
character is prompted from that character's own description. A bust that is too
specifically Pol Reddick is wrong on the other three.
"""

from __future__ import annotations

import argparse
import collections
import glob
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ART = os.path.join(ROOT, "assets", "images", "npcs")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

GEN_W, GEN_H = 768, 1024      # 3:4, generated large and reduced
BUST_W, BUST_H = 384, 512     # what the shipped busts are, near enough
STEPS, GUIDANCE = 2, 4.5

# Player paperdoll bakes and one stale duplicate. Not NPC art.
SKIP = {"pc_1534", "pc_173", "pc_434", "npc_kessa"}

# Same painterly contract as the locations, re-aimed at a person. The plain
# dark ground is the existing convention — every shipped bust has one, and the
# dialogue theatre composites the cut-out against its own panel.
# The period, said first and said plainly.
#
# STYLE below says "grounded low fantasy", which turns out to constrain the
# rendering and not the wardrobe. Officer Bram Ockley — role "Watch Officer" —
# came back as a twentieth-century policeman in a peaked cap, necktie and duty
# belt: the model read the job title, not the genre. Several other roles in this
# game have the same problem waiting in them (Quartermaster, Foreman, Clerk of
# accounts), because they are all words that exist in both centuries.
#
# Leading rather than appending, for the reason documented in
# gen_location_art.py: this model runs without classifier-free guidance, the
# negative prompt below is inert, and the positive prompt is weighted toward
# what it reads first.
# Trades the PERIOD clause dresses wrongly, and it is worth being exact about
# why rather than treating each as a one-off.
#
# PERIOD opens with "Wool, linen, leather, riveted mail and hand-forged iron"
# to anchor the century. The model reads a list of materials as a WARDROBE,
# and it is the first thing in the prompt — where, per the note in
# gen_location_art.py, this model weights hardest. So it dresses anybody whose
# own description does not dress them. Sib Marrell empties Rivermark's
# cesspits by dark and came back in riveted mail and steel pauldrons, twice,
# on two separate generations: he looked exactly like the watch he is
# described as knowing the underside of.
#
# The remedy is the one gen_race_art.py arrived at for dragonborn and half-orc
# — name the thing the model is SUBSTITUTING rather than describing the right
# thing harder, and put it where the weight is. "No armour, not a guard" does
# more work here than any amount of oilskin.
#
# Only keys that need it. A character whose description already dresses them
# does not belong in this table.
# SHORT, and said twice. The first draft of Sib's entry was two sentences of
# oilskin and negation; it removed most of the armour and cost the shot — the
# bust came back as a full standing figure with the head small in frame,
# because FRAMING sits between PERIOD and the subject and a long clause in
# front of it is displacement rather than emphasis. That is the finding
# gen_race_art.py records almost word for word: lengthening a clause to insist
# harder buys the insistence out of something else.
#
# So each entry is one short phrase, placed twice — once after PERIOD where the
# weight is and the mail was just named, once after the description where
# there is room. Same trick that made "back to back" survive in the race
# plates.
# The seed each of these shipped on, for the reason gen_module_cover.py keeps
# SHIPPED_SEED: the same seed and prompt reproduce the file on disk, and
# without it "regenerate that one" is a fresh roll of the dice. --seed is how
# to ask for it. Sib went out on 3; the default 11 and two others still put him
# in a mail shirt, and the wardrobe clause alone was not enough to shift it.
SHIPPED_SEED = {"sib_marrell": 3}

WARDROBE = {
    "sib_marrell": "No armour: oilskin cape over coarse wool.",
}

PERIOD = (
    "Medieval fantasy. Pre-industrial. Wool, linen, leather, riveted mail and "
    "hand-forged iron. No modern clothing of any kind — no peaked caps, no "
    "neckties, no uniform shirts, no buttons, no zips, no printed insignia, "
    "nothing machine-made."
)

# The shot, said second and said plainly, for the same reason PERIOD is said
# first.
#
# STYLE below already asks for "waist-up, three-quarter view" and a "plain dark
# neutral background", and NEGATIVE already forbids "full body, tiny head" —
# and both were losing. STYLE is appended AFTER the wearer's description, and
# NEGATIVE does nothing at all on this model, so the only framing instruction
# with any weight was arriving last.
#
# It lost to the descriptions. The Undervault's surface cast are written by
# what they DO — Wren brought a cart up the hill, Orrin sits at the stair head
# with a slate across his knees, Dessa comes up the spoil most days and sits —
# and the model painted the sentence: five scene illustrations with the figure
# small in frame, one of them standing beside a cart in snow. The shipped Old
# City busts survived the same code only because their descriptions happen to
# name duties rather than postures.
#
# So the shot is stated before the prose that would otherwise decide it. This
# is the third instance of the leading-clause trap in this repo and the first
# one that was not a wardrobe problem.
#
# It fixed four of the five. Orrin Bly's description opened with the word
# "Sits" and he came back seated again — a clause this strong, this early in
# the description, still outweighs a framing instruction that precedes the
# whole description. Reworded to lead with the man rather than his posture and
# he came back standing. So: this is the floor, not a guarantee. A description
# whose FIRST words are a posture will still paint that posture, and the fix
# for that one belongs in the description, which should be leading with who
# somebody is anyway.
FRAMING = (
    "A waist-up portrait. Head and shoulders fill the frame, head near the top, "
    "figure centred and facing the viewer, standing. Plain dark neutral "
    "background, no scenery, no furniture, no vehicles, no floor, no horizon."
)

STYLE = (
    "stylised painterly digital character portrait, hand-painted concept art, "
    "visible brushwork, muted desaturated palette with one warm accent, "
    "soft directional light, painted texture, grounded low fantasy, "
    "waist-up, three-quarter view, facing the viewer, calm neutral expression, "
    "plain dark neutral background, no props held up to camera"
)

NEGATIVE = (
    "text, letters, watermark, signature, logo, frame, border, "
    "photorealistic, photograph, 3d render, cgi, anime, cartoon, "
    "oversaturated, neon, blurry, lowres, deformed, extra limbs, extra fingers, "
    "full body, tiny head, cropped head, back view"
)

# Who the writing says these people are.
#
# The model has no idea. Kessa's own description — "Half-orc mercenary, drinking
# alone at the end of the bar" — contains no pronoun at all, so the first pass
# painted her as a man, which is the same failure that put a bearded portrait on
# Aggie Slate. Read out of the prose by counting pronouns across each wearer's
# description and their whole dialogue file, then checked by hand; a key whose
# wearers genuinely disagree is left unset and the model may choose.
#
# `?` is honest rather than lazy: `sweeper` dresses three men and one woman, and
# there is no portrait that is right for all four.
GENDER = {
    "aldric": "man", "baker": "man", "barbarian": "man", "bard": "woman",
    "captain": "man", "cleric": "man", "druid": "man", "elowen": "woman",
    "farmer": "man", "fighter": "man", "goblin_warlord": "woman",
    "innkeeper": "woman", "kessa": "woman", "merchant": "woman", "miner": "man",
    "monk": "man", "paladin": "man", "prisoner_1": "man", "prisoner_2": "man",
    "prisoner_3": "man", "ranger": "woman", "rogue": "man", "saurial": "man",
    "scribe": "man", "skulker": "man", "smith": "woman", "sorcerer": "woman",
    "warlock": "woman", "wizard": "woman",
    # The Old City cast. Each has a key of their own so the bust is painted
    # from their own description rather than from a shared archetype.
    "bram_ockley": "man", "sella_carrow": "woman", "foreman_dessel": "man",
    "jimmy_able": "man", "nell_carrow": "girl", "penned_boys": "boy",
    # The Undervault surface. Same rule — one wearer each, painted from their
    # own description. "Outfitter", "Salvage Buyer", "Stove-keeper" and
    # "Tallyman" are all jobs that exist in both centuries, which is the trap
    # PERIOD was written for; nothing else about them needs guarding.
    "wren_tallow": "woman", "odger_vance": "man", "marda_scole": "woman",
    "orrin_bly": "man", "dessa_kell": "woman",
    # Rivermark's named cast, given a key each on 2026-08-08. They had been
    # sharing fourteen archetype keys between thirty-three people — three
    # different men wearing `miner`, three wearing `warlock` — which is
    # invisible meeting them one at a time in the game and glaring on the
    # adventure book's NPC cards, four to a page. The archetypes stay for the
    # ambient walk-ons, which is what they were for.
    #
    # Every entry below is read from what the content itself says — the
    # pronouns in a person's own description and dialogue — and not guessed
    # from a name. Quartermaster Oyle is deliberately absent: the writing
    # never says, so the prompt omits the clause, exactly as it does for any
    # key this map has no entry for.
    "osgar_tull": "man", "tull_barrow": "man", "serjeant_ballow": "man",
    "bael_rourke": "man", "gest_pyle": "man", "dagen_wyke": "man",
    "ottilie_hale": "woman", "pol_reddick": "man", "corbin_waite": "man",
    "sten_harrow": "man", "joss_frayle": "man", "mott_wenn": "man",
    "mara_hearthstone": "woman", "nell_quist": "woman", "sera_vance": "woman",
    "wat_fenner": "man", "alder_pyke": "man", "dorn_ackley": "man",
    "tibald_ashe": "man", "brother_teodor": "man", "fessick": "man",
    "wick_lorrimer": "man", "corrin_dale": "man", "cully_tench": "man",
    "rilk": "man", "ludo_corse": "man", "sib_marrell": "man",
    "gillian_petch": "woman", "gubb_wrayne": "man", "aggie_slate": "woman",
    "wenna_coyle": "woman", "nesh_gray": "woman",
}

# Archetypes for keys that dress more than one person. Written to be true of
# everyone wearing them and specific enough to paint.
ARCHETYPE = {
    "baker":      "a broad working baker in a flour-dusted apron, sleeves rolled",
    "barbarian":  "a heavy-shouldered northern fighter in furs and worn leather",
    "bard":       "a sharp-eyed travelling singer in a patched travelling coat",
    "beggar":     "a thin ragged beggar in layered cast-off clothing",
    "captain":    "a watch captain in a mail shirt and a town surcoat",
    "cleric":     "a robed cleric with a plain pewter holy symbol",
    "druid":      "a hooded herbalist in oiled green wool, hands stained",
    "farmer":     "a weathered farmer in homespun and a leather jerkin",
    "fighter":    "a scarred soldier in a battered mail shirt",
    "innkeeper":  "a warm capable innkeeper in an apron over plain wool",
    "jester":     "a lean street entertainer in faded motley",
    "miner":      "a stooped quarryman in canvas and a leather cap, dust in the creases",
    "monk":       "a plain-robed monastic with a shaved crown",
    "paladin":    "an armoured watch officer in polished plate, helm under one arm",
    "prisoner_1": "a gaunt old man with a long white beard, rough prison wool",
    "prisoner_2": "a dark-bearded man in rough patched clothing",
    "prisoner_3": "a hard-faced man in rough patched clothing, close-cropped hair",
    "rogue":      "a neat watchful man in a dark travelling coat and a wide hat",
    "sorcerer":   "a masked woman fortune-teller in a hooded robe, face half-shadowed",
    "sweeper":    "a young street sweeper in a patched shawl",
    "warlock":    "a dark-hooded woman with a still, direct gaze",
    "wizard":     "an elderly woman scholar in ink-stained robes",
    "skulker":    "a hooded figure in dark leathers, face shadowed",
    "scribe":     "a bearded clerk in a good but worn gown, ink on the fingers",
    "smith":      "a strong woman smith in a scorched leather apron",
    "merchant":   "a poised dark-haired woman in a good travelling coat",
    "saurial":    "a lizardfolk trader with fine scales and a shrewd eye",
    "goblin_warlord": "a goblin chieftain in ill-fitting human plate armour",
}


# The expression ladder.
#
# `busts.json` gives a COUNT and nothing else — no index means anything on its
# own — so these were read back out of the writing that asks for them. Ilse's
# 6 is the one line where she turns on you ("You have not asked me a single
# question you did not already have an answer for"); her 8 is the packing scene
# where she says she is not going to shout. Reed's 5 is a watchman spitting on
# her boots, her 7 is standing up to refuse. The ladder runs from composed to
# hard, which is what both of them actually do across their trees.
#
# Everything else in the prompt is held identical to the base bust, same seed
# included, so the person stays the same person and only the face moves.
EXPRESSIONS = {
    1: "calm neutral expression, composed",
    2: "speaking, intent and engaged, mouth slightly open",
    3: "alert and watchful, attentive, eyes level",
    4: "guarded concern, patient, brow faintly drawn",
    5: "weary and grim, tired around the eyes",
    6: "sharp and reproachful, jaw set, hard stare",
    7: "wry and conceding, one brow raised, faint dry half-smile",
    8: "controlled anger, holding it in, cold and still",
}


def expressions_used() -> dict[str, int]:
    """
    The highest expression index the content actually asks of each sprite.

    Six keys declare eight expressions; the writing uses eight of Ilse's, seven
    of Reed's, and exactly one of the rest. Generating all forty-eight would
    spend most of its time on files nothing will ever open.
    """
    sprite = {}
    for path in glob.glob(os.path.join(ROOT, "content/npcs/*.json")):
        with open(path, encoding="utf-8") as fh:
            d = json.load(fh)
        sprite[d["npc_key"]] = d.get("sprite_key")

    top: dict[str, int] = {}

    def walk(node, key):
        if isinstance(node, dict):
            e = node.get("expression")
            if isinstance(e, int) and key:
                top[key] = max(top.get(key, 1), e)
            for v in node.values():
                walk(v, key)
        elif isinstance(node, list):
            for x in node:
                walk(x, key)

    for path in glob.glob(os.path.join(ROOT, "content/dialog/*.json")):
        npc = os.path.basename(path)[:-5]
        with open(path, encoding="utf-8") as fh:
            walk(json.load(fh), sprite.get(npc))
    return top


def sprite_usage() -> dict[str, list[dict]]:
    """sprite_key -> the content rows that wear it."""
    out = collections.defaultdict(list)
    for pattern, kind in (("content/npcs/*.json", "npc"),
                          ("content/companions/*.json", "companion")):
        for path in glob.glob(os.path.join(ROOT, pattern)):
            with open(path, encoding="utf-8") as fh:
                d = json.load(fh)
            if d.get("sprite_key"):
                d["_kind"] = kind
                out[d["sprite_key"]].append(d)
    return out


def keys_with_busts() -> list[str]:
    return sorted(
        os.path.basename(p)[:-len("_bust.png")]
        for p in glob.glob(os.path.join(ART, "*_bust.png"))
        if os.path.basename(p)[:-len("_bust.png")] not in SKIP
    )


def build_prompt(key: str, wearers: list[dict], expression: int = 1) -> str:
    """
    One wearer means the bust may be that person. More than one means it may
    not be anybody in particular — it has to sit on all of them.
    """
    # A companion appears twice — once as the NPC you meet and once as the
    # recruitable template — and they are the same person. Counting them as two
    # wearers made Kessa "shared", so she was prompted as a bare key with no
    # description at all and would have come back as a stranger.
    distinct = {}
    for w in wearers:
        distinct.setdefault((w.get("name") or "").strip().lower() or id(w), w)
    wearers = list(distinct.values())

    named = [w for w in wearers if w.get("description")]
    if len(wearers) == 1 and named:
        w = named[0]
        who = w.get("name", key)
        role = w.get("role") or w.get("title") or ""
        desc = " ".join((w.get("description") or "").split())
        if len(desc) > 240:
            cut = desc[:240]
            desc = cut[:cut.rfind(".") + 1] if "." in cut else cut
        race = " ".join(x for x in [w.get("race"), w.get("class")] if x)
        sex = GENDER.get(key)
        who_is = f"{who}, a {sex}," if sex else f"{who},"
        wardrobe = WARDROBE.get(key, "")
        bits = [PERIOD, wardrobe, FRAMING, who_is,
                f"{role}." if role else "",
                f"{race}." if race else "", desc, wardrobe]
    else:
        bits = [PERIOD, FRAMING, ARCHETYPE.get(key, key.replace("_", " ")) + "."]
    bits.append(STYLE)
    prompt = " ".join(b for b in bits if b)
    if expression and expression != 1:
        # Swap the expression clause rather than appending a second one, or the
        # prompt asks for "calm neutral" and "controlled anger" in one breath
        # and the model splits the difference.
        prompt = prompt.replace("calm neutral expression",
                                EXPRESSIONS.get(expression, EXPRESSIONS[1]))
    return prompt


def generate(prompt: str, seed: int, timeout: int = 300) -> str | None:
    payload = json.dumps({
        "data": [prompt, NEGATIVE, GEN_W, GEN_H, STEPS, GUIDANCE, seed, False]
    }).encode()
    req = urllib.request.Request(
        f"{BASE}/gradio_api/call/generate",
        data=payload, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        event_id = json.loads(resp.read()).get("event_id")
    if not event_id:
        return None
    deadline = time.time() + timeout
    with urllib.request.urlopen(
            f"{BASE}/gradio_api/call/generate/{event_id}", timeout=timeout) as stream:
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
                    p = first.get("path") or first.get("name")
                    if p:
                        return p
                elif isinstance(first, str):
                    return first
    return None


def place(src: str, key: str, suffix: str = "_bust") -> None:
    """Trim any bars the sampler baked in, then fit the shipped bust size."""
    from PIL import Image
    im = Image.open(src).convert("RGB")
    w, h = im.size
    px = im.load()
    sx, sy = max(1, w // 64), max(1, h // 64)
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
    rw, rh = im.size
    # Centred horizontally, anchored to the TOP: a portrait loses its chin more
    # gracefully than its forehead, and cut_face.py takes its square from the top.
    left = (rw - BUST_W) // 2
    im.crop((left, 0, left + BUST_W, BUST_H)).save(os.path.join(ART, f"{key}{suffix}.png"))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--only", nargs="*", default=[])
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--faces", action="store_true", help="re-cut faces afterwards")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--seed", type=int, default=11)
    ap.add_argument("--expressions", action="store_true",
                    help="generate _bust_N variants up to the highest index the content uses")
    args = ap.parse_args()

    if args.expressions:
        usage = sprite_usage()
        need = {k: n for k, n in expressions_used().items()
                if k and n > 1 and k not in SKIP}
        if args.only:
            need = {k: n for k, n in need.items() if k in set(args.only)}
        total = sum(n - 1 for n in need.values())
        print(f"{len(need)} sprite(s), {total} variant(s) to draw "
              f"({', '.join(f'{k}:2-{n}' for k, n in sorted(need.items()))})\n")
        made = fell = 0
        for key, top in sorted(need.items()):
            # Same seed as the base bust. The face is being asked to change; the
            # person is not.
            seed = (args.seed + sum(ord(c) for c in key) * 7919) % (2 ** 31)
            for n in range(2, top + 1):
                prompt = build_prompt(key, usage.get(key, []), expression=n)
                if args.dry_run:
                    print(f"--- {key}_bust_{n} ---\n{prompt}\n")
                    continue
                print(f"  {key}_bust_{n:<2} ", end="", flush=True)
                try:
                    path = generate(prompt, seed)
                except (urllib.error.URLError, OSError) as exc:
                    print(f"FAILED ({exc})")
                    fell += 1
                    continue
                if not path or not os.path.exists(path):
                    print("FAILED (no image)")
                    fell += 1
                    continue
                place(path, key, f"_bust_{n}")
                print("ok")
                made += 1
        if not args.dry_run:
            # busts.json is what DialogEngine clamps against. Told the truth
            # about what exists, so a node asking for an expression nobody drew
            # falls back to one that is there instead of a broken image.
            bj = os.path.join(ART, "busts.json")
            with open(bj, encoding="utf-8") as fh:
                counts = json.load(fh)
            for key in keys_with_busts():
                counts[key] = need.get(key, 1)
            with open(bj, "w", encoding="utf-8") as fh:
                json.dump(counts, fh, indent=1, sort_keys=True)
                fh.write("\n")
            print(f"\n{made} variant(s) drawn, {fell} failed; busts.json updated")
        return 1 if fell else 0

    usage = sprite_usage()
    keys = args.only or keys_with_busts()
    keys = [k for k in keys if k not in SKIP]

    if args.list:
        for k in keys:
            who = usage.get(k, [])
            print(f"  {k:16s} {len(who)} wearer(s)  {', '.join(w.get('name', '?') for w in who)}")
        print(f"\n{len(keys)} sprite key(s)")
        return 0
    if not args.all and not args.only:
        ap.error("give --all, --only <keys>, or --list")

    done = failed = 0
    for i, key in enumerate(keys, 1):
        prompt = build_prompt(key, usage.get(key, []))
        if args.dry_run:
            print(f"\n--- {key} ---\n{prompt}")
            continue
        seed = (args.seed + sum(ord(c) for c in key) * 7919) % (2 ** 31)
        print(f"[{i}/{len(keys)}] {key:16s} ", end="", flush=True)
        try:
            path = generate(prompt, seed)
        except (urllib.error.URLError, OSError) as exc:
            print(f"FAILED ({exc})")
            failed += 1
            continue
        if not path or not os.path.exists(path):
            print(f"FAILED (no image: {path!r})")
            failed += 1
            continue
        place(path, key)
        print("ok")
        done += 1

    if args.dry_run:
        return 0

    if args.faces:
        print("\nre-cutting faces:")
        for key in keys:
            subprocess.run(
                [sys.executable, os.path.join(ROOT, "tools", "cut_face.py"), key, "--apply"],
                cwd=ROOT, check=False,
            )

    print(f"\n{done} bust(s) generated, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
