#!/usr/bin/env python3
"""
Generate sql/content.sql from the hand-authored JSON under content/.

Story — the world's regions and locations, NPCs, dialogue, quests, companions,
items, monsters and encounters — is authored as JSON and imported into MySQL.
Nothing is read off disk at runtime, so this import is the only thing standing
between a typo and a scene that silently does nothing when a player walks into
it:

    python3 tools/load_content.py                # validate, write sql/content.sql
    python3 tools/load_content.py --check        # validate only, write nothing
    python3 tools/load_content.py --verbose      # and say what was found
    mysql -u web -p rpg_5e < sql/content.sql     # after schema.sql + seed_data.sql

Validation collects *every* problem before deciding anything, and one error
means nothing is written at all. A half-written content.sql is worse than no
file: it loads clean, and the missing half only surfaces in play.

Rows are addressed by their stable key. Every reference in the generated SQL is
a subselect on that key — `(SELECT id FROM npcs WHERE npc_key = 'mara_hearthstone')`
— so the file never contains an id this script guessed, and re-running it
cannot repoint a reference at whatever now holds that number. The world itself
is no exception: locations are referenced by location_key, regions by
region_key, both authored in content/locations/<region_key>.json.
"""

import argparse
import functools
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(ROOT, "content")
OUT = os.path.join(ROOT, "sql", "content.sql")

RULES_PHP = os.path.join(ROOT, "app", "lib", "Rules.php")
FEATS_PHP = os.path.join(ROOT, "app", "lib", "Feats.php")

# Flags written by PHP rather than by any authored effect, as (file, constant).
# Each constant is read for the strings inside it, so a scalar contributes one
# name and an array contributes all of them — which is what lets a whole band of
# threshold flags be declared once, in the class that writes them.
#
# Two checkers need this list and neither can derive it: this one, to refuse
# content that writes a flag the engine owns, and tools/trace_content.py, to
# stop reporting every gate on one as a dead gate. It lives here because this is
# the tool that already reads Rules.php and Feats.php for the same reason, and
# trace_content.py imports it rather than keeping a second copy — two greps for
# one fact is how one of them ends up agreeing with nothing after a rename.
ENGINE_FLAG_CONSTANTS = (
    ("app/lib/DelveEngine.php", "DEPTH_FLAG"),
    ("app/lib/ConcernPressure.php", "FLAG"),
    ("app/lib/ConcernPressure.php", "THRESHOLDS"),
)

# The subset of those that the engine keeps for itself: written by PHP and read
# back by PHP, and deliberately not part of the vocabulary content gates on.
#
# `concern_pressure` is the running total behind the Concern's named
# thresholds. What content reads is the thresholds — a condition written against
# the raw number would pin tuning that is expected to move — so "nothing in the
# corpus reads this" is the intended state here and not a finding, which is what
# tools/trace_content.py needs this to know. Note it is NOT simply "every flag
# PHP reads back": DelveEngine reads uv_deepest too, and that one stays off this
# list, because a depth flag no conversation ever asks about is exactly the dead
# weight the check exists to report.
ENGINE_PRIVATE_CONSTANTS = (
    ("app/lib/ConcernPressure.php", "FLAG"),
)

NPC_ART = os.path.join(ROOT, "assets", "images", "npcs")
MONSTER_ART = os.path.join(ROOT, "assets", "images", "monsters")

KEY_RE = re.compile(r"^[a-z0-9_]+$")

# Content kinds, in the order they are emitted. The order is a dependency
# order: a quest's INSERT contains a subselect against npcs and items, so those
# rows have to exist by the time it runs. `modules` comes first because a
# region belongs to one, and `locations` next because nearly everything else
# places itself somewhere — an NPC's location_id, an encounter's region, a
# quest stage's target are all subselects against the location rows. `dialog`
# has no table of its own — it is merged into npcs.dialogue_json — but it is
# loaded like any other kind.
KINDS = ("modules", "locations", "items", "monsters", "spells", "npcs", "dialog",
         "encounters", "quests", "companions")

# kind -> the field inside the file that carries its key.
KEY_FIELD = {
    "modules": "module_key",
    "locations": "region_key",   # one file per region, holding its locations
    "items": "item_key",
    "monsters": "monster_key",
    "spells": "spell_key",
    "npcs": "npc_key",
    "dialog": "npc_key",
    "encounters": "encounter_key",
    "quests": "quest_key",
    "companions": "companion_key",
}

# kind -> (table, key column), for the subselects the generated SQL uses.
TABLE = {
    "modules": ("modules", "module_key"),
    "items": ("items", "item_key"),
    "monsters": ("monsters", "monster_key"),
    "spells": ("spells", "spell_key"),
    "npcs": ("npcs", "npc_key"),
    "encounters": ("encounters", "encounter_key"),
    "quests": ("quests", "quest_key"),
    "companions": ("companions", "companion_key"),
}

# ---------------------------------------------------------------------------
# Field manifests. Required and optional field names per kind, mirroring the
# columns in sql/schema.sql. An unlisted field is an error rather than a
# shrug: a misspelt `is_quest_giver` that loads as a no-op is exactly the class
# of bug this script exists to catch.
# ---------------------------------------------------------------------------
FIELDS = {
    # One file per module: which game this is, and where a party that picks it
    # begins. The regions it owns name it back, rather than being listed here —
    # one file per region is already the shape, and a list in two places is a
    # list that disagrees with itself.
    "modules": (
        {"module_key", "name", "start_location_key"},
        {"blurb", "level_min", "level_max", "attribution", "is_active",
         "sort_order"},
    ),
    # One file per region. The locations themselves are validated against
    # LOC_FIELDS below; this is the region envelope.
    "locations": (
        {"region_key", "module", "name", "region_type", "locations"},
        {"description", "sort_order", "plan"},
    ),
    "items": (
        {"item_key", "name", "item_type"},
        {"description", "rarity", "weight", "value_gp", "damage_dice",
         "damage_type", "armor_bonus", "armor_type", "slot", "properties",
         "image_url", "icon", "place"},
    ),
    "monsters": (
        {"monster_key", "name"},
        {"size", "type", "alignment", "armor_class", "hit_points", "hit_dice",
         "speed", "strength", "dexterity", "constitution", "intelligence",
         "wisdom", "charisma", "challenge_rating", "experience_points",
         "traits", "actions", "legendary_actions", "saves", "resistances",
         "immunities", "vulnerabilities", "condition_immunities", "loot",
         "rank_pref", "image_url", "sprite_key", "source"},
    ),
    "spells": (
        {"spell_key", "name"},
        {"level", "school", "casting_time", "range_text", "components",
         "duration", "description", "damage_dice", "damage_type", "resolution",
         "save_ability", "save_effect", "concentration", "duration_rounds",
         "target_kind", "reaches_back_rank", "effect", "higher_level",
         "classes", "source"},
    ),
    "npcs": (
        {"npc_key", "name"},
        {"role", "description", "image_url", "sprite_key", "dialog",
         "is_merchant", "is_quest_giver", "is_ambient", "pose", "shop", "place"},
    ),
    "dialog": ({"npc_key", "start", "nodes"}, set()),
    "encounters": (
        {"encounter_key", "name", "monsters"},
        {"description", "region", "is_random", "ambush", "difficulty",
         "allow_flee", "allow_parley", "parley_node", "victory_flag",
         "defeat_flag", "victory_quest_stage", "place", "scale"},
    ),
    "quests": (
        {"quest_key", "title", "stages"},
        {"act", "description", "giver", "companion", "on_job_board",
         "required_level", "rewards", "is_active"},
    ),
    "companions": (
        {"companion_key", "name", "race", "class", "sprite_key", "abilities"},
        {"title", "description", "subrace", "background", "alignment", "level",
         "combat_rank", "npc_key", "personal_quest", "recruit", "approval",
         "romanceable", "gear", "spells", "feats"},
    ),
}

# A location inside a region file, and one of its exits. `map_pos` is [x, y]
# in percent of the region's map drawing — a chart coordinate, not a tile.
LOC_FIELDS = ({"name", "type", "map_pos", "description"},
              {"first_visit", "ambience", "inn_cost", "allow_camp",
               "random_encounter_pct", "hidden_until_visited", "job_board",
               "exits", "loot"})
EXIT_FIELDS = ({"to", "label"}, {"conditions", "hidden"})
# Authored dungeon shape. Rooms sit on DungeonGen's coarse plan; halls name
# two of them. The loader stores the drawing — it does not compile it. The
# studio's savePlan is what writes the matching locations and exits.
PLAN_FIELDS = ({"rooms"}, {"halls"})
PLAN_ROOM_FIELDS = ({"key", "gx", "gy", "w", "h"}, {"id", "name"})
PLAN_HALL_FIELDS = ({"a", "b"}, {"id", "door", "hidden"})
PLAN_DOORS = ("open", "arch", "door", "stuck", "locked", "portcullis")

NODE_FIELDS = ({"text"},
               {"expression", "speaker", "conditions", "once", "pool", "on_enter",
                "interjections", "choices"})
CHOICE_FIELDS = ({"label"},
                 {"tag", "conditions", "check", "effects", "next", "end", "reoffer"})
# `reoffer` keeps a quest-offering reply on the menu after the party has taken
# the job. DialogEngine hides those by default — an offer already accepted does
# nothing when picked, and a reply that does nothing is what a dead end is made
# of. Set it only where saying it again is meant to mean something.
# `retry` opts a check out of being spent by a failure. The default — one
# attempt, then the option is greyed — is what makes a DC mean anything; a
# check you may repeat until it passes is a delay, not a decision. Set it only
# where trying again is the point.
CHECK_FIELDS = ({"skill", "dc", "on_success", "on_failure"}, {"retry"})
INTERJECTION_FIELDS = ({"companion", "text"}, {"conditions", "once"})
STAGE_FIELDS = ({"title"},
                {"objective", "journal", "target", "terminal", "resolution",
                 "outcome", "effects"})

# ---------------------------------------------------------------------------
# The condition and effect vocabularies, from docs/CONTENT.md. Each entry is a
# head key mapped to the extra keys that may sit beside it and to how its value
# is checked. Whitelisting is the point: a typo'd `{"set_flags": ...}` has to
# be a load error, because at runtime it is an effect that quietly never fires.
# ---------------------------------------------------------------------------
CONDITION_SPEC = {
    "flag":           ({"equals", "at_least"}, "str"),
    "not":            (set(), "condition"),
    "any":            (set(), "condition_list"),
    "all":            (set(), "condition_list"),
    "quest":          ({"status", "stage", "resolution"}, "quest"),
    "companion":      ({"status", "approval_at_least", "approval_at_most",
                       "romance_stage", "romance_locked"}, "companion"),
    "origin":         (set(), "str"),
    "level_at_least": (set(), "int"),
    "ability":        ({"at_least"}, "ability"),
    "has_item":       (set(), "item"),
    "gold_at_least":  (set(), "int"),
}

EFFECT_SPEC = {
    "set_flag":         ({"value"}, "str"),
    "clear_flag":       (set(), "str"),
    "increment_flag":   ({"by"}, "str"),
    # What an act cost the Corse Concern. Its own verb rather than an
    # `increment_flag` on the counter because the counter must only ever rise —
    # see ConcernPressure — and `increment_flag` takes a negative `by`.
    "pressure":         (set(), "pressure"),
    "approval":         (set(), "approval"),
    "give_item":        ({"quantity"}, "item"),
    "take_item":        ({"quantity"}, "item"),
    "give_gold":        (set(), "int"),
    "take_gold":        (set(), "int"),
    "xp":               (set(), "int"),
    "heal":             (set(), "int"),
    "start_quest":      (set(), "quest"),
    "fail_quest":       (set(), "quest"),
    "quest_stage":      (set(), "quest_stage"),
    "recruit":          (set(), "companion"),
    "companion_leaves": (set(), "companion"),
    "romance":          (set(), "romance"),
    "start_combat":     (set(), "encounter"),
    "open_shop":        (set(), "shop"),
    "rest":             (set(), "str"),
    "travel":           (set(), "travel"),
}

# Standing animations an NPC may be authored into. The art behind them —
# <sprite_key>_<pose>.png, cut by tools/slice_assets.py — is no longer required
# or drawn; see check_sprite(). The vocabulary stays closed anyway, because the
# value reaches a column and an unknown one could never mean anything.
POSES = {"sleeping", "kneel"}

# Classes that hold a spell list. Fighter, Rogue, Barbarian and Monk are absent
# on purpose — nothing in this game gives them one.
CASTER_CLASSES = {"Bard", "Cleric", "Druid", "Paladin", "Ranger",
                  "Sorcerer", "Warlock", "Wizard"}

QUEST_STATUS = ("available", "active", "completed", "failed")
COMPANION_STATUS = ("unmet", "met", "active", "camp", "left", "hostile", "dead")
ABILITIES = ("strength", "dexterity", "constitution",
             "intelligence", "wisdom", "charisma")
ITEM_TYPES = ("weapon", "armor", "potion", "scroll", "misc", "treasure")
# Wearable slots beyond the derived three (weapon / shield / body). A misc
# item with one of these is equippable; InventoryService::equip is the reader.
ITEM_SLOTS = ("ring", "amulet", "cloak", "boots")
RANKS = ("front", "back")
OUTCOMES = ("success", "failure", "neutral")
SPELL_RESOLUTIONS = ("attack", "save", "auto", "heal", "buff", "debuff")
SAVE_ABILITIES = ("str", "dex", "con", "int", "wis", "cha")
SAVE_EFFECTS = ("half", "negate")
# Mirrors Conditions::CATALOG. A monster action's `on_fail.condition` must name
# one of these: CombatEngine::applyAttackRider() drops anything it cannot find
# in the catalogue, so a typo here is a rider that rolls no save and applies
# nothing, silently, exactly like the prose `note` it was written to replace.
COMBAT_CONDITIONS = ("blinded", "charmed", "frightened", "grappled",
                     "incapacitated", "invisible", "paralyzed", "poisoned",
                     "prone", "restrained", "stunned", "unconscious")
TARGET_KINDS = ("enemy", "ally", "self", "any", "all_enemies", "all_allies")

# The world-graph vocabularies, mirroring the ENUMs/comments in sql/schema.sql.
REGION_TYPES = ("town", "wilderness", "dungeon")
LOCATION_TYPES = ("square", "street", "gate", "building", "room", "site", "camp",
                  # A place whose whole purpose is a fight built to order;
                  # PitEngine keys off this and the client draws the bout card.
                  "arena")

# The chart's drawing field, mirroring assets/js/ui-map.js. A node's name is
# written below its glyph, so the usable band for `map_pos` y stops short of
# the full height.
MAP_H = 75
MAP_LABEL_Y = 80

# Where a playthrough begins is now per-module — each module names its own
# `start_location_key` — so reachability is judged once per module, over that
# module's own regions. A location no chain of exits can reach is content
# nobody sees; a location reachable only from *another* module's start is
# worse, because it means the two games are joined and neither author meant
# them to be.

# The keys where a jump lands, walked to prove every node is reachable.
JUMP_KEYS = ("next", "on_success", "on_failure")

# The kinds that may declare a `place {location}` block. An NPC's placement is
# a column on their row; an encounter's is encounters.location_id; an item's
# becomes a location_items row (ground loot the Search action surfaces).
PLACE_KINDS = ("npcs", "encounters", "items")


# ---------------------------------------------------------------------------
# Problem collection. Nothing raises: everything wrong with the tree is
# reported in one pass, because fixing content one error per run is miserable.
# ---------------------------------------------------------------------------
class Problems:
    def __init__(self):
        self.errors = []
        self.warnings = []

    def err(self, where, msg):
        self.errors.append((where, msg))

    def warn(self, where, msg):
        self.warnings.append((where, msg))

    def report(self, stream=sys.stderr):
        for label, items in (("ERROR", self.errors), ("warning", self.warnings)):
            for (path, jpath), msg in items:
                loc = f"{path}: {jpath}" if jpath else path
                print(f"{label}  {loc}\n         {msg}", file=stream)


def jp(path, *parts):
    """Extend a JSON path: jp("$.nodes", "greeting", 0) -> "$.nodes.greeting[0]"."""
    for p in parts:
        path += f"[{p}]" if isinstance(p, int) else f".{p}"
    return path


# ---------------------------------------------------------------------------
# Things read out of the rest of the project rather than restated here, so the
# two cannot drift: the SRD skill list, the expression counts, the map names.
# ---------------------------------------------------------------------------
def read_skills():
    """Parse Rules::SKILLS out of the PHP source."""
    src = open(RULES_PHP, encoding="utf-8").read()
    m = re.search(r"public const SKILLS\s*=\s*\[(.*?)\];", src, re.S)
    if not m:
        raise SystemExit(f"cannot find the SKILLS const in {RULES_PHP}")
    skills = re.findall(r"'([a-z_]+)'\s*=>", m.group(1))
    if not skills:
        raise SystemExit(f"SKILLS in {RULES_PHP} parsed as empty")
    return tuple(skills)


