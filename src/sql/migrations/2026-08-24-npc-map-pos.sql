-- Where a person stands inside a place, for a database that already has npcs.
--
-- `LocationEngine::npcsAt()` selects `n.map_x, n.map_y` and runs on every look
-- at every location, so a database without these two columns cannot draw a
-- single game screen:
--
--   SQLSTATE[42S22]: Unknown column 'n.map_x' in 'field list'
--
-- They were never in schema.sql. The feature was written against a development
-- database where the columns had been added by hand, which is why it worked
-- everywhere it was tested and nowhere it was installed — a fresh
-- `docker compose down -v` would have failed exactly as production did.
-- schema.sql carries them now; this is the same change for a database that is
-- already running.
--
--   mysql rpg_5e < src/sql/migrations/2026-08-24-npc-map-pos.sql
--
-- Safe to run twice. Nothing is backfilled and NULL is the ordinary answer:
-- it means "with everyone else at the place marker", which is right for almost
-- everyone. Only a location drawn large enough to have rooms in it has
-- anywhere for a shopkeeper to stand that is not simply "here".

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'npcs'
      AND COLUMN_NAME = 'map_x'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE npcs
        ADD COLUMN map_x DECIMAL(5,2) NULL AFTER is_ambient,
        ADD COLUMN map_y DECIMAL(5,2) NULL AFTER map_x',
    'SELECT ''npcs.map_x already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
