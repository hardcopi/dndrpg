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
            /* `character_id` 0 so that taking damage — which the walk test
               below does — writes `WHERE id = 0` rather than `WHERE id = NULL`
               and a warning. The column is auto_increment from 1, so 0 matches
               nothing and this file's "touches no rows" stays true by
               construction rather than by the id happening to be absent. */
            ['cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
             'character_id' => 0,
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

/* ---------------------------------------------------------------------------
 * A walk that gets interrupted, and WHEN the interruption is said to happen.
 *
 * Reported from a real fight: "Kessa just attacked and got hit by a rat from a
 * distance." The rule was right — her path clipped the rat's reach at one cell
 * and the opportunity attack fired as she left it — but stepMove() logged the
 * whole move after the whole walk, so the log read
 *
 *     Giant Rat 2 lashes out as Kessa Dunmar breaks away.
 *     Giant Rat 2 CRITS Kessa Dunmar: 24 vs AC 12, 6 piercing damage.
 *     Kessa Dunmar moves 30 feet.
 *
 * and the client, which plays events in order, drew the bite while she was
 * still standing on her starting cell thirty feet away.
 *
 * So this asserts the ORDER, not the arithmetic: the ground covered before the
 * blow is on the board before the blow. The geometry that decides whether an
 * attack happens at all is test_combat.php's.
 * ------------------------------------------------------------------------- */
echo "\n== a blow lands where the walk had got to ==\n";

$stepMove = new ReflectionMethod(CombatEngine::class, 'stepMove');
$stepMove->setAccessible(true);

/* The bandit is at 10,5 and threatens 9..11 x 4..6. Wren starts at 4,5, walks
   east into that reach at 9,5, then steps away to 9,7 — the same shape as the
   rat and Kessa: in reach for one cell, then out. */
$walk = board();
$walk['order'] = ['pc_1', 'mon_1'];
$walk['events'] = [];
$path = array_map(
    static fn (array $c) => BattleGrid::keyOf($c[0], $c[1]),
    [[4, 5], [5, 5], [6, 5], [7, 5], [8, 5], [9, 5], [9, 6], [9, 7]]
);
$after = $stepMove->invoke($engine, $walk, 'pc_1', $path, $walk['grid']['terrain']);

$types = array_column($after['events'], 'type');
$firstMove = array_search('move', $types, true);
$oa = array_search('opportunity', $types, true);

ok('the walk provokes at all', $oa !== false);
ok('and a move is on the board before the swing',
    $firstMove !== false && $oa !== false && $firstMove < $oa,
    'events were: ' . implode(', ', $types));

/* The whole point: the segment emitted before the blow must END on a cell the
   attacker can actually reach. This is the assertion that fails if stepMove
   goes back to logging one move after the walk — the mover would still be on
   her starting cell, six cells away, when the bite is drawn. */
if ($firstMove !== false && $oa !== false && $firstMove < $oa) {
    $at = $after['events'][$firstMove]['to'];
    same('and it ends within the attacker\'s reach', 5,
        BattleGrid::feet((int) $at[0], (int) $at[1], 10, 5));
}

/* A move nothing interrupts is still ONE line and ONE event. The split is the
   information; a walk across an empty room must not grow segments. */
$clear = board();
$clear['events'] = [];
$clearPath = array_map(
    static fn (array $c) => BattleGrid::keyOf($c[0], $c[1]),
    [[4, 5], [4, 6], [4, 7], [4, 8]]
);
$plain = $stepMove->invoke($engine, $clear, 'pc_1', $clearPath, $clear['grid']['terrain']);
same('an uninterrupted walk is one event', 1,
    count(array_filter($plain['events'], static fn ($e) => $e['type'] === 'move')));
same('and one line', 1, count($plain['log']));

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);

function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}
