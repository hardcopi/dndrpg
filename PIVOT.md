# The text-game pivot — 2026-08-01

Rivermark Chronicles was a DOM tile renderer. It is now a **text game**:
menu-driven, prose-first, with clickable SVG region maps. This is what changed,
why, and where to look.

A full backup of the previous state — source and a SQL dump — is at
`/home/richard/code/rpg-backups/backup-20260731-222520/` (plus a 157 MB tarball
beside it). Nothing below is unrecoverable.

---

## The shape of it

**A region is one map view. A location is a node on it: one described scene,
with the people in it listed and the ways out named.** That is the whole model.
A character's position is `characters.current_location_id` and nothing else —
there are no coordinates anywhere in the schema.

| Before | Now |
|---|---|
| `maps`, `map_tiles`, `map_decor`, `map_links`, `npc_positions` | `regions`, `locations`, `location_exits`, `location_items` |
| `characters.current_map_id/current_x/current_y` | `characters.current_location_id` |
| `map_tiles.npc_id` | `npcs.location_id` |
| `map_tiles.encounter_id` | `encounters.location_id` + `is_ambush` |
| `map_tiles.item_id` | `location_items` (+ `party_items_taken`) |
| `quest_stages.target_map_id/x/y` | `quest_stages.target_location_id` |
| `MapEngine.php` (913 lines) | `LocationEngine.php` |
| `assets/js/game.js` (3,229-line tile renderer) | `assets/js/game.js` (~900-line driver) |
| tile map editor (`editor.js`, `MapEditor.php`, `editor/*`) | the **Places** tab in `/content.php` |
| `map/*` API group | `location/*` API group |

**Per-party state is now its own thing.** Winning a fight writes
`party_encounters_cleared`; taking loot writes `party_items_taken`; arriving
somewhere writes `party_locations_visited`; Searching out a hidden way writes
`party_exits_found`. The old code mutated the content rows themselves — nulling
an encounter off its tile — so two playthroughs trod on each other and an export
saw a world scarred by play. Content tables are now immutable at runtime.

## The world

17 locations across 3 regions, authored in `content/locations/*.json`:

- **Rivermark** (10) — Market Square, The Counting House, Tobin's Goods, The
  Anvil, The High Street, Root & Ash, Granary Row, The Golden Flagon, The Flagon
  Cellar (hidden until found), The East Gate.
- **Quarry Wilds** (4) — The Quarry Road, The Warden's Camp, The Broken Hills,
  The Warren Approach. Campable; the eastern two roll random encounters.
- **Goblin Warren** (3) — The Warren Mouth, The Deep Shaft, The Warlord's Hall.

34 exits, all reachable — the loader now *fails* on an exit pointing nowhere and
*warns* on a location no chain of exits can reach. Every one of the 33 NPCs, 10
placed encounters and 19 quest-stage targets was re-pointed from coordinates to
a location key.

Each location carries prose, an optional first-visit paragraph shown once, and
an `ambience` array — one line picked at random per render, so a scene looked at
twice is not word-for-word identical.

## Two design decisions worth knowing

**Ambushes are opt-in.** Most authored fights are opened by a line of dialogue
(`start_combat`); they merely happen to be associated with a place. Firing every
placed encounter on arrival meant walking into the Golden Flagon and being set
upon by a debt collector you had not met. `encounters.is_ambush` marks the three
fights that genuinely *are* their location — the cellar rats, the warren gate,
the warlord — and only those trigger on arrival. The loader now warns about any
encounter nothing can start: no `start_combat`, not an ambush, not random.

**Travel is multi-hop and interruptible.** Clicking any node on the chart routes
the party there over however many stops, resolving hop by hop; a location's
`random_encounter_pct` is rolled on arriving at each, and a hit stops the trip
where the fight found it. The client narrates each leg into the log, which is
now the transcript of the playthrough.

## What did not change

Combat, dialogue, the d20 ceremony, level-up, inventory, shops, quests,
companions, the rules engine and the character builder — including the paperdoll
— are untouched. Combat was always rank-based with no grid, which is most of why
this pivot was cheap. **Portraits survive**: `_face.png` in the people list and
party rail, `_bust.png` in conversation, battler art and combat clips on the
cards.

`assets/images/{tiles,decor,buildings,anim}` — about **36 MB** — is now
referenced by nothing and can be deleted whenever you like.

The `*_sheet.png` walk sheets (42 MB) are **still live**: `Paperdoll.php` bakes
one per player character and the creator's Appearance step previews it. Nothing
in the game draws a walking character any more, so that preview is arguably
showing the wrong thing — but it is showing the character you are making, which
is still the point of the step. Left alone deliberately.

## Playing it

Numbers **1–9** take an exit; **T** talk, **S** search, **L** loot, **R** rest or
camp, **F** fight, **M** map, **J** journal. Clicking a node on the region map
travels there directly. The keyboard is deliberately inert while a conversation,
a die prompt or a modal is up.

## Verification run

All green, on a database rebuilt from the files.

| | |
|---|---|
| `tools/test_route.php` | **2004 passed, 0 failed** — 17 locations, **0 unreachable pairs**, longest journey 9 hops |
| `tools/test_ownership.sh` | **46 passed, 0 failed** (with `ADMIN_PASS` set, so the admin side runs too) |
| `tools/test_combat.php` | 83 / 0 |
| `tools/test_rules.php` | 375 / 0 |
| `tools/test_content_rules.php` | 153 / 0 |
| `tools/test_creation.php` | 83 / 0 |
| `tools/test_checks.php` | 46 / 0 |
| `tools/test_levelup.php` | 48 / 0 |
| `tools/test_auth.php` | 39 / 0 |
| `tools/test_paperdoll.php` | 33 / 0 |
| `tools/test_content_export.php` | 20 / 0 |
| `tools/load_content.py --check` | clean (8 pre-existing sprite-art warnings, unrelated to the pivot) |
| DB rebuild `down -v && up -d` | all three SQL files load with no errors; reproduces 3 regions / 17 locations / 34 exits / 33 placed NPCs exactly |
| **Export round-trip** | `export_content.php` → *"nothing changed — the files already match the database"*, and `diff -r` of `content/` before and after is **empty** |
| Browser (headless Chrome, real session) | new character → first-visit prose → talk to Mara → map-click multi-hop travel → cross-region travel → keyboard 1-9/T/S/R/M → search → camp → rest gating → cellar ambush → full combat board → creation wizard. **No console errors.** |

That is **2,864 assertions passing**.

## Loose ends

- `tools/test_art.php` fails 3/1 on "there were companions to check (0 found)" —
  no party in the dev database has recruited one. Pre-existing, not a pivot bug.
- The location prose is serviceable rather than final; it is seeded from the old
  one-line map descriptions plus two or three authored sentences each. The Places
  tab exists so you can rewrite it in the browser and export.
