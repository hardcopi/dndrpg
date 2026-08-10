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
--
-- The numbers and the trait names are the SRD's, used under CC-BY. The
-- descriptions are NOT: they are written for this valley, and they are about
-- where these people stand in Rivermark rather than what they are in general.
-- That is the only kind of description that can be written here at all — the
-- SRD's own prose is a licensed document and copying it into a column would
-- put it in the database under our name — and it is also the more useful kind,
-- because a player choosing a race is choosing who they will be in this town.
--
-- One per ROW, not per race: the creator shows the row for the race and
-- subrace currently selected, so a Wood Elf who read the High Elf's paragraph
-- would be reading about somebody else. Each stands alone.
-- ---------------------------------------------------------------------------
INSERT INTO races (name, subrace, speed, str_bonus, dex_bonus, con_bonus, int_bonus, wis_bonus, cha_bonus, traits, description) VALUES
('Dragonborn', NULL, 30, 2, 0, 0, 0, 0, 1, 'Draconic Ancestry, Breath Weapon, Damage Resistance',
 'Rivermark sees perhaps two a year, both times off the caravan ground, and the town has never settled on whether to be frightened. What it has settled on is the price: a dragonborn who wants a bed at the Flagon pays what everyone pays, and a dragonborn who wants work is hired the same afternoon, because nobody on a wagon road turns down a guard that arriving raiders can see from the ridge.'),
('Dwarf', 'Hill Dwarf', 25, 0, 0, 2, 0, 1, 0, 'Darkvision, Dwarven Resilience, Stonecunning, Dwarven Toughness',
 'The hill families came down the valley with the second wave of stone-carts and never went back up: four generations of them hold the high ground north of the ford now, running sheep on land nobody else wanted and lending money at rates nobody else would dare. They are the reason the priory keeps a set of accounts it does not show visitors.'),
('Dwarf', 'Mountain Dwarf', 25, 2, 0, 2, 0, 0, 0, 'Darkvision, Dwarven Resilience, Stonecunning, Dwarven Armor Training',
 'These came for the deep seams and stayed for the argument about who owns them. A mountain dwarf in Rivermark is generally in mail, generally on somebody''s payroll, and generally of the opinion that the cut was abandoned twenty years too early — which is why more of them go down the Undervault stair each season than come back up it.'),
('Elf', 'High Elf', 30, 0, 2, 0, 1, 0, 0, 'Darkvision, Keen Senses, Fey Ancestry, Trance, Cantrip',
 'They keep a house on the good side of the market square and a correspondence with somewhere further east that nobody here has been. Rivermark finds them courteous, unhurried and impossible to hurry, and has learned that a high elf who says a thing will be seen to means it and does not mean this month.'),
('Elf', 'Wood Elf', 35, 0, 2, 0, 0, 1, 0, 'Darkvision, Keen Senses, Fey Ancestry, Trance, Fleet of Foot, Mask of the Wild',
 'The standing woods east of the river are walked by people the town almost never sees and relies on completely. Half the warden''s camps above the quarry road are theirs, the valley''s early warning about the raiders came down out of the trees before it reached the gate, and a wood elf will tell you the wood is not theirs either — it is simply where they are.'),
('Elf', 'Dark Elf', 30, 0, 2, 0, 0, 0, 1, 'Superior Darkvision, Keen Senses, Fey Ancestry, Trance, Sunlight Sensitivity, Drow Magic',
 'There are roads under this valley older than the Old City, and the people who use them come up into Undertown blinking and unimpressed. The watch counts them at the gate and writes the number down; the market takes their coin without comment; and every dark elf in Rivermark has heard the same joke about daylight often enough to have stopped hearing it.'),
('Gnome', 'Forest Gnome', 25, 0, 1, 0, 2, 0, 0, 'Darkvision, Gnome Cunning, Natural Illusionist, Speak with Small Beasts',
 'Fen-side people, hedge-side people: they hold the small holdings between Rivermark and Hollow Fen and know which of the paths across it are paths. When a holding out that way goes quiet, a forest gnome is usually the one who noticed first and the last one anybody in town thinks to ask.'),
('Gnome', 'Rock Gnome', 25, 0, 0, 1, 2, 0, 0, 'Darkvision, Gnome Cunning, Artificer''s Lore, Tinker',
 'Everything in this town that pumps, winds, lifts or counts was set up by a rock gnome and is maintained by a rock gnome grandchild who has strong views about the original. They keep benches at the caravan ground rather than shops, on the principle that the work comes off the road and the road does not stop.'),
