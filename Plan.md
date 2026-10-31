# Rivermark Chronicles: prioritized plan

_Written by Codex (ChatGPT) from a read-only review of this repo at f86a9d7, Oct 30 2026; citations spot-checked. Movement footprint fix (#15) and UI polish (#13) landed after the review._

## Top recommendations

1. **Ship the next story beat:** Act I has a detailed finale and Act II handoff, but no Act II campaign content in `src/content/quests/` yet. Turn the four `survey_fate` outcomes into distinct, playable openings.
2. **Give the player a satisfying solo loop between story beats:** connect authored quests, dangerous travel, combat, rest, loot, and party choices with clear goals and consequences. The systems exist, but they need to feel like one adventure rather than separate modes.
3. **Make rewards more memorable:** improve the connection between named gear, build choices, and combat tactics. The content already has distinctive items and the rules support feats, spells, and character progression.
4. **Make the content easier to navigate and finish:** audit the 86 quests and their endings, follow-up reactions, targets, and rewards; prioritize major outcomes over exhaustive acknowledgement of every side quest.
5. **Polish the first hour and returning-player experience:** teach the prose/map controls, explain what to do next, and make the journal and travel log useful as a concise adventure recap.
6. **Reduce release risk before adding more content:** reconcile stale docs, strengthen the safe content-update and deployment workflow, and keep the current static and database-backed checks easy to run.
7. **Treat art as a targeted content pass:** the current authored locations are prose-first, and `OVERNIGHT.md` once reported 56 of 73 locations without art (the tree now has 148 locations, so re-count before planning art). Add art where it improves recognition or emotional impact, beginning with hubs and story-critical scenes.

## What is already in place

This is not a blank-slate RPG. `README.md` describes a prose-first browser game whose world is a graph of locations, with PHP handling rules and state and JavaScript drawing scenes and interactions. `src/api/index.php` routes requests to handlers such as `handle_location()`, `handle_dialogue()`, `handle_combat()`, and `handle_quest()`. `LocationEngine::getState()` supplies the scene and region map; `location/travel` resolves journeys on the server.

The content set is substantial: this review counted 19 location JSON files, 108 NPC files, 105 dialogue files, 86 quests, 65 encounters, 94 items, and 8 companions under `src/content/`. The campaign design in `src/docs/ACT_ONE.md` and the follow-on notes in `src/docs/ACT_TWO_HOOK.md` give the setting a coherent commercial-conspiracy mystery and four explicit finale branches. `src/content/README.md` documents an earlier Act I; read it as historical design context where its roster differs from the current tree.

Core systems are also broad. `CombatEngine` handles tactical combat; `CheckService` and `DialogEngine` support ability checks and branching conversations; `QuestService` tracks party-scoped quest stages; and `LevelUpService`, `InventoryService`, `Spellbook`, and `CompanionService` support progression and party management. `DelveEngine` and `DungeonGen` create temporary floors, while `PitEngine` offers repeatable fights.

The project has a substantial verification toolkit, including `src/tools/verify.sh`, content import and trace scripts, and many focused PHP harnesses. The notes report thousands of passing assertions from earlier verification runs. Those reports document past runs; this review did not run tests or inspect the live site.

## 1. Biggest gameplay and content gaps

### The most important gap is the next playable chapter

The repository contains a detailed Act II handoff, but the quest files visible under `src/content/quests/` end at the Act I and module content. `ACT_TWO_HOOK.md` defines what `took`, `burned`, `sold`, and `warned` mean; those are strong starting conditions, not playable outcomes yet.

**Recommendation:** build a compact Act II opening that recognizes each `survey_fate` and makes the difference visible in the first few scenes: who trusts the party, what resources or obligations they have, and what problem arrives first. Carry forward the flags identified in `src/docs/ACT_TWO_HOOK.md`, then give the chapter its own local resolution before expanding its geography.

**Pointers:** `src/docs/ACT_TWO_HOOK.md`; `src/docs/ACT_ONE.md` (“The spine quest chain”); `src/content/quests/the_survey.json`; `src/content/quests/what_the_quarry_fell_on.json`; `src/content/dialog/`; `src/content/locations/`; `World/` gazetteers and maps.

### The campaign has many quests; its strongest arcs need a clear path

With 86 quest files, the main risk is not too few errands. It is that players may not recognize which threads are central, what they have learned, or where a decision leads. The design notes already call for `the_same_mark` to emerge from evidence gathered across multiple quests. That kind of discovery needs strong in-game support without spelling out the mystery too early.

**Recommendation:** review each major quest chain as a player journey: how it becomes available, what clue or choice advances it, how it can fail or conclude, what changes afterward, and what the player is meant to pursue next. Protect the intended subtext in Act I, but use journal language and character reactions to make the next playable step clear.

**Pointers:** `src/docs/ACT_ONE.md`; `src/content/quests/the_same_mark.json`, `paper_town.json`, `the_polite_man.json`, `what_the_quarry_fell_on.json`, and `the_survey.json`; `src/app/lib/QuestService.php`; `src/app/lib/LocationEngine.php` (`hasWorkFor()`, `offeredQuestKeys()`).

### A single-player party needs useful reasons to make choices

The game supports a party of up to four and eight companions, with approval, romance, personal quests, and interjections. The story’s own companion design recognizes competing values: Kessa, Aldric, Sera, and Ilse can respond differently to the same resolution. That is a strong solo-RPG feature, but it depends on those reactions being visible and consequential.

**Recommendation:** prioritize companion reactions to major campaign outcomes, recruitment decisions, and personal-quest endings. Add occasional useful interjections or practical benefits to make party composition matter beyond filling combat ranks. `OVERNIGHT.md` notes that many opening-variant lint findings concern missing reactions to endings; triage these by narrative importance instead of trying to acknowledge all 86 quests equally.

**Pointers:** `src/app/lib/CompanionService.php`; `src/app/lib/DialogEngine.php`; `src/content/companions/`; `src/content/dialog/`; `src/docs/ACT_ONE.md` (“Companions”); `src/tools/test_dialogue_lint.php`; `src/tools/test_companions.php`.

### Loot and advancement need to reinforce one another

There is an established inventory and equipment system, authored quest rewards, level-ups, feats, and spell selection. The design bible even links silvered weapons to the Wererat’s damage immunity. Those are the right ingredients for rewarding preparation and giving loot a reason to matter.

**Recommendation:** curate reward arcs around upcoming challenges and character builds: telegraph a useful property through fiction, let the player find or earn the item, and ensure it has a meaningful use soon afterward. Review repeated low-impact rewards and fights whose only practical value is XP. Make sure a quest reward is distinct from a shop purchase or generic combat drop.

**Pointers:** `src/app/lib/InventoryService.php`; `src/app/lib/GeneralStore.php`; `src/app/lib/LevelUpService.php`; `src/app/lib/Feats.php`; `src/app/lib/CombatEngine.php` (`namedLoot()`, `grantXpAndGold()`); `src/content/items/`; `src/content/encounters/`; `src/content/quests/`.

## 2. Deepen the core loop

### Exploration

`LocationEngine` makes places, exits, travel, searching, loot, rest, hidden exits, and arrival encounters part of one server-backed model. Travel can be interrupted, while party-specific visit and discovery state is tracked separately from authored content. This is a solid basis for exploration, but location prose and actions need to offer enough variation that revisiting places remains worthwhile.

**Recommendation:** add authored, state-sensitive scene details at key locations: a new detail after a quest resolution, a changed NPC presence, an unlocked route, or a resource to discover. Make search results and hidden exits legible as meaningful discoveries. Reserve random travel encounters for places where danger makes sense, and make travel choices offer a real tradeoff in safety, distance, or opportunity.

**Pointers:** `src/app/lib/LocationEngine.php` (`getState()`, `locationPayload()`, `exitsFrom()`, `regionMap()`); `src/app/lib/WorldState.php`; `src/app/lib/Requirements.php`; `src/content/locations/`; `src/content/dialog/`; `src/assets/js/ui-map.js`.

### Combat

`CombatEngine` contains substantial rules support, including movement, attacks, spells, conditions, reactions, death saves, monster AI, and loot resolution. Combat is a major strength, but a broad rules engine does not automatically make every fight distinct or satisfying.

**Recommendation:** design encounters around a tactical or narrative identity: a positional problem, a hazard, a protected objective, an opportunity to parley, or an enemy with a recognizable behavior. Use the server’s combat geometry and action offers to communicate available tactics clearly. Review pacing and difficulty across the opening levels, especially fights that can occur on travel or on arrival.

**Pointers:** `src/app/lib/CombatEngine.php` (`startEncounter()`, `action()`, `deriveUi()`, `runMonsterAI()`, `checkEnd()`); `src/app/lib/BattleGrid.php`; `src/app/lib/BattleMapGen.php`; `src/app/lib/EncounterBudget.php`; `src/content/encounters/`; `src/assets/js/ui-combat.js`, `ui-battlemap.js`, and `ui-check.js`.

### Loot and progression

The game offers item use and equipment, spellbooks, feats, ability improvements, and level-up ceremonies. The Undervault also provides a repeatable delve and the Proving Yard offers repeatable fights. These systems can turn a quest into a satisfying cycle of preparation, risk, reward, and recovery.

**Recommendation:** make the rewards and risks of repeatable content explicit, and prevent it from overshadowing authored quests. The design note in `CLAUDE.md` already says the pit should pay the same XP and gold as equivalent monsters elsewhere, rather than being the best grind. Revisit that principle with actual play pacing: ensure the player has a reason to explore authored content, and that the repeatable modes remain fun when the player wants a fight or a delve.

**Pointers:** `src/app/lib/PitEngine.php` (`offer()`, `toLevel()`); `src/app/lib/DelveEngine.php` (`writeQuest()`, `markDepth()`); `src/app/lib/DungeonGen.php`; `src/app/lib/LevelUpService.php`; `src/assets/js/ui-levelup.js`; `src/content/quests/uv_*.json`.

### Quests and story

`QuestService` models quests as stages moved by effects, rather than assuming every quest is a linear checklist. That flexibility supports branching and emergent quest starts, but also makes a content audit important: progression can be blocked by a missing effect, an unreachable location, an unrecognized resolution, or a stage marker that points somewhere confusing.

**Recommendation:** create a player-facing “what I know / what I can do next” summary for tracked quests and major clues. Audit major endings for follow-up dialogue, changed locations, reward delivery, and clearly recorded resolutions. Preserve the layered mystery: present reminders as observations and leads, not as an omniscient explanation.

**Pointers:** `src/app/lib/QuestService.php` (`start()`, `advance()`, `arriveAt()`, `postable()`); `src/app/lib/Effects.php`; `src/app/lib/WorldState.php`; `src/assets/js/ui-panels.js`; `src/content/quests/`; `src/tools/trace_content.py`; `src/tools/check_quest_reachability.php`.

## 3. UX and polish

### Help the player get oriented

The controls are discoverable to a returning player, but a new player must learn a prose-first interaction model: click exits, people, actions, map destinations, and keyboard shortcuts; use the journal, talk, search, loot, rest, and combat controls. `src/README.md` contains a useful MVP loop, but it describes old grid exploration and is stale in several places.

**Recommendation:** build a brief, skippable in-game tutorial around the opening cellar quest. Teach one interaction at a time, make keyboard shortcuts visible in context, and clearly explain the map as a travel chart rather than a walkable grid. Keep the tutorial integrated with Mara and the opening story rather than adding a detached help screen.

**Pointers:** `src/assets/js/game.js` (scene driver and key handling); `src/assets/js/ui-panels.js` (journal and overlays); `src/assets/js/ui-map.js`; `src/content/quests/cellar_rats.json`; `src/content/dialog/`.

### Make the journal a useful adventure record

The game’s log is explicitly treated as a playthrough transcript in `game.js`, while the journal and quest tracker show campaign state. A transcript is useful when short, but can become hard to scan after many conversations, travel legs, and combat events.

**Recommendation:** provide a compact “recent events” recap and a clue/lead section for story-critical discoveries. Keep the full log for detail, but make it easy to return from a long break and understand where the party is, what they last did, and what changed.

**Pointers:** `src/assets/js/game.js` (`log()`); `src/assets/js/ui-panels.js` (journal and log panels); `src/app/lib/QuestService.php`; `src/app/lib/WorldState.php`; `src/content/quests/`.

### Tighten presentation and accessibility in the main flows

The existing client already includes reduced-motion handling, focus traps, escape behavior, modal stacking, and semantic dialog roles in `ui-panels.js`; preserve these as new features are added. The game’s core is prose, so typography, line length, portrait treatment, and readable mobile layouts directly affect play.

**Recommendation:** review character creation, dialogue, combat, map, and journal together at mobile widths and with keyboard navigation. Check text contrast, focus visibility, button labels, and whether important battle state is conveyed by text as well as color. Treat art generation and portrait consistency as a narrative presentation pass, not just a missing-image count.

**Pointers:** `src/assets/css/style.css`, `tokens.css`, `game-amiga.css`, `landing.css`, `src/assets/js/create.js`, `ui-dialog.js`, `ui-combat.js`, `ui-map.js`, `src/app/lib/Paperdoll.php`.

### Align scene prose and visuals

`World/` contains maps, gazetteers, JSON, and explorer tools for Waerhaven and Sundermere world-building, while the playable locations are authored under `src/content/locations/`. Keep those roles clear: world-building material is useful reference, but it is not automatically in-game content.

**Recommendation:** use the region gazetteers and map assets to guide a consistent visual pass on the playable hubs and consequential story locations. Prioritize scenes where a portrait, location plate, or readable map adds context; do not attempt to generate an image for every minor room before the story and scene prose are settled.

**Pointers:** `World/waerhaven-gazetteer.md`, `World/sundermere-gazetteer.md`, `World/waerhaven-map.svg`, `World/sundermere-map.svg`; `src/content/locations/`; `src/tools/gen_location_art.py`, `gen_region_map.py`, `gen_npc_art.py`, `gen_monster_art.py`, `gen_module_cover.py`, `slice_assets.py`, `cut_face.py`.

## 4. Technical debt and growth risks

### Content update and deployment safety

`deploy.sh` is explicitly dry-run by default and requires typing `deploy` after `--apply`; it omits `--delete`. It also excludes `tools/`, because public-web-root tools can mutate production data. Preserve that guard. The content authoring workflow is database-first for editable content, while JSON is the seed/export representation; `tools/apply_content_safely.py` exists to apply content without losing player state.

**Recommendation:** establish a rehearsed release checklist that exports or validates authored content, checks migrations before deploying code that depends on them, and verifies production-facing routes after release. Keep production data changes and code deployment separate, and use the safe content application tool for updates to an active game.

**Pointers:** `deploy.sh`; `src/tools/apply_content_safely.py`; `src/tools/load_content.py`; `src/tools/export_content.php`; `src/sql/schema.sql`; `src/sql/migrations/`; `docker-compose.yml`; `docker/php/Dockerfile`.

### Resolve stale and conflicting documentation

The repository’s documentation does not all describe the same version. `HANDOVER.md` itself warns that its map and tile sections predate the text pivot. `src/README.md` still describes grid exploration and setup that conflicts with root `README.md`. `CLAUDE.md` also contains operational notes and historical incident details that should remain clearly separated from current player-facing and contributor instructions.

**Recommendation:** mark historical material prominently, make the root README the current contributor entry point, and replace stale setup and game-loop text in `src/README.md`.

**Pointers:** `README.md`; `HANDOVER.md`; `PIVOT.md`; `OVERNIGHT.md`; `CLAUDE.md`; `src/README.md`; `docs/CHARACTER_CREATOR.md`; `src/tools/load_content.py`; `src/content/README.md`.

### Keep the system manageable as content grows

Content is imported from JSON, but database-backed authoring and exported files are also part of the documented workflow. This creates a source-of-truth risk if edits or releases do not follow the same path. The router and engines live in a large front-controller PHP application without Composer dependencies or a formal test framework. The existing lint and script harnesses mitigate that risk but depend on contributors knowing which scripts apply to each change.

**Recommendation:** document the authoritative path for each content category and make one repeatable verification command the normal pre-release path. As the project grows, split oversized routing or combat responsibilities only where a concrete maintenance pain appears; avoid architectural rewrites that interrupt content production.

**Pointers:** `README.md` (“Content, and where truth lives”); `src/api/index.php`; `src/app/lib/CombatEngine.php`; `src/tools/verify.sh`; `src/tools/lint_php.py`; `src/tools/lint_js.py`; `src/tools/trace_content.py`.

## 5. Quick wins

All items below are scoped to less than one day each.

| Quick win | Effort | Concrete pointers |
|---|---:|---|
| Repair the stale `src/README.md` game loop, PHP version, and content/setup guidance. | 2–4 hours | `src/README.md`, `README.md`, `PIVOT.md` |
| Add a compact “return to play” recap in the journal from the existing log and active quest state. | 4–8 hours | `src/assets/js/game.js`, `src/assets/js/ui-panels.js`, `src/app/lib/QuestService.php` |
| Add contextual keyboard hints beside the opening scene’s talk, search, travel, and journal actions. | 3–6 hours | `src/assets/js/game.js`, `src/assets/js/ui-map.js`, `src/content/quests/cellar_rats.json` |
| Audit the major quest endings for missing follow-up dialogue, confusing targets, and reward clarity. | 6–8 hours | `src/content/quests/`, `src/content/dialog/`, `src/tools/trace_content.py`, `src/tools/check_quest_reachability.php` |
| Art and prose polish pass for the starting inn, Market Square, and Golden Flagon Cellar. | 4–8 hours | `src/content/locations/`, `src/tools/gen_location_art.py`, `src/assets/js/game.js` |
| Add a deployment note/check that flags code changes relying on unapplied migrations. | 3–6 hours | `deploy.sh`, `src/sql/migrations/`, `README.md`, `src/tools/verify.sh` |
| Triage major-ending companion reaction gaps from the dialogue lint rather than treating every side-quest finding equally. | 4–8 hours | `src/tools/test_dialogue_lint.php`, `src/content/dialog/`, `src/content/companions/`, `src/docs/ACT_ONE.md` |

## 6. Phased roadmap

Effort estimates are approximate solo-development time for design, implementation, and content integration. They exclude artwork generation queues, production deployment windows, and extended player feedback.

### Phase 1 — Make the current adventure coherent and easy to return to (1–2 weeks)

| Item | Effort | Deliverable | Pointers |
|---|---:|---|---|
| Establish a current source-of-truth and verification guide for content, docs, and release steps. | 1 day | Current README; clear database/JSON ownership; reproducible pre-release checklist. | `README.md`, `src/README.md`, `CLAUDE.md`, `src/tools/verify.sh`, `src/tools/apply_content_safely.py` |
| Audit main quest paths and all four Act I finale outcomes. | 2–3 days | A player-path matrix from opening through finale, covering availability, failure, clue, reward, aftermath, and next step. | `src/docs/ACT_ONE.md`, `src/docs/ACT_TWO_HOOK.md`, `src/content/quests/`, `src/content/dialog/`, `src/tools/check_quest_reachability.php` |
| Improve first-hour onboarding and journal recap. | 1–2 days | A tutorial integrated with Mara/cellar, contextual controls, and a concise “where you left off” panel. | `src/content/quests/cellar_rats.json`, `src/content/dialog/`, `src/assets/js/game.js`, `src/assets/js/ui-panels.js` |
| Tune opening progression and encounters. | 2–3 days | A measured level 1–3 sequence of fights, rest opportunities, loot, and level-up beats. | `src/content/encounters/`, `src/content/items/`, `src/app/lib/EncounterBudget.php`, `CombatEngine`, `src/app/lib/LevelUpService.php` |
| Prioritize reactions to the most consequential quest endings. | 2–3 days | Major NPCs and companions acknowledge the player’s defining choices; lower-priority lint findings documented. | `src/content/dialog/`, `src/content/companions/`, `src/tools/test_dialogue_lint.php`, `src/tools/trace_content.py` |

### Phase 2 — Deliver a memorable next chapter (3–6 weeks)

| Item | Effort | Deliverable | Pointers |
|---|---:|---|---|
| Write and implement Act II’s opening, gated by all four `survey_fate` values. | 1–2 weeks | Four recognizably different openings that converge on a compelling local problem without erasing the branch. | `src/docs/ACT_TWO_HOOK.md`, `src/content/quests/the_survey.json`, `src/content/quests/`, `src/content/dialog/`, `src/content/locations/` |
| Add one tightly scoped new region and quest chain. | 1–2 weeks | A compact playable chapter with exploration, at least one investigation path, a tactical set piece, and a consequential ending. | `src/docs/ACT_TWO_HOOK.md`; region/location schemas in `src/sql/schema.sql`; `src/content/locations/`, `quests/`, `encounters/`, `dialog/` |
| Add targeted visual identity to the new chapter and revisited hubs. | 3–5 days | A small set of consistent location plates, maps, and portraits for high-value scenes. | `src/tools/gen_location_art.py`, `gen_region_map.py`, `gen_npc_art.py`, `slice_assets.py`, `src/assets/js/game.js` |
| Tie reward items to decisions and future encounters. | 3–5 days | Several memorable rewards with clear, tested uses, including noncombat or investigative utility where supported. | `src/content/items/`, `quests/`, `encounters/`; `src/app/lib/InventoryService.php`; `src/app/lib/Effects.php` |
| Add save-compatible, focused regression coverage for branch handoff and consequence delivery. | 2–4 days | Harnesses for each ending’s flags, journal state, NPC reaction, and Act II availability. | `src/tools/test_quest_module.php`, `test_quest_marks.php`, `test_dialogue_lint.php`, `src/tools/trace_content.py` |

### Phase 3 — Make the world support a lasting solo campaign (6–12 weeks)

| Item | Effort | Deliverable | Pointers |
|---|---:|---|---|
| Extend the campaign beyond the first major chapter based on actual play feedback. | 3–6 weeks | A second chapter with its own conflict and a few durable consequences from Act I, rather than only more locations and quests. | `src/docs/ACT_TWO_HOOK.md`, `src/content/quests/`, `src/content/locations/`, `src/content/dialog/` |
| Build more authored variety into exploration and delve runs. | 1–2 weeks | More meaningful discoveries, room interactions, and quest variations while preserving generated-floor readability. | `src/app/lib/DungeonGen.php`, `src/app/lib/DelveEngine.php`, `src/app/lib/DoorEngine.php`, `src/app/lib/FurnishingEngine.php`, `src/content/quests/uv_*.json` |
| Make character builds feel distinct through curated loot and encounter design. | 1–2 weeks | Better interaction between feats, spells, class abilities, and challenge types over multiple levels. | `src/app/lib/Rules.php`, `Feats.php`, `CombatEngine.php`, `LevelUpService.php`; `src/content/items/`, `encounters/` |
| Refine mobile, accessibility, and performance on the complete play loop. | 1–2 weeks | Consistent small-screen behavior, readable battle information, keyboard and reduced-motion support across core screens. | `src/assets/css/style.css`, `tokens.css`, `ui-dialog.js`, `ui-combat.js`, `ui-map.js`, `ui-panels.js` |
| Formalize release and save-data compatibility as content expands. | 3–5 days initial, then ongoing | A safe release path that validates content, verifies required migrations, applies content safely, and checks deployment outcomes. | `deploy.sh`, `src/tools/apply_content_safely.py`, `src/tools/verify.sh`, `src/sql/migrations/`, `docker-compose.yml` |

## Recommended order

First fix the contributor and release documentation, then audit and polish the existing Act I path, especially the tutorial, journal, and major consequences. Next ship the four-branch Act II opening. Expand the world only after that opening gives the player a clear reason to travel, fight, investigate, and care about what happens next.