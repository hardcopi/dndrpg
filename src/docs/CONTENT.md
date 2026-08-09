# Authoring content

Everything that is *story* — NPCs, dialogue, quests, companions, items, monsters
and encounters — lives as hand-editable JSON under `content/`. Nothing in
`app/lib/` needs to change to add any of it.

```
content/
  modules/*.json       one file per playable game
  npcs/*.json          who exists and what art they wear
  dialog/*.json        what they say, and what saying it does
  quests/*.json        stage graphs with multiple endings
  companions/*.json    recruitable party members
  items/*.json
  monsters/*.json
  spells/*.json
  encounters/*.json
  locations/*.json     one file per region: the world graph
```

`tools/load_content.py` validates the whole tree and writes `sql/content.sql`.
Nothing is loaded straight from disk at runtime — the import is what puts it in
MySQL, so the journal, the content editors and every existing SQL join keep
working unchanged.

```bash
python3 tools/load_content.py                       # validate + write sql/content.sql
python3 tools/load_content.py --check                # validate only, write nothing
docker compose exec -T db mysql -uweb -pdevpassword rpg_5e < sql/content.sql
```

The loader **refuses to write** on a dangling reference, an unreachable dialogue
node, a quest with no terminal stage, an effect that names a flag no condition
ever reads, a `sprite_key` with no art on disk, an exit pointing at a location
that does not exist, or a `place` block naming a location nobody authored. That is deliberate: a typo in a quest key is otherwise
invisible until a player walks into the scene it broke.

`content/` owns the `modules`, `regions`, `locations`, `location_exits`,
`location_items`, `items`, `monsters`, `spells`, `npcs`, `encounters`, `quests`,
`quest_stages` and `companions` tables outright — `sql/seed_data.sql` seeds only
users, races, classes and avatars, and inserts nothing a player can meet. Two files writing one table is how the job board ended up with
both the authored quests and five older ones nothing could delete.

## Modules: one file per playable game

A **module** is a game you can sit down to play. It owns some regions; a party
picks one when it is created and stays in it for good.

```json
{
    "module_key": "rivermark",
    "name": "Rivermark Chronicles",
    "blurb": "What the picker shows under the name.",
    "start_location_key": "flagon_common_room",
    "attribution": "Credit line. Every CC licence requires one.",
    "sort_order": 10
}
```

`level_min` (1), `level_max` (`Rules::MAX_LEVEL`) and `is_active` (true) are
written only when they differ from the default. `is_active: false` hides a
module from the picker without touching its content or the saves inside it —
it stops new games, it does not confiscate running ones.

Every region names its module:

```json
{
    "region_key": "goblin_warren",
    "module": "rivermark",
    ...
}
```

**Two modules must share no exits.** That is the whole of the isolation: there
is no global region list anywhere a player can see, so two clusters with no exit
between them cannot be walked between. The loader enforces it — an exit that
crosses a module boundary is an error, not a warning, because the symptom in
play (a party wandering out of one game into another) reads as a travel bug
rather than the authoring mistake it is.

Reachability is checked per module, from each module's own `start_location_key`.

The one place content crosses between games by query rather than by walking is
the **job board**, and `QuestService::postable()` filters it. A quest's module is
derived from its giver's location, falling back to its target location — so
every job-board quest needs at least one of those, or it posts nowhere.

Items, monsters, spells, races and classes are **not** scoped. A goblin is a
goblin; the bestiary and the rules are shared.

`tools/test_modules.sh` drives two modules over real HTTP and asserts a party in
one cannot see the other's work.

### Modules that ship

| Module | Regions | Locations | Levels | Notes |
|---|---|---|---|---|
| `rivermark` | 9 | 73 | 1–6 | Original setting. |
| `old_city` | 1 | 22 | 1–4 | One companion (Jimmy Able). Adapted from Simon J. Bull's *Secrets of the Old City* (2014). See `attribution` on the module row — it is rendered on the picker, and the licence is to be confirmed before public release. |

