#!/usr/bin/env python3
"""
Cover the cut, the casting, and the one contract the engine depends on.

    python3 tools/test_voiceover.py

Nothing here talks to the speech model or to the database. The interesting part
of `gen_voiceover.py` is a grammar — where a line of prose stops being narration
and starts being somebody talking — and a grammar is testable in microseconds
against strings, which is the same reason `BattleGrid` and `DungeonGen` are pure.

Every attribution case below is one that has actually been got wrong, in this
corpus, by an earlier draft of these rules. They are not hypotheticals: the
`table` case cast every one of Finch's lines as a companion, and the two-speaker
interjection gave Aldric's line to the man he was arguing with.
"""

from __future__ import annotations

import hashlib
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import gen_voiceover as vo  # noqa: E402

FAIL = 0


def check(label: str, got, want) -> None:
    global FAIL
    if got == want:
        print(f"  ok   {label}")
    else:
        FAIL += 1
        print(f"  FAIL {label}\n         got  {got!r}\n         want {want!r}")


def speakers(text: str, default: str, names: dict) -> list:
    return [(c["speaker"], c["text"]) for c in vo.segment(text, default, names)]


NAMES = {"Finch": "garrow_finch", "Garrow": "garrow_finch",
         "Aldric": "aldric", "Sera": "sera", "Vance": "sera",
         "Kessa": "kessa", "Dunmar": "kessa",
         "Jimmy": "jimmy_able", "Able": "jimmy_able"}


def test_cut() -> None:
    print("the cut")

    check(
        "prose is the narrator's, quotes are the speaker's",
        speakers('A neat man in a green coat. "Garrow Finch," he says.',
                 "garrow_finch", NAMES),
        [("narrator", "A neat man in a green coat."),
         ("garrow_finch", "Garrow Finch,"),
         ("narrator", "he says.")],
    )

    # `Able` is a companion's surname and also the middle of "table". A
    # substring search finds him there and recasts the entire conversation.
    check(
        "a name inside an ordinary word is not a name",
        speakers('Finch is at the same table with a new cup. "No hard feelings," he says.',
                 "garrow_finch", NAMES),
        [("narrator", "Finch is at the same table with a new cup."),
         ("garrow_finch", "No hard feelings,"),
         ("narrator", "he says.")],
    )

    # The lowercase adjective is not the surname either.
    check(
        "the word 'able' does not cast Jimmy",
        vo.attribute("He was able to pay.", " he says.", NAMES),
        None,
    )

    # Two speakers, one authored string, taken through the same two steps the
    # generator takes an interjection through: the speaker's own name comes off
    # the front (the portrait beside the line already says who it is), and what
    # is left is cut. The prose in front of the SECOND quote is what the four
    # words after the FIRST would grab if they were read as a speech tag.
    aside = 'Aldric: "She signed with a mark." Finch, mildly: "Most of them do."'
    check(
        "an aside with two speakers splits between them",
        speakers(vo.strip_prefix(aside, "Brother Aldric", "aldric"), "aldric", NAMES),
        [("aldric", "She signed with a mark."),
         ("narrator", "Finch, mildly:"),
         ("garrow_finch", "Most of them do.")],
    )

    check(
        "a prefix naming somebody else stays",
        vo.strip_prefix('Reed: "Hold the gate."', "Brother Aldric", "aldric"),
        'Reed: "Hold the gate."',
    )

    check(
        "a name and a colon with stage direction between",
        vo.attribute("Kessa, to you, not to him:", ' "Four years."', NAMES),
        "kessa",
    )

    # The tag AFTER the quote beats the name in the prose before it, which is
    # the only reason this one comes out right.
    check(
        "the speech tag outranks the last name mentioned",
        vo.attribute("Kessa is watching.", ' Finch says, and drinks.', NAMES),
        "garrow_finch",
    )

    check(
        "an inverted tag reads the same way",
        vo.attribute("", ' says Finch, and drinks.', NAMES),
        "garrow_finch",
    )

    # `"Ninety," Finch says to Kessa` — the subject speaks, the object does not.
    check(
        "the subject of the tag speaks, not the object",
        vo.attribute("", ' Finch says to Kessa, flatly.', NAMES),
        "garrow_finch",
    )

    check(
        "the subject of the preceding sentence, when there is no tag",
        vo.attribute("Sera reads it upside down in about six seconds.", ' "Sloppy."', NAMES),
        "sera",
    )

    check(
        "a pronoun keeps whoever was already speaking",
        speakers('"One," he says. "Two."', "garrow_finch", NAMES),
        [("garrow_finch", "One,"), ("narrator", "he says."), ("garrow_finch", "Two.")],
    )

    check(
        "an unquoted line is all narration",
        speakers("Kessa takes the paper and folds it very small.", "kessa", NAMES),
        [("narrator", "Kessa takes the paper and folds it very small.")],
    )

    # `," ` left between two halves of one speech is punctuation, not a line.
    check(
        "a fragment with no words in it is not a clip",
        speakers('"Sixty," "and you have cost me a dinner."', "garrow_finch", NAMES),
        [("garrow_finch", "Sixty,"), ("garrow_finch", "and you have cost me a dinner.")],
    )

    check(
        "an em-dash becomes a pause, without a limping space",
        vo.clean("give it to her — most people do"),
        "give it to her, most people do",
    )


