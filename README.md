# Rivermark Chronicles

An Open 5e role-playing game played in a browser. It is menu-driven and made of
prose: you read a scene, click a place on the map to travel, click a person to
talk to them, and fights are resolved on a tactical grid. There are no tiles and
no sprites walking about — the world is a graph of described places.

Runs at <https://rpg.five-star.com>; this repository is the development copy.

---

## Getting it running

Everything is in Docker. From the repository root:

```bash
docker compose up -d          # nginx + PHP-FPM + MySQL
docker compose ps             # what is running
docker compose logs -f php    # PHP errors, the most useful log
docker compose down           # stop
```

Then open <http://localhost:8081>. Port 8080 is deliberately not used — an
unrelated `llama-server` container holds it.

| | |
|---|---|
| Site | <http://localhost:8081> |
| MySQL | `localhost:3307` (`3306` inside the compose network) |
| Database | `rpg_5e`, user `web`, password `devpassword` |

```bash
docker compose exec db mysql -uweb -pdevpassword rpg_5e            # a shell
docker compose exec -T db mysql -uweb -pdevpassword rpg_5e \
    -e "SHOW TABLES;"                                              # one-shot
```

`src/` is bind-mounted, so edits to PHP, JS and CSS are live. Changing
`docker/nginx/default.conf` needs `docker compose restart web`; changing
`docker/php/Dockerfile` needs `docker compose up -d --build php`.

**One local file is not in this repository.** `src/app/config/database.php`
holds credentials and is excluded from version control and from both sync
scripts, so production credentials never reach a laptop and development ones
never reach production. Copy it from an existing checkout or write it fresh; the
app reads `DB_HOST`, `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD` and `DB_PORT` from
the environment, which `docker-compose.yml` sets.

---

## The shape of the thing

### The world is a graph

- A **module** is one game you sit down to play. A party picks one when it is
  made and stays in it.
- A **region** is one map view, drawn client-side as an inline SVG chart over an
  optional painted plate.
- A **location** is a node on that chart: one described scene, the people in it,
  and the ways out of it.
- **Exits** are the graph. Travel is a server-side breadth-first search resolved
  hop by hop, so a journey can be interrupted by what you meet on the way.

Modules are kept apart by the exit graph and nothing else, and the content
loader treats a cross-module exit as an error rather than a convention.

### Combat has a grid of its own

Fights happen on a generated 16×12 board of five-foot cells, unrelated to the
world map. Movement costs feet, reach and range are distances, obstacles give
cover, and leaving somebody's reach provokes an opportunity attack.

**The client computes no geometry.** The engine ships a derived block — which
cells are reachable and at what cost, which are threatened, the quality of every
shot — and the board paints it. A rule mirrored in JavaScript is a rule free to
disagree with the server.

### One module builds itself

The Undervault is a random dungeon generator in the manner of the 1979 DMG's
Appendix A. Four authored locations sit above a stair, and everything below is
rolled when a party walks down it. A level is never stored: it is rebuilt from a
seed and a depth whenever anything needs it, so there is no second copy to
disagree with the first. A party sees only what it has walked — rooms and
passages carry a fog of war, and a passage behind an undiscovered secret door is
not drawn at all.

---

## Layout

```
docker/          the local stack: nginx vhost, PHP image, database seeding
src/             the site itself, mirrored to the production host
  api/           one router; every route hangs off ?r=
  app/lib/       the engines — combat, locations, dialogue, quests, delving
  assets/        js, css, and the art
  content/       the world as JSON: locations, people, quests, monsters, items
  sql/           schema and seed, loaded in dependency order
  tools/         tests, art generators, content linters, drawing benches
docs/            design notes
pull.sh          bring production's copy down
deploy.sh        push this copy up — dry-run unless given --apply
```

### Content, and where truth lives

The world lives in the **database** and is edited in the browser at
`/content.php` (admin only). The files under `src/content/` are a seed for a
fresh install and an export target, not the master copy:

```bash
# edit in the browser, then write the database back out to files
docker compose exec -T php php /var/www/html/tools/export_content.php
```

Monsters, items, spells and encounters are the exception — nothing edits them in
the browser, so their files *are* the source and are edited directly.

Rebuilding the database from files destroys player progress, and
`sql/content.sql` is destructive by design. To get new content into a game
somebody is playing:

```bash
cd src
python3 tools/apply_content_safely.py --check   # report, write nothing
python3 tools/apply_content_safely.py --apply
```

---

## Voices, and the mouths that speak them

A dialogue node is prose with speech quoted inside it, so it is read by more
than one voice: a narrator for the prose, the character for what is in the
quotes. Where those cuts fall is a small grammar, and it is worked out **once**,
offline, by the generator — never re-derived at request time. `Voiceover.php`
only looks the answer up, keyed on a sha1 of the exact authored text.

