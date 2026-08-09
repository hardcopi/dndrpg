<?php

/**
 * The Fighting Pit.
 *
 * A place to take a fight for its own sake. Everything else in the world is a
 * fight somebody authored — placed at a location, or opened by a line of
 * dialogue — and every one of them is finite. The pit is the other kind: it
 * builds a match to order, sized to whoever walks in, and it never runs out.
 *
 * The sizing is 5e's own encounter arithmetic and not a guess. Each character
 * contributes an XP threshold for their level; those add up to a budget; a
 * group of monsters is worth its XP times a multiplier for how many of them
 * there are, because six things attacking you is harder than the sum of six
 * things. The budget is what the tier buys — the same match is a warm-up for a
 * level 5 party and very nearly the end of a level 1 one.
 *
 * What it does NOT do is invent rewards. The XP and the gold are the monsters'
 * own, paid by CombatEngine exactly as they are for an authored fight, so an
 * hour in the pit is worth an hour of the same monsters anywhere else. A pit
 * that paid better than the world would be the only content anybody played.
 */

declare(strict_types=1);

class PitEngine
{
    /** What the pit offers, easiest first. */
    public const TIERS = [
        'warmup' => ['label' => 'A warm-up',    'column' => 0, 'blurb' => 'Something to break a sweat on.'],
        'fair'   => ['label' => 'A fair match', 'column' => 1, 'blurb' => 'An honest fight, evenly made.'],
        'hard'   => ['label' => 'A hard match', 'column' => 2, 'blurb' => 'The crowd will get its money back.'],
    ];

    /**
     * The thresholds moved to EncounterBudget when the dungeon generator
     * needed the same arithmetic. A second copy of a tuning table is how the
     * slot table drifted once already, and a difficulty curve that disagrees
     * with itself is invisible until somebody dies to it.
     */

    /**
     * Six foes.
     *
     * This used to be justified by the ranks — four to a rank, two ranks, so
     * eight was a hard ceiling and six a comfortable one. The ceiling is gone:
     * a deployment zone is three columns of ten and would hold thirty. The
     * working limit was always the real one and it still applies. Past six the
     * fight is a queue rather than a battle, every round takes a minute to
     * read, and the monster turn — which now pathfinds — costs real time inside
     * a single request. Raising it is a tuning decision, not a consequence of
     * the floor arriving.
     */
    private const MAX_FOES = 6;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * What the pit will put in front of this party, per tier.
     *
     * Rolled fresh, so the numbers shown are a fair sample of what a bout at
     * that tier looks like rather than a promise about the next one.
     */
    public function offer(int $partyId): array
    {
        $out = [];
        foreach (self::TIERS as $key => $tier) {
            $budget = $this->budget($partyId, $key);
            $group = $this->pick($budget);
            $out[] = [
                'tier'   => $key,
                'label'  => $tier['label'],
                'blurb'  => $tier['blurb'],
                'budget' => $budget,
                'sample' => $this->describe($group),
            ];
        }
        return $out;
    }

    /**
     * Build a bout and hand back the encounter it lives in.
     *
     * The encounter row is scratch: one per party, rewritten every time, and
     * keyed with a leading underscore so it reads as machinery rather than as
     * something an author wrote. It has to be a real row because
     * CombatEngine::startEncounter reads the fight out of the database — and
     * giving each party its own means two people can be in the pit at once
     * without dealing each other's monsters.
     */
    public function bout(int $partyId, string $tier): int
    {
        if (!isset(self::TIERS[$tier])) {
            throw new InvalidArgumentException('No such bout.');
        }
        $group = $this->pick($this->budget($partyId, $tier));
        if (!$group) {
            throw new RuntimeException('The pit has nothing to send out.');
        }

        $encounterId = $this->scratchEncounter($partyId);
        $this->db->prepare('UPDATE encounters SET name = ?, description = ? WHERE id = ?')
            ->execute([
                'The Pit: ' . $this->describe($group),
                'The gate goes up. ' . self::TIERS[$tier]['blurb'],
                $encounterId,
            ]);

        $this->db->prepare('DELETE FROM encounter_monsters WHERE encounter_id = ?')
            ->execute([$encounterId]);
        $ins = $this->db->prepare(
            'INSERT INTO encounter_monsters (encounter_id, monster_id, quantity) VALUES (?,?,?)'
        );
        foreach ($group as $g) {
            $ins->execute([$encounterId, $g['id'], $g['quantity']]);
        }
        return $encounterId;
    }

    // -----------------------------------------------------------------------

    /** What this party's tier is worth, in monster XP. */
    private function budget(int $partyId, string $tier): int
    {
        $stmt = $this->db->prepare(
            'SELECT c.level FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             WHERE cp.party_id = ? AND c.is_active = 1'
        );
        $stmt->execute([$partyId]);
        return EncounterBudget::forParty(
            array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            $tier
        );
    }

    /** EncounterBudget's, kept under the old name so `pick()` reads unchanged. */
    private static function multiplier(int $count): float
    {
        return EncounterBudget::multiplier($count);
    }

