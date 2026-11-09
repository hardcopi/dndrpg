<?php
/**
 * Up, dying, stable or dead — for a player character or a companion.
 *
 * In a fight this lived as three loosely-coupled fields on a combatant
 * (`alive`, `stable`, the `unconscious` condition) plus `hp`; out of a fight
 * it lived nowhere at all. A companion who went down came out of the battle
 * on 0 hit points with no state to say so, and one who bled out was put back
 * on 1 hit point — so the camp screen sat both of them at the fire and let you
 * talk to them (playtest note, Nov 1).
 *
 * The SRD's rule is the table below: at 0 HP a character is unconscious and
 * dying; three successes (or Spare the Dying) make them stable; three failures
 * (or massive damage) kill them; any healing, or a natural 20, puts them back
 * on their feet.
 *
 * Storage is DERIVED, never a second column that could disagree with the hit
 * points: see of() and ofCharacter(). Out of a fight nobody is still rolling
 * saves — the survivors bind them up — so a character on 0 HP outside combat
 * is `stable`, and any healing that lifts current_hp above 0 is the wake-up.
 * `dead` outside a fight is party_companions.status = 'dead', which only
 * CombatEngine::COMPANION_PERMADEATH (on) writes when a companion dies.
 */

declare(strict_types=1);

final class LifeState
{
    public const CONSCIOUS = 'conscious';
    public const DYING     = 'dying';
    public const STABLE    = 'stable';
    public const DEAD      = 'dead';

    public static function machine(): StateMachine
    {
        static $m = null;
        return $m ??= new StateMachine(
            'life_state',
            'Life state (PC or companion)',
            'derived: combatant {alive, hp, stable, unconscious} in combat_sessions.state_json; '
            . 'characters.current_hp + party_companions.status out of combat',
            [
                self::CONSCIOUS => 'Above 0 HP. Can act, travel and talk.',
                self::DYING     => '0 HP, unconscious, rolling death saves (combat only).',
                self::STABLE    => '0 HP, unconscious, no longer rolling. Cannot act or talk. '
                    . 'Wakes with any healing or a long rest.',
                self::DEAD      => 'Gone. In combat: out for the fight. After it: a companion stays dead (permadeath on); a player-made character is carried off STABLE.',
            ],
            self::CONSCIOUS,
            [
                [self::CONSCIOUS, 'drop_to_zero', self::DYING],
                [self::CONSCIOUS, 'massive_damage', self::DEAD],
                [self::DYING, 'three_successes', self::STABLE],
                [self::DYING, 'stabilise', self::STABLE],
                [self::DYING, 'three_failures', self::DEAD],
                [self::DYING, 'heal', self::CONSCIOUS],
                [self::DYING, 'natural_20', self::CONSCIOUS],
                [self::STABLE, 'heal', self::CONSCIOUS],
                [self::STABLE, 'long_rest', self::CONSCIOUS],
                [self::STABLE, 'damaged', self::DYING],
                [self::STABLE, 'massive_damage', self::DEAD],
                // Leaving a fight: anyone still dying is bound up by the
                // survivors; a bled-out player-made character is carried off
                // unconscious (a companion stays dead: permadeath is on).
                [self::DYING, 'combat_ends', self::STABLE],
                [self::DEAD, 'carried_off', self::STABLE],
            ],
            [],
            'Companions: DEAD is final after the fight (CombatEngine::COMPANION_PERMADEATH '
            . 'is on; party_companions.status = dead, no revivify yet). Player-made '
            . 'characters: `carried_off` brings the bled-out back to STABLE after the fight.'
        );
    }

    /** A combatant's state, from the fields CombatEngine keeps. */
    public static function of(array $c): string
    {
        if (empty($c['alive'])) {
            return self::DEAD;
        }
        $down = (int) ($c['hp'] ?? 0) <= 0 && Conditions::has($c, 'unconscious');
        if (!$down) {
            return self::CONSCIOUS;
        }
        return !empty($c['stable']) ? self::STABLE : self::DYING;
    }

    /**
     * A character row's state outside combat.
     *
     * @param ?string $rosterStatus party_companions.status for a companion,
     *                              null for a player-made character
     */
    public static function ofCharacter(int $currentHp, ?string $rosterStatus = null): string
    {
        if ($rosterStatus === CompanionRoster::DEAD) {
            return self::DEAD;
        }
        return $currentHp > 0 ? self::CONSCIOUS : self::STABLE;
    }

    /** Can they hold a conversation? Only when awake. */
    public static function canTalk(string $state): bool
    {
        return $state === self::CONSCIOUS;
    }

    /** The line the camp screen and the dialogue refusal print. */
    public static function describe(string $state): string
    {
        return match ($state) {
            self::CONSCIOUS => 'awake',
            self::DYING     => 'unconscious and dying',
            self::STABLE    => 'unconscious — wakes with healing or a long rest',
            self::DEAD      => 'dead',
            default         => $state,
        };
    }
}