Two passes, in order. The second reads what the first wrote:

```bash
cd src
python3 tools/gen_voiceover.py --all    # cut the nodes, speak them (needs Kokoro on :3000)
python3 tools/gen_visemes.py --all      # read the clips back, work out the mouth (needs ffmpeg, numpy)
```

`gen_visemes.py` derives a mouth track per clip — how far the jaw is open and
whether the lips are spread or rounded, a byte each at 30fps — and writes it to
`assets/audio/vo/<npc>/visemes.json`, keyed by clip filename. `Voiceover::clips()`
hands it to the client on the clip it belongs to, and the Unity portrait paints
it against the playhead. Nothing analyses audio at run time.

Both passes are incremental: a clip already done is skipped unless `--force`, so
adding one line costs one clip rather than the corpus. Both are also optional
everywhere — a clip with no track plays with a still face, exactly as a line
with no recording plays silently.

The client only moves a face for that character's own clips. The `speaker` on
each clip is what makes that possible, and it is why the narrator reading half
of every conversation does not set the whole town talking.

---

## Tests and benches

There is no framework. Tests are scripts that exit non-zero.

```bash
docker compose exec -T php php /var/www/html/tools/test_combat.php    # 279 rules checks, no database
docker compose exec -T php php /var/www/html/tools/test_dungeon.php   # generated levels, swept
docker compose exec -T php php /var/www/html/tools/test_delve.php     # delving, over a real database
docker compose exec -T php php /var/www/html/tools/test_visemes.php   # every recorded clip has a mouth
bash src/tools/test_ownership.sh                                      # two accounts, over real HTTP
python3 src/tools/lint_php.py && python3 src/tools/lint_js.py
```

**Benches** draw a screen against fixtures with no session and no game running,
which is how layout is judged here. Each serves the shipped renderer rather than
a copy of it, so a fault visible on a bench is a fault in the game:

| | |
|---|---|
| `/tools/combat_field_preview.html` | the whole combat board |
| `/tools/scene_preview.html` | the exploration screen |
| `/tools/sheet_preview.html` | the character sheet and its bag |
| `/tools/floorplan_preview.php` | generated dungeon floors |
| `/tools/home_preview.php` | the module shelf and character list |

---

## Art

Five generators talk to a local image model (SANA-Sprint) on `127.0.0.1:7860`,
overridable with `QWEN_URL`:

| Tool | Draws |
|---|---|
| `gen_location_art.py` | a 16:9 plate per location |
| `gen_region_map.py` | a 4:3 map per region |
| `gen_npc_art.py` | a bust per person |
| `gen_monster_art.py` | a bust per creature |
| `gen_module_cover.py` | a 3:2 cover per module |

Faces are **cut** from busts by `cut_face.py`, never generated separately: two
generations of the same description are two different people, and the party rail
and the dialogue theatre would then disagree about who somebody is.

The model runs without classifier-free guidance, so **the negative prompt does
nothing** — anything that must be true has to be in the positive prompt, and
early, because the model weights what it reads first. Several bugs have come
from getting that wrong; the generators carry the details.

**Not all art is in this repository yet.** A push ceiling on this machine
stops the larger directories going up (see the git history); the region maps are
here, the rest ship through `deploy.sh` as they always have. Most of it is
reproducible from the generators above.

---

## The adventure, as a book

Administrators can export a module as a printed adventure — `/content.php` →
**Export as PDF**, or `/adventure_print.php` directly. It prints a cover,
numbered area entries with boxed read-aloud text, the cast, the quests with
their outcomes, stat blocks for everything the module sends, the maps again as
handouts without the numbers, and NPC cards four to a page.

It is the GM's copy: secret doors are marked as secret and quest stages carry
their endings, which is why it is admin-only.

There is no PDF library. The browser already prints to PDF, honours `@page`, and
sets type better than anything that would have to be installed to compete.

---

## Deploying

```bash
./deploy.sh              # dry run — always do this first
./deploy.sh --apply      # then type "deploy" to confirm
```

It intentionally omits `--delete`, so a local deletion cannot remove a live
file, and it chowns to `www-data` afterwards because files arrive over SSH as
root.

---

## Before changing anything

Read `CLAUDE.md`. It is the working notes for this codebase — what the pivot
from tiles changed, which mistakes have already been made and what they cost,
and the rules that are load-bearing rather than stylistic. It is longer than
this README on purpose.

---

Uses only content from the 5e System Reference Document under OGL 1.0a /
CC-BY 4.0. Not affiliated with any trademark holders.
