<?php
/**
 * Sneak Attack fires when the opening is there, and only then.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_sneak.php`.
 *
 * The gap this closes: `classes.features` has said "Expertise, Sneak Attack,
 * Thieves' Cant" on every Rogue sheet since the game was seeded, and nothing
 * anywhere read it. The string `sneak` appeared twice in the whole project —
 * once in that seed row, once in a comment in Rules.php explaining why a rogue
 * is put in the front rank because "sneak attack wants a melee" — and a rogue's
 * entire class was one bonus action. A sheet that promises a feature the engine
 * does not have is worse than a sheet that promises nothing.
 *
 * The DICE live in tools/test_combat.php, which is PDO-free and covers
 * CombatEngine::sneakAttackDice() across the level range. What is here is the
 * half that needs a board: whether the opening exists, what closes it, and the
 * once-per-turn ledger. Reflection reaches the private method deliberately —
 * this is a probe, not an argument for making it public.
 *
 * Touches no rows. The state below is hand-built and thrown away.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$sneak = new ReflectionMethod(CombatEngine::class, 'sneakAttack');
$sneak->setAccessible(true);

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

/**
 * A rogue at 4,5, an ally at 6,5, and a bandit between them at 5,5.
 *
 * The ally is adjacent to the bandit, which is the second of the SRD's two
 * openings; move them and it closes.
 */
function board(): array
{
    return [
        'round' => 1,
        'turn_index' => 0,
        'order' => ['pc_1', 'mon_1', 'pc_2'],
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16))],
        'combatants' => [
            ['cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Sera',
             'class' => 'Rogue', 'level' => 3, 'x' => 4, 'y' => 5,
             'hp' => 19, 'max_hp' => 19, 'alive' => true, 'conditions' => []],
            ['cid' => 'pc_2', 'type' => 'pc', 'side' => 'party', 'name' => 'Ally',
             'class' => 'Fighter', 'level' => 3, 'x' => 6, 'y' => 5,
             'hp' => 19, 'max_hp' => 19, 'alive' => true, 'conditions' => []],
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'hp' => 11, 'max_hp' => 11, 'alive' => true, 'conditions' => []],
        ],
    ];
}

$FINESSE = ['tags' => ['finesse', 'light', 'melee'], 'damage' => '1d6+3'];
$CLUB    = ['tags' => ['melee'], 'damage' => '1d6+3'];
$BOW     = ['tags' => ['ranged'], 'damage' => '1d6+3'];

$try = fn(array $state, array $weapon, string $mode, bool $crit = false)
    => $sneak->invoke($GLOBALS['engine'], $state, 'pc_1', 'mon_1', $weapon, $mode, $crit);

echo "== the two openings ==\n";
$hit = $try(board(), $FINESSE, 'advantage');
ok('advantage opens it', $hit !== null);
ok('and a 3rd-level rogue rolls 2d6', $hit !== null && count($hit['roll']['rolls']) === 2,
    $hit ? 'rolled ' . count($hit['roll']['rolls']) . ' dice' : 'nothing came back');

ok('an ally within reach of the target opens it without advantage',
    $try(board(), $FINESSE, 'normal') !== null);

$alone = board();
$alone['combatants'][1]['x'] = 12;
ok('and closes when that ally walks away', $try($alone, $FINESSE, 'normal') === null);

ok('disadvantage closes it even with the ally standing there',
    $try(board(), $FINESSE, 'disadvantage') === null);

echo "\n== what it is held in ==\n";
ok('a club does not qualify', $try(board(), $CLUB, 'advantage') === null);
ok('a finesse weapon does', $try(board(), $FINESSE, 'advantage') !== null);
ok('so does a ranged one', $try(board(), $BOW, 'advantage') !== null);

echo "\n== who gets it ==\n";
$fighter = board();
$fighter['combatants'][0]['class'] = 'Fighter';
ok('a fighter with a finesse weapon gets nothing', $try($fighter, $FINESSE, 'advantage') === null);

$five = board();
$five['combatants'][0]['level'] = 5;
$hit = $try($five, $FINESSE, 'advantage');
ok('3d6 at 5th', $hit !== null && count($hit['roll']['rolls']) === 3);

echo "\n== once per turn, not once per YOUR turn ==\n";
$spent = $try(board(), $FINESSE, 'advantage')['state'];
ok('a second attack in the same turn adds nothing',
    $try($spent, $FINESSE, 'advantage') === null);

$elsewhere = $spent;
$elsewhere['turn_index'] = 1;
ok('an opportunity attack during somebody else\'s turn still lands it',
    $try($elsewhere, $FINESSE, 'advantage') !== null);

$nextRound = $spent;
$nextRound['round'] = 2;
ok('and the next round starts fresh', $try($nextRound, $FINESSE, 'advantage') !== null);

echo "\n== criticals ==\n";
$crit = $try(board(), $FINESSE, 'advantage', true);
ok('a critical doubles the sneak dice with the rest',
    $crit !== null && count($crit['roll']['rolls']) === 4,
    $crit ? 'rolled ' . count($crit['roll']['rolls']) . ' dice' : 'nothing came back');

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
