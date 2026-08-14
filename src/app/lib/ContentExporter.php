<?php
/**
 * The database, written back out as authored content.
 *
 * Why this direction exists
 * -------------------------
 * Content used to flow one way and destructively: `content/**.json` →
 * tools/load_content.py → `sql/content.sql` → the database, where content.sql
 * DELETEs every row whose key is not in the files. The database was a derived
 * artifact, so anything edited in the game survived exactly until the next
 * regeneration — which is why edits made in the map editor kept vanishing.
 *
 * The database is now the source of truth. That makes this the piece that was
 * missing: without a way back to files, "the database is truth" also means
 * losing every authored hour the first time a volume is dropped, and it means
 * content can no longer be committed, diffed or deployed.
 *
 * So this is not a backup. It is the export half of the loop:
 *
 *     edit in the game  →  export  →  content/*.json changes  →  commit
 *
 * and a fresh install seeded from those files comes up as the world you left.
 *
 * What it covers
 * --------------
 * The world graph — regions, their locations and the exits between them — plus
 * the people, their conversations and the quests. Terrain is not here because
 * terrain no longer exists: the tile grid, and tools/snapshot_map.php with it,
 * were replaced by the location graph, and a location is prose in a row rather
 * than a picture made of tiles. There is nothing left for a second exporter to
 * own.
 *
 * Encounters, items, monsters, spells and companions are still file-authored
 * only. Nothing in the editors writes them, so exporting them could only
 * reformat files nobody changed; when something starts editing them, they get
 * an export method here beside the others.
 */

declare(strict_types=1);

class ContentExporter
{
    private PDO $db;
    private string $root;

    /** @var string[] every path written this run, for reporting */
    private array $written = [];

    /**
     * Placeholder => the literal text it stands for, for the one file whose
     * layout json_encode() cannot produce on its own. See inline().
     *
     * @var array<string, string>
     */
    private array $inline = [];

    public function __construct(PDO $db, ?string $contentRoot = null)
    {
        $this->db = $db;
        $this->root = rtrim($contentRoot ?? (APP_ROOT . '/content'), '/');
    }

    /** @return array{written: string[], counts: array<string,int>} */
    public function exportAll(): array
    {
        $this->written = [];
        $counts = [
            'modules'    => $this->exportModules(),
            'regions'    => $this->exportRegions(),
            'npcs'       => $this->exportNpcs(),
            'dialog'     => $this->exportDialogue(),
            'quests'     => $this->exportQuests(),
            'encounters' => $this->exportEncounters(),
        ];
        return ['written' => $this->written, 'counts' => $counts];
    }

    // =======================================================================
    // Modules
    // =======================================================================

    /**
     * One file per playable game, at content/modules/<key>.json.
     *
     * Short rows and no nesting, so unlike the region files this needs none of
     * the inline() machinery below. Optional keys are written only when they
     * say something the default does not, matching how exportRegions treats
     * inn_cost and the rest — an all-defaults module is three lines.
     */
    public function exportModules(): int
    {
        $modules = $this->db->query(
            'SELECT * FROM modules ORDER BY sort_order, module_key'
        )->fetchAll();

        $count = 0;
        foreach ($modules as $m) {
            $out = [
                'module_key'         => $m['module_key'],
                'name'               => $m['name'],
                'blurb'              => $m['blurb'],
                'start_location_key' => $m['start_location_key'],
            ];
            if (($m['blurb'] ?? null) === null || $m['blurb'] === '') {
                unset($out['blurb']);
            }
            if ((int) $m['level_min'] !== 1) {
                $out['level_min'] = (int) $m['level_min'];
            }
            // The default is the level cap, not a literal 6 — a module that
            // runs the whole way says nothing, and the line only appears when
            // the module stops short.
            if ((int) $m['level_max'] !== Rules::MAX_LEVEL) {
                $out['level_max'] = (int) $m['level_max'];
            }
            if (($m['attribution'] ?? null) !== null && $m['attribution'] !== '') {
                $out['attribution'] = $m['attribution'];
            }
            // Written only when false: `is_active` defaults to true, and the
            // one time it matters is a module deliberately withdrawn.
            if (!(int) $m['is_active']) {
                $out['is_active'] = false;
            }
            if ((int) $m['sort_order'] !== 0) {
                $out['sort_order'] = (int) $m['sort_order'];
            }

            $this->write('modules/' . $m['module_key'] . '.json', $out);
            $count++;
        }
        return $count;
    }

