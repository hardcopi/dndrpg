# Eleven Weeks — Waerhaven, a city of the Ythan League

Original setting. Waerhaven, the Ythan League and the continent of Sundermere
are ours; the gazetteers they are built from are in [`World/`](../../World) —
`sundermere-gazetteer.md` for the nine realms, `waerhaven-gazetteer.md` for the
city, and `waerhaven.json` for the eight wards, forty keyed places, twelve
named people, seven factions and twelve rumours this act is authored against.

Validate before committing, as with any act:

```bash
python3 tools/load_content.py --check      # strict
python3 tools/trace_content.py             # reachability + the flag ledger
python3 tools/trace_content.py --economy   # can a companion climb back?
python3 tools/load_content.py              # writes sql/content.sql
bash    tools/test_waerhaven.sh            # drives the act over real HTTP
```

---

## The problem the act had to solve

Rivermark's antagonist is a man. Upmeads's was a piece of paper. Waerhaven's is
an **arrangement that everybody in it is defending in good faith**, and the act
exists to find out whether that is a thing a party can be given rather than
told about.

> Waerhaven builds the fleet the Ythan League has instead of an army, on a
> promontory with no river, behind one landward gate. It also has nineteen
> hundred people from Drennmark living outside the wall in a suburb the
> League's charter says does not exist, doing the work that keeps the slipways
> moving. The Moot needs the hands. The Watch is paid not to look. The Tarrow
> Houses want the cheap labour. And the Factor cannot report the problem
> without also reporting that his granary is two-thirds empty.
>
> It works. It is eleven weeks of grain away from not working.

Nobody in that paragraph is lying for gain. Every one of them is lying because
the truthful version kills somebody in February. That is the act.

## Who the player is

A stranger off the Hallowgate road, and the act says so out loud in four
separate places, because it is load-bearing. **Twenty-six thousand people live
here and every one of them is holding something they cannot afford to have
shaken** — a loft, a bench, a family in Old Waer, a debt to Ilber Cass, a hull
on the ways. Tam Ryke says it plainly and it is the reason she hires you:

> *And then somebody walks in off the Hallowgate road with nothing in the
> water. Do you know how rare that is?*

Every major NPC hires the party for the same reason, phrased differently, and
none of them notice they are all saying it.

---

## The world

Eight regions, forty-nine locations. Seven are the city's wards; the eighth is
under it.

| Region | Type | Locations |
|---|---|---|
| `hallowgate_ward` | town | 8 — the inn, its cellar, Hallowmarket, the gate, the Keep, the granary, the Weigh House, Halvard's |
| `highstrand` | town | 7 — the Moot Yard, the Moot, the Factor's House, the Wet Hall, the Charthouse, Vennik's, the Sounding Line |
| `the_boom` | town | 7 — Boomhead, the Counting Boom, the Chain Winch, Ilber's, the Drowned Jarl, the Great Crane, the Quiet Charter |
| `the_slipways` | town | 6 — the Great Slip, the Nine Masts, the mast pond, the Ropehouse, the Sailmaker's Loft, the dry dock |
| `old_waer` | town | 5 — the square, the Cold Anchor, the Fish Hall, the Temple of the Deep Mere, the Waer Light |
| `tarrow` | town | 6 — the Tar Steps, the pitch yards, the Boom Forge, the Oakum House, the Pitch & Pot, the Tar Gaol |
| `the_wardenswatch` | town | 5 — Wardens' Road, the Grey Hall, the chapel, the Green Bough, the lazar house |
| `the_underquay` | dungeon | 5 — the old quay steps, the low cellars, the tide gate, the drowned room, the deep store |

`waermans_rest` is the module's `start_location_key` and carries the only job
board. The Underquay is the only region with a random pool
(`underquay_wandering_rats`, `underquay_wandering_crawlers`), which is what
makes going under the stone read as going under the stone.

**Three locations are hidden and none of them are decoration.** The old quay
steps and the Quiet Charter are `hidden` exits off the Great Crane; the drain
in the inn's cellar is a `hidden` exit off the tutorial. All three are the same
floor, and the act's two discoveries are both down there.

