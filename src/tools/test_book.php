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

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
