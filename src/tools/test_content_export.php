<?php
/**
 * The database is the source of truth — so it had better be readable.
 *
 * Run with:
 *   docker compose exec -T php php /var/www/html/tools/test_content_export.php
 *
 * Two things are checked, and both are regressions that already happened once:
 *
 *  1. No double-encoded text anywhere. The initdb script piped SQL into `mysql`
 *     with no --default-character-set, so the client used latin1 and every
 *     multi-byte character was read as two and re-encoded. Thirteen rows carried
 *     an em-dash rendered as "â€”" and nobody noticed, because it reads as a
 *     typo. Now that the database is authoritative and gets exported to files,
 *     that corruption would be written into the source of truth.
 *
 *  2. The exporter emits field names the loader accepts. The columns and the
 *     authored keys are not the same words — `journal_entry` versus `journal`,
 *     `is_terminal` versus `terminal` — and tools/load_content.py rejects
 *     unknown fields rather than ignoring them, so a mismatch is 67 errors and
 *     an export nobody can load back.
 *
 * Read-only. Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$RESULTS = ['pass' => 0, 'fail' => 0];

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $RESULTS;
    if ($condition) {
        $RESULTS['pass']++;
        return;
    }
    $RESULTS['fail']++;
    echo 'FAIL  ' . $name . ($detail === '' ? '' : '  (' . $detail . ')') . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

$db = db();

// ---------------------------------------------------------------------------
section('Nothing in the database is double-encoded');

/*
 * C3A2 E282AC is what "â€" looks like as bytes, and it is the signature of a
 * UTF-8 character that has been through latin1 and back. Correctly stored text
 * never contains it.
 */
$suspects = [
    'npcs.dialogue_json'        => 'SELECT COUNT(*) FROM npcs WHERE HEX(dialogue_json) LIKE ?',
    'npcs.description'          => 'SELECT COUNT(*) FROM npcs WHERE HEX(description) LIKE ?',
    'quests.description'        => 'SELECT COUNT(*) FROM quests WHERE HEX(description) LIKE ?',
    'quest_stages.journal_entry' => 'SELECT COUNT(*) FROM quest_stages WHERE HEX(journal_entry) LIKE ?',
    'quest_stages.objective'    => 'SELECT COUNT(*) FROM quest_stages WHERE HEX(objective) LIKE ?',
    'items.description'         => 'SELECT COUNT(*) FROM items WHERE HEX(description) LIKE ?',
    // The location graph carries more authored prose than anything else now —
    // a description and an arrival paragraph per room — so it is the largest
    // surface for this, not an afterthought.
    'locations.description'     => 'SELECT COUNT(*) FROM locations WHERE HEX(description) LIKE ?',
    'locations.first_visit_text' => 'SELECT COUNT(*) FROM locations WHERE HEX(first_visit_text) LIKE ?',
    'locations.ambience_json'   => 'SELECT COUNT(*) FROM locations WHERE HEX(ambience_json) LIKE ?',
    'location_exits.label'      => 'SELECT COUNT(*) FROM location_exits WHERE HEX(label) LIKE ?',
    'regions.description'       => 'SELECT COUNT(*) FROM regions WHERE HEX(description) LIKE ?',
];
foreach ($suspects as $label => $sql) {
    $stmt = $db->prepare($sql);
    $stmt->execute(['%C3A2E282AC%']);
    $n = (int) $stmt->fetchColumn();
    ok("{$label} is clean", $n === 0, "{$n} row(s) double-encoded");
}

// The positive case: the em-dash that was corrupted is a real em-dash now.
$stmt = $db->query("SELECT dialogue_json FROM npcs WHERE npc_key = 'street_scholar'");
$raw = (string) $stmt->fetchColumn();
ok('a known em-dash survives as one character', str_contains($raw, "\u{2014}"), 'not found in street_scholar');

// ---------------------------------------------------------------------------
section('The exporter speaks the loader\'s vocabulary');

/*
 * Mirrors FIELDS and STAGE_FIELDS in tools/load_content.py. Duplicated rather
 * than parsed out of the Python, because the point is to notice when the two
 * drift apart — reading the answer from the thing being checked would agree
 * with it by construction.
 */
$npcFields   = ['npc_key', 'name', 'role', 'description', 'image_url', 'sprite_key',
                'dialog', 'is_merchant', 'is_quest_giver', 'is_ambient', 'pose', 'shop', 'place'];