@functools.lru_cache(maxsize=1)
def read_max_level():
    """
    Parse Rules::MAX_LEVEL out of the PHP source.

    A module advertises a level range in the picker, and a range running past
    the cap advertises levels the game will not grant. Read rather than
    restated, for the same reason the skill list is.
    """
    src = open(RULES_PHP, encoding="utf-8").read()
    m = re.search(r"public const MAX_LEVEL\s*=\s*(\d+)\s*;", src)
    if not m:
        raise SystemExit(f"cannot find the MAX_LEVEL const in {RULES_PHP}")
    return int(m.group(1))


def read_feats():
    """
    Parse the keys of Feats::CATALOG out of the PHP source.

    A companion's `feats` list is a reference like any other, and the loader's
    whole doctrine is that a dangling reference stops the write. Restating the
    catalogue here would be a second copy free to drift from the first, so the
    keys are read from the file that owns them — the same trick read_skills()
    uses for the SRD skill list, and for the same reason.
    """
    src = open(FEATS_PHP, encoding="utf-8").read()
    m = re.search(r"public const CATALOG\s*=\s*\[(.*?)\n    \];", src, re.S)
    if not m:
        raise SystemExit(f"cannot find the CATALOG const in {FEATS_PHP}")
    keys = re.findall(r"^        '([a-z0-9_]+)'\s*=> \[", m.group(1), re.M)
    if not keys:
        raise SystemExit(f"CATALOG in {FEATS_PHP} parsed as empty")
    return tuple(keys)


@functools.lru_cache(maxsize=None)
def read_flag_constants(pairs):
    """
    The flag names inside a list of (file, constant), as ((flag, owner), ...).

    Missing is fatal rather than ignorable, and that is the whole reason this
    reads the source instead of restating the strings. A silently-empty list
    would leave `tools/trace_content.py` reporting every scene gated on an
    engine flag as unreachable and this loader accepting content that writes a
    flag the engine owns — two wrong answers that both look like authoring
    faults rather than a tool that has been left behind by a rename.
    """
    out = []
    for rel, const in pairs:
        path = os.path.join(ROOT, rel)
        src = open(path, encoding="utf-8").read()
        # Up to the terminating `;`, so a scalar and an array literal are read
        # by one expression; the strings inside are the flag names either way,
        # since a THRESHOLDS entry is keyed by an unquoted number.
        m = re.search(r"const\s+" + re.escape(const) + r"\s*=(.*?);", src, re.S)
        if not m:
            raise SystemExit(
                f"{rel} no longer declares {const} — tools/load_content.py "
                f"reads it to know which flags the engine sets")
        names = re.findall(r"'([a-z0-9_]+)'", m.group(1))
        if not names:
            raise SystemExit(f"{const} in {rel} parsed as no flag names")
        out.extend((n, f"{rel} {const}") for n in names)
    return tuple(out)


def read_engine_flags():
    """Every flag PHP writes, as ((flag, "file CONST"), ...)."""
    return read_flag_constants(ENGINE_FLAG_CONSTANTS)


def read_private_engine_flags():
    """The engine's own bookkeeping among those — see ENGINE_PRIVATE_CONSTANTS."""
    return read_flag_constants(ENGINE_PRIVATE_CONSTANTS)


def read_busts(path):
    f = os.path.join(path, "busts.json")
    if not os.path.exists(f):
        return {}
    return json.load(open(f, encoding="utf-8"))


# ---------------------------------------------------------------------------
# Loading
# ---------------------------------------------------------------------------
class Index:
    """Everything parsed off disk, plus what validation needs to resolve keys."""

    def __init__(self):
        self.by_kind = {k: {} for k in KINDS}   # kind -> key -> obj
        self.paths = {k: {} for k in KINDS}     # kind -> key -> relative path
        self.skills = ()
        self.feats = ()
        self.npc_busts = {}
        self.monster_busts = {}
        self.locations = {}                     # location_key -> location obj
        self.location_region = {}               # location_key -> region_key
        self.location_module = {}               # location_key -> module_key
        self.region_module = {}                 # region_key -> module_key
        self.location_path = {}                 # location_key -> (path, jsonpath)
        self.named_stages = set()               # (quest_key, stage_key) a jump names

    def has(self, kind, key):
        return key in self.by_kind[kind]

    def get(self, kind, key):
        return self.by_kind[kind].get(key)

    def quest_stages(self, quest_key):
        q = self.get("quests", quest_key)
        return q.get("stages", {}) if isinstance(q, dict) else {}

    def npc_sprite(self, npc_key):
        npc = self.get("npcs", npc_key)
        return npc.get("sprite_key") if isinstance(npc, dict) else None

    def bust_count(self, sprite_key, monster=False):
        table = self.monster_busts if monster else self.npc_busts
        return table.get(sprite_key)


def load_tree(problems):
    index = Index()
    index.skills = read_skills()
    index.feats = read_feats()
    index.npc_busts = read_busts(NPC_ART)
    index.monster_busts = read_busts(MONSTER_ART)

    if not os.path.isdir(CONTENT):
        problems.err(("content/", ""), "no content/ directory")
        return index

    for kind in KINDS:
        d = os.path.join(CONTENT, kind)
        if not os.path.isdir(d):
            continue
        for name in sorted(os.listdir(d)):
            if not name.endswith(".json"):
                continue
            rel = f"content/{kind}/{name}"
            stem = name[:-5]
            try:
                obj = json.load(open(os.path.join(d, name), encoding="utf-8"))
            except (json.JSONDecodeError, UnicodeDecodeError) as e:
                problems.err((rel, ""), f"not valid JSON: {e}")
                continue
            if not isinstance(obj, dict):
                problems.err((rel, "$"), "expected a JSON object at the top level")
                continue

            field = KEY_FIELD[kind]
            key = obj.get(field)
            if not isinstance(key, str):
                problems.err((rel, jp("$", field)), f"missing required field {field!r}")
                continue
            if not KEY_RE.match(key):
                problems.err((rel, jp("$", field)),
                             f"malformed key {key!r} — keys are lowercase [a-z0-9_]")
                continue
            if key in index.by_kind[kind]:
                problems.err((rel, jp("$", field)),
                             f"duplicate {kind} key {key!r}, already declared in "
                             f"{index.paths[kind][key]}")
                continue
            if key != stem:
                # Reported but still indexed: references name the key, so
                # checking the rest of the tree against it is still useful.
                problems.err((rel, jp("$", field)),
                             f"key {key!r} does not match the file name {stem!r}")
            index.by_kind[kind][key] = obj
            index.paths[kind][key] = rel
    return index