    // =======================================================================
    // Regions and locations
    // =======================================================================

    /**
     * The world graph, one file per region, at content/locations/<key>.json.
     *
     * The shape is the one tools/load_content.py reads back — LOC_FIELDS and
     * EXIT_FIELDS there are the contract — and the field order is the order the
     * regions were authored in, so a first export of an unedited database is a
     * no-op rather than a three-file reshuffle nobody can read.
     *
     * Optional keys are written only when they say something the default does
     * not: a location that is not an inn, cannot be camped in, rolls no random
     * encounters and is not hidden carries none of those four lines.
     */
    public function exportRegions(): int
    {
        $regions = $this->db->query(
            'SELECT r.*, m.module_key
               FROM regions r
               LEFT JOIN modules m ON m.id = r.module_id
              ORDER BY r.sort_order, r.region_key'
        )->fetchAll();

        $locStmt = $this->db->prepare(
            'SELECT * FROM locations WHERE region_id = ? ORDER BY sort_order, id'
        );
        // Exits are addressed by the destination's key, never by its id — the
        // rule docs/CONTENT.md states for every reference in a content file.
        $exitStmt = $this->db->prepare(
            'SELECT e.label, e.conditions_json, e.is_hidden, t.location_key AS to_key
               FROM location_exits e
               INNER JOIN locations t ON t.id = e.to_location_id
              WHERE e.from_location_id = ?
              ORDER BY e.sort_order, e.id'
        );

        $count = 0;
        foreach ($regions as $r) {
            $this->inline = [];

            $out = [
                'region_key'  => $r['region_key'],
                // Second, matching the order the loader's FIELDS manifest reads
                // and the order these files were authored in. Not optional: a
                // region file without it fails validation, so omitting it when
                // the join came back empty would write a tree that cannot load.
                'module'      => $r['module_key'],
                'name'        => $r['name'],
                'region_type' => $r['region_type'],
            ];
            if (($r['description'] ?? null) !== null && $r['description'] !== '') {
                $out['description'] = $r['description'];
            }
            if ((int) $r['sort_order'] !== 0) {
                $out['sort_order'] = (int) $r['sort_order'];
            }
            if (!empty($r['plan_json'])) {
                $plan = json_decode((string) $r['plan_json'], true);
                if (is_array($plan) && !empty($plan['rooms'])) {
                    $out['plan'] = $plan;
                }
            }

            $locStmt->execute([(int) $r['id']]);
            $locations = [];
            foreach ($locStmt->fetchAll() as $l) {
                $loc = [
                    'name' => $l['name'],
                    'type' => $l['location_type'],
                    // [x, y] on one line: a coordinate pair broken over four
                    // lines is harder to read than the thing it describes.
                    'map_pos' => $this->inline(
                        '[' . $this->number($l['map_x']) . ', ' . $this->number($l['map_y']) . ']'
                    ),
                ];
                if ($l['inn_cost'] !== null) {
                    $loc['inn_cost'] = (int) $l['inn_cost'];
                }
                if ((int) $l['allow_camp'] === 1) {
                    $loc['allow_camp'] = true;
                }
                if ((int) $l['random_encounter_pct'] !== 0) {
                    $loc['random_encounter_pct'] = (int) $l['random_encounter_pct'];
                }
                if ((int) $l['hidden_until_visited'] === 1) {
                    $loc['hidden_until_visited'] = true;
                }
                if ((int) ($l['has_job_board'] ?? 0) === 1) {
                    $loc['job_board'] = true;
                }

                $loc['description'] = $l['description'];
                if (($l['first_visit_text'] ?? null) !== null && $l['first_visit_text'] !== '') {
                    $loc['first_visit'] = $l['first_visit_text'];
                }
                $ambience = json_decode((string) ($l['ambience_json'] ?? ''), true);
                if (is_array($ambience) && $ambience) {
                    $loc['ambience'] = array_values($ambience);
                }

                $exitStmt->execute([(int) $l['id']]);
                $exits = [];
                foreach ($exitStmt->fetchAll() as $e) {
                    $exit = ['to' => $e['to_key'], 'label' => $e['label']];
                    $conditions = json_decode((string) ($e['conditions_json'] ?? ''), true);
                    if (is_array($conditions) && $conditions) {
                        $exit['conditions'] = $conditions;
                    }
                    if ((int) $e['is_hidden'] === 1) {
                        $exit['hidden'] = true;
                    }
                    $exits[] = $this->compactObject($exit);
                }
                if ($exits) {
                    // One exit per line, whole. An exit is two short fields and
                    // reads as a sentence; expanded it becomes five lines of
                    // punctuation and the graph stops being visible in the file.
                    $loc['exits'] = $this->inline(
                        "[\n" . implode(",\n", array_map(
                            static fn(string $e): string => str_repeat(' ', 16) . $e,
                            $exits
                        )) . "\n" . str_repeat(' ', 12) . ']'
                    );
                }

                $lootStmt = $this->db->prepare(
                    'SELECT i.item_key FROM location_items li
                      INNER JOIN items i ON i.id = li.item_id
                     WHERE li.location_id = ? ORDER BY i.item_key'
                );
                $lootStmt->execute([(int) $l['id']]);
                $loot = $lootStmt->fetchAll(PDO::FETCH_COLUMN);
                if ($loot) {
                    $loc['loot'] = array_values(array_unique($loot));
                }

                $locations[$l['location_key']] = $loc;
            }
            $out['locations'] = $locations;

            $this->write('locations/' . $r['region_key'] . '.json', $out);
            $count++;
        }
        return $count;
    }

