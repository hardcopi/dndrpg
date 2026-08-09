<?php
/**
 * The d20 check, in two halves: describe it, then roll it.
 *
 * A check the player cannot see before it happens is just a number appearing,
 * and a check the client rolls is a number the player can retry until they like
 * it. So request() answers "what is being asked of me, and what may I spend on
 * it" and returns nothing secret, resolve() throws the die on the server, and
 * the only thing that travels between them is an opaque id naming a pending
 * check the session already holds.
 *
 * That split is also why the offered boosts are stored rather than re-derived on
 * resolve: the party's state can change between the two calls — a bard could
 * spend their last inspiration elsewhere — and a check that quietly withdrew an
 * option the player was already looking at would read as the game cheating.
 *
 * Everything that decides an outcome is static and takes what it needs as an
 * argument, so the arithmetic can be exercised without a database, a session or
 * a party.
 */

declare(strict_types=1);

class CheckService
{
    /**
     * How long an offered check stays offered.
     *
     * Long enough that reading the boosts and thinking about them is free, short
     * enough that a token left in a closed tab cannot be replayed tomorrow
     * against a party that has since rested, levelled and moved on.
     */
    public const TTL_SECONDS = 600;

    /**
     * Bardic Inspiration is counted per bard, not per party: two bards each
     * carry their own uses, and a single shared counter would let the second
     * one's dice be eaten by the first one's spending. The name below is the
     * prefix; the bard's character id is appended.
     */
    private const FLAG_BARDIC_USED = 'bardic_inspiration_used';

    /**
     * Bless is a spell somebody else has already paid for, so this asks only
     * whether it is currently up. Concentration is tracked by whatever put it
     * there — combat state during a fight, this flag outside one — because a
     * dialogue check has no combat session to read a concentration record from.
     */
    private const FLAG_BLESS = 'bless_active';

    /**
     * Luck points spent, counted per character for the same reason Bardic
     * Inspiration is: the points belong to the character who took the feat, and
     * a shared counter would let one Lucky character drain another's.
     */
    private const FLAG_LUCK_USED = 'luck_points_used';

    /**
     * Bonus-action class features spent, counted per character and per feature.
     *
     * Rage and Second Wind are per-rest in the SRD, but combat state dies with
     * the encounter, so counting them there meant every new fight handed the
     * feature back — a Barbarian had as many rages as there were fights. The
     * count belongs to the party's world flags for the same reason Bardic
     * Inspiration's does: it has to outlive the thing that spends it.
     *
     * CombatEngine::bonusFeatureFlag() builds the key.
     */
    public const FLAG_BONUS_FEATURE_USED = 'bonus_feature_used';

    /** Flag prefixes a long rest wipes. */
    private const PER_REST_FLAGS = [
        self::FLAG_BARDIC_USED,
        self::FLAG_LUCK_USED,
        self::FLAG_BONUS_FEATURE_USED,
    ];

    /** Short ability keys to the `characters` columns that hold them. */
    private const ABILITY_COLUMNS = [
        'str' => 'strength',
        'dex' => 'dexterity',
        'con' => 'constitution',
        'int' => 'intelligence',
        'wis' => 'wisdom',
        'cha' => 'charisma',
    ];

