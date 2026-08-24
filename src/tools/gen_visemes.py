#!/usr/bin/env python3
"""
Work out how a mouth moves for each recorded clip, once, and write it down.

`gen_voiceover.py` cuts a dialogue node into clips and records them. This reads
those clips back and derives, per clip, a mouth track: how far open the jaw is
and whether the lips are spread or rounded, sampled at 30 frames a second. The
client plays the mp3 and paints the track against it. Nothing analyses audio at
run time.

Offline for the same reason the quote grammar is offline. A clip is named for a
sha1 of its words and its voice, so a clip's audio can never change without its
name changing — which makes its mouth track cacheable forever and regenerable
never. Doing it in the client instead would mean every player's machine
recomputing the identical answer for the identical bytes, every time the line is
spoken, and a phone doing an FFT to decide where a jaw goes is a phone not
drawing the game.

WHY FROM THE AUDIO AND NOT THE TEXT. The honest options were a forced aligner
(Montreal, aeneas) mapping the authored words onto the waveform, or reading the
waveform alone. The aligner is more accurate about consonants and is a large
dependency that has to be installed, fed a pronunciation dictionary, and kept
agreeing with whatever Kokoro actually said — and Kokoro does not always say the
authored string, it says its own phonemisation of it. The waveform is what the
player will actually hear. It needs ffmpeg, which is already a dependency here
for encoding, and numpy. So: the waveform.

WHAT IS EXTRACTED, AND WHY ONLY TWO NUMBERS. Not a phoneme set. The portrait
this feeds is 208x277 pixels in a dialogue card with the head filling maybe 40%
of it, and at that size the difference between `mouthRollOutLower` and
`mouthShrugLower` is a couple of pixels that nobody will ever resolve. Two axes
carry essentially the whole effect:

    openness   how far the jaw is down          <- RMS envelope
    shape      lips spread (+) or rounded (-)   <- balance of F2 against F1

Vowel openness correlates with energy, and the first two formants separate the
vowel space well enough for a cartoon: a high second formant over a low first is
a front spread vowel (the vowel in "see"), the reverse is a back rounded one
(the vowel in "who"). Sibilants are found separately, in the 4-10 kHz band, and
matter because they are loud but the jaw is nearly shut for them — without that
correction every "s" reads as a shout.

Consonant closures need no special handling: /m/, /b/ and /p/ are silence, and
silence is already openness zero.

    python3 tools/gen_visemes.py --only aggie_slate --dry-run   # show the track
    python3 tools/gen_visemes.py --only aggie_slate
    python3 tools/gen_visemes.py --all
    python3 tools/gen_visemes.py --all --force                  # ignore the cache

Output, one file per NPC beside the clips that `gen_voiceover.py` wrote:

    assets/audio/vo/<npc_key>/visemes.json

keyed by clip filename, because that name is already a hash of the content. A
clip whose track is present is skipped unless --force, so a run after adding one
line costs one clip rather than the corpus.
"""

from __future__ import annotations

import argparse
import base64
import glob
import json
import os
import subprocess
import sys

import numpy as np

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "assets", "audio", "vo")

# What the model produces. Decoding to anything else would resample for no gain.
SAMPLE_RATE = 24000

# 30 is film, and a jaw is not fast. 60 doubled the payload to buy motion nobody
# reported seeing; the client interpolates between frames anyway, so the ceiling
# on smoothness is the interpolation, not this.
FPS = 30

# Long enough to resolve a formant at this sample rate (~23 Hz bins), short
# enough not to smear a stop consonant across the frame it closes on.
WINDOW = 1024

# Formant bands. F1 rises as the jaw opens; F2 rises as the tongue comes forward
# and the lips spread. The split at 1 kHz is above any English F1 and below any
# F2 that matters, so the two bands do not trade energy with each other.
F1_BAND = (200.0, 1000.0)
F2_BAND = (1000.0, 3000.0)
SIBILANT_BAND = (4000.0, 10000.0)

# A mouth cannot snap between shapes in a 33 ms frame. This is the weight of the
# new frame in the one-pole smoother; the rest is carried from the last one.
# Openness tracks faster than shape because a jaw drop IS the visible event and
# lagging it reads as dubbing, whereas lips rounding early is invisible.
OPEN_SMOOTHING = 0.55
SHAPE_SMOOTHING = 0.25

# A clip is normalised against its own loud end rather than an absolute level,
# because the cast runs from a whisperer to a sergeant and both should open
# their mouths. The percentile rather than the peak keeps one plosive from
# flattening the whole line.
LOUD_PERCENTILE = 95

