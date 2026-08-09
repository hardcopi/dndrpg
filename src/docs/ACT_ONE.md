# Act I — the design bible

> Written 2026-08-01 as the shared contract for the Act I expansion. Everything
> in `content/` that is added from here on should be traceable to something in
> this document. Where this document and a JSON file disagree, the JSON file is
> what ships — but the disagreement is worth a look, because coherence across
> forty quests is the thing that is expensive to fix later and cheap to hold now.

Act I shipped with 8 quests, 3 regions and 18 locations. That is a strong
vertical slice and a thin act. Baldur's Gate's first act carried 40–50. This
document is the plan for getting there without the world turning into a job
board.

---

## 1. The spine

### The problem a spine solves

The eight existing quests are individually excellent and collectively a list.
Each one resolves into a flag — `ledger_fate`, `hills_fate`, `quarry_fate` — and
then stops. Nothing accumulates. A player finishing all eight has had eight good
evenings and cannot say what Act I was *about*.

A spine is not a ninth quest. It is a thing the player keeps noticing across the
other forty, which they are never told and eventually work out.

### The spine: the surveyor's mark

**Somebody is buying the valley. Quietly, legally, and from underneath.**

Not conquering it — *purchasing* it. Grain contracts, debt paper, quarry writs,
water rights, and the watch's own payroll. By the time anyone in Rivermark
notices, most of what the town stands on will belong to someone who has never
visited it.

The tell is a **mark**: a surveyor's plumb-and-square, stamped in black wax or
cut into stone. It is never explained. It simply keeps turning up:

| Where the mark appears | Which existing thing it re-frames |
|---|---|
| Stamped on the grain sacks in the Flagon's cellar | `cellar_rats` — the rats came *with* the grain, and the grain is not Rivermark's |
| On Garrow Finch's debt paper | `kessa_debt` — Finch collects for a house, not for himself |
| In Hollis Marrow's second book, on every page he did not write | `ledger_and_lie`, `sera_leash` — Marrow is a middleman being paid, not a principal |
| On the human plate armour Nakka Skarn is wearing | `teeth_in_the_hills`, `quarry_pact` — somebody armed the raiders, and it was not the goblins |
| Cut fresh into the stone on the ledge, forty feet down | `ilse_deep_warren` — the cuts Ilse finds are **survey marks**, not goblin work |

The flag `grain_stamp_seen` already exists in the shipped content. The spine is
built outward from it rather than bolted on: **every one of those five is
already in the game**, and none of them currently mean anything together.

### What is actually happening

The **Corse Concern** is a mercantile house from downriver. Its factor in the
valley is **Ludo Corse** — unfailingly polite, entirely legal, and never once
present when anything happens.

The Concern is not interested in Rivermark. Rivermark is *access*. The old
quarry collapsed onto something, and the Concern has been quietly test-shafting
beneath it for three years: that is what is taking the bottom out of the goblin
warren, and why the goblins have been pushed up onto the road. The goblins are
not the act's antagonist. They are its first casualty — refugees with weapons,
which is what a refugee becomes when nobody will let them stop moving.

The Concern buys the valley because a landowner does not need a permit to dig on
their own ground, and does not have to explain what they find.

### The subtext rule

**The player is never told any of this in Act I.** They are told it in Act II.

What Act I gives them is five sightings of the same mark and, at the very end,
one document that makes the five into one thing: a survey of the valley with
Rivermark's name written in the margin as a cost, not a place.

Concretely, that means every quest below is written so that it works completely
on its own terms — a player who never joins the dots has had a good act — and
carries at most **one** unremarked detail that belongs to the spine. Nobody
explains the mark. Nobody says "Concern" out loud until `the_survey`.

### The spine quest chain

Five quests, one per act-beat, each unlocked by ordinary play rather than by
being handed out. `act: 1` on all of them.

| Key | Title | Opens when | What it actually is |
|---|---|---|---|
| `the_same_mark` | The Same Mark | the party has seen the mark in **any two** places | the journal entry that lets the player start collecting. No objective but "notice" |
| `paper_town` | Paper Town | after `the_same_mark`, level 3 | Follow the paper: who holds Rivermark's debt. Ends at the Counting House with a name nobody recognises |
| `the_polite_man` | The Polite Man | after `paper_town`, level 4 | Meet Ludo Corse. He answers every question honestly and tells you nothing. He is not a fight |
| `what_the_quarry_fell_on` | What the Quarry Fell On | after `the_polite_man`, level 4 | Down past the deep shaft into the Deepworks. The excavation is real, industrial, and staffed |
| `the_survey` | The Survey | level 5, act finale | Take the survey. Four resolutions, and they are what Act II opens on |

