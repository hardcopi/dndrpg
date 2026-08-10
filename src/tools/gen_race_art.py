#!/usr/bin/env python3
"""
Draw one plate per playable race: a man and a woman of that people, back to
back, painted in the same hand as the busts and the location plates.

    python3 tools/gen_race_art.py --list
    python3 tools/gen_race_art.py --only sarsen
    python3 tools/gen_race_art.py --only sarsen --seeds 4    # judge a few, keep one
    python3 tools/gen_race_art.py --all

Into `assets/images/races/<race_key>.png`, which the character creator shows
beside the race's description and simply omits when the file is not there — the
same convention the module covers use, and for the same reason: art is by
filename here, not by a column, so a race nobody has painted is normal rather
than broken.

WHY THIS IS NOT gen_npc_art.py WITH A DIFFERENT FOLDER
------------------------------------------------------
That tool paints ONE person, centred, facing the viewer, and everything in it —
the framing clause, the bar-trimming crop, the fit to 384x512, the face cut
afterwards — is built around a single head near the top of a tall frame. This
is a two-figure composition in landscape, and the two rules that matter most
here (both faces visible, the pair turned away from each other) are ones a
single-portrait prompt never had to state.

What it DOES share is the hard-won part, and it is copied deliberately rather
than imported, because these are prompt fragments and not code:

  * The negative prompt does nothing. This model is a two-step distilled
    sampler running without classifier-free guidance, so there is no
    unconditional branch for a negative to push against. It is sent because the
    endpoint's signature takes one. Anything that must be true goes in the
    POSITIVE prompt.
  * The positive prompt is weighted toward what it reads FIRST. Three separate
    bugs in this repo came from getting that ordering wrong — open sky over an
    underground dungeon, a medieval watch officer in a necktie, and five busts
    that came back as scene illustrations because the shot was asked for last.
  * So the clause order below IS the design: ground, identity, pose, period,
    detail, style. It is not the order a person would write them in, and it is
    the fourth instance of the same trap in this repo — see prompt_for().

THE COMPOSITION IS THE WHOLE DIFFICULTY
---------------------------------------
"Back to back" and "both faces visible" are in tension, and asked for loosely
the model resolves the tension by painting two backs. What works is saying the
turn twice and in two vocabularies: once as a relationship between the figures
(shoulder blades touching, turned away from each other) and once as an
instruction per figure (the left one looking off to the left, the right one
looking off to the right, both faces toward the viewer's side of the picture).
That worked on the first seed and has not needed touching since.

The word "silhouette" is avoided entirely — it pulls the whole plate to a black
cut-out against a bright ground.

WHAT THE FIRST DRAFT GOT WRONG, WHICH WAS NOT THE COMPOSITION
-------------------------------------------------------------
It opened with ninety words of pose, then the period clause, and only then who
these people were. Three seeds came back beautifully composed — back to back,
correctly turned, faces visible — and painted two ordinary humans on a pale
grey studio wall: no stone skin, no mineral veining, no chisel marks, and a
background the opposite of the one asked for. Both instructions were in the
prompt. Both were behind a hundred and thirty words of something else.

The fix was ordering, not wording. The ground moved to the first six words and
the race moved to the second clause, ahead of the pose that had been beating
it. That is why `RACES` splits each people into `identity` and `detail`: the
handful of words that make somebody say "that is a Sarsen" have to be read
early, and the wardrobe does not.
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
ART = os.path.join(ROOT, "assets", "images", "races")
BASE = os.environ.get("QWEN_URL", "http://127.0.0.1:7860")

# Landscape, because the subject is two figures side by side. Generated large
# and reduced, as the busts are.
GEN_W, GEN_H = 1024, 768
OUT_W, OUT_H = 768, 576
STEPS, GUIDANCE = 2, 4.5

# Which seed produced what is on disk. Same prompt and same seed reproduce it
# byte for byte, so this is the difference between a plate that can be redrawn
# and one that can only be re-rolled.
#
# Sarsen took fifteen images across five prompt revisions, and the log of that
# is in the comments on PLATE, `identity` and `detail` — worth reading before
# adding the next race, because the failure was never the composition. That
# worked on the first seed and every version since. What kept breaking was the
# race itself surviving beside it.
SHIPPED_SEED = {
    "sarsen": 51,
    # The SRD nine, drawn once the Sarsen had settled the prompt shape. Six of
    # them came right on the first seed, which is the payoff for those fifteen
    # images. The three that did not each failed in a way already described in
    # their entry below, and each is a variation on the same thing: a race
    # whose identifying feature is small or shared with a neighbouring race
    # loses it to the model's nearest common template.
    "dwarf": 51, "elf": 51, "half_elf": 51, "halfling": 51,
    "human": 51, "tiefling": 51,
    "dragonborn": 61,
    "half_orc": 71,
    # The weakest plate of the ten, shipped knowingly. Its pose, ground and
    # hand are right and its people read as small elderly humans in a tinker's
    # apron rather than as gnomes — the big ears and round skull do not
    # survive. Every version that got the gnome features also lost the
    # composition (see the entry below), and between the two the composition is
    # what makes it one plate in a set of ten. Worth another attempt by anybody
    # who has a better idea than "say it twice".
    "gnome": 71,
}

# The ground, said first and said in six words.
#
# The first draft opened with ninety words of pose and put "plain dark neutral
# background" at the end of them, with STYLE saying it again afterwards. It came
# back on a pale grey studio wall both times. Every bust and plate in this game
# is on a dark ground and the creator's panel is dark behind it, so a white
# plate reads as a photograph somebody pasted in.
#
# Six words, first, and the sampler obeys them.
#
# The headcount is here too, and it is here because "Two figures only" sitting
# in POSE was not enough: one seed came back with three. The count is now said
# in the first line and again in the pose, which is the same
# say-it-twice-in-two-vocabularies trick that made back-to-back work.
#
# "Painted" is in the first four words for a related reason. The identity
# clause below has to say "granite" and "limestone" to get stone-coloured skin
# at all, and those words pull the whole rendering toward a photographed rock
# — the first revision came back as a 3D statue with none of the brushwork the
# rest of this game's art has. STYLE says "hand-painted" at the end and lost.
PLATE = (
    "A painted character plate on a plain black background. "
    "Exactly two figures: one man and one woman."
)

# WHO THEY ARE, SAID SECOND — and this is the ordering that mattered most.
#
# The race description used to arrive third, after the pose and the period
# clause, which is a hundred and thirty words in. What came back was two
# perfectly composed ordinary humans: back to back, correctly turned, and with
# none of the grey stone skin, mineral seaming or chisel-marked forearms that
# are the entire reason this people is not just a tall human. The pose survived
# and the race did not, because the pose was what the model read first.
#
# So `RACES` is split. The identity — the handful of words that would make
# somebody say "that is a Sarsen" — is placed here, ahead of everything except
# the background. The wardrobe and the small print go in DETAIL, after the
# period clause, where they have always belonged.

# The pose, said third. Unchanged from the first draft, which got the
# composition right on the first seed: back to back, both faces visible, one
# turned each way. Saying the turn twice — once as a relationship between the
# figures and once as an instruction per figure — is what stops the model
# resolving "back to back" into two backs.
POSE = (
    "Two people, a man and a woman, standing back to back, shoulder blades "
    "touching at the centre of the picture. Waist-up, both heads near the top "
    "of the frame. The figure on the left is turned to face the viewer's left; "
    "the figure on the right is turned to face the viewer's right. Both faces "
    "clearly visible, three-quarter view, seen from the front — not from "
    "behind. Two figures only, no scenery, no floor, no horizon, no props."
)

# The period, said second. Same clause as the busts, same reason: half the
# words that describe a trade exist in two centuries and the model will happily
# pick the wrong one.
PERIOD = (
    "Medieval fantasy. Pre-industrial. Wool, linen, leather, riveted mail and "
    "hand-forged iron. No modern clothing of any kind — no peaked caps, no "
    "neckties, no uniform shirts, no buttons, no zips, no printed insignia, "
    "nothing machine-made."
)

STYLE = (
    "stylised painterly digital character art, hand-painted concept art, "
    "visible brushwork, muted desaturated palette with one warm accent, "
    "soft directional light from above, painted texture, grounded low fantasy, "
    "calm neutral expressions, plain dark neutral background"
)

NEGATIVE = (
    "text, letters, watermark, signature, logo, frame, border, "
    "photorealistic, photograph, 3d render, cgi, anime, cartoon, "
    "oversaturated, neon, blurry, lowres, deformed, extra limbs, extra fingers, "
    "full body, tiny head, cropped head, rear view, backs to the viewer, "
    "three people, crowd, silhouette"
)

# What a painter needs to know, which is not what a player is told.
#
# `races.description` is about where a people stand in Rivermark — who lends
# money, who witnesses contracts, who the priory disapproves of — and almost
# none of that can be painted. These are bodies, faces and cloth, and they lead
# with the body for the reason in the docstring: whatever the description says
# first is what gets painted.
#
# ONE PLATE PER RACE, NOT PER ROW.
#
# `races` has fifteen rows and ten names: a Wood Elf and a High Elf are both
# elves and there is one painting of elves. `create.js` keys the filename off
# the race NAME for the same reason, so what belongs in an entry here is what is
# true of every subrace under it — no drow colouring on the elf plate, no hill
# dwarf's particular trade on the dwarf.
#
# Every entry follows the shape the Sarsen cost fifteen images to find:
#
#   identity  ~30 words, read second in the prompt, leading with the ONE
#             physical fact that identifies the people, and naming both figures
#             so the trait does not land on the man alone. Longer than this and
#             it starts eating the pose.
#   detail    read after PERIOD. Restates that same identifying fact once —
#             this is the say-it-twice that makes it stick — then wardrobe, then
#             one line telling the two figures apart.
#
# The wardrobe is Rivermark's, not a generic fantasy wardrobe: these people are
# in this valley, and `races.description` already says what they do in it.
RACES = {
    "sarsen": {
        # Read before the pose and before the period. Skin first, because it is
        # the one thing a glance has to land on, and "stone" said three ways
        # because said once it comes back as a tan.
        # SHORT, and every word of it fought for.
        #
        # "Not human skin — grey stone" worked too well: the man came back as a
        # cracked statue, clothing and all, and the woman stayed an ordinary
        # pale human beside him. The obvious fix — say it belongs to both of
        # them, say they are alive, say it at length — made things worse in a
        # way worth recording: at sixty words this clause started outweighing
        # the POSE that follows it, and three seeds came back face to face,
        # full length, and in one case as two men. Length here is not emphasis;
        # it is displacement.
        #
        # So: thirty words. "Both" carries the symmetry, "living skin" carries
        # the not-a-statue, and the pose gets its weight back.
        "identity": (
            "Both the man and the woman have living stone-grey skin — cool "
            "grey-brown like weathered limestone, faint pale mineral veining "
            "at the temples and shoulders. Heavy brows, broad flat cheekbones, "
            "blunt jaws."
        ),
        # Read after the period clause, where wardrobe belongs — and where the
        # skin gets said a SECOND time.
        #
        # Shortening `identity` to thirty words gave the pose back and lost the
        # stone: three more seeds of perfectly composed ordinary humans. The
        # two requirements were trading off against each other on length alone,
        # which is the point at which the answer is not to keep adjusting the
        # ratio. Saying a thing twice in two places is what made "back to back"
        # survive in the first place, so the skin is stated once early, where
        # it is read, and once here, where there is room for it without
        # crowding the pose. Both figures named again, because the failure that
        # keeps recurring is the woman coming out human beside a grey man.
        "detail": (
            "Both of their faces, necks and forearms are that same stone-grey, "
            "matte and dry as dressed limestone. They wear plain quarry "
            "working clothes: heavy undyed wool, thick leather over one "
            "shoulder, a canvas hauling strap. Rows of short chisel-cut marks "
            "are scarred along their bare forearms. The man is bearded and the "
            "older of the two; the woman wears her dark hair shorn close at "
            "the sides."
        ),
    },

    # --- The SRD nine ---------------------------------------------------
    #
    # These are the bodies only. Nothing in an entry below is taken from the
    # SRD's own descriptive text — it is a licensed document and this is a
    # prompt, so what is here is what somebody would see standing in Rivermark
    # market, written for the painter.
    #
    # The recurring difficulty is not exotic races. It is the ones whose
    # identifying feature is SMALL — a half-elf's ear, a halfling's proportions
    # — because the waist-up crop throws away height and the model defaults to
    # a human. Those entries say the feature twice and say it early.

    "dragonborn": {
        # First pass came back with the left figure scaled and the right one a
        # smooth grey humanoid with pointed ears — the Sarsen's asymmetry, and
        # a drift toward elf on top of it. So: "reptile" before "draconic",
        # every face said to be scaled, and the ears named explicitly, because
        # the ear is where the blend happened.
        "identity": (
            "Both figures are reptilian dragon-people: every inch of both their "
            "faces covered in fine bronze-green scales, blunt reptile muzzles "
            "and nostrils, no hair anywhere, no external ears, amber eyes with "
            "slit pupils."
        ),
        "detail": (
            "Neither of them has any bare skin — both faces, throats and hands "
            "are scaled bronze-green, scuffed and dulled at the knuckles. No "
            "hair, no beards, no pointed elf ears. They wear caravan-guard "
            "kit: boiled leather over wool, a heavy travelling cloak, an iron "
            "gorget. The male is heavier built and darker scaled; the female's "
            "scale is paler at the throat."
        ),
    },

    "dwarf": {
        # Height is the obvious dwarf fact and the one thing a waist-up crop
        # cannot show, so this leads with breadth and bone instead. A plate
        # that tried to say "short" would just come back as a distant figure.
        "identity": (
            "Both the man and the woman are enormously broad and thick-boned — "
            "vast shoulders, short thick necks, huge hands, heavy brows over "
            "deep-set eyes, weathered ruddy skin."
        ),
        "detail": (
            "Both are built like barrels, twice the breadth of a tall man "
            "across the shoulder. They wear the mail and leather of people who "
            "work underground and expect trouble: riveted rings, a thick wool "
            "underlayer, iron buckles. The man's beard is full and plaited to "
            "the chest; the woman's dark hair is braided back hard at the "
            "temples."
        ),
    },

    "elf": {
        # The race plate covers High, Wood and Dark alike, so this is what all
        # three share and nothing that belongs to one of them: no drow
        # colouring, no woodland dressing.
        "identity": (
            "Both the man and the woman are elves: long sharply pointed ears, "
            "narrow angular faces, high cheekbones, large pale eyes, smooth "
            "unlined ageless skin, no beard."
        ),
        "detail": (
            "Both have the long pointed ears and the fine unlined faces, hair "
            "worn long and straight. They wear well-made, quiet clothes — soft "
            "greys and greens, close-cut wool, a plain silver clasp. The man's "
            "hair is pale and tied back; the woman's is dark and loose."
        ),
    },

    "gnome": {
        # Same problem as the dwarf and worse: waist-up cannot show a gnome's
        # height, so the read has to come from the proportions of the head.
        #
        # "Oversized round heads on SMALL BODIES" was the mistake. Naming the
        # body pulled the whole plate out to full length and, with it, the
        # pose: the first pass stood them side by side facing the camera like
        # two carved dolls. The body is not mentioned now — the crop is
        # waist-up and the head is the only thing that can carry the read.
        # Cut twice. The second pass still stood them side by side facing the
        # camera, in a whimsical folk-art hand nothing else in this game uses —
        # a pile of caricature cues (laugh lines, wild hair, bright eyes) reads
        # as a genre, and the genre brought its own composition with it. What
        # is left is three features and no manner.
        "identity": (
            "Both figures have large round heads, broad blunt noses and big "
            "ears."
        ),
        "detail": (
            "Both have the same round face, broad nose and large ears. They "
            "wear a tinker's working clothes: layered wool, a leather apron "
            "with tool loops, spectacles pushed up on the forehead. The man's "
            "white hair stands out in all directions; the woman's is bundled "
            "under a kerchief."
        ),
    },

    "half_elf": {
        # The hardest of the nine. A half-elf's whole visual identity is one
        # feature the model will drop given any excuse, so the ears are the
        # first thing said, the last thing said, and said as an instruction
        # rather than a description.
        "identity": (
            "Both the man and the woman have clearly pointed elven ears on "
            "otherwise human faces — human colouring, human build, slightly "
            "sharpened cheekbones, no beard."
        ),
        "detail": (
            "Both of their ears are visibly pointed and swept back, plainly "
            "showing through the hair — this is the one thing that marks them. "
            "Otherwise they look human. They wear a trader's good travelling "
            "clothes: a decent wool coat, a linen collar, nothing gaudy. The "
            "man wears his hair short; the woman's is pinned up off the neck."
        ),
    },

    "half_orc": {
        # First pass drifted to elf: long pointed ears, barely any green, and
        # no tusks at all. The tusk is the whole read, so it goes in the first
        # six words, and the ears are named as ROUND — leaving them unsaid is
        # what let the model reach for a pointed one.
        # Third go. "Half-orc" and "tusks" both got results that were more
        # goblin than orc — long hooked noses, pointed ears, a witch's profile
        # — so this leads with "orcish" and with the underbite, which is the
        # shape of the jaw rather than a thing hung on it, and asks for a broad
        # heavy face to keep the nose out of caricature.
        "identity": (
            "Both figures are orcish: a heavy jutting underbite with two small "
            "tusks at the lower lip, broad heavy faces, wide flat noses, dull "
            "grey-green skin, small round ears."
        ),
        "detail": (
            "Both of their faces are grey-green, and both show the lower tusks "
            "when the mouth is closed. Round ears, not pointed. They wear a "
            "caravan guard's gear, hard used: studded leather, a wool cloak "
            "pinned at the shoulder, an old scar or two. The man is "
            "shaven-headed; the woman wears her black hair in a thick tail."
        ),
    },

    "halfling": {
        "identity": (
            "Both the man and the woman are halflings: small, round-cheeked "
            "faces, thick curly hair, large bright eyes, snub noses, no beards "
            "— adults with an open unlined look."
        ),
        "detail": (
            "Both are small and round-faced with thick curly hair, grown adults "
            "and not children. They wear river-trade clothes: a waistcoat over "
            "a linen shirt, a neckerchief, a canvas coat. The man's curls are "
            "sandy; the woman's are dark and tied with a cord."
        ),
    },

    "human": {
        # No trait to protect, so this one only has to avoid being generic.
        # What makes them Rivermark's is weather and work.
        "identity": (
            "Both the man and the woman are ordinary humans, weather-worn river "
            "town people — lined faces, wind-burnt cheeks, plain features, "
            "nothing exotic."
        ),
        "detail": (
            "They wear the workaday clothes of a trade town on a tired river: "
            "wool coat, linen collar, a leather strap across the chest, a "
            "little mended. The man is greying at the temple and stubbled; the "
            "woman's brown hair is tied back off her face."
        ),
    },

    "tiefling": {
        "identity": (
            "Both the man and the woman are tieflings: dark red skin, a pair of "
            "curling horns growing back from the forehead, solid black eyes "
            "with no visible whites, sharp features."
        ),
        "detail": (
            "Both have the red skin, the horns and the solid dark eyes. They "
            "wear plain, deliberately unremarkable town clothes — dark wool, a "
            "high collar, a hood pushed back. The man's horns are heavy and "
            "curl like a ram's; the woman's are slimmer and swept straight "
            "back."
        ),
    },
}


def prompt_for(key: str) -> str:
    """The five clauses, in the order the model weights them.

    Ground, identity, pose, period, detail, style. Reordering this is not a
    style choice — the first draft's order lost the race itself, and each of
    the three generators before this one has a comment about the same trap.
    """
    r = RACES[key]
    return f"{PLATE} {r['identity']} {POSE} {PERIOD} {r['detail']} {STYLE}"


def generate(prompt: str, seed: int, timeout: int = 300) -> str | None:
    """One image out of the local sampler. Returns a path on the server's disk."""
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


