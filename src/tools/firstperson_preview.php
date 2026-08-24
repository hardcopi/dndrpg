<?php
/**
 * The gold-box view on a bench, with no game running.
 *
 *   /tools/firstperson_preview.php                 a floor, and every door on it
 *   /tools/firstperson_preview.php?seed=&depth=    a particular one
 *   /tools/firstperson_preview.php?n=8             more views down the corridors
 *
 * ui-firstperson.js's own header says why this can exist: "NO DOM, NO GAME.
 * Everything below takes a raster and a cursor and returns a string or a plain
 * object, which is what lets tools/floorplan_preview.php exist for the chart and
 * would let the same bench exist for this." This is that bench.
 *
 * WHY IT WAS WORTH BUILDING RATHER THAN WALKING. Judging how a door or a wall
 * texture looks meant descending, finding a door, and turning until it was
 * square on — and a door that is not square on projects off the side of the
 * picture, where SVG clips it away. Three separate attempts to photograph one
 * caught a wall, a door already off-frame, and a panel the game redrew from the
 * server while the camera was pointed at it. None of that is a fault in the
 * view; it is a fault in looking at the view through a live game.
 *
 * The floor and the raster come from DungeonGen — the same two calls
 * DelveEngine makes — so what is drawn here is a real level and not a fixture.
 * The renderer is `window.FirstPerson.svg`, the game's own, against the shipped
 * stylesheet: a copy of either would agree with the screen right up until one
 * of them was fixed.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$seed  = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : random_int(1, 999999);
$depth = max(1, min(5, (int) ($_GET['depth'] ?? 2)));
$extra = max(0, min(24, (int) ($_GET['n'] ?? 6)));

$level = DungeonGen::generate($seed, $depth);

/*
 * The raster the CLIENT is shipped, not the one DungeonGen returns.
 *
 * `DungeonGen::tiles()` speaks the engine's own vocabulary — `solid`, `owner`,
 * doors keyed `tile`/`dir`/`kind` — and `DelveEngine::fogTiles()` is what turns
 * that into the `rows`/`locs`/`doors[{t,d,k,to}]` shape ui-firstperson.js reads.
 * Feeding the raw one straight to the renderer is a bench that draws something
 * the game never sends; the first cut of this file did exactly that and filled
 * the page with "Undefined array key" for its trouble.
 *
 * No fog. `seen` defaults to true in fogTiles, so a plan that says nothing
 * about it shows the whole floor — which is what a bench wants, and the same
 * argument the printed book makes for leaving `seen` out entirely.
 *
 * Room ids stand in for location ids, exactly as floorplan_preview.php does it:
 * there is no `locations` table here, and all the renderer needs is that the
 * ids agree with themselves.
 */
$plan = DungeonGen::plan($level);
foreach ($plan['rooms'] as $i => $r) {
    $plan['rooms'][$i]['location_id'] = (int) $r['id'];
}
foreach ($plan['corridors'] as $i => $c) {
    $plan['corridors'][$i]['location_id'] = 1000 + $i;
}
$tiles = DelveEngine::fogTiles(DungeonGen::tiles($level), $plan);

/**
 * Somewhere to stand, and something to look at.
 *
 * A door is shown from the tile it is set in, facing it — which is where a
 * party stands when they are about to open it, and the only place it fills the
 * frame. The corridor views are the entrance walked forward, which is what the
 * first thirty seconds of a delve actually look like.
 *
 * @return list<array{x:int,y:int,facing:int,label:string}>
 */