$questFields = ['quest_key', 'title', 'stages', 'act', 'description', 'giver',
                'companion', 'on_job_board', 'required_level', 'rewards', 'is_active'];
$stageFields = ['title', 'objective', 'journal', 'target', 'terminal', 'resolution',
                'outcome', 'effects'];

// Export into a scratch directory rather than over content/, so a test run
// never touches the authored files.
$tmp = sys_get_temp_dir() . '/rpg-export-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/npcs', 0o775, true);
mkdir($tmp . '/dialog', 0o775, true);
mkdir($tmp . '/quests', 0o775, true);

try {
    $exporter = new ContentExporter($db, $tmp);
    $counts = $exporter->exportAll()['counts'];

    ok('some NPCs were exported', $counts['npcs'] > 0, (string) $counts['npcs']);
    ok('some quests were exported', $counts['quests'] > 0, (string) $counts['quests']);

    $badNpc = [];
    foreach (glob($tmp . '/npcs/*.json') as $f) {
        foreach (array_keys(json_decode(file_get_contents($f), true) ?: []) as $k) {
            if (!in_array($k, $npcFields, true)) {
                $badNpc[] = basename($f) . ':' . $k;
            }
        }
    }
    ok('every NPC field is one the loader knows', $badNpc === [], implode(', ', array_slice($badNpc, 0, 5)));

    $badQuest = [];
    $badStage = [];
    foreach (glob($tmp . '/quests/*.json') as $f) {
        $q = json_decode(file_get_contents($f), true) ?: [];
        foreach (array_keys($q) as $k) {
            if (!in_array($k, $questFields, true)) {
                $badQuest[] = basename($f) . ':' . $k;
            }
        }
        foreach (($q['stages'] ?? []) as $key => $stage) {
            foreach (array_keys($stage) as $k) {
                if (!in_array($k, $stageFields, true)) {
                    $badStage[] = basename($f) . ':' . $key . '.' . $k;
                }
            }
        }
    }
    ok('every quest field is one the loader knows', $badQuest === [], implode(', ', array_slice($badQuest, 0, 5)));
    ok('every stage field is one the loader knows', $badStage === [], implode(', ', array_slice($badStage, 0, 5)));

    // A quest with no terminal stage can be started and never finished, which
    // load_content.py refuses. Losing `terminal` in export is exactly how that
    // happens, so it is checked here rather than discovered at import.
    $noEnd = [];
    foreach (glob($tmp . '/quests/*.json') as $f) {
        $q = json_decode(file_get_contents($f), true) ?: [];
        $terminal = false;
        foreach (($q['stages'] ?? []) as $stage) {
            if (!empty($stage['terminal'])) {
                $terminal = true;
            }
        }
        if (!$terminal) {
            $noEnd[] = basename($f);
        }
    }
    ok('every exported quest keeps an ending', $noEnd === [], implode(', ', $noEnd));

    // Ids must never reach a file: they are assigned by the import, so a file
    // carrying one silently repoints the moment content is reordered.
    $withIds = [];
    foreach (glob($tmp . '/*/*.json') as $f) {
        $data = json_decode(file_get_contents($f), true) ?: [];
        $forbidden = ['id', 'npc_id', 'quest_id', 'item_id', 'giver_npc_id',
                      'location_id', 'region_id', 'from_location_id', 'to_location_id',
                      'target_location_id'];
        foreach ($forbidden as $k) {
            if (array_key_exists($k, $data)) {
                $withIds[] = basename($f) . ':' . $k;
            }
        }
    }
    ok('no numeric ids were written to a file', $withIds === [], implode(', ', $withIds));

    // Dialogue must come out as JSON somebody can load, not as a quoted blob.
    $badDialog = [];
    foreach (glob($tmp . '/dialog/*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!is_array($d) || !isset($d['npc_key']) || !isset($d['nodes'])) {
            $badDialog[] = basename($f);
        }
    }
    ok('every conversation exports as a readable document', $badDialog === [], implode(', ', $badDialog));
} finally {
    foreach (glob($tmp . '/*/*.json') as $f) {
        unlink($f);
    }
    foreach (['npcs', 'dialog', 'quests', 'locations'] as $d) {
        @rmdir($tmp . '/' . $d);
    }
    @rmdir($tmp);
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
