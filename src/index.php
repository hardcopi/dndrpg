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
<body class="home-page">
  <!--
    Account chrome, out of the hero.

    It used to sit in the middle of the hero's button row — "Continue
    Adventure", "About / Legal", "Accounts", "Content" all drawn as the same
    grey rectangle, with "signed in as" in small print underneath. Four equal
    buttons is four equal offers, and only one of them is why anybody opened
    this page. Housekeeping goes up here as quiet links; the hero keeps the one
    button that continues a game.
  -->
  <div class="site-bar">
    <nav class="site-bar-in">
      <span class="site-who">Signed in as
        <strong><?= htmlspecialchars((string) auth()->currentUser()['username'], ENT_QUOTES) ?></strong></span>
      <a href="about.php">About &amp; legal</a>
<?php if (auth()->isAdmin()): ?>
      <a href="admin.php">Accounts</a>
      <a href="content.php">Content</a>
      <a href="adventure_print.php">Books</a>
<?php endif; ?>
      <a href="#" id="btn-signout">Sign out</a>
    </nav>
  </div>

  <header class="hero hero-home">
    <p class="hero-eyebrow">Open 5e SRD &middot; single player &middot; in the browser</p>
    <h1>Rivermark Chronicles</h1>
    <div class="rule-orn" aria-hidden="true"></div>
    <p>
      A single-player RPG in the manner of the old gold-box games: talk your way
      around a town, walk out of it, and fight what is waiting on a battlefield
      of five-foot squares. The world — every room, every face, every quest —
      lives in the database.
    </p>
    <div class="hero-actions">
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
      <a class="btn btn-primary btn-lg" href="create.php" id="new-game-btn">Start a New Game</a>
      <a class="btn btn-lg" href="game.php" id="continue-btn">
        <svg class="btn-glyph" aria-hidden="true" focusable="false"><use href="#i-play"></use></svg>
        Continue where you left off
      </a>
    </div>
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
    <div class="section-head">
      <h2>The shelf</h2>
      <p class="section-hint">Each card is a separate game. A party is made in
        one and stays in it.</p>
    </div>

    <div class="home-grid">
      <!-- Filled by renderModules(); one cell per module. -->
      <div id="module-slots" class="home-modules"></div>
    </div>

    <section class="panel home-howto">
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
     * A card is a book on a shelf: the painting is the whole top of it, with
     * the name and the level band lying across the bottom of the picture
     * rather than in a line of text underneath. That is the shape a player
     * already knows how to read, and it puts the one thing worth looking at —
     * the art — at the size it was drawn for.
     *
     * The plate is itself the door, and it leads wherever the loud button
     * leads: to your parties if you have some here, to the creator if you do
     * not. A picture of a place you can play, that does nothing when pressed,
     * is a picture that has to be explained.
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
        const key = encodeURIComponent(m.module_key);
        const played = 'characters.php?module=' + key;
        const fresh = 'create.php?module=' + key;

        const card = document.createElement('section');
        card.className = 'panel home-cell module-card';
        // Cover art is by convention rather than by a column: the file is named
        // for the module key, and a module without one simply has no <img>.
        // The plate keeps its 3:2 whatever happens, so a missing painting is a
        // dark board with the title on it rather than a card that collapses to
        // half the height of the two beside it — the vhost answers a missing
        // file with the homepage HTML and a 200, so the browser gets a
        // document where it wanted a picture and `onerror` is the only warning.
        card.innerHTML = `
          <a class="module-plate" href="${n ? played : fresh}">
            <img class="module-cover" alt="" src="assets/images/modules/${key}.jpg">
            ${n ? `<span class="module-badge">${n} part${n === 1 ? 'y' : 'ies'}</span>` : ''}
            <span class="module-cap">
              <span class="module-name">${esc(m.name)}</span>
              <span class="module-levels">Levels ${esc(m.level_min)}–${esc(m.level_max)}</span>
            </span>
          </a>
          <div class="module-body">
            ${m.blurb ? `<p class="module-blurb">${esc(m.blurb)}</p>` : ''}
            ${m.attribution ? `<p class="module-credit">${esc(m.attribution)}</p>` : ''}
          </div>`;

        const cover = card.querySelector('.module-cover');
        cover.addEventListener('error', () => {
          cover.remove();
          card.querySelector('.module-plate').classList.add('no-art');
        });

        // Two doors, and which one is the loud one depends on whether there
        // is anything behind it. With parties here, Play is the thing you came
        // for; with none, Play would open an empty page and Create is the only
        // useful move — so it takes the emphasis and Play is not drawn at all.
        const actions = document.createElement('div');
        actions.className = 'module-actions';

        if (n) {
          const play = document.createElement('a');
          play.className = 'btn btn-small btn-primary';
          play.href = played;
          play.textContent = 'Play';
          actions.appendChild(play);
        }

        const start = document.createElement('a');
        start.className = 'btn btn-small' + (n ? '' : ' btn-primary');
        start.href = fresh;
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

    /* Was an <a href="#"> with nothing listening: it scrolled to the top of the
       page and left you signed in. admin.php has always had the real one. */
    document.getElementById('btn-signout').addEventListener('click', async (e) => {
      e.preventDefault();
      try { await API.post('auth/logout', {}); } catch (err) { /* going anyway */ }
      location.href = 'login.php';
    });

    (async function () {
      await renderModules();
    })();

  </script>
</body>
</html>
