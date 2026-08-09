<?php
/**
 * The adventure book: does one module's gathering hold together, and does it
 * stay inside its own module?
 *
 * NEEDS the database — a book is a dozen joins over authored content — and
 * reads only, so it is safe against a database somebody is playing on.
 *
 * The boundary assertions are the point. CLAUDE.md's rule is that modules are
 * kept apart by the exit graph and nothing else, and that anything gathering
 * content has to be asked about the module; a printed book that quietly
 * included the Old City's cast in Rivermark's appendix would be that fault in
 * a form nobody would notice until it was on paper.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$book = new AdventureBook($db);

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $n\n"; return; }
    $fail++;
    echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

echo "\nThe catalogue\n";
$cat = $book->catalogue();
ok('every module can be picked', count($cat) >= 3, count($cat) . ' listed');
same('a module that does not exist has no book', null, $book->build('_not_a_module'));

foreach (['rivermark', 'old_city', 'undervault'] as $key) {
    $b = $book->build($key);
    echo "\n{$key}\n";
    ok('there is a book', $b !== null);
    if ($b === null) { continue; }

    ok('it has chapters', $b['counts']['regions'] >= 1, (string) $b['counts']['regions']);
    ok('and areas in them', $b['counts']['locations'] >= 4, (string) $b['counts']['locations']);

    // Numbering is what the cross-references in the text lean on.
    $numbered = true;
    foreach ($b['regions'] as $r) {
        foreach (array_values($r['locations']) as $i => $l) {
            if ((int) $l['number'] !== $i + 1) { $numbered = false; }
        }
    }
    ok('every area is numbered from one within its chapter', $numbered);

    // The number on the chapter map is placed from `map_x`/`map_y`, which are
    // positions in the chart's 0–100 by 0–75 field — the same two columns the
    // game draws the node from, so a pin and a place-name cannot disagree. An
    // area with no position gets no pin, and an area outside the field gets one
    // off the edge of the paper.
    $unplaced = [];
    $offField = [];
    foreach ($b['regions'] as $r) {
        foreach ($r['locations'] as $l) {
            if ($l['map_x'] === null || $l['map_y'] === null) {
                $unplaced[] = $l['location_key'];
                continue;
            }
            $x = (float) $l['map_x'];
            $y = (float) $l['map_y'];
            if ($x < 0 || $x > 100 || $y < 0 || $y > 75) {
                $offField[] = $l['location_key'] . " ({$x},{$y})";
            }
        }
    }
    same('every area has somewhere to put its number', [], $unplaced);
    same('and it is inside the field the plate is drawn into', [], $offField);

    // --- the boundary -----------------------------------------------------
    // Everything printed has to belong to this module. Asked of the database
    // rather than of the book, so the answer cannot come from the same joins
    // that built it.
    $mine = $db->prepare(
        'SELECT l.id FROM locations l
           INNER JOIN regions r ON r.id = l.region_id
           INNER JOIN modules m ON m.id = r.module_id
          WHERE m.module_key = ?'
    );
    $mine->execute([$key]);
    $ours = array_fill_keys(array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN)), true);

    $strayCast = 0;
    foreach ($b['cast'] as $p) {
        $at = $db->prepare('SELECT location_id FROM npcs WHERE npc_key = ?');
        $at->execute([$p['npc_key']]);
        if (!isset($ours[(int) $at->fetchColumn()])) { $strayCast++; }
    }
    same('nobody in the cast stands in another module', 0, $strayCast);

    $strayEnc = 0;
    foreach ($b['encounters'] as $e) {
        $lid = (int) ($e['location_id'] ?? 0);
        if ($lid > 0 && !isset($ours[$lid])) { $strayEnc++; }
    }
    same('no encounter is somewhere else', 0, $strayEnc);

    ok('no generated delve floor is printed',
        count(array_filter($b['encounters'],
            fn ($e) => str_starts_with((string) $e['encounter_key'], '_dg_'))) === 0);

    // --- the bestiary -----------------------------------------------------
    // Monsters are deliberately NOT scoped to a module — a goblin is a goblin —
    // so the book has to reach them through its own encounters or it prints the
    // whole bestiary.
    $sent = [];
    foreach ($b['encounters'] as $e) {
        foreach ($e['roster'] as $m) { $sent[$m['monster_key']] = true; }
    }
    $printed = array_column($b['monsters'], 'monster_key');
    sort($printed);
    $expected = array_keys($sent);
    sort($expected);
    same('the bestiary is exactly what this module sends', $expected, $printed);

    $all = (int) $db->query('SELECT COUNT(*) FROM monsters')->fetchColumn();
    ok('and not the whole bestiary', count($printed) < $all || $all === 0,
        count($printed) . ' of ' . $all);

    // --- stat blocks ------------------------------------------------------
    $blocksOk = true;
    foreach ($b['monsters'] as $m) {
        // Both shapes of the traits/actions columns decode to a list of
        // {name, text} — the seeded bestiary holds prose, the authored one
        // holds JSON, and a stat block that printed `[{"name":…` guessed wrong.
        foreach (['traits_list', 'actions_list'] as $field) {
            foreach ($m[$field] as $entry) {
                if (!array_key_exists('name', $entry) || !array_key_exists('text', $entry)) {
                    $blocksOk = false;
                }
                if (str_starts_with(trim((string) $entry['text']), '[{')) { $blocksOk = false; }
            }
        }
    }
    ok('every stat block reads its traits and actions', $blocksOk);

    // The bestiary's `actions` column is the row CombatEngine swings with, not
    // prose — {name, type, bonus, damage, damage_type}. Printed raw it comes
    // out as a bare "Scimitar." with nothing after it, which is what the first
    // draft of this book did for every creature in it.
    $mute = [];
    foreach ($b['monsters'] as $m) {
        foreach ($m['actions_list'] as $a) {
            if (trim((string) $a['text']) === '') {
                $mute[] = $m['name'] . ': ' . $a['name'];
            }
        }
    }
    same('and every action says what it does', [], $mute);

    // --- quests -----------------------------------------------------------
    $stagedOk = true;
    foreach ($b['quests'] as $q) {
        if (!is_array($q['stages'])) { $stagedOk = false; }
    }
    ok('every quest carries its stages', $stagedOk);
}

// ---------------------------------------------------------------------------
// A module whose levels are made at play
// ---------------------------------------------------------------------------
echo "\nThe rolled delve\n";

// A generated floor is written into the Undervault's own module id and keyed
// `_dg_<party>_<depth>`. It is one party's scratch, it exists for hours, and it
// used to arrive in the book as an extra chapter — so the printed adventure
// changed depending on who happened to be underground. Three parties down meant
// three chapters of somebody else's rooms, with their traps and secret doors.
$db->beginTransaction();
try {
    $moduleId = (int) $db->query(
        "SELECT id FROM modules WHERE module_key = '" . DelveEngine::MODULE_KEY . "'"
    )->fetchColumn();
    $mouth = (int) $db->query(
        "SELECT id FROM locations WHERE location_key = 'uv_mouth'"
    )->fetchColumn();

    $before = count($book->build(DelveEngine::MODULE_KEY, 1)['regions']);
    foreach ([1, 2] as $n) {
        $db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL,?,?)')
            ->execute([$moduleId, "book delve probe {$n}"]);
        $partyId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
                constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
                proficiency_bonus, is_active, current_location_id)
             VALUES (?, 'Human', 'Fighter', 3, 0, 12, 12, 12, 12, 12, 12, 25, 25, 14, 2, 1, ?)"
        )->execute(['_book_probe_' . bin2hex(random_bytes(3)), $mouth]);
        $characterId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')
            ->execute([$partyId, $characterId]);
        $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')
            ->execute([$characterId, $partyId]);
        (new DelveEngine($db))->descend($characterId, $partyId);
    }

    $floors = (int) $db->query(
        "SELECT COUNT(*) FROM regions WHERE region_key LIKE '\\_dg\\_%'"
    )->fetchColumn();
    ok('two parties are really underground', $floors >= 2, (string) $floors);

    $b = $book->build(DelveEngine::MODULE_KEY, 1);
    same('and the book gained no chapters', $before, count($b['regions']));
    $scratch = array_values(array_filter(
        array_map(static fn ($r) => (string) $r['region_key'], $b['regions']),
        static fn (string $k) => str_starts_with($k, '_dg_')
    ));
    same('no generated floor is in it', [], $scratch);
} finally {
    $db->rollBack();
}

// What a book SHOULD say about a generated dungeon: one rolled for the page,
// with the number that made it, so the same book can be printed again.
$rolled = $book->build(DelveEngine::MODULE_KEY, 4471)['delve'];
ok('the Undervault prints a specimen delve', !empty($rolled['floors']));
same('one seed, and it is the one asked for', 4471, $rolled['seed'] ?? null);
same('every floor of the delve', DelveEngine::MAX_DEPTH, count($rolled['floors'] ?? []));

$sameAgain = $book->build(DelveEngine::MODULE_KEY, 4471)['delve'];
$other = $book->build(DelveEngine::MODULE_KEY, 4472)['delve'];
ok('the same seed reprints the same dungeon', json_encode($rolled) === json_encode($sameAgain));
ok('a different seed is a different dungeon', json_encode($rolled) !== json_encode($other));

$numbered = true;
$labelled = true;
$plans = true;
foreach (($rolled['floors'] ?? []) as $floor) {
    foreach (array_values($floor['rooms']) as $i => $room) {
        if ((int) $room['number'] !== $i + 1) { $numbered = false; }
        if (trim((string) $room['name']) === '') { $labelled = false; }
    }
    // The plan is handed to the game's own renderer, so it has to be the shape
    // that renderer reads: room shapes to draw, and a node per room carrying
    // the area number as its label.
    if (empty($floor['map']['floorplan']['rooms']) || empty($floor['map']['nodes'])) {
        $plans = false;
    }
    $labels = array_map(static fn ($n) => $n['name'], $floor['map']['nodes']);
    if ($labels !== array_map('strval', range(1, count($floor['rooms'])))) { $plans = false; }
    // No fog: a book is the GM's copy and draws the whole floor. The renderer
    // reads `seen === false` as "hide", so the flag must simply be absent.
    foreach ($floor['map']['floorplan']['rooms'] as $room) {
        if (array_key_exists('seen', $room)) { $plans = false; }
    }
}
ok('areas are numbered from one on every floor', $numbered);
ok('and every room is named', $labelled);
ok('every floor carries a plan the shipped renderer can draw', $plans);

$onlyHere = true;
foreach (['rivermark', 'old_city'] as $key) {
    if ($book->build($key, 4471)['delve'] !== []) { $onlyHere = false; }
}
ok('no other module gets a dungeon it does not have', $onlyHere);

// --- the wandering table ---------------------------------------------------
//
// The game has wandering monsters down here already; what it does not have is
// a list anybody can read, because the pool is drawn by the travel roll and
// never shown. A book has to print one — the person running it is the die.
$tabled = true;
$distinct = true;
$odds = true;
$sized = true;
$ceiling = 0;
foreach ($book->catalogue() as $m) {
    if ($m['module_key'] === DelveEngine::MODULE_KEY) { $ceiling = (int) $m['level_max']; }
}
ok('the module advertises a level ceiling to size against', $ceiling > 0);
foreach (($rolled['floors'] ?? []) as $floor) {
    $table = $floor['wandering'];
    if (count($table['rows']) !== $table['die']) { $tabled = false; }
    // Six identical rows is a d1 wearing a d6's clothes. The picker takes the
    // top third of what the budget affords, which on a shallow floor is a
    // handful of creatures, so the rows have to be drawn until they differ.
    $names = array_map(static fn ($r) => $r['monster'], $table['rows']);
    if (count(array_unique($names)) !== count($names)) { $distinct = false; }
    // The odds printed are the odds the engine actually rolls, read from it
    // rather than restated: a book that told a referee the wrong chance would
    // be wrong where nobody can check it.
    if ($table['chance'] !== DelveEngine::wanderChance((int) $floor['depth'])) { $odds = false; }
    if ($floor['party']['level'] > $ceiling || $floor['party']['level'] < 1) { $sized = false; }
}
ok('every floor has a full wandering table', $tabled);
ok('and no two rows of it are the same creature', $distinct);
ok('the printed odds are the odds the engine rolls', $odds);
ok('the fights are sized for a party the module could have', $sized);

// --- the bestiary ----------------------------------------------------------
//
// The appendix is built from the encounters a module SENDS, and a generated
// module sends none — its fights are rolled, not stored. So the Undervault
// printed five levels of fights, thirty wandering rows and "0 creatures", with
// not one stat block for anything it named. A book you cannot run.
$uv = $book->build(DelveEngine::MODULE_KEY, 4471);
ok('a generated module still has a bestiary', count($uv['monsters']) > 0,
   count($uv['monsters']) . ' stat blocks');
ok('and its fights are counted', ($uv['counts']['rolled'] ?? 0) > 0,
   (string) ($uv['counts']['rolled'] ?? 0));

$statted = [];
foreach ($uv['monsters'] as $m) {
    $statted[(string) $m['monster_key']] = true;
}
$named = [];
$emptyRooms = [];
foreach ($uv['delve']['floors'] as $floor) {
    foreach ($floor['rooms'] as $room) {
        // Every room the stocking table calls occupied has to hold something,
        // or the entry promises a fight the referee has to invent.
        if (in_array($room['kind'], ['monster', 'hoard', 'boss'], true)) {
            if (empty($room['holds'])) {
                $emptyRooms[] = "L{$floor['depth']} area {$room['number']}";
            } else {
                $named[(string) $room['holds']['key']] = true;
            }
        }
    }
    foreach ($floor['wandering']['rows'] as $row) {
        $named[(string) $row['key']] = true;
    }
}
same('every occupied room holds something', [], $emptyRooms);
same('and everything the delve names has a stat block',
     [], array_values(array_diff(array_keys($named), array_keys($statted))));

// The table is part of the seed's promise, so it has to survive a reprint.
$tablesA = array_map(static fn ($f) => $f['wandering'], $rolled['floors']);
$tablesB = array_map(static fn ($f) => $f['wandering'], $sameAgain['floors']);
same('the same seed reprints the same wandering table', $tablesA, $tablesB);

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
