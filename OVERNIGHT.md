# Overnight build — 2026-08-01 into 08-02

Everything below is on disk, loaded into the live database, and verified. Your
save is intact and playable; the party is standing in the Golden Flagon at full
health where you left them.

---

## The short version

| | before | after |
|---|---|---|
| Quests | 8 | **61** |
| Regions | 3 | **9** |
| Locations | 18 | **73** |
| NPCs | 33 | **65** |
| Dialogue files | 30 | **62** |
| Feats | 1 (inert) | **27** |
| Companion greeting variants | 10–14 each | **39–51 each** |
| Test assertions | 705 | **38,000+** |

An overall Act I questline now runs through all of it in subtext, and there is a
printable character sheet.

---

## 1. The spine — `src/docs/ACT_ONE.md`

Act I is now *about* something. Somebody is quietly **buying the valley** —
grain contracts, debt paper, quarry writs, water rights, the watch's payroll —
because the old quarry collapsed onto something and a landowner does not need a
permit to dig on their own ground.

The tell is a surveyor's plumb-and-square mark. It is never explained. It turns
up on the grain sacks in your cellar, on Garrow Finch's debt paper, inside the
lining plate of Nakka's armour, cut fresh into the stone at the bottom of Ilse's
shaft, and on one brick of a wall that was sealed from the far side.

**The whole thing is built outward from things already in your game.** The flag
`grain_stamp_seen` already existed. Nakka already wore human plate nobody
explained. Ilse already found fresh cuts on the ledge. None of it meant anything
together. Now it does.

The goblins are re-framed: not the antagonist, the **first casualty**. Something
is taking the bottom out of their warren and pushing them onto the road. The
warden's two-year tally only makes sense read backwards — she has been counting a
retreat. 110 of the 406 are under six winters.

Five spine quests (`the_same_mark` → `paper_town` → `the_polite_man` →
`what_the_quarry_fell_on` → `the_survey`), opening through **Alder Pyke**, a
sixty-one-year-old chainman who drags a hundred feet of chain for whoever pays
and has never asked who. His answer to the mystery is the design in one line:
*"That's a survey mark. I paint about forty a season."*

**Ludo Corse is not a fight.** He answers every question honestly, tells you
nothing, does not know what is under the quarry either, and has twice written on
paper that the dig does not pay — and been instructed to continue.

Four finale resolutions feed Act II: `took` / `burned` / `sold` / `warned`.
Handoff note in `src/docs/ACT_TWO_HOOK.md`.

**The subtext rule was held throughout.** Nobody says "Concern" until the end.
Every quest resolves completely on its own terms for a player who never joins the
dots. Kessa actively dismisses the mark — *"Masons cut a mark in every brick. It
doesn't mean anything."*

---

## 2. Companions — the repetition was structural

Your companion files were already large and well written. **The problem was not a
shortage of writing**, and it is worth knowing what it actually was:

1. **`matchVariant()` returned the first passing variant.** In a stable state a
   companion had exactly one opening line, replayed forever. None of the 48
   greeting variants was marked `once`, so nothing ever retired.
2. **Openings did not read their own quest resolutions.** Kessa reacted to
   `kessa_debt_fate=sold` but not `paid` or `fought`.

Your save has `kessa_debt` completed as **`paid`**, `teeth_in_the_hills` as
**`ambushed_the_raiders`**, `ledger_and_lie` as **`burned_the_book`** — every one
a resolution no greeting read. You were living the bug.

**The fix is an engine change.** Dialogue variants now support `pool`:
interchangeable lines at one priority rung, **rotated** through a party-scoped
cursor. Rotation, not randomness — random repeats immediately about 1-in-n, which
is the complaint. Compatibility was *proved*, not argued: the harness replays the
old matching logic across every node of all 52 dialogue files × 4 synthetic
playthroughs — 2,808 node/context pairs — asserting no-pool nodes still pick
exactly what they used to.

Greeting variants went **10–14 → 39–51** per companion, with 26–32 interchangeable
lines in the settled state, and every resolution gap closed.

