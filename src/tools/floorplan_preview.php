<?php
/**
 * Generated dungeon floors, drawn by the real chart, with no game running.
 *
 * `dungeon_preview.php` prints the coarse plan as ASCII, which is the right
 * tool for judging whether the LAYOUT is any good — room sizes, corridors,
 * whether a level looks like a place. This is the other half: whether the
 * layout survives being drawn. A plan can be perfectly well formed and still
 * come out with rooms overlapping their own labels, corridors crossing rooms
 * they have nothing to do with, or a 1x1 chamber rendered as a slot.
 *
 * The SVG here is produced by `window.WorldMap.svg` — the shipped renderer,
 * not a copy of it — against the shipped stylesheet. What is faked is the map
 * object the server would have sent, built here from `DungeonGen::plan()`,
 * which is the same function `DelveEngine::planFor()` calls. So a drawing
 * fault visible here is a drawing fault in the game.
 *
 *   /tools/floorplan_preview.php                     four levels, mixed depths
 *   /tools/floorplan_preview.php?seed=4471&depth=3   one, at a size worth reading
 *   /tools/floorplan_preview.php?n=12                a dozen, to judge variety
 *
 * A drawing bench. Pure DungeonGen, no database, no session, no party.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/DungeonGen.php';
// For DelveEngine::fog() only, which is static and touches no database. The
// fog is most of what a floor plan looks like now, so a bench that drew the
// whole level would be previewing a screen no player ever sees — the mistake
// `visited` below records having made once already.
require_once dirname(__DIR__) . '/app/lib/DelveEngine.php';

$one   = isset($_GET['seed']);
$seed  = (int) ($_GET['seed'] ?? 4471);
$depth = max(1, min(5, (int) ($_GET['depth'] ?? 1)));
$count = $one ? 1 : max(1, min(24, (int) ($_GET['n'] ?? 4)));

/**
 * The map object `location/state` would have carried, for one generated floor.
 *
 * Room ids stand in for location ids. The real thing resolves them through the
 * room key against the `locations` table; here there is no table, and the only
 * thing that matters to the renderer is that the ids agree between the nodes,
 * the edges and the plan — which is exactly what planFor() guarantees.
 */
