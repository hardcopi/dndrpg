<?php
/**
 * A quest's lifecycle for one party — `party_quests.status`.
 *
 * The stage graph (quest_stages) is authored content and already explicit;
 * what was implicit is the status around it. start(), advance(), fail() and
 * finish() each re-derived "is this quest over?" with their own in_array().
 * They now ask this table.
 */

declare(strict_types=1);

final class QuestStatus
{
    public const AVAILABLE = 'available';
    public const ACTIVE    = 'active';
    public const COMPLETED = 'completed';
    public const FAILED    = 'failed';

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'quest_status',
            'Quest status (per party)',
            'party_quests.status (ENUM) + current_stage_id, resolution, ended_at',
            [
                self::AVAILABLE => 'Offered (or no row yet). Not started.',
                self::ACTIVE    => 'Under way; current_stage_id is where it stands.',
                self::COMPLETED => 'Ended on a success or neutral terminal stage. Rewards paid once.',
                self::FAILED    => 'Ended on a failure stage, or failed outright by content.',
            ],
            self::AVAILABLE,
            [
                [self::AVAILABLE, 'start', self::ACTIVE],
                [self::AVAILABLE, 'advance', self::ACTIVE],
                [self::ACTIVE, 'advance', self::ACTIVE],
                [self::ACTIVE, 'finish_success', self::COMPLETED],
                [self::ACTIVE, 'finish_failure', self::FAILED],
                [self::ACTIVE, 'fail', self::FAILED],
            ],
            [self::COMPLETED, self::FAILED],
            'A missing party_quests row reads as `available`. Re-offering a terminal '
            . 'quest is a note, not an error, so a second NPC offering the same job '
            . 'does not end the conversation.'
        );
    }

    public static function isOver(?string $status): bool
    {
        return $status !== null && self::machine()->isTerminal($status);
    }
}
