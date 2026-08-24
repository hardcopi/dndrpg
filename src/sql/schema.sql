-- 5e Browser RPG – Database Schema
-- Uses only open 5e SRD content under OGL 1.0a / CC-BY 4.0
-- Engine: InnoDB, UTF-8

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS rpg_5e
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rpg_5e;

DROP TABLE IF EXISTS roll_log;
DROP TABLE IF EXISTS party_quest_stages;
DROP TABLE IF EXISTS party_quests;
DROP TABLE IF EXISTS quest_stages;
DROP TABLE IF EXISTS party_companions;
DROP TABLE IF EXISTS companions;
DROP TABLE IF EXISTS world_flags;
DROP TABLE IF EXISTS combat_sessions;
DROP TABLE IF EXISTS character_known_npcs;
-- character_quests is gone — quests are party-scoped (party_quests) — but the
-- drop stays so a database built before the cleanup sheds the orphan table.
DROP TABLE IF EXISTS character_quests;
DROP TABLE IF EXISTS character_spells;
DROP TABLE IF EXISTS character_inventory;
DROP TABLE IF EXISTS character_party;
DROP TABLE IF EXISTS pending_level_ups;
DROP TABLE IF EXISTS party_exits_found;
DROP TABLE IF EXISTS party_items_taken;
DROP TABLE IF EXISTS party_encounters_cleared;
DROP TABLE IF EXISTS party_locations_visited;
DROP TABLE IF EXISTS location_items;
DROP TABLE IF EXISTS location_exits;
DROP TABLE IF EXISTS parties;
DROP TABLE IF EXISTS encounter_monsters;
DROP TABLE IF EXISTS encounters;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS regions;
DROP TABLE IF EXISTS modules;
DROP TABLE IF EXISTS quests;
DROP TABLE IF EXISTS npcs;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS spells;
DROP TABLE IF EXISTS monsters;
DROP TABLE IF EXISTS avatars;
DROP TABLE IF EXISTS races;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS characters;
DROP TABLE IF EXISTS app_settings;
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100),
    -- The whole of the permission model, deliberately. Two values, because two
    -- is what was asked for and because every additional role is a new set of
    -- questions about what it may reach. An ENUM rather than a lookup table for
    -- the same reason: the set is closed, and a bad value should be a write
    -- error rather than a row that quietly grants nothing.
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    -- Deactivation rather than deletion. characters.user_id is ON DELETE SET
    -- NULL, so removing an account would orphan its characters into a state no
    -- ownership check can reason about.
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    password_hash VARCHAR(255),
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bearer tokens for clients that are not a browser — Unity, today.
--
-- The website still signs in with a PHP cookie session. A standalone player
-- has no cookie jar that works like one, so login also issues one of these.
-- The hash is stored, not the secret; `state_json` carries the same per-play
-- bag the cookie session holds (active character, party, a pending ability
-- roll, an in-flight check) so those routes do not grow a second store.
CREATE TABLE api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    state_json JSON NULL,
    label VARCHAR(60) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id, revoked_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whether the public may sign themselves up. Lives in the database rather than