def test_casting() -> None:
    print("\ncasting")

    check("the narrator is Emma and nobody else is",
          vo.NARRATOR not in vo.VOICE_POOL["male"] + vo.VOICE_POOL["female"], True)

    check("every pooled voice is English",
          all(v[0] in "ab" for v in vo.VOICE_POOL["male"] + vo.VOICE_POOL["female"]), True)

    # Ilse's only aside is "He has told you exactly what he will do" — four
    # masculine pronouns, every one of them about Finch. Counting a character's
    # speech towards their own gender cast her as a man.
    aside = 'Ilse: "He has told you exactly what he will do. He is not bluffing."'
    cut = [({"owner": "ilse"}, vo.segment(aside, "ilse", NAMES))]
    check("what a character says about others does not gender them",
          vo.infer_gender(vo.gender_prose("ilse", cut)), "female")

    # ...while the narration beside their line does, which is what carries Finch:
    # his description has no pronoun in it at all.
    node = 'Finch is settling his bill. "A clean transaction," he says.'
    cut = [({"owner": "garrow_finch"}, vo.segment(node, "garrow_finch", NAMES))]
    check("the narration beside a line does gender them",
          vo.infer_gender(vo.gender_prose("garrow_finch", cut)), "male")

    check("a voice is stable across runs", assign_twice("garrow_finch"), True)


def assign_twice(key: str) -> bool:
    a, b = empty_cast(), empty_cast()
    return vo.assign_voice(a, key, "he him his") == vo.assign_voice(b, key, "he him his")


def empty_cast() -> dict:
    return {"narrator": vo.narrator_entry(), "voices": {}}


def test_registry() -> None:
    """The cast list: its migration, its spread, and what it refuses to do.

    The migration is the dangerous one. Finch is `bm_fable` in a hundred and
    eleven generated files, and a reformat that recast him would orphan every
    one of them — silently, because the lookup is by hash and a miss is just
    silence. So the test is not "does it produce v2", it is "does every voice
    survive".
    """
    print("\nthe registry")

    v1 = {"narrator": "bf_emma",
          "voices": {"garrow_finch": "bm_fable", "kessa": "af_sarah",
                     "sera": "af_alloy", "aldric": "am_michael",
                     "ilse": "bf_isabella"}}
    got = vo.migrate_cast(v1)

    check("migrating the first format changes nobody's voice",
          {k: r["voice"] for k, r in got["voices"].items()}, v1["voices"])
    check("migrating fills in the accent",
          got["voices"]["garrow_finch"]["accent"], "en-GB")
    check("migrating fills in the grade",
          got["voices"]["ilse"]["grade"], "C")
    check("the narrator survives the migration",
          got["narrator"]["voice"], vo.NARRATOR)

    # Already-cast rows are read, never rewritten.
    frozen = vo.migrate_cast(v1)
    vo.assign_voice(frozen, "garrow_finch", "she her hers", "Garrow Finch")
    check("an existing pick is not overwritten, even against the pronouns",
          frozen["voices"]["garrow_finch"]["voice"], "bm_fable")
    check("...but it does gain the display name",
          frozen["voices"]["garrow_finch"].get("name"), "Garrow Finch")

    # Spread. Twelve men cast into an eleven-strong male pool should use every
    # voice once before doubling up; hashing alone put five on one voice.
    cast = empty_cast()
    pool = len(vo.VOICE_POOL["male"])
    for i in range(pool):
        vo.assign_voice(cast, f"man_{i}", "he him his")
    used = {r["voice"] for r in cast["voices"].values()}
    check(f"{pool} men fill the male pool without repeating", len(used), pool)

    check("nobody can be cast as the narrator",
          any(r["voice"] == vo.NARRATOR for r in cast["voices"].values()), False)


def test_key_contract() -> None:
    """The one thing that spans the two languages.

    Python files a line under sha1 of the authored text and PHP looks it up by
    sha1 of the authored text. Nothing normalises on either side, so the test is
    that both really do mean the same thing by "sha1 of this string" — including
    for a line with the em-dashes and curly punctuation the corpus is full of.
    """
    print("\nthe key")

    sample = 'He turns the cup. "So it is the ninety — or the yard," Finch says.'
    check("python's key", vo.line_key(sample), hashlib.sha1(sample.encode()).hexdigest())

    php = subprocess.run(
        ["docker", "compose", "exec", "-T", "php", "php", "-r",
         'echo sha1(file_get_contents("php://stdin"));'],
        input=sample.encode("utf-8"), capture_output=True,
        cwd=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    )
    if php.returncode != 0:
        print("  skip php agrees on the key (no stack running)")
        return
    check("php agrees on the key", php.stdout.decode().strip(), vo.line_key(sample))


def main() -> int:
    test_cut()
    test_casting()
    test_registry()
    test_key_contract()
    print(f"\n{'FAILED' if FAIL else 'passed'} — {FAIL} failure(s)")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
