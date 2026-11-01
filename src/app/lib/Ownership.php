<?php
/**
 * Who a character or a party belongs to — the one place that decides.
 *
 * This used to be two functions in `api/index.php`, and while the API was the
 * only door into a save that was the right place for them. It is not any more:
 * `sheet_print.php` renders a character straight from the database for the
 * printer, without going near a route, and a page that answers "is this yours?"
 * with its own copy of the rule is a page that will still be answering the old
 * way after the rule changes. So the rule moved here and the API's
 * `character_is_owned()` / `party_is_owned()` became names for it.
 *
 * Static and argument-free because the answer depends on the request rather
 * than on anything a caller could sensibly pass: `auth()` and `db()` are both
 * request singletons, and taking them as parameters would only offer callers a
 * way to ask the question about a different session than the one they are in.
 *
 * An admin passes for anybody's character, deliberately: being able to open a
 * player's save is how a broken one gets diagnosed.
 *
 * A row with a NULL `user_id` belongs to nobody and is reachable by nobody but
 * an admin. That should not happen — creation always stamps an owner — and if
 * it does, refusing is the safe direction. The `AND user_id = ?` does that for
 * free: NULL never equals anything.
 */

declare(strict_types=1);

class Ownership
{
    /**
     * Is this character yours — and still somebody at all?
     *
     * A DELETED character belongs to nobody, admins included. Every route that
     * names a character by id comes through here (the API's accessible,
     * readable and manageable gates, session/select, the inventory owner, the
     * printed sheet), so this is the one line that makes a deleted row
     * unreachable rather than a filter added to each of them and forgotten on
     * the next. Lists need nothing: a deleted character is also is_active = 0
     * and out of character_party, which every list already filters on.
     *
     * Admins are refused too, deliberately. Being able to open a broken save
     * is why they pass the ownership test; a deleted character is not a save
     * anybody is playing, and getting one back is an UPDATE, not a route.
     */
    public static function character(int $id): bool
    {
        if (self::isDeleted($id)) {
            return false;
        }
        return self::ownsRow('characters', $id);
    }

    /**
     * Has a player deleted this character?
     *
     * False on a database that has not had the migration yet, rather than an
     * unknown-column error on every request that names a character: until the
     * column exists nothing can have been deleted, so false is the true answer
     * and the site keeps working through a deploy that lands before the
     * migration does. Only character/delete itself needs the column, and says so.
     */
    public static function isDeleted(int $id): bool
    {
        if (!self::deletionTracked()) {
            return false;
        }
        $stmt = db()->prepare('SELECT 1 FROM characters WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Does `characters.deleted_at` exist here? Asked once per request.
     *
     * See migrations/2026-11-01-character-deleted-at.sql.
     */
    public static function deletionTracked(): bool
    {
        static $tracked = null;
        if ($tracked === null) {
            $stmt = db()->query("SHOW COLUMNS FROM characters LIKE 'deleted_at'");
            $tracked = $stmt !== false && $stmt->fetch() !== false;
        }
        return $tracked;
    }

    /**
     * Does this party belong to whoever is signed in?
     *
     * `character/create` takes a party id from the request body so that
     * "Recruit" can add somebody to an existing game. Unchecked, that is a way
     * to insert a character of your own into a stranger's party — and from
     * inside their party, the API's membership test would then start answering
     * yes about their characters.
     */
    public static function party(int $id): bool
    {
        return self::ownsRow('parties', $id);
    }

    /**
     * The shared half: an owned row in a table that has a `user_id`.
     *
     * The table name is interpolated, which would be alarming if it came from
     * anywhere — it comes from the two literals above and nowhere else, and the
     * id, which is the part a request can influence, is bound.
     */
    private static function ownsRow(string $table, int $id): bool
    {
        if (auth()->isAdmin()) {
            return true;
        }
        $userId = session_user_id();
        if (!$userId) {
            return false;
        }
        $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