('Half-Elf', NULL, 30, 0, 0, 0, 0, 0, 2, 'Darkvision, Fey Ancestry, Skill Versatility (+1 two abilities of choice applied as +1 DEX +1 CON in generator)',
 'A trade town that has stopped trading well runs on people who can sit between two parties and be trusted by neither more than the other, and Rivermark has made that a profession. Half the contracts on the caravan ground were witnessed by a half-elf, which is a living, and a way of never quite being from anywhere.'),
('Half-Orc', NULL, 30, 2, 0, 1, 0, 0, 0, 'Darkvision, Relentless Endurance, Savage Attacks',
 'The fighting pit off the quarry road pays well and asks nothing, which is why the town assumes that is where its half-orcs are, and why a good many of them are somewhere else out of spite. Judged before speaking and hired after, they hold more of the caravan guard work in this valley than any other people and almost none of the standing it should have bought.'),
('Halfling', 'Lightfoot', 25, 0, 2, 0, 0, 0, 1, 'Lucky, Brave, Halfling Nimbleness, Naturally Stealthy',
 'River families, boat families: they came up the water when the water still carried trade and they have not entirely accepted that it does not. The Flagon''s kitchen is theirs, three of the barges tied below the bridge are theirs, and a lightfoot can cross a crowded common room without one person turning round.'),
('Halfling', 'Stout', 25, 0, 2, 1, 0, 0, 0, 'Lucky, Brave, Halfling Nimbleness, Stout Resilience',
 'The holdings west of the water are stout country, and the west is where the emptying started. Some of those farms are dark now and some are not, and the ones that are not are held by people who have buried a neighbour, moved the livestock in with the family, and gone back out to the field in the morning.'),
('Human', NULL, 30, 1, 1, 1, 1, 1, 1, 'Extra Language',
 'Rivermark is a human town in the way a coat is a coat: it was built by them, mostly, and it is wearing out at the elbows. They put it up on the bones of the Old City without asking hard questions about what the bones were, and two hundred years later the questions are asking themselves.'),
('Tiefling', NULL, 30, 0, 0, 0, 1, 0, 2, 'Darkvision, Hellish Resistance, Infernal Legacy',
 'The priory has a position on tieflings, and the market has a different one, and the market is where people actually live. What a tiefling gets in Rivermark is service, civility, and a small pause before both — endless, unremarkable, and the reason most of them can tell you exactly how long they have been in this valley.');

-- ---------------------------------------------------------------------------
-- Races (ours)
--
-- A separate statement from the SRD block above, and that separation is the
-- point rather than tidiness: those rows are somebody else's document used
-- under CC-BY and these are not, so `source` says which is which and the two
-- never get edited as one list.
--
-- The Sarsen exist because the SRD has no big people in it. Goliath — the
-- obvious answer — is not in SRD 5.1, and rather than reach for a licence we
-- do not have, this is a quarry-cut people written for Rivermark: they belong
-- to the haul-road, the boundary stones and the Old City's masonry, which are
-- already in the world and were previously nobody's.
--
-- Two of the three traits are enforced by the engine (Rules::RACE_FEATURES);
-- Load-Bearing is deliberately not, on the same footing as the SRD races'
-- Darkvision — nothing here weighs a pack against a limit, so the sheet prints
-- the trait and does not claim to apply it.
-- ---------------------------------------------------------------------------
INSERT INTO races (name, subrace, speed, str_bonus, dex_bonus, con_bonus, int_bonus, wis_bonus, cha_bonus, traits, description, source) VALUES
('Sarsen', NULL, 30, 1, 0, 2, 0, 0, 0, 'Quarry-Built, Set Fast, Load-Bearing',
 'The cut above Rivermark was worked by Sarsen crews for four generations: grey-skinned, heavy through the shoulder, a head and a half over the men who hired them, and famously hard to hurry. The quarry stopped paying twenty years ago and most of the crews went east with the stone-carts, but not all of them — there are Sarsen families along the haul-road who have not dressed a block in a lifetime and still cut their own boundary marks by hand, because a mark somebody else cut is a mark somebody else can argue with. They keep their reckoning on their forearms in short chisel-strokes, one to a debt owed and one across it when it is paid.',
 'Rivermark');

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
