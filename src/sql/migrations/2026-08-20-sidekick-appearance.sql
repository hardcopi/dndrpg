-- The 3D character look, for the Unity client and the embeddable creator.
--
-- schema.sql builds a database from nothing and drops every table on the way;
-- this is the same change for a database that already has people living in it.
-- Safe to run twice: it checks before it alters.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg < src/sql/migrations/2026-08-20-sidekick-appearance.sql
--
-- Nothing is backfilled. A character with no recipe opens the creator on a
-- default rather than on an error, which is what every character made before
-- today does.

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'characters'
      AND COLUMN_NAME = 'sidekick_json'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE characters ADD COLUMN sidekick_json TEXT NULL AFTER appearance_json',
    'SELECT ''characters.sidekick_json already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
