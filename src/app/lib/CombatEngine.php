<?php
/**
 * Turn-based combat on a sixteen-by-twelve grid, resolved by the SRD rules
 * layer.
 *
 * Positioning used to be one enum. Every combatant stood in one of four places
 * — party front, party back, foe front, foe back — melee reached the opposing
 * front rank and ranged reached either, and that was the whole of it. It is now
 * a floor of five-foot cells, and everything spatial defers to BattleGrid:
 *
 *   - A combatant stands at `x`,`y`. Movement is a budget of feet (`move_left`,
 *     refilled from `speed` at the start of the turn) spent walking a path the
 *     server pathfinds; terrain can cost double or refuse entry outright.
 *   - Reach is a distance. A sword reaches one cell, a halberd two, a shortbow
 *     sixteen — and past its normal range it shoots at disadvantage.
 *   - Obstacles give cover, which is a bonus to armour class and to Dexterity
 *     saves, and a wall gives total cover, which is not a bonus but the absence
 *     of a shot.
 *   - Leaving somebody's reach provokes an opportunity attack, so Disengage is
 *     a real action rather than the logged apology it used to be.
 *   - Standing opposite an ally flanks. Shooting with an enemy at your elbow is
 *     disadvantage.
 *
 * THE OLD RANK SURVIVES, DEMOTED. `characters.combat_rank`,
 * `monsters.rank_pref` and `encounter_monsters.rank_override` are all still
 * read, and they now decide one thing only: which end of your deployment zone
 * you start in. No rule consults `rank` after BattleMapGen::deploy() has run.
 *
 * THE CLIENT DOES NO GEOMETRY. It used to mirror legalTargets() in JavaScript,
 * with a comment in ui-combat.js warning that the copy would drift. Mirroring a
 * pathfinder would be that mistake at ten times the size, so the server ships a
 * derived `ui` block — reachable cells with their predecessors, threatened
 * cells, and the quality of every shot the actor has — and the board paints it.
 * The highlight and the rule are then the same code by construction.
 *
 * Everything that touches dice goes through Dice, Rules and Conditions rather
 * than being approximated here: attack rolls take their advantage state from
 * Conditions::attackContext(), criticals double dice and not the modifier,
 * damage passes through Rules::damageAfterDefenses(), and a spell that calls
 * for a save makes the target roll it.
 *
 * State lives in combat_sessions.state_json. Alongside the human-readable
 * `log` the state carries `events`: a machine-readable list of what just
 * happened, cleared at the start of every action. The client animates from
 * `events` and prints `log`, so rewording a log line cannot break an
 * animation — which is exactly what used to happen when the client picked the
 * English apart with regexes.
 */

declare(strict_types=1);

class CombatEngine
{
    private PDO $db;

    /**
     * The shape of `state_json`.
     *
     * A session written by any other version is closed rather than converted —
     * see getActiveSession(). Bump this when the blob's shape changes in a way
     * a half-finished fight cannot survive.
     */
    public const ENGINE_VERSION = 2;

    /** Melee reach when nothing says otherwise, in feet. */
    public const DEFAULT_REACH_FT = 5;

    /** Big things reach further. Anything this size or larger gets ten feet. */
    private const LARGE_SIZES = ['large', 'huge', 'gargantuan'];

    // -----------------------------------------------------------------------
    // Monster AI weights.
    //
    // Every legal option a monster has is scored and the best one taken. The
    // numbers below are the whole of its personality, so they are gathered
    // here to be tuned in one place. A plain reachable attack is worth
    // AI_BASE_ATTACK; everything else is a bonus on top of that, which keeps
    // any attack ahead of standing around.
    // -----------------------------------------------------------------------

    /** Any attack that can legally land at all. */
    private const AI_BASE_ATTACK = 100.0;
    /** Scaled by the fraction of the target's hit points already gone. */
    private const AI_WOUNDED = 45.0;
    /** Expected damage would finish the target outright. */
    private const AI_KILLING_BLOW = 60.0;
    /** The target can cast: artillery dies first. */
    private const AI_CASTER = 30.0;
    /** And a healer undoes the whole fight, so it is worth more still. */
    private const AI_HEALER = 20.0;
    /** Per point of AC below 15, so soft targets are preferred to armoured ones. */
    private const AI_SOFT_TARGET = 1.5;
    /** An ally can also reach the target and this monster has Pack Tactics. */
    private const AI_PACK = 12.0;
    /**
     * Shooting from a cell nothing threatens.
     *
     * Kept its name and its value across the move to a grid; only its meaning
     * changed, from "is in the back rank" to "is out of everybody's reach",
     * which is what the back rank was standing in for.
     */
    private const AI_SAFE_SHOT = 5.0;
    /**
     * Retreating when badly hurt, scaled by how badly.
     *
     * Judged against zero rather than against attack scores: repositioning
     * costs the move and leaves the action free, so the monster does both in
     * one turn and the two never compete.
     */
    private const AI_SELF_PRESERVE = 130.0;
    /** Below this fraction of maximum hit points a monster considers retreating. */
    private const AI_HURT_THRESHOLD = 0.35;
    /** Dodging: what a monster does when it cannot reach anything. */
    private const AI_DODGE = 10.0;

    /**
     * Dashing: what it does instead when running would actually help.
     *
     * Above AI_DODGE so that closing beats hunkering down, but only just, and
     * the distance term below is what decides it in practice: a dash that gains
     * nothing scores zero and a monster with nowhere to run still takes cover.
     * Deliberately not large. A monster that dashes across the room every round
     * is a monster that never attacks, and the failure mode of tuning this up
     * is a fight where nothing ever closes to contact.
     */
    private const AI_DASH = 14.0;

    // --- and the weights the floor added -----------------------------------

    /**
     * Per opportunity attack the walk would eat.
     *
     * Large on purpose. Taking two free hits to cross a room is almost always
     * worse than shooting from where you are, and a monster that does it looks
     * stupid in a way players notice immediately.
     */
    private const AI_PROVOKE = 55.0;
    /** Per point of cover the destination gives, so archers find the pillars. */
    private const AI_COVER = 6.0;
    /** Per enemy whose reach the destination sits inside. */
    private const AI_THREATENED = 8.0;
    /**
     * Per foot closed toward the best target when nothing can be reached at all.
     *
     * Replaces AI_HOLD_LINE, which meant "step into the empty front rank" and
     * has no counterpart now that there are no ranks to step between.
     */
    private const AI_CLOSE = 0.6;
    /** A destination that brings a caster or a healer into reach. */
    private const AI_ENGAGE_SOFT = 25.0;

    /**
     * How hard a monster is discouraged from joining a pile-on.
     *
     * Subtracted once per attack already aimed at that target this round. The
     * scoring is otherwise deterministic, so four identical rats facing an
     * identical party all computed an identical best option and all swung at
     * the same character — and AI_WOUNDED and AI_KILLING_BLOW then made them
     * focus *harder* as that character bled. Four rats at 1d4+2 is eighteen
     * damage into one nine-hit-point warlock, which is not a difficult fight,
     * it is a coin flip on who the game deletes first.
     *
     * Set below AI_BASE_ATTACK so a pile-on is discouraged, never forbidden:
     * finishing a nearly dead target is still correct play, and three wolves
     * bringing down one straggler is the whole point of wolves.
     */
    private const AI_ALREADY_TARGETED = 38.0;

    /**
     * A tiny per-actor tiebreak, so two identical creatures with identical
     * options do not make identical choices. Small enough never to outweigh a
     * real reason, large enough to break a tie.
     */
    private const AI_JITTER = 6.0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =======================================================================
    // Pure rules. No PDO, no session — these are what test_combat.php drives.
    // =======================================================================

    /**
     * Whether a combatant is still in the fight and may be attacked.
     *
     * A downed player character keeps `alive => true` because they are dying,
     * not dead, but they neither shield the rank they lie in nor draw attacks,
     * so every question about the battle line asks this rather than `alive`.
     */
    public static function isTargetable(array $c): bool
    {
        return !empty($c['alive']) && !Conditions::has($c, 'unconscious');
    }