---

## The spine — `granary_fate`

| resolution | what it is | Waerhaven afterward |
|---|---|---|
| `filled` | Claw nine weeks back out of the city — the weight proved, the Underquay store surfaced, the Houses opened at February's price — until the Factor's return is a true document. | `held`. Nothing is fixed. Nobody starves. The city goes on being exactly what it is, which was the price. |
| `reported` | The true figure south under Vennik's seal with six years of the weight's arithmetic behind it. | `audited`. The barges stop, Lathmere pays back six years in writing, and nineteen hundred people have nine days to not exist by the time the clerk walks through the gate. |
| `broken` | The Fish Hall sets the ration, the Moot holds the door, and Culm cuts his own seals rather than let a crowd break them. | `free`. Waerhaven feeds itself for the first time in ninety years. Lathmere answers it in the spring and everybody on that quay knew it. |

**All three doors are assembled out of other threads, and the loader will not
let you fake it.** `filled` needs `weight_fate: proved`, a `charter_fate` that
left the grain intact, *and* `reth_deal`. `reported` needs Vennik to have
offered his seal. `broken` needs the Moot to know and the Fish Hall to be
willing. A party that has done only the spine gets `not_yet` and a list of what
it is missing, in Culm's own voice.

### The rest

| quest | endings |
|---|---|
| `the_carters_cellar` (tutorial) | `cleared` · `found_the_way` |
| `the_short_weight` | `proved` · `sold` · `lie` |
| `nine_masts` | `found` · `covered` |
| `what_goes_out` | `seized` · `bought_in` · `burned` |
| `the_forty_one` | `named` · `buried` |
| `the_light_goes_out` | `stopped` · `joined` |
| `what_the_mere_gives_back` | `answered` · `quiet` |
| `eleven_weeks` (spine) | `filled` · `reported` · `broken` |
| `the_nineteen` (Yrsa) | `told` · `unsaid` |
| `the_beam` (Doren) | `owned` · `painted_out` |
| `what_the_houses_owe` (Nessa) | `split` · `stayed` |

---

## The two discoveries

### The four ounces

The League's standard weight, in a sealed box with three locks in the Counting
Boom, is **four ounces light**, and the filing is on the underside where the
stamp is, and it is six years old.

Four ounces in the hundredweight, over six years, on grain alone, is **nine
weeks of this city's bread** — which is most of the shortfall. It never came
off a barge. Lathmere shipped what Lathmere was paid for and Waerhaven signed
for more than it received, every quarter, in a document the Factor countersigns.

**Waerhaven has not been cheating the League. Waerhaven has been buying air.**
The Factor before Culm went home six years ago with a commendation and a house
in the Silver Quarter, and the wax on that box is not as old as the box.

The mechanism is the point: *three locks does not mean no man has ever been
alone with it.* Corran Sedge worked that out four years ago and has had nobody
safe to tell, because the only three people who can open the box are a Factor
who has never spoken to him, a Master Shipwright who has not addressed him
outside a weighing in nineteen years, and himself. Forty-one years of very
careful arrangement produced exactly one person who could ever get all three
keys together, and it is a stranger off the Hallowgate road.

### The second granary

**Gedda Halloway keeps the Quiet Charter.** Not the Moot — the Moot has never
voted on it, because a vote is a record. Nine masters keep the book on a rota,
a page each, in nine hands, so there is no hand in it and no name to give, and
every one of the nine thinks she is the ninth of an arrangement rather than
one of nine.

Six years ago she did two sums in one week: that the yard could not meet a
fleet order without Drennmark hands, and that the League would rather have the
hulls than enforce its own charter — right up until it needed somebody to
blame. So the Moot takes them in, and feeds them off the top of every barge
before the Factor's seals ever touch it, and stores it **below the
high-water mark, where a clause in the charter says the Factor's seals do not
run.** She read that clause once. Culm has read it eleven times and never
understood the point of it.

She has never sold a grain of it. Eighty quarters under the outer quay, kept
better than the League keeps its own, against the winter the League fails them.

The scene the whole act is built to reach is `two_granaries_known`:

> *Two granaries. In one city. Forty yards apart. Neither of them knowing about
> the other, both of them short, both of them lying, and both of them lying for
> exactly the same reason.*

---

## Companions

| key | who | rank | sprite | class | approves | disapproves |
|---|---|---|---|---|---|---|
| `yrsa` | Yrsa Fell, of the Wyrdpines | back | `ranger` | Ranger (Archery) | being let to finish a sentence, being used rather than protected, the north taken seriously | being handled gently, being told she is safe now |
| `doren_ferrow` | Doren Ferrow, shipwright's man | front | `fighter` | Fighter (Great Weapon) | curiosity, being asked a question, things built properly | contempt for the yard, work done badly to save a day |
| `nessa_reth` | Nessa Reth, of the Houses | back | `joss_frayle` | Rogue (Alert) | pragmatism, saying the number out loud, feeding people | sentiment, charity that costs the giver nothing |

All three are romanceable. All three leave at −20 and all three climb back to 0
on the `camp` subtree alone — **+58, +61 and +47 against the +20 needed**, with
quest money at 26%, 20% and 32%, which is inside what
`trace_content.py --economy` will pass and a long way from the 93% that broke
Rivermark.

**Every one of the three is written against the same joint**: a person who has
been in one building, one district or one road since childhood, and who wants
out from under the people who love them. Yrsa is nineteen and has not completed
a sentence about her own life since she was fourteen because forty people are
being kind to her. Doren has been in one hall since he was eleven and everybody
in it formed an opinion of him when he was a boy carrying somebody's adze.
Nessa has seventeen years of Tarrow and can price everything in it and has
never seen a room where a price was decided.

That is deliberate and it is the act's argument in miniature: **Waerhaven's
problem is not wickedness, it is that everybody in it is being protected by
somebody.**

- **Yrsa's arc is a sentence.** She has three worn-smooth sentences about the
  eleventh night and she stops at the same word every time, and everybody in
  the Green Bough kindly lets her. `told` is waiting four seconds longer than
  anybody else has. `unsaid` is the twelfth person to tell her she need not,
  and she takes it, and she is easier afterwards and further away, both.
- **Doren's arc is a beam.** `owned` is standing up under it in front of the
  house; `painted_out` is doing the responsible thing and keeping the paper.
  Both are worth the same and neither is wrong.
- **Nessa's arc is a number.** `split` says it out loud and changes nothing;
  `stayed` hands it over privately and the bread ration goes up a third within
  the fortnight and nobody is ever told why. *The first one is true and does
  nothing. The second one does something and is a lie by arrangement.*

---

## Mother Reth, and the rule about arguing

The Tarrow Houses are the act's test of whether the player can hold two things
at once, and the writing refuses to help.

Mother Reth pays four hundred people in tin tokens redeemable at one cart, and
takes eleven thousand four hundred marks a year off it, and is genuinely the
only person in Waerhaven who has ever guaranteed the poor bread in a bad year,
and every word she says about that is true. She will offer to let you take
every child off the oakum bench, today, with no conditions, and three priests
have taken that offer and it is where the lazar house came from.

What she wants is not to be thanked and not to be shouted at:

> *What I want is to be argued with. Properly. By somebody who has looked at
> what I do and can hold both halves of it at once and still thinks I am wrong.*

`reth_deal` is the only way into the Houses' stock, and therefore into the
`filled` ending, and it costs nothing but bargaining with her as an equal —
which nobody in Highstrand has done in forty years.

---

## Checks

DCs are 10 easy / 13 moderate / 16 hard / 19 very hard, and every failure lands
somewhere with a consequence.

| check | failure goes to |
|---|---|
| `bevis_culm:the_tour` Perception 13 | `he_walks_you_out` — he shows you two bays and talks about rats, and waits to see whether you come back |
| `corran_sedge:the_ropewalk` Persuasion 13 / Intimidation 16 | `he_shuts_up` — sets `sedge_closed`; four years of carefulness closes and does not reopen from that direction |
| `ione_brack:the_north_wall` Investigation 13 (10 Scribe) | `she_closes_it` — she stands in front of the presses until you leave |
| `nel_waer:four_nights` Insight 13 (10 Sailor) | `he_holds` — he gets the lie out level, and wins, and does not enjoy it |
| `oren_skeld:the_reckoning` Persuasion 16 / Intimidation 16 (13 Soldier) | `he_takes_it` — he asks you which of his three paymasters he should stop taking from, and waits, and is not being rhetorical |
| `reik_ollow:the_screen` Investigation 13 (10 Artisan) / Persuasion 13 | `the_hearth_cold` — the fifth hearth is banked and the bench swept |

