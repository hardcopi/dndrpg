# Handover — Act 1 build

> **This document predates the text-game pivot of 2026-08-01 and is kept as a
> record of the Act 1 build, not as current instructions.** Anything below
> about tile maps, the map editor, `sql/maps.sql`, `tools/build_maps.py`,
> `MapEngine` or 8-directional movement describes a renderer that no longer
> exists. The world is now a graph of described locations; see `CLAUDE.md`
> ("The world is a graph, not a grid"), `README.md` and `docs/CONTENT.md`.
> The rules, dialogue, quest, check and combat work described here is all still
> live and was not touched by the pivot.

Written 2026-07-28. Everything below is on disk in this repo.

**Update — most of this is now verified in a browser.** It was written blind (no
PHP, no MySQL, no network in the build environment), but it has since been run
against the live server at `localhost:8081` through Chrome. What has actually
been exercised end to end:

- All four PHP harnesses, served through FPM at `/tools/test_*.php` because
  there is no PHP CLI on the host: **646 assertions, 0 failures.**
- Character creation, ability/racial arithmetic, starting gear and AC.
- Exploration: the three-column shell, party rail, map render, action bar.
- **Dialogue**: node variants, condition-gated choices, origin tags, bust art,
  companion interjections — verified against four different NPCs.
- **The d20 ceremony**, both phases: DC, itemised modifier, live odds, the die
  landing on the server's number, the outcome branching the conversation, and
  the roll appearing in the Rolls tab.
- **Combat**: initiative ribbon, rank boxes, battler art in the inspector,
  legal-target rules, an attack resolving with correct damage, action economy
  locking the spent action.

Zero console errors throughout. Five real bugs were found and fixed this way —
they are listed under "Found by running it" below.

## Run this first

```bash
cd /home/richard/code/rpg/src

php tools/test_rules.php          # ~330 assertions, rules layer, no DB
php tools/test_checks.php         # d20 checks, no DB
php tools/test_combat.php         # rank/targeting/economy, no DB
php tools/test_content_rules.php  # conditions + effects, no DB

python3 tools/lint_php.py         # cross-reference every Class::member call
python3 tools/lint_js.py          # every API route the client calls
python3 tools/load_content.py     # validate content/ and write sql/content.sql
python3 tools/trace_content.py    # quest/flag reachability
```

The four Python tools pass clean as of writing. The four PHP harnesses are
unrun — they are the first thing to try.

Then load the database. **`schema.sql` drops every table**; on a database you
care about use `migrate_act1.sql` instead, which is idempotent and additive.

```bash
mysql -u web -p rpg_5e < sql/schema.sql
mysql -u web -p rpg_5e < sql/seed_data.sql
mysql -u web -p rpg_5e < sql/maps.sql
mysql -u web -p rpg_5e < sql/content.sql
mysql -u web -p rpg_5e < sql/migrate_editor.sql
```

## What changed

**Content moved out of SQL into `content/*.json`.** That is now the authoring
source of truth: NPCs, dialogue, quests, companions, items, monsters,
encounters. `tools/load_content.py` validates the whole tree and writes
`sql/content.sql`; it refuses to write on a dangling reference, an unreachable
dialogue node, a quest with no ending, or an unknown condition or effect key.
Maps stayed in the database because the map editor owns them.
Format and authoring guide: **`docs/CONTENT.md`**.

**Combat is rank-based.** `CombatEngine` kept its name, file, routes and state
envelope; the grid geometry is gone. Combatants have `side` and `rank`, melee
reaches only the opposing front rank until that rank is empty, and swapping
rank costs the move. Saving throws, spell slots, concentration, conditions,
advantage/disadvantage, resistance and vulnerability all now apply — none of
them existed before. Crits double dice and not the modifier.

**Dialogue branches.** A node key may hold an ordered list of variants, and the
first whose conditions match is what the NPC says — that is the whole of
"NPCs remember what you did". Choices carry conditions, origin tags, effects
and ability checks with visible DCs.