`the_survey` resolutions — these are the branch Act II reads:

- `took_the_survey` — the party has the document. Corse knows they have it.
- `burned_the_survey` — three years of work gone; the Concern must start again, and is now looking for who did it.
- `sold_it_back` — Corse pays, generously, and the party is now on the Concern's books. Act II opens with them holding a retainer they did not quite mean to accept.
- `warned_the_town` — Rivermark knows. The town has no power to act and now knows that too.

### Where the spine touches the eight existing quests

**No existing quest file's mechanics change.** The spine attaches by adding
*one* new stage-effect flag to each — `mark_seen_grain`, `mark_seen_paper`,
`mark_seen_plate`, `mark_seen_stone`, `mark_seen_book` — and by adding new
dialogue variants that notice. An existing save must keep working, so every one
of those flags is additive and nothing reads them as a requirement to progress.

---

## 2. The world

Three regions today. Ten at the end of Act I.

| # | `region_key` | Type | sort | Level band | Status |
|---|---|---|---|---|---|
| 1 | `rivermark` | town | 10 | 1–2 | **exists** — 10 locations |
| 2 | `undertown` | dungeon | 15 | 2–3 | new — flood tunnels under the town |
| 3 | `quarry_wilds` | wilderness | 20 | 2–3 | **exists** — 5 locations |
| 4 | `ford_road` | wilderness | 25 | 2–3 | new — west of town: the ford, the burnt mill, farms |
| 5 | `goblin_warren` | dungeon | 30 | 3–4 | **exists** — 3 locations, wants 3 more |
| 6 | `hollow_fen` | wilderness | 40 | 3–4 | new — the drowned village, smugglers, the fen road |
| 7 | `arden_priory` | site | 45 | 3–4 | new — Aldric's order. Where his quest should always have gone |
| 8 | `the_deepworks` | dungeon | 50 | 4–5 | new — the Concern's excavation. Act I's climax |
| 9 | `greyhythe` | town | 60 | 5 | new — river port downstream. The Concern's landing, and the road to Act II |

Target ~62 locations. Existing 18, so ~44 new.

**Connection rule.** Every new region hangs off an existing one by exactly one
authored two-way exit, so the map stays legible and the loader's reachability
warning stays meaningful:

```
rivermark ──── east_gate ────► quarry_wilds ──► goblin_warren ──► the_deepworks
    │                              │
    ├─ flagon_cellar ──► undertown │
    ├─ west_gate ──► ford_road ────┼──► arden_priory
    │                              │
    └─ river_stair ──► greyhythe   └──► hollow_fen
```

`west_gate` and `river_stair` do not exist yet and must be added to
`content/locations/rivermark.json` as new locations in the town.

---

## 3. The quest roster

44 quests. Eight exist; 36 are new. Keys are fixed here so that cross-references
between quests written by different hands resolve.

Level band is `required_level`. "Giver" is an `npc_key`; `—` means it starts
from a place or an event rather than a person.

### Spine (5, new)

| Key | Lvl | Giver |
|---|---|---|
| `the_same_mark` | 2 | — |
| `paper_town` | 3 | — |
| `the_polite_man` | 4 | `ludo_corse` |
| `what_the_quarry_fell_on` | 4 | — |
| `the_survey` | 5 | — |

### Rivermark — town, levels 1–2 (11: 2 exist, 9 new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `cellar_rats` | 1 | `mara_hearthstone` | **exists** |
| `ledger_and_lie` | 1 | `captain_elowen` | **exists** |
| `the_anvil_debt` | 1 | `brenna_smith` | Brenna's forge is mortgaged to someone she has never met |
| `a_short_measure` | 1 | `market_baker` | The baker's flour is light. Every sack, exactly the same amount light |
| `tobins_consignment` | 1 | `tobin` | A crate Tobin did not order, that he is being charged for |
| `the_almoners_list` | 2 | `street_almoner` | The almoner's list of the poor is growing in one direction only |
| `root_and_ash` | 2 | `osric_apothecary` | Osric needs fen-root. Nobody will go to the fen and he will not say why |
| `the_watch_payroll` | 2 | `captain_elowen` | Reed's watch has been paid on time for a year. Reed has not been paying them |
| `night_soil` | 2 | — | Something is coming up out of the drains. Opens Undertown |
| `the_seers_coin` | 2 | `street_seer` | The seer has been paid to say one particular thing |
| `a_room_for_the_night` | 1 | `mara_hearthstone` | Small, warm, no combat. A tutorial for dialogue and rest |

