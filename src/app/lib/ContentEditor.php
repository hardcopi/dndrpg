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
    public function listNpcs(): array
    {
        return $this->db->query(
            "SELECT n.id, n.npc_key, n.name, n.role, n.sprite_key, n.pose,
                    n.is_merchant, n.is_quest_giver, n.is_ambient,
                    n.dialogue_json IS NOT NULL AND n.dialogue_json <> '' AS has_dialogue,
                    n.location_id, l.location_key, l.name AS location_name,
                    r.name AS region_name
               FROM npcs n
               LEFT JOIN locations l ON l.id = n.location_id
               LEFT JOIN regions r ON r.id = l.region_id
              WHERE n.npc_key IS NOT NULL
              ORDER BY n.name"
        )->fetchAll();
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
     * check_sprite in tools/load_content.py treats a missing sheet, face or
     * bust as a hard error, so accepting one here would save an NPC that the
     * next content load refuses — and in the meantime they render as a hole in
     * the map. A posed NPC additionally needs the sheet for that pose, which is
     * the same check tools/load_content.py makes.
     */
    private function assertSpriteArt(string $spriteKey, string $pose): void
    {
        if (!preg_match(self::KEY_RE, $spriteKey)) {
            throw new InvalidArgumentException(
                'A sprite key is lowercase letters, numbers and underscores.'
            );
        }
        $dir = APP_ROOT . '/assets/images/npcs/';
        foreach (['_sheet', '_face', '_bust'] as $suffix) {
            if (!is_file($dir . $spriteKey . $suffix . '.png')) {
                throw new InvalidArgumentException(
                    "No art at assets/images/npcs/{$spriteKey}{$suffix}.png."
                );
            }
        }
        if ($pose !== '' && !is_file($dir . $spriteKey . '_' . $pose . '.png')) {
            throw new InvalidArgumentException(
                "{$spriteKey} has no {$pose} sheet (assets/images/npcs/{$spriteKey}_{$pose}.png)."
            );
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

    public function listQuests(): array
    {
        return $this->db->query(
            'SELECT q.id, q.quest_key, q.title, q.act, q.on_job_board, q.required_level,
                    q.companion_key, q.is_active,
                    (SELECT COUNT(*) FROM quest_stages s WHERE s.quest_id = q.id) AS stages
               FROM quests q
              WHERE q.quest_key IS NOT NULL
              ORDER BY q.act, q.title'
        )->fetchAll();
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