# ---------------------------------------------------------------------------
# Small typed field checks. Each returns the value or None, and reports once.
# ---------------------------------------------------------------------------
@functools.lru_cache(maxsize=1)
def column_widths():
    """
    `{table: {column: width}}` for every VARCHAR in sql/schema.sql.

    Read out of the schema rather than written down here, so a column that is
    widened stops being a constraint without anybody having to remember this
    function exists.

    This closes a real gap. Everything else in this file checks that content
    means something — that a key resolves, that a node is reachable. Nothing
    checked that a string FITS, so a value longer than its column validated
    cleanly, generated SQL, and then failed halfway through the load with
    `Data too long for column 'source' at row 10` — a row number in a generated
    file, naming neither the content file nor the key. Two monsters had put an
    83-character explanation in `source`, which is a VARCHAR(50).

    Failing at load is the worst time for this: the transaction is already part
    applied, and the person reading the error is holding a database rather than
    a text editor.
    """
    schema = os.path.join(ROOT, "sql", "schema.sql")
    if not os.path.exists(schema):
        return {}
    out, table = {}, None
    with open(schema, encoding="utf-8") as fh:
        lines = fh.read().splitlines()
    for line in lines:
        s = line.strip()
        m = re.match(r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?", s, re.I)
        if m:
            table = m.group(1)
            out[table] = {}
            continue
        if table is None:
            continue
        if s.startswith(")"):
            table = None
            continue
        m = re.match(r"`?(\w+)`?\s+VARCHAR\s*\(\s*(\d+)\s*\)", s, re.I)
        if m:
            out[table][m.group(1)] = int(m.group(2))
    return out


def check_widths(kind, obj, p, where):
    """
    Refuse a string too long for the column it lands in.

    Only fields whose name IS the column name are checked. Several are not —
    `saves` becomes `saves_json`, `dialog` is a filename — and guessing at the
    mapping would produce false errors on content that is fine, which is worse
    than missing a few. The columns that actually bite are the short descriptive
    ones (`source`, `title`, `rarity`), and those are named alike.
    """
    if kind not in TABLE:
        return
    widths = column_widths().get(TABLE[kind][0], {})
    for field, value in obj.items():
        if not isinstance(value, str):
            continue
        limit = widths.get(field)
        if limit is not None and len(value) > limit:
            p.err(
                (where[0], jp(where[1], field)),
                f"{len(value)} characters, but {TABLE[kind][0]}.{field} holds "
                f"{limit}; the load would fail partway through",
            )


def check_fields(obj, spec, p, where, kind=None):
    req, opt = spec
    if not isinstance(obj, dict):
        p.err(where, "expected an object")
        return False
    for k in sorted(req):
        if k not in obj:
            p.err(where, f"missing required field {k!r}")
    for k in obj:
        if k not in req and k not in opt:
            p.err((where[0], jp(where[1], k)), f"unknown field {k!r}")
    if kind is not None:
        check_widths(kind, obj, p, where)
    return True


def want(kind_name, ok, obj, p, where):
    if obj is None or ok(obj):
        return obj
    p.err(where, f"expected {kind_name}, got {type(obj).__name__}")
    return None


def want_str(o, p, w):
    return want("a string", lambda v: isinstance(v, str), o, p, w)


def want_int(o, p, w):
    return want("a number", lambda v: isinstance(v, (int, float)) and not isinstance(v, bool), o, p, w)


def want_bool(o, p, w):
    return want("true or false", lambda v: isinstance(v, bool), o, p, w)


def want_list(o, p, w):
    return want("a list", lambda v: isinstance(v, list), o, p, w)


def want_dict(o, p, w):
    return want("an object", lambda v: isinstance(v, dict), o, p, w)


def want_enum(o, choices, p, w):
    if o is None:
        return None
    if not isinstance(o, str) or o not in choices:
        p.err(w, f"{o!r} is not one of: {', '.join(choices)}")
        return None
    return o


def want_key(o, kind, index, p, w, label=None):
    """A reference to another content file, by key."""
    if o is None:
        return None
    if not isinstance(o, str):
        p.err(w, f"expected a {kind[:-1]} key, got {type(o).__name__}")
        return None
    if not index.has(kind, o):
        p.err(w, f"no {label or kind[:-1]} {o!r} in content/{kind}/")
        return None
    return o


def want_location(key, index, p, w):
    """A reference to a location, by its globally unique location_key."""
    if key is None:
        return None
    if not isinstance(key, str):
        p.err(w, f"expected a location key, got {type(key).__name__}")
        return None
    if key not in index.locations:
        p.err(w, f"no location {key!r} is authored in content/locations/")
        return None
    return key


def dungeon_grid():
    """DungeonGen::GRID_W × GRID_H, read from the class so a resize cannot drift."""
    src = open(os.path.join(ROOT, "app", "lib", "DungeonGen.php"), encoding="utf-8").read()
    w = re.search(r"const GRID_W = (\d+);", src)
    h = re.search(r"const GRID_H = (\d+);", src)
    if not w or not h:
        raise RuntimeError("could not read DungeonGen::GRID_W / GRID_H")
    return int(w.group(1)), int(h.group(1))


def plan_fits(rooms, gx, gy, w, h):
    """The same clearance DungeonGen::fits() requires: one cell of rock."""
    for r in rooms:
        if (gx <= r["gx"] + r["w"] and r["gx"] <= gx + w
                and gy <= r["gy"] + r["h"] and r["gy"] <= gy + h):
            return False
    return True


def check_plan(plan, loc_keys, p, rel):
    """An authored dungeon drawing. Mirrors PlanAuthored::normalize()."""
    if want_dict(plan, p, (rel, "$.plan")) is None:
        return
    check_fields(plan, PLAN_FIELDS, p, (rel, "$.plan"))
    rooms_in = plan.get("rooms")
    if want_list(rooms_in, p, (rel, "$.plan.rooms")) is None:
        return
    if not rooms_in:
        p.err((rel, "$.plan.rooms"), "a dungeon plan needs at least one room")
        return

    grid_w, grid_h = dungeon_grid()
    rooms = []
    by_id = {}
    keys = {}
    for i, r in enumerate(rooms_in):
        at = (rel, jp("$.plan.rooms", i))
        if want_dict(r, p, at) is None:
            continue
        check_fields(r, PLAN_ROOM_FIELDS, p, at)
        gx = r.get("gx")
        gy = r.get("gy")
        w = r.get("w")
        h = r.get("h")
        if want_int(gx, p, (at[0], at[1] + ".gx")) is None:
            continue
        if want_int(gy, p, (at[0], at[1] + ".gy")) is None:
            continue
        if want_int(w, p, (at[0], at[1] + ".w")) is None:
            continue
        if want_int(h, p, (at[0], at[1] + ".h")) is None:
            continue
        gx, gy, w, h = int(gx), int(gy), int(w), int(h)
        if w < 1 or h < 1:
            p.err(at, "a room is at least one cell on a side")
            continue
        if gx < 0 or gy < 0 or gx + w > grid_w or gy + h > grid_h:
            p.err(at, f"sits off the {grid_w}×{grid_h} plan ({gx},{gy} {w}×{h})")
            continue
        if not plan_fits(rooms, gx, gy, w, h):
            p.err(at, "overlaps another room, or leaves no cell for a hall")
            continue
        key = r.get("key")
        if want_str(key, p, (at[0], at[1] + ".key")) is None:
            continue
        if not KEY_RE.match(key):
            p.err((at[0], at[1] + ".key"),
                  f"malformed location key {key!r} — keys are lowercase [a-z0-9_]")
            continue
        if key in keys:
            p.err((at[0], at[1] + ".key"), f"two rooms share the key {key}")
            continue
        if key not in loc_keys:
            p.err((at[0], at[1] + ".key"),
                  f"plan room {key!r} is not a location in this region")
            continue
        keys[key] = True
        rid = int(r["id"]) if "id" in r and isinstance(r["id"], (int, float)) else i
        if rid in by_id:
            p.err(at, f"two rooms share the id {rid}")
            continue
        row = {"id": rid, "gx": gx, "gy": gy, "w": w, "h": h}
        rooms.append(row)
        by_id[rid] = row

    halls = plan.get("halls")
    if halls is None:
        return
    if want_list(halls, p, (rel, "$.plan.halls")) is None:
        return
    seen = set()
    for i, hall in enumerate(halls):
        at = (rel, jp("$.plan.halls", i))
        if want_dict(hall, p, at) is None:
            continue
        check_fields(hall, PLAN_HALL_FIELDS, p, at)
        a = hall.get("a")
        b = hall.get("b")
        if want_int(a, p, (at[0], at[1] + ".a")) is None:
            continue
        if want_int(b, p, (at[0], at[1] + ".b")) is None:
            continue
        a, b = int(a), int(b)
        if a not in by_id or b not in by_id or a == b:
            p.err(at, "does not join two rooms")
            continue
        pair = (min(a, b), max(a, b))
        if pair in seen:
            p.err(at, "the same two rooms are already joined")
            continue
        seen.add(pair)
        door = hall.get("door")
        if door is not None:
            want_enum(door, PLAN_DOORS, p, (at[0], at[1] + ".door"))
        want_bool(hall.get("hidden"), p, (at[0], at[1] + ".hidden"))


# ---------------------------------------------------------------------------
# Conditions and effects
# ---------------------------------------------------------------------------
def check_condition_list(conds, index, p, where):
    if want_list(conds, p, where) is None:
        return
    for i, c in enumerate(conds):
        check_condition(c, index, p, (where[0], jp(where[1], i)))


def check_condition(cond, index, p, where):
    if want_dict(cond, p, where) is None:
        return
    heads = [k for k in cond if k in CONDITION_SPEC]
    if not heads:
        unknown = ", ".join(repr(k) for k in cond) or "an empty object"
        p.err(where, f"no recognised condition key — got {unknown}. "
                     f"Known: {', '.join(sorted(CONDITION_SPEC))}")
        return
    if len(heads) > 1:
        p.err(where, f"ambiguous condition: {', '.join(repr(h) for h in heads)} "
                     f"are all condition keys, and a condition takes one")
        return

    head = heads[0]
    mods, vtype = CONDITION_SPEC[head]
    for k in cond:
        if k != head and k not in mods:
            p.err((where[0], jp(where[1], k)),
                  f"{k!r} is not a valid key beside {head!r}"
                  + (f" (allowed: {', '.join(sorted(mods))})" if mods else ""))

    v = cond[head]
    at = (where[0], jp(where[1], head))
    if vtype == "condition":
        check_condition(v, index, p, at)
    elif vtype == "condition_list":
        check_condition_list(v, index, p, at)
    elif vtype == "int":
        want_int(v, p, at)
    elif vtype == "str":
        want_str(v, p, at)
    elif vtype == "item":
        want_key(v, "items", index, p, at)
    elif vtype == "ability":
        want_enum(v, ABILITIES, p, at)
    elif vtype == "quest":
        if want_key(v, "quests", index, p, at):
            if "status" in cond:
                want_enum(cond["status"], QUEST_STATUS, p, (where[0], jp(where[1], "status")))
            if "stage" in cond:
                check_stage_ref(v, cond["stage"], index, p, (where[0], jp(where[1], "stage")))
            if "resolution" in cond:
                check_resolution_ref(v, cond["resolution"], index, p,
                                     (where[0], jp(where[1], "resolution")))
    elif vtype == "companion":
        want_key(v, "companions", index, p, at)
        if "status" in cond:
            want_enum(cond["status"], COMPANION_STATUS, p, (where[0], jp(where[1], "status")))
        for k in ("approval_at_least", "romance_stage"):
            if k in cond:
                want_int(cond[k], p, (where[0], jp(where[1], k)))
    if head == "flag" and "at_least" in cond:
        want_int(cond["at_least"], p, (where[0], jp(where[1], "at_least")))
    if head == "ability" and "at_least" in cond:
        want_int(cond["at_least"], p, (where[0], jp(where[1], "at_least")))


def check_stage_ref(quest_key, stage_key, index, p, where):
    if want_str(stage_key, p, where) is None:
        return
    stages = index.quest_stages(quest_key)
    if isinstance(stages, dict) and stage_key not in stages:
        p.err(where, f"quest {quest_key!r} has no stage {stage_key!r}")


def check_resolution_ref(quest_key, resolution, index, p, where):
    if want_str(resolution, p, where) is None:
        return
    stages = index.quest_stages(quest_key)
    known = {s.get("resolution") for s in stages.values()
             if isinstance(s, dict) and s.get("terminal")}
    if resolution not in known:
        listed = ", ".join(sorted(r for r in known if r)) or "none"
        p.err(where, f"quest {quest_key!r} has no ending with resolution "
                     f"{resolution!r} (it ends: {listed})")


def check_effect_list(effs, index, p, where):
    if want_list(effs, p, where) is None:
        return
    for i, e in enumerate(effs):
        check_effect(e, index, p, (where[0], jp(where[1], i)))


def check_effect(eff, index, p, where):
    if want_dict(eff, p, where) is None:
        return
    heads = [k for k in eff if k in EFFECT_SPEC]
    if not heads:
        unknown = ", ".join(repr(k) for k in eff) or "an empty object"
        p.err(where, f"no recognised effect key — got {unknown}. "
                     f"Known: {', '.join(sorted(EFFECT_SPEC))}")
        return
    if len(heads) > 1:
        p.err(where, f"ambiguous effect: {', '.join(repr(h) for h in heads)} "
                     f"are all effect keys, and an effect does one thing")
        return

    head = heads[0]
    mods, vtype = EFFECT_SPEC[head]
    for k in eff:
        if k != head and k not in mods:
            p.err((where[0], jp(where[1], k)),
                  f"{k!r} is not a valid key beside {head!r}"
                  + (f" (allowed: {', '.join(sorted(mods))})" if mods else ""))
    for k in ("quantity", "by"):
        if k in eff:
            want_int(eff[k], p, (where[0], jp(where[1], k)))

    v = eff[head]
    at = (where[0], jp(where[1], head))

    # The engine's own flags are not content's to write. `set_flag` could put a
    # threshold flag on without the number that justifies it, `clear_flag`
    # could take one off, and either spelling of a decrement on the counter is
    # the decaying pressure the design rejected — see docs/CONCERN_PRESSURE.md.
    # Refused at the head rather than by convention, because a rule that only
    # exists in a docblock is a rule the next author will not know about.
    if head in ("set_flag", "clear_flag", "increment_flag") and isinstance(v, str):
        for flag, owner in read_engine_flags():
            if v != flag:
                continue
            fix = (' — price the act with {"pressure": n} instead'
                   if "ConcernPressure" in owner else "")
            p.err(at, f"{v!r} is written by the engine ({owner}) and must not "
                      f"be set by content{fix}")

    if vtype == "str":
        want_str(v, p, at)
    elif vtype == "int":
        want_int(v, p, at)
    elif vtype == "pressure":
        # Positive, whole, and small. Zero is an act that cost the Concern
        # nothing, which is written by writing no effect; negative is a counter
        # that comes down. Whole because the engine casts to int and 2.5 would
        # silently become 2 — want_int alone accepts a float. The cap is not
        # arithmetic; it is a reminder that the scale is 1 to 5 and that a stage
        # wanting more than the top threshold in one go has mistaken the units.
        if want_int(v, p, at) is not None and (not isinstance(v, int) or not 1 <= v <= 5):
            p.err(at, f"pressure is a whole price from 1 to 5, not {v!r} — the "
                      f"Concern's ledger only rises, and one act is not the "
                      f"whole of what it can be made to notice")
    elif vtype == "item":
        want_key(v, "items", index, p, at)
    elif vtype == "quest":
        want_key(v, "quests", index, p, at)
    elif vtype == "companion":
        want_key(v, "companions", index, p, at)
    elif vtype == "encounter":
        want_key(v, "encounters", index, p, at)
    elif vtype == "shop":
        # `open_shop` names a merchant. The shop service resolves it against
        # shop_inventory, whose rows come from that npc's authored `shop` list —
        # so a key pointing at an npc without one opens an empty counter, and
        # that is worth failing the build over.
        if want_str(v, p, at) is not None:
            npc = index.get("npcs", v) if index.has("npcs", v) else None
            if npc is None:
                p.err(at, f"open_shop names unknown npc {v!r}")
            elif not npc.get("shop"):
                p.err(at, f"open_shop names {v!r}, who has no `shop` stock list "
                          f"in content/npcs/{v}.json — the counter would be empty")
    elif vtype == "approval":
        if want_dict(v, p, at) is not None:
            for ck, amount in v.items():
                want_key(ck, "companions", index, p, (where[0], jp(at[1], ck)))
                want_int(amount, p, (where[0], jp(at[1], ck)))
    elif vtype == "romance":
        if want_dict(v, p, at) is not None:
            check_fields(v, ({"companion", "stage"}, set()), p, at)
            want_key(v.get("companion"), "companions", index, p, (where[0], jp(at[1], "companion")))
            want_int(v.get("stage"), p, (where[0], jp(at[1], "stage")))
    elif vtype == "quest_stage":
        if want_dict(v, p, at) is not None:
            check_fields(v, ({"quest", "stage"}, set()), p, at)
            qk = want_key(v.get("quest"), "quests", index, p, (where[0], jp(at[1], "quest")))
            if qk:
                check_stage_ref(qk, v.get("stage"), index, p, (where[0], jp(at[1], "stage")))
                if isinstance(v.get("stage"), str):
                    index.named_stages.add((qk, v["stage"]))
    elif vtype == "travel":
        if want_dict(v, p, at) is not None:
            check_fields(v, ({"location"}, set()), p, at)
            want_location(v.get("location"), index, p, (where[0], jp(at[1], "location")))


# ---------------------------------------------------------------------------
# Per-kind validation
# ---------------------------------------------------------------------------
def check_sprite(sprite, art_dir, index, p, where, monster=False, ambient=False):
    """
    The files a sprite_key resolves to, and its expression count.

    A bust is only mandatory for somebody who can appear in the dialogue
    theatre, which is the one thing that draws it. Monsters have always been
    exempt because the packs ship none for a wolf; ambient townsfolk are exempt
    for the stronger reason that they never open a conversation at all. That is
    what lets the pack's children be used — they ship a Face and no Bust.

    `<key>_sheet.png` is deliberately NOT checked, and neither is a pose sheet.
    A walk sheet is eight facings of a figure crossing a tile, and there has
    been nothing to cross since the world became a graph of described scenes on
    2026-08-01: the client asks for `_face` and `_bust` and nothing else, and
    the only `_sheet` left in the JavaScript is create.js compositing paperdoll
    LAYER files, which never pass through here. Requiring one was a rule the
    game had stopped meaning, and it was not free — it refused to write
    content.sql at all over fifty walk sheets that no screen would have drawn,
    which is the loudest possible complaint about the least consequential
    missing art. Paperdoll::bake() still writes a sheet for a created
    character, and create.js still previews the layers; both are outside this
    check and stay as they are.
    """
    if want_str(sprite, p, where) is None:
        return
    needs_bust = not monster and not ambient
    for suffix, fatal in (("_face", True), ("_bust", needs_bust)):
        f = os.path.join(art_dir, f"{sprite}{suffix}.png")
        if os.path.exists(f):
            continue
        rel = os.path.relpath(f, ROOT)
        msg = f"sprite_key {sprite!r} has no {rel}"
        # The monster packs ship no bust for every creature — wolf has none —
        # and a monster only needs one if a node ever puts it on screen.
        (p.err if fatal else p.warn)(where, msg)
    if index.bust_count(sprite, monster) is None:
        p.warn(where, f"sprite_key {sprite!r} is not listed in "
                      f"{os.path.relpath(os.path.join(art_dir, 'busts.json'), ROOT)}; "
                      f"assuming one expression")


def validate_modules(index, p):
    """
    One file per playable game.

    Runs before validate_locations because a region names its module and the
    name has to be checkable. What a module's `start_location_key` points at
    cannot be checked here, though — no location has been indexed yet — so that
    is deferred to check_module_reachability, which needs the graph anyway.
    """
    if not index.by_kind["modules"]:
        p.err(("content/modules/", ""),
              "no modules authored — every region belongs to one, and a party "
              "picks one to play")
        return

    for mk, obj in index.by_kind["modules"].items():
        rel = index.paths["modules"][mk]
        check_fields(obj, FIELDS["modules"], p, (rel, "$"), kind="modules")
        want_str(obj.get("name"), p, (rel, "$.name"))
        want_str(obj.get("blurb"), p, (rel, "$.blurb"))
        want_str(obj.get("attribution"), p, (rel, "$.attribution"))
        want_str(obj.get("start_location_key"), p, (rel, "$.start_location_key"))
        want_bool(obj.get("is_active"), p, (rel, "$.is_active"))
        want_int(obj.get("sort_order"), p, (rel, "$.sort_order"))

        cap = read_max_level()
        lo = want_int(obj.get("level_min"), p, (rel, "$.level_min"))
        hi = want_int(obj.get("level_max"), p, (rel, "$.level_max"))
        for label, v in (("level_min", lo), ("level_max", hi)):
            if v is not None and not 1 <= v <= cap:
                p.err((rel, jp("$", label)),
                      f"{label} {v} is outside 1..{cap}, the range "
                      f"Rules::MAX_LEVEL allows")
        if lo is not None and hi is not None and lo > hi:
            p.err((rel, "$.level_min"),
                  f"level_min {lo} is above level_max {hi}")


def validate_module_ownership(index, p):
    """
    Every module owns at least one region, and every region names a module.

    The second half is enforced field-by-field in validate_locations; this is
    the other direction — a module whose regions were never written is a module
    the picker offers and nobody can play, which fails at character creation
    rather than at import.
    """
    owned = set(index.region_module.values())
    for mk in index.by_kind["modules"]:
        if mk not in owned:
            p.err((index.paths["modules"][mk], "$.module_key"),
                  f"no region names module {mk!r} — a module with no regions "
                  f"has nowhere for a party to stand")


def validate_module_placement(index, p):
    """
    No piece of content may reach into another module's world.

    check_module_reachability walks EXITS, which is most of the boundary but not
    all of it: a `travel` effect, a quest `target`, an NPC `place`, an
    encounter's `place` and a companion's `recruit` all name a location by key,
    and none of them is an exit. A `travel` effect pointing at another module
    would put the party in a game they cannot otherwise reach and from which the
    whole of that game is walkable — the exact failure the boundary exists to
    prevent, arrived at by teleport rather than on foot.

    Worth having because it already happened once in code: the defeat path woke
    a beaten party at `flagon_common_room` as a literal, so losing a fight
    anywhere in the Old City moved the party into Rivermark. Everything a player
    could WALK respected the boundary; the one path that moved them without
    walking did not. Content has the same shape of hole and nothing was checking
    it.

    An item's own module is taken from where it sits — an NPC's `place`, a
    companion's `recruit`, a quest's giver or target, an encounter's `place` or
    `region`. Everything it then names has to agree.
    """
    def module_of(loc_key):
        return index.location_module.get(loc_key)

    def locations_in(obj):
        """Every location key this object names, at any depth."""
        found = []
        stack = [obj]
        while stack:
            cur = stack.pop()
            if isinstance(cur, dict):
                for k, v in cur.items():
                    if k in ("travel", "target", "place", "recruit") and isinstance(v, dict):
                        if isinstance(v.get("location"), str):
                            found.append(v["location"])
                    stack.append(v)
            elif isinstance(cur, list):
                stack.extend(cur)
        return found

    for kind in ("npcs", "dialog", "quests", "encounters", "companions", "items"):
        for key, obj in index.by_kind[kind].items():
            rel = index.paths[kind][key]
            named = [lk for lk in locations_in(obj) if lk in index.locations]

            # Dialogue has no place of its own; it belongs to the NPC who
            # speaks it, and that NPC's own file says where they stand.
            home = None
            if kind == "dialog":
                npc = index.get("npcs", key) or {}
                place = (npc.get("place") or {}).get("location")
                home = module_of(place) if isinstance(place, str) else None
            if home is None:
                for lk in named:
                    home = module_of(lk)
                    if home is not None:
                        break
            if home is None:
                continue

            for lk in named:
                mk = module_of(lk)
                if mk is not None and mk != home:
                    p.err((rel, "$"),
                          f"names {lk!r}, which belongs to module {mk!r}, but "
                          f"this content sits in module {home!r} — content may "
                          f"not reach across the boundary")


def validate_locations(index, p):
    """
    The world graph: one file per region, each holding its locations and the
    exits between them. Runs first, because it is what builds the global
    location index that every `place`, `target`, `recruit` and `travel`
    reference elsewhere in the tree is checked against.
    """
    # Pass 1 — region envelopes, and the global location key index. Location
    # keys are global rather than per-region because everything that references
    # one does so by the bare key, with no region qualifier.
    for rk, obj in index.by_kind["locations"].items():
        rel = index.paths["locations"][rk]
        check_fields(obj, FIELDS["locations"], p, (rel, "$"))
        want_str(obj.get("name"), p, (rel, "$.name"))
        want_str(obj.get("description"), p, (rel, "$.description"))
        want_int(obj.get("sort_order"), p, (rel, "$.sort_order"))
        want_enum(obj.get("region_type"), REGION_TYPES, p, (rel, "$.region_type"))

        plan = obj.get("plan")
        if plan is not None:
            if obj.get("region_type") != "dungeon":
                p.err((rel, "$.plan"), "a plan belongs on a dungeon, not a "
                      f"{obj.get('region_type') or 'region'} with a painted map")
            loc_keys = obj.get("locations") if isinstance(obj.get("locations"), dict) else {}
            check_plan(plan, set(loc_keys), p, rel)

        module = obj.get("module")
        if want_str(module, p, (rel, "$.module")) is not None:
            if not index.has("modules", module):
                p.err((rel, "$.module"),
                      f"unknown module {module!r} — no content/modules/{module}.json")
            else:
                index.region_module[rk] = module

        locs = obj.get("locations")
        if want_dict(locs, p, (rel, "$.locations")) is None:
            continue
        if not locs:
            p.err((rel, "$.locations"), "a region needs at least one location")
        for lk in locs:
            at = (rel, jp("$.locations", lk))
            if not KEY_RE.match(lk):
                p.err(at, f"malformed location key {lk!r} — keys are lowercase [a-z0-9_]")
                continue
            if lk in index.locations:
                p.err(at, f"duplicate location key {lk!r}, already declared in "
                          f"{index.location_path[lk][0]} — location keys are global")
                continue
            index.locations[lk] = locs[lk]
            index.location_region[lk] = rk
            index.location_path[lk] = at
            # Denormalised off the region so the reachability walk can ask which
            # game a location belongs to without a second lookup per edge.
            index.location_module[lk] = index.region_module.get(rk)

    # Pass 2 — each location's own fields and exits. Needs the full index,
    # since an exit may cross into another region's file.
    edges = set()
    for lk, loc in index.locations.items():
        rel, at = index.location_path[lk]
        if not check_fields(loc, LOC_FIELDS, p, (rel, at)):
            continue
        want_str(loc.get("name"), p, (rel, jp(at, "name")))
        want_str(loc.get("description"), p, (rel, jp(at, "description")))
        want_str(loc.get("first_visit"), p, (rel, jp(at, "first_visit")))
        want_enum(loc.get("type"), LOCATION_TYPES, p, (rel, jp(at, "type")))
        for f in ("allow_camp", "hidden_until_visited", "job_board"):
            want_bool(loc.get(f), p, (rel, jp(at, f)))
        want_int(loc.get("inn_cost"), p, (rel, jp(at, "inn_cost")))

        # The chart is drawn on a 100-wide by MAP_H-tall field, and a node's
        # name is written underneath it. A location authored below MAP_LABEL_Y
        # has its label pushed off the bottom of the drawing — which is not a
        # load error (the place works perfectly well) but is invisible until
        # somebody opens the map and finds a nameless dot, so it is said here.
        pos = loc.get("map_pos")
        if want_list(pos, p, (rel, jp(at, "map_pos"))) is not None:
            if len(pos) != 2:
                p.err((rel, jp(at, "map_pos")), "map_pos is [x, y]")
            else:
                for i, v in enumerate(pos):
                    limit = 100 if i == 0 else MAP_H
                    axis = "x" if i == 0 else "y"
                    v = want_int(v, p, (rel, jp(at, "map_pos", i)))
                    if v is None:
                        continue
                    if not (0 <= v <= limit):
                        p.err((rel, jp(at, "map_pos", i)),
                              f"{v} is outside 0–{limit} — map_pos {axis} is a "
                              f"position on the region's map drawing, which is "
                              f"100 by {MAP_H}")
                    elif i == 1 and v > MAP_LABEL_Y:
                        p.warn((rel, jp(at, "map_pos", 1)),
                               f"{v} leaves no room for the name underneath "
                               f"(keep y at {MAP_LABEL_Y} or less)")

        pct = want_int(loc.get("random_encounter_pct"), p,
                       (rel, jp(at, "random_encounter_pct")))
        if pct is not None and not (0 <= pct <= 100):
            p.err((rel, jp(at, "random_encounter_pct")),
                  f"{pct} is outside 0–100 — it is a percent chance per travel hop")

        amb = loc.get("ambience")
        if amb is not None and want_list(amb, p, (rel, jp(at, "ambience"))) is not None:
            for i, line in enumerate(amb):
                want_str(line, p, (rel, jp(at, "ambience", i)))

        loot = loc.get("loot")
        if loot is not None and want_list(loot, p, (rel, jp(at, "loot"))) is not None:
            for i, item in enumerate(loot):
                want_key(item, "items", index, p, (rel, jp(at, "loot", i)), label="item")

        exits = loc.get("exits")
        if exits is None:
            continue
        if want_list(exits, p, (rel, jp(at, "exits"))) is None:
            continue
        for i, e in enumerate(exits):
            eat = (rel, jp(at, "exits", i))
            if not check_fields(e, EXIT_FIELDS, p, eat):
                continue
            want_str(e.get("label"), p, (rel, jp(eat[1], "label")))
            want_bool(e.get("hidden"), p, (rel, jp(eat[1], "hidden")))
            if "conditions" in e:
                check_condition_list(e["conditions"], index, p,
                                     (rel, jp(eat[1], "conditions")))
            to = want_location(e.get("to"), index, p, (rel, jp(eat[1], "to")))
            if to is None:
                continue
            if to == lk:
                p.err(eat, f"exit from {lk!r} to itself")
                continue
            if (lk, to) in edges:
                p.err(eat, f"duplicate exit {lk!r} → {to!r} — location_exits is "
                           f"unique on (from, to)")
            edges.add((lk, to))

    # A one-way passage is nearly always a missing return exit rather than a
    # design choice, so it is flagged — but only flagged: a chute or a
    # collapsing tunnel is legitimate.
    for a, b in sorted(edges):
        if (b, a) not in edges:
            p.warn(index.location_path[a],
                   f"one-way exit: {a!r} → {b!r} has no exit back")

    check_module_reachability(index, p, edges)


def check_module_reachability(index, p, edges):
    """
    Every location reachable from its own module's start, and from no other.

    Two questions, one walk. Conditions and hidden flags are ignored for both:
    they gate when a way opens, not whether one exists.

    The first is the old check, now asked once per module — a location no chain
    of exits reaches is content nobody sees.

    The second is new and is the whole point of modules. A module is kept apart
    from its neighbours by the exit graph and nothing else: there is no global
    region list a player can see, so two clusters with no exit between them are
    isolated by construction. That also means a single stray exit silently
    joins two games, and the symptom — a party wandering out of the Old City
    into Rivermark — would look like a travel bug rather than an authoring one.
    Cheaper to catch here.
    """
    if not index.locations:
        return

    starts = {}          # module_key -> start location_key
    for mk, m in index.by_kind["modules"].items():
        start = m.get("start_location_key")
        if not isinstance(start, str):
            continue     # already reported by validate_modules
        if start not in index.locations:
            p.err((index.paths["modules"][mk], "$.start_location_key"),
                  f"no location {start!r} — reachability not checked for this module")
            continue
        starts[mk] = start

    reached = {}         # module_key -> set of location keys
    for mk, start in starts.items():
        seen, frontier = {start}, [start]
        while frontier:
            cur = frontier.pop()
            for a, b in edges:
                if a == cur and b not in seen:
                    seen.add(b)
                    frontier.append(b)
        reached[mk] = seen

    for lk in index.locations:
        owner = index.location_module.get(lk)
        here = [mk for mk, seen in reached.items() if lk in seen]

        if owner in starts and lk not in reached[owner]:
            p.warn(index.location_path[lk],
                   f"not reachable from {starts[owner]!r}, where module "
                   f"{owner!r} begins")

        # Reached from a module that does not own it: the graphs have grown
        # together. An error, not a warning — this one is never a design choice
        # the way a one-way chute is.
        for mk in here:
            if mk != owner:
                p.err(index.location_path[lk],
                      f"reachable from module {mk!r} (starting at {starts[mk]!r}), "
                      f"but belongs to module {owner!r} — an exit joins two games")


def validate_items(index, p):
    for key, obj in index.by_kind["items"].items():
        rel = index.paths["items"][key]
        check_fields(obj, FIELDS["items"], p, (rel, "$"), kind="items")
        want_enum(obj.get("item_type"), ITEM_TYPES, p, (rel, "$.item_type"))
        want_str(obj.get("name"), p, (rel, "$.name"))
        for f in ("description", "rarity", "damage_dice", "damage_type",
                  "armor_type", "image_url", "icon"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("weight", "value_gp", "armor_bonus"):
            want_int(obj.get(f), p, (rel, jp("$", f)))
        want_dict(obj.get("properties"), p, (rel, "$.properties"))
        if "slot" in obj:
            want_enum(obj["slot"], ITEM_SLOTS, p, (rel, "$.slot"))
        # A scroll (or any use_effect) that casts names a real spell, checked
        # now for the usual reason: a dangling key would surface as a scroll
        # that crumbles to no effect, with no error anywhere.
        use = (obj.get("properties") or {}).get("use_effect")
        if isinstance(use, dict) and "cast" in use:
            want_key(use["cast"], "spells", index, p,
                     (rel, "$.properties.use_effect.cast"), label="spell")


def validate_monsters(index, p):
    for key, obj in index.by_kind["monsters"].items():
        rel = index.paths["monsters"][key]
        check_fields(obj, FIELDS["monsters"], p, (rel, "$"), kind="monsters")
        want_str(obj.get("name"), p, (rel, "$.name"))
        for f in ("size", "type", "alignment", "hit_dice", "speed",
                  "image_url", "source"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("armor_class", "hit_points", "challenge_rating",
                  "experience_points") + ABILITIES:
            want_int(obj.get(f), p, (rel, jp("$", f)))
        want_dict(obj.get("traits"), p, (rel, "$.traits"))
        want_dict(obj.get("saves"), p, (rel, "$.saves"))
        for f in ("resistances", "immunities", "vulnerabilities",
                  "condition_immunities"):
            want_list(obj.get(f), p, (rel, jp("$", f)))
        rank = want_enum(obj.get("rank_pref"), RANKS, p, (rel, "$.rank_pref"))

        # Loot: [{"item": "<item_key>", "chance": 50}, {"gold": "2d6"}]. Item
        # keys are checked here so a drop cannot name something the import
        # never created — the same promise every other cross-reference makes.
        loot = obj.get("loot")
        if loot is not None and want_list(loot, p, (rel, "$.loot")) is not None:
            for i, entry in enumerate(loot):
                w = (rel, jp("$.loot", i))
                if want_dict(entry, p, w) is None:
                    continue
                has_item, has_gold = "item" in entry, "gold" in entry
                if has_item == has_gold:
                    p.err(w, "each loot entry names exactly one of 'item' or 'gold'")
                    continue
                if has_item:
                    if entry["item"] not in index.by_kind["items"]:
                        p.err(w, f"names unknown item '{entry['item']}'")
                    chance = entry.get("chance", 100)
                    if not isinstance(chance, int) or not 1 <= chance <= 100:
                        p.err(w, "'chance' must be an integer percent, 1-100")
                    unknown = set(entry) - {"item", "chance", "qty"}
                else:
                    if not isinstance(entry["gold"], (str, int)):
                        p.err(w, "'gold' is a dice expression like \"2d6\" or a flat integer")
                    unknown = set(entry) - {"gold", "chance"}
                if unknown:
                    p.err(w, f"unknown loot field(s): {', '.join(sorted(unknown))}")

        if "sprite_key" in obj:
            check_sprite(obj["sprite_key"], MONSTER_ART, index, p,
                         (rel, "$.sprite_key"), monster=True)

        actions = obj.get("actions")
        if actions is not None and want_list(actions, p, (rel, "$.actions")) is not None:
            for i, a in enumerate(actions):
                if want_dict(a, p, (rel, jp("$.actions", i))) is not None:
                    check_action_rider(a, p, (rel, jp("$.actions", i)))
            if rank == "back" and not any(isinstance(a, dict) and a.get("type") == "ranged"
                                          for a in actions):
                # It would take the back rank and then spend the fight unable to
                # reach anything the front rank is shielding.
                p.warn((rel, "$.rank_pref"),
                       "rank_pref is 'back' but no action has \"type\": \"ranged\" — "
                       "it will stand at the back with nothing it can hit")


def check_action_rider(action, p, where):
    """The optional save/on_fail pair on a monster action.

    Both halves are needed or neither does anything: CombatEngine only rolls the
    save when it has a condition to impose, and only imposes the condition when
    it has a save to fail. Half a rider is the same silent nothing as writing it
    in the prose `note`, which is what this pair exists to replace.
    """
    save, on_fail = action.get("save"), action.get("on_fail")
    if save is None and on_fail is None:
        return
    if save is None or on_fail is None:
        missing = "save" if save is None else "on_fail"
        p.err(where, f"has one half of a rider but not the other — {missing!r} is "
                     "missing, so nothing will be applied")
        return

    if want_dict(save, p, (where[0], where[1] + ".save")) is not None:
        want_enum(save.get("ability"), SAVE_ABILITIES, p, (where[0], where[1] + ".save.ability"))
        if "ability" not in save:
            p.err((where[0], where[1] + ".save"), "no 'ability' — the save has nothing to roll")
        dc = want_int(save.get("dc"), p, (where[0], where[1] + ".save.dc"))
        if dc is not None and not 1 <= dc <= 30:
            p.err((where[0], where[1] + ".save.dc"), f"DC {dc} is outside 1-30")

    if want_dict(on_fail, p, (where[0], where[1] + ".on_fail")) is not None:
        want_enum(on_fail.get("condition"), COMBAT_CONDITIONS, p,
                  (where[0], where[1] + ".on_fail.condition"))
        if "condition" not in on_fail:
            p.err((where[0], where[1] + ".on_fail"),
                  "no 'condition' — failing the save would cost nothing")
        if "rounds" in on_fail:
            rounds = want_int(on_fail["rounds"], p, (where[0], where[1] + ".on_fail.rounds"))
            if rounds is not None and rounds < 1:
                p.err((where[0], where[1] + ".on_fail.rounds"),
                      "must be 1 or more; omit it for a condition with no duration")
        if "repeat_save" in on_fail:
            want_bool(on_fail["repeat_save"], p, (where[0], where[1] + ".on_fail.repeat_save"))
        # Open-ended and no way out: nothing in the engine lifts a condition that
        # has neither a clock nor a save to shake it off.
        if not on_fail.get("rounds") and not on_fail.get("repeat_save"):
            p.warn((where[0], where[1] + ".on_fail"),
                   "no 'rounds' and no 'repeat_save' — the condition will last "
                   "the rest of the fight with nothing able to end it")


def validate_spells(index, p):
    for key, obj in index.by_kind["spells"].items():
        rel = index.paths["spells"][key]
        check_fields(obj, FIELDS["spells"], p, (rel, "$"), kind="spells")
        # `classes` is what level-up offers from, so an unknown class name here
        # is a spell that silently never appears on anyone's list.
        if "classes" in obj:
            for i, c in enumerate(want_list(obj["classes"], p, (rel, "$.classes")) or []):
                if c not in CASTER_CLASSES:
                    p.err((rel, jp("$.classes", i)),
                          f"{c!r} does not cast spells; expected one of "
                          f"{sorted(CASTER_CLASSES)}")
        want_str(obj.get("name"), p, (rel, "$.name"))
        for f in ("school", "casting_time", "range_text", "components",
                  "duration", "description", "damage_dice", "damage_type", "source"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("level", "duration_rounds"):
            want_int(obj.get(f), p, (rel, jp("$", f)))
        for f in ("concentration", "reaches_back_rank"):
            want_bool(obj.get(f), p, (rel, jp("$", f)))
        want_enum(obj.get("resolution"), SPELL_RESOLUTIONS, p, (rel, "$.resolution"))
        want_enum(obj.get("save_ability"), SAVE_ABILITIES, p, (rel, "$.save_ability"))
        want_enum(obj.get("save_effect"), SAVE_EFFECTS, p, (rel, "$.save_effect"))
        want_enum(obj.get("target_kind"), TARGET_KINDS, p, (rel, "$.target_kind"))
        want_dict(obj.get("effect"), p, (rel, "$.effect"))

        # "At Higher Levels". The engine scales by adding dice of the SAME size
        # to the base expression — it cannot roll "8d6 plus 1d8" — so a
        # mismatched die here would be a promise the cast quietly breaks.
        hl = obj.get("higher_level")
        if hl is not None and want_dict(hl, p, (rel, "$.higher_level")) is not None:
            unknown = set(hl) - {"dice_per_level"}
            if unknown:
                p.err((rel, "$.higher_level"),
                      f"unknown field(s): {sorted(unknown)} — only dice_per_level scales")
            per = hl.get("dice_per_level")
            m_per = re.fullmatch(r"(\d+)d(\d+)", str(per or ""))
            if m_per is None:
                p.err((rel, "$.higher_level.dice_per_level"),
                      "must be dice like \"1d8\"")
            else:
                m_base = re.match(r"(\d+)d(\d+)", str(obj.get("damage_dice") or ""))
                if m_base is None:
                    p.err((rel, "$.higher_level"),
                          "spell has no damage_dice for the extra dice to join")
                elif m_base.group(2) != m_per.group(2):
                    p.err((rel, "$.higher_level.dice_per_level"),
                          f"d{m_per.group(2)} cannot join the base d{m_base.group(2)} dice")
            if int(obj.get("level", 0)) < 1:
                p.err((rel, "$.higher_level"),
                      "a cantrip scales by caster level, not by slot — drop this")


def validate_npcs(index, p):
    for key, obj in index.by_kind["npcs"].items():
        rel = index.paths["npcs"][key]
        check_fields(obj, FIELDS["npcs"], p, (rel, "$"), kind="npcs")
        for f in ("name", "role", "description", "image_url"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("is_merchant", "is_quest_giver", "is_ambient"):
            want_bool(obj.get(f), p, (rel, jp("$", f)))
        if "sprite_key" in obj:
            check_sprite(obj["sprite_key"], NPC_ART, index, p, (rel, "$.sprite_key"),
                         ambient=bool(obj.get("is_ambient")))
        if "pose" in obj:
            # Still a closed set, because the column is still written and an
            # unknown value would be a word nothing could ever act on. The art
            # behind it is no longer checked: a pose is a variant walk sheet,
            # and check_sprite() above says why there is nothing left that
            # draws one. The field survives its renderer on purpose — it
            # records that this NPC is asleep or kneeling, which is true of the
            # scene whether or not a picture of it is ever shown.
            pose = want_str(obj["pose"], p, (rel, "$.pose"))
            if pose is not None and pose not in POSES:
                p.err((rel, "$.pose"), f"pose {pose!r} is not one of {sorted(POSES)}")
        if "shop" in obj:
            # A shop is a list of stocked items. Every reference is resolved
            # now, because a dangling item key would otherwise surface as an
            # empty shelf with no error anywhere.
            if not isinstance(obj["shop"], list) or not obj["shop"]:
                p.err((rel, "$.shop"), "shop must be a non-empty list of stock entries")
            else:
                if not obj.get("is_merchant"):
                    p.warn((rel, "$.shop"), "has a shop but is_merchant is not set; "
                                            "dialogue effects gate on the flag")
                for i, entry in enumerate(obj["shop"]):
                    at = (rel, jp("$.shop", i))
                    if not isinstance(entry, dict) or "item" not in entry:
                        p.err(at, "each stock entry needs at least {\"item\": <item_key>}")
                        continue
                    unknown = set(entry) - {"item", "stock", "markup_pct"}
                    if unknown:
                        p.err(at, f"unknown stock field(s): {sorted(unknown)}")
                    want_key(entry["item"], "items", index, p, at, label="item")
                    for f in ("stock", "markup_pct"):
                        if f in entry and (not isinstance(entry[f], int) or entry[f] < 0):
                            p.err(at, f"{f} must be a non-negative integer")
        if "dialog" in obj:
            want_key(obj["dialog"], "dialog", index, p, (rel, "$.dialog"), label="dialog file")

    spoken_for = {o["dialog"] for o in index.by_kind["npcs"].values()
                  if isinstance(o.get("dialog"), str)}
    for key in index.by_kind["dialog"]:
        if key not in spoken_for:
            p.warn((index.paths["dialog"][key], "$"),
                   "no NPC names this file in its `dialog` field, so nothing will ever open it")


def validate_dialog(index, p):
    for key, obj in index.by_kind["dialog"].items():
        rel = index.paths["dialog"][key]
        check_fields(obj, FIELDS["dialog"], p, (rel, "$"))
        owner = want_key(obj.get("npc_key"), "npcs", index, p, (rel, "$.npc_key"))

        nodes = obj.get("nodes")
        if want_dict(nodes, p, (rel, "$.nodes")) is None:
            continue
        start = obj.get("start")
        if want_str(start, p, (rel, "$.start")) is not None and start not in nodes:
            p.err((rel, "$.start"), f"start node {start!r} is not in `nodes`")

        for nk in nodes:
            if not KEY_RE.match(nk):
                p.err((rel, jp("$.nodes", nk)),
                      f"malformed node key {nk!r} — keys are lowercase [a-z0-9_]")

        for nk, node in nodes.items():
            variants = node if isinstance(node, list) else [node]
            at = jp("$.nodes", nk)
            if isinstance(node, list):
                if not variants:
                    p.err((rel, at), "empty variant list — a node has to say something")
                for i, v in enumerate(variants):
                    # A `once` variant stands down after it has been shown, so
                    # the variants below it ARE reachable — that is the whole
                    # point of the flag, and it is how a node that hands
                    # something over says "and not twice". Only a variant that
                    # is both unconditional and repeatable swallows the rest.
                    if (isinstance(v, dict) and "conditions" not in v
                            and not v.get("once") and i != len(variants) - 1):
                        p.err((rel, jp(at, i)),
                              "an unconditional variant must come last; the variants after "
                              "it can never be reached")
                if variants and isinstance(variants[-1], dict) and "conditions" in variants[-1]:
                    p.err((rel, at),
                          "every variant is conditional — with no fallback the conversation "
                          "dead-ends when none of them pass")
                check_pools(variants, p, (rel, at))
                check_shadowing(variants, p, (rel, at))
            for i, v in enumerate(variants):
                vat = jp(at, i) if isinstance(node, list) else at
                check_node(v, nodes, owner, index, p, (rel, vat), variant=isinstance(node, list))

        # Reachability. Nothing declares the edges, so walk them.
        if isinstance(start, str) and start in nodes:
            seen, frontier = {start}, [start]
            while frontier:
                cur = frontier.pop()
                node = nodes[cur]
                for v in (node if isinstance(node, list) else [node]):
                    for tgt in node_jumps(v):
                        if tgt in nodes and tgt not in seen:
                            seen.add(tgt)
                            frontier.append(tgt)
            for nk in nodes:
                if nk not in seen:
                    p.err((rel, jp("$.nodes", nk)),
                          f"unreachable from {start!r} — no next/on_success/on_failure "
                          f"ever names it")


def check_pools(variants, p, where):
    """
    Validate the `pool` key across one node's variant list.

    A pool is several interchangeable variants at one priority, rotated between
    visits so that a settled playthrough stops hearing the same line forever.
    DialogEngine::chooseVariant() is the runtime rule; these are the three ways
    an author can write a pool that does not mean what it looks like.

      * **Not the fallback.** A pooled variant must carry `conditions`. The last
        variant in a node is the unconditional fallback and the engine leans on
        it existing — every variant list ends in something that always matches,
        or `node()` throws. Rotating the fallback would also make the pool the
        node's whole behaviour rather than one priority within it, which is not
        what the key is for.

      * **Contiguous.** Members of one pool must sit together. Nothing in the
        engine requires it — it gathers by name — but a pool split around an
        unrelated variant reads, correctly, as a list where priority runs top to
        bottom, and it does not: the intervening variant is either dead (the
        pool's first member outranks it) or it splits the pool (it outranks the
        members below). Either way the file lies about its own ordering, so the
        loader insists the block is a block.

      * **More than one member.** A pool of one rotates between itself and
        itself. It is not wrong, and it is never what somebody meant to write —
        usually a second member lost to a bad merge — so it warns rather than
        failing the load.
    """
    rel, at = where
    seen = {}          # pool name -> [indices, in order]
    for i, v in enumerate(variants):
        if not isinstance(v, dict) or "pool" not in v:
            continue
        name = v["pool"]
        vat = (rel, jp(at, i, "pool"))
        if want_str(name, p, vat) is None:
            continue
        if not KEY_RE.match(name):
            p.err(vat, f"malformed pool name {name!r} — pool names are lowercase [a-z0-9_]")
            continue
        if "conditions" not in v:
            p.err(vat,
                  "a pooled variant needs `conditions` — the unconditional variant is the "
                  "node's fallback and must stay outside the rotation")
        seen.setdefault(name, []).append(i)

    for name, idx in seen.items():
        if idx != list(range(idx[0], idx[-1] + 1)):
            gaps = [i for i in range(idx[0], idx[-1] + 1) if i not in idx]
            p.err((rel, jp(at, idx[0], "pool")),
                  f"pool {name!r} is split by variant(s) {gaps} — members of a pool must be "
                  f"contiguous, so the file reads in the order the engine applies")
        if len(idx) == 1:
            p.warn((rel, jp(at, idx[0], "pool")),
                   f"pool {name!r} has one member, so it rotates between itself and itself; "
                   f"either give it siblings or drop the key")


# The `*_at_least` family, where a bigger threshold implies a smaller one.
AT_LEAST_KEYS = {"approval_at_least", "gold_at_least", "level_at_least",
                 "at_least", "romance_stage"}


def _canon(v):
    """A stable form for a predicate, so two can be compared by value."""
    if isinstance(v, dict):
        return tuple(sorted((k, _canon(x)) for k, x in v.items()))
    if isinstance(v, list):
        return tuple(_canon(x) for x in v)
    return v


def _predicate_implies(need, have):
    """One predicate implying another: identical, or a weaker `*_at_least`."""
    if _canon(need) == _canon(have):
        return True
    if not isinstance(need, dict) or not isinstance(have, dict):
        return False
    if len(need) != 1 or len(have) != 1:
        return False
    (nk, nv), = need.items()
    (hk, hv), = have.items()
    return (nk == hk and nk in AT_LEAST_KEYS
            and isinstance(nv, (int, float)) and not isinstance(nv, bool)
            and isinstance(hv, (int, float)) and not isinstance(hv, bool)
            and hv >= nv)


def _implies(a, b):
    """Does every predicate in `a` hold wherever all of `b` hold?"""
    return all(any(_predicate_implies(need, have) for have in b) for need in a)


def check_shadowing(variants, p, where):
    """
    A variant an earlier variant will always beat.

    The rule above this one catches the blunt version — an UNCONDITIONAL variant
    swallows everything below it. This is the quiet version: a conditional
    variant whose conditions are a SUBSET of a later one's. It reads as two
    scenes and it is one. The earlier matches wherever the later does, it is
    tried first, so the later is dead — written, costed, and never spoken.

    It is worth a rule because of where the content sits: `aldric.greeting` is
    53 ordered variants, ilse 49, kessa 46, sera 41. Nobody holds 53 condition
    sets in their head while adding the 54th.

    Implication is tested conservatively — identical predicates, or the same
    `*_at_least` key with a lower threshold first. Anything cleverer would need
    to know that `{"flag": "x", "equals": "a"}` and `{"flag": "x", "equals":
    "b"}` exclude each other, which is the condition vocabulary and belongs to
    Requirements. So this can miss a shadow; it cannot invent one.

    A `once` variant stands down when spent and a pooled variant rotates, so
    neither is ever the permanent winner — both are skipped as shadowers, for
    the same reason `once` is skipped by the fallback rule above.

    DialogueLint::checkVariantShadowing() is the PHP twin; the message is shared
    with it verbatim, as with every other rule here.
    """
    rel, at = where
    for j, later in enumerate(variants):
        if not isinstance(later, dict):
            continue
        late_conds = later.get("conditions")
        if not isinstance(late_conds, list) or not late_conds:
            continue
        for i in range(j):
            earlier = variants[i]
            if not isinstance(earlier, dict):
                continue
            if earlier.get("once") or "pool" in earlier:
                continue
            early_conds = earlier.get("conditions")
            if not isinstance(early_conds, list) or not early_conds:
                continue
            if not _implies(early_conds, late_conds):
                continue
            p.err((rel, jp(at, j)),
                  f"can never be shown — variant {i} above it is satisfied wherever "
                  f"this one is, so it always wins. Narrow the earlier variant, or "
                  f"move this one above it.")
            break


def node_jumps(variant):
    if not isinstance(variant, dict):
        return
    for c in variant.get("choices") or []:
        if not isinstance(c, dict):
            continue
        if isinstance(c.get("next"), str):
            yield c["next"]
        check = c.get("check")
        if isinstance(check, dict):
            for k in ("on_success", "on_failure"):
                if isinstance(check.get(k), str):
                    yield check[k]


def check_node(node, nodes, owner, index, p, where, variant):
    rel, at = where
    if not check_fields(node, NODE_FIELDS, p, where):
        return
    want_str(node.get("text"), p, (rel, jp(at, "text")))
    want_bool(node.get("once"), p, (rel, jp(at, "once")))
    if "conditions" in node:
        if not variant:
            p.err((rel, jp(at, "conditions")),
                  "only a variant may carry conditions; make this node a list of variants")
        check_condition_list(node["conditions"], index, p, (rel, jp(at, "conditions")))
    if "pool" in node and not variant:
        # A pool is a choice between siblings, and a bare node has none. Said
        # here as well as in check_pools because check_pools only ever sees a
        # list, so a `pool` on a lone object would otherwise pass unmentioned.
        p.err((rel, jp(at, "pool")),
              "only a variant may join a pool; make this node a list of variants")
    if "on_enter" in node:
        check_effect_list(node["on_enter"], index, p, (rel, jp(at, "on_enter")))

    speaker = owner
    if "speaker" in node:
        speaker = want_key(node["speaker"], "npcs", index, p, (rel, jp(at, "speaker"))) or owner
    if "expression" in node:
        e = want_int(node["expression"], p, (rel, jp(at, "expression")))
        sprite = index.npc_sprite(speaker) if speaker else None
        count = index.bust_count(sprite) or 1
        if e is not None and (e < 1 or e > count):
            p.err((rel, jp(at, "expression")),
                  f"expression {e} is outside 1..{count} for sprite_key {sprite!r} "
                  f"(assets/images/npcs/busts.json)")

    if want_list(node.get("interjections"), p, (rel, jp(at, "interjections"))) is not None:
        for i, ij in enumerate(node["interjections"]):
            iat = (rel, jp(at, "interjections", i))
            if not check_fields(ij, INTERJECTION_FIELDS, p, iat):
                continue
            want_key(ij.get("companion"), "companions", index, p, (rel, jp(iat[1], "companion")))
            want_str(ij.get("text"), p, (rel, jp(iat[1], "text")))
            want_bool(ij.get("once"), p, (rel, jp(iat[1], "once")))
            if "conditions" in ij:
                check_condition_list(ij["conditions"], index, p, (rel, jp(iat[1], "conditions")))

    choices = node.get("choices")
    if choices is None:
        return
    if want_list(choices, p, (rel, jp(at, "choices"))) is None:
        return
    for i, c in enumerate(choices):
        check_choice(c, nodes, index, p, (rel, jp(at, "choices", i)))


def check_choice(choice, nodes, index, p, where):
    rel, at = where
    if not check_fields(choice, CHOICE_FIELDS, p, where):
        return
    want_str(choice.get("label"), p, (rel, jp(at, "label")))
    want_str(choice.get("tag"), p, (rel, jp(at, "tag")))
    want_bool(choice.get("end"), p, (rel, jp(at, "end")))
    if "conditions" in choice:
        check_condition_list(choice["conditions"], index, p, (rel, jp(at, "conditions")))
    if "effects" in choice:
        check_effect_list(choice["effects"], index, p, (rel, jp(at, "effects")))
    if choice.get("tag") and "conditions" not in choice:
        p.warn((rel, jp(at, "tag")),
               "a tag pill with no conditions advertises a requirement that is not enforced")

    exits = [k for k in ("next", "check") if k in choice] + \
            (["end"] if choice.get("end") else [])
    if len(exits) > 1:
        p.err(where, f"{', '.join(exits)} together — a choice leads one way")

    if "next" in choice:
        nxt = want_str(choice["next"], p, (rel, jp(at, "next")))
        if nxt is not None and nxt not in nodes:
            p.err((rel, jp(at, "next")), f"no node {nxt!r} in this file")

    if "check" in choice:
        cat = (rel, jp(at, "check"))
        chk = choice["check"]
        if not check_fields(chk, CHECK_FIELDS, p, cat):
            return
        skill = chk.get("skill")
        if want_str(skill, p, (rel, jp(cat[1], "skill"))) is not None and skill not in index.skills:
            p.err((rel, jp(cat[1], "skill")),
                  f"{skill!r} is not an SRD skill (Rules::SKILLS): {', '.join(index.skills)}")
        want_int(chk.get("dc"), p, (rel, jp(cat[1], "dc")))
        for k in ("on_success", "on_failure"):
            tgt = want_str(chk.get(k), p, (rel, jp(cat[1], k)))
            if tgt is not None and tgt not in nodes:
                p.err((rel, jp(cat[1], k)), f"no node {tgt!r} in this file")


def validate_encounters(index, p):
    for key, obj in index.by_kind["encounters"].items():
        rel = index.paths["encounters"][key]
        check_fields(obj, FIELDS["encounters"], p, (rel, "$"), kind="encounters")
        for f in ("name", "description", "difficulty", "victory_flag", "defeat_flag"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("is_random", "ambush", "allow_flee", "allow_parley", "scale"):
            want_bool(obj.get(f), p, (rel, jp("$", f)))

        # An ambush is a fight that happens to the party for standing
        # somewhere, so it has to have a somewhere to stand.
        if obj.get("ambush") and not isinstance(obj.get("place"), dict):
            p.err((rel, "$.ambush"),
                  "an ambush needs `place {location}` — it is what happens when "
                  "the party arrives there")
        if obj.get("ambush") and obj.get("is_random"):
            p.err((rel, "$.ambush"),
                  "a random encounter is already something that happens to the "
                  "party; drop `ambush`")

        # A fight that is also an answer: winning it advances a quest, so the
        # ending is reachable by the party that fought rather than only by the
        # party that talked.
        #
        # Checked here because nothing was checking it at all. The format is a
        # flat "quest_key:stage_key" string — CombatEngine::victoryQuestStage()
        # splits it on the first colon — and an object written instead was
        # accepted, stored, and did nothing, which is precisely the silent
        # no-op this loader exists to prevent. Registering the stage also lets
        # it count toward quest reachability; before, a stage reachable only by
        # winning a fight was reported unreachable and had to be given a
        # redundant dialogue path to satisfy the check.
        if "victory_quest_stage" in obj:
            at = (rel, "$.victory_quest_stage")
            spec = obj["victory_quest_stage"]
            if not isinstance(spec, str):
                p.err(at, f"expected a 'quest_key:stage_key' string, got "
                          f"{type(spec).__name__} — an object here loads without "
                          f"complaint and then does nothing")
            elif spec.count(":") != 1:
                p.err(at, f"malformed {spec!r} — it is one quest key and one "
                          f"stage key, separated by a single colon")
            else:
                qk, sk = (part.strip() for part in spec.split(":", 1))
                if want_key(qk, "quests", index, p, at, label="quest") and sk:
                    check_stage_ref(qk, sk, index, p, at)
                    index.named_stages.add((qk, sk))

        # A fight is either placed (waits at one location) or random (drawn
        # from a region's pool during travel). The two are exclusive, and each
        # names its scope its own way: `place {location}` or `region`.
        if "region" in obj:
            want_key(obj["region"], "locations", index, p, (rel, "$.region"),
                     label="region")
        if obj.get("is_random"):
            if "region" not in obj:
                p.err((rel, "$"), "a random encounter needs `region` — it says "
                                  "which region's travel rolls may draw it")
            if "place" in obj:
                p.err((rel, "$.place"), "a random encounter has no fixed place; "
                                        "drop `place` or drop `is_random`")
        elif "region" in obj:
            p.err((rel, "$.region"), "`region` is only for random encounters; a "
                                     "placed fight says `place {location}` instead")

        mons = obj.get("monsters")
        if want_list(mons, p, (rel, "$.monsters")) is not None:
            if not mons:
                p.err((rel, "$.monsters"), "an encounter needs at least one monster")
            for i, m in enumerate(mons):
                mat = (rel, jp("$.monsters", i))
                if not check_fields(m, ({"monster"}, {"quantity", "rank"}), p, mat):
                    continue
                want_key(m.get("monster"), "monsters", index, p, (rel, jp(mat[1], "monster")))
                want_int(m.get("quantity"), p, (rel, jp(mat[1], "quantity")))
                if "rank" in m:
                    want_enum(m["rank"], RANKS, p, (rel, jp(mat[1], "rank")))

        if "parley_node" in obj:
            check_parley(obj["parley_node"], index, p, (rel, "$.parley_node"))
            if not obj.get("allow_parley"):
                p.warn((rel, "$.parley_node"),
                       "parley_node is set but allow_parley is not true, so the button "
                       "is never offered")


def check_parley(spec, index, p, where):
    """`npc_key:node_key` — the fight that becomes a conversation."""
    if want_str(spec, p, where) is None:
        return
    if spec.count(":") != 1:
        p.err(where, f"{spec!r} is not \"npc_key:node_key\"")
        return
    npc_key, node_key = spec.split(":")
    if not index.has("npcs", npc_key):
        p.err(where, f"no npc {npc_key!r} in content/npcs/")
        return
    dialog_key = index.get("npcs", npc_key).get("dialog")
    if not isinstance(dialog_key, str) or not index.has("dialog", dialog_key):
        p.err(where, f"npc {npc_key!r} has no dialogue file to open {node_key!r} in")
        return
    if node_key not in (index.get("dialog", dialog_key).get("nodes") or {}):
        p.err(where, f"content/dialog/{dialog_key}.json has no node {node_key!r}")


def validate_quests(index, p):
    for key, obj in index.by_kind["quests"].items():
        rel = index.paths["quests"][key]
        check_fields(obj, FIELDS["quests"], p, (rel, "$"), kind="quests")
        for f in ("title", "description"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        for f in ("act", "required_level"):
            want_int(obj.get(f), p, (rel, jp("$", f)))
        for f in ("on_job_board", "is_active"):
            want_bool(obj.get(f), p, (rel, jp("$", f)))
        if "giver" in obj:
            want_key(obj["giver"], "npcs", index, p, (rel, "$.giver"))
        if "companion" in obj:
            want_key(obj["companion"], "companions", index, p, (rel, "$.companion"))

        rewards = obj.get("rewards")
        if rewards is not None and want_dict(rewards, p, (rel, "$.rewards")) is not None:
            check_fields(rewards, (set(), {"xp", "gold", "item"}), p, (rel, "$.rewards"))
            want_int(rewards.get("xp"), p, (rel, "$.rewards.xp"))
            want_int(rewards.get("gold"), p, (rel, "$.rewards.gold"))
            if "item" in rewards:
                want_key(rewards["item"], "items", index, p, (rel, "$.rewards.item"))

        stages = obj.get("stages")
        if want_dict(stages, p, (rel, "$.stages")) is None:
            continue
        if not stages:
            p.err((rel, "$.stages"), "a quest needs at least one stage")
            continue

        for sk, stage in stages.items():
            sat = (rel, jp("$.stages", sk))
            if not KEY_RE.match(sk):
                p.err(sat, f"malformed stage key {sk!r} — keys are lowercase [a-z0-9_]")
            if not check_fields(stage, STAGE_FIELDS, p, sat):
                continue
            for f in ("title", "objective", "journal", "resolution"):
                want_str(stage.get(f), p, (rel, jp(sat[1], f)))
            want_bool(stage.get("terminal"), p, (rel, jp(sat[1], "terminal")))
            want_enum(stage.get("outcome"), OUTCOMES, p, (rel, jp(sat[1], "outcome")))
            if "effects" in stage:
                check_effect_list(stage["effects"], index, p, (rel, jp(sat[1], "effects")))
            if "target" in stage:
                # Where the tracker points while this stage is current. It
                # names a location and routeTo() BFSes the exit graph to it,
                # so a dangling key is a quest pin pointing at nothing.
                tat = (rel, jp(sat[1], "target"))
                if want_dict(stage["target"], p, tat) is not None:
                    check_fields(stage["target"], ({"location"}, set()), p, tat)
                    want_location(stage["target"].get("location"), index, p,
                                  (rel, jp(tat[1], "location")))

        terminals = [sk for sk, s in stages.items()
                     if isinstance(s, dict) and s.get("terminal")]
        if not terminals:
            p.err((rel, "$.stages"),
                  "no terminal stage — this quest can be started but never ends")
        seen = {}
        for sk in terminals:
            res = stages[sk].get("resolution")
            if not isinstance(res, str):
                p.err((rel, jp("$.stages", sk, "resolution")),
                      "a terminal stage needs a resolution; it is what later acts branch on")
                continue
            if res in seen:
                p.err((rel, jp("$.stages", sk, "resolution")),
                      f"resolution {res!r} is already used by the ending {seen[res]!r} — "
                      f"two endings that read the same are one ending")
            else:
                seen[res] = sk


def validate_quest_reachability(index, p):
    """
    Stages are a graph with no declared edges: a `quest_stage` effect anywhere
    in the tree is what moves a quest along, and `start_quest` enters the first
    stage. Anything named by neither is content nothing can ever show.
    """
    for key, obj in index.by_kind["quests"].items():
        rel = index.paths["quests"][key]
        stages = obj.get("stages")
        if not isinstance(stages, dict) or not stages:
            continue
        first = next(iter(stages))
        for sk in stages:
            if sk == first or (key, sk) in index.named_stages:
                continue
            p.err((rel, jp("$.stages", sk)),
                  "unreachable: it is not the quest's first stage and no quest_stage "
                  "effect anywhere in content/ names it")


def validate_companions(index, p):
    for key, obj in index.by_kind["companions"].items():
        rel = index.paths["companions"][key]
        check_fields(obj, FIELDS["companions"], p, (rel, "$"), kind="companions")
        for f in ("name", "title", "description", "race", "subrace", "class",
                  "background", "alignment"):
            want_str(obj.get(f), p, (rel, jp("$", f)))
        want_int(obj.get("level"), p, (rel, "$.level"))
        want_bool(obj.get("romanceable"), p, (rel, "$.romanceable"))
        want_enum(obj.get("combat_rank"), RANKS, p, (rel, "$.combat_rank"))
        check_sprite(obj.get("sprite_key"), NPC_ART, index, p, (rel, "$.sprite_key"))

        abilities = obj.get("abilities")
        if want_dict(abilities, p, (rel, "$.abilities")) is not None:
            check_fields(abilities, (set(ABILITIES), set()), p, (rel, "$.abilities"))
            for a in ABILITIES:
                want_int(abilities.get(a), p, (rel, jp("$.abilities", a)))

        if "npc_key" in obj:
            want_key(obj["npc_key"], "npcs", index, p, (rel, "$.npc_key"))
        if "personal_quest" in obj:
            want_key(obj["personal_quest"], "quests", index, p, (rel, "$.personal_quest"))

        if "recruit" in obj:
            rat = (rel, "$.recruit")
            if want_dict(obj["recruit"], p, rat) is not None:
                check_fields(obj["recruit"], (set(), {"location", "conditions"}), p, rat)
                if "location" in obj["recruit"]:
                    want_location(obj["recruit"]["location"], index, p,
                                  (rel, "$.recruit.location"))
                if "conditions" in obj["recruit"]:
                    check_condition_list(obj["recruit"]["conditions"], index, p,
                                         (rel, "$.recruit.conditions"))

        if "approval" in obj:
            aat = (rel, "$.approval")
            if want_dict(obj["approval"], p, aat) is not None:
                check_fields(obj["approval"], (set(), {"leave_at", "hostile_at", "romance_at"}),
                             p, aat)
                for f in ("leave_at", "hostile_at", "romance_at"):
                    want_int(obj["approval"].get(f), p, (rel, jp("$.approval", f)))

        if want_list(obj.get("gear"), p, (rel, "$.gear")) is not None:
            for i, g in enumerate(obj["gear"]):
                want_key(g, "items", index, p, (rel, jp("$.gear", i)))
        if want_list(obj.get("spells"), p, (rel, "$.spells")) is not None:
            for i, s in enumerate(obj["spells"]):
                want_key(s, "spells", index, p, (rel, jp("$.spells", i)))
        # Feats are keys into Feats::CATALOG rather than into a content table,
        # so they cannot go through want_key. The check is the same in spirit:
        # a companion authored with a feat nobody wrote arrives without it, and
        # the only place that would ever be noticed is the moment they join.
        if want_list(obj.get("feats"), p, (rel, "$.feats")) is not None:
            for i, f in enumerate(obj["feats"]):
                if f not in index.feats:
                    p.err((rel, jp("$.feats", i)),
                          f"no such feat: {f!r} (see app/lib/Feats.php)")


def validate_placements(index, p):
    """
    `place` blocks, against the location graph.

    Placement is content — who stands where is a story decision — so it is
    authored beside the character rather than in the world files. A location is
    a described scene, not a tile, so there is nothing to collide with and any
    number of people may share one; all that can go wrong is the key, and a
    dangling key is an NPC, fight or treasure that silently exists nowhere.
    """
    for kind in PLACE_KINDS:
        for key, obj in index.by_kind[kind].items():
            place = obj.get("place")
            if place is None:
                continue
            rel = index.paths[kind][key]
            at = (rel, "$.place")
            if want_dict(place, p, at) is None:
                continue
            check_fields(place, ({"location"}, set()), p, at)
            want_location(place.get("location"), index, p, (rel, "$.place.location"))


def validate_encounter_reachability(index, p):
    """
    Every fight has to be openable by something.

    Three ways in: a dialogue choice or quest stage whose effects say
    `start_combat`, an `ambush` that fires on arrival, or a random encounter
    drawn while travelling. A fight with none of those is written, costed and
    unreachable — the kind of thing that is only ever noticed by not happening.

    A warning rather than an error: content half-written is a normal state to
    save a file in, and refusing to load the world over it would be worse than
    saying so.
    """
    started = set()
    for kind in ("dialog", "quests"):
        for obj in index.by_kind[kind].values():
            for eff in walk_effects(obj):
                if isinstance(eff, dict) and isinstance(eff.get("start_combat"), str):
                    started.add(eff["start_combat"])

    for key, obj in index.by_kind["encounters"].items():
        if obj.get("is_random") or obj.get("ambush") or key in started:
            continue
        rel = index.paths["encounters"][key]
        p.warn((rel, "$"),
               "nothing can start this fight: no dialogue or quest effect names "
               "it in `start_combat`, and it is neither an `ambush` nor random")


# The keys that hold an effect list. A dialogue node runs `on_enter`, a choice
# runs `effects`, and a check runs one of its two branches — all the same
# vocabulary, all places a fight can be started from.
EFFECT_LIST_KEYS = ("effects", "on_enter", "on_success", "on_failure")


def walk_effects(obj):
    """Every effect object anywhere inside a content file, at any depth."""
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k in EFFECT_LIST_KEYS and isinstance(v, list):
                yield from (e for e in v if isinstance(e, dict))
            else:
                yield from walk_effects(v)
    elif isinstance(obj, list):
        for v in obj:
            yield from walk_effects(v)


def validate(index, p):
    # Locations first: it builds the global location index every place/target/
    # recruit/travel reference in the rest of the tree is resolved against.
    # Modules first: a region names one, so the name has to be checkable by the
    # time the region envelopes are read.
    validate_modules(index, p)
    validate_locations(index, p)
    validate_module_ownership(index, p)
    validate_module_placement(index, p)
    validate_items(index, p)
    validate_monsters(index, p)
    validate_spells(index, p)
    validate_npcs(index, p)
    validate_dialog(index, p)
    validate_encounters(index, p)
    validate_quests(index, p)
    validate_companions(index, p)
    validate_placements(index, p)
    # Effects are what name a stage, so reachability can only be judged once
    # every effect list in the tree has been walked. Encounters are the same
    # question asked of fights.
    validate_quest_reachability(index, p)
    validate_encounter_reachability(index, p)


# ---------------------------------------------------------------------------
# SQL emission
# ---------------------------------------------------------------------------
def sql_str(s):
    """A MySQL string literal. Backslashes matter: dialogue_json is full of them."""
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def sql_val(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float)):
        return repr(v)
    return sql_str(v)


def sql_json(v):
    if v is None:
        return "NULL"
    return sql_str(json.dumps(v, ensure_ascii=False, separators=(",", ":")))


def ref(kind, key):
    """A subselect that resolves a content key to its id at load time."""
    if key is None:
        return "NULL"
    table, col = TABLE[kind]
    return f"(SELECT id FROM {table} WHERE {col} = {sql_str(key)})"


def loc_ref(key):
    """A subselect resolving a location_key. Keys are global, so no region."""
    return "NULL" if key is None else \
        f"(SELECT id FROM locations WHERE location_key = {sql_str(key)})"


def region_ref(key):
    return "NULL" if key is None else \
        f"(SELECT id FROM regions WHERE region_key = {sql_str(key)})"


def module_ref(key):
    return "NULL" if key is None else \
        f"(SELECT id FROM modules WHERE module_key = {sql_str(key)})"


def key_list(keys):
    return ", ".join(sql_str(k) for k in keys)


def rows(lines, table, columns, values, upsert_on=None):
    """
    Emit an INSERT, as an upsert when the table has a key to match on.

    Upserting rather than replacing is not a tidiness preference — it is the
    difference between a content reload and a wiped save.

    Every one of these tables is the parent of a foreign key from player data
    with ON DELETE CASCADE: character_spells.spell_id, character_inventory.item_id,
    party_quests.quest_id, party_quest_stages.stage_id, character_known_npcs.
    So DELETE-then-INSERT gave every row a new id and took the player's known
    spells, everything they were carrying and all of their quest progress with it.
    It looked idempotent, and it was — to the content. Reloading content is
    something you do casually and often; losing a save to it is not recoverable.

    `upsert_on` names the column(s) of the unique index to match on. Those columns
    are excluded from the UPDATE, since matching on them means they already agree.
    """
    lines.append(f"INSERT INTO {table} ({','.join(columns)}) VALUES")
    body = ",\n".join(f"({','.join(v)})" for v in values)
    if not upsert_on:
        lines.append(body + ";")
        return
    keys = {c.strip() for c in upsert_on}
    updates = [f"  {c} = VALUES({c})" for c in columns if c not in keys]
    if not updates:
        # A table whose every column is part of the key has nothing to update;
        # matching a row means it is already correct.
        updates = [f"  {columns[0]} = VALUES({columns[0]})"]
    lines.append(body)
    lines.append("ON DUPLICATE KEY UPDATE")
    lines.append(",\n".join(updates) + ";")


def normalise_nodes(nodes):
    """
    One shape for the engine to read.

    A node may be authored as a bare object or as a list of variants. Stored as
    a list either way, so DialogEngine never has to ask which it is holding.
    """
    return {k: (v if isinstance(v, list) else [v]) for k, v in nodes.items()}


def emit(index):
    lines = [
        "-- GENERATED by tools/load_content.py — do not edit by hand.",
        "-- Authored content lives in content/**/*.json; edit there and re-run.",
        "--",
        "-- Loads after sql/schema.sql and sql/seed_data.sql. The world graph —",
        "-- regions, locations, exits — is emitted first, because nearly every",
        "-- other row places itself with a subselect against it.",
        "--",
        "-- Every reference below is a subselect on a stable key rather than an id,",
        "-- so this file can be replayed against any database without the loader",
        "-- having to know, or guess, what id a row ended up with.",
        "",
        "USE rpg_5e;",
        # Say the charset in the file itself, so it is right however this is
        # applied — the initdb script passes --default-character-set, but a
        # human piping it into `mysql` by hand gets the client's compiled-in
        # default instead, which is latin1. That reads every multi-byte
        # character as two, re-encodes it into the utf8mb4 column, and turns
        # every em-dash in the dialogue into "â€”". It stayed that way in
        # thirteen rows until an export round-trip surfaced it.
        "SET NAMES utf8mb4;",
        "SET FOREIGN_KEY_CHECKS = 0;",
        "",
    ]

    items = index.by_kind["items"]
    monsters = index.by_kind["monsters"]
    spells = index.by_kind["spells"]
    npcs = index.by_kind["npcs"]
    encounters = index.by_kind["encounters"]
    quests = index.by_kind["quests"]
    companions = index.by_kind["companions"]

    # Re-importing recycles ids. Anything holding one of the old numbers is
    # cleared first, so a stale reference cannot come back pointing at whatever
    # now owns that id.
    if quests:
        lines.append(
            f"DELETE pqs FROM party_quest_stages pqs JOIN quests q ON q.id = pqs.quest_id "
            f"WHERE q.quest_key IN ({key_list(quests)});"
        )
        lines.append(
            f"DELETE pq FROM party_quests pq JOIN quests q ON q.id = pq.quest_id "
            f"WHERE q.quest_key IN ({key_list(quests)});"
        )
    if npcs:
        lines.append(
            f"DELETE ckn FROM character_known_npcs ckn JOIN npcs n ON n.id = ckn.npc_id "
            f"WHERE n.npc_key IN ({key_list(npcs)});"
        )
    lines.append("")

    emit_locations(lines, index)
    emit_items(lines, items)
    emit_monsters(lines, monsters)
    emit_spells(lines, spells)
    emit_npcs(lines, index, npcs)
    emit_encounters(lines, encounters)
    emit_quests(lines, index, quests, companions)
    emit_companions(lines, index, companions)
    emit_placements(lines, index)

    lines.append("SET FOREIGN_KEY_CHECKS = 1;")
    if RETIRE:
        lines.append("")
        lines.append("-- === Retire content the JSON no longer names ===")
        lines.append("-- Deliberately after FOREIGN_KEY_CHECKS = 1, so removing a")
        lines.append("-- spell or item takes its references with it instead of")
        lines.append("-- leaving rows pointing at an id that is gone.")
        lines.extend(RETIRE)
    with open(OUT, "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    return OUT


# Retirement deletes, held back until foreign key checks are on again.
#
# The file disables them so content can be inserted in any order — a quest may
# reference an item that has not been written yet. But a DELETE with checks off
# does not cascade: it leaves the child row pointing at an id that no longer
# exists. That is how a save ended up with inventory and spell rows that survived
# a reload and yet resolved to nothing, so the game showed a caster with no spells
# and a character carrying nothing while the rows sat there intact.
#
# Retiring content genuinely should cascade — a spell removed from the game should
# not leave anyone remembering it — so these run last, with checks restored.
RETIRE: list[str] = []


def section(lines, title, keys, table, col):
    """
    Open a section, retiring content that no longer exists.

    Deletes rows this table owns that the JSON no longer names — the opposite of
    what it used to do, which was delete exactly the rows about to be re-inserted.
    That gave every current row a fresh id on every reload and cascaded the
    player's spells, inventory and quest progress into oblivion; see rows().

    `col IS NOT NULL` keeps this to content's own rows. Anything in the table
    without a key was put there by something else and is not content's to remove.
    """
    lines.append(f"-- === {title} ===")
    if not keys:
        lines.append(f"-- none authored under content/{title.lower()}/")
        lines.append("")
        return False
    RETIRE.append(
        f"DELETE FROM {table} WHERE {col} IS NOT NULL "
        f"AND {col} NOT IN ({key_list(keys)});"
    )
    return True


def emit_locations(lines, index):
    """
    The world graph: regions, their locations, and the exits between them.

    Emitted first so every placement subselect later in the file resolves.
    Regions and locations are upserted on their keys — player data hangs off
    location ids (characters.current_location_id, party_locations_visited,
    location_items) and must survive a reload. Exits are delete-and-reinsert:
    the graph is simplest synced whole, and the one per-party table that
    references an exit row (party_exits_found) is cleared alongside it —
    with FOREIGN_KEY_CHECKS off a bare DELETE would not cascade and would
    leave orphans. Losing found-hidden-exit state on a content reload is
    acceptable on a dev rebuild.
    """
    lines.append("-- === Modules ===")
    modules = index.by_kind["modules"]
    if modules:
        rows(lines, "modules",
             ("module_key", "name", "blurb", "start_location_key",
              "level_min", "level_max", "attribution", "is_active", "sort_order"),
             [[sql_str(mk), sql_val(m["name"]), sql_val(m.get("blurb")),
               sql_val(m["start_location_key"]),
               sql_val(m.get("level_min", 1)),
               sql_val(m.get("level_max", read_max_level())),
               sql_val(m.get("attribution")), sql_val(m.get("is_active", True)),
               sql_val(m.get("sort_order", 0))]
              for mk, m in modules.items()],
             upsert_on=("module_key",))
    else:
        lines.append("-- none authored under content/modules/")
    lines.append("")

    lines.append("-- === Regions & locations ===")
    regions = index.by_kind["locations"]
    if not regions:
        lines.append("-- none authored under content/locations/")
        lines.append("")
        return
    # `_`-prefixed keys are runtime rows and are none of this file's business.
    #
    # DelveEngine writes a generated dungeon level as ordinary regions and
    # locations keyed `_dg_<party>_<depth>_…`, exactly as PitEngine writes a
    # `_pit_party_<n>` encounter. Nothing under content/ names them, so a plain
    # NOT IN sweep would delete them — and unlike the pit's scratch row, which
    # is simply recreated on demand, these are rows a party may be STANDING IN.
    # Losing them mid-delve leaves `characters.current_location_id` pointing at
    # nothing, and the next request answers "Character is nowhere."
    #
    # The exclusion is written into the retire clause rather than left to the
    # key list, so it holds however the content happens to be shaped that day.
    RETIRE.append(
        f"DELETE FROM regions WHERE region_key NOT LIKE '\\_%' "
        f"AND region_key NOT IN ({key_list(regions)});"
    )
    loc_keys = [lk for r in regions.values() for lk in (r.get("locations") or {})]
    if loc_keys:
        RETIRE.append(
            f"DELETE FROM locations WHERE location_key NOT LIKE '\\_%' "
            f"AND location_key NOT IN ({key_list(loc_keys)});"
        )
    # After its regions and locations, because RETIRE runs in append order with
    # foreign keys back on. By the time this fires a retired module has nothing
    # left hanging off it, so the CASCADE on regions.module_id has nothing to
    # take with it — which is the point. Deleting a module file should retire
    # the module, not silently reach through it and delete a world that some
    # region file still names.
    if modules:
        RETIRE.append(f"DELETE FROM modules WHERE module_key NOT IN "
                      f"({key_list(modules)});")

    rows(lines, "regions",
         ("region_key", "module_id", "name", "description", "region_type",
          "plan_json", "sort_order"),
         [[sql_str(rk), module_ref(r.get("module")), sql_val(r["name"]),
           sql_val(r.get("description")), sql_val(r.get("region_type", "town")),
           sql_json(r.get("plan")),
           sql_val(r.get("sort_order", 0))]
          for rk, r in regions.items()],
         upsert_on=("region_key",))
    lines.append("")

    lines.append("-- sort_order is authored order ×10, per region.")
    loc_rows = []
    for rk, r in regions.items():
        for i, (lk, loc) in enumerate((r.get("locations") or {}).items()):
            pos = loc.get("map_pos") or [50, 50]
            loc_rows.append([
                sql_str(lk), region_ref(rk), sql_val(loc["name"]),
                sql_val(loc["description"]), sql_val(loc.get("first_visit")),
                sql_json(loc.get("ambience")), sql_val(loc.get("type", "site")),
                sql_val(pos[0]), sql_val(pos[1]), sql_val(loc.get("inn_cost")),
                sql_val(loc.get("allow_camp", False)),
                sql_val(loc.get("random_encounter_pct", 0)),
                sql_val(loc.get("hidden_until_visited", False)),
                sql_val(loc.get("job_board", False)),
                sql_val(i * 10),
            ])
    rows(lines, "locations",
         ("location_key", "region_id", "name", "description", "first_visit_text",
          "ambience_json", "location_type", "map_x", "map_y", "inn_cost",
          "allow_camp", "random_encounter_pct", "hidden_until_visited",
          "has_job_board", "sort_order"), loc_rows, upsert_on=("location_key",))
    lines.append("")

    lines.append("-- The exit graph, rebuilt whole; see the loader for why the")
    lines.append("-- party_exits_found rows go with it.")
    lines.append("DELETE FROM party_exits_found;")
    lines.append("DELETE FROM location_exits;")
    exit_rows = []
    for r in regions.values():
        for lk, loc in (r.get("locations") or {}).items():
            for i, e in enumerate(loc.get("exits") or []):
                exit_rows.append([
                    loc_ref(lk), loc_ref(e["to"]), sql_val(e["label"]),
                    sql_json(e.get("conditions")),
                    sql_val(e.get("hidden", False)), sql_val(i * 10),
                ])
    if exit_rows:
        rows(lines, "location_exits",
             ("from_location_id", "to_location_id", "label", "conditions_json",
              "is_hidden", "sort_order"), exit_rows)
    lines.append("")


def emit_items(lines, items):
    if not section(lines, "Items", items, "items", "item_key"):
        return
    cols = ("item_key", "name", "description", "item_type", "rarity", "weight",
            "value_gp", "damage_dice", "damage_type", "armor_bonus",
            "armor_type", "slot", "properties", "image_url", "icon")
    values = []
    for key, o in items.items():
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("description")),
            sql_val(o["item_type"]), sql_val(o.get("rarity", "common")),
            sql_val(o.get("weight", 0)), sql_val(o.get("value_gp", 0)),
            sql_val(o.get("damage_dice")), sql_val(o.get("damage_type")),
            sql_val(o.get("armor_bonus")), sql_val(o.get("armor_type")),
            sql_val(o.get("slot")),
            sql_json(o.get("properties")), sql_val(o.get("image_url")),
            sql_val(o.get("icon")),
        ])
    rows(lines, "items", cols, values, upsert_on=("item_key",))
    lines.append("")


def emit_monsters(lines, monsters):
    if not section(lines, "Monsters", monsters, "monsters", "monster_key"):
        return
    cols = ("monster_key", "name", "size", "type", "alignment", "armor_class",
            "hit_points", "hit_dice", "speed", "strength", "dexterity",
            "constitution", "intelligence", "wisdom", "charisma",
            "challenge_rating", "experience_points", "traits", "actions",
            "legendary_actions", "saves_json", "resistances_json",
            "immunities_json", "vulnerabilities_json",
            "condition_immunities_json", "loot_json", "rank_pref", "image_url",
            "sprite_key", "source")
    values = []
    for key, o in monsters.items():
        sprite = o.get("sprite_key")
        image = o.get("image_url") or (f"assets/images/monsters/{sprite}.png" if sprite else None)
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("size")),
            sql_val(o.get("type")), sql_val(o.get("alignment")),
            sql_val(o.get("armor_class")), sql_val(o.get("hit_points")),
            sql_val(o.get("hit_dice")), sql_val(o.get("speed")),
        ] + [sql_val(o.get(a)) for a in ABILITIES] + [
            sql_val(o.get("challenge_rating")), sql_val(o.get("experience_points")),
            sql_json(o.get("traits")), sql_json(o.get("actions")),
            sql_json(o.get("legendary_actions")), sql_json(o.get("saves")),
            sql_json(o.get("resistances")), sql_json(o.get("immunities")),
            sql_json(o.get("vulnerabilities")), sql_json(o.get("condition_immunities")),
            sql_json(o.get("loot")),
            sql_val(o.get("rank_pref", "front")), sql_val(image), sql_val(sprite),
            sql_val(o.get("source", "5e SRD")),
        ])
    rows(lines, "monsters", cols, values, upsert_on=("monster_key",))
    lines.append("")


def emit_spells(lines, spells):
    if not section(lines, "Spells", spells, "spells", "spell_key"):
        return
    cols = ("spell_key", "name", "level", "higher_level_json", "school",
            "casting_time", "range_text", "components", "duration",
            "description", "damage_dice", "damage_type", "resolution",
            "save_ability", "save_effect", "concentration", "duration_rounds",
            "target_kind", "reaches_back_rank", "effect_json", "class_list",
            "source")
    values = []
    for key, o in spells.items():
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("level", 0)),
            sql_json(o.get("higher_level")),
            sql_val(o.get("school")), sql_val(o.get("casting_time")),
            sql_val(o.get("range_text")), sql_val(o.get("components")),
            sql_val(o.get("duration")), sql_val(o.get("description")),
            sql_val(o.get("damage_dice")), sql_val(o.get("damage_type")),
            sql_val(o.get("resolution", "attack")), sql_val(o.get("save_ability")),
            sql_val(o.get("save_effect", "half")),
            sql_val(o.get("concentration", False)),
            sql_val(o.get("duration_rounds", 0)),
            sql_val(o.get("target_kind", "enemy")),
            sql_val(o.get("reaches_back_rank", True)),
            sql_json(o.get("effect")),
            sql_val(",".join(o.get("classes", [])) or None),
            sql_val(o.get("source", "5e SRD")),
        ])
    rows(lines, "spells", cols, values, upsert_on=("spell_key",))
    lines.append("")