function shots(array $tiles, int $extra): array
{
    $w = (int) $tiles['w'];
    $dirs = ['north', 'east', 'south', 'west'];
    $out = [];

    foreach (($tiles['doors'] ?? []) as $i => $d) {
        $t = (int) $d['t'];
        $out[] = [
            'x' => $t % $w,
            'y' => intdiv($t, $w),
            'facing' => (int) $d['d'],
            'label' => 'Door ' . ($i + 1) . ' — ' . ((string) ($d['k'] ?? 'door'))
                     . ', facing ' . $dirs[(int) $d['d']],
        ];
    }

    // Corridors: stand on each stair and look each way, then a few tiles picked
    // across the floor so the set is not all one room.
    $rows = $tiles['rows'] ?? [];
    $open = [];
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < strlen((string) $row); $x++) {
            if ($row[$x] !== ' ') {
                $open[] = [$x, $y];
            }
        }
    }
    if ($open && $extra > 0) {
        $step = max(1, intdiv(count($open), $extra));
        for ($i = 0, $n = 0; $i < count($open) && $n < $extra; $i += $step, $n++) {
            [$x, $y] = $open[$i];
            $out[] = ['x' => $x, 'y' => $y, 'facing' => $n % 4,
                      'label' => "Floor at {$x},{$y} — facing " . $dirs[$n % 4]];
        }
    }
    return $out;
}

$views = shots($tiles, $extra);
$css = dirname(__DIR__) . '/assets/css/style.css';
$js  = dirname(__DIR__) . '/assets/js/ui-firstperson.js';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>First-person bench — seed <?= (int) $seed ?>, level <?= (int) $depth ?></title>
  <!-- Root-relative, not asset(): this page is served from /tools/, so a
       request-relative path resolves to /tools/assets/... and 404s.
       floorplan_preview.php carries the same line for the same reason. -->
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= is_file($css) ? filemtime($css) : '0' ?>">
  <style>
    body { padding: 1.4rem; background: var(--bg-inset); }
    h1 { font-family: var(--font-display); color: var(--gold); font-size: 1.1rem; }
    .note { color: var(--text-dim); font-size: 0.82rem; max-width: 72ch;
            line-height: 1.6; margin: 0 0 1.2rem; }
    .bench { display: grid; gap: 1.1rem;
             grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); }
    .shot { border: 1px solid var(--border-soft); border-radius: var(--r-md);
            padding: 0.6rem; background: var(--bg-raised); }
    .shot h2 { font-size: 0.74rem; color: var(--gold-dim); margin: 0 0 0.4rem;
               border: 0; padding: 0; font-weight: var(--fw-semibold); }
    /* The panel the game gives this view, so a thing that fits here fits there. */
    .shot .fp-wrap { position: relative; background: #000;
                     border-radius: var(--r-sm); overflow: hidden; }
    .shot svg { display: block; width: 100%; height: auto; }
  </style>
</head>
<body class="game-page">
  <h1>First-person bench — seed <?= (int) $seed ?>, level <?= (int) $depth ?></h1>
  <p class="note">
    Drawn by <code>window.FirstPerson.svg</code> against the shipped stylesheet —
    the renderer the game uses, not a copy. Every door on this floor is shown from
    the tile it is set in, facing it, which is where a party stands to open it and
    the only place it fills the frame. Reload for another floor, or pass
    <code>?seed=&amp;depth=</code> for a particular one and <code>?n=</code> for
    more corridor views.
  </p>

  <div class="bench" id="bench"></div>

  <script src="/assets/js/ui-firstperson.js?v=<?= is_file($js) ? filemtime($js) : '0' ?>"></script>
  <script>
    const TILES = <?= json_encode($tiles) ?>;
    const VIEWS = <?= json_encode($views) ?>;
    const bench = document.getElementById('bench');

    for (const v of VIEWS) {
      const card = document.createElement('div');
      card.className = 'shot';
      const h = document.createElement('h2');
      h.textContent = v.label;
      const wrap = document.createElement('div');
      wrap.className = 'fp-wrap';
      // The textures are referenced relative to the page, and this page is in
      // /tools/ — so the base has to be spelled out here exactly as game.php's
      // own default would resolve from the web root.
      wrap.innerHTML = window.FirstPerson.svg(TILES, v, {
        label: v.label,
        textures: '/assets/images/fp/',
      });
      card.append(h, wrap);
      bench.appendChild(card);
    }
  </script>
</body>
</html>
