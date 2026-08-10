# Location establishing shots — the Deepworks and Greyhythe

Companion file to `LOCATION_PROMPTS.md`, same workflow, same global style
contract. **Do not merge this into that file by hand** — three agents wrote art
docs on 2026-08-01 and the merge is done centrally.

Covers the two act-finale regions:

| Region | `region_key` | Locations |
|---|---|---|
| The Deepworks | `the_deepworks` | 8 |
| Greyhythe | `greyhythe` | 8 |

Plus new named NPCs (busts/faces) and new monsters at the end.

Drop finished files here as:

```
src/assets/images/locations/<location_key>.png
src/assets/images/npcs/<sprite_key>_face.png / _bust.png / _sheet.png
src/assets/images/monsters/<sprite_key>*.png
```

---

## Global style contract (paste at the top of every request)

Identical to `LOCATION_PROMPTS.md`. Reproduced so this file can be used alone.

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

## The one visual rule these two regions live or die on

**The Deepworks must not look like a dungeon.** It is a Victorian-industrial
work site that happens to be underground: sawn timber, numbered props, swept
floors, trimmed lamps, a canteen, a bell. Every instinct the model has toward
"cave", "lair", "ruin", "bones", "cobwebs", "glowing crystals" is wrong. Where
you can only pick one adjective, pick **orderly**.

**Greyhythe must not look sinister.** It is a well-kept, prosperous, entirely
legal river port. The horror is that it is nicer than Rivermark. No fog, no
crows, no shuttered windows, no menace in the light.

**The mark.** A plumb-bob hanging inside a set-square, stamped or painted in
black. It appears on crates, notes and stone throughout both regions. It is
**a geometric shape, not a letterform** — a small dark square outline with a
teardrop-shaped weight hanging on a line inside it. Requesting it is optional
(the "no text" rule makes it risky); where it is worth trying, the phrasing that
works is *"a small painted geometric maker's mark — a plumbline weight inside a
square outline — stencilled in black on the crate ends. No letters, no numbers."*

---

## Portraits — when and how to attach

Same instruction block as `LOCATION_PROMPTS.md`:

```
Attached image(s) are reference portraits for likeness only (face, hair, outfit,
build, race). Match them closely. Do not copy the black studio background or the
bust crop — put the person into this scene naturally.
```

| Who | Bust path | Belongs to |
|---|---|---|
| Alder Pyke (chainman) | `assets/images/npcs/miner_bust.png` | `granary_row` (Rivermark, existing shot) |
| Wenna Coyle (timekeeper) | `assets/images/npcs/warlock_bust.png` | `timekeepers_hut` |
| Bael Rourke (overseer) | `assets/images/npcs/captain_bust.png` | `overseers_office` |
| Corrin Dale (indentured digger) | `assets/images/npcs/prisoner_2_bust.png` | `deepworks_canteen` |
| Fessick (face-man) | `assets/images/npcs/prisoner_1_bust.png` | `the_undercut` |
| Gillian Petch (landing clerk) | `assets/images/npcs/sweeper_bust.png` | `corse_landing` |
| Osgar Tull (bargemaster) | `assets/images/npcs/barbarian_bust.png` | `packet_stair` |
| Ludo Corse (factor) | `assets/images/npcs/rogue_bust.png` | `factors_house` |

---

## Batch order (suggested)

Deepworks first — it is the more distinctive look and the harder brief, so get
the language right there and Greyhythe follows easily.

1. `muster_yard` — the whole thesis of the region in one shot
2. `the_headings`
3. `deepworks_canteen` — Corrin Dale
4. `timekeepers_hut` — Wenna Coyle
5. `spoil_stair`
6. `overseers_office` — Bael Rourke
7. `pump_gallery`
8. `the_undercut` — Fessick
9. `corse_landing` — Gillian Petch
10. `the_hythe`
11. `factors_house` — Ludo Corse
12. `the_long_room`
13. `tally_shed`
14. `packet_stair` — Osgar Tull
15. `towpath_end`
16. `salt_house`

---

# The Deepworks — location prompts

---

## 1. `spoil_stair` — The Spoil Stair

**Attach:** none
**Output file:** `spoil_stair.png`

**Prompt:**