**The d20 ceremony.** Two-phase: `check/request` describes the check and what
the party may spend, the player chooses, `check/resolve` rolls server-side. The
die animation lands on the number the server already rolled. Guidance, Bardic
Inspiration, Bless and Halfling Lucky are implemented; all SRD.

**Party systems.** Four authored companions with approval, romance tracks,
personal quests, and interjections inside other people's conversations.

**UI reworked.** Three-column shell, dialogue theatre replacing the 520px modal,
rank combat board with an initiative ribbon (the old UI never showed turn order
at all), a real modal stack, `Esc` and focus traps everywhere.

## New files worth knowing

| Path | What |
|------|------|
| `docs/CONTENT.md` | How to add a quest, an NPC, a dialogue node. Start here. |
| `content/README.md` | Act 1's design doc — every flag, every DC, every ending |
| `app/lib/WorldState.php` | Party flags; the game's memory |
| `app/lib/Requirements.php` | Condition evaluation |
| `app/lib/Effects.php` | Effect application |
| `app/lib/DialogEngine.php` | Conversation resolution |
| `app/lib/CheckService.php` | The two-phase d20 |
| `app/lib/Rules.php` `Conditions.php` | SRD rules layer |
| `app/lib/CompanionService.php` | Recruit, approval, romance, thresholds |
| `assets/js/ui-{dialog,combat,check,panels}.js` | The new screens |
| `tools/lint_php.py` `lint_js.py` `trace_content.py` | Verification |

## Found by running it — fixed

1. **All of Act 1 was standing nowhere.** `maps.sql` placed NPCs by hardcoded
   numeric id pointing at seed rows; `content.sql` replaced those rows but never
   placed the new ones. Every authored NPC and encounter existed in the database
   and was on no tile. Fixed by making placement content's job — a `place` block
   on NPCs, encounters and items, validated at import against real walkability.
2. **Seed and content both loaded.** The loader deletes by key and seed rows have
   NULL keys, so Skeleton/Zombie/Orc survived alongside Gnoll/Wererat/Bugbear and
   the old fetch-quests sat on the job board next to the authored ones. Seed data
   no longer owns any table `content/` owns.
3. **No armour added Dexterity.** The item files used `adds_dex`/`dex_max` while
   `CharacterGenerator::recalculateAC()` reads `dex_bonus`/`dex_bonus_max`, so
   leather was a flat 11 and chain shirt a flat 13 for every character in the
   game. `properties` is a free-form blob, so nothing caught it.
4. **Every HP bar rendered red at full health.** `.ok`/`.mid`/`.low` had no CSS
   at all — deleted with the old `.map-hpbar` and never re-added — and the party
   rail and sheet never applied a band class anyway. Now one `hpBand()` helper
   feeds all three call sites.
5. **No cache-busting on JS or CSS.** The tile art has had `TILE_CACHE_VER` since
   the beginning but the code that draws it had nothing, so a returning player
   silently kept stale scripts. `game.php` now versions every asset by
   `filemtime`.
6. **The dialogue close button overlapped the prose**, eating the last word of
   the first two lines of any long speech.

## Character names and retirement

Living characters have unique names; retiring somebody frees theirs again. The
constraint hangs off a generated column (`characters.active_name`) that is NULL
for a retired row, because NULLs do not collide in a unique index — a plain
`UNIQUE(name)` would reserve every name anyone ever used forever.

Authored companion names are reserved too, from the `companions` table, so a
player cannot name themselves Kessa Dunmar and then have her recruitment fail
on a constraint in the middle of the scene where she agrees to join.

New routes: `character/rename`, `character/retire`, `character/restore`.
Retirement is a soft delete (`is_active = 0`, dropped from `character_party`)
because quests, flags, the roll log and finished combat sessions all reference
the row by id. `restore` puts them back, name permitting.

**Before running `sql/migrate_act1.sql`:** this database currently holds four
characters called "Elara Stormwind" (ids 1, 2, 3 and 5). The migration renames
duplicates before adding the index — oldest keeps the plain name, the rest
become "Elara Stormwind (2)" and so on. If ids 1–3 are abandoned test
characters, retiring them first is tidier than letting them be renamed:

