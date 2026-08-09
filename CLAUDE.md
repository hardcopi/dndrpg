# CLAUDE.md

Local development environment for **rpg** (Rivermark Chronicles — Open 5e RPG),
which runs in production at https://rpg.five-star.com on the host `hermes`.

## Layout

- `src/` — the site itself, mirrored from `hermes:/home/www/rpg`. **All app code edits go here.**
- `docker/` — local stack definition (nginx vhost, PHP image, DB seeding). Not deployed.
- `pull.sh` / `deploy.sh` — sync with production.

## Accessing the nginx and MySQL servers

Both run as Docker containers **on the host**. How you reach them depends
entirely on where you are executing — read the right section, they are not
interchangeable.

### Connection facts

| | Value |
|---|---|
| Site (nginx) | port **8081** (8080 is held by an unrelated `llama-server` container) |
| MySQL | port **3307** on the host, **3306** inside the compose network |
| Database | `rpg_5e` |
| User / password | `web` / `devpassword` |
| Host inside compose network | `db` |

### If you are running on the host (Code tab / local agent mode)

Everything works directly. This is the easier context — prefer it for this project.

```bash
cd /home/richard/code/rpg

docker compose up -d               # start the stack
docker compose ps                  # what's running
docker compose logs -f php         # tail PHP errors (most useful log)
docker compose logs -f web         # nginx access/error log
docker compose down                # stop

curl http://localhost:8081/                       # the site
curl 'http://localhost:8081/api/?r=meta/races'    # an API call

docker compose exec db mysql -uweb -pdevpassword rpg_5e        # interactive
docker compose exec -T db mysql -uweb -pdevpassword rpg_5e \
    -e "SHOW TABLES;"                                          # one-shot
```

### If you are running inside a Cowork VM

**`docker` does not exist in the VM and the Docker socket is not shared into it.
Every `docker compose ...` command above will fail.** The VM is a separate KVM
guest; editing files works (the project is shared over virtiofs) but the
containers are not yours to control from in there.

`localhost` inside the VM is *the VM*, not this machine. QEMU gives the guest
user-mode ("slirp") networking, where the host is the gateway at **10.0.2.2**:

```bash
curl http://10.0.2.2:8081/                        # the site
curl 'http://10.0.2.2:8081/api/?r=meta/races'     # an API call

mysql -h 10.0.2.2 -P 3307 -uweb -pdevpassword rpg_5e   # if a client is installed
```

If no `mysql` client is present in the VM, query through the app's HTTP API
rather than installing one — that also exercises the code path you're changing.

To start, stop, rebuild, or read logs from the containers, **ask the user to run
it on the host**; you cannot do it from the VM.

### After changing things — what needs a restart

| Changed | Action |
|---|---|
| PHP / HTML / assets in `src/` | nothing — `src/` is bind-mounted, edits are live |
| `docker/nginx/default.conf` | `docker compose restart web` |
| `docker/php/Dockerfile` | `docker compose up -d --build php` |
| Anything in `src/sql/` | full DB rebuild — see **Database** below |
| `docker-compose.yml` | `docker compose up -d` |

## Stack — matches production deliberately

nginx 1.24 · PHP 8.4-FPM · MySQL 8.0. Keep these pinned; version drift with
hermes is a debugging trap. PHP extensions in `docker/php/Dockerfile` mirror the
set enabled on hermes. There is no redis here because the production PHP has no
redis extension either — the app doesn't use it.

## The world is a graph, not a grid

This is a **text game**: menu-driven, no tile graphics. It was a DOM tile
renderer until 2026-08-01, and the pivot is the single biggest fact about the
codebase.

- A **module** (`modules`) is one game you can sit down to play — Rivermark
  Chronicles. It owns some regions; a party picks one at creation
  (`parties.module_id`) and stays in it. Authored in `content/modules/*.json`.
- A **region** (`regions`) is one map view — Rivermark, the Quarry Wilds, the
  Goblin Warren. The client draws it as an inline SVG chart, `assets/js/ui-map.js`.
