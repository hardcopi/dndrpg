<?php
/**
 * SRD conditions, and what they do to a roll.
 *
 * Deafened and exhaustion are left out: the first governs hearing checks this
 * game does not make, and the second is a six-step track with no source of
 * exhaustion to drive it. Everything else in the SRD is here.
 *
 * This used to say it carried "only the conditions that still mean something
 * in a fight without a movement grid", which was true of the rank board and
 * stopped being true when the battlefield arrived. Two fields were left behind
 * by that: `speed_zero`, declared on every entry and read by nothing, so five
 * conditions that pin a creature in place let it walk away at full speed; and
 * frightened's "cannot willingly move closer", which had no board to be closer
 * on. Both are enforced now — see rooted() and fearedBy() below, and
 * CombatEngine::movementBudget(), which is where a condition meets a distance.
 *
 * A condition lives on a combatant as an entry in $combatant['conditions']:
 *   ['key' => 'frightened', 'rounds' => 3, 'source_cid' => 'mon_1',
 *    'save_ability' => 'wis', 'save_dc' => 13]
 * `rounds` may be absent or null, which means it lasts until something removes
 * it rather than until a timer runs out.
 */

declare(strict_types=1);

class Conditions
{
    /**
     * Every condition and the flags the roll builders read.
     *
     * Each entry carries the full set of keys even where they are false, so a
     * caller can read a flag off any condition without first checking that this
     * particular one bothered to declare it.
     *
     * `melee_against_advantage` and `ranged_against_disadvantage` are separate
     * from the range-blind `attacks_against_*` pair because prone is the one
     * condition that helps an attacker in reach and hinders one at distance.
     */
    public const CATALOG = [
        'blinded' => [
            'label'                        => 'Blinded',
            'description'                  => 'Cannot see, and automatically fails any check that requires sight. Attacks against it have advantage; its own attacks have disadvantage.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => true,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'charmed' => [
            'label'                        => 'Charmed',
            'description'                  => 'Cannot attack the charmer or target them with harmful effects, and the charmer has advantage on social checks against it.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'frightened' => [
            'label'                        => 'Frightened',
            'description'                  => 'Has disadvantage on ability checks and attack rolls while the source of its fear is in sight, and cannot willingly move closer to it.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => true,
            'checks_disadvantage'          => true,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'grappled' => [
            'label'                        => 'Grappled',
            'description'                  => 'Its speed is zero and it gains no benefit from any bonus to speed.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => true,
        ],
        'incapacitated' => [
            'label'                        => 'Incapacitated',
            'description'                  => 'Cannot take actions or reactions.',
            'blocks_actions'               => true,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'invisible' => [
            'label'                        => 'Invisible',
            'description'                  => 'Impossible to see without magic or a special sense. Attacks against it have disadvantage; its own attacks have advantage.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => true,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => true,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'paralyzed' => [
            'label'                        => 'Paralyzed',
            'description'                  => 'Incapacitated and unable to move or speak. It automatically fails Strength and Dexterity saves, attacks against it have advantage, and any attack that hits it from within reach is a critical hit.',
            'blocks_actions'               => true,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => true,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => ['str', 'dex'],
            'saves_disadvantage'           => [],
            'speed_zero'                   => true,
        ],
        /*
         * The last SRD condition to arrive, and the only one that was missing
         * without a reason beside it — deafened and exhaustion are argued for
         * in the header, petrified was simply not there.
         *
         * `resists_all_damage` is a field of its own because petrified is the
         * only condition in the SRD that changes what a blow is worth rather
         * than whether it lands, and folding it into the defence lists on the
         * combatant would mean adding eleven damage types on the way in and
         * unpicking them on the way out. Rules::damageAfterDefenses() reads it.
         */
        'petrified' => [
            'label'                        => 'Petrified',
            'description'                  => 'Turned to stone, incapacitated and unaware of its surroundings. Attacks against it have advantage, it automatically fails Strength and Dexterity saves, and it resists all damage.',
            'blocks_actions'               => true,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => ['str', 'dex'],
            'saves_disadvantage'           => [],
            'speed_zero'                   => true,
            'resists_all_damage'           => true,
        ],
        'poisoned' => [
            'label'                        => 'Poisoned',
            'description'                  => 'Has disadvantage on attack rolls and ability checks.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => true,
            'checks_disadvantage'          => true,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'prone' => [
            'label'                        => 'Prone',
            'description'                  => 'Its own attacks have disadvantage. Attacks against it have advantage from within reach and disadvantage from further off.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => false,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => true,
            'ranged_against_disadvantage'  => true,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => true,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => [],
            'speed_zero'                   => false,
        ],
        'restrained' => [
            'label'                        => 'Restrained',
            'description'                  => 'Its speed is zero. Attacks against it have advantage, its own attacks have disadvantage, and it has disadvantage on Dexterity saves.',
            'blocks_actions'               => false,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => true,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => [],
            'saves_disadvantage'           => ['dex'],
            'speed_zero'                   => true,
        ],
        'stunned' => [
            'label'                        => 'Stunned',
            'description'                  => 'Incapacitated, unable to move, and able to speak only falteringly. It automatically fails Strength and Dexterity saves, and attacks against it have advantage.',
            'blocks_actions'               => true,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => false,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => ['str', 'dex'],
            'saves_disadvantage'           => [],
            'speed_zero'                   => true,
        ],
        'unconscious' => [
            'label'                        => 'Unconscious',
            'description'                  => 'Incapacitated, unaware of its surroundings, and prone. It automatically fails Strength and Dexterity saves, attacks against it have advantage, and any attack that hits it from within reach is a critical hit.',
            'blocks_actions'               => true,
            'attacks_against_advantage'    => true,
            'attacks_against_disadvantage' => false,
            'melee_against_advantage'      => false,
            'ranged_against_disadvantage'  => false,
            'melee_auto_crit'              => true,
            'attacks_by_advantage'         => false,
            'attacks_by_disadvantage'      => false,
            'checks_disadvantage'          => false,
            'auto_fail_saves'              => ['str', 'dex'],
            'saves_disadvantage'           => [],
            'speed_zero'                   => true,
        ],
    ];

    private const LONG_ABILITIES = [
        'strength'     => 'str',
        'dexterity'    => 'dex',
        'constitution' => 'con',
        'intelligence' => 'int',
        'wisdom'       => 'wis',
        'charisma'     => 'cha',
    ];

    /**
     * The catalogue as the client needs it: what each condition is called and
     * what it does, and nothing else.
     *
     * The mechanical flags stay here. The browser has no use for
     * `melee_auto_crit` — it does not resolve attacks, the server does — and
     * shipping them would invite somebody to start reading them, which is how a
     * rules table ends up with two copies that disagree. The prose is the part
     * a player is owed.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function forClient(): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $c) {
            $out[$key] = [
                'label'       => (string) ($c['label'] ?? ucfirst($key)),
                'description' => (string) ($c['description'] ?? ''),
            ];
        }
        return $out;
    }

    public static function has(array $combatant, string $key): bool
    {
        $key = self::key($key);
        foreach (self::listOf($combatant) as $condition) {
            if ($condition['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply a condition, or refresh one already present.
     *
     * Re-applying keeps whichever duration lasts longer, so a second casting of
     * a fear effect cannot cut short the first one that landed — which is what
     * a plain overwrite would do, and it would look to the player as though the
     * new spell had helped its target.
     *
     * A creature immune to the condition is returned untouched rather than
     * given a condition that every later read has to remember to ignore.
     */
    public static function add(array $combatant, array $condition): array
    {
        $key = self::key((string) ($condition['key'] ?? ''));
        if (!isset(self::CATALOG[$key])) {
            throw new InvalidArgumentException("Unknown condition: {$key}");
        }
        if (self::isImmune($combatant, $key)) {
            return $combatant;
        }

        $condition['key'] = $key;
        $list = self::listOf($combatant);

        foreach ($list as $i => $existing) {
            if ($existing['key'] !== $key) {
                continue;
            }
            $old = $existing['rounds'] ?? null;
            $new = $condition['rounds'] ?? null;
            // Either one being open-ended makes the refreshed one open-ended.
            $condition['rounds'] = ($old === null || $new === null)
                ? null
                : max((int) $old, (int) $new);
            $list[$i] = $condition;
            $combatant['conditions'] = array_values($list);
            return $combatant;
        }

        $list[] = $condition;
        $combatant['conditions'] = array_values($list);
        return $combatant;
    }

    public static function remove(array $combatant, string $key): array
    {
        $key = self::key($key);
        $kept = [];
        foreach (self::listOf($combatant) as $condition) {
            if ($condition['key'] !== $key) {
                $kept[] = $condition;
            }
        }
        $combatant['conditions'] = $kept;
        return $combatant;
    }

    /**
     * Advance every timed condition by one round at the start of a turn.
     *
     * A condition that runs out this tick does not also demand its repeat save:
     * asking a creature to shake off something that has already lapsed produces
     * a log line about a save that could not have mattered.
     *
     * @return array{combatant:array, expired:string[], saves_due:array<int, array>}
     */
    public static function tickStart(array $combatant): array
    {
        $kept = [];
        $expired = [];
        $savesDue = [];

        foreach (self::listOf($combatant) as $condition) {
            if (($condition['rounds'] ?? null) !== null) {
                $condition['rounds'] = (int) $condition['rounds'] - 1;
                if ($condition['rounds'] <= 0) {
                    $expired[] = $condition['key'];
                    continue;
                }
            }

            $kept[] = $condition;

            if (!empty($condition['save_ability']) && !empty($condition['save_dc'])) {
                $savesDue[] = [
                    'key'        => $condition['key'],
                    'ability'    => self::abilityKey((string) $condition['save_ability']),
                    'dc'         => (int) $condition['save_dc'],
                    'source_cid' => $condition['source_cid'] ?? null,
                ];
            }
        }

        $combatant['conditions'] = $kept;

        return [
            'combatant' => $combatant,
            'expired'   => $expired,
            'saves_due' => $savesDue,
        ];
    }

    public static function canAct(array $combatant): bool
    {
        foreach (self::listOf($combatant) as $condition) {
            if (!empty(self::CATALOG[$condition['key']]['blocks_actions'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether anything holding this creature sets its speed to zero.
     *
     * `speed_zero` was declared on all fifteen entries in this catalogue and
     * read by nothing whatsoever. The header above explains why — the file was
     * written to carry "only the conditions that still mean something in a
     * fight without a movement grid", and a field about movement had nothing to
     * act on. There is a grid now, and until this existed a grappled, bound or
     * paralysed creature strolled away at full speed.
     */
    /**
     * Whether stone is holding this creature — SRD petrified.
     *
     * The only condition that halves a blow rather than colouring the die that
     * threw it, which is why it is asked as its own question and answered in
     * Rules::damageAfterDefenses() rather than in attackContext().
     */
    public static function resistsAllDamage(array $combatant): bool
    {
        foreach (self::listOf($combatant) as $condition) {
            if (!empty(self::CATALOG[$condition['key']]['resists_all_damage'])) {
                return true;
            }
        }
        return false;
    }

    public static function rooted(array $combatant): bool
    {
        foreach (self::listOf($combatant) as $condition) {
            if (!empty(self::CATALOG[$condition['key']]['speed_zero'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whoever this creature is frightened of, by cid.
     *
     * Frightened is the other condition the gridless catalogue could only half
     * express: "has disadvantage on ability checks and attack rolls while the
     * source of its fear is in sight" was implementable and is implemented, and
     * "cannot willingly move closer to it" needed somewhere to move.
     *
     * A condition with no `source_cid` constrains nobody. That is deliberate
     * rather than defensive — a fear with no source in the fight (a failed save
     * against an authored effect, say) still colours the dice, and there is no
     * square on the board it could sensibly forbid.
     *
     * @return string[]
     */
    public static function fearedBy(array $combatant): array
    {
        $out = [];
        foreach (self::listOf($combatant) as $condition) {
            if ($condition['key'] !== 'frightened') {
                continue;
            }
            $source = (string) ($condition['source_cid'] ?? '');
            if ($source !== '') {
                $out[] = $source;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * How the conditions on both sides colour one attack roll.
     *
     * Advantage and disadvantage are resolved here rather than handed to the
     * caller as two raw flags, because the SRD cancels them against each other
     * and a caller that checked advantage first would silently give a blinded
     * attacker the benefit of a restrained target. Both sources still appear in
     * `reasons` so the log can say what happened.
     *
     * Melee and ranged differ only for prone, so the attacker declares which it
     * is with `attack_kind`; anything other than 'ranged' is treated as melee,
     * which is the only kind the front rank ever makes.
     *
     * `forbidden` is not one of the SRD's advantage rules — it is how charmed
     * refuses an attack on the charmer, which has nowhere else to live in a
     * return value about dice.
     *
     * @return array{advantage:bool, disadvantage:bool, auto_crit:bool, auto_hit:bool, forbidden:bool, reasons:string[]}
     */
    public static function attackContext(array $attacker, array $target): array
    {
        $melee = (string) ($attacker['attack_kind'] ?? 'melee') !== 'ranged';

        $advantage = [];
        $disadvantage = [];
        $notes = [];
        $autoCrit = false;
        $forbidden = false;

        // Blind Fighting sees by feel out to ten feet, which in a fight with two
        // ranks and no floor is exactly "anything I could hit in melee". So the
        // feat does not remove the Blinded condition — the attacker still fails
        // sight checks and still cannot shoot — it removes the one thing being
        // blinded costs them at arm's length.
        $ignoreBlind = $melee && Feats::flag($attacker, 'ignore_blinded_melee');

        foreach (self::listOf($attacker) as $condition) {
            $meta = self::CATALOG[$condition['key']] ?? null;
            if ($meta === null) {
                continue;
            }
            if ($condition['key'] === 'charmed'
                && isset($condition['source_cid'], $target['cid'])
                && $condition['source_cid'] === $target['cid']) {
                $forbidden = true;
                $notes[] = 'attacker is charmed by the target';
            }
            if ($meta['attacks_by_advantage']) {
                $advantage[] = 'attacker is ' . $condition['key'];
            }
            if ($meta['attacks_by_disadvantage']) {
                if ($ignoreBlind && $condition['key'] === 'blinded') {
                    $notes[] = 'attacker is blinded but fights by feel';
                    continue;
                }
                $disadvantage[] = 'attacker is ' . $condition['key'];
            }
        }

        // Grappler and its kin: a feat that says "advantage against a creature
        // that is X" names the condition, and this reads the name rather than
        // knowing which feats exist.
        foreach (Feats::strings($attacker, 'attack_advantage_vs') as $conditionKey) {
            if (self::has($target, $conditionKey)) {
                $advantage[] = 'target is ' . $conditionKey . ', and the attacker has the feat for it';
            }
        }

        foreach (self::listOf($target) as $condition) {
            $meta = self::CATALOG[$condition['key']] ?? null;
            if ($meta === null) {
                continue;
            }
            if ($meta['attacks_against_advantage']) {
                $advantage[] = 'target is ' . $condition['key'];
            }
            if ($meta['attacks_against_disadvantage']) {
                $disadvantage[] = 'target is ' . $condition['key'];
            }
            if ($melee && $meta['melee_against_advantage']) {
                $advantage[] = 'target is ' . $condition['key'] . ' and within reach';
            }
            if (!$melee && $meta['ranged_against_disadvantage']) {
                $disadvantage[] = 'target is ' . $condition['key'] . ' and at range';
            }
            if ($melee && $meta['melee_auto_crit']) {
                $autoCrit = true;
                $notes[] = 'a hit on a ' . $condition['key'] . ' target within reach is a critical';
            }
        }

        $hasAdvantage = $advantage !== [];
        $hasDisadvantage = $disadvantage !== [];
        $reasons = array_merge($advantage, $disadvantage, $notes);

        if ($hasAdvantage && $hasDisadvantage) {
            $hasAdvantage = false;
            $hasDisadvantage = false;
            $reasons[] = 'advantage and disadvantage cancel';
        }

        return [
            'advantage'    => $hasAdvantage,
            'disadvantage' => $hasDisadvantage,
            // No SRD condition makes an attack land without a roll; the key is
            // here so callers can treat auto-hit effects uniformly later.
            'auto_hit'     => false,
            'auto_crit'    => $autoCrit,
            'forbidden'    => $forbidden,
            'reasons'      => $reasons,
        ];
    }

    /**
     * How the conditions on a creature colour one saving throw.
     *
     * `auto_fail` is checked before anything else by the caller: a paralyzed
     * creature does not roll a Dexterity save at disadvantage, it does not roll
     * at all.
     *
     * @return array{advantage:bool, disadvantage:bool, auto_fail:bool, reasons:string[]}
     */
    public static function saveContext(array $actor, string $ability): array
    {
        $ability = self::abilityKey($ability);

        $advantage = [];
        $disadvantage = [];
        $notes = [];
        $autoFail = false;

        foreach (self::listOf($actor) as $condition) {
            $meta = self::CATALOG[$condition['key']] ?? null;
            if ($meta === null) {
                continue;
            }
            if (in_array($ability, $meta['auto_fail_saves'], true)) {
                $autoFail = true;
                $notes[] = $condition['key'] . ' fails this save automatically';
            }
            if (in_array($ability, $meta['saves_disadvantage'], true)) {
                $disadvantage[] = 'saver is ' . $condition['key'];
            }
        }

        $hasAdvantage = $advantage !== [];
        $hasDisadvantage = $disadvantage !== [];
        $reasons = array_merge($advantage, $disadvantage, $notes);

        if ($hasAdvantage && $hasDisadvantage) {
            $hasAdvantage = false;
            $hasDisadvantage = false;
            $reasons[] = 'advantage and disadvantage cancel';
        }

        return [
            'advantage'    => $hasAdvantage,
            'disadvantage' => $hasDisadvantage,
            'auto_fail'    => $autoFail,
            'reasons'      => $reasons,
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The conditions on a combatant, in the one shape the rest of the class
     * expects.
     *
     * Entries that name a condition the catalog does not carry are dropped
     * here, so a stale save holding 'deafened' cannot make every later lookup
     * of $meta['blocks_actions'] a warning.
     *
     * @return array<int, array>
     */
    private static function listOf(array $combatant): array
    {
        $raw = $combatant['conditions'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            // A bare string is accepted so content can author ['prone'].
            if (is_string($entry)) {
                $entry = ['key' => $entry];
            }
            if (!is_array($entry) || !isset($entry['key'])) {
                continue;
            }
            $key = self::key((string) $entry['key']);
            if (!isset(self::CATALOG[$key])) {
                continue;
            }
            $entry['key'] = $key;
            $out[] = $entry;
        }
        return $out;
    }

    private static function isImmune(array $combatant, string $key): bool
    {
        $list = $combatant['condition_immunities'] ?? null;
        if (!is_array($list)) {
            $list = json_decode((string) ($combatant['condition_immunities_json'] ?? ''), true);
        }
        if (!is_array($list)) {
            return false;
        }

        foreach ($list as $name) {
            if (is_string($name) && self::key($name) === $key) {
                return true;
            }
        }
        return false;
    }

    private static function key(string $key): string
    {
        return strtolower(trim($key));
    }

    private static function abilityKey(string $ability): string
    {
        $ability = strtolower(trim($ability));
        return self::LONG_ABILITIES[$ability] ?? $ability;
    }
}
