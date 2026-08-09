<?php
/**
 * Look at a generated dungeon without starting a game.
 *
 * Run with:
 *   docker compose exec -T php php /var/www/html/tools/dungeon_preview.php
 *   ... --seed 4471 --depth 2
 *   ... --sweep 8            # eight levels at a glance, to judge the variety
 *
 * DungeonGen is pure, so this needs nothing behind it — no MySQL, no session,
 * no party. It draws the coarse room plan the generator actually laid out,
 * which is the thing worth judging: whether the levels look like places.
 *
 * The map ui-map.js will draw is the same graph projected onto its 0-100 by
 * 0-75 field; this shows the plan the projection came from, because a bug in
 * the layout is obvious here and invisible there.
 */

declare(strict_types=1);

require_once '/var/www/html/app/lib/DungeonGen.php';

$args = $argv;
array_shift($args);
$opt = static function (string $name, $default) use ($args) {
    $i = array_search('--' . $name, $args, true);
    return $i === false || !isset($args[$i + 1]) ? $default : $args[$i + 1];
};

$sweep = (int) $opt('sweep', 0);
$seed  = (int) $opt('seed', 4471);
$depth = (int) $opt('depth', 1);

/** One glyph per room kind, so a level can be read at a glance. */
const GLYPH = [
    'empty'    => '.',
    'monster'  => 'M',
    'hoard'    => 'H',
    'treasure' => '$',
    'trap'     => 'X',
    'trick'    => '?',
    'boss'     => 'B',
];

function draw(array $level): void
{
    $w = DungeonGen::GRID_W;
    $h = DungeonGen::GRID_H;

    // Two plan cells per room cell across, so a corridor has somewhere to be
    // drawn between two rooms that are one cell apart.
    $cw = $w * 4;
    $ch = $h * 2;
    $canvas = array_fill(0, $ch, array_fill(0, $cw, ' '));

    $put = static function (int $x, int $y, string $glyph) use (&$canvas) {
        if ($y >= 0 && $y < count($canvas) && $x >= 0 && $x < count($canvas[0])) {
            $canvas[$y][$x] = $glyph;
        }
    };

    // Corridors first, so rooms draw over them.
    foreach ($level['edges'] as $e) {
        $a = $level['rooms'][$e['a']];
        $b = $level['rooms'][$e['b']];
        $ax = (int) round(($a['gx'] + $a['w'] / 2) * 4);
        $ay = (int) round(($a['gy'] + $a['h'] / 2) * 2);
        $bx = (int) round(($b['gx'] + $b['w'] / 2) * 4);
        $by = (int) round(($b['gy'] + $b['h'] / 2) * 2);
        // An L, horizontal then vertical — which is how a dungeon corridor is
        // drawn on paper and how DelveEngine will describe it in prose.
        for ($x = min($ax, $bx); $x <= max($ax, $bx); $x++) {
            $put($x, $ay, '-');
        }
        for ($y = min($ay, $by); $y <= max($ay, $by); $y++) {
            $put($bx, $y, '|');
        }
    }

    foreach ($level['rooms'] as $room) {
        $x0 = $room['gx'] * 4;
        $y0 = $room['gy'] * 2;
        $x1 = $x0 + $room['w'] * 4 - 2;
        $y1 = $y0 + $room['h'] * 2 - 1;
        // Wipe the footprint before walling it, or the corridors drawn above
        // show through the room and it reads as a passage crossing a chamber.
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $put($x, $y, ' ');
            }
        }
        for ($x = $x0; $x <= $x1; $x++) {
            $put($x, $y0, '#');
            $put($x, $y1, '#');
        }
        for ($y = $y0; $y <= $y1; $y++) {
            $put($x0, $y, '#');
            $put($x1, $y, '#');
        }
        $mark = $room['role'] === 'entrance' ? '<'
              : ($room['role'] === 'stair' ? '>' : (GLYPH[$room['kind']] ?? '.'));
        $put($x0 + 1, $y0 + ($y1 > $y0 ? 1 : 0), $mark);
        $put($x0 + 2, $y0 + ($y1 > $y0 ? 1 : 0), (string) $room['id']);
    }

    foreach ($canvas as $row) {
        echo '  ' . rtrim(implode('', $row)) . PHP_EOL;
    }
}

function summarise(array $level): string
{
    $counts = [];
    foreach ($level['rooms'] as $r) {
        $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
    }
    ksort($counts);
    $parts = [];
    foreach ($counts as $kind => $n) {
        $parts[] = "{$n} {$kind}";
    }
    return implode(', ', $parts);
}

$levels = $sweep > 0
    ? array_map(static fn ($i) => DungeonGen::generate(1000 + $i * 7919, ($i % 3) + 1), range(0, $sweep - 1))
    : [DungeonGen::generate($seed, $depth)];

foreach ($levels as $level) {
    $walkable = DungeonGen::isWalkable($level) ? 'walkable' : 'NOT WALKABLE';
    printf(
        "%sseed %d, depth %d (%s) — %d rooms, %d ways, in at %d, down at %d — %s%s",
        PHP_EOL,
        $level['seed'],
        $level['depth'],
        $level['profile'],
        count($level['rooms']),
        count($level['edges']),
        $level['entrance'],
        $level['stair'],
        $walkable,
        PHP_EOL . PHP_EOL
    );
    draw($level);
    echo PHP_EOL . '  ' . summarise($level) . PHP_EOL;
    echo '  ' . dressing($level) . PHP_EOL;
}

/**
 * The dressing, one line: door mix, rigged passages, the level's air.
 * This is the quick way to eyeball the upgrade rates across a --sweep
 * without counting glyphs off a drawing.
 */
function dressing(array $level): string
{
    $doors = [];
    foreach ($level['edges'] as $e) {
        $doors[$e['door']] = ($doors[$e['door']] ?? 0) + 1;
    }
    ksort($doors);
    $parts = [];
    foreach ($doors as $door => $n) {
        $parts[] = "{$n} {$door}";
    }
    $traps = [];
    foreach ($level['corridors'] as $c) {
        if (!empty($c['trap'])) {
            $traps[] = "c{$c['id']} {$c['trap']['key']}";
        }
    }
    return 'doors: ' . implode(', ', $parts)
        . ' — traps: ' . ($traps ? implode(', ', $traps) : 'none')
        . ' — air: ' . $level['atmosphere']['key'];
}

echo PHP_EOL . '  < in   > down   M monster   H hoard   $ treasure   X trap   ? trick   B boss'
    . PHP_EOL . PHP_EOL;
