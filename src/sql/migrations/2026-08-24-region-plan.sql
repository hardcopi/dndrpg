-- The authored dungeon shape, for a database that already has regions in it.
--
-- `regions.plan_json` went into schema.sql when the browser dungeon editor was
-- added and got no migration, so it exists on every fresh install and on no
-- database anybody has been playing on. Nothing READS it unless a region has
-- one, which is why the gap went unnoticed for weeks — but `sql/content.sql`
-- WRITES it: the regions INSERT names every column, so a content load against
-- a live database dies at its first statement with
--
--   ERROR 1054 (42S22): Unknown column 'plan_json' in 'field list'
--
-- That is the worst possible place to die. content.sql opens by DELETEing
-- party_quests, party_quest_stages and character_known_npcs for every key the
-- files mention, and apply_content_safely.py carries those across by holding
-- them in memory and writing them back at the END. A failure in between takes
-- the snapshot down with the process and leaves the hole. It did, on
-- production, on 2026-08-24.
--
--   mysql rpg_5e < src/sql/migrations/2026-08-24-region-plan.sql
--
-- Safe to run twice: it checks before it alters. Nothing is backfilled — NULL
-- is what a town, a wilderness and a generated floor all carry.

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'regions'
      AND COLUMN_NAME = 'plan_json'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE regions ADD COLUMN plan_json TEXT NULL AFTER region_type',
    'SELECT ''regions.plan_json already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