function fakeMap(int $seed, int $depth): array
{
    $level = DungeonGen::generate($seed, $depth);
    $plan = DungeonGen::plan($level);

    // A party three rooms in, which is the state the game spends almost all of
    // its time in. Walked rather than sampled: the set is grown from the
    // entrance along real edges, so what is visited is CONNECTED, which is the
    // only shape a party can actually produce. A scatter of visited rooms —
    // `id % 4 === 1`, which is what stood here — previews a fog that can never
    // happen and hides the one thing worth looking at, the fringe where the
    // seen floor meets the dark.
    //
    // (Before that it was `true` for every room, with a comment claiming a
    // half-visited floor was "a state the game does not produce" — exactly
    // backwards, and why the bench once showed a readable level while the game
    // showed blank boxes.)
    $walkedRooms = [(int) $level['entrance'] => true];
    $walkedHalls = [];
    $frontier = [(int) $level['entrance']];
    while (count($walkedRooms) < 3 && $frontier) {
        $from = array_shift($frontier);
        foreach ($plan['corridors'] as $c) {
            $a = (int) $c['a'];
            $b = (int) $c['b'];
            if ($a !== $from && $b !== $from) {
                continue;
            }
            $to = $a === $from ? $b : $a;
            if (isset($walkedRooms[$to]) || count($walkedRooms) >= 3) {
                continue;
            }
            $walkedHalls[(int) $c['id']] = true;
            $walkedRooms[$to] = true;
            $frontier[] = $to;
        }
    }
    // Wherever the walk ended is where the party is standing.
    $hereRoom = array_key_last($walkedRooms);

    $nodes = [];
    foreach ($level['rooms'] as $r) {
        $nodes[] = [
            'id'      => (int) $r['id'],
            'key'     => 'r' . $r['id'],
            'name'    => $r['name'],
            'type'    => $r['role'] === 'stair' ? 'gate' : 'room',
            'x'       => $r['x'],
            'y'       => $r['y'],
            'visited' => isset($walkedRooms[(int) $r['id']]),
            'current' => (int) $r['id'] === $hereRoom,
        ];
    }

    // Passages are locations too, so they are nodes on the chart like the
    // rooms. Their ids are offset past the rooms' here for the same reason
    // DelveEngine gives them a `c` prefix in the real key: the two number from
    // zero independently, and nothing else keeps them apart.
    $hallId = fn (int $corridor): int => 1000 + $corridor;
    foreach ($plan['corridors'] as $c) {
        $nodes[] = [
            'id'      => $hallId((int) $c['id']),
            'key'     => 'c' . $c['id'],
            'name'    => $level['corridors'][(int) $c['id']]['name'],
            'type'    => 'passage',
            'x'       => $c['mid'][0],
            'y'       => $c['mid'][1],
            'visited' => isset($walkedHalls[(int) $c['id']]),
            'current' => false,
        ];
    }

    // Room, passage, room — the shape the graph actually has now.
    $edges = [];
    foreach ($plan['corridors'] as $c) {
        $edges[] = ['from' => (int) $c['a'], 'to' => $hallId((int) $c['id'])];
        $edges[] = ['from' => $hallId((int) $c['id']), 'to' => (int) $c['b']];
    }

    foreach ($plan['rooms'] as $i => $r) {
        $plan['rooms'][$i]['location_id'] = (int) $r['id'];
    }
    foreach ($plan['corridors'] as $i => $c) {
        $plan['corridors'][$i]['location_id'] = $hallId((int) $c['id']);
    }

    // What planFor() would have decided for a real party, faked both ways so
    // the whole vocabulary is on the bench at once. The plan arrives from
    // DungeonGen with GM truth — every secret drawn, every trap a bare key —
    // and the game never ships it like that: planFor() hides unfound secrets,
    // downgrades unmet trapped doors, and turns met traps into {kind, state}.
    // Here, the FIRST stuck door is left visible (found) and the rest hidden
    // (unfound); the first trap is shown found, the second sprung, the rest
    // unmet. ?spoilers=1 skips all of it and shows the GM map.
    if (empty($_GET['spoilers'])) {
        $stuckSeen = false;
        $trapsSeen = 0;
        foreach ($plan['corridors'] as $i => $c) {
            if ($c['door'] === 'stuck') {
                if ($stuckSeen) {
                    $plan['corridors'][$i]['door'] = null;
                    $plan['corridors'][$i]['found'] = false;
                }
                $stuckSeen = true;
            }
            if (!empty($c['trap'])) {
                $trapsSeen++;
                if ($trapsSeen === 1) {
                    $plan['corridors'][$i]['trap'] = ['kind' => $c['trap'], 'state' => 'found'];
                } elseif ($trapsSeen === 2) {
                    $plan['corridors'][$i]['trap'] = ['kind' => $c['trap'], 'state' => 'sprung'];
                } else {
                    $plan['corridors'][$i]['trap'] = null;
                    if ($plan['corridors'][$i]['door'] === 'trapped') {
                        $plan['corridors'][$i]['door'] = 'door';
                    }
                }
            }
        }
    } else {
        // GM view: every trap marked as found so the marks are drawable.
        foreach ($plan['corridors'] as $i => $c) {
            if (!empty($c['trap'])) {
                $plan['corridors'][$i]['trap'] = ['kind' => $c['trap'], 'state' => 'found'];
            }
        }
    }

    // The fog, from the real rule. The visited set is the same one the nodes
    // above carry, so what the plan hides and what the labels withhold cannot
    // disagree here any more than they can in the game — both are that set,
    // read twice. `?spoilers=1` skips it and draws the GM's whole floor.
    if (empty($_GET['spoilers'])) {
        $visited = [];
        foreach ($nodes as $n) {
            if ($n['visited']) {
                $visited[(int) $n['id']] = true;
            }
        }
        $plan = DelveEngine::fog($plan, $visited);
    }

    return [
        'map' => [
            'region_id'   => 1,
            'region_key'  => '_dg_1_' . $depth,
            'name'        => 'The Undervault, level ' . $depth,
            'region_type' => 'dungeon',
            'nodes'       => $nodes,
            'edges'       => $edges,
            'neighbors'   => [],
            'floorplan'   => $plan,
        ],
        // The ways out of the room the party is standing in, so the corridor
        // highlighting is exercised rather than just the resting state.
        // A way out of the entrance is now the PASSAGE that leaves it, not the
        // room at the far end — which is exactly what the change is about, and
        // what makes the highlighting on the runs worth looking at here.
        'ways' => array_values(array_map(
            static fn (array $c): array => [
                'id' => $hallId((int) $c['id']),
                'locked' => false,
                'label' => 'On',
            ],
            array_filter(
                $plan['corridors'],
                fn (array $c): bool =>
                    (int) $c['a'] === $hereRoom || (int) $c['b'] === $hereRoom
            )
        )),
        'profile' => $level['profile'],
        'rooms'   => count($level['rooms']),
        'seed'    => $seed,
        'depth'   => $depth,
    ];
}

