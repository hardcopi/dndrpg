# Act 1 — Rivermark

The format is in [`docs/CONTENT.md`](../docs/CONTENT.md). This file is about the
*act*: what is here, how the flags are named, and which decisions were made by
the art rather than by the story.

Validate everything before committing:

```bash
python3 tools/load_content.py --check      # strict: dangling refs, dead nodes
python3 tools/trace_content.py             # reachability + the flag ledger
python3 tools/load_content.py              # writes sql/content.sql
```

---

## The shape of it

Rivermark's eastern quarry fell in years ago and goblins hold the warren under
it. The raids on the trade road look random and are not: **Hollis Marrow**, the
reeve of the granary, buys the harvest forward at a spring price, so a road
nobody can use is a bad summer that arrives whenever he needs one. He pays
Nakka Skarn's warren in salt and iron to burn specific wagons — his own — and
claims them at the year's price. The mill's ledger is where the arithmetic
shows, in Sera Vance's handwriting and nobody else's.

Underneath that: the goblins only came up four winters ago because something
took the deep warren off them. That is Act 2's problem and Act 1 only lets you
look at it.

### The spine — `quarry_pact`

| resolution | what it is | Rivermark afterward |
|---|---|---|
| `steel` | Take the hall, kill the warlord. | Safe, coarsened, no council has met since. Reed's word is final. |
| `pact` | Truce with Nakka, and Marrow read out on his own weighing floor. | Divided. Half the market cheers; half shuts the door. The warren is a neighbour. |
| `ash` | Take Marrow's four hundred and the writ of carriage, and go west. | Left to fall. |

### The rest

| quest | endings |
|---|---|
| `cellar_rats` (tutorial) | `exterminated` · `source_found` |
| `ledger_and_lie` | `burned_the_book` · `gave_it_to_reed` · `sold_it_back` |
| `teeth_in_the_hills` | `ambushed_the_raiders` · `escorted_the_caravan` · `turned_the_road` |
| `quarry_pact` | `steel` · `pact` · `ash` |
| `kessa_debt` | `paid` · `fought` · `sold_her` |
| `aldric_absolution` | `confessed` · `buried` |
| `sera_leash` | `cut_the_leash` · `held_the_leash` |
| `ilse_deep_warren` | `sealed_the_shaft` · `sounded_the_deep` |

`ledger_and_lie` decides whether Sera can be recruited: `burned_the_book` and
`sold_it_back` both set `sera_spared`; `gave_it_to_reed` puts her in the lockup.

### Companions

| key | who | rank | sprite | approves | disapproves |
|---|---|---|---|---|---|
| `kessa` | Kessa Dunmar, half-orc barbarian | front | `barbarian` | directness, decisive force, promises kept | cruelty to the helpless, betrayal, dithering |
| `aldric` | Brother Aldric, human cleric | back | `cleric` | mercy, protecting civilians, honesty | murder, extortion, cruelty |
| `sera` | Sera Vance, human rogue | back | `rogue` | pragmatism, kept secrets, self-preservation | naive honesty, moralising |
| `ilse` | Ilse Thornwood, wood elf ranger | back | `ranger` | restraint, curiosity, sparing the goblins | slaughter, greed |

Romanceable: Kessa, Aldric, Ilse. Not Sera — she is written as somebody who has
only ever been wanted for what she can be made to write, and the arc is about
her getting out from under that, not into another one.

