# Character creator — scope

A modular paperdoll creator, built on `Medieval T&C/Medieval_T&C_Humans`. Every
question that could have sunk this has been checked against the real files and
the running server; the findings are at the bottom.

## What the art gives us

| | Male | Female | Children |
|---|---|---|---|
| Base bodies | 3 | 3 | 4 |
| Hair (incl. `_Alt_N` colours) | 30 | 35 | — |
| Facial hair | 10 | — | — |
| Top | 9 | 5 | — |
| Dress | — | 5 | — |
| Pants | 5 | 3 | — |
| Feet | 3 | 2 | — |
| Head | 2 | — | — |
| Accessory | 5 | 8 | — |
| Weapon slots | 4 main, 3 off | 4 main, 3 off | — |

**Every layer ships a complete animation set at the base body's exact
dimensions** — `walking` and `walking_diag` at 1024×512, `MVsv` at 1152×768,
`Face` at 576×288, `Bust` at 512×512, `Paperdoll` at 512×512. They are built to
stack pixel-for-pixel, so compositing is a plain alpha-over with no alignment
work. `_Alt_N` hair variants share an identical silhouette with their base, so
they are colour swaps and can be presented as a colour picker rather than as
thirty separate hairstyles.

Ignoring colour variants that is roughly **3 × 6 hair × 9 top × 5 pants × 3 feet
× 10 facial hair** before accessories — tens of thousands of combinations, which
is the point.

## Layer order

Not guessed. `Medieval T&C/Read Me.docx` states it:

```
Shadow (optional)
Base template (nude)
Feet*
Pants*
Gloves
Top
Hair
Weapon
Accessory**
```

\* The pack notes feet and pants are sometimes reversed depending on the boot and
trouser style, so the order needs to be a constant the creator can flip per
outfit rather than hardcoded.
\*\* Head and facial hair are not in the pack's list; from the head-crop overlap
test, facial hair belongs above the base and below hair, and head above hair.

## The approach: bake, don't stack

Composite the chosen layers into a single sprite set at creation time and write
it to `assets/images/npcs/<key>_sheet.png`, `_face.png`, `_bust.png`. **The
renderer needs no changes at all** — it sees another `sprite_key`, exactly like
the 69 actors already cut, and every existing path (map sprite, party rail,
combat board, dialogue bust, MVsv clips) works untouched.

The alternative — stacking eight absolutely-positioned layers per entity at
runtime — multiplies DOM elements for every character on screen, and `renderMap`
is already the most expensive thing in the client. Rejected.

The creator UI still stacks in the browser for the **live preview**, because
that has to be instant and touches one character, not a whole map.

## Where it fits

```
create.php                     the existing form gains an Appearance step
assets/js/create.js            layer pickers + live preview
app/lib/Paperdoll.php          NEW: catalogue, validation, and the bake
api/index.php                  meta/paperdoll  (what can be picked)
                               character/appearance (bake and save)
tools/slice_paperdoll.py       NEW: cut the layer library out of the pack
assets/images/paperdoll/       the sliced layers, served for the preview
characters.appearance_json     NEW column: the chosen layers, so it is re-editable
```

`characters.sprite_key` becomes the generated key. `appearance_json` records the
choices, so a character can be re-dressed later without losing what they were —
and so a future art fix can re-bake everyone.

## Plan

**1. Cut the layer library.** `tools/slice_paperdoll.py`, alongside
`slice_assets.py` and following its manifest conventions. For each base body and
clothing piece, cut the 8-facing walk sheet, the face and the bust into
`assets/images/paperdoll/<sex>/<category>/<key>.png`. Emit a
`paperdoll/index.json` recording categories, keys, labels, colour variants and
the layer order — the same pattern as `anim/index.json` and `busts.json`, for the
same reason: the client cannot enumerate a directory.

**2. `Paperdoll.php`.** Reads the index, validates a chosen set (every key
exists, one per category, sex-consistent), and bakes. GD is available and alpha
compositing is confirmed correct; a nine-layer bake is ~36 MB against a 128 MB
limit. Output keys are `pc_<character_id>` so they cannot collide with authored
art.

