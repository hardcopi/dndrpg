<?php
/**
 * Dice roller supporting standard 5e notation: NdX+M, NdX-M, NdX
 */

declare(strict_types=1);

class Dice
{
    /** The die every check, save and attack in 5e is resolved on. */
    private const D20 = 20;

    /**
     * Split "2d6+3" into count, sides and modifier, or throw.
     *
     * Shared by every roller here so they cannot drift apart on what counts as
     * a legal expression. A spell whose damage parsed one way on a normal hit
     * and another way on a critical would be very hard to catch by eye.
     */
    private static function parse(string $expression): array
    {
        $expression = strtolower(trim(str_replace(' ', '', $expression)));

        if ($expression === '' || $expression === '0') {
            return ['count' => 0, 'sides' => 0, 'modifier' => 0, 'expression' => $expression];
        }

        // Pure modifier
        if (preg_match('/^[+-]?\d+$/', $expression)) {
            $mod = (int) $expression;
            return ['count' => 0, 'sides' => 0, 'modifier' => $mod, 'expression' => $expression];
        }

        if (!preg_match('/^(\d+)d(\d+)([+-]\d+)?$/', $expression, $m)) {
            throw new InvalidArgumentException("Invalid dice expression: {$expression}");
        }

        return [
            'count'      => max(1, (int) $m[1]),
            'sides'      => max(1, (int) $m[2]),
            'modifier'   => isset($m[3]) ? (int) $m[3] : 0,
            'expression' => $expression,
        ];
    }

    /**
     * Roll dice expression like "2d6+3", "1d20", "4d6", "3d4+3".
     * Returns ['total' => int, 'rolls' => int[], 'modifier' => int, 'expression' => string]
     */
    public static function roll(string $expression): array
    {
        $parts = self::parse($expression);

        $rolls = [];
        for ($i = 0; $i < $parts['count']; $i++) {
            $rolls[] = random_int(1, $parts['sides']);
        }

        return [
            'total'      => array_sum($rolls) + $parts['modifier'],
            'rolls'      => $rolls,
            'modifier'   => $parts['modifier'],
            'expression' => $parts['expression'],
        ];
    }

    /** 4d6 drop lowest – classic ability score generation */
    public static function abilityScore(): array
    {
        $rolls = [random_int(1, 6), random_int(1, 6), random_int(1, 6), random_int(1, 6)];
        sort($rolls);
        $dropped = array_shift($rolls);
        return [
            'total'   => array_sum($rolls),
            'rolls'   => $rolls,
            'dropped' => $dropped,
        ];
    }

    public static function d20(int $modifier = 0): array
    {
        $roll = random_int(1, self::D20);
        return [
            'roll'     => $roll,
            'modifier' => $modifier,
            'total'    => $roll + $modifier,
            'natural'  => $roll,
            'crit'     => $roll === self::D20,
            'fumble'   => $roll === 1,
        ];
    }

    /**
     * A d20 rolled normally, with advantage or with disadvantage.
     *
     * Both dice are handed back in `rolls` so the client can show the discarded
     * one, but every judgement is made on `natural` — the die that actually
     * counted. A 20 on the die advantage threw away is not a critical hit, and
     * a 1 on the die disadvantage threw away is not a fumble.
     *
     * An unrecognised mode is treated as normal rather than rejected, because
     * this is reached from request payloads and a typo should cost the player a
     * bonus, not the whole turn.
     */
    public static function d20Mode(int $modifier = 0, string $mode = 'normal'): array
    {
        if ($mode !== 'advantage' && $mode !== 'disadvantage') {
            $mode = 'normal';
        }

        $rolls = [random_int(1, self::D20)];
        if ($mode !== 'normal') {
            $rolls[] = random_int(1, self::D20);
        }

        if ($mode === 'advantage') {
            $natural = max($rolls);
        } elseif ($mode === 'disadvantage') {
            $natural = min($rolls);
        } else {
            $natural = $rolls[0];
        }

        return [
            'rolls'    => $rolls,
            'natural'  => $natural,
            'modifier' => $modifier,
            'total'    => $natural + $modifier,
            'crit'     => $natural === self::D20,
            'fumble'   => $natural === 1,
            'mode'     => $mode,
        ];
    }

    /**
     * Damage, with a critical hit doubling the dice and nothing else.
     *
     * The SRD doubles the dice of the attack, not the ability modifier, so a
     * critical with a greatsword is 4d6+3 and never 4d6+6. The old approach of
     * rolling the expression a second time and subtracting its modifier gets
     * that right only for a positive modifier on a single-group expression; a
     * negative modifier came back doubled in the wrong direction and a `max(0,
     * ...)` guard silently swallowed the difference.
     *
     * `dice_total` is separated out because the UI shows the dice and the
     * modifier on different lines.
     */
    public static function rollDetailed(string $expr, bool $crit = false): array
    {
        $parts = self::parse($expr);
        $count = $crit ? $parts['count'] * 2 : $parts['count'];

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $parts['sides']);
        }
        $diceTotal = array_sum($rolls);

        return [
            'total'      => $diceTotal + $parts['modifier'],
            'rolls'      => $rolls,
            'dice_total' => $diceTotal,
            'modifier'   => $parts['modifier'],
            'expression' => $parts['expression'],
            'crit'       => $crit,
        ];
    }
}