    private PDO $db;
    private WorldState $world;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->world = new WorldState($db);
    }

    // -----------------------------------------------------------------------
    // Phase one
    // -----------------------------------------------------------------------

    /**
     * Describe a check and everything the party may legally spend on it.
     *
     * `$spec` is one of ['skill' => 'persuasion', 'dc' => 14],
     * ['ability' => 'str', 'dc' => 12] or ['save' => 'dex', 'dc' => 13]. It may
     * also carry `advantage` / `disadvantage` (true, or a string giving the
     * reason to show the player) and `conditions`, which is how a combatant
     * mid-fight brings its conditions to a check made from a `characters` row
     * that has no column for them.
     */
    public function request(int $partyId, array $spec, ?int $characterId = null): array
    {
        $ctx = $this->world->context($partyId, $characterId);
        $actor = $ctx['leader'];
        if (!is_array($actor)) {
            throw new InvalidArgumentException('That party has nobody in it to make a check.');
        }
        if (isset($spec['conditions']) && is_array($spec['conditions'])) {
            $actor['conditions'] = $spec['conditions'];
        }

        // The DC is bounded because it is written to a TINYINT column and shown
        // as a target the player is meant to weigh; a DC of 0 or 900 is a
        // content bug worth stopping on rather than rendering.
        $dc = (int) ($spec['dc'] ?? 0);
        if ($dc < 1 || $dc > 30) {
            throw new InvalidArgumentException('A check needs a DC between 1 and 30.');
        }

        $described = self::describe($actor, $spec);
        $mode = self::dieMode($actor, $described['kind'], $spec);
        $boosts = $this->boosts($partyId, $ctx['party'], $actor, $described['kind']);

        $character = [
            'id'         => (int) $actor['id'],
            'name'       => (string) $actor['name'],
            'sprite_key' => $actor['sprite_key'] ?? null,
        ];

        $checkId = self::stash([
            'party_id'  => $partyId,
            'kind'      => $described['kind'],
            'label'     => $described['label'],
            'dc'        => $dc,
            'character' => $character,
            'modifier'  => $described['total'],
            'parts'     => $described['parts'],
            'die_mode'  => $mode['mode'],
            'boosts'    => $boosts,
            // Whatever the caller needs handed back when the die lands. A
            // conversation stores the two nodes the outcome chooses between,
            // so the destination is fixed before the roll rather than computed
            // once the number is known — and so a client cannot nominate where
            // a success takes it.
            'context'   => $spec['context'] ?? null,
        ]);

        return [
            'check_id'    => $checkId,
            'kind'        => $described['kind'],
            'label'       => $described['label'],
            'dc'          => $dc,
            'character'   => $character,
            'base'        => $described['total'],
            'parts'       => $described['parts'],
            'die_mode'    => $mode['mode'],
            'die_reasons' => $mode['reasons'],
            'boosts'      => $boosts,
            // As offered, with nothing spent. Spending is the player's decision
            // and showing them what it buys is the whole point of phase one.
            'chance'      => self::successChance($described['total'], $dc, $mode['mode']),
        ];
    }

    // -----------------------------------------------------------------------
    // Phase two
    // -----------------------------------------------------------------------

    /**
     * Roll a check that was offered, spend what the player chose, log it.
     *
     * The pending check is claimed before anything is rolled, so a client that
     * fires resolve twice gets one result and one error rather than two rolls
     * and a choice of which to believe.
     */
    public function resolve(string $checkId, array $chosenBoostKeys): array
    {
        $pending = self::claim($checkId);
        $result = self::resolvePending($pending, $chosenBoostKeys);

        $partyId = (int) $pending['party_id'];
        $this->spend($partyId, $result['spent']);
        $this->log($partyId, $pending, $result);

        // Carried through from request() rather than recomputed, so the caller
        // resumes exactly the thing it suspended.
        $result['context'] = $pending['context'] ?? null;
        $result['party_id'] = $partyId;

        return $result;
    }

    /**
     * The whole of a resolution that does not touch the world: dice, boosts and
     * the verdict. Given the stored pending check, it decides everything.
     */
    public static function resolvePending(array $pending, array $chosenBoostKeys): array
    {
        $offered = [];
        foreach ($pending['boosts'] ?? [] as $boost) {
            $offered[(string) $boost['key']] = $boost;
        }

        // Choosing the same boost twice is one boost, not two dice — the client
        // is free to be sloppy about a checkbox, the roll is not.
        $chosen = [];
        foreach ($chosenBoostKeys as $key) {
            $key = is_scalar($key) ? (string) $key : '';
            if (!isset($offered[$key])) {
                throw new InvalidArgumentException("That boost was not offered for this check: {$key}");
            }
            $chosen[$key] = $offered[$key];
        }

        $mode = (string) ($pending['die_mode'] ?? 'normal');
        $rerollOnes = false;
        $spent = [];

        foreach ($chosen as $key => $boost) {
            if (!empty($boost['die_mode'])) {
                $mode = self::combineMode($mode, (string) $boost['die_mode']);
                // A boost whose whole contribution is the die mode is spent
                // here, because the loop further down only charges for boosts
                // that added dice or a flat number and would let this one be
                // chosen for free every time. The Lucky feat's luck points are
                // exactly that shape — a point buys a second d20, not a bonus —
                // and an uncharged luck point is an unlimited one.
                $spent[] = self::spentEntry($key, $boost);
            }
            if (!empty($boost['reroll_ones'])) {
                $rerollOnes = true;
            }
        }

        $modifier = (int) ($pending['modifier'] ?? 0);
        $dc = (int) ($pending['dc'] ?? 0);

        $throw = Dice::d20Mode($modifier, $mode);
        $rolls = $throw['rolls'];
        $natural = $throw['natural'];

        // Halfling Lucky only costs something when it fires, so it is only
        // reported as spent then. Offering it and having it sit unused must not
        // grey it out for the next check.
        if ($rerollOnes && $natural === 1) {
            $again = Dice::d20Mode(0, 'normal');
            $rolls[] = $again['natural'];
            $natural = $again['natural'];
            foreach ($chosen as $key => $boost) {
                if (!empty($boost['reroll_ones'])) {
                    $spent[] = self::spentEntry($key, $boost);
                }
            }
        }

        $boostRolls = [];
        $boostTotal = 0;
        foreach ($chosen as $key => $boost) {
            $expr = trim((string) ($boost['bonus_dice'] ?? ''));
            $flat = (int) ($boost['bonus_flat'] ?? 0);
            if ($expr === '' && $flat === 0) {
                continue;
            }

            $value = $flat;
            if ($expr !== '') {
                $value += Dice::roll($expr)['total'];
            }

            $boostRolls[] = [
                'label' => (string) $boost['label'],
                'expr'  => $expr !== '' ? $expr : sprintf('%+d', $flat),
                'value' => $value,
            ];
            $boostTotal += $value;
            // A boost that both changed the mode and added dice has already
            // been charged for above; charging twice would take two luck points
            // for one spend.
            if (empty($boost['die_mode'])) {
                $spent[] = self::spentEntry($key, $boost);
            }
        }

        $total = $natural + $modifier + $boostTotal;

        $parts = $pending['parts'] ?? [];
        foreach ($boostRolls as $roll) {
            $parts[] = ['label' => $roll['label'], 'value' => $roll['value']];
        }

        return [
            'check_id'    => (string) ($pending['check_id'] ?? ''),
            'outcome'     => self::outcomeOf($natural, $total, $dc),
            'natural'     => $natural,
            'rolls'       => $rolls,
            'die_mode'    => $mode,
            'modifier'    => $modifier,
            'boost_rolls' => $boostRolls,
            'total'       => $total,
            'dc'          => $dc,
            'margin'      => $total - $dc,
            'parts'       => $parts,
            'spent'       => $spent,
        ];
    }

    /**
     * The verdict on a rolled check.
     *
     * A natural 20 always reads as a critical and a natural 1 always as a
     * fumble, whatever the DC. The SRD defines critical hits for attack rolls
     * only and says nothing of the sort about ability checks — this is a house
     * rule the game is choosing on purpose, because the die landing on a 20 is
     * the moment the whole two-phase ceremony exists to sell, and a 20 that
     * merely says "success" wastes it. A 20 that fails to beat the DC therefore
     * still succeeds here, and a 1 that would have beaten it still fails.
     */
    public static function outcomeOf(int $natural, int $total, int $dc): string
    {
        if ($natural === 20) {
            return 'critical';
        }
        if ($natural === 1) {
            return 'fumble';
        }
        return $total >= $dc ? 'success' : 'failure';
    }

    /**
     * The odds the UI shows, as a whole percent.
     *
     * Counted face by face rather than from a formula so that the auto-success
     * on a 20 and the auto-failure on a 1 above are counted the same way the
     * roll will count them; a closed form for "d20 + mod beats DC" disagrees
     * with this class at both ends of the die.
     *
     * Advantage and disadvantage compose as they do because the set of faces
     * that succeed is always the top of the die: taking the better of two dice
     * succeeds unless both fail.
     */
    public static function successChance(int $modifier, int $dc, string $dieMode = 'normal'): int
    {
        $hits = 0;
        for ($face = 1; $face <= 20; $face++) {
            if (self::faceSucceeds($face, $modifier, $dc)) {
                $hits++;
            }
        }

        $p = $hits / 20;
        if ($dieMode === 'advantage') {
            $p = 1 - ((1 - $p) ** 2);
        } elseif ($dieMode === 'disadvantage') {
            $p = $p ** 2;
        }

        return (int) round($p * 100);
    }

    // -----------------------------------------------------------------------
    // The pending-check store
    // -----------------------------------------------------------------------

    /**
     * Put an offered check in the session and return the token that names it.
     *
     * The token is random rather than derived from the check, so it carries no
     * information and cannot be constructed: a client that wants a check must
     * have been offered one. Nothing about the modifier or the DC is trusted
     * from the client on the way back, because none of it travels back.
     */
    public static function stash(array $pending): string
    {
        $id = bin2hex(random_bytes(16));
        $pending['check_id'] = $id;
        $pending['created_at'] = time();
        $_SESSION['pending_checks'][$id] = $pending;
        return $id;
    }

    /**
     * Take a pending check out of the session, or explain why there is none.
     *
     * Removal is unconditional and happens before the die is thrown, which is
     * what makes a token single-use: rerolling until the answer is agreeable
     * would otherwise be a matter of calling resolve in a loop.
     */
    public static function claim(string $checkId): array
    {
        $pending = $_SESSION['pending_checks'][$checkId] ?? null;
        unset($_SESSION['pending_checks'][$checkId]);

        if (!is_array($pending)) {
            throw new InvalidArgumentException('That check is no longer waiting to be rolled.');
        }
        if (time() - (int) ($pending['created_at'] ?? 0) > self::TTL_SECONDS) {
            throw new InvalidArgumentException('That check expired. Ask for it again.');
        }

        return $pending;
    }

    // -----------------------------------------------------------------------
    // Rest
    // -----------------------------------------------------------------------

    /**
     * Give back everything a long rest gives back.
     *
     * The flags are read and cleared through WorldState rather than deleted in
     * one statement, because WorldState caches a party's flags for the request
     * and a bulk delete behind its back would leave a bard's spent inspiration
     * still spent for the rest of the page load that restored it.
     */
    /**
     * What an hour's breather gives back, which is much less than a night.
     *
     * Only the features whose SRD text says "short rest" — Bardic Inspiration
     * and the Lucky feat's points are long-rest and stay spent, and so does
     * Rage. Named per feature rather than cleared by prefix, because the prefix
     * covers every bonus-action feature and clearing it wholesale would hand a
     * Barbarian unlimited rages for the price of sitting down.
     */
    public function resetOnShortRest(int $partyId): void
    {
        foreach (CombatEngine::SHORT_REST_FEATURES as $feature) {
            $prefix = self::FLAG_BONUS_FEATURE_USED . '_' . $feature . '_';
            $stmt = $this->db->prepare(
                'SELECT flag_key FROM world_flags WHERE party_id = ? AND flag_key LIKE ?'
            );
            $stmt->execute([$partyId, $prefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $this->world->clear($partyId, (string) $key);
            }
        }
    }

    public function resetOnLongRest(int $partyId): void
    {
        foreach (self::PER_REST_FLAGS as $prefix) {
            $stmt = $this->db->prepare(
                'SELECT flag_key FROM world_flags WHERE party_id = ? AND flag_key LIKE ?'
            );
            $stmt->execute([$partyId, $prefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $this->world->clear($partyId, (string) $key);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Boosts
    // -----------------------------------------------------------------------

    /**
     * What this party can actually pay for, right now.
     *
     * Only affordable boosts are listed. An option shown and then refused on
     * resolve is worse than an option never shown, and resolve trusts this list
     * completely — it is the record of what was legal at the moment the player
     * was asked.
     *
     * All SRD, and each is offered only on the kind of roll the SRD lets it
     * touch: Guidance buys an ability check and never a saving throw, Bless buys
     * a saving throw and never an ability check, and only Bardic Inspiration and
     * Lucky reach both. Offering them everywhere would be the easy version and
     * would hand the player a bonus the rules do not.
     *
     * Enhance Ability and the class features that grant advantage on particular
     * checks are deliberately absent: they need a target, a duration and a
     * caster's slot spent minutes ago, none of which this game records outside a
     * fight, and guessing at them would put invented rules in front of the
     * player.
     *
     * @param array<int, array> $party rows from WorldState::context()
     */
    private function boosts(int $partyId, array $party, array $actor, string $kind): array
    {
        $candidates = [];

        if ($kind === 'save') {
            $candidates[] = $this->blessBoost($partyId);
        } elseif ($kind === 'attack') {
            // Bless is the one that reads "attack rolls and saving throws" in
            // the SRD. Guidance is ability checks only, so it is deliberately
            // absent here — offering it on an attack would be the engine
            // inventing a rule the spell does not have.
            $candidates[] = $this->blessBoost($partyId);
        } else {
            $candidates[] = $this->guidanceBoost($party);
        }
        // Bardic Inspiration is "one ability check, attack roll, or saving
        // throw", so it belongs to all three.
        $candidates[] = $this->bardicBoost($partyId, $party, (int) $actor['id']);
        $candidates[] = self::luckyBoost($actor);
        // The Lucky feat is "any d20 test", which is all three kinds and then
        // some. It sits beside the Halfling's Lucky rather than replacing it —
        // a halfling who takes the feat has both, and they do different things.
        $candidates[] = $this->luckyFeatBoost($partyId, $actor);

        return array_values(array_filter($candidates));
    }

    /**
     * The Lucky feat: proficiency-bonus many points, one of them buying
     * advantage on this roll.
     *
     * Offered only while a point is left, because an option shown and then
     * refused on resolve is worse than an option never shown — the same rule
     * every other boost here follows. The points come back with a long rest,
     * through PER_REST_FLAGS.
     */
    private function luckyFeatBoost(int $partyId, array $actor): ?array
    {
        if (!Feats::flag($actor, 'luck_points')) {
            return null;
        }

        $characterId = (int) $actor['id'];
        $bonus = (int) ($actor['proficiency_bonus']
            ?? Rules::proficiencyBonus((int) ($actor['level'] ?? 1)));
        $points = Feats::sum($actor, 'luck_points', $bonus);
        $left = $points - $this->world->number($partyId, self::FLAG_LUCK_USED . '_' . $characterId);
        if ($left < 1) {
            return null;
        }

        return self::boost([
            'key'                 => 'lucky_feat',
            'label'               => 'Lucky',
            'detail'              => $left . ' luck point' . ($left === 1 ? '' : 's') . ' left',
            // A luck point buys the roll, not a number added to it — which is
            // why this carries a die mode and no dice at all.
            'die_mode'            => 'advantage',
            'cost'                => 'use',
            'source_character_id' => $characterId,
        ]);
    }

    /**
     * One Guidance, not one per cleric.
     *
     * The same cantrip cast twice does not stack, so offering it from every
     * knower would let a player spend three concentrations to add one die.
     */
    private function guidanceBoost(array $party): ?array
    {
        foreach ($party as $member) {
            if (!self::isConscious($member) || !$this->knowsGuidance((int) $member['id'])) {
                continue;
            }
            return self::boost([
                'key'                 => 'guidance',
                'label'               => 'Guidance',
                'detail'              => $member['name'] . ', cantrip',
                'bonus_dice'          => '1d4',
                'cost'                => 'concentration',
                'source_character_id' => (int) $member['id'],
            ]);
        }
        return null;
    }

    /**
     * The first bard who can still inspire someone, and has someone to inspire.
     *
     * A bard cannot inspire themselves, so the party's only bard making the
     * check has nothing to offer it. Uses are counted per bard because the die
     * comes out of that bard's own allowance, and only one inspiration die may
     * be spent on a single roll however many bards are standing about.
     */
    private function bardicBoost(int $partyId, array $party, int $actorId): ?array
    {
        foreach ($party as $member) {
            if ((int) $member['id'] === $actorId || !self::isConscious($member)) {
                continue;
            }
            if (strcasecmp(trim((string) $member['class']), 'Bard') !== 0) {
                continue;
            }

            $left = max(1, Rules::abilityMod((int) $member['charisma']))
                - $this->world->number($partyId, self::FLAG_BARDIC_USED . '_' . (int) $member['id']);
            if ($left < 1) {
                continue;
            }

            return self::boost([
                'key'                 => 'bardic_inspiration',
                'label'               => 'Bardic Inspiration',
                'detail'              => $member['name'] . ', ' . $left . ' left',
                // The die grows at 5th level. The campaign stops at 6, so the
                // rest of the SRD's table never comes up.
                'bonus_dice'          => (int) $member['level'] >= 5 ? '1d8' : '1d6',
                'cost'                => 'use',
                'source_character_id' => (int) $member['id'],
            ]);
        }
        return null;
    }

    /**
     * Bless, if somebody already has it running.
     *
     * It costs nothing here because the slot and the concentration were paid
     * when it was cast; this is only the question of whether the party is still
     * standing in it.
     *
     * UNREACHABLE, deliberately left standing. Nothing writes FLAG_BLESS, so
     * this always returns null. It is not an oversight to fix by setting the
     * flag somewhere: spells can only be cast in combat (CombatEngine::doCast
     * is private and no route reaches it otherwise), and a combat Bless is
     * handled there as a boon. This becomes live the day out-of-combat casting
     * exists, and until then the honest thing is to say so rather than leave a
     * reader believing Bless helps their skill checks.
     */
    private function blessBoost(int $partyId): ?array
    {
        if (!$this->world->isSet($partyId, self::FLAG_BLESS)) {
            return null;
        }
        return self::boost([
            'key'        => 'bless',
            'label'      => 'Bless',
            'detail'     => 'already running',
            'bonus_dice' => '1d4',
            'cost'       => 'free',
        ]);
    }

    /**
     * Halfling Lucky, which is not a bonus at all.
     *
     * It adds nothing and changes no mode; it says that a 1 is not final. That
     * is why it is carried as `reroll_ones` rather than as dice, and why it only
     * counts as spent when a 1 actually turns up.
     */
    private static function luckyBoost(array $actor): ?array
    {
        if (strcasecmp(trim((string) ($actor['race'] ?? '')), 'Halfling') !== 0) {
            return null;
        }
        return self::boost([
            'key'                 => 'halfling_lucky',
            'label'               => 'Lucky',
            'detail'              => 'reroll a natural 1',
            'reroll_ones'         => true,
            'cost'                => 'free',
            'source_character_id' => (int) $actor['id'],
        ]);
    }

    /**
     * One boost, with every key present.
     *
     * The client reads the same fields off each entry, so an entry that omitted
     * the ones it has no use for would make the renderer guard every field
     * rather than just the two that vary.
     */
    private static function boost(array $boost): array
    {
        return [
            'key'                 => $boost['key'],
            'label'               => $boost['label'],
            'detail'              => $boost['detail'] ?? '',
            'bonus_dice'          => $boost['bonus_dice'] ?? '',
            'bonus_flat'          => (int) ($boost['bonus_flat'] ?? 0),
            'die_mode'            => $boost['die_mode'] ?? null,
            'reroll_ones'         => (bool) ($boost['reroll_ones'] ?? false),
            'source_character_id' => $boost['source_character_id'] ?? null,
            'cost'                => $boost['cost'],
        ];
    }

    private static function spentEntry(string $key, array $boost): array
    {
        return [
            'key'                 => $key,
            'label'               => (string) $boost['label'],
            'cost'                => (string) $boost['cost'],
            'source_character_id' => $boost['source_character_id'] ?? null,
        ];
    }

    /**
     * Charge the party for what they spent.
     *
     * Only the boosts with a lasting cost appear here. Guidance costs its caster
     * their concentration, which is real but lives in combat state and has
     * nowhere to be recorded outside a fight; writing a flag for it would invent
     * a second, disagreeing record of who is concentrating on what.
     */
    private function spend(int $partyId, array $spent): void
    {
        foreach ($spent as $entry) {
            if ($entry['key'] === 'bardic_inspiration' && $entry['source_character_id'] !== null) {
                $this->world->increment(
                    $partyId,
                    self::FLAG_BARDIC_USED . '_' . (int) $entry['source_character_id']
                );
            }
            if ($entry['key'] === 'lucky_feat' && $entry['source_character_id'] !== null) {
                $this->world->increment(
                    $partyId,
                    self::FLAG_LUCK_USED . '_' . (int) $entry['source_character_id']
                );
            }
        }
    }

    private function knowsGuidance(int $characterId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM character_spells cs
             INNER JOIN spells s ON s.id = cs.spell_id
             WHERE cs.character_id = ? AND (s.spell_key = 'guidance' OR s.name = 'Guidance')
             LIMIT 1"
        );
        $stmt->execute([$characterId]);
        return (bool) $stmt->fetchColumn();
    }

    /** A downed party member is offering nobody anything. */
    private static function isConscious(array $member): bool
    {
        return (int) ($member['current_hp'] ?? 0) > 0;
    }

    // -----------------------------------------------------------------------
    // Reading the spec
    // -----------------------------------------------------------------------

    /**
     * What is being rolled, what to call it, and the modifier behind it.
     *
     * The modifier comes from Rules rather than being assembled here so that a
     * dialogue check and the same check made in combat cannot disagree about
     * what a character's Persuasion is.
     *
     * @return array{kind:string, label:string, total:int, parts:array}
     */
    private static function describe(array $actor, array $spec): array
    {
        // An attack roll, offered to the player as a die rather than resolved
        // behind their back. Unlike the others its modifier is not derived from
        // the character sheet — a weapon's bonus already folds in ability and
        // proficiency, and CombatEngine has worked it out by the time it asks —
        // so it arrives precomputed with its own breakdown.
        if (isset($spec['attack'])) {
            $a = $spec['attack'];
            return [
                'kind'  => 'attack',
                'label' => (string) ($a['label'] ?? 'Attack'),
                'total' => (int) ($a['modifier'] ?? 0),
                'parts' => (array) ($a['parts'] ?? []),
            ];
        }

        if (isset($spec['skill'])) {
            $skill = (string) $spec['skill'];
            $check = Rules::skillCheck($actor, $skill);
            return [
                'kind'  => 'skill',
                'label' => self::skillLabel($skill),
                'total' => $check['total'],
                'parts' => $check['parts'],
            ];
        }

        if (isset($spec['save'])) {
            $ability = self::abilityKey((string) $spec['save']);
            $check = Rules::savingThrow($actor, $ability);
            return [
                'kind'  => 'save',
                'label' => Rules::ABILITY_NAMES[$ability] . ' save',
                'total' => $check['total'],
                'parts' => $check['parts'],
            ];
        }

        if (isset($spec['ability'])) {
            // A raw ability check is the modifier and nothing else: proficiency
            // belongs to skills and saves, and adding it here would quietly
            // make "roll Strength" better than the Athletics check it exists to
            // avoid asking for.
            $ability = self::abilityKey((string) $spec['ability']);
            $mod = Rules::abilityMod(self::score($actor, $ability));
            return [
                'kind'  => 'ability',
                'label' => Rules::ABILITY_NAMES[$ability],
                'total' => $mod,
                'parts' => [['label' => Rules::ABILITY_NAMES[$ability], 'value' => $mod]],
            ];
        }

        throw new InvalidArgumentException('A check must name a skill, an ability or a save.');
    }

    /**
     * Whether the die is thrown once, or twice and one of them kept.
     *
     * Content states advantage directly; conditions supply it too, but only for
     * an actor that brought them — a `characters` row has no conditions column,
     * so this is silent for a check made out of combat and correct for one made
     * inside it.
     *
     * @return array{mode:string, reasons:string[]}
     */
    private static function dieMode(array $actor, string $kind, array $spec): array
    {
        // An attack arrives with its mode already decided. CombatEngine has
        // weighed conditions, Dodge, Help and Pack Tactics against each other
        // by then, and letting this re-apply the condition half would count it
        // twice — a prone target giving advantage, then giving it again.
        if ($kind === 'attack') {
            return [
                'mode'    => (string) ($spec['attack']['die_mode'] ?? 'normal'),
                'reasons' => (array) ($spec['attack']['die_reasons'] ?? []),
            ];
        }

        $advantage = [];
        $disadvantage = [];

        if (!empty($spec['advantage'])) {
            $advantage[] = is_string($spec['advantage']) ? $spec['advantage'] : 'advantage';
        }
        if (!empty($spec['disadvantage'])) {
            $disadvantage[] = is_string($spec['disadvantage']) ? $spec['disadvantage'] : 'disadvantage';
        }

        if ($kind === 'save') {
            // Conditions has already cancelled its own sources against each
            // other, so its verdict decides which pile its reasons belong in.
            // With neither flag set the reasons are notes about something else
            // — an automatic failure, say — and have no business colouring a
            // die they did not change.
            $ctx = Conditions::saveContext($actor, self::abilityKey((string) ($spec['save'] ?? 'con')));
            if ($ctx['advantage']) {
                $advantage = array_merge($advantage, $ctx['reasons']);
            } elseif ($ctx['disadvantage']) {
                $disadvantage = array_merge($disadvantage, $ctx['reasons']);
            }
        } else {
            foreach (Conditions::CATALOG as $key => $meta) {
                if ($meta['checks_disadvantage'] && Conditions::has($actor, $key)) {
                    $disadvantage[] = $meta['label'];
                }
            }
            // A feat that grants advantage on a named skill — Actor, on
            // Deception and Performance. Only skill checks: a raw ability check
            // is not the skill, and offering it there would hand the player a
            // bonus by asking for the wrong roll.
            if ($kind === 'skill' && isset($spec['skill'])) {
                $skill = str_replace([' ', '-'], '_', strtolower(trim((string) $spec['skill'])));
                if (in_array($skill, Feats::strings($actor, 'check_advantage'), true)) {
                    $advantage[] = 'a feat';
                }
                // Stonecunning. The SRD limits it to stonework History and a
                // check has no way to say what it is about, so a dwarf gets
                // all of History — the over-grant Rules::RACE_FEATURES notes.
                if ($skill === 'history' && Rules::raceFeature($actor, 'stonecunning')) {
                    $advantage[] = 'Stonecunning';
                }
            }
        }

        $hasAdvantage = $advantage !== [];
        $hasDisadvantage = $disadvantage !== [];
        $reasons = array_values(array_unique(array_merge($advantage, $disadvantage)));

        // Advantage and disadvantage cancel outright rather than accumulating,
        // as the SRD says, and both sides stay in `reasons` so the prompt can
        // say why the player is rolling one die after being told two things.
        if ($hasAdvantage && $hasDisadvantage) {
            $reasons[] = 'advantage and disadvantage cancel';
            return ['mode' => 'normal', 'reasons' => $reasons];
        }

        if ($hasAdvantage) {
            return ['mode' => 'advantage', 'reasons' => $reasons];
        }
        if ($hasDisadvantage) {
            return ['mode' => 'disadvantage', 'reasons' => $reasons];
        }
        return ['mode' => 'normal', 'reasons' => []];
    }

    /** A boost's die mode folded into the one the check already had. */
    private static function combineMode(string $mode, string $granted): string
    {
        if ($granted !== 'advantage' && $granted !== 'disadvantage') {
            return $mode;
        }
        if ($mode === 'normal' || $mode === $granted) {
            return $granted;
        }
        return 'normal';
    }

    private static function faceSucceeds(int $face, int $modifier, int $dc): bool
    {
        if ($face === 20) {
            return true;
        }
        if ($face === 1) {
            return false;
        }
        return $face + $modifier >= $dc;
    }

    private static function score(array $actor, string $ability): int
    {
        $long = self::ABILITY_COLUMNS[$ability];
        return (int) ($actor[$ability] ?? $actor[$long] ?? 10);
    }

    private static function abilityKey(string $ability): string
    {
        $ability = strtolower(trim($ability));
        if (isset(self::ABILITY_COLUMNS[$ability])) {
            return $ability;
        }
        $short = array_search($ability, self::ABILITY_COLUMNS, true);
        if ($short !== false) {
            return (string) $short;
        }
        throw new InvalidArgumentException("Unknown ability: {$ability}");
    }

    /** `sleight_of_hand` as the player reads it, not as the database stores it. */
    private static function skillLabel(string $skill): string
    {
        $words = explode('_', str_replace([' ', '-'], '_', strtolower(trim($skill))));
        foreach ($words as $i => $word) {
            $words[$i] = $word === 'of' ? $word : ucfirst($word);
        }
        return implode(' ', $words);
    }

    // -----------------------------------------------------------------------
    // The log
    // -----------------------------------------------------------------------

    /**
     * Record the roll for the journal's Rolls tab.
     *
     * `modifier` is the check's own modifier and never includes the boosts —
     * boosts_json is what explains a total that does not add up from the other
     * two columns, and folding them into the modifier would lose the fact that
     * the party spent anything at all.
     *
     * `natural` needs its quotes: it is a reserved word in MySQL, and an
     * unquoted one turns this INSERT into a syntax error.
     */
    private function log(int $partyId, array $pending, array $result): void
    {
        // Everything spent is logged, including the boosts that added nothing:
        // a Lucky reroll is the only thing that can explain a natural 1 sitting
        // in `d20_rolls` above a total that succeeded.
        $rolled = [];
        foreach ($result['boost_rolls'] as $roll) {
            $rolled[$roll['label']] = $roll['value'];
        }

        $boosts = [];
        foreach ($result['spent'] as $entry) {
            $boosts[] = [
                'key'   => $entry['key'],
                'label' => $entry['label'],
                'bonus' => $rolled[$entry['label']] ?? 0,
            ];
        }

        $this->db->prepare(
            'INSERT INTO roll_log
                (party_id, character_id, kind, label, dc, die_mode, d20_rolls,
                 `natural`, modifier, total, boosts_json, outcome)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $partyId,
            $pending['character']['id'] ?? null,
            $pending['kind'],
            $pending['label'],
            $pending['dc'],
            $result['die_mode'],
            implode(',', $result['rolls']),
            $result['natural'],
            $result['modifier'],
            $result['total'],
            $boosts === [] ? null : json_encode($boosts, JSON_UNESCAPED_SLASHES),
            $result['outcome'],
        ]);
    }

    /**
     * The party's recent rolls, newest first.
     *
     * Read back through the same names the client already knows from resolve(),
     * so the journal renders a logged roll with the code that renders a fresh
     * one.
     */
    public function history(int $partyId, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT r.id, r.character_id, c.name AS character_name, r.kind, r.label, r.dc,
                    r.die_mode, r.d20_rolls, r.`natural`, r.modifier, r.total,
                    r.boosts_json, r.outcome, r.created_at
             FROM roll_log r
             LEFT JOIN characters c ON c.id = r.character_id
             WHERE r.party_id = ?
             ORDER BY r.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$partyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $rolls = [];
            foreach (explode(',', (string) $row['d20_rolls']) as $die) {
                if ($die !== '') {
                    $rolls[] = (int) $die;
                }
            }
            $row['rolls'] = $rolls;
            $row['boosts'] = json_decode((string) ($row['boosts_json'] ?? ''), true) ?: [];
            $row['margin'] = $row['dc'] === null ? null : (int) $row['total'] - (int) $row['dc'];
            unset($row['d20_rolls'], $row['boosts_json']);
            $out[] = $row;
        }
        return $out;
    }
}