Three new tier-3 arcs: Kessa's debt was bought by the Concern; Sera is offered
genuinely better work and may leave; Aldric never read the foot of his own
summons. Ilse gets a dialogue-only arc — the second book under her hearthstone is
not counts, it is eleven years of who was told and what they answered.

**A shipped bug was found and fixed:** Ilse's `quarry_fate=steel` variant carried
no other condition, so it matched ahead of the variant below it. If you talked
her into staying, you got "Ilse is packing" for the rest of the act and the entire
staying branch was dead text you would never have seen.

`tools/trace_content.py --openings` is a new lint that catches this class
permanently.

---

## 3. Feats — the mechanism existed, the catalogue did not

`Feats::offerTo()`, `chooseAsi()`, `feats_json` and the level-up ceremony were all
already built and working. There was **one** feat, Grappler, because SRD 5.1
contains exactly one, and the docblock argued at length that this was the whole of
the open content.

Your note about SRD 5.2 was the unlock. **27 feats**, CC-BY-4.0, attribution added
to `about.php` beside the 5.1 notice.

**Ability Score Improvement is catalogue entry #1**, marked `repeatable`, with
`offerTo()` explicitly exempting repeatables from the already-taken filter — so it
can be chosen every level, unlike ordinary feats. The existing `applyAsi()`
validation (2 points, max two abilities, nothing over 20, retroactive HP on a CON
boundary, AC recalc on DEX) was reused untouched.

20 of 27 are genuinely wired into combat, checks and rules. The rest carry an
honest `partial` note shown on the card rather than pretending to work.

**A trap I flagged turned out to be real:** `startEncounter()` built combat rows
field-by-field and never copied `feats_json`. Every feat would have been silently
inert in combat. Caught and fixed.

Your party is backfilled — Aldric→Healer, Kessa→Savage Attacker, Sera→Alert,
Ilse→Archery. Nobody was owed a level feat; your highest character is 3 and the
first increase is at 4.

**Two decisions to review:** companions now start with an Origin feat but player
characters do not (a sixth creator step felt like the wrong thing to meet a new
player with — reversible in one line). And Grappler now grants +1 Strength,
because 5.2 changed it.

---

## 4. Print to PDF

`sheet_print.php?character_id=N`. Button on the character sheet header and on each
character card. Recognisable 5e layout: ability spine, all six saves and eighteen
skills with filled proficiency dots, passive Perception, AC/init/speed, HP, hit
dice, death-save bubbles, attacks, equipment with a coin row, personality blanks
to pen in, and Features & Traits including feats. Page two is spells — **casters
only**, so a Fighter prints one page. US Letter portrait.

I checked the rendered PDF rather than the DOM. A real pagination bug was caught
that way: a 30-item equipment list refused to split and shoved itself onto an
otherwise blank page 2.

Ownership is enforced — a player cannot print someone else's character.

---

## 5. Bugs found and fixed along the way

| Bug | Effect |
|---|---|
| `attackProfile()` tested `properties.ammunition`, content writes `{"ranged":...}` | **Every bow was swung with Strength and could not reach the back rank.** Found independently by two agents |
| `startEncounter()` never copied `feats_json` | every feat silently inert in combat |
| `Folk Hero` — *the creator's default background* — granted no skills | so did `Guild Artisan` and `Outlander`; the commonest character in the game was missing two skills |
| Ilse's `quarry_fate=steel` variant unconditioned | her entire "stayed" branch was unreachable |
| Two one-way region seams | Ford Road, Hollow Fen, Arden Priory and Greyhythe — 30 locations, ~27 quests — were unreachable from town |
| Two monsters put an 83-char note in `source` (VARCHAR(50)) | load failed halfway through |

On backgrounds: `Rules::BACKGROUND_SKILLS` is the SRD 5.2 list of sixteen; the
creator was offering 2014 PHB names that are not open content. I aliased the three
legacy names to the open backgrounds that replaced them (Folk Hero→Farmer, Guild
Artisan→Artisan, Outlander→Guide) — renames, not invented rules — and rebuilt the
creator's list. **The alias resolves skills only**: three of your dialogue choices
gate on the `guild_artisan` origin tag, which derives from the stored string, so
renaming the column would have silently closed content that is already written.
There are tests pinning that distinction.

