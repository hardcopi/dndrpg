-- 5e Browser RPG – Seed Data
-- All names and stats from the 5e System Reference Document (SRD)
-- under OGL 1.0a / CC-BY 4.0
--
-- This file seeds the *rules*: the tables a character sheet is built from and
-- nothing a player can meet. Everything with a story attached — monsters,
-- items, spells, NPCs, quests, encounters — is authored under content/ and
-- imported by tools/load_content.py into sql/content.sql. See docs/CONTENT.md.
--
-- Those sections used to live here too, and the two sets of rows did not
-- replace each other: content/ addresses rows by their stable key, the rows
-- below had no key, and DELETE ... WHERE monster_key IN (...) never matched
-- them. So the seed Skeleton stood next to the authored Gnoll, the seed
-- fetch-quests sat on the job board beside the real ones, and every count was
-- roughly double what the act declares. A table is owned by one file or the
-- other; each removed section says below which one now owns it.

USE rpg_5e;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- The administrator account. password_hash is deliberately NULL: a default
-- password is a published password. Auth::login() refuses any account with no
-- hash, so this cannot be used until somebody sets one:
--
--     docker compose exec -T php php /var/www/html/tools/set_password.php admin
INSERT INTO users (id, username, email, role, password_hash) VALUES
(1, 'admin', 'admin@localhost', 'admin', NULL);

-- Whether the public may sign themselves up. RPG_REGISTRATION wins when set.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('registration_open', '1');

-- ---------------------------------------------------------------------------
-- Races (SRD)
-- ---------------------------------------------------------------------------
INSERT INTO races (name, subrace, speed, str_bonus, dex_bonus, con_bonus, int_bonus, wis_bonus, cha_bonus, traits) VALUES
('Dragonborn', NULL, 30, 2, 0, 0, 0, 0, 1, 'Draconic Ancestry, Breath Weapon, Damage Resistance'),
('Dwarf', 'Hill Dwarf', 25, 0, 0, 2, 0, 1, 0, 'Darkvision, Dwarven Resilience, Stonecunning, Dwarven Toughness'),
('Dwarf', 'Mountain Dwarf', 25, 2, 0, 2, 0, 0, 0, 'Darkvision, Dwarven Resilience, Stonecunning, Dwarven Armor Training'),
('Elf', 'High Elf', 30, 0, 2, 0, 1, 0, 0, 'Darkvision, Keen Senses, Fey Ancestry, Trance, Cantrip'),
('Elf', 'Wood Elf', 35, 0, 2, 0, 0, 1, 0, 'Darkvision, Keen Senses, Fey Ancestry, Trance, Fleet of Foot, Mask of the Wild'),
('Elf', 'Dark Elf', 30, 0, 2, 0, 0, 0, 1, 'Superior Darkvision, Keen Senses, Fey Ancestry, Trance, Sunlight Sensitivity, Drow Magic'),
('Gnome', 'Forest Gnome', 25, 0, 1, 0, 2, 0, 0, 'Darkvision, Gnome Cunning, Natural Illusionist, Speak with Small Beasts'),
('Gnome', 'Rock Gnome', 25, 0, 0, 1, 2, 0, 0, 'Darkvision, Gnome Cunning, Artificer''s Lore, Tinker'),
('Half-Elf', NULL, 30, 0, 0, 0, 0, 0, 2, 'Darkvision, Fey Ancestry, Skill Versatility (+1 two abilities of choice applied as +1 DEX +1 CON in generator)'),
('Half-Orc', NULL, 30, 2, 0, 1, 0, 0, 0, 'Darkvision, Relentless Endurance, Savage Attacks'),
('Halfling', 'Lightfoot', 25, 0, 2, 0, 0, 0, 1, 'Lucky, Brave, Halfling Nimbleness, Naturally Stealthy'),
('Halfling', 'Stout', 25, 0, 2, 1, 0, 0, 0, 'Lucky, Brave, Halfling Nimbleness, Stout Resilience'),
('Human', NULL, 30, 1, 1, 1, 1, 1, 1, 'Extra Language'),
('Tiefling', NULL, 30, 0, 0, 0, 1, 0, 2, 'Darkvision, Hellish Resistance, Infernal Legacy');