## Keys, not ids

Content refers to everything by a stable string key — `npc_key`, `quest_key`,
`item_key`, `companion_key`. Numeric ids are assigned by the import and must
never appear in a JSON file. Reordering or re-importing content reassigns ids;
if a file named one, every reference in it would silently repoint at whatever
now holds that number.

A key is lowercase, `[a-z0-9_]`, and unique within its kind.

---

## Add an NPC

`content/npcs/mara_hearthstone.json`:

```json
{
  "npc_key": "mara_hearthstone",
  "name": "Mara Hearthstone",
  "role": "Innkeeper",
  "description": "Warm smile, flour-dusted apron, knows every rumour in town.",
  "sprite_key": "innkeeper",
  "is_merchant": false,
  "is_quest_giver": true,
  "dialog": "mara_hearthstone"
}
```

`sprite_key` names an art set under `assets/images/npcs/` — `<key>_face.png` and
`<key>_bust.png`. The loader checks both exist and reads the expression count
from `assets/images/npcs/busts.json`, so a dialogue node cannot ask for an
expression that was never cut. (`tools/rebuild_busts.py --check` is what to run
when that count is suspected; it counts the folder and needs no art packs.)

A `<key>_sheet.png` may also be on disk and is neither required nor drawn. A
walk sheet is eight facings of a figure crossing a tile and there has been
nothing to cross since the world became a location graph; the client asks for
`_face` and `_bust` and nothing else. The same goes for the art behind `pose`.

`dialog` names a file in `content/dialog/`. Omit it for an NPC who is scenery.

To put them on the map, add a `place` block. See below.

---

## The world: regions and locations

The world is a graph of described scenes, not a grid of tiles. A **region** is
one map view — Rivermark, the Quarry Wilds, the Goblin Warren. A **location** is
a node on it: one place the party can stand, with prose, the people who are
there, and named ways out.

One file per region, `content/locations/<region_key>.json`:

```json
{
  "region_key": "rivermark",
  "name": "Rivermark",
  "region_type": "town",
  "description": "A modest trade town of cobbled streets...",
  "sort_order": 10,
  "locations": {
    "flagon_common_room": {
      "name": "The Golden Flagon",
      "type": "building",
      "map_pos": [28, 72],
      "inn_cost": 5,
      "description": "The warm heart of Rivermark: hearth-light on scrubbed tables...",
      "first_visit": "The door swings shut behind you and the road noise dies.",
      "ambience": [
        "The hearth pops and settles a log.",
        "Mara counts something under her breath — mugs, or debts."
      ],
      "exits": [
        { "to": "high_street", "label": "Out to the High Street" },
        { "to": "flagon_cellar", "label": "Down to the cellar" }
      ]
    }
  }
}
```

| Field | Meaning |
|---|---|
| `type` | `square`, `street`, `gate`, `building`, `room`, `site`, `camp` — picks the glyph the map draws |
| `map_pos` | `[x, y]` as a **percentage** of the region's chart, 0–100. A drawing coordinate, not a tile |
| `description` | the prose. This is the screen; it does the work the tile art used to |
| `first_visit` | optional, shown once, above the description |
| `ambience` | optional; one line is picked at random each time the scene is drawn |
| `inn_cost` | set it and "Rest at the inn" is offered here at that price in gold |
| `allow_camp` | whether the party may make camp (a free long rest) |
| `random_encounter_pct` | chance, per travel hop arriving here, of a fight from the region's random pool |
| `hidden_until_visited` | keep it off the chart until the party has been |

### Exits

`exits` is the graph, and it is directed — author both halves of a two-way
passage. `label` is what the player clicks, so write it as an instruction
("Through the east gate"), not as a destination.