def emit_npcs(lines, index, npcs):
    if not section(lines, "NPCs", npcs, "npcs", "npc_key"):
        return
    lines.append("-- dialogue_json is content/dialog/<key>.json merged in, with every node")
    lines.append("-- normalised to a variant list so the engine handles one shape.")
    cols = ("npc_key", "name", "role", "description", "image_url", "sprite_key",
            "bust_count", "dialogue_json", "is_merchant", "is_quest_giver",
            "is_ambient", "pose")
    values = []
    for key, o in npcs.items():
        sprite = o.get("sprite_key")
        image = o.get("image_url") or (f"assets/images/npcs/{sprite}.png" if sprite else None)
        dialogue = None
        dk = o.get("dialog")
        if isinstance(dk, str) and index.has("dialog", dk):
            d = index.get("dialog", dk)
            dialogue = {"start": d.get("start"), "nodes": normalise_nodes(d.get("nodes") or {})}
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("role")),
            sql_val(o.get("description")), sql_val(image), sql_val(sprite),
            sql_val(index.bust_count(sprite) or 1), sql_json(dialogue),
            sql_val(o.get("is_merchant", False)), sql_val(o.get("is_quest_giver", False)),
            sql_val(o.get("is_ambient", False)), sql_val(o.get("pose")),
        ])
    rows(lines, "npcs", cols, values, upsert_on=("npc_key",))
    lines.append("")

    # Shop stock. Deleted and re-inserted per merchant rather than upserted:
    # nothing player-owned references these rows (character_inventory points at
    # items, not shelves), so a reload may honestly reset stock to the authored
    # figure — on a dev database, that IS the restock mechanism.
    stocked = {k: o["shop"] for k, o in npcs.items() if o.get("shop")}
    if stocked:
        lines.append("-- Shop stock — authored per merchant in content/npcs/*.json.")
        keys = ",".join(sql_str(k) for k in stocked)
        lines.append(f"DELETE FROM shop_inventory WHERE npc_key IN ({keys});")
        lines.append("INSERT INTO shop_inventory (npc_key, item_id, stock, markup_pct) VALUES")
        stock_rows = []
        for k, entries in stocked.items():
            for e in entries:
                stock_rows.append(
                    f"({sql_str(k)}, (SELECT id FROM items WHERE item_key = {sql_str(e['item'])}), "
                    f"{e.get('stock') if e.get('stock') is not None else 'NULL'}, "
                    f"{e.get('markup_pct', 100)})"
                )
        lines.append(",\n".join(stock_rows) + ";")
        lines.append("")


