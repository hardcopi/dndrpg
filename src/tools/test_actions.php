<?php
/**
 * Ready, Hide, Grapple and Shove — the SRD actions the engine did not have.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_actions.php`.
 *
 * Each was absent for its own reason and all four are reasons the grid removed:
 *
 *   - Ready needed somewhere for a trigger to happen. It has one now, and
 *     exactly one — "an enemy comes within your reach" — fired out of the same
 *     two sets the opportunity attack uses, on the reach you ENTER rather than
 *     the reach you leave.
 *   - Hide needed cover to hide behind, and something for success to mean. It
 *     applies `invisible`, which was a fully specified entry in the condition
 *     catalogue that nothing in the entire game ever applied.
 *   - Grapple and Shove needed conditions that bite. `grappled` and `prone`
 *     both did nothing to movement until speed_zero and the cost of standing
 *     up were enforced; putting somebody on the floor is worth an action now.
 *
 * Touches no rows.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};

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
function threw(string $n, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($n, false, 'nothing was thrown');
    } catch (InvalidArgumentException $e) {
        ok($n, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

/**
 * A fighter at 4,5 and a bandit at 5,5, beside each other.
 * `$terrain` may place a tall block for the cover tests.
 */
function board(array $pc = [], array $walls = []): array
{
    $rows = array_map(fn($r) => str_split($r), array_fill(0, 12, str_repeat('.', 16)));
    foreach ($walls as [$x, $y]) {
        $rows[$y][$x] = 'n';
    }
    return [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_1', 'mon_1'], 'status' => 'active',
        'grid' => ['terrain' => array_map(fn($r) => implode('', $r), $rows), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [],
        'combatants' => [
            array_merge([
                'character_id' => 0,
                'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
                'class' => 'Fighter', 'level' => 3, 'background' => 'Soldier',
                'x' => 4, 'y' => 5,
                'str' => 16, 'dex' => 14, 'con' => 14, 'int' => 10, 'wis' => 12, 'cha' => 10,
                'prof' => 2, 'speed' => 30, 'move_left' => 30,
                'hp' => 20, 'max_hp' => 20, 'ac' => 15, 'alive' => true, 'reach_ft' => 5,
                'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
                'action_used' => false, 'attacks_made' => 0, 'reaction_used' => false,
                'readied' => false, 'temp_hp' => 0, 'stable' => false, 'surprised' => false,
                'death_saves' => ['success' => 0, 'fail' => 0],
            ], $pc),
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'str' => 11, 'dex' => 12, 'con' => 12,
             'int' => 10, 'wis' => 10, 'cha' => 10, 'prof' => 2, 'level' => 1,
             'hp' => 11, 'max_hp' => 11, 'ac' => 12, 'alive' => true, 'reach_ft' => 5,
             'speed' => 30, 'move_left' => 30,
             'conditions' => [], 'boons' => [], 'concentrating' => null,
             'actions' => [['name' => 'Scimitar', 'type' => 'melee', 'bonus' => 3,
                            'damage' => '1d6+1', 'damage_type' => 'slashing']]],
        ],
    ];
}
$pc = fn(array $s) => $s['combatants'][0];
$foe = fn(array $s) => $s['combatants'][1];

echo "== Ready ==\n";
$held = $invoke('doReady', board());
ok('holding spends the action', !empty($pc($held)['action_used']));
ok('and sets the swing', !empty($pc($held)['readied']));

// The trigger: an enemy walking INTO reach, which is the mirror of the reach
// an opportunity attack fires on leaving.
$waiting = board(['readied' => true, 'x' => 8, 'y' => 5]);
$fired = $invoke('fireReadied', $waiting, 'mon_1', ['pc_1'], $waiting['grid']['terrain']);
ok('somebody walking into reach is struck',
    count(array_filter($fired['events'], fn($e) => $e['type'] === 'readied')) === 1);
ok('the reaction is spent', !empty($pc($fired)['reaction_used']));
ok('and the swing is not held twice', empty($pc($fired)['readied']));

