<?php
/**
 * The encounter budget: 5e's arithmetic, shared by the pit and the dungeon.
 *
 * Run with plain `php src/tools/test_budget.php` — EncounterBudget takes a list
 * of levels rather than a party id, so none of this needs MySQL. That is the
 * point of the extraction: the numbers that decide how hard every generated
 * fight in the game will be can be swept over invented parties in a
 * millisecond, instead of only being observable by ordering a bout.
 *
 * It was lifted out of PitEngine when the dungeon generator needed the same
 * sums. Two copies of a tuning table is how the slot table drifted once
 * already, and a difficulty curve that disagrees with itself is invisible
 * until somebody dies to it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/EncounterBudget.php';

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

section('the table itself');
same('twenty levels, no gaps', range(1, 20), array_keys(EncounterBudget::THRESHOLDS));
$shape = true;
$rising = true;
$prev = [0, 0, 0];
foreach (EncounterBudget::THRESHOLDS as $level => $row) {
    if (count($row) !== 3) {
        $shape = false;
    }
    // easy < medium < hard, at every level.
    if (!($row[0] < $row[1] && $row[1] < $row[2])) {
        $shape = false;
    }
    // And every column climbs with level, or a party would get weaker fights
    // for levelling up.
    for ($i = 0; $i < 3; $i++) {
        if ($row[$i] < $prev[$i]) {
            $rising = false;
        }
    }
    $prev = $row;
}
ok('every row is easy, medium, hard in order', $shape);
ok('and no column ever falls as level rises', $rising);
same('three tiers, and no deadly', ['warmup', 'fair', 'hard'],
    array_keys(EncounterBudget::TIERS));

section('a budget scales on both level and headcount');
same('one first-level character, fair', 50, EncounterBudget::forParty([1], 'fair'));
same('four of them', 200, EncounterBudget::forParty([1, 1, 1, 1], 'fair'));
same('a mixed party sums its members', 1050, EncounterBudget::forParty([3, 3, 4, 5], 'fair'));
ok('hard is more than fair is more than a warm-up',
    EncounterBudget::forParty([3, 3], 'hard') > EncounterBudget::forParty([3, 3], 'fair')
    && EncounterBudget::forParty([3, 3], 'fair') > EncounterBudget::forParty([3, 3], 'warmup'));
same('an empty party is read as one first-level character, not as zero',
    EncounterBudget::forParty([1], 'fair'), EncounterBudget::forParty([], 'fair'));
same('a level above the table is clamped', EncounterBudget::forParty([20], 'fair'),
    EncounterBudget::forParty([99], 'fair'));
same('and a level below it', EncounterBudget::forParty([1], 'fair'),
    EncounterBudget::forParty([0], 'fair'));
same('an unknown tier falls back to fair', EncounterBudget::forParty([3], 'fair'),
    EncounterBudget::forParty([3], 'legendary'));

section('the crowd multiplier');
same('one is itself', 1.0, EncounterBudget::multiplier(1));
same('two is half again', 1.5, EncounterBudget::multiplier(2));
same('three to six', 2.0, EncounterBudget::multiplier(6));
same('seven to ten', 2.5, EncounterBudget::multiplier(10));
same('eleven and up', 3.0, EncounterBudget::multiplier(11));
same('and none is still one', 1.0, EncounterBudget::multiplier(0));

section('filling a fight');
$budget = EncounterBudget::forParty([3, 3, 3, 3], 'fair');   // 600
// A 50xp creature: six of them raw is 300, doubled by the crowd is 600.
same('six fifty-point creatures exactly fill six hundred', 6,
    EncounterBudget::bestCount(50, 600, 6));
ok('a seventh would not fit, if there were room for it',
    EncounterBudget::bestCount(50, 600, 10) <= 8,
    (string) EncounterBudget::bestCount(50, 600, 10));
same('nothing affordable, and nobody forced, is none', 0,
    EncounterBudget::bestCount(5000, 100, 6));
same('but a headline creature always comes through', 1,
    EncounterBudget::bestCount(5000, 100, 6, 0, 0, true));
same('a creature worth nothing is refused rather than infinite', 0,
    EncounterBudget::bestCount(0, 600, 6));
same('and no slots means none', 0, EncounterBudget::bestCount(50, 600, 0));

// The multiplier is a property of the fight, so adding to a group that is
// already large costs more than adding to an empty one.
$alone = EncounterBudget::bestCount(50, 600, 6, 0, 0);
$crowded = EncounterBudget::bestCount(50, 600, 6, 200, 4);
ok('adding to a crowd buys fewer than starting one', $crowded < $alone,
    "alone {$alone}, crowded {$crowded}");

section('weighing what came out');
same('an empty budget weighs nothing rather than dividing by zero', 0.0,
    EncounterBudget::weight(100, 2, 0));
same('a group at exactly the budget weighs one', 1.0,
    EncounterBudget::weight(300, 6, 600));
ok('and over the budget weighs more than one',
    EncounterBudget::weight(400, 6, 600) > 1.0);

section('the curve a delve has to climb');
// Sanity, and the reason the dungeon exists: how many fair fights of a party's
// own tier does a level cost? Printed rather than asserted — it is the thing to
// look at when tuning how many rooms a dungeon level holds.
$xpToLevel = [2 => 300, 3 => 900, 4 => 2700, 5 => 6500, 6 => 14000];
$prevNeed = 0;
foreach ($xpToLevel as $level => $total) {
    $partyLevel = $level - 1;
    $party = array_fill(0, 4, $partyLevel);
    $fair = EncounterBudget::forParty($party, 'fair');
    // A fight's raw XP is roughly the budget divided by the crowd multiplier,
    // and the party splits it four ways.
    $rawPerFight = (int) ($fair / EncounterBudget::multiplier(4));
    $perCharacter = (int) ($rawPerFight / 4);
    $need = $total - $prevNeed;
    $prevNeed = $total;
    printf(
        "  level %d -> %d: %d xp each, about %d per fair fight — %d fights%s",
        $partyLevel,
        $level,
        $need,
        $perCharacter,
        $perCharacter > 0 ? (int) ceil($need / $perCharacter) : 0,
        PHP_EOL
    );
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