| Field | Meaning |
|---|---|
| `to` | a location key, in any region. Cross-region exits are how the party leaves town |
| `label` | required; the words on the button |
| `conditions` | optional; the same vocabulary dialogue uses. A failing exit is listed greyed rather than hidden — knowing a road is shut is information |
| `hidden` | not listed at all until the party Searches here, or has already been through |

The loader refuses to write on an exit naming a location nobody authored, and
warns on a one-way exit and on any location no chain of exits can reach from
`flagon_common_room`. An unreachable location is content nobody will ever see —
the same class of bug as a dialogue node nothing jumps to, caught the same way.

---

## Placement

An NPC, an encounter or an item may say where it stands:

```json
"place": { "location": "market_square" }
```

Placement is a column on the row itself — `npcs.location_id`,
`encounters.location_id` — so the import is a plain `UPDATE`:

```sql
UPDATE npcs SET location_id = (SELECT id FROM locations WHERE location_key = 'market_square')
 WHERE npc_key = 'sera';
```

A location is a scene rather than a square, so **any number of people may share
one** and there is nothing to collide with. Every NPC of a kind the files no
longer place is set back to `NULL` on each import, so deleting a `place` block
takes that person off the street rather than leaving them where they were.

On an item, `place` is ground loot: it becomes a `location_items` row, the
Search action surfaces it, and which parties have taken it is recorded per
party — the content row is never consumed.

The loader refuses to write on any of these:

| Rejected | Because |
|---|---|
| a `location` key nobody authored | there is nowhere to stand |
| an `ambush` with no `place` | an ambush is what happens on arriving *somewhere* |
| an `ambush` that is also `is_random` | a random encounter already happens to the party |
| a random encounter with no `region` | nothing would ever draw it |
| a placed encounter that also names a `region` | `region` scopes the random pool only |

---

## Add a dialogue node

`content/dialog/mara_hearthstone.json`:

```json
{
  "npc_key": "mara_hearthstone",
  "start": "greeting",
  "nodes": {
    "greeting": {
      "text": "Welcome to the Golden Flagon! What can I do for you?",
      "expression": 1,
      "choices": [
        { "label": "A room, please.", "effects": [{"rest": "inn"}] },
        { "label": "Tell me about this town.", "next": "about_town" },
        { "label": "Goodbye.", "end": true }
      ]
    },
    "about_town": {
      "text": "Rivermark sits on the old trade road. Quiet enough, until the mill burned.",
      "choices": [{ "label": "Back", "next": "greeting" }]
    }
  }
}
```

That is the whole minimum. Everything below is optional and composes freely.

### Nodes that remember

A node key may map to an **array of variants** instead of one node. The first
variant whose conditions pass is the one shown; a variant with no conditions is
the fallback and must come last.

```json
"greeting": [
  { "conditions": [{"flag": "burned_the_mill"}],
    "expression": 4,
    "text": "You. Out. Before I fetch the captain." },

  { "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "Kessa. Didn't expect to see you back in here." },

  { "text": "Welcome to the Golden Flagon! What can I do for you?" }
]
```

This is the whole of "NPCs remember what you did" — there is no separate
memory system, only flags and variants.

### Nodes that do not repeat themselves: `pool`

First-match-wins has a failure mode, and it is the one players actually
complain about. A party in a settled state — companion recruited, personal
quest not yet running, approval where it has been for an hour — lands on the
same variant on every visit and hears the identical line forever. The writing
above it is fine. The writing below it is unreachable. Nothing is broken and
the character feels like a vending machine.

`pool` is the fix. Variants sharing a pool name within one node are
**interchangeable at one priority**, and repeat visits rotate through them:

```json
"greeting": [
  { "conditions": [{"flag": "kessa_debt_fate", "equals": "paid"}],
    "once": true,
    "text": "\"Paid,\" Kessa says. \"Don't.\"" },

  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "\"Where are we going and who's in the way,\" Kessa says." },
  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "Kessa has found a whetstone from somewhere." },
  { "pool": "idle", "conditions": [{"companion": "kessa", "status": "active"}],
    "text": "\"Ask me the thing you've been not asking me.\"" },

  { "text": "The half-orc at the end of the bar does not look up." }
]
```

