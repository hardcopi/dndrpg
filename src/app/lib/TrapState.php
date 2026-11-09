<?php
/**
 * A trap's lifecycle for one party: door/passage traps and trapped furnishings.
 *
 * Stored in `world_flags` under DelveEngine::trapFlag() / furnishingTrapFlag().
 * No row means hidden. Before this table, DoorEngine, FurnishingEngine and
 * LocationEngine each wrote the raw values themselves (audit #6), with the
 * same rules copied three ways.
 *
 * "Disarmed" and "went off" are both stored as `sprung`. That is the value
 * every save already holds and every reader checks (`!== 'sprung'` means still
 * armed), so the two are one state here, reached by two events: a trap that is
 * spent can never fire again, whichever way it was spent.
 */

declare(strict_types=1);

final class TrapState
{
    public const HIDDEN = 'hidden';   // no world_flags row
    public const FOUND  = 'found';
    public const SPRUNG = 'sprung';   // spent: disarmed or set off

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'trap_state',
            'Trap state (per party, per trap)',
            "world_flags[DelveEngine::trapFlag(key)] / furnishingTrapFlag(key): unset | 'found' | 'sprung'",
            [
                self::HIDDEN => 'Not yet noticed. No world_flags row.',
                self::FOUND  => 'Spotted, by a passive look on the way in or by Search. Can be disarmed.',
                self::SPRUNG => 'Spent: disarmed, or set off on somebody. Never fires again.',
            ],
            self::HIDDEN,
            [
                [self::HIDDEN, 'spot', self::FOUND],
                [self::FOUND, 'spot', self::FOUND],
                [self::HIDDEN, 'fire', self::SPRUNG],
                [self::FOUND, 'fire', self::SPRUNG],
                [self::FOUND, 'disarm', self::SPRUNG],
            ],
            [self::SPRUNG],
            "Disarming needs the trap found first (DoorEngine/FurnishingEngine::disarm refuse otherwise). "
            . "Disarmed is stored as `sprung` for back-compat with existing saves."
        );
    }

    /** The state of one trap flag, from the stored value. */
    public static function of(WorldState $world, int $partyId, string $flag): string
    {
        $v = $world->get($partyId, $flag);
        if ($v === self::SPRUNG) {
            return self::SPRUNG;
        }
        if ($v === self::FOUND) {
            return self::FOUND;
        }
        return self::HIDDEN;
    }

    public static function isSpent(WorldState $world, int $partyId, string $flag): bool
    {
        return self::of($world, $partyId, $flag) === self::SPRUNG;
    }

    /**
     * The one writer. Moves the trap along `event` if the table allows it and
     * writes the stored value; an illegal move is logged and nothing is written.
     *
     * @return bool whether the move was allowed
     */
    public static function apply(WorldState $world, int $partyId, string $flag, string $event): bool
    {
        $from = self::of($world, $partyId, $flag);
        $to = self::machine()->next($from, $event);
        if ($to === null) {
            StateMachine::logIllegal('trap_state', $from, '?', $event, ['flag' => $flag, 'party' => $partyId]);
            return false;
        }
        if ($to !== $from) {
            $world->set($partyId, $flag, $to);
        }
        return true;
    }
}
