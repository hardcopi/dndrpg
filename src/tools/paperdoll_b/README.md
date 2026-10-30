# Paper doll, style b (proof of concept)

The layers in `src/assets/images/paperdoll-b/` and the test page `tools/paperdoll_b_preview.html` come from these scripts. They are kept for reference. They read and write the art box's scratch paths (`/workspace/cc/paperdoll/`, `/workspace/cc/rivermark-front/figures_b.py` for `soft_key`) and the game doesn't run them.

This set is separate from the T&C `paperdoll/` library that `Paperdoll.php` bakes, and nothing in the game reads it yet.

## Pipeline

1. **Art (GenerateImage only).** First a base body: bald, plain linen tunic, A-pose, magenta key. Then one edit per part using the base as the reference image ("same man, same pose, same framing, only add X"). Raw images are 720x1280.
2. **`extract.py`** keys every image and registers each part image back onto the base with an ECC affine fit. The fit uses rows that the part does not touch, and the fits came out at 1-3 px. The part layer is the colour difference against the base (Lab, blurred), cleaned with morphology:
   - Hair layers drop skin-coloured pixels.
   - Armour fills from the difference into nearby non-skin body pixels, so the plate legs have no gaps.
   - Weapons keep only the gripping fist, not the forearm.
3. **`weapons.py`** cleans up the hand-held items:
   - The longsword keeps its blade and fist.
   - The staff's shaft comes from a staff edit whose arm was bent. It is re-gripped by the longsword fist so the arm never moves.
   - The shield is cut to a fitted ellipse.
   - It also writes the "cut" mask, which removes the base's open right hand (and any armour hand) when a fist is drawn over it.
4. **`stage3.py <depth>`** (32, 64, 128 or 256) does the style-b conversion and writes `paperdoll-b/<depth>/`:
   - Every layer goes through the same transform (scale 0.2587, then a fixed offset onto 192x320), with hard alpha and a 1 px ink outline. Where hair meets the face, the outline is dark hair instead of black ink, so hairlines are softer.
   - The palette is shared. Skin, hair, cloth and metal are swappable luminance ramps of N steps (5/10/20/40), interpolated from five anchor colours. The fixed groups (leather and wood, linen, brass, the crystal) get a k-means palette of their own pixels. Every entry is a unique RGB value.
   - Swappable ramps get their level from luminance: half linear, half equalised, pooled across layers, so skin in the base and skin in a fist match. A 4x4 Bayer dither is applied at 0.9 / 0.55 / 0.35 / 0.12 of a step.
   - The plate's steel is pulled down a stop.
5. **`sheet.py`** writes the contact sheet, and **`compare.py`** writes the depth comparison.

## Recolouring

Each depth's `manifest.json` lists every ramp's default colours, plus every variant already expanded to the same number of steps. Each pixel in every layer is exactly one default colour, so a recolour is a lookup from RGB to (ramp, level) and then to the same level of the chosen variant. Only `skin`, `hair`, `cloth` and `metal` swap. Metal is one ramp, so it also tints blades, rims and shield bosses.

## Layer order

The order is `back, base, armour, hair, off, main`. Nothing uses `back` yet. It is reserved for capes and quivers, which go behind the body.
