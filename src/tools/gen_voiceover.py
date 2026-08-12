#!/usr/bin/env python3
"""
Read the authored dialogue aloud with the local speech model (Kokoro).

A dialogue node is not one utterance. It is a paragraph of prose with speech
quoted inside it:

    A neat man in a green coat is sitting where he can see the yard door.
    "Garrow Finch," he says. "Debt factor."

Handing that whole string to one voice gets you a narrator reading a script,
including the word "says", in the character's voice. So a node is CUT at the
quotation marks: the prose between quotes is the narrator, and each quoted span
is whoever is speaking it. That split is the whole idea here, and it is done
once, in this file — see `segment()`. The engine does not re-derive it; it looks
the result up by hash. A second implementation of the quote grammar in PHP would
agree with this one exactly until one of the two was fixed.

Who speaks a quoted span is read off the prose immediately before it, because
that is where English puts it:

    Aldric: "She signed with a mark."  Finch, mildly: "Most of them do."

Two speakers, one authored string, and the attribution is sitting right there in
front of each quote. Where no name precedes a quote the last speaker continues,
and the first quote in a block defaults to whoever owns the block — the NPC for
their own line, the companion for their aside. That default is right far more
often than any cleverer rule, because an author writing an NPC's node opens with
the NPC talking.

VOICES are assigned once and then persisted in `voices.json`, which is the point
of the file: a character who sounded like one person on Tuesday and another on
Wednesday is a bug you cannot hear until you have already shipped it. The
initial pick is drawn from a hash of the character's key rather than from the
clock, so a lost `voices.json` regenerates the same cast, and the file can be
hand-edited afterwards when a pick is simply wrong for somebody.

Gender is inferred from the pronouns in the character's own prose. There is no
gender column anywhere in this game and there should not be one for this — what
matters is only which voice pool to draw from, the text already says it, and a
guess that reads "he" fifty times and still picks a soprano is not defensible.

The narrator is fixed: `bf_emma`, English (GB). Every NPC voice is drawn from a
pool that excludes her, so the narrator is never mistaken for a character.

    python3 tools/gen_voiceover.py --only garrow_finch --dry-run   # show the cut
    python3 tools/gen_voiceover.py --only garrow_finch
    python3 tools/gen_voiceover.py --all
    python3 tools/gen_voiceover.py --cast                          # who sounds like what

Output, under `assets/audio/vo/`:

    voices.json               the cast: speaker key -> voice id. Committed.
    <npc_key>/lines.json      sha1(authored text) -> the clips it cuts into
    <npc_key>/<sha1>.mp3      one clip

`lines.json` is per NPC rather than one manifest for the whole game because
`Voiceover.php` loads it on the request that draws the conversation, and a
single file carrying every line in the module would be parsed in full to answer
one node.

The key is a sha1 of the EXACT authored text. Edit a line and its audio stops
being found — silently, and correctly: the old recording is of the old words.
Regenerate. That also means this tool reads `content/dialog/*.json` while the
engine serves from the database, so **export before generating** (`/content.php`
-> Export to files) or you will voice the version on disk rather than the
version being played.
"""

from __future__ import annotations

import argparse
import glob
import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content")
OUT = os.path.join(ROOT, "assets", "audio", "vo")
BASE = os.environ.get("KOKORO_URL", "http://127.0.0.1:3000")
API_KEY = os.environ.get("KOKORO_API_KEY", "")

MODEL = "model_q8f16"     # 8-bit weights, fp16 activations: the quality knee
NARRATOR = "bf_emma"      # English (GB), and the one voice no character may use
SPEED = 1.0

# Drawn from the model's own voice list, keeping only grades C and up. The D and
# F voices are not merely worse, they are audibly synthetic in a way that breaks
# the scene — am_adam is graded F+ and sounds it. Kokoro also ships Japanese,
# Mandarin, Hindi, Italian, Spanish and Portuguese voices; they are trained for
# those languages and read English with an accent the writing did not ask for,
# so the pools are English only.
VOICE_POOL = {
    "male": ["am_fenrir", "am_michael", "am_puck", "bm_george", "bm_fable"],
    "female": ["af_heart", "af_bella", "af_nicole", "af_aoede",
               "af_kore", "af_sarah", "af_nova", "af_alloy", "bf_isabella"],
}