def emit_encounters(lines, encounters):
    lines.append("-- === Encounters ===")
    if not encounters:
        lines.append("-- none authored under content/encounters/")
        lines.append("")
        return
    keys = key_list(encounters)
    # encounter_monsters has no natural key and nothing in player data points at
    # it, so this one really is a replace: clear the rosters and rebuild them.
    # The encounters themselves are upserted, so their ids — which map_encounters
    # and the combat sessions refer to — survive.
    lines.append(f"DELETE FROM encounter_monsters WHERE encounter_id IN "
                 f"(SELECT id FROM encounters WHERE encounter_key IN ({keys}));")
    RETIRE.append(f"DELETE FROM encounters WHERE encounter_key IS NOT NULL "
                  f"AND encounter_key NOT IN ({keys});")
    cols = ("encounter_key", "name", "description", "location_id", "region_id",
            "is_random", "is_ambush", "difficulty", "allow_flee",
            "allow_parley", "parley_node", "victory_flag", "defeat_flag",
            "victory_quest_stage", "scale_to_party")
    values = []
    for key, o in encounters.items():
        # A placed fight waits at its `place` location; a random one carries
        # the region whose travel rolls may draw it. Validation guarantees the
        # two are exclusive.
        place = o.get("place") or {}
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("description")),
            loc_ref(place.get("location")), region_ref(o.get("region")),
            sql_val(o.get("is_random", False)), sql_val(o.get("ambush", False)),
            sql_val(o.get("difficulty")), sql_val(o.get("allow_flee", True)),
            sql_val(o.get("allow_parley", False)), sql_val(o.get("parley_node")),
            sql_val(o.get("victory_flag")), sql_val(o.get("defeat_flag")),
            sql_val(o.get("victory_quest_stage")),
            # `scale` defaults on: an authored roster says who is here, and
            # CombatEngine fits the count to the party. A set piece whose size
            # is the point says "scale": false.
            sql_val(o.get("scale", True)),
        ])
    rows(lines, "encounters", cols, values, upsert_on=("encounter_key",))

    lineup = []
    for key, o in encounters.items():
        for m in o["monsters"]:
            lineup.append([ref("encounters", key), ref("monsters", m["monster"]),
                           sql_val(m.get("quantity", 1)),
                           # NULL falls back to the monster's own rank_pref, so
                           # an encounter only has to say where a creature
                           # stands when it differs from that creature's habit.
                           sql_val(m.get("rank"))])
    if lineup:
        rows(lines, "encounter_monsters",
             ("encounter_id", "monster_id", "quantity", "rank_override"), lineup)
    lines.append("")


