<?php
/**
 * Companions: the cast, their standing with one party, and the real character
 * a recruitment turns a template into.
 *
 * `companions` is authored content and never changes during a playthrough.
 * `party_companions` is what this party has done to it — met, recruited,
 * dismissed, disappointed. Recruiting builds a row in `characters` from the
 * template, which is the point of keeping the two apart: editing Kessa's
 * authored Strength never has to reconcile with a save where she is already
 * carrying a greataxe.
 *
 * The arithmetic of building that character is CharacterGenerator's, not this
 * class's. A companion has to come out the same as a player-made character of
 * the same race and class, or their sheet is quietly a different game.
 *
 * This class deliberately knows nothing about combat. A companion who turns
 * hostile is reported, not fought: starting the fight is the caller's job,
 * because the caller is the one that knows whether we are in a conversation, at
 * camp or on the map, and CompanionService reaching into CombatEngine would
 * make every approval change a possible battle.
 */

declare(strict_types=1);

class CompanionService
{
    /**
     * How many characters may march at once, matching the cap api/index.php
     * enforces on character creation. Everyone else waits at camp — a fifth
     * body in `character_party` would draw a fifth portrait into a HUD built
     * for four and take a share of every XP award.
     */
    public const PARTY_CAP = 4;

    /** Statuses that mean "this companion is ours", for approval and romance. */
    private const RECRUITED = ['active', 'camp'];

    /**
     * The romance stage at which the party has chosen, and every other arc
     * closes.
     *
     * Authored romances run in two beats: stage 1 is the acknowledgement —
     * Kessa's "that's not a question, and I'm not going to make it one" — and
     * stage 2 is the answer to it. Locking on 2 rather than 1 is deliberate:
     * stage 1 is where the player finds out an arc exists, and a single warm
     * reply foreclosing every other character is a trap rather than a choice.
     * Reaching stage 2 is unambiguous, and it is the beat all three authored
     * arcs already use for it.
     *
     * One number, so moving the line is one edit. Set it to 1 and the first
     * flirt commits.
     */
    private const ROMANCE_COMMIT_STAGE = 2;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Every authored companion, whether or not this party has heard of them. */
    public function catalog(): array
    {
        return $this->db->query(
            'SELECT companion_key, name, title, description, race, subrace, class,
                    background, alignment, level, sprite_key, bust_count, combat_rank,
                    npc_key, personal_quest_key, recruit_location_id,
                    leave_at, hostile_at, romance_at, romanceable
             FROM companions ORDER BY name'
        )->fetchAll();
    }