Origin-gated options carry the `conditions` that match the pill: `SAILOR` on
`corran_sedge:the_ropewalk` and `nel_waer:four_nights`, `SCRIBE` on
`ione_brack:the_north_wall`, `SOLDIER` on `oren_skeld:the_reckoning`, `ARTISAN`
on `reik_ollow:the_screen`.

---

## Flag vocabulary

**108 flags, and every one of them is both set and read** — the rule is
`<subject>_<thing>`, lowercase, in the three shapes Rivermark uses and does not
mix: `met_*` presence flags, `*_fate` holding which ending happened, and
everything else a presence flag valued `"1"`.
`tools/trace_content.py` fails on a flag set and never read or read and never
set, so this table cannot drift silently.

### The ones other content branches on

| flag | set by | read by |
|---|---|---|
| `granary_fate` | `eleven_weeks` terminals — `filled` / `reported` / `broken` | Culm, Gedda, Ellsa, Hesta, the carter |
| `city_standing` | `eleven_weeks` terminals — `held` / `audited` / `free` | Vorn, Skeld, Hesta, the boom hand |
| `weight_fate` | `the_short_weight` terminals — `proved` / `sold` / `lie` | Culm, Sedge, Ilber |
| `charter_fate` | `what_goes_out` terminals — `seized` / `bought_in` / `burned` | Culm, Gedda, Doss, Reth, Ryke, Reik |
| `masts_fate` | `nine_masts` terminals — `found` / `covered` | Gedda, the sawyer |
| `forty_one_fate` | `the_forty_one` terminals — `named` / `buried` | Vorn, Yrsa, Skeld, the widow |
| `light_fate` | `the_light_goes_out` terminals — `stopped` / `joined` | Ellsa, Nel |
| `mere_fate` | `what_the_mere_gives_back` terminals — `answered` / `quiet` | Nairn, Ellsa, the singer |
| `yrsa_fate` / `doren_fate` / `nessa_fate` | the three companion quests | their own greetings and camp |

### The discoveries, and what they unlock

| flag | set by | what it opens |
|---|---|---|
| `granary_floor_seen` | `culm:the_third_bay` | starts `eleven_weeks` |
| `granary_lie_known` | `culm:he_admits_it`, `vennik:in_grain` | Gedda's `gedda_knows`, Hesta's steady head, Sedge's returns |
| `box_opened` | `sedge:he_agrees` | Vennik's beam, Ilber's other two offers |
| `culm_key_given` + `gedda_key_given` | Culm's drawer, Gedda's press | `sedge:all_three` — the only scene in forty-one years where all three keys are in one hand |
| `deep_store_seen` | `doss:whose_grain` | Gedda's `the_second_door`, Culm's three-hole arithmetic |
| `charter_head_known` | `gedda:the_second_door` | `the_charter_choice` |
| `two_granaries_known` | `gedda:the_two_granaries` | Culm's window scene |
| `gedda_wants_asking` | `gedda:what_she_wanted` | `culm:the_asking` — the Factor walks down the hill on his own |
| `vorn_told_you` + `vorn_wants_the_moot` | Vorn's map | `gedda:the_warden_in_the_hall` |
| `tenth_hull_known` + `gedda_knows_the_north` | Gedda reads the lines | `the_hull_and_the_north` — the sixth slip |

**`the_asking_done` is the beat the act is proudest of.** A League Factor walks
down the Gate Way with no clerk and asks a guild with no charter what should be
done, badly, in a room with its arms folded — and it goes in the Moot's book,
where it has needed to be for four hundred years. It is worth no gold and no
item and it is required by none of the three endings.

---