### Undertown — levels 2–3 (5, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_flood_gate` | 2 | — | The old flood gate has been re-hung. Recently. Well |
| `what_the_water_left` | 2 | — | The drowned strongroom of a bank that failed forty years ago |
| `the_undertown_court` | 3 | `street_cutpurse` | There is a court down here, and it would like to try you |
| `rats_and_better` | 3 | `mara_hearthstone` | Follow-up to `cellar_rats` — where the grain actually comes in |
| `the_sealed_arch` | 3 | — | An arch bricked from the far side. Spine sighting: the mark is on the brick |

### Quarry Wilds — levels 2–3 (7: 2 exist, 5 new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `teeth_in_the_hills` | 2 | `captain_elowen` | **exists** |
| `quarry_pact` | 2 | `captain_elowen` | **exists** |
| `the_wardens_count` | 2 | `ilse` | Ilse's two-year tally of goblins, and what it means read backwards |
| `stone_and_writ` | 3 | — | Who owns the quarry now. The writ has changed hands twice this year |
| `the_pit_champion` | 2 | — | The fighting pit exists and has one quest. It should have three |
| `salt_and_silence` | 3 | — | A salt caravan that will not say what it is really carrying |
| `the_last_quarryman` | 3 | — | The only man who was down there when it fell, and he will not go near it |

### Ford Road — levels 2–3 (6, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_burnt_mill` | 2 | — | The mill everyone mentions and nobody has visited since |
| `the_ford_itself` | 3 | — | Where Aldric's burning happened. Reachable long before his quest resolves |
| `harrow_farm` | 2 | `market_farmer` | A farm being bought out, politely, by letter |
| `the_drovers_road` | 2 | `street_drover` | Escort work that goes wrong in an interesting direction |
| `wolves_in_winter_field` | 2 | — | Straight combat. Not everything needs a moral |
| `the_miller_who_stayed` | 3 | — | Someone still lives at the burnt mill |

### Goblin Warren — levels 3–4 (4: 1 exists, 3 new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `ilse_deep_warren` | 3 | — | **exists** (companion) |
| `the_warrens_children` | 3 | `nakka` | There are goblin young in the warren. This is a problem for everybody |
| `nakkas_plate` | 3 | `nakka` | Where the human armour came from. Spine sighting |
| `the_bottom_of_the_warren` | 4 | — | The floor is gone. Opens the Deepworks |

### Hollow Fen — levels 3–4 (5, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_drowned_village` | 3 | — | A village under four feet of water, and why it flooded |
| `fen_root` | 3 | `osric_apothecary` | Completes `root_and_ash` |
| `the_fen_wardens` | 4 | — | Smugglers who are the only people keeping the fen road open |
| `what_floats` | 3 | — | Bodies in the fen, all facing the same way |
| `the_sluice` | 4 | — | Who opened the sluice forty years ago, and who is paying to keep it open |

### Arden Priory — levels 3–4 (4, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_order_that_threw_him_out` | 3 | — | Aldric's order, seen without Aldric's account of it |
| `the_reliquary` | 3 | — | The priory is selling its relics. To whom |
| `brother_teodor` | 4 | — | The man who wrote the letter Aldric carries |
| `the_ford_in_the_record` | 4 | — | The priory's own account of the burning. It does not match |

### The Deepworks — levels 4–5 (4, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_dig` | 4 | — | It is a work site. There are timekeepers and a canteen |
| `the_indentured` | 4 | — | Who is digging, and what they signed |
| `the_thing_under_the_quarry` | 5 | — | What the Concern is actually digging toward |
| `the_overseers_office` | 5 | — | Where `the_survey` is kept |

### Greyhythe — level 5 (4, new)

