<?php
/**
 * What a condition does to a distance.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_movement.php`.
 *
 * Conditions.php was written for the rank board and said so: it carried "only
 * the conditions that still mean something in a fight without a movement
 * grid". Two things were left behind when the grid arrived and neither made a
 * sound. `speed_zero` was declared on all fifteen catalogue entries and read by
 * nothing at all, so grappled, restrained, paralysed, stunned and unconscious
 * each let a creature walk away at full speed. Frightened's own description
 * ends "and cannot willingly move closer to it", which had no board to be
 * closer on.
 *
 * Standing up is here too because it is the same class of bug: half your speed
 * is the SRD's price and nobody was charged it.
 *
 * The geometry stays in BattleGrid and is tested in test_combat.php with no PDO
 * behind it. What is tested here is the seam — CombatEngine::movementBudget()
 * and reachFor(), where a condition meets a distance.
 *
 * Touches no rows.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$reachFor = new ReflectionMethod(CombatEngine::class, 'reachFor');
$reachFor->setAccessible(true);
$doMove = new ReflectionMethod(CombatEngine::class, 'doMove');
$doMove->setAccessible(true);

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

/** A fighter at 4,5 with thirty feet, and a bandit at 10,5 to be afraid of. */
function board(array $conditions = [], int $left = 30): array
{
    return [
        'round' => 1,
        'turn_index' => 0,
        'order' => ['pc_1'],
        'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16))],
        'log' => [],
        'combatants' => [
            ['cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
             'class' => 'Fighter', 'level' => 3, 'x' => 4, 'y' => 5,
             'speed' => 30, 'move_left' => $left, 'hp' => 19, 'max_hp' => 19,
             'alive' => true, 'conditions' => $conditions, 'boons' => [],
             'action_used' => false],
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 10, 'y' => 5, 'hp' => 11, 'max_hp' => 11,
             'alive' => true, 'conditions' => []],
        ],
    ];
}

$actor = fn(array $s) => $s['combatants'][0];

echo "== held in place ==\n";
foreach (['grappled', 'restrained', 'paralyzed', 'stunned', 'unconscious'] as $key) {
    same("{$key} is nought feet", 0,
        CombatEngine::movementBudget($actor(board([['key' => $key]]))));
}
same('and nothing else is', 30, CombatEngine::movementBudget($actor(board([['key' => 'poisoned']]))));
same('an unhindered fighter has what they have left', 15,
    CombatEngine::movementBudget($actor(board([], 15))));

// Its own cell and nothing else. `reachable()` always returns where you are
// standing at a cost of zero — that is the root of the search, not an offer —
// so "held" is one entry rather than none.
$held = board([['key' => 'grappled']]);
same('a held creature is offered nowhere but where it stands',
    [BattleGrid::keyOf(4, 5)], array_keys($reachFor->invoke($engine, $held, $actor($held))));

$refused = false;
try {
    $doMove->invoke($engine, $held, 5, 5);
} catch (InvalidArgumentException $e) {
    $refused = str_contains($e->getMessage(), 'cannot move at all');
}
ok('and is told why, not told it is out of movement', $refused);

echo "\n== getting up ==\n";
same('standing costs half your speed', 15,
    CombatEngine::movementBudget($actor(board([['key' => 'prone']]))));
same('half of SPEED, not half of what is left', 5,
    CombatEngine::movementBudget($actor(board([['key' => 'prone']], 20))));
same('and ten feet will not buy it', 0,
    CombatEngine::movementBudget($actor(board([['key' => 'prone']], 10))));

$prone = board([['key' => 'prone']]);
$after = $doMove->invoke($engine, $prone, 6, 5);
$moved = $after['combatants'][0];
ok('walking gets you up', !Conditions::has($moved, 'prone'));
ok('and the stand is paid for out of the same allowance',
    (int) $moved['move_left'] === 5, "left {$moved['move_left']}");