# Speech is cut at double quotes. The corpus uses straight quotes throughout
# (checked: no curly quote appears in any dialogue file), and apostrophes are
# left alone — "don't" is not a quotation.
QUOTE_RE = re.compile(r'"([^"]*)"')

# `Aldric:` / `Finch, mildly:` / `Kessa, to you, not to him:` — a name, then any
# amount of stage direction, then a colon. Anchored to the END of the run of
# prose that precedes a quote, because that is the only position where it
# attributes the quote that follows.
ATTRIB_RE = re.compile(r"([A-Z][A-Za-z'\-]+)[^.!?\"]{0,40}:\s*$")

# How far past a closing quote to look for `," Finch says`. Four words reaches
# the verb and its subject without running into the next sentence.
TAG_WORDS = 4

# A speech tag is a name and a verb of speaking, in either order — `Finch says`,
# `says Finch`. The verb is REQUIRED, and that is the whole point of this list.
# Without it the four words after a quote also match the attribution belonging to
# the NEXT quote: in `Aldric: "She signed with a mark." Finch, mildly: "Most of
# them do."` the words after Aldric's line are `Finch, mildly:`, and reading them
# as a tag gives Aldric's one line to Finch.
SAID = (r"says?|said|observes?|observed|agrees?|agreed|asks?|asked|replies|replied"
        r"|tells?|told|adds?|added|answers?|answered|murmurs?|remarks?|notes?"
        r"|continues?|offers?|puts?|calls?|repeats?|admits?|concedes?")


# --------------------------------------------------------------------------
# The cast
# --------------------------------------------------------------------------

def load_cast() -> dict:
    path = os.path.join(OUT, "voices.json")
    if os.path.exists(path):
        with open(path, encoding="utf-8") as fh:
            return json.load(fh)
    return {"narrator": NARRATOR, "voices": {}}


def save_cast(cast: dict) -> None:
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "voices.json"), "w", encoding="utf-8") as fh:
        json.dump(cast, fh, indent=2, ensure_ascii=False)
        fh.write("\n")


def infer_gender(text: str) -> str:
    """Which voice pool to draw from, from the pronouns in a character's prose.

    Counted as whole words so "she" inside "shed" does not vote. A tie or a
    silence goes to female, which is arbitrary and has to be: the alternative is
    failing, and a character with no pronoun in their prose still needs a voice.
    """
    low = text.lower()
    he = len(re.findall(r"\b(?:he|him|his)\b", low))
    she = len(re.findall(r"\b(?:she|her|hers)\b", low))
    return "male" if he > she else "female"


def assign_voice(cast: dict, key: str, prose: str) -> str:
    """This character's voice, picking one the first time and never again."""
    if key in cast["voices"]:
        return cast["voices"][key]
    pool = VOICE_POOL[infer_gender(prose)]
    # Seeded from the key, not from the clock: a deleted voices.json rebuilds
    # the same cast rather than recasting the town.
    digest = hashlib.sha1(key.encode("utf-8")).digest()
    voice = pool[int.from_bytes(digest[:4], "big") % len(pool)]
    cast["voices"][key] = voice
    return voice


# --------------------------------------------------------------------------
# The cut
# --------------------------------------------------------------------------

def speaker_index(npc_key: str) -> dict[str, str]:
    """Names the prose might use for somebody, mapped to their key.

    Both halves of a name point at the same person — the corpus writes "Finch"
    far more often than "Garrow Finch" — and a surname shared by two characters
    is dropped rather than guessed at, since a wrong attribution is worse than
    falling back to whoever is talking anyway.
    """
    people: list[tuple[str, str]] = []
    for path in glob.glob(os.path.join(CONTENT, "companions", "*.json")):
        with open(path, encoding="utf-8") as fh:
            d = json.load(fh)
        people.append((str(d.get("companion_key") or ""), str(d.get("name") or "")))
    npc = read_npc(npc_key)
    people.append((npc_key, str(npc.get("name") or "")))

    seen: dict[str, set[str]] = {}
    for key, name in people:
        for word in re.split(r"\s+", name):
            word = word.strip()
            # "Brother" in "Brother Aldric" is a title, not a name to match on.
            if len(word) < 3 or word.lower() in {"brother", "sister", "the", "of"}:
                continue
            seen.setdefault(word, set()).add(key)
    return {word: next(iter(keys)) for word, keys in seen.items() if len(keys) == 1}


