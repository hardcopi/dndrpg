<?php
/**
 * The account bar on every page that is not the game.
 *
 * One include so the doors cannot drift: a new admin tool that is only linked
 * from the shelf is a tool nobody finds from Content. The game is excluded on
 * purpose — its chrome is the party rail — and so are the printed books, which
 * are paper and have a bar of their own.
 *
 * Sign-out lives here rather than on each page. It used to be an `<a href="#">`
 * with a listener only on the shelf, so every other copy scrolled to the top
 * and left you signed in.
 *
 * @var string $here  basename of this request, used to mark the current door
 */
$here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$user = auth()->currentUser();
$signedIn = $user !== null;

$doors = [];
if ($signedIn) {
    // "Shelf" is the adventures. With them hidden the same door is just home.
    $doors[] = ['index.php', ADVENTURES_ENABLED ? 'Shelf' : 'Home'];
    $doors[] = ['about.php', 'About &amp; legal'];
    if (auth()->isAdmin()) {
        $doors[] = ['admin.php', 'Accounts'];
        $doors[] = ['content.php', 'Content'];
        $doors[] = ['studio.php', 'Studio'];
        $doors[] = ['adventure_print.php', 'Books'];
        $doors[] = ['bestiary.php', 'Bestiary'];
    }
} else {
    $doors[] = ['login.php', 'Sign in'];
    $doors[] = ['about.php', 'About &amp; legal'];
}
?>
<div class="site-bar">
  <nav class="site-bar-in">
    <?php if ($signedIn) { ?>
      <span class="site-who">Signed in as
        <strong><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></strong></span>
    <?php } else { ?>
      <span class="site-who">Rivermark Chronicles</span>
    <?php } ?>
    <?php foreach ($doors as [$href, $label]) {
        $on = $here === basename($href);
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ($on ? ' aria-current="page"' : '') . '>'
            . $label . '</a>';
    } ?>
    <?php if ($signedIn) { ?>
      <a href="login.php" id="btn-signout">Sign out</a>
    <?php } ?>
  </nav>
</div>
<script>
  (function () {
    var btn = document.getElementById('btn-signout');
    if (!btn || btn.getAttribute('data-bound') === '1') return;
    btn.setAttribute('data-bound', '1');
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var go = function () { location.href = 'login.php'; };
      var req = (window.API && typeof window.API.post === 'function')
        ? window.API.post('auth/logout', {})
        : fetch('api/?r=auth/logout', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: '{}'
          });
      req.then(go, go);
    });
  })();
</script>
