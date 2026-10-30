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
4. **`stage2.py`** does the style-b conversion:
   - Every layer goes through the same transform: scale 0.2587, then a fixed offset onto 192x320 with the feet 4 px from the bottom.
   - Alpha is hard and the edges get a 1 px ink outline.
   - Pixels use one shared 32-colour palette made of ramps: ink 1, skin 5, hair 5, cloth 5, metal 5, leather 5, linen 3, brass 2, magic 1. Each pixel gets a ramp from its colour, limited by which ramps that layer may use.
   - Each pixel gets a level from luminance with a 4x4 Bayer dither. The thresholds are pooled across layers, so skin in the base and skin in a fist match.
5. **`sheet.py`** writes the contact sheet.

## Recolouring

`manifest.json` lists every ramp's default colours. Each pixel in every layer is exactly one default colour, so a recolour is a lookup from RGB to (ramp, level) and then to the same level of the chosen variant. Only `skin`, `hair`, `cloth` and `metal` swap. Metal is one ramp, so it also tints blades, rims and shield bosses.

## Layer order

The order is `back, base, armour, hair, off, main`. Nothing uses `back` yet. It is reserved for capes and quivers, which go behind the body.