```
SUBJECT — a newly built timber staircase descending through a broken rock floor
into a deep working, lit by hanging lamps.

Not a cave mouth and not a ruin: a properly engineered stair. Sawn pale treads,
a planed handrail, iron pins with a guide rope, a landing every twenty feet,
and a wooden bucket of sand standing on each landing against fire. Above, the
raw broken rock and shored ceiling of an older tunnel; below, warm lamplight
going down further than the eye follows, and the faint suggestion of a very
large lit space at the bottom. Fresh sawdust on the treads. New timber, still
pale, against three-hundred-year-old quarry stone.

No people, no bones, no cobwebs, no glowing crystals, no fantasy ruin dressing.

Mood: someone carried all of this down here a plank at a time, and someone else
signed for it.
```

---

## 2. `muster_yard` — The Muster Yard

**Attach:** none
**Output file:** `muster_yard.png`

**Prompt:**

```
SUBJECT — a large underground plank-floored yard the size of a market square,
roofed with heavy timber props and packed rubble, lit by trimmed oil lamps.

An industrial mustering floor, not a cavern. Swept board floor. A brass bell on
an iron bracket. A tall wooden board hung with a hundred small numbered pegs,
most of them turned. Two long benches. A water butt with a tin cup on a chain.
A single painted white line across the boards that nobody crosses without being
counted. Wheelbarrows in a rank against one wall. Everything squared, tidied,
maintained.

A few workers in the middle distance, backs to camera, walking with purpose —
grimy canvas and wool, no armour, no weapons. Faces not readable.

Mood: a factory floor that happens to be four hundred feet underground. Order,
routine, and absolutely no drama.
```

---

## 3. `timekeepers_hut` — The Timekeeper's Hut

**Attach:** `warlock_bust.png`
**Output file:** `timekeepers_hut.png`

**Prompt:**

```
SUBJECT — interior of a small plank-built office hut inside an underground
works, warm, dry and much better lit than anything around it.

A wooden box of a room with one large window looking out over the plank muster
floor beyond. Inside: a brass-and-glass water clock, a bell rope through the
roof, a rack of wooden tally sticks on hooks, four heavy bound ledgers, a
writing desk, a small stove. Everything squared to the edges of the desk. The
window is deliberately positioned to watch the floor outside.

KEY NPC (required) — Wenna Coyle, Timekeeper.
Match the attached portrait for likeness. Reinterpret the costume as a practical
dark hooded oilskin over plain working clothes, not ceremonial robes — she
dresses against the drip, not for effect. Seated at the desk mid-line, pen down
on the paper, looking up. Dry, exact, entirely unhurried.

No other people in the room; figures beyond the window are distant and blurred.

Mood: the most powerful person in the works is the one holding the pen.
```

---

## 4. `deepworks_canteen` — The Canteen

**Attach:** `prisoner_2_bust.png`
**Output file:** `deepworks_canteen.png`

**Prompt:**

```
SUBJECT — an underground canteen at shift change: long trestle tables, benches,
two great copper vats of soup, lamps, steam.

Crowded and loud and utterly ordinary. Sixty working men and women eating fast
at trestles, coats steaming, bowls and hunks of bread. Two big copper coppers on
a brick stand at the end. A slate price board on the wall with two columns of
chalked marks — MARKS AND STROKES ONLY, no readable letters or numbers. Sawdust
on the floor. One man asleep sitting upright and left where he is.

KEY NPC (optional, if portrait attached) — Corrin Dale, an indentured digger.
Match the attached portrait: dark hair, beard, filthy hard-worn work clothes,
mid-life, tired but not broken. Seated at the end of the nearest bench, shifting
along to make room, bread in hand.

Crowd faces should be soft/turned — no gallery of invented portraits.

Mood: warm, fed, exhausted, and nobody looking up. This is the least frightening
room in the region and that is the point.
```

---

## 5. `the_headings` — The Headings

**Attach:** none
**Output file:** `the_headings.png`

**Prompt:**

```
SUBJECT — six rock faces being cut at once in a large timbered underground
working, seen down the length of the gallery.

Industrial mining, mid-nineteenth-century in feel but with hand tools only. Each
face has its own hanging lamp, its own barrow run of laid plank, and its own
number painted on the prop beside it (a painted numeral is acceptable here if it
reads as a single stencilled figure; otherwise a painted stripe). Ranks of
squared timber props and headtrees. Barrows, picks, shovels racked. Lamp black
settled evenly over everything like weather. Dozens of workers, small in the
frame, all doing the same motion.

The gallery goes back further than the lamps do.

No monsters, no glowing anything, no cathedral cavern. Ceiling low and propped.

Mood: enormous, regular, costed. Pick, pick, pick, and a barrow, and pick again.
```

