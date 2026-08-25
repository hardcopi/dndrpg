-- The Proving House: free play gets the inn its map has been drawing since August.
--
-- `assets/images/maps/_freeplay_grounds.png` is a top-down plan of an INN — a
-- hearth and long tables in the middle, a shop counter and shelves top-left, a
-- spiral stair going down on the left, two bedrooms top-right, a kitchen right.
-- The database had one location in that region, still typed `arena`, still
-- describing "a ring of trodden earth inside a fence of lashed poles", with no
-- innkeeper, no shop, no exits and no bed. The art was committed and the rows
-- behind it never written, so the chart drew an inn and the game called it a yard.
--
-- WHAT THIS DOES
--
--   _freeplay_yard   becomes the common room of the house: a `building` with an
--                    inn_cost, three people in it and a shop. It KEEPS ITS KEY.
--                    That key is modules.start_location_key, it is what
--                    CharacterGenerator::havenFor() resolves to for every
--                    free-play party, and it is where every existing character
--                    is standing. Renaming the row is cosmetic; renaming the key
--                    would strand all of them.
--
--   _freeplay_cellar is new: the pit under the house. `arena`, so it offers
--                    PitEngine's three tiers, and has_delve, so the stair down
--                    starts there. Both descents are the one stair the plan
--                    draws, which is why has_delve moves off the common room —
--                    a delve mouth on the ground floor and a pit in the basement
--                    would be two ways down where the picture shows one.
--
-- WHY A MIGRATION AND NOT content/. Free play is built by
-- 2026-08-21-free-play.sql and is deliberately outside the content pipeline:
-- load_content.py's retirement pass skips `_`-prefixed keys, so a content load
-- leaves this alone. Authored under content/ it would be retired on the next
-- load, which is exactly the trap this module exists to sit outside of.
--
-- map_x/map_y are read off the plan: the common room on the open floor between
-- the hearth and the tables, the cellar node on the stairwell itself. They are
-- percentages of the chart's own 100x75 field, NOT of the image — ui-map.js
-- paints the plate with preserveAspectRatio="none", so the plate is stretched
-- onto that field and a node's position is a fraction of the field either way.
-- Which is also why the plate has to be 4:3 before it is committed: a 16:9 one
-- is not letterboxed, it is squashed by a quarter, and the plan reads squat.
--
-- Safe to run twice.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-08-25-freeplay-inn.sql

-- --- the common room ------------------------------------------------------
UPDATE locations SET
    name = 'The Proving House',
    description = 'A long room under a low ceiling, built around a stone hearth that is never quite out. Tables and benches take the middle of the floor, worn pale where elbows go. A counter runs along the far wall with shelves behind it — jars, rope, tallow, whatever anyone has needed twice. A spiral stair goes down into the dark at the near end, and nobody who takes it is asked what for.',
    first_visit_text = 'The door shuts behind you on a room that smells of woodsmoke and wet wool. Somebody at the hearth looks up, decides you are nobody, and looks back.',
    location_type = 'building',
    inn_cost = 3,
    allow_camp = 1,
    has_delve = 0,
    map_x = 63.00,
    map_y = 44.00
  WHERE location_key = '_freeplay_yard';

-- --- the pit under it -----------------------------------------------------
INSERT INTO locations
    (location_key, region_id, name, description, first_visit_text,
     location_type, map_x, map_y, allow_camp, has_delve, random_encounter_pct, sort_order)
SELECT '_freeplay_cellar',
       (SELECT id FROM regions WHERE region_key = '_freeplay_grounds'),
       'The Pit',
       'The stair comes down into a cellar that was dug wider than a cellar needs to be. Sand on the floor, raked over and over. A ring of posts and rope, benches banked up on two sides, and a low arch in the far wall where the brick stops and cut rock begins — that one goes down further than anybody has bothered to measure.',
       'The sand has been raked since the last bout. It does not stay raked.',
       'arena',
       43.00, 46.00,
       0, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) AS x
                   WHERE EXISTS (SELECT 1 FROM locations WHERE location_key = '_freeplay_cellar'));

