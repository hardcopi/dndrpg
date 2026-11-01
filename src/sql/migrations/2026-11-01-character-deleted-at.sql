-- Deleting a character: a timestamp that says a player asked for them gone.
--
-- schema.sql builds a database from nothing and drops every table on the way;
-- this is the same change for a database that already has people living in it.
-- Safe to run twice: it checks for the column before it alters.
--
--   src/sql/migrate.sh --database rpg_5e --apply
--
-- A soft delete on top of retiring, not a second kind of row. A deleted
-- character is also is_active = 0 and out of character_party, so every list
-- that already hides the retired hides them with no change; what this column
-- adds is the one difference that matters to a player — retired people can be
-- brought back from the game, deleted ones cannot — and a date, so that a
-- mistake can be put right by hand:
--
--   UPDATE characters SET deleted_at = NULL WHERE id = ...;   -- then restore
--
-- The row is kept rather than dropped because quests, flags, the roll log and
-- the baked look art all reference it by id, and because "I didn't mean to"
-- is a thing players say.

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'characters'
      AND COLUMN_NAME = 'deleted_at'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE characters ADD COLUMN deleted_at DATETIME NULL AFTER is_active',
    'SELECT ''characters.deleted_at already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
