<?php
/**
 * The name generator: does every people have names, and are they names?
 *
 * The headline assertion is the one that keeps this honest as the catalogue
 * grows: `races` is the whole catalogue and a row is a playable race, so a race
 * somebody adds without writing names for it must FAIL HERE rather than quietly
 * lose the button in the creator. That is the same move test_dungeon.php makes
 * for room names — the margin is asserted, so running out is a test failure and
 * not a duplicate on somebody's map.
 *
 * Everything else is cheap and static: NameGen has no PDO, so the sweep below
 * rolls tens of thousands of names in milliseconds.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}
function section(string $t): void
{
    echo PHP_EOL . '== ' . $t . ' ==' . PHP_EOL;
}

section('every playable row has names');
$rows = db()->query('SELECT name, subrace FROM races ORDER BY name, subrace')->fetchAll(PDO::FETCH_ASSOC);
ok('the catalogue is not empty', count($rows) > 0, count($rows) . ' rows');

$without = [];
foreach ($rows as $r) {
    if (!NameGen::has((string) $r['name'], $r['subrace'])) {
        $without[] = trim($r['name'] . ' ' . ($r['subrace'] ?? ''));
    }
}
ok('and every one of them can be named', $without === [],
    'no table for: ' . implode(', ', $without));

// The other direction: a table for a people nobody can play is dead weight, and
// more to the point is usually a typo in a race name that will never match.
$known = [];
foreach ($rows as $r) {
    $known[(string) $r['name']] = true;
    if ($r['subrace'] !== null && $r['subrace'] !== '') {
        $known[(string) $r['subrace']] = true;
    }
}
$orphans = array_values(array_filter(NameGen::races(), fn ($k) => !isset($known[$k])));
same('and no table names a people who is not in the catalogue', [], $orphans);

section('a rolled name is a name');
$peoples = NameGen::races();
$bad = [];
$short = [];
$untrimmed = [];
$wrongPool = [];
$seen = [];
$rolls = 0;

foreach ($rows as $r) {
    $race = (string) $r['name'];
    $sub = $r['subrace'];
    for ($i = 0; $i < 2000; $i++) {
        // A third of the rolls ask for nothing, so "either" is swept too.
        $gender = [null, 'masculine', 'feminine'][$i % 3];
        $name = NameGen::roll($race, $sub, $gender);
        $rolls++;
        if ($name === null) {
            $bad[] = "{$race}: null";
            break;
        }
        // The creator's own floor: two characters, and it has to survive the
        // trim() the field and the UNIQUE index both apply.
        if (mb_strlen($name) < 2) {
            $short[] = $name;
        }
        if (trim($name) !== $name || str_contains($name, '  ')) {
            $untrimmed[] = $name;
        }
        // 50 is the input's maxlength AND the column's width; a suggestion the
        // field would truncate is a suggestion that fails to save.
        if (mb_strlen($name) > 50) {
            $bad[] = "{$race}: too long — {$name}";
        }
        $seen[trim($race . '/' . ($sub ?? ''))][$name] = true;
        // A name asked for by gender has to come out of that pool. This is the
        // assertion that catches a pool renamed or dropped in one race's table,
        // which `given()` would otherwise paper over by merging what is left.
        if ($gender !== null) {
            $pool = NameGen::tableFor($race, $sub)[$gender];
            if (!in_array(explode(' ', $name)[0], $pool, true)) {
                $wrongPool[] = "{$race}/{$gender}: {$name}";
            }
        }
    }
}
same('nothing rolled null', [], array_slice($bad, 0, 4));
same('nothing rolled short', [], array_slice($short, 0, 4));
same('nothing rolled with stray spacing', [], array_slice($untrimmed, 0, 4));
same('a name asked for by gender comes from that pool', [], array_slice($wrongPool, 0, 4));
echo "  {$rolls} names rolled\n";

// Variety, which is the whole point of the feature: a player pressing the
// button twice must not keep getting the same person. The floor is deliberately
// well under given*family — the tables multiply out into the hundreds — because
// what is being caught here is a table that has collapsed to one entry, not a
// run of bad luck.
$thin = [];
foreach ($seen as $who => $names) {
    if (count($names) < 60) {
        $thin[] = $who . ' (' . count($names) . ')';
    }
}
same('and every people has a deep enough table', [], $thin);

section('gender picks a pool, and asking for neither picks both');
$thinPool = [];
foreach ($rows as $r) {
    $table = NameGen::tableFor((string) $r['name'], $r['subrace']);
    foreach (NameGen::GENDERS as $g) {
        if (!isset($table[$g]) || count($table[$g]) < 8) {
            $thinPool[] = trim($r['name'] . ' ' . ($r['subrace'] ?? '')) . '/' . $g;
        }
    }
}
same('every people has both pools, deep enough to vary', [], $thinPool);

// "Either" is the union, not a coin toss between two lists of different
// lengths — which would quietly over-weight whichever pool is shorter.
$human = NameGen::tableFor('Human');
same('asking for neither offers both pools at once',
    count($human['masculine']) + count($human['feminine']),
    count(NameGen::given($human)));
same('and asking for one offers only that one',
    $human['feminine'], NameGen::given($human, 'feminine'));
same('a gender nobody wrote a pool for falls back to either',
    NameGen::given($human), NameGen::given($human, 'wyvern'));

section('a subrace takes its own names, or its race\'s');
// The Dark Elf is the one row with its own tradition; the rest of the elves
// share. If that stops being true the fallback is what would hide it.
$drow = NameGen::tableFor('Elf', 'Dark Elf');
$wood = NameGen::tableFor('Elf', 'Wood Elf');
$high = NameGen::tableFor('Elf', 'High Elf');
ok('the dark elves have their own list', $drow !== null && $drow !== $wood);
same('and the other elves share one', $wood, $high);
same('a subrace with no list of its own falls back to the race',
    NameGen::tableFor('Elf'), $wood);
same('and an unknown people has none at all', null, NameGen::tableFor('Automaton'));
same('which rolls as null rather than as somebody else', null, NameGen::roll('Automaton'));

section('the tables are written, not borrowed');
// Not a licence check — no test can be one — but a guard on the thing that
// would actually go wrong: a name pasted in from somewhere with punctuation or
// casing this project does not use, which is the shape a copied list arrives
// in. Letters, spaces, apostrophes and hyphens only.
$odd = [];
foreach ($peoples as $who) {
    $table = NameGen::tableFor($who, $who);
    foreach (array_merge($table['masculine'], $table['feminine'], $table['family']) as $part) {
        if (!preg_match("/^[A-Z][A-Za-z'\\-]*$/", $part)) {
            $odd[] = "{$who}: {$part}";
        }
    }
}
same('every entry is one capitalised word', [], array_slice($odd, 0, 6));

$dupes = [];
foreach ($peoples as $who) {
    $table = NameGen::tableFor($who, $who);
    foreach (['masculine', 'feminine', 'family'] as $half) {
        $list = $table[$half];
        if (count($list) !== count(array_unique($list))) {
            $dupes[] = "{$who}/{$half}";
        }
    }
}
same('and no list repeats itself', [], $dupes);

$both = [];
foreach ($peoples as $who) {
    $table = NameGen::tableFor($who, $who);
    foreach (array_intersect($table['masculine'], $table['feminine']) as $name) {
        $both[] = "{$who}: {$name}";
    }
}
same('and no given name is in both pools of one people', [], $both);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
