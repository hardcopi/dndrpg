<?php
/**
 * The character embed, on a page, three ways.
 *
 * Exists to be read as much as to be used: it is the shortest complete answer
 * to "how do I put the creator on a page", and it is a working page, so an
 * answer that has drifted out of date fails visibly here rather than quietly in
 * somebody else's file.
 *
 * Signed in, because the creator saves to a character and the route it saves
 * through needs a session. The viewer half would work for a signed-out visitor
 * if `character/model` were ever made public; it is not, and that is a decision
 * to take deliberately rather than by leaving this page open.
 */
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();

$characterId = (int) ($_GET['character_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Character embed — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <style>
    .embed-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
    @media (min-width: 60rem) { .embed-grid { grid-template-columns: 2fr 1fr; } }

    /* An embed owns its box and fills it. A box with no height is the
       commonest way to make a working embed look like a broken one. */
    .embed-well { height: min(70vh, 640px); border-radius: 8px; overflow: hidden; background: #1e1a15; }
    .embed-portrait { height: 420px; }

    .recipe-out { font: 12px/1.5 ui-monospace, monospace; white-space: pre-wrap;
                  max-height: 14rem; overflow: auto; }
  </style>
</head>
<body>
  <?php require APP_PATH . '/inc/site_bar.php'; ?>

  <header class="hero hero-slim">
    <h1>Character embed</h1>
    <p class="hero-sub">
      The same Unity build twice: a creator you can edit, and a viewer that only shows.
    </p>
  </header>

  <main class="container">
    <div id="error-banner" class="error-banner hidden"></div>

    <p class="help-hint">
      Add <code>?character_id=N</code> to load and save a real character.
      Without one the creator starts on a default and Save only reports the recipe.
    </p>

    <div class="embed-grid">
      <section>
        <h2>Creator — mounted from JavaScript</h2>
        <div id="creator" class="embed-well"></div>
        <p class="hero-actions">
          <button class="btn" id="btn-surprise" type="button">Surprise me</button>
          <button class="btn btn-primary" id="btn-save" type="button">Save</button>
          <button class="btn" id="btn-view" type="button">Show as viewer</button>
        </p>
        <pre id="recipe" class="recipe-out">waiting for the embed…</pre>
      </section>

      <section>
        <h2>Viewer — as an element</h2>
        <!-- No script involved. The element takes the same options as
             attributes, and `background="none"` lets the page show through. -->
        <rivermark-character
          id="viewer"
          class="embed-portrait"
          mode="view"
          background="none"
          <?= $characterId > 0 ? 'character="' . $characterId . '"' : '' ?>
          style="display:block"></rivermark-character>

        <h2>Viewer — as a bare iframe</h2>
        <p class="help-hint">
          No JavaScript at all. Everything the first frame needs is in the URL.
        </p>
        <iframe
          class="embed-portrait"
          style="width:100%;border:0;border-radius:8px"
          title="Character"
          src="/embed/?mode=view&amp;bg=1E1A15<?= $characterId > 0 ? '&amp;character=' . $characterId : '' ?>"></iframe>
      </section>
    </div>
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

    const CHARACTER = <?= $characterId > 0 ? $characterId : 'null' ?>;
    const out = document.getElementById('recipe');
    const banner = document.getElementById('error-banner');
    const viewer = document.getElementById('viewer');

    const creator = mountCharacter(document.getElementById('creator'), {
      mode: 'create',
      character: CHARACTER || undefined,

      // The page has its own Save button, so the creator does not draw one.
      // Two Saves on one screen is a question about which one is real.
      save: false,

      onReady: ({ version }) => { out.textContent = 'ready — recipe version ' + version; },
      onChange: ({ recipe }) => { out.textContent = JSON.stringify(recipe, null, 2); },
      onSave: ({ recipe }) => {
        out.textContent = 'saved:\n' + JSON.stringify(recipe, null, 2);
        // The viewer is a separate embed with its own copy of the character,
        // so it is told rather than expected to notice.
        viewer.recipe = recipe;
      },
      onError: ({ message }) => {
        banner.textContent = message;
        banner.classList.remove('hidden');
      },
    });

    document.getElementById('btn-surprise').addEventListener('click', () => creator.surprise());
    document.getElementById('btn-save').addEventListener('click', () => creator.save());
    document.getElementById('btn-view').addEventListener('click', (e) => {
      const showing = e.target.dataset.mode === 'view';
      creator.setMode(showing ? 'create' : 'view');
      e.target.dataset.mode = showing ? 'create' : 'view';
      e.target.textContent = showing ? 'Show as viewer' : 'Back to the creator';
    });
  </script>
</body>
</html>