    /**
     * The roster as this party knows it.
     *
     * Left-joined from the templates so a companion who has never been met
     * still appears, with the status `unmet` the schema's default describes.
     * The camp screen needs the whole cast to be able to say nothing about most
     * of it.
     */
    public function forParty(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.companion_key, c.name, c.title, c.description, c.race, c.subrace,
                    c.class, c.level, c.sprite_key, c.combat_rank, c.npc_key,
                    c.personal_quest_key, c.leave_at, c.hostile_at, c.romance_at,
                    c.romanceable,
                    COALESCE(pc.status, \'unmet\') AS status,
                    COALESCE(pc.approval, 0) AS approval,
                    COALESCE(pc.romance_stage, 0) AS romance_stage,
                    COALESCE(pc.romance_locked, 0) AS romance_locked,
                    pc.character_id, pc.recruited_at,
                    ch.current_hp, ch.max_hp, ch.armor_class
             FROM companions c
             LEFT JOIN party_companions pc
                    ON pc.companion_key = c.companion_key AND pc.party_id = ?
             LEFT JOIN characters ch ON ch.id = pc.character_id
             ORDER BY FIELD(COALESCE(pc.status, \'unmet\'),
                            \'active\', \'camp\', \'met\', \'unmet\', \'left\', \'hostile\', \'dead\'),
                      c.name'
        );
        $stmt->execute([$partyId]);

        return array_map(static function (array $row): array {
            $row['approval']       = (int) $row['approval'];
            $row['romance_stage']  = (int) $row['romance_stage'];
            $row['romance_locked'] = (bool) $row['romance_locked'];
            $row['character_id']   = $row['character_id'] === null ? null : (int) $row['character_id'];
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * First contact: `unmet` becomes `met`.
     *
     * Its own step because recruitment conditions are usually written against
     * it — `{"flag": "met_kessa"}` is the common form, but a companion's own
     * dialogue needs to know whether this is the first conversation, and a
     * status is more honest about that than a flag an author has to remember
     * to set.
     */
    public function meet(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);

        $r = Effects::blank();
        if ($state && $state['status'] !== 'unmet') {
            $r['notes'][] = "{$companion['name']} has already been met.";
            return $r;
        }

        $this->db->prepare(
            'INSERT INTO party_companions (party_id, companion_key, status) VALUES (?,?,\'met\')
             ON DUPLICATE KEY UPDATE status = \'met\''
        )->execute([$partyId, $key]);

        $r['messages'][] = "You have met {$companion['name']}.";
        return $r;
    }

    /**
     * Build the companion into the party as a real character.
     *
     * Everything a player-made character gets: racial bonuses, hit points from
     * the class's die, an armour class recomputed once their gear is on, origin
     * tags for dialogue to key off, and a place beside the leader on the map.
     * They arrive with the experience their level is worth so that the next XP
     * award does not immediately promote them for having been written as level 3.
     *
     * A full party does not refuse the recruit — the scene that recruited them
     * has already played — so they wait at camp instead.
     */
    public function recruit(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);
        $r = Effects::blank();

        if ($state && in_array($state['status'], self::RECRUITED, true)) {
            $r['notes'][] = "{$companion['name']} is already with the party.";
            return $r;
        }

        // Someone who left and was talked back into it keeps the character they
        // walked out with, gear and scars included. Rebuilding them from the
        // template would quietly undo everything the party had done to them.
        $characterId = $state && $state['character_id'] ? (int) $state['character_id'] : null;

        // ...and they do not walk straight back out again.
        //
        // Approval is what made them leave and re-recruiting did not touch it,
        // so a companion won back at or under `leave_at` was still under it the
        // moment they rejoined. `checkThresholds()` runs on every long rest, so
        // the first time the party made camp they left a second time, for the
        // same reason, with the same line. Measured on a real save: Aldric at
        // -32 against a threshold of -30 rejoined, camped, and was gone again
        // inside one rest — a revolving door that would have made any
        // reconciliation scene feel broken rather than hard-won.
        //
        // Coming back is therefore worth exactly enough to be standing on: ten
        // points clear of the line. Not zero — being talked round is not the
        // same as being forgiven, and a companion who walked out once should
        // stay easy to lose. Only ever upward, and only for somebody who
        // actually left, so this can never quietly reward a party whose
        // standing is already fine.
        if ($state && in_array($state['status'], ['left', 'hostile'], true)) {
            $floor = (int) $companion['leave_at'] + 10;
            if ((int) $state['approval'] < $floor) {
                $this->db->prepare(
                    'UPDATE party_companions SET approval = ?
                      WHERE party_id = ? AND companion_key = ?'
                )->execute([$floor, $partyId, $key]);
                $r['notes'][] = sprintf(
                    '%s is back, and watching how this goes.',
                    $companion['name']
                );
            }
        }
        if ($characterId !== null && !$this->characterExists($characterId)) {
            $characterId = null;
        }
        if ($characterId === null) {
            $characterId = $this->buildCharacter($partyId, $companion);
        }

        $hasRoom = $this->marchingCount($partyId) < self::PARTY_CAP;
        $this->db->prepare(
            'INSERT INTO party_companions (party_id, companion_key, character_id, status, recruited_at)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE character_id = VALUES(character_id),
                                     status = VALUES(status),
                                     recruited_at = COALESCE(recruited_at, NOW())'
        )->execute([$partyId, $key, $characterId, $hasRoom ? 'active' : 'camp']);

        if ($hasRoom) {
            $this->joinParty($partyId, $characterId);
            $r['messages'][] = "{$companion['name']} joins the party.";
        } else {
            $this->leaveParty($characterId);
            $r['messages'][] = "{$companion['name']} joins you, and waits at camp — the party is full.";
        }

        // The tile is NOT cleared. clearWorldPresence() used to wipe
        // map_tiles.npc_id here — globally, because the column is global — and
        // that did two kinds of damage. It recruited the companion out of
        // every OTHER party's world: once one game took Brother Aldric, no new
        // game could ever meet him. And once the database became the source of
        // truth, the vacancy exported: aldric.json came out with no `place`
        // block, so even a fresh install seeded from the files had nobody in
        // the inn to recruit. LocationEngine already hides active and
        // camped companions per party through party_companions, which is the
        // correct scope for "they are travelling with me" — a fact about one
        // party that was being written into the world for all of them.
        // Exclusivity has to hold for somebody who was not in the party when
        // it was decided. closeOtherRomances() locks everyone travelling with
        // the party at the moment of commitment, which leaves the person
        // recruited afterwards as the one door still open — their arc would
        // offer itself to a player who is already committed, and taking it
        // would be a second romance the rule says cannot exist.
        if ((int) ($companion['romanceable'] ?? 0) === 1 && $this->hasCommittedRomance($partyId, $key)) {
            $this->db->prepare(
                'UPDATE party_companions SET romance_locked = 1
                  WHERE party_id = ? AND companion_key = ?'
            )->execute([$partyId, $key]);
        }

        $r['recruited'][] = $key;
        return $r;
    }

    /** Whether this party has already committed to somebody other than `$except`. */
    private function hasCommittedRomance(int $partyId, string $except): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM party_companions
              WHERE party_id = ? AND companion_key <> ? AND romance_stage >= ?'
        );
        $stmt->execute([$partyId, $except, self::ROMANCE_COMMIT_STAGE]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /*
     * clearWorldPresence() lived here. It wiped map_tiles.npc_id at
     * recruitment so the new companion did not also stand at the bar offering
     * to join a party they were already in — the double it worried about was
     * real, but the engine now solves it per party (active and
     * camped companions are filtered through party_companions), and the
     * global wipe had costs the filter does not: it removed the companion
     * from every other party's world, and it exported as an NPC with no
     * `place` block once the database became the source of truth. Deleted
     * rather than kept as a no-op, for the same reason shopCatalog() was.
     */

    /** Send an active companion to camp. They are still yours. */
    public function dismiss(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->requireState($partyId, $key);
        $r = Effects::blank();

        if ($state['status'] !== 'active') {
            $r['notes'][] = "{$companion['name']} is not travelling with you.";
            return $r;
        }

        $this->db->prepare(
            'UPDATE party_companions SET status = \'camp\' WHERE party_id = ? AND companion_key = ?'
        )->execute([$partyId, $key]);
        if ($state['character_id']) {
            $this->leaveParty((int) $state['character_id']);
        }

        $r['messages'][] = "{$companion['name']} will wait at camp.";
        return $r;
    }

    /**
     * Bring a companion out of camp, if there is room.
     *
     * This is the one place the cap is refused rather than worked around: the
     * player is choosing, and silently declining to swap someone in is worse
     * than saying the party is full.
     */
    public function activate(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->requireState($partyId, $key);
        $r = Effects::blank();

        if ($state['status'] === 'active') {
            $r['notes'][] = "{$companion['name']} is already with you.";
            return $r;
        }
        if ($state['status'] !== 'camp') {
            throw new InvalidArgumentException("{$companion['name']} is not at camp.");
        }
        if ($this->marchingCount($partyId) >= self::PARTY_CAP) {
            throw new InvalidArgumentException('Party is full (max ' . self::PARTY_CAP . ' characters).');
        }

        $characterId = (int) $state['character_id'];
        if (!$characterId || !$this->characterExists($characterId)) {
            $characterId = $this->buildCharacter($partyId, $companion);
            $this->db->prepare(
                'UPDATE party_companions SET character_id = ? WHERE party_id = ? AND companion_key = ?'
            )->execute([$characterId, $partyId, $key]);
        }

        $this->db->prepare(
            'UPDATE party_companions SET status = \'active\' WHERE party_id = ? AND companion_key = ?'
        )->execute([$partyId, $key]);
        $this->joinParty($partyId, $characterId);
        $this->moveToLeader($partyId, $characterId);

        $r['messages'][] = "{$companion['name']} rejoins the party.";
        return $r;
    }

    /**
     * Move a companion's opinion, and say what that crossed.
     *
     * Only a recruited companion has one to move. Content is written for the
     * whole cast — the same choice pleases Kessa and appals Aldric whether or
     * not either is present — so a delta aimed at someone who has not joined is
     * reported and dropped, never stored against a future recruitment. Banking
     * approval for a companion the party has not met means they arrive already
     * in love with you or already leaving.
     *
     * `crossed` names the threshold this change took them through, and only on
     * the change that crossed it, so a companion sitting below `leave_at`
     * announces it once rather than every time anyone speaks to them.
     */
    public function adjustApproval(int $partyId, string $key, int $delta): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);

        if (!$state || !in_array($state['status'], self::RECRUITED, true)) {
            return [
                'applied'  => false,
                'crossed'  => null,
                'approval' => 0,
                'note'     => "{$companion['name']} is not in the party; approval unchanged.",
            ];
        }

        $before = (int) $state['approval'];
        $after  = $before + $delta;
        $this->db->prepare(
            'UPDATE party_companions SET approval = ? WHERE party_id = ? AND companion_key = ?'
        )->execute([$after, $partyId, $key]);

        $crossed = self::thresholdCrossed($before, $after, $companion);
        // A "romance" crossing on somebody whose arc cannot open — not
        // romanceable, or locked because the player chose someone else — is
        // not a crossing, and reporting it would promise a door that is not
        // there.
        if ($crossed === 'romance'
            && ((int) ($companion['romanceable'] ?? 0) !== 1
                || (int) ($state['romance_locked'] ?? 0) === 1)) {
            $crossed = null;
        }

        return [
            'applied'  => true,
            'crossed'  => $crossed,
            'approval' => $after,
            'name'     => (string) $companion['name'],
            'note'     => '',
        ];
    }

    /**
     * Which threshold a change in approval passed through, if any.
     *
     * Pure, and separate from the write, because it is the only arithmetic in
     * this class that decides anything: whether a companion walks out, turns on
     * the party, or becomes someone the romance arc may open for. Downward
     * crossings are checked first — a change that somehow passes both is a
     * companion leaving, not a companion falling in love.
     */
    public static function thresholdCrossed(int $before, int $after, array $companion): ?string
    {
        $hostileAt = (int) ($companion['hostile_at'] ?? -70);
        $leaveAt   = (int) ($companion['leave_at'] ?? -40);
        $romanceAt = (int) ($companion['romance_at'] ?? 30);

        if ($before > $hostileAt && $after <= $hostileAt) {
            return 'hostile';
        }
        if ($before > $leaveAt && $after <= $leaveAt) {
            return 'leave';
        }
        if ($before < $romanceAt && $after >= $romanceAt) {
            return 'romance';
        }
        return null;
    }

    /**
     * Advance a romance.
     *
     * Gated on `romance_at` rather than on a flag, so an arc cannot open on a
     * companion who merely tolerates the party, and refused outright for one
     * who is not `romanceable` — that is a property of the character, not of
     * how well the conversation went. `romance_locked` is set by a companion
     * turning the player down, or by another arc closing this one off.
     */
    public function setRomanceStage(int $partyId, string $key, int $stage): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);
        $r = Effects::blank();

