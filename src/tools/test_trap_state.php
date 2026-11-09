<?php
/**
 * TrapState: the one table and writer for door and furnishing traps (audit #6).
 *   php tools/test_trap_state.php
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? " — $d" : '') . "\n"; }
}

$db = db();
// A party row of its own so the flags have somewhere to live.
$db->exec("INSERT INTO parties (name) VALUES ('trap-state test')");
$pid = (int) $db->lastInsertId();
$world = new WorldState($db);
$m = TrapState::machine();

echo "== the table ==\n";
ok('hidden → found by spotting', $m->can('hidden', 'found', 'spot'));
ok('hidden → sprung by walking into it', $m->can('hidden', 'sprung', 'fire'));
ok('found → sprung by disarming', $m->can('found', 'sprung', 'disarm'));
ok('a hidden trap cannot be disarmed', !$m->can('hidden', 'sprung', 'disarm'));
ok('sprung is terminal', $m->isTerminal('sprung'));
ok('it is listed with the others', isset(StateMachines::all()['trap_state']));

echo "\n== the writer ==\n";
$door = DelveEngine::trapFlag('_t_' . $pid . '_door');
$furn = DelveEngine::furnishingTrapFlag('_t_' . $pid . '_chest');
ok('no row reads as hidden', TrapState::of($world, $pid, $door) === 'hidden');
ok('spotting is allowed', TrapState::apply($world, $pid, $door, 'spot'));
ok('and stored as found', $world->get($pid, $door) === 'found');
ok('spotting again is a harmless no-op', TrapState::apply($world, $pid, $door, 'spot') && $world->get($pid, $door) === 'found');
ok('disarming a found trap is allowed', TrapState::apply($world, $pid, $door, 'disarm'));
ok('and stored as sprung, as saves always have', $world->get($pid, $door) === 'sprung');
ok('so it is spent', TrapState::isSpent($world, $pid, $door));
StateMachine::$refused = [];
ok('a spent trap cannot fire again', !TrapState::apply($world, $pid, $door, 'fire'));
ok('and the refusal is logged', count(StateMachine::$refused) === 1);
ok('disarming an unfound one is refused', !TrapState::apply($world, $pid, $furn, 'disarm'));
ok('and writes nothing', $world->get($pid, $furn) === null);
ok('walking into a hidden one sets it off', TrapState::apply($world, $pid, $furn, 'fire') && $world->get($pid, $furn) === 'sprung');

echo "\n== the engines use it ==\n";
foreach (['DoorEngine', 'FurnishingEngine', 'LocationEngine'] as $cls) {
    $src = (string) file_get_contents(APP_PATH . "/lib/{$cls}.php");
    $raw = preg_match_all("/->set\\([^;]*'(found|sprung)'\\)/", $src);
    ok("{$cls} writes no raw trap value", $raw === 0, "{$raw} raw write(s)");
}

$db->prepare('DELETE FROM world_flags WHERE party_id = ?')->execute([$pid]);
$db->prepare('DELETE FROM parties WHERE id = ?')->execute([$pid]);
echo str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
