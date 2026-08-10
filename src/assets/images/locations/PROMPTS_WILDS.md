# Location establishing shots — the wilderness tier (Grok prompts)

Covers the twenty-nine new locations of the levels 2–4 wilderness tier: the Ford
Road, Hollow Fen, Arden Priory, and the additions to the Quarry Wilds and the
Goblin Warren.

**This file is a merge candidate, not a replacement.** It follows the format of
`LOCATION_PROMPTS.md` exactly and is meant to be folded into it. Do not append it
to that file by hand while other art docs are outstanding.

Drop finished files here as:

```
src/assets/images/locations/<location_key>.png
```

`location_key` is the authoring key (e.g. `mill_ruin.png`), **not** the pretty
name.

---

## Global style contract (paste at the top of every request)

Identical to `LOCATION_PROMPTS.md`. Reproduced so this file can be used on its
own; it is the same block and must not drift.

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

---

## Portraits — when and how to attach

Same rule and same attachment text as `LOCATION_PROMPTS.md`. Only named permanent
NPCs who *belong* to the place; never ambient townsfolk.

```
Attached image(s) are reference portraits for likeness only (face, hair, outfit,
build, race). Match them closely. Do not copy the black studio background or the
bust crop — put the person into this scene naturally.
```

**No new NPC art is required for this tier.** Every named NPC added here reuses a
bust that already exists on disk, deliberately, so that nothing in the tier can
be a load error. The new names and the busts they wear:

| Who | Bust path |
|---|---|
| Gest Pyle (watch road-relief) | `assets/images/npcs/captain_bust.png` |
| Vessa Dann (pit tally-keeper) | `assets/images/npcs/rogue_bust.png` |
| Orm Lisser (the pit's man) | `assets/images/npcs/prisoner_3_bust.png` |
| Hesk Dray (carrier) | `assets/images/npcs/baker_bust.png` |
| Dorn Ackley (last quarryman) | `assets/images/npcs/miner_bust.png` |
| Sten Harrow (farmer) | `assets/images/npcs/farmer_bust.png` |
| Tull Barrow (drover) | `assets/images/npcs/barbarian_bust.png` |
| Wick Lorrimer (the miller who stayed) | `assets/images/npcs/prisoner_1_bust.png` |
| Joss Frayle (carter, at the ford) | `assets/images/npcs/fighter_bust.png` |
| Nesh Gray (fen warden) | `assets/images/npcs/warlock_bust.png` |
| Cully Tench (fen-runner) | `assets/images/npcs/prisoner_2_bust.png` |
| Gubb Wrayne (sluice-keeper) | `assets/images/npcs/sweeper_bust.png` |
| Ottilie Hale (Prior of Arden) | `assets/images/npcs/cleric_bust.png` |
| Brother Venn (almoner) | `assets/images/npcs/paladin_bust.png` |
| Brother Teodor | `assets/images/npcs/monk_bust.png` |
| Ovid (keeper of the presses) | `assets/images/npcs/wizard_bust.png` |
| Nakka Skarn (existing) | `assets/images/npcs/goblin_warlord_bust.png` |

Only `captain`, `cleric` and `fighter` in that list carry eight expressions; every
other bust has exactly one, and nothing in this tier's dialogue asks for a second.

---

## Batch order (suggested)

The tier's four best shots first — they are the ones a player will remember —
then the rest of each region, then the warren.

1. `the_ford` — the four rectangles
2. `drowned_village` — the roofs
3. `sluice_gate` — the only square corner in the fen
4. `priory_infirmary` — Ottilie Hale
5. `mill_ruin` — Wick Lorrimer
6. `warren_nests`
7. `broken_floor`
8. `drowned_chapel`
9. `stilt_camp` — Nesh Gray
10. `boundary_stone` — Gest Pyle
11. `priory_cloister` — Ottilie Hale
12. `priory_reliquary` — Brother Venn
13. `priory_scriptorium` — Ovid
14. `teodors_cell` — Brother Teodor
15. `pit_undercroft` — Orm Lisser
16. `quarrymans_hut` — Dorn Ackley
17. `harrow_steading` — Sten Harrow
18. `drovers_rest` — Tull Barrow
19. `winter_field`
20. `black_pools`
21. `root_beds`
22. `fen_causeway`
23. `fen_road_head`
24. `ford_lane`
25. `priory_track`
26. `priory_gate`
27. `priory_chapel`
28. `warren_stores`
29. `caravan_ground` — Hesk Dray

---

# Location prompts

For each location: attach portraits listed under **Attach**, paste the **global
style contract**, then paste the block under **Prompt**.

---

## Ford Road — wilderness, west of Rivermark

---

### 1. `ford_lane` — The Ford Lane

**Attach:** none
**Output file:** `ford_lane.png`

**Prompt:**

```
SUBJECT — a hedged country lane running west out of a small trade town,
overcast daylight.

Hawthorn hedges on both sides, high enough to hide a cart. A hand-dug drainage
ditch on one side, cleaned out this spring. Cart-ruts shallow — not much comes
this way any more — with grass beginning in the middle. A worn stone milestone
at a bend, moss scraped off it recently. Small birds in the hedge.

No people. No town visible; the town is behind camera. Mood: the road noise
stops about thirty paces from the gate, faster than it has any right to.
```

---

### 2. `the_ford` — The Ford

**Attach:** `fighter_bust.png` (optional — Joss Frayle; the default shot should
work without him)
**Output file:** `the_ford.png`

**Prompt:**

```
SUBJECT — a wide shallow river crossing over a bed of deliberately laid stone,
overcast, mid-morning.

Near bank: a stone mounting-block, a rope stay for horses, and a flat grey slab
standing on end that is plainly not a milestone — no cutting on it at all, with
several hundred small river-stones piled against its foot, one at a time, over
years.

Far bank, and this is the point of the shot: FOUR long rectangles of bare earth,
laid out square, in a row, set back from the water. Grass grows right up to their
edges and stops. Nothing has grown in them in four years. Do not show ruins,
timbers or burnt structures — only the bare rectangular ground.

OPTIONAL (only if portrait attached) — Joss Frayle, a big carter in working
leathers, crouched at the standing stone placing one river-stone against its
foot. Not looking at camera. Four minutes of a man's year.

Mood: everybody crosses here at a trot and waters upstream.
```

---

### 3. `mill_ruin` — The Burnt Mill

**Attach:** `prisoner_1_bust.png` (optional — Wick Lorrimer)
**Output file:** `mill_ruin.png`

**Prompt:**

```
SUBJECT — a burnt-out stone watermill beside a working mill race, overcast day.

Three stone walls standing, the fourth gone. Roof collapsed INWARD, not outward
— charred beam-ends protruding from the wall at floor level, all of them angled
inward. Wheel-pit full of black standing water; the wheel itself gone or
skeletal. Scorching worst high on the river-side wall.

The mill race itself runs clean and fast and perfectly maintained, cut too well
to silt, turning nothing. That contrast is the shot.

Small domestic signs of habitation, understated: a cooking pot on a flat stone
by the wheel-pit, a swept corner under the one sound gable, a path worn from the
corner to the race.

OPTIONAL (only if portrait attached) — Wick Lorrimer, a gaunt weathered man in
his fifties, standing in the race up to his knees with a long weed-hook, working.

Mood: the only thing here still doing its job is the water.
```

---

### 4. `priory_track` — The Priory Track

**Attach:** none
**Output file:** `priory_track.png`

**Prompt:**

```
SUBJECT — an old stone-flagged processional track climbing away from a river
between overgrown hedges, late afternoon.

Flagstones cracked down the middle where carts have run and sound at the edges.
Bramble has taken the hedges and has not been cut back this year. A waist-high
cross-stone at the verge with the head snapped off at the shoulder — an old
break, weathered. Ground voided under a flag or two.

No people. A suggestion of buildings uphill in the distance, small, not the
subject. Mood: somebody walks this in both directions regularly and does not
clear the brambles.
```

---

### 5. `harrow_steading` — Harrow Steading

**Attach:** `farmer_bust.png`
**Output file:** `harrow_steading.png`

**Prompt:**

```
SUBJECT — a long low working farmstead and its yard, early evening.

Farmhouse, byre, walled yard. Everything done twice: ditches clear, gates hanging
true, muck-heap squared off, yard draining properly. A chained working dog,
standing, alert, not barking. Under the porch a small table with an oil lamp on
it, trimmed and unlit.

KEY NPC (required) — Sten Harrow, farmer.
Match the attached portrait: a weathered practical man, third of his name on this
ground. Standing in the middle of the yard rather than at the gate, wiping his
hands on a cloth, deciding about the visitor. Not posing.

No other people. Mood: good ground, well kept, and a lamp for reading letters
that the house is not big enough to read in.
```

---

### 6. `drovers_rest` — The Drovers' Rest

**Attach:** `barbarian_bust.png` (optional — Tull Barrow)
**Output file:** `drovers_rest.png`

**Prompt:**

```
SUBJECT — a walled cattle fold and roadside stopping place at dusk, where a
drove track meets a road.

Dry-stone fold big enough for sixty beasts, cattle inside settling. Stone water
trough fed by a spring and overflowing steadily. A lean-to with a fire-back. A
pole for hanging a lantern. THREE separate small fires along the fold wall, well
apart, none of them talking to each other — that separation is the mood.

Working dogs patrolling the fold edge. It is not an inn and must not read as one:
no sign, no door, no innkeeper.

OPTIONAL (only if portrait attached) — Tull Barrow, a big drover at the third
fire with a six-foot iron-ferruled ash staff across his knees.

Mood: cattle-smell, woodsmoke, and everybody keeping their own counsel.
```

---

### 7. `winter_field` — Winter Field

**Attach:** none
**Output file:** `winter_field.png`

**Prompt:**

```
SUBJECT — two hundred acres of low common grazing at the edge of a fen, grey
windy afternoon.

Half the field standing in water in the ruts; the drier half grazed too hard and
short. Along the far side, uncut thorn brakes, dense and dark, unmanaged for
years. There is a channel dragged through the grass into the thorn.

The cattle — and this is the shot — are ALL bunched in one far corner, facing the
thorn, not eating. Read them at distance, small in frame.

No people. Wind off the fen with nothing to break it. Mood: the herd has decided
something and the field has not been told.
```

---

### 8. `fen_road_head` — The Fen Road Head

**Attach:** none
**Output file:** `fen_road_head.png`

**Prompt:**

```
SUBJECT — the last dry ground before a fen, where a road becomes a causeway,
early morning with mist standing at head height.

The road stops being a road: rutted earth gives way to a raised way of bound
faggot bundles, stone and driven stakes running out west into water. The first
hundred yards of it have been recently and competently mended — new pale faggots
bedded properly among weathered ones.

A stout post at the transition with a plain horn hanging on a chain, for calling
across. The chain is bright where the horn has been lifted many times.

No people. Mist lies flat at head height and goes no higher. Mood: west of here
the country is water pretending to be land.
```

---

## Hollow Fen — wilderness, west of the ford road

---

### 9. `fen_causeway` — The Fen Causeway

**Attach:** none
**Output file:** `fen_causeway.png`

**Prompt:**

```
SUBJECT — a narrow raised causeway of faggot, stone and driven stakes running
dead straight across open standing water, flat grey light, mist ahead.

Three feet wide in the good stretches. Marker withies pushed into the water on
both sides every ten paces — and, importantly, a stretch where the causeway has
gone under and the withies are the only thing telling you the road is still down
there. Water absolutely still, about four feet deep, bottom visible.

No people, no boats. Mood: it runs straight ahead until the mist takes it, and
it is not wide enough to turn a cart on.
```

---

### 10. `drowned_village` — The Drowned Village

**Attach:** none
**Output file:** `drowned_village.png`

**Prompt:**

```
SUBJECT — eleven roofs of a stone-and-timber village standing out of four feet
of still water, in two rows with a street between them. Flat grey light.

Thatch entirely gone; rafters intact and still pegged. Doorways standing open at
knee height above the waterline, water passing in and out. The street between the
rows is still legible because the water over it is clearer.

CRITICAL — this must read as TIDY, not as a disaster. No furniture, no floating
debris, no pots, no doors off hinges, no collapse. Every house was emptied
properly, in order, with time to spare, before the water came. That wrongness is
the entire shot.

No people. Reeds at the frame edges. Mood: it is not a ruin. It was packed up.
```

---

### 11. `drowned_chapel` — The Drowned Chapel

**Attach:** none
**Output file:** `drowned_chapel.png`

**Prompt:**

```
SUBJECT — interior of a small stone-vaulted chapel with three feet of still black
water standing in the nave, dim, lit only from a doorway behind camera.

Barrel or ribbed stone vault. Water flat and unbroken, black, reflecting the
vault. A chancel with steps rising out of the water — the water stops a hand's
width below the THIRD step and there is a clear, clean, decades-old waterline on
the wall at exactly that height.

On the FOURTH step, dry: a single small iron-bound wooden chest. It has been there
forty years and the step under it has never been wet.

No people, no creature visible. Cold, silent, absolutely still. Mood: somebody
worked out where the water would stand before they carried the chest up there.
```

---

### 12. `black_pools` — The Black Pools

**Attach:** none
**Output file:** `black_pools.png`

**Prompt:**

```
SUBJECT — flooded hundred-year-old peat cuttings forming a chain of deep pools,
overcast, no wind.

Water the colour of strong tea, then abruptly black where the bottom drops away —
show the colour change happening in the space of a stride at a cut edge. Sedge
islands between the pools, unstable-looking. A slow line of gas bubbles rising
through the peat and stopping.

Absolutely still surface. Nothing floating in frame — no bodies, no figures; the
implication is enough and the quest supplies the rest.

No people. Mood: peat water does not let go of what it takes.
```

---

### 13. `stilt_camp` — The Stilt Camp

**Attach:** `warlock_bust.png`
**Output file:** `stilt_camp.png`

**Prompt:**

```
SUBJECT — a hidden fen camp of six timber platforms on driven piles, four feet
above still water, joined by loose plank walks. Out of sight of any road. Grey
wet daylight.

Flat-bottomed punts moored underneath. Drying nets that have plainly never held a
fish. A stack of new bound faggot bundles far larger than this camp could ever
burn — that stack is road-mending material and should read as the biggest object
in the scene after the platforms. Everything built to be pulled down fast: no
nails where a lashing would do.

KEY NPC (required) — Nesh Gray, fen warden.
Match the attached portrait: a woman of about fifty, hooded against the wet, one
hand resting on a plank walk that is not fastened down. Above the camera, looking
down. Watchful, unhurried, unarmed in frame.

No other clear faces — one or two figures at the edges, backs turned. Mood: they
watched you come the whole way in.
```

---

### 14. `root_beds` — The Root Beds

**Attach:** none
**Output file:** `root_beds.png`

**Prompt:**

```
SUBJECT — a shallow flooded flat in a fen, cultivated: four rectangular beds of
pale jointed water-root with straight edges and kept channels between them.

Three beds cut close and even this season. The fourth uncut, indistinguishable
otherwise, with a plain wooden stake at each of its four corners.

A planked working stage at the edge to cut from, pegged not nailed, with a
long-handled cutting hook laid on it. Channels between beds running clear because
they have been kept open by hand for two generations.

No people. Mood: somebody built this, in the middle of a wilderness, and is still
keeping it.
```

---

### 15. `sluice_gate` — The Sluice

**Attach:** `sweeper_bust.png`
**Output file:** `sluice_gate.png`

**Prompt:**

```
SUBJECT — a masonry sluice at the outfall of a fen: two dressed stone piers, a
paved apron, and a heavy oak gate in an iron frame, raised.

This is the first square corner in the whole region and it must look it: dressed
stone, true angles, competent forty-year-old engineering. A screw-jack and a
counterweight of cut quarry block with lifting-holes in it. The ironwork is
GREASED — clean grease, done this month. Water passes through the raised gate
steadily and almost silently.

A small stone keeper's hut on the west pier with a chimney that is drawing;
smoke goes straight up, there is no wind down in the cut.

KEY NPC (required) — Gubb Wrayne, sluice-keeper.
Match the attached portrait: a small old man on the apron with a grease-pot and a
long rake-hook, working along the frame. Doing a job he has done two thousand
times.

Mood: maintained, not repaired. Somebody has been paying for this since before
anyone here was born.
```

---

## Arden Priory — a small house of the Sun's Hour, above the ford

---

### 16. `priory_gate` — The Priory Gate

**Attach:** none
**Output file:** `priory_gate.png`

**Prompt:**

```
SUBJECT — the gatehouse of a small, poor monastic house, exterior, overcast.

Good old stonework built for a community of forty. In the arch, a NEW pale pine
door, plainly cut to fit an opening it was never made for and matching nothing
around it. A stone bench in the shadow of the wall, worn. A bell-rope with a
smooth patch at exactly one height. An alms slot in the wall stopped from the
inside with a twist of rag.

Through the arch, a glimpse of grass and a covered walk. One robed figure
crossing that gap far back, small, not looking this way.

Mood: dignified poverty. Nothing here is a ruin and nothing here is spare.
```

---

### 17. `priory_cloister` — The Cloister

**Attach:** `cleric_bust.png`
**Output file:** `priory_cloister.png`

**Prompt:**

```
SUBJECT — a small monastic cloister: a square of grass with a covered walk round
it, daylight.

ONE arcade glazed with mismatched salvaged glass; the other three open to the
weather. Rain standing in the flags of the unglazed walk in the same three
places it always stands. Herb beds weeded right to the edge — all of them useful
plants, nothing ornamental. A besom leaning where somebody is part-way through
sweeping the north walk.

Two or three robed brothers crossing at different points, unhurried, faces not
readable, not reacting to camera.

KEY NPC (required) — Ottilie Hale, Prior.
Match the attached portrait: a middle-aged woman in plain habit with a basket of
something practical under one arm, stopped mid-crossing, facing camera squarely
rather than obliquely. She runs a hospice and calls it the whole of the rule.

Mood: eleven men, a bell, and an hour of the day.
```

---

### 18. `priory_chapel` — The Chapel

**Attach:** none
**Output file:** `priory_chapel.png`

**Prompt:**

```
SUBJECT — interior of a plain, severe monastic chapel, daylight from high
windows.

Whitewash. Stone floor worn into TWO visible tracks, door to altar and back.
Backless benches. Over the altar a sun-disc cut from a single piece of pale wood
by somebody who could not carve and did it anyway — clumsy, and kept very clean.

On the east wall, FOUR iron brackets at eye height: three empty, one holding a
small reliquary. The peg-holes where a fourth bracket was moved have not been
filled. That detail matters.

One figure on the back bench, seen from behind, sitting the hour out rather than
praying.

Mood: austerity that is about money as much as about discipline.
```

---

### 19. `priory_reliquary` — The Reliquary

**Attach:** `paladin_bust.png`
**Output file:** `priory_reliquary.png`

**Prompt:**

```
SUBJECT — a tiny barrel-vaulted stone strongroom off a chapel, lamp-lit, cold.

An iron grille instead of a window, recently re-barred from the inside. Shelves
cut directly into the stone: SEVENTEEN shelf-spaces, NINE small reliquary caskets
standing in them. In eight of the empty spaces, a small plain card stands upright
where the casket used to be. (No readable writing — the cards read as documents
at a glance and nothing more.)

One of the remaining nine has been shifted along to close a gap and the dust
shows it.

KEY NPC (required) — Brother Venn, almoner.
Match the attached portrait: a careful, tired man in habit holding an oil lamp,
standing in front of the empty spaces the way other men stand in front of a fire.
He is not hiding the lamp.

Mood: he is not a thief and he has made very sure the record says so.
```

---

### 20. `priory_scriptorium` — The Writing Room

**Attach:** `wizard_bust.png`
**Output file:** `priory_scriptorium.png`

**Prompt:**

```
SUBJECT — a monastic writing room: four desks under the best window in the
building, and a wall of document presses.

Three desks stacked with ordinary paper — leases, rolls, letters. One kept
completely clear. Along the north wall, the cartulary presses: bound volumes by
decade, in order, DUSTED, while everything else in the building is not. One press
open at a volume with a strip of leather laid in as a marker. A fire banked
deliberately low — the room is warm for the paper, not for the people.

Oak-gall ink, iron, cold stone.

KEY NPC (required) — Ovid, keeper of the presses.
Match the attached portrait: an old woman in habit at the one clear desk, pen in
hand, finishing the line she is on rather than looking up.

Mood: a hundred and forty years, complete, and nobody has asked for it twice in
eleven years.
```

---

### 21. `priory_infirmary` — The Infirmary

**Attach:** `cleric_bust.png` (optional — Ottilie Hale)
**Output file:** `priory_infirmary.png`

**Prompt:**

```
SUBJECT — the long infirmary hall of a small monastic hospice, warm, daytime.

TWELVE beds down one wall. SIX made up with clean linen; the other six stripped
to the frame and ready. A hearth kept in at BOTH ends of the room, with nobody
standing at either. A door in the long wall going straight out to the yard, so
that nobody has to be carried through the cloister — the most thought-about
detail in the building.

One robed brother mid-way through turning a patient, gently, competently, not
looking up. Patients indistinct, faces away or in shadow.

OPTIONAL (only if portrait attached) — Ottilie Hale in the doorway, watching the
room rather than the camera.

Mood: this is what the whole house is actually for. Everything else is the
arrangement that keeps this room open.
```

---

### 22. `teodors_cell` — The End Cell

**Attach:** `monk_bust.png`
**Output file:** `teodors_cell.png`

**Prompt:**

```
SUBJECT — a small monastic sick-cell off an infirmary, shuttered dim light.

A bed, a single stool, a shelf, and a wooden shutter open exactly a hand's width
and clearly set there and not moved for a long time. On the shelf, a cup that is
full. A writing-board propped against the wall with a half-written sheet clipped
to it; the ink is obviously long dry.

Door ajar to a warmer, brighter room beyond — the infirmary carrying on without
him.

KEY NPC (required) — Brother Teodor.
Match the attached portrait: a very old man in a plain habit, propped in the bed,
entirely awake, hands folded. Not frail-picturesque; alert and finished.

No readable text on the sheet. Mood: eleven starts and no finish.
```

---

## Quarry Wilds — additions

---

### 23. `boundary_stone` — The Mearing Stone

**Attach:** `captain_bust.png` (optional — Gest Pyle)
**Output file:** `boundary_stone.png`

**Prompt:**

```
SUBJECT — a waist-high upright grey boundary stone on an open windswept ridge
where four holdings meet, overcast.

The stone has four faces. THREE are weathered nearly smooth, their old carved
marks barely legible. The FOURTH has been dressed back completely flat and cut
fresh this year — sharp, clean, unmistakably recent professional mason's work.
Fresh pale stone-dust in the grass on that side.

Do NOT render any legible symbol or letter on the new face. Keep it in shadow, at
an oblique angle, or partly out of frame. The sharpness of the cutting is the
subject; what it says is not.

The base is polished smooth where sheep have rubbed against it for a century.
Long empty view of hills.

OPTIONAL (only if portrait attached) — Gest Pyle, a watchman with a satchel
strapped twice over one shoulder, standing with his hand flat on one of the OLD
faces.

Mood: nobody re-cuts a mearing stone.
```

---

### 24. `quarrymans_hut` — The Cottage on the Spoil

**Attach:** `miner_bust.png`
**Output file:** `quarrymans_hut.png`

**Prompt:**

```
SUBJECT — a one-room cottage and lean-to built out of quarry offcuts on top of a
spoil heap, facing AWAY from the quarry. Overcast.

Cut stone offcuts laid dry without mortar, fitted better than most masons manage.
The door and the single window face north, away from a quarry that is behind the
building. A bench outside on that far side — the wrong side of the house — with
the grass in front of it worn down to bare dirt. In the lean-to, a pick standing
in the corner with the head wrapped in sacking.

The quarry itself must be implied only: a spoil slope falling away behind the
roof, no pit visible.

KEY NPC (required) — Dorn Ackley, quarryman.
Match the attached portrait: a weathered man in his fifties sitting on the bench
facing north at nothing in particular, back to the quarry, back to camera-right.

Mood: eleven years of arranging a life so as not to turn round.
```

---

### 25. `caravan_ground` — The Caravan Ground

**Attach:** `baker_bust.png` (optional — Hesk Dray)
**Output file:** `caravan_ground.png`

**Prompt:**

```
SUBJECT — a rammed-chalk hard-standing beside a road, big enough to turn six
wagons on, at dusk.

A well, a picket line of draught animals, and a stone fire-ring the size of a
cart wheel. TWO covered wagons standing with the sheets LACED DOWN through
eyelets rather than roped — the lacing should be visible and read as slow to
open. The fire is small for the number of men and is at the wrong end of the
ground; the men are camped BETWEEN the wagons and the road, not around it.

One man greasing a wheel in the dark by feel, without a lamp.

OPTIONAL (only if portrait attached) — Hesk Dray, a heavy man of about fifty in
an apron-coat, standing between camera and the nearer wagon.

Mood: twenty years without a complaint, and paid in advance since the spring.
```

---

### 26. `pit_undercroft` — The Undercroft

**Attach:** `prisoner_3_bust.png`
**Output file:** `pit_undercroft.png`

**Prompt:**

```
SUBJECT — a low worked-stone gallery behind an arena gate, lit by a few lamps.

Six barred stalls cut into one long wall, a drain running down the middle of the
floor and out under the far wall. FOUR of the stalls hold animals (indistinct, in
shadow, no clear monster design). TWO of them hold beds — made up, with a chest at
the foot of one and a heavy leather belt hanging on a peg.

Above, gate-boards with a thin steady line of sand falling through them when the
crowd moves. None of the stall doors are locked and none of them have chains.

KEY NPC (required) — Orm Lisser, the pit's man.
Match the attached portrait: a big tired scarred man of about thirty-five sitting
on the nearer bed with a whetstone he is not using.

Mood: the door is open. It has been open for fourteen months.
```

---

## Goblin Warren — additions

**Note on tone.** The warren is not a lair in this tier. Three shipped quests
re-frame the goblins as displaced rather than hostile, and the art has to carry
that: these are crowded, cold, organised, provisioned places, not dens.

---

### 27. `warren_nests` — The Nest Ledges

**Attach:** none
**Output file:** `warren_nests.png`

**Prompt:**

```
SUBJECT — two stacked stone galleries in an old quarry working, cut originally
for spoil, now used entirely for bedding. Warm low firelight from further in.

Every foot of both galleries is bedding: hide, sacking, straw and stripped cart-
canvas, laid out in ORDERED BAYS with a hand's width of walkway between them. The
bays are small. There are a very great many of them.

This must read as crowded domestic shelter — a refugee gallery — and not as a
lair. No bones, no trophies, no filth, no weapons. Neatness under pressure.

No clear goblin figures in frame: at most one or two small indistinct shapes deep
in shadow at the far end. Warm, used air; the suggestion of milk and smoke.

Mood: the noise here is the wrong noise for a warren.
```

---

### 28. `warren_stores` — The Stores

**Attach:** none
**Output file:** `warren_stores.png`

**Prompt:**

```
SUBJECT — a dry cut-stone store room in an old quarry, lamp-lit, with a heavy
timber door that still hangs true.

Sacked salt stacked off the floor on wooden battens, properly, by somebody who
was taught how. Bundles of unworked BAR IRON tied with wire, in tens. Cut timber
stacked.

Along the back wall, and this is the shot: a row of CRATES that plainly did not
come off any burned wagon — planed pine, square, clean pale straw packing spilling
from one, opened carefully from the top rather than smashed. One crate shows three
small notches cut into a batten and a scar across the boards where strapping has
bitten and been cut away.

No people. Mood: this is a provisioned store, not loot.
```

---

### 29. `broken_floor` — The Broken Floor

**Attach:** none
**Output file:** `broken_floor.png`

**Prompt:**

```
SUBJECT — the lowest gallery of a quarry warren, with a hole thirty feet across
where the floor has gone into a void. Lamp and torch light only.

CRITICAL — the rim of the hole is CUT, not broken: dressed square edges all the
way round, no shelf, no bedding-plane fracture, no rubble apron. Stone that falls
does not fall square. It must read as having been taken out from underneath and
upward.

Eleven quarry-timber props holding up the remaining floor — well set, competent,
and visibly not enough of them. A rope over the edge. Warm air moving up out of
the void, suggested by dust drift rather than by any glow.

No people, no creatures. Mood: a shell of worked stone over a great deal of
nothing, and the nothing has an opinion.
```

---

## After generation — drop-in checklist

| File | Location |
|---|---|
| `ford_lane.png` | The Ford Lane |
| `the_ford.png` | The Ford |
| `mill_ruin.png` | The Burnt Mill |
| `priory_track.png` | The Priory Track |
| `harrow_steading.png` | Harrow Steading |
| `drovers_rest.png` | The Drovers' Rest |
| `winter_field.png` | Winter Field |
| `fen_road_head.png` | The Fen Road Head |
| `fen_causeway.png` | The Fen Causeway |
| `drowned_village.png` | The Drowned Village |
| `drowned_chapel.png` | The Drowned Chapel |
| `black_pools.png` | The Black Pools |
| `stilt_camp.png` | The Stilt Camp |
| `root_beds.png` | The Root Beds |
| `sluice_gate.png` | The Sluice |
| `priory_gate.png` | The Priory Gate |
| `priory_cloister.png` | The Cloister |
| `priory_chapel.png` | The Chapel |
| `priory_reliquary.png` | The Reliquary |
| `priory_scriptorium.png` | The Writing Room |
| `priory_infirmary.png` | The Infirmary |
| `teodors_cell.png` | The End Cell |
| `boundary_stone.png` | The Mearing Stone |
| `quarrymans_hut.png` | The Cottage on the Spoil |
| `caravan_ground.png` | The Caravan Ground |
| `pit_undercroft.png` | The Undercroft |
| `warren_nests.png` | The Nest Ledges |
| `warren_stores.png` | The Stores |
| `broken_floor.png` | The Broken Floor |

Path for all:

```
/home/richard/code/rpg/src/assets/images/locations/
```

The game loads these automatically from `assets/images/locations/<key>.png`
(see `locationArt()` in `assets/js/game.js`). Missing files remove themselves on
error, so a place without art just shows prose.

---

## Monster art — no new sheets needed

Eight new monsters ship with this tier and every one of them wears a sheet that is
already on disk, in the manner of the three shipped undead reskins. Recorded here
so the merge into `assets/images/monsters/MONSTER_PROMPTS.md` has the list:

| `monster_key` | `sprite_key` | Note |
|---|---|---|
| `dire_wolf` | `dire_wolf` | own sheet |
| `fen_howler` | `howler` | own sheet |
| `bog_crawler` | `crawler` | own sheet |
| `peat_ooze` | `ooze` | own sheet |
| `fen_horror` | `tentacled` | own sheet |
| `deep_gremlin` | `gremlin` | own sheet |
| `drowned_man` | `boss_drowned` | **reskin** — the packs ship no undead |
| `pit_champion` | `bandit` | **reskin** — the packs ship no arena fighter |