$floors = [];
for ($i = 0; $i < $count; $i++) {
    // Depth cycles with the index when several are drawn, so one page shows
    // all three profiles rather than four samples of the same one.
    $floors[] = fakeMap($seed + $i * 977, $one ? $depth : (($i % 3) * 2) + 1);
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dungeon floor plans — drawn by the real chart</title>
  <?php
  // Cache-busted the way game.php does it. Without this the bench serves
  // whatever style.css the browser last saw, so a change to the plan's own
  // CSS appears to have done nothing — which is the exact opposite of what a
  // drawing bench is for.
  $css = dirname(__DIR__) . '/assets/css/style.css';
  ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= is_file($css) ? filemtime($css) : '0' ?>">
  <style>
    body { padding: 1.5rem; background: var(--bg-inset); }
    .bench { display: grid; gap: 1.4rem;
             grid-template-columns: repeat(auto-fill, minmax(<?= $one ? '40rem' : '26rem' ?>, 1fr)); }
    .floor { border: 1px solid var(--border-soft); border-radius: 0.4rem;
             padding: 0.7rem; background: var(--bg-raised); }
    .floor h2 { font-size: 0.82rem; color: var(--gold); margin: 0 0 0.5rem;
                border: none; padding: 0; }
    .floor .meta { color: var(--text-faint); font-weight: 400; }
    .note { color: var(--text-dim); font-size: 0.8rem; max-width: 68ch;
            margin: 0 0 1.2rem; line-height: 1.6; }
  </style>
</head>
<body class="game-page">
  <p class="note">
    Drawn by <code>window.WorldMap.svg</code> against the shipped stylesheet —
    the renderer the game uses, not a copy. The room you start in is
    highlighted and its corridors are drawn as ways out. The plate is a
    player's map by default — one secret door shown found, the rest hidden,
    one trap marked found and one sprung — add <code>?spoilers=1</code> for
    the whole GM truth.
  </p>
  <p class="note">
    The legend, learned once:
    <strong>□</strong> a door &nbsp;·&nbsp;
    <strong>□ barred</strong> locked &nbsp;·&nbsp;
    <strong>□ crossed</strong> a known trap in the doorway &nbsp;·&nbsp;
    <strong>┄</strong> portcullis &nbsp;·&nbsp;
    <strong>╌ s</strong> a found secret way &nbsp;·&nbsp;
    <strong>|||</strong> stairs (shortening = down) &nbsp;·&nbsp;
    <strong>△</strong> a trap, spotted &nbsp;·&nbsp;
    <strong>▲</strong> a trap, sprung.
  </p>
  <div class="bench" id="bench"></div>

  <script>
    /* ui-map.js reaches for Game.esc, and nothing else of the game. */
    window.Game = { esc: (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c])) };
    const FLOORS = <?= json_encode($floors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    /* A party standing in the entrance, so the faces are drawn here too. Real
       sprite keys and one member down, because the thing worth looking at is
       whether a pile of portraits fits under a room NAME at floor-plan scale —
       which is smaller than a region's, and is the case the token size is
       scaled for. */
    const PARTY = [
      { name: 'Aldric', face: '/assets/images/npcs/fighter_face.png', hp: 24, max_hp: 24, level: 3, active: true },
      { name: 'Bryn',   face: '/assets/images/npcs/rogue_face.png',   hp: 15, max_hp: 19, level: 3 },
      { name: 'Cass',   face: '/assets/images/npcs/wizard_face.png',  hp: 0,  max_hp: 14, level: 3 },
      { name: 'Dev',    face: '/assets/images/npcs/cleric_face.png',  hp: 18, max_hp: 21, level: 3 },
      { name: 'Eward',  face: '/assets/images/npcs/ranger_face.png',  hp: 20, max_hp: 20, level: 3 },
      { name: 'Fenn',   face: '/assets/images/npcs/bard_face.png',    hp: 12, max_hp: 16, level: 3 },
    ];
  </script>
  <script src="/assets/js/ui-map.js"></script>
  <script>
    document.getElementById('bench').innerHTML = FLOORS.map((f) => `
      <div class="floor">
        <h2>seed ${f.seed} · depth ${f.depth}
          <span class="meta">· ${f.profile} · ${f.rooms} rooms</span></h2>
        ${window.WorldMap.svg(f.map, { ways: f.ways, party: PARTY })}
      </div>`).join('');
  </script>
</body>
</html>
