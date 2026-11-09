<?php
/**
 * Which screen the game client is showing — `G().state.mode` in game.js.
 *
 * Declared server-side only so it is exported with the others: the client
 * owns it. It used to be written as 'location' by game.js and 'explore' by
 * ui-combat.js for the same state, which only worked because every reader
 * compared against 'combat'.
 */

declare(strict_types=1);

final class UiMode
{
    public const LOCATION = 'location';
    public const COMBAT   = 'combat';

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'ui_mode',
            'Game client screen mode',
            'client only: G().state.mode (assets/js/game.js)',
            [
                self::LOCATION => 'Exploring: painted scene, map or first-person view of the current location.',
                self::COMBAT   => 'The battle board is up; game.js key handling stands down.',
            ],
            self::LOCATION,
            [
                [self::LOCATION, 'encounter_starts', self::COMBAT],
                [self::LOCATION, 'resume_active_fight', self::COMBAT],
                [self::COMBAT, 'leave_combat', self::LOCATION],
            ],
            [],
            'Sub-views (scene / map / first-person, sheet and camp overlays, spell aiming) are '
            . 'audited in docs/STATE_MACHINES_AUDIT.md and left implicit for now.'
        );
    }
}