**3. Creator UI.** `create.php` is a five-step wizard — Identity, Calling,
Abilities, Appearance, Review — and it is deliberately the one page in the game
that does not scroll on a desktop. `body.wizard-page` cannot scroll, `.wiz-body`
is the only flexible region, and each step is sized to fit inside it. A step that
does not fit wants splitting; that is a design answer rather than a scrollbar.

It used to be one long form: name, race, class, background, alignment, six
ability scores, a twelve-portrait grid and nine layer pickers stacked down a page
you scrolled three times. Everything was visible, which sounds like a virtue, and
none of it was legible.

The steps are data, not five hand-wired screens. Each entry in `STEPS` names the
section it shows and a `ready()` returning `null` when you may proceed or the
sentence to print instead; Next is disabled and the reason appears beside it, so
the button never refuses without saying why. Back is always allowed and the rail
jumps to any step already reached — forward only through Next, because a later
step's validity depends on an earlier one's answers.

Two things that fall out of the wizard shape and had to be built for it:

- **The name is checked on step one.** `meta/name_free` asks the same question
  `assertNameFree` does, debounced, cached against the exact string. Without it a
  collision surfaced on submit — after choosing a face, a haircut and six ability
  scores — which is the precise frustration a wizard introduces if nobody checks
  early.
- **The Review step's numbers have to be true.** It is the only place before
  committing where the scores you will actually play with appear, since the
  abilities step shows base scores and the server adds racial bonuses. AC is the
  hard one: creation equips the class kit and recalculates, so a review printing a
  bare 10 + Dex is contradicted by the character sheet one click later.
  `meta/classes` therefore serves each class's `starting_gear` and
  `starting_armor`, and `CharacterGenerator::startingAc` and the client's
  `startingAc` agree by construction. `tools/test_creation.php` creates a
  character of every class at three Dexterity values and asserts the two match —
  36 assertions, all rolled back.

The Appearance step's own layout: sex, body, then a picker per category with the
live stacked preview and a facing toggle so you can see the back of the head, plus
Randomise. The preview uses the sliced layers directly, so it needs no server
round trip per click. Pickers flow into `columns: 230px` — three on a desktop, two
on a laptop, one on a phone, with no breakpoints to keep in sync.

Two views, both composited from the same ordered layer list so they cannot
disagree with each other or with the bake:

| | Frames | Facing |
|---|---|---|
| Portrait (`#pd-bust`) | 320px squares, `<key>_bust.png` | always front |
| Walking figure (`#pd-preview`) | 128px cells of the walk sheet | four cardinal, via **Turn** |

The portrait is the one that matters most — it is the face in every conversation
— and judging a haircut from a 40px-tall walking figure was guesswork. Boots are
the only layer in the library with no bust frame, which is right rather than
missing: a portrait stops above the ankle. Any layer whose bust fails to load
removes its own `<img>`, mirroring the server's `is_file` skip, so an absent file
cannot leave a broken-image icon on the character's chest.

Colour swatches are painted with the mean of each variant's opaque pixels rather
than a thumbnail of the artwork. A beard covers a few hundred pixels of a 128px
frame, so five thumbnails were five identical grey smudges; what the player is
choosing is a colour, so the swatch shows the colour.

## The dice

CSS 3D, no library, no build step. `tools/gen_dice_css.py` derives an
icosahedron and a cube from their own geometry and emits two files:
`assets/css/dice.css` (a `matrix3d` per face, plus the triangle `clip-path` and
the `.show-N` resting orientations) and `assets/js/dice-geometry.js` (the same
resting orientations as Euler angles, for JavaScript).

Three things in there are worth knowing before touching it:

- **Nothing on the client decides a number.** `Dice3D.land(stage, value)` is told
  the value. Every dice UI has to solve "look thrown, finish on a predetermined
  face", and a resting orientation being a plain triple of Euler angles is what
  makes it easy: a throw is that triple plus some whole turns.
- **The turns have to be Euler angles, not a matrix.** CSS interpolates two
  `matrix3d` values by decomposing to quaternions and taking the shortest arc,
  which discards whole rotations — the die would pivot sedately instead of
  tumbling. Matching lists of `rotate*` functions interpolate componentwise, so
  `rotateX(1080deg)` really is three turns.