## Where everybody stands

| npc | location | sprite | |
|---|---|---|---|
| `hesta_orrow` | `waermans_rest` | `innkeeper` | tutorial |
| `corran_sedge` | `the_weigh_house` | `baker` | |
| `halvard_stonecut` | `halvards_ironmongery` | `smith` | shop |
| `oren_skeld` | `hallowgate_keep` | `paladin` | |
| `bevis_culm` | `the_factors_house` | `scribe` | spine |
| `gedda_halloway` | `shipwrights_moot` | `captain` | |
| `ione_brack` | `the_charthouse` | `sorcerer` | |
| `vennik_tallowglass` | `venniks` | `wizard` | shop |
| `ilber_cass` | `ilbers_chandlery` | `merchant` | shop |
| `doss_the_crane` | `the_great_crane` | `barbarian` | |
| `tam_ryke` | `the_sailmakers_loft` | `skulker` | |
| `doren_ferrow` | `the_nine_masts` | `fighter` | companion |
| `ellsa_waer` | `the_fish_hall` | `ottilie_hale` | |
| `sister_nairn` | `temple_of_the_deep_mere` | `cleric` | |
| `nel_waer` | `the_waer_light` | `farmer` | |
| `mother_reth` | `the_pitch_yards` | `miner` | |
| `reik_ollow` | `the_boom_forge` | `smith` | |
| `nessa_reth` | `the_oakum_house` | `joss_frayle` | companion |
| `aske_vorn` | `the_grey_hall` | `elowen` | |
| `yrsa` | `the_green_bough` | `ranger` | companion |

Ambient, one pooled voice per ward: `hallow_carter` (Hallowmarket),
`boom_drillman` (the Chain Winch), `slip_sawyer` (the Great Slip),
`waer_singer` (Old Waer Square), `oakum_picker` (the Oakum House),
`tar_gaol_prisoner` (the Tar Gaol), `bough_widow` (the Green Bough). Every one
of them reads a `*_fate` flag, so the wards report the act back to the player
without a single quest-giver having to.

| encounter | how it opens | where |
|---|---|---|
| `rest_cellar_rats` | ambush | `the_rest_cellar` |
| `underquay_crawlers` | ambush | `the_low_cellars` |
| `the_drowned_ones` | ambush | `the_drowned_room` |
| `mast_pond_drowned` | ambush | `the_mast_pond` |
| `charter_men` | ambush | `the_quiet_charter` |
| `tarrow_bravos` | `reth:she_calls_them`, parley to `she_calls_them_off` | the pitch yards |
| `boom_press_gang` | `skeld:he_calls_it_off`, parley to the same node | the Keep |
| `gaol_turnkeys` | `vorn:the_gaol_run` — *and only if you have not squared it* | the Tar Gaol |
| `the_light_men` | `nel_waer:the_fifth_night` | the point |
| `underquay_wandering_rats`, `underquay_wandering_crawlers` | random | `the_underquay` |