- **Priority is unchanged.** The authored list is still walked from the top and
  the first live variant still wins. A pool is only reached when nothing above
  it matched — the `paid` line above still pre-empts all three idle lines.
- **Rotation, not randomness.** A cursor walks the pool in order, so a player
  hears every line in it before hearing any of them twice. Random picking
  repeats immediately about one visit in `n`, which is the complaint, not the
  cure.
- **The cursor is a party flag** (`pool:<npc>:<node>:<name>`) and survives a
  save. It is taken modulo the live member count on every read, so adding a
  line to a pool in a later release cannot break a save whose cursor is past
  the old end.
- **Members may differ in their conditions.** A line gated on a flag simply
  joins the rotation when the flag sets, and drops out again if it clears.
- **`once` works normally**, and a retired pool member is skipped — that is how
  a pool holds one line that fires the first time round and then never again.
- **A node with no `pool` keys behaves exactly as it did before pools existed.**
  That is a guarantee, not an intention; it is asserted in
  `tools/test_content_rules.php`.

Three rules the loader enforces, and why:

| Rule | Because |
|---|---|
| a pooled variant must have `conditions` | the last variant is the node's unconditional fallback and the engine requires one; rotating it would make the pool the node's whole behaviour rather than one priority inside it |
| members of a pool must be contiguous | a pool split around an unrelated variant reads as though priority runs top to bottom, and it does not |
| a pool of one warns | it rotates between itself and itself, and is almost always a sibling lost in a merge |

Pools are for the **settled** states — the companion who is simply travelling
with you, the shopkeeper you visit twice a day. A state the player reached by
doing something wants a written variant that says so, not a pool.

### Choices with conditions, tags and checks

```json
{
  "label": "Give me the ledger and walk away.",
  "tag": "ROGUE",
  "conditions": [{"origin": "rogue"}],
  "check": {
    "skill": "persuasion",
    "dc": 14,
    "on_success": "she_folds",
    "on_failure": "she_calls_the_guard"
  },
  "effects": [{"approval": {"kessa": 5}}],
  "next": "she_folds"
}
```

- `tag` is the pill the UI draws on the option (`ROGUE`, `ELF`, `SOLDIER`).
  It is *display only* — `conditions` is what actually gates it. Setting one
  without the other gives you an option that lies about why it is there.
- `check` routes through `CheckService`, which opens the d20 prompt, offers
  whatever boosts the party can legally spend, and rolls **server-side**.
  `on_success` / `on_failure` are node keys.
- `effects` fire before the jump. With a `check`, they fire on either outcome —
  put outcome-specific effects on the destination node's `on_enter` instead.
- `next` is the node to go to. `end: true` closes the conversation.
  A choice with a `check` needs no `next`.

### Companion interjections

```json
"she_folds": {
  "text": "Sera slides the ledger across the table without looking up.",
  "interjections": [
    { "companion": "kessa",
      "conditions": [{"companion": "kessa", "approval_at_least": 10}],
      "text": "Kessa snorts. \"That was easier than it should have been.\"" }
  ],
  "choices": [{ "label": "Take it.", "end": true }]
}
```

Interjections only render for a companion who is currently in the party. All
whose conditions pass are shown, in file order.

### Node fields, in full

| Field | Meaning |
|-------|---------|
| `text` | What is said. Required. |
| `expression` | Bust index, 1–N. Defaults to 1. Bounded by `busts.json`. |
| `speaker` | `npc_key` of who is talking, if not the NPC whose file this is. |
| `conditions` | Only on variants. See below. |
| `once` | Once shown, this variant is never chosen again. |
| `pool` | Only on variants. Rotate with the other variants of the same pool name. |
| `on_enter` | Effects applied when the node is displayed. |
| `interjections` | Companion asides. |
| `choices` | What the player may say. A node with none auto-closes. |

