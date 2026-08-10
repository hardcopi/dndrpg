# Location establishing shots — Undertown and the two new town gates

Companion file to `LOCATION_PROMPTS.md`, same workflow and same style contract.
Covers the **town tier** added for Act I: two new Rivermark locations (`west_gate`,
`river_stair`) and the eight locations of the new `undertown` region.

Kept separate only because three people are writing art docs at once. Merge into
`LOCATION_PROMPTS.md` when convenient — nothing here contradicts it.

Drop finished files here as:

```
src/assets/images/locations/<location_key>.png
```

`location_key` is the authoring key (e.g. `low_court.png`), **not** the pretty name.

---

## Global style contract (paste at the top of every request)

Use this block once per image, then the location-specific section. This is the
same block as `LOCATION_PROMPTS.md` — do not vary it, the set has to hang
together.

```
I need one establishing photograph for a fantasy RPG location screen.

STYLE
Grounded fantasy realism, slightly desaturated, weathered and lived-in — the look
of a still from a prestige fantasy series, not a painting, not a video-game
render, not concept art with dramatic god-rays. Natural light appropriate to the
place (hearth, forge, overcast daylight, lantern, etc.). No lens flare, no bloom,
no magical glow effects, no HUD, no letterboxing, no film grain frame, no border.

COMPOSITION
Eye-level or slightly below, wide enough to read the room or street as a place,
not a character portrait. Depth of field mild (background readable). If a person
is present, they are part of the scene (working, waiting, keeping watch), not
posing for a bust shot — three-quarter or full figure, mid-ground, not filling
the frame. No player character in shot.

OUTPUT
16:9 landscape, 1536 × 864 px (1280 × 720 acceptable), opaque PNG, full-bleed.
STRICTLY NO TEXT, NO SIGNS WITH LETTERS, NO LABELS, NO WATERMARKS.
```

### One extra note for the Undertown set

Undertown is **brick, water and lamplight**, and it must not read as a cave or a
crypt. Everything below ground here was *built* — dressed brick, struck joints,
sprung arches, iron fittings — by people who expected it to last two hundred
years, and it has. Where there is decay it is the decay of masonry, not of stone.

Light source is always a carried lamp or a lamp set down, plus daylight coming
down through street gratings in bars. Nothing glows on its own.

```
Add to the STYLE block for every Undertown image:

The space is built masonry, not a natural cave: dressed brick, even courses,
sprung arches, iron fittings. Light comes from carried oil lamps and from
daylight falling through street gratings above. No torches on walls, no glowing
crystals, no bioluminescence, no magical light of any kind.
```

---

## Portraits — when and how to attach

Same rule as the main file. Attach the listed bust PNG(s) and say:

```
Attached image(s) are reference portraits for likeness only (face, hair, outfit,
build, race). Match them closely. Do not copy the black studio background or the
bust crop — put the person into this scene naturally.
```

Only named permanent NPCs who *belong* to the place. Never ambient townsfolk.

Every NPC below reuses an existing sprite set, so every bust path already exists
on disk — no new NPC art is required for this tier.

| Who | Where they stand | Bust path |
|---|---|---|
| Serjeant Ballow (west gate watch) | `west_gate` | `assets/images/npcs/captain_bust.png` |
| Mott Wenn (quay porter) | `river_stair` | `assets/images/npcs/fighter_bust.png` |
| Sib Marrell (nightman) | `river_stair` | `assets/images/npcs/sweeper_bust.png` |
| Dov (mudlark) | `low_conduit` | `assets/images/npcs/beggar_bust.png` |
| Aggie Slate (the Bench) | `low_court` | `assets/images/npcs/prisoner_3_bust.png` |
| Wat Fenner (gateman) | `flood_gate` | `assets/images/npcs/miner_bust.png` |

Named NPCs from this tier who stand in **existing** locations, listed here only
so that a later reshoot of those rooms has the reference to hand — no new
location art needed for them:

| Who | Existing location | Bust path |
|---|---|---|
| Tibald Ashe (counting-house clerk) | `counting_house` | `assets/images/npcs/monk_bust.png` |
| Nell Quist (baker) | `market_square` | `assets/images/npcs/baker_bust.png` |
| Dagen Wyke (almoner) | `high_street` | `assets/images/npcs/cleric_bust.png` |
| Nan Vetch (fortune-teller) | `high_street` | `assets/images/npcs/sorcerer_bust.png` |
| Rilk (loiterer) | `east_gate` | `assets/images/npcs/rogue_bust.png` |
| Pol Reddick (granary weigher) | `granary_row` | `assets/images/npcs/farmer_bust.png` |
| Corbin Waite (bargeman) | `flagon_common_room` | `assets/images/npcs/farmer_bust.png` |

---

## Batch order (suggested)

Town first, because both new town locations are on the critical path out of
Rivermark. Then Undertown from the entrance inward, so that the brick, the water
line and the lamp colour are established before the set-pieces.

1. `west_gate` — Ballow
2. `river_stair` — Mott Wenn (Sib Marrell optional)
3. `sump_drain`
4. `low_conduit` — Dov
5. `weir_chamber`
6. `low_court` — Aggie Slate
7. `grain_stair`
8. `flood_gate` — Wat Fenner
9. `drowned_counting_room`
10. `bricked_arch`

---

# Location prompts

For each location: attach the portraits listed under **Attach**, paste the
**global style contract** (plus the Undertown addendum where marked), then paste
the block under **Prompt**.

---

## 1. `west_gate` — The West Gate

**Attach:** `captain_bust.png`
**Output file:** `west_gate.png`

**Prompt:**

```
SUBJECT — exterior of Rivermark's lesser west gate, looking out along the road,
overcast afternoon.

A timber arch set in a stretch of town wall the town stopped maintaining when
the trade went east: propped on one side with raking timbers, patched on the
other with quarry stone that does not match the original. The gate leaf hangs
open on one hinge and has clearly hung that way for years. Beyond the arch the
road is mud and cart-ruts rather than metalling, running out through flat wet
fields toward distant farmland. Standing water in the ruts. A cart track, no
traffic.

This is the poor gate. Nothing here is defended and everybody knows it.

KEY NPC (required) — Serjeant Ballow, town watch.
Match the attached portrait: a man past fifty, twenty-two years in, watch collar
and a posting well beneath it. He stands under the arch doing the job properly —
back straight, hands behind him, eyes on an empty road — which is quietly the
saddest thing in the frame. Mid-ground, three-quarter, not facing camera.

No other people. Mood: a door nobody watches, watched anyway.
```

---

## 2. `river_stair` — The River Stair

**Attach:** `fighter_bust.png` (optionally also `sweeper_bust.png`)
**Output file:** `river_stair.png`

**Prompt:**

```
SUBJECT — exterior, Rivermark's river landing, seen from partway down the
steps, flat grey daylight.

Forty worn stone steps drop from the quay wall to a stone landing at the water,
with heavy iron mooring rings set into it. A flat-bottomed river barge is tied
up, low in the water, half unloaded — coopered crates and salt sacks stacked
under a tarpaulin above the tide-line. Bollards. Coils of rope. A tide-scale cut
into the stone of the wall, unpainted for a generation and read anyway (marks
and notches only — NO NUMERALS, NO LETTERING).

The river is brown, wide, patient, moving faster than it looks. Town noise has
fallen away behind the quay wall. Gulls on the tide-line.

KEY NPC (required) — Mott Wenn, quay porter.
Match the attached portrait: a big man in his forties, a carrier rather than a
fighter. He is sitting on a bollard at the top of the landing eating something
out of a cloth, watching the water do the work. Not posing, not looking at
camera.

OPTIONAL second figure — Sib Marrell, nightman: if the second portrait is
attached, put him alone on the bottom step, downwind of everything, doing
nothing with great determination. Leave a clear gap between him and everyone
else; that gap is the point.

Mood: everything the town did not grow arrives here, and everything it cannot
account for leaves the same way.
```

---

## 3. `sump_drain` — The Sump Drain