def find_name(text: str, names: dict[str, str]) -> str | None:
    """The first character named in a run of prose, if any.

    Matched on the capitalised form and on whole words, both of which are load
    bearing. "Jimmy Able" is a companion, and a substring search for his surname
    finds one in the middle of "the same **table**" — which cast every one of
    Finch's lines as Jimmy until this was tightened. A word-boundary search alone
    still matches the ordinary adjective in "he was able to pay"; requiring the
    capital is what separates the name from the word.
    """
    hits = []
    for word, key in names.items():
        m = re.search(rf"\b{re.escape(word)}\b", text)
        if m:
            hits.append((m.start(), key))
    return min(hits)[1] if hits else None


def segment(text: str, default: str, names: dict[str, str]) -> list[dict]:
    """Cut one authored string into ordered (speaker, text) clips.

    Unquoted prose is the narrator's. A quoted span belongs to whoever the prose
    around it names — see `attribute()` — or to the last person to speak, or to
    `default` if nobody has yet.
    """
    clips: list[dict] = []
    current = default
    pos = 0

    def push(speaker: str, raw: str) -> None:
        said = clean(raw)
        if said:
            clips.append({"speaker": speaker, "text": said})

    for m in QUOTE_RE.finditer(text):
        before = text[pos:m.start()]
        push("narrator", before)
        current = attribute(before, text[m.end():], names) or current
        push(current, m.group(1))
        pos = m.end()

    push("narrator", text[pos:])
    return clips


def attribute(before: str, after: str, names: dict[str, str]) -> str | None:
    """Who the prose around a quote says is speaking it, if anyone.

    Three shapes, tried in descending order of how certain each one is. They are
    ordered rather than combined because they disagree, and when they do the
    earlier one is right:

      1. `Aldric: "..."` — a name and a colon immediately before the quote. This
         is an attribution and can be nothing else.
      2. `"...," Finch says` — the speech tag after the quote. A name AND a verb
         of speaking, within the first few words, and only the first name among
         them: in `"Ninety," Finch says to Kessa` the speaker is the subject, not
         the object.
      3. The first name in the last sentence of the prose before the quote —
         `Sera reads it upside down. "Second endorsement's out of date order,"`.
         Weakest, and deliberately last: in `Kessa is watching. "Ninety," Finch
         says.` this rule answers Kessa and rule 2 has already answered Finch.
    """
    stripped = before.strip()
    m = ATTRIB_RE.search(stripped)
    if m and m.group(1) in names:
        return names[m.group(1)]

    tag = " ".join(after.split()[:TAG_WORDS])
    if re.search(rf"\b(?:{SAID})\b", tag, re.I):
        who = find_name(tag, names)
        if who:
            return who

    sentence = re.split(r"(?<=[.!?])\s+", stripped)[-1] if stripped else ""
    return find_name(sentence, names)


def clean(text: str) -> str:
    """Tidy a clip for the reader.

    The em-dash is the one that matters: the model has no phoneme for it and
    drops it, running two clauses together at speed. A comma is not what the
    author wrote but it is what they meant the voice to do.
    """
    text = text.replace("—", ", ").replace("–", ", ")
    text = re.sub(r"\s+", " ", text).strip()
    # The dash often had a space in front of it, and " , " reads as a stumble.
    text = re.sub(r"\s+([,.;:!?])", r"\1", text)
    # A run of prose that is only punctuation once its quotes are taken out —
    # `," ` between two halves of a speech — is not a line to read.
    return "" if not re.search(r"[A-Za-z0-9]", text) else text