| Key | Lvl | Giver | One line |
|---|---|---|---|
| `the_landing` | 5 | — | The Concern's wharf. Legal, busy, indifferent |
| `the_factors_house` | 5 | `ludo_corse` | Corse at home. Still not a fight |
| `passage_downriver` | 5 | — | The boat to Act II |
| `what_rivermark_is_worth` | 5 | — | The finale's quiet twin: the number the town was valued at |

### Companion personal quests (7: 4 exist, 3 new)

| Key | Lvl | Companion |
|---|---|---|
| `kessa_debt` | 2 | **exists** |
| `sera_leash` | 2 | **exists** |
| `aldric_absolution` | 2 | **exists** |
| `ilse_deep_warren` | 3 | **exists** |
| `kessa_the_house_that_bought_her` | 4 | Kessa — her debt was sold *to the Concern*. Second-tier arc |
| `sera_what_she_is_for` | 4 | Sera — she is offered work by Corse, and it is better work |
| `aldric_the_true_record` | 4 | Aldric — resolves against the priory's version, not his |

**Total: 5 + 11 + 5 + 7 + 6 + 4 + 5 + 4 + 4 + 4 + 7 = 62 quest keys, of which 8 exist.**

That is more than the 40–50 asked for. Treat the roster as **prioritised**: the
spine, the companion arcs and the Rivermark/Quarry tiers are the commitment; the
Greyhythe and Deepworks tiers are the finale and matter; anything that has to be
cut is cut from the wilderness tiers, which are the most self-contained.

---

## 4. Companions

### The diagnosis (measured, not guessed)

The four companion dialogue files are large and well written — 53–58 nodes and
112–131 choices each. **The repetition the player is experiencing is not a
shortage of writing.** It has two specific structural causes:

**(a) No rotation within a state.** `DialogEngine::matchVariant()` returns the
*first* variant whose conditions pass. Kessa's greeting has ten variants, but a
party in a stable state — Kessa active, approval under 30, no personal quest
running — lands on variant `[7]` and gets the identical line on every single
visit, forever. Not one of the 48 greeting variants across the four companions
is marked `once`, so nothing ever retires either. This is the dominant cause and
it is an **engine** problem, not a content one.

**(b) Openings do not read their own resolutions.** Measured coverage:

| Companion | Greeting reacts to | Silent on |
|---|---|---|
| `kessa` | `kessa_debt_fate=sold`, `quarry_fate=steel|pact` | `kessa_debt_fate=paid`, `=fought` — **the two outcomes most players get** |
| `aldric` | `aldric_fate` both, `ledger_fate=burned|sold` | `ledger_fate=reed` |
| `ilse` | `quarry_fate` all three, `deep_shaft_cleared` | `hills_fate` entirely |
| `sera` | `sera_leash_fate` both, `sera_arrested` | `hills_fate`, `deep_shaft_fate` |

Resolving Kessa's personal quest by *paying* the debt — the good ending, the one
the player worked for — changes her opening line not at all. That is precisely
the "quest is complete and they still talk about the same thing" the user
reported, and it is a content gap with a known shape.

### The fixes

1. **Engine — variant pools.** A node may hold several interchangeable variants
   at the same condition-priority, and repeat visits rotate through them. Spec
   in §5. This is what makes a companion feel like a person rather than a
   vending machine, and it improves all 30 dialogue files at once.
2. **Content — resolution coverage.** Every companion's opening reacts to every
   resolution of their own personal quest, and to the party-level fates
   (`quarry_fate`, `ledger_fate`, `hills_fate`) they would plausibly have an
   opinion about.
3. **Content — small talk pools.** Each companion gets 4–6 interchangeable
   idle openers per major state, so the stable state stops being one line.
4. **Lint — keep it fixed.** `tools/trace_content.py` gains a check that reports
   any quest resolution or `*_fate` flag that no dialogue greeting anywhere
   reads. That is the check that would have caught `kessa_debt_fate=paid`.

### Depth

Each companion gets a **three-tier arc** rather than one personal quest:

| Tier | Opens on | Shape |
|---|---|---|
| 1 — the presenting problem | recruitment | the existing personal quest. Ships already |
| 2 — the thing underneath | tier 1 resolved + approval ≥ 15 | why the presenting problem was the shape it was |
| 3 — the choice | tier 2 resolved + level 4 | they ask the party for something that costs the party |

