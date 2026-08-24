<?php
/**
 * How big a fight ought to be, in 5e's own arithmetic.
 *
 * Lifted out of PitEngine, which held the only encounter-building maths in the
 * codebase and now shares it. The fighting pit asks "how hard a bout did they
 * order"; a generated dungeon asks "how hard should the thing in room seven
 * be"; both questions have the same answer and there should not be two copies
 * of it. A second copy of a tuning table is how the slot table drifted, and
 * this one is worse than that: a difficulty curve that disagrees with itself
 * is invisible until somebody dies to it.
 *
 * PURE STATIC AND WITHOUT A PDO. The party's levels are handed in as a list of
 * integers rather than looked up, so the arithmetic can be swept over invented
 * parties in a test — the same reason CombatEngine::scoreOption() takes counted
 * facts rather than a board.
 */

declare(strict_types=1);

final class EncounterBudget
{
    /**
     * XP thresholds per character, by level: easy, medium, hard, deadly.
     *
     * Straight from the 5e encounter-building tables, deadly included.
     *
     * Deadly used to be left out on the argument that nothing should build a
     * fight whose honest description is "you will probably die". That still
     * holds for anything the player did not ask for — a generated dungeon must
     * not put one behind an unmarked door — but it was never a good argument
     * about the pit, where the whole transaction is choosing your own fight off
     * a board with three prices on it. A hard match that tops out at the hard
     * threshold is the one the crowd asks for its money back on.
     *
     * So the column exists, and reaching it is opt-in: see who asks for it.
     */
    public const THRESHOLDS = [
        1  => [25, 50, 75, 100],        2  => [50, 100, 150, 200],
        3  => [75, 150, 225, 400],      4  => [125, 250, 375, 500],
        5  => [250, 500, 750, 1100],    6  => [300, 600, 900, 1400],
        7  => [350, 750, 1100, 1700],   8  => [450, 900, 1400, 2100],
        9  => [550, 1100, 1600, 2400],  10 => [600, 1200, 1900, 2800],
        11 => [800, 1600, 2400, 3600],  12 => [1000, 2000, 3000, 4500],
        13 => [1100, 2200, 3400, 5100], 14 => [1250, 2500, 3800, 5700],
        15 => [1400, 2800, 4300, 6400], 16 => [1600, 3200, 4800, 7200],
        17 => [2000, 3900, 5900, 8800], 18 => [2100, 4200, 6300, 9500],
        19 => [2400, 4900, 7300, 10900], 20 => [2800, 5700, 8500, 12700],
    ];

    /**
     * Tier name to the column of THRESHOLDS it reads.
     *
     * `deadly` is a name a caller has to type on purpose. Nothing maps to it by
     * accident: CombatEngine still sends an authored `deadly` encounter to the
     * hard column, because an author writing that word is describing a room,
     * not agreeing to a budget.
     */
    public const TIERS = ['warmup' => 0, 'fair' => 1, 'hard' => 2, 'deadly' => 3];

    /**
     * The XP a fight of this tier may spend on a party of these levels.
     *
     * Scales on headcount as well as level, because both are what makes a
     * fight easy: four level-3 characters and one level-3 character are not
     * the same problem. An empty list is read as a single first-level
     * character rather than as zero, so a caller with a broken party gets a
     * small fight instead of an impossible one.
     *
     * @param int[] $levels one per active party member
     */
    public static function forParty(array $levels, string $tier): int
    {
        $column = self::TIERS[$tier] ?? self::TIERS['fair'];
        $levels = $levels ?: [1];

        $budget = 0;
        foreach ($levels as $level) {
            $budget += self::THRESHOLDS[max(1, min(20, (int) $level))][$column];
        }
        return $budget;
    }

    /**
     * 5e's multiplier for facing several things at once.
     *
     * Applied when measuring a group against the budget, never to the XP paid
     * out: it describes how hard the fight is, not what the bodies are worth.
     */
    public static function multiplier(int $count): float
    {
        if ($count <= 1) {
            return 1.0;
        }
        if ($count === 2) {
            return 1.5;
        }
        if ($count <= 6) {
            return 2.0;
        }
        if ($count <= 10) {
            return 2.5;
        }
        return 3.0;
    }

    /**
     * How many of a creature worth `$xp` still fit inside the budget.
     *
     * The multiplier is recomputed for the whole group at each step rather than
     * applied once at the end, because it is a property of the fight and not of
     * the newcomer: adding a third wolf makes the two already there harder.
     *
     * `$rawUsed` and `$count` describe what is already in the fight, so a
     * caller can fill a group one species at a time.
     */
    public static function bestCount(
        int $xp,
        int $budget,
        int $slots,
        int $rawUsed = 0,
        int $count = 0,
        bool $atLeastOne = false
    ): int {
        if ($xp <= 0 || $slots < 1) {
            return $atLeastOne ? 1 : 0;
        }
        $best = 0;
        for ($n = 1; $n <= $slots; $n++) {
            if (($rawUsed + $xp * $n) * self::multiplier($count + $n) <= $budget) {
                $best = $n;
            }
        }
        return $best > 0 ? $best : ($atLeastOne ? 1 : 0);
    }

    /**
     * How hard a group actually came out, as a fraction of the budget.
     *
     * Over 1.0 is above the tier that was asked for. Used to report rather than
     * to decide — a generated room is allowed to be a little over, and a caller
     * that refused everything above 1.0 would reject most interesting fights.
     */
    public static function weight(int $rawXp, int $count, int $budget): float
    {
        if ($budget <= 0) {
            return 0.0;
        }
        return ($rawXp * self::multiplier($count)) / $budget;
    }
}