def strip_prefix(text: str, name: str, key: str) -> str:
    """Drop a leading `Aldric:` from an aside.

    The theatre already does this on screen, because the portrait beside the
    line has the speaker's name under it (`stripSpeakerPrefix` in ui-dialog.js).
    Read aloud it is worse than redundant: the narrator announces the speaker
    and then the speaker says the same name.
    """
    m = re.match(r"^\s*([A-Za-z' -]{2,20}):\s*", text)
    if not m:
        return text
    said = m.group(1).strip().lower()
    mine = set(str(name or "").lower().split()) | {str(key or "").lower()}
    return text[m.end():] if said in mine else text


# --------------------------------------------------------------------------
# Content
# --------------------------------------------------------------------------

def read_npc(npc_key: str) -> dict:
    path = os.path.join(CONTENT, "npcs", f"{npc_key}.json")
    if not os.path.exists(path):
        return {}
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def companion_name(key: str) -> str:
    path = os.path.join(CONTENT, "companions", f"{key}.json")
    if not os.path.exists(path):
        return key
    with open(path, encoding="utf-8") as fh:
        return str(json.load(fh).get("name") or key)


def blocks(npc_key: str) -> list[dict]:
    """Every authored string this NPC's tree can put on screen.

    One entry per string, carrying the text the engine will serve — which is the
    key the audio is filed under — and who owns it.
    """
    path = os.path.join(CONTENT, "dialog", f"{npc_key}.json")
    if not os.path.exists(path):
        return []
    with open(path, encoding="utf-8") as fh:
        tree = json.load(fh)

    out = []
    for node_key, variants in (tree.get("nodes") or {}).items():
        for variant in variants if isinstance(variants, list) else [variants]:
            text = str(variant.get("text") or "")
            if text:
                out.append({"node": node_key, "owner": npc_key, "text": text,
                            "speak": text})
            for line in variant.get("interjections") or []:
                who = str(line.get("companion") or "")
                raw = str(line.get("text") or "")
                if not raw:
                    continue
                out.append({
                    "node": node_key,
                    "owner": who,
                    "text": raw,
                    # Filed under the authored text, read from the stripped one.
                    "speak": strip_prefix(raw, companion_name(who), who),
                })
    return out


def describe(key: str) -> str:
    """What is written ABOUT somebody, as opposed to what they say."""
    npc = read_npc(key)
    bits = [str(npc.get("description") or ""), str(npc.get("role") or "")]
    comp = os.path.join(CONTENT, "companions", f"{key}.json")
    if os.path.exists(comp):
        with open(comp, encoding="utf-8") as fh:
            d = json.load(fh)
        bits += [str(d.get("description") or ""), str(d.get("personality") or "")]
    return " ".join(bits)


def gender_prose(key: str, cut: list[tuple[dict, list[dict]]]) -> str:
    """The prose the pronoun count is allowed to read: descriptions of somebody,
    plus the NARRATION around their own lines — never the lines themselves.

    A character's speech is full of pronouns belonging to other people. Ilse's
    one aside here is "He has told you exactly what he will do", every word of it
    about Finch, and counting it cast her as a man. The narration beside a line
    is the opposite: "Kessa is looking at her own mark", "Finch is settling his
    bill" — third person, about the speaker, which is exactly the signal wanted.

    Descriptions alone are not enough to fall back on. Half of them contain no
    pronoun at all: Finch's is "Buys other people's debts at a discount and
    collects them at full price", which would have made him a soprano.
    """
    bits = [describe(key)]
    for block, clips in cut:
        if block["owner"] == key:
            bits += [c["text"] for c in clips if c["speaker"] == "narrator"]
    return " ".join(bits)


# --------------------------------------------------------------------------
# Speech
# --------------------------------------------------------------------------