Tier 2 and 3 are where the new backstory lands, and all four tie to the spine —
which is how a companion arc earns its place in the act rather than running
beside it.

---

## 5. Engine change: variant pools

Required by §4(a). Small, central, and it must not break the 30 shipped
dialogue files or any save in progress.

### The format

A variant may carry a `pool` key. Variants sharing the same `pool` name within
one node are interchangeable: all of their conditions are evaluated, and one of
the passing ones is chosen — not always the first.

```json
"greeting": [
  { "conditions": [{"flag": "kessa_debt_fate", "equals": "paid"}],
    "once": true,
    "text": "..." },

  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "\"Where are we going and who's in the way,\" Kessa says." },
  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "Kessa has found a whetstone from somewhere..." },
  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "\"Ask me the thing you've been not asking me,\" she says." },

  { "text": "..." }
]
```

### The rules

- **Rotation, not randomness.** Cycle through the pool in order, remembering the
  position in a party-scoped world flag, so a player sees all of a pool's lines
  before seeing any of them twice. Random picking produces immediate repeats
  about a third of the time, which is the exact complaint.
- **Priority is unchanged.** The first *matching* thing still wins; a pool
  member is only reached if no earlier non-pool variant matched. Ordering
  semantics of existing files must not shift.
- **`once` still retires a variant**, and a retired pool member is skipped.
- **A node with no `pool` keys behaves exactly as it does today.** This is the
  compatibility requirement and it is testable.
- The rotation flag must be **party-scoped**, like every other flag, and must
  survive a save. It must also tolerate the pool changing size between
  releases — an author adding a line must not crash a save whose cursor is past
  the end.

### What must be updated together

| File | Change |
|---|---|
| `app/lib/DialogEngine.php` | `matchVariant()` / `drawnVariant()` — the pool cursor. Note `drawnVariant()` exists because re-matching mid-conversation is a real bug that was already fixed once; the cursor must not reintroduce it |
| `tools/load_content.py` | validate `pool`: members must be contiguous, must not be the unconditional fallback, and a pool of one is an authoring mistake worth a warning |
| `docs/CONTENT.md` | document `pool` in "Nodes that remember" |
| `tools/test_content_rules.php` | assertions: rotation order, wraparound, pool shrinking under a live cursor, and a no-pool node behaving identically |

---

## 6. Working agreements

These exist because several people are writing content into one tree at once and
the loader validates the tree as a whole.

- **Keys are reserved by this document.** If you need a key that is not here, add
  it here in the same commit.
- **Never renumber or re-order an existing node's `choices`.** `DialogEngine`
  identifies a spent check by its position in the authored list
  (`check_failed:<npc>:<node>:<index>`), so re-ordering silently forgives or
  re-imposes failed rolls on existing saves.
- **`content.sql` is not to be re-applied to the live database by hand.** It is
  destructive by design. See CLAUDE.md — this is the "edits keep disappearing"
  failure.
- **Validate before claiming done:** `python3 tools/load_content.py --check`
  must pass, and `python3 tools/trace_content.py` must report no new findings.
- **The database is the source of truth for live content**, and `content/` is the
  seed and export target. New content authored as files is fine — it is new, so
  there is nothing in the database to lose — but do not export over the user's
  live edits without checking.
- **Art.** Every new location and every new named NPC needs an entry in the art
  request docs (§7). Content that ships without an art entry is content that
  will look broken.

---

## 7. Art

The established workflow is `assets/images/locations/LOCATION_PROMPTS.md`: a
global style contract plus a per-location section, with named NPC busts attached
as likeness reference. It works and is not to be redesigned.

New content extends it in three files:

| File | Covers |
|---|---|
| `assets/images/locations/LOCATION_PROMPTS.md` | every new location's establishing shot — append, do not rewrite |
| `assets/images/npcs/NPC_PROMPTS.md` | new named NPCs: face, bust, and any extra expressions a dialogue file asks for |
| `assets/images/monsters/MONSTER_PROMPTS.md` | new monsters, and a note of which are art reskins of existing sheets |

**Write against the art that exists.** Three shipped monsters are already
reskins because the packs contain no undead; a new monster with no sheet is a
`sprite_key` the loader will reject.
