<?php
/**
 * Quests as a graph of stages, scoped to the party.
 *
 * A quest is not a boolean and not a sequence. Nothing declares the edges
 * between stages: dialogue, encounters and other quests move a quest with a
 * `quest_stage` effect, and tools/load_content.py refuses to import a stage no
 * effect can reach. That is what lets one quest have three endings without the
 * engine knowing anything about any of them — it only ever enters the stage it
 * was told to.
 *
 * Party-scoped on purpose. A quest belongs to the playthrough: companions join
 * and leave and the leader can be swapped, and quest progress that lived on one
 * character would be lost or duplicated the moment the roster changed.
 *
 * Everything that changes the world does so through Effects — including the
 * quest's own rewards, which are paid by synthesising the effect list an author
 * would have written by hand. One code path for "gain 250 XP" means the party
 * levels the same way whoever handed the XP over.
 */

declare(strict_types=1);

class QuestService
{
    private PDO $db;
    private ?Effects $effects;

    /**
     * Effects is optional and lazy because the two classes need each other: a
     * `start_quest` effect calls in here, and a stage's own effects call back
     * out. Constructing eagerly on both sides is an infinite regress; taking
     * the caller's instance when there is one keeps a single depth counter
     * across the whole cascade, which is what catches a content cycle.
     */
    public function __construct(PDO $db, ?Effects $effects = null)
    {
        $this->db = $db;
        $this->effects = $effects;
    }

    private function effects(): Effects
    {
        return $this->effects ??= new Effects($this->db);
    }

    /** Built on demand: only the job board reads party flags from in here. */
    private ?WorldState $worldState = null;

    private function world(): WorldState
    {
        return $this->worldState ??= new WorldState($this->db);
    }

    // -----------------------------------------------------------------------
    // Lifecycle

