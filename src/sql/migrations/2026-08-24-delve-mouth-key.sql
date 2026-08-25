-- Where a delve began, recorded by KEY rather than only by id.
--
-- THE FAULT. `dungeon_delves.mouth_location_id` is a foreign key onto
-- `locations` declared ON DELETE SET NULL. Anything that rebuilds the location
-- table therefore erases, silently, where every party currently underground
-- went in — and `apply_content_safely.py`, which exists precisely to carry
-- player-side rows across a content load, does not snapshot this table at all.
-- So the ordinary act of loading content while somebody was delving nulled the
-- column, and `DelveEngine::mouthOf()` fell back to a literal `uv_mouth`. A
-- party that went down the Proving Yard's stair climbed out in the Undervault:
-- a module they never chose, reached without walking, which is exactly what the
-- module boundary rules exist to prevent.
--
-- THE FIX. Keys, not ids — the same rule `docs/CONTENT.md` states and the same
-- one `apply_content_safely.py` restores by. A location key is stable across a
-- rebuild by construction; the id is not. The id column stays, because it is a
-- real foreign key and worth having while it is valid, but it is now the second
-- place the engine looks and never the only one.
--
-- The backfill can only recover delves whose mouth id still resolves. Rows
-- already nulled cannot be recovered from here — there is nothing left in them
-- that says where the party went in — and `mouthOf()` now answers those from
-- the party's own module instead of from a constant naming somebody else's.
--
-- Safe to run twice.
--
--   docker compose exec -T db mysql -u root -p<pw> rpg_5e < src/sql/migrations/2026-08-24-delve-mouth-key.sql

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dungeon_delves'
               AND COLUMN_NAME = 'mouth_location_key');
SET @sql = IF(@has = 0,
    'ALTER TABLE dungeon_delves ADD COLUMN mouth_location_key VARCHAR(64) NULL AFTER mouth_location_id',
    'SELECT ''dungeon_delves.mouth_location_key already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Whatever is still resolvable. Idempotent: it only writes rows that have an
-- id and no key yet.
UPDATE dungeon_delves d
  INNER JOIN locations l ON l.id = d.mouth_location_id
    SET d.mouth_location_key = l.location_key
  WHERE d.mouth_location_key IS NULL;

SELECT party_id, depth, mouth_location_id, mouth_location_key FROM dungeon_delves;