---

## 6. Two new tools, and one bad hour

**I destroyed your quest progress and restored it.** Testing whether `content.sql`
was safe to load, I piped it at a scratch database. The file contains a literal
`USE rpg_5e;` on line 12 — it ignored my target and hit the live database,
deleting `party_quests`, `party_quest_stages`, `character_known_npcs` and
`party_items_taken`. I had taken a backup minutes earlier and restored all of it,
verified row by row. **Net damage: none.** It cost about an hour and it was my
mistake.

The useful part: it proved there was **no safe way to get new content into a
running game**, and you were about to have 53 new quests to load.

- **`tools/apply_content_safely.py`** — snapshots player tables **by content key
  rather than by id**, applies `content.sql`, restores by resolving keys against
  whatever ids the load produced. Idempotent, names its target database
  explicitly, and reports rows it genuinely could not restore instead of passing
  over them. Two bugs were found in it by testing (it doubled inventory on the
  first run). Rehearsed on a scratch copy of your save before touching live.

- **`tools/backfill_spine_marks.php`** — closes a gap the spine agent missed.
  Stage effects fire on *entry*, and you had already finished four of the five
  sighting quests, so those flags could never fire. You held one sighting; the
  opener needs two. **Nothing errors — the quest is simply never offered.** A save
  further along would have been permanently locked out of the main questline with
  no symptom. The tool derives its mapping from `quest_stages.effects_json` rather
  than hardcoding it, and asks `party_quest_stages` "was this party in the room".
  Applied: **your party now holds 4 sightings, so the spine will open.**

`load_content.py` also gained a column-width guard that reads `schema.sql`, so
"content longer than its column" now fails at validation naming the file and
field, instead of failing partway through a load with a row number in generated
SQL.

CLAUDE.md documents all of this, including the `USE rpg_5e;` trap.

---

## 7. Verification

```
test_rules           443      test_feats           151
test_checks           46      test_creation         83
test_combat           83      test_auth             39
test_content_rules   404      test_content_export   20
test_levelup          49      test_ownership        45
test_route        37,172      test_art              24
```

**0 failures.** `lint_php.py` and `lint_js.py` both clean. Content validates:
358 files, 0 errors. All 73 locations reachable from the starting inn by BFS.
32 journal rows carry proper UTF-8 em-dashes, 0 mojibake. Ownership isolation
driven over real HTTP.

Backups in `backups/` — pre-load and final, database and content tree.

---

## 8. What needs you

1. **Art — 56 of 73 locations have no PNG.** Three prompt docs are written in your
   established format: `PROMPTS_UNDERTOWN.md`, `PROMPTS_WILDS.md`,
   `PROMPTS_DEEPWORKS.md`, indexed from `LOCATION_PROMPTS.md`. Not merged
   deliberately — each reproduces the style contract so it can be handed over
   alone. A location without art is not broken; the screen is prose.
   Corse would benefit from an 8-expression bust; he is currently on `rogue` (1).
2. **Duplicate monsters.** Two agents independently wrote near-duplicates at
   different CRs: `peat_ooze`/`grey_ooze`, `bog_crawler`/`carrion_crawler`,
   `deep_gremlin`/`sump_gremlin`, `drowned_man`/`drowned_clerk`. I left both sets
   rather than guess. Your call.
3. **The two feat decisions** in §3.
4. **The opening lint reports ~200 findings** on new content — mostly "no greeting
   reacts to this ending". That is the check doing its job on brand-new quests,
   not a regression. Major fates deserve reactions; minor side quests do not. Worth
   triaging, not fixing wholesale.
5. **`region_type` has no `site` value** — the ENUM is `town|wilderness|dungeon`,
   so Arden Priory is filed as `town`. Cosmetic, but you may want the ENUM widened.

Nothing here is deployed. `deploy.sh` is still dry-run-unless-`--apply`.
