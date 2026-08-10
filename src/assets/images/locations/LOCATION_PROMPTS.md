# Location establishing shots — Grok prompts

> **Act I expanded on 2026-08-01 from 18 locations to 73.** This file still holds
> the style contract and the original Rivermark / Quarry Wilds / Goblin Warren
> shots. The new regions are in three companion files, listed below. They are
> deliberately NOT merged into this one: each reproduces the global style
> contract so it can be handed over on its own, and one 2,200-line file is a
> worse thing to work from than four focused ones.
>
> | File | Covers | Locations wanting art |
> |---|---|---|
> | **this file** | Rivermark, Quarry Wilds, Goblin Warren | 10 |
> | `PROMPTS_UNDERTOWN.md` | Undertown, and the two new Rivermark gates | 10 |
> | `PROMPTS_WILDS.md` | Ford Road, Hollow Fen, Arden Priory, and the Quarry/Warren additions | 30 |
> | `PROMPTS_DEEPWORKS.md` | The Deepworks, Greyhythe, plus new NPC busts and monsters | 16 |
>
> **56 of the 73 locations have no PNG yet.** A location with no art is not
> broken — the location screen is prose and the art is the backdrop — so these
> can be worked through in any order. Suggested order is by where a player goes
> first: Undertown and Ford Road (levels 2–3), then the Fen and the Priory, then
> the Deepworks and Greyhythe, which are the act's finale and the ones worth the
> most care.
>
> Two things that are easy to get wrong and expensive to redo:
> - **A missing PNG serves the homepage with HTTP 200**, not a 404 — the vhost
>   falls through to `index.php`. Never check an asset by status code. Use
>   `curl -s -o /dev/null -w '%{content_type}\n' <url>`; `image/png` is there,
>   `text/html` is not.
> - **File names are `location_key`, not the pretty name** — `flagon_common_room.png`,
>   not `The Golden Flagon.png`.

Generate one **interior or exterior establishing photo** per location for Rivermark
Chronicles. These are the backgrounds behind the location screen (prose + people
list), not map art and not dialogue camp scenes.

Drop finished files here as:

```
src/assets/images/locations/<location_key>.png
```

`location_key` is the authoring key (e.g. `flagon_common_room.png`), **not** the
pretty name.

---

## Global style contract (paste at the top of every request)

Use this block once per image, then the location-specific section.

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

Attach the listed bust PNG(s) for that location and say:

```
Attached image(s) are reference portraits for likeness only (face, hair, outfit,
build, race). Match them closely. Do not copy the black studio background or the
bust crop — put the person into this scene naturally.
```

**Only named permanent NPCs** who *belong* to the place. Never ambient
townsfolk (bakers, children, watch fillers, street colour). Companions who
*start* at a place are noted as optional early-game extras — the default shot
should work without them.

| Who | Bust path |
|---|---|
| Mara Hearthstone (innkeeper) | `assets/images/npcs/innkeeper_bust.png` |
| Captain Elowen Reed | `assets/images/npcs/elowen_bust.png` |
| Sera Vance | `assets/images/npcs/merchant_bust.png` |
| Tobin Brassscale | `assets/images/npcs/saurial_bust.png` |
| Brenna Coalwright | `assets/images/npcs/smith_bust.png` |
| Osric Fen | `assets/images/npcs/druid_bust.png` |
| Hollis Marrow | `assets/images/npcs/scribe_bust.png` |
| Brother Aldric | `assets/images/npcs/aldric_bust.png` |
| Kessa Dunmar (half-orc) | `assets/images/npcs/kessa_bust.png` |
| Garrow Finch | `assets/images/npcs/skulker_bust.png` |
| Ilse Thornwood (deer-hood) | `assets/images/npcs/ranger_bust.png` |
| Nakka Skarn | `assets/images/npcs/goblin_warlord_bust.png` |

---

## Batch order (suggested)

Venue fixtures first (highest reuse), then streets/gates, then wilds, then warren.

1. `flagon_common_room` — Mara  
2. `tobins_goods` — Tobin  
3. `the_anvil` — Brenna  
4. `root_ash` — Osric  
5. `granary_row` — Hollis  
6. `market_square` — Elowen (Sera optional)  
7. `high_street`  
8. `counting_house`  
9. `east_gate`  
10. `flagon_cellar`  
11. `quarry_road`  
12. `wardens_camp` — Ilse  
13. `broken_hills`  
14. `warren_approach`  
15. `warren_mouth`  
16. `deep_shaft`  
17. `warlord_hall` — Nakka  

---

# Location prompts

For each location: attach portraits listed under **Attach**, paste the **global
style contract**, then paste the block under **Prompt**.

---

## 1. `flagon_common_room` — The Golden Flagon