echo "\n== fear ==\n";
$scared = board([['key' => 'frightened', 'source_cid' => 'mon_1']]);
$cells = $reachFor->invoke($engine, $scared, $actor($scared));
$closer = 0;
$away = 0;
foreach (array_keys($cells) as $key) {
    [$x, $y] = BattleGrid::parseKey($key);
    $d = BattleGrid::cells($x, $y, 10, 5);
    if ($d < 6) {
        $closer++;
    }
    if ($d > 6) {
        $away++;
    }
}
same('not one cell nearer the thing you fear', 0, $closer);
ok('but running away is still allowed', $away > 0, "{$away} cells further off");

$free = board();
ok('and an unfrightened fighter may close', count($reachFor->invoke($engine, $free, $actor($free))) > count($cells));

// The source has to be on the board and standing. A fear whose source is dead
// constrains nobody — there is nothing left to back away from.
//
// Compared against a board with the same corpse on it rather than against
// `$free`: a dead body stops blocking its cell, so killing the bandit changes
// what is reachable for a reason that has nothing to do with fear. The first
// version of this assertion compared the two and failed for that reason.
$dead = board([['key' => 'frightened', 'source_cid' => 'mon_1']]);
$dead['combatants'][1]['alive'] = false;
$deadAndCalm = board();
$deadAndCalm['combatants'][1]['alive'] = false;
same('a dead source frightens nobody',
    count($reachFor->invoke($engine, $deadAndCalm, $actor($deadAndCalm))),
    count($reachFor->invoke($engine, $dead, $actor($dead))));

$sourceless = board([['key' => 'frightened']]);
ok('and neither does a fear with no source named',
    count($reachFor->invoke($engine, $sourceless, $actor($sourceless)))
    === count($reachFor->invoke($engine, $free, $actor($free))));

echo "\n== Dodge reaches saves, and Help does not reach across the room ==\n";

$rollSave = new ReflectionMethod(CombatEngine::class, 'rollSave');
$rollSave->setAccessible(true);
$doHelp = new ReflectionMethod(CombatEngine::class, 'doHelp');
$doHelp->setAccessible(true);

// Two dice or one is the whole of the claim, and it is what `rolls` records.
// Sampled rather than asserted once, because a single advantage roll and a
// single normal roll can produce the same total.
$dodger = ['name' => 'Wren', 'dex' => 14, 'con' => 12, 'level' => 3, 'class' => 'Fighter',
           'prof' => 2, 'dodging' => true, 'conditions' => []];
$still = ['name' => 'Wren', 'dex' => 14, 'con' => 12, 'level' => 3, 'class' => 'Fighter',
          'prof' => 2, 'dodging' => false, 'conditions' => []];

$twoDice = true;
$oneDie = true;
for ($i = 0; $i < 40; $i++) {
    if ($rollSave->invoke($engine, $dodger, 'dex', 10)['mode'] !== 'advantage') {
        $twoDice = false;
    }
    if ($rollSave->invoke($engine, $still, 'dex', 10)['mode'] !== 'normal') {
        $oneDie = false;
    }
}
ok('dodging saves against Dexterity with advantage', $twoDice);
ok('and standing still does not', $oneDie);
ok('dodging does nothing for a Constitution save',
    $rollSave->invoke($engine, $dodger, 'con', 10)['mode'] === 'normal');

// Help wants somebody beside you. Both parties are placed, so this is a
// distance now rather than a rank.
function helpBoard(int $allyX): array
{
    $s = board();
    $s['combatants'][] = ['cid' => 'pc_2', 'type' => 'pc', 'side' => 'party', 'name' => 'Kessa',
        'x' => $allyX, 'y' => 5, 'speed' => 30, 'move_left' => 30,
        'hp' => 19, 'max_hp' => 19, 'alive' => true, 'conditions' => []];
    return $s;
}

$beside = $doHelp->invoke($engine, helpBoard(5), 'pc_2');
ok('an ally at your elbow can be helped', ($beside['combatants'][2]['helped'] ?? null) === 'pc_1');

$shouted = false;
try {
    $doHelp->invoke($engine, helpBoard(12), 'pc_2');
} catch (InvalidArgumentException $e) {
    $shouted = str_contains($e->getMessage(), 'too far away');
}
ok('one across the room cannot', $shouted);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);

function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}