def place(src: str, dest: str) -> None:
    """Trim the flat bars the sampler sometimes bakes in, then fit OUT_W x OUT_H.

    Same trimmer as the busts and for the same reason, but it looks at the
    SIDES as well: a landscape frame given a portrait-shaped subject comes back
    pillarboxed about one time in four, and a plate with a black column down
    each edge reads as a broken image rather than a dark background.
    """
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
    left = 0
    while left < w // 3 and dark([sum(px[left, y]) / 3 for y in range(0, h, sy)]):
        left += 1
    right = w - 1
    while right > w * 2 // 3 and dark([sum(px[right, y]) / 3 for y in range(0, h, sy)]):
        right -= 1
    im = im.crop((left, top, right + 1, bot + 1))

    cw, ch = im.size
    scale = max(OUT_W / cw, OUT_H / ch)
    im = im.resize((max(1, round(cw * scale)), max(1, round(ch * scale))), Image.LANCZOS)
    rw, rh = im.size
    # Centred horizontally — the composition is symmetrical about the middle,
    # which is the one thing this plate can rely on — and anchored to the top,
    # because a pair of portraits loses chins more gracefully than foreheads.
    x0 = (rw - OUT_W) // 2
    im.crop((x0, 0, x0 + OUT_W, OUT_H)).save(dest)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--only", nargs="*", default=[])
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--seed", type=int, default=None,
                    help="first seed; defaults to the key's SHIPPED_SEED, "
                         "then to 7. --seeds n walks upward from it")
    ap.add_argument("--seeds", type=int, default=1,
                    help="draw n candidates as <key>_s<seed>.png and keep none")
    ap.add_argument("--prompt", action="store_true", help="print the prompt and stop")
    args = ap.parse_args()

    if args.list:
        for k in sorted(RACES):
            print(k)
        return 0

    keys = sorted(RACES) if args.all else [k for k in args.only if k in RACES]
    unknown = [k for k in args.only if k not in RACES]
    for k in unknown:
        print(f"  ?? no art direction written for '{k}'", file=sys.stderr)
    if not keys:
        ap.error("give --all, --only <keys>, or --list")

    if args.prompt:
        for k in keys:
            print(f"--- {k}\n{prompt_for(k)}\n")
        return 0

    os.makedirs(ART, exist_ok=True)
    failures = 0
    for key in keys:
        prompt = prompt_for(key)
        # `--all` with no --seed REPRODUCES what is on disk rather than
        # re-rolling it. Without this it would redraw every shipped plate from
        # seed 7 and overwrite the ones that took five revisions to get — which
        # is a destructive default for a flag whose name suggests a refresh.
        # An explicit --seed still overrides, because deliberately re-rolling is
        # a thing you sometimes want.
        first = args.seed if args.seed is not None else SHIPPED_SEED.get(key, 7)
        for i in range(args.seeds):
            seed = first + i
            # A trial run writes candidates side by side under their seed so
            # they can be looked at together; a single run writes the shipped
            # name. The seed that produced what ships is worth recording in the
            # commit message — same prompt, same seed, same PNG.
            name = f"{key}_s{seed}.png" if args.seeds > 1 else f"{key}.png"
            dest = os.path.join(ART, name)
            print(f"  {key}: seed {seed} ... ", end="", flush=True)
            try:
                src = generate(prompt, seed)
            except (urllib.error.URLError, TimeoutError) as e:
                print(f"FAILED ({e})")
                failures += 1
                continue
            if not src or not os.path.exists(src):
                print("FAILED (no image came back)")
                failures += 1
                continue
            place(src, dest)
            print(os.path.relpath(dest, ROOT))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
