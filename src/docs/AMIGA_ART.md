# Amiga PRO art spike

This is an opt-in art direction for the Rivermark host, not a UI reskin or a
replacement of shipped production art. The Amiga client can continue to load the
host's existing PNG paths; this spike keeps candidates parallel until Rich
approves a later deploy.

## Brief

- **Chrome reference:** charcoal `#171A20` / `#242A33`, readable cool grey, and
  cobalt accent `#378EF0`. Art should use that blue sparingly, not compete with
  controls or labels.
- **Parchment maps:** warm paper, quiet sepia ground, dark crisp plan lines,
  large areas of blank paper, and no labels baked into the image. The client's
  current-location disc is cobalt; the plate does not paint one.
- **People:** the dialogue-theatre portrait from the AmiDungeon mockups. An
  early-1990s adventure painting: readable head-and-shoulders silhouette,
  mottled charcoal-umber ground, shading as pixel clusters rather than a smooth
  gradient. Not a photograph, and not a smooth modern illustration.
- **RTG delivery ceilings:** 144×144 face, 384×512 bust, and 1536×1152 region
  plate, matching the host's current asset contracts.
- **AGA-safe preview ceilings:** 64×64 face, 160×212 bust, and 320×240 region
  plate, at most 32 colours. These are conservative pixel-friendly preview
  targets, not a claim that every AGA mode has the same display limits.
- **Names:** use the established sprite key: `<key>_face.png` and
  `<key>_bust.png`; region plates use `<region_key>.png`. Candidate files stay
  under `assets/images/amiga-spike/{npcs,maps}/`, never in the live folders.

`tools/gen_npc_art.py --amiga` and `tools/gen_region_map.py --amiga` only
switch the prompt and the output folder. They are the opt-in model path, for a
machine that has the local image service. They are not what produced the
checked-in samples, and the default painterly prompts are unchanged.

## Samples without an image service

The local SANA-Sprint service (`QWEN_URL`, default
`http://127.0.0.1:7860`) is not part of the host stack and may be absent.
The checked-in samples do not need it, and they are not a resize of the
shipped PNGs. Filtering those kept the smooth painting; the busts were
rejected for looking like that. `tools/amiga_art.py` paints new ones with
Pillow: a limited ramp per material, Floyd–Steinberg inside that ramp (so the
cobalt stone cannot freckle the face), and locked pixels for the eyes, the
mouth and the disc, which a dither would otherwise sand off at 64px.

```bash
cd src
python3 tools/amiga_art.py --samples
```

This writes:

- `assets/images/amiga-spike/npcs/aldric_face.png` — 64×64
- `assets/images/amiga-spike/npcs/aldric_bust.png` — 160×212
- `assets/images/amiga-spike/maps/rivermark.png` — 320×240

Aldric here is a new drawing for the spike: short hair, a close beard, linen
at the throat, wool robe, a borrowed sun-disc with one cobalt stone. It is not
traced off `assets/images/npcs/aldric_bust.png`. The face is the same drawing
sampled through a tighter window, not a second portrait and not a Lanczos
shrink of the bust — a filtered shrink is what melted the last attempt back
into a smooth painting. The map is warm parchment, a river bank, and the
public roads from `content/locations/rivermark.json`. No names, no location
discs, no cobalt marker; those are the chart's.

The painter is deterministic. Two runs write the same pixels, on a machine
with no model and no network. Pillow is the only dependency, as it is for the
other art tools.

To inspect the opt-in model prompts without calling the service:

```bash
python3 tools/gen_npc_art.py --only aldric --amiga --dry-run
python3 tools/gen_region_map.py --only rivermark --amiga --dry-run
```

Neither the samples nor the opt-in generator outputs are wired into the game.
Production folders are not written.

## AmiDungeon verification after an approved deploy

First fetch the candidate over HTTPS and verify both status and content, not
status alone:

```bash
curl -fsS -D /tmp/aldric.headers \
  -o /tmp/aldric.png \
  https://rpg.five-star.com/assets/images/amiga-spike/npcs/aldric_face.png
grep -i '^content-type: image/png' /tmp/aldric.headers
file /tmp/aldric.png
```

Then configure AmiDungeon's test build to load that URL and confirm the same
sprite in the **party rail**, **PEOPLE HERE**, and the **dialogue theatre bust**.
The first two use the face crop; the theatre uses the bust. Repeat against the
region plate URL to check its parchment remains legible beneath the client-drawn
chart. Only after visual sign-off should the approved candidate be promoted to
the established asset path and deployed to Hermes. Do not run
`deploy.sh --apply` as part of this spike.
