<?php
/**
 * An authored dungeon plan: is it a place, and can it be walked?
 *
 *   docker compose exec -T php php /var/www/html/tools/test_plan.php
 *
 * PlanAuthored builds the same $level DungeonGen::generate() returns, then
 * plan() and tiles() do what they already do. This file asserts the drawing
 * is refused when it is broken, and that a four-room hole the studio would
 * save compiles to a raster the walker and the chart agree on. No database:
 * the compile is pure, for the same reason test_dungeon.php is.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/DungeonGen.php';
require_once dirname(__DIR__) . '/app/lib/PlanAuthored.php';

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
        echo "  FAIL $n" . ($d !== '' ? "\n         $d" : '') . "\n";
    }
}

function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

function threw(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
}

function hole(): array
{
    return [
        'rooms' => [
            ['id' => 0, 'key' => 'hole_door', 'name' => 'The door', 'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2],
            ['id' => 1, 'key' => 'hole_east', 'name' => 'East chamber', 'gx' => 5, 'gy' => 1, 'w' => 2, 'h' => 2],
            ['id' => 2, 'key' => 'hole_south', 'name' => 'South chamber', 'gx' => 1, 'gy' => 4, 'w' => 2, 'h' => 2],
            ['id' => 3, 'key' => 'hole_end', 'name' => 'The end', 'gx' => 5, 'gy' => 4, 'w' => 2, 'h' => 2],
        ],
        'halls' => [
            ['id' => 0, 'a' => 0, 'b' => 1, 'door' => 'door'],
            ['id' => 1, 'a' => 1, 'b' => 3, 'door' => 'open'],
            ['id' => 2, 'a' => 3, 'b' => 2, 'door' => 'locked', 'hidden' => true],
        ],
    ];
}

/** Which locations a flood fill of the tiles can reach from a starting tile. */
function flood(array $t, int $from): array
{
    $doors = [];
    foreach ($t['doors'] as $d) {
        $doors[$d['tile'] * 4 + $d['dir']] = true;
    }
    $walls = [];
    foreach ($t['walls'] as $w) {
        $walls[$w['tile'] * 4 + $w['dir']] = true;
    }
    $steps = [-$t['w'], 1, $t['w'], -1];

    $seen = [$from => true];
    $queue = [$from];
    $owners = [];
    while ($queue) {
        $at = array_shift($queue);
        $o = $t['owner'][$at] ?? null;
        if ($o !== null) {
            $owners[$o[0] . ':' . $o[1]] = true;
        }
        foreach ($steps as $dir => $off) {
            $to = $at + $off;
            if (!isset($t['owner'][$to]) || $t['owner'][$to] === null) {
                continue;
            }
            if (isset($walls[$at * 4 + $dir])) {
                continue;
            }
            $sameOwner = $t['owner'][$to] === $o;
            if (!$sameOwner && !isset($doors[$at * 4 + $dir])) {
                continue;
            }
            if (!isset($seen[$to])) {
                $seen[$to] = true;
                $queue[] = $to;
            }
        }
    }
    return $owners;
}

echo "== authored plans ==\n";

$msg = threw(fn () => PlanAuthored::normalize(['rooms' => []]));
ok('an empty drawing is refused', $msg !== null && str_contains((string) $msg, 'at least one'));

$msg = threw(fn () => PlanAuthored::normalize([
    'rooms' => [['id' => 0, 'key' => 'a', 'gx' => 8, 'gy' => 6, 'w' => 2, 'h' => 2]],
]));
ok('a room off the plan is refused', $msg !== null && str_contains((string) $msg, 'off the plan'));