    /**
     * Take a quest on, entering its first stage.
     *
     * The first stage is the one with the lowest `sort_order`, which the
     * importer fills from the order the stages appear in the JSON file. The
     * author's first stage is the one the party gets; nothing has to declare
     * an entry point twice.
     */
    public function start(int $partyId, string $questKey): array
    {
        $quest = $this->questByKey($questKey);
        $existing = $this->partyQuest($partyId, (int) $quest['id']);

        if ($existing && in_array($existing['status'], ['active', 'completed', 'failed'], true)) {
            // Two NPCs may both offer the same job. The second offer is a note,
            // not an error, because refusing it would end the conversation.
            $r = Effects::blank();
            $r['notes'][] = "Quest already {$existing['status']}: {$quest['title']}";
            return $r + ['ok' => true, 'quest' => $quest];
        }

        // Not another game's work.
        //
        // postable() filters the BOARD by module, which was enough while the
        // board was the only way across — and it was not. A defeat put a party
        // in the wrong module (CombatEngine's haven was a literal), and from
        // there they talked to a Rivermark companion and took `kessa_debt` into
        // an Old City save, where its target is a location no exit reaches. A
        // filter on one door is not a guard; this is the guard.
        //
        // A note rather than a throw, matching the "already taken" case above:
        // a refusal that ends the conversation is worse than one that says so.
        // And silent when the quest has no anchor to derive a module from — a
        // companion quest with neither giver nor target belongs wherever the
        // companion is, which is the party.
        $questModule = $this->moduleOfQuest((int) $quest['id']);
        $partyModule = $this->partyModuleId($partyId);
        if ($questModule !== null && $partyModule !== null && $questModule !== $partyModule) {
            $r = Effects::blank();
            $r['notes'][] = "{$quest['title']} belongs to another module; not taken.";
            return $r + ['ok' => true, 'quest' => $quest];
        }

        $level = $this->leaderLevel($partyId);
        if ($level < (int) $quest['required_level']) {
            throw new InvalidArgumentException('You do not meet the required level.');
        }

        $this->db->prepare(
            'INSERT INTO party_quests (party_id, quest_id, status, started_at)
             VALUES (?,?,\'active\',NOW())
             ON DUPLICATE KEY UPDATE status = \'active\', started_at = NOW(),
                                     resolution = NULL, ended_at = NULL'
        )->execute([$partyId, (int) $quest['id']]);

        $first = $this->firstStage((int) $quest['id']);
        $r = Effects::blank();
        $r['messages'][] = 'Quest started: ' . $quest['title'];
        $r['quests_started'][] = $questKey;
        if ($first) {
            $r = Effects::merge($r, $this->enterStage($partyId, $quest, $first));
        }
        return $r + ['ok' => true, 'quest' => $quest, 'stage' => $first];
    }

    /**
     * Move a quest to a named stage.
     *
     * A quest not yet taken is started by being advanced, rather than refusing.
     * Content routinely pushes a party straight into the middle of something —
     * you are handed the ledger before anyone offers you the job — and making
     * every such author remember to write `start_quest` first is a rule the
     * loader cannot check and a scene that silently does nothing.
     */
    public function advance(int $partyId, string $questKey, string $stageKey): array
    {
        $quest = $this->questByKey($questKey);
        $stage = $this->stage((int) $quest['id'], $stageKey);
        $row = $this->partyQuest($partyId, (int) $quest['id']);

        if ($row && in_array($row['status'], ['completed', 'failed'], true)) {
            $r = Effects::blank();
            $r['notes'][] = "Quest already {$row['status']}, cannot advance: {$quest['title']}";
            return $r + ['ok' => true, 'quest' => $quest];
        }

        if (!$row) {
            $this->db->prepare(
                'INSERT INTO party_quests (party_id, quest_id, status, started_at)
                 VALUES (?,?,\'active\',NOW())'
            )->execute([$partyId, (int) $quest['id']]);
        }

        $r = $this->enterStage($partyId, $quest, $stage);
        return $r + ['ok' => true, 'quest' => $quest, 'stage' => $stage];
    }

    /**
     * End a quest badly without entering a stage.
     *
     * For the failures a quest cannot describe as an ending of its own: the NPC
     * who was carrying it is dead, the act moved on. A quest with an authored
     * failure ending should use that stage instead, so the journal records what
     * happened rather than only that it stopped.
     */
    /**
     * Walking into a room, as a way of getting on with a quest.
     *
     * GENERATED QUESTS ONLY, and the `_dg_q_` prefix is the whole of the guard.
     * An authored stage with a target location is a place the quest is POINTING
     * AT — the marker on the map — and it advances when its own effect fires,
     * from a conversation or a fight. Advancing those by arrival would march
     * every authored quest forward the moment a party wandered past, which is
     * the sort of thing that cannot be undone in a save.
     *
     * A generated floor has nobody to talk to, so arrival is all there is.
     *
     * ONLY THE VERY NEXT STAGE, never a later one that happens to be here.
     * Matching any unpassed stage let a party walk into the last room first and
     * finish the errand without doing it — observed, not theorised: a delve
     * jumped from stage one to stage three and closed the quest, because room
     * three was nearer the door than room two. The errand is a route.
     *
     * Two consecutive steps in the SAME room resolve one after the other, on
     * consecutive looks, which is right: the generator writes those when a room
     * is worth two beats.
     */
    public function arriveAt(int $partyId, int $locationId): array
    {
        if ($partyId <= 0 || $locationId <= 0) {
            return Effects::blank() + ['ok' => true];
        }

        $stmt = $this->db->prepare(
            "SELECT q.quest_key, s.stage_key
               FROM party_quests pq
               INNER JOIN quests q ON q.id = pq.quest_id
               INNER JOIN quest_stages s ON s.quest_id = q.id
              WHERE pq.party_id = ?
                AND pq.status = 'active'
                AND q.quest_key LIKE '\_dg\_q\_%'
                AND s.target_location_id = ?
                AND s.sort_order = (
                      SELECT MIN(n.sort_order) FROM quest_stages n
                       WHERE n.quest_id = q.id
                         AND n.sort_order > COALESCE(
                               (SELECT cs.sort_order FROM quest_stages cs
                                 WHERE cs.id = pq.current_stage_id), 0))
              LIMIT 1"
        );
        $stmt->execute([$partyId, $locationId]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$next) {
            return Effects::blank() + ['ok' => true];
        }

        return $this->advance($partyId, (string) $next['quest_key'], (string) $next['stage_key']);
    }

    public function fail(int $partyId, string $questKey): array
    {
        $quest = $this->questByKey($questKey);
        $row = $this->partyQuest($partyId, (int) $quest['id']);
        $r = Effects::blank();

        if (!$row) {
            $r['notes'][] = "Cannot fail a quest the party never took: {$questKey}";
            return $r + ['ok' => true, 'quest' => $quest];
        }
        if (in_array($row['status'], ['completed', 'failed'], true)) {
            $r['notes'][] = "Quest already {$row['status']}: {$quest['title']}";
            return $r + ['ok' => true, 'quest' => $quest];
        }

        $this->db->prepare(
            'UPDATE party_quests SET status = \'failed\', ended_at = NOW(), is_tracked = 0
             WHERE party_id = ? AND quest_id = ?'
        )->execute([$partyId, (int) $quest['id']]);

        $r['messages'][] = 'Quest failed: ' . $quest['title'];
        return $r + ['ok' => true, 'quest' => $quest];
    }

    /**
     * Enter a stage: the one place quest progress is written.
     *
     * Order matters. The stage is recorded before its effects run, so that an
     * effect which reads the quest's own state — a condition on
     * `{"quest": "q", "stage": "s"}` — sees the stage it is in and not the one
     * it came from. Rewards are paid last, after the terminal stage's effects,
     * because an ending that sets a flag the reward depends on should have set
     * it by then.
     */
    private function enterStage(int $partyId, array $quest, array $stage): array
    {
        $questId = (int) $quest['id'];
        $stageId = (int) $stage['id'];

        $this->db->prepare(
            'UPDATE party_quests SET current_stage_id = ?, status = \'active\'
             WHERE party_id = ? AND quest_id = ?'
        )->execute([$stageId, $partyId, $questId]);

        // The unique key on (party_id, stage_id) makes re-entering a stage a
        // no-op in the history rather than a second line in the journal. A
        // quest that loops back through a hub stage should read as one visit.
        $this->db->prepare(
            'INSERT IGNORE INTO party_quest_stages (party_id, quest_id, stage_id) VALUES (?,?,?)'
        )->execute([$partyId, $questId, $stageId]);

        $r = Effects::blank();
        $r['messages'][] = $quest['title'] . ': ' . $stage['title'];
        $r['quests_advanced'][] = [
            'quest'   => (string) $quest['quest_key'],
            'stage'   => (string) $stage['stage_key'],
            'title'   => (string) $stage['title'],
            'journal' => (string) ($stage['journal_entry'] ?? ''),
        ];

        $effects = json_decode((string) ($stage['effects_json'] ?? ''), true);
        if (is_array($effects) && $effects) {
            $r = Effects::merge($r, $this->effects()->apply($partyId, $effects));
        }

        if ((int) $stage['is_terminal'] === 1) {
            $r = Effects::merge($r, $this->finish($partyId, $quest, $stage));
        }
        return $r;
    }

    /**
     * Close a quest on one of its endings.
     *
     * `resolution` is copied onto the party's row so that Act 2 can ask
     * `{"quest": "ledger_and_lie", "resolution": "sided_with_sera"}` without
     * walking the stage history, and so it survives the stage being renamed.
     *
     * Rewards are paid exactly once, guarded by `ended_at` rather than by the
     * status, because a quest can be re-entered by content that does not know
     * it already ended. A `failure` ending pays nothing — that is the whole
     * distinction between it and a `neutral` one, which does.
     */
    private function finish(int $partyId, array $quest, array $stage): array
    {
        $questId = (int) $quest['id'];
        $before = $this->partyQuest($partyId, $questId);
        $alreadyEnded = $before && $before['ended_at'] !== null;

        $failed = ($stage['outcome'] ?? 'success') === 'failure';
        $this->db->prepare(
            'UPDATE party_quests SET status = ?, resolution = ?, ended_at = NOW(), is_tracked = 0
             WHERE party_id = ? AND quest_id = ?'
        )->execute([
            $failed ? 'failed' : 'completed',
            $stage['resolution'],
            $partyId,
            $questId,
        ]);

        $r = Effects::blank();
        $r['messages'][] = $failed
            ? 'Quest failed: ' . $quest['title']
            : 'Quest complete: ' . $quest['title'];

        if ($failed || $alreadyEnded) {
            return $r;
        }
        return Effects::merge($r, $this->payRewards($partyId, $quest));
    }

    /**
     * Hand over what the quest promised.
     *
     * Built as an effect list and run through Effects so that quest XP splits
     * across the party the way combat XP does and a reward item stacks the way
     * a looted one does. The keyless fallback is for the five hand-seeded
     * quests that predate content keys and name their reward by row id.
     */
    private function payRewards(int $partyId, array $quest): array
    {
        $effects = [];
        if ((int) $quest['reward_xp'] > 0) {
            $effects[] = ['xp' => (int) $quest['reward_xp']];
        }
        if ((int) $quest['reward_gold'] > 0) {
            $effects[] = ['give_gold' => (int) $quest['reward_gold']];
        }

        $r = Effects::blank();
        if (!empty($quest['reward_item_id'])) {
            $item = $this->db->prepare('SELECT id, item_key, name FROM items WHERE id = ?');
            $item->execute([(int) $quest['reward_item_id']]);
            $row = $item->fetch();
            if ($row && $row['item_key'] !== null) {
                $effects[] = ['give_item' => (string) $row['item_key']];
            } elseif ($row) {
                $leader = $this->leaderId($partyId);
                if ($leader !== null) {
                    $this->db->prepare(
                        'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
                         VALUES (?,?,1,0)'
                    )->execute([$leader, (int) $row['id']]);
                    $r['messages'][] = 'Received ' . $row['name'] . '.';
                }
            }
        }

        return $effects ? Effects::merge($r, $this->effects()->apply($partyId, $effects)) : $r;
    }

    // -----------------------------------------------------------------------
    // Reading

    /**
     * The journal: every quest this party has touched, with where it stands and
     * the path it actually took.
     *
     * The history is the point. A finished quest's design has three endings; a
     * finished quest in *this* save has one, reached through the stages this
     * party entered, and that is what the journal prints.
     */
    public function listForParty(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pq.quest_id, pq.status, pq.resolution, pq.is_tracked,
                    pq.started_at, pq.ended_at,
                    q.quest_key, q.title, q.description, q.act, q.companion_key,
                    q.reward_xp, q.reward_gold,
                    s.stage_key AS current_stage_key, s.title AS current_stage_title,
                    s.objective AS current_objective, s.journal_entry AS current_journal,
                    COALESCE(s.target_location_id, q.target_location_id) AS target_location_id,
                    l.name AS target_location_name, n.name AS giver_name
             FROM party_quests pq
             INNER JOIN quests q ON q.id = pq.quest_id
             LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
             LEFT JOIN locations l ON l.id = COALESCE(s.target_location_id, q.target_location_id)
             LEFT JOIN npcs n ON n.id = q.giver_npc_id
             WHERE pq.party_id = ?
             ORDER BY FIELD(pq.status, \'active\', \'available\', \'completed\', \'failed\'), q.act, q.id'
        );
        $stmt->execute([$partyId]);
        $quests = $stmt->fetchAll();

        // One query for every quest's history rather than one per quest: the
        // journal is opened often and is otherwise N+1 round trips deep.
        $hist = $this->db->prepare(
            'SELECT pqs.quest_id, pqs.entered_at, s.stage_key, s.title, s.journal_entry,
                    s.is_terminal, s.resolution, s.outcome
             FROM party_quest_stages pqs
             INNER JOIN quest_stages s ON s.id = pqs.stage_id
             WHERE pqs.party_id = ?
             ORDER BY pqs.id'
        );
        $hist->execute([$partyId]);
        $byQuest = [];
        foreach ($hist->fetchAll() as $row) {
            $byQuest[(int) $row['quest_id']][] = $row;
        }

        foreach ($quests as &$quest) {
            $quest['history'] = $byQuest[(int) $quest['quest_id']] ?? [];
        }
        unset($quest);
        return $quests;
    }

    /**
     * The job board: work the party could take but has not.
     *
     * `on_job_board` is what separates a contract pinned to a wall from a quest
     * that only starts because someone told you about it. A personal quest or
     * one that opens mid-conversation must never appear here, or the player
     * accepts it before the scene that explains it.
     */
    public function listAvailable(int $partyId): array
    {
        $seen = $this->boardSeen($partyId);
        if (!$seen) {
            return [];
        }
        return array_values(array_filter(
            $this->postable($partyId),
            static fn (array $q) => isset($seen[(string) $q['quest_key']])
        ));
    }

    /**
     * Read the board: everything posted on it becomes work the party knows of.
     *
     * The knowing is per party and per quest, in world_flags, so a job read in
     * one playthrough is not read in another and levelling into a new job puts
     * it on the board rather than into the journal unannounced.
     *
     * Returns what is on the board now — including the jobs already read, since
     * the board does not take a notice down because you have looked at it.
     */
    public function readBoard(int $partyId): array
    {
        $posted = $this->postable($partyId);
        foreach ($posted as $q) {
            $this->world()->set($partyId, self::boardFlag((string) $q['quest_key']));
        }
        return $posted;
    }

    /** Which board jobs this party has actually stood in front of and read. */
    private function boardSeen(int $partyId): array
    {
        $seen = [];
        foreach ($this->world()->all($partyId) as $key => $_) {
            if (str_starts_with((string) $key, 'job_read:')) {
                $seen[substr((string) $key, 9)] = true;
            }
        }
        return $seen;
    }

    private static function boardFlag(string $questKey): string
    {
        return 'job_read:' . $questKey;
    }

    /**
     * What the board is carrying: active, posted, not already taken, within the
     * party's level, and belonging to the game they are playing. Whether they
     * have READ it is a separate question — this is what is on the wall, not
     * what they know about.
     *
     * The module filter is here and nowhere else because this is the only place
     * content crosses between games. Everywhere else a party can see the world,
     * it sees it by walking the exit graph, and two modules share no exits — so
     * they are kept apart by construction. The board is a query instead, and a
     * query does not care what is reachable: without this clause a party three
     * hops into the Old City reads Rivermark's notices, for jobs whose givers
     * they can never meet.
     *
     * A quest's module is derived from where it is anchored rather than stored
     * on the row. Every quest has an anchor — a giver, a target, or both — and
     * a second copy of the fact would be a second thing to keep in step. The
     * giver wins when both are set, since that is who is doing the asking;
     * COALESCE falls through to the target when a quest has no giver.
     *
     * A quest with neither resolves to NULL and posts nowhere, which is the
     * safe direction to fail: invisible rather than leaked. The loader has to
     * be the thing that catches it, since nothing here can.
     */
    private function postable(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT q.id, q.quest_key, q.title, q.description, q.act,
                    q.required_level, q.reward_xp, q.reward_gold,
                    n.name AS giver_name, l.name AS target_location_name
             FROM quests q
             LEFT JOIN npcs n ON n.id = q.giver_npc_id
             LEFT JOIN locations l ON l.id = q.target_location_id
             LEFT JOIN locations gl ON gl.id = n.location_id
             LEFT JOIN regions gr ON gr.id = gl.region_id
             LEFT JOIN regions tr ON tr.id = l.region_id
             LEFT JOIN party_quests pq ON pq.quest_id = q.id AND pq.party_id = :party
             WHERE q.is_active = 1 AND q.on_job_board = 1
               AND pq.id IS NULL
               AND q.required_level <= :level
               AND COALESCE(gr.module_id, tr.module_id) = :module
             ORDER BY q.act, q.required_level, q.id'
        );
        $stmt->execute([
            ':party'  => $partyId,
            ':level'  => $this->leaderLevel($partyId),
            ':module' => $this->partyModuleId($partyId),
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Which module's world a party is playing in.
     *
     * Falls back to the first active module for a party that predates the
     * column, which is what the NULL on `parties.module_id` is documented to
     * mean. Doing that here rather than in SQL is deliberate: the obvious
     * spelling is a NULL-safe `<=>` against the party row, and that quietly
     * matches a NULL-module party against NULL-module quests only — an empty
     * job board for exactly the old saves the fallback exists to protect.
     */
    /**
     * Which module a quest belongs to, by the same derivation the board uses.
     *
     * Giver first, then target — the giver is who is doing the asking. NULL
     * when it has neither, which is a companion quest carried by a person
     * rather than pinned to a place, and those belong to whoever is holding
     * them.
     */
    private function moduleOfQuest(int $questId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(gr.module_id, tr.module_id)
               FROM quests q
               LEFT JOIN npcs n ON n.id = q.giver_npc_id
               LEFT JOIN locations gl ON gl.id = n.location_id
               LEFT JOIN regions gr ON gr.id = gl.region_id
               LEFT JOIN locations tl ON tl.id = q.target_location_id
               LEFT JOIN regions tr ON tr.id = tl.region_id
              WHERE q.id = ?'
        );
        $stmt->execute([$questId]);
        $id = $stmt->fetchColumn();
        return ($id === false || $id === null) ? null : (int) $id;
    }

    private function partyModuleId(int $partyId): ?int
    {
        $stmt = $this->db->prepare('SELECT module_id FROM parties WHERE id = ?');
        $stmt->execute([$partyId]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        $id = $this->db->query(
            'SELECT id FROM modules WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1'
        )->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Follow one quest. Exactly one, or none.
     *
     * Tracking is a single marker on the map and a single line under the
     * compass; two tracked quests is two arrows pointing different ways, which
     * is worse than none.
     */
    public function track(int $partyId, string $questKey): array
    {
        $quest = $this->questByKey($questKey);
        $row = $this->partyQuest($partyId, (int) $quest['id']);
        if (!$row) {
            throw new InvalidArgumentException('The party has not taken that quest.');
        }

        $this->db->prepare('UPDATE party_quests SET is_tracked = 0 WHERE party_id = ?')
            ->execute([$partyId]);
        $this->db->prepare(
            'UPDATE party_quests SET is_tracked = 1 WHERE party_id = ? AND quest_id = ?'
        )->execute([$partyId, (int) $quest['id']]);

        return ['ok' => true, 'tracked' => $questKey, 'markers' => $this->markers($partyId)];
    }

    /**
     * Where the map should point.
     *
     * Only active quests, and only from the stage the party is actually on: a
     * marker left over from the stage before sends the player back to a door
     * they have already opened.
     */
    /**
     * Quest markers, each already told how to reach it from where the party is.
     *
     * `$fromMapId` is optional so the raw marker list is still available to
     * anything that only wants to know what is outstanding; pass it and every
     * marker gains a `route` saying either "walk to this tile" or "take that
     * doorway", which is the difference between a marker and directions.
     */
    /**
     * @param ?int $forCharacterId whose eyes decide which people are named
     *                             rather than described, when a marker reports
     *                             what is waiting at its destination.
     */
    public function markers(int $partyId, ?int $fromLocationId = null, ?int $forCharacterId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT q.quest_key, q.title, s.stage_key, s.title AS stage_title,
                    s.objective, pq.is_tracked,
                    COALESCE(s.target_location_id, q.target_location_id) AS location_id,
                    l.name AS location_name
             FROM party_quests pq
             INNER JOIN quests q ON q.id = pq.quest_id
             LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
             LEFT JOIN locations l ON l.id = COALESCE(s.target_location_id, q.target_location_id)
             WHERE pq.party_id = ? AND pq.status = \'active\'
               AND COALESCE(s.target_location_id, q.target_location_id) IS NOT NULL
             ORDER BY pq.is_tracked DESC, q.id'
        );
        $stmt->execute([$partyId]);

        $engine = $fromLocationId === null ? null : new LocationEngine($this->db);

        return array_map(
            static function (array $row) use ($engine, $fromLocationId, $partyId, $forCharacterId): array {
                $marker = [
                    'quest'         => (string) $row['quest_key'],
                    'title'         => (string) $row['title'],
                    'stage'         => $row['stage_key'],
                    'stage_title'   => $row['stage_title'],
                    'objective'     => $row['objective'],
                    'location_id'   => (int) $row['location_id'],
                    'location_name' => $row['location_name'],
                    'tracked'       => (bool) $row['is_tracked'],
                ];
                if ($engine !== null) {
                    $marker['route'] = $engine->routeTo((int) $fromLocationId, $marker['location_id']);
                    // Standing on the marker, "You are here" is where the
                    // tracker used to stop — which is the moment it is least
                    // use. Say what is in front of them instead.
                    if (!empty($marker['route']['arrived']) && $forCharacterId) {
                        $marker['here'] = $engine->whatIsHere(
                            $marker['location_id'],
                            $forCharacterId,
                            $partyId
                        );
                    }
                }
                return $marker;
            },
            $stmt->fetchAll()
        );
    }

    // -----------------------------------------------------------------------

    private function questByKey(string $questKey): array
    {
        $row = $this->questByKeyOrNull($questKey);
        if (!$row) {
            throw new RuntimeException("No active quest with key: {$questKey}");
        }
        return $row;
    }

    /**
     * Is this party actually on that job right now?
     *
     * For callers who may advance a quest as a side effect of something else —
     * winning a fight, say — and must not thereby hand out a job nobody was
     * given. advance() starts a quest the party does not have, which is right
     * when a line of dialogue says so and wrong when a sword does.
     */
    public function isActiveFor(int $partyId, string $questKey): bool
    {
        $quest = $this->questByKeyOrNull($questKey);
        if (!$quest) {
            return false;
        }
        $row = $this->partyQuest($partyId, (int) $quest['id']);
        return $row !== null && $row['status'] === 'active';
    }

    /**
     * Where this party stands with a quest: 'active', 'completed', 'failed',
     * or null for one they have never been given.
     *
     * `isActiveFor()` above answers a narrower question and cannot answer this
     * one — it reports false for a finished quest and for an unknown quest
     * alike, and the difference between those two is exactly what a
     * conversation needs. A reply offering a job is worth showing to somebody
     * who has never heard of it and is noise to somebody who finished it last
     * night; both look identical through `isActiveFor`.
     */
    public function statusFor(int $partyId, string $questKey): ?string
    {
        $quest = $this->questByKeyOrNull($questKey);
        if (!$quest) {
            return null;
        }
        $row = $this->partyQuest($partyId, (int) $quest['id']);
        return $row === null ? null : (string) $row['status'];
    }

    /** The same lookup for callers asking a question rather than making a demand. */
    public function questByKeyOrNull(string $questKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM quests WHERE quest_key = ? AND is_active = 1');
        $stmt->execute([$questKey]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The level this party would need to take that job, or null if nothing
     * stands in the way.
     *
     * The same gate `start()` enforces, asked before the fact. `start()`
     * throws, which is right for the job board — the caller can leave the row
     * out of the list — and wrong inside a conversation, where the reply has
     * already been offered and the throw comes back as a 400 that kills the
     * dialogue. So the gate is readable as well as enforceable: one definition,
     * asked two ways.
     */
    public function levelGate(int $partyId, string $questKey): ?int
    {
        $quest = $this->questByKeyOrNull($questKey);
        if (!$quest) {
            return null;
        }
        $required = (int) $quest['required_level'];
        if ($required <= 1) {
            return null;
        }
        // A job already taken is not gated: start() answers a repeat offer with
        // a note rather than an error, so that reply stays live regardless.
        if ($this->partyQuest($partyId, (int) $quest['id'])) {
            return null;
        }
        return $this->leaderLevel($partyId) < $required ? $required : null;
    }

    /** A quest row by id, for the callers that already hold one. */
    public function questById(int $questId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM quests WHERE id = ? AND is_active = 1');
        $stmt->execute([$questId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No active quest with id: {$questId}");
        }
        return $row;
    }

    private function stage(int $questId, string $stageKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM quest_stages WHERE quest_id = ? AND stage_key = ?');
        $stmt->execute([$questId, $stageKey]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Quest has no stage {$stageKey}");
        }
        return $row;
    }

    private function firstStage(int $questId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM quest_stages WHERE quest_id = ? ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute([$questId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function partyQuest(int $partyId, int $questId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM party_quests WHERE party_id = ? AND quest_id = ?'
        );
        $stmt->execute([$partyId, $questId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function leaderId(int $partyId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT c.id FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             LEFT JOIN parties p ON p.id = cp.party_id
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY c.id = p.leader_character_id DESC, cp.slot
             LIMIT 1'
        );
        $stmt->execute([$partyId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * The level a required_level gate is measured against.
     *
     * The leader's, not the party's best: the gate is about who is taking the
     * job, and a level 1 party does not become eligible because a level 3
     * companion is standing behind them.
     */
    private function leaderLevel(int $partyId): int
    {
        $id = $this->leaderId($partyId);
        if ($id === null) {
            return 1;
        }
        $stmt = $this->db->prepare('SELECT level FROM characters WHERE id = ?');
        $stmt->execute([$id]);
        return max(1, (int) $stmt->fetchColumn());
    }
}
