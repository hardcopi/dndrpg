-- Free play: a place to stand that belongs to no adventure.
--
-- The adventures are hidden for now and characters fight random encounters
-- instead. That runs into one hard fact of the schema: a character's starting
-- location comes from their module, `regions.module_id` is NOT NULL, and
-- `combat/random` fights the party wherever they are standing. A character with
-- no adventure therefore has nowhere to be, and a fight with nowhere to be is
-- a fight that cannot start.
--
-- So this adds the smallest thing that fixes it: one module that is not an
-- adventure, holding one region, holding one arena.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-08-21-free-play.sql
--
-- Safe to run twice. Nothing here is destructive — wiping the parties' module
-- links is a separate statement kept out of this file on purpose, because it
-- is the one part that cannot be undone by running something again.
--
-- `is_active = 0` is what keeps it off the shelf. It is not an adventure and
-- must never be offered as one, whatever happens to the flag that hides the
-- real ones — a module row is how the schema says "a world", and this is the
-- world with nothing in it.

INSERT INTO modules (module_key, name, blurb, start_location_key, level_min, level_max, is_active, sort_order)
SELECT '_freeplay',
       'Free Play',
       'No adventure — a yard, and whatever walks into it.',
       '_freeplay_yard',
       1, 20, 0, 999
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) AS x WHERE EXISTS (SELECT 1 FROM modules WHERE module_key = '_freeplay'));

INSERT INTO regions (region_key, module_id, name, description, region_type, sort_order)
SELECT '_freeplay_grounds',
       (SELECT id FROM modules WHERE module_key = '_freeplay'),
       'The Proving Grounds',
       'Bare ground, a fence, and no road leading anywhere in particular.',
       'wilderness',
       0
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) AS x WHERE EXISTS (SELECT 1 FROM regions WHERE region_key = '_freeplay_grounds'));

-- location_type 'arena' is what puts the fighting pit on a location; see
-- LocationEngine. allow_camp so a party can rest between bouts without a road
-- to an inn, which there is not going to be.
INSERT INTO locations
    (location_key, region_id, name, description, first_visit_text,
     location_type, map_x, map_y, allow_camp, random_encounter_pct, sort_order)
SELECT '_freeplay_yard',
       (SELECT id FROM regions WHERE region_key = '_freeplay_grounds'),
       'The Proving Yard',
       'A ring of trodden earth inside a fence of lashed poles. Somebody has raked it flat again since the last bout. There is no gate on the far side, and no road past it — whatever you are here to fight is brought to you.',
       'The gate shuts behind you and the yard is yours. Bare earth, raked flat, and a fence too high to see over.',
       'arena',
       50.00, 50.00,
       1, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) AS x WHERE EXISTS (SELECT 1 FROM locations WHERE location_key = '_freeplay_yard'));

SELECT m.module_key, r.region_key, l.location_key, l.location_type, l.allow_camp
FROM locations l
INNER JOIN regions r ON r.id = l.region_id
INNER JOIN modules m ON m.id = r.module_id
WHERE l.location_key = '_freeplay_yard';