---

## Add a quest

`content/quests/ledger_and_lie.json`:

```json
{
  "quest_key": "ledger_and_lie",
  "title": "The Ledger and the Lie",
  "act": 1,
  "description": "The mill's accounts do not add up, and two people would rather you stopped looking.",
  "giver": "captain_elowen",
  "on_job_board": true,
  "required_level": 1,
  "rewards": { "xp": 250, "gold": 75, "item": "silvered_shortsword" },

  "stages": {
    "look_into_it": {
      "title": "Ask around Rivermark",
      "objective": "Find out who keeps the mill's books.",
      "journal": "Captain Reed asked me to look into the mill's accounts quietly.",
      "target": { "location": "counting_house" }
    },
    "confront_sera": {
      "title": "Confront Sera Vance",
      "objective": "Sera keeps the ledger. Get it from her.",
      "journal": "The books are Sera Vance's work. She has been covering for someone."
    },

    "sided_with_sera": {
      "terminal": true,
      "resolution": "sided_with_sera",
      "outcome": "success",
      "title": "The ledger burns",
      "journal": "I burned the ledger. Sera keeps her name, and the captain keeps her suspicions.",
      "effects": [
        { "set_flag": "mill_ledger", "value": "burned" },
        { "approval": { "kessa": 8, "aldric": -12 } }
      ]
    },
    "handed_it_over": {
      "terminal": true,
      "resolution": "handed_it_over",
      "outcome": "success",
      "title": "The ledger goes to the captain",
      "journal": "I gave Reed the ledger. Sera was arrested before dusk.",
      "effects": [
        { "set_flag": "mill_ledger", "value": "captain" },
        { "set_flag": "sera_arrested" },
        { "approval": { "kessa": -10, "aldric": 8 } }
      ]
    },
    "walked_away": {
      "terminal": true,
      "resolution": "walked_away",
      "outcome": "neutral",
      "title": "Not my business",
      "journal": "I left it alone. Whatever the mill is hiding, it is still hiding it.",
      "effects": [{ "set_flag": "mill_ledger", "value": "untouched" }]
    }
  }
}
```

Stages are a graph, not a sequence. Nothing declares the edges — dialogue and
encounters move the quest with a `quest_stage` effect, and the loader verifies
every stage is reachable from at least one of them.

Every quest needs **at least one terminal stage**, and each terminal stage needs
a distinct `resolution`. That string is what Acts 2 and 3 branch on:

```json
{ "conditions": [{"quest": "ledger_and_lie", "resolution": "sided_with_sera"}] }
```

Rewards are paid once, when the first terminal stage is entered — unless the
stage's `outcome` is `failure`, in which case they are not.

---

## Add a companion

`content/companions/kessa.json`:

```json
{
  "companion_key": "kessa",
  "name": "Kessa Dunmar",
  "title": "the Debt-Bound",
  "description": "Half-orc mercenary. Owes money to people who do not forget.",
  "race": "Half-Orc",
  "class": "Barbarian",
  "background": "Soldier",
  "alignment": "Chaotic Good",
  "level": 1,
  "abilities": { "strength": 16, "dexterity": 13, "constitution": 15,
                 "intelligence": 9, "wisdom": 11, "charisma": 12 },
  "sprite_key": "barbarian",
  "combat_rank": "front",
  "npc_key": "kessa",
  "personal_quest": "kessa_debt",
  "recruit": {
    "location": "flagon_common_room",
    "conditions": [{ "flag": "met_kessa" }]
  },
  "approval": { "leave_at": -40, "hostile_at": -70, "romance_at": 30 },
  "romanceable": true,
  "gear": ["greataxe", "potion_healing"],
  "spells": [],
  "feats": ["savage_attacker"]
}
```

