<?php
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rivermark Chronicles — Open 5e RPG</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <header class="hero">
    <h1>Rivermark Chronicles</h1>
    <p>
      A single-player, browser-based RPG inspired by classic gold-box exploration and tactical combat.
      Powered by the open 5e system rules (SRD). All maps, monsters, quests, and items live in the database.
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
      <!--
        This starts a new party, which is to say a new game — quests, flags and
        companions are all party-scoped. It is labelled that way because it used
        to say "Create Character" while quietly adding a fourth member to
        whatever party you were already playing, and the two are worth telling
        apart. Adding somebody to a party in progress is the party rail's own
        link, in game.

        Removed by the script as soon as the module cells are drawn: "start a
        new game" without saying which game is exactly the question those cells
        exist to ask. It survives only on an install with no modules at all.
      -->
      <a class="btn btn-primary" href="create.php" id="new-game-btn">Start a New Game</a>
      <a class="btn" href="game.php" id="continue-btn">Continue Adventure</a>
      <a class="btn" href="about.php">About / Legal</a>
<?php if (auth()->isAdmin()): ?>
      <a class="btn" href="admin.php">Accounts</a>
      <a class="btn" href="content.php">Content</a>
<?php endif; ?>
    </div>
    <p class="hero-who">
      Signed in as <strong><?= htmlspecialchars((string) auth()->currentUser()['username'], ENT_QUOTES) ?></strong>
      &middot; <a href="#" id="btn-signout">Sign out</a>
    </p>
  </header>

  <!--
    Two symbols, in the same stroke-only 24-grid style as the set in game.php,
    so they take `.icon-btn`'s sizing and currentColor without a second rule.
    Kept here rather than shared because these two are the only ones this page
    needs and game.php's sprite is not loaded on it.
  -->
  <svg class="icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5l11 7-11 7z"/></symbol>
    <!-- A printer: paper going in at the top, the sheet coming out below. -->
    <symbol id="i-print" viewBox="0 0 24 24">
      <path d="M7 9V3h10v6"/>
      <path d="M5 9h14a2 2 0 012 2v5h-4"/>
      <path d="M7 16H3v-5a2 2 0 012-2"/>
      <path d="M7 14h10v7H7z"/>
    </symbol>
  </svg>

  <main class="container">
    <div id="error-banner" class="error-banner hidden"></div>

    <!--
      One card per module across the top, then the how-to full width.
      
      This page used to spend its first column on a flat list of every
      character the account had ever made. That answered neither of the
      questions it was being asked — which game is this one in, and who do they
      travel with — and at thirty characters it answered nothing at all. The
      list moved to characters.php, one module at a time and grouped by party,
      and the modules took the row back.
    -->
    <div class="home-grid">
      <!-- Filled by renderModules(); one cell per module. -->
      <div id="module-slots" class="home-modules"></div>

      <section class="panel home-wide">
        <h2>How to Play</h2>
        <ol class="howto">
          <li>Create a hero (or a party of up to 4) using point buy, standard array, or random rolls.</li>
          <li>You begin wherever your chosen module opens — for Rivermark
            Chronicles, the <strong>Golden Flagon</strong> inn.</li>
          <li>Talk to NPCs, buy gear from the merchant, and take work from
            <strong>Available work</strong> in your journal (J).
            The board carries only your own module's work.</li>
          <li>Follow the map outward — every way on is an exit somebody wrote.</li>
          <li>Fight turn-based tactical battles, loot treasure, return to rest.</li>
        </ol>
      </section>
    </div>
  </main>

  <footer class="footer-legal">
    Uses only content from the 5e System Reference Document under OGL 1.0a / CC-BY 4.0.
    Not affiliated with any trademark holders. See <a href="about.php">Legal</a>.
  </footer>

  <script src="assets/js/api.js"></script>
  <script>
    function esc(s) {
      return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
      }[c]));
    }
    /**
     * How many empty slots to draw when there is room left on the shelf.
     *
     * The count is no longer a cap. It was `MODULE_SLOTS = 2` — the three
     * columns of the row, less the one the character list used — and the
     * comment here warned that a third module would need it changed. A third
     * module arrived and `slice(0, 2)` quietly dropped it off the page, which
     * is exactly the surprise the old note was trying to prevent and did not.
     *
     * Every module is drawn now, however many there are, and this only decides
     * how many "another could go here" placeholders keep the last row from
     * looking like something failed to load.
     */
    const SHELF_WIDTH = 3;

    /**
     * The module cells.
     *
     * Each is a panel in the top row, sitting beside the saved characters at
     * the same size — the two questions this page answers are "who am I already
     * playing" and "what else could I play", and they deserve equal billing.
     *
     * The header's "Start a New Game" goes away as soon as the modules are
     * drawn: starting a game without saying which game is precisely the
     * question these cells exist to ask.
     */
    async function renderModules() {
      const host = document.getElementById('module-slots');
      let modules = [];
      try {
        modules = (await API.get('session/modules')).modules || [];
      } catch (e) {
        return [];   // the character list reports failures; one banner is enough
      }

      const newGame = document.getElementById('new-game-btn');
      if (newGame && modules.length) newGame.remove();

      host.innerHTML = '';
      modules.forEach((m) => {
        const n = Number(m.party_count) || 0;
        const card = document.createElement('section');
        card.className = 'panel home-cell module-card';
        // Cover art is by convention rather than by a column: the file is named
        // for the module key, and a module without one simply has no <img>.
        // `onerror` removes it rather than leaving a broken-image glyph — the
        // vhost answers a missing file with the homepage HTML and a 200, so the
        // browser gets a document where it wanted a picture.
        card.innerHTML = `
          <img class="module-cover" alt=""
               src="assets/images/modules/${encodeURIComponent(m.module_key)}.jpg"
               onerror="this.remove()">
          <div class="module-head">
            <span class="module-name">${esc(m.name)}</span>
            <span class="module-levels">Levels ${esc(m.level_min)}–${esc(m.level_max)}</span>
          </div>
          ${m.blurb ? `<p class="module-blurb">${esc(m.blurb)}</p>` : ''}
          <p class="module-count">${n ? `${n} part${n === 1 ? 'y' : 'ies'} of yours here` : 'Nothing started here yet'}</p>
          ${m.attribution ? `<p class="module-credit">${esc(m.attribution)}</p>` : ''}`;
        // Two doors, and which one is the loud one depends on whether there
        // is anything behind it. With parties here, Play is the thing you came
        // for; with none, Play would open an empty page and Create is the only
        // useful move — so it takes the emphasis and Play is not drawn at all.
        const actions = document.createElement('div');
        actions.className = 'module-actions';

        if (n) {
          const play = document.createElement('a');
          play.className = 'btn btn-small btn-primary';
          play.href = 'characters.php?module=' + encodeURIComponent(m.module_key);
          play.textContent = 'Play';
          actions.appendChild(play);
        }

        const start = document.createElement('a');
        start.className = 'btn btn-small' + (n ? '' : ' btn-primary');
        start.href = 'create.php?module=' + encodeURIComponent(m.module_key);
        start.textContent = n ? 'New party' : 'Start a game here';
        actions.appendChild(start);

        card.appendChild(actions);
        host.appendChild(card);
      });

      // Draw the empties. A row with a hole in it reads as something that
      // failed to load; a slot that names itself reads as a shelf with room.
      for (let i = modules.length; i < SHELF_WIDTH; i++) {
        const slot = document.createElement('div');
        slot.className = 'module-slot-empty';
        slot.innerHTML = '<span>Another module<br>could go here</span>';
        host.appendChild(slot);
      }
      return modules;
    }

    (async function () {
      await renderModules();
    })();

  </script>
</body>
</html>