- A **location** (`locations`) is a node on that chart: one described scene, with
  the people in it listed and the ways out named. `map_x`/`map_y` are percentages
  positioning the node on the drawing — they are not coordinates a character
  occupies.
- **Exits** (`location_exits`) are the graph. Travel is a server-side BFS over
  them, resolved hop by hop so a journey can be interrupted; `LocationEngine::travel`.
- A character's whole position is `characters.current_location_id`.

**Modules are kept apart by the exit graph and nothing else.** No player-facing
screen lists regions globally — `regionMap()` draws the region you are in and
labels arrows along real exits — so two clusters with no exit between them are
isolated by construction. The loader makes that an error rather than a
convention: a cross-module exit fails the import, because in play it would look
like a travel bug rather than an authoring mistake.

Two things crossed anyway, and both moved a party without walking. The **job
board** posts by query; `QuestService::postable()` filters it by the party's
module, deriving each quest's module from its giver or target. And **defeat**
woke the party at `flagon_common_room`, written into `CombatEngine` as a
literal — so losing a fight in the Old City put the party in Rivermark with the
whole of it walkable. It now asks `CharacterGenerator::havenFor()`, which
answers with that party's own module's opening room; `tools/test_haven.php`
covers it. The lesson generalises: **anything that moves a party other than by
an exit has to be asked about the module**, and the loader now refuses content
that does it too (a `travel`, `target`, `place` or `recruit` naming another
module's location). Items, monsters
and spells are deliberately *not* scoped — a goblin is a goblin.
`tools/test_modules.sh` drives the boundary over real HTTP.

`app/lib/LocationEngine.php` is the engine (it replaced `MapEngine.php`);
`assets/js/game.js` is a ~900-line driver that replaced a 3,200-line tile
renderer. **Portraits survive** — `_face.png` and `_bust.png` are used by the
party rail, the people list, the dialogue theatre and the combat cards — but
`assets/images/{tiles,decor,buildings,anim}` (~36 MB) is dead weight, left on
disk and referenced by nothing. The `*_sheet.png` walk sheets are still baked
by `Paperdoll.php` and previewed in the creator, so they stay.

They are no longer **required**, and that mattered more than it sounds.
`load_content.py` went on treating a missing `<sprite_key>_sheet.png` as a hard
error long after the last thing that drew one was deleted, so fifty walk sheets
nobody would ever have seen — for a giant spider, a peat ooze, thirty-three
named townspeople — stopped `sql/content.sql` being written at all, and could
not be replaced either: `slice_assets.py` cuts them from a licensed pack tree
that is not on this machine, and `Paperdoll::bake()` draws only humanoids and
would overwrite the painted busts and faces while it did it. `check_sprite()`
now asks for `_face` and `_bust`, which are what the client actually requests,
and `ContentEditor::assertSpriteArt` matches it — the two have to, or the
editor saves rows the next load refuses. The art behind `pose` went the same
way; the column stays, because "this NPC is asleep" is true of the scene
whether or not a picture of it is drawn.

Combat survived that pivot untouched — it was rank-based (party/foe ×
front/back) with no grid, which is most of why the pivot was cheap. **It has
since grown a grid of its own, and the two are unrelated.** Fights are fought on
a generated 16×12 board of 5-ft cells held in `combat_sessions.state_json`:
movement costs feet, reach and weapon range are distances, obstacles give cover,
leaving somebody's reach provokes an opportunity attack. `app/lib/BattleGrid.php`
is the geometry (pure static, no PDO), `app/lib/BattleMapGen.php` generates the
terrain, `assets/js/ui-battlemap.js` draws it as inline SVG.

The old rank is kept but demoted: `characters.combat_rank`, `monsters.rank_pref`
and `encounter_monsters.rank_override` now decide only which end of a
deployment zone somebody starts in.

**The client does no combat geometry.** The engine ships a derived `state.ui`
block — reachable cells with their costs and predecessors, threatened cells,
per-target shot quality — and the board paints it. `ui-combat.js` used to mirror
`legalTargets` in JavaScript and the comment there conceded the copy would
drift; do not reintroduce that, in any form, for any rule.

This is **not** the old tile-map combat coming back. That fought on the walkable
grid of the *world* map, with spawn rings and a client-side mirror of
`reachable()`. This is a self-contained battlefield generated per encounter, and
it shares no code, no table and no concept with the world map.

Things that no longer exist and should not be reintroduced: `maps`, `map_tiles`,
`map_decor`, `map_links`, `npc_positions`, `MapEngine`, `MapEditor`, the
`editor/*` API group, `assets/js/editor.js`, `tools/build_maps.py`, `src/maps/`.
Also retired with the ranks: `CombatEngine::RANK_CAPACITY`, `assignRanks`,
`otherRank`, `rankHasRoom`, `rankCount`, `frontRankHolds`, `doSwapRank`, the
`swap_rank` action and the `rank` event.

## The Undervault — levels made at play

The third module is a **random dungeon generator**, in the manner of the 1979
DMG's Appendix A. It has four authored locations — a camp, a long tent, a spoil
heap, and the Mouth — and everything below them is generated when a party walks
down the stair.

- `app/lib/DungeonGen.php` rolls a level: rooms from a size table on an 11×8
  plan, joined by a spanning tree plus a few loops, stocked from a contents
  table, and described from depth-banded fragment tables. **Pure static, no
  PDO**, for the reason `BattleGrid` is — `tools/test_dungeon.php` generates
  thousands of levels in milliseconds.
- `app/lib/DelveEngine.php` writes one into real `regions`/`locations` rows,
  stocks the encounters, and drops the previous floor as the party leaves it.
  At most one generated region exists per party, however deep they go.
- `app/lib/EncounterBudget.php` sizes the fights against the party.

**The level is not stored — it is rebuilt from `dungeon_delves.seed` and
`.depth` whenever anything needs it.** `status()` regenerates it to find which
room the stair is in; `planFor()` regenerates it to draw the map. A stored copy
of a deterministic layout is a second thing that can disagree with the first.

That is also why **three grids exist and share nothing**: the world is a graph
of locations, `DungeonGen` lays rooms out on a coarse 11×8 plan projected onto
the chart's 0–100 × 0–75 field, and a fight inside one of those rooms is
generated by `BattleMapGen` on its own 16×12 board exactly as a fight anywhere
else is.

### The surface, and the one number that crosses

The four authored locations shipped as **scenery** — no NPC, no item, no quest,
and no job board anywhere in the module — while their prose promised all three.
The camp said "everything here is for sale twice" with nobody selling anything;
the spoil heap was described as tailings people pick through and had nothing to
find. A player standing in the Delvers' Camp had exactly one thing they could
do, which was walk to the Mouth.

It now has five people, four board quests and salvage on the heap. Two things
about that are load-bearing:

- **`uv_camp` carries the only job board in the module.** `QuestService::postable()`
  filters by the party's module and had nothing to return here, but that was the
  second fault — the first was that no Undervault location had `job_board` set at
  all, so quests alone would still have shown an empty wall.
- **`DelveEngine::DEPTH_FLAG` (`uv_deepest`) is the only fact authored content
  can state about a generated floor.** A level rolled ten minutes ago has no
  key, no name and no history, so there is nothing down there to write a quest
  against; what a conversation can ask is how deep this party has been.
  `markDepth()` raises it and never lowers it — climbing out and starting a
  fresh delve puts you on level 1 and must not revoke a quest already turned in.

That flag is written by code and read by content, which is a direction nothing
else in this game goes, and it broke both flag-wiring checks at once: they read
every `set_flag` in the corpus, so a flag set in PHP looks exactly like a flag
nobody sets, and every gate on it reads as a dead scene. Both now know about it
— `DialogueLint::ENGINE_FLAGS`, and `engine_flags()` in `tools/trace_content.py`,
which greps the constant out of the PHP rather than copying the string so that
renaming it cannot leave a checker quietly agreeing with nothing.

**Nothing else about a delve leaks upwards, and nothing should.** The engine
knows no quest keys; it writes one number and content decides what that is
worth.

### The floor plan

A generated floor has no painted plate and never will — the plates under the
authored regions are drawn once by a tool and committed, and a region that
exists only while a party is standing in it cannot be. So `regionMap()` ships a
`floorplan` block and `ui-map.js` draws the level's actual shape: rooms as
rectangles, passages as doglegged corridors, doors ticked across the threshold.
An authored region gets its painting and no plan; the two are exclusive, because
a plan under a painting is two maps of one place disagreeing.

Three things about it that are load-bearing:

- **`DungeonGen::plan()` is pure and the client does no geometry.** Same rule as
  combat. The corridors arrive as points in the chart's own field, and they
  carry LOCATION ids rather than room ids so `ui-map.js` can match them against
  the edges it already has. A corridor is drawn with the same classes as any
  other edge, so "this is a way out of here" reads identically on a floor plan
  and on a painted map.
- **`DungeonGen::project()` is the only place the two grids are converted**,
  which the file header always promised and which was true only because nothing
  but `placeRooms()` needed it.
- **`is-bare` is set when no plate is asked for, not only when one 404s.** It
  used to be added purely by the `<image>`'s `onerror`, so a chart that requests
  no image never got it — and the corridors would have been dimmed to the 0.22
  meant for ink lying over a painting.

**Room names are identifiers, not prose.** A name is the click target on the
chart and the only label a room shows. `NAMES` held ten per band against
profiles asking for up to fourteen rooms, the deck ran out and reshuffled
mid-level, and 63% of generated levels had two rooms with the same name. The
tables are twenty long now, `nameDeck()` deals once and never reshuffles, and
`test_dungeon.php` asserts the margin — so raising a profile's room count past
its name table fails in the test rather than as a duplicate on somebody's map.
The prose tables still reshuffle, deliberately: a repeated *sentence* across a
long level is fine.

`tools/floorplan_preview.php` draws floors with the shipped renderer and no game
running (`?seed=&depth=` for one, `?n=12` to judge variety);
`tools/dungeon_preview.php` prints the coarse plan as ASCII, which is the better
tool for judging the layout itself.

## Generated art

Four generators, all talking to the local image model (SANA-Sprint) on
`127.0.0.1:7860`, override with `QWEN_URL`:

| Tool | Draws | Into |
|---|---|---|
| `tools/gen_location_art.py` | one 16:9 plate per location | `assets/images/locations/<location_key>.png` |
| `tools/gen_region_map.py` | one 4:3 map per region | `assets/images/maps/<region_key>.png` |
| `tools/gen_npc_art.py` | one bust per sprite key | `assets/images/npcs/<key>_bust.png` |
| `tools/gen_module_cover.py` | one 3:2 cover per module | `assets/images/modules/<module_key>.jpg` |

Faces are **cut** from busts by `tools/cut_face.py`, never generated separately —
two generations of "a weathered miner" are two different men, and the party rail
and the dialogue theatre would then disagree about who somebody is.

**The negative prompt does nothing.** This is a two-step distilled model running
without classifier-free guidance, so there is no unconditional branch for a
negative to push against. Proved rather than assumed: regenerating with nine
extra anti-letterbox terms produced a byte-identical PNG. Anything that must be
true has to be in the *positive* prompt, and **early** — the model weights what
it reads first. Three bugs came from getting that wrong:

- Old City plates came back with **open sky** over a dungeon thirty feet
  underground, because the art direction keyed off the location's `type` and
  never consulted `region_type`. Fixed by leading with an underground clause.
- Officer Bram came back as a **twentieth-century policeman** in a peaked cap
  and necktie, because "Watch Officer" is a job title that exists in both
  centuries and nothing anchored the period. Fixed by leading every portrait
  with `PERIOD`. Quartermaster, Foreman and Clerk have the same trap in them.
- The Undervault's five busts came back as **scene illustrations** with the
  figure small in frame — one standing beside a cart in snow, two seated. The
  shot was asked for in `STYLE`, which is appended *after* the wearer's
  description, and forbidden in `NEGATIVE`, which does nothing; so the only
  framing instruction with any weight arrived last and lost to descriptions
  written around what somebody habitually does. Fixed by a `FRAMING` clause
  led beside `PERIOD`. The shipped Old City busts had survived the same code
  only because their descriptions name duties rather than postures.

  That fixed four of five. Orrin Bly's description opened with the word "Sits"
  and he came back seated again — **a posture in the description's first words
  still beats a framing clause that precedes the whole description.** Reworded
  to lead with the man; he came back standing. `FRAMING` is the floor, not a
  guarantee, and a character description should be leading with who somebody is
  in any case.

A module cover must **match the shelf**, which is the opposite constraint and
for the same reason — three cards in a row are read as one row. The Undervault's
first plate came back at mean luminance 52 against the two shipped covers' 79
and 80, and a dark card in a bright row reads as an image that failed to load,
not as a night scene. `match_shelf()` bisects a gamma exponent until the
measured mean lands on `SHELF_MEAN`; gamma rather than a brightness multiply,
because a multiply that lifts a mean-52 plate to 79 blows the one fire in frame
to white. `SHIPPED_SEED` records which seed produced what is on disk — the same
seed and prompt reproduce it byte for byte.

The cover prompts hit the leading-clause trap harder than anything else has.
"a square-cut stone stair descending into a black hole in a bare hillside" drew
a stair climbing *up* to a temple doorway on all four seeds: "stair" plus
"stone" plus "lintel" is an entrance in the weights, and "descending" three
words in does not outvote it. It took leading with "a deep square pit sunk into
the ground, seen from above at its rim".

A region map must stay quiet: the SVG chart draws place names over it, so the
generator pulls contrast and brightness toward the shipped plates (~138 mean)
after generating. A map that competes with its own labels is worse than none —
`ui-map.js` falls back to bare parchment on its own if the file is missing.

## Database

The schema is **not** dumped from production — it is built from the project's own
files in `src/sql/`, loaded by `docker/initdb/00-load.sh` in an explicit
dependency order (currently `schema.sql` → `seed_data.sql` → `content.sql`).
MySQL's entrypoint would otherwise run them alphabetically and every file would
execute before the tables it depends on exist. **If you add a `.sql` file, add
it to that list** — a file not named there is silently skipped.

There are no `migrate_*.sql` files any more: they were folded into `schema.sql`
when the world became a location graph, and a schema that drops every table has
no use for migrations that add columns to tables it has just created.

`content.sql` is generated from `src/content/*.json` by `src/tools/load_content.py`,
so regenerate it before rebuilding if you changed the JSON.

**Load SQL with `--default-character-set=utf8mb4`.** Piping into a bare `mysql`
gets the client's compiled-in default, which is latin1: every multi-byte
character is then read as two and re-encoded, and the em-dashes in the authored
dialogue arrive as `â€”`. Thirteen rows carried that for weeks — it reads as a
typo, not a fault. `00-load.sh` passes the flag and the generated SQL says
`SET NAMES utf8mb4`.

### The database is the source of truth

This changed when the content editors were added, and it inverts what used to be
true here. The world — locations, NPCs, dialogue, quests — lives in the database
and is edited in the browser. The files under `src/content/` are a **seed for a
fresh install and an export target**, not the master copy.

The loop is:

```bash
# edit in the browser at /content.php — People, Quests and Places, admin-only
docker compose exec -T php php /var/www/html/tools/export_content.php
```

or press **Export to files** on `/content.php`. That writes the database back
out to `content/**/*.json` so the work can be committed, diffed and deployed.
Files are compared before writing, so an export that changes nothing touches
nothing.

**Export before dropping the volume, or the work is gone.** The init scripts only
run when the data volume is empty:

```bash
docker compose down -v && docker compose up -d    # -v drops the data volume
docker compose logs db | grep '\[initdb\]'        # confirm each file loaded
```

That destroys everything — characters, parties, saved state, and any authored
content not yet exported. Rebuilding is how you verify the files reproduce the
world; it is not something to run casually while somebody is playing.

Do **not** re-apply `content.sql` to a populated database by hand. It is
destructive by design — it deletes every row whose key is not in the files, and
rebuilds the exit graph and the ground loot wholesale — so replaying it reverts
exactly the edits the editors exist to make. That is what "edits keep
disappearing" was.

It destroys **player progress** as well, which is worth stating separately
because the row counts afterwards still look plausible:

- it opens by deleting `party_quests`, `party_quest_stages` and
  `character_known_npcs` for every key the files mention — a party three quests
  in loses all three;
- it rebuilds `location_exits` and `location_items` wholesale, and the new
  auto-increment ids cascade `party_exits_found` and `party_items_taken` away
  with them.

**`sql/content.sql` contains a literal `USE rpg_5e;`.** Piping it at any other
database does not send it there — the `USE` wins and it lands on the live one.
That is not hypothetical: it is how a live save was destroyed while trying to
test the destruction safely in a scratch database. Strip the line if you are
targeting anything else.

### Getting new content into a game somebody is playing

```bash
cd /home/richard/code/rpg/src
python3 tools/apply_content_safely.py --check     # report, write nothing
python3 tools/apply_content_safely.py --apply
```

It snapshots the player-side tables **by content key rather than by id**, applies
`content.sql`, then restores them by resolving those keys against whatever ids
the load produced. Keys are stable across a rebuild by design — that is the
"Keys, not ids" rule in `docs/CONTENT.md` — so the mapping always exists, and the
only rows it cannot bring back are ones whose content genuinely went away. It
reports those rather than passing over them, it is idempotent, and it names the
target database explicitly instead of trusting the `USE` line.

Take a backup first anyway:

```bash
docker compose exec -T db mysqldump -uweb -pdevpassword --default-character-set=utf8mb4 \
    --single-transaction rpg_5e > backup.sql
```

Schema changes belong in `src/sql/`, so they travel with the code.

## Getting into a game

The front page is a shelf of modules and nothing else. Each card carries the
cover plate, the level band, how many of your parties are in it, and two doors:
**Play**, to `characters.php?module=<key>`, and **New party**, to `create.php`.
A module you have nothing in draws only the second, because Play would open an
empty page.

`characters.php` is one module's worth of your characters, **grouped by the
party they march in**, with anyone party-less collected at the bottom. It
groups client-side over `session/list` rather than issuing its own query: that
route is where ownership is already decided, and a second `WHERE` is a second
place to get it wrong.

Three things about it that look like details and are not:

- **Play is a POST, not a link.** `game.php` reads no query string; it plays
  whoever the session says is active. So the button calls `session/select`
  first and then navigates. A bare `game.php?character_id=N` looks like it
  works and drops you into the last character you played.
- **There is one Play per party, on the party's heading, not one per member.**
  A party is the unit of a playthrough, so six buttons on six cards read as six
  games and asked the player to pick a face on no basis. The session still
  plays a single character, so `session/select` takes a `party_id` as well and
  resolves it itself — `session_party_lead()` prefers whoever the session
  already had active in that party, then `parties.leader_character_id`, then
  the first slot, skipping companions. That rule stays on the server because
  the client cannot see the leader column, and a party id in a request body is
  a second way to reach somebody else's save: it is ownership-checked before it
  resolves, and `tools/test_ownership.sh` asserts it. A character with no party
  keeps a Play of its own — there is no party to press.
- `session/list` had `party_id` and no party **name** until this page wanted
  one. Both branches of the query — admin and not — carry `p.name AS
  party_name` now.

`tools/home_preview.php` draws both pages against fixtures, with no account and
no session, so the empty case and the six-member case can be looked at without
arranging a database to produce them. It serves the pages' own markup and their
own inline scripts and fakes only the two API routes, so a layout fault visible
there is a layout fault in the game. `tools/test_characters_page.sh` covers the
guard, the catalogue, the cover art and the grouping contract over real HTTP.

The front page used to spend its first column on a flat list of every character
the account had ever made, with `MODULE_SLOTS = 2` capping the module row
beside it — which silently sliced the third module off the page when it
arrived. The cap is gone; every module is drawn.

## Accounts

Two roles, `admin` and `user`. Sign in at `/login.php`; registration is open by
default and an admin can close it from `/admin.php` (or `RPG_REGISTRATION=0`,
which wins over the switch).

- Characters and parties belong to the account that made them. `session/list` is
  scoped, and `Ownership::character()` / `Ownership::party()` in
  `app/lib/Ownership.php` are the only places ownership is decided. An admin sees
  everyone's. `character_is_owned()` / `party_is_owned()` in `api/index.php` are
  still the names the routes call, and still the right ones to reach for there,
  but they now delegate rather than decide: the rule had to move out of the API
  when `sheet_print.php` — a page, not a route — needed to ask the same question.
  A second copy of an ownership test is the kind of duplication that stays
  correct right up until one of them is fixed.
- The API denies by default: `PUBLIC_ROUTES` at the top of `api/index.php` is the
  short allow-list, everything else needs a session.
- The content editors at `/content.php` — People, Quests and Places — are
  admin-only. The tile map editor is gone with the tiles; locations are edited
  in the Places tab.
- Passwords: `tools/set_password.php <username>` (also `--create`, `--role=`).
  An account with a NULL `password_hash` cannot sign in, which is the state
  `seed_data.sql` deliberately leaves the seeded admin in — a default password
  is a published password.

All four companions are romanceable, and **romance is exclusive**: reaching
`CompanionService::ROMANCE_COMMIT_STAGE` (stage 2, the beat every authored arc
uses for the answer) locks every other companion's arc permanently. Stage 1 is
the acknowledgement and deliberately does *not* commit, so a warm reply is not
a trap. `party_companions.romance_locked` had existed unwritten since romance
was added — the gate read it and nothing set it, so all arcs could be run at
once. `tools/test_romance.php` covers it.

`tools/test_ownership.sh` drives two accounts over real HTTP and asserts one
cannot reach the other's save. Run it after touching anything that takes an id.
`tools/test_modules.sh` is its counterpart for the module boundary: it builds a
throwaway second module, plays a party in each, and asserts neither can see the
other's job board. Both clean up after themselves and are safe to run against a
database somebody is playing on.

## Things that will bite you

- **`src/app/config/database.php` is local-only.** It is excluded from both
  `pull.sh` and `deploy.sh` so production credentials never land on this machine
  and dev credentials never reach production. Don't "fix" the exclusion.
- **`deploy.sh` writes to the live public site.** It is dry-run unless given
  `--apply`, then requires typing `deploy`. It intentionally omits `--delete` so a
  local deletion can't remove live files, and it chowns to `www-data` afterward
  because files arrive over SSH as `root:root` and php-fpm can't read those.
- **The app is env-var driven** — `app/config/database.php` reads `DB_HOST`,
  `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PORT` via `getenv()`. Set them in
  `docker-compose.yml`, don't hardcode.
- **The API routes on `?r=`**, e.g. `/api/?r=meta/races`. A bare `/api/` returning
  JSON `404` is the app's own router working correctly, not a broken vhost.
- **A missing static file returns HTTP `200`, not `404`.** The vhost ends in
  `try_files $uri $uri/ /index.php?$args`, so any URL that doesn't exist on disk —
  including a deleted or misnamed image — falls through to `index.php` and serves
  the homepage HTML with a `200`. **Never verify an asset by status code**; a
  status-only check passes for files that aren't there. Test the content type
  instead:

  ```bash
  curl -s -o /dev/null -w '%{content_type}\n' http://localhost:8081/<path>
  # image/png  = really there
  # text/html  = missing, you are looking at the homepage
  ```

  The same trap applies to `deploy.sh` verification against production.
- `hermes:/home/www/rpg` is also mounted at `~/mount/hermes/rpg`. That mount is an
  SSHFS **submount**, which Claude Cowork's VM cannot traverse — that's why this
  local copy exists. Work here, not there.