`feats` names entries in `Feats::CATALOG` (`app/lib/Feats.php`). They are applied
at recruitment through `LevelUpService::grantFeat`, so whatever the feat is
worth — Tough's hit points, Resilient's ability score, the Defense fighting
style's armour class — arrives with it rather than being a line of text on a
sheet. The loader refuses to write on a key the catalogue does not carry.

Only feats the companion actually qualifies for: a template at level 1 can take
an **Origin** feat, which is exactly what SRD 5.2 grants from a background, and a
Fighter, Paladin or Ranger can take a **Fighting Style** one. General feats need
level 4 and will be refused at the moment somebody agrees to join.

A companion is a *template*. Recruiting them creates a real `characters` row
from it, so editing this file never has to reconcile with a save in progress.

Their conversation is an ordinary NPC dialogue file named by `npc_key`. Their
approval is what its conditions read:

```json
{ "companion": "kessa", "approval_at_least": 30 }
```

Approval is adjusted by an `{"approval": {...}}` effect anywhere — dialogue,
quest stages, encounter outcomes. Crossing `leave_at` downward makes them walk
out at the next camp; crossing `hostile_at` makes them turn on the party on the
spot. Romance stages advance with `{"romance": {"companion": "kessa", "stage": 2}}`
and cannot open below `romance_at`.

### Where approval is allowed to live

Quest resolutions are the tempting place to put it and the wrong place to put
most of it. They are one-shot, mutually exclusive, and they routinely set two
companions against each other — approving for Kessa is disapproving for Aldric —
so a party deciding pragmatically ends up with one friend and three enemies and
no way back. Approval drifted to 93% quest money once already, and the
symptom, reported by a player, was "Aldric left me because I couldn't get his
affection high enough. All the characters feel like they are at dead ends."

The rule that follows from it:

**A companion must be able to climb from their own `leave_at` back to 0 on the
`camp` node's subtree alone.** Not on all dialogue — approval hung on a
personal quest's confrontation can only be spent by doing that quest, and a
companion who is under water afterwards cannot go back for it. What the fire is
worth is the only figure that answers "can I fix this from where I am
standing".

`python3 tools/trace_content.py --economy` prints the arithmetic per companion
and files a finding against anybody who cannot make the climb.

Camp topics that carry approval want to be **one-time and deep** rather than
repeatable and shallow: a set of `once`-style topics (a flag set in `on_enter`,
the `camp` choice gated on `{"not": {"flag": ...}}`) worth +5 to +7 each, so a
player who actually talks to somebody climbs meaningfully and a player who
mashes one reply gets nothing. An unconditional repeatable +5 turns approval
into a grind button, which is a worse bug than the one it fixes.

And the standing has to be earned by something that reads as true. The best of
the shipped writing pays for honesty that costs the speaker something — telling
Aldric the order was right to put him out, telling Kessa she is the one you put
in front — and pays nothing at all for picking the obviously kind option.

---

## Conditions

Used by dialogue variants, choices, interjections, recruitment and encounters.
A list is AND. `any` / `all` / `not` nest.

| Condition | Passes when |
|-----------|-------------|
| `{"flag": "k"}` | flag `k` is set and not `"0"` or `""` |
| `{"flag": "k", "equals": "burned"}` | flag `k` is exactly that string |
| `{"flag": "k", "at_least": 3}` | flag `k` read as a number is ≥ 3 |
| `{"not": {...}}` | the inner condition fails |
| `{"any": [...]}` / `{"all": [...]}` | OR / AND |
| `{"quest": "q", "status": "active"}` | `available`, `active`, `completed`, `failed` |
| `{"quest": "q", "stage": "confront_sera"}` | that stage is current |
| `{"quest": "q", "resolution": "walked_away"}` | ended that way |
| `{"companion": "c", "status": "active"}` | `unmet`…`hostile`, `dead` |
| `{"companion": "c", "approval_at_least": 30}` | |
| `{"companion": "c", "romance_stage": 2}` | at least that stage |
| `{"origin": "wood_elf"}` | race, subrace, class or background tag |
| `{"level_at_least": 3}` | |
| `{"ability": "strength", "at_least": 15}` | |
| `{"has_item": "brass_key"}` | anywhere in the party's packs |
| `{"gold_at_least": 50}` | |

