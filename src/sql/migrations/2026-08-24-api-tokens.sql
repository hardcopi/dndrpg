-- Bearer tokens for clients that are not a browser — Unity, today.
--
-- The table went into schema.sql and nowhere else, and schema.sql only runs on
-- an empty data volume. A deploy copies files and never applies SQL, so on any
-- database that already has people in it the table simply is not there — and
-- Auth::issueToken() is called on EVERY auth/login and auth/register, not only
-- by token clients. Without this, signing in returns a 500 and nobody can get
-- into the site at all.
--
--   mysql rpg_5e < src/sql/migrations/2026-08-24-api-tokens.sql
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS, and nothing is backfilled.
-- Every existing cookie session keeps working untouched; a token is only
-- issued the next time somebody signs in.

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    state_json JSON NULL,
    label VARCHAR(60) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id, revoked_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
