<?php
/**
 * Evaluates the condition vocabulary that authored content is written in.
 *
 * One implementation, used by dialogue variants, dialogue choices, companion
 * interjections, recruitment gates and quest stages alike. They are all asking
 * the same question — "is this true of this playthrough?" — and giving each of
 * them its own evaluator is how a game ends up with an option that appears in
 * the journal but not in the conversation.
 *
 * Pure: takes the snapshot from WorldState::context() and no database handle,
 * so it can be tested without one and cannot accidentally perform a write
 * while deciding whether to show a line of dialogue.
 *
 * The vocabulary is documented in docs/CONTENT.md and validated at import time
 * by tools/load_content.py. An unknown key here is a bug that should have been
 * caught by the loader, so it throws rather than quietly failing closed — a
 * silently false condition hides an entire branch of a quest and looks like
 * writing, not like a crash.
 */

declare(strict_types=1);

class Requirements
{
    /** Short ability names to the `characters` columns that hold them. */
    private const ABILITY_COLUMNS = [
        'str' => 'strength',
        'dex' => 'dexterity',
        'con' => 'constitution',
        'int' => 'intelligence',
        'wis' => 'wisdom',
        'cha' => 'charisma',
    ];

    /**
     * Whether every condition in a list holds. An empty or missing list passes.
     *
     * A list is AND. `any` is how content asks for OR, and nests.
     */
    public static function pass(?array $conditions, array $ctx): bool
    {
        if (!$conditions) {
            return true;
        }
        foreach ($conditions as $condition) {
            if (!is_array($condition) || !self::one($condition, $ctx)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The first variant whose conditions hold.
     *
     * Node variants are ordered, and the loader guarantees the last one is
     * unconditional, so this cannot return null for validated content. It
     * still handles the empty case rather than assuming, because dialogue
     * reaching this with nothing to say should be a caught error upstream and
     * not an undefined index down here.
     */
    public static function firstMatch(array $variants, array $ctx): ?array
    {
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (self::pass($variant['conditions'] ?? null, $ctx)) {
                return $variant;
            }
        }
        return null;
    }

    private static function one(array $c, array $ctx): bool
    {
        // Combinators first: they are the only keys that take conditions
        // rather than values, and checking them here keeps the rest flat.
        if (isset($c['not'])) {
            return !self::one((array) $c['not'], $ctx);
        }
        if (isset($c['any'])) {
            foreach ((array) $c['any'] as $sub) {
                if (self::one((array) $sub, $ctx)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($c['all'])) {
            return self::pass((array) $c['all'], $ctx);
        }

        if (isset($c['flag'])) {
            return self::flag($c, $ctx);
        }
        if (isset($c['quest'])) {
            return self::quest($c, $ctx);
        }
        if (isset($c['companion'])) {
            return self::companion($c, $ctx);
        }
        if (isset($c['origin'])) {
            return in_array(WorldState::tag((string) $c['origin']), $ctx['origins'] ?? [], true);
        }
        if (isset($c['has_item'])) {
            return isset($ctx['items'][(string) $c['has_item']]);
        }
        if (isset($c['level_at_least'])) {
            return (int) ($ctx['level'] ?? 1) >= (int) $c['level_at_least'];
        }
        if (isset($c['gold_at_least'])) {
            return (int) ($ctx['gold'] ?? 0) >= (int) $c['gold_at_least'];
        }
        if (isset($c['ability'])) {
            return self::ability($c, $ctx);
        }

        throw new InvalidArgumentException(
            'Unknown condition: ' . json_encode($c, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * A flag condition is three questions sharing one key.
     *
     * Bare is "is it set", `equals` compares as a string, `at_least` and
     * `at_most` compare as numbers. Keeping them on one key means an author
     * writes `{"flag": "x", "at_least": 3}` rather than learning a second name
     * for a counter.
     */
    private static function flag(array $c, array $ctx): bool
    {
        $key = (string) $c['flag'];
        $raw = $ctx['flags'][$key] ?? null;

        if (array_key_exists('equals', $c)) {
            return $raw !== null && (string) $raw === (string) $c['equals'];
        }
        if (array_key_exists('at_least', $c)) {
            return (int) ($raw ?? 0) >= (int) $c['at_least'];
        }
        if (array_key_exists('at_most', $c)) {
            return (int) ($raw ?? 0) <= (int) $c['at_most'];
        }
        return $raw !== null && $raw !== '' && $raw !== '0';
    }

    /**
     * Quest conditions read the party's progress, not the quest's design.
     *
     * A quest the party has never touched has no row, and every form below is
     * false for it — including `{"status": "available"}`, which asks whether
     * the party has been offered it, not whether it exists.
     */
    private static function quest(array $c, array $ctx): bool
    {
        $state = $ctx['quests'][(string) $c['quest']] ?? null;
        if ($state === null) {
            return false;
        }
        if (isset($c['status'])) {
            return $state['status'] === (string) $c['status'];
        }
        if (isset($c['stage'])) {
            return $state['stage'] === (string) $c['stage'];
        }
        if (isset($c['resolution'])) {
            return $state['resolution'] === (string) $c['resolution'];
        }
        // Bare {"quest": "k"} means the party has it in some form.
        return true;
    }

    /**
     * Companion conditions.
     *
     * `approval_at_least` and `romance_stage` are false for a companion who has
     * not been recruited, rather than reading their default of zero as a
     * neutral standing. A line gated on a companion's opinion has no business
     * appearing before they are in the party to hold one.
     */
    private static function companion(array $c, array $ctx): bool
    {
        $key = (string) $c['companion'];
        $state = $ctx['companions'][$key] ?? null;
        if ($state === null) {
            return false;
        }
        if (isset($c['status'])) {
            return $state['status'] === (string) $c['status'];
        }

        $recruited = in_array($state['status'], ['active', 'camp'], true);
        if (isset($c['approval_at_least'])) {
            return $recruited && $state['approval'] >= (int) $c['approval_at_least'];
        }
        if (isset($c['approval_at_most'])) {
            return $recruited && $state['approval'] <= (int) $c['approval_at_most'];
        }
        if (isset($c['romance_stage'])) {
            return $recruited && $state['romance_stage'] >= (int) $c['romance_stage'];
        }
        // Whether this companion's arc has been closed off — by them turning
        // the player down, or by the player committing to somebody else.
        //
        // Content needs this to hide the approach, not merely to refuse it.
        // setRomanceStage() already declines a locked arc, so nothing breaks
        // without the gate; what happens instead is that the flirtatious reply
        // stays on the menu after the choice has been made, the player picks
        // it, and the game says nothing at all. A door that is closed should
        // not still be drawn.
        if (isset($c['romance_locked'])) {
            return $recruited
                && ((bool) $state['romance_locked'] === (bool) $c['romance_locked']);
        }
        return $recruited;
    }

    /**
     * An ability score gate, read off the party leader.
     *
     * The leader rather than the best in the party: this is the character
     * doing the talking, and a door that opens because someone waiting outside
     * is strong reads as a bug.
     */
    private static function ability(array $c, array $ctx): bool
    {
        $leader = $ctx['leader'] ?? null;
        if (!$leader) {
            return false;
        }
        // Content may name an ability either way — `strength` reads better in
        // a JSON file, `str` matches the combat state — so accept both rather
        // than making the author remember which side of the fence they are on.
        $name = strtolower((string) $c['ability']);
        $long = self::ABILITY_COLUMNS[$name] ?? $name;
        $score = (int) ($leader[$long] ?? $leader[$name] ?? 0);

        if (isset($c['at_least'])) {
            return $score >= (int) $c['at_least'];
        }
        if (isset($c['at_most'])) {
            return $score <= (int) $c['at_most'];
        }
        return $score > 0;
    }
}