**Attach:** none
**Output file:** `sump_drain.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, the brick throat behind the Golden Flagon's cellar wall,
lamplight only.

A gap in an old cellar wall opens into a stooping-height brick passage. The
brickwork is two hundred years old and beautifully laid — even courses, tight
joints, a shallow sprung arch overhead — laid by someone who expected it to be
looked at, and nobody has looked at it since.

A thin constant sheet of water runs down one wall and away along a channel cut
into the floor. Sludge on a narrow dry ledge, scuffed with traffic. A single
bootprint in the sludge, small, going the wrong way — read it in the composition,
do not spotlight it.

The lamp reaches about eight feet and then gives up entirely. Beyond that,
black.

No people. No creatures. Mood: this was built, and it has been used recently,
and nobody upstairs knows.
```

---

## 4. `low_conduit` — The Low Conduit

**Attach:** `beggar_bust.png`
**Output file:** `low_conduit.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, the main flood conduit under Rivermark's High Street,
looking along it.

A barrel vault of dressed brick, tall and wide enough to drive a cart down,
running away into dark in both directions. Ankle-deep water down the centre
channel; a dry raised ledge along either side. Every twenty feet a smaller house
drain enters from above, and most of them are stopped with rag — one of the rags
is obviously fresh.

Above, daylight comes down through a street grating and lies across the water in
hard bars. That is the only light besides the lamps.

KEY NPC (required) — Dov, mudlark.
Match the attached portrait: about thirty, wearing four coats, carrying a hooked
pole and a shuttered lamp. Standing on the dry ledge at a careful distance —
far enough back that reaching them would have to be a decision. Wary, competent,
not pitiable. Not begging, not cowering, not looking at camera.

Mood: an inhabited road, not a sewer. Voices carry down here and arrive without
a direction.
```

---

## 5. `weir_chamber` — The Weir Chamber

**Attach:** none
**Output file:** `weir_chamber.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, a brick chamber where four drains fall into one pool,
lamplight and spray.

Four brick outfalls arrive at four different heights and pour over a stepped
stone weir into a pool that is deeper than the lamp reaches. Constant heavy
falling water, mist in the air, every brick within reach furred green with wet
growth. An iron handrail was set into the wall once; half of it is still there,
and the stub is worn bright by hands. The missing half was taken, not lost.

Loud — compose so the viewer can hear it. Sheeting water, spray haze, no
individual dramatic splashes.

No people, no creatures. Mood: the one place down here where you cannot hear
yourself think, and the water goes down further than the light does.
```

---

## 6. `flood_gate` — The Flood Gate

**Attach:** `miner_bust.png`
**Output file:** `flood_gate.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, an iron flood gate hung across a brick cut, lamplight.

A shut iron gate the size of a barn door in a dressed stone frame, with a
worm-screw and a spoked wheel to raise it. THE CRITICAL CONTRAST: everything
around it is two hundred years old and going soft — and the gate is new work.
New pintles, new packing, bright grease on the screw that has not yet gone grey,
spokes polished by use. Somebody paid a proper price for this and did not tell
the town.

Water stands against the gate to a wet line about four feet up the brick on the
near side. Through and beyond the gate, the cut runs on and is completely dry —
that dry/wet boundary is the whole shot.

KEY NPC (required) — Wat Fenner, gateman.
Match the attached portrait: about sixty, in a good oilskin, a former river
lock-keeper. Sitting on the stone sill beside the gate with a lamp that has
proper glass in it, at ease, unhurried, the way a man sits at a job he has done
for a year. A square iron key inside his coat or across his knees.

Mood: somebody has decided where the water stops, and it was not the town.
```

---

## 7. `low_court` — The Low Court

