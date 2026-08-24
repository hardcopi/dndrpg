-- The delve, freed from the Undervault.
--
-- The generated-dungeon feature was written for one module: it began at an
-- authored location `uv_mouth`, wrote its floors into the `undervault` module,
-- and returned the party to that same mouth. All three were constants. With
-- the adventures hidden the whole feature became unreachable, which is a lot of
-- working game to leave switched off.
--
-- Two changes make it portable:
--
--   locations.has_delve      a stair goes down from here. A flag rather than a
--                            location_type because the Proving Yard is already
--                            an `arena` — it has to be both, and the schema
--                            already says this kind of thing with a flag
--                            (allow_camp, has_job_board).
--
--   dungeon_delves.mouth_location_id
--                            where this delve began, so it knows where to put
--                            the party when they climb out and which module its
--                            floors belong to. Held per delve rather than as a
--                            constant, because there is no longer one mouth.
--
-- Safe to run twice.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-08-21-delve-anywhere.sql

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations'
               AND COLUMN_NAME = 'has_delve');
SET @sql = IF(@has = 0,
    'ALTER TABLE locations ADD COLUMN has_delve TINYINT(1) NOT NULL DEFAULT 0 AFTER has_job_board',
    'SELECT ''locations.has_delve already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dungeon_delves'
               AND COLUMN_NAME = 'mouth_location_id');
SET @sql = IF(@has = 0,
    'ALTER TABLE dungeon_delves
       ADD COLUMN mouth_location_id INT UNSIGNED NULL AFTER region_id,
       ADD CONSTRAINT fk_delve_mouth FOREIGN KEY (mouth_location_id)
           REFERENCES locations (id) ON DELETE SET NULL',
    'SELECT ''dungeon_delves.mouth_location_id already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The two mouths that exist: the authored one in the Undervault, and the yard.
UPDATE locations SET has_delve = 1 WHERE location_key IN ('uv_mouth', '_freeplay_yard');

SELECT location_key, name, location_type, has_delve
  FROM locations WHERE has_delve = 1;