**Attach:** `innkeeper_bust.png`  
**Optional later variants:** also attach `aldric_bust.png`, `kessa_bust.png`,
`skulker_bust.png` for a crowded early-game common room (not required for the
default shot).

**Output file:** `flagon_common_room.png`

**Prompt:**

```
SUBJECT — interior of the Golden Flagon, Rivermark's coaching inn, daytime /
early evening.

The warm heart of a modest river town: hearth-light on scrubbed oak tables,
low beams, a polished bar, a job board by the door, stairs up to rooms in the
back, mugs and a bread board on the bar. Flour-dust, woodsmoke, and the sense
that most of what matters in town is agreed at these tables before it is ever
said officially. Prosperous but plain — no palace, no gilt.

KEY NPC (required) — Mara Hearthstone, the innkeeper.
Match the attached portrait: a middle-aged working woman, flour on the apron,
practical hair, level expression, no patience for a story told twice. Place her
behind the bar, mid-scene, wiping a mug or resting both hands on the bar as if
mid-count. She owns this room.

Do not include other named characters unless their portraits are also attached.
No random customers with invented faces filling the frame — empty tables and
the suggestion of recent use are enough.

Mood: shelter after the road. On your side.
```

---

## 2. `tobins_goods` — Tobin's Goods

**Attach:** `saurial_bust.png`  
**Output file:** `tobins_goods.png`

**Prompt:**

```
SUBJECT — interior of Tobin's Goods, a trade shop off Rivermark's market square.

Rope coils, salt sacks, shelves of travelling stock; the better goods behind a
wooden counter. Chalk slate for prices (marks only, no readable letters). Tar,
rope and salt in the air. Narrow, practical, well-kept.

KEY NPC (required) — Tobin Brassscale, merchant.
Match the attached portrait: half-dragonborn with dull brass scales, careful
hands, keeps prices where the road puts them. Behind the counter, dusting a
shelf or weighing something, not posing.

No other people. Mood: competent trade, nothing free.
```

---

## 3. `the_anvil` — The Anvil

**Attach:** `smith_bust.png`  
**Output file:** `the_anvil.png`

**Prompt:**

```
SUBJECT — interior of Rivermark's smithy, the Anvil.

Forge never quite cold; hammers, tongs, blades, tools and horseshoes in ranks;
anvil centre; quench-trough; heat haze; soot on timber and stone. Good steel
under the counter. Working shop, not a show forge.

KEY NPC (required) — Brenna Coalwright, smith.
Match the attached portrait: strong working woman, soot and leather apron,
forge-smart face. At the anvil or bellows mid-work, hammer raised or resting —
opinion of guild prices visible in her posture.

No other people. Mood: heat, work, judgement.
```

---

## 4. `root_ash` — Root & Ash

**Attach:** `druid_bust.png`  
**Output file:** `root_ash.png`

**Prompt:**

```
SUBJECT — interior of Root & Ash, the apothecary on Rivermark's high street.

Drying herbs hung from beams, stoppered bottles in ranked shelves, mortar and
pestle, a ledger on the counter, green and amber light through dusty glass.
Smells of sage, iron, and something sharp. Exact, tidy, slightly austere.

KEY NPC (required) — Osric Fen, apothecary.
Match the attached portrait: careful, measured man, measures words the way he
measures powders. Grinding a pestle or selecting a bottle, not facing camera
like a portrait.

No other people. Mood: precision; remedies have a price.
```

---

## 5. `granary_row` — Granary Row

**Attach:** `scribe_bust.png`  
**Output file:** `granary_row.png`

**Prompt:**

```
SUBJECT — exterior of Rivermark's Granary Row by day, looking along the lane.

Tall windowless warehouses and grain-lofts, loading doors, sacks, a cart, tally
chalk on boards (marks only, no readable text). Dust in the air; wealth stored
and watched. Cobbled lane, deep shadow between lofts, river light at one end.

KEY NPC (required) — Hollis Marrow, Reeve of the Granary.
Match the attached portrait: thin precise man who owns the scales and most of
the debt in town. At a weighing floor or loft door with a tally stick or
ledger, chalking sacks or watching a load. Not warm.

No other people. Mood: quiet power, grain as leverage.
```

---

## 6. `market_square` — Market Square

**Attach:** `elowen_bust.png`  
**Optional:** also `merchant_bust.png` for Sera Vance at the edge of the square.  
**Output file:** `market_square.png`

**Prompt:**

```
SUBJECT — Rivermark Market Square, open air, daytime.

Cobbled square open to river wind; stall canvas and awnings; a stone well at
the centre; fish-water and trodden straw on the stones; stacked crates and
barrels. Busy geometry of a working market without filling the shot with
invented faces — mostly empty stalls mid-setup or mid-lull, so the place reads
clear.

KEY NPC (required) — Captain Elowen Reed, watch captain.
Match the attached portrait. At the well or a watch post overlooking the
square, quiet, studying hands rather than faces. Rivermark's patience with a
badge.

OPTIONAL (only if portrait attached) — Sera Vance, ledger-keeper: neat brown
travelling leathers, braided hair, sharp tired face, off to one side as if
between errands, not centre stage.

No ambient townsfolk with invented faces. Mood: trade, watchfulness, river wind.
```

