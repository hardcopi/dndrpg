<?php
/**
 * Editing the world from inside the game.
 *
 * The database is the source of truth, so writes here are the real thing rather
 * than a staging area — an NPC saved here is the NPC the next player meets, and
 * ContentExporter is what later carries it back out to a file.
 *
 * Everything is validated against the same rules tools/load_content.py enforces
 * on import. That is the whole discipline of this class: an edit that saves but
 * could never be loaded back is worse than a rejected edit, because it survives
 * until the next export and then breaks the pipeline for content that has
 * nothing to do with it. Where the loader refuses something, so does this.
 *
 * Places are here now, and terrain is not, because terrain no longer exists:
 * the tile grid became a graph of locations joined by exits, and a location is
 * a paragraph of prose rather than a picture made of tiles. That is why there
 * is no walkability or occupancy check anywhere below — a location is a scene,
 * and any number of people may stand in one.
 */

declare(strict_types=1);

class ContentEditor
{
    /** Mirrors KEY_RE in tools/load_content.py. */
    private const KEY_RE = '/^[a-z0-9_]+$/';

    /** Mirrors POSES in tools/load_content.py, and the art on disk. */
    private const POSES = ['sleeping', 'kneel'];

    private const OUTCOMES = ['success', 'failure', 'neutral'];

    /**
     * Mirrors LOCATION_TYPES in tools/load_content.py. The type is not
     * decoration: it picks the glyph the region map draws for the node, and a
     * type the loader does not know is a location the map cannot draw.
     */
    private const LOCATION_TYPES = ['square', 'street', 'gate', 'building', 'room', 'site', 'camp'];

    private const REGION_TYPES = ['town', 'wilderness', 'dungeon'];

    private PDO $db;

    /** The world's flag read/write sets, gathered once per request. */
    private ?array $flagIndex = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =======================================================================
    // NPCs
    // =======================================================================

    /** Everything the editor's list needs, without the dialogue blobs. */
    public function listNpcs(?int $moduleId = null): array
    {
        $sql = "SELECT n.id, n.npc_key, n.name, n.role, n.sprite_key, n.pose,
                       n.is_merchant, n.is_quest_giver, n.is_ambient,
                       n.dialogue_json IS NOT NULL AND n.dialogue_json <> '' AS has_dialogue,
                       n.location_id, l.location_key, l.name AS location_name,
                       r.name AS region_name
                  FROM npcs n
                  LEFT JOIN locations l ON l.id = n.location_id
                  LEFT JOIN regions r ON r.id = l.region_id
                 WHERE n.npc_key IS NOT NULL";
        $args = [];
        if ($moduleId !== null) {
            $sql .= ' AND r.module_id = ?';
            $args[] = $moduleId;
        }
        $sql .= ' ORDER BY n.name';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function getNpc(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.*, l.location_key, l.name AS location_name, r.name AS region_name
               FROM npcs n
               LEFT JOIN locations l ON l.id = n.location_id
               LEFT JOIN regions r ON r.id = l.region_id
              WHERE n.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such NPC.');
        }
        return $row;
    }

