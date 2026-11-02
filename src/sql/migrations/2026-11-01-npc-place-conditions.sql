-- When somebody is where they are placed: conditions on an NPC's `place`.
--
-- schema.sql builds a database from nothing and drops every table on the way;
-- this is the same change for a database that already has people living in it.
-- Safe to run twice: it checks for the column before it alters.
--
--   src/sql/migrate.sh --database rpg_5e --apply
--
-- A place used to be unconditional: whoever the files put in the Golden
-- Flagon was sitting there from the first night. Garrow Finch, the debt
-- factor who comes for Kessa, was therefore at the next table while she told
-- the party somebody was coming for her. This holds the `place.conditions`
-- list from content/npcs/*.json — the same condition vocabulary dialogue
-- uses — and LocationEngine::npcsAt() leaves an NPC out of the scene while it
-- fails. NULL is "always here", which is every NPC but the ones that say
-- otherwise, so the column changes nothing until a content load writes it.
--
-- Run it BEFORE deploying the code that reads it: npcsAt() selects this
-- column on every look, so the game screen 500s without it.

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'npcs'
      AND COLUMN_NAME = 'place_conditions_json'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE npcs ADD COLUMN place_conditions_json TEXT NULL AFTER location_id',
    'SELECT ''npcs.place_conditions_json already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