---

## 7. `high_street` — The High Street

**Attach:** none  
**Output file:** `high_street.png`

**Prompt:**

```
SUBJECT — Rivermark High Street, looking along the spine of town, daytime.

Shopfronts and house doors shoulder to shoulder; cobbles; shutters; a mule
track of straw; the street running from market light toward the inn end. Word
travels this street faster than anything on wheels. Prosperous, plain, lived-in
timber and plaster.

No named characters, no invented crowd filling the frame — a nearly empty
street mid-afternoon with the sense people just passed. Mood: the town's
spine; everyone who wants to be seen walks it twice a day.
```

---

## 8. `counting_house` — The Counting House

**Attach:** none  
**Output file:** `counting_house.png`

**Prompt:**

```
SUBJECT — interior of the Counting House on Rivermark's river quay.

Narrow stone room; tall windows with river light; ledgers, wax seals, writing
desks, coin trays, shelves of bound accounts. Smell of ink, wax, and old paper
— money already counted. Austere, precise, slightly cold.

No named characters. Empty desks mid-work (open ledger, resting quill) is
fine. Mood: tariffs and silence; clerks not required on camera.
```

---

## 9. `east_gate` — The East Gate

**Attach:** none  
**Output file:** `east_gate.png`

**Prompt:**

```
SUBJECT — Rivermark's East Gate from just inside the wall, daytime.

Fortified stone gatehouse, open arch, road running out east into open country
and distant hills. Dust on the wind. Gate-watch equipment (spear rack, stool,
horn) but no invented faces required — empty post mid-shift is fine. Cart ruts
through the arch.

No named characters. Mood: leaving is easy; coming back is studied.
```

---

## 10. `flagon_cellar` — The Flagon Cellar

**Attach:** none  
**Output file:** `flagon_cellar.png`

**Prompt:**

```
SUBJECT — the cellar under the Golden Flagon.

Cool dark: grain sacks, kegs on chocks, low vaulted stone, a sump where the
river seeps in. One oil lamp or lantern; light does not reach the corners.
Something has been at the stores — torn sacks, scattered grain, sour smell
under damp stone. Not a fight in progress; the aftermath of vermin or worse.

No people. Mood: larder gone wrong; corners know more than the lamp.
```

---

## 11. `quarry_road` — The Quarry Road

**Attach:** none  
**Output file:** `quarry_road.png`

**Prompt:**

```
SUBJECT — the old quarry haul-road east of Rivermark, open country, overcast
daylight.

Rutted road with grass up the middle, puddles the colour of slate, hills and
wind, treeline that could hide watchers. No traffic. A broken milestone or cart
fragment in the verge optional. Emptied, slightly menacing.

No people. Mood: good road for making time; better for being watched.
```

---

## 12. `wardens_camp` — The Warden's Camp

**Attach:** `ranger_bust.png`  
**Output file:** `wardens_camp.png`

**Prompt:**

```
SUBJECT — Ilse's lean camp on high ground above the quarry road, late day.

One weathered tent the colour of the hillside; stone fire-ring built to throw
no light downwind; stretched hides on a rack; tin cup; sightlines over the
valley like an unrolled map. Thin footpath down toward the road. Soldier's
economy, not comfort.

KEY NPC (required) — Ilse Thornwood, Hill Warden.
Match the attached portrait EXACTLY for the distinctive kit: stag skull-and-
hide headdress with antlers, cloth mask over the lower face (her face is never
seen), russet and brown layered leather, green and orange forearm wraps. Seated
or standing at the fire-ring's edge, watching the valley, not posing.

No other people. Mood: someone has been counting goblins for two years.
```

---

## 13. `broken_hills` — The Broken Hills

**Attach:** none  
**Output file:** `broken_hills.png`

**Prompt:**

```
SUBJECT — the Broken Hills east of the quarry road: gorse, grey stone, narrow
cuttings.

Path threading between buckled ridges; a burned-out cart tipped on a ridgeline;
loose stone; every rise looks down on the path. Overcast, wind-scraped. Raider
country.

No people. Mood: the road's ease ends here; quiet stops feeling like quiet.
```

---

## 14. `warren_approach` — The Warren Approach

**Attach:** none  
**Output file:** `warren_approach.png`

**Prompt:**

```
SUBJECT — the abandoned stone quarry approach used as a goblin door.

Worked cliff of quarry stone gone wild; spoil heaps; dark cave mouth at the
base with crude timber and stake fortification; bones and scrap iron on the
ground before it; smoke-thread from the mouth. Path ends here.

No people (no living goblins in frame — implication only). Mood: the hills
belong to the warren from here on; smell of smoke, refuse, old meat.
```