-- only in the environment so an admin can close registration from the admin
-- screen without a redeploy. RPG_REGISTRATION still wins when it is set.
CREATE TABLE app_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE characters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    -- Unique among LIVING characters, including recruited companions, because
    -- a party holding two Kessas is unreadable everywhere it matters: the
    -- initiative ribbon, the combat log and the approval readout all identify
    -- people by name.
    --
    -- Retiring somebody frees their name again, which is why the constraint
    -- hangs off the generated column below rather than off `name` directly:
    -- a retired row generates NULL, and NULLs do not collide in a unique
    -- index. A plain UNIQUE(name) would keep every name anyone ever used
    -- reserved forever, including the ones from characters that were rolled,
    -- disliked and abandoned in the first five minutes.
    name VARCHAR(50) NOT NULL,
    race VARCHAR(50) NOT NULL,
    subrace VARCHAR(50),
    class VARCHAR(50) NOT NULL,
    subclass VARCHAR(50),
    level TINYINT UNSIGNED DEFAULT 1,
    experience INT UNSIGNED DEFAULT 0,
    strength TINYINT UNSIGNED NOT NULL,
    dexterity TINYINT UNSIGNED NOT NULL,
    constitution TINYINT UNSIGNED NOT NULL,
    intelligence TINYINT UNSIGNED NOT NULL,
    wisdom TINYINT UNSIGNED NOT NULL,
    charisma TINYINT UNSIGNED NOT NULL,
    max_hp SMALLINT UNSIGNED NOT NULL,
    current_hp SMALLINT UNSIGNED NOT NULL,
    armor_class TINYINT UNSIGNED NOT NULL,
    speed TINYINT UNSIGNED DEFAULT 30,
    proficiency_bonus TINYINT UNSIGNED DEFAULT 2,
    -- Feats taken, as a JSON array of keys into Feats::CATALOG:
    -- ["tough","resilient_constitution"]. Nothing here validates the keys —
    -- Feats::taken() drops any the catalogue does not carry, so a feat renamed
    -- or retired leaves a save readable rather than broken.
    feats_json VARCHAR(255) NULL,
    gold INT UNSIGNED DEFAULT 0,
    background VARCHAR(50),
    alignment VARCHAR(30),
    -- Player's chosen avatar. NULL falls back to the class default in
    -- classes.sprite_key, so existing characters keep the look they had.
    sprite_key VARCHAR(50) NULL,
    -- Which line this character stands in when a fight starts. Combat has no
    -- movement grid, so this one field is the whole of their positioning: the
    -- front rank is what enemy melee can reach, and shields the back rank
    -- while any of it still stands.
    combat_rank ENUM('front','back') NOT NULL DEFAULT 'front',
    -- Spell slots expended, keyed by level: {"1":2,"2":0}. Persisted rather
    -- than held in combat state because slots survive a fight and only come
    -- back on a long rest.
    spell_slots_json VARCHAR(255) NULL,
    -- Tags dialogue may key an option off: race, subrace, class, background
    -- and anything a quest grants. Denormalised from the columns above at
    -- creation so DialogEngine does one lookup instead of five.
    origin_tags VARCHAR(255) NULL,
    -- Set for recruited companions; names the row in `companions` they were
    -- built from, so their authored dialogue and personal quest can be found.
    companion_key VARCHAR(60) NULL,
    -- The paperdoll layers a player chose in the creator, as
    -- {"sex":"male","body":"body_1","hair":"hair_2_alt3", ...}.
    --
    -- Kept alongside the baked sprite rather than instead of it: the sprite is
    -- what the game draws, and this is what it was made from. Without it a
    -- character can never be re-dressed, and a later fix to the layer order or
    -- the art could not re-bake anybody.
    appearance_json VARCHAR(600) NULL,
    -- The 3D look: which Sidekick parts the character wears, the three body
    -- sliders, and the colours. Written by the Unity client and by the
    -- character embed on the web; validated by SidekickAppearance, which is
    -- also the only thing that should read it.
    --
    -- Beside the paperdoll rather than instead of it. They describe the same
    -- person in two media and neither can be derived from the other: the
    -- paperdoll is a stack of PNG layers and this is a list of meshes.
    --
    -- TEXT rather than VARCHAR: a full recipe is about 1.4 KB, and a VARCHAR
    -- that size counts against InnoDB's 65,535-byte row limit on a table that
    -- is already wide. TEXT is stored off-page and costs the row nothing.
    -- SidekickAppearance caps what it writes at 4 KB regardless.
    sidekick_json TEXT NULL,
    -- Where the character stands. The world is a graph of locations, not a
    -- grid: this one id is the whole of a character's position.
    current_location_id INT UNSIGNED NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- The name of a living PLAYER character, or NULL. Exists only to carry the
    -- uniqueness constraint; nothing should read it. STORED rather than VIRTUAL
    -- because MySQL and MariaDB disagree about indexing virtual columns and this
    -- schema is meant to load identically on both.
    --
    -- Retired rows are NULL so retiring somebody gives their name back, and
    -- NULLs do not collide in a unique index.
    --
    -- Companions are NULL too, and that is the point rather than an oversight.
    -- A recruited companion is a `characters` row, but its name is authored
    -- rather than chosen: every playthrough that recruits Kessa Dunmar gets a
    -- row called "Kessa Dunmar", and a second party doing so is normal play,
    -- not a name clash. Including them made the second recruitment die on
    -- `Duplicate entry 'Kessa Dunmar'` in the middle of the scene where she
    -- agrees to join. The rule this index is for -- that no two living players
    -- share a name -- is unaffected, and CharacterGenerator::nameTaken() still
    -- reserves companion names against player characters separately.
    active_name VARCHAR(50) AS (
        CASE WHEN is_active = 1 AND companion_key IS NULL THEN name END
    ) STORED,
    UNIQUE KEY uq_active_name (active_name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    -- Which module this party is playing. Set once, when the party is made,
    -- and never changed: a party IS a playthrough of one game, so changing it
    -- would strand every quest, flag and visited location the party holds.
    --
    -- NULL means a party from before modules existed. Everything that reads it
    -- treats NULL as the default module rather than as an error, because a
    -- save that predates a feature should keep working.
    module_id INT UNSIGNED NULL,
    name VARCHAR(100) DEFAULT 'Adventuring Party',
    leader_character_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_party_module (module_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE character_party (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    character_id INT UNSIGNED NOT NULL,
    slot TINYINT UNSIGNED DEFAULT 1,
    UNIQUE KEY (party_id, character_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A MODULE is one game you can sit down to play: Rivermark Chronicles, or
-- Secrets of the Old City. It is a scoping label over the world graph and
-- nothing more clever than that — a module owns some regions, and a party
-- picks one when it is created and stays in it.
--
-- That is enough to keep two games apart because the world is already a graph
-- reachable only along authored exits. There is no global region list anywhere
-- a player can see; LocationEngine::regionMap() draws the region you are
-- standing in and labels arrows along real exits out of it. So two region
-- clusters with no exit between them are isolated by construction, and the
-- only thing that ever leaked across was the job board, which posts by query
-- rather than by walking the graph.
--
-- Deliberately NOT scoped: items, monsters, spells, races, classes, avatars.
-- A goblin is a goblin. The bestiary and the rules are shared, and a module
-- that wants its own creature authors one like anybody else.
--
-- Authored in content/modules/*.json and imported by tools/load_content.py.
-- ---------------------------------------------------------------------------
CREATE TABLE modules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    -- What the picker shows under the name. Prose, not a description of rules.
    blurb TEXT NULL,
    -- Where a party that picks this module wakes up.
    --
    -- A location_key rather than an id, for the same reason every other
    -- reference in content is a key: modules are inserted before the locations
    -- exist, and an id written into content is an id that repoints at whatever
    -- happens to hold that number after the next import. Resolved to a row by
    -- CharacterGenerator::startingLocationId().
    start_location_key VARCHAR(64) NOT NULL,
    -- Advertised range, for the picker. Not enforced — a level 6 party may
    -- walk into a level 1 module and find it easy, which is their business.
    level_min TINYINT UNSIGNED NOT NULL DEFAULT 1,
    level_max TINYINT UNSIGNED NOT NULL DEFAULT 6,
    -- Credit line, shown on the picker and on about.php. Every Creative
    -- Commons licence requires attribution, so a module adapted from someone
    -- else's scenario carries theirs here rather than in a comment nobody
    -- renders.
    attribution TEXT NULL,
    -- Hides a module from the picker without deleting its content or the saves
    -- inside it. This is the lever for "the licence check did not come back" —
    -- parties already in it keep playing, nobody new can start one.
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The world is a graph, not a grid.
--
-- A REGION is one map view: Rivermark, the Quarry Wilds, the Goblin Warren.
-- The client draws it as an SVG chart of its locations. A LOCATION is a node
-- on that chart — one described scene the party can stand in, with the people
-- present listed and the ways out named. There are no tiles, no coordinates a
-- character can occupy, and nothing to collide with; map_x/map_y below are
-- percentages that place the node on the drawing, nothing more.
--
-- Authored in content/locations/<region_key>.json and imported by
-- tools/load_content.py, same as every other kind of content.
-- ---------------------------------------------------------------------------
CREATE TABLE regions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_key VARCHAR(64) NOT NULL UNIQUE,
    -- Which game this region belongs to. Every region has one; the loader
    -- refuses a region file that does not name a module that exists.
    module_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    region_type ENUM('town','wilderness','dungeon') NOT NULL DEFAULT 'town',
    -- Authored dungeon shape: rooms as rectangles on the same coarse plan
    -- DungeonGen uses, halls as pairs of room ids. NULL for a town, a
    -- wilderness, or a generated floor. The plan is the source; locations
    -- and exits are compiled from it on save so the chart and the walker
    -- cannot disagree with the drawing.
    plan_json TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_region_module (module_id),
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Globally unique across regions: authored content (place blocks, quest
    -- targets, travel effects) references locations by this key alone.
    location_key VARCHAR(64) NOT NULL UNIQUE,
    region_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    -- The prose. This is what the player reads instead of seeing tiles.
    description TEXT NOT NULL,
    -- Shown once, the first time the party arrives, above the description.
    first_visit_text TEXT NULL,
    -- Rotating one-line flavor, as a JSON array of strings. One is picked per
    -- render so the scene breathes without any authored event.
    ambience_json TEXT NULL,
    -- A sprung-once hazard on this location, or NULL for almost every row.
    -- Written only by DelveEngine onto generated passages: {key, name, save,
    -- dc, damage, text, found_text}, fired by LocationEngine::travel() on
    -- arrival. Deliberately its own column rather than a tenant of
    -- ambience_json — that field is authored prose with the editors and the
    -- exporter round-tripping it, and mechanics hidden in it would make every
    -- reader guard against a shape it was never meant to hold. Whether a
    -- party has found or sprung the trap is per-party state in world_flags
    -- (dg_trap_<location_key>), not here.
    trap_json TEXT NULL,
    -- Which glyph the SVG map draws for the node.
    -- `passage` is a generated dungeon's corridor: a place you stand in on the
    -- way between two rooms. The chart draws it as its own run rather than as
    -- a name, so it is the one location type with no label on the map.
    location_type VARCHAR(32) NOT NULL DEFAULT 'site',  -- square|street|gate|building|room|passage|site|camp
    -- Percent position of the node on the region's map drawing.
    map_x DECIMAL(5,2) NOT NULL DEFAULT 50,
    map_y DECIMAL(5,2) NOT NULL DEFAULT 50,
    -- Non-NULL means "Rest at the inn" is offered here, at this price in gold.
    inn_cost SMALLINT UNSIGNED NULL,
    -- Whether the party may make camp (free long rest) here.
    allow_camp TINYINT(1) NOT NULL DEFAULT 0,
    -- Whether there is a job board on the wall.
    --
    -- Work posted publicly has to be read somewhere. The board used to be a
    -- panel with no place in the world, so every job the party was high enough
    -- for simply appeared in the journal wherever they were standing — the
    -- game telling them about a notice they had never walked past. Reading the
    -- board here is what puts its jobs in the journal.
    has_job_board TINYINT(1) NOT NULL DEFAULT 0,
    -- Percent chance, rolled per travel hop INTO this location, of a random
    -- encounter from this region's is_random pool interrupting the trip.
    random_encounter_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Not drawn on the map (beyond a "?" node) until the party has been here.
    -- The way in is usually a hidden exit surfaced by Search or a quest.
    hidden_until_visited TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The ways out of a location, with the label the player clicks. Directed:
-- authored both ways when a passage is two-way, which nearly all are. This is
-- the graph routeTo() BFSes over, so an unreachable location is an authoring
-- error the content loader flags.
CREATE TABLE location_exits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id INT UNSIGNED NOT NULL,
    label VARCHAR(160) NOT NULL,
    -- Optional Requirements list (same vocabulary dialogue uses); NULL is
    -- always open. A locked exit is listed greyed with its label, which is
    -- how the player learns a way exists at all.
    conditions_json TEXT NULL,
    -- A hidden exit is not listed until the party Searches here (or the
    -- destination is already visited). This is the cellar-door beat.
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_exit (from_location_id, to_location_id),
    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monsters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    monster_key VARCHAR(60) NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    size VARCHAR(20),
    type VARCHAR(50),
    alignment VARCHAR(50),
    armor_class TINYINT UNSIGNED,
    hit_points SMALLINT UNSIGNED,
    hit_dice VARCHAR(20),
    speed VARCHAR(50),
    strength TINYINT UNSIGNED,
    dexterity TINYINT UNSIGNED,
    constitution TINYINT UNSIGNED,
    intelligence TINYINT UNSIGNED,
    wisdom TINYINT UNSIGNED,
    charisma TINYINT UNSIGNED,
    challenge_rating DECIMAL(4,2),
    experience_points INT UNSIGNED,
    traits TEXT,
    actions TEXT,
    legendary_actions TEXT,
    -- Structured rules data. `traits` above is prose the player can read;
    -- these are what the engine actually consults, so a Skeleton's
    -- bludgeoning vulnerability changes the damage rather than only the
    -- flavour text.
    saves_json VARCHAR(255) NULL,           -- {"dex":4,"con":2} proficient save bonuses
    resistances_json VARCHAR(255) NULL,     -- ["poison","cold"]
    immunities_json VARCHAR(255) NULL,
    vulnerabilities_json VARCHAR(255) NULL,
    condition_immunities_json VARCHAR(255) NULL,
    -- What the body is worth: [{"item":"<item_key>","chance":50},{"gold":"2d6"}].
    -- Item KEYS rather than ids — the "Keys, not ids" rule — resolved at drop
    -- time, so a content reload cannot orphan a loot table.
    loot_json VARCHAR(500) NULL,
    -- Which line this monster prefers to hold. Archers and casters ask for the
    -- back rank; brutes take the front. The encounter builder honours it where
    -- the ranks have room.
    rank_pref ENUM('front','back') NOT NULL DEFAULT 'front',
    image_url VARCHAR(255),
    -- Art set under assets/images/monsters/. Resolves the walk sheet, the
    -- battler, the portrait and the MVsv combat clips.
    sprite_key VARCHAR(50) NULL,
    source VARCHAR(50) DEFAULT '5e SRD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encounters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_key VARCHAR(60) NULL UNIQUE,
    name VARCHAR(100),
    description TEXT,
    -- A placed fight waits at this location; arriving there triggers it.
    -- Whether it has already been fought is per-party state in
    -- party_encounters_cleared, so content rows are never mutated by play.
    location_id INT UNSIGNED NULL,
    -- A random encounter (is_random = 1) has no fixed place; region_id scopes
    -- which region's travel rolls may draw it.
    region_id INT UNSIGNED NULL,
    is_random TINYINT(1) DEFAULT 0,
    -- Whether standing here starts this fight.
    --
    -- Most authored fights are opened by a line of dialogue — `start_combat`
    -- in a choice's effects — and merely happen to be associated with a place.
    -- Firing those on arrival would mean walking into the Golden Flagon to be
    -- set upon by a debt collector you have not met. An ambush is the other
    -- kind: the rats in the cellar, the gate the goblins keep, the warlord at
    -- the bottom of the shaft. Those are what the location IS.
    is_ambush TINYINT(1) NOT NULL DEFAULT 0,
    difficulty VARCHAR(20),
    -- Fights can be talked out of, fled, or surrendered to. A resolvable
    -- encounter offers those buttons; a scripted duel does not.
    allow_flee TINYINT(1) NOT NULL DEFAULT 1,
    allow_parley TINYINT(1) NOT NULL DEFAULT 0,
    -- Whether the roster below is a fixed quantity or a difficulty.
    --
    -- On, which is the default, the authored monsters are a statement of WHO
    -- is here and `difficulty` is a statement of how hard it should be; the
    -- counts are fitted to the party at the moment the fight starts. Off, the
    -- roster is exactly what walks onto the board however strong the party is.
    --
    -- Turn it off for a set piece whose size is the point — a duel, a lone
    -- named opponent, a fight a quest describes in words. Leave it on for the
    -- rats in a cellar, which are simply "some rats".
    scale_to_party TINYINT(1) NOT NULL DEFAULT 1,
    -- Dialogue node to open if the party parleys successfully, as
    -- "npc_key:node_key". This is how a fight becomes a conversation.
    parley_node VARCHAR(120) NULL,
    -- Set when the encounter ends, so quests and dialogue can branch on how a
    -- fight finished rather than only on whether it happened.
    victory_flag VARCHAR(120) NULL,
    defeat_flag VARCHAR(120) NULL,
    -- The stage a quest reaches by winning this fight, as "quest_key:stage_key".
    --
    -- Stage-based quests advance through dialogue, which is right for a story
    -- told in conversation and wrong the moment a fight is also an answer. The
    -- raid in the Broken Hills could be talked down through Nakka — and every
    -- one of her replies moved the quest — or simply beaten, which moved
    -- nothing: the party won the road and the journal still asked them to go
    -- and count wagons. A fight that resolves a quest says so here, and the
    -- engine applies it on victory exactly as a reply would.
    victory_quest_stage VARCHAR(120) NULL
    -- location_id / region_id FKs are added at the end of this file, after
    -- `locations` and `regions` exist.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encounter_monsters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT UNSIGNED NOT NULL,
    monster_id INT UNSIGNED NOT NULL,
    quantity TINYINT UNSIGNED DEFAULT 1,
    -- Overrides the monster's own rank_pref for this encounter. The same
    -- goblin is a skirmisher in one fight and an archer holding the back in
    -- another, and that is a property of the fight, not of the creature.
    rank_override ENUM('front','back') NULL,
    FOREIGN KEY (encounter_id) REFERENCES encounters(id) ON DELETE CASCADE,
    FOREIGN KEY (monster_id) REFERENCES monsters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a party has fought and won. Replaces the old scheme of NULLing the
-- encounter off its tile, which mutated shared content: two playthroughs
-- should not clear each other's fights, and an export should never see a
-- world scarred by play.
CREATE TABLE party_encounters_cleared (
    party_id INT UNSIGNED NOT NULL,
    encounter_id INT UNSIGNED NOT NULL,
    cleared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (party_id, encounter_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (encounter_id) REFERENCES encounters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A delve in progress: which party is down a hole, and which hole.
--
-- The dungeon itself is NOT stored. `seed` and `depth` are enough to rebuild
-- any level exactly, because DungeonGen is deterministic — so this table is
-- three numbers rather than a hundred rows, and a bug in a level can be
-- reported as a seed.
--
-- The rows the party actually walks around in are ordinary `regions`,
-- `locations` and `location_exits`, written by DelveEngine with keys prefixed
-- `_dg_`. They are deleted when the delve ends, and every per-party table that
-- references them cascades away with them — which is why a generated dungeon
-- needs no cleanup code of its own beyond dropping the region.
--
-- One delve per party, enforced by the unique key: a party is one expedition,
-- and two live dungeons for one party would leave rows nothing would ever
-- collect.
CREATE TABLE dungeon_delves (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    seed INT UNSIGNED NOT NULL,
    -- 1 is the first level below the Mouth. Deeper is harder; see
    -- DungeonGen::profileForDepth().
    depth TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- The generated region the party is currently inside, so ending a delve
    -- knows what to drop without having to guess from a key pattern.
    region_id INT UNSIGNED NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delve_party (party_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE npcs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Stable name used by authored content. Numeric ids are assigned by the
    -- import and must never appear in a JSON file, or reordering content
    -- would silently repoint every reference.
    npc_key VARCHAR(60) NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(80) NULL,                 -- display class/occupation (Innkeeper, Cleric, …)
    description TEXT,
    image_url VARCHAR(255),
    -- Art set under assets/images/npcs/, same convention as characters:
    -- <key>_sheet.png, <key>_face.png, <key>_bust.png, <key>_bust_2..8.png
    sprite_key VARCHAR(50) NULL,
    -- How many expression busts were cut for this sprite_key. Dialogue nodes
    -- name an expression by index; this bounds what they may ask for.
    bust_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    dialogue_json MEDIUMTEXT,
    is_merchant TINYINT(1) DEFAULT 0,
    is_quest_giver TINYINT(1) DEFAULT 0,
    -- A standing pose, when this person is not simply idling: 'sleeping' or
    -- 'kneel'. Names one of the animation sheets cut beside their walk. A
    -- posed NPC holds their place — a beggar who wanders is not kneeling for
    -- alms, he is doing laps.
    pose VARCHAR(20) NULL,
    -- Background townsfolk: listed in the scene, but quietly.
    --
    -- The people list leads with the authored characters — the ones with real
    -- conversations and quests. Ambient folk are the crowd: they render in a
    -- muted secondary list, still talkable, but they do not compete with Mara
    -- for the eye.
    is_ambient TINYINT(1) DEFAULT 0,
    -- Where this person stands. NULL is "nowhere": recruited companions are
    -- subtracted from the scene by the engine, and an unplaced NPC simply
    -- does not appear. Placement lives on the NPC row because a person stands
    -- in at most one place; it is authored via the place block in
    -- content/npcs/*.json.
    location_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What each merchant actually sells.
--
-- Before this table, every merchant offered one global SELECT over the items
-- table — identical stock everywhere, `is_merchant` gating nothing, and the
-- four 0-gp quest items on open sale, so the McGuffin of ledger_and_lie could
-- be bought off any counter before meeting anyone. Stock is authored in
-- content/npcs/*.json under "shop" and owned by tools/load_content.py.
--
-- stock NULL means unlimited; a number is decremented by purchases and refused
-- at zero. A content reload restores the authored figure, which on a dev
-- database is a feature.
CREATE TABLE shop_inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    npc_key VARCHAR(60) NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    stock SMALLINT UNSIGNED NULL,
    -- Percent of items.value_gp this merchant charges. 100 = list price.
    markup_pct SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    UNIQUE KEY uq_shop_item (npc_key, item_id),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE character_known_npcs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    npc_id INT UNSIGNED NOT NULL,
    known_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_char_npc (character_id, npc_id),
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (npc_id) REFERENCES npcs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(60) NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    item_type ENUM('weapon','armor','potion','scroll','misc','treasure') NOT NULL,
    rarity VARCHAR(30) DEFAULT 'common',
    weight DECIMAL(5,2) DEFAULT 0,
    value_gp INT UNSIGNED DEFAULT 0,
    damage_dice VARCHAR(20),
    damage_type VARCHAR(30),
    armor_bonus TINYINT,
    armor_type VARCHAR(30),
    -- Where a wearable sits: ring, amulet, cloak, boots. NULL for everything
    -- else — weapons, armour and shields derive their slot from item_type and
    -- armor_type, and a slotless misc item cannot be equipped at all.
    slot VARCHAR(20) NULL,
    properties TEXT,
    image_url VARCHAR(255),
    -- The packs ship no icon art and there is no icon set to cut from, so an
    -- item names a glyph the stylesheet draws instead of a file that does not
    -- exist. Falls back to item_type when unset.
    icon VARCHAR(40) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE character_inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED DEFAULT 1,
    is_equipped TINYINT(1) DEFAULT 0,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ground loot: items authored as lying in a location, surfaced by the Search
-- action. Whether a given party has already taken one is per-party state in
-- party_items_taken, so content is never consumed globally.
CREATE TABLE location_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE party_items_taken (
    party_id INT UNSIGNED NOT NULL,
    location_item_id INT UNSIGNED NOT NULL,
    taken_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (party_id, location_item_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (location_item_id) REFERENCES location_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which locations a party has stood in. Drives fog on the SVG map, the
-- first-visit paragraph, and hidden locations staying hidden.
CREATE TABLE party_locations_visited (
    party_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    first_visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (party_id, location_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hidden exits a party has found by Searching. An exit with is_hidden = 1 is
-- listed only once it appears here (or its destination is already visited).
CREATE TABLE party_exits_found (
    party_id INT UNSIGNED NOT NULL,
    exit_id INT UNSIGNED NOT NULL,
    found_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (party_id, exit_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (exit_id) REFERENCES location_exits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quest_key VARCHAR(60) NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    -- Which act this belongs to, so a journal can group it and the content
    -- loader can refuse a stage that points into an act that is not authored.
    act TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- A personal quest belongs to one companion and only appears once they are
    -- recruited and their approval clears the gate in the quest's own stages.
    companion_key VARCHAR(60) NULL,
    -- Whether the job board lists it. Personal quests and quests that start
    -- from a conversation are hidden.
    on_job_board TINYINT(1) NOT NULL DEFAULT 0,
    giver_npc_id INT UNSIGNED NULL,
    required_level TINYINT UNSIGNED DEFAULT 1,
    reward_xp INT UNSIGNED DEFAULT 0,
    reward_gold INT UNSIGNED DEFAULT 0,
    reward_item_id INT UNSIGNED NULL,
    target_location_id INT UNSIGNED NULL,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (giver_npc_id) REFERENCES npcs(id) ON DELETE SET NULL,
    FOREIGN KEY (reward_item_id) REFERENCES items(id) ON DELETE SET NULL,
    FOREIGN KEY (target_location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A quest is a graph of stages, not a boolean. Each stage names what the party
-- is meant to do next and which stages it may lead to; a stage flagged
-- `is_terminal` is one of the quest's endings, and `resolution` is the label
-- the journal and the epilogue read to know which ending was taken.
--
-- Kept as rows rather than a JSON blob on `quests` because the journal, the
-- map's quest markers and the dialogue conditions all query stages directly.
CREATE TABLE quest_stages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quest_id INT UNSIGNED NOT NULL,
    stage_key VARCHAR(60) NOT NULL,
    title VARCHAR(150) NOT NULL,
    -- What the tracker shows while this stage is current.
    objective TEXT,
    -- Journal prose appended when the stage is entered, so a finished quest
    -- reads as a record of what actually happened.
    journal_entry TEXT,
    -- Where the tracker points while this stage is current. The tracker
    -- names the location and routes to it over the exit graph.
    target_location_id INT UNSIGNED NULL,
    -- Terminal stages end the quest. `resolution` distinguishes endings from
    -- one another — 'sided_with_sera', 'burned_the_mill', 'walked_away'.
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    resolution VARCHAR(60) NULL,
    outcome ENUM('success','failure','neutral') NOT NULL DEFAULT 'success',
    -- Applied when this stage is entered, in the same effect vocabulary
    -- dialogue uses: set flags, adjust approval, grant items, award XP.
    effects_json TEXT,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_quest_stage (quest_id, stage_key),
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    FOREIGN KEY (target_location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Party-scoped state. Everything the world remembers about a playthrough.
--
-- Scoped to the party rather than a character on purpose: companions join and
-- leave, and the leader can be swapped, but the party is the save. Anything
-- keyed to one character would be lost or duplicated the moment the roster
-- changes.
-- ---------------------------------------------------------------------------

-- The flag store. Dialogue, quests, encounters and companions all read and
-- write here, and it is the only thing that makes an NPC able to remember what
-- you did. Values are strings; the engine coerces to int where a condition
-- compares numerically, so a flag can be a boolean, a counter or a label
-- without needing three tables.
CREATE TABLE world_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    flag_key VARCHAR(120) NOT NULL,
    flag_value VARCHAR(255) NOT NULL DEFAULT '1',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_party_flag (party_id, flag_key),
    KEY idx_flag_key (flag_key),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authored companion definitions, imported from content/companions/*.json.
-- Deliberately separate from `characters`: this is the template, and a row in
-- `characters` is the instance created when the party recruits them. Keeping
-- the two apart means editing a companion's authored stats never has to
-- reconcile with a save already in progress.
CREATE TABLE companions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    companion_key VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    title VARCHAR(120) NULL,               -- "the Ledger-Keeper's Daughter"
    description TEXT,
    race VARCHAR(50) NOT NULL,
    subrace VARCHAR(50) NULL,
    class VARCHAR(50) NOT NULL,
    background VARCHAR(50) NULL,
    alignment VARCHAR(30) NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    strength TINYINT UNSIGNED NOT NULL,
    dexterity TINYINT UNSIGNED NOT NULL,
    constitution TINYINT UNSIGNED NOT NULL,
    intelligence TINYINT UNSIGNED NOT NULL,
    wisdom TINYINT UNSIGNED NOT NULL,
    charisma TINYINT UNSIGNED NOT NULL,
    sprite_key VARCHAR(50) NOT NULL,
    bust_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    combat_rank ENUM('front','back') NOT NULL DEFAULT 'front',
    npc_key VARCHAR(60) NULL,              -- their conversation, in `npcs`
    personal_quest_key VARCHAR(60) NULL,
    -- Where they are found and what has to be true before they will join.
    recruit_location_id INT UNSIGNED NULL,
    recruit_conditions_json TEXT,
    -- Approval thresholds. Crossing `leave_at` downward makes them walk out;
    -- `hostile_at` makes them turn on the party instead. Romance cannot open
    -- below `romance_at`.
    leave_at SMALLINT NOT NULL DEFAULT -40,
    hostile_at SMALLINT NOT NULL DEFAULT -70,
    romance_at SMALLINT NOT NULL DEFAULT 30,
    romanceable TINYINT(1) NOT NULL DEFAULT 0,
    starting_gear_json TEXT,
    starting_spells_json TEXT,
    -- Feats the template arrives with, as a JSON array of Feats::CATALOG keys.
    -- In SRD 5.2 an Origin feat is what a background grants at first level, and
    -- a companion is the only character in this game with an authored
    -- background nobody chose — so this is where Kessa gets to be Tough and
    -- Aldric gets to be a Healer without either of them levelling for it.
    -- CompanionService::grantFeats applies them through LevelUpService, so the
    -- hit points and armour class a feat is worth arrive with it.
    starting_feats_json TEXT,
    FOREIGN KEY (recruit_location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A companion's standing with one party: whether they are travelling with you,
-- waiting at camp, gone, or hunting you, and how they feel about it.
CREATE TABLE party_companions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    companion_key VARCHAR(60) NOT NULL,
    -- The `characters` row created for them on recruitment. NULL until then.
    character_id INT UNSIGNED NULL,
    status ENUM('unmet','met','active','camp','left','hostile','dead')
        NOT NULL DEFAULT 'unmet',
    approval SMALLINT NOT NULL DEFAULT 0,
    -- Romance runs on its own track so a companion can be a close friend
    -- without the arc opening, and so declining does not cost approval twice.
    romance_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    romance_locked TINYINT(1) NOT NULL DEFAULT 0,
    recruited_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_party_companion (party_id, companion_key),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quest progress for a party, replacing the per-character boolean.
CREATE TABLE party_quests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    quest_id INT UNSIGNED NOT NULL,
    status ENUM('available','active','completed','failed') NOT NULL DEFAULT 'available',
    current_stage_id INT UNSIGNED NULL,
    -- Copied off the terminal stage when the quest ends, so later acts can
    -- branch on the ending without walking the stage history.
    resolution VARCHAR(60) NULL,
    is_tracked TINYINT(1) NOT NULL DEFAULT 0,
    started_at TIMESTAMP NULL DEFAULT NULL,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_party_quest (party_id, quest_id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    FOREIGN KEY (current_stage_id) REFERENCES quest_stages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stage a party has entered, in order. This is what the journal prints:
-- not the quest's design, but the path this playthrough actually took.
CREATE TABLE party_quest_stages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    quest_id INT UNSIGNED NOT NULL,
    stage_id INT UNSIGNED NOT NULL,
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_party_stage (party_id, stage_id),
    KEY idx_party_quest_order (party_id, quest_id, id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    FOREIGN KEY (stage_id) REFERENCES quest_stages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every d20 the party has rolled, with the breakdown that produced it.
--
-- Written server-side at resolve time. The client animates a die onto a number
-- it is handed rather than choosing one, so the roll history is a record and
-- not a re-enactment — which is the only way the modifier prompt can be
-- offered before the result exists without the result being guessable.
CREATE TABLE roll_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    character_id INT UNSIGNED NULL,
    kind ENUM('ability','skill','save','attack','initiative','death') NOT NULL,
    label VARCHAR(120) NOT NULL,           -- "Persuasion", "Dexterity save"
    dc TINYINT UNSIGNED NULL,
    die_mode ENUM('normal','advantage','disadvantage') NOT NULL DEFAULT 'normal',
    d20_rolls VARCHAR(20) NOT NULL,        -- "14" or "14,7" when two were rolled
    -- Quoted because NATURAL is a reserved word in MySQL; unquoted, this
    -- CREATE TABLE is a syntax error and the whole schema stops loading here.
    `natural` TINYINT UNSIGNED NOT NULL,   -- the die that counted
    modifier SMALLINT NOT NULL,
    total SMALLINT NOT NULL,
    -- Which boosts were spent, so the log can explain a total that does not
    -- match the base modifier: [{"key":"guidance","label":"Guidance","bonus":3}]
    boosts_json VARCHAR(500) NULL,
    outcome ENUM('success','failure','critical','fumble') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_roll_party (party_id, id),
    FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE combat_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    encounter_id INT UNSIGNED,
    party_id INT UNSIGNED NULL,
    current_turn INT UNSIGNED DEFAULT 0,
    -- The whole fight, including any check parked mid-turn waiting on the
    -- player's die. One blob on purpose: state split across columns is state
    -- that can disagree with itself.
    state_json LONGTEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (encounter_id) REFERENCES encounters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spells (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spell_key VARCHAR(60) NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    level TINYINT UNSIGNED DEFAULT 0,
    -- "At Higher Levels": {"dice_per_level": "1d8"} — what each slot level
    -- above the spell's own adds to the damage or healing dice. NULL for a
    -- spell that gains nothing from a bigger slot.
    higher_level_json VARCHAR(255) NULL,
    school VARCHAR(50),
    casting_time VARCHAR(50),
    range_text VARCHAR(50),
    components VARCHAR(100),
    duration VARCHAR(100),
    description TEXT,
    damage_dice VARCHAR(20),
    damage_type VARCHAR(30),
    -- How the spell resolves. `attack` rolls against AC, `save` makes the
    -- target roll, `auto` always lands (Magic Missile), `heal` and `buff`
    -- target allies. The old engine treated every spell as an attack roll,
    -- which quietly turned Sacred Flame — a Dexterity save — into something
    -- the SRD does not describe.
    resolution ENUM('attack','save','auto','heal','buff','debuff') NOT NULL DEFAULT 'attack',
    -- Which save the target rolls when `resolution` is 'save', and whether a
    -- success halves the damage or negates it entirely.
    save_ability ENUM('str','dex','con','int','wis','cha') NULL,
    save_effect ENUM('half','negate') NOT NULL DEFAULT 'half',
    -- Whether holding the spell requires concentration, which caps the party
    -- at one such effect each and drops it on a failed save after damage.
    concentration TINYINT(1) NOT NULL DEFAULT 0,
    -- Rounds the effect lasts once it lands; 0 is instantaneous.
    duration_rounds TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Who it may be pointed at, and how many of them.
    target_kind ENUM('enemy','ally','self','any','all_enemies','all_allies')
        NOT NULL DEFAULT 'enemy',
    -- Whether it can cross ranks. A touch spell cannot reach the enemy back
    -- rank; a bolt can.
    reaches_back_rank TINYINT(1) NOT NULL DEFAULT 1,
    -- Conditions applied on a failed save, and stat changes while it holds:
    -- {"condition":"frightened"} / {"ac":2,"advantage_on":"saves"}
    effect_json VARCHAR(500) NULL,
    -- Which classes may learn this, comma-separated: 'Cleric,Druid'. Level-up
    -- offers a caster spells from this; before it existed the only class/spell
    -- mapping was a hardcoded STARTING_SPELLS array in CharacterGenerator,
    -- which said what you begin with and nothing about what comes next.
    class_list VARCHAR(120) NULL,
    source VARCHAR(50) DEFAULT '5e SRD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE character_spells (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    spell_id INT UNSIGNED NOT NULL,
    prepared TINYINT(1) DEFAULT 1,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (spell_id) REFERENCES spells(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A level recorded as owed and claimed afterwards. XP can be granted from
-- anywhere, including mid-conversation and mid-combat, and neither is a place
-- to stop and ask somebody which ability score they would like to raise.
-- Rows are kept after resolution: `resolved_at` plus the columns below are the
-- record of what was rolled and chosen, so a character sheet can be explained.
CREATE TABLE pending_level_ups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    new_level TINYINT UNSIGNED NOT NULL,
    -- The die face, and what it came to once CON was added. Both NULL until
    -- rolled. hp_gain is stored rather than recomputed because a CON change
    -- later in the same ceremony must not retroactively rewrite a roll the
    -- player already watched land.
    hp_roll TINYINT UNSIGNED NULL,
    hp_gain SMALLINT NULL,
    -- {"asi": {"str": 2}} or {"feat": "grappler"} or {"spells": ["fear"]}.
    choices_json JSON NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- A character reaches a given level exactly once, which is what makes
    -- recording a level-up safe to call twice.
    UNIQUE KEY uq_character_level (character_id, new_level),
    KEY idx_unresolved (character_id, resolved_at),
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avatars a player may pick from on the character sheet. This is also the
-- whitelist the API validates against: sprite_key ends up in an asset path, so
-- it must never be free text from the client.
CREATE TABLE avatars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sprite_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(80) NOT NULL,
    sort_order SMALLINT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE races (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    subrace VARCHAR(50),
    speed TINYINT UNSIGNED DEFAULT 30,
    str_bonus TINYINT DEFAULT 0,
    dex_bonus TINYINT DEFAULT 0,
    con_bonus TINYINT DEFAULT 0,
    int_bonus TINYINT DEFAULT 0,
    wis_bonus TINYINT DEFAULT 0,
    cha_bonus TINYINT DEFAULT 0,
    traits TEXT,
    -- Who these people are, in this world's own words. NULL for the SRD races:
    -- their prose is in the SRD and copying it here would put a licensed
    -- document in a column, so the creator simply shows nothing for them.
    -- Filled in for anything of our own, where it is the only place the race
    -- exists as more than six numbers.
    description TEXT NULL,
    -- '5e SRD' for anything taken from the SRD under CC-BY, and the provenance
    -- of anything that is not. Worth a column rather than a comment: the
    -- licence obligations differ by row, and the footer's attribution is only
    -- honest if we can tell which rows it covers.
    source VARCHAR(50) DEFAULT '5e SRD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    hit_die TINYINT UNSIGNED NOT NULL,
    primary_ability VARCHAR(50),
    saving_throws VARCHAR(50),
    armor_proficiencies TEXT,
    weapon_proficiencies TEXT,
    subclass_name VARCHAR(50),
    subclass_level TINYINT UNSIGNED DEFAULT 3,
    features TEXT,
    sprite_key VARCHAR(50),                -- art set under assets/images/npcs/
    source VARCHAR(50) DEFAULT '5e SRD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deferred FKs: these tables are created before `locations` exists in file
-- order, so their location references are added here at the end.
ALTER TABLE characters
    ADD CONSTRAINT fk_char_location FOREIGN KEY (current_location_id) REFERENCES locations(id) ON DELETE SET NULL;

ALTER TABLE npcs
    ADD CONSTRAINT fk_npc_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;

ALTER TABLE encounters
    ADD CONSTRAINT fk_enc_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_enc_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