- **A face box is not the die's footprint.** 106px faces draw a solid 176px
  across, and a cube is one edge wide at rest but passes through its diagonal
  mid-throw, swelling by 70%. Both numbers are measured by the generator and
  published at `:root`, which is why the spacing between dice in a tray is a
  `calc()` rather than a guess — the first version allowed for the tumble
  horizontally and not vertically, and rows of dice passed through each other on
  the way down.

Verification is split, because the claims are different. `gen_dice_css.py --check`
proves the geometry in Python — 20 faces, 6 faces, every basis orthonormal, every
`show-N` presenting its own face upright — and that the files on disk match what
the generator would produce now. `/tools/dice_preview.html` proves the *browser*
agrees, by reading back its own composite matrices for all 26 faces. That second
page originally hit-tested with `elementFromPoint`, which is quietly unreliable
inside a `preserve-3d` context: it reported nothing for the four faces whose
resting rotation involves an exact 90°, on which the rendering was in fact
perfect.

The d20 ceremony uses it by default; `?dice=flat` on `game.php` brings back the
old hexagon so the two can be compared on the same throw.

## 4d6, and who is allowed to roll it

The Random method's dice are thrown by the server. `meta/roll_abilities` rolls six
4d6-drop-lowest, returns every individual die so the animation has something to
replay, and stashes the six totals in the session; `character/create` then accepts
only an arrangement of exactly those, and refuses outright if the session holds
none.

This was not decoration. The old Random method rolled in JavaScript as a
"preview" and `resolveAbilities` threw the numbers away and rolled again, so the
scores a player watched appear were never the scores they got. That is survivable
while the roll is a number changing in a box; it is not once twenty-four dice
tumble across the screen to announce a result.

Rerolling stays unlimited — each roll simply overwrites the session's. What is
guaranteed is that the dice on screen are the dice that count, not that you only
get one go.

Each tray row is one *throw*, not one ability, and carries a dropdown for which
ability it feeds. The first version had six trays and then a six-box ability grid
underneath showing the same numbers again, which both pushed the step off one
screen and read as a contradiction — dice adding to 12 beside a score of 16.

One rules bug fell out of rebuilding the abilities step. The standard array is a
*permutation* of 15/14/13/12/10/8, and the old picker was six independent
dropdowns each offering all six numbers — so 15 in every ability was two clicks
away, and `resolveAbilities` accepted whatever arrived. Choosing a number now
swaps it with whoever holds it, which keeps the multiset intact so the step can
never be invalid rather than being validated and refused, and the server rejects a
set that is not a permutation regardless of what the UI sent.

**4. Re-dress.** `character/appearance` accepts a new set for an existing
character and re-bakes. Cheap once the bake exists, and it makes the whole
feature forgiving. The entry point is a **Look: change** pill on the character
sheet linking to `create.php?redress=<id>`, which reuses this whole page with the
stats and the portrait tabs hidden — companions do not get the pill, because
their art belongs to the authored template rather than to the row.

## What I checked, so this is not optimism

- **GD is loaded in the running FPM** — `tools/check_image_libs.php` confirms
  PNG support, alpha-correct `imagecopy`, and the memory headroom. Imagick is
  absent; GD is enough.
- **Layers align.** Same dimensions across body and every clothing piece.
- **Layers ship portraits**, not just walk sheets — so the face and bust can be
  composited too, and a created character gets a real dialogue portrait.
- **`_Alt_N` variants are colour-only**, verified by identical alpha channels.
- **Layer order is documented** in the pack, not inferred.

## Two things worth deciding first

**The look.** These are the same modular bodies the Interiors, T&C and Warfare
premades are built from — so a created character reads as a townsperson, not as
Baenor or Leyanne. That is more *yours* but less striking, and a created PC
standing beside Kessa's Heroes-pack art may look like it came from a different
game. Worth making one and comparing before building the UI.

**Children have no clothing.** Four base bodies, nothing to dress them in. They
are for NPCs, not the creator.

## Estimated shape

The slicer is the bulk of it — roughly 140 clothing pieces × 3 outputs, and the
pack mount is slow, so it wants the `--only`/`--actors` batching
`slice_assets.py` already has. `Paperdoll.php` is small. The UI is a picker
grid and a stack of absolutely-positioned images, which is the easy part.