def emit_quests(lines, index, quests, companions):
    lines.append("-- === Quests ===")
    if not quests:
        lines.append("-- none authored under content/quests/")
        lines.append("")
        return
    keys = key_list(quests)
    # Both upserted below, so both keep their ids. What is removed here is only
    # what the JSON no longer names: a retired quest, or a stage deleted from a
    # quest that still exists. Deleting a stage the party had reached is
    # unavoidable in that case — the stage genuinely no longer exists — but it no
    # longer happens to every stage on every reload.
    RETIRE.append(f"DELETE FROM quests WHERE quest_key IS NOT NULL "
                  f"AND quest_key NOT IN ({keys});")

    # A personal quest is declared from the companion's side; the column on
    # `quests` is filled from that link so the two cannot disagree.
    personal = {c["personal_quest"]: ck for ck, c in companions.items()
                if isinstance(c.get("personal_quest"), str)}

    cols = ("quest_key", "title", "description", "act", "companion_key",
            "on_job_board", "giver_npc_id", "required_level", "reward_xp",
            "reward_gold", "reward_item_id", "target_location_id",
            "is_active")
    values = []
    for key, o in quests.items():
        rewards = o.get("rewards") or {}
        # The quest-level target follows the first stage that declares one, so
        # it agrees with the stage graph instead of being authored twice.
        target_loc = next((s["target"]["location"] for s in o["stages"].values()
                           if isinstance(s.get("target"), dict)
                           and "location" in s["target"]), None)
        values.append([
            sql_str(key), sql_val(o["title"]), sql_val(o.get("description")),
            sql_val(o.get("act", 1)), sql_val(o.get("companion") or personal.get(key)),
            sql_val(o.get("on_job_board", False)), ref("npcs", o.get("giver")),
            sql_val(o.get("required_level", 1)), sql_val(rewards.get("xp", 0)),
            sql_val(rewards.get("gold", 0)), ref("items", rewards.get("item")),
            loc_ref(target_loc),
            sql_val(o.get("is_active", True)),
        ])
    rows(lines, "quests", cols, values, upsert_on=("quest_key",))

    # Retire stages dropped from a quest that still exists. Scoped to the one
    # quest, so a reload cannot touch the stages of any other — which is the whole
    # difference between this and the blanket delete it replaces.
    for key, o in quests.items():
        stage_keys = key_list(o["stages"])
        RETIRE.append(
            f"DELETE FROM quest_stages WHERE quest_id = {ref('quests', key)} "
            f"AND stage_key NOT IN ({stage_keys});"
        )

    stage_rows = []
    for key, o in quests.items():
        for i, (sk, s) in enumerate(o["stages"].items()):
            target = s.get("target") or {}
            stage_rows.append([
                ref("quests", key), sql_str(sk), sql_val(s["title"]),
                sql_val(s.get("objective")), sql_val(s.get("journal")),
                loc_ref(target.get("location")), sql_val(s.get("terminal", False)),
                sql_val(s.get("resolution")), sql_val(s.get("outcome", "success")),
                sql_json(s.get("effects")), sql_val(i),
            ])
    if stage_rows:
        lines.append("-- sort_order is file order: the first stage is the one start_quest enters.")
        # Matched on (quest_id, stage_key) — the uq_quest_stage index. Stage ids
        # have to survive a reload too: party_quest_stages references them, and
        # that table is which stage of which quest the party has reached.
        rows(lines, "quest_stages",
             ("quest_id", "stage_key", "title", "objective", "journal_entry",
              "target_location_id", "is_terminal", "resolution", "outcome",
              "effects_json", "sort_order"), stage_rows,
             upsert_on=("quest_id", "stage_key"))
    lines.append("")