-- ---------------------------------------------------------------------------
-- Classes (SRD core + subclass names)
-- ---------------------------------------------------------------------------
INSERT INTO classes (name, hit_die, primary_ability, saving_throws, armor_proficiencies, weapon_proficiencies, subclass_name, subclass_level, features) VALUES
('Barbarian', 12, 'Strength', 'Strength,Constitution', 'Light,Medium,Shields', 'Simple,Martial', 'Path of the Berserker', 3, 'Rage, Unarmored Defense'),
('Bard', 8, 'Charisma', 'Dexterity,Charisma', 'Light', 'Simple,Hand Crossbow,Longsword,Rapier,Shortsword', 'College of Lore', 3, 'Spellcasting, Bardic Inspiration'),
('Cleric', 8, 'Wisdom', 'Wisdom,Charisma', 'Light,Medium,Shields', 'Simple', 'Life Domain', 1, 'Spellcasting, Divine Domain'),
('Druid', 8, 'Wisdom', 'Intelligence,Wisdom', 'Light,Medium,Shields (nonmetal)', 'Clubs,Daggers,Darts,Javelins,Maces,Quarterstaffs,Scimitars,Sickles,Slings,Spears', 'Circle of the Land', 2, 'Spellcasting, Druidic'),
('Fighter', 10, 'Strength or Dexterity', 'Strength,Constitution', 'All,Shields', 'Simple,Martial', 'Champion', 3, 'Fighting Style, Second Wind'),
('Monk', 8, 'Dexterity,Wisdom', 'Strength,Dexterity', 'None', 'Simple,Shortswords', 'Way of the Open Hand', 3, 'Unarmored Defense, Martial Arts'),
('Paladin', 10, 'Strength,Charisma', 'Wisdom,Charisma', 'All,Shields', 'Simple,Martial', 'Oath of Devotion', 3, 'Divine Sense, Lay on Hands'),
-- The Ranger's two 1st-level SRD features are Favored Enemy and Natural
-- Explorer, and this column is deliberately empty rather than naming them.
-- Both are about a journey — tracking, recalling lore, difficult ground slowing
-- a march, becoming lost, foraging — and this game has none of that: travel is
-- a walk over an exit graph, and a skill check names a skill and not a subject.
-- Bending them onto the combat grid would be inventing a rule rather than
-- implementing one.
--
-- This column is what CharacterSheet::features() prints, so a name here is a
-- promise to the player. tools/test_class_features.php checks every entry in it
-- against what the engine can actually do; leaving these two in was the last
-- thing failing that check. A Ranger still gets Spellcasting at 2nd, the Hunter
-- archetype at 3rd and Extra Attack at 5th — none of which any class lists
-- here, because this column holds 1st-level features only.
('Ranger', 10, 'Dexterity,Wisdom', 'Strength,Dexterity', 'Light,Medium,Shields', 'Simple,Martial', 'Hunter', 3, ''),
('Rogue', 8, 'Dexterity', 'Dexterity,Intelligence', 'Light', 'Simple,Hand Crossbow,Longsword,Rapier,Shortsword', 'Thief', 3, 'Expertise, Sneak Attack, Thieves'' Cant'),
('Sorcerer', 6, 'Charisma', 'Constitution,Charisma', 'None', 'Daggers,Darts,Slings,Quarterstaffs,Light Crossbows', 'Draconic Bloodline', 1, 'Spellcasting, Sorcerous Origin'),
('Warlock', 8, 'Charisma', 'Wisdom,Charisma', 'Light', 'Simple', 'The Fiend', 1, 'Otherworldly Patron, Pact Magic'),
('Wizard', 6, 'Intelligence', 'Intelligence,Wisdom', 'None', 'Daggers,Darts,Slings,Quarterstaffs,Light Crossbows', 'School of Evocation', 2, 'Spellcasting, Arcane Recovery');

-- ---------------------------------------------------------------------------
-- Monsters — owned by content/monsters/, via tools/load_content.py.
--
-- The ten SRD stat blocks that were here are gone. Three of them named art
-- that does not depict them (the packs ship no undead), which is why the
-- authored bestiary is Gnoll, Wererat and Bugbear rather than Skeleton, Zombie
-- and Orc. See docs/CONTENT.md and content/README.md.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Items — owned by content/items/, via tools/load_content.py.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Spells — owned by content/spells/, via tools/load_content.py.
--
-- The six that were here predated the columns combat now resolves against —
-- resolution, save_ability, concentration, target_kind — so Sacred Flame was
-- rolled as an attack rather than the Dexterity save the SRD describes, and
-- Guidance did not exist at all, which is the one spell CheckService looks up
-- by name to offer its d4 on an ability check.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- NPCs — owned by content/npcs/ and content/dialog/, via tools/load_content.py.
--
-- Including the Job Board: the board is a UI panel over the quests table now,
-- not a person standing on a tile.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Regions and locations — owned by content/locations/, via
-- tools/load_content.py. The world is a graph of described scenes, not a tile
-- grid; nothing about it is seeded here.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Encounters and their line-ups — owned by content/encounters/, via
-- tools/load_content.py, which writes both `encounters` and `encounter_monsters`.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Quests — owned by content/quests/, via tools/load_content.py.
--
-- The five that were here described themselves with objective_type /
-- objective_data and predate the stage graph. They also had NULL quest_key, so
-- the import could not replace them and they sat on the job board next to the
-- authored act. Quests are stage graphs now; see docs/CONTENT.md.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Character art. sprite_key names an art set under assets/images/npcs/ — the
-- faceset (<key>_face.png) and dialogue bust (<key>_bust.png).
--
-- classes.sprite_key is the default for a class; characters.sprite_key is the
-- player's own pick and wins when set.
-- ---------------------------------------------------------------------------
UPDATE classes SET sprite_key = LOWER(name);

-- Pickable avatars. Labels describe the art rather than naming the class it
-- defaults to, since any character may wear any of them. This table doubles as
-- the whitelist the API validates a chosen sprite_key against.
INSERT INTO avatars (sprite_key, label, sort_order) VALUES
('fighter',   'Veteran Swordsman',    10),
('paladin',   'Horned Knight',        20),
('cleric',    'Gilded Cleric',        30),
('barbarian', 'Bare-Chested Brawler', 40),
('ranger',    'Deer-Hooded Hunter',   50),
('rogue',     'Feather-Hat Rogue',    60),
('monk',      'Elder Ascetic',        70),
('druid',     'Brown-Robed Hermit',   80),
('wizard',    'Blue Magus',           90),
('sorcerer',  'Masked Pyromancer',   100),
('warlock',   'Crimson Warlock',     110),
('bard',      'Red-Haired Minstrel', 120);