---

## 6. `pump_gallery` — The Pump Gallery

**Attach:** none
**Output file:** `pump_gallery.png`

**Prompt:**

```
SUBJECT — an underground pumping chamber: three great chain-pumps turning, a
wooden launder carrying grey water away into darkness.

Wet, loud, mechanical. Three chain-and-bucket pumps in timber housings, chains
rising into the roof toward unseen gearing above. A long open wooden trough (a
launder) running the length of the room carrying grey water into a black
tunnel. Standing water underfoot, ankle deep in places, planks laid as walkways.
Lamps in dented wire cages, not open flames. Green slime at the waterline. Where
the launder leaks, the stonework is stained and something has been scraping at
it.

Wooden staves stacked by the door, used and left. No people.

Mood: the only room down here nobody has tidied. The works would be a lake
without it, and the crews carry sticks and do not talk about why.
```

---

## 7. `overseers_office` — The Overseer's Office

**Attach:** `captain_bust.png`
**Output file:** `overseers_office.png`

**Prompt:**

```
SUBJECT — interior of a plank hut raised on a platform above an underground
working, with a wide shutter thrown open onto the whole works below.

One room on stilts, reached by a ladder through a floor hatch. A plain desk with
sections and rolled drawings on it. An iron-bound strongbox bolted through the
floorboards. A shelf of bound volumes and an open rack of rolled paper beside it
— the rack conspicuously unsecured. A good wool coat on a nail and a good hat
above it, both clean, both never worn underground. Through the open shutter:
lamplit galleries, barrow runs and small distant figures working.

KEY NPC (required) — Bael Rourke, Overseer of the Works.
Match the attached portrait for build and face: large, bearded, physically
formidable. Reinterpret the costume as heavy working leathers, canvas and a
tally in his hand — NOT armour, NOT a warrior. Rock dust grey in the beard.
Leaning on the sill with one boot up, watching a barrow run, mid-complaint.

Mood: a man who came up through the faces and is proud of a bucket of sand.
```

---

## 8. `the_undercut` — The Undercut

**Attach:** `prisoner_1_bust.png`
**Output file:** `the_undercut.png`

**Prompt:**

```
SUBJECT — the flooded floor of an old collapsed quarry working, deep
underground, with one rock face that has been cut at very carefully by hand.

Older and rougher than the rest of the works: no new timber, no numbered props.
Fallen quarry stone in heaps, three years of black standing water in the bottom,
four lamps set on ledges. One worked face at the far end, and the stone of that
face is subtly the WRONG COLOUR — paler, waxier, faintly organic in grain,
distinct from the grey rock around it. Hand tools laid out in a row on a ledge,
sharpened, in order of size. No picks, no powder, no barrows. A chalk line drawn
across the floor with no footprints past it.

KEY NPC (optional, if portrait attached) — Fessick, an old face-man.
Match the attached portrait: very old, bald, long white beard, gaunt, bare arms.
Seated or standing at the face with one hand flat against the rock, listening,
chisel lowered.

No creature visible. Nothing glowing. The wrongness is entirely in the colour
and grain of one wall.

Mood: the quiet of a room in which something has stopped.
```

---

# Greyhythe — location prompts

---

## 9. `towpath_end` — The Towpath

**Attach:** none
**Output file:** `towpath_end.png`

**Prompt:**

```
SUBJECT — the end of a river towpath where it becomes a cobbled street, at the
edge of a small prosperous river port, overcast afternoon.

A hauling path along a wide slow river, worn grass and mud, ending at a stone
mooring bollard grooved into a deep saddle by generations of rope. Beyond it the
path becomes new-laid cobbles running between low houses. Woodsmoke. A working
horse passing along the path with an unseen load. Gulls. Wet rope and tar in
the air.

No wall, no gate, no guards — the town simply starts.

No named characters. Mood: after the quarry road, this smells like somewhere
that gets things.
```

---

## 10. `the_hythe` — The Hythe

**Attach:** none
**Output file:** `the_hythe.png`

