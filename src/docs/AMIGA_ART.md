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

The PNGs checked in under `assets/images/amiga-spike/` are Amiga-style
proofs. They are not Pillow filters of production art and not model output
from `--amiga`. The Aldric pair, the Rivermark plate, and the seventeen
portraits below were drawn by hand. A later batch, listed after those, was
made with GenerateImage to match the AmiDungeon PRO mockups.

- `assets/images/amiga-spike/npcs/aldric_face.png` — 144×144
- `assets/images/amiga-spike/npcs/aldric_bust.png` — 384×512
- `assets/images/amiga-spike/maps/rivermark.png` — 1536×1152

Seventeen more hand-generated portraits sit beside those Aldric proofs, same
contracts (144×144 face, 384×512 bust), still under
`assets/images/amiga-spike/npcs/` and still not wired into the game.
Production `assets/images/npcs` is unchanged. Each key has `<key>_face.png`
and `<key>_bust.png`:

aggie_slate, alder_pyke, bael_rourke, bram_ockley, brother_teodor,
corrin_dale, cully_tench, dessa_kell, dorn_ackley, foreman_dessel,
jimmy_able, kessa, mara_hearthstone, mott_wenn, orrin_bly, sella_carrow,
serjeant_ballow.

Sixteen more GenerateImage proofs sit beside those, matching the AmiDungeon
PRO mockups. They are not Pillow or filter samples of production art, and
not model output from `--amiga`. Same contracts (144×144 face, 384×512
bust), still under `assets/images/amiga-spike/npcs/` and still not wired
into the game. Production `assets/images/npcs` is unchanged. Each key has
`<key>_face.png` and `<key>_bust.png`:

fessick, gest_pyle, gillian_petch, gubb_wrayne, joss_frayle, ludo_corse,
marda_scole, nell_carrow, nesh_gray, odger_vance, osgar_tull, pol_reddick,
quartermaster_oyle, sib_marrell, sten_harrow, tibald_ashe.

Ten more GenerateImage proofs sit beside those, matching the AmiDungeon
PRO mockups. They are not Pillow or filter samples of production art, and
not model output from `--amiga`. Same contracts (144×144 face, 384×512
bust), still under `assets/images/amiga-spike/npcs/` and still not wired
into the game. Production `assets/images/npcs` is unchanged. Each key has
`<key>_face.png` and `<key>_bust.png`:

baker, innkeeper, merchant, penned_boys, smith, tull_barrow, wat_fenner,
wenna_coyle, wick_lorrimer, wren_tallow.

`tools/amiga_art.py --samples` still paints smaller AGA previews into those
same paths (64×64 face, 160×212 bust, 320×240 plate). Running it replaces
the proofs. It does not read production art and it does not call the model.

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