Origin tags are derived at character creation from race, subrace, class and
background, lowercased with spaces as underscores — `half_orc`, `wood_elf`,
`rogue`, `folk_hero`. That is what a `tag` pill on a choice should mirror.

## Effects

| Effect | Does |
|--------|------|
| `{"set_flag": "k", "value": "v"}` | `value` defaults to `"1"` |
| `{"clear_flag": "k"}` | |
| `{"increment_flag": "k", "by": 1}` | counters, for "how many did you spare" |
| `{"pressure": 2}` | what this act cost the Corse Concern — see below |
| `{"approval": {"kessa": 5, "aldric": -10}}` | only applies to recruited companions |
| `{"give_item": "k", "quantity": 1}` / `{"take_item": "k"}` | |
| `{"give_gold": 50}` / `{"take_gold": 25}` | |
| `{"xp": 100}` | split across the party, as combat does |
| `{"start_quest": "q"}` | enters its first stage |
| `{"quest_stage": {"quest": "q", "stage": "s"}}` | |
| `{"fail_quest": "q"}` | |
| `{"recruit": "kessa"}` / `{"companion_leaves": "kessa"}` | |
| `{"romance": {"companion": "kessa", "stage": 2}}` | |
| `{"start_combat": "mill_ambush"}` | closes dialogue and opens the fight |
| `{"open_shop": "tobin"}` | |
| `{"rest": "inn"}` / `{"heal": 8}` | |
| `{"travel": {"location": "flagon_common_room"}}` | moves the whole party and closes dialogue |

Effects run in file order. An effect naming something that does not exist is a
load error, not a runtime shrug.

### `pressure`: what an act cost the Concern

`{"pressure": n}` charges the valley's antagonist for something the party has
just done to it — a purchase blocked, a note voided, a boundary re-cut, three
years of survey work burned. `n` is a price from **1 to 5**: 1 is a thing the
Concern will hear about eventually, 3 is a loss it has to answer, 5 is the
scale of the whole act.

The engine adds them up and nothing else. Crossing a named total sets a flag,
and every reaction the player ever sees is authored against that flag exactly
as every other conditional line is:

```json
{ "conditions": [{"flag": "concern_asking"}], "once": true,
  "text": "\"Somebody asked,\" Finch says, and makes nothing of it. ..." }
```

| Flag | Set at | What it means |
|---|---|---|
| `concern_noticed` | 3 | you are a cost, and the cost has been written down |
| `concern_asking` | 8 | a polite man is up from downriver with your description |
| `concern_answering` | 15 | the asking is over; doors shut and prices move |

The totals live in `ConcernPressure::THRESHOLDS` and are expected to move, so
**gate on the flags rather than on the number**. `{"flag": "concern_pressure",
"at_least": 8}` works and always will, and it pins tuning into a dialogue file.

Three rules the loader enforces rather than asking you to remember:

- **The counter only rises.** `set_flag`, `clear_flag` and `increment_flag` are
  all refused on `concern_pressure` and on the threshold flags — those are the
  engine's to write. `docs/CONCERN_PRESSURE.md` argues why pressure that
  decays would make the correct play "interfere, then wait".
- **A price is positive.** An act that cost the Concern nothing is written by
  writing no effect.
- **One number, valley-wide.** There is no per-holding pressure and there is not
  going to be. The Concern is one organisation reading one set of books.

`tools/test_pressure.php` asserts the ratchet, and that the acts priced in
`content/quests/` can still pay for the top band — so raising a threshold past
what the corpus charges fails there rather than as a band of writing nobody
can reach.

---

## Encounters