        if (!$state || !in_array($state['status'], self::RECRUITED, true)) {
            $r['notes'][] = "{$companion['name']} is not in the party; romance unchanged.";
            return $r;
        }
        if (!$companion['romanceable']) {
            $r['notes'][] = "{$companion['name']} is not romanceable.";
            return $r;
        }
        if ($state['romance_locked']) {
            $r['notes'][] = "{$companion['name']}'s romance is closed.";
            return $r;
        }
        if ((int) $state['approval'] < (int) $companion['romance_at']) {
            $r['notes'][] = "{$companion['name']} does not think of you that way yet.";
            return $r;
        }

        $stage = max(0, $stage);
        $this->db->prepare(
            'UPDATE party_companions SET romance_stage = ? WHERE party_id = ? AND companion_key = ?'
        )->execute([$stage, $partyId, $key]);

        $r['messages'][] = "Your bond with {$companion['name']} deepens.";

        if ($stage >= self::ROMANCE_COMMIT_STAGE) {
            $r = Effects::merge($r, $this->closeOtherRomances($partyId, $key));
        }
        return $r;
    }

    /**
     * Choosing one closes the rest.
     *
     * `romance_locked` has existed on `party_companions` since romance was
     * added and the gate in setRomanceStage() has always read it — but nothing
     * anywhere ever wrote it, so the column was decorative and all three arcs
     * could be run to completion in one playthrough. This is the write.
     *
     * Everyone else is locked, not just the ones already being courted: a
     * companion recruited *after* the party settled down should not have an
     * arc either, and the alternative is a check that has to run again at
     * every recruitment. Locking is cheap and it is permanent by design —
     * there is no unlock, because taking one back would make the choice
     * meaningless, which is the whole reason for having it.
     *
     * Recruited-only, because a companion who is not travelling with the party
     * has no romance state to close and no row to close it on.
     */
    private function closeOtherRomances(int $partyId, string $chosen): array
    {
        $in = implode(',', array_fill(0, count(self::RECRUITED), '?'));
        $stmt = $this->db->prepare(
            "SELECT pc.companion_key, c.name
               FROM party_companions pc
               INNER JOIN companions c ON c.companion_key = pc.companion_key
              WHERE pc.party_id = ?
                AND pc.companion_key <> ?
                AND pc.romance_locked = 0
                AND pc.status IN ({$in})"
        );
        $stmt->execute(array_merge([$partyId, $chosen], self::RECRUITED));
        $closing = $stmt->fetchAll();

        $r = Effects::blank();
        if (!$closing) {
            return $r;
        }

        $keys = array_column($closing, 'companion_key');
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $this->db->prepare(
            "UPDATE party_companions SET romance_locked = 1
              WHERE party_id = ? AND companion_key IN ({$marks})"
        )->execute(array_merge([$partyId], $keys));

        // Only worth saying for somebody the player had actually started
        // something with. Announcing that a companion they have never spoken
        // to that way is now unavailable tells them about a door by closing
        // it, which is worse than silence.
        foreach ($closing as $row) {
            $state = $this->state($partyId, (string) $row['companion_key']);
            if ($state && (int) $state['romance_stage'] > 0) {
                $r['messages'][] = "Whatever was beginning with {$row['name']} is not going to.";
            }
        }
        return $r;
    }

    /**
     * Settle up with everyone whose approval has run out.
     *
     * Called by the camp screen and by the rest effect, which is the "next
     * camp" docs/CONTENT.md defers a departure to. A companion below
     * `hostile_at` is turned here too, in case the change that took them there
     * was applied by something that did not check — but the fight is still the
     * caller's to start.
     */
    public function checkThresholds(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.companion_key, pc.approval, c.leave_at, c.hostile_at
             FROM party_companions pc
             INNER JOIN companions c ON c.companion_key = pc.companion_key
             WHERE pc.party_id = ? AND pc.status IN (\'active\',\'camp\')'
        );
        $stmt->execute([$partyId]);

        $r = Effects::blank();
        foreach ($stmt->fetchAll() as $row) {
            $approval = (int) $row['approval'];
            if ($approval <= (int) $row['hostile_at']) {
                $r = Effects::merge($r, $this->turnHostile($partyId, (string) $row['companion_key']));
            } elseif ($approval <= (int) $row['leave_at']) {
                $r = Effects::merge($r, $this->leave($partyId, (string) $row['companion_key']));
            }
        }
        return $r;
    }

    /**
     * A companion walks out for good.
     *
     * Their `characters` row is kept rather than deleted: they may be written
     * back into the story later, and a row deleted here takes their inventory
     * and their history with it.
     */
    public function leave(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);
        $r = Effects::blank();

        if (!$state || !in_array($state['status'], self::RECRUITED, true)) {
            $r['notes'][] = "{$companion['name']} is not in the party.";
            return $r;
        }

        $this->db->prepare(
            'UPDATE party_companions SET status = \'left\' WHERE party_id = ? AND companion_key = ?'
        )->execute([$partyId, $key]);
        if ($state['character_id']) {
            $this->leaveParty((int) $state['character_id']);
        }

        $r['messages'][] = "{$companion['name']} has left the party.";
        $r['left'][] = $key;
        return $r;
    }

    /**
     * A companion turns on the party.
     *
     * The caller is responsible for spawning the fight: this class must not
     * reach into CombatEngine, or every `{"approval": {...}}` in a dialogue
     * file becomes a possible combat trigger and the dialogue engine loses
     * control of its own screen. `hostile` in the result is the signal.
     */
    public function turnHostile(int $partyId, string $key): array
    {
        $companion = $this->template($key);
        $state = $this->state($partyId, $key);
        $r = Effects::blank();

        if (!$state || $state['status'] === 'hostile') {
            $r['notes'][] = "{$companion['name']} is not with the party.";
            return $r;
        }

        $this->db->prepare(
            'UPDATE party_companions SET status = \'hostile\' WHERE party_id = ? AND companion_key = ?'
        )->execute([$partyId, $key]);
        if ($state['character_id']) {
            $this->leaveParty((int) $state['character_id']);
        }

        $r['messages'][] = "{$companion['name']} turns on you.";
        $r['hostile'][] = $key;
        return $r;
    }

    // -----------------------------------------------------------------------

    /**
     * Turn a template into a `characters` row.
     *
     * The authored ability scores are treated as base scores and take the
     * race's bonuses, exactly as a player's point-buy array does. The
     * alternative — reading them as finals — means an author has to know that
     * a Half-Orc's 16 Strength should be written as 14, which is precisely the
     * arithmetic a content file should never contain.
     */
    private function buildCharacter(int $partyId, array $companion): int
    {
        $gen = new CharacterGenerator($this->db);
        $race = $gen->findRace((string) $companion['race'], $companion['subrace'] ?: null);
        if (!$race) {
            throw new RuntimeException(
                "Companion {$companion['companion_key']} names an unknown race: {$companion['race']}"
            );
        }
        $class = $gen->findClass((string) $companion['class']);
        if (!$class) {
            throw new RuntimeException(
                "Companion {$companion['companion_key']} names an unknown class: {$companion['class']}"
            );
        }

        $abilities = CharacterGenerator::racialAbilities([
            'strength'     => (int) $companion['strength'],
            'dexterity'    => (int) $companion['dexterity'],
            'constitution' => (int) $companion['constitution'],
            'intelligence' => (int) $companion['intelligence'],
            'wisdom'       => (int) $companion['wisdom'],
            'charisma'     => (int) $companion['charisma'],
        ], $race);

        $level = max(1, (int) $companion['level']);
        $subclass = ((int) $class['subclass_level'] <= $level) ? $class['subclass_name'] : null;
        $maxHp = CharacterGenerator::hitPointsAtLevel(
            (int) $class['hit_die'],
            ability_mod($abilities['constitution']),
            $level,
            $race['subrace'],
            $subclass
        );
        $ac = CharacterGenerator::unarmoredAc((string) $companion['class'], $abilities, $subclass);

        $where = $this->leaderPosition($partyId);
        $tags = WorldState::originTags([
            'race'       => $companion['race'],
            'subrace'    => $companion['subrace'],
            'class'      => $companion['class'],
            'background' => $companion['background'],
        ]);

        $this->db->prepare(
            'INSERT INTO characters
             (user_id, name, race, subrace, class, subclass, level, experience,
              strength, dexterity, constitution, intelligence, wisdom, charisma,
              max_hp, current_hp, armor_class, speed, proficiency_bonus, gold,
              background, alignment, combat_rank, origin_tags,
              companion_key, current_location_id, is_active)
             VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,0, ?,?,?,?, ?,?,1)'
        )->execute([
            $where['user_id'],
            $companion['name'],
            $companion['race'],
            $companion['subrace'],
            $companion['class'],
            $subclass,
            $level,
            Rules::XP_THRESHOLDS[min($level, Rules::MAX_LEVEL)] ?? 0,
            $abilities['strength'],
            $abilities['dexterity'],
            $abilities['constitution'],
            $abilities['intelligence'],
            $abilities['wisdom'],
            $abilities['charisma'],
            $maxHp,
            $maxHp,
            $ac,
            (int) $race['speed'],
            Rules::proficiencyBonus($level),
            $companion['background'],
            $companion['alignment'],
            // sprite_key is deliberately NOT copied. It is resolved from the
            // companion template at read time by CharacterGenerator, so editing
            // a companion's art in content/ reaches saves already in progress
            // instead of only new ones.
            $companion['combat_rank'],
            implode(',', $tags),
            $companion['companion_key'],
            $where['location_id'],
        ]);

        $characterId = (int) $this->db->lastInsertId();
        $this->grantGear($characterId, $companion['starting_gear_json']);
        $this->grantSpells($characterId, $companion['starting_spells_json']);
        $this->grantFeats($characterId, $companion['starting_feats_json'] ?? null);
        // Once their armour is on, the unarmoured AC written above is wrong.
        // grantFeats has already recomputed it once for the Defense fighting
        // style; this is the pass that accounts for the armour itself, and the
        // two cannot disagree because both ask CharacterGenerator.
        $gen->recalculateAC($characterId);

        return $characterId;
    }

    /**
     * The feats the template arrives with, named by catalogue key.
     *
     * Applied through LevelUpService rather than written straight to
     * `feats_json`, because a feat is worth more than the row: Tough is hit
     * points, Resilient is an ability score, the Defense fighting style is
     * armour class. Recruiting somebody with a feat that did none of those
     * things would produce a companion whose sheet disagrees with their own
     * character card, and nothing would say so.
     *
     * A key the catalogue does not carry, or a feat this companion does not
     * qualify for, throws — exactly as gear naming an unimported item does.
     * Both are content errors, and a companion who quietly arrives without what
     * they were written with is the harder one to notice.
     */
    private function grantFeats(int $characterId, ?string $featsJson): void
    {
        $keys = json_decode((string) $featsJson, true);
        if (!is_array($keys) || !$keys) {
            return;
        }
        $levels = new LevelUpService($this->db);
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!Feats::exists($key)) {
                throw new RuntimeException("Companion feats name no such feat: {$key}");
            }
            $levels->grantFeat($characterId, $key);
        }
    }

    /**
     * Starting gear, named by item key.
     *
     * Unlike CharacterGenerator's class kits, which are row ids from the seed,
     * a companion's gear is authored content and refers to items by key. A key
     * with no item behind it is a load error that got through, so it throws
     * rather than arming them with nothing.
     */
    private function grantGear(int $characterId, ?string $gearJson): void
    {
        $keys = json_decode((string) $gearJson, true);
        if (!is_array($keys) || !$keys) {
            return;
        }
        $find = $this->db->prepare('SELECT id, item_type FROM items WHERE item_key = ?');
        $add = $this->db->prepare(
            'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
             VALUES (?,?,1,?)'
        );
        $armed = false;
        foreach ($keys as $key) {
            $find->execute([(string) $key]);
            $item = $find->fetch();
            if (!$item) {
                throw new RuntimeException("Companion gear names no imported item: {$key}");
            }
            // Their first weapon is drawn, and any armour is worn; a companion
            // who arrives with a greataxe in their pack fights bare-handed.
            $equip = 0;
            if ($item['item_type'] === 'armor') {
                $equip = 1;
            } elseif ($item['item_type'] === 'weapon' && !$armed) {
                $equip = 1;
                $armed = true;
            }
            $add->execute([$characterId, (int) $item['id'], $equip]);
        }
    }

    private function grantSpells(int $characterId, ?string $spellsJson): void
    {
        $keys = json_decode((string) $spellsJson, true);
        if (!is_array($keys) || !$keys) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO character_spells (character_id, spell_id, prepared)
             SELECT ?, id, 1 FROM spells WHERE spell_key = ? LIMIT 1'
        );
        foreach ($keys as $key) {
            $ins->execute([$characterId, (string) $key]);
        }
    }

    private function template(string $key): array
    {
        $stmt = $this->db->prepare('SELECT * FROM companions WHERE companion_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No imported companion with key: {$key}");
        }
        return $row;
    }

    private function state(int $partyId, string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM party_companions WHERE party_id = ? AND companion_key = ?'
        );
        $stmt->execute([$partyId, $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function requireState(int $partyId, string $key): array
    {
        $state = $this->state($partyId, $key);
        if (!$state) {
            throw new InvalidArgumentException("The party has not met {$key}.");
        }
        return $state;
    }

    private function characterExists(int $characterId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        return (bool) $stmt->fetchColumn();
    }

    /** How many characters are currently marching, companions included. */
    private function marchingCount(int $partyId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM character_party WHERE party_id = ?');
        $stmt->execute([$partyId]);
        return (int) $stmt->fetchColumn();
    }

    private function joinParty(int $partyId, int $characterId): void
    {
        $slot = $this->db->prepare(
            'SELECT COALESCE(MAX(slot),0)+1 FROM character_party WHERE party_id = ?'
        );
        $slot->execute([$partyId]);
        $this->db->prepare(
            'INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE slot = slot'
        )->execute([$partyId, $characterId, (int) $slot->fetchColumn()]);
    }

    private function leaveParty(int $characterId): void
    {
        $this->db->prepare('DELETE FROM character_party WHERE character_id = ?')
            ->execute([$characterId]);
    }

    private function moveToLeader(int $partyId, int $characterId): void
    {
        $where = $this->leaderPosition($partyId);
        $this->db->prepare(
            'UPDATE characters SET current_location_id = ? WHERE id = ?'
        )->execute([$where['location_id'], $characterId]);
    }

    /**
     * Where a new companion appears, and whose account they belong to.
     *
     * The leader's location: there is one party and it is in one place, and a
     * companion standing anywhere else would be visible only as an inventory
     * they cannot reach.
     */
    private function leaderPosition(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.user_id, c.current_location_id
             FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             LEFT JOIN parties p ON p.id = cp.party_id
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY c.id = p.leader_character_id DESC, cp.slot
             LIMIT 1'
        );
        $stmt->execute([$partyId]);
        $row = $stmt->fetch();

        return [
            'user_id'     => $row['user_id'] ?? null,
            'location_id' => $row ? $row['current_location_id'] : null,
        ];
    }
}