The axes are meant to fight. `steel` is +15 Kessa and −25 Ilse. Burning the
ledger is +6 Kessa, +10 Sera, −12 Aldric. Selling it back is −18 Aldric and −8
Ilse and Sera does not thank you either. Kessa and Sera argue in each other's
conversations (`kessa:about_sera`, `sera:the_others`); Aldric and Sera argue
about whether a true thing should be said out loud (`aldric:the_sera_argument`,
and Sera's interjection on `aldric:the_true_version`).

---

## Flag vocabulary

**Every flag in the act is listed here.** The rule is `<subject>_<thing>`, all
lowercase, no verbs in the past tense unless the flag records a completed
choice. Three shapes are used and they are not mixed:

* **`met_*`** — plain presence flags, value `"1"`. Set the first time a
  character is on screen. Read by companion `recruit.conditions` and by
  greeting variants.
* **`*_fate`** — a single flag holding *which* ending happened, as a word.
  One per quest that other content branches on. Read with `equals`.
* **everything else** — plain presence flags, value `"1"`, except
  `goblins_spared`, which is a counter and is read with `at_least`.

`tools/trace_content.py` fails if any flag is set and never read, or read and
never set, so this table cannot drift without the build saying so.

### Presence — who you have met

| flag | set by | read by |
|---|---|---|
| `met_kessa` | `mara:who_drinks_here`, `kessa:greeting` fallback | Kessa's `recruit.conditions`, `kessa:greeting` |
| `met_aldric` | `mara:who_drinks_here`, `aldric:greeting` fallback | Aldric's `recruit.conditions`, `aldric:greeting` |
| `met_ilse` | `mara:the_warden`, `captain:the_warden_hint`, `tobin:the_rumours`, `ilse:greeting` fallback | Ilse's `recruit.conditions`, `ilse:greeting` |
| `met_sera` | `captain:the_ledger`, `sera:greeting` fallback | `tobin:the_rumours` |
| `met_hollis` | `hollis:greeting` fallback | `tobin:the_rumours` |
| `met_nakka` | `nakka:hail`, `nakka:scouts_hold`, `nakka:raid_halt`, `nakka:gate_hail` | `nakka:gate_hail` |
| `met_garrow` | `kessa:who_is_finch`, `garrow:greeting` fallback | `garrow:greeting` |

### Tutorial — the cellar

| flag | set by | read by |
|---|---|---|
| `cellar_rats_beaten` | encounter `cellar_rats_swarm` victory | `mara:greeting` (report-back variant) |
| `cellar_stirges_beaten` | encounter `cellar_stirges` victory | `mara:greeting` (the sump variant) |
| `cellar_cleared` | `cellar_rats` terminals — `"killed"` / `"cause"` | `mara:greeting`, `tobin:the_rumours` |
| `grain_stamp_seen` | `cellar_rats/source_found`, `sera:the_paymaster_lead` | `captain:name_marrow`, `hollis:greeting` |

### The ledger and the granary

| flag | set by | read by |
|---|---|---|
| `ledger_fate` | `ledger_and_lie` terminals — `"burned"` / `"reed"` / `"sold"` | `captain:briefing`, `aldric:greeting` |
| `sera_spared` | `burned_the_book`, `sold_it_back` | Sera's `recruit.conditions`, `sera:greeting` |
| `sera_arrested` | `gave_it_to_reed` | `mara:greeting`, `sera:greeting` |
| `sera_second_book_known` | `sera:the_second_book_told`, `sera:name_from_the_cell`, `hollis:the_lie_shows` | `hollis:the_loft_taunt` |
| `sera_leash_fate` | `sera_leash` terminals — `"cut"` / `"held"` | `sera:greeting` |
| `knows_paymaster` | `sera:names_hollis`, `sera:the_real_fear`, `captain:the_pattern_report`, `hollis:he_gives_a_name`, `nakka:the_manifest` | `captain:name_marrow`, `hollis:greeting`, `nakka:gate_hail`, `nakka:the_paymaster_named` |
| `hollis_warned` | `sera:she_bolts`, `sera:she_calls_the_yard` | `hollis:greeting` |
| `hollis_rattled` | `hollis:the_spoiled_grain`, `the_lie_shows`, `the_loft_taunt`, `he_recalculates` | `nakka:the_paymaster_named` |
| `hollis_coin_taken` | `sold_it_back`, `quarry_pact/ash` | `hollis:greeting` |
| `granary_knives_beaten` | encounter `granary_knives` victory | `hollis:greeting` |
| `mara_knows_marrow` | `mara:she_talks` | `hollis:greeting` |
| `mara_wary` | `mara:she_shuts_up` (failed Persuasion) | `mara:greeting` |

### The watch

| flag | set by | read by |
|---|---|---|
| `reed_looks_away` | `captain:she_gives_you_room` (Persuasion 16) | `hollis:greeting` |
| `watch_on_you` | `captain:she_puts_a_watch_on_you` (failed Persuasion 16) | `hollis:greeting` |
| `reed_writ_given` | `captain:she_writes_it` | `nakka:gate_hail` |
| `reed_absolute` | `quarry_pact/steel` | `tobin:the_rumours` |

### The road and the warren

| flag | set by | read by |
|---|---|---|
| `hills_pattern` | `escorted_the_caravan`, `turned_the_road`, `nakka:the_manifest` | `mara:about_town`, `captain:briefing`, `nakka:truce_terms`, `nakka:she_wont_come_down`, `hollis:the_wagons` |
| `hills_fate` | `teeth_in_the_hills` terminals — `"ambush"` / `"escort"` / `"reroute"` | `captain:briefing`, `tobin:greeting` |
| `caravan_saved` | `escorted_the_caravan` | `tobin:greeting` |
| `goblins_spared` | counter, `increment_flag` in `nakka:she_lets_you_go`, `she_stands_aside`, `she_lets_the_wagon_pass`, `she_walks_away`, `she_takes_the_wagon` | `nakka:the_hall` (`at_least: 4`) |
| `nakka_owes_you` | `nakka:she_takes_the_wagon` | `nakka:gate_hail` |
| `nakka_dismissed_you` | `nakka:she_ends_it`, `nakka:the_gate_holds` | `nakka:the_hall` |
| `nakka_named_him` | `nakka:the_paymaster_named` | `nakka:truce_terms` |
| `warren_gate_forced` | encounter `warren_gate` victory | `nakka:hail` |
| `warlord_hall_taken` | encounter `warlord_hall` victory | `nakka:hail` → sets `quarry_pact/steel` |
| `warlord_hall_lost` | encounter `warlord_hall` defeat | `nakka:hail` (she has you carried out) |
| `warlord_dead` | `quarry_pact/steel` | `tobin:greeting` |
| `warren_truce` | `quarry_pact/pact` | `tobin:greeting` |
| `quarry_fate` | `quarry_pact` terminals — `"steel"` / `"pact"` / `"ash"` | every major NPC's greeting |
| `town_standing` | `quarry_pact` terminals — `"safe"` / `"divided"` / `"fallen"` | `tobin:greeting` |

### The deep

| flag | set by | read by |
|---|---|---|
| `deep_known` | `nakka:the_pushed_out`, `nakka:what_is_down_there`, `ilse:the_deep` | `ilse:the_deep`, `nakka:the_deep_after` |
| `deep_shaft_cleared` | encounter `deep_shaft` victory | `ilse:greeting` |
| `deep_shaft_fate` | `ilse_deep_warren` terminals — `"sealed"` / `"sounded"` | `nakka:the_deep_after` |
| `ilse_count_heard` | `ilse:the_count` | `ilse:the_recruit` |
| `ilse_stayed_after_steel` | `ilse:she_will_stay_for_that` | `ilse:greeting` |

### Companion arcs

| flag | set by | read by |
|---|---|---|
| `kessa_debt_known` | `kessa:she_admits_the_debt`, `kessa:the_debt_talk` | `kessa:the_debt_talk` |
| `kessa_debt_fate` | `kessa_debt` terminals — `"paid"` / `"fought"` / `"sold"` | `kessa:greeting`, `kessa:the_debt_talk`, `garrow:greeting` |
| `finch_backed_off` | `garrow:he_walks_away` (Intimidation 16) | `garrow:greeting` |
| `finch_knows_you_are_short` | `garrow:he_holds_at_ninety` (failed Persuasion) | `garrow:greeting` |
| `aldric_letter_seen` | `aldric:the_letter_glimpsed` (Insight 13) | `aldric:the_order` |
| `aldric_secret_known` | `aldric:the_true_version` | `aldric:the_order` |
| `aldric_fate` | `aldric_absolution` terminals — `"confessed"` / `"buried"` | `aldric:greeting` |
| `aldric_disappointed` | `aldric:that_is_not_why`, `aldric:he_will_stay` | `aldric:greeting` |

---

## Checks

DCs are 10 easy / 13 moderate / 16 hard / 19 very hard, and every failure goes
somewhere with a consequence rather than back to the menu:

| check | failure goes to |
|---|---|
| `mara:the_grain_talk` Persuasion 13 | `she_shuts_up` — sets `mara_wary`, she stops gossiping for the rest of the act |
| `captain:name_marrow` Persuasion 16 | `she_puts_a_watch_on_you` — a watchman follows you into every scene at the granary |
| `sera:the_confrontation` Persuasion 13 | `she_bolts` — she rides to warn Marrow; he has an hour to burn things |
| `sera:the_confrontation` Intimidation 16 | `she_calls_the_yard` — opens `granary_knives` on the spot |
| `hollis:the_offer` Persuasion 13 | `he_recalculates` — the offer is withdrawn and the dog comes in |
| `hollis:the_ash_offer` Persuasion 19 | `the_offer_withdrawn` — `ash` closes; he does not send anyone, he simply stops |
| `nakka:scouts_hold` Persuasion 13 / Intimidation 16 | `she_ends_it` — she walks off and leaves the scouts to it (`quarry_trail_ambush`) |
| `nakka:gate_hail` Persuasion 16 | `the_gate_holds` — sets `nakka_dismissed_you`, which she remembers in the hall |
| `nakka:truce_terms` Persuasion 16 | `she_wont_come_down` — the pact still exists, but only via the written manifest |
| `nakka:the_choice` Persuasion 19 | `no_more_talking` — `warlord_hall` opens |
| `garrow:the_paper` Persuasion 13/16 | `he_holds_at_ninety` — he now knows you cannot pay |
| `aldric:the_order` Insight 13 | `he_deflects` — the letter stays shut until he raises it himself |

Origin-gated options always carry the `conditions` that match the pill:
`{"origin": "rogue"}` on `sera:greeting` **ROGUE**, `{"origin": "soldier"}` on
`nakka:scouts_hold` **SOLDIER**, `{"origin": "guild_artisan"}` on
`mara:the_grain_talk` and `sera:the_confrontation` **GUILD ARTISAN**.

---

## What the art decided

Three things in this act are the shape they are because of what is on disk.

**The bestiary is renamed, not reskinned.** Three of the shipped monster art
sets were mislabelled: `skeleton` is a Gnoll, `zombie` is a Wererat, `orc` is a
Bugbear, and the packs contain no undead at all. Rather than write encounters
against art that lies, the monsters are named for what they look like — the
files are `gnoll.json`, `wererat.json`, `bugbear.json` and they keep
`sprite_key` values of `skeleton`, `zombie` and `orc` because that is where the
sheets actually live. Skeleton, Zombie and Orc do not exist in this act. The
bestiary is Goblin, Kobold, Bandit, Wolf, Giant Rat, Stirge, Gnoll, Bugbear,
Wererat, Ogre, all SRD.

The Wererat's SRD immunity to non-silvered weapons is why `ledger_and_lie`
rewards a **Silvered Shortsword**: the reward for the mill business is the
thing you need on the ledge in `ilse_deep_warren`.

`bandit` has no battler and `wolf`, `stirge` and `giant_rat` have no combat
clips, so nothing structural is built on them. Bandits appear as Finch's
collectors and Marrow's two hired men — small fights, no set pieces. The
tutorial rats and the sump stirges are deliberately the two shortest fights in
the act.

**Nakka Skarn wears the `fighter` bust.** Only four NPC sprites have more than
one expression — `fighter`, `cleric`, `ranger`, `captain`, eight each — and the
heaviest-dialogue characters had to have them. Aldric took `cleric`, Ilse took
`ranger`, Reed took `captain`. That left `fighter` for the goblin warlord,
whose art is human. Rather than ignore it, it is written in: Nakka has looted
three suits of human plate that fit her at the shoulder and nowhere else, and
she has kept the helm because she has worked out what a helm does in a doorway.
The mismatch became her characterisation. Sera, Kessa and Hollis have one
expression each and their nodes never ask for a second.

**Everything happens on three maps.** `Rivermark` (48×44), `Golden Flagon Inn`
(20×16) and `Goblin Warren` (16×12) are all that `sql/maps.sql` builds, so every
stage `target` names one of those three. That is why the granary, the mill and
the watch house are all coordinates in Rivermark rather than interiors, why the
cellar and Finch's collection are both staged in the inn, and why the deep
warren is a shaft *inside* the existing warren map rather than a fourth level.
Coming back up out of the cellar to report to Mara is a dialogue node for the
same reason: there is nowhere else to put it.

---

## Where everybody stands

Placement is authored, in the `place` block of each NPC, encounter and item —
see [`docs/CONTENT.md`](../docs/CONTENT.md#placement). Every coordinate below
is a tile `sql/maps.sql` actually builds and marks walkable; the loader refuses
to write `sql/content.sql` if one of them is not.

### The town, in tiles

Rivermark's buildings are solid blocks the party walks *around*, so a scene set
"in" one is staged on the tile at its door, its alley or its yard.

| block | tiles | what it is |
|---|---|---|
| market square | y 15–19, full width | stalls, well, fountain |
| main street | y 35–38, full width | the inn door (10,35) and the east gate (46,36) |
| `house_double` | x 25–36, y 1–14 | **the mill** — its side alley is x 23–24 |
| `house_gabled` | x 37–46, y 1–14 | **the watch house**, fronting the square |
| `hall_long` | x 3–17, y 23–34 | **the Golden Flagon**, door onto the main street |
| `hall_wide` | x 29–45, y 21–34 | **the granary** — its yard is the x 28 lane |
| east lane | x 46, y 21–34 | where the wagons stand, inside the wall |

### NPCs

| npc | map | tile | scene |
|---|---|---|---|
| `mara_hearthstone` | Golden Flagon Inn | 5, 1 | behind the bar |
| `aldric` | Golden Flagon Inn | 6, 4 | the table he has not left in a week |
| `kessa` | Golden Flagon Inn | 9, 6 | drinking, on the runner from the door |
| `garrow_finch` | Golden Flagon Inn | 12, 6 | across the room from Kessa, waiting |
| `tobin` | Rivermark | 14, 17 | the market square, among his stalls |
| `captain_elowen` | Rivermark | 38, 16 | the square, outside the watch house |
| `sera` | Rivermark | 24, 13 | the mill alley, where the books are kept |
| `hollis_marrow` | Rivermark | 28, 31 | the granary yard, on the weighing floor |
| `ilse` | Rivermark | 44, 18 | by the east signpost, come down out of the hills |
| `nakka` | Goblin Warren | 12, 7 | her hall, past the gate |

### Encounters

| encounter | map | tile | scene |
|---|---|---|---|
| `cellar_rats_swarm` | Golden Flagon Inn | 5, 12 | the store room door |
| `cellar_stirges` | Golden Flagon Inn | 3, 14 | the sump behind the partition |
| `garrow_collectors` | Golden Flagon Inn | 14, 8 | the hearth side of the common room |
| `granary_knives` | Rivermark | 28, 25 | Marrow's hired men, deep in the yard |
| `goblin_scouts` | Rivermark | 44, 36 | the main street, two tiles inside the gate |
| `hills_raiders` | Rivermark | 46, 23 | the east lane, at the wagons |
| `quarry_trail_ambush` | Goblin Warren | 3, 6 | the tunnel mouth, off the stair |
| `warren_gate` | Goblin Warren | 10, 6 | the corridor before the warlord's door |
| `warlord_hall` | Goblin Warren | 13, 8 | the hall itself |
| `deep_shaft` | Goblin Warren | 7, 9 | the lower gallery, where the floor opens |

Difficulty runs with distance from the inn door: the two cellar fights are the
shortest in the act, `goblin_scouts` sits on the road out, and `warlord_hall`
and `deep_shaft` are both behind a door and a corridor.

### Ground loot

| item | map | tile | why it is lying there |
|---|---|---|---|
| `potion_healing` | Goblin Warren | 3, 3 | a goblin's stash in the north room |
| `chain_shirt` | Goblin Warren | 7, 2 | beside the skull — warden issue, twenty years old |

### Scenes with nowhere to stand

Three stage `target` markers point at tiles that are inside a building and
therefore not walkable. They are journal markers, not placements, so nothing is
broken by them, but they do not agree with the geography above and should be
moved when the act is next revised:

| quest | stage | target | falls inside |
|---|---|---|---|
| `ledger_and_lie` | `the_paymaster` | Rivermark 12,33 | the Golden Flagon, not the granary |
| `sera_leash` | `the_second_book`, `the_loft_stair` | Rivermark 12,31 / 14,30 | the Golden Flagon, not the granary loft |
| `quarry_pact` | `east_gate` | Rivermark 41,21 | the granary; the gate is at 46,36 |

The deep warren has no floor of its own — `ilse_deep_warren` marks 8,11 and
7,10, which are solid rock below the map. The shaft is placed at the south edge
of the lower gallery (7,9) instead, which is as deep as the map goes.
