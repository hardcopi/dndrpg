-- The character-creator look: which picture from the baked look library a
-- character is wearing, for the portraits, the party rail and the board.
--
-- schema.sql builds a database from nothing and drops every table on the way;
-- this is the same change for a database that already has people living in it.
-- Safe to run twice: it checks each column before it alters.
--
--   src/sql/migrate.sh --database rpg_5e --apply
--
-- Two columns rather than one, because they answer different questions.
-- look_json is the PICKS (gender, hair, outfit and their colours), which is
-- what the creator reopens on; look_key is the creator's own key for the
-- picture those picks resolve to, which is what the library is filed under.
-- The key is derivable from the picks today, and stored anyway: it is the
-- string the creator and the bake tool agree on, and a row that says which
-- picture it was baked from can be checked against the library without
-- re-running the rules that produced it.
--
-- Nothing is backfilled. A character with no look wears its sprite_key, which
-- is what every character made before today does.

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'characters'
      AND COLUMN_NAME = 'look_json'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE characters ADD COLUMN look_json TEXT NULL AFTER sidekick_json',
    'SELECT ''characters.look_json already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'characters'
      AND COLUMN_NAME = 'look_key'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE characters ADD COLUMN look_key VARCHAR(120) NULL AFTER look_json',
    'SELECT ''characters.look_key already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