    /**
     * Choose what comes through the gate.
     *
     * Biased toward the biggest thing the budget can afford more than one of,
     * so a party that has grown out of goblins stops being sent goblins. A
     * second species is added when there is room and change left over, because
     * two ogres is a fight and two ogres and a pack of wolves is a match.
     *
     * @return list<array{id:int,name:string,quantity:int,xp:int}>
     */
    private function pick(int $budget): array
    {
        $roster = $this->db->query(
            'SELECT id, name, experience_points AS xp FROM monsters
              WHERE experience_points > 0 ORDER BY experience_points DESC'
        )->fetchAll();
        if (!$roster) {
            return [];
        }

        $affordable = array_values(array_filter(
            $roster,
            static fn ($m) => (int) $m['xp'] * self::multiplier(1) <= $budget
        ));
        // Too poor for anything on the books: send the cheapest thing there is,
        // alone, rather than nothing. A level 1 party is meant to survive a
        // warm-up, not to find the pit empty.
        if (!$affordable) {
            $cheapest = $roster[count($roster) - 1];
            return [[
                'id' => (int) $cheapest['id'], 'name' => $cheapest['name'],
                'quantity' => 1, 'xp' => (int) $cheapest['xp'],
            ]];
        }

        // Only creatures that could fill the budget if the sand were full of
        // them. Without this the headline is drawn from everything the party
        // can afford, which at high budgets means everything — and six goblins
        // came out as a level 5 party's "hard match", worth a fifth of what
        // was asked for. Random within what is left, so the pit is not the
        // same fight every night.
        $ceiling = self::MAX_FOES * self::multiplier(self::MAX_FOES);
        $capable = array_values(array_filter(
            $affordable,
            static fn ($m) => (int) $m['xp'] * $ceiling >= $budget * 0.5
        ));
        if (!$capable) {
            // Nothing on the books can fill it — the party has outgrown the
            // bestiary. Send the biggest thing there is, whichever of them
            // that turns out to be.
            $topXp = (int) $affordable[0]['xp'];
            $capable = array_values(array_filter(
                $affordable,
                static fn ($m) => (int) $m['xp'] === $topXp
            ));
        }
        $headline = $capable[random_int(0, count($capable) - 1)];

        $xp = max(1, (int) $headline['xp']);
        $n = $this->bestCount($xp, $budget, 0, 0, self::MAX_FOES, true);
        $group = [[
            'id' => (int) $headline['id'], 'name' => $headline['name'],
            'quantity' => $n, 'xp' => $xp,
        ]];
        $rawUsed = $xp * $n;
        $count = $n;

        // Something else on the sand, if the budget genuinely stands it. Tested
        // against the WHOLE group, because the multiplier is a property of the
        // fight: adding one more stirge to two bandits is not the price of a
        // stirge, it is the price of turning a pair into a crowd. Getting this
        // wrong made a solo level 1 "hard" bout worth double its own budget.
        if ($count < self::MAX_FOES && random_int(0, 2) > 0) {
            $others = array_values(array_filter(
                $affordable,
                static fn ($m) => (int) $m['id'] !== (int) $headline['id']
            ));
            if ($others) {
                $second = $others[random_int(0, count($others) - 1)];
                $sxp = max(1, (int) $second['xp']);
                $sn = $this->bestCount(
                    $sxp,
                    $budget,
                    $rawUsed,
                    $count,
                    self::MAX_FOES - $count,
                    false
                );
                if ($sn > 0) {
                    $group[] = [
                        'id' => (int) $second['id'], 'name' => $second['name'],
                        'quantity' => $sn, 'xp' => $sxp,
                    ];
                }
            }
        }
        return $group;
    }

    /**
     * How many more of a creature the budget stands.
     *
     * Measured on the whole fight — what is already committed plus what is
     * being added — because that is what the multiplier is about. Returns 0
     * when not even one fits, unless somebody has to come through the gate.
     */
    private function bestCount(
        int $xp,
        int $budget,
        int $rawUsed,
        int $count,
        int $slots,
        bool $atLeastOne
    ): int {
        return EncounterBudget::bestCount($xp, $budget, $slots, $rawUsed, $count, $atLeastOne);
    }

    /**
     * "3 Goblins and an Ogre" — what the crowd is told it is about to see.
     *
     * The phrasing moved to CombatEngine::describeRoster when the ambush
     * warning needed to say the same kind of thing. It is the same sentence
     * about the same table, and two of it would have drifted.
     */
    private function describe(array $group): string
    {
        return CombatEngine::describeRoster($group);
    }

    /** This party's scratch encounter row, made on first use. */
    private function scratchEncounter(int $partyId): int
    {
        $key = '_pit_party_' . $partyId;
        $stmt = $this->db->prepare('SELECT id FROM encounters WHERE encounter_key = ?');
        $stmt->execute([$key]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        // No region and no location: it is not placed in the world, it is not
        // drawn from on travel rolls, and it cannot be stumbled into. The only
        // way to meet it is to ask for it.
        $this->db->prepare(
            // scale_to_party off: the card the player read is the card they
            // bought, and this roster was already built against the budget for
            // the tier they chose. Letting CombatEngine fit it a second time
            // would quietly sell them a different bout from the one on the
            // board.
            'INSERT INTO encounters
                (encounter_key, name, description, is_random, is_ambush, difficulty,
                 allow_flee, allow_parley, scale_to_party)
             VALUES (?, ?, ?, 0, 0, ?, 1, 0, 0)'
        )->execute([$key, 'The Pit', 'A bout in the fighting pit.', 'pit']);
        return (int) $this->db->lastInsertId();
    }
}