**Prompt:**

```
SUBJECT — an old common wharf on a river bank at low water, half its berths
empty, mid-afternoon.

Four hundred years of use: a shelving stone-and-shingle bank, a row of iron
mooring rings, worn timber staging, one or two small working boats. Fish
baskets, a mended net, crates. Visibly under-used — most of the rings unused,
most of the staging empty, weed at the tideline. Downstream in the distance, a
newer and better-built stone quay with a crane on it, plainly busy.

Two figures mid-ground mending a net, backs mostly turned.

No named characters. Mood: a working quay in the middle of the afternoon with
half its berths empty, which is not a thing a river town does by choice.
```

---

## 11. `salt_house` — The Salt House

**Attach:** none
**Output file:** `salt_house.png`

**Prompt:**

```
SUBJECT — interior of a boarding house converted from an old salt store,
evening.

Thick stone walls with a white salt bloom furring the plaster near the stair,
scraped back at hand height. A common room with a settle by the fire, plain
tables, a stair up to beds. Bargemen and hauliers' gear — coiled line, oilskins,
a tally board. Warm, dry, plain, not picturesque. A pair of boots on the fender.

One or two figures at rest, faces turned away or shadowed.

No named characters. Mood: the beds are dry all year and that is the whole of
its reputation.
```

---

## 12. `corse_landing` — The New Landing

**Attach:** `sweeper_bust.png`
**Output file:** `corse_landing.png`

**Prompt:**

```
SUBJECT — a new private stone quay on a river, with a timber crane and a bonded
warehouse, working briskly on an overcast day.

Forty yards of dressed, squared stone quay — noticeably better built than
anything else in the town. A tall wooden treadwheel or jib crane swinging a
crate. A long stone-and-timber bonded shed behind. A fence of plain iron
standards with a wide gate standing open. Crates and barrels stacked
dead-square in blocks, every crate turned the same way. Six or seven dockers in
decent matched working clothes, unhurried, competent.

Optional (only if attempting): a small painted geometric maker's mark — a
plumbline weight inside a square outline — stencilled in black on the crate
ends. No letters, no numbers.

KEY NPC (required) — Gillian Petch, landing clerk.
Match the attached portrait for likeness: young woman, dark hair pinned under a
scarf. Dress her in a plain good coat a size too grand for her and give her a
tally board under one arm. Mid-stride down the quay, calling a number to the
crane crew, entirely in charge and entirely pleased about it.

Mood: too good a quay for this river. Somebody intends it to be busier.
```

---

## 13. `tally_shed` — The Bonded Shed

**Attach:** none
**Output file:** `tally_shed.png`

**Prompt:**

```
SUBJECT — interior of a large bonded warehouse, cool, dry, and half empty.

Long timber-framed interior, high roof, light in bars from clerestory openings.
Goods in disciplined ranks with wide empty floor between them: salt sacks,
pig iron, ranked lamp-oil casks with the oldest at the front, sawn timber cut to
one length, coiled rope, folded sailcloth, and — dominating one bay — enormous
coils of heavy iron chain, hundreds of yards of it, sweating slightly and wiped
down.

By the door, a plain desk with a large ledger lying open on it and nobody
standing over it.

No people. Mood: nothing here is for sale. All of it is going somewhere.
```

---

## 14. `packet_stair` — The Packet Stair

**Attach:** `barbarian_bust.png`
**Output file:** `packet_stair.png`

**Prompt:**

```
SUBJECT — worn stone river steps going down to the water where a passage boat
puts in, looking downstream.

Wide shallow stone steps, green with weed below the sixth and dry above it. An
iron ring and a bollard with rope neatly coiled on it. A slate sailing board on
a post (marks only, no readable letters). Downstream the river widens, the hills
give out and the sky opens up — the horizon should read as "a great deal of
country you have never seen".

KEY NPC (required) — Osgar Tull, bargemaster.
Match the attached portrait for build and face: a very large, weathered, long-
haired man. Reinterpret the costume as a riverman's heavy coat and jersey, not
tribal or barbarian dress. Sitting on the seventh step with a rope in his hands
he is not doing anything with.

Mood: this is the road out of the act, and it goes on the second and the fifth
whether you are on it or not.
```

---

## 15. `factors_house` — The Factor's House

