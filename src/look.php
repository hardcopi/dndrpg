<?php
/**
 * A character's face, and how they change it.
 *
 * The creator is a Unity WebGL build in an iframe; everything this page does is
 * stand it in a box, tell it whose face it is looking at, and get out of the
 * way. It saves through `character/model` on its own — same origin, so the
 * session cookie goes with it and nothing has to pass a token about.
 *
 * Ownership is checked here as well as by the API, and deliberately twice: the
 * API's check is what stops somebody else's character being re-dressed, and
 * this one is what stops the page rendering a name and a heading for a
 * character that is not yours before the iframe gets around to refusing.
 */

require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();

function look_refuse(int $status, string $heading, string $detail): void
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>No such character — Rivermark Chronicles</title>'
        . '<link rel="stylesheet" href="' . asset('assets/css/style.css') . '"></head>'
        . '<body class="auth-page"><main class="auth-card">'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
        . '<p class="auth-sub">' . htmlspecialchars($detail, ENT_QUOTES) . '</p>'
        . '<p class="auth-foot"><a href="characters.php">Back to your characters</a></p>'
        . '</main></body></html>';
    exit;
}

$characterId = (int) ($_GET['character_id'] ?? 0);
if ($characterId <= 0) {
    look_refuse(400, 'Which character?', 'This page needs a character to dress.');
}
if (!Ownership::character($characterId)) {
    look_refuse(404, 'No such character', 'There is no character of yours with that number.');
}

$who = db()->prepare('SELECT name, race, subrace, class, level FROM characters WHERE id = ?');
$who->execute([$characterId]);
$character = $who->fetch(PDO::FETCH_ASSOC);
if (!$character) {
    look_refuse(404, 'No such character', 'There is no character of yours with that number.');
}

$name = (string) $character['name'];
$race = (string) $character['race'];
$line = trim($race . ($character['subrace'] ? ' / ' . $character['subrace'] : '')
    . ' · ' . $character['class'] . ' ' . $character['level']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($name, ENT_QUOTES) ?>'s look — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <style>
    /* An embed owns its box and fills it, so the box needs a height. A box with
       no height is the commonest way to make a working embed look broken. */
    .look-well {
      height: min(78vh, 760px);
      min-height: 420px;
      border-radius: 8px;
      overflow: hidden;
      background: #1e1a15;
    }
    .look-note { margin-top: .75rem; }
    .look-saved { color: var(--gold, #c9a227); }
  </style>
</head>
<body>
  <?php require APP_PATH . '/inc/site_bar.php'; ?>

  <header class="hero hero-slim">
    <h1><?= htmlspecialchars($name, ENT_QUOTES) ?></h1>
    <p class="hero-sub"><?= htmlspecialchars($line, ENT_QUOTES) ?></p>
    <div class="hero-actions">
      <a class="btn" href="characters.php">Back to your characters</a>
      <a class="btn" href="sheet_print.php?character_id=<?= (int) $characterId ?>">Their sheet</a>
    </div>
  </header>

  <main class="container">
    <div id="error-banner" class="error-banner hidden"></div>

    <section class="panel">
      <div class="look-well" id="look"></div>
      <p class="help-hint look-note" id="look-note">
        Drag to turn them. Changes are only kept when you press Save.
      </p>
    </section>
  </main>

  <footer class="footer-legal">
    Uses only content from the 5e System Reference Document under OGL 1.0a / CC-BY 4.0.
    Not affiliated with any trademark holders. See <a href="about.php">Legal</a>.
  </footer>

  <script type="module">
    // "./" is required: asset() returns a bare relative path, which is fine for a
    // <link> or a <script src> but is not a legal ES module specifier — the
    // browser refuses to resolve one that does not start with "/", "./" or "../".
    import { mountCharacter } from './<?= asset('assets/js/rivermark-character.js') ?>';

    const note = document.getElementById('look-note');
    const banner = document.getElementById('error-banner');

    const character = mountCharacter(document.getElementById('look'), {
      mode: 'create',
      character: <?= (int) $characterId ?>,
      // Only used if they have never been dressed. The creator opens as
      // whatever their sheet already says they are rather than as a human
      // everybody then has to correct.
      race: <?= json_encode($race, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    });

    character.on('save', () => {
      note.textContent = 'Saved.';
      note.classList.add('look-saved');
    });

    character.on('change', () => {
      note.textContent = 'Unsaved changes. Press Save to keep them.';
      note.classList.remove('look-saved');
    });

    character.on('error', (detail) => {
      banner.textContent = detail.message || 'The character creator reported a problem.';
      banner.classList.remove('hidden');
    });
  </script>
</body>
</html>