**`gaol_turnkeys` is the act's one avoidable fight and it is avoidable two
ways** — `skeld_will_look_away` (a written drill order the Watch-Captain files
himself) or `reth_deal` (the debt squared at the Houses' own rule). Both routes
open the same door and neither of them is cheaper than the fight; they are just
the two things the party will have done if it has been paying attention.

---

## The maps, which are the city's own

**The region plates are not generated art.** `World/` already holds a
hand-built plan of Waerhaven at 2400x1800 metres with every keyed place, every
street and every ward on it at a real coordinate, so
`tools/gen_waerhaven_maps.py` crops that instead of asking a model to invent a
harbour that is already drawn:

```bash
python3 tools/gen_waerhaven_maps.py --check   # report the windows, write nothing
python3 tools/gen_waerhaven_maps.py           # eight plates + every map_pos
```

It does two things and the second is the one that matters. It cuts one 4:3
window per ward out of `waerhaven-map.svg` at 1536x1152 — no upscaling, because
inkscape exports the area rather than the sheet — and then it **writes every
location's `map_pos` back from where the place actually stands on the plan**. A
node is not near the Wet Hall; it is on the Wet Hall. Forty-four of the
forty-nine locations come straight off the gazetteer's own coordinates, and the
squares, the gate and the two streets the act added are taken from the plan's
`plazas`, `gates` and `streets` geometry rather than guessed.

Two layers come off before the crop. `<g id="poi">` is the numbered key discs
and `<g id="district-names">` is the big ward captions, and the three bold gate
captions go with them — all of it is set at the weight the chart draws its own
node names at, so on a plate it reads as a node you cannot click. The italic
street, water and woodland names stay: nothing in the chart duplicates them,
and on a cropped window they are the only thing that says which way is out.

Six of the eight windows are fitted to the ward's own places. Three are not, and
the reason is worth keeping:

| ward | window | why |
|---|---|---|
| `the_boom` | hand-set, west to the piers | The Boom *is* the harbour. A window fitted to its buildings frames the warehouses behind them and crops the water off. |
| `the_slipways` | hand-set, north to the slip heads | Same: the ways run into the Mere and the Mere is the point of them. |
| `tarrow` | hand-set, pushed east | Tarrow's places are a narrow column 330 m tall, and a 4:3 window round that is 750 m wide — half of which is not Tarrow. |

Feeding the piers into the fitter as extra points was tried first and is what
the hand-set windows replaced: it drags the box to 1135 m across, because a 4:3
window round a tall point-set is a very wide one, and the ward ends up small in
the middle of a lot of water. Choosing the frame is the call
`gen_region_map.py` makes with `BY_KEY`, made the same way.

**The Underquay takes the Boom's window and is darkened**, because it lies
under exactly that ground — and it keeps its hand-authored `map_pos`, since a
floor nobody surveyed does not get to pretend it was surveyed.

## The cover, and the art that is still missing

The **cover** is shipped: `assets/images/modules/waerhaven.jpg`, seed 302,
recorded in `SHIPPED_SEED`. It is the south boom tower with the harbour chain
running out of it and lying across the quay in the foreground, one figure in a
dark coat for scale, timber warehouses on stilts along the wharf. `index.php`
renders a module card as a plain `<img>`, so a missing cover is a broken image
on the shelf rather than a graceful gap — this was not optional. It measures at
the shelf's mean of 79.5, which `match_shelf()` solves for rather than tunes.

What is left:

- **Portraits.** Every NPC wears a generic sprite, chosen for likeness. The
  five heaviest-dialogue characters hold the five eight-expression sets
  (`captain`, `cleric`, `elowen`, `fighter`, `ranger`); Ellsa Waer and Nessa
  Reth hold the two three-expression sets; everybody else has one and their
  nodes never ask for a second. `tools/gen_npc_art.py` wants the image model on
  `127.0.0.1:7860`.
- **Location plates.** `tools/gen_location_art.py`, one 16:9 per location.

## What Act 2 attaches to

Three things are deliberately planted and none of them resolve here.

- **The sixth slip.** On the best line through `nine_masts` → `the_beam` →
  `gedda:the_hull_and_the_north`, the Moot lays down a hull for ice, in the
  open, in its own book, with a tenth mast on that beam — and it is eighteen
  months from keel to launch, which is Act 2's clock. The party leaves with
  `the_tenth_hull` in the pack.
- **The line of pins that stops.** Aske Vorn's map has forty-three villages on
  it and nothing at all above a line, and *empty on a Warden's map means
  nothing has come out*. Eleven miles a year for six years, then the fell, then
  eighty-five miles of forest with no track, then this promontory. Nine
  villages behind that line have not been heard from in two years.
- **The correspondence.** `mere_fate: answered` empties Sister Nairn's shelves
  and she leaves the door propped open on purpose, because a reply is not an
  ending: *there will be more, and now we have told it that we are listening.*
  About a tenth of what comes ashore was never thrown, and one piece of it is
  a net-mender's needle from a house that has had nobody in it for eleven years.

And underneath all three, unresolved on purpose: three holds of Karrun-Deep
have gone silent under the Kalder Wall, Halvard Stonecut has written twelve
unanswered letters north, and the Deepmoot voted six to nothing not to discuss
it — which is six votes out of nine.
