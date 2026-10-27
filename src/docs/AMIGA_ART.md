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
  large areas of blank paper, and no labels baked into the image.
- **People:** readable head-and-shoulders silhouettes, pixel-cluster shading,
  charcoal grounds, small restrained blue accents; no photoreal textures.
- **RTG delivery ceilings:** 144×144 face, 384×512 bust, and 1536×1152 region
  plate, matching the host's current asset contracts.
- **AGA-safe preview ceilings:** 64×64 face, 160×212 bust, and 320×240 region
  plate, quantized to at most 32 colours. These are conservative pixel-friendly
  preview targets, not a claim that every AGA mode has the same display limits.
- **Names:** use the established sprite key: `<key>_face.png` and
  `<key>_bust.png`; region plates use `<region_key>.png`. Candidate files stay
  under `assets/images/amiga-spike/{npcs,maps}/`, never in the live folders.

`tools/gen_npc_art.py --amiga` and `tools/gen_region_map.py --amiga` use the
prompt profile and write generated candidates to the parallel folders. Existing
commands and their painterly style remain unchanged.

## Samples without an image service

The local SANA-Sprint service (`QWEN_URL`, default
`http://127.0.0.1:7860`) is not part of the host stack and may be unavailable.
The checked-in samples are deterministic AGA-size previews made from existing
host assets, not newly generated paintings:

```bash
cd src
python3 tools/amiga_art.py --samples
```

This writes `assets/images/amiga-spike/npcs/aldric_face.png`,
`assets/images/amiga-spike/npcs/aldric_bust.png`, and
`assets/images/amiga-spike/maps/rivermark.png`. It downsizes and palette-limits
the existing files without modifying them. Pillow is required, as it is for
the existing art-processing tools.

To inspect prompts without the model, for example:

```bash
python3 tools/gen_npc_art.py --only aldric --amiga --dry-run
python3 tools/gen_region_map.py --only rivermark --amiga
```

The region command above will attempt generation; use the sample command when
the local model is unavailable. Neither sample previews nor opt-in generator
outputs are wired into the game.

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
