<?php
/**
 * The monster manual: does the catalogue hold every creature, and does each
 * stat block actually say what the creature does?
 *
 * NEEDS the database — a bestiary is a SELECT over `monsters` — and reads
 * only, so it is safe against a database somebody is playing on.
 *
 * The two claims that have already been wrong once:
 *
 *   - the adventure book printed the whole catalogue (or, later, none of it)
 *     because it asked `monsters` what it held instead of asking the module
 *     what it sends;
 *   - an action whose column is CombatEngine's JSON printed as a bare
 *     "Scimitar." with nothing after it.
 *
 * This file asserts the catalogue is complete, the book is still a subset,
 * and every action writes a sentence.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$bestiary = new Bestiary($db);

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
$all = $bestiary->all();
$keys = array_column($all, 'monster_key');
sort($keys);
$inTable = $db->query('SELECT monster_key FROM monsters ORDER BY monster_key')
    ->fetchAll(PDO::FETCH_COLUMN);
sort($inTable);

ok('there are creatures to print', count($all) > 0, (string) count($all));
same('every row in the table is in the book', $inTable, $keys);
same('ofKeys of nothing is empty', [], $bestiary->ofKeys([]));

$justGoblin = $bestiary->ofKeys(['goblin', '_not_a_creature']);
same('ofKeys drops unknown names', ['goblin'], array_column($justGoblin, 'monster_key'));

echo "\nHow a number is written\n";
same('a bandit is challenge 1/8', '1/8', Bestiary::crText(0.13));
same('a goblin is challenge 1/4', '1/4', Bestiary::crText(0.25));
same('CR 0 is 0', '0', Bestiary::crText(0));
same('CR 5 is 5', '5', Bestiary::crText(5));
same('strength 8 is −1', '-1', Bestiary::modOf(8));
same('strength 10 is +0', '+0', Bestiary::modOf(10));
same('strength 20 is +5', '+5', Bestiary::modOf(20));
same('a goblin is a humanoid', 'humanoid', Bestiary::kindOf('humanoid (goblinoid)'));
same('an empty type is a creature', 'creature', Bestiary::kindOf(''));

echo "\nStat blocks\n";
$byKey = [];
foreach ($all as $m) {
    $byKey[(string) $m['monster_key']] = $m;
}

$blocksOk = true;
$mute = [];
$rawJson = [];
foreach ($all as $m) {
    foreach (['traits_list', 'actions_list', 'legendary_list'] as $field) {
        foreach ($m[$field] as $entry) {
            if (!array_key_exists('name', $entry) || !array_key_exists('text', $entry)) {
                $blocksOk = false;
            }
            if (str_starts_with(trim((string) $entry['text']), '[{')) {
                $rawJson[] = $m['name'] . ':' . $field;
            }
        }
    }
    foreach ($m['actions_list'] as $a) {
        if (trim((string) $a['text']) === '') {
            $mute[] = $m['name'] . ': ' . $a['name'];
        }
    }
}
ok('every list is {name, text}', $blocksOk);
same('and none of them printed the raw JSON', [], $rawJson);
same('and every action says what it does', [], $mute);

$goblin = $byKey['goblin'] ?? null;
ok('the goblin is in the book', $goblin !== null);
if ($goblin !== null) {
    same('its challenge is written as a fraction', '1/4', $goblin['cr_label']);
    $scimitar = '';
    $bow = '';
    foreach ($goblin['actions_list'] as $a) {
        if ($a['name'] === 'Scimitar') {
            $scimitar = $a['text'];
        }
        if ($a['name'] === 'Shortbow') {
            $bow = $a['text'];
        }
    }
    ok('a scimitar is a melee attack with its dice',
        str_contains($scimitar, 'Melee attack') && str_contains($scimitar, '1d6+2'),
        $scimitar);
    ok('a shortbow is a ranged attack at 80/320',
        str_contains($bow, 'Ranged attack') && str_contains($bow, '80/320') && str_contains($bow, 'ft'),
        $bow);
    ok('and the range is not written twice', !preg_match('/ft\.?\s*ft/', $bow), $bow);
    ok('its Dex save is on the block', str_contains((string) $goblin['save_line'], 'Dex'),
        (string) $goblin['save_line']);
    ok('its treasure names coin', str_contains((string) $goblin['loot_line'], 'gp'),
        (string) $goblin['loot_line']);
    ok('Nimble Escape is a trait, not a paragraph of JSON',
        $goblin['traits_list'] !== [] && $goblin['traits_list'][0]['name'] === 'Nimble Escape');
}

$growth = $byKey['the_growth'] ?? null;
ok('The Growth is in the book', $growth !== null);
if ($growth !== null) {
    $spore = '';
    foreach ($growth['actions_list'] as $a) {
        if ($a['name'] === 'Spore Cast') {
            $spore = $a['text'];
        }
    }
    ok('a range that already said ft does not say it twice',
        str_contains($spore, 'range 40 ft') && !preg_match('/ft\.?\s*ft/', $spore),
        $spore);
    ok('fire is a vulnerability', in_array('fire', $growth['vulnerabilities'], true));
    ok('it cannot be blinded', in_array('blinded', $growth['condition_immunities'], true));
}

$bandit = $byKey['bandit'] ?? null;
ok('a bandit is challenge 1/8 in the row', $bandit !== null && $bandit['cr_label'] === '1/8',
    $bandit['cr_label'] ?? 'missing');

echo "\nArt\n";
$missingArt = [];
foreach ($all as $m) {
    $file = (string) $m['art_file'];
    if ($file === '') {
        $missingArt[] = $m['monster_key'];
        continue;
    }
    if (!is_file(APP_ROOT . '/' . $file)) {
        $missingArt[] = $m['monster_key'] . ' (broken path)';
    }
}
// The vhost 200-for-missing trap: a path we ship must be a real file. A
// creature with no bust is allowed — the block just has no picture.
ok('every art_file that is set is a file on disk',
    count(array_filter($missingArt, static fn ($k) => str_contains($k, 'broken'))) === 0,
    implode(', ', $missingArt));
ok('most creatures have a bust', count($all) - count($missingArt) > count($all) / 2,
    (count($all) - count($missingArt)) . ' of ' . count($all));

echo "\nThe adventure book is still a subset\n";
$book = (new AdventureBook($db))->build('rivermark');
ok('Rivermark has a book', $book !== null);
if ($book !== null) {
    $printed = array_column($book['monsters'], 'monster_key');
    ok('and it does not print the whole catalogue',
        count($printed) < count($all),
        count($printed) . ' of ' . count($all));
    $extra = array_diff($printed, $keys);
    same('everything it does print is in the catalogue', [], array_values($extra));
    $goblinInBook = null;
    foreach ($book['monsters'] as $m) {
        if ($m['monster_key'] === 'goblin') {
            $goblinInBook = $m;
            break;
        }
    }
    if ($goblinInBook !== null) {
        ok('the book\'s goblin is the same formatted row',
            ($goblinInBook['cr_label'] ?? null) === '1/4'
            && ($goblinInBook['save_line'] ?? '') !== '');
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
