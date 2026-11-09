<?php
/**
 * How a fight stands — `combat_sessions.state_json.status`, mirrored by
 * `combat_sessions.is_active` (1 exactly while the status is `active`).
 */

declare(strict_types=1);

final class CombatStatus
{
    public const ACTIVE  = 'active';
    public const VICTORY = 'victory';
    public const DEFEAT  = 'defeat';
    public const FLED    = 'fled';
    public const PARLEY  = 'parley';
    public const ENDED   = 'ended';

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'combat_status',
            'Combat encounter lifecycle',
            'combat_sessions.state_json.status + combat_sessions.is_active',
            [
                self::ACTIVE  => 'Fight in progress; turn order in state.order / turn_index.',
                self::VICTORY => 'No foe standing, a party member still up. XP, gold, loot, encounter cleared.',
                self::DEFEAT  => 'No party member standing. Everyone wakes at the module haven on 1 HP.',
                self::FLED    => 'The party broke away (DEX check vs flee DC).',
                self::PARLEY  => 'Weapons lowered; hands over to the parley dialogue node.',
                self::ENDED   => 'Retired without awards (stale engine version / reset).',
            ],
            self::ACTIVE,
            [
                [self::ACTIVE, 'last_foe_falls', self::VICTORY],
                [self::ACTIVE, 'last_ally_falls', self::DEFEAT],
                [self::ACTIVE, 'flee_succeeds', self::FLED],
                [self::ACTIVE, 'parley', self::PARLEY],
                [self::ACTIVE, 'reset', self::ENDED],
            ],
            [self::VICTORY, self::DEFEAT, self::FLED, self::PARLEY, self::ENDED],
            'Every terminal state is written by CombatEngine::finish() through this '
            . 'table and then persisted with is_active = 0 by finalizeCombat().'
        );
    }

    /**
     * Move a fight's state array to `to`, if the table allows it.
     *
     * Never throws: a refused move mid-fight leaves the state as it was and is
     * logged, which is safer than a 500 halfway through a monster's turn.
     */
    public static function transition(array $state, string $to, string $event): array
    {
        $from = (string) ($state['status'] ?? self::ACTIVE);
        if (self::machine()->allows($from, $to, $event, ['session' => $state['session_id'] ?? null])) {
            $state['status'] = $to;
        }
        return $state;
    }
}