**Attach:** `prisoner_3_bust.png`
**Output file:** `low_court.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, the vaulted brick room under Rivermark's market square
where about sixty people live. Lamplit, dry, swept.

This is the most important image in the set and the easiest to get wrong. It
must NOT read as a den, a slum, a thieves' guild or a horror. It reads as a
community hall in a cellar: DRY floor, SWEPT floor, sailcloth curtains across
living bays along the walls, washing up on a line, a cooking pot on a small
fire, oil lamps in wall niches that nobody has to guard. Poor and orderly, and
the order is deliberate and defended.

At the far end, a single scaffold plank laid across two barrels, worn smooth in
the middle from years of one person sitting on it. Nothing else at that end of
the room. Nobody sits on it but the bench, and everybody knows.

KEY NPC (required) — Aggie Slate, the Bench.
Match the attached portrait: a woman of about sixty, plainly and poorly dressed,
sitting on the plank with her hands folded in her lap, entirely composed, doing
nothing in the manner of somebody whose job is to be available. She should read
as a magistrate, not a beggar.

Other figures: a few residents at middle distance going about ordinary business
— stirring the pot, mending, carrying water. Backs and three-quarter figures,
faces indistinct, no invented named characters. Nobody menacing. Nobody
grovelling.

Mood: a town under a town, that has rules, and keeps them.
```

---

## 8. `drowned_counting_room` — The Drowned Counting Room

**Attach:** none
**Output file:** `drowned_counting_room.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, the flooded cellar strongroom of a bank that failed forty
years ago, lamplight over standing water.

NOT a drain. A room: plastered walls, a moulded plaster cornice, a plaster
ceiling rose, a proper door frame with a lintel. All of it half drowned — two
to three feet of still, silty water, so that the cornice runs just above the
waterline and the viewer's eye takes a moment to understand that they are
looking at a fine room from chest height.

At the back, an iron strongroom door standing open. Beyond it, racks of small
iron deposit boxes, chest-deep, each with a brass name-plate. The plates catch
the lamp. (NO READABLE LETTERING — engraved marks only, suggestion of names, no
legible text anywhere in frame.)

Against one wall, a clerk's desk, and on it a single swollen waterlogged ledger,
still shut.

The water is dead still and gives back the lamp as a shape that is only the
water. Silt hangs in it, disturbed.

No figures. Mood: forty years of somebody else's money standing in the dark,
and the room is politer than anything that has happened in it.
```

---

## 9. `bricked_arch` — The Bricked Arch

**Attach:** none
**Output file:** `bricked_arch.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, a brick passage that ends in a wall, lamplight, close and
tight.

An old sprung brick arch — soft hand-made brick, lime mortar, weeping at every
joint, the same two-hundred-year-old fabric as the rest of Undertown — and the
arch has been completely filled in with a wall that is nothing like it.

THE WHOLE IMAGE IS THAT CONTRAST. The fill is hard, uniform, grey modern brick
in a cement that has not weathered at all: laid plumb, laid dead level, laid
well, by somebody who was paid properly. Cement dust still in the joints. The
old brick weeps; the new brick is bone dry.

Compose so the viewer can see that the joints on the near face are left rough
and unstruck — the finished face is on the other side. Nobody finishes the face
they are walking away from.

Near the springing of the arch, on one brick, a very small cut mark: a
plumb-line crossed by a square, no bigger than a thumbnail. It must be PRESENT
AND FINDABLE but NOT emphasised — no spotlight on it, no shallow-focus hero
shot of it, no composition that points at it. A viewer should be able to find it
on a second look and miss it on the first. It is a mark cut into clay before
firing, not paint and not carving.

No figures. Mood: a door, shut from the far side, by people who have not
finished with it.
```

---

## 10. `grain_stair` — The Grain Stair

**Attach:** none
**Output file:** `grain_stair.png`
*(Use the Undertown STYLE addendum.)*

**Prompt:**

```
SUBJECT — interior, a brick stair rising under Granary Row toward a loading
hatch, lamplight from below and a thin seam of daylight above.

Worn brick steps — worn in the middle, which takes years of traffic — going up
to a heavy timber hatch in the ceiling, closed, with daylight showing as a bright
line around its edge. At the foot of the steps, a landing swept noticeably
cleaner than anywhere else underground: no silt, no sludge, brushed. That
cleanliness is the tell and should be legible as odd.

A barrow with a broken handle stands abandoned at the foot of the stair. Grain
dust hangs in the lamplight and will not settle. A few loose barley corns on the
steps.

No figures. Mood: a great deal of grain has come down here and somebody has been
very careful that it should not look as though it had.
```