    /**
     * Save an NPC's own fields. Placement and dialogue have their own routes.
     *
     * `npc_key` is not editable. Quests point at a giver by key, dialogue files
     * are named for it, and companions reference it — changing it here would
     * leave every one of those pointing at nothing, silently, because they are
     * resolved by subselect at import and a missing key becomes a NULL.
     */
    public function saveNpc(int $id, array $body): array
    {
        $npc = $this->getNpc($id);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('An NPC needs a name.');
        }
        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('That name is too long for the column (100).');
        }

        $pose = trim((string) ($body['pose'] ?? ''));
        if ($pose !== '' && !in_array($pose, self::POSES, true)) {
            throw new InvalidArgumentException(
                'A pose is one of: ' . implode(', ', self::POSES) . '.'
            );
        }

        $spriteKey = trim((string) ($body['sprite_key'] ?? ''));
        if ($spriteKey !== '') {
            $this->assertSpriteArt($spriteKey, $pose);
        }

        $this->db->prepare(
            'UPDATE npcs
                SET name = ?, role = ?, description = ?, sprite_key = ?, pose = ?,
                    is_merchant = ?, is_quest_giver = ?, is_ambient = ?
              WHERE id = ?'
        )->execute([
            $name,
            $this->nullIfBlank($body['role'] ?? null, 80),
            $this->nullIfBlank($body['description'] ?? null),
            $spriteKey !== '' ? $spriteKey : null,
            $pose !== '' ? $pose : null,
            !empty($body['is_merchant']) ? 1 : 0,
            !empty($body['is_quest_giver']) ? 1 : 0,
            !empty($body['is_ambient']) ? 1 : 0,
            $id,
        ]);

        return $this->getNpc($id);
    }

    /**
     * The art an NPC wears has to be on disk.
     *
     * check_sprite in tools/load_content.py treats a missing face or bust as a
     * hard error, so accepting one here would save an NPC that the next content
     * load refuses — and in the meantime they are a hole in the party rail, the
     * people list and the dialogue theatre, which are the three screens that
     * draw them.
     *
     * The walk sheet and the pose sheet are deliberately not asked for, and the
     * `$pose` argument is kept only so the signature still says what the caller
     * is saving. Nothing has drawn either since the world became a graph of
     * described scenes; the loader's check_sprite() carries the full account,
     * and the two must agree or this saves rows that the next load rejects.
     */
    private function assertSpriteArt(string $spriteKey, string $pose): void
    {
        if (!preg_match(self::KEY_RE, $spriteKey)) {
            throw new InvalidArgumentException(
                'A sprite key is lowercase letters, numbers and underscores.'
            );
        }
        $dir = APP_ROOT . '/assets/images/npcs/';
        foreach (['_face', '_bust'] as $suffix) {
            if (!is_file($dir . $spriteKey . $suffix . '.png')) {
                throw new InvalidArgumentException(
                    "No art at assets/images/npcs/{$spriteKey}{$suffix}.png."
                );
            }
        }
    }

    /**
     * Put an NPC in a location, or take them out of the world.
     *
     * One column on their own row, because a person stands in at most one
     * place. There is nothing to check beyond the location existing: a location
     * is a scene, not a square, so it has no capacity and nobody can block a
     * doorway by standing in it. Passing null takes them out entirely, which is
     * legitimate — a companion exists as a row before they are met, and
     * recruiting one subtracts them from the scene.
     */
    public function placeNpc(int $id, ?int $locationId): array
    {
        $this->getNpc($id);

        if ($locationId !== null) {
            $this->requireLocation($locationId);
        }
        $this->db->prepare('UPDATE npcs SET location_id = ? WHERE id = ?')
            ->execute([$locationId, $id]);

        return $this->getNpc($id);
    }

    // =======================================================================
    // Dialogue
    // =======================================================================

    public function getDialogue(int $npcId): array
    {
        $npc = $this->getNpc($npcId);
        $raw = (string) ($npc['dialogue_json'] ?? '');
        $doc = $raw === '' ? null : json_decode($raw, true);
        return [
            'npc_id'   => (int) $npc['id'],
            'npc_key'  => $npc['npc_key'],
            'name'     => $npc['name'],
            'dialogue' => is_array($doc) ? $doc : null,
        ];
    }

    /**
     * Replace an NPC's conversation.
     *
     * Stored as one JSON document on the NPC row rather than as a table, which
     * is why this can be a whole-document write: there is nothing else pointing
     * into it to leave dangling.
     *
     * Validation is structural, not exhaustive. It refuses a document the
     * dialogue engine would certainly choke on — a missing start node, a choice
     * pointing at a node that does not exist — and does not attempt to
     * re-implement the condition and effect vocabularies, which live in
     * tools/load_content.py and would be a second copy free to disagree. A
     * `null` document clears the conversation.
     */
    public function saveDialogue(int $npcId, $doc): array
    {
        $this->getNpc($npcId);

        if ($doc === null) {
            $this->db->prepare('UPDATE npcs SET dialogue_json = NULL WHERE id = ?')->execute([$npcId]);
            return $this->getDialogue($npcId);
        }
        if (!is_array($doc)) {
            throw new InvalidArgumentException('A conversation is a JSON object.');
        }

        $nodes = $doc['nodes'] ?? null;
        if (!is_array($nodes) || !$nodes) {
            throw new InvalidArgumentException('A conversation needs at least one node under "nodes".');
        }
        $start = (string) ($doc['start'] ?? '');
        if ($start === '') {
            throw new InvalidArgumentException('A conversation needs a "start" naming the first node.');
        }
        if (!array_key_exists($start, $nodes)) {
            throw new InvalidArgumentException("The start node \"{$start}\" is not in nodes.");
        }

        // Everything else is DialogueLint's, which is the same rule set
        // tools/load_content.py enforces on the files.
        //
        // This used to be a shorter list written out here — dangling jumps, key
        // spelling, a choice with no exit — chosen because the alternative was a
        // second copy of the loader free to disagree with it. That reasoning was
        // sound while dialogue was edited as raw JSON, where an unreachable node
        // takes deliberate effort to make. With a graph editor it takes one
        // click, so the gap between "the database accepts it" and "the next
        // content build refuses it" became somewhere real work could be lost.
        //
        // The findings are reported all at once rather than one throw at a time:
        // a document with four faults should say so, not send the author round
        // the loop four times.
        $findings = $this->dialogueLint($npcId)->check($doc, $this->spriteKeyFor($npcId));
        $errors = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['level'] === DialogueLint::ERROR
        ));
        if ($errors) {
            $lines = array_map(
                static fn (array $f): string => $f['where'] . ': ' . $f['message'],
                array_slice($errors, 0, 6)
            );
            $more = count($errors) - count($lines);
            throw new InvalidArgumentException(
                implode("\n", $lines) . ($more > 0 ? "\n…and {$more} more." : '')
            );
        }

        // The stored form drops npc_key — the row it is stored on already
        // answers that, and keeping it would let the two disagree.
        unset($doc['npc_key']);

        $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException('That conversation cannot be encoded: ' . json_last_error_msg());
        }
        $this->db->prepare('UPDATE npcs SET dialogue_json = ? WHERE id = ?')->execute([$json, $npcId]);

        return $this->getDialogue($npcId);
    }

    /**
     * A node is either one variant or a list of them.
     *
     * Both forms are authored — a node with conditions is a list of
     * alternatives, a plain one is a single object — and the import normalises
     * to a list. Validation has to cope with either, or editing a
     * hand-authored file would fail on the shape it was written in.
     *
     * @return array<int, array>
     */
    /**
     * Everything wrong with an NPC's conversation, plus the graph it describes.
     *
     * Returns the findings AND the edges, because the caller drawing a flowchart
     * needs both and working the edges out a second time in JavaScript would be
     * a second implementation of `jumpsFrom()` free to disagree about where a
     * conversation can go — which is the specific thing this whole class exists
     * to stop happening twice.
     *
     * `roots` is the part that is easy to get wrong by hand: `start` is only one
     * way in. The camp screen opens `camp` directly, an encounter can open a
     * parley node, and neither is named by any `next`.
     */
    public function lintDialogue(int $npcId): array
    {
        $npc = $this->getNpc($npcId);
        $doc = $this->getDialogue($npcId)['dialogue'];
        if (!is_array($doc)) {
            return ['npc_key' => $npc['npc_key'], 'nodes' => [], 'edges' => [],
                    'roots' => [], 'findings' => []];
        }

        $lint = $this->dialogueLint($npcId);
        $findings = $lint->check($doc, $this->spriteKeyFor($npcId));
        $nodes = is_array($doc['nodes'] ?? null) ? $doc['nodes'] : [];
        $start = (string) ($doc['start'] ?? '');

        $edges = [];
        foreach ($nodes as $key => $node) {
            foreach ($lint->jumpsFrom($node) as $to) {
                $edges[] = ['from' => (string) $key, 'to' => $to,
                            'dangling' => !isset($nodes[$to])];
            }
        }

        $roots = [];
        foreach (array_merge([$start], $lint->externalRootsIn($nodes)) as $r) {
            if ($r !== '' && isset($nodes[$r]) && !in_array($r, $roots, true)) {
                $roots[] = $r;
            }
        }

        // Everything the graph needs to draw a node without re-parsing the
        // document: how many variants, whether it ends the conversation, whether
        // it forks on a die, whether it rotates a pool.
        $summary = [];
        foreach ($nodes as $key => $node) {
            $variants = $lint->variantsOf($node);
            $choices = 0;
            $pools = [];
            $hasCheck = false;
            $terminal = true;
            foreach ($variants as $v) {
                foreach ($v['choices'] ?? [] as $c) {
                    $choices++;
                    if (!empty($c['check'])) {
                        $hasCheck = true;
                    }
                    if (isset($c['next']) || !empty($c['check'])) {
                        $terminal = false;
                    }
                }
                if (!empty($v['pool'])) {
                    $pools[(string) $v['pool']] = true;
                }
            }
            $first = $variants[0] ?? [];
            $summary[] = [
                'key'      => (string) $key,
                'text'     => mb_substr((string) ($first['text'] ?? ''), 0, 160),
                'variants' => count($variants),
                'choices'  => $choices,
                'terminal' => $terminal && $choices > 0,
                'check'    => $hasCheck,
                'pools'    => array_keys($pools),
            ];
        }

        return [
            'npc_key'  => $npc['npc_key'],
            'name'     => $npc['name'],
            'role'     => $npc['role'] ?? '',
            'start'    => $start,
            'roots'    => $roots,
            'nodes'    => $summary,
            'edges'    => $edges,
            'findings' => $findings,
        ] + $this->portraits($npc);
    }

    /**
     * The faces this NPC has, one per expression.
     *
     * Resolved here rather than in the browser for two reasons. The naming rule
     * is `DialogEngine::bust()` and belongs to it — the reader is meant to show
     * the portrait the player would see, and a second copy of the `_bust_N`
     * suffix in JavaScript is a copy that can drift. And existence is checked on
     * disk, because this vhost ends in `try_files … /index.php`, so a missing
     * image is served as the homepage with a 200: an `<img>` pointed at one gets
     * HTML, not a 404, and the browser finds out by failing to decode it. The
     * server already knows; it should answer.
     *
     * `busts` is positional — index 0 is expression 1 — with a null wherever the
     * art was never cut, so the caller can index by expression and fall back.
     *
     * @return array{busts: array<int, ?string>, face: ?string}
     */
    private function portraits(array $npc): array
    {
        $sprite = (string) ($npc['sprite_key'] ?? '');
        if ($sprite === '') {
            return ['busts' => [], 'face' => null];
        }

        $exists = static fn (string $rel): ?string
            => is_file(APP_ROOT . '/' . $rel) ? $rel : null;

        $busts = [];
        $count = max(1, (int) ($npc['bust_count'] ?? 1));
        for ($e = 1; $e <= $count; $e++) {
            $busts[] = $exists(DialogEngine::bust($npc, $e));
        }

        return ['busts' => $busts, 'face' => $exists("assets/images/npcs/{$sprite}_face.png")];
    }

    /**
     * A linter that knows this NPC's way in.
     *
     * Three things it cannot work out from the document alone. The parley nodes,
     * because an encounter names them as `"npc_key:node_key"` and they are a way
     * into the tree that no `next` has to mention — without them a parley scene
     * lints as unreachable. The bust counts, so an `expression` can be checked
     * against the art that was actually cut. And the world's flag wiring, which
     * is the whole of `flagIndex()` below.
     */
    private function dialogueLint(int $npcId): DialogueLint
    {
        $npc = $this->getNpc($npcId);
        $key = (string) ($npc['npc_key'] ?? '');

        $parley = [];
        if ($key !== '') {
            $stmt = $this->db->prepare(
                "SELECT parley_node FROM encounters
                  WHERE parley_node IS NOT NULL AND parley_node <> '' AND parley_node LIKE ?"
            );
            $stmt->execute([$key . ':%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                $bits = explode(':', (string) $ref, 2);
                if (count($bits) === 2 && $bits[1] !== '') {
                    $parley[] = $bits[1];
                }
            }
        }

        $busts = [];
        $file = APP_ROOT . '/assets/images/npcs/busts.json';
        if (is_file($file)) {
            $busts = json_decode((string) file_get_contents($file), true) ?: [];
        }

        $flags = $this->flagIndex();

        return new DialogueLint($parley, $busts, $flags['written'], $flags['read']);
    }

    /**
     * Every flag the world sets, and every flag the world reads.
     *
     * A flag is wired across content types, not within one, so this has to be
     * gathered from everywhere before either half of the question means
     * anything. The sites, and there are no others in the schema today:
     *
     *   written  `npcs.dialogue_json`        on_enter / a choice's effects
     *            `quest_stages.effects_json` applied when a stage is entered
     *            `encounters.victory_flag`   set by CombatEngine on the outcome
     *            `encounters.defeat_flag`
     *   read     `npcs.dialogue_json`        variant / choice / interjection
     *            `location_exits.conditions_json`     a locked way out
     *            `companions.recruit_conditions_json` what has to be true to join
     *
     * The bare JSON columns are wrapped in the key the walker branches on, so
     * one implementation of "find the flags in this" serves all of them rather
     * than each column growing its own.
     *
     * If a later schema adds a place that reads flags and it is not listed
     * here, the cost is a false "set here and nothing reads it" warning, not a
     * refused save — which is why DialogueLint warns rather than errors on
     * this. Add the column here and the warning goes away.
     *
     * Public because `tools/test_dialogue_lint.php` lints the shipped corpus
     * with the same index the editor uses, and the only other way to give it
     * one would be a second copy of this list of columns — which would pass
     * the test by agreeing with itself.
     *
     * @return array{written: string[], read: string[]}
     */
    public function flagIndex(): array
    {
        if ($this->flagIndex !== null) {
            return $this->flagIndex;
        }

        $written = [];
        $read = [];
        $decode = static function ($json) {
            $v = json_decode((string) $json, true);
            return is_array($v) ? $v : null;
        };

        $rows = $this->db->query(
            'SELECT dialogue_json FROM npcs WHERE dialogue_json IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $json) {
            $doc = $decode($json);
            if ($doc === null) {
                continue;
            }
            foreach (DialogueLint::flagsWrittenIn($doc) as $f) {
                $written[$f] = true;
            }
            foreach (DialogueLint::flagsReadIn($doc) as $f) {
                $read[$f] = true;
            }
        }

        $stages = $this->db->query(
            'SELECT effects_json FROM quest_stages WHERE effects_json IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($stages as $json) {
            $eff = $decode($json);
            if ($eff === null) {
                continue;
            }
            foreach (DialogueLint::flagsWrittenIn(['effects' => $eff]) as $f) {
                $written[$f] = true;
            }
        }

        $outcomes = $this->db->query(
            "SELECT victory_flag, defeat_flag FROM encounters"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($outcomes as $row) {
            foreach (['victory_flag', 'defeat_flag'] as $col) {
                $f = (string) ($row[$col] ?? '');
                if ($f !== '') {
                    $written[$f] = true;
                }
            }
        }

        $gates = array_merge(
            $this->db->query(
                'SELECT conditions_json FROM location_exits WHERE conditions_json IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN),
            $this->db->query(
                'SELECT recruit_conditions_json FROM companions
                  WHERE recruit_conditions_json IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
        foreach ($gates as $json) {
            $cond = $decode($json);
            if ($cond === null) {
                continue;
            }
            foreach (DialogueLint::flagsReadIn(['conditions' => $cond]) as $f) {
                $read[$f] = true;
            }
        }

        $this->flagIndex = [
            'written' => array_keys($written),
            'read' => array_keys($read),
        ];
        return $this->flagIndex;
    }

    /** The sprite an NPC wears, or null — the expression bound needs it. */
    private function spriteKeyFor(int $npcId): ?string
    {
        $key = (string) ($this->getNpc($npcId)['sprite_key'] ?? '');
        return $key === '' ? null : $key;
    }

    private function variantsOf($node): array
    {
        if (!is_array($node)) {
            return [];
        }
        // A list of variants is a list; a single variant is a map with keys
        // like `text` and `choices`.
        return array_is_list($node) ? array_filter($node, 'is_array') : [$node];
    }

    // =======================================================================
    // Quests
    // =======================================================================

    public function listQuests(?int $moduleId = null): array
    {
        $sql = 'SELECT q.id, q.quest_key, q.title, q.act, q.on_job_board, q.required_level,
                       q.companion_key, q.is_active, q.giver_npc_id,
                       (SELECT COUNT(*) FROM quest_stages s WHERE s.quest_id = q.id) AS stages
                  FROM quests q
                 WHERE q.quest_key IS NOT NULL';
        $args = [];
        if ($moduleId !== null) {
            $sql .= ' AND q.giver_npc_id IN (
                        SELECT n.id FROM npcs n
                        INNER JOIN locations l ON l.id = n.location_id
                        INNER JOIN regions r ON r.id = l.region_id
                       WHERE r.module_id = ?)';
            $args[] = $moduleId;
        }
        $sql .= ' ORDER BY q.act, q.title';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function getQuest(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT q.*, g.npc_key AS giver_key, ri.item_key AS reward_item_key
               FROM quests q
               LEFT JOIN npcs g ON g.id = q.giver_npc_id
               LEFT JOIN items ri ON ri.id = q.reward_item_id
              WHERE q.id = ?'
        );
        $stmt->execute([$id]);
        $quest = $stmt->fetch();
        if (!$quest) {
            throw new InvalidArgumentException('No such quest.');
        }

        $stages = $this->db->prepare(
            'SELECT s.*, l.location_key AS target_location_key,
                    l.name AS target_location_name
               FROM quest_stages s
               LEFT JOIN locations l ON l.id = s.target_location_id
              WHERE s.quest_id = ? ORDER BY s.sort_order, s.id'
        );
        $stages->execute([$id]);
        $quest['stages'] = $stages->fetchAll();
        return $quest;
    }

    /** The quest's own fields. Stages are saved one at a time. */
    public function saveQuest(int $id, array $body): array
    {
        $this->getQuest($id);

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('A quest needs a title.');
        }
        $act = (int) ($body['act'] ?? 1);
        if ($act < 1 || $act > 5) {
            throw new InvalidArgumentException('An act is between 1 and 5.');
        }
        $level = (int) ($body['required_level'] ?? 1);
        if ($level < 1 || $level > Rules::MAX_LEVEL) {
            throw new InvalidArgumentException(
                'A required level is between 1 and ' . Rules::MAX_LEVEL . '.'
            );
        }

        $this->db->prepare(
            'UPDATE quests
                SET title = ?, description = ?, act = ?, on_job_board = ?,
                    required_level = ?, is_active = ?
              WHERE id = ?'
        )->execute([
            $title,
            $this->nullIfBlank($body['description'] ?? null),
            $act,
            !empty($body['on_job_board']) ? 1 : 0,
            $level,
            isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : 1,
            $id,
        ]);

        return $this->getQuest($id);
    }

    /**
     * Save one stage.
     *
     * The terminal check is the one worth having: load_content.py refuses a
     * quest with no terminal stage — it can be started and never ended — so
     * clearing the last one is caught here rather than at the next import, by
     * which time the quest is live and somebody is stuck in it.
     */
    public function saveStage(int $stageId, array $body): array
    {
        $stmt = $this->db->prepare('SELECT * FROM quest_stages WHERE id = ?');
        $stmt->execute([$stageId]);
        $stage = $stmt->fetch();
        if (!$stage) {
            throw new InvalidArgumentException('No such stage.');
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('A stage needs a title.');
        }

        $terminal = !empty($body['is_terminal']);
        $outcome = (string) ($body['outcome'] ?? 'success');
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('An outcome is success, failure or neutral.');
        }

        // The tracker marker: which location the quest points at while this
        // stage is current. One field now rather than a map and two
        // coordinates — the tracker names the place and routes to it over the
        // exit graph, so there is nothing finer than a location to point at.
        $targetLocation = isset($body['target_location_id'])
            && $body['target_location_id'] !== null && $body['target_location_id'] !== ''
            ? (int) $body['target_location_id'] : null;
        if ($targetLocation !== null) {
            $this->requireLocation($targetLocation);
        }

        if (!$terminal && (int) $stage['is_terminal'] === 1) {
            $others = $this->db->prepare(
                'SELECT COUNT(*) FROM quest_stages WHERE quest_id = ? AND is_terminal = 1 AND id <> ?'
            );
            $others->execute([(int) $stage['quest_id'], $stageId]);
            if ((int) $others->fetchColumn() === 0) {
                throw new InvalidArgumentException(
                    'That is the quest\'s only ending. Mark another stage terminal first, '
                    . 'or the quest can be started and never finished.'
                );
            }
        }

        $this->db->prepare(
            'UPDATE quest_stages
                SET title = ?, objective = ?, journal_entry = ?, is_terminal = ?,
                    resolution = ?, outcome = ?, target_location_id = ?
              WHERE id = ?'
        )->execute([
            $title,
            $this->nullIfBlank($body['objective'] ?? null),
            $this->nullIfBlank($body['journal_entry'] ?? null),
            $terminal ? 1 : 0,
            $this->nullIfBlank($body['resolution'] ?? null, 60),
            $outcome,
            $targetLocation,
            $stageId,
        ]);

        return $this->getQuest((int) $stage['quest_id']);
    }

    // =======================================================================
    // Places
    // =======================================================================

    /**
     * The world as the editor's list shows it: regions, each with its
     * locations, each with what stands to be lost if it goes.
     *
     * Two queries and a regroup rather than one per region — three regions is
     * cheap either way, but the shape of the code is what somebody copies the
     * next time a kind of content grows a list, and a query in a loop is not
     * the shape to leave lying about.
     */
    public function listRegions(): array
    {
        // Regions are not editable here — locations are, and a new one inherits
        // its region's module — so the module comes back as a label rather than
        // as something to change. It is worth showing even so: which game a
        // region belongs to is the first thing you need to know before editing
        // a scene in it, and it is otherwise invisible from this screen.
        $regions = $this->db->query(
            'SELECT r.id, r.region_key, r.name, r.description, r.region_type,
                    r.sort_order, m.module_key, m.name AS module_name
               FROM regions r
               LEFT JOIN modules m ON m.id = r.module_id
              ORDER BY m.sort_order, r.sort_order, r.name'
        )->fetchAll();

        $locations = $this->db->query(
            'SELECT l.id, l.region_id, l.location_key, l.name, l.location_type,
                    l.inn_cost, l.allow_camp, l.hidden_until_visited,
                    (SELECT COUNT(*) FROM npcs n WHERE n.location_id = l.id) AS npcs,
                    (SELECT COUNT(*) FROM location_exits e WHERE e.from_location_id = l.id) AS exits
               FROM locations l
              ORDER BY l.sort_order, l.id'
        )->fetchAll();

        $byRegion = [];
        foreach ($locations as $l) {
            $byRegion[(int) $l['region_id']][] = $l;
        }
        foreach ($regions as &$r) {
            $r['locations'] = $byRegion[(int) $r['id']] ?? [];
        }
        return $regions;
    }

    /**
     * One location, with everything that points at it.
     *
     * The people and the ground loot are read-only here — a person is placed
     * from their own screen, and items are file-authored — but they are the
     * answer to "what happens if I change this", which is the question somebody
     * editing a scene is actually asking.
     */
    public function getLocation(int $id): array
    {
        $location = $this->requireLocation($id);

        $exits = $this->db->prepare(
            'SELECT e.id, e.to_location_id, e.label, e.conditions_json, e.is_hidden,
                    e.sort_order, t.location_key AS to_location_key, t.name AS to_name
               FROM location_exits e
               INNER JOIN locations t ON t.id = e.to_location_id
              WHERE e.from_location_id = ?
              ORDER BY e.sort_order, e.id'
        );
        $exits->execute([$id]);
        $location['exits'] = $exits->fetchAll();

        $npcs = $this->db->prepare(
            'SELECT id, npc_key, name, role, is_ambient FROM npcs
              WHERE location_id = ? ORDER BY is_ambient, name'
        );
        $npcs->execute([$id]);
        $location['npcs'] = $npcs->fetchAll();

        $items = $this->db->prepare(
            'SELECT li.id, i.item_key, i.name
               FROM location_items li
               INNER JOIN items i ON i.id = li.item_id
              WHERE li.location_id = ? ORDER BY i.name'
        );
        $items->execute([$id]);
        $location['items'] = $items->fetchAll();

        $enc = $this->db->prepare(
            'SELECT e.id, e.encounter_key, e.name, e.is_ambush, e.difficulty
               FROM encounters e
              WHERE e.location_id = ? AND e.is_random = 0
              ORDER BY e.id'
        );
        $enc->execute([$id]);
        $location['encounters'] = $enc->fetchAll();

        return $location;
    }

    /**
     * Create or rewrite a location.
     *
     * Identified by `id` when the caller has one and by `location_key`
     * otherwise, so the editor can save a form it has just filled in from
     * nothing. The key is editable, unlike an NPC's: every reference to a
     * location inside the database is by id, so renaming one repoints nothing —
     * the key only has to stay unique, and the next export writes the new name
     * into the files that mention it.
     */
    public function saveLocation(array $data): array
    {
        $key = trim((string) ($data['location_key'] ?? ''));
        if (!preg_match(self::KEY_RE, $key)) {
            throw new InvalidArgumentException(
                'A location key is lowercase letters, numbers and underscores.'
            );
        }
        if (mb_strlen($key) > 64) {
            throw new InvalidArgumentException('That key is too long for the column (64).');
        }

        $id = isset($data['id']) && $data['id'] !== null && $data['id'] !== ''
            ? (int) $data['id'] : null;

        $clash = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $clash->execute([$key]);
        $found = $clash->fetchColumn();
        if ($found !== false && $id === null) {
            $id = (int) $found;   // saving the form of a location we already have
        } elseif ($found !== false && (int) $found !== $id) {
            throw new InvalidArgumentException(
                "There is already a location keyed {$key}; keys are global, not per region."
            );
        }
        if ($id !== null) {
            $this->requireLocation($id);
        }

        $regionId = (int) ($data['region_id'] ?? 0);
        $region = $this->db->prepare('SELECT id FROM regions WHERE id = ?');
        $region->execute([$regionId]);
        if (!$region->fetchColumn()) {
            throw new InvalidArgumentException('No such region.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A location needs a name.');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('That name is too long for the column (120).');
        }

        // NOT NULL in the schema, and the description is the whole scene — a
        // location without one renders as an empty room with exits.
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('A location needs a description — it is what the player reads.');
        }

        $type = trim((string) ($data['location_type'] ?? ''));
        if (!in_array($type, self::LOCATION_TYPES, true)) {
            throw new InvalidArgumentException(
                'A location type is one of: ' . implode(', ', self::LOCATION_TYPES) . '.'
            );
        }

        // Percent positions on the region's map drawing, not tile coordinates.
        $mapX = $this->percent($data['map_x'] ?? null, 'map_x');
        $mapY = $this->percent($data['map_y'] ?? null, 'map_y');

        $innCost = null;
        if (isset($data['inn_cost']) && $data['inn_cost'] !== null && $data['inn_cost'] !== '') {
            $innCost = (int) $data['inn_cost'];
            if ($innCost < 0) {
                throw new InvalidArgumentException('A room cannot cost less than nothing.');
            }
        }

        $pct = (int) ($data['random_encounter_pct'] ?? 0);
        if ($pct < 0 || $pct > 100) {
            throw new InvalidArgumentException(
                'A random encounter chance is a percentage between 0 and 100.'
            );
        }

        // Stored as a JSON array of strings; one is picked per render. Anything
        // else here is a location the loader refuses and the renderer trips on.
        $ambience = $data['ambience'] ?? null;
        if ($ambience !== null && !is_array($ambience)) {
            throw new InvalidArgumentException('Ambience is a list of lines.');
        }
        if (is_array($ambience)) {
            $lines = [];
            foreach ($ambience as $line) {
                if (!is_string($line)) {
                    throw new InvalidArgumentException('Every ambience line is a line of text.');
                }
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
            $ambience = $lines;
        }
        $ambienceJson = $ambience ? json_encode(
            array_values($ambience),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) : null;

        $allowCamp = !empty($data['allow_camp']) ? 1 : 0;
        $hidden = !empty($data['hidden_until_visited']) ? 1 : 0;

        if ($id === null) {
            // Authored order is what the exporter writes and what the loader
            // reads back as sort_order; a new location goes on the end of its
            // region rather than into the middle of somebody's sequence.
            $next = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM locations WHERE region_id = ?'
            );
            $next->execute([$regionId]);
            $this->db->prepare(
                'INSERT INTO locations
                     (location_key, region_id, name, description, first_visit_text,
                      ambience_json, location_type, map_x, map_y, inn_cost,
                      allow_camp, random_encounter_pct, hidden_until_visited, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $key, $regionId, $name, $description,
                $this->nullIfBlank($data['first_visit_text'] ?? null),
                $ambienceJson, $type, $mapX, $mapY, $innCost,
                $allowCamp, $pct, $hidden, (int) $next->fetchColumn(),
            ]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->prepare(
                'UPDATE locations
                    SET location_key = ?, region_id = ?, name = ?, description = ?,
                        first_visit_text = ?, ambience_json = ?, location_type = ?,
                        map_x = ?, map_y = ?, inn_cost = ?, allow_camp = ?,
                        random_encounter_pct = ?, hidden_until_visited = ?
                  WHERE id = ?'
            )->execute([
                $key, $regionId, $name, $description,
                $this->nullIfBlank($data['first_visit_text'] ?? null),
                $ambienceJson, $type, $mapX, $mapY, $innCost,
                $allowCamp, $pct, $hidden, $id,
            ]);
        }

        return $this->getLocation($id);
    }

    /**
     * Move a node on the chart. The plan is the source for a dungeon;
     * this is only for a town or a wilderness, where map_x / map_y are
     * authored by hand.
     */
    public function savePosition(int $id, float $x, float $y): array
    {
        $this->requireLocation($id);
        $this->db->prepare('UPDATE locations SET map_x = ?, map_y = ? WHERE id = ?')
            ->execute([$this->percent($x, 'map_x'), $this->percent($y, 'map_y'), $id]);
        return $this->getLocation($id);
    }

    /**
     * Create or rewrite one way out of a location.
     *
     * Exits are directed and unique on (from, to), which is the pair that
     * identifies one when no id is given. A two-way passage is two exits — that
     * is deliberate in the schema, because the label differs in each direction
     * and one of the two may be hidden.
     */
    public function saveExit(array $data): array
    {
        $from = (int) ($data['from_location_id'] ?? 0);
        $to = (int) ($data['to_location_id'] ?? 0);
        $this->requireLocation($from);
        $this->requireLocation($to);
        if ($from === $to) {
            throw new InvalidArgumentException('An exit has to lead somewhere else.');
        }
        $this->assertSameModule($from, $to);

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('An exit needs a label — it is the words the player clicks.');
        }
        if (mb_strlen($label) > 160) {
            throw new InvalidArgumentException('That label is too long for the column (160).');
        }

        // Same Requirements vocabulary dialogue uses. Only the syntax is
        // checked here; the vocabulary lives in tools/load_content.py and a
        // second copy of it would be free to disagree with the first.
        $conditions = $data['conditions_json'] ?? $data['conditions'] ?? null;
        $conditionsJson = null;
        if (is_array($conditions)) {
            $conditionsJson = $conditions
                ? json_encode($conditions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
        } elseif (is_string($conditions) && trim($conditions) !== '') {
            $decoded = json_decode($conditions, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Those conditions are not valid JSON.');
            }
            $conditionsJson = $decoded
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
        }

        $hidden = !empty($data['is_hidden']) ? 1 : 0;

        $id = isset($data['id']) && $data['id'] !== null && $data['id'] !== ''
            ? (int) $data['id'] : null;

        $existing = $this->db->prepare(
            'SELECT id FROM location_exits WHERE from_location_id = ? AND to_location_id = ?'
        );
        $existing->execute([$from, $to]);
        $found = $existing->fetchColumn();
        if ($found !== false && ($id === null || (int) $found !== $id)) {
            // Repointing an exit at a destination that already has one would
            // violate uq_exit; saying so beats a driver-level duplicate-key.
            if ($id !== null) {
                throw new InvalidArgumentException('There is already a way from here to there.');
            }
            $id = (int) $found;
        }

        if ($id === null) {
            $next = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM location_exits WHERE from_location_id = ?'
            );
            $next->execute([$from]);
            $this->db->prepare(
                'INSERT INTO location_exits
                     (from_location_id, to_location_id, label, conditions_json, is_hidden, sort_order)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$from, $to, $label, $conditionsJson, $hidden, (int) $next->fetchColumn()]);
        } else {
            $this->db->prepare(
                'UPDATE location_exits
                    SET from_location_id = ?, to_location_id = ?, label = ?,
                        conditions_json = ?, is_hidden = ?
                  WHERE id = ?'
            )->execute([$from, $to, $label, $conditionsJson, $hidden, $id]);
        }

        return $this->getLocation($from);
    }

    public function deleteExit(int $id): array
    {
        $stmt = $this->db->prepare('SELECT from_location_id FROM location_exits WHERE id = ?');
        $stmt->execute([$id]);
        $from = $stmt->fetchColumn();
        if ($from === false) {
            throw new InvalidArgumentException('No such exit.');
        }
        $this->db->prepare('DELETE FROM location_exits WHERE id = ?')->execute([$id]);
        return $this->getLocation((int) $from);
    }

    /**
     * Remove a location, unless something still stands in it.
     *
     * This is the check that stops an editor quietly stranding the cast. The
     * foreign keys would allow most of it — npcs.location_id and the quest
     * targets are ON DELETE SET NULL — so deleting a busy location does not
     * fail, it succeeds and silently takes four people off the map, unpoints
     * two quests and leaves a party standing nowhere. What it refuses is named,
     * so the answer to "why not" is on screen rather than in a log.
     */
    public function deleteLocation(int $id): array
    {
        $location = $this->requireLocation($id);

        $blockers = [
            'npcs'         => 'SELECT name FROM npcs WHERE location_id = ? ORDER BY name',
            'encounters'   => 'SELECT name FROM encounters WHERE location_id = ? ORDER BY name',
            'quest stages' => 'SELECT s.title FROM quest_stages s WHERE s.target_location_id = ? ORDER BY s.title',
            'quests'       => 'SELECT title FROM quests WHERE target_location_id = ? ORDER BY title',
            'companions'   => 'SELECT name FROM companions WHERE recruit_location_id = ? ORDER BY name',
            'characters'   => 'SELECT name FROM characters WHERE current_location_id = ? ORDER BY name',
            'ground loot'  => 'SELECT i.name FROM location_items li
                                 INNER JOIN items i ON i.id = li.item_id
                                WHERE li.location_id = ? ORDER BY i.name',
        ];

        $held = [];
        foreach ($blockers as $what => $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($names) {
                $shown = array_slice($names, 0, 4);
                $held[] = $what . ': ' . implode(', ', $shown)
                    . (count($names) > count($shown) ? ' and ' . (count($names) - count($shown)) . ' more' : '');
            }
        }
        if ($held) {
            throw new InvalidArgumentException(
                "{$location['name']} is still in use — " . implode('; ', $held)
                . '. Move those first.'
            );
        }

        // The exits are the location's own, in both directions; they exist only
        // to join it to the graph and mean nothing without it, so they go with
        // it rather than being something to clear by hand first. The FKs on
        // location_exits cascade, so this is the schema's own answer.
        $this->db->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);

        return ['deleted' => true, 'location_key' => $location['location_key']];
    }

    // =======================================================================
    // Studio creates — a blank page, not a revision of one that exists
    // =======================================================================

    /**
     * Every module the picker and the studio can name.
     *
     * Inactive modules stay on this list: the studio has to be able to open
     * a draft the public shelf will not show.
     */
    public function listModules(): array
    {
        return $this->db->query(
            'SELECT m.id, m.module_key, m.name, m.blurb, m.start_location_key,
                    m.level_min, m.level_max, m.attribution, m.is_active, m.sort_order,
                    (SELECT COUNT(*) FROM regions r WHERE r.module_id = m.id) AS regions
               FROM modules m
              ORDER BY m.sort_order, m.name'
        )->fetchAll();
    }

    public function getModule(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM modules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such module.');
        }
        return $row;
    }

    /**
     * The module's first region as the chart payload WorldMap.svg already draws.
     *
     * Every node is visited: this is the GM's copy, not a party's fog. The
     * start room is marked current so the chart has a "here" to sit on.
     */
    public function moduleChart(int $moduleId, ?int $hereId = null): array
    {
        $module = $this->getModule($moduleId);
        $stmt = $this->db->prepare(
            'SELECT * FROM regions WHERE module_id = ? ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute([$moduleId]);
        $region = $stmt->fetch();
        if (!$region) {
            return ['region_id' => null, 'region_key' => null, 'name' => $module['name'],
                    'region_type' => 'dungeon', 'nodes' => [], 'edges' => [],
                    'neighbors' => [], 'floorplan' => null];
        }

        $locs = $this->db->prepare(
            'SELECT id, location_key, name, location_type, map_x, map_y
               FROM locations WHERE region_id = ? ORDER BY sort_order, id'
        );
        $locs->execute([(int) $region['id']]);
        $rows = $locs->fetchAll();

        $startKey = (string) $module['start_location_key'];
        $startId = null;
        foreach ($rows as $l) {
            if ($l['location_key'] === $startKey) {
                $startId = (int) $l['id'];
                break;
            }
        }
        if ($hereId === null) {
            $hereId = $startId;
        }

        $nodes = [];
        $shown = [];
        foreach ($rows as $l) {
            $id = (int) $l['id'];
            $shown[$id] = true;
            $nodes[] = [
                'id'      => $id,
                'key'     => $l['location_key'],
                'name'    => $l['name'],
                'type'    => $l['location_type'],
                'x'       => (float) $l['map_x'],
                'y'       => (float) $l['map_y'],
                'visited' => true,
                'current' => $hereId !== null && $id === $hereId,
            ];
        }

        $exits = $this->db->prepare(
            'SELECT e.from_location_id, e.to_location_id
               FROM location_exits e
               INNER JOIN locations l1 ON l1.id = e.from_location_id
               INNER JOIN locations l2 ON l2.id = e.to_location_id
              WHERE l1.region_id = ? AND l2.region_id = ?'
        );
        $exits->execute([(int) $region['id'], (int) $region['id']]);
        $edges = [];
        $seen = [];
        foreach ($exits->fetchAll() as $e) {
            $a = (int) $e['from_location_id'];
            $b = (int) $e['to_location_id'];
            if (!isset($shown[$a], $shown[$b])) {
                continue;
            }
            $pair = min($a, $b) . ':' . max($a, $b);
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $edges[] = ['from' => $a, 'to' => $b];
        }

        $floorplan = null;
        if (!empty($region['plan_json'])) {
            $raw = json_decode((string) $region['plan_json'], true);
            if (is_array($raw)) {
                try {
                    $compiled = PlanAuthored::compile($raw);
                    $floorplan = $this->bindPlan($compiled['plan'], $compiled['level'], $shown);
                } catch (InvalidArgumentException $e) {
                    $floorplan = null;
                }
            }
        }

        return [
            'region_id'   => (int) $region['id'],
            'region_key'  => $region['region_key'],
            'name'        => $region['name'],
            'region_type' => $region['region_type'],
            'nodes'       => $nodes,
            'edges'       => $edges,
            'neighbors'   => [],
            'floorplan'   => $floorplan,
            'plan'        => !empty($region['plan_json'])
                ? json_decode((string) $region['plan_json'], true) : null,
        ];
    }

    /**
     * Save an authored dungeon plan and compile it into locations and exits.
     *
     * The plan is the source. Room rows are created or updated by key; halls
     * become a pair of exits. A hall the router cannot run is refused rather
     * than stored as a door that the walker will not see.
     */
    public function savePlan(int $regionId, array $plan): array
    {
        $region = $this->requireRegion($regionId);
        if ($region['region_type'] !== 'dungeon') {
            throw new InvalidArgumentException(
                'A plan belongs on a dungeon, not a ' . $region['region_type'] . '.'
            );
        }
        $plan = PlanAuthored::normalize($plan);
        $compiled = PlanAuthored::compile($plan);
        if ($compiled['dropped'] > 0) {
            throw new InvalidArgumentException(
                'A hall could not be routed between two rooms. Leave a cell of '
                . 'rock between them, or move them so a passage can run.'
            );
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE regions SET plan_json = ? WHERE id = ?')
                ->execute([
                    json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $regionId,
                ]);

            $oldKeyById = [];
            if (!empty($region['plan_json'])) {
                $prev = json_decode((string) $region['plan_json'], true);
                if (is_array($prev)) {
                    foreach ($prev['rooms'] ?? [] as $old) {
                        if (is_array($old) && isset($old['id'], $old['key'])) {
                            $oldKeyById[(int) $old['id']] = (string) $old['key'];
                        }
                    }
                }
            }

            $idByPlan = [];
            foreach ($compiled['level']['rooms'] as $r) {
                [$mx, $my] = DungeonGen::project(
                    $r['gx'] + $r['w'] / 2,
                    $r['gy'] + $r['h'] / 2
                );
                $newKey = (string) $r['key'];
                $oldKey = $oldKeyById[(int) $r['id']] ?? null;
                if ($oldKey !== null && $oldKey !== $newKey) {
                    $prevRow = $this->db->prepare(
                        'SELECT id FROM locations WHERE location_key = ?'
                    );
                    $prevRow->execute([$oldKey]);
                    $prevId = $prevRow->fetchColumn();
                    if ($prevId !== false) {
                        $taken = $this->db->prepare(
                            'SELECT id FROM locations WHERE location_key = ?'
                        );
                        $taken->execute([$newKey]);
                        $takenId = $taken->fetchColumn();
                        if ($takenId !== false && (int) $takenId !== (int) $prevId) {
                            throw new InvalidArgumentException(
                                "There is already a location keyed {$newKey}."
                            );
                        }
                        $this->db->prepare(
                            'UPDATE locations SET location_key = ? WHERE id = ?'
                        )->execute([$newKey, (int) $prevId]);
                    }
                }
                $existing = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
                $existing->execute([$newKey]);
                $locId = $existing->fetchColumn();
                if ($locId === false) {
                    $saved = $this->saveLocation([
                        'location_key'  => $newKey,
                        'region_id'     => $regionId,
                        'name'          => $r['name'],
                        'location_type' => 'room',
                        'description'   => 'A chamber. Nobody has written what is in it yet.',
                        'map_x'         => $mx,
                        'map_y'         => $my,
                    ]);
                    $locId = (int) $saved['id'];
                } else {
                    $this->db->prepare(
                        'UPDATE locations SET name = ?, map_x = ?, map_y = ?, region_id = ?
                          WHERE id = ?'
                    )->execute([$r['name'], $mx, $my, $regionId, (int) $locId]);
                }
                $idByPlan[(int) $r['id']] = (int) $locId;
            }

            $keepPairs = [];
            foreach ($compiled['level']['corridors'] as $c) {
                $from = $idByPlan[(int) $c['a']] ?? null;
                $to = $idByPlan[(int) $c['b']] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                $hidden = !empty($c['hidden']);
                $this->saveExit([
                    'from_location_id' => $from,
                    'to_location_id'   => $to,
                    'label'            => 'On to ' . $this->roomName($compiled['level']['rooms'], (int) $c['b']),
                    'is_hidden'        => $hidden,
                ]);
                $this->saveExit([
                    'from_location_id' => $to,
                    'to_location_id'   => $from,
                    'label'            => 'Back to ' . $this->roomName($compiled['level']['rooms'], (int) $c['a']),
                    'is_hidden'        => $hidden,
                ]);
                $keepPairs[min($from, $to) . ':' . max($from, $to)] = true;
            }

            $this->prunePlanExits($regionId, $idByPlan, $keepPairs);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'plan'  => $plan,
            'chart' => $this->moduleChart((int) $region['module_id']),
        ];
    }

    /** @param list<array{id:int,name:string}> $rooms */
    private function roomName(array $rooms, int $id): string
    {
        foreach ($rooms as $r) {
            if ((int) $r['id'] === $id) {
                return (string) $r['name'];
            }
        }
        return 'the next room';
    }

    /**
     * Drop exits between plan rooms that no longer have a hall, without
     * touching exits that leave the region.
     *
     * @param array<int,int> $idByPlan
     * @param array<string,true> $keepPairs
     */
    private function prunePlanExits(int $regionId, array $idByPlan, array $keepPairs): void
    {
        $planLocs = array_flip($idByPlan);
        $stmt = $this->db->prepare(
            'SELECT e.id, e.from_location_id, e.to_location_id
               FROM location_exits e
               INNER JOIN locations a ON a.id = e.from_location_id
               INNER JOIN locations b ON b.id = e.to_location_id
              WHERE a.region_id = ? AND b.region_id = ?'
        );
        $stmt->execute([$regionId, $regionId]);
        foreach ($stmt->fetchAll() as $e) {
            $a = (int) $e['from_location_id'];
            $b = (int) $e['to_location_id'];
            if (!isset($planLocs[$a], $planLocs[$b])) {
                continue;
            }
            $pair = min($a, $b) . ':' . max($a, $b);
            if (!isset($keepPairs[$pair])) {
                $this->db->prepare('DELETE FROM location_exits WHERE id = ?')
                    ->execute([(int) $e['id']]);
            }
        }
    }

    /**
     * Attach location ids to a compiled plan so the chart can match rooms
     * and halls against the nodes it already has.
     *
     * @param array<int,true> $shown location id => true
     */
    private function bindPlan(array $drawn, array $level, array $shown): array
    {
        $keyOf = [];
        foreach ($level['rooms'] as $r) {
            $keyOf[(int) $r['id']] = (string) $r['key'];
        }
        $idOf = [];
        if ($keyOf) {
            $keys = array_values($keyOf);
            $in = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, location_key FROM locations WHERE location_key IN ({$in})"
            );
            $stmt->execute($keys);
            foreach ($stmt->fetchAll() as $row) {
                $idOf[(string) $row['location_key']] = (int) $row['id'];
            }
        }
        foreach ($drawn['rooms'] as $i => $r) {
            $key = $keyOf[(int) $r['id']] ?? '';
            $lid = $idOf[$key] ?? null;
            $drawn['rooms'][$i]['location_id'] = $lid;
            $drawn['rooms'][$i]['seen'] = $lid !== null && isset($shown[$lid]);
        }
        foreach ($drawn['corridors'] as $i => $c) {
            $drawn['corridors'][$i]['seen'] = true;
        }
        return $drawn;
    }

    /**
     * A new game: the module row, one region, and the room a party wakes in.
     *
     * Inactive until somebody turns it on — a draft on the public shelf is a
     * hole people walk into. The start location's key is stored on the module
     * as a key, not an id, which is the same rule the loader uses.
     */
    public function createModule(array $body): array
    {
        $key = $this->requireKey($body['module_key'] ?? '', 'module');
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A module needs a name.');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('That name is too long for the column (120).');
        }

        $clash = $this->db->prepare('SELECT id FROM modules WHERE module_key = ?');
        $clash->execute([$key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a module keyed {$key}.");
        }

        $startKey = trim((string) ($body['start_location_key'] ?? ''));
        $startKey = $startKey !== '' ? $this->requireKey($startKey, 'location') : $key . '_door';
        $here = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $here->execute([$startKey]);
        if ($here->fetchColumn() !== false) {
            throw new InvalidArgumentException(
                "The start location {$startKey} already exists; pick another key."
            );
        }

        $regionKey = trim((string) ($body['region_key'] ?? ''));
        $regionKey = $regionKey !== '' ? $this->requireKey($regionKey, 'region') : $key . '_region';
        $regionClash = $this->db->prepare('SELECT id FROM regions WHERE region_key = ?');
        $regionClash->execute([$regionKey]);
        if ($regionClash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a region keyed {$regionKey}.");
        }

        $levelMin = (int) ($body['level_min'] ?? 1);
        $levelMax = (int) ($body['level_max'] ?? 6);
        if ($levelMin < 1 || $levelMax < $levelMin || $levelMax > Rules::MAX_LEVEL) {
            throw new InvalidArgumentException(
                'A level band is from 1 to ' . Rules::MAX_LEVEL . ', min no higher than max.'
            );
        }

        $regionType = trim((string) ($body['region_type'] ?? 'dungeon'));
        if (!in_array($regionType, self::REGION_TYPES, true)) {
            throw new InvalidArgumentException(
                'A region type is one of: ' . implode(', ', self::REGION_TYPES) . '.'
            );
        }

        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM modules')
            ->fetchColumn();

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO modules
                     (module_key, name, blurb, start_location_key, level_min, level_max,
                      attribution, is_active, sort_order)
                 VALUES (?,?,?,?,?,?,?,0,?)'
            )->execute([
                $key,
                $name,
                $this->nullIfBlank($body['blurb'] ?? null),
                $startKey,
                $levelMin,
                $levelMax,
                $this->nullIfBlank($body['attribution'] ?? null),
                $next,
            ]);
            $moduleId = (int) $this->db->lastInsertId();

            $regionName = trim((string) ($body['region_name'] ?? ''));
            if ($regionName === '') {
                $regionName = $name;
            }
            $this->db->prepare(
                'INSERT INTO regions (region_key, module_id, name, description, region_type, sort_order)
                 VALUES (?,?,?,?,?,10)'
            )->execute([
                $regionKey,
                $moduleId,
                $regionName,
                $this->nullIfBlank($body['region_description'] ?? null),
                $regionType,
            ]);
            $regionId = (int) $this->db->lastInsertId();

            $doorName = trim((string) ($body['start_location_name'] ?? ''));
            if ($doorName === '') {
                $doorName = 'The door you came in by';
            }
            [$mx, $my] = $regionType === 'dungeon'
                ? DungeonGen::project(2, 3)
                : [50.0, 50.0];
            $this->saveLocation([
                'location_key' => $startKey,
                'region_id'    => $regionId,
                'name'         => $doorName,
                'location_type'=> $regionType === 'dungeon' ? 'room' : 'site',
                'description'  => (string) ($body['start_description']
                    ?? 'The door you came in by. Nobody has written what is beyond it yet.'),
                'map_x'        => $mx,
                'map_y'        => $my,
            ]);
            if ($regionType === 'dungeon') {
                $seedPlan = [
                    'rooms' => [[
                        'id' => 0, 'key' => $startKey, 'name' => $doorName,
                        'gx' => 1, 'gy' => 2, 'w' => 2, 'h' => 2,
                    ]],
                    'halls' => [],
                ];
                $this->db->prepare('UPDATE regions SET plan_json = ? WHERE id = ?')
                    ->execute([
                        json_encode($seedPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $regionId,
                    ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'module'   => $this->getModule($moduleId),
            'region'   => $this->requireRegion($regionId),
            'location' => $this->requireLocationByKey($startKey),
        ];
    }

    /**
     * Another map view inside a module.
     *
     * A module that is only a dungeon has one; a town with a wilderness
     * beyond it has two. Creating one does not move the start location.
     */
    public function createRegion(array $body): array
    {
        $moduleId = (int) ($body['module_id'] ?? 0);
        $this->getModule($moduleId);

        $key = $this->requireKey($body['region_key'] ?? '', 'region');
        $clash = $this->db->prepare('SELECT id FROM regions WHERE region_key = ?');
        $clash->execute([$key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a region keyed {$key}.");
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A region needs a name.');
        }

        $type = trim((string) ($body['region_type'] ?? 'dungeon'));
        if (!in_array($type, self::REGION_TYPES, true)) {
            throw new InvalidArgumentException(
                'A region type is one of: ' . implode(', ', self::REGION_TYPES) . '.'
            );
        }

        $next = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM regions WHERE module_id = ?'
        );
        $next->execute([$moduleId]);

        $this->db->prepare(
            'INSERT INTO regions (region_key, module_id, name, description, region_type, sort_order)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $key,
            $moduleId,
            $name,
            $this->nullIfBlank($body['description'] ?? null),
            $type,
            (int) $next->fetchColumn(),
        ]);

        return $this->requireRegion((int) $this->db->lastInsertId());
    }

    /**
     * A person who did not exist this morning.
     *
     * `sprite_key` may be empty: a draft with no bust is a person the scene
     * can still name. Assigning a key that has no `_face`/`_bust` on disk is
     * refused, same as saveNpc, so the next load does not.
     */
    public function createNpc(array $body): array
    {
        $key = $this->requireKey($body['npc_key'] ?? '', 'npc');
        $clash = $this->db->prepare('SELECT id FROM npcs WHERE npc_key = ?');
        $clash->execute([$key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a person keyed {$key}.");
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A person needs a name.');
        }
        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('That name is too long for the column (100).');
        }

        $spriteKey = trim((string) ($body['sprite_key'] ?? ''));
        if ($spriteKey !== '') {
            $this->assertSpriteArt($spriteKey, '');
        }

        $locationId = isset($body['location_id']) && $body['location_id'] !== null
            && $body['location_id'] !== ''
            ? (int) $body['location_id'] : null;
        if ($locationId !== null) {
            $this->requireLocation($locationId);
        }

        $this->db->prepare(
            'INSERT INTO npcs
                 (npc_key, name, role, description, sprite_key, is_merchant,
                  is_quest_giver, is_ambient, location_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $key,
            $name,
            $this->nullIfBlank($body['role'] ?? null, 80),
            $this->nullIfBlank($body['description'] ?? null),
            $spriteKey !== '' ? $spriteKey : null,
            !empty($body['is_merchant']) ? 1 : 0,
            !empty($body['is_quest_giver']) ? 1 : 0,
            !empty($body['is_ambient']) ? 1 : 0,
            $locationId,
        ]);

        return $this->getNpc((int) $this->db->lastInsertId());
    }

    /**
     * A quest that can be finished the moment it is made.
     *
     * The loader refuses a quest with no terminal stage, so a blank one that
     * saved without an ending would be a row the next import deleted. One
     * terminal stage is created with it; more stages are addStage().
     */
    public function createQuest(array $body): array
    {
        $key = $this->requireKey($body['quest_key'] ?? '', 'quest');
        if (mb_strlen($key) > 60) {
            throw new InvalidArgumentException('That key is too long for the column (60).');
        }
        $clash = $this->db->prepare('SELECT id FROM quests WHERE quest_key = ?');
        $clash->execute([$key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a quest keyed {$key}.");
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('A quest needs a title.');
        }

        $giverId = isset($body['giver_npc_id']) && $body['giver_npc_id'] !== null
            && $body['giver_npc_id'] !== ''
            ? (int) $body['giver_npc_id'] : null;
        if ($giverId !== null) {
            $this->getNpc($giverId);
        }

        $act = (int) ($body['act'] ?? 1);
        if ($act < 1 || $act > 5) {
            throw new InvalidArgumentException('An act is between 1 and 5.');
        }
        $level = (int) ($body['required_level'] ?? 1);
        if ($level < 1 || $level > Rules::MAX_LEVEL) {
            throw new InvalidArgumentException(
                'A required level is between 1 and ' . Rules::MAX_LEVEL . '.'
            );
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO quests
                     (quest_key, title, description, act, on_job_board, giver_npc_id,
                      required_level, is_active)
                 VALUES (?,?,?,?,?,?,?,1)'
            )->execute([
                $key,
                $title,
                $this->nullIfBlank($body['description'] ?? null),
                $act,
                !empty($body['on_job_board']) ? 1 : 0,
                $giverId,
                $level,
            ]);
            $questId = (int) $this->db->lastInsertId();

            $this->db->prepare(
                'INSERT INTO quest_stages
                     (quest_id, stage_key, title, objective, is_terminal, outcome, sort_order)
                 VALUES (?, \'done\', \'Done\', ?, 1, \'success\', 10)'
            )->execute([
                $questId,
                $this->nullIfBlank($body['objective'] ?? null) ?? 'The work is finished.',
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getQuest($questId);
    }

    /**
     * Another stage on an existing quest.
     *
     * A new stage is not terminal. Making it one is saveStage's job, which
     * already knows how to refuse clearing the last ending.
     */
    public function addStage(int $questId, array $body): array
    {
        $this->getQuest($questId);

        $key = $this->requireKey($body['stage_key'] ?? '', 'stage');
        if (mb_strlen($key) > 60) {
            throw new InvalidArgumentException('That key is too long for the column (60).');
        }
        $clash = $this->db->prepare(
            'SELECT id FROM quest_stages WHERE quest_id = ? AND stage_key = ?'
        );
        $clash->execute([$questId, $key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("This quest already has a stage keyed {$key}.");
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('A stage needs a title.');
        }

        $targetLocation = isset($body['target_location_id'])
            && $body['target_location_id'] !== null && $body['target_location_id'] !== ''
            ? (int) $body['target_location_id'] : null;
        if ($targetLocation !== null) {
            $this->requireLocation($targetLocation);
        }

        // New work goes before the ending. start_quest enters the first stage
        // by sort_order; a "go there" that landed after Done would never be
        // the work the player was handed.
        $term = $this->db->prepare(
            'SELECT MIN(sort_order) FROM quest_stages WHERE quest_id = ? AND is_terminal = 1'
        );
        $term->execute([$questId]);
        $termAt = $term->fetchColumn();
        if ($termAt !== false && $termAt !== null) {
            $order = (int) $termAt;
            $this->db->prepare(
                'UPDATE quest_stages SET sort_order = sort_order + 10
                  WHERE quest_id = ? AND sort_order >= ?'
            )->execute([$questId, $order]);
        } else {
            $tail = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM quest_stages WHERE quest_id = ?'
            );
            $tail->execute([$questId]);
            $order = (int) $tail->fetchColumn();
        }

        $this->db->prepare(
            'INSERT INTO quest_stages
                 (quest_id, stage_key, title, objective, journal_entry, is_terminal,
                  resolution, outcome, target_location_id, sort_order)
             VALUES (?,?,?,?,?,0,?,\'success\',?,?)'
        )->execute([
            $questId,
            $key,
            $title,
            $this->nullIfBlank($body['objective'] ?? null),
            $this->nullIfBlank($body['journal_entry'] ?? null),
            $this->nullIfBlank($body['resolution'] ?? null, 60),
            $targetLocation,
            $order,
        ]);

        return $this->getQuest($questId);
    }

    /**
     * Drop a stage that is not the last ending.
     *
     * The loader refuses a quest with no terminal stage, so this will not
     * leave one. The work that remains is still a quest.
     */
    public function deleteStage(int $stageId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM quest_stages WHERE id = ?');
        $stmt->execute([$stageId]);
        $stage = $stmt->fetch();
        if (!$stage) {
            throw new InvalidArgumentException('No such stage.');
        }
        $questId = (int) $stage['quest_id'];
        if ((int) $stage['is_terminal'] === 1) {
            $others = $this->db->prepare(
                'SELECT COUNT(*) FROM quest_stages WHERE quest_id = ? AND is_terminal = 1 AND id <> ?'
            );
            $others->execute([$questId, $stageId]);
            if ((int) $others->fetchColumn() === 0) {
                throw new InvalidArgumentException(
                    'That is the quest\'s only ending. It cannot be removed.'
                );
            }
        }
        $this->db->prepare('DELETE FROM quest_stages WHERE id = ?')->execute([$stageId]);
        return $this->getQuest($questId);
    }

    /**
     * Sprite keys that have both a face and a bust on disk.
     *
     * The same pair assertSpriteArt and the loader ask for. A key with only
     * one of the two is not offered, because assigning it would save a person
     * the next load refuses.
     *
     * @return list<string>
     */
    public function listSprites(): array
    {
        $dir = APP_ROOT . '/assets/images/npcs/';
        $out = [];
        foreach (glob($dir . '*_bust.png') ?: [] as $path) {
            $base = basename($path, '.png');
            if (!str_ends_with($base, '_bust')) {
                continue;
            }
            $key = substr($base, 0, -5);
            if ($key !== '' && is_file($dir . $key . '_face.png')) {
                $out[] = $key;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * A fight waiting in a place.
     *
     * The monsters are the shared bestiary, named by key. Quantity is who is
     * standing here; scale_to_party stays on, so the cellar rats stay "some
     * rats" rather than a fixed head-count.
     */
    public function createEncounter(array $body): array
    {
        $key = $this->requireKey($body['encounter_key'] ?? '', 'encounter');
        if (mb_strlen($key) > 60) {
            throw new InvalidArgumentException('That key is too long for the column (60).');
        }
        $clash = $this->db->prepare('SELECT id FROM encounters WHERE encounter_key = ?');
        $clash->execute([$key]);
        if ($clash->fetchColumn() !== false) {
            throw new InvalidArgumentException("There is already a fight keyed {$key}.");
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A fight needs a name.');
        }

        $locationId = (int) ($body['location_id'] ?? 0);
        $this->requireLocation($locationId);

        $roster = $body['monsters'] ?? [];
        if (!is_array($roster) || $roster === []) {
            throw new InvalidArgumentException('A fight needs at least one kind of creature.');
        }

        $resolved = [];
        foreach ($roster as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each creature is an object with a key and a quantity.');
            }
            $mk = trim((string) ($row['monster_key'] ?? ''));
            if ($mk === '') {
                throw new InvalidArgumentException('Every creature needs a monster_key.');
            }
            $found = $this->db->prepare('SELECT id FROM monsters WHERE monster_key = ?');
            $found->execute([$mk]);
            $mid = $found->fetchColumn();
            if ($mid === false) {
                throw new InvalidArgumentException("No creature keyed {$mk} in the bestiary.");
            }
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $resolved[] = [(int) $mid, $qty];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO encounters
                     (encounter_key, name, description, location_id, is_random, is_ambush,
                      difficulty, allow_flee, scale_to_party)
                 VALUES (?,?,?,?,0,?,?,1,1)'
            )->execute([
                $key,
                $name,
                $this->nullIfBlank($body['description'] ?? null),
                $locationId,
                !empty($body['is_ambush']) ? 1 : 0,
                $this->nullIfBlank($body['difficulty'] ?? null, 20) ?? 'medium',
            ]);
            $eid = (int) $this->db->lastInsertId();
            $ins = $this->db->prepare(
                'INSERT INTO encounter_monsters (encounter_id, monster_id, quantity) VALUES (?,?,?)'
            );
            foreach ($resolved as [$mid, $qty]) {
                $ins->execute([$eid, $mid, $qty]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getEncounter($eid);
    }

    /**
     * Something lying on the ground in a place.
     *
     * The item is from the shared catalogue, named by key. The same item
     * twice in one room is two things to pick up, so a second drop of the
     * same key is allowed.
     */
    public function addLocationItem(int $locationId, string $itemKey): array
    {
        $this->requireLocation($locationId);
        $key = $this->requireKey($itemKey, 'item');
        $found = $this->db->prepare('SELECT id FROM items WHERE item_key = ?');
        $found->execute([$key]);
        $itemId = $found->fetchColumn();
        if ($itemId === false) {
            throw new InvalidArgumentException("No item keyed {$key}.");
        }
        $this->db->prepare(
            'INSERT INTO location_items (location_id, item_id) VALUES (?,?)'
        )->execute([$locationId, (int) $itemId]);
        return $this->getLocation($locationId);
    }

    public function removeLocationItem(int $id): array
    {
        $stmt = $this->db->prepare('SELECT location_id FROM location_items WHERE id = ?');
        $stmt->execute([$id]);
        $locId = $stmt->fetchColumn();
        if ($locId === false) {
            throw new InvalidArgumentException('No such drop.');
        }
        $this->db->prepare('DELETE FROM location_items WHERE id = ?')->execute([$id]);
        return $this->getLocation((int) $locId);
    }

    /**
     * Put a draft on the public shelf, or take it off.
     *
     * Play still works either way — the studio's Play door does not consult
     * is_active. The homepage does.
     */
    public function setModuleActive(int $id, bool $active): array
    {
        $this->getModule($id);
        $this->db->prepare('UPDATE modules SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
        return $this->getModule($id);
    }

    /**
     * Cover art or a region plate, named for the key the renderer already uses.
     *
     * No new column: a module cover is assets/images/modules/<key>.jpg and a
     * town plate is assets/images/maps/<region_key>.png. A dungeon does not
     * take a plate — the plan is the map.
     */
    public function saveArt(string $kind, string $key, string $payload): array
    {
        $key = $this->requireKey($key, $kind === 'cover' ? 'module' : 'region');
        if ($kind === 'cover') {
            $found = $this->db->prepare('SELECT id FROM modules WHERE module_key = ?');
            $found->execute([$key]);
            if ($found->fetchColumn() === false) {
                throw new InvalidArgumentException("No module keyed {$key}.");
            }
            $path = APP_ROOT . '/assets/images/modules/' . $key . '.jpg';
            $this->writeImage($payload, $path, 'jpeg');
            return ['kind' => 'cover', 'key' => $key, 'path' => 'assets/images/modules/' . $key . '.jpg'];
        }
        if ($kind === 'plate') {
            $region = $this->db->prepare(
                'SELECT id, region_type FROM regions WHERE region_key = ?'
            );
            $region->execute([$key]);
            $row = $region->fetch();
            if (!$row) {
                throw new InvalidArgumentException("No region keyed {$key}.");
            }
            if ($row['region_type'] === 'dungeon') {
                throw new InvalidArgumentException(
                    'A dungeon does not take a world plate. The plan is the map.'
                );
            }
            $path = APP_ROOT . '/assets/images/maps/' . $key . '.png';
            $this->writeImage($payload, $path, 'png');
            return ['kind' => 'plate', 'key' => $key, 'path' => 'assets/images/maps/' . $key . '.png'];
        }
        throw new InvalidArgumentException('Art is a cover or a plate.');
    }

    private function writeImage(string $payload, string $path, string $format): void
    {
        if (preg_match('#^data:image/[\w+.-]+;base64,#', $payload)) {
            $payload = substr($payload, (int) strpos($payload, ',') + 1);
        }
        $bin = base64_decode($payload, true);
        if ($bin === false || $bin === '') {
            throw new InvalidArgumentException('That is not an image.');
        }
        if (strlen($bin) > 3_000_000) {
            throw new InvalidArgumentException('That image is too large (3 MB).');
        }
        $im = @imagecreatefromstring($bin);
        if ($im === false) {
            throw new InvalidArgumentException('That file is not a PNG or JPEG.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            imagedestroy($im);
            throw new InvalidArgumentException('Cannot write art to ' . $dir . '.');
        }
        $ok = $format === 'jpeg' ? imagejpeg($im, $path, 88) : imagepng($im, $path);
        imagedestroy($im);
        if (!$ok) {
            throw new InvalidArgumentException('The image could not be written.');
        }
    }

    /**
     * How this adventure runs, inferred from what is already authored.
     *
     * A beat is arrive, talk, take work, fight, or end. No extra table: the
     * quest stages and the people who hand them out are the spine. Empty is
     * the honest picture of a blank page.
     *
     * @return list<array<string,mixed>>
     */
    public function moduleRun(int $moduleId): array
    {
        $module = $this->getModule($moduleId);
        $beats = [];

        $start = $this->db->prepare(
            'SELECT l.id, l.name, l.location_key FROM locations l
              WHERE l.location_key = ?'
        );
        $start->execute([(string) $module['start_location_key']]);
        $door = $start->fetch();
        if ($door) {
            $beats[] = [
                'kind'         => 'arrive',
                'title'        => 'Arrive at ' . $door['name'],
                'location_id'  => (int) $door['id'],
                'location_key' => $door['location_key'],
            ];
        }

        $quests = $this->db->prepare(
            'SELECT q.id, q.quest_key, q.title, n.id AS npc_id, n.name AS npc_name,
                    n.location_id AS npc_location_id
               FROM quests q
               INNER JOIN npcs n ON n.id = q.giver_npc_id
               INNER JOIN locations l ON l.id = n.location_id
               INNER JOIN regions r ON r.id = l.region_id
              WHERE r.module_id = ?
              ORDER BY q.id'
        );
        $quests->execute([$moduleId]);
        $seenFight = [];
        foreach ($quests->fetchAll() as $q) {
            $beats[] = [
                'kind'        => 'talk',
                'title'       => 'Talk to ' . $q['npc_name'],
                'npc_id'      => (int) $q['npc_id'],
                'location_id' => (int) $q['npc_location_id'],
            ];
            $beats[] = [
                'kind'        => 'work',
                'title'       => $q['title'],
                'quest_id'    => (int) $q['id'],
                'quest_key'   => $q['quest_key'],
                'location_id' => (int) $q['npc_location_id'],
            ];
            $stages = $this->db->prepare(
                'SELECT s.title, s.is_terminal, s.target_location_id, l.name AS target_name,
                        l.location_key AS target_key
                   FROM quest_stages s
                   LEFT JOIN locations l ON l.id = s.target_location_id
                  WHERE s.quest_id = ? ORDER BY s.sort_order, s.id'
            );
            $stages->execute([(int) $q['id']]);
            foreach ($stages->fetchAll() as $s) {
                if ((int) $s['is_terminal'] === 1) {
                    $beats[] = [
                        'kind'      => 'end',
                        'title'     => $s['title'] !== '' ? $s['title'] : 'The work ends',
                        'quest_id'  => (int) $q['id'],
                    ];
                    continue;
                }
                if ($s['target_location_id']) {
                    $beats[] = [
                        'kind'         => 'arrive',
                        'title'        => 'Go to ' . ($s['target_name'] ?: $s['title']),
                        'location_id'  => (int) $s['target_location_id'],
                        'location_key' => $s['target_key'],
                        'quest_id'     => (int) $q['id'],
                    ];
                    $fights = $this->db->prepare(
                        'SELECT id, name FROM encounters
                          WHERE location_id = ? AND is_random = 0 ORDER BY id'
                    );
                    $fights->execute([(int) $s['target_location_id']]);
                    foreach ($fights->fetchAll() as $f) {
                        $seenFight[(int) $f['id']] = true;
                        $beats[] = [
                            'kind'        => 'fight',
                            'title'       => $f['name'],
                            'encounter_id'=> (int) $f['id'],
                            'location_id' => (int) $s['target_location_id'],
                        ];
                    }
                }
            }
        }

        $orphans = $this->db->prepare(
            'SELECT e.id, e.name, e.location_id, l.name AS location_name
               FROM encounters e
               INNER JOIN locations l ON l.id = e.location_id
               INNER JOIN regions r ON r.id = l.region_id
              WHERE r.module_id = ? AND e.is_random = 0
              ORDER BY e.id'
        );
        $orphans->execute([$moduleId]);
        foreach ($orphans->fetchAll() as $f) {
            if (isset($seenFight[(int) $f['id']])) {
                continue;
            }
            $beats[] = [
                'kind'        => 'fight',
                'title'       => $f['name'] . ' (' . $f['location_name'] . ')',
                'encounter_id'=> (int) $f['id'],
                'location_id' => (int) $f['location_id'],
            ];
        }

        return $beats;
    }

    public function getEncounter(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, l.location_key, l.name AS location_name
               FROM encounters e
               LEFT JOIN locations l ON l.id = e.location_id
              WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such fight.');
        }
        $mons = $this->db->prepare(
            'SELECT em.quantity, m.monster_key, m.name
               FROM encounter_monsters em
               INNER JOIN monsters m ON m.id = em.monster_id
              WHERE em.encounter_id = ?
              ORDER BY em.id'
        );
        $mons->execute([$id]);
        $row['monsters'] = $mons->fetchAll();
        return $row;
    }

    /**
     * Tear down a draft module and everything that only existed to stand in it.
     *
     * Refuses if a party has picked it — those saves are somebody's game.
     * People, quests and fights that live in its rooms go with it; the
     * shared bestiary does not.
     */
    public function deleteModule(int $id): array
    {
        $module = $this->getModule($id);

        $playing = $this->db->prepare(
            'SELECT COUNT(*) FROM parties WHERE module_id = ?'
        );
        $playing->execute([$id]);
        if ((int) $playing->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                'A party is playing this module. It cannot be deleted while a save is in it.'
            );
        }

        $locIds = $this->db->prepare(
            'SELECT l.id FROM locations l
               INNER JOIN regions r ON r.id = l.region_id
              WHERE r.module_id = ?'
        );
        $locIds->execute([$id]);
        $ids = array_map('intval', $locIds->fetchAll(PDO::FETCH_COLUMN));

        $npcIds = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $npcStmt = $this->db->prepare("SELECT id FROM npcs WHERE location_id IN ({$in})");
            $npcStmt->execute($ids);
            $npcIds = array_map('intval', $npcStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $this->db->beginTransaction();
        try {
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $this->db->prepare("DELETE FROM encounters WHERE location_id IN ({$in})")
                    ->execute($ids);
            }
            if ($npcIds) {
                $nin = implode(',', array_fill(0, count($npcIds), '?'));
                $this->db->prepare("DELETE FROM quests WHERE giver_npc_id IN ({$nin})")
                    ->execute($npcIds);
            }
            $this->db->prepare(
                'DELETE q FROM quests q
                   INNER JOIN locations tl ON tl.id = q.target_location_id
                   INNER JOIN regions tr ON tr.id = tl.region_id
                  WHERE tr.module_id = ?'
            )->execute([$id]);
            if ($npcIds) {
                $nin = implode(',', array_fill(0, count($npcIds), '?'));
                $this->db->prepare("DELETE FROM npcs WHERE id IN ({$nin})")->execute($npcIds);
            }
            $this->db->prepare('DELETE FROM modules WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['deleted' => true, 'module_key' => $module['module_key']];
    }

    /**
     * One adventure as a document somebody can download and import.
     *
     * Shared catalogue (monsters, items, spells) is named by key, not copied.
     * Art that belongs to the module — the cover and any region plate — travels
     * as data URLs so the other install does not have to share a disk.
     *
     * @return array<string,mixed>
     */
    public function packModule(int $id): array
    {
        $module = $this->getModule($id);
        $key = (string) $module['module_key'];

        $regions = $this->db->prepare(
            'SELECT * FROM regions WHERE module_id = ? ORDER BY sort_order, id'
        );
        $regions->execute([$id]);
        $packedRegions = [];
        $locIds = [];
        foreach ($regions->fetchAll() as $r) {
            $locs = $this->db->prepare(
                'SELECT * FROM locations WHERE region_id = ? ORDER BY sort_order, id'
            );
            $locs->execute([(int) $r['id']]);
            $locations = [];
            foreach ($locs->fetchAll() as $l) {
                $locIds[] = (int) $l['id'];
                $here = $this->getLocation((int) $l['id']);
                $exits = [];
                foreach ($here['exits'] as $e) {
                    $ex = ['to' => $e['to_location_key'], 'label' => $e['label']];
                    if ((int) $e['is_hidden'] === 1) {
                        $ex['hidden'] = true;
                    }
                    $conds = json_decode((string) ($e['conditions_json'] ?? ''), true);
                    if (is_array($conds) && $conds) {
                        $ex['conditions'] = $conds;
                    }
                    $exits[] = $ex;
                }
                $loot = array_values(array_unique(array_map(
                    static fn ($it) => (string) $it['item_key'],
                    $here['items'] ?? []
                )));
                $entry = [
                    'name'        => $l['name'],
                    'type'        => $l['location_type'],
                    'map_pos'     => [(float) $l['map_x'], (float) $l['map_y']],
                    'description' => $l['description'],
                ];
                if (($l['first_visit_text'] ?? '') !== '') {
                    $entry['first_visit'] = $l['first_visit_text'];
                }
                if ($exits) {
                    $entry['exits'] = $exits;
                }
                if ($loot) {
                    $entry['loot'] = $loot;
                }
                $locations[(string) $l['location_key']] = $entry;
            }
            $reg = [
                'region_key'  => $r['region_key'],
                'module'      => $key,
                'name'        => $r['name'],
                'region_type' => $r['region_type'],
                'locations'   => $locations,
            ];
            if (($r['description'] ?? '') !== '') {
                $reg['description'] = $r['description'];
            }
            if (!empty($r['plan_json'])) {
                $plan = json_decode((string) $r['plan_json'], true);
                if (is_array($plan) && !empty($plan['rooms'])) {
                    $reg['plan'] = $plan;
                }
            }
            $packedRegions[] = $reg;
        }

        $npcs = [];
        $dialog = [];
        if ($locIds) {
            $in = implode(',', array_fill(0, count($locIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM npcs WHERE location_id IN ({$in}) AND npc_key IS NOT NULL"
            );
            $stmt->execute($locIds);
            foreach ($stmt->fetchAll() as $n) {
                $row = [
                    'npc_key' => $n['npc_key'],
                    'name'    => $n['name'],
                ];
                foreach (['role', 'description', 'sprite_key'] as $k) {
                    if (($n[$k] ?? '') !== '') {
                        $row[$k] = $n[$k];
                    }
                }
                foreach (['is_merchant', 'is_quest_giver', 'is_ambient'] as $k) {
                    if ((int) ($n[$k] ?? 0) === 1) {
                        $row[$k] = true;
                    }
                }
                $lk = $this->db->prepare('SELECT location_key FROM locations WHERE id = ?');
                $lk->execute([(int) $n['location_id']]);
                $place = $lk->fetchColumn();
                if ($place) {
                    $row['place'] = ['location' => $place];
                }
                if (($n['dialogue_json'] ?? '') !== '') {
                    $doc = json_decode((string) $n['dialogue_json'], true);
                    if (is_array($doc)) {
                        $row['dialog'] = $n['npc_key'];
                        $dialog[] = [
                            'npc_key' => $n['npc_key'],
                            'start'   => $doc['start'] ?? 'hail',
                            'nodes'   => $doc['nodes'] ?? new stdClass(),
                        ];
                    }
                }
                $npcs[] = $row;
            }
        }

        $quests = [];
        foreach ($this->listQuests($id) as $qrow) {
            $q = $this->getQuest((int) $qrow['id']);
            $out = [
                'quest_key' => $q['quest_key'],
                'title'     => $q['title'],
                'stages'    => [],
            ];
            if (($q['description'] ?? '') !== '') {
                $out['description'] = $q['description'];
            }
            if ($q['giver_key']) {
                $out['giver'] = $q['giver_key'];
            }
            if ((int) $q['on_job_board'] === 1) {
                $out['on_job_board'] = true;
            }
            foreach ($q['stages'] as $s) {
                $st = ['title' => $s['title']];
                if (($s['objective'] ?? '') !== '') {
                    $st['objective'] = $s['objective'];
                }
                if ((int) $s['is_terminal'] === 1) {
                    $st['terminal'] = true;
                }
                if ($s['target_location_key']) {
                    $st['target'] = ['location' => $s['target_location_key']];
                }
                $out['stages'][(string) $s['stage_key']] = $st;
            }
            $quests[] = $out;
        }

        $encounters = [];
        if ($locIds) {
            $in = implode(',', array_fill(0, count($locIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM encounters WHERE location_id IN ({$in}) AND encounter_key IS NOT NULL"
            );
            $stmt->execute($locIds);
            foreach ($stmt->fetchAll() as $e) {
                $full = $this->getEncounter((int) $e['id']);
                $roster = [];
                foreach ($full['monsters'] as $m) {
                    $roster[] = [
                        'monster'  => $m['monster_key'],
                        'quantity' => (int) $m['quantity'],
                    ];
                }
                $row = [
                    'encounter_key' => $e['encounter_key'],
                    'name'          => $e['name'],
                    'monsters'      => $roster,
                ];
                if ((int) $e['is_ambush'] === 1) {
                    $row['ambush'] = true;
                }
                $lk = $this->db->prepare('SELECT location_key FROM locations WHERE id = ?');
                $lk->execute([(int) $e['location_id']]);
                $place = $lk->fetchColumn();
                if ($place) {
                    $row['place'] = ['location' => $place];
                }
                $encounters[] = $row;
            }
        }

        $art = [];
        $cover = APP_ROOT . '/assets/images/modules/' . $key . '.jpg';
        if (is_file($cover)) {
            $art['cover'] = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($cover));
        }
        foreach ($packedRegions as $r) {
            $plate = APP_ROOT . '/assets/images/maps/' . $r['region_key'] . '.png';
            if (is_file($plate)) {
                $art['plates'][$r['region_key']] =
                    'data:image/png;base64,' . base64_encode((string) file_get_contents($plate));
            }
        }

        return [
            'format'     => 'rivermark.adventure',
            'version'    => 1,
            'module'     => [
                'module_key'         => $key,
                'name'               => $module['name'],
                'blurb'              => $module['blurb'],
                'start_location_key' => $module['start_location_key'],
                'level_min'          => (int) $module['level_min'],
                'level_max'          => (int) $module['level_max'],
                'attribution'        => $module['attribution'],
                'is_active'          => (int) $module['is_active'] === 1,
            ],
            'regions'    => $packedRegions,
            'npcs'       => $npcs,
            'dialog'     => $dialog,
            'quests'     => $quests,
            'encounters' => $encounters,
            'art'        => $art,
        ];
    }

    /**
     * Install a downloaded adventure. Refuses if the module key already exists.
     *
     * @param  array<string,mixed> $pack
     * @return array{module: array}
     */
    public function importBundle(array $pack): array
    {
        if (($pack['format'] ?? '') !== 'rivermark.adventure') {
            throw new InvalidArgumentException('That is not a Rivermark adventure file.');
        }
        if ((int) ($pack['version'] ?? 0) !== 1) {
            throw new InvalidArgumentException('This studio cannot read that adventure version.');
        }
        $mod = $pack['module'] ?? null;
        if (!is_array($mod)) {
            throw new InvalidArgumentException('The file has no module.');
        }
        $regions = $pack['regions'] ?? [];
        if (!is_array($regions) || $regions === []) {
            throw new InvalidArgumentException('The file has no region.');
        }
        $first = $regions[0];
        $type = (string) ($first['region_type'] ?? 'dungeon');

        $made = $this->createModule([
            'module_key'          => $mod['module_key'] ?? '',
            'name'                => $mod['name'] ?? '',
            'blurb'               => $mod['blurb'] ?? '',
            'start_location_key'  => $mod['start_location_key'] ?? '',
            'level_min'           => $mod['level_min'] ?? 1,
            'level_max'           => $mod['level_max'] ?? 6,
            'attribution'         => $mod['attribution'] ?? '',
            'region_type'         => $type,
            'region_key'          => $first['region_key'] ?? '',
            'region_name'         => $first['name'] ?? '',
            'start_location_name' => $first['locations'][$mod['start_location_key'] ?? '']['name'] ?? '',
        ]);
        $moduleId = (int) $made['module']['id'];
        $regionId = (int) $made['region']['id'];

        if (!empty($first['plan']['rooms'])) {
            $this->savePlan($regionId, $first['plan']);
        }

        foreach ($first['locations'] ?? [] as $lk => $loc) {
            if (!is_array($loc)) {
                continue;
            }
            $pos = $loc['map_pos'] ?? [50, 50];
            $this->saveLocation([
                'location_key'         => $lk,
                'region_id'            => $regionId,
                'name'                 => $loc['name'] ?? $lk,
                'description'          => $loc['description'] ?? 'A place.',
                'first_visit_text'     => $loc['first_visit'] ?? '',
                'location_type'        => $loc['type'] ?? 'site',
                'map_x'                => $pos[0] ?? 50,
                'map_y'                => $pos[1] ?? 50,
            ]);
            foreach ($loc['loot'] ?? [] as $itemKey) {
                if (is_string($itemKey) && $itemKey !== '') {
                    try {
                        $this->addLocationItem(
                            (int) $this->requireLocationByKey((string) $lk)['id'],
                            $itemKey
                        );
                    } catch (InvalidArgumentException $e) {
                        // Shared catalogue missing on this install: skip the drop.
                    }
                }
            }
        }

        foreach ($first['locations'] ?? [] as $lk => $loc) {
            $from = (int) $this->requireLocationByKey((string) $lk)['id'];
            foreach ($loc['exits'] ?? [] as $ex) {
                if (!is_array($ex) || empty($ex['to'])) {
                    continue;
                }
                try {
                    $to = (int) $this->requireLocationByKey((string) $ex['to'])['id'];
                } catch (InvalidArgumentException $e) {
                    continue;
                }
                $this->saveExit([
                    'from_location_id' => $from,
                    'to_location_id'   => $to,
                    'label'            => (string) ($ex['label'] ?? 'Onward'),
                    'is_hidden'        => !empty($ex['hidden']),
                    'conditions'       => $ex['conditions'] ?? null,
                ]);
            }
        }

        $npcId = [];
        foreach ($pack['npcs'] ?? [] as $n) {
            if (!is_array($n)) {
                continue;
            }
            $place = $n['place']['location'] ?? null;
            $locId = null;
            if (is_string($place) && $place !== '') {
                try {
                    $locId = (int) $this->requireLocationByKey($place)['id'];
                } catch (InvalidArgumentException $e) {
                    $locId = null;
                }
            }
            $body = [
                'npc_key'     => $n['npc_key'] ?? '',
                'name'        => $n['name'] ?? '',
                'role'        => $n['role'] ?? '',
                'description' => $n['description'] ?? '',
                'sprite_key'  => $n['sprite_key'] ?? '',
                'location_id' => $locId,
                'is_merchant' => !empty($n['is_merchant']),
                'is_quest_giver' => !empty($n['is_quest_giver']),
                'is_ambient'  => !empty($n['is_ambient']),
            ];
            try {
                $person = $this->createNpc($body);
            } catch (InvalidArgumentException $e) {
                $body['sprite_key'] = '';
                $person = $this->createNpc($body);
            }
            $npcId[(string) $n['npc_key']] = (int) $person['id'];
        }

        foreach ($pack['dialog'] ?? [] as $d) {
            if (!is_array($d) || empty($d['npc_key'])) {
                continue;
            }
            $id = $npcId[(string) $d['npc_key']] ?? null;
            if ($id === null) {
                continue;
            }
            $this->saveDialogue($id, [
                'start' => $d['start'] ?? 'hail',
                'nodes' => $d['nodes'] ?? ['hail' => ['text' => '', 'choices' => [['label' => 'Leave.', 'end' => true]]]],
            ]);
        }

        foreach ($pack['quests'] ?? [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $giver = $q['giver'] ?? null;
            $qid = $this->createQuest([
                'quest_key'    => $q['quest_key'] ?? '',
                'title'        => $q['title'] ?? '',
                'description'  => $q['description'] ?? '',
                'giver_npc_id' => is_string($giver) ? ($npcId[$giver] ?? null) : null,
                'on_job_board' => !empty($q['on_job_board']),
            ]);
            $created = $this->getQuest((int) $qid['id']);
            foreach ($q['stages'] ?? [] as $sk => $st) {
                if (!is_array($st)) {
                    continue;
                }
                if ($sk === 'done') {
                    $doneId = (int) $created['stages'][array_key_last($created['stages'])]['id'];
                    $this->saveStage($doneId, [
                        'title'       => $st['title'] ?? 'Done',
                        'objective'   => $st['objective'] ?? '',
                        'is_terminal' => 1,
                    ]);
                    continue;
                }
                $target = $st['target']['location'] ?? null;
                $tid = null;
                if (is_string($target)) {
                    try {
                        $tid = (int) $this->requireLocationByKey($target)['id'];
                    } catch (InvalidArgumentException $e) {
                        $tid = null;
                    }
                }
                $this->addStage((int) $qid['id'], [
                    'stage_key'          => $sk,
                    'title'              => $st['title'] ?? $sk,
                    'objective'          => $st['objective'] ?? '',
                    'target_location_id' => $tid,
                ]);
            }
        }

        foreach ($pack['encounters'] ?? [] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $place = $e['place']['location'] ?? null;
            if (!is_string($place)) {
                continue;
            }
            try {
                $lid = (int) $this->requireLocationByKey($place)['id'];
            } catch (InvalidArgumentException $ex) {
                continue;
            }
            $roster = [];
            foreach ($e['monsters'] ?? [] as $m) {
                if (is_array($m) && !empty($m['monster'])) {
                    $roster[] = [
                        'monster_key' => $m['monster'],
                        'quantity'    => $m['quantity'] ?? 1,
                    ];
                }
            }
            if ($roster === []) {
                continue;
            }
            try {
                $this->createEncounter([
                    'encounter_key' => $e['encounter_key'] ?? '',
                    'name'          => $e['name'] ?? 'A fight',
                    'location_id'   => $lid,
                    'is_ambush'     => !empty($e['ambush']),
                    'monsters'      => $roster,
                ]);
            } catch (InvalidArgumentException $ex) {
                // Missing bestiary entry on this install.
            }
        }

        $art = $pack['art'] ?? [];
        if (is_array($art)) {
            if (!empty($art['cover']) && is_string($art['cover'])) {
                try {
                    $this->saveArt('cover', (string) $mod['module_key'], $art['cover']);
                } catch (InvalidArgumentException $e) {
                    // cover is optional
                }
            }
            foreach ($art['plates'] ?? [] as $rk => $data) {
                if (is_string($data)) {
                    try {
                        $this->saveArt('plate', (string) $rk, $data);
                    } catch (InvalidArgumentException $e) {
                        // plate optional
                    }
                }
            }
        }

        if (!empty($mod['is_active'])) {
            $this->setModuleActive($moduleId, true);
        }

        return ['module' => $this->getModule($moduleId)];
    }

    /** A content key: lowercase letters, numbers, underscores. */
    private function requireKey($value, string $kind): string
    {
        $key = trim((string) $value);
        if (!preg_match(self::KEY_RE, $key)) {
            throw new InvalidArgumentException(
                "A {$kind} key is lowercase letters, numbers and underscores."
            );
        }
        return $key;
    }

    private function requireRegion(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, m.module_key, m.name AS module_name
               FROM regions r
               INNER JOIN modules m ON m.id = r.module_id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such region.');
        }
        return $row;
    }

    private function requireLocationByKey(string $key): array
    {
        $stmt = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException('No such location.');
        }
        return $this->requireLocation((int) $id);
    }

    /**
     * Two locations in different modules cannot share an exit.
     *
     * The loader treats a cross-module exit as an error, not a warning, because
     * in play it looks like a travel bug. Saving one here would be a row the
     * next import refuses.
     */
    private function assertSameModule(int $fromId, int $toId): void
    {
        $stmt = $this->db->prepare(
            'SELECT r.module_id
               FROM locations l
               INNER JOIN regions r ON r.id = l.region_id
              WHERE l.id = ?'
        );
        $stmt->execute([$fromId]);
        $a = $stmt->fetchColumn();
        $stmt->execute([$toId]);
        $b = $stmt->fetchColumn();
        if ($a === false || $b === false || (int) $a !== (int) $b) {
            throw new InvalidArgumentException(
                'An exit cannot leave its module. Two games share no doors.'
            );
        }
    }

    /** Every route that takes a location id resolves it through here. */
    private function requireLocation(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, r.region_key, r.name AS region_name
               FROM locations l
               INNER JOIN regions r ON r.id = l.region_id
              WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such location.');
        }
        return $row;
    }

    /**
     * A map position, in percent of the region's drawing.
     *
     * Outside 0–100 is a node drawn off the edge of its own map — reachable in
     * play, invisible on the chart — which is exactly the kind of fault that
     * gets attributed to the renderer.
     */
    private function percent($value, string $field): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException("{$field} is a number between 0 and 100.");
        }
        $n = (float) $value;
        if ($n < 0 || $n > 100) {
            throw new InvalidArgumentException(
                "{$field} is a percent position on the region's map; {$n} is outside 0–100."
            );
        }
        return $n;
    }

    // =======================================================================

    /** Blank strings become NULL; the columns are nullable and '' is not a value. */
    private function nullIfBlank($value, ?int $maxLength = null): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        if ($maxLength !== null && mb_strlen($s) > $maxLength) {
            throw new InvalidArgumentException("That value is too long (max {$maxLength}).");
        }
        return $s;
    }
}