-- Where it sits on the plan, applied separately from the INSERT above.
--
-- That insert is guarded `WHERE NOT EXISTS` so the migration is safe to run
-- twice — which also means it cannot MOVE a pit that already exists. When the
-- plate was replaced and every node had to be repositioned, the common room
-- moved (it is written by an UPDATE) and the pit silently did not. A guarded
-- insert creates; only an update changes.
UPDATE locations SET map_x = 43.00, map_y = 46.00 WHERE location_key = '_freeplay_cellar';

-- --- the stair, both ways -------------------------------------------------
INSERT INTO location_exits (from_location_id, to_location_id, label, is_hidden)
SELECT f.id, t.id, 'Down the spiral stair', 0
  FROM locations f, locations t
 WHERE f.location_key = '_freeplay_yard' AND t.location_key = '_freeplay_cellar'
   AND NOT EXISTS (SELECT 1 FROM location_exits e
                    WHERE e.from_location_id = f.id AND e.to_location_id = t.id);

INSERT INTO location_exits (from_location_id, to_location_id, label, is_hidden)
SELECT f.id, t.id, 'Up to the common room', 0
  FROM locations f, locations t
 WHERE f.location_key = '_freeplay_cellar' AND t.location_key = '_freeplay_yard'
   AND NOT EXISTS (SELECT 1 FROM location_exits e
                    WHERE e.from_location_id = f.id AND e.to_location_id = t.id);

SELECT l.location_key, l.name, l.location_type, l.inn_cost, l.allow_camp, l.has_delve,
       l.map_x, l.map_y
  FROM locations l
 INNER JOIN regions r ON r.id = l.region_id
 WHERE r.region_key = '_freeplay_grounds' ORDER BY l.sort_order;