    /**
     * Stand a value in for text json_encode() will not produce.
     *
     * JSON_PRETTY_PRINT has one layout and it is the wrong one for two things
     * here: a coordinate pair and an exit both belong on a single line. The
     * value is written as a placeholder string and swapped for the real text
     * after encoding, which keeps the structure in PHP arrays — where it can be
     * reasoned about — rather than in string concatenation.
     */
    private function inline(string $text): string
    {
        $token = '@@inline:' . count($this->inline) . '@@';
        $this->inline[$token] = $text;
        return $token;
    }

    /** One object on one line, spaced the way these files are authored. */
    private function compactObject(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $this->scalar((string) $key) . ': ' . $this->scalar($value);
        }
        return '{' . implode(', ', $parts) . '}';
    }

    private function scalar($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Cannot encode a value: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * DECIMAL comes back from PDO as a string — "45.00", which is an int in
     * every file that was ever authored. load_content.py checks map_pos with
     * want_int, so writing 45.00 would export a world it then refuses to load.
     *
     * @return int|float
     */
    private function number($value)
    {
        $f = (float) $value;
        return $f == floor($f) ? (int) $f : $f;
    }

    // =======================================================================
    // NPCs
    // =======================================================================

    /**
     * One file per NPC, in the shape tools/load_content.py expects to read.
     *
     * Only keys that carry something are emitted. A file full of nulls and
     * falses round-trips identically but reads as noise in a diff, and the
     * point of exporting to JSON rather than to SQL is that somebody can see
     * what changed.
     */
    public function exportNpcs(): int
    {
        $rows = $this->db->query(
            'SELECT n.*, l.location_key
               FROM npcs n
               LEFT JOIN locations l ON l.id = n.location_id
              WHERE n.npc_key IS NOT NULL
              ORDER BY n.npc_key'
        )->fetchAll();

        $shops = $this->shopsByNpc();
        $count = 0;

        foreach ($rows as $r) {
            $out = ['npc_key' => $r['npc_key'], 'name' => $r['name']];

            foreach (['role', 'description', 'sprite_key', 'pose'] as $k) {
                if (($r[$k] ?? null) !== null && $r[$k] !== '') {
                    $out[$k] = $r[$k];
                }
            }
            // image_url is written only when it is not simply the sprite key's
            // own portrait. The importer derives that default, so exporting it
            // every time would add a line to thirty-three files that says
            // nothing and would then have to be kept in step by hand if the
            // convention ever changed.
            $derived = 'assets/images/npcs/' . ($r['sprite_key'] ?? '') . '.png';
            if (($r['image_url'] ?? null) !== null && $r['image_url'] !== '' && $r['image_url'] !== $derived) {
                $out['image_url'] = $r['image_url'];
            }
            foreach (['is_merchant', 'is_quest_giver', 'is_ambient'] as $k) {
                if ((int) ($r[$k] ?? 0) === 1) {
                    $out[$k] = true;
                }
            }

            // `dialog` names a file in content/dialog/, keyed by npc_key —
            // that is the convention exportDialogue() writes to.
            if (($r['dialogue_json'] ?? '') !== '' && $r['dialogue_json'] !== null) {
                $out['dialog'] = $r['npc_key'];
            }
            if (isset($shops[$r['npc_key']])) {
                $out['shop'] = $shops[$r['npc_key']];
            }
            // A person stands in at most one place, so placement is a column on
            // their own row and a location key in the file. Standing nowhere is
            // legitimate — companions exist as rows before they are met, and
            // recruiting one takes them out of the scene — so a NULL is an
            // omitted key rather than an error.
            if ($r['location_key'] !== null) {
                $out['place'] = ['location' => $r['location_key']];
            }

            $this->write('npcs/' . $r['npc_key'] . '.json', $out);
            $count++;
        }
        return $count;
    }

    /** @return array<string, array<int, array>> npc_key => the shop block */
    private function shopsByNpc(): array
    {
        $rows = $this->db->query(
            // By insertion order, not alphabetically: the import writes these
            // rows in the order the file lists them, so this preserves the
            // order somebody chose rather than imposing one and making every
            // first export a reshuffle in the diff.
            'SELECT s.npc_key, i.item_key, s.stock, s.markup_pct
               FROM shop_inventory s
               INNER JOIN items i ON i.id = s.item_id
              ORDER BY s.npc_key, s.id'
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $entry = ['item' => $r['item_key']];
            // Defaults are omitted, matching how these files were authored: a
            // markup of 100 and unlimited stock are what you get by saying
            // nothing, so saying it adds only noise.
            if ($r['stock'] !== null) {
                $entry['stock'] = (int) $r['stock'];
            }
            if ((int) $r['markup_pct'] !== 100) {
                $entry['markup_pct'] = (int) $r['markup_pct'];
            }
            $out[$r['npc_key']][] = $entry;
        }
        return $out;
    }

    // =======================================================================
    // Dialogue
    // =======================================================================

    /**
     * `npcs.dialogue_json` back out to content/dialog/<npc_key>.json.
     *
     * Stored as one MEDIUMTEXT blob per NPC rather than as a table, which makes
     * this the easiest of the three: the column already holds the document the
     * file is supposed to contain. It is re-encoded rather than copied so the
     * file comes out pretty-printed and a diff is per-line.
     */
    public function exportDialogue(): int
    {
        $rows = $this->db->query(
            "SELECT npc_key, dialogue_json FROM npcs
              WHERE npc_key IS NOT NULL AND dialogue_json IS NOT NULL AND dialogue_json <> ''
              ORDER BY npc_key"
        )->fetchAll();

        $count = 0;
        foreach ($rows as $r) {
            $doc = json_decode((string) $r['dialogue_json'], true);
            if (!is_array($doc)) {
                // Unreadable JSON in the column is worth stopping for. Writing
                // the raw string out would produce a file the loader rejects,
                // and skipping silently would drop a conversation.
                throw new RuntimeException(
                    "{$r['npc_key']} has dialogue that is not valid JSON; export stopped."
                );
            }
            // The file format carries the key; the column does not need to.
            $out = ['npc_key' => $r['npc_key']] + $doc;
            $this->write('dialog/' . $r['npc_key'] . '.json', $out);
            $count++;
        }
        return $count;
    }

    // =======================================================================
    // Quests
    // =======================================================================

    /**
     * A quest and its stages, as one file.
     *
     * Stages are a keyed object rather than a list, which is how they were
     * authored: a stage is referred to by `stage_key` from effects and from
     * other stages, so a list would put the identifier one level away from the
     * thing that uses it. `sort_order` is implied by position and re-derived on
     * import, so it is not written.
     */
    public function exportQuests(): int
    {
        // The giver and the reward item are stored as ids and authored as keys,
        // which is the rule docs/CONTENT.md states for every reference: a
        // numeric id must never reach a JSON file, or reordering an import
        // silently repoints it at something else.
        $quests = $this->db->query(
            'SELECT q.*, g.npc_key AS giver_key, ri.item_key AS reward_item_key
               FROM quests q
               LEFT JOIN npcs g ON g.id = q.giver_npc_id
               LEFT JOIN items ri ON ri.id = q.reward_item_id
              WHERE q.quest_key IS NOT NULL
              ORDER BY q.quest_key'
        )->fetchAll();

        $stagesStmt = $this->db->prepare(
            'SELECT s.*, l.location_key AS target_location_key
               FROM quest_stages s
               LEFT JOIN locations l ON l.id = s.target_location_id
              WHERE s.quest_id = ? ORDER BY s.sort_order, s.id'
        );

        $count = 0;
        foreach ($quests as $q) {
            $out = ['quest_key' => $q['quest_key'], 'title' => $q['title']];

            if ((int) $q['act'] !== 1) {
                $out['act'] = (int) $q['act'];
            }
            foreach (['description' => 'description', 'companion_key' => 'companion'] as $col => $key) {
                if (($q[$col] ?? null) !== null && $q[$col] !== '') {
                    $out[$key] = $q[$col];
                }
            }
            if ($q['giver_key'] !== null) {
                $out['giver'] = $q['giver_key'];
            }
            $out['on_job_board'] = (bool) $q['on_job_board'];
            if ((int) ($q['required_level'] ?? 1) > 1) {
                $out['required_level'] = (int) $q['required_level'];
            }
            if ((int) ($q['is_active'] ?? 1) !== 1) {
                $out['is_active'] = false;
            }

            // Three columns in the table, one object in the file. Omitted
            // entirely when there is nothing to give, so a quest without a
            // reward does not carry an empty block explaining that.
            $rewards = [];
            if ((int) ($q['reward_xp'] ?? 0) > 0) {
                $rewards['xp'] = (int) $q['reward_xp'];
            }
            if ((int) ($q['reward_gold'] ?? 0) > 0) {
                $rewards['gold'] = (int) $q['reward_gold'];
            }
            if ($q['reward_item_key'] !== null) {
                $rewards['item'] = $q['reward_item_key'];
            }
            if ($rewards) {
                $out['rewards'] = $rewards;
            }

            $stagesStmt->execute([(int) $q['id']]);
            $stages = [];
            foreach ($stagesStmt->fetchAll() as $s) {
                // The file's names are not the column's names, and guessing
                // cost a round trip: the columns are `journal_entry` and
                // `is_terminal`, the authored keys are `journal` and
                // `terminal`, and load_content.py rejects anything it does not
                // recognise rather than ignoring it. STAGE_FIELDS in
                // tools/load_content.py is the list this has to match.
                $stage = ['title' => $s['title']];
                foreach (['objective' => 'objective', 'journal_entry' => 'journal', 'resolution' => 'resolution'] as $col => $key) {
                    if (($s[$col] ?? null) !== null && $s[$col] !== '') {
                        $stage[$key] = $s[$col];
                    }
                }
                if ((int) $s['is_terminal'] === 1) {
                    $stage['terminal'] = true;
                    // Only meaningful on a terminal stage, and 'success' is the
                    // column default, so it is written only when it says
                    // something the default does not.
                    if (($s['outcome'] ?? 'success') !== 'success') {
                        $stage['outcome'] = $s['outcome'];
                    }
                }
                // Where the tracker points while this stage is current: a
                // location, by key. `quests.target_location_id` is not written
                // anywhere — the import derives it from the first stage that
                // declares a target, and authoring it twice is how the two come
                // to disagree.
                if ($s['target_location_key'] !== null) {
                    $stage['target'] = ['location' => $s['target_location_key']];
                }
                $effects = json_decode((string) ($s['effects_json'] ?? ''), true);
                if (is_array($effects) && $effects) {
                    $stage['effects'] = $effects;
                }
                $stages[$s['stage_key']] = $stage;
            }
            $out['stages'] = $stages;

            $this->write('quests/' . $q['quest_key'] . '.json', $out);
            $count++;
        }
        return $count;
    }

    /**
     * Fights the studio (or a later editor) wrote into the database.
     *
     * Files that already exist under content/encounters/ stay file-owned —
     * rewriting them would be a fifty-file formatting diff of fights nobody
     * edited. A key with no file is a fight that only lives in the database,
     * and that is what this writes so Export can commit it.
     */
    public function exportEncounters(): int
    {
        $rows = $this->db->query(
            'SELECT e.*, l.location_key, r.region_key
               FROM encounters e
               LEFT JOIN locations l ON l.id = e.location_id
               LEFT JOIN regions r ON r.id = e.region_id
              WHERE e.encounter_key IS NOT NULL
              ORDER BY e.encounter_key'
        )->fetchAll();
        $mons = $this->db->prepare(
            'SELECT m.monster_key, em.quantity, em.rank_override
               FROM encounter_monsters em
               INNER JOIN monsters m ON m.id = em.monster_id
              WHERE em.encounter_id = ?
              ORDER BY em.id'
        );

        $count = 0;
        foreach ($rows as $e) {
            $rel = 'encounters/' . $e['encounter_key'] . '.json';
            if (is_file($this->root . '/' . $rel)) {
                continue;
            }
            $mons->execute([(int) $e['id']]);
            $roster = [];
            foreach ($mons->fetchAll() as $m) {
                $row = ['monster' => $m['monster_key'], 'quantity' => (int) $m['quantity']];
                if ($m['rank_override']) {
                    $row['rank'] = $m['rank_override'];
                }
                $roster[] = $row;
            }
            if ($roster === []) {
                continue;
            }
            $out = [
                'encounter_key' => $e['encounter_key'],
                'name'          => $e['name'],
            ];
            if (($e['description'] ?? '') !== '') {
                $out['description'] = $e['description'];
            }
            if (($e['difficulty'] ?? '') !== '') {
                $out['difficulty'] = $e['difficulty'];
            }
            $out['monsters'] = $roster;
            if ((int) $e['is_random'] === 1) {
                $out['is_random'] = true;
            }
            if ((int) $e['is_ambush'] === 1) {
                $out['ambush'] = true;
            }
            if ((int) $e['allow_flee'] === 0) {
                $out['allow_flee'] = false;
            }
            if ((int) $e['allow_parley'] === 1) {
                $out['allow_parley'] = true;
            }
            foreach (['parley_node', 'victory_flag', 'defeat_flag', 'victory_quest_stage'] as $k) {
                if (($e[$k] ?? '') !== '') {
                    $out[$k] = $e[$k];
                }
            }
            if ((int) $e['scale_to_party'] === 0) {
                $out['scale'] = false;
            }
            if ($e['location_key']) {
                $out['place'] = ['location' => $e['location_key']];
            }
            if ($e['region_key'] && (int) $e['is_random'] === 1) {
                $out['region'] = $e['region_key'];
            }
            $this->write($rel, $out);
            $count++;
        }
        return $count;
    }

    // =======================================================================
    // Writing
    // =======================================================================

    /**
     * Write one file, and only if it would change.
     *
     * Comparing before writing keeps mtimes still for everything that did not
     * move, so `ls -lt content/npcs` after an export shows what the edit
     * touched. It also stops an export from turning into a hundred-file diff
     * that has to be read to discover it says nothing.
     */
    private function write(string $relative, array $data): void
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        // JSON_UNESCAPED_UNICODE and _SLASHES because these files are read by
        // people: an escaped apostrophe or a \/ in every image path makes the
        // dialogue unreadable in a diff for no gain.
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException("Cannot encode {$relative}: " . json_last_error_msg());
        }
        // The placeholders inline() left behind, swapped for the text they
        // stand for. Quoted on both sides so a token can only match where a
        // whole string value sits, never inside authored prose.
        foreach ($this->inline as $token => $text) {
            $json = str_replace('"' . $token . '"', $text, $json);
        }
        $this->inline = [];
        $json .= "\n";

        if (is_file($path) && file_get_contents($path) === $json) {
            return;
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException(
                "Cannot write {$path}. The web user needs write access to content/ for exporting to work."
            );
        }
        $this->written[] = $relative;
    }

    /** Whether exporting could work at all, checked before anything is offered. */
    public function writable(): bool
    {
        return is_dir($this->root) && is_writable($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }
}