**Attach:** `rogue_bust.png`
**Output file:** `factors_house.png`

**Prompt:**

```
SUBJECT — the front room of a plain, well-kept merchant's house, with a desk
facing a window that looks out over a busy stone quay.

Deliberately unremarkable and deliberately not cheap: scrubbed boards, plain
panelling, one good rug, a plain clock. A writing desk turned to face the
window, papers squared, an inkstand, a lamp. One chair on the visitor's side of
the desk, plainly the oldest thing in the room, worn through at both arms.
Through the window: the crane and the stacked crates of the new landing,
working.

KEY NPC (required) — Ludo Corse, factor.
Match the attached portrait for face and build. Reinterpret the costume as a
good plain dark coat, no ornament, no hat, ink on the side of one hand — a
merchant's factor, not a duellist or an adventurer. Seated at the desk, pen just
set down, turned toward the room, unhurried and pleasant.

He must not read as a villain. No shadow across the eyes, no smirk, no
theatrical lighting. He should look like the most reasonable man in the county.

Mood: the step is washed and the door is answered.
```

---

## 16. `the_long_room` — The Long Room

**Attach:** none
**Output file:** `the_long_room.png`

**Prompt:**

```
SUBJECT — a long private records room at the back of a merchant's house, walls
of document presses from floor to ceiling, one lamp.

Tall wooden press cabinets on every wall, each with ranks of small labelled
drawers going up past the reach of the light. A long central table with a green
baize cloth, ink-stained at one corner. A brass oil lamp mounted in a gimbal
ring so it can be carried without being set down. A stepladder. Everything
labelled in one hand (illegible at this distance — no readable text).

The door at the near end stands open. There is no lock on it anywhere in shot.

No people. Mood: this room holds everything the house knows, and it is not
locked, and that is worse.
```

---

# New named NPCs — face/bust/sheet requests

All eight new speaking NPCs currently borrow an existing `sprite_key`. That is
deliberate — a `sprite_key` with no art on disk is a hard load error — but every
one of them is sharing a sheet with somebody else in the game, and the two
listed below are the ones a player will actually notice.

## Priority 1 — `corse` (would replace `rogue` on `ludo_corse`)

**Ludo Corse is the antagonist of Act I and currently wears the `rogue` sheet,
which is also on the ambient cutpurse at Rivermark's east gate.** He also has
**eight expression variants written for him in spirit but only one cut**, so
every node in a 40-node dialogue file renders on the same face.

Request: a full set — `corse_sheet.png`, `corse_face.png`, `corse_bust.png` —
plus **8 bust expressions** and an entry in `assets/images/npcs/busts.json`.

```
SUBJECT — bust portrait, black studio background, matching the existing NPC bust
set in style, lighting and crop.

A man of about forty. Neat, unremarkable, expensively plain: a good dark wool
coat with no ornament at all, clean linen, no jewellery, no hat, hair cut short
and tidy. Ink on the side of one hand. Clean-shaven or close-trimmed. Level,
attentive, faintly kind expression — the face of a man who is about to answer
your question completely and at length.

He must NOT look sinister, aristocratic, or dangerous. No scar, no smirk, no
shadowed eyes, no rings. The intended reading is: the most reasonable man you
have ever met, and you cannot work out why that frightens you.

EXPRESSIONS (8, same face, same lighting, same crop):
1 neutral attentive (default)
2 warm, mildly amused
3 listening — head slightly tilted, waiting
4 explaining — mid-sentence, open, unhurried
5 regretful — genuinely sorry, no defensiveness
6 declining — the single mildest possible refusal
7 writing something down about you
8 completely unreadable
```

## Priority 2 — `chainman` (would replace `miner` on `alder_pyke`)

Alder Pyke is the hinge of the whole spine — he is where the player's five
sightings turn into a journal entry — and he currently wears the ambient
market miner's sheet.

```
SUBJECT — bust portrait, black studio background, matching the existing NPC bust
set in style, lighting and crop.

A weathered outdoor working man of about sixty. Bald or close-cropped and grey,
sun-beaten, strong forearms, plain cream working shirt and a worn canvas coat.
A hundred-foot surveyor's chain of flat iron links looped over one shoulder. A
small brass plumb-bob on a waxed line in one hand. Comfortable, unhurried,
faintly amused — a man being asked about his trade for the first time in years
and enjoying it.

Not a miner, not a soldier, not sinister. Clothes worn but clean.
```

