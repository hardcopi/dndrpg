<?php
/**
 * The ambush warning, drawn by the real dialog, with no game running.
 *
 * What the party is about to be asked to fight is the one thing that dialog
 * exists to tell them — "Hold back" is not a choice anybody can make against a
 * name alone — but seeing it means arranging a save: a party standing on an
 * ambush it has not cleared, one fight at a time, and no way to compare two of
 * them side by side.
 *
 * So this draws every authored ambush at once against a party that really
 * exists, using the shipped stylesheet and the same markup game.js builds. A
 * layout fault visible here is a layout fault in the game.
 *
 *   /tools/ambush_preview.php                every ambush, against any party
 *   /tools/ambush_preview.php?party=78       against that one, to see it scale
 *
 * What it does NOT prove is the scaling, which is the server's arithmetic and
 * is covered by tools/test_combat.php. This is a drawing bench: it is here so
 * that eight species of foe in one dialog can be looked at before somebody
 * meets it.
 *
 * Read-only: it writes no row, clears nothing, and starts no fight. The roster
 * comes from CombatEngine::roster(), which is the call the scene makes and the
 * scaling startEncounter() will apply.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();

$partyId = (int) ($_GET['party'] ?? 0);
$who = $db->prepare(
    'SELECT c.id, c.name, c.level, cp.party_id, p.name AS party_name
       FROM character_party cp
       INNER JOIN characters c ON c.id = cp.character_id
       INNER JOIN parties p ON p.id = cp.party_id
      WHERE (? = 0 OR cp.party_id = ?)
      ORDER BY cp.party_id, c.id LIMIT 1'
);
$who->execute([$partyId, $partyId]);
$char = $who->fetch();

$parties = $db->query(
    'SELECT p.id, p.name, COUNT(*) AS n, MAX(c.level) AS lvl
       FROM parties p
       INNER JOIN character_party cp ON cp.party_id = p.id
       INNER JOIN characters c ON c.id = cp.character_id
      GROUP BY p.id, p.name ORDER BY n DESC, p.id LIMIT 12'
)->fetchAll();

$cards = [];
if ($char) {
    $engine = new CombatEngine($db);
    // Authored fights only. A generated floor stocks an ambush in most of its
    // rooms and the pit rewrites one per party, so without this the bench is a
    // hundred cards of scratch machinery and the dozen written fights are
    // somewhere in the middle of it. The leading underscore is the convention
    // those rows already use to say they were not written by anybody.
    $rows = $db->query(
        "SELECT e.id, e.name, e.description, l.name AS place, r.name AS region
           FROM encounters e
           LEFT JOIN locations l ON l.id = e.location_id
           LEFT JOIN regions r ON r.id = l.region_id
          WHERE e.is_ambush = 1 AND e.is_random = 0
            AND COALESCE(l.location_key, '') NOT LIKE '\_%'
            AND COALESCE(e.encounter_key, '') NOT LIKE '\_%'
          ORDER BY r.name, e.id"
    )->fetchAll();

    foreach ($rows as $r) {
        $foes = $engine->roster((int) $r['id'], (int) $char['id']);
        if (!$foes) {
            continue;
        }
        $cards[] = [
            'title' => (string) $r['name'],
            'body'  => (string) ($r['description'] ?: 'They have seen you.'),
            'where' => trim(($r['place'] ?? '') . ' · ' . ($r['region'] ?? ''), ' ·'),
            'text'  => CombatEngine::describeRoster($foes),
            'foes'  => $foes,
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Ambush warnings</title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
  body { padding: 1.5rem; }
  .bench-head { margin-bottom: 1.25rem; }
  .bench-head p { color: var(--text-dim); }
  .bench-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); }
  /* The dialog, stood still so it can be looked at. The chrome inside is the
     modal's own; only the fixed positioning is undone. */
  .bench-modal { border: 1px solid var(--border); border-radius: 6px;
                 background: var(--bg-panel); padding: 1rem; }
  .bench-where { font-size: 0.75rem; color: var(--text-dim); margin: 0 0 0.5rem; }
  .bench-text { font-size: 0.8rem; color: var(--gold); margin: 0.6rem 0 0; }
</style>
</head>
<body>
<div class="bench-head">
  <h1>Ambush warnings</h1>
<?php if (!$char): ?>
  <p>No party in the database to size these against.</p>
<?php else: ?>
  <p>Every authored ambush, sized against
     <strong><?= htmlspecialchars((string) $char['party_name']) ?></strong>
     (level <?= (int) $char['level'] ?>).
     Other parties:
     <?php foreach ($parties as $p): ?>
       <a href="?party=<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['name']) ?>
         (<?= (int) $p['n'] ?>)</a><?= $p === end($parties) ? '' : ' · ' ?>
     <?php endforeach; ?>
  </p>
<?php endif; ?>
</div>
<div class="bench-grid" id="bench"></div>

<script>
  const CARDS = <?= json_encode($cards, JSON_UNESCAPED_SLASHES) ?>;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // game.js's foeLineup(), which is not importable from here — game.js is one
  // IIFE that expects a session, an API and a live scene. Kept to the same
  // markup and the same class names deliberately: if this and the game ever
  // disagree, the bench is lying and the styles are being judged against
  // nothing.
  function foeLineup(foes) {
    if (!foes || !foes.length) return '';
    return `<ul class="foe-lineup">${foes.map((f) => {
      const key = f.sprite_key
        || (/([^/]+)\.(png|svg)/i.exec(String(f.image_url || '')) || [])[1];
      const face = key
        ? `<img class="foe-face" src="/assets/images/monsters/${esc(key)}_face.png"
             alt="" onerror="this.remove()">`
        : '';
      return `<li class="foe-card">${face}
        <span class="foe-name">${esc(f.name)}</span>
        ${f.quantity > 1 ? `<span class="foe-count">×${f.quantity}</span>` : ''}
      </li>`;
    }).join('')}</ul>`;
  }

  document.getElementById('bench').innerHTML = CARDS.map((c) => `
    <div class="bench-modal">
      <p class="bench-where">${esc(c.where)}</p>
      <h2>${esc(c.title)}</h2>
      <p class="modal-body">${esc(c.body)}</p>
      ${foeLineup(c.foes)}
      <p class="bench-text">${esc(c.text)}</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary">Fight</button>
        <button type="button" class="btn">Hold back</button>
      </div>
    </div>`).join('');
</script>
</body>
</html>