---

## 15. `warren_mouth` — The Warren Mouth

**Attach:** none  
**Output file:** `warren_mouth.png`

**Prompt:**

```
SUBJECT — interior of the warren entrance: old quarry cutting floor, partly
roofed with hide and scavenged timber.

Crude gate of cart-iron plates and sharpened stakes barring the tunnel deeper;
cook-fire smoke along ceiling seams; refuse heaps; lean-to shelters against
worked stone walls. Daylight dies a few paces in. Squalid, fortified, mine-
works fouled by squatters.

No people, no clear goblin figures (empty of guards mid-shift). Mood: past the
gate is theirs.
```

---

## 16. `deep_shaft` — The Deep Shaft

**Attach:** none  
**Output file:** `deep_shaft.png`

**Prompt:**

```
SUBJECT — the deep quarry shaft chamber used as the warren hub.

Large roughly circular shaft of concentric worked ledges stepping down into
black; rope-ladders and plank walkways between ledges; stacked crates and nest-
like bedrolls on ledges; one lantern or fire-glow high up. Long drop. Goblins
mine nothing here now — they store, nest, and watch.

No people. Mood: a throat of stone; one wrong plank is a long time falling.
```

---

## 17. `warlord_hall` — The Warlord's Hall

**Attach:** `goblin_warlord_bust.png`  
**Output file:** `warlord_hall.png`

**Prompt:**

```
SUBJECT — the warlord's hall at the bottom of the warren: vaulted quarry store-
hall turned throne room.

Square-cut pillars, great fire-pit centre, trophies and scrap weapons hung
along walls, crude raised throne of stacked crates and hides at the far end.
Stolen goods of a dozen valley raids. Fire-light and smoke. Expecting visitors.

KEY NPC (required) — Nakka Skarn, Warlord of the Warren.
Match the attached portrait: goblin warlord in looted human plate that does not
fit at the shoulder, helm kept for the shape it makes in a doorway. Seated or
standing at the throne end of the hall, holding court, not a hero pose. She is
why the valley has gone wrong.

No other named people. Mood: fire, teeth, and a sum that has already been done.
```

---

## Optional companion extras (same file names with suffix)

Only if you want a second art set for early game when recruitables still sit
in town. Same global style. Save as
`<location_key>_early.png` if you keep both.

### `flagon_common_room_early`

**Attach:** `innkeeper_bust.png`, `kessa_bust.png`, `aldric_bust.png`  
(optional also `skulker_bust.png` for Finch at a far table)

```
Same Golden Flagon interior as flagon_common_room, but:

- Mara behind the bar (required).
- Kessa Dunmar: half-orc, muted green-grey skin, small tusks, blonde messy
  hair, olive laced top — at the end of the bar, back to the wall, cup she is
  not drinking. Match kessa_bust.
- Brother Aldric: dirty-blond hair, brown studded jerkin, wooden sun-disc —
  at a side table or near the hearth, quiet, mending or sitting the hours.
  Match aldric_bust.
- Optional Garrow Finch: polite debt factor in dark clothes at a far table,
  watching the room. Match skulker_bust.

Still no invented crowd faces. Mood: the Flagon before the party empties it.
```

### `market_square_early`

**Attach:** `elowen_bust.png`, `merchant_bust.png`

```
Same Market Square, with Captain Reed at the well AND Sera Vance mid-ground
in travelling leathers with ledger satchel, between stalls, watchful. Both
likenesses from attached busts. No ambient faces.
```

---

## After generation — drop-in checklist

| File | Location |
|---|---|
| `flagon_common_room.png` | The Golden Flagon |
| `tobins_goods.png` | Tobin's Goods |
| `the_anvil.png` | The Anvil |
| `root_ash.png` | Root & Ash |
| `granary_row.png` | Granary Row |
| `market_square.png` | Market Square |
| `high_street.png` | The High Street |
| `counting_house.png` | The Counting House |
| `east_gate.png` | The East Gate |
| `flagon_cellar.png` | The Flagon Cellar |
| `quarry_road.png` | The Quarry Road |
| `wardens_camp.png` | The Warden's Camp |
| `broken_hills.png` | The Broken Hills |
| `warren_approach.png` | The Warren Approach |
| `warren_mouth.png` | The Warren Mouth |
| `deep_shaft.png` | The Deep Shaft |
| `warlord_hall.png` | The Warlord's Hall |

Path for all:

```
/home/richard/code/rpg/src/assets/images/locations/
```

The game loads these automatically from `assets/images/locations/<key>.png`
(see `locationArt()` in `assets/js/game.js`). Missing files remove themselves
on error so a place without art just shows prose.