$msg = threw(fn () => PlanAuthored::normalize([
    'rooms' => [
        ['id' => 0, 'key' => 'a', 'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2],
        ['id' => 1, 'key' => 'b', 'gx' => 3, 'gy' => 1, 'w' => 2, 'h' => 2],
    ],
]));
ok('rooms with no cell between them are refused', $msg !== null && str_contains((string) $msg, 'overlaps'));

$msg = threw(fn () => PlanAuthored::normalize([
    'rooms' => [['id' => 0, 'key' => 'Bad Key', 'gx' => 1, 'gy' => 1, 'w' => 1, 'h' => 1]],
]));
ok('a bad room key is refused', $msg !== null && str_contains((string) $msg, 'location key'));

$msg = threw(fn () => PlanAuthored::normalize([
    'rooms' => [
        ['id' => 0, 'key' => 'same', 'gx' => 1, 'gy' => 1, 'w' => 1, 'h' => 1],
        ['id' => 1, 'key' => 'same', 'gx' => 4, 'gy' => 1, 'w' => 1, 'h' => 1],
    ],
]));
ok('two rooms cannot share a key', $msg !== null && str_contains((string) $msg, 'share the key'));

$msg = threw(fn () => PlanAuthored::normalize([
    'rooms' => [['id' => 0, 'key' => 'a', 'gx' => 1, 'gy' => 1, 'w' => 1, 'h' => 1]],
    'halls' => [['a' => 0, 'b' => 9]],
]));
ok('a hall to a missing room is refused', $msg !== null && str_contains((string) $msg, 'join two'));

$plan = hole();
$norm = PlanAuthored::normalize($plan);
same('four rooms survive', 4, count($norm['rooms']));
same('and three halls', 3, count($norm['halls']));
ok('the secret hall stays secret', !empty($norm['halls'][2]['hidden']));

$compiled = PlanAuthored::compile($plan);
same('every hall routed', 0, $compiled['dropped']);
same('the chart has four rooms', 4, count($compiled['plan']['rooms']));
same('and three passages', 3, count($compiled['plan']['corridors']));

$level = $compiled['level'];
same('the first room is the entrance', 'entrance', $level['rooms'][0]['role']);
same('its name is the one that was drawn', 'The door', $level['rooms'][0]['name']);
same('its key is the one that was drawn', 'hole_door', $level['rooms'][0]['key']);

$again = PlanAuthored::compile($plan);
same('compile is deterministic', $compiled, $again);

echo "\n== the raster agrees with the graph ==\n";

$t = $compiled['tiles'];
$routes = DungeonGen::routes($level);
same('the grid is the plan sub-divided, plus a border',
    [DungeonGen::GRID_W * DungeonGen::SUB + 2, DungeonGen::GRID_H * DungeonGen::SUB + 2],
    [$t['w'], $t['h']]);

$reach = flood($t, $t['spines']['room'][$level['entrance']]);

$adj = [];
foreach ($level['corridors'] as $e) {
    if (!isset($routes[$e['id']])) {
        continue;
    }
    $adj[$e['a']][] = [$e['b'], (int) $e['id']];
    $adj[$e['b']][] = [$e['a'], (int) $e['id']];
}
$want = ['room:' . $level['entrance'] => true];
$queue = [$level['entrance']];
while ($queue) {
    $at = array_shift($queue);
    foreach ($adj[$at] ?? [] as [$to, $cid]) {
        $want['corridor:' . $cid] = true;
        if (!isset($want['room:' . $to])) {
            $want['room:' . $to] = true;
            $queue[] = $to;
        }
    }
}
ksort($want);
ksort($reach);
same('the raster reaches exactly what the graph reaches', $want, $reach);

$cutOff = 0;
foreach ($level['rooms'] as $r) {
    if (!isset($want['room:' . $r['id']])) {
        $cutOff++;
    }
}
ok('and no chamber is left without a way in', $cutOff === 0, "{$cutOff} were");

$notRect = 0;
foreach ($level['rooms'] as $r) {
    $expect = 0;
    for ($y = DungeonGen::SUB * $r['gy'] + 1; $y <= DungeonGen::SUB * ($r['gy'] + $r['h']); $y++) {
        for ($x = DungeonGen::SUB * $r['gx'] + 1; $x <= DungeonGen::SUB * ($r['gx'] + $r['w']); $x++) {
            if ($t['owner'][$y * $t['w'] + $x] !== ['room', (int) $r['id']]) {
                $notRect++;
            }
            $expect++;
        }
    }
    $got = 0;
    foreach ($t['owner'] as $o) {
        if ($o === ['room', (int) $r['id']]) {
            $got++;
        }
    }
    if ($got !== $expect) {
        $notRect++;
    }
}
ok('every room is its own footprint, exactly', $notRect === 0, "{$notRect} tiles were not");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
