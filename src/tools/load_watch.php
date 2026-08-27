<?php
/**
 * Load the watch-jobs content into the live database.
 *
 * Quests, stages, items, the cousin, dialogue, the inn's givers, and a
 * module_id repair for free-play parties that predate the column.
 *
 * Jobs are given in talk. The Proving House has no board.
 *
 *   docker compose exec -T php php /var/www/html/tools/load_watch.php
 */
declare(strict_types=1);

require_once '/var/www/html/app/bootstrap.php';

$db = db();
$root = '/var/www/html';

function load_json(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("missing {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("bad json {$path}: " . json_last_error_msg());
    }
    return $data;
}

$pitId = (int) $db->query("SELECT id FROM locations WHERE location_key = '_freeplay_cellar'")->fetchColumn();
$innId = (int) $db->query("SELECT id FROM locations WHERE location_key = '_freeplay_yard'")->fetchColumn();
if ($pitId <= 0 || $innId <= 0) {
    fwrite(STDERR, "Proving House / Pit missing.\n");
    exit(1);
}

$npcId = static function (PDO $db, string $key): int {
    $stmt = $db->prepare('SELECT id FROM npcs WHERE npc_key = ?');
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("NPC {$key} is not in the database");
    }
    return (int) $id;
};

// --- items ----------------------------------------------------------------
$items = [
    ['fp_cousin_note', 'A Hurried Note', 'Went further. do not follow. — C. A scrap of paper, folded twice, the hand shaking on the last letter.', 'misc', 0, 0],
    ['fp_sour_wine', 'A Green Bottle', 'Wine gone the colour of pondwater. The cork is still in. Nobody who has opened one has described the taste twice.', 'misc', 1, 2],
];
$insItem = $db->prepare(
    'INSERT INTO items (item_key, name, description, item_type, rarity, weight, value_gp, icon)
     VALUES (?, ?, ?, ?, \'common\', ?, ?, \'misc\')
     ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                             item_type = VALUES(item_type), weight = VALUES(weight),
                             value_gp = VALUES(value_gp)'
);
foreach ($items as $row) {
    $insItem->execute($row);
}
echo "items: fp_cousin_note, fp_sour_wine\n";

// --- cousin NPC -----------------------------------------------------------
$db->prepare(
    "INSERT INTO npcs (npc_key, name, role, description, sprite_key, bust_count,
                       is_merchant, is_quest_giver, is_ambient, location_id)
     VALUES ('_fp_cousin', 'Calder Vosk', 'Stayed',
             'He is Brenna Vosk\'s cousin. Thinner than she is, and he has been below long enough that the lamp looks like his. He sits with his back to the wall the way she does upstairs.',
             'token_human_m1', 1, 0, 0, 0, NULL)
     ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role),
                             description = VALUES(description), sprite_key = VALUES(sprite_key)"
)->execute();
echo "npc: _fp_cousin\n";

// --- inn: no board; givers talk -------------------------------------------
$db->prepare('UPDATE locations SET has_job_board = 0 WHERE id = ?')->execute([$innId]);
$db->prepare(
    "UPDATE npcs SET is_quest_giver = 1
      WHERE npc_key IN ('_fp_odd', '_fp_hessa', '_fp_brenna')"
)->execute();
echo "inn: has_job_board=0, givers marked\n";

// --- dialogue -------------------------------------------------------------
foreach (['_fp_odd', '_fp_hessa', '_fp_brenna', '_fp_cousin'] as $key) {
    $tree = load_json("{$root}/content/dialog/{$key}.json");
    $db->prepare('UPDATE npcs SET dialogue_json = ? WHERE npc_key = ?')
        ->execute([json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key]);
    echo "dialogue: {$key}\n";
}

// --- quests ---------------------------------------------------------------
$questFiles = [
    'fp_watch_crate', 'fp_watch_cousin', 'fp_watch_shrine',
    'fp_watch_wine', 'fp_watch_name', 'fp_watch_stayed',
];
$upsertQ = $db->prepare(
    'INSERT INTO quests
        (quest_key, title, description, act, on_job_board, giver_npc_id,
         required_level, reward_xp, reward_gold, target_location_id, is_active)
     VALUES (?, ?, ?, 1, ?, ?, 1, 0, 0, ?, 1)
     ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
                             on_job_board = VALUES(on_job_board),
                             giver_npc_id = VALUES(giver_npc_id),
                             reward_xp = 0, reward_gold = 0,
                             target_location_id = VALUES(target_location_id),
                             is_active = 1'
);
$upsertS = $db->prepare(
    'INSERT INTO quest_stages
        (quest_id, stage_key, title, objective, journal_entry,
         target_location_id, is_terminal, resolution, outcome, effects_json, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'success\', ?, ?)
     ON DUPLICATE KEY UPDATE title = VALUES(title), objective = VALUES(objective),
                             journal_entry = VALUES(journal_entry),
                             target_location_id = VALUES(target_location_id),
                             is_terminal = VALUES(is_terminal),
                             resolution = VALUES(resolution),
                             effects_json = VALUES(effects_json),
                             sort_order = VALUES(sort_order)'
);

foreach ($questFiles as $i => $key) {
    $q = load_json("{$root}/content/quests/{$key}.json");
    $giver = $npcId($db, (string) $q['giver']);
    $onBoard = !empty($q['on_job_board']) ? 1 : 0;
    $upsertQ->execute([
        $key,
        $q['title'],
        $q['description'] ?? '',
        $onBoard,
        $giver,
        $pitId,
    ]);
    $qid = (int) $db->query('SELECT id FROM quests WHERE quest_key = ' . $db->quote($key))->fetchColumn();
    $sort = 0;
    foreach ($q['stages'] as $sk => $s) {
        $effects = isset($s['effects']) ? json_encode($s['effects'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $upsertS->execute([
            $qid,
            $sk,
            $s['title'],
            $s['objective'] ?? null,
            $s['journal'] ?? null,
            $pitId,
            !empty($s['terminal']) ? 1 : 0,
            $s['resolution'] ?? null,
            $effects,
            $sort,
        ]);
        $sort += 10;
    }
    echo "quest: {$key} (board={$onBoard})\n";
}

// Watch jobs are given in talk. None of them pin to a wall.
$db->prepare(
    "UPDATE quests SET on_job_board = 0
      WHERE quest_key LIKE 'fp\\_watch\\_%'"
)->execute();
echo "board: none (jobs are given in talk)\n";

// --- free-play parties that predate module_id -----------------------------
$n = $db->exec(
    "UPDATE parties p
        INNER JOIN characters c ON c.id = p.leader_character_id
        INNER JOIN locations l ON l.id = c.current_location_id
        INNER JOIN regions r ON r.id = l.region_id
         SET p.module_id = r.module_id
       WHERE p.module_id IS NULL AND r.module_id IS NOT NULL"
);
echo "repaired {$n} parties with a null module_id\n";

echo "ok\n";