-- --- the three people in it ----------------------------------------------
-- An innkeeper, a shopkeeper and a regular. `is_merchant` is what puts the
-- Trade door on somebody; the stock below is what is behind it.
--
-- THEY GET SPRITE KEYS OF THEIR OWN, and the reason is worth stating: they
-- briefly shared the `innkeeper`, `merchant` and `fighter` archetypes, which
-- works for display and is a trap the moment anything RENDERS to those names.
-- `mara_hearthstone` already points at `innkeeper.png`, so writing a new
-- innkeeper bust would have repainted the Golden Flagon's landlady in another
-- module. A shared key is fine to read and never safe to write.
--
-- Their art comes from tools/gen_npc_portraits.php — the 3D creator, driven in
-- a browser — because tools/gen_npc_art.py reads content/npcs/*.json and these
-- three deliberately are not there, so that a content load cannot retire them.
INSERT INTO npcs (npc_key, name, role, description, sprite_key, bust_count,
                  is_merchant, is_quest_giver, is_ambient, location_id, map_x, map_y)
SELECT * FROM (
    SELECT '_fp_hessa' AS npc_key, 'Hessa Marrow' AS name, 'Innkeeper' AS role,
           'Keeps the Proving House. Broad, unhurried, and entirely uninterested in what anybody does downstairs so long as they come back up and settle the slate. Has run the place long enough to have stopped counting who does not.' AS description,
           '_fp_hessa' AS sprite_key, 1 AS bust_count,
           0 AS is_merchant, 0 AS is_quest_giver, 0 AS is_ambient,
           (SELECT id FROM locations WHERE location_key = '_freeplay_yard') AS location_id,
           62.00 AS map_x, 52.00 AS map_y
    UNION ALL
    SELECT '_fp_odd', 'Oddvar Lint', 'Keeps the counter',
           'Runs the shelves along the back wall. Sells rope, tallow, oil and iron at a markup he will describe as fair and defend as necessary. Knows the weight of everything in the room and the price of most of it.',
           '_fp_odd', 1, 1, 0, 0,
           (SELECT id FROM locations WHERE location_key = '_freeplay_yard'),
           33.00, 27.00
    UNION ALL
    SELECT '_fp_brenna', 'Brenna Vosk', 'Fights in the cellar',
           'Sits nearest the stair with her back to the wall, which is the seat you take if you intend to go down again. Has fought in the pit long enough to have opinions about the arch at the far end of it, and will share them without being asked twice.',
           '_fp_brenna', 1, 0, 0, 0,
           (SELECT id FROM locations WHERE location_key = '_freeplay_yard'),
           40.00, 66.00
) AS want
WHERE NOT EXISTS (SELECT 1 FROM npcs n WHERE n.npc_key = want.npc_key);

-- --- what is on the shelves ----------------------------------------------
-- Ordinary goods and consumables, by RULE rather than by a hand-picked list:
-- the same line GeneralStore::sells() draws, so a content load that adds an
-- item puts it on this counter without anybody remembering to re-stock.
INSERT INTO shop_inventory (npc_key, item_id, stock, markup_pct)
SELECT '_fp_odd', i.id, 3, 115
  FROM items i
 WHERE i.item_type IN ('misc', 'potion', 'weapon', 'armor')
   AND COALESCE(i.rarity, 'common') = 'common'
   AND COALESCE(i.value_gp, 0) BETWEEN 1 AND 60
   AND NOT EXISTS (SELECT 1 FROM shop_inventory s
                    WHERE s.npc_key = '_fp_odd' AND s.item_id = i.id);
-- NO LIMIT, deliberately. With one, `NOT EXISTS` skips what is already stocked
-- and the LIMIT then tops the shelf up with the NEXT few — so the second run
-- added seven more rows and the third would have added seven again, until it
-- had quietly stocked everything anyway. A migration that lands on a different
-- shelf depending on how many times it has been run is not safe to run twice,
-- it merely fails to error. The rule stocks what the rule matches.

SELECT n.npc_key, n.name, n.role, n.is_merchant,
       (SELECT COUNT(*) FROM shop_inventory s WHERE s.npc_key = n.npc_key) AS stock
  FROM npcs n INNER JOIN locations l ON l.id = n.location_id
 WHERE l.location_key IN ('_freeplay_yard', '_freeplay_cellar');


-- --- what they say, and who they are -------------------------------------
-- UPDATEs rather than folded into the INSERT above, so this block can be
-- re-run against people who already exist.
--
-- The descriptions carry PRONOUNS deliberately. tools/gen_npc_portraits.php
-- reads which pronoun a description uses to decide whether the 3D creator
-- should put a beard on somebody; one that does not say leaves it to roll.
-- That is the only place the fact is recorded, which is why it is written
-- into the prose rather than into a column beside it that could disagree.
--
-- Oddvar's counter opens through `effects: [{open_shop: ...}]`, which is
-- what the MODERN dialogue path resolves server-side. It was authored as
-- `action: "shop"` — the legacy choice verb — which the modern renderer
-- ignores outright, so the counter opened nothing at all. A shop that
-- opened empty would have pointed at the stock; one that did nothing
-- pointed at the wrong half of the file.

UPDATE npcs SET description = 'She sits nearest the stair with her back to the wall, which is the seat you take if you intend to go down again. She has fought in the pit long enough to have opinions about the arch at the far end of it, and she will share them without being asked twice.' WHERE npc_key = '_fp_brenna';
UPDATE npcs SET dialogue_json = '{"start": "hail", "nodes": {"hail": [{"text": "She has the seat nearest the stair, back to the wall, one boot up on the bench opposite so nobody takes it.\\n\\n\\"You''re new. You''ll either go down tonight or you''ll say you will and won''t. No shame in the second one. There''s some in the first.\\"", "choices": [{"label": "Tell me about the pit.", "next": "pit"}, {"label": "And the arch past it?", "next": "arch"}, {"label": "I''ll decide myself.", "action": "close"}]}], "pit": [{"text": "\\"Three ways to take a bout, and the house sizes it to whoever walks out onto the sand. Go alone and it''s a smaller fight, not a braver one — that''s the part people get wrong and only get wrong once.\\"", "choices": [{"label": "And the arch past it?", "next": "arch"}, {"label": "Good to know.", "next": "hail"}]}], "arch": [{"text": "\\"That''s not the pit. The pit has rules and a man with a rope who stops it.\\"\\n\\nShe turns her cup around on the table.\\n\\n\\"Down there it''s different every time you go, and it doesn''t stop. I''ve been four floors. I''ve not been five.\\"", "choices": [{"label": "Why not five?", "next": "why"}, {"label": "Noted.", "next": "hail"}]}], "why": [{"text": "\\"Because on four I put my hand on a chest that wasn''t a chest and I''ve been paying a physician since.\\"\\n\\n\\"Look at things before you open them. That''s the whole of what I''ve got to teach anybody.\\"", "choices": [{"label": "I''ll remember it.", "next": "hail"}]}]}}' WHERE npc_key = '_fp_brenna';

UPDATE npcs SET description = 'She keeps the Proving House. Broad, unhurried, and entirely uninterested in what anybody does downstairs so long as they come back up and settle the slate. She has run the place long enough to have stopped counting who does not.' WHERE npc_key = '_fp_hessa';
UPDATE npcs SET dialogue_json = '{"start": "hail", "nodes": {"hail": [{"text": "She has your measure before you reach the counter — boots first, then hands, then face, in that order.\\n\\n\\"Bed''s three. That''s the bed, the fire and whatever''s in the pot, and it''s three whether you use all of it or none of it.\\"", "choices": [{"label": "What''s downstairs?", "next": "downstairs"}, {"label": "Who else is in tonight?", "next": "who"}, {"label": "Nothing for now.", "action": "close"}]}], "downstairs": [{"text": "\\"Sand and a rope ring. People fight in it, people bet on it, and I take a cut of the second one and none of the first.\\"\\n\\nShe wipes the counter down without looking at it.\\n\\n\\"There''s an arch at the far end goes down further. That one I don''t take a cut of, and I don''t ask.\\"", "choices": [{"label": "You don''t ask?", "next": "dont_ask"}, {"label": "Right.", "next": "hail"}]}], "dont_ask": [{"text": "\\"I asked, once. Fellow told me. Then he went back down and I had a room with his kit in it for a season and nobody to give it to.\\"\\n\\n\\"Now I keep the slate and the fire. It''s a better trade.\\"", "choices": [{"label": "Fair enough.", "next": "hail"}]}], "who": [{"text": "\\"Oddvar, on the counter — he''ll sell you rope you don''t need and be right about it. Brenna, by the stair, who fights. And whoever comes up.\\"\\n\\n\\"Some nights nobody comes up. That''s the trade too.\\"", "choices": [{"label": "Understood.", "next": "hail"}]}]}}' WHERE npc_key = '_fp_hessa';

UPDATE npcs SET description = 'He runs the shelves along the back wall. He sells rope, tallow, oil and iron at a markup he will describe as fair and defend as necessary, and he knows the weight of everything in the room and the price of most of it.' WHERE npc_key = '_fp_odd';
UPDATE npcs SET dialogue_json = '{"start":"hail","nodes":{"hail":[{"text":"The shelves behind him go from floor to beam: coiled rope, tallow blocks, oil in stoppered jars, iron in sizes.\\n\\n\\"Everything on that wall has been down the stair and come back,\\" he says. \\"Not always with the same person. Doesn''t make it worse rope.\\"","choices":[{"label":"Let me see what you''ve got.","effects":[{"open_shop":"_fp_odd"}]},{"label":"What sells fastest?","next":"fastest"},{"label":"Later.","action":"close"}]}],"fastest":[{"text":"\\"Rope. Every time, rope.\\"\\n\\nHe says it like a man who has stopped finding it funny.\\n\\n\\"Second is oil. Third is a thing I keep behind me and don''t put a price on until I''ve seen who''s asking.\\"","choices":[{"label":"Show me the shelves.","effects":[{"open_shop":"_fp_odd"}]},{"label":"Another time.","next":"hail"}]}]}}' WHERE npc_key = '_fp_odd';

