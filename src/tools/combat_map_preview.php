<?php
/**
 * Generated battlefields, drawn by the real board, with no fight running.
 *
 * BattleMapGen stamps terrain; the engine reads those characters for cover
 * and movement. This page is whether the furniture sitting on that terrain
 * looks like the cover it is — a crate on half cover, a column on tall cover,
 * not a tree in the open.
 *
 *   /tools/combat_map_preview.php
 *   /tools/combat_map_preview.php?palette=interior&seed=4242
 *   /tools/combat_map_preview.php?n=12
 *
 * A drawing bench. Pure BattleMapGen, no database, no session.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/BattleGrid.php';
require_once dirname(__DIR__) . '/app/lib/BattleMapGen.php';
require_once dirname(__DIR__) . '/app/lib/FixedBattleMaps.php';

$palettes = ['interior', 'street', 'cavern', 'tunnel', 'forest', 'camp', 'arena'];
$one = isset($_GET['seed']) || isset($_GET['palette']);
$seed = (int) ($_GET['seed'] ?? 4242);
$want = strtolower((string) ($_GET['palette'] ?? ''));
if ($want && !in_array($want, $palettes, true)) {
    $want = 'interior';
}
$count = $one ? 1 : max(1, min(14, (int) ($_GET['n'] ?? 7)));

$maps = [];
// `?fixed=<location_key>` shows a hand-drawn board instead of generated ones,
// which is the only way to look at one without starting a fight in the place
// it belongs to. See FixedBattleMaps.
$fixed = trim((string) ($_GET['fixed'] ?? ''));
if ($fixed !== '' && FixedBattleMaps::has($fixed)) {
    $maps[] = FixedBattleMaps::get($fixed, $seed);
} elseif ($one) {
    $palette = $want ?: 'interior';
    $maps[] = BattleMapGen::generate($seed, $palette);
} else {
    foreach ($palettes as $i => $palette) {
        if (count($maps) >= $count) {
            break;
        }
        $maps[] = BattleMapGen::generate($seed + $i * 7919, $palette);
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Combat maps — generated boards</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    body { margin: 0; padding: 1rem; background: var(--bg-inset); }
    h1 { font-size: 1rem; color: var(--gold); margin: 0 0 0.4rem; }
    .note { color: var(--text-dim); font-size: 0.8rem; max-width: 72ch; margin: 0 0 1rem; }
    .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(22rem, 1fr)); gap: 1rem; }
    .board { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px; padding: 0.6rem; }
    .board h2 { margin: 0 0 0.4rem; font-size: 0.8rem; color: var(--gold); }
    .board .cbt-map { height: 16rem; padding: 0.3rem; }
    .meta { font-size: 0.7rem; color: var(--text-dim); font-family: var(--mono); margin-top: 0.3rem; }
  </style>
</head>
<body class="game-page">
  <h1>Generated battlefields</h1>
  <p class="note">
    Real <code>BattleMapGen</code> and the shipped board renderer.
    Furniture is a picture of the terrain already under it — crates are half
    cover, columns are tall cover. <code>?palette=interior&amp;seed=4242</code> for one,
    <code>?n=12</code> for a dozen.
  </p>
  <div class="gallery" id="gallery"></div>
  <script>
  window.Game = {
    esc: (s) => String(s ?? '').replace(/[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
    TILE_CACHE_VER: 'bench',
  };
  </script>
  <script src="/assets/js/ui-battlemap.js"></script>
  <script>
  const MAPS = <?= json_encode($maps, JSON_UNESCAPED_SLASHES) ?>;
  const gallery = document.getElementById('gallery');
  for (const grid of MAPS) {
    const card = document.createElement('div');
    card.className = 'board';
    const n = (grid.props || []).length;
    card.innerHTML = `<h2>${Game.esc(grid.palette)} · seed ${Game.esc(grid.seed)}</h2>
      <div class="cbt-map"></div>
      <div class="meta">${n} prop${n === 1 ? '' : 's'} · ${Game.esc(grid.floor || '')}</div>`;
    const state = {
      round: 2,
      turn_index: 0,
      order: [],
      combatants: [],
      grid,
      ui: null,
    };
    card.querySelector('.cbt-map').innerHTML = BattleMap.html(state, { selected: null, faceUrl: () => '' });
    gallery.appendChild(card);
  }
  </script>
</body>
</html>