def synth(text: str, voice: str) -> bytes:
    body = json.dumps({
        "model": MODEL,
        "voice": voice,
        "input": text,
        "response_format": "mp3",
        "speed": SPEED,
    }).encode("utf-8")
    req = urllib.request.Request(
        f"{BASE}/api/v1/audio/speech", data=body,
        headers={"Content-Type": "application/json",
                 **({"Authorization": f"Bearer {API_KEY}"} if API_KEY else {})},
    )
    with urllib.request.urlopen(req, timeout=300) as resp:
        return resp.read()


def clip_name(voice: str, text: str) -> str:
    """A clip is named for its words and its voice, so identical lines in the
    same voice are generated once and different voices never collide."""
    return hashlib.sha1(f"{voice}\x00{text}".encode("utf-8")).hexdigest()[:16] + ".mp3"


def line_key(text: str) -> str:
    """What the engine will hash to find this line: the authored text, exactly."""
    return hashlib.sha1(text.encode("utf-8")).hexdigest()


# --------------------------------------------------------------------------

def run(npc_key: str, cast: dict, dry: bool, force: bool) -> tuple[int, int]:
    names = speaker_index(npc_key)
    items = blocks(npc_key)
    if not items:
        print(f"  {npc_key}: no dialogue tree", file=sys.stderr)
        return (0, 0)

    # Cut everything before casting anybody: who a character sounds like is
    # decided from the narration around their lines, which does not exist as a
    # separate thing until the cut has been made.
    cut = [(block, segment(block["speak"], block["owner"], names)) for block in items]

    npc_dir = os.path.join(OUT, npc_key)
    lines: dict[str, list[dict]] = {}
    made = skipped = 0

    for block, clips in cut:
        entry = []
        for clip in clips:
            who = clip["speaker"]
            voice = (cast["narrator"] if who == "narrator"
                     else assign_voice(cast, who, gender_prose(who, cut)))
            name = clip_name(voice, clip["text"])
            entry.append({"speaker": who, "voice": voice, "src": name})

            if dry:
                print(f"    [{who:<14} {voice:<12}] {clip['text'][:78]}")
                continue

            path = os.path.join(npc_dir, name)
            if os.path.exists(path) and not force:
                skipped += 1
                continue
            os.makedirs(npc_dir, exist_ok=True)
            try:
                audio = synth(clip["text"], voice)
            except urllib.error.URLError as exc:
                print(f"  ! {npc_key} {who}: {exc}", file=sys.stderr)
                return (made, skipped)
            with open(path, "wb") as fh:
                fh.write(audio)
            made += 1
            print(f"  + {who:<14} {voice:<12} {len(audio):>7}b  {clip['text'][:52]}")

        lines[line_key(block["text"])] = entry
        if dry:
            print(f"  {block['node']} ({block['owner']})")

    if not dry:
        os.makedirs(npc_dir, exist_ok=True)
        with open(os.path.join(npc_dir, "lines.json"), "w", encoding="utf-8") as fh:
            json.dump(lines, fh, indent=1, ensure_ascii=False)
            fh.write("\n")
    return (made, skipped)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--only", nargs="+", metavar="NPC_KEY")
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--dry-run", action="store_true",
                    help="print the cut and the casting, generate nothing")
    ap.add_argument("--force", action="store_true", help="re-generate existing clips")
    ap.add_argument("--cast", action="store_true", help="print the cast and exit")
    args = ap.parse_args()

    cast = load_cast()

    if args.cast:
        print(f"narrator       {cast['narrator']}")
        for key, voice in sorted(cast["voices"].items()):
            print(f"{key:<14} {voice}")
        return 0

    if args.all:
        keys = sorted(os.path.basename(p)[:-5]
                      for p in glob.glob(os.path.join(CONTENT, "dialog", "*.json")))
    elif args.only:
        keys = args.only
    else:
        ap.print_help()
        return 2

    total_made = total_skipped = 0
    for key in keys:
        print(f"{key}:")
        made, skipped = run(key, cast, args.dry_run, args.force)
        total_made += made
        total_skipped += skipped

    if not args.dry_run:
        save_cast(cast)
        print(f"\n{total_made} clip(s) generated, {total_skipped} already on disk")
    return 0


if __name__ == "__main__":
    sys.exit(main())