## Lower priority — the remaining six

Each works acceptably on its borrowed sheet. Listed with what a dedicated cut
would fix.

| NPC | Borrowed key | Dedicated cut would fix |
|---|---|---|
| `wenna_coyle` | `warlock` | Red-and-black hooded finery reads gothic; she is a clerk in an oilskin. Wants: severe practical woman, dark oiled hood, pen in hand. |
| `bael_rourke` | `captain` | Shares a face with the east-gate watchman. Wants: same build, but leathers and canvas rather than armour, rock dust in the beard. |
| `corrin_dale` | `prisoner_2` | Close enough — filthy work clothes, dark beard. No change urgent. |
| `fessick` | `prisoner_1` | Close enough — very old, gaunt, white beard. Would benefit from a shirt. |
| `gillian_petch` | `sweeper` | Blue house-dress reads domestic; she wants a plain good coat and a tally board. |
| `osgar_tull` | `barbarian` | Shares a face with the Rivermark drover. Wants: riverman's coat and jersey rather than furs. |

**Also worth noting for whoever merges:** `watch_street` (ambient) holds
`fighter`, `watch_gate` (ambient) holds `captain`, and `street_cutpurse`
(ambient) holds `rogue`. Ambient filler is currently occupying three of the six
eight-expression sheets in the game. Moving the ambient watchmen onto
one-expression sheets would free `fighter` and `captain` for named characters
who actually open conversations.

---

# New monsters

Three of the five are reskins of art that already ships; two use unused sheets.
None of them needs new art to load. Requests below are improvements, not
blockers.

| `monster_key` | Name | `sprite_key` | Status |
|---|---|---|---|
| `concern_warden` | Pit Warden | `bandit` | reskin — acceptable as is |
| `warden_serjeant` | Serjeant of the Works | `bandit` | reskin — see below |
| `pale_crawler` | Launder Crawler | `crawler` | existing sheet, unused until now |
| `crust` | Crust | `rock_man` | existing sheet, unused until now |
| `the_growth` | The Growth | `boss_worm` | existing sheet, act climax |

## `warden` / `warden_serjeant` — optional dedicated sheet

The Concern's wardens are the one enemy in the act that is emphatically **not**
bandits, and reading as bandits undercuts the whole point of the region.

```
SUBJECT — full-body battler sheet in the existing monster art style.

A private company guard, not a soldier and not a brigand. Matched dark canvas
coat to the knee with a high collar, small plates quilted into the lining, a
plain leather harness, a weighted baton and a light crossbow. Sturdy boots. No
heraldry, no fantasy armour, no skulls, no fur. Clean, uniform, well-fed,
completely unromantic. Two variants: rank and file, and a serjeant with a
broadsword and a whistle on a cord.

Mood: paid weekly, and it is in the terms.
```

## `the_growth` — no new art wanted

`boss_worm` is exactly right and should be kept: a mass of pale writhing
root-like limbs knotted around a single red aperture, grown into and out of the
rock. **Do not commission anything that explains it.** It is the last thing the
player sees in Act I and it is supposed to leave the question open.

---

## After generation — drop-in checklist

| File | Location |
|---|---|
| `spoil_stair.png` | The Spoil Stair |
| `muster_yard.png` | The Muster Yard |
| `timekeepers_hut.png` | The Timekeeper's Hut |
| `deepworks_canteen.png` | The Canteen |
| `the_headings.png` | The Headings |
| `pump_gallery.png` | The Pump Gallery |
| `overseers_office.png` | The Overseer's Office |
| `the_undercut.png` | The Undercut |
| `towpath_end.png` | The Towpath |
| `the_hythe.png` | The Hythe |
| `salt_house.png` | The Salt House |
| `corse_landing.png` | The New Landing |
| `tally_shed.png` | The Bonded Shed |
| `packet_stair.png` | The Packet Stair |
| `factors_house.png` | The Factor's House |
| `the_long_room.png` | The Long Room |

Path for all:

```
/home/richard/code/rpg/src/assets/images/locations/
```

The game loads these automatically from `assets/images/locations/<key>.png`
(see `locationArt()` in `assets/js/game.js`). Missing files remove themselves on
error, so a place without art just shows prose — none of this blocks the
content shipping.
