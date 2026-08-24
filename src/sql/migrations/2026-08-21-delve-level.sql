-- The floor a delve is actually standing on, kept rather than recomputed.
--
-- Until now a delve was two integers — a seed and a depth — and the level was
-- rebuilt from them whenever anything looked: the chart, the stair, the printed
-- book. That works when the generator is a deterministic function in this
-- codebase, which DungeonGen is.
--
-- It stops working the moment a floor can come from the map service. The
-- service is allowed to be absent — MapService falls back to DungeonGen so a
-- missing container is a plainer dungeon rather than a broken stair — and a
-- delve that was WRITTEN from a service floor and then REDRAWN from the
-- fallback would show the player a map of a different dungeon than the one
-- whose rooms are in the database. Every wall in the right place and every
-- room the wrong one.
--
-- So the level is stored with the delve. The seed and depth stay, because they
-- are still what a DungeonGen floor is made of and what the book prints from;
-- this is the answer for floors nobody can recompute.
--
-- Safe to run twice.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-08-21-delve-level.sql

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dungeon_delves'
               AND COLUMN_NAME = 'level_json');
SET @sql = IF(@has = 0,
    'ALTER TABLE dungeon_delves ADD COLUMN level_json LONGTEXT NULL AFTER mouth_location_id',
    'SELECT ''dungeon_delves.level_json already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SHOW COLUMNS FROM dungeon_delves;
