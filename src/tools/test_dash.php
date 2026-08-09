<?php
/**
 * Dash, and Cunning Action being a choice rather than a fixture.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_dash.php`.
 *
 * Dash was the one common SRD action the engine did not have. On the rank board
 * that was right — there was no distance to double and the button would have
 * done nothing — but `move_left` is a budget of feet now, and the situation the
 * grid produces most often is somebody sixty feet away and a speed of thirty.
 *
 * The same change makes Cunning Action worth choosing. The Rogue's bonus action
 * was Disengage and only Disengage, for the same rank-board reason; on a floor
 * the Dash is the more useful half most turns.
 *
 * The AI's *judgement* about running is pure and lives in
 * tools/test_combat.php, which needs no PDO. What is here is the half that
 * changes state: what the action does to the turn's economy, and what the
 * bonus action accepts. Reflection reaches the private methods deliberately.
 *
 * Touches no rows. Cunning Action carries no per-rest allowance, so nothing
 * below reaches WorldState.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$dash = new ReflectionMethod(CombatEngine::class, 'doDash');
$dash->setAccessible(true);
$bonus = new ReflectionMethod(CombatEngine::class, 'doBonusAction');
$bonus->setAccessible(true);

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

/** One rogue, mid-turn, with `$left` of their 30 feet still unspent. */
function board(int $left = 30, array $extra = []): array
{
    return [
        'round' => 1,
        'turn_index' => 0,
        'order' => ['pc_1'],
        'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16))],
        'log' => [],
        'combatants' => [array_merge([
            'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Sera',
            'class' => 'Rogue', 'level' => 3, 'x' => 4, 'y' => 5,
            'speed' => 30, 'move_left' => $left,
            'hp' => 19, 'max_hp' => 19, 'alive' => true,
            'conditions' => [], 'boons' => [],
            'action_used' => false, 'bonus_used' => false,
            'bonus_uses_left' => null, 'bonus_features_used' => [],
        ], $extra)],
    ];
}

$who = fn(array $state) => $state['combatants'][0];

echo "== the action ==\n";
$after = $who($dash->invoke($engine, board(30)));
ok('a full move becomes two', $after['move_left'] === 60, "got {$after['move_left']}");
ok('and it costs the action', !empty($after['action_used']));

$after = $who($dash->invoke($engine, board(10)));
ok('it adds your speed rather than doubling what is left',
    $after['move_left'] === 40, "got {$after['move_left']}");

$after = $who($dash->invoke($engine, board(0)));
ok('a spent move still buys a full one', $after['move_left'] === 30, "got {$after['move_left']}");

$refused = false;
try {
    $dash->invoke($engine, board(30, ['action_used' => true]));
} catch (InvalidArgumentException $e) {
    $refused = true;
}
ok('the action cannot be spent twice', $refused);

echo "\n== Cunning Action, either way ==\n";
$after = $who($bonus->invoke($engine, board(30), 'cunning_action', 'dash'));
ok('the bonus dash moves you', $after['move_left'] === 60, "got {$after['move_left']}");
ok('and leaves the action alone', empty($after['action_used']));
ok('but spends the bonus', !empty($after['bonus_used']));

$after = $who($bonus->invoke($engine, board(30), 'cunning_action', 'disengage'));
ok('the bonus disengage sets the flag', !empty($after['disengaging']));
ok('and does not move you', $after['move_left'] === 30);

$after = $who($bonus->invoke($engine, board(30), 'cunning_action', ''));
ok('a client that names no half gets the one it was written against',
    !empty($after['disengaging']) && $after['move_left'] === 30);

$refused = false;
try {
    $bonus->invoke($engine, board(30), 'cunning_action', 'hide');
} catch (InvalidArgumentException $e) {
    $refused = true;
}
ok('a half the feature does not offer is refused', $refused);

$refused = false;
try {
    $bonus->invoke($engine, board(30), 'cunning_action', 'dash');
    $bonus->invoke($engine, board(30, ['bonus_used' => true]), 'cunning_action', 'dash');
} catch (InvalidArgumentException $e) {
    $refused = true;
}
ok('and neither half can be spent twice in a turn', $refused);

echo "\n== a class with no choice is unaffected ==\n";
$barb = board(30, ['class' => 'Barbarian', 'level' => 3, 'bonus_uses_left' => 3]);
$raged = false;
try {
    $after = $who($bonus->invoke($engine, $barb, 'rage', ''));
    $raged = !empty($after['bonus_used']);
} catch (Throwable $e) {
    $raged = false;
}
ok('rage still works with no option named', $raged);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