    /** @return array<int, array> every combatant of a side still in the fight */
    public static function standing(array $combatants, string $side): array
    {
        $out = [];
        foreach ($combatants as $c) {
            if (($c['side'] ?? '') !== $side || !self::isTargetable($c)) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    /** The other side's name. */
    public static function enemySide(array $actor): string
    {
        return ($actor['side'] ?? 'party') === 'party' ? 'foe' : 'party';
    }

    /**
     * Every shot the actor has from where they stand, and how good each is.
     *
     * This is the one place targeting is decided. Attacks, spells, Pack
     * Tactics and the monster AI's option list all come through here, and they
     * all need more than a yes or no — the distance, the cover, whether the
     * shot is long, whether there is somebody at the shooter's elbow. Returning
     * bare combatants and making each caller work the rest out again is exactly
     * how weaponFor() and Rules::weaponAttack() drifted apart.
     *
     * `$range` is Rules::weaponRange() or Rules::spellRange() reshaped:
     * `['reach' => ?int, 'normal' => ?int, 'long' => ?int]`, all in feet. A
     * thrown weapon sets all three and takes whichever applies at the distance.
     *
     * A target is legal when it is standing, visible (total cover is not a
     * penalty, it is the absence of a shot) and within reach or within long
     * range. Between normal and long the shot is legal and taken at
     * disadvantage, which is the SRD rule and the reason a longbow is worth
     * carrying.
     *
     * @return array<string, array{c:array, dist:int, cover:int, long:bool,
     *                             melee:bool, crowded:bool, flank:bool}>
     */
    public static function shotsFrom(array $grid, array $combatants, array $actor, array $range): array
    {
        $terrain = $grid['terrain'] ?? [];
        $ax = (int) ($actor['x'] ?? 0);
        $ay = (int) ($actor['y'] ?? 0);
        $side = (string) ($actor['side'] ?? 'party');

        $reachFt = $range['reach'] ?? null;
        $normalFt = $range['normal'] ?? null;
        $longFt = $range['long'] ?? $normalFt;

        // Somebody at your elbow spoils a bow shot. Worked out once here rather
        // than per target, because it is a fact about the shooter.
        $crowded = false;
        if ($normalFt !== null) {
            foreach (self::standing($combatants, self::enemySide($actor)) as $foe) {
                if (BattleGrid::feet($ax, $ay, (int) ($foe['x'] ?? 0), (int) ($foe['y'] ?? 0)) <= self::DEFAULT_REACH_FT) {
                    $crowded = true;
                    break;
                }
            }
        }

        $allies = [];
        foreach (self::standing($combatants, $side) as $ally) {
            if (($ally['cid'] ?? '') !== ($actor['cid'] ?? '') && Conditions::canAct($ally)) {
                $allies[] = $ally;
            }
        }

        $out = [];
        foreach (self::standing($combatants, self::enemySide($actor)) as $target) {
            $tx = (int) ($target['x'] ?? 0);
            $ty = (int) ($target['y'] ?? 0);
            if (!BattleGrid::losClear($terrain, $ax, $ay, $tx, $ty)) {
                continue;
            }
            $dist = BattleGrid::feet($ax, $ay, $tx, $ty);

            $inMelee = $reachFt !== null && $dist <= $reachFt;
            $inShot = $normalFt !== null && $longFt !== null && $dist <= $longFt;
            if (!$inMelee && !$inShot) {
                continue;
            }

            $out[(string) $target['cid']] = [
                'c'       => $target,
                'dist'    => $dist,
                'cover'   => BattleGrid::coverBetween($terrain, $combatants, $actor, $target),
                'melee'   => $inMelee,
                'long'    => !$inMelee && $normalFt !== null && $dist > $normalFt,
                'crowded' => !$inMelee && $crowded,
                'flank'   => $inMelee && BattleGrid::flanking($terrain, $actor, $allies, $target),
            ];
        }
        return $out;
    }

    /**
     * Everyone the actor may legally point this attack at.
     *
     * The bodies only, for callers that want a list. Anything that also needs
     * to know how good the shot is should call shotsFrom() and keep the answer.
     *
     * @return array<int, array>
     */
    public static function legalTargets(array $grid, array $combatants, array $actor, array $range): array
    {
        $out = [];
        foreach (self::shotsFrom($grid, $combatants, $actor, $range) as $shot) {
            $out[] = $shot['c'];
        }
        return $out;
    }

    public static function canReach(array $grid, array $combatants, array $actor, string $targetCid, array $range): bool
    {
        return isset(self::shotsFrom($grid, $combatants, $actor, $range)[$targetCid]);
    }

    /**
     * A weapon's range as the three numbers shotsFrom() wants.
     *
     * Monster actions carry their range as a string on the action; player
     * weapons carry it in `properties`. Both arrive here already parsed by
     * weaponFor(), so this is only the shape.
     *
     * @return array{reach:?int, normal:?int, long:?int}
     */
    public static function rangeOf(array $weapon): array
    {
        $r = $weapon['range'] ?? null;
        if (is_array($r)) {
            return [
                'reach'  => isset($r['reach']) ? (int) $r['reach'] : null,
                'normal' => isset($r['normal']) ? (int) $r['normal'] : null,
                'long'   => isset($r['long']) ? (int) $r['long'] : null,
            ];
        }
        return ['reach' => self::DEFAULT_REACH_FT, 'normal' => null, 'long' => null];
    }

    /** Allies the actor may point a helpful effect at, the fallen included. */
    public static function allyTargets(array $combatants, array $actor): array
    {
        $out = [];
        foreach ($combatants as $c) {
            if (($c['side'] ?? '') === ($actor['side'] ?? '') && !empty($c['alive'])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Damage after a saving throw.
     *
     * `half` rounds down, as the SRD says; `negate` leaves nothing.
     */
    public static function saveDamage(int $damage, bool $passed, string $effect): int
    {
        if (!$passed) {
            return max(0, $damage);
        }
        return $effect === 'negate' ? 0 : intdiv(max(0, $damage), 2);
    }

    /** Constitution DC to keep concentration after taking a hit. */
    public static function concentrationDc(int $damage): int
    {
        return max(10, (int) floor(max(0, $damage) / 2));
    }

    /**
     * Slots of one level still unspent.
     *
     * `$expended` is the decoded `characters.spell_slots_json`, keyed by slot
     * level. Cantrips are level 0 and always free, so they answer as though a
     * slot were always available rather than making every caller special-case
     * them.
     */
    public static function slotsAvailable(array $expended, string $class, int $level, int $spellLevel): int
    {
        if ($spellLevel <= 0) {
            return 1;
        }
        $table = Rules::slotTable($class, $level);
        // Pact Magic casts a lower-level spell with a pact slot; every other
        // class spends the spell's own level. spendSlot() asks the same
        // question, and the two must not disagree.
        $spellLevel = Rules::slotLevelSpent($class, $level, $spellLevel);
        $have = (int) ($table[$spellLevel] ?? 0);
        $used = (int) ($expended[(string) $spellLevel] ?? $expended[$spellLevel] ?? 0);
        return max(0, $have - $used);
    }

    /** The party's DC to break off a fight, from the toughest thing in it. */
    public static function fleeDc(float $highestCr): int
    {
        return max(10, 10 + (int) floor($highestCr));
    }

    /**
     * How one d20 should be rolled, given the conditions and anything else.
     *
     * Conditions::attackContext() has already cancelled advantage against
     * disadvantage, so a context that came back with neither but says so in
     * its reasons is re-inflated to both before the extra sources are folded
     * in — otherwise the Help action would hand advantage to an attacker whose
     * own blindness should still have cancelled it.
     */
    public static function rollMode(array $ctx, bool $extraAdvantage = false, bool $extraDisadvantage = false): string
    {
        $cancelled = in_array('advantage and disadvantage cancel', $ctx['reasons'] ?? [], true);
        $adv = !empty($ctx['advantage']) || $cancelled || $extraAdvantage;
        $dis = !empty($ctx['disadvantage']) || $cancelled || $extraDisadvantage;

        if ($adv && $dis) {
            return 'normal';
        }
        if ($adv) {
            return 'advantage';
        }
        return $dis ? 'disadvantage' : 'normal';
    }

    /** The mean of a dice expression, for "would this finish them?" questions. */
    public static function expectedDamage(string $expr): float
    {
        $expr = strtolower(str_replace(' ', '', trim($expr)));
        if (!preg_match('/^(\d+)d(\d+)([+-]\d+)?$/', $expr, $m)) {
            return is_numeric($expr) ? (float) $expr : 0.0;
        }
        return (int) $m[1] * ((int) $m[2] + 1) / 2 + (isset($m[3]) ? (int) $m[3] : 0);
    }

    public static function hasTrait(array $combatant, string $needle): bool
    {
        $traits = $combatant['traits'] ?? [];
        if (!is_array($traits)) {
            $traits = json_decode((string) $traits, true) ?: [];
        }
        $needle = strtolower($needle);
        foreach ($traits as $name => $text) {
            if (str_contains(strtolower((string) $name), $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a class can spend its bonus action on.
     *
     * The bonus action existed as a pip on the action bar and a flag in the
     * state and was spent by nothing at all, which made every character's turn
     * the same turn regardless of class. These are the SRD level-1 features
     * that survive the loss of a movement grid: Second Wind and Rage need no
     * geometry, and Cunning Action's Disengage is meaningful here because
     * leaving the front rank is the thing it protects.
     *
     * Keyed by class because that is what the character sheet already knows;
     * a fuller implementation would read a feature list off the class row.
     */
    public const BONUS_ACTIONS = [
        'Fighter'   => [
            'key' => 'second_wind',
            'label' => 'Second Wind',
            'hint' => 'Regain 1d10 + level hit points, once per rest.',
        ],
        'Barbarian' => [
            'key' => 'rage',
            'label' => 'Rage',
            'hint' => 'Resist physical damage and hit harder while it lasts.',
        ],
        // The one entry with a choice in it. `options` is absent from the other
        // two and everything that reads this treats that as "the feature is the
        // whole of it", so adding a choice to Rage would be adding an array
        // here and nothing else.
        //
        // It used to be Disengage and only Disengage, because on the rank board
        // "slip out of reach" was the only half of the SRD feature that could
        // mean anything — there was no distance for a bonus-action Dash to
        // cover. On a floor the Dash is the more useful of the two most turns,
        // and it is most of what makes a rogue read as a rogue in a fight.
        'Rogue'     => [
            'key' => 'cunning_action',
            'label' => 'Cunning Action',
            'hint' => 'Dash or Disengage as a bonus action.',
            'options' => [
                ['key' => 'dash', 'label' => 'Dash',
                 'hint' => 'Your speed again, as a bonus action.'],
                ['key' => 'disengage', 'label' => 'Disengage',
                 'hint' => 'Move without drawing an opportunity attack.'],
            ],
        ],
    ];

    /** The sub-choice a bonus action falls back on when the client names none. */
    private const BONUS_OPTION_DEFAULT = 'disengage';

    /**
     * Features an hour's breather gives back.
     *
     * usesPerRest() has carried the note for as long as it has existed: "the
     * SRD gives this back on a short rest as well, and there is no short rest
     * here, so the long rest is the whole of it". There is one now, so Second
     * Wind comes back from it — and a Fighter who has spent theirs is no longer
     * out of it until they can find a bed.
     *
     * Rage is not here and should not be: the SRD returns it on a long rest
     * only, and this list is the difference between the two rests rather than a
     * list of everything that recharges.
     */
    public const SHORT_REST_FEATURES = ['second_wind', 'action_surge', 'channel_divinity'];

    /**
     * How hard it is to notice an ambush before it lands.
     *
     * Ten plus the hardest thing in it, held between 10 and 16. The floor keeps
     * a cellar of rats from surprising anybody who is awake; the ceiling keeps
     * the worst thing in the game from being unnoticeable by a party with a
     * scout in it. This is a made-up number and the only one in the surprise
     * rule — startEncounter() explains why the SRD's own comparison, Stealth
     * against passive Perception, cannot be made here.
     */
    public static function surpriseDc(float $highestCr): int
    {
        return max(10, min(16, 10 + (int) round($highestCr)));
    }

    /** The physical damage a rage turns aside. */
    private const RAGE_RESISTANCES = ['bludgeoning', 'piercing', 'slashing'];

    /** Ten rounds, which is the SRD's one minute. */
    private const RAGE_ROUNDS = 10;

    /**
     * The damage a rage adds to a hit.
     *
     * Flat rather than a level table because the SRD's first step up is at
     * level 9 and Rules::MAX_LEVEL is 6 — a table here would be five rows of
     * the same number and one that can never be reached.
     */
    private const RAGE_DAMAGE = 2;

    /**
     * How many times a long rest allows a bonus-action feature.
     *
     * Null means the feature costs nothing but the bonus action itself, so a
     * rest has nothing to give back.
     *
     * This used to be "once per fight", tracked on the combatant — which meant
     * every new encounter restored it and a Barbarian's rages were limited only
     * by how many fights they were willing to pick. The count now lives in the
     * party's world flags and comes back through
     * CheckService::resetOnLongRest(), the same road Bardic Inspiration and the
     * Lucky feat's points already travel.
     */
    private static function usesPerRest(string $featureKey, int $level): ?int
    {
        return match ($featureKey) {
            // SRD: two at 1st, three at 3rd, four at 6th. Rules::MAX_LEVEL is 6,
            // so the table stops where the game does.
            'rage' => $level >= 6 ? 4 : ($level >= 3 ? 3 : 2),
            // The SRD gives this back on a short rest as well, and there is no
            // short rest here, so the long rest is the whole of it.
            'second_wind' => 1,
            default => null,
        };
    }

    /** The world flag counting one character's spent uses of one feature. */
    public static function bonusFeatureFlag(string $featureKey, int $characterId): string
    {
        return CheckService::FLAG_BONUS_FEATURE_USED . '_' . $featureKey . '_' . $characterId;
    }

    /**
     * What each party member has left of their bonus-action feature.
     *
     * Read once when the fight starts, in the same crossing as `feats`, and
     * kept current on the combatant as it is spent. The board needs it to grey
     * the button out: a button offered and then refused is worse than a button
     * that says why it is closed.
     *
     * @param  int[] $characterIds
     * @return array<int, int|null> character id => uses left, null for unlimited
     */
    private function bonusUsesLeft(array $party): array
    {
        $out = [];
        foreach ($party as $pc) {
            $characterId = (int) $pc['id'];
            $feature = self::BONUS_ACTIONS[$pc['class'] ?? ''] ?? null;
            $allowance = $feature
                ? self::usesPerRest($feature['key'], (int) ($pc['level'] ?? 1))
                : null;
            if (!$feature || $allowance === null) {
                $out[$characterId] = null;
                continue;
            }
            $partyId = WorldState::partyIdFor($this->db, $characterId);
            $used = $partyId
                ? (new WorldState($this->db))->number(
                    $partyId,
                    self::bonusFeatureFlag($feature['key'], $characterId)
                )
                : 0;
            $out[$characterId] = max(0, $allowance - $used);
        }
        return $out;
    }

    /**
     * What each half-orc in the party has left of Relentless Endurance.
     *
     * The same flag family and the same crossing as bonusUsesLeft(): spent
     * uses live in a world flag, so they survive between fights and come back
     * on a long rest — and only a long rest, which is what the SRD says.
     *
     * @return array<int, int|null> character id => uses left, null for a
     *                              character who does not have the trait
     */
    /**
     * What a character's worn magic adds up to, for the stamp.
     *
     * Combat state never reads the database after startEncounter, so the
     * ring's numbers have to make the crossing beside `feats` — the same rule,
     * stated at that line.
     *
     * @return array{save_bonus:int, resistances:string[]}
     */
    private function equipmentSummary(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.properties FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ? AND ci.is_equipped = 1'
        );
        $stmt->execute([$characterId]);

        $out = ['save_bonus' => 0, 'resistances' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $props = json_decode((string) $json, true) ?: [];
            $out['save_bonus'] += (int) ($props['save_bonus'] ?? 0);
            foreach ((array) ($props['resistance'] ?? []) as $type) {
                if (is_string($type) && !in_array($type, $out['resistances'], true)) {
                    $out['resistances'][] = strtolower($type);
                }
            }
        }
        return $out;
    }

    private function relentlessUsesLeft(array $party): array
    {
        $out = [];
        foreach ($party as $pc) {
            $characterId = (int) $pc['id'];
            $features = Rules::raceFeatures(
                (string) ($pc['race'] ?? ''),
                isset($pc['subrace']) ? (string) $pc['subrace'] : null
            );
            if (!in_array('relentless_endurance', $features, true)) {
                $out[$characterId] = null;
                continue;
            }
            $partyId = WorldState::partyIdFor($this->db, $characterId);
            $used = $partyId
                ? (new WorldState($this->db))->number(
                    $partyId,
                    self::bonusFeatureFlag('relentless_endurance', $characterId)
                )
                : 0;
            $out[$characterId] = max(0, 1 - $used);
        }
        return $out;
    }

    /**
     * Spend the bonus action.
     *
     * A feature with a per-rest allowance (usesPerRest) is limited by that
     * allowance and nothing else, so a Barbarian whose rage runs out mid-fight
     * may spend another if they still have one. A feature with no allowance —
     * Cunning Action — keeps the old once-per-fight guard, which is stricter
     * than the SRD's every-turn wording; that is a known wrong and a separate
     * change, not something to relax by accident here.
     */
    private function doBonusAction(array $state, string $which, string $option = ''): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || $actor['type'] !== 'pc') {
            throw new InvalidArgumentException('Only a party member has a bonus action.');
        }
        if (!empty($actor['bonus_used'])) {
            throw new InvalidArgumentException('Bonus action already spent this turn.');
        }
        $available = self::BONUS_ACTIONS[$actor['class'] ?? ''] ?? null;
        if (!$available || ($which !== '' && $which !== $available['key'])) {
            throw new InvalidArgumentException('No bonus action available to that character.');
        }

        // A feature that offers a choice is refused an option it does not
        // offer, rather than quietly doing the other one. An empty option is
        // not a bad one: it is a client that predates the choice, and it gets
        // the behaviour that client was written against.
        $offered = array_column((array) ($available['options'] ?? []), 'key');
        if ($offered !== []) {
            $option = $option === '' ? self::BONUS_OPTION_DEFAULT : $option;
            if (!in_array($option, $offered, true)) {
                throw new InvalidArgumentException(
                    $available['label'] . ' cannot be spent that way.'
                );
            }
        }

        $characterId = (int) ($actor['character_id'] ?? 0);
        $allowance = self::usesPerRest($available['key'], (int) ($actor['level'] ?? 1));
        $spent = (array) ($actor['bonus_features_used'] ?? []);

        if ($allowance === null) {
            if (in_array($available['key'], $spent, true)) {
                throw new InvalidArgumentException(
                    $available['label'] . ' has already been used in this fight.'
                );
            }
        } else {
            if ((int) ($actor['bonus_uses_left'] ?? 0) < 1) {
                throw new InvalidArgumentException(
                    $available['label'] . ' is spent — that needs a long rest.'
                );
            }
            // Raging while already raging refreshes nothing and would waste the
            // use. The SRD says as much; here it also protects the resistance
            // snapshot the boon is holding.
            if ($available['key'] === 'rage') {
                foreach ((array) ($actor['boons'] ?? []) as $held) {
                    if (($held['key'] ?? '') === 'rage') {
                        throw new InvalidArgumentException(
                            $actor['name'] . ' is already raging.'
                        );
                    }
                }
            }
        }
        $spent[] = $available['key'];

        $changes = ['bonus_used' => true, 'bonus_features_used' => $spent];
        if ($allowance !== null) {
            $changes['bonus_uses_left'] = max(0, (int) ($actor['bonus_uses_left'] ?? 0) - 1);
            $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
            if ($partyId) {
                (new WorldState($this->db))->increment(
                    $partyId,
                    self::bonusFeatureFlag($available['key'], $characterId)
                );
            }
        }
        $state = $this->updateCombatant($state, $actor['cid'], $changes);

        switch ($available['key']) {
            case 'second_wind':
                $roll = self::healingRoll($actor, '1d10+' . max(1, (int) ($actor['level'] ?? 1)));
                $state['log'][] = "{$actor['name']} catches their breath — {$roll['total']} hit points.";
                return $this->heal($state, (string) $actor['cid'], (int) $roll['total']);

            case 'rage':
                // A boon, not a bare flag. addBoon() is what owns a timed
                // effect here, and it carries the pip on the card, the round
                // counter, the tooltip and the does-not-stack-with-itself rule.
                // This used to write `raging => true` and emit a condition
                // event for a key no catalogue holds: nothing read the field,
                // nothing could draw a pip for it, and the player got one frame
                // of a floater over a card that then showed nothing at all.
                // The snapshot survives a second rage. addBoon() replaces a
                // boon of the same key, so a rage granted while one was already
                // running would otherwise snapshot the list its predecessor had
                // already widened and bake the three resistances in for good.
                // The once-per-fight guard makes that unreachable today; it
                // should not become reachable by relaxing the guard.
                $before = null;
                foreach ((array) ($actor['boons'] ?? []) as $held) {
                    if (($held['key'] ?? '') === 'rage' && isset($held['restore_resistances'])) {
                        $before = (array) $held['restore_resistances'];
                        break;
                    }
                }
                $before ??= array_values(array_unique(array_map(
                    'strval',
                    (array) ($actor['resistances'] ?? [])
                )));
                // The resistances go into `resistances` rather than a
                // `resist_physical` flag, because Rules::damageAfterDefenses
                // reads that list and knows nothing about rage — a bespoke flag
                // would have looked right here and reduced no damage at all.
                // Boons have no resistance field of their own, so the pre-rage
                // list rides on the boon and tickBoons() puts it back when the
                // rage runs out.
                $state = $this->updateCombatant($state, $actor['cid'], [
                    'resistances' => array_values(array_unique(array_merge(
                        $before,
                        self::RAGE_RESISTANCES
                    ))),
                ]);
                $state['log'][] = "{$actor['name']} roars, and stops feeling most of it.";
                return $this->addBoon($state, (string) $actor['cid'], [
                    'key'                 => 'rage',
                    'label'               => 'Rage',
                    'rounds'              => self::RAGE_ROUNDS,
                    // The other half of the hint. Nothing applied a rage damage
                    // bonus before this; boonBonus() spends the field on every
                    // hit, which is where the SRD puts it.
                    'damage_bonus_dice'   => (string) self::RAGE_DAMAGE,
                    'restore_resistances' => $before,
                ]);

            case 'cunning_action':
            default:
                // Whichever half of it the player asked for. Disengage used to
                // also shove the Rogue into the back rank, because "slip out of
                // reach" had to mean something and a rank was the only place to
                // put them. There is a floor now, so the player does the moving
                // — and Dash is what pays for the moving.
                if ($option === 'dash') {
                    $gain = max(0, (int) ($actor['speed'] ?? 30));
                    $state['log'][] = "{$actor['name']} is away before anybody moves — {$gain} more feet.";
                    return $this->updateCombatant($state, $actor['cid'], [
                        'move_left' => max(0, (int) ($actor['move_left'] ?? 0)) + $gain,
                    ]);
                }
                $state['log'][] = "{$actor['name']} slips free — they can move without drawing a swing.";
                return $this->updateCombatant($state, $actor['cid'], ['disengaging' => true]);
        }
    }

    /**
     * A stable pseudo-random nudge for one attacker/target pair.
     *
     * Deterministic on the two cids rather than drawn from random_int, because
     * scoreOption is called once per candidate and then the winner is acted on
     * — a fresh draw each call would mean the option that scored highest is not
     * necessarily the option that gets taken, and identical fights would be
     * unreplayable when debugging one.
     */
    private static function jitter(string $actorCid, string $targetCid): float
    {
        $h = crc32($actorCid . '|' . $targetCid);
        return (($h % 1000) / 1000.0) * self::AI_JITTER;
    }

    /**
     * What one option is worth to a monster. Higher wins; zero means never.
     *
     * PURE, AND IT MUST STAY PURE. Not one BattleGrid call belongs in here.
     * Every spatial fact an option depends on — how many opportunity attacks
     * the walk costs, how much cover the destination gives, how many enemies
     * threaten it — is worked out by runMonsterAI() and handed in already
     * counted. That is what lets tools/test_combat.php hand this function
     * invented option arrays and check the personality without standing up a
     * board, and it is the reason the AI's tuning has stayed testable through
     * two rewrites of what a position even is.
     *
     * `$option` is one of:
     *   ['kind'=>'attack', 'target'=>combatant, 'expected_damage'=>float,
     *    'pack'=>bool, 'already_targeted'=>int, 'shot'=>array, 'safe'=>bool]
     *   ['kind'=>'move_attack', …the attack fields…, 'move_cost'=>int,
     *    'dest'=>[x,y], 'provokes'=>int, 'dest_cover'=>int, 'dest_threat'=>int]
     *   ['kind'=>'reposition', 'dest'=>[x,y], 'provokes'=>int,
     *    'dest_cover'=>int, 'dest_threat'=>int, 'closes_ft'=>int,
     *    'engages_soft'=>bool]
     *   ['kind'=>'dodge']
     *   ['kind'=>'dash', 'closes_ft'=>int, 'provokes'=>int]
     */
    public static function scoreOption(array $actor, array $option, array $combatants = []): float
    {
        $kind = (string) ($option['kind'] ?? '');

        if ($kind === 'attack' || $kind === 'move_attack') {
            $t = $option['target'] ?? [];
            $maxHp = max(1, (int) ($t['max_hp'] ?? 1));
            $hp = max(0, (int) ($t['hp'] ?? 0));

            $score = self::AI_BASE_ATTACK;
            $score += self::AI_WOUNDED * (1.0 - $hp / $maxHp);
            if ((float) ($option['expected_damage'] ?? 0.0) >= $hp) {
                $score += self::AI_KILLING_BLOW;
            }
            if (!empty($t['is_caster'])) {
                $score += self::AI_CASTER;
            }
            if (!empty($t['is_healer'])) {
                $score += self::AI_HEALER;
            }
            $score += self::AI_SOFT_TARGET * max(0, 15 - self::effectiveAc($t));
            if (!empty($option['pack'])) {
                $score += self::AI_PACK;
            }

            // The shot's own quality. Cover on the target and a long-range
            // penalty both make a hit less likely, so a monster with a choice
            // should prefer the clean shot even at a slightly worse target.
            $shot = (array) ($option['shot'] ?? []);
            $score -= self::AI_COVER * (int) ($shot['cover'] ?? 0);
            if (!empty($shot['long'])) {
                $score -= self::AI_COVER;
            }
            if (!empty($shot['crowded'])) {
                $score -= self::AI_COVER;
            }
            if (!empty($shot['flank'])) {
                $score += self::AI_PACK;
            }

            if ($kind === 'attack') {
                if (!empty($option['safe'])) {
                    $score += self::AI_SAFE_SHOT;
                }
            } else {
                // Walking there has a price: free hits on the way, whatever the
                // destination is exposed to, and the cover it does or does not
                // offer once you arrive.
                $score -= self::AI_PROVOKE * (int) ($option['provokes'] ?? 0);
                $score -= self::AI_THREATENED * (int) ($option['dest_threat'] ?? 0);
                $score += self::AI_COVER * (int) ($option['dest_cover'] ?? 0);
                // All else equal, walk less. Small enough never to outweigh a
                // better target, large enough to stop pointless wandering.
                $score -= (int) ($option['move_cost'] ?? 0) * 0.05;
            }

            // Spread the damage. Without this every identical monster in an
            // encounter picks the same target and the party loses one member
            // per round to a coordinated swarm of animals.
            $score -= self::AI_ALREADY_TARGETED * (int) ($option['already_targeted'] ?? 0);

            // Break ties between identical creatures. Seeded from the actor's
            // own id so one monster is consistent within its turn — a random
            // number drawn per call would make the chosen option disagree with
            // the score that chose it.
            $score += self::jitter((string) ($actor['cid'] ?? ''), (string) ($t['cid'] ?? ''));

            return $score;
        }

        if ($kind === 'reposition') {
            $frac = max(0, (int) ($actor['hp'] ?? 0)) / max(1, (int) ($actor['max_hp'] ?? 1));

            $score = 0.0;
            // Badly hurt and able to shoot: get out of reach and keep fighting.
            // A melee-only brute retreating is furniture, exactly as it was
            // when this meant falling back a rank.
            if ($frac <= self::AI_HURT_THRESHOLD
                && !empty($actor['has_ranged'])
                && (int) ($option['dest_threat'] ?? 0) === 0) {
                $score += self::AI_SELF_PRESERVE * (1.0 - $frac);
            }
            // Otherwise the only reason to walk is to get somewhere useful.
            $score += self::AI_CLOSE * max(0, (int) ($option['closes_ft'] ?? 0));
            if (!empty($option['engages_soft'])) {
                $score += self::AI_ENGAGE_SOFT;
            }
            $score += self::AI_COVER * (int) ($option['dest_cover'] ?? 0);
            $score -= self::AI_PROVOKE * (int) ($option['provokes'] ?? 0);
            $score -= self::AI_THREATENED * (int) ($option['dest_threat'] ?? 0);

            return max(0.0, $score);
        }

        // Running, when the alternative is standing in an empty room. Scored
        // against the ground it would actually gain rather than as a flat
        // preference: a dash that closes nothing is worth nothing, which is
        // what keeps a monster that has already arrived from sprinting on past.
        if ($kind === 'dash') {
            $closes = (int) ($option['closes_ft'] ?? 0);
            if ($closes <= 0) {
                return 0.0;
            }
            $score = self::AI_DASH + self::AI_CLOSE * $closes;
            // The same price the walk itself pays. Sprinting through somebody's
            // reach is still sprinting through their reach.
            $score -= self::AI_PROVOKE * (int) ($option['provokes'] ?? 0);
            return max(0.0, $score);
        }

        return $kind === 'dodge' ? self::AI_DODGE : 0.0;
    }

    // =======================================================================
    // Sessions
    // =======================================================================

    /**
     * The fight this character is in the middle of, if this engine can resolve it.
     *
     * A session written by an older engine is closed here rather than converted.
     * The rank engine's blob holds a `rank` and no coordinates, and there is no
     * honest way to invent the coordinates — a guess puts somebody inside a
     * wall, and the fight then fails in a way that reads as a targeting bug
     * rather than an upgrade. Nothing of value is lost: takeDamage() writes
     * `characters.current_hp` on every blow, so the party's health has never
     * lived only in this blob. No awards are handed out, so finalizeCombat() is
     * deliberately not called — a fight nobody finished did not end in victory.
     *
     * The check lives here rather than in action() because action() is not the
     * only door. combat/status, startEncounter()'s own early return, and the
     * piggybacked state on session/status and location/state all arrive through
     * this method and none of them go anywhere near action().
     *
     * It does mean a GET can write one row. That is acknowledged rather than
     * hidden: it is idempotent, it is bounded by the number of stale sessions
     * that exist, and the alternative is the same check copied to four call
     * sites, which is the arrangement that goes wrong.
     */
    public function getActiveSession(int $characterId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM combat_sessions WHERE character_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$characterId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['state'] = json_decode($row['state_json'] ?? '{}', true) ?: [];

        $version = (int) ($row['state']['engine_version'] ?? 1);
        if ($version !== self::ENGINE_VERSION || !isset($row['state']['grid'])) {
            $this->closeStaleSession((int) $row['id'], $row['state']);
            return null;
        }

        // Refreshed on the way out as well as after every action, so a fight
        // already in progress when this shipped shows its slots on the next
        // redraw rather than on the next swing — and so the one number on the
        // party card that lives outside `state_json` cannot be read stale.
        $row['state'] = $this->deriveSlots($row['state']);

        return $row;
    }

    /** Retire a session this engine cannot resolve. One row, no awards. */
    private function closeStaleSession(int $sessionId, array $state): void
    {
        $state['status'] = 'ended';
        $state['events'] = [];
        $state['log'][] = 'The ground shifts underfoot — this encounter has been reset.';
        $this->saveState($sessionId, $state);
        $this->db->prepare('UPDATE combat_sessions SET is_active = 0 WHERE id = ?')->execute([$sessionId]);
    }

    /**
     * Begin a fight.
     *
     * `$mapSeed` decides the battlefield. Left null it is derived from the
     * encounter and the place, so an authored fight always looks the same and
     * the boulder is where you left it. The fighting pit passes one explicitly,
     * because PitEngine::bout() rewrites a single scratch encounter row per
     * party — derive the seed from that row's id and every bout a player ever
     * fights is on an identical map.
     */
    /**
     * The words authored content uses for difficulty, against the budget's
     * three columns.
     *
     * `deadly` maps to `hard` rather than to a fourth column, because
     * EncounterBudget does not have one and says why: it will not build a fight
     * whose honest description is "you will probably die". An author asking for
     * deadly gets the hard budget, and the group multiplier — which is applied
     * to the whole group at each step — is what makes a swarm of them bite.
     *
     * A generated floor writes the tier name straight into the same column, so
     * `warmup`/`fair`/`hard` are understood here too.
     */
    private const DIFFICULTY_TIERS = [
        'easy' => 'warmup', 'medium' => 'fair', 'hard' => 'hard', 'deadly' => 'hard',
        'warmup' => 'warmup', 'fair' => 'fair',
    ];

    /**
     * How many bodies scaling may put on the board.
     *
     * BattleMapGen::deploy() falls back to `anywhere()` when a deployment zone
     * fills up, so overflowing is survivable rather than fatal — but a fight
     * that spills out of its own zone reads as a bug. Both the pit and the
     * delve stop at six; this is a little looser because it must also be able
     * to leave an authored horde alone.
     */
    private const SCALE_MAX_FOES = 8;

    /**
     * Fit an encounter's roster to the party that walked into it.
     *
     * An authored fight is a statement of WHO is here — three goblins in the
     * cellar — and until now it was also a statement of how many, fixed at
     * authoring time. A party who came back at fifth level met the same three
     * goblins, and the fight the author meant as a threat was a formality.
     * Generated floors had a subtler version of the same fault: DelveEngine
     * stocks against the budget when the level is rolled, so a floor you walk
     * away from and come back to two levels later is still sized for who you
     * used to be.
     *
     * Both are the same fix and it belongs here rather than in either of them,
     * because HERE is the moment the party is known. `difficulty` says how hard
     * the fight should be, `EncounterBudget` turns that and the party's levels
     * into an allowance, and the counts are fitted to it.
     *
     * What is NOT touched: the creatures themselves. A goblin has a goblin's
     * hit points and a goblin's attack whatever level the party is. Scaling
     * statistics would make the bestiary a lie and make every fight the same
     * fight; scaling numbers makes the cellar hold more goblins.
     *
     * Three floors, each of which is a rule rather than a tuning knob:
     *
     *   - every authored species keeps at least one member, so scaling can
     *     never delete the thing the room is about;
     *   - a roster the author marked `scale_to_party` off is returned
     *     untouched — a duel is a duel;
     *   - a fight that cannot be made small enough is left over budget rather
     *     than emptied. Being too hard is a fight; being empty is a bug.
     *
     * @param  list<array<string,mixed>> $monsterRows rows of encounter_monsters
     *                                   joined to monsters, carrying `quantity`
     * @param  list<array<string,mixed>> $party       the characters turning up
     * @return list<array<string,mixed>> the same rows with `quantity` refitted
     */
    private function scaleToParty(array $monsterRows, array $party, array $encounter): array
    {
        if (!$monsterRows || (int) ($encounter['scale_to_party'] ?? 1) !== 1) {
            return $monsterRows;
        }

        $levels = [];
        foreach ($party as $pc) {
            $levels[] = (int) ($pc['level'] ?? 1);
        }
        $tier = self::DIFFICULTY_TIERS[strtolower((string) ($encounter['difficulty'] ?? ''))] ?? 'fair';
        $budget = EncounterBudget::forParty($levels, $tier);
        if ($budget <= 0) {
            return $monsterRows;
        }

        // What the author asked for, kept as the shape to grow back into: a
        // fight written as one ogre and four goblins should stay mostly
        // goblins when it doubles, not become five ogres.
        $want = [];
        $xp = [];
        $authored = 0;
        foreach ($monsterRows as $i => $row) {
            $want[$i] = max(1, (int) ($row['quantity'] ?? 1));
            $xp[$i] = max(0, (int) ($row['experience_points'] ?? 0));
            $authored += $want[$i];
        }
        // A bestiary row with no XP cannot be priced, so it cannot be fitted.
        if (!array_filter($xp)) {
            return $monsterRows;
        }

        // Everyone starts at one and the group is grown a body at a time. This
        // is EncounterBudget::bestCount()'s loop with more than one species in
        // it: the multiplier is a property of the whole fight, so a third wolf
        // makes the two already there harder and has to be re-measured, not
        // approximated by scaling a total.
        $cap = max($authored, self::SCALE_MAX_FOES);
        $have = array_fill_keys(array_keys($want), 1);
        $count = count($have);
        $raw = 0;
        foreach ($have as $i => $n) {
            $raw += $xp[$i] * $n;
        }

        while ($count < $cap) {
            // Whichever species is furthest behind the author's proportions —
            // that is what keeps four goblins and one ogre looking like four
            // goblins and one ogre however far the fight is scaled.
            $pick = null;
            $worst = -1.0;
            foreach ($want as $i => $target) {
                if ($xp[$i] <= 0) {
                    continue;
                }
                $behind = $target / $have[$i];
                if ($behind > $worst) {
                    $worst = $behind;
                    $pick = $i;
                }
            }
            if ($pick === null) {
                break;
            }
            if (EncounterBudget::weight($raw + $xp[$pick], $count + 1, $budget) > 1.0) {
                break;
            }
            $have[$pick]++;
            $raw += $xp[$pick];
            $count++;
        }

        foreach ($monsterRows as $i => $row) {
            $monsterRows[$i]['quantity'] = $have[$i];
        }
        return $monsterRows;
    }

    /**
     * Who is standing over there, before anybody swings.
     *
     * The party can see the fight they are being asked to walk into, so the
     * scene and the ambush dialog say what is in it. This runs the SAME two
     * steps startEncounter() does — the same select, then scaleToParty() — and
     * that is the whole point of it being here rather than a tidy little query
     * in LocationEngine. The roster is scaled to who turned up, so a preview
     * built any other way would announce four goblins and then deal six; and
     * because scaleToParty() is arithmetic with no dice in it, calling it twice
     * with the same party gives the same answer both times.
     *
     * Grouped by species rather than listed body by body: "3 Goblins and a
     * Bugbear" is what a player can act on, and twelve identical lines is not.
     *
     * @return list<array{name:string, quantity:int, sprite_key:?string, image_url:?string}>
     */
    public function roster(int $encounterId, int $characterId): array
    {
        $enc = $this->db->prepare('SELECT * FROM encounters WHERE id = ?');
        $enc->execute([$encounterId]);
        $encounter = $enc->fetch();
        if (!$encounter) {
            return [];
        }

        $mons = $this->db->prepare(
            'SELECT em.quantity, em.rank_override, m.* FROM encounter_monsters em
             INNER JOIN monsters m ON m.id = em.monster_id
             WHERE em.encounter_id = ?'
        );
        $mons->execute([$encounterId]);
        $rows = $mons->fetchAll();
        if (!$rows) {
            return [];
        }

        $party = $this->getPartyCharacters($characterId);
        if ($party) {
            $rows = $this->scaleToParty($rows, $party, $encounter);
        }

        $out = [];
        foreach ($rows as $m) {
            $img = $m['image_url'] ?? null;
            $out[] = [
                'name'       => (string) $m['name'],
                'quantity'   => max(1, (int) ($m['quantity'] ?? 1)),
                'sprite_key' => $m['sprite_key'] ?? null,
                // The same .svg → .png rewrite the combat cards do, so the
                // portrait in the warning is the portrait in the fight.
                'image_url'  => is_string($img) ? preg_replace('/\.svg$/i', '.png', $img) : $img,
            ];
        }
        return $out;
    }

    /**
     * A roster as a sentence: "3 Goblins, 2 Wolves and a Bugbear".
     *
     * Lives here because this is where rosters are built. PitEngine grew it
     * first, for the line under each bout, and calls this now — one phrasing
     * of a group of monsters, so the pit's gate and an ambush warning cannot
     * start describing the same three wolves differently.
     *
     * @param list<array{name:string, quantity:int}> $group
     */
    public static function describeRoster(array $group): string
    {
        $parts = [];
        foreach ($group as $g) {
            $name = (string) $g['name'];
            $n = max(1, (int) ($g['quantity'] ?? 1));
            if ($n > 1) {
                $parts[] = $n . ' ' . self::plural($name);
            } elseif (self::isNamed($name)) {
                $parts[] = $name;
            } else {
                $parts[] = self::article($name) . ' ' . $name;
            }
        }
        if (count($parts) < 2) {
            return $parts[0] ?? 'nothing at all';
        }
        return implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts);
    }

    /**
     * A monster whose name is already a name, not a species.
     *
     * Three bosses in the bestiary carry their own article — The Drowned Clerk,
     * The Growth, The Pit's Man — and an ambush is exactly where they turn up
     * one at a time, which is how "a The Drowned Clerk" got as far as being
     * read. The pit never showed it because its pool is all generic bodies.
     */
    private static function isNamed(string $name): bool
    {
        return (bool) preg_match('/^(the|a|an)\s/i', $name);
    }

    private static function plural(string $name): string
    {
        // Wolf → Wolves, not Wolfs. The roster is small and English is not, so
        // this covers what is actually in it rather than pretending to be a
        // general rule.
        if (preg_match('/(?:f|fe)$/i', $name)) {
            return preg_replace('/(?:f|fe)$/i', 'ves', $name);
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $name)) {
            return $name . 'es';
        }
        return $name . 's';
    }

    private static function article(string $name): string
    {
        return preg_match('/^[aeiou]/i', $name) ? 'an' : 'a';
    }

    public function startEncounter(
        int $characterId,
        int $encounterId,
        int $locationId,
        ?int $mapSeed = null
    ): array {
        $existing = $this->getActiveSession($characterId);
        if ($existing) {
            return $existing;
        }

        $enc = $this->db->prepare('SELECT * FROM encounters WHERE id = ?');
        $enc->execute([$encounterId]);
        $encounter = $enc->fetch();
        if (!$encounter) {
            throw new InvalidArgumentException('Encounter not found.');
        }

        $mons = $this->db->prepare(
            'SELECT em.quantity, em.rank_override, m.* FROM encounter_monsters em
             INNER JOIN monsters m ON m.id = em.monster_id
             WHERE em.encounter_id = ?'
        );
        $mons->execute([$encounterId]);
        $monsterRows = $mons->fetchAll();

        $party = $this->getPartyCharacters($characterId);
        if (!$party) {
            throw new RuntimeException('No party characters for combat.');
        }

        // Fit the roster to who turned up. See scaleToParty().
        $monsterRows = $this->scaleToParty($monsterRows, $party, $encounter);

        // --- the ground ----------------------------------------------------
        // Generated from the location's own type and the type of the region
        // holding it, so a brawl in a tavern gets tables and a fight in the Old
        // City gets stalagmites without anybody drawing either.
        $place = $this->db->prepare(
            'SELECT l.location_type, r.region_type
               FROM locations l
          LEFT JOIN regions r ON r.id = l.region_id
              WHERE l.id = ?'
        );
        $place->execute([$locationId]);
        $where = $place->fetch() ?: [];
        $palette = BattleMapGen::paletteFor(
            (string) ($where['location_type'] ?? ''),
            (string) ($where['region_type'] ?? '')
        );
        $mapSeed ??= (int) crc32($encounterId . ':' . $locationId);
        $grid = BattleMapGen::generate($mapSeed, $palette);

        $combatants = [];
        $id = 1;

        // --- the party -----------------------------------------------------
        $fit = [];
        foreach ($party as $pc) {
            if ((int) $pc['current_hp'] > 0) {
                $fit[] = $pc;
            }
        }
        $spellsBy = $this->partySpellProfile(array_map(static fn ($p) => (int) $p['id'], $fit));
        $usesLeft = $this->bonusUsesLeft($fit);
        $ranks = array_map(
            static fn ($p) => ($p['combat_rank'] ?? 'front') === 'back' ? 'back' : 'front',
            $fit
        );
        $partySpots = BattleMapGen::deploy($grid['terrain'], $grid['zones']['party'], $ranks, 'right');

        $relentlessLeft = $this->relentlessUsesLeft($fit);

        foreach ($fit as $i => $pc) {
            $profile = $spellsBy[(int) $pc['id']] ?? ['caster' => false, 'healer' => false];
            $raceFeatures = Rules::raceFeatures(
                (string) ($pc['race'] ?? ''),
                isset($pc['subrace']) ? (string) $pc['subrace'] : null
            );
            $equipment = $this->equipmentSummary((int) $pc['id']);
            $combatants[] = [
                'cid'          => 'pc_' . $pc['id'],
                'id'           => $id++,
                'type'         => 'pc',
                'side'         => 'party',
                // Kept, and demoted. Nothing but the deployment above reads it
                // now; it survives so that the rank a player set on their sheet
                // still means "I stand at the front", and so that the column,
                // the editor and the API route all keep meaning something.
                'rank'         => $ranks[$i],
                'x'            => $partySpots[$i]['x'],
                'y'            => $partySpots[$i]['y'],
                'speed'        => max(0, (int) ($pc['speed'] ?? 30)),
                'move_left'    => max(0, (int) ($pc['speed'] ?? 30)),
                'character_id' => (int) $pc['id'],
                'name'         => $pc['name'],
                'hp'           => (int) $pc['current_hp'],
                'max_hp'       => (int) $pc['max_hp'],
                'ac'           => (int) $pc['armor_class'],
                'str'          => (int) $pc['strength'],
                'dex'          => (int) $pc['dexterity'],
                'con'          => (int) $pc['constitution'],
                'int'          => (int) $pc['intelligence'],
                'wis'          => (int) $pc['wisdom'],
                'cha'          => (int) $pc['charisma'],
                'level'        => (int) ($pc['level'] ?? 1),
                'prof'         => (int) ($pc['proficiency_bonus'] ?? 2),
                'class'        => $pc['class'],
                // Same crossing as `feats`: subclass features are read off the
                // combatant mid-fight, and the fight never asks the database.
                'subclass'     => $pc['subclass'] ?? null,
                'background'   => $pc['background'] ?? '',
                'sprite_key'   => $pc['sprite_key'] ?? 'fighter',
                'image_url'    => null,
                'is_caster'    => $profile['caster'],
                'is_healer'    => $profile['healer'],
                'has_ranged'   => true,
                // Stamped on here and nowhere else. Combat state is built from
                // the `characters` row once and then never reads the database
                // again, so anything a feat is to change in a fight has to make
                // the crossing at this line. Leaving `feats_json` behind is how
                // a catalogue of thirty feats would have been thirty paragraphs
                // of text and no game: Feats::taken() would have found nothing
                // on every combatant, and every effect would have read as zero.
                'feats'        => Feats::taken($pc),
                // The racial traits, crossing at the same line as `feats` and
                // for the same reason. Resistances land under the key
                // Rules::damageAfterDefenses already reads off monsters, so a
                // dwarf halves poison through the same door a skeleton does —
                // and Rage's snapshot-and-restore merges over it cleanly.
                'race_features' => $raceFeatures,
                'resistances'  => array_values(array_unique(array_merge(
                    Rules::raceResistances($raceFeatures),
                    $equipment['resistances']
                ))),
                // Worn magic's save bonus, read by Rules::savingThrow off the
                // combatant the same way `equip_save_bonus` documents there.
                'equip_save_bonus' => $equipment['save_bonus'],
                // Relentless Endurance's once-per-long-rest, counted in the
                // same world flag family as Rage and Second Wind so
                // CheckService::resetOnLongRest() gives it back unasked.
                'relentless_left' => $relentlessLeft[(int) $pc['id']] ?? null,
                // Same crossing as `feats`, and for the same reason: the fight
                // never reads the database again, so a per-rest allowance that
                // is not stamped here cannot be shown or spent. Null means the
                // feature has no rest cost.
                'bonus_uses_left' => $usesLeft[(int) $pc['id']] ?? null,
                'initiative'   => 0,
                'conditions'   => [],
                'boons'        => [],
                'concentrating'=> null,
                'action_used'  => false,
                'bonus_used'   => false,
                'move_used'    => false,
                'reaction_used'=> false,
                'dodging'      => false,
                'helped'       => null,
                'turn_forfeit' => false,
                // Dying, and how far along. `stable` is the SRD's third state
                // between up and dead: down, no longer slipping, and no longer
                // rolling. Both are on monsters too and mean nothing there —
                // a monster at zero is simply dead.
                'stable'       => false,
                'death_saves'  => ['success' => 0, 'fail' => 0],
                // A ward, spent before hit points and never healed back. Starts
                // at nothing every fight: the SRD loses temporary hit points on
                // a long rest, and a fight is further apart than that.
                'temp_hp'      => 0,
                // Set at the top of startEncounter() for an ambush, and spent
                // by the first turn it costs.
                'surprised'    => false,
                // A swing held for whoever walks into it. Cleared at the top of
                // your own turn along with the rest of the economy — a Ready
                // lasts until then, not for ever.
                'readied'      => false,
                'reckless'     => false,
                'smite'        => 0,
                // Extra Attack is counted here rather than derived on the
                // client, which would be a second copy of a class table in
                // JavaScript — exactly the drift ui-combat.js's header says was
                // taken out of this screen once already.
                'attacks_made' => 0,
                // Which of CLASS_FEATURES this character actually has, worked
                // out once here so the bar never has to hold a copy of the
                // table. The same rule as `ui.attacks_left`: the board asks,
                // it does not decide.
                'features'     => Rules::featuresHeld(
                    (string) $pc['class'],
                    (int) ($pc['level'] ?? 1),
                    isset($pc['subclass']) ? (string) $pc['subclass'] : null
                ),
                // Whether a bonus-action swing is available at all. It depends
                // on what is equipped, so it cannot be worked out from the
                // combatant alone; read once here because equipment does not
                // change in the middle of a fight.
                'can_offhand'  => $this->canBonusAttack($pc),
                'alive'        => true,
            ];
        }

        // --- the opposition ------------------------------------------------
        $foePrefs = [];
        $foeRows = [];
        foreach ($monsterRows as $m) {
            $qty = max(1, (int) $m['quantity']);
            for ($i = 0; $i < $qty; $i++) {
                $foeRows[] = [$m, $i, $qty];
                $foePrefs[] = ($m['rank_override'] ?? null) === 'back'
                    || (($m['rank_override'] ?? null) === null && ($m['rank_pref'] ?? 'front') === 'back')
                    ? 'back' : 'front';
            }
        }
        $foeSpots = BattleMapGen::deploy($grid['terrain'], $grid['zones']['foe'], $foePrefs, 'left');

        foreach ($foeRows as $slot => [$m, $i, $qty]) {
            $actions = json_decode($m['actions'] ?? '[]', true) ?: [];
            $img = $m['image_url'] ?? null;
            if (is_string($img)) {
                $img = preg_replace('/\.svg$/i', '.png', $img);
            }
            $hasRanged = false;
            foreach ($actions as $a) {
                if (($a['type'] ?? 'melee') === 'ranged') {
                    $hasRanged = true;
                }
            }
            $combatants[] = [
                'cid'        => 'mon_' . $m['id'] . '_' . $i . '_' . $id,
                'id'         => $id++,
                'type'       => 'monster',
                'side'       => 'foe',
                'rank'       => $foePrefs[$slot],
                'x'          => $foeSpots[$slot]['x'],
                'y'          => $foeSpots[$slot]['y'],
                // `monsters.speed` has read "30 ft." since the table was
                // written and nothing has ever looked at it.
                'speed'      => Rules::parseSpeedFt($m['speed'] ?? null),
                'move_left'  => Rules::parseSpeedFt($m['speed'] ?? null),
                'size'       => (string) ($m['size'] ?? ''),
                'monster_id' => (int) $m['id'],
                'name'       => $m['name'] . ($qty > 1 ? ' ' . ($i + 1) : ''),
                'hp'         => (int) $m['hit_points'],
                'max_hp'     => (int) $m['hit_points'],
                'ac'         => (int) $m['armor_class'],
                'image_url'  => $img,
                'sprite_key' => $m['sprite_key'] ?? null,
                'str'        => (int) $m['strength'],
                'dex'        => (int) $m['dexterity'],
                'con'        => (int) $m['constitution'],
                'int'        => (int) $m['intelligence'],
                'wis'        => (int) $m['wisdom'],
                'cha'        => (int) $m['charisma'],
                'class'      => '',
                'level'      => 1,
                'prof'       => 2,
                'cr'         => (float) $m['challenge_rating'],
                // `monsters.type` — undead, fiend, beast, humanoid. It has sat
                // in the table unread since the bestiary was written; Divine
                // Sense is the first rule that asks what a thing IS rather than
                // how hard it hits.
                'creature_type' => strtolower(trim((string) ($m['type'] ?? ''))),
                'xp'         => (int) $m['experience_points'],
                'actions'    => $actions,
                'traits'     => json_decode($m['traits'] ?? '{}', true) ?: [],
                // Decoded here so Rules and Conditions can read them straight
                // off the combatant without touching the database again.
                'saves'      => json_decode($m['saves_json'] ?? '{}', true) ?: [],
                // What the body is worth, rolled by checkEnd() on victory.
                'loot'       => json_decode($m['loot_json'] ?? '[]', true) ?: [],
                'resistances'=> json_decode($m['resistances_json'] ?? '[]', true) ?: [],
                'immunities' => json_decode($m['immunities_json'] ?? '[]', true) ?: [],
                'vulnerabilities' => json_decode($m['vulnerabilities_json'] ?? '[]', true) ?: [],
                'condition_immunities' => json_decode($m['condition_immunities_json'] ?? '[]', true) ?: [],
                'is_caster'  => false,
                'is_healer'  => false,
                'has_ranged' => $hasRanged,
                'initiative' => 0,
                'conditions' => [],
                'boons'      => [],
                'concentrating' => null,
                'action_used'  => false,
                'bonus_used'   => false,
                'move_used'    => false,
                'reaction_used'=> false,
                'dodging'      => false,
                'helped'       => null,
                'turn_forfeit' => false,
                // Dying, and how far along. `stable` is the SRD's third state
                // between up and dead: down, no longer slipping, and no longer
                // rolling. Both are on monsters too and mean nothing there —
                // a monster at zero is simply dead.
                'stable'       => false,
                'death_saves'  => ['success' => 0, 'fail' => 0],
                // A ward, spent before hit points and never healed back. Starts
                // at nothing every fight: the SRD loses temporary hit points on
                // a long rest, and a fight is further apart than that.
                'temp_hp'      => 0,
                // Set at the top of startEncounter() for an ambush, and spent
                // by the first turn it costs.
                'surprised'    => false,
                // A swing held for whoever walks into it. Cleared at the top of
                // your own turn along with the rest of the economy — a Ready
                // lasts until then, not for ever.
                'readied'      => false,
                'reckless'     => false,
                'smite'        => 0,
                // Extra Attack is counted here rather than derived on the
                // client, which would be a second copy of a class table in
                // JavaScript — exactly the drift ui-combat.js's header says was
                // taken out of this screen once already.
                'attacks_made' => 0,
                'alive'        => true,
            ];
        }

        // How far each of them threatens with what they are holding.
        //
        // Stamped once, here, for the same reason `feats` is: the fight never
        // reads the database again, and BattleGrid has to be able to work out
        // who threatens which cell without knowing what a weapon is. Nothing
        // swaps weapons mid-fight, so once is enough.
        foreach ($combatants as &$c) {
            $c['reach_ft'] = $this->meleeReachFt($c);
        }
        unset($c);

        // Initiative: d20 + DEX mod, highest first, DEX breaking ties.
        //
        // Two feats reach in here, and they reach in differently. Alert is a
        // flat bonus equal to the proficiency bonus, so it lands on the
        // modifier; Skulker rolls the die twice and keeps the better, so it
        // lands on the mode. Both are read off the combatant, which is why the
        // `feats` key had to be stamped on above before this loop runs.
        foreach ($combatants as &$c) {
            $modifier = Rules::abilityMod((int) $c['dex'])
                + Feats::sum($c, 'initiative_bonus', (int) ($c['prof'] ?? 2));
            $mode = Feats::flag($c, 'initiative_advantage') ? 'advantage' : 'normal';
            $init = Dice::d20Mode($modifier, $mode);
            $c['initiative'] = $init['total'];
            $c['init_roll'] = $init;
        }
        unset($c);

        usort($combatants, static function ($a, $b) {
            if ($b['initiative'] === $a['initiative']) {
                return $b['dex'] <=> $a['dex'];
            }
            return $b['initiative'] <=> $a['initiative'];
        });

        $highestCr = 0.0;
        foreach ($combatants as $c) {
            if ($c['type'] === 'monster') {
                $highestCr = max($highestCr, (float) ($c['cr'] ?? 0));
            }
        }

        // Surprise. `encounters.is_ambush` has meant one thing since it was
        // added — this fight starts the moment you walk in, without being
        // chosen — and the other thing it obviously means was never
        // implemented: the party did not see it coming.
        //
        // Decided PER CHARACTER against passive Perception, which is the SRD's
        // own mechanism for noticing an ambush and is the reason this is not
        // simply "the party loses a round". Seventeen encounters carry the flag
        // already, authored when it meant only that the fight starts by itself;
        // turning that into a free round for the monsters would silently make
        // all seventeen harder than whoever wrote them intended. A watchful
        // character still gets their turn, and the party's scout is worth
        // having along.
        //
        // The DC is the one invented number here. The SRD would roll the
        // ambushers' Stealth, and no monster in this game has any — so it is
        // built from what the encounter does carry, its hardest creature, and
        // kept inside a band where a reasonable Perception can pass and a poor
        // one cannot.
        if (!empty($encounter['is_ambush'])) {
            $dc = self::surpriseDc($highestCr);
            foreach ($combatants as $i => $c) {
                if (($c['side'] ?? '') !== 'party') {
                    continue;
                }
                $noticed = Rules::passiveScore($c, 'perception') >= $dc;
                $combatants[$i]['surprised'] = !$noticed;
            }
        }

        $state = [
            'engine_version' => self::ENGINE_VERSION,
            'grid'           => $grid,
            'location_id'    => $locationId,
            'combatants'     => $combatants,
            'order'          => array_column($combatants, 'cid'),
            'turn_index'     => 0,
            'round'          => 1,
            'log'            => ['Combat begins! Initiative rolled.'],
            'events'         => [],
            'status'         => 'active',
            'encounter_id'   => $encounterId,
            'encounter_name' => $encounter['name'],
            'allow_flee'     => (bool) ($encounter['allow_flee'] ?? 1),
            'allow_parley'   => (bool) ($encounter['allow_parley'] ?? 0),
            'parley_node'    => $encounter['parley_node'] ?? null,
            'victory_flag'   => $encounter['victory_flag'] ?? null,
            'defeat_flag'    => $encounter['defeat_flag'] ?? null,
            'flee_dc'        => self::fleeDc($highestCr),
            'xp_award'       => 0,
            'gold_award'     => 0,
        ];

        $state = $this->beginTurn($state);
        // The first turn needs a board to draw as much as any other one does.
        // settle() covers every turn after this; without this line the opening
        // round is the only one with nothing highlighted.
        $state = $this->deriveUi($state);
        $state = $this->deriveSlots($state);

        $partyId = WorldState::partyIdFor($this->db, $characterId);
        $ins = $this->db->prepare(
            'INSERT INTO combat_sessions (character_id, location_id, encounter_id, party_id, current_turn, state_json, is_active)
             VALUES (?, ?, ?, ?, 0, ?, 1)'
        );
        $ins->execute([$characterId, $locationId, $encounterId, $partyId, json_encode($state)]);

        return [
            'id'           => (int) $this->db->lastInsertId(),
            'character_id' => $characterId,
            'location_id'  => $locationId,
            'encounter_id' => $encounterId,
            'is_active'    => 1,
            'state'        => $state,
        ];
    }

    public function action(int $characterId, array $payload): array
    {
        $session = $this->getActiveSession($characterId);
        if (!$session) {
            // The last finished session, so the victory/defeat screen survives
            // a reload.
            $stmt = $this->db->prepare(
                'SELECT * FROM combat_sessions WHERE character_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$characterId]);
            $last = $stmt->fetch();
            if ($last) {
                $last['state'] = json_decode($last['state_json'] ?? '{}', true) ?: [];
                return $this->formatSession($last);
            }
            throw new InvalidArgumentException('No active combat.');
        }

        // Sessions this engine cannot resolve have already been retired by
        // getActiveSession(), which is why there is no version check here.
        $state = $session['state'];

        if (($state['status'] ?? '') !== 'active') {
            return $this->formatSession($session);
        }

        // The client animates from whatever this one action produced, so the
        // slate is wiped here and nowhere else.
        $state['events'] = [];

        $action = (string) ($payload['action'] ?? '');
        $actor = $this->currentActor($state);
        if (!$actor) {
            throw new RuntimeException('No current actor.');
        }

        if ($actor['type'] === 'monster' && $action !== 'ai' && $action !== 'end_turn') {
            $state = $this->runMonsterAI($state);
            $state = $this->settle($state, (int) $session['id'], $characterId);
            return $this->reread($characterId, (int) $session['id'], $state);
        }

        switch ($action) {
            case 'attack':
                $state = $this->doAttack(
                    $state,
                    (string) ($payload['target_cid'] ?? ''),
                    !empty($payload['manual'])
                );
                // A parked attack must not run the monsters or save a settled
                // state: the turn is not over, it is waiting on a die.
                if (!empty($state['pending_check'])) {
                    $this->saveState((int) $session['id'], $state);
                    return $this->reread($characterId, (int) $session['id'], $state);
                }
                break;
            case 'cast':
                $state = $this->doCast(
                    $state,
                    (int) ($payload['spell_id'] ?? 0),
                    (string) ($payload['target_cid'] ?? ''),
                    isset($payload['target_x']) ? (int) $payload['target_x'] : null,
                    isset($payload['target_y']) ? (int) $payload['target_y'] : null,
                    isset($payload['cast_level']) ? (int) $payload['cast_level'] : null
                );
                break;
            case 'use_item':
                $state = $this->doUseItem($state, (int) ($payload['inventory_id'] ?? 0));
                break;
            case 'move':
                $state = $this->doMove(
                    $state,
                    (int) ($payload['x'] ?? -1),
                    (int) ($payload['y'] ?? -1)
                );
                break;
            case 'dash':
                $state = $this->doDash($state);
                break;
            case 'ready':
                $state = $this->doReady($state);
                break;
            case 'surge':
                $state = $this->doActionSurge($state);
                break;
            case 'reckless':
                $state = $this->doReckless($state);
                break;
            case 'smite':
                $state = $this->doSmite($state, (int) ($payload['level'] ?? 1));
                break;
            case 'channel':
                $state = $this->doChannelDivinity($state, (string) ($payload['which'] ?? ''));
                break;
            case 'offhand':
                $state = $this->doBonusAttack($state, (string) ($payload['target_cid'] ?? ''));
                break;
            case 'lay_on_hands':
                $state = $this->doLayOnHands($state, (string) ($payload['target_cid'] ?? ''));
                break;
            case 'divine_sense':
                $state = $this->doDivineSense($state);
                break;
            case 'hide':
                $state = $this->doHide($state);
                break;
            case 'grapple':
            case 'shove':
                $state = $this->doContest($state, (string) ($payload['target_cid'] ?? ''), $action);
                break;
            case 'dodge':
                $state = $this->doDodge($state);
                break;
            case 'help':
                $state = $this->doHelp($state, (string) ($payload['target_cid'] ?? ''));
                break;
            case 'disengage':
                $state = $this->doDisengage($state);
                break;
            case 'flee':
                $state = $this->doFlee($state);
                break;
            case 'parley':
                $state = $this->doParley($state);
                break;
            case 'bonus':
                $state = $this->doBonusAction(
                    $state,
                    (string) ($payload['which'] ?? ''),
                    (string) ($payload['option'] ?? '')
                );
                break;
            case 'end_turn':
                $state = $this->endTurn($state);
                break;
            case 'ai':
                if ($actor['type'] === 'monster') {
                    $state = $this->runMonsterAI($state);
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown combat action.');
        }

        $state = $this->settle($state, (int) $session['id'], $characterId);
        return $this->reread($characterId, (int) $session['id'], $state);
    }

    /**
     * End-of-action bookkeeping: check for an ending, run the monsters, save.
     *
     * The monster loop lives here rather than in action() so that a monster
     * turn reached by any route resolves the same way.
     */
    private function settle(array $state, int $sessionId, int $characterId): array
    {
        $state = $this->checkEnd($state, $characterId);

        if (($state['status'] ?? '') !== 'active') {
            $this->finalizeCombat($sessionId, $characterId, $state);
            return $state;
        }

        $guard = 0;
        while (($state['status'] ?? '') === 'active' && $guard++ < 40) {
            $cur = $this->currentActor($state);
            if (!$cur || $cur['type'] !== 'monster' || !self::isTargetable($cur)) {
                break;
            }
            $state = $this->runMonsterAI($state);
            $state = $this->checkEnd($state, $characterId);
        }

        if (($state['status'] ?? '') !== 'active') {
            $this->finalizeCombat($sessionId, $characterId, $state);
        } else {
            $state = $this->deriveUi($state);
            // After the action, not before: a Cleric who has just cast has
            // already had the slot taken out of `spell_slots_json`, and the
            // card the player looks at next has to say so.
            $state = $this->deriveSlots($state);
            $this->saveState($sessionId, $state);
        }
        return $state;
    }

    /**
     * Everything the board needs to draw, worked out by the code that enforces it.
     *
     * The client used to mirror legalTargets() in JavaScript, and ui-combat.js
     * carried a comment admitting the copy would drift. A pathfinder, a
     * line-of-sight trace and a cover rule mirrored the same way would be that
     * problem several times over, and the failure would be the worst kind: the
     * board offering a move or a shot the server then refuses, which reads to a
     * player as the game being broken rather than as a disagreement.
     *
     * So the server computes and the client paints. Three things:
     *
     *   - `reach`: every cell the actor can walk to, its cost, the predecessor
     *     the search already recorded, and how many free swings going there
     *     would cost. The predecessor chain is what lets hovering a cell draw
     *     the exact path the server would take, with no second request and no
     *     second implementation.
     *   - `threat`: the cells enemies can reach, so leaving one can be shown to
     *     be dangerous before it is done.
     *   - `targets`: the quality of every shot — distance, cover, and why the
     *     dice would be what they are.
     *
     * Derived and non-authoritative. Nothing on the server ever reads it back;
     * it is rebuilt from scratch after every action. Computed only for a player
     * character's turn, because nothing draws a monster's options.
     */
    private function deriveUi(array $state): array
    {
        unset($state['ui']);
        $actor = $this->currentActor($state);
        if (!$actor || ($actor['type'] ?? '') !== 'pc' || !self::isTargetable($actor)) {
            return $state;
        }

        $terrain = $state['grid']['terrain'] ?? [];
        $watchers = $this->reactors($state, (string) $actor['cid']);
        // reachFor() and not BattleGrid::reachable(): what is painted has to be
        // what doMove() will accept, and being grappled, frightened or flat on
        // your back all change that.
        $reach = $this->reachFor($state, $actor);

        $cells = [];
        foreach ($reach as $key => $entry) {
            $cells[$key] = [
                'cost'     => $entry['cost'],
                'from'     => $entry['from'],
                'end'      => $entry['end'],
                'provokes' => $this->provocations($state, $actor, $reach, (string) $key, $terrain),
            ];
        }

        $weapon = $this->weaponFor($actor);
        $targets = [];
        foreach (self::shotsFrom($state['grid'], $state['combatants'], $actor, self::rangeOf($weapon)) as $cid => $shot) {
            $why = [];
            if (!empty($shot['long'])) {
                $why[] = 'long range';
            }
            if (!empty($shot['crowded'])) {
                $why[] = 'an enemy at your elbow';
            }
            if (!empty($shot['flank'])) {
                $why[] = 'flanking';
            }
            if ((int) $shot['cover'] > 0) {
                $why[] = 'in cover (+' . (int) $shot['cover'] . ')';
            }
            $targets[$cid] = [
                'dist'  => $shot['dist'],
                'cover' => $shot['cover'],
                'long'  => $shot['long'],
                'melee' => $shot['melee'],
                'flank' => $shot['flank'],
                'mode'  => (!empty($shot['long']) || !empty($shot['crowded']))
                    ? (!empty($shot['flank']) ? 'normal' : 'disadvantage')
                    : (!empty($shot['flank']) ? 'advantage' : 'normal'),
                'why'   => implode(', ', $why),
            ];
        }

        $state['ui'] = [
            'actor'   => (string) $actor['cid'],
            'weapon'  => ['name' => $weapon['name'], 'range' => self::rangeOf($weapon)],
            'reach'   => $cells,
            'threat'  => BattleGrid::threatenedCells($terrain, $watchers),
            'targets' => $targets,
            // Swings left in the Attack action, worked out by the same function
            // that will accept or refuse the next one. The board must not read
            // a class table of its own to decide whether to offer a second
            // attack — that is the mirrored-rule mistake this block exists to
            // prevent, and it would drift the first time a class gained Extra
            // Attack at a different level.
            'attacks_left' => max(0, self::attacksAllowed($actor)
                - (int) ($actor['attacks_made'] ?? 0)),
        ];
        return $state;
    }

    /**
     * What every party caster has left to cast with, per slot level.
     *
     * Derived beside `ui` and for the same reason: the board draws it and must
     * not work it out. A slot table in JavaScript would be a second copy of
     * Rules::slotTable — the exact mirrored-rule mistake deriveUi() exists to
     * prevent — and the expended counts are not in combat state at all. They
     * live in `characters.spell_slots_json`, which is where spendSlot() writes
     * them, so this reads them from there rather than keeping a running tally
     * a fight could get wrong.
     *
     * Every PC, not just the actor: the point of the party rail is comparing
     * the party, and "can Aldric still heal me" is a question asked on the
     * fighter's turn.
     *
     * A list rather than a map, deliberately. An empty PHP map encodes as `[]`
     * and a populated one as an object, which is the round-trip trap `map()` in
     * ui-combat.js was written to survive; a list is an array either way.
     *
     * @return array<int,array{level:int,max:int,left:int}>
     */
    private function slotsFor(array $c): array
    {
        $table = Rules::slotTable((string) ($c['class'] ?? ''), (int) ($c['level'] ?? 1));
        $out = [];
        $spent = null;
        foreach ($table as $lvl => $have) {
            if ((int) $have < 1) {
                continue;
            }
            // Only asked for once a class turns out to have slots at all, so a
            // party of four fighters costs no queries.
            $spent ??= $this->slotsExpended((int) $c['character_id']);
            $used = (int) ($spent[(string) $lvl] ?? $spent[$lvl] ?? 0);
            $out[] = [
                'level' => (int) $lvl,
                'max'   => (int) $have,
                'left'  => max(0, (int) $have - $used),
            ];
        }
        return $out;
    }

    /** Stamp slotsFor() onto every party member. Called wherever `ui` is derived. */
    private function deriveSlots(array $state): array
    {
        // `?? []` because this now runs on the way out of getActiveSession(),
        // which is reached by a saved blob rather than by a state this method
        // just built.
        foreach ($state['combatants'] ?? [] as $i => $c) {
            if (($c['type'] ?? '') !== 'pc') {
                continue;
            }
            $state['combatants'][$i]['slots'] = $this->slotsFor($c);
        }
        return $state;
    }

    private function reread(int $characterId, int $sessionId, array $state): array
    {
        $fresh = $this->getActiveSession($characterId);
        if (!$fresh) {
            return ['id' => $sessionId, 'is_active' => 0, 'state' => $state];
        }
        return $this->formatSession($fresh);
    }

    private function formatSession(array $session): array
    {
        return [
            'id' => (int) $session['id'],
            'character_id' => (int) $session['character_id'],
            'location_id' => (int) $session['location_id'],
            'encounter_id' => (int) $session['encounter_id'],
            'is_active' => (int) $session['is_active'],
            'state' => $session['state'],
        ];
    }

    // =======================================================================
    // State access
    // =======================================================================

    private function currentActor(array $state): ?array
    {
        $order = $state['order'] ?? [];
        $idx = (int) ($state['turn_index'] ?? 0);
        if (!isset($order[$idx])) {
            return null;
        }
        return $this->getCombatant($state, $order[$idx]);
    }

    private function updateCombatant(array $state, string $cid, array $changes): array
    {
        foreach ($state['combatants'] as $i => $c) {
            if ($c['cid'] === $cid) {
                $state['combatants'][$i] = array_merge($c, $changes);
                break;
            }
        }
        return $state;
    }

    private function getCombatant(array $state, string $cid): ?array
    {
        foreach ($state['combatants'] as $c) {
            if ($c['cid'] === $cid) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Record one thing that happened, and where the log had got to when it did.
     *
     * `log_at` is how many lines the log held at the moment of the event. The
     * client flushes up to that mark before playing the event, so a blow's
     * description and the bar it steps arrive on the same beat — the whole
     * round's prose used to be printed before the first animation started, which
     * read as the log running ahead of the board.
     *
     * An INDEX, deliberately, not the line itself: playEvents' contract is that
     * nothing in the client reads a word of the prose, and a count is a fact
     * about the log rather than its content. Stamped here because this is the
     * one place an event is ever appended — a mark left at each call site would
     * be forty chances to forget one.
     */
    private function emit(array $state, array $event): array
    {
        $event['log_at'] = count($state['log'] ?? []);
        $state['events'][] = $event;
        return $state;
    }

    // =======================================================================
    // The turn
    // =======================================================================

    private function advanceTurn(array $state): array
    {
        $state['turn_index'] = ((int) $state['turn_index'] + 1) % max(1, count($state['order']));
        if ((int) $state['turn_index'] === 0) {
            $state['round'] = ((int) $state['round']) + 1;
            // Who has been swung at is a within-round memory: it exists to stop
            // one round's worth of monsters all choosing the same throat, not
            // to make them ignore somebody for the whole fight.
            $state['focus'] = [];
        }
        return $state;
    }

    /**
     * Open a turn: reset the action economy, tick conditions, then either hand
     * over or skip.
     *
     * Written as a loop rather than by recursing through endTurn(), because a
     * party all of whom are unconscious would otherwise recurse until the stack
     * ran out instead of falling through to checkEnd().
     */
    private function beginTurn(array $state): array
    {
        $guard = 0;
        while ($guard++ < 60) {
            $actor = $this->currentActor($state);
            if (!$actor) {
                return $state;
            }
            if (empty($actor['alive'])) {
                $state = $this->advanceTurn($state);
                continue;
            }

            $state = $this->updateCombatant($state, $actor['cid'], [
                'action_used'   => false,
                'bonus_used'    => false,
                'move_used'     => false,
                // A fresh allowance of feet. `move_used` is kept beside it as
                // the flag the action bar's pip reads; `move_left` is the one
                // that decides anything.
                'move_left'     => self::turnSpeed($actor),
                // One reaction, refreshed at the start of your own turn. That
                // looks wrong the first time you read it next to opportunity
                // attacks — surely it should refresh at the top of the round? —
                // and it is right: the SRD's reaction comes back "at the start
                // of your turn", so a creature that spent one reacting to
                // somebody earlier in the order has none until its own turn
                // comes round again.
                'reaction_used' => false,
                'dodging'       => false,
                // Disengage lasts until your next turn, like Dodge, and is
                // cleared in the same breath for the same reason. A readied
                // swing goes the same way: you cannot hold one across your own
                // turn and still take that turn.
                'disengaging'   => false,
                'readied'       => false,
                // "Until the start of your next turn" — the exposure Reckless
                // Attack buys advantage with expires exactly here.
                'reckless'      => false,
                // A Smite held and never landed. Dropped rather than carried,
                // because "the next thing you hit" cannot mean next round.
                'smite'         => 0,
                // Savage Attacker and Piercer are each "once on each of your
                // turns", and this is where a turn begins. Cleared alongside
                // the action economy because it is part of it.
                'feat_turn'     => [],
                // How many swings of the Attack action have been taken. Counted
                // up rather than an allowance counted down, so that "have you
                // started attacking yet" and "how many have you had" are the
                // same number — which is what lets doAttack() tell a second
                // swing of one Attack action apart from an attack after Dodge
                // without a second flag to keep in step.
                'attacks_made'  => 0,
            ]);

            $state = $this->tickConditions($state, $actor['cid']);
            $state = $this->tickBoons($state, $actor['cid']);
            $state = $this->tickConcentration($state, $actor['cid']);

            $actor = $this->getCombatant($state, $actor['cid']);
            $state['log'][] = "— Round {$state['round']}: {$actor['name']}'s turn —";

            // Caught unawares. "You can't move or take an action on your first
            // turn of the combat, and you can't take a reaction until that turn
            // ends" — so the flag is cleared here, on the turn it costs, and
            // the reaction is spent along with it rather than refreshed by the
            // block above.
            if (!empty($actor['surprised'])) {
                $state = $this->updateCombatant($state, $actor['cid'], [
                    'surprised' => false, 'reaction_used' => true,
                ]);
                $state['log'][] = "{$actor['name']} is caught unawares and loses the turn.";
                $state = $this->emit($state, ['type' => 'surprised', 'actor' => $actor['cid']]);
                $state = $this->advanceTurn($state);
                continue;
            }

            // A failed flee costs the whole party its turn; the flag is
            // one-shot so the round after is normal.
            if (!empty($actor['turn_forfeit'])) {
                $state = $this->updateCombatant($state, $actor['cid'], ['turn_forfeit' => false]);
                $state['log'][] = "{$actor['name']} is still scrambling for an opening and loses the turn.";
                $state = $this->advanceTurn($state);
                continue;
            }

            // A dying character's turn is the death save, and nothing else.
            // Checked before canAct() below, which would otherwise report them
            // as Unconscious and pass over them in silence — which is exactly
            // what happened for the whole life of the rank board.
            if (self::isDying($actor)) {
                $state = $this->deathSave($state, (string) $actor['cid']);
                $actor = $this->getCombatant($state, (string) $actor['cid']);
                // A natural 20 put them back up at one hit point, and the turn
                // it happened on is still theirs to take.
                if ($actor && self::isTargetable($actor) && Conditions::canAct($actor)) {
                    return $state;
                }
                $state = $this->advanceTurn($state);
                continue;
            }

            if (!Conditions::canAct($actor)) {
                $blocker = 'incapacitated';
                foreach ($actor['conditions'] ?? [] as $cond) {
                    $key = is_array($cond) ? ($cond['key'] ?? '') : (string) $cond;
                    if (!empty(Conditions::CATALOG[$key]['blocks_actions'])) {
                        $blocker = $key;
                        break;
                    }
                }
                $label = Conditions::CATALOG[$blocker]['label'] ?? ucfirst($blocker);
                $state['log'][] = "{$actor['name']} is {$label} and can do nothing.";
                $state = $this->advanceTurn($state);
                continue;
            }

            return $state;
        }
        return $state;
    }

    private function endTurn(array $state): array
    {
        return $this->beginTurn($this->advanceTurn($state));
    }

    /** Age the conditions on one combatant and roll any repeat saves they owe. */
    private function tickConditions(array $state, string $cid): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }

        $tick = Conditions::tickStart($c);
        $state = $this->updateCombatant($state, $cid, ['conditions' => $tick['combatant']['conditions'] ?? []]);

        foreach ($tick['expired'] as $key) {
            $label = Conditions::CATALOG[$key]['label'] ?? ucfirst($key);
            $state['log'][] = "{$c['name']} is no longer {$label}.";
            $state = $this->emit($state, ['type' => 'condition', 'target' => $cid, 'key' => $key, 'applied' => false]);
        }

        foreach ($tick['saves_due'] as $due) {
            $subject = $this->getCombatant($state, $cid);
            if (!$subject) {
                break;
            }
            $result = $this->rollSave($subject, (string) $due['ability'], (int) $due['dc'], false, [
                'condition' => (string) ($due['key'] ?? ''),
            ]);
            $state = $this->emit($state, [
                'type' => 'save', 'actor' => $cid, 'ability' => $due['ability'], 'dc' => (int) $due['dc'],
                'natural' => $result['natural'], 'total' => $result['total'], 'passed' => $result['passed'],
            ]);
            if ($result['passed']) {
                $state = $this->removeCondition($state, $cid, (string) $due['key']);
            } else {
                $label = Conditions::CATALOG[$due['key']]['label'] ?? $due['key'];
                $state['log'][] = "{$subject['name']} fails to shake off {$label} ({$result['total']} vs DC {$due['dc']}).";
            }
        }

        return $state;
    }

    /** Count down a held spell and drop it when its duration runs out. */
    private function tickConcentration(array $state, string $cid): array
    {
        $c = $this->getCombatant($state, $cid);
        $conc = $c['concentrating'] ?? null;
        if (!is_array($conc)) {
            return $state;
        }
        $rounds = (int) ($conc['rounds'] ?? 0);
        if ($rounds <= 1) {
            return $this->dropConcentration($state, $cid, 'ends');
        }
        $conc['rounds'] = $rounds - 1;
        return $this->updateCombatant($state, $cid, ['concentrating' => $conc]);
    }

    // =======================================================================
    // Actions
    // =======================================================================

    /** The actor whose turn it is, checked for being able to take an action. */
    private function acting(array $state, bool $needsAction = true): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || !self::isTargetable($actor)) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($needsAction && !empty($actor['action_used'])) {
            throw new InvalidArgumentException('Already acted this turn.');
        }
        return $actor;
    }

    private function doAttack(array $state, string $targetCid, bool $manual = false): array
    {
        // NOT acting(), which refuses anybody whose action is spent. The second
        // swing of an Extra Attack is taken *inside* an action already spent,
        // and is the one case where that is not somebody trying to act twice.
        $actor = $this->acting($state, false);
        $allowed = self::attacksAllowed($actor);
        $made = (int) ($actor['attacks_made'] ?? 0);
        if ($made === 0 && !empty($actor['action_used'])) {
            throw new InvalidArgumentException('Already acted this turn.');
        }
        if ($made >= $allowed) {
            throw new InvalidArgumentException(
                $allowed > 1
                    ? $actor['name'] . ' has taken both attacks this turn.'
                    : 'Already acted this turn.'
            );
        }

        $weapon = $this->weaponFor($actor);

        $target = $this->getCombatant($state, $targetCid);
        if (!$target || !self::isTargetable($target)) {
            throw new InvalidArgumentException('Invalid target.');
        }
        if (($target['side'] ?? '') === ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('Cannot attack allies.');
        }

        $range = self::rangeOf($weapon);
        $shots = self::shotsFrom($state['grid'], $state['combatants'], $actor, $range);
        if (!isset($shots[$targetCid])) {
            throw new InvalidArgumentException($this->whyNot($state, $actor, $target, $range, $weapon));
        }

        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used'  => true,
            'attacks_made' => $made + 1,
        ]);

        // A player who wants to throw their own dice gets the same two-phase
        // prompt a dialogue check uses: the DC is the target's armour class, and
        // Bless or Bardic Inspiration can be spent on it before the die lands.
        // The turn stops here and resumes in resolveAttackCheck().
        if ($manual && ($actor['type'] ?? '') === 'pc') {
            return $this->suspendForAttackRoll($state, $actor, $target, $weapon, $shots[$targetCid]);
        }

        return $this->resolveAttack($state, $actor['cid'], $targetCid, $weapon, null, $shots[$targetCid]);
    }

    /**
     * Why a shot is not on, in words worth reading.
     *
     * "That target cannot be reached" was a fine answer when the only reason
     * was a front rank. There are four reasons now and they want different
     * responses from the player — walk closer, walk sideways, change weapon —
     * so the message says which one it is and what the numbers were.
     */
    private function whyNot(array $state, array $actor, array $target, array $range, array $weapon): string
    {
        $terrain = $state['grid']['terrain'] ?? [];
        $ax = (int) $actor['x'];
        $ay = (int) $actor['y'];
        $tx = (int) $target['x'];
        $ty = (int) $target['y'];

        if (!BattleGrid::losClear($terrain, $ax, $ay, $tx, $ty)) {
            return "There is no line to {$target['name']} from here.";
        }

        $dist = BattleGrid::feet($ax, $ay, $tx, $ty);
        $furthest = $range['long'] ?? $range['normal'] ?? $range['reach'] ?? 0;
        if ($range['normal'] === null) {
            return "{$target['name']} is {$dist} feet away, and {$weapon['name']} reaches {$furthest}.";
        }
        return "{$target['name']} is {$dist} feet away — {$weapon['name']} carries {$furthest} at the outside.";
    }

    /**
     * Park the turn until the player has rolled.
     *
     * Held inside `state_json`: a session's state already round-trips as one
     * blob, and splitting half of it into a second column would mean two
     * things to keep in step for no gain.
     *
     * THE SHOT IS PARKED WITH THE WEAPON, and it has to be. Cover, long range
     * and a crowded shot are all facts about this particular attack from this
     * particular cell, and the fight moves on while the player is looking at a
     * die. Leave them out and a manually-rolled attack silently drops the
     * target's cover bonus and its own disadvantage — a bug that only appears
     * when "My dice" is ticked, which is to say almost never, which is to say
     * it would have lived here for months.
     */
    private function suspendForAttackRoll(
        array $state,
        array $actor,
        array $target,
        array $weapon,
        array $shot
    ): array {
        $actorCopy = $actor;
        $actorCopy['attack_kind'] = !empty($shot['melee']) ? 'melee' : 'ranged';
        $ctx = Conditions::attackContext($actorCopy, $target);
        $extraAdv = !empty($actor['helped'])
            || !empty($shot['flank'])
            || (self::hasTrait($actor, 'pack tactics') && $this->packSupport($state, $actor, $target));
        $extraDis = !empty($target['dodging']) || !empty($shot['long']) || !empty($shot['crowded']);
        $mode = self::rollMode($ctx, $extraAdv, $extraDis);

        $characterId = (int) ($actor['character_id'] ?? 0);
        $partyId = WorldState::partyIdFor($this->db, $characterId);
        if (!$partyId) {
            // No party means no boosts to offer and nobody to log the roll
            // against, so there is nothing a prompt could usefully show.
            return $this->resolveAttack(
                $state,
                (string) $actor['cid'],
                (string) $target['cid'],
                $weapon,
                null,
                $shot
            );
        }

        $reasons = $ctx['reasons'] ?? [];
        if (!empty($shot['long'])) {
            $reasons[] = 'long range';
        }
        if (!empty($shot['crowded'])) {
            $reasons[] = 'an enemy at your elbow';
        }
        if (!empty($shot['flank'])) {
            $reasons[] = 'flanking';
        }

        $parts = [['label' => $weapon['name'], 'value' => (int) $weapon['bonus']]];
        $cover = (int) ($shot['cover'] ?? 0);

        $offered = (new CheckService($this->db))->request($partyId, [
            // Armour class is the target number, clamped because the column it
            // is written to is a TINYINT and a DC outside 1..30 is a bug.
            'dc'     => max(1, min(30, self::effectiveAc($target, $cover))),
            'attack' => [
                'label'       => $weapon['name'] . ' vs ' . $target['name']
                    . ($cover > 0 ? ' (in cover)' : ''),
                'modifier'    => (int) $weapon['bonus'],
                'parts'       => $parts,
                'die_mode'    => $mode,
                'die_reasons' => $reasons,
            ],
            'context' => ['kind' => 'combat_attack'],
        ], $characterId);

        $state['pending_check'] = $offered + [
            'attack' => [
                'actor_cid'  => (string) $actor['cid'],
                'target_cid' => (string) $target['cid'],
                'weapon'     => $weapon,
                'shot'       => $shot,
            ],
        ];
        $state['log'][] = "{$actor['name']} lines up on {$target['name']}…";
        return $state;
    }

    /**
     * Finish an attack the player rolled themselves.
     *
     * The die and any boosts are resolved by CheckService, then handed to the
     * ordinary attack path — so a manual roll and an automatic one differ only
     * in who watched the number appear.
     */
    public function resolveAttackCheck(int $characterId, string $checkId, array $boosts): array
    {
        $session = $this->getActiveSession($characterId);
        if (!$session) {
            throw new InvalidArgumentException('No active combat.');
        }
        $state = $session['state'];
        $parked = $state['pending_check']['attack'] ?? null;
        if (!$parked) {
            throw new InvalidArgumentException('No attack is waiting on a roll.');
        }

        $checks = new CheckService($this->db);
        $result = $checks->resolve($checkId, $boosts);

        unset($state['pending_check']);
        $state['events'] = [];

        // resolvePending has already applied advantage, boosts and any reroll,
        // so this is the number that counted — reshaped into what Dice::d20Mode
        // would have returned so resolveAttack cannot tell the difference.
        $prerolled = [
            'rolls'    => $result['rolls'],
            'natural'  => $result['natural'],
            'modifier' => $result['modifier'],
            'total'    => $result['total'],
            'crit'     => $result['natural'] === 20,
            'fumble'   => $result['natural'] === 1,
            'mode'     => $result['die_mode'],
        ];

        $state = $this->resolveAttack(
            $state,
            (string) $parked['actor_cid'],
            (string) $parked['target_cid'],
            (array) $parked['weapon'],
            $prerolled,
            isset($parked['shot']) ? (array) $parked['shot'] : null
        );

        $state = $this->settle($state, (int) $session['id'], $characterId);
        $out = $this->reread($characterId, (int) $session['id'], $state);
        $out['roll'] = $result;
        return $out;
    }

    /**
     * One attack roll and its damage, from whoever to whoever.
     *
     * `$shot` is the entry shotsFrom() made for this pair — distance, cover,
     * whether it is a melee swing or a long shot, whether the attacker is
     * flanking or has somebody at their elbow. It arrives rather than being
     * recomputed because the caller has already worked it out and because an
     * opportunity attack's shot is about the cell being *left*, which nothing
     * here could reconstruct.
     *
     * @param array{name:string, bonus:int, damage:string, damage_type:string, reach:string} $weapon
     */
    private function resolveAttack(
        array $state,
        string $actorCid,
        string $targetCid,
        array $weapon,
        ?array $prerolled = null,
        ?array $shot = null
    ): array {
        $actor = $this->getCombatant($state, $actorCid);
        $target = $this->getCombatant($state, $targetCid);
        if (!$actor || !$target) {
            return $state;
        }

        // Conditions::attackContext() requires this; melee and ranged differ
        // for prone, and it has no other way to tell which this is. The *shot*
        // decides, not the weapon: a javelin thrust and a javelin thrown are
        // the same weapon and different attacks.
        $actor['attack_kind'] = ($shot === null)
            ? ($weapon['reach'] === 'ranged' ? 'ranged' : 'melee')
            : (!empty($shot['melee']) ? 'melee' : 'ranged');
        $ctx = Conditions::attackContext($actor, $target);

        if ($ctx['forbidden']) {
            $state['log'][] = "{$actor['name']} cannot bring themselves to strike {$target['name']}.";
            return $state;
        }

        $extraAdv = !empty($shot['flank']);
        // Reckless Attack, both halves. The Barbarian swings with advantage,
        // and everything swinging back at them has it too — which is why the
        // second half is read off the TARGET rather than the attacker.
        if (!empty($actor['reckless']) && ($actor['attack_kind'] ?? 'melee') === 'melee') {
            $extraAdv = true;
        }
        if (!empty($target['reckless'])) {
            $extraAdv = true;
        }
        $extraDis = !empty($target['dodging'])
            || !empty($shot['long'])
            || !empty($shot['crowded']);

        if (!empty($actor['helped'])) {
            $extraAdv = true;
            $state = $this->updateCombatant($state, $actorCid, ['helped' => null]);
        }
        // Pack Tactics: advantage while an ally is also on the target. This
        // reads better on a grid than it ever did on ranks — the SRD wording is
        // literally "an ally within 5 feet of the creature", which is now what
        // it checks rather than what it approximated.
        if (self::hasTrait($actor, 'pack tactics') && $this->packSupport($state, $actor, $target)) {
            $extraAdv = true;
        }

        $mode = self::rollMode($ctx, $extraAdv, $extraDis);
        // A player who asked to roll their own attacks has already thrown this
        // one through CheckService, so the number arrives rather than being
        // made here. Rolling again would discard the die they watched land, and
        // any boost they spent on it.
        $roll = $prerolled ?? Dice::d20Mode((int) $weapon['bonus'], $mode);

        // Halfling Luck: a natural 1 thrown again, the new die standing. Never
        // on a prerolled attack — the player watched that die land, and taking
        // it back would be rolling their roll for them.
        if ($prerolled === null
            && (int) $roll['natural'] === 1
            && Rules::raceFeature($actor, 'halfling_luck')) {
            $roll = Dice::d20Mode((int) $weapon['bonus'], 'normal');
            $state['log'][] = "{$actor['name']}'s luck turns the die over.";
        }

        // Bless and its kin ride on top of whatever was rolled — including a
        // prerolled attack the player watched land, whose d20 must not be
        // thrown away just because a bonus applies to it.
        $boonAtk = $this->boonBonus($actor, 'attack_bonus_dice');
        if ($boonAtk > 0) {
            $roll['total'] = (int) $roll['total'] + $boonAtk;
        }

        $cover = (int) ($shot['cover'] ?? 0);
        $ac = self::effectiveAc($target, $cover);
        $hit = !$roll['fumble'] && ($roll['crit'] || $roll['total'] >= $ac);

        // Shield, cast for the player the moment it earns its slot: a hit
        // that +5 would turn into a miss, on a reaction they still hold, from
        // a slot they still have. Never on a natural 20 (nothing turns those)
        // and never wasted on a blow that would have hit anyway — which is
        // exactly how anybody would play it, and the same
        // server-perfect-information reasoning Uncanny Dodge states below.
        if ($hit && !$roll['crit']
            && $target['type'] === 'pc'
            && empty($target['reaction_used'])
            && $roll['total'] < $ac + 5) {
            $shieldState = $this->autoShield($state, $target);
            if ($shieldState !== null) {
                $state = $shieldState;
                $target = $this->getCombatant($state, $targetCid) ?? $target;
                $ac += 5;
                $hit = false;
            }
        }

        // Improved Critical widens the window but does not widen the hit: a
        // Champion's natural 19 that misses the armour class is still a miss,
        // it just would have been a critical had it landed.
        $crit = $roll['crit']
            || ($hit && !empty($ctx['auto_crit']))
            || ($hit && (int) $roll['natural'] >= self::critFloor($actor));

        // Cover, distance and long range ride on the attack event rather than
        // getting events of their own. Cover is not a thing that happens, it is
        // a modifier on the thing that happened, and an event type per modifier
        // is a new event for every rule.
        $geometry = [
            'cover' => $cover,
            'dist'  => (int) ($shot['dist'] ?? 0),
            'long'  => !empty($shot['long']),
            // Whether the blow crossed the board or landed where it stood. The
            // board draws a bolt for one and not the other, and it must not
            // work that out from `dist`: a halberd reaching ten feet is not a
            // shot, and a thrown javelin at ten feet is. This is the same
            // `attack_kind` the condition rules were already given above, said
            // out loud instead of being inferred from a number.
            'kind'  => $actor['attack_kind'],
            // Where from and where to, in cells, so the bolt has somewhere to
            // start. The token positions are on the state as well, but a walk
            // that happened earlier in the same batch of events would have
            // already moved them by the time this one plays.
            'from'  => [(int) ($actor['x'] ?? 0), (int) ($actor['y'] ?? 0)],
            'to'    => [(int) ($target['x'] ?? 0), (int) ($target['y'] ?? 0)],
        ];

        // "You give away your position when you attack." The roll above already
        // took the advantage for being unseen, which is the order the SRD puts
        // them in — you strike from hiding and are seen afterwards, hit or
        // miss. Done here rather than in doHide()'s neighbourhood because an
        // opportunity attack reveals you just as surely as a chosen one.
        if (Conditions::has($actor, 'invisible')) {
            $state = $this->removeCondition($state, $actorCid, 'invisible');
            $state['log'][] = "{$actor['name']} breaks cover.";
        }

        if (!$hit) {
            $because = $cover > 0 ? " ({$target['name']} is in cover, +{$cover})" : '';
            $state['log'][] = "{$actor['name']} attacks {$target['name']}: {$roll['total']} vs AC {$ac}{$because} — miss.";
            return $this->emit($state, [
                'type' => 'attack', 'actor' => $actorCid, 'target' => $targetCid,
                'hit' => false, 'crit' => false, 'natural' => $roll['natural'],
                'total' => $roll['total'], 'ac' => $ac, 'damage' => 0,
                'damage_type' => $weapon['damage_type'], 'mode' => $mode,
            ] + $geometry);
        }

        // Criticals double the dice and leave the modifier alone. The feats
        // that touch damage all touch the dice rather than the total — a
        // reroll, a second attempt, an extra die — so they are applied to the
        // roll before anything is added to it, and the state comes back with
        // whatever once-per-turn allowance they spent.
        $damaged = $this->featDamageRoll($state, $actorCid, $weapon, $crit);
        $state = $damaged['state'];
        $dmg = $damaged['roll'];

        // Savage Attacks: one more weapon die on a melee critical, into the
        // dice rather than the total for the same resistance reasoning as
        // Sneak Attack below.
        if ($crit
            && ($actor['attack_kind'] ?? 'melee') === 'melee'
            && Rules::raceFeature($actor, 'savage_attacks')) {
            $sides = self::dieSides((string) $weapon['damage']);
            if ($sides > 0) {
                $dmg['rolls'][] = (int) Dice::rollDetailed('1d' . $sides)['total'];
                $dmg = self::retotal($dmg);
                $damaged['notes'][] = "{$actor['name']} strikes savagely — an extra die rides the critical.";
            }
        }

        // Colossus Slayer: +1d8 once a turn against a target already below
        // its maximum, folded into the dice for the resistance reasoning
        // below. The feat_turn ledger already clears at the top of the
        // actor's own turn, which is exactly the SRD's "once per turn".
        if (Rules::subclassFeature($actor, 'colossus_slayer')
            && (int) $target['hp'] < (int) $target['max_hp']
            && !self::featSpent($actor, 'colossus_slayer')) {
            $state = $this->spendFeatTurn($state, $actorCid, 'colossus_slayer');
            $dmg['rolls'][] = (int) Dice::rollDetailed($crit ? '2d8' : '1d8')['total'];
            $dmg = self::retotal($dmg);
            $damaged['notes'][] = "{$actor['name']} finds the wound and widens it.";
        }

        // Sneak Attack, folded into the dice rather than added to the total,
        // so a resistant target halves the whole blow — the same reasoning the
        // Hunter's Mark line below spells out.
        $sneak = $this->sneakAttack($state, $actorCid, $targetCid, $weapon, $mode, $crit);
        if ($sneak !== null) {
            $state = $sneak['state'];
            $dmg['rolls'] = array_merge($dmg['rolls'], $sneak['roll']['rolls']);
            $dmg = self::retotal($dmg);
            $damaged['notes'][] = $sneak['note'];
        }

        // Hunter's Mark: extra dice on every hit, added before resistances so
        // a resistant target halves the whole blow rather than only part of it.
        $raw = max(0, $dmg['total']) + $this->boonBonus($actor, 'damage_bonus_dice');
        $after = Rules::damageAfterDefenses($raw, $weapon['damage_type'], $target);
        $amount = $after['amount'];

        // An enchanted weapon's rider — "1d4 fire" — rolled apart from the
        // blade's own dice because it is a different damage type with its own
        // resistances: a fire-proof target shrugs the flame and still takes
        // the steel. Dice double on a critical like any other damage dice.
        // An oil boon rides the same way, off whatever weapon the hit used.
        $riderSpecs = [];
        if (!empty($weapon['on_hit'])) {
            $riderSpecs[] = (string) $weapon['on_hit'];
        }
        foreach ((array) ($actor['boons'] ?? []) as $held) {
            if (!empty($held['on_hit']) && is_string($held['on_hit'])) {
                $riderSpecs[] = $held['on_hit'];
            }
        }
        $riderNote = '';
        foreach ($riderSpecs as $spec) {
            $rider = self::parseOnHit($spec);
            if ($rider === null) {
                continue;
            }
            $riderRoll = (int) Dice::rollDetailed($rider['dice'], $crit)['total'];
            $riderAfter = Rules::damageAfterDefenses($riderRoll, $rider['type'], $target);
            if ($riderAfter['amount'] > 0) {
                $amount += $riderAfter['amount'];
                $riderNote .= " (+{$riderAfter['amount']} {$rider['type']})";
            } elseif ($riderAfter['note'] !== null) {
                $riderNote .= " ({$target['name']} is {$riderAfter['note']})";
            }
        }

        // A held Divine Smite, spent now because now is when the blow landed.
        // Radiant, and rolled apart from the weapon's dice for the same reason
        // the riders above are: it is a different damage type and carries its
        // own resistances. Before Uncanny Dodge, because halving the attack's
        // damage halves all of it — the Smite is part of the blow, not a second
        // one.
        $smite = $this->spendSmite($state, $actorCid, $target, (string) $actor['attack_kind']);
        if ($smite !== null) {
            $state = $smite['state'];
            $smiteAfter = Rules::damageAfterDefenses((int) $smite['roll']['total'], 'radiant', $target);
            $amount += $smiteAfter['amount'];
            $riderNote .= " (+{$smiteAfter['amount']} radiant)";
            $damaged['notes'][] = $smite['note'];
        }

        // Uncanny Dodge: "when an attacker you can see hits you, you can use
        // your reaction to halve the attack's damage". Applied here, before the
        // log line and before the event — both of them state the number, and a
        // board that animated the full blow and then took half would be showing
        // the player something that did not happen.
        //
        // Taken automatically rather than offered. There is nowhere to ask
        // mid-swing, and a rogue holding the reaction for an opportunity attack
        // they may never get is not a choice worth stopping the fight for.
        $dodge = $this->uncannyDodge($state, $targetCid, $amount);
        $state = $dodge['state'];
        $amount = $dodge['amount'];
        $target = $this->getCombatant($state, $targetCid) ?? $target;

        $verb = $crit ? 'CRITS' : 'hits';
        $note = $after['note'] === null ? '' : " ({$target['name']} is {$after['note']})";
        $inCover = $cover > 0 ? " through cover (+{$cover})" : '';
        $state['log'][] = "{$actor['name']} {$verb} {$target['name']}{$inCover}: {$roll['total']} vs AC {$ac},"
            . " {$amount} {$weapon['damage_type']} damage{$note}{$riderNote}.";
        foreach ($damaged['notes'] as $line) {
            $state['log'][] = $line;
        }

        // The hit point total the target is left on, carried on the event so the
        // client can step its bar down as each blow plays rather than snapping
        // every bar to the end of the round before the animation starts. The
        // same clamp takeDamage applies, computed here because the event has to
        // be emitted before the death event takeDamage may queue behind it.
        $state = $this->emit($state, [
            'type' => 'attack', 'actor' => $actorCid, 'target' => $targetCid,
            'hit' => true, 'crit' => $crit, 'natural' => $roll['natural'],
            'total' => $roll['total'], 'ac' => $ac, 'damage' => $amount,
            'damage_type' => $weapon['damage_type'], 'mode' => $mode,
            'target_hp' => max(0, (int) $target['hp'] - $amount),
            'target_max_hp' => (int) $target['max_hp'],
        ] + $geometry);

        // The crit travels with the blow: striking somebody who is already down
        // is one death save failure, or two if it was a critical.
        $state = $this->takeDamage($state, $targetCid, $amount, $crit);

        // Dark One's Blessing: a Fiend warlock who drops a foe walks away
        // warded. Read after takeDamage because "reduce to 0" is the trigger,
        // and hooked here rather than inside takeDamage because only the
        // attack knows who struck the blow — a kill by spell attack (Eldritch
        // Blast's route) lands here too; one by a failed save does not, which
        // is a known narrowing rather than an accident.
        $felled = $this->getCombatant($state, $targetCid);
        if ($felled
            && $felled['side'] !== $actor['side']
            && (int) $felled['hp'] <= 0
            && $actor['type'] === 'pc'
            && Rules::subclassFeature($actor, 'dark_ones_blessing')) {
            $ward = max(1, Rules::abilityMod((int) ($actor['cha'] ?? 10)) + (int) ($actor['level'] ?? 1));
            $state['log'][] = "{$actor['name']}'s patron takes notice of the kill.";
            $state = $this->grantTempHp($state, $actorCid, $ward);
        }

        // Hellish Rebuke, cast back at whoever drew blood: still standing,
        // reaction unspent, a slot to burn. Auto-cast on the same
        // server-perfect-information footing as Shield above — the one thing
        // a player would always do with it is exactly this.
        $struck = $this->getCombatant($state, $targetCid);
        if ($struck
            && $struck['type'] === 'pc'
            && self::isTargetable($struck)
            && !self::isDying($struck)
            && empty($struck['reaction_used'])
            && $struck['side'] !== $actor['side']) {
            $rebuke = $this->knownReactionSpell($struck, 'hellish_rebuke');
            if ($rebuke !== null && $this->payReactionSlot($struck) !== null) {
                $state = $this->updateCombatant($state, $targetCid, ['reaction_used' => true]);
                $state['log'][] = "{$struck['name']} answers in hellfire — Hellish Rebuke.";
                $state = $this->emit($state, [
                    'type' => 'cast', 'actor' => $targetCid, 'spell' => 'Hellish Rebuke',
                    'level' => 1, 'cast_level' => 1, 'targets' => [$actorCid],
                ]);
                $state = $this->applySpellTo(
                    $state,
                    $targetCid,
                    $actorCid,
                    $rebuke,
                    (string) ($rebuke['resolution'] ?? 'save')
                );
            }
        }

        return $this->applyAttackRider($state, $targetCid, $weapon);
    }

    /**
     * The saving throw a hit forces, and what failing it costs.
     *
     * A monster action may carry `save` and `on_fail` beside its damage — the
     * wolf's bite that knocks a target prone, the carrion crawler's tentacles
     * that poison. Every one of those was authored as prose in the action's
     * `note` and read by nothing, so no monster in the game could inflict a
     * condition at all and the board's condition pips had almost nothing to
     * draw. This is the one place that changes it, and it mirrors what
     * applySpellTo() already does on a spell's save: roll, emit, and add the
     * condition on a failure.
     *
     * A rider fires only on a hit, which is how the notes are all worded.
     */
    private function applyAttackRider(array $state, string $targetCid, array $weapon): array
    {
        $save = $weapon['save'] ?? null;
        $onFail = $weapon['on_fail'] ?? null;
        if (!is_array($save) || !is_array($onFail)) {
            return $state;
        }
        $condition = strtolower(trim((string) ($onFail['condition'] ?? '')));
        if (!isset(Conditions::CATALOG[$condition])) {
            return $state;
        }
        // The blow that felled somebody is the last thing that happens to them.
        // Stacking prone onto a character already lying unconscious is noise in
        // the log and a second pip that means nothing.
        $target = $this->getCombatant($state, $targetCid);
        if (!$target || !self::isTargetable($target)) {
            return $state;
        }

        $ability = strtolower(trim((string) ($save['ability'] ?? ''))) ?: 'dex';
        $dc = max(1, (int) ($save['dc'] ?? 10));
        $result = $this->rollSave($target, $ability, $dc, false, [
            'condition'   => $condition,
            'damage_type' => strtolower((string) ($weapon['damage_type'] ?? '')),
        ]);
        $state = $this->emit($state, [
            'type' => 'save', 'actor' => $targetCid, 'ability' => $ability, 'dc' => $dc,
            'natural' => $result['natural'], 'total' => $result['total'], 'passed' => $result['passed'],
        ]);
        if ($result['passed']) {
            $state['log'][] = "{$target['name']} shrugs it off ({$result['total']} vs DC {$dc}).";
            return $state;
        }

        $rounds = (int) ($onFail['rounds'] ?? 0);
        $condition = ['key' => $condition, 'rounds' => $rounds > 0 ? $rounds : null];
        // The repeat save is opt-in, because Conditions::tickStart offers one
        // for any condition carrying a DC and most of these should not have it:
        // being poisoned is shaken off by saving again, being knocked prone is
        // not — you get up, which here is what letting the round lapse means.
        if (!empty($onFail['repeat_save'])) {
            $condition['save_ability'] = $ability;
            $condition['save_dc'] = $dc;
        }
        return $this->addCondition($state, $targetCid, $condition);
    }

    /**
     * The attack a combatant makes with what it is holding.
     *
     * `tags` is what the feats read. It is the weapon's own properties plus the
     * two facts the properties do not state outright — whether the swing is
     * melee or ranged, and whether it is made with a fist. Archery wants
     * `ranged`, Duelling wants `one_handed`, Great Weapon Master wants `heavy`,
     * and none of them should be reaching back into the `items` row to find
     * out; a tag list computed once here is the only description of the weapon
     * the rest of the turn needs.
     *
     * @return array{name:string, bonus:int, damage:string, damage_type:string,
     *               reach:string, tags:string[]}
     */
    public function weaponFor(array $actor, ?int $actionIndex = null): array
    {
        if ($actor['type'] === 'monster') {
            $actions = $actor['actions'] ?? [];
            $atk = $actions[$actionIndex ?? 0] ?? ['name' => 'Strike', 'bonus' => 2, 'damage' => '1d6'];
            $reach = ($atk['type'] ?? 'melee') === 'ranged' ? 'ranged' : 'melee';
            return [
                'name'        => (string) ($atk['name'] ?? 'Strike'),
                'bonus'       => (int) ($atk['bonus'] ?? 2),
                'damage'      => (string) ($atk['damage'] ?? '1d6'),
                'damage_type' => (string) ($atk['damage_type'] ?? 'bludgeoning'),
                'reach'       => $reach,
                'range'       => self::monsterActionRange($actor, $atk),
                // A monster holds no feats, so its tags exist only so that
                // every weapon in the engine has the same shape.
                'tags'        => [$reach],
                // The rider a hit forces: a save, and what failing it costs.
                // Optional, and absent from every player weapon — resolveAttack
                // simply finds nothing to apply. See applyAttackRider().
                'save'        => is_array($atk['save'] ?? null) ? $atk['save'] : null,
                'on_fail'     => is_array($atk['on_fail'] ?? null) ? $atk['on_fail'] : null,
            ];
        }

        return $this->buildWeapon($actor, $this->getEquippedWeapon((int) $actor['character_id']));
    }

    /**
     * One weapon in one hand, priced for this character.
     *
     * Split out of weaponFor() so that the SAME arithmetic can be pointed at a
     * second item — the off-hand of a two-weapon fighter, and the fist a Monk
     * throws after their Attack action. Both of those needed the whole of what
     * is below (proficiency, feats, enchantments, tags) and differ from the
     * main hand in one term each, which is what `$opts` carries:
     *
     *   offhand — no ability modifier on the damage. The SRD's whole cost for
     *             the extra swing, and the reason this could not simply reuse
     *             weaponFor(): the modifier is folded into `damage` as a
     *             string by the time that function returns.
     *
     * Martial Arts is not an option because it is not a choice: it applies to
     * whatever a qualifying Monk is holding, in either hand.
     *
     * @param array|null $weapon an `items` row, or null for an unarmed strike
     */
    private function buildWeapon(array $actor, ?array $weapon, array $opts = []): array
    {
        $strMod = Rules::abilityMod((int) $actor['str']);
        $dexMod = Rules::abilityMod((int) $actor['dex']);

        $name = 'Unarmed strike';
        $damageExpr = '1d4';
        $damageType = 'bludgeoning';
        $ranged = false;
        $finesse = false;
        $tags = [];

        if ($weapon) {
            $props = Rules::weaponProperties($weapon);
            $name = (string) $weapon['name'];
            $damageExpr = $weapon['damage_dice'] ?: '1d6';
            $damageType = $weapon['damage_type'] ?: 'slashing';
            // Both spellings, because the content uses one and the engine used
            // to look for the other: the Shortbow says {"ranged":"80/320"} and
            // sets no `ammunition`, so every bow in the game was being swung
            // with Strength and could not reach past the enemy front rank.
            // Rules::weaponAttack already reads it this way for the printed
            // character sheet, and a sheet that disagreed with the fight is
            // worse than either being wrong alone.
            $ranged = !empty($props['ammunition']) || !empty($props['ranged']);
            $finesse = !empty($props['finesse']);

            foreach (['heavy', 'two_handed', 'versatile', 'finesse', 'light'] as $property) {
                if (!empty($props[$property])) {
                    $tags[] = $property;
                }
            }
            // Duelling's "one hand, and nothing in the other". A character
            // equips one weapon here — getEquippedWeapon takes the first — so
            // the empty hand is a given and only the grip has to be judged. A
            // versatile weapon counts as one-handed because `damage_dice` is
            // its one-handed die; the two-handed die is in `properties` and
            // nothing rolls it.
            if (!$ranged && empty($props['two_handed'])) {
                $tags[] = 'one_handed';
            }
        } else {
            $tags[] = 'unarmed';
            // Tavern Brawler and Unarmed Fighting both make a fist worth more
            // than 1d4. Feats::bestDice picks the better rather than adding
            // them, because a character with both has one pair of hands.
            $damageExpr = Feats::bestDice($actor, 'unarmed_damage') ?? $damageExpr;
        }

        $tags[] = $ranged ? 'ranged' : 'melee';

        // Martial Arts, which changes two things about whatever a Monk is
        // holding: it may be swung with Dexterity, and the fist is worth more
        // than 1d4. The die is the LARGER of the two, never the sum — a
        // shortsword stays a d6 at first level and becomes the martial arts die
        // only when that overtakes it.
        $monk = Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'martial_arts')
            && Rules::isMonkWeapon($weapon);
        if ($monk) {
            $finesse = true;
            $tags[] = 'monk';
            $maDie = Rules::martialArtsDie((int) ($actor['level'] ?? 1));
            if (self::dieSides($damageExpr) < $maDie) {
                $damageExpr = '1d' . $maDie;
            }
        }

        // The SRD picks the ability, not the class: ranged weapons use
        // Dexterity, finesse weapons use whichever is better, everything else
        // uses Strength.
        $mod = $ranged ? $dexMod : (($finesse && $dexMod > $strMod) ? $dexMod : $strMod);

        $prof = (int) $actor['prof'];
        $bonus = $mod + $prof + self::featBonus($actor, 'attack_bonus', $tags);
        // "You don't add your ability modifier to the damage of the bonus
        // attack." The attack ROLL still gets it — only the damage is bare.
        $damageMod = (empty($opts['offhand']) ? $mod : 0)
            + self::featBonus($actor, 'damage_bonus', $tags);

        // The enchantment on the blade itself: a +1 weapon carries
        // {"attack_bonus":1,"damage_bonus":1} in its properties, and a flaming
        // one an `on_hit` rider that resolveAttack rolls separately so its
        // damage type keeps its own resistances.
        $onHit = null;
        if ($weapon) {
            $props = Rules::weaponProperties($weapon);
            $bonus += (int) ($props['attack_bonus'] ?? 0);
            $damageMod += (int) ($props['damage_bonus'] ?? 0);
            $onHit = isset($props['on_hit']) && is_string($props['on_hit']) ? $props['on_hit'] : null;
        }
        // Great Weapon Master's Hew: the proficiency bonus, on top, for a
        // weapon whose tags it names.
        foreach (Feats::strings($actor, 'damage_bonus_proficiency') as $tag) {
            if (in_array($tag, $tags, true)) {
                $damageMod += $prof;
            }
        }

        return [
            'name'        => $name,
            'bonus'       => $bonus,
            'damage'      => $damageExpr . sprintf('%+d', $damageMod),
            'damage_type' => $damageType,
            'reach'       => $ranged ? 'ranged' : 'melee',
            // The numbers, from the same `properties` the tags came out of. A
            // thrown weapon comes back with a reach *and* a range and takes
            // whichever suits the distance.
            'range'       => Rules::weaponRange($weapon),
            'tags'        => $tags,
            'on_hit'      => $onHit,
        ];
    }

    /**
     * How far a monster's action carries.
     *
     * The content has said `"range": "80/320"` on every ranged monster action
     * since the monsters were written, and nothing has ever read it. A melee
     * action gets a reach instead: ten feet for anything Large or bigger, which
     * is the SRD's rule and the only use `monsters.size` has ever been put to.
     *
     * @return array{reach:?int, normal:?int, long:?int}
     */
    private static function monsterActionRange(array $actor, array $action): array
    {
        $text = (string) ($action['range'] ?? '');

        if (($action['type'] ?? 'melee') === 'ranged') {
            $r = Rules::parseRangeText($text);
            return [
                'reach'  => null,
                'normal' => $r['normal'] ?? 30,
                'long'   => $r['long'] ?? $r['normal'] ?? 120,
            ];
        }

        $reach = self::DEFAULT_REACH_FT;
        if (in_array(strtolower(trim((string) ($actor['size'] ?? ''))), self::LARGE_SIZES, true)) {
            $reach = 10;
        }
        // An authored reach on the action wins over the size rule.
        if ($text !== '') {
            $reach = Rules::parseRangeText($text)['normal'] ?? $reach;
        }
        return ['reach' => $reach, 'normal' => null, 'long' => null];
    }

    /**
     * The melee weapon a combatant threatens with, or null if they have none.
     *
     * Opportunity attacks need this and nothing else does. It matters that it
     * can answer null: `weaponFor($actor, 0)` hands back action index zero,
     * which for a goblin happens to be the scimitar and for the next monster
     * somebody writes might be a bow. A free ranged shot at somebody walking
     * past is not a reaction, it is a bug that looks like one.
     */
    private function meleeWeaponFor(array $actor): ?array
    {
        if (($actor['type'] ?? '') !== 'monster') {
            $weapon = $this->weaponFor($actor);
            return self::rangeOf($weapon)['reach'] === null ? null : $weapon;
        }
        foreach (array_keys((array) ($actor['actions'] ?? [])) as $index) {
            $weapon = $this->weaponFor($actor, (int) $index);
            if (self::rangeOf($weapon)['reach'] !== null) {
                return $weapon;
            }
        }
        // A stat block with no actions at all still gets weaponFor()'s default
        // strike, which is melee.
        if (empty($actor['actions'])) {
            return $this->weaponFor($actor, 0);
        }
        return null;
    }

    /** How far this combatant threatens, in feet. Zero if they threaten nothing. */
    private function meleeReachFt(array $actor): int
    {
        $weapon = $this->meleeWeaponFor($actor);
        return $weapon === null ? 0 : (int) (self::rangeOf($weapon)['reach'] ?? self::DEFAULT_REACH_FT);
    }

    /**
     * A keyed feat bonus, totalled over the tags this weapon actually carries.
     *
     * Summed rather than best-of: two feats that both improve ranged attacks
     * are two different feats and both were paid for. `Feats::best` collapses
     * duplicates of the same tag first, so a single feat naming a tag twice
     * cannot double itself.
     *
     * @param string[] $tags
     */
    public static function featBonus(array $actor, string $effect, array $tags): int
    {
        $total = 0;
        foreach (Feats::best($actor, $effect) as $tag => $value) {
            if (in_array($tag, $tags, true)) {
                $total += $value;
            }
        }
        return $total;
    }

    // -----------------------------------------------------------------------
    // Damage, as the feats leave it
    //
    // Public, for the reason the block at the top of this class is public: a
    // feat that changes a damage die has to be checkable without standing up a
    // fight, a party and a schema. tools/test_feats.php drives these directly.
    //
    // Every feat here changes the dice rather than the total, which is the
    // difference between "reroll a 1" and "+1 damage" and is the whole reason
    // this cannot be a number added at the end. Two of them are once per turn,
    // so the allowance is tracked on the combatant and cleared by beginTurn()
    // — a fight is the only span this engine measures and a turn is the only
    // span inside it.
    // -----------------------------------------------------------------------

    /**
     * Roll a weapon's damage with whatever the attacker's feats do to it.
     *
     * @return array{state:array, roll:array, notes:string[]}
     */
    public function featDamageRoll(array $state, string $actorCid, array $weapon, bool $crit): array
    {
        $expr = (string) $weapon['damage'];
        $actor = $this->getCombatant($state, $actorCid);

        // The overwhelmingly common case, and the one that must cost nothing:
        // a monster, or a character who has taken no feats at all.
        if (!$actor || Feats::taken($actor) === []) {
            return ['state' => $state, 'roll' => Dice::rollDetailed($expr, $crit), 'notes' => []];
        }

        $sides = self::dieSides($expr);
        $tags = (array) ($weapon['tags'] ?? []);
        $type = strtolower((string) ($weapon['damage_type'] ?? ''));
        $notes = [];

        // Great Weapon Fighting and Tavern Brawler: a die at or below this is
        // thrown again and the new number stands. The highest threshold wins
        // rather than the two applying in turn, because a die rerolled twice is
        // not what either feat says.
        $floor = 0;
        foreach (Feats::best($actor, 'damage_reroll_below') as $tag => $threshold) {
            if (in_array($tag, $tags, true)) {
                $floor = max($floor, (int) $threshold);
            }
        }

        $roll = self::rerollUnder(Dice::rollDetailed($expr, $crit), $sides, $floor);

        // Savage Attacker: the whole handful thrown a second time, and the
        // better of the two totals kept. Once on each of your turns.
        if ($sides > 0
            && Feats::flag($actor, 'damage_reroll_best')
            && !self::featSpent($actor, 'damage_reroll_best')) {
            $state = $this->spendFeatTurn($state, $actorCid, 'damage_reroll_best');
            $again = self::rerollUnder(Dice::rollDetailed($expr, $crit), $sides, $floor);
            if ($again['total'] > $roll['total']) {
                $roll = $again;
            }
            $notes[] = "{$actor['name']} rolls the damage twice and takes the better of it.";
        }

        // Piercer: one die of a piercing hit thrown again. The SRD lets the
        // attacker choose which; the lowest is chosen here, because choosing
        // any other one is choosing to do less damage and there is nobody to
        // ask.
        if ($sides > 0
            && $type !== ''
            && $roll['rolls'] !== []
            && in_array($type, Feats::strings($actor, 'damage_reroll_one'), true)
            && !self::featSpent($actor, 'damage_reroll_one')) {
            $state = $this->spendFeatTurn($state, $actorCid, 'damage_reroll_one');
            $worst = array_keys($roll['rolls'], min($roll['rolls']), true)[0];
            $roll['rolls'][$worst] = (int) Dice::rollDetailed('1d' . $sides)['total'];
            $roll = self::retotal($roll);
            $notes[] = "{$actor['name']} works the point in and rolls one die again.";
        }

        // Piercer again, on a critical: one more die on top of the doubled set.
        if ($crit && $sides > 0 && $type !== ''
            && in_array($type, Feats::strings($actor, 'crit_extra_die'), true)) {
            $roll['rolls'][] = (int) Dice::rollDetailed('1d' . $sides)['total'];
            $roll = self::retotal($roll);
        }

        return ['state' => $state, 'roll' => $roll, 'notes' => $notes];
    }

    /** Throw again every die at or below `$floor`, keeping the new number. */
    public static function rerollUnder(array $roll, int $sides, int $floor): array
    {
        if ($floor < 1 || $sides < 1) {
            return $roll;
        }
        $changed = false;
        foreach ($roll['rolls'] as $i => $face) {
            if ((int) $face <= $floor) {
                $roll['rolls'][$i] = (int) Dice::rollDetailed('1d' . $sides)['total'];
                $changed = true;
            }
        }
        return $changed ? self::retotal($roll) : $roll;
    }

    /** Re-add a roll whose individual dice have been meddled with. */
    private static function retotal(array $roll): array
    {
        $roll['dice_total'] = array_sum($roll['rolls']);
        $roll['total'] = $roll['dice_total'] + (int) ($roll['modifier'] ?? 0);
        return $roll;
    }

    /** The die size in `2d6+3`, or 0 for an expression that rolls nothing. */
    public static function dieSides(string $expr): int
    {
        return preg_match('/\dd(\d+)/', strtolower(str_replace(' ', '', $expr)), $m)
            ? (int) $m[1]
            : 0;
    }

    /**
     * A roll that restores hit points, with the Healer feat applied.
     *
     * "Whenever you roll a die to restore hit points" is broad on purpose in
     * the SRD, so every place this engine rolls healing goes through here: a
     * cure spell, a potion, a Fighter's Second Wind. The reroll is once per
     * die and the new number stands, win or lose — this is not "reroll until
     * it is good".
     *
     * `$healer` is whoever is doing the restoring, which for a potion is the
     * person drinking it and for a spell is the caster, not the patient.
     */
    public static function healingRoll(array $healer, string $expr): array
    {
        $roll = Dice::rollDetailed($expr);
        if (!Feats::flag($healer, 'heal_reroll_ones')) {
            return $roll;
        }
        return self::rerollUnder($roll, self::dieSides($expr), 1);
    }

    /**
     * The spells row for a reaction spell this character knows, or null.
     * A DB read mid-fight, deliberately: knowing a spell is not combat state,
     * and doCast reads the same join for the same reason.
     */
    private function knownReactionSpell(array $combatant, string $spellKey): ?array
    {
        $characterId = (int) ($combatant['character_id'] ?? 0);
        if ($characterId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ? AND s.spell_key = ?'
        );
        $stmt->execute([$characterId, $spellKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Whether this combatant can pay a level-1 reaction spell right now, and
     * the burn if so. Returns the slot level spent, or null for cannot.
     */
    private function payReactionSlot(array $combatant, int $spellLevel = 1): ?int
    {
        $characterId = (int) ($combatant['character_id'] ?? 0);
        $class = (string) ($combatant['class'] ?? '');
        $level = (int) ($combatant['level'] ?? 1);
        if (self::slotsAvailable($this->slotsExpended($characterId), $class, $level, $spellLevel) < 1) {
            return null;
        }
        $burn = Rules::slotLevelSpent($class, $level, $spellLevel);
        $this->spendSlot($characterId, $burn);
        return $burn;
    }

    /**
     * Shield, as a reaction. The caller has already judged the trigger — this
     * checks the book and the slot, spends both halves, and hangs the boon.
     */
    private function autoShield(array $state, array $target): ?array
    {
        if ($this->knownReactionSpell($target, 'shield') === null) {
            return null;
        }
        if ($this->payReactionSlot($target) === null) {
            return null;
        }
        $state = $this->updateCombatant($state, (string) $target['cid'], ['reaction_used' => true]);
        $state['log'][] = "{$target['name']} throws up a shimmering ward — Shield turns the blow.";
        return $this->addBoon($state, (string) $target['cid'], [
            'key' => 'shield', 'label' => 'Shield', 'ac' => 5, 'rounds' => 1,
        ]);
    }

    /**
     * "1d4 fire" → dice and type. Null for anything it cannot read, so a
     * mis-authored rider is a rider that does nothing rather than a fatal.
     */
    public static function parseOnHit(string $spec): ?array
    {
        if (!preg_match('/^\s*(\d+d\d+(?:[+-]\d+)?)\s+([a-z]+)\s*$/i', $spec, $m)) {
            return null;
        }
        return ['dice' => strtolower($m[1]), 'type' => strtolower($m[2])];
    }

    /** The natural roll a critical starts at: 20, or 19 for a Champion. */
    private static function critFloor(array $actor): int
    {
        return Rules::subclassFeature($actor, 'improved_critical') ? 19 : 20;
    }

    private static function featSpent(array $actor, string $effect): bool
    {
        return in_array($effect, (array) ($actor['feat_turn'] ?? []), true);
    }

    private function spendFeatTurn(array $state, string $cid, string $effect): array
    {
        $actor = $this->getCombatant($state, $cid);
        $spent = (array) ($actor['feat_turn'] ?? []);
        $spent[] = $effect;
        return $this->updateCombatant($state, $cid, ['feat_turn' => array_values(array_unique($spent))]);
    }

    /**
     * How many d6 a Rogue's Sneak Attack adds.
     *
     * 1d6 at 1st and another at every odd level after it. Rules::MAX_LEVEL is
     * 6, so this tops out at 3d6 and there is no table worth writing.
     *
     * Rogue only. The `classes.features` row has advertised "Expertise, Sneak
     * Attack, Thieves' Cant" on every Rogue sheet since the game was seeded,
     * and until now nothing anywhere read it — a rogue's whole class was one
     * bonus action. Rules::expertiseCount() is the other half of that row.
     */
    public static function sneakAttackDice(string $class, int $level): int
    {
        return $class === 'Rogue' ? intdiv(max(1, $level) + 1, 2) : 0;
    }

    /**
     * The extra dice a Rogue lands when the opening is there, or null.
     *
     * Two conditions, and they are the SRD's: advantage on the attack, or an
     * ally of yours within reach of the target while you do not have
     * disadvantage. Both are asked of things the engine had already worked out
     * — `$mode` is the resolved attack mode, with conditions, cover, dodging,
     * flanking, Help and Pack Tactics already folded in, and packSupport() is
     * the same "an ally within 5 feet of the target" check Pack Tactics uses.
     * Nothing here re-derives a rule that exists above it.
     *
     * ONCE PER TURN, AND NOT ONCE PER *YOUR* TURN. The SRD says "once per
     * turn", which is what lets a rogue sneak-attack on their own turn and
     * again on an opportunity attack during somebody else's. That is why this
     * stamps the round and turn index rather than using the `feat_turn` ledger
     * beside it: `feat_turn` is cleared when the actor's own turn begins, which
     * is right for Savage Attacker ("once on each of your turns") and would
     * silently forbid the reaction case here.
     *
     * @return array{state:array, roll:array, note:string}|null
     */
    private function sneakAttack(
        array $state,
        string $actorCid,
        string $targetCid,
        array $weapon,
        string $mode,
        bool $crit
    ): ?array {
        $actor = $this->getCombatant($state, $actorCid);
        $target = $this->getCombatant($state, $targetCid);
        if (!$actor || !$target || ($actor['type'] ?? '') !== 'pc') {
            return null;
        }

        $dice = self::sneakAttackDice((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1));
        if ($dice <= 0) {
            return null;
        }

        // "A finesse or a ranged weapon" — the tags weaponFor() already builds,
        // rather than a second reading of the item's properties.
        $tags = (array) ($weapon['tags'] ?? []);
        if (!in_array('finesse', $tags, true) && !in_array('ranged', $tags, true)) {
            return null;
        }

        $stamp = ((int) ($state['round'] ?? 1)) . ':' . ((int) ($state['turn_index'] ?? 0));
        if ((string) ($actor['sneak_turn'] ?? '') === $stamp) {
            return null;
        }

        $opening = $mode === 'advantage'
            || ($mode !== 'disadvantage' && $this->packSupport($state, $actor, $target));
        if (!$opening) {
            return null;
        }

        $roll = Dice::rollDetailed($dice . 'd6', $crit);
        $state = $this->updateCombatant($state, $actorCid, ['sneak_turn' => $stamp]);

        $how = $mode === 'advantage' ? 'catches them unready' : 'strikes while they are pressed';
        return [
            'state' => $state,
            'roll'  => $roll,
            'note'  => "{$actor['name']} {$how} — sneak attack, {$roll['total']} of that damage.",
        ];
    }

    /**
     * Whether a living ally is also on this target — SRD Pack Tactics.
     *
     * "An ally of the creature is within 5 feet of the target" is the actual
     * wording, and it is now the actual check. The rank engine approximated it
     * with "an ally could also reach this target with the same reach", which
     * was as close as two ranks allowed.
     *
     * This and flanking now fire on similar geometry, which is fine: advantage
     * does not stack, so a wolf that is both flanking and packing rolls two
     * dice, not three.
     */
    private function packSupport(array $state, array $actor, array $target): bool
    {
        $terrain = $state['grid']['terrain'] ?? [];
        $tx = (int) ($target['x'] ?? 0);
        $ty = (int) ($target['y'] ?? 0);

        foreach (self::standing($state['combatants'], (string) $actor['side']) as $ally) {
            if ($ally['cid'] === $actor['cid'] || !Conditions::canAct($ally)) {
                continue;
            }
            $reach = BattleGrid::ftToCells((int) ($ally['reach_ft'] ?? self::DEFAULT_REACH_FT));
            $ax = (int) ($ally['x'] ?? 0);
            $ay = (int) ($ally['y'] ?? 0);
            if ($reach >= 1
                && BattleGrid::cells($ax, $ay, $tx, $ty) <= $reach
                && BattleGrid::losClear($terrain, $ax, $ay, $tx, $ty)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk somewhere.
     *
     * Costs feet rather than "the move", so a character can step out, swing and
     * step back within one allowance — splitting a move around an action falls
     * out of the budget for free, and the client simply posts `move` twice.
     */
    private function doMove(array $state, int $x, int $y): array
    {
        $actor = $this->acting($state, false);
        $budget = self::movementBudget($actor);
        if ($budget <= 0) {
            throw new InvalidArgumentException(self::whyStuck($actor));
        }
        if (!BattleGrid::inBounds($x, $y)) {
            throw new InvalidArgumentException('That is off the battlefield.');
        }

        $terrain = $state['grid']['terrain'] ?? [];
        $reach = $this->reachFor($state, $actor, $budget);
        $destKey = BattleGrid::keyOf($x, $y);

        if (!isset($reach[$destKey])) {
            throw new InvalidArgumentException(
                BattleGrid::passable($terrain, $x, $y)
                    ? 'That is further than ' . $actor['name'] . ' can go this turn.'
                    : 'Nothing walks there.'
            );
        }
        if (!$reach[$destKey]['end']) {
            throw new InvalidArgumentException('Somebody is already standing there.');
        }
        if ($destKey === BattleGrid::keyOf((int) $actor['x'], (int) $actor['y'])) {
            return $state;
        }

        // Getting up happens before the walk and is paid for out of the same
        // allowance, which is what makes the highlight honest: movementBudget()
        // has already taken the cost off, so the cells offered are the cells
        // reachable *after* standing.
        $state = $this->standIfProne($state, $actor);

        return $this->stepMove($state, (string) $actor['cid'], BattleGrid::pathTo($reach, $destKey), $terrain);
    }

    /**
     * The feet this creature may actually spend, after what is holding it.
     *
     * Two rules the board could not express until there was a board:
     *
     *   - Held in place is held in place. Grappled, restrained, paralysed,
     *     stunned and unconscious all set `speed_zero` in the catalogue, and
     *     nothing read it, so all five walked away at full speed.
     *   - Standing up costs half your speed. Charged here rather than as an
     *     action of its own, because the SRD does not make it one — and taken
     *     off the budget the reach highlight is drawn from, so a prone
     *     character is offered the shorter list they can actually walk.
     *
     * Half of *speed*, not half of what is left: a character who has already
     * spent ten feet and then been knocked down still pays fifteen to rise.
     */
    public static function movementBudget(array $actor): int
    {
        if (Conditions::rooted($actor)) {
            return 0;
        }
        $left = max(0, (int) ($actor['move_left'] ?? 0));
        if (Conditions::has($actor, 'prone')) {
            $left -= intdiv(max(0, (int) ($actor['speed'] ?? 30)), 2);
        }
        return max(0, $left);
    }

    /**
     * Halve a blow, if the target is a rogue with a reaction to spend on it.
     *
     * @return array{state:array, amount:int}
     */
    private function uncannyDodge(array $state, string $cid, int $amount): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c || $amount <= 0 || !empty($c['reaction_used'])) {
            return ['state' => $state, 'amount' => $amount];
        }
        if (!Rules::hasFeature((string) ($c['class'] ?? ''), (int) ($c['level'] ?? 1), 'uncanny_dodge')) {
            return ['state' => $state, 'amount' => $amount];
        }
        if (!Conditions::canAct($c)) {
            return ['state' => $state, 'amount' => $amount];
        }
        $halved = intdiv($amount, 2);
        $state = $this->updateCombatant($state, $cid, ['reaction_used' => true]);
        $state['log'][] = "{$c['name']} rolls with it — half of {$amount}.";
        $state = $this->emit($state, ['type' => 'uncanny', 'target' => $cid, 'from' => $amount]);
        return ['state' => $state, 'amount' => $halved];
    }

    /**
     * The feet a turn opens with.
     *
     * `speed` plus whatever a class adds to it. Fast Movement is the only one
     * today; the SRD hangs it on not wearing heavy armour, which nothing here
     * records, so a Barbarian gets it — the class is proficient in medium at
     * best and the ones who wear plate are not Barbarians.
     */
    public static function turnSpeed(array $actor): int
    {
        $speed = max(0, (int) ($actor['speed'] ?? 30));
        if (Rules::hasFeature(
            (string) ($actor['class'] ?? ''),
            (int) ($actor['level'] ?? 1),
            'fast_movement'
        )) {
            $speed += Rules::FAST_MOVEMENT_FT;
        }
        return $speed;
    }

    /**
     * How many swings one Attack action buys this combatant.
     *
     * A monster gets one. The SRD gives Multiattack to plenty of creatures and
     * not one stat block in this bestiary declares it — the `actions` array is
     * a list of what a creature *may* swing (a bandit's scimitar or its
     * crossbow), not a sequence it takes in turn — so a monster asking for two
     * would be inventing a rule its own stat block does not claim.
     */
    public static function attacksAllowed(array $actor): int
    {
        if (($actor['type'] ?? '') !== 'pc') {
            return 1;
        }
        return Rules::attacksPerAction(
            (string) ($actor['class'] ?? ''),
            (int) ($actor['level'] ?? 1)
        );
    }

    /** The sentence to refuse a move with, which is not always the same one. */
    private static function whyStuck(array $actor): string
    {
        if (Conditions::rooted($actor)) {
            return $actor['name'] . ' cannot move at all.';
        }
        if (Conditions::has($actor, 'prone')) {
            return $actor['name'] . ' has not the movement left to stand up.';
        }
        return $actor['name'] . ' has no movement left this turn.';
    }

    /** Rise, and pay for it. Nothing happens to somebody already on their feet. */
    private function standIfProne(array $state, array $actor): array
    {
        if (!Conditions::has($actor, 'prone')) {
            return $state;
        }
        $cost = intdiv(max(0, (int) ($actor['speed'] ?? 30)), 2);
        $state = $this->updateCombatant($state, (string) $actor['cid'], [
            'move_left' => max(0, (int) ($actor['move_left'] ?? 0) - $cost),
        ]);
        $state['log'][] = "{$actor['name']} gets up ({$cost} ft).";
        return $this->removeCondition($state, (string) $actor['cid'], 'prone');
    }

    /**
     * Where this actor may walk, with fear taken into account.
     *
     * BattleGrid stays pure geometry and knows nothing about conditions — that
     * separation is why a cone can be argued about in a test with no board
     * underneath it — so the one rule that is half condition and half distance
     * is applied here, on the way out.
     *
     * "Cannot willingly move closer" is read as the SRD writes it: a cell no
     * nearer to the thing you fear than you already are. Sideways is allowed,
     * away is allowed, and being frightened of two things forbids closing on
     * either.
     */
    private function reachFor(array $state, array $actor, ?int $budget = null): array
    {
        $terrain = $state['grid']['terrain'] ?? [];
        $budget ??= self::movementBudget($actor);
        $reach = BattleGrid::reachable($terrain, $state['combatants'], $actor, $budget);

        $sources = [];
        foreach (Conditions::fearedBy($actor) as $cid) {
            $feared = $this->getCombatant($state, $cid);
            if ($feared && self::isTargetable($feared) && $feared['x'] !== null) {
                $sources[] = $feared;
            }
        }
        if (!$sources) {
            return $reach;
        }

        $ax = (int) ($actor['x'] ?? 0);
        $ay = (int) ($actor['y'] ?? 0);
        foreach ($reach as $key => $entry) {
            [$x, $y] = BattleGrid::parseKey($key);
            foreach ($sources as $f) {
                $fx = (int) $f['x'];
                $fy = (int) $f['y'];
                if (BattleGrid::cells($x, $y, $fx, $fy) < BattleGrid::cells($ax, $ay, $fx, $fy)) {
                    unset($reach[$key]);
                    break;
                }
            }
        }
        return $reach;
    }

    /**
     * Walk a path one cell at a time, stopping if it goes wrong.
     *
     * Stepping rather than teleporting is what makes opportunity attacks
     * possible, and it is also what makes them stop the walk: a mover cut down
     * halfway across the room stays where they fell.
     *
     * @param string[] $path cell keys, origin first
     */
    private function stepMove(array $state, string $cid, array $path, array $terrain): array
    {
        if (count($path) < 2) {
            return $state;
        }
        $mover = $this->getCombatant($state, $cid);
        $walked = [[(int) $mover['x'], (int) $mover['y']]];
        $spent = 0;

        for ($i = 1; $i < count($path); $i++) {
            [$nx, $ny] = BattleGrid::parseKey($path[$i]);
            $mover = $this->getCombatant($state, $cid);
            if (!$mover || !self::isTargetable($mover)) {
                break;
            }
            $cx = (int) $mover['x'];
            $cy = (int) $mover['y'];

            $watchers = $this->reactors($state, $cid);
            $leaving = BattleGrid::threatenersOf($terrain, $watchers, $cx, $cy);
            $entering = BattleGrid::threatenersOf($terrain, $watchers, $nx, $ny);
            $swingers = empty($mover['disengaging'])
                ? $this->opportunists($state, $leaving, $entering)
                : [];

            // THE WALK SO FAR GOES ON THE BOARD BEFORE THE BLOW THAT STOPS IT.
            //
            // An opportunity attack happens partway through a move, and this
            // used to log the whole move after the whole walk — so the log read
            // "the rat lashes out … Kessa moves 30 feet", and the client, which
            // plays events in order, drew the bite while she was still standing
            // on her starting cell thirty feet away. The rule was right and had
            // always been right; the picture was a lie about when.
            //
            // Flushing here splits one move into the segments the interruption
            // actually cut it into. A move nothing interrupts still emits
            // exactly one event and one line, which is every move in most
            // fights — the split IS the information, so it only appears when
            // there is something to say.
            if ($swingers) {
                $state = $this->emitMove($state, $cid, $walked, $spent);
                $walked = [[$cx, $cy]];
                $spent = 0;
            }

            $state = $this->provoke(
                $state,
                $cid,
                $cx,
                $cy,
                $swingers,
                array_values(array_diff($entering, $leaving)),
                $terrain
            );

            $mover = $this->getCombatant($state, $cid);
            if (!$mover || !self::isTargetable($mover)) {
                // Dropped on the way. Whoever it was stops where they fell —
                // and the segment they had already walked is on the board, so
                // they fall where the blow caught them and not at the start.
                break;
            }

            $step = BattleGrid::enterCost($terrain, $nx, $ny) ?? 0;
            $spent += $step;
            $state = $this->updateCombatant($state, $cid, [
                'x'         => $nx,
                'y'         => $ny,
                'move_left' => max(0, (int) $mover['move_left'] - $step),
                'move_used' => true,
            ]);
            $walked[] = [$nx, $ny];
        }

        return $this->emitMove($state, $cid, $walked, $spent);
    }

    /**
     * One leg of a walk: the line and the event for it.
     *
     * A leg of nothing is not an event. stepMove() flushes whenever a step is
     * about to be interrupted, and a mover interrupted on their very first step
     * has gone nowhere yet — logging "moves 0 feet" before the blow would be a
     * line about something that did not happen.
     *
     * @param array<int,array{0:int,1:int}> $walked cells, origin first
     */
    private function emitMove(array $state, string $cid, array $walked, int $spent): array
    {
        if (count($walked) < 2) {
            return $state;
        }
        $mover = $this->getCombatant($state, $cid);
        $state['log'][] = "{$mover['name']} moves {$spent} feet.";
        return $this->emit($state, [
            'type'  => 'move',
            'actor' => $cid,
            'from'  => $walked[0],
            'to'    => $walked[count($walked) - 1],
            'path'  => $walked,
            'cost'  => $spent,
        ]);
    }

    /**
     * The free swings a step out of somebody's reach earns them.
     *
     * The SRD rule exactly: an enemy who threatens the cell being left and does
     * not threaten the cell being entered gets one attack. Circling somebody at
     * arm's length provokes nothing; walking away provokes. Disengaging
     * suppresses it entirely.
     *
     * Who swings and who is newly in reach are decided by stepMove() and handed
     * in, rather than worked out again here. It has to know before the step, so
     * that the ground already covered is on the board before the blow lands —
     * see the flush there — and a second computation of the same two sets is a
     * second thing to keep in agreement with the first.
     *
     * Order is initiative order rather than array order, so a walk across a
     * room is resolved the same way twice. Each reactor is re-read from state
     * inside the loop because resolveAttack() can drop the mover — and the
     * mover being gone is what stops the rest of the walk, up in stepMove().
     *
     * @param string[] $provoked cids that will swing, initiative order
     * @param string[] $entered  cids whose reach the mover has just entered
     */
    private function provoke(
        array $state,
        string $moverCid,
        int $fromX,
        int $fromY,
        array $provoked,
        array $entered,
        array $terrain
    ): array {
        foreach ($provoked as $reactorCid) {
            $reactor = $this->getCombatant($state, $reactorCid);
            $mover = $this->getCombatant($state, $moverCid);
            if (!$reactor || !$mover || !self::isTargetable($mover)) {
                break;
            }
            if (!empty($reactor['reaction_used']) || !self::isTargetable($reactor) || !Conditions::canAct($reactor)) {
                continue;
            }
            $weapon = $this->meleeWeaponFor($reactor);
            if ($weapon === null) {
                // Nothing to swing. An archer with no other action does not get
                // to shoot somebody for walking past — that would be a free
                // ranged attack, which is not what a reaction is.
                continue;
            }

            $state = $this->updateCombatant($state, $reactorCid, ['reaction_used' => true]);
            $state['log'][] = "{$reactor['name']} lashes out as {$mover['name']} breaks away.";
            $state = $this->emit($state, [
                'type'   => 'opportunity',
                'actor'  => $reactorCid,
                'target' => $moverCid,
                'at'     => [$fromX, $fromY],
            ]);
            $state = $this->resolveAttack($state, $reactorCid, $moverCid, $weapon, null, [
                'dist' => BattleGrid::feet(
                    (int) $reactor['x'],
                    (int) $reactor['y'],
                    $fromX,
                    $fromY
                ),
                'cover' => 0, 'melee' => true, 'long' => false, 'crowded' => false, 'flank' => false,
            ]);
        }
        return $this->fireReadied($state, $moverCid, $entered, $terrain);
    }

    /**
     * Who, of the watchers on a step, will actually swing — in initiative order.
     *
     * Split out of provoke() so stepMove() can ask the question BEFORE the step
     * rather than discover the answer afterwards. It has to know: an opportunity
     * attack interrupts a walk, and the walk it interrupts has to be on the
     * board before the blow lands or the swing is drawn against the mover's
     * starting cell — which is how a rat at (9,4) came to bite somebody standing
     * at (3,6), thirty feet away. The rule was right and the picture was not.
     *
     * Every filter here is a fact about the reactor at the moment the step is
     * taken, so asking early and asking late give the same answer. What is NOT
     * here is provoke()'s inner re-read of the mover — an earlier reactor can
     * drop them and stop the rest — because that decides how many of these
     * swing, not whether the step is interrupted at all.
     *
     * @param  string[] $leaving  watchers threatening the cell being left
     * @param  string[] $entering watchers threatening the cell being entered
     * @return string[] cids, initiative order
     */
    private function opportunists(array $state, array $leaving, array $entering): array
    {
        $provoked = array_values(array_filter(
            array_diff($leaving, $entering),
            function (string $cid) use ($state): bool {
                $reactor = $this->getCombatant($state, $cid);
                return $reactor
                    && empty($reactor['reaction_used'])
                    && self::isTargetable($reactor)
                    && Conditions::canAct($reactor)
                    // Nothing to swing. An archer with no other action does not
                    // get to shoot somebody for walking past — that would be a
                    // free ranged attack, which is not what a reaction is.
                    && $this->meleeWeaponFor($reactor) !== null;
            }
        ));

        // Initiative order, so the same walk resolves the same way every time.
        $rank = array_flip((array) ($state['order'] ?? []));
        usort($provoked, static fn ($a, $b) => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));
        return $provoked;
    }

    /**
     * Anybody waiting for this mover to come within reach.
     *
     * The mirror of the opportunity attack above, on the same walk and out of
     * the same two sets: an opportunity attack fires on the reach you LEFT, a
     * readied one on the reach you have just ENTERED.
     *
     * The trigger is fixed at "when an enemy comes within your reach" and there
     * is no way to choose another. A general Ready needs a language for
     * triggers — a condition the engine can evaluate at arbitrary moments — and
     * inventing half of one would give the player a promise the engine could
     * not keep. This is the trigger a grid can state exactly, and it is the one
     * that makes holding a doorway mean something.
     *
     * @param string[] $entered cids of watchers whose reach the mover just entered
     */
    private function fireReadied(array $state, string $moverCid, array $entered, array $terrain): array
    {
        if (!$entered) {
            return $state;
        }
        $rank = array_flip((array) ($state['order'] ?? []));
        usort($entered, static fn ($a, $b) => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));

        foreach ($entered as $reactorCid) {
            $reactor = $this->getCombatant($state, $reactorCid);
            $mover = $this->getCombatant($state, $moverCid);
            if (!$reactor || !$mover || !self::isTargetable($mover)) {
                break;
            }
            if (empty($reactor['readied'])
                || !empty($reactor['reaction_used'])
                || !self::isTargetable($reactor)
                || !Conditions::canAct($reactor)) {
                continue;
            }
            $weapon = $this->meleeWeaponFor($reactor);
            if ($weapon === null) {
                continue;
            }

            $state = $this->updateCombatant($state, $reactorCid, [
                'reaction_used' => true, 'readied' => false,
            ]);
            $state['log'][] = "{$reactor['name']} was waiting for that.";
            $state = $this->emit($state, [
                'type' => 'readied', 'actor' => $reactorCid, 'target' => $moverCid,
            ]);
            $state = $this->resolveAttack($state, $reactorCid, $moverCid, $weapon, null, [
                'dist' => BattleGrid::feet(
                    (int) $reactor['x'],
                    (int) $reactor['y'],
                    (int) $mover['x'],
                    (int) $mover['y']
                ),
                'cover' => 0, 'melee' => true, 'long' => false, 'crowded' => false, 'flank' => false,
            ]);
        }
        return $state;
    }

    /**
     * Who could react to this mover: the other side, upright and able to act.
     *
     * Pared down to what BattleGrid needs — it answers questions about cells,
     * not about conditions, so the rules filtering happens here.
     *
     * @return array<int, array>
     */
    private function reactors(array $state, string $moverCid): array
    {
        $mover = $this->getCombatant($state, $moverCid);
        $out = [];
        foreach (self::standing($state['combatants'], self::enemySide($mover)) as $c) {
            if (($c['cid'] ?? '') === $moverCid || !Conditions::canAct($c)) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    /**
     * The extra swing a bonus action buys, from either of the two things that
     * grant one.
     *
     * Two-weapon fighting and Martial Arts are the same shape — take the Attack
     * action, then spend your bonus action on one more strike — and differ only
     * in what is in the hand. Both required the buildWeapon() split, because
     * both need a weapon priced differently from the main hand: the off-hand
     * without its ability modifier, the fist with the Monk's die.
     *
     * `attacks_made` being non-zero is the gate, and it is the SRD's: this is
     * bought by having taken the Attack action, not by having an action free. A
     * character who dodged does not get to punch afterwards.
     */
    private function doBonusAttack(array $state, string $targetCid): array
    {
        $actor = $this->acting($state, false);
        if (($actor['type'] ?? '') !== 'pc') {
            throw new InvalidArgumentException('Nobody here can do that.');
        }
        if (!empty($actor['bonus_used'])) {
            throw new InvalidArgumentException('Bonus action already spent this turn.');
        }
        if (empty($actor['attacks_made'])) {
            throw new InvalidArgumentException('Take the Attack action first.');
        }

        $characterId = (int) ($actor['character_id'] ?? 0);
        $main = $this->getEquippedWeapon($characterId);
        $offhand = $this->getOffhandWeapon($characterId);
        $monk = Rules::hasFeature(
            (string) ($actor['class'] ?? ''),
            (int) ($actor['level'] ?? 1),
            'martial_arts'
        ) && Rules::isMonkWeapon($main);

        if ($offhand !== null && !empty(Rules::weaponProperties($main ?? [])['light'])) {
            // Both hands light: the off-hand swings, without its modifier.
            $weapon = $this->buildWeapon($actor, $offhand, ['offhand' => true]);
        } elseif ($monk) {
            // A Monk's spare hand is always free, whatever the other one holds,
            // so long as what it holds is a monk weapon. The fist keeps its
            // modifier — Martial Arts says the bonus strike is an unarmed
            // strike, and does not take the modifier off it the way two-weapon
            // fighting does.
            $weapon = $this->buildWeapon($actor, null);
        } else {
            throw new InvalidArgumentException(
                $actor['name'] . ' has no second attack to make — that needs two light weapons, or a monk.'
            );
        }

        $target = $this->getCombatant($state, $targetCid);
        if (!$target || !self::isTargetable($target)) {
            throw new InvalidArgumentException('Invalid target.');
        }
        if (($target['side'] ?? '') === ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('Cannot attack allies.');
        }
        $shots = self::shotsFrom($state['grid'], $state['combatants'], $actor, self::rangeOf($weapon));
        if (!isset($shots[$targetCid])) {
            throw new InvalidArgumentException(
                $this->whyNot($state, $actor, $target, self::rangeOf($weapon), $weapon)
            );
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['bonus_used' => true]);
        $state['log'][] = "{$actor['name']} follows up with {$weapon['name']}.";
        return $this->resolveAttack($state, $actor['cid'], $targetCid, $weapon, null, $shots[$targetCid]);
    }

    /**
     * Lay on Hands: a day's worth of healing, spent a handful at a time.
     *
     * The pool is per long rest and lives in world_flags beside the hit dice
     * and the other per-day counters, because that is where this game keeps
     * anything a night's sleep gives back. Drawn from as needed rather than in
     * a fixed dose — the SRD lets a Paladin decide, and "as much as they are
     * missing, or as much as is left" is the decision anybody would make.
     *
     * Curing a poisoning costs a flat five and is offered first, because a
     * poisoned ally is worth more than five hit points and the engine has to
     * choose for itself.
     */
    private function doLayOnHands(array $state, string $targetCid): array
    {
        $actor = $this->acting($state);
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'lay_on_hands')) {
            throw new InvalidArgumentException($actor['name'] . ' has no such gift.');
        }
        $target = $this->getCombatant($state, $targetCid);
        if (!$target || empty($target['alive'])) {
            throw new InvalidArgumentException('Invalid target.');
        }
        if (($target['side'] ?? '') !== ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('That is not somebody you would heal.');
        }
        $reach = BattleGrid::ftToCells((int) ($actor['reach_ft'] ?? self::DEFAULT_REACH_FT));
        if (BattleGrid::cells((int) $actor['x'], (int) $actor['y'],
                              (int) $target['x'], (int) $target['y']) > max(1, $reach)) {
            throw new InvalidArgumentException($target['name'] . ' is out of reach — it is a touch.');
        }

        $characterId = (int) ($actor['character_id'] ?? 0);
        $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
        $world = new WorldState($this->db);
        $pool = Rules::layOnHandsPool((int) ($actor['level'] ?? 1));
        $spent = $partyId
            ? (int) ($world->get($partyId, LocationEngine::layOnHandsFlag($characterId)) ?? 0)
            : 0;
        $left = max(0, $pool - $spent);
        if ($left <= 0) {
            throw new InvalidArgumentException($actor['name'] . ' has nothing left to give today.');
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);

        // Poison first: five points buys more than five points of healing.
        if (Conditions::has($target, 'poisoned') && $left >= Rules::LAY_ON_HANDS_CURE) {
            $draw = Rules::LAY_ON_HANDS_CURE;
            if ($partyId) {
                $world->set($partyId, LocationEngine::layOnHandsFlag($characterId), (string) ($spent + $draw));
            }
            $state['log'][] = "{$actor['name']} draws the poison out of {$target['name']}.";
            return $this->removeCondition($state, $targetCid, 'poisoned');
        }

        $missing = max(0, (int) $target['max_hp'] - (int) $target['hp']);
        $draw = min($left, $missing);
        if ($draw <= 0) {
            throw new InvalidArgumentException($target['name'] . ' is unhurt.');
        }
        if ($partyId) {
            $world->set($partyId, LocationEngine::layOnHandsFlag($characterId), (string) ($spent + $draw));
        }
        $state['log'][] = "{$actor['name']} lays on hands — {$draw} of "
            . ($left) . " remaining.";
        return $this->heal($state, $targetCid, $draw);
    }

    /**
     * Divine Sense: what is in the room that should not be.
     *
     * Names every undead, fiend or celestial within sixty feet, and strips the
     * unseen from any of them — which is the one mechanical thing the SRD's
     * "you know the location of" can mean on a board where position is already
     * public. Nothing in the bestiary hides today; a Paladin who spends a use
     * and finds only what they could already see has still learned that there
     * is nothing else, which is what the feature is for.
     *
     * Costs no action in the SRD. It costs a use, and those are few.
     */
    private function doDivineSense(array $state): array
    {
        $actor = $this->acting($state, false);
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'divine_sense')) {
            throw new InvalidArgumentException($actor['name'] . ' has no such sight.');
        }
        $characterId = (int) ($actor['character_id'] ?? 0);
        $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
        $world = new WorldState($this->db);
        $allowance = Rules::divineSenseUses((int) ($actor['cha'] ?? 10));
        $used = $partyId
            ? (int) ($world->get($partyId, LocationEngine::divineSenseFlag($characterId)) ?? 0)
            : 0;
        if ($used >= $allowance) {
            throw new InvalidArgumentException('Divine Sense is spent — that needs a long rest.');
        }
        if ($partyId) {
            $world->increment($partyId, LocationEngine::divineSenseFlag($characterId));
        }

        $found = [];
        foreach (self::standing($state['combatants'], self::enemySide($actor)) as $c) {
            $type = strtolower(trim((string) ($c['creature_type'] ?? '')));
            if (!in_array($type, Rules::DIVINE_SENSE_TYPES, true)) {
                continue;
            }
            if (BattleGrid::feet((int) $actor['x'], (int) $actor['y'],
                                 (int) $c['x'], (int) $c['y']) > Rules::DIVINE_SENSE_FT) {
                continue;
            }
            $found[] = $c['name'];
            if (Conditions::has($c, 'invisible')) {
                $state = $this->removeCondition($state, (string) $c['cid'], 'invisible');
            }
        }

        $state['log'][] = $found
            ? "{$actor['name']} senses it: " . implode(', ', $found) . '.'
            : "{$actor['name']} senses nothing unclean nearby.";
        return $this->emit($state, [
            'type' => 'divine_sense', 'actor' => (string) $actor['cid'],
            'found' => count($found), 'left' => max(0, $allowance - $used - 1),
        ]);
    }

    /**
     * Action Surge: one more action, once per breather.
     *
     * Not a bonus action and so not in BONUS_ACTIONS — it is a free feature
     * that gives the turn's action back. Its accounting rides on the same world
     * flag the bonus features use, because "once per rest" is the same
     * bookkeeping wherever it appears, and it is in SHORT_REST_FEATURES so an
     * hour returns it.
     *
     * `attacks_made` is reset with the action, or a fighter with Extra Attack
     * would surge into a fresh action and still be told they had swung twice.
     */
    private function doActionSurge(array $state): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || ($actor['type'] ?? '') !== 'pc') {
            throw new InvalidArgumentException('Nobody here can do that.');
        }
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'action_surge')) {
            throw new InvalidArgumentException($actor['name'] . ' has no Action Surge.');
        }
        if (empty($actor['action_used'])) {
            throw new InvalidArgumentException('Take your action first — this is for when it is gone.');
        }
        $spent = (array) ($actor['bonus_features_used'] ?? []);
        if (in_array('action_surge', $spent, true)) {
            throw new InvalidArgumentException('Action Surge is spent — that needs a rest.');
        }
        $spent[] = 'action_surge';

        $characterId = (int) ($actor['character_id'] ?? 0);
        $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
        if ($partyId) {
            (new WorldState($this->db))->increment(
                $partyId,
                self::bonusFeatureFlag('action_surge', $characterId)
            );
        }

        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => false,
            'attacks_made' => 0,
            'bonus_features_used' => $spent,
        ]);
        $state['log'][] = "{$actor['name']} surges — one more, right now.";
        return $this->emit($state, ['type' => 'surge', 'actor' => (string) $actor['cid']]);
    }

    /**
     * Channel Divinity, in its two forms.
     *
     * Both are actions, both are counted against the same small allowance, and
     * both come back on a breather — so the bookkeeping rides on the world-flag
     * counter every other per-rest feature already uses, and the key goes in
     * SHORT_REST_FEATURES beside Second Wind and Action Surge.
     *
     * TURN UNDEAD REUSES `frightened`, AND THAT IS THE POINT. The SRD says a
     * turned creature "must spend its turns trying to move as far away as it
     * can from you", and until this session's movement work there was nothing
     * in the engine that could express it — frightened was a modifier on dice
     * and nothing else. It now forbids closing on its source, and the monster
     * AI walks through the same reachFor() a player does, so a turned skeleton
     * genuinely cannot come at you. What is NOT modelled is the compulsion to
     * actively flee: it will hold its ground rather than run. That is a smaller
     * lie than the alternative, and it is the honest limit of a condition
     * built for dice being asked to carry a movement rule.
     */
    private function doChannelDivinity(array $state, string $which): array
    {
        $actor = $this->acting($state);
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'channel_divinity')) {
            throw new InvalidArgumentException($actor['name'] . ' has no Channel Divinity.');
        }

        $characterId = (int) ($actor['character_id'] ?? 0);
        $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
        $world = new WorldState($this->db);
        $flag = self::bonusFeatureFlag('channel_divinity', $characterId);
        $used = $partyId ? (int) ($world->get($partyId, $flag) ?? 0) : 0;
        $allowance = Rules::channelDivinityUses((int) ($actor['level'] ?? 1));
        if ($used >= $allowance) {
            throw new InvalidArgumentException('Channel Divinity is spent — that needs a rest.');
        }
        if (!in_array($which, ['turn_undead', 'preserve_life'], true)) {
            throw new InvalidArgumentException('No such channel.');
        }
        if ($which === 'preserve_life'
            && !Rules::subclassFeature($actor, 'disciple_of_life')) {
            // Preserve Life is the Life Domain's channel, and Life Domain is
            // the only cleric subclass in the seed — but a cleric below the
            // level that grants it has the class feature and not the domain
            // one, and should be told which is missing.
            throw new InvalidArgumentException($actor['name'] . ' has no such domain gift.');
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);
        if ($partyId) {
            $world->increment($partyId, $flag);
        }

        return $which === 'turn_undead'
            ? $this->turnUndead($state, $actor)
            : $this->preserveLife($state, $actor);
    }

    /** Every undead within thirty feet saves or backs off for a minute. */
    private function turnUndead(array $state, array $actor): array
    {
        $dc = Rules::spellSaveDc($actor);
        $state['log'][] = "{$actor['name']} holds up their symbol. The dead feel it.";

        $turned = 0;
        $seen = 0;
        foreach (self::standing($state['combatants'], self::enemySide($actor)) as $c) {
            if (strtolower(trim((string) ($c['creature_type'] ?? ''))) !== 'undead') {
                continue;
            }
            if (BattleGrid::feet((int) $actor['x'], (int) $actor['y'],
                                 (int) $c['x'], (int) $c['y']) > Rules::CHANNEL_FT) {
                continue;
            }
            $seen++;
            $save = $this->rollSave($c, 'wis', $dc);
            $state = $this->emit($state, [
                'type' => 'save', 'actor' => (string) $c['cid'], 'ability' => 'wis',
                'dc' => $dc, 'natural' => $save['natural'], 'total' => $save['total'],
                'passed' => $save['passed'],
            ]);
            if ($save['passed']) {
                $state['log'][] = "{$c['name']} holds.";
                continue;
            }
            $turned++;
            $state = $this->addCondition($state, (string) $c['cid'], [
                'key' => 'frightened',
                'rounds' => Rules::TURNED_ROUNDS,
                'source_cid' => (string) $actor['cid'],
            ]);
        }

        if ($seen === 0) {
            $state['log'][] = 'Nothing here answers to it.';
        }
        return $this->emit($state, [
            'type' => 'channel', 'actor' => (string) $actor['cid'],
            'which' => 'turn_undead', 'affected' => $turned,
        ]);
    }

    /**
     * Preserve Life: a pool of healing, spread over whoever needs it most.
     *
     * "You choose how to divide those hit points", and the SRD caps each
     * creature at half its maximum. Nobody is asked, so the pool goes to the
     * worst hurt first — which is the choice a player makes, and the only one
     * that can bring somebody back from the floor.
     */
    private function preserveLife(array $state, array $actor): array
    {
        $pool = Rules::preserveLifePool((int) ($actor['level'] ?? 1));
        $state['log'][] = "{$actor['name']} spreads their hands, and the light goes where it is needed.";

        $wounded = [];
        foreach (self::standing($state['combatants'], (string) $actor['side']) as $c) {
            if (BattleGrid::feet((int) $actor['x'], (int) $actor['y'],
                                 (int) $c['x'], (int) $c['y']) > Rules::CHANNEL_FT) {
                continue;
            }
            // The SRD excludes undead and constructs. Nothing on the party side
            // is either today, and the check costs nothing and stops a raised
            // companion from being healed by it later.
            if (in_array(strtolower(trim((string) ($c['creature_type'] ?? ''))),
                         ['undead', 'construct'], true)) {
                continue;
            }
            $half = intdiv((int) $c['max_hp'], 2);
            $room = min($half, (int) $c['max_hp']) - (int) $c['hp'];
            if ($room > 0) {
                $wounded[] = ['cid' => (string) $c['cid'], 'room' => $room, 'hp' => (int) $c['hp']];
            }
        }
        usort($wounded, static fn ($a, $b) => $a['hp'] <=> $b['hp']);

        $given = 0;
        foreach ($wounded as $w) {
            if ($pool <= 0) {
                break;
            }
            $draw = min($pool, $w['room']);
            $pool -= $draw;
            $given += $draw;
            $state = $this->heal($state, $w['cid'], $draw);
        }
        if ($given === 0) {
            $state['log'][] = 'Nobody here needs it.';
        }
        return $this->emit($state, [
            'type' => 'channel', 'actor' => (string) $actor['cid'],
            'which' => 'preserve_life', 'affected' => $given,
        ]);
    }

    /**
     * Divine Smite: hold a spell slot ready to spend on the next thing you hit.
     *
     * THE SRD LETS YOU DECIDE AFTER THE HIT, AND THIS DOES NOT. That is a real
     * deviation and worth stating rather than burying: "when you hit, you can
     * expend one spell slot" means a Paladin who misses keeps the slot, and a
     * Paladin who hits for four damage on a dying goblin can decline to waste a
     * second-level one. Asking mid-swing would need a second parked-turn prompt
     * beside the one manual attack rolls already use, and two different
     * questions interrupting one attack is worse than the loss.
     *
     * What is kept instead is the part that matters: the slot is only spent on
     * a HIT. Arming costs nothing, a miss costs nothing, and the slot is burnt
     * inside resolveAttack() at the point the blow lands. A Paladin who arms a
     * Smite and whiffs has lost their choice, not their magic.
     */
    private function doSmite(array $state, int $slotLevel): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || ($actor['type'] ?? '') !== 'pc') {
            throw new InvalidArgumentException('Nobody here can do that.');
        }
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'divine_smite')) {
            throw new InvalidArgumentException($actor['name'] . ' has no Smite.');
        }
        $characterId = (int) ($actor['character_id'] ?? 0);
        $table = Rules::slotTable((string) $actor['class'], (int) ($actor['level'] ?? 1));
        $spent = $this->slotsExpended($characterId);
        $left = (int) ($table[$slotLevel] ?? 0) - (int) ($spent[(string) $slotLevel] ?? 0);
        if ($slotLevel < 1 || $left < 1) {
            throw new InvalidArgumentException('No slot of that level left.');
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['smite' => $slotLevel]);
        $state['log'][] = "{$actor['name']} draws it up, and holds it.";
        return $this->emit($state, [
            'type' => 'smite_ready', 'actor' => (string) $actor['cid'], 'level' => $slotLevel,
        ]);
    }

    /**
     * Spend a held Smite on a blow that landed.
     *
     * Returns the extra dice rolled, or null if there was nothing held. The
     * slot is burnt here and the flag cleared here, both of them only on a hit
     * — see doSmite() for why that is the half of the SRD rule worth keeping.
     *
     * @return array{state:array, roll:array, note:string}|null
     */
    private function spendSmite(
        array $state,
        string $actorCid,
        array $target,
        string $attackKind
    ): ?array {
        $actor = $this->getCombatant($state, $actorCid);
        $level = (int) ($actor['smite'] ?? 0);
        if (!$actor || $level < 1) {
            return null;
        }
        // Melee only, which is the SRD's wording and not an accident: a Smite
        // is something a Paladin puts into a swing, not into an arrow.
        //
        // Handed in rather than read off the combatant. resolveAttack() sets
        // `attack_kind` on its own local copy of the actor and never writes it
        // back — so asking the stored row would always answer 'melee', and a
        // Paladin could smite with a longbow. The tests caught exactly that.
        if ($attackKind !== 'melee') {
            return null;
        }

        $unclean = in_array(
            strtolower(trim((string) ($target['creature_type'] ?? ''))),
            Rules::SMITE_UNCLEAN,
            true
        );
        $dice = Rules::smiteDice($level, $unclean);
        $roll = Dice::roll($dice . 'd8');

        $state = $this->updateCombatant($state, $actorCid, ['smite' => 0]);
        $this->spendSlot((int) ($actor['character_id'] ?? 0), $level);

        return [
            'state' => $state,
            'roll'  => $roll,
            'note'  => "{$actor['name']} burns it — {$roll['total']} radiant"
                . ($unclean ? ', and it bites deeper on this one.' : '.'),
        ];
    }

    /**
     * Reckless Attack: swing wide open.
     *
     * Free and declared on your turn, so it costs nothing but the risk — which
     * is the whole feature. Both halves are read in resolveAttack(): the
     * Barbarian's melee swings gain advantage, and everything swinging back at
     * them gains it too. Cleared at the top of their next turn, like Dodge,
     * because "until your next turn" is how long the exposure lasts.
     */
    private function doReckless(array $state): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || ($actor['type'] ?? '') !== 'pc') {
            throw new InvalidArgumentException('Nobody here can do that.');
        }
        if (!Rules::hasFeature((string) ($actor['class'] ?? ''), (int) ($actor['level'] ?? 1), 'reckless_attack')) {
            throw new InvalidArgumentException($actor['name'] . ' does not fight that way.');
        }
        if (!empty($actor['attacks_made'])) {
            throw new InvalidArgumentException('Too late — decide before the first swing.');
        }
        $state = $this->updateCombatant($state, $actor['cid'], ['reckless' => true]);
        $state['log'][] = "{$actor['name']} abandons all defence.";
        return $this->emit($state, ['type' => 'reckless', 'actor' => (string) $actor['cid']]);
    }

    /**
     * Hold your swing for whoever walks into it.
     *
     * Spends the action now and the reaction later, which is what makes Ready
     * a real cost rather than a free extra attack: a character who readies and
     * whose trigger never fires has spent their turn on nothing.
     */
    private function doReady(array $state): array
    {
        $actor = $this->acting($state);
        if ($this->meleeWeaponFor($actor) === null) {
            throw new InvalidArgumentException($actor['name'] . ' has nothing to hold ready.');
        }
        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => true, 'readied' => true,
        ]);
        $state['log'][] = "{$actor['name']} holds, watching the ground in front of them.";
        return $state;
    }

    /**
     * A contest of strength: Grapple and Shove.
     *
     * Both are the SRD's "special melee attacks" — they replace one attack of
     * the Attack action, which is why they spend `attacks_made` rather than the
     * action outright, and why a fighter with Extra Attack may swing and then
     * shove.
     *
     * The contest is Athletics against the better of the target's Athletics and
     * Acrobatics. Ties go to the defender, as the SRD says.
     */
    private function doContest(array $state, string $targetCid, string $kind): array
    {
        $actor = $this->acting($state, false);
        $allowed = self::attacksAllowed($actor);
        $made = (int) ($actor['attacks_made'] ?? 0);
        if ($made === 0 && !empty($actor['action_used'])) {
            throw new InvalidArgumentException('Already acted this turn.');
        }
        if ($made >= $allowed) {
            throw new InvalidArgumentException($actor['name'] . ' has no attacks left this turn.');
        }

        $target = $this->getCombatant($state, $targetCid);
        if (!$target || !self::isTargetable($target)) {
            throw new InvalidArgumentException('Invalid target.');
        }
        if (($target['side'] ?? '') === ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('Cannot grapple or shove an ally.');
        }
        // Reach, and only reach: both are made with your hands and neither is a
        // thing you can do to somebody across the room.
        $reach = BattleGrid::ftToCells((int) ($actor['reach_ft'] ?? self::DEFAULT_REACH_FT));
        if (BattleGrid::cells((int) $actor['x'], (int) $actor['y'],
                              (int) $target['x'], (int) $target['y']) > max(1, $reach)) {
            throw new InvalidArgumentException($target['name'] . ' is out of reach.');
        }

        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => true, 'attacks_made' => $made + 1,
        ]);

        $mine = Dice::d20(Rules::skillCheck($actor, 'athletics')['total']);
        $theirs = max(
            Rules::skillCheck($target, 'athletics')['total'],
            Rules::skillCheck($target, 'acrobatics')['total']
        );
        // Set Fast: a Sarsen defends this contest with advantage. It is the one
        // racial instinct that cannot go through rollSave() with the others,
        // because being shoved is not a saving throw here — it is an opposed
        // check, and this is the only place one is rolled.
        $setFast = Rules::raceFeature($target, 'set_fast');
        $defence = Dice::d20Mode($theirs, $setFast ? 'advantage' : 'normal');
        $won = (int) $mine['total'] > (int) $defence['total'];

        $verb = $kind === 'grapple' ? 'grapple' : 'shove';
        $state['log'][] = "{$actor['name']} tries to {$verb} {$target['name']}: "
            . "{$mine['total']} against {$defence['total']}"
            . ($setFast ? " — {$target['name']} is set fast." : '.');
        $state = $this->emit($state, [
            'type' => 'contest', 'actor' => (string) $actor['cid'], 'target' => $targetCid,
            'kind' => $kind, 'attacker' => (int) $mine['total'],
            'defender' => (int) $defence['total'], 'won' => $won,
        ]);

        if (!$won) {
            $state['log'][] = "{$target['name']} holds their ground.";
            return $state;
        }
        // Neither carries a duration: grappled ends when the grappler lets go
        // or is put down, and prone ends when you stand up — which now costs
        // half your speed, so it is a real price.
        return $this->addCondition($state, $targetCid, [
            'key' => $kind === 'grapple' ? 'grappled' : 'prone',
            'source_cid' => (string) $actor['cid'],
        ]);
    }

    /**
     * Slip out of sight.
     *
     * Two things have to be true and both are the SRD's: you cannot hide from
     * somebody who can plainly see you, and hiding is a Stealth check against
     * what the other side notices without trying. Cover is what "plainly" turns
     * on here — the board already computes it for every line on the field, so
     * being behind the crates is what lets you try at all.
     *
     * Success applies `invisible`, which until now was a fully specified entry
     * in the condition catalogue that NOTHING in the game ever applied: no
     * spell granted it and no action produced it. Being unseen and being
     * invisible are not the same thing in the SRD, but they do the same two
     * things to a die roll, and the catalogue already says so.
     */
    private function doHide(array $state): array
    {
        $actor = $this->acting($state);
        $terrain = $state['grid']['terrain'] ?? [];
        $foes = self::standing($state['combatants'], self::enemySide($actor));

        foreach ($foes as $foe) {
            $cover = BattleGrid::coverBetween($terrain, $state['combatants'], $foe, $actor);
            $seen = BattleGrid::losClear(
                $terrain,
                (int) $foe['x'],
                (int) $foe['y'],
                (int) $actor['x'],
                (int) $actor['y']
            );
            if ($seen && $cover <= 0) {
                throw new InvalidArgumentException(
                    $foe['name'] . ' can see you plainly — get behind something first.'
                );
            }
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);

        $roll = Dice::d20(Rules::skillCheck($actor, 'stealth')['total']);
        $watch = 10;
        foreach ($foes as $foe) {
            $watch = max($watch, Rules::passiveScore($foe, 'perception'));
        }

        $state['log'][] = "{$actor['name']} slips out of sight: {$roll['total']} against {$watch}.";
        $state = $this->emit($state, [
            'type' => 'contest', 'actor' => (string) $actor['cid'], 'target' => '',
            'kind' => 'hide', 'attacker' => (int) $roll['total'],
            'defender' => $watch, 'won' => (int) $roll['total'] >= $watch,
        ]);

        if ((int) $roll['total'] < $watch) {
            $state['log'][] = "{$actor['name']} is spotted anyway.";
            return $state;
        }
        return $this->addCondition($state, (string) $actor['cid'], ['key' => 'invisible']);
    }

    private function doDodge(array $state): array
    {
        $actor = $this->acting($state);
        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true, 'dodging' => true]);
        $state['log'][] = "{$actor['name']} takes the Dodge action.";
        return $state;
    }

    private function doHelp(array $state, string $targetCid): array
    {
        $actor = $this->acting($state);
        $ally = $this->getCombatant($state, $targetCid);
        if (!$ally || !self::isTargetable($ally) || ($ally['side'] ?? '') !== ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('Invalid ally to help.');
        }
        if ($ally['cid'] === $actor['cid']) {
            throw new InvalidArgumentException('Cannot help yourself.');
        }
        // "...a creature within 5 feet of you". Unchecked until now because
        // when this was written there was no distance to check: on the rank
        // board an ally was in a rank, not at a place, and Help could be
        // shouted across the room. It is the difference between distracting
        // somebody's opponent and encouraging them from the back.
        $reach = BattleGrid::ftToCells((int) ($actor['reach_ft'] ?? self::DEFAULT_REACH_FT));
        if (BattleGrid::cells(
            (int) $actor['x'],
            (int) $actor['y'],
            (int) $ally['x'],
            (int) $ally['y']
        ) > max(1, $reach)) {
            throw new InvalidArgumentException(
                $ally['name'] . ' is too far away to help — get beside them.'
            );
        }
        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);
        $state = $this->updateCombatant($state, $targetCid, ['helped' => $actor['cid']]);
        $state['log'][] = "{$actor['name']} helps {$ally['name']}, who has advantage on their next attack.";
        return $state;
    }

    /**
     * Disengage, which finally does something.
     *
     * This was a logged apology for most of the game's life — the comment here
     * used to say there were no opportunity attacks for it to prevent, which
     * was true when nobody could walk anywhere. The `disengaging` field it sets
     * had already existed for as long, written by Cunning Action and read by
     * nothing at all. provoke() reads it now.
     */
    /**
     * Dash: the turn's movement again.
     *
     * It did not exist while the board was ranks, and correctly so — there was
     * no distance to double and the button would have done nothing. On a floor
     * it is the answer to the commonest thing the grid produces, which is an
     * archer sixty feet away and a fighter with a speed of thirty. Without it
     * that fighter's only move is to walk half the distance and stop.
     *
     * Added to `move_left` rather than doubling it: a character who has already
     * walked ten of their thirty feet gets thirty more, not forty. `speed` is
     * the SRD's "your speed", not what remains of it.
     */
    private function doDash(array $state): array
    {
        $actor = $this->acting($state);
        // Twice nothing is nothing. Refused rather than allowed and wasted,
        // because spending your action to gain thirty feet you cannot use is
        // not a choice anybody makes on purpose.
        if (Conditions::rooted($actor)) {
            throw new InvalidArgumentException($actor['name'] . ' cannot move at all.');
        }
        $gain = max(0, (int) ($actor['speed'] ?? 30));
        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => true,
            'move_left'   => max(0, (int) ($actor['move_left'] ?? 0)) + $gain,
        ]);
        $state['log'][] = "{$actor['name']} breaks into a run — {$gain} more feet.";
        return $state;
    }

    private function doDisengage(array $state): array
    {
        $actor = $this->acting($state);
        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => true,
            'disengaging' => true,
        ]);
        $state['log'][] = "{$actor['name']} disengages — nobody gets a free swing as they go.";
        return $state;
    }

    private function doUseItem(array $state, int $inventoryId): array
    {
        $actor = $this->acting($state);
        if ($actor['type'] !== 'pc') {
            throw new InvalidArgumentException('Cannot use item now.');
        }

        $stmt = $this->db->prepare(
            'SELECT ci.*, i.name, i.item_type, i.damage_dice, i.properties
             FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$inventoryId, (int) $actor['character_id']]);
        $item = $stmt->fetch();
        $props = $item ? (json_decode($item['properties'] ?? '{}', true) ?: []) : [];
        $use = is_array($props['use_effect'] ?? null) ? $props['use_effect'] : null;
        $usable = $item && ($item['item_type'] === 'potion'
            || (in_array($item['item_type'], ['misc', 'scroll'], true) && $use !== null));
        if (!$usable) {
            throw new InvalidArgumentException('Invalid potion.');
        }

        // Fast Hands: a Thief uses an item on the bonus action when it is
        // still free, keeping the action for the knife. Everyone else — and a
        // Thief who already spent the bonus — pays the action as before.
        $economy = (Rules::subclassFeature($actor, 'fast_hands') && empty($actor['bonus_used']))
            ? ['bonus_used' => true]
            : ['action_used' => true];
        $state = $this->updateCombatant($state, $actor['cid'], $economy);

        if ($use !== null && isset($use['cast'])) {
            // A scroll: the spell without the slot, aimed at the reader. Only
            // a mending — a scroll of Burning Hands would need the whole
            // targeting flow, and none ships until it does.
            $spellStmt = $this->db->prepare('SELECT * FROM spells WHERE spell_key = ?');
            $spellStmt->execute([(string) $use['cast']]);
            $scrollSpell = $spellStmt->fetch();
            if (!$scrollSpell || ($scrollSpell['resolution'] ?? '') !== 'heal') {
                throw new InvalidArgumentException("{$item['name']} cannot be read to any effect here.");
            }
            $state['log'][] = "{$actor['name']} reads {$item['name']} — it crumbles as the words take.";
            $state = $this->applySpellTo(
                $state,
                (string) $actor['cid'],
                (string) $actor['cid'],
                $scrollSpell,
                'heal'
            );
        } elseif ($use !== null && isset($use['boon']) && is_array($use['boon'])) {
            // A consumable whose worth is a boon — antitoxin's save advantage,
            // an oil's extra die on every hit. Same shape as a spell's boon,
            // through the same door, so it ticks down and is read the same way.
            $boon = $use['boon'] + [
                'key'    => 'item_' . (int) $item['item_id'],
                'label'  => (string) $item['name'],
                'rounds' => max(1, (int) ($use['rounds'] ?? 10)),
            ];
            $state['log'][] = "{$actor['name']} uses {$item['name']}.";
            $state = $this->addBoon($state, (string) $actor['cid'], $boon);
        } else {
            // Same rule as drinking one outside a fight — see the note on
            // InventoryService::healExpression(). The drinker is the healer
            // here, which is the reading that makes Healer worth taking on
            // somebody who casts nothing.
            $roll = self::healingRoll($actor, InventoryService::healExpression($item));
            $state['log'][] = "{$actor['name']} drinks {$item['name']}.";
            $state = $this->heal($state, $actor['cid'], (int) $roll['total']);
        }

        if ((int) $item['quantity'] <= 1) {
            $this->db->prepare('DELETE FROM character_inventory WHERE id = ?')->execute([$inventoryId]);
        } else {
            $this->db->prepare('UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?')
                ->execute([$inventoryId]);
        }
        return $state;
    }

    private function doFlee(array $state): array
    {
        $actor = $this->acting($state);
        if (empty($state['allow_flee'])) {
            throw new InvalidArgumentException('There is no way out of this fight.');
        }

        $dc = (int) ($state['flee_dc'] ?? 10);

        // One roll for the whole party, made on the slowest member's
        // Dexterity: a retreat moves at the pace of whoever is last out.
        $slowest = null;
        foreach (self::standing($state['combatants'], 'party') as $pc) {
            if ($slowest === null || (int) $pc['dex'] < (int) $slowest['dex']) {
                $slowest = $pc;
            }
        }
        $slowest ??= $actor;

        // Frightened and poisoned both hamper ability checks, and a scramble
        // for the door is an ability check.
        $hampered = false;
        foreach ($slowest['conditions'] ?? [] as $cond) {
            $key = is_array($cond) ? (string) ($cond['key'] ?? '') : (string) $cond;
            if (!empty(Conditions::CATALOG[$key]['checks_disadvantage'])) {
                $hampered = true;
            }
        }
        $mode = self::rollMode(['reasons' => []], false, $hampered);
        $roll = Dice::d20Mode(Rules::abilityMod((int) $slowest['dex']), $mode);
        $passed = $roll['total'] >= $dc;

        $state = $this->emit($state, [
            'type' => 'save', 'actor' => $slowest['cid'], 'ability' => 'dex', 'dc' => $dc,
            'natural' => $roll['natural'], 'total' => $roll['total'], 'passed' => $passed,
        ]);

        if ($passed) {
            $state['status'] = 'fled';
            $state['log'][] = "The party breaks off and runs ({$roll['total']} vs DC {$dc}).";
            return $state;
        }

        $state['log'][] = "The party cannot break away ({$roll['total']} vs DC {$dc}) and loses the initiative.";
        foreach ($state['combatants'] as $c) {
            if (($c['side'] ?? '') === 'party' && self::isTargetable($c) && $c['cid'] !== $actor['cid']) {
                $state = $this->updateCombatant($state, $c['cid'], ['turn_forfeit' => true]);
            }
        }
        return $this->endTurn($state);
    }

    private function doParley(array $state): array
    {
        $this->acting($state);
        if (empty($state['allow_parley'])) {
            throw new InvalidArgumentException('There is no talking to these.');
        }
        $state['status'] = 'parley';
        $state['log'][] = 'Weapons lower. There is something to say after all.';
        return $this->emit($state, ['type' => 'parley', 'node' => $state['parley_node'] ?? null]);
    }

    // =======================================================================
    // Spellcasting
    // =======================================================================

    private function doCast(
        array $state,
        int $spellId,
        string $targetCid,
        ?int $targetX = null,
        ?int $targetY = null,
        ?int $castLevel = null
    ): array {
        $actor = $this->acting($state);
        if ($actor['type'] !== 'pc') {
            throw new InvalidArgumentException('Cannot cast now.');
        }

        $stmt = $this->db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ? AND s.id = ?'
        );
        $stmt->execute([(int) $actor['character_id'], $spellId]);
        $spell = $stmt->fetch();
        if (!$spell) {
            throw new InvalidArgumentException('Spell not known.');
        }

        // A reaction spell is cast by its trigger, not by the action bar —
        // Shield fires on the hit it turns, Hellish Rebuke on the blood
        // drawn. Refused here so a slot cannot be spent casting one at nobody.
        if (stripos((string) ($spell['casting_time'] ?? ''), 'reaction') !== false) {
            throw new InvalidArgumentException(
                "{$spell['name']} casts itself when its moment comes — it cannot be cast as an action."
            );
        }

        $level = (int) $spell['level'];
        $characterId = (int) $actor['character_id'];

        // "At Higher Levels": the slot the player chose, clamped to what
        // exists, never below the spell's own level. A Warlock's pact slot is
        // all one level, so their casts arrive at that level whatever was
        // asked — slotLevelSpent answers for both the check and the effect,
        // which is what keeps the slot burned and the dice added in step.
        $castLevel = max($level, min($castLevel ?? $level, Rules::MAX_SLOT_LEVEL));
        if ($level > 0) {
            $castLevel = Rules::slotLevelSpent(
                (string) $actor['class'],
                (int) ($actor['level'] ?? 1),
                $castLevel
            );
        }
        // Carried on the spell row so applySpellTo can scale dice and
        // Disciple of Life can pay by the slot, not the spell.
        $spell['cast_level'] = $castLevel;

        // Slots are refused before anything is rolled, and spent immediately
        // rather than at the end of the fight — a fight that ends badly must
        // not hand the slots back.
        if ($level > 0) {
            $left = self::slotsAvailable(
                $this->slotsExpended($characterId),
                (string) $actor['class'],
                (int) ($actor['level'] ?? 1),
                $castLevel
            );
            if ($left < 1) {
                throw new InvalidArgumentException(
                    "{$actor['name']} has no level {$castLevel} spell slots left."
                );
            }
        }

        $resolution = (string) ($spell['resolution'] ?? 'attack');
        if (($spell['damage_type'] ?? '') === 'healing' && $resolution === 'attack') {
            // Pre-`resolution` seed rows say only that they heal.
            $resolution = 'heal';
        }
        // The range prose on the spell card is now also the rule. Where only a
        // target was named — the client sending target_cid and no cell, or the
        // AI — the aim point is that target's own square.
        $range = Rules::spellRange(
            (string) ($spell['range_text'] ?? ''),
            (int) ($spell['reaches_back_rank'] ?? 1)
        );
        $aim = $this->aimPoint($state, $actor, $targetCid, $targetX, $targetY);

        $area = null;
        if (is_array($range['aoe'] ?? null)) {
            $area = BattleGrid::template(
                $state['grid']['terrain'] ?? [],
                (string) $range['aoe']['shape'],
                (int) $range['aoe']['ft'],
                (int) $actor['x'],
                (int) $actor['y'],
                $aim[0],
                $aim[1]
            );
        }

        $targets = $this->spellTargets($state, $actor, $spell, $targetCid, $range, $area);
        if (!$targets) {
            throw new InvalidArgumentException('Nothing to point that spell at.');
        }

        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);
        $state['log'][] = "{$actor['name']} casts {$spell['name']}"
            . ($castLevel > $level && $level > 0 ? " at level {$castLevel}" : '') . '.';
        if ($area !== null) {
            // Emitted before the per-target rolls so the board paints the
            // footprint first and the saves land inside something.
            $state = $this->emit($state, [
                'type'   => 'aoe',
                'actor'  => $actor['cid'],
                'spell'  => $spell['name'],
                'shape'  => (string) $range['aoe']['shape'],
                'ft'     => (int) $range['aoe']['ft'],
                'origin' => [(int) $actor['x'], (int) $actor['y']],
                'toward' => $aim,
                'cells'  => $area,
            ]);
        }
        $state = $this->emit($state, [
            'type' => 'cast', 'actor' => $actor['cid'], 'spell' => $spell['name'],
            'level' => $level, 'cast_level' => $castLevel,
            'targets' => array_map(static fn ($t) => $t['cid'], $targets),
        ]);

        // A caster holds one thing at a time; starting a second drops the
        // first, and says so.
        if ((int) ($spell['concentration'] ?? 0) === 1) {
            $state = $this->dropConcentration($state, (string) $actor['cid'], 'replaced');
        }

        foreach ($targets as $t) {
            // Cover is worked out per target and here rather than inside, so
            // that the caster's own square never counts as cover from itself.
            $cover = ((string) $t['cid'] === (string) $actor['cid'])
                ? 0
                : BattleGrid::coverBetween($state['grid']['terrain'] ?? [], $state['combatants'], $actor, $t);
            $state = $this->applySpellTo(
                $state,
                $actor['cid'],
                $t['cid'],
                $spell,
                $resolution,
                $cover,
                $area !== null
            );
        }

        if ((int) ($spell['concentration'] ?? 0) === 1) {
            $effect = json_decode($spell['effect_json'] ?? '{}', true) ?: [];
            $state = $this->updateCombatant($state, $actor['cid'], ['concentrating' => [
                'spell_id' => (int) $spell['id'],
                'name'     => (string) $spell['name'],
                'targets'  => array_map(static fn ($t) => (string) $t['cid'], $targets),
                'rounds'   => max(1, (int) ($spell['duration_rounds'] ?? 1)),
                'condition'=> $effect['condition'] ?? null,
                'boon'     => strtolower((string) $spell['name']),
            ]]);
        }

        if ($level > 0) {
            // The slot actually burned — $castLevel has already been through
            // slotLevelSpent, so for a Warlock this is the pact slot.
            // slotsAvailable() checked the same one.
            $this->spendSlot($characterId, $castLevel);
        }

        return $state;
    }

    /**
     * Where a spell is aimed.
     *
     * A cell if the client sent one, otherwise the named target's square, and
     * failing both the caster's own. Falling back rather than refusing keeps
     * the monster AI and any half-updated client honest: a spell that has to be
     * placed is still castable at somebody.
     *
     * @return array{0:int,1:int}
     */
    private function aimPoint(array $state, array $actor, string $targetCid, ?int $x, ?int $y): array
    {
        if ($x !== null && $y !== null && BattleGrid::inBounds($x, $y)) {
            return [$x, $y];
        }
        $target = $targetCid === '' ? null : $this->getCombatant($state, $targetCid);
        if ($target !== null && isset($target['x'], $target['y'])) {
            return [(int) $target['x'], (int) $target['y']];
        }
        return [(int) $actor['x'], (int) $actor['y']];
    }

    /**
     * The combatants a spell will actually touch.
     *
     * `$area`, when a spell has one, is the cell list BattleGrid::template()
     * produced. It bounds `all_enemies`, which used to mean literally every
     * enemy anywhere — a Burning Hands that caught the whole board. Encounters
     * were tuned against that, so a few area spells are genuinely weaker now,
     * and that is the correction rather than a regression.
     *
     * @param string[]|null $area
     * @return array<int, array>
     */
    private function spellTargets(
        array $state,
        array $actor,
        array $spell,
        string $targetCid,
        array $range,
        ?array $area = null
    ): array {
        $kind = (string) ($spell['target_kind'] ?? 'enemy');
        $reach = self::spellReach($range);

        if ($kind === 'self' && $area === null) {
            return [$actor];
        }

        if ($area !== null) {
            $cells = array_flip($area);
            $wantAllies = $kind === 'all_allies' || $kind === 'ally';
            $out = [];
            $pool = $wantAllies
                ? self::allyTargets($state['combatants'], $actor)
                : self::standing($state['combatants'], self::enemySide($actor));
            foreach ($pool as $c) {
                if (isset($cells[BattleGrid::keyOf((int) $c['x'], (int) $c['y'])])) {
                    $out[] = $c;
                }
            }
            return $out;
        }

        if ($kind === 'all_enemies') {
            return self::legalTargets($state['grid'], $state['combatants'], $actor, $reach);
        }
        if ($kind === 'all_allies') {
            return self::allyTargets($state['combatants'], $actor);
        }

        $target = $targetCid === '' ? null : $this->getCombatant($state, $targetCid);

        if ($kind === 'ally') {
            if (!$target || ($target['side'] ?? '') !== ($actor['side'] ?? '') || empty($target['alive'])) {
                return [$actor];
            }
            return [$target];
        }

        if ($kind === 'any' && $target && ($target['side'] ?? '') === ($actor['side'] ?? '')) {
            return empty($target['alive']) ? [] : [$target];
        }

        if (!$target || !self::isTargetable($target)) {
            throw new InvalidArgumentException('Invalid spell target.');
        }
        if (($target['side'] ?? '') === ($actor['side'] ?? '')) {
            throw new InvalidArgumentException('Cannot target an ally with that spell.');
        }
        if (!self::canReach($state['grid'], $state['combatants'], $actor, (string) $target['cid'], $reach)) {
            $dist = BattleGrid::feet(
                (int) $actor['x'],
                (int) $actor['y'],
                (int) $target['x'],
                (int) $target['y']
            );
            $carries = $reach['normal'] ?? $reach['reach'] ?? 0;
            throw new InvalidArgumentException(
                "{$target['name']} is {$dist} feet away, and that spell carries {$carries}."
            );
        }
        return [$target];
    }

    /**
     * A parsed spell range in the shape shotsFrom() reads.
     *
     * Touch is a reach; anything with a distance is a shot with no long-range
     * bracket, because a spell either reaches or it does not — there is no
     * disadvantage band on a Fire Bolt.
     *
     * @return array{reach:?int, normal:?int, long:?int}
     */
    private static function spellReach(array $range): array
    {
        if (($range['kind'] ?? '') === 'touch') {
            return ['reach' => (int) ($range['ft'] ?? 5), 'normal' => null, 'long' => null];
        }
        $ft = max(0, (int) ($range['ft'] ?? 0));
        return ['reach' => null, 'normal' => $ft, 'long' => $ft];
    }

    /**
     * One spell, landing on one target.
     *
     * `$cover` is what the terrain and the bodies between them are worth, and
     * `$isArea` says whether the spell arrived as a template. Together they are
     * the SRD rule that cover helps against a Dexterity save from a fireball —
     * which is the sort of thing players notice immediately and remember.
     */
    private function applySpellTo(
        array $state,
        string $casterCid,
        string $targetCid,
        array $spell,
        string $resolution,
        int $cover = 0,
        bool $isArea = false
    ): array {
        $caster = $this->getCombatant($state, $casterCid);
        $target = $this->getCombatant($state, $targetCid);
        if (!$caster || !$target) {
            return $state;
        }

        $dice = self::scaledSpellDice($spell, (int) ($caster['level'] ?? 1));
        $damageType = (string) ($spell['damage_type'] ?? 'force');
        $effect = json_decode($spell['effect_json'] ?? '{}', true) ?: [];
        $condition = isset($effect['condition']) ? strtolower((string) $effect['condition']) : null;
        $rounds = (int) ($spell['duration_rounds'] ?? 0);

        // --- healing -------------------------------------------------------
        if ($resolution === 'heal') {
            $ability = Rules::spellcastingAbility((string) ($caster['class'] ?? '')) ?? 'wis';
            $mod = max(0, Rules::abilityMod((int) ($caster[$ability] ?? 10)));
            $expr = $dice ?: '1d8';

            // A ward rather than a mending. `{"temp_hp": true}` on a healing
            // spell says the number is temporary hit points, which take no
            // spellcasting modifier and do not stack — the SRD is explicit that
            // you choose between the new lot and what you have, so a second
            // casting on somebody already warded is only worth taking if it
            // rolls higher.
            if (!empty($effect['temp_hp'])) {
                $roll = Dice::roll($expr);
                return $this->grantTempHp($state, $targetCid, (int) $roll['total']);
            }

            // Stabilising is not healing and restores nothing. Spare the Dying
            // was authored as `heal 1d4` because there was no such thing as
            // being stable to reach for — a downed character stayed down until
            // hit points arrived, so healing them a little was the closest
            // available reading. Now that death saves exist it is the wrong
            // one: the SRD spell explicitly restores no hit points.
            if (!empty($effect['stabilise'])) {
                return $this->stabilise($state, $targetCid);
            }

            // A spell's damage_dice is bare dice; a potion's already carries a
            // modifier, and Dice rejects "2d4+2+3".
            if (!preg_match('/[+-]/', $expr)) {
                $expr .= sprintf('%+d', $mod);
            }
            // The Healer feat belongs to whoever is casting, not to whoever is
            // being mended.
            $roll = self::healingRoll($caster, $expr);
            $healed = (int) $roll['total'];
            // Disciple of Life: +2 + the SLOT's level on any levelled healing
            // spell — an upcast cure pays more. Cantrips stay out by the
            // SRD's own wording ("of 1st level or higher").
            if ((int) ($spell['level'] ?? 0) >= 1
                && Rules::subclassFeature($caster, 'disciple_of_life')) {
                $healed += 2 + (int) ($spell['cast_level'] ?? $spell['level']);
            }
            return $this->heal($state, $targetCid, $healed);
        }

        // --- a buff: a condition, a cure, a boon, or any mix of the three ---
        if ($resolution === 'buff') {
            if ($condition !== null) {
                $state = $this->addCondition($state, $targetCid, [
                    'key' => $condition, 'rounds' => $rounds > 0 ? $rounds : null, 'source_cid' => $casterCid,
                ]);
            }

            // Lesser Restoration and its kin: lift what the target is under.
            $cured = [];
            foreach ((array) ($effect['cure'] ?? []) as $ill) {
                $ill = strtolower(trim((string) $ill));
                $subject = $this->getCombatant($state, $targetCid);
                if ($ill !== '' && $subject && Conditions::has($subject, $ill)) {
                    $state = $this->removeCondition($state, $targetCid, $ill);
                    $cured[] = Conditions::CATALOG[$ill]['label'] ?? $ill;
                }
            }
            if ($cured) {
                $state['log'][] = "{$target['name']} is freed from " . implode(' and ', $cured) . '.';
            }

            // The numeric half. `effect.uses` marks a boon spent on the first
            // roll that reads it — Guidance is one check, not ten.
            $boon = ['key' => strtolower((string) $spell['name']), 'label' => (string) $spell['name']];
            $carries = false;
            foreach (self::BOON_FIELDS as $field) {
                if (isset($effect[$field]) && $effect[$field] !== '' && $effect[$field] !== 0) {
                    $boon[$field] = is_numeric($effect[$field]) ? (int) $effect[$field] : (string) $effect[$field];
                    $carries = true;
                }
            }
            if ($carries) {
                // A concentration spell is already on a clock — the caster's.
                // Giving the boon a second countdown would mean two timers for
                // one spell, free to disagree; concentration owns the duration
                // and dropConcentration() takes the boon back with it.
                $boon['rounds'] = ((int) ($spell['concentration'] ?? 0) === 1 || $rounds <= 0) ? null : $rounds;
                $boon['source_cid'] = $casterCid;
                $state = $this->addBoon($state, $targetCid, $boon);
            } elseif ($condition === null && !$cured) {
                // Nothing declared anything. Better a line in the log than a
                // slot silently vanishing, which is how this whole class of bug
                // went unnoticed in the first place.
                $state['log'][] = "{$spell['name']} settles over {$target['name']}.";
            }
            return $state;
        }

        // --- a saving throw ------------------------------------------------
        if ($resolution === 'save' || ($resolution === 'debuff' && !empty($spell['save_ability']))) {
            // Sculpt Spells: an evoker's allies inside their own blast are
            // simply not in it. The SRD caps how many can be spared and asks
            // the caster to choose; with nobody to ask, sparing every ally is
            // the only choice a player would make anyway.
            if ($isArea
                && $target['side'] === $caster['side']
                && Rules::subclassFeature($caster, 'sculpt_spells')) {
                $state['log'][] = "The blast bends around {$target['name']}.";
                return $state;
            }

            // Fey Ancestry's second half: magic cannot put an elf to sleep.
            // Checked before the save is even rolled — the SRD wording is not
            // "advantage against", it is "can't".
            if ($condition === 'unconscious'
                && strtolower((string) ($spell['school'] ?? '')) === 'enchantment'
                && Rules::raceFeature($target, 'fey_ancestry')) {
                $state['log'][] = "{$target['name']} cannot be lulled — the fey blood keeps its own counsel.";
                return $state;
            }

            $ability = (string) (($spell['save_ability'] ?? null) ?: 'dex');
            $dc = Rules::spellSaveDc($caster);
            // Cover helps you dive out of the way of an area effect, and only
            // of an area effect: crouching behind a crate does nothing about a
            // Hold Person aimed at you personally.
            $effectiveDc = ($isArea && $ability === 'dex') ? max(1, $dc - max(0, $cover)) : $dc;
            $result = $this->rollSave($target, $ability, $effectiveDc, false, [
                'magic'       => true,
                'condition'   => $condition,
                'damage_type' => $damageType,
            ]);
            $dc = $effectiveDc;

            $state = $this->emit($state, [
                'type' => 'save', 'actor' => $targetCid, 'ability' => $ability, 'dc' => $dc,
                'natural' => $result['natural'], 'total' => $result['total'], 'passed' => $result['passed'],
            ]);

            $raw = $dice === '' ? 0 : (int) Dice::rollDetailed($dice)['total'];
            $amount = self::saveDamage($raw, $result['passed'], (string) ($spell['save_effect'] ?? 'half'));

            if ($amount > 0) {
                $after = Rules::damageAfterDefenses($amount, $damageType, $target);
                $amount = $after['amount'];
                $note = $after['note'] === null ? '' : " ({$target['name']} is {$after['note']})";
                $state['log'][] = "{$target['name']} " . ($result['passed'] ? 'partly resists' : 'is caught')
                    . ": {$result['total']} vs DC {$dc}, {$amount} {$damageType} damage{$note}.";
                $state = $this->takeDamage($state, $targetCid, $amount);
            } else {
                $state['log'][] = "{$target['name']} makes the save ({$result['total']} vs DC {$dc}).";
            }

            if (!$result['passed'] && $condition !== null) {
                $state = $this->addCondition($state, $targetCid, [
                    'key' => $condition, 'rounds' => $rounds > 0 ? $rounds : null,
                    'source_cid' => $casterCid, 'save_ability' => $ability, 'save_dc' => $dc,
                ]);
            }
            return $state;
        }

        // --- an attack roll, or one that simply lands ----------------------
        if ($resolution === 'attack') {
            $range = Rules::spellRange(
                (string) ($spell['range_text'] ?? ''),
                (int) ($spell['reaches_back_rank'] ?? 1)
            );
            $reach = ($range['kind'] ?? '') === 'touch' ? 'melee' : 'ranged';
            $weapon = [
                'name'        => (string) $spell['name'],
                'bonus'       => Rules::spellAttackBonus($caster),
                'damage'      => $dice ?: '1d8',
                'damage_type' => $damageType,
                'reach'       => $reach,
                'range'       => self::spellReach($range),
                // A spell attack carries `spell` and its reach and nothing
                // else. That is what keeps Archery off a Fire Bolt and Great
                // Weapon Master off an Inflict Wounds: the feats name weapon
                // properties, and a spell has none.
                'tags'        => ['spell', $reach],
            ];
            // A spell attack has already had its range checked by
            // spellTargets(); what it still needs from a shot is the cover.
            // Long range does not apply — a Fire Bolt reaches or it does not.
            $shot = [
                'dist'  => BattleGrid::feet(
                    (int) $caster['x'],
                    (int) $caster['y'],
                    (int) $target['x'],
                    (int) $target['y']
                ),
                'cover' => $cover, 'melee' => $reach === 'melee',
                'long' => false, 'crowded' => false, 'flank' => false,
            ];
            return $this->resolveAttack($state, $casterCid, $targetCid, $weapon, null, $shot);
        }

        // 'auto' — Magic Missile and its kin.
        $raw = $dice === '' ? 0 : (int) Dice::rollDetailed($dice)['total'];
        $after = Rules::damageAfterDefenses($raw, $damageType, $target);
        $state['log'][] = "{$spell['name']} strikes {$target['name']} for {$after['amount']} {$damageType} damage.";
        $state = $this->emit($state, [
            'type' => 'attack', 'actor' => $casterCid, 'target' => $targetCid,
            'hit' => true, 'crit' => false, 'natural' => 0, 'total' => 0,
            // Cover deliberately not applied. Magic Missile does not miss, so
            // there is no roll for a bonus to armour class to affect.
            'ac' => self::effectiveAc($target), 'damage' => $after['amount'], 'damage_type' => $damageType,
            'mode' => 'auto',
        ]);
        if ($condition !== null) {
            $state = $this->addCondition($state, $targetCid, [
                'key' => $condition, 'rounds' => $rounds > 0 ? $rounds : null, 'source_cid' => $casterCid,
            ]);
        }
        return $this->takeDamage($state, $targetCid, $after['amount']);
    }

    /**
     * The dice a cast actually rolls: the spell's own, plus what the slot or
     * the caster's years add.
     *
     * Two scalings, mutually exclusive by construction. A levelled spell cast
     * from a bigger slot adds `higher_level_json.dice_per_level` per level
     * above its own — the loader has already refused any spec whose die size
     * cannot fold into the base expression. A cantrip ignores slots and
     * doubles its dice at caster level 5; MAX_LEVEL is 6, so the SRD's 11th-
     * and 17th-level tiers have no rows here to be wrong about.
     */
    public static function scaledSpellDice(array $spell, int $casterLevel): string
    {
        $base = (string) ($spell['damage_dice'] ?? '');
        if (!preg_match('/^(\d+)d(\d+)([+-]\d+)?$/', $base, $m)) {
            return $base;
        }
        $count = (int) $m[1];
        $sides = (int) $m[2];
        $tail = $m[3] ?? '';

        $spellLevel = (int) ($spell['level'] ?? 0);
        if ($spellLevel === 0) {
            if ($casterLevel >= 5) {
                $count *= 2;
            }
            return $count . 'd' . $sides . $tail;
        }

        $castLevel = (int) ($spell['cast_level'] ?? $spellLevel);
        $steps = $castLevel - $spellLevel;
        if ($steps > 0) {
            $hl = json_decode((string) ($spell['higher_level_json'] ?? ''), true) ?: [];
            if (preg_match('/^(\d+)d(\d+)$/', (string) ($hl['dice_per_level'] ?? ''), $per)
                && (int) $per[2] === $sides) {
                $count += $steps * (int) $per[1];
            }
        }
        return $count . 'd' . $sides . $tail;
    }

    /**
     * The slot ledger, delegated. Spellbook is the one owner now that
     * casting also happens outside a fight; two copies of "which slot does a
     * Warlock burn" is the drift the delegation prevents.
     */
    private function slotsExpended(int $characterId): array
    {
        return (new Spellbook($this->db))->expended($characterId);
    }

    private function spendSlot(int $characterId, int $level): void
    {
        (new Spellbook($this->db))->spend($characterId, $level);
    }

    // =======================================================================
    // Damage, healing, conditions, concentration
    // =======================================================================

    /**
     * `mode` rides along because a save made with advantage is a fact worth
     * being able to state — the attack path has said so since it was written,
     * and a save that quietly rolled two dice looked identical to one that
     * rolled well.
     *
     * @return array{natural:int, total:int, passed:bool, auto_fail:bool, mode:string}
     */
    /**
     * `$tags` says what the save is against, which the ability alone cannot:
     * `condition` names what a failure inflicts, `damage_type` what it deals,
     * `magic` whether a spell forced it. The racial instincts below are all
     * claims about the *against* — a dwarf is not better at Constitution, they
     * are better at poison — so without the tags none of them could exist.
     */
    private function rollSave(
        array $subject,
        string $ability,
        int $dc,
        bool $advantage = false,
        array $tags = []
    ): array {
        $ctx = Conditions::saveContext($subject, $ability);
        if (!empty($ctx['auto_fail'])) {
            return ['natural' => 0, 'total' => 0, 'passed' => false, 'auto_fail' => true,
                    'mode' => 'normal'];
        }
        $mod = Rules::savingThrow($subject, $ability)['total'];
        // Dodge is half a defence against saves as well as against attacks:
        // "you make Dexterity saving throws with advantage". Only the attack
        // half was implemented — `dodging` was read in exactly two places, both
        // of them attack resolution — so a rogue who dodged a breath weapon got
        // nothing for it. Applied here rather than at the four call sites,
        // because this is where a save is decided.
        $isDex = str_starts_with(strtolower(trim($ability)), 'dex');
        if (!empty($subject['dodging']) && $isDex) {
            $advantage = true;
        }
        // Danger Sense: "advantage on Dexterity saving throws against effects
        // that you can see". Sitting beside Dodge because it is the same claim
        // about the same die, and because a Barbarian who is also dodging
        // should get advantage once rather than twice.
        if ($isDex && Rules::hasFeature(
            (string) ($subject['class'] ?? ''),
            (int) ($subject['level'] ?? 1),
            'danger_sense'
        )) {
            $advantage = true;
        }

        // The racial instincts, each keyed off what the save is against.
        $vsCondition = strtolower((string) ($tags['condition'] ?? ''));
        $vsDamage = strtolower((string) ($tags['damage_type'] ?? ''));
        $abilityKey = strtolower(substr(trim($ability), 0, 3));
        if ($vsCondition === 'frightened' && Rules::raceFeature($subject, 'brave')) {
            $advantage = true;
        }
        if ($vsCondition === 'charmed' && Rules::raceFeature($subject, 'fey_ancestry')) {
            $advantage = true;
        }
        if (($vsCondition === 'poisoned' || $vsDamage === 'poison')
            && (Rules::raceFeature($subject, 'dwarven_resilience')
                || Rules::raceFeature($subject, 'stout_resilience'))) {
            $advantage = true;
        }
        if (!empty($tags['magic'])
            && in_array($abilityKey, ['int', 'wis', 'cha'], true)
            && Rules::raceFeature($subject, 'gnome_cunning')) {
            $advantage = true;
        }
        // A boon may promise the same thing — an antitoxin carries
        // {"save_advantage_vs": "poison"}. 'poison' covers both the damage
        // and the condition, because nobody authoring a phial cares which.
        foreach ((array) ($subject['boons'] ?? []) as $held) {
            $vs = strtolower((string) ($held['save_advantage_vs'] ?? ''));
            if ($vs === '') {
                continue;
            }
            if ($vs === $vsCondition || $vs === $vsDamage
                || ($vs === 'poison' && $vsCondition === 'poisoned')) {
                $advantage = true;
            }
        }

        // `$advantage` is folded in through rollMode rather than forced, so a
        // saver who is also under something that hampers them still gets the
        // SRD's cancellation instead of a free advantage.
        $mode = self::rollMode($ctx, $advantage);
        $roll = Dice::d20Mode($mod, $mode);
        // Halfling Luck: a natural 1 is thrown again and the new die stands,
        // whatever it says. Rolled plain rather than re-running the mode —
        // the trait rerolls the one die that came up 1, not the whole throw.
        if ((int) $roll['natural'] === 1 && Rules::raceFeature($subject, 'halfling_luck')) {
            $roll = Dice::d20Mode($mod, 'normal');
        }
        $total = (int) $roll['total'] + $this->boonBonus($subject, 'save_bonus_dice');
        return [
            'natural'   => (int) $roll['natural'],
            'total'     => $total,
            'passed'    => $total >= $dc,
            'auto_fail' => false,
            'mode'      => $mode,
        ];
    }

    /** Commit damage: hit points, concentration, and going down. */
    private function takeDamage(array $state, string $cid, int $amount, bool $crit = false): array
    {
        $target = $this->getCombatant($state, $cid);
        if (!$target || $amount <= 0) {
            return $state;
        }

        // Hitting somebody who is already down does not take hit points off
        // them — they have none — it takes a death save. Checked before the
        // arithmetic, because after it nothing distinguishes this from any
        // other blow that left them on zero.
        if (self::isDying($target)) {
            return $this->failDeathSave($state, $cid, $crit);
        }

        // Temporary hit points are spent first and are not hit points: they do
        // not heal, they do not carry a maximum, and a blow that only eats them
        // reaches nothing underneath. That last part is why this is here rather
        // than folded into the subtraction below — a hit absorbed entirely by
        // a ward must not roll a concentration check or drop anybody.
        $temp = max(0, (int) ($target['temp_hp'] ?? 0));
        if ($temp > 0) {
            $soaked = min($temp, $amount);
            $state = $this->updateCombatant($state, $cid, ['temp_hp' => $temp - $soaked]);
            $amount -= $soaked;
            $state['log'][] = $amount > 0
                ? "{$target['name']}'s ward takes {$soaked} of it and breaks."
                : "{$target['name']}'s ward takes all {$soaked}.";
            $state = $this->emit($state, [
                'type' => 'temp_hp', 'target' => $cid,
                'soaked' => $soaked, 'left' => $temp - $soaked,
            ]);
            if ($amount <= 0) {
                return $state;
            }
            $target = $this->getCombatant($state, $cid) ?? $target;
        }

        $newHp = max(0, (int) $target['hp'] - $amount);
        $overkill = $amount - max(0, (int) $target['hp']);
        $state = $this->updateCombatant($state, $cid, ['hp' => $newHp]);
        if ($target['type'] === 'pc') {
            $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
                ->execute([$newHp, (int) $target['character_id']]);
        }

        if (is_array($target['concentrating'] ?? null)) {
            $state = $this->concentrationCheck($state, $cid, $amount);
        }

        if ($newHp <= 0) {
            $stood = $this->relentlessEndurance($state, $cid, max(0, $overkill));
            if ($stood !== null) {
                return $stood;
            }
            $state = $this->dropCombatant($state, $cid, max(0, $overkill));
        }
        return $state;
    }

    /**
     * Relentless Endurance: dropped to zero but not killed outright, a
     * half-orc stands on 1 hit point instead. Once per long rest, spent
     * through the same world flag Rage and Second Wind use so the rest that
     * returns theirs returns this.
     *
     * Returns the new state, or null when the trait does not apply and the
     * fall goes ahead.
     */
    private function relentlessEndurance(array $state, string $cid, int $overkill): ?array
    {
        $target = $this->getCombatant($state, $cid);
        if (!$target
            || $target['type'] !== 'pc'
            || (int) ($target['relentless_left'] ?? 0) < 1
            || !Rules::raceFeature($target, 'relentless_endurance')
            // "…but not killed outright": damage past zero reaching the hit
            // point maximum is the SRD's instant death, and the trait's own
            // text stands aside for it.
            || $overkill >= (int) $target['max_hp']) {
            return null;
        }

        $state = $this->updateCombatant($state, $cid, [
            'hp' => 1,
            'relentless_left' => 0,
        ]);
        $this->db->prepare('UPDATE characters SET current_hp = 1 WHERE id = ?')
            ->execute([(int) $target['character_id']]);

        $characterId = (int) $target['character_id'];
        $partyId = $characterId ? WorldState::partyIdFor($this->db, $characterId) : null;
        if ($partyId) {
            (new WorldState($this->db))->increment(
                $partyId,
                self::bonusFeatureFlag('relentless_endurance', $characterId)
            );
        }

        $state['log'][] = "{$target['name']} refuses to fall — Relentless Endurance.";
        return $this->emit($state, [
            'type' => 'heal', 'target' => $cid, 'amount' => 1, 'hp' => 1,
        ]);
    }

    /**
     * A ward over somebody's hit points, which is not the same as hit points.
     *
     * The SRD does not add: "if you have temporary hit points and receive more,
     * you decide whether to keep the ones you have or gain the new ones". There
     * is nobody to ask here, so the larger is kept, which is the choice anybody
     * would make. A smaller second casting is refused rather than silently
     * wasting the first.
     */
    private function grantTempHp(array $state, string $cid, int $amount): array
    {
        $target = $this->getCombatant($state, $cid);
        if (!$target || $amount <= 0) {
            return $state;
        }
        $held = max(0, (int) ($target['temp_hp'] ?? 0));
        if ($amount <= $held) {
            $state['log'][] = "{$target['name']} already has a stronger ward ({$held}).";
            return $state;
        }
        $state = $this->updateCombatant($state, $cid, ['temp_hp' => $amount]);
        $state['log'][] = "{$target['name']} gains {$amount} temporary hit points.";
        return $this->emit($state, [
            'type' => 'temp_hp', 'target' => $cid, 'granted' => $amount, 'left' => $amount,
        ]);
    }

    private function heal(array $state, string $cid, int $amount): array
    {
        $target = $this->getCombatant($state, $cid);
        if (!$target || $amount <= 0) {
            return $state;
        }
        $newHp = min((int) $target['max_hp'], (int) $target['hp'] + $amount);
        $state = $this->updateCombatant($state, $cid, ['hp' => $newHp]);
        if ($target['type'] === 'pc') {
            $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
                ->execute([$newHp, (int) $target['character_id']]);
        }
        $state['log'][] = "{$target['name']} recovers {$amount} HP.";
        $state = $this->emit($state, ['type' => 'heal', 'target' => $cid, 'amount' => $amount, 'hp' => $newHp]);

        // Healing a downed character puts them back on their feet, and wipes
        // the slate: the SRD's "regaining any hit points" ends the dying
        // outright, so two failures accrued a round ago do not follow somebody
        // who has been patched up and knocked down again.
        if ($newHp > 0 && Conditions::has($target, 'unconscious')) {
            $state = $this->updateCombatant($state, $cid, [
                'stable' => false, 'death_saves' => self::freshDeathSaves(),
            ]);
            $state = $this->removeCondition($state, $cid, 'unconscious');
            $state['log'][] = "{$target['name']} is back on their feet.";
        }
        return $state;
    }

    // =======================================================================
    // Dying
    //
    // A player character at zero used to gain Unconscious and stay there for
    // the rest of the fight, and `alive => false` was unreachable for anything
    // but a monster: there were no death saves, no stabilising, and no way for
    // a character to die. Falling had one consequence and it was "out of the
    // fight until somebody heals you".
    // =======================================================================

    /** Successes or failures that settle it, either way. */
    private const DEATH_SAVE_TARGET = 3;

    /** Ten or better on a flat d20 — no modifiers, no proficiency, ever. */
    private const DEATH_SAVE_DC = 10;

    /**
     * Whether death outlives the fight.
     *
     * FALSE, and deliberately: a character who bleeds out is gone for the rest
     * of that fight and cannot be healed back up in it, which is what makes the
     * three rounds matter — but the survivors carry them out afterwards. Real
     * permadeath needs somewhere to record it (`characters` has no column for
     * being dead), a way back (no revivify here), and a decision about what
     * happens to a party whose only member dies. None of those should be
     * invented quietly inside a combat engine.
     */
    private const DEATH_IS_PERMANENT = false;

    /** At zero, not yet stable, and not yet gone. */
    public static function isDying(array $c): bool
    {
        return ($c['type'] ?? '') === 'pc'
            && !empty($c['alive'])
            && (int) ($c['hp'] ?? 0) <= 0
            && empty($c['stable'])
            && Conditions::has($c, 'unconscious');
    }

    /** A fresh slate: nobody carries last fight's failures into this one. */
    private static function freshDeathSaves(): array
    {
        return ['success' => 0, 'fail' => 0];
    }

    /**
     * One death saving throw, at the start of a dying character's turn.
     *
     * A flat d20 against 10 — the SRD is explicit that this save is tied to no
     * ability and gains no proficiency, which is why it does not go anywhere
     * near rollSave(). A natural 20 is worth a hit point rather than a success,
     * and a natural 1 is worth two failures.
     */
    private function deathSave(array $state, string $cid): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        $saves = is_array($c['death_saves'] ?? null) ? $c['death_saves'] : self::freshDeathSaves();
        $roll = Dice::d20(0);
        $natural = (int) $roll['natural'];

        if ($natural === 20) {
            $state['log'][] = "{$c['name']} comes round — a hit point, and back in the fight.";
            $state = $this->emit($state, [
                'type' => 'death_save', 'target' => $cid, 'natural' => $natural,
                'outcome' => 'revive', 'success' => 0, 'fail' => 0,
            ]);
            return $this->reviveAtOne($state, $cid);
        }

        if ($natural === 1) {
            $saves['fail'] += 2;
            $state['log'][] = "{$c['name']} fails badly — two strikes against them.";
        } elseif ($natural >= self::DEATH_SAVE_DC) {
            $saves['success']++;
            $state['log'][] = "{$c['name']} holds on ({$natural}).";
        } else {
            $saves['fail']++;
            $state['log'][] = "{$c['name']} slips further ({$natural}).";
        }

        $state = $this->updateCombatant($state, $cid, ['death_saves' => $saves]);
        $outcome = 'none';
        if ($saves['fail'] >= self::DEATH_SAVE_TARGET) {
            $outcome = 'died';
        } elseif ($saves['success'] >= self::DEATH_SAVE_TARGET) {
            $outcome = 'stable';
        }

        $state = $this->emit($state, [
            'type' => 'death_save', 'target' => $cid, 'natural' => $natural,
            'outcome' => $outcome,
            'success' => (int) $saves['success'], 'fail' => (int) $saves['fail'],
        ]);

        if ($outcome === 'died') {
            return $this->killOutright($state, $cid, 'bleeds out');
        }
        if ($outcome === 'stable') {
            $state['log'][] = "{$c['name']} is stable — still down, but no longer slipping.";
            return $this->updateCombatant($state, $cid, ['stable' => true]);
        }
        return $state;
    }

    /**
     * Damage taken while already down, which is worth a failure by itself.
     *
     * Two for a critical, which is the SRD's rule and the reason takeDamage()
     * has to be told whether the blow was one — a monster finishing off a
     * downed character is the commonest way this is reached, and it should not
     * take the same three rounds as bleeding does.
     */
    private function failDeathSave(array $state, string $cid, bool $crit): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c || !self::isDying($c)) {
            return $state;
        }
        $saves = is_array($c['death_saves'] ?? null) ? $c['death_saves'] : self::freshDeathSaves();
        $saves['fail'] += $crit ? 2 : 1;
        $state = $this->updateCombatant($state, $cid, ['death_saves' => $saves]);
        $state['log'][] = $crit
            ? "{$c['name']} is struck while down — twice against them."
            : "{$c['name']} is struck while down.";

        $state = $this->emit($state, [
            'type' => 'death_save', 'target' => $cid, 'natural' => 0,
            'outcome' => $saves['fail'] >= self::DEATH_SAVE_TARGET ? 'died' : 'none',
            'success' => (int) $saves['success'], 'fail' => (int) $saves['fail'],
        ]);

        return $saves['fail'] >= self::DEATH_SAVE_TARGET
            ? $this->killOutright($state, $cid, 'does not get up')
            : $state;
    }

    /**
     * Stop somebody bleeding without putting anything back.
     *
     * They stay at zero and stay unconscious; what changes is that they stop
     * rolling. Refused on anybody who is not dying, so the cantrip cannot be
     * spent on a healthy ally by mistake.
     */
    private function stabilise(array $state, string $cid): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        if (!self::isDying($c)) {
            $state['log'][] = "{$c['name']} is not dying — nothing to hold together.";
            return $state;
        }
        $state = $this->updateCombatant($state, $cid, [
            'stable' => true, 'death_saves' => self::freshDeathSaves(),
        ]);
        $state['log'][] = "{$c['name']} is stabilised — still down, but no longer slipping.";
        return $this->emit($state, [
            'type' => 'death_save', 'target' => $cid, 'natural' => 0,
            'outcome' => 'stable', 'success' => 0, 'fail' => 0,
        ]);
    }

    /** Up at one hit point, saves wiped — a natural 20, or any healing. */
    private function reviveAtOne(array $state, string $cid): array
    {
        $state = $this->updateCombatant($state, $cid, [
            'hp' => 1, 'stable' => false, 'death_saves' => self::freshDeathSaves(),
        ]);
        $c = $this->getCombatant($state, $cid);
        if ($c && ($c['type'] ?? '') === 'pc') {
            $this->db->prepare('UPDATE characters SET current_hp = 1 WHERE id = ?')
                ->execute([(int) $c['character_id']]);
        }
        return $this->removeCondition($state, $cid, 'unconscious');
    }

    /** The end of it, for a player character. */
    private function killOutright(array $state, string $cid, string $how): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        $state = $this->updateCombatant($state, $cid, [
            'alive' => false, 'stable' => false, 'dodging' => false,
        ]);
        $state['log'][] = "{$c['name']} {$how}.";
        return $this->emit($state, ['type' => 'death', 'target' => $cid, 'dead' => true]);
    }

    /**
     * Falling over, and the one way to skip straight past it.
     *
     * A player character at 0 keeps `alive => true` and gains Unconscious: the
     * fight is lost when the last of them falls, not when the last of them
     * dies, and a healing spell has to have something left to heal. What is new
     * is that staying there is no longer free — see deathSave() above.
     *
     * `$overkill` is the damage left over after the blow reached zero. The SRD
     * kills outright when that leftover is at least the character's hit point
     * maximum, which is the one case where a character never gets a save at
     * all. Nothing measured it before because nothing could act on it.
     */
    private function dropCombatant(array $state, string $cid, int $overkill = 0): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }

        $state = $this->dropConcentration($state, $cid, 'falls');

        if ($c['type'] === 'pc') {
            if ($overkill >= (int) $c['max_hp'] && (int) $c['max_hp'] > 0) {
                return $this->killOutright($state, $cid, 'is killed outright');
            }
            // addCondition() writes the log line; `death` is emitted for the
            // client's benefit with dead => false, because the animation for
            // going down is the same one either way.
            $state = $this->updateCombatant($state, $cid, [
                'stable' => false, 'death_saves' => self::freshDeathSaves(),
            ]);
            $state = $this->addCondition($state, $cid, ['key' => 'unconscious']);
            return $this->emit($state, ['type' => 'death', 'target' => $cid, 'dead' => false]);
        }

        $state = $this->updateCombatant($state, $cid, ['alive' => false, 'dodging' => false]);
        $state['log'][] = "{$c['name']} is defeated!";
        return $this->emit($state, ['type' => 'death', 'target' => $cid, 'dead' => true]);
    }

    private function addCondition(array $state, string $cid, array $condition): array
    {
        $key = strtolower(trim((string) ($condition['key'] ?? '')));
        if (!isset(Conditions::CATALOG[$key])) {
            return $state;
        }
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        $condition['key'] = $key;
        $updated = Conditions::add($c, $condition);
        $state = $this->updateCombatant($state, $cid, ['conditions' => $updated['conditions'] ?? []]);

        if (!Conditions::has($updated, $key)) {
            // Immune: Conditions::add() hands the combatant back untouched.
            $state['log'][] = "{$c['name']} is immune to " . Conditions::CATALOG[$key]['label'] . '.';
            return $state;
        }
        $state['log'][] = "{$c['name']} is " . Conditions::CATALOG[$key]['label'] . '.';
        return $this->emit($state, ['type' => 'condition', 'target' => $cid, 'key' => $key, 'applied' => true]);
    }

    private function removeCondition(array $state, string $cid, string $key): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c || !Conditions::has($c, $key)) {
            return $state;
        }
        $updated = Conditions::remove($c, $key);
        $state = $this->updateCombatant($state, $cid, ['conditions' => $updated['conditions'] ?? []]);
        return $this->emit($state, ['type' => 'condition', 'target' => $cid, 'key' => $key, 'applied' => false]);
    }

    // =======================================================================
    // Boons — the numeric half of a buff
    //
    // Conditions carry the things a creature *is* (poisoned, prone), and
    // Conditions::CATALOG gates their keys. A +2 to AC or Bless's +1d4 is
    // neither a condition nor in that catalog, so before this existed the
    // `buff` resolution read `effect.condition`, found none, and returned the
    // state untouched: every buff in the game spent a slot and did nothing.
    // Boons are the other track. They live beside conditions on the combatant,
    // age the same way, and are read at the three places a bonus can matter —
    // the attack roll, the saving throw, and the armour class being shot at.
    // =======================================================================

    /**
     * The dice/number fields a boon may carry, and where each is spent.
     *
     * These are the key names the spell JSON already used while nothing read
     * them — `attack_bonus_dice` and friends. Kept verbatim rather than
     * renamed, so the authored content did not have to be rewritten to start
     * working.
     */
    private const BOON_FIELDS = [
        'ac', 'attack_bonus_dice', 'save_bonus_dice', 'damage_bonus_dice', 'check_bonus_dice',
    ];

    private function addBoon(array $state, string $cid, array $boon): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        $boons = array_values(array_filter(
            $c['boons'] ?? [],
            // One source does not stack with itself: recasting Bless refreshes
            // it rather than granting a second +1d4.
            static fn ($b) => ($b['key'] ?? '') !== ($boon['key'] ?? '')
        ));
        $boons[] = $boon;
        $state = $this->updateCombatant($state, $cid, ['boons' => $boons]);

        $state['log'][] = "{$c['name']} is under {$boon['label']}.";
        return $this->emit($state, [
            'type' => 'boon', 'target' => $cid, 'key' => $boon['key'],
            'label' => $boon['label'], 'applied' => true,
        ]);
    }

    /** Drop every boon a given spell is maintaining on a combatant. */
    private function removeBoon(array $state, string $cid, string $key): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c) {
            return $state;
        }
        $before = $c['boons'] ?? [];
        $after = array_values(array_filter($before, static fn ($b) => ($b['key'] ?? '') !== $key));
        if (count($after) === count($before)) {
            return $state;
        }
        $state = $this->updateCombatant($state, $cid, ['boons' => $after]);
        foreach ($before as $b) {
            if (($b['key'] ?? '') === $key) {
                $state = $this->restoreResistances($state, $cid, $b);
            }
        }
        return $this->emit($state, ['type' => 'boon', 'target' => $cid, 'key' => $key, 'applied' => false]);
    }

    /**
     * Hand back the resistance list a boon widened when it was granted.
     *
     * Boons carry no resistance field of their own, so one that changes what a
     * combatant shrugs off — Rage is the only one so far — snapshots the list
     * it found and this puts it back. Both ways out of a boon go through here,
     * because a resistance that outlives its boon is indistinguishable from a
     * boon that never ended.
     */
    private function restoreResistances(array $state, string $cid, array $boon): array
    {
        if (!isset($boon['restore_resistances']) || !is_array($boon['restore_resistances'])) {
            return $state;
        }
        return $this->updateCombatant($state, $cid, [
            'resistances' => array_values($boon['restore_resistances']),
        ]);
    }

    /**
     * Armour class as it currently stands, Shield of Faith and cover included.
     *
     * Every defensive read goes through here rather than `$c['ac']`, which is
     * only ever the number written on the sheet.
     *
     * COVER ARRIVES AS AN ARGUMENT because it is not a property of the
     * defender. The same creature behind the same crate has cover from the
     * archer across the room and none at all from the goblin beside them, so
     * folding it into the combatant would be recording a fact that is only true
     * of one attacker. The default of zero means every existing call site is
     * still correct and only the ones that know better have to say so.
     */
    private static function effectiveAc(array $c, int $cover = 0): int
    {
        $ac = (int) ($c['ac'] ?? 10);
        foreach ($c['boons'] ?? [] as $b) {
            $ac += (int) ($b['ac'] ?? 0);
        }
        return max(1, $ac + max(0, $cover));
    }

    /**
     * Roll every boon die of one kind and total them.
     *
     * Rolled at the moment of use, not when the boon was granted — Bless is
     * a fresh 1d4 on each attack, which is the whole character of the spell.
     */
    private function boonBonus(array $c, string $field): int
    {
        $total = 0;
        foreach ($c['boons'] ?? [] as $b) {
            $expr = trim((string) ($b[$field] ?? ''));
            if ($expr !== '') {
                $total += (int) Dice::rollDetailed($expr)['total'];
            }
        }
        return $total;
    }

    /** Age boons at the start of a turn and retire the ones that have run out. */
    private function tickBoons(array $state, string $cid): array
    {
        $c = $this->getCombatant($state, $cid);
        if (!$c || empty($c['boons'])) {
            return $state;
        }
        $keep = [];
        $expired = [];
        $counted = false;
        foreach ($c['boons'] as $b) {
            $rounds = $b['rounds'] ?? null;
            if ($rounds === null) {
                $keep[] = $b;
                continue;
            }
            $rounds = (int) $rounds - 1;
            $counted = true;
            if ($rounds > 0) {
                $b['rounds'] = $rounds;
                $keep[] = $b;
            } else {
                $expired[] = $b;
            }
        }
        // The decremented list has to be written even when nothing expired.
        // Returning early on `!$expired` discarded it, so a boon's counter was
        // only ever saved on the tick some *other* boon ran out — a lone Bless
        // sat on ten rounds for the whole fight and never ended.
        if (!$counted) {
            return $state;
        }
        $state = $this->updateCombatant($state, $cid, ['boons' => $keep]);
        if (!$expired) {
            return $state;
        }
        foreach ($expired as $b) {
            $state['log'][] = "{$b['label']} fades from {$c['name']}.";
            $state = $this->restoreResistances($state, $cid, $b);
            $state = $this->emit($state, [
                'type' => 'boon', 'target' => $cid, 'key' => $b['key'] ?? '',
                'label' => $b['label'] ?? ($b['key'] ?? ''), 'applied' => false,
            ]);
        }
        return $state;
    }

    private function concentrationCheck(array $state, string $cid, int $damage): array
    {
        $c = $this->getCombatant($state, $cid);
        $conc = $c['concentrating'] ?? null;
        if (!is_array($conc)) {
            return $state;
        }

        $dc = self::concentrationDc($damage);
        // War Caster is the one feat that changes how a save is rolled rather
        // than what it adds, so it is applied here instead of inside
        // rollSave() — every other Constitution save in the game is an ordinary
        // one, and a blanket advantage would be a different feat.
        $result = $this->rollSave(
            $c,
            'con',
            $dc,
            Feats::flag($c, 'concentration_save_advantage')
        );
        $state = $this->emit($state, [
            'type' => 'save', 'actor' => $cid, 'ability' => 'con', 'dc' => $dc,
            'natural' => $result['natural'], 'total' => $result['total'], 'passed' => $result['passed'],
        ]);

        if ($result['passed']) {
            $state['log'][] = "{$c['name']} holds {$conc['name']} together ({$result['total']} vs DC {$dc}).";
            return $state;
        }
        return $this->dropConcentration($state, $cid, 'broken');
    }

    /** Let go of a held spell and take back whatever it was maintaining. */
    private function dropConcentration(array $state, string $cid, string $why): array
    {
        $c = $this->getCombatant($state, $cid);
        $conc = $c['concentrating'] ?? null;
        if (!is_array($conc)) {
            return $state;
        }

        foreach ($conc['targets'] ?? [] as $tcid) {
            if (!empty($conc['condition'])) {
                $state = $this->removeCondition($state, (string) $tcid, (string) $conc['condition']);
            }
            // A concentration buff ends with the concentration. Without this,
            // Bless's +1d4 outlived the caster being knocked out of it.
            if (!empty($conc['boon'])) {
                $state = $this->removeBoon($state, (string) $tcid, (string) $conc['boon']);
            }
        }
        $state = $this->updateCombatant($state, $cid, ['concentrating' => null]);

        $tail = $why === 'replaced' ? 'to take up another spell' : 'and it ends';
        $state['log'][] = "{$c['name']} loses concentration on {$conc['name']} {$tail}.";
        return $this->emit($state, [
            'type' => 'concentration_broken', 'actor' => $cid, 'spell' => $conc['name'], 'reason' => $why,
        ]);
    }

    // =======================================================================
    // Monster AI
    //
    // There is a pathfinder again. Enumerate every legal option — shoot from
    // here, walk somewhere and then attack, or just walk somewhere better —
    // score each with scoreOption() and take the best.
    //
    // Everything spatial is counted here and handed to scoreOption() as plain
    // numbers. That division is deliberate and load-bearing: it is what lets
    // tools/test_combat.php check the monster's judgement against invented
    // options with no board underneath them.
    // =======================================================================

    private function runMonsterAI(array $state): array
    {
        $actor = $this->currentActor($state);
        if (!$actor || $actor['type'] !== 'monster' || !self::isTargetable($actor)) {
            return $this->endTurn($state);
        }
        if (!Conditions::canAct($actor)) {
            return $this->endTurn($state);
        }

        $terrain = $state['grid']['terrain'] ?? [];
        $combatants = $state['combatants'];

        // Worked out once and reused for every option below. A monster asking
        // these two questions per candidate cell is where the turn's cost runs
        // away from it.
        //
        // Through reachFor(), so a held or frightened monster is bound by the
        // same rules a held or frightened player is. A web spell that stopped
        // the party and not the spider would be worse than no web spell.
        $reach = $this->reachFor($state, $actor);
        $threat = BattleGrid::threatenedCells($terrain, $this->reactors($state, (string) $actor['cid']));

        // A stat block with no actions still gets one swing, from weaponFor()'s
        // default, rather than standing there for the whole fight.
        $indices = array_keys(($actor['actions'] ?? []) ?: [[]]);
        $enemies = self::standing($combatants, self::enemySide($actor));

        $best = null;
        $bestScore = 0.0;

        // 1. Everything it can hit without moving.
        foreach ($indices as $index) {
            $weapon = $this->weaponFor($actor, (int) $index);
            $shots = self::shotsFrom($state['grid'], $combatants, $actor, self::rangeOf($weapon));
            foreach ($shots as $tcid => $shot) {
                $option = $this->attackOption($state, $actor, $weapon, $shot) + [
                    'kind' => 'attack',
                    'safe' => empty($threat[BattleGrid::keyOf((int) $actor['x'], (int) $actor['y'])]),
                ];
                $score = self::scoreOption($actor, $option, $combatants);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['weapon' => $weapon, 'target_cid' => (string) $tcid, 'dest' => null, 'shot' => $shot];
                }
            }
        }

        // 2. Walk somewhere and then hit something.
        foreach ($indices as $index) {
            $weapon = $this->weaponFor($actor, (int) $index);
            $range = self::rangeOf($weapon);
            foreach ($enemies as $target) {
                $spot = $this->bestApproach($state, $actor, $target, $range, $reach, $terrain);
                if ($spot === null) {
                    continue;
                }
                $option = $this->attackOption($state, $actor, $weapon, $spot['shot']) + [
                    'kind'        => 'move_attack',
                    'move_cost'   => $spot['cost'],
                    'dest'        => $spot['dest'],
                    'provokes'    => $spot['provokes'],
                    'dest_cover'  => $spot['dest_cover'],
                    'dest_threat' => $spot['dest_threat'],
                ];
                $score = self::scoreOption($actor, $option, $combatants);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'weapon' => $weapon,
                        'target_cid' => (string) $target['cid'],
                        'dest' => $spot['dest'],
                        'shot' => $spot['shot'],
                    ];
                }
            }
        }

        // 3. If nothing worth attacking came up, is anywhere worth standing?
        $walk = null;
        if ($best === null || $best['dest'] === null) {
            $walk = $this->bestReposition($state, $actor, $enemies, $reach, $threat, $terrain);
        }

        // 4. Act.
        if ($best !== null) {
            if ($best['dest'] !== null) {
                $state = $this->walkTo($state, (string) $actor['cid'], $best['dest'], $reach, $terrain);
                // The walk may have eaten opportunity attacks, and one of them
                // may have been fatal. Nothing below is safe until this is
                // rechecked.
                $actor = $this->getCombatant($state, (string) $actor['cid']);
                if (!$actor || !self::isTargetable($actor) || !Conditions::canAct($actor)) {
                    return $this->endTurn($state);
                }
                // Re-take the shot from where it actually ended up: an
                // opportunity attack could have felled the target too, and the
                // parked shot describes a cell nobody is standing in now.
                $shots = self::shotsFrom(
                    $state['grid'],
                    $state['combatants'],
                    $actor,
                    self::rangeOf($best['weapon'])
                );
                if (!isset($shots[$best['target_cid']])) {
                    $state = $this->braceOrEnd($state, $actor);
                    return $this->endTurn($state);
                }
                $best['shot'] = $shots[$best['target_cid']];
            }

            $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true]);
            // Remember who was swung at, so the next monster in the order
            // prefers somebody else. Cleared at the top of each round.
            $tcid = $best['target_cid'];
            $state['focus'][$tcid] = (int) ($state['focus'][$tcid] ?? 0) + 1;
            $state = $this->resolveAttack(
                $state,
                (string) $actor['cid'],
                $tcid,
                $best['weapon'],
                null,
                $best['shot']
            );
            return $this->endTurn($state);
        }

        if ($walk !== null) {
            $state = $this->walkTo($state, (string) $actor['cid'], $walk['dest'], $reach, $terrain);
            $actor = $this->getCombatant($state, (string) $actor['cid']) ?? $actor;

            // Walked, and still nothing to hit. Running is what the action is
            // for; braceOrEnd() below is what a monster does when running would
            // not help either. Guarded because the walk may have been fatal —
            // an opportunity attack on the way is the same hazard step 4 above
            // rechecks for.
            if (self::isTargetable($actor) && Conditions::canAct($actor)) {
                $state = $this->dashOnward($state, $actor, $enemies, $terrain);
                $actor = $this->getCombatant($state, (string) $actor['cid']) ?? $actor;
            }
        }
        $state = $this->braceOrEnd($state, $actor);
        return $this->endTurn($state);
    }

    /**
     * Spend the action on running, and run.
     *
     * Only ever reached when nothing could be attacked from anywhere the walk
     * could get to, so the action is otherwise going to be spent on Dodge. The
     * choice between the two is made by scoreOption() rather than here, which
     * is what keeps the AI's judgement in the one testable pure function: a
     * dash that closes no ground scores zero and the monster takes cover.
     *
     * The second walk is priced from a freshly derived reach set, because the
     * first walk moved the monster and doubled nothing about the board.
     */
    private function dashOnward(array $state, array $actor, array $enemies, array $terrain): array
    {
        if (!empty($actor['action_used']) || !$enemies) {
            return $state;
        }

        $gain = max(0, (int) ($actor['speed'] ?? 30));
        if ($gain <= 0) {
            return $state;
        }

        // Dashing doubles what you may spend and lifts nothing that is holding
        // you: a grappled monster given twice nothing still has nothing.
        $budget = self::movementBudget($actor);
        if ($budget <= 0) {
            return $state;
        }
        $reach = $this->reachFor($state, $actor, $budget + $gain);
        $threat = BattleGrid::threatenedCells($terrain, $this->reactors($state, (string) $actor['cid']));
        $onward = $this->bestReposition($state, $actor, $enemies, $reach, $threat, $terrain);
        if ($onward === null) {
            return $state;
        }

        $dash = self::scoreOption($actor, [
            'kind'      => 'dash',
            'closes_ft' => (int) ($onward['closes_ft'] ?? 0),
            'provokes'  => (int) ($onward['provokes'] ?? 0),
        ], $state['combatants']);
        if ($dash <= self::scoreOption($actor, ['kind' => 'dodge'], $state['combatants'])) {
            return $state;
        }

        $state = $this->updateCombatant($state, $actor['cid'], [
            'action_used' => true,
            'move_left'   => max(0, (int) ($actor['move_left'] ?? 0)) + $gain,
        ]);
        $state['log'][] = "{$actor['name']} breaks into a run.";
        return $this->walkTo($state, (string) $actor['cid'], $onward['dest'], $reach, $terrain);
    }

    /** The scoring fields common to attacking from here and attacking after a walk. */
    private function attackOption(array $state, array $actor, array $weapon, array $shot): array
    {
        $target = $shot['c'];
        return [
            'target'          => $target,
            'shot'            => $shot,
            'expected_damage' => self::expectedDamage($weapon['damage']),
            'pack'            => self::hasTrait($actor, 'pack tactics')
                && $this->packSupport($state, $actor, $target),
            'already_targeted' => (int) ($state['focus'][$target['cid']] ?? 0),
        ];
    }

    /**
     * The cheapest cell this attack becomes legal from, if there is one.
     *
     * For a melee weapon only the cells around the target are worth testing —
     * at most eight, against the hundred-odd a monster can usually reach. That
     * shortcut is not an optimisation to add later; it is the difference
     * between a six-monster turn taking milliseconds and taking most of a
     * second inside a single HTTP request.
     */
    private function bestApproach(
        array $state,
        array $actor,
        array $target,
        array $range,
        array $reach,
        array $terrain
    ): ?array {
        $tx = (int) $target['x'];
        $ty = (int) $target['y'];
        $here = BattleGrid::keyOf((int) $actor['x'], (int) $actor['y']);

        if ($range['normal'] === null) {
            $r = BattleGrid::ftToCells((int) ($range['reach'] ?? self::DEFAULT_REACH_FT));
            $candidates = [];
            for ($y = $ty - $r; $y <= $ty + $r; $y++) {
                for ($x = $tx - $r; $x <= $tx + $r; $x++) {
                    $key = BattleGrid::keyOf($x, $y);
                    if ($key !== $here && isset($reach[$key]) && $reach[$key]['end']) {
                        $candidates[] = $key;
                    }
                }
            }
        } else {
            $candidates = [];
            foreach ($reach as $key => $entry) {
                if ($key !== $here && $entry['end']) {
                    $candidates[] = $key;
                }
            }
        }

        $best = null;
        foreach ($candidates as $key) {
            [$x, $y] = BattleGrid::parseKey($key);
            if ($best !== null && $reach[$key]['cost'] >= $best['cost']) {
                continue;   // already have somewhere cheaper that works
            }
            $from = $actor;
            $from['x'] = $x;
            $from['y'] = $y;
            $shots = self::shotsFrom($state['grid'], $state['combatants'], $from, $range);
            if (!isset($shots[(string) $target['cid']])) {
                continue;
            }
            $best = [
                'dest'        => [$x, $y],
                'cost'        => (int) $reach[$key]['cost'],
                'shot'        => $shots[(string) $target['cid']],
                'provokes'    => $this->provocations($state, $actor, $reach, $key, $terrain),
                // Cover works both ways along the same line, so a cell that
                // gives a clean shot gives a clean shot back. Weighed as the
                // cover the *target* would be shooting through.
                'dest_cover'  => BattleGrid::coverBetween($terrain, $state['combatants'], $target, $from),
                'dest_threat' => count(BattleGrid::threatenersOf(
                    $terrain,
                    $this->reactors($state, (string) $actor['cid']),
                    $x,
                    $y
                )),
            ];
        }
        return $best;
    }

    /**
     * Somewhere better to stand when there is nothing to hit from anywhere.
     *
     * Two reasons to walk: getting out of trouble when badly hurt and able to
     * shoot from further off, or closing on whoever is worth reaching. Both are
     * scored through scoreOption() so the tuning stays in one place.
     */
    private function bestReposition(
        array $state,
        array $actor,
        array $enemies,
        array $reach,
        array $threat,
        array $terrain
    ): ?array {
        if (!$enemies) {
            return null;
        }
        $here = BattleGrid::keyOf((int) $actor['x'], (int) $actor['y']);
        $watchers = $this->reactors($state, (string) $actor['cid']);

        // Whoever is most worth walking toward: the softest thing on the field.
        $prize = null;
        $prizeScore = -1.0;
        foreach ($enemies as $e) {
            $s = (empty($e['is_caster']) ? 0.0 : self::AI_CASTER)
                + (empty($e['is_healer']) ? 0.0 : self::AI_HEALER)
                + self::AI_WOUNDED * (1.0 - max(0, (int) $e['hp']) / max(1, (int) $e['max_hp']));
            if ($s > $prizeScore) {
                $prizeScore = $s;
                $prize = $e;
            }
        }
        $px = (int) $prize['x'];
        $py = (int) $prize['y'];
        $wasFt = BattleGrid::feet((int) $actor['x'], (int) $actor['y'], $px, $py);

        $best = null;
        $bestScore = 0.0;
        foreach ($reach as $key => $entry) {
            if ($key === $here || !$entry['end']) {
                continue;
            }
            [$x, $y] = BattleGrid::parseKey($key);
            $destThreat = count(BattleGrid::threatenersOf($terrain, $watchers, $x, $y));
            $standing = $actor;
            $standing['x'] = $x;
            $standing['y'] = $y;
            $option = [
                'kind'         => 'reposition',
                'dest'         => [$x, $y],
                'provokes'     => $this->provocations($state, $actor, $reach, $key, $terrain),
                'dest_cover'   => BattleGrid::coverBetween($terrain, $state['combatants'], $prize, $standing),
                'dest_threat'  => $destThreat,
                'closes_ft'    => $wasFt - BattleGrid::feet($x, $y, $px, $py),
                'engages_soft' => BattleGrid::feet($x, $y, $px, $py) <= (int) ($actor['reach_ft'] ?? 5),
            ];
            $score = self::scoreOption($actor, $option, $state['combatants']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $option;
            }
        }
        return $best;
    }

    /**
     * How many opportunity attacks a walk to this cell would earn.
     *
     * Counted along the path the server would actually take, so the number the
     * AI weighs is the number it would pay. A disengaging monster pays none —
     * nothing sets that flag on a monster today, but reading it here means the
     * day something does, the AI already understands it.
     */
    private function provocations(array $state, array $actor, array $reach, string $destKey, array $terrain): int
    {
        if (!empty($actor['disengaging'])) {
            return 0;
        }
        $watchers = $this->reactors($state, (string) $actor['cid']);
        if (!$watchers) {
            return 0;
        }
        $path = BattleGrid::pathTo($reach, $destKey);
        $spent = [];
        $n = 0;
        for ($i = 1; $i < count($path); $i++) {
            [$fx, $fy] = BattleGrid::parseKey($path[$i - 1]);
            [$tx, $ty] = BattleGrid::parseKey($path[$i]);
            $leaving = BattleGrid::threatenersOf($terrain, $watchers, $fx, $fy);
            $entering = BattleGrid::threatenersOf($terrain, $watchers, $tx, $ty);
            foreach (array_diff($leaving, $entering) as $cid) {
                // One reaction each, however many times you cross their reach.
                if (!isset($spent[$cid])) {
                    $spent[$cid] = true;
                    $n++;
                }
            }
        }
        return $n;
    }

    /** @param array{0:int,1:int} $dest */
    private function walkTo(array $state, string $cid, array $dest, array $reach, array $terrain): array
    {
        $path = BattleGrid::pathTo($reach, BattleGrid::keyOf($dest[0], $dest[1]));
        return $path ? $this->stepMove($state, $cid, $path, $terrain) : $state;
    }

    /** Nothing to hit: brace rather than stand there doing nothing. */
    private function braceOrEnd(array $state, array $actor): array
    {
        if (!empty($actor['action_used'])) {
            return $state;
        }
        $state = $this->updateCombatant($state, $actor['cid'], ['action_used' => true, 'dodging' => true]);
        $state['log'][] = "{$actor['name']} can reach nobody and takes cover.";
        return $state;
    }

    // =======================================================================
    // Ending a fight
    // =======================================================================

    /**
     * Roll one loot spec — [{"item":"key","chance":50,"qty":2},{"gold":"2d6"}]
     * — into what actually dropped. Pure and static, so the arithmetic tests
     * without a database; resolving keys to rows is finalizeCombat's job.
     *
     * @return array{items: array<string,int>, gold: int} item key => quantity
     */
    public static function rollLoot(array $spec): array
    {
        $out = ['items' => [], 'gold' => 0];
        foreach ($spec as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $chance = max(1, min(100, (int) ($entry['chance'] ?? 100)));
            if ($chance < 100 && random_int(1, 100) > $chance) {
                continue;
            }
            if (isset($entry['gold'])) {
                $gold = $entry['gold'];
                $out['gold'] += is_numeric($gold)
                    ? (int) $gold
                    : (int) (Dice::roll((string) $gold)['total'] ?? 0);
            } elseif (isset($entry['item']) && is_string($entry['item'])) {
                $qty = max(1, (int) ($entry['qty'] ?? 1));
                $out['items'][$entry['item']] = ($out['items'][$entry['item']] ?? 0) + $qty;
            }
        }
        return $out;
    }

    private function checkEnd(array $state, int $characterId): array
    {
        if (($state['status'] ?? '') !== 'active') {
            return $state;
        }

        $partyUp = self::standing($state['combatants'], 'party') !== [];
        $foesUp = self::standing($state['combatants'], 'foe') !== [];

        $xp = 0;
        $loot = ['items' => [], 'gold' => 0];
        foreach ($state['combatants'] as $c) {
            if (($c['side'] ?? '') === 'foe' && empty($c['alive'])) {
                $xp += (int) ($c['xp'] ?? 0);
                // The dead are already being counted; their pockets are
                // counted in the same pass.
                $dropped = self::rollLoot((array) ($c['loot'] ?? []));
                $loot['gold'] += $dropped['gold'];
                foreach ($dropped['items'] as $key => $qty) {
                    $loot['items'][$key] = ($loot['items'][$key] ?? 0) + $qty;
                }
            }
        }

        if (!$foesUp && $partyUp) {
            $state['status'] = 'victory';
            $state['xp_award'] = $xp;
            $state['gold_award'] = max(5, (int) floor($xp / 5)) + $loot['gold'];
            // Names resolved here so the outcome bar can print them without a
            // lookup; ids are finalizeCombat's business.
            $state['loot_award'] = $this->namedLoot($loot['items']);
            $state['log'][] = "Victory! Gained {$state['xp_award']} XP and {$state['gold_award']} gp.";
            foreach ($state['loot_award'] as $drop) {
                $state['log'][] = 'The fallen yield ' . $drop['name']
                    . ($drop['qty'] > 1 ? " ×{$drop['qty']}" : '') . '.';
            }
        } elseif (!$partyUp) {
            $state['status'] = 'defeat';
            $state['log'][] = 'Your party has fallen...';
        }
        return $state;
    }

    /**
     * Loot keys resolved to display rows, dropping any key the database no
     * longer knows — the loader refuses those at import, so a miss here is a
     * content reload race, not a player-facing error.
     *
     * @param  array<string,int> $items item key => qty
     * @return list<array{item_key:string, name:string, qty:int}>
     */
    private function namedLoot(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $out = [];
        $stmt = $this->db->prepare('SELECT name FROM items WHERE item_key = ?');
        foreach ($items as $key => $qty) {
            $stmt->execute([$key]);
            $name = $stmt->fetchColumn();
            if ($name !== false) {
                $out[] = ['item_key' => (string) $key, 'name' => (string) $name, 'qty' => (int) $qty];
            }
        }
        return $out;
    }

    private function finalizeCombat(int $sessionId, int $characterId, array $state): void
    {
        $status = (string) ($state['status'] ?? 'ended');

        $this->saveState($sessionId, $state);
        $this->db->prepare('UPDATE combat_sessions SET is_active = 0, state_json = ? WHERE id = ?')
            ->execute([json_encode($state), $sessionId]);

        foreach ($state['combatants'] as $c) {
            if (($c['side'] ?? '') !== 'party') {
                continue;
            }
            $hp = max(0, (int) $c['hp']);
            // Somebody who bled out is carried off the field rather than left
            // on it. DEATH_IS_PERMANENT explains why that is the policy and
            // what real permadeath would need first; the three rounds still
            // mattered, because nothing could bring them back during the fight.
            if (empty($c['alive']) && !self::DEATH_IS_PERMANENT) {
                $hp = max($hp, 1);
            }
            $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
                ->execute([$hp, (int) $c['character_id']]);
        }

        // How the fight ended is a fact about the playthrough, so it goes in
        // world_flags where dialogue and quests can read it.
        $partyId = WorldState::partyIdFor($this->db, $characterId);
        if ($partyId !== null) {
            $world = new WorldState($this->db);
            $encId = (int) ($state['encounter_id'] ?? 0);
            if ($encId) {
                $world->set($partyId, 'encounter_' . $encId . '_outcome', $status);
            }
            if ($status === 'victory' && !empty($state['victory_flag'])) {
                $world->set($partyId, (string) $state['victory_flag']);
            }
            if ($status === 'defeat' && !empty($state['defeat_flag'])) {
                $world->set($partyId, (string) $state['defeat_flag']);
            }
        }

        if ($status === 'victory') {
            $xp = (int) ($state['xp_award'] ?? 0);
            $gold = (int) ($state['gold_award'] ?? 0);
            $party = $this->getPartyCharacters($characterId);
            $n = max(1, count($party));
            $xpEach = (int) floor($xp / $n);
            foreach ($party as $pc) {
                $this->grantXpAndGold((int) $pc['id'], $xpEach, $gold);
            }
            // Only the leader banks the gold, once.
            $this->db->prepare('UPDATE characters SET gold = gold + ? WHERE id = ?')
                ->execute([$gold, $characterId]);

            // The drops go in the same pocket the gold does. Keys resolved
            // against whatever ids the current load produced — "Keys, not
            // ids" — and a key the reload retired simply doesn't land.
            foreach ((array) ($state['loot_award'] ?? []) as $drop) {
                $this->db->prepare(
                    'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
                     SELECT ?, i.id, ?, 0 FROM items i WHERE i.item_key = ?'
                )->execute([$characterId, max(1, (int) $drop['qty']), (string) $drop['item_key']]);
            }

            $encId = (int) ($state['encounter_id'] ?? 0);
            if ($encId) {
                // Won fights stay won — per party, in the party's own record,
                // so content rows are never mutated by play and two
                // playthroughs cannot clear each other's encounters.
                $partyId = WorldState::partyIdFor($this->db, $characterId);
                if ($partyId) {
                    $this->db->prepare(
                        'INSERT IGNORE INTO party_encounters_cleared (party_id, encounter_id) VALUES (?, ?)'
                    )->execute([$partyId, $encId]);
                }
                // A quest ending on the last blow of a fight is the one moment
                // the player is certainly looking at this screen, so what the
                // quest had to say goes into the combat log rather than being
                // dropped on the way out.
                $questLines = $this->completeEncounterQuests($characterId, $encId);
                if ($questLines) {
                    $state['log'] = array_merge($state['log'] ?? [], $questLines);
                    $this->db->prepare('UPDATE combat_sessions SET state_json = ? WHERE id = ?')
                        ->execute([json_encode($state), $sessionId]);
                }
            }
        } elseif ($status === 'defeat') {
            // Soft fail: wake somewhere safe on 1 HP rather than lose the save.
            //
            // Somewhere safe IN THIS GAME. This read `flagon_common_room` — the
            // Rivermark inn — as a literal, so losing a fight in the Old City
            // put the whole party thirty feet under a different module, in a
            // region their own module has no exit to and from which every
            // street in Rivermark is walkable. Every other way a party can move
            // respects the boundary; this one handed them a key to it.
            // `$partyId` is resolved above and may be null — this branch sits
            // outside the block that checks it. No party means no module to ask
            // about, and leaving them where they fell is the safe answer.
            $haven = $partyId === null
                ? null
                : (new CharacterGenerator($this->db))->havenFor($partyId);
            foreach ($state['combatants'] as $c) {
                if (($c['side'] ?? '') === 'party') {
                    $this->db->prepare(
                        'UPDATE characters SET current_hp = 1, current_location_id = COALESCE(?, current_location_id) WHERE id = ?'
                    )->execute([$haven['id'] ?? null, (int) $c['character_id']]);
                }
            }
            // A party beaten underground is out of the dungeon, so the delve is
            // over and the floor they fell on stops existing.
            //
            // AFTER the haven move above, and with `false` so end() does not
            // move anybody itself: the haven is the module's front door and the
            // Mouth is not, so letting end() do the walking would wake a beaten
            // party at the top of the stair instead of at the fire. Dropping
            // the region before they had been moved off it would be worse
            // still — `current_location_id` would point at nothing and the next
            // request would answer "Character is nowhere."
            //
            // Without this the party wakes at the camp with a `dungeon_delves`
            // row insisting they are on level three, and a generated region
            // that nothing will ever collect.
            if ($partyId !== null) {
                (new DelveEngine($this->db))->end($partyId, false);
            }

            // Named rather than assumed, for the same reason: the line used to
            // say "the Golden Flagon" wherever it actually woke you.
            $state['log'][] = $haven
                ? 'You awaken at ' . $haven['name'] . ', battered but alive.'
                : 'You come round where you fell, battered but alive.';
            $this->db->prepare('UPDATE combat_sessions SET state_json = ? WHERE id = ?')
                ->execute([json_encode($state), $sessionId]);
        }
    }

    private function grantXpAndGold(int $characterId, int $xp, int $gold): void
    {
        // XP only here; gold applied once to leader
        $stmt = $this->db->prepare('SELECT experience, level FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $c = $stmt->fetch();
        $newXp = (int) $c['experience'] + $xp;
        $level = (int) $c['level'];
        // The ladder lives in Rules because a dialogue `{"xp": 100}` awards
        // against it too, and two copies of it is how a party levels for
        // killing the bandits but not for talking them down.
        $newLevel = max($level, Rules::levelForXp($newXp));
        $this->db->prepare('UPDATE characters SET experience = ?, level = ? WHERE id = ?')
            ->execute([$newXp, $newLevel, $characterId]);

        if ($newLevel > $level) {
            // Proficiency now, hit points later. A fight is no place to stop
            // and wait on a die the player has not thrown, so the level-up is
            // recorded as owed and claimed afterwards through LevelUpService —
            // the same treatment Effects::grantXp gives a level won by talking.
            $this->db->prepare('UPDATE characters SET proficiency_bonus = ? WHERE id = ?')
                ->execute([Rules::proficiencyBonus($newLevel), $characterId]);

            (new LevelUpService($this->db))->record($characterId, $level, $newLevel);
        }
    }

    /**
     * Advance any quest this fight was the answer to.
     *
     * The quest itself is QuestService's to end: it knows the stage graph and
     * it pays the rewards once to the party. Combat's job here is only to say
     * which encounter was beaten.
     *
     * @return string[] lines for the combat log
     */
    private function completeEncounterQuests(int $characterId, int $encounterId): array
    {
        $partyId = WorldState::partyIdFor($this->db, $characterId);
        if ($partyId === null) {
            return [];
        }
        $quests = new QuestService($this->db);
        $lines = [];

        // A fight that is also an answer. Authored as "quest_key:stage_key" on
        // the encounter, and applied here so winning reaches the same ending a
        // reply would have — the alternative is a quest that only the talkers
        // can finish, which is what stranded Teeth in the Hills on "count the
        // wagons" after the party had already taken the road back.
        $stmt = $this->db->prepare('SELECT victory_quest_stage FROM encounters WHERE id = ?');
        $stmt->execute([$encounterId]);
        $spec = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($spec !== '' && str_contains($spec, ':')) {
            [$questKey, $stageKey] = explode(':', $spec, 2);
            $questKey = trim($questKey);
            try {
                // Only a job they are actually on. advance() would otherwise
                // start one they were never given — winning a roadside fight is
                // not being briefed, and it would hand out a quest past its own
                // level gate. A quest already finished stays finished; advance()
                // answers that with a note rather than re-opening it.
                if ($quests->isActiveFor($partyId, $questKey)) {
                    $advanced = $quests->advance($partyId, $questKey, trim($stageKey));
                    $lines = array_merge($lines, $advanced['messages'] ?? []);
                }
            } catch (Throwable $e) {
                // Content naming a stage that does not exist, or a quest this
                // party never took. Not worth losing the victory over.
                $lines[] = '(' . $e->getMessage() . ')';
            }
        }
        return $lines;
    }

    private function saveState(int $sessionId, array $state): void
    {
        $this->db->prepare(
            'UPDATE combat_sessions SET state_json = ?, current_turn = ? WHERE id = ?'
        )->execute([json_encode($state), (int) ($state['turn_index'] ?? 0), $sessionId]);
    }

    /**
     * The party, as CharacterGenerator resolves it.
     *
     * This used to be its own `SELECT c.*`, which meant a third copy of the
     * "which art does this character wear" rule — and the wrong one: a raw
     * `characters.sprite_key` is the stale value recruitment copied off the
     * companion template, so the combat board showed a companion's old face
     * while their conversation showed the new one. Delegating leaves one
     * resolution, and it also picks up the is_active filter, so a retired
     * character cannot be dragged into a fight.
     */
    private function getPartyCharacters(int $characterId): array
    {
        $gen = new CharacterGenerator($this->db);
        $partyId = WorldState::partyIdFor($this->db, $characterId);
        if (!$partyId) {
            try {
                return [$gen->getCharacter($characterId)];
            } catch (Throwable $e) {
                return [];
            }
        }
        return $gen->getPartyCharacters($partyId);
    }

    /**
     * The second weapon in the other hand, if there is one.
     *
     * The inventory has no concept of a hand — `is_equipped` is a flag and
     * getEquippedWeapon() takes the first row it finds — so the off-hand is
     * simply the NEXT equipped weapon after that one. It has to be light, which
     * is the SRD's requirement and also what stops a character with a longsword
     * and a spare greataxe swinging both.
     */
    /**
     * Whether this character has a bonus-action swing available at all.
     *
     * Asked once when the fight is built, so the action bar can offer the
     * button to a two-weapon fighter and a Monk and to nobody else. The same
     * two conditions doBonusAttack() enforces, which is why they are read from
     * here rather than restated on the client.
     *
     * @param array $row a `characters` row, not a combatant
     */
    private function canBonusAttack(array $row): bool
    {
        $characterId = (int) ($row['id'] ?? 0);
        if ($characterId <= 0) {
            return false;
        }
        $main = $this->getEquippedWeapon($characterId);
        if (Rules::hasFeature((string) ($row['class'] ?? ''), (int) ($row['level'] ?? 1), 'martial_arts')
            && Rules::isMonkWeapon($main)) {
            return true;
        }
        return $this->getOffhandWeapon($characterId) !== null
            && !empty(Rules::weaponProperties($main ?? [])['light']);
    }

    private function getOffhandWeapon(int $characterId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.* FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ? AND ci.is_equipped = 1 AND i.item_type = \'weapon\'
             ORDER BY ci.id
             LIMIT 2'
        );
        $stmt->execute([$characterId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
            return null;
        }
        return empty(Rules::weaponProperties($rows[1])['light']) ? null : $rows[1];
    }

    private function getEquippedWeapon(int $characterId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.* FROM character_inventory ci
             INNER JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ? AND ci.is_equipped = 1 AND i.item_type = \'weapon\'
             LIMIT 1'
        );
        $stmt->execute([$characterId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Which party members can cast, and which can heal.
     *
     * Read once at the start of the fight and stamped onto the combatant,
     * because it is what the monster AI uses to pick out the dangerous ones and
     * the AI must be scorable without a database behind it.
     *
     * @param  int[] $characterIds
     * @return array<int, array{caster:bool, healer:bool}>
     */
    private function partySpellProfile(array $characterIds): array
    {
        $out = [];
        foreach ($characterIds as $id) {
            $out[$id] = ['caster' => false, 'healer' => false];
        }
        if (!$characterIds) {
            return $out;
        }

        $in = implode(',', array_map('intval', $characterIds));
        $rows = $this->db->query(
            "SELECT cs.character_id, s.resolution, s.damage_type
             FROM character_spells cs
             INNER JOIN spells s ON s.id = cs.spell_id
             WHERE cs.character_id IN ({$in})"
        )->fetchAll();

        foreach ($rows as $row) {
            $id = (int) $row['character_id'];
            $out[$id]['caster'] = true;
            if (($row['resolution'] ?? '') === 'heal' || ($row['damage_type'] ?? '') === 'healing') {
                $out[$id]['healer'] = true;
            }
        }
        return $out;
    }
}