def emit_companions(lines, index, companions):
    if not section(lines, "Companions", companions, "companions", "companion_key"):
        return
    cols = ("companion_key", "name", "title", "description", "race", "subrace",
            "`class`", "background", "alignment", "level", "strength",
            "dexterity", "constitution", "intelligence", "wisdom", "charisma",
            "sprite_key", "bust_count", "combat_rank", "npc_key",
            "personal_quest_key", "recruit_location_id", "recruit_conditions_json",
            "leave_at", "hostile_at", "romance_at", "romanceable",
            "starting_gear_json", "starting_spells_json", "starting_feats_json")
    values = []
    for key, o in companions.items():
        ab = o["abilities"]
        recruit = o.get("recruit") or {}
        approval = o.get("approval") or {}
        values.append([
            sql_str(key), sql_val(o["name"]), sql_val(o.get("title")),
            sql_val(o.get("description")), sql_val(o["race"]), sql_val(o.get("subrace")),
            sql_val(o["class"]), sql_val(o.get("background")), sql_val(o.get("alignment")),
            sql_val(o.get("level", 1)),
        ] + [sql_val(ab[a]) for a in ABILITIES] + [
            # Companions wear NPC art, so their expression count comes from
            # the same assets/images/npcs/busts.json the NPCs read.
            sql_val(o["sprite_key"]), sql_val(index.bust_count(o["sprite_key"]) or 1),
            sql_val(o.get("combat_rank", "front")), sql_val(o.get("npc_key")),
            sql_val(o.get("personal_quest")), loc_ref(recruit.get("location")),
            sql_json(recruit.get("conditions")),
            sql_val(approval.get("leave_at", -40)),
            sql_val(approval.get("hostile_at", -70)),
            sql_val(approval.get("romance_at", 30)),
            sql_val(o.get("romanceable", False)),
            sql_json(o.get("gear")), sql_json(o.get("spells")),
            sql_json(o.get("feats")),
        ])
    rows(lines, "companions", cols, values, upsert_on=("companion_key",))
    lines.append("")