# Below this fraction of the clip's loud end there is no speech, only room tone
# and the tail of the last word. Without a floor, silence between sentences
# amplifies into a mouth that chews continuously through the pauses.
SILENCE_FLOOR = 0.12


def decode(path: str) -> np.ndarray:
    """One mp3, as mono float samples, through ffmpeg on a pipe."""
    proc = subprocess.run(
        ["ffmpeg", "-v", "error", "-i", path,
         "-f", "f32le", "-ac", "1", "-ar", str(SAMPLE_RATE), "pipe:1"],
        capture_output=True,
    )
    if proc.returncode != 0:
        raise RuntimeError("ffmpeg: " + proc.stderr.decode("utf-8", "replace")[:200])
    return np.frombuffer(proc.stdout, dtype=np.float32).astype(np.float64)


def analyse(samples: np.ndarray) -> tuple[list[int], list[int]]:
    """The two tracks for one clip, each a byte per frame.

    Openness is 0 shut to 255 wide. Shape is 128 neutral, above it spread and
    below it rounded, so that an unvoiced frame sits in the middle and a client
    that ignores the channel entirely still gets a working jaw.
    """
    hop = SAMPLE_RATE // FPS
    frames = max(1, len(samples) // hop)
    window = np.hanning(WINDOW)
    freqs = np.fft.rfftfreq(WINDOW, 1.0 / SAMPLE_RATE)

    in_f1 = (freqs >= F1_BAND[0]) & (freqs < F1_BAND[1])
    in_f2 = (freqs >= F2_BAND[0]) & (freqs < F2_BAND[1])
    in_sib = (freqs >= SIBILANT_BAND[0]) & (freqs < SIBILANT_BAND[1])

    energy = np.zeros(frames)
    spread = np.zeros(frames)
    sibilance = np.zeros(frames)

    for i in range(frames):
        chunk = samples[i * hop: i * hop + WINDOW]
        if len(chunk) < WINDOW:
            chunk = np.pad(chunk, (0, WINDOW - len(chunk)))
        energy[i] = np.sqrt(np.mean(chunk * chunk))

        power = np.abs(np.fft.rfft(chunk * window)) ** 2
        total = power.sum() + 1e-12
        f1 = power[in_f1].sum()
        f2 = power[in_f2].sum()
        # Ratio rather than difference: it is the balance between the formants
        # that names the vowel, and the balance survives the speaker being loud.
        spread[i] = f2 / (f1 + f2 + 1e-12)
        sibilance[i] = power[in_sib].sum() / total

    loud = np.percentile(energy, LOUD_PERCENTILE)
    if loud <= 1e-6:
        # A clip of pure silence. Rare, but a division here would poison a whole
        # NPC's file with NaN and the client would see a mouth hanging open.
        return [0] * frames, [128] * frames

    openness = np.clip(energy / loud, 0.0, 1.0)
    openness[openness < SILENCE_FLOOR] = 0.0

    # An "s" is loud in the way a vowel is not, and the jaw is nearly shut for
    # it. Pull the opening down where the high band dominates, and push the
    # shape toward spread, which is what the lips are actually doing.
    hush = np.clip(sibilance * 6.0, 0.0, 0.85)
    openness = openness * (1.0 - hush)
    spread = np.clip(spread + sibilance * 2.0, 0.0, 1.0)

    # Centre the shape channel on its own clip. Absolute formant balance varies
    # more between two voices than it does between two vowels in one mouth, so
    # a fixed midpoint would leave some of the cast permanently pouting.
    voiced = openness > 0
    midpoint = float(np.median(spread[voiced])) if voiced.any() else 0.5
    shape = np.clip((spread - midpoint) * 2.5, -1.0, 1.0)
    shape[~voiced] = 0.0

    openness = smooth(openness, OPEN_SMOOTHING)
    shape = smooth(shape, SHAPE_SMOOTHING)

    return (
        [int(round(v * 255)) for v in np.clip(openness, 0.0, 1.0)],
        [int(round(v * 127 + 128)) for v in np.clip(shape, -1.0, 1.0)],
    )


def smooth(values: np.ndarray, weight: float) -> np.ndarray:
    """One-pole low pass, forwards only.

    Forwards only is deliberate: a symmetric filter would let a loud frame open
    the mouth before the sound that opens it, and a mouth that moves early reads
    worse than one that moves late.
    """
    out = np.empty_like(values)
    carry = values[0] if len(values) else 0.0
    for i, v in enumerate(values):
        carry += (v - carry) * weight
        out[i] = carry
    return out


def pack(values: list[int]) -> str:
    """A byte per frame, base64'd, because this rides in a JSON payload.

    A JSON array of 90 integers is about 380 bytes of text; the same frames
    packed are 120. The node payload carries one of these per clip and a busy
    node has half a dozen clips.
    """
    return base64.b64encode(bytes(values)).decode("ascii")


def track(path: str) -> dict:
    """The stored form of one clip's mouth."""
    samples = decode(path)
    openness, shape = analyse(samples)
    return {
        "fps": FPS,
        "frames": len(openness),
        "open": pack(openness),
        "shape": pack(shape),
    }


def npc_keys(only: str | None) -> list[str]:
    if only:
        return [only]
    found = []
    for path in sorted(glob.glob(os.path.join(OUT, "*", "lines.json"))):
        found.append(os.path.basename(os.path.dirname(path)))
    return found


def clips_for(npc_key: str) -> list[str]:
    """Every clip filename this NPC's manifest actually references.

    Read from lines.json rather than globbing the directory so that a clip
    orphaned by an edited line is not given a mouth track nobody will ask for.
    """
    manifest = os.path.join(OUT, npc_key, "lines.json")
    if not os.path.isfile(manifest):
        return []
    with open(manifest, "r", encoding="utf-8") as handle:
        lines = json.load(handle)

    names = []
    seen = set()
    for clips in lines.values():
        if not isinstance(clips, list):
            continue
        for clip in clips:
            src = str(clip.get("src", ""))
            if src and src not in seen:
                seen.add(src)
                names.append(src)
    return names


def run(npc_key: str, dry: bool, force: bool) -> tuple[int, int, int]:
    """Returns (written, skipped, missing) for one NPC."""
    folder = os.path.join(OUT, npc_key)
    out_path = os.path.join(folder, "visemes.json")

    existing = {}
    if os.path.isfile(out_path) and not force:
        try:
            with open(out_path, "r", encoding="utf-8") as handle:
                existing = json.load(handle)
        except (OSError, ValueError):
            existing = {}

    written = skipped = missing = 0
    tracks = dict(existing)
    for name in clips_for(npc_key):
        if name in tracks and not force:
            skipped += 1
            continue
        clip_path = os.path.join(folder, name)
        if not os.path.isfile(clip_path):
            missing += 1
            continue
        result = track(clip_path)
        tracks[name] = result
        written += 1
        if dry:
            print(f"  {name}  {result['frames']} frames")
            print("    " + bars(result["open"]))

    # Clips the manifest no longer mentions are dropped rather than carried, so
    # the file cannot outgrow the audio beside it.
    live = set(clips_for(npc_key))
    tracks = {k: v for k, v in tracks.items() if k in live}

    if not dry and (written or tracks != existing):
        with open(out_path, "w", encoding="utf-8") as handle:
            json.dump(tracks, handle, separators=(",", ":"), sort_keys=True)
    return written, skipped, missing


def bars(packed: str) -> str:
    """The openness track as something a person can look at."""
    glyphs = " .:-=+*#%@"
    return "".join(glyphs[min(9, b * 10 // 256)] for b in base64.b64decode(packed))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--only", help="one npc key")
    parser.add_argument("--all", action="store_true", help="every recorded npc")
    parser.add_argument("--dry-run", action="store_true", help="analyse, write nothing")
    parser.add_argument("--force", action="store_true", help="redo clips already done")
    args = parser.parse_args()

    if not args.only and not args.all:
        parser.print_help()
        return 2

    keys = npc_keys(args.only if args.only else None)
    if not keys:
        print("Nothing recorded under " + OUT, file=sys.stderr)
        return 1

    totals = [0, 0, 0]
    for key in keys:
        written, skipped, missing = run(key, args.dry_run, args.force)
        totals = [a + b for a, b in zip(totals, (written, skipped, missing))]
        if written or missing:
            note = f"{key}: {written} tracked"
            if skipped:
                note += f", {skipped} already done"
            if missing:
                note += f", {missing} MISSING AUDIO"
            print(note)

    verb = "would write" if args.dry_run else "wrote"
    print(f"{verb} {totals[0]} mouth tracks over {len(keys)} npcs"
          f" ({totals[1]} already done, {totals[2]} missing audio)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
