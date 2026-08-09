<?php
/**
 * How much the Corse Concern has been made to notice the party.
 *
 * The valley's antagonist is an economic one — a company with a ledger, not a
 * cult with a plan — and it was entirely passive: a party could shut the
 * sluice, void a note the Concern held, read the long room and burn three years
 * of survey work, and nothing anywhere reacted. This is the thing that reacts,
 * and docs/CONCERN_PRESSURE.md is where its two settled decisions are argued.
 * Both of them are load-bearing here, so both are restated:
 *
 * **One number, valley-wide.** Not one per holding. The Concern is one
 * organisation reading one set of books, and every holding in the valley
 * reports to the same office in Greyhythe. A per-holding split would ask the
 * player to model an invisible piece of bookkeeping — the mill is annoyed with
 * you, the quarry is not — and the moment two holdings react differently to the
 * same act the fiction stops being about a company and becomes a scoreboard.
 *
 * **It never comes down.** A decaying counter would make the correct play
 * "interfere, then wait", which rewards doing nothing and turns an antagonist
 * into a chore. A debt is not forgotten, it is called in. `raise()` is the only
 * way the number moves and it refuses a non-positive delta, and a threshold
 * flag, once set, is never cleared.
 *
 * What is deliberately NOT here
 * -----------------------------
 * Any opinion about what hurt. Content states the price, in the `effects` of a
 * quest stage or a dialogue choice, as `{"pressure": 2}`; this only adds up.
 * An engine that decided for itself which acts were expensive would be an
 * engine with a plot in it, and would silently disagree with the prose the
 * moment either changed.
 *
 * And any reaction. Crossing a threshold sets a flag and does nothing else — no
 * message, no encounter, no quest. Everything the player sees is authored
 * against those flags exactly as every other conditional line is, which is what
 * keeps consequences in the prose voice instead of in a string literal in PHP.
 *
 * This is the second number the engine writes for content to read, and it is
 * modelled on the first: DelveEngine::DEPTH_FLAG, raised and never lowered for
 * the same reason. Both directions of that arrangement are unusual enough here
 * that the two flag-wiring checkers have to be told — see
 * DialogueLint::ENGINE_FLAGS and engine_flags() in tools/trace_content.py, both
 * of which name the constants below rather than copying the strings.
 *
 * Pure but for the one method that writes, so tools/test_pressure.php can prove
 * the ratchet and the threshold arithmetic without a database.
 */

declare(strict_types=1);

final class ConcernPressure
{
    /**
     * The counter itself, in `world_flags` like every other party fact.
     *
     * Content may read it — `{"flag": "concern_pressure", "at_least": 8}` works
     * and always will — but should not. Gate on the named threshold flags
     * instead: a condition written against a bare number pins the tuning of
     * THRESHOLDS into a dialogue file, and the tuning is expected to move.
     */
    public const FLAG = 'concern_pressure';

    /**
     * What the number is worth, as `total => flag`, ascending.
     *
     * Three, because each one has to be worth authoring reactions for and a
     * fourth would be a band nobody wrote anything into. They are the three
     * things an organisation like this actually does, in order:
     *
     *   `concern_noticed`   — you are a cost, and the cost has been written
     *                         down. Nobody has been told to do anything.
     *   `concern_asking`    — somebody polite is up from downriver with your
     *                         description, buying drinks and writing down the
     *                         answers.
     *   `concern_answering` — the asking is over. Doors that were open are
     *                         shut, prices move, and the people who work for
     *                         the Concern have been given your name.
     *
     * The values are reachable but not cheap: the ledger in content/quests is
     * priced so that a party that goes out of its way costs the Concern
     * `concern_noticed` inside the town tier, and only an act on the scale of
     * the Deepworks or the survey reaches the top. tools/test_pressure.php
     * asserts the corpus can still pay for all three, so re-tuning a threshold
     * past what content charges fails there rather than in a playthrough where
     * a whole band of writing silently never fires.
     */
    public const THRESHOLDS = [
        3  => 'concern_noticed',
        8  => 'concern_asking',
        15 => 'concern_answering',
    ];

    /**
     * Every flag this class writes.
     *
     * For the two flag-wiring checkers, which work by reading every `set_flag`
     * in the corpus and would otherwise report each of these as a flag nobody
     * sets — and so every scene gated on one as dead.
     *
     * @return string[]
     */
    public static function engineFlags(): array
    {
        return array_merge([self::FLAG], array_values(self::THRESHOLDS));
    }

    /**
     * The threshold flags a party at this total has earned.
     *
     * Derived rather than remembered, so the flags cannot drift from the
     * number: whatever else has happened to the row, the next raise puts the
     * set right. Pure — this is the whole of the arithmetic.
     *
     * @return string[]
     */
    public static function flagsAt(int $total): array
    {
        $out = [];
        foreach (self::THRESHOLDS as $at => $flag) {
            if ($total >= $at) {
                $out[] = $flag;
            }
        }
        return $out;
    }

    /**
     * The largest total any threshold cares about.
     *
     * Content is free to charge past it — a party can cost the Concern more
     * than the Concern has answers for — and nothing above this changes what
     * anybody says.
     */
    public static function ceiling(): int
    {
        return max(array_keys(self::THRESHOLDS));
    }

    /**
     * Charge the Concern's ledger, and set whatever that crosses.
     *
     * Returns the new total and the flags this call set — the crossings only,
     * not everything that is now true, because the caller's interest is in what
     * just changed and a caller that wants the rest can ask flagsAt().
     *
     * A delta of zero or less is refused rather than clamped. There is no such
     * thing as an act that costs the Concern nothing — content that means
     * "nothing happened" writes no effect — and a negative one is the decaying
     * counter this design rejected, arriving through the back door.
     *
     * @return array{total:int, crossed:string[]}
     */
    public static function raise(WorldState $world, int $partyId, int $by): array
    {
        if ($by < 1) {
            throw new InvalidArgumentException(
                'Concern pressure only rises; refusing a delta of ' . $by . '.'
            );
        }

        $before = $world->number($partyId, self::FLAG);
        $total  = $world->increment($partyId, self::FLAG, $by);

        $crossed = array_values(array_diff(self::flagsAt($total), self::flagsAt($before)));
        foreach ($crossed as $flag) {
            $world->set($partyId, $flag);
        }

        return ['total' => $total, 'crossed' => $crossed];
    }
}