def emit_placements(lines, index):
    """
    The `place` blocks, applied last.

    Last because a placement is a subselect on the row it points at, and those
    rows are inserted above. Addressed by location key rather than by id for the
    same reason everything else here is: a reload must not depend on what number
    a row happened to get.

    NPCs and encounters carry their placement as a column on their own row, so
    those are UPDATEs — and every row of the kind is written, clearing the
    column for anyone whose `place` block was removed. Items are different: a
    placed item is ground loot, a location_items row, so those are rebuilt
    wholesale.
    """
    lines.append("-- === Placements ===")

    # NPCs and encounters: one column, set for the placed and cleared for the
    # rest. The clear matters — deleting a `place` block should take the person
    # off the street, not leave them standing where they used to be.
    for kind, table, keycol in (("npcs", "npcs", "npc_key"),
                                ("encounters", "encounters", "encounter_key")):
        entries = index.by_kind[kind]
        if not entries:
            continue
        placed = {k: o["place"]["location"] for k, o in entries.items()
                  if isinstance(o.get("place"), dict)}
        unplaced = [k for k in entries if k not in placed]
        for key, loc in placed.items():
            lines.append(
                f"UPDATE {table} SET location_id = {loc_ref(loc)} "
                f"WHERE {keycol} = {sql_str(key)};"
            )
        if unplaced:
            lines.append(
                f"UPDATE {table} SET location_id = NULL "
                f"WHERE {keycol} IN ({key_list(unplaced)});"
            )
    lines.append("")

    # Ground loot. Rebuilt whole, and party_items_taken goes with it: with
    # FOREIGN_KEY_CHECKS off the DELETE would not cascade, and a party's
    # "already taken" rows would be left pointing at location_items ids that no
    # longer exist. Losing that state on a content reload is acceptable; a
    # dangling reference that silently hides loot is not.
    lines.append("-- Ground loot, rebuilt whole; see the loader for why the")
    lines.append("-- party_items_taken rows go with it.")
    lines.append("DELETE FROM party_items_taken;")
    lines.append("DELETE FROM location_items;")
    pairs = {}
    for key, o in index.by_kind["items"].items():
        if isinstance(o.get("place"), dict) and o["place"].get("location"):
            pairs[(o["place"]["location"], key)] = True
    for rk, region in index.by_kind["locations"].items():
        locs = region.get("locations") or {}
        if not isinstance(locs, dict):
            continue
        for lk, loc in locs.items():
            if not isinstance(loc, dict):
                continue
            for item in loc.get("loot") or []:
                if isinstance(item, str) and item:
                    pairs[(lk, item)] = True
    loot = [[loc_ref(loc), ref("items", item)] for loc, item in pairs]
    if loot:
        rows(lines, "location_items", ("location_id", "item_id"), loot)
    else:
        lines.append("-- no item declares a `place` block and no location lists loot")
    lines.append("")


# ---------------------------------------------------------------------------
def summarise(index):
    for kind in KINDS:
        entries = index.by_kind[kind]
        if not entries:
            print(f"  {kind:<11} —")
            continue
        for key, obj in entries.items():
            print(f"  {kind:<11} {key:<22} {describe(kind, obj)}{placed_at(obj)}")


def placed_at(obj):
    p = obj.get("place")
    return f"  @ {p['location']}" if isinstance(p, dict) else ""


def describe(kind, obj):
    if kind == "dialog":
        nodes = obj.get("nodes") or {}
        variants = sum(len(v) if isinstance(v, list) else 1 for v in nodes.values())
        return f"{len(nodes)} nodes, {variants} variants, start={obj.get('start')!r}"
    if kind == "npcs":
        bits = [obj.get("role") or "—", f"sprite={obj.get('sprite_key')}"]
        if obj.get("dialog"):
            bits.append(f"dialog={obj['dialog']}")
        return ", ".join(bits)
    if kind == "quests":
        stages = obj.get("stages") or {}
        terminal = [s for s in stages.values() if isinstance(s, dict) and s.get("terminal")]
        return f"act {obj.get('act', 1)}, {len(stages)} stages, {len(terminal)} endings"
    if kind == "encounters":
        return f"{len(obj.get('monsters') or [])} monster groups, {obj.get('difficulty')}"
    if kind == "companions":
        return f"{obj.get('race')} {obj.get('class')}, sprite={obj.get('sprite_key')}"
    if kind == "monsters":
        return f"CR {obj.get('challenge_rating')}, rank {obj.get('rank_pref', 'front')}"
    return obj.get("name", "")


def audit_generated_sql(sql: str) -> list[str]:
    """
    Refuse SQL that deletes a row it is about to re-insert.

    Every content table is the parent of an ON DELETE CASCADE from player data —
    character_spells, character_inventory, party_quests, party_quest_stages,
    character_known_npcs. So a reload that deletes and re-inserts a spell does not
    merely give it a new id; it takes with it every character who knew it, was
    carrying it, or was partway through it.

    That is what this file used to do to all nine of its tables, and it read as
    perfectly reasonable idempotence. The symptom was a save that quietly lost its
    casters' spells, everyone's inventory and all quest progress — noticed, days
    later, as "casters no longer have spells in combat".

    The rule is mechanical, so it is checked mechanically: a key that appears in a
    DELETE must not appear in an INSERT for the same table.

    LEAF_TABLES are the exception the rule allows for: tables nothing player-owned
    references. shop_inventory's rows are shelves — character_inventory points at
    `items`, never at a shelf — so delete-and-reinsert cannot cascade into a save,
    and for stock it is the desired behaviour: a content reload IS the restock.
    A table goes in this set only after checking the schema for inbound FKs.
    """
    LEAF_TABLES = {"shop_inventory"}
    problems = []

    deleted: dict[str, set[str]] = {}
    for m in re.finditer(
        r"DELETE FROM (\w+) WHERE .*?NOT IN \(([^)]*)\)", sql
    ):
        # `NOT IN` is the safe form: it removes what is *not* being written.
        deleted.setdefault(m.group(1), set())
    for m in re.finditer(
        r"DELETE FROM (\w+) WHERE (\w+) IN \(([^)]*)\)", sql
    ):
        table, keys = m.group(1), m.group(3)
        deleted.setdefault(table, set()).update(re.findall(r"'([^']*)'", keys))

    for m in re.finditer(r"INSERT INTO (\w+) \(([^)]*)\) VALUES\n(.*?);\n", sql, re.S):
        table, cols, body = m.group(1), m.group(2), m.group(3)
        if table in LEAF_TABLES:
            continue
        if table not in deleted or not deleted[table]:
            continue
        if not cols.split(",")[0].endswith("_key"):
            continue
        inserted = {
            v for v in re.findall(r"^\('([^']*)'", body, re.M)
        }
        clash = sorted(deleted[table] & inserted)
        if clash:
            problems.append(
                f"{table}: deletes then re-inserts {len(clash)} row(s) "
                f"({', '.join(clash[:3])}{'…' if len(clash) > 3 else ''}) — this "
                f"changes their ids and cascades into player data. Upsert instead."
            )

    return problems


def main():
    ap = argparse.ArgumentParser(
        description="Validate content/**/*.json and generate sql/content.sql.")
    ap.add_argument("--check", action="store_true",
                    help="validate only; write nothing")
    ap.add_argument("--verbose", action="store_true",
                    help="list what was found in each file")
    args = ap.parse_args()

    problems = Problems()
    index = load_tree(problems)
    validate(index, problems)

    if args.verbose:
        summarise(index)

    problems.report()
    if problems.errors:
        print(f"\n{len(problems.errors)} error(s), {len(problems.warnings)} warning(s) — "
              f"refusing to write {os.path.relpath(OUT, ROOT)}", file=sys.stderr)
        return 1
    if problems.warnings:
        print(f"\n{len(problems.warnings)} warning(s)", file=sys.stderr)

    total = sum(len(index.by_kind[k]) for k in KINDS)

    # Audit what would be written, whether or not it gets written. --check has to
    # catch this too: it is the mode verify.sh runs, and the whole point of the
    # audit is that nobody notices the damage by looking.
    if args.check:
        import io
        import contextlib
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            emitted = open(emit(index), encoding="utf-8").read()
    else:
        path = emit(index)
        emitted = open(path, encoding="utf-8").read()

    cascade = audit_generated_sql(emitted)
    if cascade:
        print("\nsql/content.sql would destroy player data:", file=sys.stderr)
        for c in cascade:
            print(f"  {c}", file=sys.stderr)
        return 1

    if args.check:
        print(f"content/ is valid: {total} files across {len(KINDS)} kinds; "
              f"content.sql preserves ids")
        return 0
    print(f"wrote {path} from {total} content files")
    return 0


if __name__ == "__main__":
    sys.exit(main())