```json
{
  "encounter_key": "mill_ambush",
  "name": "Ambush at the Mill",
  "description": "Three of Sera's hired knives step out of the dark.",
  "difficulty": "medium",
  "monsters": [
    { "monster": "bandit", "quantity": 2, "rank": "front" },
    { "monster": "kobold", "quantity": 1, "rank": "back" }
  ],
  "allow_flee": true,
  "allow_parley": true,
  "parley_node": "sera:call_them_off",
  "victory_flag": "mill_ambush_won",
  "defeat_flag": "mill_ambush_lost"
}
```

`rank` overrides the monster's own `rank_pref`. `parley_node` is
`npc_key:node_key` — this is how a fight becomes a conversation instead of a
loss.

A fight has to be openable by something, and there are three ways:

| | |
|---|---|
| a `start_combat` effect in dialogue or a quest stage | the usual one: the fight is the outcome of a scene |
| `"ambush": true` with a `place` | it happens on arriving there. This is for fights that *are* the place — the rats in the cellar, the gate the goblins keep |
| `"is_random": true` with a `region` | drawn while travelling through that region, at the odds each location sets |

The loader warns about any encounter with none of the three: it is written,
costed, and unreachable.

## Spells

```json
{
  "spell_key": "sacred_flame",
  "name": "Sacred Flame",
  "level": 0,
  "school": "Evocation",
  "description": "Flame-like radiance descends on a creature...",
  "damage_dice": "1d8",
  "damage_type": "radiant",
  "resolution": "save",
  "save_ability": "dex",
  "save_effect": "negate",
  "concentration": false,
  "target_kind": "enemy",
  "reaches_back_rank": true
}
```

`resolution` is the field that matters most and the one with no safe default:

| `resolution` | How it lands |
|---|---|
| `attack` | ranged spell attack against AC — Fire Bolt, Ray of Frost |
| `save` | the target rolls; needs `save_ability` and `save_effect` (`half` or `negate`) |
| `auto` | no roll on either side — Magic Missile |
| `heal` | restores hit points; `target_kind` is an ally |
| `buff` / `debuff` | applies `effect` for `duration_rounds` |

Getting it wrong is not cosmetic: Sacred Flame resolved as `attack` is a
different spell from the one the SRD prints, and every AC in the game changes
how often it lands.

`range_text` is a rule, not a caption. It is parsed — `"60 feet"`, `"Touch"`,
`"Self (15-foot cone)"` — and decides how far the spell carries and what shape
it arrives in, so a spell whose range nobody wrote will not reach past arm's
length. Area wording in brackets makes the spell ask for a spot on the ground
rather than a body. `reaches_back_rank` is kept as the fallback for a range that
fails to parse: `false` means touch, `true` means sixty feet.
`concentration` caps a character at one such effect at a time.

`name` is load-bearing in two places: `CharacterGenerator::grantStartingSpells()`
picks a class's opening spells by name, and `CheckService` looks up `Guidance`
by name to offer its d4 on an ability check. A companion's `spells` list uses
keys, not names.

## Monsters and items

Straight mirrors of the `monsters` and `items` tables; see
`content/monsters/goblin.json` and `content/items/longsword.json` for the shape.
Two fields on a monster matter more than they look:

- `sprite_key` resolves the walk sheet, the portrait, the battler and the combat
  clips. Three of the shipped monsters are art reskins — `skeleton` wears the
  Gnoll sheet, `zombie` the Ratman, `orc` the Bugbear — because the packs ship
  no undead at all. Write encounters around the art that exists.
- `rank_pref` decides where it *deploys* — `back` starts it at the rear of its
  zone — and nothing after that. Fights are fought on a grid, so a monster walks
  wherever it likes once one starts. Archers and casters still want `back`, but
  give them a ranged action with a `range` on it (`"80/320"`) or they will spend
  the fight trudging across the board. `size` matters too: anything Large or
  bigger threatens ten feet instead of five.
