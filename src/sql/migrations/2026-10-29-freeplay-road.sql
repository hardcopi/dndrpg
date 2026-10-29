-- Free play: a road out of the Proving House.
--
-- New characters start in `_freeplay_yard` (the Proving House common room).
-- Its region was built in 2026-08-21-free-play.sql with "no road leading
-- anywhere in particular", and 2026-08-25-freeplay-inn.sql only added the
-- stair down to the Pit. So a free-play party could fight, rest and shop, and
-- never leave. Reported as "new groups can't get out of there".
--
-- WHAT THIS DOES
--
--   A two-way road between the Proving House and Rivermark's West Gate.
--   LocationEngine::travel() moves a free-play (or module-less) party into
--   the arrival region's module the first time it walks into a real
--   adventure, so the job board, quests and havenFor() follow it there.
--
-- The exits live here and not in content/ for the same reason the house does:
-- load_content.py leaves `_`-prefixed locations, and any exit with an `_` end,
-- alone. A content load rebuilds every other exit and keeps these.
--
-- Safe to run twice.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-10-29-freeplay-road.sql

-- --- the front door, both ways --------------------------------------------
INSERT INTO location_exits (from_location_id, to_location_id, label, is_hidden, sort_order)
SELECT f.id, t.id, 'Out the front door, the road to Rivermark', 0, 90
  FROM locations f, locations t
 WHERE f.location_key = '_freeplay_yard' AND t.location_key = 'west_gate'
   AND NOT EXISTS (SELECT 1 FROM location_exits e
                    WHERE e.from_location_id = f.id AND e.to_location_id = t.id);

INSERT INTO location_exits (from_location_id, to_location_id, label, is_hidden, sort_order)
SELECT f.id, t.id, 'The road to the Proving House', 0, 90
  FROM locations f, locations t
 WHERE f.location_key = 'west_gate' AND t.location_key = '_freeplay_yard'
   AND NOT EXISTS (SELECT 1 FROM location_exits e
                    WHERE e.from_location_id = f.id AND e.to_location_id = t.id);

-- --- say so in the room and on the region ---------------------------------
UPDATE locations
   SET description = CONCAT(description, ' The front door stands at the far end, and past it the road runs east to Rivermark.')
 WHERE location_key = '_freeplay_yard'
   AND description NOT LIKE '%front door%';

UPDATE regions
   SET description = 'A house on the road west of Rivermark, with a pit under it and a front door that opens onto the road to town.'
 WHERE region_key = '_freeplay_grounds';

SELECT f.location_key AS from_key, t.location_key AS to_key, e.label
  FROM location_exits e
 INNER JOIN locations f ON f.id = e.from_location_id
 INNER JOIN locations t ON t.id = e.to_location_id
 WHERE f.location_key = '_freeplay_yard' OR t.location_key = '_freeplay_yard';
