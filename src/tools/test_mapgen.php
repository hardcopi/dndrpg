<?php
/**
 * The map service, and the adapter that turns its floors into levels.
 *
 * Separate from test_delve.php, which pins the delve engine against specific
 * rooms on specific floors and therefore forces the local generator. This
 * asserts what has to be true of ANY floor the service draws, because the
 * service is allowed to be a newer version of itself tomorrow.
 *
 * SKIPS ITSELF WHEN THERE IS NO SERVICE, and does not fail. An install without
 * the container is a supported install — the delve falls back to DungeonGen —
 * so "no service" is a configuration, not a broken test.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_mapgen.php
 */

declare(strict_types=1);

require_once '/var/www/html/app/bootstrap.php';

$passed = 0;
$failed = 0;

function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   {$what}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$what}\n";
    if ($detail !== '') {
        echo "         {$detail}\n";
    }
}

if (!MapService::available()) {
    echo "No RPG_MAPGEN_URL on this install — nothing to test.\n";
    echo "The delve uses DungeonGen, which test_dungeon.php covers.\n";
    exit(0);
}

echo "== the service answers ==\n";
$probe = MapService::floor(1234, 1, MapService::KIND_KEEP, ['cols' => 48, 'rows' => 36, 'roomCount' => 9]);
ok('a floor comes back', $probe !== null);
if ($probe === null) {
    echo "\nThe service is configured but not answering. Is the container up?\n";
    exit(1);
}
ok('with a grid', ($probe['cols'] ?? 0) >= 4 && ($probe['rows'] ?? 0) >= 4);
ok('with rooms', count($probe['rooms'] ?? []) >= 2);
ok('with a floor bitmap the size of the grid',
    count($probe['floor'] ?? []) === (int) $probe['cols'] * (int) $probe['rows']);
ok('and prose', ($probe['lore']['title'] ?? '') !== '');

echo "\n== the same seed is the same floor ==\n";
$a = MapService::floor(777, 2, MapService::KIND_KEEP, ['cols' => 48, 'rows' => 36, 'roomCount' => 9]);
$b = MapService::floor(777, 2, MapService::KIND_KEEP, ['cols' => 48, 'rows' => 36, 'roomCount' => 9]);
ok('twice over', json_encode($a) === json_encode($b));
$c = MapService::floor(777, 3, MapService::KIND_KEEP, ['cols' => 48, 'rows' => 36, 'roomCount' => 9]);
ok('and a different depth is a different floor', json_encode($a) !== json_encode($c));

echo "\n== every floor becomes a level ==\n";
$shape = array_keys(DungeonGen::generate(1, 1));
sort($shape);

$adapted = 0;
$refused = 0;
$badShape = [];
$unwalkable = 0;
$orphans = 0;
$rasterWrong = 0;

for ($s = 1; $s <= 20; $s++) {
    foreach ([1, 3, 5] as $depth) {
        $seed = $s * 104729;
        $dungeon = MapService::floor($seed, $depth, MapService::KIND_KEEP,
            ['cols' => 48, 'rows' => 36, 'roomCount' => 9]);
        if ($dungeon === null) {
            $refused++;
            continue;
        }
        $level = GeneratedLevel::fromDungeon($dungeon, $seed, $depth);
        if ($level === null) {
            $refused++;
            continue;
        }
        $adapted++;

        $mine = array_keys($level);
        sort($mine);
        $missing = array_diff($shape, $mine);
        if ($missing !== []) {
            $badShape[] = implode(',', $missing);
        }

        if (!DungeonGen::isWalkable($level)) {
            $unwalkable++;
        }

        // Every room reachable from the entrance. A room nobody can walk to is
        // a location written into the world that no player will ever see.
        $reach = DungeonGen::reachable($level);
        foreach ($level['rooms'] as $room) {
            if (!isset($reach[(int) $room['id']])) {
                $orphans++;
            }
        }

        // The raster must be the generator's grid plus its one-tile border, or
        // the fog is measured against a different floor than the chart draws.
        $tiles = DungeonGen::tiles($level);
        if ($tiles['w'] !== (int) $dungeon['cols'] + 2 || $tiles['h'] !== (int) $dungeon['rows'] + 2) {
            $rasterWrong++;
        }
    }
}

ok("{$adapted} floors adapted", $adapted > 0);
ok('none refused', $refused === 0, "{$refused} refused");
ok('every level has DungeonGen\'s shape', $badShape === [], implode(' | ', array_unique($badShape)));
ok('every level is walkable', $unwalkable === 0, "{$unwalkable} were not");
ok('no room is stranded', $orphans === 0, "{$orphans} unreachable rooms");
ok('the fog raster matches the grid', $rasterWrong === 0, "{$rasterWrong} wrong");

echo "\n== the fallback ==\n";
$was = MapService::url();
putenv('RPG_MAPGEN_URL=');
ok('no service means no floor', MapService::floor(1, 1, MapService::KIND_KEEP) === null);
ok('and MapService says so', !MapService::available());
putenv('RPG_MAPGEN_URL=' . $was);
ok('and it comes back when the url does', MapService::available());

echo "\n----------------------------------------------------\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