```sql
UPDATE characters SET is_active = 0 WHERE id IN (1,2,3);
DELETE FROM character_party WHERE character_id IN (1,2,3);
```

## Overnight session — quest navigation, art, combat balance

**Quest navigation.** `MapEngine::routeTo()` is a breadth-first search over
`map_links`: given where the party is and where a quest wants them, it returns
either the destination tile or the doorway that leads toward it, plus the maps
in between. The tracker now prints directions ("Exit to Rivermark · Rivermark →
Goblin Warren") instead of only a destination name, and pins are drawn on the
map — a diamond when you are there, an arrow on the door when you are not.
Verified by `tools/test_route.php`: 42 assertions over every map pair.

**Twelve of nineteen quest targets pointed inside buildings.** Harmless while a
target was only journal prose; not harmless once it became a pin telling the
player where to walk. All snapped to the nearest reachable tile, and
`load_content.py` now holds a stage `target` to the same walkability standard as
a `place` block, so it cannot recur.

**Art: 25 actors to 69.** All fourteen townfolk, the three dungeon prisoners,
Saurial, the remaining fifteen dungeon monsters, six animals and all eight
bosses. Plus 41 battlers and 129 combat clips.

Two things worth knowing about how that ran:

- `slice_assets.py` gained `--only STAGE` and `--actors KEY`. Cutting everything
  reads 2.8 GB and cannot finish inside a short-lived shell; naming a stage is
  how you add one actor without re-reading art that has not changed.
- Art keys now describe the picture, not the stat block. `skeleton`, `zombie`
  and `orc` are gone — the sheets are a Gnoll, a Wererat and a Bugbear, and the
  packs contain no undead at all. `content/monsters/*.json` was repointed.

**Combat.** Monsters no longer all pick the same target: `scoreOption()` was
deterministic, so four identical rats computed identical scores and every one of
them swung at whoever was first in the list, with the wounded and killing-blow
bonuses making them focus harder as that character died. There is now a
per-round record of who has been attacked and a small deterministic jitter.
Separately, the client rendered the board *before* playing the animation, so a
round's damage landed in one frame and the floating numbers were decoration —
playback now runs first and each blow steps its own target's bar.

**Bonus actions exist now.** `bonus_used` was set every turn and spent by
nothing. Fighter's Second Wind, Barbarian's Rage and Rogue's Cunning Action.

## Known gaps, in the order I would fix them

1. **`combat_sessions.pending_check_json` is never written.** Mid-combat saving
   throws roll inline and emit a `save` event rather than opening the d20
   ceremony. `ui-combat.js` has the hook for it and it is dead code. Either
   wire the engine to suspend on a save, or delete the hook.
2. **The spell slot table is duplicated** in `ui-combat.js` from
   `Rules::slotTable`. No route serves it. Add one to `meta/` and delete the
   copy before they drift.
3. **Ranged reach is guessed for non-active party members** — only the session
   character's inventory is loaded into combat state, so the client cannot
   prove another member has a bow. Put the equipped weapon in the combatant.
4. **`bonus_used` is displayed but nothing costs a bonus action yet.**
5. **`open_shop` resolves nothing** — no table holds a shop. The loader
   deliberately only checks its shape.
6. **`character_quests` is now dead.** Nothing reads or writes it; quests moved
   to `party_quests`. Drop it once you are confident.
7. **`wolf` has no bust art** and `wolf`/`stirge`/`giant_rat` have no combat
   clips — the packs ship none for beasts. `bandit` has no battler. The UI falls
   back, but the fallbacks are unexercised.

## Things that will bite you

- **`natural` is a MySQL reserved word.** The `roll_log` column is backticked
  for that reason. Do not unquote it.
- **`tools/build_maps.py` still overwrites edited maps.** Unchanged behaviour —
  use the editor's Export SQL and reload it after `maps.sql`, as before.
- **`migrate_act1.sql` duplicates seven table bodies from `schema.sql`.** They
  must be edited together; `load_content.py` fails loudly if they drift.
- **Content keys are never numeric ids.** Re-importing reassigns ids; a JSON
  file naming one would silently repoint after any edit.
