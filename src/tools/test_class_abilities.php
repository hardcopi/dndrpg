<?php
/**
 * Rules::CLASS_ABILITIES against the classes table.
 *
 * The creator's abilities step marks one box "Primary" and one "Secondary".
 * The first of those is the SRD's own `classes.primary_ability`; the second is
 * this project's advice, and lives in code because the SRD has no such field.
 * Two homes for one subject is exactly the arrangement that drifts, so this is
 * what stops it: the advice may add to what the column says and may not
 * contradict it, and every class in the catalogue has to have a row.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_class_abilities.php`.
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

/** "Strength or Dexterity" and "Dexterity,Wisdom" are both lists. */
function abilitiesIn(string $field): array
{
    $short = array_flip(Rules::ABILITY_NAMES);
    $out = [];
    foreach (preg_split('/\s*(,|\bor\b|&|\/)\s*/i', $field) as $word) {
        $word = trim($word);
        if ($word !== '' && isset($short[$word])) {
            $out[] = $short[$word];
        }
    }
    return $out;
}

$rows = db()->query('SELECT name, primary_ability FROM classes ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

section('every class in the catalogue has advice');
ok('the catalogue is not empty', count($rows) > 0, count($rows) . ' classes');

$missing = [];
foreach ($rows as $r) {
    if (!isset(Rules::CLASS_ABILITIES[(string) $r['name']])) {
        $missing[] = (string) $r['name'];
    }
}
same('and a row in CLASS_ABILITIES', [], $missing);

// The other way, which is usually a class renamed in one place and not the other.
$known = array_column($rows, 'name');
$orphans = array_values(array_diff(array_keys(Rules::CLASS_ABILITIES), $known));
same('and no advice for a class nobody can play', [], $orphans);

section('the advice does not contradict the SRD column');
$parsed = 0;
$contradicts = [];
$dropped = [];
$noPrimary = [];
$sameTwice = [];
$badKey = [];

foreach ($rows as $r) {
    $name = (string) $r['name'];
    $row = Rules::CLASS_ABILITIES[$name] ?? null;
    if ($row === null) {
        continue;
    }
    $srd = abilitiesIn((string) ($r['primary_ability'] ?? ''));
    if ($srd === []) {
        $noPrimary[] = $name;
        continue;
    }
    $parsed++;

    // Every ability the advice calls primary must be one the SRD does. The
    // advice may narrow the SRD's list — a Monk's is "Dexterity & Wisdom" and
    // the creator says Dexterity first, Wisdom next — but it may not promote
    // something the rules never called primary at all.
    foreach ($row['primary'] as $key) {
        if (!in_array($key, $srd, true)) {
            $contradicts[] = "{$name}: {$key} is not in '{$r['primary_ability']}'";
        }
    }
    // And nothing the SRD names may vanish from both halves.
    foreach ($srd as $key) {
        if (!in_array($key, $row['primary'], true) && $row['secondary'] !== $key) {
            $dropped[] = "{$name}: {$key} is in the column and in neither half";
        }
    }
    if ($row['primary'] === []) {
        $noPrimary[] = $name;
    }
    if (in_array($row['secondary'], $row['primary'], true)) {
        $sameTwice[] = $name;
    }
    foreach (array_merge($row['primary'], [$row['secondary']]) as $key) {
        if (!in_array($key, Rules::ABILITIES, true)) {
            $badKey[] = "{$name}: {$key}";
        }
    }
}
echo "  {$parsed} classes cross-checked\n";
same('no advice promotes an ability the rules do not', [], $contradicts);
same('and none of the rules\' own abilities is dropped', [], $dropped);
same('every class has at least one primary', [], $noPrimary);
same('and its secondary is a different ability', [], $sameTwice);
same('every key is a real ability', [], $badKey);

section('the shapes the column comes in are all read');
// The three the SRD actually uses. If a fourth ever appears, abilitiesIn()
// answering [] is what would silently drop a whole class off the screen.
same('one ability', ['str'], abilitiesIn('Strength'));
same('a choice of two', ['str', 'dex'], abilitiesIn('Strength or Dexterity'));
same('a pair', ['dex', 'wis'], abilitiesIn('Dexterity,Wisdom'));
same('and a column nobody filled in reads as nothing', [], abilitiesIn(''));

section('a Fighter keeps its choice');
// The one class whose primary is genuinely two builds rather than two stats to
// raise together — worth pinning, because collapsing it to one would be an
// easy tidy-up that quietly recommends the wrong Fighter.
same('both are offered', ['str', 'dex'], Rules::CLASS_ABILITIES['Fighter']['primary']);
same('and a Monk is told which comes first', ['dex'], Rules::CLASS_ABILITIES['Monk']['primary']);
same('with the other as the secondary', 'wis', Rules::CLASS_ABILITIES['Monk']['secondary']);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
