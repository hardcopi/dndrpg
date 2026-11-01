#!/usr/bin/env python3
"""
Self-test for tools/bake_looks.py. Needs the creator snapshot and the venv that
has rembg in it:

    PY=/home/richard/rivermark/creator-src/.venv/bin/python
    $PY tools/test_bake_looks.py [--src SNAPSHOT]

Three things are pinned, because each is a promise somebody else relies on:

  * the outfit composite is the creator's arithmetic to the bit — the library
    is supposed to be the creator's pictures, not pictures like them;
  * the keys are the creator's keys — `imagine_creator.resolve()` cannot be
    imported from the snapshot (it loads a v1 manifest the snapshot does not
    carry), so the expected strings are written out here by hand from
    resolve()'s own f-strings, and the tool's copy of the rules is checked against
    them rather than against itself;
  * a board figure is shaped like the hand-drawn ones — 192x320 RGBA, feet on
    row 316, nothing above row 4, clear corners.
"""

from __future__ import annotations

import argparse
import os
import sys
import tempfile

import numpy as np
from PIL import Image

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bake_looks as B  # noqa: E402

DEFAULT_SRC = "/home/richard/rivermark/creator-src/pose2-v7-20261101"
FAILS: list[str] = []


def check(cond: bool, what: str) -> None:
    print(("  ok    " if cond else "  FAIL  ") + what)
    if not cond:
        FAILS.append(what)


def find(entries, ch, stem, variant):
    return next(e for e in entries if e["char"] == ch and e["stem"] == stem and e["variant"] == variant)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=DEFAULT_SRC)
    src = ap.parse_args().src
    _, entries = B.load_catalogue(src)

    print("catalogue")
    check(len(entries) == 12892, f"12,892 entries (got {len(entries)})")
    check(len({e["key"] for e in entries}) == len(entries), "keys are unique")
    check(len({(e["char"], e["stem"], e["outfit"]) for e in entries}) == 4688, "4,688 matte groups")

    print("keys match imagine_creator.resolve()")
    expected = {
        ("feminine_Dwarf", "bald", "tunic"): "p2:look:feminine_Dwarf/bald",
        ("masculine_Human", "shaggy__black", "plate__blackened"): "p2:look:masculine_Human/shaggy__black|plate__blackened",
        ("feminine_Elf", "long_loose__dark_brown", "leather__grey-brown"):
            "p2:look:feminine_Elf/long_loose__dark_brown|leather__grey-brown",
        ("masculine_Dragonborn", "base", "tunic"): "p2:base:masculine_Dragonborn",
        ("feminine_Dragonborn", "base", "robe__crimson"): "p2:base:feminine_Dragonborn|robe__crimson",
        ("masculine_Tiefling", "hood", "robe__green"): "p2:look:masculine_Tiefling/hood|robe__green",
    }
    for (ch, stem, v), key in expected.items():
        got = find(entries, ch, stem, v)["key"]
        check(got == key, f"{key}" + ("" if got == key else f"  (got {got})"))

    print("composition equals the creator's formula")
    e = find(entries, "feminine_Elf", "long_loose__black", "plate__blackened")
    look = np.asarray(Image.open(os.path.join(src, "imagine_looks_pose2", "feminine_Elf", "long_loose__black.png"))
                      .convert("RGB"), dtype=np.uint16)
    d = os.path.join(src, "outfits_pose2", "placed", "feminine_Elf")
    V = np.asarray(Image.open(os.path.join(d, "plate__blackened.png")).convert("RGB"), dtype=np.uint16)
    A = np.asarray(Image.open(os.path.join(d, "plate__long_loose__alpha.png")).convert("L"), dtype=np.uint16)[..., None]
    want = ((look * (255 - A) + V * A + 127) // 255).astype(np.uint8)
    got = B.compose(e["src_full"], e["char"], e["style"], e["outfit"], e["outfit_color"], "full", src)
    check(got.shape == (1024, 384, 3) and np.array_equal(got, want), "full: bit-identical to outfit_png()")
    check(not np.array_equal(got, look.astype(np.uint8)), "full: the layer actually changed something")
    t = find(entries, "feminine_Elf", "long_loose__black", "tunic")
    got_t = B.compose(t["src_full"], t["char"], t["style"], "tunic", None, "full", src)
    check(np.array_equal(got_t, look.astype(np.uint8)), "tunic: the look itself")

    print("bake one entry end to end")
    with tempfile.TemporaryDirectory() as out:
        groups = B.groups_of([e], entries)
        st = B.bake_group(dict(groups[0], src=src, out=out, force=False, face=(200, 192, 20)))
        check(not st["errors"], "no errors" + ("" if not st["errors"] else f": {st['errors']}"))
        paths = B.out_paths(out, e)
        check(paths["fig"].endswith("feminine_Elf/long_loose__black/plate__blackened.fig.webp"), "path layout")
        sizes = {"full": ((384, 1024), "RGB"), "bust": ((384, 512), "RGB"), "fig": ((192, 320), "RGBA"),
                 "face": ((144, 144), "RGB")}
        for k, (size, mode) in sizes.items():
            im = Image.open(paths[k])
            check(im.format == "WEBP" and im.size == size and im.mode == mode, f"{k}: {size[0]}x{size[1]} {mode} webp")
        fig = np.asarray(Image.open(paths["fig"]))
        box = Image.fromarray(fig[..., 3]).getbbox()
        check(box is not None and box[3] == B.FIG_BOTTOM, f"fig: feet on row {B.FIG_BOTTOM} (bbox {box})")
        check(box is not None and box[1] >= B.FIG_TOP, f"fig: top at or below row {B.FIG_TOP}")
        check(box is not None and (box[1] == B.FIG_TOP or box[2] - box[0] >= B.FIG_MAX_W - 1),
              "fig: fills the height (or the width)")
        corners = [fig[0, 0, 3], fig[0, -1, 3], fig[-1, 0, 3], fig[-1, -1, 3]]
        check(all(c == 0 for c in corners), "fig: alpha corners are 0")
        st2 = B.bake_group(dict(groups[0], src=src, out=out, force=False, face=(200, 192, 20)))
        check(st2["skipped"] == 1 and st2["entries"] == 0, "idempotent: a second run skips")

    print("backdrop")
    dw = find(entries, "masculine_Dwarf", "bald", "tunic")
    full = B.compose(dw["src_full"], dw["char"], dw["style"], "tunic", None, "full", src)
    check(B.backdrop(full).max() > B.BACKDROP_LIMIT, "masculine_Dwarf is measured as not-black")
    el = B.compose(t["src_full"], t["char"], t["style"], "tunic", None, "full", src)
    check(B.backdrop(el).max() <= B.BACKDROP_LIMIT, "feminine_Elf is measured as black")

    print(f"\n{'FAILED: ' + str(len(FAILS)) if FAILS else 'all passed'}")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
