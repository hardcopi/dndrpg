<?php
/**
 * Show sprite sets side by side, at the size the game draws them.
 *
 * Built to answer one question — does a character composited out of the T&C
 * paperdoll layers sit comfortably next to the Heroes and Townfolk art already
 * in the game? — and kept because the character creator will need exactly this
 * view while its layer order and colour variants are being tuned.
 *
 *   /tools/paperdoll_preview.php?keys=pc_sample,barbarian,cleric,ranger
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$keys = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_GET['keys'] ?? 'pc_sample,barbarian,fighter,ranger,cleric'))
)));

/** Only [a-z0-9_] — a key ends up in a path. */
$keys = array_filter($keys, static fn ($k) => (bool) preg_match('/^[a-z0-9_]+$/', $k));

function art(string $key, string $suffix): ?array
{
    $rel = "assets/images/npcs/{$key}{$suffix}.png";
    $abs = dirname(__DIR__) . '/' . $rel;
    if (!is_file($abs)) {
        return null;
    }
    $size = @getimagesize($abs);
    return ['url' => '../' . $rel, 'w' => $size[0] ?? 0, 'h' => $size[1] ?? 0];
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Paperdoll preview</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body { padding: 1.5rem; }
    .pd-row { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .pd-card {
      background: var(--bg-panel); border: 1px solid var(--border);
      border-radius: 8px; padding: 0.75rem; text-align: center; min-width: 150px;
    }
    .pd-card h3 { margin: 0 0 0.5rem; font-size: 0.8rem; color: var(--gold); }
    .pd-card .meta { font-family: var(--mono); font-size: 0.62rem; color: var(--text-faint); }
    /* One 128px cell of the sheet, at the size the map draws it. */
    .pd-walk {
      width: 128px; height: 128px; margin: 0 auto;
      background-repeat: no-repeat; background-size: 400% 800%;
      image-rendering: auto;
    }
    .pd-still img { height: 94px; image-rendering: auto; }
    .pd-face img { width: 96px; border-radius: 4px; }
    .pd-bust img { height: 200px; }
    .pd-missing { color: var(--danger); font-size: 0.7rem; padding: 1rem 0; }
    h2 { margin-top: 1.75rem; }
  </style>
</head>
<body>
  <h1>Paperdoll preview</h1>
  <p class="help-hint">
    <code>pc_sample</code> is composited from the T&amp;C modular layers by
    <code>tools/bake_paperdoll.py</code>. The rest are cut whole from the packs by
    <code>tools/slice_assets.py</code>. Same sizes, same pipeline from here on.
  </p>

  <?php foreach ([
      ['Map sprite — south, first walk frame', 'walk'],
      ['Standing frame', 'still'],
      ['Faceset (party rail, initiative ribbon)', 'face'],
      ['Bust (dialogue)', 'bust'],
  ] as [$title, $kind]): ?>
    <h2><?= htmlspecialchars($title) ?></h2>
    <div class="pd-row">
      <?php foreach ($keys as $key): ?>
        <div class="pd-card">
          <h3><?= htmlspecialchars($key) ?></h3>
          <?php
          if ($kind === 'walk') {
              $a = art($key, '_sheet');
              if ($a) {
                  // The sheet is 4 frames x 8 facings; show row 0 (south), frame 0.
                  echo '<div class="pd-walk" style="background-image:url(\''
                      . htmlspecialchars($a['url']) . '\');background-position:0 0"></div>';
                  echo '<div class="meta">' . $a['w'] . '&times;' . $a['h'] . '</div>';
              } else {
                  echo '<p class="pd-missing">no _sheet</p>';
              }
          } else {
              $suffix = ['still' => '', 'face' => '_face', 'bust' => '_bust'][$kind];
              $a = art($key, $suffix);
              if ($a) {
                  echo '<div class="pd-' . $kind . '"><img src="'
                      . htmlspecialchars($a['url']) . '" alt=""></div>';
                  echo '<div class="meta">' . $a['w'] . '&times;' . $a['h'] . '</div>';
              } else {
                  echo '<p class="pd-missing">no ' . htmlspecialchars($suffix ?: 'still') . '</p>';
              }
          }
          ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</body>
</html>
