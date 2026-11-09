<?php
/**
 * A companion's standing with one party — `party_companions.status`.
 *
 * Before this the lifecycle was spread over CompanionService: each method
 * checked the status it expected with its own in_array() and wrote the next
 * one with its own UPDATE. Nothing refused the moves nobody had thought of —
 * recruit() would happily raise a companion marked `dead`. The table below is
 * now the only description of what may follow what; CompanionService asks it
 * before every write.
 */

declare(strict_types=1);

final class CompanionRoster
{
    public const UNMET   = 'unmet';
    public const MET     = 'met';
    public const ACTIVE  = 'active';
    public const CAMP    = 'camp';
    public const LEFT    = 'left';
    public const HOSTILE = 'hostile';
    public const DEAD    = 'dead';

    /** "This companion is ours" — approval, romance and camp all key off it. */
    public const RECRUITED = [self::ACTIVE, self::CAMP];

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'companion_roster',
            'Companion roster status',
            'party_companions.status (ENUM)',
            [
                self::UNMET   => 'Never met. No row yet, or the schema default.',
                self::MET     => 'Spoken to; recruit conditions may now be met.',
                self::ACTIVE  => 'Travelling with the party (in character_party).',
                self::CAMP    => 'Recruited, waiting at camp (not in character_party).',
                self::LEFT    => 'Walked out over approval; can be won back where you met them.',
                self::HOSTILE => 'Turned on the party.',
                self::DEAD    => 'Gone for good. Only reachable when CombatEngine::COMPANION_PERMADEATH is on.',
            ],
            self::UNMET,
            [
                [self::UNMET, 'meet', self::MET],
                // recruit() may run without a meet() first; a full party sends
                // the recruit to camp instead of refusing a scene already played.
                [self::UNMET, 'recruit', self::ACTIVE],
                [self::UNMET, 'recruit_full', self::CAMP],
                [self::MET, 'recruit', self::ACTIVE],
                [self::MET, 'recruit_full', self::CAMP],
                [self::LEFT, 'recruit', self::ACTIVE],
                [self::LEFT, 'recruit_full', self::CAMP],
                [self::HOSTILE, 'recruit', self::ACTIVE],
                [self::HOSTILE, 'recruit_full', self::CAMP],
                [self::ACTIVE, 'dismiss', self::CAMP],
                [self::CAMP, 'activate', self::ACTIVE],
                [self::ACTIVE, 'leave', self::LEFT],
                [self::CAMP, 'leave', self::LEFT],
                [self::UNMET, 'turn_hostile', self::HOSTILE],
                [self::MET, 'turn_hostile', self::HOSTILE],
                [self::ACTIVE, 'turn_hostile', self::HOSTILE],
                [self::CAMP, 'turn_hostile', self::HOSTILE],
                [self::LEFT, 'turn_hostile', self::HOSTILE],
                [self::ACTIVE, 'die', self::DEAD],
                [self::CAMP, 'die', self::DEAD],
            ],
            [self::DEAD],
            'A missing party_companions row reads as `unmet`. The out-of-combat '
            . 'life state (conscious / unconscious / dead) is a separate machine, '
            . 'see `life_state`.'
        );
    }

    public static function isRecruited(?string $status): bool
    {
        return in_array($status, self::RECRUITED, true);
    }
}