$notWaiting = board(['x' => 8, 'y' => 5]);
same('nobody who did not ready swings', 0,
    count(array_filter($invoke('fireReadied', $notWaiting, 'mon_1', ['pc_1'],
        $notWaiting['grid']['terrain'])['events'], fn($e) => $e['type'] === 'readied')));

$spent = board(['readied' => true, 'reaction_used' => true, 'x' => 8, 'y' => 5]);
same('and neither does somebody who has no reaction left', 0,
    count(array_filter($invoke('fireReadied', $spent, 'mon_1', ['pc_1'],
        $spent['grid']['terrain'])['events'], fn($e) => $e['type'] === 'readied')));

echo "\n== Grapple and Shove ==\n";
$grappled = $invoke('doContest', board(), 'mon_1', 'grapple');
ok('a grapple is resolved as a contest',
    count(array_filter($grappled['events'], fn($e) => $e['type'] === 'contest')) === 1);
same('and spends one attack, not the whole action', 1, (int) $pc($grappled)['attacks_made']);
$ev = array_values(array_filter($grappled['events'], fn($e) => $e['type'] === 'contest'))[0];
ok('a win holds them, a loss does not',
    $ev['won'] === Conditions::has($foe($grappled), 'grappled'));

$shoved = $invoke('doContest', board(), 'mon_1', 'shove');
$sev = array_values(array_filter($shoved['events'], fn($e) => $e['type'] === 'contest'))[0];
ok('a shove puts them down, or does not', $sev['won'] === Conditions::has($foe($shoved), 'prone'));

threw('an ally cannot be grappled',
    fn() => $invoke('doContest', board(), 'pc_1', 'grapple'), 'ally');
$far = board([], []);
$far['combatants'][1]['x'] = 12;
threw('nor can somebody out of reach',
    fn() => $invoke('doContest', $far, 'mon_1', 'grapple'), 'out of reach');
threw('and not after the action has gone elsewhere',
    fn() => $invoke('doContest', board(['action_used' => true]), 'mon_1', 'shove'), 'Already acted');

// Extra Attack: swing, then shove.
$fifth = $invoke('doContest', board(['level' => 5, 'attacks_made' => 1, 'action_used' => true]),
    'mon_1', 'shove');
same('a fifth-level fighter may swing and then shove', 2, (int) $pc($fifth)['attacks_made']);

echo "\n== Hide ==\n";
threw('you cannot hide in the open',
    fn() => $invoke('doHide', board()), 'can see you plainly');

// A tall block between the two of them is what makes it possible at all.
$behind = board(['x' => 2, 'y' => 5], [[4, 5], [4, 4], [4, 6]]);
$behind['combatants'][1]['x'] = 9;
$hidden = $invoke('doHide', $behind);
ok('behind cover you may try',
    count(array_filter($hidden['events'], fn($e) => $e['type'] === 'contest'
        && $e['kind'] === 'hide')) === 1);
// Filtered on the type first: `kind` is a field of the contest event and not
// of the attack and condition events beside it in the same list.
$hev = array_values(array_filter($hidden['events'],
    fn($e) => ($e['type'] ?? '') === 'contest' && ($e['kind'] ?? '') === 'hide'))[0];
ok('and a good roll takes you out of sight',
    $hev['won'] === Conditions::has($pc($hidden), 'invisible'));
ok('either way it costs the action', !empty($pc($hidden)['action_used']));

// Striking gives you away, whether or not it lands.
$unseen = board(['conditions' => [['key' => 'invisible']]]);
$struck = $invoke('resolveAttack', $unseen, 'pc_1', 'mon_1',
    ['name' => 'Sword', 'bonus' => 5, 'damage' => '1d8+3', 'damage_type' => 'slashing',
     'reach' => 'melee', 'range' => ['reach' => 5, 'normal' => null, 'long' => null],
     'tags' => ['melee']],
    null, ['dist' => 5, 'cover' => 0, 'melee' => true, 'long' => false,
           'crowded' => false, 'flank' => false, 'c' => $foe($unseen)]);
ok('attacking breaks cover', !Conditions::has($pc($struck), 'invisible'));

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
